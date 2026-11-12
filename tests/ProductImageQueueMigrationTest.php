<?php
/** php tests/ProductImageQueueMigrationTest.php — le foto in attesa nella coda del modulo passano a quella del core (nessun database). */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\App\Media\MemoryResizeStore;
use Wonder\App\Media\ResizeQueue;
use Wonder\Plugin\Gestionale\Migrations\ProductImageQueueMigration as Migration;
use Wonder\Plugin\Gestionale\Support\Catalog\ImageSizes;

check('è dichiarata nel manifest', function () {
    $migrazioni = (array) (json_decode((string) file_get_contents(__DIR__.'/../module.json'), true)['database']['migrations_after_tables'] ?? []);

    return in_array(Migration::class, $migrazioni, true);
});

$righe = [
    ['id' => '1', 'file' => '["maglia.jpg"]'],
    ['id' => '2', 'file' => '["spot.mp4"]'],
    ['id' => '3', 'file' => '["sparita.png"]'],
    ['id' => '4', 'file' => '[]'],
    ['id' => '0', 'file' => '["senza-id.jpg"]'],
];
$percorso = static function (array $row): string {
    $nomi = json_decode((string) $row['file'], true) ?: [];

    return $nomi === [] ? '' : '/sito/assets/upload/app/gestionale/prodotti/'.$nomi[0];
};
$esiste = static fn (string $path): bool => !str_contains($path, 'sparita');

check('il piano: una foto si consegna, un video è pronto, un file sparito no', function () use ($righe, $percorso, $esiste) {
    $piano = Migration::plan($righe, $percorso, $esiste);

    return array_column($piano, 'action', 'id') === [
        1 => Migration::QUEUE,
        2 => Migration::READY,
        3 => Migration::MISSING,
        4 => Migration::MISSING,
    ]
        && $piano[0]['path'] === '/sito/assets/upload/app/gestionale/prodotti/maglia.jpg';
});

check('niente righe, niente piano', fn () => Migration::plan([], $percorso, $esiste) === []);

check('una riga cambia stato solo dopo la consegna alla coda del core', function () use ($righe, $percorso, $esiste) {
    $consegnati = [];
    $scritti = [];

    $esito = Migration::apply(
        Migration::plan($righe, $percorso, $esiste),
        static function (string $path) use (&$consegnati): bool {
            $consegnati[] = basename($path);

            return true;
        },
        static function (int $id, string $status, string $error) use (&$scritti): bool {
            $scritti[$id] = [$status, $error];

            return true;
        }
    );

    return $consegnati === ['maglia.jpg']
        && $scritti === [
            1 => ['ready', ''],
            2 => ['ready', ''],
            3 => ['failed', Migration::MISSING_ERROR],
            4 => ['failed', Migration::MISSING_ERROR],
        ]
        && $esito === ['queued' => 1, 'ready' => 1, 'missing' => 2];
});

check('se la coda non prende il file la riga resta in attesa per l\'update dopo', function () use ($righe, $percorso, $esiste) {
    $scritti = [];

    $esito = Migration::apply(
        Migration::plan($righe, $percorso, $esiste),
        static fn (string $path): bool => false,
        static function (int $id, string $status, string $error) use (&$scritti): bool {
            $scritti[] = $id;

            return true;
        }
    );

    return !in_array(1, $scritti, true) && $esito === ['ready' => 1, 'missing' => 2, 'waiting' => 1];
});

check('un piano vuoto non riporta niente', fn () =>
    Migration::apply([], static fn (): bool => true, static fn (): bool => true) === []
);

check('la consegna vera scrive la coda del core una volta sola, anche rilanciata', function () use ($percorso) {
    $store = new MemoryResizeStore(null); // scheduler fermo: si consegna lo stesso
    ResizeQueue::useStore($store);
    ResizeQueue::useRoot('/sito');

    $piano = Migration::plan([['id' => '1', 'file' => '["maglia.jpg"]']], $percorso, static fn (): bool => true);
    $scrivi = static fn (): bool => true;

    try {
        $prima = Migration::apply($piano, ImageSizes::handOver(...), $scrivi);
        $store->rows[1]['status'] = ResizeQueue::READY;
        $seconda = Migration::apply($piano, ImageSizes::handOver(...), $scrivi);
    } finally {
        ResizeQueue::useStore(null);
        ResizeQueue::useRoot(null);
    }

    return $prima === ['queued' => 1] && $seconda === ['queued' => 1]
        && count($store->rows) === 1
        && $store->rows[1]['path'] === 'assets/upload/app/gestionale/prodotti/maglia.jpg'
        && $store->rows[1]['status'] === ResizeQueue::READY;
});

summary();
