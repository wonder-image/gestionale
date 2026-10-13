<?php

namespace Wonder\Plugin\Gestionale\Seeding;

use Wonder\Plugin\Gestionale\Console\Demo\DemoData;
use Wonder\Plugin\Gestionale\Models\Subscriptions\Plan;
use Wonder\Plugin\Gestionale\Models\Subscriptions\PlanFeature;
use Wonder\Plugin\Gestionale\Models\Subscriptions\PlanPrice;
use Wonder\Plugin\Gestionale\Models\Tax\TaxCategory;
use Wonder\Plugin\Gestionale\Support\Catalog\Slug;
use Wonder\Plugin\Gestionale\Support\Positions;
use Wonder\Plugin\Gestionale\Support\Subscriptions\Subscriptions;

/**
 * I piani di prova (G10.1): «Base» e «Pro», ognuno con un prezzo mensile e
 * uno annuale e due limiti. Il mensile di «Pro» ha quattordici giorni di
 * prova; l'annuale di «Pro» un anno di vincolo.
 *
 * Il segno di prova sta nel codice del piano e dei prezzi (`pln_demo-base`,
 * `ppr_demo-base-mensile`). La pulizia toglie piani, prezzi e limiti col
 * segno, ma non un piano con un prezzo usato da un abbonamento: quello resta
 * e il comando lo dice.
 */
final class PlansDemo
{
    public const KEY = 'piani';

    public static function register(): void
    {
        DemoData::register(
            self::KEY,
            'Due piani di abbonamento (Base e Pro) con prezzi mensile e annuale',
            static fn (): int => self::create(),
            static fn (): int => self::clear()
        );
    }

    /** @return int righe create */
    public static function create(): int
    {
        $created = 0;
        $tax = self::ordinaryTaxCategoryId();

        foreach (self::plans() as $ref => $plan) {
            $code = DemoCode::forModel(Plan::class, $ref);

            if (self::find(Plan::class, $code) !== null) {
                continue;
            }

            $id = DemoCode::revive(Plan::class, $code);

            if ($id <= 0) {
                $result = Plan::create($plan['values'] + [
                    'code' => $code,
                    'slug' => Slug::make((string) $plan['values']['name'], Plan::$table),
                    'tax_category_id' => $tax > 0 ? $tax : '',
                    'visible_online' => 'true',
                    'active' => 'true',
                    'position' => Positions::next(Plan::$table),
                ]);
                $id = !empty($result->success) ? (int) ($result->insert_id ?? 0) : 0;
            }

            if ($id <= 0) {
                continue;
            }

            $created++;
            $position = 0;

            foreach ($plan['prices'] as $priceRef => $price) {
                $priceCode = DemoCode::forModel(PlanPrice::class, $ref.'-'.$priceRef);

                if (self::find(PlanPrice::class, $priceCode) === null && DemoCode::revive(PlanPrice::class, $priceCode) <= 0) {
                    PlanPrice::create($price + [
                        'code' => $priceCode,
                        'plan_id' => $id,
                        'sale_price' => '0.00',
                        'renewal_plan_price_id' => 0,
                        'active' => 'true',
                        'position' => $position,
                    ]);
                    $created++;
                }

                $position++;
            }

            if (self::rows(PlanFeature::find(['plan_id' => $id, 'deleted' => 'false'])) === []) {
                $position = 0;

                foreach ($plan['features'] as $key => $value) {
                    PlanFeature::create(['plan_id' => $id, 'feature_key' => $key, 'feature_value' => $value, 'position' => $position++]);
                    $created++;
                }
            }
        }

        return $created;
    }

    /** @return int righe tolte */
    public static function clear(): int
    {
        $removed = 0;
        $kept = [];

        foreach (self::ours() as $plan) {
            $id = (int) $plan['id'];
            $prices = array_map(
                static fn (array $row): int => (int) $row['id'],
                self::rows(PlanPrice::find("plan_id = {$id} AND (deleted = 'false' OR deleted = 'true')"))
            );

            if (Subscriptions::usedPrices($prices) !== []) {
                $kept[] = (string) ($plan['name'] ?? '');
                continue;
            }

            $removed += count($prices) + count(self::rows(PlanFeature::find(['plan_id' => $id])));
            sqlDelete(PlanFeature::$table, 'plan_id = '.$id);
            sqlDelete(PlanPrice::$table, 'plan_id = '.$id);
            sqlDelete(Plan::$table, 'id = '.$id);
            $removed++;
        }

        if ($kept !== []) {
            DemoData::note('Piani di prova lasciati, hanno abbonamenti: '.implode(', ', $kept).'.');
        }

        return $removed;
    }

    /**
     * @return array<string, array{values: array<string, mixed>, prices: array<string, array<string, mixed>>, features: array<string, string>}>
     */
    private static function plans(): array
    {
        return [
            'base' => [
                'values' => ['name' => 'Base', 'description' => 'Per cominciare: tre progetti e supporto via email.'],
                'prices' => [
                    'mensile' => ['name' => 'Mensile', 'billing_interval' => 'month', 'term_months' => 1, 'commitment_months' => 0, 'trial_days' => 0, 'price' => '9.90'],
                    'annuale' => ['name' => 'Annuale', 'billing_interval' => 'year', 'term_months' => 12, 'commitment_months' => 0, 'trial_days' => 0, 'price' => '99.00'],
                ],
                'features' => ['max_progetti' => '3', 'supporto' => 'email'],
            ],
            'pro' => [
                'values' => ['name' => 'Pro', 'description' => 'Per chi lavora tutti i giorni: venti progetti e supporto prioritario.'],
                'prices' => [
                    'mensile' => ['name' => 'Mensile', 'billing_interval' => 'month', 'term_months' => 1, 'commitment_months' => 0, 'trial_days' => 14, 'price' => '24.90'],
                    'annuale' => ['name' => 'Annuale', 'billing_interval' => 'year', 'term_months' => 12, 'commitment_months' => 12, 'trial_days' => 0, 'price' => '249.00'],
                ],
                'features' => ['max_progetti' => '20', 'supporto' => 'prioritario'],
            ],
        ];
    }

    /** I piani di prova, anche quelli cancellati dal backend. @return list<array<string, mixed>> */
    private static function ours(): array
    {
        $rows = self::rows(Plan::find("code LIKE 'pln\\_demo-%' AND (deleted = 'false' OR deleted = 'true')"));

        return array_values(array_filter($rows, static fn (array $row): bool => DemoCode::is((string) ($row['code'] ?? ''))));
    }

    /** @param class-string<\Wonder\App\Model> $model @return array<string, mixed>|null */
    private static function find(string $model, string $code): ?array
    {
        $row = $model::find(['code' => $code, 'deleted' => 'false'], 1);

        return is_array($row) && (int) ($row['id'] ?? 0) > 0 ? $row : null;
    }

    /** Il tipo fiscale ordinario, quello che `Defaults` precarica. */
    private static function ordinaryTaxCategoryId(): int
    {
        $row = TaxCategory::find(['code' => 'ordinaria', 'deleted' => 'false'], 1);

        return is_array($row) ? (int) ($row['id'] ?? 0) : 0;
    }

    /** @return list<array<string, mixed>> */
    private static function rows(mixed $found): array
    {
        if (!is_array($found) || $found === []) {
            return [];
        }

        return array_key_exists('id', $found) ? [$found] : array_values(array_filter($found, 'is_array'));
    }
}
