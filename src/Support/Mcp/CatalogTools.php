<?php

namespace Wonder\Plugin\Gestionale\Support\Mcp;

use Wonder\Plugin\Gestionale\Models\Catalog\Attribute;
use Wonder\Plugin\Gestionale\Models\Catalog\AttributeValue;
use Wonder\Plugin\Gestionale\Models\Catalog\Customization;
use Wonder\Plugin\Gestionale\Models\Catalog\CustomizationOption;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModelCustomization;
use Wonder\Plugin\Gestionale\Resources\Catalog\AttributeResource;
use Wonder\Plugin\Gestionale\Resources\Catalog\CustomizationResource;
use Wonder\Plugin\Gestionale\Resources\Catalog\ProductModelResource;
use Wonder\Plugin\Gestionale\Resources\Catalog\ProductResource;
use Wonder\Plugin\Gestionale\Support\Catalog\Attributes;
use Wonder\Plugin\Gestionale\Support\Catalog\Code;
use Wonder\Plugin\Gestionale\Support\Catalog\Customizations;
use Wonder\Plugin\Gestionale\Support\Catalog\ProductAttributes;
use Wonder\Plugin\Gestionale\Support\Catalog\RelatedProducts;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Plugin\Gestionale\Support\Positions;
use Wonder\Sql\Transaction;

/**
 * Gli strumenti MCP per attributi e personalizzazioni: crearli, modificarli
 * e assegnarli a un articolo o alle sue versioni.
 *
 * Le regole sono quelle delle schede del backend: l'attributo passa da
 * `AttributeResource::mutateRequestValues()`, la personalizzazione da
 * `Customizations::assertDefinition()`. Gli attributi a valori che fanno
 * nascere le opzioni in vendita (gli assi delle combinazioni) non si scrivono
 * da qui: come nella scheda, li scrive solo il generatore delle opzioni.
 */
final class CatalogTools
{
    use ToolSupport;

    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        $id = ['type' => 'integer', 'minimum' => 1];
        $flag = ['type' => 'boolean'];
        $money = ['type' => 'number', 'minimum' => 0];
        $value = [
            'type' => 'object',
            'properties' => (object) [
                'id' => $id,
                'label' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 150],
                'color' => ['type' => 'string', 'maxLength' => 20, 'description' => 'Codice esadecimale, solo per il tipo `color`.'],
            ],
            'required' => ['label'],
            'additionalProperties' => false,
        ];
        $option = [
            'type' => 'object',
            'properties' => (object) [
                'id' => $id,
                'label' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 150],
                'surcharge' => $money,
            ],
            'required' => ['label'],
            'additionalProperties' => false,
        ];

        return [
            'attributes_list' => [
                'description' => 'Elenca attributi e varianti del catalogo con tipo, uso (`level`), unità e valori. `level`: `model` = attributo della scheda tecnica, `variant` e `product` = variante (per testo e numero: scheda tecnica di ogni versione). Quale variante fa una pagina per ogni valore lo decide ogni articolo, non `level`: vedi `page_attribute_id` di `product_set_attributes`.',
                'schema' => self::schema([], []),
                'permission' => [AttributeResource::class, 'list'],
                'write' => false,
                'run' => self::attributesList(...),
            ],
            'attribute_create' => [
                'description' => 'Crea un attributo. Obbligatori nome, tipo e uso: se l\'utente non li ha detti, chiediglieli, non sceglierli tu. Tipi: '.implode(', ', array_keys(Attributes::TYPES)).'. Testo e numero ammettono solo gli usi `model` e `product`. `values` sono i valori di un tipo a elenco (select, button, color, pattern, icon).',
                'schema' => self::schema([
                    'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 150],
                    'type' => ['type' => 'string', 'enum' => array_keys(Attributes::TYPES)],
                    'level' => ['type' => 'string', 'enum' => array_keys(Attributes::LEVELS)],
                    'unit' => ['type' => 'string', 'maxLength' => 20, 'description' => 'Solo per testo e numero.'],
                    'group_name' => ['type' => 'string', 'maxLength' => 150],
                    'is_visible' => $flag,
                    'is_filterable' => $flag,
                    'values' => ['type' => 'array', 'items' => $value],
                ], ['name', 'type', 'level']),
                'permission' => [AttributeResource::class, 'store'],
                'write' => true,
                'run' => self::attributeCreate(...),
            ],
            'attribute_update' => [
                'description' => 'Modifica un attributo: nome, tipo, uso, unità, gruppo, visibilità, filtro. L\'uso di un attributo già assegnato non cambia. `values`: con `id` rinomina un valore, senza `id` ne aggiunge uno; i valori non elencati restano.',
                'schema' => self::schema([
                    'id' => $id,
                    'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 150],
                    'type' => ['type' => 'string', 'enum' => array_keys(Attributes::TYPES)],
                    'level' => ['type' => 'string', 'enum' => array_keys(Attributes::LEVELS)],
                    'unit' => ['type' => 'string', 'maxLength' => 20],
                    'group_name' => ['type' => 'string', 'maxLength' => 150],
                    'is_visible' => $flag,
                    'is_filterable' => $flag,
                    'values' => ['type' => 'array', 'items' => $value],
                ], ['id']),
                'permission' => [AttributeResource::class, 'update'],
                'write' => true,
                'run' => self::attributeUpdate(...),
            ],
            'product_set_attributes' => [
                'description' => 'Assegna attributi alla versione `product_id`: `model` va sull\'articolo, testo e numero con uso `product` sulla versione. `attributes` è un oggetto `{id attributo: valore}`: per un tipo a elenco l\'id di un valore o un elenco di id, per testo e numero il valore; stringa vuota o null toglie. Le varianti (attributi a elenco con uso `variant` o `product`) sono gli assi delle versioni in vendita e non si cambiano da qui: i loro valori stanno sotto `variant` quando l\'articolo fa una pagina per ogni valore di quella variante (`page_attribute_id`, 0 = nessuna), altrimenti sotto `product`.',
                'schema' => self::schema([
                    'product_id' => $id,
                    'attributes' => [
                        'type' => 'object',
                        'additionalProperties' => ['type' => ['string', 'number', 'integer', 'array', 'null'], 'items' => $id],
                    ],
                ], ['product_id', 'attributes']),
                'permission' => [ProductResource::class, 'update'],
                'write' => true,
                'run' => self::productSetAttributes(...),
            ],
            'customizations_list' => [
                'description' => 'Elenca le personalizzazioni (testo, numero, scelta) con sovrapprezzo, limiti, opzioni e numero di articoli che le usano.',
                'schema' => self::schema([], []),
                'permission' => [CustomizationResource::class, 'list'],
                'write' => false,
                'run' => self::customizationsList(...),
            ],
            'customization_create' => [
                'description' => 'Crea una personalizzazione che il cliente compila comprando. Obbligatori nome e tipo (`kind`); poi, secondo il tipo: `text` vuole `max_length` (1-1000), `number` vuole `decimals` (0-6), `choice` vuole almeno 2 `options`. Se l\'utente non ha detto questi valori, chiediglieli. Il sovrapprezzo di una scelta sta sulle opzioni.',
                'schema' => self::schema([
                    'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 150],
                    'kind' => ['type' => 'string', 'enum' => Customizations::KINDS],
                    'label' => ['type' => 'string', 'maxLength' => 150, 'description' => 'Etichetta per il cliente; vuota = il nome.'],
                    'help_text' => ['type' => 'string'],
                    'max_length' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 1000],
                    'decimals' => ['type' => 'integer', 'minimum' => 0, 'maximum' => Customizations::MAX_DECIMALS],
                    'surcharge' => $money,
                    'active' => $flag,
                    'options' => ['type' => 'array', 'items' => $option],
                ], ['name', 'kind']),
                'permission' => [CustomizationResource::class, 'store'],
                'write' => true,
                'run' => self::customizationCreate(...),
            ],
            'customization_update' => [
                'description' => 'Modifica una personalizzazione. Si passano solo i campi da cambiare; `options`, se c\'è, è l\'elenco completo delle opzioni (con `id` si aggiorna, senza si aggiunge, le altre si tolgono).',
                'schema' => self::schema([
                    'id' => $id,
                    'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 150],
                    'kind' => ['type' => 'string', 'enum' => Customizations::KINDS],
                    'label' => ['type' => 'string', 'maxLength' => 150],
                    'help_text' => ['type' => 'string'],
                    'max_length' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 1000],
                    'decimals' => ['type' => 'integer', 'minimum' => 0, 'maximum' => Customizations::MAX_DECIMALS],
                    'surcharge' => $money,
                    'active' => $flag,
                    'options' => ['type' => 'array', 'items' => $option],
                ], ['id']),
                'permission' => [CustomizationResource::class, 'update'],
                'write' => true,
                'run' => self::customizationUpdate(...),
            ],
            'product_set_customizations' => [
                'description' => 'Imposta le personalizzazioni dell\'articolo a cui appartiene la versione `product_id` (valgono per tutte le sue versioni), nell\'ordine dato. È l\'elenco completo: quelle non elencate si tolgono. `surcharge` sostituisce su questo articolo il sovrapprezzo della personalizzazione.',
                'schema' => self::schema([
                    'product_id' => $id,
                    'customizations' => ['type' => 'array', 'items' => [
                        'type' => 'object',
                        'properties' => (object) [
                            'customization_id' => $id,
                            'required' => $flag,
                            'surcharge' => ['type' => ['number', 'null'], 'minimum' => 0],
                        ],
                        'required' => ['customization_id'],
                        'additionalProperties' => false,
                    ]],
                ], ['product_id', 'customizations']),
                'permission' => [ProductResource::class, 'update'],
                'write' => true,
                'run' => self::productSetCustomizations(...),
            ],
            'product_set_related' => [
                'description' => 'Imposta gli articoli collegati all\'articolo a cui appartiene la versione `product_id`: `suggested` da comprare insieme («Abbinalo a»), `alternative` al suo posto («Potrebbe interessarti»). `product_model_ids` è l\'elenco completo di quel tipo, nell\'ordine dato: quelli non elencati si tolgono, un elenco vuoto li toglie tutti.',
                'schema' => self::schema([
                    'product_id' => $id,
                    'type' => ['type' => 'string', 'enum' => RelatedProducts::TYPES],
                    'product_model_ids' => ['type' => 'array', 'items' => $id],
                ], ['product_id', 'type', 'product_model_ids']),
                'permission' => [ProductModelResource::class, 'update'],
                'write' => true,
                'run' => self::productSetRelated(...),
            ],
        ];
    }

    // ── Attributi ────────────────────────────────────────────────────────

    private static function attributesList(): array
    {
        return ['result' => array_map(self::attribute(...), self::rows(Attribute::find(['deleted' => 'false'], null, 'position', 'ASC')))];
    }

    private static function attributeCreate(array $args): array
    {
        $values = self::attributeValues($args) + ['is_visible' => 'true', 'is_filterable' => 'true'];

        foreach (['name', 'type', 'level'] as $key) {
            self::string($args, $key);
        }

        $type = (string) $values['type'];
        $values = AttributeResource::mutateRequestValues($values, 'store');
        $values['code'] = Code::make(Attribute::class, Codes::ATTRIBUTE);

        if (!Attributes::usesUnit($type)) {
            $values['unit'] = '';
        }

        $id = Transaction::run(static function () use ($values, $args, $type): int {
            $saved = self::saved(Attribute::create($values));
            $id = (int) ($saved->insert_id ?? $saved->id ?? 0);
            self::writeValues($id, $type, (array) ($args['values'] ?? []));

            return $id;
        });
        $after = self::attribute(self::live(Attribute::class, $id, 'attribute_not_found'));

        return ['result' => $after, 'subject' => $id, 'after' => $after];
    }

    private static function attributeUpdate(array $args): array
    {
        $row = self::live(Attribute::class, self::int($args, 'id'), 'attribute_not_found');
        $id = (int) $row['id'];
        $values = self::attributeValues($args);

        if ($values === [] && !array_key_exists('values', $args)) {
            throw McpError::make('nothing_to_change');
        }

        $before = self::attribute($row);
        $type = (string) ($values['type'] ?? $row['type']);

        Transaction::run(static function () use ($values, $row, $id, $type, $args): void {
            if ($values !== []) {
                self::saved(Attribute::update(AttributeResource::mutateRequestValues($values, 'update', 'backend', $row), $id));
            }

            self::writeValues($id, $type, (array) ($args['values'] ?? []));
        });

        $after = self::attribute(self::live(Attribute::class, $id, 'attribute_not_found'));

        return ['result' => $after, 'subject' => $id, 'before' => $before, 'after' => $after];
    }

    /**
     * I campi dell'attributo arrivati, come li posta la scheda: l'uso di un
     * testo o di un numero arriva in `level_text`.
     *
     * @return array<string, mixed>
     */
    private static function attributeValues(array $args): array
    {
        $values = [];

        foreach (['name', 'group_name', 'unit'] as $key) {
            if (array_key_exists($key, $args)) {
                $values[$key] = trim((string) $args[$key]);
            }
        }

        if (isset($values['name']) && $values['name'] === '') {
            throw McpError::make('required', 0, ['field' => 'name']);
        }

        if (array_key_exists('type', $args)) {
            $values['type'] = (string) $args['type'];

            if (!array_key_exists($values['type'], Attributes::TYPES)) {
                throw McpError::make('attribute_type', 0, ['types' => implode(', ', array_keys(Attributes::TYPES))]);
            }
        }

        if (array_key_exists('level', $args)) {
            $values['level'] = $values['level_text'] = (string) $args['level'];
        }

        foreach (['is_visible', 'is_filterable'] as $key) {
            if (array_key_exists($key, $args)) {
                $values[$key] = self::flag($args[$key], $key);
            }
        }

        return $values;
    }

    /**
     * Scrive i valori dati: con `id` si rinomina quello dell'attributo, senza
     * se ne aggiunge uno in coda.
     *
     * @param list<mixed> $values
     */
    private static function writeValues(int $attributeId, string $type, array $values): void
    {
        if ($values === []) {
            return;
        }

        if (!Attributes::usesValues($type)) {
            throw McpError::make('attribute_no_values');
        }

        foreach ($values as $index => $value) {
            $label = is_array($value) ? trim((string) ($value['label'] ?? '')) : '';

            if ($label === '') {
                throw McpError::make('required', 0, ['field' => 'values['.$index.'].label']);
            }

            $fields = ['label' => $label];

            if (array_key_exists('color', $value)) {
                $fields['color'] = trim((string) $value['color']);
            }

            $valueId = (int) ($value['id'] ?? 0);

            if ($valueId > 0) {
                self::attributeValue($attributeId, $valueId);
                self::saved(AttributeValue::update($fields, $valueId));

                continue;
            }

            self::saved(AttributeValue::create($fields + [
                'attribute_id' => $attributeId,
                'position' => Positions::next(AttributeValue::$table, ['attribute_id' => $attributeId]),
            ]));
        }
    }

    private static function productSetAttributes(array $args): array
    {
        $product = self::productRow(self::int($args, 'product_id'));
        $input = $args['attributes'] ?? null;

        if (!is_array($input) || $input === [] || array_is_list($input)) {
            throw McpError::make('object', 0, ['field' => 'attributes']);
        }

        // Ogni attributo va sul suo livello, con l'id della riga di quel livello.
        $parents = [
            'model' => (int) $product['product_model_id'],
            'variant' => (int) ($product['product_variant_id'] ?? 0),
            'product' => (int) $product['id'],
        ];
        $byLevel = [];

        foreach ($input as $attributeId => $value) {
            $attribute = self::live(Attribute::class, (int) $attributeId, 'attribute_not_found');
            $type = (string) $attribute['type'];
            $level = (string) $attribute['level'];

            if (Attributes::usesValues($type) && Attributes::createsVersions($level)) {
                throw McpError::make('attribute_axis', 0, ['name' => (string) $attribute['name']]);
            }

            // Una versione senza opzione con foto non ha dove scrivere un `variant`.
            if (($parents[$level] ?? 0) <= 0) {
                throw McpError::make('attribute_level', 0, ['name' => (string) $attribute['name']]);
            }

            if (Attributes::usesValues($type)) {
                foreach (is_array($value) ? $value : ($value === null || $value === '' ? [] : [$value]) as $valueId) {
                    self::attributeValue((int) $attribute['id'], (int) $valueId);
                }
            } elseif (is_array($value)) {
                throw McpError::make('attribute_single', 0, ['name' => (string) $attribute['name']]);
            } elseif ($type === 'number' && $value !== null && $value !== '' && !is_numeric(str_replace(',', '.', (string) $value))) {
                throw McpError::make('attribute_number', 0, ['name' => (string) $attribute['name']]);
            }

            $byLevel[$level]['attributes'][] = $attribute;
            $byLevel[$level]['input'][(int) $attribute['id']] = $value;
        }

        $before = self::productAttributes($parents);

        Transaction::run(static function () use ($byLevel, $parents): void {
            foreach ($byLevel as $level => $chosen) {
                ProductAttributes::save($level, $parents[$level], $chosen['attributes'], $chosen['input']);
            }
        });

        $after = self::productAttributes($parents);

        // La variante con una pagina per valore è una scelta dell'articolo.
        $pagina = ProductModelResource::variantAttributeId($parents['model']);

        return ['result' => ['product_id' => (int) $product['id'], 'page_attribute_id' => $pagina, 'attributes' => $after], 'subject' => (int) $product['id'], 'before' => $before, 'after' => $after];
    }

    /** @return array<string, mixed> */
    private static function attributeValue(int $attributeId, int $valueId): array
    {
        $value = $valueId > 0 ? AttributeValue::findById($valueId) : null;

        if (!is_array($value) || $value === [] || (string) ($value['deleted'] ?? 'false') === 'true' || (int) ($value['attribute_id'] ?? 0) !== $attributeId) {
            throw McpError::make('value_not_found', 0, ['id' => $valueId, 'attribute' => $attributeId]);
        }

        return $value;
    }

    /** @return array<string, mixed> */
    private static function attribute(array $row): array
    {
        $id = (int) $row['id'];

        return [
            'id' => $id,
            'name' => (string) ($row['name'] ?? ''),
            'slug' => (string) ($row['slug'] ?? ''),
            'type' => (string) ($row['type'] ?? ''),
            'level' => (string) ($row['level'] ?? ''),
            'unit' => (string) ($row['unit'] ?? ''),
            'group_name' => (string) ($row['group_name'] ?? ''),
            'is_visible' => (string) ($row['is_visible'] ?? 'true') === 'true',
            'is_filterable' => (string) ($row['is_filterable'] ?? 'true') === 'true',
            // Un asse delle opzioni in vendita: lo scrive solo il generatore.
            'sales_axis' => Attributes::usesValues((string) ($row['type'] ?? '')) && Attributes::createsVersions((string) ($row['level'] ?? '')),
            'values' => array_map(static fn (array $value): array => [
                'id' => (int) $value['id'],
                'label' => (string) ($value['label'] ?? ''),
                'color' => (string) ($value['color'] ?? ''),
            ], self::rows(AttributeValue::find(['attribute_id' => $id, 'deleted' => 'false'], null, 'position', 'ASC'))),
        ];
    }

    /**
     * Gli attributi scritti su articolo, opzione e versione, per uso.
     *
     * @param array<string, int> $parents
     * @return array<string, list<array<string, mixed>>>
     */
    private static function productAttributes(array $parents): array
    {
        $out = [];

        foreach ($parents as $level => $parentId) {
            $out[$level] = [];

            if ($parentId <= 0) {
                continue;
            }

            foreach (ProductAttributes::rows($level, $parentId) as $attributeId => $links) {
                $out[$level][] = [
                    'attribute_id' => $attributeId,
                    'value_ids' => array_values(array_filter(array_map(static fn (array $link): int => (int) ($link['attribute_value_id'] ?? 0), $links))),
                    'text' => (string) ($links[0]['value_text'] ?? ''),
                    'number' => isset($links[0]['value_number']) && $links[0]['value_number'] !== '' ? (float) $links[0]['value_number'] : null,
                ];
            }
        }

        return $out;
    }

    // ── Personalizzazioni ────────────────────────────────────────────────

    private static function customizationsList(): array
    {
        return ['result' => array_map(self::customization(...), self::rows(Customization::find(['deleted' => 'false'], null, 'position', 'ASC')))];
    }

    private static function customizationCreate(array $args): array
    {
        self::customizationsActive();
        self::string($args, 'name');
        self::string($args, 'kind');

        [$values, $options] = self::customizationValues($args, ['active' => 'true', 'surcharge' => 0, 'label' => '', 'help_text' => '']);
        $values['code'] = Code::make(Customization::class, Codes::CUSTOMIZATION);
        $values['position'] = Positions::next(Customization::$table);

        $id = Transaction::run(static function () use ($values, $options): int {
            $saved = self::saved(Customization::create($values));
            $id = (int) ($saved->insert_id ?? $saved->id ?? 0);
            self::writeOptions($id, $options ?? []);

            return $id;
        });
        $after = self::customization(self::live(Customization::class, $id, 'customization_not_found'));

        return ['result' => $after, 'subject' => $id, 'after' => $after];
    }

    private static function customizationUpdate(array $args): array
    {
        self::customizationsActive();
        $row = self::live(Customization::class, self::int($args, 'id'), 'customization_not_found');
        $id = (int) $row['id'];

        if (array_diff(array_keys($args), ['id']) === []) {
            throw McpError::make('nothing_to_change');
        }

        $before = self::customization($row);
        [$values, $options] = self::customizationValues($args, $row);

        Transaction::run(static function () use ($values, $options, $id): void {
            if ($values !== []) {
                self::saved(Customization::update($values, $id));
            }

            if ($options !== null) {
                self::writeOptions($id, $options);
            }
        });

        $after = self::customization(self::live(Customization::class, $id, 'customization_not_found'));

        return ['result' => $after, 'subject' => $id, 'before' => $before, 'after' => $after];
    }

    /**
     * I campi da scrivere e le opzioni (null = non si toccano), controllati
     * con le regole della scheda sui valori che risulteranno.
     *
     * @param array<string, mixed> $current i valori di partenza
     * @return array{0: array<string, mixed>, 1: list<array<string, mixed>>|null}
     */
    private static function customizationValues(array $args, array $current): array
    {
        $values = [];

        foreach (['name', 'label', 'help_text', 'kind'] as $key) {
            if (array_key_exists($key, $args)) {
                $values[$key] = trim((string) $args[$key]);
            }
        }

        if (isset($values['name']) && $values['name'] === '') {
            throw McpError::make('required', 0, ['field' => 'name']);
        }

        foreach (['max_length', 'decimals'] as $key) {
            if (array_key_exists($key, $args)) {
                $values[$key] = $args[$key];
            }
        }

        if (array_key_exists('surcharge', $args)) {
            $values['surcharge'] = self::price($args['surcharge'], 'surcharge');
        }

        if (array_key_exists('active', $args)) {
            $values['active'] = self::flag($args['active'], 'active');
        }

        $merged = $values + $current;
        $kind = (string) ($merged['kind'] ?? '');
        $options = null;

        if (array_key_exists('options', $args)) {
            $options = [];

            foreach ((array) $args['options'] as $index => $option) {
                if (!is_array($option) || trim((string) ($option['label'] ?? '')) === '') {
                    throw McpError::make('required', 0, ['field' => 'options['.$index.'].label']);
                }

                $options[] = [
                    'id' => (int) ($option['id'] ?? 0),
                    'label' => trim((string) $option['label']),
                    'surcharge' => self::price($option['surcharge'] ?? 0, 'options['.$index.'].surcharge'),
                ];
            }
        }

        if ($kind !== 'choice' && $options !== null && $options !== []) {
            throw McpError::make('customization_options');
        }

        // Quello che il tipo chiede deve esserci: si dice tutto quello che manca.
        $missing = match ($kind) {
            'text' => (int) ($merged['max_length'] ?? 0) > 0 ? [] : ['max_length'],
            'number' => array_key_exists('decimals', $values) || (string) ($current['kind'] ?? '') === 'number' ? [] : ['decimals'],
            'choice' => $options !== null || (string) ($current['kind'] ?? '') === 'choice' ? [] : ['options'],
            default => [],
        };

        if ($missing !== []) {
            throw McpError::make('missing_fields', 0, ['fields' => implode(', ', $missing)]);
        }

        Customizations::assertDefinition($merged, $options ?? self::storedOptions((int) ($current['id'] ?? 0)));

        // Come la scheda: una scelta non ha sovrapprezzo suo, e quello che
        // non vale per il tipo si azzera. Le opzioni restano solo a una scelta.
        if ($values !== []) {
            $values['max_length'] = $kind === 'text' ? (int) $merged['max_length'] : 0;
            $values['decimals'] = $kind === 'number' ? (int) ($merged['decimals'] ?? 0) : 0;
            $values['surcharge'] = $kind === 'choice' ? 0.0 : (float) ($merged['surcharge'] ?? 0);
        }

        if ($kind !== 'choice' && (string) ($current['kind'] ?? '') === 'choice') {
            $options = [];
        }

        return [$values, $options];
    }

    /** @return list<array<string, mixed>> */
    private static function storedOptions(int $customizationId): array
    {
        return $customizationId > 0
            ? self::rows(CustomizationOption::find(['customization_id' => $customizationId, 'deleted' => 'false'], null, 'position', 'ASC'))
            : [];
    }

    /**
     * Allinea le opzioni all'elenco dato: con `id` si aggiornano, senza si
     * aggiungono, le altre si cancellano (la riga d'ordine tiene la sua copia).
     *
     * @param list<array<string, mixed>> $options
     */
    private static function writeOptions(int $customizationId, array $options): void
    {
        $existing = [];

        foreach (self::storedOptions($customizationId) as $row) {
            $existing[(int) $row['id']] = $row;
        }

        foreach ($options as $option) {
            if ($option['id'] > 0 && !isset($existing[$option['id']])) {
                throw McpError::make('option_not_found', 0, ['id' => $option['id']]);
            }
        }

        $kept = array_filter(array_column($options, 'id'));

        foreach (array_keys($existing) as $optionId) {
            if (!in_array($optionId, $kept, true)) {
                CustomizationOption::delete($optionId);
            }
        }

        foreach (array_values($options) as $index => $option) {
            $values = ['customization_id' => $customizationId, 'label' => $option['label'], 'surcharge' => $option['surcharge'], 'position' => $index + 1];

            self::saved($option['id'] > 0
                ? CustomizationOption::update($values, $option['id'])
                : CustomizationOption::create($values));
        }
    }

    private static function productSetRelated(array $args): array
    {
        $product = self::productRow(self::int($args, 'product_id'));
        $modelId = (int) $product['product_model_id'];
        $type = (string) ($args['type'] ?? '');
        $ids = array_map('intval', (array) ($args['product_model_ids'] ?? []));

        $before = Tools::modelRelated($modelId);
        Transaction::run(static fn () => RelatedProducts::replace($modelId, $type, $ids));
        $after = Tools::modelRelated($modelId);

        return ['result' => ['product_model_id' => $modelId, 'related' => $after], 'subject' => (int) $product['id'], 'before' => $before, 'after' => $after];
    }

    private static function productSetCustomizations(array $args): array
    {
        self::customizationsActive();
        $product = self::productRow(self::int($args, 'product_id'));
        $modelId = (int) $product['product_model_id'];
        $wanted = [];

        foreach ((array) ($args['customizations'] ?? []) as $index => $entry) {
            $customizationId = is_array($entry) ? (int) ($entry['customization_id'] ?? 0) : 0;
            self::live(Customization::class, $customizationId, 'customization_not_found');

            $surcharge = is_array($entry) && array_key_exists('surcharge', $entry) && $entry['surcharge'] !== null
                ? self::price($entry['surcharge'], 'customizations['.$index.'].surcharge')
                : null;

            $wanted[$customizationId] = [
                'is_required' => is_array($entry) && array_key_exists('required', $entry) ? self::flag($entry['required'], 'customizations['.$index.'].required') : 'false',
                'surcharge' => $surcharge,
            ];
        }

        $before = Customizations::forModel($modelId);

        Transaction::run(static function () use ($modelId, $wanted): void {
            $existing = [];

            foreach (self::rows(ProductModelCustomization::find(['product_model_id' => $modelId, 'deleted' => 'false'])) as $link) {
                $existing[(int) $link['customization_id']] = $link;
            }

            foreach ($existing as $customizationId => $link) {
                if (!isset($wanted[$customizationId])) {
                    ProductModelCustomization::delete((int) $link['id']);
                }
            }

            $position = 0;

            foreach ($wanted as $customizationId => $entry) {
                $values = $entry + ['product_model_id' => $modelId, 'customization_id' => $customizationId, 'position' => ++$position];

                self::saved(isset($existing[$customizationId])
                    ? ProductModelCustomization::update($values, (int) $existing[$customizationId]['id'])
                    : ProductModelCustomization::create($values));
            }
        });

        $after = Customizations::forModel($modelId);

        return ['result' => ['product_model_id' => $modelId, 'customizations' => $after], 'subject' => (int) $product['id'], 'before' => $before, 'after' => $after];
    }

    /** Con la funzione spenta le personalizzazioni non si vedono nel backend: nemmeno qui. */
    private static function customizationsActive(): void
    {
        if (!CustomizationResource::featureActive()) {
            throw McpError::make('feature_off', 0, ['feature' => 'customizations']);
        }
    }

    /** @return array<string, mixed> */
    private static function customization(array $row): array
    {
        $id = (int) $row['id'];

        return [
            'id' => $id,
            'code' => (string) ($row['code'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'label' => (string) ($row['label'] ?? ''),
            'help_text' => (string) ($row['help_text'] ?? ''),
            'kind' => (string) ($row['kind'] ?? ''),
            'max_length' => (int) ($row['max_length'] ?? 0),
            'decimals' => (int) ($row['decimals'] ?? 0),
            'surcharge' => (float) ($row['surcharge'] ?? 0),
            'active' => (string) ($row['active'] ?? 'true') === 'true',
            'used_by' => CustomizationResource::usage($id),
            'options' => array_map(static fn (array $option): array => [
                'id' => (int) $option['id'],
                'label' => (string) ($option['label'] ?? ''),
                'surcharge' => (float) ($option['surcharge'] ?? 0),
            ], self::storedOptions($id)),
        ];
    }
}
