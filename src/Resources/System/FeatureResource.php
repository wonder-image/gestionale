<?php

namespace Wonder\Plugin\Gestionale\Resources\System;

use Wonder\App\Environment;
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
use Wonder\Plugin\Gestionale\Support\Features\FeatureCatalog;
use Wonder\Plugin\Gestionale\Support\Features\FeaturePanel;

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
                // non è abilitato non si può sbloccare.
                if ($readonly || !$feature['available']) {
                    $field->disabled();
                }

                $fields[] = $field;
            }
        }

        return $fields;
    }

    public static function formLayoutSchema(): ?Form
    {
        $cards = [];

        foreach (FeaturePanel::byArea() as $area => $features) {
            $components = [SectionTitle::make((string) $area)->columnSpan(12)];

            foreach ($features as $feature) {
                $components[] = static::getInput($feature['key'])->columnSpan(12);
            }

            $cards[] = (new Card)->components($components)->columns(12)->columnSpan(1);
        }

        // Una card sotto l'altra: le aree hanno altezze diverse e affiancarle
        // lascerebbe buchi.
        return (new Form)->components([
            (new Container)->components($cards)->columns(1)->columnSpan(12),
        ])->columns(12);
        
    }

    public static function pageSchema(): PageSchema
    {
        // Nessuna pagina CRUD: l'elenco automatico cercherebbe un model che non c'è.
        return parent::pageSchema()
            ->only([])
            ->titles(['form' => 'Funzionalità'])
            ->subtitles(['form' => 'Il gestionale è predisposto al massimo: qui si sblocca solo ciò che serve. Bloccare non cancella mai i dati.'])
            ->docs(Gestionale::docsUrl('funzionalita'), 'form');
    }

    public static function navigationSchema(): NavigationSchema
    {
        return NavigationSchema::for(static::class)
            ->section('set-up', 'Set Up', 'bi-gear', 1020, ['admin'])
            ->title('Funzionalità')
            ->order(20)
            ->authority(['admin']);
    }

    /**
     * Salva gli interruttori: le regole delle dipendenze stanno in
     * `FeaturePanel`, qui si compone solo il messaggio.
     */
    public static function submitFormPage(array $values): string
    {
        $requested = [];

        foreach (array_keys(FeatureCatalog::all()) as $key) {
            // L'interruttore staccato manda comunque "false" (campo nascosto).
            $requested[$key] = ($values[$key] ?? 'false') === 'true';
        }

        $user = LegacyGlobals::get('USER');
        $changes = FeaturePanel::save($requested, is_object($user) ? (int) ($user->id ?? 0) : 0);

        return match (true) {
            $changes['unlocked'] !== [] && $changes['locked'] !== [] => 'Sbloccate: '.implode(', ', $changes['unlocked'])
                .'. Bloccate: '.implode(', ', $changes['locked']).'.',
            $changes['unlocked'] !== [] => 'Sbloccate: '.implode(', ', $changes['unlocked']).'.',
            $changes['locked'] !== [] => 'Bloccate: '.implode(', ', $changes['locked']).'.',
            default => 'Nessun cambiamento.',
        };
    }

    /** Descrizione del catalogo, con le dipendenze e il modulo richiesto. */
    private static function describe(array $feature): string
    {
        $parts = [$feature['description']];

        if ($feature['requires'] !== []) {
            $parts[] = 'Richiede: '.implode(', ', $feature['requires']).'.';
        }

        if (!$feature['available']) {
            $parts[] = 'Serve il modulo '.$feature['module'].'.';
        }

        return trim(implode(' ', array_filter($parts)));
    }
}
