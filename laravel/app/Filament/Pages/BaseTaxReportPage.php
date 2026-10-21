<?php

namespace App\Filament\Pages;

use App\Models\TaxFiling;
use App\Reports\TaxReport;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

abstract class BaseTaxReportPage extends Page
{
    protected static string $view = 'filament.pages.tax-report';

    protected static ?string $navigationGroup = 'Tax Reports';

    protected static string $reportKey;

    /** KY EDI XML generator class (KyTobaccoXml / KyCigaretteXml). */
    protected static ?string $xmlClass = null;

    public string $period = '';

    public ?array $report = null;

    /** Schema errors from the last blocked XML download (shown on the page). */
    public array $xmlErrors = [];

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->canModule('reports');
    }

    public function mount(): void
    {
        $this->period = now()->subMonthNoOverflow()->format('Y-m');
        $this->generate();
    }

    public function reportKey(): string
    {
        return static::$reportKey;
    }

    public function getTitle(): string
    {
        return TaxReport::for(static::$reportKey)->title();
    }

    public function periodOptions(): array
    {
        $o = [];
        for ($i = 0; $i < 24; $i++) {
            $d = now()->startOfMonth()->subMonthsNoOverflow($i);
            $o[$d->format('Y-m')] = $d->format('F Y');
        }

        return $o;
    }

    protected function range(): array
    {
        $s = CarbonImmutable::createFromFormat('Y-m-d', $this->period.'-01')->startOfMonth();

        return [$s, $s->endOfMonth()];
    }

    public function updatedPeriod(): void
    {
        $this->generate();
    }

    public function generate(): void
    {
        try {
            [$s, $e] = $this->range();
            $this->report = TaxReport::for(static::$reportKey)->build($s, $e);
        } catch (\Throwable $ex) {
            $this->report = null;
            Notification::make()->danger()->title('Report failed')->body($ex->getMessage())->send();
        }
    }

    public function existingFiling(): ?TaxFiling
    {
        [$s] = $this->range();

        return \App\Tax\ReturnService::latest(static::$reportKey, $s);
    }

    protected function getHeaderActions(): array
    {
        $file = fn ($ext) => static::$reportKey.'_'.$this->period.'.'.$ext;

        return [
            Action::make('csv')->label('CSV')->icon('heroicon-o-arrow-down-tray')->disabled(fn () => ! $this->report)
                ->action(fn () => response()->streamDownload(fn () => print (TaxReport::for(static::$reportKey)->toCsv($this->report)), $file('csv'), ['Content-Type' => 'text/csv'])),
            Action::make('pdf')->label('PDF')->icon('heroicon-o-document-arrow-down')->disabled(fn () => ! $this->report)
                ->action(fn () => response()->streamDownload(fn () => print (TaxReport::for(static::$reportKey)->toPdf($this->report)), $file('pdf'), ['Content-Type' => 'application/pdf'])),
            Action::make('xml')->label('Download XML')->icon('heroicon-o-code-bracket')->visible(fn () => static::$xmlClass !== null)
                ->action(fn () => $this->downloadXml()),
            Action::make('save')->label('Save draft filing')->icon('heroicon-o-bookmark')->disabled(fn () => ! $this->report)
                ->action(function () {
                    $f = $this->saveDraft();
                    $f ? Notification::make()->success()->title("Draft filing #{$f->id} saved")->send()
                        : Notification::make()->warning()->title('This period has an approved or filed return; not overwritten. Start an amendment from Tax filings.')->send();
                }),
        ];
    }

    /** Generates the KY EDI XML for the period and validates it against the KY XSD (docs/xsd, docs/xsd-cig). Invalid XML is never downloaded. */
    public function downloadXml()
    {
        [$s, $e] = $this->range();
        $f = $this->existingFiling();
        $amended = $f && $f->version > 1;
        [$xml, $errors, $msgs] = app(\App\Tax\ReturnService::class)->xml(static::$reportKey, $s, $e, $amended);
        if (! $xml) {
            $this->xmlErrors = $errors;
            Notification::make()->danger()->title('Download blocked: the XML does not validate against the KY schema')
                ->body(implode("\n", array_slice(array_merge($errors, $msgs), 0, 12)))->persistent()->send();

            return null;
        }
        $this->xmlErrors = [];
        $msgs ? Notification::make()->warning()->title('XML validated against the KY schema, with data warnings')->body(implode("\n", array_slice($msgs, 0, 12)))->persistent()->send()
            : Notification::make()->success()->title('XML generated and validated against the KY schema')->send();

        return response()->streamDownload(fn () => print ($xml), static::$reportKey.'_'.$this->period.($amended ? '_amended_v'.$f->version : '').'.xml', ['Content-Type' => 'application/xml']);
    }

    /** Saves/refreshes the draft for this period. Filed records are never overwritten. */
    public function saveDraft(): ?TaxFiling
    {
        [$s, $e] = $this->range();
        $f = $this->existingFiling();
        if ($f && $f->status !== 'draft') {
            return null; // approved/filed versions are locked; start an amendment from Tax filings
        }
        $f ??= new TaxFiling(['version' => 1, 'report_type' => static::$reportKey, 'period_start' => $s->toDateString(), 'period_end' => $e->toDateString()]);
        $f->fill(['summary' => $this->report['summary'], 'data' => $this->report, 'tax_due' => $this->report['tax_due'], 'status' => 'draft'])->save();

        return $f;
    }
}
