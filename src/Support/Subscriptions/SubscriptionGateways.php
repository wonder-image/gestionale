<?php

namespace Wonder\Plugin\Gestionale\Support\Subscriptions;

use Wonder\Plugin\Gestionale\Providers\Payments\StripeProvider;
use Wonder\Plugin\Gestionale\Providers\Payments\SubscriptionGateway;
use Wonder\Plugin\Gestionale\Support\Payments\PaymentProviders;

/**
 * Da dove passano le chiamate a Stripe degli abbonamenti: quello vero, con le
 * chiavi del sito, salvo che un test ne abbia messo uno finto con `use()`.
 */
final class SubscriptionGateways
{
    private static ?SubscriptionGateway $override = null;

    /** Un gateway finto per i test; `null` torna a quello vero. */
    public static function use(?SubscriptionGateway $gateway): void
    {
        self::$override = $gateway;
    }

    /** Il gateway sull'ambiente dato (quello attivo se vuoto); `null` se Stripe non c'è. */
    public static function get(string $environment = ''): ?SubscriptionGateway
    {
        if (self::$override !== null) {
            return self::$override;
        }

        $provider = PaymentProviders::get('stripe');

        return $provider instanceof StripeProvider ? $provider->billing($environment !== '' ? $environment : null) : null;
    }
}
