<?php

namespace Wonder\Plugin\Gestionale\Resources\Stock;

/**
 * *Inventari*: la merce contata sullo scaffale; alla conferma la giacenza
 * delle righe contate si corregge della differenza (G2b-bis 4.2).
 */
final class StockStocktakeResource extends StockDocumentResource
{
    public static string $docsPage = 'magazzino/magazzino-inventario';

    public static function type(): string
    {
        return 'stocktake';
    }

    public static function path(): string
    {
        return 'app/gestionale/inventari';
    }

    public static function icon(): string
    {
        return 'bi-clipboard-check';
    }

    public static function titleLabel(): string
    {
        return 'Inventari';
    }

    public static function singular(): string
    {
        return 'Inventario';
    }

    public static function newLabel(): string
    {
        return 'Nuovo inventario';
    }

    public static function confirmLabel(): string
    {
        return 'Conferma inventario';
    }

    public static function textSchema(): array
    {
        return [
            'label' => 'inventario',
            'plural_label' => 'inventari',
            'last' => 'ultimi',
            'all' => 'tutti',
            'article' => "l'",
            'this' => 'questo',
        ];
    }

    protected static function navigationOrder(): int
    {
        return 40;
    }
}
