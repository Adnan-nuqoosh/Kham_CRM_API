<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('market_tax_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('market_id')->unique()->constrained('markets')->restrictOnDelete();
            $table->decimal('rate_percent', 7, 4)->default(0);
            $table->string('calculation_method', 24)->default('exclusive');
            $table->boolean('tax_shipping')->default(false);
            $table->boolean('is_approved')->default(false);
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_tax_rules');
    }
};
