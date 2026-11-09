<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('product_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_item_id')->nullable()->unique()->constrained('order_items')->nullOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->string('title', 150)->nullable();
            $table->text('body')->nullable();
            $table->string('reviewer_name', 120);
            $table->boolean('verified_purchase')->default(false);
            $table->string('status', 20)->default('pending');
            $table->timestamp('moderated_at')->nullable();
            $table->foreignId('moderated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['product_id', 'status', 'created_at']);
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('product_reviews');
    }
};
