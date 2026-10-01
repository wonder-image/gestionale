<?php

namespace Wonder\Plugin\Gestionale\Resources\Sales;

use Throwable;
use Wonder\App\Resources\Support\NavigationOnlyResource;
use Wonder\App\ResourceSchema\NavigationSchema;
use Wonder\App\ResourceSchema\PageSchema;
use Wonder\App\ResourceSchema\PermissionSchema;
use Wonder\Backend\Support\FlashAlert;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Resources\Stock\StockAdjustmentResource;
use Wonder\Plugin\Gestionale\Support\Orders\OrderSheet;

/**
 * Le note dell'ordine che si scrivono a mano: quella interna, che non esce
 * dal backend, e quella sul documento, che finisce sulla fattura. La nota del
 * cliente è sua, e qui non si tocca.
 *
 * Non ha una pagina sua: la scheda apre la finestra e questa riceve il POST.
 * Scrivere una nota non cambia lo stato dell'ordine, quindi non passa da
 * `Lifecycle`.
 */
final class OrderNoteResource extends NavigationOnlyResource
{
    /** L'id della finestra che la scheda dell'ordine apre dal pulsante accanto alle note. */
    public const MODAL_ID = 'wi-ordine-note';

    /** Caratteri al massimo per ciascuna nota. */
    public const MAX = 5000;

    /** Perché le note sono bloccate: sta nel tooltip del lucchetto e nel rifiuto. */
    public const LOCKED_TEXT = 'Ordine evaso e pagato: le note non si modificano più.';

    public static function path(): string
    {
        return 'app/gestionale/ordine-note';
    }

    public static function icon(): string
    {
        return 'bi-pencil-square';
    }

    public static function titleLabel(): string
    {
        return 'Note dell\'ordine';
    }

    public static function isFormPage(): bool
    {
        return true;
    }

    public static function pageSchema(): PageSchema
    {
        return parent::pageSchema()->only([])->titles(['form' => 'Note dell\'ordine']);
    }

    /** Le pagine-form leggono `edit` (apertura) e `update` (invio). */
    public static function permissionSchema(): PermissionSchema
    {
        return PermissionSchema::for(static::class)->backend(['edit', 'update'], ['admin', 'administrator']);
    }

    public static function navigationSchema(): NavigationSchema
    {
        // Fuori dal menu: ci si arriva dai pulsanti accanto alle note.
        return NavigationSchema::for(static::class)
            ->inSection('vendite')
            ->title('Note dell\'ordine')
            ->authority(['admin', 'administrator'])
            ->enabled(false);
    }

    /** Dove postano la finestra: la rotta di questa pagina, in POST. */
    public static function submitUrl(): string
    {
        $base = '/backend/'.static::path();

        if (function_exists('__r')) {
            try {
                $named = (string) __r('backend.resource.'.static::slug().'.form');
                $base = $named !== '' ? $named : $base;
            } catch (Throwable) {
                // Rotta non registrata (test, comandi): resta il percorso.
            }
        }

        return $base;
    }

    /**
     * Un ordine evaso e pagato è chiuso: le sue note non si riscrivono più.
     *
     * @param array<string, mixed> $order
     */
    public static function isLocked(array $order): bool
    {
        return (string) ($order['fulfillment_status'] ?? '') === 'fulfilled'
            && (string) ($order['payment_status'] ?? '') === 'paid';
    }

    /**
     * La finestra con le due note, già compilate. Vuota se le note sono
     * bloccate: non c'è niente da aprire.
     *
     * @param array<string, mixed> $order
     */
    public static function modal(array $order, string $back): string
    {
        if (static::isLocked($order)) {
            return '';
        }

        $id = static::MODAL_ID;
        $esc = static fn (string $v): string => OrderSheet::esc($v);
        $titolo = trim('Note: ordine '.trim((string) ($order['order_number'] ?? '')));
        $aiuto = 'La nota interna la vedi solo tu e chi lavora sugli ordini. La nota sul documento si stampa sulla fattura.';
        $campo = static fn (string $nome, string $etichetta, string $valore): string => '<div class="col-12">'
            .'<label class="form-label" for="'.$id.'-'.$nome.'">'.$etichetta.'</label>'
            .'<textarea class="form-control" id="'.$id.'-'.$nome.'" name="'.$nome.'" rows="4" maxlength="'.static::MAX.'">'
            .OrderSheet::esc($valore).'</textarea></div>';

        return '<div class="modal fade" id="'.$id.'" tabindex="-1" aria-hidden="true">'
            .'<div class="modal-dialog modal-dialog-centered"><div class="modal-content">'
            .'<form method="post" action="'.$esc(static::submitUrl()).'">'
            .'<div class="modal-header"><h5 class="modal-title">'.$esc($titolo)
            .' <i class="bi bi-info-circle text-muted fs-6 ms-1" title="'.$esc($aiuto).'"></i></h5>'
            .'<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button></div>'
            .'<div class="modal-body"><div class="row g-3">'
            .$campo('internal_note', 'Nota interna', (string) ($order['internal_note'] ?? ''))
            .$campo('document_note', 'Nota sul documento', (string) ($order['document_note'] ?? ''))
            .'</div></div>'
            .'<div class="modal-footer">'
            .'<input type="hidden" name="order_id" value="'.(int) ($order['id'] ?? 0).'">'
            .'<input type="hidden" name="back" value="'.$esc($back).'">'
            .'<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Indietro</button>'
            .'<button type="submit" class="btn btn-primary">Salva</button>'
            .'</div></form></div></div></div>';
    }

    /**
     * Una nota pulita: a capo uniformi e niente spazi ai bordi. `null` se è
     * più lunga del consentito: meglio un rifiuto che una nota tagliata.
     */
    public static function clean(string $note): ?string
    {
        $note = trim(str_replace(["\r\n", "\r"], "\n", $note));

        return mb_strlen($note) > static::MAX ? null : $note;
    }

    /**
     * Salva le note e dice com'è andata. Una nota assente dai valori resta
     * com'è: si cambia solo quello che arriva.
     *
     * @param array<string, mixed> $values
     * @return array{ok: bool, message: string}
     */
    public static function run(int $orderId, array $values): array
    {
        $order = $orderId > 0 ? Order::findById($orderId) : null;

        if (!is_array($order) || $order === []
            || (string) ($order['deleted'] ?? 'false') === 'true'
            || (string) ($order['stage'] ?? '') !== 'order') {
            return ['ok' => false, 'message' => 'Ordine non trovato.'];
        }

        if (static::isLocked($order)) {
            return ['ok' => false, 'message' => static::LOCKED_TEXT];
        }

        $nuove = [];

        foreach (['internal_note', 'document_note'] as $campo) {
            if (!array_key_exists($campo, $values)) {
                continue;
            }

            $pulita = static::clean((string) $values[$campo]);

            if ($pulita === null) {
                return ['ok' => false, 'message' => 'Le note sono troppo lunghe: al massimo '.static::MAX.' caratteri ciascuna.'];
            }

            $nuove[$campo] = $pulita;
        }

        if ($nuove !== []) {
            Order::update($nuove, $orderId);
        }

        return ['ok' => true, 'message' => trim('Note dell\'ordine '.trim((string) ($order['order_number'] ?? ''))).' salvate.'];
    }

    /**
     * Riceve il POST della finestra: `order_id`, le note, `back`. Si torna
     * sempre alla scheda.
     */
    public static function submitFormPage(array $values): string
    {
        $orderId = (int) ($values['order_id'] ?? 0);
        $result = static::run($orderId, $values);
        $back = StockAdjustmentResource::backUrlFrom($values['back'] ?? '');

        if (headers_sent()) {
            return $result['message'];
        }

        if ($result['ok']) {
            FlashAlert::saved($result['message']);
        } else {
            FlashAlert::custom('Attenzione', $result['message'], 'warning');
        }

        header('Location: '.($orderId > 0 ? OrderResource::detailUrl($orderId, $back !== '' ? $back : null) : OrderResource::listUrl()));
        exit();
    }
}
