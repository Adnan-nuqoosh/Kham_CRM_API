<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('finance_expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('market_id')->constrained('markets')->restrictOnDelete();
            $table->string('currency_code', 3);
            $table->string('category', 80);
            $table->string('description', 500);
            $table->decimal('amount', 18, 4);
            $table->date('incurred_on');
            $table->string('reference', 120)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['market_id', 'incurred_on']);
            $table->index(['currency_code', 'incurred_on']);
        });
        Schema::table('order_items', function (Blueprint $table) {
            $table->decimal('unit_cost_snapshot', 18, 4)->nullable();
        });
    }
    public function down(): void {
        Schema::table('order_items', fn (Blueprint $table) => $table->dropColumn('unit_cost_snapshot'));
        Schema::dropIfExists('finance_expenses');
    }
};
