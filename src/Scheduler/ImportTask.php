<?php

namespace Wonder\Plugin\Gestionale\Scheduler;

use Wonder\App\Scheduler\AbstractTask;
use Wonder\App\Scheduler\Context;
use Wonder\Plugin\Gestionale\Support\Import\Importer;
use Wonder\Plugin\Gestionale\Support\Import\Store;

/**
 * Porta avanti i caricamenti da file avviati: chi chiude la pagina non lo
 * ferma. Ogni minuto, per una ventina di secondi al massimo; la pagina aperta
 * chiama lo stesso `Importer::step()` e il lock evita che si pestino i piedi.
 */
final class ImportTask extends AbstractTask
{
    private const SECONDS = 20;

    public function key(): string
    {
        return 'gestionale.import';
    }

    public function label(): string
    {
        return 'Gestionale: caricamenti da file';
    }

    public function expression(): string
    {
        return '* * * * *';
    }

    public function enabled(): bool
    {
        return true;
    }

    public function timeout(): int
    {
        return 120;
    }

    public function run(Context $context): array
    {
        $until = microtime(true) + self::SECONDS;
        $rows = 0;
        $jobs = 0;

        foreach (Store::runningJobs() as $jobId) {
            $jobs++;

            do {
                $before = Importer::progress($jobId)['done'];
                $progress = Importer::step($jobId);
                $rows += $progress['done'] - $before;
            } while ($progress['status'] === 'running' && $progress['done'] > $before && microtime(true) < $until);

            if (microtime(true) >= $until) {
                break;
            }
        }

        return ['jobs' => $jobs, 'rows' => $rows];
    }
}
