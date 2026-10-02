<?php

namespace Wonder\Plugin\Gestionale\Resources\Catalog;

use RuntimeException;
use Wonder\App\ResourceSchema\ApiSchema;
use Wonder\App\ResourceSchema\FormField;
use Wonder\App\ResourceSchema\NavigationSchema;
use Wonder\App\ResourceSchema\PageSchema;
use Wonder\App\ResourceSchema\PermissionSchema;
use Wonder\App\ResourceSchema\RepeaterColumn;
use Wonder\App\ResourceSchema\RepeaterRelation;
use Wonder\App\ResourceSchema\TableColumn;
use Wonder\App\Support\Repeater;
use Wonder\Elements\Components\Card;
use Wonder\Elements\Components\Container;
use Wonder\Elements\Components\SectionTitle;
use Wonder\Elements\Form\Form;
use Wonder\Plugin\Gestionale\Models\Catalog\Customization;
use Wonder\Plugin\Gestionale\Models\Catalog\CustomizationOption;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModelCustomization;
use Wonder\Plugin\Gestionale\Resources\GestionaleResource;
use Wonder\Plugin\Gestionale\Support\Catalog\Customizations;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Numbers;
use Wonder\Plugin\Gestionale\Support\Positions;
use Wonder\Sql\Transaction;

/**
 * "Personalizzazioni": l'incisione, il colore del filo, il messaggio sul
 * biglietto — quello che il cliente aggiunge a un articolo e che non è una
 * delle sue opzioni in vendita.
 *
 * Si scrivono una volta qui e si collegano agli articoli dalla loro scheda.
 * Un **testo** lo scrive il cliente (con un massimo di caratteri), un
 * **numero** con i decimali stabiliti qui, una **scelta** la fa fra le
 * opzioni preparate qui. Il sovrapprezzo della
 * personalizzazione e quello dell'opzione scelta si sommano.
 *
 * Una personalizzazione già su qualche articolo non si elimina: si
 * disattiva, e smette di essere offerta.
 */
class CustomizationResource extends GestionaleResource
{
    public static string $feature = 'customizations';
    public static string $model = Customization::class;
    public static string $orderColumn = 'position';
    public static string $orderDirection = 'ASC';
    public static string $docsPage = 'catalogo/catalogo-personalizzazioni';

    /** Quanti caratteri può scrivere il cliente in una personalizzazione nata dal modal. */
    public const QUICK_MAX_LENGTH = 100;

    public static function path(): string
    {
        return 'app/gestionale/personalizzazioni';
    }

    public static function icon(): string
    {
        return 'bi-pencil-square';
    }

    public static function titleLabel(): string
    {
        return 'Personalizzazioni';
    }

    public static function textSchema(): array
    {
        return [
            'label' => 'personalizzazione',
            'plural_label' => 'personalizzazioni',
            'last' => 'ultime',
            'all' => 'tutte',
            'article' => 'le',
            'full' => 'attiva',
            'empty' => 'disattivata',
            'this' => 'questa',
        ];
    }

    public static function labelSchema(): array
    {
        return [
            'name' => 'Nome',
            'label' => 'Etichetta per il cliente',
            'help_text' => 'Testo d\'aiuto',
            'kind' => 'Tipo',
            'max_length' => 'Caratteri massimi',
            'decimals' => 'Decimali',
            'surcharge' => 'Sovrapprezzo',
            'active' => 'Stato',
        ];
    }

    /** @return array<string, string> */
    public static function kinds(): array
    {
        return ['text' => 'Testo', 'number' => 'Numero', 'choice' => 'Scelta'];
    }

    public static function formSchema(): array
    {
        return [
            FormField::key('name')->text()->label('Nome interno')->required(),
            FormField::key('label')->text()->label('Etichetta per il cliente'),
            FormField::key('help_text')->textarea()->label('Testo d\'aiuto'),
            FormField::key('kind')
                ->select(static::kinds())
                ->value('text')
                ->label('Tipo')
                ->required(),
            FormField::key('max_length')
                ->number()
                ->decimal(0)
                ->value('100')
                ->label('Caratteri massimi')
                ->visibleWhen('kind', 'text'),
            FormField::key('decimals')
                ->select(['0' => '0', '1' => '1', '2' => '2', '3' => '3', '4' => '4', '5' => '5', '6' => '6'])
                ->value('0')
                ->label('Decimali')
                ->visibleWhen('kind', 'number'),
            FormField::key('surcharge')->price()->decimal(2)->value('0.00')->label('Sovrapprezzo'),
            FormField::key('active')
                ->select(['true' => 'Attiva', 'false' => 'Disattivata'])
                ->value('true')
                ->label('Stato')
                ->required(),
            FormField::key('options')
                ->repeater([
                    RepeaterColumn::key('id')->hidden(),
                    RepeaterColumn::key('label')->text()->label('Etichetta')->columnFill(),
                    RepeaterColumn::key('surcharge')->price()->decimal(2)->label('Sovrapprezzo')->columnSpan(3),
                ])
                ->relation(
                    RepeaterRelation::make(CustomizationOption::$table, 'customization_id')
                        ->model(CustomizationOption::class)
                        ->positionKey('position')
                        ->softDelete(false)
                )
                ->nested()
                ->repeaterSortable()
                ->repeaterAddLabel('Aggiungi opzione')
                ->repeaterDeleteTitle('Elimina opzione')
                ->repeaterDeleteText('Confermi l\'eliminazione di questa opzione?')
                ->repeaterDeleteCancelLabel('Annulla')
                ->repeaterDeleteConfirmLabel('Elimina')
                ->repeaterDeleteConfirmClass('btn btn-danger')
                // Il titolo del riquadro dice già «Opzioni».
                ->label(''),
        ];
    }

    /**
     * A sinistra quello che il cliente legge — nome, etichetta, testo d'aiuto
     * — e sotto, quando il tipo è «Scelta», le opzioni. A destra, stretta, i
     * «Dettagli»: tipo, caratteri o decimali, sovrapprezzo.
     */
    public static function formLayoutSchema(): ?Form
    {
        $main = (new Card)->components([
            SectionTitle::make('Personalizzazione')
                ->tooltip('Il nome interno lo vedi solo tu; l\'etichetta è quella che legge il cliente (se la lasci vuota vale il nome). Una personalizzazione disattivata esce dai carrelli: chi l\'aveva già nel carrello deve toglierla per procedere.')
                ->columnSpan(12),
            static::getInput('name')->columnSpan(6),
            static::getInput('active')->columnSpan(6),
            static::getInput('label')->columnSpan(12),
            static::getInput('help_text')->columnSpan(12),
        ])->columns(12)->columnSpan(12);

        // Il riquadro segue il tipo mentre lo si sceglie, senza salvare.
        $options = (new Card)->components([
            SectionTitle::make('Opzioni')
                ->tooltip('Almeno due. L\'ordine è quello che vedrà il cliente. Il sovrapprezzo di un\'opzione si somma a quello della personalizzazione. Eliminare un\'opzione non cambia gli ordini già fatti: le righe d\'ordine ricordano cosa era stato scelto.')
                ->columnSpan(12),
            static::getInput('options')->columnSpan(12),
        ])->columns(12)->columnSpan(12)->visibleWhen('kind', 'choice');

        $details = (new Card)->components([
            SectionTitle::make('Dettagli')
                ->tooltip('Un testo lo scrive il cliente, con un massimo di caratteri; un numero lo scrive con i decimali che dici qui; una scelta la fa fra le opzioni preparate sotto. Il sovrapprezzo si aggiunge al prezzo dell\'articolo, e a quello dell\'opzione scelta: su ogni articolo lo puoi cambiare dalla sua scheda.')
                ->columnSpan(12),
            static::getInput('kind')->columnSpan(12),
            static::getInput('max_length')->columnSpan(12),
            static::getInput('decimals')->columnSpan(12),
            static::getInput('surcharge')->columnSpan(12),
        ])->columns(12)->columnSpan(12);

        // `columns(12)` anche sul Form: la larghezza di un figlio si calcola
        // sulle colonne del padre.
        return (new Form)->components([
            (new Container)->components([$main, $options])->columns(12)->columnSpan(8),
            (new Container)->components([$details])->columns(12)->columnSpan(4),
        ])->columns(12);
    }

    public static function tableSchema(): array
    {
        return [
            TableColumn::key('name')->text()->link('edit'),
            TableColumn::key('kind')
                ->text()
                ->size('little')
                ->formatter(static fn (array $row): string => static::escape(
                    static::kinds()[(string) ($row['kind'] ?? '')] ?? ''
                )),
            TableColumn::key('surcharge')->price()->size('little'),
            TableColumn::key('usage')
                ->text()
                ->size('little')
                ->label('Articoli')
                ->formatter(static fn (array $row): string => (string) static::usage((int) ($row['id'] ?? 0))),
            TableColumn::key('active')
                ->booleanBadge()
                ->badgeOn('Attiva', 'bi-check-circle', 'success')
                ->badgeOff('Disattivata', 'bi-dash-circle', 'secondary')
                ->size('little'),
            TableColumn::key('actions')->button()->actions(['edit', 'delete']),
        ];
    }

    public static function pageSchema(): PageSchema
    {
        return parent::pageSchema()
            ->disable(['view'])
            ->titles([
                'list' => 'Personalizzazioni',
                'create' => 'Nuova personalizzazione',
                'edit' => 'Modifica personalizzazione',
            ]);
    }

    public static function permissionSchema(): PermissionSchema
    {
        return PermissionSchema::for(static::class)->backendCrud(['admin', 'administrator']);
    }

    /**
     * Solo lo store, per «Nuova personalizzazione» della scheda dell'articolo:
     * chi vende scrive il nome, il resto lo decide il server (vedi
     * quickCreateValues()).
     */
    public static function apiSchema(): ApiSchema
    {
        return ApiSchema::for(static::class)
            ->only(['store'])
            ->fields('store', ['name']);
    }

    public static function navigationSchema(): NavigationSchema
    {
        return parent::navigationSchema()
            ->inSection('catalogo')
            ->title('Personalizzazioni')
            ->order(44)
            ->authority(['admin', 'administrator']);
    }

    /**
     * I campi del modal «Nuova personalizzazione» della scheda articolo: solo
     * il nome.
     *
     * @return list<\Wonder\App\ResourceSchema\Input>
     */
    public static function quickCreateFields(): array
    {
        return [
            FormField::key('name')->text()->label('Nome')->required()->columnSpan(12),
        ];
    }

    /**
     * Quello che lo store API accetta: il nome. Il resto lo decide il server —
     * un testo da {@see QUICK_MAX_LENGTH} caratteri, senza sovrapprezzo,
     * attivo —, qualunque cosa porti la richiesta: le opzioni di una scelta
     * si preparano dalla pagina della personalizzazione.
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    public static function quickCreateValues(array $values): array
    {
        $name = is_scalar($values['name'] ?? null) ? trim((string) $values['name']) : '';

        if ($name === '') {
            throw UserError::make('customization.quick_name');
        }

        return [
            'name' => $name,
            'label' => $name,
            'help_text' => '',
            'kind' => 'text',
            'max_length' => static::QUICK_MAX_LENGTH,
            'decimals' => 0,
            'surcharge' => '0.00',
            'active' => 'true',
        ];
    }

    /**
     * Le regole del form, la posizione alla creazione e il tipo che non
     * lascia opzioni appese.
     */
    public static function mutateRequestValues(
        array $values,
        string $action,
        string $context = 'backend',
        ?array $oldValues = null
    ): array {
        if ($context === 'api' && $action === 'store') {
            $values = static::quickCreateValues($values);
        } else {
            $options = Repeater::rowsFromRequest('options', (array) $_POST);

            // Lo zero non si ristampa nel campo, e un campo vuoto vuol dire zero.
            if (trim((string) ($values['decimals'] ?? '')) === '') {
                $values['decimals'] = '0';
            }

            Customizations::assertDefinition($values, $options);

            // Il controllo ha accettato vuoto e virgola: si scrive sempre un decimale.
            $values['surcharge'] = Numbers::fromForm($values['surcharge'] ?? null) ?? '0.00';

            foreach ((array) ($_POST['options'] ?? []) as $key => $row) {
                if (is_array($row)) {
                    $_POST['options'][$key]['surcharge'] = Numbers::fromForm($row['surcharge'] ?? null) ?? '0.00';
                }
            }

            // Un testo o un numero non hanno opzioni: passando da scelta a uno
            // di loro quelle postate (il riquadro è solo nascosto) non devono
            // restare.
            if (($values['kind'] ?? '') !== 'choice') {
                $_POST['options'] = [];
            }

            // Un campo nascosto può arrivare vuoto: le colonne sono interi, e
            // quello che non vale per il tipo si azzera.
            $isText = ($values['kind'] ?? '') === 'text';
            $isNumber = ($values['kind'] ?? '') === 'number';
            $values['max_length'] = $isText || is_numeric($values['max_length'] ?? null) ? (int) $values['max_length'] : 0;
            $values['decimals'] = $isNumber ? (int) $values['decimals'] : 0;
        }

        if ($action === 'store') {
            $values['position'] = Positions::next(Customization::$table);
        } else {
            unset($values['position']);
        }

        return $values;
    }

    /** Le opzioni senza etichetta non sono opzioni: non si scrivono. */
    public static function prepareRepeaterRows(
        string $inputName,
        array $rows,
        string $action = 'store',
        string $context = 'backend'
    ): array {
        if ($inputName !== 'options') {
            return $rows;
        }

        return array_values(array_filter(
            $rows,
            static fn (array $row): bool => trim((string) ($row['label'] ?? '')) !== ''
        ));
    }

    /**
     * Le opzioni si scrivono solo dalla pagina della personalizzazione: lo
     * store API passa al repeater la richiesta intera, e un `options[...]`
     * portato lì creerebbe opzioni sotto a un testo appena nato.
     */
    public static function syncRepeaterRelations(
        int|string $parentId,
        array $post,
        array $files = [],
        string $action = 'store',
        string $context = 'backend'
    ): array {
        if ($context === 'api') {
            return [];
        }

        return parent::syncRepeaterRelations($parentId, $post, $files, $action, $context);
    }

    /** Quanti articoli la usano. */
    public static function usage(int $id): int
    {
        if ($id <= 0) {
            return 0;
        }

        return count(static::rowsOf(ProductModelCustomization::class, ['customization_id' => $id]));
    }

    /** Una personalizzazione su qualche articolo si disattiva, non si elimina. */
    public static function assertDeletable(int|string $id): void
    {
        $count = static::usage((int) $id);

        if ($count > 0) {
            // `refusal()` e non `make()`: chi cancella dall'elenco intercetta
            // `RuntimeException` (vedi `UserError`).
            throw UserError::refusal('customization.in_use', ['count' => $count]);
        }
    }

    /** Opzioni e collegamenti nel cestino se ne vanno con la personalizzazione: la chiave esterna non lascerebbe eliminarla. */
    public static function deleteRecord(int|string $id): object
    {
        $id = (int) $id;

        static::assertDeletable($id);

        $result = null;

        Transaction::run(static function () use ($id, &$result): void {
            // Anche quelle nel cestino: la chiave esterna le vede comunque. I
            // collegamenti rimasti sono tutti nel cestino (`assertDeletable`
            // ha rifiutato quelli vivi).
            foreach ([CustomizationOption::class, ProductModelCustomization::class] as $model) {
                foreach (static::rowsOf($model, ['customization_id' => $id, 'deleted' => ['true', 'false']]) as $row) {
                    $model::delete((int) $row['id']);
                }
            }

            $result = Customization::delete($id);
        });

        return is_object($result) ? $result : (object) ['success' => true, 'table' => Customization::$table, 'id' => $id];
    }
}
