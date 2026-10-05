# Listini di spedizione e calcolo — piano di realizzazione (G7, piano 1)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** i listini di spedizione e il loro calcolo: metodi, zone per paese e provincia, tariffe a scaglioni con peso volumetrico, carburante, margine, arrotondamento, minimo e soglie di gratuità. Il carrello tiene la riga di spedizione, il contrassegno prende il `cod_fee` del listino, il backend gestisce metodi, zone e corrieri.

**Architecture:** due classi pure in `src/Support/Shipping/` (`ShippingWeight`: righe → peso tassabile; `ShippingRates`: listino, scaglioni, peso e totale prodotti → importo o «gratuita») e tre servizi (`ShippingZones` risolve la zona più specifica, `Shipping` è l'unica porta del calcolo: `options`, `quote`, `line`; `Carriers` regge i corrieri e il link di tracking). `Cart::recalculate`, già punto unico dei prezzi, chiede a `Shipping::line` la riga di spedizione. `Checkout::applyFee` legge il `cod_fee` dal listino.

**Tech Stack:** PHP 8.2+, harness `tests/harness.php` (`php tests/run.php`), sito di prova `boilerplates/ecommerce-site` (tabelle con `php forge update --local`), helper `tests/integrazione/supporto/compra.php`.

**Spec:** `docs/superpowers/specs/2026-10-05-spedizioni-design.md` (sezioni 1–2 e «I due piani» punto 1); regole di fondo in `2026-09-11-gestionale-ecommerce-architettura-design.md` §4.11 (D41, D42).

## Global Constraints

- Lingua: commenti, testi utente e nomi dei test in **italiano** con gli accenti; classi, tabelle, colonne e chiavi in inglese.
- Funzionalità `shipping` (già in `config/features.php`, richiede `orders`): spenta, il carrello non crea righe di spedizione, pagine e menu spariscono, **i dati restano**; gli ordini già fatti non cambiano mai.
- Tabelle col prefisso `gst_`; soft delete `deleted='true'`; chiavi esterne `ON DELETE RESTRICT`; codici `car_`, `shm_`, `shz_` da aggiungere in `Codes` accanto a `SHIPMENT` (`Field::key('code')->text()->uniqueCode(Codes::…)`).
- Unità: peso in kg, misure in cm. Importi `Columns::decimal(..., '12,2')`.
- Le classi pure non toccano il database, non lanciano, non leggono `date()`.
- Si arrotonda al centesimo alla fine di ogni passo del calcolo, mai a catena su float grezzi.
- La riga `shipping` è una per carrello: nome del metodo, quantità 1, `price_source` `base` se calcolata e `manual` se scritta a mano (quella a mano non si tocca), aliquota **`gst_settings.shipping_tax_id`** (non il ripiego delle altre righe). Con ritiro, consegna `none` o metodo vuoto non c'è.
- Rifiuti prevedibili: `UserError::make('shipping.<chiave>', [...])`, frase in `lang/it/gestionale.json` sotto `gestionale.errors.shipping` (JSON riscritto con python `json.dumps(ensure_ascii=False, indent=4) + "\n"`); `ErrorKeysTest` cade se manca una chiave.
- Form compatti: tooltip al posto dei testi d'aiuto, campi correlati sulla stessa riga, niente campi automatici. Tutto ciò che viene dal database e finisce in HTML passa da `escape()`.
- Test d'integrazione dentro `prova()` (transazione annullata), `Gestionale::reset()` dopo aver toccato le funzionalità; ogni task chiude con `php tests/run.php` verde per intero (rossi noti: `ContactResourcesTest` 1, `CatalogDemoTest` 7).
- Branch `g7-spedizioni` (già creato, con la spec); commit con trailer `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`. **Non toccare né committare** i file che l'utente ha modificato e non committato: ogni `git add` nomina i file uno per uno.

## Decisioni di perimetro

- **Destinazione.** Paese e provincia si leggono da `shipping_country`/`shipping_province` dell'ordine; se l'indirizzo di consegna è vuoto, da `billing_*`; senza paese, `IT`. Le province sono sigle maiuscole.
- **Zona.** Un'area con provincia vince su una col solo paese; a pari specificità la zona con `position` minore. Se la zona più specifica non ha un listino attivo per quel metodo, il metodo **non compare** (non ricade sul paese).
- **Listino.** Uno per coppia metodo-zona (`UNIQUE`). Senza scaglioni, o con peso oltre l'ultimo scaglione e nessuna riga `excess`, il listino non copre quel peso e il metodo non compare.
- **Peso tassabile.** Si calcola sulle righe `product` con `requires_shipping` vero (dal modello dell'articolo) che hanno un `product_id`; le madri di una confezione non pesano (contano le figlie, come il magazzino). Divisore vuoto/zero = peso reale. Quantità frazionarie contano per intero.
- **Il totale per la soglia gratuita** è `products_total` **dopo gli sconti** (campagna, riga, coupon), come §4.11 passo 5.
- **Prezzo a mano.** Una riga `shipping` con `price_source` `manual` non si ricalcola mai (ordini di ufficio); si cancella solo se il metodo sparisce dall'ordine.
- **Metodo scelto non più valido.** Per destinazione cambiata o listino disattivato la riga esce, `shipping_method_id` resta e `Cart::recalculate` restituisce `shipping_dropped` con la frase (come `coupon_dropped`).
- **Contrassegno.** `cod_fee` del listino > 0 sostituisce `fee_type/fee_value` del metodo di pagamento quando questo è il contrassegno (`PaymentMethod` con `timing = 'on_delivery'` e `available_for` diverso da `pickup`: il pagamento al ritiro non porta il `cod_fee`).
- **Fuori dal piano 1:** spedizioni, tracking, ritiro (piano 2), checkout e area cliente (E1c).

## Review Focus

- **Il confine degli scaglioni.** Peso esattamente uguale a `max_weight`, peso zero, peso oltre l'ultimo scaglione con e senza riga `excess`, `total_weight` contro `excess_only`. *(Task 2)*
- **Gli importi non scivolano di un centesimo.** Carburante e margine negativo in catena, arrotondamento per eccesso a gradino 0,50 / 1, minimo, margine che porta sotto zero (mai sotto `min_price`, mai negativo). *(Task 2)*
- **Le soglie gratuite.** Solo importo, solo peso, entrambe (tutte e due), importo esattamente uguale alla soglia (non basta: «supera»). *(Task 2)*
- **La zona.** Provincia con zona propria e metodo mancante, due zone sulla stessa area, paese sconosciuto, sigla in minuscolo. *(Task 3)*
- **Il carrello non si sporca.** Una sola riga di spedizione dopo più ricalcoli, destinazione cambiata, prezzo a mano che resta, ritiro che toglie la riga, solo servizi (`requires_shipping` falso) senza riga, coupon `free_shipping` che la azzera ma non la toglie, funzionalità spenta. *(Task 5)*
- **Il form.** Scaglioni fuori ordine o duplicati, importi negativi, zona senza aree, area duplicata, richiesta modificata a mano: rifiutati con una frase, nulla scritto a metà. *(Task 6)*

---

### Task 1: Tabelle, Model e codici

**Files:**
- Create: `src/Models/Shipping/Carrier.php`, `ShippingMethod.php`, `ShippingZone.php`, `ShippingZoneArea.php`, `ShippingRate.php`, `ShippingRateBracket.php`, `tests/ShippingModelsTest.php`
- Modify: `src/Support/Codes.php` (`CARRIER = 'car_'`, `SHIPPING_METHOD = 'shm_'`, `SHIPPING_ZONE = 'shz_'`), `tests/CodesTest.php` se elenca i prefissi

**Interfaces:**
- Produces, tutti `$folder = 'gestionale/models'`, `syncSchema()` → come `DiscountCampaign` (impostazione del commerciante, sincronizzata): `Carrier::$table = 'gst_carriers'` (`code`, `name`, `tracking_url_template`, `provider` enum `manual` default, `active`, `position`); `ShippingMethod` (`code`, `name`, `description`, `carrier_id` int default 0 senza chiave esterna obbligatoria — 0 = nessun corriere —, `provider_service_code`, `applies_online`/`applies_office` enum, `active`, `position`); `ShippingZone` (`code`, `name`, `position`); `ShippingZoneArea` (`shipping_zone_id`, `country` char 2, `province` vuoto = tutto il paese); `ShippingRate` (`shipping_method_id`, `shipping_zone_id`, `volumetric_divisor`, `excess_mode` enum `total_weight|excess_only`, `fuel_surcharge_percent`, `markup_percent`, `rounding_step`, `min_price`, `free_over_amount`, `free_under_weight`, `cod_fee`, `active`; unico su `(shipping_method_id, shipping_zone_id)`); `ShippingRateBracket` (`shipping_rate_id`, `type` enum `price|excess`, `max_weight`, `amount`).
- I campi facoltativi dei listini (`free_over_amount`, `free_under_weight`, `rounding_step`, `volumetric_divisor`) sono **nullable**: `NULL` = non impostato (diverso da 0, che per `free_over_amount` vorrebbe dire «gratuita sempre»).

Ricalcare gli schemi su `DiscountCampaign`/`Coupon` (tabelle con codice, `deleted`) e sui ponti `DiscountCampaignCategory`.

- [ ] **Step 1: scrivere i test** in `ShippingModelsTest.php` (stile di `PromotionModelsTest.php`): i sei modelli hanno tabella e colonne attese con tipo, chiavi esterne e unicità (`gst_shipping_rates` unica su metodo+zona), i campi facoltativi sono nullable, i default (`active` `true`, `excess_mode` `total_weight`, `type` `price`), i codici usano i prefissi giusti.
- [ ] **Step 2: eseguire** `php tests/ShippingModelsTest.php` — atteso: rosso (classi mancanti).
- [ ] **Step 3: implementare** i sei modelli e i tre codici.
- [ ] **Step 4: eseguire** il test, poi `cd /Users/andreamarinoni/Developer/boilerplates/ecommerce-site && php forge update --local` (atteso: sei tabelle create, nessun errore) e `php tests/run.php` dal pacchetto — atteso: verde.
- [ ] **Step 5: commit** «Spedizioni: tabelle e Model dei listini».

### Task 2: Le classi pure — `ShippingWeight` e `ShippingRates`

**Files:**
- Create: `src/Support/Shipping/ShippingWeight.php`, `src/Support/Shipping/ShippingRates.php`, `tests/ShippingWeightTest.php`, `tests/ShippingRatesTest.php`

**Interfaces:**
- Produces: `ShippingWeight::of(array $lines, ?float $divisor): float` con `$lines` lista di `['weight' => float, 'length' => float, 'width' => float, 'height' => float, 'quantity' => float]`; per riga `max(weight, length×width×height ÷ divisor)` (solo il peso reale se divisore `null`/≤ 0 o misure mancanti) × quantità, sommato e arrotondato a 3 decimali.
- Produces: `ShippingRates::price(array $rate, array $brackets, float $weight, float $productsTotal): ?array{amount: float, free: bool}`; `$rate` ha le chiavi delle colonne di `ShippingRate`, `$brackets` lista di `['type', 'max_weight', 'amount']`. Restituisce `null` se il listino non copre il peso. Ordine dei passi come §4.11: scaglione (primo `price` con `max_weight` ≥ peso, ordinati per `max_weight`) o, oltre l'ultimo, la riga `excess` (`amount` = €/kg) su tutto il peso (`total_weight`) o sulla parte oltre l'ultimo scaglione (`excess_only`, in quel caso si somma l'importo dell'ultimo scaglione); × (1 + carburante %), arrotondato a 2 decimali; × (1 + margine %), arrotondato; per eccesso al gradino `rounding_step` (`null`/0 = al centesimo, già fatto); mai sotto `min_price` e mai sotto 0; poi gratuita se `free_over_amount` impostato e `productsTotal` **>** soglia e/o `free_under_weight` impostato e peso **<** soglia (se entrambe impostate, valgono tutte e due) → `['amount' => 0.0, 'free' => true]`. Il `weight` passato è quello tassabile.

- [ ] **Step 1: scrivere i test.** `ShippingWeightTest`: volumetrico maggiore del reale vince; reale maggiore vince; divisore `null` e 0 → reale; misure a zero → reale; quantità 3; più righe sommate; lista vuota → 0. `ShippingRatesTest`: peso uguale a `max_weight` resta nello scaglione e peso appena sopra passa al successivo; peso 0 → primo scaglione; oltre l'ultimo senza `excess` → `null`; `total_weight` (8 kg, ultimo scaglione 5 kg a 10 €, 2 €/kg → 16,00) contro `excess_only` (10 + 3 × 2 = 16,00 con numeri scelti per distinguerli: usare 4 €/kg e 6 kg → `total_weight` 24,00, `excess_only` 14,00 con ultimo scaglione 5 kg a 10 €); carburante 10 % e margine −5 % su 10,00 → 10,45 con arrotondamento a ogni passo (e un caso scelto in cui la catena su float grezzi darebbe un centesimo diverso); gradino 0,50 e 1,00 per eccesso; `min_price` che alza; margine che porterebbe sotto zero → 0 o `min_price`; soglie: solo importo (uguale alla soglia → non gratis, appena sopra → gratis), solo peso (uguale → non gratis), entrambe (basta una sola → non gratis), nessuna → mai gratis; con gratuità `amount` 0 e `free` vero.
- [ ] **Step 2: eseguire** i due file — atteso: rossi (classi mancanti).
- [ ] **Step 3: implementare** le due classi.
- [ ] **Step 4: eseguire** i due file, poi `php tests/run.php` — atteso: verde.
- [ ] **Step 5: commit** «Spedizioni: peso tassabile e prezzo del listino, classi pure».

### Task 3: `ShippingZones` e `Carriers`

**Files:**
- Create: `src/Support/Shipping/ShippingZones.php`, `src/Support/Shipping/Carriers.php`, `tests/integrazione/ShippingZonesTest.php`, `tests/CarriersTest.php`
- Modify: `lang/it/gestionale.json` (`gestionale.errors.shipping`: le chiavi che i task 4–6 useranno si aggiungono man mano; qui `no_method_for_destination`)

**Interfaces:**
- Produces: `ShippingZones::resolve(string $country, string $province): ?int` (id della zona più specifica attiva, `null` se nessuna; paese e provincia normalizzati a maiuscolo e tagliati; provincia con zona propria batte il solo paese; a pari specificità la `position` minore); `ShippingZones::overlaps(int $zoneId): list<string>` (nomi delle altre zone che hanno almeno un'area uguale).
- Produces: `Carriers::trackingUrl(array $carrier, string $tracking): string` pura: sostituisce `{tracking}` (url-encoded) nel template, vuota se template o tracking vuoti. `Carriers::options(): array<int, string>` per i select.

- [ ] **Step 1: scrivere i test.** `CarriersTest` (senza database): template con e senza `{tracking}`, caratteri speciali codificati, template vuoto, tracking vuoto. `ShippingZonesTest` (con `prova()`): paese senza zona → `null`; paese → zona; provincia con zona propria batte il paese; provincia senza zona propria ricade sul paese; sigla in minuscolo; due zone con la stessa area → la `position` minore, `overlaps` le segnala; zona eliminata (`deleted`) ignorata.
- [ ] **Step 2: eseguire** — atteso: rossi.
- [ ] **Step 3: implementare** le due classi.
- [ ] **Step 4: eseguire** i test, `php tests/ErrorKeysTest.php`, `php tests/run.php` — atteso: verde.
- [ ] **Step 5: commit** «Spedizioni: zone e corrieri».

### Task 4: `Shipping` — opzioni, preventivo, riga

**Files:**
- Create: `src/Support/Shipping/Shipping.php`, `tests/integrazione/ShippingTest.php`
- Modify: `lang/it/gestionale.json` (frasi `shipping.*` che servono)

**Interfaces:**
- Consumes: `ShippingZones::resolve`, `ShippingWeight::of`, `ShippingRates::price`, `Gestionale::feature('shipping')`.
- Produces: `Shipping::options(int $cartId): list<array{method_id: int, name: string, description: string, carrier_id: int, price: string, free: bool}>` (metodi attivi con listino attivo per la zona di destinazione, che coprono il peso, per il canale dell'ordine, ordinati per `position`; `[]` con funzionalità spenta, senza righe spedibili o senza zona); `Shipping::quote(int $cartId, int $methodId): array{price: string, free: bool}` o `UserError` `shipping.not_available` con il motivo; `Shipping::line(array $cart, array $computed): ?array` — la riga che `Cart` deve scrivere (`name`, `amount`, `tax_id` da `shipping_tax_id`, `free`) o `null`; per `computed` si usa la forma già costruita in `Cart::recalculate` (campi `product_id`, `quantity`, `line_total`, `type`). Il totale prodotti per la soglia è la somma dei `line_total` delle righe `product` **dopo gli sconti di riga e di campagna** (il coupon si applica dopo: `Shipping` lo riceve in `$productsTotal` solo quando `Cart` può dirlo; vedi task 5).
- Il peso e le misure di ogni riga si leggono dal `Product` (e dal suo `ProductModel` per `requires_shipping`); le righe senza `product_id` o con `requires_shipping` falso non pesano e non contano come spedibili.

- [ ] **Step 1: scrivere i test** (con `prova()`, un sito con sedi e articoli del catalogo di prova, zone e listini creati dal test): `options` elenca solo i metodi coperti per la destinazione e il canale, con il prezzo giusto per il peso del carrello; metodo senza listino per la zona → assente; metodo disattivato → assente; carrello di soli servizi → `[]`; funzionalità spenta → `[]`; `quote` di un metodo valido; di uno non coperto → `UserError` `shipping.not_available`; il peso volumetrico di un articolo ingombrante cambia lo scaglione; soglia gratuita → `free` vero e prezzo `0.00`; destinazione dall'indirizzo di fatturazione quando quello di consegna è vuoto; paese vuoto → `IT`.
- [ ] **Step 2: eseguire** `php tests/integrazione/ShippingTest.php` — atteso: rosso.
- [ ] **Step 3: implementare** `Shipping` e le frasi.
- [ ] **Step 4: eseguire** il test, `php tests/ErrorKeysTest.php`, `php tests/run.php` — atteso: verde.
- [ ] **Step 5: commit** «Spedizioni: il calcolo del carrello, opzioni e preventivo».

### Task 5: Innesto in `Cart::recalculate` e `cod_fee` in `Checkout`

**Files:**
- Modify: `src/Support/Orders/Cart.php` (in `recalculate`: dopo il ciclo delle righe, prima di `Coupons::context`), `src/Support/Orders/Checkout.php` (`applyFee`, ~riga 340), `tests/integrazione/CartShippingTest.php` (nuovo)

**Interfaces:**
- Consumes: `Shipping::line`, `Setting::current()['shipping_tax_id']`.
- Produces: in `Cart::recalculate`, con la funzionalità accesa, `fulfillment_type = 'shipping'` e `shipping_method_id > 0`: una sola riga `shipping` (si crea con `OrderItem::create` come fa `Checkout::applyFee` per le commissioni, `position` 800, `price_source` `base`; se esiste già si aggiorna; una `manual` non si tocca). Altrimenti le righe `shipping` calcolate (`base`) si tolgono. La riga entra in `$computed` con `type` `shipping`, `tax_id` = `shipping_tax_id`, così `OrderTotals` la somma in `shipping_total` e il coupon `free_shipping` la azzera. La risposta ha anche `shipping_dropped` (stringa, vuota se tutto bene): frase quando il metodo scelto non copre più la destinazione.
- Produces: `Checkout::applyFee` usa `cod_fee` del listino scelto (via `Shipping::codFee(int $cartId): float`, da aggiungere a `Shipping`, 0 se non c'è) al posto della commissione del metodo quando il metodo di pagamento è il contrassegno e `cod_fee` > 0.

**Attenzione al totale per la soglia gratuita:** la riga si calcola sui prodotti dopo gli sconti di riga/campagna; lo sconto del coupon (su `OrderTotals`) non rientra. Lo si dichiara in un commento e lo prova un test.

- [ ] **Step 1: scrivere i test** (con `prova()`, `compra.php`): carrello con metodo e destinazione → una riga `shipping`, `shipping_total` e `total` coerenti, IVA con `shipping_tax_id`; due ricalcoli di fila → ancora una sola riga; aggiunta di un articolo pesante → prezzo ricalcolato; destinazione cambiata a una zona non coperta → riga tolta e `shipping_dropped` con la frase, il metodo resta scelto; ritiro (`fulfillment_type` `pickup`) o `none` → nessuna riga; riga a mano → resta intatta ai ricalcoli; solo servizi → nessuna riga; coupon `free_shipping` → riga a `0.00`, `shipping_saved` pieno; funzionalità spenta → nessuna riga; ordine già fatto (`stage` `order`) non cambia se il listino cambia dopo. `Checkout`: contrassegno con `cod_fee` 4,00 e commissione del metodo del 2 % → la riga `fee` vale 4,00; senza `cod_fee` → resta quella del metodo; metodo non contrassegno → `cod_fee` ignorato.
- [ ] **Step 2: eseguire** il file — atteso: rossi i nuovi.
- [ ] **Step 3: implementare** l'innesto e `Shipping::codFee`.
- [ ] **Step 4: eseguire** il file, `CartTest.php`, `CheckoutTest.php`, poi `php tests/run.php` — atteso: verde.
- [ ] **Step 5: commit** «Spedizioni: la riga del carrello e il cod_fee del contrassegno».

### Task 6: Le pagine del backend — Corrieri, Zone, Metodi

**Files:**
- Create: `src/Resources/Shipping/CarrierResource.php`, `ShippingZoneResource.php`, `ShippingMethodResource.php`, `src/Support/Shipping/RateForm.php` (trait per riquadri listino e tabella scaglioni), `tests/ShippingResourcesTest.php`, `tests/integrazione/ShippingBackendTest.php`
- Modify: `lang/it/gestionale.json` se servono frasi; la voce di menu nell'area *Spedizioni* come fa `DiscountCampaignResource`

**Interfaces:**
- Produces: tre Resource con `$feature = 'shipping'`, solo `admin`; `CarrierResource` (nome, dati aziendali, link di tracking con tooltip `{tracking}`, attivo, ordine; `$docsPage = 'spedizioni/spedizioni-listini'`); `ShippingZoneResource` (nome e tabella aree paese/provincia con righe aggiungibili, avviso di sovrapposizione da `ShippingZones::overlaps`, che non blocca); `ShippingMethodResource` (nome, descrizione tempi, corriere, codice servizio, canali, attivo, ordine, e un **riquadro per zona** con i campi del listino e la tabella scaglioni `price`/`excess`; si aggiunge una zona alla volta, `Zone senza listino` offerte da un select). `RateForm::readRates(array $post): array` e `RateForm::saveRates(int $methodId, array $rates): void` (riscrive listini e scaglioni dentro `Transaction::run`; i listini tolti dal form si disattivano con `deleted`); `RateForm::validate(array $rates): void` lancia `UserError` `shipping.*`.
- La scheda del metodo avvisa (Alert, non blocca) se nel catalogo ci sono articoli senza peso.

Pagine nello stile di `CustomizationResource` (struttura) e `TagResource` (ponti); **non** ricopiare da `ProductModelResource`.

- [ ] **Step 1: scrivere i test.** `ShippingResourcesTest` (senza database): campi attesi dei tre moduli, voci di menu sotto *Spedizioni*, le pagine spariscono con la funzionalità spenta, `readRates` toglie scaglioni vuoti e normalizza i numeri. `ShippingBackendTest` (con `prova()`): salvare un metodo con due zone scrive listini e scaglioni; modificare riscrive senza orfani; scaglioni duplicati o con `max_weight` ripetuto, importo negativo, margine che porta il prezzo sotto zero senza minimo (consentito), zona senza aree, area duplicata, listino senza scaglioni → `UserError` e **nessuna riga** scritta; il form senza zone salva solo il metodo; un id inesistente o un tipo cambiato a mano non rompono la pagina.
- [ ] **Step 2: eseguire** i due file — atteso: rossi.
- [ ] **Step 3: implementare** `RateForm` e le tre Resource.
- [ ] **Step 4: eseguire** i due file, poi `php tests/run.php` — atteso: verde.
- [ ] **Step 5: commit** «Spedizioni: pagine di corrieri, zone e metodi».

### Task 7: Dati di prova, guide, chiusura

**Files:**
- Create: `src/Seeding/ShippingDemo.php`, `docs/user/spedizioni-listini.md`, `docs/dev/concetti/spedizioni.md`, `tests/integrazione/ShippingDemoTest.php`
- Modify: `src/Seeding/Demo.php` (`ShippingDemo::class` dopo `CatalogDemo`, prima di `OrdersDemo`), `docs/user/SUMMARY.md`, `docs/dev/SUMMARY.md`, `gitbook-docs.yaml` se elenca le pagine, `tests/DocsPagesTest.php` se le elenca, `CHANGELOG.md`, `TODO.md`

**Interfaces:**
- Produces: `ShippingDemo::register()` come le altre classi (rimovibili con `gestionale:demo --clear`): zone **Italia**, **Isole** (SA, CA, … le sigle delle due isole maggiori più quelle sarde/siciliane principali: scegliere un elenco breve e dichiararlo nella classe) e **UE** (cinque paesi); un corriere con link di tracking; due metodi (**Standard**, **Espresso**) con listini a scaglioni, Espresso con soglia gratuita sopra un importo; un listino con `cod_fee`. Il peso dei pochi articoli di prova senza peso si compila solo se l'anagrafica demo li ha vuoti.

- [ ] **Step 1: scrivere il test** `ShippingDemoTest.php`: dopo `register()` zone, corriere, metodi e listini esistono; `Shipping::options` su un carrello di prova con destinazione IT dà entrambi i metodi, su un'isola dà il prezzo delle Isole, su un paese fuori zona dà `[]`; `--clear` toglie tutto senza ponti orfani; due `register()` di fila non duplicano.
- [ ] **Step 2: eseguire** — atteso: rosso.
- [ ] **Step 3: implementare** la classe e registrarla; scrivere le due guide (utente: cos'è un metodo, una zona, un listino e i suoi campi, come si calcola un prezzo con un esempio svolto, quando un metodo non compare, il prezzo a mano sugli ordini di ufficio; sviluppatori: `Shipping::options/quote/line`, l'innesto in `Cart::recalculate`, `cod_fee`, come E1c deve usarli); voce nel `CHANGELOG.md` con l'avvertenza **«lanciare `php forge update`»**; in `TODO.md` G7 → «piano 1 (Listini e calcolo) fatto», con il piano 2 da fare.
- [ ] **Step 4: eseguire** `php tests/run.php` per intero — atteso: verde (rossi noti a parte). Lanciare `php forge gestionale:demo` sul sito di prova e guardare le tre pagine.
- [ ] **Step 5: commit** «Spedizioni: dati di prova, guide e chiusura del piano 1».

### Task 8: Prova nel browser (serve il tuo accesso)

Con il sito di prova avviato e l'utente collegato al backend: (1) accendere *Spedizioni* da Funzionalità e vedere comparire il menu; (2) creare un metodo con due zone e scaglioni dal modulo; (3) nel carrello di prova scegliere il metodo e vedere la riga di spedizione e il totale; (4) cambiare la provincia in una zona non coperta e vedere la riga sparire; (5) contrassegno con `cod_fee`; (6) spegnere la funzionalità: voci sparite, carrello senza riga. Riportare cosa si è visto; niente merge senza il via dell'utente.
