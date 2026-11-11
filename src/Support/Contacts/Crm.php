<?php

namespace Wonder\Plugin\Gestionale\Support\Contacts;

use Throwable;
use Wonder\Plugin\CustomerAccount\Models\CompanyBrand;
use Wonder\Plugin\CustomerAccount\Models\CompanyPoint;
use Wonder\Plugin\CustomerAccount\Models\Contact;
use Wonder\Plugin\CustomerAccount\Models\ContactPoint;
use Wonder\Plugin\CustomerAccount\Support\Crm as AccountCrm;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Sql\Query;

/**
 * Il «CRM» delle schede, con la funzionalità `crm` (area Anagrafiche): più
 * recapiti tipizzati per persone, aziende, brand e referenti, e i social di
 * brand e persone.
 *
 * `enabled()` è l'unico punto che il codice legge; `apply()` passa lo stato
 * all'interruttore di customer-account (`Support\Crm`), che è quello che
 * leggono i suoi form. Spenta nasconde, non cancella: si mostrano un'email e
 * un telefono, i recapiti in più e i social restano salvati. Con lo stato non
 * letto (test, comandi) è spenta.
 */
final class Crm
{
    public const FEATURE = 'crm';

    public static function enabled(): bool
    {
        return Gestionale::feature(self::FEATURE);
    }

    /**
     * Accende il CRM di customer-account con la funzionalità; non lo spegne
     * mai, se l'ha acceso il sito. Una Resource lo chiama prima di costruire
     * un form o salvare, come `CompanyBrandResource::useKit()`.
     */
    public static function apply(): bool
    {
        if (self::enabled()) {
            AccountCrm::enable();
        }

        return AccountCrm::enabled();
    }

    /** I recapiti multipli e i social del brand: con il CRM, anche a brand kit spento. */
    public static function brandSocials(): bool
    {
        return self::enabled();
    }

    /**
     * Vero se i dati del CRM ci sono già: un recapito in più di una persona
     * o di un'azienda, o i social di un brand o di una persona. È il
     * `default_if` di «CRM»: senza riga in `gst_features` la funzionalità
     * nasce accesa dove quei dati sono in uso; con la riga vale la riga,
     * anche spenta. Un errore di lettura vale no.
     */
    public static function inUse(): bool
    {
        try {
            foreach ([ContactPoint::class, CompanyPoint::class] as $model) {
                $row = $model::find(['deleted' => 'false'], 1, null, null, '`id`');

                if (is_array($row) && $row !== []) {
                    return true;
                }
            }

            foreach ([CompanyBrand::$table, Contact::$table] as $table) {
                $row = (new Query())->Select(
                    $table,
                    "`deleted` = 'false' AND `socials` IS NOT NULL AND `socials` NOT IN ('', '[]', '{}', 'null')",
                    1,
                    null,
                    null,
                    '`id`'
                )->row;

                if (is_array($row) && $row !== []) {
                    return true;
                }
            }
        } catch (Throwable) {
            return false;
        }

        return false;
    }
}
