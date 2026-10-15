<?php

namespace Wonder\Plugin\Gestionale\Providers\Payments;

use Stripe\StripeClient;
use Wonder\Plugin\Stripe\Stripe;

/**
 * Gli abbonamenti su Stripe, sul conto collegato del sito, con le chiavi che
 * passa `StripeProvider::billing()`: le stesse del pagamento degli ordini,
 * risolte nello stesso modo.
 *
 * Come `Wonder\Plugin\Stripe\PaymentIntent` del core: un `StripeClient` con la
 * versione dell'API fissata dal core e l'opzione `stripe_account`.
 */
final class StripeBilling implements SubscriptionGateway
{
    private ?StripeClient $client = null;

    public function __construct(
        private readonly string $secretKey,
        private readonly string $accountId,
        private readonly string $environment
    ) {
    }

    public function environment(): string
    {
        return $this->environment;
    }

    public function connected(): bool
    {
        return $this->secretKey !== '' && $this->accountId !== '';
    }

    public function createProduct(array $params, string $idempotencyKey): array
    {
        return $this->client()->products->create($params, $this->options($idempotencyKey))->toArray();
    }

    public function createPrice(array $params, string $idempotencyKey): array
    {
        return $this->client()->prices->create($params, $this->options($idempotencyKey))->toArray();
    }

    public function createTaxRate(array $params, string $idempotencyKey): array
    {
        return $this->client()->taxRates->create($params, $this->options($idempotencyKey))->toArray();
    }

    public function createCoupon(array $params, string $idempotencyKey): array
    {
        return $this->client()->coupons->create($params, $this->options($idempotencyKey))->toArray();
    }

    public function createPromotionCode(array $params, string $idempotencyKey): array
    {
        return $this->client()->promotionCodes->create($params, $this->options($idempotencyKey))->toArray();
    }

    public function updatePromotionCode(string $id, array $params): array
    {
        return $this->client()->promotionCodes->update($id, $params, $this->options())->toArray();
    }

    public function createCustomer(array $params, string $idempotencyKey): array
    {
        return $this->client()->customers->create($params, $this->options($idempotencyKey))->toArray();
    }

    public function createCheckoutSession(array $params, string $idempotencyKey): array
    {
        return $this->client()->checkout->sessions->create($params, $this->options($idempotencyKey))->toArray();
    }

    public function retrieveCheckoutSession(string $id): array
    {
        return $this->client()->checkout->sessions->retrieve($id, [], $this->options())->toArray();
    }

    public function retrieveSubscription(string $id): array
    {
        return $this->client()->subscriptions->retrieve($id, [], $this->options())->toArray();
    }

    public function updateSubscription(string $id, array $params): array
    {
        return $this->client()->subscriptions->update($id, $params, $this->options())->toArray();
    }

    public function createPortalSession(array $params): array
    {
        return $this->client()->billingPortal->sessions->create($params, $this->options())->toArray();
    }

    public function createSubscriptionItem(array $params, string $idempotencyKey): array
    {
        return $this->client()->subscriptionItems->create($params, $this->options($idempotencyKey))->toArray();
    }

    public function updateSubscriptionItem(string $id, array $params): array
    {
        return $this->client()->subscriptionItems->update($id, $params, $this->options())->toArray();
    }

    public function deleteSubscriptionItem(string $id, array $params): array
    {
        return $this->client()->subscriptionItems->delete($id, $params, $this->options())->toArray();
    }

    public function createSubscriptionSchedule(array $params, string $idempotencyKey): array
    {
        return $this->client()->subscriptionSchedules->create($params, $this->options($idempotencyKey))->toArray();
    }

    public function updateSubscriptionSchedule(string $id, array $params): array
    {
        return $this->client()->subscriptionSchedules->update($id, $params, $this->options())->toArray();
    }

    public function retrieveSubscriptionSchedule(string $id): array
    {
        return $this->client()->subscriptionSchedules->retrieve($id, [], $this->options())->toArray();
    }

    public function releaseSubscriptionSchedule(string $id): array
    {
        return $this->client()->subscriptionSchedules->release($id, [], $this->options())->toArray();
    }

    public function previewInvoice(array $params): array
    {
        return $this->client()->invoices->createPreview($params, $this->options())->toArray();
    }

    private function client(): StripeClient
    {
        return $this->client ??= new StripeClient(['api_key' => $this->secretKey, 'stripe_version' => Stripe::API_VERSION]);
    }

    /** @return array<string, string> */
    private function options(string $idempotencyKey = ''): array
    {
        $options = ['stripe_account' => $this->accountId];

        if ($idempotencyKey !== '') {
            $options['idempotency_key'] = $idempotencyKey;
        }

        return $options;
    }
}
