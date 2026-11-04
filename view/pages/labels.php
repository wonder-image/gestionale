<?php

/*
 * Catalogo › Etichette (#116): il selettore dei prodotti come nei coupon e
 * le scelte di stampa; il PDF si apre in una scheda nuova.
 */

use Wonder\Backend\Support\ResourceFormLayoutRenderer as Layout;
use Wonder\Elements\Components\Alert;
use Wonder\Elements\Components\Button;
use Wonder\Elements\Components\Card;
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
            (new Card())->columns(12)->columnSpan(12)->components([
                ...LabelsResource::formFields(),
                Text::make('Formato: '.$format->name.' (lo cambi nelle Impostazioni). Al massimo '.Labels::MAX_LABELS.' etichette per PDF.')->muted()->small()->columnSpan(12),
                Button::make('Crea il PDF')->icon('bi bi-file-earmark-pdf')->variant('primary')->type('submit')->columnSpan(['default' => 12, 'md' => 4]),
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
