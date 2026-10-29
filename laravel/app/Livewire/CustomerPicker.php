<?php

namespace App\Livewire;

use App\Models\Customer;
use App\Support\CustomerSearch;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Modelable;
use Livewire\Component;

/**
 * 0.20.20: THE standard customer picker. A search box, not a dropdown of every customer: type any of account number,
 * name, DBA, phone or city (every word must match, any order, punctuation ignored) and pick from the matches.
 * It starts blank (there is never a default customer); the page decides what a blank customer means.
 *
 *   <livewire:customer-picker wire:model.live="customerId" />                     // POS / van sale / reports
 *   <livewire:customer-picker wire:model.live="customerId" :walk-in="true" />    // adds an explicit "Walk-in (CASH)" button
 *   Filament: App\Filament\Forms\Components\CustomerPickerField::make('customer_id')
 *
 * Props: label, placeholder, active-only (default true), clearable (default true), walk-in (default false), name.
 * Event: `customer-picker-changed` { id: ?int, name: string }.
 */
class CustomerPicker extends Component
{
    public const LIMIT = 20;

    #[Modelable]
    public mixed $value = null;

    public string $label = 'Customer';

    public string $placeholder = 'Search account number, name, DBA, phone or city';

    public bool $activeOnly = true;

    public bool $clearable = true;

    public bool $walkIn = false;

    public string $name = 'customer-picker';

    public string $search = '';

    public string $notice = '';

    public function mount(): void
    {
        $this->value = $this->currentId();
    }

    private function currentId(): ?int
    {
        return filled($this->value) && is_numeric($this->value) ? (int) $this->value : null;
    }

    #[Computed]
    public function selected(): ?array
    {
        $c = ($id = $this->currentId()) ? Customer::find($id) : null;

        return $c ? CustomerSearch::row($c) : null;
    }

    #[Computed]
    public function results(): array
    {
        return trim($this->search) === '' ? [] : CustomerSearch::results($this->search, self::LIMIT, $this->activeOnly);
    }

    public function select(int $id): void
    {
        if (! Customer::whereKey($id)->exists()) {
            return;
        }
        $this->set($id);
    }

    public function clear(): void
    {
        $this->set(null);
    }

    /**
     * Explicit, never automatic: the counter's cash / walk-in account (account number CASH).
     * NOT named walkIn(): that is also the public $walkIn property, and in the browser `$wire.walkIn` then resolves to
     * the property (true), so wire:click="walkIn" silently did nothing on live (0.20.20 / 0.20.21).
     */
    public function selectWalkIn(): void
    {
        $this->notice = '';
        if ($c = \App\Compliance\WalkInRule::cashCustomer()) {
            $this->set((int) $c->id);

            return;
        }
        $this->notice = 'No cash customer found. Create a customer with Account Number CASH (or switch on "Walk-in / cash customer" for one account), then try again.';
    }

    public function isWalkInSelected(): bool
    {
        return ($id = $this->currentId()) ? \App\Compliance\WalkInRule::isWalkInId($id) : false;
    }

    private function set(?int $id): void
    {
        $this->value = $id;
        $this->search = '';
        unset($this->selected, $this->results);
        $this->dispatch('customer-picker-changed', id: $id, name: $this->name);
    }

    public function render()
    {
        return view('livewire.customer-picker');
    }
}
