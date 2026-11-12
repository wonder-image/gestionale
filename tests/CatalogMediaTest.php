<?php
/** php tests/CatalogMediaTest.php — categorie, tag, marchi e selezioni tengono le immagini nella libreria Media (nessun database). */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\App\Media\MediaFile;
use Wonder\App\Media\MediaInputs;
use Wonder\App\Media\MediaLibrary;
use Wonder\App\Media\MediaTypes;
use Wonder\App\Media\MediaUsages;
use Wonder\App\Media\MemoryMediaStore;
use Wonder\App\Media\MemoryMediaUsageStore;
use Wonder\App\ResourceSchema\Inputs\InputMedia;
use Wonder\Plugin\Gestionale\Models\Catalog\Brand;
use Wonder\Plugin\Gestionale\Models\Catalog\Category;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductSelection;
use Wonder\Plugin\Gestionale\Models\Catalog\Tag;
use Wonder\Plugin\Gestionale\Resources\Catalog\BrandResource;
use Wonder\Plugin\Gestionale\Resources\Catalog\CategoryResource;
use Wonder\Plugin\Gestionale\Resources\Catalog\ProductSelectionResource;
use Wonder\Plugin\Gestionale\Resources\Catalog\TagResource;
use Wonder\Plugin\Gestionale\Support\Catalog\CatalogMedia;

/** @return array<string, object> le colonne della tabella, per nome */
$colonne = static function (string $model): array {
    $c = [];

    foreach ($model::tableSchema() as $colonna) {
        $c[(string) $colonna->name] = $colonna;
    }

    return $c;
};

/** @return array<string, object> i campi dei dati, per chiave */
$dati = static function (string $model): array {
    $d = [];

    foreach ($model::dataSchema() as $campo) {
        $d[(string) $campo->key] = $campo;
    }

    return $d;
};

/** @return array<string, object> i campi del form, per nome */
$form = static function (string $resource): array {
    $f = [];

    foreach ($resource::formSchema() as $campo) {
        $f[(string) $campo->name] = $campo;
    }

    return $f;
};

$attesi = [
    Category::class => [CategoryResource::class, ['image_id' => [MediaTypes::IMAGE, 'categoria-{slug}', '{name}']]],
    Tag::class => [TagResource::class, ['image_id' => [MediaTypes::IMAGE, 'tag-{slug}', '{name}']]],
    Brand::class => [BrandResource::class, [
        'logo_id' => [MediaTypes::IMAGE, 'logo-{slug}', 'Logo {name}'],
        'logo_svg_id' => [MediaTypes::ICON, 'logo-{slug}', 'Logo {name}'],
    ]],
    ProductSelection::class => [ProductSelectionResource::class, [
        'image_id' => [MediaTypes::IMAGE, 'selezione-{slug}', '{title}'],
        'background_id' => [MediaTypes::IMAGE, 'selezione-{slug}-sfondo', ''],
    ]],
];

check('i quattro Model del catalogo sono quelli che la migrazione conosce', fn () =>
    CatalogMedia::MODELS === array_keys($attesi)
);

foreach ($attesi as $model => [$resource, $campi]) {
    $nome = substr((string) strrchr($model, '\\'), 1);

    check("{$nome}: la colonna con l'id è un intero che può restare vuoto", function () use ($model, $campi, $colonne, $dati) {
        foreach (array_keys($campi) as $colonna) {
            $c = $colonne($model)[$colonna] ?? null;
            $d = $dati($model)[$colonna] ?? null;

            if ($c === null || $d === null
                || stripos((string) $c->getSchema('type'), 'int') === false
                || $c->getSchema('null') !== true
                || (string) $d->type !== 'number') {
                return false;
            }
        }

        $dichiarate = CatalogMedia::columns($model);
        $attese = array_keys($campi);
        sort($dichiarate);
        sort($attese);

        return $dichiarate === $attese;
    });

    check("{$nome}: il form ha il campo media del core, con slug e testo alternativo presi dalla riga", function () use ($resource, $campi, $form) {
        foreach ($campi as $colonna => [$tipo, $slug, $alt]) {
            $campo = $form($resource)[$colonna] ?? null;

            if (!$campo instanceof InputMedia || $campo->mediaConfig() !== ['type' => $tipo, 'slug' => $slug, 'alt' => $alt]) {
                return false;
            }
        }

        return true;
    });

    check("{$nome}: il campo ha la sua etichetta e il vecchio campo file non è più nel form", function () use ($model, $resource, $campi, $form) {
        foreach (array_keys($campi) as $colonna) {
            if (trim((string) $resource::getLabel($colonna)) === '') {
                return false;
            }
        }

        return array_intersect(array_keys($model::mediaFields()), array_keys($form($resource))) === [];
    });

    check("{$nome}: la colonna file di prima resta nella tabella, deprecata", function () use ($model, $colonne) {
        return array_diff(array_keys($model::mediaFields()), array_keys($colonne($model))) === [];
    });

    check("{$nome}: la cartella dei file di prima è quella della pagina più quella del campo", function () use ($model, $resource, $dati) {
        foreach ($model::mediaFields() as $campo => $config) {
            $dir = trim((string) $dati($model)[$campo]->getSchema('dir'), '/');

            if ($config['legacy'] !== trim($resource::path(), '/').'/'.$dir) {
                return false;
            }
        }

        return true;
    });
}

check('un campo che non ha quel tipo di media si rifiuta', function () {
    try {
        CatalogMedia::input(Category::class, 'image', MediaTypes::ICON);
    } catch (InvalidArgumentException) {
        return true;
    }

    return false;
});

check('i modelli si riempiono con i valori della riga: slug e testo alternativo del media creato dal campo', function () {
    MediaLibrary::useStore($libreria = new MemoryMediaStore([
        ['id' => 4, 'type' => 'image', 'slug' => 'categoria-scarpe', 'name' => 'Altra', 'file' => 'categoria-scarpe.jpg'],
    ]));
    MediaUsages::useStore($registro = new MemoryMediaUsageStore());

    try {
        $inputs = new MediaInputs(Category::$table, ['image_id' => CatalogMedia::input(Category::class, 'image')->mediaConfig()]);
        $file = tempnam(sys_get_temp_dir(), 'jpg');
        $valori = $inputs->resolve([
            'name' => 'Scarpe',
            'slug' => 'scarpe',
            'image_id' => '',
            'image_id__wi_media_upload' => ['name' => ['IMG_0012.JPG'], 'type' => ['image/jpeg'], 'tmp_name' => [$file], 'error' => [UPLOAD_ERR_OK], 'size' => [10]],
        ]);
        @unlink($file);
        $id = (int) $valori['image_id'];
        $inputs->sync(7, $valori);

        return $inputs->errors() === []
            && $id > 4
            // Lo slug era già di un altro media: prende il -2.
            && $libreria->rows[$id]['slug'] === 'categoria-scarpe-2'
            && $libreria->rows[$id]['alt'] === 'Scarpe'
            && MediaUsages::inUse($id)
            && MediaUsages::owners($id) === [['table' => Category::$table, 'id' => '7', 'column' => 'image_id']];
    } finally {
        MediaLibrary::useStore(null);
        MediaUsages::useStore(null);
    }
});

// ── Lettura ──────────────────────────────────────────────────────────────────

$libreria = new MemoryMediaStore([
    ['id' => 10, 'type' => 'image', 'slug' => 'categoria-scarpe', 'name' => 'Scarpe', 'alt' => 'Scarpe', 'file' => 'categoria-scarpe.jpg'],
    ['id' => 11, 'type' => 'image', 'slug' => 'logo-acme', 'name' => 'Acme', 'alt' => 'Logo Acme', 'file' => 'logo-acme.png'],
    ['id' => 12, 'type' => 'icon', 'slug' => 'logo-acme-2', 'name' => 'Acme', 'alt' => 'Logo Acme', 'file' => 'logo-acme-2.svg'],
    ['id' => 13, 'type' => 'image', 'slug' => 'selezione-home-sfondo', 'name' => 'Home', 'file' => 'selezione-home-sfondo.webp'],
]);
MediaLibrary::useStore($libreria);

check('una riga con il suo media si legge dalla libreria, per id', function () {
    $media = Category::media(['id' => 1, 'image_id' => '10', 'image' => ''], 'image');

    return $media instanceof MediaFile
        && $media->fileName() === 'categoria-scarpe.jpg'
        && $media->alt === 'Scarpe'
        && Category::mediaId(['image_id' => '10'], 'image') === 10
        && Category::media(['image_id' => null], 'image') === null
        && Category::media(['image_id' => '999'], 'image') === null;
});

check('il logo di un marchio: il vettoriale se c\'è, altrimenti il raster', fn () =>
    Brand::mediaId(['logo_id' => '11', 'logo_svg_id' => '12'], 'logo') === 12
    && Brand::media(['logo_id' => '11', 'logo_svg_id' => '12'], 'logo')?->type === MediaTypes::ICON
    && Brand::media(['logo_id' => '11', 'logo_svg_id' => null], 'logo')?->fileName() === 'logo-acme.png'
    && Brand::mediaId(['logo_id' => 0, 'logo_svg_id' => ''], 'logo') === 0
);

check('un elenco si legge con una lettura sola', function () use ($libreria) {
    $libreria->reads = 0;
    $media = Category::mediaMany([
        'a' => ['id' => 1, 'image_id' => '10'],
        'b' => ['id' => 2, 'image_id' => null],
        'c' => ['id' => 3, 'image_id' => '10'],
        'd' => ['id' => 4, 'image_id' => '404'],
    ], 'image');

    return $libreria->reads === 1
        && array_keys($media) === ['a', 'c']
        && $media['a']->id === 10;
});

check('una riga non ancora migrata ripiega sul file di prima', fn () =>
    Category::legacyMediaUrl(['image' => '["scarpe.jpg"]'], 'image') === '/assets/upload/app/gestionale/categorie/catalogo/categorie/scarpe.jpg'
    && Category::imageUrl(['id' => 1, 'image' => '["scarpe.jpg"]']) === '/assets/upload/app/gestionale/categorie/catalogo/categorie/scarpe.jpg'
    && Tag::imageUrl(['image' => '["saldi.png"]', 'image_id' => null]) === '/assets/upload/app/gestionale/tag/catalogo/tag/saldi.png'
    && Brand::logoUrl(['logo' => '["acme.svg"]']) === '/assets/upload/app/gestionale/marchi/catalogo/marchi/acme.svg'
    && ProductSelection::imageUrl(['background' => '["home-sfondo.jpg"]'], 'background') === '/assets/upload/app/gestionale/selezioni/catalogo/selezioni/home-sfondo.jpg'
);

check('senza media e senza file di prima non c\'è indirizzo', fn () =>
    Category::imageUrl(['image' => '', 'image_id' => null]) === ''
    && Category::imageUrl(['image' => '[]']) === ''
    && Brand::logoUrl([]) === ''
    && ProductSelection::imageUrl(['image' => '["x.jpg"]'], 'colonna-che-non-esiste') === ''
);

check('una riga migrata non torna al file di prima quando il media viene tolto', fn () =>
    // La migrazione svuota la colonna di prima: tolto il media, non resta niente.
    Category::imageUrl(['image' => '', 'image_id' => null]) === ''
    // E con il media presente la colonna di prima non conta.
    && Category::mediaId(['image' => '["vecchia.jpg"]', 'image_id' => '10'], 'image') === 10
);

check('la selezione ha immagine e sfondo, ognuno il suo media', fn () =>
    ProductSelection::mediaId(['image_id' => null, 'background_id' => '13'], 'background') === 13
    && ProductSelection::mediaId(['image_id' => null, 'background_id' => '13'], 'image') === 0
);

check('i nomi che un campo media manda nella richiesta: l\'id e il file caricato', fn () =>
    CatalogMedia::requestKeys(ProductSelection::class) === [
        'image_id', 'image_id__wi_media_upload', 'background_id', 'background_id__wi_media_upload',
    ]
);

MediaLibrary::useStore(null);

summary();
