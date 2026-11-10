<?php

namespace Wonder\Plugin\Gestionale\Models\Contacts;

/**
 * Il Model del gestionale sulla tabella `contact_addresses` di `wonder-image/customer-account`:
 * cambia solo la cartella. «Presso» (`business_name`), place ID e coordinate
 * sono già del modello di customer-account.
 * La `ContactAddressResource` del gestionale è solo di navigazione, senza Model sulla
 * tabella: per `resolveByTable()` risponde quella di customer-account (fallback).
 */
final class ContactAddress extends \Wonder\Plugin\CustomerAccount\Models\ContactAddress
{
    public static string $folder = 'gestionale/contacts';
}
