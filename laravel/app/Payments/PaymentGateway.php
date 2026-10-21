<?php

namespace App\Payments;

use App\Models\Customer;

/** Processor-neutral contract. Swap the bound implementation in AppServiceProvider to change processors. */
interface PaymentGateway
{
    public function name(): string;

    public function isConfigured(): bool;

    /** Why it is not configured (null when configured). */
    public function configurationProblem(): ?string;

    /** Public values the browser tokeniser needs (never the transaction key). */
    public function clientConfig(): array;

    /**
     * Turn a one-time browser token (Accept.js opaque data) into a stored customer/payment profile.
     * Returns success + extra['customer_profile_id'], extra['payment_profile_id'].
     */
    public function storeToken(Customer $customer, string $descriptor, string $value, ?string $existingCustomerProfileId = null): GatewayResult;

    public function charge(string $customerProfileId, string $paymentProfileId, string $amount, string $invoiceNumber): GatewayResult;

    public function refund(string $customerProfileId, string $paymentProfileId, string $transactionId, string $amount): GatewayResult;

    public function void(string $transactionId): GatewayResult;
}
