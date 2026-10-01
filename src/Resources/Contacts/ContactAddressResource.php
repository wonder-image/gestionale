<?php

namespace Wonder\Plugin\Gestionale\Resources\Contacts;

use Throwable;
use Wonder\App\Resources\Support\NavigationOnlyResource;
use Wonder\App\ResourceSchema\NavigationSchema;
use Wonder\App\ResourceSchema\PageSchema;
use Wonder\App\ResourceSchema\PermissionSchema;
use Wonder\Backend\Support\FlashAlert;
use Wonder\Plugin\Gestionale\Models\Contacts\Contact;
use Wonder\Plugin\Gestionale\Models\Contacts\ContactAddress;
use Wonder\Plugin\Gestionale\Support\Orders\OrderSheet;

/**
 * Gli indirizzi di consegna di una scheda, gestiti dalla scheda stessa:
 * aggiungere, modificare, scegliere il predefinito, eliminare.
 *
 * Non ha una pagina sua: la scheda del cliente apre le finestre e questa
 * riceve il POST, poi riporta alla scheda. Gli indirizzi sono sempre di una
 * scheda sola e uno solo è il predefinito.
 */
final class ContactAddressResource extends NavigationOnlyResource
{
    /** La finestra che aggiunge e modifica; la scheda la apre dai pulsanti. */
    public const MODAL_ID = 'wi-indirizzo';

    /** La finestra che chiede conferma prima di eliminare. */
    public const DELETE_MODAL_ID = 'wi-indirizzo-elimina';

    /** I campi che la finestra manda, con la lunghezza massima di ciascuno. */
    public const FIELDS = [
        'label' => 100, 'name' => 100, 'surname' => 100, 'phone_prefix' => 8, 'phone' => 30,
        'street' => 150, 'number' => 20, 'more' => 150, 'cap' => 12, 'city' => 100, 'province' => 100, 'country' => 2,
    ];

    /** Senza questi un corriere non trova la porta. */
    private const REQUIRED = ['street' => 'la via', 'cap' => 'il CAP', 'city' => 'la città'];

    public static function path(): string
    {
        return 'app/gestionale/indirizzi-cliente';
    }

    public static function icon(): string
    {
        return 'bi-geo';
    }

    public static function titleLabel(): string
    {
        return 'Indirizzi di consegna';
    }

    public static function isFormPage(): bool
    {
        return true;
    }

    public static function pageSchema(): PageSchema
    {
        return parent::pageSchema()->only([])->titles(['form' => 'Indirizzi di consegna']);
    }

    /** Le pagine-form leggono `edit` (apertura) e `update` (invio). */
    public static function permissionSchema(): PermissionSchema
    {
        return PermissionSchema::for(static::class)->backend(['edit', 'update'], ['admin', 'administrator']);
    }

    public static function navigationSchema(): NavigationSchema
    {
        // Fuori dal menu: ci si arriva dai pulsanti della scheda del cliente.
        return NavigationSchema::for(static::class)
            ->inSection('anagrafiche')
            ->title('Indirizzi di consegna')
            ->authority(['admin', 'administrator'])
            ->enabled(false);
    }

    /** Dove postano le finestre: la rotta di questa pagina, in POST. */
    public static function submitUrl(): string
    {
        $base = '/backend/'.static::path();

        if (function_exists('__r')) {
            try {
                $named = (string) __r('backend.resource.'.static::slug().'.form');
                $base = $named !== '' ? $named : $base;
            } catch (Throwable) {
                // Rotta non registrata (test, comandi): resta il percorso.
            }
        }

        return $base;
    }

    /**
     * I valori della finestra, puliti: solo i campi noti, senza spazi ai
     * bordi, il paese in maiuscolo e `IT` se manca, il predefinito sì o no.
     *
     * @param array<string, mixed> $values
     * @return array<string, string>
     */
    public static function clean(array $values): array
    {
        $clean = [];

        foreach (array_keys(static::FIELDS) as $campo) {
            $clean[$campo] = trim((string) ($values[$campo] ?? ''));
        }

        $clean['country'] = strtoupper($clean['country']) !== '' ? strtoupper($clean['country']) : 'IT';
        $clean['is_default'] = (string) ($values['is_default'] ?? '') === 'true' ? 'true' : 'false';

        return $clean;
    }

    /**
     * Cosa manca o non va, in una frase; `null` se l'indirizzo è valido.
     *
     * @param array<string, string> $clean i valori di `clean()`
     */
    public static function problem(array $clean): ?string
    {
        $mancano = [];

        foreach (static::REQUIRED as $campo => $nome) {
            if (($clean[$campo] ?? '') === '') {
                $mancano[] = $nome;
            }
        }

        if ($mancano !== []) {
            return 'Manca '.implode(', ', $mancano).'.';
        }

        if (preg_match('/^[A-Z]{2}$/', (string) ($clean['country'] ?? '')) !== 1) {
            return 'Il paese è il codice di due lettere, per esempio IT.';
        }

        foreach (static::FIELDS as $campo => $max) {
            if (mb_strlen((string) ($clean[$campo] ?? '')) > $max) {
                return 'Un campo è troppo lungo: al massimo '.$max.' caratteri.';
            }
        }

        return null;
    }

    /**
     * Fa quello che la finestra ha chiesto — `save`, `default`, `delete` — e
     * dice com'è andata.
     *
     * @param array<string, mixed> $values
     * @return array{ok: bool, message: string}
     */
    public static function run(string $action, int $contactId, int $addressId, array $values = []): array
    {
        $contact = $contactId > 0 ? Contact::findById($contactId) : null;

        if (!is_array($contact) || $contact === [] || (string) ($contact['deleted'] ?? 'false') === 'true') {
            return ['ok' => false, 'message' => 'Scheda non trovata.'];
        }

        $esistente = null;

        if ($addressId > 0 || $action !== 'save') {
            $esistente = static::ownAddress($contactId, $addressId);

            if ($esistente === null) {
                return ['ok' => false, 'message' => 'Indirizzo non trovato.'];
            }
        }

        return match ($action) {
            'save' => static::save($contactId, $esistente, static::clean($values)),
            'default' => static::makeDefault($contactId, $addressId),
            'delete' => static::remove($contactId, $addressId),
            default => ['ok' => false, 'message' => 'Azione non riconosciuta.'],
        };
    }

    /**
     * Riceve il POST di una finestra: `action`, `contact_id`, `address_id` e i
     * campi. Si torna sempre alla scheda del cliente.
     */
    public static function submitFormPage(array $values): string
    {
        $contactId = (int) ($values['contact_id'] ?? 0);
        $result = static::run((string) ($values['action'] ?? ''), $contactId, (int) ($values['address_id'] ?? 0), $values);

        if (headers_sent()) {
            return $result['message'];
        }

        if ($result['ok']) {
            FlashAlert::saved($result['message']);
        } else {
            FlashAlert::custom('Attenzione', $result['message'], 'warning');
        }

        header('Location: '.($contactId > 0 ? CustomerResource::viewUrl($contactId) : '/backend/'.CustomerResource::path().'/'));
        exit();
    }

    /**
     * Gli indirizzi non eliminati di una scheda, nell'ordine in cui si
     * mostrano: il predefinito per primo.
     *
     * @return list<array<string, mixed>>
     */
    public static function addressesOf(int $contactId): array
    {
        try {
            $rows = ContactAddress::find(['contact_id' => $contactId, 'deleted' => 'false'], null, 'position', 'ASC');
        } catch (Throwable) {
            return [];
        }

        if (!is_array($rows) || $rows === []) {
            return [];
        }

        $rows = isset($rows['id']) ? [$rows] : array_values(array_filter($rows, 'is_array'));

        usort($rows, static fn (array $a, array $b): int => [(string) ($b['is_default'] ?? '') === 'true', (int) ($a['position'] ?? 0)]
            <=> [(string) ($a['is_default'] ?? '') === 'true', (int) ($b['position'] ?? 0)]);

        return $rows;
    }

    /**
     * Il pulsante che apre la finestra: con un indirizzo la riempie per
     * modificarlo, senza la lascia vuota per aggiungerne uno.
     *
     * @param array<string, mixed> $address
     */
    public static function openButton(string $label, string $class, array $address = []): string
    {
        $dati = $address === [] ? [] : array_merge(['id' => (int) ($address['id'] ?? 0)], static::clean($address));

        return '<button type="button" class="btn '.OrderSheet::esc($class).'" data-bs-toggle="modal" data-bs-target="#'.static::MODAL_ID.'"'
            .' data-wi-address="'.OrderSheet::esc((string) json_encode($dati, JSON_UNESCAPED_UNICODE)).'">'.$label.'</button>';
    }

    /**
     * Il pulsante che apre la conferma di eliminazione.
     *
     * @param array<string, mixed> $address
     */
    public static function deleteButton(string $label, string $class, array $address): string
    {
        $dati = ['id' => (int) ($address['id'] ?? 0), 'label' => trim((string) ($address['label'] ?? ''))];

        return '<button type="button" class="btn '.OrderSheet::esc($class).'" data-bs-toggle="modal" data-bs-target="#'.static::DELETE_MODAL_ID.'"'
            .' data-wi-address="'.OrderSheet::esc((string) json_encode($dati, JSON_UNESCAPED_UNICODE)).'">'.$label.'</button>';
    }

    /**
     * Il form che fa un indirizzo il predefinito, senza finestra: un solo
     * pulsante.
     */
    public static function defaultForm(int $contactId, int $addressId, string $label, string $class): string
    {
        return '<form method="post" action="'.OrderSheet::esc(static::submitUrl()).'" class="d-inline">'
            .'<input type="hidden" name="action" value="default">'
            .'<input type="hidden" name="contact_id" value="'.$contactId.'">'
            .'<input type="hidden" name="address_id" value="'.$addressId.'">'
            .'<button type="submit" class="btn '.OrderSheet::esc($class).'">'.$label.'</button></form>';
    }

    /**
     * Le due finestre della scheda: aggiungi/modifica ed elimina. Si
     * riempiono dai `data-wi-address` del pulsante che le apre.
     */
    public static function modals(int $contactId): string
    {
        $esc = static fn (string $v): string => OrderSheet::esc($v);
        $url = $esc(static::submitUrl());
        $id = static::MODAL_ID;
        $nascosti = '<input type="hidden" name="contact_id" value="'.$contactId.'">';
        $campo = static fn (string $nome, string $etichetta, int $col, string $extra = ''): string => '<div class="col-'.$col.'">'
            .'<label class="form-label" for="'.$id.'-'.$nome.'">'.$etichetta.'</label>'
            .'<input type="text" class="form-control" id="'.$id.'-'.$nome.'" name="'.$nome.'" maxlength="'.static::FIELDS[$nome].'" '.$extra.'></div>';
        $aiuto = static fn (string $testo): string => ' <i class="bi bi-info-circle text-muted" title="'.$esc($testo).'"></i>';

        $form = '<div class="modal fade" id="'.$id.'" tabindex="-1" aria-hidden="true">'
            .'<div class="modal-dialog modal-dialog-centered modal-lg"><div class="modal-content">'
            .'<form method="post" action="'.$url.'">'
            .'<div class="modal-header"><h5 class="modal-title" data-wi-title>Aggiungi indirizzo</h5>'
            .'<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button></div>'
            .'<div class="modal-body"><div class="row g-3">'
            .$campo('label', 'Etichetta'.$aiuto('Per riconoscerlo: Casa, Ufficio, Magazzino. Si vede solo qui.'), 12)
            .$campo('name', 'Nome'.$aiuto('Chi ritira: spesso non è il titolare della scheda.'), 6)
            .$campo('surname', 'Cognome', 6)
            .$campo('street', 'Via', 8)
            .$campo('number', 'Civico', 4)
            .$campo('more', 'Scala, interno, citofono', 12)
            .$campo('cap', 'CAP', 4)
            .$campo('city', 'Città', 8)
            .$campo('province', 'Provincia', 6)
            .$campo('country', 'Paese'.$aiuto('Il codice di due lettere: IT, FR, DE.'), 6, 'style="text-transform:uppercase"')
            .$campo('phone_prefix', 'Prefisso', 4)
            .$campo('phone', 'Telefono', 8)
            .'<div class="col-12"><div class="form-check">'
            .'<input type="checkbox" class="form-check-input" id="'.$id.'-is_default" name="is_default" value="true">'
            .'<label class="form-check-label" for="'.$id.'-is_default">Indirizzo predefinito'
            .$aiuto('Uno solo per scheda: scegliendo questo, l\'altro smette di esserlo.').'</label></div></div>'
            .'</div></div>'
            .'<div class="modal-footer">'.$nascosti
            .'<input type="hidden" name="action" value="save"><input type="hidden" name="address_id" value="0">'
            .'<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Indietro</button>'
            .'<button type="submit" class="btn btn-primary">Salva</button>'
            .'</div></form></div></div></div>';

        $did = static::DELETE_MODAL_ID;
        $elimina = '<div class="modal fade" id="'.$did.'" tabindex="-1" aria-hidden="true">'
            .'<div class="modal-dialog modal-dialog-centered"><div class="modal-content">'
            .'<form method="post" action="'.$url.'">'
            .'<div class="modal-header"><h5 class="modal-title">Elimina indirizzo</h5>'
            .'<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button></div>'
            .'<div class="modal-body"><p class="mb-0" data-wi-text>Confermi l\'eliminazione di questo indirizzo?</p></div>'
            .'<div class="modal-footer">'.$nascosti
            .'<input type="hidden" name="action" value="delete"><input type="hidden" name="address_id" value="0">'
            .'<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Indietro</button>'
            .'<button type="submit" class="btn btn-danger">Elimina</button>'
            .'</div></form></div></div></div>';

        return $form.$elimina.static::script();
    }

    /**
     * Riempie le finestre quando si aprono. Si aggancia al documento, perché
     * Bootstrap può caricarsi dopo questa pagina: gli eventi `show.bs.modal`
     * salgono fin lì.
     */
    private static function script(): string
    {
        $campi = (string) json_encode(array_merge(array_keys(static::FIELDS), ['is_default']));

        return '<script>(function(){'
            .'var FIELDS='.$campi.',FORM='.json_encode(static::MODAL_ID).',DEL='.json_encode(static::DELETE_MODAL_ID).';'
            .'function dati(e){try{return JSON.parse((e.relatedTarget&&e.relatedTarget.getAttribute("data-wi-address"))||"{}")||{}}catch(x){return {}}}'
            .'document.addEventListener("show.bs.modal",function(e){'
            .'var m=e.target,d=dati(e);if(!m||!m.id){return}'
            .'if(m.id===FORM){'
            .'FIELDS.forEach(function(k){var i=m.querySelector("[name="+k+"]");if(!i){return}'
            .'if(i.type==="checkbox"){i.checked=d[k]==="true"}else{i.value=d[k]!==undefined?d[k]:(k==="country"?"IT":"")}});'
            .'m.querySelector("[name=address_id]").value=d.id||0;'
            .'m.querySelector("[data-wi-title]").textContent=d.id?"Modifica indirizzo":"Aggiungi indirizzo";}'
            .'if(m.id===DEL){m.querySelector("[name=address_id]").value=d.id||0;'
            .'m.querySelector("[data-wi-text]").textContent=d.label?"Confermi l\'eliminazione dell\'indirizzo «"+d.label+"»?":"Confermi l\'eliminazione di questo indirizzo?";}'
            .'});})();</script>';
    }

    /**
     * L'indirizzo di una scheda, se è davvero suo e non è eliminato.
     *
     * @return array<string, mixed>|null
     */
    private static function ownAddress(int $contactId, int $addressId): ?array
    {
        foreach (static::addressesOf($contactId) as $riga) {
            if ((int) ($riga['id'] ?? 0) === $addressId) {
                return $riga;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed>|null $existing
     * @param array<string, string> $clean
     * @return array{ok: bool, message: string}
     */
    private static function save(int $contactId, ?array $existing, array $clean): array
    {
        $problema = static::problem($clean);

        if ($problema !== null) {
            return ['ok' => false, 'message' => $problema];
        }

        if ($existing === null) {
            $posizioni = array_map(static fn (array $r): int => (int) ($r['position'] ?? 0), static::addressesOf($contactId));
            $creato = ContactAddress::create(array_merge($clean, [
                'contact_id' => $contactId,
                'position' => ($posizioni === [] ? 0 : max($posizioni)) + 1,
            ]));

            if (empty($creato->success)) {
                return ['ok' => false, 'message' => 'Non sono riuscito a salvare l\'indirizzo.'];
            }

            $id = (int) ($creato->insert_id ?? 0);
        } else {
            $id = (int) $existing['id'];
            ContactAddress::update($clean, $id);
        }

        if ($clean['is_default'] === 'true') {
            static::clearDefaultExcept($contactId, $id);
        }

        static::ensureDefault($contactId);

        return ['ok' => true, 'message' => $existing === null ? 'Indirizzo aggiunto.' : 'Indirizzo salvato.'];
    }

    /** @return array{ok: bool, message: string} */
    private static function makeDefault(int $contactId, int $addressId): array
    {
        ContactAddress::update(['is_default' => 'true'], $addressId);
        static::clearDefaultExcept($contactId, $addressId);

        return ['ok' => true, 'message' => 'Indirizzo predefinito aggiornato.'];
    }

    /** @return array{ok: bool, message: string} */
    private static function remove(int $contactId, int $addressId): array
    {
        // Nel cestino, come fa la griglia della scheda: `Model::delete` cancellerebbe la riga.
        $fatto = sqlModify(ContactAddress::$table, ['deleted' => 'true', 'is_default' => 'false'], 'id', $addressId);

        if (empty($fatto->success)) {
            return ['ok' => false, 'message' => 'Non sono riuscito a eliminare l\'indirizzo.'];
        }

        static::ensureDefault($contactId);

        return ['ok' => true, 'message' => 'Indirizzo eliminato.'];
    }

    /** Se la scheda ha indirizzi, uno è sempre il predefinito: in mancanza, il primo. */
    private static function ensureDefault(int $contactId): void
    {
        $righe = static::addressesOf($contactId);

        if ($righe !== [] && (string) ($righe[0]['is_default'] ?? '') !== 'true') {
            ContactAddress::update(['is_default' => 'true'], (int) $righe[0]['id']);
        }
    }

    /** Uno solo è il predefinito: gli altri della scheda tornano a «no». */
    private static function clearDefaultExcept(int $contactId, int $keepId): void
    {
        foreach (static::addressesOf($contactId) as $riga) {
            if ((int) $riga['id'] !== $keepId && (string) ($riga['is_default'] ?? '') === 'true') {
                ContactAddress::update(['is_default' => 'false'], (int) $riga['id']);
            }
        }
    }
}
