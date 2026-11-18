<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Read-only operator triage of unresolved provider payment reservations. */
final class KhamPaymentReconciliationReport extends Command
{
    protected $signature = 'kham:payment-reconciliation-report
        {--older-than=30 : Minimum age of unresolved reservations in minutes}
        {--limit=50 : Maximum rows returned, 1 to 100}';
    protected $description = 'List unresolved MyFatoorah payment reservations without changing payment or inventory state';

    public function handle(): int
    {
        $minutes=filter_var($this->option('older-than'),FILTER_VALIDATE_INT,
            ['options'=>['min_range'=>1,'max_range'=>10080]]);
        $limit=filter_var($this->option('limit'),FILTER_VALIDATE_INT,
            ['options'=>['min_range'=>1,'max_range'=>100]]);
        if ($minutes===false || $limit===false) {
            $this->error('Invalid older-than (1..10080) or limit (1..100).');
            return self::FAILURE;
        }
        $rows=DB::table('myfatoorah_checkout_reservations as r')
            ->join('orders as o','o.id','=','r.order_id')
            ->select('o.order_number','r.status','r.invoice_id','r.provider_requested_at',
                'r.created_at','o.payment_status')
            ->whereIn('r.status',['reserved','requesting','uncertain','initiated'])
            ->where('r.created_at','<=',now()->subMinutes($minutes))
            ->orderBy('r.created_at')->limit($limit)->get();

        if ($rows->isEmpty()) {
            $this->info('No unresolved payment reservations match the selected age.');
            return self::SUCCESS;
        }
        $this->table(['Order','Reservation','Invoice','Created UTC','Order payment'],
            $rows->map(fn($row)=>[
                $row->order_number,$row->status,$row->invoice_id ?? '(unknown)',
                (string)$row->created_at,(string)$row->payment_status,
            ])->all());
        $this->warn('Read-only. Do not retry or release stock until provider-side reconciliation.');
        $this->line('Provider tokens, payment URL, payment card data and webhook secrets are not shown.');
        return self::SUCCESS;
    }
}
