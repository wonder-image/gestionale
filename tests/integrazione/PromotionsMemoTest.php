<?php
/** php tests/integrazione/PromotionsMemoTest.php */
declare(strict_types=1);

define('SITE', getenv('WI_TEST_SITE') ?: '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site');

chdir(SITE);
$GLOBALS['ROOT'] = SITE;
require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__ . '/../harness.php';

use Wonder\Plugin\Gestionale\Models\Catalog\Category;
use Wonder\Plugin\Gestionale\Models\Promotions\DiscountCampaign;
use Wonder\Plugin\Gestionale\Models\Promotions\DiscountCampaignCategory;
use Wonder\Plugin\Gestionale\Models\Tax\TaxRule;
use Wonder\Plugin\Gestionale\Resources\Tax\TaxRuleResource;
use Wonder\Plugin\Gestionale\Support\Catalog\Code;
use Wonder\Plugin\Gestionale\Support\Catalog\Slug;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Plugin\Gestionale\Support\Promotions\Campaigns;
use Wonder\Plugin\Gestionale\Support\Promotions\ProductScope;
use Wonder\Plugin\Gestionale\Support\RequestMemo;
use Wonder\Sql\Transaction;

const ORA = '2026-10-15 12:00:00';

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
    }

    return $esito;
}

function campagna(): int
{
    $riga = DiscountCampaign::create([
        'code' => Code::make(DiscountCampaign::class, Codes::DISCOUNT_CAMPAIGN),
        'name' => 'Memo '.uniqid(),
        'discount_type' => 'percent',
        'discount_value' => '10.00',
        'starts_at' => '2026-10-01 00:00:00',
        'ends_at' => '2026-10-31 23:59:59',
        'active' => 'true',
        'applies_to_all' => 'true',
        'applies_online' => 'true',
    ]);

    return (int) ($riga->insert_id ?? $riga->id ?? 0);
}

function categoria(): int
{
    $nome = 'Memo '.uniqid();
    $riga = Category::create([
        'code' => Code::make(Category::class, Codes::CATEGORY),
        'name' => $nome,
        'slug' => Slug::unique($nome, Category::class),
        'position' => 900,
        'visible' => 'true',
    ]);

    return (int) ($riga->insert_id ?? $riga->id ?? 0);
}

function ids(array $righe): array
{
    return array_map(static fn (array $r): int => (int) $r['id'], $righe);
}

check('Campaigns::running: una campagna creata col Model si vede subito, e sparisce col rollback', function () {
    RequestMemo::reset();
    $prima = ids(Campaigns::running(ORA));

    $dentro = prova(static function () use ($prima): bool {
        $id = campagna();

        return $id > 0 && in_array($id, ids(Campaigns::running(ORA)), true) && !in_array($id, $prima, true);
    });

    return $dentro === true && ids(Campaigns::running(ORA)) === $prima;
});

check('Campaigns::running: dentro una transazione nessuna copia, anche una scrittura diretta si vede', function () {
    RequestMemo::reset();

    return prova(static function (): bool {
        $id = campagna();
        $prima = in_array($id, ids(Campaigns::running(ORA)), true);
        sqlModify(DiscountCampaign::$table, ['active' => 'false'], 'id', (string) $id);

        return $prima && !in_array($id, ids(Campaigns::running(ORA)), true);
    }) === true;
});

check('ProductScope::of: un ponte aggiunto col Model entra nel selettore', function () {
    RequestMemo::reset();

    return prova(static function (): bool {
        $campagna = campagna();
        $categoria = categoria();
        $vuoto = ProductScope::of('campaign', $campagna)['categories'];
        DiscountCampaignCategory::create(['discount_campaign_id' => $campagna, 'category_id' => $categoria]);

        return $vuoto === [] && in_array($categoria, ProductScope::of('campaign', $campagna)['categories'], true);
    }) === true;
});

check('TaxRule::active: TaxRuleResource::afterUpdate butta la copia', function () {
    RequestMemo::reset();
    $regole = TaxRule::active();

    if ($regole === []) {
        return true;
    }

    $id = (int) $regole[0]['id'];
    $attiva = (string) ($regole[0]['active'] ?? 'true');
    $altro = $attiva === 'true' ? 'false' : 'true';

    $dentro = prova(static function () use ($id, $attiva, $altro): bool {
        sqlModify(TaxRule::$table, ['active' => $altro], 'id', (string) $id);
        $copia = (string) (TaxRule::active()[0]['active'] ?? '');
        TaxRuleResource::afterUpdate($id, (object) ['success' => true], []);

        return $copia === $attiva && (string) (TaxRule::active()[0]['active'] ?? '') === $altro;
    });

    return $dentro === true && (string) (TaxRule::active()[0]['active'] ?? '') === $attiva;
});

summary();
