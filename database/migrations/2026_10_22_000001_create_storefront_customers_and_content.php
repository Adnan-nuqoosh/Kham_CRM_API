<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  Schema::create('store_customers',function(Blueprint $t){$t->id();$t->string('name');$t->string('email')->unique();$t->string('password');$t->boolean('marketing_opt_in')->default(false);$t->timestamp('email_verified_at')->nullable();$t->rememberToken();$t->timestamps();});
  Schema::create('store_customer_addresses',function(Blueprint $t){$t->id();$t->foreignId('store_customer_id')->constrained()->cascadeOnDelete();$t->string('label')->nullable();$t->string('first_name');$t->string('last_name')->nullable();$t->string('phone');$t->string('line1');$t->string('line2')->nullable();$t->string('city');$t->string('state')->nullable();$t->string('postal_code')->nullable();$t->char('country_code',2);$t->boolean('is_default')->default(false);$t->timestamps();});
  Schema::create('store_wishlists',function(Blueprint $t){$t->id();$t->foreignId('store_customer_id')->constrained()->cascadeOnDelete();$t->foreignId('product_id')->constrained()->cascadeOnDelete();$t->timestamps();$t->unique(['store_customer_id','product_id']);});
  Schema::create('store_ingredients',function(Blueprint $t){$t->id();$t->string('slug')->unique();$t->string('name');$t->string('name_ar')->nullable();$t->text('description')->nullable();$t->text('description_ar')->nullable();$t->string('image_url')->nullable();$t->boolean('is_active')->default(true);$t->timestamps();});
  Schema::create('store_rituals',function(Blueprint $t){$t->id();$t->string('slug')->unique();$t->string('name');$t->string('name_ar')->nullable();$t->text('description')->nullable();$t->text('description_ar')->nullable();$t->string('image_url')->nullable();$t->boolean('is_active')->default(true);$t->timestamps();});
  Schema::create('store_content_blocks',function(Blueprint $t){$t->id();$t->string('key')->unique();$t->string('title')->nullable();$t->string('title_ar')->nullable();$t->text('body')->nullable();$t->text('body_ar')->nullable();$t->string('image_url')->nullable();$t->string('link_url')->nullable();$t->boolean('is_active')->default(true);$t->integer('sort_order')->default(0);$t->timestamps();});
  Schema::table('orders',function(Blueprint $t){$t->foreignId('store_customer_id')->nullable()->after('customer_id')->constrained('store_customers')->nullOnDelete();$t->string('checkout_key',100)->nullable()->unique();});
 }
 public function down():void {Schema::table('orders',function(Blueprint $t){$t->dropConstrainedForeignId('store_customer_id');$t->dropUnique(['checkout_key']);$t->dropColumn('checkout_key');});foreach(['store_content_blocks','store_rituals','store_ingredients','store_wishlists','store_customer_addresses','store_customers'] as $name)Schema::dropIfExists($name);}
};
