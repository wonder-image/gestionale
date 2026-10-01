<?php

namespace Wonder\Plugin\Gestionale\Support\Orders;

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

    /** `15/10/2025 10:30`; vuota o zero diventano un trattino. */
    public static function date(string $value): string
    {
        $time = $value === '' || str_starts_with($value, '0000') ? false : strtotime($value);

        return $time === false ? '—' : date('d/m/Y H:i', $time);
    }

    /** Un numero senza zeri inutili: `2.000` → `2`, `1.500` → `1,5`. */
    public static function number(mixed $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 3, ',', ''), '0'), ',');
    }

    /** L'indirizzo su righe: destinatario, via, località; un trattino se manca tutto. */
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
            return '<span class="text-muted">—</span>';
        }

        return implode('<br>', array_map(static fn (string $r): string => self::esc($r), $righe));
    }

    /** @param list<array<string, mixed>> $items */
    public static function items(array $items): string
    {
        if ($items === []) {
            return self::empty('Nessuna riga: l\'ordine non ha ancora articoli.');
        }

        $righe = [];

        foreach ($items as $item) {
            $tipo = (string) ($item['type'] ?? 'product');
            $nome = self::esc((string) ($item['name'] ?? ''));
            $sku = trim((string) ($item['sku'] ?? ''));
            $etichetta = $nome.($sku !== '' ? ' <span class="text-muted small">'.self::esc($sku).'</span>' : '');

            if ($tipo === 'text') {
                $righe[] = '<tr><td colspan="6" class="fst-italic">'.$nome.'</td></tr>';

                continue;
            }

            $sconto = match ((string) ($item['discount_type'] ?? 'none')) {
                'percent' => self::number($item['discount_value'] ?? 0).'%',
                'amount' => self::money($item['discount_value'] ?? 0),
                default => '—',
            };
            $tipoEtichetta = self::ITEM_TYPES[$tipo] ?? $tipo;

            $righe[] = '<tr>'
                .'<td>'.$etichetta.($tipo !== 'product' ? ' <span class="badge text-bg-light">'.self::esc($tipoEtichetta).'</span>' : '').'</td>'
                .'<td class="text-end">'.self::number($item['quantity'] ?? 0).'</td>'
                .'<td class="text-end">'.self::money($item['unit_price'] ?? 0).'</td>'
                .'<td class="text-end">'.$sconto.'</td>'
                .'<td class="text-end">'.self::number($item['tax_rate'] ?? 0).'%</td>'
                .'<td class="text-end">'.self::money($item['line_total'] ?? 0).'</td>'
                .'</tr>';
        }

        return self::table(['Articolo', 'Quantità', 'Prezzo', 'Sconto', 'IVA', 'Totale'], $righe, [1, 2, 3, 4, 5]);
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

    /** I totali: sconto, spedizione e costi compaiono solo se sono diversi da zero. */
    public static function totals(array $order): string
    {
        $voci = [['Prodotti', $order['products_total'] ?? 0, false]];

        foreach ([['Sconto', 'discount_total'], ['Spedizione', 'shipping_total'], ['Costi', 'fees_total']] as [$nome, $campo]) {
            if (abs((float) ($order[$campo] ?? 0)) > 0.004) {
                $voci[] = [$nome, $order[$campo], false];
            }
        }

        $voci[] = ['Imponibile', $order['taxable_total'] ?? 0, false];
        $voci[] = ['IVA', $order['tax_total'] ?? 0, false];
        $voci[] = ['Totale', $order['total'] ?? 0, true];

        $righe = '';

        foreach ($voci as [$nome, $valore, $forte]) {
            $righe .= '<tr'.($forte ? ' class="fw-bold"' : '').'><td>'.self::esc($nome).'</td>'
                .'<td class="text-end">'.self::money($valore).'</td></tr>';
        }

        return '<table class="table table-sm mb-0" style="max-width: 24rem; font-variant-numeric: tabular-nums"><tbody>'.$righe.'</tbody></table>';
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param array<int, string> $methods nome del metodo per id
     */
    public static function payments(array $rows, array $methods): string
    {
        if ($rows === []) {
            return self::empty('Nessun pagamento registrato.');
        }

        $righe = [];

        foreach ($rows as $row) {
            [$stato, $colore] = self::PAYMENT_STATUSES[(string) ($row['status'] ?? '')] ?? [(string) ($row['status'] ?? ''), 'secondary'];
            $metodo = $methods[(int) ($row['payment_method_id'] ?? 0)] ?? '';
            $righe[] = '<tr>'
                .'<td>'.self::esc((string) ($row['code'] ?? '')).'</td>'
                .'<td>'.(($row['type'] ?? '') === 'refund' ? 'Rimborso' : 'Incasso').'</td>'
                .'<td class="text-end">'.self::money($row['amount'] ?? 0).'</td>'
                .'<td><span class="badge text-bg-'.$colore.'">'.self::esc($stato).'</span></td>'
                .'<td>'.self::date((string) ($row['paid_at'] ?? '')).'</td>'
                .'<td>'.($metodo !== '' ? self::esc($metodo) : '—').'</td>'
                .'<td>'.(trim((string) ($row['provider_reference'] ?? '')) !== '' ? self::esc((string) $row['provider_reference']) : '—').'</td>'
                .'</tr>';
        }

        return self::table(['Codice', 'Tipo', 'Importo', 'Stato', 'Data', 'Metodo', 'Riferimento'], $righe, [2]);
    }

    /** @param list<array<string, mixed>> $rows */
    public static function returns(array $rows): string
    {
        if ($rows === []) {
            return self::empty('Nessun reso su questo ordine.');
        }

        $righe = [];

        foreach ($rows as $row) {
            $stato = (string) ($row['status'] ?? '');
            $righe[] = '<tr>'
                .'<td>'.self::esc((string) ($row['number'] ?? $row['code'] ?? '')).'</td>'
                .'<td>'.self::esc(self::RETURN_STATUSES[$stato] ?? $stato).'</td>'
                .'<td>'.self::date((string) ($row['requested_at'] ?? $row['creation'] ?? '')).'</td>'
                .'</tr>';
        }

        return self::table(['Numero', 'Stato', 'Data'], $righe, []);
    }

    /** @param list<array<string, mixed>> $rows */
    public static function history(array $rows): string
    {
        if ($rows === []) {
            return self::empty('Nessun cambio registrato.');
        }

        $righe = [];

        foreach ($rows as $row) {
            $campo = (string) ($row['field'] ?? '');
            $kind = self::LOG_KINDS[$campo] ?? null;
            $valore = static fn (string $v): string => $v === '' ? '—' : self::esc($kind !== null ? StatusLabels::{$kind}($v)['label'] : $v);
            $utente = (int) ($row['user_id'] ?? 0);
            $righe[] = '<tr>'
                .'<td>'.self::date((string) ($row['creation'] ?? '')).'</td>'
                .'<td>'.self::esc(self::LOG_FIELDS[$campo] ?? $campo).'</td>'
                .'<td>'.$valore((string) ($row['from_value'] ?? '')).'</td>'
                .'<td>'.$valore((string) ($row['to_value'] ?? '')).'</td>'
                .'<td>'.self::esc((string) ($row['source'] ?? '')).'</td>'
                .'<td>'.($utente > 0 ? '#'.$utente : '—').'</td>'
                .'</tr>';
        }

        return self::table(['Data', 'Cosa', 'Da', 'A', 'Origine', 'Utente'], $righe, []);
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
