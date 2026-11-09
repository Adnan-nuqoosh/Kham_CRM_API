<?php
/**
 * Local-only Arabic catalog integration regression.
 * Uses existing catalog rows without writing to the database.
 * Run: php scripts/local_arabic_catalog_regression.php
 */
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
$app = require dirname(__DIR__) . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\Api\V1\CatalogController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$host = strtolower((string) config('database.connections.mysql.host', ''));
if (!app()->environment('local') || config('database.default') !== 'mysql'
    || !in_array($host, ['127.0.0.1', 'localhost'], true)
    || DB::connection()->getDatabaseName() !== 'kham_crm_api') {
    fwrite(STDERR, "STOP: Requires APP_ENV=local and local MySQL kham_crm_api.\n");
    exit(2);
}
foreach (['products', 'categories', 'brands'] as $table) {
    if (!Schema::hasColumn($table, 'name_ar')) {
        fwrite(STDERR, "STOP: Arabic migration is missing on {$table}.\n");
        exit(2);
    }
}
$assert = static function (bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException('FAIL: ' . $label);
    echo 'PASS: ' . $label . PHP_EOL;
};
$catalog = $app->make(CatalogController::class);
$products = $app->make(ProductController::class);
$checks = 0;
foreach ([
    [Category::class, 'showCategory', 'category'],
    [Brand::class, 'showBrand', 'brand'],
    [Product::class, 'show', 'product'],
] as [$model, $action, $label]) {
    $item = $model::query()->first();
    if (!$item) {
        fwrite(STDERR, "STOP: No {$label} fixture exists; no data was created.\n");
        exit(2);
    }
    $controller = $label === 'product' ? $products : $catalog;
    $arabic = Request::create('/?locale=ar');
    $english = Request::create('/?locale=en');
    $ar = $controller->{$action}($arabic, $item)->getData(true)['data'];
    $en = $controller->{$action}($english, $item)->getData(true)['data'];
    $expected = $item->name_ar !== null && trim($item->name_ar) !== ''
        ? $item->name_ar : $item->name;
    $assert($ar['name_localized'] === $expected, "{$label} Arabic name or fallback"); $checks++;
    $assert($en['name_localized'] === $item->name, "{$label} English name"); $checks++;
    $assert($ar['name'] === $item->name && array_key_exists('name_ar', $ar),
        "{$label} preserves original bilingual fields"); $checks++;
}
$assert($checks === 9, 'Nine catalog localization checks completed');
echo "ALL ARABIC CATALOG CHECKS PASSED (read-only)\n";
