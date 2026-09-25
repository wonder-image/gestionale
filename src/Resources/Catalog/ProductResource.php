<?php

namespace Wonder\Plugin\Gestionale\Resources\Catalog;

use Wonder\App\ResourceSchema\ApiSchema;
use Wonder\App\ResourceSchema\FormField;
use Wonder\App\ResourceSchema\Input;
use Wonder\App\ResourceSchema\NavigationSchema;
use Wonder\App\ResourceSchema\PageSchema;
use Wonder\App\ResourceSchema\PermissionSchema;
use Wonder\App\ResourceSchema\RepeaterColumn;
use Wonder\App\ResourceSchema\RepeaterRelation;
use Wonder\App\ResourceSchema\TableColumn;
use Wonder\App\Support\Repeater;
use Wonder\Elements\Components\Card;
use Wonder\Elements\Components\Container;
use Wonder\Elements\Components\RichText;
use Wonder\Elements\Components\SectionTitle;
use Wonder\Elements\Form\Form;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductSupplier;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductVariant;
use Wonder\Plugin\Gestionale\Models\Stock\StockMovement;
use Wonder\Plugin\Gestionale\Resources\Stock\StockAdjustmentResource;
use Wonder\Plugin\Gestionale\Resources\Stock\StockMovementResource;
use Wonder\Plugin\Gestionale\Support\Catalog\Attributes;
use Wonder\Plugin\Gestionale\Support\Catalog\Ean;
use Wonder\Plugin\Gestionale\Support\Catalog\ProductAttributes;
use Wonder\Plugin\Gestionale\Support\Catalog\Sku;
use Wonder\Plugin\Gestionale\Support\Contacts\Contacts;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Numbers;
use Wonder\Plugin\Gestionale\Support\Purchasing\ProductSuppliers;
use Wonder\Plugin\Gestionale\Support\Stock\Alerts;
use Wonder\Plugin\Gestionale\Support\Stock\Levels;
use Wonder\Plugin\Gestionale\Support\Stock\Reasons;
use Wonder\Plugin\Gestionale\Support\Stock\StockHistory;

/**
 * "Opzioni in vendita": l'elenco piatto di quello che si vende davvero.
 *
 * Serve a **trovare** — uno SKU, un EAN, un prezzo da correggere — non a
 * creare: un'opzione nasce nella griglia della scheda del prodotto, dove si
 * vede insieme alle sorelle. Per questo la pagina non ha il pulsante
 * "Aggiungi".
 *
 * La scheda della singola opzione tiene quello che nella griglia non
 * entrerebbe: MPN, misure proprie, gli attributi di livello `product` e, con
 * gli acquisti, l'elenco intero dei suoi fornitori.
 *
 * Estende la Resource dei prodotti per riusarne le letture del catalogo —
 * attributi, valori, campi degli attributi — e ne riscrive tutto il resto:
 * qui non nasce nessuno scheletro e non si elimina nessun prodotto.
 *
 * Non è `final`: i test la estendono con una classe anonima.
 */
class ProductResource extends ProductModelResource
{
    public static string $model = Product::class;
    public static string $orderColumn = 'sku';
    public static string $orderDirection = 'ASC';
    public static string $docsPage = 'catalogo/catalogo-prodotti';

    public static function path(): string
    {
        return 'app/gestionale/versioni';
    }

    public static function icon(): string
    {
        return 'bi-upc-scan';
    }

    public static function titleLabel(): string
    {
        return 'Opzioni in vendita';
    }

    public static function textSchema(): array
    {
        return [
            'label' => 'opzione',
            'plural_label' => 'opzioni',
            'last' => 'ultime',
            'all' => 'tutte',
            'article' => 'le',
            'full' => 'attiva',
            'empty' => 'ferma',
            'this' => 'questa',
        ];
    }

    public static function labelSchema(): array
    {
        return [
            'sku' => 'SKU',
            'ean' => 'EAN',
            'mpn' => 'Codice del produttore',
            'price' => 'Prezzo',
            'sale_price' => 'Prezzo scontato',
            'active' => 'Stato',
            'name' => 'Opzione',
            'product_model_id' => 'Prodotto',
        ];
    }

    public static function formSchema(): array
    {
        $fields = [
            FormField::key('sku')->text()->label('SKU'),
            FormField::key('ean')->text()->label('EAN'),
            FormField::key('mpn')->text()->label('Codice del produttore'),
            FormField::key('price')->number()->decimal(2)->label('Prezzo'),
            FormField::key('sale_price')->number()->decimal(2)->label('Prezzo scontato'),
            FormField::key('active')
                ->select(['true' => 'Attiva', 'false' => 'Ferma'])
                ->value('true')
                ->label('Stato')
                ->required(),
            FormField::key('weight')->number()->decimal(3)->label('Peso (kg)'),
            FormField::key('length')->number()->decimal(2)->label('Lunghezza (cm)'),
            FormField::key('width')->number()->decimal(2)->label('Larghezza (cm)'),
            FormField::key('height')->number()->decimal(2)->label('Altezza (cm)'),
        ];

        if (Gestionale::feature('low_stock_alerts')) {
            $fields[] = FormField::key('min_stock_quantity')->number()->decimal(3)->label('Scorta minima');
        }

        if (Gestionale::feature('purchasing')) {
            $fields[] = static::supplierCardField();
        }

        foreach (Attributes::byLevel(static::attributes(), 'product') as $attribute) {
            $fields[] = static::attributeField($attribute);
        }

        return $fields;
    }

    /**
     * I fornitori dell'opzione, uno per riga.
     *
     * Le righe sono quelle di `gst_product_suppliers` e le salva il
     * repeater: prima scrive, poi toglie quelle sparite, davvero e non nel
     * cestino. Per questo i doppioni li ferma la scheda e non un indice unico.
     */
    protected static function supplierCardField(): Input
    {
        $productId = static::currentId() ?? 0;

        return FormField::key('suppliers')
            ->repeater([
                RepeaterColumn::key('id')->hidden(),
                // I fornitori attivi, più quelli già legati a questa opzione
                // anche se non lo sono più: senza, la tendina posterebbe un
                // valore vuoto e staccherebbe il fornitore.
                RepeaterColumn::key('supplier_id')
                    ->select(['' => '—'] + static::supplierCardChoices(static::supplierCardLinkedIds($productId)))
                    ->label('Fornitore')
                    ->columnSpan(4),
                RepeaterColumn::key('supplier_sku')->text()->maxLength(ProductSuppliers::SKU_MAX_LENGTH)->label('Codice fornitore')->columnSpan(3),
                // Due decimali qui, quattro nella tabella: finché la casella
                // non cambia resta quello salvato.
                RepeaterColumn::key('cost')->price()->decimal(2)->label('Costo d\'acquisto')->columnSpan(3),
                // Il «No» per primo: una riga nuova nasce così e non ruba il
                // preferito a nessuno.
                RepeaterColumn::key('is_preferred')
                    ->select(['false' => 'No', 'true' => 'Sì'])
                    ->value('false')
                    ->label('Preferito')
                    ->columnSpan(2),
            ])
            ->relation(
                RepeaterRelation::make(ProductSupplier::$table, 'product_id')
                    ->model(ProductSupplier::class)
                    ->positionKey('position')
                    ->softDelete(false)
            )
            ->nested()
            ->repeaterSortable()
            ->repeaterAddLabel('Aggiungi fornitore')
            ->repeaterDeleteTitle('Togli fornitore')
            ->repeaterDeleteText('Questa opzione non si comprerà più da questo fornitore: il suo codice e il suo costo se ne vanno al salvataggio.')
            ->repeaterDeleteCancelLabel('Annulla')
            ->repeaterDeleteConfirmLabel('Togli')
            ->repeaterDeleteConfirmClass('btn btn-danger')
            // Il titolo lo dà il riquadro.
            ->label('');
    }

    /**
     * I fornitori da proporre, id => nome.
     *
     * @param list<int> $keepIds quelli già legati all'opzione
     * @return array<int, string>
     */
    protected static function supplierCardChoices(array $keepIds): array
    {
        return Contacts::supplierOptions($keepIds);
    }

    /** @return list<int> i fornitori già legati all'opzione */
    protected static function supplierCardLinkedIds(int $productId): array
    {
        if ($productId <= 0) {
            return [];
        }

        return array_column(ProductSuppliers::linksFor([$productId])[$productId] ?? [], 'supplier_id');
    }

    /**
     * Fra le righe postate, quelle che nel database sono già le preferite.
     *
     * @param list<string> $rowIds
     * @return list<string>
     */
    protected static function supplierCardStoredPreferred(array $rowIds): array
    {
        $rowIds = array_values(array_filter(array_map('intval', $rowIds), static fn (int $id): bool => $id > 0));

        if ($rowIds === []) {
            return [];
        }

        return array_map(
            static fn (array $row): string => (string) $row['id'],
            static::rowsOf(ProductSupplier::class, ['id' => $rowIds, 'is_preferred' => 'true'])
        );
    }

    public static function formLayoutSchema(): ?Form
    {
        $cards = [
            (new Card)->components([
                SectionTitle::make(static::currentTitle())
                    ->tooltip('Questa opzione nasce nella scheda del prodotto: nome, descrizioni e categorie si cambiano di là, e il nome della riga segue gli attributi che la compongono. Qui c\'è quello che vale solo per lei.')
                    ->columnSpan(12),
                static::getInput('sku')->columnSpan(4),
                static::getInput('ean')->columnSpan(4),
                static::getInput('mpn')->columnSpan(4),
                static::getInput('price')->columnSpan(4),
                static::getInput('sale_price')->columnSpan(4),
                static::getInput('active')->columnSpan(4),
            ])->columns(12)->columnSpan(12),
        ];

        $attributeInputs = [];

        foreach (Attributes::byLevel(static::attributes(), 'product') as $attribute) {
            $attributeInputs[] = static::getInput('attribute_'.(int) $attribute['id'])->columnSpan(4);
        }

        if ($attributeInputs !== []) {
            $cards[] = (new Card)->components([
                SectionTitle::make('Attributi')
                    ->tooltip('Gli attributi che cambiano da un\'opzione all\'altra — taglia, misura, gusto — e non quelli che descrivono tutto l\'articolo.')
                    ->columnSpan(12),
                ...$attributeInputs,
            ])->columns(12)->columnSpan(12);
        }

        $cards[] = (new Card)->components([
            SectionTitle::make('Misure')
                ->tooltip('Lasciando vuoto valgono peso e misure dell\'articolo.')
                ->columnSpan(12),
            static::getInput('weight')->columnSpan(3),
            static::getInput('length')->columnSpan(3),
            static::getInput('width')->columnSpan(3),
            static::getInput('height')->columnSpan(3),
        ])->columns(12)->columnSpan(12);

        $soglia = Gestionale::feature('low_stock_alerts');

        $cards[] = (new Card)->components([
            SectionTitle::make(static::stockCardTitle())
                ->tooltip('Qui la giacenza si legge. Si scrive nella riga della griglia delle opzioni in vendita, oppure con una rettifica quando serve lasciare una causale e una nota.'
                    .($soglia ? ' La scorta minima è la soglia sotto cui arriva l\'avviso: vale sul disponibile di tutte le sedi, e con zero non arriva niente.' : ''))
                ->columnSpan(12),
            ...($soglia ? [static::getInput('min_stock_quantity')->columnSpan(4)] : []),
            RichText::make(static::stockSummary())->columnSpan(12),
            RichText::make(static::stockHistoryTable())->columnSpan(12),
        ])->columns(12)->columnSpan(12);

        if (Gestionale::feature('purchasing')) {
            $cards[] = (new Card)->components([
                SectionTitle::make('Fornitori')
                    ->tooltip('Da chi compri questa opzione, con il suo codice e a quanto. Il preferito è quello che si vede nella scheda del prodotto e che useranno gli ordini ai fornitori. Un costo lasciato vuoto vuol dire «non lo so», non zero.')
                    ->columnSpan(12),
                ...(static::supplierCardChoices(static::supplierCardLinkedIds(static::currentId() ?? 0)) === []
                    ? [RichText::make('<p class="text-muted mb-0">Nessun fornitore da proporre: aggiungilo da Anagrafiche → Fornitori.</p>')->columnSpan(12)]
                    : []),
                static::getInput('suppliers')->columnSpan(12),
            ])->columns(12)->columnSpan(12);
        }

        return (new Form)->components([
            (new Container)->components($cards)->columns(12)->columnSpan(12),
        ]);
    }

    public static function stockCardTitle(): string
    {
        return 'Magazzino';
    }

    /** Quanti pezzi, con il link alla rettifica. */
    protected static function stockSummary(): string
    {
        $productId = static::currentId() ?? 0;

        if ($productId <= 0) {
            return '';
        }

        $levels = Levels::of($productId);
        $parts = ['<b>Giacenza:</b> '.static::escape(static::plainNumber($levels['quantity'])).' pezzi'];

        if (Gestionale::feature('orders')) {
            $parts[] = 'impegnati '.static::escape(static::plainNumber($levels['reserved']));
            $parts[] = 'disponibili '.static::escape(static::plainNumber($levels['available']));
        }

        $parts[] = '<a href="'.static::escape(StockAdjustmentResource::urlFor($productId)).'">Rettifica</a>';

        return implode(' · ', $parts);
    }

    /** Gli ultimi dieci movimenti di questa opzione. */
    protected static function stockHistoryTable(): string
    {
        $productId = static::currentId() ?? 0;
        $rows = $productId > 0 ? StockHistory::latest($productId, 10) : [];

        if ($rows === []) {
            return '<p class="text-muted mb-0">Nessun movimento: la giacenza di questa opzione non è mai cambiata.</p>';
        }

        $html = '<table class="table table-sm mb-2"><thead><tr>'
            .'<th>Quando</th><th>Tipo</th><th>Causale</th><th>Pezzi</th><th>Dopo</th><th>Nota</th>'
            .'</tr></thead><tbody>';

        foreach ($rows as $row) {
            $quantity = (float) ($row['quantity'] ?? 0);
            $html .= '<tr>'
                .'<td>'.static::escape((string) ($row['creation'] ?? '')).'</td>'
                .'<td>'.static::escape(StockMovement::typeLabels()[(string) ($row['type'] ?? '')] ?? '').'</td>'
                .'<td>'.static::escape(Reasons::label((string) ($row['reason'] ?? ''))).'</td>'
                .'<td>'.static::escape(($quantity > 0 ? '+' : '').static::plainNumber($quantity)).'</td>'
                .'<td>'.static::escape(static::plainNumber((float) ($row['quantity_after'] ?? 0))).'</td>'
                .'<td>'.static::escape((string) ($row['note'] ?? '')).'</td>'
                .'</tr>';
        }

        $html .= '</tbody></table><a href="'
            .static::escape(StockMovementResource::listUrlFor($productId))
            .'">Vedi tutti i movimenti</a>';

        return $html;
    }

    public static function tableSchema(): array
    {
        return [
            TableColumn::key('name')
                ->text()
                ->link('edit')
                // Le righe nate prima che il nome esistesse mostrano lo SKU:
                // meglio un codice di una casella vuota.
                ->formatter(static fn (array $row): string => static::escape(
                    trim((string) ($row['name'] ?? '')) !== ''
                        ? (string) $row['name']
                        : (string) ($row['sku'] ?? '')
                )),
            TableColumn::key('product_model_id')
                ->text()
                ->formatter(static fn (array $row): string => static::escape(
                    static::modelNames()[(int) ($row['product_model_id'] ?? 0)] ?? '—'
                )),
            TableColumn::key('sku')->text()->size('little'),
            TableColumn::key('ean')->text()->size('little'),
            TableColumn::key('price')->text()->size('little'),
            // "Attiva" e "Ferma": qui non si parla di vetrina ma di magazzino.
            TableColumn::key('active')
                ->booleanBadge()
                ->badgeOn('Attiva', 'bi-check-circle', 'success')
                ->badgeOff('Ferma', 'bi-pause-circle', 'secondary')
                ->size('little'),
            TableColumn::key('actions')->button()->actions(['edit']),
        ];
    }

    public static function pageSchema(): PageSchema
    {
        // Niente creazione: un'opzione nasce nella scheda del suo prodotto.
        return parent::pageSchema()
            ->only(['list', 'edit', 'update'])
            ->titles([
                'list' => 'Opzioni in vendita',
                'edit' => 'Modifica opzione',
            ]);
    }

    public static function permissionSchema(): PermissionSchema
    {
        return PermissionSchema::for(static::class)->backendCrud(['admin', 'administrator']);
    }

    public static function apiSchema(): ApiSchema
    {
        return ApiSchema::for(static::class)->enabled(false);
    }

    /**
     * Fuori dal menu: un'opzione si apre dalla scheda del suo prodotto.
     *
     * Un elenco piatto di quello che si vende avrà senso con le giacenze, e
     * allora sarà quello del magazzino, con le sue colonne.
     */
    public static function navigationSchema(): NavigationSchema
    {
        return NavigationSchema::for(static::class)
            ->inSection('catalogo')
            ->title('Opzioni in vendita')
            ->order(39)
            ->authority(['admin', 'administrator'])
            ->enabled(false);
    }

    /**
     * L'indirizzo dell'elenco filtrato su un articolo.
     *
     * Lo usa il pulsante nella scheda del prodotto. Gli indirizzi delle
     * Resource nascono da rotte con un nome, non da percorsi scritti a mano:
     * `__r()` è la strada giusta, il percorso è solo il ripiego per quando il
     * framework non è avviato (test, comandi).
     */
    public static function listUrlFor(int $modelId): string
    {
        $base = '/backend/'.static::path();

        if (function_exists('__r')) {
            try {
                $named = (string) __r('backend.resource.'.static::slug().'.list');
                $base = $named !== '' ? $named : $base;
            } catch (\Throwable) {
                // Rotta non registrata: resta il percorso.
            }
        }

        return $base.'?prodotto='.$modelId;
    }

    /**
     * L'elenco, filtrato sull'articolo quando l'indirizzo lo dice.
     *
     * Il core rivaluta `querySchema()` a ogni richiesta, quindi leggere la
     * query string qui è sicuro: è la stessa strada di `currentId()`.
     */
    public static function querySchema(): array
    {
        $schema = parent::querySchema();
        $modelId = (int) ($_GET['prodotto'] ?? 0);

        if ($modelId > 0) {
            $schema['condition'] = array_merge(
                (array) ($schema['condition'] ?? []),
                ['product_model_id' => $modelId]
            );
        }

        return $schema;
    }

    /** SKU ed EAN unici, e i numeri nella forma che il database accetta. */
    public static function mutateRequestValues(
        array $values,
        string $action,
        string $context = 'backend',
        ?array $oldValues = null
    ): array {
        $id = (int) ($oldValues['id'] ?? 0);
        $sku = trim((string) ($values['sku'] ?? ''));
        $ean = trim((string) ($values['ean'] ?? ''));

        if ($sku !== '' && !Sku::isFree(Product::class, $sku, $id > 0 ? $id : null)) {
            throw UserError::make('product.sku_taken');
        }

        if (!Ean::isValid($ean)) {
            throw UserError::make('product.ean_invalid');
        }

        if (!Ean::isFree($ean, $id > 0 ? $id : null)) {
            throw UserError::make('product.ean_taken');
        }

        foreach (['price', 'sale_price', 'weight', 'length', 'width', 'height'] as $key) {
            if (array_key_exists($key, $values)) {
                $values[$key] = Numbers::fromForm($values[$key]);
            }
        }

        foreach (array_keys($values) as $key) {
            if (str_starts_with((string) $key, 'attribute_')) {
                unset($values[$key]);
            }
        }

        // Senza la funzionalità la colonna non si scrive: un form aperto prima
        // di bloccarla non deve azzerare la soglia.
        if (!Gestionale::feature('low_stock_alerts')) {
            unset($values['min_stock_quantity']);
        } elseif (array_key_exists('min_stock_quantity', $values)) {
            $values['min_stock_quantity'] = static::minStockValue($values['min_stock_quantity']);
        }

        // Le righe dei fornitori le salva il repeater dopo l'opzione, e il
        // core le ha già tolte da `$values`: si controllano su quello che è
        // arrivato, prima che si scriva qualcosa.
        if (Gestionale::feature('purchasing')) {
            $rows = Repeater::rowsFromRequest('suppliers', $_POST);

            if ($rows !== []) {
                $choices = static::supplierCardChoices(static::supplierCardLinkedIds($id));
                ProductSuppliers::assertValid($rows, array_keys($choices), $choices);
            }
        }

        return $values;
    }

    /**
     * Le righe dei fornitori come si salvano: senza quelle vuote, un
     * fornitore per riga e un preferito solo.
     *
     * Svuotare il fornitore, il codice e il costo di una riga la stacca: non
     * arriva al repeater, che la toglie.
     */
    public static function prepareRepeaterRows(
        string $inputName,
        array $rows,
        string $action = 'store',
        string $context = 'backend'
    ): array {
        if ($inputName !== 'suppliers') {
            return parent::prepareRepeaterRows($inputName, $rows, $action, $context);
        }

        $prepared = [];
        $seen = [];

        foreach ($rows as $row) {
            if (!is_array($row) || ProductSuppliers::isEmptyRow($row)) {
                continue;
            }

            $supplierId = (int) ($row['supplier_id'] ?? 0);

            // La scheda li ha già rifiutati: qui non passano comunque.
            if ($supplierId <= 0 || isset($seen[$supplierId])) {
                continue;
            }

            $seen[$supplierId] = true;
            $cost = Numbers::fromForm(is_scalar($row['cost'] ?? null) ? $row['cost'] : null);

            $prepared[] = [
                'id' => trim((string) ($row['id'] ?? '')),
                'supplier_id' => $supplierId,
                'supplier_sku' => trim((string) ($row['supplier_sku'] ?? '')),
                // Vuoto resta vuoto, e la colonna lo scrive NULL: «non lo so».
                'cost' => $cost ?? '',
                'is_preferred' => ($row['is_preferred'] ?? 'false') === 'true' ? 'true' : 'false',
            ];
        }

        return ProductSuppliers::preferOne(static::supplierCardNewPreferred($prepared));
    }

    /**
     * Una riga dei fornitori con un id che non è di questa opzione si salva
     * come riga nuova.
     *
     * Il repeater del core aggiorna per id e riscrive l'opzione della riga:
     * l'id di un'altra opzione (un form copiato o ritoccato) le porterebbe
     * via il legame, e uno che non c'è più, perché nel frattempo la finestra
     * dei costi dell'articolo ha cambiato fornitore, non aggiornerebbe
     * niente, mentre il legame nuovo se ne andrebbe come non visto. Così
     * anche `supplierCardStoredPreferred()` legge solo righe di questa
     * opzione.
     */
    public static function syncRepeaterRelations(
        int|string $parentId,
        array $post,
        array $files = [],
        string $action = 'store',
        string $context = 'backend'
    ): array {
        if (is_array($post['suppliers'] ?? null)) {
            $own = array_map(
                static fn (array $row): string => (string) $row['id'],
                static::rowsOf(ProductSupplier::class, ['product_id' => (int) $parentId, 'deleted' => ['true', 'false']])
            );

            foreach ($post['suppliers'] as $key => $row) {
                $rowId = is_array($row) && is_scalar($row['id'] ?? null) ? trim((string) $row['id']) : '';

                if (is_array($row) && !in_array($rowId, $own, true)) {
                    $post['suppliers'][$key]['id'] = '';
                }
            }
        }

        return parent::syncRepeaterRelations($parentId, $post, $files, $action, $context);
    }

    /**
     * Con due «Sì» vince quello appena scelto.
     *
     * Il preferito è una tendina per riga, e sceglierne un altro non spegne
     * quello di prima: fra i «Sì» si tiene il primo che nel database non era
     * già il preferito. Senza niente di nuovo decide `preferOne()`.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    protected static function supplierCardNewPreferred(array $rows): array
    {
        $flagged = array_keys(array_filter($rows, static fn (array $row): bool => $row['is_preferred'] === 'true'));

        if (count($flagged) < 2) {
            return $rows;
        }

        $stored = static::supplierCardStoredPreferred(array_map(
            static fn (int $index): string => (string) $rows[$index]['id'],
            $flagged
        ));

        foreach ($flagged as $index) {
            if (!in_array((string) $rows[$index]['id'], $stored, true)) {
                foreach ($flagged as $other) {
                    $rows[$other]['is_preferred'] = $other === $index ? 'true' : 'false';
                }

                break;
            }
        }

        return $rows;
    }

    /**
     * Il costo si scrive con due decimali e si tiene con quattro: se la
     * casella dice lo stesso numero arrotondato, resta quello salvato.
     */
    public static function prepareRepeaterRelationRow(
        string $inputName,
        array $payload,
        array $row,
        ?array $existingRow = null,
        string $action = 'store',
        string $context = 'backend'
    ): array {
        if ($inputName !== 'suppliers') {
            return parent::prepareRepeaterRelationRow($inputName, $payload, $row, $existingRow, $action, $context);
        }

        $stored = $existingRow['cost'] ?? null;
        $posted = $payload['cost'] ?? null;

        if (is_numeric($stored) && is_numeric($posted) && round((float) $stored, 2) === round((float) $posted, 2)) {
            $payload['cost'] = (string) $stored;
        }

        return $payload;
    }

    public static function afterUpdate(int|string $id, object $result, array $values = []): void
    {
        $attributes = Attributes::byLevel(static::attributes(), 'product');
        $input = [];

        foreach ($attributes as $attribute) {
            $attributeId = (int) $attribute['id'];
            $input[$attributeId] = $_POST['attribute_'.$attributeId] ?? null;
        }

        ProductAttributes::save('product', (int) $id, $attributes, $input);

        // La soglia può essere appena cambiata senza nessun movimento:
        // l'avviso si apre o si chiude adesso, non alla prossima vendita.
        if (Gestionale::feature('low_stock_alerts')) {
            Alerts::refresh((int) $id);
        }
    }

    /** Riempie il form con gli attributi dell'opzione e i costi dei fornitori. */
    public static function mutateFormValues(
        array $values,
        string $mode,
        string $context = 'backend'
    ): array {
        // Le righe le ha già lette il core, dal database o da un salvataggio
        // rifiutato. Il costo va alla casella come numero grezzo con il
        // punto e due decimali: le cifre le mette lei, e vuoto resta vuoto.
        if (is_array($values['suppliers'] ?? null)) {
            foreach ($values['suppliers'] as $index => $row) {
                if (is_array($row) && array_key_exists('cost', $row)) {
                    $values['suppliers'][$index]['cost'] = is_numeric($row['cost'])
                        ? number_format(round((float) $row['cost'], 2), 2, '.', '')
                        : (string) ($row['cost'] ?? '');
                }
            }
        }

        $productId = (int) ($values['id'] ?? 0);

        if ($mode !== 'edit' || $productId === 0) {
            return $values;
        }

        $links = ProductAttributes::read('product', $productId);

        foreach (Attributes::byLevel(static::attributes(), 'product') as $attribute) {
            $attributeId = (int) $attribute['id'];
            $values['attribute_'.$attributeId] = static::attributeValue($attribute, $links[$attributeId] ?? null);
        }

        return $values;
    }

    /**
     * Una versione con movimenti non si elimina, come il suo articolo.
     *
     * Quella del genitore legge l'id come id del modello: qui è già il
     * prodotto. Nessuna pagina elimina una versione, ma `api/backend/delete`
     * del core passa di qui.
     */
    public static function assertDeletable(int|string $id): void
    {
        if (StockHistory::hasMovements((int) $id)) {
            throw UserError::refusal('product.has_movements');
        }
    }

    /** Eliminare un'opzione porta via i suoi attributi e i suoi fornitori. */
    public static function deleteRecord(int|string $id): object
    {
        static::assertDeletable($id);

        foreach (ProductAttributes::read('product', (int) $id) as $link) {
            ProductAttributes::modelClass('product')::delete((int) $link['id']);
        }

        StockHistory::dropAlerts([(int) $id]);
        // Anche ad acquisti spenti: la chiave esterna fermerebbe l'eliminazione.
        ProductSuppliers::dropFor([(int) $id]);

        return Product::delete($id);
    }

    /** Il titolo della scheda dice di che articolo e di che colore si tratta. */
    public static function currentTitle(): string
    {
        $id = static::currentId();

        if ($id === null) {
            return 'Prodotto';
        }

        $product = static::rowsOf(Product::class, ['id' => $id])[0] ?? null;

        if ($product === null) {
            return 'Prodotto';
        }

        $model = static::modelNames()[(int) ($product['product_model_id'] ?? 0)] ?? '';
        $variant = static::variantNames()[(int) ($product['product_variant_id'] ?? 0)] ?? '';

        return trim($model.($variant === '' || $variant === $model ? '' : ' · '.$variant)) ?: 'Prodotto';
    }

    /** @return array<int, string> */
    public static function modelNames(): array
    {
        static $names = null;

        if ($names === null) {
            $names = [];

            foreach (static::rowsOf(ProductModel::class, [], 'name') as $row) {
                $names[(int) $row['id']] = (string) ($row['name'] ?? '');
            }
        }

        return $names;
    }

    /** @return array<int, string> */
    public static function variantNames(): array
    {
        static $names = null;

        if ($names === null) {
            $names = [];

            foreach (static::rowsOf(ProductVariant::class, [], 'position') as $row) {
                $names[(int) $row['id']] = (string) ($row['name'] ?? '');
            }
        }

        return $names;
    }

    /** Un'opzione non si crea da qui: non c'è niente da preparare. */
    public static function afterStore(object $result, array $values = []): void
    {
    }

    protected static function attributeField(array $attribute): Input
    {
        return parent::attributeField($attribute);
    }
}
