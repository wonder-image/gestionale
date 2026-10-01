# Resi, guide e prova nel browser — piano di realizzazione

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** chiudere G4 — registrare un reso dalla scheda dell'ordine (righe, quantità, motivo, rientro a magazzino), chiuderlo o annullarlo, e scrivere le guide che mancano (utente e sviluppatori).

**Architecture:** una regola pura (`ReturnRules`: massimo reso, ricarico proposto, lettura della quantità) provata senza database; un servizio (`Returns`) che fa tutto il lavoro e rifiuta con `UserError`; una Resource sottile (`OrderReturnResource`) che ricalca `OrderPaymentResource` — pagina-form con `torna=`, POST con esito sulla stessa pagina — e una colonna di azioni nella tabella *Resi* già in scheda. Nessuna regola di magazzino nuova: il rientro passa da `Allocation::returnGoods()`, il log da `StatusLogger::record(SalesReturnStatusLog::class, …)`, il numero da `DocumentSequences::next('sales_return')`.

**Tech Stack:** PHP 8.2+, harness `tests/harness.php` (`php tests/run.php`), sito di prova `boilerplates/ecommerce-site` per i test d'integrazione, helper `tests/integrazione/supporto/compra.php` (`ordineDiProva`, `articoloConGiacenza`, `resoDiProva`).

**Spec:** `docs/superpowers/specs/2026-09-29-ordini-e-pagamenti-design.md` (§ Resi, § Azioni, § Documentazione, § Piani). Modelli già pronti: `SalesReturn`, `SalesReturnItem`, `SalesReturnStatusLog`.

## Global Constraints

- Lingua: commenti, testi utente e nomi dei test in **italiano** con gli accenti; classi, tabelle, colonne e chiavi in inglese.
- Form compatti: tooltip al posto dei testi d'aiuto, campi correlati vicini, niente campi automatici.
- Il rientro in magazzino passa **solo** da `Allocation::returnGoods()` (`Stock::apply()` è l'unica porta); lo stato del reso lo scrive solo `Returns`, sempre con `StatusLogger`.
- Rifiuti prevedibili: `UserError::make('return.<chiave>', [...])`, frase in `lang/it/gestionale.json` sotto `gestionale.errors.return` (il JSON si riscrive con python `json.dumps(ensure_ascii=False, indent=4) + "\n"`); `ErrorKeysTest` cade se manca.
- La funzionalità `returns` (richiede `orders`) decide se la voce, la pagina e la tabella esistono; spenta, niente di tutto questo si vede e il servizio non si raggiunge dalla pagina.
- Tutto ciò che viene dal database e finisce in HTML passa da `escape()`/`OrderSheet::esc()`; il ritorno `torna=`/`back` passa da `StockAdjustmentResource::backUrlFrom()`.
- Quantità con tre decimali (`Columns::decimal`), lette con la stessa regola di `OrderPaymentResource::amountFrom` (`2`, `2,5`, `2.5`).
- Test d'integrazione dentro `Transaction::run()` annullata (`prova()`), che non lasciano righe; ogni task chiude con `php tests/run.php` verde per intero.
- Commit con trailer `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`; PR con merge commit su `main`, poi pulizia di branch e worktree da parte mia.

## Decisioni di perimetro

- **Rimborso al cliente: fuori.** Il reso scrive righe, magazzino e log; il denaro lo restituisce il gateway con `Ledger::refund()` (G4 piano 2), non da qui.
- **Annullare un reso** è consentito solo da `received` e solo se nessuna riga è rientrata a magazzino; altrimenti si rifiuta (`return.already_restocked`) e si rimanda alla rettifica in *Magazzino*. Disfare un movimento `return` già a scaffale non è un'operazione che questa pagina deve inventarsi.
- **Stati `requested`, `approved`, `rejected`**: restano nell'enum per il reso online (E1), qui non si usano. Un reso registrato dal backend nasce `received`.
- **Righe ammesse**: solo `type = 'product'` con `product_id > 0` (i servizi e le spedizioni non rientrano).
- **Ordini ammessi**: `stage = 'order'` e stato `confirmed`, `processing` o `completed`.

## Review Focus

- **Quantità strane.** Zero, negativa, testo, `2,5`, più di quanto ordinato, più di quanto resta dopo un reso precedente: rifiutate o lette bene, mai un reso da zero pezzi né un `NaN`. *(Task 1 e 2)*
- **Due resi sulla stessa riga.** Il secondo vede solo il residuo; un reso annullato libera di nuovo la quantità. *(Task 2)*
- **Doppio invio.** Premere due volte *Registra* non fa due resi né due rientri: il secondo trova la quantità già esaurita. *(Task 2)*
- **Reso senza ricarico.** Righe `damaged`/`defective` (o con la spunta tolta) registrate, in magazzino non entra niente e nessun movimento viene scritto. *(Task 2)*
- **Ritorno aperto verso fuori.** `torna=https://altro.sito/` non porta fuori dal backend. *(Task 3)*
- **Funzionalità spenta.** Con `returns` spenta l'URL della pagina non registra niente e la scheda non mostra voce né tabella. *(Task 3)*
- **`--fresh` della demo con un reso dentro.** Il reso finto sparisce prima dell'ordine e delle righe, senza violare le chiavi esterne. *(Task 4)*

---

### Task 1: Regole pure del reso

**Files:**
- Create: `src/Support/Returns/ReturnRules.php`
- Test: `tests/ReturnRulesTest.php`

**Interfaces:**
- Produces: `ReturnRules::REASON_LABELS` (array `reason => etichetta italiana`, le sette di `SalesReturnItem::REASONS`);
  `ReturnRules::defaultRestock(string $reason): bool` (falso per `damaged` e `defective`, vero altrimenti, vero anche per motivo ignoto);
  `ReturnRules::returnable(float $ordered, float $alreadyReturned): float` (mai sotto zero, arrotondato a tre decimali);
  `ReturnRules::quantityFrom(mixed $value): ?float` (`null` se non è un numero maggiore di zero; accetta `2`, `2,5`, `2.5`, `" 3 "`);
  `ReturnRules::eligibleOrder(array $order, array $features = []): bool` (`returns` accesa, `stage = order`, stato `confirmed|processing|completed`).

- [ ] **Step 1: scrivere `tests/ReturnRulesTest.php`** con `check()` per: ricarico spento per `damaged`/`defective` e acceso per `changed_mind`, `wrong_size`, `other` e per un motivo inventato; `returnable(3, 1) = 2`, `returnable(3, 3) = 0`, `returnable(3, 5) = 0` (mai negativo), `returnable(2.5, 0.5) = 2.0`; `quantityFrom` per `'2'`, `'2,5'`, `'2.5'`, `' 3 '` (valide) e `'0'`, `'-1'`, `'abc'`, `''`, `null` (nulle); `eligibleOrder` per i tre stati ammessi, per `cancelled`, `pending`, `draft`, per un `stage = cart`, e per `returns` spenta; ogni motivo di `SalesReturnItem::REASONS` ha un'etichetta.
- [ ] **Step 2: eseguire** `php tests/ReturnRulesTest.php` — atteso: errore «class not found».
- [ ] **Step 3: scrivere `ReturnRules`** (classe `final`, solo metodi statici, niente database).
- [ ] **Step 4: eseguire** il test — atteso: tutto verde; poi `php tests/run.php` verde.
- [ ] **Step 5: commit** «Regole pure del reso: massimo, ricarico proposto, quantità».

### Task 2: Il servizio `Returns`

**Files:**
- Create: `src/Support/Returns/Returns.php`, `tests/integrazione/ReturnsTest.php`
- Modify: `lang/it/gestionale.json` (gruppo `return`)

**Interfaces:**
- Consumes: `ReturnRules` (Task 1); `Allocation::returnGoods(array $line)` con `product_id`, `quantity`, `location_id`, `sales_return_id`, `restock`, `source`, `user_id`, `note`; `DocumentSequences::next('sales_return')`; `StatusLogger::record(SalesReturnStatusLog::class, $id, 'status', $da, $a, $source, $userId, $messaggio)`; `Code::make(SalesReturn::class, Codes::SALES_RETURN)`.
- Produces:
  `Returns::returned(int $orderItemId): float` — somma delle `SalesReturnItem.quantity` dei resi non `cancelled` e non `rejected`;
  `Returns::lines(int $orderId): list<array{order_item_id:int, name:string, ordered:float, returned:float, max:float}>` — le righe prodotto dell'ordine con quanto si può ancora rendere (per la pagina);
  `Returns::register(int $orderId, array $lines, array $options = []): array{return_id:int, number:string, restocked:int, kept:int}` — `$lines` = `[['order_item_id'=>int,'quantity'=>mixed,'reason'=>string,'restock'=>?bool,'note'=>string], …]`; `$options` = `location_id`, `customer_note`, `internal_note`, `source`, `user_id`;
  `Returns::complete(int $returnId, array $options = []): void`;
  `Returns::cancel(int $returnId, array $options = []): void`.
  Chiavi d'errore: `return.order_not_returnable`, `return.nothing_selected`, `return.line_not_returnable`, `return.over_quantity` (`{{product}}`, `{{max}}`), `return.unknown_reason`, `return.not_found`, `return.cannot_complete`, `return.cannot_cancel`, `return.already_restocked`.

- [ ] **Step 1: scrivere le chiavi** in `gestionale.json` (frasi in italiano a chi usa il backend, es. `over_quantity`: «Di {{product}} se ne possono rendere al massimo {{max}}: il resto è già stato reso.»); eseguire `php tests/ErrorKeysTest.php` — atteso: rosso finché il servizio non usa tutte le chiavi.
- [ ] **Step 2: scrivere `tests/integrazione/ReturnsTest.php`** (copiare l'intestazione e `prova()` da `CustomerSheetBackendTest.php`; `returns` accesa in transazione con `accendiFunzionalita(['orders','returns'])`), con questi `check()`:
  1. reso con ricarico: ordine di prova confermato con 3 pezzi, reso di 2 con `changed_mind` → riga `SalesReturnItem` scritta, giacenza della sede +2, un movimento `return` con `reference_type = sales_return` e il numero del reso, reso `received` con `received_at`, numero del formato della sequenza, una riga nel log di stato;
  2. reso senza ricarico: `damaged` → riga scritta, giacenza invariata, nessun movimento `return`, `kept = 1`;
  3. spunta tolta esplicitamente (`restock=false`) su un motivo che di norma ricarica → come sopra;
  4. quantità oltre l'ordinato → `UserError` `return.over_quantity`, niente scritto (nessun reso, nessun movimento);
  5. due resi: 2 + 1 su 3 pezzi passano, un terzo da 1 viene rifiutato; annullato il primo (`cancel`), il terzo ora passa;
  6. quantità `0`, `-1`, `abc`, `2,5` → le prime tre rifiutate (`nothing_selected` o `over_quantity` a seconda del caso), `2,5` letta come 2,5 su una riga da 3;
  7. riga che non è `product` (servizio) o di un altro ordine → `return.line_not_returnable`;
  8. nessuna riga selezionata → `return.nothing_selected`;
  9. ordine `cancelled`, `pending`, carrello, `returns` spenta → `return.order_not_returnable`;
  10. motivo sconosciuto → `return.unknown_reason`;
  11. `complete`: da `received` a `completed` con `completed_at` e riga di log; da `completed` o `cancelled` → `return.cannot_complete`;
  12. `cancel` da `received` senza righe rientrate → `cancelled`; con una riga rientrata → `return.already_restocked`; da `completed` → `return.cannot_cancel`;
  13. un errore a metà (riga valida seguita da una oltre il massimo) non lascia niente: né il reso né i movimenti della prima riga (tutto dentro `Transaction::run`);
  14. `lines()` riporta ordinato, già reso e massimo di ogni riga, e un reso annullato non conta.
- [ ] **Step 3: eseguire** `php tests/integrazione/ReturnsTest.php` — atteso: errore «class not found».
- [ ] **Step 4: scrivere `Returns`.** Una sola `Transaction::run` per `register`: legge l'ordine (`stage`, stato, `ReturnRules::eligibleOrder`), blocca le righe dell'ordine con la lettura `FOR UPDATE` già usata dai servizi gemelli — così il doppio invio vede la quantità già resa — controlla ogni riga con `ReturnRules::returnable`, crea la testata (`received`, numero da `DocumentSequences`, `location_id` dalle opzioni o `Locations::mainId()`, `customer_id` e `channel = office`), le righe e, per quelle col ricarico acceso, chiama `Allocation::returnGoods()`; scrive il log con `StatusLogger`. `complete` e `cancel` controllano lo stato e scrivono data e log.
- [ ] **Step 5: eseguire** il test e `php tests/ErrorKeysTest.php` — atteso: verdi; poi `php tests/run.php` verde.
- [ ] **Step 6: commit** «Returns: registra, chiude e annulla i resi con il rientro a magazzino».

### Task 3: Pagina *Registra reso*, voce nel menu, azioni nella tabella

**Files:**
- Create: `src/Resources/Sales/OrderReturnResource.php`, `tests/OrderReturnResourceTest.php`, `tests/integrazione/ReturnBackendTest.php`
- Modify: `src/Support/Orders/OrderActions.php` (`canRegisterReturn`), `src/Resources/Sales/OrderResource.php` (`actionsFor`, voce *Registra reso*), `src/Resources/Sales/OrderReturnTableResource.php` (colonne *Righe* e *Azioni*), `tests/OrderActionsTest.php`

**Interfaces:**
- Consumes: `Returns::lines/register/complete/cancel`, `ReturnRules::*` (Task 1-2); il modello di `OrderPaymentResource` (`urlFor`, `submitUrl`, `submitFormPage`, `run`, `isFormPage`, `NavigationOnlyResource`).
- Produces:
  `OrderActions::canRegisterReturn(array $order, array $features): bool` (= `ReturnRules::eligibleOrder`);
  `OrderReturnResource::urlFor(int $orderId, string $back = ''): string`;
  `OrderReturnResource::run(int $orderId, array $values, int $userId): array{ok:bool, message:string}` — `$values['lines']` è `[order_item_id => ['quantity'=>…, 'reason'=>…, 'restock'=>'on'|'']]`, più `location_id` e `internal_note`; legge le quantità vuote come «non reso» e le scarta;
  `OrderReturnResource::runStatus(string $action, int $returnId, int $userId): array{ok:bool, message:string}` con `$action` = `complete` o `cancel`.

La pagina è fatta a mano come la finestra di `OrderPaymentResource::modal()` (`data-wi-check` sui campi obbligatori, vedi memoria *Form a mano nel backend del core*): una tabella con una riga per prodotto — nome, ordinato, già reso, **Quantità**, **Motivo** (select dalle etichette di `ReturnRules`), **Rientra a magazzino** (spunta, accesa o spenta secondo `defaultRestock` del motivo, e riallineata dal cambio del motivo con un piccolo script) — più *Sede* (select, solo se le sedi attive sono più d'una; altrimenti nascosto sulla principale) e *Nota interna*. Il tooltip del titolo spiega il ricarico; le righe senza niente da rendere (massimo 0) sono grigie e senza campi.

- [ ] **Step 1: test unitari** (`OrderActionsTest`, `OrderReturnResourceTest`): `canRegisterReturn` per i tre stati ammessi e per `cancelled`/`pending`, con `returns` spenta; `urlFor` porta `?ordine=` e `torna=` codificato; la pagina (HTML) elenca le sole righe prodotto con il loro massimo, mette la spunta spenta per `damaged`, non stampa HTML preso dal nome del prodotto senza `escape` (nome `<b>x</b>`), nasconde la *Sede* con una sede sola; `Resi` ha `$feature = 'returns'`; la colonna *Azioni* della tabella offre *Chiudi* e *Annulla* solo per un reso `received`, niente per `completed` e `cancelled`.
- [ ] **Step 2: test d'integrazione** (`ReturnBackendTest`): `run()` registra un reso da due righe e dice «Registrato il reso RES/… di N pezzi: M rientrati a magazzino»; `run()` con tutte le quantità vuote → `ok=false` con la frase di `nothing_selected` e niente scritto; `run()` oltre il massimo → `ok=false`, frase di `over_quantity`; doppio invio dello stesso modulo → il secondo `ok=false`; `torna=https://altro.sito/` → `backUrlFrom` lo scarta; con `returns` spenta `run()` → `ok=false` e niente scritto; `runStatus('complete', …)` e `('cancel', …)` ricalcano `Returns`; ordine inesistente o carrello → «Ordine non trovato».
- [ ] **Step 3: eseguire** — atteso: rossi (classe e metodi mancanti).
- [ ] **Step 4: scrivere** `OrderActions::canRegisterReturn`, `OrderReturnResource` (pagina-form + POST, `isFormPage()`, `$feature = 'returns'`, permessi come `OrderPaymentResource`, nessuna voce di menu), la voce *Registra reso* in `OrderResource::actionsFor` (dopo *Registra pagamento*, prima di *Annulla*, `href` = `OrderReturnResource::urlFor()` con `torna` alla scheda) e, in `OrderReturnTableResource`, la colonna *Righe* (nomi e pezzi) e la colonna *Azioni* con due piccoli form POST (`Chiudi`, `Annulla`) verso la stessa Resource.
- [ ] **Step 5: eseguire** i test e `php tests/run.php` — atteso: verdi.
- [ ] **Step 6: commit** «Registra reso: pagina, voce nel menu dell'ordine, chiusura e annullo dalla tabella».

### Task 4: Un reso nei dati di prova

**Files:**
- Modify: `src/Seeding/OrdersDemo.php` (un reso sull'ordine evaso, pulizia in `purge()`), `tests/integrazione/OrdersDemoTest.php`

- [ ] **Step 1: scrivere il test** — con `returns` accesa, `OrdersDemo::create()` lascia un reso `received` su un ordine evaso, di una riga con `changed_mind`, con il movimento `return` sul magazzino; con `returns` spenta non crea nessun reso; `clear()` toglie reso, righe e log prima dell'ordine, senza errori di chiave, e non tocca i resi di ordini veri.
- [ ] **Step 2: eseguire** il test — atteso: rosso.
- [ ] **Step 3: implementare** il reso finto passando da `Returns::register()` con `source = system` (dopo `fulfilled()`), il codice col segno del seed (`DemoCode::forModel(SalesReturn::class, …)`), e in `purge()` la cancellazione di `gst_sales_return_status_logs`, `gst_sales_return_items`, `gst_sales_returns` (e dei movimenti con `reference_type = sales_return` di quei resi) prima delle righe d'ordine.
- [ ] **Step 4: eseguire** `php tests/run.php` — atteso: verde. Rigenerare il sito: `php forge gestionale:demo --fresh` in `boilerplates/ecommerce-site` e controllare che l'ordine evaso mostri il reso.
- [ ] **Step 5: commit** «Demo: un reso sull'ordine evaso».

### Task 5: Guide utente e guida per sviluppatori

**Files:**
- Create: `docs/user/vendite-ordini.md`, `docs/user/vendite-pagamenti.md`, `docs/user/vendite-resi.md`, `docs/dev/concetti/vendite.md`
- Modify: `docs/user/SUMMARY.md` (sezione **Vendite** dopo *Magazzino*), `docs/dev/SUMMARY.md` (la voce *Vendite* fra i concetti)

Le guide utente raccontano cosa si vede e cosa succede, con le stesse parole del backend, senza codice:
- **Ordini** — l'elenco e i filtri, la scheda (accordion, cliente cliccabile verso la sua scheda, note), gli stati (in attesa, confermato, evaso, annullato) e le azioni *Conferma*, *Segna evaso*, *Annulla* con ciò che fanno al magazzino; cosa significano i tre stati e quando le note si bloccano.
- **Pagamenti e scadenze** — *Registra pagamento* (importo, metodo, data, riferimento; acconto), lo stato del pagamento, i *Metodi* e i *Conti* in Set Up, l'attesa del pagamento e la scadenza delle prenotazioni (`Expiry`), cosa vede il cliente.
- **Resi** — quando *Registra reso* compare, come si compila (righe, quantità, motivo, rientro a magazzino proposto spento per merce rotta), cosa succede in magazzino e nei movimenti, *Chiudi* e *Annulla* il reso, perché il rimborso non parte da qui.

La guida per sviluppatori (`docs/dev/concetti/vendite.md`) è quella che E1 leggerà per costruire il checkout: la mappa dei servizi (`Cart`, `Checkout`, `Lifecycle`, `Ledger`, `Allocation`, `Returns`, `Expiry`), chi è l'unico a scrivere ogni stato (`Lifecycle` → `status`, `Ledger::sync()` → `payment_status`, `Returns` → stato del reso, `Allocation` → prenotazione/scarico/rientro), le chiavi d'errore `UserError` e come mostrarle, come si accendono le funzionalità `orders` e `returns`, come si provano i servizi nei test d'integrazione (`prova()`, `accendiFunzionalita`, helper di `compra.php`) e la demo.

- [ ] **Step 1: scrivere le quattro pagine**, ciascuna con un titolo `#` e le sezioni sopra, controllando i nomi di pulsanti e colonne contro il codice (`OrderActions::LABELS`, `OrderResource`, `ReturnRules::REASON_LABELS`).
- [ ] **Step 2: aggiornare `docs/user/SUMMARY.md`** con la sezione **Vendite** e le tre voci.
- [ ] **Step 3: eseguire** `php tests/DocsPagesTest.php` — atteso: verde (ogni pagina è nel sommario, ogni link interno esiste); poi `php tests/run.php` verde.
- [ ] **Step 4: commit** «Guide utente di Vendite e guida per sviluppatori ai servizi di vendita».

### Task 6: Chiusura di G4

**Files:**
- Modify: `CHANGELOG.md`, `TODO.md` (spuntare il Piano 5 e chiudere G4), `docs/superpowers/REVISIONI-scheda-ordine.md` (la spunta della prova nel browser, se fatta)

- [ ] **Step 1: CHANGELOG** — voce sui resi (pagina *Registra reso*, `Returns`, rientro a magazzino, chiusura/annullo, demo) e sulle guide.
- [ ] **Step 2: TODO.md** — spuntare il Piano 5 e la voce **G4**, aggiungere in *Fatto* la data (2026-10-01) e i commit.
- [ ] **Step 3: eseguire** `php tests/run.php` — atteso: tutta la suite verde (unitari + integrazione); riportare i conteggi.
- [ ] **Step 4: commit**, push del branch `piano-5-resi`, PR su `main` con la descrizione e il trailer; CI verde; merge con merge commit e pulizia di branch remoto e locale (regola dell'utente).

### Task 7: Prova nel browser (serve il tuo accesso)

Non si può fare da soli: il backend chiede il login. Dopo il Task 6 dico a chi guarda cosa aprire su `https://ecommerce.test/backend/`: *Vendite → Ordini* (l'ordine con il reso di prova), la scheda dell'ordine (accordion *Resi*, voce *Registra reso*), la pagina di registrazione con un reso `damaged` (spunta spenta) e uno `changed_mind`, *Chiudi* sul reso, la scheda cliente di Anna Verdi, le finestre di *Conferma*, *Annulla* e *Registra pagamento*. Gli eventuali ritocchi li registro in `REVISIONI-scheda-ordine.md`.

- [ ] **Step 1: avvisare l'utente** che la prova serve il suo login e proporre una breve sessione guidata (o la sua lista di ritocchi).
