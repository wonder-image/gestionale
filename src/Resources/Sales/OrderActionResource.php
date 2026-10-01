<?php

namespace Wonder\Plugin\Gestionale\Resources\Sales;

use Throwable;
use Wonder\App\LegacyGlobals;
use Wonder\App\Resources\Support\NavigationOnlyResource;
use Wonder\App\ResourceSchema\NavigationSchema;
use Wonder\App\ResourceSchema\PageSchema;
use Wonder\Backend\Support\FlashAlert;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Resources\Stock\StockAdjustmentResource;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Orders\Lifecycle;
use Wonder\Plugin\Gestionale\Support\Orders\OrderActions;
use Wonder\Plugin\Gestionale\Support\Orders\OrderSheet;

/**
 * Le azioni sull'ordine dalla scheda: Conferma, Segna evaso, Annulla.
 *
 * Non ha una pagina sua: riceve il POST delle finestre di conferma della
 * scheda, fa passare l'azione da `Lifecycle` — l'unico che cambia lo stato —
 * e rimanda alla scheda con una frase. Un rifiuto è una frase, mai una pagina
 * d'errore.
 */
final class OrderActionResource extends NavigationOnlyResource
{
    public static function path(): string
    {
        return 'app/gestionale/ordine-azione';
    }

    public static function icon(): string
    {
        return 'bi-check2-circle';
    }

    public static function titleLabel(): string
    {
        return 'Azione sull\'ordine';
    }

    public static function isFormPage(): bool
    {
        return true;
    }

    public static function pageSchema(): PageSchema
    {
        return parent::pageSchema()->only([])->titles(['form' => 'Azione sull\'ordine']);
    }

    public static function navigationSchema(): NavigationSchema
    {
        // Fuori dal menu: ci si arriva dai pulsanti della scheda.
        return NavigationSchema::for(static::class)
            ->inSection('vendite')
            ->title('Azione sull\'ordine')
            ->authority(['admin', 'administrator'])
            ->enabled(false);
    }

    /** Dove postano le finestre della scheda: la rotta di questa pagina, in POST. */
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
     * Esegue l'azione e dice com'è andata.
     *
     * Non solleva per i rifiuti dell'utente: la frase del rifiuto è l'esito.
     * Il backend chiama `Lifecycle` con `source = user` e il proprio `user_id`,
     * perché lo storico dica chi ha premuto il pulsante. La conferma non
     * registra un incasso: il denaro si registra a parte, con «Registra
     * pagamento», e il contrassegno scarica comunque la merce.
     *
     * @return array{ok: bool, message: string}
     */
    public static function run(string $action, int $orderId, int $userId): array
    {
        $order = $orderId > 0 ? Order::findById($orderId) : null;

        if (!is_array($order) || $order === []
            || (string) ($order['deleted'] ?? 'false') === 'true'
            || (string) ($order['stage'] ?? '') !== 'order') {
            return ['ok' => false, 'message' => 'Ordine non trovato.'];
        }

        $name = 'L\'ordine '.trim((string) ($order['order_number'] ?? ''));
        $name = rtrim($name);
        $source = ['source' => 'user', 'user_id' => $userId];

        try {
            return match ($action) {
                OrderActions::CONFIRM => static::confirm($orderId, $name, $source),
                OrderActions::CANCEL => static::cancel($orderId, $name, $source),
                OrderActions::FULFILL => static::fulfill($order, $name, $source),
                default => ['ok' => false, 'message' => 'Azione non riconosciuta.'],
            };
        } catch (UserError $error) {
            return ['ok' => false, 'message' => $error->getMessage()];
        }
    }

    /**
     * Riceve il POST di una finestra: `order_id`, `action`, `torna`.
     *
     * Il rifiuto si tratta qui dentro e si torna sempre alla scheda: il core
     * rimanderebbe invece a questa pagina, che non ha niente da mostrare.
     */
    public static function submitFormPage(array $values): string
    {
        $orderId = (int) ($values['order_id'] ?? 0);
        $user = LegacyGlobals::get('USER');
        $result = static::run(
            (string) ($values['action'] ?? ''),
            $orderId,
            is_object($user) ? (int) ($user->id ?? 0) : 0
        );
        $back = StockAdjustmentResource::backUrlFrom($values['torna'] ?? '');

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

    /** @param array{source: string, user_id: int} $source */
    private static function confirm(int $orderId, string $name, array $source): array
    {
        $esito = Lifecycle::confirm($orderId, $source + ['payment' => false]);

        return $esito['changed']
            ? ['ok' => true, 'message' => $name.' è confermato.']
            : ['ok' => false, 'message' => $name.' è già confermato.'];
    }

    /** @param array{source: string, user_id: int} $source */
    private static function cancel(int $orderId, string $name, array $source): array
    {
        $esito = Lifecycle::cancel($orderId, $source);

        if (!$esito['changed']) {
            return ['ok' => false, 'message' => $name.' è già annullato.'];
        }

        $message = $name.' è annullato.';

        if ((float) $esito['refundable'] > 0.004) {
            $message .= ' Da rimborsare al cliente: '.OrderSheet::money((float) $esito['refundable']).'.';
        }

        return ['ok' => true, 'message' => $message];
    }

    /**
     * @param array<string, mixed> $order
     * @param array{source: string, user_id: int} $source
     */
    private static function fulfill(array $order, string $name, array $source): array
    {
        $status = (string) ($order['status'] ?? '');

        if (!in_array($status, ['confirmed', 'processing'], true)) {
            return ['ok' => false, 'message' => in_array($status, ['draft', 'pending'], true)
                ? $name.' non è ancora confermato: confermalo prima di segnarlo evaso.'
                : $name.' è chiuso o annullato: non si può più segnare evaso.'];
        }

        $esito = Lifecycle::fulfill((int) $order['id'], 'fulfilled', $source);

        return $esito['changed']
            ? ['ok' => true, 'message' => $name.' è segnato come evaso.']
            : ['ok' => false, 'message' => $name.' è già evaso.'];
    }
}
