<?php
/**
 * Local, rollback-only API authorization smoke test.
 * Run: php scripts/local_api_permissions_smoke.php
 * Does not use or print real passwords/tokens.
 */
require dirname(__DIR__) . '/vendor/autoload.php';
$app = require dirname(__DIR__) . '/bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Http\Kernel::class);
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

$host = strtolower((string) config('database.connections.mysql.host', ''));
$db = (string) DB::connection()->getDatabaseName();
if (app()->environment() !== 'local'
    || config('database.default') !== 'mysql'
    || !in_array($host, ['127.0.0.1', 'localhost'], true)
    || $db !== 'kham_crm_api') {
    fwrite(STDERR, "STOP: Only APP_ENV=local, localhost MySQL, kham_crm_api allowed.\n");
    exit(2);
}

$check = static function (bool $ok, string $message): void {
    if (! $ok) throw new RuntimeException("FAIL: {$message}");
    echo "PASS: {$message}\n";
};

$exitCode = 0;
$originalUserCount = DB::table('users')->count();
$originalOrderCount = DB::table('orders')->count();
DB::beginTransaction();
try {
    $user = User::create([
        'name' => 'Local Permission Smoke Test',
        'email' => 'permission-test-' . Str::random(16) . '@example.invalid',
        'password' => Hash::make(Str::random(48)),
    ]);
    Sanctum::actingAs($user, ['*']);

    $send = static function (string $method, string $path) use ($kernel): int {
        $request = Request::create('http://localhost' . $path, $method, [], [], [], [
            'HTTP_ACCEPT' => 'application/json',
        ]);
        $response = $kernel->handle($request);
        $status = $response->getStatusCode();
        $kernel->terminate($request, $response);
        return $status;
    };

    $check($send('GET', '/api/v1/orders') === 403,
        'Authenticated user without orders.view cannot list orders');
    $check($send('GET', '/api/v1/inventory') === 403,
        'Authenticated user without inventory.view cannot list inventory');
    $check($send('POST', '/api/v1/orders') === 403,
        'Authenticated user without orders.manage cannot create orders');
    $check($send('POST', '/api/v1/inventory/adjust') === 403,
        'Authenticated user without inventory.manage cannot adjust stock');

    $user->givePermissionTo('orders.view', 'inventory.view');
    $user->unsetRelation('permissions');

    $check($send('GET', '/api/v1/orders') === 200,
        'View-only user can list orders');
    $check($send('GET', '/api/v1/inventory') === 200,
        'View-only user can list inventory');
    $check($send('POST', '/api/v1/orders') === 403,
        'View-only user still cannot create orders');
    $check($send('POST', '/api/v1/inventory/adjust') === 403,
        'View-only user still cannot adjust stock');

    $check(DB::table('orders')->count() === $originalOrderCount,
        'Denied requests created no orders');
    echo "ALL API PERMISSION TESTS PASSED; rolling back temporary user and permissions.\n";
} catch (Throwable $e) {
    $exitCode = 1;
    fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . "\n");
} finally {
    DB::rollBack();
}
if (DB::table('users')->count() !== $originalUserCount
    || DB::table('orders')->count() !== $originalOrderCount) {
    fwrite(STDERR, "WARNING: User/order counts changed after rollback; investigate.\n");
    $exitCode = 1;
} else {
    echo "PASS: Original user and order counts preserved after rollback\n";
}
exit($exitCode);
