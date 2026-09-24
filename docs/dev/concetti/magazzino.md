# Magazzino

## Una sola porta

`Support\Stock\Stock::apply()` è **l'unico codice che scrive** su `gst_stock` e
`gst_stock_movements`. Nessuna Resource, nessun comando e nessun sotto-progetto
scrive direttamente: chi deve muovere merce chiama `apply()`.

```php
$esito = Stock::apply([
    'product_id' => 42,
    'quantity' => -3,          // con il segno: negativo esce
    'reason' => 'damaged',     // una chiave di Reasons::all()
    'note' => 'Caduta dallo scaffale',
]);
// ['movement_id' => 1234, 'before' => 10.0, 'after' => 7.0, 'alert' => 'open']
```

Cosa fa, in ordine, dentro `Transaction::run()`:

1. legge la riga di `gst_stock` con `FOR UPDATE`;
2. calcola `quantity_before` e `quantity_after`;
3. scrive il movimento;
4. aggiorna la giacenza;
5. rinfresca l'avviso di scorta (`Alerts::refresh()`).

Il `FOR UPDATE` è il motivo per cui due ordini contemporanei non possono
prendere lo stesso ultimo pezzo. Le transazioni si annidano: chiamare `apply()`
dentro una transazione propria (l'elenco delle giacenze che salva venti righe)
apre un savepoint, non una seconda transazione.

## Le due pagine

| Pagina | Classe | Cosa fa |
|---|---|---|
| Giacenze | `StockLevelResource` | pagina-form: una casella per riga, salvataggio in blocco dentro una transazione |
| Rettifica | `StockAdjustmentResource` | pagina-form su `?versione=`, con causale e nota |

Nessuna delle due scrive sul database: compongono un movimento e chiamano
`Stock::apply()`. La differenza fra quello che c'era e quello che è stato
scritto la calcola `Stocktake`, che è pura — una casella vuota non è uno zero,
uno zero scritto sì.

Tre cose da sapere se le si tocca:

1. **La rotta del salvataggio non ha query string.** Filtri, pagina e versione
   viaggiano in campi nascosti (`back`, `product_id`); chi li rileggesse da
   `$_GET` salverebbe la prima pagina invece di quella aperta.
2. **Il controller delle pagine-form non intercetta niente.** Un `UserError`
   che vola via da `submitFormPage()` diventa una pagina 500: i rifiuti si
   catturano lì dentro e diventano un `FlashAlert` più un redirect.
3. **Gli indirizzi del ritorno si controllano.** `backUrlFrom()` accetta solo
   percorsi o indirizzi assoluti di questo sito: un `torna=` che arriva dalla
   query string è testo di chiunque.

## I parametri

| Chiave | Obbligatoria | Note |
|---|---|---|
| `product_id` | sì | una riga di `gst_products` |
| `quantity` | sì | con il segno, diversa da zero |
| `type` | no | `adjustment` se non la passi; l'enum completo è in `StockMovement::TYPES` |
| `reason` | no | una chiave di `Reasons::all()`; una sconosciuta è un rifiuto |
| `location_id` | no | la sede principale se non la passi (`Locations::mainId()`) |
| `batch_id`, `supplier_id` | no | zero fino a G3 |
| `reference_type`, `reference_id` | no | il documento che ha causato il movimento |
| `source` | no | `backend` se non lo passi; `online`, `import` |
| `user_id`, `note`, `unit_cost` | no | |

## I rifiuti

`apply()` rifiuta con `UserError` — che il backend trasforma in messaggio sul
form, non in una pagina 500:

| Chiave | Quando |
|---|---|
| `stock.zero_quantity` | quantità zero |
| `stock.unknown_reason` | causale che non esiste |
| `stock.product_missing` | prodotto cancellato o inesistente |
| `stock.no_location` | nessuna sede con magazzino |
| `stock.insufficient` | la giacenza andrebbe sotto zero e `backorders` è bloccata |

## Le classi

| Classe | Pura | Cosa fa |
|---|---|---|
| `Reasons` | sì | le causali e le loro etichette |
| `Availability` | sì | disponibile = giacenza − prenotazioni attive |
| `Adjustment` | sì | dalla quantità scritta al movimento (`fromTarget`, `fromDelta`) |
| `LowStock` | sì | `open`, `close` o `none` per l'avviso di scorta |
| `Locations` | no | la sede principale, con cache per richiesta |
| `Levels` | no | giacenza, prenotato e disponibile di uno o più prodotti |
| `Alerts` | no | scrive gli avvisi decisi da `LowStock` |
| `Stock` | no | la porta di scrittura |
| `ProductNames` | in parte | articolo e opzione come li legge la griglia: `of()` è pura, `models()` legge i nomi |
| `LowStockReport` | in parte | gli avvisi aperti o da mandare, fatti righe: `build()` è pura e scarta chi è tornato sopra |
| `LowStockEmail` | no | oggetto e corpo dell'email, con la view sovrascrivibile |
| `LowStockNotifier` | no | un giro dell'attività: legge, chiude i vecchi, manda, segna |
| `NegativeStock` | in parte | le giacenze sotto zero per prodotto: `group()` è pura |

Le pure si provano con gli array e senza database: è lì che deve stare la
logica che G3 e G4 rileggeranno.

## Le prenotazioni

`gst_stock_reservations` esiste ma **nasce vuota**: la riempirà il checkout in
G4. `Availability` la legge già, così "disponibile" vuol dire la stessa cosa in
backend e in vetrina fin da ora.

## Chi ha una storia non si elimina

Giacenze e movimenti puntano al prodotto con una chiave esterna
`ON DELETE RESTRICT`: un articolo che si è mosso non si cancella, e senza un
controllo il database risponderebbe con una pagina di errore.
`ProductModelResource::assertDeletable()` lo chiede prima a
`StockHistory::hasMovements()` e rifiuta con un messaggio: chi non vende più un
articolo lo mette su "Nascosto".

Il rifiuto usa `UserError::refusal()`, non `UserError::make()`, perché il core
ha **due porte con due gusti diversi**: il controller del form intercetta
`InvalidArgumentException`, mentre `api/backend/delete` intercetta
`RuntimeException` e risponde 422. Il testo resta uno solo, nei file di lingua.

`StockHistory::purge()` cancella davvero la storia di certi prodotti: la usa
**solo** la pulizia dei dati di prova (`gestionale:demo --fresh`), che
cancella anche i prodotti.

## Gli avvisi di scorta minima

Funzionalità `low_stock_alerts`. Bloccata, non si vede niente — né la
*Scorta minima*, né il filtro *Solo sotto scorta*, né il riquadro *Sotto
scorta*, né i *Destinatari degli avvisi* — e l'attività gira senza mandare.

| Chi | Cosa |
|---|---|
| `Stock::apply()` | apre e chiude la riga di `gst_stock_alerts` a ogni movimento |
| le due schede | salvando la soglia chiamano `Alerts::refresh()`: l'avviso si apre o si chiude subito |
| attività `gestionale.stock_alerts` | ogni quarto d'ora, **nata spenta**: una email sola con i prodotti ancora sotto soglia e un avviso mai mandato |
| `php forge gestionale:stock-alerts` | l'anteprima: cosa partirebbe e a chi; non manda e non scrive |
| riquadro *Sotto scorta* | gli stessi prodotti dell'email, letti adesso |

L'attività si accende in **Dev → Pianificazioni** quando si sblocca la
funzionalità.

**Il salvataggio non manda email** (G2b.9): dieci rettifiche di fila non fanno
dieci email, e salvare non dipende dal server di posta. Varrà anche quando a
scaricare sarà un ordine online.

**Il comando è solo un'anteprima.** `forge` carica l'autoload ma non le
funzioni globali del core: `sendMail()` lì non esiste. L'attività invece gira
dentro `bin/scheduler.php`, che avvia il sito.

**Due pulizie a ogni giro**, che nessun altro farebbe:

1. gli avvisi di prodotti eliminati o tolti dalla griglia si chiudono;
2. gli avvisi aperti di prodotti tornati sopra la soglia senza un movimento (la
   soglia cambiata dal database, domani una prenotazione scaduta) passano da
   `Alerts::refresh()`. Senza, alla prossima discesa `Stock::apply()`
   troverebbe l'avviso ancora aperto e non ne aprirebbe uno nuovo.

L'anteprima non fa nemmeno queste: dice cosa succederebbe.

**Eliminare un prodotto con l'avviso aperto.** Anche `gst_stock_alerts` punta al
prodotto con una chiave esterna: `StockHistory::dropAlerts()` toglie gli avvisi
prima dell'eliminazione, dalla scheda dell'articolo e da quella della versione.

**Quando la posta non va.**

| Esito | Cosa succede agli avvisi |
|---|---|
| mandata a tutti | `notified_at`: non tornano più |
| fermata dall'hook | `notified_at`: è una scelta del sito, non un guasto |
| mandata solo ad alcuni | `notified_at`, e si segnala chi non l'ha ricevuta |
| non partita per nessuno | restano da mandare: si riprova al giro dopo |

Le segnalazioni vanno in `error_reports` con un **messaggio fisso**, così le
ripetizioni si contano sulla stessa riga. Attenzione: il registro del core, a
ogni ripetizione, scrive di nuovo a chi sviluppa. Se il server di posta è giù
non parte neanche quella; se invece rifiuta sempre lo stesso indirizzo, arriva
un'email tecnica ogni quarto d'ora finché qualcuno non corregge i destinatari.

**L'email.** L'oggetto è "N prodotti sotto scorta", il corpo viene da
`view/emails/low-stock.php`. Per cambiarlo si copia il file in
`custom/modules/gestionale/view/emails/low-stock.php`: la view riceve
`$items`, `$count`, `$url`, `$e()` per il testo e `$qty()` per i pezzi.

```php
LowStockNotifier::run(true);   // l'anteprima, come il comando
LowStockNotifier::run();       // un giro vero, come l'attività
```

Si manda con `Support\Mail\Mailer::send($key, $to, $subject, $body)`: applica
l'hook `beforeEmailSend` con la chiave `stock.low_stock` (vedi
[Hook del sito](hook.md)), manda un'email per indirizzo e torna `sent`,
`cancelled` o `failed`. Le risposte vanno a `Mailer::replyTo()`: l'email del
negozio o, se manca, il mittente del sito. `Support\Mail\Recipients::parse()`
legge gli indirizzi dell'impostazione: validi, non validi, senza doppioni.

## Tre trappole del framework

**I decimali delle colonne.** `Field::key('quantity')->number()->decimals(3)`
non dice niente al database: `Data\Fields\Number::sqlSchema()` del core torna
sempre `DECIMAL(10,2)`, e `decimals()` vale solo per come il form scrive il
numero. Una colonna che ha bisogno di più decimali si dichiara a mano con
`Support\Columns::decimal('quantity', '10,3')`.

**Le date vuote.** `resolved_at IS NULL` è l'unico modo di cercare un avviso
ancora aperto: confrontare un `DATETIME` con la stringa vuota fa fallire la
query in MySQL strict mode ("Incorrect DATETIME value"). Lo stesso vale per
ogni altra colonna data del modulo.

**`sendMail()` "sgrassa" il corpo.** Il core passa il corpo da
`sanitizeEcho()`, che toglie le barre rovesciate e decodifica le entità: un
`&lt;b&gt;` scritto con cura nel nome di un prodotto torna `<b>` e diventa
grassetto vero, e `C:\cartella` perde la barra. `Mailer::shield()` prepara il
corpo perché dopo quel passaggio resti esattamente quello scritto. Chi manda
un'email dal modulo passa da `Mailer`, mai da `sendMail()` diretto.
