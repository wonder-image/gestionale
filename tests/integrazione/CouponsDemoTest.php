<?php
/** php tests/integrazione/CouponsDemoTest.php */
declare(strict_types=1);

define('SITE', getenv('WI_TEST_SITE') ?: '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site');

chdir(SITE);
$GLOBALS['ROOT'] = SITE;

require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__.'/../harness.php';
require __DIR__.'/supporto/compra.php';

use Wonder\Plugin\Gestionale\Models\Catalog\Category;
use Wonder\Plugin\Gestionale\Models\Contacts\Contact;
use Wonder\Plugin\Gestionale\Models\Promotions\Coupon;
use Wonder\Plugin\Gestionale\Models\Promotions\CouponCategory;
use Wonder\Plugin\Gestionale\Models\Promotions\CouponCustomer;
use Wonder\Plugin\Gestionale\Models\Promotions\CouponRedemption;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Seeding\CatalogDemo;
use Wonder\Plugin\Gestionale\Seeding\ContactsDemo;
use Wonder\Plugin\Gestionale\Seeding\DemoCode;
use Wonder\Plugin\Gestionale\Seeding\OrdersDemo;
use Wonder\Plugin\Gestionale\Seeding\PromotionsDemo;
use Wonder\Plugin\Gestionale\Support\Catalog\ProductImages;
use Wonder\Sql\Transaction;

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

/** Le righe di un Model che rispondono alla condizione, in elenco. */
function righe(string $model, string|array $dove): array
{
    $rows = $model::find($dove);

    if (!is_array($rows) || $rows === []) {
        return [];
    }

    return isset($rows['id']) ? [$rows] : array_values(array_filter($rows, 'is_array'));
}

/** I coupon di prova, per codice. */
function coupon(): array
{
    $trovati = [];

    foreach (righe(Coupon::class, "code LIKE 'DEMO-%' AND deleted = 'false'") as $riga) {
        $trovati[(string) $riga['code']] = $riga;
    }

    return $trovati;
}

/** Gli ordini di prova con un coupon, per riferimento. */
function ordiniConCoupon(): array
{
    $trovati = [];

    foreach (righe(Order::class, "code LIKE 'ord\\_demo-coupon%' AND deleted = 'false'") as $riga) {
        $trovati[substr((string) $riga['code'], strlen('ord_demo-'))] = $riga;
    }

    return $trovati;
}

/** Le righe dei coupon (utilizzi, clienti, ambiti) rimaste attaccate a un coupon che non c'è più. */
function orfane(): int
{
    $conta = 0;

    foreach ([CouponRedemption::class, CouponCustomer::class, CouponCategory::class] as $modello) {
        $conta += count(righe($modello, 'coupon_id NOT IN (SELECT id FROM '.Coupon::$table.')'));
    }

    return $conta;
}

/** Il catalogo e i clienti di prova, a transazione aperta, con le vendite e i coupon accesi. */
function preparati(): void
{
    accendiFunzionalita(['orders', 'coupons', 'discount_campaigns']);
    spegniFunzionalita(['bundles']);
    OrdersDemo::clear();
    PromotionsDemo::clear();
    ContactsDemo::create();
    CatalogDemo::create();
}

// `CatalogDemo` scrive foto sul disco e la transazione non le rimette: l'istantanea sì.
$foto = Istantanea::di(rtrim((string) ($GLOBALS['ROOT'] ?? ''), '/').'/assets/upload'.ProductImages::folder());

try {
    check('create fa cinque coupon di prova: percentuale, importo, spedizione gratuita, riservato, primo ordine', function () {
        return prova(static function (): bool {
            preparati();
            PromotionsDemo::create();
            $c = coupon();

            return count($c) === 5
                && ($c['DEMO-PERCENTUALE10']['discount_type'] ?? '') === 'percent'
                && ($c['DEMO-IMPORTO5']['discount_type'] ?? '') === 'amount'
                && ($c['DEMO-SPEDIZIONE']['discount_type'] ?? '') === 'free_shipping'
                && ($c['DEMO-ANNA15']['discount_type'] ?? '') === 'percent'
                && ($c['DEMO-PRIMO']['first_order_only'] ?? '') === 'true';
        });
    });

    check('il coupon riservato ha il suo cliente di prova, gli altri sono di tutti', function () {
        return prova(static function (): bool {
            preparati();
            PromotionsDemo::create();
            $c = coupon();
            $verdi = Contact::find(['code' => DemoCode::forModel(Contact::class, 'verdi'), 'deleted' => 'false'], 1);
            $ponti = righe(CouponCustomer::class, 'coupon_id = '.(int) $c['DEMO-ANNA15']['id']);

            return is_array($verdi) && count($ponti) === 1 && (int) $ponti[0]['customer_id'] === (int) $verdi['id']
                && righe(CouponCustomer::class, 'coupon_id = '.(int) $c['DEMO-PERCENTUALE10']['id']) === [];
        });
    });

    check('due create di fila non duplicano i coupon', function () {
        return prova(static function (): bool {
            preparati();
            $primo = PromotionsDemo::create();
            $secondo = PromotionsDemo::create();

            return $primo > 0 && $secondo === 0 && count(coupon()) === 5;
        });
    });

    check('gli ordini di prova usano i coupon: pagato, riservato, annullato con l\'utilizzo rilasciato', function () {
        return prova(static function (): bool {
            preparati();
            PromotionsDemo::create();
            OrdersDemo::create();
            $o = ordiniConCoupon();

            if (array_keys($o) === [] || count($o) !== 3) {
                return false;
            }

            $uso = static fn (string $ref): array => righe(CouponRedemption::class, 'order_id = '.(int) $o[$ref]['id'])[0] ?? [];
            $liberato = static fn (array $r): bool => trim((string) ($r['released_at'] ?? '')) !== '' && !str_starts_with((string) $r['released_at'], '0000-00-00');

            return ($o['coupon-pagato']['coupon_code'] ?? '') === 'DEMO-PERCENTUALE10'
                && ($o['coupon-riservato']['coupon_code'] ?? '') === 'DEMO-ANNA15'
                && ($o['coupon-annullato']['coupon_code'] ?? '') === 'DEMO-PERCENTUALE10'
                && (float) ($uso('coupon-pagato')['discount_amount'] ?? 0) > 0
                && !$liberato($uso('coupon-pagato')) && !$liberato($uso('coupon-riservato'))
                && $liberato($uso('coupon-annullato'))
                && ($o['coupon-annullato']['status'] ?? '') === 'cancelled';
        });
    });

    check('con i coupon spenti gli ordini con coupon non nascono', function () {
        return prova(static function (): bool {
            preparati();
            PromotionsDemo::create();
            spegniFunzionalita(['coupons']);
            OrdersDemo::create();

            return ordiniConCoupon() === [];
        });
    });

    check('due create degli ordini non duplicano quelli con coupon', function () {
        return prova(static function (): bool {
            preparati();
            PromotionsDemo::create();
            OrdersDemo::create();

            return OrdersDemo::create() === 0 && count(ordiniConCoupon()) === 3;
        });
    });

    check('clear toglie coupon, clienti e utilizzi di prova senza lasciare orfani', function () {
        return prova(static function (): bool {
            preparati();
            PromotionsDemo::create();
            OrdersDemo::create();

            // Come il comando: prima gli ordini, poi le campagne e i coupon.
            OrdersDemo::clear();
            PromotionsDemo::clear();

            return coupon() === [] && ordiniConCoupon() === [] && orfane() === 0
                && righe(CouponRedemption::class, "coupon_id NOT IN (SELECT id FROM ".Coupon::$table.")") === [];
        });
    });

    check('clear dei coupon da soli porta via anche gli utilizzi, senza violare le chiavi', function () {
        return prova(static function (): bool {
            preparati();
            PromotionsDemo::create();
            OrdersDemo::create();
            PromotionsDemo::clear();

            return coupon() === [] && orfane() === 0;
        });
    });

    check('clear non tocca un coupon vero, nemmeno uno che inizia per DEMO-', function () {
        return prova(static function (): bool {
            preparati();
            $vero = Coupon::create([
                'code' => 'DEMO-VERO', 'name' => 'Vero', 'discount_type' => 'percent', 'discount_value' => '5.00',
                'applies_to_all' => 'true', 'applies_online' => 'true', 'active' => 'true', 'note' => 'Lo ha fatto il negozio.',
            ]);
            PromotionsDemo::create();
            PromotionsDemo::clear();

            return is_array(Coupon::find(['id' => (int) $vero->insert_id], 1)) && count(coupon()) === 1;
        });
    });
} finally {
    Wonder\Plugin\Gestionale\Gestionale::reset();
    $foto->ripristina();
}

summary();
