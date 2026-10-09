<?php
/** php tests/integrazione/ProviderEventsTest.php */
declare(strict_types=1);

define('SITE', getenv('WI_TEST_SITE') ?: '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site');

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

        check('un evento ricevuto e non ancora elaborato si riprende, senza una seconda riga', function () use ($provider, $evento) {
            $prima = ProviderEvents::find($provider, $evento);

            return ProviderEvents::receive($provider, $evento, 'payment.succeeded', ['amount' => 1000]) === true
                && (int) (ProviderEvents::find($provider, $evento)['id'] ?? 0) === (int) ($prima['id'] ?? -1);
        });

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

        check('un evento elaborato non si riprende', fn () =>
            ProviderEvents::receive($provider, $evento, 'payment.succeeded', []) === false
        );

        check('un evento fallito si riprende', function () use ($provider) {
            $fallito = 'evt_fallito_'.bin2hex(random_bytes(3));
            ProviderEvents::receive($provider, $fallito, 'payment.succeeded', []);
            ProviderEvents::markFailed($provider, $fallito, 'rete giù');

            return ProviderEvents::receive($provider, $fallito, 'payment.succeeded', []) === true
                && (int) (ProviderEvents::find($provider, $fallito)['attempts'] ?? 0) === 1;
        });

        check('un fallimento definitivo porta i tentativi al massimo', function () use ($provider) {
            $definitivo = 'evt_definitivo_'.bin2hex(random_bytes(3));
            ProviderEvents::receive($provider, $definitivo, 'payment.succeeded', []);
            ProviderEvents::markFailed($provider, $definitivo, 'importo diverso', 'live', true);
            $riga = ProviderEvents::find($provider, $definitivo);

            return ($riga['status'] ?? '') === 'failed'
                && (int) ($riga['attempts'] ?? 0) === ProviderEvents::MAX_ATTEMPTS;
        });

        check('un evento ritentato aggiorna tipo e contenuto sulla stessa riga', function () use ($provider) {
            $ritentato = 'evt_ritentato_'.bin2hex(random_bytes(3));
            ProviderEvents::receive($provider, $ritentato, 'payment.processing', ['amount' => 1000]);
            ProviderEvents::markFailed($provider, $ritentato, 'rete giù');
            ProviderEvents::receive($provider, $ritentato, 'payment.succeeded', ['amount' => 2500]);
            $riga = ProviderEvents::find($provider, $ritentato);

            return ($riga['type'] ?? '') === 'payment.succeeded'
                && str_contains((string) ($riga['payload'] ?? ''), '2500');
        });

        check('il client_secret non si salva nel contenuto dell\'evento', function () use ($provider) {
            $segreto = 'evt_segreto_'.bin2hex(random_bytes(3));
            ProviderEvents::receive($provider, $segreto, 'payment.succeeded', [
                'data' => ['object' => ['id' => 'pi_x', 'client_secret' => 'pi_x_secret_prova', 'charges' => [['client_secret' => 'annidato_prova']]]],
            ]);
            $payload = (string) (ProviderEvents::find($provider, $segreto)['payload'] ?? '');

            return str_contains($payload, 'pi_x')
                && !str_contains($payload, 'pi_x_secret_prova')
                && !str_contains($payload, 'annidato_prova');
        });

        throw new Annulla();
    });
} catch (Annulla) {
}

check('dopo l\'annullamento non resta niente', fn () =>
    ProviderEvents::find($provider, $evento) === null
);

summary();
