<?php

namespace Wonder\Plugin\Gestionale\Support\Sales;

use Wonder\App\LegacyGlobals;
use Wonder\App\ResourceSchema\Input;
use Wonder\App\Table;
use Wonder\Backend\Support\ResourceFormLayoutRenderer;
use Wonder\Elements\Components\Button;
use Wonder\Elements\Components\Choice;
use Wonder\Elements\Components\Container;
use Wonder\Elements\Components\DataItem;
use Wonder\Elements\Components\Modal;
use Wonder\Plugin\Gestionale\Models\Contacts\Contact;
use Wonder\Plugin\Gestionale\Resources\Contacts\ContactAddressResource;
use Wonder\Plugin\Gestionale\Resources\Contacts\CustomerResource;
use Wonder\Plugin\Gestionale\Support\Contacts\CustomerSheet;
use Wonder\Plugin\Gestionale\Support\Orders\OrderSheet;

/**
 * La colonna del cliente nel nuovo ordine da ufficio (e nel preventivo):
 * Contatti, Fatturazione e Indirizzi di consegna, come nella scheda del
 * cliente. Si modificano dalle finestre e si salvano sulla scheda con una
 * chiamata JSON, senza ricaricare la pagina.
 */
final class OfficeCustomerPanel
{
    public const CONTACTS_MODAL = 'wi-oo-contatti';

    public const BILLING_MODAL = 'wi-oo-fatturazione';

    /** I campi della scheda che la finestra «Contatti» può cambiare. */
    public const CONTACT_KEYS = ['email', 'phone_prefix', 'phone'];

    /** I campi della scheda che la finestra «Fatturazione» può cambiare. */
    public const BILLING_KEYS = ['type', 'name', 'surname', 'business_name', 'cf', 'pi', 'sdi', 'pec', 'country', 'street', 'number', 'province', 'city', 'cap', 'more'];

    /**
     * Tutto quello che serve alla pagina dopo aver scelto (o cambiato) un
     * cliente: i valori dell'ordine, quelli della scheda per le finestre, gli
     * indirizzi e l'HTML dei tre riquadri. Vuoto se la scheda non c'è.
     *
     * @return array<string, mixed>
     */
    public static function payload(int $contactId): array
    {
        $contact = self::contact($contactId);

        if ($contact === null) {
            return [];
        }

        $addresses = ContactAddressResource::addressesOf($contactId);
        $valori = ['id' => $contactId];

        foreach (array_merge(self::CONTACT_KEYS, self::BILLING_KEYS) as $key) {
            $valori[$key] = (string) ($contact[$key] ?? '');
        }

        return [
            'customer' => (object) OfficeOrders::customerDefaults($contactId),
            'contact' => $valori,
            'addresses' => array_map(static fn (array $a): array => self::address($a), $addresses),
            'html' => [
                'contacts' => self::contactsHtml($contact),
                'billing' => self::billingHtml($contact),
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
        $nome = (string) ($contact['type'] ?? '') === 'business'
            ? trim((string) ($contact['business_name'] ?? ''))
            : trim((string) ($contact['name'] ?? '').' '.(string) ($contact['surname'] ?? ''));
        $items = array_map(
            static fn (DataItem $item): DataItem => $item->columnSpan(12),
            CustomerSheet::billing($contact)
        );

        return ResourceFormLayoutRenderer::renderLayout((new Container)->columns(12)->components([
            DataItem::make('Intestatario', $nome !== '' ? $nome : '—')->columnSpan(12),
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
                    ContactAddressResource::openButton('Modifica', 'btn-outline-secondary btn-sm', $address),
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
            .'<div class="mt-2">'.ContactAddressResource::openButton('<i class="bi bi-plus-lg"></i> Aggiungi indirizzo', 'btn-outline-primary btn-sm').'</div>';
    }

    /**
     * Salva sulla scheda del cliente quello che una finestra ha mandato:
     * `contacts` e `billing` passano dal giro del core (controlli della
     * Resource, preparazione della tabella, `afterUpdate`), `save` e `delete`
     * dagli indirizzi della scheda.
     *
     * @param array<string, mixed> $post
     * @return array{ok: bool, message: string, id?: int}
     */
    public static function save(string $action, int $contactId, array $post): array
    {
        if (in_array($action, ['save', 'delete'], true)) {
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

        return self::update($contactId, $values, $old);
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
            ->form($saveUrl, hidden: ['action' => 'contacts', 'contact_id' => 0])
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
            ->form($saveUrl, hidden: ['action' => 'billing', 'contact_id' => 0])
            ->columns(12)
            ->components([
                $campo('type', 4),
                $campo('name', 4),
                $campo('surname', 4),
                $campo('business_name', 12),
                $campo('cf', 6),
                $campo('pi', 6),
                $campo('sdi', 4),
                $campo('pec', 8),
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

        return $contatti->render('bootstrap')
            .$fatturazione->render('bootstrap')
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

        foreach (['country', 'province', 'city', 'cap', 'street', 'number', 'more', 'phone_prefix', 'phone', 'name', 'surname', 'place_id', 'lat', 'lng'] as $key) {
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

        return implode(' · ', array_filter([$chi, $via, $citta, $get('country')], static fn (string $s): bool => $s !== ''));
    }

    /** @return array<string, mixed>|null */
    private static function contact(int $contactId): ?array
    {
        $contact = $contactId > 0 ? Contact::findById($contactId) : null;

        if (!is_array($contact) || $contact === [] || (string) ($contact['deleted'] ?? 'false') === 'true') {
            return null;
        }

        return $contact;
    }

    /**
     * Il giro di `ResourcePageController::update()` del core, su una parte
     * dei campi: controlli della Resource, preparazione della tabella, poi
     * `afterUpdate` ed export.
     *
     * @param array<string, string> $values
     * @param array<string, mixed> $old
     * @return array{ok: bool, message: string}
     */
    private static function update(int $id, array $values, array $old): array
    {
        $GLOBALS['ALERT'] = '';
        $table = CustomerResource::modelTable();
        LegacyGlobals::set('NAME', (object) [
            'table' => $table,
            'folder' => CustomerResource::legacyFolder(),
            'schema' => CustomerResource::prepareSchemaName(),
        ]);

        try {
            $values = CustomerResource::mutateRequestValues(CustomerResource::stripRelationInputValues($values), 'update', 'backend', $old);
        } catch (\InvalidArgumentException|\JsonException $error) {
            return ['ok' => false, 'message' => $error->getMessage()];
        }

        $schema = CustomerResource::prepareSchemaName();

        if (array_key_exists($schema, Table::$list)) {
            $values = Table::key($schema)->prepareFor($table, $values, $old);
        } elseif (array_key_exists($table, Table::$list)) {
            $values = Table::key($table)->prepare($values, $old);
        }

        if (!empty($GLOBALS['ALERT'])) {
            return ['ok' => false, 'message' => strip_tags((string) $GLOBALS['ALERT'])];
        }

        $modelClass = CustomerResource::modelClass();
        $result = $modelClass::query()->Update($modelClass::$table, $values, 'id', $id);

        if (empty($result->success)) {
            return ['ok' => false, 'message' => 'Non sono riuscito a salvare la scheda.'];
        }

        CustomerResource::afterUpdate($id, $result, $values);
        CustomerResource::exportSyncData();

        return ['ok' => true, 'message' => 'Scheda del cliente aggiornata.'];
    }
}
