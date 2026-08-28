<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    /**
     * Seed roles and permissions.
     */
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = \App\Support\PermissionCatalog::allPermissionNames();

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $admin = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $manager = Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        $viewer = Role::firstOrCreate(['name' => 'viewer', 'guard_name' => 'web']);

        $admin->syncPermissions($permissions);

        $managerPerms = [];
        foreach (['orders', 'products', 'customers', 'shipments', 'invoices', 'alerts', 'messages', 'reports', 'sync', 'logs'] as $module) {
            foreach (['view', 'create', 'update'] as $action) {
                $name = $module.'.'.$action;
                if (in_array($name, $permissions, true)) {
                    $managerPerms[] = $name;
                }
            }
        }
        $manager->syncPermissions($managerPerms);

        $viewerPerms = [];
        foreach (['orders', 'products', 'customers', 'shipments', 'invoices', 'alerts', 'reports', 'sync'] as $module) {
            $name = $module.'.view';
            if (in_array($name, $permissions, true)) {
                $viewerPerms[] = $name;
            }
        }
        $viewer->syncPermissions($viewerPerms);
    }
}
