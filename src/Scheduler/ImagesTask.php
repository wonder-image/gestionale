<?php

namespace Wonder\Plugin\Gestionale\Scheduler;

use Wonder\App\Scheduler\AbstractTask;
use Wonder\App\Scheduler\Context;
use Wonder\App\Media\ResizeQueue;

/**
 * Deprecata: le misure delle immagini le genera il task del core
 * `wonder.media-resize` («Ridimensionamento immagini»), sempre attivo, ogni
 * minuto.
 *
 * L'attività resta perché i siti che l'avevano accesa hanno la sua
 * pianificazione salvata: toglierla lascerebbe quella riga senza attività.
 * Chi la tiene accesa fa fare un giro in più alla stessa coda, sotto lo
 * stesso lock: se il task del core sta già lavorando, non fa niente. Nasce
 * spenta e si può sospendere senza perdere nulla.
 *
 * @deprecated usa il task del core `wonder.media-resize`.
 */
final class ImagesTask extends AbstractTask
{
    public function key(): string
    {
        return 'gestionale.images';
    }

    public function label(): string
    {
        return 'Gestionale: misure delle immagini (deprecata, le genera «Ridimensionamento immagini»)';
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
        return ['deprecated' => 'wonder.media-resize'] + ResizeQueue::drain($context);
    }
}
