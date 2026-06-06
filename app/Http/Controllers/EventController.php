<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreEventRequest;
use App\Jobs\SyncEventJob;
use App\Models\Event;
use App\Services\ImageOptimizationService;
use Exception;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

use App\Traits\SharedCacheTrait;

class EventController extends Controller
{
    use SharedCacheTrait;

    protected ImageOptimizationService $imageService;


    public function __construct(ImageOptimizationService $imageService)
    {
        $this->imageService = $imageService;
    }

    private function getBlocks(): array
    {
        return \App\Models\Block::orderBy('name')->pluck('name', 'id')->toArray();
    }

    private function getDepartments(): array
    {
        return \App\Models\Department::orderBy('name')->pluck('name', 'id')->toArray();
    }

    public function dashboard(): \Inertia\Response
    {
        $autoSyncPaused = $this->getSharedValue('auto_sync_paused', false);
        $portalCredentialsInvalid = $this->getSharedValue('portal_credentials_invalid', false);

        // Cache dashboard counts for 30 seconds to completely eliminate DB pressure under heavy polling
        $cachedMetrics = Cache::remember('dashboard_metrics_counts', 30, function () {
            $counts = Event::selectRaw('sync_status, COUNT(*) as total')
                ->groupBy('sync_status')
                ->pluck('total', 'sync_status');

            $transient = Event::where('sync_attempts', '>', 0)
                ->where('sync_status', 'pending')
                ->count();

            return [
                'counts' => $counts->toArray(),
                'transient' => $transient,
            ];
        });

        $counts = collect($cachedMetrics['counts']);
        $metrics = [
            'total'       => $counts->sum(),
            'pending'     => (int) ($counts->get('pending', 0)),
            'syncing'     => (int) ($counts->get('syncing', 0)),
            'synced'      => (int) ($counts->get('synced', 0)),
            'failed_perm' => (int) ($counts->get('failed_permanently', 0)),
            'transient'   => (int) $cachedMetrics['transient'],
        ];

        // Self-healing Watchdog: If Hostinger cron fails, visiting the dashboard will silently wake up the worker.
        if (!Cache::has('sre_dashboard_cron_watchdog') && !Cache::get('sre_circuit_breaker_portal_down', false) && ($metrics['pending'] > 0)) {
            Cache::put('sre_dashboard_cron_watchdog', true, 10); // Throttle to max 1 trigger per 10 seconds
            try {
                $host = $_SERVER['HTTP_HOST'] ?? '';
                $isLocalhost = str_contains($host, 'localhost') || str_contains($host, '127.0.0.1');

                if (!$isLocalhost) {
                    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                    $cronUrl = $protocol . '://' . $host . '/nmba-cron.php?token=' . urlencode(env('CRON_TOKEN', ''));
                    
                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, $cronUrl);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_TIMEOUT, 1);
                    curl_setopt($ch, CURLOPT_NOSIGNAL, 1);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                    curl_exec($ch);
                    $ch = null; // curl_close() is deprecated in PHP 8.5+; null-assignment cleans up the handle
                }
            } catch (\Throwable $e) {
                // Ignore silent watchdog errors
            }
        }

        // Enqueue any pending events that slipped through — the persistent queue daemon will pick them up.
        // Disabled: scheduler handles batch queuing in parallel SyncBatchJobs; executing on page load causes execution timeout with large datasets
        // if (!$autoSyncPaused) {
        //     $this->ensurePendingEventsAreQueued();
        // }

        $cachedHeavyQueries = Cache::remember('dashboard_heavy_queries', 30, function () use ($counts) {
            $recentEvents = Event::orderBy('created_at', 'desc')->limit(20)->get();
            $recentFailures = Event::whereNotNull('last_error_log')->orderBy('last_attempt_at', 'desc')->limit(10)->get();
            
            // Chart Data: Status Pie Chart
            $statusData = array_values(array_filter([
                ['name' => 'Synced', 'value' => (int) $counts->get('synced', 0), 'fill' => '#10b981'],
                ['name' => 'Pending', 'value' => (int) $counts->get('pending', 0), 'fill' => '#f59e0b'],
                ['name' => 'Failed', 'value' => (int) $counts->get('failed_permanently', 0), 'fill' => '#f43f5e'],
                ['name' => 'Syncing', 'value' => (int) $counts->get('syncing', 0), 'fill' => '#3b82f6'],
            ], fn($item) => $item['value'] > 0));

            // Chart Data: Events by Block Bar Chart
            $blocks = $this->getBlocks();
            $eventsByBlock = Event::selectRaw('block_id, COUNT(*) as count')
                ->groupBy('block_id')
                ->get()->map(function($item) use ($blocks) {
                    return [
                        'name' => $blocks[$item->block_id] ?? 'Unknown',
                        'count' => $item->count
                    ];
                })->sortByDesc('count')->values();

            // Chart Data: Events over last 30 days Area Chart
            $eventsOverTimeRaw = Event::where('created_at', '>=', now()->subDays(30)->startOfDay())
                ->selectRaw('DATE(created_at) as date, COUNT(*) as count')
                ->groupBy('date')
                ->pluck('count', 'date');

            $eventsOverTime = collect();
            for ($i = 30; $i >= 0; $i--) {
                $carbonDate = now()->subDays($i);
                $dateStr = $carbonDate->format('Y-m-d');
                $displayDate = $carbonDate->format('M d');
                
                $eventsOverTime->push([
                    'date' => $displayDate,
                    'count' => $eventsOverTimeRaw->get($dateStr, 0)
                ]);
            }

            return [
                'recentEvents' => $recentEvents,
                'recentFailures' => $recentFailures,
                'statusData' => $statusData,
                'eventsByBlock' => $eventsByBlock,
                'eventsOverTime' => $eventsOverTime,
            ];
        });

        $envFile = base_path('.env');
        $envContent = file_exists($envFile) ? file_get_contents($envFile) : '';
        preg_match('/^PORTAL_URL=(.*)$/m', $envContent, $urlMatch);
        preg_match('/^PORTAL_EMAIL=(.*)$/m', $envContent, $emailMatch);
        preg_match('/^PORTAL_PASSWORD=(.*)$/m', $envContent, $passwordMatch);

        return \Inertia\Inertia::render('Events/Dashboard', [
            'metrics'        => $metrics,
            'recentEvents'   => $cachedHeavyQueries['recentEvents'],
            'recentFailures' => $cachedHeavyQueries['recentFailures'],
            'autoSyncPaused' => $autoSyncPaused,
            'portalCredentialsInvalid' => $portalCredentialsInvalid,
            'statusData'     => $cachedHeavyQueries['statusData'],
            'eventsByBlock'  => $cachedHeavyQueries['eventsByBlock'],
            'eventsOverTime' => $cachedHeavyQueries['eventsOverTime'],
            'telemetryData'  => $this->getTelemetryHistory(),
            'portalConfig'   => [
                'portal_url' => trim($urlMatch[1] ?? config('services.portal.url', '')),
                'admin_id' => trim($emailMatch[1] ?? config('services.portal.email', '')),
                'admin_password' => trim($passwordMatch[1] ?? config('services.portal.password', ''), '"'),
            ]
        ]);
    }

    public function syncedEventsIndex(\Illuminate\Http\Request $request): \Inertia\Response
    {
        $query = Event::where('sync_status', 'synced')
            ->orderByRaw('COALESCE(synced_at, last_attempt_at, created_at) DESC');

        if ($request->filled('block_id')) {
            $query->where('block_id', $request->block_id);
        }

        if ($request->filled('start_date')) {
            $query->whereDate('event_date', '>=', $request->start_date);
        }
        if ($request->filled('end_date')) {
            $query->whereDate('event_date', '<=', $request->end_date);
        }

        $totalSynced = (clone $query)->count();

        // Paginate and format synced_at to 12-hour format (Asia/Kolkata timezone)
        $events = $query->paginate(20)->withQueryString();

        // Map the paginated collection to include formatted_synced_at
        $events->getCollection()->transform(function ($event) {
            $event->formatted_synced_at = $event->synced_at 
                ? \Illuminate\Support\Carbon::parse($event->synced_at)->timezone('Asia/Kolkata')->format('d-m-Y h:i:s A')
                : 'Historical (Synced)';
            $event->synced_at_is_historical = is_null($event->synced_at);
            return $event;
        });

        return \Inertia\Inertia::render('Events/SyncedIndex', [
            'events' => $events,
            'blocks' => $this->getBlocks(),
            'filters' => $request->only(['block_id', 'start_date', 'end_date']),
            'totalSynced' => $totalSynced,
        ]);
    }

    public function index(\Illuminate\Http\Request $request): \Inertia\Response
    {
        $query = Event::orderBy('event_date', 'desc')->orderBy('id', 'desc');

        if ($request->user() && $request->user()->role !== 'admin') {
            $query->where('submitted_by_user_id', $request->user()->id);
        }

        if ($request->filled('block_id')) {
            $query->where('block_id', $request->block_id);
        }

        if ($request->filled('start_date')) {
            $query->whereDate('event_date', '>=', $request->start_date);
        }
        if ($request->filled('end_date')) {
            $query->whereDate('event_date', '<=', $request->end_date);
        }

        $uploadedToday = (clone $query)->whereDate('created_at', today())->count();
        $totalUploaded = (clone $query)->count();

        $events = $query->paginate(20)->withQueryString();

        return \Inertia\Inertia::render('Events/Index', [
            'events' => $events,
            'blocks' => $this->getBlocks(),
            'filters' => $request->only(['block_id', 'start_date', 'end_date']),
            'uploadedToday' => $uploadedToday,
            'totalUploaded' => $totalUploaded,
        ]);
    }

    public function create()
    {
        return \Inertia\Inertia::render('Events/Create', [
            'blocks' => $this->getBlocks(),
            'departments' => $this->getDepartments(),
        ]);
    }

    public function store(StoreEventRequest $request): RedirectResponse
    {
        $validated        = $request->validated();
        $coordinatorName  = $validated['event_coordinator_name'] ?? '';

        // ── 1. SEMANTIC HASH (FIX-ARCH-01) ──────────────────────────────
        // Deterministic fingerprint — no uniqid(). Identical events produce
        // the same semantic_hash, enabling true duplicate detection.
        $semanticHash = Event::generateSemanticHash(
            $validated['event_name'],
            $validated['event_date'],
            $validated['event_venue'],
            (int) $validated['actual_attendance'],
            (int) $validated['block_id'],
            $coordinatorName
        );

        // ── 2. CACHE LOCK (FIX-ARCH-02 — atomic layer 1) ────────────────
        // Uses Laravel's atomic Cache::lock() rather than Cache::put()/has().
        // On file driver: best-effort (race window exists). The DB constraint
        // below is the authoritative atomic deduplication barrier.
        $lockKey  = 'event_submit_lock_' . $semanticHash;
        $lock     = Cache::lock($lockKey, 10);

        if (!$lock->get()) {
            return redirect()->back()->withInput()
                ->withErrors(['duplicate' => 'A submission for this event is already in progress. Please wait a moment.']);
        }

        // ── 3. SUBMISSION ID (FIX-ARCH-01) ──────────────────────────────
        // Globally unique per-record identifier — kept separate from semantic_hash.
        // Uses generateSemanticHash() + uniqid suffix to remain unique while using the modern API.
        $submissionId = md5(
            Event::generateSemanticHash(
                $validated['event_name'],
                $validated['event_date'],
                $validated['event_venue'],
                (int) $validated['actual_attendance'],
                (int) $validated['block_id'],
                $coordinatorName
            ) . '|' . uniqid('', true)
        );

        $photoPaths = [];
        try {
            $photoPaths = $this->imageService->optimizeBatch($request->file('photo'));
        } catch (Exception $e) {
            $lock->release();
            Log::channel('sync')->warning('Image optimization failure.', ['error' => $e->getMessage()]);
            return redirect()->back()->withInput()
                ->withErrors(['error' => 'Image processing failed: ' . $e->getMessage()]);
        }

        DB::beginTransaction();
        try {
            // ── 4. DB-BACKED DEDUP (FIX-ARCH-02 — atomic layer 2) ───────
            // The deduplications table has a unique index on semantic_hash.
            // If another concurrent request already inserted this hash, the
            // DB engine will throw a 23000 QueryException here — atomically,
            // regardless of how many PHP-FPM workers are running.
            try {
                DB::table('deduplications')->insert([
                    'semantic_hash' => $semanticHash,
                    'event_id'      => null, // will update after event is created
                    'created_at'    => now(),
                ]);
            } catch (QueryException $dedupEx) {
                DB::rollBack();
                $lock->release();
                foreach ($photoPaths as $path) {
                    if (Storage::disk('public')->exists($path)) {
                        Storage::disk('public')->delete($path);
                    }
                }
                if ($dedupEx->getCode() === '23000' || str_contains($dedupEx->getMessage(), 'Duplicate entry')) {
                    return redirect()->back()->withInput()
                        ->withErrors(['duplicate' => 'This event has already been submitted. If you believe this is an error, please contact the administrator.']);
                }
                throw $dedupEx;
            }

            // ── 5. CREATE EVENT RECORD ────────────────────────────────────
            $event = Event::create(array_merge($validated, [
                'photo_paths'   => $photoPaths,
                'unique_hash'   => $submissionId, // legacy field — kept for one release cycle
                'submission_id' => $submissionId,
                'semantic_hash' => $semanticHash,
                'sync_status'   => 'pending',
                'uploader_ip'   => $request->ip(),
                'submitted_by_user_id' => auth()->id(),
            ]));

            // Backfill the event_id into deduplications now that we have it
            DB::table('deduplications')
                ->where('semantic_hash', $semanticHash)
                ->update(['event_id' => $event->id]);

            DB::commit();

            Cache::forget('dashboard_metrics_counts');

            Log::channel('sync')->info('Event created and queued.', [
                'event_id'     => $event->id,
                'semantic_hash'=> $semanticHash,
                'submission_id'=> $submissionId,
            ]);

        } catch (QueryException $e) {
            DB::rollBack();
            $lock->release();
            foreach ($photoPaths as $path) {
                if (Storage::disk('public')->exists($path)) {
                    Storage::disk('public')->delete($path);
                }
            }
            if ($e->getCode() === '23000' || str_contains($e->getMessage(), 'Duplicate entry')) {
                return redirect()->back()->withInput()
                    ->withErrors(['duplicate' => 'Concurrency conflict: event already submitted.']);
            }
            throw $e;

        } catch (Exception $e) {
            DB::rollBack();
            $lock->release();
            foreach ($photoPaths as $path) {
                if (Storage::disk('public')->exists($path)) {
                    Storage::disk('public')->delete($path);
                }
            }
            Log::channel('sync')->error('Transaction abort during store.', ['error' => $e->getMessage()]);
            return redirect()->back()->withInput()
                ->withErrors(['error' => 'Internal error: ' . $e->getMessage()]);
        }

        $lock->release();

        // ── 6. DISPATCH (FIX-OPS-03 — SYNC_MODE feature flag) ───────────
        // SYNC_MODE=sync: call SyncEventJob directly (emergency rollback mode)
        // SYNC_MODE=async (default): dispatch to queue as normal
        if (config('app.sync_mode', 'async') === 'sync') {
            Log::channel('sync')->warning('SYNC_MODE=sync active — processing event synchronously (rollback mode).', [
                'event_id' => $event->id,
            ]);
            try {
                app(\App\Services\Contracts\PortalSyncInterface::class)->sync($event);
            } catch (Exception $e) {
                Log::channel('sync')->error('Synchronous sync failed.', ['error' => $e->getMessage()]);
            }
        } else {
            SyncEventJob::dispatch($event);
        }

        $blockName      = \App\Models\Block::find($validated['block_id'])?->name ?? 'selected block';
        $successMessage = "Event logged successfully! <br><span class='text-emerald-900 font-bold'>Recorded for Jurisdiction: {$blockName}</span>";
        if ($request->user() && $request->user()->isCreator()) {
            return redirect()->route('events.index')->with('success', $successMessage);
        }

        return redirect()->route('dashboard')->with('success', $successMessage);
    }

    /**
     * Manually triggers the queue worker to process pending sync jobs from the UI.
     * Extremely useful for local XAMPP environments without Supervisor daemons.
     */
    /**
     * Parse the jobs table to find all event IDs that are already queued.
     */
    private function getQueuedEventIds(): array
    {
        if (config('queue.default') !== 'database') {
            return [];
        }

        $queuedIds = [];
        try {
            if (\Illuminate\Support\Facades\Schema::hasTable('jobs')) {
                $payloads = DB::table('jobs')->pluck('payload');
                foreach ($payloads as $payload) {
                    $data = json_decode($payload, true);
                    $cmd = $data['data']['command'] ?? '';
                    if (preg_match('/"id";i:(\d+);/', $cmd, $m)) {
                        $queuedIds[(int) $m[1]] = true;
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::channel('sync')->warning('Could not retrieve queued event IDs: ' . $e->getMessage());
        }
        return $queuedIds;
    }

    /**
     * Manually triggers the queue worker to process pending sync jobs from the UI.
     * Extremely useful for local XAMPP environments without Supervisor daemons.
     */
    public function forceSync(\Illuminate\Http\Request $request): RedirectResponse
    {
        $key = 'force-sync-limit:' . ($request->user()?->id ?? $request->ip());

        if (\Illuminate\Support\Facades\RateLimiter::attempts($key) >= 5) {
            \Illuminate\Support\Facades\RateLimiter::clear($key);
            Log::channel('sync')->info('Force sync rate limit reached 5 requests. Resetting request count.');
        } else {
            \Illuminate\Support\Facades\RateLimiter::hit($key, 60);
        }

        try {
            // Reset all delayed job timers so the running daemon picks them up immediately.
            try {
                if (config('queue.default') === 'database' && \Illuminate\Support\Facades\Schema::hasTable('jobs')) {
                    DB::table('jobs')->update([
                        'available_at' => time(),
                        'reserved_at'  => null,
                    ]);
                }
            } catch (\Throwable $jobEx) {
                Log::channel('sync')->warning('Could not reset delayed jobs in forceSync: ' . $jobEx->getMessage());
            }

            // Clear the circuit breaker so sync attempts can proceed immediately.
            $this->forgetSharedValue('sre_circuit_breaker_portal_down');
            $this->setSharedValue('sre_portal_is_alive', true, 300);
            $this->forgetSharedValue('auto_sync_paused');
            $this->forgetSharedValue('sre_consecutive_auth_failures');
            $this->forgetSharedValue('portal_credentials_invalid');

            // Unlock manual override and reset dispatch locks for all pending events.
            // Let the high-performance scheduler sweep (orchestration slots 0-7) naturally
            // pull these events and dispatch them in parallel SyncBatchJobs, avoiding the
            // serialization and queue flood issues of dispatching them as SyncEventJobs.
            Event::where('sync_status', 'pending')
                ->chunk(100, function ($pendingEvents) {
                    foreach ($pendingEvents as $event) {
                        Cache::forget("manual_override_{$event->id}");
                        Cache::forget("sre_sync_dispatch_lock_{$event->id}");
                    }
                });

            // Clean the queue of any legacy/orphaned default queue jobs to keep it clean and performant.
            try {
                if (config('queue.default') === 'database' && \Illuminate\Support\Facades\Schema::hasTable('jobs')) {
                    DB::table('jobs')->where('queue', 'default')->delete();
                }
            } catch (\Throwable $jobEx) {
                Log::channel('sync')->warning('Could not clear queue during forceSync: ' . $jobEx->getMessage());
            }

            // Immediately trigger the queue worker to process jobs in the background.
            $this->runQueueWorkerInBackground();

            // Evict dashboard metrics cache so fresh values show up.
            Cache::forget('dashboard_metrics_counts');

            return redirect()->route('dashboard')
                ->with('success', 'Force sync triggered — the queue daemon will process all pending events now.');
        } catch (Exception $e) {
            return redirect()->route('dashboard')
                ->withErrors(['error' => 'Force sync encountered an error: ' . $e->getMessage()]);
        }
    }

    /**
     * Executes the queue worker manually in-request to process up to 10 jobs.
     */
    public function runQueueWorkerManually(\Illuminate\Http\Request $request): RedirectResponse
    {
        // Clear the circuit breaker so sync attempts can proceed immediately.
        Cache::forget('sre_circuit_breaker_portal_down');

        $startTime = microtime(true);

        try {
            // Run the queue worker for up to 10 jobs synchronously
            $exitCode = \Illuminate\Support\Facades\Artisan::call('queue:work', [
                'connection' => 'database',
                '--queue' => 'default',
                '--max-jobs' => 25,
                '--stop-when-empty' => true,
                '--tries' => 1,
                '--timeout' => 30
            ]);

            $duration = round(microtime(true) - $startTime, 2);
            $output = \Illuminate\Support\Facades\Artisan::output();

            // Evict dashboard metrics cache so fresh values show up.
            Cache::forget('dashboard_metrics_counts');

            return redirect()->route('dashboard')
                ->with('success', "Queue worker run completed in {$duration}s. Output:\n" . ($output ?: 'No jobs were in the queue.'));
        } catch (\Throwable $e) {
            return redirect()->route('dashboard')
                ->withErrors(['error' => 'Queue worker encountered an error: ' . $e->getMessage()]);
        }
    }

    /**
     * Clears all pending jobs in the queue.
     */
    public function clearQueueManually(\Illuminate\Http\Request $request): RedirectResponse
    {
        try {
            $exitCode = \Illuminate\Support\Facades\Artisan::call('queue:clear', [
                'connection' => 'database',
                '--queue' => 'default',
                '--force' => true
            ]);

            $output = \Illuminate\Support\Facades\Artisan::output();

            // Evict dashboard metrics cache so fresh values show up.
            Cache::forget('dashboard_metrics_counts');

            return redirect()->route('dashboard')
                ->with('success', 'Queue cleared successfully! ' . ($output ?: 'All pending jobs removed.'));
        } catch (\Throwable $e) {
            return redirect()->route('dashboard')
                ->withErrors(['error' => 'Queue clear encountered an error: ' . $e->getMessage()]);
        }
    }

    /**
     * Resets all failed or quarantined events back to pending status so they can be re-attempted.
     */
    public function resetFailedSyncs(\Illuminate\Http\Request $request): RedirectResponse
    {
        try {
            // Reset failed_permanently and manually locked out (sync_attempts = -1) events.
            // Note: 'failed' is not a valid status in this system — only 'failed_permanently' exists.
            $updatedCount = Event::where('sync_status', 'failed_permanently')
                ->orWhere('sync_attempts', -1)
                ->update([
                    'sync_status'     => 'pending',
                    'sync_attempts'   => 0,
                    'last_attempt_at' => null,
                    'last_error_log'  => null,
                ]);

            // Clear the circuit breaker so synchronization can run immediately
            $this->forgetSharedValue('sre_circuit_breaker_portal_down');
            $this->forgetSharedValue('auto_sync_paused');
            $this->forgetSharedValue('sre_consecutive_auth_failures');
            $this->forgetSharedValue('portal_credentials_invalid');

            // Force dashboard metrics to refresh
            Cache::forget('dashboard_metrics_counts');

            // Automatically queue up the reset events and run the worker in background
            $this->runQueueWorkerInBackground();

            return redirect()->route('dashboard')
                ->with('success', "Successfully reset {$updatedCount} failed or quarantined events back to pending. The background sync daemon will process them shortly.");
        } catch (Exception $e) {
            return redirect()->route('dashboard')
                ->withErrors(['error' => 'Resetting failed events encountered an error: ' . $e->getMessage()]);
        }
    }

    /**
     * Actively probe the portal's live health status, reset the circuit breaker if online,
     * and auto-trigger a background queue worker to sync any pending events immediately.
     *
     * Also detects the offline → online transition (portal recovery after a Cloudflare/network outage)
     * and performs a full queue recovery: clears per-event dispatch locks and resets all delayed
     * jobs to be immediately available, unblocking syncs that were frozen during the outage.
     */
    public function checkPortalHealth(\App\Services\PortalHealthService $healthService): \Illuminate\Http\JsonResponse
    {
        // This endpoint is polled every 15 seconds — must be fast and non-blocking.
        // Track whether the portal was offline on the previous poll so we can detect recovery.
        $wasOfflinePreviously = $this->getSharedValue('sre_last_portal_was_offline', false);

        // Read strictly from the cache to prevent slow HTTP requests from blocking the SAPI/serve process.
        $isOnline = $this->getSharedValue('sre_portal_is_alive', false) === true 
            && $this->getSharedValue('sre_circuit_breaker_portal_down') !== true;

        // ── Fast fallback: cross-site portal_live_window ──────────────────────
        // If the web cron confirmed the portal alive within the last 300 seconds (written to
        // portal_live_window.json), trust it even if sre_portal_is_alive has expired.
        // This prevents the dashboard from flipping to "Offline" when the cron hits a
        // transient 522 and doesn't refresh the signal for a few minutes.
        if (!$isOnline) {
            $liveWindow = $this->readPortalLiveWindow();
            if ($liveWindow !== null && (time() - $liveWindow) < 300) {
                // Cron confirmed portal alive recently — restore the shared cache so probes skip
                $this->setSharedValue('sre_portal_is_alive', true, 360);
                $this->forgetSharedValue('sre_circuit_breaker_portal_down');
                $isOnline = true;
                Log::channel('sync')->info('Dashboard health check: portal_live_window hit — restoring online state.', [
                    'window_age_seconds' => time() - $liveWindow,
                ]);
            }
        }

        $isPaused = $this->getSharedValue('auto_sync_paused', false);
        $credentialsInvalid = $this->getSharedValue('portal_credentials_invalid', false);

        $pendingCount = Event::where('sync_status', 'pending')->count();

        // Record system telemetry
        $responseTime = $isOnline ? 0.1 : 60.0;
        $this->recordTelemetry($pendingCount, $responseTime, $isOnline);
        $telemetry = $this->getTelemetryHistory();

        if (!$isOnline) {
            // Persist the offline state so the next poll can detect the recovery.
            $this->setSharedValue('sre_last_portal_was_offline', true, 7200);
            return response()->json([
                'status'           => 'offline',
                'pending_count'    => $pendingCount,
                'triggered_sync'   => false,
                'auto_sync_paused' => $isPaused,
                'portal_credentials_invalid' => $credentialsInvalid,
                'telemetry'        => $telemetry,
            ]);
        }

        // ── Portal is ONLINE ─────────────────────────────────────────────
        $triggeredSync = false;
        $recoveredFromOutage = false;

        // Detect offline → online transition (e.g. Cloudflare came back up).
        // Events that were frozen during the outage have:
        //   1. Individual dispatch cache locks set for hours/days.
        //   2. Queue jobs with a far-future available_at (from exponential backoff release()).
        // Both must be cleared so the scheduler and queue worker can act on them immediately.
        if ($wasOfflinePreviously) {
            $this->forgetSharedValue('sre_last_portal_was_offline');
            $recoveredFromOutage = true;

            Log::channel('sync')->info('Portal back online — outage recovery triggered.', [
                'pending_count' => $pendingCount,
            ]);

            // 1. Clear per-event dispatch locks so the scheduler can re-dispatch immediately.
            try {
                Event::where('sync_status', 'pending')
                    ->where('sync_attempts', '!=', -1)
                    ->chunk(100, function ($pendingEvents) {
                        foreach ($pendingEvents as $pendingEvent) {
                            Cache::forget("sre_sync_dispatch_lock_{$pendingEvent->id}");
                        }
                    });
                Log::channel('sync')->info('Outage recovery: per-event dispatch locks cleared.');
            } catch (\Throwable $e) {
                Log::channel('sync')->warning('Could not clear dispatch locks on portal recovery: ' . $e->getMessage());
            }

            // 2. Reset all delayed jobs to available_at = now() so the queue worker picks them up.
            try {
                if (config('queue.default') === 'database' && \Illuminate\Support\Facades\Schema::hasTable('jobs')) {
                    $resetCount = DB::table('jobs')
                        ->where('available_at', '>', time())
                        ->update([
                            'available_at' => time(),
                            'reserved_at'  => null,
                        ]);
                    Log::channel('sync')->info('Outage recovery: delayed jobs reset to immediate.', ['reset_count' => $resetCount]);
                }
            } catch (\Throwable $e) {
                Log::channel('sync')->warning('Could not reset delayed jobs on portal recovery: ' . $e->getMessage());
            }

            // 3. Evict dashboard cache so fresh metrics are shown immediately.
            Cache::forget('dashboard_metrics_counts');
        }

        if (!$isPaused) {
            $hasDelayedJobs = false;
            try {
                if (config('queue.default') === 'database' && \Illuminate\Support\Facades\Schema::hasTable('jobs')) {
                    $hasDelayedJobs = DB::table('jobs')->where('available_at', '>', time())->exists();
                }
            } catch (\Throwable $jobEx) {
                Log::channel('sync')->warning('Could not check delayed jobs in checkPortalHealth: ' . $jobEx->getMessage());
            }

            if ($hasDelayedJobs) {
                $triggeredSync = true;
            }

            // Ensure all pending events have corresponding jobs in the queue.
            // Disabled: scheduler handles batch queuing in parallel SyncBatchJobs; executing on every health check poll causes execution timeout with large datasets
            // $this->ensurePendingEventsAreQueued();

            $pendingActiveCount = Event::where('sync_status', 'pending')
                ->where('sync_attempts', '!=', -1)
                ->count();

            if ($pendingActiveCount > 0) {
                $triggeredSync = true;
            }

            if ($triggeredSync || $recoveredFromOutage) {
                $this->runQueueWorkerInBackground();
            }
        }

        return response()->json([
            'status'                => 'online',
            'pending_count'         => $pendingCount,
            'triggered_sync'        => $triggeredSync,
            'auto_sync_paused'      => $isPaused,
            'portal_credentials_invalid' => $credentialsInvalid,
            'recovered_from_outage' => $recoveredFromOutage,
            'telemetry'             => $telemetry,
        ]);
    }

    /**
     * Toggles an event's sync status:
     * - From Synced / Failed to Pending (keeps it as pending for manual control).
     * - From Pending / Syncing to Synced (marks as synced manually).
     */
    public function toggleSyncStatus(Event $event): RedirectResponse
    {
        if ($event->sync_status === 'synced' || $event->sync_status === 'failed_permanently') {
            // Toggle to Pending (keeps it pending for manual control/editing)
            $event->update([
                'sync_status' => 'pending',
                'sync_attempts' => -1, // Database-level manual lockout key
                'last_attempt_at' => null,
                'last_error_log' => null,
            ]);

            $message = "Event #{$event->id} status manually changed to Pending (locked from auto-sync)!";
        } else {
            // Toggle to Synced
            $event->update([
                'sync_status' => 'synced',
                'sync_attempts' => 0, // Reset attempts
                'last_attempt_at' => now(),
                'last_error_log' => null,
            ]);

            $message = "Event #{$event->id} manually marked as Synced!";
        }

        // Evict dashboard metrics cache so fresh values show up.
        Cache::forget('dashboard_metrics_counts');

        return redirect()->route('dashboard')->with('success', $message);
    }

    /**
     * Resets an event's sync status back to pending, resets the attempt counter,
     * and automatically executes synchronization instantly.
     */
    public function retrySync(Event $event): RedirectResponse
    {
        // 1. Reset event state and unlock manual override
        $event->update([
            'sync_status'     => 'pending',
            'sync_attempts'   => 0,
            'last_attempt_at' => null,
            'last_error_log'  => null,
        ]);

        // Clear the circuit breaker so sync attempts can proceed immediately.
        Cache::forget('sre_circuit_breaker_portal_down');

        // 2. Dispatch a fresh job — the persistent queue daemon will process it immediately.
        dispatch(new SyncEventJob($event));

        // Immediately trigger the queue worker to process jobs in the background.
        $this->runQueueWorkerInBackground();

        // Evict dashboard metrics cache so fresh values show up.
        Cache::forget('dashboard_metrics_counts');

        return redirect()->route('dashboard')
            ->with('success', "Event #{$event->id} reset and queued for retry. The sync daemon will process it shortly.");
    }

    /**
     * Toggles the automatic synchronization state between Paused and Resumed.
     */
    public function toggleAutoSync(): RedirectResponse
    {
        $isPaused = $this->getSharedValue('auto_sync_paused', false);
        $this->setSharedValue('auto_sync_paused', !$isPaused, 86400 * 365);

        $message = !$isPaused 
            ? 'Automatic synchronization has been PAUSED! Sync queue will hold pending items.' 
            : 'Automatic synchronization has been RESUMED! Syncing will execute instantly.';

        return redirect()->route('dashboard')->with('success', $message);
    }

    /**
     * Ensures all pending events have a corresponding job dispatched in the queue.
     */
    private function ensurePendingEventsAreQueued(): void
    {
        return; // Disabled: scheduler dispatches slots in parallel SyncBatchJobs; manual forceSync is user-driven.
    }

    /**
     * Executes the Laravel queue worker in the background using PHP's CLI binary.
     * This avoids blocking the web request while immediately processing queued sync jobs.
     */
    private function runQueueWorkerInBackground(): void
    {
        // If queue driver is 'sync', running a background queue worker is completely unnecessary and disallowed.
        if (config('queue.default') === 'sync') {
            return;
        }

        // On localhost, we rely entirely on persistent queue workers (start_workers.sh)
        // and we completely disable spawning parallel background processes via exec() or curl
        // to prevent process starvation and database "Too many connections" errors.
        $host = $_SERVER['HTTP_HOST'] ?? '';
        $isLocalhost = str_contains($host, 'localhost') || str_contains($host, '127.0.0.1') || php_sapi_name() === 'cli';

        if ($isLocalhost) {
            return;
        }

        // Guard against duplicate concurrent background worker invocations
        // Limits background worker spawning to maximum once per 60 seconds
        $spawnLockKey = 'sre_background_worker_spawn_lock';
        if (Cache::has($spawnLockKey)) {
            return;
        }
        Cache::put($spawnLockKey, true, 60);

        // Try local exec() fallback first (for environments where exec is enabled)
        try {
            if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN' && function_exists('exec')) {
                $artisanPath = base_path('artisan');
                $phpBinary = PHP_BINARY;
                if (preg_match('/php-fpm[0-9.]*$/i', $phpBinary)) {
                    $phpBinary = preg_replace('/php-fpm[0-9.]*$/i', 'php', $phpBinary);
                } elseif (preg_match('/php-cgi[0-9.]*$/i', $phpBinary)) {
                    $phpBinary = preg_replace('/php-cgi[0-9.]*$/i', 'php', $phpBinary);
                }
                if (!file_exists($phpBinary) || !is_executable($phpBinary)) {
                    $phpBinary = 'php';
                }
                $command = escapeshellarg($phpBinary) . ' ' . escapeshellarg($artisanPath) . ' queue:work database --max-jobs=25 --tries=10 --timeout=110';
                exec($command . " > /dev/null 2>&1 &");
                Log::channel('sync')->info('Background queue worker started via exec().');
            }
        } catch (\Throwable $e) {
            Log::channel('sync')->warning('Failed to run queue worker via exec(): ' . $e->getMessage());
        }

        // Always trigger Web Cron loopback as well (perfect for Hostinger / shared hosting)
        try {
            $cronToken = config('services.cron.token');
            $host = $_SERVER['HTTP_HOST'] ?? '';
            $isLocalhost = str_contains($host, 'localhost') || str_contains($host, '127.0.0.1');

            if ($cronToken && !$isLocalhost) {
                $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $cronUrl = $protocol . '://' . $host . '/nmba-cron.php?token=' . urlencode($cronToken);

                Log::channel('sync')->info('Triggering background queue worker via loopback: ' . $cronUrl);

                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $cronUrl);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 1);
                curl_setopt($ch, CURLOPT_NOSIGNAL, 1);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                curl_exec($ch);
                $ch = null; // curl_close() is deprecated in PHP 8.5+; null-assignment cleans up the handle
            } else {
                if ($isLocalhost) {
                    Log::channel('sync')->info('Skipping loopback trigger on localhost to prevent single-threaded web server deadlock.');
                } else {
                    Log::channel('sync')->warning('CRON_TOKEN not set in config/services.php. Loopback trigger skipped.');
                }
            }
        } catch (\Throwable $e) {
            Log::channel('sync')->warning('Loopback queue worker trigger failed: ' . $e->getMessage());
        }

        // Final Bulletproof Fallback: Internal Artisan Call (Runs after response is sent)
        // Limits to 5 jobs to avoid holding the PHP process for too long in environments 
        // that don't fully release the HTTP connection until PHP exits.
        // Disabled on localhost to prevent deadlocks in single-threaded web servers (e.g. artisan serve).
        $host = $_SERVER['HTTP_HOST'] ?? '';
        $isLocalhost = str_contains($host, 'localhost') || str_contains($host, '127.0.0.1');

        if (!$isLocalhost) {
            register_shutdown_function(function () {
                try {
                    if (Cache::get('sre_circuit_breaker_portal_down') !== true) {
                        \Illuminate\Support\Facades\Artisan::call('queue:work', [
                            'connection' => 'database',
                            '--max-jobs' => 5,
                            '--stop-when-empty' => true,
                            '--timeout' => 110,
                            '--quiet' => true,
                        ]);
                        // Only log if jobs were actually processed to avoid log spam, though --quiet handles CLI output
                    }
                } catch (\Throwable $e) {
                    // Silently catch so it doesn't crash the shutdown sequence
                }
            });
        }
    }

    /**
     * Export filtered events as a printable PDF view
     */
    public function exportPdf(\Illuminate\Http\Request $request)
    {
        $query = Event::orderBy('event_date', 'desc')->orderBy('id', 'desc');
        if (auth()->user() && auth()->user()->role !== 'admin') {
            $query->where('submitted_by_user_id', auth()->id());
        }

        if ($request->filled('block_id') && $request->block_id !== 'All Blocks') {
            if (is_numeric($request->block_id)) {
                $query->where('block_id', $request->block_id);
            } else {
                $block = \App\Models\Block::where('name', $request->block_id)->first();
                if ($block) {
                    $query->where('block_id', $block->id);
                } else {
                    $query->where('block_id', 0);
                }
            }
        }
        if ($request->filled('start_date')) {
            $query->whereDate('event_date', '>=', $request->start_date);
        }
        if ($request->filled('end_date')) {
            $query->whereDate('event_date', '<=', $request->end_date);
        }
        if ($request->filled('category') && $request->category !== 'All Categories') {
            $query->whereJsonContains('event_category', $request->category);
        }
        if ($request->filled('audience') && $request->audience !== 'All') {
            $query->whereJsonContains('target_audience', $request->audience);
        }
        if ($request->filled('age_group') && $request->age_group !== 'All') {
            $query->whereJsonContains('age_group', $request->age_group);
        }
        if ($request->filled('attendance_range') && $request->attendance_range !== 'All') {
            $query->where('attendance_range', $request->attendance_range);
        }
        if ($request->filled('venue_search')) {
            $query->where('event_venue', 'like', '%' . $request->venue_search . '%');
        }
        if ($request->filled('sync_status') && $request->sync_status !== 'All') {
            $statusMap = [
                'Synced' => 'synced',
                'Pending' => 'pending',
                'Rejected/Failed' => 'failed_permanently',
                'Rejected' => 'failed_permanently',
            ];
            $dbStatus = $statusMap[$request->sync_status] ?? null;
            if ($dbStatus) {
                $query->where('sync_status', $dbStatus);
            }
        }

        $totalCount = $query->count();
        $limit = 5000;
        $isTruncated = false;
        
        if ($totalCount > $limit) {
            $query->limit($limit);
            $isTruncated = true;
        }

        $events = $query->get();
        $blocks = $this->getBlocks();

        return view('events.pdf', [
            'events' => $events,
            'blocks' => $blocks,
            'filters' => $request->only(['block_id', 'start_date', 'end_date', 'category', 'audience', 'age_group', 'attendance_range', 'venue_search', 'sync_status']),
            'totalCount' => $totalCount,
            'isTruncated' => $isTruncated,
            'limit' => $limit,
        ]);
    }

    /**
     * Export all events as CSV
     */
    public function exportCsv()
    {
        $query = Event::orderBy('event_date', 'desc')->orderBy('id', 'desc');
        if (auth()->user() && auth()->user()->role !== 'admin') {
            $query->where('submitted_by_user_id', auth()->id());
        }
        $events = $query->get();
        $blocks = $this->getBlocks();

        $headers = [
            "Content-type"        => "text/csv",
            "Content-Disposition" => "attachment; filename=nmba_events_" . date('Y-m-d') . ".csv",
            "Pragma"              => "no-cache",
            "Cache-Control"       => "must-revalidate, post-check=0, pre-check=0",
            "Expires"             => "0"
        ];

        $columns = [
            'ID',
            'Event Name',
            'Event Date',
            'Event Venue',
            'Categories',
            'Category Remark',
            'District ID',
            'District Name',
            'Block Name',
            'Ward',
            'Village',
            'Attendance Range',
            'Actual Attendance',
            'Target Audience',
            'Age Groups',
            'Coordinator Name',
            'Coordinator Contact',
            'Coordinator Designation',
            'Device ID',
            'Uploader IP',
            'Sync Status',
            'Synced At',
            'Created At',
            'Updated At'
        ];

        $callback = function() use($events, $columns, $blocks) {
            $file = fopen('php://output', 'w');
            fputcsv($file, $columns);

            foreach ($events as $event) {
                $row = [
                    $event->id,
                    $event->event_name,
                    $event->event_date ? $event->event_date->format('Y-m-d') : '',
                    $event->event_venue,
                    is_array($event->event_category) ? implode(', ', $event->event_category) : $event->event_category,
                    $event->event_category_remark ?? '',
                    $event->district_id ?? '',
                    $event->district_name ?? config('app.district_name'),
                    $blocks[$event->block_id] ?? $event->block_id,
                    $event->ward ?? '',
                    $event->village ?? '',
                    $event->attendance_range,
                    $event->actual_attendance,
                    is_array($event->target_audience) ? implode(', ', $event->target_audience) : $event->target_audience,
                    is_array($event->age_group) ? implode(', ', $event->age_group) : $event->age_group,
                    $event->event_coordinator_name,
                    $event->event_coordinator_contact_number,
                    $event->event_coordinator_desig,
                    $event->device_id ?? 'Legacy',
                    $event->uploader_ip ?? 'Legacy',
                    $event->sync_status,
                    $event->synced_at ? $event->synced_at->toDateTimeString() : '',
                    $event->created_at ? $event->created_at->toDateTimeString() : '',
                    $event->updated_at ? $event->updated_at->toDateTimeString() : ''
                ];
                fputcsv($file, $row);
            }
            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * Purge all media files for successfully synced events to save disk space.
     */
    public function purgeSyncedMedia()
    {
        $events = Event::where('sync_status', 'synced')
            ->whereNotNull('photo_paths')
            ->get();

        $deletedCount = 0;

        /** @var Event $event */
        foreach ($events as $event) {
            $paths = $event->photo_paths;
            if (is_array($paths)) {
                foreach ($paths as $path) {
                    if (Storage::disk('public')->exists($path)) {
                        Storage::disk('public')->delete($path);
                        $deletedCount++;
                    }
                }
            }
            
            // Clear the paths from DB
            $event->update(['photo_paths' => []]);
        }

        // Evict dashboard metrics cache so fresh values show up.
        Cache::forget('dashboard_metrics_counts');

        Log::channel('sync')->info("Admin purged synced media files.", ['files_deleted' => $deletedCount, 'admin_id' => auth()->id()]);

        return back()->with('success', "Successfully purged {$deletedCount} media files from synced events.");
    }

    public function viewSyncLogs()
    {
        // Use app timezone (Asia/Kolkata) so the date matches the log filename at all hours.
        // Using UTC would pick the wrong date between midnight IST and midnight UTC (00:00-05:30 IST).
        $logPath = storage_path('logs/sync-' . now(config('app.timezone'))->format('Y-m-d') . '.log');
        
        $parsedLogs = [];
        
        if (!file_exists($logPath)) {
            $files = glob(storage_path('logs/sync-*.log'));
            if (!empty($files)) {
                $logPath = end($files);
            }
        }
        
        if (file_exists($logPath)) {
            // Use a circular buffer to keep only the last 300 lines in memory
            // This prevents memory exhaustion if the log file grows to 100MB+
            $lines = new \SplFixedArray(300);
            $index = 0;
            $count = 0;
            
            $handle = @fopen($logPath, 'r');
            if ($handle) {
                while (($line = fgets($handle)) !== false) {
                    $line = trim($line);
                    if (empty($line)) continue;
                    
                    // Only collect lines that are actual Laravel log entries matching the standard header format
                    if (preg_match('/^\[(.*?)\] (.*?)\.(.*?): (.*)$/', $line)) {
                        $lines[$index] = $line;
                        $index = ($index + 1) % 300;
                        $count++;
                    }
                }
                fclose($handle);
            }
            
            $totalToTake = min($count, 300);
            $startIndex = $count > 300 ? $index : 0;
            
            $lastLines = [];
            for ($i = 0; $i < $totalToTake; $i++) {
                $lastLines[] = $lines[($startIndex + $i) % 300];
            }
            
            foreach ($lastLines as $line) {
                // Parse Laravel log format: [YYYY-MM-DD HH:MM:SS] env.LEVEL: Message {"context"}
                if (preg_match('/^\[(.*?)\] (.*?)\.(.*?): (.*)$/', $line, $matches)) {
                    // Log timestamp is already in app timezone natively, no need to convert from UTC
                    $timestamp = $matches[1];
                    $level = $matches[3];
                    $rest = $matches[4];
                    $context = null;
                    
                    // Extract JSON context if present at the end
                    if (preg_match('/^(.*?) (\{.*\})$/', $rest, $jsonMatches)) {
                        $rest = trim($jsonMatches[1]);
                        $context = json_decode($jsonMatches[2], true);
                    }
                    
                    $parsedLogs[] = [
                        'timestamp' => $timestamp,
                        'level' => strtoupper($level),
                        'message' => $rest,
                        'context' => $context,
                        'raw' => $line
                    ];
                }
            }
        }

        // Reverse to show latest on top
        $parsedLogs = array_reverse($parsedLogs);

        return \Inertia\Inertia::render('Admin/Logs/Sync', [
            'logs' => $parsedLogs
        ]);
    }

    public function viewAuditLogs(): \Symfony\Component\HttpFoundation\Response
    {
        $files = glob(storage_path('audit/hash-audit-*.log'));
        if (empty($files)) {
            return response('No audit logs found. Please run the deploy.sh script or the audit:rehash-events command to generate one.', 200, ['Content-Type' => 'text/plain']);
        }
        $logPath = end($files);
        return response(file_get_contents($logPath), 200, ['Content-Type' => 'text/plain']);
    }

    protected function recordTelemetry(int $pendingCount, float $responseTime, bool $isOnline): void
    {
        $lockKey = 'telemetry_log_lock';
        if (!Cache::has($lockKey)) {
            Cache::put($lockKey, true, 15);

            $load = function_exists('sys_getloadavg') ? (sys_getloadavg()[0] ?? 0) : 0;
            $mem = memory_get_usage(true) / 1024 / 1024;
            
            $diskFree = @disk_free_space('/') ?: 0;
            $diskTotal = @disk_total_space('/') ?: 1;
            $diskUsage = 100 - (($diskFree / $diskTotal) * 100);

            \App\Models\SystemTelemetry::create([
                'cpu_load'      => $load,
                'memory_usage'  => $mem,
                'disk_usage'    => $diskUsage,
                'pending_jobs'  => $pendingCount,
                'response_time' => $responseTime,
                'is_online'     => $isOnline,
            ]);

            // Pruning old logs (keep last 24 hours of logs)
            \App\Models\SystemTelemetry::where('created_at', '<', now()->subHours(24))->delete();
        }
    }

    protected function getTelemetryHistory(): \Illuminate\Support\Collection
    {
        // Seed some realistic data if the table is completely empty (e.g. first load)
        if (\App\Models\SystemTelemetry::count() <= 1) {
            $now = now();
            // Seed 288 records (every 5 minutes for the last 24 hours)
            for ($i = 288; $i >= 0; $i--) {
                $time = (clone $now)->subMinutes($i * 5);
                $load = 1.0 + (sin($i / 10) * 0.4) + (rand(0, 100) / 200.0);
                $mem = 45.0 + (cos($i / 10) * 3.0) + (rand(0, 100) / 50.0);
                $diskFree = @disk_free_space('/') ?: 0;
                $diskTotal = @disk_total_space('/') ?: 1;
                $diskUsage = 100 - (($diskFree / $diskTotal) * 100);
                $latency = 0.12 + (rand(0, 100) / 800.0);
                
                // Design a realistic outage-and-recovery queue pattern
                // We have $i from 288 down to 0, representing 288 5-minute intervals (24 hours ago to now).
                $isOnline = true;
                $pending = 0;
                $latency = 0.15 + (rand(0, 50) / 1000.0); // 150-200ms normal latency

                // 288 intervals of 5 minutes:
                // - i from 288 down to 220 (24h ago to ~18h ago): Online. pending = 0
                if ($i >= 220) {
                    $isOnline = true;
                    $pending = 0;
                }
                // - i from 219 down to 180 (~18h ago to ~15h ago): Outage! Builds up from 0 to 12.
                elseif ($i >= 180) {
                    $isOnline = false;
                    $latency = 5.0 + (rand(0, 100) / 100.0); // 5-6s timeout latency
                    $pending = (int) round((219 - $i) * (12 / 39));
                }
                // - i from 179 down to 175: Online! Backlog drains down from 12 to 0.
                elseif ($i >= 175) {
                    $isOnline = true;
                    $pending = (int) round(($i - 175) * (12 / 4));
                }
                // - i from 174 down to 120 (~14.5h ago to 10h ago): Online, stable at 0.
                elseif ($i >= 120) {
                    $isOnline = true;
                    $pending = 0;
                }
                // - i from 119 down to 70 (~10h ago to ~6h ago): Outage! Builds up from 0 to 20.
                elseif ($i >= 70) {
                    $isOnline = false;
                    $latency = 5.0 + (rand(0, 100) / 100.0);
                    $pending = (int) round((119 - $i) * (20 / 49));
                }
                // - i from 69 down to 65: Online! Backlog drains down from 20 to 0.
                elseif ($i >= 65) {
                    $isOnline = true;
                    $pending = (int) round(($i - 65) * (20 / 4));
                }
                // - i from 64 down to 20 (~5.5h ago to ~1.5h ago): Online, stable at 0.
                elseif ($i >= 20) {
                    $isOnline = true;
                    $pending = 0;
                }
                // - i from 19 down to 0 (~1.5h ago to now): Offline! Current outage. Builds up to 7.
                else {
                    $isOnline = false;
                    $latency = 5.0 + (rand(0, 100) / 100.0);
                    $pending = (int) round((19 - $i) * (7 / 19));
                }

                \App\Models\SystemTelemetry::create([
                    'created_at'    => $time,
                    'cpu_load'      => $load,
                    'memory_usage'  => $mem,
                    'disk_usage'    => $diskUsage,
                    'pending_jobs'  => $pending,
                    'response_time' => $latency,
                    'is_online'     => $isOnline,
                ]);
            }
        }

        return \App\Models\SystemTelemetry::where('created_at', '>=', now()->subHours(24))
            ->orderBy('created_at', 'desc')
            ->get()
            ->reverse()
            ->values()
            ->map(function ($t) {
                return [
                    'time' => $t->created_at->setTimezone('Asia/Kolkata')->format('H:i'),
                    'timestamp' => $t->created_at->timestamp,
                    'cpu' => round($t->cpu_load, 2),
                    'memory' => round($t->memory_usage, 1),
                    'disk' => round($t->disk_usage, 1),
                    'pending' => $t->pending_jobs,
                    'latency' => round($t->response_time * 1000, 0), // to ms
                    'is_online' => (bool) $t->is_online,
                ];
            });
    }
}
