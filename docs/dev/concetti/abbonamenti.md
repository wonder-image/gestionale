# Abbonamenti: piani, importi e limiti

Funzionalità `subscriptions` (G10). Gli abbonamenti sono **separati dagli
ordini** (D17): un rinnovo non crea un ordine, crea una riga di `gst_payments`
con `subscription_id`.

## Le tabelle

| Tabella | Cosa tiene |
|---|---|
| `gst_plans` | il piano: codice `pln_`, slug, nome, tipo fiscale, visibile sul sito, attivo |
| `gst_plan_prices` | i prezzi: codice `ppr_`, intervallo (`month`, `year`), `term_months`, vincolo, prova, prezzo, prezzo scontato, prezzo di rinnovo |
| `gst_plan_features` | i limiti: `feature_key` e `feature_value` (non `key`/`value`: `KEY` è riservata in MySQL) |
| `gst_subscriptions` | l'abbonamento: codice `sub_`, cliente, prezzo, canale, stato, fatturazione copiata, importi, date del periodo |
| `gst_subscription_plan_changes` | i cambi di piano |
| `gst_subscription_status_logs` | la storia degli stati |

Nessuna si sincronizza: i piani sono lavoro del commerciante, come i coupon, e
gli ID di Stripe (salvati con `ExternalReferences`) sono di un ambiente solo.

Gli stati sono `pending`, `trialing`, `active`, `past_due`, `unpaid`,
`cancelled`. `Subscription::LIVE` (`trialing`, `active`, `past_due`) sono gli
abbonamenti **in corso**: valgono i limiti, il piano non si elimina e
`subscriptions` non si spegne (`FeatureGuards`).

## I limiti del piano

Il sito chiede il limite quando un cliente crea qualcosa che il piano limita:

```php
use Wonder\Plugin\Gestionale\Support\Subscriptions\Subscriptions;

$max = Subscriptions::limit($customerId, 'max_progetti'); // "20", "illimitato" o null

if ($max !== null && is_numeric($max) && $progetti >= (int) $max) {
    // rifiuta la creazione
}
```

- Il valore è il testo scritto nel piano: lo interpreta il sito.
- `null` vuol dire che il cliente non ha un abbonamento in corso, o che il suo
  piano non dichiara quel limite. Cosa fare in quel caso lo decide il sito.
- Il controllo vale **solo alla creazione**: passare a un piano più basso non
  toglie niente.
- `Subscriptions::live($customerId)` dà la riga dell'abbonamento in corso.

## Gli importi di un periodo

`SubscriptionAmounts::of($planPrice, $coupon, $taxRate, $pricesIncludeTax, $charge)`
è pura: calcola il periodo come un ordine con una riga sola (`LinePrice` e
`OrderTotals`), con gli stessi arrotondamenti. Il coupon vale se
`SubscriptionAmounts::couponApplies($coupon, $charge, $termMonths)`:

- `once`: solo il primo addebito (`$charge === 0`);
- `repeating`: finché `$charge × term_months < duration_months`, come Stripe (un
  annuale con un coupon di tre mesi lo prende solo sul primo addebito);
- `forever`: sempre.

Solo coupon con `applies_subscriptions` e sconto in percentuale o importo.

## Abbonamenti online (Stripe)

Funzionalità `online_subscriptions` (richiede `online_sales` e
`subscriptions`).

- **Chiamate a Stripe** solo da `SubscriptionGateways::get()`: è
  `StripeBilling`, con le chiavi di `StripeProvider::billing()`. Nei test,
  `SubscriptionGateways::use(new FakeSubscriptionGateway())`
  (`tests/supporto/`).
- **`StripeCatalog`**: prodotto, prezzo, aliquota, coupon e codice
  promozionale, creati alla prima occorrenza e salvati con
  `ExternalReferences`. Un prezzo cambiato ha un'altra impronta
  (`price:2490-month-1-eur`), quindi è un prezzo nuovo in Stripe.
- **`OnlineSubscriptions`**:
  - `start()` crea l'abbonamento `pending` e la Checkout Session (metadati
    `subscription_id`);
  - `changePlan()`, `cancel()`, `portalUrl()`;
  - `reconcile()` (oraria, dentro `StripeReconcileTask`) e `commitments()`
    (`SubscriptionCommitmentTask`).
- **Notifiche**: `PaymentEvents` passa a `SubscriptionEvents` i tipi
  `checkout.session.completed`, `customer.subscription.*`, `invoice.paid` e
  `invoice.payment_failed`. Qui non si chiama Stripe: si legge l'oggetto, nelle
  due forme dell'API (`StripeSubscriptionData`). Gli intenti delle fatture
  (`payment_intent.*` senza `order_id`) si lasciano stare.
- **Eventi del webhook**: `PaymentEvents::types()`, cioè
  `payment_intent.succeeded`, `payment_intent.processing`,
  `payment_intent.payment_failed`, `payment_intent.canceled`,
  `charge.refunded` e i sei di `SubscriptionEvents::TYPES`. Li registra il
  modulo con `Connect::addEvents` (in `config/routes/route.api.php`); dopo un
  cambio dell'elenco va rifatto «Collega webhook» in ogni ambiente.
- **Pagamenti**: `invoice.paid` scrive una riga di `gst_payments` già `paid`,
  con `subscription_id`, `order_id = 0` e `provider_reference` = id della
  fattura.
- **Stati**: li scrive solo `SubscriptionStatus::move()`, con la storia.

## Abbonamenti da ufficio

`OfficeSubscriptions` (canale `office`, provider `manual`):

- **`create()`** parte attivo o in prova dalla data d'inizio e, senza prova,
  chiama `openPeriod()`.
- **`openPeriod()`** scrive le rate con `Installments::createForSubscription()`
  (la `PaymentSchedule` di G9, dal `current_period_start`) e manda il proforma
  (`ProformaPdf`, tipo `proforma`, con il dato di
  `DocumentData::forSubscription()`). L'addebito per il coupon si conta dai mesi
  trascorsi.
- **`registerPayment()`** passa da `Installments::register()`, che riconosce le
  rate con `subscription_id`: stessi conti delle rate degli ordini, senza
  `Ledger::sync()`.
- **`daily()`** (`OfficeSubscriptionsTask`) apre i periodi, chiude i disdetti
  e segue le rate scadute: `past_due` con il promemoria, `unpaid` oltre
  `subscription_unpaid_days`.

## Provare

- Unitari: `PlanPriceRulesTest`, `SubscriptionAmountsTest`,
  `SubscriptionModelsTest`, `SubscriptionPagesTest`, `StripeCatalogTest`,
  `StripeSubscriptionDataTest`.
- Integrazione: `php tests/integrazione/PlansTest.php` e
  `php tests/integrazione/OnlineSubscriptionsTest.php` (Stripe finto, notifiche
  simulate) e `php tests/integrazione/OfficeSubscriptionsTest.php`, dopo
  `php forge update`.
- Dati di prova: `php forge gestionale:demo` crea i piani «Base» e «Pro».
