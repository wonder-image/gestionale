<?php
/** php tests/StockMovementResourceTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Models\Stock\StockMovement;
use Wonder\Plugin\Gestionale\Resources\Stock\StockMovementResource;
use Wonder\Plugin\Gestionale\Support\Stock\Reasons;

$pagine = StockMovementResource::pageSchema()->toArray();
$colonne = [];

foreach (StockMovementResource::tableSchema() as $colonna) {
    $colonne[] = (string) $colonna->name;
}

$filtri = static function (string $column): array {
    $schema = StockMovementResource::tableLayoutSchema()->toArray();

    foreach ((array) ($schema['custom_filters'] ?? []) as $filtro) {
        if (($filtro['column'] ?? '') === $column) {
            return array_keys((array) ($filtro['array'] ?? []));
        }
    }

    return [];
};

check('l\'elenco dei movimenti ha il suo indirizzo', fn () =>
    StockMovementResource::path() === 'app/gestionale/movimenti'
);

check('il model dell\'elenco è quello dei movimenti', fn () =>
    StockMovementResource::$model === StockMovement::class
);

check('i movimenti si leggono e basta: niente aggiungi, modifica o elimina', function () use ($pagine) {
    $attive = array_keys(array_filter((array) ($pagine['pages'] ?? [])));

    return $attive === ['list'];
});

check('la riga racconta tutto quello che serve', function () use ($colonne) {
    foreach (['creation', 'product_id', 'type', 'reason', 'quantity', 'quantity_after'] as $nome) {
        if (!in_array($nome, $colonne, true)) {
            return false;
        }
    }

    return true;
});

check('le celle si disegnano davvero, con il segno e le etichette', function () {
    // Le colonne con `formatter()` non girano finché qualcuno non le chiama:
    // un metodo che non esiste si scoprirebbe solo aprendo la pagina.
    $riga = [
        'product_id' => 0,
        'type' => 'adjustment',
        'reason' => 'damaged',
        'quantity' => '-3.000',
        'quantity_after' => '7.000',
    ];
    $celle = [];

    foreach (StockMovementResource::tableSchema() as $colonna) {
        $formatter = $colonna->getSchema('formatter');
        $celle[(string) $colonna->name] = is_callable($formatter) ? $formatter($riga) : null;
    }

    return $celle['type'] === 'Rettifica'
        && $celle['reason'] === 'Danneggiato'
        && $celle['quantity'] === '-3'
        && $celle['quantity_after'] === '7'
        && $celle['product_id'] === '—';
});

check('l\'ultimo movimento sta in cima', fn () =>
    StockMovementResource::$orderColumn === 'id'
    && StockMovementResource::$orderDirection === 'DESC'
);

check('il filtro per causale offre le causali vere', fn () =>
    $filtri('reason') === array_keys(Reasons::all())
);

check('il filtro per tipo offre i tipi veri', fn () =>
    $filtri('type') === StockMovement::TYPES
);

check('l\'elenco si filtra su un\'opzione dall\'indirizzo', function () {
    $_GET['versione'] = '42';
    $schema = StockMovementResource::querySchema();
    unset($_GET['versione']);

    return str_contains((string) ($schema['condition'] ?? ''), 'product_id = 42');
});

check('un periodo scritto male non arriva al database', function () {
    $_GET['dal'] = "2026-01-01'; DROP TABLE gst_stock; --";
    $schema = StockMovementResource::querySchema();
    unset($_GET['dal']);

    // Nessun filtro valido: la condizione resta quella di partenza, un array.
    $condizione = $schema['condition'] ?? '';

    return is_array($condizione) && !str_contains(json_encode($condizione), 'DROP');
});

check('il filtro non fa riemergere le righe cancellate', function () {
    $_GET['versione'] = '42';
    $schema = StockMovementResource::querySchema();
    unset($_GET['versione']);

    return str_contains((string) ($schema['condition'] ?? ''), "deleted = 'false'");
});

check('un periodo scritto bene diventa una condizione', function () {
    $_GET['dal'] = '2026-09-01';
    $_GET['al'] = '2026-09-30';
    $schema = StockMovementResource::querySchema();
    unset($_GET['dal'], $_GET['al']);

    $condizione = (string) ($schema['condition'] ?? '');

    return str_contains($condizione, "creation >= '2026-09-01 00:00:00'")
        && str_contains($condizione, "creation <= '2026-09-30 23:59:59'");
});

check('il link dalla scheda porta all\'elenco filtrato', fn () =>
    str_ends_with(StockMovementResource::listUrlFor(7), '?versione=7')
);

summary();
