<?php
/**
 * Local-only order hardening regression. Invalid orders must never be created.
 * No test data is persisted. Run: php scripts/local_order_hardening_regression.php
 */
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
$app = require dirname(__DIR__) . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Market;
use App\Models\ProductVariant;
use App\Services\OrderService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

$host = strtolower((string) config('database.connections.mysql.host', ''));
if (!app()->environment('local') || config('database.default') !== 'mysql'
    || !in_array($host, ['localhost', '127.0.0.1'], true)
    || DB::connection()->getDatabaseName() !== 'kham_crm_api') {
    fwrite(STDERR, "STOP: Requires local MySQL kham_crm_api.\n"); exit(2);
}
$assert = static function (bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException("FAIL: {$label}");
    echo "PASS: {$label}\n";
};
$market = Market::query()->where('is_active', true)->first();
$variant = ProductVariant::query()->with('product')->first();
if (!$market || !$variant) {
    fwrite(STDERR, "STOP: Existing market and variant fixtures are required.\n"); exit(2);
}
$base = [
    'market_id' => $market->id,
    'items' => [['variant_id' => $variant->id, 'quantity' => 1]],
];
$service = $app->make(OrderService::class);
$cases = [
    'duplicate variant rejected' => array_replace($base, [
        'items' => [
            ['variant_id' => $variant->id, 'quantity' => 1],
            ['variant_id' => $variant->id, 'quantity' => 1],
        ],
    ]),
    'zero quantity rejected' => array_replace($base, [
        'items' => [['variant_id' => $variant->id, 'quantity' => 0]],
    ]),
    'excessive quantity rejected' => array_replace($base, [
        'items' => [['variant_id' => $variant->id, 'quantity' => 10001]],
    ]),
    'empty order rejected' => array_replace($base, ['items' => []]),
];
foreach ($cases as $label => $payload) {
    DB::beginTransaction();
    try {
        try {
            $service->create($payload);
            throw new RuntimeException("FAIL: {$label} was accepted");
        } catch (ValidationException $e) {
            $assert(true, $label);
        }
    } finally {
        DB::rollBack();
    }
}
DB::beginTransaction();
try {
    $market->update(['is_active' => false]);
    try {
        $service->create($base);
        throw new RuntimeException('FAIL: inactive market was accepted');
    } catch (ValidationException $e) {
        $assert(true, 'inactive market rejected');
    }
} finally {
    DB::rollBack();
}
DB::beginTransaction();
try {
    $variant->update(['is_active' => false]);
    try {
        $service->create($base);
        throw new RuntimeException('FAIL: inactive variant was accepted');
    } catch (ValidationException $e) {
        $assert(true, 'inactive variant rejected');
    }
} finally {
    DB::rollBack();
}
echo "ALL ORDER HARDENING CHECKS PASSED (rolled back)\n";
