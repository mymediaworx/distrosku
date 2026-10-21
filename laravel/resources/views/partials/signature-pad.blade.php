{{-- Phase 15 signature capture. Needs $acks (list of texts). Binds sigImage / sigName / sigAccepted / lat / lng on the Livewire component. --}}
<div class="sigbox" data-test="signature-pad" wire:key="sigpad-{{ md5(implode('|', $acks)) }}"
     x-data="{ drawing:false, has:false, ctx:null,
        init(){ const c=this.$refs.c; const r=()=>{const w=c.parentElement.clientWidth; c.width=w; c.height=Math.max(160, Math.round(w*0.38)); this.ctx=c.getContext('2d'); this.ctx.lineWidth=2.5; this.ctx.lineCap='round'; this.ctx.strokeStyle='#111'; this.has=false; $wire.set('sigImage','', false)}; r(); window.addEventListener('orientationchange', ()=>setTimeout(r,300));
              if (navigator.geolocation) navigator.geolocation.getCurrentPosition(p=>{ $wire.set('lat', String(p.coords.latitude), false); $wire.set('lng', String(p.coords.longitude), false) }, ()=>{}, {timeout:8000}); },
        pos(e){ const b=this.$refs.c.getBoundingClientRect(); return [e.clientX-b.left, e.clientY-b.top] },
        down(e){ this.drawing=true; this.$refs.c.setPointerCapture(e.pointerId); const [x,y]=this.pos(e); this.ctx.beginPath(); this.ctx.moveTo(x,y) },
        move(e){ if(!this.drawing) return; const [x,y]=this.pos(e); this.ctx.lineTo(x,y); this.ctx.stroke(); this.has=true },
        up(){ if(!this.drawing) return; this.drawing=false; if(this.has) $wire.set('sigImage', this.$refs.c.toDataURL('image/png'), false) },
        clear(){ this.ctx.clearRect(0,0,this.$refs.c.width,this.$refs.c.height); this.has=false; $wire.set('sigImage','', false) } }">
    <p style="margin:.2rem 0 .5rem"><strong>Signature required</strong> &mdash; this order contains age-restricted (regulated) products.</p>
    @foreach($acks as $i => $text)
        <label class="ack" style="display:flex;gap:.6rem;align-items:flex-start;font-weight:400;font-size:1rem;margin:.5rem 0">
            <input type="checkbox" wire:model="sigAccepted.{{ $i }}" style="width:28px;height:28px;flex:none" data-test="ack-{{ $i }}"> <span>{{ $text }}</span></label>
    @endforeach
    <label for="sigName">Printed name of signer</label>
    <input id="sigName" wire:model="sigName" autocomplete="name" placeholder="Type full name" data-test="sig-name">
    <div style="margin-top:.5rem;border:2px dashed #94a3b8;border-radius:10px;background:#fff;touch-action:none" wire:ignore>
        <canvas x-ref="c" style="display:block;width:100%;touch-action:none" @pointerdown.prevent="down($event)" @pointermove.prevent="move($event)" @pointerup="up()" @pointercancel="up()" @pointerleave="up()" aria-label="Sign here"></canvas>
    </div>
    <div style="display:flex;justify-content:space-between;align-items:center;margin-top:.3rem"><small style="color:#64748b">Sign above with a finger or stylus</small>
        <button type="button" class="btn2" style="width:auto;padding:.4rem .9rem" @click="clear()">Clear</button></div>
</div>
