<?php
/** php tests/integrazione/RequestMemoTransactionTest.php */
declare(strict_types=1);

define('SITE', getenv('WI_TEST_SITE') ?: '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site');

chdir(SITE);
$GLOBALS['ROOT'] = SITE;
require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__ . '/../harness.php';

use Wonder\Plugin\Gestionale\Support\RequestMemo;
use Wonder\Sql\Transaction;

final class AnnullaMemo extends RuntimeException {}

check('dentro una transazione non tiene le letture nuove', function () {
    RequestMemo::reset();
    $letture = 0;
    $leggi = function () use (&$letture): int { return ++$letture; };

    Transaction::run(function () use ($leggi): void {
        RequestMemo::remember('g', 'a', $leggi);
        RequestMemo::remember('g', 'a', $leggi);
    });

    return $letture === 2 && RequestMemo::remember('g', 'a', $leggi) === 3
        && RequestMemo::remember('g', 'a', $leggi) === 3;
});

check('dopo un rollback non resta niente della transazione', function () {
    RequestMemo::reset();
    $letture = 0;
    $leggi = function () use (&$letture): int { return ++$letture; };

    try {
        Transaction::run(function () use ($leggi): void {
            RequestMemo::remember('g', 'a', $leggi);
            throw new AnnullaMemo();
        });
    } catch (AnnullaMemo) {
    }

    return RequestMemo::remember('g', 'a', $leggi) === 2;
});

check('gruppo scritto nella transazione: si rilegge fino alla chiusura', function () {
    RequestMemo::reset();
    $letture = 0;
    $leggi = function () use (&$letture): int { return ++$letture; };
    RequestMemo::remember('g', 'a', $leggi);
    RequestMemo::remember('h', 'a', $leggi);

    $dentro = Transaction::run(function () use ($leggi): array {
        RequestMemo::forget('g');

        return [RequestMemo::remember('g', 'a', $leggi), RequestMemo::remember('g', 'a', $leggi), RequestMemo::remember('h', 'a', $leggi)];
    });

    // Il gruppo non toccato resta quello letto prima della transazione.
    return $dentro === [3, 4, 2] && RequestMemo::remember('g', 'a', $leggi) === 5
        && RequestMemo::remember('g', 'a', $leggi) === 5;
});

RequestMemo::reset();
summary();
