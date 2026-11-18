<?php

namespace App\Console\Commands;

use App\Services\MyFatoorahV3Client;
use Illuminate\Console\Command;

/**
 * Read-only sandbox readiness check. Intentionally never initiates a charge.
 * The API key is loaded from private local environment configuration.
 */
final class MyFatoorahSandboxCheck extends Command
{
    protected $signature = 'kham:myfatoorah-sandbox-check
        {--payment-id= : Optional known sandbox payment ID for a read-only GET enquiry}';

    protected $description = 'Check MyFatoorah sandbox configuration; optionally verify a known payment without changing database state';

    public function handle(MyFatoorahV3Client $client): int
    {
        if (!app()->environment('local', 'testing')) {
            $this->error('Sandbox diagnostic is restricted to local/testing.');
            return self::FAILURE;
        }
        $checks = [
            'Sandbox enabled' => config('commerce.myfatoorah_sandbox_enabled', false) === true,
            'Sandbox token configured' => trim((string) config('commerce.myfatoorah_sandbox_token', '')) !== '',
            'Hosted checkout flag' => config('commerce.myfatoorah_sandbox_hosted_checkout_enabled', false) === true,
            'Sandbox redirect HTTPS' => $this->https((string) config('commerce.myfatoorah_sandbox_redirect_url', '')),
            'Sandbox webhook flag' => config('commerce.myfatoorah_sandbox_webhook_enabled', false) === true,
            'Sandbox webhook secret configured' => trim((string) config('commerce.myfatoorah_webhook_secret', '')) !== '',
            'Production checkout disabled' => config('commerce.storefront_production_checkout_enabled', false) !== true,
            'Live MyFatoorah disabled' => config('commerce.myfatoorah_live_enabled', false) !== true,
        ];
        foreach ($checks as $name => $ok) {
            $this->line(($ok ? '[PASS] ' : '[FAIL] ').$name);
        }
        $this->line('Sandbox API base: https://apitest.myfatoorah.com/v3');
        $this->line('No credentials or webhook secrets are printed.');
        if (in_array(false, $checks, true)) {
            $this->warn('Fix configuration first. No provider enquiry was sent.');
            return self::FAILURE;
        }

        $id = $this->option('payment-id');
        if ($id === null || $id === '') {
            $this->info('Configuration ready. No network call or charge was made.');
            $this->line('Optional read-only check: php artisan kham:myfatoorah-sandbox-check --payment-id=KNOWN_SANDBOX_PAYMENT_ID');
            return self::SUCCESS;
        }
        if (!is_string($id) || !preg_match('/^[0-9]{1,64}$/D', $id)) {
            $this->error('Invalid sandbox payment ID.');
            return self::FAILURE;
        }
        try {
            $details = $client->getPaymentDetails($id);
            $this->info('Read-only provider enquiry completed.');
            $this->line('Invoice status: '.(string) data_get($details, 'Invoice.Status', 'unknown'));
            $this->line('Transaction status: '.(string) data_get($details, 'Transaction.Status', 'unknown'));
            $this->line('No database status changed. No new payment was initiated.');
            return self::SUCCESS;
        } catch (\Throwable $e) {
            // Provider HTTP bodies may contain sensitive data. Do not echo them.
            $this->error('Sandbox provider enquiry failed. Check token, payment ID, firewall and PHP cURL/TLS.');
            return self::FAILURE;
        }
    }

    private function https(string $url): bool
    {
        return (bool) filter_var($url, FILTER_VALIDATE_URL)
            && parse_url($url, PHP_URL_SCHEME) === 'https';
    }
}
