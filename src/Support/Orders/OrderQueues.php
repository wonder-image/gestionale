<?php

namespace Wonder\Plugin\Gestionale\Support\Orders;

use DateTimeImmutable;
use RuntimeException;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Support\Stock\MovementPeriod;

/**
 * Gli ordini su cui c'è da lavorare, contati per il riquadro «Ordini» della
 * home e filtrati dalla voce «Situazione» dell'elenco: le condizioni stanno
 * qui una volta sola, così il numero del riquadro e le righe che si aprono
 * non possono dire cose diverse.
 *
 * - in attesa: da confermare (`pending`);
 * - da evadere: confermati o in lavorazione, con la merce non ancora partita
 *   del tutto (il «Pronto per il ritiro» aspetta il cliente, non il negozio);
 * - da pagare: ordini vivi con il pagamento non saldato, gli stessi su cui la
 *   scheda offre «Registra pagamento».
 */
final class OrderQueues
{
    /** chiave del filtro → etichetta */
    public const LABELS = [
        'in_attesa' => 'In attesa di conferma',
        'da_evadere' => 'Da evadere',
        'da_pagare' => 'Da pagare',
    ];

    /** Le stesse righe dell'elenco Ordini: niente carrelli, preventivi, pagamenti in corso o abbandonati. */
    public const BASE = "`deleted` = 'false' AND `stage` = 'order' AND `status` NOT IN ('awaiting_payment', 'abandoned')";

    /** La condizione di una coda; testo vuoto per una chiave sconosciuta. */
    public static function condition(string $key): string
    {
        return match ($key) {
            'in_attesa' => "`status` = 'pending'",
            'da_evadere' => "`status` IN ('".implode("', '", Lifecycle::COMMITTED)."') AND `fulfillment_status` IN ('unfulfilled', 'partially_fulfilled')",
            'da_pagare' => "`status` IN ('pending', 'confirmed', 'processing') AND `payment_status` IN ('unpaid', 'pending', 'partially_paid')",
            default => '',
        };
    }

    /**
     * La query che conta tutto in un colpo: le tre code, gli ordini del mese
     * e quelli di oggi (per `ordered_at`).
     */
    public static function countSql(DateTimeImmutable $now): string
    {
        $sums = [];

        foreach (array_keys(self::LABELS) as $key) {
            $sums[] = 'COALESCE(SUM('.self::condition($key).'), 0) AS `'.$key.'`';
        }

        $sums[] = 'COALESCE(SUM('.MovementPeriod::sql('questo-mese', 'ordered_at', $now).'), 0) AS `mese`';
        $sums[] = 'COALESCE(SUM('.MovementPeriod::sql('oggi', 'ordered_at', $now).'), 0) AS `oggi`';

        return 'SELECT '.implode(', ', $sums).' FROM `'.Order::$table.'` WHERE '.self::BASE;
    }

    /** @return array{in_attesa: int, da_evadere: int, da_pagare: int, mese: int, oggi: int} */
    public static function counts(?DateTimeImmutable $now = null): array
    {
        $connection = Order::connection();
        $result = $connection->query(self::countSql($now ?? new DateTimeImmutable('now')));

        // Meglio nessun riquadro che quattro zeri verdi falsi.
        if ($result === false) {
            throw new RuntimeException('Conteggio ordini non riuscito: '.$connection->error);
        }

        $row = $result->fetch_assoc();

        return self::normalize(is_array($row) ? $row : []);
    }

    /** @return array{in_attesa: int, da_evadere: int, da_pagare: int, mese: int, oggi: int} */
    public static function normalize(array $row): array
    {
        $counts = [];

        foreach ([...array_keys(self::LABELS), 'mese', 'oggi'] as $key) {
            $counts[$key] = max(0, (int) ($row[$key] ?? 0));
        }

        return $counts;
    }
}
