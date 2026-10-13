<?php
/** php tests/integrazione/DemoRipristinoTest.php */
declare(strict_types=1);

define('SITE', getenv('WI_TEST_SITE') ?: '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site');

chdir(SITE);
$GLOBALS['ROOT'] = SITE;
require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__ . '/../harness.php';

use Wonder\Plugin\Gestionale\Models\Catalog\Attribute;
use Wonder\Plugin\Gestionale\Models\Catalog\Brand;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
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

/*
 * Articoli e attributi di prova si **rifanno da zero** (decisione del
 * 2026-10-13): la riga cancellata si toglie davvero, con le sue righe figlie,
 * e ne nasce una nuova. Rimetterla in vita riporterebbe anche versioni, foto
 * e valori in uno stato che nessuno ricorda più.
 */
// La riga non c'è più nemmeno fra le cancellate.
$sparita = static function (string $model, int $id): bool {
    $riga = $model::find(['id' => $id, 'deleted' => ['true', 'false']], 1);

    return !is_array($riga) || !isset($riga['id']);
};

check('un attributo di prova cancellato dal backend si rifà da zero, coi suoi valori', function () use ($cancella, $idDelCodice, $sparita) {
    $esito = false;

    try {
        Transaction::run(static function () use (&$esito, $cancella, $idDelCodice, $sparita): void {
            $attributo = new ReflectionMethod(CatalogDemo::class, 'attribute');
            $attributo->invoke(null, 'colore');
            $prima = $idDelCodice(Attribute::class, 'colore');
            $usato = (new ReflectionMethod(CatalogDemo::class, 'attributeInUse'))->invoke(null, $prima);
            $cancella(Attribute::class, $prima);
            $creati = (int) $attributo->invoke(null, 'colore');
            $dopo = $idDelCodice(Attribute::class, 'colore');

            // Se un articolo lo usa, resta cancellato e il comando lo dice.
            $esito = $prima > 0 && ($usato
                ? $dopo === 0 && $creati === 0
                : $dopo > 0 && $dopo !== $prima && $creati >= 1
                    && $sparita(Attribute::class, $prima));

            throw new Annulla();
        });
    } catch (Annulla) {
    }

    return $esito;
});

check('un articolo di prova cancellato dal backend si rifà da zero, con le sue opzioni', function () use ($cancella, $idDelCodice, $sparita) {
    $esito = false;

    try {
        Transaction::run(static function () use (&$esito, $cancella, $idDelCodice, $sparita): void {
            CatalogDemo::create();
            $ref = 'cappello-di-lana';
            $prima = $idDelCodice(ProductModel::class, $ref);
            $cancella(ProductModel::class, $prima);
            $creati = CatalogDemo::create();
            $dopo = $idDelCodice(ProductModel::class, $ref);

            $esito = $prima > 0 && $creati >= 1 && $dopo > 0 && $dopo !== $prima
                && $sparita(ProductModel::class, $prima);

            throw new Annulla();
        });
    } catch (Annulla) {
    }

    return $esito;
});

summary();
