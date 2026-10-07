<?php

namespace Wonder\Plugin\Gestionale\Support\Payments;

use Wonder\Plugin\Gestionale\Models\Payments\PaymentMethod;

/**
 * I provider che il checkout online può usare davvero: i manuali sempre,
 * quelli online solo quando il collegamento è fatto (D6).
 */
final class PaymentProviders
{
    /** @var list<string> */
    private const ONLINE = [];

    public static function connected(string $provider): bool
    {
        return PaymentMethod::ledgerProvider($provider) === 'manual' || in_array($provider, self::ONLINE, true);
    }
}
