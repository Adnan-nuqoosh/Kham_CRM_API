<?php
/**
 * HTTP middleware security regression for sandbox provider enquiry.
 * Local MySQL only. All fixtures rolled back; no real provider requests.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
$app = require dirname(__DIR__) . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

$host = strtolower((string) config('database.connections.mysql.host', ''));
if (!app()->environment('local') || config('database.default') !== 'mysql'
    || !in_array($host, ['127.0.0.1', 'localhost'], true)
    || DB::connection()->getDatabaseName() !== 'kham_crm_api') {
    fwrite(STDERR, "STOP: Requires APP_ENV=local and local MySQL kham_crm_api.\n");
    exit(2);
}
$assert = static function (bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException('FAIL: ' . $label);
    echo 'PASS: ' . $label . PHP_EOL;
};
$original = [
    'sandbox' => config('commerce.myfatoorah_sandbox_enabled'),
    'hosted' => config('commerce.myfatoorah_sandbox_hosted_checkout_enabled'),
    'token' => config('commerce.myfatoorah_sandbox_token'),
];
$tables = ['orders', 'payments', 'myfatoorah_checkout_reservations'];
$snapshot = static function () use ($tables): array {
    $out = [];
    foreach ($tables as $table) $out[$table] = DB::table($table)->count();
    return $out;
};
$before = $snapshot();
$send = static function (string $uri, ?User $user = null): array {
    if ($user) Sanctum::actingAs($user, ['*']);
    else auth()->forgetGuards();
    $request = Request::create($uri, 'POST', ['payment_id' => '123456789'], [], [],
        ['HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => '127.0.0.1']);
    $kernel = app(HttpKernel::class);
    $response = $kernel->handle($request);
    $result = [$response->getStatusCode(), json_decode($response->getContent(), true)];
    $kernel->terminate($request, $response);
    return $result;
};
$exit = 0;
DB::beginTransaction();
try {
    RateLimiter::for('storefront-test-order', static fn () => Limit::none());
    config([
        'commerce.myfatoorah_sandbox_enabled' => true,
        'commerce.myfatoorah_sandbox_hosted_checkout_enabled' => true,
        'commerce.myfatoorah_sandbox_token' => 'fake-local-only-token',
    ]);
    Http::swap(new \Illuminate\Http\Client\Factory());
    Http::preventStrayRequests();
    $orderId = DB::table('orders')->orderBy('id')->value('id');
    if (!$orderId) throw new RuntimeException('STOP: Requires an existing local order.');

    $uri = '/api/v1/orders/' . $orderId . '/payments/myfatoorah/sandbox-hosted/provider-enquiry';
    [$guestStatus] = $send($uri);
    $assert($guestStatus === 401, 'Guest cannot access provider enquiry');

    $user = User::create([
        'name' => 'Sandbox Enquiry Security Regression',
        'email' => 'sandbox-security-' . Str::random(16) . '@example.invalid',
        'password' => Str::random(40),
    ]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    [$deniedStatus] = $send($uri, $user);
    $assert($deniedStatus === 403, 'Authenticated user without orders.manage is forbidden');

    $permission = Permission::findOrCreate('orders.manage', 'web');
    $user->givePermissionTo($permission);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    [$allowedStatus, $allowedBody] = $send($uri, $user);
    // No invoice may exist for this order; 422 is the expected safe refusal.
    // If one does exist, the fake HTTP layer blocks any real gateway call.
    $assert($allowedStatus === 422 || $allowedStatus === 500,
        'Authorized user reaches guarded controller without real provider traffic');
    if ($allowedStatus === 500) {
        throw new RuntimeException('FAIL: Authorized enquiry returned server error; inspect exception before proceeding.');
    }

    config(['commerce.myfatoorah_sandbox_hosted_checkout_enabled' => false]);
    [$disabledStatus] = $send($uri, $user);
    $assert($disabledStatus === 404, 'Disabled sandbox enquiry is not accessible');

    $assert($snapshot() === $before, 'Enquiry HTTP checks did not mutate order, payment or reservation counts');
} catch (Throwable $e) {
    $exit = 1;
    fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . PHP_EOL);
} finally {
    DB::rollBack();
    auth()->forgetGuards();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    config([
        'commerce.myfatoorah_sandbox_enabled' => $original['sandbox'],
        'commerce.myfatoorah_sandbox_hosted_checkout_enabled' => $original['hosted'],
        'commerce.myfatoorah_sandbox_token' => $original['token'],
    ]);
}
if ($snapshot() !== $before) {
    fwrite(STDERR, "FAIL: Database count mismatch after rollback.\n");
    $exit = 1;
} else {
    echo "PASS: Order, payment and reservation counts unchanged after rollback.\n";
}
exit($exit);
