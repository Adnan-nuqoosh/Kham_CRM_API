<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('rituals', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('title');
            $table->string('title_ar')->nullable();
            $table->text('description')->nullable();
            $table->text('description_ar')->nullable();
            $table->string('image_url', 2048)->nullable();
            $table->boolean('is_published')->default(false)->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
        Schema::create('ritual_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ritual_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('title_ar')->nullable();
            $table->text('instructions');
            $table->text('instructions_ar')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
        Schema::create('ritual_product', function (Blueprint $table) {
            $table->foreignId('ritual_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->primary(['ritual_id', 'product_id']);
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('ritual_product');
        Schema::dropIfExists('ritual_steps');
        Schema::dropIfExists('rituals');
    }
};
