<?php

namespace App\Models;

use App\Models\Concerns\HasActivity;
use Illuminate\Database\Eloquent\Model;

/** 0.18: one product eligibility rule (federal or state, optional county/city), effective-dated and versioned. */
class EligibilityRule extends Model
{
    use HasActivity;

    protected $guarded = ['id'];

    protected $attributes = ['version' => 1, 'action' => 'block', 'level' => 'state', 'enabled' => false];

    protected function casts(): array
    {
        return [
            'thc_total_max_mg' => 'decimal:4', 'thc_serving_max_mg' => 'decimal:4',
            'thc_unknown_fires' => 'boolean', 'synthetic_fires' => 'boolean', 'flavored_fires' => 'boolean',
            'fda_statuses' => 'array', 'needs_msa_directory' => 'boolean', 'needs_vape_directory' => 'boolean', 'needs_current_coa' => 'boolean',
            'effective_from' => 'date', 'effective_to' => 'date', 'enabled' => 'boolean', 'verify_with_counsel' => 'boolean', 'version' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // every edit of a rule's meaning bumps its version (the Activity log keeps the old values)
        static::updating(function (self $r) {
            $meaning = array_diff(array_keys($r->getDirty()), ['version', 'updated_at', 'updated_by', 'name', 'source_note']);
            if ($meaning && ! $r->isDirty('version')) {
                $r->version = (int) $r->getOriginal('version') + 1;
            }
        });
    }

    public function jurisdictionLabel(): string
    {
        if ($this->level === 'federal') {
            return 'Federal';
        }
        $s = $this->state ?: 'All states';

        return trim($s.($this->county ? ", {$this->county} County" : '').($this->city ? ", {$this->city}" : ''));
    }
}
