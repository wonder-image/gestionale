<?php

namespace Wonder\Plugin\Gestionale\Providers\Payments;

/** Lo stato di un intento, come lo dice il gateway. Importo in centesimi. */
final class PaymentState
{
    public const SUCCEEDED = 'succeeded';
    public const PROCESSING = 'processing';
    public const REQUIRES_PAYMENT_METHOD = 'requires_payment_method';
    public const CANCELED = 'canceled';
    public const OTHER = 'other';

    public function __construct(
        public readonly string $status,
        public readonly int $amount = 0,
        public readonly string $currency = '',
        public readonly int $orderId = 0,
    ) {
    }
}
