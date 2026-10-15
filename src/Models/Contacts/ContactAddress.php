<?php

namespace Wonder\Plugin\Gestionale\Models\Contacts;

/** Compatibility entrypoint; shared storage is owned by wonder-image/customer-account. */
final class ContactAddress extends \Wonder\Plugin\CustomerAccount\Models\ContactAddress
{
    public static string $folder = 'gestionale/contacts';
}
