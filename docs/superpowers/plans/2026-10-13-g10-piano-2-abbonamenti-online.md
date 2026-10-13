# G10 piano 2 — Abbonamenti online (E3)

Spec: [abbonamenti](../specs/2026-10-13-abbonamenti-design.md) §2, §4, §5 e §6
(G10.8–G10.15, G10.17). Ramo `g10-piano-2` del gestionale, sopra `g10-piano-1`;
ramo `g10-abbonamenti-online` dell'ecommerce, sopra `main`. Si unisce su `main`
dopo la `1.0.0` (G10.2).

## Cosa fa

Il cliente con l'account sceglie un prezzo nella pagina dei piani, conferma i dati
di fatturazione e paga sulla pagina di Stripe (Checkout in modalità abbonamento).
Le notifiche di Stripe decidono lo stato. Dall'area cliente si cambia piano, si
disdice e si aggiorna la carta (portale di Stripe).

**Il gestionale non chiama mai Stripe dentro una notifica:** gli eventi si leggono
dal loro contenuto. Così le notifiche simulate dei test non hanno bisogno delle
chiavi. Le chiamate a Stripe passano tutte da `SubscriptionGateway`, che nei test
è un finto.

## Task

### Gestionale

1. **Funzionalità e dati.**
   - `online_subscriptions` in *Canali di vendita*: richiede `online_sales` e
     `subscriptions`.
   - Nessuna tabella nuova: gli ID di Stripe stanno in `gst_external_references`.
     Un prezzo cambiato è un altro `object_type` (l'impronta di importo, intervallo
     e IVA), quindi un prezzo nuovo in Stripe.
   - `gst_subscriptions` prende `environment` (`live`, `test`), come i pagamenti:
     il riallineamento tocca solo gli abbonamenti dell'ambiente attivo.
2. **Gateway.**
   - `SubscriptionGateway` (interfaccia) e `StripeBilling` (stripe-php sul conto
     collegato, con le chiavi dell'ambiente come `StripeProvider`).
   - Operazioni: prodotto, prezzo, aliquota, coupon, codice promozionale, cliente,
     Checkout Session, lettura e modifica dell'abbonamento, sessione del portale.
   - Nei test si usa un finto, `SubscriptionGateways::use()`.
3. **`StripeCatalog`.**
   - `priceFor($planPriceId)`: prodotto dal piano, prezzo ricorrente e aliquota IVA
     inclusa o esclusa, creati alla prima occorrenza.
   - `promotionCodeFor($couponId)`: coupon e codice promozionale con la durata.
4. **`OnlineSubscriptions`.**
   - `start($customerId, $planPriceCode, $billing, $couponCode, $urls)`:
     - controlla account, piano visibile e attivo, prezzo attivo, nessun
       abbonamento vivo sullo stesso piano;
     - la prova vale solo se il cliente non ha mai avuto quel piano (§4): conta un
       abbonamento partito davvero (`started_at` scritto), non un `pending`
       abbandonato;
     - crea l'abbonamento `pending` con fatturazione e importi;
     - crea la Checkout Session e restituisce l'URL di Stripe.
   - `changePlan()`: `proration_behavior = none`, una riga in
     `gst_subscription_plan_changes`, coupon mantenuto.
   - `cancel()`: `cancel_at_period_end`; durante il vincolo registra
     `cancel_requested_at`.
   - `portalUrl()`.
5. **Notifiche** (`SubscriptionEvents`, chiamata da `PaymentEvents`):
   - `checkout.session.completed`: collega l'abbonamento Stripe;
   - `customer.subscription.created` e `customer.subscription.updated`: stato,
     periodo, prova, rinnovo automatico, prezzo (un cambio fatto altrove si
     registra);
   - `invoice.paid`: una riga di `gst_payments` già `paid` con `amount_paid` della
     fattura (la verità è Stripe: una differenza dal totale atteso si segnala, non
     fa fallire l'evento), `provider_reference` = id della fattura; niente per
     importo zero. Abbonamento `active`, email di conferma alla prima attivazione.
     Le righe degli abbonamenti nascono già pagate, quindi
     `Reconcile::payments()`, che legge solo `pending` e `failed`, non le tocca;
   - `invoice.payment_failed`: `past_due` ed email con il link all'area cliente;
   - `customer.subscription.deleted`: `cancelled`, oppure `unpaid` se chiuso per
     mancato pagamento.
   - Stati nel log con `source = webhook`.
   - Il contenuto cambia con la versione dell'API dell'endpoint: il periodo sta
     sull'abbonamento o sulla sua voce, il legame fattura → abbonamento in
     `subscription` o in `parent.subscription_details`. Si leggono tutti e due, e
     i test hanno un evento per forma.
6. **Riallineamento e attività.**
   - `Reconcile`: gli abbonamenti `pending` o `past_due` da più di un'ora si
     rileggono da Stripe. Un `pending` senza abbonamento Stripe dopo 24 ore (la
     Checkout Session è scaduta) diventa `cancelled`, con una riga nel log: ogni
     clic su «Abbonati» crea una riga, e le abbandonate non restano.
   - `SubscriptionCommitmentTask` (giornaliera, spenta di serie):
     - a fine vincolo passa la disdetta chiesta a Stripe;
     - oppure, con `renewal_plan_price_id`, cambia il prezzo.
7. **Email** (`SubscriptionEmail` e `SubscriptionNotifier`), testi in
   `lang/it/gestionale.json`:
   - conferma;
   - rinnovo non riuscito;
   - disdetta registrata.
8. **Contorno.**
   - Guida `vendite-abbonamenti.md` (online) e guida sviluppatori.
   - CHANGELOG, TODO.
   - Test unitari: `StripeCatalog` con il finto, mappatura degli eventi, email.
   - Test d'integrazione `OnlineSubscriptionsTest`: notifiche simulate,
     riallineamento con il finto, prova usata una volta.
   - PR.

### Ecommerce (frontend: le quattro domande sono qui sotto)

9. **Pagina dei piani** `/abbonamenti/` (`SubscriptionsController`), con
   `online_subscriptions`.
10. **Sottoscrizione.**
    - `/abbonamenti/{prezzo}/`: chiede l'account e mostra il riepilogo, i dati di
      fatturazione (come nel checkout) e il codice sconto;
    - il POST manda a Stripe;
    - `/abbonamenti/conferma/?abbonamento=…` dice «stiamo confermando» finché la
      notifica non arriva.
11. **Area cliente → Abbonamenti:**
    - piano, stato, prossimo rinnovo e pagamenti;
    - *Cambia piano*, *Disdici*, *Aggiorna la carta*.
12. **Contorno.**
    - lang;
    - test per metadati, JSON-LD e dataLayer;
    - `docs/`;
    - TODO dell'ecommerce (E3), CHANGELOG;
    - PR.

## Scelte rispetto alla spec

- *Cambia piano* e *Disdici* **dal backend** vanno nel piano 3, insieme agli
  abbonamenti da ufficio, che ne hanno bisogno anche loro. In questo piano ci
  sono il servizio e l'area cliente.
- **Rimborsi** di un addebito di abbonamento: si fanno da Stripe; la notifica
  `charge.refunded` non trova un ordine e finisce in *Da controllare*, come oggi
  per un intento sconosciuto. La registrazione automatica arriva con G8.
- L'endpoint del webhook in Stripe deve ricevere gli eventi nuovi
  (`checkout.session.completed`, `customer.subscription.*`, `invoice.paid`,
  `invoice.payment_failed`): da scrivere nelle note di rilascio.

## Le quattro domande del frontend

| Pagina | SEO | schema.org | Breadcrumb | dataLayer |
|---|---|---|---|---|
| `/abbonamenti/` | `INDEX,FOLLOW`, titolo e descrizione da lang, canonical assoluto senza parametri, un `<h1>`, piani resi lato server | `ItemList` di `Product` con un `Offer` per prezzo (`UnitPriceSpecification` con `billingDuration`, `billingIncrement`, `unitCode`), importi visibili | `Home › Abbonamenti`, visibile e JSON-LD dal core | `view_item_list` con un item per prezzo (`item_id` = codice `ppr_`); `select_item` al clic su un prezzo |
| `/abbonamenti/{prezzo}/` | `NOINDEX,NOFOLLOW` (flusso privato) | nessuno | `$SEO->breadcrumb = []`, visibile `Abbonamenti › piano` | `begin_checkout` alla vista, prima di Stripe |
| `/abbonamenti/conferma/` | `NOINDEX,NOFOLLOW` | nessuno | `[]` | `purchase` una volta sola (`transaction_id` = codice `sub_`), quando la notifica ha confermato; la sessione ricorda i codici già inviati |
| area cliente | `NOINDEX,NOFOLLOW` (del pannello) | nessuno | `[]` | solo i dati di pagina |

## Test d'integrazione

- Notifiche simulate, con la firma saltata da `SubscriptionEvents::apply()`:
  attivazione con prova, primo pagamento, rinnovo, rinnovo fallito, disdetta,
  chiusura per mancato pagamento, evento ripetuto.
- Riallineamento con il finto.
- Prova negata a chi ha già avuto il piano.
- Cambio piano e disdetta durante il vincolo.

Le prove con Stripe vero (Checkout, portale, carta con autenticazione) aspettano le
chiavi di prova sul sito di prova (domanda aperta, G10.19).
