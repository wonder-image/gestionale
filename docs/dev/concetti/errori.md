---
icon: triangle-exclamation
---

# Errori e log

Tre tipi di errore, tre strade diverse. Sbagliare strada è il modo più veloce
per riempire il log di cose che non servono e perdere quelle che servono.

| Tipo | Come si solleva | Dove finisce |
|---|---|---|
| Dell'utente | `UserError::make('stock.insufficient', ['product' => $nome])` | messaggio tradotto, nessun log |
| Di un servizio esterno | `ProviderError::make($provider, $azione, $messaggio, $contesto)` | log del provider + `error_reports` |
| Interno | qualunque `Throwable` non gestito | `gestionale.log` con il contesto |

## Errore dell'utente

Non è un guasto, è una risposta: "non c'è abbastanza merce", "questo ordine è
già chiuso". Il testo sta in `lang/it/gestionale.json` sotto `errors`, così si
riscrive senza toccare il codice.

```php
throw UserError::make('stock.insufficient', ['product' => $prodotto['name']]);
```

### `make()` o `refusal()`

Il core ha due porte, e ognuna intercetta un tipo diverso:

| Porta | Intercetta | Si usa |
|---|---|---|
| il salvataggio del form di una Resource (`mutateRequestValues()`) | `InvalidArgumentException` → avviso rosso sul form | `UserError::make()` |
| l'eliminazione dall'elenco (`api/backend/delete`) | `RuntimeException` → risposta 422 con il messaggio | `UserError::refusal()` |

Un `UserError` arrivato alla porta sbagliata non viene visto e diventa una
pagina 500. Per questo i rifiuti di `assertDeletable()` — un articolo con
movimenti, una scheda con un account sul sito, un fornitore con dei costi —
usano `refusal()`: lo stesso metodo lo chiama sia l'elenco sia l'eliminazione
dalla scheda. Il testo è uno solo, nei file di lingua, per tutti e due.

La rettifica, l'unica pagina-form del magazzino, non ha nessuna delle due porte: i rifiuti si
catturano dentro `submitFormPage()` (vedi
[Magazzino](magazzino.md#le-pagine)).

### Ogni chiave ha la sua frase

Una chiave senza frase non esplode: arriva a schermo così com'è,
«product.supplier_missing». `tests/ErrorKeysTest.php` cerca in `src/` ogni
chiave scritta per intero in `UserError::make()` o `UserError::refusal()`, anche
quando va a capo dopo la parentesi, e guarda che `lang/it/gestionale.json` abbia
la sua frase. Le chiavi composte a runtime non le vede: quelle le coprono i test
di chi le usa.

```bash
php tests/ErrorKeysTest.php
```

### Le chiavi dei fornitori e della vendita senza giacenza

| Chiave | Metodo | Quando |
|---|---|---|
| `product.backorder_lead_days_invalid` | `make()` | «Giorni di attesa» fuori da 0-365, o non un numero intero; vedi [Catalogo](catalogo.md#la-vendita-senza-giacenza) |
| `product.supplier_missing` | `make()` | una riga della finestra «Fornitori» con codice o costo ma senza fornitore |
| `product.supplier_invalid` | `make()` | un fornitore che la pagina non propone: eliminato, non più fornitore, o disattivato e mai legato a quell'opzione; oppure «Codice fornitore» e «Costo d'acquisto» compilati quando il fornitore unico non c'è più |
| `product.supplier_sku_too_long` | `make()` | un codice del fornitore oltre cento caratteri; `{{max}}` è il limite |
| `product.supplier_cost_invalid` | `make()` | un costo d'acquisto scritto che non è un numero |
| `product.supplier_cost_negative` | `make()` | un costo d'acquisto sotto zero |
| `product.supplier_cost_too_high` | `make()` | un costo d'acquisto oltre 99.999.999,9999, quello che tiene la colonna |
| `product.supplier_duplicate` | `make()` | lo stesso fornitore due volte sulla stessa opzione; `{{supplier}}` è il nome |
| `contact.supplier_in_use` | `refusal()` | eliminare un fornitore scritto su opzioni in vendita; `{{count}}` è quante |
| `contact.supplier_role_in_use` | `make()` | togliere il ruolo di fornitore a una scheda con dei costi; `{{count}}` come sopra |
| `stock.location_duplicate` | `make()` | la stessa sede due volte nelle righe «Giacenza per sede»; `{{location}}` è il suo nome |
| `stock.location_unknown` | `make()` | una riga per sede che punta a una sede che il magazzino non mostra più |
| `product.min_stock_invalid` | `make()` | una scorta minima che non è un numero da zero in su (vuota vale zero) |

Le sette `product.supplier_…` le solleva `ProductSuppliers::assertValid()`,
per i fornitori scritti nella scheda dell'articolo e in quella dell'opzione
(`product.supplier_invalid` anche `postedSuppliers()`); le due
`contact.…` le solleva `CustomerResource` (vedi [Fornitori e costi
d'acquisto](acquisti.md#un-fornitore-in-uso-non-si-toglie)). Le due
`stock.location_…` le solleva `LocationRows::normalize()`, per le righe
«Giacenza per sede» della scheda dell'articolo, della finestra «Giacenza»
della griglia e della scheda dell'opzione (vedi
[Catalogo](catalogo.md#la-giacenza-si-scrive-dalla-scheda)); la
`product.min_stock_invalid` la sollevano `LocationRows::normalize()` per quelle
righe e `ProductModelResource::minStockValue()` per la casella «Scorta minima»
con una sede sola.

`stock.insufficient` resta com'era, ma cambia **quando** parte: una giacenza va
sotto zero solo se è attiva `backorders` **e** l'opzione ha `allow_backorder`
acceso. Se ne manca uno, il movimento che toglie e la porterebbe sotto zero è
rifiutato; un carico entra sempre, anche se la giacenza resta negativa (vedi
[Magazzino](magazzino.md#i-rifiuti)).

## Errore di un servizio esterno

```php
Errors::provider(ProviderError::make(
    'fatture-in-cloud',
    'invoice.send',
    'Timeout',
    ['invoice' => $numero]
));
```

Finisce in `storage/logs/error/fatture-in-cloud.log` e diventa una riga di
`error_reports` del core.

## Errore interno

```php
Errors::internal($throwable, 'order.confirm', ['order' => $id]);
```

Log e basta: all'utente si mostra un messaggio generico. In webhook, cron e
comandi il log non interrompe mai l'esecuzione.

## Chi riceve le email

`developer_error_emails` («Email per gli errori tecnici») in **Set Up →
Gestionale → Impostazioni**, più indirizzi separati da virgola. Il core non li
conosce: glieli passa il modulo al momento della segnalazione.

**Gli errori sono roba di chi sviluppa.** Quello che deve sapere il commerciante
— un ordine nuovo, un ordine annullato — non è un errore ma una **notifica**: ha
parole sue e i suoi destinatari, `merchant_notification_emails` («Email per gli
ordini»), nello stesso riquadro. A differenza del resto della pagina non viaggia
col deploy e si cambia anche in produzione (`editableWhenReadonly()`): ogni
ambiente ha i suoi destinatari. Entrambi i campi rifiutano un indirizzo scritto
male, nominandolo.

Il primo errore manda l'email, i successivi alzano solo il contatore. Segnando
l'errore risolto la riga si chiude; se il problema torna, la riga riapre e
l'email riparte — è così che ci si accorge che la correzione non ha tenuto.

La pagina è **Set Up → Errori** (`admin`); il riquadro "Da controllare" della
home mostra gli stessi errori a chi installa.
