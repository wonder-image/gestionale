<?php

namespace Wonder\Plugin\Gestionale\Resources\Shipping;

use Wonder\App\ResourceSchema\FormField;
use Wonder\App\ResourceSchema\NavigationSchema;
use Wonder\App\ResourceSchema\PageSchema;
use Wonder\App\ResourceSchema\PermissionSchema;
use Wonder\App\ResourceSchema\RepeaterColumn;
use Wonder\App\ResourceSchema\RepeaterRelation;
use Wonder\App\ResourceSchema\TableColumn;
use Wonder\App\Support\Repeater;
use Wonder\Elements\Components\Alert;
use Wonder\Elements\Components\Card;
use Wonder\Elements\Components\Container;
use Wonder\Elements\Components\SectionTitle;
use Wonder\Elements\Form\Form;
use Wonder\Plugin\Gestionale\Models\Shipping\ShippingRate;
use Wonder\Plugin\Gestionale\Models\Shipping\ShippingRateBracket;
use Wonder\Plugin\Gestionale\Models\Shipping\ShippingZone;
use Wonder\Plugin\Gestionale\Models\Shipping\ShippingZoneArea;
use Wonder\Plugin\Gestionale\Resources\GestionaleResource;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Positions;
use Wonder\Plugin\Gestionale\Support\Shipping\ShippingZones;
use Wonder\Sql\Transaction;

/**
 * «Zone di spedizione»: un gruppo di aree — un paese, o una provincia di un
 * paese — che hanno gli stessi listini. Vince l'area più specifica: una
 * provincia con la sua zona batte il solo paese.
 *
 * Se due zone hanno la stessa area la pagina avvisa, ma non blocca: vale la
 * prima dell'elenco.
 */
final class ShippingZoneResource extends GestionaleResource
{
    public static string $feature = 'shipping';
    public static string $model = ShippingZone::class;
    public static string $orderColumn = 'position';
    public static string $orderDirection = 'ASC';
    public static string $docsPage = 'spedizioni/spedizioni-listini';

    public static function path(): string
    {
        return 'app/gestionale/zone-di-spedizione';
    }

    public static function icon(): string
    {
        return 'bi-globe-europe-africa';
    }

    public static function titleLabel(): string
    {
        return 'Zone di spedizione';
    }

    public static function textSchema(): array
    {
        return [
            'label' => 'zona',
            'plural_label' => 'zone',
            'last' => 'ultime',
            'all' => 'tutte',
            'article' => 'le',
            'full' => 'attiva',
            'empty' => 'non attiva',
            'this' => 'questa',
        ];
    }

    public static function labelSchema(): array
    {
        return [
            'name' => 'Nome',
            'areas' => 'Aree',
        ];
    }

    public static function formSchema(): array
    {
        return [
            FormField::key('name')->text()->label('Nome')->required(),
            FormField::key('areas')
                ->repeater([
                    RepeaterColumn::key('id')->hidden(),
                    RepeaterColumn::key('country')->country('province')->value('IT')->label('Paese')->columnSpan(6),
                    RepeaterColumn::key('province')->states('IT')->label('Provincia')->columnSpan(6),
                ])
                ->relation(
                    RepeaterRelation::make(ShippingZoneArea::$table, 'shipping_zone_id')
                        ->model(ShippingZoneArea::class)
                        ->softDelete(false)
                )
                ->nested()
                ->repeaterAddLabel('Aggiungi area')
                ->repeaterDeleteTitle('Togli area')
                ->repeaterDeleteText('Confermi di togliere quest\'area dalla zona?')
                ->repeaterDeleteCancelLabel('Annulla')
                ->repeaterDeleteConfirmLabel('Togli')
                ->repeaterDeleteConfirmClass('btn btn-danger')
                ->label(''),
        ];
    }

    public static function formLayoutSchema(): ?Form
    {
        $id = static::currentId() ?? 0;
        $components = [];
        $overlaps = $id > 0 ? ShippingZones::overlaps($id) : [];

        if ($overlaps !== []) {
            $components[] = (new Container)->components([
                Alert::make(
                    'Anche '.implode(', ', array_map(static fn (string $name): string => '«'.$name.'»', $overlaps))
                    .' ha'.(count($overlaps) > 1 ? 'nno' : '').' qualche area uguale a queste: per quelle aree vale la zona che sta prima nell\'elenco.',
                    'warning'
                ),
            ])->columnSpan(12);
        }

        $components[] = (new Card)->components([
            SectionTitle::make('Zona')
                ->tooltip('Il nome serve a te, per riconoscerla nei listini (per esempio «Italia», «Isole», «Europa»).')
                ->columnSpan(12),
            static::getInput('name')->columnSpan(6),
        ])->columns(12)->columnSpan(12);

        $components[] = (new Card)->components([
            SectionTitle::make('Aree')
                ->tooltip('La provincia è facoltativa, per esempio Cagliari o Sassari per isolare la Sardegna: senza provincia l\'area vale per tutto il paese. Una provincia con la sua zona batte il paese.')
                ->columnSpan(12),
            static::getInput('areas')->columnSpan(12),
        ])->columns(12)->columnSpan(12);

        return (new Form)->components([
            (new Container)->components($components)->columns(12)->columnSpan(12),
        ]);
    }

    public static function tableSchema(): array
    {
        return [
            TableColumn::key('name')->text()->link('edit'),
            TableColumn::key('areas')
                ->text()
                ->formatter(static fn (array $row): string => static::escape(static::areasLabel((int) ($row['id'] ?? 0)))),
            TableColumn::key('actions')->button()->actions(['edit', 'delete']),
        ];
    }

    public static function pageSchema(): PageSchema
    {
        return parent::pageSchema()
            ->disable(['view'])
            ->titles([
                'list' => 'Zone di spedizione',
                'create' => 'Nuova zona',
                'edit' => 'Modifica zona',
            ]);
    }

    public static function permissionSchema(): PermissionSchema
    {
        return PermissionSchema::for(static::class)->backendCrud(['admin', 'administrator']);
    }

    public static function navigationSchema(): NavigationSchema
    {
        return parent::navigationSchema()
            ->inSection('spedizioni')
            ->title('Zone')
            ->order(20)
            ->authority(['admin', 'administrator']);
    }

    /**
     * «IT, FR, IT CA»: fino a otto aree, poi «… e altre N».
     */
    public static function areasLabel(int $zoneId): string
    {
        $areas = static::rowsOf(ShippingZoneArea::class, ['shipping_zone_id' => $zoneId]);
        $names = array_map(
            static fn (array $a): string => trim((string) ($a['country'] ?? '').' '.(string) ($a['province'] ?? '')),
            $areas
        );

        if (count($names) <= 8) {
            return implode(', ', $names);
        }

        return implode(', ', array_slice($names, 0, 8)).' … e altre '.(count($names) - 8);
    }

    /**
     * Le aree si normalizzano nella richiesta (sigle in maiuscolo, senza
     * spazi) e si controllano prima che si scriva qualcosa: serve almeno
     * un'area, il paese è di due lettere e la stessa area non c'è due volte. La
     * posizione la mette il backend, in fondo all'elenco.
     */
    public static function mutateRequestValues(
        array $values,
        string $action,
        string $context = 'backend',
        ?array $oldValues = null
    ): array {
        $seen = [];

        foreach ((array) ($_POST['areas'] ?? []) as $key => $row) {
            if (!is_array($row)) {
                continue;
            }

            $country = strtoupper(preg_replace('/\s+/', '', (string) ($row['country'] ?? '')) ?? '');
            $province = strtoupper(preg_replace('/\s+/', '', (string) ($row['province'] ?? '')) ?? '');
            $_POST['areas'][$key]['country'] = $country;
            $_POST['areas'][$key]['province'] = $province;
        }

        foreach (Repeater::rowsFromRequest('areas', (array) $_POST) as $row) {
            $country = (string) ($row['country'] ?? '');
            $province = (string) ($row['province'] ?? '');

            // Una riga svuotata (resta solo l'id) è una riga tolta.
            if ($country === '' && $province === '') {
                continue;
            }

            if (preg_match('/^[A-Z]{2}$/', $country) !== 1) {
                throw UserError::make('shipping.area_country', ['country' => $country]);
            }

            $key = $country.'|'.$province;

            if (isset($seen[$key])) {
                throw UserError::make('shipping.area_duplicate', ['area' => trim($country.' '.$province)]);
            }

            $seen[$key] = true;
        }

        if ($seen === []) {
            throw UserError::make('shipping.zone_no_areas');
        }

        if ($action === 'store') {
            $values['position'] = Positions::next(ShippingZone::$table);
        } else {
            unset($values['position']);
        }

        return $values;
    }

    /** Le aree senza paese e provincia non sono aree: non si scrivono. */
    public static function prepareRepeaterRows(
        string $inputName,
        array $rows,
        string $action = 'store',
        string $context = 'backend'
    ): array {
        if ($inputName !== 'areas') {
            return $rows;
        }

        return array_values(array_filter(
            $rows,
            static fn (array $row): bool => trim((string) ($row['country'] ?? '').(string) ($row['province'] ?? '')) !== ''
        ));
    }

    /** Una zona che ha listini accesi si toglie dai metodi, non si elimina. */
    public static function assertDeletable(int|string $id): void
    {
        $count = count(static::rowsOf(ShippingRate::class, ['shipping_zone_id' => (int) $id, 'active' => 'true']));

        if ($count > 0) {
            // `refusal()` e non `make()`: chi cancella dall'elenco intercetta `RuntimeException`.
            throw UserError::refusal('shipping.zone_in_use', ['count' => $count]);
        }
    }

    /** Aree e listini spenti se ne vanno con la zona: la chiave esterna non lascerebbe eliminarla. */
    public static function deleteRecord(int|string $id): object
    {
        $id = (int) $id;

        static::assertDeletable($id);

        $result = null;

        Transaction::run(static function () use ($id, &$result): void {
            foreach (static::rowsOf(ShippingRate::class, ['shipping_zone_id' => $id, 'deleted' => ['true', 'false']]) as $rate) {
                foreach (static::rowsOf(ShippingRateBracket::class, ['shipping_rate_id' => (int) $rate['id'], 'deleted' => ['true', 'false']]) as $bracket) {
                    ShippingRateBracket::delete((int) $bracket['id']);
                }

                ShippingRate::delete((int) $rate['id']);
            }

            foreach (static::rowsOf(ShippingZoneArea::class, ['shipping_zone_id' => $id, 'deleted' => ['true', 'false']]) as $area) {
                ShippingZoneArea::delete((int) $area['id']);
            }

            $result = ShippingZone::delete($id);
        });

        return is_object($result) ? $result : (object) ['success' => true, 'table' => ShippingZone::$table, 'id' => $id];
    }
}
