# G10 piano 1 — Catalogo dei piani

Spec: [abbonamenti](../specs/2026-10-13-abbonamenti-design.md) §1, §4 e §6
(G10.3–G10.7, G10.17). Ramo `g10-piano-1`, sopra `g10-abbonamenti` (la spec). Si
unisce su `main` dopo la `1.0.0` (G10.2).

## Cosa fa

Piani con prezzi e limiti, le tabelle degli abbonamenti, gli importi di un periodo,
i coupon con durata e la lettura dei limiti per il sito. Gli abbonamenti si
**leggono** soltanto: li creano i piani 2 (online) e 3 (da ufficio). In questo
piano li scrivono i test.

## Task

1. **Dati.**
   - `Plan` (`gst_plans`, codice `pln_`), `PlanFeature` (`gst_plan_features`, con
     `feature_key` e `feature_value`: `key` è una parola riservata di MySQL),
     `PlanPrice` (`gst_plan_prices`, codice `ppr_`: serve al dataLayer del piano 2
     come `item_id`).
   - `Subscription` (`gst_subscriptions`, codice `sub_`, indirizzo di fatturazione
     come l'ordine), `SubscriptionPlanChange`, `SubscriptionStatusLog`.
   - Nessuna sincronizzazione: piani e abbonamenti sono lavoro del commerciante,
     come i coupon, e gli ID di Stripe sono di un ambiente solo.
   - `gst_coupons` prende `applies_subscriptions`, `duration` e `duration_months`.
   - `subscriptions` diventa costruita.
   - Gli stati, i canali e i provider dell'abbonamento si dichiarano tutti adesso,
     con `Subscription::LIVE` (`trialing`, `active`, `past_due`) letto dai limiti e
     dalle guardie.
2. **Regole pure.**
   - `PlanPriceRules::assert()`:
     - prezzo ≥ 0;
     - prezzo scontato minore del prezzo;
     - `term_months` coerente con l'intervallo (1–12 per il mese, multipli di 12 per
       l'anno);
     - vincolo e prova non negativi;
     - prezzo di rinnovo diverso da sé e di un piano vivo.
   - `SubscriptionAmounts::of()`: un periodo come una riga d'ordine, con
     `LinePrice` e `OrderTotals`. Restituisce subtotale, sconto del coupon,
     imponibile, imposta e totale.
   - `SubscriptionAmounts::couponApplies()`: il coupon vale in quell'addebito?
     `once` solo il primo, `repeating` finché i mesi già coperti
     (`addebito × term_months`) sono meno di N, `forever` sempre. Un annuale con un
     coupon di tre mesi lo prende solo sul primo addebito, come in Stripe.
   - Test unitari.
3. **Vendite → Piani** (`PlanResource`, con `subscriptions`):
   - elenco con nome, prezzi in breve, visibile online e stato;
   - modulo con piano e tipo fiscale, prezzi in un repeater e limiti in un repeater
     chiave/valore;
   - un prezzo usato da un abbonamento, anche chiuso, non si toglie dal repeater
     (si spegne);
   - un piano con abbonamenti vivi non si elimina.
4. **Vendite → Abbonamenti** (`SubscriptionResource`, sola lettura):
   - elenco con codice, cliente, piano e prezzo, stato, prossimo rinnovo, totale e
     canale;
   - filtri per stato e canale;
   - scheda con periodo, pagamenti (`gst_payments.subscription_id`), cambi di piano
     e storia degli stati.
5. **Coupon con durata.** Nella scheda del coupon, l'interruttore «Vale per gli
   abbonamenti» con la durata (una volta, per N mesi, per sempre), visibile solo
   con `subscriptions`.
   - Rifiutati: la spedizione gratuita per gli abbonamenti, e `repeating` senza
     mesi.
   - Per gli ordini non cambia niente.
6. **Limiti e guardia.**
   - `Subscriptions::live($customerId)` e `Subscriptions::limit($customerId, $key)`.
   - `FeatureGuards`: `subscriptions` non si spegne con abbonamenti vivi.
7. **Contorno.**
   - Dati di prova: due piani, ognuno con un prezzo mensile e uno annuale, e i
     limiti.
   - Guida `vendite-abbonamenti.md` e ritocco alla guida dei coupon; guida
     sviluppatori dei limiti.
   - CHANGELOG, TODO.
   - Test d'integrazione `PlansTest`.
   - PR.

## Test d'integrazione (sul sito di prova)

- piano con due prezzi salvato e riletto; codici dei prezzi;
- regole dei prezzi rifiutate al salvataggio;
- prezzo in uso che non si toglie, piano con abbonamenti vivi che non si elimina;
- importi di un periodo con coupon `once`, `repeating`, `forever`, IVA inclusa ed
  esclusa;
- `limit()` con abbonamento vivo, scaduto e senza abbonamento;
- guardia di `subscriptions`.
