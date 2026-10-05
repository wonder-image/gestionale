<?php

namespace Wonder\Plugin\Gestionale\Resources\Sales;

use Throwable;
use Wonder\App\LegacyGlobals;
use Wonder\App\Resources\Support\NavigationOnlyResource;
use Wonder\App\ResourceSchema\NavigationSchema;
use Wonder\App\ResourceSchema\PageSchema;
use Wonder\App\ResourceSchema\PermissionSchema;
use Wonder\Backend\Support\FlashAlert;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Models\Shipping\Shipment;
use Wonder\Plugin\Gestionale\Resources\Stock\StockAdjustmentResource;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Orders\Lifecycle;
use Wonder\Plugin\Gestionale\Support\Orders\OrderActions;
use Wonder\Plugin\Gestionale\Support\Orders\OrderSheet;
use Wonder\Plugin\Gestionale\Support\Shipping\Shipments;

/**
 * Le azioni sull'ordine dalla scheda: Conferma, Segna evaso, Annulla e, con le
 * spedizioni accese, quelle che portano avanti l'evasione (crea, segna spedita,
 * consegnata, annulla, pronto per il ritiro, ritirato).
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

    /** Le pagine-form leggono `edit` (apertura) e `update` (invio): le azioni toccano denaro e magazzino, come l'elenco. */
    public static function permissionSchema(): PermissionSchema
    {
        return PermissionSchema::for(static::class)->backend(['edit', 'update'], ['admin', 'administrator']);
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
     * `$values` porta i campi delle finestre: `qty` (riga => quantità),
     * `carrier_id`, `tracking_number`, `after` (`ship` = segna subito spedita)
     * e `shipment_id`. Una spedizione indicata deve essere di quest'ordine.
     *
     * @param array<string, mixed> $values
     * @return array{ok: bool, message: string}
     */
    public static function run(string $action, int $orderId, int $userId, array $values = []): array
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
                OrderActions::CREATE_SHIPMENT => static::createShipment($orderId, $name, $source, $values),
                OrderActions::SHIP => static::ship($orderId, $name, $source, $values),
                OrderActions::DELIVER => static::deliver($orderId, $name, $source, $values),
                OrderActions::CANCEL_SHIPMENT => static::cancelShipment($orderId, $name, $source, $values),
                OrderActions::READY => static::ready($orderId, $name, $source, $values),
                OrderActions::PICKED_UP => static::pickedUp($orderId, $name, $source, $values),
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
            is_object($user) ? (int) ($user->id ?? 0) : 0,
            static::shipmentValues($values)
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

        // Con le spedizioni accese l'evasione nasce da spedizioni e ritiri, non da un clic.
        if (Gestionale::feature('shipping') && in_array((string) ($order['fulfillment_type'] ?? ''), ['shipping', 'pickup'], true)) {
            return ['ok' => false, 'message' => $name.' si evade con le spedizioni: '
                .((string) $order['fulfillment_type'] === 'pickup' ? 'segnalalo pronto per il ritiro' : 'crea una spedizione').'.'];
        }

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

    /**
     * I campi delle finestre di spedizione, letti dal POST: le quantità
     * arrivano come `qty_<riga>` (un campo per riga) e qui diventano `qty[riga]`.
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private static function shipmentValues(array $values): array
    {
        $qty = [];

        foreach ($values as $key => $value) {
            if (is_string($key) && preg_match('/^qty_(\d+)$/', $key, $match)) {
                $qty[(int) $match[1]] = is_scalar($value) ? trim((string) $value) : '';
            }
        }

        return [
            'qty' => $qty,
            'carrier_id' => (int) ($values['carrier_id'] ?? 0),
            'tracking_number' => is_scalar($values['tracking_number'] ?? null) ? trim((string) $values['tracking_number']) : '',
            'after' => (string) ($values['after'] ?? ''),
            'shipment_id' => (int) ($values['shipment_id'] ?? 0),
        ];
    }

    private static function guardShipping(): ?array
    {
        return Gestionale::feature('shipping')
            ? null
            : ['ok' => false, 'message' => 'Le spedizioni non sono attive.'];
    }

    /**
     * La spedizione indicata, se è davvero di quest'ordine e viva.
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed>|null
     */
    private static function shipmentOf(int $orderId, array $values): ?array
    {
        $id = (int) ($values['shipment_id'] ?? 0);
        $shipment = $id > 0 ? Shipment::findById($id) : null;

        if (!is_array($shipment) || $shipment === []
            || (string) ($shipment['deleted'] ?? 'false') === 'true'
            || (int) ($shipment['order_id'] ?? 0) !== $orderId) {
            return null;
        }

        return $shipment;
    }

    /** @param array{source: string, user_id: int} $source @param array<string, mixed> $values */
    private static function createShipment(int $orderId, string $name, array $source, array $values): array
    {
        if (($stop = static::guardShipping()) !== null) {
            return $stop;
        }

        $carrier = (int) ($values['carrier_id'] ?? 0);
        $tracking = (string) ($values['tracking_number'] ?? '');
        $id = Shipments::create($orderId, (array) ($values['qty'] ?? []), $source + [
            'carrier_id' => $carrier,
            'tracking_number' => $tracking,
        ]);

        if ((string) ($values['after'] ?? '') === 'ship') {
            try {
                Shipments::ship($id, $source + ['carrier_id' => $carrier, 'tracking_number' => $tracking]);
            } catch (UserError $error) {
                // La spedizione è creata: resta in attesa e si dice perché non è partita.
                return ['ok' => true, 'message' => 'Spedizione creata per '.$name.', ma non è partita: '.$error->getMessage()];
            }

            return ['ok' => true, 'message' => 'Spedizione creata e partita per '.$name.'.'];
        }

        return ['ok' => true, 'message' => 'Spedizione creata per '.$name.'.'];
    }

    /** @param array{source: string, user_id: int} $source @param array<string, mixed> $values */
    private static function ship(int $orderId, string $name, array $source, array $values): array
    {
        if (($stop = static::guardShipping()) !== null) {
            return $stop;
        }

        $shipment = static::shipmentOf($orderId, $values);

        if ($shipment === null) {
            return ['ok' => false, 'message' => 'Spedizione non trovata.'];
        }

        Shipments::ship((int) $shipment['id'], $source + [
            'carrier_id' => (int) ($values['carrier_id'] ?? 0),
            'tracking_number' => (string) ($values['tracking_number'] ?? ''),
        ]);

        return ['ok' => true, 'message' => 'La spedizione di '.$name.' è partita.'];
    }

    /** @param array{source: string, user_id: int} $source @param array<string, mixed> $values */
    private static function deliver(int $orderId, string $name, array $source, array $values): array
    {
        if (($stop = static::guardShipping()) !== null) {
            return $stop;
        }

        $shipment = static::shipmentOf($orderId, $values);

        if ($shipment === null) {
            return ['ok' => false, 'message' => 'Spedizione non trovata.'];
        }

        Shipments::advance((int) $shipment['id'], 'delivered', $source);

        return ['ok' => true, 'message' => 'La spedizione di '.$name.' è consegnata.'];
    }

    /** @param array{source: string, user_id: int} $source @param array<string, mixed> $values */
    private static function cancelShipment(int $orderId, string $name, array $source, array $values): array
    {
        if (($stop = static::guardShipping()) !== null) {
            return $stop;
        }

        $shipment = static::shipmentOf($orderId, $values);

        if ($shipment === null) {
            return ['ok' => false, 'message' => 'Spedizione non trovata.'];
        }

        Shipments::cancel((int) $shipment['id'], $source);

        return ['ok' => true, 'message' => 'Spedizione annullata: le quantità tornano da spedire per '.$name.'.'];
    }

    /**
     * «Pronto per il ritiro»: se il ritiro non c'è ancora lo crea, poi lo segna
     * pronto. Ripetuta, lascia le cose come stanno.
     *
     * @param array{source: string, user_id: int} $source @param array<string, mixed> $values
     */
    private static function ready(int $orderId, string $name, array $source, array $values): array
    {
        if (($stop = static::guardShipping()) !== null) {
            return $stop;
        }

        $id = static::pickupOf($orderId, $values);
        $id = $id > 0 ? $id : Shipments::createPickup($orderId, $source);
        Shipments::ready($id, $source);

        return ['ok' => true, 'message' => $name.' è pronto per il ritiro: il cliente è stato avvisato.'];
    }

    /** @param array{source: string, user_id: int} $source @param array<string, mixed> $values */
    private static function pickedUp(int $orderId, string $name, array $source, array $values): array
    {
        if (($stop = static::guardShipping()) !== null) {
            return $stop;
        }

        $id = static::pickupOf($orderId, $values);

        if ($id <= 0) {
            return ['ok' => false, 'message' => 'Non c\'è un ritiro pronto per '.$name.'.'];
        }

        Shipments::pickedUp($id, $source);

        return ['ok' => true, 'message' => $name.' è stato ritirato dal cliente.'];
    }

    /**
     * Il ritiro vivo dell'ordine (o quello indicato): 0 se non c'è.
     *
     * @param array<string, mixed> $values
     */
    private static function pickupOf(int $orderId, array $values): int
    {
        if ((int) ($values['shipment_id'] ?? 0) > 0) {
            $shipment = static::shipmentOf($orderId, $values);

            return is_array($shipment) && (string) $shipment['type'] === 'pickup' ? (int) $shipment['id'] : 0;
        }

        $found = Shipment::find(['order_id' => $orderId, 'type' => 'pickup', 'deleted' => 'false']);
        $rows = is_array($found) && array_key_exists('id', $found) ? [$found] : array_values(array_filter((array) $found, 'is_array'));

        foreach ($rows as $row) {
            if ((string) ($row['status'] ?? '') !== 'cancelled') {
                return (int) $row['id'];
            }
        }

        return 0;
    }
}
