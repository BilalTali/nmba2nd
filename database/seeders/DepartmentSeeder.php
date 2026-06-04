<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DepartmentSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $departments = [
            'Education',
            'DYSSO',
            'Social welfare',
            'Higher Education',
            'Health',
            'RDD',
            'NRLM',
            'ICDS',
            'Excise',
            'Forest',
            'Drug Controller',
            'HUD',
            'Agriculture',
            'Revenue',
            'Transport',
            'Bank through LDM',
            'Food supplies',
            'DDAC',
        ];

        foreach ($departments as $name) {
            \App\Models\Department::firstOrCreate(['name' => $name]);
        }
    }
}
