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
 * Il bottone «Etichette» e la sua finestra: prodotti e varianti da stampare
 * (nel pannello della lista Prodotti), campi e prima casella libera del
 * foglio. Il formato è quello delle Impostazioni. Il PDF si apre in una
 * scheda nuova.
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

    /**
     * La rotta del pannello: `POST etichette/` sulla lista, con la scelta nel
     * corpo (una scelta lunga non sta in un indirizzo).
     */
    public static function selectionRoute(string $slug, string $area): void
    {
        Route::post('/etichette/', Gestionale::handlerPath('backend/catalog/labels.php'), [
            'resource' => $slug,
            'resource_action' => 'labels-selection',
            'labels_source' => 'selection',
        ])->name('labels-selection')
            ->permit(Access::roles(Gestionale::area($area), 'view'));
    }

    /** L'indirizzo del PDF del pannello. */
    public static function selectionUrl(string $slug, string $path): string
    {
        $fallback = '/backend/'.$path.'/etichette/';

        if (!function_exists('__r')) {
            return $fallback;
        }

        try {
            $named = (string) __r('backend.resource.'.$slug.'.labels-selection');
        } catch (Throwable) {
            return $fallback;
        }

        return $named !== '' ? $named : $fallback;
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

    /**
     * Con `$items` (le voci di `Labels::selectionOptions()`) è il pannello:
     * si scelgono prodotti e varianti, una etichetta per variante. Senza è
     * il carico, che stampa i suoi pezzi.
     *
     * @param array<string, string>|null $items
     */
    public static function modal(string $url, ?array $items = null): string
    {
        if (!Gestionale::feature('barcode_labels') || $url === '') {
            return '';
        }

        $campi = [];

        if ($items !== null) {
            $campi[] = FormField::key('items')->selectSearch($items, true)->label('Prodotti e varianti')->columnSpan(12);
        }

        $campi[] = FormField::key('fields')->checkbox()->options(Labels::FIELDS)->value(Labels::DEFAULT_FIELDS)->label('Da stampare')->columnSpan(12);

        // Per un foglio già usato in parte; il rotolo non ha caselle.
        if (!LabelFormats::current()->isRoll()) {
            $campi[] = FormField::key('start')->number()->integer()->value('1')->label('Prima casella libera')->columnSpan(12);
        }

        $finestra = Modal::make('Stampa etichette')
            ->id(self::MODAL)
            ->form($url, $items !== null ? 'post' : 'get')
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

            // La ricerca nella select la accende la lib; il modello era inerte.
            document.getElementById(ID).addEventListener('show.bs.modal', function () {
                if (typeof window.setInput === 'function' && !this.querySelector('.select2-hidden-accessible')) {
                    window.setInput(this);
                }
            });

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
