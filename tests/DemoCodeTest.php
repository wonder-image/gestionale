<?php
/** php tests/DemoCodeTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Models\Catalog\Attribute;
use Wonder\Plugin\Gestionale\Models\Catalog\Brand;
use Wonder\Plugin\Gestionale\Models\Catalog\Category;
use Wonder\Plugin\Gestionale\Models\Catalog\Package;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Catalog\Tag;
use Wonder\Plugin\Gestionale\Models\Contacts\Contact;
use Wonder\Plugin\Gestionale\Seeding\DemoCode;
use Wonder\Plugin\Gestionale\Support\Catalog\Slug;
use Wonder\Plugin\Gestionale\Support\Contacts\Contacts;

/** Un Model finto: `Slug::unique()` gli chiede solo `find()`. */
final class TabellaFinta
{
    /** @var list<array{slug: string, deleted: string}> */
    public static array $righe = [];

    public static function find(array $condition, int $limit): mixed
    {
        foreach (self::$righe as $riga) {
            if ($riga['slug'] === $condition['slug'] && $riga['deleted'] === $condition['deleted']) {
                return $riga;
            }
        }

        return [];
    }
}

check('il segno è prefisso, demo- e riferimento', fn () =>
    DemoCode::make('cat_', 'abbigliamento') === 'cat_demo-abbigliamento'
    && DemoCode::make('cat_', 'Magliette e felpe') === 'cat_demo-magliette-e-felpe'
    && DemoCode::make('tag_', 'Novità') === 'tag_demo-novita'
);

check('senza riferimento o con un prefisso storto non nasce un segno', function () {
    foreach ([['cat_', ''], ['cat_', ' - '], ['cat', 'x'], ['', 'x'], ['Cat-', 'x']] as [$prefisso, $ref]) {
        try {
            DemoCode::make($prefisso, $ref);

            return false;
        } catch (InvalidArgumentException) {
        }
    }

    return true;
});

check('ogni entità dei dati di prova usa il suo prefisso', fn () =>
    DemoCode::forModel(Brand::class, 'maglificio-aurora') === 'bra_demo-maglificio-aurora'
    && DemoCode::forModel(Category::class, 'accessori') === 'cat_demo-accessori'
    && DemoCode::forModel(Tag::class, 'saldi') === 'tag_demo-saldi'
    && DemoCode::forModel(Attribute::class, 'colore') === 'att_demo-colore'
    && DemoCode::forModel(Package::class, 'scatola-media') === 'pkg_demo-scatola-media'
    && DemoCode::forModel(ProductModel::class, 'cappello-di-lana') === 'mod_demo-cappello-di-lana'
    && DemoCode::forModel(Contact::class, 'bianchi') === 'con_demo-bianchi'
);

check('il segno si riconosce, e se ne legge il riferimento', fn () =>
    DemoCode::is('cat_demo-abbigliamento')
    && DemoCode::is('con_demo-rossi-abbigliamento')
    && DemoCode::ref('cat_demo-magliette-e-felpe') === 'magliette-e-felpe'
    && !DemoCode::is('cat_demo-')
    && !DemoCode::is('demo-abbigliamento')
    && !DemoCode::is('cat_demo-Abbigliamento')
    && !DemoCode::is('')
    && DemoCode::ref('cat_k3x9d2a') === ''
);

check('un codice vero non ha mai la forma del segno', function () {
    // Come li fa `Code::make()`: prefisso e sette caratteri senza trattino.
    for ($i = 0; $i < 500; $i++) {
        $codice = 'cat_';

        for ($j = 0; $j < 7; $j++) {
            $codice .= 'abcdefghijklmnopqrstuvwxyz0123456789'[random_int(0, 35)];
        }

        if (DemoCode::is($codice)) {
            return false;
        }
    }

    return !DemoCode::is('cat_demoabc') && !DemoCode::is('bra_demo123');
});

check('lo stesso nome, con maiuscole, spazi ed entità diverse', fn () =>
    DemoCode::sameName('Magliette e felpe', 'Magliette E Felpe')
    && DemoCode::sameName('  Novità ', 'novit&agrave;')
    && DemoCode::sameName('Busta  imbottita', 'busta imbottita')
    && !DemoCode::sameName('Accessori', 'Accessorio')
    && !DemoCode::sameName('', '')
);

check('prima la riga col segno, anche se ce n\'è una vera con lo stesso nome', function () {
    $righe = [
        ['id' => 4, 'code' => 'cat_k3x9d2a', 'name' => 'Accessori'],
        ['id' => 9, 'code' => 'cat_demo-accessori', 'name' => 'Accessori rinominata'],
    ];

    return DemoCode::pick($righe, 'cat_demo-accessori', 'Accessori') === ['id' => 9, 'demo' => true];
});

check('senza segno si usa la riga vera con lo stesso nome, e resta vera', function () {
    $righe = [
        ['id' => 3, 'code' => 'tag_a1b2c3d', 'name' => 'Novità'],
        ['id' => 4, 'code' => 'tag_q9w8e7r', 'name' => 'Saldi'],
    ];

    return DemoCode::pick($righe, 'tag_demo-saldi', 'saldi') === ['id' => 4, 'demo' => false];
});

check('un altro dato di prova con lo stesso nome non si riusa', function () {
    $righe = [['id' => 7, 'code' => 'cat_demo-altro', 'name' => 'Accessori']];

    return DemoCode::pick($righe, 'cat_demo-accessori', 'Accessori') === null;
});

check('senza segno né nome uguale la riga va creata', fn () =>
    DemoCode::pick([['id' => 1, 'code' => 'bra_x1y2z3w', 'name' => 'Altro marchio']], 'bra_demo-maglificio-aurora', 'Maglificio Aurora') === null
    && DemoCode::pick([], 'bra_demo-maglificio-aurora', 'Maglificio Aurora') === null
);

check('una scheda vera si riconosce dal nome da mostrare', function () {
    $righe = [['id' => 12, 'code' => 'con_7h6g5f4', 'type' => 'private', 'name' => 'Luca', 'surname' => 'Bianchi']];
    $prova = ['type' => 'private', 'name' => 'Luca', 'surname' => 'Bianchi'];

    return DemoCode::pick($righe, 'con_demo-bianchi', Contacts::displayName($prova), [Contacts::class, 'displayName'])
        === ['id' => 12, 'demo' => false];
});

check('la pulizia tocca solo righe col segno o con un vecchio nome', fn () =>
    DemoCode::ours(Category::class, 'cat_demo-accessori', 'Accessori')
    && DemoCode::ours(Category::class, 'cat_k3x9d2a', 'Prova Magliette')
    && DemoCode::ours(ProductModel::class, 'mod_k3x9d2a', 'Prova Cappello Di Lana')
    && DemoCode::ours(Tag::class, 'tag_k3x9d2a', 'Prova Novit&agrave;')
    // La riga vera riusata per nome non ha segno né vecchio nome: resta.
    && !DemoCode::ours(Category::class, 'cat_k3x9d2a', 'Accessori')
    // Un vecchio nome vale solo per la sua entità.
    && !DemoCode::ours(Brand::class, 'bra_k3x9d2a', 'Prova Magliette')
);

check('i vecchi nomi sono tutti quelli con «Prova » davanti, uno per riga creata', function () {
    $nomi = array_merge(...array_values(DemoCode::LEGACY_NAMES));

    foreach ($nomi as $nome) {
        if (!str_starts_with($nome, 'Prova ')) {
            return false;
        }
    }

    return count($nomi) === 19 && count(array_unique($nomi)) === 19;
});

check('le note dicono quante righe e quali', fn () =>
    DemoCode::keptNote([]) === ''
    && DemoCode::keptNote([DemoCode::label(Category::class, 'Accessori')])
        === 'Resta al suo posto 1 dato di prova ancora in uso: categoria «Accessori».'
    && DemoCode::keptNote(['categoria «Accessori»', 'marchio «Maglificio Aurora»'])
        === 'Restano al loro posto 2 dati di prova ancora in uso: categoria «Accessori», marchio «Maglificio Aurora».'
    && str_contains(DemoCode::reusedNote([DemoCode::label(Tag::class, 'Saldi')]), '1 dato già presente')
    && DemoCode::label(Tag::class, 'Novit&agrave;') === 'tag «Novità»'
);

check('lo slug libero salta quelli presi, anche dalle righe cancellate', function () {
    TabellaFinta::$righe = [
        ['slug' => 'accessori', 'deleted' => 'false'],
        ['slug' => 'accessori-2', 'deleted' => 'true'],
    ];

    return Slug::unique('Accessori', TabellaFinta::class) === 'accessori-3'
        && Slug::unique('Magliette e felpe', TabellaFinta::class) === 'magliette-e-felpe'
        && Slug::unique('Novità', TabellaFinta::class) === 'novita'
        && Slug::unique('  ', TabellaFinta::class) === '';
});

summary();
