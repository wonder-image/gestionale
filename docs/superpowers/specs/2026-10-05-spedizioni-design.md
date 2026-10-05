# G7 — Spedizioni

- **Sotto-progetto:** G7, quarto del percorso di consegna (D61), dopo G4, G5 e G6 ridotto
- **Stato:** disegno approvato il 2026-10-05 (calcolo completo, due piani)
- **Documento di riferimento:** [architettura](2026-09-11-gestionale-ecommerce-architettura-design.md)
  §4.11 (D41, D42), §4.3, §4.5, §4.10, §10.3; [ordini e pagamenti](2026-09-29-ordini-e-pagamenti-design.md)
  (`Cart`, `OrderTotals`, `Lifecycle`, `OrderNotifier`); [sconti e coupon](2026-10-05-sconti-e-coupon-design.md)
- **Dipende da:** G2 (peso e misure dell'articolo), G4 (carrello, checkout, ciclo di
  vita, email), G6 (coupon «spedizione gratuita»)

## Contesto

G4 ha preparato il posto e non l'ha riempito. `gst_order_items` ammette il tipo
`shipping` e `OrderTotals` lo somma in `shipping_total` (oggi sempre a zero) e lo
azzera con il coupon `free_shipping`; `gst_orders` ha `fulfillment_type`
(`shipping`, `pickup`, `none`), `shipping_method_id` e `fulfillment_status`;
`gst_settings` ha `shipping_tax_id`; le sedi hanno `is_pickup_point` e gli orari
e le chiusure stanno nel core (`SocietyLocations::isOpen()`). Il `cod_fee` del
listino, che per il contrassegno prevale sulla commissione del metodo di
pagamento, è rimasto a G7. La funzionalità `shipping` è già in
`config/features.php` (richiede `orders`, `release` G7).

G7 riempie i posti con **i listini di spedizione** (metodi, zone, tariffe a
scaglioni e il loro calcolo) e **le spedizioni** (creazione dall'ordine, tracking
a mano, ritiro in sede, evasione ricavata dalle righe spedite). Le regole sono
già scritte in §4.11 e qui non si ripetono: questa spec fissa solo ciò che §4.11
lascia aperto e i punti dove G7 si innesta nel codice di G4.

Come per G5 e G6, nessuno schermo del negozio sceglie la spedizione: il checkout
e l'area cliente sono E1c. G7 costruisce il motore che E1c userà, la gestione nel
backend e la lettura su ordini e spedizioni; i dati di prova fanno nascere ordini
con spedizione e ritiro per vedere tutto nel pannello.

## Decisioni prese nel brainstorming

| Tema | Scelta | Alternative scartate |
|---|---|---|
| Calcolo | **Completo come §4.11**: peso volumetrico, scaglioni, tariffa al kg oltre l'ultimo (`total_weight` o `excess_only`), supplemento carburante, margine ±, arrotondamento per eccesso, minimo, gratuita per importo e/o per peso | scaglioni di peso e soglia gratuita soltanto, il resto dopo |
| Piani | **Due: Listini e calcolo, poi Spedizioni.** Il calcolo si innesta nel carrello nel Piano 1 | un piano unico; tre piani |
| Prezzo a mano | Il prezzo scritto a mano sulla riga di spedizione vince su quello calcolato (`price_source`), come per le righe prodotto | ricalcolare sempre |
| Metodo non coperto | Un metodo senza tariffa attiva per la zona di destinazione **non compare** tra le opzioni; se il metodo scelto smette di coprire la destinazione la riga esce e il motivo arriva a chi chiama | mostrarlo a prezzo zero |
| Unità | Peso in kg, misure in cm (come nelle schede articolo) | unità per sito |
| Evasione | Con `shipping` accesa `fulfillment_status` si **ricava dalle quantità evase**; il pulsante manuale «Segna evaso» resta solo con `shipping` spenta | tenerlo sempre; un campo da scrivere a mano |
| Zone | Per **paese e provincia** (la più specifica vince). CAP, ritiro prenotabile, tariffe in tempo reale e corrieri collegati restano fuori, come in §4.11 | zone per CAP |

## Design

### 1. Unità e flusso

Due classi pure e tre servizi, in `src/Support/Shipping/`. Le classi pure non
toccano il database: prendono numeri e restituiscono numeri, e sono quelle su cui
i test dicono davvero qualcosa. I servizi scrivono, e ognuno è l'unica porta della
sua area.

| Unità | Cosa fa | Tocca |
|---|---|---|
| `ShippingWeight` (pura) | dalle righe `{peso, lunghezza, larghezza, altezza, quantità}` e dal divisore, il peso tassabile: per riga il maggiore tra peso reale e volume ÷ divisore, per la quantità, sommato | niente |
| `ShippingRates` (pura) | da un listino, i suoi scaglioni, il peso tassabile e il totale dei prodotti dopo gli sconti, l'importo (o «gratuita»); i passi 2-5 di §4.11 | niente |
| `ShippingZones` | dato paese e provincia, la zona più specifica (provincia prima del paese), o nessuna | `gst_shipping_zones`, `gst_shipping_zone_areas` |
| `Shipping` | unica porta del calcolo: `options($cartId)` (metodi disponibili con prezzo e tempi), `quote($cartId, $methodId)` (prezzo di uno o il motivo del rifiuto), `line($cartId)` (la riga da scrivere, o nessuna) | `ShippingWeight`, `ShippingRates`, `ShippingZones`, `Cart` |
| `Shipments` | unica porta delle spedizioni: crea dall'ordine, parte, consegnata, annullata, pronto e ritirato; scrive il log e porta avanti l'ordine | `Lifecycle`, `OrderNotifier` |
| `Carriers` | corrieri e link di tracking dal template (`{tracking}`) | `gst_carriers` |

Flusso del calcolo: il carrello conosce destinazione (`shipping_country`,
`shipping_province`) e metodo scelto; `Cart::recalculate` chiede a `Shipping::line`
la riga e la scrive; `OrderTotals` somma, ripartisce e azzera col coupon.

### 2. Piano 1 — Listini e calcolo

**Tabelle** come in §4.11, tutte con prefisso `gst_`, `deleted` e `position` dove
serve: `gst_carriers`, `gst_shipping_methods`, `gst_shipping_zones`,
`gst_shipping_zone_areas`, `gst_shipping_rates`, `gst_shipping_rate_brackets`.
`syncSchema` locale come le altre impostazioni del commerciante. Prefissi dei
codici in `Codes`: `car_`, `shm_` (metodo) e `shz_` (zona).

**Calcolo, in ordine.**

1. Peso tassabile: per riga `max(peso, L × l × h ÷ divisore)`; con divisore
   vuoto o zero conta il peso reale. Le righe che non richiedono spedizione
   (`requires_shipping = false`) e le righe non prodotto non contano; per le
   confezioni (`bundle`) contano i componenti, che sono ciò che esce dal magazzino.
2. Importo dello scaglione che contiene il peso (primo con `max_weight` ≥ peso,
   ordinati). Oltre l'ultimo: tariffa `excess` al kg, su tutto il peso
   (`total_weight`) o sulla sola parte oltre l'ultimo scaglione (`excess_only`).
   Senza scaglioni oltre e peso oltre l'ultimo, il listino non copre quel peso e
   il metodo non compare.
3. `× (1 + carburante %)`, poi `× (1 + margine %)` (il margine può essere
   negativo).
4. Arrotondamento per eccesso al gradino `rounding_step` (vuoto = al centesimo);
   mai sotto `min_price`.
5. Gratuita se il totale dei prodotti dopo gli sconti supera `free_over_amount`
   e/o il peso tassabile è sotto `free_under_weight`; con entrambe impostate
   devono valere tutte e due.

Si arrotonda al centesimo alla fine di ogni passo, mai a catena su float
grezzi: un test lo fissa con casi in cui la catena sbaglia di un centesimo.

**Innesto nel carrello.** Con `shipping` accesa, `fulfillment_type = shipping` e un
`shipping_method_id` scelto, `Cart::recalculate` tiene **una sola** riga
`shipping` (descrizione = nome del metodo, quantità 1, aliquota
`gst_settings.shipping_tax_id`), ricalcolata a ogni modifica del carrello. Con
ritiro, consegna `none` o metodo vuoto la riga non c'è. Il costo scritto a mano
sulla riga (`price_source = manual`) non si tocca. Se il metodo non copre più la
destinazione, la riga esce e `Cart::recalculate` restituisce
`shipping_dropped` con il motivo, come `coupon_dropped`. Gli ordini già creati non
cambiano mai: la riga è una fotografia.

**Contrassegno.** Quando il metodo di pagamento è il contrassegno e il listino ha
`cod_fee`, `Checkout::place` scrive quella commissione al posto di
`fee_type/fee_value` del metodo di pagamento. Senza `cod_fee` vale la commissione
del metodo, come oggi.

**Coupon «spedizione gratuita».** Non cambia: azzera la riga `shipping` che ora
esiste davvero; `shipping_saved` finisce nei riepiloghi.

**Pagine del backend** (sezione *Spedizioni*, solo `admin`, con `shipping` accesa):

- **Metodi di spedizione:** nome, descrizione dei tempi, corriere, codice del
  servizio, canali, attivo, ordine. La scheda di modifica ha un riquadro per ogni
  zona con il suo listino e la tabella degli scaglioni (righe `price` e `excess`);
  si aggiunge una zona alla volta.
- **Zone:** nome e tabella delle aree (paese, provincia).
- **Corrieri:** nome, dati aziendali (indirizzo del core), link di tracking, attivo.

**Dati di prova.** Zone Italia, Isole e UE; due metodi (Standard, Espresso) con
listini a scaglioni, uno con soglia gratuita; un corriere con link di tracking.

### 3. Piano 2 — Spedizioni, ritiro e tracking

**Tabelle:** `gst_shipments`, `gst_shipment_items`, `gst_shipment_status_logs` come
in §4.11, codice `shp_`.

**Servizio `Shipments`.**

- `create(orderId, [orderItemId => quantità], options)`: una spedizione `delivery`
  o `pickup` con le righe indicate (default: tutte le righe rimaste da evadere).
  Rifiuta righe già evase oltre la quantità, un ordine annullato o non confermato,
  una riga `text`/`shipping`/`fee`, un ritiro su una sede non attiva, senza
  `is_pickup_point` o chiusa.
- `ship(shipmentId, carrierId, tracking)`: stato `in_transit`, `shipped_at`,
  `tracking_url` dal template del corriere, email «spedito» (`customer_notified_at`).
- `deliver`, `cancel`; per il ritiro `ready` (`ready_for_pickup`, email «pronto
  per il ritiro») e `pickedUp`.
- Ogni cambio di stato scrive `gst_shipment_status_logs` (stato, nota, utente) e
  chiama `afterShipmentUpdated` (§7).

**Evasione.** Una riga è evasa per la quantità che sta in spedizioni `in_transit`
o oltre (consegnate comprese), o in ritiri `picked_up`; `ready_for_pickup` porta
l'ordine a `ready_for_pickup`. Dopo ogni cambio `Shipments` ricalcola
`fulfillment_status` (`unfulfilled`, `partially_fulfilled`, `fulfilled`) e lo
passa a `Lifecycle::fulfill`, che scrive il log e porta l'ordine a `completed`
quando è anche pagato. Annullare una spedizione già partita rimette le righe da
evadere. Il magazzino non si muove: lo scarico c'è già, fatto da G4 al pagamento.

**Termine dei resi.** Parte dal tracking inserito (`shipped_at`), come dice §4.10,
se la funzionalità `returns` è accesa; nessun cambio di G7 alle regole del reso,
solo il dato disponibile.

**Pagine.** *Spedizioni → Spedizioni*: elenco con filtri (stato, tipo, corriere) e
scheda di lettura con righe, stato, tracking e log; da lì le azioni (partita,
consegnata, annulla, pronto, ritirato) con finestra di conferma. Dalla scheda
ordine, con `shipping` accesa, «Spedisci» al posto di «Segna evaso», e un riquadro
con le spedizioni dell'ordine. L'area cliente resta di E1c; G7 dà solo i dati.

**Email.** Due chiavi nuove per `OrderNotifier`: `shipped` (corriere, tracking e
link) e `ready_for_pickup` (sede, indirizzo e orari). Stesse viste e stessi testi
sovrascrivibili di G4.

**Dashboard.** Due widget: spedizioni in eccezione (`exception`, `failed_attempt`)
e spedizioni partite da più di N giorni e non consegnate (N = 7, costante del
modulo).

### 4. Errori, casi limite e test

**Rifiuti.** `UserError` con messaggio tradotto per motivo: nessun metodo per
questa destinazione, metodo non attivo, peso oltre il listino, riga già evasa,
quantità oltre il residuo, ordine non spedibile, sede di ritiro chiusa o non di
ritiro, spedizione in uno stato che non ammette quel passaggio. Le classi pure non
lanciano: restituiscono `null` o il motivo.

**Casi limite.**

- **Carrello senza righe spedibili** (solo servizi): consegna `none`, nessuna
  riga e nessuna opzione di spedizione.
- **Zona più specifica:** una provincia con listino proprio vince sul paese; se la
  provincia ha la zona ma non quel metodo, il metodo non compare (non ricade sul
  paese).
- **Più zone con la stessa area:** vince la prima per `position`; la pagina avvisa
  della sovrapposizione.
- **Listino senza scaglioni:** non copre niente, il metodo non compare.
- **Peso zero** (articoli senza peso): scaglione più basso; la scheda del
  metodo avverte che manca il peso su alcuni articoli, così il commerciante sa
  che il prezzo è quello dello scaglione più basso.
- **Spedizione parziale, poi seconda per il resto:** `partially_fulfilled`, poi
  `fulfilled`.
- **Reso di una riga spedita:** non tocca la spedizione.
- **Funzionalità spenta:** nessuna riga di spedizione, le pagine spariscono, i
  dati restano; il pulsante «Segna evaso» torna.
- **Due processi** che creano la stessa spedizione per l'ultima quantità: la
  seconda trova il residuo a zero e rifiuta (riga d'ordine bloccata con `FOR
  UPDATE` dentro la transazione).

**Test.**

- *Unitari, sulle classi pure:* `ShippingWeight` (volumetrico maggiore e minore del
  reale, divisore vuoto, quantità), `ShippingRates` (ogni passo, `total_weight` e
  `excess_only`, carburante e margine negativo, arrotondamento, minimo, le due
  soglie da sole e insieme, peso oltre l'ultimo scaglione senza tariffa al kg,
  casi dove la catena su float sbaglia di un centesimo).
- *D'integrazione:* zona più specifica; carrello con riga di spedizione che segue
  le modifiche; metodo che esce; prezzo a mano che resta; coupon gratuito;
  contrassegno con `cod_fee`; creazione di spedizioni parziali e totali;
  evasione ricavata e `completed`; ritiro (sede chiusa e non di ritiro);
  annullo di una spedizione partita; due processi sull'ultima quantità;
  funzionalità spenta; pagine del backend; email; widget; demo.
- *Documenti:* guide utente (listini e zone, spedizioni e tracking, ritiro in
  sede), una guida per sviluppatori (`Shipping::options/quote`, dati per E1c),
  `CHANGELOG.md` e `TODO.md`.

## I due piani

1. **Piano 1 — Listini e calcolo.** Tabelle e Model dei listini, `ShippingWeight`,
   `ShippingRates`, `ShippingZones`, `Shipping`, la riga di spedizione in
   `Cart::recalculate`, `cod_fee` in `Checkout::place`, le tre pagine, i dati di
   prova, le guide. **Chi aggiorna deve lanciare `php forge update`.**
2. **Piano 2 — Spedizioni.** Tabelle e Model delle spedizioni, `Shipments` e
   `Carriers`, evasione ricavata, ritiro, pagine e schede, riquadro nella scheda
   ordine, email, widget, demo, guide, chiusura di G7.

## Fuori da G7

Zone per CAP, orari di ritiro prenotabili, tariffe in tempo reale, corrieri
collegati e etichette (§6.4, funzionalità `carriers`, futura). Il checkout e la
scelta del metodo da parte del cliente sono E1c.
