<?php

namespace Wonder\Plugin\Gestionale\Providers\Payments;

/** L'intento aperto: il riferimento per noi, il segreto per il browser. */
final class PaymentStart
{
    public function __construct(
        public readonly string $reference,
        public readonly string $clientSecret,
        public readonly string $environment,
    ) {
    }
}
