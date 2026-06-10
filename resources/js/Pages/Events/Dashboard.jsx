import React, { useState, useEffect } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, usePage } from '@inertiajs/react';
import { PieChart, Pie, Cell, BarChart, Bar, XAxis, YAxis, Tooltip, ResponsiveContainer, AreaChart, Area, CartesianGrid } from 'recharts';

export default function Dashboard({ metrics, recentEvents, recentFailures, autoSyncPaused, portalCredentialsInvalid, statusData = [], eventsByBlock = [], eventsOverTime = [], telemetryData = [], portalConfig = {} }) {
    const { auth } = usePage().props;
    const isDistrictAdmin = auth.user.role === 'admin';

    const [filterDate, setFilterDate] = useState('');
    const [filterStatus, setFilterStatus] = useState('');
    const [settingsError, setSettingsError] = useState('');
    const [settingsSuccess, setSettingsSuccess] = useState('');

    const [healthState, setHealthState] = useState({
        status: 'probing',
        pending_count: 0,
        triggered_sync: false,
        auto_sync_paused: autoSyncPaused,
        portal_credentials_invalid: portalCredentialsInvalid,
    });

    const [telemetry, setTelemetry] = useState(telemetryData);
    const [activeTelemetryTab, setActiveTelemetryTab] = useState('performance');

    const [isSidebarOpen, setIsSidebarOpen] = useState(false);
    const [showPassword, setShowPassword] = useState(false);

    // SRE Uptime states
    const [uptimeRange, setUptimeRange] = useState('24h'); // '6h', '12h', '24h'
    const [hoveredBucket, setHoveredBucket] = useState(null);

    // Sync report states
    const [isReportModalOpen, setIsReportModalOpen] = useState(false);
    const [reportHours, setReportHours] = useState(12);
    const [reportData, setReportData] = useState(null);
    const [isReportLoading, setIsReportLoading] = useState(false);
    const [reportError, setReportError] = useState('');

    const fetchSyncReport = async (hoursToFetch = reportHours) => {
        setIsReportLoading(true);
        setReportError('');
        try {
            const response = await fetch(route('admin.sync-report') + `?hours=${hoursToFetch}`);
            if (!response.ok) throw new Error('Failed to load sync report.');
            const data = await response.json();
            setReportData(data);
        } catch (err) {
            console.error('Error fetching sync report:', err);
            setReportError(err.message || 'Error fetching report.');
        } finally {
            setIsReportLoading(false);
        }
    };

    useEffect(() => {
        if (isReportModalOpen) {
            fetchSyncReport(reportHours);
        }
    }, [isReportModalOpen, reportHours]);

    // SRE Uptime calculation
    const { overallUptime, buckets } = React.useMemo(() => {
        if (!telemetry || telemetry.length === 0) {
            return {
                overallUptime: 100,
                buckets: Array.from({ length: 48 }, (_, i) => ({
                    id: i,
                    status: 'no_data',
                    uptime: 100,
                    latency: 0,
                    startLabel: '--:--',
                    endLabel: '--:--',
                    pointCount: 0
                }))
            };
        }

        // Determine maximum timestamp as reference point
        const maxTimestamp = Math.max(...telemetry.map(t => t.timestamp));
        
        const durations = {
            '24h': 24 * 60 * 60,
            '12h': 12 * 60 * 60,
            '6h': 6 * 60 * 60,
        };

        const activeDuration = durations[uptimeRange] || durations['24h'];
        const startThreshold = maxTimestamp - activeDuration;

        // Filter telemetries in range
        const rangePoints = telemetry.filter(t => t.timestamp >= startThreshold);

        // Overall Uptime math
        const totalPoints = rangePoints.length;
        const onlinePoints = rangePoints.filter(t => t.is_online).length;
        const overallUptime = totalPoints > 0 ? (onlinePoints / totalPoints) * 100 : 100;

        // Group into 48 buckets
        const bucketSizeSec = activeDuration / 48;
        const buckets = [];

        for (let i = 0; i < 48; i++) {
            const bucketStart = startThreshold + (i * bucketSizeSec);
            const bucketEnd = bucketStart + bucketSizeSec;

            // Find all points in this bucket
            const pointsInBucket = rangePoints.filter(t => t.timestamp >= bucketStart && t.timestamp < bucketEnd);

            const formatTime = (ts) => {
                const date = new Date(ts * 1000);
                return date.toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit', hour12: false });
            };

            const startLabel = formatTime(bucketStart);
            const endLabel = formatTime(bucketEnd);

            if (pointsInBucket.length === 0) {
                buckets.push({
                    id: i,
                    status: 'no_data',
                    uptime: 0,
                    latency: 0,
                    startLabel,
                    endLabel,
                    pointCount: 0
                });
            } else {
                const onlineCount = pointsInBucket.filter(t => t.is_online).length;
                const ratio = onlineCount / pointsInBucket.length;
                const avgLatency = Math.round(pointsInBucket.reduce((sum, p) => sum + p.latency, 0) / pointsInBucket.length);

                let status = 'online';
                if (ratio === 0) {
                    status = 'offline';
                } else if (ratio < 1) {
                    status = 'degraded';
                }

                buckets.push({
                    id: i,
                    status,
                    uptime: Math.round(ratio * 100),
                    latency: avgLatency,
                    startLabel,
                    endLabel,
                    pointCount: pointsInBucket.length
                });
            }
        }

        return {
            overallUptime,
            buckets
        };
    }, [telemetry, uptimeRange]);

    useEffect(() => {
        const checkPortalHealth = async () => {
            try {
                const response = await fetch(route('events.check-portal'));
                if (!response.ok) throw new Error('Network error');
                const data = await response.json();
                setHealthState(data);
                if (data.telemetry) {
                    setTelemetry(data.telemetry);
                }
            } catch (error) {
                console.error('Portal health check failed:', error);
                setHealthState({ status: 'offline', auto_sync_paused: false, portal_credentials_invalid: false });
            }
        };

        checkPortalHealth();
        const interval = setInterval(checkPortalHealth, 15000);
        return () => clearInterval(interval);
    }, []);

    useEffect(() => {
        setHealthState(prev => ({
            ...prev,
            auto_sync_paused: autoSyncPaused,
            portal_credentials_invalid: portalCredentialsInvalid,
        }));
    }, [autoSyncPaused, portalCredentialsInvalid]);

    const { data, setData, post, processing, errors, reset } = useForm({
        portal_url: portalConfig.portal_url || '',
        admin_id: portalConfig.admin_id || '',
        admin_password: portalConfig.admin_password || '',
    });

    useEffect(() => {
        if (portalConfig.portal_url || portalConfig.admin_id || portalConfig.admin_password) {
            setData({
                portal_url: portalConfig.portal_url || '',
                admin_id: portalConfig.admin_id || '',
                admin_password: portalConfig.admin_password || '',
            });
        }
    }, [portalConfig.portal_url, portalConfig.admin_id, portalConfig.admin_password]);

    const submitEnv = (e) => {
        e.preventDefault();
        setSettingsError('');
        setSettingsSuccess('');
        post(route('settings.env'), {
            onSuccess: () => {
                setSettingsSuccess('Credentials updated successfully.');
            },
            onError: (errs) => {
                if (errs.admin_password) setSettingsError(errs.admin_password);
                else if (errs.admin_id) setSettingsError(errs.admin_id);
                else setSettingsError('Failed to update credentials.');
            }
        });
    };

    const toggleSync = (eventId) => post(route('events.toggleSync', eventId));
    const retrySync = (eventId) => post(route('events.retrySync', eventId));
    const forceSyncQueue = () => post(route('events.force-sync'));
    const runQueueWorker = () => post(route('events.run-queue-worker'));
    const clearQueue = () => post(route('events.clear-queue'));
    const resetFailedSyncs = () => post(route('events.reset-failed'));
    const toggleAutoSync = () => post(route('events.toggleAutoSync'));

    const filteredEvents = recentEvents.filter(event => {
        if (filterDate && !event.event_date.startsWith(filterDate)) return false;
        if (filterStatus && event.sync_status !== filterStatus) return false;
        return true;
    });

    return (
        <AuthenticatedLayout
            header={
                <div className="flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
                    <div>
                        <h2 className="text-2xl font-extrabold text-slate-800 tracking-tight" style={{ fontFamily: "'Outfit', sans-serif" }}>
                            {isDistrictAdmin ? 'District Admin Overview' : 'Event Creator Portal'}
                        </h2>
                        <p className="text-sm font-medium text-slate-500 mt-1" style={{ fontFamily: "'Inter', sans-serif" }}>Manage and synchronize your Nasha Mukt Abhiyaan events.</p>
                    </div>
                    <div className="flex items-center gap-2">
                        <a
                            href={route('events.create')}
                            className="px-4 py-2 bg-emerald-600 hover:bg-emerald-500 text-white font-bold rounded-xl shadow-md shadow-emerald-500/10 hover:shadow-emerald-500/25 transition-all text-sm flex items-center gap-2 border border-emerald-600/30 hover:scale-[1.02] active:scale-[0.98] duration-150"
                            style={{ fontFamily: "'Outfit', sans-serif" }}
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" className="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fillRule="evenodd" d="M10 3a1 1 0 011 1v5h5a1 1 0 110 2h-5v5a1 1 0 11-2 0v-5H4a1 1 0 110-2h5V4a1 1 0 011-1z" clipRule="evenodd" /></svg>
                            Create Event
                        </a>
                        {isDistrictAdmin && (
                            <button
                                onClick={() => setIsSidebarOpen(!isSidebarOpen)}
                                className={`px-4 py-2 font-bold rounded-xl transition-all text-sm flex items-center gap-2 shadow-sm border ${
                                    isSidebarOpen
                                        ? 'bg-slate-900 text-white border-slate-900'
                                        : 'bg-white text-slate-700 border-slate-200 hover:bg-slate-50 hover:border-slate-300'
                                }`}
                                style={{ fontFamily: "'Outfit', sans-serif" }}
                                title="Open admin actions & settings"
                            >
                                <svg xmlns="http://www.w3.org/2000/svg" className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                </svg>
                                Sync Console
                            </button>
                        )}
                    </div>
                </div>
            }
        >
            <Head title="Dashboard | Nasha Mukt J&K" />

            <div className="flex relative bg-slate-50 min-h-screen">
                <div className={`py-8 flex-1 transition-all duration-300 ${isSidebarOpen ? 'lg:mr-80' : ''}`}>
                    <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 space-y-8">

                        {/* Elegant Dark Welcome Hero Banner */}
                        <div className="relative overflow-hidden rounded-3xl bg-gradient-to-r from-slate-950 via-slate-900 to-indigo-950 p-8 text-white shadow-xl border border-slate-800">
                            <div className="absolute top-0 right-0 w-96 h-96 bg-emerald-500/10 rounded-full blur-3xl pointer-events-none -mr-20 -mt-20"></div>
                            <div className="absolute bottom-0 left-0 w-96 h-96 bg-indigo-500/10 rounded-full blur-3xl pointer-events-none -ml-20 -mb-20"></div>
                            
                            <div className="relative z-10 flex flex-col md:flex-row justify-between items-start md:items-center gap-6">
                                <div className="space-y-2.5 max-w-2xl">
                                    <div className="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 text-xs font-bold uppercase tracking-wider">
                                        <span className="relative flex h-2 w-2">
                                            <span className="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                                            <span className="relative inline-flex rounded-full h-2 w-2 bg-emerald-500"></span>
                                        </span>
                                        Active Control Console
                                    </div>
                                    <h1 className="text-3xl md:text-4xl font-extrabold tracking-tight text-white" style={{ fontFamily: "'Outfit', sans-serif" }}>
                                        Nasha Mukt Bharat Abhiyaan
                                    </h1>
                                    <p className="text-slate-400 font-medium leading-relaxed text-sm md:text-base" style={{ fontFamily: "'Inter', sans-serif" }}>
                                        Welcome back, <span className="text-slate-100 font-bold">{auth.user.name}</span>. Oversee the synchronization, diagnostic health logs, and real-time observability of District Budgam's drug-free campaign events.
                                    </p>
                                </div>
                                <div className="flex flex-row md:flex-col gap-3.5 w-full md:w-auto shrink-0 border-t border-slate-800/80 md:border-t-0 pt-4 md:pt-0">
                                    <div className="flex-1 bg-slate-900/60 backdrop-blur border border-slate-800 rounded-2xl p-4 min-w-[140px] text-center">
                                        <span className="text-[10px] font-black uppercase tracking-widest text-slate-500">Auto Sync Mode</span>
                                        <div className="mt-1.5 flex items-center justify-center gap-1.5">
                                            <span className={`h-2.5 w-2.5 rounded-full ${healthState.auto_sync_paused ? 'bg-amber-500' : 'bg-emerald-500 animate-pulse'}`}></span>
                                            <span className="font-extrabold text-sm">{healthState.auto_sync_paused ? 'PAUSED' : 'ACTIVE'}</span>
                                        </div>
                                    </div>
                                    <div className="flex-1 bg-slate-900/60 backdrop-blur border border-slate-800 rounded-2xl p-4 min-w-[140px] text-center">
                                        <span className="text-[10px] font-black uppercase tracking-widest text-slate-500">Portal Target</span>
                                        <div className="mt-1.5 font-mono font-bold text-slate-200 text-xs truncate max-w-[140px] mx-auto">
                                            {portalConfig.portal_url ? new URL(portalConfig.portal_url).hostname : 'nashamuktjk.org'}
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        {/* Invalid Credentials Premium Alert */}
                        {isDistrictAdmin && healthState.portal_credentials_invalid && (
                            <div className="bg-gradient-to-r from-rose-50/90 to-amber-50/90 border border-rose-200 rounded-3xl p-6 shadow-md shadow-rose-100/50 flex flex-col md:flex-row justify-between items-start md:items-center gap-6 animate-pulse duration-[3000ms]">
                                <div className="flex gap-4 items-start">
                                    <div className="p-3.5 bg-rose-500 text-white rounded-2xl shadow-lg shadow-rose-500/20 shrink-0">
                                        <svg xmlns="http://www.w3.org/2000/svg" className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                                        </svg>
                                    </div>
                                    <div className="space-y-1">
                                        <h4 className="text-rose-950 font-extrabold text-lg tracking-tight" style={{ fontFamily: "'Outfit', sans-serif" }}>Sync Paused: Invalid Portal Credentials Detected!</h4>
                                        <p className="text-slate-600 text-sm font-medium leading-relaxed" style={{ fontFamily: "'Inter', sans-serif" }}>
                                            Automatic synchronization has been globally paused to prevent the target portal from blacklisting your IP address. Please update your API ID or password in the configuration panel to resume synchronization.
                                        </p>
                                    </div>
                                </div>
                                <button
                                    onClick={() => setIsSidebarOpen(true)}
                                    className="px-5 py-3 bg-rose-600 hover:bg-rose-500 text-white text-sm font-black rounded-xl shadow-lg shadow-rose-650/20 transition-all flex items-center gap-2 shrink-0 hover:scale-[1.03] duration-200"
                                    style={{ fontFamily: "'Outfit', sans-serif" }}
                                >
                                    <svg xmlns="http://www.w3.org/2000/svg" className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                    </svg>
                                    Update Credentials
                                </button>
                            </div>
                        )}

                        {/* SRE Portal Health & Uptime Observatory Dashboard */}
                        {isDistrictAdmin && (
                            <div className="bg-slate-950 rounded-3xl border border-slate-900 shadow-2xl p-6 space-y-6 text-white relative overflow-hidden">
                                <div className="absolute top-0 right-0 w-96 h-96 bg-emerald-500/5 rounded-full blur-3xl pointer-events-none -mr-20 -mt-20"></div>
                                <div className="absolute bottom-0 left-0 w-96 h-96 bg-indigo-500/5 rounded-full blur-3xl pointer-events-none -ml-20 -mb-20"></div>

                                {/* Header Block */}
                                <div className="relative z-10 flex flex-col md:flex-row justify-between items-start md:items-center gap-5 border-b border-slate-800 pb-5">
                                    <div className="flex items-center gap-3.5">
                                        {/* Pulsing state circle */}
                                        <div className="relative flex h-5 w-5 shrink-0">
                                            <span className={`animate-ping absolute inline-flex h-full w-full rounded-full opacity-75 
                                                ${healthState.status === 'online' ? (healthState.auto_sync_paused ? 'bg-amber-400' : 'bg-emerald-400') : healthState.status === 'offline' ? 'bg-rose-400' : 'bg-slate-400'}`}></span>
                                            <span className={`relative inline-flex rounded-full h-5 w-5 
                                                ${healthState.status === 'online' ? (healthState.auto_sync_paused ? 'bg-amber-500' : 'bg-emerald-500') : healthState.status === 'offline' ? 'bg-rose-500' : 'bg-slate-500'}`}></span>
                                        </div>
                                        <div>
                                            <div className="flex items-center gap-2">
                                                <span className="text-[10px] font-black uppercase tracking-widest text-slate-500">Sync Target Portal</span>
                                                <span className="font-mono text-[10px] font-black px-2 py-0.5 rounded bg-slate-900 border border-slate-800 text-slate-400">
                                                    {portalConfig.portal_url ? new URL(portalConfig.portal_url).hostname : 'nashamuktjk.org'}
                                                </span>
                                            </div>
                                            <h4 className="text-lg font-black tracking-tight mt-0.5 text-slate-100 font-outfit" style={{ fontFamily: "'Outfit', sans-serif" }}>
                                                {healthState.status === 'probing' && 'Initializing active health probing...'}
                                                {healthState.status === 'offline' && <span className="text-rose-400">Offline (Timeout or 522 Cloudflare Error)</span>}
                                                {healthState.status === 'degraded' && (
                                                    <span className="text-amber-400 font-bold">
                                                        ⚠ Degraded — Login OK, submissions failing (522/524) · {healthState.pending_count} events queued
                                                    </span>
                                                )}
                                                {healthState.status === 'online' && (
                                                    healthState.auto_sync_paused 
                                                        ? <span className="text-amber-400 font-bold">Online — Auto-Sync Paused ({healthState.pending_count} pending)</span>
                                                        : <span className="text-emerald-400 font-bold">Online — Operational {healthState.triggered_sync && `(Syncing ${healthState.pending_count} events)`}</span>
                                                )}
                                            </h4>
                                        </div>
                                    </div>

                                    {/* Right Side: Uptime Percentage & Filter buttons */}
                                    <div className="flex flex-col sm:flex-row items-start sm:items-center gap-5 w-full md:w-auto self-stretch md:self-auto justify-between md:justify-end">
                                        <div className="flex flex-col items-start sm:items-end">
                                            <span className="text-[10px] font-black uppercase tracking-widest text-slate-500">Target Portal Uptime</span>
                                            <div className="flex items-baseline gap-1.5 mt-0.5">
                                                <span className="text-2xl font-black text-slate-100 font-mono tracking-tight">{overallUptime.toFixed(2)}%</span>
                                                <span className="text-[10px] font-extrabold text-emerald-400 bg-emerald-500/10 px-2 py-0.5 rounded-md border border-emerald-500/20 uppercase tracking-wide">24h Base</span>
                                            </div>
                                        </div>

                                        <div className="flex bg-slate-900 p-1.5 rounded-xl border border-slate-800">
                                            {[
                                                { id: '6h', label: '6h' },
                                                { id: '12h', label: '12h' },
                                                { id: '24h', label: '24h' }
                                            ].map(range => (
                                                <button
                                                    key={range.id}
                                                    type="button"
                                                    onClick={() => setUptimeRange(range.id)}
                                                    className={`px-3 py-1 rounded-lg text-xs font-black transition-all ${uptimeRange === range.id ? 'bg-slate-800 text-white shadow-sm' : 'text-slate-400 hover:text-white'}`}
                                                    style={{ fontFamily: "'Outfit', sans-serif" }}
                                                >
                                                    {range.label}
                                                </button>
                                            ))}
                                        </div>
                                    </div>
                                </div>

                                {/* Uptime Timeline Grid (48 rounded pills) */}
                                <div className="space-y-3 relative z-10">
                                    {/* Interactive Timeline Relative Container */}
                                    <div className="relative">
                                        <div 
                                            className="h-10 select-none"
                                            style={{ 
                                                display: 'grid', 
                                                gridTemplateColumns: 'repeat(48, minmax(0, 1fr))',
                                                gap: '4px'
                                            }}
                                        >
                                            {buckets.map((bucket) => {
                                                let bgClass = 'bg-slate-800 hover:bg-slate-700';
                                                if (bucket.status === 'online') bgClass = 'bg-emerald-500 hover:bg-emerald-400 shadow-sm shadow-emerald-500/10';
                                                else if (bucket.status === 'offline') bgClass = 'bg-rose-500 hover:bg-rose-400 shadow-sm shadow-rose-500/10';
                                                else if (bucket.status === 'degraded') bgClass = 'bg-amber-500 hover:bg-amber-400 shadow-sm shadow-amber-500/10';

                                                return (
                                                    <div
                                                        key={bucket.id}
                                                        className={`rounded cursor-pointer transition-all hover:scale-y-115 duration-150 relative h-full ${bgClass}`}
                                                        onMouseEnter={(e) => {
                                                            const rect = e.currentTarget.getBoundingClientRect();
                                                            const parentRect = e.currentTarget.parentElement.getBoundingClientRect();
                                                            setHoveredBucket({
                                                                ...bucket,
                                                                left: rect.left - parentRect.left + (rect.width / 2),
                                                            });
                                                        }}
                                                        onMouseLeave={() => setHoveredBucket(null)}
                                                    />
                                                );
                                            })}
                                        </div>

                                        {/* Smooth Glassmorphic Tooltip */}
                                        {hoveredBucket && (
                                            <div
                                                className="absolute bottom-full mb-3.5 z-30 -translate-x-1/2 pointer-events-none transition-all duration-200 ease-out animate-in fade-in slide-in-from-bottom-2"
                                                style={{ left: `${hoveredBucket.left}px` }}
                                            >
                                                <div className="bg-slate-950/95 backdrop-blur-md border border-slate-800 rounded-2xl shadow-2xl p-3.5 text-white w-52 space-y-2.5">
                                                    <div className="flex justify-between items-center text-[10px] text-slate-500 font-extrabold tracking-wider border-b border-slate-800 pb-1.5 uppercase font-mono">
                                                        <span>{hoveredBucket.startLabel} - {hoveredBucket.endLabel}</span>
                                                        <span>B#{hoveredBucket.id + 1}</span>
                                                    </div>
                                                    <div className="space-y-1.5 text-xs" style={{ fontFamily: "'Inter', sans-serif" }}>
                                                        <div className="flex justify-between items-center">
                                                            <span className="text-slate-400 font-semibold">Status:</span>
                                                            <span className={`font-black uppercase tracking-wider text-[10px] px-2 py-0.5 rounded ${
                                                                hoveredBucket.status === 'online' ? 'bg-emerald-500/20 text-emerald-400' :
                                                                hoveredBucket.status === 'offline' ? 'bg-rose-500/20 text-rose-400' :
                                                                hoveredBucket.status === 'degraded' ? 'bg-amber-500/20 text-amber-400' :
                                                                'bg-slate-500/20 text-slate-400'
                                                            }`}>
                                                                {hoveredBucket.status === 'no_data' ? 'No Data' : hoveredBucket.status}
                                                            </span>
                                                        </div>
                                                        <div className="flex justify-between items-center">
                                                            <span className="text-slate-400 font-semibold">Uptime Ratio:</span>
                                                            <span className="font-bold font-mono text-slate-200">{hoveredBucket.uptime}%</span>
                                                        </div>
                                                        <div className="flex justify-between items-center">
                                                            <span className="text-slate-400 font-semibold">Avg Latency:</span>
                                                            <span className="font-bold font-mono text-emerald-400">{hoveredBucket.status === 'no_data' ? 'N/A' : `${hoveredBucket.latency} ms`}</span>
                                                        </div>
                                                        <div className="flex justify-between items-center">
                                                            <span className="text-slate-400 font-semibold">Samples:</span>
                                                            <span className="font-bold font-mono text-slate-300">{hoveredBucket.pointCount}</span>
                                                        </div>
                                                    </div>
                                                    {/* Tiny tooltip arrow */}
                                                    <div className="absolute top-full left-1/2 -translate-x-1/2 -mt-[1px] border-4 border-transparent border-t-slate-950" />
                                                </div>
                                            </div>
                                        )}
                                    </div>

                                    {/* Timeline Legend */}
                                    <div className="flex flex-wrap justify-between items-center gap-2 pt-1.5 text-[10px] font-bold text-slate-500">
                                        <div className="flex items-center gap-3">
                                            <span className="flex items-center gap-1.5"><span className="w-2.5 h-2.5 rounded-full bg-emerald-500"></span> 100% Operational</span>
                                            <span className="flex items-center gap-1.5"><span className="w-2.5 h-2.5 rounded-full bg-amber-500"></span> Degraded Spot</span>
                                            <span className="flex items-center gap-1.5"><span className="w-2.5 h-2.5 rounded-full bg-rose-500"></span> Full Outage</span>
                                            <span className="flex items-center gap-1.5"><span className="w-2.5 h-2.5 rounded-full bg-slate-800"></span> No Probe Data</span>
                                        </div>
                                        <div className="font-mono text-slate-600">
                                            Range: {uptimeRange === '24h' ? 'Last 24 Hours' : uptimeRange === '12h' ? 'Last 12 Hours' : 'Last 6 Hours'} to Now
                                        </div>
                                    </div>
                                </div>
                            </div>
                        )}

                        {/* Quick Diagnostics & Links */}
                        {isDistrictAdmin && (
                            <div className="bg-white p-6 rounded-3xl shadow-sm border border-slate-200 space-y-4">
                                <h3 className="text-slate-800 text-lg font-bold flex items-center gap-2" style={{ fontFamily: "'Outfit', sans-serif" }}>
                                    <svg xmlns="http://www.w3.org/2000/svg" className="h-5 w-5 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                                    </svg>
                                    System Diagnostics & Traffic Checks
                                </h3>
                                <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-6 gap-4">
                                    <a
                                        href="https://nashamuktjk.org/enterprise/login"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="flex items-center gap-3.5 p-4 rounded-2xl bg-slate-50 border border-slate-200 hover:border-emerald-500 hover:bg-emerald-50/20 hover:shadow-sm hover:-translate-y-0.5 transition-all duration-205 group"
                                    >
                                        <div className="p-2.5 rounded-xl bg-emerald-100 text-emerald-700 group-hover:bg-emerald-200 transition-colors">
                                            <svg xmlns="http://www.w3.org/2000/svg" className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                                            </svg>
                                        </div>
                                        <div>
                                            <h4 className="text-sm font-bold text-slate-800" style={{ fontFamily: "'Outfit', sans-serif" }}>Target Portal</h4>
                                            <p className="text-xs text-slate-500" style={{ fontFamily: "'Inter', sans-serif" }}>Official JK Login</p>
                                        </div>
                                    </a>

                                    <a
                                        href={route('admin.logs.sync')}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="flex items-center gap-3.5 p-4 rounded-2xl bg-slate-50 border border-slate-200 hover:border-emerald-500 hover:bg-emerald-50/20 hover:shadow-sm hover:-translate-y-0.5 transition-all duration-205 group"
                                    >
                                        <div className="p-2.5 rounded-xl bg-indigo-100 text-indigo-700 group-hover:bg-indigo-200 transition-colors">
                                            <svg xmlns="http://www.w3.org/2000/svg" className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                            </svg>
                                        </div>
                                        <div>
                                            <h4 className="text-sm font-bold text-slate-800" style={{ fontFamily: "'Outfit', sans-serif" }}>Queue Logs</h4>
                                            <p className="text-xs text-slate-500" style={{ fontFamily: "'Inter', sans-serif" }}>Real-time traffic</p>
                                        </div>
                                    </a>

                                    <a
                                        href={route('admin.logs.audit')}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="flex items-center gap-3.5 p-4 rounded-2xl bg-slate-50 border border-slate-200 hover:border-emerald-500 hover:bg-emerald-50/20 hover:shadow-sm hover:-translate-y-0.5 transition-all duration-205 group"
                                    >
                                        <div className="p-2.5 rounded-xl bg-purple-100 text-purple-700 group-hover:bg-purple-200 transition-colors">
                                            <svg xmlns="http://www.w3.org/2000/svg" className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                                            </svg>
                                        </div>
                                        <div>
                                            <h4 className="text-sm font-bold text-slate-800" style={{ fontFamily: "'Outfit', sans-serif" }}>Audit Reports</h4>
                                            <p className="text-xs text-slate-500" style={{ fontFamily: "'Inter', sans-serif" }}>Database hash checks</p>
                                        </div>
                                    </a>

                                    <a
                                        href={route('events.check-portal')}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="flex items-center gap-3.5 p-4 rounded-2xl bg-slate-50 border border-slate-200 hover:border-emerald-500 hover:bg-emerald-50/20 hover:shadow-sm hover:-translate-y-0.5 transition-all duration-205 group"
                                    >
                                        <div className="p-2.5 rounded-xl bg-amber-100 text-amber-700 group-hover:bg-amber-200 transition-colors">
                                            <svg xmlns="http://www.w3.org/2000/svg" className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                                            </svg>
                                        </div>
                                        <div>
                                            <h4 className="text-sm font-bold text-slate-800" style={{ fontFamily: "'Outfit', sans-serif" }}>API Health Probe</h4>
                                            <p className="text-xs text-slate-500" style={{ fontFamily: "'Inter', sans-serif" }}>Live status JSON</p>
                                        </div>
                                    </a>

                                    <a
                                        href="#server-telemetry"
                                        className="flex items-center gap-3.5 p-4 rounded-2xl bg-slate-50 border border-slate-200 hover:border-emerald-500 hover:bg-emerald-50/20 hover:shadow-sm hover:-translate-y-0.5 transition-all duration-205 group"
                                    >
                                        <div className="p-2.5 rounded-xl bg-slate-200 text-slate-700 group-hover:bg-slate-350 transition-colors">
                                            <svg xmlns="http://www.w3.org/2000/svg" className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                                            </svg>
                                        </div>
                                        <div>
                                            <h4 className="text-sm font-bold text-slate-800" style={{ fontFamily: "'Outfit', sans-serif" }}>Telemetry Graph</h4>
                                            <p className="text-xs text-slate-500" style={{ fontFamily: "'Inter', sans-serif" }}>Live performance grids</p>
                                        </div>
                                    </a>

                                    <button
                                        onClick={() => setIsReportModalOpen(true)}
                                        className="flex items-center gap-3.5 p-4 rounded-2xl bg-slate-50 border border-slate-200 hover:border-emerald-500 hover:bg-emerald-50/20 hover:shadow-sm hover:-translate-y-0.5 transition-all duration-205 text-left group"
                                    >
                                        <div className="p-2.5 rounded-xl bg-teal-100 text-teal-700 group-hover:bg-teal-200 transition-colors">
                                            <svg xmlns="http://www.w3.org/2000/svg" className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                            </svg>
                                        </div>
                                        <div>
                                            <h4 className="text-sm font-bold text-slate-800" style={{ fontFamily: "'Outfit', sans-serif" }}>Sync Report</h4>
                                            <p className="text-xs text-slate-500" style={{ fontFamily: "'Inter', sans-serif" }}>Date-wise stats</p>
                                        </div>
                                    </button>
                                </div>
                            </div>
                        )}

                        {/* Left-Striped Metrics Section */}
                        {isDistrictAdmin && (
                            <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-6">
                                {[
                                    { 
                                        label: 'Total Events', 
                                        value: metrics.total, 
                                        color: 'text-slate-900', 
                                        border: 'border-l-indigo-650', 
                                        icon: (
                                            <svg className="h-6 w-6 text-indigo-650" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2.5" d="M19 20H5a2 2 0 01-2-2V6a2 2 0 012-2h10a2 2 0 012 2v1m2 13a2 2 0 01-2-2V7m2 13a2 2 0 002-2V9a2 2 0 00-2-2h-2m-4-3H9M7 16h6M7 8h6m-6 4h3" />
                                            </svg>
                                        ),
                                        bg: 'bg-indigo-50/50' 
                                    },
                                    { 
                                        label: 'Successfully Synced', 
                                        value: metrics.synced, 
                                        color: 'text-emerald-600', 
                                        border: 'border-l-emerald-500', 
                                        icon: (
                                            <svg className="h-6 w-6 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2.5" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                                            </svg>
                                        ),
                                        bg: 'bg-emerald-50/50'
                                    },
                                    { 
                                        label: 'Pending / Syncing', 
                                        value: metrics.pending + metrics.syncing, 
                                        color: 'text-amber-600', 
                                        border: 'border-l-amber-500', 
                                        icon: (
                                            <svg className="h-6 w-6 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2.5" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                            </svg>
                                        ),
                                        bg: 'bg-amber-50/50'
                                    },
                                    { 
                                        label: 'Permanently Failed', 
                                        value: metrics.failed_perm, 
                                        color: 'text-rose-600', 
                                        border: 'border-l-rose-500', 
                                        icon: (
                                            <svg className="h-6 w-6 text-rose-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2.5" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                                            </svg>
                                        ),
                                        bg: 'bg-rose-50/50'
                                    }
                                ].map((stat, i) => (
                                    <div key={i} className={`bg-white p-6 rounded-3xl shadow-sm border border-slate-200 border-l-4 ${stat.border} hover:shadow-md hover:-translate-y-1 transition-all duration-200 flex justify-between items-center`}>
                                        <div className="space-y-1">
                                            <h3 className="text-slate-500 text-xs font-black uppercase tracking-wider" style={{ fontFamily: "'Outfit', sans-serif" }}>{stat.label}</h3>
                                            <p className={`text-3xl font-black ${stat.color} font-mono`}>{stat.value}</p>
                                        </div>
                                        <div className={`p-3 rounded-2xl ${stat.bg}`}>
                                            {stat.icon}
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}

                        {/* Live Server Telemetry Section */}
                        {isDistrictAdmin && (
                            <div id="server-telemetry" className="bg-white p-6 rounded-3xl shadow-sm border border-slate-200 space-y-6 scroll-mt-6">
                                <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 border-b border-slate-100 pb-4">
                                    <div>
                                        <h3 className="text-slate-800 text-lg font-extrabold tracking-tight flex items-center gap-2" style={{ fontFamily: "'Outfit', sans-serif" }}>
                                            <span className="relative flex h-3.5 w-3.5">
                                                <span className="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-500 opacity-75"></span>
                                                <span className="relative inline-flex rounded-full h-3.5 w-3.5 bg-emerald-500"></span>
                                            </span>
                                            Live Server Telemetry & Health Indexes
                                        </h3>
                                        <p className="text-xs text-slate-500 font-medium mt-0.5" style={{ fontFamily: "'Inter', sans-serif" }}>Real-time status indexes tracked from active server traffic.</p>
                                    </div>
                                    <div className="flex bg-slate-100 p-1.5 rounded-xl gap-1">
                                        {[
                                            { id: 'performance', label: 'Performance' },
                                            { id: 'resources', label: 'Resources' },
                                            { id: 'queue', label: 'Uptime & Sync Queue' }
                                        ].map(tab => (
                                            <button
                                                key={tab.id}
                                                onClick={() => setActiveTelemetryTab(tab.id)}
                                                className={`px-3 py-1.5 rounded-lg text-xs font-black transition-all ${activeTelemetryTab === tab.id ? 'bg-white text-slate-800 shadow-sm border-slate-200' : 'text-slate-500 hover:text-slate-800'}`}
                                                style={{ fontFamily: "'Outfit', sans-serif" }}
                                            >
                                                {tab.label}
                                            </button>
                                        ))}
                                    </div>
                                </div>

                                {/* Live Value Badges */}
                                <div className="grid grid-cols-2 sm:grid-cols-5 gap-4">
                                    {[
                                        { label: 'CPU Load', value: `${(telemetry[telemetry.length - 1]?.cpu ?? 0.0).toFixed(2)}`, desc: 'Average index', color: 'text-indigo-650', bg: 'bg-indigo-50/50' },
                                        { label: 'Memory Usage', value: `${(telemetry[telemetry.length - 1]?.memory ?? 0).toFixed(0)} MB`, desc: 'Allocated heap', color: 'text-sky-650', bg: 'bg-sky-50/50' },
                                        { label: 'Disk Space', value: `${(telemetry[telemetry.length - 1]?.disk ?? 0).toFixed(1)}%`, desc: 'Used capacity', color: 'text-amber-600', bg: 'bg-amber-50/50' },
                                        { label: 'Portal Status', value: telemetry[telemetry.length - 1]?.is_online ? 'ONLINE' : 'OFFLINE', desc: 'nashamuktjk.org', color: telemetry[telemetry.length - 1]?.is_online ? 'text-emerald-600' : 'text-rose-650 font-extrabold', bg: telemetry[telemetry.length - 1]?.is_online ? 'bg-emerald-50/50' : 'bg-rose-50/50 animate-pulse' },
                                        { label: 'Pending Jobs', value: `${(telemetry[telemetry.length - 1]?.pending ?? 0)}`, desc: 'In queue', color: 'text-rose-650', bg: 'bg-rose-50/50' }
                                    ].map((badge, i) => (
                                        <div key={i} className={`p-4 rounded-2xl border border-slate-100 ${badge.bg} transition-all`}>
                                            <span className="text-[10px] font-extrabold uppercase tracking-widest text-slate-400" style={{ fontFamily: "'Outfit', sans-serif" }}>{badge.label}</span>
                                            <h4 className={`text-lg font-black ${badge.color} mt-1 font-mono`}>{badge.value}</h4>
                                            <p className="text-[10px] font-medium text-slate-500 mt-0.5" style={{ fontFamily: "'Inter', sans-serif" }}>{badge.desc}</p>
                                        </div>
                                    ))}
                                </div>

                                {/* Dynamic Recharts Area Chart */}
                                <div className="h-72 border border-slate-100 rounded-2xl p-4 bg-slate-50/50">
                                    {telemetry.length > 0 ? (
                                        <ResponsiveContainer width="100%" height="100%" minWidth={0}>
                                            <AreaChart 
                                                data={telemetry.map(t => ({
                                                    ...t,
                                                    portal_status_num: t.is_online ? 1 : 0
                                                }))} 
                                                margin={{ top: 10, right: 15, left: -20, bottom: 0 }}
                                            >
                                                <defs>
                                                    <linearGradient id="telemetryPerfGrad" x1="0" y1="0" x2="0" y2="1">
                                                        <stop offset="5%" stopColor="#4f46e5" stopOpacity={0.4}/>
                                                        <stop offset="95%" stopColor="#4f46e5" stopOpacity={0}/>
                                                    </linearGradient>
                                                    <linearGradient id="telemetryResGrad" x1="0" y1="0" x2="0" y2="1">
                                                        <stop offset="5%" stopColor="#0284c7" stopOpacity={0.4}/>
                                                        <stop offset="95%" stopColor="#0284c7" stopOpacity={0}/>
                                                    </linearGradient>
                                                    <linearGradient id="telemetryQueueGrad" x1="0" y1="0" x2="0" y2="1">
                                                        <stop offset="5%" stopColor="#e11d48" stopOpacity={0.4}/>
                                                        <stop offset="95%" stopColor="#e11d48" stopOpacity={0}/>
                                                    </linearGradient>
                                                    <linearGradient id="telemetryOnlineGrad" x1="0" y1="0" x2="0" y2="1">
                                                        <stop offset="5%" stopColor="#10b981" stopOpacity={0.3}/>
                                                        <stop offset="95%" stopColor="#10b981" stopOpacity={0}/>
                                                    </linearGradient>
                                                </defs>
                                                <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="#e2e8f0" />
                                                <XAxis dataKey="time" tickLine={false} axisLine={false} tick={{fill: '#64748b', fontSize: 10}} />
                                                
                                                {activeTelemetryTab === 'performance' && (
                                                    <>
                                                        <YAxis yAxisId="left" tickLine={false} axisLine={false} tick={{fill: '#4f46e5', fontSize: 11}} unit="ms" />
                                                        <YAxis yAxisId="right" orientation="right" tickLine={false} axisLine={false} tick={{fill: '#10b981', fontSize: 11}} />
                                                        <Tooltip contentStyle={{ borderRadius: '12px', border: 'none', boxShadow: '0 4px 12px rgba(0,0,0,0.08)', fontFamily: "'Inter', sans-serif" }} />
                                                        <Area yAxisId="left" type="monotone" dataKey="latency" stroke="#4f46e5" strokeWidth={2.5} fillOpacity={1} fill="url(#telemetryPerfGrad)" name="Latency (ms)" />
                                                        <Area yAxisId="right" type="monotone" dataKey="cpu" stroke="#10b981" strokeWidth={2} fillOpacity={0} name="CPU Load" />
                                                    </>
                                                )}

                                                {activeTelemetryTab === 'resources' && (
                                                    <>
                                                        <YAxis yAxisId="left" tickLine={false} axisLine={false} tick={{fill: '#0284c7', fontSize: 11}} unit="M" />
                                                        <YAxis yAxisId="right" orientation="right" tickLine={false} axisLine={false} tick={{fill: '#d97706', fontSize: 11}} unit="%" />
                                                        <Tooltip contentStyle={{ borderRadius: '12px', border: 'none', boxShadow: '0 4px 12px rgba(0,0,0,0.08)', fontFamily: "'Inter', sans-serif" }} />
                                                        <Area yAxisId="left" type="monotone" dataKey="memory" stroke="#0284c7" strokeWidth={2.5} fillOpacity={1} fill="url(#telemetryResGrad)" name="Memory (MB)" />
                                                        <Area yAxisId="right" type="monotone" dataKey="disk" stroke="#d97706" strokeWidth={2} fillOpacity={0} name="Disk Space (%)" />
                                                    </>
                                                )}

                                                {activeTelemetryTab === 'queue' && (
                                                    <>
                                                        <YAxis 
                                                            yAxisId="left" 
                                                            tickLine={false} 
                                                            axisLine={false} 
                                                            tick={{fill: '#e11d48', fontSize: 11}} 
                                                            label={{ value: 'Pending Events', angle: -90, position: 'insideLeft', offset: -10, style: { fill: '#e11d48', fontSize: 9, fontWeight: 'bold' } }}
                                                        />
                                                        <YAxis 
                                                            yAxisId="right" 
                                                            orientation="right" 
                                                            domain={[0, 1]} 
                                                            tickCount={2}
                                                            tickFormatter={(value) => value === 1 ? 'UP' : 'DOWN'}
                                                            tickLine={false} 
                                                            axisLine={false} 
                                                            tick={{fill: '#10b981', fontSize: 10, fontWeight: 'bold'}} 
                                                        />
                                                        <Tooltip 
                                                            contentStyle={{ borderRadius: '12px', border: 'none', boxShadow: '0 4px 12px rgba(0,0,0,0.08)', fontFamily: "'Inter', sans-serif" }}
                                                            formatter={(value, name) => {
                                                                if (name === 'Portal Status') {
                                                                    return [value === 1 ? 'ONLINE (Up)' : 'OFFLINE (Down)', name];
                                                                }
                                                                return [value, name];
                                                            }}
                                                        />
                                                        <Area 
                                                            yAxisId="left" 
                                                            type="monotone" 
                                                            dataKey="pending" 
                                                            stroke="#e11d48" 
                                                            strokeWidth={2.5} 
                                                            fillOpacity={0.3} 
                                                            fill="url(#telemetryQueueGrad)" 
                                                            name="Pending Events" 
                                                        />
                                                        <Area 
                                                            yAxisId="right" 
                                                            type="step" 
                                                            dataKey="portal_status_num" 
                                                            stroke="#10b981" 
                                                            strokeWidth={3} 
                                                            fillOpacity={0.15} 
                                                            fill="url(#telemetryOnlineGrad)" 
                                                            name="Portal Status" 
                                                        />
                                                    </>
                                                )}
                                            </AreaChart>
                                        </ResponsiveContainer>
                                    ) : (
                                        <div className="flex flex-col items-center justify-center h-full text-slate-400 space-y-2">
                                            <svg xmlns="http://www.w3.org/2000/svg" className="h-10 w-10 animate-spin text-slate-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                                            </svg>
                                            <p className="text-xs font-semibold" style={{ fontFamily: "'Inter', sans-serif" }}>Populating live health telemetry logs...</p>
                                        </div>
                                    )}
                                </div>
                            </div>
                        )}

                        {/* Charts Section */}
                        {isDistrictAdmin && (
                            <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                                {/* Sync Status Pie */}
                                <div className="bg-white p-6 rounded-3xl shadow-sm border border-slate-200 col-span-1 flex flex-col justify-between">
                                    <h3 className="text-slate-800 text-lg font-bold mb-4" style={{ fontFamily: "'Outfit', sans-serif" }}>Sync Status Distribution</h3>
                                    <div className="h-64 relative flex items-center justify-center">
                                        <ResponsiveContainer width="100%" height="100%" minWidth={0}>
                                            <PieChart>
                                                <Pie data={statusData} dataKey="value" nameKey="name" cx="50%" cy="50%" innerRadius={60} outerRadius={80} paddingAngle={5}>
                                                    {statusData.map((entry, index) => (
                                                        <Cell key={`cell-${index}`} fill={entry.fill} />
                                                    ))}
                                                </Pie>
                                                <Tooltip contentStyle={{ borderRadius: '10px', fontFamily: "'Inter', sans-serif" }} />
                                            </PieChart>
                                        </ResponsiveContainer>
                                    </div>
                                    <div className="flex flex-wrap justify-center gap-3.5 mt-2 border-t border-slate-100 pt-4">
                                        {statusData.map(entry => (
                                            <div key={entry.name} className="flex items-center gap-1.5 text-xs font-bold text-slate-650" style={{ fontFamily: "'Inter', sans-serif" }}>
                                                <div className="w-2.5 h-2.5 rounded-full shrink-0" style={{ backgroundColor: entry.fill }}></div>
                                                {entry.name} ({entry.value})
                                            </div>
                                        ))}
                                    </div>
                                </div>

                                {/* Events Over Time Area */}
                                <div className="bg-white p-6 rounded-3xl shadow-sm border border-slate-200 col-span-1 lg:col-span-2">
                                    <h3 className="text-slate-800 text-lg font-bold mb-4" style={{ fontFamily: "'Outfit', sans-serif" }}>Campaign Events Trend (Last 30 Days)</h3>
                                    <div className="h-64">
                                        <ResponsiveContainer width="100%" height="100%" minWidth={0}>
                                            <AreaChart data={eventsOverTime} margin={{ top: 10, right: 10, left: -25, bottom: 0 }}>
                                                <defs>
                                                    <linearGradient id="colorCount" x1="0" y1="0" x2="0" y2="1">
                                                        <stop offset="5%" stopColor="#10b981" stopOpacity={0.35}/>
                                                        <stop offset="95%" stopColor="#10b981" stopOpacity={0}/>
                                                    </linearGradient>
                                                </defs>
                                                <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="#e2e8f0" />
                                                <XAxis dataKey="date" tickLine={false} axisLine={false} tick={{fill: '#64748b', fontSize: 11}} />
                                                <YAxis tickLine={false} axisLine={false} tick={{fill: '#64748b', fontSize: 11}} />
                                                <Tooltip contentStyle={{ borderRadius: '12px', border: 'none', boxShadow: '0 4px 12px rgba(0,0,0,0.08)', fontFamily: "'Inter', sans-serif" }} />
                                                <Area type="monotone" dataKey="count" stroke="#10b981" strokeWidth={3} fillOpacity={1} fill="url(#colorCount)" />
                                            </AreaChart>
                                        </ResponsiveContainer>
                                    </div>
                                </div>

                                {/* Events By Block Bar */}
                                <div className="bg-white p-6 rounded-3xl shadow-sm border border-slate-200 col-span-1 lg:col-span-3">
                                    <h3 className="text-slate-800 text-lg font-bold mb-4" style={{ fontFamily: "'Outfit', sans-serif" }}>Events by Block Jurisdiction</h3>
                                    <div className="h-72">
                                        <ResponsiveContainer width="100%" height="100%" minWidth={0}>
                                            <BarChart data={eventsByBlock} margin={{ top: 10, right: 10, left: -25, bottom: 20 }}>
                                                <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="#e2e8f0" />
                                                <XAxis dataKey="name" angle={-45} textAnchor="end" tickLine={false} axisLine={false} tick={{fill: '#64748b', fontSize: 11}} height={65} />
                                                <YAxis tickLine={false} axisLine={false} tick={{fill: '#64748b', fontSize: 11}} />
                                                <Tooltip cursor={{fill: '#f8fafc'}} contentStyle={{ borderRadius: '12px', border: 'none', boxShadow: '0 4px 12px rgba(0,0,0,0.08)', fontFamily: "'Inter', sans-serif" }} />
                                                <Bar dataKey="count" fill="#4f46e5" radius={[5, 5, 0, 0]} />
                                            </BarChart>
                                        </ResponsiveContainer>
                                    </div>
                                </div>
                            </div>
                        )}

                        {/* Recent Events Table Card */}
                        <div className="bg-white p-6 rounded-3xl shadow-sm border border-slate-200 space-y-6">
                            <div className="flex flex-col md:flex-row justify-between items-start md:items-center gap-4 border-b border-slate-100 pb-5">
                                <h3 className="text-xl font-bold text-slate-800" style={{ fontFamily: "'Outfit', sans-serif" }}>Recent Events Log</h3>
                                <div className="flex flex-wrap gap-3 w-full md:w-auto">
                                    <input
                                        type="date"
                                        value={filterDate}
                                        onChange={e => setFilterDate(e.target.value)}
                                        className="rounded-xl border-slate-200 shadow-sm focus:border-emerald-500 focus:ring-emerald-500 sm:text-sm w-full sm:w-auto bg-slate-50/70 py-2.5 px-4 font-medium transition-all"
                                        style={{ fontFamily: "'Inter', sans-serif" }}
                                    />
                                    <select
                                        value={filterStatus}
                                        onChange={e => setFilterStatus(e.target.value)}
                                        className="rounded-xl border-slate-200 shadow-sm focus:border-emerald-500 focus:ring-emerald-500 sm:text-sm w-full sm:w-auto bg-slate-50/70 py-2.5 px-4 font-bold text-slate-700 transition-all cursor-pointer"
                                        style={{ fontFamily: "'Outfit', sans-serif" }}
                                    >
                                        <option value="">All Statuses</option>
                                        <option value="synced">Synced Only</option>
                                        <option value="pending">Pending Only</option>
                                        <option value="failed_permanently">Failed Only</option>
                                    </select>
                                </div>
                            </div>

                            {/* Mobile Card View (xs screens) */}
                            <div className="block sm:hidden space-y-4">
                                {filteredEvents.map(event => (
                                    <div key={event.id} className="bg-slate-50/80 p-5 rounded-2xl border border-slate-200 space-y-4">
                                        <div className="flex justify-between items-start gap-2">
                                            <div className="flex-1 min-w-0">
                                                <p className="font-bold text-slate-900 text-sm truncate" style={{ fontFamily: "'Outfit', sans-serif" }}>{event.event_name}</p>
                                                <p className="text-xs text-slate-500 mt-0.5 truncate" style={{ fontFamily: "'Inter', sans-serif" }}>{event.event_venue}</p>
                                                <p className="text-xs text-slate-400 mt-1 font-mono">{new Date(event.event_date).toLocaleDateString('en-IN')}</p>
                                            </div>
                                            <span className={`shrink-0 px-3 py-1 text-[10px] leading-5 font-black rounded-full shadow-sm uppercase tracking-wider border
                                                ${event.sync_status === 'synced' ? 'bg-emerald-50 text-emerald-800 border-emerald-200' :
                                                event.sync_status === 'pending' ? 'bg-amber-50 text-amber-800 border-amber-200' :
                                                'bg-rose-50 text-rose-800 border-rose-200'}`}>
                                                {event.sync_status.replace('_', ' ')}
                                            </span>
                                        </div>
                                        {isDistrictAdmin && (
                                            <div className="pt-3.5 border-t border-slate-200/80 flex gap-3">
                                                <button
                                                    onClick={() => toggleSync(event.id)}
                                                    className="flex-1 py-2 text-xs font-bold rounded-lg bg-white border border-slate-200 text-slate-650 hover:bg-slate-50 transition-colors shadow-sm"
                                                    style={{ fontFamily: "'Outfit', sans-serif" }}
                                                >
                                                    Toggle Sync
                                                </button>
                                                {event.sync_status !== 'synced' && (
                                                    <button
                                                        onClick={() => retrySync(event.id)}
                                                        className="flex-1 py-2 text-xs font-bold rounded-lg bg-teal-50 border border-teal-200 text-teal-700 hover:bg-teal-100 transition-colors shadow-sm"
                                                        style={{ fontFamily: "'Outfit', sans-serif" }}
                                                    >
                                                        Retry
                                                    </button>
                                                )}
                                            </div>
                                        )}
                                    </div>
                                ))}
                                {filteredEvents.length === 0 && (
                                    <div className="py-12 text-center">
                                        <div className="inline-flex items-center justify-center w-16 h-16 rounded-full bg-slate-100 mb-4 text-slate-400">
                                            <svg xmlns="http://www.w3.org/2000/svg" className="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4" /></svg>
                                        </div>
                                        <p className="text-slate-500 font-medium text-sm">No events found matching active query.</p>
                                    </div>
                                )}
                            </div>

                            {/* Desktop Table View */}
                            <div className="hidden sm:block overflow-x-auto rounded-2xl border border-slate-150">
                                <table className="min-w-full divide-y divide-slate-150">
                                    <thead className="bg-slate-50">
                                        <tr>
                                            <th className="px-6 py-4 text-left text-[10px] font-bold text-slate-500 uppercase tracking-widest" style={{ fontFamily: "'Outfit', sans-serif" }}>Date</th>
                                            <th className="px-6 py-4 text-left text-[10px] font-bold text-slate-500 uppercase tracking-widest" style={{ fontFamily: "'Outfit', sans-serif" }}>Event Name</th>
                                            <th className="px-6 py-4 text-left text-[10px] font-bold text-slate-500 uppercase tracking-widest" style={{ fontFamily: "'Outfit', sans-serif" }}>Venue</th>
                                            <th className="px-6 py-4 text-left text-[10px] font-bold text-slate-500 uppercase tracking-widest" style={{ fontFamily: "'Outfit', sans-serif" }}>Sync Status</th>
                                            {isDistrictAdmin && <th className="px-6 py-4 text-left text-[10px] font-bold text-slate-500 uppercase tracking-widest" style={{ fontFamily: "'Outfit', sans-serif" }}>Actions</th>}
                                        </tr>
                                    </thead>
                                    <tbody className="bg-white divide-y divide-slate-100">
                                        {filteredEvents.map(event => (
                                            <tr key={event.id} className="hover:bg-slate-50/70 transition-colors duration-150 group">
                                                <td className="px-6 py-4 whitespace-nowrap text-sm font-semibold text-slate-600 font-mono">
                                                    {new Date(event.event_date).toLocaleDateString('en-IN')}
                                                </td>
                                                <td className="px-6 py-4 whitespace-nowrap text-sm font-black text-slate-900" style={{ fontFamily: "'Outfit', sans-serif" }}>
                                                    {event.event_name}
                                                </td>
                                                <td className="px-6 py-4 whitespace-nowrap text-sm text-slate-500 font-medium" style={{ fontFamily: "'Inter', sans-serif" }}>
                                                    {event.event_venue}
                                                </td>
                                                <td className="px-6 py-4 whitespace-nowrap">
                                                    <span
                                                        className={`px-3.5 py-1 inline-flex text-[10px] leading-5 font-extrabold rounded-full cursor-help shadow-sm border uppercase tracking-wider
                                                        ${event.sync_status === 'synced' ? 'bg-emerald-50 text-emerald-800 border-emerald-200' :
                                                        event.sync_status === 'pending' ? 'bg-amber-50 text-amber-800 border-amber-200' :
                                                        'bg-rose-50 text-rose-800 border-rose-200'}`}
                                                        title={event.last_error_log || (event.sync_status === 'pending' ? 'Waiting in queue...' : 'Synced')}
                                                    >
                                                        {event.sync_status.replace('_', ' ')}
                                                    </span>
                                                </td>
                                                {isDistrictAdmin && (
                                                    <td className="px-6 py-4 whitespace-nowrap text-sm font-bold" style={{ fontFamily: "'Outfit', sans-serif" }}>
                                                        <button 
                                                            onClick={() => toggleSync(event.id)} 
                                                            className="text-slate-500 hover:text-slate-900 mr-4 transition-colors hover:scale-105 duration-100"
                                                        >
                                                            Toggle Sync
                                                        </button>
                                                        {event.sync_status !== 'synced' && (
                                                            <button 
                                                                onClick={() => retrySync(event.id)} 
                                                                className="text-teal-650 hover:text-teal-900 transition-colors hover:scale-105 duration-100"
                                                            >
                                                                Retry
                                                            </button>
                                                        )}
                                                    </td>
                                                )}
                                            </tr>
                                        ))}
                                        {filteredEvents.length === 0 && (
                                            <tr>
                                                <td colSpan={isDistrictAdmin ? 5 : 4} className="px-6 py-12 text-center">
                                                    <div className="inline-flex items-center justify-center w-16 h-16 rounded-full bg-slate-100 mb-4 text-slate-400">
                                                        <svg xmlns="http://www.w3.org/2000/svg" className="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4" /></svg>
                                                    </div>
                                                    <p className="text-slate-500 font-medium" style={{ fontFamily: "'Inter', sans-serif" }}>No events found matching active query.</p>
                                                </td>
                                            </tr>
                                        )}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                {/* Mobile Sidebar Backdrop */}
                {isDistrictAdmin && isSidebarOpen && (
                    <div
                        className="fixed inset-0 bg-black/55 z-40 lg:hidden backdrop-blur-sm"
                        onClick={() => setIsSidebarOpen(false)}
                        aria-hidden="true"
                    />
                )}

                {/* Highly Polished Settings Sidebar — full-width on mobile, fixed 320px on desktop */}
                {isDistrictAdmin && (
                    <div className={`fixed top-0 right-0 h-full w-full sm:w-80 bg-slate-900 shadow-2xl border-l border-slate-800 transform transition-transform duration-300 ease-in-out z-50 pt-16 overflow-y-auto ${isSidebarOpen ? 'translate-x-0' : 'translate-x-full'} text-white`}>
                        <div className="p-6 space-y-6">
                            {/* Sidebar header */}
                            <div className="flex justify-between items-center border-b border-slate-800 pb-4">
                                <h3 className="text-lg font-black text-slate-100 flex items-center gap-2" style={{ fontFamily: "'Outfit', sans-serif" }}>
                                    <svg xmlns="http://www.w3.org/2000/svg" className="h-5 w-5 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4" />
                                    </svg>
                                    Sync Console
                                </h3>
                                <button onClick={() => setIsSidebarOpen(false)} className="w-8 h-8 flex items-center justify-center rounded-full bg-slate-800 text-slate-400 hover:bg-slate-700 hover:text-white transition-all">
                                    <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2.5" d="M6 18L18 6M6 6l12 12"></path></svg>
                                </button>
                            </div>

                            {/* Quick Actions List */}
                            <div className="space-y-3">
                                <p className="text-[10px] font-black uppercase tracking-widest text-slate-500 px-1">Control Operations</p>

                                <button
                                    onClick={toggleAutoSync}
                                    disabled={processing}
                                    className={`w-full py-3 px-4 font-black rounded-xl transition-all flex items-center gap-3 text-sm disabled:opacity-50 shadow-md ${
                                        healthState.auto_sync_paused
                                            ? 'bg-emerald-600 hover:bg-emerald-500 text-white shadow-emerald-600/10'
                                            : 'bg-amber-600 hover:bg-amber-500 text-white shadow-amber-600/10'
                                    }`}
                                    style={{ fontFamily: "'Outfit', sans-serif" }}
                                >
                                    {healthState.auto_sync_paused ? (
                                        <>
                                            <svg xmlns="http://www.w3.org/2000/svg" className="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2.5" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z" />
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2.5" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                            </svg>
                                            Resume Auto-Sync
                                        </>
                                    ) : (
                                        <>
                                            <svg xmlns="http://www.w3.org/2000/svg" className="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2.5" d="M10 9v6m4-6v6m7-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                            </svg>
                                            Pause Auto-Sync
                                        </>
                                    )}
                                </button>

                                <button
                                    onClick={forceSyncQueue}
                                    disabled={processing}
                                    className="w-full py-3 px-4 bg-slate-800 text-white font-black rounded-xl hover:bg-slate-750 transition-all flex items-center gap-3 text-sm disabled:opacity-50 border border-slate-700 shadow-sm"
                                    style={{ fontFamily: "'Outfit', sans-serif" }}
                                >
                                    <svg xmlns="http://www.w3.org/2000/svg" className="h-4 w-4 shrink-0 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                                    </svg>
                                    Force Sync Queue
                                </button>

                                <button
                                    onClick={runQueueWorker}
                                    disabled={processing}
                                    className="w-full py-3 px-4 bg-sky-600 hover:bg-sky-500 text-white font-black rounded-xl transition-all flex items-center gap-3 text-sm disabled:opacity-50 shadow-sm shadow-sky-600/10"
                                    style={{ fontFamily: "'Outfit', sans-serif" }}
                                >
                                    <svg xmlns="http://www.w3.org/2000/svg" className="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2.5" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z" />
                                    </svg>
                                    Trigger Queue Worker
                                </button>

                                <button
                                    onClick={resetFailedSyncs}
                                    disabled={processing}
                                    className="w-full py-3 px-4 bg-amber-600 hover:bg-amber-500 text-white font-black rounded-xl transition-all flex items-center gap-3 text-sm disabled:opacity-50 shadow-sm shadow-amber-600/10"
                                    style={{ fontFamily: "'Outfit', sans-serif" }}
                                >
                                    <svg xmlns="http://www.w3.org/2000/svg" className="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                                    </svg>
                                    Reset Failed Syncs
                                </button>

                                <a
                                    href={route('events.export')}
                                    className="w-full py-3 px-4 bg-indigo-600 hover:bg-indigo-500 text-white font-black rounded-xl transition-all flex items-center justify-center gap-3 text-sm shadow-md shadow-indigo-600/10"
                                    style={{ fontFamily: "'Outfit', sans-serif" }}
                                >
                                    <svg xmlns="http://www.w3.org/2000/svg" className="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2.5" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                    </svg>
                                    Export CSV Report
                                </a>

                                <button
                                    onClick={clearQueue}
                                    disabled={processing}
                                    className="w-full py-3 px-4 bg-rose-700 hover:bg-rose-600 text-white font-black rounded-xl transition-all flex items-center gap-3 text-sm disabled:opacity-50 shadow-sm shadow-rose-700/10"
                                    style={{ fontFamily: "'Outfit', sans-serif" }}
                                >
                                    <svg xmlns="http://www.w3.org/2000/svg" className="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2.5" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                    </svg>
                                    Clear Queue Logs
                                </button>

                                <button
                                    onClick={() => post(route('events.purge-media'))}
                                    disabled={processing}
                                    className="w-full py-3 px-4 bg-rose-650 hover:bg-rose-550 text-white font-black rounded-xl transition-all flex items-center gap-3 text-sm disabled:opacity-50 shadow-sm shadow-rose-600/10"
                                    style={{ fontFamily: "'Outfit', sans-serif" }}
                                >
                                    <svg xmlns="http://www.w3.org/2000/svg" className="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                    </svg>
                                    Purge Synced Media
                                </button>
                            </div>

                            {/* Credentials Settings Form */}
                            <div className="border-t border-slate-800 pt-5 space-y-4">
                                <p className="text-[10px] font-black uppercase tracking-widest text-slate-500 px-1">Settings & Auth</p>

                                <div className="bg-slate-950 p-5 rounded-2xl border border-slate-800 space-y-4">
                                    <h4 className="text-xs font-black text-emerald-500 uppercase tracking-widest" style={{ fontFamily: "'Outfit', sans-serif" }}>Portal Credentials</h4>
                                    <form onSubmit={submitEnv} className="space-y-4.5">
                                        <div className="space-y-1.5">
                                            <label className="block text-[10px] font-bold text-slate-400 uppercase tracking-wider">Portal URL</label>
                                            <input
                                                type="url"
                                                value={data.portal_url}
                                                onChange={e => setData('portal_url', e.target.value)}
                                                className="block w-full rounded-xl border-slate-800 bg-slate-900 text-white shadow-sm focus:border-emerald-500 focus:ring-emerald-500 text-xs py-2.5 px-3.5 transition-all"
                                                style={{ fontFamily: "'Inter', sans-serif" }}
                                                placeholder="https://example.com"
                                                required
                                            />
                                            {errors.portal_url && <p className="text-rose-400 text-[10px] mt-1 font-medium">{errors.portal_url}</p>}
                                        </div>
                                        <div className="space-y-1.5">
                                            <label className="block text-[10px] font-bold text-slate-400 uppercase tracking-wider">API ID</label>
                                            <input
                                                type="text"
                                                value={data.admin_id}
                                                onChange={e => setData('admin_id', e.target.value)}
                                                className="block w-full rounded-xl border-slate-800 bg-slate-900 text-white shadow-sm focus:border-emerald-500 focus:ring-emerald-500 text-xs py-2.5 px-3.5 transition-all"
                                                style={{ fontFamily: "'Inter', sans-serif" }}
                                                placeholder="Enter API ID"
                                            />
                                            {errors.admin_id && <p className="text-rose-400 text-[10px] mt-1 font-medium">{errors.admin_id}</p>}
                                        </div>
                                        <div className="space-y-1.5">
                                            <label className="block text-[10px] font-bold text-slate-400 uppercase tracking-wider">API Password</label>
                                            <div className="relative">
                                                <input
                                                    type={showPassword ? "text" : "password"}
                                                    value={data.admin_password}
                                                    onChange={e => setData('admin_password', e.target.value)}
                                                    className="block w-full rounded-xl border-slate-800 bg-slate-900 text-white shadow-sm focus:border-emerald-500 focus:ring-emerald-500 text-xs py-2.5 px-3.5 pr-10 transition-all"
                                                    style={{ fontFamily: "'Inter', sans-serif" }}
                                                    placeholder="Enter Password"
                                                />
                                                <button
                                                    type="button"
                                                    onClick={() => setShowPassword(!showPassword)}
                                                    className="absolute inset-y-0 right-0 flex items-center pr-3 text-slate-400 hover:text-emerald-500 focus:outline-none"
                                                >
                                                    {showPassword ? (
                                                        <svg xmlns="http://www.w3.org/2000/svg" className="h-4.5 w-4.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.29 3.29m0 0a10.05 10.05 0 015.71-2.29c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0l-3.29-3.29" /></svg>
                                                    ) : (
                                                        <svg xmlns="http://www.w3.org/2000/svg" className="h-4.5 w-4.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /><path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" /></svg>
                                                    )}
                                                </button>
                                            </div>
                                            {errors.admin_password && <p className="text-rose-400 text-[10px] mt-1 font-medium">{errors.admin_password}</p>}
                                        </div>
                                        {settingsSuccess && (
                                            <p className="text-emerald-400 text-xs font-semibold bg-emerald-950/40 border border-emerald-900/60 rounded-xl px-4 py-2" style={{ fontFamily: "'Inter', sans-serif" }}>{settingsSuccess}</p>
                                        )}
                                        {settingsError && (
                                            <p className="text-rose-400 text-xs font-semibold bg-rose-955/40 border border-rose-900/60 rounded-xl px-4 py-2" style={{ fontFamily: "'Inter', sans-serif" }}>{settingsError}</p>
                                        )}
                                        <button
                                            type="submit"
                                            disabled={processing}
                                            className="w-full py-3 bg-emerald-600 hover:bg-emerald-500 text-white font-bold rounded-xl shadow-lg shadow-emerald-600/10 transition-all disabled:opacity-50"
                                            style={{ fontFamily: "'Outfit', sans-serif" }}
                                        >
                                            {processing ? 'Saving...' : 'Update API Settings'}
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                )}

            {/* Sync Report Modal */}
            {isReportModalOpen && (
                <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-md transition-opacity duration-300">
                    <div 
                        className="bg-white rounded-3xl shadow-2xl border border-slate-100 max-w-3xl w-full max-h-[85vh] overflow-hidden flex flex-col transform transition-all scale-100 animate-in fade-in zoom-in-95 duration-200"
                        style={{ fontFamily: "'Inter', sans-serif" }}
                    >
                        {/* Modal Header */}
                        <div className="p-6 border-b border-slate-105 bg-gradient-to-r from-emerald-50/50 via-teal-50/20 to-transparent flex items-center justify-between">
                            <div>
                                <h3 className="text-xl font-black text-slate-800 flex items-center gap-2.5" style={{ fontFamily: "'Outfit', sans-serif" }}>
                                    <span className="p-2 rounded-xl bg-teal-100 text-teal-700">
                                        <svg xmlns="http://www.w3.org/2000/svg" className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2.5" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                        </svg>
                                    </span>
                                    Sync Report by Event Date
                                </h3>
                                <p className="text-xs text-slate-505 mt-1 font-medium">Comparison of synced events between local and peer databases</p>
                            </div>
                            <button 
                                onClick={() => setIsReportModalOpen(false)}
                                className="p-2 rounded-xl text-slate-400 hover:bg-slate-100 hover:text-slate-600 transition-colors"
                            >
                                <svg xmlns="http://www.w3.org/2000/svg" className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>

                        {/* Controls & Filter */}
                        <div className="p-6 bg-slate-50/60 border-b border-slate-200 flex flex-wrap items-center justify-between gap-4">
                            <div className="flex items-center gap-3">
                                <span className="text-xs font-bold text-slate-600 uppercase tracking-wider">Sync Timeframe:</span>
                                <div className="inline-flex rounded-xl bg-slate-100 p-0.5 border border-slate-200">
                                    {[3, 6, 9, 12, 24, 48].map((h) => (
                                        <button
                                            key={h}
                                            onClick={() => {
                                                setReportHours(h);
                                                fetchSyncReport(h);
                                            }}
                                            className={`px-3 py-1.5 rounded-lg text-xs font-bold transition-all duration-200 ${
                                                reportHours === h
                                                    ? 'bg-white text-slate-800 shadow-sm'
                                                    : 'text-slate-500 hover:text-slate-800'
                                            }`}
                                        >
                                            {h}h
                                        </button>
                                    ))}
                                </div>
                            </div>

                            <button
                                onClick={() => fetchSyncReport(reportHours)}
                                disabled={isReportLoading}
                                className="px-4 py-2 bg-emerald-600 hover:bg-emerald-505 text-white rounded-xl text-xs font-bold shadow-md shadow-emerald-600/10 flex items-center gap-2 transition-all disabled:opacity-50"
                            >
                                <svg xmlns="http://www.w3.org/2000/svg" className={`h-3.5 w-3.5 ${isReportLoading ? 'animate-spin' : ''}`} fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                                </svg>
                                {isReportLoading ? 'Refreshing...' : 'Refresh'}
                            </button>
                        </div>

                        {/* Modal Body */}
                        <div className="flex-1 overflow-y-auto p-6 min-h-[250px] flex flex-col">
                            {isReportLoading && !reportData && (
                                <div className="flex-1 flex flex-col items-center justify-center py-12 space-y-3">
                                    <div className="w-10 h-10 border-4 border-emerald-500/20 border-t-emerald-600 rounded-full animate-spin"></div>
                                    <p className="text-sm text-slate-500 font-medium">Compiling synchronization logs...</p>
                                </div>
                            )}

                            {reportError && (
                                <div className="p-4 bg-rose-50 border border-rose-100 rounded-2xl text-rose-600 text-xs flex items-start gap-2">
                                    <svg xmlns="http://www.w3.org/2000/svg" className="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                                    </svg>
                                    <div>
                                        <p className="font-bold">Error loading sync report</p>
                                        <p className="mt-0.5">{reportError}</p>
                                    </div>
                                </div>
                            )}

                            {!isReportLoading && reportData && reportData.report && reportData.report.length === 0 && (
                                <div className="flex-1 flex flex-col items-center justify-center py-12 text-center">
                                    <div className="w-16 h-16 rounded-full bg-slate-50 flex items-center justify-center text-slate-400 mb-4 border border-slate-100">
                                        <svg xmlns="http://www.w3.org/2000/svg" className="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0a2 2 0 01-2 2H6a2 2 0 01-2-2m16 0V9a2 2 0 00-2-2H6a2 2 0 00-2 2v4.5m16 3H4" />
                                        </svg>
                                    </div>
                                    <h4 className="text-slate-750 font-bold mb-1" style={{ fontFamily: "'Outfit', sans-serif" }}>No Synced Events</h4>
                                    <p className="text-xs text-slate-500 max-w-sm">No events were synchronized on either portal in the last {reportHours} hours.</p>
                                </div>
                            )}

                            {reportData && reportData.report && reportData.report.length > 0 && (
                                <div className="flex-1 space-y-6">
                                    {/* Summary Cards */}
                                    <div className="grid grid-cols-2 gap-4">
                                        <div className="p-4 rounded-2xl bg-slate-50 border border-slate-200">
                                            <p className="text-[10px] font-bold text-slate-550 uppercase tracking-wider mb-1">Timeframe</p>
                                            <p className="text-lg font-black text-slate-800" style={{ fontFamily: "'Outfit', sans-serif" }}>Last {reportData.hours} Hrs</p>
                                        </div>
                                        <div className="p-4 rounded-2xl bg-emerald-50/30 border border-emerald-100">
                                            <p className="text-[10px] font-bold text-emerald-600 uppercase tracking-wider mb-1">Portal ({reportData.portal_name})</p>
                                            <p className="text-lg font-black text-emerald-700" style={{ fontFamily: "'Outfit', sans-serif" }}>
                                                {reportData.report.reduce((sum, r) => sum + r.total, 0).toLocaleString()} <span className="text-xs text-slate-400 font-normal">events</span>
                                            </p>
                                        </div>
                                    </div>

                                    {/* Report Table */}
                                    <div className="border border-slate-200 rounded-2xl overflow-hidden shadow-sm max-h-[300px] overflow-y-auto">
                                        <table className="w-full text-left border-collapse">
                                            <thead>
                                                <tr className="bg-slate-55 border-b border-slate-200 text-[10px] font-bold text-slate-550 uppercase tracking-wider sticky top-0">
                                                    <th className="py-3.5 px-4 bg-slate-50">Event Date</th>
                                                    <th className="py-3.5 px-4 text-right bg-slate-100">Synced Events</th>
                                                </tr>
                                            </thead>
                                            <tbody className="divide-y divide-slate-100 text-xs">
                                                {reportData.report.map((row) => (
                                                    <tr key={row.date} className="hover:bg-slate-50/50 transition-colors">
                                                        <td className="py-3 px-4 font-semibold text-slate-700">{row.date}</td>
                                                        <td className="py-3 px-4 text-right font-mono text-slate-800 font-black bg-slate-50/20">{row.total.toLocaleString()}</td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                            <tfoot>
                                                <tr className="bg-slate-100/50 border-t border-slate-200 text-xs font-bold text-slate-800">
                                                    <td className="py-3 px-4 font-black">Total</td>
                                                    <td className="py-3 px-4 text-right font-mono text-slate-900 font-black bg-slate-100/80">
                                                        {reportData.report.reduce((sum, r) => sum + r.total, 0).toLocaleString()}
                                                    </td>
                                                </tr>
                                            </tfoot>
                                        </table>
                                    </div>
                                </div>
                            )}
                        </div>

                        {/* Modal Footer */}
                        <div className="p-6 border-t border-slate-100 bg-slate-50/50 flex justify-end">
                            <button
                                onClick={() => setIsReportModalOpen(false)}
                                className="px-6 py-2.5 bg-slate-800 hover:bg-slate-700 text-white rounded-xl text-xs font-bold transition-all shadow-sm"
                                style={{ fontFamily: "'Outfit', sans-serif" }}
                            >
                                Close Report
                            </button>
                        </div>
                    </div>
                </div>
            )}

            </div>
        </AuthenticatedLayout>
    );
}
