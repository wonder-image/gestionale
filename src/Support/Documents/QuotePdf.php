<?php

namespace Wonder\Plugin\Gestionale\Support\Documents;

/**
 * Il PDF del preventivo (G9 §3.3): numero e revisione, data e validità. Il
 * sito lo sostituisce con l'hook `documentPdf` (tipo `quote`).
 */
class QuotePdf extends DocumentPdf
{
    public function title(): string
    {
        return 'Preventivo';
    }

    protected function meta(): array
    {
        $order = $this->data['order'];
        $revision = (int) ($order['quote_revision'] ?? 0);

        return [
            'Numero' => (trim((string) ($order['quote_number'] ?? '')) ?: trim((string) ($order['order_number'] ?? ''))).($revision > 1 ? ' rev. '.$revision : ''),
            'Data' => static::date((string) (($order['quote_sent_at'] ?? '') ?: date('Y-m-d'))),
            'Valido fino al' => static::date((string) ($order['quote_expires_at'] ?? '')),
        ] + ($this->data['meta'] ?? []);
    }
}
