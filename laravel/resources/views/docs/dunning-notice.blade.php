<!doctype html><html><head><meta charset="utf-8"></head><body>
@include('docs._head', ['docTitle' => 'PAYMENT REMINDER', 'docSub' => now()->format('m/d/Y')])
<p class="box"><strong>{{ $n->customer->name }}</strong> ({{ $n->customer->code }})@if(filled($n->to_email))<br>{{ $n->to_email }}@endif</p>
<h3>{{ $n->subject }}</h3>
<div style="white-space:pre-wrap;font-size:11px;line-height:1.5">{{ $n->body }}</div>
</body></html>
