<?php
/** php tests/ImageQueueTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Support\Catalog\ImageQueue;

check('si lavora a blocchi, non tutta la coda', fn () =>
    ImageQueue::BATCH === 20
);

check('dopo tre tentativi una riga si arrende', fn () =>
    ImageQueue::nextStatus(1) === 'pending'
    && ImageQueue::nextStatus(2) === 'pending'
    && ImageQueue::nextStatus(3) === 'failed'
    && ImageQueue::nextStatus(4) === 'failed'
);

check('una riga senza id non si lavora', fn () =>
    ImageQueue::process(['file' => '["x.jpg"]']) === false
);

summary();
