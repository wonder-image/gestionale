<?php

namespace Wonder\Plugin\Gestionale\Seeding;

use Wonder\Plugin\Gestionale\Console\Demo\DemoData;
use Wonder\Plugin\Gestionale\Models\Catalog\Brand;
use Wonder\Plugin\Gestionale\Models\Catalog\Category;
use Wonder\Plugin\Gestionale\Models\Catalog\Tag;
use Wonder\Plugin\Gestionale\Models\Contacts\Contact;
use Wonder\Plugin\Gestionale\Models\Promotions\Coupon;
use Wonder\Plugin\Gestionale\Models\Promotions\CouponCustomer;
use Wonder\Plugin\Gestionale\Models\Promotions\CouponRedemption;
use Wonder\Plugin\Gestionale\Models\Promotions\DiscountCampaign;
use Wonder\Plugin\Gestionale\Models\Promotions\DiscountCampaignBrand;
use Wonder\Plugin\Gestionale\Models\Promotions\DiscountCampaignCategory;
use Wonder\Plugin\Gestionale\Models\Promotions\DiscountCampaignTag;
use Wonder\Plugin\Gestionale\Support\Promotions\ProductScope;

/**
 * Le promozioni di prova: tre campagne di sconto e cinque coupon.
 *
 * Le campagne, una per ogni stato che il commerciante deve imparare a
 * riconoscere.
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
 * I coupon sono cinque, uno per tipo di regola: **percentuale**, **importo**
 * (con spesa minima), **spedizione gratuita**, **riservato** a un cliente di
 * prova e **primo ordine**. Il codice è quello che il cliente digita, quindi
 * non porta il segno degli altri dati di prova: si riconoscono dal prefisso
 * `DEMO-` *e* dalla nota «Dato di prova», così un coupon vero che per caso
 * inizia per `DEMO-` non viene mai toccato. Il riservato ha bisogno della
 * scheda di prova «Anna Verdi»: se non c'è non si fa e il comando lo dice.
 * Gli ordini di `OrdersDemo` li usano.
 *
 * La pulizia cancella davvero campagne e coupon col segno, i loro ponti, i
 * clienti riservati e gli utilizzi: nessuna riga vera li usa, e non resta
 * niente di orfano.
 */
final class PromotionsDemo
{
    /** Chiave nel registro dei dati di prova. */
    public const KEY = 'campagne-sconto';

    /** Il testo che segna come «di prova» la nota di un coupon. */
    private const COUPON_NOTE = 'Dato di prova';

    public static function register(): void
    {
        DemoData::register(
            self::KEY,
            'Campagne di sconto (in corso, programmata, finita) e cinque coupon',
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

        return $created + self::createCoupons();
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

        foreach (self::ourCoupons() as $row) {
            $id = (int) $row['id'];

            // Utilizzi e clienti non hanno un `deleted` che conti: si tolgono per davvero, insieme.
            foreach (['product_models', 'brands', 'tags', 'categories'] as $part) {
                sqlDelete(ProductScope::OWNERS['coupon'][$part]::$table, 'coupon_id = '.$id);
            }

            sqlDelete(CouponRedemption::$table, 'coupon_id = '.$id);
            sqlDelete(CouponCustomer::$table, 'coupon_id = '.$id);
            Coupon::delete($id);
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

    /** @return int righe create */
    private static function createCoupons(): int
    {
        $created = 0;

        foreach (self::coupons() as $values) {
            $code = (string) $values['code'];
            $existing = self::couponByCode($code);

            if ($existing !== null) {
                if (!self::isOurs($existing)) {
                    DemoData::note('Coupon «'.$code.'» non creato: il codice è già di un coupon vero.');
                } elseif ((string) ($existing['deleted'] ?? 'false') === 'true') {
                    // Cancellato dal backend: il codice è unico, si rimette in vita.
                    Coupon::query()->Update(Coupon::$table, ['deleted' => 'false'], 'id', (int) $existing['id']);
                    $created++;
                }

                continue;
            }

            $customer = (string) ($values['customer'] ?? '');
            unset($values['customer']);
            $customerId = 0;

            if ($customer !== '') {
                $row = Contact::find(['code' => DemoCode::forModel(Contact::class, $customer), 'deleted' => 'false'], 1);
                $customerId = is_array($row) ? (int) ($row['id'] ?? 0) : 0;

                if ($customerId <= 0) {
                    DemoData::note('Coupon «'.$code.'» non creato: manca la scheda cliente di prova «'.$customer.'».');
                    continue;
                }
            }

            $result = Coupon::create($values);
            $id = !empty($result->success) ? (int) ($result->insert_id ?? 0) : 0;

            if ($id <= 0) {
                continue;
            }

            $created++;

            if ($customerId > 0) {
                CouponCustomer::create(['coupon_id' => $id, 'customer_id' => $customerId]);
                $created++;
            }
        }

        return $created;
    }

    /**
     * I cinque coupon. Sempre validi (dal mese scorso a fra sei mesi) e per
     * il canale online; il cliente riservato è un riferimento di `ContactsDemo`.
     *
     * @return list<array<string, mixed>>
     */
    private static function coupons(): array
    {
        $common = [
            'applies_to_all' => 'true',
            'exclude_discounted_products' => 'false',
            'first_order_only' => 'false',
            'applies_online' => 'true',
            'applies_office' => 'false',
            'applies_pos' => 'false',
            'active' => 'true',
            'starts_at' => date('Y-m-d', strtotime('-30 days')).' 00:00:00',
            'ends_at' => date('Y-m-d', strtotime('+180 days')).' 23:59:59',
        ];

        return [
            $common + [
                'code' => 'DEMO-PERCENTUALE10', 'name' => 'Dieci per cento su tutto',
                'discount_type' => 'percent', 'discount_value' => '10.00', 'note' => self::COUPON_NOTE.': percentuale.',
            ],
            $common + [
                'code' => 'DEMO-IMPORTO5', 'name' => 'Cinque euro sopra i trenta',
                'discount_type' => 'amount', 'discount_value' => '5.00', 'min_order_amount' => '30.00',
                'usage_limit' => '50', 'note' => self::COUPON_NOTE.': importo con spesa minima.',
            ],
            $common + [
                'code' => 'DEMO-SPEDIZIONE', 'name' => 'Spedizione gratuita sopra i cinquanta',
                'discount_type' => 'free_shipping', 'discount_value' => '0.00', 'min_order_amount' => '50.00',
                'note' => self::COUPON_NOTE.': spedizione gratuita.',
            ],
            $common + [
                'code' => 'DEMO-ANNA15', 'name' => 'Quindici per cento per Anna',
                'discount_type' => 'percent', 'discount_value' => '15.00', 'usage_limit_per_customer' => '3',
                'customer' => 'verdi', 'note' => self::COUPON_NOTE.': riservato a un cliente.',
            ],
            [...$common, 'first_order_only' => 'true'] + [
                'code' => 'DEMO-PRIMO', 'name' => 'Benvenuto: dieci per cento sul primo ordine',
                'discount_type' => 'percent', 'discount_value' => '10.00', 'usage_limit_per_customer' => '1',
                'note' => self::COUPON_NOTE.': primo ordine.',
            ],
        ];
    }

    /** @return array<string, mixed>|null il coupon con quel codice, anche cancellato */
    private static function couponByCode(string $code): ?array
    {
        foreach (['false', 'true'] as $deleted) {
            $row = Coupon::find(['code' => $code, 'deleted' => $deleted], 1);

            if (is_array($row) && (int) ($row['id'] ?? 0) > 0) {
                return $row;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $coupon */
    private static function isOurs(array $coupon): bool
    {
        return str_starts_with((string) ($coupon['code'] ?? ''), 'DEMO-')
            && str_starts_with((string) ($coupon['note'] ?? ''), self::COUPON_NOTE);
    }

    /**
     * I coupon di prova, anche quelli cancellati dal backend: il codice è
     * unico e una riga nascosta bloccherebbe comunque il ricrearla.
     *
     * @return list<array<string, mixed>>
     */
    private static function ourCoupons(): array
    {
        $rows = Coupon::find("code LIKE 'DEMO-%' AND (deleted = 'false' OR deleted = 'true')");

        if (!is_array($rows) || $rows === []) {
            return [];
        }

        $rows = array_key_exists('id', $rows) ? [$rows] : array_values(array_filter($rows, 'is_array'));

        return array_values(array_filter($rows, static fn (array $row): bool => self::isOurs($row)));
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
