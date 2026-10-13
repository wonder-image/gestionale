<?php
/** php tests/integrazione/PlansTest.php */

define('SITE', getenv('WI_TEST_SITE') ?: '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site');

chdir(SITE);
$GLOBALS['ROOT'] = SITE;

require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__.'/../harness.php';
require __DIR__.'/supporto/compra.php';

use Wonder\App\ResourceSchema\RepeaterRelation;
use Wonder\App\Support\Repeater;
use Wonder\Plugin\Gestionale\Models\Contacts\Contact;
use Wonder\Plugin\Gestionale\Models\Promotions\Coupon;
use Wonder\Plugin\Gestionale\Models\Subscriptions\Plan;
use Wonder\Plugin\Gestionale\Models\Subscriptions\PlanFeature;
use Wonder\Plugin\Gestionale\Models\Subscriptions\PlanPrice;
use Wonder\Plugin\Gestionale\Models\Subscriptions\Subscription;
use Wonder\Plugin\Gestionale\Resources\Subscriptions\PlanResource;
use Wonder\Plugin\Gestionale\Seeding\DemoCode;
use Wonder\Plugin\Gestionale\Seeding\PlansDemo;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Features\FeatureGuards;
use Wonder\Plugin\Gestionale\Support\Promotions\Coupons;
use Wonder\Plugin\Gestionale\Support\Subscriptions\SubscriptionAmounts;
use Wonder\Plugin\Gestionale\Support\Subscriptions\Subscriptions;
use Wonder\Sql\Transaction;

final class Annulla extends RuntimeException {}

function prova(callable $corpo): mixed
{
    $esito = null;

    try {
        Transaction::run(static function () use ($corpo, &$esito): void {
            accendiFunzionalita(['subscriptions']);
            $esito = $corpo();

            throw new Annulla();
        });
    } catch (Annulla) {
    } finally {
        $_POST = [];
        Wonder\Plugin\Gestionale\Gestionale::reset();
    }

    return $esito;
}

/** Un piano con i suoi prezzi e limiti, salvati come li salva il repeater della pagina. Ridà l'id. */
function piano(string $nome, array $prezzi, array $limiti = []): int
{
    $id = (int) (Plan::create(['name' => $nome, 'slug' => strtolower($nome).'-'.uniqid(), 'active' => 'true', 'visible_online' => 'true'])->insert_id ?? 0);

    Repeater::syncRelatedRows(
        RepeaterRelation::make(PlanPrice::$table, 'plan_id')->model(PlanPrice::class)->positionKey('position'),
        $id,
        PlanResource::prepareRepeaterRows('prices', $prezzi)
    );

    if ($limiti !== []) {
        Repeater::syncRelatedRows(
            RepeaterRelation::make(PlanFeature::$table, 'plan_id')->model(PlanFeature::class)->positionKey('position')->softDelete(false),
            $id,
            PlanResource::prepareRepeaterRows('features', $limiti)
        );
    }

    return $id;
}

/** @return list<array<string, mixed>> */
function prezziDi(int $piano): array
{
    $rows = PlanPrice::find(['plan_id' => $piano, 'deleted' => 'false'], null, 'position', 'ASC');

    return is_array($rows) ? (isset($rows['id']) ? [$rows] : array_values($rows)) : [];
}

function cliente(): int
{
    return (int) (Contact::create([
        'type' => 'private', 'name' => 'Sara', 'surname' => 'Abbonata', 'country' => 'IT',
        'email' => 'sara.piani.'.uniqid().'@example.com', 'is_customer' => 'true', 'active' => 'true',
    ])->insert_id ?? 0);
}

function abbonamento(int $cliente, int $prezzo, string $stato): int
{
    return (int) (Subscription::create([
        'customer_id' => $cliente, 'plan_price_id' => $prezzo, 'channel' => 'office', 'provider' => 'manual',
        'status' => $stato, 'total' => '19.00', 'started_at' => date('Y-m-d H:i:s'),
    ])->insert_id ?? 0);
}

/** Il salvataggio della pagina fino ai controlli: ridà la chiave del rifiuto, o null. */
function rifiutoPagina(array $post, ?int $id = null): ?string
{
    $_POST = $post;

    try {
        PlanResource::mutateRequestValues($post, $id === null ? 'store' : 'update', 'backend', $id === null ? null : Plan::findById($id));
    } catch (UserError $e) {
        return $e->key();
    } finally {
        $_POST = [];
    }

    return null;
}

function rifiuto(callable $f): ?string
{
    try {
        $f();
    } catch (UserError $e) {
        return $e->key();
    } catch (RuntimeException $e) {
        return 'refusal:'.$e->getMessage();
    }

    return null;
}

$mensile = ['name' => 'Mensile', 'billing_interval' => 'month', 'term_months' => '1', 'price' => '19,00', 'sale_price' => '', 'trial_days' => '14', 'active' => 'true'];
$annuale = ['name' => 'Annuale', 'billing_interval' => 'year', 'term_months' => '', 'price' => '190,00', 'sale_price' => '150', 'active' => 'true'];

check('un piano con due prezzi e due limiti: codici, numeri e posizioni', function () use ($mensile, $annuale) {
    return prova(static function () use ($mensile, $annuale): bool {
        $id = piano('Pro', [$mensile, $annuale], [['feature_key' => 'max_progetti', 'feature_value' => '20'], ['feature_key' => 'supporto', 'feature_value' => 'prioritario']]);
        $prezzi = prezziDi($id);
        $piano = Plan::findById($id);

        return str_starts_with((string) $piano['code'], 'pln_')
            && count($prezzi) === 2
            && str_starts_with((string) $prezzi[0]['code'], 'ppr_') && $prezzi[0]['code'] !== $prezzi[1]['code']
            && round((float) $prezzi[0]['price'], 2) === 19.0 && (int) $prezzi[0]['trial_days'] === 14
            && (string) $prezzi[1]['billing_interval'] === 'year' && (int) $prezzi[1]['term_months'] === 12
            && round((float) $prezzi[1]['sale_price'], 2) === 150.0
            && PlanResource::pricesSummary($id) === 'Mensile 19,00 € · Annuale 150,00 €';
    });
});

check('la pagina rifiuta un piano senza nome, senza prezzi o con un prezzo storto', function () use ($mensile) {
    return prova(static function () use ($mensile): bool {
        return rifiutoPagina(['name' => '', 'prices' => [$mensile]]) === 'plan.name_required'
            && rifiutoPagina(['name' => 'Vuoto', 'prices' => []]) === 'plan.no_prices'
            && rifiutoPagina(['name' => 'Storto', 'prices' => [['sale_price' => '30'] + $mensile]]) === 'plan.sale_not_lower'
            && rifiutoPagina(['name' => 'Storto', 'prices' => [['billing_interval' => 'year', 'term_months' => '6'] + $mensile]]) === 'plan.term_mismatch'
            && rifiutoPagina(['name' => 'Doppio', 'prices' => [$mensile], 'features' => [['feature_key' => 'max', 'feature_value' => '1'], ['feature_key' => 'MAX', 'feature_value' => '2']]]) === 'plan.feature_duplicate'
            && rifiutoPagina(['name' => 'Buono', 'prices' => [$mensile]]) === null;
    });
});

check('il rinnovo deve essere un prezzo già salvato di un piano attivo', function () use ($mensile, $annuale) {
    return prova(static function () use ($mensile, $annuale): bool {
        $acceso = piano('Acceso', [$annuale]);
        $spento = piano('Spento', [$annuale]);
        Plan::update(['active' => 'false'], $spento);
        $buono = (int) prezziDi($acceso)[0]['id'];
        $cattivo = (int) prezziDi($spento)[0]['id'];

        return rifiutoPagina(['name' => 'Promo', 'prices' => [['renewal_plan_price_id' => (string) $buono] + $mensile]]) === null
            && rifiutoPagina(['name' => 'Promo', 'prices' => [['renewal_plan_price_id' => (string) $cattivo] + $mensile]]) === 'plan.renewal_invalid';
    });
});

check('un prezzo usato da un abbonamento, anche chiuso, non esce dal repeater; spento sì', function () use ($mensile, $annuale) {
    return prova(static function () use ($mensile, $annuale): bool {
        $id = piano('Usato', [$mensile, $annuale]);
        [$primo, $secondo] = prezziDi($id);
        abbonamento(cliente(), (int) $primo['id'], 'cancelled');

        $senzaPrimo = rifiutoPagina(['name' => 'Usato', 'prices' => [['id' => (string) $secondo['id']] + $annuale]], $id);
        $senzaSecondo = rifiutoPagina(['name' => 'Usato', 'prices' => [['id' => (string) $primo['id'], 'active' => 'false'] + $mensile]], $id);

        return $senzaPrimo === 'plan.price_in_use' && $senzaSecondo === null;
    });
});

check('un piano con abbonamenti vivi non si elimina; con soli chiusi sì', function () use ($mensile) {
    return prova(static function () use ($mensile): bool {
        $vivo = piano('Vivo', [$mensile]);
        $chiuso = piano('Chiuso', [$mensile]);
        $persona = cliente();
        abbonamento($persona, (int) prezziDi($vivo)[0]['id'], 'past_due');
        abbonamento($persona, (int) prezziDi($chiuso)[0]['id'], 'unpaid');

        return str_starts_with((string) rifiuto(static fn () => PlanResource::assertDeletable($vivo)), 'refusal:')
            && rifiuto(static fn () => PlanResource::assertDeletable($chiuso)) === null;
    });
});

check('limit(): dal piano dell\'abbonamento vivo; null senza, o con uno chiuso', function () use ($mensile) {
    return prova(static function () use ($mensile): bool {
        $id = piano('Limiti', [$mensile], [['feature_key' => 'max_progetti', 'feature_value' => '5']]);
        $prezzo = (int) prezziDi($id)[0]['id'];
        $vivo = cliente();
        $chiuso = cliente();
        $nessuno = cliente();
        abbonamento($vivo, $prezzo, 'trialing');
        abbonamento($chiuso, $prezzo, 'cancelled');

        return Subscriptions::limit($vivo, 'max_progetti') === '5'
            && Subscriptions::limit($vivo, 'inventato') === null
            && Subscriptions::limit($chiuso, 'max_progetti') === null
            && Subscriptions::limit($nessuno, 'max_progetti') === null
            && Subscriptions::live($vivo) !== null;
    });
});

check('«Abbonamenti» non si spegne con abbonamenti in corso', function () use ($mensile) {
    return prova(static function () use ($mensile): bool {
        abbonamento(cliente(), (int) prezziDi(piano('Guardia', [$mensile]))[0]['id'], 'active');

        return Subscriptions::liveCount() >= 1
            && rifiuto(static fn () => FeatureGuards::assertAllowed(['subscriptions' => true], ['subscriptions' => false])) === 'feature.subscriptions_in_use'
            && rifiuto(static fn () => FeatureGuards::assertAllowed(['subscriptions' => true], ['subscriptions' => true])) === null;
    });
});

check('coupon degli abbonamenti: niente spedizione gratuita, «per N mesi» vuole i mesi', function () {
    return prova(static function (): bool {
        $base = ['code' => 'ABB'.strtoupper(substr(uniqid(), -7)), 'discount_type' => 'percent', 'discount_value' => '20', 'applies_subscriptions' => 'true'];

        return rifiuto(static fn () => Coupons::validate(['discount_type' => 'free_shipping', 'discount_value' => '0'] + $base)) === 'coupon.subscriptions_free_shipping'
            && rifiuto(static fn () => Coupons::validate(['duration' => 'repeating', 'duration_months' => 0] + $base)) === 'coupon.duration_months_required'
            && rifiuto(static fn () => Coupons::validate(['duration' => 'repeating', 'duration_months' => 3] + $base)) === null
            && rifiuto(static fn () => Coupons::validate(['discount_type' => 'free_shipping', 'discount_value' => '0', 'applies_subscriptions' => 'false'] + $base)) === null;
    });
});

check('importi di un periodo con un coupon vero, riletto dal database', function () {
    return prova(static function (): bool {
        $id = (int) (Coupon::create([
            'code' => 'ABB'.strtoupper(substr(uniqid(), -7)), 'discount_type' => 'percent', 'discount_value' => '20',
            'applies_subscriptions' => 'true', 'duration' => 'repeating', 'duration_months' => 3, 'active' => 'true',
        ])->insert_id ?? 0);
        $coupon = Coupon::findById($id);
        $prezzo = ['price' => '24.40', 'sale_price' => '0.00', 'term_months' => 1];
        $terzo = SubscriptionAmounts::of($prezzo, $coupon, 22.0, true, 2);
        $quarto = SubscriptionAmounts::of($prezzo, $coupon, 22.0, true, 3);

        return $terzo['total'] === '19.52' && $terzo['discount_total'] === '4.88'
            && $quarto['total'] === '24.40' && $quarto['coupon_applied'] === false;
    });
});

check('i piani di prova: due piani, quattro prezzi, quattro limiti; il secondo giro non rifà niente', function () {
    return prova(static function (): bool {
        Wonder\Plugin\Gestionale\Seeding\OfficeSubscriptionsDemo::clear();
        PlansDemo::clear();
        $creati = PlansDemo::create();
        $secondo = PlansDemo::create();
        $pro = Plan::find(['code' => DemoCode::forModel(Plan::class, 'pro'), 'deleted' => 'false'], 1);
        $prezzi = is_array($pro) ? prezziDi((int) $pro['id']) : [];
        $tolti = PlansDemo::clear();
        $sparito = Plan::find(['code' => DemoCode::forModel(Plan::class, 'pro')], 1);

        return $creati === 10 && $secondo === 0
            && count($prezzi) === 2 && (int) $prezzi[0]['trial_days'] === 14 && (int) $prezzi[1]['commitment_months'] === 12
            && $tolti === 10
            && (!is_array($sparito) || $sparito === []);
    });
});

summary();
