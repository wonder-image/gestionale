<?php

namespace Wonder\Plugin\Gestionale\Support\Catalog;

use Wonder\Plugin\Gestionale\Extensions\Extensions;

/**
 * I blocchi del sito che possono mostrare una selezione: il dato che il sito
 * legge per scegliere il componente. Il gestionale non disegna niente.
 *
 * Ogni blocco ha una chiave, un'etichetta, i campi standard che usa fra
 * {@see STANDARD_FIELDS} (le colonne della selezione) e i campi in più,
 * salvati in `block_meta`:
 *
 * ```php
 * 'carousel' => [
 *     'label' => 'Carosello',
 *     'fields' => ['title', 'subtitle'],
 *     'meta' => ['autoplay' => ['label' => 'Scorrimento', 'type' => 'select', 'options' => ['no' => 'A mano', 'si' => 'Automatico']]],
 * ]
 * ```
 *
 * Tipi dei campi in più: `text`, `textarea`, `url`, `select` (con `options`)
 * e i riferimenti al catalogo {@see REF_TYPES}, salvati come slug; `default`
 * è il valore di serie, per una select una delle sue voci. `when` lega il
 * campo al valore di un altro: `['cta_link' => 'custom']`.
 * Un modulo aggiunge i suoi con {@see register()}, il sito con l'hook
 * `selectionBlocks()` della sua estensione, che riceve tutti i blocchi e li
 * restituisce, anche cambiati.
 */
final class SelectionBlocks
{
    public const DEFAULT = 'default';

    /** Le colonne della selezione che un blocco può usare. */
    public const STANDARD_FIELDS = ['title', 'subtitle', 'text', 'image', 'background'];

    /** I campi che scelgono una voce del catalogo: il valore è lo slug. */
    public const REF_TYPES = ['category', 'tag', 'brand', 'product'];

    public const META_TYPES = ['text', 'textarea', 'url', 'select', ...self::REF_TYPES];

    /** Dove porta il bottone dei blocchi elenco e swiper. */
    public const CTA_LINKS = [
        'all' => 'Tutti i prodotti',
        'custom' => 'Link personalizzato',
        'category' => 'Categoria',
        'tag' => 'Tag',
        'brand' => 'Marchio',
        'product' => 'Prodotto',
    ];

    /** Il bottone: testo, azione e la voce da aprire, che dipende dall'azione. */
    private const CTA_META = [
        'cta_label' => ['label' => 'Testo del bottone', 'type' => 'text'],
        'cta_link' => ['label' => 'Azione', 'type' => 'select', 'options' => self::CTA_LINKS, 'default' => 'all'],
        'cta_url' => ['label' => 'Link', 'type' => 'text', 'when' => ['cta_link' => 'custom']],
        'cta_category' => ['label' => 'Categoria', 'type' => 'category', 'when' => ['cta_link' => 'category']],
        'cta_tag' => ['label' => 'Tag', 'type' => 'tag', 'when' => ['cta_link' => 'tag']],
        'cta_brand' => ['label' => 'Marchio', 'type' => 'brand', 'when' => ['cta_link' => 'brand']],
        'cta_product' => ['label' => 'Prodotto', 'type' => 'product', 'when' => ['cta_link' => 'product']],
    ];

    /** Il prefisso dei campi in più nel form: `block_meta_cta_label`. */
    public const META_INPUT_PREFIX = 'block_meta_';

    private const BUILTIN = [
        self::DEFAULT => [
            'label' => 'Elenco di articoli',
            'fields' => ['title', 'subtitle', 'text'],
            'meta' => self::CTA_META,
        ],
        'swiper' => [
            'label' => 'Swiper di articoli',
            'fields' => ['title', 'subtitle', 'text'],
            'meta' => self::CTA_META,
        ],
        // Il primo articolo: foto 1:1 da un lato, card dall'altro sullo sfondo.
        'spotlight' => [
            'label' => 'Articolo in primo piano',
            'fields' => ['title', 'subtitle', 'text', 'image', 'background'],
            'meta' => [
                'image_side' => ['label' => 'Lato della foto', 'type' => 'select', 'options' => ['left' => 'Sinistra', 'right' => 'Destra'], 'default' => 'left'],
            ],
        ],
    ];

    /** @var array<string, array<string, mixed>> quelli dei moduli */
    private static array $registered = [];

    /** @var array<string, array{label:string,fields:list<string>,meta:array<string, array<string, mixed>>}>|null */
    private static ?array $blocks = null;

    /**
     * Aggiunge (o sostituisce) un blocco, per i moduli.
     *
     * @param list<string> $fields fra {@see STANDARD_FIELDS}
     * @param array<string, array<string, mixed>> $meta
     */
    public static function register(string $key, string $label, array $fields = ['title', 'subtitle', 'text'], array $meta = []): void
    {
        self::$registered[trim($key)] = ['label' => $label, 'fields' => $fields, 'meta' => $meta];
        self::$blocks = null;
    }

    /** Dimentica i blocchi letti (e quelli registrati, con `$registered`): per i test. */
    public static function reset(bool $registered = false): void
    {
        self::$blocks = null;

        if ($registered) {
            self::$registered = [];
        }
    }

    /** @return array<string, array{label:string,fields:list<string>,meta:array<string, array<string, mixed>>}> */
    public static function all(): array
    {
        if (self::$blocks !== null) {
            return self::$blocks;
        }

        $blocks = Extensions::filter('selectionBlocks', array_merge(self::BUILTIN, self::$registered));
        $out = [];

        foreach (is_array($blocks) ? $blocks : [] as $key => $block) {
            $key = self::key($key);

            if ($key !== '' && is_array($block)) {
                $out[$key] = self::clean($key, $block);
            }
        }

        // Il blocco standard c'è sempre: è quello delle selezioni di prima.
        $out[self::DEFAULT] ??= self::clean(self::DEFAULT, self::BUILTIN[self::DEFAULT]);

        return self::$blocks = [self::DEFAULT => $out[self::DEFAULT]] + $out;
    }

    /** @return array<string, string> le voci del select */
    public static function options(): array
    {
        return array_map(static fn (array $block): string => $block['label'], self::all());
    }

    public static function normalize(mixed $value): string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';

        return isset(self::all()[$value]) ? $value : self::DEFAULT;
    }

    /** @return list<string> i campi standard del blocco */
    public static function fields(string $block): array
    {
        return self::all()[self::normalize($block)]['fields'];
    }

    /** @return array<string, array<string, mixed>> i campi in più del blocco */
    public static function metaFields(string $block): array
    {
        return self::all()[self::normalize($block)]['meta'];
    }

    /**
     * I blocchi che usano un campo standard o un campo in più: la regola di
     * visibilità del form.
     *
     * @return list<string>
     */
    public static function blocksWith(string $field): array
    {
        $out = [];

        foreach (self::all() as $key => $block) {
            if (in_array($field, $block['fields'], true) || isset($block['meta'][$field])) {
                $out[] = $key;
            }
        }

        return $out;
    }

    /**
     * Tutti i campi in più dei blocchi, una volta per chiave: vale la prima
     * definizione.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function allMetaFields(): array
    {
        $out = [];

        foreach (self::all() as $block) {
            $out += $block['meta'];
        }

        return $out;
    }

    /**
     * I valori in più del blocco, solo le sue chiavi, in testo; una select
     * accetta solo le sue voci. Le chiavi vuote o non valide hanno il valore
     * di serie.
     *
     * @param array<string, mixed> $values
     * @return array<string, string>
     */
    public static function cleanMeta(string $block, array $values): array
    {
        $out = [];

        foreach (self::metaFields($block) as $key => $field) {
            $value = $values[$key] ?? '';
            $value = is_scalar($value) ? trim((string) $value) : '';

            if ($field['type'] === 'select' && !isset($field['options'][$value])) {
                $value = '';
            }

            $out[$key] = $value !== '' ? $value : $field['default'];
        }

        // Un campo legato a un altro vale solo quando l'altro ha il valore giusto.
        foreach (self::metaFields($block) as $key => $field) {
            foreach ($field['when'] as $whenKey => $values) {
                if (!in_array($out[$whenKey] ?? '', $values, true)) {
                    $out[$key] = $field['default'];
                }
            }
        }

        return $out;
    }

    /**
     * `block_meta` come arriva dal modello: array già letto o JSON salvato,
     * anche con le entità del salvataggio. La colonna è `json` (utf8mb4): con la
     * connessione latin1 MySQL la ridà in cp1252, che va riportato in UTF-8.
     *
     * @return array<string, mixed>
     */
    public static function decodeMeta(mixed $meta): array
    {
        if (is_string($meta) && $meta !== '') {
            if (!mb_check_encoding($meta, 'UTF-8')) {
                $meta = mb_convert_encoding($meta, 'UTF-8', 'Windows-1252');
            }


            $decoded = json_decode($meta, true);
            $meta = is_array($decoded) ? $decoded : json_decode(html_entity_decode($meta, ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
        }

        return is_array($meta) ? $meta : [];
    }

    /**
     * @param array<string, mixed> $block
     * @return array{label:string,fields:list<string>,meta:array<string, array<string, mixed>>}
     */
    private static function clean(string $key, array $block): array
    {
        $label = trim((string) ($block['label'] ?? ''));
        $fields = array_values(array_intersect(self::STANDARD_FIELDS, array_map('strval', (array) ($block['fields'] ?? []))));
        $meta = [];

        foreach ((array) ($block['meta'] ?? []) as $metaKey => $field) {
            // Diventa il nome di un campo del form: niente trattini.
            $metaKey = str_contains((string) $metaKey, '-') ? '' : self::key($metaKey);

            if ($metaKey === '' || !is_array($field)) {
                continue;
            }

            $type = in_array($field['type'] ?? 'text', self::META_TYPES, true) ? (string) ($field['type'] ?? 'text') : 'text';
            $options = $type === 'select' ? array_map('strval', (array) ($field['options'] ?? [])) : [];
            $default = is_scalar($field['default'] ?? null) ? trim((string) $field['default']) : '';
            $when = [];

            foreach ((array) ($field['when'] ?? []) as $whenKey => $values) {
                $whenKey = self::key($whenKey);

                if ($whenKey !== '') {
                    $when[$whenKey] = array_values(array_map('strval', (array) $values));
                }
            }

            $meta[$metaKey] = [
                'label' => trim((string) ($field['label'] ?? $metaKey)),
                'type' => $type,
                'options' => $options,
                'default' => $type === 'select' && !isset($options[$default]) ? '' : $default,
                'when' => $when,
            ];
        }

        return ['label' => $label !== '' ? $label : $key, 'fields' => $fields, 'meta' => $meta];
    }

    /** Chiavi da nome di campo e da classe CSS: minuscole, cifre, `_` e `-`. */
    private static function key(mixed $key): string
    {
        $key = is_scalar($key) ? trim((string) $key) : '';

        return preg_match('/^[a-z][a-z0-9_-]{0,49}$/', $key) ? $key : '';
    }
}
