import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';
import React from 'react';

export default function SyncLogs({ auth, logs }) {
    const getLevelBadgeColor = (level) => {
        switch (level) {
            case 'ERROR':
            case 'CRITICAL':
            case 'EMERGENCY':
            case 'ALERT':
                return 'bg-red-100 text-red-800';
            case 'WARNING':
                return 'bg-yellow-100 text-yellow-800';
            case 'INFO':
                return 'bg-blue-100 text-blue-800';
            case 'DEBUG':
                return 'bg-gray-100 text-gray-800';
            default:
                return 'bg-gray-100 text-gray-800';
        }
    };

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={<h2 className="font-semibold text-xl text-gray-800 leading-tight">Sync Logs</h2>}
        >
            <Head title="Sync Logs" />

            <div className="py-12">
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8">
                    <div className="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                        <div className="p-6 text-gray-900">
                            
                            <div className="mb-4">
                                <h3 className="text-lg font-medium">Recent Synchronization Activity</h3>
                                <p className="text-sm text-gray-500">Showing the latest 300 sync events, newest first.</p>
                            </div>

                            <div className="overflow-x-auto rounded-lg border border-gray-200">
                                <table className="min-w-full divide-y divide-gray-200">
                                    <thead className="bg-gray-50">
                                        <tr>
                                            <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-48">Date & Time</th>
                                            <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-24">Level</th>
                                            <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Message & Context</th>
                                        </tr>
                                    </thead>
                                    <tbody className="bg-white divide-y divide-gray-200">
                                        {logs.length > 0 ? (
                                            logs.map((log, index) => (
                                                <tr key={index} className="hover:bg-gray-50 transition-colors">
                                                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500 align-top">
                                                        {log.timestamp ? (
                                                            <div className="flex flex-col">
                                                                {(() => {
                                                                    const parts = log.timestamp.split(' ');
                                                                    if (parts.length !== 2) {
                                                                        return <span className="font-medium text-gray-700">{log.timestamp}</span>;
                                                                    }
                                                                    const [datePart, timePart] = parts;
                                                                    const dateParts = datePart.split('-');
                                                                    const timeParts = timePart.split(':');
                                                                    if (dateParts.length !== 3 || timeParts.length !== 3) {
                                                                        return <span className="font-medium text-gray-700">{log.timestamp}</span>;
                                                                    }
                                                                    const [y, m, d] = dateParts;
                                                                    const [H, i, s] = timeParts;
                                                                    const HNum = parseInt(H, 10);
                                                                    const ampm = HNum >= 12 ? 'PM' : 'AM';
                                                                    const H12 = HNum % 12 || 12;
                                                                    
                                                                    return (
                                                                        <>
                                                                            <span className="font-medium text-gray-700">{`${d}-${m}-${y}`}</span>
                                                                            <span>{`${H12.toString().padStart(2, '0')}:${i}:${s} ${ampm}`}</span>
                                                                        </>
                                                                    );
                                                                })()}
                                                            </div>
                                                        ) : '-'}
                                                    </td>
                                                    <td className="px-6 py-4 whitespace-nowrap align-top">
                                                        <span className={`px-2 inline-flex text-xs leading-5 font-semibold rounded-full ${getLevelBadgeColor(log.level)}`}>
                                                            {log.level}
                                                        </span>
                                                    </td>
                                                    <td className="px-6 py-4 text-sm text-gray-500 align-top max-w-3xl break-words whitespace-pre-wrap">
                                                        <div className="font-medium text-gray-900 mb-1">{log.message}</div>
                                                        {log.context && (
                                                            <pre className="mt-2 text-xs bg-gray-100 p-2 rounded max-h-40 overflow-y-auto whitespace-pre-wrap font-mono text-gray-700 border border-gray-200">
                                                                {JSON.stringify(log.context, null, 2)}
                                                            </pre>
                                                        )}
                                                        {!log.timestamp && (
                                                            <div className="text-xs text-gray-400 font-mono">{log.raw}</div>
                                                        )}
                                                    </td>
                                                </tr>
                                            ))
                                        ) : (
                                            <tr>
                                                <td colSpan="3" className="px-6 py-8 text-center text-gray-500">
                                                    No sync logs found for today.
                                                </td>
                                            </tr>
                                        )}
                                    </tbody>
                                </table>
                            </div>
                            
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
