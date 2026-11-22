<?php

return [
    'collections' => [],
    'collection' => env('SELLISSIMO_COLLECTION', 'products'),
    'connection' => env('SELLISSIMO_CONNECTION', 'sellissimo'),
    'sqlite_path' => env('SELLISSIMO_SQLITE_PATH', database_path('sellissimo.sqlite')),
    'locked_settings' => [], // Field handles, or site handle => field handles.
    'currencies' => ['default' => 'USD'],
    'storefront' => env('SELLISSIMO_STOREFRONT', false),
    'prefix' => env('SELLISSIMO_PREFIX', 'shop'),
    'activity_widget' => true,
    'default_features' => [],
    'low_stock_threshold' => 5,
    'order_columns_by_role' => [], // Role handle => comma-separated order columns.
    'carriers' => [],
    'shipping' => ['flat_rate'],
    'payments' => env('SELLISSIMO_STRIPE_SECRET') ? ['stripe'] : [],
    'checkout_countries' => [], // Per site: billing, shipping, default_billing, default_shipping, provider.
    'checkout_steps' => ['contact', 'delivery', 'review'],
    'unit_price_providers' => [],
    'order_charge_providers' => [],
    'discount_rules' => [],
    'discounts' => [],
    'checkout_policy' => [],
    'taxes' => [],
    'tax_settings' => [], // Per site: physical_address (billing or shipping), shipping_class, provider.
    'download_disk' => 'sellissimo_private',
    'download_upload_kb' => 51200,
    'product_urls' => [], // Optional per-site product URL pattern containing {sku} for custom themes.
    'account_urls' => [], // Per-site login and registration page URLs.
    'access_days' => 7,
    'first_order_number' => 1000,
    'recovery' => ['inactive_minutes' => 60, 'retention_days' => 90, 'automatic' => false, 'storefront_urls' => []],
];
