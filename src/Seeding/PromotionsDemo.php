<?php

namespace Wonder\Plugin\Gestionale\Seeding;

use Wonder\Plugin\Gestionale\Console\Demo\DemoData;
use Wonder\Plugin\Gestionale\Models\Catalog\Brand;
use Wonder\Plugin\Gestionale\Models\Catalog\Category;
use Wonder\Plugin\Gestionale\Models\Catalog\Tag;
use Wonder\Plugin\Gestionale\Models\Promotions\DiscountCampaign;
use Wonder\Plugin\Gestionale\Models\Promotions\DiscountCampaignBrand;
use Wonder\Plugin\Gestionale\Models\Promotions\DiscountCampaignCategory;
use Wonder\Plugin\Gestionale\Models\Promotions\DiscountCampaignTag;
use Wonder\Plugin\Gestionale\Support\Promotions\ProductScope;

/**
 * Le campagne di sconto di prova: tre, una per ogni stato che il commerciante
 * deve imparare a riconoscere.
 *
 * - **in corso**: 20 % sulla categoria «Magliette e felpe»;
 * - **programmata**: 5 € sui prodotti col tag «Saldi», parte fra qualche giorno;
 * - **finita**: 10 % sul marchio «Maglificio Aurora», chiusa il mese scorso.
 *
 * Si appoggiano alle tassonomie di `CatalogDemo`, che vengono prima: se una
 * non c'è (cancellata a mano), la campagna che la usa non si fa e il comando
 * lo dice. Le date sono relative a oggi, così ogni campagna resta nel suo stato
 * quando i dati di prova si rifanno.
 *
 * La pulizia cancella davvero le campagne col segno e i loro ponti: nessuna
 * riga vera le usa, e non resta niente di orfano.
 */
final class PromotionsDemo
{
    /** Chiave nel registro dei dati di prova. */
    public const KEY = 'campagne-sconto';

    public static function register(): void
    {
        DemoData::register(
            self::KEY,
            'Campagne di sconto: una in corso, una programmata, una finita',
            static fn (): int => self::create(),
            static fn (): int => self::clear()
        );
    }

    /** @return int righe create */
    public static function create(): int
    {
        $created = 0;
        $missing = [];

        foreach (self::campaigns() as $ref => $campaign) {
            [$bridge, $field, $taxonomy, $taxonomyRef] = $campaign['target'];
            $targetId = self::idOf($taxonomy, $taxonomyRef);

            if ($targetId <= 0) {
                $missing[] = $campaign['values']['name'];
                continue;
            }

            $code = DemoCode::forModel(DiscountCampaign::class, $ref);

            if (self::find($code) !== null) {
                continue;
            }

            $id = DemoCode::revive(DiscountCampaign::class, $code);

            if ($id <= 0) {
                $result = DiscountCampaign::create($campaign['values'] + ['code' => $code]);
                $id = !empty($result->success) ? (int) ($result->insert_id ?? 0) : 0;
            }

            if ($id <= 0) {
                continue;
            }

            $created++;

            if (self::bridgesOf($bridge, $id) === []) {
                $bridge::create(['discount_campaign_id' => $id, $field => $targetId]);
                $created++;
            }
        }

        if ($missing !== []) {
            DemoData::note('Campagne non create, manca la tassonomia di prova: '.implode(', ', $missing).'.');
        }

        return $created;
    }

    /** @return int righe tolte */
    public static function clear(): int
    {
        $removed = 0;
        $owner = ProductScope::OWNERS['campaign'];

        foreach (self::ours() as $row) {
            $id = (int) $row['id'];

            foreach (['categories', 'tags', 'brands', 'product_models'] as $part) {
                foreach (self::bridgesOf($owner[$part], $id) as $bridge) {
                    $owner[$part]::delete((int) $bridge['id']);
                    $removed++;
                }
            }

            DiscountCampaign::delete($id);
            $removed++;
        }

        return $removed;
    }

    /**
     * Le campagne, per riferimento: valori, e la tassonomia che scelgono
     * (ponte, colonna del ponte, Model, riferimento di prova).
     *
     * @return array<string, array{values: array<string, mixed>, target: array{0: class-string, 1: string, 2: class-string, 3: string}}>
     */
    private static function campaigns(): array
    {
        $day = static fn (int $offset, string $time): string => date('Y-m-d', strtotime(($offset >= 0 ? '+' : '').$offset.' days')).' '.$time;

        $common = [
            'active' => 'true',
            'applies_to_all' => 'false',
            'exclude_sale_products' => 'false',
            'applies_online' => 'true',
            'applies_office' => 'false',
            'applies_pos' => 'false',
        ];

        return [
            'in-corso' => [
                'values' => $common + [
                    'name' => 'Magliette di stagione -20%',
                    'discount_type' => 'percent',
                    'discount_value' => '20.00',
                    'starts_at' => $day(-7, '00:00:00'),
                    'ends_at' => $day(30, '23:59:59'),
                    'note' => 'Dato di prova: in corso.',
                ],
                'target' => [DiscountCampaignCategory::class, 'category_id', Category::class, 'magliette-e-felpe'],
            ],
            'programmata' => [
                'values' => $common + [
                    'name' => 'Saldi: 5 euro in meno',
                    'discount_type' => 'amount',
                    'discount_value' => '5.00',
                    'starts_at' => $day(5, '00:00:00'),
                    'ends_at' => $day(20, '23:59:59'),
                    'note' => 'Dato di prova: programmata.',
                ],
                'target' => [DiscountCampaignTag::class, 'tag_id', Tag::class, 'saldi'],
            ],
            'finita' => [
                'values' => $common + [
                    'name' => 'Aurora -10% (chiusa)',
                    'discount_type' => 'percent',
                    'discount_value' => '10.00',
                    'starts_at' => $day(-60, '00:00:00'),
                    'ends_at' => $day(-30, '23:59:59'),
                    'note' => 'Dato di prova: finita.',
                ],
                'target' => [DiscountCampaignBrand::class, 'brand_id', Brand::class, 'maglificio-aurora'],
            ],
        ];
    }

    /** L'id della tassonomia di prova con quel riferimento, `0` se non c'è. */
    private static function idOf(string $model, string $ref): int
    {
        $row = $model::find(['code' => DemoCode::forModel($model, $ref), 'deleted' => 'false'], 1);

        return is_array($row) ? (int) ($row['id'] ?? 0) : 0;
    }

    /** @return array<string, mixed>|null la campagna viva con quel codice */
    private static function find(string $code): ?array
    {
        $row = DiscountCampaign::find(['code' => $code, 'deleted' => 'false'], 1);

        return is_array($row) && (int) ($row['id'] ?? 0) > 0 ? $row : null;
    }

    /**
     * Le campagne di prova, anche quelle cancellate dal backend: il codice è
     * unico e una riga nascosta bloccherebbe comunque il ricrearla.
     *
     * @return list<array<string, mixed>>
     */
    private static function ours(): array
    {
        $rows = DiscountCampaign::find("code LIKE 'dsc\\_demo-%' AND (deleted = 'false' OR deleted = 'true')");

        if (!is_array($rows) || $rows === []) {
            return [];
        }

        $rows = array_key_exists('id', $rows) ? [$rows] : array_values(array_filter($rows, 'is_array'));

        return array_values(array_filter($rows, static fn (array $row): bool => DemoCode::is((string) ($row['code'] ?? ''))));
    }

    /**
     * @param class-string<\Wonder\App\Model> $bridge
     * @return list<array<string, mixed>>
     */
    private static function bridgesOf(string $bridge, int $campaignId): array
    {
        $rows = $bridge::find("discount_campaign_id = {$campaignId} AND (deleted = 'false' OR deleted = 'true')");

        if (!is_array($rows) || $rows === []) {
            return [];
        }

        return array_key_exists('id', $rows) ? [$rows] : array_values(array_filter($rows, 'is_array'));
    }
}
