<?php

namespace Wonder\Plugin\Gestionale\Support\Contacts;

use Wonder\Plugin\Gestionale\Gestionale;

/**
 * Con chi lavora il gestionale: solo persone, persone e aziende, solo aziende.
 *
 * Il modo nasce da due funzionalità del pannello, area Anagrafiche:
 * «Clienti privati» (`private_customers`) e «Aziende» (`companies`). Sono
 * interruttori veri, così ciò che vive solo con le aziende (gli account
 * aziendali) le richiede con le dipendenze di sempre e si spegne con loro.
 * Spente tutte e due non si può (`FeatureGuards`); se lo stato non si legge
 * (test, comandi) vale `both`, il comportamento di sempre.
 *
 * È l'unico punto che il codice legge. Il modo nasconde, non cancella: le
 * schede e i legami restano nel database e tornano alla riaccensione.
 */
final class CustomerMode
{
    /** Solo persone: niente aziende, brand, referenti, capogruppo, reparti. */
    public const PEOPLE = 'people';

    /** Persone e aziende. */
    public const BOTH = 'both';

    /** Solo aziende: le persone ci sono come loro referenti, non come clienti. */
    public const COMPANIES = 'companies';

    public static function current(): string
    {
        return self::of(Gestionale::feature('private_customers'), Gestionale::feature('companies'));
    }

    /** Il modo dalle due funzionalità. Pura. */
    public static function of(bool $privateCustomers, bool $companies): string
    {
        return match (true) {
            $privateCustomers && !$companies => self::PEOPLE,
            $companies && !$privateCustomers => self::COMPANIES,
            default => self::BOTH,
        };
    }

    /** Si vende anche alle persone (clienti privati): modo `people` o `both`. */
    public static function people(): bool
    {
        return self::current() !== self::COMPANIES;
    }

    /** Ci sono le aziende, con quello che è solo loro: modo `companies` o `both`. */
    public static function companies(): bool
    {
        return self::current() !== self::PEOPLE;
    }
}
