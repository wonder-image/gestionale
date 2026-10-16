<?php

namespace Wonder\Plugin\Gestionale\Backend\Widgets;

use Throwable;
use Wonder\App\Permission\Access;
use Wonder\Backend\Contracts\HomeWidget;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Resources\Stock\StockLevelResource;
use Wonder\Plugin\Gestionale\Support\Errors\Errors;
use Wonder\Plugin\Gestionale\Support\Stock\Locations;
use Wonder\Plugin\Gestionale\Support\Stock\StockValue;

/**
 * "Valore del magazzino" (G3.19, spec §3.4): pezzi per costo del fornitore,
 * IVA esclusa; per sede quando le sedi da mostrare sono più di una. I pezzi
 * senza costo si dicono, con il link alle Giacenze.
 *
 * A «Acquisti» bloccata il riquadro non esiste.
 */
final class StockValueWidget implements HomeWidget
{
    public function title(): string
    {
        return 'Valore del magazzino';
    }

    public function authorities(): array
    {
        return Access::viewers(Gestionale::area('magazzino'));
    }

    public function order(): int
    {
        return 28;
    }

    public function render(): string
    {
        if (!Gestionale::feature('purchasing')) {
            return '';
        }

        try {
            $values = StockValue::byLocation();
            $locations = Locations::shown();
        } catch (Throwable $error) {
            Errors::internal($error, 'widget.stock_value');

            return '';
        }

        return self::markup($values, count($locations) >= 2 ? $locations : [], StockLevelResource::listUrl());
    }

    /**
     * @param array<int, array{value: float, pieces: float, uncosted: float}> $values per sede, totale in 0
     * @param list<array{id: int, label: string}> $locations le sedi da elencare; vuota con una sede sola
     */
    public static function markup(array $values, array $locations, string $listUrl): string
    {
        $e = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        $total = $values[0] ?? ['value' => 0.0, 'pieces' => 0.0, 'uncosted' => 0.0];
        $rows = '';

        foreach ($locations as $location) {
            $value = $values[$location['id']]['value'] ?? 0.0;
            $rows .= '<li class="d-flex justify-content-between"><span>'.$e($location['label']).'</span><span>'.$e(StockValue::money($value)).'</span></li>';
        }

        $list = $rows === '' ? '' : '<ul class="list-unstyled small mb-2">'.$rows.'</ul>';
        $uncosted = $total['uncosted'] > 0
            ? '<p class="small text-warning-emphasis mb-0">'.$e(self::pieces($total['uncosted'])).' senza costo: non entrano nel valore. '
                .'<a href="'.$e($listUrl).'">Apri le Giacenze</a></p>'
            : '';

        return '<wi-card class="col-12 col-md-6">'
            .'<h5 class="mb-1"><i class="bi bi-cash-stack"></i> Valore del magazzino</h5>'
            .'<p class="fs-4 fw-semibold mb-1">'.$e(StockValue::money($total['value'])).'</p>'
            .'<p class="text-body-secondary small mb-2">IVA esclusa, al costo di oggi dei fornitori.</p>'
            .$list
            .$uncosted
            .'</wi-card>';
    }

    /** «1 pezzo», «12 pezzi», «2,5 pezzi». */
    private static function pieces(float $quantity): string
    {
        $text = $quantity === round($quantity) ? (string) (int) $quantity : rtrim(rtrim(number_format($quantity, 3, ',', '.'), '0'), ',');

        return $text.($quantity === 1.0 ? ' pezzo' : ' pezzi');
    }
}
