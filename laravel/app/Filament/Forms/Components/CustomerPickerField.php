<?php

namespace App\Filament\Forms\Components;

use Closure;
use Filament\Forms\Components\Field;

/**
 * 0.20.20: Filament field wrapper around the ONE standard customer picker (App\Livewire\CustomerPicker).
 * State is a customer id or null (blank). No default, ever.
 *
 *   CustomerPickerField::make('customer_id')->required()->live()
 */
class CustomerPickerField extends Field
{
    protected string $view = 'filament.forms.customer-picker-field';

    protected bool|Closure $activeOnly = false;

    public function activeOnly(bool|Closure $condition = true): static
    {
        $this->activeOnly = $condition;

        return $this;
    }

    public function getActiveOnly(): bool
    {
        return (bool) $this->evaluate($this->activeOnly);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->dehydrateStateUsing(fn ($state) => filled($state) ? (int) $state : null);
    }
}
