<?php
/**
 * Read-only MyFatoorah invoice/index preflight.
 * Run: php scripts/local_myfatoorah_invoice_uniqueness_audit.php
 * Does not write, migrate, reset or contact any payment provider.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
$app = require dirname(__DIR__) . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$host = strtolower((string) config('database.connections.mysql.host', ''));
if (!app()->environment('local')
    || config('database.default') !== 'mysql'
    || !in_array($host, ['localhost', '127.0.0.1'], true)
    || DB::connection()->getDatabaseName() !== 'kham_crm_api') {
    fwrite(STDERR, "STOP: Requires APP_ENV=local and local MySQL kham_crm_api.\n");
    exit(2);
}

try {
    $rows = DB::select(
        "SELECT transaction_reference, COUNT(*) AS copies, COUNT(DISTINCT order_id) AS orders
         FROM payments
         WHERE provider = ? AND transaction_reference IS NOT NULL
         GROUP BY transaction_reference HAVING COUNT(*) > 1
         ORDER BY copies DESC LIMIT 20",
        ['myfatoorah']
    );
    $total = (int) DB::table('payments')->where('provider', 'myfatoorah')->count();
    $blank = (int) DB::table('payments')->where('provider', 'myfatoorah')
        ->where(function ($q) {
            $q->whereNull('transaction_reference')->orWhere('transaction_reference', '');
        })->count();
    $indexes = DB::select("SHOW INDEX FROM payments");
    $columns = DB::select("SHOW COLUMNS FROM payments");

    echo "MyFatoorah payment rows: {$total}\n";
    echo "Rows with missing/blank invoice reference: {$blank}\n";
    echo "Duplicate non-null provider invoice groups: " . count($rows) . " (showing up to 20)\n";
    foreach ($rows as $row) {
        echo "  invoice=" . (string) $row->transaction_reference
            . " copies=" . (int) $row->copies
            . " orders=" . (int) $row->orders . "\n";
    }
    echo "Payments index definitions:\n";
    foreach ($indexes as $index) {
        echo "  " . $index->Key_name . " column=" . $index->Column_name
            . " unique=" . ((int) $index->Non_unique === 0 ? 'yes' : 'no')
            . " seq=" . $index->Seq_in_index . "\n";
    }
    echo "Relevant payment columns:\n";
    foreach ($columns as $column) {
        if (in_array($column->Field, ['id', 'order_id', 'provider', 'transaction_reference'], true)) {
            echo "  " . $column->Field . " " . $column->Type
                . " nullable=" . $column->Null . "\n";
        }
    }
    echo "READ-ONLY AUDIT COMPLETE; no changes made.\n";
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
