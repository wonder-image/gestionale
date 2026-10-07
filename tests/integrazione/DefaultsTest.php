<?php
/** php tests/integrazione/DefaultsTest.php */
declare(strict_types=1);

const SITE = '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site';

chdir(SITE);
$GLOBALS['ROOT'] = SITE;
require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__ . '/../harness.php';

use Wonder\App\Models\Config\SocietyLocation;
use Wonder\App\Support\DefaultRows;
use Wonder\Plugin\Custom\Fattura\Valori\AliquoteIva;
use Wonder\Plugin\Custom\Fattura\Valori\Natura;
use Wonder\Plugin\Gestionale\Models\Locations\Location;
use Wonder\Plugin\Gestionale\Models\Payments\PaymentMethod;
use Wonder\Plugin\Gestionale\Models\Shipping\Carrier;
use Wonder\Plugin\Gestionale\Models\System\Feature;
use Wonder\Plugin\Gestionale\Models\System\FeatureLog;
use Wonder\Plugin\Gestionale\Models\System\MerchantSetting;
use Wonder\Plugin\Gestionale\Models\System\Setting;
use Wonder\Plugin\Gestionale\Models\Tax\Tax;
use Wonder\Plugin\Gestionale\Models\Tax\TaxCategory;
use Wonder\Plugin\Gestionale\Models\Tax\TaxRule;
use Wonder\Plugin\Gestionale\Seeding\Defaults;
use Wonder\Plugin\Gestionale\Support\Features\FeatureCatalog;
use Wonder\Sql\Transaction;

final class Annulla extends RuntimeException {}

$righe = static fn (): array => array_values(array_filter(
    (array) sqlSelect(Feature::$table, null)->row,
    'is_array'
));

$prima = count($righe());

try {
    Transaction::run(static function () use ($righe, $prima): void {
        // Si semina sul vuoto: con le righe già presenti il seed non tocca
        // niente e lo stato sarebbe quello lasciato dal pannello.
        sqlDelete(FeatureLog::$table);
        sqlDelete(Feature::$table);
        // Giacenze e movimenti puntano alla sede: senza toglierli prima, la
        // chiave esterna non lascia svuotare `gst_locations`. La transazione
        // rimette tutto a posto.
        // Lo stesso vale per i resi, che ricordano la sede dove la merce è rientrata.
        sqlDelete(\Wonder\Plugin\Gestionale\Models\Sales\SalesReturnStatusLog::$table);
        sqlDelete(\Wonder\Plugin\Gestionale\Models\Sales\SalesReturnItem::$table);
        sqlDelete(\Wonder\Plugin\Gestionale\Models\Sales\SalesReturn::$table);
        sqlDelete(\Wonder\Plugin\Gestionale\Models\Stock\StockThreshold::$table);
        sqlDelete(\Wonder\Plugin\Gestionale\Models\Stock\StockAlert::$table);
        sqlDelete(\Wonder\Plugin\Gestionale\Models\Stock\StockReservation::$table);
        sqlDelete(\Wonder\Plugin\Gestionale\Models\Stock\StockMovement::$table);
        sqlDelete(\Wonder\Plugin\Gestionale\Models\Stock\Stock::$table);
        sqlDelete(Location::$table);
        sqlDelete(TaxRule::$table);
        sqlDelete(Setting::$table);
        sqlDelete(TaxCategory::$table);
        sqlDelete(Tax::$table);
        sqlDelete(MerchantSetting::$table);
        sqlDelete(PaymentMethod::$table);

        Defaults::seed(new DefaultRows());
        $dopo = $righe();

        check('una riga per ogni funzionalità del catalogo', fn () =>
            count($dopo) === count(FeatureCatalog::all())
        );

        $stato = array_column($dopo, 'enabled', 'feature_key');

        check('le funzionalità nascono bloccate', fn () =>
            ($stato['orders'] ?? null) === 'false' && ($stato['backorders'] ?? null) === 'false'
        );

        $aliquote = array_values(array_filter((array) sqlSelect(Tax::$table, null)->row, 'is_array'));

        check('le aliquote italiane nascono visibili', function () use ($aliquote) {
            $visibili = array_column(
                array_values(array_filter($aliquote, static fn (array $r): bool => ($r['visible'] ?? '') === 'true')),
                'rate',
                'code'
            );

            return count($visibili) === count(AliquoteIva::Valori)
                && isset($visibili['22'], $visibili['10'], $visibili['5'], $visibili['4']);
        });

        check('le operazioni a zero ci sono tutte, nascoste', function () use ($aliquote) {
            $nascoste = array_values(array_filter(
                $aliquote,
                static fn (array $r): bool => ($r['visible'] ?? '') === 'false'
            ));

            return count($nascoste) === count(Natura::valide());
        });

        check('c\'è un solo tipo fiscale, quello ordinario', function () {
            $tipi = array_values(array_filter((array) sqlSelect(TaxCategory::$table, null)->row, 'is_array'));

            return count($tipi) === 1 && ($tipi[0]['code'] ?? '') === 'ordinaria';
        });

        check('le due regole italiane puntano al 22%', function () {
            $regole = array_values(array_filter((array) sqlSelect(TaxRule::$table, null)->row, 'is_array'));
            $ventidue = Tax::find(['code' => '22', 'deleted' => 'false'], 1);

            if (count($regole) !== 2 || !is_array($ventidue)) {
                return false;
            }

            foreach ($regole as $regola) {
                if ((int) ($regola['tax_id'] ?? 0) !== (int) $ventidue['id']) {
                    return false;
                }

                if (($regola['country'] ?? '') !== 'IT') {
                    return false;
                }
            }

            return array_column($regole, 'customer_type') === ['private', 'business'];
        });

        check('le impostazioni tecniche nascono confermabili e col ripiego giusto', function () {
            $impostazioni = Setting::current();
            $ventidue = Tax::find(['code' => '22', 'deleted' => 'false'], 1);

            return ($impostazioni['tax_regime'] ?? '') === 'RF01'
                && ($impostazioni['vat_collectability'] ?? '') === 'I'
                && ($impostazioni['catalog_prices_include_tax'] ?? '') === 'true'
                && ($impostazioni['invoice_numeration'] ?? '') === 'WEB'
                // Vuoto e NULL sono la stessa cosa: nessun provider, nessuna conferma.
                && trim((string) ($impostazioni['invoice_provider'] ?? '')) === ''
                && trim((string) ($impostazioni['fiscal_confirmed_at'] ?? '')) === ''
                && (int) ($impostazioni['fallback_tax_id'] ?? 0) === (int) ($ventidue['id'] ?? -1)
                && (int) ($impostazioni['shipping_tax_id'] ?? 0) === (int) ($ventidue['id'] ?? -1);
        });

        check('il commerciante ha la sua riga', fn () => MerchantSetting::current() !== []);

        check('la sede predefinita ha la riga del magazzino', function () {
            $sede = SocietyLocation::find(['is_default' => 'true', 'deleted' => 'false'], 1);
            $riga = Location::forSocietyLocation((int) ($sede['id'] ?? 0));

            return $riga !== [] && ($riga['has_stock'] ?? '') === 'true'
                && str_starts_with((string) ($riga['code'] ?? ''), 'loc_');
        });

        $metodi = static function (): array {
            $righe = array_values(array_filter((array) sqlSelect(PaymentMethod::$table, null)->row, 'is_array'));

            return array_column($righe, null, 'code');
        };

        check('nascono tre metodi di pagamento: bonifico, contanti e Stripe', function () use ($metodi) {
            $m = $metodi();

            return array_keys($m) === ['bank-transfer', 'cash', 'stripe']
                && ($m['bank-transfer']['timing'] ?? '') === 'deferred' && ($m['bank-transfer']['provider'] ?? '') === 'bank_transfer'
                && ($m['cash']['timing'] ?? '') === 'on_delivery' && ($m['cash']['provider'] ?? '') === 'cash'
                && ($m['cash']['available_for'] ?? '') === 'pickup'
                && ($m['stripe']['timing'] ?? '') === 'immediate' && ($m['stripe']['provider'] ?? '') === 'stripe';
        });

        check('Stripe nasce spento finché non c\'è la chiave, gli altri due accesi', function () use ($metodi) {
            $m = $metodi();

            return ($m['stripe']['active'] ?? '') === 'false'
                && ($m['bank-transfer']['active'] ?? '') === 'true'
                && ($m['cash']['active'] ?? '') === 'true';
        });

        // Spento a mano e acceso a mano: il rilancio non rimette le cose com'erano.
        PaymentMethod::update(['active' => 'false'], (int) $metodi()['bank-transfer']['id']);
        PaymentMethod::update(['active' => 'true'], (int) $metodi()['stripe']['id']);

        Defaults::seed(new DefaultRows());

        check('il rilancio non duplica i metodi e non tocca quelli cambiati a mano', function () use ($metodi) {
            $m = $metodi();

            return count($m) === 3
                && ($m['bank-transfer']['active'] ?? '') === 'false'
                && ($m['stripe']['active'] ?? '') === 'true';
        });

        // Un sito aggiornato ha ancora il tipo «manual» e la percentuale in fee_value.
        PaymentMethod::update(['provider' => 'manual', 'fee_type' => 'percent', 'fee_value' => '1.50', 'fee_percent' => '0.00', 'icons' => ''], (int) $metodi()['bank-transfer']['id']);
        PaymentMethod::update(['provider' => 'manual', 'icons' => ''], (int) $metodi()['cash']['id']);
        PaymentMethod::update(['icons' => ''], (int) $metodi()['stripe']['id']);

        Defaults::seed(new DefaultRows());

        check('i metodi di un sito aggiornato si sistemano da soli', function () use ($metodi) {
            $m = $metodi();

            return ($m['bank-transfer']['provider'] ?? '') === 'bank_transfer'
                && (float) ($m['bank-transfer']['fee_percent'] ?? 0) === 1.5
                && (float) ($m['bank-transfer']['fee_value'] ?? 0) === 0.0
                && ($m['cash']['provider'] ?? '') === 'cash'
                && ($m['bank-transfer']['icons'] ?? '') === 'genericbank'
                && ($m['cash']['icons'] ?? '') === 'cash'
                && ($m['stripe']['icons'] ?? '') === 'visa,master,maestro,american_express,google_pay,apple_pay';
        });

        Defaults::seed(new DefaultRows());

        $corrieri = static function (): array {
            $righe = array_values(array_filter((array) sqlSelect(Carrier::$table, null)->row, 'is_array'));

            return array_column($righe, null, 'code');
        };

        check('nascono i sei corrieri più comuni, col link per seguire il pacco', function () use ($corrieri) {
            $c = $corrieri();
            $attesi = [
                'poste-italiane' => ['Poste Italiane', 'https://www.poste.it/cerca/index.html#/risultati-spedizioni/{tracking}'],
                'dhl' => ['DHL', 'https://www.dhl.com/it-en/home/tracking.html?tracking-id={tracking}'],
                'gls' => ['GLS', 'https://gls-group.com/IT/it/servizi-online/ricerca-spedizioni.html?match={tracking}'],
                'ups' => ['UPS', 'https://www.ups.com/track?loc=it_IT&requester=QUIC&tracknum={tracking}'],
                'bartolini' => ['Bartolini', 'https://services.brt.it/it/tracking?OP=N&CD={tracking}'],
                'fedex' => ['FedEx', 'https://www.fedex.com/fedextrack/?action=track&trackingnumber={tracking}'],
            ];

            foreach ($attesi as $codice => [$nome, $link]) {
                $riga = $c[$codice] ?? [];

                if (html_entity_decode((string) ($riga['name'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8') !== $nome
                    || html_entity_decode((string) ($riga['tracking_url_template'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8') !== $link
                    || ($riga['active'] ?? '') !== 'true' || ($riga['provider'] ?? '') !== 'manual') {
                    return false;
                }
            }

            return true;
        });

        $dopoCorrieri = count($corrieri());
        Carrier::update(['name' => 'Il mio GLS', 'active' => 'false'], (int) $corrieri()['gls']['id']);
        Defaults::seed(new DefaultRows());

        check('il rilancio non duplica i corrieri e non rimette a posto quelli cambiati a mano', fn () =>
            count($corrieri()) === $dopoCorrieri
            && html_entity_decode((string) ($corrieri()['gls']['name'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8') === 'Il mio GLS'
            && ($corrieri()['gls']['active'] ?? '') === 'false'
        );

        check('una seconda esecuzione non duplica niente', fn () =>
            count($righe()) === count($dopo)
            && count(array_filter((array) sqlSelect(Tax::$table, null)->row, 'is_array')) === count($aliquote)
        );

        throw new Annulla();
    });
} catch (Annulla) {
}

check('dopo l\'annullamento il database è come prima', fn () => count($righe()) === $prima);

summary();
