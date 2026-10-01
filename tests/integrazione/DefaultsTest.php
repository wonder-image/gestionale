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
                && ($m['bank-transfer']['timing'] ?? '') === 'deferred' && ($m['bank-transfer']['provider'] ?? '') === 'manual'
                && ($m['cash']['timing'] ?? '') === 'on_delivery' && ($m['cash']['provider'] ?? '') === 'manual'
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

        Defaults::seed(new DefaultRows());

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
