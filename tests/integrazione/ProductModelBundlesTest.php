<?php
/** php tests/integrazione/ProductModelBundlesTest.php */

const SITE = '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site';

chdir(SITE);
$GLOBALS['ROOT'] = SITE;

require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__.'/../harness.php';
require __DIR__.'/supporto/compra.php';

use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Catalog\BundleComponent;
use Wonder\Plugin\Gestionale\Models\Catalog\BundleGroup;
use Wonder\Plugin\Gestionale\Models\Catalog\BundleGroupOption;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Sales\OrderItem;
use Wonder\Plugin\Gestionale\Resources\Catalog\ProductModelResource;
use Wonder\Plugin\Gestionale\Resources\Catalog\ProductResource;
use Wonder\Plugin\Gestionale\Resources\Stock\StockLevelResource;
use Wonder\Plugin\Gestionale\Support\Catalog\Bundles;
use Wonder\Plugin\Gestionale\Support\Catalog\Code;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Stock\Levels;
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
    } finally {
        $_POST = [];
        Gestionale::reset();
    }

    return $esito;
}

/**
 * Una richiesta di scheda: quello che manda il form di un multiprodotto.
 *
 * @param list<array<string, mixed>> $componenti [['product_id', 'quantity']]
 * @param list<array<string, mixed>> $gruppi [['name', 'min', 'max', 'options' => [['product_id', 'surcharge']]]]
 * @param array<string, mixed> $extra
 * @return array<string, mixed>
 */
function richiesta(string $modo, array $componenti, array $gruppi, array $extra = []): array
{
    $righeGruppi = [];

    foreach ($gruppi as $gruppo) {
        $righeGruppi[] = array_merge($gruppo, [
            'options' => is_string($gruppo['options'] ?? null)
                ? $gruppo['options']
                : json_encode(array_values($gruppo['options'] ?? []), JSON_UNESCAPED_UNICODE),
        ]);
    }

    return $extra + [
        'name' => 'Cesto '.uniqid(),
        'sku' => 'CES-'.strtoupper(substr(uniqid(), -6)),
        'unit' => 'pz',
        'visible' => 'true',
        'visible_online' => 'true',
        'has_variants' => 'false',
        'type' => 'bundle',
        'bundle_mode' => $modo,
        'show_components_value' => 'false',
        'bundle_components' => $componenti,
        'bundle_groups' => $righeGruppi,
    ];
}

/** Crea l'articolo come il backend: controlli, insert, poi `afterStore`. Torna l'id del modello. */
function creaScheda(array $post): int
{
    $_POST = $post;
    $valori = ProductModelResource::mutateRequestValues($post, 'store');
    $modello = ProductModel::create($valori + [
        'code' => Code::make(ProductModel::class, Codes::MODEL),
    ]);
    $id = (int) ($modello->insert_id ?? 0);

    ProductModelResource::afterStore($modello, $valori);

    return $id;
}

/** Salva di nuovo un articolo che esiste. */
function aggiornaScheda(int $id, array $post): array
{
    $_POST = $post;
    $vecchi = ProductModel::findById($id);
    $valori = ProductModelResource::mutateRequestValues($post, 'update', 'backend', $vecchi);

    if (array_key_exists('type', $valori)) {
        ProductModel::update($valori, $id);
    } else {
        ProductModel::update($valori, $id);
    }

    ProductModelResource::afterUpdate($id, (object) [], $valori);

    return $valori;
}

/** La chiave dell'errore che il salvataggio dà, o `''` se passa. */
function rifiuto(callable $corpo): string
{
    try {
        $corpo();
    } catch (UserError $errore) {
        return $errore->key();
    }

    return '';
}

/** @return list<array<string, mixed>> */
function righe(string $modello, array $condizione): array
{
    $trovate = $modello::find($condizione + ['deleted' => ['true', 'false']], null, 'position', 'ASC');

    if (!is_array($trovate) || $trovate === []) {
        return [];
    }

    return isset($trovate['id']) ? [$trovate] : array_values(array_filter($trovate, 'is_array'));
}

/** Quanti articoli ci sono con questo nome: i rifiuti non ne lasciano. */
function articoliChiamati(string $nome): int
{
    return count(righe(ProductModel::class, ['name' => $nome]));
}

/** Un componente semplice da usare nelle prove. */
function pezzo(string $sigla): int
{
    return articoloConGiacenza(5, $sigla.'-'.strtoupper(substr(uniqid(), -5)));
}

check('un multiprodotto fisso nasce con un modello «bundle», un prodotto e i componenti in ordine', function () {
    return prova(static function (): bool {
        accendiFunzionalita(['orders', 'bundles']);
        $a = pezzo('FA');
        $b = pezzo('FB');
        $id = creaScheda(richiesta('fixed', [
            ['product_id' => (string) $b, 'quantity' => '2'],
            ['product_id' => (string) $a, 'quantity' => '1'],
        ], []));
        $modello = ProductModel::findById($id);
        $prodotti = righe(Product::class, ['product_model_id' => $id]);
        $componenti = righe(BundleComponent::class, ['product_model_id' => $id]);

        return $modello['type'] === 'bundle'
            && $modello['bundle_mode'] === 'fixed'
            && count($prodotti) === 1
            && abs((float) (Levels::of((int) $prodotti[0]['id'])['quantity'] ?? 0)) < 0.001
            && array_map('intval', array_column($componenti, 'product_id')) === [$b, $a]
            && array_map('floatval', array_column($componenti, 'quantity')) === [2.0, 1.0]
            && righe(BundleGroup::class, ['product_model_id' => $id]) === [];
    });
});

check('un multiprodotto a scelta scrive gruppi e opzioni, e nessun componente', function () {
    return prova(static function (): bool {
        accendiFunzionalita(['orders', 'bundles']);
        $a = pezzo('CA');
        $b = pezzo('CB');
        $id = creaScheda(richiesta('choice', [['product_id' => (string) $a, 'quantity' => '1']], [[
            'name' => 'Gusto', 'min' => '1', 'max' => '1',
            'options' => [['product_id' => $a, 'surcharge' => '0'], ['product_id' => $b, 'surcharge' => '2.5']],
        ]]));
        $forma = Bundles::forModel($id);

        return $forma['mode'] === 'choice'
            && $forma['components'] === []
            && righe(BundleComponent::class, ['product_model_id' => $id]) === []
            && count($forma['groups']) === 1
            && $forma['groups'][0]['name'] === 'Gusto'
            && $forma['groups'][0]['min'] === 1 && $forma['groups'][0]['max'] === 1
            && array_column($forma['groups'][0]['options'], 'product_id') === [$a, $b]
            && array_column($forma['groups'][0]['options'], 'surcharge') === ['0.00', '2.50'];
    });
});

check('un multiprodotto misto scrive componenti e gruppi', function () {
    return prova(static function (): bool {
        accendiFunzionalita(['orders', 'bundles']);
        $a = pezzo('MA');
        $b = pezzo('MB');
        $c = pezzo('MC');
        $id = creaScheda(richiesta('mixed', [['product_id' => (string) $a, 'quantity' => '3']], [[
            'name' => 'Extra', 'min' => '0', 'max' => '2',
            'options' => [['product_id' => $b, 'surcharge' => '1'], ['product_id' => $c, 'surcharge' => '0']],
        ]], ['show_components_value' => 'true']));
        $forma = Bundles::forModel($id);

        return $forma['mode'] === 'mixed' && $forma['show_value'] === true
            && count($forma['components']) === 1 && $forma['components'][0]['quantity'] === 3.0
            && count($forma['groups']) === 1 && count($forma['groups'][0]['options']) === 2;
    });
});

check('un fisso non scrive i gruppi che il pannello nascosto manda, un a scelta non scrive i componenti', function () {
    return prova(static function (): bool {
        accendiFunzionalita(['orders', 'bundles']);
        $a = pezzo('NA');
        $b = pezzo('NB');
        $gruppo = ['name' => 'Gusto', 'min' => '1', 'max' => '1', 'options' => [['product_id' => $b, 'surcharge' => '0']]];
        $fisso = creaScheda(richiesta('fixed', [['product_id' => (string) $a, 'quantity' => '1']], [$gruppo]));
        $scelta = creaScheda(richiesta('choice', [['product_id' => (string) $a, 'quantity' => '1']], [$gruppo]));

        return righe(BundleGroup::class, ['product_model_id' => $fisso]) === []
            && count(righe(BundleComponent::class, ['product_model_id' => $fisso])) === 1
            && righe(BundleComponent::class, ['product_model_id' => $scelta]) === []
            && count(righe(BundleGroup::class, ['product_model_id' => $scelta])) === 1;
    });
});

check('passare da misto a fisso cancella davvero gruppi e opzioni', function () {
    return prova(static function (): bool {
        accendiFunzionalita(['orders', 'bundles']);
        $a = pezzo('PA');
        $b = pezzo('PB');
        $gruppo = ['name' => 'Extra', 'min' => '0', 'max' => '1', 'options' => [['product_id' => $b, 'surcharge' => '1']]];
        $id = creaScheda(richiesta('mixed', [['product_id' => (string) $a, 'quantity' => '1']], [$gruppo]));
        $gruppoId = (int) righe(BundleGroup::class, ['product_model_id' => $id])[0]['id'];

        aggiornaScheda($id, richiesta('fixed', [['product_id' => (string) $a, 'quantity' => '1']], [$gruppo]));

        return righe(BundleGroup::class, ['product_model_id' => $id]) === []
            && righe(BundleGroupOption::class, ['bundle_group_id' => $gruppoId]) === []
            && ProductModel::findById($id)['bundle_mode'] === 'fixed';
    });
});

check('salvare di nuovo la stessa composizione tiene gli id delle opzioni, anche se cambia il sovrapprezzo', function () {
    return prova(static function (): bool {
        accendiFunzionalita(['orders', 'bundles']);
        $a = pezzo('QA');
        $b = pezzo('QB');
        $id = creaScheda(richiesta('choice', [], [[
            'name' => 'Gusto', 'min' => '1', 'max' => '1',
            'options' => [['product_id' => $a, 'surcharge' => '0'], ['product_id' => $b, 'surcharge' => '1']],
        ]]));
        $prima = Bundles::forModel($id)['groups'][0];
        $opzioni = array_map(
            static fn (array $o): array => ['id' => $o['id'], 'product_id' => $o['product_id'], 'surcharge' => $o['surcharge']],
            $prima['options']
        );
        $opzioni[1]['surcharge'] = '4.00';

        aggiornaScheda($id, richiesta('choice', [], [[
            'id' => $prima['id'], 'name' => 'Gusto', 'min' => '1', 'max' => '1', 'options' => $opzioni,
        ]]));
        $dopo = Bundles::forModel($id)['groups'][0];

        return $dopo['id'] === $prima['id']
            && array_column($dopo['options'], 'id') === array_column($prima['options'], 'id')
            && array_column($dopo['options'], 'surcharge') === ['0.00', '4.00'];
    });
});

check('un componente tolto dall\'elenco sparisce, uno nuovo entra in fondo', function () {
    return prova(static function (): bool {
        accendiFunzionalita(['orders', 'bundles']);
        $a = pezzo('RA');
        $b = pezzo('RB');
        $c = pezzo('RC');
        $id = creaScheda(richiesta('fixed', [
            ['product_id' => (string) $a, 'quantity' => '1'],
            ['product_id' => (string) $b, 'quantity' => '1'],
        ], []));
        $prima = righe(BundleComponent::class, ['product_model_id' => $id]);

        aggiornaScheda($id, richiesta('fixed', [
            ['id' => (string) $prima[1]['id'], 'product_id' => (string) $b, 'quantity' => '2'],
            ['product_id' => (string) $c, 'quantity' => '1'],
        ], []));
        $dopo = righe(BundleComponent::class, ['product_model_id' => $id]);

        return array_map('intval', array_column($dopo, 'product_id')) === [$b, $c]
            && (int) $dopo[0]['id'] === (int) $prima[1]['id']
            && (float) $dopo[0]['quantity'] === 2.0;
    });
});

check('«type» non si cambia in modifica, qualunque cosa arrivi', function () {
    return prova(static function (): bool {
        accendiFunzionalita(['orders', 'bundles']);
        $a = pezzo('TA');
        $id = creaScheda(richiesta('fixed', [['product_id' => (string) $a, 'quantity' => '1']], []));
        $valori = aggiornaScheda($id, richiesta('fixed', [['product_id' => (string) $a, 'quantity' => '1']], [], ['type' => 'simple']));

        return !array_key_exists('type', $valori) && ProductModel::findById($id)['type'] === 'bundle';
    });
});

check('con la funzionalità spenta «bundle» diventa «simple» e non si scrive niente', function () {
    return prova(static function (): bool {
        accendiFunzionalita(['orders']);
        spegniFunzionalita(['bundles']);
        $a = pezzo('SA');
        $id = creaScheda(richiesta('fixed', [['product_id' => (string) $a, 'quantity' => '1']], []));
        $modello = ProductModel::findById($id);

        return $modello['type'] === 'simple'
            && righe(BundleComponent::class, ['product_model_id' => $id]) === [];
    });
});

check('un multiprodotto ignora giacenza, varianti e fornitori che i pannelli nascosti mandano', function () {
    return prova(static function (): bool {
        accendiFunzionalita(['orders', 'bundles']);
        $a = pezzo('GA');
        $id = creaScheda(richiesta('fixed', [['product_id' => (string) $a, 'quantity' => '1']], [], [
            'has_variants' => 'true',
            'product_stock' => '9',
            'product_min_stock' => '3',
            'product_suppliers' => '[{"supplier_id":99999,"supplier_sku":"","cost":3}]',
        ]));
        $prodotti = righe(Product::class, ['product_model_id' => $id]);

        return count($prodotti) === 1
            && ProductModel::findById($id)['has_variants'] === 'false'
            && abs((float) (Levels::of((int) $prodotti[0]['id'])['quantity'] ?? 0)) < 0.001;
    });
});

check('eliminare un multiprodotto toglie composizione, gruppi e opzioni', function () {
    return prova(static function (): bool {
        accendiFunzionalita(['orders', 'bundles']);
        $a = pezzo('DA');
        $b = pezzo('DB');
        $id = creaScheda(richiesta('mixed', [['product_id' => (string) $a, 'quantity' => '1']], [[
            'name' => 'Extra', 'min' => '0', 'max' => '1', 'options' => [['product_id' => $b, 'surcharge' => '0']],
        ]]));
        $gruppoId = (int) righe(BundleGroup::class, ['product_model_id' => $id])[0]['id'];

        ProductModelResource::deleteRecord($id);

        return righe(BundleComponent::class, ['product_model_id' => $id]) === []
            && righe(BundleGroup::class, ['product_model_id' => $id]) === []
            && righe(BundleGroupOption::class, ['bundle_group_id' => $gruppoId]) === [];
    });
});

check('«composizione» e «prodotti scelti» si leggono per la scheda', function () {
    return prova(static function (): bool {
        accendiFunzionalita(['orders', 'bundles']);
        $a = pezzo('LA');
        $b = pezzo('LB');
        $id = creaScheda(richiesta('mixed', [['product_id' => (string) $a, 'quantity' => '2']], [[
            'name' => 'Extra', 'min' => '0', 'max' => '1', 'options' => [['product_id' => $b, 'surcharge' => '1.5']],
        ]]));
        $valori = ProductModelResource::mutateFormValues(ProductModel::findById($id), 'edit');
        $componenti = $valori['bundle_components'] ?? [];
        $gruppi = $valori['bundle_groups'] ?? [];
        $opzioni = json_decode((string) ($gruppi[0]['options'] ?? ''), true);

        return ProductModelResource::isBundleModel($id)
            && !ProductModelResource::isBundleModel(modelloDi($a))
            && count($componenti) === 1 && (int) $componenti[0]['product_id'] === $a
            && count($gruppi) === 1 && $gruppi[0]['name'] === 'Extra'
            && is_array($opzioni) && count($opzioni) === 1 && (int) $opzioni[0]['product_id'] === $b
            && isset($opzioni[0]['id']);
    });
});

check('l\'elenco dei prodotti scelti non offre i multiprodotti e tiene il componente spento, segnato', function () {
    return prova(static function (): bool {
        accendiFunzionalita(['orders', 'bundles']);
        $a = pezzo('OA');
        $spento = pezzo('OB');
        $conf = multiprodottoDiProva('fixed', [['product_id' => $a, 'quantity' => 1]], []);
        $id = creaScheda(richiesta('fixed', [['product_id' => (string) $spento, 'quantity' => '1']], []));
        Product::update(['active' => 'false'], $spento);
        $elenco = ProductModelResource::bundleOptionProducts($id);

        return isset($elenco[$a]) && !isset($elenco[$conf])
            && isset($elenco[$spento]) && str_contains($elenco[$spento], '(disattivato)')
            && !isset(ProductModelResource::bundleOptionProducts(0)[$spento]);
    });
});

/** Le richieste che il salvataggio rifiuta, con la frase che le dice. */
$sbagliate = static function (): array {
    $a = pezzo('XA');
    $b = pezzo('XB');
    $conf = multiprodottoDiProva('fixed', [['product_id' => $a, 'quantity' => 1]], []);
    $cancellato = pezzo('XC');
    Product::query()->Update(Product::$table, ['deleted' => 'true'], 'id', $cancellato);
    $una = static fn (array $over = []): array => array_merge(
        ['name' => 'Gusto', 'min' => '1', 'max' => '1', 'options' => [['product_id' => $a, 'surcharge' => '0'], ['product_id' => $b, 'surcharge' => '0']]],
        $over
    );

    return [
        'fisso senza componenti' => ['bundle.no_components', richiesta('fixed', [], [])],
        'a scelta senza gruppi' => ['bundle.no_groups', richiesta('choice', [], [])],
        'misto senza gruppi' => ['bundle.no_groups', richiesta('mixed', [['product_id' => (string) $a, 'quantity' => '1']], [])],
        'misto senza componenti' => ['bundle.no_components', richiesta('mixed', [], [$una()])],
        'quantità zero' => ['bundle.zero_quantity', richiesta('fixed', [['product_id' => (string) $a, 'quantity' => '0']], [])],
        'componente doppio' => ['bundle.duplicate_product', richiesta('fixed', [
            ['product_id' => (string) $a, 'quantity' => '1'], ['product_id' => (string) $a, 'quantity' => '2'],
        ], [])],
        'prodotto doppio in un gruppo' => ['bundle.duplicate_product', richiesta('choice', [], [$una([
            'options' => [['product_id' => $a, 'surcharge' => '0'], ['product_id' => $a, 'surcharge' => '1']],
        ])])],
        'minimo sopra il massimo' => ['bundle.group_range', richiesta('choice', [], [$una(['min' => '2', 'max' => '1'])])],
        'massimo zero' => ['bundle.group_range', richiesta('choice', [], [$una(['max' => '0'])])],
        'massimo sopra le opzioni' => ['bundle.group_range', richiesta('choice', [], [$una(['max' => '3'])])],
        'nome del gruppo vuoto' => ['bundle.group_name', richiesta('choice', [], [$una(['name' => '  '])])],
        'sovrapprezzo negativo' => ['bundle.negative_surcharge', richiesta('choice', [], [$una([
            'options' => [['product_id' => $a, 'surcharge' => '-1'], ['product_id' => $b, 'surcharge' => '0']],
        ])])],
        'opzioni illeggibili' => ['bundle.options_invalid', richiesta('choice', [], [$una(['options' => '{non json'])])],
        'multiprodotto come componente' => ['bundle.nested', richiesta('fixed', [['product_id' => (string) $conf, 'quantity' => '1']], [])],
        'multiprodotto come opzione' => ['bundle.nested', richiesta('choice', [], [$una([
            'options' => [['product_id' => $conf, 'surcharge' => '0'], ['product_id' => $b, 'surcharge' => '0']],
        ])])],
        'prodotto cancellato come componente' => ['bundle.component_unavailable', richiesta('fixed', [['product_id' => (string) $cancellato, 'quantity' => '1']], [])],
        'modalità sconosciuta' => ['bundle.unknown_mode', richiesta('altro', [['product_id' => (string) $a, 'quantity' => '1']], [])],
    ];
};

check('ogni composizione sbagliata si ferma con la sua frase, e non lascia un articolo a metà', function () use ($sbagliate) {
    return prova(static function () use ($sbagliate): bool {
        accendiFunzionalita(['orders', 'bundles']);
        $ok = true;
        $contati = [];

        foreach ($sbagliate() as $nome => [$chiave, $post]) {
            $dato = rifiuto(static fn () => creaScheda($post));
            $contati[$nome] = $dato;
            $ok = $ok && $dato === $chiave && articoliChiamati((string) $post['name']) === 0;
        }

        if (!$ok) {
            fwrite(STDERR, json_encode($contati, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)."\n");
        }

        return $ok;
    });
});

check('lo stesso rifiuto vale in modifica, prima di toccare la composizione salvata', function () {
    return prova(static function (): bool {
        accendiFunzionalita(['orders', 'bundles']);
        $a = pezzo('YA');
        $id = creaScheda(richiesta('fixed', [['product_id' => (string) $a, 'quantity' => '1']], []));
        $dato = rifiuto(static fn () => aggiornaScheda($id, richiesta('fixed', [['product_id' => (string) $a, 'quantity' => '0']], [])));

        return $dato === 'bundle.zero_quantity'
            && (float) righe(BundleComponent::class, ['product_model_id' => $id])[0]['quantity'] === 1.0;
    });
});

/** Il testo del rifiuto di una guardia che lancia un `RuntimeException` (`UserError::refusal`). */
function rifiutoTesto(callable $corpo): string
{
    try {
        $corpo();
    } catch (RuntimeException $errore) {
        return $errore->getMessage();
    }

    return '';
}

/** Un prodotto usato in tre multiprodotti: fisso in uno, opzione in un altro, fisso e opzione nel terzo. */
function prodottoInUso(): array
{
    $x = pezzo('IU');
    $y = pezzo('IV');
    $fisso = multiprodottoDiProva('fixed', [['product_id' => $x, 'quantity' => 1]], []);
    $scelta = multiprodottoDiProva('choice', [], [['name' => 'G', 'min' => 1, 'max' => 1, 'options' => [['product_id' => $x], ['product_id' => $y]]]]);
    $misto = multiprodottoDiProva('mixed', [['product_id' => $x, 'quantity' => 1]], [['name' => 'G', 'min' => 1, 'max' => 1, 'options' => [['product_id' => $x], ['product_id' => $y]]]]);
    $nomi = array_map(static fn (int $id): string => (string) ProductModel::findById(modelloDi($id))['name'], [$fisso, $scelta, $misto]);

    return [$x, $y, $nomi];
}

check('un prodotto usato in un multiprodotto non si elimina, né da articolo né da versione, e il rifiuto dice quali', function () {
    return prova(static function (): bool {
        accendiFunzionalita(['orders', 'bundles']);
        [$x, $y, $nomi] = prodottoInUso();
        $daModello = rifiutoTesto(static fn () => ProductModelResource::assertDeletable(modelloDi($x)));
        $daVersione = rifiutoTesto(static fn () => ProductResource::assertDeletable($x));

        foreach ([$daModello, $daVersione] as $testo) {
            if ($testo === '') {
                return false;
            }

            foreach ($nomi as $nome) {
                if (substr_count($testo, $nome) !== 1) {
                    return false;
                }
            }
        }

        return true;
    });
});

check('un prodotto non usato si elimina come prima', function () {
    return prova(static function (): bool {
        accendiFunzionalita(['orders', 'bundles']);
        $libero = pezzo('LB');
        // Con un movimento (la giacenza iniziale) il rifiuto è quello di sempre, non il nuovo.
        $testo = rifiutoTesto(static fn () => ProductResource::assertDeletable($libero));

        return str_contains($testo, 'movimenti') && !str_contains($testo, 'multiprodotti');
    });
});

check('spegnere un prodotto usato in un multiprodotto è rifiutato, e il prodotto resta attivo', function () {
    return prova(static function (): bool {
        accendiFunzionalita(['orders', 'bundles']);
        [$x] = prodottoInUso();
        $libero = pezzo('SP');
        $riga = (array) Product::findById($x);
        $testo = rifiutoTesto(static fn () => ProductResource::mutateRequestValues(['active' => 'false'], 'update', 'backend', $riga));
        $ok = str_contains($testo, 'multiprodotti') && (string) Product::findById($x)['active'] === 'true';

        // Accenderlo (o salvarlo ancora attivo) non si ferma; uno non usato si spegne.
        ProductResource::mutateRequestValues(['active' => 'true'], 'update', 'backend', $riga);
        $values = ProductResource::mutateRequestValues(['active' => 'false'], 'update', 'backend', (array) Product::findById($libero));

        return $ok && ($values['active'] ?? '') === 'false';
    });
});

check('dalla griglia delle opzioni non si toglie né si ferma una versione usata in un multiprodotto', function () {
    return prova(static function (): bool {
        accendiFunzionalita(['orders', 'bundles']);
        [$x] = prodottoInUso();
        $modello = modelloDi($x);
        $altro = pezzo('GR');
        $_POST = ['products' => ['k1' => ['id' => (string) $x, 'active' => 'false']]];
        $fermata = rifiutoTesto(static fn () => ProductModelResource::assertVersionsKept($modello));
        $_POST = ['products' => ['k2' => ['id' => (string) $altro, 'active' => 'true']]];
        $tolta = rifiutoTesto(static fn () => ProductModelResource::assertVersionsKept($modello));
        $_POST = ['products' => ['k1' => ['id' => (string) $x, 'active' => 'true']]];
        $intatta = rifiutoTesto(static fn () => ProductModelResource::assertVersionsKept($modello));
        $_POST = [];
        $senzaGriglia = rifiutoTesto(static fn () => ProductModelResource::assertVersionsKept($modello));

        return str_contains($fermata, 'multiprodotti') && str_contains($tolta, 'multiprodotti')
            && $intatta === '' && $senzaGriglia === '';
    });
});

check('eliminare un multiprodotto non venduto porta via componenti, gruppi e opzioni, anche a funzionalità spenta', function () {
    return prova(static function (): bool {
        accendiFunzionalita(['orders', 'bundles']);
        $x = pezzo('DL');
        $y = pezzo('DM');
        $multi = multiprodottoDiProva('mixed', [['product_id' => $x, 'quantity' => 1]], [['name' => 'G', 'min' => 1, 'max' => 1, 'options' => [['product_id' => $y]]]]);
        $modello = modelloDi($multi);
        $gruppi = righe(BundleGroup::class, ['product_model_id' => $modello]);
        spegniFunzionalita(['bundles']);

        ProductModelResource::deleteRecord($modello);

        return count($gruppi) === 1
            && righe(BundleComponent::class, ['product_model_id' => $modello]) === []
            && righe(BundleGroup::class, ['product_model_id' => $modello]) === []
            && righe(BundleGroupOption::class, ['bundle_group_id' => (int) $gruppi[0]['id']]) === []
            && empty(ProductModel::findById($modello));
    });
});

check('un multiprodotto già in un ordine non si elimina; un prodotto semplice si comporta come prima', function () {
    return prova(static function (): bool {
        accendiFunzionalita(['orders', 'bundles']);
        $x = pezzo('OR');
        $multi = multiprodottoDiProva('fixed', [['product_id' => $x, 'quantity' => 1]], []);
        $ordine = ordineDiProva(30.0);
        OrderItem::create(['order_id' => $ordine, 'type' => 'product', 'position' => 1, 'product_id' => $multi, 'name' => 'Confezione', 'quantity' => '1.000', 'unit_price' => '30.00', 'line_total' => '30.00']);
        $testo = rifiutoTesto(static fn () => ProductModelResource::assertDeletable(modelloDi($multi)));
        $libero = multiprodottoDiProva('fixed', [['product_id' => $x, 'quantity' => 1]], []);
        $nonVenduto = rifiutoTesto(static fn () => ProductModelResource::assertDeletable(modelloDi($libero)));

        return str_contains($testo, 'ordini') && $nonVenduto === '';
    });
});

check('l\'elenco delle giacenze non mostra le righe di un multiprodotto, e sì quelle dei pezzi', function () {
    return prova(static function (): bool {
        accendiFunzionalita(['orders', 'bundles']);
        $pezzo = pezzo('GA');
        $confezione = creaScheda(richiesta('fixed', [['product_id' => (string) $pezzo, 'quantity' => '1']], []));
        $confezioneProdotto = (int) righe(Product::class, ['product_model_id' => $confezione])[0]['id'];

        $trovate = Product::find('WHERE '.StockLevelResource::querySchema()['condition']);
        $trovate = !is_array($trovate) ? [] : (isset($trovate['id']) ? [$trovate] : $trovate);
        $visti = array_map(static fn (array $riga): int => (int) $riga['id'], $trovate);

        return in_array($pezzo, $visti, true) && !in_array($confezioneProdotto, $visti, true);
    });
});

check('a funzionalità spenta un multiprodotto esistente si salva lo stesso e la composizione resta', function () {
    return prova(static function (): bool {
        accendiFunzionalita(['orders', 'bundles']);
        $a = pezzo('OA');
        $id = creaScheda(richiesta('fixed', [['product_id' => (string) $a, 'quantity' => '2']], [], ['show_components_value' => 'true']));
        $prima = righe(BundleComponent::class, ['product_model_id' => $id]);
        spegniFunzionalita(['bundles']);

        // Con la funzionalità spenta il form non mostra la composizione: i
        // suoi campi non arrivano, e il resto della scheda si salva.
        $post = richiesta('fixed', [], [], ['name' => 'Cesto rinominato']);
        unset($post['bundle_components'], $post['bundle_groups'], $post['bundle_mode'], $post['show_components_value'], $post['type']);
        $errore = rifiuto(static function () use ($id, $post): void {
            aggiornaScheda($id, $post);
        });
        $dopo = righe(BundleComponent::class, ['product_model_id' => $id]);
        $modello = ProductModel::findById($id);

        return $errore === ''
            && $modello['name'] === 'Cesto Rinominato'
            && $modello['type'] === 'bundle' && $modello['bundle_mode'] === 'fixed' && $modello['show_components_value'] === 'true'
            && count($dopo) === 1 && (int) $dopo[0]['id'] === (int) $prima[0]['id'];
    });
});

summary();
