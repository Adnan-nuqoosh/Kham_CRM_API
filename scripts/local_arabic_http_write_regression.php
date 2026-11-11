<?php
/**
 * Local-only Arabic HTTP write/validation checks; transaction always rolled back.
 * Run: php scripts/local_arabic_http_write_regression.php
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
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

$host = strtolower((string) config('database.connections.mysql.host', ''));
if (!app()->environment('local') || config('database.default') !== 'mysql'
    || !in_array($host, ['localhost', '127.0.0.1'], true)
    || DB::connection()->getDatabaseName() !== 'kham_crm_api') {
    fwrite(STDERR, "STOP: Requires local MySQL kham_crm_api.\n"); exit(2);
}
foreach (['products', 'categories', 'brands'] as $table) {
    if (!Schema::hasColumn($table, 'name_ar')) {
        fwrite(STDERR, "STOP: Missing Arabic migration on {$table}.\n"); exit(2);
    }
}
$user = User::query()->get()->first(fn (User $u) => $u->can('products.manage'));
if (!$user) {
    fwrite(STDERR, "STOP: No existing products.manage user; no permissions changed.\n"); exit(2);
}
$kernel = $app->make(\Illuminate\Contracts\Http\Kernel::class);
$call = static function (string $method, string $uri, array $data) use ($kernel): array {
    $request = Request::create('/api/v1/' . $uri, $method, $data, [], [], [
        'HTTP_ACCEPT' => 'application/json',
    ]);
    $response = $kernel->handle($request);
    try {
        return [$response->getStatusCode(), json_decode($response->getContent(), true)];
    } finally {
        $kernel->terminate($request, $response);
    }
};
$assert = static function (bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException("FAIL: {$label}");
    echo "PASS: {$label}\n";
};
$marker = 'ArabicHttp' . Str::random(12);
DB::beginTransaction();
try {
    Sanctum::actingAs($user, ['*']);
    foreach ([
        ['categories', Category::class, 'PATCH'],
        ['brands', Brand::class, 'PATCH'],
        ['products', Product::class, 'PATCH'],
    ] as [$route, $model, $method]) {
        $item = $model::query()->firstOrFail();
        $english = $item->name;
        [$status, $body] = $call($method, $route . '/' . $item->id, [
            'name_ar' => $marker, 'description_ar' => 'وصف عربي',
        ]);
        $assert($status === 200, "{$route} authenticated Arabic HTTP update (status {$status})");
        $fresh = $model::findOrFail($item->id);
        $assert($fresh->name_ar === $marker && $fresh->name === $english,
            "{$route} HTTP write persists Arabic and preserves English");
        [$status, $body] = $call('GET', $route . '/' . $item->id . '?locale=ar', []);
        $assert($status === 200 && ($body['data']['name_localized'] ?? null) === $marker,
            "{$route} Arabic HTTP detail reflects update");
        [$status] = $call($method, $route . '/' . $item->id, ['name_ar' => str_repeat('x', 256)]);
        $assert($status === 422, "{$route} rejects Arabic name over 255 characters");
    }
    echo "ALL ARABIC HTTP WRITE CHECKS PASSED (rolled back)\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
} finally {
    DB::rollBack();
}
