<?php

namespace Wonder\Plugin\Gestionale\Resources\Subscriptions;

use DateTimeImmutable;
use Wonder\App\ResourceSchema\TableColumn;
use Wonder\Plugin\Gestionale\Models\Subscriptions\Subscription;
use Wonder\Plugin\Gestionale\Models\Subscriptions\SubscriptionItem;
use Wonder\Plugin\Gestionale\Resources\Sales\OrderResource;
use Wonder\Plugin\Gestionale\Resources\Sales\OrderSectionResource;
use Wonder\Plugin\Gestionale\Support\Backend\RowMenu;
use Wonder\Plugin\Gestionale\Support\Catalog\Customizations;
use Wonder\Plugin\Gestionale\Support\Subscriptions\SubscriptionItems;
use Wonder\Plugin\Gestionale\Support\Subscriptions\SubscriptionProration;

/**
 * I servizi dell'abbonamento, come le righe dell'ordine: servizio con le
 * personalizzazioni, stato, quantità, importo del periodo pieno, prossimo
 * rinnovo della riga, fine vincolo e il menu ⋯ delle righe in corso.
 */
final class SubscriptionItemTableResource extends OrderSectionResource
{
    public static string $feature = 'subscriptions';
    public static string $model = SubscriptionItem::class;
    public static string $foreignKey = 'subscription_id';

    public const STATUSES = ['pending' => 'Da avviare', 'active' => 'Attivo', 'ending' => 'In chiusura', 'ended' => 'Finito'];

    /** @var array<int, array{row: array<string, mixed>, editable: bool, labels: array<int, string>}> per abbonamento */
    private static array $context = [];

    public static function path(): string
    {
        return 'app/gestionale/abbonamento-servizi';
    }

    public static function icon(): string
    {
        return 'bi-boxes';
    }

    public static function titleLabel(): string
    {
        return 'Servizi dell\'abbonamento';
    }

    public static function textSchema(): array
    {
        return [
            'label' => 'servizio',
            'plural_label' => 'servizi',
            'last' => 'ultimi',
            'all' => 'tutti',
            'article' => 'i',
            'this' => 'questo',
        ];
    }

    public static function labelSchema(): array
    {
        return [
            'plan_price_id' => 'Servizio',
            'status' => 'Stato',
            'quantity' => 'Q.tà',
            'total' => 'Importo',
            'renewal' => 'Prossimo rinnovo',
            'commitment_ends_at' => 'Fine vincolo',
            'actions' => '',
        ];
    }

    public static function tableSchema(): array
    {
        return [
            TableColumn::key('plan_price_id')
                ->text()
                ->formatter(static fn (array $row): string => static::serviceOf($row)),
            TableColumn::key('status')
                ->text()
                ->size('little')
                ->formatter(static fn (array $row): string => static::escape(self::STATUSES[(string) ($row['status'] ?? '')] ?? (string) ($row['status'] ?? ''))),
            TableColumn::key('quantity')
                ->text()
                ->size('little')
                ->formatter(static fn (array $row): string => (string) (int) ($row['quantity'] ?? 1)),
            TableColumn::key('total')
                ->text()
                ->size('little')
                ->formatter(static fn (array $row): string => static::escape(OrderResource::money((float) ($row['total'] ?? 0)))),
            TableColumn::key('renewal')
                ->text()
                ->size('medium')
                ->formatter(static fn (array $row): string => static::escape(static::renewalOf($row))),
            TableColumn::key('commitment_ends_at')
                ->text()
                ->size('medium')
                ->formatter(static fn (array $row): string => static::escape(SubscriptionResource::day($row['commitment_ends_at'] ?? ''))),
            TableColumn::key('actions')
                ->text()
                ->size('little')
                ->formatter(static fn (array $row): string => static::actionsOf($row)),
        ];
    }

    /** Il servizio, le sue personalizzazioni e la riga a cui si appoggia. */
    public static function serviceOf(array $item): string
    {
        $parent = (int) ($item['parent_item_id'] ?? 0);
        $labels = $parent > 0 ? static::context($item)['labels'] : [];

        return static::escape(SubscriptionResource::planLabel((int) ($item['plan_price_id'] ?? 0)))
            .implode('', array_map(static fn (string $line): string => '<div class="small text-muted">'.static::escape($line).'</div>', Customizations::lines($item)))
            .($parent > 0 ? '<div class="small text-muted">Si appoggia a: '.static::escape($labels[$parent] ?? '#'.$parent).'</div>' : '');
    }

    /** Quando si rinnova la riga: l'inizio se deve partire, la fine del suo periodo se è attiva. */
    public static function renewalOf(array $item): string
    {
        return match ((string) ($item['status'] ?? '')) {
            'pending' => SubscriptionResource::day($item['starts_at'] ?? ''),
            'active' => date('d/m/Y', SubscriptionProration::rowPeriod(
                SubscriptionItems::anchor(static::context($item)['row']),
                SubscriptionResource::termOfPrice((int) ($item['plan_price_id'] ?? 0)),
                new DateTimeImmutable('today')
            )[1]->getTimestamp()),
            'ending' => 'Finisce il '.SubscriptionResource::day($item['ends_at'] ?? ''),
            default => '',
        };
    }

    /** Il menu ⋯ di una riga in corso: le finestre le stampa `SubscriptionResource::itemModals()`. */
    public static function actionsOf(array $item): string
    {
        $id = (int) ($item['id'] ?? 0);

        if ($id <= 0 || (string) ($item['status'] ?? '') !== 'active' || !static::context($item)['editable']) {
            return '';
        }

        return RowMenu::render(array_values(array_filter([
            ['label' => 'Cambia', 'icon' => 'bi bi-arrow-left-right', 'modal' => 'wi-servizio-change-'.$id],
            SubscriptionResource::planCustomizations((int) ($item['plan_price_id'] ?? 0)) !== []
                ? ['label' => 'Correggi personalizzazioni', 'icon' => 'bi bi-pencil', 'modal' => 'wi-servizio-customize-'.$id]
                : null,
            ['label' => 'Togli', 'icon' => 'bi bi-x-lg', 'modal' => 'wi-servizio-remove-'.$id, 'danger' => true],
        ])));
    }

    protected static function emptyText(): string
    {
        return 'Nessun servizio.';
    }

    /**
     * L'abbonamento della riga, letto una volta per tabella: serve per il
     * rinnovo, il menu e le righe a cui ci si appoggia.
     *
     * @return array{row: array<string, mixed>, editable: bool, labels: array<int, string>}
     */
    private static function context(array $item): array
    {
        $subscriptionId = (int) ($item['subscription_id'] ?? 0);

        if (!isset(self::$context[$subscriptionId])) {
            $row = $subscriptionId > 0 ? Subscription::findById($subscriptionId) : null;
            $row = is_array($row) ? $row : [];
            self::$context[$subscriptionId] = [
                'row' => $row,
                'editable' => $row !== [] && SubscriptionResource::itemsEditable($row),
                'labels' => SubscriptionResource::itemLabels(SubscriptionItems::forSubscription($subscriptionId)),
            ];
        }

        return self::$context[$subscriptionId];
    }
}
