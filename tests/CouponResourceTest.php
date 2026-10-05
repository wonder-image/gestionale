<?php
/** php tests/CouponResourceTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Models\Promotions\Coupon;
use Wonder\Plugin\Gestionale\Resources\Promotions\CouponResource;

/** I nomi dei campi del form, nell'ordine dichiarato. @return list<string> */
$campi = static fn (): array => array_map(
    static fn ($input): string => (string) $input->name,
    CouponResource::formSchema()
);

check('la pagina sta sotto Promozioni, dietro la funzionalità', function () {
    $menu = CouponResource::navigationSchema()->toArray();

    return CouponResource::$feature === 'coupons'
        && CouponResource::$model === Coupon::class
        && CouponResource::$docsPage === 'promozioni/promozioni-coupon'
        && CouponResource::path() === 'app/gestionale/coupon'
        && ($menu['section_key'] ?? '') === 'promozioni';
});

check('il form ha i campi del coupon, quelli del selettore e i clienti riservati', function () use ($campi) {
    $attesi = [
        'code', 'name', 'discount_type', 'discount_value', 'min_order_amount',
        'usage_limit', 'usage_limit_per_customer', 'first_order_only', 'exclude_discounted_products',
        'starts_at', 'ends_at', 'active', 'applies_online', 'applies_office', 'applies_pos',
        'applies_to_all', 'categories', 'tags', 'brands', 'models', 'excluded_models', 'customers', 'note',
    ];

    return array_diff($attesi, $campi()) === [];
});

check('il tipo offre percentuale, importo e spedizione gratuita: il credito no', function () {
    $campo = CouponResource::getInput('discount_type');
    $schema = (new ReflectionProperty($campo, 'schema'))->getValue($campo);
    $opzioni = array_keys($schema['options'] ?? []);

    return $opzioni === ['percent', 'amount', 'free_shipping'] && !in_array('store_credit', $opzioni, true);
});

check('il valore dello sconto si nasconde con la spedizione gratuita', function () {
    $regola = CouponResource::getInput('discount_value')->conditionalAttributes();

    return $regola !== [] && in_array('free_shipping', array_map('strval', array_values($regola)), true);
});

check('i clienti riservati si scelgono in una ricerca a scelta multipla', function () {
    $campo = CouponResource::getInput('customers');
    $schema = (new ReflectionProperty($campo, 'schema'))->getValue($campo);

    return !empty($schema['multiple']);
});

check('le etichette dell\'elenco ci sono tutte', function () {
    return array_diff(['code', 'name', 'discount_value', 'period', 'usage', 'status'], array_keys(CouponResource::labelSchema())) === [];
});

check('con la funzionalità spenta la pagina non c\'è e il menu la nasconde', function () {
    return CouponResource::featureActive() === false
        && (CouponResource::navigationSchema()->toArray()['enabled'] ?? true) === false;
});

check('lo sconto si legge «20 %», «10,00 €» o «Spedizione gratuita»', function () {
    return CouponResource::discountLabel(['discount_type' => 'percent', 'discount_value' => '20.00']) === '20 %'
        && CouponResource::discountLabel(['discount_type' => 'amount', 'discount_value' => '10.00']) === '10,00 €'
        && CouponResource::discountLabel(['discount_type' => 'free_shipping', 'discount_value' => '0.00']) === 'Spedizione gratuita';
});

check('gli utilizzi si leggono «usati / limite», «∞» senza limite', function () {
    return CouponResource::usageLabel(3, ['usage_limit' => '10']) === '3 / 10'
        && CouponResource::usageLabel(3, ['usage_limit' => '0']) === '3 / ∞';
});

check('il coupon si apre in una scheda di lettura, con «Modifica» in testata', function () {
    $pagine = CouponResource::pageSchema()->toArray();
    $azioni = $pagine['actions']['view'] ?? null;
    $pulsanti = is_callable($azioni) ? $azioni(['id' => 7]) : (array) $azioni;

    return !empty($pagine['pages']['view'])
        && str_ends_with((string) ($pagine['views']['show'] ?? ''), 'pages/coupon-show.php')
        && ($pulsanti[0]['label'] ?? '') === 'Modifica'
        && str_contains((string) ($pulsanti[0]['href'] ?? ''), '7');
});

check('l\'elenco porta alla scheda: il codice la apre e «Visualizza» sta tra le azioni', function () {
    $colonne = [];

    foreach (CouponResource::tableSchema() as $colonna) {
        $colonne[(string) $colonna->name] = $colonna;
    }

    $codice = (new ReflectionProperty($colonne['code'], 'schema'))->getValue($colonne['code']);
    $azioni = (new ReflectionProperty($colonne['actions'], 'schema'))->getValue($colonne['actions']);

    return str_contains(json_encode($codice), 'view') && array_key_exists('view', (array) ($azioni['actions'] ?? []));
});

check('la scheda ha dettagli, regole, prodotti e clienti, utilizzi e, di lato, dove vale', function () {
    $layout = CouponResource::showLayoutSchema(['id' => 0, 'code' => 'ESTATE10', 'discount_type' => 'percent', 'discount_value' => '10.00', 'note' => 'Per la newsletter']);
    $titoli = [];
    $scendi = function (array $componenti) use (&$scendi, &$titoli): void {
        foreach ($componenti as $c) {
            if ($c instanceof Wonder\Elements\Components\Container) {
                $scendi($c->components);
            } elseif (($c->components[0] ?? null) instanceof Wonder\Elements\Components\SectionTitle) {
                $titoli[] = (string) (new ReflectionProperty($c->components[0], 'text'))->getValue($c->components[0]);
            }
        }
    };
    $scendi($layout->components);

    return $titoli === ['Coupon', 'Regole', 'Prodotti e clienti', 'Utilizzi', 'Dove vale', 'Note'];
});

summary();
