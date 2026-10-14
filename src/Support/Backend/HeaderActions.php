<?php

namespace Wonder\Plugin\Gestionale\Support\Backend;

/**
 * I pulsanti in testa a una scheda, raggruppati in un menu.
 *
 * Una scheda con tante azioni (ordine, abbonamento, documento di magazzino)
 * metteva in fila cinque o sei pulsanti: qui diventano le voci di un menu a
 * tendina del core (il descrittore con `items` di `PageActionNormalizer`).
 * Un pulsante solo resta un pulsante: un menu con una voce è un clic in più.
 */
final class HeaderActions
{
    /**
     * @param list<array<string, string>> $buttons i pulsanti come li dà la Resource (`label`, `icon`, `class`, `href`, `onclick`)
     * @return list<array<string, mixed>>
     */
    public static function group(array $buttons, string $label = 'Azioni', string $icon = 'bi-three-dots-vertical'): array
    {
        $buttons = array_values(array_filter($buttons, static fn ($button): bool => is_array($button) && trim((string) ($button['label'] ?? '')) !== ''));

        if (count($buttons) <= 1) {
            return $buttons;
        }

        return [[
            'label' => $label,
            'icon' => $icon,
            'class' => 'btn-outline-dark btn-sm',
            'items' => array_map(static fn (array $button): array => self::item($button), $buttons),
        ]];
    }

    /**
     * Un pulsante come voce del menu: stesso collegamento e stesso clic; il
     * rosso dei pulsanti che annullano resta nel testo della voce.
     *
     * @param array<string, string> $button
     * @return array<string, string>
     */
    public static function item(array $button): array
    {
        return [
            'label' => (string) $button['label'],
            'icon' => (string) ($button['icon'] ?? ''),
            'href' => (string) ($button['href'] ?? ''),
            'onclick' => (string) ($button['onclick'] ?? ''),
            'target' => (string) ($button['target'] ?? ''),
            'class' => str_contains((string) ($button['class'] ?? ''), 'danger') ? 'text-danger' : '',
        ];
    }
}
