<?php

namespace Wonder\Plugin\Gestionale\Support\Promotions;

use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Promotions\DiscountCampaign;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;

/**
 * Le campagne di sconto: l'unica porta da cui carrello, backend e vetrina le
 * leggono (§3 della spec di G6).
 *
 * Lo stato di una campagna non si scrive: si ricava da `active` e dalle date.
 * Una campagna è in corso dall'istante di `starts_at` a quello di `ends_at`,
 * **estremi compresi**; `ends_at` vuoto vuol dire senza fine. Con la
 * funzionalità `discount_campaigns` spenta nessuna campagna si applica, ma i
 * dati restano.
 *
 * `$now` lo passa sempre il chiamante, `Y-m-d H:i:s`: così le prove non
 * dipendono dall'orologio.
 */
final class Campaigns
{
    public const STATUSES = ['inactive', 'scheduled', 'running', 'ended'];
    public const CHANNELS = ['online', 'office', 'pos'];

    /** `inactive`, `scheduled`, `running` o `ended`. */
    public static function status(array $row, string $now): string
    {
        if (($row['active'] ?? 'true') !== 'true') {
            return 'inactive';
        }

        $starts = self::moment($row['starts_at'] ?? '');
        $ends = self::moment($row['ends_at'] ?? '');

        if ($starts !== '' && $now < $starts) {
            return 'scheduled';
        }

        if ($ends !== '' && $now > $ends) {
            return 'ended';
        }

        return 'running';
    }

    /**
     * Le campagne in corso su un canale.
     *
     * @return list<array<string, mixed>>
     */
    public static function running(string $now, string $channel = 'online'): array
    {
        $found = DiscountCampaign::find(['active' => 'true']);
        $rows = is_array($found) ? (array_key_exists('id', $found) ? [$found] : array_filter($found, 'is_array')) : [];
        $flag = 'applies_'.$channel;

        return array_values(array_filter(
            $rows,
            static fn (array $row): bool => ($row[$flag] ?? 'false') === 'true' && self::status($row, $now) === 'running'
        ));
    }

    /**
     * Il prezzo di campagna di un prodotto, o `null` se nessuna si applica.
     *
     * @return array{campaign_id: int, price: float, percent: float}|null
     */
    public static function forProduct(int $productId, string $now, string $channel = 'online'): ?array
    {
        $best = self::best($productId, $now, $channel);

        return $best === null ? null : array_diff_key($best, ['name' => true, 'base_price' => true]);
    }

    /**
     * Quello che la vetrina deve mostrare: prezzo da barrare, prezzo, percentuale
     * e fine della campagna. La vetrina non fa conti.
     *
     * @return array{campaign_id: int, name: string, base_price: float, price: float, percent: float, ends_at: string}|null
     */
    public static function display(int $productId, string $now): ?array
    {
        $best = self::best($productId, $now, 'online');

        return $best === null ? null : [
            'campaign_id' => $best['campaign_id'],
            'name' => $best['name'],
            'base_price' => $best['base_price'],
            'price' => $best['price'],
            'percent' => $best['percent'],
            'ends_at' => self::moment($best['ends_at'] ?? ''),
        ];
    }

    /**
     * Quanti prodotti prende una campagna e quali, con il prezzo prima e dopo.
     *
     * `$draft` ha i campi della campagna (`discount_type`, `discount_value`,
     * `exclude_sale_products`) e `scope`, il selettore. `after` è vuoto per un
     * prodotto che la campagna prende ma su cui non cambia il prezzo (per
     * esempio, già in saldo con `exclude_sale_products`).
     *
     * @return array{count: int, products: list<array{id: int, model_id: int, variant_id: int, name: string, sku: string, before: string, after: string}>}
     */
    public static function preview(array $draft, string $now): array
    {
        $products = [];

        foreach (ProductScope::catalog() as $id => $product) {
            if (!ScopeMatcher::matches((array) ($draft['scope'] ?? []), $product['facts'])) {
                continue;
            }

            $price = CampaignPrice::best([self::candidate($draft, 0)], $product['price'], $product['sale_price']);

            $products[] = [
                'id' => (int) $id,
                'model_id' => $product['facts']['model_id'],
                'variant_id' => $product['variant_id'],
                'name' => $product['name'],
                'sku' => $product['sku'],
                'before' => number_format($product['price'], 2, '.', ''),
                'after' => $price === null ? '' : number_format($price['price'], 2, '.', ''),
            ];
        }

        return ['count' => count($products), 'products' => $products];
    }

    /**
     * I nomi delle campagne che si pestano i piedi con questa: attive, con
     * un periodo che si interseca e almeno un prodotto in comune. Non blocca
     * niente — vince comunque lo sconto maggiore — ma il commerciante lo deve
     * sapere.
     *
     * @return list<string>
     */
    public static function overlaps(array $draft, string $now, int $exceptId = 0): array
    {
        $found = DiscountCampaign::find(['active' => 'true']);
        $rows = is_array($found) ? (array_key_exists('id', $found) ? [$found] : array_filter($found, 'is_array')) : [];
        $catalog = null;
        $mine = null;
        $names = [];

        foreach ($rows as $row) {
            if ((int) $row['id'] === $exceptId || !self::intersects($draft, $row)) {
                continue;
            }

            $catalog ??= ProductScope::catalog();
            $mine ??= self::covered((array) ($draft['scope'] ?? []), $catalog);

            if (array_intersect_key($mine, self::covered(ProductScope::of('campaign', (int) $row['id']), $catalog)) !== []) {
                $names[] = (string) ($row['name'] ?? '');
            }
        }

        return $names;
    }

    /** Rifiuta una campagna che non sta in piedi. */
    public static function validate(array $draft): void
    {
        $starts = self::moment($draft['starts_at'] ?? '');
        $ends = self::moment($draft['ends_at'] ?? '');

        if ($starts !== '' && $ends !== '' && $ends < $starts) {
            throw UserError::make('campaign.ends_before_starts');
        }

        $value = (float) ($draft['discount_value'] ?? 0);

        if (($draft['discount_type'] ?? 'percent') === 'percent') {
            if ($value <= 0.0 || $value > 100.0) {
                throw UserError::make('campaign.percent_out_of_range');
            }
        } elseif ($value <= 0.0) {
            throw UserError::make('campaign.amount_negative');
        }

        $scope = (array) ($draft['scope'] ?? []);

        if (empty($scope['all']) && array_filter([
            ...(array) ($scope['categories'] ?? []),
            ...(array) ($scope['tags'] ?? []),
            ...(array) ($scope['brands'] ?? []),
            ...(array) ($scope['models'] ?? []),
        ]) === []) {
            throw UserError::make('campaign.scope_empty');
        }
    }

    /**
     * La campagna che vince su un prodotto, con nome e prezzo base.
     *
     * @return array{campaign_id: int, price: float, percent: float, name: string, base_price: float, ends_at: mixed}|null
     */
    private static function best(int $productId, string $now, string $channel): ?array
    {
        if (!Gestionale::feature('discount_campaigns')) {
            return null;
        }

        $product = \Wonder\Plugin\Gestionale\Models\Catalog\Product::findById($productId);

        if (!is_array($product)) {
            return null;
        }

        $running = self::running($now, $channel);

        if ($running === []) {
            return null;
        }

        $facts = ProductScope::facts($productId);
        $candidates = [];
        $byId = [];

        foreach ($running as $row) {
            if (ScopeMatcher::matches(ProductScope::of('campaign', (int) $row['id']), $facts)) {
                $candidates[] = self::candidate($row, (int) $row['id']);
                $byId[(int) $row['id']] = $row;
            }
        }

        $base = (float) ($product['price'] ?? 0);
        $best = CampaignPrice::best($candidates, $base, (float) ($product['sale_price'] ?? 0));

        if ($best === null) {
            return null;
        }

        $winner = $byId[$best['campaign_id']];

        return $best + [
            'name' => (string) ($winner['name'] ?? ''),
            'base_price' => round($base, 2),
            'ends_at' => $winner['ends_at'] ?? '',
        ];
    }

    /** @return array{id: int, discount_type: string, discount_value: float, exclude_sale_products: bool} */
    private static function candidate(array $row, int $id): array
    {
        return [
            'id' => $id,
            'discount_type' => (string) ($row['discount_type'] ?? 'percent'),
            'discount_value' => (float) ($row['discount_value'] ?? 0),
            'exclude_sale_products' => ($row['exclude_sale_products'] ?? 'false') === 'true' || ($row['exclude_sale_products'] ?? false) === true,
        ];
    }

    /** I periodi si intersecano (estremi compresi, fine vuota = per sempre). */
    private static function intersects(array $a, array $b): bool
    {
        $aStarts = self::moment($a['starts_at'] ?? '');
        $aEnds = self::moment($a['ends_at'] ?? '');
        $bStarts = self::moment($b['starts_at'] ?? '');
        $bEnds = self::moment($b['ends_at'] ?? '');

        return ($aEnds === '' || $bStarts === '' || $bStarts <= $aEnds)
            && ($bEnds === '' || $aStarts === '' || $aStarts <= $bEnds);
    }

    /**
     * @param array<int, array{facts: array<string, mixed>}> $catalog
     * @return array<int, true> gli id dei prodotti che il selettore prende
     */
    private static function covered(array $scope, array $catalog): array
    {
        $ids = [];

        foreach ($catalog as $id => $product) {
            if (ScopeMatcher::matches($scope, $product['facts'])) {
                $ids[$id] = true;
            }
        }

        return $ids;
    }

    /** Una data-ora, o stringa vuota se manca o è la «data zero» di MySQL. */
    private static function moment(mixed $value): string
    {
        $value = trim((string) $value);

        return ($value === '' || str_starts_with($value, '0000')) ? '' : $value;
    }
}
