<?php
/**
 * Local-only receipt idempotency regression. Uses a rollback transaction.
 * No network, no order/payment/stock mutation.
 */
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';
$app = require dirname(__DIR__) . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\MyFatoorahWebhookReceiptService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

if (!app()->environment('local', 'testing')
    || DB::connection()->getDatabaseName() !== 'kham_crm_api') {
    fwrite(STDERR, "STOP: Only local kham_crm_api test database is allowed.\n");
    exit(2);
}
if (!Schema::hasTable('myfatoorah_webhook_receipts')) {
    fwrite(STDERR, "STOP: Run php artisan migrate before this test.\n");
    exit(2);
}

$assert = static function (bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException('FAIL: ' . $label);
    echo 'PASS: ' . $label . PHP_EOL;
};

DB::beginTransaction();
$exit = 0;
try {
    $event = [
        'event_reference' => 'evt-local-test-1',
        'invoice_id' => '6148108',
        'payment_id' => '12345',
        'external_identifier' => 'KH-2026-000001',
        'invoice_status' => 'PAID',
        'transaction_status' => 'SUCCESS',
        'requires_provider_reconciliation' => true,
    ];
    $reconciled = [
        'invoice_id' => '6148108',
        'payment_id' => '12345',
        'external_identifier' => 'KH-2026-000001',
        'verified_paid' => true,
        'requires_atomic_database_transition' => true,
    ];
    $service = new MyFatoorahWebhookReceiptService();

    $first = $service->record($event, $reconciled);
    $assert($first['inserted'] === true && $first['duplicate'] === false
        && $first['order_payment_status_changed'] === false,
        'First verified callback creates unapplied receipt only');

    $again = $service->record($event, $reconciled);
    $assert($again['inserted'] === false && $again['duplicate'] === true
        && $again['receipt_id'] === $first['receipt_id'],
        'Repeated callback is deduplicated');

    $modifiedReference = $event;
    $modifiedReference['event_reference'] = 'evt-local-test-2';
    $replay = $service->record($modifiedReference, $reconciled);
    $assert($replay['duplicate'] === true,
        'Changed unsigned event reference cannot bypass deduplication');

    $assert(DB::table('myfatoorah_webhook_receipts')->where('event_hash',
        DB::table('myfatoorah_webhook_receipts')->where('id', $first['receipt_id'])->value('event_hash'))->count() === 1,
        'One unique receipt persists inside test transaction');

    $invalid = $reconciled;
    $invalid['verified_paid'] = false;
    $caught = false;
    try { $service->record($event, $invalid); }
    catch (ValidationException $e) { $caught = true; }
    $assert($caught, 'Unverified provider payment cannot be recorded as verified');

    $invalid = $reconciled;
    $invalid['payment_id'] = 'wrong';
    $caught = false;
    try { $service->record($event, $invalid); }
    catch (ValidationException $e) { $caught = true; }
    $assert($caught, 'Mismatched payment ID rejected');

    echo "ALL MYFATOORAH RECEIPT REPLAY TESTS PASSED; rolling back fixtures.\n";
} catch (Throwable $e) {
    $exit = 1;
    fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . PHP_EOL);
} finally {
    DB::rollBack();
}
exit($exit);
