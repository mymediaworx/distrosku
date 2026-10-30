<?php

namespace App\Filament\Pages;

use App\Catalog\ImageService;
use App\Models\Company;
use App\Models\Setting;
use App\Support\PortalSettings;
use Filament\Actions\Action;
use App\Reports\KyTobaccoXml;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Company Settings (0.20.17; was Settings > Settings). Owner / admin / Brandworx admin open it. One place for: company profile + defaults,
 * Brand (logo), Employee Quick Tools link, payment gateway, B2B Customer Portal, warehouse, compliance, integrations.
 * Settings values are stored encrypted (settings table, APP_KEY). Secrets are write-only here and only owner / Brandworx admin see them.
 */
class Settings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string $view = 'filament.pages.settings';

    protected static ?string $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Company Settings';

    protected static ?string $title = 'Company Settings';

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $slug = 'settings';

    public const SECRETS = ['authnet_login_id', 'authnet_transaction_key', 'dba_secret'];

    public ?array $data = [];

    public static function canAccess(): bool
    {
        $u = auth()->user();

        return $u !== null && $u->isAdmin();
    }

    /** Gateway keys, the instance-manager secret and the Rules Hub stay owner / Brandworx admin only (as before 0.20.17). */
    public static function canSeeSecrets(): bool
    {
        return (bool) auth()->user()?->hasRole('owner', 'brandworx_admin');
    }

    public const COMPANY_FIELDS = ['company_name' => 'name', 'company_address_line1' => 'address_line1', 'company_address_line2' => 'address_line2',
        'company_city' => 'city', 'company_state' => 'state', 'company_postal_code' => 'postal_code', 'company_phone' => 'phone', 'company_email' => 'email'];

    public function mount(): void
    {
        $vals = ['authnet_client_key' => Setting::get('authnet_client_key'), 'rules_hub_url' => Setting::get('rules_hub_url'),
            'rules_hub_public_key' => Setting::get('rules_hub_public_key'),
            'over_receipt_pct' => Setting::get('over_receipt_pct', 0), 'lot_expiry_alert_days' => Setting::get('lot_expiry_alert_days', 30),
            'reorder_velocity_days' => Setting::get('reorder_velocity_days', 30), 'reorder_default_lead_days' => Setting::get('reorder_default_lead_days', 7),
            'reorder_cover_days' => Setting::get('reorder_cover_days', 14),
            'count_auto_approve_value' => Setting::get('count_auto_approve_value', 25), 'count_auto_approve_pct' => Setting::get('count_auto_approve_pct', 2),
            'pact_interstate_enabled' => (bool) Setting::get('pact_interstate_enabled'), 'msa_directory_strict' => (bool) Setting::get('msa_directory_strict'), 'coa_expiry_warn_days' => Setting::get('coa_expiry_warn_days', 30),
            'pos_hold_days' => Setting::get('pos_hold_days', 7), 'allow_negative_inventory_sales' => \App\Inventory\NegativeStock::allowed(),
            // #43: sales tax (off by default)
            'sales_tax_enabled' => \App\Tax\SalesTax::enabled(), 'sales_tax_rate' => \App\Tax\SalesTax::rate(), 'sales_tax_name' => \App\Tax\SalesTax::name(), 'sales_tax_state' => \App\Tax\SalesTax::state(),
            'cycle_plan_enabled' => \App\Warehouse\CycleCountPlanner::enabled(), 'cycle_plan_frequency' => \App\Warehouse\CycleCountPlanner::frequency(),
            'cycle_plan_weekday' => (string) \App\Warehouse\CycleCountPlanner::weekday(), 'cycle_plan_size' => \App\Warehouse\CycleCountPlanner::size(),
            'cycle_plan_high_risk_classes' => implode(', ', \App\Warehouse\CycleCountPlanner::highRiskClasses()), 'cycle_plan_high_risk_departments' => (string) Setting::get('cycle_plan_high_risk_departments'),
            'lot_track_departments' => \App\Warehouse\LotTracking::departments(), 'lot_track_tax_classes' => \App\Warehouse\LotTracking::taxClasses(), 'lot_track_apply_existing' => false];
        foreach (array_keys(KyTobaccoXml::filerSettingKeys()) as $k) {
            $vals[$k] = Setting::get($k);
        }
        $c = Company::current();
        foreach (self::COMPANY_FIELDS as $field => $col) {
            $vals[$field] = $c->{$col};
        }
        $vals['logo_path'] = $c->logo_path;
        if (static::canSeeSecrets()) { // 0.20.18: Email tab. The password is write-only: never filled back into the form.
            $m = \App\Mailing\MailConfig::stored();
            $vals += ['mail_host' => $m['mail_host'], 'mail_port' => $m['mail_port'], 'mail_encryption' => $m['mail_encryption'] ?: 'tls', 'mail_username' => $m['mail_username'],
                'mail_from_address' => $m['mail_from_address'], 'mail_from_name' => $m['mail_from_name'], 'dunning_auto_email' => \App\Accounting\DunningMailer::enabled()];
        }
        $vals['portal_enabled'] = PortalSettings::enabled();
        $vals['portal_disabled_message'] = Setting::get('portal_disabled_message');
        $this->form->fill($vals);
    }

    public function form(Form $form): Form
    {
        $set = fn ($k) => Setting::get($k) ? 'Saved (hidden). Leave blank to keep.' : 'Not set.';
        $filer = collect(KyTobaccoXml::filerSettingKeys())->map(fn ($label, $k) => Forms\Components\TextInput::make($k)->label($label))->values()->all();

        $secrets = static::canSeeSecrets();
        $portalUrl = url('/portal');
        $contacts = \App\Models\CustomerContact::query()->where('is_portal_contact', true)->count();

        return $form->statePath('data')->schema([
            Forms\Components\Tabs::make('settings')->persistTabInQueryString()->tabs(array_values(array_filter([
                Forms\Components\Tabs\Tab::make('Company')->icon('heroicon-o-building-office-2')->schema([
                    Forms\Components\Section::make('Company profile and defaults')->description('Printed in the header of statements, invoices and other documents.')->schema([
                        Forms\Components\TextInput::make('company_name')->label('Company name')->required()->maxLength(255),
                        Forms\Components\TextInput::make('company_phone')->label('Phone')->tel()->maxLength(32),
                        Forms\Components\TextInput::make('company_email')->label('Email')->email()->maxLength(255),
                        Forms\Components\TextInput::make('company_address_line1')->label('Address line 1')->maxLength(255),
                        Forms\Components\TextInput::make('company_address_line2')->label('Address line 2')->maxLength(255),
                        Forms\Components\TextInput::make('company_city')->label('City')->maxLength(255),
                        Forms\Components\TextInput::make('company_state')->label('State')->maxLength(8),
                        Forms\Components\TextInput::make('company_postal_code')->label('ZIP')->maxLength(16),
                    ])->columns(3),
                    Forms\Components\Section::make('Brand')->description('Logo shown on documents and in the quick-tools pages.')->schema([
                        Forms\Components\FileUpload::make('logo_path')->label('Logo')->image()->disk('public')->directory('branding')
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])->maxSize(ImageService::MAX_KB),
                    ]),
                    Forms\Components\Section::make('Employee Quick Tools link (no login)')->description('Anyone with this link can create and look up SKUs and print labels. Regenerate it if it leaks.')->schema([
                        Forms\Components\Placeholder::make('quick_link')->label('Link')
                            ->content(fn () => Company::current()->publicUrl() ?? 'No link yet. Use "Generate quick-tools link" at the top of the page.')
                            ->extraAttributes(['class' => 'break-all font-mono', 'data-test' => 'quick-link']),
                    ]),
                ]),
                $secrets ? Forms\Components\Tabs\Tab::make('Payment gateway')->icon('heroicon-o-credit-card')->schema([
                    Forms\Components\Section::make('Authorize.net (SANDBOX only)')->description('Live processing is disabled in this build. Env AUTHNET_ENV must be "sandbox".')
                        ->schema([
                            Forms\Components\TextInput::make('authnet_login_id')->label('API Login ID')->password()->revealable(false)->helperText($set('authnet_login_id')),
                            Forms\Components\TextInput::make('authnet_transaction_key')->label('Transaction Key')->password()->revealable(false)->helperText($set('authnet_transaction_key')),
                            Forms\Components\TextInput::make('authnet_client_key')->label('Public Client Key (Accept.js)'),
                        ])->columns(3),
                ]) : null,
                $secrets ? Forms\Components\Tabs\Tab::make('Email')->icon('heroicon-o-envelope')->schema([
                    Forms\Components\Section::make('Outgoing email (SMTP)')->description('Used for dunning notices and any other email the ERP sends. Saved here, these values win over the server\'s .env MAIL_* settings; leave the host blank to use .env. The password is stored encrypted and is never shown again.')->schema([
                        Forms\Components\TextInput::make('mail_host')->label('SMTP host')->maxLength(190)->placeholder('mail.example.com'),
                        Forms\Components\TextInput::make('mail_port')->label('Port')->numeric()->minValue(1)->maxValue(65535)->helperText('SSL: 465. TLS: 587.'),
                        Forms\Components\Select::make('mail_encryption')->label('Encryption')->options(\App\Mailing\MailConfig::ENCRYPTIONS)->native(false)->selectablePlaceholder(false),
                        Forms\Components\TextInput::make('mail_username')->label('Username')->maxLength(190)->autocomplete('off'),
                        Forms\Components\TextInput::make('mail_password')->label(fn () => \App\Mailing\MailConfig::passwordIsSet() ? 'Replace password' : 'Password')->password()->revealable(false)->autocomplete('new-password')
                            ->helperText(fn () => \App\Mailing\MailConfig::passwordIsSet() ? 'A password is set. Leave blank to keep it.' : 'No password saved.'),
                        Forms\Components\TextInput::make('mail_from_address')->label('From address')->email()->maxLength(190),
                        Forms\Components\TextInput::make('mail_from_name')->label('From name')->maxLength(190)->helperText('Shown as the sender, e.g. your company name.'),
                    ])->columns(3),
                    Forms\Components\Section::make('Dunning email')->description('Each morning at 07:00 the ERP emails overdue customers their notice with the statement PDF attached. Needs the normal scheduler cron. Customers with no email, or opted out, go to the print list on the Dunning page.')->schema([
                        Forms\Components\Toggle::make('dunning_auto_email')->label('Auto-email dunning')
                            ->helperText('Off by default. Turning it on only sends notices created from then on. Use "Send test email" first.'),
                    ]),
                ]) : null,
                Forms\Components\Tabs\Tab::make('B2B Customer Portal')->icon('heroicon-o-globe-alt')->schema([
                    Forms\Components\Section::make('B2B Customer Portal')->description("Portal address: {$portalUrl}. {$contacts} contact(s) have portal access (set per customer contact; no emails are sent, a one-time password link is issued by an admin).")->schema([
                        Forms\Components\Toggle::make('portal_enabled')->label('Portal enabled')->helperText('Off = customers see a notice instead of the portal. Orders and data are untouched.'),
                        Forms\Components\Textarea::make('portal_disabled_message')->label('Message shown when the portal is off')->rows(2)->maxLength(500),
                    ]),
                    Forms\Components\Section::make('Payments in the portal')->schema([
                        Forms\Components\Placeholder::make('portal_pay')->label('Card payments')
                            ->content(fn () => Setting::get('authnet_login_id') && Setting::get('authnet_transaction_key') ? 'Gateway keys are saved (see Payment gateway).' : 'Gateway keys are not set; the portal can show invoices but cannot take card payments.'),
                    ]),
                ]),
                // #43: state sales tax + exemption certificates. OFF until the owner turns it on.
                Forms\Components\Tabs\Tab::make('Sales tax')->icon('heroicon-o-receipt-percent')->schema([
                    Forms\Components\Section::make('Sales tax on invoices')->description('Off by default. When on, every sale to a customer WITHOUT a current resale or exemption certificate (Customers > Licenses: type Resale Certificate or Sales Tax Exemption Certificate, with a number or document, not expired) gets this tax on top of the price and the product\'s own taxes. It shows on the invoice and is owed on account 2200 Sales Tax Payable. Walk-in / cash sales always pay it. Posted invoices are not changed.')->schema([
                        Forms\Components\Toggle::make('sales_tax_enabled')->label('Charge sales tax')->live(),
                        Forms\Components\TextInput::make('sales_tax_rate')->label('Rate (%)')->numeric()->minValue(0)->maxValue(99.999)->required(fn (Forms\Get $get) => (bool) $get('sales_tax_enabled')),
                        Forms\Components\TextInput::make('sales_tax_name')->label('Name on the invoice')->maxLength(40)->placeholder('KY sales tax'),
                        Forms\Components\TextInput::make('sales_tax_state')->label('State (certificates for this state count)')->maxLength(2)->placeholder('KY'),
                    ])->columns(4),
                ]),
                Forms\Components\Tabs\Tab::make('Warehouse')->icon('heroicon-o-archive-box')->schema([
                    Forms\Components\Section::make('Warehouse')->schema([
                        Forms\Components\TextInput::make('over_receipt_pct')->label('Global over-receipt allowance %')->numeric()->minValue(0)->maxValue(100)
                            ->helperText('0 = refuse over-receipts. A PO can override this. Every over-receipt is logged.'),
                        Forms\Components\TextInput::make('lot_expiry_alert_days')->label('Expiry alert window (days)')->integer()->minValue(1),
                        Forms\Components\TextInput::make('reorder_velocity_days')->label('Reorder: sales velocity window (days)')->integer()->minValue(1),
                        Forms\Components\TextInput::make('reorder_default_lead_days')->label('Reorder: default lead time (days)')->integer()->minValue(0),
                        Forms\Components\TextInput::make('reorder_cover_days')->label('Reorder: days of cover to order')->integer()->minValue(0),
                        Forms\Components\TextInput::make('count_auto_approve_value')->label('Count: auto-approve variance up to $ per line')->numeric()->minValue(0)
                            ->helperText('A submitted count posts automatically when every line is within this value OR the % below. Set both to 0 to always need an admin. Auto-approvals are logged in Warehouse events.'),
                        Forms\Components\TextInput::make('pos_hold_days')->label('POS: held sales expire after (days)')->integer()->minValue(1)->maxValue(90)
                            ->helperText('Held (parked) POS sales are removed after this many days; each removal is logged.'),
                        Forms\Components\TextInput::make('count_auto_approve_pct')->label('Count: auto-approve variance up to % of expected per line')->numeric()->minValue(0)->maxValue(100),
                    ])->columns(2),
                    Forms\Components\Section::make('Selling stock you do not have yet')->schema([
                        Forms\Components\Toggle::make('allow_negative_inventory_sales')->label('Allow negative inventory sales')
                            ->helperText('OFF (default) = a sale is refused when there is not enough stock. ON = orders, POS and van sales can go through and on-hand drops below zero until the next receipt or count. Every change is logged.'),
                    ]),
                    Forms\Components\Section::make('Cycle count plan')->description('Builds the day\'s (or week\'s) cycle count tasks for the Warehouse hub. Counts post through the normal count approval; nothing here changes stock by itself.')->schema([
                        Forms\Components\Toggle::make('cycle_plan_enabled')->label('Generate cycle count tasks'),
                        Forms\Components\Select::make('cycle_plan_frequency')->label('Frequency')->options(['daily' => 'Daily', 'weekly' => 'Weekly'])->native(false)->required(),
                        Forms\Components\Select::make('cycle_plan_weekday')->label('Weekly run day')->options([1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'])->native(false),
                        Forms\Components\TextInput::make('cycle_plan_size')->label('Items per day (x5 when weekly)')->integer()->minValue(1)->maxValue(500),
                        Forms\Components\TextInput::make('cycle_plan_high_risk_classes')->label('High-risk tax classes (comma list)')->helperText('Counted at least every 14 days. Default: cigarette_stamped, snuff_chew, vapor.'),
                        Forms\Components\TextInput::make('cycle_plan_high_risk_departments')->label('High-risk departments (comma list of names)'),
                    ])->columns(2),
                    Forms\Components\Section::make('Lot tracking by product type')->description('New products in these departments or tax classes are lot-tracked automatically (a product can still be switched off). Receiving also has a "Track lots for this item" box.')
                        ->schema([
                            Forms\Components\CheckboxList::make('lot_track_departments')->label('Departments')->options(fn () => \App\Models\Department::orderBy('name')->pluck('name', 'id')->all())->columns(3),
                            Forms\Components\CheckboxList::make('lot_track_tax_classes')->label('Tax classes')
                                ->options(collect(\App\Enums\TaxClass::cases())->mapWithKeys(fn ($c) => [$c->value => method_exists($c, 'label') ? $c->label() : $c->value])->all())->columns(3),
                            Forms\Components\Toggle::make('lot_track_apply_existing')->label('Also switch tracking on for existing products in these types (never switches it off)'),
                        ])->collapsible(),
                ]),
                Forms\Components\Tabs\Tab::make('Compliance')->icon('heroicon-o-shield-check')->schema([
                    Forms\Components\Section::make('PACT Act and MSA directories')->schema([
                        Forms\Components\Toggle::make('pact_interstate_enabled')->label('Allow out-of-state PACT shipments (needs current ATF + state PACT registrations)')
                            ->helperText('Off = PACT items cannot ship outside the home state (previous behaviour).'),
                        Forms\Components\Toggle::make('msa_directory_strict')->label('Block cigarette / RYO sales into states with no directory list loaded'),
                        Forms\Components\TextInput::make('coa_expiry_warn_days')->label('COA: warn this many days before expiry')->integer()->minValue(0)->maxValue(365),
                    ]),
                    Forms\Components\Section::make('KY filer (EDI XML header)')->schema($filer)->columns(3),
                ]),
                $secrets ? Forms\Components\Tabs\Tab::make('Integrations')->icon('heroicon-o-puzzle-piece')->schema([
                    Forms\Components\Section::make('Instance manager')->description('Links this ERP to the DistroSKU instance manager (dba.distrosku.com). Paste the shared secret shown once when the instance was registered.')
                        ->schema([
                            Forms\Components\TextInput::make('dba_secret')->label('Shared secret')->password()->revealable(false)->helperText($set('dba_secret'))
                                ->rule('nullable')->rule('min:32'),
                        ]),
                    Forms\Components\Section::make('Rules Hub')->schema([
                        Forms\Components\TextInput::make('rules_hub_url')->label('Package URL')->url(),
                        Forms\Components\Textarea::make('rules_hub_public_key')->label('Ed25519 public key (base64)')->rows(2),
                    ])->columns(2),
                ]) : null,
            ]))),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('test_email')->label('Send test email')->icon('heroicon-o-paper-airplane')->color('gray')
            ->visible(fn () => static::canSeeSecrets())
            ->modalDescription('Sends one message using the email settings as last saved (save first if you just changed them).')
            ->form([Forms\Components\TextInput::make('to')->label('Send to')->email()->required()->maxLength(190)])
            ->action(function (array $data) {
                abort_unless(static::canSeeSecrets(), 403);
                [$ok, $msg] = \App\Mailing\MailConfig::sendTest((string) $data['to']);
                $n = Notification::make()->title($ok ? 'Test email sent' : 'Test email failed')->body($msg);
                $ok ? $n->success()->send() : $n->danger()->persistent()->send();
            }),
            Action::make('token')->label(fn () => Company::current()->public_token ? 'Regenerate quick-tools link' : 'Generate quick-tools link')->icon('heroicon-o-link')
            ->requiresConfirmation()->modalDescription('Regenerating disables the old link immediately.')
            ->action(function () {
                Company::current()->rotateToken();
                Notification::make()->success()->title('New quick-tools link generated')->send();
            })];
    }

    public function save(): void
    {
        try {
            $d = $this->form->getState();
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->data['mail_password'] = null; // never echo the typed password back
            throw $e;
        }
        $changed = null;
        $mailKeys = array_merge(\App\Mailing\MailConfig::KEYS, ['dunning_auto_email']);
        if (static::canSeeSecrets() && array_key_exists('mail_host', $d)) { // 0.20.18 Email tab (owner / Brandworx admin only)
            \App\Mailing\MailConfig::save($d);
            if (array_key_exists('dunning_auto_email', $d)) {
                $before = \App\Accounting\DunningMailer::enabled();
                \App\Accounting\DunningMailer::setEnabled((bool) $d['dunning_auto_email']);
                if ($before !== (bool) $d['dunning_auto_email']) {
                    \App\Audit\Audit::action('dunning_auto_email', null, 'Auto-email dunning turned '.($d['dunning_auto_email'] ? 'ON' : 'OFF'));
                }
            }
        }
        $d = array_diff_key($d, array_flip($mailKeys)); // handled above (or not allowed for this user): never through the generic loop
        if (array_key_exists('lot_track_departments', $d)) { // 0.20.15 (#36): lists, not scalar settings
            \App\Warehouse\LotTracking::save($d['lot_track_departments'] ?? [], $d['lot_track_tax_classes'] ?? []);
            $changed = ! empty($d['lot_track_apply_existing']) ? \App\Warehouse\LotTracking::applyToExisting() : null;
            $d['lot_track_apply_existing'] = false;
        }
        if (array_key_exists('company_name', $d)) { // 0.20.17: Company profile + Brand live here now
            $company = [];
            foreach (self::COMPANY_FIELDS as $field => $col) {
                $company[$col] = filled($d[$field] ?? null) ? trim((string) $d[$field]) : ($col === 'name' ? Company::current()->name : null);
            }
            $company['logo_path'] = app(ImageService::class)->normalizeStored($d['logo_path'] ?? null, 'branding');
            Company::current()->update($company);
            $d = array_diff_key($d, self::COMPANY_FIELDS, ['logo_path' => 1]);
        }
        if (array_key_exists('allow_negative_inventory_sales', $d)) { // 0.20.20: logged setting, stored via NegativeStock (not the generic loop)
            $before = \App\Inventory\NegativeStock::allowed();
            \App\Inventory\NegativeStock::set((bool) $d['allow_negative_inventory_sales']);
            if ($before !== (bool) $d['allow_negative_inventory_sales']) {
                \App\Audit\Audit::action('negative_inventory_sales', null, 'Allow negative inventory sales turned '.($d['allow_negative_inventory_sales'] ? 'ON' : 'OFF'));
            }
            unset($d['allow_negative_inventory_sales']);
        }
        if (array_key_exists('sales_tax_enabled', $d)) { // #43: a money setting: every change is logged
            $before = [\App\Tax\SalesTax::enabled(), \App\Tax\SalesTax::rate()];
            $after = [(bool) $d['sales_tax_enabled'], (string) ($d['sales_tax_rate'] ?? $before[1])];
            if ($before[0] !== $after[0] || ($after[0] && (float) $before[1] !== (float) $after[1])) {
                \App\Audit\Audit::action('sales_tax_setting', null, 'Sales tax '.($after[0] ? 'ON at '.$after[1].'%' : 'OFF'), ['before' => $before, 'after' => $after]);
            }
        }
        $lists = array_intersect_key($d, array_flip(['lot_track_departments', 'lot_track_tax_classes', 'lot_track_apply_existing']));
        $d = array_diff_key($d, $lists);
        foreach ($d as $k => $v) {
            if (in_array($k, self::SECRETS, true) && ($v === null || $v === '')) {
                continue; // write-only: blank keeps the stored secret
            }
            Setting::put($k, is_bool($v) ? ($v ? '1' : '0') : ($v === null ? null : trim((string) $v)));
        }
        $this->mount(); // reload from storage; secrets stay blank (write-only)
        Notification::make()->success()->title('Settings saved (encrypted)')->body($changed !== null ? "Lot tracking switched on for {$changed} existing product(s)." : null)->send();
    }
}
