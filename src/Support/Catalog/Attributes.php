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
     * L'uso di un attributo, detto come lo capisce chi vende.
     *
     * Le chiavi sono quelle di sempre — il database non cambia — ma nessuno
     * deve più indovinare cosa sia un "livello". La scelta si fa una volta per
     * negozio: dentro un sito lo stesso attributo si comporta sempre allo
     * stesso modo, e la scheda del prodotto non lo nomina mai.
     *
     * Sono le voci di un attributo a valori: per Testo e Numero le parole
     * cambiano, vedi {@see levelsFor()}.
     */
    public const LEVELS = [
        'model' => 'Scheda tecnica dell\'articolo',
        'variant' => 'Opzione con foto proprie',
        'product' => 'Opzione da scegliere',
    ];

    /**
     * Le voci di un Testo o di un Numero.
     *
     * Non fanno nascere opzioni — non c'è un elenco da cui sceglierle — e
     * quindi niente foto proprie. Quello che resta è dove si scrive il valore:
     * una volta per l'articolo, o su ogni opzione (il peso di ogni formato).
     */
    public const FREE_LEVELS = [
        'model' => 'Scheda tecnica dell\'articolo',
        'product' => 'Scheda tecnica di ogni opzione',
    ];

    /** Come si scrive il suo valore. */
    public const TYPES = [
        'select' => 'Elenco',
        'color' => 'Colore',
        'pattern' => 'Fantasia',
        'icon' => 'Icona',
        'text' => 'Testo',
        'number' => 'Numero',
    ];

    /**
     * I tipi che si scelgono da un elenco di valori.
     *
     * Un Elenco ha solo il nome del valore, un Colore anche il codice, una
     * Fantasia anche l'immagine, un'Icona un segno della raccolta o
     * un'immagine sua.
     */
    public const VALUE_TYPES = ['select', 'color', 'pattern', 'icon'];

    /** I tipi che si scrivono sul prodotto, e che hanno un'unità di misura. */
    public const UNIT_TYPES = ['number', 'text'];

    /** Gruppo di chi non ne dichiara uno. */
    public const DEFAULT_GROUP = 'Generale';

    /** @return array<string, string> */
    public static function levels(): array
    {
        return self::LEVELS;
    }

    /**
     * Gli usi che hanno senso per quel tipo, con le parole giuste per lui.
     *
     * @return array<string, string>
     */
    public static function levelsFor(string $type): array
    {
        return self::usesUnit($type) ? self::FREE_LEVELS : self::LEVELS;
    }

    /** Vero quando quel tipo può avere quell'uso. */
    public static function acceptsLevel(string $type, string $level): bool
    {
        return array_key_exists($level, self::levelsFor($type));
    }

    /** L'uso da leggere, con le parole del tipo. */
    public static function levelLabel(string $type, string $level): string
    {
        return self::levelsFor($type)[$level] ?? self::LEVELS[$level] ?? '';
    }

    /** @return array<string, string> */
    public static function types(): array
    {
        return self::TYPES;
    }

    /** I tipi che pescano da `gst_attribute_values`. */
    public static function usesValues(string $type): bool
    {
        return in_array($type, self::VALUE_TYPES, true);
    }

    /** I tipi per cui l'unità di misura vuol dire qualcosa. */
    public static function usesUnit(string $type): bool
    {
        return in_array($type, self::UNIT_TYPES, true);
    }

    /** Gli attributi che fanno nascere opzioni in vendita. */
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
     * Il segno che accompagna il nome di un valore: `image`, `icon` o
     * `color`, nella forma che leggono le opzioni dei campi del core.
     *
     * Lo decide il tipo dell'attributo, non le colonne piene: cambiando tipo
     * i codici e le immagini di prima restano sul valore, e un Elenco che è
     * stato un Colore non deve mostrare pallini. Sull'Icona il file caricato
     * vince sul nome: chi l'ha caricato ha scelto quello.
     *
     * @param array<string, mixed> $value riga di `gst_attribute_values`
     * @param string $imageUrl l'indirizzo della sua immagine, se ne ha una
     * @return array<string, string> vuoto quando il tipo non ne ha
     */
    public static function valueVisual(string $type, array $value, string $imageUrl = ''): array
    {
        $imageUrl = trim($imageUrl);

        return match ($type) {
            'color' => trim((string) ($value['color'] ?? '')) !== ''
                ? ['color' => trim((string) $value['color'])]
                : [],
            'pattern' => $imageUrl !== '' ? ['image' => $imageUrl] : [],
            'icon' => match (true) {
                $imageUrl !== '' => ['image' => $imageUrl],
                trim((string) ($value['icon'] ?? '')) !== '' => ['icon' => trim((string) $value['icon'])],
                default => [],
            },
            default => [],
        };
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
