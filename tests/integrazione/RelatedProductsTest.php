<?php
/** php tests/integrazione/RelatedProductsTest.php */

define('SITE', getenv('WI_TEST_SITE') ?: '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site');

chdir(SITE);
$GLOBALS['ROOT'] = SITE;

require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__.'/../harness.php';
require __DIR__.'/supporto/compra.php';

use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductRelation;
use Wonder\Plugin\Gestionale\Resources\Catalog\ProductModelResource;
use Wonder\Plugin\Gestionale\Support\Catalog\RelatedProducts;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Mcp\Tools;
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

/** Un articolo nuovo, visibile e con una versione attiva. */
function articolo(string $nome): int
{
    return modelloDi(articoloConGiacenza(1, 'RP-'.$nome.'-'.uniqid()));
}

/** Salva i due elenchi come il backend: lo stesso sync del core. */
function salvaCollegati(int $modello, array $suggeriti, array $alternativi): void
{
    $righe = static fn (array $ids): array => array_map(static fn (int $id): array => ['related_model_id' => (string) $id], $ids);

    ProductModelResource::syncRepeaterRelations($modello, [
        'related_suggested' => $righe($suggeriti),
        'related_alternative' => $righe($alternativi),
    ], [], 'update', 'backend');
}

/** Le righe scritte di un tipo, nell'ordine. @return list<int> */
function scritti(int $modello, string $tipo): array
{
    $righe = ProductRelation::find(['product_model_id' => $modello, 'type' => $tipo], null, 'position', 'ASC');
    $righe = is_array($righe) ? (isset($righe['id']) ? [$righe] : $righe) : [];

    return array_map(static fn (array $r): int => (int) $r['related_model_id'], array_values($righe));
}

check('suggeriti e alternativi si salvano ognuno nel suo elenco e nel suo ordine', function () {
    return prova(static function (): bool {
        [$a, $b, $c, $d] = [articolo('A'), articolo('B'), articolo('C'), articolo('D')];
        salvaCollegati($a, [$c, $b], [$d]);

        return scritti($a, 'suggested') === [$c, $b]
            && scritti($a, 'alternative') === [$d]
            && RelatedProducts::forModel($a) === ['suggested' => [$c, $b], 'alternative' => [$d]];
    });
});

check('risalvare cambia l\'ordine e toglie le righe sparite, senza toccare l\'altro elenco', function () {
    return prova(static function (): bool {
        [$a, $b, $c, $d] = [articolo('A'), articolo('B'), articolo('C'), articolo('D')];
        salvaCollegati($a, [$b, $c], [$d]);
        $ids = array_column(ProductRelation::find(['product_model_id' => $a, 'type' => 'suggested'], null, 'position', 'ASC'), 'id', 'related_model_id');

        ProductModelResource::syncRepeaterRelations($a, ['related_suggested' => [
            ['id' => (string) $ids[$c], 'related_model_id' => (string) $c],
        ], 'related_alternative' => [['related_model_id' => (string) $d]]], [], 'update', 'backend');

        return scritti($a, 'suggested') === [$c] && scritti($a, 'alternative') === [$d];
    });
});

check('l\'articolo non si collega a sé stesso', function () {
    return prova(static function (): bool {
        [$a, $b] = [articolo('A'), articolo('B')];
        $chiavi = [];

        // Il salvataggio della scheda si ferma prima del sync, come in mutateRequestValues().
        try {
            ProductModelResource::assertRelatedRows($a, ['related_alternative' => [['related_model_id' => (string) $a]]]);
        } catch (UserError $e) {
            $chiavi[] = $e->key();
        }

        RelatedProducts::replace($a, 'suggested', [$b]);

        try {
            RelatedProducts::replace($a, 'suggested', [$b, $a]);
        } catch (UserError $e) {
            $chiavi[] = $e->key();
        }

        return $chiavi === ['catalog.related_self', 'catalog.related_self']
            && scritti($a, 'suggested') === [$b] && scritti($a, 'alternative') === [];
    });
});

check('lo stesso articolo due volte fa un collegamento solo', function () {
    return prova(static function (): bool {
        [$a, $b, $c] = [articolo('A'), articolo('B'), articolo('C')];
        salvaCollegati($a, [$b, $c, $b], []);
        RelatedProducts::replace($a, 'alternative', [$c, $c, $b]);

        return scritti($a, 'suggested') === [$b, $c] && scritti($a, 'alternative') === [$c, $b];
    });
});

check('il sito non mostra gli articoli nascosti, non online, eliminati o senza versione attiva', function () {
    return prova(static function (): bool {
        [$a, $ok, $nascosto, $offline, $eliminato, $spento] = [articolo('A'), articolo('OK'), articolo('H'), articolo('O'), articolo('E'), articolo('S')];
        ProductModel::update(['visible' => 'false'], $nascosto);
        ProductModel::update(['visible_online' => 'false'], $offline);
        ProductModel::query()->mysqli->query("UPDATE `".ProductModel::$table."` SET `deleted` = 'true' WHERE `id` = ".$eliminato);

        foreach ((array) Product::find(['product_model_id' => $spento], null) as $p) {
            if (is_array($p) && isset($p['id'])) {
                Product::update(['active' => 'false'], (int) $p['id']);
            }
        }

        salvaCollegati($a, [$nascosto, $ok, $offline], [$eliminato, $spento, $ok]);

        return RelatedProducts::forModel($a) === ['suggested' => [$ok], 'alternative' => [$ok]]
            && count(RelatedProducts::ids($a, 'suggested')) === 3;
    });
});

check('un articolo senza collegamenti dà due elenchi vuoti', function () {
    return prova(static function (): bool {
        $a = articolo('A');

        return RelatedProducts::forModel($a) === ['suggested' => [], 'alternative' => []]
            && Tools::modelRelated($a) === ['suggested' => [], 'alternative' => []];
    });
});

check('il carrello unisce i suggeriti senza doppioni e senza gli articoli già dentro', function () {
    return prova(static function (): bool {
        [$a, $b, $c, $d] = [articolo('A'), articolo('B'), articolo('C'), articolo('D')];
        salvaCollegati($a, [$c, $b], []);
        salvaCollegati($b, [$d, $c, $a], []);

        return RelatedProducts::suggestedFor([$a, $b]) === [$c, $d];
    });
});

check('MCP: replace rifiuta un articolo che non c\'è e product_get mostra i nomi', function () {
    return prova(static function (): bool {
        [$a, $b] = [articolo('A'), articolo('B')];
        RelatedProducts::replace($a, 'suggested', [$b]);
        $nome = (string) ProductModel::findById($b)['name'];

        try {
            RelatedProducts::replace($a, 'suggested', [999999999]);
        } catch (UserError $e) {
            return $e->key() === 'catalog.related_not_found'
                && Tools::modelRelated($a)['suggested'] === [['id' => $b, 'name' => $nome]];
        }

        return false;
    });
});

check('eliminare un articolo toglie i suoi collegamenti e quelli degli altri verso di lui', function () {
    return prova(static function (): bool {
        // Senza movimenti di magazzino: con quelli l'articolo non si elimina.
        $b = modelloDi(articoloConGiacenza(0, 'RP-B-'.uniqid()));
        [$a, $c] = [articolo('A'), articolo('C')];
        salvaCollegati($a, [$b], [$c]);
        salvaCollegati($b, [$a], [$c]);
        salvaCollegati($c, [$b], [$a]);
        $esito = ProductModelResource::deleteRecord($b);

        return !empty($esito->success) && ProductRelation::find(['product_model_id' => $b]) == [] && scritti($a, 'suggested') === [] && scritti($a, 'alternative') === [$c]
            && scritti($c, 'suggested') === [] && scritti($c, 'alternative') === [$a];
    });
});

summary();
