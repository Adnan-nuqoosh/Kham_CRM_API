<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;

return new class extends Migration {
    public function up(): void
    {
        Permission::firstOrCreate(['name' => 'refunds.approve', 'guard_name' => 'web']);
    }
    public function down(): void
    {
        // Keep assigned permissions intact on rollback.
    }
};
