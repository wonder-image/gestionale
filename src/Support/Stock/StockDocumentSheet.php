<?php

namespace Wonder\Plugin\Gestionale\Support\Stock;

use Wonder\App\Models\User\User;
use Wonder\Plugin\Gestionale\Support\Contacts\Contacts;
use Wonder\Plugin\Gestionale\Support\Contacts\SupplierNames;
use Wonder\Plugin\Gestionale\Models\Contacts\Contact;
use Wonder\Plugin\Gestionale\Gestionale;
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
     * Nel carico con «Acquisti» ogni riga ha anche `cost` e `line_total`, e
     * `supplier` dice fornitore e documento.
     *
     * G3: `source` dice lotto e scadenza del carico, lotto e fornitore scelti
     * a mano nello scarico; vuota con le funzionalità spente o in automatico.
     *
     * @return array{lines: list<array{label: string, sku: string, quantity: string, expected: string, difference: string, tone: string, cost: string, line_total: string}>, total: string, costs: bool, supplier: string, confirmed: string, cancelled: string, location: string, reason: string, note: string}
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

        $people = self::people($id, (string) ($document['type'] ?? ''));
        // G3, Acquisti: fornitore e costi del carico, solo con la funzionalità.
        $costs = (string) ($document['type'] ?? '') === 'receipt' && Gestionale::feature('purchasing');
        $total = $counting ? StockDocumentEditor::stocktakeTotals(count($items), $counted, $differing) : StockDocumentEditor::totals(count($items), $pieces);

        return [
            'lines' => self::lines($items, $counting, $costs, (string) ($document['type'] ?? '')),
            'total' => $costs ? $total.' · '.StockValue::money((float) ($document['total_cost'] ?? 0)) : $total,
            'costs' => $costs,
            'supplier' => $costs ? self::supplier($document) : '',
            'sent' => $people['in_transit'] ?? '',
            'confirmed' => $people['completed'] ?? '',
            'cancelled' => $people['cancelled'] ?? '',
            'location' => (string) ($document['type'] ?? '') === 'transfer'
                ? self::route((int) ($document['location_id'] ?? 0), (int) ($document['to_location_id'] ?? 0))
                : self::location((int) ($document['location_id'] ?? 0)),
            'reason' => (string) ($document['type'] ?? '') === 'issue' ? Reasons::label((string) ($document['reason'] ?? '')) : '',
            'note' => self::text((string) ($document['note'] ?? '')),
        ];
    }

    /**
     * @param list<array{product_id: int, quantity: ?float, expected_quantity?: ?float}> $items
     * @return list<array{label: string, sku: string, quantity: string, expected: string, difference: string, tone: string, source: string}>
     */
    private static function lines(array $items, bool $counting, bool $costs = false, string $type = ''): array
    {
        $useBatches = Gestionale::feature('batch_tracking') && in_array($type, ['receipt', 'issue', 'transfer'], true);
        $useSuppliers = Gestionale::feature('purchasing') && in_array($type, ['issue', 'transfer'], true);
        $batches = $useBatches ? StockVersionSearch::batches(array_map(static fn (array $item): int => (int) ($item['batch_id'] ?? 0), $items)) : [];
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

        return array_map(static function (array $item) use ($products, $models, $counting, $costs, $useBatches, $useSuppliers, $batches): array {
            $product = $products[(int) $item['product_id']] ?? null;
            $line = [
                'label' => $product === null ? 'Versione eliminata' : self::text(ProductNames::full($product, $models)),
                'sku' => $product === null ? '' : self::text((string) ($product['sku'] ?? '')),
                'quantity' => StockDocumentForm::quantityText($item['quantity']),
                'expected' => '',
                'difference' => '',
                'tone' => '',
                'cost' => '',
                'line_total' => '',
                'cost_note' => '',
                'source' => self::source($item, $useBatches ? $batches : null, $useSuppliers),
            ];

            if ($costs) {
                $cost = $item['unit_cost'] ?? null;
                $line['cost'] = $cost === null ? '—' : StockValue::money((float) $cost);
                $line['line_total'] = $cost === null || $item['quantity'] === null ? '—' : StockValue::money(round((float) $cost * (float) $item['quantity'], 2));
                // Scritto IVA inclusa: il costo è l'imponibile, sotto quello scritto e l'aliquota.
                $line['cost_note'] = self::costNote($item);
            }

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

    /** «Scritto 1,22 € · IVA 22%» per la riga scritta IVA inclusa, vuoto altrimenti. */
    private static function costNote(array $item): string
    {
        $entered = $item['entered_cost'] ?? null;

        if ($entered === null || ($item['tax_rate'] ?? null) === null) {
            return '';
        }

        $rate = (float) $item['tax_rate'];

        return 'Scritto '.StockValue::money((float) $entered).' · '.($rate > 0
            ? 'IVA '.rtrim(rtrim(number_format($rate, 2, ',', ''), '0'), ',').'%'
            : 'senza aliquota');
    }

    /**
     * «Lotto L24 · scade il 01/12/2026», «Lotto L7 · Fornitore Rossi»: quello
     * che la riga dice da sé. Il numero del carico è sulla riga; quello del
     * lotto scelto nello scarico si legge dal lotto.
     *
     * @param array<int, array{number: string, expiry: string}>|null $batches null = senza lotti
     * @param bool $suppliers con la scelta del fornitore (scarico con *Acquisti*)
     */
    private static function source(array $item, ?array $batches, bool $suppliers): string
    {
        $parts = [];
        $batchId = (int) ($item['batch_id'] ?? 0);
        $number = trim((string) ($item['batch_number'] ?? ''));
        $expiry = (string) ($item['batch_expiry'] ?? '');

        if ($batches !== null) {
            $number = $number !== '' ? $number : ($batches[$batchId]['number'] ?? '');
            $expiry = $expiry !== '' ? $expiry : ($batches[$batchId]['expiry'] ?? '');

            if ($number !== '') {
                $parts[] = 'Lotto '.$number;
            }

            if ($number !== '' && $expiry !== '') {
                $parts[] = 'scade il '.date('d/m/Y', (int) strtotime($expiry));
            }
        }

        $supplierId = (int) ($item['supplier_id'] ?? 0);

        if ($suppliers && $supplierId > 0) {
            $parts[] = 'Fornitore '.SupplierNames::of($supplierId);
        }

        return self::text(implode(' · ', $parts));
    }

    /**
     * «Fornitore Rossi · FT 12 del 10/10/2026»; senza fornitore stringa vuota.
     *
     * @param array<string, mixed> $document
     */
    private static function supplier(array $document): string
    {
        $id = (int) ($document['supplier_id'] ?? 0);

        if ($id <= 0) {
            return '';
        }

        $contact = Contact::find('`id` = '.$id." AND `deleted` IN ('true', 'false')", 1);
        $name = is_array($contact) && isset($contact['id']) ? self::text(Contacts::displayName($contact)) : 'Fornitore #'.$id;
        $number = self::text((string) ($document['supplier_document_number'] ?? ''));
        $date = substr((string) ($document['supplier_document_date'] ?? ''), 0, 10);
        $date = $date !== '' && !str_starts_with($date, '0000') ? date('d/m/Y', (int) strtotime($date)) : '';
        $reference = trim($number.($date !== '' ? ' del '.$date : ''));

        return 'Fornitore '.$name.($reference !== '' ? ' · '.$reference : '');
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
    private static function people(int $id, string $type = ''): array
    {
        if ($id <= 0) {
            return [];
        }

        $logs = self::rows(StockDocumentStatusLog::find(
            '`stock_document_id` = '.$id." AND `to_value` IN ('in_transit', 'completed', 'cancelled') AND `deleted` = 'false'",
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

            // Il trasferimento si invia e si riceve (G3.16); gli altri si confermano.
            $verb = match ($status) {
                'in_transit' => 'Inviato',
                'completed' => $type === 'transfer' ? 'Ricevuto' : 'Confermato',
                default => 'Annullato',
            };
            $people[$status] = $verb
                .($who !== '' ? ' da '.$who : '')
                .($time !== false ? ' il '.date('d/m/Y', $time) : '');
        }

        return $people;
    }

    /** «Da Magazzino a Negozio Centro»: le due sedi del trasferimento, sempre. */
    private static function route(int $from, int $to): string
    {
        $names = [];

        foreach (Locations::shown() as $location) {
            $names[(int) $location['id']] = $location['label'];
        }

        $name = static fn (int $id): string => $id > 0 ? ($names[$id] ?? 'Sede #'.$id) : '—';

        return 'Da '.$name($from).' a '.$name($to);
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
