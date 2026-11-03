<?php

namespace Wonder\Plugin\Gestionale\Support\Labels;

/**
 * Un formato di etichette, in millimetri: il foglio, la griglia e la misura
 * di una etichetta. Un rotolo termico è un foglio grande quanto l'etichetta,
 * una colonna e una riga.
 */
final class LabelFormat
{
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly float $pageWidth,
        public readonly float $pageHeight,
        public readonly int $columns,
        public readonly int $rows,
        public readonly float $labelWidth,
        public readonly float $labelHeight,
        public readonly float $marginTop = 0.0,
        public readonly float $marginLeft = 0.0,
        public readonly float $gapX = 0.0,
        public readonly float $gapY = 0.0,
    ) {
    }

    /** Un rotolo: una etichetta per pagina. */
    public static function roll(string $key, string $name, float $width, float $height): self
    {
        return new self($key, $name, $width, $height, 1, 1, $width, $height);
    }

    /** Un foglio A4 a griglia senza spazi fra le etichette, centrata. */
    public static function a4(string $key, string $name, int $columns, int $rows, float $width, float $height): self
    {
        return new self(
            $key,
            $name,
            210.0,
            297.0,
            $columns,
            $rows,
            $width,
            $height,
            max(0.0, (297.0 - $rows * $height) / 2),
            max(0.0, (210.0 - $columns * $width) / 2),
        );
    }

    public function perPage(): int
    {
        return $this->columns * $this->rows;
    }

    public function isRoll(): bool
    {
        return $this->perPage() === 1;
    }
}
