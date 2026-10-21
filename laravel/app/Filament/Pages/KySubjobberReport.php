<?php

namespace App\Filament\Pages;

use App\Reports\KyCigaretteXml;

class KySubjobberReport extends BaseTaxReportPage
{
    protected static ?string $xmlClass = KyCigaretteXml::class;

    protected static string $reportKey = 'ky_subjobber_cigarette';

    protected static ?string $navigationLabel = 'KY Subjobber (cigarettes)';

    protected static ?string $navigationIcon = 'heroicon-o-document-chart-bar';

    protected static ?string $slug = 'reports/ky-subjobber';
}
