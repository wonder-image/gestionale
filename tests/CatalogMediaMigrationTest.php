<?php
/** php tests/CatalogMediaMigrationTest.php — i file di categorie, tag, marchi e selezioni diventano media della libreria (nessun database). */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Migrations\CatalogMediaMigration as Migration;
use Wonder\Plugin\Gestionale\Models\Catalog\Brand;
use Wonder\Plugin\Gestionale\Models\Catalog\Category;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductSelection;
use Wonder\Plugin\Gestionale\Support\Catalog\CatalogMediaPlan as Plan;

check('è dichiarata nel manifest', function () {
    $migrazioni = (array) (json_decode((string) file_get_contents(__DIR__.'/../module.json'), true)['database']['migrations_after_tables'] ?? []);

    return in_array(Migration::class, $migrazioni, true);
});

/** Una voce del piano per un campo di un Model, con le sue righe e i file della sua cartella. */
$fonte = static function (string $model, string $campo, array $righe, array $file): array {
    $config = $model::mediaFields()[$campo];

    return [
        'table' => $model::$table,
        'field' => $campo,
        'columns' => $config['columns'],
        'slug' => $config['slug'],
        'alt' => $config['alt'],
        'dirs' => [$config['legacy'] => $file],
        'rows' => $righe,
    ];
};

$categorie = $fonte(Category::class, 'image', [
    ['id' => '1', 'slug' => 'scarpe', 'name' => 'Scarpe', 'image' => '["scarpe.jpg"]', 'image_id' => null],
    ['id' => '2', 'slug' => 'borse', 'name' => 'Borse &amp; zaini', 'image' => '[]', 'image_id' => null],
    ['id' => '3', 'slug' => 'cinture', 'name' => 'Cinture', 'image' => '', 'image_id' => null],
], ['scarpe.jpg', 'scarpe-480.jpg', 'scarpe.webp']);

check('una categoria con la foto: un\'immagine con lo slug del modello, il nome e il testo alternativo della riga', function () use ($categorie) {
    $piano = Plan::plan([$categorie]);

    return $piano['clear'] === [] && $piano['skipped'] === []
        && $piano['create'] === [[
            'table' => 'gst_categories',
            'id' => 1,
            'field' => 'image',
            'column' => 'image_id',
            'type' => 'image',
            'source' => 'app/gestionale/categorie/catalogo/categorie/scarpe.jpg',
            'target' => 'images/categoria-scarpe.jpg',
            'slug' => 'categoria-scarpe',
            'name' => 'Scarpe',
            'alt' => 'Scarpe',
        ]];
});

check('righe senza file non entrano nel piano', fn () =>
    !Plan::needed(Plan::plan([$fonte(Category::class, 'image', [
        ['id' => '2', 'slug' => 'borse', 'name' => 'Borse', 'image' => '[]', 'image_id' => null],
        ['id' => '3', 'slug' => 'cinture', 'name' => 'Cinture', 'image' => null, 'image_id' => null],
    ], [])]))
);

check('il logo di un marchio: svg è un\'icona, png un\'immagine, ognuno nella sua colonna e nella sua cartella', function () use ($fonte) {
    $piano = Plan::plan([$fonte(Brand::class, 'logo', [
        ['id' => '5', 'slug' => 'acme', 'name' => 'Acme', 'logo' => '["acme.svg"]', 'logo_id' => null, 'logo_svg_id' => null],
        ['id' => '6', 'slug' => 'beta', 'name' => 'Beta', 'logo' => '["beta.png"]', 'logo_id' => null, 'logo_svg_id' => null],
    ], ['acme.svg', 'beta.png'])]);

    return array_column($piano['create'], 'type', 'id') === [5 => 'icon', 6 => 'image']
        && array_column($piano['create'], 'column', 'id') === [5 => 'logo_svg_id', 6 => 'logo_id']
        && array_column($piano['create'], 'target', 'id') === [5 => 'icons/logo-acme.svg', 6 => 'images/logo-beta.png']
        && array_column($piano['create'], 'alt', 'id') === [5 => 'Logo Acme', 6 => 'Logo Beta'];
});

check('la selezione ha due campi: immagine e sfondo, con nomi diversi; lo sfondo nasce senza testo alternativo', function () use ($fonte) {
    $riga = ['id' => '9', 'slug' => 'home', 'name' => 'Home', 'title' => 'In evidenza', 'image' => '["home.jpg"]', 'background' => '["home-sfondo.jpg"]', 'image_id' => null, 'background_id' => null];
    $file = ['home.jpg', 'home-sfondo.jpg'];
    $piano = Plan::plan([
        $fonte(ProductSelection::class, 'image', [$riga], $file),
        $fonte(ProductSelection::class, 'background', [$riga], $file),
    ]);

    return array_column($piano['create'], 'target') === ['images/selezione-home.jpg', 'images/selezione-home-sfondo.jpg']
        && array_column($piano['create'], 'column') === ['image_id', 'background_id']
        && array_column($piano['create'], 'alt') === ['In evidenza', ''];
});

check('uno slug già di un altro media prende il -2', function () use ($categorie) {
    $piano = Plan::plan([$categorie], ['categoria-scarpe']);

    return $piano['create'][0]['slug'] === 'categoria-scarpe-2'
        && $piano['create'][0]['target'] === 'images/categoria-scarpe-2.jpg';
});

check('un file diverso con quel nome in libreria non si sovrascrive: il media prende un altro nome', function () use ($categorie) {
    $piano = Plan::plan([$categorie], [], ['images' => ['categoria-scarpe.jpg']]);
    $conMisure = Plan::plan([$categorie], [], ['images' => ['categoria-scarpe-480.webp']]);

    return $piano['create'][0]['slug'] === 'categoria-scarpe-2'
        // Anche le misure e il webp di un altro file occupano il nome.
        && $conMisure['create'][0]['slug'] === 'categoria-scarpe-2';
});

check('una copia identica lasciata da un giro interrotto si riusa', function () use ($categorie) {
    $confronti = [];
    $piano = Plan::plan(
        [$categorie],
        [],
        ['images' => ['categoria-scarpe.jpg']],
        static function (string $source, string $target) use (&$confronti): bool {
            $confronti[] = [$source, $target];

            return true;
        }
    );

    return $piano['create'][0]['slug'] === 'categoria-scarpe'
        && $confronti === [['app/gestionale/categorie/catalogo/categorie/scarpe.jpg', 'images/categoria-scarpe.jpg']];
});

check('due righe con lo stesso slug di partenza non si pestano i piedi', function () use ($fonte) {
    // Stesso modello di slug, due tabelle diverse non capita; capita invece
    // una riga che arriva a uno slug già prenotato nello stesso giro.
    $piano = Plan::plan([$fonte(Category::class, 'image', [
        ['id' => '1', 'slug' => 'scarpe', 'name' => 'Scarpe', 'image' => '["a.jpg"]', 'image_id' => null],
        ['id' => '2', 'slug' => 'Scarpe', 'name' => 'Scarpe bis', 'image' => '["b.jpg"]', 'image_id' => null],
    ], ['a.jpg', 'b.jpg'])]);

    return array_column($piano['create'], 'slug') === ['categoria-scarpe', 'categoria-scarpe-2'];
});

check('le righe che non si possono migrare restano come sono, con il motivo', function () use ($fonte) {
    $piano = Plan::plan([$fonte(Category::class, 'image', [
        ['id' => '1', 'slug' => 'a', 'name' => 'A', 'image' => '["sparita.jpg"]', 'image_id' => null],
        ['id' => '2', 'slug' => 'b', 'name' => 'B', 'image' => '["foto.gif"]', 'image_id' => null],
        ['id' => '3', 'slug' => 'c', 'name' => 'C', 'image' => '["https://cdn.esempio.it/c.jpg"]', 'image_id' => null],
        ['id' => '4', 'slug' => 'd', 'name' => 'D', 'image' => '["../../altro/d.jpg"]', 'image_id' => null],
        // Una categoria non ha un campo per le icone.
        ['id' => '5', 'slug' => 'e', 'name' => 'E', 'image' => '["e.svg"]', 'image_id' => null],
    ], ['foto.gif', 'd.jpg', 'e.svg'])]);

    return $piano['create'] === []
        && array_column($piano['skipped'], 'reason', 'id') === [
            1 => Plan::MISSING,
            2 => Plan::EXTENSION,
            3 => Plan::EXTERNAL,
            4 => Plan::MISSING,
            5 => Plan::EXTENSION,
        ];
});

check('una riga che ha già il suo media svuota solo la colonna di prima', function () use ($fonte) {
    $piano = Plan::plan([$fonte(Category::class, 'image', [
        ['id' => '1', 'slug' => 'a', 'name' => 'A', 'image' => '["a.jpg"]', 'image_id' => '44'],
    ], ['a.jpg'])]);

    return $piano['create'] === [] && $piano['skipped'] === []
        && $piano['clear'] === [['table' => 'gst_categories', 'id' => 1, 'field' => 'image']];
});

check('il nome e il testo alternativo restano come sono salvati; lo slug no', fn () =>
    Plan::fill('Logo {name}', ['name' => 'Borse &amp; zaini'], false) === 'Logo Borse &amp; zaini'
    && Plan::fill('categoria-{slug}', ['slug' => 'Borse &amp; Zaini'], true) === 'categoria-borse-zaini'
    && Plan::fill('{title}', [], false) === ''
);

// ── Esecuzione ───────────────────────────────────────────────────────────────

check('niente da fare, niente da riferire', fn () =>
    Migration::apply(['create' => [], 'clear' => [], 'skipped' => []], static fn () => true, static fn () => true, static fn () => true, static fn () => null) === []
);

check('si copia, si salva e solo dopo si preparano le misure', function () use ($categorie) {
    $passi = [];
    $esito = Migration::apply(
        Plan::plan([$categorie]),
        static function (string $source, string $target) use (&$passi): bool {
            $passi[] = 'copia '.$source.' → '.$target;

            return true;
        },
        static function (array $step) use (&$passi): bool {
            $passi[] = 'salva '.$step['slug'];

            return true;
        },
        static fn (): bool => true,
        static function (array $step) use (&$passi): void {
            $passi[] = 'misure '.$step['target'];
        }
    );

    return $esito === ['migrated' => 1]
        && $passi === [
            'copia app/gestionale/categorie/catalogo/categorie/scarpe.jpg → images/categoria-scarpe.jpg',
            'salva categoria-scarpe',
            'misure images/categoria-scarpe.jpg',
        ];
});

check('se la copia non riesce la riga non si tocca; se non si salva, niente misure', function () use ($categorie) {
    $salvati = 0;
    $misure = 0;
    $senzaCopia = Migration::apply(
        Plan::plan([$categorie]),
        static fn (): bool => false,
        static function () use (&$salvati): bool {
            $salvati++;

            return true;
        },
        static fn (): bool => true,
        static function () use (&$misure): void {
            $misure++;
        }
    );
    $senzaSalvare = Migration::apply(
        Plan::plan([$categorie]),
        static fn (): bool => true,
        static fn (): bool => false,
        static fn (): bool => true,
        static function () use (&$misure): void {
            $misure++;
        }
    );

    return $salvati === 0 && $misure === 0
        && $senzaCopia === ['skipped' => ['gst_categories#1.image' => 'copia del file non riuscita']]
        && $senzaSalvare === ['skipped' => ['gst_categories#1.image' => 'media non salvato']];
});

check('le righe saltate dal piano finiscono nel resoconto dell\'update', function () use ($fonte) {
    $piano = Plan::plan([$fonte(Category::class, 'image', [
        ['id' => '1', 'slug' => 'a', 'name' => 'A', 'image' => '["sparita.jpg"]', 'image_id' => null],
        ['id' => '2', 'slug' => 'b', 'name' => 'B', 'image' => '["b.jpg"]', 'image_id' => '7'],
    ], [])]);

    return Migration::apply($piano, static fn () => true, static fn () => true, static fn () => true, static fn () => null)
        === ['cleared' => 1, 'skipped' => ['gst_categories#1.image' => Plan::MISSING]];
});

// ── Senza database: la migrazione non parte se non c'è dove lavorare ─────────

check('fuori dal sito (senza ROOT) la migrazione non fa niente', function () {
    if (defined('ROOT')) {
        return true;
    }

    $connessione = (new ReflectionClass(mysqli::class))->newInstanceWithoutConstructor();

    return Migration::run($connessione) === [];
});

summary();
