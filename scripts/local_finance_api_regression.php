<?php
/**
 * Finance API authorization and expense lifecycle regression.
 *
 * Run: php scripts/local_finance_api_regression.php
 * Only operates on the dedicated local XAMPP database and rolls back fixtures.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
$app = require dirname(__DIR__) . '/bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Http\Kernel::class);
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

$host = strtolower((string) config('database.connections.mysql.host', ''));
$dbName = (string) DB::connection()->getDatabaseName();
if (app()->environment() !== 'local'
    || config('database.default') !== 'mysql'
    || !in_array($host, ['localhost', '127.0.0.1'], true)
    || $dbName !== 'kham_crm_api') {
    fwrite(STDERR, "STOP: Requires APP_ENV=local and localhost MySQL database kham_crm_api.\n");
    exit(2);
}

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    echo 'PASS: ' . $message . PHP_EOL;
};

$send = static function (string $method, string $path, ?array $body = null) use ($kernel): array {
    $request = Request::create('http://localhost' . $path, $method, [], [], [], [
        'HTTP_ACCEPT' => 'application/json',
        'CONTENT_TYPE' => 'application/json',
    ], $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR));
    $response = $kernel->handle($request);
    try {
        $content = $response->getContent();
        return [
            $response->getStatusCode(),
            is_string($content) ? json_decode($content, true) : null,
            $response->headers->get('Content-Type'),
        ];
    } finally {
        $kernel->terminate($request, $response);
    }
};

$market = DB::table('markets')->where('code', 'AE')->where('is_active', 1)->first();
if (!$market) {
    fwrite(STDERR, "STOP: Active AE market not found.\n");
    exit(2);
}
$before = [
    'users' => DB::table('users')->count(),
    'expenses' => DB::table('finance_expenses')->count(),
    'orders' => DB::table('orders')->count(),
    'order_items' => DB::table('order_items')->count(),
];
$exitCode = 0;

DB::beginTransaction();
try {
    $user = User::create([
        'name' => 'Local Finance Regression',
        'email' => 'finance-regression-' . Str::random(16) . '@example.invalid',
        'password' => Str::random(48),
    ]);
    Sanctum::actingAs($user, ['*']);

    // Use a real persisted order item: Laravel resolves route model bindings
    // before the permission middleware, so a nonexistent ID returns 404.
    $warehouseId = DB::table('warehouses')->where('code', 'EG-01')->value('id');
    $assert($warehouseId !== null, 'Egypt warehouse available for test fixture');
    $orderId = DB::table('orders')->insertGetId([
        'order_number' => 'FIN-QA-' . Str::upper(Str::random(18)),
        'market_id' => $market->id,
        'warehouse_id' => $warehouseId,
        'currency_code' => 'AED',
        'status' => 'pending',
        'payment_status' => 'pending',
        'fulfillment_status' => 'unfulfilled',
        'subtotal' => 1,
        'discount_total' => 0,
        'shipping_total' => 0,
        'tax_total' => 0,
        'grand_total' => 1,
        'placed_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $itemId = DB::table('order_items')->insertGetId([
        'order_id' => $orderId,
        'sku' => 'FINANCE-QA',
        'name' => 'Temporary finance permission fixture',
        'quantity' => 1,
        'unit_price' => 1,
        'line_total' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $costPath = '/api/v1/finance/order-items/' . $itemId . '/cost';

    $reportPath = '/api/v1/finance/report?period=daily&market_id=' . $market->id;
    $expensePath = '/api/v1/finance/expenses';

    $assert($send('GET', $reportPath)[0] === 403,
        'User without finance.view cannot access finance report');
    $assert($send('GET', $expensePath)[0] === 403,
        'User without finance.view cannot list expenses');
    $assert($send('POST', $expensePath, [])[0] === 403,
        'User without finance.manage cannot create expenses');

    $user->givePermissionTo('finance.view');
    $user->unsetRelation('permissions');
    $assert($send('GET', $reportPath)[0] === 200,
        'View-only user can access finance report');
    $assert($send('GET', $expensePath)[0] === 200,
        'View-only user can list expenses');
    $assert($send('POST', $expensePath, [])[0] === 403,
        'View-only user cannot create expenses');
    $assert($send('PATCH', $costPath, ['unit_cost_snapshot' => 1])[0] === 403,
        'View-only user cannot modify existing item cost');
    $assert(DB::table('order_items')->where('id', $itemId)->value('unit_cost_snapshot') === null,
        'Denied cost update did not mutate the item');

    $user->givePermissionTo('finance.manage');
    $user->unsetRelation('permissions');

    $assert($send('PATCH', $costPath, ['unit_cost_snapshot' => -1])[0] === 422,
        'Negative item cost rejected');
    [$costStatus, $costResult] = $send('PATCH', $costPath, ['unit_cost_snapshot' => 0.50]);
    $assert($costStatus === 200 && (float) ($costResult['data']['unit_cost_snapshot'] ?? -1) === 0.50,
        'Finance manager can update existing item cost');

    $assert($send('GET', '/api/v1/finance/report')[0] === 422,
        'Finance report requires market filter');
    $assert($send('GET', '/api/v1/finance/report?period=custom&market_id=' . $market->id
        . '&from=2026-11-08&to=2026-11-07')[0] === 422,
        'Finance report rejects reversed custom dates');
    $assert($send('POST', $expensePath, [
        'market_id' => $market->id,
        'category' => 'Test',
        'description' => 'Invalid negative amount',
        'amount' => -1,
        'incurred_on' => now()->toDateString(),
    ])[0] === 422, 'Negative expense amount rejected');

    [$status, $created] = $send('POST', $expensePath, [
        'market_id' => $market->id,
        'category' => 'QA',
        'description' => 'Rollback-only finance fixture',
        'amount' => 15.50,
        'incurred_on' => now()->toDateString(),
        'reference' => 'LOCAL-TEST',
    ]);
    $expenseId = (int) ($created['data']['id'] ?? 0);
    $assert($status === 201 && $expenseId > 0,
        'Finance manager can create expense');
    $assert(($created['data']['currency_code'] ?? '') === 'AED',
        'Expense currency derived from market rather than client');
    $assert((int) ($created['data']['created_by'] ?? 0) === (int) $user->id,
        'Expense creator recorded from authenticated user');

    [$status, $updated] = $send('PATCH', $expensePath . '/' . $expenseId, [
        'amount' => 18.75,
    ]);
    $assert($status === 200 && (float) ($updated['data']['amount'] ?? 0) === 18.75,
        'Finance manager can update expense');

    [$status, $report] = $send('GET', $reportPath);
    $assert($status === 200 && ($report['data']['currency_code'] ?? '') === 'AED',
        'Daily report returns market-local currency');
    $assert((float) ($report['data']['operating_expenses'] ?? 0) >= 18.75,
        'Daily report includes new expense');

    [$status, , $type] = $send('GET',
        '/api/v1/finance/report/export.csv?period=daily&market_id=' . $market->id);
    $assert($status === 200 && str_contains((string) $type, 'text/csv'),
        'Finance CSV export returns successful CSV response');

    [$status] = $send('DELETE', $expensePath . '/' . $expenseId);
    $assert($status === 200
        && DB::table('finance_expenses')->where('id', $expenseId)->whereNotNull('deleted_at')->exists(),
        'Finance expense archive uses soft delete');

    echo "ALL LOCAL FINANCE API REGRESSION TESTS PASSED; rolling back fixtures.\n";
} catch (Throwable $e) {
    $exitCode = 1;
    fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . PHP_EOL);
} finally {
    DB::rollBack();
}

$after = [
    'users' => DB::table('users')->count(),
    'expenses' => DB::table('finance_expenses')->count(),
    'orders' => DB::table('orders')->count(),
];
if ($after !== $before) {
    fwrite(STDERR, "FAIL: User, expense or order counts changed after rollback.\n");
    $exitCode = 1;
} else {
    echo "PASS: Original users, expenses and orders preserved after rollback\n";
}
exit($exitCode);
