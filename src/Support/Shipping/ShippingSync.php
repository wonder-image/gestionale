<?php

namespace Wonder\Plugin\Gestionale\Support\Shipping;

use Throwable;
use Wonder\App\Support\SyncSchema;
use Wonder\Plugin\Gestionale\Models\System\Setting;

/**
 * Se corrieri, metodi, zone e listini viaggiano col deploy: lo decide lo
 * sviluppatore in locale, con `shipping_sync` delle impostazioni tecniche.
 *
 * Acceso, le sei tabelle partono dal locale con i loro id e in produzione si
 * leggono e basta, come i metodi di pagamento. Spento, sono lavoro del
 * commerciante e il deploy non le tocca.
 *
 * Si legge ogni volta, senza tenerlo da parte: l'import del deploy porta
 * l'interruttore e subito dopo rilegge le tabelle che deve importare.
 */
final class ShippingSync
{
    public static function enabled(): bool
    {
        try {
            $value = Setting::current()['shipping_sync'] ?? 'true';
        } catch (Throwable) {
            // Senza database (test degli schemi, primo `forge update`) vale
            // il predefinito della colonna.
            $value = 'true';
        }

        return (string) $value !== 'false';
    }

    public static function schema(): ?SyncSchema
    {
        return self::schemaFor(self::enabled());
    }

    public static function schemaFor(bool $enabled): ?SyncSchema
    {
        return $enabled ? SyncSchema::multiRow()->keepIds()->localOnly() : null;
    }
}
