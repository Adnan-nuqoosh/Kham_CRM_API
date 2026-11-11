<?php
/**
 * Local-only Arabic catalog authorization regression.
 * Temporary viewer and permission assignment are rolled back after testing.
 * Run: php scripts/local_arabic_http_permissions_regression.php
 */
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
$app = require dirname(__DIR__) . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Laravel\Sanctum\Sanctum;

$host = strtolower((string) config('database.connections.mysql.host', ''));
if (!app()->environment('local') || config('database.default') !== 'mysql'
    || !in_array($host, ['localhost', '127.0.0.1'], true)
    || DB::connection()->getDatabaseName() !== 'kham_crm_api') {
    fwrite(STDERR, "STOP: Requires local MySQL kham_crm_api.\n"); exit(2);
}
$kernel = $app->make(\Illuminate\Contracts\Http\Kernel::class);
$call = static function (string $method, string $uri, array $data = []) use ($kernel): int {
    $request = Request::create('/api/v1/' . $uri, $method, $data, [], [], [
        'HTTP_ACCEPT' => 'application/json',
    ]);
    $response = $kernel->handle($request);
    try { return $response->getStatusCode(); }
    finally { $kernel->terminate($request, $response); }
};
$assert = static function (bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException("FAIL: {$label}");
    echo "PASS: {$label}\n";
};
try {
    $fixtures = [
        ['products', Product::query()->firstOrFail()->id],
        ['categories', Category::query()->firstOrFail()->id],
        ['brands', Brand::query()->firstOrFail()->id],
    ];
    foreach ($fixtures as [$route, $id]) {
        $assert($call('PATCH', "{$route}/{$id}", ['name_ar' => 'ممنوع']) === 401,
            "{$route} unauthenticated Arabic write denied");
    }
    $permission = Permission::query()
        ->where('name', 'products.view')
        ->where('guard_name', 'web')
        ->first();
    if (!$permission) {
        throw new RuntimeException('Missing products.view permission (web guard).');
    }
    DB::beginTransaction();
    $viewer = User::create([
        'name' => 'Temporary Arabic Read-Only Regression',
        'email' => 'arabic-regression-' . Str::random(20) . '@example.invalid',
        'password' => Str::random(48),
    ]);
    $viewer->givePermissionTo($permission);
    $assert($viewer->can('products.view') && !$viewer->can('products.manage'),
        'Temporary user has products.view only');
    Sanctum::actingAs($viewer, ['*']);
    foreach ($fixtures as [$route, $id]) {
        $assert($call('GET', "{$route}/{$id}?locale=ar") === 200,
            "{$route} read-only user may read Arabic detail");
        $assert($call('PATCH', "{$route}/{$id}", ['name_ar' => 'ممنوع']) === 403,
            "{$route} read-only user cannot update Arabic content");
    }
    echo "ALL ARABIC HTTP PERMISSION CHECKS PASSED (rolled back)\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL); exit(1);
} finally {
    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    app(PermissionRegistrar::class)->forgetCachedPermissions();
}
