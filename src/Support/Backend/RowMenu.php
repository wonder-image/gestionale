<?php

namespace Wonder\Plugin\Gestionale\Support\Backend;

use Wonder\Elements\Components\Dropdown;

/**
 * Il menu ⋯ in fondo a una riga di tabella, con il `Dropdown` del core.
 *
 * Le tabelle della scheda (righe, pagamenti, resi, spedizioni) cambiano lo
 * stato di una riga da qui: ogni voce apre la finestra di conferma che la
 * pagina disegna, o porta a un indirizzo. Senza voci non c'è menu.
 */
final class RowMenu
{
    /**
     * @param list<array{label: string, modal?: string, href?: string, icon?: string, danger?: bool}> $items
     *        `modal` è l'id della finestra da aprire; altrimenti `href`
     */
    public static function render(array $items): string
    {
        $items = array_values(array_filter($items, static fn (array $item): bool => trim((string) ($item['label'] ?? '')) !== ''));

        if ($items === []) {
            return '';
        }

        $menu = Dropdown::make()
            ->icon('bi bi-three-dots')
            ->caret(false)
            ->variant('link')
            ->size('sm')
            ->align('end')
            ->toggleClass('text-dark')
            ->toggleAttributes(['title' => 'Azioni', 'aria-label' => 'Azioni']);

        foreach ($items as $item) {
            $options = array_filter([
                'icon' => (string) ($item['icon'] ?? ''),
                'variant' => !empty($item['danger']) ? 'danger' : '',
            ]);

            if (($item['modal'] ?? '') !== '') {
                $menu->action((string) $item['label'], ['data-bs-toggle' => 'modal', 'data-bs-target' => '#'.$item['modal']], $options);
            } else {
                $menu->item((string) $item['label'], (string) ($item['href'] ?? '#'), $options);
            }
        }

        $menu->schema('inline', true);

        return $menu->render('bootstrap');
    }
}
