<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ritual_quiz_questions', function (Blueprint $table) {
            $table->id();
            $table->string('question');
            $table->string('question_ar')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('ritual_quiz_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_id')->constrained('ritual_quiz_questions')->cascadeOnDelete();
            $table->string('label');
            $table->string('label_ar')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('ritual_quiz_option_product', function (Blueprint $table) {
            $table->foreignId('option_id')->constrained('ritual_quiz_options')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->unsignedSmallInteger('weight')->default(1);
            $table->primary(['option_id', 'product_id']);
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('ritual_quiz_option_product');
        Schema::dropIfExists('ritual_quiz_options');
        Schema::dropIfExists('ritual_quiz_questions');
    }
};
