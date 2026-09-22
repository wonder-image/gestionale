<?php
/** php tests/StockInCatalogTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

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

check('la scheda della versione ha il riquadro del magazzino', fn () =>
    str_contains(strtolower(ProductResource::stockCardTitle()), 'magazzino')
);

summary();
