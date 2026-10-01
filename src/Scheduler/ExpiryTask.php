<?php

namespace Wonder\Plugin\Gestionale\Scheduler;

use Wonder\App\Scheduler\AbstractTask;
use Wonder\App\Scheduler\Context;
use Wonder\Plugin\Gestionale\Support\Orders\Expiry;

/**
 * Prenotazioni scadute, promemoria e annullamenti, una volta all'ora.
 *
 * Nasce **spenta**, come le altre: la si accende dalla pagina delle attività
 * quando il negozio comincia a vendere davvero. Finché è spenta la merce di un
 * ordine mai pagato resta impegnata, ed è una scelta che il commerciante deve
 * fare sapendolo.
 */
final class ExpiryTask extends AbstractTask
{
    public function key(): string
    {
        return 'gestionale.order_expiry';
    }

    public function label(): string
    {
        return 'Gestionale: prenotazioni e pagamenti scaduti';
    }

    public function expression(): string
    {
        return '0 * * * *';
    }

    public function enabled(): bool
    {
        return false;
    }

    public function timeout(): int
    {
        return 300;
    }

    public function run(Context $context): array
    {
        $result = Expiry::run();

        return [
            'released' => $result['released'],
            'reminded' => $result['reminded'],
            'cancelled' => $result['cancelled'],
        ];
    }
}
