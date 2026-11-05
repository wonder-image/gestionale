<?php

namespace Wonder\Plugin\Gestionale\Resources\System;

use Wonder\App\Resources\Config\ImportLayoutResource as CoreImportLayoutResource;
use Wonder\App\ResourceSchema\NavigationSchema;
use Wonder\Plugin\Gestionale\Gestionale;

/**
 * «Tracciati file» del core (Dev → File): stesso `path()`, quindi sostituisce
 * la voce del core. Si vede solo con la funzionalità «Caricamento del
 * catalogo» accesa.
 */
final class ImportLayoutResource extends CoreImportLayoutResource
{
    public static function navigationSchema(): NavigationSchema
    {
        return parent::navigationSchema()->enabled(Gestionale::feature('import_catalog'));
    }
}
