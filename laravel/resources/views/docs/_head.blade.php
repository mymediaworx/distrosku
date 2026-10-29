<style>
body{font-family:DejaVu Sans,sans-serif;font-size:10.5px;color:#111}h1{font-size:18px;margin:0}table{width:100%;border-collapse:collapse}
td,th{padding:4px;border-bottom:1px solid #ddd;text-align:left}.r{text-align:right}.muted{color:#666}.box{border:1px solid #ccc;padding:6px}
.tot td{font-weight:bold;border-top:2px solid #111}
</style>
<table style="border:0"><tr>
    <td style="border:0">@if($company?->logo_path && ($logo = \App\Catalog\ImageService::dataUri($company->logo_path)))<img src="{{ $logo }}" style="max-height:50px;max-width:180px"><div style="height:10px"></div>@endif
        <strong style="font-size:13px">{{ $company->name ?? $info }}</strong>
        @php($addr = array_filter([$company?->address_line1, $company?->address_line2, trim(implode(' ', array_filter([$company?->city ? $company->city.',' : null, $company?->state, $company?->postal_code])))]))
        @foreach($addr as $line)<br><span class="muted">{{ $line }}</span>@endforeach
        @if(filled($company?->phone))<br><span class="muted">Phone: {{ $company->phone }}</span>@endif
        @if(filled($company?->email))<br><span class="muted">{{ $company->email }}</span>@endif</td>
    <td style="border:0" class="r"><h1>{{ $docTitle }}</h1>{{ $docSub ?? '' }}</td>
</tr></table>
