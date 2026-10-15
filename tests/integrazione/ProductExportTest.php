<?php
/** php tests/integrazione/ProductExportTest.php — D64, l'elenco prodotti sui dati demo */

define('SITE', getenv('WI_TEST_SITE') ?: '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site');

chdir(SITE);
$GLOBALS['ROOT'] = SITE;

require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__.'/../harness.php';

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Wonder\Http\Route;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Resources\Catalog\ProductModelResource;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\System\Feature;
use Wonder\Plugin\Gestionale\Support\Catalog\Exports\ProductExports;
use Wonder\Plugin\Gestionale\Support\Catalog\Exports\ProductFeed;
use Wonder\Plugin\Gestionale\Support\Catalog\ProductExport;

$export = ProductExport::of();
$righe = $export['rows'];
$perSku = array_column($righe, null, 'sku');

/** Il conto delle opzioni non cancellate di articoli non cancellati. */
$attese = (static function (): int {
    $modelli = ProductModel::find("`deleted` = 'false'");
    $modelli = isset($modelli['id']) ? [$modelli] : (array) $modelli;
    $ids = array_map(static fn (array $m): int => (int) $m['id'], $modelli);

    if ($ids === []) {
        return 0;
    }

    $opzioni = Product::find('`product_model_id` IN ('.implode(', ', $ids).") AND `deleted` = 'false'");

    return isset($opzioni['id']) ? 1 : count((array) $opzioni);
})();

check('of: una riga per ogni opzione non cancellata, bozze comprese', fn () =>
    $righe !== [] && count($righe) === $attese
);

check('of: l\'impermeabile nero scontato, con codice articolo, EAN e link della variante', function () use ($righe) {
    foreach ($righe as $riga) {
        if ($riga['group'] === 'IMP-1' && str_starts_with($riga['option'], 'Nero')) {
            return $riga['price'] === 89.0 && $riga['sale_price'] === 69.0
                && preg_match('/^\d{13}$/', $riga['ean']) === 1
                && str_ends_with($riga['link'], '/prodotto/impermeabile-da-citta/nero/')
                && str_starts_with($riga['image'], 'http');
        }
    }

    return false;
});

check('of: il multiprodotto KIT-1 con EAN e disponibili calcolati dai pezzi', fn () =>
    isset($perSku['KIT-1']) && $perSku['KIT-1']['ean'] === '2000040003007'
    && $perSku['KIT-1']['type'] === 'Multiprodotto'
    && ($perSku['KIT-1']['available'] === null || is_float($perSku['KIT-1']['available']))
);

check('of: l\'IVA dall\'impostazione del catalogo', fn () => is_bool($export['pricesIncludeTax']));

check('rotta: l\'«Esporta» passa dalla rotta export della risorsa', function () {
    foreach (Route::all() as $rotta) {
        if (($rotta['name'] ?? '') === 'backend.resource.'.ProductModelResource::slug().'.export') {
            return str_ends_with((string) $rotta['path'], '/export/{format}/');
        }
    }

    return false;
});

$feed = ProductFeed::of();

check('feed: solo ciò che si compra sul sito, con prezzo, link e foto', fn () =>
    $feed['rows'] !== [] && count($feed['rows']) <= count($righe)
    && array_filter($feed['rows'], static fn (array $r): bool => !ProductFeed::eligible($r)) === []
    && preg_match('/^[A-Z]{3}$/', $feed['currency']) === 1
);

/**
 * Le esportazioni sono funzionalità: il test le concede, poi rimette lo
 * stato com'era. Il controller gira in un altro processo, che legge il
 * database: niente transazione.
 */
$concedi = static function (array $chiavi): void {
    foreach (array_column(ProductExports::FORMATS, 'feature') as $chiave) {
        $acceso = in_array($chiave, $chiavi, true) ? 'true' : 'false';
        $riga = Feature::find(['feature_key' => $chiave]);
        $riga = isset($riga['id']) ? $riga : ((array) $riga)[0] ?? null;

        if (is_array($riga)) {
            sqlModify(Feature::$table, ['enabled' => $acceso, 'deleted' => 'false'], 'id', (int) $riga['id']);
        } else {
            Feature::create(['feature_key' => $chiave, 'enabled' => $acceso]);
        }
    }

    Gestionale::reset();
};

$prima = [];

foreach (array_column(ProductExports::FORMATS, 'feature') as $chiave) {
    $riga = Feature::find(['feature_key' => $chiave]);
    $riga = isset($riga['id']) ? $riga : ((array) $riga)[0] ?? null;
    $prima[$chiave] = is_array($riga) ? ['id' => (int) $riga['id'], 'enabled' => $riga['enabled'], 'deleted' => $riga['deleted'] ?? 'false'] : null;
}

/** Il controller della tabella in un processo a parte: invia il file. */
$scarica = static function (string $format): string {
    $codice = 'define("SITE", '.var_export(SITE, true).'); chdir(SITE); $GLOBALS["ROOT"] = SITE;'
        .' require SITE."/vendor/autoload.php"; require SITE."/vendor/wonder-image/app/wonder-image.php";'
        .' try { \\Wonder\\Backend\\Support\\ResourceDownloadController::fromSlug('.var_export(ProductModelResource::slug(), true).')->handle('.var_export($format, true).'); }'
        .' catch (\\Throwable $e) { echo "ERRORE: ".$e->getMessage(); }';

    return (string) shell_exec(escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($codice).' 2>/dev/null');
};

try {
    $concedi([]);

    check('pannello: senza esportazioni concesse «Esporta» è spento e non scarica', fn () =>
        empty(ProductModelResource::tableLayoutSchema()->all()['download']['enabled'])
        && str_starts_with($scarica('csv'), 'ERRORE: Export non abilitato')
    );

    $concedi(['export_csv', 'export_google']);

    check('pannello: «Esporta» offre solo quelle concesse, col gestore del registro', function () use ($scarica) {
        $download = (array) (ProductModelResource::tableLayoutSchema()->all()['download'] ?? []);

        return !empty($download['enabled'])
            && array_keys((array) $download['formats']) === ['csv', 'google']
            && is_callable($download['handler'] ?? null)
            && str_starts_with($scarica('xlsx'), 'ERRORE: Formato export non supportato');
    });

    $concedi(array_column(ProductExports::FORMATS, 'feature'));

    check('scarico csv: lo stesso file del servizio', function () use ($scarica, $righe) {
        $csv = $scarica('csv');

        return str_starts_with($csv, 'ID,') && substr_count($csv, "\n") === count($righe) + 1;
    });

    check('scarico xlsx: un foglio con l\'EAN come testo', function () use ($scarica, $righe) {
        $file = tempnam(sys_get_temp_dir(), 'wi-export');
        file_put_contents($file, $scarica('xlsx'));

        try {
            $foglio = IOFactory::load($file)->getActiveSheet();
        } finally {
            unlink($file);
        }

        foreach ($foglio->getRowIterator(2) as $riga) {
            $ean = $foglio->getCell('D'.$riga->getRowIndex());

            if ((string) $ean->getValue() === '2000040003007') {
                return $ean->getDataType() === DataType::TYPE_STRING && $foglio->getHighestRow() === count($righe) + 1;
            }
        }

        return false;
    });

    check('scarico meta: il .csv di Meta, una riga per prodotto del feed', function () use ($scarica, $feed) {
        $csv = $scarica('meta');
        $linee = array_map(static fn (string $l): array => str_getcsv($l, ',', '"', ''), explode("\n", trim($csv)));

        return ($linee[0][0] ?? '') === 'id' && count($linee) === count($feed['rows']) + 1
            && in_array('in stock', array_column(array_slice($linee, 1), 4), true);
    });

    check('scarico google: un xml RSS che si rilegge, un item per prodotto del feed', function () use ($scarica, $feed) {
        $doc = @simplexml_load_string($scarica('google'));

        return $doc !== false && count($doc->channel->item) === count($feed['rows'])
            && preg_match('/^\d+\.\d{2} [A-Z]{3}$/', (string) $doc->channel->item[0]->children('http://base.google.com/ns/1.0')->price) === 1;
    });

    check('scarico: un formato non dichiarato non scarica niente', fn () =>
        str_starts_with($scarica('pdf'), 'ERRORE: Formato export non supportato')
    );
} finally {
    foreach ($prima as $chiave => $stato) {
        if ($stato === null) {
            sqlDelete(Feature::$table, "feature_key = '".$chiave."'");
        } else {
            sqlModify(Feature::$table, ['enabled' => $stato['enabled'], 'deleted' => $stato['deleted']], 'id', $stato['id']);
        }
    }

    Gestionale::reset();
}

summary();
