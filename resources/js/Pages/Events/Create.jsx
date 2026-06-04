import React, { useState, useEffect } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm } from '@inertiajs/react';

const getOrCreateDeviceUuid = () => {
    if (typeof window === 'undefined') return '';
    let uuid = localStorage.getItem('nmba_device_uuid');
    if (!uuid) {
        try {
            uuid = ([1e7]+-1e3+-4e3+-8e3+-1e11).replace(/[018]/g, c =>
                (c ^ crypto.getRandomValues(new Uint8Array(1))[0] & 15 >> c / 4).toString(16)
            );
        } catch (e) {
            uuid = 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
                var r = Math.random() * 16 | 0, v = c == 'x' ? r : (r & 0x3 | 0x8);
                return v.toString(16);
            });
        }
        localStorage.setItem('nmba_device_uuid', uuid);
    }
    return uuid;
};

export default function Create({ blocks, departments }) {
    const todayStr = new Date().toISOString().split('T')[0];

    const { data, setData, post, processing, errors } = useForm({
        event_name: '',
        event_date: todayStr,
        event_venue: '',
        event_category: [],
        event_category_remark: '',
        block_id: '',
        department_id: '',
        ward: '',
        village: '',
        actual_attendance: '',
        attendance_range: '',
        target_audience: [],
        age_group: [],
        event_coordinator_name: '',
        event_coordinator_contact_number: '',
        event_coordinator_desig: '',
        photo: [],
        device_id: ''
    });

    const [step, setStep] = useState(1);

    // Initialize Device ID on mount
    useEffect(() => {
        setData('device_id', getOrCreateDeviceUuid());
    }, []);

    // Helper to calculate attendance range
    useEffect(() => {
        const count = parseInt(data.actual_attendance);
        if (!isNaN(count) && count > 0) {
            let range = '500 & above';
            if (count <= 40) range = '20-40';
            else if (count <= 100) range = '40-100';
            else if (count <= 150) range = '100-150';
            else if (count <= 200) range = '150-200';
            else if (count <= 500) range = '200-500';
            setData('attendance_range', range);
        } else {
            setData('attendance_range', '');
        }
    }, [data.actual_attendance]);

    const handleCheckboxChange = (e, field) => {
        const { value, checked } = e.target;
        if (checked) {
            setData(field, [...data[field], value]);
        } else {
            setData(field, data[field].filter(v => v !== value));
        }
    };

    const handleFileChange = (e) => {
        setData('photo', Array.from(e.target.files));
    };

    const submit = (e) => {
        e.preventDefault();
        if (step !== 3) return;
        post(route('events.store'));
    };

    const nextStep = () => {
        const form = document.querySelector('form');
        if (!form.reportValidity()) return;

        if (step === 2) {
            if (data.event_category.length === 0) {
                alert("Please select at least one Event Category.");
                return;
            }
            if (data.target_audience.length === 0) {
                alert("Please select at least one Target Audience.");
                return;
            }
            if (data.age_group.length === 0) {
                alert("Please select at least one Age Group.");
                return;
            }
        }

        setStep(prev => Math.min(prev + 1, 3));
    };
    const prevStep = () => setStep(prev => Math.max(prev - 1, 1));

    const categories = ['Cultural', 'Awareness', 'Sports', 'Training & Counselling'];
    const audiences = ['Civil Society', 'Students', 'Youth', 'Transporters', 'Other'];
    const ages = ['Under 18', '18-25', '25-35', '35-45', '45-55', 'Above 55'];

    return (
        <AuthenticatedLayout
            header={<h2 className="text-xl font-semibold leading-tight text-emerald-900">Create Event</h2>}
        >
            <Head title="Create Event" />

            <div className="py-12 bg-slate-50 min-h-screen">
                <div className="mx-auto max-w-4xl sm:px-6 lg:px-8">
                    
                    {/* Progress Bar */}
                    <div className="mb-8 relative">
                        <div className="overflow-hidden h-2 mb-4 text-xs flex rounded-full bg-emerald-100">
                            <div style={{ width: `${(step / 3) * 100}%` }} className="shadow-none flex flex-col text-center whitespace-nowrap text-white justify-center bg-emerald-500 transition-all duration-500"></div>
                        </div>
                        <div className="flex justify-between text-xs font-semibold text-emerald-800 px-1">
                            <span className={step >= 1 ? 'opacity-100' : 'opacity-50'}>1. Basic Info</span>
                            <span className={step >= 2 ? 'opacity-100' : 'opacity-50'}>2. Demographics</span>
                            <span className={step >= 3 ? 'opacity-100' : 'opacity-50'}>3. Coordinator & Media</span>
                        </div>
                    </div>

                    <div className="overflow-hidden bg-white shadow-2xl sm:rounded-3xl border border-emerald-50">
                        <div className="p-8 sm:p-12">
                            <h3 className="text-3xl font-extrabold text-emerald-900 mb-8 tracking-tight">
                                {step === 1 && "Basic Event Information"}
                                {step === 2 && "Demographics & Categories"}
                                {step === 3 && "Coordinator & Media"}
                            </h3>

                            {errors.error && (
                                <div className="mb-6 bg-red-50 border-l-4 border-red-500 p-4 rounded-md">
                                    <p className="text-red-700 text-sm font-medium">{errors.error}</p>
                                </div>
                            )}
                            {errors.duplicate && (
                                <div className="mb-6 bg-yellow-50 border-l-4 border-yellow-500 p-4 rounded-md">
                                    <p className="text-yellow-700 text-sm font-medium">{errors.duplicate}</p>
                                </div>
                            )}

                            <form onSubmit={submit} className="space-y-8" encType="multipart/form-data">
                                
                                {/* Step 1: Core Information */}
                                {step === 1 && (
                                    <div className="grid grid-cols-1 md:grid-cols-2 gap-6 animate-fade-in-up">
                                        <div>
                                            <label className="block text-sm font-bold text-slate-700">Event Name</label>
                                            <input type="text" value={data.event_name} onChange={e => setData('event_name', e.target.value)}
                                                className="mt-2 block w-full rounded-xl border-slate-200 bg-slate-50 py-3 px-4 shadow-sm focus:border-emerald-500 focus:ring-emerald-500 focus:bg-white transition-all" placeholder="e.g. Nasha Mukt Rally" required />
                                            {errors.event_name && <p className="text-red-500 text-xs mt-1">{errors.event_name}</p>}
                                        </div>
                                        
                                        <div>
                                            <label className="block text-sm font-bold text-slate-700">Event Date</label>
                                            <input type="date" value={data.event_date} readOnly min={todayStr} max={todayStr}
                                                className="mt-2 block w-full rounded-xl border-slate-200 bg-emerald-50 text-emerald-700 font-semibold shadow-inner cursor-not-allowed py-3 px-4" required />
                                            {errors.event_date && <p className="text-red-500 text-xs mt-1">{errors.event_date}</p>}
                                        </div>

                                        <div className="col-span-full">
                                            <label className="block text-sm font-bold text-slate-700">Event Venue</label>
                                            <input type="text" value={data.event_venue} onChange={e => setData('event_venue', e.target.value)}
                                                className="mt-2 block w-full rounded-xl border-slate-200 bg-slate-50 py-3 px-4 shadow-sm focus:border-emerald-500 focus:ring-emerald-500 focus:bg-white transition-all" placeholder="Enter event venue" required />
                                            {errors.event_venue && <p className="text-red-500 text-xs mt-1">{errors.event_venue}</p>}
                                        </div>

                                        <div>
                                            <label className="block text-sm font-bold text-slate-700">Block</label>
                                            <select value={data.block_id} onChange={e => setData('block_id', e.target.value)}
                                                className="mt-2 block w-full rounded-xl border-slate-200 bg-slate-50 py-3 px-4 shadow-sm focus:border-emerald-500 focus:ring-emerald-500 focus:bg-white transition-all" required>
                                                <option value="">Select Block</option>
                                                {Object.entries(blocks).map(([id, name]) => (
                                                    <option key={id} value={id}>{name}</option>
                                                ))}
                                            </select>
                                            {errors.block_id && <p className="text-red-500 text-xs mt-1">{errors.block_id}</p>}
                                        </div>

                                        <div>
                                            <label className="block text-sm font-bold text-slate-700">Department <span className="font-normal text-slate-400 text-xs">(Optional)</span></label>
                                            <select value={data.department_id} onChange={e => setData('department_id', e.target.value)}
                                                className="mt-2 block w-full rounded-xl border-slate-200 bg-slate-50 py-3 px-4 shadow-sm focus:border-emerald-500 focus:ring-emerald-500 focus:bg-white transition-all">
                                                <option value="">Select Department</option>
                                                {departments && Object.entries(departments).map(([id, name]) => (
                                                    <option key={id} value={id}>{name}</option>
                                                ))}
                                            </select>
                                            {errors.department_id && <p className="text-red-500 text-xs mt-1">{errors.department_id}</p>}
                                        </div>

                                        <div>
                                            <label className="block text-sm font-bold text-slate-700">Ward</label>
                                            <input type="text" value={data.ward} onChange={e => setData('ward', e.target.value)}
                                                className="mt-2 block w-full rounded-xl border-slate-200 bg-slate-50 py-3 px-4 shadow-sm focus:border-emerald-500 focus:ring-emerald-500 focus:bg-white transition-all" required />
                                            {errors.ward && <p className="text-red-500 text-xs mt-1">{errors.ward}</p>}
                                        </div>

                                        <div>
                                            <label className="block text-sm font-bold text-slate-700">Village</label>
                                            <input type="text" value={data.village} onChange={e => setData('village', e.target.value)}
                                                className="mt-2 block w-full rounded-xl border-slate-200 bg-slate-50 py-3 px-4 shadow-sm focus:border-emerald-500 focus:ring-emerald-500 focus:bg-white transition-all" required />
                                            {errors.village && <p className="text-red-500 text-xs mt-1">{errors.village}</p>}
                                        </div>
                                    </div>
                                )}

                                {/* Step 2: Demographics */}
                                {step === 2 && (
                                    <div className="grid grid-cols-1 md:grid-cols-2 gap-8 animate-fade-in-up">
                                        <div>
                                            <label className="block text-sm font-bold text-slate-700 mb-3">Event Category</label>
                                            <div className="space-y-3 p-5 rounded-2xl bg-slate-50 border border-slate-100">
                                                {categories.map(cat => (
                                                    <label key={cat} className="flex items-center space-x-3 cursor-pointer group">
                                                        <input type="checkbox" value={cat} checked={data.event_category.includes(cat)} onChange={e => handleCheckboxChange(e, 'event_category')}
                                                            className="form-checkbox h-5 w-5 text-emerald-500 border-slate-300 rounded focus:ring-emerald-500 transition duration-150 ease-in-out" />
                                                        <span className="text-slate-700 font-medium group-hover:text-emerald-600 transition-colors">{cat}</span>
                                                    </label>
                                                ))}
                                            </div>
                                            {errors.event_category && <p className="text-red-500 text-xs mt-1">{errors.event_category}</p>}
                                        </div>

                                        <div>
                                            <label className="block text-sm font-bold text-slate-700 mb-3">Target Audience</label>
                                            <div className="space-y-3 p-5 rounded-2xl bg-slate-50 border border-slate-100">
                                                {audiences.map(aud => (
                                                    <label key={aud} className="flex items-center space-x-3 cursor-pointer group">
                                                        <input type="checkbox" value={aud} checked={data.target_audience.includes(aud)} onChange={e => handleCheckboxChange(e, 'target_audience')}
                                                            className="form-checkbox h-5 w-5 text-emerald-500 border-slate-300 rounded focus:ring-emerald-500 transition duration-150 ease-in-out" />
                                                        <span className="text-slate-700 font-medium group-hover:text-emerald-600 transition-colors">{aud}</span>
                                                    </label>
                                                ))}
                                            </div>
                                            {errors.target_audience && <p className="text-red-500 text-xs mt-1">{errors.target_audience}</p>}
                                        </div>

                                        <div>
                                            <label className="block text-sm font-bold text-slate-700 mb-3">Age Groups</label>
                                            <div className="space-y-3 p-5 rounded-2xl bg-slate-50 border border-slate-100">
                                                {ages.map(age => (
                                                    <label key={age} className="flex items-center space-x-3 cursor-pointer group">
                                                        <input type="checkbox" value={age} checked={data.age_group.includes(age)} onChange={e => handleCheckboxChange(e, 'age_group')}
                                                            className="form-checkbox h-5 w-5 text-emerald-500 border-slate-300 rounded focus:ring-emerald-500 transition duration-150 ease-in-out" />
                                                        <span className="text-slate-700 font-medium group-hover:text-emerald-600 transition-colors">{age}</span>
                                                    </label>
                                                ))}
                                            </div>
                                            {errors.age_group && <p className="text-red-500 text-xs mt-1">{errors.age_group}</p>}
                                        </div>

                                        <div className="space-y-6">
                                            <div>
                                                <label className="block text-sm font-bold text-slate-700">Actual Attendance</label>
                                                <input type="number" min="20" value={data.actual_attendance} onChange={e => setData('actual_attendance', e.target.value)}
                                                    className="mt-2 block w-full rounded-xl border-slate-200 bg-slate-50 py-3 px-4 shadow-sm focus:border-emerald-500 focus:ring-emerald-500 transition-all" required />
                                                {errors.actual_attendance && <p className="text-red-500 text-xs mt-1">{errors.actual_attendance}</p>}
                                            </div>
                                            
                                            <div>
                                                <label className="block text-sm font-bold text-slate-700">Inferred Range <span className="font-normal text-slate-400">(Auto-calculated)</span></label>
                                                <input type="text" value={data.attendance_range} readOnly
                                                    className="mt-2 block w-full rounded-xl border-slate-200 bg-emerald-50 text-emerald-700 font-medium shadow-inner cursor-not-allowed py-3 px-4" />
                                            </div>
                                        </div>
                                    </div>
                                )}

                                {/* Step 3: Coordinator & File Upload */}
                                {step === 3 && (
                                    <div className="space-y-8 animate-fade-in-up">
                                        <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
                                            <div>
                                                <label className="block text-sm font-bold text-slate-700">Coordinator Name</label>
                                                <input type="text" value={data.event_coordinator_name} onChange={e => setData('event_coordinator_name', e.target.value)}
                                                    className="mt-2 block w-full rounded-xl border-slate-200 bg-slate-50 py-3 px-4 shadow-sm focus:border-emerald-500 focus:ring-emerald-500" required />
                                                {errors.event_coordinator_name && <p className="text-red-500 text-xs mt-1">{errors.event_coordinator_name}</p>}
                                            </div>
                                            <div>
                                                <label className="block text-sm font-bold text-slate-700">Designation</label>
                                                <input type="text" value={data.event_coordinator_desig} onChange={e => setData('event_coordinator_desig', e.target.value)}
                                                    className="mt-2 block w-full rounded-xl border-slate-200 bg-slate-50 py-3 px-4 shadow-sm focus:border-emerald-500 focus:ring-emerald-500" required />
                                                {errors.event_coordinator_desig && <p className="text-red-500 text-xs mt-1">{errors.event_coordinator_desig}</p>}
                                            </div>
                                            <div>
                                                <label className="block text-sm font-bold text-slate-700">Contact Number</label>
                                                <input type="text" value={data.event_coordinator_contact_number} onChange={e => setData('event_coordinator_contact_number', e.target.value)}
                                                    className="mt-2 block w-full rounded-xl border-slate-200 bg-slate-50 py-3 px-4 shadow-sm focus:border-emerald-500 focus:ring-emerald-500" required />
                                                {errors.event_coordinator_contact_number && <p className="text-red-500 text-xs mt-1">{errors.event_coordinator_contact_number}</p>}
                                            </div>
                                        </div>

                                        <div className="border-2 border-dashed border-emerald-300 rounded-3xl p-10 text-center bg-emerald-50/50 hover:bg-emerald-50 transition-colors group relative overflow-hidden">
                                            <div className="absolute inset-0 bg-emerald-500/5 translate-y-full group-hover:translate-y-0 transition-transform duration-500"></div>
                                            <div className="relative z-10">
                                                <div className="w-16 h-16 bg-white rounded-full flex items-center justify-center mx-auto mb-4 shadow-sm text-emerald-500">
                                                    <svg xmlns="http://www.w3.org/2000/svg" className="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                                    </svg>
                                                </div>
                                                <label className="block text-lg font-bold text-emerald-900 mb-2">Upload Photos (Max 3)</label>
                                                <input type="file" multiple accept="image/*" onChange={handleFileChange}
                                                    className="block w-full max-w-sm mx-auto text-sm text-slate-500 file:mr-4 file:py-2.5 file:px-6 file:rounded-full file:border-0 file:text-sm file:font-bold file:bg-emerald-100 file:text-emerald-700 hover:file:bg-emerald-200 transition-all cursor-pointer" required />
                                                <p className="mt-4 text-xs font-medium text-emerald-600/70">PNG, JPG, GIF up to 10MB each</p>
                                            </div>
                                            {errors.photo && <p className="text-red-500 text-xs mt-2 relative z-10">{errors.photo}</p>}
                                            {Object.keys(errors).filter(k => k.startsWith('photo.')).map(k => (
                                                <p key={k} className="text-red-500 text-xs mt-2 relative z-10">{errors[k]}</p>
                                            ))}
                                        </div>
                                    </div>
                                )}

                                <div className="pt-8 flex justify-between items-center border-t border-slate-100 mt-12">
                                    {step > 1 ? (
                                        <button type="button" onClick={prevStep}
                                            className="px-8 py-3.5 rounded-xl font-bold text-slate-600 bg-slate-100 hover:bg-slate-200 transition-all">
                                            Back
                                        </button>
                                    ) : (
                                        <button type="button" onClick={() => window.history.back()}
                                            className="px-8 py-3.5 rounded-xl font-bold text-slate-500 hover:text-slate-800 transition-all">
                                            Cancel
                                        </button>
                                    )}

                                    {step < 3 ? (
                                        <button type="button" onClick={nextStep}
                                            className="px-8 py-3.5 rounded-xl font-bold text-white bg-emerald-600 hover:bg-emerald-500 shadow-lg shadow-emerald-500/30 transition-all">
                                            Next Step
                                        </button>
                                    ) : (
                                        <button type="submit" disabled={processing}
                                            className="px-8 py-3.5 rounded-xl font-bold text-white bg-emerald-600 hover:bg-emerald-500 shadow-lg shadow-emerald-500/30 transition-all disabled:opacity-50 flex items-center gap-2">
                                            {processing ? 'Submitting...' : 'Submit Event'}
                                            {!processing && (
                                                <svg xmlns="http://www.w3.org/2000/svg" className="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                                                    <path fillRule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clipRule="evenodd" />
                                                </svg>
                                            )}
                                        </button>
                                    )}
                                </div>
                            </form>

                        </div>
                    </div>
                </div>
            </div>
            <style jsx>{`
                @keyframes fadeInUp {
                    from { opacity: 0; transform: translateY(10px); }
                    to { opacity: 1; transform: translateY(0); }
                }
                .animate-fade-in-up {
                    animation: fadeInUp 0.4s ease-out forwards;
                }
            `}</style>
        </AuthenticatedLayout>
    );
}
