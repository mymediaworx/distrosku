<x-filament-panels::page>
    @if($problem = $this->gatewayProblem())
        <x-filament::section>
            <p data-test="not-configured"><strong>Card &amp; ACH payments are not configured.</strong> {{ $problem }}</p>
        </x-filament::section>
    @else
        @php($so = $this->order())
        <x-filament::section heading="Invoice">
            <select wire:model.live="orderId" class="fi-input block w-full rounded-lg">
                <option value="">Choose an invoice</option>
                @foreach($this->openOrders() as $o)
                    <option value="{{ $o->id }}">{{ $o->number }} | {{ $o->customer->name }} | balance {{ number_format((float) $o->balance(), 2) }}</option>
                @endforeach
            </select>
        </x-filament::section>

        @if($so)
            <x-filament::section heading="Charge a saved method">
                <div class="grid gap-3 sm:grid-cols-3">
                    <select wire:model="methodId" class="fi-input rounded-lg">
                        <option value="">Choose a method</option>
                        @foreach($so->customer->paymentMethods()->where('active', true)->get() as $m)
                            <option value="{{ $m->id }}">{{ strtoupper($m->type) }} {{ $m->label }}</option>
                        @endforeach
                    </select>
                    <input type="number" step="0.01" wire:model="amount" class="fi-input rounded-lg" aria-label="Amount">
                    <x-filament::button wire:click="charge" wire:confirm="Charge this amount?">Charge</x-filament::button>
                </div>
            </x-filament::section>

            @php($cfg = $this->clientConfig())
            <x-filament::section heading="Add a card or bank account (tokenised by Authorize.net Accept.js)">
                <div x-data="acceptForm(@js($cfg))" class="grid gap-3 sm:grid-cols-2">
                    <div class="space-y-2">
                        <strong>Card</strong>
                        {{-- No name attributes: card data never posts to this server. --}}
                        <input x-ref="card" placeholder="Card number" autocomplete="off" inputmode="numeric" class="fi-input w-full rounded-lg">
                        <div class="flex gap-2">
                            <input x-ref="mm" placeholder="MM" class="fi-input w-16 rounded-lg"><input x-ref="yy" placeholder="YY" class="fi-input w-16 rounded-lg">
                            <input x-ref="cvv" placeholder="CVV" autocomplete="off" class="fi-input w-20 rounded-lg">
                        </div>
                        <x-filament::button type="button" x-on:click="card()">Save card</x-filament::button>
                    </div>
                    <div class="space-y-2">
                        <strong>eCheck (ACH)</strong>
                        <input x-ref="routing" placeholder="Routing number" autocomplete="off" class="fi-input w-full rounded-lg">
                        <input x-ref="account" placeholder="Account number" autocomplete="off" class="fi-input w-full rounded-lg">
                        <input x-ref="holder" placeholder="Name on account" class="fi-input w-full rounded-lg">
                        <select x-ref="acctType" class="fi-input rounded-lg"><option value="businessChecking">Business checking</option><option value="checking">Checking</option><option value="savings">Savings</option></select>
                        <x-filament::button type="button" x-on:click="bank()">Save bank account</x-filament::button>
                    </div>
                    <p x-text="error" class="text-danger-600"></p>
                </div>
            </x-filament::section>
            <script src="{{ $cfg['acceptJsUrl'] }}" charset="utf-8"></script>
            <script>
                function acceptForm(cfg) {
                    return {
                        error: '',
                        send(secure, type, label) {
                            secure.authData = { clientKey: cfg.clientKey, apiLoginID: cfg.apiLoginId };
                            Accept.dispatchData(secure, (r) => {
                                if (r.messages.resultCode === 'Error') { this.error = r.messages.message.map(m => m.text).join(' '); return; }
                                ['card', 'cvv', 'routing', 'account'].forEach(k => this.$refs[k].value = '');
                                this.$wire.saveMethod(r.opaqueData.dataDescriptor, r.opaqueData.dataValue, type, label);
                            });
                        },
                        card() {
                            const n = this.$refs.card.value.replace(/\D/g, '');
                            this.send({ cardData: { cardNumber: n, month: this.$refs.mm.value, year: this.$refs.yy.value, cardCode: this.$refs.cvv.value } }, 'card', 'Card ••' + n.slice(-4));
                        },
                        bank() {
                            const a = this.$refs.account.value.replace(/\D/g, '');
                            this.send({ bankData: { accountNumber: a, routingNumber: this.$refs.routing.value.replace(/\D/g, ''), nameOnAccount: this.$refs.holder.value, accountType: this.$refs.acctType.value } }, 'ach', 'Bank ••' + a.slice(-4));
                        },
                    };
                }
            </script>
        @endif
    @endif
</x-filament-panels::page>
