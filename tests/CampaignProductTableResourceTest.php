<?php
/** php tests/CampaignProductTableResourceTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Resources\Promotions\CampaignProductTableResource;

/**
 * L'elenco dei prodotti nell'anteprima di una campagna è una Resource senza
 * pagina e senza menu, come le tabelle della scheda ordine.
 */
check('ha il suo model e il suo percorso', fn () =>
    CampaignProductTableResource::$model === Product::class
    && CampaignProductTableResource::path() === 'app/gestionale/campagne-prodotti'
);

check('nessuna pagina, nessuna voce di menu, solo amministratore, nessuna API', function () {
    $pagine = array_filter((array) (CampaignProductTableResource::pageSchema()->toArray()['pages'] ?? []));
    $nav = CampaignProductTableResource::navigationSchema()->toArray();
    $permessi = json_encode(CampaignProductTableResource::permissionSchema()->toArray());

    return $pagine === [] && empty($nav['enabled'])
        && is_string($permessi) && str_contains($permessi, 'administrator')
        && empty(CampaignProductTableResource::apiSchema()->toArray()['enabled']);
});

check('le colonne sono foto, articolo, prezzo e prezzo con la campagna, tutte con la loro etichetta', function () {
    $nomi = array_map(static fn ($c): string => (string) $c->name, CampaignProductTableResource::tableSchema());
    $etichette = CampaignProductTableResource::labelSchema();

    foreach ($nomi as $nome) {
        if (!isset($etichette[$nome])) {
            return false;
        }
    }

    return $nomi === ['photo', 'model_name', 'price', 'campaign_price'];
});

check('il nome è «Articolo — opzione» con lo SKU sotto, escapato una volta sola', function () {
    $riga = ['model_name' => 'Maglia &#8212; <b>', 'name' => 'Blu / M', 'sku' => 'MG-1&amp;2', 'product_model_id' => 7];
    $html = CampaignProductTableResource::nameCell($riga);

    return str_contains($html, 'Maglia — &lt;b&gt; — Blu / M')
        && str_contains($html, '<div class="text-muted small">MG-1&amp;2</div>')
        && !str_contains($html, '&amp;amp;') && !str_contains($html, '<b>');
});

check('un prodotto con una versione sola mostra il solo articolo; senza SKU niente riga sotto', function () {
    $html = CampaignProductTableResource::nameCell(['model_name' => 'Tazza', 'name' => '', 'sku' => '', 'product_model_id' => 3]);

    return str_contains($html, 'Tazza') && !str_contains($html, ' — ') && !str_contains($html, 'small');
});

check('l\'importo sta a destra; uno vuoto o non numerico non scrive niente', fn () =>
    str_contains(CampaignProductTableResource::priceCell('1234.5', true), '<strong>1.234,50 €</strong>')
    && str_contains(CampaignProductTableResource::priceCell('50.00', false), '50,00 €')
    && !str_contains(CampaignProductTableResource::priceCell('50.00', false), '<strong>')
    && CampaignProductTableResource::priceCell('', true) === ''
);
