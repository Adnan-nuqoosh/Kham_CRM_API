<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {
  Schema::create('partner_applications',function(Blueprint $table){
   $table->id();
   $table->string('contact_name',150);
   $table->string('email',255)->index();
   $table->string('phone',50)->nullable();
   $table->string('company_name',200);
   $table->string('website',500)->nullable();
   $table->string('partner_type',30);
   $table->text('message')->nullable();
   $table->string('status',20)->default('pending')->index();
   $table->text('internal_notes')->nullable();
   $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
   $table->timestamp('reviewed_at')->nullable();
   $table->timestamps();
  });
 }
 public function down():void {Schema::dropIfExists('partner_applications');}
};
