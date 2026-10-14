# G10 + E3 — Abbonamenti

**Stato:** decisioni G10.1–G10.19 approvate il 2026-10-13, tutte come consigliate.
Design scritto lo stesso giorno. Resta aperta una domanda per i test (§7): le
chiavi di prova di Stripe sul sito di prova.

Riferimenti: [architettura](2026-09-11-gestionale-ecommerce-architettura-design.md)
§4.13 (abbonamenti, D17, D44), §5.5 (flussi online e da ufficio), §4.6 (coupon),
§10.3 (D63); [G9](2026-10-13-vendite-da-ufficio-design.md) §1 (condizioni di
pagamento, `PaymentSchedule`, Scadenzario) e §3.3 (`DocumentPdf`);
[pagamento con Stripe](2026-10-09-pagamento-stripe-design.md) (notifiche e
riallineamento); [pannello account](2026-10-08-pannello-account-design.md);
regole del frontend dell'ecommerce (`.claude/rules/frontend-seo-tracking.md`).

## Contesto

G10 porta gli **abbonamenti**: piani con prezzi ricorrenti, sottoscritti dal
cliente online (E3, nell'ecommerce, con Stripe) o creati dall'operatore da ufficio
(senza Stripe, con pagamenti in attesa e proforma). Gli abbonamenti sono **separati
dagli ordini** (D17): un rinnovo non crea un ordine, crea un pagamento.

**Senza fatture:** finché non c'è G8, un rinnovo pagato è una riga di
`gst_payments` e basta (G10.5).

**Quando si unisce (G10.2): dopo la `1.0.0`**, come G9. Il piano 2 tocca la
ricezione delle notifiche di Stripe, la stessa del checkout degli ordini: prima
della consegna del 29 ottobre non si rischia. Tutto sta dietro `subscriptions` e
`online_subscriptions`, spente di serie (vincolo di D63).

### Cosa c'è già nel codice

- **Pagamenti.** `gst_payments` ha già `subscription_id` accanto a `order_id`,
  `due_date`, `status`, `provider`, `provider_reference`. `Ledger` registra e
  rimborsa.
- **Codici.** `Codes::SUBSCRIPTION` (`sub_`) e `Codes::PLAN` (`pln_`) esistono.
- **Funzionalità.** `subscriptions` è in `config/features.php` con
  `created => false`. `online_subscriptions` non è dichiarata da nessuno: oggi
  l'ecommerce non dichiara funzionalità sue, e il canale online è `online_sales`
  del gestionale (G6), non `online_store` dell'architettura (§6).
- **Stripe.**
  - Il gestionale riceve le notifiche in `http/api/stripe/webhook.php`:
    `StripeProvider` le legge, `PaymentEvents::handle()` le smista. Oggi gestisce
    solo `payment_intent.*`.
  - `ProviderEvents` le registra una volta sola.
  - `Reconcile` riallinea dal cron quando una notifica non arriva.
- **Riferimenti esterni.** `gst_external_references` con `ExternalReferences`, per
  gli ID di prodotti, prezzi e coupon in Stripe.
- **Core.** `Wonder\Plugin\Stripe\Stripe` (involucro generico di stripe-php),
  `BillingPortal`, `PaymentIntent`, `Connect`. Mancano Checkout Session e
  Subscription: si usano con l'involucro generico, o si aggiungono al core come
  lavoro preparatorio (D58) se serve.
- **Coupon.** `gst_coupons` non ha la durata (`duration`, `duration_months`).
- **Ecommerce.** Area cliente con sezioni (`EcommerceAccountExtension`), punto
  unico del dataLayer (`Frontend\Tracking\DataLayer::script()`), regole SEO del
  frontend.
- **Da G9** (sul suo ramo, non ancora su `main`): condizioni di pagamento,
  `PaymentSchedule` (pura, non legata all'ordine), Scadenzario, `DocumentPdf`.

## Decisioni

| # | Decisione |
|---|---|
| G10.1 | Tre piani: catalogo dei piani; abbonamenti online (E3); abbonamenti da ufficio, per ultimo perché riusa `PaymentSchedule` (G9 piano 1) e `DocumentPdf` (G9 piano 3). |
| G10.2 | Si unisce su `main` dopo la `1.0.0`. |
| G10.3 | Piani, prezzi e limiti come §4.13 dell'architettura, in *Vendite → Piani*. |
| G10.4 | Prezzo con la regola dei prodotti (IVA inclusa o esclusa come il listino base), aliquota dalla categoria del piano; solo IVA italiana per ora. |
| G10.5 | Nessuna fattura fino a G8: un rinnovo pagato è una riga di pagamento. |
| G10.6 | Coupon con durata (una volta, N mesi, per sempre); online diventano coupon e codici promozionali di Stripe. |
| G10.7 | Limiti del piano dichiarati dal gestionale ed esposti al sito; il sito li controlla solo alla creazione; un piano più basso non cancella niente. |
| G10.8 | Online con Stripe Checkout in modalità abbonamento (pagina di Stripe). |
| G10.9 | Piani e prezzi creati in Stripe dal gestionale alla prima occorrenza; un prezzo cambiato è un prezzo nuovo in Stripe; gli abbonati restano sul vecchio fino al cambio piano. |
| G10.10 | Carta (con Link); SEPA dopo. |
| G10.11 | Account obbligatorio per abbonarsi. |
| G10.12 | Stati e notifiche come §4.13; riallineamento orario; email del rinnovo non riuscito con il link al portale. |
| G10.13 | Cambio piano immediato, nuovo importo dal rinnovo dopo, senza conguagli; disdetta = rinnovo spento, valido fino a fine periodo (in vincolo: a fine vincolo); a fine vincolo il prezzo di rinnovo. |
| G10.14 | Area cliente: sezione *Abbonamenti* (piano, rinnovo, pagamenti, cambio piano, disdetta); carta dal portale di Stripe. |
| G10.15 | Pagina pubblica dei piani con le regole SEO, schema.org, breadcrumb e dataLayer del progetto (§5). |
| G10.16 | Da ufficio come §5.5 dell'architettura: pagamento in attesa a ogni periodo con la scadenza dalla condizione di pagamento, proforma PDF per email, promemoria, `past_due` → `unpaid` dopo 15 giorni (impostazione), annullamento deciso dal commerciante; le rate nello Scadenzario di G9. |
| G10.17 | `subscriptions` diventa costruita; `online_subscriptions` richiede il canale online e `subscriptions` (§6). Spente, non compare niente. |
| G10.18 | Fuori: prodotti fisici negli abbonamenti, SEPA, IVA estera e OSS, fatture. |
| G10.19 | Test d'integrazione sul sito di prova; quelli online con Stripe in modalità test e notifiche simulate. |

## 1. Catalogo dei piani (piano 1)

### 1.1 Dati

Come §4.13 dell'architettura, con il prefisso `gst_`:

| Tabella | Colonne |
|---|---|
| `gst_plans` | code `pln_`, slug, name, description, tax_category_id, visible_online, active, position, deleted |
| `gst_plan_features` | plan_id, key, value |
| `gst_plan_prices` | plan_id, name, billing_interval (`month`, `year`), term_months, commitment_months, trial_days, price, sale_price, renewal_plan_price_id, active, position |
| `gst_subscriptions` | code `sub_`, customer_id, plan_price_id, channel (`online`, `office`), status, provider (`stripe`, `manual`), payment_method_id, payment_term_id, coupon_id, coupon_code, dati `billing_` copiati, prices_include_tax, subtotal, discount_total, tax_id, tax_country, tax_rate, tax_total, total, currency, started_at, trial_ends_at, commitment_ends_at, current_period_start, current_period_end, next_renewal_at, auto_renew, cancel_requested_at, cancelled_at, ended_at, provider_reference, note |
| `gst_subscription_plan_changes` | subscription_id, old_plan_price_id, new_plan_price_id, user_id, source, changed_at |
| `gst_subscription_status_logs` | come gli altri log di stato |

- Rispetto all'architettura: `current_period_start` (serve al proforma e al
  rinnovo da ufficio) e `cancel_requested_at` (la disdetta chiesta durante il
  vincolo, G10.13).
- `term_months` dice quanti mesi copre un addebito (1 per il mensile, 12 per
  l'annuale); `commitment_months` 0 = nessun vincolo.

### 1.2 Pagine

- **Vendite → Piani** (`PlanResource`, con `subscriptions`): elenco e scheda con i
  prezzi in un repeater e i limiti in coppie chiave/valore. Un prezzo usato da un
  abbonamento non si elimina (si spegne con `active`); un piano con abbonamenti
  vivi neppure.
- **Vendite → Abbonamenti** (`SubscriptionResource`): elenco con cliente, piano,
  stato, prossimo rinnovo, totale, canale; filtri per stato e canale. Scheda con
  periodi, pagamenti, cambi di piano e storia degli stati.
- **Regola pura** `PlanPriceRules`: prezzo ≥ 0, prezzo scontato minore del prezzo,
  `renewal_plan_price_id` di un prezzo dello stesso piano o di un altro piano vivo,
  intervallo coerente con `term_months`.

### 1.3 Importi

`SubscriptionAmounts::of($planPrice, $coupon, $taxRate, $pricesIncludeTax)`, pura:
subtotale, sconto del coupon nel periodo, imposta, totale, con le regole di
arrotondamento di `LinePrice`. L'IVA viene dalla categoria del piano (G10.4), solo
italiana: `tax_country` = `IT`.

### 1.4 Coupon con durata (G10.6)

`gst_coupons` prende `duration` (`once`, `repeating`, `forever`, predefinito
`once`) e `duration_months`. Valgono solo per gli abbonamenti; per gli ordini non
cambia niente. Un coupon vale per gli abbonamenti se il suo ambito comprende i
piani (scelta nuova nella scheda del coupon: «Vale per gli abbonamenti»).

### 1.5 Limiti (G10.7)

`Subscriptions::limit(int $customerId, string $key): ?string`: il valore del
limite dal piano dell'abbonamento vivo del cliente (`trialing`, `active`,
`past_due`), `null` senza abbonamento. È documentato nella guida sviluppatori.
Il controllo lo fa il sito quando crea qualcosa; un piano più basso non toglie
niente.

## 2. Abbonamenti online, E3 (piano 2)

### 2.1 Sottoscrizione

1. Il cliente, **con l'account** (G10.11), sceglie piano e prezzo nella pagina dei
   piani (§5).
2. Inserisce o conferma i dati di fatturazione nel sito
   (`AddressExtension::billing()`), come nel checkout.
3. Il gestionale crea l'abbonamento `pending` e una **Checkout Session** di Stripe in
   modalità `subscription`. Usa il cliente Stripe dell'account (creato se manca,
   ID in `external_references`), il prezzo Stripe (§2.2), la prova gratuita
   (`trial_period_days`), i codici promozionali consentiti e il metodo carta (G10.10).
   Poi il cliente va sulla pagina di Stripe.
4. Al ritorno sul sito la pagina dice «stiamo confermando» finché la notifica non
   arriva. La verità la decidono le notifiche, non il ritorno.

### 2.2 Piani e prezzi in Stripe (G10.9)

`StripeCatalog::priceFor(int $planPriceId)`: alla prima occorrenza crea il
prodotto (dal piano) e il prezzo ricorrente (importo, valuta, intervallo, IVA
inclusa o esclusa con un'aliquota Stripe creata quando serve) e salva gli ID in
`external_references`. Un prezzo cambiato nel gestionale crea **un prezzo nuovo**
in Stripe al prossimo uso; il vecchio resta agli abbonati esistenti. Lo stesso per
coupon e codici promozionali (§1.4).

### 2.3 Notifiche

`PaymentEvents` impara gli eventi degli abbonamenti, con `ProviderEvents` per non
elaborarli due volte:

| Evento | Effetto |
|---|---|
| `checkout.session.completed` | collega l'abbonamento Stripe (`provider_reference`) |
| `customer.subscription.created` / `updated` | stato, periodo, prova, `auto_renew`, piano |
| `invoice.paid` | riga di `gst_payments` `paid` con `subscription_id`; abbonamento `active`; nessuna riga per importo zero o prova |
| `invoice.payment_failed` | `past_due`; email con il link al portale |
| `customer.subscription.deleted` | `cancelled` (o `unpaid` se i tentativi sono esauriti) |

- **Riallineamento orario** (`Reconcile`): gli abbonamenti `pending` o
  `past_due` da più di un'ora si rileggono da Stripe.
- **Stati** come §4.13 dell'architettura: `pending`, `trialing`, `active`,
  `past_due`, `unpaid`, `cancelled`; log in `gst_subscription_status_logs`.

### 2.4 Cambio piano e disdetta (G10.13)

- **Cambio piano** dall'area cliente o dal backend: aggiornamento dell'abbonamento
  Stripe con `proration_behavior = none` (nuovo importo dal rinnovo dopo), riga in
  `gst_subscription_plan_changes`, coupon mantenuto.
- **Disdetta:** `cancel_at_period_end` in Stripe, `auto_renew` spento. Durante il
  vincolo la disdetta si registra (`cancel_requested_at`) e diventa effettiva a fine
  vincolo: un'attività la passa a Stripe quando il vincolo finisce.
- **Fine vincolo:** con `renewal_plan_price_id` l'abbonamento passa a quel prezzo
  dal rinnovo dopo.

### 2.5 Area cliente (G10.14)

Sezione **Abbonamenti** nel pannello dell'account (`EcommerceAccountExtension`):
piano e prezzo, stato, prossimo rinnovo, pagamenti, *Cambia piano* (prezzi dello
stesso piano e degli altri piani visibili), *Disdici* (con la data in cui finisce),
*Aggiorna la carta* (portale di Stripe con `BillingPortal` del core). Pagina
privata: `NOINDEX,NOFOLLOW`, nessun dato strutturato, `$SEO->breadcrumb = []`.

### 2.6 Email

- conferma dell'abbonamento (prima attivazione);
- rinnovo non riuscito, con il link al portale;
- disdetta registrata, con la data di fine.

Con i testi modificabili come quelli degli ordini.

## 3. Abbonamenti da ufficio (piano 3)

Dopo G9 piani 1 e 3 (`PaymentSchedule`, `DocumentPdf`).

- **Creazione** dal backend (*Nuovo abbonamento*): cliente, prezzo del piano, data
  d'inizio, metodo e condizione di pagamento (predefiniti dal cliente, G9.2),
  coupon. `channel` `office`, `provider` `manual`.
- **A ogni periodo** un'attività giornaliera (scheduler del core):
  1. crea il pagamento in attesa del periodo, con scadenze e importi da
     `PaymentSchedule` sulla condizione di pagamento, partendo da
     `current_period_start`;
  2. genera il **proforma** (`ProformaPdf` su `DocumentPdf`): non è un documento
     fiscale, riporta importo, scadenza, IBAN del conto del metodo e periodo coperto;
  3. lo manda per email al cliente.
- **Pagamento registrato** (dallo Scadenzario di G9 o dalla scheda): il periodo è
  coperto e il prossimo si prepara alla data di rinnovo.
- **Scaduto:** la rata non pagata porta a `past_due` con un promemoria email; dopo N
  giorni (impostazione tecnica, predefinita 15) a `unpaid`. L'annullamento lo decide
  il commerciante.
- **Disdetta:** rinnovo spento, fine alla chiusura del periodo.
- Le rate degli abbonamenti compaiono nello **Scadenzario** di G9 (che legge
  `subscription_id` accanto a `order_id`).

## 4. Prova gratuita, vincolo e regole comuni

Da §4.13 dell'architettura:

- attivo al primo pagamento riuscito; con prova gratuita il primo addebito è a fine
  prova; nessun pagamento registrato per prova o importo zero;
- una prova si usa una volta per cliente e per piano (controllo nel gestionale
  prima della Checkout Session);
- con il vincolo, la disdetta vale a fine vincolo (§2.4).

## 5. Pagina pubblica dei piani (E3, frontend)

Le quattro domande delle regole del frontend:

1. **SEO.** Pagina indicizzabile (`INDEX,FOLLOW`), URL tradotto da `urls.json`
   (`/abbonamenti/` in italiano), `$SEO->title` e `$SEO->description` da
   `lang/*/ecommerce.json` (entro 140 caratteri), canonical assoluto senza
   parametri, un solo `<h1>`, i piani resi lato server. Solo i piani con
   `visible_online` e i prezzi attivi.
2. **schema.org.** Un `ItemList` di `Product` (uno per piano) con `offers`: per ogni
   prezzo un `Offer` con `price`, `priceCurrency` e `priceSpecification`
   (`UnitPriceSpecification` con `billingDuration` e `billingIncrement`, e il
   prezzo scontato se mostrato). Gli stessi importi e la stessa valuta visibili in
   pagina; niente recensioni.
3. **Breadcrumb.** `Home › Abbonamenti` in `$SEO->breadcrumb`, mostrato anche a
   video con gli stessi nomi; l'ultimo elemento è il canonical. Il JSON-LD lo
   genera `breadcrumb()` del core.
4. **dataLayer** (con `DataLayer::script()`, dati di pagina nel punto condiviso):
   - `view_item_list` alla vista, con un item per prezzo (`item_id` = codice del
     prezzo, `item_name` = piano e prezzo, `price` numerico, `item_category`
     «abbonamento»);
   - `select_item` e `begin_checkout` quando il cliente sceglie un prezzo, prima di
     andare su Stripe;
   - `purchase` **una sola volta**, sulla pagina di ritorno quando la notifica ha
     confermato l'abbonamento, con `transaction_id` = codice dell'abbonamento
     (ricaricare la pagina non lo ripete);
   - `ecommerce: null` prima di ogni evento, utente come id interno o `null`.

Test in `tests/` per metadati, JSON-LD e push al dataLayer; guida del frontend
aggiornata.

## 6. Funzionalità

| Funzionalità | Dove | Richiede | Cosa accende |
|---|---|---|---|
| `subscriptions` (diventa costruita) | gestionale | — | *Piani*, *Abbonamenti*, coupon con durata, limiti; da ufficio anche *Nuovo abbonamento* e proforma |
| `online_subscriptions` (nuova) | gestionale, area *Canali di vendita* | `online_sales`, `subscriptions` | pagina dei piani, sottoscrizione con Stripe, sezione dell'area cliente, notifiche degli abbonamenti |

`online_subscriptions` sta nel catalogo del gestionale accanto a `online_sales`,
perché l'ecommerce non dichiara funzionalità sue (§Cosa c'è già): è lo stesso
adattamento già fatto per il canale online. Spente, non compare niente. La guardia
del pannello (G3.18) non spegne `subscriptions` con abbonamenti vivi.

## 7. Piani e prove

| Piano | Dove | Contenuto | Test d'integrazione |
|---|---|---|---|
| 1 | gestionale | dati, *Piani*, *Abbonamenti* in lettura, importi, coupon con durata, limiti | piani e prezzi, regole, importi con coupon, limiti |
| 2 | gestionale ed ecommerce | Stripe (catalogo, Checkout Session, notifiche, riallineamento), cambio piano e disdetta, area cliente, pagina dei piani, email | notifiche simulate (attivazione, rinnovo, fallito, disdetta), riallineamento, prova usata una volta |
| 3 | gestionale | abbonamenti da ufficio, periodi, proforma, promemoria, `past_due` → `unpaid` | periodi e pagamenti in attesa, scadenza, pagamento registrato |

- **Domanda aperta (G10.19):** i test online vogliono le chiavi di **prova** di
  Stripe sul sito di prova (`ecommerce-site`). Da confermare con l'utente prima del
  piano 2.
- **Prove nel browser:** sottoscrizione con una carta di prova (anche con
  autenticazione), prova gratuita, cambio piano, disdetta, portale; da ufficio un
  periodo con il proforma e il pagamento registrato.
- Ogni piano aggiorna guide, dati di prova (due piani con prezzi mensile e annuale,
  un abbonamento da ufficio), CHANGELOG e TODO; il piano 2 anche il TODO
  dell'ecommerce (E3).

## 8. Fuori da G10

- Abbonamenti con prodotti fisici (spedizione e scarico a ogni rinnovo).
- SEPA Direct Debit.
- IVA estera e OSS per i privati UE.
- Fatture degli abbonamenti: G8.
