<?php

namespace Wonder\Plugin\Gestionale\Support\Documents;

use Wonder\Plugin\Gestionale\Models\Sales\Order;

/**
 * Il proforma di un ordine (online o da ufficio): quando c'è, come si chiama
 * il file, e il PDF. Lo usano la scheda dell'ordine nel backend e l'area
 * cliente del sito, con la stessa regola.
 */
final class OrderProforma
{
    /** Gli stati senza proforma: la bozza non è ancora un ordine, l'annullato non si paga. */
    public const EXCLUDED_STATUSES = ['draft', 'cancelled'];

    /** @param array<string, mixed> $order */
    public static function available(array $order): bool
    {
        return (int) ($order['id'] ?? 0) > 0
            && (string) ($order['stage'] ?? '') === 'order'
            && (string) ($order['deleted'] ?? 'false') === 'false'
            && !in_array((string) ($order['status'] ?? ''), self::EXCLUDED_STATUSES, true);
    }

    /** @param array<string, mixed> $order */
    public static function fileName(array $order): string
    {
        $number = trim((string) ($order['order_number'] ?? '')) ?: (string) (int) ($order['id'] ?? 0);

        return 'proforma-ordine-'.preg_replace('/[^A-Za-z0-9_-]+/', '-', $number).'.pdf';
    }

    /** Il PDF dell'ordine com'è adesso: non si archivia, così dopo un cambio è già aggiornato. */
    public static function pdf(int $orderId): string
    {
        return DocumentPdfs::render('order_proforma', DocumentData::forOrderProforma($orderId));
    }

    /** Lo manda al browser e chiude: `inline`, così si apre e si scarica dal visore. */
    public static function send(array $order): never
    {
        $pdf = self::pdf((int) $order['id']);

        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="'.self::fileName($order).'"');
        header('Content-Length: '.strlen($pdf));
        header('Cache-Control: private, no-store');
        header('X-Robots-Tag: noindex, nofollow');

        echo $pdf;
        exit;
    }

    /** L'ordine, se ha il proforma; altrimenti null. @return array<string, mixed>|null */
    public static function order(int $orderId): ?array
    {
        $order = $orderId > 0 ? Order::findById($orderId) : null;

        return is_array($order) && self::available($order) ? $order : null;
    }
}
