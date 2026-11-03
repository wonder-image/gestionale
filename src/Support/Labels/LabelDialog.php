<?php

namespace Wonder\Plugin\Gestionale\Support\Labels;

use Throwable;
use Wonder\App\Permission\Access;
use Wonder\App\ResourceSchema\FormField;
use Wonder\Elements\Components\Button;
use Wonder\Elements\Components\Modal;
use Wonder\Http\Route;
use Wonder\Plugin\Gestionale\Gestionale;

/**
 * Il bottone «Etichette» e la sua finestra: formato, campi da stampare, copie
 * e prima casella libera del foglio. Il PDF si apre in una scheda nuova.
 *
 * La finestra nasce in un `<template>` e lo script la porta in fondo alla
 * pagina: le schede sono già un form, e un form dentro un form non esiste.
 */
final class LabelDialog
{
    public const MODAL = 'wi-labels';

    /**
     * La rotta `labels` di una Resource: `{id}/etichette/`, nell'area che la
     * vede in lettura. Si registra anche a funzionalità spenta: il 403 lo dà
     * l'handler.
     */
    public static function route(string $slug, string $source, string $area): void
    {
        Route::get('/{id}/etichette/', Gestionale::handlerPath('backend/catalog/labels.php'), [
            'resource' => $slug,
            'resource_action' => 'labels',
            'labels_source' => $source,
        ])->name('labels')
            ->permit(Access::roles(Gestionale::area($area), 'view'))
            ->where('id', '[0-9]+');
    }

    /** L'indirizzo del PDF di una scheda. */
    public static function url(string $slug, string $path, int $id): string
    {
        $fallback = '/backend/'.$path.'/'.$id.'/etichette/';

        if ($id <= 0 || !function_exists('__r')) {
            return $id > 0 ? $fallback : '';
        }

        try {
            $named = (string) __r('backend.resource.'.$slug.'.labels', ['id' => $id]);
        } catch (Throwable) {
            return $fallback;
        }

        return $named !== '' ? $named : $fallback;
    }

    /**
     * L'azione d'intestazione; nessuna a funzionalità spenta.
     *
     * @return list<Button>
     */
    public static function actions(): array
    {
        if (!Gestionale::feature('barcode_labels')) {
            return [];
        }

        return [Button::make('Etichette')->icon('bi bi-upc')->variant('dark')->outline()->size('sm')->opensModal(self::MODAL)->schema('inline', true)];
    }

    /** `$copies` falso sul carico: lì le copie sono i pezzi ricevuti. */
    public static function modal(string $url, bool $copies = true): string
    {
        if (!Gestionale::feature('barcode_labels') || $url === '') {
            return '';
        }

        $formati = LabelFormats::options();

        $campi = [
            FormField::key('format')->select($formati)->value((string) array_key_first($formati))->label('Formato')->columnSpan(12),
            FormField::key('fields')->checkbox()->options(Labels::FIELDS)->value(Labels::DEFAULT_FIELDS)->label('Da stampare')->columnSpan(12),
        ];

        if ($copies) {
            $campi[] = FormField::key('copies')->number()->value('1')->label('Copie per variante')->columnSpan(6);
        }

        // Per un foglio A4 già usato in parte; sui rotoli non conta.
        $campi[] = FormField::key('start')->number()->value('1')->label('Prima casella libera (A4)')
            ->columnSpan($copies ? 6 : 12);

        $finestra = Modal::make('Stampa etichette')
            ->id(self::MODAL)
            ->form($url, 'get')
            ->columns(12)
            ->components($campi)
            ->cancel('Annulla')
            ->submit('Crea il PDF')
            ->render('bootstrap');

        $id = json_encode(self::MODAL);

        return '<template data-wi-labels="'.self::MODAL.'">'.$finestra.'</template>'
            .<<<HTML
<script>
    (function () {
        var ID = {$id};

        function porta() {
            var modello = document.querySelector('template[data-wi-labels="' + ID + '"]');

            if (!modello || document.getElementById(ID)) {
                return;
            }

            document.body.appendChild(modello.content.cloneNode(true));

            var form = document.querySelector('#' + ID + ' form');

            if (form) {
                form.target = '_blank';
                form.addEventListener('submit', function () {
                    window.bootstrap.Modal.getOrCreateInstance(document.getElementById(ID)).hide();
                });
            }
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', porta);
        } else {
            porta();
        }
    })();
</script>
HTML;
    }
}
