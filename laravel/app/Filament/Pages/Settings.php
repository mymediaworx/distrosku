<?php

namespace App\Filament\Pages;

use App\Models\Setting;
use App\Reports\KyTobaccoXml;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/** Owner / Brandworx admin only. All values stored encrypted (settings table, APP_KEY). Secrets are write-only here. */
class Settings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string $view = 'filament.pages.settings';

    protected static ?string $navigationGroup = 'Admin';

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $slug = 'settings';

    public const SECRETS = ['authnet_login_id', 'authnet_transaction_key'];

    public ?array $data = [];

    public static function canAccess(): bool
    {
        $u = auth()->user();

        return $u !== null && $u->hasRole('owner', 'brandworx_admin');
    }

    public function mount(): void
    {
        $vals = ['authnet_client_key' => Setting::get('authnet_client_key'), 'rules_hub_url' => Setting::get('rules_hub_url'),
            'rules_hub_public_key' => Setting::get('rules_hub_public_key'),
            'over_receipt_pct' => Setting::get('over_receipt_pct', 0), 'lot_expiry_alert_days' => Setting::get('lot_expiry_alert_days', 30),
            'reorder_velocity_days' => Setting::get('reorder_velocity_days', 30), 'reorder_default_lead_days' => Setting::get('reorder_default_lead_days', 7),
            'reorder_cover_days' => Setting::get('reorder_cover_days', 14),
            'count_auto_approve_value' => Setting::get('count_auto_approve_value', 25), 'count_auto_approve_pct' => Setting::get('count_auto_approve_pct', 2),
            'pact_interstate_enabled' => (bool) Setting::get('pact_interstate_enabled'), 'msa_directory_strict' => (bool) Setting::get('msa_directory_strict'), 'coa_expiry_warn_days' => Setting::get('coa_expiry_warn_days', 30),
            'pos_hold_days' => Setting::get('pos_hold_days', 7)];
        foreach (array_keys(KyTobaccoXml::filerSettingKeys()) as $k) {
            $vals[$k] = Setting::get($k);
        }
        $this->form->fill($vals);
    }

    public function form(Form $form): Form
    {
        $set = fn ($k) => Setting::get($k) ? 'Saved (hidden). Leave blank to keep.' : 'Not set.';
        $filer = collect(KyTobaccoXml::filerSettingKeys())->map(fn ($label, $k) => Forms\Components\TextInput::make($k)->label($label))->values()->all();

        return $form->statePath('data')->schema([
            Forms\Components\Section::make('Authorize.net (SANDBOX only)')->description('Live processing is disabled in this build. Env AUTHNET_ENV must be "sandbox".')
                ->schema([
                    Forms\Components\TextInput::make('authnet_login_id')->label('API Login ID')->password()->revealable(false)->helperText($set('authnet_login_id')),
                    Forms\Components\TextInput::make('authnet_transaction_key')->label('Transaction Key')->password()->revealable(false)->helperText($set('authnet_transaction_key')),
                    Forms\Components\TextInput::make('authnet_client_key')->label('Public Client Key (Accept.js)'),
                ])->columns(3),
            Forms\Components\Section::make('Rules Hub')->schema([
                Forms\Components\TextInput::make('rules_hub_url')->label('Package URL')->url(),
                Forms\Components\Textarea::make('rules_hub_public_key')->label('Ed25519 public key (base64)')->rows(2),
            ])->columns(2),
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
            Forms\Components\Section::make('PACT Act and MSA directories')->schema([
                Forms\Components\Toggle::make('pact_interstate_enabled')->label('Allow out-of-state PACT shipments (needs current ATF + state PACT registrations)')
                    ->helperText('Off = PACT items cannot ship outside the home state (previous behaviour).'),
                Forms\Components\Toggle::make('msa_directory_strict')->label('Block cigarette / RYO sales into states with no directory list loaded'),
                Forms\Components\TextInput::make('coa_expiry_warn_days')->label('COA: warn this many days before expiry')->integer()->minValue(0)->maxValue(365),
            ]),
            Forms\Components\Section::make('KY filer (EDI XML header)')->schema($filer)->columns(3),
        ]);
    }

    public function save(): void
    {
        $d = $this->form->getState();
        foreach ($d as $k => $v) {
            if (in_array($k, self::SECRETS, true) && ($v === null || $v === '')) {
                continue; // write-only: blank keeps the stored secret
            }
            Setting::put($k, $v === null ? null : trim((string) $v));
        }
        $this->form->fill(array_merge($d, array_fill_keys(self::SECRETS, null)));
        Notification::make()->success()->title('Settings saved (encrypted)')->send();
    }
}
