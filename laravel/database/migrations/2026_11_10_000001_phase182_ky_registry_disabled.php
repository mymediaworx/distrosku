<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** 0.18.2: KY THC-vape registry rule off until CHFS lists UPCs. Re-run safe (only touches the seeded rule). */
return new class extends Migration
{
    public const NOTE_PREFIX = 'DISABLED until CHFS lists UPCs on the registry (0.18.2). ';

    public function up(): void
    {
        $r = DB::table('eligibility_rules')->where('key', 'ky_thc_vape_registry')->first();
        if (! $r) {
            return;
        }
        $note = (string) $r->source_note;
        if (! str_starts_with($note, self::NOTE_PREFIX)) {
            $note = self::NOTE_PREFIX.$note;
        }
        $upd = ['enabled' => false, 'source_note' => $note, 'updated_at' => now()];
        if ($r->enabled) {
            $upd['version'] = (int) $r->version + 1;
        }
        DB::table('eligibility_rules')->where('id', $r->id)->update($upd);
    }

    public function down(): void {}
};
