# G4 — Ordini e pagamenti

- **Sotto-progetto:** G4, primo del percorso di consegna (D61)
- **Stato:** approvata il 2026-09-29 (sezioni 1 e 2, poi 3, 4 e 5); cinque piani
- **Documento di riferimento:** [architettura](2026-09-11-gestionale-ecommerce-architettura-design.md)
  §4.1, §4.3, §4.5, §4.7, §4.8, §4.10, §5.1, §5.2, §5.6, §10.3 (D61)
- **Dipende da:** G1 (funzionalità, numerazione, log degli stati, codici, errori),
  G2 (catalogo, IVA, anagrafiche, sedi), G2b (giacenze, movimenti, prenotazioni,
  `Stock::apply()`), G2b-bis (movimenti leggibili)

## Contesto

G4 è il motore di vendita: fin qui il gestionale sa cosa c'è in catalogo e
quanti pezzi ci sono in magazzino, ma niente esce mai da lì per essere venduto.
`gst_stock_reservations` è una tabella vuota che nessuno scrive, `Availability`
sottrae prenotazioni che nessuno crea, `TaxTotals` calcola riepiloghi IVA che
nessuno salva, e `StockMovement` ha i tipi `sale`, `sale_cancel` e `return`
dichiarati e mai usati. G4 chiude tutti questi fili.

Con D61 il percorso della consegna è G4 → G5 → G6 ridotto → G7 → E1, e G3 esce
dal nucleo: quando G4 tocca il magazzino, più sedi, lotti e fornitori sono
spenti, `gst_stock.batch_id` e `gst_stock.supplier_id` restano a zero e la sede
è sempre quella predefinita. Perché G3 possa innestarsi senza riaprire gli
ordini, **tutto ciò che in G4 tocca il magazzino per una vendita passa da un
solo servizio**, che riceve già sede, lotto e fornitore: oggi li riempie con i
valori predefiniti, domani li sceglierà G3.

Il primo ordine vero arriverà dal checkout di E1. In G4 non si crea un ordine
dal backend: creare ordini da ufficio è G9, e anticiparlo vorrebbe dire
costruire una pagina di composizione delle righe — ricerca prodotti, prezzi
modificabili a mano, ricalcolo dei totali — che il negozio della consegna non
usa. Quello che serve al commerciante in G4 è **vedere** gli ordini che
arrivano dal sito e **agirci sopra**: confermare, annullare, segnare l'evasione,
registrare un bonifico, registrare un reso.

Quello che G4 costruisce serve subito anche al modulo `ecommerce`: E1 sarà fatto
di pagine — vetrina, carrello, checkout, area cliente — appoggiate ai servizi di
questa spec, senza logica di vendita propria.

## Decisioni prese nel brainstorming

| Tema | Scelta | Alternative scartate |
|---|---|---|
| Perimetro | **Solo il motore**: tabelle, servizi, stati, magazzino, pagamenti, resi, più le pagine di consultazione e le azioni | motore e creazione dell'ordine da ufficio insieme |
| Creazione dell'ordine | **Nessuna interfaccia di creazione**: gli ordini nascono dai servizi, il primo vero dal checkout di E1; il `DemoCommand` ne crea di finti per provare il backend | pagina di composizione delle righe in G4 (è G9) |
| Azioni dal backend | **Cambiare stato, annullare, registrare un pagamento a mano, segnare l'evasione, registrare un reso**; le righe dell'ordine **non si modificano** | modifica delle righe dopo l'invio (G9) |
| Resi | **Reso ridotto**: tabelle complete, registrazione dal dettaglio dell'ordine con righe, motivo e ricarico, stati `received` → `completed` e `cancelled` | flusso completo con richiesta online e approvazione (resta per E2/G9) |
| Rimborsi | **Fuori dal gestionale**: si fanno sul gateway; il gestionale registra la riga `refund` e aggiorna il `payment_status` | rimborso comandato dal pannello (funzionalità futura, architettura §5.2) |
| Confine con E1 | **Approccio A**: motore completo nel gestionale, E1 solo pagine | logica di vendita divisa tra i due pacchetti |
| Magazzino delle vendite | **Un solo servizio `Stock\Allocation`**, che riceve già sede, lotto e fornitore (D61) | ogni flusso che scrive movimenti per conto proprio |
| Colonne future | **Tutte le colonne di §4.7 e §4.8 nascono adesso**, anche quelle che nessuno scrive (listino, coupon, metodo di spedizione, condizione di pagamento, campi del preventivo), come già fatto con `batch_id` e `supplier_id` | aggiungerle sotto-progetto per sotto-progetto, con una migrazione su tabelle piene |
| Preventivi | **Fuori**: la colonna `stage` nasce con i tre valori e le colonne `quote_*` esistono, ma la fase `quote` resta spenta con `quotes` (G9) | tenere fuori anche le colonne |
| Listini, coupon, spedizioni | **Fuori** (G6, G7); le righe `shipping` e `fee` esistono da subito come tipi di riga, perché entrano nei riepiloghi IVA | rimandare anche i tipi di riga |
| Riquadro in home | **Nessuno in G4**: lo aggiunge E1 quando gli ordini arrivano davvero | riquadro degli ordini del giorno subito |

## Design

### 1. Confini, unità e funzionalità (approvata)

**Due classi pure e sette servizi.** Le classi pure non toccano il database:
prendono numeri e restituiscono numeri, e sono quelle su cui i test dicono
davvero qualcosa. I servizi scrivono, e ognuno è l'unica porta della sua area.

| Unità | Cosa fa | Tocca |
|---|---|---|
| `Support\Pricing\LinePrice` | il prezzo di **una riga**: quale prezzo vince, lo sconto della riga, i sovrapprezzi | niente |
| `Support\Pricing\OrderTotals` | dalle righe ai **totali dell'ordine**: sconto sul totale ripartito, imponibile, imposta, totale | `Tax\TaxTotals` |
| `Support\Orders\Cart` | righe del carrello: aggiungi, cambia quantità, togli, ricalcola, unisci | `LinePrice`, `OrderTotals`, `Stock\Availability` |
| `Support\Orders\Checkout` | da carrello a ordine: copia i dati, prenota, numera, apre il pagamento | `Cart`, `Allocation`, `Ledger`, `DocumentSequences` |
| `Support\Orders\Lifecycle` | conferma, annulla, evasione, ricalcolo degli stati | `Allocation`, `Ledger`, `StatusLogger`, `Mailer` |
| `Support\Stock\Allocation` | **l'unico punto** che tocca il magazzino per una vendita | `Stock::apply()`, `StockReservation` |
| `Support\Payments\Ledger` | righe di pagamento e rimborso, `payment_status` dell'ordine | `StatusLogger` |
| `Support\Returns\Returns` | registra un reso e fa rientrare la merce | `Allocation`, `StatusLogger` |
| `Support\Orders\Expiry` | prenotazioni scadute, promemoria, annullamento automatico | `Lifecycle`, `Allocation`, `Mailer` |

**`LinePrice::of(array $input): array`.** Riceve il prodotto, la quantità, il
contesto fiscale e lo sconto scritto a mano; restituisce `list_price`,
`unit_price`, `price_source`, `discount_type`, `discount_value`,
`customization_surcharge` e `line_total`. La priorità dei prezzi è quella di
§4.5 e §4.6: **listino → campagna → prezzo scontato → prezzo base**, e la
sorgente che ha vinto resta scritta in `price_source` (`price_list`,
`campaign`, `sale_price`, `base`, `manual`). Listino e campagna in G4 non
esistono ancora: la classe li accetta come parametri vuoti e li salta, e G6 si
limiterà a passarglieli. Lo sconto manuale della riga si applica dopo, e
`price_source` diventa `manual`. I sovrapprezzi della personalizzazione (G5)
arrivano come voce a parte e si sommano al prezzo unitario: la colonna esiste,
in G4 vale sempre zero.

**`OrderTotals::of(array $lines, array $context): array`.** Ripartisce lo sconto
sul totale (coupon o sconto manuale della testata) **in proporzione** sulle
righe a cui si applica, scrivendo `order_discount_amount` riga per riga, così
che i riepiloghi IVA restino corretti; l'ultimo centesimo di resto va alla riga
più alta, perché la somma delle righe deve fare esattamente il totale.
Restituisce `products_total`, `discount_total`, `shipping_total`, `fees_total`,
`taxable_total`, `tax_total`, `total`, `total_weight` e i riepiloghi per
aliquota, che vengono da `TaxTotals::summaries()` senza riscriverne la logica.
`prices_include_tax` arriva dal contesto: è la stessa impostazione che il
catalogo già usa.

**`Stock\Allocation`.** Cinque metodi, tutti con sede, lotto e fornitore nella
firma: `reserve()` scrive le righe di `gst_stock_reservations` con la scadenza;
`release()` le chiude con `released_at`; `commit()` trasforma la prenotazione in
un movimento `sale` (scarica davvero); `restore()` fa rientrare la merce di un
ordine confermato con un movimento `sale_cancel`; `returnGoods()` la fa
rientrare da un reso con un movimento `return`. Ogni movimento porta
`reference_type` = `order` o `sales_return` e l'id del documento, così i
*Movimenti* di G2b-bis mostrano già il numero dell'ordine. Con una prenotazione valida `commit()` scarica sempre: quella merce era già
impegnata per questo ordine. Quando la prenotazione non c'è più — è scaduta e
la merce l'ha presa qualcun altro — decide `backorders`: accesa, la giacenza va
sotto zero e la vendita è «su ordinazione»; spenta, se il pagamento è già
riuscito si scarica comunque sotto zero e parte l'avviso al commerciante — un
ordine pagato e invisibile in magazzino è peggio di una giacenza negativa —
mentre se il pagamento non è riuscito `commit()` rifiuta con un `UserError` e
l'ordine resta in attesa. Oggi sede è quella predefinita, lotto e fornitore
sono zero: G3 cambierà solo il **dentro** di questi metodi.

**Funzionalità.** `orders` accende tutto; `returns` (già dichiarata, `requires`
`orders`) accende i resi; `backorders` permette la vendita a magazzino vuoto.
La fase `cart` in G4 vive sotto `orders`: la funzionalità `online_store` nasce
con E1, che la aggiunge a `config/features.php`.

### 2. Tabelle (approvata)

Otto tabelle nuove più tre log degli stati, tutte con le regole di §4.1: `code`
con prefisso da `Support\Codes`, decimali dichiarati con `Support\Columns`,
`syncSchema()` nullo per i documenti e `SyncSchema` per la configurazione.

| Tabella | Contenuto |
|---|---|
| `gst_orders` | testata: documento, stati, cliente, consegna, pagamento, sconti, totali, note, carrello, `custom_data` |
| `gst_order_items` | righe: riferimenti, copia del prodotto, prezzo, IVA, personalizzazione, totale |
| `gst_order_tax_summaries` | imponibile e imposta per aliquota, come li produce `TaxTotals` |
| `gst_payments` | ogni movimento di denaro, rimborsi compresi |
| `gst_payment_methods` | metodi di pagamento (configurazione) |
| `gst_payment_accounts` | conti e IBAN (configurazione) |
| `gst_sales_returns` | testata del reso |
| `gst_sales_return_items` | righe del reso |
| `gst_order_status_logs`, `gst_payment_status_logs`, `gst_sales_return_status_logs` | log degli stati su `StatusLog` |

**`gst_orders`** ha le colonne di §4.7, tutte, anche quelle che in G4 nessuno
scrive:

- *Documento:* `code` (`ord_`), `stage` (`cart`, `quote`, `order`), `channel`
  (`office`, `online`, `b2b_portal`, `pos`), `quote_number`, `quote_revision`,
  `quote_sent_at`, `quote_expires_at`, `order_number`, `ordered_at`,
  `completed_at`, `cancelled_at`, `user_id`.
- *Stati:* `status` con un solo enum per tutti i valori di §4.7 (`draft`,
  `sent`, `rejected`, `expired` del preventivo; `pending`, `confirmed`,
  `processing`, `completed`, `cancelled` dell'ordine), `payment_status`,
  `fulfillment_status`.
- *Cliente:* `customer_id` (verso `gst_contacts`), `email`, `phone`, i campi
  dell'indirizzo di fatturazione da `AddressExtension::billing('billing_',
  countryDefault: 'IT')->withLink(false)` — gli stessi della scheda cliente — e
  quelli di consegna da `AddressExtension::simple('shipping_', countryDefault:
  'IT')->withLink(false)->withContactName()->withPhone()`, che non ha dati
  fiscali né aziendali; `price_list_id`, `prices_include_tax`.
- *Consegna:* `fulfillment_type` (`shipping`, `pickup`, `none`), `location_id`
  (verso `gst_locations`), `shipping_method_id`.
- *Pagamento:* `payment_method_id` (verso `gst_payment_methods`),
  `payment_term_id`.
- *Sconti sul totale:* `coupon_id`, `coupon_code`, `manual_discount_type`
  (`none`, `amount`, `percent`), `manual_discount_value`.
- *Totali:* `currency`, `products_total`, `discount_total`, `shipping_total`,
  `fees_total`, `taxable_total`, `tax_total`, `total`, `total_weight`.
- *Note:* `customer_note`, `internal_note`, `document_note`.
- *Carrello:* `cart_token`, `last_activity_at`.
- *Sito:* `custom_data`.

La **chiave esterna sta solo sulle colonne sempre valorizzate**, in tutte le
tabelle di G4: MySQL rifiuta uno zero che non punta a niente. Restano quindi
interi con predefinito zero, senza chiave, sia le colonne che puntano a tabelle
non ancora nate — `price_list_id`, `coupon_id`, `shipping_method_id`,
`payment_term_id` —, come `batch_id` e `supplier_id` in `gst_stock`, sia quelle
che possono restare vuote su una tabella che esiste già: il carrello di un
ospite non ha `customer_id`, un carrello non ha ancora scelto `location_id` né
`payment_method_id`, una riga `shipping` non ha `product_id`, il pagamento di
un abbonamento non ha `order_id`. La chiave la aggiungerà il sotto-progetto che
crea la tabella, se e quando quella colonna diventerà obbligatoria. Nel nucleo
di G4 la chiave resta su `order_items.order_id`,
`order_tax_summaries.order_id`, `sales_returns.order_id`,
`sales_returns.location_id`, `sales_return_items.sales_return_id`,
`sales_return_items.order_item_id` e sulla colonna dell'entità dei tre log.
Indici su `order_number`, `cart_token`, `customer_id`, `status` e
`last_activity_at`.

**`gst_order_items`** ha le colonne di §4.7: `order_id`, `type` (`product`,
`custom`, `text`, `shipping`, `fee`), `product_id`, `parent_item_id`,
`position`; `sku`, `name`, `description`, `unit`; `quantity`, `list_price`,
`unit_price`, `price_source`, `discount_type`, `discount_value`,
`order_discount_amount`, `price_list_id`, `discount_campaign_id`;
`tax_category_id`, `tax_id`, `tax_rate`, `tax_nature`; `customization` (JSON),
`customization_surcharge`; `line_total`. Nome, SKU e descrizione sono **copiati**
al momento dell'aggiunta: un ordine di marzo deve continuare a dire cosa è stato
venduto anche se il prodotto oggi si chiama diversamente o non esiste più. Per
la stessa ragione `product_id` non ha `ON DELETE CASCADE`: un prodotto venduto
non si elimina, lo impedisce già il controllo dei movimenti di G2b.

**`gst_payments`**: `code` (`pay_`), `type` (`payment`, `refund`), `order_id`,
`subscription_id`, `customer_id`, `payment_method_id`, `payment_account_id`,
`amount`, `currency`, `status` (`pending`, `paid`, `failed`, `cancelled`),
`provider` (`stripe`, `paypal`, `nexi`, `manual`), `provider_reference`,
`due_date`, `paid_at`, `note`. `provider_reference` ha un indice **unico
insieme a `provider`**: è la difesa contro la notifica doppia del gateway
(§3). `subscription_id` è un intero a zero, come le altre colonne in anticipo.

**`gst_payment_methods`** e **`gst_payment_accounts`** sono configurazione di
`admin` con `SyncSchema`, con le colonne di §4.8. I metodi hanno `code`
parlante (`bank-transfer`, `card`, `cash-on-delivery`, `pickup-payment`),
`name`, `sdi_code`, `provider`, `payment_account_id`, `fee_type`, `fee_value`,
`available_for`, `applies_online`, `applies_office`, `applies_pos`,
`instructions`, `stripe_payment_method_types`, `active`, `position`. In G4 il
costo aggiuntivo (`fee_type`, `fee_value`) **viene applicato**: genera la riga
`fee` dell'ordine con l'aliquota di spedizione e commissioni di §4.5; il
`cod_fee` del listino di spedizione, che per il contrassegno prevale su questo
(§4.11), arriva con G7. La riga `shipping` esiste invece solo come tipo: finché
non c'è G7 nessuno la crea e `shipping_total` resta a zero. Le condizioni a
rate (`payment_terms`, `payment_term_installments`) restano a G9.

**`gst_sales_returns`** e **`gst_sales_return_items`** hanno le colonne di
§4.10. Gli stati usati in G4 sono `received`, `completed` e `cancelled`;
`requested`, `approved` e `rejected` esistono nell'enum e li accenderà il reso
online.

**Numerazione.** `order_number` dalla sequenza `order` e `number` del reso
dalla sequenza `sales_return`, entrambe con `DocumentSequences::next()`, che
già prende la riga con `FOR UPDATE` e formatta `{YYYY}/{mm}{nnnn}`. Il numero
dell'ordine si assegna **all'invio** (§5.2): il cliente lo usa subito come
causale del bonifico, e i buchi degli ordini annullati non contano.

**Impostazioni.** Due colonne nuove su `Setting` (tecniche, di `admin`):
`order_reservation_minutes` (predefinito 30) e `order_payment_wait_days`
(predefinito 7). Le email al commerciante usano `merchant_notification_emails`,
che esiste già su `MerchantSetting`.

### 3. Flussi (approvata)

**Carrello.** Una riga di `gst_orders` con `stage` = `cart`, riconosciuta dal
`cart_token` per chi non ha un account o dal `customer_id` per chi ce l'ha;
ogni operazione aggiorna `last_activity_at`. Il carrello **non prenota niente**:
prenotare a ogni aggiunta vorrebbe dire bloccare la merce per chi guarda e
basta. Quando si aggiunge una riga si controlla la disponibilità
(`Availability::of()`) e, con `backorders` spento, si rifiuta la quantità che
non c'è con un `UserError` che dice quanti pezzi restano. Ogni modifica
ricalcola prezzi e totali: il carrello di ieri non tiene il prezzo di ieri.
`Cart::merge()` unisce il carrello da ospite a quello dell'account quando il
cliente accede durante l'acquisto, sommando le quantità delle righe uguali.

**Checkout** (`Checkout::place()`). Una sola transazione, con
`sqlSelectForUpdate()` sulle righe di magazzino toccate:

1. copia sull'ordine i dati di fatturazione e consegna, il metodo di pagamento
   e, se il metodo ha un costo, aggiunge la riga `fee`;
2. ricalcola tutto da capo con `LinePrice` e `OrderTotals` — i prezzi validi
   sono quelli di adesso, non quelli che il sito ha in pagina;
3. prenota la merce con `Allocation::reserve()`, con la scadenza che dipende
   dal metodo di pagamento: `order_reservation_minutes` per i pagamenti
   immediati (carta, wallet), `order_payment_wait_days` per il bonifico,
   nessuna scadenza per i metodi che confermano subito (contrassegno,
   pagamento al ritiro), dove la prenotazione diventa scarico nello stesso
   momento;
4. assegna `order_number` e `ordered_at`, porta `stage` a `order` e `status` a
   `pending`;
5. apre con `Ledger::open()` una riga di `gst_payments` in attesa per l'importo
   totale;
6. salva i riepiloghi IVA in `gst_order_tax_summaries`.

Se qualcosa rifiuta — merce finita, indirizzo incompleto, metodo di pagamento
non valido per il tipo di consegna — la transazione torna indietro e il
carrello resta com'era.

**Conferma.** Arriva da una notifica del gateway (E2 per Stripe; in G4 il
servizio è pronto e il provider è `manual`) o dalla registrazione a mano di un
bonifico. `Lifecycle::confirm()` segna il pagamento come `paid`, porta l'ordine
a `confirmed`, trasforma le prenotazioni in movimenti `sale` con
`Allocation::commit()` e manda l'email di conferma. **È idempotente:** una
notifica che arriva due volte trova il pagamento già `paid` e non scarica il
magazzino una seconda volta; l'indice unico su `provider` +
`provider_reference` chiude la porta anche a due notifiche gemelle che arrivano
insieme. I metodi che si pagano dopo — contrassegno, pagamento al ritiro — sono
segnati sul metodo di pagamento e confermano l'ordine subito, con il pagamento
che resta in attesa (§5.2).

**Annullamento** (`Lifecycle::cancel()`). Prima della conferma libera le
prenotazioni; dopo la conferma fa rientrare la merce con `Allocation::restore()`
e movimenti `sale_cancel`. Porta `status` a `cancelled`, annulla i pagamenti
ancora in attesa e manda l'email al cliente. Se l'ordine era già pagato, il
rimborso si fa sul gateway: la pagina lo dice e il gestionale registrerà la riga
`refund` alla notifica.

**Chiusura.** `Lifecycle` porta l'ordine a `completed` da solo quando è
interamente pagato ed evaso, e scrive `completed_at`. `processing` resta
un'etichetta che l'operatore mette a mano quando vuole far vedere che ci sta
lavorando: in G4 nessun automatismo la usa.

**Evasione.** `fulfillment_status` si muove **a mano** e non tocca il magazzino:
la merce è già uscita alla conferma. In G4 il commerciante segna *evaso* quando
ha spedito; le spedizioni con tracking arrivano con G7, i DDT con G9.

**Pagamenti.** Ogni movimento di denaro è una riga. `Ledger::register()` segna
un pagamento riuscito, `Ledger::fail()` uno fallito, `Ledger::refund()` scrive
una riga `refund`. Dopo ogni scrittura il `payment_status` dell'ordine si
**ricalcola dalla somma delle righe** — mai scritto a mano — e il cambio
finisce nel log. Un pagamento parziale dà `partially_paid`, un rimborso
parziale `partially_refunded`.

**Resi** (`Returns::register()`). Si parte dal dettaglio dell'ordine, si
scelgono le righe e le quantità: il massimo è **quanto ordinato meno quanto già
reso**, e oltre si rifiuta con un `UserError`. Per ogni riga un motivo
(§4.10) e la spunta *rientra a magazzino*, proposta **spenta** per `damaged` e
`defective` — merce rotta non torna in vendita. Alla registrazione il reso
nasce `received`, la merce rientra con `Allocation::returnGoods()` e movimenti
`return` sulla sede che la riceve, e il reso si chiude a `completed` quando il
commerciante lo dice. Il rimborso al cliente resta sul gateway.

**Prenotazioni scadute e attesa del pagamento.** `Orders\Expiry` è un task dello
scheduler, accanto a `StockAlertsTask`, sotto `NamedLock` come gli altri: gira
ogni ora e fa tre cose. Libera le prenotazioni scadute degli ordini non
confermati: con un pagamento immediato l'ordine resta in attesa senza merce
impegnata e il cliente può riprovare dal link, e se poi paga vale la regola di
`commit()` (§1). A metà di `order_payment_wait_days` manda al cliente il
promemoria del pagamento. Alla scadenza dell'attesa annulla l'ordine, libera la
merce e avvisa cliente e commerciante. Tutto passa da `Lifecycle::cancel()`: il task non tocca il
magazzino per conto suo, e `source` dei log è `cron`.

**Email.** Con `Mailer` e `Recipients`, testi in `lang/`, template sovrascrivibili
come le pagine del modulo. In G4 sono quattro al cliente — ordine ricevuto (con
le istruzioni del metodo di pagamento, per esempio l'IBAN), ordine confermato,
promemoria di pagamento, ordine annullato — e due al commerciante: nuovo ordine
e ordine annullato per mancato pagamento. Spedizione, ritiro e fattura arrivano
con G7 e G8.

### 4. Backend: la sezione *Vendite* (approvata)

**Elenco *Ordini*.** Una Resource sul Model `Order`: numero, data, cliente,
totale e **tre etichette di stato** — ordine, pagamento, evasione — con i colori
del modulo. Filtri per stato, stato del pagamento, stato dell'evasione e
periodo; ricerca per numero, cliente ed email. I carrelli non compaiono:
l'elenco mostra `stage` = `order`.

**Dettaglio dell'ordine.** Una pagina di sola lettura, costruita come la scheda
prodotto — componenti del modulo dentro una Resource, non il form CRUD del core
— con: testata (numero, data, canale, cliente, indirizzi, metodo di pagamento,
tipo di consegna, note), righe con prezzo, sconto e IVA, riepilogo IVA per
aliquota, totali, pagamenti, resi e storia degli stati. Le righe non si
modificano: chi deve cambiare un ordine lo annulla e ne fa uno nuovo, finché
non arriva G9.

**Azioni**, nel menu della pagina. *Conferma*, *Annulla* e *Segna evaso* non
chiedono dati: aprono una conferma che **dice cosa succede al magazzino**
(«Conferma l'ordine e scarica 3 pezzi»; «Annulla l'ordine e rimette 3 pezzi in
magazzino»). *Registra pagamento* e *Registra reso* aprono una pagina-form
dedicata con il ritorno al dettaglio, come fa già la *Rettifica* con `torna=`:
la prima chiede importo, metodo, data e riferimento; la seconda le righe, le
quantità, il motivo e il ricarico. Le azioni compaiono solo quando hanno senso:
*Conferma* su un ordine in attesa, *Annulla* su un ordine non ancora annullato,
*Registra reso* solo con `returns` accesa e su un ordine confermato, evaso o
completato.

**Configurazione in *Set Up*.** *Metodi di pagamento* e *Conti di pagamento*,
due Resource CRUD normali, riservate ad `admin` e sincronizzate tra ambienti
come le aliquote. Nessun riquadro in home: lo aggiungerà E1.

### 5. Errori, test, documentazione e piani (approvata)

**Errori.** I rifiuti prevedibili — merce finita, quantità oltre il reso,
importo maggiore del dovuto, azione non ammessa in questo stato — sono
`UserError` con il messaggio in `lang/`, mostrati come frase. Tutto il resto va
a `ErrorReporter` e all'email agli sviluppatori. Le notifiche dei gateway si
registrano in `gst_provider_events` con `ProviderEvents`, riuscite o no: quando
un ordine non si conferma, la prima domanda è sempre cosa è arrivato davvero.

**Test**, nel runner del modulo (`php tests/run.php`, transazioni annullate).
Sulle classi pure: priorità dei prezzi con e senza listino, sconto di riga,
ripartizione dello sconto sul totale con il resto dell'ultimo centesimo,
riepiloghi IVA con prezzi IVA inclusa ed esclusa. Sui servizi: prenotazione e
scarico, **due processi che comprano l'ultimo pezzo** (uno vince, l'altro
riceve il rifiuto), notifica di pagamento doppia (un solo scarico), annullamento
prima e dopo la conferma, reso con e senza ricarico, giro dello scheduler con
prenotazione scaduta e ordine da annullare.

**Dati di prova.** `DemoCommand` impara a creare ordini nei vari stati — in
attesa di bonifico, confermato e scaricato, evaso, annullato, con un reso —
così il backend si prova senza un sito davanti.

**Documentazione.** Guide utente *Ordini*, *Pagamenti e scadenze* e *Resi*
nella sezione Vendite già prevista dall'indice; guida per sviluppatori sui
servizi di vendita, che è quella che E1 leggerà per costruire il checkout.

**Piani.**

1. **Tabelle e prezzi** — gli otto Model più i tre log, `LinePrice` e
   `OrderTotals` con i loro test, le due impostazioni, i prefissi dei codici.
2. **Magazzino delle vendite e pagamenti** — `Allocation` e `Ledger`, con i
   test di contemporaneità e di idempotenza.
3. **Carrello, checkout e ciclo di vita** — `Cart`, `Checkout`, `Lifecycle`,
   `Expiry` nello scheduler, le email.
4. **Backend *Vendite*** — elenco, dettaglio, azioni, metodi e conti in *Set
   Up*, ordini finti nel `DemoCommand`.
5. **Resi, guide e prova nel browser** — `Returns`, la pagina di registrazione,
   le tre guide utente e la guida per sviluppatori.
