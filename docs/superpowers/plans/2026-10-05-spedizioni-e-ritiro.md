# Spedizioni, tracking e ritiro in sede — piano di realizzazione (G7, piano 2)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** le spedizioni vere: un ordine si spedisce in uno o più colli con vettore e numero di tracking, ogni passaggio resta nello storico, l'evasione dell'ordine si ricava dalle quantità spedite, il cliente riceve l'email con il link di tracking; e il ritiro in sede, gratuito, con «pronto per il ritiro» e «ritirato».

**Architecture:** un servizio, `Shipments`, è l'unica porta che scrive spedizioni, righe e storico (`create`, `ship`, `deliver`, `cancel`, `ready`, `pickedUp`); dopo ogni scrittura ricalcola `fulfillment_status` dell'ordine passando da `Lifecycle::fulfill` (che mantiene la chiusura automatica). Le righe dell'ordine si bloccano con `FOR UPDATE` mentre si assegnano le quantità. Due email nuove passano da `OrderNotifier`/`OrderEmail`; due widget nel cruscotto segnalano le spedizioni da guardare.

**Tech Stack:** PHP 8.2+, harness `tests/harness.php` (`php tests/run.php`), sito di prova `boilerplates/ecommerce-site` (tabelle con `php forge update --local`), helper `tests/integrazione/supporto/compra.php`.

**Spec:** `docs/superpowers/specs/2026-10-05-spedizioni-design.md` (sezione 3 e «I due piani» punto 2); piano precedente `2026-10-05-listini-di-spedizione.md` (da fare per primo: qui si usano `gst_carriers`, `Carriers::trackingUrl` e il metodo di spedizione dell'ordine).

## Global Constraints

- Lingua: commenti, testi utente e nomi dei test in **italiano** con gli accenti; classi, tabelle, colonne e chiavi in inglese.
- Funzionalità `shipping`: spenta, spariscono pagine, pulsanti e widget, **i dati restano**; con `shipping` spenta «Segna evaso» resta quello di sempre; con `shipping` accesa si evade solo spedendo (o con ritiro).
- Tabelle col prefisso `gst_`; soft delete `deleted='true'`; chiavi esterne `ON DELETE RESTRICT`; codice `shp_` (`Codes::SHIPMENT` esiste già).
- Stati. Consegna: `pending, label_created, in_transit, out_for_delivery, delivered, failed_attempt, exception, returned, cancelled`. Ritiro: `pending, ready_for_pickup, picked_up, cancelled`. Ogni cambio di stato scrive una riga in `shipment_status_logs` (`from_status`, `to_status`, `source`, `user_id`, `message`, come `OrderStatusLog` via `StatusLogger::record`).
- `fulfillment_status` dell'ordine (valori esistenti: `unfulfilled, ready_for_pickup, partially_fulfilled, fulfilled`) si ricava, non si scrive a mano, quando `shipping` è accesa: tutte le quantità in spedizioni **non annullate** in stato `in_transit` o successivo → `fulfilled`; alcune → `partially_fulfilled`; un ritiro `ready_for_pickup` → `ready_for_pickup`; `picked_up` → `fulfilled`; nessuna → `unfulfilled`. Si passa sempre da `Lifecycle::fulfill` (storico dell'ordine e `refresh` inclusi).
- Una riga d'ordine non si assegna oltre la sua quantità: **la somma delle quantità nelle spedizioni non annullate ≤ la quantità dell'ordine**, controllata con le righe dell'ordine bloccate (`FOR UPDATE`) dentro `Transaction::run`.
- Rifiuti prevedibili: `UserError::make('shipment.<chiave>', [...])`, frase in `lang/it/gestionale.json` sotto `gestionale.errors.shipment` (JSON riscritto con python `json.dumps(ensure_ascii=False, indent=4) + "\n"`); `ErrorKeysTest` cade se manca una chiave.
- Gli stati ammessi in avanti: `pending → label_created → in_transit → out_for_delivery → delivered`, con `failed_attempt`, `exception` e `returned` raggiungibili da `in_transit`/`out_for_delivery`, e `cancelled` solo da `pending`/`label_created`. Indietro non si torna; ripetere lo stesso stato non scrive niente (due clic non sono due passaggi).
- Form compatti (tooltip, campi correlati sulla stessa riga); tutto ciò che viene dal database e finisce in HTML o in un'email passa da `escape()`; il numero di tracking si codifica con `rawurlencode` nel link.
- Test d'integrazione dentro `prova()`, `Gestionale::reset()` dopo aver toccato le funzionalità; ogni task chiude con `php tests/run.php` verde per intero (rossi noti: `ContactResourcesTest` 1, `CatalogDemoTest` 7).
- Branch `g7-spedizioni`; commit con trailer `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`; `git add` file per file, **senza toccare** i file modificati dall'utente e non committati.

## Decisioni di perimetro

- **Una spedizione = un collo con un tracking.** Un ordine può avere più spedizioni (spedizioni parziali); ogni spedizione ha righe (`shipment_items`: `order_item_id`, `quantity`). Le righe `shipping`, `fee`, `discount` e i servizi non si spediscono; le confezioni si spediscono dalle righe-figlie come per il magazzino.
- **Creare ≠ spedire.** `create` fa nascere la spedizione in `pending` e già riserva le quantità; `ship` la porta a `in_transit` (con vettore, tracking e data); da lì in poi lo stato lo muove il commerciante a mano (non ci sono corrieri collegati).
- **Annullare** libera le quantità; una spedizione già `in_transit` non si annulla, si segna `returned`/`exception`. Dopo `returned` le quantità tornano disponibili per una nuova spedizione.
- **L'evasione** conta solo spedizioni da `in_transit` in poi: un `pending` non evade niente. `returned` e `cancelled` non contano.
- **Ritiro.** Una sola spedizione di tipo `pickup` per ordine, con `location_id` (la sede), senza vettore né tracking. Si può scegliere solo una sede con `is_pickup_point` e aperta (`SocietyLocations::isOpen`) come già prevede il carrello; costo zero, nessuna riga `shipping` (piano 1). `ready` → email «pronto per il ritiro»; `pickedUp` → ordine `fulfilled`.
- **Email.** `shipped` (alla creazione dello stato `in_transit`) e `ready_for_pickup`: chiavi nuove in `OrderEmail::KEYS`, testi e oggetto in `lang`, link di tracking da `Carriers::trackingUrl`; l'invio non blocca mai l'operazione (come per le altre email: un errore è `failed`, mai un'eccezione).
- **Reso.** Il termine del reso parte da `shipped_at` della prima spedizione consegnata/spedita se c'è, altrimenti da come oggi.
- **Fuori dal piano 2:** etichette e corrieri collegati, tracking automatico, ritiro prenotabile con fasce, checkout/area cliente (E1c).

## Review Focus

- **Due processi sull'ultima quantità.** Due creazioni di spedizione sulla stessa riga con 1 pezzo rimasto: una sola riesce, l'altra prende `shipment.over_quantity`. *(Task 2)*
- **Spedizione parziale e ricalcolo.** 2 pezzi su 3 spediti → `partially_fulfilled`; il terzo → `fulfilled` e, se pagato, l'ordine si chiude da solo; annullare la prima torna a `partially_fulfilled`, mai oltre. *(Task 3)*
- **Transizioni di stato.** Saltare passi, tornare indietro, ripetere lo stesso stato, annullare una spedizione già in viaggio, agire su un ordine annullato o con funzionalità spenta. *(Task 3)*
- **Righe spedibili.** Servizi, spedizione, commissioni, righe a quantità 0, confezioni (si spediscono le figlie), righe di un altro ordine passate a mano nel form. *(Task 2)*
- **Ritiro.** Sede non di ritiro o chiusa, ordine già con una spedizione di consegna, «ritirato» senza «pronto», doppio «pronto»; l'ordine con ritiro non ha mai la riga `shipping`. *(Task 4)*
- **Email e tracking.** Tracking con spazi e caratteri speciali, vettore senza modello di link (nessun link, ma l'email parte), cliente senza email (`no_recipients`, nessun errore), oggetto e corpo senza HTML iniettato. *(Task 5)*

---

### Task 1: Tabelle, Model e stati

**Files:**
- Create: `src/Models/Shipping/Shipment.php`, `ShipmentItem.php`, `ShipmentStatusLog.php`, `tests/ShipmentModelsTest.php`
- Modify: `tests/CodesTest.php` se elenca i prefissi (`SHIPMENT` esiste già)

**Interfaces:**
- Produces: `Shipment::$table = 'gst_shipments'` (`code` con `Codes::SHIPMENT`, `order_id`, `type` enum `delivery|pickup` default `delivery`, `status` enum (le costanti `Shipment::DELIVERY_STATUSES` e `Shipment::PICKUP_STATUSES`, l'enum della colonna è la loro unione), `carrier_id` int default 0 (0 = nessuno), `tracking_number`, `tracking_url` (calcolato alla spedizione e conservato, così un link già mandato non cambia se il vettore viene modificato), `location_id` int default 0 (ritiro), `shipped_at`, `delivered_at`, `note`); `ShipmentItem` (`shipment_id`, `order_item_id`, `quantity` decimal `12,3`, chiave esterna su `gst_order_items`); `ShipmentStatusLog` come `OrderStatusLog` (stessa forma: `shipment_id`, `from_status`, `to_status`, `source`, `user_id`, `message`), tutti `syncSchema()` null (storia dell'ambiente, non configurazione, come `Order`).

Ricalcare gli schemi su `Order`/`OrderItem`/`OrderStatusLog` e `SalesReturn`/`SalesReturnItem`/`SalesReturnStatusLog`.

- [ ] **Step 1: scrivere i test** in `ShipmentModelsTest.php` (stile di `SalesReturnModelsTest` se esiste, altrimenti `PromotionModelsTest`): le tre tabelle hanno le colonne attese, chiavi esterne e default; `Shipment::DELIVERY_STATUSES` e `PICKUP_STATUSES` hanno esattamente i valori del piano; l'enum della colonna li contiene tutti; il codice usa `shp_`.
- [ ] **Step 2: eseguire** `php tests/ShipmentModelsTest.php` — atteso: rosso (classi mancanti).
- [ ] **Step 3: implementare** i tre modelli.
- [ ] **Step 4: eseguire** il test, `cd /Users/andreamarinoni/Developer/boilerplates/ecommerce-site && php forge update --local` (atteso: tre tabelle create) e `php tests/run.php` dal pacchetto — atteso: verde.
- [ ] **Step 5: commit** «Spedizioni: tabelle e Model delle spedizioni».

### Task 2: `Shipments::create` e le quantità

**Files:**
- Create: `src/Support/Shipping/Shipments.php`, `src/Support/Shipping/ShippableLines.php`, `tests/ShippableLinesTest.php`, `tests/integrazione/ShipmentsCreateTest.php`
- Modify: `lang/it/gestionale.json` (`shipment.over_quantity`, `shipment.nothing_to_ship`, `shipment.order_not_open`, `shipment.feature_off`)

**Interfaces:**
- Produces: `ShippableLines::remaining(array $items, array $shipped): array<int, float>` pura: dalle righe dell'ordine (`id`, `type`, `quantity`, `requires_shipping`) e dalle quantità già in spedizioni vive (`order_item_id => quantità`) restituisce, per ogni riga spedibile (`type = product`, `requires_shipping` vero, figlie delle confezioni al posto della madre), quanto resta da spedire (mai negativo).
- Produces: `Shipments::remaining(int $orderId): array<int, float>` (legge righe e spedizioni vive e chiama `ShippableLines::remaining`); `Shipments::create(int $orderId, array $quantities, array $options = []): int` con `$quantities` `[order_item_id => quantità]`, `$options` `['carrier_id' => int, 'tracking_number' => string, 'note' => string, 'source' => string, 'user_id' => int]`, che crea la spedizione `pending` e le sue righe dentro `Transaction::run`, **bloccando le righe dell'ordine con `SELECT … FOR UPDATE`** prima di leggere le quantità già assegnate, scrive la prima riga di storico (`'' → pending`) e restituisce l'id. Lancia `shipment.feature_off` con la funzionalità spenta, `shipment.order_not_open` per ordini non impegnati (`COMMITTED` in `Lifecycle`) o con `fulfillment_type` diverso da `shipping`, `shipment.nothing_to_ship` per una richiesta vuota o fatta di sole righe a zero, `shipment.over_quantity` se una riga supera il residuo (o non è di quell'ordine, o non è spedibile).

- [ ] **Step 1: scrivere i test.** `ShippableLinesTest` (senza database): un prodotto intero → residuo = quantità; metà già spedita → metà; già tutto spedito → 0, mai negativo; servizio (`requires_shipping` falso), righe `shipping`/`fee`/`discount` → assenti; una confezione: la madre assente, le figlie presenti; quantità frazionarie. `ShipmentsCreateTest` (con `prova()`, `compra.php`, `accendiFunzionalita('shipping')`): spedizione completa crea `pending` con righe e una riga di storico; spedizione parziale lascia il residuo giusto; la seconda sulla stessa riga oltre il residuo → `shipment.over_quantity` e **nessuna riga scritta**; riga di un altro ordine → rifiuto; riga di un servizio → rifiuto; richiesta vuota → `nothing_to_ship`; ordine annullato o in bozza → `order_not_open`; ordine con ritiro → rifiuto; funzionalità spenta → `feature_off`; il blocco `FOR UPDATE` si prova con due connessioni PDO distinte (il test apre una seconda connessione, crea la prima spedizione senza chiudere la transazione e verifica che la seconda attenda/fallisca; se l'harness non permette due connessioni, si prova che la query di blocco è eseguita leggendo il log delle query della transazione — dichiararlo con un commento).
- [ ] **Step 2: eseguire** i due file — atteso: rossi.
- [ ] **Step 3: implementare** le due classi e le frasi.
- [ ] **Step 4: eseguire** i due file, `php tests/ErrorKeysTest.php`, `php tests/run.php` — atteso: verde.
- [ ] **Step 5: commit** «Spedizioni: creazione e quantità assegnabili».

### Task 3: Spedire, consegnare, annullare — e l'evasione dell'ordine

**Files:**
- Modify: `src/Support/Shipping/Shipments.php`, `src/Support/Orders/Lifecycle.php` (solo un punto d'ingresso, vedi sotto)
- Create: `src/Support/Shipping/ShipmentFlow.php` (le transizioni, pura), `tests/ShipmentFlowTest.php`, `tests/integrazione/ShipmentsFlowTest.php`
- Modify: `lang/it/gestionale.json` (`shipment.bad_transition`, `shipment.tracking_required`)

**Interfaces:**
- Produces: `ShipmentFlow::allowed(string $type, string $from): list<string>` pura (le transizioni delle Global Constraints) e `ShipmentFlow::fulfillment(string $current, array $lines): string` pura: dato lo stato di evasione attuale di un ordine e, per le sue righe spedibili, quantità ordinata e quantità in spedizioni contabili (`in_transit`+, non `returned`/`cancelled`, e ritiri `picked_up`), più la presenza di un ritiro `ready_for_pickup`, dà `unfulfilled | ready_for_pickup | partially_fulfilled | fulfilled`.
- Produces: `Shipments::ship(int $shipmentId, array $data): void` (`$data`: `carrier_id`, `tracking_number`, `shipped_at` — `Y-m-d`, default oggi; con un vettore che ha un modello di link il tracking è obbligatorio, `shipment.tracking_required`; calcola e conserva `tracking_url` con `Carriers::trackingUrl`; porta a `in_transit`); `Shipments::advance(int $shipmentId, string $to, array $options = []): void` (qualunque passaggio intermedio consentito, `delivered` scrive `delivered_at`); `Shipments::cancel(int $shipmentId, array $options = []): void` (da `pending`/`label_created`). Tutte: dentro `Transaction::run`, righe di storico `shipment_status_logs`, stato uguale all'attuale = nessuna scrittura, passaggio non consentito → `shipment.bad_transition`; dopo ogni scrittura `Shipments::syncOrder(int $orderId): void` ricalcola l'evasione con `ShipmentFlow::fulfillment` e chiama `Lifecycle::fulfill` **solo se lo stato cambia** (così la chiusura automatica e lo storico ordine restano quelli di sempre).
- In `Lifecycle`: nessun cambio di logica; `fulfill` è già l'unica porta. Solo il commento della classe dice che con `shipping` accesa è `Shipments` a chiamarla.

- [ ] **Step 1: scrivere i test.** `ShipmentFlowTest` (senza database): transizioni ammesse e non (saltare passi ammesso solo in avanti, `cancelled` solo da `pending`/`label_created`, niente ritorni, `returned` da `in_transit`/`out_for_delivery`); `fulfillment`: nessuna riga in viaggio → `unfulfilled`; 2 su 3 → `partially_fulfilled`; 3 su 3 → `fulfilled`; spedizione `pending` non conta; `returned` e `cancelled` non contano; ritiro `ready_for_pickup` → `ready_for_pickup`; ritiro `picked_up` → `fulfilled`. `ShipmentsFlowTest` (con `prova()`): `ship` con vettore e tracking → `in_transit`, `tracking_url` costruito, `shipped_at`, ordine `partially_fulfilled`; `ship` della seconda → ordine `fulfilled` e, con ordine pagato, `completed` da `refresh`; `cancel` della prima torna a `partially_fulfilled`; vettore con modello di link e tracking vuoto → `tracking_required`; vettore senza modello e tracking vuoto → ammesso; `returned` libera le quantità e rimette l'ordine in `unfulfilled`/`partially_fulfilled`; due `ship` di fila sulla stessa spedizione → una sola riga di storico; ordine annullato dopo la creazione → `ship` rifiutata; funzionalità spenta → `feature_off`; lo storico dell'ordine riceve il passaggio con `source` coerente.
- [ ] **Step 2: eseguire** i due file — atteso: rossi.
- [ ] **Step 3: implementare** `ShipmentFlow`, i metodi di `Shipments`, il commento in `Lifecycle`.
- [ ] **Step 4: eseguire** i due file, `LifecycleTest`, `OrderActionsTest`, `php tests/ErrorKeysTest.php`, poi `php tests/run.php` — atteso: verde.
- [ ] **Step 5: commit** «Spedizioni: stati, tracking e evasione dell'ordine».

### Task 4: Ritiro in sede

**Files:**
- Modify: `src/Support/Shipping/Shipments.php` (`createPickup`, `ready`, `pickedUp`), `src/Support/Orders/Cart.php` o `Checkout.php` solo se oggi il ritiro accetta sedi non di ritiro (verificarlo con un test prima di toccarlo)
- Create: `tests/integrazione/ShipmentsPickupTest.php`
- Modify: `lang/it/gestionale.json` (`shipment.not_pickup_point`, `shipment.location_closed`, `shipment.pickup_exists`)

**Interfaces:**
- Consumes: `SocietyLocations::isOpen`, `Location::is_pickup_point`, `Shipments::advance`, `ShipmentFlow::allowed('pickup', …)`.
- Produces: `Shipments::createPickup(int $orderId, array $options = []): int` (ordine con `fulfillment_type = pickup` e `location_id`; una sola spedizione di ritiro viva per ordine, `shipment.pickup_exists`; sede con `is_pickup_point` e aperta, altrimenti `shipment.not_pickup_point` / `shipment.location_closed`; righe = tutte quelle spedibili col residuo; stato `pending`); `Shipments::ready(int $shipmentId, array $options = []): void` (`pending → ready_for_pickup`; ordine `ready_for_pickup`; manda l'email del task 5); `Shipments::pickedUp(int $shipmentId, array $options = []): void` (`ready_for_pickup → picked_up`, ordine `fulfilled`). Annullare dal `pending`/`ready_for_pickup` libera tutto e riporta l'ordine a `unfulfilled`.

- [ ] **Step 1: scrivere i test** (con `prova()`): ritiro su sede non di ritiro → rifiuto; sede chiusa → rifiuto (usare gli orari di una sede di prova); due ritiri sullo stesso ordine → `pickup_exists`; `ready` poi `pickedUp` → ordine `ready_for_pickup` poi `fulfilled`, e con ordine pagato `completed`; `pickedUp` senza `ready` → `bad_transition`; doppio `ready` → una sola riga di storico; ritiro su ordine con consegna → rifiuto; dopo un ritiro l'ordine **non ha mai** una riga `shipping` (verifica sul carrello del piano 1); contrassegno al ritiro non porta il `cod_fee`.
- [ ] **Step 2: eseguire** — atteso: rosso.
- [ ] **Step 3: implementare** i tre metodi (`ready` chiama una funzione di invio che nel task 5 diventa l'email; qui si lascia il punto d'innesto con un commento e senza invio: il task 5 lo collega e il suo test lo prova).
- [ ] **Step 4: eseguire** il test e `php tests/run.php` — atteso: verde.
- [ ] **Step 5: commit** «Spedizioni: il ritiro in sede».

### Task 5: Le email — spedito e pronto per il ritiro

**Files:**
- Modify: `src/Support/Orders/OrderEmail.php` (`KEYS` + `shipped`, `ready_for_pickup`; nuovi segnaposto `:tracking`, `:carrier`, `:url`, `:location`), `src/Support/Orders/OrderNotifier.php` (nessun cambio di forma: `send($key, $orderId, $extra)`), `src/Support/Shipping/Shipments.php` (collega l'invio in `ship` e `ready`), le viste delle email se il tema le tiene separate (verificare in `OrderEmail::CUSTOMER_VIEW`), `lang/it/gestionale.json` (oggetto, titolo, testi), `tests/OrderEmailTest.php` (esistente, si estende), `tests/integrazione/ShipmentEmailTest.php` (nuovo)

**Interfaces:**
- Consumes: `Carriers::trackingUrl`, `Shipment.tracking_url`, `OrderNotifier::send`.
- Produces: `OrderEmail::KEYS` con `shipped` e `ready_for_pickup` (non sono `MERCHANT_KEYS`); `compose('shipped', …, ['tracking' => …, 'carrier' => …, 'url' => …])` con il link solo se c'è; `compose('ready_for_pickup', …, ['location' => nome e indirizzo della sede])`. L'invio dalle operazioni di `Shipments` è fuori dalla transazione (dopo il commit), non lancia mai: un fallimento si registra nello storico dell'ordine come già fa `OrderNotifier`.

- [ ] **Step 1: scrivere i test.** `OrderEmailTest` esteso (senza database): `compose('shipped')` con tracking, vettore e link → il corpo contiene il link; senza link → niente anchor e niente «:url» rimasto; tracking con `<script>` e virgolette → escapato; `compose('ready_for_pickup')` con la sede nel corpo; chiavi sconosciute ricadono su `received` come oggi. `ShipmentEmailTest` (con `prova()` e il trasporto di prova usato da `OrderNotifierTest`): `ship` manda un'email `shipped` al cliente; `ready` manda `ready_for_pickup`; cliente senza email → `no_recipients`, l'operazione riesce lo stesso; due `ship` ripetute → una sola email; email non inviabile → la spedizione resta `in_transit` e lo storico lo dice.
- [ ] **Step 2: eseguire** — atteso: rossi.
- [ ] **Step 3: implementare** e collegare l'invio.
- [ ] **Step 4: eseguire** i due file, `php tests/ErrorKeysTest.php`, `php tests/run.php` — atteso: verde.
- [ ] **Step 5: commit** «Spedizioni: le email di spedito e pronto per il ritiro».

### Task 6: Le pagine — spedire dall'ordine, elenco spedizioni, widget

**Files:**
- Create: `src/Resources/Shipping/ShipmentResource.php` (elenco e scheda spedizione), `src/Backend/Widgets/ShipmentsToCheckWidget.php`, `src/Backend/Widgets/ShippedNotDeliveredWidget.php`, `tests/ShipmentResourcesTest.php`, `tests/integrazione/ShipmentBackendTest.php`
- Modify: `src/Resources/Sales/OrderActionResource.php` (le azioni «Crea spedizione», «Segna spedita», «Segna consegnata», «Annulla spedizione», «Pronto per il ritiro», «Ritirato»; «Segna evaso» resta solo con `shipping` spenta), `src/Support/Orders/OrderActions.php` (disponibilità), la scheda dell'ordine (blocco *Spedizioni* con elenco e stato), `src/Backend/Widgets` registrati dove si registrano gli altri, `lang/it/gestionale.json`

**Interfaces:**
- Consumes: tutto `Shipments`, `OrderActions`.
- Produces: `ShipmentResource` con `$feature = 'shipping'`, elenco filtrabile per stato e vettore (tabella del core, `TableColumn`, come l'anteprima campagne), scheda con righe, storico e tracking cliccabile (`escape()` + `rel="noopener"`); in `OrderActions` le nuove azioni con la stessa forma di `FULFILL` (disponibili per ordini impegnati con `shipping` accesa); in `OrderActionResource` il modulo «Crea spedizione» con le righe e le quantità (precompilate col residuo), vettore e tracking facoltativi, e il pulsante «Segna spedita» che chiama `ship`; widget 1: spedizioni in `exception` o `failed_attempt` (conteggio e link all'elenco filtrato); widget 2: spedite da più di 7 giorni e non consegnate (stesso schema, sul modello di `AttentionWidget`); entrambi spariscono con la funzionalità spenta e col conteggio a zero.

- [ ] **Step 1: scrivere i test.** `ShipmentResourcesTest` (senza database): campi e voce di menu, le pagine spariscono con `shipping` spenta, le azioni nuove compaiono solo con `shipping` accesa e «Segna evaso» solo con `shipping` spenta (via `OrderActions`). `ShipmentBackendTest` (con `prova()`): «Crea spedizione» dal modulo scrive spedizione e righe; quantità oltre il residuo → `UserError` e nulla scritto; riga di un altro ordine passata a mano → rifiuto; «Segna spedita» con tracking e vettore; l'azione ripetuta non duplica; i due widget contano i casi giusti (anche con data limite esatta: 7 giorni non basta, oltre sì) e restituiscono vuoto a zero; il testo di un tracking con HTML esce escapato nella scheda.
- [ ] **Step 2: eseguire** i due file — atteso: rossi.
- [ ] **Step 3: implementare** Resource, azioni e widget.
- [ ] **Step 4: eseguire** i due file, `OrderActionsTest`, `OrderActionResourceTest`, poi `php tests/run.php` — atteso: verde.
- [ ] **Step 5: commit** «Spedizioni: pagine, azioni dall'ordine e widget».

### Task 7: Reso da `shipped_at`, dati di prova, guide, chiusura

**Files:**
- Modify: il punto in cui `Returns` calcola il termine del reso (cercare con `grep -n "return_window\|giorni" src/Support/Returns/*.php src/Models/System/Setting.php`; se oggi parte da `completed_at`, usare `shipped_at` della prima spedizione spedita quando c'è e ripiegare sull'attuale), `src/Seeding/Demo.php` (registra `ShipmentsDemo` **dopo** `OrdersDemo`: `ShippingDemo` del piano 1 gira prima, i listini non hanno bisogno degli ordini), `docs/user/spedizioni-spedire-e-ritiro.md`, `docs/dev/concetti/spedizioni.md` (sezione spedizioni e ritiro), `docs/user/SUMMARY.md`, `docs/dev/SUMMARY.md`, `gitbook-docs.yaml` e `tests/DocsPagesTest.php` se elencano le pagine, `CHANGELOG.md`, `TODO.md`
- Create: `src/Seeding/ShipmentsDemo.php` (le spedizioni di prova sugli ordini di `OrdersDemo`: una consegnata, una in viaggio, una parziale, un ritiro pronto), `tests/integrazione/ShipmentReturnTermTest.php`, `tests/integrazione/ShipmentsDemoTest.php`

- [ ] **Step 1: scrivere i test.** Termine di reso: con una spedizione spedita il termine parte da `shipped_at`; senza spedizioni (o con `shipping` spenta) resta quello di oggi; spedizione `cancelled` o `pending` non lo sposta. Demo: dopo `register()` le spedizioni di prova esistono negli stati attesi, l'evasione degli ordini coincide con quella ricavata, `--clear` toglie tutto senza orfani, due `register()` non duplicano.
- [ ] **Step 2: eseguire** — atteso: rossi.
- [ ] **Step 3: implementare** il termine di reso, le spedizioni di prova, le guide (utente: spedire un ordine, spedizioni parziali, tracking, ritiro e i tre passaggi, cosa fanno i due widget, perché «Segna evaso» non c'è più con le spedizioni accese; sviluppatori: `Shipments`, il flusso degli stati, `ShipmentFlow::fulfillment`, il blocco `FOR UPDATE`, come E1c deve leggere stato e tracking), il `CHANGELOG.md` con l'avvertenza **«lanciare `php forge update`»**, e in `TODO.md` G7 → chiuso (piani 1 e 2).
- [ ] **Step 4: eseguire** `php tests/run.php` per intero — atteso: verde (rossi noti a parte); lanciare `php forge gestionale:demo` e guardare l'elenco spedizioni.
- [ ] **Step 5: commit** «Spedizioni: reso da shipped_at, dati di prova, guide e chiusura del piano 2».

### Task 8: Prova nel browser (serve il tuo accesso)

Con il sito di prova avviato e l'utente collegato al backend: (1) ordine con consegna: «Crea spedizione» parziale, «Segna spedita» con tracking → ordine `partially_fulfilled`, link di tracking cliccabile, email nel registro; (2) la seconda spedizione → ordine `fulfilled` (e chiuso se pagato); (3) annullare una `pending`; (4) ordine con ritiro: «Pronto per il ritiro» (email) poi «Ritirato»; (5) i due widget con una spedizione in `exception` e una vecchia non consegnata; (6) spegnere `shipping`: pulsanti e widget spariscono, «Segna evaso» torna. Riportare cosa si è visto; niente merge senza il via dell'utente.
