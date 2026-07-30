<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            ['slug' => 'super_admin', 'name' => 'Super Admin', 'description' => 'Full system access', 'is_system' => true],
            ['slug' => 'admin', 'name' => 'Admin', 'description' => 'Manages assigned users and teams', 'is_system' => true],
            ['slug' => 'subadmin', 'name' => 'Subadmin', 'description' => 'Limited admin access as permitted', 'is_system' => true],
            ['slug' => 'user', 'name' => 'Standard User', 'description' => 'Regular platform user', 'is_system' => true],
            ['slug' => 'salesperson', 'name' => 'Salesperson', 'description' => 'Staff member with Internal Work access', 'is_system' => true],
        ];

        foreach ($roles as $role) {
            Role::updateOrCreate(['slug' => $role['slug']], $role);
        }

        $permissions = [
            'users' => ['users.view', 'users.create', 'users.update', 'users.suspend', 'users.roles'],
            'tasks' => ['tasks.view_all', 'tasks.assign', 'tasks.manage_all'],
            'categories' => ['categories.manage_shared'],
            'admin' => ['admin.access', 'admin.stats', 'admin.login_histories'],
            'system' => ['system.settings', 'system.audit_logs', 'system.app_ids'],
        ];

        foreach ($permissions as $module => $slugs) {
            foreach ($slugs as $slug) {
                Permission::updateOrCreate(
                    ['slug' => $slug],
                    ['name' => ucwords(str_replace(['.', '_'], ' ', $slug)), 'module' => $module]
                );
            }
        }

        // super_admin bypasses checks in code; grant admin a working subset.
        $admin = Role::where('slug', 'admin')->first();
        $admin->permissions()->sync(
            Permission::whereIn('slug', [
                'users.view', 'users.create', 'users.update', 'users.suspend',
                'tasks.view_all', 'tasks.assign', 'categories.manage_shared',
                'admin.access', 'admin.stats', 'admin.login_histories',
            ])->pluck('id')
        );

        $subadmin = Role::where('slug', 'subadmin')->first();
        $subadmin->permissions()->sync(
            Permission::whereIn('slug', ['users.view', 'admin.access'])->pluck('id')
        );
    }
}
