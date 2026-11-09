<?php

namespace Wonder\Plugin\Gestionale\Resources\Contacts;

use Wonder\App\ResourceSchema\PermissionSchema;
use Wonder\Plugin\CustomerAccount\Resources\CompanyBrandResource as BaseCompanyBrandResource;
use Wonder\Plugin\Gestionale\Gestionale;

/**
 * I brand delle aziende nel gestionale: stessa scheda di customer-account,
 * con i permessi dell'area contatti, così il «+» compare a chi gestisce
 * le anagrafiche.
 */
class CompanyBrandResource extends BaseCompanyBrandResource
{
    public static function path(): string
    {
        return 'app/gestionale/brand';
    }

    public static function permissionSchema(): PermissionSchema
    {
        return PermissionSchema::for(static::class)->access(Gestionale::area('contatti'));
    }
}
