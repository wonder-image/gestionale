<?php

namespace Wonder\Plugin\Gestionale\Support\Stock;

use JsonException;
use Wonder\Backend\Support\FlashAlert;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Support\Contacts\SupplierNames;
use Wonder\Plugin\Gestionale\Models\Stock\StockDocument;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;

/**
 * L'invio della bozza di carico e scarico (G2b-bis 4.2), senza HTML: legge il
 * POST, chiama `StockDocuments` e dice com'è andata. Le pagine non scrivono
 * mai il documento.
 *
 * Le righe arrivano in un solo campo JSON (`lines`), mai un campo per numero:
 * 500 righe da due campi supererebbero `max_input_vars`.
 */
final class StockDocumentForm
{
    /**
     * Salva la bozza e, con `intent = confirm`, la conferma.
     *
     * Un documento nuovo nasce bozza anche quando la conferma poi si ferma:
     * `id` torna sempre, così la pagina riapre quella bozza con l'errore e un
     * secondo invio la aggiorna invece di crearne un'altra.
     *
     * @param array<string, mixed> $post
     * @return array{id: int, number: string, error: string}
     */
    public static function submit(string $type, int $id, array $post, int $userId): array
    {
        $result = ['id' => $id, 'number' => '', 'error' => ''];

        try {
            if ($id > 0) {
                self::assertType($id, $type);
            }

            $lines = self::decode($post['lines'] ?? null);
            $header = self::header($post);

            if ($id > 0) {
                StockDocuments::saveDraft($id, $header, $lines);
            } else {
                $id = StockDocuments::create($type, $header, $lines, ['user_id' => $userId]);
                $result['id'] = $id;
            }

            if ((string) ($post['intent'] ?? '') === 'confirm') {
                $result['number'] = StockDocuments::confirm($id, ['user_id' => $userId, 'source' => 'user']);
            }
        } catch (UserError $error) {
            $result['error'] = $error->getMessage();
        }

        return $result;
    }

    /**
     * Il campo `lines`: una lista JSON. Tutto il resto è un rifiuto, anche il
     * campo che manca: una pagina rotta non deve cancellare le righe salvate.
     *
     * @return list<array<string, mixed>>
     */
    public static function decode(mixed $raw): array
    {
        if (!is_string($raw) || trim($raw) === '') {
            throw UserError::make('stock_document.invalid_lines');
        }

        try {
            $lines = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw UserError::make('stock_document.invalid_lines');
        }

        if (!is_array($lines) || !array_is_list($lines)) {
            throw UserError::make('stock_document.invalid_lines');
        }

        return $lines;
    }

    /**
     * Le righe salvate come le disegna l'editor: la versione descritta (nome,
     * SKU, foto, giacenza della sede) più i pezzi in testo. Una riga la cui
     * versione non c'è più si toglie e si conta: la pagina lo dice.
     *
     * Nell'inventario la riga porta anche `expected`, l'atteso salvato (o
     * null): l'editor lo mostra finché il contato e la sede restano quelli.
     *
     * G3: con *Lotti e scadenze* lotto e scadenza del carico e il lotto
     * scelto nello scarico (con il suo numero, anche se oggi non ha più
     * pezzi); con *Acquisti* il fornitore scelto, con il nome. Spente, le
     * chiavi non ci sono.
     *
     * @param list<array{product_id: int, quantity: ?float, expected_quantity?: ?float}> $items
     * @return array{lines: list<array<string, mixed>>, dropped: int}
     */
    public static function editorLines(array $items, int $locationId): array
    {
        $ids = array_map(static fn (array $item): int => (int) $item['product_id'], $items);
        $versions = $ids === [] ? [] : StockVersionSearch::describe($ids, $locationId);
        $lines = [];
        $dropped = 0;
        $useBatches = Gestionale::feature('batch_tracking');
        $useSuppliers = Gestionale::feature('purchasing');
        $batches = $useBatches ? StockVersionSearch::batches(array_map(static fn (array $item): int => (int) ($item['batch_id'] ?? 0), $items)) : [];

        foreach ($items as $item) {
            $version = $versions[(int) $item['product_id']] ?? null;

            if ($version === null) {
                $dropped++;

                continue;
            }

            $expected = $item['expected_quantity'] ?? null;
            $cost = $item['unit_cost'] ?? null;
            $lines[] = $version + [
                'quantity' => self::quantityText($item['quantity']),
                'expected' => $expected === null ? null : round((float) $expected, 3),
                // G3, Acquisti: il costo salvato, come si scrive nella casella («2,5»).
                'cost' => $cost === null ? '' : str_replace('.', ',', (string) (round((float) $cost, 4) + 0)),
            ];
            $batchId = (int) ($item['batch_id'] ?? 0);
            $supplierId = (int) ($item['supplier_id'] ?? 0);

            if ($useBatches) {
                $lines[array_key_last($lines)] += [
                    'batch_number' => (string) ($item['batch_number'] ?? ''),
                    'batch_expiry' => (string) ($item['batch_expiry'] ?? ''),
                    'batch_id' => $batchId,
                    'batch_label' => $batches[$batchId]['number'] ?? '',
                ];
            }

            if ($useSuppliers) {
                $lines[array_key_last($lines)] += ['supplier_id' => $supplierId, 'supplier_label' => SupplierNames::of($supplierId)];
            }
        }

        return ['lines' => $lines, 'dropped' => $dropped];
    }

    /** I pezzi come si scrivono nella casella: vuoto, `3`, `2,5`. */
    public static function quantityText(?float $quantity): string
    {
        if ($quantity === null) {
            return '';
        }

        $quantity = round($quantity, 3) + 0.0;

        if ($quantity === round($quantity)) {
            return (string) (int) $quantity;
        }

        return rtrim(rtrim(number_format($quantity, 3, ',', ''), '0'), ',');
    }

    /**
     * Il messaggio e il redirect dopo un invio. Un rifiuto è una frase, mai
     * una pagina d'errore.
     *
     * @param array{ok: bool, message: string, url: string} $result
     */
    public static function respond(array $result): never
    {
        if ($result['ok']) {
            FlashAlert::saved($result['message']);
        } else {
            FlashAlert::custom('Attenzione', $result['message'], 'warning');
        }

        if (!headers_sent()) {
            header('Location: '.$result['url']);
        }

        exit();
    }

    /**
     * Sede, causale e nota: solo i campi inviati, così una chiave che manca
     * tiene il valore di prima (`saveDraft()`). La causale del carico la
     * ignorano le regole.
     *
     * @param array<string, mixed> $post
     * @return array{location_id?: int, to_location_id?: int, reason?: string, note?: string, supplier_id?: string, supplier_document_number?: string, supplier_document_date?: string}
     */
    private static function header(array $post): array
    {
        $header = [];

        if (array_key_exists('location_id', $post)) {
            $header['location_id'] = (int) $post['location_id'];
        }

        // G3.16: la sede d'arrivo del trasferimento.
        if (array_key_exists('to_location_id', $post) && is_scalar($post['to_location_id'])) {
            $header['to_location_id'] = (int) $post['to_location_id'];
        }

        // Il fornitore del carico (G3.7): la pagina li manda solo con «Acquisti».
        foreach (['reason', 'note', 'supplier_id', 'supplier_document_number', 'supplier_document_date'] as $key) {
            if (array_key_exists($key, $post) && is_scalar($post[$key])) {
                $header[$key] = (string) $post[$key];
            }
        }

        return $header;
    }

    private static function assertType(int $id, string $type): void
    {
        $document = StockDocument::findById($id);

        if (!is_array($document) || $document === []
            || (string) ($document['deleted'] ?? 'false') === 'true'
            || (string) ($document['type'] ?? '') !== $type) {
            throw UserError::make('stock_document.not_found');
        }
    }
}
