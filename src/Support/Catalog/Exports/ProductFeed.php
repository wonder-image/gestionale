<?php

namespace Wonder\Plugin\Gestionale\Support\Catalog\Exports;

use Throwable;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Support\Catalog\Measures;
use Wonder\Plugin\Gestionale\Support\Catalog\ProductExport;
use Wonder\Plugin\Gestionale\Support\Promotions\Campaigns;

/**
 * Le regole comuni ai feed dei cataloghi di fuori (Meta, Google, …), sopra
 * le righe di `ProductExport::rows()`.
 *
 * Un feed parla al cliente del negozio online: entra solo ciò che si compra
 * sul sito (articolo pubblicato e online, opzione attiva, con prezzo, pagina
 * e foto) e il prezzo è quello che il cliente paga, IVA compresa e con la
 * campagna di sconto in corso.
 *
 * Tutto puro tranne `of()` e `withCampaigns()`.
 */
final class ProductFeed
{
    /**
     * Le righe pronte per un feed, lette dal database.
     *
     * @return array{rows: list<array<string, mixed>>, pricesIncludeTax: bool, currency: string, now: string}
     */
    public static function of(): array
    {
        $export = ProductExport::of();
        $now = date('Y-m-d H:i:s');
        $rows = array_values(array_filter($export['rows'], [self::class, 'eligible']));

        return [
            'rows' => Gestionale::feature('discount_campaigns') ? self::withCampaigns($rows, $now) : $rows,
            'pricesIncludeTax' => $export['pricesIncludeTax'],
            'currency' => self::currency(),
            'now' => $now,
        ];
    }

    /** Si compra sul sito: pubblicato, online, attivo, con prezzo, pagina e foto. */
    public static function eligible(array $row): bool
    {
        return ($row['visible'] ?? '') === 'Sì'
            && ($row['visible_online'] ?? '') === 'Sì'
            && ($row['active'] ?? '') === 'Sì'
            && (float) ($row['price'] ?? 0) > 0
            && (string) ($row['link'] ?? '') !== ''
            && (string) ($row['image'] ?? '') !== '';
    }

    /**
     * Aggiunge `campaign` (`price`, `ends_at`) alle righe che una campagna
     * sconta adesso, sul canale online.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public static function withCampaigns(array $rows, string $now): array
    {
        foreach ($rows as $index => $row) {
            try {
                $campaign = Campaigns::display((int) $row['id'], $now);
            } catch (Throwable) {
                $campaign = null;
            }

            if ($campaign !== null) {
                $rows[$index]['campaign'] = ['price' => (float) $campaign['price'], 'ends_at' => (string) $campaign['ends_at']];
            }
        }

        return $rows;
    }

    /** Il nome nel feed: "Maglia - Blu / S", o solo "Maglia". */
    public static function title(array $row, int $limit): string
    {
        $title = trim((string) ($row['article'] ?? ''));
        $option = trim((string) ($row['option'] ?? ''));

        return self::cut($option !== '' ? $title.' - '.$option : $title, $limit);
    }

    /** La descrizione, se no la breve, se no il nome: un feed non la vuole vuota. */
    public static function description(array $row, int $limit): string
    {
        foreach (['description', 'short_description'] as $key) {
            $text = trim((string) ($row[$key] ?? ''));

            if ($text !== '') {
                return self::cut($text, $limit);
            }
        }

        return self::title($row, $limit);
    }

    /** Il prezzo pieno che paga il cliente. */
    public static function price(array $row, bool $pricesIncludeTax): float
    {
        return self::gross((float) ($row['price'] ?? 0), $row, $pricesIncludeTax);
    }

    /**
     * Il prezzo scontato che paga il cliente: il più basso tra lo scontato
     * dell'opzione e la campagna in corso; `null` se non sconta.
     */
    public static function salePrice(array $row, bool $pricesIncludeTax): ?float
    {
        $best = self::bestSale($row);

        return $best === null ? null : self::gross($best, $row, $pricesIncludeTax);
    }

    /**
     * Quando vale lo sconto, `inizio/fine` in ISO 8601: solo per una campagna
     * che ha una fine e vince sullo scontato. Vuoto altrimenti.
     */
    public static function saleDates(array $row, string $now, string $separator = '/'): string
    {
        $campaign = $row['campaign'] ?? null;
        $best = self::bestSale($row);

        if (!is_array($campaign) || $best === null || $best !== (float) $campaign['price'] || trim((string) $campaign['ends_at']) === '') {
            return '';
        }

        $start = strtotime($now);
        $end = strtotime((string) $campaign['ends_at']);

        return $start === false || $end === false || $end <= $start ? '' : date('Y-m-d\TH:iP', $start).$separator.date('Y-m-d\TH:iP', $end);
    }

    /** Si può comprare adesso: giacenza, illimitata, o vendibile senza. */
    public static function inStock(array $row): bool
    {
        $available = $row['available'] ?? null;

        return $available === null || (float) $available > 0 || ($row['backorder'] ?? '') === 'Sì';
    }

    /** "12.90 EUR": punto nei decimali, spazio, codice ISO. */
    public static function money(float $amount, string $currency): string
    {
        return number_format($amount, 2, '.', '').' '.$currency;
    }

    /** Un EAN/UPC/GTIN valido (8, 12, 13 o 14 cifre); vuoto se non lo è. */
    public static function gtin(array $row): string
    {
        $code = preg_replace('/[\s-]+/', '', (string) ($row['ean'] ?? '')) ?? '';

        return preg_match('/^(\d{8}|\d{12,14})$/', $code) === 1 ? $code : '';
    }

    /** "0.4 kg"; vuoto senza peso. */
    public static function weight(array $row): string
    {
        $weight = Measures::of($row)['weight'];

        return $weight > 0 ? rtrim(rtrim(number_format($weight, 3, '.', ''), '0'), '.').' kg' : '';
    }

    /** Il marchio, se no il nome del negozio: i cataloghi lo vogliono sempre. */
    public static function brand(array $row, string $fallback, int $limit): string
    {
        $brand = trim((string) ($row['brand'] ?? ''));

        return self::cut($brand !== '' ? $brand : $fallback, $limit);
    }

    public static function cut(string $text, int $limit): string
    {
        return mb_strlen($text, 'UTF-8') <= $limit ? $text : rtrim(mb_substr($text, 0, $limit - 1, 'UTF-8')).'…';
    }

    /** La valuta del catalogo (`exports.currency`, euro se manca). */
    public static function currency(): string
    {
        return strtoupper((string) Gestionale::config('exports.currency', 'EUR')) ?: 'EUR';
    }

    /** Il nome del negozio, per il marchio che manca e il titolo del feed. */
    public static function shopName(): string
    {
        $society = $GLOBALS['SOCIETY'] ?? null;

        return trim((string) (is_object($society) ? ($society->name ?? '') : (is_array($society) ? ($society['name'] ?? '') : '')));
    }

    /** L'indirizzo del sito, per il canale del feed. */
    public static function shopUrl(): string
    {
        return defined('APP_URL') ? rtrim((string) constant('APP_URL'), '/').'/' : '';
    }

    private static function bestSale(array $row): ?float
    {
        $price = (float) ($row['price'] ?? 0);
        $candidates = [];

        if (($row['sale_price'] ?? null) !== null) {
            $candidates[] = (float) $row['sale_price'];
        }

        if (is_array($row['campaign'] ?? null)) {
            $candidates[] = (float) $row['campaign']['price'];
        }

        $candidates = array_filter($candidates, static fn (float $value): bool => $value > 0 && $value < $price);

        return $candidates === [] ? null : min($candidates);
    }

    /** Ivato quando il catalogo è senza IVA; l'aliquota è quella dell'articolo. */
    private static function gross(float $amount, array $row, bool $pricesIncludeTax): float
    {
        if (!$pricesIncludeTax && ($row['tax_rate'] ?? null) !== null) {
            $amount *= 1 + ((float) $row['tax_rate']) / 100;
        }

        return round($amount, 2);
    }
}
