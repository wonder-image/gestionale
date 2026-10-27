<?php

namespace Wonder\Plugin\Gestionale\Resources\Catalog;

use Wonder\App\ResourceSchema\ApiSchema;
use Wonder\App\ResourceSchema\FormField;
use Wonder\App\ResourceSchema\NavigationSchema;
use Wonder\App\ResourceSchema\PageSchema;
use Wonder\App\ResourceSchema\PermissionSchema;
use Wonder\App\ResourceSchema\TableColumn;
use Wonder\Elements\Components\Card;
use Wonder\Elements\Components\Container;
use Wonder\Elements\Components\SectionTitle;
use Wonder\Elements\Form\Form;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Catalog\Faq;
use Wonder\Plugin\Gestionale\Resources\GestionaleResource;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Positions;
use Wonder\Plugin\Gestionale\Support\Promotions\ProductScope;
use Wonder\Plugin\Gestionale\Support\Promotions\ScopeForm;
use Wonder\Plugin\Gestionale\Support\RequestMemo;

/**
 * «FAQ»: domande frequenti con la risposta, da mostrare nella scheda dei
 * prodotti scelti e, se serve, nella home.
 *
 * Il selettore dei prodotti è quello di campagne e coupon (`ScopeForm`),
 * salvato nei ponti `gst_faq_*`; qui parte da «Solo la selezione», così una
 * FAQ nuova non finisce in tutte le schede per sbaglio.
 */
final class FaqResource extends GestionaleResource
{
    use ScopeForm;

    public static string $model = Faq::class;
    public static string $orderColumn = 'position';
    public static string $orderDirection = 'ASC';
    public static string $docsPage = 'catalogo/catalogo-faq';

    public static function path(): string
    {
        return 'app/gestionale/faq';
    }

    public static function icon(): string
    {
        return 'bi-question-circle';
    }

    public static function titleLabel(): string
    {
        return 'FAQ';
    }

    public static function textSchema(): array
    {
        return [
            'label' => 'FAQ',
            'plural_label' => 'FAQ',
            'last' => 'ultime',
            'all' => 'tutte',
            'article' => 'le',
            'full' => 'visibile',
            'empty' => 'nascosta',
            'this' => 'questa',
        ];
    }

    public static function labelSchema(): array
    {
        return [
            'question' => 'Domanda',
            'answer' => 'Risposta',
            'importance' => 'Ordine',
            'visible' => 'Stato',
            'show_on_home' => 'Nella home',
            'where' => 'Dove si vede',
        ];
    }

    public static function formSchema(): array
    {
        $scope = static::scopeFields();
        $scope[0] = FormField::key('applies_to_all')
            ->select(['false' => 'Solo la selezione', 'true' => 'Tutti i prodotti'])
            ->value('false')
            ->label('Nella scheda di')
            ->required();

        return [
            FormField::key('question')->text()->label('Domanda')->required(),
            FormField::key('answer')->textarea('plus')->label('Risposta')->required(),
            FormField::key('visible')
                ->select(['true' => 'Visibile', 'false' => 'Nascosta'])
                ->value('true')
                ->label('Stato')
                ->required(),
            FormField::key('importance')
                ->select(['high' => 'In cima', 'normal' => 'Normale', 'low' => 'In fondo'])
                ->value('normal')
                ->label('Ordine')
                ->required(),
            FormField::key('show_on_home')->toggle()->value('false')->label('Mostra nella home'),
            ...$scope,
        ];
    }

    public static function formLayoutSchema(): ?Form
    {
        $main = (new Card)->components([
            SectionTitle::make('FAQ')->columnSpan(12),
            static::getInput('question')->columnSpan(12),
            static::getInput('answer')->columnSpan(12),
        ])->columns(12)->columnSpan(12);

        $scope = (new Card)->components([
            SectionTitle::make('Prodotti')
                ->tooltip('Nella scheda di tutti i prodotti o «Solo la selezione»: categorie (con le loro sottocategorie), tag, marchi e articoli. Basta che il prodotto ne rispetti uno. Gli articoli esclusi non la mostrano mai. Senza selezione la FAQ non compare in nessuna scheda.')
                ->columnSpan(12),
            static::getInput('applies_to_all')->columnSpan(12),
            static::getInput('categories')->columnSpan(12),
            static::getInput('tags')->columnSpan(12),
            static::getInput('brands')->columnSpan(12),
            static::getInput('models')->columnSpan(12),
            static::getInput('excluded_models')->columnSpan(12),
        ])->columns(12)->columnSpan(12);

        $side = (new Card)->components([
            SectionTitle::make('Pubblicazione')
                ->tooltip('«In cima» e «In fondo» spostano la FAQ rispetto alle altre; a parità vale l\'ordine di creazione.')
                ->columnSpan(12),
            static::getInput('visible')->columnSpan(12),
            static::getInput('importance')->columnSpan(12),
            static::getInput('show_on_home')->columnSpan(12),
        ])->columns(12)->columnSpan(12);

        return (new Form)->components([
            (new Container)->components([$main, $scope])->columns(12)->columnSpan(8),
            (new Container)->components([$side])->columns(12)->columnSpan(4),
        ])->columns(12);
    }

    public static function tableSchema(): array
    {
        return [
            TableColumn::key('question')->text()->link('edit'),
            TableColumn::key('where')
                ->text()
                ->formatter(static fn (array $row): string => static::escape(static::whereLabel($row))),
            TableColumn::key('visible')->visibleBadge()->size('little'),
            TableColumn::key('actions')->button()->actions(['edit', 'delete']),
        ];
    }

    public static function pageSchema(): PageSchema
    {
        return parent::pageSchema()
            ->disable(['view'])
            ->titles([
                'list' => 'FAQ',
                'create' => 'Nuova FAQ',
                'edit' => 'Modifica FAQ',
            ]);
    }

    public static function permissionSchema(): PermissionSchema
    {
        return PermissionSchema::for(static::class)->access(Gestionale::area('catalogo'));
    }

    public static function apiSchema(): ApiSchema
    {
        return ApiSchema::for(static::class)->enabled(false);
    }

    public static function navigationSchema(): NavigationSchema
    {
        return NavigationSchema::for(static::class)
            ->inSection('catalogo')
            ->title('FAQ')
            ->order(45)
            ->access(Gestionale::area('catalogo'));
    }

    /** «Home · tutti i prodotti», «Selezione», «—» se non si vede da nessuna parte. */
    public static function whereLabel(array $row): string
    {
        $parts = [];

        if (($row['show_on_home'] ?? 'false') === 'true') {
            $parts[] = 'Home';
        }

        $id = (int) ($row['id'] ?? 0);
        $scope = $id > 0 ? ProductScope::of('faq', $id) : ['all' => false];

        if (!empty($scope['all'])) {
            $parts[] = 'tutti i prodotti';
        } elseif (array_filter([$scope['categories'] ?? [], $scope['tags'] ?? [], $scope['brands'] ?? [], $scope['models'] ?? []]) !== []) {
            $parts[] = 'prodotti scelti';
        }

        return $parts === [] ? '—' : ucfirst(implode(' · ', $parts));
    }

    /**
     * Domanda e risposta obbligatorie anche lato server (il `required` non
     * ferma gli spazi); la posizione nasce in fondo alla creazione.
     */
    public static function mutateRequestValues(
        array $values,
        string $action,
        string $context = 'backend',
        ?array $oldValues = null
    ): array {
        foreach (['question' => 'faq.question_required', 'answer' => 'faq.answer_required'] as $column => $error) {
            if ($action !== 'store' && !array_key_exists($column, $values)) {
                continue;
            }

            $value = is_scalar($values[$column] ?? null) ? trim((string) $values[$column]) : '';

            if (trim(strip_tags(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'))) === '') {
                throw UserError::make($error);
            }

            $values[$column] = $value;
        }

        if ($action === 'store' || array_key_exists('importance', $values)) {
            $values['importance'] = in_array($values['importance'] ?? '', ['high', 'low'], true) ? $values['importance'] : 'normal';
        }

        if ($action === 'store') {
            $values['position'] = Positions::next(Faq::$table);
        } else {
            unset($values['position']);
        }

        // Il selettore non è una colonna: si salva nei ponti.
        return array_diff_key($values, array_flip(static::FIELDS));
    }

    /** Riempie il form con il selettore salvato. */
    public static function mutateFormValues(array $values, string $mode, string $context = 'backend'): array
    {
        $id = (int) ($values['id'] ?? 0);

        if ($mode === 'edit' && $id > 0) {
            $values = static::loadScope('faq', $id) + $values;
        }

        return $values;
    }

    public static function afterStore(object $result, array $values = []): void
    {
        // Il backend scrive la testata con una query diretta.
        RequestMemo::forget(Faq::MEMO_GROUP);
        $id = (int) ($result->insert_id ?? 0);

        if ($id > 0) {
            static::saveScope('faq', $id, static::readScope((array) $_POST));
        }
    }

    public static function afterUpdate(int|string $id, object $result, array $values = []): void
    {
        RequestMemo::forget(Faq::MEMO_GROUP);
        static::saveScope('faq', (int) $id, static::readScope((array) $_POST));
    }

    public static function afterDelete(int|string $id, object $result, array $values = []): void
    {
        RequestMemo::forget(Faq::MEMO_GROUP);
        parent::afterDelete($id, $result, $values);
    }
}
