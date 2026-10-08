<?php
/** php tests/integrazione/DiscountCampaignBackendTest.php */

define('SITE', getenv('WI_TEST_SITE') ?: '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site');

chdir(SITE);
$GLOBALS['ROOT'] = SITE;

require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__.'/../harness.php';
require __DIR__.'/supporto/compra.php';
require __DIR__.'/supporto/layout.php';

use Wonder\Plugin\Gestionale\Models\Catalog\Category;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Promotions\DiscountCampaign;
use Wonder\Plugin\Gestionale\Models\Promotions\DiscountCampaignCategory;
use Wonder\Plugin\Gestionale\Models\Promotions\DiscountCampaignProductModel;
use Wonder\Plugin\Gestionale\Resources\Promotions\CampaignProductTableResource;
use Wonder\Plugin\Gestionale\Resources\Promotions\DiscountCampaignResource;
use Wonder\Plugin\Gestionale\Support\Catalog\Code;
use Wonder\Plugin\Gestionale\Support\Catalog\Slug;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Promotions\ProductScope;
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

const ORA = '2026-10-05 12:00:00';

/** Un articolo con prezzo, e il suo modello. */
function articoloAPrezzo(string $prezzo): array
{
    $prodotto = articoloConGiacenza(0, 'CMB-'.uniqid());
    Product::update(['price' => $prezzo, 'sale_price' => '0.00'], $prodotto);

    return [$prodotto, modelloDi($prodotto)];
}

function categoriaDiProva(string $nome): int
{
    $riga = Category::create([
        'code' => Code::make(Category::class, Codes::CATEGORY),
        'name' => $nome,
        'slug' => Slug::unique($nome, Category::class),
        'parent_id' => 0,
        'position' => 900,
        'visible' => 'true',
    ]);

    return (int) ($riga->insert_id ?? 0);
}

/**
 * Il salvataggio dalla pagina del backend: lo stesso percorso del controller
 * (preparazione dei valori, scrittura, selettore). Ridà l'id.
 *
 * @param array<string, mixed> $post
 */
function salva(array $post, ?int $id = null): int
{
    $_POST = $post;
    $vecchia = $id !== null ? DiscountCampaign::find(['id' => $id], 1) : null;
    $valori = DiscountCampaignResource::mutateRequestValues($post, $id === null ? 'store' : 'update', 'backend', $vecchia);
    // Come il controller: i valori passano dallo schema della Resource, che ha anche i campi del selettore.
    $valori = \Wonder\App\Table::key(DiscountCampaignResource::prepareSchemaName())->prepareFor(DiscountCampaignResource::modelTable(), $valori, $vecchia ?: null);

    if ($id === null) {
        $risultato = DiscountCampaign::query()->Insert(DiscountCampaign::$table, $valori + [
            'code' => Code::make(DiscountCampaign::class, Codes::DISCOUNT_CAMPAIGN),
        ]);
        $id = (int) ($risultato->insert_id ?? 0);
        DiscountCampaignResource::afterStore($risultato, $valori);
    } else {
        $risultato = DiscountCampaign::query()->Update(DiscountCampaign::$table, $valori, 'id', $id);
        DiscountCampaignResource::afterUpdate($id, $risultato, $valori);
    }

    $_POST = [];

    return $id;
}

/** La richiesta di una campagna del 20 % di ottobre, con quello che serve in più o in meno. */
function richiesta(array $in = []): array
{
    return $in + [
        'name' => 'Ottobre '.uniqid(),
        'discount_type' => 'percent',
        'discount_value' => '20',
        'starts_at' => '2026-10-01',
        'ends_at' => '2026-10-31',
        'active' => 'true',
        'exclude_sale_products' => 'false',
        'applies_online' => 'true',
        'applies_office' => 'false',
        'applies_pos' => 'false',
        'applies_to_all' => 'true',
        'note' => '',
    ];
}

/** Il tipo di errore con cui una funzione si ferma, '' se non si ferma. */
function errore(callable $fai): string
{
    try {
        $fai();
    } catch (UserError $e) {
        return $e->key();
    } catch (RuntimeException $e) {
        return $e->getMessage();
    }

    return '';
}

function conta(string $modello): int
{
    $righe = $modello::all();

    return !is_array($righe) || $righe === [] ? 0 : (array_key_exists('id', $righe) ? 1 : count($righe));
}

check('salvare una campagna scrive la riga, le date piene e i ponti', function () {
    return prova(static function (): bool {
        accendiFunzionalita(['orders', 'discount_campaigns']);
        [, $modello] = articoloAPrezzo('50.00');
        $categoria = categoriaDiProva('Prova Backend Campagne');

        $id = salva(richiesta([
            'applies_to_all' => 'false',
            'categories' => [(string) $categoria],
            'models' => [(string) $modello],
        ]));

        $riga = DiscountCampaign::find(['id' => $id], 1);
        $selettore = ProductScope::of('campaign', $id);

        return $riga['discount_value'] === '20.00'
            && $riga['starts_at'] === '2026-10-01 00:00:00'
            && $riga['ends_at'] === '2026-10-31 23:59:59'
            && $selettore['all'] === false
            && $selettore['categories'] === [$categoria]
            && $selettore['models'] === [$modello];
    });
});

check('modificare la selezione riscrive i ponti senza lasciare orfani', function () {
    return prova(static function (): bool {
        accendiFunzionalita(['orders', 'discount_campaigns']);
        [, $uno] = articoloAPrezzo('50.00');
        [, $due] = articoloAPrezzo('60.00');
        $categoria = categoriaDiProva('Prova Orfani Campagne');

        $id = salva(richiesta(['applies_to_all' => 'false', 'categories' => [(string) $categoria], 'models' => [(string) $uno]]));
        salva(richiesta(['applies_to_all' => 'false', 'models' => [(string) $due], 'excluded_models' => [(string) $uno]]), $id);

        $selettore = ProductScope::of('campaign', $id);
        $categorie = DiscountCampaignCategory::find(['discount_campaign_id' => $id]);
        $articoli = DiscountCampaignProductModel::find(['discount_campaign_id' => $id]);

        return $selettore['categories'] === []
            && ($categorie === [] || $categorie === null)
            && $selettore['models'] === [$due]
            && $selettore['excluded_models'] === [$uno]
            && count(array_key_exists('id', (array) $articoli) ? [$articoli] : (array) $articoli) === 2;
    });
});

check('una campagna che non sta in piedi si rifiuta senza scrivere niente', function () {
    return prova(static function (): bool {
        accendiFunzionalita(['orders', 'discount_campaigns']);
        $righe = conta(DiscountCampaign::class);
        $ponti = conta(DiscountCampaignProductModel::class);
        [, $modello] = articoloAPrezzo('50.00');

        $casi = [
            'campaign.ends_before_starts' => richiesta(['starts_at' => '2026-10-10', 'ends_at' => '2026-10-01']),
            'campaign.percent_out_of_range' => richiesta(['discount_value' => '120']),
            'campaign.amount_negative' => richiesta(['discount_type' => 'amount', 'discount_value' => '-5']),
            'campaign.scope_empty' => richiesta(['applies_to_all' => 'false']),
        ];

        foreach ($casi as $chiave => $post) {
            $post += ['models' => []];

            if (errore(static fn () => salva($post)) !== $chiave) {
                return false;
            }
        }

        // «Solo la selezione» con un articolo scelto invece sta in piedi.
        return conta(DiscountCampaign::class) === $righe
            && conta(DiscountCampaignProductModel::class) === $ponti
            && errore(static fn () => salva(richiesta(['applies_to_all' => 'false', 'models' => [(string) $modello]]))) === '';
    });
});

check('l\'avviso di sovrapposizione compare e il salvataggio riesce', function () {
    return prova(static function (): bool {
        accendiFunzionalita(['orders', 'discount_campaigns']);
        [, $modello] = articoloAPrezzo('50.00');

        $prima = salva(richiesta(['name' => 'Prima campagna', 'applies_to_all' => 'false', 'models' => [(string) $modello]]));
        $seconda = salva(richiesta(['name' => 'Seconda campagna', 'discount_value' => '30', 'applies_to_all' => 'false', 'models' => [(string) $modello]]));

        $avviso = DiscountCampaignResource::overlapNotice($seconda);
        $solo = DiscountCampaignResource::overlapNotice($prima) !== '';

        return str_contains($avviso, 'Prima Campagna') && $solo
            && DiscountCampaign::find(['id' => $seconda], 1)['name'] === 'Seconda Campagna';
    });
});

check('senza sovrapposizioni l\'avviso non c\'è, e l\'anteprima conta i prodotti senza scrivere niente', function () {
    return prova(static function (): bool {
        accendiFunzionalita(['orders', 'discount_campaigns']);
        [, $modello] = articoloAPrezzo('50.00');
        $id = salva(richiesta(['applies_to_all' => 'false', 'models' => [(string) $modello]]));
        $righe = conta(DiscountCampaign::class);

        $anteprima = DiscountCampaignResource::previewHtml($id);

        return DiscountCampaignResource::overlapNotice($id) === ''
            && str_contains($anteprima, '<strong>1</strong> prodotto')
            && str_contains($anteprima, 'gst_products__table')
            && conta(DiscountCampaign::class) === $righe;
    });
});

check('l\'anteprima è una tabella del core: cerca per nome o SKU e ogni riga ha nome per intero, SKU e prezzi', function () {
    return prova(static function (): bool {
        accendiFunzionalita(['orders', 'discount_campaigns']);
        [$prodotto, $modello] = articoloAPrezzo('50.00');
        ProductModel::update(['name' => 'Maglia'], $modello);
        Product::update(['name' => 'Blu / M', 'sku' => 'MAGLIA-BLU-M'], $prodotto);
        $id = salva(richiesta(['applies_to_all' => 'false', 'models' => [(string) $modello]]));

        $anteprima = DiscountCampaignResource::previewHtml($id);
        $colonne = [];

        foreach (CampaignProductTableResource::tableSchema() as $colonna) {
            $colonne[(string) $colonna->name] = $colonna->schema['formatter'] ?? null;
        }

        // Le righe le chiede l'API a ogni pagina: il formatter parte dalla riga e dall'id della campagna nella query.
        $riga = (array) Product::find(['id' => $prodotto], 1) + ['model_name' => 'Maglia', 'campaign_id' => $id];

        return str_contains($anteprima, 'gst_products__table')
            && str_contains($anteprima, 'gst_products__search_input')
            && str_contains($anteprima, 'Con la campagna')
            && array_keys($colonne) === ['photo', 'model_name', 'price', 'campaign_price']
            && str_contains($colonne['model_name']($riga), 'Maglia — Blu / M')
            && str_contains($colonne['model_name']($riga), 'MAGLIA-BLU-M')
            && str_contains($colonne['price']($riga), '50,00 €')
            && str_contains($colonne['campaign_price']($riga), '40,00 €');
    });
});

check('l\'elenco mostra lo stato giusto', function () {
    $riga = ['active' => 'true', 'starts_at' => '2026-10-01 00:00:00', 'ends_at' => '2026-10-31 23:59:59'];

    return DiscountCampaignResource::statusLabel($riga, ORA) === 'In corso'
        && DiscountCampaignResource::statusLabel(['starts_at' => '2026-11-01 00:00:00'] + $riga, ORA) === 'Programmata'
        && DiscountCampaignResource::statusLabel(['ends_at' => '2026-10-04 23:59:59'] + $riga, ORA) === 'Terminata'
        && DiscountCampaignResource::statusLabel(['active' => 'false'] + $riga, ORA) === 'Disattivata';
});

check('un id che non esiste o un tipo cambiato a mano non rompono la pagina', function () {
    return prova(static function (): bool {
        accendiFunzionalita(['orders', 'discount_campaigns']);
        [, $modello] = articoloAPrezzo('50.00');

        $strano = salva(richiesta(['discount_type' => 'regalo', 'applies_to_all' => 'false', 'models' => [(string) $modello]]));

        return DiscountCampaignResource::overlapNotice(99999999) === ''
            && DiscountCampaignResource::previewHtml(99999999) === ''
            && DiscountCampaign::find(['id' => $strano], 1)['discount_type'] === 'percent'
            && is_array(DiscountCampaignResource::mutateFormValues(['id' => 99999999, 'starts_at' => '0000-00-00 00:00:00'], 'edit'));
    });
});

check('la scheda mostra dettagli, selezione, anteprima, avviso di sovrapposizione e canali', function () {
    return prova(static function (): bool {
        accendiFunzionalita(['orders', 'discount_campaigns']);
        [, $modello] = articoloAPrezzo('50.00');
        $prima = salva(richiesta(['name' => 'Prima campagna', 'applies_to_all' => 'false', 'models' => [(string) $modello]]));
        $seconda = salva(richiesta(['name' => 'Seconda campagna', 'discount_value' => '30', 'applies_to_all' => 'false', 'models' => [(string) $modello]]));
        $html = layoutHtml(DiscountCampaignResource::showLayoutSchema((array) DiscountCampaign::find(['id' => $seconda], 1)));

        return str_contains($html, 'Seconda Campagna') && str_contains($html, '30 %')
            && str_contains($html, 'Solo la selezione')
            && str_contains($html, '<strong>1</strong> prodotto') && str_contains($html, 'gst_products__table')
            && str_contains($html, 'Prima Campagna')
            && str_contains($html, 'Sito') && str_contains($html, 'Ufficio') && str_contains($html, 'Cassa')
            && $prima > 0;
    });
});

check('il form di modifica non porta più né l\'anteprima né l\'avviso', function () {
    return prova(static function (): bool {
        accendiFunzionalita(['orders', 'discount_campaigns']);
        [, $modello] = articoloAPrezzo('50.00');
        salva(richiesta(['name' => 'Prima campagna', 'applies_to_all' => 'false', 'models' => [(string) $modello]]));
        $id = salva(richiesta(['name' => 'Seconda campagna', 'applies_to_all' => 'false', 'models' => [(string) $modello]]));
        $_GET['id'] = $id;
        $html = layoutHtml(DiscountCampaignResource::formLayoutSchema());
        unset($_GET['id']);

        return !str_contains($html, 'Anteprima') && !str_contains($html, 'negli stessi giorni');
    });
});

check('con il solo sito acceso il form della campagna non ha i toggle dei canali; con ufficio acceso sì, ma senza la cassa', function () {
    return prova(static function (): bool {
        accendiFunzionalita(['orders', 'discount_campaigns']);
        // Il sito di prova può avere canali accesi a mano: si parte da nessuno.
        spegniFunzionalita(['online_sales', 'office_sales', 'pos']);
        $solo = layoutHtml(DiscountCampaignResource::formLayoutSchema());

        accendiFunzionalita(['online_sales', 'office_sales']);
        $due = layoutHtml(DiscountCampaignResource::formLayoutSchema());

        return !str_contains($solo, 'Dove vale') && !str_contains($solo, 'applies_office')
            && str_contains($due, 'Dove vale') && str_contains($due, 'applies_online')
            && str_contains($due, 'applies_office') && !str_contains($due, 'applies_pos');
    });
});

check('con un solo canale il salvataggio della campagna non tocca gli altri e l\'unico attivo vale «sì»', function () {
    return prova(static function (): bool {
        accendiFunzionalita(['orders', 'discount_campaigns']);
        // Il sito di prova può avere canali accesi a mano: si parte da nessuno.
        spegniFunzionalita(['online_sales', 'office_sales', 'pos']);
        $senzaCanali = array_diff_key(richiesta(), array_flip(['applies_online', 'applies_office', 'applies_pos']));

        $nuova = salva($senzaCanali);
        $riga = (array) DiscountCampaign::find(['id' => $nuova], 1);

        DiscountCampaign::update(['applies_office' => 'true'], $nuova);
        salva(array_merge($senzaCanali, ['name' => 'Cambiata']), $nuova);
        $dopo = (array) DiscountCampaign::find(['id' => $nuova], 1);

        return $riga['applies_online'] === 'true' && $riga['applies_office'] === 'false' && $riga['applies_pos'] === 'false'
            && $dopo['applies_online'] === 'true' && $dopo['applies_office'] === 'true' && $dopo['name'] === 'Cambiata';
    });
});
