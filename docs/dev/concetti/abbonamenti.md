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
gli ID di Stripe (in `gst_external_references`) sono di un ambiente solo.

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

## Provare

- Unitari: `PlanPriceRulesTest`, `SubscriptionAmountsTest`,
  `SubscriptionModelsTest`, `SubscriptionPagesTest`.
- Integrazione: `php tests/integrazione/PlansTest.php` (dopo `php forge update`).
- Dati di prova: `php forge gestionale:demo` crea i piani «Base» e «Pro».
