<?php
/** php tests/SetupChecksTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\App\Module\Contracts\ModuleGoogleApis;
use Wonder\App\Support\GoogleCloud\GoogleApis;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Support\Setup\SetupChecks;

// Il registro delle API Google legge il sito da ROOT: qui il sito dichiara
// le API del gestionale, come farebbe il modulo abilitato.
$previousRoot = $GLOBALS['ROOT'] ?? null;
$sandbox = sys_get_temp_dir().'/gestionale-setup-test-'.uniqid();
mkdir($sandbox.'/custom/config', 0777, true);
file_put_contents($sandbox.'/custom/config/google-apis.php', "<?php return Wonder\\Plugin\\Gestionale\\Gestionale::googleApis();");
$GLOBALS['ROOT'] = $sandbox;
GoogleApis::reset();

$completi = [
    'society' => ['legal_name' => 'Rossi srl', 'pi' => '01234567890', 'email' => 'info@rossi.it'],
    'location' => ['city' => 'Bergamo', 'street' => 'Via Roma', 'number' => '1', 'cap' => '24100'],
    'settings' => ['fiscal_confirmed_at' => '2026-09-21 10:00:00'],
];

check('con tutto a posto non resta niente da fare', fn () =>
    SetupChecks::pending($completi['society'], $completi['location'], $completi['settings']) === []
);

check('senza partita IVA né codice fiscale manca un passo', function () use ($completi) {
    $società = $completi['society'];
    $società['pi'] = '';

    $voci = array_column(SetupChecks::pending($società, $completi['location'], $completi['settings']), 'key');

    return $voci === ['society'];
});

check('il codice fiscale basta al posto della partita IVA', function () use ($completi) {
    $società = $completi['society'];
    $società['pi'] = '';
    $società['cf'] = 'RSSMRA80A01A794X';

    return SetupChecks::pending($società, $completi['location'], $completi['settings']) === [];
});

check('un indirizzo a metà è un passo aperto', function () use ($completi) {
    $sede = $completi['location'];
    $sede['street'] = '';

    $voci = array_column(SetupChecks::pending($completi['society'], $sede, $completi['settings']), 'key');

    return $voci === ['location'];
});

check('le impostazioni fiscali vanno confermate da una persona', function () use ($completi) {
    $voci = array_column(SetupChecks::pending($completi['society'], $completi['location'], []), 'key');

    return $voci === ['fiscal'];
});

check('ogni passo dice dove andare', function () {
    foreach (SetupChecks::pending([], [], []) as $voce) {
        if (trim((string) ($voce['title'] ?? '')) === ''
            || trim((string) ($voce['description'] ?? '')) === ''
            || !str_starts_with((string) ($voce['url'] ?? ''), '/backend/')) {
            return false;
        }
    }

    return count(SetupChecks::pending([], [], [])) === 3;
});

// Google Cloud: le credenziali che servono a questo progetto.
$google = [
    'gcp_project_id' => 'progetto-prova',
    'gcp_api_key' => 'chiave_privata_prova',
    'gcp_client_api_key' => 'chiave_pubblica_prova',
    'g_recaptcha_site_key' => 'chiave_sito_prova',
    'google_oauth_client_id' => 'prova.apps.googleusercontent.com',
];

check('senza credenziali passate i passi Google non si controllano', fn () =>
    SetupChecks::pending($completi['society'], $completi['location'], $completi['settings']) === []
);

check('con le credenziali Google a posto non resta niente da fare', fn () =>
    SetupChecks::pending($completi['society'], $completi['location'], $completi['settings'], $google) === []
);

check('il gestionale dichiara obbligatorie Maps JavaScript, Places e l\'ID client OAuth', function () {
    $keys = array_map(static fn ($api) => $api->key(), [...Gestionale::googleApis()]);

    return is_subclass_of(Gestionale::class, ModuleGoogleApis::class)
        && $keys === ['maps_javascript', 'places', 'google_oauth']
        && array_keys(GoogleApis::required()) === ['recaptcha_enterprise', 'maps_javascript', 'places', 'google_oauth'];
});

check('senza credenziali Google ci sono tre passi, in ordine', fn () =>
    array_column(SetupChecks::pending($completi['society'], $completi['location'], $completi['settings'], []), 'key')
        === ['google_recaptcha_enterprise', 'google_maps_javascript', 'google_oauth']
);

check('la chiave pubblica è un passo solo per Maps JavaScript API e Places API (New)', function () use ($completi, $google) {
    $credenziali = $google;
    $credenziali['gcp_client_api_key'] = '  ';
    $voci = SetupChecks::pending($completi['society'], $completi['location'], $completi['settings'], $credenziali);

    return array_column($voci, 'key') === ['google_maps_javascript']
        && str_contains($voci[0]['title'], 'Maps JavaScript API, Places API (New)')
        && str_contains($voci[0]['description'], 'Maps JavaScript API')
        && str_contains($voci[0]['description'], 'Places API (New)')
        && str_contains($voci[0]['description'], '«Chiave pubblica»')
        && $voci[0]['url'] === '/backend/app/config/credentials/';
});

check('reCAPTCHA vuole ID progetto, chiave privata e chiave del sito', function () use ($completi, $google) {
    foreach (['gcp_project_id', 'gcp_api_key', 'g_recaptcha_site_key'] as $campo) {
        $credenziali = $google;
        $credenziali[$campo] = '';
        $voci = SetupChecks::pending($completi['society'], $completi['location'], $completi['settings'], $credenziali);

        if (array_column($voci, 'key') !== ['google_recaptcha_enterprise']
            || !str_contains($voci[0]['description'], 'reCAPTCHA Enterprise API')
            || !str_contains($voci[0]['description'], '«Chiave privata»')) {
            return false;
        }
    }

    return true;
});

check('Accedi con Google chiede un ID client OAuth 2.0 per applicazione web', function () use ($completi, $google) {
    $credenziali = $google;
    unset($credenziali['google_oauth_client_id']);
    $voci = SetupChecks::pending($completi['society'], $completi['location'], $completi['settings'], $credenziali);

    return array_column($voci, 'key') === ['google_oauth']
        && str_contains($voci[0]['description'], 'ID client OAuth 2.0')
        && str_contains($voci[0]['description'], 'origini JavaScript autorizzate');
});

unlink($sandbox.'/custom/config/google-apis.php');
rmdir($sandbox.'/custom/config');
rmdir($sandbox.'/custom');
rmdir($sandbox);
$GLOBALS['ROOT'] = $previousRoot;
GoogleApis::reset();

summary();
