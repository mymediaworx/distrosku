<?php

use App\Support\Terms;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 0.20.6: Edit Customer screen.
 *  - license_types (Settings list) seeded with KY Tobacco License / KY Hemp License + every type already in use.
 *  - customer terms normalized to the standard dropdown values (unknown values are left as-is and stay selectable).
 *  - price groups Standard and Wholesale ensured.
 * Federal EIN uses the existing customers.fein column. Re-runnable: every step checks first. No data is deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('license_types')) {
            Schema::create('license_types', function (Blueprint $t) {
                $t->id();
                $t->string('code', 40)->unique();
                $t->string('name', 80);
                $t->string('state', 2)->nullable();
                $t->boolean('active')->default(true);
                $t->unsignedInteger('sort')->default(100);
                $t->timestamps();
            });
        }
        \App\Models\LicenseType::seedDefaults();
        $used = DB::table('customer_licenses')->whereNotNull('type')->distinct()->pluck('type')
            ->merge(Schema::hasTable('compliance_rule_versions') ? DB::table('compliance_rule_versions')->whereNotNull('license_type')->distinct()->pluck('license_type') : [])
            ->filter()->unique();
        foreach ($used as $code) {
            if (! DB::table('license_types')->where('code', $code)->exists()) {
                DB::table('license_types')->insert(['code' => substr($code, 0, 40), 'name' => ucwords(str_replace(['_', '-'], ' ', $code)),
                    'active' => true, 'sort' => 200, 'created_at' => now(), 'updated_at' => now()]);
            }
        }

        foreach (DB::table('customers')->select('terms')->distinct()->pluck('terms') as $old) {
            $new = Terms::normalize($old);
            if ($new !== null && $new !== $old) {
                DB::table('customers')->where('terms', $old)->update(['terms' => $new]);
            }
        }

        if (Schema::hasTable('customer_groups')) {
            $has = fn (string $name) => DB::table('customer_groups')->whereRaw('LOWER(TRIM(name)) = ?', [strtolower($name)])->first();
            if ($w = $has('wholesale')) {
                if ($w->name !== 'Wholesale') {
                    DB::table('customer_groups')->where('id', $w->id)->update(['name' => 'Wholesale', 'updated_at' => now()]);
                }
            } else {
                $code = DB::table('customer_groups')->where('code', 'WHOLESALE')->exists() ? 'WHOLESALE2' : 'WHOLESALE';
                DB::table('customer_groups')->insert(['code' => $code, 'name' => 'Wholesale', 'created_at' => now(), 'updated_at' => now()]);
            }
            if (! $has('standard')) {
                $code = DB::table('customer_groups')->where('code', 'STANDARD')->exists() ? 'STANDARD2' : 'STANDARD';
                DB::table('customer_groups')->insert(['code' => $code, 'name' => 'Standard', 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        // Data-preserving: license_types is kept (licenses reference its codes); terms/price-group changes are not reverted.
    }
};
