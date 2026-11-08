<?php

return [
    // All EG, AE and SA storefront orders ship from the Egypt warehouse.
    // This is a warehouse code (not a market ID or a client-provided ID).
    'fulfillment_warehouse_code' => env('FULFILLMENT_WAREHOUSE_CODE', 'EG-01'),
    // Internal CRM configured shipping orders remain off until tax policy is approved.
    'configured_shipping_orders_enabled' => env('COMMERCE_CONFIGURED_SHIPPING_ORDERS_ENABLED', false),
    // Never enables production checkout. Local mock flow only; no real payment.
    'storefront_test_checkout_enabled' => env('COMMERCE_STOREFRONT_TEST_CHECKOUT_ENABLED', false),
];
