<?php

namespace App\Payments;

use App\Models\Customer;
use App\Models\Setting;
use App\Services\Money;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Authorize.net JSON API, SANDBOX ONLY (apitest.authorize.net). Card/bank data is tokenised in the browser by Accept.js;
 * this class only ever sees opaque tokens and CIM profile ids. Element order in requests follows the Authorize.net schema.
 */
class AuthorizeNetGateway implements PaymentGateway
{
    public const SANDBOX_API = 'https://apitest.authorize.net/xml/v1/request.api';

    public const SANDBOX_ACCEPT_JS = 'https://jstest.authorize.net/v1/Accept.js';

    public function name(): string
    {
        return 'authorizenet';
    }

    public function loginId(): ?string
    {
        return Setting::get('authnet_login_id', config('erp.authorizenet.login_id'));
    }

    protected function transactionKey(): ?string
    {
        return Setting::get('authnet_transaction_key', config('erp.authorizenet.transaction_key'));
    }

    public function clientKey(): ?string
    {
        return Setting::get('authnet_client_key', config('erp.authorizenet.client_key'));
    }

    public function environment(): string
    {
        return (string) config('erp.authorizenet.environment', 'sandbox');
    }

    public function configurationProblem(): ?string
    {
        if ($this->environment() !== 'sandbox') {
            return 'AUTHNET_ENV must be "sandbox". Live processing is disabled in this build.';
        }
        if (! $this->loginId() || ! $this->transactionKey() || ! $this->clientKey()) {
            return 'Authorize.net sandbox keys are not configured (Settings > Payments).';
        }

        return null;
    }

    public function isConfigured(): bool
    {
        return $this->configurationProblem() === null;
    }

    public function clientConfig(): array
    {
        return ['apiLoginId' => $this->loginId(), 'clientKey' => $this->clientKey(), 'acceptJsUrl' => self::SANDBOX_ACCEPT_JS, 'environment' => 'sandbox'];
    }

    protected function auth(): array
    {
        return ['name' => $this->loginId(), 'transactionKey' => $this->transactionKey()];
    }

    protected function send(string $root, array $body): array
    {
        if (! $this->isConfigured()) {
            return ['_error' => $this->configurationProblem()];
        }
        try {
            $res = Http::timeout(30)->acceptJson()->asJson()
                ->post(self::SANDBOX_API, [$root => ['merchantAuthentication' => $this->auth()] + $body]);
            // Authorize.net prefixes JSON with a UTF-8 BOM.
            $json = json_decode(preg_replace('/^\xEF\xBB\xBF/', '', $res->body()), true);

            return is_array($json) ? $json : ['_error' => 'Unreadable gateway response (HTTP '.$res->status().').'];
        } catch (Throwable $e) {
            return ['_error' => 'Gateway unreachable: '.$e->getMessage()];
        }
    }

    protected static function msg(array $r): array
    {
        $m = $r['messages']['message'][0] ?? [];

        return [$m['code'] ?? null, $m['text'] ?? ($r['_error'] ?? 'Unknown error')];
    }

    public function storeToken(Customer $customer, string $descriptor, string $value, ?string $existingCustomerProfileId = null): GatewayResult
    {
        $payment = ['opaqueData' => ['dataDescriptor' => $descriptor, 'dataValue' => $value]];
        if (! $existingCustomerProfileId) {
            $r = $this->send('createCustomerProfileRequest', [
                'profile' => array_filter([
                    'merchantCustomerId' => substr((string) $customer->code, 0, 20),
                    'description' => substr((string) $customer->name, 0, 255),
                    'email' => $customer->email ?: null,
                    'paymentProfiles' => ['customerType' => 'business', 'payment' => $payment],
                ], fn ($v) => $v !== null && $v !== ''),
                'validationMode' => 'testMode',
            ]);
            [$code, $text] = self::msg($r);
            if (($r['messages']['resultCode'] ?? '') === 'Ok') {
                return new GatewayResult(true, null, $code, $text, [
                    'customer_profile_id' => (string) $r['customerProfileId'],
                    'payment_profile_id' => (string) ($r['customerPaymentProfileIdList'][0] ?? ''),
                ]);
            }
            // E00039 duplicate profile: reuse the existing id and add the payment profile to it.
            if ($code === 'E00039' && preg_match('/ID (\d+)/', (string) $text, $m)) {
                $existingCustomerProfileId = $m[1];
            } else {
                return new GatewayResult(false, null, $code, $text);
            }
        }
        $r = $this->send('createCustomerPaymentProfileRequest', [
            'customerProfileId' => $existingCustomerProfileId,
            'paymentProfile' => ['customerType' => 'business', 'payment' => $payment],
            'validationMode' => 'testMode',
        ]);
        [$code, $text] = self::msg($r);
        $ok = ($r['messages']['resultCode'] ?? '') === 'Ok';

        return new GatewayResult($ok, null, $code, $text, $ok ? [
            'customer_profile_id' => (string) $existingCustomerProfileId,
            'payment_profile_id' => (string) $r['customerPaymentProfileId'],
        ] : []);
    }

    protected function transaction(array $request): GatewayResult
    {
        $r = $this->send('createTransactionRequest', ['transactionRequest' => $request]);
        $t = $r['transactionResponse'] ?? [];
        $ok = ($r['messages']['resultCode'] ?? '') === 'Ok' && ($t['responseCode'] ?? '') === '1';
        [$code, $text] = self::msg($r);
        if (! $ok && isset($t['errors'][0])) {
            [$code, $text] = [$t['errors'][0]['errorCode'] ?? $code, $t['errors'][0]['errorText'] ?? $text];
        } elseif ($ok && isset($t['messages'][0]['description'])) {
            $text = $t['messages'][0]['description'];
        }
        $id = $t['transId'] ?? null;

        return new GatewayResult($ok, $id && $id !== '0' ? (string) $id : null, $code, $text, ['response_code' => $t['responseCode'] ?? null]);
    }

    protected static function profile(string $c, string $p): array
    {
        return ['customerProfileId' => $c, 'paymentProfile' => ['paymentProfileId' => $p]];
    }

    public function charge(string $customerProfileId, string $paymentProfileId, string $amount, string $invoiceNumber): GatewayResult
    {
        return $this->transaction([
            'transactionType' => 'authCaptureTransaction',
            'amount' => (string) Money::of($amount)->toScale(2, RoundingMode::HALF_UP),
            'profile' => self::profile($customerProfileId, $paymentProfileId),
            'order' => ['invoiceNumber' => substr($invoiceNumber, 0, 20)],
        ]);
    }

    public function refund(string $customerProfileId, string $paymentProfileId, string $transactionId, string $amount): GatewayResult
    {
        return $this->transaction([
            'transactionType' => 'refundTransaction',
            'amount' => (string) Money::of($amount)->toScale(2, RoundingMode::HALF_UP),
            'profile' => self::profile($customerProfileId, $paymentProfileId),
            'refTransId' => $transactionId,
        ]);
    }

    public function void(string $transactionId): GatewayResult
    {
        return $this->transaction(['transactionType' => 'voidTransaction', 'refTransId' => $transactionId]);
    }
}
