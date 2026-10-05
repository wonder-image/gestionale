<?php

namespace Wonder\Plugin\Gestionale\Support\Orders;

use Wonder\Plugin\Gestionale\Support\Returns\ReturnRules;

/**
 * Le azioni che il backend offre su un ordine, e la frase che dice cosa
 * faranno.
 *
 * Pura: non legge e non scrive. Dice *quali* azioni valgono per un ordine; il
 * lavoro lo fa `Lifecycle`, che resta l'unico a cambiare lo stato.
 */
final class OrderActions
{
    public const CONFIRM = 'confirm';
    public const CANCEL = 'cancel';
    public const FULFILL = 'fulfill';

    // Con le spedizioni accese l'evasione non si segna a mano: nasce da queste.
    public const CREATE_SHIPMENT = 'create_shipment';
    public const READY = 'ready_for_pickup';
    public const PICKED_UP = 'picked_up';
    public const SHIP = 'ship';
    public const DELIVER = 'deliver';
    public const CANCEL_SHIPMENT = 'cancel_shipment';

    private const LABELS = [
        self::CONFIRM => 'Conferma', self::FULFILL => 'Segna evaso', self::CANCEL => 'Annulla',
        self::CREATE_SHIPMENT => 'Crea spedizione', self::READY => 'Pronto per il ritiro', self::PICKED_UP => 'Ritirato',
        self::SHIP => 'Segna spedita', self::DELIVER => 'Segna consegnata', self::CANCEL_SHIPMENT => 'Annulla spedizione',
    ];
    private const CLASSES = [
        self::CONFIRM => 'btn-success', self::FULFILL => 'btn-primary', self::CANCEL => 'btn-outline-danger',
        self::CREATE_SHIPMENT => 'btn-primary', self::READY => 'btn-primary', self::PICKED_UP => 'btn-success',
        self::SHIP => 'btn-primary', self::DELIVER => 'btn-success', self::CANCEL_SHIPMENT => 'btn-outline-danger',
    ];
    private const ICONS = [
        self::CONFIRM => 'bi-check2-circle', self::FULFILL => 'bi-box-seam', self::CANCEL => 'bi-x-circle',
        self::CREATE_SHIPMENT => 'bi-box-seam', self::READY => 'bi-bag-check', self::PICKED_UP => 'bi-check2-circle',
        self::SHIP => 'bi-truck', self::DELIVER => 'bi-check2-circle', self::CANCEL_SHIPMENT => 'bi-x-circle',
    ];

    /**
     * Le azioni che valgono adesso, nell'ordine dei pulsanti: annullare sta
     * sempre per ultimo.
     *
     * `$features` entra perché le voci che dipendono da una funzionalità
     * (il reso, nel Piano 5) si aggiungano senza cambiare la firma.
     *
     * @param array<string, mixed> $order
     * @param array<string, bool> $features
     * @return list<string>
     */
    public static function available(array $order, array $features = []): array
    {
        $status = (string) ($order['status'] ?? '');
        $azioni = [];

        if (in_array($status, ['draft', 'pending'], true)) {
            $azioni[] = self::CONFIRM;
        }

        if (in_array($status, ['confirmed', 'processing'], true)) {
            array_push($azioni, ...self::fulfillmentActions($order, $features));
        }

        if (in_array($status, ['draft', 'pending', 'confirmed', 'processing'], true)) {
            $azioni[] = self::CANCEL;
        }

        return $azioni;
    }

    /**
     * Cosa si fa per portare avanti l'evasione.
     *
     * Con le spedizioni spente è il vecchio «Segna evaso». Accese, un ordine da
     * spedire offre «Crea spedizione» finché resta qualcosa da spedire, un
     * ritiro offre «Pronto per il ritiro» e poi «Ritirato»; un ordine senza
     * spedizione né ritiro si evade ancora a mano.
     *
     * @param array<string, mixed> $order
     * @param array<string, bool> $features
     * @return list<string>
     */
    private static function fulfillmentActions(array $order, array $features): array
    {
        $evasione = (string) ($order['fulfillment_status'] ?? 'unfulfilled');
        $tipo = (string) ($order['fulfillment_type'] ?? 'shipping');

        if (empty($features['shipping']) || !in_array($tipo, ['shipping', 'pickup'], true)) {
            return $evasione === 'unfulfilled' ? [self::FULFILL] : [];
        }

        if ($tipo === 'pickup') {
            return match ($evasione) {
                'unfulfilled' => [self::READY],
                'ready_for_pickup' => [self::PICKED_UP],
                default => [],
            };
        }

        return in_array($evasione, ['unfulfilled', 'partially_fulfilled'], true) ? [self::CREATE_SHIPMENT] : [];
    }

    /**
     * Le azioni di una spedizione, secondo il suo stato.
     *
     * @param array<string, mixed> $shipment
     * @return list<string>
     */
    public static function shipmentActions(array $shipment): array
    {
        $stato = (string) ($shipment['status'] ?? '');

        if ((string) ($shipment['type'] ?? 'delivery') === 'pickup') {
            return match ($stato) {
                'pending' => [self::READY, self::CANCEL_SHIPMENT],
                'ready_for_pickup' => [self::PICKED_UP, self::CANCEL_SHIPMENT],
                default => [],
            };
        }

        return match ($stato) {
            'pending', 'label_created' => [self::SHIP, self::CANCEL_SHIPMENT],
            'in_transit', 'out_for_delivery', 'failed_attempt', 'exception' => [self::DELIVER],
            default => [],
        };
    }

    /**
     * «Registra pagamento» vale finché l'ordine è vivo e c'è ancora qualcosa
     * da incassare.
     *
     * Non sta in `available()`: quelle azioni aprono una finestra di
     * conferma, questa porta a una pagina con i suoi campi.
     *
     * @param array<string, mixed> $order
     */
    public static function canRegisterPayment(array $order): bool
    {
        return in_array((string) ($order['status'] ?? ''), ['pending', 'confirmed', 'processing'], true)
            && in_array((string) ($order['payment_status'] ?? 'unpaid'), ['unpaid', 'pending', 'partially_paid'], true);
    }

    /**
     * «Registra reso» vale per un ordine già confermato, con i resi accesi.
     *
     * Come «Registra pagamento» porta a una pagina, non a una finestra di
     * conferma: la regola è quella del servizio (`ReturnRules`).
     *
     * @param array<string, mixed> $order
     * @param array<string, bool> $features
     */
    public static function canRegisterReturn(array $order, array $features = []): bool
    {
        return ReturnRules::eligibleOrder($order, $features);
    }

    public static function label(string $action): string
    {
        return self::LABELS[$action] ?? '';
    }

    public static function buttonClass(string $action): string
    {
        return self::CLASSES[$action] ?? '';
    }

    /** La variante del pulsante pieno che conferma l'azione nella sua finestra: `success`, `primary`, `danger`. */
    public static function variant(string $action): string
    {
        return str_replace(['btn-outline-', 'btn-'], '', self::buttonClass($action)) ?: 'primary';
    }

    public static function icon(string $action): string
    {
        return self::ICONS[$action] ?? '';
    }

    /**
     * La frase della finestra: cosa succede al magazzino e al denaro.
     *
     * I pezzi si contano dalle righe `product` vendute (una confezione da 2 sono 2, non 2 più le figlie). Se l'ordine ha incassato
     * qualcosa, chi chiama mette l'importo in `paid_total`.
     *
     * @param array<string, mixed> $order
     * @param list<array<string, mixed>> $items
     */
    public static function summary(string $action, array $order, array $items): string
    {
        $pezzi = 0.0;

        foreach (OrderLines::sold($items) as $item) {
            if ((string) ($item['type'] ?? '') === 'product') {
                $pezzi += (float) ($item['quantity'] ?? 0);
            }
        }

        $singolare = abs($pezzi - 1.0) < 0.0005;
        $quanti = OrderSheet::number($pezzi).($singolare ? ' pezzo' : ' pezzi');

        return match ($action) {
            self::CONFIRM => 'Conferma l\'ordine e scarica '.$quanti.'.',
            self::CANCEL => self::cancelSentence($order, $quanti, $singolare),
            self::FULFILL => 'Segna l\'ordine come evaso: il magazzino non cambia, la merce è già uscita alla conferma.',
            self::READY => 'Segna l\'ordine pronto per il ritiro e avvisa il cliente: il magazzino non cambia.',
            self::PICKED_UP => 'Segna l\'ordine ritirato dal cliente: l\'ordine risulta evaso.',
            default => '',
        };
    }

    /** @param array<string, mixed> $order */
    private static function cancelSentence(array $order, string $quanti, bool $singolare): string
    {
        $merce = in_array((string) ($order['status'] ?? ''), ['confirmed', 'processing'], true)
            ? 'rimette '.$quanti.' in magazzino'
            : 'libera '.$quanti.($singolare ? ' prenotato' : ' prenotati');
        $frase = 'Annulla l\'ordine e '.$merce.'.';
        $incassato = (float) ($order['paid_total'] ?? 0);

        if ($incassato > 0.004) {
            $frase .= ' Il denaro già incassato ('.OrderSheet::money($incassato).') non si rimborsa da qui.';
        }

        return $frase;
    }
}
