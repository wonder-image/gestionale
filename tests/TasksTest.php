<?php
/** php tests/TasksTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\App\Module\Contracts\ModuleTasks;
use Wonder\App\Scheduler\Contracts\TaskInterface;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Scheduler\ImagesTask;

check('il modulo dichiara le sue attività al core', fn () =>
    is_subclass_of(Gestionale::class, ModuleTasks::class)
);

check('la coda delle immagini è una di quelle', function () {
    foreach (Gestionale::tasks() as $task) {
        if ($task instanceof ImagesTask) {
            return $task instanceof TaskInterface;
        }
    }

    return false;
});

check('l\'attività ha una chiave che il core accetta', function () {
    $task = new ImagesTask();

    // Stesso vincolo di `TaskRegistry`: minuscole, numeri, punto e trattino.
    return preg_match('/^[a-z0-9][a-z0-9_.-]{0,119}$/D', $task->key()) === 1
        && $task->key() === 'gestionale.images';
});

check('gira ogni cinque minuti e non tiene occupato il processo', function () {
    $task = new ImagesTask();

    return $task->expression() === '*/5 * * * *'
        && $task->timeout() > 0
        && $task->timeout() <= 3600;
});

check('nasce spenta: un sito senza catalogo non ha niente da fare', fn () =>
    (new ImagesTask())->enabled() === false
);

check('non chiede parametri', fn () =>
    (new ImagesTask())->defaultParameters() === []
    && (new ImagesTask())->validate([]) === []
);

summary();
