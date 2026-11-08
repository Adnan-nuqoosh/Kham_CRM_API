<?php
/**
 * Additional shipping-rule regression tests, using the actual service.
 *
 * Run: php scripts/local_shipping_rules_regression.php
 * Requires local XAMPP database and the shipping_rules migration.
 * All fixture writes are rolled back.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
$app = require dirname(__DIR__) . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Market;
use App\Models\ShippingRule;
use App\Services\ShippingQuoteService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

$host = strtolower((string) config('database.connections.mysql.host', ''));
if (app()->environment() !== 'local'
    || config('database.default') !== 'mysql'
    || !in_array($host, ['localhost', '127.0.0.1'], true)
    || DB::connection()->getDatabaseName() !== 'kham_crm_api') {
    fwrite(STDERR, "STOP: local XAMPP kham_crm_api only.\n");
    exit(2);
}
if (!Schema::hasTable('shipping_rules')) {
    fwrite(STDERR, "STOP: Run php artisan migrate first.\n");
    exit(2);
}
function shippingAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    echo 'PASS: ' . $message . PHP_EOL;
}
$originalCount = ShippingRule::count();
$exitCode = 0;

DB::beginTransaction();
try {
    $market = Market::with('currency')->where('code', 'AE')->firstOrFail();
    $service = app(ShippingQuoteService::class);

    // Existing configuration remains untouched: temporarily deactivate it
    // inside the rollback-only transaction to isolate test fixtures.
    ShippingRule::where('market_id', $market->id)->update(['is_active' => false]);
    $fallback = ShippingRule::updateOrCreate(
        ['market_id' => $market->id, 'city_key' => '*'],
        ['shipping_fee' => 25, 'free_shipping_threshold' => null, 'is_active' => true]
    );
    $dubai = ShippingRule::updateOrCreate(
        ['market_id' => $market->id, 'city_key' => 'dubai'],
        ['shipping_fee' => 15, 'free_shipping_threshold' => 200, 'is_active' => true]
    );

    $quote = $service->quote($market, 'AE', ' Dubai ', 100);
    shippingAssert($quote['rule_id'] === $dubai->id
        && (float) $quote['shipping_total'] === 15.0,
        'Specific city rule takes priority over market-wide fallback');

    $quote = $service->quote($market, 'AE', 'Abu Dhabi', 100);
    shippingAssert($quote['rule_id'] === $fallback->id
        && (float) $quote['shipping_total'] === 25.0,
        'Fallback rule covers other cities within the same market');

    $quote = $service->quote($market, 'AE', 'Dubai', 200);
    shippingAssert((float) $quote['shipping_total'] === 0.0
        && $quote['free_shipping_applied'] === true,
        'Free shipping applies exactly at the threshold');

    $dubai->update(['shipping_fee' => 12, 'free_shipping_threshold' => null]);
    $quote = $service->quote($market, 'AE', 'DUBAI', 250);
    shippingAssert((float) $quote['shipping_total'] === 12.0
        && $quote['free_shipping_applied'] === false,
        'Updated CRM fee is used immediately and null threshold disables free shipping');

    $dubai->update(['is_active' => false]);
    $quote = $service->quote($market, 'AE', 'Dubai', 100);
    shippingAssert($quote['rule_id'] === $fallback->id,
        'Inactive city rule falls back to active market-wide rule');

    $fallback->update(['is_active' => false]);
    try {
        $service->quote($market, 'AE', 'Dubai', 100);
        throw new RuntimeException('FAIL: Unconfigured destination was accepted.');
    } catch (ValidationException $e) {
        shippingAssert(isset($e->errors()['shipping_address.city']),
            'Shipping quote fails closed without an active rule');
    }

    try {
        $service->quote($market, 'SA', 'Dubai', 100);
        throw new RuntimeException('FAIL: Wrong country was accepted.');
    } catch (ValidationException $e) {
        shippingAssert(isset($e->errors()['shipping_address.country_code']),
            'Destination country must match the sales market');
    }

    echo "ALL LOCAL SHIPPING RULE REGRESSION TESTS PASSED; rolling back fixtures.\n";
} catch (Throwable $e) {
    $exitCode = 1;
    fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . PHP_EOL);
} finally {
    DB::rollBack();
}
if (ShippingRule::count() !== $originalCount) {
    fwrite(STDERR, "FAIL: Shipping-rule count changed after rollback.\n");
    $exitCode = 1;
} else {
    echo "PASS: Original shipping-rule count preserved.\n";
}
exit($exitCode);
