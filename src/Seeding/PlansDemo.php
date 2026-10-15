<?php

namespace Wonder\Plugin\Gestionale\Seeding;

use Wonder\Plugin\Gestionale\Console\Demo\DemoData;
use Wonder\Plugin\Gestionale\Models\Catalog\Customization;
use Wonder\Plugin\Gestionale\Models\Catalog\CustomizationLink;
use Wonder\Plugin\Gestionale\Models\Subscriptions\Plan;
use Wonder\Plugin\Gestionale\Models\Subscriptions\PlanFeature;
use Wonder\Plugin\Gestionale\Models\Subscriptions\PlanLink;
use Wonder\Plugin\Gestionale\Models\Subscriptions\PlanPrice;
use Wonder\Plugin\Gestionale\Models\Tax\TaxCategory;
use Wonder\Plugin\Gestionale\Resources\Catalog\CustomizationResource;
use Wonder\Plugin\Gestionale\Support\Catalog\Slug;
use Wonder\Plugin\Gestionale\Support\Positions;
use Wonder\Plugin\Gestionale\Support\Subscriptions\Subscriptions;

/**
 * I piani di prova (G10.1): «Base» e «Pro», ognuno con un prezzo mensile e
 * uno annuale e due limiti. Il mensile di «Pro» ha quattordici giorni di
 * prova; l'annuale di «Pro» un anno di vincolo.
 *
 * E tre servizi da comporre in un abbonamento (G10b): «Dominio .it» annuale
 * con la personalizzazione «Nome del dominio» (testo, obbligatoria),
 * «Hosting» annuale che si lega a «Dominio .it», «Assistenza» mensile.
 *
 * Il segno di prova sta nel codice del piano e dei prezzi (`pln_demo-base`,
 * `ppr_demo-base-mensile`). La pulizia toglie piani, prezzi e limiti col
 * segno, ma non un piano con un prezzo usato da un abbonamento: quello resta
 * e il comando lo dice. Toglie anche i legami dei piani e la personalizzazione
 * di prova, quando nessun piano la usa più.
 */
final class PlansDemo
{
    public const KEY = 'piani';

    private const DOMAIN_NAME = 'nome-dominio';

    public static function register(): void
    {
        DemoData::register(
            self::KEY,
            'Due piani di abbonamento (Base e Pro) e tre servizi (Dominio .it, Hosting, Assistenza)',
            static fn (): int => self::create(),
            static fn (): int => self::clear()
        );
    }

    /** @return int righe create */
    public static function create(): int
    {
        $created = 0;
        $tax = self::ordinaryTaxCategoryId();
        $customization = self::domainName($created);
        $ids = [];

        foreach (self::plans() as $ref => $plan) {
            $code = DemoCode::forModel(Plan::class, $ref);
            $found = self::find(Plan::class, $code);

            if ($found !== null) {
                $ids[$ref] = (int) $found['id'];
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

            $ids[$ref] = $id;
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

            $parent = $ids[$plan['parent'] ?? ''] ?? 0;

            if ($parent > 0 && self::rows(PlanLink::find(['plan_id' => $id, 'parent_plan_id' => $parent])) === []) {
                PlanLink::create(['plan_id' => $id, 'parent_plan_id' => $parent]);
                $created++;
            }

            if (!empty($plan['domain_name']) && $customization > 0 && self::rows(CustomizationLink::find(['plan_id' => $id, 'customization_id' => $customization, 'deleted' => 'false'])) === []) {
                CustomizationLink::create(['plan_id' => $id, 'customization_id' => $customization, 'is_required' => 'true', 'position' => 1]);
                $created++;
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

            $removed += count($prices) + count(self::rows(PlanFeature::find(['plan_id' => $id])))
                + count(self::rows(CustomizationLink::find("plan_id = {$id} AND (deleted = 'false' OR deleted = 'true')")))
                + count(self::rows(PlanLink::find("plan_id = {$id} OR parent_plan_id = {$id}")));
            // Prima i legami: le chiavi esterne non lasciano andare il piano.
            sqlDelete(CustomizationLink::$table, 'plan_id = '.$id);
            sqlDelete(PlanLink::$table, 'plan_id = '.$id.' OR parent_plan_id = '.$id);
            sqlDelete(PlanFeature::$table, 'plan_id = '.$id);
            sqlDelete(PlanPrice::$table, 'plan_id = '.$id);
            sqlDelete(Plan::$table, 'id = '.$id);
            $removed++;
        }

        if ($kept !== []) {
            DemoData::note('Piani di prova lasciati, hanno abbonamenti: '.implode(', ', $kept).'.');
        }

        $customization = Customization::find(['code' => DemoCode::forModel(Customization::class, self::DOMAIN_NAME), 'deleted' => 'false'], 1);

        // Resta finché un piano (di prova lasciato o vero) la usa.
        if (is_array($customization) && isset($customization['id'])
            && self::rows(CustomizationLink::find(['customization_id' => (int) $customization['id'], 'deleted' => 'false'])) === []) {
            try {
                CustomizationResource::deleteRecord((int) $customization['id']);
                $removed++;
            } catch (\Throwable) {
                DemoData::note('Personalizzazione di prova lasciata: «Nome del dominio».');
            }
        }

        return $removed;
    }

    /** La personalizzazione «Nome del dominio», creata se manca. @return int il suo id */
    private static function domainName(int &$created): int
    {
        $code = DemoCode::forModel(Customization::class, self::DOMAIN_NAME);
        $found = self::find(Customization::class, $code);

        if ($found !== null) {
            return (int) $found['id'];
        }

        $id = DemoCode::revive(Customization::class, $code);

        if ($id > 0) {
            return $id;
        }

        $result = Customization::create([
            'code' => $code,
            'name' => 'Nome del dominio',
            'label' => 'Nome del dominio',
            'help_text' => 'Senza www, per esempio annaverdi.it',
            'kind' => 'text',
            'max_length' => 63,
            'active' => 'true',
            'for_products' => 'false',
            'for_subscriptions' => 'true',
        ]);
        $id = !empty($result->success) ? (int) ($result->insert_id ?? 0) : 0;
        $created += $id > 0 ? 1 : 0;

        return $id;
    }

    /**
     * @return array<string, array{values: array<string, mixed>, prices: array<string, array<string, mixed>>, features: array<string, string>, parent?: string, domain_name?: bool}>
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
            'dominio' => [
                'values' => ['name' => 'Dominio .it', 'description' => 'La registrazione del dominio, rinnovata ogni anno.'],
                'prices' => [
                    'annuale' => ['name' => 'Annuale', 'billing_interval' => 'year', 'term_months' => 12, 'commitment_months' => 0, 'trial_days' => 0, 'price' => '12.20'],
                ],
                'features' => [],
                'domain_name' => true,
            ],
            'hosting' => [
                'values' => ['name' => 'Hosting', 'description' => 'Lo spazio del sito sul dominio scelto.'],
                'prices' => [
                    'annuale' => ['name' => 'Annuale', 'billing_interval' => 'year', 'term_months' => 12, 'commitment_months' => 0, 'trial_days' => 0, 'price' => '61.00'],
                ],
                'features' => [],
                'parent' => 'dominio',
            ],
            'assistenza' => [
                'values' => ['name' => 'Assistenza', 'description' => 'Aggiornamenti e assistenza via email, ogni mese.'],
                'prices' => [
                    'mensile' => ['name' => 'Mensile', 'billing_interval' => 'month', 'term_months' => 1, 'commitment_months' => 0, 'trial_days' => 0, 'price' => '10.00'],
                ],
                'features' => [],
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
