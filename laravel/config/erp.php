<?php

return [
    'version' => '0.20.21',
    // 0.20.21: walk-in / cash customers (CASH or flagged "Walk-in / cash") cannot buy regulated products (tobacco, vapor, CBD/hemp, kratom): POS, van, sales orders, portal, picker.
    // 0.20.20: customer search picker (no default customer), TV mode removed, wider order lines, Marketing label, taxes on invoices, product Duplicate, negative-inventory setting, Quick Tools counts.
    // 0.20.19: smart product search (every word, any order, punctuation ignored, abbreviations from config/product_search.php, ranked results with price + pack unit; also Products table and global search).
    // 0.20.16: 0.20.15 + test suite brought in line with 0.20.13 behaviour (no app code changes).
    // 0.20.15: lot traceability (#36) — GS1 receiving, COA per lot, tracking by product type, lot on sale lines, Needs review, Trace.
    // 0.20.14: customer Code → Account Number label (column unchanged). Includes 0.20.13: KY Site ID, DBA, Customer Contacts / portal flag, mid-form tabs, beginning balance 2dp, special-price lookup.
    // 0.20.12: admin tables offer 10/25/50/100 per page (no 'All'); big-table selects search instead of preloading.
    // 0.20.11 (#26): an offline sale goes to Needs review when a line is priced below cost, or more than this % below the
    // current price, or when the device didn't send the age of its saved prices. Owner/admin can approve and complete it.
    'offline_sales' => [
        'max_below_price_pct' => (float) env('OFFLINE_SALE_MAX_BELOW_PRICE_PCT', 20),
    ],
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
    // 0.20.8: instance manager (dba.distrosku.com). The shared secret is entered in Settings (stored encrypted) or ERP_DBA_SECRET.
    'dba' => [
        'secret' => env('ERP_DBA_SECRET'),
        'instance' => env('ERP_INSTANCE'), // defaults to the APP_URL host
        'backup_path' => env('ERP_BACKUP_PATH'), // defaults to ~/erp-backups/<instance> (outside every web root)
        'window' => 300,
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
        'quick_counts' => ['view' => ['warehouse'], 'manage' => []],         // 0.20.20: counts typed on the Quick Tools page; applying = admin
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
