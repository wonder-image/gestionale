<?php

namespace Wonder\Plugin\Gestionale\Support\Contacts;

use Throwable;
use Wonder\Plugin\CustomerAccount\Models\CompanyBrand;
use Wonder\Plugin\Gestionale\Gestionale;

/**
 * I brand delle aziende (customer-account), con la funzionalità «Brand»
 * (`brands`, area Anagrafiche, richiede le aziende).
 *
 * `enabled()` è l'unico punto che il codice legge: voce di menu e pagine del
 * brand, riquadro nella scheda dell'azienda, brand di preventivi e ordini
 * (tendina, «Aggiungi», logo, sedi per brand), brand seguiti dai referenti,
 * brand degli indirizzi, scheda dell'ordine, PDF e MCP. Spenta nasconde, non
 * cancella: i legami e il brand copiato sui documenti restano e tornano alla
 * riaccensione. Con lo stato non letto (test, comandi) è spenta.
 */
final class Brands
{
    public const FEATURE = 'brands';

    public static function enabled(): bool
    {
        return CustomerMode::companies() && Gestionale::feature(self::FEATURE);
    }

    /**
     * Vero se c'è almeno un brand non cancellato: il `default_if` di «Brand»
     * nel catalogo. Senza riga in `gst_features` (mai impostata) la
     * funzionalità nasce accesa dove i brand ci sono già; con la riga vale la
     * riga, anche spenta. Un errore di lettura vale no.
     */
    public static function inUse(): bool
    {
        try {
            $row = CompanyBrand::find(['deleted' => 'false'], 1, null, null, '`id`');
        } catch (Throwable) {
            return false;
        }

        return is_array($row) && $row !== [];
    }
}
