<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('refund_audit_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('refund_request_id')->constrained('order_refund_requests')->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type', 48);
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->timestamp('created_at')->useCurrent();
            $table->index(['refund_request_id', 'id']);
        });
    }
    public function down(): void { Schema::dropIfExists('refund_audit_events'); }
};
