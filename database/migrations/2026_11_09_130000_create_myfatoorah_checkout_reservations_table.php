<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Durable hosted checkout reservation. Separate from payments so an unknown
 * provider response cannot be mistaken for a confirmed payment invoice.
 * Additive only; existing payments and orders are not modified.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('myfatoorah_checkout_reservations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->char('idempotency_key_hash', 64);
            $table->string('status', 24)->default('reserved');
            $table->decimal('amount', 16, 4);
            $table->char('currency_code', 3);
            $table->string('payment_method', 32)->nullable();
            $table->string('invoice_id', 64)->nullable();
            $table->text('payment_url')->nullable();
            $table->timestamp('provider_requested_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            // Only one active or historical initiation reservation per order.
            // A failed/unknown provider outcome must not permit a new key.
            $table->unique('order_id', 'myfatoorah_reservation_order_unique');
            $table->unique('idempotency_key_hash', 'myfatoorah_reservation_key_unique');
            $table->unique('invoice_id', 'myfatoorah_reservation_invoice_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('myfatoorah_checkout_reservations');
    }
};
