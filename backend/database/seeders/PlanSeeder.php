<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $gb = 1024 * 1024 * 1024;

        $plans = [
            [
                'slug' => 'free',
                'name' => 'Free',
                'description' => 'Get organised with the essentials.',
                'monthly_price' => 0, 'annual_price' => 0, 'sort_order' => 0,
                'limits' => [
                    'max_tasks' => 50, 'storage_bytes' => $gb, 'max_groups' => 1,
                    'max_group_members' => 4, 'max_categories' => 5,
                    'max_meeting_participants' => 4, 'max_meeting_minutes' => 40,
                ],
                'features' => [
                    'reminders' => true, 'notes' => true, 'voice_assistant' => true,
                    'calls' => false, 'reports_export' => false, 'subadmins' => false,
                ],
            ],
            [
                'slug' => 'personal',
                'name' => 'Personal',
                'description' => 'Unlimited personal productivity.',
                'monthly_price' => 99, 'annual_price' => 999, 'trial_days' => 14, 'sort_order' => 1,
                'limits' => [
                    'max_tasks' => null, 'storage_bytes' => 5 * $gb, 'max_groups' => 2,
                    'max_group_members' => 6, 'max_categories' => null,
                    'max_meeting_participants' => 8, 'max_meeting_minutes' => 60,
                ],
                'features' => [
                    'reminders' => true, 'notes' => true, 'voice_assistant' => true,
                    'calls' => true, 'reports_export' => true, 'subadmins' => false,
                ],
            ],
            [
                'slug' => 'family',
                'name' => 'Family',
                'description' => 'Shared tasks, calendar, chat and calls for the whole family.',
                'monthly_price' => 199, 'annual_price' => 1999, 'trial_days' => 14,
                'is_recommended' => true, 'sort_order' => 2,
                'limits' => [
                    'max_tasks' => null, 'storage_bytes' => 20 * $gb, 'max_groups' => 5,
                    'max_group_members' => 12, 'max_categories' => null,
                    'max_meeting_participants' => 16, 'max_meeting_minutes' => 120,
                ],
                'features' => [
                    'reminders' => true, 'notes' => true, 'voice_assistant' => true,
                    'calls' => true, 'reports_export' => true, 'subadmins' => false,
                ],
            ],
            [
                'slug' => 'professional',
                'name' => 'Professional',
                'description' => 'Team management with assignments and reports.',
                'monthly_price' => 499, 'annual_price' => 4999, 'trial_days' => 14, 'sort_order' => 3,
                'limits' => [
                    'max_tasks' => null, 'storage_bytes' => 50 * $gb, 'max_groups' => 15,
                    'max_group_members' => 30, 'max_categories' => null,
                    'max_meeting_participants' => 50, 'max_meeting_minutes' => 300,
                ],
                'features' => [
                    'reminders' => true, 'notes' => true, 'voice_assistant' => true,
                    'calls' => true, 'reports_export' => true, 'subadmins' => true,
                ],
            ],
            [
                'slug' => 'business',
                'name' => 'Business',
                'description' => 'Larger teams, audit logs and priority support.',
                'monthly_price' => 999, 'annual_price' => 9999, 'sort_order' => 4,
                'limits' => [
                    'max_tasks' => null, 'storage_bytes' => 200 * $gb, 'max_groups' => null,
                    'max_group_members' => 100, 'max_categories' => null,
                    'max_meeting_participants' => 100, 'max_meeting_minutes' => null,
                ],
                'features' => [
                    'reminders' => true, 'notes' => true, 'voice_assistant' => true,
                    'calls' => true, 'reports_export' => true, 'subadmins' => true,
                ],
            ],
            [
                'slug' => 'enterprise',
                'name' => 'Enterprise',
                'description' => 'Custom limits, integrations and dedicated support.',
                'monthly_price' => 0, 'annual_price' => 0, 'is_public' => false, 'sort_order' => 5,
                'limits' => [
                    'max_tasks' => null, 'storage_bytes' => null, 'max_groups' => null,
                    'max_group_members' => null, 'max_categories' => null,
                    'max_meeting_participants' => null, 'max_meeting_minutes' => null,
                ],
                'features' => [
                    'reminders' => true, 'notes' => true, 'voice_assistant' => true,
                    'calls' => true, 'reports_export' => true, 'subadmins' => true,
                ],
            ],
        ];

        foreach ($plans as $plan) {
            Plan::updateOrCreate(['slug' => $plan['slug']], $plan);
        }
    }
}
