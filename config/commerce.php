<?php

return [
    // All EG, AE and SA storefront orders ship from the Egypt warehouse.
    // This is a warehouse code (not a market ID or a client-provided ID).
    'fulfillment_warehouse_code' => env('FULFILLMENT_WAREHOUSE_CODE', 'EG-01'),
    // Internal CRM configured shipping orders remain off until tax policy is approved.
    'configured_shipping_orders_enabled' => env('COMMERCE_CONFIGURED_SHIPPING_ORDERS_ENABLED', false),
    // Never enables production checkout. Local mock flow only; no real payment.
    'storefront_test_checkout_enabled' => env('COMMERCE_STOREFRONT_TEST_CHECKOUT_ENABLED', false),
    // Sandbox-only service; no checkout route or payment status mutation yet.
    'myfatoorah_sandbox_enabled' => env('MYFATOORAH_SANDBOX_ENABLED', false),
    'myfatoorah_sandbox_token' => env('MYFATOORAH_SANDBOX_TOKEN'),
    // CRM-only local/testing hosted initiation; requires server-controlled HTTPS redirect.
    'myfatoorah_sandbox_hosted_checkout_enabled' => env('MYFATOORAH_SANDBOX_HOSTED_CHECKOUT_ENABLED', false),
    'myfatoorah_sandbox_redirect_url' => env('MYFATOORAH_SANDBOX_REDIRECT_URL'),
    // Local-only webhook harness; disabled unless explicitly opted in.
    'myfatoorah_sandbox_webhook_enabled' => env('MYFATOORAH_SANDBOX_WEBHOOK_ENABLED', false),
    'myfatoorah_webhook_secret' => env('MYFATOORAH_WEBHOOK_SECRET'),
];
