<?php

namespace Wonder\Plugin\Gestionale\Support\Contacts;

use Wonder\Plugin\Gestionale\Support\Orders\OrderSheet;

/**
 * La scheda del cliente, scritta in HTML: statistiche e dati.
 *
 * Come `OrderSheet` non legge il database: la Resource porta le righe già
 * lette e qui si disegnano. Tutto il testo che arriva da un cliente passa da
 * `OrderSheet::esc()`.
 */
final class CustomerSheet
{
    private const TYPES = ['private' => 'Privato', 'business' => 'Azienda'];

    private const ADDRESS_KEYS = ['business_name', 'name', 'surname', 'street', 'number', 'more', 'cap', 'city', 'province'];

    /**
     * Le statistiche di `CustomerStats`, in caselle.
     *
     * @param array<string, mixed> $stats
     */
    public static function stats(array $stats): string
    {
        $cart = (int) $stats['cart_items'] > 0
            ? OrderSheet::money($stats['cart_value']).'<div class="small text-muted fw-normal">'.(int) $stats['cart_items'].' '.((int) $stats['cart_items'] === 1 ? 'pezzo' : 'pezzi').'</div>'
            : '<span class="text-muted">vuoto</span>';

        $caselle = [
            ['Ordini', (string) (int) $stats['orders'].((int) $stats['cancelled'] > 0 ? '<div class="small text-muted fw-normal">'.(int) $stats['cancelled'].' annullati</div>' : '')],
            ['Speso', OrderSheet::money($stats['spent'])],
            ['Scontrino medio', (int) $stats['orders'] > 0 ? OrderSheet::money($stats['average']) : '—'],
            ['Primo ordine', self::day((string) $stats['first_order'])],
            ['Ultimo ordine', self::day((string) $stats['last_order'])],
            ['Da pagare', (string) (int) $stats['to_pay'].' '.((int) $stats['to_pay'] === 1 ? 'ordine' : 'ordini')],
            ['Carrello', $cart],
        ];

        $html = '';

        foreach ($caselle as [$etichetta, $valore]) {
            $html .= '<div class="col-6 col-md-4 col-xl"><div class="border rounded p-3 h-100">'
                .'<div class="small text-muted">'.OrderSheet::esc($etichetta).'</div>'
                .'<div class="fs-5 fw-semibold" style="font-variant-numeric: tabular-nums">'.$valore.'</div></div></div>';
        }

        return '<div class="row g-3">'.$html.'</div>';
    }

    /**
     * Tutti i dati della scheda, in blocchi: chi è, contatti, fatturazione,
     * consegne, accesso al sito, note. Dove non c'è un valore, un trattino.
     *
     * @param array<string, mixed> $contact
     * @param list<array<string, mixed>> $addresses indirizzi di consegna
     */
    public static function details(array $contact, array $addresses, string $access): string
    {
        $get = static fn (string $key): string => trim((string) ($contact[$key] ?? ''));
        $azienda = $get('type') === 'business';
        $telefono = trim($get('phone_prefix').' '.$get('phone'));

        $chiE = [
            ['Tipo', self::text(self::TYPES[$get('type')] ?? '')],
            ['Nome', self::text(trim($get('name').' '.$get('surname')))],
        ];

        if ($azienda) {
            $chiE[] = ['Ragione sociale', self::text($get('business_name'))];
        }

        $chiE[] = ['Ruolo', self::text(Contacts::roles($contact))];
        $chiE[] = ['Stato', ($contact['active'] ?? 'true') === 'false'
            ? '<span class="badge text-bg-secondary">Non attiva</span>'
            : '<span class="badge text-bg-success">Attiva</span>'];

        $fatturazione = [['Codice fiscale', self::text($get('cf'))]];

        if ($azienda) {
            $fatturazione[] = ['Partita IVA', self::text($get('pi'))];
            $fatturazione[] = ['SDI', self::text($get('sdi'))];
            $fatturazione[] = ['PEC', self::text($get('pec'))];
        }

        $fatturazione[] = ['Indirizzo', OrderSheet::address(self::prefixed($contact), 'c')];

        $consegne = $addresses === []
            ? '<p class="text-muted mb-0">Nessun indirizzo di consegna: si spedisce all\'indirizzo di fatturazione.</p>'
            : '<div class="row g-3">'.implode('', array_map(static fn (array $a): string => self::delivery($a), $addresses)).'</div>';

        return self::block('Chi è', $chiE)
            .self::block('Contatti', [['Email', self::text($get('email'))], ['Telefono', self::text($telefono)]])
            .self::block('Dati di fatturazione', $fatturazione)
            .'<h6 class="mt-4">Indirizzi di consegna</h6>'.$consegne
            .self::block('Accesso al sito', [['Metodo', self::text($access)]])
            .'<h6 class="mt-4">Note</h6>'
            .($get('note') !== '' ? '<p class="mb-0">'.nl2br(OrderSheet::esc($get('note'))).'</p>' : '<p class="text-muted mb-0">Nessuna nota.</p>');
    }

    /** La frase dei coupon, finché i coupon non esistono. */
    public static function couponsEmpty(): string
    {
        return 'Nessun coupon assegnato: i coupon arriveranno con la loro funzionalità e compariranno qui.';
    }

    private static function delivery(array $address): string
    {
        $etichetta = trim((string) ($address['label'] ?? ''));
        $predefinito = ($address['is_default'] ?? 'false') === 'true';

        return '<div class="col-12 col-md-6 col-xl-4"><div class="border rounded p-3 h-100">'
            .'<div class="fw-semibold mb-1">'.OrderSheet::esc($etichetta !== '' ? $etichetta : 'Indirizzo')
            .($predefinito ? ' <span class="badge text-bg-light">Predefinito</span>' : '').'</div>'
            .OrderSheet::address(self::prefixed($address), 'c').'</div></div>';
    }

    /**
     * Un blocco di coppie «etichetta: valore».
     *
     * @param list<array{0: string, 1: string}> $rows il valore è HTML già escapato
     */
    private static function block(string $titolo, array $rows): string
    {
        $html = '';

        foreach ($rows as [$etichetta, $valore]) {
            $html .= '<dt class="col-sm-3 text-muted fw-normal">'.OrderSheet::esc($etichetta).'</dt><dd class="col-sm-9">'.$valore.'</dd>';
        }

        return '<h6 class="mt-4">'.OrderSheet::esc($titolo).'</h6><dl class="row mb-0">'.$html.'</dl>';
    }

    private static function text(string $value): string
    {
        return $value !== '' ? OrderSheet::esc($value) : '<span class="text-muted">—</span>';
    }

    /** Il giorno senza l'ora: `10/09/2026`; un trattino se manca. */
    private static function day(string $value): string
    {
        $data = OrderSheet::date($value);

        return $data === '—' ? $data : substr($data, 0, 10);
    }

    /**
     * L'indirizzo della scheda ha le colonne senza prefisso; `OrderSheet::address`
     * le vuole con il prefisso, come sull'ordine.
     *
     * @return array<string, string>
     */
    private static function prefixed(array $row): array
    {
        $out = [];

        foreach (self::ADDRESS_KEYS as $key) {
            $out['c_'.$key] = (string) ($row[$key] ?? '');
        }

        return $out;
    }
}
