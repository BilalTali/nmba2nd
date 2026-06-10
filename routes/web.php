<?php

use App\Http\Controllers\ProfileController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    $totalEvents = \App\Models\Event::count();
    $uploadedToday = \App\Models\Event::whereDate('created_at', today())->count();
    $uploadedThisWeek = \App\Models\Event::where('created_at', '>=', now()->subDays(7)->startOfDay())->count();
    $blocksActive = \App\Models\Event::distinct('block_id')->count('block_id');

    $liveMetrics = [
        ['label' => 'Total Events Uploaded', 'value' => number_format($totalEvents)],
        ['label' => 'Uploaded Today', 'value' => number_format($uploadedToday)],
        ['label' => 'Uploaded This Week', 'value' => number_format($uploadedThisWeek)],
        ['label' => 'Active Blocks', 'value' => number_format($blocksActive)],
    ];

    // Chart Data: Events over last 7 days Area Chart
    $eventsOverTimeRaw = \App\Models\Event::where('created_at', '>=', now()->subDays(7)->startOfDay())
        ->selectRaw('DATE(created_at) as date, COUNT(*) as count')
        ->groupBy('date')
        ->pluck('count', 'date');

    $eventsOverTime = collect();
    for ($i = 7; $i >= 0; $i--) {
        $carbonDate = now()->subDays($i);
        $dateStr = $carbonDate->format('Y-m-d');
        $displayDate = $carbonDate->format('M d');
        
        $eventsOverTime->push([
            'date' => $displayDate,
            'count' => $eventsOverTimeRaw->get($dateStr, 0)
        ]);
    }

    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'liveMetrics' => $liveMetrics,
        'eventsOverTime' => $eventsOverTime,
    ]);
});

Route::middleware(['auth', 'district_access'])->group(function () {
    Route::get('/events', [\App\Http\Controllers\EventController::class, 'index'])->name('events.index');
    Route::get('/events/create', [\App\Http\Controllers\EventController::class, 'create'])->name('events.create');
    Route::post('/events', [\App\Http\Controllers\EventController::class, 'store'])->name('events.store');
    Route::get('/events/export', [\App\Http\Controllers\EventController::class, 'exportCsv'])->name('events.export');
});

Route::middleware(['auth', 'admin'])->group(function () {
    Route::get('/dashboard', [\App\Http\Controllers\DashboardController::class, 'dashboard'])->name('dashboard');
    Route::get('/admin/synced-events', [\App\Http\Controllers\EventController::class, 'syncedEventsIndex'])->name('admin.synced-events');
    Route::get('/admin/events/pdf', [\App\Http\Controllers\EventController::class, 'exportPdf'])->name('admin.events.pdf');
    Route::get('/admin/events-portal', function (\Illuminate\Http\Request $request) {
        // ── DRY FIX: Shared filter closure reused by both portal view and export ──
        // This closure is defined once and referenced below and in the export route.
        // Moving all filter logic here ensures a single point of truth.
        $filters = $request->only([
            'start_date', 'end_date', 'block_id', 'department_id',
            'category', 'audience', 'age_group', 'attendance_range',
            'venue_search', 'sync_status',
        ]);

        $applyPortalFilters = function (\Illuminate\Database\Eloquent\Builder $query) use ($request): void {
            if ($request->filled('start_date')) {
                $query->whereDate('event_date', '>=', $request->start_date);
            }
            if ($request->filled('end_date')) {
                $query->whereDate('event_date', '<=', $request->end_date);
            }
            if ($request->filled('block_id') && $request->block_id !== 'All Blocks') {
                $block = \App\Models\Block::where('name', $request->block_id)->first();
                $query->where('block_id', $block ? $block->id : 0);
            }
            if ($request->filled('department_id') && $request->department_id !== 'All Departments') {
                $department = \App\Models\Department::where('name', $request->department_id)->first();
                $query->where('department_id', $department ? $department->id : 0);
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
                    'Synced'           => 'synced',
                    'Pending'          => 'pending',
                    'Rejected/Failed'  => 'failed_permanently',
                    'Rejected'         => 'failed_permanently',
                ];
                $dbStatus = $statusMap[$request->sync_status] ?? null;
                if ($dbStatus) {
                    $query->where('sync_status', $dbStatus);
                }
            }
        };

        // Base query for filtered table records (includes eager-loaded department for the table)
        $query = \App\Models\Event::with(['department']);
        $applyPortalFilters($query);

        // BN-4 FIX: Cache all 8 heavy aggregate queries for 60 seconds,
        // keyed on the full filter set. Repeated page loads with identical
        // filters cost 0 DB queries for stats — only the paginated table row
        // fetch remains live (it must stay live for accurate pagination).
        $cacheKey = 'portal_stats_' . md5(json_encode($filters));
        $stats = \Illuminate\Support\Facades\Cache::remember($cacheKey, 60, function () use ($query) {
            // Stats base: no eager-loaded relations needed for aggregates
            $statsQuery = \App\Models\Event::query();
            // Re-apply same where clauses to a clean query (without ->with(['department']))
            foreach ($query->getQuery()->wheres as $where) {
                $statsQuery->getQuery()->wheres[] = $where;
            }
            $statsQuery->getQuery()->bindings = $query->getQuery()->bindings;

            $blocks = \App\Models\Block::pluck('name', 'id')->toArray();
            $departments = \App\Models\Department::pluck('name', 'id')->toArray();

            $totalEvents       = (clone $statsQuery)->count();
            $totalParticipants = (clone $statsQuery)->sum('actual_attendance');
            $uniqueVenues      = (clone $statsQuery)->distinct('event_venue')->count('event_venue');
            $blocksActive      = (clone $statsQuery)->distinct('block_id')->count('block_id');
            $activeDays        = (clone $statsQuery)->distinct('event_date')->count('event_date');

            // Events by Block chart
            $eventsByBlockRaw = (clone $statsQuery)
                ->select('block_id', \DB::raw('count(*) as count'))
                ->groupBy('block_id')
                ->get();
            $eventsByBlock = $eventsByBlockRaw->map(fn ($item) => [
                'name'  => $blocks[$item->block_id] ?? 'Unknown',
                'count' => (int) $item->count,
            ])->sortByDesc('count')->values();

            // Events by Category chart
            $categoryCounts = ['Awareness' => 0, 'Cultural' => 0, 'Sports' => 0, 'Training & Counselling' => 0];
            (clone $statsQuery)->pluck('event_category')->each(function ($categories) use (&$categoryCounts) {
                if (is_array($categories)) {
                    foreach ($categories as $cat) {
                        if (isset($categoryCounts[$cat])) {
                            $categoryCounts[$cat]++;
                        }
                    }
                }
            });

            // Participants by Block chart
            $participantsByBlockRaw = (clone $statsQuery)
                ->select('block_id', \DB::raw('sum(actual_attendance) as participants'))
                ->groupBy('block_id')
                ->get();
            $participantsByBlock = $participantsByBlockRaw->map(fn ($item) => [
                'name'         => $blocks[$item->block_id] ?? 'Unknown',
                'participants' => (int) $item->participants,
            ])->sortByDesc('participants')->values();

            return compact(
                'totalEvents', 'totalParticipants', 'uniqueVenues', 'blocksActive',
                'activeDays', 'eventsByBlock', 'categoryCounts', 'participantsByBlock',
                'blocks', 'departments'
            );
        });

        // Paginated table — always live (pagination must reflect the true current state)
        $events = $query->orderBy('event_date', 'desc')->orderBy('id', 'desc')->paginate(10)->withQueryString();

        return view('events.portal', array_merge($stats, [
            'events'  => $events,
            'filters' => $filters,
        ]));
    })->name('admin.events.portal');

    Route::get('/admin/events-portal/export', function (\Illuminate\Http\Request $request) {
        // DRY FIX: Extract shared filter logic to avoid duplication with portal view route.
        $applyPortalFilters = function (\Illuminate\Database\Eloquent\Builder $query) use ($request): void {
            if ($request->filled('start_date')) {
                $query->whereDate('event_date', '>=', $request->start_date);
            }
            if ($request->filled('end_date')) {
                $query->whereDate('event_date', '<=', $request->end_date);
            }
            if ($request->filled('block_id') && $request->block_id !== 'All Blocks') {
                $block = \App\Models\Block::where('name', $request->block_id)->first();
                $query->where('block_id', $block ? $block->id : 0);
            }
            if ($request->filled('department_id') && $request->department_id !== 'All Departments') {
                $department = \App\Models\Department::where('name', $request->department_id)->first();
                $query->where('department_id', $department ? $department->id : 0);
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
                    'Synced'          => 'synced',
                    'Pending'         => 'pending',
                    'Rejected/Failed' => 'failed_permanently',
                    'Rejected'        => 'failed_permanently',
                ];
                $dbStatus = $statusMap[$request->sync_status] ?? null;
                if ($dbStatus) {
                    $query->where('sync_status', $dbStatus);
                }
            }
        };

        $query = \App\Models\Event::with(['department']);
        $applyPortalFilters($query);

        $events = $query->orderBy('event_date', 'desc')->orderBy('id', 'desc')->get();
        $blocks = \App\Models\Block::pluck('name', 'id')->toArray();

        $headers = [
            "Content-type"        => "text/csv",
            "Content-Disposition" => "attachment; filename=nmba_budgam_admin_portal_" . date('Y-m-d') . ".csv",
            "Pragma"              => "no-cache",
            "Cache-Control"       => "must-revalidate, post-check=0, pre-check=0",
            "Expires"             => "0"
        ];

        $columns = [
            'ID', 'Event Name', 'Event Date', 'Event Venue', 'Categories',
            'Block Name', 'Department', 'Village', 'Attendance', 'Audience', 'Coordinator', 'Contact'
        ];

        $callback = function () use ($events, $columns, $blocks) {
            $file = fopen('php://output', 'w');
            fputcsv($file, $columns);

            foreach ($events as $event) {
                $row = [
                    $event->id,
                    $event->event_name,
                    $event->event_date ? $event->event_date->format('Y-m-d') : '',
                    $event->event_venue,
                    is_array($event->event_category) ? implode(', ', $event->event_category) : $event->event_category,
                    $blocks[$event->block_id] ?? 'Unknown',
                    $event->department->name ?? '',
                    $event->village ?? '',
                    $event->actual_attendance,
                    is_array($event->target_audience) ? implode(', ', $event->target_audience) : $event->target_audience,
                    $event->event_coordinator_name,
                    $event->event_coordinator_contact_number
                ];
                fputcsv($file, $row);
            }
            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    })->name('admin.events.portal.export');

    Route::resource('users', \App\Http\Controllers\UserController::class);

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
    
    // Admin routes — rate limited to prevent self-inflicted DoS
    // Sync management — write operations (SyncManagementController)
    Route::post('/events/{event}/toggle-sync', [\App\Http\Controllers\SyncManagementController::class, 'toggleSyncStatus'])
        ->middleware('throttle:15,1')->name('events.toggleSync');
    Route::post('/events/{event}/retry-sync', [\App\Http\Controllers\SyncManagementController::class, 'retrySync'])
        ->middleware('throttle:5,1')->name('events.retrySync');
    Route::post('/events/toggle-auto-sync', [\App\Http\Controllers\SyncManagementController::class, 'toggleAutoSync'])
        ->middleware('throttle:10,1')->name('events.toggleAutoSync');
    Route::post('/events/force-sync', [\App\Http\Controllers\SyncManagementController::class, 'forceSync'])
        ->name('events.force-sync');
    Route::post('/events/run-queue-worker', [\App\Http\Controllers\SyncManagementController::class, 'runQueueWorkerManually'])
        ->name('events.run-queue-worker');
    Route::post('/events/clear-queue', [\App\Http\Controllers\SyncManagementController::class, 'clearQueueManually'])
        ->name('events.clear-queue');
    Route::post('/events/reset-failed', [\App\Http\Controllers\SyncManagementController::class, 'resetFailedSyncs'])
        ->name('events.reset-failed');
    Route::post('/events/purge-synced-media', [\App\Http\Controllers\SyncManagementController::class, 'purgeSyncedMedia'])
        ->middleware('throttle:30,1')->name('events.purge-media');
    // Polled every 15s — allow max 60/min per user to support multiple tabs without 429 errors
    Route::get('/events/check-portal', [\App\Http\Controllers\DashboardController::class, 'checkPortalHealth'])
        ->middleware('throttle:60,1')->name('events.check-portal');

    // Credentials
    Route::post('/settings/env', [\App\Http\Controllers\SettingsController::class, 'updateEnv'])
        ->middleware('throttle:10,1')->name('settings.env');

    // Diagnostic Logs (DashboardController)
    Route::get('/admin/logs/sync', [\App\Http\Controllers\DashboardController::class, 'viewSyncLogs'])
        ->name('admin.logs.sync');
    Route::get('/admin/logs/audit', [\App\Http\Controllers\DashboardController::class, 'viewAuditLogs'])
        ->name('admin.logs.audit');
    Route::get('/admin/sync-report', [\App\Http\Controllers\DashboardController::class, 'getSyncReport'])
        ->name('admin.sync-report');
});

// Profile routes moved to admin group

// Block worker routes
Route::middleware(['auth'])->prefix('block')->name('block.')->group(function () {
    Route::get('/events', [\App\Http\Controllers\BlockEventController::class, 'index'])->name('events.index');
    Route::get('/events/create', [\App\Http\Controllers\BlockEventController::class, 'create'])->name('events.create');
    Route::post('/events', [\App\Http\Controllers\BlockEventController::class, 'store'])->name('events.store');
});

require __DIR__.'/auth.php';
