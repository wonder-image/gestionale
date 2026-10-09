<?php

namespace Wonder\Plugin\Gestionale\Providers\Payments;

use Throwable;
use Wonder\App\Credentials;
use Wonder\Plugin\Gestionale\Models\Contacts\Contact;
use Wonder\Plugin\Gestionale\Models\Payments\PaymentMethod;
use Wonder\Plugin\Gestionale\Support\Contacts\Contacts;
use Wonder\Plugin\Gestionale\Support\Providers\ExternalReferences;
use Wonder\Plugin\Stripe\PaymentIntent;

/**
 * Stripe visto dal gestionale: addebiti diretti sul conto collegato del sito,
 * con le chiavi della piattaforma (Connect).
 *
 * L'ambiente lo decide `stripe_test` delle credenziali. Un evento si verifica
 * prima col segreto dell'ambiente attivo e poi con quello dell'altro: così un
 * evento di prova che arriva in produzione si riconosce e si lascia stare,
 * invece di finire tra le firme sbagliate.
 */
final class StripeProvider implements PaymentProvider
{
    /** Gli stati che Stripe lascia pagare di nuovo sullo stesso intento. */
    private const REUSABLE = ['requires_payment_method', 'requires_confirmation', 'requires_action'];

    private const STATES = [
        'succeeded' => PaymentState::SUCCEEDED,
        'processing' => PaymentState::PROCESSING,
        'requires_payment_method' => PaymentState::REQUIRES_PAYMENT_METHOD,
        'canceled' => PaymentState::CANCELED,
    ];

    public function __construct(private readonly ?object $api = null)
    {
    }

    public static function activeEnvironment(?object $api = null): string
    {
        return ($api ?? Credentials::api())->stripe_test ? 'test' : 'live';
    }

    /** @return list<string> */
    public static function methodTypes(string $column): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $column)), 'strlen'));
    }

    public function code(): string
    {
        return 'stripe';
    }

    public function environment(): string
    {
        return self::activeEnvironment($this->api());
    }

    public function connected(): bool
    {
        return !in_array('', $this->keys($this->environment()), true);
    }

    public function start(array $order, array $payment): PaymentStart
    {
        $environment = $this->environment();
        $intents = $this->intents($environment);
        $code = (string) $payment['code'];
        $previous = trim((string) ($payment['provider_reference'] ?? ''));
        $attempt = 1;

        if (str_starts_with($previous, 'pi_')) {
            $old = $intents->get($previous);

            if (in_array((string) $old->status, self::REUSABLE, true)) {
                return new PaymentStart((string) $old->id, (string) $old->client_secret, $environment);
            }

            // Già incassato o in verifica (SEPA): aprirne un altro farebbe pagare due volte.
            if (in_array((string) $old->status, ['succeeded', 'processing'], true)) {
                throw new \RuntimeException('Pagamento già incassato o in verifica: si attende la conferma di Stripe.');
            }

            $attempt = (int) ($old->metadata['attempt'] ?? 1) + 1;
        }

        $params = [
            'amount' => (int) round((float) $payment['amount'] * 100),
            'currency' => strtolower((string) ($order['currency'] ?? 'EUR')),
            'description' => (string) ($order['order_number'] ?? $order['code'] ?? ''),
            'metadata' => [
                'order_id' => (string) (int) $order['id'],
                'order_code' => (string) ($order['code'] ?? ''),
                'payment_code' => $code,
                'attempt' => (string) $attempt,
            ],
        ];

        $customer = $this->customer($order, $environment, $intents);
        if ($customer !== '') {
            $params['customer'] = $customer;
        }

        $types = self::methodTypes((string) ($this->method($order)['stripe_payment_method_types'] ?? ''));
        if ($types !== []) {
            $params['payment_method_types'] = $types;
        } else {
            $params['automatic_payment_methods'] = ['enabled' => true];
        }

        $intent = $intents->create($params, $attempt === 1 ? $code : $code.'-'.$attempt);

        return new PaymentStart((string) $intent->id, (string) $intent->client_secret, $environment);
    }

    public function status(string $reference): PaymentState
    {
        $intent = $this->intents($this->environment())->get($reference);

        return new PaymentState(
            self::STATES[(string) $intent->status] ?? PaymentState::OTHER,
            (int) (($intent->amount_received ?? 0) ?: $intent->amount),
            strtolower((string) $intent->currency),
            (int) ($intent->metadata['order_id'] ?? 0)
        );
    }

    public function event(string $raw, string $signature): ?PaymentEvent
    {
        $active = $this->environment();
        $verifiedBy = null;

        foreach ([$active, $active === 'test' ? 'live' : 'test'] as $environment) {
            $secret = $this->keys($environment)['webhook'];

            if ($secret === '' || $signature === '') {
                continue;
            }

            try {
                \Stripe\WebhookSignature::verifyHeader($raw, $signature, $secret, 300);
                $verifiedBy = $environment;
                break;
            } catch (Throwable) {
                // Firma non di questo ambiente: si prova l'altro.
            }
        }

        $data = json_decode($raw, true);

        if ($verifiedBy === null || !is_array($data)) {
            return null;
        }

        // Il segreto dice l'ambiente; se l'evento ne dice un altro, non si tocca nulla.
        return $this->replay($data, (bool) ($data['livemode'] ?? false) === ($verifiedBy === 'live') ? $verifiedBy : '');
    }

    public function replay(array $payload, string $environment): ?PaymentEvent
    {
        $object = $payload['data']['object'] ?? null;

        if (!is_array($object) || (string) ($payload['id'] ?? '') === '') {
            return null;
        }

        $isCharge = ($object['object'] ?? '') === 'charge';

        return new PaymentEvent(
            (string) $payload['id'],
            (string) ($payload['type'] ?? ''),
            $environment,
            (string) ($isCharge ? ($object['payment_intent'] ?? '') : ($object['id'] ?? '')),
            (int) ($isCharge ? ($object['amount'] ?? 0) : (($object['amount_received'] ?? 0) ?: ($object['amount'] ?? 0))),
            strtolower((string) ($object['currency'] ?? '')),
            (int) ($object['metadata']['order_id'] ?? 0),
            $isCharge && $environment !== '' ? $this->refunds($object, $environment) : [],
            (string) ($isCharge ? ($object['id'] ?? '') : ($object['latest_charge'] ?? '')),
            $payload
        );
    }

    public function cancel(string $reference): void
    {
        $this->intents($this->environment())->cancel($reference);
    }

    private function api(): object
    {
        return $this->api ?? Credentials::api();
    }

    /** @return array{secret: string, account: string, public: string, webhook: string} */
    private function keys(string $environment): array
    {
        $api = $this->api();
        $test = $environment === 'test';

        return [
            'secret' => trim((string) ($test ? ($api->stripe_test_key ?? '') : ($api->stripe_private_key ?? ''))),
            'account' => trim((string) ($test ? ($api->stripe_test_account_id ?? '') : ($api->stripe_account_id ?? ''))),
            'public' => trim((string) ($test ? ($api->stripe_test_public_key ?? '') : ($api->stripe_public_key ?? ''))),
            'webhook' => trim((string) ($test ? ($api->stripe_test_webhook_secret ?? '') : ($api->stripe_webhook_secret ?? ''))),
        ];
    }

    private function intents(string $environment): PaymentIntent
    {
        $keys = $this->keys($environment);

        return new PaymentIntent($keys['secret'], $keys['account']);
    }

    /** Il Customer della scheda in questo ambiente: lo crea al primo pagamento. */
    private function customer(array $order, string $environment, PaymentIntent $intents): string
    {
        $contactId = (int) ($order['customer_id'] ?? 0);

        if ($contactId <= 0) {
            return '';
        }

        $saved = ExternalReferences::find('contact', $contactId, 'stripe', 'customer', $environment);

        if ($saved !== null && trim((string) $saved['external_id']) !== '') {
            return (string) $saved['external_id'];
        }

        $contact = Contact::findById($contactId);
        $customer = $intents->createCustomer(array_filter([
            'email' => (string) ($order['email'] ?? ''),
            'name' => is_array($contact) && $contact !== [] ? Contacts::displayName($contact) : '',
            'metadata' => ['contact_id' => (string) $contactId],
        ]), 'contact-'.$contactId.'-'.$environment);

        ExternalReferences::save('contact', $contactId, 'stripe', 'customer', (string) $customer->id, $environment);

        return (string) $customer->id;
    }

    /** @return array<string, mixed> */
    private function method(array $order): array
    {
        $id = (int) ($order['payment_method_id'] ?? 0);
        $method = $id > 0 ? PaymentMethod::findById($id) : null;

        return is_array($method) ? $method : [];
    }

    /**
     * I rimborsi della carica. Da un certo API in poi la carica non li porta
     * più con sé: allora si chiedono a Stripe.
     *
     * @return list<array{id: string, amount: int, status: string}>
     */
    private function refunds(array $charge, string $environment): array
    {
        $list = $charge['refunds']['data'] ?? null;

        if (!is_array($list)) {
            return $this->intents($environment)->refundsOf((string) ($charge['id'] ?? ''));
        }

        return array_map(static fn (array $refund): array => [
            'id' => (string) ($refund['id'] ?? ''),
            'amount' => (int) ($refund['amount'] ?? 0),
            'status' => (string) ($refund['status'] ?? ''),
        ], array_values(array_filter($list, 'is_array')));
    }
}
