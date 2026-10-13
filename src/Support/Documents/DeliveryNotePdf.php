<?php

namespace Wonder\Plugin\Gestionale\Support\Documents;

/**
 * Il PDF del DDT (G9 §4.2): mittente, destinatario e luogo di consegna, le
 * righe **senza prezzi** con il lotto, causale e dati di trasporto, spazio per
 * le firme. Il sito lo sostituisce con l'hook `documentPdf` (tipo
 * `delivery_note`).
 */
class DeliveryNotePdf extends DocumentPdf
{
    /** Chi trasporta, porto e causale, come si stampano. */
    public const TRANSPORT_BY = ['sender' => 'Mittente', 'recipient' => 'Destinatario', 'carrier' => 'Vettore'];
    public const FREIGHT_TERMS = ['prepaid' => 'Franco', 'collect' => 'Assegnato'];
    public const REASONS = ['sale' => 'Vendita', 'transfer' => 'Trasferimento fra sedi'];

    public function title(): string
    {
        return 'Documento di trasporto';
    }

    protected function meta(): array
    {
        $note = $this->data['note'] ?? [];

        return [
            'Numero' => trim((string) ($note['number'] ?? '')),
            'Data' => static::date((string) ($note['date'] ?? '')),
            'Ordine' => trim((string) ($this->data['order']['order_number'] ?? '')),
        ] + ($this->data['meta'] ?? []);
    }

    /** Il destinatario e il luogo di consegna sono quelli copiati nel DDT. */
    protected function customer(): void
    {
        $note = $this->data['note'] ?? [];
        $top = $this->pdf->GetY();
        $this->box(self::LEFT, $top, 'Destinatario', $this->billingLines($note));
        $bottom = $this->pdf->GetY();
        $shipping = $this->shippingLines(['fulfillment_type' => 'shipping'] + $note);

        if ($shipping !== []) {
            $this->box(self::LEFT + 95, $top, 'Luogo di consegna', $shipping);
            $bottom = max($bottom, $this->pdf->GetY());
        }

        $this->pdf->SetY($bottom + 6);
    }

    protected function lines(): void
    {
        $widths = $this->columns();
        $this->tableHead($widths, ['Descrizione', 'Codice', 'Lotto', 'Q.tà', 'U.M.']);
        $this->pdf->SetFont('Helvetica', '', 9);

        foreach ($this->data['items'] as $item) {
            $this->row([
                (string) ($item['description'] ?? ''),
                (string) ($item['sku'] ?? ''),
                (string) ($item['batch_number'] ?? ''),
                static::quantity($item['quantity'] ?? 0),
                (string) ($item['unit'] ?? ''),
            ], $widths, $this->aligns());
        }

        $this->pdf->Ln(4);
    }

    /** Nel DDT niente totali, niente IVA, niente pagamento. */
    protected function totals(): void
    {
    }

    protected function taxes(): void
    {
    }

    /** Il trasporto al posto del pagamento, poi le firme. */
    protected function payment(): void
    {
        $note = $this->data['note'] ?? [];
        $by = (string) ($note['transport_by'] ?? 'sender');
        $rows = [
            'Causale' => self::REASONS[(string) ($note['reason'] ?? 'sale')] ?? 'Vendita',
            'Trasporto a cura del' => self::TRANSPORT_BY[$by] ?? '',
            'Vettore' => $by === 'carrier' ? trim((string) ($note['carrier_name'] ?? '')) : '',
            'Inizio trasporto' => trim(static::date((string) ($note['transport_started_at'] ?? '')).' '.substr((string) ($note['transport_started_at'] ?? ''), 11, 5)),
            'Aspetto dei beni' => trim((string) ($note['goods_appearance'] ?? '')),
            'Colli' => (int) ($note['packages_count'] ?? 0) > 0 ? (string) (int) $note['packages_count'] : '',
            'Peso lordo' => (float) ($note['gross_weight'] ?? 0) > 0 ? static::quantity($note['gross_weight']).' kg' : '',
            'Peso netto' => (float) ($note['net_weight'] ?? 0) > 0 ? static::quantity($note['net_weight']).' kg' : '',
            'Porto' => self::FREIGHT_TERMS[(string) ($note['freight_terms'] ?? 'prepaid')] ?? '',
        ];

        $this->section('Trasporto');
        $this->pdf->SetFont('Helvetica', '', 9);
        $i = 0;

        foreach ($rows as $label => $value) {
            if (trim($value) === '') {
                continue;
            }

            $this->pdf->Cell(45, 5, static::text($label), 0, 0);
            $this->pdf->Cell(45, 5, static::text($value), 0, $i % 2 === 1 ? 1 : 0);
            $i++;
        }

        if ($i % 2 === 1) {
            $this->pdf->Ln();
        }

        $this->pdf->Ln(3);
        $this->noteText();
        $this->pdf->Ln(7);
        $y = $this->pdf->GetY();

        foreach (['Firma del conducente', 'Firma del vettore', 'Firma del destinatario'] as $n => $label) {
            $x = self::LEFT + $n * 62;
            $this->pdf->Line($x, $y + 10, $x + 54, $y + 10);
            $this->pdf->SetXY($x, $y + 11);
            $this->pdf->SetFont('Helvetica', '', 7);
            $this->pdf->Cell(54, 4, static::text($label), 0, 0, 'C');
        }

        $this->pdf->SetY($y + 20);
    }

    /** Le note stanno sopra le firme (`noteText()`): qui niente. */
    protected function notes(): void
    {
    }

    protected function noteText(): void
    {
        $note = trim(html_entity_decode((string) ($this->data['note']['document_note'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if ($note === '') {
            return;
        }

        $this->section('Note');
        $this->pdf->SetFont('Helvetica', '', 9);
        $this->pdf->MultiCell(self::WIDTH, 5, static::text($note));
    }

    protected function columns(): array
    {
        return [86, 32, 32, 16, 14];
    }

    protected function aligns(): array
    {
        return ['L', 'L', 'L', 'R', 'L'];
    }
}
