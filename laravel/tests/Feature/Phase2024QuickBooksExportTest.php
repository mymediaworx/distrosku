<?php

namespace Tests\Feature;

use App\Accounting\QuickBooksExport;
use App\Filament\Pages\QuickBooksExportPage;
use App\Models\Account;
use App\Models\User;
use App\Services\PostingService;
use Database\Seeders\CoreSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** #40 (0.20.24): general ledger as QuickBooks Online CSV / Desktop IIF journal entries. */
class Phase2024QuickBooksExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreSeeder::class);
        $gl = app(PostingService::class);
        $gl->post('2026-09-15', 'Cash sale, bag 7', [[PostingService::CASH, '107.50', 0], [PostingService::SALES, 0, '100.00'], [PostingService::SALES_TAX_PAYABLE, 0, '7.50']]);
        $gl->post('2026-10-02', 'Outside the range', [[PostingService::CASH, '5', 0], [PostingService::SALES, 0, '5']]);
        Account::where('code', PostingService::SALES)->update(['qb_name' => 'Sales:Wholesale']);
    }

    public function test_qbo_csv_has_balanced_rows_and_mapped_names(): void
    {
        $f = app(QuickBooksExport::class)->build('2026-09-01', '2026-09-30', 'qbo');
        $rows = array_map('str_getcsv', array_filter(explode("\n", trim($f['body']))));
        $this->assertSame(['JournalNo', 'JournalDate', 'AccountName', 'Debits', 'Credits', 'Description'], $rows[0]);
        $this->assertCount(4, $rows);                                   // header + 3 lines of one journal
        $this->assertSame(1, $f['journals']);
        $this->assertSame('09/15/2026', $rows[1][1]);
        $this->assertContains('Sales:Wholesale', array_column($rows, 2));
        $this->assertEqualsWithDelta(array_sum(array_map('floatval', array_column(array_slice($rows, 1), 3))), array_sum(array_map('floatval', array_column(array_slice($rows, 1), 4))), 0.001);
        $this->assertStringEndsWith('.csv', $f['name']);
    }

    public function test_iif_signs_credits_negative_and_closes_each_entry(): void
    {
        $body = app(QuickBooksExport::class)->build('2026-09-01', '2026-09-30', 'iif')['body'];
        $lines = array_values(array_filter(explode("\r\n", $body)));
        $this->assertStringStartsWith("!TRNS\t", $lines[0]);
        $this->assertStringStartsWith("TRNS\t\tGENERAL JOURNAL\t09/15/2026", $lines[3]);
        $this->assertStringContainsString("\t107.50\t", $lines[3]);
        $this->assertStringContainsString("Sales:Wholesale\t-100.00", $lines[4]);
        $this->assertSame('ENDTRNS', end($lines));
        $amounts = array_map(fn ($l) => (float) explode("\t", $l)[5], array_slice($lines, 3, 3));
        $this->assertEqualsWithDelta(0, array_sum($amounts), 0.001);
    }

    public function test_page_is_admin_only_and_downloads(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'sales']));
        $this->assertFalse(QuickBooksExportPage::canAccess());
        $this->actingAs(User::factory()->create(['role' => 'owner']));
        Livewire::test(QuickBooksExportPage::class)->set('data.from', '2026-09-01')->set('data.to', '2026-09-30')->set('data.format', 'qbo')
            ->call('download')->assertFileDownloaded('distrosku-gl-2026-09-01-to-2026-09-30.csv');
        $this->assertDatabaseHas('activity_logs', ['event' => 'quickbooks_export']);
    }
}
