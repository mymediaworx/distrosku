<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\RoleGated;
use App\Filament\Resources\CustomerLicenseResource\Pages;
use App\Filament\Resources\CustomerResource\RelationManagers\LicensesRelationManager;
use App\Models\CustomerLicense;
use App\Models\LicenseType;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class CustomerLicenseResource extends Resource
{
    use RoleGated;

    protected static string $module = 'customer_licenses';

    protected static ?string $model = CustomerLicense::class;

    protected static ?string $navigationGroup = 'Customers';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Form $form): Form
    {
        return $form
            ->schema(LicensesRelationManager::licenseFields(true))
            ->columns(['default' => 1, 'md' => 2]);
    }

    /** 0.20.6: licenses live on the customer page; this list stays reachable by URL (and from Settings) but is not in the sidebar. */
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('customer.name')
                    ->sortable(),
                Tables\Columns\TextColumn::make('shipTo.name')
                    ->sortable(),
                Tables\Columns\TextColumn::make('type')->badge()
                    ->formatStateUsing(fn ($state) => LicenseType::label($state))->searchable(),
                Tables\Columns\TextColumn::make('site_id')->label('Site ID')->searchable()->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('number')->label('License #')
                    ->searchable()->placeholder('—'),
                Tables\Columns\TextColumn::make('state')
                    ->searchable(),
                Tables\Columns\TextColumn::make('expires_at')
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')->label('License type')->options(fn () => LicenseType::options(null, false)),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCustomerLicenses::route('/'),
            'create' => Pages\CreateCustomerLicense::route('/create'),
            'edit' => Pages\EditCustomerLicense::route('/{record}/edit'),
        ];
    }
}
