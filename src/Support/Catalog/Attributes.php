<?php

namespace Wonder\Plugin\Gestionale\Support\Catalog;

/**
 * Livelli, tipi e valori degli attributi, senza database.
 *
 * Chi assegna un attributo passa di qui: il tipo decide quale colonna del
 * collegamento si riempie, e le altre restano vuote. Così pannello, vetrina e
 * import scrivono le righe allo stesso modo, e la regola si prova senza
 * accendere mezzo sito.
 */
final class Attributes
{
    /**
     * A cosa serve un attributo, detto come lo capisce chi vende.
     *
     * Le chiavi sono quelle di sempre — il database non cambia — ma nessuno
     * deve più indovinare cosa sia un "livello". La scelta si fa una volta per
     * negozio: dentro un sito la stessa opzione si comporta sempre allo stesso
     * modo, e la scheda del prodotto non la nomina mai.
     */
    public const LEVELS = [
        'model' => 'Descrive l\'articolo',
        'variant' => 'Crea versioni con pagina e foto proprie',
        'product' => 'Crea versioni da scegliere nel carrello',
    ];

    /** Come si scrive il suo valore. */
    public const TYPES = [
        'select' => 'Elenco',
        'color' => 'Colore',
        'text' => 'Testo',
        'number' => 'Numero',
    ];

    /** Gruppo di chi non ne dichiara uno. */
    public const DEFAULT_GROUP = 'Generale';

    /** @return array<string, string> */
    public static function levels(): array
    {
        return self::LEVELS;
    }

    /** @return array<string, string> */
    public static function types(): array
    {
        return self::TYPES;
    }

    /** I tipi che pescano da `gst_attribute_values`. */
    public static function usesValues(string $type): bool
    {
        return $type === 'select' || $type === 'color';
    }

    /** Gli attributi che fanno nascere righe da vendere. */
    public static function createsVersions(string $level): bool
    {
        return $level === 'variant' || $level === 'product';
    }

    /**
     * Gli attributi di un livello, nell'ordine in cui arrivano.
     *
     * @param list<array<string, mixed>> $attributes
     * @return list<array<string, mixed>>
     */
    public static function byLevel(array $attributes, string $level): array
    {
        return array_values(array_filter(
            $attributes,
            static fn (array $attribute): bool => (string) ($attribute['level'] ?? '') === $level
        ));
    }

    /**
     * Gli attributi divisi per gruppo, per i riquadri della scheda.
     *
     * @param list<array<string, mixed>> $attributes
     * @return array<string, list<array<string, mixed>>>
     */
    public static function grouped(array $attributes): array
    {
        $groups = [];

        foreach ($attributes as $attribute) {
            $group = trim((string) ($attribute['group_name'] ?? ''));
            $groups[$group === '' ? self::DEFAULT_GROUP : $group][] = $attribute;
        }

        return $groups;
    }

    /**
     * La riga di collegamento per un valore scelto: una sola colonna piena.
     *
     * @param array<string, mixed> $attribute
     * @return array{attribute_value_id: int|null, value_text: string, value_number: float|null}
     */
    public static function assignment(array $attribute, mixed $value): array
    {
        $empty = ['attribute_value_id' => null, 'value_text' => '', 'value_number' => null];
        $type = (string) ($attribute['type'] ?? '');
        $raw = is_string($value) ? trim($value) : $value;

        if ($raw === null || $raw === '') {
            return $empty;
        }

        if (self::usesValues($type)) {
            $id = (int) $raw;

            return $id > 0 ? ['attribute_value_id' => $id] + $empty : $empty;
        }

        if ($type === 'number') {
            $number = str_replace(',', '.', (string) $raw);

            return is_numeric($number)
                ? ['value_number' => (float) $number] + $empty
                : $empty;
        }

        return ['value_text' => (string) $raw] + $empty;
    }

    /**
     * Il valore da far leggere, con l'unità quando c'è.
     *
     * @param array<string, mixed> $attribute
     * @param array<string, mixed> $link riga di collegamento
     * @param array<int, array<string, mixed>> $values valori dell'attributo, per id
     */
    public static function format(array $attribute, array $link, array $values = []): string
    {
        $type = (string) ($attribute['type'] ?? '');
        $unit = trim((string) ($attribute['unit'] ?? ''));

        if (self::usesValues($type)) {
            $id = (int) ($link['attribute_value_id'] ?? 0);

            return (string) ($values[$id]['label'] ?? '');
        }

        if ($type === 'number') {
            $number = $link['value_number'] ?? null;

            if ($number === null || $number === '') {
                return '';
            }

            // Numero all'italiana, senza zeri inutili in coda.
            $text = rtrim(rtrim(number_format((float) $number, 3, ',', ''), '0'), ',');

            return self::withUnit($text, $unit);
        }

        $text = trim((string) ($link['value_text'] ?? ''));

        return $text === '' ? '' : self::withUnit($text, $unit);
    }

    private static function withUnit(string $text, string $unit): string
    {
        return $unit === '' ? $text : $text.' '.$unit;
    }
}
