<?php

namespace Wonder\Plugin\Gestionale\Support\Payments;

use Wonder\Plugin\Gestionale\Models\Payments\PaymentMethod;
use Wonder\Plugin\Gestionale\Providers\Payments\PaymentProvider;
use Wonder\Plugin\Gestionale\Providers\Payments\StripeProvider;

/**
 * I provider che il checkout online può usare davvero: i manuali sempre,
 * quelli online solo quando il loro adapter dice che il collegamento è fatto.
 *
 * I test registrano un provider finto; `reset()` torna ai predefiniti.
 */
final class PaymentProviders
{
    /** @var array<string, PaymentProvider> */
    private static array $providers = [];

    public static function register(PaymentProvider $provider): void
    {
        self::$providers[$provider->code()] = $provider;
    }

    public static function get(string $code): ?PaymentProvider
    {
        // Stripe c'è sempre: se nessuno ha registrato un altro adapter (i test),
        // si usa quello vero, con le credenziali del sito.
        if (!isset(self::$providers[$code]) && $code === 'stripe') {
            self::$providers[$code] = new StripeProvider();
        }

        return self::$providers[$code] ?? null;
    }

    public static function reset(): void
    {
        self::$providers = [];
    }

    public static function connected(string $provider): bool
    {
        if (PaymentMethod::ledgerProvider($provider) === 'manual') {
            return true;
        }

        return self::get($provider)?->connected() ?? false;
    }
}
