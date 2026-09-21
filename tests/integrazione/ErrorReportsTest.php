<?php
/** php tests/integrazione/ErrorReportsTest.php */
declare(strict_types=1);

const SITE = '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site';

chdir(SITE);
$GLOBALS['ROOT'] = SITE;
require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__ . '/../harness.php';

use Wonder\App\Support\Errors\ErrorReporter;
use Wonder\Plugin\Gestionale\Support\Errors\Errors;
use Wonder\Plugin\Gestionale\Support\Errors\ProviderError;
use Wonder\Sql\Transaction;

final class Annulla extends RuntimeException {}

$servizio = 'provider-di-prova-'.bin2hex(random_bytes(3));
$errore = static fn (): ProviderError => ProviderError::make(
    $servizio,
    'invoice.send',
    'Timeout del provider',
    ['invoice' => 12]
);

$riga = static function () use ($servizio): ?array {
    foreach (ErrorReporter::open() as $row) {
        if (($row['service'] ?? '') === $servizio) {
            return $row;
        }
    }

    return null;
};

try {
    Transaction::run(static function () use ($errore, $riga, $servizio): void {
        Errors::provider($errore());

        check('il primo errore apre una riga', function () use ($riga) {
            $row = $riga();

            return $row !== null
                && (int) $row['occurrences'] === 1
                && str_contains((string) $row['context'], 'invoice');
        });

        Errors::provider($errore());
        Errors::provider($errore());

        check('gli errori uguali restano una riga sola', function () use ($riga) {
            $row = $riga();

            return $row !== null && (int) $row['occurrences'] === 3;
        });

        $id = (int) ($riga()['id'] ?? 0);

        check('segnare risolto toglie l\'errore dagli aperti', function () use ($id, $riga) {
            ErrorReporter::resolve($id, 7);

            return $riga() === null;
        });

        check('lo stesso errore dopo la chiusura riapre la riga da capo', function () use ($errore, $riga) {
            Errors::provider($errore());
            $row = $riga();

            return $row !== null && (int) $row['occurrences'] === 1;
        });

        check('un\'azione diversa è un errore diverso', function () use ($servizio) {
            Errors::provider(ProviderError::make($servizio, 'invoice.cancel', 'Timeout del provider'));

            $aperti = array_values(array_filter(
                ErrorReporter::open(),
                static fn (array $row): bool => ($row['service'] ?? '') === $servizio
            ));

            return count($aperti) === 2;
        });

        check('gli aperti si leggono tutti insieme', function () use ($servizio) {
            Errors::provider(ProviderError::make($servizio, 'shipment.track', 'Tracking assente'));

            $aperti = array_values(array_filter(
                ErrorReporter::open(),
                static fn (array $row): bool => ($row['service'] ?? '') === $servizio
            ));

            return count($aperti) === 3;
        });

        throw new Annulla();
    });
} catch (Annulla) {
}

check('dopo l\'annullamento non resta nessun errore di prova', fn () => $riga() === null);

summary();
