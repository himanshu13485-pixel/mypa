<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

class DefaultCategorySeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            ['name' => 'Personal', 'icon' => 'user', 'color' => '#406cf0'],
            ['name' => 'Important', 'icon' => 'star', 'color' => '#f59e0b'],
            ['name' => 'Family', 'icon' => 'users', 'color' => '#ec4899'],
            ['name' => 'Work', 'icon' => 'briefcase', 'color' => '#8b5cf6'],
            ['name' => 'Business', 'icon' => 'building', 'color' => '#0ea5e9'],
            ['name' => 'Health', 'icon' => 'heart-pulse', 'color' => '#ef4444'],
            ['name' => 'Finance', 'icon' => 'wallet', 'color' => '#10b981'],
            ['name' => 'Shopping', 'icon' => 'shopping-cart', 'color' => '#f97316'],
            ['name' => 'Travel', 'icon' => 'plane', 'color' => '#06b6d4'],
            ['name' => 'Education', 'icon' => 'graduation-cap', 'color' => '#6366f1'],
            ['name' => 'Meetings', 'icon' => 'video', 'color' => '#84cc16'],
            ['name' => 'Calls', 'icon' => 'phone', 'color' => '#14b8a6'],
            ['name' => 'Follow-ups', 'icon' => 'refresh-cw', 'color' => '#a855f7'],
            ['name' => 'Birthdays', 'icon' => 'cake', 'color' => '#f43f5e'],
            ['name' => 'Anniversaries', 'icon' => 'gift', 'color' => '#d946ef'],
            ['name' => 'Bills', 'icon' => 'receipt', 'color' => '#eab308'],
            ['name' => 'Documents', 'icon' => 'file-text', 'color' => '#64748b'],
            ['name' => 'Emergency', 'icon' => 'alert-triangle', 'color' => '#dc2626'],
            ['name' => 'Goals', 'icon' => 'target', 'color' => '#22c55e'],
            ['name' => 'Habits', 'icon' => 'repeat', 'color' => '#3b82f6'],
        ];

        foreach ($defaults as $i => $category) {
            Category::withTrashed()->updateOrCreate(
                ['user_id' => null, 'name' => $category['name']],
                array_merge($category, ['sort_order' => $i, 'visibility' => 'shared'])
            );
        }
    }
}
