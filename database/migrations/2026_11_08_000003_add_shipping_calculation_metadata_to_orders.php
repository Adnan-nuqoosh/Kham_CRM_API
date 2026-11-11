<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('shipping_mode', 20)->default('manual');
            $table->foreignId('shipping_rule_id')->nullable()
                ->constrained('shipping_rules')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('shipping_rule_id');
            $table->dropColumn('shipping_mode');
        });
    }
};
