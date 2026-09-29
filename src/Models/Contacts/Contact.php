<?php

namespace Wonder\Plugin\Gestionale\Models\Contacts;

use Throwable;
use Wonder\App\Model;
use Wonder\App\Schema\Extensions\AddressExtension;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Plugin\Gestionale\Support\Catalog\Code;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Sql\TableSchema as Column;

/**
 * Una scheda della rubrica: un cliente, un fornitore, o tutti e due.
 *
 * **Una scheda è una identità fiscale**, non un ruolo: la stessa azienda a cui
 * vendi e da cui compri è una riga sola, con due interruttori. Duplicarla
 * vorrebbe dire due partite IVA uguali e due storie separate della stessa
 * persona.
 *
 * I dati di fatturazione arrivano da `AddressExtension::billing()` del core —
 * tipo, ragione sociale, codice fiscale e partita IVA **già validati**, SDI,
 * PEC, indirizzo e telefono. Il modulo aggiunge solo quello che il core non
 * sa: i ruoli, l'email, l'account del sito e le scelte commerciali.
 *
 * `price_list_id`, `payment_method_id` e `payment_term_id` nascono adesso ma
 * non hanno nessun campo nel form: non c'è ancora niente da scegliere, e il
 * campo arriverà con listini e pagamenti.
 *
 * Un fornitore è legato alle opzioni che si comprano da lui
 * (`gst_product_suppliers`): l'articolo senza varianti ha la sua sola
 * opzione. Finché ha un legame in vendita la scheda non si elimina e non
 * perde il ruolo: lo controlla `CustomerResource` con
 * `ProductSuppliers::countForSupplier()`.
 */
final class Contact extends Model
{
    public static string $table = 'gst_contacts';
    public static string $folder = 'gestionale/contacts';
    public static string $icon = 'bi bi-person-vcard';

    /** I dati di fatturazione del core, sempre con la stessa configurazione. */
    public static function billing(): AddressExtension
    {
        // Niente link a Google Maps: l'indirizzo di fatturazione non si visita.
        return AddressExtension::billing(countryDefault: 'IT')->withLink(false);
    }

    /** La rubrica è il lavoro di chi vende, non configurazione da spostare. */
    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            ...static::sqlColumnsFromDataSchema(['code', 'email', 'color']),
            ...static::billing()->tableSchema(),
            Column::key('is_customer')->enum(['true', 'false'])->default('true'),
            Column::key('is_supplier')->enum(['true', 'false'])->default('false'),
            // Nessuna chiave esterna: le tabelle degli utenti del core, dei
            // listini e dei pagamenti o non sono nostre o non esistono ancora,
            // e lo zero non potrebbe puntare a niente.
            Column::key('user_id')->int(),
            Column::key('price_list_id')->int(),
            Column::key('payment_method_id')->int(),
            Column::key('payment_term_id')->int(),
            Column::key('note')->type('TEXT'),
            Column::key('custom_data')->json(),
            Column::key('active')->enum(['true', 'false'])->default('true'),
        ];
    }

    public static function tablePseudos(): array
    {
        return [
            'ind_customer' => ['index' => 'is_customer'],
            'ind_supplier' => ['index' => 'is_supplier'],
            'ind_user' => ['index' => 'user_id'],
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('code')->text()->uniqueCode(Codes::CONTACT),
            ...static::billing()->dataSchema(),
            Field::key('email')->email(),
            Field::key('is_customer')->text()->sanitize(false),
            Field::key('is_supplier')->text()->sanitize(false),
            Field::key('user_id')->number()->decimals(0),
            Field::key('price_list_id')->number()->decimals(0),
            Field::key('payment_method_id')->number()->decimals(0),
            Field::key('payment_term_id')->number()->decimals(0),
            Field::key('color')->text()->sanitize(false),
            Field::key('note')->text(),
            Field::key('custom_data')->json(),
            Field::key('active')->text()->sanitize(false),
        ];
    }

    /** L'indirizzo già composto, come lo mostra il core. */
    public static function decorate(array $row): array
    {
        try {
            return static::billing()->decorate($row);
        } catch (Throwable) {
            // L'indirizzo "bello" lo compone il core con le sue funzioni
            // globali, che nei comandi di `forge` non esistono: lì la riga
            // torna com'è invece di far esplodere chi la legge.
            return $row;
        }
    }

    /**
     * Codice nuovo per una scheda.
     *
     * `Model::prepare()` formatta i valori ma non genera i codici unici: li fa
     * il flusso dei form. Chi inserisce una riga da codice (i dati di prova,
     * un ordine da ospite in E1) chiede il codice qui.
     */
    public static function newCode(): string
    {
        return Code::make(static::class, Codes::CONTACT);
    }

    public static function create(array $values): object
    {
        if (trim((string) ($values['code'] ?? '')) === '') {
            $values['code'] = static::newCode();
        }

        return parent::create($values);
    }
}
