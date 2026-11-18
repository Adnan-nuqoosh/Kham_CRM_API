<?php

namespace App\Console\Commands;

use App\Models\Market;
use App\Services\StorefrontCheckoutReadiness;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Read-only deploy preflight. Never enables payments or prints secrets. */
final class KhamReleaseAudit extends Command
{
    protected $signature = 'kham:release-audit {--strict : Exit failure if any launch prerequisite is unmet}';
    protected $description = 'Read-only PHP, TLS, DB, shipping, tax, payment and security deployment audit';

    public function handle(): int
    {
        $checks=[];
        $checks['PHP >=8.3'] = PHP_VERSION_ID >= 80300;
        $checks['APP_DEBUG disabled'] = config('app.debug') === false;
        $checks['APP_KEY present'] = strlen((string) config('app.key')) >= 32;
        $checks['APP_URL HTTPS'] = $this->https((string) config('app.url'));
        $checks['Database reachable'] = $this->dbAvailable();
        $checks['Vendor storage writable'] = is_writable(storage_path('logs'))
            && is_writable(storage_path('framework/cache'));
        $checks['CORS origins explicit'] = $this->corsOriginsSafe();
        $checks['Development mock checkout disabled'] =
            config('commerce.storefront_test_checkout_enabled',false) !== true;
        $checks['Production payment gate disabled until launch'] =
            config('commerce.storefront_production_checkout_enabled',false) !== true;
        $checks['MyFatoorah live flag disabled until launch'] =
            config('commerce.myfatoorah_live_enabled',false) !== true;

        foreach ($checks as $name => $pass) {
            $this->line(($pass?'[PASS] ':'[FAIL] ').$name);
        }
        $this->newLine();
        if ($checks['Database reachable']) {
            $tables=['storefront_accounts','storefront_wishlists','storefront_checkout_attempts',
                'myfatoorah_checkout_reservations','myfatoorah_webhook_receipts','market_tax_rules','shipping_rules'];
            foreach ($tables as $table) {
                $ok=Schema::hasTable($table);
                $checks['Table '.$table]=$ok;
                $this->line(($ok?'[PASS] ':'[FAIL] ').'Table '.$table);
            }
            foreach (Market::where('is_active',true)->orderBy('id')->get() as $market) {
                $ready=app(StorefrontCheckoutReadiness::class)->forMarket($market);
                $this->line('Market '.$market->code.': '.($ready['ready']?'READY':'NOT READY'));
                foreach($ready['blockers'] as $blocker) $this->line('  - '.$blocker);
                $checks['Market '.$market->code.' checkout ready']=$ready['ready'];
            }
        }
        $failed=count(array_filter($checks,fn($passed)=>!$passed));
        $this->newLine();
        $this->line('Summary: '.(count($checks)-$failed).'/'.count($checks).' checks passed.');
        $this->line('Security and payment keys are intentionally never displayed.');
        if ($failed && $this->option('strict')) return self::FAILURE;
        return self::SUCCESS;
    }

    private function https(string $url): bool
    {
        return (bool) filter_var($url,FILTER_VALIDATE_URL)
            && parse_url($url,PHP_URL_SCHEME)==='https';
    }

    private function dbAvailable(): bool
    {
        try { DB::connection()->select('SELECT 1'); return true; }
        catch (\Throwable $e) { return false; }
    }

    private function corsOriginsSafe(): bool
    {
        $origins=config('cors.allowed_origins',[]);
        if (!is_array($origins) || !$origins) return false;
        foreach ($origins as $origin) {
            if (!is_string($origin) || !$this->https(trim($origin))
                || str_contains($origin,'*')) return false;
        }
        return true;
    }
}
