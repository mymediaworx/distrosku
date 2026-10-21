<?php

namespace Tests\Feature;

use App\Exceptions\UnbalancedJournalException;
use App\Models\JournalLine;
use App\Services\PostingService;
use Database\Seeders\CoreSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PostingServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreSeeder::class);
    }

    public function test_balanced_journal_posts(): void
    {
        $j = app(PostingService::class)->post('2026-10-20', 'test', [['1000', '10.10', 0], ['3000', 0, '10.10']]);
        $this->assertSame('10.1000', $j->total);
        $this->assertCount(2, $j->lines);
    }

    public function test_unbalanced_journal_throws_and_writes_nothing(): void
    {
        try {
            app(PostingService::class)->post('2026-10-20', 'bad', [['1000', '10.00', 0], ['3000', 0, '9.99']]);
            $this->fail('expected exception');
        } catch (UnbalancedJournalException) {
        }
        $this->assertSame(0, JournalLine::count());
    }

    public function test_decimal_precision_no_float_drift(): void
    {
        $lines = [];
        for ($i = 0; $i < 10; $i++) {
            $lines[] = ['1100', '0.1', 0];
        }
        $lines[] = ['4000', 0, '1.0'];
        $j = app(PostingService::class)->post('2026-10-20', 'drift', $lines);
        $this->assertSame('1.0000', $j->total);
    }

    public function test_unknown_account_and_negative_rejected(): void
    {
        $this->expectException(UnbalancedJournalException::class);
        app(PostingService::class)->post('2026-10-20', 'x', [['9999', 1, 0], ['1000', 0, 1]]);
    }

    public function test_negative_amount_rejected(): void
    {
        $this->expectException(UnbalancedJournalException::class);
        app(PostingService::class)->post('2026-10-20', 'x', [['1000', '-1', 0], ['3000', 0, '-1']]);
    }
}
