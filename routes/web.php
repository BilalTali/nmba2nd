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
    Route::get('/dashboard', [\App\Http\Controllers\EventController::class, 'dashboard'])->name('dashboard');
    Route::get('/admin/synced-events', [\App\Http\Controllers\EventController::class, 'syncedEventsIndex'])->name('admin.synced-events');
    Route::get('/admin/events/pdf', [\App\Http\Controllers\EventController::class, 'exportPdf'])->name('admin.events.pdf');
    Route::get('/admin/events-portal', function (\Illuminate\Http\Request $request) {
        $query = \App\Models\Event::with(['department']);

        // 1. Apply Dynamic Filters
        if ($request->filled('start_date')) {
            $query->whereDate('event_date', '>=', $request->start_date);
        }
        if ($request->filled('end_date')) {
            $query->whereDate('event_date', '<=', $request->end_date);
        }
        if ($request->filled('block_id') && $request->block_id !== 'All Blocks') {
            // Resolve block ID from name
            $block = \App\Models\Block::where('name', $request->block_id)->first();
            if ($block) {
                $query->where('block_id', $block->id);
            } else {
                $query->where('block_id', 0); // No match
            }
        }
        if ($request->filled('department_id') && $request->department_id !== 'All Departments') {
            $department = \App\Models\Department::where('name', $request->department_id)->first();
            if ($department) {
                $query->where('department_id', $department->id);
            } else {
                $query->where('department_id', 0);
            }
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

        // 2. Fetch Live Stats (based on filtered events)
        $totalEvents = (clone $query)->count();
        $totalParticipants = (clone $query)->sum('actual_attendance');
        $uniqueVenues = (clone $query)->distinct('event_venue')->count('event_venue');
        $blocksActive = (clone $query)->distinct('block_id')->count('block_id');
        
        // Active Days: Count unique event dates
        $activeDays = (clone $query)->distinct('event_date')->count('event_date');

        // 3. Prepare Chart Data
        $blocks = \App\Models\Block::pluck('name', 'id')->toArray();
        $departments = \App\Models\Department::pluck('name', 'id')->toArray();
        
        // Events by Block
        $eventsByBlockRaw = (clone $query)->select('block_id', \DB::raw('count(*) as count'))
            ->groupBy('block_id')
            ->get();
        
        $eventsByBlock = $eventsByBlockRaw->map(fn($item) => [
            'name' => $blocks[$item->block_id] ?? 'Unknown',
            'count' => (int) $item->count
        ])->sortByDesc('count')->values();

        // Events by Category
        $categoryCounts = [
            'Awareness' => 0,
            'Cultural' => 0,
            'Sports' => 0,
            'Training & Counselling' => 0
        ];
        (clone $query)->pluck('event_category')->each(function ($categories) use (&$categoryCounts) {
            if (is_array($categories)) {
                foreach ($categories as $cat) {
                    if (isset($categoryCounts[$cat])) {
                        $categoryCounts[$cat]++;
                    }
                }
            }
        });

        // Participants by Block
        $participantsByBlockRaw = (clone $query)->select('block_id', \DB::raw('sum(actual_attendance) as participants'))
            ->groupBy('block_id')
            ->get();

        $participantsByBlock = $participantsByBlockRaw->map(fn($item) => [
            'name' => $blocks[$item->block_id] ?? 'Unknown',
            'participants' => (int) $item->participants
        ])->sortByDesc('participants')->values();

        // 4. Paginated Table Records
        $events = $query->orderBy('event_date', 'desc')->orderBy('id', 'desc')->paginate(10)->withQueryString();

        return view('events.portal', [
            'totalEvents' => $totalEvents,
            'totalParticipants' => $totalParticipants,
            'uniqueVenues' => $uniqueVenues,
            'blocksActive' => $blocksActive,
            'activeDays' => $activeDays,
            'eventsByBlock' => $eventsByBlock,
            'categoryCounts' => $categoryCounts,
            'participantsByBlock' => $participantsByBlock,
            'events' => $events,
            'blocks' => $blocks,
            'departments' => $departments,
            'filters' => $request->only(['start_date', 'end_date', 'block_id', 'department_id', 'category', 'audience', 'age_group', 'attendance_range', 'venue_search', 'sync_status']),
        ]);
    })->name('admin.events.portal');

    Route::get('/admin/events-portal/export', function (\Illuminate\Http\Request $request) {
        $query = \App\Models\Event::with(['department']);

        // Apply the same filters
        if ($request->filled('start_date')) {
            $query->whereDate('event_date', '>=', $request->start_date);
        }
        if ($request->filled('end_date')) {
            $query->whereDate('event_date', '<=', $request->end_date);
        }
        if ($request->filled('block_id') && $request->block_id !== 'All Blocks') {
            $block = \App\Models\Block::where('name', $request->block_id)->first();
            if ($block) {
                $query->where('block_id', $block->id);
            } else {
                $query->where('block_id', 0);
            }
        }
        if ($request->filled('department_id') && $request->department_id !== 'All Departments') {
            $department = \App\Models\Department::where('name', $request->department_id)->first();
            if ($department) {
                $query->where('department_id', $department->id);
            } else {
                $query->where('department_id', 0);
            }
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
    Route::post('/events/{event}/toggle-sync', [\App\Http\Controllers\EventController::class, 'toggleSyncStatus'])
        ->middleware('throttle:15,1')->name('events.toggleSync');
    Route::post('/events/{event}/retry-sync', [\App\Http\Controllers\EventController::class, 'retrySync'])
        ->middleware('throttle:5,1')->name('events.retrySync');
    Route::post('/events/toggle-auto-sync', [\App\Http\Controllers\EventController::class, 'toggleAutoSync'])
        ->middleware('throttle:10,1')->name('events.toggleAutoSync');
    Route::post('/events/force-sync', [\App\Http\Controllers\EventController::class, 'forceSync'])
        ->name('events.force-sync');
    Route::post('/events/run-queue-worker', [\App\Http\Controllers\EventController::class, 'runQueueWorkerManually'])
        ->name('events.run-queue-worker');
    Route::post('/events/clear-queue', [\App\Http\Controllers\EventController::class, 'clearQueueManually'])
        ->name('events.clear-queue');
    Route::post('/events/reset-failed', [\App\Http\Controllers\EventController::class, 'resetFailedSyncs'])
        ->name('events.reset-failed');
    Route::post('/events/purge-synced-media', [\App\Http\Controllers\EventController::class, 'purgeSyncedMedia'])
        ->middleware('throttle:30,1')->name('events.purge-media');
    // Polled every 15s — allow max 60/min per user to support multiple tabs without 429 errors
    Route::get('/events/check-portal', [\App\Http\Controllers\EventController::class, 'checkPortalHealth'])
        ->middleware('throttle:60,1')->name('events.check-portal');

    // Setting env route
    Route::post('/settings/env', [\App\Http\Controllers\SettingsController::class, 'updateEnv'])
        ->middleware('throttle:10,1')->name('settings.env');

    // Diagnostic Logs
    Route::get('/admin/logs/sync', [\App\Http\Controllers\EventController::class, 'viewSyncLogs'])
        ->name('admin.logs.sync');
    Route::get('/admin/logs/audit', [\App\Http\Controllers\EventController::class, 'viewAuditLogs'])
        ->name('admin.logs.audit');
});

// Profile routes moved to admin group

// Block worker routes
Route::middleware(['auth'])->prefix('block')->name('block.')->group(function () {
    Route::get('/events', [\App\Http\Controllers\BlockEventController::class, 'index'])->name('events.index');
    Route::get('/events/create', [\App\Http\Controllers\BlockEventController::class, 'create'])->name('events.create');
    Route::post('/events', [\App\Http\Controllers\BlockEventController::class, 'store'])->name('events.store');
});

require __DIR__.'/auth.php';
