<?php
/** php tests/RequestMemoTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Support\RequestMemo;

check('remember: legge una volta per chiave', function () {
    RequestMemo::reset();
    $letture = 0;
    $leggi = function () use (&$letture): int { return ++$letture; };

    return RequestMemo::remember('g', 'a', $leggi) === 1
        && RequestMemo::remember('g', 'a', $leggi) === 1
        && RequestMemo::remember('g', 'b', $leggi) === 2
        && $letture === 2;
});

check('remember: tiene anche null e array vuoti', function () {
    RequestMemo::reset();
    $letture = 0;
    $leggi = function () use (&$letture): ?array { $letture++; return null; };
    RequestMemo::remember('g', 'a', $leggi);
    RequestMemo::remember('g', 'a', $leggi);

    return $letture === 1;
});

check('forget: rilegge solo il gruppo scritto', function () {
    RequestMemo::reset();
    $letture = 0;
    $leggi = function () use (&$letture): int { return ++$letture; };
    RequestMemo::remember('g', 'a', $leggi);
    RequestMemo::remember('h', 'a', $leggi);
    RequestMemo::forget('g');

    return RequestMemo::remember('g', 'a', $leggi) === 3
        && RequestMemo::remember('h', 'a', $leggi) === 2;
});

check('reset: rilegge tutto', function () {
    RequestMemo::reset();
    $letture = 0;
    $leggi = function () use (&$letture): int { return ++$letture; };
    RequestMemo::remember('g', 'a', $leggi);
    RequestMemo::reset();

    return RequestMemo::remember('g', 'a', $leggi) === 2;
});

summary();
