<?php

namespace Wonder\Plugin\Gestionale\Resources\Locations;

use RuntimeException;
use Wonder\App\Resources\Config\SocietyLocationResource;
use Wonder\App\ResourceSchema\FormField;
use Wonder\App\ResourceSchema\PageSchema;
use Wonder\App\ResourceSchema\TableColumn;
use Wonder\Elements\Components\Card;
use Wonder\Elements\Components\SectionTitle;
use Wonder\Elements\Form\Form;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Locations\Location;

/**
 * "Sedi" del gestionale: la stessa pagina del core con in più il magazzino.
 *
 * Ha lo stesso percorso della Resource del core, quindi il `ResourceRegistry`
 * mette questa al suo posto (i moduli hanno priorità più alta): il
 * commerciante continua a vedere una sola voce "Sedi".
 *
 * I dati del core restano dove sono: nome, indirizzo, contatti, orari e
 * chiusure in `society_locations`, giacenza, ritiro e banco in
 * `gst_locations`, una riga per sede. I campi in più non sono colonne della
 * sede, quindi si tolgono dai valori prima della query e si scrivono dopo.
 */
final class LocationResource extends SocietyLocationResource
{
    /** Campi del gestionale, da non far finire nella query di `society_locations`. */
    private const WAREHOUSE_FIELDS = ['has_stock', 'is_pickup_point', 'is_pos', 'active'];

    public static function labelSchema(): array
    {
        return array_merge(parent::labelSchema(), [
            'has_stock' => 'Giacenza',
            'is_pickup_point' => 'Punto di ritiro',
            'is_pos' => 'Banco',
            'active' => 'Sede attiva',
        ]);
    }

    public static function formSchema(): array
    {
        $fields = parent::formSchema();

        $fields[] = FormField::key('has_stock')
            ->select(['true' => 'Sì', 'false' => 'No'])
            ->value('true')
            ->label('Giacenza');

        // Il ritiro in sede ha senso solo con le spedizioni, il banco solo con
        // il POS: senza la funzionalità il campo non esiste (D20).
        if (Gestionale::feature('shipping')) {
            $fields[] = FormField::key('is_pickup_point')
                ->select(['true' => 'Sì', 'false' => 'No'])
                ->value('false')
                ->label('Punto di ritiro');
        }

        if (Gestionale::feature('pos')) {
            $fields[] = FormField::key('is_pos')
                ->select(['true' => 'Sì', 'false' => 'No'])
                ->value('false')
                ->label('Banco');
        }

        $fields[] = FormField::key('active')
            ->select(['true' => 'Attiva', 'false' => 'Non attiva'])
            ->value('true')
            ->label('Sede attiva');

        return $fields;
    }

    public static function formLayoutSchema(): ?Form
    {
        $form = parent::formLayoutSchema();

        if (!$form instanceof Form || $form->components === []) {
            return $form;
        }

        $container = $form->components[0];

        if (!is_object($container) || !property_exists($container, 'components')) {
            return $form;
        }

        $components = [
            SectionTitle::make('Magazzino')
                ->tooltip('La giacenza si tiene per sede: senza, la sede non movimenta prodotti.')
                ->columnSpan(12),
            static::getInput('has_stock')->columnSpan(3),
        ];

        if (Gestionale::feature('shipping')) {
            $components[] = static::getInput('is_pickup_point')->columnSpan(3);
        }

        if (Gestionale::feature('pos')) {
            $components[] = static::getInput('is_pos')->columnSpan(3);
        }

        $components[] = static::getInput('active')->columnSpan(3);

        $container->components([
            ...$container->components,
            (new Card)->components($components)->columns(12)->columnSpan(2),
        ]);

        return $form;
    }

    public static function tableSchema(): array
    {
        $columns = parent::tableSchema();
        $actions = array_pop($columns);

        $columns[] = TableColumn::key('has_stock')
            ->booleanBadge()
            ->badgeOn('Giacenza', 'bi bi-box-seam', 'primary')
            ->badgeOff('Senza giacenza', 'bi bi-box', 'secondary')
            ->size('little');

        $columns[] = $actions;

        return $columns;
    }

    public static function pageSchema(): PageSchema
    {
        $schema = parent::pageSchema();
        $url = Gestionale::docsUrl('sedi');

        return $url === '' ? $schema : $schema->docs($url);
    }

    /**
     * Fuori dal locale la sede è in sola lettura, ma orari e chiusure cambiano
     * dove si lavora: sono l'unica cosa che il commerciante deve poter
     * correggere subito (G1.6).
     */
    public static function editableWhenReadonly(): array
    {
        return ['hours', 'special_hours'];
    }

    /** I campi del magazzino non sono colonne della sede: li scrive `afterUpdate`. */
    public static function mutateRequestValues(
        array $values,
        string $action,
        string $context = 'backend',
        ?array $oldValues = null
    ): array {
        $values = parent::mutateRequestValues($values, $action, $context, $oldValues);

        foreach (self::WAREHOUSE_FIELDS as $field) {
            unset($values[$field]);
        }

        return $values;
    }

    /** La scheda mostra i valori del gestionale accanto a quelli della sede. */
    public static function mutateFormValues(array $values, string $mode, string $context = 'backend'): array
    {
        $values = parent::mutateFormValues($values, $mode, $context);
        $warehouse = Location::forSocietyLocation((int) ($values['id'] ?? 0));

        foreach (self::WAREHOUSE_FIELDS as $field) {
            $values[$field] = (string) ($warehouse[$field] ?? self::defaultValue($field));
        }

        return $values;
    }

    public static function afterStore(object $result, array $values = []): void
    {
        parent::afterStore($result, $values);

        $id = (int) ($result->insert_id ?? 0);

        if ($id > 0) {
            self::saveWarehouse($id);
        }
    }

    public static function afterUpdate(int|string $id, object $result, array $values = []): void
    {
        parent::afterUpdate($id, $result, $values);

        self::saveWarehouse((int) $id);
    }

    /** Una sede con una riga del gestionale non si elimina da sotto al magazzino. */
    public static function assertDeletable(int|string $id): void
    {
        parent::assertDeletable($id);

        if (Location::forSocietyLocation((int) $id) !== []) {
            throw new RuntimeException(
                'Questa sede è collegata al magazzino del gestionale: disattivala invece di eliminarla.'
            );
        }
    }

    /** Scrive la riga del gestionale, creandola se la sede è nuova. */
    private static function saveWarehouse(int $societyLocationId): void
    {
        if ($societyLocationId <= 0) {
            return;
        }

        $values = [];

        foreach (self::WAREHOUSE_FIELDS as $field) {
            if (!isset($_POST[$field])) {
                continue;
            }

            $values[$field] = ((string) $_POST[$field]) === 'true' ? 'true' : 'false';
        }

        if ($values === []) {
            return;
        }

        $existing = Location::forSocietyLocation($societyLocationId);

        if ($existing !== []) {
            sqlModify(Location::$table, $values, 'id', (int) $existing['id']);

            return;
        }

        Location::create(array_merge($values, ['society_location_id' => $societyLocationId]));
    }

    private static function defaultValue(string $field): string
    {
        return $field === 'has_stock' || $field === 'active' ? 'true' : 'false';
    }
}
