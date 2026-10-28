<?php

namespace Wonder\Plugin\Gestionale\Support\Catalog;

use Throwable;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Catalog\Faq;
use Wonder\Plugin\Gestionale\Support\Promotions\ProductScope;
use Wonder\Plugin\Gestionale\Support\Promotions\ScopeMatcher;
use Wonder\Plugin\Gestionale\Support\RequestMemo;

/**
 * Le FAQ visibili, pronte per il sito: quelle di un articolo (il selettore
 * della FAQ, con le regole di `ScopeMatcher`) e quelle della home.
 *
 * Ogni voce è `question` in testo semplice e `answer` in HTML dell'editor.
 * L'ordine è `importance` (prima, normale, in fondo), poi `position`.
 * Senza la funzionalità `faqs` non ce n'è nessuna.
 */
final class Faqs
{
    private const IMPORTANCE = ['high' => 0, 'normal' => 1, 'low' => 2];

    /** @return list<array{id: int, question: string, answer: string}> */
    public static function forModel(int $modelId): array
    {
        if ($modelId <= 0 || !self::active()) {
            return [];
        }

        $facts = ProductScope::modelFacts($modelId);
        $faqs = [];

        foreach (self::visible() as $row) {
            if (ScopeMatcher::matches(ProductScope::of('faq', (int) $row['id']), $facts)) {
                $faqs[] = self::item($row);
            }
        }

        return $faqs;
    }

    /** @return list<array{id: int, question: string, answer: string}> */
    public static function home(): array
    {
        $faqs = [];

        foreach (self::visible() as $row) {
            if (($row['show_on_home'] ?? 'false') === 'true') {
                $faqs[] = self::item($row);
            }
        }

        return $faqs;
    }

    public static function active(): bool
    {
        return Gestionale::feature('faqs');
    }

    /**
     * Le FAQ visibili, già in ordine.
     *
     * @return list<array<string, mixed>>
     */
    public static function visible(): array
    {
        // Funzionalità spenta: le FAQ restano salvate, il sito non le legge.
        if (!self::active()) {
            return [];
        }

        return RequestMemo::remember(Faq::MEMO_GROUP, 'visible', static function (): array {
            try {
                $found = Faq::find(['visible' => 'true', 'deleted' => 'false']);
            } catch (Throwable) {
                // Tabella non ancora creata: il sito resta in piedi senza FAQ.
                return [];
            }

            $rows = !is_array($found) || $found === []
                ? []
                : (array_key_exists('id', $found) ? [$found] : array_values(array_filter($found, 'is_array')));

            return self::sort($rows);
        });
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public static function sort(array $rows): array
    {
        usort($rows, static fn (array $a, array $b): int => [
            self::IMPORTANCE[$a['importance'] ?? 'normal'] ?? 1,
            (int) ($a['position'] ?? 0),
            (int) ($a['id'] ?? 0),
        ] <=> [
            self::IMPORTANCE[$b['importance'] ?? 'normal'] ?? 1,
            (int) ($b['position'] ?? 0),
            (int) ($b['id'] ?? 0),
        ]);

        return $rows;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: int, question: string, answer: string}
     */
    public static function item(array $row): array
    {
        return [
            'id' => (int) ($row['id'] ?? 0),
            // La domanda è salvata con le entità: si decodifica e la vista la escapa.
            'question' => trim(html_entity_decode((string) ($row['question'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8')),
            'answer' => (string) ($row['answer'] ?? ''),
        ];
    }
}
