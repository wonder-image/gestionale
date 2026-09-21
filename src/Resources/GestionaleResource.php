<?php

namespace Wonder\Plugin\Gestionale\Resources;

use Throwable;
use Wonder\App\Resource;
use Wonder\App\ResourceSchema\ApiSchema;
use Wonder\App\ResourceSchema\NavigationSchema;
use Wonder\App\ResourceSchema\PageSchema;
use Wonder\Elements\Components\Card;
use Wonder\Elements\Components\SectionTitle;
use Wonder\Plugin\Gestionale\Gestionale;

/**
 * Resource base del gestionale. Ogni pagina dichiara la funzionalità che la
 * governa (`$feature`, vuoto = sempre attiva) e la pagina della guida
 * commercianti (`$docsPage`). Il controllo della funzionalità su menu, pagine e
 * API arriva con il piano 2, quando esiste lo stato delle funzionalità.
 */
abstract class GestionaleResource extends Resource
{
    public static string $feature = '';
    public static string $docsPage = '';

    /** Quale guida apre il pulsante: `user` (commerciante) o `dev` (sviluppatore). */
    public static string $docsSpace = 'user';

    /** Vero se la pagina non dipende da nessuna funzionalità o se quella dichiarata è attiva. */
    public static function featureActive(): bool
    {
        return static::$feature === '' || Gestionale::feature(static::$feature);
    }

    public static function pageSchema(): PageSchema
    {
        return static::withFeature(static::withDocs(PageSchema::for(static::class)));
    }

    public static function navigationSchema(): NavigationSchema
    {
        return parent::navigationSchema()->enabled(static::featureActive());
    }

    public static function apiSchema(): ApiSchema
    {
        $schema = parent::apiSchema();

        return static::featureActive() ? $schema : $schema->enabled(false);
    }

    /** Con la funzionalità bloccata la Resource non ha nessuna pagina. */
    protected static function withFeature(PageSchema $schema): PageSchema
    {
        return static::featureActive() ? $schema : $schema->only([]);
    }

    /**
     * Il riquadro di quello che si tocca di rado: in fondo alla pagina.
     *
     * Doveva chiudersi a fisarmonica. `Accordion` esiste nel core, si apre e si
     * chiude, ma dentro un form non regge la griglia: i campi del form prendono
     * le classi del tema Wonder (`col-6`), mentre l'accordion è disegnato dal
     * tema Bootstrap, che si aspetta `col-span-6`. I due non si parlano, e i
     * campi finiscono ammassati in una striscia.
     *
     * Farli parlare vuol dire mettere le mani nel rendering del core, che è un
     * lavoro suo e non di questa scheda. Finché non si fa, un riquadro normale:
     * sta in fondo, dove non disturba.
     */
    protected static function foldable(string $title, array $components, string $tooltip = ''): object
    {
        $title = SectionTitle::make($title)->columnSpan(12);

        if ($tooltip !== '') {
            $title->tooltip($tooltip);
        }

        return (new Card)->components([$title, ...$components])->columns(12)->columnSpan(12);
    }

    /** Aggiunge il pulsante "Guida" se la Resource dichiara una pagina. */
    protected static function withDocs(PageSchema $schema): PageSchema
    {
        $url = Gestionale::docsUrl(static::$docsPage, static::$docsSpace);

        return $url === '' ? $schema : $schema->docs($url);
    }

    /**
     * L'id della riga aperta, quando la pagina ne ha una.
     *
     * Il form si dichiara con metodi statici, che non ricevono la riga: per
     * sapere cosa si sta modificando resta l'indirizzo. L'ultimo pezzo del
     * percorso della Resource identifica la rotta
     * (`.../gestionale/attributi/12/edit/`).
     */
    protected static function currentId(): ?int
    {
        $id = (int) ($_GET['id'] ?? 0);

        if ($id > 0) {
            return $id;
        }

        $segment = basename(static::path());
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');

        if (preg_match('#/'.preg_quote($segment, '#').'/(\d+)/#', $uri, $matches) === 1) {
            return (int) $matches[1];
        }

        return null;
    }

    /**
     * Le righe vive di un Model, sempre come lista.
     *
     * Senza database (test degli schemi, convenzioni) torna vuoto invece di far
     * esplodere il form: lì servono le voci di un select, non i dati.
     *
     * @param class-string<\Wonder\App\Model> $modelClass
     * @param array<string, mixed> $where
     * @return list<array<string, mixed>>
     */
    protected static function rowsOf(
        string $modelClass,
        array $where = [],
        ?string $order = null,
        string $direction = 'ASC'
    ): array {
        try {
            $rows = $modelClass::find(
                array_merge(['deleted' => 'false'], $where),
                null,
                $order,
                $order === null ? null : $direction
            );
        } catch (Throwable) {
            return [];
        }

        if (!is_array($rows) || $rows === []) {
            return [];
        }

        return isset($rows['id']) ? [$rows] : array_values(array_filter($rows, 'is_array'));
    }
}
