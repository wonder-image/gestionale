<?php

namespace Wonder\Plugin\Gestionale\Support\Sales;

use Wonder\App\LegacyGlobals;
use Wonder\App\ResourceSchema\Input;
use Wonder\App\Table;
use Wonder\App\Visibility\RowVisibility;
use Wonder\Backend\Support\ResourceFormLayoutRenderer;
use Wonder\Elements\Components\Button;
use Wonder\Elements\Components\Choice;
use Wonder\Elements\Components\Container;
use Wonder\Elements\Components\DataItem;
use Wonder\Elements\Components\Modal;
use Wonder\Plugin\Gestionale\Models\Contacts\Company;
use Wonder\Plugin\Gestionale\Models\Contacts\Contact;
use Wonder\Plugin\Gestionale\Resources\Contacts\CompanyResource;
use Wonder\Plugin\Gestionale\Resources\Contacts\ContactAddressResource;
use Wonder\Plugin\Gestionale\Resources\Contacts\CustomerResource;
use Wonder\Plugin\Gestionale\Support\Contacts\CustomerSheet;
use Wonder\Plugin\CustomerAccount\Support\CompanyBrands;
use Wonder\Plugin\Gestionale\Support\Contacts\DocumentBrands;
use Wonder\Plugin\Gestionale\Support\Contacts\DocumentReferents;
use Wonder\Plugin\Gestionale\Support\Orders\OrderSheet;

/**
 * La colonna del cliente nel nuovo ordine da ufficio (e nel preventivo):
 * Contatti, Fatturazione e Indirizzi di consegna, come nella scheda del
 * cliente. Si modificano dalle finestre e si salvano sulla scheda con una
 * chiamata JSON, senza ricaricare la pagina.
 *
 * Il cliente è una persona (`contacts`) o un'azienda (`companies`, con
 * `company_id`): per l'azienda i riquadri mostrano i suoi dati, Contatti e
 * Fatturazione si salvano sulla sua scheda, le sedi di consegna si spuntano
 * ma si cambiano solo dalla scheda dell'azienda.
 */
final class OfficeCustomerPanel
{
    public const CONTACTS_MODAL = 'wi-oo-contatti';

    public const BILLING_MODAL = 'wi-oo-fatturazione';

    public const COMPANY_BILLING_MODAL = 'wi-oo-fatturazione-azienda';

    /** I campi della scheda che la finestra «Contatti» può cambiare. */
    public const CONTACT_KEYS = ['email', 'phone_prefix', 'phone'];

    /** I campi della scheda che la finestra «Fatturazione» può cambiare. */
    public const BILLING_KEYS = ['name', 'surname', 'cf', 'country', 'street', 'number', 'province', 'city', 'cap', 'more'];

    /** Lo stesso per un'azienda. */
    public const COMPANY_BILLING_KEYS = ['business_name', 'cf', 'pi', 'sdi', 'pec', 'country', 'street', 'number', 'province', 'city', 'cap', 'more'];

    /**
     * Tutto quello che serve alla pagina dopo aver scelto (o cambiato) un
     * cliente: i valori dell'ordine, quelli della scheda per le finestre, gli
     * indirizzi e l'HTML dei tre riquadri. Vuoto se la scheda non c'è.
     *
     * Con `$companyId` il cliente è l'azienda e `$contactId` il referente
     * scelto (0: il principale, se c'è). `customer` è
     * `OfficeOrders::customerDefaults()`; `contact` ha `kind` (`contact` o
     * `company`), `id`, `company_id` e i campi delle finestre; `referents` le
     * persone dell'azienda e `brands` i suoi brand con il logo (vuoti per una
     * persona). Le sedi dell'azienda seguono `$brandId` (`null` = il brand
     * proposto): prima quelle del brand, poi quelle per tutti; `proposed` è
     * la sede che la pagina spunta se l'operatore non ne ha scelta una.
     *
     * @return array<string, mixed>
     */
    public static function payload(int $contactId, int $companyId = 0, ?int $brandId = null): array
    {
        if ($companyId > 0) {
            return self::companyPayload($contactId, $companyId, $brandId);
        }

        $contact = self::contact($contactId);

        if ($contact === null) {
            return [];
        }

        $addresses = ContactAddressResource::addressesOf($contactId);
        $valori = ['kind' => 'contact', 'id' => $contactId, 'company_id' => 0];

        foreach (array_merge(self::CONTACT_KEYS, self::BILLING_KEYS) as $key) {
            $valori[$key] = (string) ($contact[$key] ?? '');
        }

        return [
            'customer' => (object) OfficeOrders::customerDefaults($contactId),
            'contact' => $valori,
            'addresses' => array_map(static fn (array $a): array => self::address($a), $addresses),
            'referents' => [],
            'brands' => [],
            'html' => [
                'contacts' => self::contactsHtml($contact),
                'billing' => self::billingHtml($contact),
                'addresses' => self::addressesHtml($addresses),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function companyPayload(int $contactId, int $companyId, ?int $brandId): array
    {
        $company = self::company($companyId);

        if ($company === null) {
            return [];
        }

        $brandId = max(0, $brandId ?? OfficeOrders::defaultBrand($companyId));
        $customer = OfficeOrders::customerDefaults($contactId, $companyId, $brandId);
        $addresses = OfficeOrders::companyAddresses($companyId, $brandId);
        $valori = ['kind' => 'company', 'id' => (int) ($customer['customer_id'] ?? 0), 'company_id' => $companyId];

        foreach (array_merge(self::CONTACT_KEYS, self::COMPANY_BILLING_KEYS) as $key) {
            $valori[$key] = (string) ($company[$key] ?? '');
        }

        // Le sedi non hanno la ragione sociale: è quella dell'azienda.
        $ragione = (string) ($company['business_name'] ?? '');
        $righe = array_map(static function (array $a) use ($ragione): array {
            $out = self::address($a);
            $out['business_name'] = $out['business_name'] !== '' ? $out['business_name'] : $ragione;

            return $out;
        }, $addresses);

        return [
            'customer' => (object) $customer,
            'contact' => $valori,
            'addresses' => $righe,
            'proposed' => (int) ($addresses[0]['id'] ?? 0),
            'referents' => array_map(static fn (array $r): array => [
                'id' => $r['id'],
                'label' => $r['name'].($r['role'] !== '' ? ' — '.$r['role'] : ''),
                'is_primary' => $r['is_primary'],
            ], DocumentReferents::people($companyId)),
            // Il predefinito è quello che la tendina propone (`CompanyBrands::defaultOf()`).
            'brands' => array_map(static fn (array $link): array => [
                'id' => $link['brand_id'],
                'label' => CompanyBrands::label($link['brand']),
                'is_default' => $link['is_default'],
                // Un URL controllato: lo script lo mette in `src` senza HTML.
                'logo' => DocumentBrands::logoUrl($link['brand']),
            ], CompanyBrands::linksOf($companyId)),
            'html' => [
                'contacts' => self::contactsHtml($company),
                'billing' => self::companyBillingHtml($company),
                'addresses' => self::addressesHtml($addresses),
            ],
        ];
    }

    /** @param array<string, mixed> $contact */
    public static function contactsHtml(array $contact): string
    {
        $get = static fn (string $key): string => trim((string) ($contact[$key] ?? ''));
        $dato = static fn (string $etichetta, string $valore): DataItem => DataItem::make($etichetta, $valore !== '' ? $valore : '—')
            ->columnSpan(12);

        return ResourceFormLayoutRenderer::renderLayout((new Container)->columns(12)->components([
            $dato('Email', $get('email')),
            $dato('Telefono', trim($get('phone_prefix').' '.$get('phone'))),
        ]));
    }

    /** @param array<string, mixed> $contact */
    public static function billingHtml(array $contact): string
    {
        $nome = trim((string) ($contact['name'] ?? '').' '.(string) ($contact['surname'] ?? ''));
        $items = array_map(
            static fn (DataItem $item): DataItem => $item->columnSpan(12),
            CustomerSheet::billing($contact)
        );

        return ResourceFormLayoutRenderer::renderLayout((new Container)->columns(12)->components([
            DataItem::make('Intestatario', $nome !== '' ? $nome : '—')->columnSpan(12),
            ...$items,
        ]));
    }

    /** @param array<string, mixed> $company */
    public static function companyBillingHtml(array $company): string
    {
        $ragione = trim((string) ($company['business_name'] ?? ''));
        $items = array_map(
            static fn (DataItem $item): DataItem => $item->columnSpan(12),
            CustomerSheet::billing($company)
        );

        return ResourceFormLayoutRenderer::renderLayout((new Container)->columns(12)->components([
            DataItem::make('Ragione sociale', $ragione !== '' ? $ragione : '—')->columnSpan(12),
            ...$items,
        ]));
    }

    /**
     * Un riquadro a spunta per indirizzo, con Modifica ed Elimina dentro, e
     * «Aggiungi indirizzo» sotto. Senza spunta si spedisce all'indirizzo di
     * fatturazione.
     *
     * @param list<array<string, mixed>> $addresses
     */
    public static function addressesHtml(array $addresses): string
    {
        $html = '';

        foreach ($addresses as $address) {
            $id = (int) ($address['id'] ?? 0);
            $etichetta = trim((string) ($address['label'] ?? ''));
            $predefinito = (string) ($address['is_default'] ?? '') === 'true';
            $nome = $etichetta !== '' ? $etichetta : 'Indirizzo';

            $html .= Choice::make('oo_address', $id)
                ->type('checkbox')
                ->title($nome.($predefinito ? ' · Predefinito' : ''))
                ->text(self::line($address))
                ->actions(
                    ContactAddressResource::openButton('Modifica', $address)->variant('secondary')->outline()->size('sm'),
                    Button::make('')
                        ->icon('bi bi-trash')
                        ->attr('aria-label', 'Elimina l\'indirizzo «'.$nome.'»')
                        ->attr('title', 'Elimina')
                        ->variant('danger')
                        ->outline()
                        ->size('sm')
                        ->confirm('Confermi l\'eliminazione dell\'indirizzo «'.$nome.'»?', 'Elimina indirizzo', 'Elimina', 'danger')
                        ->attr('data-wi-oo-address-delete', (string) $id)
                )
                ->render('bootstrap');
        }

        if ($addresses === []) {
            $html .= '<p class="text-muted small mb-2">'.OrderSheet::esc(CustomerSheet::noDelivery()).'</p>';
        } else {
            $html .= '<p class="text-muted small mb-2">Senza spunta si spedisce all\'indirizzo di fatturazione.</p>';
        }

        return '<div class="d-grid gap-2">'.$html.'</div>'
            .'<div class="mt-2">'.ContactAddressResource::openButton('Aggiungi indirizzo')->icon('bi bi-plus-lg')->variant('primary')->outline()->size('sm')->render('bootstrap').'</div>';
    }

    /**
     * Salva sulla scheda del cliente quello che una finestra ha mandato:
     * `contacts` e `billing` passano dal giro del core (controlli della
     * Resource, preparazione della tabella, `afterUpdate`), `save` e `delete`
     * dagli indirizzi della scheda.
     *
     * Con `$companyId` si salva la scheda dell'azienda, sedi comprese.
     *
     * @param array<string, mixed> $post
     * @return array{ok: bool, message: string, id?: int}
     */
    public static function save(string $action, int $contactId, array $post, int $companyId = 0): array
    {
        if ($companyId > 0) {
            return self::saveCompany($action, $companyId, $post);
        }

        if (in_array($action, ['save', 'delete'], true)) {
            if (self::contact($contactId) === null) {
                return ['ok' => false, 'message' => 'Scheda non trovata.'];
            }

            return ContactAddressResource::run($action, $contactId, (int) ($post['address_id'] ?? 0), $post);
        }

        $keys = match ($action) {
            'contacts' => self::CONTACT_KEYS,
            'billing' => self::BILLING_KEYS,
            default => null,
        };

        if ($keys === null) {
            return ['ok' => false, 'message' => 'Azione non riconosciuta.'];
        }

        $old = self::contact($contactId);

        if ($old === null) {
            return ['ok' => false, 'message' => 'Scheda non trovata.'];
        }

        $values = [];

        foreach ($keys as $key) {
            $values[$key] = trim((string) ($post[$key] ?? ''));
        }

        return self::update(CustomerResource::class, $contactId, $values, $old);
    }

    /**
     * @param array<string, mixed> $post
     * @return array{ok: bool, message: string}
     */
    private static function saveCompany(string $action, int $companyId, array $post): array
    {
        $keys = match ($action) {
            'contacts' => self::CONTACT_KEYS,
            'billing' => self::COMPANY_BILLING_KEYS,
            'save', 'delete' => [],
            default => null,
        };

        if ($keys === null) {
            return ['ok' => false, 'message' => 'Azione non riconosciuta.'];
        }

        $old = self::company($companyId);

        if ($old === null) {
            return ['ok' => false, 'message' => 'Azienda non trovata.'];
        }

        if ($keys === []) {
            return ContactAddressResource::run($action, $companyId, (int) ($post['address_id'] ?? 0), $post, ContactAddressResource::OWNER_COMPANY);
        }

        $values = [];

        foreach ($keys as $key) {
            $values[$key] = trim((string) ($post[$key] ?? ''));
        }

        return self::update(CompanyResource::class, $companyId, $values, $old);
    }

    /**
     * Le finestre Contatti e Fatturazione (campi della scheda cliente) e
     * quella degli indirizzi. Vanno fuori dal form dell'ordine.
     */
    public static function modals(string $saveUrl): string
    {
        $campo = static fn (string $key, int $col): Input => CustomerResource::getInput($key)->columnSpan($col);

        $contatti = Modal::make('Contatti')
            ->id(self::CONTACTS_MODAL)
            ->form($saveUrl, hidden: ['action' => 'contacts', 'contact_id' => 0, 'company_id' => 0])
            ->columns(12)
            ->components([
                $campo('email', 12),
                $campo('phone_prefix', 4),
                $campo('phone', 8),
            ])
            ->cancel('Indietro')
            ->submit('Salva');

        $fatturazione = Modal::make('Fatturazione')
            ->id(self::BILLING_MODAL)
            ->size('lg')
            ->help('Si salva sulla scheda del cliente: vale anche per i prossimi ordini.')
            ->form($saveUrl, hidden: ['action' => 'billing', 'contact_id' => 0, 'company_id' => 0])
            ->columns(12)
            ->components([
                $campo('name', 4),
                $campo('surname', 4),
                $campo('cf', 4),
                $campo('street', 9),
                $campo('number', 3),
                $campo('country', 3),
                $campo('province', 3),
                $campo('city', 3),
                $campo('cap', 3),
                $campo('more', 12),
            ])
            ->cancel('Indietro')
            ->submit('Salva');

        $azienda = static fn (string $key, int $col): Input => CompanyResource::getInput($key)->columnSpan($col);

        $fatturazioneAzienda = Modal::make('Fatturazione')
            ->id(self::COMPANY_BILLING_MODAL)
            ->size('lg')
            ->help('Si salva sulla scheda dell\'azienda: vale anche per i prossimi ordini.')
            ->form($saveUrl, hidden: ['action' => 'billing', 'contact_id' => 0, 'company_id' => 0])
            ->columns(12)
            ->components([
                $azienda('business_name', 12),
                $azienda('cf', 4),
                $azienda('pi', 4),
                $azienda('sdi', 4),
                $azienda('pec', 12),
                $azienda('street', 9),
                $azienda('number', 3),
                $azienda('country', 3),
                $azienda('province', 3),
                $azienda('city', 3),
                $azienda('cap', 3),
                $azienda('more', 12),
            ])
            ->cancel('Indietro')
            ->submit('Salva');

        return $contatti->render('bootstrap')
            .$fatturazione->render('bootstrap')
            .$fatturazioneAzienda->render('bootstrap')
            .ContactAddressResource::modals(0);
    }

    /**
     * Un indirizzo come lo usa la pagina: i campi della spedizione e la riga
     * da confrontare con quella salvata nell'ordine.
     *
     * @param array<string, mixed> $address
     * @return array<string, mixed>
     */
    private static function address(array $address): array
    {
        $out = ['id' => (int) ($address['id'] ?? 0), 'is_default' => (string) ($address['is_default'] ?? '') === 'true'];

        foreach (OfficeOrders::shippingKeys() as $key) {
            $out[$key] = (string) ($address[$key] ?? '');
        }

        return $out;
    }

    /** L'indirizzo su una riga, testo semplice. @param array<string, mixed> $a */
    private static function line(array $a): string
    {
        $get = static fn (string $key): string => trim((string) ($a[$key] ?? ''));
        $via = trim($get('street').' '.$get('number'));
        $citta = trim($get('cap').' '.$get('city').($get('province') !== '' ? ' ('.$get('province').')' : ''));
        $chi = trim($get('name').' '.$get('surname'));

        return implode(' · ', array_filter([$get('business_name'), $chi, $via, $citta, $get('country')], static fn (string $s): bool => $s !== ''));
    }

    /**
     * La scheda, solo se è un cliente: un fornitore non si legge né si
     * modifica da qui.
     *
     * @return array<string, mixed>|null
     */
    private static function contact(int $contactId): ?array
    {
        $contact = $contactId > 0 ? Contact::findById($contactId) : null;

        if (!is_array($contact) || $contact === [] || (string) ($contact['deleted'] ?? 'false') === 'true') {
            return null;
        }

        if ((string) ($contact[CustomerResource::roleColumn()] ?? 'false') !== 'true') {
            return null;
        }

        // Il commerciale non legge né modifica i clienti degli altri (#77).
        return RowVisibility::visible(Contact::$table, $contactId, RowVisibility::currentUser()) ? $contact : null;
    }

    /**
     * L'azienda, solo se è un cliente.
     *
     * @return array<string, mixed>|null
     */
    private static function company(int $companyId): ?array
    {
        $company = $companyId > 0 ? Company::findById($companyId) : null;

        if (!is_array($company) || $company === [] || (string) ($company['deleted'] ?? 'false') === 'true') {
            return null;
        }

        if ((string) ($company[CompanyResource::roleColumn()] ?? 'false') !== 'true') {
            return null;
        }

        return RowVisibility::visible(Company::$table, $companyId, RowVisibility::currentUser()) ? $company : null;
    }

    /**
     * Il giro di `ResourcePageController::update()` del core, su una parte
     * dei campi: controlli della Resource, preparazione della tabella, poi
     * `afterUpdate` ed export.
     *
     * @param class-string<CustomerResource> $resource
     * @param array<string, string> $values
     * @param array<string, mixed> $old
     * @return array{ok: bool, message: string}
     */
    private static function update(string $resource, int $id, array $values, array $old): array
    {
        $GLOBALS['ALERT'] = '';
        $table = $resource::modelTable();
        LegacyGlobals::set('NAME', (object) [
            'table' => $table,
            'folder' => $resource::legacyFolder(),
            'schema' => $resource::prepareSchemaName(),
        ]);

        try {
            $values = $resource::mutateRequestValues($resource::stripRelationInputValues($values), 'update', 'backend', $old);
        } catch (\InvalidArgumentException|\JsonException $error) {
            return ['ok' => false, 'message' => $error->getMessage()];
        }

        $schema = $resource::prepareSchemaName();

        if (Table::has($schema)) {
            $values = Table::key($schema)->prepareFor($table, $values, $old);
        } elseif (Table::has($table)) {
            $values = Table::key($table)->prepare($values, $old);
        }

        if (!empty($GLOBALS['ALERT'])) {
            return ['ok' => false, 'message' => strip_tags((string) $GLOBALS['ALERT'])];
        }

        $modelClass = $resource::modelClass();
        $result = $modelClass::query()->Update($modelClass::$table, $values, 'id', $id);

        if (empty($result->success)) {
            return ['ok' => false, 'message' => 'Non sono riuscito a salvare la scheda.'];
        }

        $resource::afterUpdate($id, $result, $values);
        $resource::exportSyncData();

        return ['ok' => true, 'message' => $resource::isCompanySide() ? 'Scheda dell\'azienda aggiornata.' : 'Scheda del cliente aggiornata.'];
    }
}
