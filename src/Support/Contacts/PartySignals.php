<?php

namespace Wonder\Plugin\Gestionale\Support\Contacts;

use Wonder\Backend\Table\RowSignal;
use Wonder\Plugin\CustomerAccount\Models\CompanyBrand;
use Wonder\Plugin\CustomerAccount\Models\CompanyBrandLink;
use Wonder\Plugin\CustomerAccount\Models\CompanyPoint;
use Wonder\Plugin\CustomerAccount\Models\Contact;
use Wonder\Plugin\CustomerAccount\Models\ContactPoint;
use Wonder\Plugin\CustomerAccount\Models\ContactRelation;
use Wonder\Plugin\CustomerAccount\Support\ContactRelations;

/**
 * I segnali di riga delle liste contatti: cosa manca a un'azienda o a una
 * persona. Le liste sono `companies`, `contacts` e `suppliers`: un sito li
 * cambia con `RowSignals::add()` / `remove()` su quelle chiavi.
 */
final class PartySignals
{
    public const COMPANIES = 'companies';
    public const CONTACTS = 'contacts';
    public const SUPPLIERS = 'suppliers';

    /** @return list<RowSignal> */
    public static function companies(): array
    {
        return [
            self::missingBrand(),
            self::missingContact(),
            self::missingReferent(),
            self::missingBilling(),
        ];
    }

    /** @return list<RowSignal> */
    public static function suppliers(): array
    {
        return [self::missingContact(), self::missingBilling()];
    }

    /** @return list<RowSignal> */
    public static function contacts(): array
    {
        return [
            RowSignal::warning('missing_name', self::blank('name'), self::text('missing_name')),
            RowSignal::warning('missing_surname', self::blank('surname'), self::text('missing_surname')),
            // Vale l'email di accesso o una email fra i recapiti personali.
            RowSignal::warning(
                'missing_email',
                self::blank('email').' AND NOT EXISTS (SELECT 1 FROM '.ContactPoint::$table." sp
                    WHERE sp.contact_id = {table}.id AND sp.deleted = 'false'
                      AND sp.kind = 'email' AND COALESCE(TRIM(sp.value), '') <> '')",
                self::text('missing_email')
            ),
        ];
    }

    /** Solo per i clienti: un fornitore non ha brand. */
    public static function missingBrand(): RowSignal
    {
        return RowSignal::warning(
            'missing_brand',
            "{table}.is_customer = 'true' AND NOT EXISTS (SELECT 1 FROM ".CompanyBrandLink::$table.' sl
                JOIN '.CompanyBrand::$table." sb ON sb.id = sl.brand_id AND sb.deleted = 'false' AND sb.archived = 'false'
                WHERE sl.company_id = {table}.id AND sl.deleted = 'false' AND COALESCE(sl.ended_at, '') = '')",
            self::text('missing_brand')
        )->when(static fn (): bool => Brands::enabled());
    }

    /** Un'email, un telefono o un recapito fra quelli dell'azienda. */
    public static function missingContact(): RowSignal
    {
        return RowSignal::warning(
            'missing_contact',
            self::blank('email').' AND '.self::blank('phone').' AND NOT EXISTS (SELECT 1 FROM '.CompanyPoint::$table." sp
                WHERE sp.company_id = {table}.id AND sp.deleted = 'false' AND COALESCE(TRIM(sp.value), '') <> '')",
            self::text('missing_contact')
        );
    }

    public static function missingReferent(): RowSignal
    {
        return RowSignal::warning(
            'missing_referent',
            'NOT EXISTS (SELECT 1 FROM '.ContactRelation::$table.' sr
                JOIN '.Contact::$table." sc ON sc.id = sr.person_id AND sc.deleted = 'false'
                WHERE sr.company_id = {table}.id AND sr.deleted = 'false')",
            self::text('missing_referent')
        )->when(static fn (): bool => ContactRelations::enabled() && CustomerMode::companies());
    }

    /**
     * Ragione sociale, via, città e paese. In Italia anche partita IVA o
     * codice fiscale, e codice SDI o PEC.
     */
    public static function missingBilling(): RowSignal
    {
        $base = implode(' OR ', array_map(self::blank(...), ['business_name', 'street', 'city', 'country']));
        $italy = "UPPER(TRIM({table}.country)) = 'IT' AND ("
            .'('.self::blank('pi').' AND '.self::blank('cf').') OR ('.self::blank('sdi').' AND '.self::blank('pec').'))';

        return RowSignal::warning('missing_billing', "({$base}) OR ({$italy})", self::text('missing_billing'));
    }

    private static function blank(string $column): string
    {
        return "COALESCE(TRIM({table}.{$column}), '') = ''";
    }

    private static function text(string $code): string
    {
        return __t('gestionale.contacts.signals.'.$code);
    }
}
