<?php

namespace Wonder\Plugin\Gestionale\Support\Contacts;

use Wonder\Elements\Components\Badge;
use Wonder\Elements\Components\DataItem;
use Wonder\Elements\Components\Link;
use Wonder\Plugin\Gestionale\Resources\Promotions\CouponResource;
use Wonder\Plugin\Gestionale\Resources\Subscriptions\SubscriptionResource;
use Wonder\Plugin\Gestionale\Support\Orders\OrderSheet;
use Wonder\Plugin\Gestionale\Support\Promotions\Coupons;
use Wonder\Plugin\Gestionale\Support\Subscriptions\SubscriptionItems;

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
            ['Scontrino medio', (int) $stats['orders'] > 0 ? OrderSheet::money($stats['average']) : '<span class="text-muted">nessuno</span>'],
            ['Primo ordine', OrderSheet::esc(self::day((string) $stats['first_order'])) ?: '<span class="text-muted">nessuno</span>'],
            ['Ultimo ordine', OrderSheet::esc(self::day((string) $stats['last_order'])) ?: '<span class="text-muted">nessuno</span>'],
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
     * accesso al sito, note. I contatti sono in `points()`, quelli di
     * fatturazione in `billing()`. Dove non c'è un valore compare «Non indicato». `$role`
     * prende il posto del ruolo salvato (il referente non ne ha uno).
     *
     * @param array<string, mixed> $contact
     * @return list<DataItem>
     */
    public static function data(array $contact, string $access, string $role = ''): array
    {
        $get = static fn (string $key): string => trim((string) ($contact[$key] ?? ''));
        $dato = static fn (string $etichetta, string $valore, bool $html = false, int $colonne = 4): DataItem => DataItem::make($etichetta, $valore)
            ->html($html)
            ->placeholder('Non indicato')
            ->columnSpan(['default' => 12, 'sm' => $colonne]);

        $items = self::isCompany($contact)
            ? [$dato('Tipo', 'Azienda'), $dato('Ragione sociale', $get('business_name'))]
            : [$dato('Tipo', 'Persona'), $dato('Nome', trim($get('name').' '.$get('surname')))];

        $items[] = $dato('Ruolo', $role !== '' ? $role : Contacts::roles($contact));
        $items[] = $dato('Stato', ($contact['active'] ?? 'true') === 'false'
            ? Badge::make('Non attiva')->variant('secondary')->inline()->render('bootstrap')
            : Badge::make('Attiva')->variant('success')->inline()->render('bootstrap'), true);
        $items[] = $dato('Accesso al sito', $access);
        $items[] = $dato('Note', $get('note') !== '' ? nl2br(OrderSheet::esc($get('note'))) : '', true, 12);

        return $items;
    }

    /**
     * I contatti della scheda (`ContactPoints::all()` di customer-account),
     * un recapito per riga: prima email e telefono principali, poi gli altri,
     * con l'etichetta («Email di accesso» per quella con cui la persona entra
     * nel sito), «principale» e gli uffici. Con `$kinds` (il CRM) ogni recapito
     * dice per primo il suo tipo: email, telefono fisso, cellulare, WhatsApp.
     * Senza recapiti la voce dice «Nessun recapito». Tutto escapato.
     *
     * @param list<array{kind?: string, value?: string, phone_prefix?: string, label?: string, is_primary?: bool, departments?: list<string>}> $points
     * @param array<string, string> $labels etichetta => testo (`ContactPoints::labels()` più quella dell'accesso)
     * @param array<string, string> $areas ufficio => testo (`ContactRelations::departments()`)
     * @param array<string, string> $kinds tipo => testo (`ContactPoints::kindLabel()`); vuoto = il tipo non si mostra
     * @return list<DataItem>
     */
    public static function points(array $points, array $labels = [], array $areas = [], array $kinds = []): array
    {
        $lines = [];

        foreach ($points as $point) {
            $value = trim((string) ($point['value'] ?? ''));

            if ($value === '') {
                continue;
            }

            if (\Wonder\Plugin\CustomerAccount\Support\ContactPoints::isPhone($point['kind'] ?? '')) {
                $value = trim((string) ($point['phone_prefix'] ?? '').' '.$value);
            }

            $details = array_filter([
                $kinds[(string) ($point['kind'] ?? '')] ?? '',
                $labels[(string) ($point['label'] ?? '')] ?? '',
                !empty($point['is_primary']) ? 'principale' : '',
                implode(', ', array_filter(array_map(static fn ($area): string => $areas[(string) $area] ?? '', (array) ($point['departments'] ?? [])))),
            ]);
            $lines[] = OrderSheet::esc($value).($details !== [] ? ' <span class="text-muted">· '.OrderSheet::esc(implode(' · ', $details)).'</span>' : '');
        }

        return [DataItem::make('Contatti', implode('<br>', $lines))->html(true)->placeholder('Nessun recapito')->columnSpan(['default' => 12, 'sm' => 8])];
    }

    /**
     * I social di una persona (`Socials::of()` di customer-account), uno per
     * riga: il link, che si apre in un'altra scheda, e la rete. Solo URL http o
     * https: il resto si salta. Tutto escapato.
     *
     * @param array<string, string> $socials rete => URL
     * @param array<string, string> $networks rete => testo (`Socials::networks()`)
     * @return list<DataItem>
     */
    public static function socials(array $socials, array $networks, string $title, string $empty): array
    {
        $lines = [];

        foreach ($socials as $network => $url) {
            $url = trim((string) $url);

            if (preg_match('#^https?://#i', $url) !== 1) {
                continue;
            }

            $testo = rtrim((string) preg_replace('#^https?://(www\.)?#i', '', $url), '/');
            $rete = (string) ($networks[(string) $network] ?? $network);
            $lines[] = Link::to($url, $testo)->target('_blank')->rel('noopener')->render('bootstrap')
                .' <span class="text-muted">· '.OrderSheet::esc($rete).'</span>';
        }

        return [DataItem::make($title, implode('<br>', $lines))->html(true)->placeholder($empty)->columnSpan(['default' => 12, 'sm' => 8])];
    }

    /**
     * Metodo e condizione di pagamento predefiniti (G9), per «Tutti i suoi
     * dati». La condizione è `null` quando non si sceglie (una sola attiva).
     *
     * @return list<DataItem>
     */
    public static function payment(string $method, ?string $term): array
    {
        $dato = static fn (string $etichetta, string $valore): DataItem => DataItem::make($etichetta, $valore)
            ->placeholder('Non indicato')
            ->columnSpan(['default' => 12, 'sm' => 4]);
        $items = [$dato('Metodo di pagamento', $method)];

        if ($term !== null) {
            $items[] = $dato('Condizione di pagamento', $term);
        }

        return $items;
    }

    /** @return list<DataItem> */
    public static function priceList(string $name): array
    {
        return [DataItem::make('Listino', $name !== '' ? $name : 'Nessuno (prezzi di catalogo)')->columnSpan(['default' => 12, 'sm' => 4])];
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
            ->placeholder('Non indicato')
            ->columnSpan(['default' => 12, 'sm' => $colonne]);

        $items = [$dato('Codice fiscale', $get('cf'))];

        if (self::isCompany($contact)) {
            $items[] = $dato('Partita IVA', $get('pi'));
            $items[] = $dato('SDI', $get('sdi'));
            $items[] = $dato('PEC', $get('pec'));
        }

        $items[] = $dato('Indirizzo', OrderSheet::address(self::prefixed($contact), 'c'), true, 12);

        return $items;
    }

    /** Una riga di `companies`: ha la ragione sociale (o la partita IVA); la persona no. */
    private static function isCompany(array $row): bool
    {
        return trim((string) ($row['pi'] ?? '')) !== '' || Contacts::isCompany($row);
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
            .($predefinito ? ' '.Badge::make('Predefinito')->variant('light')->inline()->render('bootstrap') : '').'</div>'
            .OrderSheet::address(self::prefixed($address), 'c')
            .(trim((string) ($address['phone'] ?? '')) !== ''
                ? '<div class="small text-muted mt-1">'.OrderSheet::esc(trim((string) ($address['phone_prefix'] ?? '').' '.$address['phone'])).'</div>'
                : '');
    }

    /**
     * L'elenco dei legami della scheda: i referenti di un'azienda o le aziende
     * di una persona, ognuno col link alla sua scheda (se c'è) e il dettaglio.
     *
     * @param list<array{name: string, url: string, detail: string}> $items
     */
    public static function relations(array $items, string $empty): string
    {
        if ($items === []) {
            return '<p class="text-muted mb-0">'.htmlspecialchars($empty, ENT_QUOTES).'</p>';
        }

        $html = '';

        foreach ($items as $item) {
            $name = htmlspecialchars($item['name'], ENT_QUOTES);
            $html .= '<li class="list-group-item px-0">'
                .($item['url'] !== '' ? '<a href="'.htmlspecialchars($item['url'], ENT_QUOTES).'">'.$name.'</a>' : $name)
                .($item['detail'] !== '' ? '<div class="small text-body-secondary">'.htmlspecialchars($item['detail'], ENT_QUOTES).'</div>' : '')
                .'</li>';
        }

        return '<ul class="list-group list-group-flush">'.$html.'</ul>';
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
    public static function coupons(int $customerId, string $column = 'customer_id'): array
    {
        $now = date('Y-m-d H:i:s');

        return array_map(static function (array $reserved) use ($now): array {
            $coupon = $reserved['coupon'];
            $limit = (int) ($coupon['usage_limit_per_customer'] ?? 0);

            return [
                'code' => (string) $coupon['code'],
                'name' => (string) ($coupon['name'] ?? ''),
                'discount' => CouponResource::discountLabel($coupon),
                'period' => CouponResource::periodLabel($coupon),
                'used' => $limit > 0 ? $reserved['used'].' / '.$limit : (string) $reserved['used'],
                'state' => CouponResource::statusLabel($coupon, $now),
            ];
        }, Coupons::reserved($customerId, $column));
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

    /**
     * Gli abbonamenti del cliente, dal più recente: codice (alla scheda),
     * piano, stato, prossimo rinnovo, importo e canale; o la frase se non ne ha.
     *
     * @param list<array<string, mixed>> $subscriptions
     */
    public static function subscriptionsTable(array $subscriptions): string
    {
        if ($subscriptions === []) {
            return '<p class="text-muted mb-0">Nessun abbonamento per questo cliente.</p>';
        }

        usort($subscriptions, static fn (array $a, array $b): int => (int) $b['id'] <=> (int) $a['id']);
        $body = '';

        foreach ($subscriptions as $row) {
            $code = OrderSheet::esc((string) ($row['code'] ?? ''));
            $body .= '<tr><td class="fw-semibold"><a href="'.OrderSheet::esc(SubscriptionResource::detailUrl((int) $row['id'])).'">'.$code.'</a></td>'
                .'<td>'.OrderSheet::esc(SubscriptionResource::planLabel(SubscriptionItems::priceIdOf($row))).'</td>'
                .'<td>'.SubscriptionResource::statusBadge((string) ($row['status'] ?? '')).'</td>'
                .'<td>'.OrderSheet::esc(self::day((string) ($row['next_renewal_at'] ?? ''))).'</td>'
                .'<td class="text-end">'.OrderSheet::esc(OrderSheet::money((float) ($row['total'] ?? 0))).'</td>'
                .'<td>'.OrderSheet::esc(SubscriptionResource::channelLabels()[(string) ($row['channel'] ?? '')] ?? '').'</td></tr>';
        }

        return '<div class="table-responsive"><table class="table table-sm mb-0"><thead><tr>'
            .'<th>Codice</th><th>Piano</th><th>Stato</th><th>Prossimo rinnovo</th><th class="text-end">Importo</th><th>Canale</th>'
            .'</tr></thead><tbody>'.$body.'</tbody></table></div>';
    }

    /** Il giorno senza l'ora: `10/09/2026`; vuoto se manca. */
    private static function day(string $value): string
    {
        return substr(OrderSheet::date($value), 0, 10);
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
