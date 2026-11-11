<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('shipping_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('market_id')->constrained('markets')->cascadeOnDelete();
            // "*" is a market-wide fallback; otherwise normalized lowercase city.
            $table->string('city_key', 120);
            $table->decimal('shipping_fee', 16, 4);
            $table->decimal('free_shipping_threshold', 16, 4)->nullable();
            // Fail closed: no shipping is enabled until management approves a rate.
            $table->boolean('is_active')->default(false);
            $table->timestamps();
            $table->unique(['market_id', 'city_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipping_rules');
    }
};
