<?php
/** php tests/integrazione/ProductModelCustomizationsTest.php */

const SITE = '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site';

chdir(SITE);
$GLOBALS['ROOT'] = SITE;

require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__.'/../harness.php';
require __DIR__.'/supporto/compra.php';

use Wonder\Plugin\Gestionale\Models\Catalog\Customization;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModelCustomization;
use Wonder\Plugin\Gestionale\Resources\Catalog\ProductModelResource;
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

/** I collegamenti di un articolo, anche nel cestino, nell'ordine. @return list<array<string, mixed>> */
function collegamenti(int $modello): array
{
    $righe = ProductModelCustomization::find(['product_model_id' => $modello, 'deleted' => ['true', 'false']], null, 'position', 'ASC');

    if (!is_array($righe) || $righe === []) {
        return [];
    }

    return isset($righe['id']) ? [$righe] : array_values(array_filter($righe, 'is_array'));
}

/** Salva l'articolo come il backend: lo stesso sync del core, righe del riquadro comprese. */
function salvaArticolo(int $modello, array $righe): void
{
    ProductModelResource::syncRepeaterRelations($modello, ['customizations' => $righe], [], 'update', 'backend');
}

check('salvare con due righe scrive due collegamenti, nell\'ordine e con l\'obbligo', function () {
    return prova(static function (): bool {
        accendiFunzionalita(['orders', 'customizations']);
        $modello = modelloDi(articoloConGiacenza(1, 'PC-A-'.uniqid()));
        $a = personalizzazioneDiProva();
        $b = personalizzazioneDiProva();
        salvaArticolo($modello, [
            ['customization_id' => (string) $b, 'is_required' => 'true'],
            ['customization_id' => (string) $a, 'is_required' => 'false'],
        ]);
        $righe = collegamenti($modello);

        return array_map('intval', array_column($righe, 'customization_id')) === [$b, $a]
            && array_column($righe, 'is_required') === ['true', 'false'];
    });
});

check('salvare con una riga sola cancella davvero l\'altra', function () {
    return prova(static function (): bool {
        accendiFunzionalita(['orders', 'customizations']);
        $modello = modelloDi(articoloConGiacenza(1, 'PC-B-'.uniqid()));
        $a = personalizzazioneDiProva();
        $b = personalizzazioneDiProva();
        salvaArticolo($modello, [['customization_id' => (string) $a], ['customization_id' => (string) $b]]);
        $prima = collegamenti($modello);
        salvaArticolo($modello, [['id' => (string) $prima[1]['id'], 'customization_id' => (string) $b]]);
        $dopo = collegamenti($modello);

        return count($dopo) === 1
            && (int) $dopo[0]['customization_id'] === $b
            && $dopo[0]['deleted'] === 'false';
    });
});

check('salvare senza righe li toglie tutti', function () {
    return prova(static function (): bool {
        accendiFunzionalita(['orders', 'customizations']);
        $modello = modelloDi(articoloConGiacenza(1, 'PC-C-'.uniqid()));
        collegaPersonalizzazione($modello, personalizzazioneDiProva(), false, 1);
        collegaPersonalizzazione($modello, personalizzazioneDiProva(), true, 2);
        salvaArticolo($modello, []);

        return collegamenti($modello) === [];
    });
});

check('la stessa personalizzazione due volte e le righe vuote fanno un collegamento solo', function () {
    return prova(static function (): bool {
        accendiFunzionalita(['orders', 'customizations']);
        $modello = modelloDi(articoloConGiacenza(1, 'PC-D-'.uniqid()));
        $a = personalizzazioneDiProva();
        salvaArticolo($modello, [
            ['customization_id' => (string) $a, 'is_required' => 'true'],
            ['customization_id' => '', 'is_required' => 'false'],
            ['customization_id' => (string) $a, 'is_required' => 'false'],
        ]);
        $righe = collegamenti($modello);

        return count($righe) === 1 && $righe[0]['is_required'] === 'true';
    });
});

check('con la funzionalità spenta salvare l\'articolo non tocca i collegamenti', function () {
    return prova(static function (): bool {
        accendiFunzionalita(['orders', 'customizations']);
        $modello = modelloDi(articoloConGiacenza(1, 'PC-E-'.uniqid()));
        collegaPersonalizzazione($modello, personalizzazioneDiProva(), true, 1);
        spegniFunzionalita(['customizations']);
        salvaArticolo($modello, []);

        return count(collegamenti($modello)) === 1;
    });
});

// `Model::create` scrive i nomi con l'iniziale maiuscola: i nomi di prova sono già così.
check('la disattivata già collegata si vede con «(disattivata)», per un altro articolo no', function () {
    return prova(static function (): bool {
        accendiFunzionalita(['orders', 'customizations']);
        $modello = modelloDi(articoloConGiacenza(1, 'PC-F-'.uniqid()));
        $altro = modelloDi(articoloConGiacenza(1, 'PC-G-'.uniqid()));
        $attiva = personalizzazioneDiProva(['name' => 'Zeta Attiva']);
        $spenta = personalizzazioneDiProva(['name' => 'Alfa Spenta', 'active' => 'false']);
        collegaPersonalizzazione($modello, $spenta, false, 1);
        $suoi = ProductModelResource::customizationOptions($modello);
        $loro = ProductModelResource::customizationOptions($altro);

        return ($suoi[(string) $spenta] ?? '') === 'Alfa Spenta (disattivata)'
            && ($suoi[(string) $attiva] ?? '') === 'Zeta Attiva'
            && !isset($loro[(string) $spenta])
            && ($loro[(string) $attiva] ?? '') === 'Zeta Attiva'
            && ($suoi[''] ?? '') === '—';
    });
});

check('eliminare l\'articolo toglie i collegamenti, senza errori di chiave', function () {
    return prova(static function (): bool {
        accendiFunzionalita(['orders', 'customizations']);
        $modello = modelloDi(articoloConGiacenza(0, 'PC-H-'.uniqid()));
        $a = personalizzazioneDiProva();
        collegaPersonalizzazione($modello, $a, false, 1);
        $esito = ProductModelResource::deleteRecord($modello);

        return !empty($esito->success)
            && collegamenti($modello) === []
            && is_array(Customization::find(['id' => $a], 1));
    });
});

summary();
