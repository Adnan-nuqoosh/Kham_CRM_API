<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {
  Schema::create('homepage_sections',function(Blueprint $table){
   $table->id();
   $table->string('key',100)->unique();
   $table->string('type',30)->index();
   $table->string('title_en',255);
   $table->string('title_ar',255)->nullable();
   $table->text('subtitle_en')->nullable();
   $table->text('subtitle_ar')->nullable();
   $table->string('image_url',1000)->nullable();
   $table->string('link_url',1000)->nullable();
   $table->json('content')->nullable();
   $table->unsignedInteger('sort_order')->default(0)->index();
   $table->boolean('is_published')->default(false)->index();
   $table->timestamps();
  });
 }
 public function down():void {Schema::dropIfExists('homepage_sections');}
};
