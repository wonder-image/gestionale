<?php

/*
 * Catalogo › Etichette (#116): il selettore dei prodotti come nei coupon e
 * le scelte di stampa; il PDF si apre in una scheda nuova.
 */

use Wonder\Backend\Support\ResourceFormLayoutRenderer as Layout;
use Wonder\Elements\Components\Alert;
use Wonder\Elements\Components\Button;
use Wonder\Elements\Components\Card;
use Wonder\Elements\Components\SectionTitle;
use Wonder\Elements\Components\Text;
use Wonder\Elements\Form\Form;
use Wonder\Http\Csrf;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Resources\Catalog\LabelsResource;
use Wonder\Plugin\Gestionale\Support\Labels\LabelFormats;
use Wonder\Plugin\Gestionale\Support\Labels\Labels;
use Wonder\View\View;

View::layout('backend.show', ['TITLE' => 'Etichette', 'SUBTITLE' => 'Scegli i prodotti e scarica il PDF da stampare']);

if (!Gestionale::feature('barcode_labels')) {
    echo Alert::make('Accendi «Etichette con codice a barre» nelle Funzionalità per stamparle.', 'info')
        ->title('Etichette non attive')->inline()->dismissible(false)->render('bootstrap');
} else {
    $format = LabelFormats::current();

    echo Layout::render(
        (new Form())->columns(12)->components([
            (new Card())->columns(12)->columnSpan(['default' => 12, 'lg' => 8])->components(LabelsResource::formFields()),
            (new Card())->columns(12)->columnSpan(['default' => 12, 'lg' => 4])->components([
                SectionTitle::make('Stampa')
                    ->tooltip('Copie per variante: quante etichette uguali per ogni variante. Comincia dalla casella n°: su un foglio A4 già usato in parte, la prima casella ancora libera, contando da sinistra a destra e riga per riga a partire da 1. Con un rotolo non serve.')
                    ->columnSpan(12),
                ...LabelsResource::printFields(),
                Text::make('Formato: '.$format->name.'. Al massimo '.Labels::MAX_LABELS.' etichette per PDF.')->muted()->small()->columnSpan(12),
                Button::make('Crea il PDF')->icon('bi bi-file-earmark-pdf')->variant('primary')->type('submit')->columnSpan(12),
            ]),
        ]),
        [
            'id' => 'gestionale-labels',
            'action' => LabelsResource::printUrl(),
            'method' => 'post',
            'enctype' => 'application/x-www-form-urlencoded',
            'onsubmit' => '',
            'attributes' => ['target' => '_blank'],
            'footer' => Csrf::field(),
        ]
    );
}

View::end();
