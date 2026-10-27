<?php
return [
 // Checkout must remain disabled until taxes, shipping rates, payment webhooks, and fulfillment rules are configured.
 'checkout_enabled' => (bool) env('STOREFRONT_CHECKOUT_ENABLED', false),
];
