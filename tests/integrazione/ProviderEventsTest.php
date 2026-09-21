<?php
/** php tests/integrazione/ProviderEventsTest.php */
declare(strict_types=1);

const SITE = '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site';

chdir(SITE);
$GLOBALS['ROOT'] = SITE;
require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__ . '/../harness.php';

use Wonder\Plugin\Gestionale\Support\Providers\ProviderEvents;
use Wonder\Sql\Transaction;

final class Annulla extends RuntimeException {}

$provider = 'stripe';
$evento = 'evt_'.bin2hex(random_bytes(4));

try {
    Transaction::run(static function () use ($provider, $evento): void {
        check('il primo arrivo registra l\'evento', fn () =>
            ProviderEvents::receive($provider, $evento, 'payment.succeeded', ['amount' => 1000]) === true
        );

        check('l\'evento nasce ricevuto, con il suo contenuto', function () use ($provider, $evento) {
            $riga = ProviderEvents::find($provider, $evento);

            return ($riga['status'] ?? '') === 'received'
                && ($riga['type'] ?? '') === 'payment.succeeded'
                && str_contains((string) ($riga['payload'] ?? ''), 'amount');
        });

        check('lo stesso evento non si registra due volte', fn () =>
            ProviderEvents::receive($provider, $evento, 'payment.succeeded', ['amount' => 1000]) === false
        );

        check('lo stesso id in un altro ambiente è un altro evento', fn () =>
            ProviderEvents::receive($provider, $evento, 'payment.succeeded', [], 'test') === true
        );

        check('un tentativo fallito si conta', function () use ($provider, $evento) {
            ProviderEvents::markFailed($provider, $evento, 'ordine inesistente');
            ProviderEvents::markFailed($provider, $evento, 'ordine inesistente');
            $riga = ProviderEvents::find($provider, $evento);

            return ($riga['status'] ?? '') === 'failed'
                && (int) ($riga['attempts'] ?? 0) === 2
                && ($riga['error'] ?? '') === 'ordine inesistente';
        });

        check('quando va bene l\'evento si chiude e l\'errore sparisce', function () use ($provider, $evento) {
            ProviderEvents::markProcessed($provider, $evento);
            $riga = ProviderEvents::find($provider, $evento);

            return ($riga['status'] ?? '') === 'processed'
                && trim((string) ($riga['processed_at'] ?? '')) !== ''
                && trim((string) ($riga['error'] ?? '')) === '';
        });

        check('un evento mai arrivato non si chiude', fn () =>
            ProviderEvents::markProcessed($provider, 'evt_mai_visto') === false
        );

        throw new Annulla();
    });
} catch (Annulla) {
}

check('dopo l\'annullamento non resta niente', fn () =>
    ProviderEvents::find($provider, $evento) === null
);

summary();
