<?php

namespace Wonder\Plugin\Gestionale\Models\Contacts;

/**
 * Il Model del gestionale sulla tabella `contact_addresses` di `wonder-image/customer-account`:
 * cambia solo la cartella. Place ID e coordinate sono già del modello di
 * customer-account, come `business_name`: il gestionale non lo chiede più, ma
 * chi l'ha già scritto lo tiene.
 * La `ContactAddressResource` del gestionale è solo di navigazione, senza Model sulla
 * tabella: per `resolveByTable()` risponde quella di customer-account (fallback).
 */
final class ContactAddress extends \Wonder\Plugin\CustomerAccount\Models\ContactAddress
{
    public static string $folder = 'gestionale/contacts';
}
