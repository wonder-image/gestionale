<?php

namespace Wonder\Plugin\Gestionale\Support\Catalog;

use RuntimeException;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductAttribute;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModelAttribute;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductVariantAttribute;

/**
 * Leggere e scrivere gli attributi appesi a un modello, a una variante o a un
 * prodotto.
 *
 * I tre livelli hanno tre tabelle gemelle: qui c'è l'unica mappa che dice
 * quale, così aggiungerne uno sarebbe una riga sola. Il valore passa sempre da
 * `Attributes::assignment()`, che riempie una colonna e lascia vuote le altre.
 */
final class ProductAttributes
{
    /** @var array<string, array{table: string, model: class-string, key: string}> */
    private const LEVELS = [
        'model' => [
            'table' => 'gst_product_model_attributes',
            'model' => ProductModelAttribute::class,
            'key' => 'product_model_id',
        ],
        'variant' => [
            'table' => 'gst_product_variant_attributes',
            'model' => ProductVariantAttribute::class,
            'key' => 'product_variant_id',
        ],
        'product' => [
            'table' => 'gst_product_attributes',
            'model' => ProductAttribute::class,
            'key' => 'product_id',
        ],
    ];

    public static function table(string $level): string
    {
        return self::level($level)['table'];
    }

    /** @return class-string */
    public static function modelClass(string $level): string
    {
        return self::level($level)['model'];
    }

    public static function parentKey(string $level): string
    {
        return self::level($level)['key'];
    }

    /**
     * Scrive i valori arrivati dal form: una riga per attributo compilato,
     * niente per quelli lasciati vuoti.
     *
     * @param list<array<string, mixed>> $attributes attributi di quel livello
     * @param array<int|string, mixed> $input valore scelto, per id dell'attributo
     * @return int righe scritte
     */
    public static function save(string $level, int $parentId, array $attributes, array $input): int
    {
        $modelClass = self::modelClass($level);
        $key = self::parentKey($level);
        $existing = self::read($level, $parentId);
        $written = 0;

        foreach ($attributes as $attribute) {
            $id = (int) ($attribute['id'] ?? 0);

            if ($id === 0) {
                continue;
            }

            $assignment = Attributes::assignment($attribute, $input[$id] ?? null);
            $row = $existing[$id] ?? null;
            $empty = $assignment['attribute_value_id'] === null
                && $assignment['value_text'] === ''
                && $assignment['value_number'] === null;

            if ($empty) {
                // Svuotare un attributo significa toglierlo: una riga con tre
                // colonne vuote non racconterebbe niente.
                if ($row !== null) {
                    $modelClass::delete((int) $row['id']);
                }

                continue;
            }

            $values = array_merge($assignment, [$key => $parentId, 'attribute_id' => $id]);

            $result = $row === null
                ? $modelClass::create($values)
                : $modelClass::update($values, (int) $row['id']);

            $written += !empty($result->success) ? 1 : 0;
        }

        return $written;
    }

    /**
     * I collegamenti di una riga, per id dell'attributo.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function read(string $level, int $parentId): array
    {
        $modelClass = self::modelClass($level);
        $rows = $modelClass::find([self::parentKey($level) => $parentId, 'deleted' => 'false']);

        if (!is_array($rows) || $rows === []) {
            return [];
        }

        $rows = isset($rows['id']) ? [$rows] : array_values(array_filter($rows, 'is_array'));
        $byAttribute = [];

        foreach ($rows as $row) {
            $byAttribute[(int) ($row['attribute_id'] ?? 0)] = $row;
        }

        return $byAttribute;
    }

    /**
     * Vero quando quell'attributo sta su almeno un articolo, un colore o
     * un'opzione.
     *
     * Guarda le tre tabelle: l'uso di un attributo decide in quale delle tre
     * finisce il valore, e un uso cambiato dopo lascerebbe quei valori dove
     * nessuno li legge più.
     */
    public static function isUsed(int $attributeId): bool
    {
        if ($attributeId <= 0) {
            return false;
        }

        foreach (array_keys(self::LEVELS) as $level) {
            $found = self::modelClass($level)::find(['attribute_id' => $attributeId, 'deleted' => 'false'], 1);

            if (is_array($found) && $found !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * Nome dell'attributo => valore da leggere. Gli attributi senza valore non
     * compaiono: un elenco di righe vuote non serve a nessuno.
     *
     * @param list<array<string, mixed>> $attributes
     * @param array<int, array<string, mixed>> $links
     * @param array<int, array<string, mixed>> $values
     * @return array<string, string>
     */
    public static function describe(array $attributes, array $links, array $values): array
    {
        $described = [];

        foreach ($attributes as $attribute) {
            $id = (int) ($attribute['id'] ?? 0);
            $link = $links[$id] ?? null;

            if ($link === null) {
                continue;
            }

            $text = Attributes::format($attribute, $link, $values);

            if ($text === '') {
                continue;
            }

            $described[(string) ($attribute['name'] ?? '')] = $text;
        }

        return $described;
    }

    /** @return array{table: string, model: class-string, key: string} */
    private static function level(string $level): array
    {
        return self::LEVELS[$level]
            ?? throw new RuntimeException("Livello di attributo sconosciuto: {$level}.");
    }
}
