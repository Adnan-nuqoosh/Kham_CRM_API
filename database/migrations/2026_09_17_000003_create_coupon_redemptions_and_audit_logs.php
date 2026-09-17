<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {
  Schema::create('coupon_redemptions',function(Blueprint $t){$t->id();$t->foreignId('coupon_id')->constrained()->cascadeOnDelete();$t->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();$t->foreignId('order_id')->constrained()->cascadeOnDelete();$t->decimal('discount_amount',16,4);$t->timestamps();});
  Schema::create('audit_logs',function(Blueprint $t){$t->id();$t->unsignedBigInteger('user_id')->nullable();$t->string('action',80);$t->string('auditable_type');$t->unsignedBigInteger('auditable_id');$t->json('old_values')->nullable();$t->json('new_values')->nullable();$t->string('ip_address',45)->nullable();$t->text('user_agent')->nullable();$t->timestamp('created_at')->useCurrent();$t->index(['auditable_type','auditable_id']);});
 }
 public function down():void {Schema::dropIfExists('audit_logs');Schema::dropIfExists('coupon_redemptions');}
};
