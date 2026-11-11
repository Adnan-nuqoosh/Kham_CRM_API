<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

/**
 * Additive protection: provider + transaction reference must identify one payment.
 *
 * This deliberately protects all providers, not only MyFatoorah. Refuse to
 * migrate if legacy data violates the invariant. No rows are changed.
 * Run the read-only invoice audit and take a database backup first.
 */
return new class extends Migration {
    private const INDEX = 'payments_provider_transaction_reference_unique';

    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            throw new RuntimeException('Payment uniqueness migration requires MySQL/MariaDB.');
        }

        $duplicates = DB::table('payments')
            ->select('provider', 'transaction_reference')
            ->selectRaw('COUNT(*) AS copies')
            ->whereNotNull('provider')
            ->whereNotNull('transaction_reference')
            ->groupBy('provider', 'transaction_reference')
            ->havingRaw('COUNT(*) > 1')
            ->limit(1)
            ->first();

        if ($duplicates !== null) {
            throw new RuntimeException(
                'Payment uniqueness migration blocked: duplicate provider/reference rows exist. ' .
                'No payment records were modified.'
            );
        }

        $indexes = DB::select('SHOW INDEX FROM payments');
        foreach ($indexes as $index) {
            if ($index->Key_name === self::INDEX) {
                return; // Already installed.
            }
        }

        Schema::table('payments', function (Blueprint $table): void {
            $table->unique(['provider', 'transaction_reference'], self::INDEX);
        });
    }

    public function down(): void
    {
        $indexes = DB::select('SHOW INDEX FROM payments');
        foreach ($indexes as $index) {
            if ($index->Key_name === self::INDEX) {
                Schema::table('payments', function (Blueprint $table): void {
                    $table->dropUnique(self::INDEX);
                });
                return;
            }
        }
    }
};
