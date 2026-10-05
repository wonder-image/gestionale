<?php

namespace Wonder\Plugin\Gestionale\Seeding;

use Wonder\Plugin\Gestionale\Console\Demo\DemoData;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Models\Shipping\Carrier;
use Wonder\Plugin\Gestionale\Models\Shipping\Shipment;
use Wonder\Plugin\Gestionale\Support\Mail\Mailer;
use Wonder\Plugin\Gestionale\Support\Shipping\Shipments;

/**
 * Le spedizioni di prova, sugli ordini di `OrdersDemo`: una consegnata, una
 * parziale in viaggio (metà dei pezzi: il resto aspetta), una in attesa e una
 * con un problema di consegna (per il riquadro della bacheca); una in viaggio
 * in più sull'ordine del coupon, quando i coupon sono accesi.
 *
 * Nascono dal flusso vero (`Shipments`), quindi l'evasione degli ordini è
 * quella che il servizio ricava dalle quantità. Servono la funzionalità
 * `shipping` accesa e gli ordini di prova; senza, il comando lo dice. Il ritiro
 * in sede non c'è: chiede una sede di ritiro aperta nelle impostazioni del
 * sito, che i dati di prova non toccano.
 *
 * Un ordine che ha già una spedizione non si tocca, quindi due giri non
 * duplicano. La pulizia toglie gli ordini che le portano (con le spedizioni):
 * un ordine evaso senza le sue spedizioni non avrebbe più senso.
 */
final class ShipmentsDemo
{
    /** Chiave nel registro dei dati di prova. */
    public const KEY = 'spedizioni-ordini';

    /** Gli ordini che spediscono, come finisce la spedizione. */
    private const PLANS = [
        'carta-pagata' => 'delivered',
        'ospite' => 'partial',
        'azienda' => 'exception',
        'pagamento-parziale' => 'pending',
        // Nasce solo con i coupon accesi.
        'coupon-pagato' => 'in_transit',
    ];

    public static function register(): void
    {
        DemoData::register(
            self::KEY,
            'Spedizioni degli ordini: consegnata, in viaggio, parziale, in attesa, con problema',
            static fn (): int => self::create(),
            static fn (): int => self::clear()
        );
    }

    /** @return int spedizioni create */
    public static function create(): int
    {
        if (!Gestionale::feature('shipping')) {
            DemoData::note('Le spedizioni di prova partono con la funzionalità Spedizioni accesa: accendila e rilancia il comando.');

            return 0;
        }

        $carrier = self::carrier();
        $created = 0;

        // Niente email: sono ordini finti, e i destinatari sono indirizzi di prova.
        Mailer::useTransport(static fn (): bool => true);

        try {
            foreach (self::PLANS as $ref => $end) {
                $order = self::order($ref);

                if ($order === [] || self::shipmentsOf((int) $order['id']) !== [] || !in_array((string) $order['status'], ['confirmed', 'processing'], true)) {
                    continue;
                }

                $created += self::ship((int) $order['id'], $end, $carrier);
            }
        } finally {
            Mailer::useTransport(null);
        }

        return $created;
    }

    /**
     * Toglie gli ordini di prova che hanno spedizioni, con le spedizioni.
     *
     * @return int spedizioni tolte
     */
    public static function clear(): int
    {
        $removed = 0;

        foreach (self::demoOrders() as $order) {
            $shipments = self::shipmentsOf((int) $order['id']);

            if ($shipments === []) {
                continue;
            }

            OrdersDemo::remove($order);
            $removed += count($shipments);
        }

        return $removed;
    }

    /** @return int spedizioni create */
    private static function ship(int $orderId, string $end, int $carrier): int
    {
        $remaining = Shipments::remaining($orderId);
        $source = ['source' => 'system'];

        if ($remaining === []) {
            return 0;
        }

        if ($end === 'partial') {
            $first = (int) array_key_first($remaining);

            // Un pezzo solo non si divide: la spedizione parte intera.
            if ($remaining[$first] >= 2.0) {
                $remaining = [$first => floor($remaining[$first] / 2)];
            }
        }

        $id = Shipments::create($orderId, $remaining, $source + ['carrier_id' => $carrier]);

        if ($end === 'pending') {
            return 1;
        }

        Shipments::ship($id, $source + ['carrier_id' => $carrier, 'tracking_number' => 'DEMO'.str_pad((string) $id, 6, '0', STR_PAD_LEFT)]);

        if (in_array($end, ['delivered', 'exception'], true)) {
            Shipments::advance($id, $end, $source);
        }

        return 1;
    }

    /** Il corriere di prova, se c'è: con un corriere senza link il tracking resta facoltativo. */
    private static function carrier(): int
    {
        $row = Carrier::find(['code' => DemoCode::forModel(Carrier::class, 'corriere'), 'deleted' => 'false'], 1);

        return is_array($row) ? (int) ($row['id'] ?? 0) : 0;
    }

    /** @return array<string, mixed> */
    private static function order(string $ref): array
    {
        $row = Order::find(['code' => DemoCode::forModel(Order::class, $ref), 'deleted' => 'false'], 1);

        return is_array($row) && isset($row['id']) ? $row : [];
    }

    /** @return list<array<string, mixed>> */
    private static function demoOrders(): array
    {
        $out = [];

        foreach (array_keys(self::PLANS) as $ref) {
            $row = Order::find(['code' => DemoCode::forModel(Order::class, $ref)], 1);

            if (is_array($row) && isset($row['id'])) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private static function shipmentsOf(int $orderId): array
    {
        $rows = Shipment::find(['order_id' => $orderId, 'deleted' => 'false']);

        if (!is_array($rows) || $rows === []) {
            return [];
        }

        return isset($rows['id']) ? [$rows] : array_values(array_filter($rows, 'is_array'));
    }
}
