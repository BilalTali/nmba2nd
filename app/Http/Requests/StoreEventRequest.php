<?php

namespace App\Http\Requests;

use App\Models\Event;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEventRequest extends FormRequest
{
    /**
     * All authenticated operators are authorized to submit events.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Canonicalize, trim, and auto-infer derived fields before validation runs.
     * This ensures all downstream validators work on clean, normalized data.
     */
    protected function prepareForValidation(): void
    {
        // Normalize top-level string primitives: lowercase + trim.
        $merges = [];

        if ($this->has('event_name') && is_string($this->event_name)) {
            $merges['event_name'] = strtolower(trim($this->event_name));
        }
        if ($this->has('event_venue') && is_string($this->event_venue)) {
            $merges['event_venue'] = strtolower(trim($this->event_venue));
        }

        // Strip whitespace from all nested array elements (multi-selects).
        if ($this->has('event_category') && is_array($this->event_category)) {
            $merges['event_category'] = array_map('trim', $this->event_category);
        }
        if ($this->has('target_audience') && is_array($this->target_audience)) {
            $merges['target_audience'] = array_map('trim', $this->target_audience);
        }
        if ($this->has('age_group') && is_array($this->age_group)) {
            $merges['age_group'] = array_map('trim', $this->age_group);
        }

        // Auto-populate district from global environment config
        $merges['district_id']   = (int) config('app.district_id', 5);
        $merges['district_name'] = config('app.district_name', 'Budgam');

        // For block workers, auto-inject their block_id so it passes validation
        // (block field is read-only in the UI and set server-side from the user's profile)
        if (auth()->check() && auth()->user()->role === 'block_worker') {
            $merges['block_id'] = auth()->user()->block_id;
        }

        $this->merge($merges);
    }

    /**
     * Full validation rule set covering all 16 data dictionary fields.
     */
    public function rules(): array
    {
        return [
            // Core event identity fields
            'event_name'  => ['required', 'string', 'max:255'],
            'event_date'  => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'event_venue' => ['required', 'string', 'max:255'],

            // Multi-select categories
            'event_category'   => ['required', 'array', 'min:1'],
            'event_category.*' => [
                'required', 'string',
                Rule::in(['Cultural', 'Awareness', 'Sports', 'Training & Counselling']),
            ],
            
            'event_category_remark' => ['nullable', 'string', 'max:255'],

            // District (auto-populated)
            'district_id'   => ['required', 'integer'],
            'district_name' => ['required', 'string'],

            // Block jurisdiction
            'block_id' => [
                'required', 'integer',
                Rule::exists('blocks', 'id'),
            ],

            // Department
            'department_id' => [
                'nullable', 'integer',
                Rule::exists('departments', 'id'),
            ],

            // Optional location fields
            'ward'    => ['nullable', 'string', 'max:100'],
            'village' => ['nullable', 'string', 'max:255'],

            // Attendance — range auto-derived; count required
            'attendance_range'  => [
                'required',
                'string',
                function ($attribute, $value, $fail) {
                    $actual = request()->input('actual_attendance');
                    if (is_numeric($actual) && $actual > 0) {
                        try {
                            $inferred = Event::inferAttendanceRange((int)$actual);
                            if ($value !== $inferred) {
                                $fail("The selected attendance range does not match the actual attendance count (which falls in range '{$inferred}').");
                            }
                        } catch (\Exception $e) {
                            $fail("Invalid actual attendance value.");
                        }
                    }
                }
            ],
            'actual_attendance' => ['required', 'integer', 'min:20'],

            // Multi-select audience and age demographics
            'target_audience'   => ['required', 'array', 'min:1'],
            'target_audience.*' => [
                'required', 'string',
                Rule::in(['Civil Society', 'Students', 'Youth', 'Transporters', 'Other']),
            ],

            'age_group'   => ['required', 'array', 'min:1'],
            'age_group.*' => [
                'required', 'string',
                Rule::in(['Under 18', '18-25', '25-35', '35-45', '45-55', 'Above 55']),
            ],

            // Coordinator contact fields
            'event_coordinator_name'           => ['required', 'string', 'max:255'],
            'event_coordinator_contact_number' => [
                'required',
                'digits:10',
                'regex:/^[6-9][0-9]{9}$/',
            ],
            'event_coordinator_desig' => ['required', 'string', 'max:255'],

            // Photo array constraints — 1 to 3 files, raw upload ceiling 10 MB each.
            // The ImageOptimizationService will compress down to ≤5 MB before portal submission.
            'photo'   => ['required', 'array', 'min:1', 'max:3'],
            'photo.*' => ['file', 'mimes:jpeg,jpg,png,gif', 'max:10240'],

            // Client-side Device ID
            'device_id' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Human-readable validation error messages for UI display.
     */
    public function messages(): array
    {
        return [
            'event_coordinator_contact_number.digits' => 'Contact number must be exactly 10 digits.',
            'event_coordinator_contact_number.regex'  => 'Contact number must start with 6, 7, 8, or 9.',
            'photo.min'                               => 'At least 1 activity photo is required.',
            'photo.max'                               => 'A maximum of 3 activity photos are allowed.',
            'photo.*.max'                             => 'Each photo must not exceed 10 MB.',
            'photo.*.mimes'                           => 'Photos must be JPEG, PNG, or GIF format.',
            'event_date.before_or_equal'              => 'Event date cannot be in the future.',
        ];
    }
}
