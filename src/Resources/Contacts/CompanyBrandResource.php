<?php

namespace Wonder\Plugin\Gestionale\Resources\Contacts;

use Throwable;
use Wonder\App\ResourceSchema\PermissionSchema;
use Wonder\Elements\Form\Form;
use Wonder\Plugin\CustomerAccount\Resources\CompanyBrandResource as BaseCompanyBrandResource;
use Wonder\Plugin\CustomerAccount\Support\CompanyBrands;
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Support\Contacts\CustomerMode;
use Wonder\Plugin\Gestionale\Support\Contacts\QuickContacts;

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

    /*
     * I campi sono quelli di customer-account (nome, brand principale, logo,
     * sito, dominio, descrizione e, con il kit, il resto): qui si accende
     * solo il brand kit, con la funzionalità «Brand kit», prima di disegnare
     * o salvare il form. Non al boot del modulo: lì lo stato delle
     * funzionalità si leggerebbe prima del database e resterebbe «tutto
     * spento» per la richiesta.
     */

    public static function formSchema(): array
    {
        static::useKit();

        return parent::formSchema();
    }

    public static function formLayoutSchema(): ?Form
    {
        static::useKit();

        return parent::formLayoutSchema();
    }

    public static function mutateFormValues(array $values, string $mode, string $context = 'backend'): array
    {
        static::useKit();

        return parent::mutateFormValues($values, $mode, $context);
    }

    public static function mutateRequestValues(array $values, string $action, string $context = 'backend', ?array $oldValues = null): array
    {
        static::useKit();

        return parent::mutateRequestValues($values, $action, $context, $oldValues);
    }

    /** Accende il kit con la funzionalità; non lo spegne mai, se l'ha acceso il sito. */
    public static function useKit(): void
    {
        if (Gestionale::feature('brand_kit')) {
            CompanyBrands::useKit();
        }
    }

    /**
     * Il «+» del brand. Dalla scheda di un'azienda crea solo il brand (il
     * legame lo scrive il salvataggio del form); dall'ordine da ufficio arriva
     * anche l'azienda (`QuickContacts::LINK_COMPANY`), e il brand nasce già
     * legato a lei, in fondo ai suoi, senza toccare ruolo e predefinito degli
     * altri. L'azienda
     * si controlla prima di creare: deve esistere e chi scrive deve vederla.
     * Del modal passa solo il nome.
     */
    public static function quickCreateStore(array $values, string $labelField): array
    {
        if (!CustomerMode::companies()) {
            return ['error' => 'Il gestionale lavora solo con le persone: i brand delle aziende non si creano.'];
        }

        $companyId = 0;

        if (array_key_exists(QuickContacts::LINK_COMPANY, $values)) {
            $raw = $values[QuickContacts::LINK_COMPANY];
            $companyId = is_scalar($raw) && ctype_digit(trim((string) $raw)) ? (int) $raw : 0;

            if ($companyId <= 0) {
                return ['error' => 'Scegli prima l\'azienda: il brand nasce legato a lei.'];
            }

            if (!QuickContacts::reachableCompany($companyId)) {
                return ['error' => 'Questa azienda non c\'è più o non è fra le tue: ricarica la pagina.'];
            }
        }

        $result = parent::quickCreateStore(['name' => $values['name'] ?? ''], $labelField);
        $brandId = (int) ($result['id'] ?? 0);

        if ($companyId <= 0 || $brandId <= 0 || CompanyBrands::belongsTo($brandId, $companyId)) {
            return $result;
        }

        try {
            static::linkTo($companyId, $brandId);
        } catch (Throwable) {
            return ['error' => 'Il brand è salvato, ma non sono riuscito a legarlo all\'azienda: aggiungilo dalla scheda dell\'azienda.'];
        }

        return $result;
    }

    /**
     * Aggiunge il brand ai legami aperti dell'azienda. `sync()` stacca quelli
     * che non riceve: si ripassano tutti, con il loro ruolo e il predefinito.
     */
    protected static function linkTo(int $companyId, int $brandId): void
    {
        $items = array_map(static fn (array $link): array => [
            'brand_id' => $link['brand_id'],
            'role' => $link['role'],
            'is_default' => $link['is_default'],
        ], CompanyBrands::linksOf($companyId));
        $items[] = ['brand_id' => $brandId];

        CompanyBrands::sync($companyId, $items);
    }
}
