<style>
body{font-family:DejaVu Sans,sans-serif;font-size:10.5px;color:#111}h1{font-size:18px;margin:0}table{width:100%;border-collapse:collapse}
td,th{padding:4px;border-bottom:1px solid #ddd;text-align:left}.r{text-align:right}.muted{color:#666}.box{border:1px solid #ccc;padding:6px}
.tot td{font-weight:bold;border-top:2px solid #111}
</style>
<table style="border:0"><tr>
    <td style="border:0">@if($company?->logo_path && ($logo = \App\Catalog\ImageService::dataUri($company->logo_path)))<img src="{{ $logo }}" style="max-height:50px;max-width:180px"><br>@endif
        <strong>{{ $company->name ?? $info }}</strong></td>
    <td style="border:0" class="r"><h1>{{ $docTitle }}</h1>{{ $docSub ?? '' }}</td>
</tr></table>
