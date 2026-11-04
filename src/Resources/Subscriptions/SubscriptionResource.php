<?php

namespace Wonder\Plugin\Gestionale\Resources\Subscriptions;

use DateTimeImmutable;
use Throwable;
use Wonder\App\Permission\Access;
use Wonder\App\ResourceSchema\ApiSchema;
use Wonder\App\ResourceSchema\FormField;
use Wonder\App\ResourceSchema\NavigationSchema;
use Wonder\App\ResourceSchema\PageSchema;
use Wonder\App\ResourceSchema\PermissionSchema;
use Wonder\App\ResourceSchema\TableColumn;
use Wonder\App\ResourceSchema\TableLayoutSchema;
use Wonder\Elements\Components\Accordion;
use Wonder\Elements\Components\Badge;
use Wonder\Elements\Components\Button;
use Wonder\Elements\Components\Card;
use Wonder\Elements\Components\Container;
use Wonder\Elements\Components\DataItem;
use Wonder\Elements\Components\Modal;
use Wonder\Http\Route;
use Wonder\Elements\Components\RichText;
use Wonder\Elements\Components\SectionTitle;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Contacts\Contact;
use Wonder\Plugin\Gestionale\Support\Subscriptions\SubscriptionModels;
use Wonder\Plugin\Gestionale\Models\Subscriptions\PlanPrice;
use Wonder\Plugin\Gestionale\Models\Subscriptions\Subscription;
use Wonder\Plugin\Gestionale\Resources\Contacts\CustomerResource;
use Wonder\Plugin\Gestionale\Resources\GestionaleResource;
use Wonder\Plugin\Gestionale\Resources\Sales\OrderPaymentTableResource;
use Wonder\Plugin\Gestionale\Resources\Sales\OrderResource;
use Wonder\Plugin\Gestionale\Support\Backend\HeaderActions;
use Wonder\Plugin\Gestionale\Support\Catalog\Customizations;
use Wonder\Plugin\Gestionale\Support\Contacts\Contacts;
use Wonder\Plugin\Gestionale\Support\Orders\OrderSheet;
use Wonder\Plugin\Gestionale\Support\Subscriptions\SubscriptionItems;

/**
 * «Abbonamenti» (G10 §1.2): quelli del sito li scrivono le notifiche di Stripe
 * (piano 2), quelli da ufficio *Nuovo abbonamento* (piano 3,
 * `OfficeSubscriptionResource`). Dalla scheda: *Disdici*, *Annulla* e
 * *Rimanda il proforma*; nella card «Servizi» *Aggiungi servizio* e, per
 * riga, *Cambia*, *Togli* e *Correggi personalizzazioni*
 * (`SubscriptionActionResource`); *Registra* sulle rate
 * (`SubscriptionPaymentResource`).
 *
 * L'elenco ha cliente, piano, stato, prossimo rinnovo, totale e canale, con i
 * filtri per stato e canale. La scheda ha il periodo, i pagamenti
 * (`gst_payments.subscription_id`), i servizi con la loro storia
 * (`SubscriptionItemChange`) e la storia degli stati.
 */
final class SubscriptionResource extends GestionaleResource
{
    public static string $feature = 'subscriptions';
    public static string $model = Subscription::class;
    public static string $orderColumn = 'id';
    public static string $orderDirection = 'DESC';
    public static string $docsPage = 'vendite/vendite-abbonamenti';

    public static function path(): string
    {
        return 'app/gestionale/abbonamenti';
    }

    public static function icon(): string
    {
        return 'bi-arrow-repeat';
    }

    public static function titleLabel(): string
    {
        return 'Abbonamenti';
    }

    public static function textSchema(): array
    {
        return [
            'label' => 'abbonamento',
            'plural_label' => 'abbonamenti',
            'last' => 'ultimi',
            'all' => 'tutti',
            'article' => 'gli',
            'full' => 'attivo',
            'empty' => 'chiuso',
            'this' => 'questo',
        ];
    }

    public static function labelSchema(): array
    {
        return [
            'code' => 'Codice',
            'customer_id' => 'Cliente',
            'plan' => 'Piano',
            'status' => 'Stato',
            'next_renewal_at' => 'Prossimo rinnovo',
            'total' => 'Totale',
            'channel' => 'Canale',
        ];
    }

    /** @return array<string, string> */
    public static function statusLabels(): array
    {
        return [
            'pending' => 'In attesa',
            'trialing' => 'In prova',
            'active' => 'Attivo',
            'past_due' => 'Da pagare',
            'unpaid' => 'Non pagato',
            'cancelled' => 'Chiuso',
        ];
    }

    /** @return array<string, string> */
    public static function channelLabels(): array
    {
        return ['online' => 'Sito', 'office' => 'Ufficio'];
    }

    public static function statusBadge(string $status): string
    {
        $class = [
            'pending' => 'secondary',
            'trialing' => 'info',
            'active' => 'success',
            'past_due' => 'warning',
            'unpaid' => 'danger',
            'cancelled' => 'light',
        ][$status] ?? 'light';

        return Badge::make(static::statusLabels()[$status] ?? $status)->variant($class)->inline()->render('bootstrap');
    }

    public static function tableSchema(): array
    {
        return [
            TableColumn::key('code')->text()->link('view'),
            TableColumn::key('customer_id')
                ->text()
                ->formatter(static fn (array $row): string => static::escape(static::customerName((int) ($row['customer_id'] ?? 0)))),
            // Non è una colonna: il piano è quello della riga principale.
            TableColumn::key('plan')
                ->text()
                ->formatter(static fn (array $row): string => static::escape(static::planLabel(SubscriptionItems::priceIdOf($row)))),
            TableColumn::key('status')
                ->text()
                ->size('little')
                ->formatter(static fn (array $row): string => static::statusBadge((string) ($row['status'] ?? ''))),
            TableColumn::key('next_renewal_at')
                ->text()
                ->size('little')
                ->formatter(static fn (array $row): string => static::escape(static::day($row['next_renewal_at'] ?? ''))),
            TableColumn::key('total')
                ->text()
                ->size('little')
                ->formatter(static fn (array $row): string => static::escape(OrderResource::money((float) ($row['total'] ?? 0)))),
            TableColumn::key('channel')
                ->text()
                ->size('little')
                ->formatter(static fn (array $row): string => static::escape(static::channelLabels()[(string) ($row['channel'] ?? '')] ?? '')),
            TableColumn::key('actions')->button()->actions(['view']),
        ];
    }

    public static function tableLayoutSchema(): TableLayoutSchema
    {
        return TableLayoutSchema::for(static::class)
            ->title('Abbonamenti')
            ->results()
            ->hideButtonAdd()
            ->buttonCustom(
                Button::to(OfficeSubscriptionResource::submitUrl(), 'Nuovo abbonamento')
                    ->variant('dark')->size('sm')->icon('bi bi-plus-lg', 'start')
            )
            ->filterSearch()
            ->searchFields(['code', 'billing_name', 'billing_surname', 'billing_business_name', 'coupon_code'])
            ->filterCustom('Stato', 'status', ['' => 'Tutti'] + static::statusLabels())
            ->filterCustom('Canale', 'channel', ['' => 'Tutti'] + static::channelLabels());
    }

    public static function pageSchema(): PageSchema
    {
        return parent::pageSchema()
            ->only(['list'])
            ->enable(['view'])
            ->titles(['list' => 'Abbonamenti', 'view' => 'Abbonamento'])
            ->subtitles(['list' => 'Gli abbonamenti dei clienti, dal sito e dall\'ufficio. Quelli del sito li crea il cliente; quelli da ufficio, «Nuovo abbonamento».'])
            ->view('show', Gestionale::viewPath('pages/subscription-show.php'))
            ->actions('view', static fn (array $item): array => static::pageActions($item));
    }

    public static function permissionSchema(): PermissionSchema
    {
        return PermissionSchema::for(static::class)->access(Gestionale::area('vendite'), ['list', 'view']);
    }

    public static function apiSchema(): ApiSchema
    {
        return ApiSchema::for(static::class)->enabled(false);
    }

    public static function navigationSchema(): NavigationSchema
    {
        return NavigationSchema::for(static::class)
            ->section('vendite', 'Vendite', 'bi-receipt', 300)
            ->title('Abbonamenti')
            ->order(55)
            ->access(Gestionale::area('vendite'))
            ->enabled(static::featureActive());
    }

    /** Il titolo della scheda, come l'ordine: «Abbonamento sub_…». @param array<string, mixed> $row */
    public static function pageTitle(array $row): string
    {
        return trim('Abbonamento '.trim((string) ($row['code'] ?? '')));
    }

    /** Sotto il titolo: quando è nato e da quale canale. @param array<string, mixed> $row */
    public static function pageSubtitle(array $row): string
    {
        $giorno = static::day($row['started_at'] ?? '') ?: static::day($row['creation'] ?? '');
        $canale = static::channelLabels()[(string) ($row['channel'] ?? '')] ?? '';
        $parti = array_filter([$giorno !== '' ? 'Creato il '.$giorno : '', $canale]);

        return implode(' · ', $parti);
    }

    /**
     * La testata, come quella dell'ordine: «Documenti» col proforma, poi le
     * azioni principali, e «Annulla» nel menu «Altro».
     *
     * @param array<string, mixed> $row
     * @return list<array<string, mixed>>
     */
    public static function pageActions(array $row): array
    {
        $id = (int) ($row['id'] ?? 0);
        $azioni = static::availableActions($row);
        $documenti = (string) ($row['channel'] ?? '') === 'office' && static::day($row['current_period_start'] ?? '') !== ''
            ? [['label' => 'Proforma', 'icon' => 'bi-file-earmark-text', 'class' => 'btn-outline-secondary btn-sm', 'href' => static::proformaUrl($id), 'target' => '_blank']]
            : [];
        $bottone = static fn (string $label, string $icon, string $modal, string $class = 'btn-outline-dark'): array => [
            'label' => $label,
            'icon' => $icon,
            'class' => $class.' btn-sm',
            'href' => '#',
            'onclick' => OrderResource::openModal($modal),
        ];
        $principali = static::itemsEditable($row) ? [$bottone('Aggiungi servizio', 'bi-plus-lg', 'wi-servizio-add')] : [];
        $altro = [];

        foreach ($azioni as $azione => $label) {
            match ($azione) {
                'cancel' => $principali[] = $bottone($label, 'bi-calendar-x', 'wi-abbonamento-cancel'),
                'proforma' => $principali[] = $bottone($label, 'bi-envelope', 'wi-abbonamento-proforma'),
                default => $altro[] = $bottone($label, 'bi-x-octagon', 'wi-abbonamento-'.$azione, 'btn-outline-danger'),
            };
        }

        return [
            ...HeaderActions::group($documenti, 'Documenti', 'bi-file-earmark-pdf'),
            ...$principali,
            ...HeaderActions::group($altro, 'Altro', 'bi-three-dots-vertical', true),
        ];
    }

    /**
     * La scheda, disegnata come quella dell'ordine: a sinistra la testata con
     * stati, cliente e fatturazione; a destra i totali del periodo e il
     * periodo; sotto, ripiegabili, servizi, pagamenti, storia dei servizi e
     * storico degli stati.
     *
     * @param array<string, mixed> $row
     */
    public static function showLayoutSchema(array $row): Container
    {
        $id = (int) ($row['id'] ?? 0);
        $riquadro = static fn (string $titolo, array $componenti, ?string $aiuto = null): Card => (new Card)->components([
            $aiuto === null ? SectionTitle::make($titolo)->columnSpan(12) : SectionTitle::make($titolo)->tooltip($aiuto)->columnSpan(12),
            ...$componenti,
        ])->columns(12)->columnSpan(12);
        $tabella = static fn (string $titolo, string $html, bool $aperto = false): Accordion => $aperto
            ? Accordion::make($titolo)->expanded()->components([RichText::make($html)->tag('div')->columnSpan(12)])->columnSpan(12)
            : Accordion::make($titolo)->components([RichText::make($html)->tag('div')->columnSpan(12)])->columnSpan(12);
        $dato = static fn (string $etichetta, string $valore): DataItem => DataItem::make($etichetta, $valore !== '' ? $valore : '—')->columnSpan(12);

        return (new Container)->components([
            (new Container)->columnSpan(['default' => 12, 'lg' => 8])->columns(12)->components([
                (new Card)->components(static::headerItems($row))->columns(12)->columnSpan(12),
            ]),
            (new Container)->columnSpan(['default' => 12, 'lg' => 4])->columns(12)->components([
                $riquadro('Totali del periodo', [RichText::make(static::totals($row))->tag('div')->columnSpan(12)]),
                $riquadro('Periodo', [
                    $dato('Iniziato', static::day($row['started_at'] ?? '')),
                    $dato('Periodo in corso', static::range($row['current_period_start'] ?? '', $row['current_period_end'] ?? '')),
                    $dato('Prossimo rinnovo', static::day($row['next_renewal_at'] ?? '')),
                    $dato('Fine prova', static::day($row['trial_ends_at'] ?? '')),
                    $dato('Fine vincolo', static::day($row['commitment_ends_at'] ?? '')),
                    $dato('Disdetta chiesta', static::day($row['cancel_requested_at'] ?? '')),
                    $dato('Chiuso', static::day($row['cancelled_at'] ?? '')),
                    $dato('Finito', static::day($row['ended_at'] ?? '')),
                ], 'Il periodo in corso e le date che contano: la fine della prova, del vincolo, il prossimo rinnovo. Con una disdetta durante il vincolo, «Disdetta chiesta» dice quando: vale a fine vincolo.'),
            ]),
            $tabella('Servizi', SubscriptionItemTableResource::embed($id), true),
            $tabella('Pagamenti e rinnovi', SubscriptionPaymentTableResource::embed($id)),
            $tabella('Storia dei servizi', SubscriptionItemChangeTableResource::embed($id)),
            $tabella('Storico', SubscriptionHistoryTableResource::embed($id)),
        ])->columns(12);
    }

    /**
     * I dati della testata, come quelli dell'ordine: prima gli stati, poi il
     * cliente, la fatturazione, il pagamento e la nota. L'abbonamento non ha
     * email e telefono suoi: vengono dalla scheda del cliente.
     *
     * @param array<string, mixed> $row
     * @return list<DataItem>
     */
    protected static function headerItems(array $row): array
    {
        $dato = static fn (string $etichetta, string $valore, bool $html = false): DataItem => DataItem::make($etichetta, $valore !== '' ? $valore : '—')
            ->html($html)
            ->columnSpan(['default' => 12, 'sm' => 4]);
        $customerId = (int) ($row['customer_id'] ?? 0);
        $cliente = $customerId > 0 ? (static::rowsOf(Contact::class, ['id' => $customerId])[0] ?? []) : [];
        $link = $customerId > 0
            ? '<a href="'.static::escape(CustomerResource::viewUrl($customerId)).'">'.static::escape(static::customerName($customerId)).'</a>'
            : '';
        $metodo = (int) ($row['payment_method_id'] ?? 0);
        $nota = trim((string) ($row['note'] ?? ''));

        return [
            $dato('Abbonamento', static::statusBadge((string) ($row['status'] ?? '')), true),
            $dato('Pagamento', (string) ($row['provider'] ?? '') === 'stripe' ? 'Stripe' : 'Registrato a mano'),
            $dato('Rinnovo automatico', (string) ($row['auto_renew'] ?? 'true') === 'true' ? 'Sì' : 'No'),
            $dato('Cliente', $link, true),
            $dato('Email', (string) ($cliente['email'] ?? '')),
            $dato('Telefono', (string) ($cliente['phone'] ?? '')),
            $dato('Fatturazione', OrderSheet::address($row, 'billing'), true),
            $dato('Piano', static::planLabel(SubscriptionItems::priceIdOf($row))),
            $dato('Metodo di pagamento', $metodo > 0 ? OrderPaymentTableResource::methodName($metodo) : ''),
            $dato('Nota', $nota !== '' ? nl2br(static::escape($nota)) : '', true),
        ];
    }

    /**
     * I totali del periodo nella tabella dei totali dell'ordine: subtotale,
     * sconto se c'è, il coupon come nota, IVA e totale.
     *
     * @param array<string, mixed> $row
     */
    public static function totals(array $row): string
    {
        $voci = [['Servizi', OrderSheet::money($row['subtotal'] ?? 0), '']];

        if (abs((float) ($row['discount_total'] ?? 0)) > 0.004) {
            $voci[] = ['Sconto', OrderSheet::money($row['discount_total']), ''];
        }

        $coupon = trim((string) ($row['coupon_code'] ?? ''));

        if ($coupon !== '') {
            $voci[] = ['Coupon '.$coupon, '', 'text-muted'];
        }

        // I prezzi sono IVA compresa: l'imponibile è il totale meno l'IVA, come nell'ordine.
        $voci[] = ['Imponibile', OrderSheet::money((float) ($row['total'] ?? 0) - (float) ($row['tax_total'] ?? 0)), ''];
        $voci[] = ['IVA '.OrderSheet::number($row['tax_rate'] ?? 0).'%', OrderSheet::money($row['tax_total'] ?? 0), ''];
        $voci[] = ['Totale', OrderSheet::money($row['total'] ?? 0), 'fw-bold'];
        $righe = '';

        foreach ($voci as [$nome, $valore, $classe]) {
            $righe .= '<tr'.($classe !== '' ? ' class="'.$classe.'"' : '').'><td>'.OrderSheet::esc($nome).'</td><td class="text-end">'.OrderSheet::esc($valore).'</td></tr>';
        }

        return '<table class="table table-sm mb-0" style="font-variant-numeric: tabular-nums"><tbody>'.$righe.'</tbody></table>';
    }

    /**
     * Le azioni che valgono per quell'abbonamento, azione => etichetta.
     *
     * @param array<string, mixed> $row
     * @return array<string, string>
     */
    public static function availableActions(array $row): array
    {
        $status = (string) ($row['status'] ?? '');
        $office = (string) ($row['channel'] ?? '') === 'office';
        $live = in_array($status, Subscription::LIVE, true) || ($office && $status === 'unpaid');
        $online = !$office && trim((string) ($row['provider_reference'] ?? '')) !== '';
        $actions = [];

        if ($live && ($office || $online) && (string) ($row['auto_renew'] ?? 'true') === 'true') {
            $actions['cancel'] = 'Disdici';
        }

        if ($office && $status !== 'cancelled') {
            if (static::day($row['current_period_start'] ?? '') !== '') {
                $actions['proforma'] = 'Rimanda il proforma';
            }

            $actions['terminate'] = 'Annulla';
        }

        return $actions;
    }

    /** Le finestre di conferma delle azioni, da stampare sotto la scheda. @param array<string, mixed> $row */
    public static function modals(array $row): string
    {
        $id = (int) ($row['id'] ?? 0);
        $code = (string) ($row['code'] ?? '');
        $html = '';

        foreach (static::availableActions($row) as $action => $label) {
            $components = match ($action) {
                'cancel' => [RichText::make(static::escape('L\'abbonamento non si rinnova più: resta attivo fino alla fine del periodo pagato (o alla fine del vincolo, se dura ancora). Il cliente riceve un\'email.'))->class('mb-0')->columnSpan(12)],
                'terminate' => [
                    RichText::make(static::escape('L\'abbonamento si chiude adesso e le rate in attesa si annullano. I pagamenti già arrivati restano: un rimborso si registra a parte.'))->class('mb-0')->columnSpan(12),
                    FormField::key('reason')->text()->label('Motivo (facoltativo)')->columnSpan(12),
                ],
                default => [RichText::make(static::escape('Rimandi al cliente il proforma del periodo in corso, con le rate ancora da pagare.'))->class('mb-0')->columnSpan(12)],
            };

            $html .= Modal::make($label.': '.$code)
                ->id('wi-abbonamento-'.$action)
                ->form(SubscriptionActionResource::submitUrl(), hidden: ['subscription_id' => $id, 'action' => $action])
                ->columns(12)
                ->components($components)
                ->cancel('Indietro')
                ->submit($label, $action === 'terminate' ? 'danger' : 'primary')
                ->render('bootstrap');
        }

        return $html.static::itemModals($row);
    }

    /** Si cambiano i servizi da qui: abbonamento in corso, da ufficio o già su Stripe. @param array<string, mixed> $row */
    public static function itemsEditable(array $row): bool
    {
        $office = (string) ($row['channel'] ?? '') === 'office';

        return ($office || trim((string) ($row['provider_reference'] ?? '')) !== '')
            && in_array((string) ($row['status'] ?? ''), Subscription::LIVE, true);
    }

    /**
     * Le finestre dei servizi: *Aggiungi servizio* e, per ogni riga attiva,
     * *Cambia*, *Togli* e *Correggi personalizzazioni*.
     *
     * @param array<string, mixed> $row
     */
    public static function itemModals(array $row): string
    {
        if (!static::itemsEditable($row)) {
            return '';
        }

        $id = (int) ($row['id'] ?? 0);
        $office = (string) ($row['channel'] ?? '') === 'office';
        $items = SubscriptionItems::forSubscription($id);
        $labels = static::itemLabels($items);
        $live = array_filter($items, static fn (array $item): bool => in_array((string) $item['status'], ['pending', 'active'], true));
        $today = new DateTimeImmutable('today');
        $modal = static fn (string $modalId, string $title, string $action, array $hidden, array $components, string $submit, string $variant = 'primary', string $help = ''): string => Modal::make($title)
            ->id($modalId)
            ->help($help)
            ->form(SubscriptionActionResource::submitUrl(), hidden: ['subscription_id' => $id, 'action' => $action] + $hidden)
            ->columns(12)
            ->components($components)
            ->cancel('Indietro')
            ->submit($submit, $variant)
            ->render('bootstrap');

        $html = $modal('wi-servizio-add', 'Aggiungi servizio: '.(string) ($row['code'] ?? ''), 'add_item', [], [
            FormField::key('plan_price_id')->select(OfficeSubscriptionResource::priceOptions())->label('Servizio')->required()->columnSpan(8),
            FormField::key('quantity')->number()->decimal(0)->value('1')->label('Quantità')->columnSpan(4),
            FormField::key('parent_item_id')
                ->select(['' => 'Nessuno'] + array_intersect_key($labels, array_flip(array_map(static fn (array $item): int => (int) $item['id'], $live))))
                ->label('Si appoggia a')
                ->columnSpan(12),
            FormField::key('billing')
                ->select(['prorate' => 'Quota ora fino al rinnovo del servizio', 'free' => 'Gratis fino al rinnovo', 'next_period' => 'Dal prossimo periodo'])
                ->value('prorate')
                ->label('Conguaglio')
                ->columnSpan(12),
            ...static::customizationFields(null),
        ], 'Aggiungi', help: '«Si appoggia a»: per un servizio che si lega a un altro (l\'hosting al suo dominio), la riga a cui si appoggia. '
            .($office
                ? 'Conguaglio, quota ora: una rata per i giorni fino al rinnovo del servizio, col suo proforma.'
                : 'Conguaglio, quota ora: Stripe addebita subito i giorni fino al rinnovo sulla carta del cliente.').' Gratis: niente fino al rinnovo. Dal prossimo periodo: il servizio parte col prossimo periodo dell\'abbonamento.');

        foreach ($items as $item) {
            if ((string) $item['status'] !== 'active') {
                continue;
            }

            $itemId = (int) $item['id'];
            $name = $labels[$itemId] ?? '';
            $hidden = ['item_id' => $itemId];
            $children = array_values(array_filter($live, static fn (array $child): bool => (int) ($child['parent_item_id'] ?? 0) === $itemId));
            $commitment = static::day($item['commitment_ends_at'] ?? '');
            $committed = $commitment !== '' && (string) $item['commitment_ends_at'] > $today->format('Y-m-d H:i:s');
            $values = Customizations::valuesOf(Customizations::decode($item['customization'] ?? ''));

            $html .= $modal('wi-servizio-change-'.$itemId, 'Cambia: '.$name, 'change_item', $hidden, [
                FormField::key('plan_price_id')
                    ->select(array_diff_key(OfficeSubscriptionResource::priceOptions(), [(int) $item['plan_price_id'] => true]))
                    ->label('Nuovo prezzo')
                    ->required()
                    ->columnSpan(12),
                FormField::key('billing')
                    ->select(['prorate' => 'Conguaglio ora', 'free' => 'Subito, senza conguaglio', 'next_period' => 'Dal prossimo rinnovo del servizio'])
                    ->value('prorate')
                    ->label('Quando')
                    ->columnSpan(12),
                ...static::customizationFields(null),
                RichText::make(static::escape('Le personalizzazioni lasciate vuote restano quelle di prima, se il nuovo piano le ha.'))->class('mb-0 small text-muted')->columnSpan(12),
            ], 'Cambia', help: ($office
                ? 'Conguaglio ora: un credito per i giorni non goduti del prezzo vecchio e una rata per la quota del nuovo.'
                : 'Conguaglio ora: Stripe fa i conti dei giorni e addebita subito la differenza.').' Subito: il nuovo prezzo vale da oggi, senza conti. Dal prossimo rinnovo: cambia a fine periodo del servizio.');

            $html .= $modal('wi-servizio-remove-'.$itemId, 'Togli: '.$name, 'remove_item', $hidden, [
                FormField::key('billing')
                    ->select($committed
                        ? ['period_end' => 'A fine vincolo ('.$commitment.')']
                        : ['period_end' => 'A fine periodo', 'credit' => 'Subito, con un credito per i giorni non goduti'])
                    ->value('period_end')
                    ->label('Quando')
                    ->columnSpan(12),
                ...($committed ? [RichText::make(static::escape('Il servizio è in vincolo fino al '.$commitment.': si toglie a fine vincolo.'))->class('mb-0')->columnSpan(12)] : []),
                ...($children !== [] ? [RichText::make(static::escape('Si tolgono insieme anche: '.implode(', ', array_map(static fn (array $child): string => $labels[(int) $child['id']] ?? '', $children)).'.'))->class('mb-0')->columnSpan(12)] : []),
            ], 'Togli', 'danger');

            if (static::planCustomizations((int) $item['plan_price_id']) !== []) {
                $html .= $modal('wi-servizio-customize-'.$itemId, 'Correggi: '.$name, 'customize_item', $hidden, [
                    ...static::customizationFields((int) $item['plan_price_id'], $values),
                    RichText::make(static::escape('Un sovrapprezzo diverso vale dal prossimo periodo.'))->class('mb-0 small text-muted')->columnSpan(12),
                ], 'Salva');
            }
        }

        return $html;
    }

    /**
     * I campi delle personalizzazioni (`customization_<id>`): quelle del piano
     * del prezzo con i valori di adesso, oppure, senza prezzo, quelle di tutti
     * i piani attivi coi nomi dei piani che le chiedono.
     *
     * @param array<int, string> $values
     * @return list<FormField>
     */
    protected static function customizationFields(?int $planPriceId, array $values = []): array
    {
        $definitions = $planPriceId === null ? OfficeSubscriptionResource::customizationColumns() : static::planCustomizations($planPriceId);
        $fields = [];

        foreach ($definitions as $definition) {
            $field = FormField::key('customization_'.(int) $definition['id']);
            $field = $definition['kind'] === 'choice'
                ? $field->select(['' => 'Nessuna'] + array_column($definition['options'], 'label', 'id'))
                : ($definition['kind'] === 'number' ? $field->number()->decimal((int) $definition['decimals']) : $field->text());
            $value = (string) ($values[(int) $definition['id']] ?? '');

            $fields[] = ($value !== '' ? $field->value($value) : $field)
                ->label((string) $definition['label'].(isset($definition['plans']) ? ' ('.implode(', ', $definition['plans']).')' : ''))
                ->columnSpan(6);
        }

        return $fields;
    }

    /** Le personalizzazioni del piano di un prezzo. @return list<array<string, mixed>> */
    public static function planCustomizations(int $planPriceId): array
    {
        $price = $planPriceId > 0 ? PlanPrice::findById($planPriceId) : null;

        return is_array($price) && (int) ($price['product_model_id'] ?? 0) > 0 ? Customizations::forPlan((int) $price['product_model_id']) : [];
    }

    /** Id della riga => «Piano — prezzo · personalizzazioni». @param list<array<string, mixed>> $items @return array<int, string> */
    public static function itemLabels(array $items): array
    {
        $labels = [];

        foreach ($items as $item) {
            $labels[(int) $item['id']] = SubscriptionItems::label($item);
        }

        return $labels;
    }

    public static function termOfPrice(int $planPriceId): int
    {
        $price = $planPriceId > 0 ? PlanPrice::findById($planPriceId) : null;

        return max(1, (int) (is_array($price) ? ($price['term_months'] ?? 1) : 1));
    }

    /** Il proforma del periodo in corso, dall'archivio non pubblico. */
    public static function proformaUrl(int $id): string
    {
        $fallback = '/backend/'.static::path().'/'.$id.'/proforma/';

        if (!function_exists('__r')) {
            return $fallback;
        }

        try {
            $named = (string) __r('backend.resource.'.static::slug().'.proforma', ['id' => $id]);
        } catch (Throwable) {
            return $fallback;
        }

        return $named !== '' ? $named : $fallback;
    }

    public static function registerBackendRoutes(string $rootApp, string $slug): void
    {
        Route::get('/{id}/proforma/', Gestionale::handlerPath('backend/sales/proforma-pdf.php'), [
            'resource' => $slug,
            'resource_action' => 'proforma',
        ])->name('proforma')
            ->permit(Access::roles(Gestionale::area('vendite'), 'view'))
            ->where('id', '[0-9]+');
    }

    /** L'indirizzo della scheda con un segnaposto al posto dell'id, per i menu di riga. */
    public static function detailUrlPattern(string $placeholder): string
    {
        return str_replace('987654321', $placeholder, static::detailUrl(987654321));
    }

    /** L'indirizzo della scheda; il percorso è il ripiego. */
    public static function detailUrl(int $id): string
    {
        $fallback = '/backend/'.static::path().'/'.$id.'/';

        if (!function_exists('__r')) {
            return $fallback;
        }

        try {
            $named = (string) __r('backend.resource.'.static::slug().'.view', ['id' => $id]);
        } catch (Throwable) {
            return $fallback;
        }

        return $named !== '' ? $named : $fallback;
    }

    /** «Pro — Mensile»; vuoto se il prezzo non c'è più. */
    public static function planLabel(int $planPriceId): string
    {
        $price = $planPriceId > 0 ? (static::rowsOf(PlanPrice::class, ['id' => $planPriceId])[0] ?? null) : null;

        if ($price === null) {
            return $planPriceId > 0 ? '#'.$planPriceId : '';
        }

        $plan = SubscriptionModels::find((int) $price['product_model_id']);

        return trim(($plan !== null ? (string) $plan['name'].' — ' : '').(string) $price['name']);
    }

    public static function sourceLabel(string $source): string
    {
        return [
            'user' => 'Backend',
            'api' => 'Sito',
            'webhook' => 'Stripe',
            'cron' => 'Automatico',
            'system' => 'Sistema',
        ][$source] ?? $source;
    }

    protected static function customerName(int $customerId): string
    {
        if ($customerId <= 0) {
            return '';
        }

        $row = static::rowsOf(Contact::class, ['id' => $customerId])[0] ?? null;

        return $row !== null ? Contacts::displayName($row) : '#'.$customerId;
    }

    /** `d/m/Y`; vuoto se manca o è la data zero di MySQL. */
    public static function day(mixed $value): string
    {
        $value = trim((string) $value);

        if ($value === '' || str_starts_with($value, '0000') || strtotime($value) === false) {
            return '';
        }

        return date('d/m/Y', strtotime($value));
    }

    protected static function range(mixed $from, mixed $to): string
    {
        $from = static::day($from);
        $to = static::day($to);

        return $from !== '' && $to !== '' ? $from.' – '.$to : $from.$to;
    }
}
