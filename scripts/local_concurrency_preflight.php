<?php
/**
 * Read-only concurrency preflight for local XAMPP database.
 * Usage: php scripts/local_concurrency_preflight.php
 * No writes, no migrations, no user credentials printed.
 */
require dirname(__DIR__) . '/vendor/autoload.php';
$app = require dirname(__DIR__) . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$host = strtolower((string) config('database.connections.mysql.host', ''));
$dbName = (string) DB::connection()->getDatabaseName();
if (app()->environment() !== 'local'
    || config('database.default') !== 'mysql'
    || !in_array($host, ['127.0.0.1', 'localhost'], true)
    || $dbName !== 'kham_crm_api') {
    fwrite(STDERR, "STOP: Only local MySQL database kham_crm_api with APP_ENV=local is allowed.\n");
    exit(2);
}

$failed = false;
$check = static function (bool $condition, string $label) use (&$failed): void {
    echo ($condition ? 'PASS: ' : 'FAIL: ') . $label . PHP_EOL;
    if (! $condition) $failed = true;
};

$tables = DB::table('information_schema.TABLES')
    ->where('TABLE_SCHEMA', $dbName)
    ->whereIn('TABLE_NAME', ['orders', 'inventory_stocks', 'inventory_movements'])
    ->pluck('ENGINE', 'TABLE_NAME');

foreach (['orders', 'inventory_stocks', 'inventory_movements'] as $table) {
    $check(strcasecmp((string) ($tables[$table] ?? ''), 'InnoDB') === 0,
        "{$table} uses InnoDB transactional row locking");
}

$indexes = DB::table('information_schema.STATISTICS')
    ->where('TABLE_SCHEMA', $dbName)
    ->whereIn('TABLE_NAME', ['orders', 'inventory_stocks'])
    ->where('NON_UNIQUE', 0)
    ->orderBy('SEQ_IN_INDEX')
    ->get(['TABLE_NAME', 'INDEX_NAME', 'COLUMN_NAME'])
    ->groupBy(fn ($row) => $row->TABLE_NAME . '/' . $row->INDEX_NAME)
    ->map(fn ($rows) => $rows->pluck('COLUMN_NAME')->all());

$hasUnique = static function (string $table, array $columns) use ($indexes): bool {
    foreach ($indexes as $name => $actual) {
        if (str_starts_with($name, $table . '/') && $actual === $columns) return true;
    }
    return false;
};

$check($hasUnique('orders', ['order_number']),
    'orders.order_number has unique index');
$check($hasUnique('inventory_stocks', ['variant_id', 'warehouse_id']),
    'inventory_stocks has unique variant+warehouse index');

try {
    $isolation = DB::selectOne('SELECT @@transaction_isolation AS level')->level;
} catch (Throwable $e) {
    $isolation = DB::selectOne('SELECT @@tx_isolation AS level')->level;
}
echo 'INFO: Transaction isolation: ' . $isolation . PHP_EOL;
echo "WARNING: OrderService::nextNumber() currently uses MAX()+1; the unique index prevents duplicates being stored but simultaneous orders may fail with a duplicate-key error. A transactional sequence or retry strategy is needed before production.\n";
echo "WARNING: This is a read-only preflight, NOT a two-process concurrency test.\n";
exit($failed ? 1 : 0);
