<?php
/** php tests/integrazione/ShippingBackendTest.php */

const SITE = '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site';

chdir(SITE);
$GLOBALS['ROOT'] = SITE;

require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__.'/../harness.php';
require __DIR__.'/supporto/compra.php';
require __DIR__.'/supporto/spedizioni.php';
require __DIR__.'/supporto/layout.php';

use Wonder\Plugin\Gestionale\Models\Shipping\Carrier;
use Wonder\Plugin\Gestionale\Models\Shipping\ShippingMethod;
use Wonder\Plugin\Gestionale\Models\Shipping\ShippingRate;
use Wonder\Plugin\Gestionale\Models\Shipping\ShippingRateBracket;
use Wonder\Plugin\Gestionale\Models\Shipping\ShippingZone;
use Wonder\Plugin\Gestionale\Models\Shipping\ShippingZoneArea;
use Wonder\Plugin\Gestionale\Resources\Shipping\CarrierResource;
use Wonder\Plugin\Gestionale\Resources\Shipping\ShippingMethodResource;
use Wonder\Plugin\Gestionale\Resources\Shipping\ShippingZoneResource;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Shipping\RateForm;
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

/** Le righe di un modello, come stanno scritte adesso (anche nel cestino). */
function tutte(string $model, array $where): array
{
    $trovate = $model::find($where);

    if (!is_array($trovate) || $trovate === []) {
        return [];
    }

    return array_key_exists('id', $trovate) ? [$trovate] : array_values(array_filter($trovate, 'is_array'));
}

/** Un listino com'è nella richiesta del form, per una zona. */
function richiesta(int $zona, array $campi = [], ?array $scaglioni = null): array
{
    $post = ['rate_'.$zona.'_on' => 'true'];

    foreach ($campi as $nome => $valore) {
        $post['rate_'.$zona.'_'.$nome] = $valore;
    }

    $post['rate_'.$zona.'_brackets'] = $scaglioni ?? [
        ['type' => 'price', 'max_weight' => '20', 'amount' => '15'],
        ['type' => 'price', 'max_weight' => '5', 'amount' => '8,50'],
        ['type' => 'excess', 'max_weight' => '', 'amount' => '1,2'],
    ];

    return $post;
}

check('saveRates scrive il listino e gli scaglioni, loadRates li rimette nei campi', fn () => prova(static function (): bool {
    $zona = zona('Italia', [['IT', '']]);
    $metodo = metodo('Standard');

    RateForm::saveRates($metodo, RateForm::readRates(richiesta($zona, [
        'markup_percent' => '10', 'cod_fee' => '3,50', 'free_over_amount' => '100', 'volumetric_divisor' => '5000',
    ])));

    $valori = RateForm::loadRates($metodo);
    $scaglioni = $valori['rate_'.$zona.'_brackets'] ?? [];

    return ($valori['rate_'.$zona.'_on'] ?? '') === 'true'
        && (float) $valori['rate_'.$zona.'_markup_percent'] === 10.0
        && (float) $valori['rate_'.$zona.'_cod_fee'] === 3.5
        && (float) $valori['rate_'.$zona.'_free_over_amount'] === 100.0
        && (int) $valori['rate_'.$zona.'_volumetric_divisor'] === 5000
        && $valori['rate_'.$zona.'_rounding_step'] === null
        && count($scaglioni) === 3
        && [(float) $scaglioni[0]['max_weight'], (float) $scaglioni[0]['amount']] === [5.0, 8.5]
        && [(float) $scaglioni[1]['max_weight'], (float) $scaglioni[1]['amount']] === [20.0, 15.0]
        && $scaglioni[2]['type'] === 'excess' && (float) $scaglioni[2]['amount'] === 1.2;
}));

check('il prezzo fisso si salva e si rilegge, e i campi a scaglioni restano per quando si torna indietro', fn () => prova(static function (): bool {
    $zona = zona('Italia', [['IT', '']]);
    $metodo = metodo('Standard');

    RateForm::saveRates($metodo, RateForm::readRates(richiesta($zona, ['markup_percent' => '10'])));
    RateForm::saveRates($metodo, RateForm::readRates(richiesta($zona, ['price_type' => 'fixed', 'fixed_price' => '6,90', 'markup_percent' => '10'])));
    $fisso = RateForm::loadRates($metodo);

    RateForm::saveRates($metodo, RateForm::readRates(richiesta($zona, ['price_type' => 'brackets', 'markup_percent' => '10'])));
    $indietro = RateForm::loadRates($metodo);

    return $fisso['rate_'.$zona.'_price_type'] === 'fixed'
        && (float) $fisso['rate_'.$zona.'_fixed_price'] === 6.9
        && (float) $fisso['rate_'.$zona.'_markup_percent'] === 10.0
        && $indietro['rate_'.$zona.'_price_type'] === 'brackets'
        && count($indietro['rate_'.$zona.'_brackets']) === 3;
}));

check('riscrivendo il listino si aggiorna lo stesso record e le caselle vuote svuotano le colonne', fn () => prova(static function (): bool {
    $zona = zona('Italia', [['IT', '']]);
    $metodo = metodo('Standard');

    RateForm::saveRates($metodo, RateForm::readRates(richiesta($zona, ['free_over_amount' => '100', 'rounding_step' => '0,5'])));
    $primo = tutte(ShippingRate::class, ['shipping_method_id' => $metodo]);

    RateForm::saveRates($metodo, RateForm::readRates(richiesta($zona, [], [['type' => 'price', 'max_weight' => '3', 'amount' => '7']])));
    $dopo = tutte(ShippingRate::class, ['shipping_method_id' => $metodo]);
    $valori = RateForm::loadRates($metodo);

    return count($primo) === 1 && count($dopo) === 1
        && (int) $dopo[0]['id'] === (int) $primo[0]['id']
        && $dopo[0]['free_over_amount'] === null
        && $dopo[0]['rounding_step'] === null
        && count($valori['rate_'.$zona.'_brackets']) === 1
        && count(tutte(ShippingRateBracket::class, ['shipping_rate_id' => (int) $dopo[0]['id']])) === 1;
}));

check('un listino tolto dal form resta salvato, spento, e si riaccende con i suoi valori', fn () => prova(static function (): bool {
    $zona = zona('Italia', [['IT', '']]);
    $isole = zona('Isole', [['IT', 'CA']]);
    $metodo = metodo('Standard');

    RateForm::saveRates($metodo, RateForm::readRates(richiesta($zona) + richiesta($isole, ['markup_percent' => '7'])));
    RateForm::saveRates($metodo, RateForm::readRates(richiesta($zona)));

    $spento = RateForm::loadRates($metodo);
    $righe = tutte(ShippingRate::class, ['shipping_method_id' => $metodo]);

    RateForm::saveRates($metodo, RateForm::readRates(richiesta($zona) + richiesta($isole, ['markup_percent' => '7'])));
    $acceso = RateForm::loadRates($metodo);

    return count($righe) === 2
        && $spento['rate_'.$isole.'_on'] === 'false'
        && $spento['rate_'.$zona.'_on'] === 'true'
        && count($spento['rate_'.$isole.'_brackets']) === 3
        && $acceso['rate_'.$isole.'_on'] === 'true'
        && count(tutte(ShippingRate::class, ['shipping_method_id' => $metodo])) === 2;
}));

check('una zona che non esiste più si ignora', fn () => prova(static function (): bool {
    $zona = zona('Italia', [['IT', '']]);
    $metodo = metodo('Standard');

    RateForm::saveRates($metodo, RateForm::readRates(richiesta($zona) + richiesta(999999)));

    return array_keys(array_filter(RateForm::loadRates($metodo), static fn ($v, $k) => str_ends_with((string) $k, '_on'), ARRAY_FILTER_USE_BOTH))
        === ['rate_'.$zona.'_on'];
}));

check('il form del metodo ha un riquadro di campi per ogni zona', fn () => prova(static function (): bool {
    $zona = zona('Italia', [['IT', '']]);
    $nomi = array_map(static fn ($i): string => (string) $i->name, ShippingMethodResource::formSchema());

    return in_array('rate_'.$zona.'_on', $nomi, true)
        && in_array('rate_'.$zona.'_brackets', $nomi, true)
        && in_array('rate_'.$zona.'_cod_fee', $nomi, true)
        && ShippingMethodResource::formLayoutSchema() !== null;
}));

check('la scheda del metodo ha «Aggiungi zona» e un riquadro per zona, con «Nuova zona…» in fondo', fn () => prova(static function (): bool {
    $zona = zona('Italia', [['IT', '']]);
    $html = layoutHtml(ShippingMethodResource::formLayoutSchema());

    return str_contains($html, 'data-wi-zone="'.$zona.'"')
        && str_contains($html, 'data-wi-zone-add="'.$zona.'"')
        && str_contains($html, 'data-wi-zone-remove="'.$zona.'"')
        && str_contains($html, 'Aggiungi zona')
        && str_contains($html, 'Nuova zona…')
        // Non c'è più l'interruttore: la zona si accende dal menu.
        && !str_contains($html, 'Spedisce verso questa zona')
        && preg_match('/<input[^>]*type="hidden"[^>]*name="rate_'.$zona.'_on"/', $html) === 1;
}));

check('le zone stanno tutte in un\'unica card «Zone», con «+ Aggiungi zona» dentro', fn () => prova(static function (): bool {
    $a = zona('Italia', [['IT', '']]);
    $b = zona('Isole', [['IT', 'CA']]);
    $html = layoutHtml(ShippingMethodResource::formLayoutSchema());
    $titolo = strpos($html, '>Zone<');
    $primo = strpos($html, 'data-wi-zone="'.$a.'"');
    $secondo = strpos($html, 'data-wi-zone="'.$b.'"');
    $menu = strpos($html, 'wi-zone-choose');
    $bottone = strpos($html, 'id="'.ShippingMethodResource::ZONE_BUTTON.'"');
    $dentro = ($titolo !== false && $menu !== false) ? substr($html, $titolo, $menu - $titolo) : '';

    return $titolo !== false && $primo !== false && $secondo !== false && $menu !== false
        && $titolo < $primo && $primo < $secondo && $secondo < $menu
        // Il bottone vero della finestra sta nella card, prima del menu, ed è nascosto dallo script.
        && ($bottone === false || $bottone < $menu)
        && str_contains($html, "colonna(bottoneZona).classList.add('d-none')")
        // Tra il titolo «Zone» e il menu non si apre nessun'altra card: i blocchi sono tutti lì dentro.
        && !str_contains($dentro, '<div class="card border">')
        && substr_count($dentro, 'data-wi-zone-remove=') >= 2;
}));

check('«Togli zona» chiede conferma, e la scheda non ha più il codice del servizio', fn () => prova(static function (): bool {
    $zona = zona('Italia', [['IT', '']]);
    $html = layoutHtml(ShippingMethodResource::formLayoutSchema());
    $pulsante = preg_match('/<button[^>]*data-wi-zone-remove="'.$zona.'"[^>]*>/', $html, $m) === 1 ? $m[0] : '';
    $nomi = array_map(static fn ($i): string => (string) $i->name, ShippingMethodResource::formSchema());

    return $pulsante !== ''
        && str_contains($pulsante, 'data-wi-confirm="')
        && str_contains($pulsante, 'data-wi-confirm-variant="danger"')
        && str_contains($pulsante, 'data-wi-confirm-ok="Togli"')
        && !in_array('provider_service_code', $nomi, true)
        && !str_contains($html, 'Codice del servizio');
}));

check('una zona nuova parte a prezzo fisso, e un listino già salvato tiene il suo tipo', fn () => prova(static function (): bool {
    $zona = zona('Italia', [['IT', '']]);
    $html = layoutHtml(ShippingMethodResource::formLayoutSchema());
    $inizio = (int) strpos($html, 'name="rate_'.$zona.'_price_type"');
    $select = substr($html, $inizio, 400);

    return preg_match('/<option value="fixed" selected>/', $select) === 1
        && preg_match('/<option value="brackets" selected>/', $select) !== 1;
}));

check('col prezzo fisso gli scaglioni stanno in un contenitore che si vede solo a scaglioni', fn () => prova(static function (): bool {
    $zona = zona('Italia', [['IT', '']]);
    $html = layoutHtml(ShippingMethodResource::formLayoutSchema());
    $contenitore = '<div data-visible-when="rate_'.$zona.'_price_type" data-visible-when-values="brackets" data-wi-conditional-container="true" class="row g-3">';
    $inizio = strpos($html, $contenitore);
    $scaglioni = strpos($html, 'rate_'.$zona.'_brackets');
    $minimo = strpos($html, 'name="rate_'.$zona.'_min_price"');
    $fisso = strpos($html, 'name="rate_'.$zona.'_fixed_price"');

    // Il contenitore apre prima della tabella degli scaglioni e degli altri campi a peso;
    // il prezzo fisso e il tipo di prezzo restano fuori, sopra.
    return $inizio !== false && $scaglioni !== false && $minimo !== false && $fisso !== false
        && $inizio < $scaglioni && $inizio < $minimo && $fisso < $inizio;
}));

check('il salvataggio del metodo ferma un listino che non sta in piedi, con la frase', fn () => prova(static function (): bool {
    $zona = zona('Italia', [['IT', '']]);
    $_POST = richiesta($zona, [], [['type' => 'price', 'max_weight' => '5', 'amount' => '-3']]);

    try {
        ShippingMethodResource::mutateRequestValues(['name' => 'Standard'], 'store');
    } catch (UserError $e) {
        return $e->key() === 'shipping.bracket_amount';
    } finally {
        $_POST = [];
    }

    return false;
}));

check('mutateRequestValues toglie i campi dei listini e mette la posizione solo alla creazione', fn () => prova(static function (): bool {
    $zona = zona('Italia', [['IT', '']]);
    $_POST = richiesta($zona);

    $nuovo = ShippingMethodResource::mutateRequestValues(['name' => 'Standard', 'carrier_id' => '0', 'rate_'.$zona.'_on' => 'true', 'applies_pos' => 'true'], 'store');
    $vecchio = ShippingMethodResource::mutateRequestValues(['name' => 'Standard', 'carrier_id' => '0'], 'update', 'backend', ['id' => 1]);
    $_POST = [];

    return isset($nuovo['position'])
        && !isset($vecchio['position'])
        && array_filter(array_keys($nuovo), static fn ($k): bool => str_starts_with((string) $k, 'rate_')) === []
        && !array_key_exists('applies_pos', $nuovo);
}));

check('eliminare un metodo toglie i suoi listini e scaglioni', fn () => prova(static function (): bool {
    $zona = zona('Italia', [['IT', '']]);
    $metodo = metodo('Standard');
    RateForm::saveRates($metodo, RateForm::readRates(richiesta($zona)));
    $rate = (int) tutte(ShippingRate::class, ['shipping_method_id' => $metodo])[0]['id'];

    ShippingMethodResource::deleteRecord($metodo);

    return tutte(ShippingMethod::class, ['id' => $metodo]) === []
        && tutte(ShippingRate::class, ['shipping_method_id' => $metodo]) === []
        && tutte(ShippingRateBracket::class, ['shipping_rate_id' => $rate]) === [];
}));

check('un metodo su un ordine non si elimina', fn () => prova(static function (): bool {
    [$cart, $metodo] = (static function (): array {
        $it = zona('Italia', [['IT', '']]);
        $metodo = metodo('Standard');
        listino($metodo, $it, [[5, 8.0]]);
        $cart = carrello([[articolo(1.0, 10.0), 1]]);
        \Wonder\Plugin\Gestionale\Models\Sales\Order::update(['shipping_method_id' => $metodo], $cart);

        return [$cart, $metodo];
    })();

    try {
        ShippingMethodResource::deleteRecord($metodo);
    } catch (RuntimeException $e) {
        return str_contains($e->getMessage(), 'ordini o carrelli')
            && tutte(ShippingMethod::class, ['id' => $metodo]) !== [];
    }

    return false;
}));

check('una zona con un listino acceso non si elimina; spento sì, con aree e listini', fn () => prova(static function (): bool {
    $zona = zona('Italia', [['IT', ''], ['SM', '']]);
    $metodo = metodo('Standard');
    RateForm::saveRates($metodo, RateForm::readRates(richiesta($zona)));

    try {
        ShippingZoneResource::deleteRecord($zona);
        return false;
    } catch (RuntimeException $e) {
        if (!str_contains($e->getMessage(), 'listini accesi')) {
            return false;
        }
    }

    RateForm::saveRates($metodo, []);
    ShippingZoneResource::deleteRecord($zona);

    return tutte(ShippingZone::class, ['id' => $zona]) === []
        && tutte(ShippingZoneArea::class, ['shipping_zone_id' => $zona]) === []
        && tutte(ShippingRate::class, ['shipping_zone_id' => $zona]) === []
        && tutte(ShippingMethod::class, ['id' => $metodo]) !== [];
}));

check('un corriere su un metodo non si elimina, senza metodi sì', fn () => prova(static function (): bool {
    $corriere = (int) Carrier::create(['code' => 'CR-T', 'name' => 'Corriere', 'provider' => 'manual', 'active' => 'true', 'position' => 1])->insert_id;
    $metodo = metodo('Standard', ['carrier_id' => $corriere]);

    try {
        CarrierResource::deleteRecord($corriere);
        return false;
    } catch (RuntimeException $e) {
        if (!str_contains($e->getMessage(), 'metodi di spedizione')) {
            return false;
        }
    }

    ShippingMethod::update(['carrier_id' => 0], $metodo);
    CarrierResource::deleteRecord($corriere);

    return tutte(Carrier::class, ['id' => $corriere]) === [];
}));

check('la zona normalizza le aree e rifiuta paesi storti, doppie e zone vuote', fn () => prova(static function (): bool {
    $chiave = static function (array $aree): string {
        $_POST = ['name' => 'Zona', 'areas' => $aree];

        try {
            ShippingZoneResource::mutateRequestValues(['name' => 'Zona'], 'store');
        } catch (UserError $e) {
            return $e->key();
        } finally {
            $post = $_POST;
            $_POST = [];
        }

        return $post['areas'][0]['country'] ?? 'ok';
    };

    return $chiave([['country' => ' it ', 'province' => ' ca ']]) === 'IT'
        && $chiave([['country' => 'ITA', 'province' => '']]) === 'shipping.area_country'
        && $chiave([['country' => 'IT', 'province' => ''], ['country' => 'it', 'province' => '']]) === 'shipping.area_duplicate'
        && $chiave([['country' => '', 'province' => '']]) === 'shipping.zone_no_areas';
}));

summary();
