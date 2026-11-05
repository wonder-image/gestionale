<?php

namespace Wonder\Plugin\Gestionale\Support\Catalog\Exports;

use InvalidArgumentException;
use Wonder\App\Import\Column;
use Wonder\App\Import\Layouts;
use Wonder\App\Import\Profile;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Support\Catalog\Import\CatalogLayout;
use Wonder\Plugin\Gestionale\Support\Catalog\ProductExport;
use Wonder\Plugin\Gestionale\Support\Export\Download;

/**
 * Le esportazioni del catalogo che l'«Esporta» dei prodotti può offrire.
 *
 * Ogni formato dipende da una funzionalità dell'area «Esportazioni» (Excel e
 * CSV dalla stessa, `export_products`; ogni feed dalla sua): l'integratore le
 * concede dal pannello Funzionalità (o con `features.unlock` del sito) e chi
 * usa il gestionale vede solo i formati concessi. Un nuovo formato è una voce
 * qui, un ramo in `body()` e, se serve, una funzionalità in `config/features.php`.
 */
final class ProductExports
{
    /** chiave del formato → funzionalità, etichetta, estensione, tipo, nome del file */
    public const FORMATS = [
        'xlsx' => [
            'feature' => 'export_products',
            'label' => 'Excel (.xlsx)',
            'extension' => 'xlsx',
            'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'file' => 'prodotti',
        ],
        'csv' => [
            'feature' => 'export_products',
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

    /** I formati che accettano un tracciato salvato del catalogo. */
    public const LAYOUT_FORMATS = ['xlsx', 'csv'];

    /** Scarica il file; un formato non concesso non parte. `$layoutId`: il tracciato scelto in «Esporta». */
    public static function send(string $key, ?int $layoutId = null): void
    {
        $format = self::format($key);

        if (!Gestionale::feature($format['feature'])) {
            throw new InvalidArgumentException('Esportazione non disponibile: '.$key);
        }

        Download::send(self::body($key, $layoutId), $format['mime'], self::fileName($key));
    }

    /** Il contenuto del file, letto dal database. */
    public static function body(string $key, ?int $layoutId = null): string
    {
        self::format($key);

        if (in_array($key, self::LAYOUT_FORMATS, true)) {
            $columns = self::layoutColumns($layoutId);
            $export = ProductExport::of(self::wantsAttributes($columns));

            return $key === 'xlsx'
                ? ProductExport::xlsx($export['rows'], $export['pricesIncludeTax'], $columns)
                : ProductExport::csv($export['rows'], $export['pricesIncludeTax'], $columns);
        }

        $feed = ProductFeed::of($key === 'meta' ? 'meta' : 'google');
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

    /**
     * Le colonne del tracciato del catalogo scelto, nel suo ordine e con le
     * sue intestazioni; null (le colonne di sempre) senza tracciato o con
     * un tracciato di un'altra fonte.
     *
     * @return list<Column>|null
     */
    public static function layoutColumns(?int $layoutId): ?array
    {
        if ($layoutId === null || $layoutId <= 0) {
            return null;
        }

        // Il tracciato registrato, se un sito lo sostituisce.
        $columns = Profile::for(Layouts::find(CatalogLayout::KEY) ?? new CatalogLayout(), $layoutId);

        return $columns === null ? null : array_values(array_filter($columns, static fn ($column): bool => $column instanceof Column));
    }

    /** @param list<Column>|null $columns */
    public static function wantsAttributes(?array $columns): bool
    {
        foreach ($columns ?? [] as $column) {
            if (str_starts_with($column->key, 'attr:')) {
                return true;
            }
        }

        return false;
    }

    /** @return array{feature: string, label: string, extension: string, mime: string, file: string} */
    private static function format(string $key): array
    {
        return self::FORMATS[$key] ?? throw new InvalidArgumentException('Esportazione sconosciuta: '.$key);
    }
}
