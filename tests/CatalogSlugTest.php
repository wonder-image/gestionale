<?php
/** php tests/CatalogSlugTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Support\Catalog\Slug;

/** Un Model finto che rispetta tutte le condizioni di `find()`, come la tabella. */
final class VariantiFinte
{
    /** @var list<array<string, string|int>> */
    public static array $righe = [];

    public static function find(array $condition, int $limit): mixed
    {
        foreach (self::$righe as $riga) {
            if (array_intersect_assoc($condition, $riga) === $condition) {
                return $riga;
            }
        }

        return [];
    }
}

check('la base è lo slug che scriverebbe il Model, entità comprese', fn () =>
    Slug::base('Blu Notte') === 'blu-notte'
    && Slug::base('Novit&agrave;') === 'novita'
    && Slug::base('a__b') === 'a-b'
    && Slug::base('★') === ''
);

check('il primo slug libero salta quelli presi', fn () =>
    Slug::firstFree('blu', static fn (string $slug): bool => in_array($slug, ['blu', 'blu-2'], true)) === 'blu-3'
    && Slug::firstFree('blu', static fn (string $slug): bool => false) === 'blu'
);

check('lo slug è unico nel modello, non nella tabella, e conta le righe cancellate', function () {
    VariantiFinte::$righe = [
        ['product_model_id' => 1, 'slug' => 'blu', 'deleted' => 'false'],
        ['product_model_id' => 1, 'slug' => 'blu-2', 'deleted' => 'true'],
        ['product_model_id' => 2, 'slug' => 'rosso', 'deleted' => 'false'],
    ];

    return Slug::uniqueWithin('Blu', VariantiFinte::class, ['product_model_id' => 1]) === 'blu-3'
        && Slug::uniqueWithin('Rosso', VariantiFinte::class, ['product_model_id' => 1]) === 'rosso'
        && Slug::uniqueWithin('Blu', VariantiFinte::class, ['product_model_id' => 2]) === 'blu';
});

check('un valore fatto solo di simboli resta raggiungibile, un nome vuoto no', function () {
    VariantiFinte::$righe = [
        ['product_model_id' => 1, 'slug' => 'variante', 'deleted' => 'false'],
    ];

    return Slug::uniqueWithin('★', VariantiFinte::class, ['product_model_id' => 1]) === 'variante-2'
        && Slug::uniqueWithin('★', VariantiFinte::class, ['product_model_id' => 3]) === 'variante'
        && Slug::uniqueWithin('  ', VariantiFinte::class, ['product_model_id' => 1]) === '';
});

summary();
