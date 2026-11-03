<?php

namespace Wonder\Plugin\Gestionale\Support\Labels;

use InvalidArgumentException;
use Wonder\Pdf;
use Wonder\Plugin\Gestionale\Support\Documents\DocumentPdf;
use Wonder\Support\Barcode\Ean;

/**
 * Il PDF delle etichette: `plan()` decide dove va ogni etichetta e cosa
 * contiene, `render()` lo disegna con FPDF. La divisione serve ai test, che
 * in un PDF compresso non vedrebbero niente.
 */
final class LabelSheet
{
    /** I campi che si possono stampare, nell'ordine in cui escono. */
    public const FIELDS = [
        'article' => 'Nome articolo',
        'variant' => 'Variante',
        'sku' => 'SKU',
        'price' => 'Prezzo',
        'barcode' => 'Codice a barre',
    ];

    public const DEFAULT_FIELDS = ['article', 'variant', 'price', 'barcode'];

    /**
     * Il contenuto di ogni etichetta, una per copia.
     *
     * @param list<array{article?: string, variant?: string, sku?: string, price?: float|null, ean?: string, copies?: int}> $items
     * @param list<string> $fields
     * @return list<array{lines: list<string>, price: string, ean: string, modules: ?string, invalid: bool}>
     */
    public static function labels(array $items, array $fields): array
    {
        $labels = [];

        foreach ($items as $item) {
            $lines = [];

            foreach (['article', 'variant', 'sku'] as $field) {
                $value = trim((string) ($item[$field] ?? ''));

                if (in_array($field, $fields, true) && $value !== '') {
                    $lines[] = $field === 'sku' ? 'SKU '.$value : $value;
                }
            }

            // La variante che ripete l'articolo (prodotto senza varianti) non si stampa due volte.
            $lines = array_values(array_unique($lines));
            $price = $item['price'] ?? null;
            $ean = in_array('barcode', $fields, true) ? trim((string) ($item['ean'] ?? '')) : '';
            $modules = null;

            if ($ean !== '') {
                try {
                    $encoded = Ean::encode($ean, strlen($ean) === 8 ? 8 : 13);
                    $ean = $encoded['code'];
                    $modules = $encoded['modules'];
                } catch (InvalidArgumentException) {
                    $modules = null;
                }
            }

            $label = [
                'lines' => $lines,
                'price' => in_array('price', $fields, true) && $price !== null ? DocumentPdf::money($price) : '',
                'ean' => $ean,
                'modules' => $modules,
                'invalid' => $ean !== '' && $modules === null,
            ];

            for ($copy = 0; $copy < max(0, (int) ($item['copies'] ?? 1)); $copy++) {
                $labels[] = $label;
            }
        }

        return $labels;
    }

    /**
     * Pagina e angolo di ogni etichetta, in mm. `$start` salta le prime
     * caselle di un foglio già usato in parte; nel rotolo non vale.
     *
     * @param list<array<string, mixed>> $labels
     * @return list<array{page: int, x: float, y: float, label: array<string, mixed>}>
     */
    public static function plan(LabelFormat $format, array $labels, int $start = 0): array
    {
        $perPage = $format->perPage();
        $slot = $format->isRoll() ? 0 : max(0, min($start, $perPage - 1));
        $out = [];

        foreach ($labels as $label) {
            $cell = $slot % $perPage;
            $column = $cell % $format->columns;
            $row = intdiv($cell, $format->columns);

            $out[] = [
                'page' => intdiv($slot, $perPage) + 1,
                'x' => round($format->marginLeft + $column * ($format->labelWidth + $format->gapX), 3),
                'y' => round($format->marginTop + $row * ($format->labelHeight + $format->gapY), 3),
                'label' => $label,
            ];

            $slot++;
        }

        return $out;
    }

    /**
     * Le misure dentro un'etichetta, in mm dal suo angolo: righe di testo,
     * prezzo e barre. Le righe che non ci stanno si lasciano dall'ultima,
     * perché le barre non scendano sotto un'altezza leggibile.
     *
     * @param array{lines: list<string>, price: string, ean: string, modules: ?string, invalid: bool} $label
     * @return array{padding: float, line: float, font: float, lines: list<string>, price: string, bars: ?array{x: float, y: float, module: float, height: float}, digits: ?array{y: float, text: string}}
     */
    public static function layout(LabelFormat $format, array $label): array
    {
        $width = $format->labelWidth;
        $height = $format->labelHeight;
        $padding = min(2.5, $height * 0.08, $width * 0.06);
        $line = min(4.2, max(2.2, $height * 0.125));
        // 1 pt = 0,3528 mm: il carattere occupa circa l'80% della riga.
        $font = round($line / 0.3528 * 0.8, 1);
        $inner = $height - 2 * $padding;

        $lines = $label['lines'];
        $hasCode = $label['ean'] !== '';
        $hasBars = $label['modules'] !== null;
        $minBars = $hasBars ? max(5.0, $height * 0.3) : 0.0;
        $fixed = ($label['price'] !== '' ? $line * 1.3 : 0.0) + ($hasCode ? $line : 0.0);

        while ($lines !== [] && count($lines) * $line + $fixed + $minBars > $inner) {
            array_pop($lines);
        }

        $bars = null;
        $digits = null;

        if ($hasCode) {
            $digitsY = $height - $padding - $line;
            $digits = ['y' => round($digitsY, 3), 'text' => $label['invalid'] ? $label['ean'].' (EAN non valido)' : $label['ean']];

            if ($hasBars) {
                $count = strlen((string) $label['modules']);
                // Margine bianco di 9 moduli per lato, che i lettori vogliono.
                $module = min(0.5, ($width - 2 * $padding) / ($count + 18));
                $top = $padding + count($lines) * $line + ($label['price'] !== '' ? $line * 1.3 : 0.0);

                $bars = [
                    'x' => round(($width - $count * $module) / 2, 3),
                    'y' => round($top, 3),
                    'module' => round($module, 4),
                    'height' => round(max(0.0, $digitsY - $top), 3),
                ];
            }
        }

        return [
            'padding' => round($padding, 3),
            'line' => round($line, 3),
            'font' => $font,
            'lines' => $lines,
            'price' => $label['price'],
            'bars' => $bars,
            'digits' => $digits,
        ];
    }

    /** @param list<array<string, mixed>> $labels */
    public static function render(LabelFormat $format, array $labels, int $start = 0): string
    {
        // FPDF mette sempre in verticale le misure date: il rotolo largo va in orizzontale.
        $orientation = $format->pageWidth > $format->pageHeight ? 'L' : 'P';
        $pdf = new Pdf($orientation, 'mm', [$format->pageWidth, $format->pageHeight]);
        $pdf->SetAutoPageBreak(false);
        $pdf->SetMargins(0, 0, 0);
        $pdf->SetCreator('Wonder Gestionale');
        $pdf->SetTitle('Etichette');

        $page = 0;
        $plan = self::plan($format, $labels, $start);

        if ($plan === []) {
            $pdf->AddPage();
        }

        foreach ($plan as $slot) {
            while ($page < $slot['page']) {
                $pdf->AddPage();
                $page++;
            }

            self::draw($pdf, $format, (float) $slot['x'], (float) $slot['y'], $slot['label']);
        }

        return $pdf->Output('S');
    }

    /** @param array{lines: list<string>, price: string, ean: string, modules: ?string, invalid: bool} $label */
    private static function draw(Pdf $pdf, LabelFormat $format, float $x, float $y, array $label): void
    {
        $box = self::layout($format, $label);
        $padding = $box['padding'];
        $usable = $format->labelWidth - 2 * $padding;
        $cursor = $y + $padding;

        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFillColor(0, 0, 0);

        foreach ($box['lines'] as $index => $text) {
            $pdf->SetFont('Helvetica', $index === 0 ? 'B' : '', $box['font']);
            $pdf->SetXY($x + $padding, $cursor);
            $pdf->Cell($usable, $box['line'], self::fit($pdf, $text, $usable), 0, 0, 'L');
            $cursor += $box['line'];
        }

        if ($box['price'] !== '') {
            $pdf->SetFont('Helvetica', 'B', $box['font'] * 1.3);
            $pdf->SetXY($x + $padding, $cursor);
            $pdf->Cell($usable, $box['line'] * 1.3, DocumentPdf::text($box['price']), 0, 0, 'L');
        }

        if ($box['bars'] !== null) {
            $bars = $box['bars'];
            $left = $x + $bars['x'];

            foreach (str_split((string) $label['modules']) as $index => $bit) {
                if ($bit === '1') {
                    $pdf->Rect($left + $index * $bars['module'], $y + $bars['y'], $bars['module'], $bars['height'], 'F');
                }
            }
        }

        if ($box['digits'] !== null) {
            $pdf->SetFont('Helvetica', '', $box['font']);
            $pdf->SetXY($x + $padding, $y + $box['digits']['y']);
            $pdf->Cell($usable, $box['line'], self::fit($pdf, $box['digits']['text'], $usable), 0, 0, 'C');
        }
    }

    /** Il testo in Windows-1252, accorciato coi puntini se non ci sta. */
    private static function fit(Pdf $pdf, string $text, float $width): string
    {
        $text = DocumentPdf::text($text);

        if ($pdf->GetStringWidth($text) <= $width) {
            return $text;
        }

        while ($text !== '' && $pdf->GetStringWidth($text.'...') > $width) {
            $text = substr($text, 0, -1);
        }

        return rtrim($text).'...';
    }
}
