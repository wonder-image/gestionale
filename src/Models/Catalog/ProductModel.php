<?php

namespace Wonder\Plugin\Gestionale\Models\Catalog;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Plugin\Gestionale\Models\Tax\TaxCategory;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Sql\TableSchema as Column;

/**
 * Il modello: la scheda che il cliente legge. "T-shirt girocollo".
 *
 * Sotto ci stanno le varianti (cosa cambia l'aspetto) e i prodotti (cosa si
 * vende e sta a magazzino). Un articolo senza varianti è comunque un modello
 * con una variante e un prodotto: il pannello semplicemente non la nomina.
 *
 * `type` è `simple` o `bundle` (multiprodotto, G5). Un multiprodotto ha
 * sempre un solo prodotto, senza giacenza: ne descrivono la composizione
 * `BundleComponent` (fissi) e `BundleGroup` con le sue `BundleGroupOption`
 * (a scelta del cliente), secondo `bundle_mode`.
 *
 * Lo SKU **non** ha un indice unico: il framework scrive stringhe vuote e non
 * NULL, quindi due modelli senza SKU si scontrerebbero. L'unicità la controlla
 * la Resource, che può spiegarla con una frase.
 *
 * Il catalogo non si sincronizza: è lavoro del commerciante.
 */
final class ProductModel extends Model
{
    public static string $table = 'gst_product_models';
    public static string $folder = 'gestionale/models';
    public static string $icon = 'bi bi-box';

    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            ...static::sqlColumnsFromDataSchema(['code', 'weight', 'length', 'width', 'height', 'circumference']),
            Column::key('brand_id')->int()->foreign(Brand::$table),
            Column::key('tax_category_id')->int()->foreign(TaxCategory::$table),
            Column::key('type')->enum(['simple', 'bundle'])->default('simple'),
            // Solo per un multiprodotto: prodotti fissi, scelti dal cliente o
            // entrambi. Vuoto per un articolo semplice.
            Column::key('bundle_mode')->enum(['fixed', 'choice', 'mixed'])->null(),
            // Se la vetrina può mostrare il valore dei componenti (E1b).
            Column::key('show_components_value')->enum(['true', 'false'])->default('false'),
            Column::key('sku')->length(100),
            Column::key('unit')->length(10)->default('pz'),
            // Senza chiave esterna: vuoto vale zero, e vuol dire "la scatola
            // predefinita del negozio".
            Column::key('package_id')->int(),
            Column::key('name'),
            Column::key('slug')->length(150)->unique(),
            Column::key('short_description')->type('TEXT'),
            Column::key('description')->type('TEXT'),
            Column::key('returnable')->enum(['true', 'false'])->default('true'),
            Column::key('requires_shipping')->enum(['true', 'false'])->default('true'),
            Column::key('visible')->enum(['true', 'false'])->default('true'),
            Column::key('visible_online')->enum(['true', 'false'])->default('true'),
            // Se l'articolo si vende in più opzioni. È una risposta, non un
            // conteggio: un articolo appena creato ha già un figlio, e il
            // conteggio direbbe "no" a chi le varianti le sta per aggiungere.
            Column::key('has_variants')->enum(['true', 'false'])->default('false'),
            // Gli attributi che generano le opzioni, nell'ordine scelto:
            // "7-3-12". Il primo raggruppa, gli altri compongono il nome.
            Column::key('axes_order')->length(100),
            Column::key('position')->int(),
        ];
    }

    public static function tablePseudos(): array
    {
        return [
            'ind_brand' => ['index' => 'brand_id'],
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('code')->text()->uniqueCode(Codes::MODEL),
            Field::key('brand_id')->number()->decimals(0),
            Field::key('tax_category_id')->number()->decimals(0),
            Field::key('type')->text()->sanitize(false),
            Field::key('bundle_mode')->text()->sanitize(false),
            Field::key('show_components_value')->text()->sanitize(false),
            Field::key('sku')->text(),
            Field::key('unit')->text(),
            Field::key('package_id')->number()->decimals(0),
            Field::key('name')->text()->sanitizeFirst(),
            Field::key('slug')->text()->slug()->readonlyOnUpdate()->immutableOnUpdate(),
            Field::key('short_description')->text(),
            // Scritta con l'editor: si salva l'HTML ripulito, non il testo
            // protetto. Quella di prima, senza tag, la riprende editorHtml().
            Field::key('description')->text()->richText(),
            Field::key('weight')->number()->decimals(3),
            Field::key('length')->number()->decimals(2),
            Field::key('width')->number()->decimals(2),
            Field::key('height')->number()->decimals(2),
            Field::key('circumference')->number()->decimals(2),
            Field::key('returnable')->text()->sanitize(false),
            Field::key('requires_shipping')->text()->sanitize(false),
            Field::key('visible')->text()->sanitize(false),
            Field::key('visible_online')->text()->sanitize(false),
            Field::key('has_variants')->text()->sanitize(false),
            Field::key('axes_order')->text(),
            Field::key('position')->number()->decimals(0),
        ];
    }
}
