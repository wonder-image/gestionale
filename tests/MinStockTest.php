<?php
/** php tests/MinStockTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Resources\Catalog\ProductModelResource;
use Wonder\Plugin\Gestionale\Resources\Catalog\ProductResource;

// Lo stato delle funzionalità si forza senza database: `features()` è
// memoizzato. `null` torna a leggerlo (e senza database è tutto spento).
$forza = static function (?array $stato): void {
    (new ReflectionProperty(Gestionale::class, 'features'))->setValue(null, $stato);
};

$chiavi = static function (string $resource): array {
    $nomi = [];

    foreach ($resource::formSchema() as $field) {
        $nomi[] = (string) $field->name;
    }

    return $nomi;
};

$rifiutata = static function (mixed $valore): bool {
    try {
        ProductModelResource::minStockValue($valore);
    } catch (InvalidArgumentException $errore) {
        return str_contains($errore->getMessage(), 'scorta minima');
    }

    return false;
};

check('una scorta minima vuota vale zero, cioè nessun avviso', fn () =>
    ProductModelResource::minStockValue('') === '0.000'
    && ProductModelResource::minStockValue(null) === '0.000'
    && ProductModelResource::minStockValue('0') === '0.000'
);

check('la scorta minima si legge come la giacenza', fn () =>
    ProductModelResource::minStockValue('5') === '5.000'
    && ProductModelResource::minStockValue('2,5') === '2.500'
    && ProductModelResource::minStockValue('20.000') === '20.000'
    && ProductModelResource::minStockValue('1.234,5') === '1234.500'
    && ProductModelResource::minStockValue(' 3 ') === '3.000'
);

check('negativi, testo e liste non passano, e il messaggio dice cosa scrivere', fn () =>
    $rifiutata('-1') && $rifiutata('abc') && $rifiutata(['5'])
);

check('con gli avvisi bloccati la scorta minima non compare in nessuna scheda', function () use ($forza, $chiavi) {
    $forza(['low_stock_alerts' => false]);

    return !in_array('product_min_stock', $chiavi(ProductModelResource::class), true)
        && !in_array('min_stock', $chiavi(ProductResource::class), true);
});

check('con gli avvisi sbloccati la scorta minima c\'è in tutte e due', function () use ($forza, $chiavi) {
    $forza(['low_stock_alerts' => true]);

    return in_array('product_min_stock', $chiavi(ProductModelResource::class), true)
        && in_array('min_stock', $chiavi(ProductResource::class), true);
});

check('la scheda della versione controlla la soglia e la toglie dai valori: non è una colonna', function () use ($forza) {
    $forza(['low_stock_alerts' => true]);
    $valori = ProductResource::mutateRequestValues(['min_stock' => '2,5', 'name' => 'Rosso'], 'update', 'backend', ['id' => 1]);

    // La scrive `afterUpdate()` sulla sede principale, tramite `Thresholds`.
    return !array_key_exists('min_stock', $valori)
        && ($valori['name'] ?? null) === 'Rosso';
});

check('con gli avvisi bloccati la soglia non si guarda, nemmeno da un form vecchio', function () use ($forza) {
    $forza(['low_stock_alerts' => false]);
    $valori = ProductResource::mutateRequestValues(['min_stock' => '-9'], 'update', 'backend', ['id' => 1]);

    return !array_key_exists('min_stock', $valori);
});

check('la scheda della versione rifiuta una soglia negativa', function () use ($forza) {
    $forza(['low_stock_alerts' => true]);

    try {
        ProductResource::mutateRequestValues(['min_stock' => '-2'], 'update', 'backend', ['id' => 1]);
    } catch (InvalidArgumentException $errore) {
        return str_contains($errore->getMessage(), 'scorta minima');
    }

    return false;
});

/**
 * Una scheda con le versioni, l'unità e le soglie che si vogliono: senza
 * database non ci sarebbe niente di tutto questo.
 */
$schedaConSoglie = new class extends ProductModelResource {
    public static string $unita = 'pz';

    /** @var array<int, float> scorta minima sulla sede principale, per id del prodotto */
    public static array $soglie = [2 => 5.0, 3 => 0.0];

    /** @var array<int, string> i fornitori che la pagina propone, id => nome */
    public static array $fornitori = [];

    protected static function supplierChoices(int $modelId): array
    {
        return static::$fornitori;
    }

    protected static function currentId(): ?int
    {
        return 1;
    }

    public static function productCount(int $modelId): int
    {
        return count(static::$soglie);
    }

    public static function variantCount(int $modelId): int
    {
        return 1;
    }

    public static function products(int $modelId): array
    {
        $righe = [];

        foreach (array_keys(static::$soglie) as $id) {
            $righe[] = ['id' => $id, 'sku' => 'CAP-'.$id, 'price' => '24.90'];
        }

        return $righe;
    }

    protected static function modelUnit(int $modelId): string
    {
        return static::$unita;
    }

    protected static function stockQuantities(array $productIds): array
    {
        return [];
    }

    // Le soglie stanno in `gst_stock_thresholds`, non nella riga del
    // prodotto: la scheda le legge da qui, sede principale.
    protected static function mainThresholds(array $productIds): array
    {
        $soglie = [];

        foreach ($productIds as $id) {
            $soglie[(int) $id] = static::$soglie[(int) $id] ?? 0.0;
        }

        return $soglie;
    }
};

/** Il contesto del repeater delle opzioni: colonne, avanzate, … */
$griglia = static function (object $scheda): array {
    foreach ($scheda::formSchema() as $field) {
        if ((string) $field->name === 'products') {
            return (array) $field->get('context');
        }
    }

    return [];
};

/** Una colonna della griglia, per nome. */
$colonna = static function (object $scheda, string $nome) use ($griglia): ?object {
    foreach ((array) ($griglia($scheda)['columns'] ?? []) as $colonna) {
        if ((string) ($colonna->name ?? '') === $nome) {
            return $colonna;
        }
    }

    return null;
};

$formato = static fn (?object $campo): array =>
    (array) (((array) ($campo?->get('context') ?? []))['number'] ?? []);

check('con gli avvisi sbloccati ogni opzione ha la sua scorta minima, fra le avanzate', function () use ($forza, $schedaConSoglie, $griglia, $colonna) {
    $forza(['low_stock_alerts' => true]);
    $contesto = $griglia($schedaConSoglie);
    $soglia = $colonna($schedaConSoglie, 'min_stock');
    $larghezze = [];

    foreach (['sku', 'ean', 'min_stock', 'active', 'photo'] as $nome) {
        $larghezze[$nome] = ((array) ($colonna($schedaConSoglie, $nome)?->columnSpan ?? []))['default'] ?? null;
    }

    // Quattro caselle in fila, e la foto sotto su tutta la riga.
    return $soglia !== null
        && $soglia->get('label') === 'Scorta minima'
        && array_values((array) ($contesto['advanced'] ?? [])) === ['sku', 'ean', 'min_stock', 'active', 'photo']
        && $larghezze === ['sku' => 3, 'ean' => 3, 'min_stock' => 3, 'active' => 3, 'photo' => 12];
});

check('con gli avvisi bloccati la griglia non ha la scorta minima', function () use ($forza, $schedaConSoglie, $griglia, $colonna) {
    $forza(['low_stock_alerts' => false]);

    return $colonna($schedaConSoglie, 'min_stock') === null
        && !in_array('min_stock', (array) ($griglia($schedaConSoglie)['advanced'] ?? []), true);
});

check('la scorta minima si scrive con l\'unità, come la giacenza', function () use ($forza, $schedaConSoglie, $colonna, $formato) {
    $forza(['low_stock_alerts' => true]);
    $schedaConSoglie::$unita = 'kg';
    $chili = $formato($colonna($schedaConSoglie, 'min_stock'));
    $schedaConSoglie::$unita = 'pz';
    $pezzi = $formato($colonna($schedaConSoglie, 'min_stock'));

    // Una soglia già scritta coi decimali li tiene, anche in pezzi.
    $schedaConSoglie::$soglie = [2 => 2.5, 3 => 0.0];
    $mezzi = $formato($colonna($schedaConSoglie, 'min_stock'));
    $schedaConSoglie::$soglie = [2 => 5.0, 3 => 0.0];

    return ($chili['decimal'] ?? null) === 3
        && ($chili['symbol'] ?? null) === ' kg'
        && ($pezzi['decimal'] ?? null) === 0
        && ($pezzi['symbol'] ?? null) === ' pz'
        && ($mezzi['decimal'] ?? null) === 3;
});

check('senza varianti la scorta minima ha anche lei l\'unità in coda', function () use ($forza, $schedaConSoglie, $formato) {
    $forza(['low_stock_alerts' => true]);
    $campo = null;

    foreach ($schedaConSoglie::formSchema() as $field) {
        if ((string) $field->name === 'product_min_stock') {
            $campo = $field;
        }
    }

    return ($formato($campo)['symbol'] ?? null) === ' pz';
});

check('una scorta minima sbagliata in una riga della griglia si rifiuta prima di salvare', function () use ($forza) {
    $forza(['low_stock_alerts' => true]);
    $rifiuta = static function (array $post, bool $conVarianti): bool {
        try {
            ProductModelResource::assertMinStocks($post, $conVarianti);
        } catch (InvalidArgumentException $errore) {
            return str_contains($errore->getMessage(), 'scorta minima');
        }

        return false;
    };
    $righe = static fn (string $soglia): array => ['products' => [
        ['id' => '2', 'min_stock' => '3'],
        ['id' => '3', 'min_stock' => $soglia],
    ]];

    return $rifiuta($righe('-1'), true)
        && $rifiuta($righe('tanti'), true)
        && $rifiuta(['product_min_stock' => '-4'], false)
        // Le caselle nascoste arrivano lo stesso: conta solo quella di chi
        // si sta usando.
        && !$rifiuta($righe('-1') + ['product_min_stock' => '5'], false)
        && !$rifiuta($righe('7 pz') + ['product_min_stock' => '-4'], true);
});

check('con gli avvisi bloccati le soglie postate non si guardano', function () use ($forza) {
    $forza(['low_stock_alerts' => false]);

    try {
        ProductModelResource::assertMinStocks(['products' => [['id' => '2', 'min_stock' => '-1']], 'product_min_stock' => '-1'], true);
        ProductModelResource::assertMinStocks(['product_min_stock' => '-1'], false);
    } catch (InvalidArgumentException) {
        return false;
    }

    return true;
});

/** Nome => larghezza delle colonne della griglia chieste, nell'ordine. */
$larghezze = static function (object $scheda, array $nomi) use ($colonna): array {
    $larghezze = [];

    foreach ($nomi as $nome) {
        $larghezze[$nome] = ((array) ($colonna($scheda, $nome)?->columnSpan ?? []))['default'] ?? null;
    }

    return $larghezze;
};

check('i fornitori stanno fra le avanzate, dopo lo stato e prima della foto', function () use ($forza, $schedaConSoglie, $griglia, $larghezze) {
    // Da questo giro i fornitori sono dell'opzione (P107, P108): la riga
    // delle avanzate tiene le sue quattro caselle, poi i fornitori — due
    // campi con uno solo, il bottone con più — e la foto in fondo.
    $attese = [
        0 => ['sku' => 3, 'ean' => 3, 'min_stock' => 3, 'active' => 3, 'photo' => 12],
        1 => ['sku' => 3, 'ean' => 3, 'min_stock' => 3, 'active' => 3, 'supplier_sku' => 6, 'supplier_cost' => 6, 'photo' => 12],
        2 => ['sku' => 3, 'ean' => 3, 'min_stock' => 3, 'active' => 3, 'suppliers_button' => 12, 'photo' => 12],
    ];
    $risultato = true;

    foreach ([[], [5 => 'Filati Nord'], [5 => 'Filati Nord', 6 => 'Lanificio Sud']] as $fornitori) {
        foreach ([true, false] as $acquisti) {
            $schedaConSoglie::$fornitori = $fornitori;
            $forza(['low_stock_alerts' => true, 'purchasing' => $acquisti]);

            // Ad acquisti spenti la riga resta quella di sempre.
            $attesa = $attese[$acquisti ? count($fornitori) : 0];
            $avanzate = array_values((array) ($griglia($schedaConSoglie)['advanced'] ?? []));

            $risultato = $risultato
                && $avanzate === array_keys($attesa)
                && $larghezze($schedaConSoglie, $avanzate) === $attesa;
        }
    }

    $schedaConSoglie::$fornitori = [];

    return $risultato;
});

$forza(null);

summary();
