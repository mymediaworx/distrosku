<table style="width:100%;font-size:.9rem" data-test="aging">
    <tr>@foreach(['0-30' => 'Current (0-30)', '31-60' => '31-60', '61-90' => '61-90', '90+' => 'Over 90', 'total' => 'Total balance'] as $t)<th style="text-align:right;padding:4px">{{ $t }}</th>@endforeach</tr>
    <tr>@foreach(['0-30', '31-60', '61-90', '90+', 'total'] as $k)<td style="text-align:right;padding:4px">{{ number_format((float) $aging[$k], 2) }}</td>@endforeach</tr>
</table>
