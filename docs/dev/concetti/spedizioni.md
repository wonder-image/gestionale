---
icon: truck
---

# Spedizioni: zone, listini e calcolo

Il prezzo della spedizione dipende da **dove** va il pacco (la zona) e da
**quanto pesa**. Si accende con la funzionalità `shipping`; da spenta nessuna
riga di spedizione si scrive e il carrello non propone niente, ma i dati restano.
Le guide dei commercianti stanno in [`docs/user/spedizioni-listini.md`](../../user/spedizioni-listini.md) e [`docs/user/spedizioni-spedire.md`](../../user/spedizioni-spedire.md).

## Chi fa cosa

| Classe | Compito | Tocca il database? |
| --- | --- | --- |
| `ShippingWeight` | il peso tassabile: il maggiore tra peso reale e volumetrico, per riga | no |
| `ShippingRates` | il prezzo di un listino dato peso e totale dei prodotti | no |
| `ShippingZones` | la zona di una destinazione: la provincia batte il solo paese, poi `position` | sì |
| `Shipping` | **l'unica porta**: opzioni, preventivo, riga del carrello, contrassegno | sì |
| `RateForm` | i listini come li scrive il form del metodo (campi piatti `rate_<zona>_*`) | sì |

Le classi pure non lanciano eccezioni. Chi ha bisogno di una frase per una persona
la prende da `Shipping`, che lancia `UserError` con le chiavi `shipping.*`.

## Il calcolo di un prezzo

Un listino è `price_type = brackets` (a scaglioni di peso, come sotto) oppure
`fixed`: un prezzo unico in `fixed_price`, che non guarda il peso e salta tutti i
passi qui sotto (niente minimo, arrotondamento, maggiorazione, margine, gratis,
volumetrico: il form non li mostra e `RateForm` non li scrive). Un listino `fixed`
senza prezzo è un errore del form (`fixed_price_missing`).

Scaglione del peso → carburante (%) → margine (%) → arrotondamento per eccesso al
passo → minimo → gratis se i prodotti superano `free_over_amount` o il peso sta
sotto `free_under_weight`. Con `excess_mode = excess_only` oltre l'ultimo
scaglione si paga l'ultimo scaglione più i kg oltre per la tariffa al kg; senza
tariffa al kg un peso oltre l'ultimo scaglione significa **metodo non offerto**.
Il peso di un articolo senza peso vale zero: cade nel primo scaglione (il form
del metodo avvisa quanti articoli da spedire sono in questo caso).

## La porta: `Shipping`

```php
Shipping::options(int $cartId): array          // metodi offerti, col prezzo, in ordine
Shipping::quote(int $cartId, int $methodId): array   // ['price' => '9.90', 'free' => false]; UserError se non vale
Shipping::line(array $cart, array $computed): ?array // la riga da scrivere, o null
Shipping::dropped(array $cart, array $computed): string // perché il metodo scelto è caduto
Shipping::codFee(int $cartId): float           // il costo di contrassegno del listino scelto
```

`options` e `quote` leggono il carrello dal database; `line` e `dropped` lavorano
su ciò che `Cart::recalculate` ha già in mano (`$computed`), perché al momento del
ricalcolo le righe non sono ancora scritte. `resolveLine` dà i due risultati con
un solo calcolo. Il totale per la soglia gratuita è la somma dei `line_total`
delle righe che non sono spedizione né commissioni, quindi **dopo** gli sconti di
riga e di campagna e **prima** dello sconto del coupon.

### Dove si innesta

- **`Cart::recalculate`** → `writeShipping`: una sola riga `shipping` in fondo ai
  prodotti, con `price_source = base`. Se il metodo scelto non copre più la
  destinazione la riga esce e `dropped` dice perché. Una riga `manual` (messa
  dall'ufficio) non si tocca e toglie il posto a quella calcolata.
- **`Checkout::place` → `applyFee`**: se il pagamento è in contrassegno alla
  consegna e il listino scelto ha un `cod_fee`, quella cifra sostituisce la
  commissione del metodo di pagamento.

### Come deve usarlo E1c

1. Dopo l'indirizzo, `Shipping::options($cartId)` per l'elenco da mostrare; vuoto
   significa «non spediamo lì» (o niente da spedire).
2. Alla scelta si salva `shipping_method_id` sul carrello e si chiama il
   ricalcolo: la riga nasce da sola. `quote` serve solo a mostrare un prezzo
   prima di scegliere, o a rifiutare un id arrivato dal browser.
3. Se cambia l'indirizzo il ricalcolo può far cadere il metodo: leggere
   `dropped` e dire al cliente di sceglierne un altro.
4. Il link di tracciamento si compone con `Carrier.tracking_url_template`
   sostituendo `{tracking}`.

## Spedizioni sugli ordini e ritiro

Le spedizioni vere stanno in `gst_shipments` (una per pacco, o una per ritiro) con
`gst_shipment_items` (le righe e le quantità) e `gst_shipment_status_log`. L'**unica
porta** che le scrive è `Shipments`; il resto del modulo legge.

| Metodo | Cosa fa |
| --- | --- |
| `remaining($orderId)` | riga d'ordine → quantità ancora da assegnare (ordinato meno spedizioni vive) |
| `create($orderId, [riga => qtà], $opzioni)` | consegna `pending`; rifiuta quantità oltre il residuo (`shipment.over_quantity`) |
| `ship($id, $dati)` | `in_transit`, `shipped_at`, vettore e tracking; **idempotente** (una seconda chiamata non scrive né manda email); il tracking è obbligatorio se il corriere ha il link (`shipment.tracking_required`) |
| `advance($id, $stato)` | gli altri passaggi; un salto in avanti da `pending` passa da `ship` |
| `update($id, $dati)` | corriere, tracking e stato **anche dopo**, in un'unica transazione (tutto o niente): per le consegne non annullate né rese; il tracking può mancare finché è `pending` o `label_created`; non tocca `shipped_at` e non scrive lo storico degli stati |
| `cancel($id)` | solo prima della partenza; la merce torna spedibile |
| `createPickup($ordine)` · `ready($id)` · `pickedUp($id)` | il ritiro: tre passaggi, sede attiva, di ritiro e aperta |
| `syncOrder($ordine)` | ricalcola l'evasione e la passa a `Lifecycle::fulfill` solo se cambia |

**Il flusso degli stati** sta in `ShipmentFlow`, pura: in avanti si può saltare, indietro
no; una consegna già partita non si annulla (si segna fallita, in eccezione o resa).
`ShipmentFlow::fulfillment()` ricava `fulfillment_status` dalle quantità: `unfulfilled`,
`partially_fulfilled`, `fulfilled` (o `ready_for_pickup` per un ritiro pronto). Nessuno
scrive l'evasione a mano: con `shipping` accesa `OrderActionResource::fulfill` rifiuta gli
ordini con consegna o ritiro, e il solo scrittore dello stato resta `Lifecycle`.

**Il blocco.** Creare una spedizione prende `Order → Shipment → ShipmentItem` in
quest'ordine, con `SELECT … FOR UPDATE` sulle righe dell'ordine *prima* di contare le
quantità già assegnate: due operatori che spediscono l'ultimo pezzo insieme si mettono
in fila e il secondo riceve `shipment.over_quantity`. Chi scrive altre operazioni deve
rispettare lo stesso ordine di blocco, o si rischia lo stallo.

**Email.** `ship` e `ready` mandano `shipped` e `ready_for_pickup` tramite
`OrderNotifier`, a transazione chiusa e mai in modo da far fallire l'operazione. Il link
si compone da `Shipment.tracking_url`.

**Come deve usarle E1c.** Nell'area cliente: leggere lo stato dell'ordine
(`fulfillment_status`) e, per ogni spedizione viva, `status`, `tracking_number` e
`tracking_url` (già composto, con il tracking `rawurlencode`d); mostrare il link solo se
non è vuoto e passarlo sempre per `escape()`, con `rel="noopener"`. Il frontend non
scrive mai spedizioni: chiama solo le letture.

**Cambiare il corriere dopo.** Spesso il corriere si sceglie a pacco pronto, in base a chi
costa meno: `update_shipment` (*Modifica spedizione*, `OrderActions::canEditShipment`)
cambia corriere, tracking e stato dalla scheda della spedizione; passa da `Shipments::update`
come tutte le altre azioni da `OrderActionResource::run()`. Dai tre puntini dell'elenco le voci
*Cambia stato* e *Tracking e corriere* aprono la scheda con la finestra già aperta
(`?apri=modifica`): il core non apre finestre da una voce del menu.

**Scheda del metodo.** Tutte le zone stanno in un'unica card «Zone» (`zonesCard()`): un blocco
per ogni zona con listino acceso (`rate_<zona>_on = true`), costruito da
`ShippingMethodResource::zoneBlock()` (un `Container`, non una `Card`), e in fondo
«+ Aggiungi zona» (`zonePicker()`), che accende il campo nascosto `rate_<zona>_on` e mostra
il blocco. «Togli zona» lo rispegne senza cancellare il listino, ma prima passa dalla
conferma dichiarativa del core (`data-wi-confirm*` sul pulsante: il suo ascolto in cattura
rilancia il clic solo dopo il «Togli», e l'ascolto dello script scatta allora). Una zona senza
listino salvato parte a **prezzo fisso** (valore del campo `rate_<zona>_price_type`); un
listino già salvato tiene il suo tipo. Il metodo non ha un campo per il codice del servizio:
la colonna `provider_service_code` resta nel modello, per i collegamenti API dei corrieri.
«Nuova zona…» apre il `QuickCreateButton` di `ShippingZoneResource` (`apiSchema()` solo
`store`, campi `quickCreateFields()`: nome, paese, provincia), che sta nella card ma con la
colonna nascosta dallo script (deve restare nel DOM: la voce del menu ne clicca l'`id`):
`mutateRequestValues()` trasforma paese e provincia nella prima riga di `areas` in `$_POST`,
che `syncRepeaterRelations` scrive. A prezzo fisso gli scaglioni e i campi che dipendono dal
peso stanno in un `Container` `visibleWhen('rate_<zona>_price_type', 'brackets')`.

**Corrieri di serie.** `Defaults::carriers()` semina POSTE ITALIANE, DHL, GLS, UPS, BARTOLINI
e FEDEX col solo nome e il link di tracking (`{tracking}` in coda), senza toccare quelli già
presenti o cambiati a mano. Sito, logo e i dati dell'API di DHL (chiavi, server,
`/shipments`) non hanno ancora colonne: servono quando si collegheranno i tracking via API.

**Pagine.** `ShipmentResource` (*Vendite → Spedizioni*, elenco, ricercabile anche per numero ordine, e scheda di lettura),
`ShippingMethodResource`, `ShippingZoneResource`, `CarrierResource` e `PackageResource`
(*Metodi di spedizione*, *Zone di spedizione*, *Corrieri*, *Imballaggi*, in quest'ordine) stanno
nel gruppo *Spedizioni* del *Set-up* (`group('spedizioni', …, 70)`),
`OrderShipmentTableResource` (il blocco *Spedizioni* della scheda ordine), le sei
azioni in `OrderActions`/`OrderActionResource`, i riquadri `ShipmentsToCheckWidget`
(`exception` e `failed_attempt`) e `ShippedNotDeliveredWidget` (partite da più di
`ShipmentAlerts::STALE_DAYS` = 7 giorni e non consegnate). Spente con la funzionalità; i
dati restano.

**Termine dei resi.** `shipped_at` è già il dato da usare, ma oggi il modulo non ha un
termine del reso da spostare: quando arriverà partirà da lì.

## Dati di prova

`ShippingDemo` (registrata in `Demo`, dopo `CatalogDemo`) crea le zone Italia,
Isole (CA SS NU OR PA CT ME) e UE (FR DE ES AT BE), un corriere, i metodi
Standard ed Espresso con i loro listini e, solo se mancano, il peso dei pochi
articoli di prova da spedire. `gestionale:demo --clear` toglie ciò che non è
usato da ordini o listini veri e dice cosa è rimasto.

`ShipmentsDemo` (dopo `OrdersDemo`) spedisce gli ordini di prova col flusso vero: una
consegnata, una parziale in viaggio, una in attesa e una in eccezione (e una in viaggio
sull'ordine del coupon, a coupon accesi). Serve `shipping` acceso; il ritiro di prova non
c'è perché chiede una sede di ritiro aperta nelle impostazioni del sito. `--clear` toglie gli
ordini di prova che hanno spedizioni, con righe e storico.
