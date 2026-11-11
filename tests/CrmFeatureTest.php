<?php
/** php tests/CrmFeatureTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\CustomerAccount\Support\Crm as AccountCrm;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Support\Contacts\Crm;
use Wonder\Plugin\Gestionale\Support\Features\FeatureCatalog;
use Wonder\Plugin\Gestionale\Support\Features\FeatureRules;
use Wonder\Plugin\Gestionale\Support\Features\FeatureState;

/*
 * «CRM»: la funzionalità dell'area Anagrafiche che mostra più recapiti
 * tipizzati e i social. Nasce accesa dove quei dati ci sono già; spenta
 * nasconde, non cancella.
 */

$funzionalita = new ReflectionProperty(Gestionale::class, 'features');
$forza = static fn (?array $stato) => $funzionalita->setValue(null, $stato);

check('nel pannello: «CRM» in Anagrafiche, spenta di serie, senza dipendenze, nasce da `Crm::inUse()`', function () {
    $voce = FeatureCatalog::all()['crm'] ?? [];

    return ($voce['name'] ?? '') === 'CRM' && ($voce['area'] ?? '') === 'Anagrafiche'
        && ($voce['default'] ?? true) === false && ($voce['requires'] ?? ['x']) === []
        && ($voce['default_if'] ?? null) === [Crm::class, 'inUse']
        && str_contains((string) ($voce['description'] ?? ''), 'restano salvati')
        && preg_match('/—|–|--/', (string) ($voce['description'] ?? '')) === 0;
});

check('senza database `Crm::inUse()` dice no e la voce non nasce accesa', function () {
    $catalogo = FeatureCatalog::all();

    return Crm::inUse() === false && !isset(FeatureState::defaults($catalogo, [])['crm']);
});

check('accenderla non accende altro; il brand kit non la accende', function () {
    $catalogo = FeatureCatalog::all();
    $crm = FeatureRules::apply($catalogo, ['private_customers' => true], ['crm' => true])['state'];
    $kit = FeatureRules::apply($catalogo, ['private_customers' => true], ['brand_kit' => true])['state'];

    return $crm['crm'] && !$crm['brand_kit'] && !$crm['brands'] && $kit['brand_kit'] && !$kit['crm'];
});

check('`Crm::enabled()` legge la funzionalità; stato non letto spenta', function () use ($forza) {
    $forza(['crm' => true]);
    $accesa = Crm::enabled();
    $forza([]);
    $spenta = Crm::enabled();
    $forza(null);

    return $accesa && !$spenta;
});

check('`Crm::apply()` accende l\'interruttore di customer-account e non lo spegne se l\'ha acceso il sito', function () use ($forza) {
    AccountCrm::enable(false);
    $forza([]);
    $spenta = Crm::apply();
    $forza(['crm' => true]);
    $accesa = Crm::apply() && AccountCrm::enabled();
    $forza([]);
    $resta = Crm::apply();
    AccountCrm::enable(false);
    $forza(null);

    return !$spenta && $accesa && $resta;
});

check('recapiti e social del brand seguono il CRM, anche a brand kit spento', function () use ($forza) {
    $esiti = [];

    foreach ([[true, true], [true, false], [false, true]] as [$kit, $crm]) {
        $forza(['brand_kit' => $kit, 'crm' => $crm]);
        $esiti[] = Crm::brandSocials();
    }

    $forza(null);

    return $esiti === [true, false, true];
});

check('il form del brand passa lo stato del CRM a customer-account', function () {
    $codice = (string) file_get_contents(__DIR__.'/../src/Resources/Contacts/CompanyBrandResource.php');

    return str_contains($codice, 'Support\Contacts\Crm::apply();');
});

summary();
