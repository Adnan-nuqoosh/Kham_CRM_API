<?php
/** Local-only rollback tax preview regression. */
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';
$app = require dirname(__DIR__) . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\Market;
use App\Models\MarketTaxRule;
use App\Models\User;
use App\Services\MarketTaxQuoteService;
use Illuminate\Support\Facades\DB;
$host = strtolower((string) config('database.connections.mysql.host', ''));
if (!app()->environment('local') || config('database.default') !== 'mysql'
    || !in_array($host, ['localhost', '127.0.0.1'], true)
    || DB::connection()->getDatabaseName() !== 'kham_crm_api') {
    fwrite(STDERR, "STOP: Local MySQL kham_crm_api only.\n"); exit(2);
}
$market = Market::firstOrFail();
$user = User::firstOrFail();
$service = app(MarketTaxQuoteService::class);
$check = static function (bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException("FAIL: {$label}");
    echo "PASS: {$label}\n";
};
DB::beginTransaction();
try {
    MarketTaxRule::where('market_id', $market->id)->delete();
    $check($service->preview($market, '100.0000', '20.0000')['tax_total'] === null,
        'missing policy does not calculate tax');
    $rule = MarketTaxRule::create([
        'market_id' => $market->id, 'rate_percent' => 10,
        'calculation_method' => 'exclusive', 'tax_shipping' => false,
    ]);
    $check($service->preview($market, '100.0000', '20.0000')['tax_total'] === null,
        'unapproved policy does not calculate tax');
    $rule->forceFill(['is_approved' => true, 'approved_at' => now(), 'approved_by' => $user->id])->save();
    $q = $service->preview($market, '100.0000', '20.0000');
    $check($q['tax_total'] === '10.0000' && $q['is_final_payable_total'] === false,
        'exclusive 10 percent preview remains non-payable');
    $rule->update(['tax_shipping' => true]);
    $check($service->preview($market, '100.0000', '20.0000')['tax_total'] === '12.0000',
        'shipping tax included when configured');
    $rule->update(['calculation_method' => 'inclusive', 'tax_shipping' => false]);
    $check($service->preview($market, '110.0000', '20.0000')['tax_total'] === '10.0000',
        'inclusive tax extracted correctly');
    $rule->forceFill(['approved_at' => null])->save();
    $check($service->preview($market, '110.0000', '20.0000')['tax_total'] === null,
        'incomplete approval fails closed');
    echo "ALL MARKET TAX PREVIEW CHECKS PASSED (rolled back)\n";
} finally { DB::rollBack(); }
