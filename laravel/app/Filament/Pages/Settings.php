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
            'pos_hold_days' => Setting::get('pos_hold_days', 7),
            'lot_track_departments' => \App\Warehouse\LotTracking::departments(), 'lot_track_tax_classes' => \App\Warehouse\LotTracking::taxClasses(), 'lot_track_apply_existing' => false];
        foreach (array_keys(KyTobaccoXml::filerSettingKeys()) as $k) {
            $vals[$k] = Setting::get($k);
        }
        $c = Company::current();
        foreach (self::COMPANY_FIELDS as $field => $col) {
            $vals[$field] = $c->{$col};
        }
        $vals['logo_path'] = $c->logo_path;
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
        return [Action::make('token')->label(fn () => Company::current()->public_token ? 'Regenerate quick-tools link' : 'Generate quick-tools link')->icon('heroicon-o-link')
            ->requiresConfirmation()->modalDescription('Regenerating disables the old link immediately.')
            ->action(function () {
                Company::current()->rotateToken();
                Notification::make()->success()->title('New quick-tools link generated')->send();
            })];
    }

    public function save(): void
    {
        $d = $this->form->getState();
        $changed = null;
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
