<?php

namespace Wonder\Plugin\Gestionale\Backend\Widgets;

use Wonder\Elements\Component;
use Wonder\Elements\Components\Button;
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

    /**
     * Una card del core (`StatusCard`, `LinkListCard`) in una colonna della
     * `.row` della home, come fa il core con le sue card di stato.
     */
    public static function column(Component $card, string $class = 'col-12'): string
    {
        return '<div class="'.htmlspecialchars($class, ENT_QUOTES, 'UTF-8').'">'.$card->render('bootstrap').'</div>';
    }

    /** Bottone piccolo in linea. L'URL va passato non escapato: lo escapa `Button`. */
    public static function button(string $url, string $label, string $variant = 'secondary'): string
    {
        return Button::to($url, $label)
            ->variant($variant)
            ->size('sm')
            ->attr('class', 'text-nowrap')
            ->schema('inline', true)
            ->render('bootstrap');
    }
}
