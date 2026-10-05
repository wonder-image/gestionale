<?php

namespace Wonder\Plugin\Gestionale\Support\Contacts;

use Wonder\Elements\Components\DataItem;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Promotions\Coupon;
use Wonder\Plugin\Gestionale\Models\Promotions\CouponCustomer;
use Wonder\Plugin\Gestionale\Models\Promotions\CouponRedemption;
use Wonder\Plugin\Gestionale\Resources\Promotions\CouponResource;
use Wonder\Plugin\Gestionale\Support\Orders\OrderSheet;

/**
 * La scheda del cliente: statistiche in HTML, dati in `DataItem`.
 *
 * Come `OrderSheet` non legge il database: la Resource porta le righe già
 * lette e qui si disegnano. Fa eccezione `coupons()`, che legge i coupon
 * riservati al cliente. Tutto il testo che arriva da un cliente passa da
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
            $html .= '<div class="col-3 col-md-2 col-xl"><div class="border rounded p-3 h-100">'
                .'<div class="small text-muted">'.OrderSheet::esc($etichetta).'</div>'
                .'<div class="fs-5 fw-semibold" style="font-variant-numeric: tabular-nums">'.$valore.'</div></div></div>';
        }

        return '<div class="row g-3">'.$html.'</div>';
    }

    /**
     * I dati della scheda, un `DataItem` ciascuno: tipo, nome, ruolo, stato,
     * contatti, accesso al sito, note. Quelli di fatturazione sono in
     * `billing()`. Dove non c'è un valore compare un trattino.
     *
     * @param array<string, mixed> $contact
     * @return list<DataItem>
     */
    public static function data(array $contact, string $access): array
    {
        $get = static fn (string $key): string => trim((string) ($contact[$key] ?? ''));
        $dato = static fn (string $etichetta, string $valore, bool $html = false, int $colonne = 4): DataItem => DataItem::make($etichetta, $valore)
            ->html($html)
            ->columnSpan(['default' => 12, 'sm' => $colonne]);

        $items = [
            $dato('Tipo', self::TYPES[$get('type')] ?? ''),
            $dato('Nome', trim($get('name').' '.$get('surname'))),
        ];

        if ($get('type') === 'business') {
            $items[] = $dato('Ragione sociale', $get('business_name'));
        }

        $items[] = $dato('Ruolo', Contacts::roles($contact));
        $items[] = $dato('Stato', ($contact['active'] ?? 'true') === 'false'
            ? '<span class="badge text-bg-secondary">Non attiva</span>'
            : '<span class="badge text-bg-success">Attiva</span>', true);
        $items[] = $dato('Email', $get('email'));
        $items[] = $dato('Telefono', trim($get('phone_prefix').' '.$get('phone')));
        $items[] = $dato('Accesso al sito', $access);
        $items[] = $dato('Note', $get('note') !== '' ? nl2br(OrderSheet::esc($get('note'))) : '', true, 12);

        return $items;
    }

    /**
     * I dati di fatturazione, un `DataItem` ciascuno: codice fiscale, per le
     * aziende partita IVA, SDI e PEC, poi l'indirizzo.
     *
     * @param array<string, mixed> $contact
     * @return list<DataItem>
     */
    public static function billing(array $contact): array
    {
        $get = static fn (string $key): string => trim((string) ($contact[$key] ?? ''));
        $dato = static fn (string $etichetta, string $valore, bool $html = false, int $colonne = 6): DataItem => DataItem::make($etichetta, $valore)
            ->html($html)
            ->columnSpan(['default' => 12, 'sm' => $colonne]);

        $items = [$dato('Codice fiscale', $get('cf'))];

        if ($get('type') === 'business') {
            $items[] = $dato('Partita IVA', $get('pi'));
            $items[] = $dato('SDI', $get('sdi'));
            $items[] = $dato('PEC', $get('pec'));
        }

        $items[] = $dato('Indirizzo', OrderSheet::address(self::prefixed($contact), 'c'), true, 12);

        return $items;
    }

    /**
     * Il corpo della card di un indirizzo di consegna: l'etichetta, il
     * «Predefinito» se lo è, l'indirizzo. I pulsanti li aggiunge chi la
     * disegna.
     *
     * @param array<string, mixed> $address
     */
    public static function delivery(array $address): string
    {
        $etichetta = trim((string) ($address['label'] ?? ''));
        $predefinito = ($address['is_default'] ?? 'false') === 'true';

        return '<div class="fw-semibold mb-1">'.OrderSheet::esc($etichetta !== '' ? $etichetta : 'Indirizzo')
            .($predefinito ? ' <span class="badge text-bg-light">Predefinito</span>' : '').'</div>'
            .OrderSheet::address(self::prefixed($address), 'c')
            .(trim((string) ($address['phone'] ?? '')) !== ''
                ? '<div class="small text-muted mt-1">'.OrderSheet::esc(trim((string) ($address['phone_prefix'] ?? '').' '.$address['phone'])).'</div>'
                : '');
    }

    /** La frase degli indirizzi di consegna che non ci sono. */
    public static function noDelivery(): string
    {
        return 'Nessun indirizzo di consegna: si spedisce all\'indirizzo di fatturazione.';
    }

    /**
     * I coupon riservati al cliente: quelli con una riga in `gst_coupon_customers`
     * per lui, non eliminati. `used` conta gli utilizzi non rilasciati di
     * questo cliente (con il suo limite, se il coupon ne ha uno). Con la
     * funzionalità spenta non c'è niente da mostrare.
     *
     * @return list<array{code: string, name: string, discount: string, period: string, used: string, state: string}>
     */
    public static function coupons(int $customerId): array
    {
        if ($customerId <= 0 || !Gestionale::feature('coupons')) {
            return [];
        }

        $now = date('Y-m-d H:i:s');
        $rows = [];

        foreach (self::list(CouponCustomer::find(['customer_id' => $customerId])) as $link) {
            $coupon = Coupon::find(['id' => (int) $link['coupon_id']], 1);

            if (!is_array($coupon) || !isset($coupon['id'])) {
                continue;
            }

            $used = count(array_filter(
                self::list(CouponRedemption::find(['coupon_id' => (int) $coupon['id'], 'customer_id' => $customerId])),
                static fn (array $row): bool => trim((string) ($row['released_at'] ?? '')) === '' || str_starts_with((string) $row['released_at'], '0000-00-00')
            ));
            $limit = (int) ($coupon['usage_limit_per_customer'] ?? 0);

            $rows[] = [
                'code' => (string) $coupon['code'],
                'name' => (string) ($coupon['name'] ?? ''),
                'discount' => CouponResource::discountLabel($coupon),
                'period' => CouponResource::periodLabel($coupon),
                'used' => $limit > 0 ? $used.' / '.$limit : (string) $used,
                'state' => CouponResource::statusLabel($coupon, $now),
            ];
        }

        return $rows;
    }

    /** La tabella dei coupon del cliente, o la frase se non ne ha. */
    public static function couponsTable(array $coupons): string
    {
        if ($coupons === []) {
            return '<p class="text-muted mb-0">Nessun coupon assegnato a questo cliente.</p>';
        }

        $body = '';

        foreach ($coupons as $coupon) {
            $body .= '<tr><td class="fw-semibold">'.OrderSheet::esc((string) $coupon['code']).'</td>'
                .'<td>'.OrderSheet::esc((string) $coupon['discount']).'</td>'
                .'<td>'.OrderSheet::esc((string) $coupon['period']).'</td>'
                .'<td>'.OrderSheet::esc((string) $coupon['used']).'</td>'
                .'<td>'.OrderSheet::esc((string) $coupon['state']).'</td></tr>';
        }

        return '<div class="table-responsive"><table class="table table-sm mb-0"><thead><tr>'
            .'<th>Codice</th><th>Sconto</th><th>Periodo</th><th>Usato</th><th>Stato</th>'
            .'</tr></thead><tbody>'.$body.'</tbody></table></div>';
    }

    /** @return list<array<string, mixed>> */
    private static function list(mixed $found): array
    {
        if (!is_array($found) || $found === []) {
            return [];
        }

        return array_key_exists('id', $found) ? [$found] : array_values(array_filter($found, 'is_array'));
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
