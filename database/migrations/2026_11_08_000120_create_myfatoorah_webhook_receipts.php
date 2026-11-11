<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('myfatoorah_webhook_receipts', function (Blueprint $table): void {
            $table->id();
            $table->string('provider_payment_id', 128);
            $table->string('provider_invoice_id', 128);
            $table->string('external_identifier', 128);
            $table->char('event_hash', 64)->unique();
            $table->string('event_reference', 128)->nullable();
            $table->string('result', 32)->default('received');
            $table->timestamps();
            $table->index(['provider_payment_id', 'result'], 'myfatoorah_receipts_payment_result_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('myfatoorah_webhook_receipts');
    }
};
