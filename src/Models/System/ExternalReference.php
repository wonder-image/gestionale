<?php

namespace Wonder\Plugin\Gestionale\Models\System;

/** Compatibility entrypoint; shared storage is owned by wonder-image/app. */
final class ExternalReference extends \Wonder\App\Models\System\ExternalReference
{
    public static string $folder = 'gestionale/external-references';
}
