<?php
/**
 * Read-only authenticated HTTP route checks for Arabic catalog.
 * Run: php scripts/local_arabic_http_regression.php
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
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;

$host = strtolower((string) config('database.connections.mysql.host', ''));
if (!app()->environment('local') || config('database.default') !== 'mysql'
    || !in_array($host, ['localhost', '127.0.0.1'], true)
    || DB::connection()->getDatabaseName() !== 'kham_crm_api') {
    fwrite(STDERR, "STOP: Only local MySQL kham_crm_api is permitted.\n");
    exit(2);
}
foreach (['products', 'categories', 'brands'] as $table) {
    if (!Schema::hasColumn($table, 'name_ar')) {
        fwrite(STDERR, "STOP: Arabic migration missing on {$table}.\n");
        exit(2);
    }
}
$assert = static function (bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException("FAIL: {$label}");
    echo "PASS: {$label}\n";
};
$kernel = $app->make(\Illuminate\Contracts\Http\Kernel::class);
$call = static function (string $uri) use ($kernel): array {
    $request = Request::create('/api/v1/' . $uri, 'GET', [], [], [], ['HTTP_ACCEPT' => 'application/json']);
    $response = $kernel->handle($request);
    try {
        return [$response->getStatusCode(), json_decode($response->getContent(), true)];
    } finally {
        $kernel->terminate($request, $response);
    }
};
try {
    [$status] = $call('products?locale=ar');
    $assert($status === 401, 'Unauthenticated catalog request denied');
    $user = User::query()->get()->first(fn (User $candidate) => $candidate->can('products.view'));
    if (!$user) {
        fwrite(STDERR, "STOP: No existing user has products.view; no user or permission created.\n");
        exit(2);
    }
    Sanctum::actingAs($user, ['*']);
    foreach ([
        ['products', Product::class],
        ['categories', Category::class],
        ['brands', Brand::class],
    ] as [$route, $model]) {
        $item = $model::query()->first();
        if (!$item) {
            fwrite(STDERR, "STOP: Missing {$route} fixture.\n");
            exit(2);
        }
        [$status, $body] = $call($route . '/' . $item->id . '?locale=ar');
        $expected = $item->name_ar !== null && trim($item->name_ar) !== '' ? $item->name_ar : $item->name;
        $assert($status === 200 && ($body['data']['name_localized'] ?? null) === $expected,
            "{$route} authenticated Arabic detail route");
        [$status, $body] = $call($route . '/' . $item->id . '?locale=en');
        $assert($status === 200 && ($body['data']['name_localized'] ?? null) === $item->name,
            "{$route} authenticated English detail route");
    }
    echo "ALL ARABIC HTTP READ CHECKS PASSED (read-only)\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
