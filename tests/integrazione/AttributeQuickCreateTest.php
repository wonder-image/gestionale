<?php
/** php tests/integrazione/AttributeQuickCreateTest.php */
declare(strict_types=1);

const SITE = '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site';

chdir(SITE);
$GLOBALS['ROOT'] = SITE;
require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__ . '/../harness.php';

use Wonder\Api\Endpoint;
use Wonder\Api\Support\ResourceApiController;
use Wonder\Backend\Support\QuickCreateController;
use Wonder\Plugin\Gestionale\Models\Catalog\Attribute;
use Wonder\Plugin\Gestionale\Models\Catalog\AttributeValue;
use Wonder\Plugin\Gestionale\Resources\Catalog\AttributeResource;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Sql\Transaction;

/*
 * «Nuova caratteristica» dalla scheda prodotto, contro il database del sito:
 * lo stesso percorso dello store API (campi ammessi, preparazione, schema
 * della tabella, salvataggio), senza passare dalla rete e senza esportare
 * niente. Tutto si annulla alla fine.
 */

final class Annulla extends RuntimeException {}

$conta = static function (): int {
    $rows = Attribute::find(['deleted' => 'false']);

    if (!is_array($rows) || $rows === []) {
        return 0;
    }

    return isset($rows['id']) ? 1 : count(array_filter($rows, 'is_array'));
};

/** La posizione più alta tra gli attributi, 0 se non ce ne sono. */
$ultimaPosizione = static function (): int {
    $rows = Attribute::find([]);

    if (!is_array($rows) || $rows === []) {
        return 0;
    }

    $rows = isset($rows['id']) ? [$rows] : array_filter($rows, 'is_array');

    return array_reduce($rows, static fn (int $max, array $row): int => max($max, (int) ($row['position'] ?? 0)), 0);
};

$controller = (new ReflectionClass(ResourceApiController::class))->newInstanceWithoutConstructor();
(new ReflectionMethod(ResourceApiController::class, '__construct'))->invoke($controller, AttributeResource::class);

/** I valori che lo store API salverebbe per questa richiesta. */
$preparati = static function (array $richiesta) use ($controller): array {
    $endpoint = (new ReflectionClass(Endpoint::class))->newInstanceWithoutConstructor();
    $endpoint->data = $richiesta;

    return (new ReflectionMethod(ResourceApiController::class, 'preparedValues'))->invoke($controller, $endpoint, 'store', null);
};

$riga = static fn (int $id): array => (new ReflectionMethod(ResourceApiController::class, 'resourceRow'))->invoke($controller, $id, ['*']);

$prima = $conta();

try {
    Transaction::run(static function () use ($preparati, $riga, $ultimaPosizione): void {
        // Quello che il modal manda al proxy, più qualche campo che non gli spetta.
        $post = [
            'resource' => AttributeResource::slug(),
            'quick_label' => 'name',
            'name' => 'Spessore integrazione',
            'type' => 'number',
            'unit' => 'mm',
            'level_text' => 'model',
            'is_filterable' => 'true',
            'is_visible' => 'false',
            'level' => 'variant',
            'position' => 1,
            'values' => [['label' => 'Intruso']],
        ];

        $richiesta = QuickCreateController::payload($post);

        check('il modal non lascia vuoto nessun campo obbligatorio', fn () =>
            QuickCreateController::missingRequired(AttributeResource::class, $richiesta) === []
        );

        $posizioneAttesa = $ultimaPosizione() + 1;
        $valori = $preparati($richiesta);
        $risultato = Attribute::create($valori);
        $id = (int) ($risultato->insert_id ?? 0);

        check('la caratteristica nasce', fn () => $id > 0);

        $relazioni = AttributeResource::syncRepeaterRelations($id, $richiesta, [], 'store', 'api');
        $item = AttributeResource::appendRepeaterRelationsToItem($riga($id));
        $rapido = QuickCreateController::responseItem($item, $richiesta);

        check('nasce col suo codice e il suo slug', fn () =>
            trim((string) ($item['code'] ?? '')) !== '' && trim((string) ($item['slug'] ?? '')) !== ''
        );

        // Il nome passa dalla sistemazione della tabella, come dalla scheda dell'attributo.
        check('nasce Numero, sull\'articolo, con la sua unità', fn () =>
            ($item['name'] ?? '') === 'Spessore Integrazione'
            && ($item['type'] ?? '') === 'number'
            && ($item['level'] ?? '') === 'model'
            && ($item['unit'] ?? '') === 'mm'
        );

        check('nasce visibile e fuori dai filtri, qualunque cosa chieda la richiesta', fn () =>
            ($item['is_visible'] ?? '') === 'true' && ($item['is_filterable'] ?? '') === 'false'
        );

        check('va in fondo all\'elenco degli attributi', fn () => (int) ($item['position'] ?? 0) === $posizioneAttesa);

        check('non porta con sé nessun valore', function () use ($id, $relazioni, $item): bool {
            $valori = AttributeValue::find(['attribute_id' => $id]);

            return $relazioni === []
                && (!is_array($valori) || $valori === [])
                && ($item['values'] ?? null) === [];
        });

        check('la risposta della creazione rapida porta la riga salvata', fn () =>
            (int) ($rapido['id'] ?? 0) === $id
            && ($rapido['level'] ?? '') === 'model'
            && ($rapido['is_filterable'] ?? '') === 'false'
            && ($rapido['is_visible'] ?? '') === 'true'
            && ($rapido['name'] ?? '') === 'Spessore Integrazione'
        );

        check('un Elenco non nasce da qui', function () use ($preparati): bool {
            try {
                $preparati(['name' => 'Taglia', 'type' => 'select']);
            } catch (UserError $errore) {
                return $errore->key() === 'attribute.quick_type';
            }

            return false;
        });

        throw new Annulla();
    });
} catch (Annulla) {
}

check('alla fine non resta niente', fn () => $conta() === $prima);

summary();
