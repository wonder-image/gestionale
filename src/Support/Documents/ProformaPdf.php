<?php

namespace Wonder\Plugin\Gestionale\Support\Documents;

/**
 * Il proforma di un periodo di un abbonamento da ufficio (G10 §3): importo,
 * scadenze, IBAN del conto del metodo, periodo coperto. Non è un documento
 * fiscale e non ha numero. Il sito lo sostituisce con l'hook `documentPdf`
 * (tipo `proforma`).
 */
class ProformaPdf extends DocumentPdf
{
    public function title(): string
    {
        return 'Proforma';
    }

    protected function meta(): array
    {
        return ['Data' => static::date(date('Y-m-d'))] + ($this->data['meta'] ?? []);
    }
}
