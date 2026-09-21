<?php

namespace Wonder\Plugin\Gestionale\Scheduler;

use Wonder\App\Scheduler\AbstractTask;
use Wonder\App\Scheduler\Context;
use Wonder\Plugin\Gestionale\Support\Catalog\ImageQueue;

/**
 * La coda delle immagini, come attività dello scheduler del core.
 *
 * È lo stesso lavoro di `php forge gestionale:images`: chi vuole può lanciarlo
 * a mano, chi non vuole pensarci la accende e la dimentica. Nasce **spenta**,
 * perché un sito senza catalogo non ha niente da ridimensionare.
 *
 * Ogni cinque minuti e un blocco per volta: le foto arrivano quando il
 * commerciante carica, non a ondate, e tenere i giri corti vuol dire che il
 * processo non resta mai appeso.
 */
final class ImagesTask extends AbstractTask
{
    public function key(): string
    {
        return 'gestionale.images';
    }

    public function label(): string
    {
        return 'Gestionale: misure delle immagini del catalogo';
    }

    public function expression(): string
    {
        return '*/5 * * * *';
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
        $result = ImageQueue::work(ImageQueue::BATCH);

        return [
            'done' => $result['done'],
            'failed' => $result['failed'],
            'left' => $result['left'],
        ];
    }
}
