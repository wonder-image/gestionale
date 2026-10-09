<?php
/** php tests/integrazione/DemoRipristinoTest.php */
declare(strict_types=1);

define('SITE', getenv('WI_TEST_SITE') ?: '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site');

chdir(SITE);
$GLOBALS['ROOT'] = SITE;
require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__ . '/../harness.php';

use Wonder\Plugin\Gestionale\Models\Catalog\Brand;
use Wonder\Plugin\Gestionale\Models\Contacts\Contact;
use Wonder\Plugin\Gestionale\Seeding\CatalogDemo;
use Wonder\Plugin\Gestionale\Seeding\ContactsDemo;
use Wonder\Plugin\Gestionale\Seeding\DemoCode;
use Wonder\Sql\Transaction;

/**
 * I dati di prova dopo che qualcuno ne ha cancellato uno dal gestionale.
 *
 * Il codice col segno è unico in tabella, e la cancellazione del backend è
 * morbida: la riga resta lì con `deleted` a `true`. Chi rimette i dati di
 * prova guarda solo fra le righe vive, non la trova, e prova a rifarla:
 * l'INSERT sbatte contro la chiave del codice e `forge demo` muore con un
 * errore di database invece di rimettere a posto quello che manca.
 */

final class Annulla extends RuntimeException {}

// La cancellazione del backend, uguale a quella di `App\Resource`: la riga
// resta in tabella e sparisce solo dalle ricerche.
$cancella = static function (string $model, int $id): void {
    $model::query()->Update($model::$table, ['deleted' => 'true'], 'id', $id);
};

$idDelCodice = static function (string $model, string $ref): int {
    $riga = $model::find(['code' => DemoCode::forModel($model, $ref), 'deleted' => 'false'], 1);

    return is_array($riga) ? (int) ($riga['id'] ?? 0) : 0;
};

check('un contatto di prova cancellato torna al suo posto invece di far morire i dati di prova', function () use ($cancella, $idDelCodice) {
    $esito = false;

    try {
        Transaction::run(static function () use (&$esito, $cancella, $idDelCodice): void {
            ContactsDemo::create();
            $prima = $idDelCodice(Contact::class, 'filati-nord');
            $cancella(Contact::class, $prima);

            $creati = ContactsDemo::create();

            $esito = $prima > 0
                && $creati >= 1
                && $idDelCodice(Contact::class, 'filati-nord') === $prima;

            throw new Annulla();
        });
    } catch (Annulla) {
    }

    return $esito;
});

check('una riga di catalogo di prova cancellata torna al suo posto invece di essere rifatta', function () use ($cancella, $idDelCodice) {
    $esito = false;
    $marca = static function (): int {
        $ensure = new ReflectionMethod(CatalogDemo::class, 'ensure');

        return (int) $ensure->invoke(
            null,
            Brand::class,
            'maglificio-aurora',
            'Maglificio Aurora',
            ['position' => 1, 'visible' => 'true']
        );
    };

    try {
        Transaction::run(static function () use (&$esito, $cancella, $idDelCodice, $marca): void {
            $marca();
            $prima = $idDelCodice(Brand::class, 'maglificio-aurora');
            $cancella(Brand::class, $prima);

            $creata = $marca();

            $esito = $prima > 0
                && $creata === 1
                && $idDelCodice(Brand::class, 'maglificio-aurora') === $prima;

            throw new Annulla();
        });
    } catch (Annulla) {
    }

    return $esito;
});

summary();
