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
use Wonder\Http\Route;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Support\Features\FeaturePanel;

/**
 * Pagina "Funzionalità": un interruttore per funzionalità, raggruppati per
 * area. Non è un elenco CRUD — le righe di `gst_features` sono lo stato di un
 * catalogo che vive nel codice — quindi la pagina dichiara form e layout qui e
 * il salvataggio passa dall'handler del modulo.
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

        return (new Form)->components([
            (new Container)->components($cards)->columns(2)->columnSpan(12),
        ])->columns(12);
    }

    public static function pageSchema(): PageSchema
    {
        // Nessuna pagina CRUD: l'elenco automatico cercherebbe un model che non c'è.
        return parent::pageSchema()->only([])->docs(Gestionale::docsUrl('funzionalita'));
    }

    public static function navigationSchema(): NavigationSchema
    {
        return NavigationSchema::for(static::class)
            ->section('set-up', 'Set Up', 'bi-gear', 1020, ['admin'])
            ->title('Funzionalità')
            ->order(20)
            ->authority(['admin']);
    }

    /** La pagina risponde sullo stesso indirizzo in lettura e in salvataggio. */
    public static function registerBackendRoutes(string $rootApp, string $slug): void
    {
        $handler = Gestionale::handlerPath('backend/features.php');

        Route::get('/', $handler)->name('page')->permit(['admin']);
        Route::post('/', $handler)->name('save')->permit(['admin']);
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
