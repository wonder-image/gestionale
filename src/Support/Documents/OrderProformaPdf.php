<?php

namespace Wonder\Plugin\Gestionale\Support\Documents;

/**
 * Il proforma di un ordine: righe, totali, IVA e come pagare (metodo, rate,
 * IBAN). Serve a chi paga prima della merce, anche dal sito. Non è un
 * documento fiscale e non ha un numero suo: porta quello dell'ordine. Il sito
 * lo sostituisce con l'hook `documentPdf` (tipo `order_proforma`).
 */
class OrderProformaPdf extends DocumentPdf
{
    public function title(): string
    {
        return 'Proforma';
    }

    protected function meta(): array
    {
        $order = $this->data['order'];

        return [
            'Ordine' => trim((string) ($order['order_number'] ?? '')),
            'Data dell\'ordine' => static::date((string) ($order['ordered_at'] ?? '')),
            'Emesso il' => static::date(date('Y-m-d')),
        ] + ($this->data['meta'] ?? []);
    }
}
