<?php

return [
    // All EG, AE and SA storefront orders ship from the Egypt warehouse.
    // This is a warehouse code (not a market ID or a client-provided ID).
    'fulfillment_warehouse_code' => env('FULFILLMENT_WAREHOUSE_CODE', 'EG-01'),
];
