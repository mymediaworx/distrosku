<div>
    <div class="card">
        <h3>Make a payment</h3>
        @if($selected)
            <p>Invoice <strong>{{ $selected->number }}</strong>: ${{ number_format((float) $amount, 2) }} <a href="{{ route('portal.pay') }}">pay full balance instead</a></p>
        @else
            <p>Full open balance: <strong>${{ number_format((float) $amount, 2) }}</strong></p>
            @if((float) $opening > 0)<p class="muted">Beginning balance ${{ number_format((float) $opening, 2) }}</p>@endif
            @foreach($invoices as $i)<div class="muted">{{ $i->number }} · ${{ number_format((float) $i->balance(), 2) }} · <a href="{{ route('portal.pay', ['invoice' => $i->id]) }}">pay only this</a></div>@endforeach
        @endif
        @foreach($done as $d)<p class="ok">Paid {{ $d }}</p>@endforeach
        @if($error)<p class="err">{{ $error }}</p>@endif
    </div>
    @if(! $configured)
        <div class="card" data-test="not-configured"><strong>Online payments are not configured yet.</strong>
            <p class="muted">Please pay by check or contact us. ({{ $problem }})</p></div>
    @elseif((float) $amount <= 0)
        <div class="card">Nothing to pay. Thank you!</div>
    @else
        <div class="card" x-data="portalPay(@js(['apiLoginId' => $cfg['apiLoginId'], 'clientKey' => $cfg['clientKey']]))" wire:ignore.self>
            <p class="muted">Card numbers are sent directly to Authorize.net (sandbox) and never stored here.</p>
            <label>Card number</label><input x-ref="card" inputmode="numeric" autocomplete="cc-number">
            <div class="inline"><div><label>MM</label><input x-ref="mm" inputmode="numeric" maxlength="2" autocomplete="cc-exp-month"></div>
                <div><label>YY</label><input x-ref="yy" inputmode="numeric" maxlength="4" autocomplete="cc-exp-year"></div>
                <div><label>CVV</label><input x-ref="cvv" inputmode="numeric" maxlength="4" autocomplete="cc-csc"></div></div>
            <p x-text="error" class="err"></p>
            <p><button type="button" x-on:click="card()" x-bind:disabled="busy">Pay ${{ number_format((float) $amount, 2) }}</button></p>
        </div>
        @push('scripts')
            <script src="{{ $cfg['acceptJsUrl'] }}" charset="utf-8"></script>
            <script>
                function portalPay(cfg) {
                    return {
                        error: '', busy: false,
                        card() {
                            const n = this.$refs.card.value.replace(/\D/g, '');
                            const secure = { authData: { clientKey: cfg.clientKey, apiLoginID: cfg.apiLoginId },
                                cardData: { cardNumber: n, month: this.$refs.mm.value, year: this.$refs.yy.value, cardCode: this.$refs.cvv.value } };
                            this.busy = true; this.error = '';
                            Accept.dispatchData(secure, (r) => {
                                if (r.messages.resultCode === 'Error') { this.busy = false; this.error = r.messages.message.map(m => m.text).join(' '); return; }
                                ['card', 'cvv'].forEach(k => this.$refs[k].value = '');
                                this.$wire.pay(r.opaqueData.dataDescriptor, r.opaqueData.dataValue, 'card', 'Card ••' + n.slice(-4)).then(() => this.busy = false);
                            });
                        },
                    };
                }
            </script>
        @endpush
    @endif
</div>
