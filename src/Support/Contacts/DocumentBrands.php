<?php

namespace Wonder\Plugin\Gestionale\Support\Contacts;

use Wonder\Plugin\CustomerAccount\Models\CompanyBrand;
use Wonder\Plugin\CustomerAccount\Support\CompanyBrands;
use Wonder\Plugin\CustomerAccount\Support\CompanyEvents;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Sql\Query;

/**
 * Il brand di un documento intestato a un'azienda (`company_brand_id` su
 * `gst_orders`): il nome commerciale di customer-account, da non confondere
 * con il marchio degli articoli (`Catalog\Brand`).
 *
 * Il nome si copia in `company_brand_name`, come `referent_name`: il
 * documento dice com'era scritto anche se il brand poi cambia nome, si stacca
 * dall'azienda o si archivia. Un documento salvato tiene il suo brand finché
 * nessuno ne sceglie un altro; uno diverso dev'essere dell'azienda oggi.
 */
final class DocumentBrands
{
    /** @var class-string la fonte dei brand: `CompanyBrands`, o un doppio nei test */
    private static string $brands = CompanyBrands::class;

    /** Ascolta l'unione dei brand (dal file di boot del modulo). */
    public static function register(): void
    {
        CompanyEvents::listen(CompanyEvents::BRAND_MERGED, [self::class, 'merged']);
    }

    /**
     * Il brand da scrivere su un documento dell'azienda, con il nome copiato.
     *
     * - Documento nuovo (o di un'altra azienda) senza brand indicato: il
     *   predefinito dell'azienda.
     * - Documento salvato della stessa azienda, senza brand indicato o con
     *   quello salvato: resta com'è, anche se nel frattempo è stato staccato
     *   o archiviato.
     * - Un brand diverso dev'essere dell'azienda oggi, altrimenti è un errore:
     *   azzerarlo in silenzio cambierebbe il documento senza dirlo. `0` (nessun
     *   brand) va sempre bene.
     *
     * @param int|null $brandId il brand indicato; null se il campo non arriva
     * @param array<string, mixed>|null $stored il documento salvato, in modifica
     * @return array{company_brand_id?: int, company_brand_name?: string} vuoto se non cambia niente
     */
    public static function resolve(int $companyId, ?int $brandId, ?array $stored = null): array
    {
        if ($stored !== null && (int) ($stored['company_id'] ?? 0) === $companyId) {
            $storedId = (int) ($stored['company_brand_id'] ?? 0);

            if ($brandId === null || $brandId === $storedId) {
                // Il nome manca solo sui documenti di prima della copia.
                return $storedId > 0 && trim((string) ($stored['company_brand_name'] ?? '')) === '' ? self::values($storedId) : [];
            }
        }

        if ($brandId === null) {
            return self::values(self::$brands::defaultOf($companyId));
        }

        if ($brandId > 0 && !self::$brands::belongsTo($brandId, $companyId)) {
            throw UserError::make('office_order.brand_unknown');
        }

        return self::values(max(0, $brandId));
    }

    /**
     * Il nome del brand di un documento, da stampare: quello copiato o, sui
     * documenti di prima, quello di oggi. È testo, non HTML: chi lo stampa lo
     * escapa.
     *
     * @param array<string, mixed> $order
     */
    public static function nameOf(array $order): string
    {
        $brandId = (int) ($order['company_brand_id'] ?? 0);

        if ($brandId <= 0 || (int) ($order['company_id'] ?? 0) <= 0) {
            return '';
        }

        $name = trim((string) ($order['company_brand_name'] ?? ''));

        return $name !== '' ? $name : self::name($brandId);
    }

    /**
     * L'URL del logo di un brand, solo se è http(s) o un percorso del sito:
     * vuoto altrimenti. Chi lo stampa lo escapa comunque.
     *
     * @param array<string, mixed> $brand la riga del brand
     */
    public static function logoUrl(array $brand): string
    {
        try {
            $url = trim(CompanyBrand::logoUrl($brand));
        } catch (\Throwable) {
            return '';
        }

        return self::safeUrl($url);
    }

    /** Un URL buono per `src`: http(s) o un percorso del sito (`/…`, non `//…`); vuoto altrimenti. Pura. */
    public static function safeUrl(string $url): string
    {
        return preg_match('#^(https?://|/(?!/))#i', $url) === 1 ? $url : '';
    }

    /**
     * Il logo del brand di un documento, in HTML escapato; vuoto se il
     * documento non ha brand o il brand non ha logo. Il brand è quello di
     * oggi: il logo non si copia sul documento.
     *
     * @param array<string, mixed> $order
     */
    public static function logoOf(array $order): string
    {
        $brandId = (int) ($order['company_brand_id'] ?? 0);

        if ($brandId <= 0 || (int) ($order['company_id'] ?? 0) <= 0) {
            return '';
        }

        $brand = self::$brands::find($brandId);

        return is_array($brand) ? self::logoHtml(self::logoUrl($brand), self::nameOf($order)) : '';
    }

    /** `<img>` del logo con URL e testo escapati; vuoto senza un URL buono. Pura. */
    public static function logoHtml(string $url, string $name): string
    {
        $url = self::safeUrl($url);

        if ($url === '') {
            return '';
        }

        return '<img src="'.htmlspecialchars($url, ENT_QUOTES, 'UTF-8').'" alt="'.htmlspecialchars($name, ENT_QUOTES, 'UTF-8')
            .'" class="img-fluid" style="max-height: 3rem">';
    }

    /**
     * `company.brand_merged`: i documenti del doppione passano al brand che
     * resta, con una sola UPDATE. Il nome copiato non si tocca: è quello che
     * c'era scritto. `$query` serve ai test.
     *
     * @param array<string, mixed> $payload `from_id`, `into_id`, `brand`
     */
    public static function merged(string $event, array $payload, ?Query $query = null): void
    {
        $fromId = (int) ($payload['from_id'] ?? 0);
        $intoId = (int) ($payload['into_id'] ?? 0);

        if ($fromId <= 0 || $intoId <= 0 || $fromId === $intoId) {
            return;
        }

        ($query ?? Order::query())->Update(Order::$table, ['company_brand_id' => $intoId], 'company_brand_id', $fromId);
    }

    /** Solo per i test: sostituisce `CompanyBrands` (`null` torna a quella vera). */
    public static function useBrands(?string $class): void
    {
        self::$brands = $class ?? CompanyBrands::class;
    }

    /** @return array{company_brand_id: int, company_brand_name: string} */
    private static function values(int $brandId): array
    {
        return ['company_brand_id' => $brandId, 'company_brand_name' => $brandId > 0 ? self::name($brandId) : ''];
    }

    private static function name(int $brandId): string
    {
        $brand = self::$brands::find($brandId);

        return is_array($brand) ? CompanyBrands::label($brand) : '';
    }
}
