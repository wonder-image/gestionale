<?php

namespace Wonder\Plugin\Gestionale\Support\Documents;

use Throwable;
use Wonder\Plugin\Gestionale\Models\Payments\PaymentAccount;
use Wonder\Plugin\Gestionale\Models\Payments\PaymentMethod;
use Wonder\Plugin\Gestionale\Models\Payments\PaymentTerm;
use Wonder\Plugin\Gestionale\Models\Sales\DeliveryNote;
use Wonder\Plugin\Gestionale\Models\Sales\DeliveryNoteItem;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Models\Sales\OrderItem;
use Wonder\Plugin\Gestionale\Models\Sales\OrderTaxSummary;
use Wonder\Plugin\Gestionale\Support\Payments\Installments;

/**
 * I dati di un PDF di vendita, letti una volta: la società (`$SOCIETY` del
 * core), l'ordine o il preventivo, le righe senza la spedizione (che sta nei
 * totali), il riepilogo IVA e il pagamento. `DocumentPdf` disegna questi, e
 * basta.
 */
final class DocumentData
{
    /** @return array<string, mixed> */
    public static function forOrder(int $orderId): array
    {
        $order = Order::findById($orderId);
        $order = is_array($order) ? $order : [];

        $items = array_values(array_filter(
            self::rows(OrderItem::find(['order_id' => $orderId, 'deleted' => 'false'], null, 'position', 'ASC')),
            static fn (array $i): bool => !in_array((string) ($i['type'] ?? ''), ['shipping'], true)
        ));

        return [
            'society' => self::society(),
            'order' => $order,
            'items' => self::ordered($items),
            'taxes' => self::rows(OrderTaxSummary::find(['order_id' => $orderId])),
            'payment' => self::payment($order),
        ];
    }

    /**
     * I dati del proforma di un ordine: quelli della conferma, con la nota che
     * non vale ai fini fiscali davanti alle note dell'ordine.
     *
     * @return array<string, mixed>
     */
    public static function forOrderProforma(int $orderId): array
    {
        $data = self::forOrder($orderId);
        $note = trim((string) ($data['order']['document_note'] ?? ''));
        $data['order']['document_note'] = 'Documento non valido ai fini fiscali: la fattura segue il pagamento.'
            .($note !== '' ? "\n\n".$note : '');

        return $data;
    }

    /**
     * I dati del DDT: la società, il DDT (con indirizzi e trasporto copiati),
     * le sue righe e il numero dell'ordine.
     *
     * @return array<string, mixed>
     */
    public static function forDeliveryNote(int $id): array
    {
        $note = DeliveryNote::findById($id);
        $note = is_array($note) ? $note : [];
        $order = (int) ($note['order_id'] ?? 0) > 0 ? Order::findById((int) $note['order_id']) : null;

        return [
            'society' => self::society(),
            'note' => $note,
            'order' => is_array($order) ? $order : [],
            'items' => self::rows(DeliveryNoteItem::find(['delivery_note_id' => $id, 'deleted' => 'false'], null, 'position', 'ASC')),
            'taxes' => [],
            'payment' => [],
        ];
    }

    /**
     * I dati del proforma di un periodo di un abbonamento (G10 §3, G10b): una
     * voce per riga addebitata (nome col suo periodo, quantità, importi), i
     * crediti come voci di sconto in negativo, i totali dell'abbonamento,
     * l'IVA per aliquota (`tax_summaries` se c'è, altrimenti quella
     * dell'abbonamento) e le rate in attesa del periodo. Il credito usato
     * scala il totale da pagare (mai sotto zero); se copre tutto, la nota lo
     * dice. Il proforma non ha numero: non è un documento fiscale.
     *
     * @param list<array{name: string, quantity: int, unit_price: float, line_total: float, tax_rate: float}> $lines
     * @param list<array{name: string, amount: float}> $credits
     * @param list<array{due_date: string, amount: float}> $installments le rate del periodo
     * @return array<string, mixed>
     */
    public static function forSubscription(array $subscription, array $lines, array $credits, string $periodStart, string $periodEnd, array $installments): array
    {
        $rate = (float) ($subscription['tax_rate'] ?? 0);
        $subtotal = round((float) ($subscription['subtotal'] ?? 0), 2);
        $summaries = is_array($subscription['tax_summaries'] ?? null) ? $subscription['tax_summaries'] : [];
        unset($subscription['tax_summaries']);
        $order = $subscription + [
            'fulfillment_type' => 'none',
            'products_total' => $subtotal,
            'taxable_total' => round((float) ($subscription['total'] ?? 0) - (float) ($subscription['tax_total'] ?? 0), 2),
            'shipping_total' => 0,
            'document_note' => 'Documento non valido ai fini fiscali: la fattura segue il pagamento.',
        ];
        $order['email'] = '';
        $credit = round(array_sum(array_map(static fn (array $c): float => (float) $c['amount'], $credits)), 2);

        if ($credit > 0.0) {
            $order['discount_total'] = $credit;
            $order['total'] = max(0.0, round((float) ($subscription['total'] ?? 0) - $credit, 2));
        }

        if ($credit > 0.0 && $installments === []) {
            $order['document_note'] = 'Niente da pagare: il credito dei servizi tolti copre il periodo. '.$order['document_note'];
        }

        $payment = self::payment(['payment_method_id' => $subscription['payment_method_id'] ?? 0, 'payment_term_id' => $subscription['payment_term_id'] ?? 0]);
        $payment['installments'] = $installments;
        $items = [];

        foreach ($lines as $line) {
            $items[] = [
                'type' => 'product',
                'name' => (string) $line['name'],
                'quantity' => max(1, (int) $line['quantity']),
                'unit_price' => round((float) $line['unit_price'], 2),
                'line_total' => round((float) $line['line_total'], 2),
                'tax_rate' => (float) $line['tax_rate'],
            ];
        }

        foreach ($credits as $credit) {
            $amount = round((float) $credit['amount'], 2);
            $items[] = [
                'type' => 'discount',
                'name' => (string) $credit['name'],
                'quantity' => 1,
                'unit_price' => -$amount,
                'line_total' => -$amount,
                'tax_rate' => 0.0,
            ];
        }

        $taxes = $summaries !== []
            ? array_map(static fn (array $summary): array => [
                'rate' => (float) ($summary['rate'] ?? 0),
                'nature' => (string) ($summary['nature'] ?? ''),
                'taxable' => round((float) ($summary['taxable'] ?? 0), 2),
                'tax' => round((float) ($summary['tax'] ?? 0), 2),
                'total' => round((float) ($summary['total'] ?? 0), 2),
            ], array_values($summaries))
            : [[
                'rate' => $rate,
                'nature' => '',
                'taxable' => (float) $order['taxable_total'],
                'tax' => round((float) ($subscription['tax_total'] ?? 0), 2),
                'total' => round((float) ($subscription['total'] ?? 0), 2),
            ]];

        return [
            'society' => self::society(),
            'order' => $order,
            'items' => $items,
            'taxes' => $taxes,
            'payment' => $payment,
            'meta' => [
                'Abbonamento' => (string) ($subscription['code'] ?? ''),
                'Periodo' => self::day($periodStart).' - '.self::day($periodEnd),
            ],
        ];
    }

    private static function day(string $value): string
    {
        $value = substr(trim($value), 0, 10);

        return $value === '' || str_starts_with($value, '0000') ? '' : implode('/', array_reverse(explode('-', $value)));
    }

    /**
     * La società come la scrive il core, in righe pronte.
     *
     * @return array<string, string>
     */
    public static function society(): array
    {
        $society = $GLOBALS['SOCIETY'] ?? null;

        if (!is_object($society)) {
            return [];
        }

        $get = static fn (string $key): string => is_scalar($society->{$key} ?? null) ? trim((string) $society->{$key}) : '';
        $vat = array_filter([$get('pi') !== '' ? 'P. IVA '.$get('pi') : '', $get('cf') !== '' && $get('cf') !== $get('pi') ? 'C.F. '.$get('cf') : '']);
        $contacts = array_filter([$get('email'), $get('tel') ?: $get('cel')]);

        return [
            'name' => $get('name'),
            'legal_name' => $get('legal_name'),
            'address' => $get('legal_street') !== '' && $get('addressLegal') !== '' ? $get('addressLegal') : $get('address'),
            'vat' => implode(' - ', $vat),
            'contacts' => implode(' - ', $contacts),
            'logo_path' => self::logoPath($society->logo ?? null),
        ];
    }

    /**
     * Metodo, condizione, rate in attesa e conto del bonifico.
     *
     * @param array<string, mixed> $order
     * @return array<string, mixed>
     */
    private static function payment(array $order): array
    {
        $out = [];
        $method = (int) ($order['payment_method_id'] ?? 0) > 0 ? PaymentMethod::findById((int) $order['payment_method_id']) : null;
        $term = (int) ($order['payment_term_id'] ?? 0) > 0 ? PaymentTerm::findById((int) $order['payment_term_id']) : null;

        if (is_array($method) && $method !== []) {
            $out['method'] = html_entity_decode((string) ($method['name'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');

            if ((string) ($method['provider'] ?? '') === 'bank_transfer') {
                $id = (int) ($method['payment_account_id'] ?? 0);
                $account = $id > 0 ? PaymentAccount::findById($id) : PaymentAccount::find(['active' => 'true', 'deleted' => 'false'], 1);

                if (is_array($account) && trim((string) ($account['iban'] ?? '')) !== '') {
                    $out['iban'] = (string) $account['iban'];
                    $out['holder'] = html_entity_decode((string) ($account['holder'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    $out['bank'] = html_entity_decode((string) ($account['bank_name'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                }
            }
        }

        if (is_array($term) && $term !== []) {
            $out['term'] = html_entity_decode((string) ($term['name'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        if ((string) ($order['stage'] ?? '') === 'order' && (int) ($order['id'] ?? 0) > 0) {
            $out['installments'] = array_map(
                static fn (array $r): array => ['due_date' => $r['due_date'], 'amount' => $r['amount']],
                Installments::pending((int) $order['id'])
            );
        }

        return $out;
    }

    /**
     * Le figlie subito sotto la loro madre, le altre per posizione.
     *
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private static function ordered(array $items): array
    {
        $children = [];
        $top = [];

        foreach ($items as $item) {
            $parent = (int) ($item['parent_item_id'] ?? 0);

            if ($parent > 0) {
                $children[$parent][] = $item;
            } else {
                $top[] = $item;
            }
        }

        $out = [];

        foreach ($top as $item) {
            $out[] = $item;

            foreach ($children[(int) $item['id']] ?? [] as $child) {
                $out[] = $child;
            }
        }

        return $out;
    }

    /** Il logo come file sul disco, se si trova; vuoto altrimenti. */
    private static function logoPath(mixed $logo): string
    {
        if (!is_string($logo) || trim($logo) === '' || !function_exists('__i')) {
            return '';
        }

        try {
            $url = (string) __i($logo)->size(480)->url();
        } catch (Throwable) {
            return '';
        }

        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
        $root = defined('ROOT') ? rtrim((string) constant('ROOT'), '/') : '';
        $file = $root.'/'.ltrim(rawurldecode($path), '/');

        return $root !== '' && is_file($file) ? $file : '';
    }

    /** @return list<array<string, mixed>> */
    private static function rows(mixed $rows): array
    {
        if (!is_array($rows) || $rows === []) {
            return [];
        }

        return isset($rows['id']) ? [$rows] : array_values(array_filter($rows, 'is_array'));
    }
}
