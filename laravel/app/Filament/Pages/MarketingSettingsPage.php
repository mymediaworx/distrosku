<?php

namespace App\Filament\Pages;

use App\Audit\Audit;
use App\Marketing\GhlClient;
use App\Marketing\MarketingSettings;
use App\Marketing\MarketingSync;
use App\Models\Customer;
use App\Models\Setting;
use App\Models\TransactionalTemplate;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/** 0.19: BrandWorx Marketing add-on setup (owner/admin). The token is write-only: never shown back, only "ends …abcd". */
class MarketingSettingsPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationGroup = 'Settings';

    protected static ?string $navigationIcon = 'heroicon-o-megaphone';

    protected static ?string $navigationLabel = 'BrandWorx Marketing';

    protected static ?string $title = 'BrandWorx Marketing';

    protected static ?string $slug = 'brandworx-marketing';

    protected static string $view = 'filament.pages.settings';

    public ?array $data = [];

    public ?array $probe = null;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->canModule('marketing', 'manage');
    }

    public function mount(): void
    {
        $this->form->fill([
            'token' => null, 'location_id' => MarketingSettings::locationId(), 'app_url' => MarketingSettings::appUrl(),
            'webhook_url' => MarketingSettings::webhookUrl(), 'enabled' => Setting::get('mkt_enabled') === '1', 'notify' => Setting::get('mkt_notify_enabled') === '1',
            'cf' => array_map(fn ($v) => $v === '' ? '-' : $v, \App\Marketing\MarketingFields::mapping()),
            'templates' => TransactionalTemplate::orderBy('key')->orderBy('channel')->get()->map(fn ($t) => $t->only(['id', 'key', 'channel', 'subject', 'body', 'enabled']))->all(),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form->statePath('data')->schema([
            Forms\Components\Section::make('Connection')->columns(2)->schema([
                Forms\Components\TextInput::make('token')->label('Private Integration Token')->password()->autocomplete('off')
                    ->placeholder(MarketingSettings::maskedToken())->helperText('Stored encrypted. Leave blank to keep the current token. Never shown again.'),
                Forms\Components\TextInput::make('location_id')->label('Location ID')->maxLength(64)->helperText('Sub-account ID (Settings > Business Profile in BrandWorx Marketing). Required by the API.'),
                Forms\Components\TextInput::make('app_url')->label('App URL (for "Open in BrandWorx Marketing" links)')->url()->default(MarketingSettings::DEFAULT_APP_URL),
                Forms\Components\TextInput::make('webhook_url')->label('Inbound webhook URL (optional)')->url()->maxLength(500)
                    ->helperText('A workflow "Inbound Webhook" trigger URL: order events are also POSTed here.'),
                Forms\Components\Toggle::make('enabled')->label('Module on (sync customers and order events)')
                    ->helperText('Stays off and hidden until a token and Location ID are saved.'),
                Forms\Components\Toggle::make('notify')->label('Send transactional email / SMS')
                    ->helperText('OFF by default. Also needs each template on and the customer opted in. Needs A2P 10DLC (SMS) and a sending domain (email) in BrandWorx Marketing.'),
            ]),
            Forms\Components\Section::make('Contact custom fields')->columns(3)->collapsed()
                ->description('Field key (e.g. contact.vega_account_number) or field ID in BrandWorx Marketing. Looked up at sync time. Blank = default key; "-" = do not send. Only text fields can be filled.')
                ->schema([
                    Forms\Components\TextInput::make('cf.account')->label('Vega Account Number (Account Number field)')->maxLength(120),
                    Forms\Components\TextInput::make('cf.state_tax')->label('State Tax ID')->maxLength(120),
                    Forms\Components\TextInput::make('cf.resale')->label('Resale certificate (number)')->maxLength(120),
                ]),
            Forms\Components\Section::make('Transactional templates')->collapsed()->description('Placeholders: {customer} {order} {total} {date} {due} {route} {company}')->schema([
                Forms\Components\Repeater::make('templates')->addable(false)->deletable(false)->reorderable(false)->columns(4)
                    ->itemLabel(fn (array $state) => (TransactionalTemplate::KEYS[$state['key'] ?? ''] ?? '').' ('.($state['channel'] ?? '').')')
                    ->schema([
                        Forms\Components\Hidden::make('id'),
                        Forms\Components\TextInput::make('subject')->maxLength(190)->columnSpan(3)->visible(fn ($get) => $get('channel') === 'email'),
                        Forms\Components\Toggle::make('enabled'),
                        Forms\Components\Textarea::make('body')->rows(3)->required()->columnSpanFull(),
                    ]),
            ]),
        ]);
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);
        $d = $this->form->getState();
        if (filled($d['token'] ?? null)) {
            Setting::put('mkt_token', trim($d['token']));
            Audit::action('marketing_token_set', null, 'BrandWorx Marketing token saved'); // value never logged
        }
        Setting::put('mkt_location_id', trim((string) ($d['location_id'] ?? '')) ?: null);
        Setting::put('mkt_app_url', rtrim((string) ($d['app_url'] ?? ''), '/') ?: MarketingSettings::DEFAULT_APP_URL);
        Setting::put('mkt_webhook_url', trim((string) ($d['webhook_url'] ?? '')) ?: null);
        foreach (array_keys(\App\Marketing\MarketingFields::DEFAULTS) as $k) {
            Setting::put("mkt_cf_{$k}", trim((string) ($d['cf'][$k] ?? '')));
        }
        \Illuminate\Support\Facades\Cache::forget('mkt_cf_defs:'.trim((string) ($d['location_id'] ?? '')));
        $on = ! empty($d['enabled']) && MarketingSettings::configured();
        Setting::put('mkt_enabled', $on ? '1' : '0');
        Setting::put('mkt_notify_enabled', $on && ! empty($d['notify']) ? '1' : '0');
        foreach ($d['templates'] ?? [] as $t) {
            if (! empty($t['id'])) {
                TransactionalTemplate::whereKey($t['id'])->first()?->update(['subject' => $t['subject'] ?? null, 'body' => $t['body'], 'enabled' => (bool) ($t['enabled'] ?? false)]);
            }
        }
        Audit::action('marketing_settings', null, 'BrandWorx Marketing settings saved', ['enabled' => $on, 'notify' => $on && ! empty($d['notify'])]);
        $this->data['token'] = null;
        Notification::make()->success()->title(! empty($d['enabled']) && ! $on ? 'Saved, but the module stays off until a token and Location ID are set' : 'Saved')->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('test')->label('Test connection')->icon('heroicon-o-signal')->visible(fn () => MarketingSettings::configured())
                ->action(function () {
                    try {
                        $r = app(GhlClient::class)->test();
                        $lines = collect($r)->map(fn ($v, $k) => ($v['ok'] ? '✓ ' : '✗ ')."{$k} ({$v['status']}) {$v['note']}")->implode("\n");
                        Audit::action('marketing_test', null, 'BrandWorx Marketing connection test', collect($r)->map(fn ($v) => $v['status'])->all());
                        Notification::make()->title($r['location']['ok'] ? 'Connected' : 'Not connected')->body(nl2br(e($lines)))->{$r['location']['ok'] ? 'success' : 'danger'}()->persistent()->send();
                    } catch (\Throwable $e) {
                        Notification::make()->danger()->title('Test failed')->body($e->getMessage())->send();
                    }
                }),
            Action::make('pushAll')->label('Push all customers')->icon('heroicon-o-arrow-up-tray')->visible(fn () => MarketingSettings::enabled())
                ->requiresConfirmation()->modalDescription('Queues every active customer for BrandWorx Marketing, '.MarketingSync::BULK_PER_10S.' every 10 seconds to stay under the API rate limit. Sync is one-way: nothing is read back.')
                ->action(function () {
                    $n = MarketingSync::queueMany(Customer::where('active', true));
                    Audit::action('marketing_push_all', null, "Queued {$n} customer(s) for BrandWorx Marketing", ['count' => $n]);
                    $mins = (int) ceil($n / MarketingSync::BULK_PER_10S * 10 / 60);
                    Notification::make()->success()->title("Queued {$n} customer(s)")->body("Spread over about {$mins} minute(s). See the sync log.")->send();
                }),
        ];
    }
}
