<?php

namespace Wonder\Plugin\Gestionale\Resources\System;

use Wonder\App\Resources\Config\ImportLayoutResource as CoreImportLayoutResource;
use Wonder\App\ResourceSchema\NavigationSchema;
use Wonder\Plugin\Gestionale\Gestionale;

/**
 * «Tracciati file» del core, sotto Set-up → Gestionale: stesso `path()`, quindi
 * sostituisce la voce del core. Si vede solo con la funzionalità
 * «Caricamento del catalogo» accesa.
 */
final class ImportLayoutResource extends CoreImportLayoutResource
{
    public static function navigationSchema(): NavigationSchema
    {
        return NavigationSchema::for(static::class)
            ->section('set-up', 'Set Up', 'bi-gear', 1020, ['admin'])
            ->group('gestionale', 'Gestionale', 40)
            ->title('Tracciati file')
            ->order(30)
            ->authority(['admin'])
            ->enabled(Gestionale::feature('import_catalog'));
    }
}
