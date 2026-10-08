<?php
/** php tests/integrazione/PromotionsDemoTest.php */

define('SITE', getenv('WI_TEST_SITE') ?: '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site');

chdir(SITE);
$GLOBALS['ROOT'] = SITE;

require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__.'/../harness.php';
require __DIR__.'/supporto/compra.php';

use Wonder\Plugin\Gestionale\Console\Demo\DemoData;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Promotions\DiscountCampaign;
use Wonder\Plugin\Gestionale\Models\Promotions\DiscountCampaignBrand;
use Wonder\Plugin\Gestionale\Models\Promotions\DiscountCampaignCategory;
use Wonder\Plugin\Gestionale\Models\Promotions\DiscountCampaignProductModel;
use Wonder\Plugin\Gestionale\Models\Promotions\DiscountCampaignTag;
use Wonder\Plugin\Gestionale\Seeding\CatalogDemo;
use Wonder\Plugin\Gestionale\Seeding\ContactsDemo;
use Wonder\Plugin\Gestionale\Seeding\Demo;
use Wonder\Plugin\Gestionale\Seeding\PromotionsDemo;
use Wonder\Plugin\Gestionale\Support\Promotions\Campaigns;
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

/** Le righe di un Model che rispondono alla condizione, in elenco. */
function righe(string $model, string $dove): array
{
    $rows = $model::find($dove);

    if (!is_array($rows) || $rows === []) {
        return [];
    }

    return array_key_exists('id', $rows) ? [$rows] : array_values(array_filter($rows, 'is_array'));
}

/** Le campagne di prova, per riferimento. */
function campagne(): array
{
    $trovate = [];

    foreach (righe(DiscountCampaign::class, "code LIKE 'dsc\\_demo-%' AND deleted = 'false'") as $riga) {
        $trovate[substr((string) $riga['code'], strlen('dsc_demo-'))] = $riga;
    }

    return $trovate;
}

/** Quante righe dei ponti restano agganciate a campagne che non esistono più. */
function orfane(): int
{
    $conta = 0;

    foreach ([DiscountCampaignCategory::class, DiscountCampaignTag::class, DiscountCampaignBrand::class, DiscountCampaignProductModel::class] as $ponte) {
        $conta += count(righe($ponte, 'discount_campaign_id NOT IN (SELECT id FROM '.DiscountCampaign::$table.')'));
    }

    return $conta;
}

/** Accende il catalogo di prova e le campagne, a transazione aperta. */
function preparati(): void
{
    accendiFunzionalita(['orders', 'discount_campaigns']);
    PromotionsDemo::clear();
    ContactsDemo::create();
    CatalogDemo::create();
}

check('le campagne di prova sono nel registro dei dati di prova', function () {
    DemoData::reset();
    Demo::registerAll();
    $registro = DemoData::all();
    DemoData::reset();

    return array_key_exists(PromotionsDemo::KEY, $registro);
});

check('create fa tre campagne: una in corso, una programmata, una finita', function () {
    return prova(static function (): bool {
        preparati();
        PromotionsDemo::create();

        $oggi = date('Y-m-d H:i:s');
        $stati = array_map(static fn (array $riga): string => Campaigns::status($riga, $oggi), campagne());
        ksort($stati);

        return $stati === ['finita' => 'ended', 'in-corso' => 'running', 'programmata' => 'scheduled'];
    });
});

check('la campagna in corso dà un prezzo sugli articoli della categoria e niente fuori', function () {
    return prova(static function (): bool {
        preparati();
        PromotionsDemo::create();

        $oggi = date('Y-m-d H:i:s');
        $dentro = 0;
        $fuori = 0;

        foreach (righe(Product::class, "deleted = 'false' AND active = 'true'") as $prodotto) {
            $prezzo = Campaigns::forProduct((int) $prodotto['id'], $oggi);
            $prezzo === null ? $fuori++ : $dentro++;
        }

        return $dentro > 0 && $fuori > 0;
    });
});

check('due create di fila non duplicano le campagne', function () {
    return prova(static function (): bool {
        preparati();
        $primo = PromotionsDemo::create();
        $secondo = PromotionsDemo::create();

        return $primo > 0 && $secondo === 0 && count(campagne()) === 3;
    });
});

check('clear toglie le campagne di prova con i loro ponti e non lascia orfani', function () {
    return prova(static function (): bool {
        preparati();
        PromotionsDemo::create();
        $prima = count(campagne());
        PromotionsDemo::clear();

        return $prima === 3 && campagne() === [] && orfane() === 0;
    });
});

check('clear non tocca una campagna vera', function () {
    return prova(static function (): bool {
        preparati();
        $vera = DiscountCampaign::create([
            'code' => 'dsc_vera-'.uniqid(),
            'name' => 'Vera',
            'discount_type' => 'percent',
            'discount_value' => '5.00',
            'applies_to_all' => 'true',
        ]);
        PromotionsDemo::create();
        PromotionsDemo::clear();

        return is_array(DiscountCampaign::find(['id' => (int) $vera->insert_id], 1));
    });
});
