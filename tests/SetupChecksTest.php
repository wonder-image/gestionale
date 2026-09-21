<?php
/** php tests/SetupChecksTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Support\Setup\SetupChecks;

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

summary();
