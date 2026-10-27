<?php

namespace App\Licensing;

use App\Models\Customer;
use App\Models\CustomerLicense;
use App\Models\LicenseConflict;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * 0.20.17: finds license / account conflicts and keeps the review queue (license_conflicts) in sync. Re-runnable: each
 * conflict has a stable fingerprint, so a decision (approved / edited) is never reopened, and a pending one whose cause
 * has gone away becomes "cleared". Nothing here changes customers or licenses; the office decides on the review screen.
 */
class LicenseConflictScanner
{
    /** @return array{found:int,new:int,cleared:int} */
    public function scan(): array
    {
        $found = $this->detect();
        $new = 0;
        foreach ($found as $fp => $row) {
            $c = LicenseConflict::firstOrCreate(['fingerprint' => $fp], $row + ['status' => 'pending']);
            $new += $c->wasRecentlyCreated ? 1 : 0;
            if (! $c->wasRecentlyCreated && $c->status === 'cleared') {
                $c->update(['status' => 'pending']);
                $new++;
            }
        }
        $cleared = LicenseConflict::whereIn('status', ['pending', 'skipped'])->whereNotIn('fingerprint', array_keys($found))->update(['status' => 'cleared', 'resolved_at' => now()]);

        return ['found' => count($found), 'new' => $new, 'cleared' => $cleared];
    }

    /** @return array<string,array<string,mixed>> fingerprint => attributes */
    public function detect(): array
    {
        $out = [];

        foreach (CustomerLicense::with('customer')->get() as $l) {
            if (! $l->customer?->active) {
                continue;
            }
            if (! $l->hasProof()) {
                $out["no_proof:{$l->id}"] = ['kind' => 'no_proof', 'customer_id' => $l->customer_id, 'license_id' => $l->id,
                    'summary' => "{$l->customer->name}: {$l->type} license has neither a Site ID nor a license number."];
            }
            if ($l->expires_at && $l->expires_at->isPast()) {
                $out["expired:{$l->id}:{$l->expires_at->toDateString()}"] = ['kind' => 'expired', 'customer_id' => $l->customer_id, 'license_id' => $l->id,
                    'summary' => "{$l->customer->name}: {$l->type} license ".($l->site_id ?: $l->number).' expired '.$l->expires_at->toDateString().'.'];
            }
        }

        $active = Customer::where('active', true)->get(['id', 'code', 'name', 'state_tax_id']);

        foreach ($active->filter(fn ($c) => filled($c->state_tax_id))->groupBy(fn ($c) => strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $c->state_tax_id)))->filter(fn ($g, $k) => $k !== '' && $g->count() > 1) as $k => $g) {
            $out["duplicate_tax_id:$k"] = ['kind' => 'duplicate_tax_id', 'customer_id' => $g->first()->id, 'related_customer_ids' => $g->pluck('id')->all(),
                'summary' => 'State tax ID '.$g->first()->state_tax_id.' is on '.$g->count().' customers: '.$g->map(fn ($c) => "{$c->code} {$c->name}")->implode('; ').'.'];
        }

        foreach ($active->groupBy(fn ($c) => mb_strtolower(preg_replace('/[^A-Za-z0-9]/', '', $c->name)))->filter(fn ($g, $k) => $k !== '' && $g->count() > 1) as $k => $g) {
            $out["duplicate_name:$k"] = ['kind' => 'duplicate_name', 'customer_id' => $g->first()->id, 'related_customer_ids' => $g->pluck('id')->all(),
                'summary' => 'Same name on '.$g->count().' accounts: '.$g->map(fn ($c) => "{$c->code} {$c->name}")->implode('; ').'.'];
        }

        $licensed = DB::table('customer_licenses')->pluck('customer_id')->unique()->all();
        $ordered = DB::table('sales_orders')->whereNotIn('status', ['draft', 'cancelled'])->pluck('customer_id')->unique()->all();
        foreach ($active->whereIn('id', array_diff($ordered, $licensed)) as $c) {
            $out["missing_license:{$c->id}"] = ['kind' => 'missing_license', 'customer_id' => $c->id,
                'summary' => "{$c->code} {$c->name} has orders but no license on file."];
        }

        return $out;
    }

    public function resolve(LicenseConflict $c, string $status, ?string $note, User $by): LicenseConflict
    {
        abort_unless(in_array($status, ['approved', 'edited', 'skipped'], true), 422);
        $c->update(['status' => $status, 'note' => $note ?: $c->note, 'resolved_by' => $by->id, 'resolved_at' => now()]);

        return $c;
    }
}
