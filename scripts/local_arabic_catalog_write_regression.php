<?php
/**
 * Local-only Arabic catalog persistence and search regression.
 * All writes occur in a transaction that is ALWAYS rolled back.
 * Run: php scripts/local_arabic_catalog_write_regression.php
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
use Illuminate\Support\Str;

$host = strtolower((string) config('database.connections.mysql.host', ''));
if (!app()->environment('local') || config('database.default') !== 'mysql'
    || !in_array($host, ['127.0.0.1', 'localhost'], true)
    || DB::connection()->getDatabaseName() !== 'kham_crm_api') {
    fwrite(STDERR, "STOP: Requires APP_ENV=local and local MySQL kham_crm_api.\n");
    exit(2);
}
foreach (['products', 'categories', 'brands'] as $table) {
    if (!Schema::hasColumn($table, 'name_ar')) {
        fwrite(STDERR, "STOP: Missing Arabic migration on {$table}.\n");
        exit(2);
    }
}
$assert = static function (bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException('FAIL: ' . $label);
    echo 'PASS: ' . $label . PHP_EOL;
};
$catalog = $app->make(CatalogController::class);
$products = $app->make(ProductController::class);
$marker = 'ArabicTest' . Str::random(12);
$exit = 0;
DB::beginTransaction();
try {
    foreach ([
        [Category::class, 'showCategory', 'categories', 'category'],
        [Brand::class, 'showBrand', 'brands', 'brand'],
        [Product::class, 'show', 'products', 'product'],
    ] as [$model, $action, $table, $label]) {
        $item = $model::query()->firstOrFail();
        $originalName = $item->name;
        $item->update(['name_ar' => $marker, 'description_ar' => 'وصف تجريبي']);
        $fresh = $model::findOrFail($item->id);
        $assert($fresh->name_ar === $marker && $fresh->name === $originalName,
            "{$label} Arabic persistence preserves English");
        $controller = $label === 'product' ? $products : $catalog;
        $arabic = $controller->{$action}(Request::create('/?locale=ar'), $fresh)->getData(true)['data'];
        $english = $controller->{$action}(Request::create('/?locale=en'), $fresh)->getData(true)['data'];
        $assert($arabic['name_localized'] === $marker
            && $arabic['description_localized'] === 'وصف تجريبي'
            && $english['name_localized'] === $originalName,
            "{$label} real Arabic content and English response");
        $searchAction = $label === 'product' ? 'index' : ($label === 'category' ? 'categories' : 'brands');
        $search = Request::create('/?search=' . $marker . '&paginate=1');
        $results = $controller->{$searchAction}($search)->getData(true)['data'];
        $assert(collect($results)->contains(fn ($row) => (int) $row['id'] === (int) $item->id),
            "{$label} Arabic name search");
    }
} catch (Throwable $e) {
    $exit = 1;
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
} finally {
    DB::rollBack();
}
if ($exit) exit($exit);
echo "ALL ARABIC WRITE/SEARCH CHECKS PASSED (rolled back)\n";
