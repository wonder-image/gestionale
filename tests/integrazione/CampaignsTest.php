<?php
/** php tests/integrazione/CampaignsTest.php */

const SITE = '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site';

chdir(SITE);
$GLOBALS['ROOT'] = SITE;

require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__.'/../harness.php';
require __DIR__.'/supporto/compra.php';

use Wonder\Plugin\Gestionale\Models\Catalog\Brand;
use Wonder\Plugin\Gestionale\Models\Catalog\Category;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModelCategory;
use Wonder\Plugin\Gestionale\Models\Promotions\DiscountCampaign;
use Wonder\Plugin\Gestionale\Models\Promotions\DiscountCampaignBrand;
use Wonder\Plugin\Gestionale\Models\Promotions\DiscountCampaignCategory;
use Wonder\Plugin\Gestionale\Models\Promotions\DiscountCampaignProductModel;
use Wonder\Plugin\Gestionale\Support\Catalog\Code;
use Wonder\Plugin\Gestionale\Support\Catalog\Slug;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Promotions\Campaigns;
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
function articoloAPrezzo(string $prezzo, string $scontato = '0.00'): array
{
    $prodotto = articoloConGiacenza(0, 'CMP-'.uniqid());
    Product::update(['price' => $prezzo, 'sale_price' => $scontato], $prodotto);

    return [$prodotto, modelloDi($prodotto)];
}

function categoriaDiProva(string $nome, int $padre = 0): int
{
    $riga = Category::create([
        'code' => Code::make(Category::class, Codes::CATEGORY),
        'name' => $nome,
        'slug' => Slug::unique($nome, Category::class),
        'parent_id' => $padre,
        'position' => 900,
        'visible' => 'true',
    ]);

    return (int) ($riga->insert_id ?? 0);
}

/** Una campagna in corso (ora = ORA) salvo diversa indicazione. */
function campagnaDiProva(array $valori = []): int
{
    $riga = DiscountCampaign::create($valori + [
        'code' => Code::make(DiscountCampaign::class, Codes::DISCOUNT_CAMPAIGN),
        'name' => 'Prova '.uniqid(),
        'discount_type' => 'percent',
        'discount_value' => '20.00',
        'starts_at' => '2026-10-01 00:00:00',
        'ends_at' => '2026-10-31 23:59:59',
        'active' => 'true',
        'applies_to_all' => 'false',
        'exclude_sale_products' => 'false',
        'applies_online' => 'true',
        'applies_office' => 'false',
        'applies_pos' => 'false',
        'note' => '',
    ]);

    return (int) ($riga->insert_id ?? 0);
}

function campagnaSuModello(int $modello, array $valori = []): int
{
    $id = campagnaDiProva($valori);
    DiscountCampaignProductModel::create(['discount_campaign_id' => $id, 'product_model_id' => $modello, 'is_excluded' => 'false']);

    return $id;
}

check('una campagna sulla categoria padre copre un articolo della figlia', function () {
    return prova(static function (): bool {
        accendiFunzionalita(['orders', 'discount_campaigns']);
        [$prodotto, $modello] = articoloAPrezzo('50.00');
        $padre = categoriaDiProva('Prova Padre Campagne');
        $figlia = categoriaDiProva('Prova Figlia Campagne', $padre);
        ProductModelCategory::create(['product_model_id' => $modello, 'category_id' => $figlia, 'is_main' => 'true', 'position' => 1]);
        $campagna = campagnaDiProva();
        DiscountCampaignCategory::create(['discount_campaign_id' => $campagna, 'category_id' => $padre]);

        $fatti = ProductScope::facts($prodotto);
        $selettore = ProductScope::of('campaign', $campagna);
        $trovata = Campaigns::forProduct($prodotto, ORA);

        return in_array($padre, $fatti['categories'], true)
            && in_array($figlia, $fatti['categories'], true)
            && $fatti['model_id'] === $modello
            && $selettore['categories'] === [$padre]
            && $selettore['all'] === false
            && $trovata !== null && $trovata['campaign_id'] === $campagna && $trovata['price'] === 40.0;
    });
});

check('lo stato si ricava da attiva e date, estremi compresi', function () {
    $riga = ['active' => 'true', 'starts_at' => '2026-10-05 12:00:00', 'ends_at' => '2026-10-05 18:00:00'];

    return Campaigns::status(['active' => 'false'] + $riga, ORA) === 'inactive'
        && Campaigns::status($riga, '2026-10-05 11:59:59') === 'scheduled'
        && Campaigns::status($riga, '2026-10-05 12:00:00') === 'running'
        && Campaigns::status($riga, '2026-10-05 18:00:00') === 'running'
        && Campaigns::status($riga, '2026-10-05 18:00:01') === 'ended'
        && Campaigns::status(['ends_at' => ''] + $riga, '2030-01-01 00:00:00') === 'running'
        && Campaigns::status(['ends_at' => '0000-00-00 00:00:00'] + $riga, '2030-01-01 00:00:00') === 'running';
});

check('le campagne in corso rispettano attiva, canale e cancellata', function () {
    return prova(static function (): bool {
        $viva = campagnaDiProva(['name' => 'Viva']);
        $spenta = campagnaDiProva(['active' => 'false']);
        $solaCassa = campagnaDiProva(['applies_online' => 'false', 'applies_pos' => 'true']);
        $cancellata = campagnaDiProva();
        DiscountCampaign::query()->Update(DiscountCampaign::$table, ['deleted' => 'true'], 'id', $cancellata);
        $scaduta = campagnaDiProva(['ends_at' => '2026-10-04 23:59:59']);

        $online = array_map(static fn (array $r): int => (int) $r['id'], Campaigns::running(ORA, 'online'));
        $cassa = array_map(static fn (array $r): int => (int) $r['id'], Campaigns::running(ORA, 'pos'));

        return in_array($viva, $online, true)
            && !in_array($spenta, $online, true)
            && !in_array($solaCassa, $online, true)
            && !in_array($cancellata, $online, true)
            && !in_array($scaduta, $online, true)
            && in_array($solaCassa, $cassa, true)
            && !in_array($viva, $cassa, true);
    });
});

check('senza la funzionalità o a campagna scaduta il prezzo di campagna non c\'è', function () {
    return prova(static function (): bool {
        accendiFunzionalita(['orders', 'discount_campaigns']);
        [$prodotto, $modello] = articoloAPrezzo('50.00');
        campagnaSuModello($modello);
        $con = Campaigns::forProduct($prodotto, ORA);
        $scaduta = Campaigns::forProduct($prodotto, '2026-11-01 00:00:00');
        $anticipo = Campaigns::forProduct($prodotto, '2026-09-30 23:59:59');
        spegniFunzionalita(['discount_campaigns']);

        return $con !== null && $scaduta === null && $anticipo === null
            && Campaigns::forProduct($prodotto, ORA) === null
            && Campaigns::display($prodotto, ORA) === null;
    });
});

check('due campagne sullo stesso articolo: vince lo sconto maggiore, a parità la più vecchia', function () {
    return prova(static function (): bool {
        accendiFunzionalita(['orders', 'discount_campaigns']);
        [$prodotto, $modello] = articoloAPrezzo('50.00');
        $piccola = campagnaSuModello($modello, ['discount_value' => '10.00']);
        $grande = campagnaSuModello($modello, ['discount_value' => '30.00']);
        $primo = Campaigns::forProduct($prodotto, ORA);

        $uguale = campagnaSuModello($modello, ['discount_type' => 'amount', 'discount_value' => '15.00']);
        $parita = Campaigns::forProduct($prodotto, ORA);

        return $primo['campaign_id'] === $grande && $primo['price'] === 35.0
            && $parita['campaign_id'] === $grande && $uguale > $grande && $piccola < $grande;
    });
});

check('«esclude i prodotti scontati» lascia al prezzo scontato la precedenza', function () {
    return prova(static function (): bool {
        accendiFunzionalita(['orders', 'discount_campaigns']);
        [$prodotto, $modello] = articoloAPrezzo('50.00', '45.00');
        campagnaSuModello($modello, ['exclude_sale_products' => 'true']);

        return Campaigns::forProduct($prodotto, ORA) === null;
    });
});

check('la vetrina riceve nome, prezzo da barrare, prezzo, percentuale e fine', function () {
    return prova(static function (): bool {
        accendiFunzionalita(['orders', 'discount_campaigns']);
        [$prodotto, $modello] = articoloAPrezzo('50.00');
        $id = campagnaSuModello($modello, ['name' => 'Saldi Di Prova']);
        $vetrina = Campaigns::display($prodotto, ORA);

        return $vetrina !== null
            && $vetrina['campaign_id'] === $id
            && $vetrina['name'] === 'Saldi Di Prova'
            && $vetrina['base_price'] === 50.0
            && $vetrina['price'] === 40.0
            && $vetrina['percent'] === 20.0
            && $vetrina['ends_at'] === '2026-10-31 23:59:59';
    });
});

check('l\'anteprima conta i prodotti coperti e mostra fino a tre esempi', function () {
    return prova(static function (): bool {
        $marchio = Brand::create([
            'code' => Code::make(Brand::class, Codes::BRAND),
            'name' => 'Marchio anteprima',
            'slug' => Slug::unique('Marchio anteprima', Brand::class),
            'position' => 1,
            'visible' => 'true',
        ]);
        $marchioId = (int) ($marchio->insert_id ?? 0);

        for ($i = 0; $i < 4; $i++) {
            [, $modello] = articoloAPrezzo('50.00');
            ProductModel::update(['brand_id' => $marchioId], $modello);
        }

        $bozza = ['discount_type' => 'percent', 'discount_value' => 20, 'exclude_sale_products' => false, 'scope' => ['brands' => [$marchioId]]];
        $anteprima = Campaigns::preview($bozza, ORA);
        $nessuno = Campaigns::preview(['scope' => ['brands' => [$marchioId + 9999]]] + $bozza, ORA);

        return $anteprima['count'] === 4
            && count($anteprima['examples']) === 3
            && $anteprima['examples'][0]['before'] === '50.00'
            && $anteprima['examples'][0]['after'] === '40.00'
            && $nessuno['count'] === 0 && $nessuno['examples'] === [];
    });
});

check('le sovrapposizioni: tempo e prodotti insieme, e non quelle spente o lontane', function () {
    return prova(static function (): bool {
        [, $modelloA] = articoloAPrezzo('50.00');
        [, $modelloB] = articoloAPrezzo('50.00');
        $conflitto = campagnaSuModello($modelloA, ['name' => 'Conflitto', 'starts_at' => '2026-10-10 00:00:00', 'ends_at' => '2026-10-20 23:59:59']);
        campagnaSuModello($modelloA, ['name' => 'Spenta', 'active' => 'false', 'starts_at' => '2026-10-10 00:00:00', 'ends_at' => '2026-10-20 23:59:59']);
        campagnaSuModello($modelloB, ['name' => 'Altri prodotti', 'starts_at' => '2026-10-10 00:00:00', 'ends_at' => '2026-10-20 23:59:59']);
        campagnaSuModello($modelloA, ['name' => 'Il giorno prima', 'starts_at' => '2026-10-01 00:00:00', 'ends_at' => '2026-10-14 23:59:59']);

        $bozza = ['starts_at' => '2026-10-15 00:00:00', 'ends_at' => '2026-10-25 00:00:00', 'scope' => ['models' => [$modelloA]]];
        $trovate = Campaigns::overlaps($bozza, ORA);
        $senzaFine = Campaigns::overlaps(['ends_at' => ''] + $bozza, ORA);
        $se_stessa = Campaigns::overlaps($bozza, ORA, $conflitto);

        return in_array('Conflitto', $trovate, true)
            && !in_array('Spenta', $trovate, true)
            && !in_array('Altri prodotti', $trovate, true)
            && !in_array('Il giorno prima', $trovate, true)
            && in_array('Conflitto', $senzaFine, true)
            && !in_array('Conflitto', $se_stessa, true);
    });
});

check('la campagna che sta in piedi passa, quella storta è rifiutata con la sua frase', function () {
    $buona = ['discount_type' => 'percent', 'discount_value' => 20, 'starts_at' => '2026-10-01 00:00:00', 'ends_at' => '2026-10-31 00:00:00', 'scope' => ['all' => true]];
    $rifiuta = static function (array $bozza): string {
        try {
            Campaigns::validate($bozza);
        } catch (UserError $e) {
            return $e->key();
        }

        return '';
    };

    Campaigns::validate($buona);

    return $rifiuta(['ends_at' => '2026-09-30 00:00:00'] + $buona) === 'campaign.ends_before_starts'
        && $rifiuta(['discount_value' => 101] + $buona) === 'campaign.percent_out_of_range'
        && $rifiuta(['discount_value' => 0] + $buona) === 'campaign.percent_out_of_range'
        && $rifiuta(['discount_type' => 'amount', 'discount_value' => 0] + $buona) === 'campaign.amount_negative'
        && $rifiuta(['scope' => ['all' => false, 'categories' => []]] + $buona) === 'campaign.scope_empty'
        && $rifiuta(['scope' => ['all' => false, 'models' => [3]]] + $buona) === ''
        && $rifiuta(['ends_at' => ''] + $buona) === '';
});

summary();
