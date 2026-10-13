# G3 piano 4 — Più sedi e trasferimenti

> Piano breve: spec approvata (`docs/superpowers/specs/2026-10-12-magazzino-avanzato-design.md`, §5, decisioni G3.16–G3.18, G3.22). Si appoggia sui piani 1–3: secchi, `StockPicking`, `StockBuckets`, `reference_item_id`, scelta a mano di lotto e fornitore, editor dei documenti. **Nessuna tabella né colonna nuova**: `to_location_id`, lo stato `in_transit` e il tipo `transfer` ci sono dal piano 1.

**Ramo:** `g3-piano-4`, da `g3-piano-3b`.

**Vincolo (D63):** `multi_location` è già costruita, quindi tutto quello che si vede sta dietro a lei. Spenta, niente cambia: niente pagina *Trasferimenti*, niente colonna *In viaggio*, niente guardia.

## Prima: un difetto dei piani 2–3

Il commit `f4e99df` (piano 2) aveva tagliato il fondo di `StockDocuments.php`: sette metodi privati spariti (`draft`, `locked`, `location`, `isStockLocation`, `assertProducts`, `log`, `decimal`). Ripristinati su `g3-piano-2` e portati con un merge su 3a e 3b; `SelfCallsTest` controlla d'ora in poi che ogni `self::metodo()` di `src` abbia il suo metodo.

## Task

### 1. Regole e servizio
- `StockDocumentRules`: `TYPES` con `transfer`; etichetta *Trasferimento*, *Annullamento del trasferimento*; sequenza `stock_transfer`. `movement('transfer', …)` = `transfer_out` negativo. `transferLocations(int $from, int $to)` (pura): diverse, altrimenti `stock_document.transfer_same_location`.
- `StockDocuments`:
  - `create()`/`saveDraft()`: `to_location_id` dalla testata, sede con *Giacenza* (`stock_document.location_unknown`) e diversa dalla partenza. Righe come lo scarico: `batch_id`/`supplier_id` scelti si salvano anche per `transfer`.
  - `confirm()` del trasferimento = **Invia**: `StockOut::take()` sulla partenza con `no_backorder` (G3.16: pezzi insufficienti = rifiuto anche con la vendita senza giacenza), lotto e fornitore scelti come nello scarico; stato `in_transit`, numero e data.
  - `receive(int $id, array $options)`: solo da `in_transit`; per ogni `transfer_out` del documento un `transfer_in` nella sede d'arrivo, stesso secchio, pezzi e costo; lock dei secchi d'arrivo prima; stato `completed`, log.
  - `cancel()`: anche da `in_transit`. L'ordine di sempre (versione, poi dall'ultimo movimento) storna prima i `transfer_in`; lo storno di un trasferimento non va mai sotto zero (`no_backorder`), e il rifiuto all'arrivo diventa `stock_document.transfer_moved`.
  - `duplicate()`: tiene anche `to_location_id`.
- `Stock::apply()`: chiave `no_backorder` (la vendita senza giacenza non apre il sotto zero). Una riga.
- `StockMovements`/`Allocation` non cambiano.

### 2. Pagine
- `StockTransferResource` (*Magazzino → Trasferimenti*, ordine 42, `$feature = 'multi_location'`), su `StockDocumentResource`: elenco con **Da** · **A**, stato *Bozza*, *In viaggio*, *Ricevuto*, *Annullato*; filtro *Stato* con *In viaggio*.
- Bozza: **Da** e **A** in testata (le sedi con *Giacenza*); editor come lo scarico (tendine *Lotto* e *Fornitore* con le loro funzionalità; giacenza della sede di partenza). Bottoni *Salva bozza* e **Invia**, con la domanda «{pezzi} pezzi in {righe} righe partono per {arrivo}».
- Scheda: *Da → A*, stato; bottoni **Ricevi** (da *in viaggio*), **Annulla** (da *in viaggio* e *ricevuto*), **Duplica**. `StockDocumentActionResource` con l'azione `ricevi`.
- *Movimenti*: *Visualizza trasferimento*, voce *Trasferimento* nel filtro *Tipo*.
- *Giacenze*: colonna **In viaggio** (con `multi_location`): per versione i pezzi dei `transfer_out` dei trasferimenti `in_transit`, `LevelsSql::inTransit()`. Non è disponibile, non è valore, non entra nel *Totale*.

### 3. Guardia del pannello (G3.18)
- `FeatureGuards::refusal(string $key, array $state): ?string`: con `multi_location` che si spegnerebbe (anche a catena), rifiuta se ci sono pezzi diversi da zero in una sede che non è la principale o trasferimenti in viaggio, e dice quali (le prime tre sedi, il numero dei trasferimenti). `FeaturePanel::save()` la chiama sullo stato calcolato dalle regole, prima di scrivere; `FeatureResource` mostra il rifiuto come avviso.
- `purchasing` e `batch_tracking` non hanno guardie.

### 4. Chiusura
- Dati di prova (con `multi_location`): una seconda sede di prova con *Giacenza* e un trasferimento in viaggio dalla principale; la pulizia annulla e toglie anche la sede se non ha più movimenti.
- Guide: `magazzino-trasferimenti.md` (in `SUMMARY.md`), ritocchi a *Giacenze* (In viaggio), *Movimenti*, *Sedi* (la vendita esce dalla principale, il punto di ritiro, lo spegnimento), *Funzionalità*; guida sviluppatori; CHANGELOG; TODO.

## Test
- Puri: `StockDocumentRulesTest` (trasferimento: movimento, etichette, sedi uguali), `FeatureGuardsTest` (messaggi dalla lettura già fatta).
- Integrazione: invio con due secchi e scelta del lotto; pezzi insufficienti anche con la vendita senza giacenza; ricevi; annulla da *in viaggio* e da *ricevuto*; annulla rifiutato con la merce già venduta all'arrivo; colonna *In viaggio* contro i movimenti; la guardia del pannello (rifiuta con pezzi in una seconda sede e con un trasferimento in viaggio, lascia spegnere senza).
- Inerzia: con `multi_location` spenta nessuna pagina nuova, nessuna colonna, il pannello come prima.

## Review Focus
1. Lock: l'invio blocca i secchi della partenza, la ricezione quelli dell'arrivo, sempre in ordine di versione.
2. Annullo da *ricevuto*: prima gli storni all'arrivo (che possono fallire), poi quelli alla partenza, in una transazione.
3. La guardia vede anche lo spegnimento a catena (un blocco che toglie una dipendenza di `multi_location`).

## Note per l'unione
- `ritocchi-carichi-scarichi`: `restorable()` deve tenere anche `to_location_id` della testata (oltre a `cost` e alle chiavi del lotto), o una bozza di trasferimento rifiutata all'invio si riapre senza l'arrivo.
- `StockDocumentsDemoTest`: un documento di prova in più con *Più sedi* accesa.
- *In viaggio* si vede da due sedi in su, non solo con la funzionalità: con una sede non si trasferisce.
