<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {
  Schema::create('newsletter_subscribers',function(Blueprint $table){
   $table->id();
   $table->string('email')->unique();
   $table->string('status',20)->default('subscribed')->index();
   $table->timestamp('subscribed_at')->nullable();
   $table->timestamp('unsubscribed_at')->nullable();
   $table->string('unsubscribe_token',64)->unique();
   $table->timestamps();
  });
 }
 public function down():void {Schema::dropIfExists('newsletter_subscribers');}
};
