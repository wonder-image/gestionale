<?php

namespace Wonder\Plugin\Gestionale\Support\Stock;

use Wonder\App\Models\User\User;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Stock\StockDocumentStatusLog;

/**
 * Quello che mostra la scheda di un documento confermato o annullato
 * (G2b-bis 4.2), già pronto da scrivere: il template non legge il database.
 *
 * Le versioni si leggono una volta sola, eliminate comprese, perché il
 * documento è storia; chi e quando vengono dal log degli stati.
 */
final class StockDocumentSheet
{
    /**
     * Nell'inventario ogni riga ha anche attesi, differenza e il suo segno
     * (`up`, `down`, `''`); una riga non contata ha «—» nelle tre celle.
     *
     * @param array<string, mixed> $document una riga di `gst_stock_documents`
     * @return array{lines: list<array{label: string, sku: string, quantity: string, expected: string, difference: string, tone: string}>, total: string, confirmed: string, cancelled: string, location: string, reason: string, note: string}
     */
    public static function for(array $document): array
    {
        $id = (int) ($document['id'] ?? 0);
        $items = $id > 0 ? StockDocuments::items($id) : [];
        $counting = (string) ($document['type'] ?? '') === 'stocktake';
        $pieces = 0.0;
        $counted = 0;
        $differing = 0;

        foreach ($items as $item) {
            $pieces += (float) ($item['quantity'] ?? 0);

            if ($item['quantity'] !== null) {
                $counted++;
                $differing += self::difference($item) != 0 ? 1 : 0;
            }
        }

        $people = self::people($id);

        return [
            'lines' => self::lines($items, $counting),
            'total' => $counting ? StockDocumentEditor::stocktakeTotals(count($items), $counted, $differing) : StockDocumentEditor::totals(count($items), $pieces),
            'confirmed' => $people['completed'] ?? '',
            'cancelled' => $people['cancelled'] ?? '',
            'location' => self::location((int) ($document['location_id'] ?? 0)),
            'reason' => (string) ($document['type'] ?? '') === 'issue' ? Reasons::label((string) ($document['reason'] ?? '')) : '',
            'note' => self::text((string) ($document['note'] ?? '')),
        ];
    }

    /**
     * @param list<array{product_id: int, quantity: ?float, expected_quantity?: ?float}> $items
     * @return list<array{label: string, sku: string, quantity: string, expected: string, difference: string, tone: string}>
     */
    private static function lines(array $items, bool $counting): array
    {
        $ids = array_values(array_unique(array_map(static fn (array $item): int => (int) $item['product_id'], $items)));
        $products = [];

        if ($ids !== []) {
            // `deleted` nella condizione: il modello non aggiunge il suo filtro
            // e le versioni eliminate dopo la conferma tengono il loro nome.
            foreach (self::rows(Product::find('`id` IN ('.implode(', ', $ids).") AND `deleted` IN ('true', 'false')")) as $product) {
                $products[(int) $product['id']] = $product;
            }
        }

        $models = ProductNames::models($products);

        return array_map(static function (array $item) use ($products, $models, $counting): array {
            $product = $products[(int) $item['product_id']] ?? null;
            $line = [
                'label' => $product === null ? 'Versione eliminata' : self::text(ProductNames::full($product, $models)),
                'sku' => $product === null ? '' : self::text((string) ($product['sku'] ?? '')),
                'quantity' => StockDocumentForm::quantityText($item['quantity']),
                'expected' => '',
                'difference' => '',
                'tone' => '',
            ];

            if (!$counting) {
                return $line;
            }

            if ($item['quantity'] === null) {
                return ['quantity' => '—', 'expected' => '—', 'difference' => '—'] + $line;
            }

            $difference = self::difference($item);

            return [
                'expected' => StockDocumentForm::quantityText((float) ($item['expected_quantity'] ?? 0)),
                'difference' => ($difference > 0 ? '+' : ($difference < 0 ? '−' : '')).StockDocumentForm::quantityText(abs($difference)),
                'tone' => $difference > 0 ? 'up' : ($difference < 0 ? 'down' : ''),
            ] + $line;
        }, $items);
    }

    /** Contati − attesi, a tre decimali (come il movimento della conferma). */
    private static function difference(array $item): float
    {
        return round((float) $item['quantity'] - (float) ($item['expected_quantity'] ?? 0), 3) + 0.0;
    }

    /**
     * «Confermato da Mario il 24/09/2026», «Annullato da Luca il 25/09/2026»:
     * una query sul log e una sugli utenti. Senza utente, senza «da».
     *
     * @return array<string, string> stato → frase
     */
    private static function people(int $id): array
    {
        if ($id <= 0) {
            return [];
        }

        $logs = self::rows(StockDocumentStatusLog::find(
            '`stock_document_id` = '.$id." AND `to_value` IN ('completed', 'cancelled') AND `deleted` = 'false'",
            null,
            'id',
            'ASC'
        ));
        $userIds = array_values(array_unique(array_filter(array_map(static fn (array $log): int => (int) ($log['user_id'] ?? 0), $logs))));
        $names = [];

        if ($userIds !== []) {
            // Eliminati compresi: chi ha confermato resta nella storia del documento.
            foreach (self::rows(User::find('`id` IN ('.implode(', ', $userIds).") AND `deleted` IN ('true', 'false')")) as $user) {
                $name = trim(trim((string) ($user['name'] ?? '')).' '.trim((string) ($user['surname'] ?? '')));
                $names[(int) $user['id']] = self::text($name !== '' ? $name : (string) ($user['username'] ?? ''));
            }
        }

        $people = [];

        foreach ($logs as $log) {
            $status = (string) $log['to_value'];
            $who = $names[(int) ($log['user_id'] ?? 0)] ?? '';
            $time = strtotime((string) ($log['creation'] ?? ''));

            $people[$status] = ($status === 'completed' ? 'Confermato' : 'Annullato')
                .($who !== '' ? ' da '.$who : '')
                .($time !== false ? ' il '.date('d/m/Y', $time) : '');
        }

        return $people;
    }

    /** Il nome della sede, solo da due sedi in su (come la colonna dell'elenco). */
    private static function location(int $locationId): string
    {
        $shown = Locations::shown();

        if (count($shown) < 2) {
            return '';
        }

        foreach ($shown as $location) {
            if ((int) $location['id'] === $locationId) {
                return $location['label'];
            }
        }

        return '';
    }

    /** Il testo salvato com'era scritto: il template lo escapa una volta sola. */
    private static function text(string $value): string
    {
        return trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /** @return list<array<string, mixed>> */
    private static function rows(mixed $rows): array
    {
        if (!is_array($rows) || $rows === []) {
            return [];
        }

        return isset($rows['id']) ? [$rows] : array_values(array_filter($rows, 'is_array'));
    }
}
