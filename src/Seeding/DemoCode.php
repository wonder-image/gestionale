<?php

namespace Wonder\Plugin\Gestionale\Seeding;

use InvalidArgumentException;
use Wonder\Plugin\Gestionale\Models\Catalog\Attribute;
use Wonder\Plugin\Gestionale\Models\Catalog\Brand;
use Wonder\Plugin\Gestionale\Models\Catalog\Category;
use Wonder\Plugin\Gestionale\Models\Catalog\Package;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Catalog\Tag;
use Wonder\Plugin\Gestionale\Models\Contacts\Contact;
use Wonder\Support\Text\Slug as TextSlug;

/**
 * Il segno che distingue una riga dei dati di prova.
 *
 * Sta nella colonna `code`, con il prefisso dell'entità: `cat_demo-accessori`,
 * `con_demo-bianchi`. Il codice non si modifica più dopo l'inserimento,
 * mentre il nome si cambia dal gestionale: con il segno nel nome la pulizia
 * perderebbe le righe rinominate. Un codice vero (prefisso e sette caratteri
 * senza trattino) non ha mai questa forma.
 *
 * I dati di prova cercano ogni riga in quest'ordine: prima quella col segno,
 * poi una riga vera con lo stesso nome, che si usa così com'è e non si segna
 * né si cancella mai; se non c'è nessuna delle due, la creano col segno.
 */
final class DemoCode
{
    /** Quello che sta fra il prefisso dell'entità e il riferimento. */
    public const MARK = 'demo-';

    /**
     * I nomi delle righe create dai dati di prova prima del segno, quando si
     * riconoscevano da `Prova ` davanti al nome. La pulizia le toglie ancora,
     * con le stesse regole delle righe segnate.
     *
     * Si può togliere quando nessun sito ha più quelle righe: basta un
     * `php forge gestionale:demo --fresh` dopo l'aggiornamento.
     */
    public const LEGACY_NAMES = [
        Brand::class => ['Prova Marchio'],
        Category::class => ['Prova Abbigliamento', 'Prova Magliette', 'Prova Accessori'],
        Tag::class => ['Prova Novità', 'Prova Saldi'],
        Attribute::class => ['Prova Colore', 'Prova Taglia', 'Prova Materiale'],
        Package::class => ['Prova Busta imbottita', 'Prova Scatola media'],
        ProductModel::class => [
            'Prova Cappello di lana',
            'Prova Maglietta girocollo',
            'Prova Felpa con cappuccio',
            'Prova Calzini a costine',
        ],
        // Il privato aveva il prefisso nel cognome, le aziende nella ragione sociale.
        Contact::class => ['Prova Bianchi', 'Prova Rossi Srl', 'Prova Filati Nord Spa', 'Prova Imballaggi Sud Srl'],
    ];

    /** Come si chiama una riga nei messaggi del comando. */
    private const KINDS = [
        Brand::class => 'marchio',
        Category::class => 'categoria',
        Tag::class => 'tag',
        Attribute::class => 'attributo',
        Package::class => 'imballaggio',
        ProductModel::class => 'articolo',
        Contact::class => 'scheda',
    ];

    /** `cat_` e `accessori` fanno `cat_demo-accessori`. */
    public static function make(string $prefix, string $ref): string
    {
        $prefix = strtolower(trim($prefix));

        if (preg_match('/^[a-z]+_$/', $prefix) !== 1) {
            throw new InvalidArgumentException("Prefisso del codice non valido: «{$prefix}».");
        }

        $ref = str_replace('_', '-', TextSlug::make($ref));
        $ref = trim((string) preg_replace('/-+/', '-', $ref), '-');

        if ($ref === '') {
            throw new InvalidArgumentException('Il riferimento di un dato di prova non può essere vuoto.');
        }

        return $prefix.self::MARK.$ref;
    }

    /**
     * Il segno per una riga di quel Model, col prefisso letto dal suo schema.
     *
     * @param class-string<\Wonder\App\Model> $modelClass
     */
    public static function forModel(string $modelClass, string $ref): string
    {
        return self::make(self::prefixOf($modelClass), $ref);
    }

    /** Il prefisso del codice di quel Model, `''` se non ne ha. */
    public static function prefixOf(string $modelClass): string
    {
        foreach ($modelClass::dataSchema() as $field) {
            if ((string) $field->key === 'code') {
                return (string) (($field->getSchema('unique_code')['prefix']) ?? '');
            }
        }

        return '';
    }

    /** Il codice è quello di un dato di prova? */
    public static function is(string $code): bool
    {
        return preg_match('/^[a-z]+_'.preg_quote(self::MARK, '/').'[a-z0-9]+(?:-[a-z0-9]+)*$/', $code) === 1;
    }

    /** `cat_demo-accessori` dà `accessori`; `''` per un codice vero. */
    public static function ref(string $code): string
    {
        if (!self::is($code)) {
            return '';
        }

        return substr($code, strpos($code, '_'.self::MARK) + 1 + strlen(self::MARK));
    }

    /**
     * Due nomi sono lo stesso nome?
     *
     * Senza badare a maiuscole e spazi, e con le entità HTML sciolte: il
     * gestionale salva «Magliette e felpe» come `Magliette E Felpe`, e un nome
     * arrivato dal form può avere `&agrave;` al posto della `à`.
     */
    public static function sameName(string $a, string $b): bool
    {
        $a = self::normalName($a);

        return $a !== '' && $a === self::normalName($b);
    }

    /**
     * La riga da usare fra quelle date: prima quella col segno, poi una vera
     * con lo stesso nome, `null` quando va creata.
     *
     * `demo` dice se la riga è dei dati di prova: quando è `false` la riga è
     * vera, e la pulizia non deve toccarla.
     *
     * @param list<array<string, mixed>> $rows
     * @param (callable(array<string, mixed>): string)|null $nameOf come si legge
     *        il nome di una riga; di solito la colonna `name`
     * @return array{id: int, demo: bool}|null
     */
    public static function pick(array $rows, string $code, string $name, ?callable $nameOf = null): ?array
    {
        $nameOf ??= static fn (array $row): string => (string) ($row['name'] ?? '');

        foreach ($code === '' ? [] : $rows as $row) {
            if (is_array($row) && (string) ($row['code'] ?? '') === $code && (int) ($row['id'] ?? 0) > 0) {
                return ['id' => (int) $row['id'], 'demo' => true];
            }
        }

        foreach ($rows as $row) {
            // Un altro dato di prova non è una riga vera da riusare.
            if (!is_array($row) || (int) ($row['id'] ?? 0) <= 0 || self::is((string) ($row['code'] ?? ''))) {
                continue;
            }

            if (self::sameName($nameOf($row), $name)) {
                return ['id' => (int) $row['id'], 'demo' => false];
            }
        }

        return null;
    }

    /** Il nome è di una riga dei vecchi dati di prova? */
    public static function isLegacy(string $modelClass, string $name): bool
    {
        foreach (self::LEGACY_NAMES[$modelClass] ?? [] as $legacy) {
            if (self::sameName($legacy, $name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * La riga è dei dati di prova, col segno o con un vecchio nome? Sono le
     * sole righe che la pulizia può togliere.
     */
    public static function ours(string $modelClass, string $code, string $name): bool
    {
        return self::is($code) || self::isLegacy($modelClass, $name);
    }

    /** `categoria «Accessori»`, per i messaggi del comando. */
    public static function label(string $modelClass, string $name): string
    {
        $name = trim(html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return (self::KINDS[$modelClass] ?? 'riga').' «'.$name.'»';
    }

    /**
     * Il messaggio per le righe di prova che la pulizia ha lasciato perché
     * qualcosa di vero le usa ancora.
     *
     * @param list<string> $labels come le scrive `label()`
     */
    public static function keptNote(array $labels): string
    {
        $count = count($labels);

        if ($count === 0) {
            return '';
        }

        $list = implode(', ', $labels);

        return $count === 1
            ? "Resta al suo posto 1 dato di prova ancora in uso: {$list}."
            : "Restano al loro posto {$count} dati di prova ancora in uso: {$list}.";
    }

    /**
     * Il messaggio per le righe vere che i dati di prova hanno usato invece
     * di crearne di nuove.
     *
     * @param list<string> $labels come le scrive `label()`
     */
    public static function reusedNote(array $labels): string
    {
        $count = count($labels);

        if ($count === 0) {
            return '';
        }

        $list = implode(', ', $labels);

        return $count === 1
            ? "Usato 1 dato già presente con lo stesso nome, che la pulizia non toccherà: {$list}."
            : "Usati {$count} dati già presenti con lo stesso nome, che la pulizia non toccherà: {$list}.";
    }

    private static function normalName(string $name): string
    {
        $name = html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));

        return mb_strtolower($name, 'UTF-8');
    }
}
