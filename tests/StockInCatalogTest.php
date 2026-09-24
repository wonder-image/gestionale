<?php
/** php tests/StockInCatalogTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Elements\Components\RichText;
use Wonder\Elements\Components\SectionTitle;
use Wonder\Plugin\Gestionale\Resources\Catalog\ProductModelResource;
use Wonder\Plugin\Gestionale\Resources\Catalog\ProductResource;

/**
 * Una scheda con più versioni: è lì che vive la griglia.
 *
 * Fuori da una richiesta `currentId()` è nullo e la scheda mostra la
 * creazione, che di griglie non ne ha.
 */
$schedaPiena = new class extends ProductModelResource {
    protected static function currentId(): ?int
    {
        return 1;
    }

    public static function productCount(int $modelId): int
    {
        return 3;
    }

    public static function variantCount(int $modelId): int
    {
        return 1;
    }

    public static function products(int $modelId): array
    {
        return [['id' => 2, 'sku' => 'CAP-1', 'price' => '24.90']];
    }
};

/** Una scheda con una versione sola: la giacenza sta nel riquadro Prodotto. */
$schedaSemplice = new class extends ProductModelResource {
    protected static function currentId(): ?int
    {
        return 1;
    }

    public static function productCount(int $modelId): int
    {
        return 1;
    }

    public static function variantCount(int $modelId): int
    {
        return 1;
    }

    public static function products(int $modelId): array
    {
        return [['id' => 2, 'sku' => 'CAP-1', 'price' => '24.90']];
    }
};

/**
 * Una scheda con l'unità e le giacenze che si vogliono: senza database non
 * ci sarebbero né l'una né le altre.
 */
$schedaMisurata = new class extends ProductModelResource {
    public static string $unita = 'pz';

    /** @var array<int, float> giacenza per id del prodotto */
    public static array $giacenze = [2 => 12.0];

    protected static function currentId(): ?int
    {
        return 1;
    }

    public static function productCount(int $modelId): int
    {
        return count(static::$giacenze);
    }

    public static function variantCount(int $modelId): int
    {
        return 1;
    }

    public static function products(int $modelId): array
    {
        $righe = [];

        foreach (array_keys(static::$giacenze) as $id) {
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
        return array_intersect_key(static::$giacenze, array_flip($productIds));
    }

    public static function vediNumero(float $value): string
    {
        return static::rawNumber($value);
    }
};

/** Il formato numerico di un campo: decimali, simbolo, posizione. */
$formato = static fn (?object $campo): array =>
    (array) (((array) ($campo?->get('context') ?? []))['number'] ?? []);

$giacenzaDi = static function (object $scheda) use ($formato): array {
    foreach ($scheda::formSchema() as $field) {
        if ((string) $field->name === 'product_stock') {
            return $formato($field);
        }
    }

    return [];
};

$colonnaGiacenzaDi = static function (object $scheda) use ($formato): array {
    foreach ($scheda::formSchema() as $field) {
        if ((string) $field->name !== 'products') {
            continue;
        }

        foreach ((array) ((array) $field->get('context'))['columns'] as $colonna) {
            if ((string) ($colonna->name ?? '') === 'stock') {
                return $formato($colonna);
            }
        }
    }

    return [];
};

/** I testi dei RichText di un riquadro, cercati per titolo. */
$scriptDelRiquadro = static function (object $scheda, string $titolo): string {
    $testi = '';

    foreach ($scheda::formLayoutSchema()->components ?? [] as $colonna) {
        foreach ($colonna->components ?? [] as $riquadro) {
            $suo = false;

            foreach ($riquadro->components ?? [] as $dentro) {
                if ($dentro instanceof SectionTitle && $dentro->getText() === $titolo) {
                    $suo = true;
                }

                if ($suo && $dentro instanceof RichText) {
                    $testi .= (string) $dentro->getText();
                }
            }
        }
    }

    return $testi;
};

$colonneVersioni = static function () use ($schedaPiena): array {
    foreach ($schedaPiena::formSchema() as $field) {
        if ((string) $field->name === 'products') {
            // Le colonne di un repeater stanno nel contesto.
            return (array) ((array) $field->get('context'))['columns'];
        }
    }

    return [];
};

$avanzate = static function () use ($schedaPiena): array {
    foreach ($schedaPiena::formSchema() as $field) {
        if ((string) $field->name === 'products') {
            return (array) ((array) $field->get('context'))['advanced'];
        }
    }

    return [];
};

$etichettaAvanzate = static function () use ($schedaPiena): string {
    foreach ($schedaPiena::formSchema() as $field) {
        if ((string) $field->name === 'products') {
            return (string) ((array) $field->get('context'))['advanced_label'];
        }
    }

    return '';
};

$campiDi = static function (object $scheda): array {
    $campi = [];

    foreach ($scheda::formSchema() as $field) {
        $campi[(string) $field->name] = $field;
    }

    return $campi;
};

check('la griglia delle versioni dice anche quante ne hai', function () use ($colonneVersioni) {
    foreach ($colonneVersioni() as $colonna) {
        if ((string) ($colonna->name ?? '') === 'stock') {
            return true;
        }
    }

    return false;
});

check('la giacenza nella griglia si scrive lì', function () use ($colonneVersioni) {
    foreach ($colonneVersioni() as $colonna) {
        if ((string) ($colonna->name ?? '') === 'stock') {
            // Si scrive quanti pezzi ci sono, e il pannello registra il
            // movimento della differenza: niente più pagina a parte.
            return !str_contains((string) $colonna->get('attribute'), 'readonly');
        }
    }

    return false;
});

check('le colonne della riga stanno in undici', function () use ($colonneVersioni, $avanzate) {
    // La dodicesima è la colonna dei bottoni, che il repeater aggiunge da sé:
    // quello che sfora va a capo, ed è il disallineamento che si vedeva.
    // Le colonne nascoste e quelle avanzate non stanno nella riga.
    $totale = 0;

    foreach ($colonneVersioni() as $colonna) {
        $nome = (string) ($colonna->name ?? '');

        if ($colonna->get('helper') === 'hidden' || in_array($nome, $avanzate(), true)) {
            continue;
        }

        // La larghezza sta in `columnSpan['default']`.
        $totale += (int) (((array) ($colonna->columnSpan ?? []))['default'] ?? 0);
    }

    return $totale === 11;
});

check('i codici, lo stato e le foto stanno dietro «compila le informazioni avanzate»', function () use ($avanzate, $etichettaAvanzate) {
    return $avanzate() === ['sku', 'ean', 'active', 'photo']
        && str_contains($etichettaAvanzate(), 'avanzate');
});

check('nel blocco avanzato ogni casella sta in dodici, e le foto le prendono tutte', function () use ($colonneVersioni, $avanzate) {
    $larghezze = [];

    foreach ($colonneVersioni() as $colonna) {
        $nome = (string) ($colonna->name ?? '');

        if (!in_array($nome, $avanzate(), true)) {
            continue;
        }

        $larghezze[$nome] = (int) (((array) ($colonna->columnSpan ?? []))['default'] ?? 0);
    }

    // Il blocco è a tutta larghezza: non c'è nessuna colonna di bottoni da
    // cui difendersi, quindi si conta fino a dodici.
    return $larghezze === ['sku' => 4, 'ean' => 4, 'active' => 4, 'photo' => 12];
});

check('l\'articolo senza varianti ha la sua casella di giacenza, e si scrive', function () use ($campiDi, $schedaSemplice) {
    $campo = $campiDi($schedaSemplice)['product_stock'] ?? null;

    // Con una sede sola il numero non è ambiguo: si scrive quanti pezzi ci
    // sono e il pannello fa il movimento della differenza.
    return $campo !== null
        && $campo->get('helper') === 'number'
        && !str_contains((string) $campo->get('attribute'), 'readonly');
});

check('la domanda sulle varianti c\'è, e nasce spenta', function () use ($campiDi, $schedaSemplice) {
    $campo = $campiDi($schedaSemplice)['has_variants'] ?? null;

    return $campo !== null && $campo->get('helper') === 'toggle';
});

check('con le varianti la casella singola si nasconde', function () use ($campiDi, $schedaPiena) {
    // Sarebbe un comando ambiguo: "quale giacenza?". Il campo resta
    // dichiarato — la risposta si cambia senza ricaricare — ma lo spegne
    // l'interruttore.
    $campo = $campiDi($schedaPiena)['product_stock'] ?? null;

    return $campo !== null
        && str_contains((string) $campo->get('attribute'), 'data-hidden-when="has_variants"');
});

check('la giacenza non è una colonna: non arriva mai al salvataggio', function () use ($schedaSemplice) {
    $valori = $schedaSemplice::mutateRequestValues(
        ['name' => 'Cappello', 'product_stock' => '12'],
        'update'
    );

    return !array_key_exists('product_stock', $valori);
});

check('la giacenza a pezzi è intera, e dice «pz»', function () use ($schedaMisurata, $giacenzaDi, $colonnaGiacenzaDi) {
    $schedaMisurata::$unita = 'pz';
    $schedaMisurata::$giacenze = [2 => 12.0];
    $singola = $giacenzaDi($schedaMisurata);

    $schedaMisurata::$giacenze = [2 => 12.0, 3 => 4.0];
    $griglia = $colonnaGiacenzaDi($schedaMisurata);

    // «20.000» su un articolo a pezzi si leggeva ventimila.
    return ($singola['decimal'] ?? null) === 0
        && ($singola['symbol'] ?? null) === ' pz'
        && ($singola['symbol_placement'] ?? null) === 's'
        && ($griglia['decimal'] ?? null) === 0
        && ($griglia['symbol'] ?? null) === ' pz'
        && ($griglia['symbol_placement'] ?? null) === 's';
});

check('la giacenza a chili ha tre decimali, e dice «kg»', function () use ($schedaMisurata, $giacenzaDi, $colonnaGiacenzaDi) {
    $schedaMisurata::$unita = 'kg';
    $schedaMisurata::$giacenze = [2 => 12.0];
    $singola = $giacenzaDi($schedaMisurata);

    $schedaMisurata::$giacenze = [2 => 12.0, 3 => 4.0];
    $griglia = $colonnaGiacenzaDi($schedaMisurata);

    return ($singola['decimal'] ?? null) === 3
        && ($singola['symbol'] ?? null) === ' kg'
        && ($griglia['decimal'] ?? null) === 3
        && ($griglia['symbol'] ?? null) === ' kg';
});

check('una giacenza con decimali su un\'unità intera non si arrotonda', function () use ($schedaMisurata, $giacenzaDi, $colonnaGiacenzaDi) {
    // 2,5 pz scritti prima: con zero decimali AutoNumeric li arrotonderebbe,
    // e il salvataggio farebbe un movimento di +0,5 che nessuno ha chiesto.
    $schedaMisurata::$unita = 'pz';
    $schedaMisurata::$giacenze = [2 => 2.5];
    $singola = $giacenzaDi($schedaMisurata);

    // Nella griglia basta una riga con i decimali perché la colonna li tenga.
    $schedaMisurata::$giacenze = [2 => 12.0, 3 => 2.5];
    $griglia = $colonnaGiacenzaDi($schedaMisurata);

    return ($singola['decimal'] ?? null) === 3
        && ($singola['symbol'] ?? null) === ' pz'
        && ($griglia['decimal'] ?? null) === 3;
});

check('in creazione la giacenza parte a pezzi', function () use ($formato) {
    foreach (ProductModelResource::formSchema() as $field) {
        if ((string) $field->name === 'product_stock') {
            $numero = $formato($field);

            return ($numero['decimal'] ?? null) === 0 && ($numero['symbol'] ?? null) === ' pz';
        }
    }

    return false;
});

check('con più sedi la giacenza con l\'unità resta da leggere e basta', function () use ($campiDi) {
    $scheda = new class extends ProductModelResource {
        protected static function currentId(): ?int
        {
            return 1;
        }

        public static function products(int $modelId): array
        {
            return [['id' => 2, 'sku' => 'CAP-1', 'price' => '24.90']];
        }

        public static function stockIsWritable(): bool
        {
            return false;
        }

        protected static function modelUnit(int $modelId): string
        {
            return 'kg';
        }
    };

    $campo = $campiDi($scheda)['product_stock'] ?? null;
    $numero = (array) (((array) ($campo?->get('context') ?? []))['number'] ?? []);

    return $campo !== null
        && str_contains((string) $campo->get('attribute'), 'readonly')
        && ($numero['symbol'] ?? null) === ' kg';
});

check('il valore della giacenza arriva ad AutoNumeric col punto', function () use ($schedaMisurata) {
    // Il campo lo formatta AutoNumeric: gli serve il numero grezzo, non
    // quello già scritto all'italiana.
    return $schedaMisurata::vediNumero(3.0) === '3'
        && $schedaMisurata::vediNumero(2.5) === '2.5'
        && $schedaMisurata::vediNumero(1234.125) === '1234.125'
        && $schedaMisurata::vediNumero(0.0) === '0';
});

check('cambiando l\'unità le caselle della giacenza si aggiornano subito', function () use ($schedaMisurata, $scriptDelRiquadro) {
    $schedaMisurata::$unita = 'pz';
    $schedaMisurata::$giacenze = [2 => 12.0];
    $script = $scriptDelRiquadro($schedaMisurata, 'Misure');

    return str_contains($script, 'window.wiStockUnit')
        // Le unità e i loro decimali vengono da una parte sola.
        && str_contains($script, 'data-wi-unit-decimals')
        && str_contains($script, '&quot;kg&quot;:3')
        && str_contains($script, '[name="unit"]')
        // La casella dell'articolo singolo e quelle della griglia…
        && str_contains($script, 'product_stock')
        && str_contains($script, '[stock]')
        // …e il modello delle righe nuove.
        && str_contains($script, 'template')
        && str_contains($script, 'data-wi-number-decimal')
        && str_contains($script, 'data-wi-number-symbol')
        && str_contains($script, 'getAutoNumericElement')
        && str_contains($script, 'decimalPlacesShownOnFocus')
        && str_contains($script, 'currencySymbol')
        // `update()` di AutoNumeric toglierebbe il readonly della casella
        // con più sedi.
        && str_contains($script, 'readOnly');
});

check('lo script dell\'unità c\'è anche in creazione', function () use ($scriptDelRiquadro) {
    $scheda = new class extends ProductModelResource {
    };

    return str_contains($scriptDelRiquadro($scheda, 'Misure'), 'window.wiStockUnit');
});

check('la scheda della versione ha il riquadro del magazzino', fn () =>
    str_contains(strtolower(ProductResource::stockCardTitle()), 'magazzino')
);

summary();
