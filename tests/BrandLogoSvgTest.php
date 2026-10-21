<?php
/** php tests/BrandLogoSvgTest.php — C-UPL-6, il logo SVG dei marchi */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\App\Security\SvgSanitizer;
use Wonder\Plugin\Gestionale\Models\Catalog\Brand;

$logo = null;

foreach (Brand::dataSchema() as $field) {
    if ((string) $field->key === 'logo') {
        $logo = $field;
    }
}

check('il logo del marchio accetta svg', fn () =>
    $logo !== null && in_array('svg', (array) ($logo->schema['extensions'] ?? []), true)
);

// Il modulo non sanifica da sé: lo fa uploadFiles() del core.
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
