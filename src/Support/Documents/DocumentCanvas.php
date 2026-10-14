<?php

namespace Wonder\Plugin\Gestionale\Support\Documents;

use Wonder\Pdf;

/**
 * Il foglio dei PDF di vendita: `Wonder\Pdf` con il piede di pagina e il
 * conto delle righe di una `MultiCell`, che FPDF non dà.
 */
class DocumentCanvas extends Pdf
{
    /** Già in Windows-1252 (`DocumentPdf::text()`). */
    public string $footerText = '';

    public function Footer(): void
    {
        $this->SetY(-12);
        $this->SetFont('Helvetica', '', 7);
        $this->SetTextColor(120, 120, 120);
        $this->Cell(120, 4, $this->footerText, 0, 0, 'L');
        $this->Cell(0, 4, 'Pagina '.$this->PageNo().' di {nb}', 0, 0, 'R');
        $this->SetTextColor(0, 0, 0);
    }

    /** Quante righe occupa il testo in una colonna larga `$width`, col carattere corrente. */
    public function lineCount(float $width, string $text): int
    {
        $usable = $width - 2 * $this->cMargin;

        if ($usable <= 0 || $text === '') {
            return 1;
        }

        $count = 0;

        foreach (explode("\n", str_replace("\r", '', $text)) as $paragraph) {
            $line = '';
            $count++;

            foreach (explode(' ', $paragraph) as $word) {
                $candidate = $line === '' ? $word : $line.' '.$word;

                if ($this->GetStringWidth($candidate) <= $usable) {
                    $line = $candidate;

                    continue;
                }

                if ($line !== '') {
                    $count++;
                }

                // Una parola più lunga della colonna va a capo da sola, più volte.
                $line = $word;

                while ($this->GetStringWidth($line) > $usable && mb_strlen($line) > 1) {
                    $count++;
                    $line = substr($line, (int) max(1, floor(strlen($line) * $usable / max(0.1, $this->GetStringWidth($line)))));
                }
            }
        }

        return max(1, $count);
    }
}
