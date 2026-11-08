<?php
/**
 * Two-process checkout test using a fresh, isolated local MySQL database.
 * Run: php scripts/local_concurrent_orders_test.php
 * Requires CREATE/DROP DATABASE privileges and PHP proc_open.
 * Does not write to the original kham_crm_api database.
 */
declare(strict_types=1);
set_time_limit(180);
require dirname(__DIR__) . '/vendor/autoload.php';
$app = require dirname(__DIR__) . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\OrderService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

function localGuard(): void {
    $host = strtolower((string) config('database.connections.mysql.host', ''));
    if (app()->environment() !== 'local' || config('database.default') !== 'mysql'
        || !in_array($host, ['127.0.0.1', 'localhost'], true)
        || DB::connection()->getDatabaseName() !== 'kham_crm_api') {
        throw new RuntimeException('STOP: Requires APP_ENV=local, localhost MySQL and original database kham_crm_api.');
    }
}
function switchDb(string $name): void {
    if (!preg_match('/^kham_concurrency_[0-9a-f]{12}$/', $name)) throw new RuntimeException('Unsafe test database name.');
    config(['database.connections.mysql.database' => $name]);
    DB::purge('mysql');
    DB::connection('mysql')->getPdo();
}
function check(bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException('FAIL: ' . $label);
    echo 'PASS: ' . $label . PHP_EOL;
}
function worker(array $args): void {
    [, $db, $variant, $dir, $id, $marketCode] = $args;
    localGuard();
    switchDb($db);
    file_put_contents($dir . DIRECTORY_SEPARATOR . $id . '.ready', 'ready');
    $deadline = microtime(true) + 25;
    while (!is_file($dir . DIRECTORY_SEPARATOR . 'go')) {
        if (microtime(true) > $deadline) throw new RuntimeException('Worker start timed out.');
        usleep(10000);
    }
    try {
        $order = app(OrderService::class)->create([
            'market_id' => (int) DB::table('markets')->where('code', $marketCode)->value('id'),
            'items' => [['variant_id' => (int) $variant, 'quantity' => 1]],
        ]);
        $result = ['status' => 'success', 'number' => $order->order_number, 'market' => $marketCode, 'currency' => $order->currency_code, 'warehouse_id' => $order->warehouse_id];
    } catch (ValidationException $e) {
        $result = ['status' => 'rejected', 'market' => $marketCode, 'errors' => $e->errors()];
    } catch (Throwable $e) {
        $result = ['status' => 'error', 'class' => get_class($e), 'message' => $e->getMessage()];
    }
    file_put_contents($dir . DIRECTORY_SEPARATOR . $id . '.json', json_encode($result, JSON_THROW_ON_ERROR));
}
function pair(string $db, int $variant, string $dir, array $marketCodes = ['AE', 'AE']): array {
    if (!mkdir($dir, 0700, true) && !is_dir($dir)) throw new RuntimeException('Cannot make temporary worker directory.');
    $processes = [];
    try {
        foreach (['a', 'b'] as $index => $id) {
            $cmd = [PHP_BINARY, __FILE__, 'worker', $db, (string) $variant, $dir, $id, $marketCodes[$index]];
            $pipes = [];
            $proc = proc_open($cmd, [
                0 => ['pipe', 'r'],
                1 => ['file', $dir . DIRECTORY_SEPARATOR . $id . '.out', 'w'],
                2 => ['file', $dir . DIRECTORY_SEPARATOR . $id . '.err', 'w'],
            ], $pipes, dirname(__DIR__), null, ['bypass_shell' => true]);
            if (!is_resource($proc)) throw new RuntimeException('Unable to launch worker; check proc_open.');
            fclose($pipes[0]);
            $processes[] = $proc;
        }
        $deadline = microtime(true) + 30;
        while (!is_file($dir . '/a.ready') || !is_file($dir . '/b.ready')) {
            if (microtime(true) > $deadline) throw new RuntimeException('Workers failed to initialize; inspect .err files.');
            foreach ($processes as $proc) {
                if (!proc_get_status($proc)['running']) throw new RuntimeException('Worker exited before ready; inspect .err files.');
            }
            usleep(20000);
        }
        file_put_contents($dir . DIRECTORY_SEPARATOR . 'go', 'go');
        $deadline = microtime(true) + 45;
        while (!is_file($dir . '/a.json') || !is_file($dir . '/b.json')) {
            if (microtime(true) > $deadline) throw new RuntimeException('Workers timed out; inspect .err files.');
            usleep(20000);
        }
        return [
            json_decode(file_get_contents($dir . '/a.json'), true, 512, JSON_THROW_ON_ERROR),
            json_decode(file_get_contents($dir . '/b.json'), true, 512, JSON_THROW_ON_ERROR),
        ];
    } finally {
        foreach ($processes as $proc) {
            if (proc_get_status($proc)['running']) {
                usleep(200000);
                if (proc_get_status($proc)['running']) proc_terminate($proc);
            }
            proc_close($proc);
        }
    }
}
try {
    localGuard();
    if (($argv[1] ?? '') === 'worker') {
        worker(array_slice($argv, 1));
        exit(0);
    }
    if (isset($argv[1])) throw new RuntimeException('Usage: php scripts/local_concurrent_orders_test.php');
    if (!function_exists('proc_open')) throw new RuntimeException('proc_open is disabled in PHP.');
    $db = 'kham_concurrency_' . bin2hex(random_bytes(6));
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kham-concurrency-' . bin2hex(random_bytes(6));
    if (!mkdir($dir, 0700, true)) throw new RuntimeException('Unable to create temporary log directory.');
    $created = false;
    try {
        // Refuse an existing database: no IF NOT EXISTS or destructive reuse.
        DB::statement('CREATE DATABASE ' . chr(96) . $db . chr(96) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $created = true;
        switchDb($db);
        if (Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]) !== 0) {
            throw new RuntimeException('Isolated migration failed: ' . Artisan::output());
        }
        $now = now();
        $egp = DB::table('currencies')->insertGetId(['code'=>'EGP','name'=>'Egyptian Pound','symbol'=>'EGP','created_at'=>$now,'updated_at'=>$now]);
        $aed = DB::table('currencies')->insertGetId(['code'=>'AED','name'=>'UAE Dirham','symbol'=>'AED','created_at'=>$now,'updated_at'=>$now]);
        $sar = DB::table('currencies')->insertGetId(['code'=>'SAR','name'=>'Saudi Riyal','symbol'=>'SAR','created_at'=>$now,'updated_at'=>$now]);
        $eg = DB::table('markets')->insertGetId(['name'=>'Egypt','code'=>'EG','country_code'=>'EG','currency_id'=>$egp,'created_at'=>$now,'updated_at'=>$now]);
        $ae = DB::table('markets')->insertGetId(['name'=>'UAE','code'=>'AE','country_code'=>'AE','currency_id'=>$aed,'created_at'=>$now,'updated_at'=>$now]);
        $sa = DB::table('markets')->insertGetId(['name'=>'Saudi Arabia','code'=>'SA','country_code'=>'SA','currency_id'=>$sar,'created_at'=>$now,'updated_at'=>$now]);
        $wh = DB::table('warehouses')->insertGetId(['market_id'=>$eg,'name'=>'Egypt Test Warehouse','code'=>'EG-01','created_at'=>$now,'updated_at'=>$now]);
        $product = DB::table('products')->insertGetId(['name'=>'Concurrency Test','slug'=>'concurrency-test','status'=>'active','created_at'=>$now,'updated_at'=>$now]);
        $scenarios = [
            'two_success' => ['stock' => 2, 'markets' => ['AE', 'AE']],
            'oversell' => ['stock' => 1, 'markets' => ['AE', 'AE']],
            'cross_market_two_success' => ['stock' => 2, 'markets' => ['AE', 'SA']],
            'cross_market_oversell' => ['stock' => 1, 'markets' => ['AE', 'SA']],
        ];
        foreach ($scenarios as $scenario => $spec) {
            $initialStock = $spec['stock'];
            $variant = DB::table('product_variants')->insertGetId(['product_id'=>$product,'sku'=>'TEST-'.$scenario,'created_at'=>$now,'updated_at'=>$now]);
            DB::table('product_market_prices')->insert(['variant_id'=>$variant,'market_id'=>$ae,'currency_id'=>$aed,'price'=>25,'created_at'=>$now,'updated_at'=>$now]);
            DB::table('product_market_prices')->insert(['variant_id'=>$variant,'market_id'=>$sa,'currency_id'=>$sar,'price'=>30,'created_at'=>$now,'updated_at'=>$now]);
            DB::table('inventory_stocks')->insert(['variant_id'=>$variant,'warehouse_id'=>$wh,'on_hand'=>$initialStock,'reserved'=>0,'created_at'=>$now,'updated_at'=>$now]);
            $results = pair($db, $variant, $dir . DIRECTORY_SEPARATOR . $scenario, $spec['markets']);
            $outcomes = array_column($results, 'status');
            $successes = count(array_filter($outcomes, fn ($v) => $v === 'success'));
            $rejections = count(array_filter($outcomes, fn ($v) => $v === 'rejected'));
            check(array_column($results, 'market') === $spec['markets'], $scenario . ': workers used intended sales markets');
            foreach ($results as $result) {
                if ($result['status'] === 'success') {
                    check($result['currency'] === ($result['market'] === 'AE' ? 'AED' : 'SAR')
                        && (int) $result['warehouse_id'] === $wh,
                        $scenario . ': ' . $result['market'] . ' order uses local currency and Egypt warehouse');
                } elseif ($result['status'] === 'rejected') {
                    $messages = json_encode($result['errors'] ?? []);
                    check(str_contains(strtolower($messages), 'insufficient stock'),
                        $scenario . ': rejection is due to insufficient shared stock');
                }
            }
            check($successes === $initialStock && $rejections === 2 - $initialStock,
                $scenario . ' outcomes: ' . json_encode($results));
            $orders = DB::table('order_items')->join('orders','orders.id','=','order_items.order_id')
                ->where('order_items.variant_id',$variant)->get(['orders.order_number','orders.warehouse_id','order_items.quantity']);
            check($orders->count() === $initialStock && $orders->every(fn ($o) => (int) $o->warehouse_id === $wh && (int) $o->quantity === 1),
                $scenario . ': only valid Egypt-fulfilled orders persisted');
            $stock = DB::table('inventory_stocks')->where('variant_id',$variant)->first();
            check((int)$stock->on_hand === $initialStock && (int)$stock->reserved === $initialStock,
                $scenario . ': reserved stock matches successful orders');
        }
        $numbers = DB::table('orders')->pluck('order_number')->all();
        check(count($numbers) === 6 && count(array_unique($numbers)) === 6, 'All successful orders across UAE/KSA have unique numbers');
        check((int) DB::table('order_number_sequences')->where('year',(int) now()->format('Y'))->value('last_number') === 6,
            'Order sequence advanced exactly six times');
        echo "ALL ISOLATED CONCURRENCY TESTS PASSED.\n";
    } finally {
        config(['database.connections.mysql.database' => 'kham_crm_api']);
        DB::purge('mysql');
        if ($created) {
            DB::statement('DROP DATABASE ' . chr(96) . $db . chr(96));
            echo "CLEANUP: Isolated test database removed; original DB unchanged.\n";
        }
        echo 'INFO: Worker log directory: ' . $dir . PHP_EOL;
    }
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
