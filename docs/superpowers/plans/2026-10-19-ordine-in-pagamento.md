# Ordine «In pagamento» e «Abbandonato»: piano di lavoro

Spec: `docs/superpowers/specs/2026-10-19-ordine-in-pagamento-design.md`.

## Vincoli

- Gestionale: worktree `packages/app/gestionale/jolly-gagarin-dbcccf`, ramo
  `fix/ordine-online-user-id`. Ecommerce: worktree
  `packages/app/ecommerce/pagamento-riprova`, ramo
  `fix/pagamento-riprova-stesso-ordine`. Si committa lì, NON si spinge; i due
  rami si uniscono insieme.
- Test sempre con `WONDER_NO_MAIL=1` e `php -d auto_prepend_file=prepend.php`,
  dove `prepend.php` (nello scratchpad) carica
  `boilerplates/ecommerce-site/vendor/autoload.php` e aggiunge una classmap che
  punta `Wonder\Plugin\Gestionale\` e `Wonder\Plugin\Ecommerce\` ai `src/`
  dei due worktree (esclusi `Models/` e `Resources/`), poi restituisce il loader; per l'ecommerce il
  symlink temporaneo `packages/app/ecommerce/gestionale` → worktree del
  gestionale, tolto alla fine.
- DB condiviso `ecommerce_site`: l'enum si allarga con `forge update` sul sito
  `ecommerce-site-rifiuto` (solo aggiunta, sicuro per gli altri worktree).
  `forge update` importa il sync e sovrascrive funzionalità, colori e sedi accesi
  dal backend: esportare o confrontare prima.
- Ogni task: test che fallisce prima, poi codice, poi suite del file, poi commit
  con `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Task 1 — Stati e etichette (gestionale)

`Order::STATUSES`, `StatusLabels::ORDER`, `lang/*/gestionale.json`.
`forge update` sul sito di prova. Prova: le etichette escono in lista e scheda.

## Task 2 — `create()` online in `awaiting_payment` (gestionale)

`Checkout::create()`: per `provider !== 'manual'` stato `awaiting_payment`,
niente numero né `ordered_at`; il resto uguale. `OnlinePayments::start()` e
`Lifecycle::reopenable()` passano ad `awaiting_payment`.
Prove in `CheckoutTest` / `OnlinePaymentsTest`.

## Task 3 — `Lifecycle::receive()` e promozione a `succeeded` (gestionale)

`receive()` con l'UPDATE condizionato (spec §2); `OnlinePayments::succeeded()`
lo chiama prima di `Ledger::register`; `abandoned` trattato come `cancelled`.
Prove: numero e email una volta con due chiamate; `alert` su `abandoned`.

## Task 4 — Pagamento in elaborazione (gestionale)

`OnlinePayments::processing()`; `PaymentEvents::apply` gestisce
`payment_intent.processing`; `Reconcile` gestisce PROCESSING. Testo «pagamento
in elaborazione» nell'email `received`. Prove: processing → pending + email;
poi succeeded → paid; poi fallimento → resta pending, pagamento fallito.
La regola DELAYED (spec §3.4) resta solo nella spec: niente codice per metodi
non attivi.

## Task 5 — `Lifecycle::abandon()` ed `Expiry` (gestionale)

`abandon()` (spec §4); `Expiry::run()` abbandona gli `awaiting_payment` scaduti
e salta quelli con il pagamento in corso; via `$silent` / `waitsForOnlinePayment`.
Prove in `ExpiryTest` e `LifecycleTest`.

## Task 6 — Dove non devono comparire (gestionale)

Query con `stage = 'order'` della spec §7, `OrderActions`, filtro
«Pagamenti non riusciti» e condizione fissa in `OrderResource`. Prove: lista,
MCP `orders_list`, ordini del cliente.

## Task 7 — Checkout sempre modificabile (ecommerce)

`OnlinePayment::pending()` su `awaiting_payment`; `dropPending()` → `abandon()`;
riapertura automatica nei punti della spec §6 (carrello, righe, coupon, primo
cambio del checkout in `checkout.js`); `CheckoutController::index` non rimanda
più a «Paga ora» con un ordine in pagamento; via «Modifica l'ordine» (JS, viste,
testi); blocco «pagamento in corso». Account (`AccountOrder`,
`EcommerceAccountController`, `EcommerceAccountExtension`) senza i due stati.
Prove in `OnlinePaymentTest`, `CheckoutPayHttpTest`, `CartCheckoutTest`.

## Task 8 — Documentazione e chiusura

`docs/dev/concetti/vendite.md`, nota in
`2026-10-09-pagamento-stripe-design.md` §8, docs dell'ecommerce sul checkout,
`TODO.md` (attivare DELAYED). Suite complete dei due pacchetti; prova nel
browser dell'utente su `ecommerce-rifiuto.test` (spec §8).
