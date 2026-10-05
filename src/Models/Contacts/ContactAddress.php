<?php

namespace Wonder\Plugin\Gestionale\Models\Contacts;

/** Compatibility entrypoint; shared storage is owned by wonder-image/app. */
final class ContactAddress extends \Wonder\App\Models\Contacts\ContactAddress
{
    public static string $folder = 'gestionale/contacts';
}
