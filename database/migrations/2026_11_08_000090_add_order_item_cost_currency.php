<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            // Legacy costs remain unverified until their currency is confirmed.
            // Never infer a historical currency from today's market or FX rate.
            $table->char('unit_cost_currency_code', 3)->nullable()
                ->after('unit_cost_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropColumn('unit_cost_currency_code');
        });
    }
};
