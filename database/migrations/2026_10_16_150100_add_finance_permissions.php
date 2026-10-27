<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration {
    public function up(): void
    {
        foreach (['finance.view', 'finance.manage'] as $name) {
            Permission::findOrCreate($name, 'web');
        }
        $admin = Role::query()->where('name', 'Super Admin')->where('guard_name', 'web')->first();
        if ($admin) $admin->givePermissionTo(['finance.view', 'finance.manage']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $admin = Role::query()->where('name', 'Super Admin')->where('guard_name', 'web')->first();
        if ($admin) $admin->revokePermissionTo(['finance.view', 'finance.manage']);
        Permission::query()->where('guard_name', 'web')->whereIn('name', ['finance.view', 'finance.manage'])->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
