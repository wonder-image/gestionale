<?php

namespace Wonder\Plugin\Gestionale\Resources\System;

use Throwable;
use Wonder\App\Environment;
use Wonder\Backend\Support\FlashAlert;
use Wonder\App\Resources\Support\NavigationOnlyResource;
use Wonder\App\ResourceSchema\FormField;
use Wonder\App\ResourceSchema\NavigationSchema;
use Wonder\App\ResourceSchema\PageSchema;
use Wonder\Elements\Components\Card;
use Wonder\Elements\Components\Container;
use Wonder\Elements\Components\SectionTitle;
use Wonder\Elements\Form\Form;
use Wonder\App\LegacyGlobals;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\System\Feature;
use Wonder\Plugin\Gestionale\Support\Catalog\Exports\ProductFeeds;
use Wonder\Plugin\Gestionale\Support\Features\FeatureCatalog;
use Wonder\Plugin\Gestionale\Support\Features\FeaturePanel;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;

/**
 * Pagina "Funzionalità": un interruttore per funzionalità, raggruppati per
 * area. Non è un elenco CRUD — le righe di `gst_features` sono lo stato di un
 * catalogo che vive nel codice — quindi è una pagina-form del core
 * (`isFormPage()`): schema, layout e salvataggio stanno tutti qui.
 */
final class FeatureResource extends NavigationOnlyResource
{
    public static function path(): string
    {
        return 'app/gestionale/funzionalita';
    }

    public static function icon(): string
    {
        return 'bi-toggles';
    }

    public static function titleLabel(): string
    {
        return 'Funzionalità';
    }

    public static function isFormPage(): bool
    {
        return true;
    }

    /**
     * La pagina non ha un model, quindi la sola lettura la dichiara lei:
     * i dati stanno in `gst_features`, che si modifica solo in locale.
     */
    public static function isReadonly(): bool
    {
        return Feature::syncSchema()?->localOnly === true && !Environment::isLocal();
    }

    /** Un interruttore per funzionalità, con nome e descrizione dal catalogo. */
    public static function formSchema(): array
    {
        $readonly = !Environment::isLocal();
        $fields = [];

        foreach (FeaturePanel::byArea() as $features) {
            foreach ($features as $feature) {
                $field = FormField::key($feature['key'])
                    ->toggle()
                    ->label($feature['name'])
                    ->description(static::describe($feature))
                    ->value($feature['enabled'] ? 'true' : 'false');

                // Sola lettura fuori dal locale; una funzionalità il cui modulo
                // non è abilitato, o che non è ancora stata costruita, non si
                // può sbloccare.
                if ($readonly || !$feature['available'] || !$feature['created']) {
                    $field->disabled();
                }

                $fields[] = $field;
                array_push($fields, ...static::feedFields($feature, $readonly));
            }
        }

        return $fields;
    }

    /**
     * Sotto l'interruttore di un feed (Meta, Google): ogni quanto si riscrive
     * e l'indirizzo da dare alla piattaforma. Si vedono a funzionalità accesa.
     *
     * @return list<\Wonder\App\ResourceSchema\Input>
     */
    private static function feedFields(array $feature, bool $readonly): array
    {
        $feed = ProductFeeds::forFeature($feature['key']);

        if ($feed === null || !$feature['created']) {
            return [];
        }

        $every = FormField::key($feature['key'].'_every')
            ->select(array_map('strval', ProductFeeds::EVERY))
            ->label('Aggiorna il feed')
            ->value((string) ProductFeeds::every($feed, $feature['settings']))
            ->visibleWhen($feature['key'], 'true');

        if ($readonly || !$feature['available']) {
            $every->disabled();
        }

        $url = FormField::key($feature['key'].'_url')
            ->text()
            ->label('Indirizzo del feed')
            ->value(ProductFeeds::url($feed))
            ->readonly()
            ->visibleWhen($feature['key'], 'true');

        return [$every, $url];
    }

    /** Le chiavi dei campi del feed sotto l'interruttore, vuote se non è un feed. @return list<string> */
    private static function feedKeys(string $feature): array
    {
        return ProductFeeds::forFeature($feature) === null ? [] : [$feature.'_every', $feature.'_url'];
    }

    public static function formLayoutSchema(): ?Form
    {
        $cards = [];

        foreach (FeaturePanel::byArea() as $area => $features) {
            $components = [SectionTitle::make((string) $area)->columnSpan(12)];

            foreach ($features as $feature) {
                $components[] = static::getInput($feature['key'])->columnSpan(12);

                foreach (static::feedKeys($feature['created'] ? $feature['key'] : '') as $key) {
                    $components[] = static::getInput($key)->columnSpan(12);
                }
            }

            $cards[] = (new Card)->components($components)->columns(12)->columnSpan(1);
        }

        // Due colonne che si riempiono dall'alto in basso: le aree hanno
        // altezze diverse e la griglia a righe lascerebbe buchi.
        return (new Form)->components([
            (new Container)->components($cards)->masonry(2)->columnSpan(12),
        ])->columns(12);
        
    }

    public static function pageSchema(): PageSchema
    {
        // Nessuna pagina CRUD: l'elenco automatico cercherebbe un model che non c'è.
        return parent::pageSchema()
            ->only([])
            ->titles(['form' => 'Funzionalità'])
            ->subtitles(['form' => 'Il gestionale è predisposto al massimo: qui si sblocca solo ciò che serve. Bloccare non cancella mai i dati.'])
            ->docs(Gestionale::docsUrl('primi-passi/funzionalita'), 'form');
    }

    public static function navigationSchema(): NavigationSchema
    {
        return NavigationSchema::for(static::class)
            ->section('set-up', 'Set Up', 'bi-gear', 1020, ['admin'])
            ->group('gestionale', 'Gestionale', 40)
            ->title('Funzionalità')
            ->order(20)
            ->access(Gestionale::area('impostazioni'));
    }

    /**
     * Salva gli interruttori: le regole delle dipendenze stanno in
     * `FeaturePanel`, qui si compone solo il messaggio.
     */
    public static function submitFormPage(array $values): string
    {
        $requested = [];
        $settings = [];

        $state = FeaturePanel::state();

        foreach (FeatureCatalog::all() as $key => $feature) {
            // L'interruttore staccato manda comunque "false" (campo nascosto):
            // una funzionalità non ancora costruita non si tocca, resta com'è.
            $requested[$key] = $feature['created']
                ? ($values[$key] ?? 'false') === 'true'
                : ($state[$key] ?? false);

            // Ogni quanto si riscrive il feed: solo una scelta dell'elenco.
            $every = (int) ($values[$key.'_every'] ?? 0);

            if (ProductFeeds::forFeature($key) !== null && isset(ProductFeeds::EVERY[$every])) {
                $settings[$key]['every'] = $every;
            }
        }

        $user = LegacyGlobals::get('USER');

        try {
            $changes = FeaturePanel::save($requested, is_object($user) ? (int) ($user->id ?? 0) : 0, $settings);
        } catch (UserError $error) {
            // G3.18: un rifiuto è un avviso, non il messaggio verde del salvataggio.
            if (headers_sent()) {
                return $error->getMessage();
            }

            FlashAlert::custom('Attenzione', $error->getMessage(), 'warning');
            header('Location: '.static::formUrl());
            exit();
        }

        return match (true) {
            $changes['unlocked'] !== [] && $changes['locked'] !== [] => 'Sbloccate: '.implode(', ', $changes['unlocked'])
                .'. Bloccate: '.implode(', ', $changes['locked']).'.',
            $changes['unlocked'] !== [] => 'Sbloccate: '.implode(', ', $changes['unlocked']).'.',
            $changes['locked'] !== [] => 'Bloccate: '.implode(', ', $changes['locked']).'.',
            default => 'Nessun cambiamento.',
        };
    }

    /** La pagina degli interruttori, dove si torna dopo un rifiuto. */
    private static function formUrl(): string
    {
        $fallback = '/backend/'.static::path();

        if (!function_exists('__r')) {
            return $fallback;
        }

        try {
            $named = (string) __r('backend.resource.'.static::slug().'.form');
        } catch (Throwable) {
            return $fallback;
        }

        return $named !== '' ? $named : $fallback;
    }

        /** Descrizione del catalogo, con le dipendenze e il modulo richiesto. */
    private static function describe(array $feature): string
    {
        $parts = [$feature['description']];

        if ($feature['requires'] !== []) {
            $parts[] = 'Richiede: '.implode(', ', $feature['requires']).'.';
        }

        if (!$feature['created']) {
            $parts[] = 'Non ancora disponibile.';
        }

        if (!$feature['available']) {
            $parts[] = 'Serve il modulo '.$feature['module'].'.';
        }

        $feed = $feature['created'] ? ProductFeeds::forFeature($feature['key']) : null;

        if ($feed !== null) {
            $written = ProductFeeds::writtenAt($feed);
            $parts[] = 'Accesa, il feed si aggiorna da solo: '
                .mb_strtolower(ProductFeeds::EVERY[ProductFeeds::every($feed, $feature['settings'])])
                .'. Ultimo aggiornamento: '.($written === null ? 'non ancora' : date('d/m/Y H:i', $written)).'.';
        }

        return trim(implode(' ', array_filter($parts)));
    }
}
