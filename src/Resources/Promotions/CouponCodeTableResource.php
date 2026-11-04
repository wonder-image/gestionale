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
use Wonder\Plugin\Gestionale\Models\Promotions\CouponCode;
use Wonder\Plugin\Gestionale\Models\Promotions\CouponRedemption;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Resources\GestionaleResource;
use Wonder\Plugin\Gestionale\Resources\Sales\OrderResource;

/**
 * I codici generati di un coupon nella sua scheda (gestionale#113).
 *
 * Come `CampaignProductTableResource` non ha pagina, menu né API: esiste perché
 * la scheda incorpori la tabella del core, con ricerca, filtro per stato,
 * ordinamento e pagine. Un codice è usato se ha un utilizzo non rilasciato;
 * l'ordine e la data arrivano da sottoquery, così il core ordina anche su di
 * loro. L'elenco completo resta in «Scarica i codici» (CSV).
 */
final class CouponCodeTableResource extends GestionaleResource
{
    public static string $feature = 'coupons';
    public static string $model = CouponCode::class;
    public static string $orderColumn = 'id';
    public static string $orderDirection = 'ASC';

    public static function path(): string
    {
        return 'app/gestionale/coupon-codici';
    }

    public static function icon(): string
    {
        return 'bi-upc';
    }

    public static function titleLabel(): string
    {
        return 'Codici del coupon';
    }

    public static function textSchema(): array
    {
        return [
            'label' => 'codice',
            'plural_label' => 'codici',
            'last' => 'ultimi',
            'all' => 'tutti',
            'article' => 'i',
            'this' => 'questo',
        ];
    }

    public static function labelSchema(): array
    {
        return [
            'code' => 'Codice',
            'code_status' => 'Stato',
            'order_number' => 'Ordine',
            'used_at' => 'Usato il',
        ];
    }

    public static function tableSchema(): array
    {
        return [
            TableColumn::key('code')
                ->text()
                ->sortable()
                ->formatter(static fn (array $row): string => '<code>'.static::escapeStored((string) ($row['code'] ?? '')).'</code>'),
            TableColumn::key('code_status')
                ->text()
                ->size('little')
                ->formatter(static fn (array $row): string => (int) ($row['order_id'] ?? 0) > 0
                    ? Badge::make('Usato')->variant('secondary')->inline()->render('bootstrap')
                    : Badge::make('Libero')->variant('success')->inline()->render('bootstrap')),
            TableColumn::key('order_number')
                ->text()
                ->sortable()
                ->formatter(static fn (array $row): string => static::orderLink((int) ($row['order_id'] ?? 0), (string) ($row['order_number'] ?? ''))),
            TableColumn::key('used_at')
                ->text()
                ->size('medium')
                ->sortable()
                ->formatter(static fn (array $row): string => static::day((string) ($row['used_at'] ?? ''))),
        ];
    }

    /**
     * L'utilizzo che tiene occupato il codice: il più recente non rilasciato.
     * SQL scritto qui, mai dalla richiesta.
     */
    public static function usedSql(string $column): string
    {
        return '(SELECT r.'.$column.' FROM `'.CouponRedemption::$table.'` r'
            .' WHERE r.coupon_code_id = `'.CouponCode::$table.'`.id AND '.static::notReleased('r')
            .' ORDER BY r.id DESC LIMIT 1)';
    }

    /** L'utilizzo conta ancora: nessuna data di rilascio (NULL o la data zero di MySQL). */
    public static function notReleased(string $alias): string
    {
        return '('.$alias.'.released_at IS NULL OR YEAR('.$alias.'.released_at) = 0)';
    }

    public static function select(): string
    {
        return static::usedSql('order_id').' AS order_id, '
            .'(SELECT o.order_number FROM `'.Order::$table.'` o WHERE o.id = '.static::usedSql('order_id').') AS order_number, '
            .static::usedSql('redeemed_at').' AS used_at';
    }

    public static function tableLayoutSchema(): TableLayoutSchema
    {
        $used = 'EXISTS (SELECT 1 FROM `'.CouponRedemption::$table.'` r WHERE r.coupon_code_id = `'.CouponCode::$table.'`.id AND '.static::notReleased('r').')';

        return TableLayoutSchema::for(static::class)
            ->cleanHeader()
            ->results()
            ->select(static::select())
            ->filterSearch()
            ->filterLimit(true)
            ->searchFields(['code'])
            ->filterQuery(
                'Stato',
                'stato',
                ['free' => 'Liberi', 'used' => 'Usati'],
                static fn (array $values): string => match ($values[0] ?? '') {
                    'free' => 'NOT '.$used,
                    'used' => $used,
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

    /** La tabella dei codici del coupon, o la frase se non ne ha. */
    public static function embed(int $couponId): string
    {
        $vuoto = '<p class="text-muted mb-0">Nessun codice generato.</p>';

        if ($couponId <= 0 || !static::featureActive()) {
            return $vuoto;
        }

        try {
            if (empty(CouponCode::find(['coupon_id' => $couponId], 1))) {
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

    /** Il numero dell'ordine col link alla sua scheda; vuoto senza ordine. */
    public static function orderLink(int $orderId, string $number): string
    {
        if ($orderId <= 0) {
            return '';
        }

        $number = trim($number) !== '' ? $number : '#'.$orderId;

        return '<a href="'.static::escape(OrderResource::detailUrl($orderId)).'">'.static::escapeStored($number).'</a>';
    }

    /** `d/m/Y`, vuoto se manca o è la data zero. */
    public static function day(string $value): string
    {
        $value = trim($value);

        return $value === '' || str_starts_with($value, '0000') || strtotime($value) === false ? '' : date('d/m/Y', strtotime($value));
    }
}
