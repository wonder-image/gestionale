# Ordine «In pagamento» e «Abbandonato»

Data: 2026-10-19. Rami: gestionale `fix/ordine-online-user-id`, ecommerce
`fix/pagamento-riprova-stesso-ordine` (si aggiunge ai punti 13 e 14 già
committati lì). Regole concordate con l'utente il 2026-10-19.

## Contesto

Oggi un ordine online nasce in `Checkout::create()` già come ordine vero:
`stage = 'order'`, `status = 'pending'` («In attesa»), con `order_number` e
`ordered_at`. Il cliente però non ha ancora pagato. Due conseguenze che
l'utente non vuole:

- un pagamento rifiutato lascia nel pannello ordini un ordine «In attesa» che il
  commerciante legge come «in attesa di un tuo comando»;
- il numero d'ordine si consuma anche per i carrelli che non verranno mai pagati.

Nel lavoro del punto 13 il rifiuto resta sullo stesso ordine (si riprova lo
stesso intento) e compare il bottone «Modifica l'ordine». Con questa spec il
bottone sparisce: il checkout resta sempre modificabile.

### Cosa c'è già nel codice

- `Order::STATUSES` e `LIVE_STATUSES` (`src/Models/Sales/Order.php`), etichette
  in `StatusLabels::ORDER`.
- `Checkout::place()` / `create()`: prenotazione (`Allocation::reserve` con
  scadenza da `PaymentTiming`), numero, stato, `StatusLogger`, `Ledger::open`,
  `writeTaxSummaries`. Email solo per gli ordini manuali.
- `OnlinePayments::start()`, `succeeded()` (controlli `PaymentMismatch`,
  `Ledger::register`, `Allocation::keep`, email `paid` e `merchant_paid`),
  `fail()`, `alert()`, `refunded()`.
- `PaymentEvents::apply` (webhook): `succeeded`, `payment_failed`, `canceled`,
  `charge.refunded`. **`payment_intent.processing` non è gestito.** `Reconcile`
  guarda solo SUCCEEDED e CANCELED.
- `Lifecycle::reopen()` (annulla prima l'intento, `order.payment_in_progress`
  se SUCCEEDED o PROCESSING), `reopenable()`, `cancel()`.
- `Expiry::run()`: prenotazioni scadute, poi `deadline()` sui `pending` con
  `$silent` per chi aspetta un pagamento online.
- Ecommerce: `OnlinePayment::pending()`, `settle()`, `reopen()`,
  `dropPending()`; `CheckoutPlacement`; `CheckoutController` (`index`, `pay`,
  `reopen`, `declined`); `resources/assets/js/checkout.js` (`reopen()`,
  `reportDeclined()`).
- Filtri della lista: `TableLayoutSchema::filterQuery(..., $value)`; il valore
  di default vale anche senza GET (`FilterCustom::condition`).

## Decisioni

| # | Decisione |
|---|---|
| D1 | Due stati nuovi dell'ordine: `awaiting_payment` («In pagamento») e `abandoned` («Abbandonato»). Nessuno dei due entra in `LIVE_STATUSES`. |
| D2 | Un ordine online nasce `awaiting_payment`, senza numero e senza `ordered_at`, senza email. |
| D3 | Diventa `pending`, prende numero e `ordered_at` e manda le email quando il cliente ha fatto tutto dalla sua parte (§3). |
| D4 | Abbandono (scadenza, nuovo checkout, express sopra un ordine in pagamento) → `abandoned`: niente email, intento annullato, merce e coupon liberati. |
| D5 | `awaiting_payment` non si vede mai nel backend, nell'area cliente, nell'MCP; `abandoned` si vede solo col filtro «Pagamenti non riusciti». |
| D6 | Niente «Modifica l'ordine»: ogni modifica del checkout riapre da sola l'ordine in pagamento (`Lifecycle::reopen`). |
| D7 | I rifiuti restano nella storia dello stesso ordine (punto 14, già fatto). |
| D8 | Metodi Stripe DELAYED: la regola è scritta (§3.4), l'attivazione in `StripeMethods::choices()` resta fuori. |

Parole: in questa spec «ordine in lavorazione» è lo stato `processing`
dell'ordine (il commerciante ci lavora); «pagamento in elaborazione» è lo stato
`processing` dell'intento Stripe (es. SEPA). Non si mescolano.

## 1. Dati

- `Order::STATUSES` += `awaiting_payment`, `abandoned`. La colonna è un enum
  guidato da `tableSchema()`: la allinea `forge update`. L'aggiunta è solo
  additiva, quindi sicura anche sul DB di prova condiviso `ecommerce_site`
  (gli altri worktree non conoscono i valori nuovi, ma non li incontrano).
- `StatusLabels::ORDER`: `awaiting_payment => ['In pagamento', 'secondary']`,
  `abandoned => ['Abbandonato', 'dark']`; traduzioni in `lang/*/gestionale.json`.
- Mentre è `awaiting_payment`, `order_number` è vuoto e `ordered_at` è NULL.
  `stage` è già `order` (serve a `OnlinePayments::succeeded` e al webhook).
- In `create()` restano uguali, anche per l'online: `Allocation::reserve` con la
  scadenza di `PaymentTiming`, `Ledger::open`, `writeTaxSummaries`,
  `StatusLogger::record(..., 'awaiting_payment')`.

## 2. Promozione a «In attesa»: `Lifecycle::receive()`

Metodo nuovo `Lifecycle::receive(int $orderId, string $reason): bool`:

- In una `Transaction::run`, nell'ordine: `Order::findForUpdate` della riga (lo
  stesso schema di `Ledger::fail` con `Payment::findForUpdate`); se lo stato non
  è `awaiting_payment` esce con false senza toccare niente; solo dopo chiede
  `DocumentSequences::next('order')` (il numero esistente si riusa, come oggi) e
  scrive `status = 'pending'`, `order_number`, `ordered_at = NOW()`. Webhook,
  ritorno (`settle`) e `Reconcile` possono arrivare insieme: il secondo aspetta
  il lock, trova `pending` ed esce, quindi nessun numero bruciato e le email
  partono una volta sola.
- Scrive `StatusLogger` (`awaiting_payment → pending`) e, se la prenotazione è
  IMMEDIATE, la porta alla durata DEFERRED quando il pagamento non è ancora
  incassato (§3.3, §3.4).
- Le email le manda il chiamante, solo se `receive()` ha restituito true.

Gli ordini manuali non passano da `awaiting_payment`: `create()` li scrive
`pending` come oggi.

## 3. Quando l'ordine diventa «In attesa», per metodo

### 3.1 Bonifico e contrassegno manuali

Come oggi: `pending` all'invio, email `received` + `merchant_new` (con
ON_DELIVERY `Lifecycle::confirm` ed email `confirmed`).

### 3.2 Stripe, pagamento riuscito subito (carta, wallet, Klarna…)

A `succeeded` (`OnlinePayments::succeeded`): se l'ordine è `awaiting_payment`
→ `receive()`, poi il flusso di oggi (`Ledger::register`, `Allocation::keep`,
email `paid` + `merchant_paid`). Niente `received` a parte: `paid` lo sostituisce.
Se l'ordine è `abandoned` si tratta come `cancelled`: `alert` al commerciante per
il rimborso.

### 3.3 Stripe, pagamento in elaborazione (SEPA)

`payment_intent.processing` si gestisce in tre punti: webhook
(`PaymentEvents::apply`), ritorno (`OnlinePayment::settle` che già restituisce
`processing`) e `Reconcile`. Tutti chiamano `OnlinePayments::processing()`
(nuovo): `receive()`, poi email `received` (testo «pagamento in elaborazione») +
`merchant_new`. Il pagamento resta «Da pagare». A `succeeded` partono
`paid` + `merchant_paid` come oggi.

Se il pagamento fallisce dopo (SEPA giorni dopo): l'ordine resta `pending`, il
pagamento diventa fallito (`fail()`, la storia lo registra), e il commerciante
riceve l'`alert` di oggi. Nessun abbandono automatico: l'ordine è già suo.

### 3.4 Stripe DELAYED (bonifico `customer_balance`, Multibanco…) — regola, non attivata

Quando Stripe dà le istruzioni (`requires_action` con `next_action` di tipo
istruzioni): `receive()`, email `received` con le istruzioni + `merchant_new`,
prenotazione portata a `order_payment_wait_days`, pagamento «Da pagare». Poi
`Expiry::deadline()` come per il bonifico manuale. L'attivazione dei metodi in
`StripeMethods::choices()` è fuori (§9).

## 4. Abbandono: `Lifecycle::abandon()`

Metodo nuovo `Lifecycle::abandon(int $orderId, string $reason): bool`, solo da
`awaiting_payment`:

1. annulla l'intento (`cancelIntent`); se lancia `order.payment_in_progress`
   (intento SUCCEEDED o in elaborazione) non abbandona e restituisce false;
2. `Allocation::release`, `Ledger::fail` sui pagamenti aperti, `Coupons::release`;
3. `status = 'abandoned'` (`WHERE status = 'awaiting_payment'`), `StatusLogger`;
4. nessuna email.

Sostituisce `OnlinePayment::dropPending()` (ecommerce) e il ramo online di
`Expiry`.

**Il cliente che torna dopo l'abbandono** (es. dopo i 30 minuti della
prenotazione IMMEDIATE) non perde il carrello: se l'ordine della sessione è
`abandoned`, `OnlinePayment::reopen` lo riporta a carrello sulla stessa riga
(`Lifecycle::reopen` accetta anche `abandoned`: intento, merce e coupon sono già
liberati, cambia solo `stage = 'cart'`, `status = 'draft'`) e lo unisce a un
carrello nato nel frattempo con `Cart::merge`, come oggi. La disponibilità si
ricontrolla al «Paga», come per ogni carrello.

## 5. Scadenza: `Expiry`

- Un `awaiting_payment` con la prenotazione scaduta → `abandon()`; se restituisce
  false lo salta (lo promuoveranno webhook o `Reconcile`).
- `WAITING` resta `['pending']`: la scadenza `order_payment_wait_days` con
  promemoria vale per bonifico manuale e DELAYED.
- `deadline()` salta (niente promemoria, niente annullo) un ordine `pending` il
  cui pagamento aperto ha l'intento SUCCEEDED o in elaborazione: un SEPA può
  restare in elaborazione fino a ~14 giorni e il cliente ha già pagato. Si usa
  lo stesso controllo di `cancelIntent` (`order.payment_in_progress`).
- `$silent` e `waitsForOnlinePayment` spariscono: un ordine online non pagato non
  è più `pending`, e quello in elaborazione lo copre il punto sopra.

## 6. Checkout sempre modificabile (ecommerce)

- `OnlinePayment::pending()` cerca l'ordine `awaiting_payment` della sessione.
- Riapertura automatica (`OnlinePayment::reopen` → `Lifecycle::reopen`, che
  annulla prima l'intento) in questi punti, quando c'è un ordine in pagamento:
  pagina del carrello, aggiunta e modifica di una riga del carrello, ogni
  modifica dei campi del checkout (il JS chiama `reopenUrl` al primo cambio,
  come `reopen()` di oggi), coupon. Il «Paga» successivo crea un intento nuovo.
- `CheckoutController::index` con il carrello vuoto e un ordine in pagamento non
  rimanda più a «Paga ora»: riapre e mostra il checkout.
- Bloccato, senza riapertura:
  - intento in `requires_action` (3DS aperto) o in elaborazione → messaggio
    «pagamento in corso» (`order.payment_in_progress`);
  - ordine già `pending` (DELAYED con istruzioni) → l'ordine sta nell'area
    cliente, il checkout parte da un carrello nuovo.
- La riapertura per una modifica scrive sul pagamento aperto il fallimento con
  la ragione «Ordine modificato dal cliente» (`Ledger::fail($id, $message)`),
  distinta da quella di un rifiuto: nella storia del pagamento (punto 14) i
  rifiuti restano riconoscibili.
- Il bottone «Modifica l'ordine» e il suo testo spariscono da `checkout.js` e
  dalle viste; dopo un rifiuto il form resta lì, si riprova o si modifica.
- `PaymentMismatch` in `succeeded()` copre la corsa fra una modifica e un incasso
  dell'intento vecchio.
- `CheckoutPlacement`: l'express sopra un ordine in pagamento chiama `reopen`
  come oggi; un nuovo checkout con un ordine in pagamento non riapribile chiama
  `abandon()` al posto di `dropPending()`. Un ordine manuale `pending`
  (bonifico) in sessione non si tocca più: è già del commerciante, la barra
  rapida e il checkout partono da un carrello nuovo.

## 7. Dove gli stati nuovi non devono comparire

- `LIVE_STATUSES` non cambia, quindi restano fuori da: filtro di OrderResource
  (riga ~161), `Mcp/Tools.php` (~189, ~600), `Coupons::hasPreviousOrders`
  (`Support/Promotions/Coupons.php:333`), `AccountOrder.php:92` (ecommerce).
- Query con `stage = 'order'` da controllare e, dove elencano ordini, filtrare
  con `status NOT IN ('awaiting_payment','abandoned')`: OrderResource (2),
  CustomerOrderTableResource, OfficeOrderResource, Mcp/Tools, OfficeOrderForm (3),
  OfficeOrders (2), Quotes; ecommerce EcommerceAccountController (2),
  EcommerceAccountExtension.
- `OrderActions` (controlli di stato ~82-95, ~189, ~264): nessuna azione su
  `awaiting_payment`; su `abandoned` nessuna (è chiuso).
- `OnlinePayments::start()` accetta `awaiting_payment` (non più `pending`);
  `Lifecycle::reopenable()` richiede `awaiting_payment`.
- Utilizzi dei coupon: un ordine in pagamento tiene il coupon prenotato come
  oggi, l'abbandono lo rilascia.

### Lista ordini: «Pagamenti non riusciti»

`OrderResource::querySchema` aggiunge `status <> 'awaiting_payment'` alla
condizione fissa. Un `filterQuery('Pagamenti', 'payment_view', ...)` con default
`active` (`status <> 'abandoned'`) e opzione `failed` («Pagamenti non riusciti»,
`status = 'abandoned'`); il default vale anche senza GET.

## 8. Prove

Gestionale (con `WONDER_NO_MAIL=1` e il prepend di sempre):

- `create()` online → `awaiting_payment`, numero vuoto, nessuna email.
- `succeeded` → `pending` con numero, `paid` + `merchant_paid`; due chiamate
  insieme (webhook + ritorno) → un numero, email una volta.
- `processing` → `pending`, `received` + `merchant_new`; poi `succeeded` → `paid`;
  poi in un'altra prova `payment_failed` → resta `pending`, pagamento fallito.
- `succeeded` su `abandoned` → `alert`, nessuna promozione.
- `abandon()` libera merce e coupon, nessuna email; con intento SUCCEEDED
  restituisce false.
- `Expiry` abbandona l'in pagamento scaduto, salta quello con intento riuscito.
- Lista ordini: in pagamento mai, abbandonato solo col filtro.

Ecommerce (con il symlink temporaneo al worktree del gestionale):

- modifica di un campo dopo un rifiuto → ordine tornato carrello, nuovo intento
  al «Paga»;
- intento in elaborazione → modifica bloccata con «pagamento in corso»;
- area cliente: niente ordini in pagamento né abbandonati.

Nel browser (utente, ecommerce-rifiuto.test): carta rifiutata
(`4000 0000 0000 0002`), modifica dell'indirizzo, carta buona → un solo ordine
nel backend, con i rifiuti nella storia del pagamento.

## 9. Fuori

- Attivare i metodi DELAYED in `StripeMethods::choices()` (spec D6 §11b): la
  regola §3.4 è pronta, manca la scelta nel modulo e la prova con Stripe. TODO.
- Migrazione degli ordini online già `pending` mai pagati: restano come sono
  (`Expiry` li annulla alla scadenza come oggi).
- Testi delle email: si riusano `received` e `paid`; la variante «pagamento in
  elaborazione» di `received` è una riga di testo, non un template nuovo.

## 10. Cosa cambia nelle altre spec

- `2026-10-09-pagamento-stripe-design.md` §8: l'ordine online nasce «In
  pagamento», non «In attesa»; «Modifica l'ordine» non c'è; l'abbandono è
  `abandoned`, non `cancelled`. Va aggiunta una nota che rimanda qui.
- `docs/dev/concetti/vendite.md`: stati dell'ordine e flusso online.
- Punto 13 (stesso ramo): la barra rapida che riportava a carrello un ordine
  normale `pending` ora lo lascia stare (§6); «Modifica l'ordine» sparisce.
