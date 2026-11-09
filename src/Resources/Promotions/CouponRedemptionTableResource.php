<?php

namespace Wonder\Plugin\Gestionale\Resources\Promotions;

use Throwable;
use Wonder\App\ResourceSchema\ApiSchema;
use Wonder\App\ResourceSchema\NavigationSchema;
use Wonder\App\ResourceSchema\PageSchema;
use Wonder\App\ResourceSchema\PermissionSchema;
use Wonder\App\ResourceSchema\TableColumn;
use Wonder\App\ResourceSchema\TableLayoutSchema;
use Wonder\Elements\Components\Badge;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Promotions\CouponRedemption;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Resources\GestionaleResource;
use Wonder\Plugin\Gestionale\Support\Contacts\CustomerKind;

/**
 * Gli utilizzi di un coupon nella sua scheda (gestionale#113): ordine, cliente,
 * codice, sconto, data e stato. Come `CouponCodeTableResource` esiste solo per
 * la tabella del core incorporata; un utilizzo rilasciato (ordine annullato o
 * pagamento non riuscito) resta in elenco ma non conta più.
 */
final class CouponRedemptionTableResource extends GestionaleResource
{
    public static string $feature = 'coupons';
    public static string $model = CouponRedemption::class;
    public static string $orderColumn = 'id';
    public static string $orderDirection = 'DESC';

    public static function path(): string
    {
        return 'app/gestionale/coupon-utilizzi';
    }

    public static function icon(): string
    {
        return 'bi-receipt';
    }

    public static function titleLabel(): string
    {
        return 'Utilizzi del coupon';
    }

    public static function textSchema(): array
    {
        return [
            'label' => 'utilizzo',
            'plural_label' => 'utilizzi',
            'last' => 'ultimi',
            'all' => 'tutti',
            'article' => 'gli',
            'this' => 'questo',
        ];
    }

    public static function labelSchema(): array
    {
        return [
            'order_number' => 'Ordine',
            'customer_id' => 'Cliente',
            'code' => 'Codice',
            'discount_amount' => 'Sconto',
            'redeemed_at' => 'Data',
            'redemption_status' => 'Stato',
        ];
    }

    public static function tableSchema(): array
    {
        return [
            TableColumn::key('order_number')
                ->text()
                ->sortable()
                ->formatter(static fn (array $row): string => CouponCodeTableResource::orderLink((int) ($row['order_id'] ?? 0), (string) ($row['order_number'] ?? ''))),
            TableColumn::key('customer_id')
                ->text()
                ->formatter(static fn (array $row): string => static::escapeStored((int) ($row['company_id'] ?? 0) > 0
                    ? CustomerKind::name($row)
                    : CouponResource::customerName((int) ($row['customer_id'] ?? 0), (string) ($row['email'] ?? '')))),
            TableColumn::key('code')
                ->text()
                ->sortable()
                ->formatter(static fn (array $row): string => trim((string) ($row['code'] ?? '')) === '' ? '' : '<code>'.static::escapeStored((string) $row['code']).'</code>'),
            TableColumn::key('discount_amount')
                ->text()
                ->size('little')
                ->sortable()
                ->formatter(static fn (array $row): string => '<span class="text-nowrap" style="font-variant-numeric: tabular-nums">'
                    .static::escape(number_format((float) ($row['discount_amount'] ?? 0), 2, ',', '.')).' €</span>'),
            TableColumn::key('redeemed_at')
                ->text()
                ->size('medium')
                ->sortable()
                ->formatter(static fn (array $row): string => CouponCodeTableResource::day((string) ($row['redeemed_at'] ?? ''))),
            TableColumn::key('redemption_status')
                ->text()
                ->size('little')
                ->formatter(static fn (array $row): string => CouponCodeTableResource::day((string) ($row['released_at'] ?? '')) !== ''
                    ? Badge::make('Rilasciato')->variant('light')->inline()->render('bootstrap')
                    : Badge::make('Valido')->variant('success')->inline()->render('bootstrap')),
        ];
    }

    public static function tableLayoutSchema(): TableLayoutSchema
    {
        $valid = CouponCodeTableResource::notReleased('`'.CouponRedemption::$table.'`');

        return TableLayoutSchema::for(static::class)
            ->cleanHeader()
            ->results()
            ->select('(SELECT o.order_number FROM `'.Order::$table.'` o WHERE o.id = `'.CouponRedemption::$table.'`.order_id) AS order_number')
            ->filterSearch()
            ->filterLimit(true)
            ->searchFields(['code', 'email'])
            ->filterQuery(
                'Stato',
                'stato',
                ['valid' => 'Validi', 'released' => 'Rilasciati'],
                static fn (array $values): string => match ($values[0] ?? '') {
                    'valid' => $valid,
                    'released' => 'NOT '.$valid,
                    default => '',
                }
            );
    }

    public static function pageSchema(): PageSchema
    {
        return parent::pageSchema()->only([]);
    }

    public static function navigationSchema(): NavigationSchema
    {
        return NavigationSchema::for(static::class)
            ->inSection('promozioni')
            ->title(static::titleLabel())
            ->access(Gestionale::area('promozioni'))
            ->enabled(false);
    }

    public static function permissionSchema(): PermissionSchema
    {
        return PermissionSchema::for(static::class)->access(Gestionale::area('promozioni'), ['list']);
    }

    public static function apiSchema(): ApiSchema
    {
        return ApiSchema::for(static::class)->enabled(false);
    }

    /** La tabella degli utilizzi del coupon, dal più recente, o la frase se non ne ha. */
    public static function embed(int $couponId): string
    {
        $vuoto = '<p class="text-muted mb-0">Nessun utilizzo.</p>';

        if ($couponId <= 0 || !static::featureActive()) {
            return $vuoto;
        }

        try {
            if (empty(CouponRedemption::find(['coupon_id' => $couponId], 1))) {
                return $vuoto;
            }

            $tabella = static::backendTable([], static::tableLayoutSchema());
            $tabella->length(25);
            $tabella->query('`coupon_id` = '.$couponId." AND `deleted` = 'false'");
            $tabella->queryOrder(static::$orderColumn, static::$orderDirection);
            $html = (string) $tabella->generate(false);
        } catch (Throwable) {
            return $vuoto;
        }

        return $html !== '' ? $html : $vuoto;
    }
}
