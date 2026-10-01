<?php
/** php tests/integrazione/CustomizationsTest.php */

const SITE = '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site';

chdir(SITE);
$GLOBALS['ROOT'] = SITE;

require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__.'/../harness.php';
require __DIR__.'/supporto/compra.php';

use Wonder\Plugin\Gestionale\Models\Catalog\Customization;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModelCustomization;
use Wonder\Plugin\Gestionale\Support\Catalog\Customizations;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Sql\Transaction;

/** Serve a buttare via tutto quello che la prova scrive. */
final class Annulla extends RuntimeException {}

/** Fa girare il corpo dentro una transazione e poi la annulla. */
function prova(callable $corpo): mixed
{
    $esito = null;

    try {
        Transaction::run(static function () use ($corpo, &$esito): void {
            $esito = $corpo();

            throw new Annulla();
        });
    } catch (Annulla) {
        // Voluto: i dati della prova non restano.
    }

    return $esito;
}

check('forModel ridà le personalizzazioni nell\'ordine del collegamento, con obbligo e opzioni', function () {
    return prova(static function (): bool {
        $modello = modelloDi(articoloConGiacenza(1, 'PZ-A-'.uniqid()));
        $testo = personalizzazioneDiProva(['name' => 'Incisione', 'surcharge' => '5.00']);
        $scelta = personalizzazioneDiProva(['name' => 'Colore'], [
            ['label' => 'Rosso', 'surcharge' => 0],
            ['label' => 'Oro', 'surcharge' => 2],
        ]);
        collegaPersonalizzazione($modello, $testo, false, 2);
        collegaPersonalizzazione($modello, $scelta, true, 1);

        $lista = Customizations::forModel($modello);

        return count($lista) === 2
            && $lista[0]['id'] === $scelta
            && $lista[0]['required'] === true
            && $lista[0]['kind'] === 'choice'
            && array_column($lista[0]['options'], 'label') === ['Rosso', 'Oro']
            && $lista[0]['options'][1]['surcharge'] === '2.00'
            && $lista[1]['id'] === $testo
            && $lista[1]['required'] === false
            && $lista[1]['surcharge'] === '5.00'
            && $lista[1]['max_length'] === 20
            && $lista[1]['options'] === [];
    });
});

check('forModel non ridà una personalizzazione disattivata', function () {
    return prova(static function (): bool {
        $modello = modelloDi(articoloConGiacenza(1, 'PZ-B-'.uniqid()));
        $spenta = personalizzazioneDiProva(['active' => 'false']);
        collegaPersonalizzazione($modello, $spenta);

        return Customizations::forModel($modello) === [];
    });
});

check('forModel non ridà un collegamento cancellato', function () {
    return prova(static function (): bool {
        $modello = modelloDi(articoloConGiacenza(1, 'PZ-C-'.uniqid()));
        $collegamento = collegaPersonalizzazione($modello, personalizzazioneDiProva());
        ProductModelCustomization::query()->Update(ProductModelCustomization::$table, ['deleted' => 'true'], 'id', $collegamento);

        return Customizations::forModel($modello) === [];
    });
});

check('un\'etichetta con & e accenti torna leggibile', function () {
    return prova(static function (): bool {
        $modello = modelloDi(articoloConGiacenza(1, 'PZ-D-'.uniqid()));
        // Il core passa i testi dal sanitize: qui si scrive come lo scrive lui.
        $id = personalizzazioneDiProva(['name' => 'Nome &amp; Cognome', 'label' => 'Più &amp; più'], [
            ['label' => 'Caffè &amp; Latte'],
            ['label' => 'Tè'],
        ]);
        collegaPersonalizzazione($modello, $id);

        $voce = Customizations::forModel($modello)[0] ?? [];

        return ($voce['name'] ?? '') === 'Nome & Cognome'
            && ($voce['label'] ?? '') === 'Più & più'
            && ($voce['options'][0]['label'] ?? '') === 'Caffè & Latte';
    });
});

check('resolve con una personalizzazione disattivata fra i valori dà unknown', function () {
    return prova(static function (): bool {
        $modello = modelloDi(articoloConGiacenza(1, 'PZ-E-'.uniqid()));
        $spenta = personalizzazioneDiProva(['active' => 'false']);
        collegaPersonalizzazione($modello, $spenta);

        try {
            Customizations::resolve($modello, [$spenta => 'Marco']);
        } catch (UserError $e) {
            return $e->key() === 'customization.unknown' && $e->field() === $spenta;
        }

        return false;
    });
});

check('resolve controlla e prezza sulle personalizzazioni vere dell\'articolo', function () {
    return prova(static function (): bool {
        $modello = modelloDi(articoloConGiacenza(1, 'PZ-F-'.uniqid()));
        $testo = personalizzazioneDiProva(['name' => 'Incisione', 'surcharge' => '5.00']);
        collegaPersonalizzazione($modello, $testo);

        $esito = Customizations::resolve($modello, [$testo => '  Marco ']);

        return count($esito['fields']) === 1
            && $esito['fields'][0]['value'] === 'Marco'
            && $esito['surcharge'] === '5.00';
    });
});

check('un articolo senza collegamenti non ha personalizzazioni', function () {
    return prova(static function (): bool {
        $modello = modelloDi(articoloConGiacenza(1, 'PZ-G-'.uniqid()));

        return Customizations::forModel($modello) === []
            && Customizations::forModel(0) === []
            && Customizations::resolve($modello, [])['fields'] === [];
    });
});

summary();
