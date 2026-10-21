<?php

namespace Wonder\Plugin\Gestionale\Support\Orders;

/**
 * Come si chiamano e di che colore sono i tre stati di un ordine.
 *
 * Elenco e scheda dicono le stesse parole con gli stessi colori: stanno qui,
 * una volta sola. Un valore che non conosce non lancia — lo mostra com'è, in
 * grigio — perché uno stato nuovo aggiunto domani non deve rompere una pagina.
 */
final class StatusLabels
{
    private const ORDER = [
        'draft' => ['Bozza', 'secondary'],
        'sent' => ['Inviato', 'info'],
        'rejected' => ['Rifiutato', 'danger'],
        'expired' => ['Scaduto', 'secondary'],
        'pending' => ['In attesa', 'warning'],
        'confirmed' => ['Confermato', 'success'],
        'processing' => ['In lavorazione', 'info'],
        'completed' => ['Completato', 'success'],
        'cancelled' => ['Annullato', 'danger'],
        'awaiting_payment' => ['In pagamento', 'secondary'],
        'abandoned' => ['Abbandonato', 'dark'],
    ];

    private const PAYMENT = [
        'unpaid' => ['Da pagare', 'secondary'],
        'pending' => ['In attesa', 'warning'],
        'partially_paid' => ['Pagato in parte', 'warning'],
        'paid' => ['Pagato', 'success'],
        'partially_refunded' => ['Rimborsato in parte', 'info'],
        'refunded' => ['Rimborsato', 'secondary'],
    ];

    private const FULFILLMENT = [
        'unfulfilled' => ['Da evadere', 'secondary'],
        'ready_for_pickup' => ['Pronto per il ritiro', 'info'],
        'partially_fulfilled' => ['Evaso in parte', 'warning'],
        'fulfilled' => ['Evaso', 'success'],
    ];

    /** Le parole dell'evasione per come arriva la merce: i colori restano quelli di `FULFILLMENT`. */
    private const FULFILLMENT_WORDS = [
        'shipping' => ['unfulfilled' => 'Da spedire', 'partially_fulfilled' => 'Spedito in parte', 'fulfilled' => 'Spedito'],
        'pickup' => ['unfulfilled' => 'Da preparare', 'ready_for_pickup' => 'Pronto per il ritiro', 'fulfilled' => 'Ritirato'],
        'none' => ['unfulfilled' => 'Da consegnare', 'partially_fulfilled' => 'Consegnato in parte', 'fulfilled' => 'Consegnato'],
    ];

    private const SHIPMENT = [
        'pending' => ['In attesa', 'warning'],
        'label_created' => ['Etichetta creata', 'info'],
        'in_transit' => ['In viaggio', 'primary'],
        'out_for_delivery' => ['In consegna', 'primary'],
        'delivered' => ['Consegnata', 'success'],
        'failed_attempt' => ['Consegna fallita', 'danger'],
        'exception' => ['Problema', 'danger'],
        'returned' => ['Tornata al mittente', 'secondary'],
        'cancelled' => ['Annullata', 'secondary'],
        'ready_for_pickup' => ['Pronta per il ritiro', 'info'],
        'picked_up' => ['Ritirata', 'success'],
    ];

    /** @return array{label: string, color: string} */
    public static function order(string $status): array
    {
        return self::pick(self::ORDER, $status);
    }

    /** @return array{label: string, color: string} */
    public static function payment(string $status): array
    {
        return self::pick(self::PAYMENT, $status);
    }

    /** @return array{label: string, color: string} */
    public static function fulfillment(string $status): array
    {
        return self::pick(self::FULFILLMENT, $status);
    }

    /**
     * Lo stato dell'evasione con le parole di come arriva la merce: «Da
     * spedire», «Spedito in parte», «Spedito» per la spedizione; «Da
     * preparare», «Pronto per il ritiro», «Ritirato» per il ritiro; «Da
     * consegnare», «Consegnato» senza nessuna delle due.
     *
     * @return array{label: string, color: string}
     */
    public static function fulfillmentFor(string $type, string $status): array
    {
        $words = self::FULFILLMENT_WORDS[$type] ?? self::FULFILLMENT_WORDS['shipping'];
        $entry = self::fulfillment($status);

        return isset($words[$status]) ? ['label' => $words[$status], 'color' => $entry['color']] : $entry;
    }

    /** Il badge di `fulfillmentFor()`. */
    public static function fulfillmentBadge(string $type, string $status): string
    {
        $entry = self::fulfillmentFor($type, $status);

        // Non con Badge del core: lo avvolge sempre in un <div>, qui serve in linea.
        return '<span class="badge text-bg-'.$entry['color'].'">'
            .htmlspecialchars($entry['label'], ENT_QUOTES, 'UTF-8').'</span>';
    }

    /** @return array{label: string, color: string} */
    public static function shipment(string $status): array
    {
        return self::pick(self::SHIPMENT, $status);
    }

    /** L'etichetta colorata, pronta per una cella. `$kind`: `order`, `payment`, `fulfillment`, `shipment`. */
    public static function badge(string $kind, string $value): string
    {
        $entry = match ($kind) {
            'order' => self::order($value),
            'payment' => self::payment($value),
            'fulfillment' => self::fulfillment($value),
            'shipment' => self::shipment($value),
            default => ['label' => $value, 'color' => 'secondary'],
        };

        // Non con Badge del core: lo avvolge sempre in un <div>, qui serve in linea.
        return '<span class="badge text-bg-'.$entry['color'].'">'
            .htmlspecialchars($entry['label'], ENT_QUOTES, 'UTF-8').'</span>';
    }

    /**
     * @param array<string, array{0: string, 1: string}> $table
     * @return array{label: string, color: string}
     */
    private static function pick(array $table, string $status): array
    {
        return isset($table[$status])
            ? ['label' => $table[$status][0], 'color' => $table[$status][1]]
            : ['label' => $status, 'color' => 'secondary'];
    }
}
