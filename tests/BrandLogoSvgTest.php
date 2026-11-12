<?php
/** php tests/BrandLogoSvgTest.php — C-UPL-6, il logo SVG dei marchi */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\App\Media\MediaInputs;
use Wonder\App\Media\MediaLibrary;
use Wonder\App\Media\MediaTypes;
use Wonder\App\Media\MediaUsages;
use Wonder\App\Media\MemoryMediaStore;
use Wonder\App\Media\MemoryMediaUsageStore;
use Wonder\App\Security\SvgSanitizer;
use Wonder\Plugin\Gestionale\Models\Catalog\Brand;
use Wonder\Plugin\Gestionale\Resources\Catalog\BrandResource;

$campi = [];

foreach (BrandResource::formSchema() as $field) {
    $campi[(string) $field->name] = $field;
}

check('il marchio ha due campi logo: l\'immagine e il vettoriale', fn () =>
    ($campi['logo_id'] ?? null)?->mediaConfig()['type'] === MediaTypes::IMAGE
    && ($campi['logo_svg_id'] ?? null)?->mediaConfig()['type'] === MediaTypes::ICON
    && !isset($campi['logo'])
);

check('il logo vettoriale accetta svg, e solo quello', fn () =>
    MediaTypes::extensions(MediaTypes::ICON) === ['svg']
    && !MediaTypes::accepts(MediaTypes::IMAGE, 'logo.svg')
);

check('un svg caricato nel campo vettoriale diventa un\'icona della libreria', function () {
    MediaLibrary::useStore($libreria = new MemoryMediaStore());
    MediaUsages::useStore(new MemoryMediaUsageStore());

    try {
        $inputs = new MediaInputs(Brand::$table, ['logo_svg_id' => ['type' => MediaTypes::ICON, 'slug' => 'logo-{slug}', 'alt' => 'Logo {name}']]);
        $svg = tempnam(sys_get_temp_dir(), 'svg');
        file_put_contents($svg, '<svg xmlns="http://www.w3.org/2000/svg"/>');

        $valori = $inputs->resolve([
            'name' => 'Acme',
            'slug' => 'acme',
            'logo_svg_id' => '',
            'logo_svg_id__wi_media_upload' => ['name' => ['acme.svg'], 'type' => ['image/svg+xml'], 'tmp_name' => [$svg], 'error' => [UPLOAD_ERR_OK], 'size' => [41]],
        ]);
        @unlink($svg);

        $media = $libreria->rows[(int) $valori['logo_svg_id']] ?? null;

        return $inputs->errors() === []
            && $media !== null
            && $media['type'] === MediaTypes::ICON
            && $media['slug'] === 'logo-acme'
            && $media['alt'] === 'Logo Acme';
    } finally {
        MediaLibrary::useStore(null);
        MediaUsages::useStore(null);
    }
});

check('un png nel campo vettoriale si rifiuta, con il motivo', function () {
    MediaLibrary::useStore(new MemoryMediaStore());

    try {
        $inputs = new MediaInputs(Brand::$table, ['logo_svg_id' => ['type' => MediaTypes::ICON, 'slug' => '', 'alt' => '']]);
        $inputs->resolve([
            'logo_svg_id__wi_media_upload' => ['name' => ['acme.png'], 'type' => ['image/png'], 'tmp_name' => ['/tmp/x'], 'error' => [UPLOAD_ERR_OK], 'size' => [10]],
        ]);

        return str_contains((string) ($inputs->errors()['logo_svg_id'] ?? ''), 'svg');
    } finally {
        MediaLibrary::useStore(null);
    }
});

// Il modulo non sanifica da sé: lo fa il core quando salva il media.
check('il core toglie script e handler da un svg caricato', function () {
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><script>alert(2)</script>'
        .'<rect width="10" height="10" onclick="alert(3)"/></svg>';
    $pulito = SvgSanitizer::sanitize($svg);

    return is_string($pulito)
        && stripos($pulito, '<script') === false
        && stripos($pulito, 'onload') === false
        && stripos($pulito, 'onclick') === false
        && stripos($pulito, '<rect') !== false;
});

check('il core rifiuta un svg con entità', fn () =>
    SvgSanitizer::sanitize('<!DOCTYPE svg [<!ENTITY x "y">]><svg xmlns="http://www.w3.org/2000/svg">&x;</svg>') === null
);

summary();
