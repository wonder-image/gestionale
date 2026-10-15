<?php

namespace Wonder\Plugin\Gestionale\Support\Documents;

/**
 * Il PDF della conferma d'ordine (G9 §3.3): numero, data e, nel pagamento, le
 * rate con le scadenze. Il sito lo sostituisce con l'hook `documentPdf` (tipo
 * `order_confirmation`).
 */
class OrderConfirmationPdf extends DocumentPdf
{
    public function title(): string
    {
        return 'Conferma d\'ordine';
    }

    protected function meta(): array
    {
        $order = $this->data['order'];

        return [
            'Numero' => trim((string) ($order['order_number'] ?? '')),
            'Data' => static::date((string) ($order['ordered_at'] ?? '')),
            // Solo i preventivi di prima avevano un numero loro: oggi è lo stesso dell'ordine.
            'Preventivo' => trim((string) ($order['quote_number'] ?? '')) !== trim((string) ($order['order_number'] ?? ''))
                ? trim((string) ($order['quote_number'] ?? ''))
                : '',
        ] + ($this->data['meta'] ?? []);
    }
}
