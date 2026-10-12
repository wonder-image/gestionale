<?php

namespace Wonder\Plugin\Gestionale\Resources\Stock;

/** *Carichi*: la merce che entra, dal fornitore o dal primo carico (G2b-bis 4.2). */
final class StockReceiptResource extends StockDocumentResource
{
    public static function type(): string
    {
        return 'receipt';
    }

    public static function path(): string
    {
        return 'app/gestionale/carichi';
    }

    public static function icon(): string
    {
        return 'bi-box-arrow-in-down';
    }

    public static function titleLabel(): string
    {
        return 'Carichi';
    }

    public static function singular(): string
    {
        return 'Carico';
    }

    public static function newLabel(): string
    {
        return 'Nuovo carico';
    }

    public static function confirmLabel(): string
    {
        return 'Conferma carico';
    }

    public static function textSchema(): array
    {
        return [
            'label' => 'carico',
            'plural_label' => 'carichi',
            'last' => 'ultimi',
            'all' => 'tutti',
            'article' => 'il',
            'this' => 'questo',
        ];
    }

    protected static function navigationOrder(): int
    {
        return 20;
    }
}
