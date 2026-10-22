<?php
/** php tests/SearchClientTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Support\Sales\OfficeOrderEditor;
use Wonder\Plugin\Gestionale\Support\SearchClient;
use Wonder\Plugin\Gestionale\Support\Stock\StockDocumentEditor;

const CLIENT = 'window.wiSearchClient = window.wiSearchClient ||';

check('SearchClient::script() stampa il client a ogni chiamata, il guard JS evita il doppio', function () {
    $uno = SearchClient::script();
    $due = SearchClient::script();

    return $uno !== '' && $uno === $due
        && str_contains($uno, CLIENT)
        && SearchClient::source() !== '';
});

check('il client sta fuori da paths.assets: publish:module --assets non lo copia nel sito', function () {
    return is_file(Gestionale::root().'/'.SearchClient::FILE)
        && !is_file(Gestionale::assetPath('js/search-client.js'));
});

check('ogni editor degli ordini d\'ufficio porta il client prima del suo script', function () {
    foreach ([OfficeOrderEditor::render([], '/s', []), OfficeOrderEditor::render([], '/s', [])] as $html) {
        $client = strpos($html, CLIENT);
        $editor = strpos($html, 'window.wiOfficeEditor = ');

        if ($client === false || $editor === false || $client > $editor) {
            return false;
        }
    }

    return true;
});

check('ogni editor dei documenti di magazzino porta il client prima del suo script', function () {
    foreach ([StockDocumentEditor::render([], '/s'), StockDocumentEditor::render([], '/s')] as $html) {
        $client = strpos($html, CLIENT);
        $editor = strpos($html, 'window.wiStockEditor = ');

        if ($client === false || $editor === false || $client > $editor) {
            return false;
        }
    }

    return true;
});

summary();
