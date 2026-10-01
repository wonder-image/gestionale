<?php
/** php tests/OrderStatusLabelsTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Support\Orders\StatusLabels;

check('ogni stato dell\'ordine ha la sua etichetta e il suo colore', function () {
    foreach (Order::STATUSES as $stato) {
        $voce = StatusLabels::order($stato);

        if ($voce['label'] === $stato || $voce['color'] === 'secondary' && !in_array($stato, ['draft', 'expired'], true)) {
            return false;
        }
    }

    return true;
});

check('ogni stato del pagamento ha etichetta e colore', function () {
    foreach (Order::PAYMENT_STATUSES as $stato) {
        if (StatusLabels::payment($stato)['label'] === $stato) {
            return false;
        }
    }

    return true;
});

check('ogni stato dell\'evasione ha etichetta e colore', function () {
    foreach (Order::FULFILLMENT_STATUSES as $stato) {
        if (StatusLabels::fulfillment($stato)['label'] === $stato) {
            return false;
        }
    }

    return true;
});

check('pagato ed evaso hanno lo stesso verde, annullato no', function () {
    return StatusLabels::payment('paid')['color'] === 'success'
        && StatusLabels::fulfillment('fulfilled')['color'] === 'success'
        && StatusLabels::order('confirmed')['color'] === 'success'
        && StatusLabels::order('cancelled')['color'] === 'danger';
});

check('un valore sconosciuto torna com\'è, in grigio, senza eccezioni', function () {
    $voce = StatusLabels::order('strano');

    return $voce === ['label' => 'strano', 'color' => 'secondary']
        && StatusLabels::payment('')['color'] === 'secondary';
});

check('il badge è un\'etichetta colorata ed esce escapata', function () {
    $html = StatusLabels::badge('order', 'confirmed');
    $sporco = StatusLabels::badge('order', '<script>alert(1)</script>');

    return str_contains($html, 'text-bg-success')
        && str_contains($html, 'Confermato')
        && !str_contains($sporco, '<script>')
        && str_contains($sporco, '&lt;script&gt;');
});

check('un tipo di badge sconosciuto non fa cadere niente', function () {
    return str_contains(StatusLabels::badge('boh', 'x'), 'x');
});

summary();
