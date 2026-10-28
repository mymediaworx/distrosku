<?php

namespace App\Models;

use App\Audit\Audit;
use App\Exceptions\WorkflowException;
use App\Models\Concerns\HasActivity;
use App\Services\CustomerBalanceService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    /** 0.19.1: true while BrandWorx Marketing inbound sync writes (no outbound echo, no ERP contact timestamp). */
    protected static function booted(): void
    {
        // 0.17.3: only owner/admin/brandworx_admin may delete a customer (any route, incl. bulk).
        static::deleting(function (Customer $c) {
            $u = auth()->user();
            if ($u instanceof User && ! $u->isAdmin()) {
                Audit::action('customer_delete_blocked', $c, "Blocked: {$u->name} ({$u->role?->value}) tried to delete customer {$c->name}");
                throw new WorkflowException('Only an owner or admin can delete a customer.');
            }
        });
        // 0.17.2: only owner/admin/brandworx_admin may set or change a credit limit.
        // No signed-in user (console, seeders, queued jobs started by an admin) is allowed.
        static::saving(function (Customer $c) {
            $u = auth()->user();
            if (! $u instanceof User || $u->isAdmin()) {
                return;
            }
            $changed = $c->exists
                ? $c->isDirty('credit_limit') && bccomp((string) ($c->getOriginal('credit_limit') ?? 0), (string) ($c->credit_limit ?? 0), 2) !== 0
                : bccomp((string) ($c->credit_limit ?? 0), '0', 2) !== 0;
            if ($changed) {
                throw new WorkflowException('Only an owner or admin can change a customer\'s credit limit.');
            }
        });
    }

    use HasActivity;
    use HasFactory;

    /** Beginning balance is set only through CustomerBalanceService (posts to the ledger + audit). */
    protected $guarded = ['id', 'beginning_balance', 'beginning_balance_date', 'beginning_balance_applied'];

    /** Never serialized (API/JSON/Livewire payloads); admin UI reads them explicitly. */
    protected $hidden = ['beginning_balance', 'beginning_balance_date', 'beginning_balance_applied'];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'msa_promo_acceptance' => 'boolean',
            'dunning_email_optout' => 'boolean', 'email_opt_in' => 'boolean', 'sms_opt_in' => 'boolean', 'ghl_synced_at' => 'datetime',
            'ghl_tags' => 'array', 'ghl_dnd' => 'boolean', 'ghl_opportunity_value' => 'decimal:2', 'ghl_contact_updated_at' => 'datetime',
            'contact_fields_updated_at' => 'datetime', 'accept_marketing_contact_updates' => 'boolean', 'ghl_created_by_erp' => 'boolean',
            'credit_limit' => 'decimal:2',
            'beginning_balance' => 'decimal:2',
            'beginning_balance_date' => 'date',
            'beginning_balance_applied' => 'decimal:2',
        ];
    }

    /** 0.20.14: Account Number + name for pickers and reports. */
    public function labelWithAccount(): string
    {
        return trim(($this->code ? $this->code.' — ' : '').($this->name ?? ''));
    }

    /** 0.20.20: tokenized search (account number, name, DBA, phone, city; every word, any order). */
    public static function optionsForSelect(?string $search = null, int $limit = 50): array
    {
        return \App\Support\CustomerSearch::options($search, $limit);
    }

    public function customerGroup()
    {
        return $this->belongsTo(CustomerGroup::class);
    }

    public function salesRep()
    {
        return $this->belongsTo(User::class, 'sales_rep_id');
    }

    public function shipTos()
    {
        return $this->hasMany(ShipTo::class);
    }

    /** 0.19.3: the default ship-to (falls back to the oldest). */
    public function defaultShipTo(): ?ShipTo
    {
        return $this->shipTos()->orderByDesc('is_default')->orderBy('id')->first();
    }

    /**
     * 0.19.5: the ship-to a POS / van / portal screen should show for this customer: the current pick if it
     * still belongs to this customer, else the default, else the only / oldest one. Null when there are none.
     */
    public function resolveShipToId(int|string|null $current = null): ?int
    {
        if ($current && $this->shipTos()->whereKey((int) $current)->exists()) {
            return (int) $current;
        }

        return $this->defaultShipTo()?->id;
    }

    public function licenses()
    {
        return $this->hasMany(CustomerLicense::class);
    }

    public function contacts()
    {
        return $this->hasMany(CustomerContact::class);
    }

    public function salesOrders()
    {
        return $this->hasMany(SalesOrder::class);
    }

    public function paymentMethods()
    {
        return $this->hasMany(PaymentMethod::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function balanceAudits()
    {
        return $this->hasMany(CustomerBalanceAudit::class)->latest('id');
    }

    /** Total AR balance: open invoices + remaining beginning balance. */
    public function balance(): string
    {
        return app(CustomerBalanceService::class)->balance($this);
    }
}
