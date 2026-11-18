<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\MyFatoorahLiveClient;
use App\Services\MyFatoorahLiveSettlementService;
use App\Services\MyFatoorahPaymentReconciler;
use App\Services\MyFatoorahWebhookReceiptService;
use App\Services\MyFatoorahWebhookV2Verifier;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Market-specific verified Webhook V2 for paid settlement.
 * Never trust browser redirect or a signed webhook alone.
 */
final class StorefrontLiveMyFatoorahWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        string $marketCode,
        MyFatoorahWebhookV2Verifier $verifier,
        MyFatoorahLiveClient $client,
        MyFatoorahPaymentReconciler $reconciler,
        MyFatoorahWebhookReceiptService $receipts,
        MyFatoorahLiveSettlementService $settlement
    ) {
        $marketCode=strtoupper($marketCode);
        if (!in_array($marketCode,['EG','AE','SA'],true)) abort(404);
        if (!app()->environment('production')
            || !config('commerce.storefront_production_checkout_enabled',false)
            || !config('commerce.myfatoorah_live_enabled',false)) abort(404);
        if (!$request->isJson() || strlen($request->getContent())>65536) {
            return ApiResponse::error('JSON webhook body required (max 64 KiB).','INVALID_WEBHOOK_BODY',400);
        }
        $secret=(string)config('commerce.myfatoorah_live_webhook_secrets.'.$marketCode,'');
        $signed=$verifier->verify(
            $request->json()->all(),
            $request->header('MyFatoorah-Signature'),
            $secret
        );
        if (!preg_match('/^[0-9]{1,64}$/D',$signed['invoice_id'])
            || !preg_match('/^[0-9]{1,64}$/D',$signed['payment_id'])) {
            throw ValidationException::withMessages(['payment'=>['Invalid payment identifiers.']]);
        }
        $attempts=DB::table('payments')
            ->join('orders','orders.id','=','payments.order_id')
            ->join('markets','markets.id','=','orders.market_id')
            ->where('payments.provider','myfatoorah')
            ->where('payments.transaction_reference',$signed['invoice_id'])
            ->where('orders.order_number',$signed['external_identifier'])
            ->where('markets.code',$marketCode)
            ->select('payments.id','orders.id as order_id','orders.order_number',
                'orders.grand_total','orders.currency_code')->limit(2)->get();

        if($attempts->count()!==1) {
            throw ValidationException::withMessages(['payment'=>['Stored payment attempt missing or ambiguous.']]);
        }
        $attempt=$attempts->first();
        $provider=$client->getPaymentDetails($marketCode,$signed['payment_id']);
        $verified=$reconciler->verify(
            $signed,$provider,$signed['invoice_id'],$attempt->order_number,
            (string)$attempt->grand_total,$attempt->currency_code
        );
        if (!$verified['verified_paid']) {
            return ApiResponse::success(['payment_status'=>'pending'],
                'Provider payment is not confirmed.','PAYMENT_NOT_CONFIRMED',202)
                ->header('Cache-Control','no-store');
        }
        $outcome=DB::transaction(function()use($receipts,$settlement,$signed,$verified,$attempt){
            $receipt=$receipts->record($signed,$verified);
            $settled=$settlement->apply((int)$attempt->order_id,$signed,$verified);
            DB::table('myfatoorah_webhook_receipts')->where('id',$receipt['receipt_id'])->update([
                'result'=>'applied','updated_at'=>now(),
            ]);
            return [
                'payment_status'=>'paid',
                'order_payment_status_changed'=>$settled['applied'],
                'duplicate'=>$settled['duplicate'],
            ];
        },5);
        return ApiResponse::success($outcome,'Verified live payment settled.',
            'STOREFRONT_PAYMENT_VERIFIED')->header('Cache-Control','no-store');
    }
}
