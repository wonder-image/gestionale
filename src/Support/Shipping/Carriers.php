<?php

namespace Wonder\Plugin\Gestionale\Support\Shipping;

use Wonder\Plugin\Gestionale\Models\Shipping\Carrier;

/**
 * I corrieri: l'indirizzo di tracking e l'elenco per i select.
 */
final class Carriers
{
    /**
     * L'indirizzo dove seguire il pacco: il template del corriere con
     * `{tracking}` sostituito (url-encoded). Vuoto se manca il template o il
     * numero di tracking.
     */
    public static function trackingUrl(array $carrier, string $tracking): string
    {
        $template = trim((string) ($carrier['tracking_url_template'] ?? ''));
        $tracking = trim($tracking);

        if ($template === '' || $tracking === '') {
            return '';
        }

        return str_replace('{tracking}', rawurlencode($tracking), $template);
    }

    /**
     * I corrieri attivi per i select: id => nome, nell'ordine di `position`.
     *
     * @return array<int, string>
     */
    public static function options(): array
    {
        $found = Carrier::find(['active' => 'true']);
        $rows = !is_array($found) ? [] : (array_key_exists('id', $found) ? [$found] : array_values(array_filter($found, 'is_array')));

        usort($rows, static fn (array $a, array $b): int => [(int) ($a['position'] ?? 0), (int) $a['id']] <=> [(int) ($b['position'] ?? 0), (int) $b['id']]);

        $options = [];
        foreach ($rows as $row) {
            $options[(int) $row['id']] = (string) ($row['name'] ?? '');
        }

        return $options;
    }
}
