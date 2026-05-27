<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NMBA District Budgam – Public Events & Analytics Portal</title>
    
    <!-- Google Fonts Outfit & Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800;900&family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- Chart.js CDN -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>

    <style>
        :root {
            --primary: #0d2447;
            --primary-light: #1a3c6e;
            --accent: #e8a020;
            --accent-glow: rgba(232, 160, 32, 0.15);
            --success: #10b981;
            --danger: #ef4444;
            --info: #3b82f6;
            --bg: #f8fafc;
            --card: #ffffff;
            --border: #e2e8f0;
            --text: #0f172a;
            --muted: #64748b;
            --shadow: 0 4px 20px -2px rgba(13, 36, 71, 0.05), 0 2px 8px -1px rgba(13, 36, 71, 0.03);
            --radius-lg: 24px;
            --radius-md: 16px;
            --radius-sm: 10px;
            --transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg);
            color: var(--text);
            font-size: 13px;
            line-height: 1.5;
            -webkit-font-smoothing: antialiased;
        }

        h1, h2, h3, h4, .outfit-font {
            font-family: 'Outfit', sans-serif;
        }

        /* Premium Header Styling */
        header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
            color: #ffffff;
            padding: 0 2rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            height: 70px;
            box-shadow: 0 4px 20px rgba(13, 36, 71, 0.15);
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .logo-icon-container {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            background: var(--accent);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.4rem;
            box-shadow: 0 4px 12px rgba(232, 160, 32, 0.3);
            animation: pulse-glow 2.5s infinite alternate;
        }

        @keyframes pulse-glow {
            0% { box-shadow: 0 4px 12px rgba(232, 160, 32, 0.3); }
            100% { box-shadow: 0 4px 20px rgba(232, 160, 32, 0.6); }
        }

        .logo-text h1 {
            font-size: 1.2rem;
            font-weight: 800;
            letter-spacing: -0.5px;
            line-height: 1.2;
        }

        .logo-text p {
            font-size: 0.72rem;
            opacity: 0.8;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .nav-btn {
            background: rgba(255, 255, 255, 0.1);
            color: #ffffff;
            text-decoration: none;
            padding: 0.6rem 1.2rem;
            border-radius: var(--radius-sm);
            font-weight: 600;
            font-size: 0.85rem;
            border: 1px solid rgba(255, 255, 255, 0.1);
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .nav-btn:hover {
            background: #ffffff;
            color: var(--primary);
            box-shadow: 0 4px 12px rgba(255, 255, 255, 0.2);
            transform: translateY(-1px);
        }

        .nav-btn-accent {
            background: var(--accent);
            color: #ffffff;
            border-color: var(--accent);
        }

        .nav-btn-accent:hover {
            background: #ffffff;
            color: var(--accent);
            border-color: #ffffff;
        }

        /* Banner section */
        .hero-banner {
            background: linear-gradient(rgba(13, 36, 71, 0.88), rgba(26, 60, 110, 0.94)), url('https://images.unsplash.com/photo-1517486808906-6ca8b3f04846?auto=format&fit=crop&q=80&w=1200') no-repeat center center/cover;
            color: #ffffff;
            padding: 3rem 2rem;
            text-align: center;
            border-bottom-left-radius: 30px;
            border-bottom-right-radius: 30px;
            margin-bottom: 2rem;
            box-shadow: var(--shadow);
            border-bottom: 4px solid var(--accent);
        }

        .hero-banner h2 {
            font-size: 2.2rem;
            font-weight: 950;
            letter-spacing: -0.8px;
            margin-bottom: 0.6rem;
            color: #ffffff;
        }

        .hero-banner p {
            font-size: 1rem;
            opacity: 0.9;
            max-width: 600px;
            margin: 0 auto;
            font-weight: 400;
            line-height: 1.6;
        }

        /* Main layout styling */
        main {
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 2rem 4rem 2rem;
        }

        /* Filter Section Styling */
        .filter-card {
            background: var(--card);
            border-radius: var(--radius-md);
            box-shadow: var(--shadow);
            border: 1px solid var(--border);
            padding: 1.5rem;
            margin-bottom: 2rem;
            transition: var(--transition);
        }

        .filter-card h3 {
            font-size: 0.85rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: var(--muted);
            margin-bottom: 1.2rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            align-items: end;
        }

        .fg {
            display: flex;
            flex-direction: column;
            gap: 0.4rem;
        }

        .fg label {
            font-size: 0.72rem;
            font-weight: 700;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .fg select, .fg input {
            width: 100%;
            padding: 0.7rem 0.9rem;
            border: 1.5px solid var(--border);
            border-radius: var(--radius-sm);
            font-family: inherit;
            font-size: 0.85rem;
            font-weight: 500;
            color: var(--text);
            background-color: var(--bg);
            transition: var(--transition);
            outline: none;
        }

        .fg select:focus, .fg input:focus {
            border-color: var(--primary-light);
            background-color: #ffffff;
            box-shadow: 0 0 0 3px rgba(26, 60, 110, 0.1);
        }

        .filter-actions {
            display: flex;
            gap: 0.8rem;
            margin-top: 1.5rem;
            justify-content: flex-end;
            border-top: 1px solid var(--border);
            padding-top: 1.2rem;
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border-radius: var(--radius-sm);
            font-size: 0.85rem;
            font-weight: 700;
            cursor: pointer;
            transition: var(--transition);
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
        }

        .btn-primary {
            background: var(--primary-light);
            color: #ffffff;
            box-shadow: 0 4px 12px rgba(26, 60, 110, 0.2);
        }

        .btn-primary:hover {
            background: var(--primary);
            transform: translateY(-1px);
            box-shadow: 0 6px 16px rgba(26, 60, 110, 0.3);
        }

        .btn-secondary {
            background: #ffffff;
            color: var(--muted);
            border: 1.5px solid var(--border);
        }

        .btn-secondary:hover {
            background: var(--bg);
            color: var(--text);
            border-color: var(--muted);
        }

        .btn-accent {
            background: var(--accent);
            color: #ffffff;
            box-shadow: 0 4px 12px rgba(232, 160, 32, 0.2);
        }

        .btn-accent:hover {
            background: #d4901b;
            transform: translateY(-1px);
            box-shadow: 0 6px 16px rgba(232, 160, 32, 0.35);
        }

        /* Live Metrics Cards Section */
        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 1.2rem;
            margin-bottom: 2rem;
        }

        @media (max-width: 1024px) {
            .metrics-grid {
                grid-template-columns: repeat(3, 1fr);
            }
        }

        @media (max-width: 768px) {
            .metrics-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 480px) {
            .metrics-grid {
                grid-template-columns: 1fr;
            }
        }

        .metric-card {
            background: var(--card);
            border-radius: var(--radius-md);
            border: 1px solid var(--border);
            padding: 1.2rem 1.5rem;
            box-shadow: var(--shadow);
            position: relative;
            overflow: hidden;
            transition: var(--transition);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        .metric-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 5px;
            height: 100%;
            background: var(--primary-light);
        }

        .metric-card.m-events::before { background: var(--primary-light); }
        .metric-card.m-participants::before { background: var(--accent); }
        .metric-card.m-venues::before { background: var(--info); }
        .metric-card.m-blocks::before { background: var(--success); }
        .metric-card.m-days::before { background: var(--danger); }

        .metric-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 25px -5px rgba(13, 36, 71, 0.1), 0 8px 10px -6px rgba(13, 36, 71, 0.05);
        }

        .metric-label {
            font-size: 0.72rem;
            font-weight: 700;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: 0.8px;
            margin-bottom: 0.5rem;
        }

        .metric-value {
            font-size: 1.8rem;
            font-weight: 900;
            color: var(--text);
            letter-spacing: -0.5px;
            line-height: 1.1;
        }

        .metric-trend {
            font-size: 0.72rem;
            font-weight: 600;
            color: var(--muted);
            margin-top: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.2rem;
        }

        .metric-trend span {
            color: var(--primary-light);
            font-weight: 700;
        }

        /* Charts Layout Styling */
        .charts-container {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
            margin-bottom: 2.5rem;
        }

        @media (max-width: 1024px) {
            .charts-container {
                grid-template-columns: 1fr;
            }
        }

        .chart-card {
            background: var(--card);
            border-radius: var(--radius-md);
            border: 1px solid var(--border);
            padding: 1.5rem;
            box-shadow: var(--shadow);
            display: flex;
            flex-direction: column;
        }

        .chart-card-tall {
            grid-column: span 2;
        }

        @media (max-width: 1024px) {
            .chart-card-tall {
                grid-column: span 1;
            }
        }

        .chart-card h3 {
            font-size: 0.95rem;
            font-weight: 800;
            color: var(--primary);
            margin-bottom: 1.2rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid var(--border);
            padding-bottom: 0.6rem;
        }

        .chart-card h3 span {
            font-size: 0.72rem;
            color: var(--muted);
            font-weight: 500;
            text-transform: uppercase;
        }

        .chart-wrapper {
            position: relative;
            height: 280px;
            width: 100%;
        }

        /* Data Table Cards */
        .table-card {
            background: var(--card);
            border-radius: var(--radius-md);
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
            margin-bottom: 2rem;
            overflow: hidden;
        }

        .table-card-header {
            padding: 1.5rem;
            background: #ffffff;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .table-card-header h3 {
            font-size: 1.1rem;
            font-weight: 850;
            color: var(--primary);
        }

        .table-container {
            width: 100%;
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
        }

        th {
            background: #f8fafc;
            padding: 1rem 1.2rem;
            font-size: 0.72rem;
            font-weight: 800;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: 0.8px;
            border-bottom: 2px solid var(--border);
        }

        td {
            padding: 1.1rem 1.2rem;
            border-bottom: 1px solid var(--border);
            vertical-align: middle;
        }

        tr {
            transition: var(--transition);
        }

        tr:hover {
            background-color: rgba(248, 250, 252, 0.7);
        }

        /* Event Row Styles */
        .event-name {
            font-family: 'Outfit', sans-serif;
            font-size: 0.95rem;
            font-weight: 750;
            color: var(--primary);
            margin-bottom: 0.2rem;
            text-transform: capitalize;
        }

        .event-id {
            font-size: 0.72rem;
            color: var(--muted);
            font-weight: 600;
        }

        .event-venue {
            font-weight: 600;
            color: var(--text);
            text-transform: capitalize;
        }

        .event-block {
            font-size: 0.75rem;
            color: var(--muted);
            margin-top: 0.2rem;
            font-weight: 500;
        }

        .badges {
            display: flex;
            flex-wrap: wrap;
            gap: 0.3rem;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            padding: 0.25rem 0.6rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .badge-cat {
            background: #eff6ff;
            color: var(--info);
            border: 1px solid #dbeafe;
        }

        .badge-aud {
            background: #fdf2f8;
            color: #db2777;
            border: 1px solid #fce7f3;
        }

        .badge-age {
            background: #f0fdf4;
            color: var(--success);
            border: 1px solid #dcfce7;
        }

        .event-attendance {
            font-family: 'Outfit', sans-serif;
            font-size: 1.1rem;
            font-weight: 850;
            color: var(--primary);
        }

        .event-range {
            font-size: 0.7rem;
            color: var(--muted);
            font-weight: 600;
            margin-top: 0.15rem;
        }

        .demo-group {
            display: flex;
            align-items: center;
            gap: 0.4rem;
            flex-wrap: wrap;
        }

        .demo-group.mt-1 {
            margin-top: 0.4rem;
        }

        .demo-label {
            font-size: 0.68rem;
            font-weight: 700;
            color: var(--muted);
            text-transform: uppercase;
            width: 60px;
            flex-shrink: 0;
        }

        .coordinator-name {
            font-weight: 600;
            color: var(--text);
        }

        .coordinator-contact {
            font-size: 0.72rem;
            color: var(--muted);
            margin-top: 0.2rem;
            font-weight: 600;
        }

        /* Pagination Overrides styling */
        .pagination-container {
            padding: 1.2rem 1.5rem;
            background: #ffffff;
            border-top: 1px solid var(--border);
        }

        .pagination-container nav {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .pagination-container nav div:first-child p {
            color: var(--muted);
            font-size: 0.85rem;
            font-weight: 500;
        }

        .pagination-container nav div:first-child p span {
            font-weight: 700;
            color: var(--primary);
        }

        .pagination-container nav div:last-child {
            display: inline-flex;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            border-radius: var(--radius-sm);
            overflow: hidden;
            border: 1px solid var(--border);
        }

        .pagination-container nav div:last-child a, 
        .pagination-container nav div:last-child span[aria-current="page"] span,
        .pagination-container nav div:last-child span[disabled] span,
        .pagination-container nav div:last-child span {
            padding: 0.6rem 0.9rem !important;
            font-size: 0.85rem !important;
            font-weight: 600 !important;
            text-decoration: none !important;
            background-color: #ffffff !important;
            color: var(--text) !important;
            border: none !important;
            border-right: 1px solid var(--border) !important;
            transition: var(--transition);
        }

        .pagination-container nav div:last-child a:last-child,
        .pagination-container nav div:last-child span:last-child {
            border-right: none !important;
        }

        .pagination-container nav div:last-child a:hover {
            background-color: var(--bg) !important;
            color: var(--primary-light) !important;
        }

        .pagination-container nav div:last-child span[aria-current="page"] span {
            background-color: var(--primary-light) !important;
            color: #ffffff !important;
        }

        .pagination-container nav svg {
            width: 1.1rem;
            height: 1.1rem;
            vertical-align: middle;
        }

        /* Footer styling */
        footer {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
            color: #ffffff;
            padding: 3rem 2rem;
            text-align: center;
            border-top: 4px solid var(--accent);
            margin-top: auto;
        }

        footer p {
            font-size: 0.85rem;
            opacity: 0.8;
            font-weight: 500;
        }

        footer p.copyright {
            font-size: 0.75rem;
            opacity: 0.6;
            margin-top: 0.5rem;
        }
    </style>
</head>
<body>

    <!-- Header Panel -->
    <header>
        <div class="logo">
            <div class="logo-icon-container">🎗️</div>
            <div class="logo-text">
                <h1>District Budgam</h1>
                <p>Nasha Mukt Bharat Abhiyaan</p>
            </div>
        </div>
        
        <div class="header-actions">
            <a href="{{ route('dashboard') }}" class="nav-btn nav-btn-accent">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path></svg>
                Admin Dashboard
            </a>
        </div>
    </header>

    <!-- Top Hero Banner -->
    <section class="hero-banner">
        <h2>NMBA Events Portal & Analytics</h2>
        <p>A comprehensive real-time transparency and reporting platform for the Nasha Mukt Bharat Abhiyaan (Drug-Free India Campaign) across all blocks of District Budgam, Jammu & Kashmir.</p>
    </section>

    <!-- Main Workspace -->
    <main>

        <!-- Dynamic Filter Panel -->
        <section class="filter-card">
            <h3>
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"></path></svg>
                Filter Portal Data
            </h3>
            
            <form method="GET" action="{{ route('admin.events.portal') }}" id="filterForm">
                <div class="filter-grid">
                    
                    <!-- Date Pickers -->
                    <div class="fg">
                        <label>Start Date</label>
                        <input type="date" name="start_date" value="{{ $filters['start_date'] ?? '' }}">
                    </div>

                    <div class="fg">
                        <label>End Date</label>
                        <input type="date" name="end_date" value="{{ $filters['end_date'] ?? '' }}">
                    </div>

                    <!-- Jurisdiction Dropdown -->
                    <div class="fg">
                        <label>Block Jurisdiction</label>
                        <select name="block_id">
                            <option value="All Blocks">All Blocks</option>
                            @foreach($blocks as $id => $name)
                                <option value="{{ $name }}" {{ ($filters['block_id'] ?? '') === $name ? 'selected' : '' }}>{{ $name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <!-- Category Dropdown -->
                    <div class="fg">
                        <label>Event Category</label>
                        <select name="category">
                            <option value="All Categories">All Categories</option>
                            @foreach(['Cultural', 'Awareness', 'Sports', 'Training & Counselling'] as $cat)
                                <option value="{{ $cat }}" {{ ($filters['category'] ?? '') === $cat ? 'selected' : '' }}>{{ $cat }}</option>
                            @endforeach
                        </select>
                    </div>

                    <!-- Target Audience -->
                    <div class="fg">
                        <label>Target Audience</label>
                        <select name="audience">
                            <option value="All">All Audiences</option>
                            @foreach(['Civil Society', 'Students', 'Youth', 'Transporters', 'Other'] as $aud)
                                <option value="{{ $aud }}" {{ ($filters['audience'] ?? '') === $aud ? 'selected' : '' }}>{{ $aud }}</option>
                            @endforeach
                        </select>
                    </div>

                    <!-- Age Group -->
                    <div class="fg">
                        <label>Age Demographics</label>
                        <select name="age_group">
                            <option value="All">All Age Groups</option>
                            @foreach(['Under 18', '18-25', '25-35', '35-45', '45-55', 'Above 55'] as $age)
                                <option value="{{ $age }}" {{ ($filters['age_group'] ?? '') === $age ? 'selected' : '' }}>{{ $age }}</option>
                            @endforeach
                        </select>
                    </div>

                    <!-- Attendance Range -->
                    <div class="fg">
                        <label>Attendance Headcount</label>
                        <select name="attendance_range">
                            <option value="All">All Headcounts</option>
                            @foreach(['20-40', '40-100', '100-150', '150-200', '200-500', '500 & above'] as $range)
                                <option value="{{ $range }}" {{ ($filters['attendance_range'] ?? '') === $range ? 'selected' : '' }}>{{ $range }}</option>
                            @endforeach
                        </select>
                    </div>

                    <!-- Sync Status -->
                    <div class="fg">
                        <label>Sync Status</label>
                        <select name="sync_status">
                            <option value="All">All Statuses</option>
                            @foreach(['Synced', 'Pending', 'Rejected/Failed'] as $status)
                                <option value="{{ $status }}" {{ ($filters['sync_status'] ?? '') === $status ? 'selected' : '' }}>{{ $status }}</option>
                            @endforeach
                        </select>
                    </div>

                    <!-- Search Input -->
                    <div class="fg">
                        <label>Venue Search</label>
                        <input type="text" name="venue_search" placeholder="Type venue keyword..." value="{{ $filters['venue_search'] ?? '' }}">
                    </div>

                </div>

                <!-- Action Toolbar -->
                <div class="filter-actions">
                    <a href="{{ route('admin.events.portal') }}" class="btn btn-secondary">
                        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                        Clear Filters
                    </a>
                    
                    <button type="button" onclick="exportCSV()" class="btn btn-accent">
                        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path></svg>
                        Export CSV Results
                    </button>

                    <button type="button" onclick="exportPDF()" class="btn" style="background-color: #dc2626; color: #ffffff; border: none; display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.6rem 1.2rem; border-radius: 6px; font-weight: 600; cursor: pointer; transition: background 0.2s;">
                        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                        Download PDF Report
                    </button>
                    
                    <button type="submit" class="btn btn-primary">
                        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                        Search & Apply
                    </button>
                </div>
            </form>
        </section>

        <!-- Live Statistics Counter -->
        <section class="metrics-grid">
            
            <div class="metric-card m-events">
                <div>
                    <div class="metric-label">Total Events</div>
                    <div class="metric-value">{{ number_format($totalEvents) }}</div>
                </div>
                <div class="metric-trend">
                    Campaign events executed
                </div>
            </div>

            <div class="metric-card m-participants">
                <div>
                    <div class="metric-label">Total Participants</div>
                    <div class="metric-value">{{ number_format($totalParticipants) }}</div>
                </div>
                <div class="metric-trend">
                    Individuals engaged in District
                </div>
            </div>

            <div class="metric-card m-venues">
                <div>
                    <div class="metric-label">Unique Venues</div>
                    <div class="metric-value">{{ number_format($uniqueVenues) }}</div>
                </div>
                <div class="metric-trend">
                    Distinct sites hosted
                </div>
            </div>

            <div class="metric-card m-blocks">
                <div>
                    <div class="metric-label">Blocks Covered</div>
                    <div class="metric-value">{{ number_format($blocksActive) }}</div>
                </div>
                <div class="metric-trend">
                    Active sub-districts covered
                </div>
            </div>

            <div class="metric-card m-days">
                <div>
                    <div class="metric-label">Active Days</div>
                    <div class="metric-value">{{ number_format($activeDays) }}</div>
                </div>
                <div class="metric-trend">
                    Unique dates with activity
                </div>
            </div>

        </section>

        <!-- Analytics Visualizations -->
        <section class="charts-container">
            
            <!-- Left Chart: Events by Block -->
            <div class="chart-card">
                <h3>
                    Events executed by Block
                    <span>Distribution</span>
                </h3>
                <div class="chart-wrapper">
                    <canvas id="eventsByBlockChart"></canvas>
                </div>
            </div>

            <!-- Right Chart: Events by Category -->
            <div class="chart-card">
                <h3>
                    Events by Activity Category
                    <span>Split Matrix</span>
                </h3>
                <div class="chart-wrapper">
                    <canvas id="categoryChart"></canvas>
                </div>
            </div>

            <!-- Bottom Horizontal Chart: Participants by Block -->
            <div class="chart-card chart-card-tall">
                <h3>
                    Total Registered Participants by Block
                    <span>Engagement Index</span>
                </h3>
                <div class="chart-wrapper" style="height: 350px;">
                    <canvas id="participantsByBlockChart"></canvas>
                </div>
            </div>

        </section>

        <!-- Detailed Events Directory Grid -->
        <section class="table-card">
            <div class="table-card-header">
                <h3>Detailed Campaign Events Directory</h3>
            </div>
            
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th style="width: 50px;">S.No</th>
                            <th>Event Details</th>
                            <th>Event Date</th>
                            <th>Venue & Jurisdiction</th>
                            <th>Activity Category</th>
                            <th>Engagement count</th>
                            <th>Target Demographics</th>
                            <th>Block Coordinator</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($events as $event)
                            <tr>
                                <td style="font-size: 0.8rem; font-weight: 700; color: var(--muted); text-align: center;">
                                    {{ ($events->currentPage() - 1) * $events->perPage() + $loop->iteration }}
                                </td>
                                <td>
                                    <div class="event-name">{{ $event->event_name }}</div>
                                    <div class="event-id">#{{ $event->id }}</div>
                                </td>
                                <td style="font-weight: 600; color: var(--primary-light);">
                                    {{ $event->event_date ? $event->event_date->format('d M Y') : 'N/A' }}
                                </td>
                                <td>
                                    <div class="event-venue">{{ $event->event_venue }}</div>
                                    <div class="event-block">
                                        Block: <strong>{{ $blocks[$event->block_id] ?? 'Unknown' }}</strong>
                                        @if($event->village)
                                            <span style="opacity: 0.7;">— {{ $event->village }}</span>
                                        @endif
                                    </div>
                                </td>
                                <td>
                                    <div class="badges">
                                        @if(is_array($event->event_category))
                                            @foreach($event->event_category as $cat)
                                                <span class="badge badge-cat">{{ $cat }}</span>
                                            @endforeach
                                        @else
                                            <span class="badge badge-cat">{{ $event->event_category }}</span>
                                        @endif
                                    </div>
                                </td>
                                <td>
                                    <div class="event-attendance">{{ number_format($event->actual_attendance) }}</div>
                                    <div class="event-range">Range: {{ $event->attendance_range }}</div>
                                </td>
                                <td>
                                    <div class="demo-group">
                                        <span class="demo-label">Audience:</span>
                                        @if(is_array($event->target_audience))
                                            @foreach($event->target_audience as $aud)
                                                <span class="badge badge-aud">{{ $aud }}</span>
                                            @endforeach
                                        @else
                                            <span class="badge badge-aud">{{ $event->target_audience }}</span>
                                        @endif
                                    </div>
                                    <div class="demo-group mt-1">
                                        <span class="demo-label">Age Groups:</span>
                                        @if(is_array($event->age_group))
                                            @foreach($event->age_group as $age)
                                                <span class="badge badge-age">{{ $age }}</span>
                                            @endforeach
                                        @else
                                            <span class="badge badge-age">{{ $event->age_group }}</span>
                                        @endif
                                    </div>
                                </td>
                                <td>
                                    <div class="coordinator-name">{{ $event->event_coordinator_name }}</div>
                                    <div class="coordinator-contact">
                                        <span style="opacity: 0.6;">Call:</span> {{ $event->event_coordinator_contact_number }}
                                        <div style="font-size: 0.68rem; font-weight: 500; opacity: 0.8; margin-top: 0.1rem;">{{ $event->event_coordinator_desig }}</div>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-center py-5 italic text-slate-400">
                                    No records found matching the active search parameters.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <!-- Paginated links navigation -->
            <div class="pagination-container">
                {{ $events->links() }}
            </div>
        </section>

    </main>

    <!-- Footer Panel -->
    <footer>
        <p>Department of Information & Public Relations, Government of Jammu & Kashmir</p>
        <p class="copyright">&copy; 2026 District Budgam. Nasha Mukt Bharat Abhiyaan Portal. All Rights Reserved.</p>
    </footer>

    <!-- Interactive script triggers and Chart init -->
    <script>
        // 1. CSV Download helper
        function exportCSV() {
            const form = document.getElementById('filterForm');
            const formData = new FormData(form);
            const params = new URLSearchParams();
            
            for (const [key, value] of formData.entries()) {
                if (value && value !== 'All' && value !== 'All Blocks' && value !== 'All Categories') {
                    params.append(key, value);
                }
            }
            
            window.location.href = "{{ route('admin.events.portal.export') }}?" + params.toString();
        }

        // 1b. PDF Download helper
        function exportPDF() {
            const form = document.getElementById('filterForm');
            const formData = new FormData(form);
            const params = new URLSearchParams();
            
            for (const [key, value] of formData.entries()) {
                if (value && value !== 'All' && value !== 'All Blocks' && value !== 'All Categories') {
                    params.append(key, value);
                }
            }
            
            window.open("{{ route('admin.events.pdf') }}?" + params.toString(), '_blank');
        }

        // 2. Chart initialization
        document.addEventListener("DOMContentLoaded", function() {
            
            // Events by Block
            const eventsByBlockRaw = {!! json_encode($eventsByBlock) !!};
            const eventsByBlockLabels = eventsByBlockRaw.map(item => item.name);
            const eventsByBlockData = eventsByBlockRaw.map(item => item.count);

            new Chart(document.getElementById('eventsByBlockChart').getContext('2d'), {
                type: 'bar',
                data: {
                    labels: eventsByBlockLabels,
                    datasets: [{
                        label: 'Events Count',
                        data: eventsByBlockData,
                        backgroundColor: '#1a3c6e',
                        borderRadius: 8,
                        borderWidth: 0,
                        barThickness: 24,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false }
                    },
                    scales: {
                        x: { grid: { display: false }, ticks: { font: { family: 'Inter', size: 11 } } },
                        y: { 
                            beginAtZero: true, 
                            ticks: { 
                                stepSize: 1, 
                                font: { family: 'Inter', size: 11 } 
                            },
                            grid: { color: '#f1f5f9' }
                        }
                    }
                }
            });

            // Events by Category
            const categoryRaw = {!! json_encode($categoryCounts) !!};
            const categoryLabels = Object.keys(categoryRaw);
            const categoryData = Object.values(categoryRaw);

            new Chart(document.getElementById('categoryChart').getContext('2d'), {
                type: 'doughnut',
                data: {
                    labels: categoryLabels,
                    datasets: [{
                        data: categoryData,
                        backgroundColor: ['#10b981', '#3b82f6', '#f59e0b', '#8b5cf6'],
                        hoverOffset: 4,
                        borderWidth: 2,
                        borderColor: '#ffffff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                boxWidth: 12,
                                padding: 16,
                                font: { family: 'Inter', size: 11, weight: '600' }
                            }
                        }
                    },
                    cutout: '70%'
                }
            });

            // Participants by Block Horizontal Bar
            const pByBlockRaw = {!! json_encode($participantsByBlock) !!};
            const pByBlockLabels = pByBlockRaw.map(item => item.name);
            const pByBlockData = pByBlockRaw.map(item => item.participants);

            new Chart(document.getElementById('participantsByBlockChart').getContext('2d'), {
                type: 'bar',
                data: {
                    labels: pByBlockLabels,
                    datasets: [{
                        label: 'Total Participants Count',
                        data: pByBlockData,
                        backgroundColor: '#e8a020',
                        borderRadius: 6,
                        borderWidth: 0,
                        barThickness: 16,
                    }]
                },
                options: {
                    indexAxis: 'y',
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false }
                    },
                    scales: {
                        x: { beginAtZero: true, grid: { color: '#f1f5f9' }, ticks: { font: { family: 'Inter', size: 11 } } },
                        y: { grid: { display: false }, ticks: { font: { family: 'Inter', size: 11 } } }
                    }
                }
            });
        });
    </script>
</body>
</html>
