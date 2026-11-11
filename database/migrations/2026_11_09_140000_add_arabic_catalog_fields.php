<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->string('name_ar')->nullable();
            $table->text('short_description_ar')->nullable();
            $table->longText('description_ar')->nullable();
            $table->longText('ingredients_ar')->nullable();
            $table->longText('usage_instructions_ar')->nullable();
            $table->string('seo_title_ar')->nullable();
            $table->text('seo_description_ar')->nullable();
        });
        Schema::table('categories', function (Blueprint $table): void {
            $table->string('name_ar')->nullable();
            $table->text('description_ar')->nullable();
        });
        Schema::table('brands', function (Blueprint $table): void {
            $table->string('name_ar')->nullable();
            $table->text('description_ar')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('brands', fn (Blueprint $table) => $table->dropColumn(['name_ar', 'description_ar']));
        Schema::table('categories', fn (Blueprint $table) => $table->dropColumn(['name_ar', 'description_ar']));
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn([
            'name_ar', 'short_description_ar', 'description_ar', 'ingredients_ar',
            'usage_instructions_ar', 'seo_title_ar', 'seo_description_ar',
        ]));
    }
};
