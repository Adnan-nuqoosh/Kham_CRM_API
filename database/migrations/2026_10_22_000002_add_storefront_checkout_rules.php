<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {
  Schema::create('store_market_rules',function(Blueprint $t){$t->id();$t->foreignId('market_id')->unique()->constrained()->cascadeOnDelete();$t->decimal('shipping_flat',16,4)->default(0);$t->decimal('shipping_free_threshold',16,4)->nullable();$t->decimal('tax_percent',8,4)->default(0);$t->boolean('tax_inclusive')->default(false);$t->boolean('cod_enabled')->default(false);$t->boolean('is_active')->default(false);$t->timestamps();});
  Schema::create('store_order_sequences',function(Blueprint $t){$t->unsignedInteger('year')->primary();$t->unsignedBigInteger('last_number')->default(0);});
  Schema::table('orders',function(Blueprint $t){$t->string('payment_method',30)->nullable();$t->char('checkout_payload_hash',64)->nullable();});
 }
 public function down():void {Schema::table('orders',fn(Blueprint $t)=>$t->dropColumn(['payment_method','checkout_payload_hash']));Schema::dropIfExists('store_order_sequences');Schema::dropIfExists('store_market_rules');}
};
