<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use App\Services\AppIdService;
use Illuminate\Database\Seeder;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $appIds = app(AppIdService::class);
        $roles = Role::pluck('id', 'slug');

        $make = function (string $name, string $email, string $role) use ($appIds, $roles): User {
            $user = User::updateOrCreate(
                ['email' => $email],
                [
                    'name' => $name,
                    'password' => 'MyPa@Demo123',
                    'email_verified_at' => now(),
                    'status' => 'active',
                ]
            );
            $user->profile()->updateOrCreate([], ['timezone' => 'Asia/Kolkata', 'country' => 'India']);
            $user->settings()->firstOrCreate([]);
            if (! $user->appId) {
                $appIds->generateFor($user);
            }
            $user->roles()->sync([$roles[$role]]);

            return $user;
        };

        $superAdmin = $make('Super Admin', 'superadmin@mypa.local', 'super_admin');
        // Demo credentials ship publicly in the README — force a change on first login.
        $superAdmin->update(['force_password_change' => true]);
        $admin = $make('Demo Admin', 'admin@mypa.local', 'admin');
        $make('Subadmin One', 'subadmin1@mypa.local', 'subadmin');
        $make('Subadmin Two', 'subadmin2@mypa.local', 'subadmin');

        $users = collect([
            $make('Rahul Sharma', 'rahul@mypa.local', 'user'),
            $make('Priya Patel', 'priya@mypa.local', 'user'),
            $make('Amit Verma', 'amit@mypa.local', 'user'),
            $make('Sneha Iyer', 'sneha@mypa.local', 'user'),
            $make('Vikram Singh', 'vikram@mypa.local', 'user'),
        ]);

        // Sample tasks for the first demo user
        $rahul = $users->first();
        $work = Category::whereNull('user_id')->where('name', 'Work')->first();
        $family = Category::whereNull('user_id')->where('name', 'Family')->first();
        $bills = Category::whereNull('user_id')->where('name', 'Bills')->first();

        if ($rahul->tasks()->count() === 0) {
            $samples = [
                ['title' => 'Prepare quarterly report', 'category_id' => $work?->id, 'priority' => 'high',
                    'status' => 'in_progress', 'due_at' => now()->addDays(2), 'progress' => 40, 'is_important' => true],
                ['title' => 'Buy groceries for the week', 'category_id' => $family?->id, 'priority' => 'normal',
                    'status' => 'not_started', 'due_at' => now()->addDay()],
                ['title' => 'Pay electricity bill', 'category_id' => $bills?->id, 'priority' => 'urgent',
                    'status' => 'not_started', 'due_at' => now()->addDays(3), 'is_important' => true,
                    'repeat_config' => ['frequency' => 'monthly', 'interval' => 1]],
                ['title' => 'Doctor appointment follow-up', 'priority' => 'medium',
                    'status' => 'completed', 'completed_at' => now()->subDay(), 'progress' => 100],
                ['title' => 'Call parents', 'category_id' => $family?->id, 'priority' => 'normal',
                    'status' => 'not_started', 'due_at' => now()->subDay()],
            ];

            foreach ($samples as $sample) {
                $task = $rahul->tasks()->create($sample);
                $task->logActivity($rahul, 'created');
            }

            // A task assigned by the admin to a user
            $assigned = $admin->tasks()->create([
                'title' => 'Review onboarding checklist',
                'category_id' => $work?->id,
                'priority' => 'high',
                'status' => 'not_started',
                'due_at' => now()->addDays(5),
            ]);
            $assigned->assignees()->attach($rahul->id, ['assigned_by' => $admin->id]);
            $assigned->logActivity($admin, 'created');

            // Checklist demo
            $report = $rahul->tasks()->where('title', 'Prepare quarterly report')->first();
            foreach (['Collect sales data', 'Draft summary', 'Review with team', 'Send to manager'] as $i => $item) {
                $report->checklists()->create(['title' => $item, 'is_done' => $i < 1, 'sort_order' => $i]);
            }

            // Reminder demo
            $report->reminders()->create([
                'user_id' => $rahul->id,
                'remind_at' => now()->addDays(2)->subHour(),
                'offset_minutes' => 60,
                'channels' => ['in_app', 'email'],
            ]);
        }

        $this->command?->info('Demo users seeded. Password for all: MyPa@Demo123');
        $this->command?->warn('Change the Super Admin password after first login!');
    }
}
