<?php
/** Local-only HTTP tax authorization regression. All writes rolled back. */
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';
$app = require dirname(__DIR__) . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Market;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

$host = strtolower((string) config('database.connections.mysql.host', ''));
if (!app()->environment('local') || config('database.default') !== 'mysql'
    || !in_array($host, ['localhost', '127.0.0.1'], true)
    || DB::connection()->getDatabaseName() !== 'kham_crm_api') {
    fwrite(STDERR, "STOP: Local MySQL kham_crm_api only.\n"); exit(2);
}
$market = Market::firstOrFail();
$kernel = app(\Illuminate\Contracts\Http\Kernel::class);
$call = static function (string $method, string $uri, array $data = []) use ($kernel): int {
    $request = Request::create('/api/v1/' . $uri, $method, $data, [], [], ['HTTP_ACCEPT' => 'application/json']);
    $response = $kernel->handle($request);
    try { return $response->getStatusCode(); }
    finally { $kernel->terminate($request, $response); }
};
$check = static function (bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException("FAIL: {$label}");
    echo "PASS: {$label}\n";
};
$uri = "markets/{$market->id}/tax-rule";
$payload = ['rate_percent' => 10, 'calculation_method' => 'exclusive', 'tax_shipping' => false];
$check($call('PUT', $uri, $payload) === 401, 'anonymous tax policy write rejected');
$check($call('POST', $uri . '/approve', ['confirm' => true]) === 401, 'anonymous approval rejected');
DB::beginTransaction();
try {
    $settings = Permission::firstOrCreate(['name' => 'settings.manage', 'guard_name' => 'web']);
    $approval = Permission::firstOrCreate(['name' => 'tax.approve', 'guard_name' => 'web']);
    $editor = User::create([
        'name' => 'Temporary Tax Editor', 'email' => 'tax-editor-' . Str::random(16) . '@example.invalid',
        'password' => Str::random(48),
    ]);
    $editor->givePermissionTo($settings);
    Sanctum::actingAs($editor, ['*']);
    $check($call('GET', 'market-tax-rules') === 200, 'settings editor can list tax policies');
    $check($call('PUT', $uri, $payload) === 200, 'settings editor can configure policy');
    $check($call('POST', $uri . '/approve', ['confirm' => true]) === 403,
        'settings editor cannot approve tax policy');
    $approver = User::create([
        'name' => 'Temporary Tax Approver', 'email' => 'tax-approver-' . Str::random(16) . '@example.invalid',
        'password' => Str::random(48),
    ]);
    $approver->givePermissionTo($approval);
    Sanctum::actingAs($approver, ['*']);
    $check($call('PUT', $uri, $payload) === 403, 'approver cannot edit tax policy');
    $check($call('POST', $uri . '/approve', []) === 422, 'approval requires explicit confirmation');
    $check($call('POST', $uri . '/approve', ['confirm' => true]) === 200,
        'tax approver can explicitly approve');
    Sanctum::actingAs($editor, ['*']);
    $check($call('PUT', $uri, ['rate_percent' => 11, 'calculation_method' => 'exclusive', 'tax_shipping' => false]) === 200,
        'editor can revise previously approved policy');
    $rule = \App\Models\MarketTaxRule::where('market_id', $market->id)->firstOrFail();
    $check(!$rule->is_approved && $rule->approved_at === null && $rule->approved_by === null,
        'policy change revokes prior approval');
    echo "ALL TAX HTTP PERMISSION CHECKS PASSED (rolled back)\n";
} finally {
    DB::rollBack();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
}
