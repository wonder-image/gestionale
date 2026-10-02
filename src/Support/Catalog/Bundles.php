<?php

namespace Wonder\Plugin\Gestionale\Support\Catalog;

use Wonder\Plugin\Gestionale\Models\Catalog\BundleComponent;
use Wonder\Plugin\Gestionale\Models\Catalog\BundleGroup;
use Wonder\Plugin\Gestionale\Models\Catalog\BundleGroupOption;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Stock\Levels;
use Wonder\Plugin\Gestionale\Support\Stock\ProductNames;
use Wonder\Plugin\Gestionale\Support\Stock\Stock;

/**
 * I multiprodotti: cosa contengono, cosa si può scegliere, quante confezioni si
 * vendono e quanto valgono i pezzi che portano dentro.
 *
 * Un multiprodotto sta in un articolo (`bundle_mode` dice se i suoi pezzi sono
 * fissi, a scelta o l'uno e l'altro). I **componenti fissi** hanno una quantità;
 * i **gruppi** offrono delle opzioni da cui il cliente sceglie fra `min` e `max`,
 * una per pezzo. Le funzioni che non nominano il database sono pure
 * ({@see assertComposition()}, {@see choose()}, {@see pieces()}, {@see shortfall()},
 * {@see capacity()}, {@see value()}) e si provano da sole; le altre leggono le tre
 * tabelle e fanno da ponte verso il carrello.
 */
final class Bundles
{
    public const MODES = ['fixed', 'choice', 'mixed'];

    /** Il tetto delle confezioni quando nessun componente limita. */
    public const UNLIMITED = 999999.0;

    /**
     * La composizione che il form manda, controllata prima di scriverla.
     *
     * I riquadri che la modalità nasconde (i componenti con `choice`, i gruppi
     * con `fixed`) non si guardano: li scarta chi salva.
     *
     * @param list<array{product_id: int, quantity: float|int|string}> $components
     * @param list<array{name: string, min: int, max: int, options: list<array{product_id: int, surcharge: float|int|string}>}> $groups
     *
     * @throws UserError
     */
    public static function assertComposition(string $mode, array $components, array $groups): void
    {
        if (!in_array($mode, self::MODES, true)) {
            throw UserError::make('bundle.unknown_mode');
        }

        if ($mode !== 'choice') {
            self::assertComponents($components);
        }

        if ($mode !== 'fixed') {
            self::assertGroups($groups);
        }
    }

    /**
     * I prodotti della composizione, controllati prima di scriverli: devono
     * esistere e non essere cancellati, e non possono essere a loro volta
     * multiprodotti. Un prodotto solo spento passa: chi modifica una scheda
     * non deve essere fermato da un componente messo a riposo.
     *
     * Come `assertComposition`, guarda solo i riquadri che la modalità tiene.
     *
     * @param list<array{product_id: int, quantity: float|int|string}> $components
     * @param list<array{name: string, min: int, max: int, options: list<array{product_id: int, surcharge: float|int|string}>}> $groups
     *
     * @throws UserError
     */
    public static function assertProducts(string $mode, array $components, array $groups): void
    {
        $ids = [];

        if ($mode !== 'choice') {
            array_push($ids, ...array_column($components, 'product_id'));
        }

        if ($mode !== 'fixed') {
            foreach ($groups as $group) {
                array_push($ids, ...array_column($group['options'], 'product_id'));
            }
        }

        foreach (array_unique(array_map('intval', $ids)) as $id) {
            $product = self::product($id);

            if ($product === null || ($product['deleted'] ?? 'false') === 'true') {
                throw UserError::make('bundle.component_unavailable', ['name' => self::nameOf($id)]);
            }

            if (self::isBundle($id)) {
                throw UserError::make('bundle.nested', ['name' => self::nameOf($id)]);
            }
        }
    }

    /**
     * Le scelte di chi compra, controllate sui gruppi dell'articolo.
     *
     * Gli id arrivano come numeri o come stringhe e contano una volta sola.
     * Torna, nell'ordine dei gruppi, ogni opzione scelta col suo prodotto e il
     * suo sovrapprezzo.
     *
     * @param list<array{id: int, name: string, min: int, max: int, options: list<array{id: int, product_id: int, surcharge: float|int|string}>}> $groups
     * @param array<int|string, mixed> $optionIds
     * @return list<array{option_id: int, product_id: int, surcharge: string}>
     *
     * @throws UserError
     */
    public static function choose(array $groups, array $optionIds): array
    {
        $wanted = [];

        foreach ($optionIds as $id) {
            $wanted[is_numeric($id) ? (int) $id : 0] = true;
        }

        $known = [];

        foreach ($groups as $group) {
            foreach ($group['options'] as $option) {
                $known[(int) $option['id']] = true;
            }
        }

        foreach (array_keys($wanted) as $id) {
            if (!isset($known[$id])) {
                throw UserError::make('bundle.unknown_option');
            }
        }

        $chosen = [];

        foreach ($groups as $group) {
            $inGroup = [];

            foreach ($group['options'] as $option) {
                if (isset($wanted[(int) $option['id']])) {
                    $inGroup[] = [
                        'option_id' => (int) $option['id'],
                        'product_id' => (int) $option['product_id'],
                        'surcharge' => number_format((float) $option['surcharge'], 2, '.', ''),
                    ];
                }
            }

            if (count($inGroup) < (int) $group['min']) {
                throw UserError::make('bundle.too_few', ['name' => (string) $group['name'], 'min' => (int) $group['min']]);
            }

            if (count($inGroup) > (int) $group['max']) {
                throw UserError::make('bundle.too_many', ['name' => (string) $group['name'], 'max' => (int) $group['max']]);
            }

            array_push($chosen, ...$inGroup);
        }

        return $chosen;
    }

    /**
     * I pezzi di ogni prodotto per `$quantity` confezioni: i fissi per la loro
     * quantità e le scelte da uno, sommati per prodotto.
     *
     * @param list<array{product_id: int, quantity: float|int|string}> $components
     * @param list<array{product_id: int}> $chosen
     * @return array<int, float> product_id => pezzi
     */
    public static function pieces(array $components, array $chosen, float $quantity): array
    {
        $pieces = [];

        foreach ($components as $component) {
            $id = (int) $component['product_id'];
            $pieces[$id] = ($pieces[$id] ?? 0.0) + (float) $component['quantity'] * $quantity;
        }

        foreach ($chosen as $option) {
            $id = (int) $option['product_id'];
            $pieces[$id] = ($pieces[$id] ?? 0.0) + $quantity;
        }

        return array_map(static fn (float $pieces): float => round($pieces, 3), $pieces);
    }

    /**
     * Il primo prodotto che non ha pezzi a sufficienza, o `null` se bastano tutti.
     * Chi si vende scoperto (D60) non limita.
     *
     * @param array<int, float> $pieces
     * @param array<int, float> $levels product_id => disponibile
     * @param array<int, bool> $backorder
     * @return array{product_id: int, available: float}|null
     */
    public static function shortfall(array $pieces, array $levels, array $backorder): ?array
    {
        foreach ($pieces as $productId => $needed) {
            if ($backorder[$productId] ?? false) {
                continue;
            }

            $available = (float) ($levels[$productId] ?? 0.0);

            if (round($needed - $available, 3) > 0.0) {
                return ['product_id' => (int) $productId, 'available' => $available];
            }
        }

        return null;
    }

    /**
     * Le confezioni vendibili.
     *
     * Il minimo fra i componenti fissi (disponibile diviso quantità, per difetto)
     * e, per ogni gruppo con `min` sopra zero, la disponibilità della `min`-esima
     * opzione più fornita. Chi si vende scoperto non limita; se nessuno limita
     * torna {@see UNLIMITED}; senza composizione, zero. Un'opzione che è anche un
     * componente fisso chiede pezzi per entrambi: la sua capacità è il
     * disponibile diviso (quantità fissa + 1).
     *
     * @param list<array{product_id: int, quantity: float|int|string}> $components
     * @param list<array{min: int, options: list<array{product_id: int}>}> $groups
     * @param array<int, float> $levels
     * @param array<int, bool> $backorder
     */
    public static function capacity(array $components, array $groups, array $levels, array $backorder): float
    {
        if ($components === [] && $groups === []) {
            return 0.0;
        }

        $fixed = [];

        foreach ($components as $component) {
            $fixed[(int) $component['product_id']] = (float) $component['quantity'];
        }

        $capacity = self::UNLIMITED;

        foreach ($fixed as $productId => $quantity) {
            if (!($backorder[$productId] ?? false)) {
                $capacity = min($capacity, self::whole((float) ($levels[$productId] ?? 0.0), $quantity));
            }
        }

        foreach ($groups as $group) {
            $min = (int) $group['min'];

            if ($min <= 0) {
                continue;
            }

            $options = [];

            foreach ($group['options'] as $option) {
                $productId = (int) $option['product_id'];

                $options[] = ($backorder[$productId] ?? false)
                    ? self::UNLIMITED
                    : self::whole((float) ($levels[$productId] ?? 0.0), ($fixed[$productId] ?? 0.0) + 1.0);
            }

            rsort($options);
            $capacity = min($capacity, $options[$min - 1] ?? 0.0);
        }

        return $capacity;
    }

    /**
     * Quanto valgono i pezzi: i fissi per la loro quantità e, per ogni gruppo,
     * le `min` opzioni più economiche.
     *
     * @param list<array{product_id: int, quantity: float|int|string}> $components
     * @param list<array{min: int, options: list<array{product_id: int}>}> $groups
     * @param array<int, float> $prices product_id => prezzo corrente
     */
    public static function value(array $components, array $groups, array $prices): string
    {
        $total = 0.0;

        foreach ($components as $component) {
            $total += (float) ($prices[(int) $component['product_id']] ?? 0.0) * (float) $component['quantity'];
        }

        foreach ($groups as $group) {
            $costs = [];

            foreach ($group['options'] as $option) {
                $costs[] = (float) ($prices[(int) $option['product_id']] ?? 0.0);
            }

            sort($costs);
            $total += array_sum(array_slice($costs, 0, max(0, (int) $group['min'])));
        }

        return number_format(round($total, 2), 2, '.', '');
    }

    /**
     * La composizione di un articolo, con i nomi, per il form e per la pagina.
     * Solo righe non cancellate, nell'ordine in cui si mostrano.
     *
     * @return array{mode: string, show_value: bool, components: list<array{id: int, product_id: int, name: string, quantity: float}>, groups: list<array{id: int, name: string, min: int, max: int, options: list<array{id: int, product_id: int, name: string, surcharge: string}>}>}
     */
    public static function forModel(int $modelId): array
    {
        $empty = ['mode' => '', 'show_value' => false, 'components' => [], 'groups' => []];
        $model = $modelId > 0 ? ProductModel::findById($modelId) : null;

        if (!is_array($model) || !isset($model['id']) || ($model['deleted'] ?? 'false') === 'true') {
            return $empty;
        }

        $components = [];

        foreach (self::rows(BundleComponent::find(['product_model_id' => $modelId, 'deleted' => 'false'], null, 'position', 'ASC')) as $row) {
            $components[] = [
                'id' => (int) $row['id'],
                'product_id' => (int) $row['product_id'],
                'name' => self::nameOf((int) $row['product_id']),
                'quantity' => round((float) $row['quantity'], 3),
            ];
        }

        $groups = [];

        foreach (self::rows(BundleGroup::find(['product_model_id' => $modelId, 'deleted' => 'false'], null, 'position', 'ASC')) as $group) {
            $options = [];

            foreach (self::rows(BundleGroupOption::find(['bundle_group_id' => (int) $group['id'], 'deleted' => 'false'], null, 'position', 'ASC')) as $row) {
                $options[] = [
                    'id' => (int) $row['id'],
                    'product_id' => (int) $row['product_id'],
                    'name' => self::nameOf((int) $row['product_id']),
                    'surcharge' => number_format((float) $row['surcharge'], 2, '.', ''),
                ];
            }

            $groups[] = [
                'id' => (int) $group['id'],
                'name' => (string) $group['name'],
                'min' => (int) $group['min_choices'],
                'max' => (int) $group['max_choices'],
                'options' => $options,
            ];
        }

        return [
            'mode' => (string) ($model['bundle_mode'] ?? ''),
            'show_value' => ($model['show_components_value'] ?? 'false') === 'true',
            'components' => $components,
            'groups' => $groups,
        ];
    }

    /** Questo prodotto è un multiprodotto? */
    public static function isBundle(int $productId): bool
    {
        $model = self::modelOf($productId);

        return is_array($model) && ($model['type'] ?? 'simple') === 'bundle';
    }

    /**
     * Le righe figlie per **una** confezione e il sovrapprezzo delle opzioni.
     *
     * Non guarda l'interruttore `bundles`: chi ha già una confezione nel
     * carrello la tiene anche a funzionalità spenta. Un componente fisso o
     * un'opzione scelta il cui prodotto è spento o cancellato non si vende.
     *
     * @param array<int|string, mixed> $optionIds
     * @return array{modelId: int, children: list<array{product_id: int, quantity: float, bundle_option_id: int}>, surcharge: string}
     *
     * @throws UserError
     */
    public static function resolve(int $productId, array $optionIds): array
    {
        $model = self::modelOf($productId);

        if (!is_array($model) || ($model['type'] ?? 'simple') !== 'bundle') {
            throw UserError::make('bundle.not_bundle');
        }

        $modelId = (int) $model['id'];
        $bundle = self::forModel($modelId);
        $mode = $bundle['mode'];
        $components = $mode === 'choice' ? [] : $bundle['components'];
        $groups = $mode === 'fixed' ? [] : $bundle['groups'];

        self::assertComposition($mode, $components, $groups);

        foreach ($components as $component) {
            self::assertSellable((int) $component['product_id'], (string) $component['name']);
        }

        $chosen = self::choose($groups, $optionIds);
        $names = [];

        foreach ($groups as $group) {
            foreach ($group['options'] as $option) {
                $names[(int) $option['id']] = (string) $option['name'];
            }
        }

        $children = [];
        $surcharge = 0.0;

        foreach ($components as $component) {
            $children[] = [
                'product_id' => (int) $component['product_id'],
                'quantity' => (float) $component['quantity'],
                'bundle_option_id' => 0,
            ];
        }

        foreach ($chosen as $option) {
            self::assertSellable($option['product_id'], $names[$option['option_id']] ?? '');
            $surcharge += (float) $option['surcharge'];
            $children[] = ['product_id' => $option['product_id'], 'quantity' => 1.0, 'bundle_option_id' => $option['option_id']];
        }

        return [
            'modelId' => $modelId,
            'children' => $children,
            'surcharge' => number_format(round($surcharge, 2), 2, '.', ''),
        ];
    }

    /**
     * Le confezioni vendibili di un articolo, dal disponibile di `Levels::of()`
     * (già al netto delle prenotazioni). Un prodotto spento vale zero.
     */
    public static function available(int $modelId): float
    {
        [$components, $groups] = self::visible(self::forModel($modelId));
        $products = self::productsOf($components, $groups);
        $levels = Levels::forProducts(array_keys($products));
        $available = [];
        $backorder = [];

        foreach ($products as $id => $product) {
            $sellable = self::sellable($product);
            $available[$id] = $sellable ? (float) ($levels[$id]['available'] ?? 0.0) : 0.0;
            $backorder[$id] = $sellable && Stock::allowsBackorder($product);
        }

        return self::capacity($components, $groups, $available, $backorder);
    }

    /** La somma dei prezzi correnti dei pezzi (scontato se c'è), a due decimali. */
    public static function componentsValue(int $modelId): string
    {
        [$components, $groups] = self::visible(self::forModel($modelId));
        $prices = [];

        foreach (self::productsOf($components, $groups) as $id => $product) {
            $price = round((float) ($product['price'] ?? 0), 2);
            $sale = round((float) ($product['sale_price'] ?? 0), 2);
            $prices[$id] = $sale > 0.0 && $sale < $price ? $sale : $price;
        }

        return self::value($components, $groups, $prices);
    }

    /**
     * I multiprodotti (non cancellati) in cui il prodotto è componente o opzione,
     * senza doppioni.
     *
     * @return list<string>
     */
    public static function usedBy(int $productId): array
    {
        if ($productId <= 0) {
            return [];
        }

        $modelIds = [];

        foreach (self::rows(BundleComponent::find(['product_id' => $productId, 'deleted' => 'false'])) as $row) {
            $modelIds[(int) $row['product_model_id']] = true;
        }

        foreach (self::rows(BundleGroupOption::find(['product_id' => $productId, 'deleted' => 'false'])) as $row) {
            $group = BundleGroup::findById((int) $row['bundle_group_id']);

            if (is_array($group) && ($group['deleted'] ?? 'false') !== 'true') {
                $modelIds[(int) $group['product_model_id']] = true;
            }
        }

        $names = [];

        foreach (array_keys($modelIds) as $modelId) {
            $model = ProductModel::findById($modelId);

            if (is_array($model) && isset($model['id']) && ($model['deleted'] ?? 'false') !== 'true') {
                $names[] = (string) $model['name'];
            }
        }

        return $names;
    }

    /**
     * Un prodotto che fa parte di un multiprodotto non si elimina né si ferma:
     * la confezione resterebbe senza un pezzo.
     *
     * Rifiuta con `refusal()` e non con `make()`: chi cancella dall'elenco
     * intercetta `RuntimeException` (vedi `UserError`).
     *
     * @param array<string, mixed> $product
     */
    public static function assertNotUsed(array $product): void
    {
        $bundles = self::usedBy((int) ($product['id'] ?? 0));

        if ($bundles === []) {
            return;
        }

        throw UserError::refusal('bundle.in_use', [
            'name' => ProductNames::full($product, ProductNames::models([$product])),
            'bundles' => implode(', ', $bundles),
        ]);
    }

    /** @param list<array<string, mixed>> $components */
    private static function assertComponents(array $components): void
    {
        if ($components === []) {
            throw UserError::make('bundle.no_components');
        }

        $seen = [];

        foreach ($components as $component) {
            if ((float) $component['quantity'] <= 0.0) {
                throw UserError::make('bundle.zero_quantity');
            }

            $id = (int) $component['product_id'];

            if (isset($seen[$id])) {
                throw UserError::make('bundle.duplicate_product');
            }

            $seen[$id] = true;
        }
    }

    /** @param list<array<string, mixed>> $groups */
    private static function assertGroups(array $groups): void
    {
        if ($groups === []) {
            throw UserError::make('bundle.no_groups');
        }

        foreach ($groups as $group) {
            $name = trim((string) ($group['name'] ?? ''));

            if ($name === '') {
                throw UserError::make('bundle.group_name');
            }

            $options = $group['options'] ?? [];
            $min = (int) ($group['min'] ?? 0);
            $max = (int) ($group['max'] ?? 0);

            if ($min < 0 || $max < 1 || $min > $max || $max > count($options)) {
                throw UserError::make('bundle.group_range', ['name' => $name]);
            }

            $seen = [];

            foreach ($options as $option) {
                if ((float) ($option['surcharge'] ?? 0) < 0.0) {
                    throw UserError::make('bundle.negative_surcharge');
                }

                $id = (int) $option['product_id'];

                if (isset($seen[$id])) {
                    throw UserError::make('bundle.duplicate_product');
                }

                $seen[$id] = true;
            }
        }
    }

    /** Le confezioni intere che `$available` pezzi rendono con `$perPack` pezzi l'una. */
    private static function whole(float $available, float $perPack): float
    {
        if ($perPack <= 0.0) {
            return self::UNLIMITED;
        }

        return min(self::UNLIMITED, (float) floor($available / $perPack + 1e-9));
    }

    /**
     * Componenti e gruppi che la modalità lascia in vista.
     *
     * @param array{mode: string, components: list<array<string, mixed>>, groups: list<array<string, mixed>>} $bundle
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    private static function visible(array $bundle): array
    {
        return [
            $bundle['mode'] === 'choice' ? [] : $bundle['components'],
            $bundle['mode'] === 'fixed' ? [] : $bundle['groups'],
        ];
    }

    /**
     * Le righe prodotto di tutti i pezzi, per id.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function productsOf(array $components, array $groups): array
    {
        $ids = array_column($components, 'product_id');

        foreach ($groups as $group) {
            array_push($ids, ...array_column($group['options'], 'product_id'));
        }

        $products = [];

        foreach (array_unique(array_map('intval', $ids)) as $id) {
            $products[$id] = self::product($id) ?? ['id' => $id, 'active' => 'false', 'deleted' => 'true'];
        }

        return $products;
    }

    /** @return array<string, mixed>|null */
    private static function product(int $productId): ?array
    {
        $product = $productId > 0 ? Product::findById($productId) : null;

        return is_array($product) && isset($product['id']) ? $product : null;
    }

    /** @param array<string, mixed> $product */
    private static function sellable(array $product): bool
    {
        return ($product['active'] ?? 'false') === 'true' && ($product['deleted'] ?? 'false') !== 'true';
    }

    /** @throws UserError */
    private static function assertSellable(int $productId, string $name): void
    {
        $product = self::product($productId);

        if ($product === null || !self::sellable($product)) {
            throw UserError::make('bundle.component_unavailable', ['name' => $name !== '' ? $name : self::nameOf($productId)]);
        }
    }

    /** @return array<string, mixed>|null il modello del prodotto, se non è cancellato */
    private static function modelOf(int $productId): ?array
    {
        $product = self::product($productId);

        if ($product === null || ($product['deleted'] ?? 'false') === 'true') {
            return null;
        }

        $model = ProductModel::findById((int) ($product['product_model_id'] ?? 0));

        return is_array($model) && isset($model['id']) && ($model['deleted'] ?? 'false') !== 'true' ? $model : null;
    }

    private static function nameOf(int $productId): string
    {
        $product = self::product($productId);

        return $product === null ? '' : ProductNames::full($product, ProductNames::models([$product]));
    }

    /** @return list<array<string, mixed>> */
    private static function rows(mixed $result): array
    {
        if (!is_array($result) || $result === []) {
            return [];
        }

        return isset($result['id']) ? [$result] : array_values(array_filter($result, 'is_array'));
    }
}
