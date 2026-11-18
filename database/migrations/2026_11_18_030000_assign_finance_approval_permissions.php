<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration {
    public function up(): void
    {
        $names=['finance.view','finance.manage','refunds.approve','tax.approve'];
        foreach($names as $name) Permission::findOrCreate($name,'web');
        $admin=Role::where('name','Super Admin')->where('guard_name','web')->first();
        if($admin) $admin->givePermissionTo($names);
        // Dedicated approver role: do not elevate an existing general Finance team.
        // No users are assigned automatically.
        $finance=Role::firstOrCreate(['name'=>'Finance Approver','guard_name'=>'web']);
        $finance->givePermissionTo($names);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
    public function down(): void
    {
        // Keep assigned operational permissions intact on rollback.
    }
};
