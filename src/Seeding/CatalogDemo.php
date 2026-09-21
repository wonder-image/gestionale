<?php

namespace Wonder\Plugin\Gestionale\Seeding;

use Wonder\Plugin\Gestionale\Console\Demo\DemoData;
use Wonder\Plugin\Gestionale\Models\Catalog\Brand;
use Wonder\Plugin\Gestionale\Models\Catalog\Category;
use Wonder\Plugin\Gestionale\Models\Catalog\Tag;
use Wonder\Plugin\Gestionale\Support\Catalog\Code;
use Wonder\Plugin\Gestionale\Support\Catalog\Slug;

/**
 * Dati di prova delle tassonomie: un marchio, un piccolo albero di categorie
 * e due tag.
 *
 * Servono a provare le pagine su un sito vuoto e, più avanti, a dare un posto
 * ai prodotti finti. Tutte le righe portano il prefisso `Prova` nel nome, così
 * si riconoscono a colpo d'occhio e `--fresh` sa cosa togliere.
 */
final class CatalogDemo
{
    /** Chiave nel registro dei dati di prova. */
    public const KEY = 'catalogo-tassonomie';

    /** Riconosce le righe create da qui. */
    private const PREFIX = 'Prova ';

    public static function register(): void
    {
        DemoData::register(
            self::KEY,
            'Catalogo: marchi, categorie e tag',
            static fn (): int => self::create(),
            static fn (): int => self::clear()
        );
    }

    /** @return int righe create */
    public static function create(): int
    {
        $created = 0;
        $created += self::ensure(Brand::class, self::PREFIX.'Marchio', ['position' => 1, 'visible' => 'true']);

        $parent = self::ensure(Category::class, self::PREFIX.'Abbigliamento', ['position' => 1, 'visible' => 'true']);
        $created += $parent;
        $parentId = self::idOf(Category::class, self::PREFIX.'Abbigliamento');

        $created += self::ensure(Category::class, self::PREFIX.'Magliette', [
            'parent_id' => $parentId,
            'position' => 1,
            'visible' => 'true',
        ]);
        $created += self::ensure(Category::class, self::PREFIX.'Accessori', ['position' => 2, 'visible' => 'true']);

        foreach (['Novità', 'Saldi'] as $tag) {
            $created += self::ensure(Tag::class, self::PREFIX.$tag, ['visible' => 'true']);
        }

        return $created;
    }

    /** @return int righe tolte */
    public static function clear(): int
    {
        $removed = 0;

        foreach ([Tag::class, Category::class, Brand::class] as $model) {
            foreach (self::rows($model) as $row) {
                if (!str_starts_with((string) ($row['name'] ?? ''), self::PREFIX)) {
                    continue;
                }

                $result = $model::delete((int) $row['id']);

                if (!empty($result->success)) {
                    $removed++;
                }
            }
        }

        return $removed;
    }

    /** Crea la riga se manca; ritorna 1 quando l'ha creata. */
    private static function ensure(string $model, string $name, array $values): int
    {
        if (self::idOf($model, $name) > 0) {
            return 0;
        }

        $result = $model::create(array_merge($values, [
            'code' => Code::make($model, self::prefixOf($model)),
            'name' => $name,
            'slug' => Slug::make($name, $model::$table),
        ]));

        return !empty($result->success) ? 1 : 0;
    }

    private static function idOf(string $model, string $name): int
    {
        $row = $model::find(['name' => $name, 'deleted' => 'false'], 1);

        return is_array($row) ? (int) ($row['id'] ?? 0) : 0;
    }

    /** @return list<array<string, mixed>> */
    private static function rows(string $model): array
    {
        $rows = $model::find(['deleted' => 'false']);

        if (!is_array($rows) || $rows === []) {
            return [];
        }

        return isset($rows['id']) ? [$rows] : array_values(array_filter($rows, 'is_array'));
    }

    /** Il prefisso del codice di quel Model, letto dal suo schema. */
    private static function prefixOf(string $model): string
    {
        foreach ($model::dataSchema() as $field) {
            if ((string) $field->key === 'code') {
                return (string) (($field->getSchema('unique_code')['prefix']) ?? '');
            }
        }

        return '';
    }
}
