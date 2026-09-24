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
     * Scrive i valori arrivati dal form, niente per gli attributi lasciati
     * vuoti.
     *
     * Un attributo a valori (elenco, colore, fantasia, icona) può averne più
     * d'uno: il suo input può essere un id solo o un elenco di id, e diventa
     * una riga per valore. Le righe dei valori ancora scelti restano come
     * sono, quelle dei valori tolti e quelle doppie spariscono, quelle dei
     * valori nuovi nascono. Testo e numero restano a una riga sola: se ce ne
     * fossero di più si tiene la prima e le altre si cancellano.
     *
     * @param list<array<string, mixed>> $attributes attributi di quel livello
     * @param array<int|string, mixed> $input valore scelto (o elenco di valori), per id dell'attributo
     * @return int righe create o aggiornate: per gli attributi a valori contano
     *             solo le righe nuove (quelle rimaste non si toccano), per
     *             testo e numero la riga scritta, nuova o riscritta
     */
    public static function save(string $level, int $parentId, array $attributes, array $input): int
    {
        $modelClass = self::modelClass($level);
        $key = self::parentKey($level);
        $existing = self::rows($level, $parentId);
        $written = 0;

        foreach ($attributes as $attribute) {
            $id = (int) ($attribute['id'] ?? 0);

            if ($id === 0) {
                continue;
            }

            $rows = $existing[$id] ?? [];

            if (Attributes::usesValues((string) ($attribute['type'] ?? ''))) {
                $written += self::syncValues($modelClass, $key, $parentId, $id, $rows, self::valueIds($input[$id] ?? null));

                continue;
            }

            $row = array_shift($rows);

            // Le righe in più di un attributo a valore unico non le legge
            // nessuno: via.
            foreach ($rows as $extra) {
                $modelClass::delete((int) $extra['id']);
            }

            $assignment = Attributes::assignment($attribute, $input[$id] ?? null);
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
     * I collegamenti di una riga, per id dell'attributo: uno per attributo, il
     * primo quando ce n'è più d'uno. Per chi legge un valore solo (opzioni,
     * versioni); per tutti i valori c'è `rows()`.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function read(string $level, int $parentId): array
    {
        return array_map(static fn (array $rows): array => $rows[0], self::rows($level, $parentId));
    }

    /**
     * Tutti i collegamenti di una riga, raggruppati per id dell'attributo e in
     * ordine di id: un attributo a valori può averne più d'uno.
     *
     * @return array<int, list<array<string, mixed>>>
     */
    public static function rows(string $level, int $parentId): array
    {
        $modelClass = self::modelClass($level);
        $rows = $modelClass::find([self::parentKey($level) => $parentId, 'deleted' => 'false']);

        if (!is_array($rows) || $rows === []) {
            return [];
        }

        $rows = isset($rows['id']) ? [$rows] : array_values(array_filter($rows, 'is_array'));
        usort($rows, static fn (array $a, array $b): int => (int) ($a['id'] ?? 0) <=> (int) ($b['id'] ?? 0));
        $byAttribute = [];

        foreach ($rows as $row) {
            $byAttribute[(int) ($row['attribute_id'] ?? 0)][] = $row;
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
     * Un collegamento può essere una riga sola (come da `read()`) o l'elenco
     * delle righe di quell'attributo (come da `rows()`): i valori si mettono
     * in fila, separati da una virgola, saltando quelli vuoti.
     *
     * @param list<array<string, mixed>> $attributes
     * @param array<int, array<string, mixed>|list<array<string, mixed>>> $links
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

            $rows = array_is_list($link) ? $link : [$link];
            $texts = [];

            foreach ($rows as $row) {
                $text = is_array($row) ? Attributes::format($attribute, $row, $values) : '';

                if ($text !== '') {
                    $texts[] = $text;
                }
            }

            $text = implode(', ', $texts);

            if ($text === '') {
                continue;
            }

            $described[(string) ($attribute['name'] ?? '')] = $text;
        }

        return $described;
    }

    /**
     * Allinea le righe di un attributo a valori all'elenco voluto.
     *
     * @param class-string $modelClass
     * @param list<array<string, mixed>> $rows righe già scritte, in ordine di id
     * @param list<int> $wanted id dei valori scelti
     * @return int righe create
     */
    private static function syncValues(string $modelClass, string $key, int $parentId, int $attributeId, array $rows, array $wanted): int
    {
        $kept = [];

        foreach ($rows as $row) {
            $valueId = (int) ($row['attribute_value_id'] ?? 0);

            if (in_array($valueId, $wanted, true) && !isset($kept[$valueId])) {
                $kept[$valueId] = true;

                continue;
            }

            // Valore tolto, riga doppia o riga senza valore.
            $modelClass::delete((int) $row['id']);
        }

        $written = 0;

        foreach ($wanted as $valueId) {
            if (isset($kept[$valueId])) {
                continue;
            }

            $result = $modelClass::create([
                $key => $parentId,
                'attribute_id' => $attributeId,
                'attribute_value_id' => $valueId,
                'value_text' => '',
                'value_number' => null,
            ]);

            $written += !empty($result->success) ? 1 : 0;
        }

        return $written;
    }

    /**
     * Gli id dei valori scelti, senza doppioni: accetta un id solo o un
     * elenco, e scarta quello che non è un id valido.
     *
     * @return list<int>
     */
    private static function valueIds(mixed $input): array
    {
        $items = is_array($input) ? $input : [$input];
        $ids = [];

        foreach ($items as $item) {
            $item = is_string($item) ? trim($item) : $item;

            if ((!is_int($item) && !is_string($item)) || !ctype_digit((string) $item)) {
                continue;
            }

            $id = (int) $item;

            if ($id > 0 && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /** @return array{table: string, model: class-string, key: string} */
    private static function level(string $level): array
    {
        return self::LEVELS[$level]
            ?? throw new RuntimeException("Livello di attributo sconosciuto: {$level}.");
    }
}
