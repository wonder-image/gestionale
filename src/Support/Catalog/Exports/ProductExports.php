<?php

namespace Wonder\Plugin\Gestionale\Support\Catalog\Exports;

use InvalidArgumentException;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Support\Catalog\ProductExport;
use Wonder\Plugin\Gestionale\Support\Export\Download;

/**
 * Le esportazioni del catalogo che l'«Esporta» dei prodotti può offrire.
 *
 * Ognuna è una funzionalità dell'area «Esportazioni»: l'integratore le
 * concede dal pannello Funzionalità (o con `features.unlock` del sito) e chi
 * usa il gestionale vede solo quelle concesse. Una nuova esportazione è una
 * voce qui, una funzionalità in `config/features.php` e un ramo in `body()`.
 */
final class ProductExports
{
    /** chiave del formato → funzionalità, etichetta, estensione, tipo, nome del file */
    public const FORMATS = [
        'xlsx' => [
            'feature' => 'export_xlsx',
            'label' => 'Excel (.xlsx)',
            'extension' => 'xlsx',
            'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'file' => 'prodotti',
        ],
        'csv' => [
            'feature' => 'export_csv',
            'label' => 'CSV (.csv)',
            'extension' => 'csv',
            'mime' => 'text/csv; charset=UTF-8',
            'file' => 'prodotti',
        ],
        'meta' => [
            'feature' => 'export_meta',
            'label' => 'Catalogo Meta (.csv)',
            'extension' => 'csv',
            'mime' => 'text/csv; charset=UTF-8',
            'file' => 'catalogo-meta',
        ],
        'google' => [
            'feature' => 'export_google',
            'label' => 'Google Merchant Center (.xml)',
            'extension' => 'xml',
            'mime' => 'application/xml; charset=UTF-8',
            'file' => 'google-merchant',
        ],
    ];

    /**
     * I formati concessi, chiave → etichetta, come li vuole `download()` della tabella.
     *
     * @param (callable(string): bool)|null $enabled per i test; di serie `Gestionale::feature`
     * @return array<string, string>
     */
    public static function granted(?callable $enabled = null): array
    {
        $enabled ??= static fn (string $feature): bool => Gestionale::feature($feature);
        $out = [];

        foreach (self::FORMATS as $key => $format) {
            if ($enabled($format['feature'])) {
                $out[$key] = $format['label'];
            }
        }

        return $out;
    }

    /** Il nome del file: `catalogo-meta-2026-10-15.csv`. */
    public static function fileName(string $key, ?int $time = null): string
    {
        $format = self::format($key);

        return ProductExport::fileName($format['extension'], $time, $format['file']);
    }

    /** Scarica il file; un formato non concesso non parte. */
    public static function send(string $key): void
    {
        $format = self::format($key);

        if (!Gestionale::feature($format['feature'])) {
            throw new InvalidArgumentException('Esportazione non disponibile: '.$key);
        }

        Download::send(self::body($key), $format['mime'], self::fileName($key));
    }

    /** Il contenuto del file, letto dal database. */
    public static function body(string $key): string
    {
        self::format($key);

        if ($key === 'xlsx' || $key === 'csv') {
            $export = ProductExport::of();

            return $key === 'xlsx'
                ? ProductExport::xlsx($export['rows'], $export['pricesIncludeTax'])
                : ProductExport::csv($export['rows'], $export['pricesIncludeTax']);
        }

        $feed = ProductFeed::of();
        $options = [
            'pricesIncludeTax' => $feed['pricesIncludeTax'],
            'currency' => $feed['currency'],
            'now' => $feed['now'],
            'brand' => ProductFeed::shopName(),
            'url' => ProductFeed::shopUrl(),
        ];

        return $key === 'meta'
            ? MetaCatalog::csv($feed['rows'], $options)
            : GoogleMerchant::xml($feed['rows'], $options);
    }

    /** @return array{feature: string, label: string, extension: string, mime: string, file: string} */
    private static function format(string $key): array
    {
        return self::FORMATS[$key] ?? throw new InvalidArgumentException('Esportazione sconosciuta: '.$key);
    }
}
