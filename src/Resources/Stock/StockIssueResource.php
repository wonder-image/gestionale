<?php

namespace Wonder\Plugin\Gestionale\Resources\Stock;

/** *Scarichi*: la merce che esce senza un ordine — rotta, scaduta, regalata, usata (G2b-bis 4.2). */
final class StockIssueResource extends StockDocumentResource
{
    public static function type(): string
    {
        return 'issue';
    }

    public static function path(): string
    {
        return 'app/gestionale/scarichi';
    }

    public static function icon(): string
    {
        return 'bi-box-arrow-up';
    }

    public static function titleLabel(): string
    {
        return 'Scarichi';
    }

    public static function singular(): string
    {
        return 'Scarico';
    }

    public static function newLabel(): string
    {
        return 'Nuovo scarico';
    }

    public static function confirmLabel(): string
    {
        return 'Conferma scarico';
    }

    public static function textSchema(): array
    {
        return [
            'label' => 'scarico',
            'plural_label' => 'scarichi',
            'last' => 'ultimi',
            'all' => 'tutti',
            'article' => 'lo',
            'this' => 'questo',
        ];
    }

    protected static function navigationOrder(): int
    {
        return 30;
    }
}
