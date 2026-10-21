<?php

namespace App\Filament\Pages;

use App\Reports\KyTobaccoXml;

class KyTobaccoDistributorReport extends BaseTaxReportPage
{
    protected static ?string $xmlClass = KyTobaccoXml::class;

    protected static string $reportKey = 'ky_tobacco_distributor';

    protected static ?string $navigationLabel = 'KY Tobacco Distributor (73A422)';

    protected static ?string $navigationIcon = 'heroicon-o-document-chart-bar';

    protected static ?string $slug = 'reports/ky-tobacco-distributor';
}
