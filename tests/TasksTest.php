<?php
/** php tests/TasksTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\App\Media\MemoryResizeStore;
use Wonder\App\Media\ResizeQueue;
use Wonder\App\Module\Contracts\ModuleTasks;
use Wonder\App\Scheduler\Context;
use Wonder\App\Scheduler\Contracts\TaskInterface;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Scheduler\ImagesTask;
use Wonder\Plugin\Gestionale\Scheduler\StockAlertsTask;
use Wonder\Plugin\Gestionale\Scheduler\StripeReconcileTask;

check('il modulo dichiara le sue attività al core', fn () =>
    is_subclass_of(Gestionale::class, ModuleTasks::class)
);

check('la vecchia attività delle immagini resta dichiarata: i siti hanno la sua pianificazione salvata', function () {
    foreach (Gestionale::tasks() as $task) {
        if ($task instanceof ImagesTask) {
            return $task instanceof TaskInterface;
        }
    }

    return false;
});

check('tiene la sua chiave, la sua frequenza e nasce spenta', function () {
    $task = new ImagesTask();

    // Stesso vincolo di `TaskRegistry`: minuscole, numeri, punto e trattino.
    return preg_match('/^[a-z0-9][a-z0-9_.-]{0,119}$/D', $task->key()) === 1
        && $task->key() === 'gestionale.images'
        && $task->expression() === '*/5 * * * *'
        && $task->timeout() > 0 && $task->timeout() <= 3600
        && $task->enabled() === false
        && $task->defaultParameters() === []
        && $task->validate([]) === [];
});

check('è deprecata e lo dice a chi la legge nel pannello', fn () =>
    str_contains((new ImagesTask())->label(), 'deprecata')
    && str_contains((new ImagesTask())->label(), 'Ridimensionamento immagini')
);

check('fa un giro della coda del core e dice chi la sostituisce', function () {
    $store = new MemoryResizeStore(gmdate('Y-m-d H:i:s'));
    $store->usable = false;
    ResizeQueue::useStore($store);

    try {
        // Senza tabella la coda del core salta il giro: niente lock, niente database.
        $esito = (new ImagesTask())->run(new Context([], microtime(true) + 60));
    } finally {
        ResizeQueue::useStore(null);
    }

    return $esito === ['deprecated' => 'wonder.media-resize', 'skipped' => 'image_resizes_missing'];
});

check('anche gli avvisi di scorta minima sono un\'attività del modulo', function () {
    foreach (Gestionale::tasks() as $task) {
        if ($task instanceof StockAlertsTask) {
            return $task instanceof TaskInterface;
        }
    }

    return false;
});

check('gli avvisi hanno una chiave che il core accetta', function () {
    $task = new StockAlertsTask();

    return preg_match('/^[a-z0-9][a-z0-9_.-]{0,119}$/D', $task->key()) === 1
        && $task->key() === 'gestionale.stock_alerts';
});

check('gli avvisi girano ogni quarto d\'ora e non tengono occupato il processo', function () {
    $task = new StockAlertsTask();

    return $task->expression() === '*/15 * * * *'
        && $task->timeout() > 0
        && $task->timeout() <= 3600;
});

check('gli avvisi nascono spenti: prima si scelgono i destinatari', fn () =>
    (new StockAlertsTask())->enabled() === false
    && (new StockAlertsTask())->defaultParameters() === []
    && (new StockAlertsTask())->validate([]) === []
);

check('le attività hanno chiavi diverse', function () {
    $chiavi = [];

    foreach (Gestionale::tasks() as $task) {
        $chiavi[] = $task->key();
    }

    return $chiavi === array_values(array_unique($chiavi)) && count($chiavi) === 8;
});

check('la fine del vincolo degli abbonamenti è un\'attività giornaliera e spenta', function () {
    $task = new Wonder\Plugin\Gestionale\Scheduler\SubscriptionCommitmentTask();

    return $task->key() === 'gestionale.subscription_commitments' && $task->expression() === '15 6 * * *' && $task->enabled() === false;
});

check('gli abbonamenti da ufficio hanno un\'attività giornaliera e spenta', function () {
    $task = new Wonder\Plugin\Gestionale\Scheduler\OfficeSubscriptionsTask();

    return $task->key() === 'gestionale.office_subscriptions' && $task->expression() === '20 6 * * *' && $task->enabled() === false;
});

check('i preventivi scaduti: un\'attività del modulo, giornaliera e spenta (G9)', function () {
    foreach (Gestionale::tasks() as $task) {
        if ($task->key() === 'gestionale.quote_expiry') {
            return $task->expression() === '10 5 * * *' && $task->enabled() === false;
        }
    }

    return false;
});

check('il riallineamento con Stripe è un\'attività del modulo, oraria e spenta', function () {
    $trovata = null;

    foreach (Gestionale::tasks() as $task) {
        if ($task instanceof StripeReconcileTask) {
            $trovata = $task;
        }
    }

    return $trovata instanceof TaskInterface
        && $trovata->key() === 'gestionale.stripe_reconcile'
        && $trovata->expression() === '0 * * * *'
        && $trovata->enabled() === false
        && $trovata->timeout() > 0 && $trovata->timeout() <= 3600;
});

summary();
