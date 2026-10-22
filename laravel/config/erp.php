<?php

return [
    'version' => '0.20.3',
    // 0.15: signature required for regulated deliveries/pickups. Always on in production (no env switch); pre-0.15 tests turn it off.
    'require_signature' => true,
    'company' => env('ERP_COMPANY', 'Vega Distribution LLC'),
    'home_state' => env('ERP_HOME_STATE', 'KY'),
    'authorizenet' => [
        // SANDBOX ONLY. Keys can also be entered in Admin > Settings (stored encrypted; DB values win).
        'login_id' => env('AUTHNET_LOGIN_ID'),
        'transaction_key' => env('AUTHNET_TRANSACTION_KEY'),
        'client_key' => env('AUTHNET_CLIENT_KEY'),
        'environment' => env('AUTHNET_ENV', 'sandbox'),
    ],
    'rules_hub' => [
        // Signed rules package (static JSON). Only a GET with install_id + app_version is sent; never customer data.
        'url' => env('RULES_HUB_URL'),
        'public_key' => env('RULES_HUB_PUBLIC_KEY'), // base64 Ed25519 public key
        'timeout' => 20,
    ],

    /*
     | Role permissions. owner, admin and brandworx_admin always have full access.
     | module => [ability => [roles]]
     */
    'full_roles' => ['owner', 'admin', 'brandworx_admin'],
    'permissions' => [
        'customer_prices' => ['view' => ['sales'], 'manage' => []], // 0.19 customer price overrides: create/edit owner+admin only
        'marketing' => ['view' => [], 'manage' => []],            // 0.19: GHL add-on, owner+admin only
        'products' => ['view' => ['sales', 'warehouse'], 'manage' => []],
        'warehouses' => ['view' => ['warehouse'], 'manage' => []],
        'inventories' => ['view' => ['warehouse'], 'manage' => []],
        'stock_movements' => ['view' => ['warehouse'], 'manage' => []],
        'customers' => ['view' => ['sales'], 'manage' => ['sales']],
        'ship_tos' => ['view' => ['sales'], 'manage' => ['sales']],
        'customer_licenses' => ['view' => ['sales'], 'manage' => ['sales']],
        'sales_orders' => ['view' => ['sales'], 'manage' => ['sales']],
        'payments' => ['view' => ['sales'], 'manage' => []],
        'pos' => ['view' => ['sales'], 'manage' => ['sales']],
        'purchase_orders' => ['view' => ['warehouse'], 'manage' => [], 'receive' => ['warehouse'], 'update_tracking' => ['warehouse']], // 0.20.2: inbound shipping fields only
        'vendors' => ['view' => [], 'manage' => []],
        'delivery_manifests' => ['view' => ['warehouse', 'driver'], 'manage' => ['warehouse', 'driver']],
        // 0.17 imports: owners/admins only by default; add roles here to delegate (e.g. 'manage' => ['warehouse']).
        'imports' => ['view' => [], 'manage' => []],
        'driver_app' => ['view' => ['driver', 'warehouse'], 'manage' => ['driver']],   // 0.15 driver phone screen
        'van_sales' => ['view' => ['driver'], 'manage' => ['driver']],
        'pickup_signature' => ['view' => ['warehouse', 'sales'], 'manage' => ['warehouse', 'sales']], // will-call signature
        'buydowns' => ['view' => [], 'manage' => []],
        'compliance_rules' => ['view' => [], 'manage' => []],
        'accounts' => ['view' => [], 'manage' => []],
        'journals' => ['view' => [], 'manage' => []],
        'users' => ['view' => [], 'manage' => []],
        'tax_rates' => ['view' => [], 'manage' => []],
        'tax_filings' => ['view' => [], 'manage' => []],
        'reports' => ['view' => [], 'manage' => []],
        'credit_memos' => ['view' => ['sales'], 'manage' => ['sales']],
        'card_payments' => ['view' => ['sales'], 'manage' => ['sales']],
        'refunds' => ['view' => [], 'manage' => []],
        'rules_hub' => ['view' => [], 'manage' => []],
        // Phase 4 catalog. "Standard users" = sales + warehouse: catalog view/labels/exports + research + catalog reports.
        'catalog' => ['view' => ['sales', 'warehouse'], 'manage' => []],
        'research' => ['view' => ['sales', 'warehouse'], 'manage' => []],
        'departments' => ['view' => ['sales', 'warehouse'], 'manage' => []],
        'company' => ['view' => [], 'manage' => []],
        // Phase 6 management reports + dashboard. Financial reports (AR, statements, margin, tax, payments) stay admin-level ('reports').
        'sales_reports' => ['view' => ['sales'], 'manage' => []],
        'inventory_reports' => ['view' => ['warehouse'], 'manage' => []],
        'kpi_sales' => ['view' => ['sales'], 'manage' => []],
        'kpi_ar' => ['view' => ['sales'], 'manage' => []],
        'kpi_orders' => ['view' => ['sales', 'warehouse', 'driver'], 'manage' => []],
        'kpi_inventory' => ['view' => ['warehouse', 'sales'], 'manage' => []],
        // Phase 7 MSA MULTICAT weekly file + settings + fix list: admin-level only.
        'msa' => ['view' => [], 'manage' => []],
        // Phase 8 accounting: AP, bank reconciliation, financial statements, period close, dunning. Admin-level only.
        'ap' => ['view' => [], 'manage' => []],
        'bank' => ['view' => [], 'manage' => []],
        'financials' => ['view' => [], 'manage' => []],
        'periods' => ['view' => [], 'manage' => []],
        'dunning' => ['view' => [], 'manage' => []],
        // Phase 9 warehouse scanning (receive / put-away / pick / pack / print lists) and bins.
        'warehouse_scan' => ['view' => ['warehouse'], 'manage' => ['warehouse']],
        'bins' => ['view' => ['warehouse'], 'manage' => ['warehouse']],
        'lots' => ['view' => ['warehouse', 'sales'], 'manage' => []],          // lots, expiry alerts, recall look-up; hold = admin
        'counts' => ['view' => ['warehouse'], 'manage' => ['warehouse']],      // create/scan/submit; approve + post = admin
        'reorder' => ['view' => ['warehouse'], 'manage' => []],              // suggestions; creating draft POs = admin
        'pact' => ['view' => [], 'manage' => []],                            // PACT registrations + monthly reports (admin)
        'msa_directory' => ['view' => ['sales'], 'manage' => []],             // state directory lists; import = admin
        'carriers' => ['view' => ['warehouse'], 'manage' => []],
        'shipping' => ['view' => ['warehouse', 'sales'], 'manage' => ['warehouse']], // carton carrier + tracking + labels
        'warehouse_events' => ['view' => [], 'manage' => []],                 // short-pick / over-receipt log (admin)
        // Phase 14 pricing: sales can view price lists/promotions; maintaining them, rebate claims and the override log are admin-level.
        'pricing' => ['view' => ['sales'], 'manage' => []],
        'rebate_claims' => ['view' => [], 'manage' => []],
        'price_overrides' => ['view' => [], 'manage' => []],
        'kpi_finance' => ['view' => [], 'manage' => []], // excise, margin-level figures // branding + public quick-tools link (admin-level)
    ],
];
