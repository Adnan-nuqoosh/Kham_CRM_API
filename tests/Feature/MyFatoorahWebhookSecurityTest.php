<?php

namespace Tests\Feature;

use App\Services\MyFatoorahPaymentReconciler;
use App\Services\MyFatoorahWebhookV2Verifier;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Validation\ValidationException;

final class MyFatoorahWebhookSecurityTest extends TestCase
{
    private function event(): array
    {
        return [
            'Event'=>['Code'=>1,'Name'=>'PAYMENT_STATUS_CHANGED','Reference'=>'WH-TEST-001'],
            'Data'=>[
                'Invoice'=>['Id'=>'500','Status'=>'PAID','ExternalIdentifier'=>'KH-2026-000001'],
                'Transaction'=>['Status'=>'SUCCESS','PaymentId'=>'900'],
            ],
        ];
    }

    private function signature(string $secret): string
    {
        $payload=implode(',',[
            'Invoice.Id=500','Invoice.Status=PAID',
            'Transaction.Status=SUCCESS','Transaction.PaymentId=900',
            'Invoice.ExternalIdentifier=KH-2026-000001',
        ]);
        return base64_encode(hash_hmac('sha256',$payload,$secret,true));
    }

    public function test_invalid_webhook_signature_never_gets_verified(): void
    {
        $this->expectException(ValidationException::class);
        app(MyFatoorahWebhookV2Verifier::class)->verify(
            $this->event(),'forged-signature','test-secret'
        );
    }

    public function test_tampering_with_signed_amount_reference_is_detected(): void
    {
        $signed=app(MyFatoorahWebhookV2Verifier::class)->verify(
            $this->event(),$this->signature('test-secret'),'test-secret'
        );
        $details=[
            'Invoice'=>['Id'=>'500','ExternalIdentifier'=>'KH-2026-000001','Status'=>'PAID'],
            'Transaction'=>['PaymentId'=>'900','Status'=>'SUCCESS'],
            'Amount'=>['DisplayCurrency'=>'EGP','ValueInDisplayCurrency'=>'99.0000'],
        ];
        $this->expectException(ValidationException::class);
        app(MyFatoorahPaymentReconciler::class)->verify(
            $signed,$details,'500','KH-2026-000001','100.0000','EGP'
        );
    }

    public function test_paid_status_requires_both_provider_invoice_and_transaction_success(): void
    {
        $signed=app(MyFatoorahWebhookV2Verifier::class)->verify(
            $this->event(),$this->signature('test-secret'),'test-secret'
        );
        $details=[
            'Invoice'=>['Id'=>'500','ExternalIdentifier'=>'KH-2026-000001','Status'=>'UNPAID'],
            'Transaction'=>['PaymentId'=>'900','Status'=>'SUCCESS'],
            'Amount'=>['DisplayCurrency'=>'EGP','ValueInDisplayCurrency'=>'100.0000'],
        ];
        $result=app(MyFatoorahPaymentReconciler::class)->verify(
            $signed,$details,'500','KH-2026-000001','100.0000','EGP'
        );
        $this->assertFalse($result['verified_paid']);
    }
}
