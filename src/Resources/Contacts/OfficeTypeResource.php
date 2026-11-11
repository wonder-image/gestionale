<?php

namespace Wonder\Plugin\Gestionale\Resources\Contacts;

use Wonder\App\ResourceSchema\PermissionSchema;
use Wonder\Plugin\CustomerAccount\Resources\OfficeTypeResource as BaseOfficeTypeResource;
use Wonder\Plugin\Gestionale\Gestionale;

/**
 * I tipi ufficio nel gestionale: stessa scheda di customer-account, con i
 * permessi dell'area contatti, così il «+» accanto a «Tipo ufficio» compare
 * a chi gestisce le anagrafiche.
 */
class OfficeTypeResource extends BaseOfficeTypeResource
{
    public static function path(): string
    {
        return 'app/gestionale/tipi-ufficio';
    }

    public static function permissionSchema(): PermissionSchema
    {
        return PermissionSchema::for(static::class)->access(Gestionale::area('contatti'));
    }
}
