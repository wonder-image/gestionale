<?php
/** php tests/integrazione/ProductModelTest.php */
declare(strict_types=1);

const SITE = '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site';

chdir(SITE);
$GLOBALS['ROOT'] = SITE;
require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__ . '/../harness.php';

use Wonder\Plugin\Gestionale\Models\Catalog\Attribute;
use Wonder\Plugin\Gestionale\Models\Catalog\AttributeValue;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModelAttribute;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductVariant;
use Wonder\Plugin\Gestionale\Support\Catalog\Code;
use Wonder\Plugin\Gestionale\Support\Catalog\ProductAttributes;
use Wonder\Plugin\Gestionale\Support\Catalog\Skeleton;
use Wonder\Plugin\Gestionale\Support\Catalog\Slug;
use Wonder\Plugin\Gestionale\Resources\Catalog\ProductModelResource;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Stock\Levels;
use Wonder\Sql\Transaction;

final class Annulla extends RuntimeException {}

$conta = static function (string $model): int {
    $rows = $model::find(['deleted' => 'false']);

    if (!is_array($rows) || $rows === []) {
        return 0;
    }

    return isset($rows['id']) ? 1 : count(array_filter($rows, 'is_array'));
};

$prima = [$conta(ProductModel::class), $conta(ProductVariant::class), $conta(Product::class)];

try {
    Transaction::run(static function () use ($conta, $prima): void {
        $modello = ProductModel::create([
            'code' => Code::make(ProductModel::class, Codes::MODEL),
            'name' => 'Prova integrazione',
            'slug' => Slug::make('prova-integrazione-'.uniqid()),
            'sku' => 'INT-1',
            'unit' => 'pz',
            'type' => 'simple',
            'visible' => 'true',
            'visible_online' => 'true',
            'position' => 1,
        ]);

        $modelId = (int) ($modello->insert_id ?? 0);

        check('il modello nasce', fn () => $modelId > 0);

        $scheletro = Skeleton::forModel($modelId, 'Prova integrazione', 'INT-1');

        check('con il modello nascono una variante e un prodotto', fn () =>
            $scheletro['variant_id'] > 0 && $scheletro['product_id'] > 0
        );

        check('il prodotto punta al modello e alla sua variante', function () use ($scheletro, $modelId) {
            $prodotto = Product::find(['id' => $scheletro['product_id']], 1);

            return (int) ($prodotto['product_model_id'] ?? 0) === $modelId
                && (int) ($prodotto['product_variant_id'] ?? 0) === $scheletro['variant_id'];
        });

        check('il prodotto eredita lo SKU del modello', function () use ($scheletro) {
            $prodotto = Product::find(['id' => $scheletro['product_id']], 1);

            return ($prodotto['sku'] ?? '') === 'INT-1';
        });

        // La giacenza scritta alla creazione entra come carico iniziale
        // (P59); dopo, la stessa casella rettifica.
        ProductModelResource::forgetCatalogCache();
        ProductModelResource::saveExtras($modelId, ['has_variants' => 'false', 'product_stock' => '7'], 'INT-1', [], true);

        check('la giacenza scritta in creazione è un carico iniziale', fn () =>
            abs((float) (Levels::of($scheletro['product_id'])['quantity'] ?? 0) - 7.0) < 0.001
        );

        $postPrima = $_POST;
        $_POST = ['product_stock' => '-2'];
        $rifiutato = false;

        try {
            ProductModelResource::assertStockWritable(0, false);
        } catch (UserError) {
            $rifiutato = true;
        }

        $_POST = $postPrima;

        check('una giacenza negativa scritta a mano si rifiuta', fn () => $rifiutato);

        // La scheda aperta: la giacenza arriva col punto, a pezzi interi e
        // con l'unità in coda; le descrizioni di prima arrivano all'editor.
        $valori = ProductModelResource::mutateFormValues([
            'id' => $modelId,
            'description' => "Cotone biologico\nLavare a 30&deg;",
            'short_description' => "Maglietta\r\na maniche corte",
        ], 'edit');

        check('la giacenza della scheda arriva ad AutoNumeric col punto', fn () =>
            ($valori['product_stock'] ?? null) === '7'
        );

        check('la descrizione di prima si apre a paragrafi, la breve su una riga', fn () =>
            ($valori['description'] ?? '') === '<p>Cotone biologico</p><p>Lavare a 30°</p>'
            && ($valori['short_description'] ?? '') === 'Maglietta a maniche corte'
        );

        $scheda = new class extends ProductModelResource {
            public static int $id = 0;

            protected static function currentId(): ?int
            {
                return static::$id;
            }
        };
        $scheda::$id = $modelId;

        check('la giacenza di un articolo a pezzi è intera e dice «pz»', function () use ($scheda) {
            foreach ($scheda::formSchema() as $campo) {
                if ((string) $campo->name === 'product_stock') {
                    $formato = ($campo->get('context') ?? [])['number'] ?? [];

                    return ($formato['decimal'] ?? null) === 0 && ($formato['symbol'] ?? null) === ' pz';
                }
            }

            return false;
        });

        // Ripulita e non protetta: niente script, niente «L\'acqua».
        check('la descrizione scritta con l\'editor si salva come HTML pulito', function () use ($modelId) {
            ProductModel::update([
                'description' => '<p>L\'acqua è <strong>buona</strong></p><script>alert(1)</script>',
            ], $modelId);
            $riga = ProductModel::find(['id' => $modelId], 1);

            return ($riga['description'] ?? '') === '<p>L\'acqua è <strong>buona</strong></p>';
        });

        // Un attributo di modello, scritto e riletto.
        $attributo = Attribute::create([
            'code' => Code::make(Attribute::class, Codes::ATTRIBUTE),
            'name' => 'Prova materiale',
            'slug' => 'prova-materiale-'.uniqid(),
            'type' => 'text',
            'level' => 'model',
            'unit' => '',
            'group_name' => '',
            'is_filterable' => 'false',
            'is_visible' => 'true',
            'position' => 90,
        ]);
        $attributeId = (int) ($attributo->insert_id ?? 0);
        $attributi = [[
            'id' => $attributeId,
            'name' => 'Prova materiale',
            'type' => 'text',
            'unit' => '',
        ]];

        check('un attributo di modello si scrive', fn () =>
            ProductAttributes::save('model', $modelId, $attributi, [$attributeId => 'Cotone']) === 1
        );

        check('e si rilegge', function () use ($modelId, $attributeId) {
            $collegamenti = ProductAttributes::read('model', $modelId);

            return ($collegamenti[$attributeId]['value_text'] ?? '') === 'Cotone';
        });

        check('e si racconta con nome e valore', function () use ($modelId, $attributi) {
            $collegamenti = ProductAttributes::read('model', $modelId);

            return ProductAttributes::describe($attributi, $collegamenti, []) === ['Prova materiale' => 'Cotone'];
        });

        check('svuotarlo lo toglie', function () use ($modelId, $attributi, $attributeId) {
            ProductAttributes::save('model', $modelId, $attributi, [$attributeId => '']);

            return ProductAttributes::read('model', $modelId) === [];
        });

        check('un attributo a elenco scrive l\'id del valore', function () use ($modelId, $attributeId) {
            $valore = AttributeValue::create([
                'attribute_id' => $attributeId,
                'label' => 'Blu',
                'color' => '#1f4ed8',
                'position' => 1,
            ]);
            $valueId = (int) ($valore->insert_id ?? 0);
            $elenco = [['id' => $attributeId, 'name' => 'Prova materiale', 'type' => 'select', 'unit' => '']];

            ProductAttributes::save('model', $modelId, $elenco, [$attributeId => (string) $valueId]);
            $collegamenti = ProductAttributes::read('model', $modelId);

            return (int) ($collegamenti[$attributeId]['attribute_value_id'] ?? 0) === $valueId
                && ($collegamenti[$attributeId]['value_text'] ?? '') === '';
        });

        // Un attributo a valori può averne più d'uno sullo stesso articolo.
        $lavaggio = Attribute::create([
            'code' => Code::make(Attribute::class, Codes::ATTRIBUTE),
            'name' => 'Prova lavaggio',
            'slug' => 'prova-lavaggio-'.uniqid(),
            'type' => 'icon',
            'level' => 'model',
            'unit' => '',
            'group_name' => '',
            'is_filterable' => 'false',
            'is_visible' => 'true',
            'position' => 91,
        ]);
        $lavaggioId = (int) ($lavaggio->insert_id ?? 0);
        $simboli = [];

        foreach (['30°', 'Non candeggiare', 'Stiro basso'] as $posizione => $etichetta) {
            $simbolo = AttributeValue::create([
                'attribute_id' => $lavaggioId,
                'label' => $etichetta,
                'position' => $posizione + 1,
            ]);
            $simboli[] = (int) ($simbolo->insert_id ?? 0);
        }

        [$trenta, $candeggio, $stiro] = $simboli;
        $multipli = [['id' => $lavaggioId, 'name' => 'Prova lavaggio', 'type' => 'icon', 'unit' => '']];
        $valoriDi = static fn (int $id): array => array_map(
            static fn (array $riga): int => (int) $riga['attribute_value_id'],
            ProductAttributes::rows('model', $modelId)[$id] ?? []
        );

        check('più valori dello stesso attributo diventano più righe', function () use ($modelId, $multipli, $lavaggioId, $trenta, $candeggio, $valoriDi) {
            $scritte = ProductAttributes::save('model', $modelId, $multipli, [$lavaggioId => [(string) $trenta, $candeggio, $trenta, '', 'x', 0]]);

            return $scritte === 2 && $valoriDi($lavaggioId) === [$trenta, $candeggio];
        });

        check('la lettura a riga singola dà la prima', fn () =>
            (int) (ProductAttributes::read('model', $modelId)[$lavaggioId]['attribute_value_id'] ?? 0) === $trenta
        );

        check('il racconto mette i valori in fila', function () use ($modelId, $multipli, $lavaggioId, $simboli) {
            $valori = [];

            foreach ($simboli as $posizione => $id) {
                $valori[$id] = ['id' => $id, 'label' => ['30°', 'Non candeggiare', 'Stiro basso'][$posizione]];
            }

            return ProductAttributes::describe($multipli, ProductAttributes::rows('model', $modelId), $valori)
                === ['Prova lavaggio' => '30°, Non candeggiare'];
        });

        check('riscrivere toglie i valori lasciati e aggiunge i nuovi', function () use ($modelId, $multipli, $lavaggioId, $candeggio, $stiro, $valoriDi) {
            $scritte = ProductAttributes::save('model', $modelId, $multipli, [$lavaggioId => [$candeggio, $stiro]]);

            return $scritte === 1 && $valoriDi($lavaggioId) === [$candeggio, $stiro];
        });

        check('un valore solo scrive una riga sola', function () use ($modelId, $multipli, $lavaggioId, $stiro, $valoriDi) {
            ProductAttributes::save('model', $modelId, $multipli, [$lavaggioId => (string) $stiro]);

            return $valoriDi($lavaggioId) === [$stiro];
        });

        check('nessun valore non lascia righe', function () use ($modelId, $multipli, $lavaggioId) {
            ProductAttributes::save('model', $modelId, $multipli, [$lavaggioId => []]);

            return !isset(ProductAttributes::rows('model', $modelId)[$lavaggioId]);
        });

        check('un valore ripetuto nel database resta una volta sola', function () use ($modelId, $multipli, $lavaggioId, $trenta, $valoriDi) {
            foreach ([1, 2] as $volta) {
                ProductModelAttribute::create(['product_model_id' => $modelId, 'attribute_id' => $lavaggioId, 'attribute_value_id' => $trenta]);
            }

            ProductAttributes::save('model', $modelId, $multipli, [$lavaggioId => [$trenta]]);

            return $valoriDi($lavaggioId) === [$trenta];
        });

        check('un attributo di testo con righe doppie ne tiene una', function () use ($modelId, $attributi, $attributeId) {
            ProductAttributes::save('model', $modelId, [['id' => $attributeId, 'type' => 'text']], [$attributeId => '']);

            foreach (['Lino', 'Canapa'] as $testo) {
                ProductModelAttribute::create(['product_model_id' => $modelId, 'attribute_id' => $attributeId, 'value_text' => $testo]);
            }

            ProductAttributes::save('model', $modelId, $attributi, [$attributeId => 'Cotone']);
            $righe = ProductAttributes::rows('model', $modelId)[$attributeId] ?? [];

            return count($righe) === 1 && ($righe[0]['value_text'] ?? '') === 'Cotone';
        });

        // La scheda tecnica: le pillole spuntate arrivano come elenco, si
        // salvano e si rileggono spuntate.
        $salva = new class extends ProductModelResource {
            public static function saveFromPost(int $modelId, array $post): void
            {
                static::saveModelAttributes($modelId, $post);
            }
        };
        ProductModelResource::forgetCatalogCache();

        check('le pillole spuntate della scheda tecnica si salvano e si rileggono', function () use ($salva, $modelId, $lavaggioId, $trenta, $stiro, $valoriDi) {
            $salva::saveFromPost($modelId, ['attribute_'.$lavaggioId => [(string) $trenta, (string) $stiro]]);
            $valori = ProductModelResource::mutateFormValues(['id' => $modelId], 'edit');

            return $valoriDi($lavaggioId) === [$trenta, $stiro]
                && ($valori['attribute_'.$lavaggioId] ?? null) === [(string) $trenta, (string) $stiro];
        });

        check('togliere tutte le spunte svuota la caratteristica', function () use ($salva, $modelId, $lavaggioId, $valoriDi) {
            // Un gruppo di caselle senza spunte non arriva nel POST.
            $salva::saveFromPost($modelId, []);

            return $valoriDi($lavaggioId) === [];
        });

        check('nella scheda la caratteristica a elenco è a pillole', function () use ($scheda, $lavaggioId) {
            foreach ($scheda::formSchema() as $campo) {
                if ((string) $campo->name === 'attribute_'.$lavaggioId) {
                    return $campo->get('pills') === true && count((array) $campo->get('options')) === 3;
                }
            }

            return false;
        });

        throw new Annulla();
    });
} catch (Annulla) {
}

check('dopo l\'annullamento il catalogo è come prima', fn () =>
    [$conta(ProductModel::class), $conta(ProductVariant::class), $conta(Product::class)] === $prima
);

summary();
