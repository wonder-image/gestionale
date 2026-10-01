# Backend *Vendite* — piano di realizzazione

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** dare agli ordini del Piano 3 la loro faccia nel backend — elenco, scheda in sola lettura, azioni *Conferma*, *Annulla*, *Segna evaso* e *Registra pagamento* — con i *Metodi* e i *Conti di pagamento* in Set Up, gli ordini finti nel `DemoCommand` e, già che la pagina si tocca, il Piano 3 di G2b-bis (i *Movimenti* con *Chi*, *Prima*, filtri e ricerca sull'articolo).

**Architecture:** nessuna regola di business nuova: ogni azione chiama `Lifecycle` o `Ledger` e mostra l'esito. Quello che il backend aggiunge sta in tre pezzi puri e provabili senza browser né database — `StatusLabels` (etichetta e colore dei tre stati), `OrderActions` (quali azioni sono visibili per quell'ordine e che frase dice la finestra di conferma) e `MovementPeriod` (gli intervalli del filtro *Periodo*) — e in Resource sottili che li usano. L'elenco è una Resource di consultazione come `StockLevelResource`; la scheda ricalca `ProductModelResource` (vista `show` + `showLayoutSchema()` + tabelle incorporate); le azioni passano da una Resource senza menu (`NavigationOnlyResource`) che riceve un POST e rimanda alla scheda; *Registra pagamento* è una pagina-form con `torna=` come `StockAdjustmentResource`.

**Tech Stack:** PHP 8.2+ (8.5.7 via Herd), framework Wonder Image (`wonder-image/app`), harness `tests/harness.php` con `php tests/run.php`, sito di prova `boilerplates/ecommerce-site` per i test d'integrazione e la prova nel browser (`ecommerce.test`). Nessuna libreria nuova.

**Spec:** `docs/superpowers/specs/2026-09-29-ordini-e-pagamenti-design.md` (§4 Backend, §5 test) e, per i *Movimenti*, `docs/superpowers/specs/2026-09-23-giacenze-movimenti-documenti-design.md` (§3, righe 158–230)

## Global Constraints

- Lingua: commenti, testi utente e nomi dei test in **italiano** con gli accenti corretti; classi, tabelle, colonne e chiavi in inglese. Le etichette dell'interfaccia sono scritte nelle Resource, come nelle altre.
- Form compatti: tooltip al posto dei testi d'aiuto, campi correlati vicini, **niente campi automatici** (lo slug, il codice).
- `Lifecycle` è l'unico che scrive `status`; `Ledger::sync()` l'unico che scrive `payment_status`; prenotazione e scarico solo da `Allocation`. Il backend **chiama**, non riscrive.
- I messaggi d'errore passano da `UserError::make('chiave', [...])` con la frase in `lang/it/gestionale.json` (`gestionale.errors.<gruppo>.<chiave>`, segnaposto `{{nome}}`); `ErrorKeysTest` cade se manca. Il JSON si riscrive con python `json.dumps(ensure_ascii=False, indent=4) + "\n"`.
- Ogni Resource dati dichiara `$feature` (qui `'orders'`, con `GestionaleResource`) o sta nell'elenco «sempre attive» di `ConventionsTest`; le pagine di documentazione nuove vanno nel `SUMMARY` (`DocsPagesTest`).
- Il denaro si mostra con due decimali e la virgola all'italiana, si scrive con il punto (`number_format($v, 2, '.', '')`); l'importo digitato `12,50` e `12.50` valgono lo stesso.
- Tutto ciò che viene dal database e finisce in HTML passa da `escape()` di `GestionaleResource`. Il ritorno `torna=`/`back` passa dal controllo anti-redirect esterno di `StockAdjustmentResource::backUrlFrom()` (stesso codice, non una copia diversa).
- I test d'integrazione girano in `Transaction::run()` annullata (`final class Annulla extends RuntimeException {}`, helper `prova()`), si appoggiano a `tests/integrazione/supporto/compra.php`, e non lasciano righe.
- TDD: test rosso visto fallire, implementazione minima, test verde, commit. Ogni task chiude con `php tests/run.php` verde per intero.

## Decisioni di perimetro

- **Registra reso resta al Piano 5.** La spec lo mette fra le azioni del menu, ma il servizio `Returns` (righe, quantità, causale, rimessa a scaffale) è del Piano 5 insieme alla sua pagina di registrazione. Qui la scheda mostra già la tabella dei resi (vuota finché non ce ne sono) e il menu non ha la voce: l'aggiunge il Piano 5.
- **Niente rimborso da qui.** *Annulla* dice quanto c'è da restituire (`refundable`) ma non lo registra: lo fa `Ledger::refund()` quando il gateway risponde.
- **Niente azione per l'evasione parziale o «pronto per il ritiro».** *Segna evaso* scrive `fulfilled`; le altre due strade le apre G7 con le spedizioni.

## Review Focus

- **Un carrello aperto dal suo indirizzo.** L'URL della scheda con l'`id` di una riga `stage='cart'` non mostra il carrello come fosse un ordine: 404 o torna all'elenco. *(test nel Task 2)*
- **Azione su un ordine che non la consente.** Doppio clic su *Conferma*, *Annulla* su un annullato, *Segna evaso* su un ordine in attesa: messaggio chiaro, niente scritto, niente errore 500. *(test nel Task 3)*
- **Importo del pagamento strano.** Zero, negativo, testo, `12,50`, più del dovuto: rifiutati o letti bene, mai un pagamento da zero né un `NaN`. *(test nel Task 4)*
- **Ritorno aperto verso fuori.** `torna=https://altro.sito/` nelle azioni e nella pagina-form non porta fuori dal backend. *(test nel Task 4)*
- **Cliente senza nome, ordine senza prodotti.** Un privato senza `business_name`, un cliente ospite senza `customer_id`, un ordine con la sola spedizione: la scheda e la finestra dicono «0 pezzi» e mostrano l'email, senza buchi né avvisi PHP. *(test nel Task 1 e 3)*
- **Metodo di pagamento già usato.** Eliminare o spegnere un metodo o un conto che hanno ordini e pagamenti: eliminare è rifiutato con la frase giusta, spegnere no. *(test nel Task 5)*
- **Il `--fresh` della demo con gli ordini dentro.** Ordini, righe, prenotazioni, movimenti e pagamenti finti spariscono prima dei prodotti finti, senza violare le chiavi esterne e senza toccare gli ordini veri. *(test nel Task 7)*

---

## File Structure

| File | Responsabilità |
|---|---|
| `src/Support/Orders/StatusLabels.php` (nuovo) | Etichetta italiana e colore di ordine, pagamento ed evasione. Puro. |
| `src/Support/Orders/OrderActions.php` (nuovo) | Azioni visibili per un ordine e frase della finestra di conferma. Puro. |
| `src/Support/Stock/MovementPeriod.php` (nuovo) | Intervallo del filtro *Periodo* dei movimenti. Puro. |
| `src/Resources/Sales/OrderResource.php` (nuovo) | Elenco, scheda in lettura, menu ⋯, sezione *Vendite*. |
| `src/Resources/Sales/OrderActionResource.php` (nuovo) | Rotte POST di *Conferma*, *Annulla*, *Segna evaso*. Senza menu. |
| `src/Resources/Sales/OrderPaymentResource.php` (nuovo) | Pagina-form *Registra pagamento*. Senza menu. |
| `src/Resources/Payments/PaymentMethodResource.php`, `PaymentAccountResource.php` (nuovi) | CRUD in Set Up, solo `admin`. |
| `view/pages/order-show.php` (nuovo) | Vista `show` della scheda. |
| `src/Resources/Stock/StockMovementResource.php`, `ProductResource.php` (modificati) | *Movimenti* e *Ultimi movimenti*. |
| `src/Console/Demo/OrdersDemo.php` (nuovo) | Ordini finti. |
| `src/Support/Payments/Ledger.php` (modificato) | `paid_at` facoltativo nei dati. |
| `src/Seeding/Defaults.php`, `src/Seeding/Demo.php` (modificati) | Metodi di base; `OrdersDemo` dopo i fornitori di dati. |

---

### Task 1: Etichette degli stati, sezione *Vendite* ed elenco *Ordini*

**Files:**
- Create: `src/Support/Orders/StatusLabels.php`, `src/Resources/Sales/OrderResource.php`
- Test: `tests/OrderStatusLabelsTest.php`, `tests/OrderResourceTest.php`
- Modify: `tests/ConventionsTest.php` solo se la Resource finisce nell'elenco «sempre attive» (non dovrebbe: dichiara `$feature = 'orders'`)

**Interfaces:**
- Produces: `StatusLabels::order(string): array{label: string, color: string}`, `::payment(string)`, `::fulfillment(string)`, `::badge(string $kind, string $value): string` (HTML `<span class="badge text-bg-<color>">` con etichetta escapata); valore sconosciuto → etichetta il valore stesso, colore `secondary`, mai un'eccezione. `OrderResource::detailUrl(int $id, ?string $back = null): string` (usato dai Task 2–4 e dai *Movimenti*).
- Consumes: costanti di `Models\Sales\Order` (`STATUSES`, `PAYMENT_STATUSES`, `FULFILLMENT_STATUSES`).

Riferimenti: struttura dell'elenco = `src/Resources/Stock/StockLevelResource.php` (`TableLayoutSchema::for()->title()->results()->hideButtonAdd()->select()->filterSearch()->searchFields()->filterQuery()`, `pageSchema()->only(['list'])`, `apiSchema()->enabled(false)`); sezione e permessi = `NavigationSchema` / `PermissionSchema` come in `StockLevelResource` (sezione nuova `vendite`, etichetta «Vendite», icona `bi-receipt`, ordine 350, ruoli `admin` e `administrator`).

- [x] **Step 1: test puri di `StatusLabels`** — ogni valore delle tre costanti del Model ha etichetta e colore (il test scorre le costanti, così un valore aggiunto domani senza etichetta fa cadere il test); un valore sconosciuto torna com'è e `secondary`; `badge()` escapa un valore con `<script>`. Colori: confermato/pagato/evaso verde, in attesa/parziale giallo, annullato/rimborsato/non pagato rosso o grigio — scegliere e fissare nel test, coerenti fra i tre stati (pagato e evaso stesso verde).
- [x] **Step 2: far fallire, implementare `StatusLabels`, far passare, commit.**
- [x] **Step 3: test di `OrderResource` senza database** (come `StockLevelResourceTest`): la Resource sta nella sezione `vendite` e i suoi ruoli sono `admin` e `administrator`; schema in sola lettura (`pageSchema` solo `list` e `view`, API spenta); colonne attese *Numero*, *Data*, *Cliente*, *Totale*, *Ordine*, *Pagamento*, *Evasione*; la `select()` e il filtro base escludono `stage <> 'order'` (il test legge la stringa SQL e controlla `stage = 'order'`); i quattro filtri (*Stato*, *Pagamento*, *Evasione*, *Periodo*) usano le chiavi delle costanti del Model e non etichette scritte a mano.
- [x] **Step 4: implementare `OrderResource` (elenco).** Cliente = `billing_business_name`, altrimenti nome e cognome della fatturazione, altrimenti l'email (mai vuoto); *Totale* in euro all'italiana; le tre colonne di stato con `StatusLabels::badge`; ricerca su numero, nome e cognome e ragione sociale di fatturazione, email; il filtro *Periodo* riusa `MovementPeriod` (Task 6) — **se il Task 6 non c'è ancora, in questo task il filtro *Periodo* non si mette e lo aggiunge il Task 6** (decisione: i task restano ordinati e verdi uno a uno); ordinamento predefinito per data decrescente; riga ⋯ con *Apri* (`detailUrl`). Sezione *Vendite* creata qui.
- [x] **Step 5: test d'integrazione** `tests/integrazione/OrderBackendTest.php`: con `ordineDiProva()` e un carrello (`Cart::open`) la query dell'elenco restituisce l'ordine e **non** il carrello; cliente senza nomi → mostra l'email.
- [x] **Step 6: `php tests/run.php` verde, commit.**

---

### Task 2: Scheda dell'ordine in sola lettura

**Files:**
- Create: `view/pages/order-show.php`
- Modify: `src/Resources/Sales/OrderResource.php` (pagina `view`, `showLayoutSchema()`, tabelle incorporate)
- Test: `tests/OrderResourceTest.php` (schema), `tests/integrazione/OrderBackendTest.php` (resa)

**Interfaces:**
- Consumes: `StatusLabels::badge()`, `OrderResource::detailUrl()` (Task 1).
- Produces: `OrderResource::showLayoutSchema(array $order): array` (usato dai test e dalla vista), e un metodo che compone il blocco delle azioni del Task 3 (`actionsFor(array $order): array`, per ora restituisce `[]`).

Riferimenti: tutto il disegno è `ProductModelResource` — `pageSchema()` ~722–750 (`enable(['view'])->titles()->view('show', Gestionale::viewPath(...))->actions('view', fn)`), vista `view/pages/product-model-show.php`, `showLayoutSchema()` ~777 (`Container > Card > SectionTitle::make()->tooltip()->columnSpan(12)`, `RichText::make($html)->tag('div')`), `showRow()` ~871. Tabelle incorporate: `ProductResource::stockHistoryTable()` ~647–675 (`Resource::backendTable([...], TableLayoutSchema)` con `->title(false)->filterSearch(false)->query("`order_id` = N AND `deleted`='false'")->queryOrder(...)`, `(string) $t->generate(false)` in try/catch).

Sezioni, in quest'ordine (spec §4): intestazione (numero, data, canale, tre stati, cliente con email e telefono, indirizzi di fatturazione e spedizione, metodo di pagamento, note cliente/interne/documento), *Righe* (nome, quantità, prezzo, sconto, IVA, totale riga; tipi `product`, `custom`, `text`, `shipping`, `fee` — le righe `text` senza importi), *Riepilogo IVA* per aliquota (da `OrderTaxSummary`), *Totali*, *Pagamenti* (codice, tipo pagamento/rimborso, importo, stato, data, metodo, riferimento), *Resi* (codice, stato, data, importo: vuota senza `returns` acceso o senza resi), *Storico* (`OrderStatusLog`: data, campo, da, a, origine, utente).

- [x] **Step 1: test di schema** — `showLayoutSchema()` contiene le sette sezioni nell'ordine; nessun campo è un input (sola lettura: nessun `FormField`).
- [x] **Step 2: test d'integrazione** — la vista dell'ordine di prova mostra numero, nome del cliente, totale e il riepilogo IVA; **il nome del cliente `<b>x</b>` esce escapato**; l'ordine con la sola riga di spedizione non genera avvisi; l'`id` di un carrello (`stage='cart'`) e un `id` inesistente non producono la scheda (ritorno all'elenco o 404, scegliere uno e fissarlo nel test); una riga `deleted='true'` non compare.
- [x] **Step 3: implementare vista, `showLayoutSchema()`, `pageSchema()`** — titolo «Ordine {numero}», pulsante *Elenco* di ritorno; la guardia `stage='order'` sta in un solo punto (il caricamento dell'ordine della scheda).
- [x] **Step 4: verde, `php tests/run.php`, commit.**

---

### Task 3: Azioni *Conferma*, *Annulla* e *Segna evaso*

**Files:**
- Create: `src/Support/Orders/OrderActions.php`, `src/Resources/Sales/OrderActionResource.php`
- Modify: `src/Resources/Sales/OrderResource.php` (`actionsFor()`, finestra di conferma nel corpo della pagina), `lang/it/gestionale.json`
- Test: `tests/OrderActionsTest.php`, `tests/integrazione/OrderBackendTest.php`

**Interfaces:**
- Produces: `OrderActions::available(array $order, array $features): list<string>` (valori `confirm`, `cancel`, `fulfill`); `OrderActions::summary(string $action, array $order, array $items): string` (la frase della finestra); `OrderActionResource::urlFor(string $action): string`, e il POST con `order_id`, `action`, `torna`.
- Consumes: `Lifecycle::confirm/cancel/fulfill` (firme in `src/Support/Orders/Lifecycle.php`), `Checkout`/`Allocation` non direttamente.

Regole di visibilità (spec §4): *Conferma* su ordine `pending` (e `draft`); *Annulla* su ogni ordine non annullato e non chiuso come tale; *Segna evaso* su `confirmed` o `processing` con `fulfillment_status` ancora `unfulfilled`. Nessuna azione su `cancelled`. `returns` e `$features` entrano nel secondo argomento perché il Piano 5 aggiunga la voce senza toccare la firma.

Le frasi dicono cosa succede al magazzino, con i pezzi contati dalle righe `product` (`quantity` sommata, intera se è intera): «Conferma l'ordine e scarica 3 pezzi»; «Annulla l'ordine e libera 3 pezzi prenotati» se `pending`, «Annulla l'ordine e rimette 3 pezzi in magazzino» se già confermato; se l'ordine ha incassato qualcosa, in più «Il denaro già incassato (X €) non si rimborsa da qui». «Segna l'ordine come evaso: il magazzino non cambia, la merce è già uscita alla conferma». «1 pezzo» al singolare.

Il pulsante dell'azione apre una finestra scritta a mano nel corpo della pagina (nessun precedente di conferma dichiarativa nel core): il form fa POST a `OrderActionResource` con `order_id`, `action`, `torna`; vedi `ProductResource::stockAdjustModal` ~684–740 per la finestra e la memoria `reference_core_check_submit` per `data-wi-check` e gli ascolti. Le azioni di pagina ammettono solo `label/href/class/icon/target/onclick`: l'`onclick` apre la finestra con `window.bootstrap.Modal.getOrCreateInstance`.

- [x] **Step 1: test puri di `OrderActions`** — tabella stato → azioni (draft, pending, confirmed, processing, completed, cancelled, con e senza evasione); le frasi per 0, 1, 3 pezzi; la frase di *Annulla* cambia fra pending e confirmed; la frase dell'incassato compare solo con un pagato; un ordine senza righe prodotto dice «0 pezzi» e non cade.
- [x] **Step 2: far fallire, implementare `OrderActions`, far passare, commit.**
- [x] **Step 3: test d'integrazione dell'esecuzione** — un metodo `OrderActionResource::run(string $action, int $orderId, int $userId): array` (esito in forma di messaggio) separato dalla parte HTTP, così si prova dentro la transazione annullata: *Conferma* su un ordine in attesa lo porta a `confirmed` (e scarica); la stessa chiamata due volte → seconda volta `changed=false`, messaggio «già confermato», niente doppio scarico; *Annulla* su un annullato → messaggio, niente scritto; *Segna evaso* su un `pending` → `UserError` presa e tradotta in messaggio, stato invariato; l'`user_id` del backend finisce nello `OrderStatusLog` con `source='user'`; l'ordine che non è un ordine (carrello) o inesistente → messaggio, nessuna eccezione.
- [x] **Step 4: implementare `OrderActionResource`** (`NavigationOnlyResource`, POST; `UserError` → `FlashAlert::custom('Attenzione', $msg, 'warning')`; riuscito → `FlashAlert::saved()`; sempre redirect a `detailUrl` o al `torna=` validato dallo stesso `backUrlFrom()` di `StockAdjustmentResource` — estrarlo in un helper condiviso se serve, senza duplicarlo) e `OrderResource::actionsFor()` + finestra. Chiavi nuove in `lang/it/gestionale.json` per i messaggi di stato non coperti dagli errori di `Lifecycle` («già confermato», «ordine non trovato»).
- [x] **Step 5: `ErrorKeysTest` e `php tests/run.php` verdi, commit.**

---

### Task 4: *Registra pagamento*

**Files:**
- Create: `src/Resources/Sales/OrderPaymentResource.php`
- Modify: `src/Support/Payments/Ledger.php` (`paid_at` facoltativo), `src/Resources/Sales/OrderResource.php` (voce nel menu), `lang/it/gestionale.json`
- Test: `tests/PaymentStatusTest.php` o il test del Ledger esistente (il `paid_at`), `tests/integrazione/OrderBackendTest.php`

**Interfaces:**
- Consumes: `Ledger::register(array): array{payment_id, created, payment_status}`, `OrderPaymentResource` come `StockAdjustmentResource` (`NavigationOnlyResource`, `isFormPage(): true`, `urlFor($id, $back)`, `pageUrl()`/`submitUrl()`, `backUrlFrom()`).
- Produces: `Ledger::register` accetta `paid_at` (`Y-m-d` o `Y-m-d H:i:s`, non nel futuro), usato sia alla creazione sia quando adotta un pagamento aperto (`adoptOpen`/`move` oggi scrivono `date('Y-m-d H:i:s')`).

Il form è compatto: *Importo* (precompilato con il residuo `total − già incassato`, tooltip con il totale e l'incassato), *Metodo* (solo i metodi attivi), *Data* (oggi), *Riferimento* (tooltip: «numero di CRO o di operazione, facoltativo»). Visibile su ordini non annullati con residuo > 0. Dopo il salvataggio torna alla scheda col `torna=`.

- [x] **Step 1: test del Ledger** — `paid_at` passato viene scritto; senza resta «adesso»; una data futura è rifiutata con una chiave `payment.future_date` (frase in lingua).
- [x] **Step 2: far fallire, modificare `Ledger`, far passare, commit.**
- [x] **Step 3: test d'integrazione** — registrare l'importo intero porta l'ordine a `paid`; metà → `partially_paid`; poi l'altra metà → `paid` (e, se l'ordine era già evaso, `completed`: lo fa già `Ledger::sync` dal Piano 3); `12,50` è letto come 12.50; zero, negativo, testo → `UserError` tradotta, nessuna riga scritta; importo oltre il residuo → rifiutato con frase che dice il residuo (chiave `payment.over_balance`); ordine annullato → rifiutato; `torna=https://altro.sito/` → il redirect va alla scheda.
- [x] **Step 4: implementare la Resource, la voce *Registra pagamento* in `actionsFor()` (via `OrderActions::available`, valore `payment`, con la stessa tabella di visibilità testata) e le chiavi di lingua.**
- [x] **Step 5: verde, `php tests/run.php`, commit.**

---

### Task 5: *Metodi di pagamento* e *Conti di pagamento* in Set Up

**Files:**
- Create: `src/Resources/Payments/PaymentMethodResource.php`, `src/Resources/Payments/PaymentAccountResource.php`
- Modify: `src/Seeding/Defaults.php`, `lang/it/gestionale.json`, `tests/ConventionsTest.php` (se non dichiarano `$feature`, vanno fra le «sempre attive»; sono la configurazione, quindi sempre attive)
- Test: `tests/PaymentMethodResourceTest.php`, `tests/integrazione/PaymentSetupTest.php`

**Interfaces:**
- Consumes: modelli `Models\Payments\PaymentMethod` e `PaymentAccount` (colonne in `src/Models/Payments/`), sezione `set-up` creata da `FeatureResource`.
- Produces: due Resource CRUD solo per `admin` (`PermissionSchema::backendCrud(['admin'])`), `->inSection('set-up')->group('pagamenti','Pagamenti',60,['admin'])`; sincronizzati come le aliquote (`SyncSchema` già dichiarato nei Model: la Resource non lo ripete, il test controlla che ci sia).

Riferimenti: scheletro CRUD = `src/Resources/Tax/TaxResource.php`; `assertDeletable` e `mutateRequestValues` = `TaxCategoryResource.php`. Campi del metodo: *Nome*, *Codice* (nessun campo automatico, il codice si scrive), *Tipo* (`provider`), *Quando* (`timing`: subito / a termine / alla consegna), *Conto*, *Commissione* (`fee_type` + `fee_value` vicini, tooltip), *Disponibile per* (`available_for`), canali (`applies_online/office/pos`), *Istruzioni* (per il bonifico: l'IBAN lo compone la email dal conto), *Codice SDI*, *Posizione*, *Attivo*. Campi del conto: *Nome*, *Banca*, *IBAN*, *BIC*, *Attivo*.

- [x] **Step 1: test di schema** — le due Resource stanno in Set Up, gruppo *Pagamenti*, ruolo solo `admin`; i campi del form coincidono con le colonne dei Model (nessun campo fantasma, nessuna colonna senza campo tranne quelle di sistema); `provider` e `timing` offrono le chiavi delle costanti del Model.
- [x] **Step 2: test d'integrazione dell'eliminazione** — un metodo con ordini o pagamenti collegati non si elimina (`payment_method.in_use` con quanti ordini) ma si può spegnere; un conto con metodi collegati idem (`payment_account.in_use`); un metodo mai usato si elimina; l'IBAN è normalizzato (spazi e maiuscole) e uno malformato rifiutato (usare la validazione che esiste già nei Model o in `Support`: se non c'è, la regola minima è lunghezza 15–34 e solo lettere e cifre).
- [x] **Step 3: implementare le due Resource con `assertDeletable`.**
- [x] **Step 4: `Defaults.php` semina tre metodi di base** con `$rows->ensure(PaymentMethod::class, 'code', [...])`: `bank_transfer` (a termine, manual), `cash` (alla consegna, manual, ritiro), `stripe` (subito, **spento** finché non c'è la chiave). Il test di `Defaults` controlla che il rilancio non duplichi e non riaccenda uno spento a mano.
- [x] **Step 5: `ConventionsTest`, `ErrorKeysTest`, `php tests/run.php` verdi, commit.**

---

### Task 6: *Movimenti* — Piano 3 di G2b-bis

**Files:**
- Create: `src/Support/Stock/MovementPeriod.php`
- Modify: `src/Resources/Stock/StockMovementResource.php` (254 righe), `src/Resources/Catalog/ProductResource.php` (`stockHistoryColumns` ~629), `src/Resources/Sales/OrderResource.php` (filtro *Periodo*)
- Test: `tests/MovementPeriodTest.php`, `tests/StockMovementResourceTest.php` (**riscritto**)

**Interfaces:**
- Produces: `MovementPeriod::OPTIONS` (chiave → etichetta: `oggi`, `7-giorni`, `questo-mese`, `mese-scorso`, `quest-anno`), `MovementPeriod::range(string $key, DateTimeImmutable $now): ?array{from: string, to: string}` (formato `Y-m-d H:i:s`, estremi inclusi, `null` per chiave sconosciuta o vuota), `MovementPeriod::sql(string $key, string $column, DateTimeImmutable $now): string` (condizione già pronta, `''` se nessuno). Il fuso è quello del sito: l'`$now` lo passa il chiamante, la classe non chiama `date()`.
- Consumes: `ProductNames::of()` per «Articolo — Versione», `Locations::shown()` per sapere se mostrare *Sede*, `OrderResource::detailUrl()` (Task 1).

Che cosa manca rispetto alla spec (§3 di G2b-bis; già presenti: data, versione, tipo, causale, quantità, dopo, nota, ricerca su codice/nota, filtri *Tipo* e *Causale*, `?versione=`): colonna *Sede* solo con più sedi; *Prima* (`quantity_before`); *Chi* (nome dell'utente, altrimenti l'origine: «Importazione» o «Sistema»); data leggibile e ordinabile; «Articolo — Versione» al posto del solo codice; cella *Tipo* col numero del documento per le righe con `reference_type` (per ora `order`: il numero dell'ordine, con link); menu ⋯ con *Apri la versione* e, per `reference_type='order'`, *Visualizza ordine*; filtri *Tipo* e *Sede* come `filterQuery`; filtro *Periodo* con `MovementPeriod` al posto dei parametri `?dal=` e `?al=` (che si tolgono); ricerca anche su SKU, EAN, nome dell'opzione e nome dell'articolo (ricerca annidata del core, Piano 1); titolo «Movimenti di …» con *Mostra tutti* quando `?versione=` è attivo; *Ultimi movimenti* nella scheda della versione con le stesse regole di data, *Tipo* col documento e *Sede*.

- [x] **Step 1: test puri di `MovementPeriod`** — con un `$now` fisso (es. mercoledì 15 ottobre 2025, 10:30): *oggi* è `00:00:00`–`23:59:59` di quel giorno; *7 giorni* parte da sei giorni prima a mezzanotte; *questo mese* dal 1° al 31; *mese scorso* dal 1° al 30 settembre; *quest'anno* dal 1° gennaio; ai confini (il 1° del mese a mezzanotte, il 31 dicembre alle 23:59:59, 29 febbraio di un anno bisestile per *mese scorso*) gli estremi sono giusti; chiave sconosciuta → `null` e `sql()` → `''`; `sql()` non contiene mai la chiave grezza (nessuna iniezione: una chiave `'; DROP` non produce SQL).
- [x] **Step 2: far fallire, implementare, far passare, commit.**
- [x] **Step 3: riscrivere `tests/StockMovementResourceTest.php`** sullo schema nuovo — colonne e loro ordine, *Sede* assente con una sola sede e presente con più (il test usa `Locations::shown()` con la stessa tecnica di `LocationResourceTest`), filtri `Tipo`, `Sede`, `Periodo` presenti e senza i vecchi `dal`/`al`, ricerca con i campi dell'articolo, menu ⋯, `?versione=` che cambia titolo e aggiunge *Mostra tutti*.
- [x] **Step 4: implementare in `StockMovementResource`**; test d'integrazione in `tests/integrazione/` — un movimento nato da un ordine (`Lifecycle::confirm` dell'ordine di prova) mostra il numero dell'ordine e il link; un movimento senza utente mostra «Sistema»; uno senza `reference_type` non produce voce *Visualizza ordine*.
- [x] **Step 5: *Ultimi movimenti* in `ProductResource::stockHistoryColumns`** (stesse celle, riuso, non copia) e test dello schema.
- [x] **Step 6: aggiungere il filtro *Periodo* all'elenco *Ordini*** (Task 1, Step 4) con `MovementPeriod` su `ordered_at`, e il suo test.
- [x] **Step 7: `php tests/run.php` verde, commit.** In `TODO.md` il Piano 3 di G2b-bis si spunta alla chiusura del piano, non qui.

---

### Task 7: Ordini finti nel `DemoCommand`

**Files:**
- Create: `src/Console/Demo/OrdersDemo.php`
- Modify: `src/Seeding/Demo.php:19` (aggiunge `OrdersDemo::class` **dopo** `ContactsDemo` e `CatalogDemo`)
- Test: `tests/integrazione/OrdersDemoTest.php`

**Interfaces:**
- Consumes: il contratto dei provider in `src/Console/Demo/DemoData.php` (`register($key, $title, callable $create, callable $clear)`; `create` torna il numero di righe create, `clear` le rimosse), `DemoCode` per i codici, i clienti `con_demo-bianchi` e `con_demo-rossi-abbigliamento` (email `@example.com`), i prodotti di `CatalogDemo`, `Cart::open/add`, `Checkout::place`, `Ledger::register`, `Lifecycle::confirm/fulfill/cancel` con `notify => false` (nessuna email vera dalla demo).
- Produces: sette ordini finti riconoscibili (codice demo): uno in attesa di bonifico, uno confermato e pagato, uno evaso (quindi completato), uno annullato, uno con pagamento parziale, uno con un cliente ospite senza `customer_id`, uno di un'azienda con ragione sociale. **Niente resi:** il servizio è del Piano 5.

Il `clear()` va scritto a mano e **prima** di quello del catalogo: toglie, per gli ordini demo, i movimenti (`StockHistory::purge`, come `CatalogDemo::clear()` ~848), le prenotazioni, i pagamenti e i loro log, le righe, i riepiloghi IVA, i log di stato e infine gli ordini; riconosce gli ordini demo dal codice/numero demo, **mai** da un criterio largo (cliente `@example.com`, data) che prenda ordini veri. L'ordine dei provider in `Demo.php` deve far sì che al `--fresh` il `clear()` di `OrdersDemo` giri prima di quello di `CatalogDemo` (controllare come `DemoData` ordina i `clear`: se li esegue in ordine inverso di registrazione, basta registrarlo dopo; se no, il test lo cattura).

- [x] **Step 1: test d'integrazione** — dopo `create` ci sono sette ordini demo negli stati attesi, con `payment_status` e `fulfillment_status` coerenti col `Ledger`; il magazzino dei prodotti demo è sceso dei pezzi giusti (confermati ed evasi scaricano, in attesa prenota, annullato non pesa); un secondo `create` non duplica; un ordine non demo creato prima del `clear` **sopravvive** al `clear`; dopo `clear` non restano righe demo in nessuna delle tabelle (movimenti, prenotazioni, pagamenti, righe, log) e il `clear` del catalogo che segue non incontra chiavi esterne.
- [x] **Step 2: far fallire, implementare `OrdersDemo`, registrarlo in `Demo.php`, far passare.**
- [x] **Step 3: `php tests/run.php` verde; lancio vero sul sito di prova** (`php artisan gestionale:demo --fresh` nel sito, comando come negli altri task di demo) e controllo degli ordini nell'elenco con `get_page_text` del browser interno; commit.

---

### Task 8: Chiusura — prova nel browser, documenti e TODO

**Files:**
- Modify: `CHANGELOG.md`, `TODO.md`, `docs/user/SUMMARY.md` solo se si aggiunge una pagina, `docs/superpowers/plans/2026-10-01-backend-vendite.md` (spunte)
- Test: `tests/DocsPagesTest.php` resta verde

Le guide utente sugli ordini sono del Piano 5 (spec §5): qui solo il CHANGELOG e l'aggiornamento della pagina `docs/user/magazzino-movimenti.md`, che racconta le colonne e i filtri vecchi dei movimenti.

- [x] **Step 1: aggiornare `docs/user/magazzino-movimenti.md`** (colonne *Chi*, *Prima*, *Sede*, filtro *Periodo*, ricerca sull'articolo, documento dentro *Tipo*, *Ultimi movimenti* nella scheda).
- [ ] **Step 2: prova nel browser su `ecommerce.test`** con la demo caricata: elenco *Ordini* (filtri, ricerca, le tre etichette), scheda di un ordine per ciascuno stato, *Conferma* sull'ordine in attesa, *Segna evaso*, *Annulla* su uno confermato (controllare i pezzi tornati in *Giacenze*), *Registra pagamento* parziale e a saldo, il ritorno con `torna=`, *Metodi* e *Conti* in Set Up da `admin` e la loro assenza per un altro ruolo, *Movimenti* con il link all'ordine; schermo stretto sull'elenco e sulla scheda. Tutto quello che non torna è un difetto da correggere con il suo test prima di chiudere.
- [x] **Step 3: CHANGELOG** (nota per chi aggiorna: `PaymentMethod` e `PaymentAccount` non cambiano tabelle in questo piano; `Defaults` semina tre metodi nuovi), **TODO.md** (Piano 4 spuntato con riassunto come i precedenti; Piano 3 di G2b-bis spuntato; Piano 5 resta aperto con la nota «Registra reso e la voce del menu»).
- [x] **Step 4: `php tests/run.php` verde per intero, commit.**

---

## Self-review rispetto alla spec

- §4 Elenco *Ordini*: tre etichette, filtri per tre stati e periodo, ricerca per numero, cliente, email → Task 1 (+ periodo al Task 6 step 6). ✔
- §4 Scheda in sola lettura (intestazione, righe, riepilogo IVA, totali, pagamenti, resi, storico) → Task 2. ✔
- §4 Azioni *Conferma*, *Annulla*, *Segna evaso* con finestra che dice cosa succede al magazzino → Task 3; *Registra pagamento* come pagina-form con `torna=` → Task 4; visibilità per stato → `OrderActions`. ✔
- §4 *Registra reso* → **rimandato al Piano 5**, dichiarato in «Decisioni di perimetro» e nel TODO. ⚠ voluto.
- §4 Set Up: *Metodi* e *Conti* in Set Up, `admin`, sincronizzati, niente box nella home → Task 5. ✔
- TODO: ordini finti nel `DemoCommand` → Task 7; Piano 3 di G2b-bis (colonne, *Chi*, *Prima*, filtri, ricerca, *Ultimi movimenti*) → Task 6. ✔
- Coerenza dei nomi fra task: `StatusLabels::badge`, `OrderResource::detailUrl`, `OrderActions::available/summary`, `OrderActionResource::run/urlFor`, `MovementPeriod::range/sql/OPTIONS` sono definiti dove nascono e usati con la stessa firma nei task successivi. Il Task 1 non dipende dal 6 (il filtro *Periodo* degli ordini si aggiunge al Task 6).
- Review Focus: ogni riga ha il suo test nel task indicato.
