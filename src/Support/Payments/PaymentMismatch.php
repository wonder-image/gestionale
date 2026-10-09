<?php

namespace Wonder\Plugin\Gestionale\Support\Payments;

use RuntimeException;

/**
 * Il gateway dice di aver incassato, ma importo, valuta o ordine non tornano.
 * Non si conferma e non si riprova: lo guarda il commerciante.
 */
final class PaymentMismatch extends RuntimeException {}
