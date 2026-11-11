<?php
/**
 * Read-only preflight for the provider/reference unique-index migration.
 * No DDL, DML, gateway calls or migrations are performed.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
$app = require dirname(__DIR__) . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$host = strtolower((string) config('database.connections.mysql.host', ''));
if (!app()->environment('local') || config('database.default') !== 'mysql'
    || !in_array($host, ['localhost', '127.0.0.1'], true)
    || DB::connection()->getDatabaseName() !== 'kham_crm_api') {
    fwrite(STDERR, "STOP: Requires APP_ENV=local and local MySQL kham_crm_api.\n");
    exit(2);
}

try {
    $duplicates = DB::table('payments')
        ->select('provider', 'transaction_reference')
        ->selectRaw('COUNT(*) AS copies')
        ->whereNotNull('provider')->whereNotNull('transaction_reference')
        ->groupBy('provider', 'transaction_reference')
        ->havingRaw('COUNT(*) > 1')
        ->limit(20)->get();
    $nulls = DB::table('payments')->whereNull('provider')
        ->orWhereNull('transaction_reference')->count();
    $total = DB::table('payments')->count();

    echo "Total existing payments: {$total}\n";
    echo "Rows with nullable provider/reference: {$nulls} (allowed by composite unique index)\n";
    echo "Duplicate provider/reference groups: {$duplicates->count()} (up to 20)\n";
    foreach ($duplicates as $item) {
        echo "  provider={$item->provider} ref={$item->transaction_reference} count={$item->copies}\n";
    }
    echo "READ-ONLY PREFLIGHT COMPLETE. No database changes made.\n";
    if ($duplicates->isNotEmpty()) {
        fwrite(STDERR, "BLOCKED: Existing duplicates must be reviewed before applying migration.\n");
        exit(1);
    }
    echo "PASS: No existing duplicate non-null provider/reference pairs.\n";
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
