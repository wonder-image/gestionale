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

## Due trappole del framework

**I decimali delle colonne.** `Field::key('quantity')->number()->decimals(3)`
non dice niente al database: `Data\Fields\Number::sqlSchema()` del core torna
sempre `DECIMAL(10,2)`, e `decimals()` vale solo per come il form scrive il
numero. Una colonna che ha bisogno di più decimali si dichiara a mano con
`Support\Columns::decimal('quantity', '10,3')`.

**Le date vuote.** `resolved_at IS NULL` è l'unico modo di cercare un avviso
ancora aperto: confrontare un `DATETIME` con la stringa vuota fa fallire la
query in MySQL strict mode ("Incorrect DATETIME value"). Lo stesso vale per
ogni altra colonna data del modulo.
