---
icon: plug
---

# Hook del sito

Un sito può agganciarsi al gestionale senza toccarne il codice: scrive una
classe, la dichiara nella configurazione del modulo e riscrive solo i metodi
che gli servono.

```php
// custom/class/Gestionale/NotificheMagazzino.php
final class NotificheMagazzino extends Wonder\Plugin\Gestionale\Extensions\GestionaleExtension
{
    public function onStatusChanged(string $entity, int $entityId, string $field, string $from, string $to): void
    {
        // avvisa il capo magazzino
    }
}
```

```php
// custom/config/modules/gestionale.php
'extensions' => [
    App\Gestionale\NotificheMagazzino::class,
],
```

## Hook di G1

| Hook | Quando | Può bloccare |
|---|---|---|
| `onStatusChanged($entity, $entityId, $field, $from, $to)` | dopo un cambio di stato registrato da `StatusLogger` | no |
| `beforeEmailSend($key, $message): array` | prima di mandare un'email; torna il messaggio, anche cambiato | no |

Gli altri hook nascono con il sotto-progetto che li usa.

### Le email che passano da `beforeEmailSend`

| Chiave | Email | Messaggio |
|---|---|---|
| `stock.low_stock` | i prodotti sotto la scorta minima | `['to' => list<string>, 'cc' => list<string>, 'subject' => string, 'body' => string, 'attachments' => array]` |
| `order.<chiave>` | le email degli ordini (`OrderNotifier`) | come sopra; `cc` pieno per `quote_sent`, `office_confirmed`, `delivery_note_sent` con i referenti |

- `to` e `cc` possono tornare come lista o come stringa separata da virgole: il
  gestionale li rilegge e scarta gli indirizzi non validi. Dal `cc` si tolgono
  i doppioni e chi è già in `to`.
- Le copie viaggiano nel CC della prima email accettata: con più `to` le altre
  partono senza copia. Se nessuna email parte, le copie finiscono in `failed`.
- Un `to` vuoto **ferma l'invio**, e gli avvisi contano come mandati: è una
  scelta del sito, non un guasto.
- Un `to` fatto solo di indirizzi non validi ferma l'invio allo stesso modo, ma
  lascia una segnalazione per lo sviluppatore (`error_reports`) con gli
  indirizzi scartati: somiglia più a uno sbaglio che a una scelta.
- Un'estensione che solleva un'eccezione finisce nel log e l'email parte con il
  messaggio com'era prima di lei.

```php
public function beforeEmailSend(string $key, array $message): array
{
    if ($key === 'stock.low_stock') {
        $message['to'][] = 'magazzino@example.com';
    }

    return $message;
}
```

### Aspetto delle email

Le email del gestionale (ordini, abbonamenti, sotto scorta, avviso dei
pagamenti) si compongono con `Wonder\App\Support\Mail\MailMessage` del core:
stessa grafica delle altre email del sito, pulsanti, riepiloghi. Il corpo
composto inizia con `<!--wi-mail:v1-->` e `Mailer::prepare()` lo manda così
com'è; un corpo scritto a mano passa ancora da `Mailer::shield()`.

Il corpo resta quello delle viste in `view/emails/` in due casi:

- il core non ha ancora `MailMessage` (`MailBuilder::available()` falso);
- il sito ha copiato la vista in `custom/modules/gestionale/view/emails/`:
  la sua copia vince sempre (`MailBuilder::forView()` torna `null`).

In `beforeEmailSend` il `body` composto si può sostituire del tutto. Se invece
gli si aggiunge HTML davanti, il segno `<!--wi-mail:v1-->` non è più
all'inizio: il corpo parte come uno scritto a mano, difeso da `shield()`.
Per aggiungere un blocco conviene copiare la vista o comporre un nuovo
`MailMessage`.

Serve un core con `MailMessage::items()` e `table()`: con uno più vecchio
`MailBuilder::available()` è falso e si torna alle viste. Gli ordini usano
`items()`: una riga per articolo con nome, scelte del bundle e
personalizzazioni nella descrizione (più lo SKU per il negozio), quantità,
prezzo unitario, prezzo di listino barrato se c'è uno sconto e totale riga; le
righe di testo vanno in un blocco di testo sotto. I totali scompongono
prodotti, sconto, spedizione, costi e IVA (ognuno solo se diverso da zero, IVA
come "di cui IVA" se i prezzi la comprendono) e finiscono con il totale.

Le foto dei prodotti vanno **solo nelle email al cliente**, e solo se **tutti**
i prodotti dell'ordine ne hanno una: altrimenti nessuna colonna foto, niente
segnaposto grigi. `MailBuilder::thumbnail()` sceglie la misura più leggera
generata al caricamento che resta nitida a 64px su schermi retina (la prima
larghezza di `ImageSizes::widths()` da 128px in su, di solito 240px), nel
formato dell'originale perché Outlook non legge il webp (una foto caricata in
webp resta webp: Outlook per Windows non la mostra). Se quella misura non
c'è su disco va bene solo un originale già piccolo; altrimenti la foto salta.
Per le righe senza la foto copiata nell'ordine `OrderNotifier` ripiega su
quella di oggi del prodotto (`OrderNotifier::withPhotos()`, solo i prodotti
principali).

L'email sotto scorta usa `table()`: prodotto (con opzione, SKU e sede sotto),
disponibili e scorta minima allineati a destra.

## Formati delle etichette

`labelFormats($formats): array` riceve i formati delle etichette con codice a
barre (chiave => `Wonder\Support\Labels\LabelFormat` del core) e torna quelli da offrire nella
finestra di stampa, con i propri aggiunti o tolti.

```php
public function labelFormats(array $formats): array
{
    $formats['roll-60x40'] = LabelFormat::roll('roll-60x40', 'Rotolo 60 × 40', 60.0, 40.0);
    // foglio A4: LabelFormat::a4($key, $name, $colonne, $righe, $larghezza, $altezza)

    return $formats;
}
```

Ciò che non è un `LabelFormat` con misure positive si scarta; se non resta
niente, valgono i formati standard. Il formato in uso è `label_format` delle
Impostazioni (`LabelFormats::current()`): se l'hook lo toglie, vale il primo.

Le etichette si stampano dalla pagina *Catalogo → Etichette*
(`LabelsResource`, una `NavigationOnlyResource` con vista
`view/pages/labels.php`) e dal carico (`GET {id}/etichette/`,
`LabelDialog::route()`). La pagina registra due rotte: `page` (GET) e `print`
(`POST stampa/`, con CSRF, `labels_source` = `scope`). Il modulo usa il
selettore dei coupon (`ScopeForm`): `LabelsResource::printQuery()` ne fa la
richiesta di `Labels::respond('scope', …)`, e `Labels::forScope()` passa i
modelli per `Labels::modelsInScope()` (che usa `ScopeMatcher`, abbonamenti
esclusi) e stampa le varianti attive, al massimo `Labels::MAX_LABELS`. Le
scelte di stampa sono `LabelDialog::printedField()` (i campi) e
`LabelDialog::startField()` (la casella da cui partire, solo sul foglio), le
stesse della finestra del carico (`LabelDialog::fields()`). Nella pagina il
selettore e i campi stanno a sinistra (`LabelsResource::formFields()`), copie e
casella a destra (`LabelsResource::printFields()`).

## Regole

- Le estensioni girano **nell'ordine in cui sono dichiarate**.
- Una classe che non esiste, o che non estende la base, viene saltata: la
  configurazione di un sito non deve poter rompere il gestionale.
- Un hook che solleva un'eccezione finisce nel log e **non ferma** il flusso:
  gli hook `on…` arrivano a operazione già fatta, tirarla indietro sarebbe
  peggio.
- Gli hook `before…` che arriveranno potranno fermare l'operazione sollevando un
  `UserError`.
- `Extensions::run()` avvisa e basta; `Extensions::filter()` fa passare un
  valore da un'estensione all'altra.

## Segnali delle anagrafiche

Le liste di aziende, persone e fornitori hanno nella prima colonna un pallino
giallo quando alla riga manca qualcosa: il motivo è nel tooltip, il filtro
«Da completare» tiene solo quelle righe e la scheda lo ripete in alto. Le
regole sono in `PartySignals` e girano sul meccanismo dei segnali di riga del
core (`RowSignal` / `RowSignals`, vedi la pagina *Segnali di riga* della
documentazione dell'app).

| Lista | Chiave | Segnali di default |
|---|---|---|
| Aziende (clienti) | `companies` | `missing_brand` (solo clienti, con *Brand*), `missing_contact`, `missing_referent` (con i referenti), `missing_billing` |
| Persone | `contacts` | `missing_name`, `missing_surname`, `missing_email` |
| Fornitori | `suppliers` | `missing_contact`, `missing_billing` |

- `missing_contact`: nessuna email, nessun telefono e nessun recapito
  dell'azienda pieno.
- `missing_billing`: manca ragione sociale, via, città o paese; in Italia anche
  partita IVA o codice fiscale, e codice SDI o PEC.
- `missing_email`: vale l'email di accesso o un recapito personale di tipo email;
  l'email del legame con l'azienda non conta.

Il sito li cambia da codice, prima che la lista venga disegnata (per esempio in
`custom/function/function.php`):

```php
use Wonder\Backend\Table\RowSignal;
use Wonder\Backend\Table\RowSignals;

RowSignals::add('companies', RowSignal::warning(
    'missing_website',
    "COALESCE(TRIM({table}.website), '') = ''",
    __t('site.signals.missing_website')
));
RowSignals::remove('contacts', 'missing_surname');
```

La condizione è SQL sulla tabella della lista (`{table}`), senza alias `AS`:
diventa il pallino, il filtro e l'avviso della scheda insieme.

## Riquadri dei moduli nelle Impostazioni

Un modulo aggiunge un riquadro alle Impostazioni di Set Up senza toccare il
gestionale: il suo entrypoint (quello del `module.json`) implementa
`ProvidesSettings` e dà una o più `SettingsSection`.

```php
final class Ecommerce implements ModuleInterface, ProvidesSettings
{
    public static function settingsSections(): iterable
    {
        yield new OnlineShopSettings();
    }
}
```

Una `SettingsSection` dice colonne (`columns()`) e dati (`data()`) che entrano
nella riga di `gst_settings`, campi (`fields()`) ed etichette (`labels()`) della
pagina, il riquadro (`card($input)`, dove `$input('chiave')` dà il campo) e la
pulizia dei valori prima del salvataggio (`mutate()`, che li riceve tutti).

- Il riquadro sta fra «Vendite» ed «Email», nell'ordine dei moduli accesi.
- Le colonne viaggiano col deploy come le altre e in produzione si leggono e
  basta, come il resto della pagina.
- Spento il modulo, `forge update` toglie le sue colonne insieme ai valori.
- Un modulo che si rompe dando i suoi riquadri ferma la pagina e `forge update`:
  tacerlo vorrebbe dire togliere le sue colonne. Senza registro dei moduli
  (test, comandi fuori dal sito) i riquadri non ci sono.
- Nei test `SettingsSections::use([...])` forza i riquadri; `use(null)` torna a
  leggerli dai moduli.

