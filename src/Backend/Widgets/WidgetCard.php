<?php

namespace Wonder\Plugin\Gestionale\Backend\Widgets;

use Wonder\Elements\Components\Card;
use Wonder\Elements\Components\RichText;

/**
 * La cornice dei riquadri della home: una `Card` del core a tutta riga.
 *
 * Prende il posto del tag `wi-card`, che il core dà per dismesso: quello lo
 * ricopiava il JavaScript della lib al caricamento, questa esce già fatta dal
 * server. Il contenuto resta quello che ogni riquadro compone, con i testi
 * già passati da `htmlspecialchars()`.
 */
final class WidgetCard
{
    public static function wrap(string $body): string
    {
        return (new Card)->components([
            RichText::make($body)->tag('div')->columnSpan(12),
        ])->columns(12)->columnSpan(12)->render('bootstrap');
    }

    /**
     * Mezza riga da `md` in su. La home mette i riquadri in una `.row` di
     * Bootstrap, dove le classi `col-span-*` della Card non dividono la riga.
     */
    public static function half(string $body): string
    {
        return '<div class="col-12 col-md-6">'.self::wrap($body).'</div>';
    }
}
