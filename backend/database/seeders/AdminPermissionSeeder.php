<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class AdminPermissionSeeder extends Seeder
{
    /**
     * Seed admin roles and permission names used by the Admin Console.
     */
    public function run(): void
    {
        $permissions = [
            'admin.users.view',
            'admin.users.manage',
            'admin.campaigns.view',
            'admin.campaigns.manage',
            'admin.creatives.review',
            'admin.analytics.view',
            'admin.finance.view',
            'admin.finance.manage',
            'admin.plugins.view',
            'admin.plugins.manage',
            'admin.rate-tiers.manage',
        ];

        foreach ($permissions as $permission) {
            Permission::query()->firstOrCreate([
                'name' => $permission,
                'guard_name' => 'web',
            ]);
        }

        Role::query()
            ->firstOrCreate(['name' => 'admin-owner', 'guard_name' => 'web'])
            ->syncPermissions($permissions);

        Role::query()
            ->firstOrCreate(['name' => 'ad-ops', 'guard_name' => 'web'])
            ->syncPermissions([
                'admin.campaigns.view',
                'admin.campaigns.manage',
                'admin.creatives.review',
                'admin.analytics.view',
            ]);

        Role::query()
            ->firstOrCreate(['name' => 'finance', 'guard_name' => 'web'])
            ->syncPermissions([
                'admin.analytics.view',
                'admin.finance.view',
                'admin.finance.manage',
            ]);

        Role::query()
            ->firstOrCreate(['name' => 'support', 'guard_name' => 'web'])
            ->syncPermissions([
                'admin.users.view',
                'admin.campaigns.view',
                'admin.plugins.view',
            ]);
    }
}
