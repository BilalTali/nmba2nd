<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NMBA Campaign Report – District Budgam</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <style>
        :root {
            --primary: #1a3c6e;
            --primary-light: #2b5c9e;
            --accent: #e8a020;
            --text-dark: #0f172a;
            --text-muted: #475569;
            --border: #e2e8f0;
            --bg-light: #f8fafc;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Outfit', 'Inter', sans-serif;
            color: var(--text-dark);
            background: #fff;
            font-size: 11px;
            line-height: 1.4;
            padding: 40px;
        }

        /* Report Header Banner */
        .report-header {
            border-bottom: 3px double var(--primary);
            padding-bottom: 20px;
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
        }

        .logo-section {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .logo-icon {
            width: 48px;
            height: 48px;
            background: var(--accent);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 20px;
            color: #fff;
            box-shadow: 0 4px 10px rgba(232, 160, 32, 0.3);
        }

        .logo-text h1 {
            font-size: 20px;
            font-weight: 800;
            color: var(--primary);
            letter-spacing: -0.5px;
            text-transform: uppercase;
        }

        .logo-text p {
            font-size: 11px;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-top: 2px;
        }

        .report-meta {
            text-align: right;
            font-family: 'Inter', sans-serif;
        }

        .report-title {
            font-size: 14px;
            font-weight: 700;
            color: var(--primary-light);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 4px;
        }

        .meta-item {
            font-size: 10px;
            color: var(--text-muted);
            margin-top: 2px;
        }

        /* Filter Summary Section */
        .filter-summary {
            background: var(--bg-light);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 12px 18px;
            margin-bottom: 25px;
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            font-size: 11px;
        }

        .filter-tag {
            font-weight: 500;
            color: var(--text-muted);
        }

        .filter-tag strong {
            color: var(--primary);
        }

        /* Data Table Styling */
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 30px;
        }

        th {
            background-color: var(--primary);
            color: #fff;
            font-weight: 700;
            text-transform: uppercase;
            font-size: 10px;
            letter-spacing: 0.5px;
            padding: 10px 12px;
            text-align: left;
            border: 1px solid var(--primary);
        }

        td {
            padding: 12px;
            border: 1px solid var(--border);
            vertical-align: top;
            font-family: 'Inter', sans-serif;
        }

        tr:nth-child(even) {
            background-color: var(--bg-light);
        }

        .event-id {
            font-size: 10px;
            font-weight: 700;
            color: var(--text-muted);
            margin-top: 2px;
        }

        .event-name {
            font-family: 'Outfit', sans-serif;
            font-size: 12px;
            font-weight: 700;
            color: var(--primary);
        }

        .badge {
            display: inline-block;
            font-size: 8px;
            font-weight: 800;
            text-transform: uppercase;
            padding: 2px 6px;
            border-radius: 4px;
            margin-top: 4px;
            margin-right: 4px;
            letter-spacing: 0.3px;
        }

        .badge-cat {
            background-color: #eff6ff;
            color: #1d4ed8;
            border: 1px solid #bfdbfe;
        }

        .badge-aud {
            background-color: #f1f5f9;
            color: #475569;
            border: 1px solid #cbd5e1;
        }

        .badge-age {
            background-color: #f5f3ff;
            color: #6d28d9;
            border: 1px solid #ddd6fe;
        }

        .badge-status {
            background-color: #ecfdf5;
            color: #047857;
            border: 1px solid #a7f3d0;
        }

        .badge-status-pending {
            background-color: #fffbeb;
            color: #b45309;
            border: 1px solid #fde68a;
        }

        .badge-status-failed {
            background-color: #fff1f2;
            color: #be123c;
            border: 1px solid #fecdd3;
        }

        .text-bold {
            font-weight: 600;
        }

        .text-slate-500 {
            color: var(--text-muted);
        }

        .coordinator-card {
            font-size: 11px;
        }

        .coordinator-name {
            font-weight: 700;
            color: var(--text-dark);
        }

        .coordinator-desig {
            font-size: 10px;
            color: var(--text-muted);
            margin-top: 1px;
        }

        /* Printable Page-break rules */
        @media print {
            body {
                padding: 0;
                font-size: 10px;
            }

            .no-print {
                display: none !important;
            }

            table {
                page-break-inside: auto;
            }

            tr {
                page-break-inside: avoid;
                page-break-after: auto;
            }

            thead {
                display: table-header-group;
            }

            /* Set portrait print orientation and custom margin page numbering */
            @page {
                size: A4 portrait;
                margin: 15mm 15mm 15mm 15mm;
                @top-left { content: none !important; }
                @top-right { content: none !important; }
                @bottom-left { content: none !important; }
                @bottom-right {
                    content: "Page " counter(page) " of " counter(pages);
                    font-family: 'Outfit', 'Inter', sans-serif;
                    font-size: 8px;
                    color: #475569;
                    font-weight: 600;
                }
            }
        }

        /* Action bar for screen view (hide on print) */
        .screen-header-bar {
            background: #1e293b;
            color: #fff;
            padding: 10px 40px;
            margin: -40px -40px 40px -40px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-family: 'Outfit', sans-serif;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }

        .screen-header-bar h2 {
            font-size: 14px;
            font-weight: 600;
        }

        .btn-print {
            background: var(--accent);
            color: #fff;
            border: none;
            padding: 6px 16px;
            border-radius: 6px;
            font-weight: 700;
            font-size: 11px;
            cursor: pointer;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            transition: background 0.2s;
        }

        .btn-print:hover {
            background: #d6921b;
        }
    </style>
</head>
<body>

    <!-- Action bar shown ONLY on screen, hidden when printing -->
    <div class="screen-header-bar no-print">
        <h2>Report Preview Mode (Admin Only)</h2>
        <button onclick="window.print()" class="btn-print">Print / Save as PDF</button>
    </div>

    <!-- Official Report Header -->
    <header class="report-header">
        <div class="logo-section">
            <div class="logo-icon">J&K</div>
            <div class="logo-text">
                <h1>Nasha Mukt Bharat Abhiyaan</h1>
                <p>Office of the District Magistrate, Budgam</p>
            </div>
        </div>
        <div class="report-meta">
            <div class="report-title">Campaign Events Directory</div>
            <div class="meta-item">Generated: <span class="text-bold">{{ date('d M Y, h:i A') }}</span></div>
            <div class="meta-item">Record Count: <span class="text-bold">{{ count($events) }} events</span></div>
        </div>
    </header>

    <!-- Filter Context Summary -->
    <section class="filter-summary">
        <div class="filter-tag">
            Jurisdiction Block: 
            <strong>
                @if(!empty($filters['block_id']))
                    @if(is_numeric($filters['block_id']))
                        {{ $blocks[$filters['block_id']] ?? 'Unknown' }}
                    @else
                        {{ $filters['block_id'] }}
                    @endif
                @else
                    All Blocks
                @endif
            </strong>
        </div>
        <div class="filter-tag">
            Date Range: 
            <strong>
                @if(!empty($filters['start_date']) && !empty($filters['end_date']))
                    {{ date('d M Y', strtotime($filters['start_date'])) }} to {{ date('d M Y', strtotime($filters['end_date'])) }}
                @elseif(!empty($filters['start_date']))
                    From {{ date('d M Y', strtotime($filters['start_date'])) }}
                @elseif(!empty($filters['end_date']))
                    Until {{ date('d M Y', strtotime($filters['end_date'])) }}
                @else
                    All Historical Records
                @endif
            </strong>
        </div>
        @if(!empty($filters['category']) && $filters['category'] !== 'All Categories')
            <div class="filter-tag">Category: <strong>{{ $filters['category'] }}</strong></div>
        @endif
        @if(!empty($filters['audience']) && $filters['audience'] !== 'All')
            <div class="filter-tag">Audience: <strong>{{ $filters['audience'] }}</strong></div>
        @endif
        @if(!empty($filters['age_group']) && $filters['age_group'] !== 'All')
            <div class="filter-tag">Age Group: <strong>{{ $filters['age_group'] }}</strong></div>
        @endif
        @if(!empty($filters['attendance_range']) && $filters['attendance_range'] !== 'All')
            <div class="filter-tag">Attendance Range: <strong>{{ $filters['attendance_range'] }}</strong></div>
        @endif
        @if(!empty($filters['venue_search']))
            <div class="filter-tag">Venue Search: <strong>"{{ $filters['venue_search'] }}"</strong></div>
        @endif
        @if(!empty($filters['sync_status']) && $filters['sync_status'] !== 'All')
            <div class="filter-tag">Sync Status: <strong>{{ $filters['sync_status'] }}</strong></div>
        @endif
        <div class="filter-tag">
            Export Access Level: <strong>Administrative (Full Audit Logs)</strong>
        </div>
    </section>

    <!-- Records Table -->
    <table>
        <thead>
            <tr>
                <th style="width: 5%;">S.No</th>
                <th style="width: 8%;">ID</th>
                <th style="width: 25%;">Event Details</th>
                <th style="width: 20%;">Venue & Location</th>
                <th style="width: 12%;">Date</th>
                <th style="width: 11%;">Headcount</th>
                <th style="width: 19%;">Coordinator</th>
            </tr>
        </thead>
        <tbody>
            @forelse($events as $event)
                <tr>
                    <td style="font-size: 10px; font-weight: 700; color: var(--text-muted); text-align: center;">{{ $loop->iteration }}</td>
                    <td class="text-bold">#{{ $event->id }}</td>
                    <td>
                        <div class="event-name">{{ $event->event_name }}</div>
                        
                        <!-- Categories -->
                        @if(is_array($event->event_category))
                            @foreach($event->event_category as $cat)
                                <span class="badge badge-cat">{{ $cat }}</span>
                            @endforeach
                        @elseif(!empty($event->event_category))
                            @php
                                $categories = json_decode($event->event_category, true);
                            @endphp
                            @if(is_array($categories))
                                @foreach($categories as $cat)
                                    <span class="badge badge-cat">{{ $cat }}</span>
                                @endforeach
                            @else
                                <span class="badge badge-cat">{{ $event->event_category }}</span>
                            @endif
                        @endif
                        
                        <!-- Target Audience -->
                        @if(is_array($event->target_audience))
                            @foreach($event->target_audience as $aud)
                                <span class="badge badge-aud">{{ $aud }}</span>
                            @endforeach
                        @elseif(!empty($event->target_audience))
                            @php
                                $audiences = json_decode($event->target_audience, true);
                            @endphp
                            @if(is_array($audiences))
                                @foreach($audiences as $aud)
                                    <span class="badge badge-aud">{{ $aud }}</span>
                                @endforeach
                            @endif
                        @endif

                        <!-- Age Groups -->
                        @if(is_array($event->age_group))
                            @foreach($event->age_group as $age)
                                <span class="badge badge-age">{{ $age }}</span>
                            @endforeach
                        @elseif(!empty($event->age_group))
                            @php
                                $ages = json_decode($event->age_group, true);
                            @endphp
                            @if(is_array($ages))
                                @foreach($ages as $age)
                                    <span class="badge badge-age">{{ $age }}</span>
                                @endforeach
                            @endif
                        @endif
                    </td>
                    <td>
                        <div class="text-bold" style="color: var(--primary);">{{ $blocks[$event->block_id] ?? 'Unknown Block' }}</div>
                        <div class="text-slate-500" style="font-size: 11px; margin-top: 2px;">
                            {{ $event->event_venue }}
                            @if($event->village) — {{ $event->village }} @endif
                            @if($event->ward) (Ward: {{ $event->ward }}) @endif
                        </div>
                    </td>
                    <td class="text-bold" style="color: var(--primary-light);">
                        {{ $event->event_date ? $event->event_date->format('d M Y') : 'N/A' }}
                    </td>
                    <td>
                        <div class="text-bold">{{ number_format($event->actual_attendance) }}</div>
                        <div class="text-slate-500" style="font-size: 9px;">Range: {{ $event->attendance_range }}</div>
                    </td>
                    <td>
                        <div class="coordinator-card">
                            <div class="coordinator-name">{{ $event->event_coordinator_name }}</div>
                            <div class="coordinator-desig">{{ $event->event_coordinator_desig }}</div>
                            <div class="text-slate-500" style="font-size: 10px; margin-top: 2px;">Call: {{ $event->event_coordinator_contact_number }}</div>
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" style="text-align: center; padding: 40px; color: var(--text-muted); font-style: italic;">
                        No campaign records found matching the active directory query parameters.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <!-- Script is no longer auto-triggering print to avoid Out of Memory crashes. User can manually print using the print button. -->
</body>
</html>
