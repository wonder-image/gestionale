<?php

namespace Wonder\Plugin\Gestionale\Providers\Payments;

/**
 * Un evento del gateway con la firma verificata.
 *
 * `environment` è quello del segreto che ha verificato la firma; vuoto quando
 * l'evento dice un ambiente diverso: in quel caso non si tocca niente.
 */
final class PaymentEvent
{
    /**
     * @param list<array{id: string, amount: int, status: string}> $refunds
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly string $environment,
        public readonly string $reference = '',
        public readonly int $amount = 0,
        public readonly string $currency = '',
        public readonly int $orderId = 0,
        public readonly array $refunds = [],
        public readonly string $chargeId = '',
        public readonly array $payload = [],
    ) {
    }
}
