<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('refund_provider_evidence', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('refund_request_id')->constrained('order_refund_requests')->restrictOnDelete();
            $table->string('provider', 64);
            $table->string('provider_refund_reference', 191);
            $table->decimal('amount', 18, 4);
            $table->string('currency_code', 8);
            $table->string('verification_status', 32)->default('unverified');
            $table->string('verification_source', 64)->default('pending_provider_integration');
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['provider', 'provider_refund_reference'], 'refund_provider_reference_unique');
            $table->unique('refund_request_id', 'refund_provider_one_evidence_per_request');
        });
    }
    public function down(): void { Schema::dropIfExists('refund_provider_evidence'); }
};
