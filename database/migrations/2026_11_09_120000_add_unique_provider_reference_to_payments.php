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
        $driver = DB::getDriverName();
        if ($driver !== 'mysql' && !($driver === 'sqlite' && app()->environment('testing'))) {
            throw new RuntimeException('Payment uniqueness migration requires MySQL/MariaDB (SQLite only in tests).');
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

        if ($driver === 'mysql') {
            $indexes = DB::select('SHOW INDEX FROM payments');
            foreach ($indexes as $index) {
                if ($index->Key_name === self::INDEX) {
                    return; // Already installed.
                }
            }
        } else {
            $indexes = DB::select("PRAGMA index_list('payments')");
            foreach ($indexes as $index) {
                if ($index->name === self::INDEX) {
                    return;
                }
            }
        }

        Schema::table('payments', function (Blueprint $table): void {
            $table->unique(['provider', 'transaction_reference'], self::INDEX);
        });
    }

    public function down(): void
    {
        $driver = DB::getDriverName();
        if ($driver === 'mysql') {
            $indexes = DB::select('SHOW INDEX FROM payments');
            $exists = collect($indexes)->contains(fn ($index) => $index->Key_name === self::INDEX);
        } elseif ($driver === 'sqlite' && app()->environment('testing')) {
            $indexes = DB::select("PRAGMA index_list('payments')");
            $exists = collect($indexes)->contains(fn ($index) => $index->name === self::INDEX);
        } else {
            throw new RuntimeException('Unsupported payment uniqueness migration driver.');
        }
        if ($exists) {
            Schema::table('payments', function (Blueprint $table): void {
                $table->dropUnique(self::INDEX);
            });
        }
    }
};
