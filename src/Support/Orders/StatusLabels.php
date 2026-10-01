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

    /** L'etichetta colorata, pronta per una cella. `$kind`: `order`, `payment`, `fulfillment`. */
    public static function badge(string $kind, string $value): string
    {
        $entry = match ($kind) {
            'order' => self::order($value),
            'payment' => self::payment($value),
            'fulfillment' => self::fulfillment($value),
            default => ['label' => $value, 'color' => 'secondary'],
        };

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
