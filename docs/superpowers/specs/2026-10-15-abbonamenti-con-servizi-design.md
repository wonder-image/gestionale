# G10b — Abbonamenti con più servizi

**Stato:** design discusso con l'utente il 2026-10-15 (decisioni G10b.1–G10b.16).
Spec da rivedere; nessun piano scritto.

Riferimenti: [G10 + E3](2026-10-13-abbonamenti-design.md) (decisioni G10.1–G10.19,
che questa spec in parte cambia); [architettura](2026-09-11-gestionale-ecommerce-architettura-design.md)
§4.13 (abbonamenti, D17, D44), §5.5, §10.5; [G9](2026-10-13-vendite-da-ufficio-design.md)
(rate, Scadenzario, proforma); guida utente
[Personalizzazioni](../../user/catalogo-personalizzazioni.md); Stripe
[Mixed interval subscriptions](https://docs.stripe.com/billing/subscriptions/mixed-interval).

## Contesto

Un'agenzia vende **servizi ricorrenti**: un dominio `.it` (con il nome del dominio
da scrivere), un hosting legato a quel dominio, l'assistenza. Li vuole vendere
insieme, in un acquisto solo, e poi **cambiarli nel tempo**: un anno il cliente
aggiunge l'assistenza, un altro trasferisce il dominio altrove e il dominio va
tolto. Dominio e hosting sono solo l'esempio: il modello deve valere per
qualsiasi servizio.

Oggi (G10, su `main`) non si può:

- un abbonamento ha **un solo** prezzo di piano (`gst_subscriptions.plan_price_id`);
- un piano **non ha personalizzazioni**: il nome del dominio non ha dove stare;
- **online un cliente ha al massimo un abbonamento in corso**
  (`OnlineSubscriptions::start()` rifiuta con `subscription.already_active`); da
  ufficio invece nessun controllo;
- `Subscriptions::limit()` legge **solo l'abbonamento più recente**;
- *Cambia piano* sostituisce l'unico prezzo, **senza conguagli** (G10.13).

Nelle spec c'era solo D44 (§10.5 dell'architettura): `plan_items`, per i prodotti
fisici negli abbonamenti, funzionalità futura. Questa spec la **assorbe**: le
righe dell'abbonamento sono il modello generale, e un prodotto fisico sarà, dopo,
un altro tipo di riga.

### Cosa c'è già nel codice

- **Abbonamenti** (G10): `gst_plans`, `gst_plan_prices`, `gst_plan_features`,
  `gst_subscriptions`, `gst_subscription_plan_changes`,
  `gst_subscription_status_logs`; `SubscriptionAmounts` (calcola un periodo come
  un ordine con una riga sola, su `LinePrice` e `OrderTotals`);
  `OnlineSubscriptions`, `OfficeSubscriptions`, `SubscriptionEvents`,
  `StripeSubscriptionData`, `StripeCatalog`.
- **Personalizzazioni** (catalogo): `gst_customizations` con le opzioni in
  `gst_customization_options`; il legame con gli articoli in
  `gst_product_model_customizations` (`product_model_id` obbligatorio,
  `customization_id`, `is_required`, `position`); `Support\Catalog\Customizations`
  controlla, prezza e prepara la copia della riga (`check()` pura, `forModel()`,
  `encode()`/`decode()` per la connessione latin1). La funzionalità
  *Personalizzazione* richiede *Ordini*.
- **Righe d'ordine**: `gst_order_items.parent_item_id` e `customization` (JSON)
  sono già il modello di una riga legata a un'altra e di una personalizzazione
  copiata.
- **Stripe**: il core usa l'API `2025-08-27.basil`, che ha già la modalità di
  fatturazione *flexible* e gli intervalli misti (da `2025-07-30.basil`).
- **Versione**: il gestionale è `0.1.0`, non rilasciato (D65: la `1.0.0` esce dopo
  G9 e G10 + E3). Non ci sono abbonamenti veri da migrare: solo i dati di prova.

## Decisioni

| # | Decisione |
|---|---|
| G10b.1 | Un abbonamento è un **contenitore con righe** (`gst_subscription_items`), una riga per servizio. L'abbonamento tiene cliente, canale, data di rinnovo, pagamento, coupon e stato; la riga tiene il prezzo del piano, la quantità, le personalizzazioni e il legame. *Scartati:* un abbonamento per servizio raggruppati in un «contratto» (date e addebiti separati); articoli del catalogo ricorrenti (mescolano ordini e rinnovi, contro D17). |
| G10b.2 | I **piani** restano i «prodotti ad abbonamento», in *Catalogo → Abbonamenti*: prezzi, intervalli, IVA, limiti e Stripe non cambiano. |
| G10b.3 | **Una sola tabella dei legami delle personalizzazioni**, per articoli e piani: `gst_product_model_customizations` diventa `gst_customization_links` con `product_model_id` **o** `plan_id`. Niente tabella a parte per i piani. |
| G10b.4 | Sulla personalizzazione due interruttori, **Per i prodotti** e **Per gli abbonamenti**: dicono dove si può collegare. Almeno uno acceso. |
| G10b.5 | *Personalizzazione* richiede *Ordini* **oppure** *Abbonamenti*. |
| G10b.6 | Un piano può dire **«Si lega a»**: i piani a cui una sua riga deve appoggiarsi (Hosting → Dominio). La riga tiene `parent_item_id`. |
| G10b.7 | **Data di rinnovo unica** per tutte le righe di un abbonamento. Intervalli diversi nello stesso abbonamento solo se **multipli del più corto** (regola di Stripe), online e da ufficio. |
| G10b.8 | **Aggiungere** una riga: si sceglie ogni volta tra **pro-rata subito**, **gratis fino al rinnovo** e **parte al rinnovo**. |
| G10b.9 | **Togliere** una riga: si sceglie ogni volta tra **a fine periodo** e **subito con credito**. Durante il vincolo della riga vale sempre la fine del vincolo. |
| G10b.10 | **Cambiare** una riga (altro prezzo o altro piano): le stesse tre scelte dell'aggiunta. Cambia G10.13 («senza conguagli»). |
| G10b.11 | Dall'**area cliente** il cliente aggiunge servizi solo con il pro-rata e li toglie solo a fine periodo; le altre scelte sono dell'operatore. |
| G10b.12 | **Impostazione «Abbonamenti in corso per cliente»**: 0 = senza limite, di serie 1. La controllano l'online e l'ufficio. Cambia G10.11 per la parte di `already_active`. |
| G10b.13 | **Acquisto online**: il cliente mette più servizi in una selezione e paga **un solo Stripe Checkout** con più righe; nasce un abbonamento con N righe. |
| G10b.14 | **Limiti dei piani** sommati su tutte le righe in corso del cliente: i numeri si sommano (per la quantità), «illimitato» vince, un testo prende la riga più recente. |
| G10b.15 | **Coupon** sull'intero abbonamento, come oggi. |
| G10b.16 | Tre piani di lavoro (§8). Tutto dietro `subscriptions` e `online_subscriptions`, spente di serie: la consegna del 29/10 non cambia. |

## 1. Dati

### 1.1 Personalizzazioni condivise (G10b.3, G10b.4)

| Tabella | Cambia |
|---|---|
| `gst_customizations` | in più `for_products` e `for_subscriptions` (`true`/`false`; di serie `true` e `false`) |
| `gst_product_model_customizations` → **`gst_customization_links`** | `product_model_id` diventa facoltativo (foreign su `gst_product_models`, `NULL` se il legame è di un piano); in più `plan_id` (foreign su `gst_plans`, `NULL` se è di un articolo); `customization_id`, `is_required`, `position` come oggi |

- **Un legame ha esattamente uno** tra `product_model_id` e `plan_id`: lo controlla
  la regola del modello e lo prova un test.
- **Il modello** `ProductModelCustomization` diventa `CustomizationLink`; la
  rinomina si fa con l'aggiornamento della tabella, senza dati veri da salvare
  (gestionale non rilasciato). I dati di prova (`CatalogDemo`, `ShowcaseDemo`) si
  riscrivono.
- **Gli interruttori**:
  - nella scheda dell'articolo si propongono solo le personalizzazioni con *Per i
    prodotti*; nella scheda del piano solo quelle con *Per gli abbonamenti*;
  - **spegnere** un interruttore mentre la personalizzazione è collegata a qualcosa
    di quel tipo è rifiutato, con il numero dei collegamenti, come l'eliminazione
    di una personalizzazione in uso;
  - con *Abbonamenti* spenta l'interruttore *Per gli abbonamenti* non si vede; con
    *Ordini* spenta non si vede *Per i prodotti*.
- **`Customizations::forModel()`** resta; accanto **`forPlan()`**, che legge gli
  stessi legami per `plan_id`. `check()`, `encode()` e `decode()` non cambiano: la
  riga dell'abbonamento si salva come la riga d'ordine.
- **Il sovrapprezzo** di una personalizzazione vale anche sulla riga
  dell'abbonamento, a ogni periodo, per la quantità della riga.

### 1.2 Piani

| Tabella | Cambia |
|---|---|
| `gst_plans` | niente |
| `gst_plan_links` (nuova) | `plan_id`, `parent_plan_id`: «una riga di `plan_id` si lega a una riga di `parent_plan_id`» (G10b.6) |

- Un piano con dei `gst_plan_links` **chiede** il legame: la riga non si crea
  senza una riga madre di uno di quei piani, nello stesso abbonamento, in corso.
- Un piano senza legami non si appoggia a niente.
- Una riga si lega a **una sola** riga madre; una riga madre può avere più righe
  figlie (due hosting sullo stesso dominio, se il commerciante lo vuole). Un solo
  livello: una riga figlia non ha figlie.

### 1.3 Abbonamenti e righe

| Tabella | Colonne |
|---|---|
| `gst_subscriptions` | come oggi **senza** `plan_price_id`; `subtotal`, `discount_total`, `tax_total`, `total` diventano la somma delle righe del periodo; `commitment_ends_at` = il più lontano fra le righe |
| `gst_subscription_items` (nuova) | `subscription_id`, `plan_price_id`, `parent_item_id`, `quantity`, `status` (`pending`, `active`, `ending`, `ended`), `customization` (JSON, come `gst_order_items`), `unit_price`, `customization_total`, `tax_id`, `tax_rate`, `subtotal`, `tax_total`, `total` (del periodo pieno), `starts_at`, `commitment_ends_at`, `ends_at`, `provider_reference` (`si_…`), `position` |
| `gst_subscription_plan_changes` → **`gst_subscription_item_changes`** | `subscription_id`, `item_id`, `action` (`added`, `removed`, `changed`, `customized`), `old_plan_price_id`, `new_plan_price_id`, `billing` (`prorate`, `free`, `next_period`, `period_end`, `credit`), `amount` (quota pro-rata o credito), `effective_at`, `user_id`, `source`, `changed_at`, `note` |
| `gst_subscription_status_logs` | come oggi (stati dell'abbonamento) |

- **Stati della riga**: `pending` (aggiunta con «parte al rinnovo», o in attesa del
  primo pagamento online), `active`, `ending` (si toglie a fine periodo o a fine
  vincolo), `ended`.
- **Aliquota per riga**: ogni piano ha la sua categoria fiscale, quindi l'aliquota
  sta sulla riga. `tax_id`, `tax_rate` dell'abbonamento restano solo come
  riepilogo (l'aliquota se è unica, 0 se sono più d'una).
- **Le personalizzazioni copiate** si correggono dalla scheda (un errore nel nome
  del dominio): la correzione è un `customized` nella storia, con il valore di
  prima nella nota. Il sovrapprezzo si ricalcola dal periodo dopo.
- **Abbonamento senza righe in corso**: non esiste. Togliere l'ultima riga è una
  disdetta (§4.4).

### 1.4 Impostazione (G10b.12)

`subscriptions_per_customer` in *Impostazioni → Vendite*, accanto a «Giorni prima
di «non pagato»»: «Abbonamenti in corso per cliente», intero ≥ 0, di serie 1,
0 = senza limite. Conta gli abbonamenti in `Subscription::LIVE` più `pending`.
Al limite:

- **online** il Checkout non parte (`subscription.limit_reached`) e la pagina
  manda all'area cliente, dove i servizi si aggiungono all'abbonamento che c'è;
- **da ufficio** *Nuovo abbonamento* rifiuta con lo stesso errore e dice quale
  abbonamento aprire.

## 2. Intervalli e date (G10b.7)

- **Data di rinnovo dell'abbonamento** (`billing anchor`): l'inizio. Ogni riga
  rinnova a multipli del suo intervallo da quella data: l'assistenza mensile ogni
  mese, il dominio annuale ogni anno, alla stessa data.
- **Regola degli intervalli** (pura, in `PlanPriceRules`): fra le righe in corso e
  quella nuova, ogni `term_months` è un multiplo del più piccolo. Mensile +
  trimestrale + annuale sì; bimestrale + trimestrale no. Online e da ufficio.
- **Periodo dell'abbonamento** (`current_period_start`/`_end`): quello della riga
  con l'intervallo più corto; ogni riga ha in più la sua fine di periodo,
  calcolata dalla data di rinnovo e dal suo intervallo (non si salva: si
  ricava).
- **Prova**: solo alla creazione dell'abbonamento, la più lunga fra i prezzi
  scelti; una riga aggiunta dopo non ha prova.

## 3. Importi

`SubscriptionAmounts` passa da una riga a **N righe**: ogni riga è una riga di
`LinePrice` (prezzo del piano + sovrapprezzi delle personalizzazioni, per la
quantità), il totale è `OrderTotals`, gli arrotondamenti quelli degli ordini.

- **Coupon** (G10b.15): sull'insieme delle righe che si addebitano nel periodo,
  con `couponApplies()` di oggi. Un coupon «per N mesi» si conta dall'inizio
  dell'abbonamento, non della riga.
- **Pro-rata** (pura, `SubscriptionProration`): quota della riga per i giorni dal
  giorno d'effetto alla fine del suo periodo, sul periodo pieno, arrotondata come
  `LinePrice`. Stessa funzione per la quota da pagare (aggiunta) e per il credito
  (rimozione subito).
- **Online** Stripe calcola da sé pro-rata e crediti: il gestionale **legge** gli
  importi dalle fatture (come oggi da `invoice.paid`) e li confronta con
  `SubscriptionProration` solo nei test.

## 4. Operazioni sulle righe

Tutte in un servizio nuovo, **`SubscriptionItems`**, che decide le regole e passa
al canale: `OfficeSubscriptions` scrive rate e proforma, `OnlineSubscriptions`
chiama Stripe. Ogni operazione scrive `gst_subscription_item_changes`.

### 4.1 Aggiungere (G10b.8)

Dalla scheda, **«Aggiungi servizio»**: piano e prezzo, quantità, personalizzazioni
del piano, riga madre (se il piano la chiede) e come si paga:

| Scelta | Da ufficio | Online |
|---|---|---|
| **Pro-rata subito** | riga `active`; una rata con la quota pro-rata, scadenza dalla condizione di pagamento, e un proforma della quota; dal rinnovo dopo la riga entra nel periodo | `subscription_items.create` con `proration_behavior=always_invoice`: Stripe addebita subito la quota sulla carta salvata; riga `active` a `invoice.paid` |
| **Gratis fino al rinnovo** | riga `active`, nessuna rata ora | `proration_behavior=none` |
| **Parte al rinnovo** | riga `pending`; il giro giornaliero la rende `active` all'apertura del periodo dopo | fase nuova di uno *Subscription Schedule* che aggiunge la riga dal prossimo rinnovo |

Controlli: piano attivo; regola degli intervalli; personalizzazioni obbligatorie
compilate (`Customizations::check()`); riga madre in corso se il piano la chiede;
abbonamento in corso (`unpaid` e `cancelled` no).

### 4.2 Togliere (G10b.9)

Dalla scheda, **«Togli»** sulla riga, con la scelta:

| Scelta | Da ufficio | Online |
|---|---|---|
| **A fine periodo** | riga `ending`, `ends_at` = fine del suo periodo; il giro giornaliero non la mette nel periodo dopo e la chiude (`ended`) | fase di uno *Subscription Schedule* che toglie la riga al rinnovo; `ended` alla notifica |
| **Subito con credito** | riga `ended` subito; il credito (`SubscriptionProration`) entra come riga di sconto nel prossimo proforma e riduce la prima rata del periodo dopo; se l'abbonamento finisce prima, il credito resta in *Da controllare* | `subscription_items.delete` con `proration_behavior=create_prorations`: Stripe mette il credito sulla fattura dopo |

- **In vincolo** (`commitment_ends_at` della riga nel futuro): solo «a fine
  periodo», con `ends_at` = fine del vincolo. La pagina lo dice e non offre
  l'altra scelta.
- **Righe figlie**: una riga con figlie in corso non si toglie da sola. La finestra
  le elenca e le toglie **insieme**, con la stessa scelta.
- **Rimborsi**: nessuno dal gestionale, come in G10. Il credito riduce i pagamenti
  dopo, non restituisce soldi.

### 4.3 Cambiare (G10b.10)

**«Cambia»** sulla riga: un altro prezzo dello stesso piano o un altro piano, con le
scelte dell'aggiunta. È una rimozione «subito con credito» della riga vecchia più
un'aggiunta della nuova, ma in **una sola operazione** sulla stessa riga (stessa
`position`, stesse personalizzazioni se il piano nuovo le ha, stessa riga madre e
stesse figlie se i legami lo permettono). Online è `subscription_items.update` con
il `proration_behavior` della scelta.

- *Cambia piano* di G10 sparisce: con una riga sola è «Cambia» su quella riga.
- Il prezzo di rinnovo a fine vincolo (`renewal_plan_price_id`) è un «Cambia»
  automatico con «parte al rinnovo».

### 4.4 Disdire e annullare

- **Disdici** l'abbonamento = tutte le righe «a fine periodo» (o a fine vincolo):
  come oggi.
- **Annulla** (solo da ufficio) = tutte le righe `ended` subito, rate in attesa
  annullate, nessun credito: come oggi.
- Quando l'ultima riga diventa `ended`, l'abbonamento è `cancelled`.

## 5. Da ufficio

- **«Nuovo abbonamento»**: cliente, data d'inizio, pagamento e coupon come oggi; poi
  le **righe** in un repeater, ognuna con piano e prezzo, quantità,
  personalizzazioni e riga madre. Controllo del limite per cliente (§1.4) e della
  regola degli intervalli.
- **`openPeriod()`**: il periodo contiene le righe `active` il cui periodo comincia
  quel giorno, le `pending` che partono e gli eventuali crediti; le rate nascono
  sul totale, come oggi; il **proforma** elenca le righe (piano, personalizzazioni,
  quantità, importo) e i crediti.
- **`daily()`**: in più chiude le righe `ending` arrivate a `ends_at` e attiva le
  `pending`.
- **Scheda**: un riquadro **Servizi** con le righe (stato, prezzo, personalizzazioni,
  riga madre, prossimo rinnovo della riga, fine vincolo) e le azioni *Aggiungi
  servizio*, *Cambia*, *Togli*, *Correggi personalizzazioni*. La storia mostra
  `gst_subscription_item_changes`.

## 6. Online (Stripe)

- **Modalità di fatturazione *flexible*** per gli abbonamenti nuovi
  (`billing_mode[type]=flexible` sul Checkout): serve agli intervalli misti. Gli
  abbonamenti di prova creati prima si ricreano (niente dati veri).
- **`StripeCatalog`**: un prodotto Stripe per piano e un prezzo per prezzo, come
  oggi. Un item Stripe ha un prezzo solo, quindi il **sovrapprezzo delle
  personalizzazioni** non è un prezzo a parte: il prezzo Stripe della riga è
  **prezzo del piano + sovrapprezzi**, con impronta che lo comprende
  (`price:2490-year-12-eur`, come oggi): due righe con sovrapprezzi diversi hanno
  prezzi Stripe diversi dello stesso prodotto.
- **Checkout** (G10b.13): `OnlineSubscriptions::start()` riceve **più righe**
  (codice del prezzo, quantità, personalizzazioni, indice della riga madre) e crea
  una Checkout Session con un `line_item` per riga. Le righe nascono `pending` con
  l'abbonamento e diventano `active` al primo pagamento; il legame `si_` ↔ riga si
  scrive da `customer.subscription.created` (metadati `item_id` sugli item).
- **Notifiche**: `StripeSubscriptionData` legge gli **item** con le loro date di
  periodo (`items.data[].current_period_*`), non più solo quelle in alto;
  `SubscriptionEvents` allinea righe, stati e importi.
- **Operazioni sulle righe** da `OnlineSubscriptions` (§4), con
  `SubscriptionSchedule` dove la modifica vale dal rinnovo. Uno schedule esistente
  si aggiorna, non se ne crea un secondo.
- **Riallineamento orario**: confronta anche le righe.

## 7. Ecommerce

- **Pagina dei piani**: ogni prezzo ha **«Aggiungi»** invece di «Abbonati»; il
  cliente compone una **selezione** (in sessione, non nel carrello degli ordini):
  per ogni servizio quantità, personalizzazioni e, se il piano lo chiede, a quale
  servizio della selezione (o del suo abbonamento in corso) si lega. La regola
  degli intervalli si controlla mentre si compone.
- **Riepilogo e pagamento**: dati di fatturazione, codice sconto, totale del primo
  addebito, poi Stripe Checkout (G10b.13). Il dataLayer passa a più item
  (`view_item_list`, `select_item`, `begin_checkout` con un item per riga).
- **Area cliente, sezione *Abbonamenti***: per ogni abbonamento le righe con
  personalizzazioni e prossimo rinnovo; *Aggiungi servizio* (solo pro-rata, con il
  totale della quota prima di confermare), *Togli* (solo a fine periodo),
  *Cambia* (solo pro-rata). Disdetta e carta come oggi (G10b.11).
- **Al limite di abbonamenti** (§1.4) la pagina dei piani aggiunge i servizi
  all'abbonamento che c'è, dall'area cliente, invece di aprirne un altro.

## 8. Piani di lavoro

1. **Dati e ufficio** (gestionale): personalizzazioni condivise e interruttori
   (§1.1), legami fra piani, righe e storia (§1.2–1.3), impostazione (§1.4),
   regola degli intervalli, `SubscriptionAmounts` a N righe e
   `SubscriptionProration`, `SubscriptionItems` col canale ufficio, *Nuovo
   abbonamento* con le righe, riquadro *Servizi*, proforma, `Subscriptions::limit()`
   sommato (G10b.14). Migrazione dei dati di prova.
2. **Stripe** (gestionale): modalità *flexible*, Checkout con più righe, prezzi con
   i sovrapprezzi, notifiche sugli item, operazioni sulle righe con
   `SubscriptionSchedule`, riallineamento.
3. **Ecommerce**: selezione dei servizi, riepilogo, area cliente.

Ogni piano aggiorna guide (*Abbonamenti*, *Personalizzazioni*), dati di prova (un
dominio con il nome come personalizzazione, un hosting legato, un'assistenza
mensile), CHANGELOG e TODO.

## 9. Prove

- **Unitari**: `PlanPriceRulesTest` (intervalli), `SubscriptionAmountsTest` (N
  righe, sovrapprezzi, coupon), `SubscriptionProrationTest`, regola «uno solo tra
  articolo e piano» dei legami, interruttori delle personalizzazioni,
  `Subscriptions::limit()` sommato.
- **Integrazione** sul sito di prova: `OfficeSubscriptionsTest` con aggiunta,
  tolta, cambio, vincolo, righe figlie e crediti nel proforma;
  `OnlineSubscriptionsTest` con Stripe finto e notifiche sugli item;
  `ProductModelCustomizationsTest` sulla tabella rinominata; `PlansTest` con i
  legami.
- **Browser**: Checkout con dominio + hosting + assistenza mensile su Stripe in
  modalità test; aggiunta con pro-rata dall'area cliente; tolta a fine periodo.

## 10. Cosa cambia nelle altre spec

- **G10**: G10.11 (`already_active` → limite per cliente), G10.13 (conguagli a
  scelta), G10.18 (i prodotti fisici restano fuori, ma il modello a righe c'è).
- **Architettura**: §4.13 (tabelle delle righe, `plan_price_id` tolto, legami),
  §10.5 e D44 (assorbita: un prodotto fisico sarà un tipo di riga), nuova decisione
  per questa spec.
- **TODO**: la voce «Abbonamenti con prodotti fisici» punta a questa spec.

## 11. Fuori

- Prodotti fisici come righe (spedizione e scarico a ogni rinnovo): dopo, sul
  modello a righe.
- Controllo della disponibilità di un dominio o registrazione presso un registrar:
  la personalizzazione è solo testo; un controllo vero è un servizio a parte.
- Righe con date di rinnovo diverse nello stesso abbonamento.
- Rimborsi dal gestionale (restano in Stripe e in *Da controllare*).
- Fatture: G8.
