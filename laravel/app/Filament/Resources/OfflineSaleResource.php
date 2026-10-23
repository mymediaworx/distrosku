<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\RoleGated;
use App\Filament\Resources\OfflineSaleResource\Pages;
use App\Models\OfflineSale;
use App\Models\Product;
use App\Pos\OfflineSaleSync;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

/**
 * Issue #26: sales recorded on a device while offline, as they arrived at sync.
 * "Needs review" = the server couldn't complete it (compliance, credit limit, stock, card, stale prices). Never dropped:
 * a manager fixes the cause and retries, retries with a credit override (owner/admin), or marks it handled with a note.
 */
class OfflineSaleResource extends Resource
{
    use RoleGated;

    protected static string $module = 'pos';

    protected static ?string $model = OfflineSale::class;

    protected static ?string $modelLabel = 'offline sale';

    protected static ?string $pluralModelLabel = 'offline sales';

    protected static ?string $navigationLabel = 'Offline sales';

    protected static ?string $navigationGroup = 'Sales';

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationIcon = 'heroicon-o-signal-slash';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function reviewCount(): int
    {
        return OfflineSale::where('status', OfflineSale::NEEDS_REVIEW)->count();
    }

    public static function getNavigationBadge(): ?string
    {
        return ($n = self::reviewCount()) ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('sold_at', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with('customer', 'user', 'salesOrder'))
            ->columns([
                Tables\Columns\TextColumn::make('sold_at')->label('Sold')->dateTime('M j, g:i A')->sortable()
                    ->description(fn (OfflineSale $r) => 'Synced '.$r->synced_at?->diffForHumans()),
                Tables\Columns\TextColumn::make('customer.name')->label('Customer')->searchable()->limit(28)
                    ->description(fn (OfflineSale $r) => $r->user?->name ? 'Cashier: '.$r->user->name : null),
                Tables\Columns\TextColumn::make('total')->money('USD')->description(fn (OfflineSale $r) => ucfirst((string) $r->tender)),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->formatStateUsing(fn ($state) => ['needs_review' => 'Needs review', 'completed' => 'Completed', 'resolved' => 'Resolved', 'pending' => 'Pending'][$state] ?? $state)
                    ->color(fn ($state) => match ($state) { 'needs_review' => 'danger', 'completed' => 'success', 'resolved' => 'gray', default => 'warning' }),
                Tables\Columns\TextColumn::make('salesOrder.number')->label('Order')->placeholder('—')
                    ->url(fn (OfflineSale $r) => $r->sales_order_id ? SalesOrderResource::getUrl('edit', ['record' => $r->sales_order_id]) : null),
                Tables\Columns\TextColumn::make('problems')->label('Why / notes')->wrap()->html()
                    ->getStateUsing(fn (OfflineSale $r) => self::notesHtml($r)),
                Tables\Columns\TextColumn::make('device_id')->label('Device')->limit(12)->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(['needs_review' => 'Needs review', 'completed' => 'Completed', 'resolved' => 'Resolved']),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->modalHeading(fn (OfflineSale $r) => 'Offline sale '.mb_substr($r->uuid, 0, 8))
                    ->modalContent(fn (OfflineSale $r) => new HtmlString(view('filament.partials.offline-sale', ['row' => $r, 'lines' => self::lines($r)])->render()))
                    ->modalSubmitAction(false),
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\Action::make('retry')->label('Retry')->icon('heroicon-o-arrow-path')
                        ->visible(fn (OfflineSale $r) => $r->status === OfflineSale::NEEDS_REVIEW && static::allowed('manage'))
                        ->requiresConfirmation()->modalDescription('Runs the sale through checkout again, e.g. after fixing stock or a customer license.')
                        ->action(fn (OfflineSale $r) => self::notify(app(OfflineSaleSync::class)->process($r))),
                    Tables\Actions\Action::make('creditOverride')->label('Retry with credit override')->icon('heroicon-o-shield-exclamation')->color('warning')
                        ->visible(fn (OfflineSale $r) => $r->status === OfflineSale::NEEDS_REVIEW && $r->tender === 'account' && auth()->user()?->isAdmin())
                        ->modalDescription('Owner/admin only. Records the override and reason in the Activity log, then completes the sale.')
                        ->form([Forms\Components\Textarea::make('reason')->label('Reason for override')->required()->minLength(3)->maxLength(500)])
                        ->action(fn (OfflineSale $r, array $data) => self::notify(app(OfflineSaleSync::class)->process($r, auth()->user(), $data['reason']))),
                    Tables\Actions\Action::make('resolve')->label('Mark handled')->icon('heroicon-o-check')->color('gray')
                        ->visible(fn (OfflineSale $r) => $r->status === OfflineSale::NEEDS_REVIEW && auth()->user()?->isAdmin())
                        ->modalDescription('Use when the sale was dealt with outside this screen (goods returned, entered by hand). Nothing is posted.')
                        ->form([Forms\Components\Textarea::make('note')->label('What was done')->required()->minLength(3)->maxLength(2000)])
                        ->action(function (OfflineSale $r, array $data) {
                            app(OfflineSaleSync::class)->resolve($r, auth()->user(), $data['note']);
                            Notification::make()->success()->title('Marked handled.')->send();
                        }),
                ]),
            ]);
    }

    /** @return list<array{sku: string, name: string, qty: string, price: string, total: string}> */
    public static function lines(OfflineSale $r): array
    {
        $ps = Product::whereIn('id', collect($r->payload['lines'] ?? [])->pluck('product_id')->filter()->all())->get()->keyBy('id');

        return collect($r->payload['lines'] ?? [])->map(fn ($l) => [
            'sku' => $ps[$l['product_id'] ?? 0]->sku ?? ('#'.($l['product_id'] ?? '?')),
            'name' => $ps[$l['product_id'] ?? 0]->name ?? 'Unknown product',
            'qty' => is_numeric($l['qty'] ?? null) ? rtrim(rtrim((string) $l['qty'], '0'), '.') ?: '0' : '?',
            'price' => is_numeric($l['price'] ?? null) ? number_format((float) $l['price'], 2) : '?',
            'total' => is_numeric($l['qty'] ?? null) && is_numeric($l['price'] ?? null) ? number_format((float) $l['qty'] * (float) $l['price'], 2) : '?',
        ])->all();
    }

    private static function notesHtml(OfflineSale $r): string
    {
        $items = array_merge(
            array_map(fn ($p) => '<span style="color:#b91c1c">'.e($p).'</span>', $r->problems ?? []),
            array_map(fn ($w) => '<span style="color:#92400e">'.e($w).'</span>', $r->warnings ?? []),
            $r->resolution_note ? ['<span style="color:#475569">Handled: '.e($r->resolution_note).'</span>'] : [],
        );

        return implode('<br>', $items) ?: '<span style="color:#94a3b8">—</span>';
    }

    private static function notify(OfflineSale $r): void
    {
        $r->status === OfflineSale::COMPLETED
            ? Notification::make()->success()->title('Recorded as '.$r->salesOrder?->number.'.')->send()
            : Notification::make()->danger()->title('Still needs review')->body(implode(' ', $r->problems ?? []))->persistent()->send();
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListOfflineSales::route('/')];
    }
}
