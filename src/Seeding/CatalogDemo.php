<?php

namespace Wonder\Plugin\Gestionale\Seeding;

use Wonder\Plugin\Gestionale\Console\Demo\DemoData;
use Wonder\Plugin\Gestionale\Models\Catalog\Attribute;
use Wonder\Plugin\Gestionale\Models\Catalog\AttributeValue;
use Wonder\Plugin\Gestionale\Models\Catalog\Brand;
use Wonder\Plugin\Gestionale\Models\Catalog\Category;
use Wonder\Plugin\Gestionale\Models\Catalog\Tag;
use Wonder\Plugin\Gestionale\Support\Catalog\Attributes;
use Wonder\Plugin\Gestionale\Support\Catalog\Code;
use Wonder\Plugin\Gestionale\Support\Catalog\ProductAttributes;
use Wonder\Plugin\Gestionale\Support\Catalog\Slug;
use Wonder\Plugin\Gestionale\Support\Codes;

/**
 * Dati di prova del catalogo: un marchio, un piccolo albero di categorie, due
 * tag e due attributi con i loro valori.
 *
 * Servono a provare le pagine su un sito vuoto e, più avanti, a dare un posto
 * ai prodotti finti. Tutte le righe portano il prefisso `Prova` nel nome, così
 * si riconoscono a colpo d'occhio e `--fresh` sa cosa togliere.
 */
final class CatalogDemo
{
    /** Chiave nel registro dei dati di prova. */
    public const KEY = 'catalogo-base';

    /** Riconosce le righe create da qui. */
    private const PREFIX = 'Prova ';

    public static function register(): void
    {
        DemoData::register(
            self::KEY,
            'Catalogo: marchi, categorie, tag e attributi',
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

        // Il colore cambia l'aspetto della variante, la taglia distingue i
        // prodotti dentro una variante: i due casi che servono al piano 3.
        $created += self::attribute('Colore', [
            'slug' => 'prova-colore',
            'type' => 'color',
            'level' => 'variant',
            'group_name' => '',
            'position' => 1,
        ], [
            ['label' => 'Blu', 'color' => '#1f4ed8'],
            ['label' => 'Rosso', 'color' => '#c1121f'],
            ['label' => 'Nero', 'color' => '#111111'],
        ]);

        $created += self::attribute('Taglia', [
            'slug' => 'prova-taglia',
            'type' => 'select',
            'level' => 'product',
            'group_name' => 'Misure',
            'position' => 2,
        ], [
            ['label' => 'S'],
            ['label' => 'M'],
            ['label' => 'L'],
            ['label' => 'XL'],
        ]);

        return $created;
    }

    /**
     * Un attributo di prova con i suoi valori.
     *
     * @param array<string, mixed> $values
     * @param list<array<string, mixed>> $rows
     * @return int righe create, attributo e valori insieme
     */
    private static function attribute(string $name, array $values, array $rows): int
    {
        $name = self::PREFIX.$name;

        if (self::idOf(Attribute::class, $name) > 0) {
            return 0;
        }

        $result = Attribute::create(array_merge([
            'code' => Code::make(Attribute::class, Codes::ATTRIBUTE),
            'name' => $name,
            'unit' => '',
            'is_filterable' => 'true',
            'is_visible' => 'true',
        ], $values));

        if (empty($result->success)) {
            return 0;
        }

        $created = 1;
        $attributeId = self::idOf(Attribute::class, $name);
        $position = 1;

        foreach ($rows as $row) {
            $value = AttributeValue::create(array_merge([
                'attribute_id' => $attributeId,
                'color' => '',
                'position' => $position++,
            ], $row));

            $created += !empty($value->success) ? 1 : 0;
        }

        return $created;
    }

    /** @return int righe tolte */
    public static function clear(): int
    {
        $removed = 0;

        foreach (self::rows(Attribute::class) as $row) {
            if (!str_starts_with((string) ($row['name'] ?? ''), self::PREFIX)) {
                continue;
            }

            // Prima chi lo usa: un prodotto che punta a questo attributo
            // impedirebbe di toglierlo, e la pulizia morirebbe a metà.
            foreach (Attributes::LEVELS as $level => $ignored) {
                $model = ProductAttributes::modelClass($level);

                foreach (self::rows($model) as $link) {
                    if ((int) ($link['attribute_id'] ?? 0) === (int) $row['id']) {
                        $model::delete((int) $link['id']);
                    }
                }
            }

            // Poi i valori: la chiave esterna non lascia andare l'attributo.
            foreach (self::rows(AttributeValue::class) as $value) {
                if ((int) ($value['attribute_id'] ?? 0) !== (int) $row['id']) {
                    continue;
                }

                $removed += !empty(AttributeValue::delete((int) $value['id'])->success) ? 1 : 0;
            }

            $removed += !empty(Attribute::delete((int) $row['id'])->success) ? 1 : 0;
        }

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
