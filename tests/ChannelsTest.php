<?php
/** php tests/ChannelsTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Support\Features\FeatureCatalog;
use Wonder\Plugin\Gestionale\Support\Sales\Channels;

check('senza nessun canale sbloccato vale solo il sito', function () {
    return Channels::activeFrom([]) === ['online']
        && Channels::activeFrom(['online_sales' => false, 'office_sales' => false, 'pos' => false]) === ['online']
        && Channels::active() === ['online'];
});

check('i canali sbloccati escono nell\'ordine sito, ufficio, cassa', function () {
    return Channels::activeFrom(['pos' => true, 'online_sales' => true]) === ['online', 'pos']
        && Channels::activeFrom(['office_sales' => true]) === ['office']
        && Channels::activeFrom(['online_sales' => true, 'office_sales' => true, 'pos' => true]) === ['online', 'office', 'pos'];
});

check('le etichette sono Sito, Ufficio, Cassa e i nomi lunghi quelli della funzionalità', function () {
    return Channels::label('online') === 'Sito'
        && Channels::label('office') === 'Ufficio'
        && Channels::label('pos') === 'Cassa'
        && Channels::column('office') === 'applies_office'
        && array_keys(Channels::all()) === ['online', 'office', 'pos'];
});

check('si sceglie il canale solo se ce n\'è più di uno attivo', function () {
    return Channels::chooseFrom(['online']) === false
        && Channels::chooseFrom(['online', 'office']) === true;
});

check('un nuovo record parte con i canali attivi a «sì» e gli altri a «no»', function () {
    return Channels::defaultsFrom(['online']) === ['applies_online' => 'true', 'applies_office' => 'false', 'applies_pos' => 'false']
        && Channels::defaultsFrom(['office', 'pos']) === ['applies_online' => 'false', 'applies_office' => 'true', 'applies_pos' => 'true'];
});

check('salvando, i canali che il form non mostra restano come erano e l\'unico attivo vale «sì»', function () {
    // Un solo canale attivo (il sito): il form non ha toggle, i valori vecchi restano.
    $vecchi = ['applies_online' => 'false', 'applies_office' => 'true', 'applies_pos' => 'false'];
    $solo = Channels::keepHiddenFrom(['online'], ['name' => 'X'], $vecchi);

    // Due canali attivi: i toggle ci sono, passano quelli della richiesta; la cassa nascosta resta com'era.
    $due = Channels::keepHiddenFrom(['online', 'office'], ['applies_online' => 'true', 'applies_office' => 'false'], $vecchi);

    // Nuovo record senza vecchi valori: partono i valori di partenza.
    $nuovo = Channels::keepHiddenFrom(['online'], ['name' => 'X'], null);

    return $solo['applies_online'] === 'true' && $solo['applies_office'] === 'true' && $solo['applies_pos'] === 'false'
        && $due['applies_online'] === 'true' && $due['applies_office'] === 'false' && $due['applies_pos'] === 'false'
        && $nuovo['applies_online'] === 'true' && $nuovo['applies_office'] === 'false' && $nuovo['applies_pos'] === 'false';
});

check('il catalogo ha Vendita online, Vendita in ufficio e Vendita in cassa nell\'area «Canali di vendita»', function () {
    $catalogo = FeatureCatalog::all();

    return ($catalogo['online_sales']['name'] ?? '') === 'Vendita online'
        && ($catalogo['office_sales']['name'] ?? '') === 'Vendita in ufficio'
        && ($catalogo['pos']['name'] ?? '') === 'Vendita in cassa'
        && ($catalogo['online_sales']['area'] ?? '') === 'Canali di vendita'
        && ($catalogo['office_sales']['area'] ?? '') === 'Canali di vendita'
        && ($catalogo['pos']['area'] ?? '') === 'Canali di vendita'
        && $catalogo['online_sales']['created'] === true
        && $catalogo['office_sales']['created'] === true
        && $catalogo['pos']['created'] === false;
});

summary();
