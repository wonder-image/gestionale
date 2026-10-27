<?php

namespace Wonder\Plugin\Gestionale\Support\Documents;

use Wonder\Plugin\Gestionale\Support\Tax\VatCollectability;

/**
 * La base dei PDF di vendita (G9 §3.3): preventivo, conferma d'ordine e, con il
 * piano 4, DDT. Disegna con `Wonder\Pdf` del core (FPDF) a partire da un array
 * di dati già letti (`DocumentData`): niente database, così si prova senza sito.
 *
 * Il sito la sostituisce con l'hook `documentPdf` di `GestionaleExtension`:
 * restituisce il nome della sua classe, che estende questa (o una delle
 * figlie) e riscrive solo i pezzi che vuole — `header()`, `lines()`,
 * `totals()`, `payment()`, `notes()`.
 *
 * FPDF scrive in Windows-1252 con i caratteri di base: ogni testo passa da
 * `text()`, che converte e toglie quello che non si può scrivere.
 *
 * @phpstan-type Data array{
 *     society: array<string, string>,
 *     order: array<string, mixed>,
 *     items: list<array<string, mixed>>,
 *     taxes: list<array<string, mixed>>,
 *     payment: array{method?: string, term?: string, installments?: list<array{due_date: string, amount: float}>, iban?: string, holder?: string, bank?: string},
 *     meta?: array<string, string>
 * }
 */
abstract class DocumentPdf
{
    protected const LEFT = 15;
    protected const WIDTH = 180;

    protected DocumentCanvas $pdf;

    /** L'intestazione della tabella in corso, da ripetere a pagina nuova. @var array{0: list<int|float>, 1: list<string>|null}|null */
    private ?array $head = null;

    /** @param Data $data */
    public function __construct(protected array $data)
    {
    }

    /** «Preventivo», «Conferma d'ordine». */
    abstract public function title(): string;

    /**
     * Le righe sotto il titolo: numero, data, validità.
     *
     * @return array<string, string>
     */
    abstract protected function meta(): array;

    /** I byte del PDF. */
    public function render(): string
    {
        $this->pdf = new DocumentCanvas('P', 'mm', 'A4');
        $this->pdf->footerText = self::text($this->footer());
        $this->pdf->SetMargins(self::LEFT, 15, self::LEFT);
        $this->pdf->SetAutoPageBreak(true, 20);
        $this->pdf->AliasNbPages();
        $this->pdf->SetTitle(self::text($this->title()));
        $this->pdf->AddPage();

        $this->header();
        $this->customer();
        $this->lines();
        $this->totals();
        $this->taxes();
        $this->payment();
        $this->notes();

        return (string) $this->pdf->Output('S');
    }

    /** Il testo per FPDF: Windows-1252, «€» compreso; il resto si traslittera. */
    public static function text(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $converted = @iconv('UTF-8', 'windows-1252//TRANSLIT', $value);

        return $converted === false ? (string) preg_replace('/[^\x20-\x7E]/', '?', $value) : $converted;
    }

    /** «1.234,50 €». */
    public static function money(mixed $value): string
    {
        return number_format((float) $value, 2, ',', '.').' €';
    }

    /** «3», «2,5». */
    public static function quantity(mixed $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 3, ',', '.'), '0'), ',');
    }

    /** «13/10/2026»; vuota o zero, niente. */
    public static function date(string $value): string
    {
        $value = substr(trim($value), 0, 10);

        if ($value === '' || str_starts_with($value, '0000')) {
            return '';
        }

        [$y, $m, $d] = explode('-', $value) + ['', '', ''];

        return $d.'/'.$m.'/'.$y;
    }

    /** La società a sinistra, titolo e dati del documento a destra. */
    protected function header(): void
    {
        $society = $this->data['society'] ?? [];
        $top = $this->pdf->GetY();
        $logo = (string) ($society['logo_path'] ?? '');

        if ($logo !== '' && is_file($logo) && preg_match('/\.(png|jpe?g)$/i', $logo) === 1) {
            $this->pdf->Image($logo, self::LEFT, $top, 0, 14);
            $this->pdf->SetY($top + 16);
        }

        $this->pdf->SetFont('Helvetica', 'B', 11);
        $this->pdf->Cell(95, 5, self::text((string) (($society['legal_name'] ?? '') ?: ($society['name'] ?? ''))), 0, 2);
        $this->pdf->SetFont('Helvetica', '', 8);

        foreach (['address', 'vat', 'contacts'] as $key) {
            if (trim((string) ($society[$key] ?? '')) !== '') {
                $this->pdf->MultiCell(95, 4, self::text((string) $society[$key]));
            }
        }

        $left = $this->pdf->GetY();
        $this->pdf->SetXY(self::LEFT + 100, $top);
        $this->pdf->SetFont('Helvetica', 'B', 16);
        $this->pdf->Cell(80, 8, self::text($this->title()), 0, 2, 'R');
        $this->pdf->SetFont('Helvetica', '', 9);

        foreach ($this->meta() as $label => $value) {
            if (trim($value) === '') {
                continue;
            }

            $this->pdf->Cell(80, 5, self::text($label.': '.$value), 0, 2, 'R');
        }

        $this->pdf->SetY(max($left, $this->pdf->GetY()) + 6);
    }

    /** Intestatario e, se diversa, la consegna. */
    protected function customer(): void
    {
        $order = $this->data['order'];
        $top = $this->pdf->GetY();
        $this->box(self::LEFT, $top, 'Cliente', $this->billingLines($order));

        $shipping = $this->shippingLines($order);
        $bottom = $this->pdf->GetY();

        if ($shipping !== []) {
            $this->box(self::LEFT + 95, $top, 'Consegna', $shipping);
            $bottom = max($bottom, $this->pdf->GetY());
        }

        $this->pdf->SetY($bottom + 6);
    }

    /** La tabella delle righe. */
    protected function lines(): void
    {
        $widths = $this->columns();
        $this->tableHead($widths);
        $this->pdf->SetFont('Helvetica', '', 9);

        foreach ($this->data['items'] as $item) {
            $type = (string) ($item['type'] ?? 'product');
            $child = (int) ($item['parent_item_id'] ?? 0) > 0;
            $name = (string) ($item['name'] ?? '');

            if ($type === 'text') {
                $this->row([$name], [array_sum($widths)], ['L'], true);

                continue;
            }

            $description = ($child ? '   - ' : '').$name.(!$child && trim((string) ($item['sku'] ?? '')) !== '' ? ' ('.$item['sku'].')' : '');

            if ($child) {
                $this->row([$description, self::quantity($item['quantity'] ?? 0), '', '', '', ''], $widths, $this->aligns());

                continue;
            }

            $discount = match ((string) ($item['discount_type'] ?? 'none')) {
                'percent' => self::quantity($item['discount_value'] ?? 0).'%',
                'amount' => self::money($item['discount_value'] ?? 0),
                default => '',
            };

            $this->row([
                $description,
                self::quantity($item['quantity'] ?? 0),
                self::money($this->grossUnit($item)),
                $discount,
                (string) ($item['tax_nature'] ?? '') !== '' ? (string) $item['tax_nature'] : self::quantity($item['tax_rate'] ?? 0).'%',
                self::money($item['line_total'] ?? 0),
            ], $widths, $this->aligns());
        }

        $this->pdf->Ln(4);
    }

    /** I totali a destra. */
    protected function totals(): void
    {
        $order = $this->data['order'];
        $rows = [['Prodotti', self::money($order['products_total'] ?? 0)]];

        if ((float) ($order['discount_total'] ?? 0) > 0) {
            $rows[] = ['Sconto', '- '.self::money($order['discount_total'])];
        }

        if ((float) ($order['shipping_total'] ?? 0) > 0) {
            $rows[] = ['Spedizione', self::money($order['shipping_total'])];
        }

        $rows[] = ['Imponibile', self::money($order['taxable_total'] ?? 0)];
        $rows[] = ['IVA', self::money($order['tax_total'] ?? 0)];

        $this->pdf->SetFont('Helvetica', '', 9);

        foreach ($rows as [$label, $value]) {
            $this->pdf->SetX(self::LEFT + 110);
            $this->pdf->Cell(40, 5, self::text($label), 0, 0);
            $this->pdf->Cell(30, 5, self::text($value), 0, 1, 'R');
        }

        $this->pdf->SetFont('Helvetica', 'B', 11);
        $this->pdf->SetX(self::LEFT + 110);
        $this->pdf->Cell(40, 7, self::text('Totale'), 'T', 0);
        $this->pdf->Cell(30, 7, self::text(self::money($order['total'] ?? 0)), 'T', 1, 'R');

        // Scissione dei pagamenti: l'IVA la versa il cliente, si paga l'imponibile.
        if (($order['vat_collectability'] ?? '') === 'S') {
            $this->pdf->SetFont('Helvetica', '', 9);
            $this->pdf->SetX(self::LEFT + 110);
            $this->pdf->Cell(40, 5, self::text('Da pagare (scissione)'), 0, 0);
            $this->pdf->Cell(30, 5, self::text(self::money(VatCollectability::payable($order))), 0, 1, 'R');
        }

        $this->pdf->Ln(4);
    }

    /** Il riepilogo IVA, per aliquota. */
    protected function taxes(): void
    {
        $taxes = $this->data['taxes'] ?? [];

        if ($taxes === []) {
            return;
        }

        $this->section('Riepilogo IVA');
        $widths = [45, 45, 45, 45];
        $this->tableHead($widths, ['Aliquota', 'Imponibile', 'Imposta', 'Totale']);
        $this->pdf->SetFont('Helvetica', '', 9);

        foreach ($taxes as $tax) {
            $nature = (string) ($tax['nature'] ?? '');
            $this->row([
                $nature !== '' ? $nature : self::quantity($tax['rate'] ?? 0).'%',
                self::money($tax['taxable'] ?? 0),
                self::money($tax['tax'] ?? 0),
                self::money($tax['total'] ?? 0),
            ], $widths, ['L', 'R', 'R', 'R']);
        }

        $this->pdf->Ln(4);
    }

    /** Metodo, condizione, rate e conto per il bonifico. */
    protected function payment(): void
    {
        $payment = $this->data['payment'] ?? [];
        $lines = [];

        if (trim((string) ($payment['method'] ?? '')) !== '') {
            $lines[] = 'Metodo: '.$payment['method'];
        }

        if (trim((string) ($payment['term'] ?? '')) !== '') {
            $lines[] = 'Condizione: '.$payment['term'];
        }

        foreach ($payment['installments'] ?? [] as $i => $rata) {
            $lines[] = 'Rata '.($i + 1).': '.self::money($rata['amount']).' entro il '.self::date((string) $rata['due_date']);
        }

        if (trim((string) ($payment['iban'] ?? '')) !== '') {
            $lines[] = 'IBAN: '.$payment['iban'].(trim((string) ($payment['holder'] ?? '')) !== '' ? ' - intestato a '.$payment['holder'] : '')
                .(trim((string) ($payment['bank'] ?? '')) !== '' ? ' - '.$payment['bank'] : '');
        }

        if ($lines === []) {
            return;
        }

        $this->section('Pagamento');
        $this->pdf->SetFont('Helvetica', '', 9);

        foreach ($lines as $line) {
            $this->pdf->MultiCell(self::WIDTH, 5, self::text($line));
        }

        $this->pdf->Ln(3);
    }

    /** La nota stampata. */
    protected function notes(): void
    {
        $note = trim(html_entity_decode((string) ($this->data['order']['document_note'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if ($note === '') {
            return;
        }

        $this->section('Note');
        $this->pdf->SetFont('Helvetica', '', 9);
        $this->pdf->MultiCell(self::WIDTH, 5, self::text($note));
    }

    /** Il piede di ogni pagina: società e numero di pagina. */
    protected function footer(): string
    {
        $society = $this->data['society'] ?? [];

        return implode(' - ', array_filter([
            trim((string) (($society['legal_name'] ?? '') ?: ($society['name'] ?? ''))),
            trim((string) ($society['vat'] ?? '')),
        ]));
    }

    /** @return list<int|float> le larghezze della tabella delle righe */
    protected function columns(): array
    {
        return [78, 16, 24, 18, 16, 28];
    }

    /** @return list<string> */
    protected function aligns(): array
    {
        return ['L', 'R', 'R', 'R', 'R', 'R'];
    }

    /** Il prezzo unitario prima dello sconto di riga: quello scritto a mano, o il listino. */
    protected function grossUnit(array $item): float
    {
        $manual = trim((string) ($item['manual_unit_price'] ?? ''));

        if ($manual !== '' && is_numeric($manual)) {
            return (float) $manual;
        }

        // Senza sconto il prezzo della riga è già quello giusto; con lo sconto si
        // torna al prezzo che lo sconto ha toccato (anche quello di campagna),
        // come lo calcola `LinePrice`: il sovrapprezzo si somma dopo.
        $unit = (float) ($item['unit_price'] ?? 0) - (float) ($item['customization_surcharge'] ?? 0);
        $value = (float) ($item['discount_value'] ?? 0);

        return match ((string) ($item['discount_type'] ?? 'none')) {
            'amount' => round($unit + $value, 2),
            'percent' => $value < 100 ? round($unit / (1 - $value / 100), 2) : (float) ($item['list_price'] ?? 0),
            default => (float) ($item['unit_price'] ?? 0),
        };
    }

    /** @param list<string> $lines */
    protected function box(float $x, float $y, string $title, array $lines): void
    {
        $this->pdf->SetXY($x, $y);
        $this->pdf->SetFont('Helvetica', 'B', 8);
        $this->pdf->SetTextColor(110, 110, 110);
        $this->pdf->Cell(85, 4, self::text(mb_strtoupper($title)), 0, 2);
        $this->pdf->SetTextColor(0, 0, 0);
        $this->pdf->SetFont('Helvetica', '', 9);

        foreach ($lines as $line) {
            $this->pdf->SetX($x);
            $this->pdf->MultiCell(85, 4.5, self::text($line));
        }
    }

    protected function section(string $title): void
    {
        $this->pdf->SetFont('Helvetica', 'B', 10);
        $this->pdf->Cell(self::WIDTH, 6, self::text($title), 0, 1);
    }

    /**
     * @param list<int|float> $widths
     * @param list<string>|null $labels
     */
    protected function tableHead(array $widths, ?array $labels = null): void
    {
        $this->head = [$widths, $labels];
        $labels ??= ['Descrizione', 'Q.tà', 'Prezzo', 'Sconto', 'IVA', 'Totale'];
        // Le intestazioni come i valori: quelle della tabella delle righe da `aligns()`.
        $aligns = count($widths) === count($this->columns()) ? $this->aligns() : [];
        $this->pdf->SetFont('Helvetica', 'B', 8);
        $this->pdf->SetFillColor(240, 240, 240);

        foreach ($labels as $i => $label) {
            $this->pdf->Cell($widths[$i], 6, self::text($label), 'B', 0, $aligns[$i] ?? ($i === 0 ? 'L' : 'R'), true);
        }

        $this->pdf->Ln();
    }

    /**
     * Una riga della tabella: la prima colonna va a capo, le altre stanno
     * in alto. Se non ci sta, nuova pagina con l'intestazione.
     *
     * @param list<string> $cells
     * @param list<int|float> $widths
     * @param list<string> $aligns
     */
    protected function row(array $cells, array $widths, array $aligns, bool $italic = false): void
    {
        $lineHeight = 4.5;
        $lines = $this->pdf->lineCount((float) $widths[0], self::text((string) $cells[0]));
        $height = max(1, $lines) * $lineHeight + 1.5;

        if ($this->pdf->GetY() + $height > $this->pdf->GetPageHeight() - 20) {
            $this->pdf->AddPage();

            if ($this->head !== null) {
                $this->tableHead($this->head[0], $this->head[1]);
            }

            $this->pdf->SetFont('Helvetica', '', 9);
        }

        $x = $this->pdf->GetX();
        $y = $this->pdf->GetY();

        $this->pdf->SetFont('Helvetica', $italic ? 'I' : '', 9);
        $this->pdf->MultiCell((float) $widths[0], $lineHeight, self::text((string) $cells[0]), 0, $aligns[0] ?? 'L');
        $this->pdf->SetFont('Helvetica', '', 9);
        $offset = $x + (float) $widths[0];

        for ($i = 1; $i < count($cells); $i++) {
            $this->pdf->SetXY($offset, $y);
            $this->pdf->Cell((float) $widths[$i], $lineHeight, self::text((string) $cells[$i]), 0, 0, $aligns[$i] ?? 'R');
            $offset += (float) $widths[$i];
        }

        $this->pdf->SetXY($x, $y + $height);
        $this->pdf->SetDrawColor(225, 225, 225);
        $this->pdf->Line($x, $y + $height - 0.5, $x + array_sum($widths), $y + $height - 0.5);
        $this->pdf->SetDrawColor(0, 0, 0);
    }

    /** @return list<string> */
    protected function billingLines(array $order): array
    {
        $g = static fn (string $k): string => trim(html_entity_decode((string) ($order['billing_'.$k] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $name = $g('business_name') !== '' ? $g('business_name') : trim($g('name').' '.$g('surname'));

        return array_values(array_filter([
            $name,
            trim($g('street').' '.$g('number')),
            trim($g('cap').' '.$g('city').($g('province') !== '' ? ' ('.$g('province').')' : '').' '.($g('country') !== 'IT' ? $g('country') : '')),
            $g('pi') !== '' ? 'P. IVA '.$g('pi') : '',
            $g('cf') !== '' && $g('cf') !== $g('pi') ? 'C.F. '.$g('cf') : '',
            $g('sdi') !== '' ? 'SDI '.$g('sdi') : ($g('pec') !== '' ? 'PEC '.$g('pec') : ''),
            trim((string) ($order['email'] ?? '')),
        ], static fn (string $line): bool => trim($line) !== ''));
    }

    /** @return list<string> vuota se non si spedisce */
    protected function shippingLines(array $order): array
    {
        if ((string) ($order['fulfillment_type'] ?? 'shipping') !== 'shipping') {
            return (string) ($order['fulfillment_type'] ?? '') === 'pickup' ? ['Ritiro in sede'] : [];
        }

        $g = static fn (string $k): string => trim(html_entity_decode((string) ($order['shipping_'.$k] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if ($g('street') === '') {
            return [];
        }

        return array_values(array_filter([
            $g('business_name'),
            trim($g('name').' '.$g('surname')),
            trim($g('street').' '.$g('number')),
            trim($g('cap').' '.$g('city').($g('province') !== '' ? ' ('.$g('province').')' : '').' '.($g('country') !== 'IT' ? $g('country') : '')),
            $g('phone') !== '' ? 'Tel. '.trim($g('phone_prefix').' '.$g('phone')) : '',
        ], static fn (string $line): bool => trim($line) !== ''));
    }
}
