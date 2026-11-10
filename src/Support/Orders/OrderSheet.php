<?php

namespace Wonder\Plugin\Gestionale\Support\Orders;

use Wonder\Elements\Components\Badge;

/**
 * La scheda dell'ordine, scritta in HTML: ogni sezione è una funzione che
 * prende righe già lette e restituisce il pezzo di pagina.
 *
 * Non legge il database e non scrive niente: la Resource porta le righe, qui
 * si disegnano. Tutto il testo che arriva da un cliente — nomi, note,
 * riferimenti — passa da `esc()`.
 */
final class OrderSheet
{
    private const ITEM_TYPES = [
        'product' => 'Prodotto', 'custom' => 'Riga libera', 'text' => 'Nota', 'shipping' => 'Spedizione', 'fee' => 'Costo',
    ];

    private const PAYMENT_STATUSES = [
        'pending' => ['In attesa', 'warning'], 'paid' => ['Pagato', 'success'],
        'failed' => ['Fallito', 'danger'], 'cancelled' => ['Annullato', 'secondary'],
    ];

    private const RETURN_STATUSES = [
        'requested' => 'Richiesto', 'approved' => 'Approvato', 'rejected' => 'Rifiutato',
        'received' => 'Ricevuto', 'completed' => 'Completato', 'cancelled' => 'Annullato',
    ];

    private const LOG_FIELDS = [
        'status' => 'Ordine', 'payment_status' => 'Pagamento', 'fulfillment_status' => 'Evasione',
        'payment_reminder' => 'Promemoria di pagamento',
        'items' => 'Righe',
        'stage' => 'Documento',
    ];

    private const LOG_KINDS = ['status' => 'order', 'payment_status' => 'payment', 'fulfillment_status' => 'fulfillment'];

    public static function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    /** Un importo all'italiana, con l'euro: `1.234,50 €`. */
    public static function money(mixed $value): string
    {
        return number_format((float) $value, 2, ',', '.').' €';
    }

    /** `15/10/2025 10:30`; vuota o zero diventano una stringa vuota. */
    public static function date(string $value): string
    {
        $time = $value === '' || str_starts_with($value, '0000') ? false : strtotime($value);

        return $time === false ? '' : date('d/m/Y H:i', $time);
    }

    /** Un numero senza zeri inutili: `2.000` → `2`, `1.500` → `1,5`. */
    public static function number(mixed $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 3, ',', ''), '0'), ',');
    }

    /** L'indirizzo su righe: destinatario, via, località; «Non indicato» se manca tutto. */
    public static function address(array $order, string $prefix): string
    {
        $get = static fn (string $key): string => trim((string) ($order[$prefix.'_'.$key] ?? ''));
        $righe = [];

        if ($get('business_name') !== '') {
            $righe[] = $get('business_name');
        }

        $nome = trim($get('name').' '.$get('surname'));

        if ($nome !== '') {
            $righe[] = $nome;
        }

        $via = trim($get('street').' '.$get('number'));

        if ($via !== '') {
            $righe[] = $via;
        }

        if ($get('more') !== '') {
            $righe[] = $get('more');
        }

        $citta = trim($get('cap').' '.$get('city').($get('province') !== '' ? ' ('.$get('province').')' : ''));

        if ($citta !== '') {
            $righe[] = $citta;
        }

        if ($righe === []) {
            return '<span class="text-muted">Non indicato</span>';
        }

        return implode('<br>', array_map(static fn (string $r): string => self::esc($r), $righe));
    }

    /** Il tipo di riga in parole: «Prodotto», «Spedizione», «Nota». */
    public static function itemType(string $type): string
    {
        return self::ITEM_TYPES[$type] ?? $type;
    }

    /** Lo sconto di una riga: `10%`, `5,00 €` o vuoto. */
    public static function discount(array $item): string
    {
        return match ((string) ($item['discount_type'] ?? 'none')) {
            'percent' => self::number($item['discount_value'] ?? 0).'%',
            'amount' => self::money($item['discount_value'] ?? 0),
            default => '',
        };
    }

    /** @param list<array<string, mixed>> $rows */
    public static function taxSummary(array $rows): string
    {
        if ($rows === []) {
            return self::empty('Nessun riepilogo IVA.');
        }

        $righe = [];

        foreach ($rows as $row) {
            $natura = trim((string) ($row['nature'] ?? ''));
            $righe[] = '<tr>'
                .'<td>'.self::number($row['rate'] ?? 0).'%'.($natura !== '' ? ' <span class="text-muted small">'.self::esc($natura).'</span>' : '').'</td>'
                .'<td class="text-end">'.self::money($row['taxable'] ?? 0).'</td>'
                .'<td class="text-end">'.self::money($row['tax'] ?? 0).'</td>'
                .'<td class="text-end">'.self::money($row['total'] ?? 0).'</td>'
                .'</tr>';
        }

        return self::table(['Aliquota', 'Imponibile', 'Imposta', 'Totale'], $righe, [1, 2, 3]);
    }

    /**
     * I totali: sconto, spedizione e costi compaiono solo se sono diversi da zero.
     * Per ogni coupon usato dall'ordine compare una riga informativa col codice e
     * quanto ha fatto risparmiare (merce o spedizione): non entra nei totali, che
     * sono già calcolati. `$redemptions` sono le righe di `gst_coupon_redemptions`
     * dell'ordine, nell'ordine di applicazione; una riga sola si può passare
     * anche da sola. Un ordine di prima, senza usi registrati, mostra il solo
     * codice. I dati restano visibili anche a funzionalità spenta: sono storia.
     *
     * @param list<array<string, mixed>>|array<string, mixed>|null $redemptions
     */
    public static function totals(array $order, ?array $redemptions = null): string
    {
        $voci = [['Prodotti', $order['products_total'] ?? 0, false, false]];

        foreach ([['Sconto', 'discount_total'], ['Spedizione', 'shipping_total'], ['Costi', 'fees_total']] as [$nome, $campo]) {
            if (abs((float) ($order[$campo] ?? 0)) > 0.004) {
                $voci[] = [$nome, $order[$campo], false, false];
            }
        }

        $redemptions ??= [];

        // Compatibilità: una riga sola passata senza lista.
        if ($redemptions !== [] && !array_is_list($redemptions)) {
            $redemptions = [$redemptions];
        }

        $codice = trim((string) ($order['coupon_code'] ?? ''));

        foreach ($redemptions as $redemption) {
            $nome = trim((string) ($redemption['code'] ?? '')) ?: $codice;
            $voci[] = ['Coupon '.$nome, 'risparmio '.self::money($redemption['discount_amount'] ?? 0), false, true];
        }

        if ($redemptions === [] && $codice !== '') {
            $voci[] = ['Coupon '.$codice, '', false, true];
        }

        $voci[] = ['Imponibile', $order['taxable_total'] ?? 0, false, false];
        $voci[] = ['IVA', $order['tax_total'] ?? 0, false, false];
        $voci[] = ['Totale', $order['total'] ?? 0, true, false];

        $righe = '';

        foreach ($voci as [$nome, $valore, $forte, $nota]) {
            $righe .= '<tr'.($forte ? ' class="fw-bold"' : '').($nota ? ' class="text-muted"' : '').'><td>'.self::esc($nome).'</td>'
                .'<td class="text-end">'.($nota ? self::esc($valore) : self::money($valore)).'</td></tr>';
        }

        return '<table class="table table-sm mb-0" style="font-variant-numeric: tabular-nums"><tbody>'.$righe.'</tbody></table>';
    }

    /** Lo stato di un pagamento come etichetta colorata. */
    public static function paymentBadge(string $status): string
    {
        [$testo, $colore] = self::PAYMENT_STATUSES[$status] ?? [$status, 'secondary'];

        return Badge::make($testo)->variant($colore)->inline()->render('bootstrap');
    }

    /** Lo stato di un reso in parole. */
    public static function returnStatus(string $status): string
    {
        return self::RETURN_STATUSES[$status] ?? $status;
    }

    /** Cosa è cambiato nello storico: «Ordine», «Pagamento», «Evasione». */
    public static function logField(string $field): string
    {
        return self::LOG_FIELDS[$field] ?? $field;
    }

    /** Un valore dello storico, con le parole dello stato quando il campo è uno stato; vuoto se manca. */
    public static function logValue(string $field, string $value): string
    {
        if ($value === '') {
            return '';
        }

        // G9: il preventivo accettato diventa un ordine.
        if ($field === 'stage') {
            return ['quote' => 'Preventivo', 'order' => 'Ordine', 'cart' => 'Carrello'][$value] ?? $value;
        }

        $kind = self::LOG_KINDS[$field] ?? null;

        return $kind !== null ? StatusLabels::{$kind}($value)['label'] : $value;
    }

    private static function empty(string $frase): string
    {
        return '<p class="text-muted mb-0">'.self::esc($frase).'</p>';
    }

    /**
     * @param list<string> $head
     * @param list<string> $rows righe `<tr>` già pronte
     * @param list<int> $right indici delle colonne allineate a destra
     */
    private static function table(array $head, array $rows, array $right): string
    {
        $th = '';

        foreach ($head as $i => $nome) {
            $th .= '<th'.(in_array($i, $right, true) ? ' class="text-end"' : '').'>'.self::esc($nome).'</th>';
        }

        return '<div class="table-responsive"><table class="table table-sm align-middle mb-0" style="font-variant-numeric: tabular-nums">'
            .'<thead><tr>'.$th.'</tr></thead><tbody>'.implode('', $rows).'</tbody></table></div>';
    }
}
