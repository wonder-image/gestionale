# Pagamento con Stripe (D6) — design

Data: 2026-10-09 · Branch per il lavoro: `pagamento-stripe` (app, gestionale, ecommerce)

Riferimenti: spec di architettura `2026-09-11-gestionale-ecommerce-architettura-design.md`
(§2.4 D14, §5.1 D46, §5.2 D47, §6.1, §6.3 D50, §9.3, §9.4), spec degli ordini
`2026-09-29-ordini-e-pagamenti-design.md` (§2–§3), spec del checkout
`2026-10-07-checkout-pagina-unica-design.md` (§7).

## 1. Obiettivo

Il negozio incassa con Stripe nel proprio checkout. Il primo negozio va consegnato
entro il 2026-10-29 (D61).

Criteri di riuscita:

- nel checkout il metodo «Carta» mostra il Payment Element. Il cliente paga senza
  lasciare il sito, salvo 3DS o metodi a reindirizzamento, e torna alla pagina di
  esito;
- l'ordine si conferma una sola volta, che la conferma arrivi dalla pagina di
  ritorno, dal webhook o dal riallineamento orario;
- un evento Stripe ripetuto non fa nulla due volte;
- riuscito, fallito, annullato e «in verifica» portano ciascuno al suo esito, e
  dopo un fallimento il cliente può riprovare;
- tutto si prova in modalità test senza addebiti veri, e test e produzione non si
  mescolano mai;
- i bottoni rapidi (Express Checkout) compaiono su checkout, carrello e scheda
  prodotto (piano 2).

## 2. Fuori

- PayPal e Nexi: dopo la 1.0.0.
- Il bottone «Rimborsa» nel gestionale. Nel D6 i rimborsi si fanno dalla
  dashboard di Stripe e il webhook li registra.
- Abbonamenti, Stripe Checkout in modalità abbonamento, prodotti, prezzi e codici
  promozionali su Stripe (§4.13).
- Billing Portal (C5): userà il Customer che crea il D6.
- Carte salvate per gli acquisti successivi (`setup_future_usage`).
- Commissioni della piattaforma (`application_fee_amount`).
- La coda dei ritentativi con tempi propri al di fuori del giro orario. Si veda
  §9: la scala del §9.3 si applica dentro il riallineamento.

## 3. Decisioni

| Tema | Decisione |
|------|-----------|
| Integrazione | Payment Element ed Express Checkout Element in **modalità differita**: partono con importo e valuta, senza PaymentIntent. Il PaymentIntent nasce solo al «Paga», dopo `Checkout::place`. *Scartati:* PaymentIntent creato all'apertura del checkout (intenti orfani, e niente express dalla scheda prodotto); Stripe Checkout ospitato (lo esclude la D46). |
| Conto | Stripe Connect, come già nel core: chiavi della piattaforma e conto collegato del sito (`stripe_id`). Gli addebiti sono diretti sul conto collegato e ogni chiamata porta `stripe_account`. Nel browser: chiave pubblica della piattaforma più `stripeAccount`. |
| Webhook | Un endpoint per sito, creato sul conto collegato (`Accounts::webhook`), con il suo segreto salvato nelle credenziali. |
| Fonte della conferma | Il webhook. La pagina di ritorno e il riallineamento chiamano la stessa `Lifecycle::confirm`, che è idempotente. |
| Eventi ripetuti | `ProviderEvents::receive` scarta solo gli eventi `processed`. Quelli `received` o `failed` si rielaborano. |
| Errore nell'elaborazione | `markFailed` e risposta 500: Stripe rimanda l'evento con i suoi tempi (fino a 3 giorni). Il riallineamento orario fa da rete (§9). |
| Importo | Il totale lo decide il server. Se differisce da quello mostrato al cliente, niente addebito e il riepilogo si aggiorna. Se un evento porta importo, valuta o ordine diversi da quelli attesi, l'ordine non si conferma e l'evento finisce in «Da controllare». |
| Customer Stripe | Uno per scheda cliente e per ambiente, creato al primo pagamento Stripe (email, nome). L'id sta in `external_references` e i cambi successivi non si sincronizzano. |
| Prodotti e coupon | Non vanno su Stripe. Il PaymentIntent porta solo il totale e i metadati. |
| Tipi di pagamento | Da `stripe_payment_method_types` del metodo; se la colonna è vuota, quelli automatici del conto. Browser e server leggono la stessa fonte. |
| Rimborsi | Fatti dalla dashboard di Stripe e registrati da `charge.refunded`. |
| Email | Per gli ordini pagati con Stripe, al `place` non parte nulla. «Confermato» e l'avviso al commerciante partono alla conferma. |
| Durata della prenotazione | `order_reservation_minutes`, impostata nel backend (predefinita 30). Il D6 non la cambia. |
| Ambiente | Colonna `environment` (`live`, `test`) su `gst_payments`. Gli ordini pagati in test portano il bollino «Prova» nel backend. |
| Express e fattura | L'express serve chi non chiede fattura, oppure il cliente con l'accesso che ha già i dati di fatturazione sulla scheda (§5.1 dell'architettura). |
| Express e ritiro | Il ritiro in negozio resta fuori: il wallet chiede sempre un indirizzo. |
| Piani | Piano 1: §4–§10 e §12 senza express. Piano 2: §11 e le sue prove. |

## 4. Credenziali e collegamento (app)

**Nuove credenziali** in `Credentials::api()`, tabella `security` e pagina
Sicurezza (D14):

| Chiave | `.env` | Pagina |
|--------|--------|--------|
| `stripe_public_key` | `STRIPE_PUBLIC_KEY` | testo |
| `stripe_test_public_key` | `STRIPE_TEST_PUBLIC_KEY` | testo |
| `stripe_webhook_secret` | `STRIPE_WEBHOOK_SECRET` | sola lettura |
| `stripe_test_webhook_secret` | `STRIPE_TEST_WEBHOOK_SECRET` | sola lettura |

I calcolati `stripe_publishable_key` e `stripe_webhook_key` seguono `stripe_test`,
come `stripe_api_key` e `stripe_id`.

**Bottone «Collega Stripe»** accanto all'onboarding, per l'ambiente scelto (test o
produzione):

1. crea l'endpoint del webhook sul conto collegato con `Accounts::webhook(stripe_id,
   url, eventi)`. L'url è quello di §6 e gli eventi sono quelli della tabella di §6;
2. salva il `secret` restituito nella colonna dell'ambiente;
3. registra il dominio del sito per Apple Pay e gli altri wallet
   (`paymentMethodDomains->create` con `stripe_account`). Questo passo serve al piano
   2, ma il bottone lo fa già nel piano 1.

Se lo si preme di nuovo, l'endpoint dell'ambiente si rifà: quello vecchio si
cancella e il segreto si sostituisce. Un dominio già registrato non è un errore.

**Nuova classe** `Wonder\Plugin\Stripe\PaymentIntent`, sul modello di `Checkout`:
`create(params, idempotencyKey)`, `get(id)`, `update(id, params)`, `cancel(id)`.
Altre classi o metodi d'appoggio (dominio, cancellazione dell'endpoint) si
aggiungono accanto a `Accounts`, con la stessa forma.

## 5. Contratto e adapter (gestionale)

Cartella `src/Providers/Payments/`:

- `PaymentProvider`, interfaccia:
  - `code(): string`;
  - `start(array $order, array $payment): PaymentStart`. Crea o riusa l'intento di
    pagamento e restituisce `reference` (`pi_…`), `client_secret` ed `environment`;
  - `status(string $reference): string`. Restituisce uno tra `succeeded`,
    `processing`, `requires_payment_method`, `canceled`, `other`, più importo e
    valuta;
  - `event(string $rawBody, string $signature): ?array`. Verifica la firma e
    restituisce l'evento normalizzato: id, tipo, ambiente, riferimento, importo,
    valuta, id dell'ordine dai metadati, payload. Restituisce `null` se la firma non
    è valida.
- `StripeProvider`: l'adapter, che usa le classi Stripe del core.
- `FakePaymentProvider` nei test: risponde con esiti scelti dal test.

`PaymentProviders` diventa il registro:

- `register(PaymentProvider)` e `get(code)`;
- `connected('stripe')` è vero solo se, per l'ambiente attivo, ci sono chiave
  segreta, conto collegato, chiave pubblica e segreto del webhook. Gli altri casi
  restano come oggi: i metodi manuali sono sempre collegati.

**`StripeProvider::start`:**

- **Customer:** lo cerca in `external_references` (`customer`, `stripe`, ambiente)
  e, se manca, lo crea con email e nome della scheda.
- **PaymentIntent:**
  - importo in centesimi dal pagamento in attesa, valuta dell'ordine;
  - `customer` e `description` con il codice dell'ordine;
  - `metadata`: `order_id`, `order_code`, `payment_code`;
  - `payment_method_types` oppure `automatic_payment_methods` secondo §3;
  - chiave di idempotenza: il codice `pay_…`.
- **Riuso:** se il pagamento ha già un `pi_…` ancora `requires_payment_method`, lo
  riusa. Se è annullato, ne crea uno nuovo con la chiave `pay_…-2`, `-3` e così
  via.

**Registro dei pagamenti:**

- `Ledger::attach(int $paymentId, string $provider, string $reference, string
  $environment)` scrive `pi_…` e l'ambiente sulla riga in attesa. Da quel momento
  `findByReference` la trova, e `Lifecycle::confirm` con lo stesso riferimento la
  chiude invece di aprirne un'altra.
- Nuova colonna `gst_payments.environment` (`live`, `test`, predefinita `live`).

## 6. Webhook ed eventi

Nuovo file `config/routes/route.api.php` nel gestionale, registrato come quello
dell'ecommerce: POST `/api/gestionale/stripe/webhook/`.

**Elaborazione:**

1. Legge il corpo grezzo (`php://input`) e l'intestazione `Stripe-Signature`.
2. Passa entrambi a `StripeProvider::event`. La firma si verifica con il segreto
   dell'ambiente attivo e la tolleranza predefinita (5 minuti). Se non è valida,
   risponde 400.
3. Se `livemode` non corrisponde all'ambiente attivo, risponde 200 e non fa nulla.
4. `ProviderEvents::receive`. Se l'evento era già `processed`, risponde 200 e non
   fa nulla.
5. Elabora l'evento secondo la tabella qui sotto, poi `markProcessed` e risposta
   200.
6. Se c'è un errore: `markFailed` (che incrementa `attempts`), log in
   `storage/logs/error/stripe.log` e risposta 500.

Niente sessione e niente CSRF: lì vale la firma.

| Evento | Effetto |
|--------|---------|
| `payment_intent.succeeded` | Trova il pagamento con `findByReference`, oppure dai metadati. Controlla che `order_id`, importo e valuta corrispondano, poi chiama `Lifecycle::confirm($orderId, provider stripe, provider_reference pi_…, amount, source 'stripe')`. Se non corrispondono: l'evento finisce `failed` e in «Da controllare», e l'ordine non si conferma. |
| `payment_intent.payment_failed` | `Ledger::fail` sul pagamento. L'ordine resta in attesa e il cliente può riprovare. |
| `payment_intent.canceled` | `Ledger::fail` sul pagamento. |
| `charge.refunded` | Per ogni rimborso nuovo della carica: `Ledger::refund` con l'id del rimborso (`re_…`) come riferimento, quindi idempotente. |
| Altri tipi | Registrati come `processed` senza effetto. |

**`ProviderEvents::receive`** cambia: restituisce `false` solo se l'evento esiste
già ed è `processed`. Se esiste `received` o `failed`, restituisce `true` e non
inserisce una seconda riga.

## 7. Pagamento nel checkout (ecommerce)

**Pagina.** Quando il metodo scelto è Stripe e `connected('stripe')` è vero, sotto
il metodo compare il Payment Element:

- `Stripe(chiave pubblica, {stripeAccount})`;
- `elements({mode: 'payment', amount, currency, paymentMethodTypes?})`;
- a ogni ricalcolo del riepilogo (spedizione, coupon), `elements.update({amount})`.

Con `connected('stripe')` falso, il metodo non compare.

**Al «Paga»**, solo se il metodo scelto è Stripe:

1. `elements.submit()`: se ci sono errori, si fermano lì.
2. Il modulo parte in fetch verso `ecommerce.checkout.place` con `expected_total`,
   chiedendo JSON. Valgono la stessa sessione e lo stesso CSRF di oggi.
3. Il server:
   - convalida come oggi;
   - ricalcola il totale. Se è diverso da `expected_total`, risponde con l'errore
     «il totale è cambiato» e il riepilogo nuovo, senza creare né ordine né
     intento;
   - esegue `Checkout::place`, che crea l'ordine in attesa, prenota la merce e apre
     il pagamento `pay_…`;
   - esegue `StripeProvider::start` e poi `Ledger::attach`;
   - risponde con `client_secret` e `return_url`.
4. Il browser chiama `stripe.confirmPayment({elements, clientSecret, confirmParams:
   {return_url}})`. Gli errori immediati, come la carta rifiutata, compaiono sotto
   il Payment Element.

**Nuovo tentativo sulla stessa pagina.**

- In sessione resta `ecommerce_checkout_pending`: codice dell'ordine e impronta del
  carrello (righe, quantità, coupon, spedizione, indirizzo).
- Se al nuovo «Paga» l'impronta è la stessa e l'ordine è ancora in attesa, si
  riusano ordine e intento.
- Se l'impronta è cambiata, il vecchio ordine si annulla con `Lifecycle::cancel`
  (la merce torna libera, senza email al cliente) e ne nasce uno nuovo.

**Email.** `Checkout::place` non manda «ricevuto» né l'avviso al commerciante
quando il metodo è Stripe. Alla conferma partono «confermato» e l'avviso al
commerciante. `Lifecycle::confirm` riceve il comando di mandare l'avviso con
un'opzione, che `Checkout` passa ai metodi Stripe.

**Il carrello** si svuota solo all'esito `succeeded` o `processing` (§8), non al
`place`.

## 8. Pagina di ritorno, «Paga ora» e ordini abbandonati

**Pagina di ritorno** `ecommerce.checkout.return` (GET, con `payment_intent` nella
query):

- non si fida di `redirect_status`: rilegge l'intento con `StripeProvider::status`;
- l'intento deve appartenere all'ordine in sessione, oppure all'ordine del link
  sicuro (§«Paga ora»). Altrimenti risponde 404.

| Stato | Esito |
|-------|-------|
| `succeeded` | `Lifecycle::confirm` (idempotente: il webhook può arrivare prima o dopo), carrello svuotato, pagina «ordine completato» che esiste già. |
| `processing` | Carrello svuotato, pagina «ordine completato» con il messaggio «pagamento in verifica». Conferma il webhook. |
| `requires_payment_method` | Rifiuto, 3DS annullato o metodo a reindirizzamento abbandonato. Ritorno al checkout con «pagamento non riuscito, riprova» e lo stesso ordine in sessione. |
| `canceled` o altro | Ritorno al checkout con «pagamento annullato». |

**«Paga ora».** Nella pagina dell'ordine, sia quella del link sicuro dell'ospite sia
quella dell'area cliente, un ordine Stripe ancora in attesa mostra il bottone
«Paga ora». Il bottone apre il Payment Element sull'intento del pagamento (con il
`client_secret`, senza la modalità differita), oppure su uno nuovo se quello vecchio
è annullato (§5). L'esito passa dalla stessa pagina di ritorno.

**Ordine abbandonato.** Nessun cambio:

- `Expiry` libera la merce dopo la durata della prenotazione impostata nel backend;
- l'ordine resta in attesa e si annulla dopo `order_payment_wait_days`;
- un pagamento che arriva dopo la scadenza della prenotazione si conferma
  comunque: con il denaro incassato, `Allocation::commit` scarica senza prenotazione
  e, se manca merce, segna la vendita in eccesso come oggi;
- quando `Expiry` annulla un ordine con un intento Stripe ancora aperto, cancella
  l'intento (`PaymentIntent::cancel`), così un pagamento tardivo non può più
  arrivare.

## 9. Riallineamento orario

`StripeReconcileTask` in `src/Scheduler/`, ogni ora, accanto a `StockAlertsTask`.
Il `NamedLock` del Worker evita le sovrapposizioni.

1. **Pagamenti in attesa.** Prende i pagamenti `stripe` in attesa, dell'ambiente
   attivo, con un `pi_…` e un ordine ancora in attesa. Per ognuno chiama
   `StripeProvider::status`:
   - `succeeded`: `Lifecycle::confirm`, con gli stessi controlli d'importo del
     webhook;
   - `canceled`: `Ledger::fail`;
   - altri stati: nulla.
2. **Eventi rimasti indietro.** Prende gli eventi `stripe` dell'ambiente attivo in
   stato `received` (più vecchi di 10 minuti) o `failed`, e li rielabora dal payload
   salvato, che ha già passato la firma. Segue la scala del §9.3: 5 min, 30 min, 2 h
   e 12 h dall'ultimo tentativo, secondo `attempts`, con la granularità del giro
   orario. Al quarto fallimento l'evento non si riprova più e resta in «Da
   controllare».

Un errore su un pagamento o su un evento finisce nel log e non ferma gli altri,
come in `Expiry`. I rimborsi non si rileggono: li coprono il webhook e i rinvii di
Stripe.

## 10. Test e produzione, sicurezza

**Ambienti:**

- `environment` su `gst_payments` e su `gst_provider_events` (che esiste già);
  `external_references` è già separato per ambiente;
- il webhook ignora gli eventi dell'altro ambiente, e il riallineamento interroga
  solo l'ambiente attivo;
- nel backend un ordine con un pagamento `test` porta il bollino «Prova» in elenco
  e nella scheda.

**Sicurezza:**

- nel browser arrivano solo la chiave pubblica e l'id del conto collegato;
- chiavi segrete e segreto del webhook restano nel server, e non compaiono né nei
  log né nelle risposte;
- il webhook verifica la firma sul corpo grezzo e controlla importo, valuta e
  ordine prima di confermare;
- la pagina di ritorno e «Paga ora» verificano che l'intento appartenga all'ordine
  della sessione o del link sicuro;
- `place` in fetch mantiene sessione, CSRF e limiti del modulo di oggi;
- le chiavi vere non passano mai dalla chat né dal codice: le inserisce lo
  sviluppatore nel `.env` o nella pagina Sicurezza.

## 11. Express Checkout (piano 2)

**Dove compare:**

- in cima al checkout, tramite `ExpressCheckout::buttons()`;
- nel carrello;
- sulla scheda prodotto, sotto «Aggiungi al carrello».

L'elemento mostra solo i wallet disponibili sul dispositivo: Apple Pay, Google
Pay, Link e, se attivi nel conto, Klarna e Amazon Pay.

**Quando non compare:**

- `connected('stripe')` è falso, oppure il metodo Stripe è spento;
- il checkout è bloccato dai requisiti del negozio (§8.6 dell'architettura);
- il visitatore non ha l'accesso e gli ordini da ospite sono spenti;
- sulla scheda prodotto, la combinazione scelta non si può comprare;
- nel checkout, è spuntato «Voglio la fattura» (i bottoni si nascondono finché
  resta spuntato).

**Cosa si compra:**

- **checkout e carrello:** il carrello, con il coupon già applicato;
- **scheda prodotto:** «compra subito» solo quell'articolo, con variante, opzioni,
  scelte dei composti e quantità. Si usa un carrello a parte in sessione
  (`ecommerce_express_cart`) e quello vero non si tocca.

Le campagne automatiche valgono sempre. Nel foglio del wallet non si inseriscono
coupon.

**Flusso** (`expressCheckout` in modalità differita, con `shippingAddressRequired`,
`emailRequired` e `phoneNumberRequired` se il checkout chiede il telefono):

1. **`click`:** il server restituisce righe, totale e tariffe di spedizione per
   l'Italia. Il browser fa `event.resolve`.
2. **`shippingaddresschange`:** prima dell'autorizzazione arrivano solo paese, CAP,
   città e provincia (`state`). La provincia si normalizza:
   - la sigla di due lettere («MI») vale così com'è;
   - il nome intero («Milano») si porta alla sigla con l'elenco delle province;
   - se non è riconosciuta, si usa la zona del solo paese.
   
   Poi `ShippingZones::resolve` dà i metodi e le tariffe. Senza nessuna zona, si
   chiama `event.reject()` con il messaggio «spedizione non disponibile».
3. **`shippingratechange`:** ricalcola il totale e aggiorna l'elemento.
4. **`confirm`:** il wallet consegna nome, email, telefono, indirizzo completo e
   l'indirizzo di fatturazione.
   - Il server compone i dati per `CheckoutForm`. La fatturazione è quella del
     wallet, oppure quella della scheda per il cliente con l'accesso che l'ha
     salvata. L'ospite crea o aggiorna la scheda per email, come in
     `GuestCheckout`.
   - Poi segue la sezione §7 dal punto 3: controllo del totale, `place`, `start`,
     `attach`.
   - Il browser chiama `confirmPayment` e arriva alla stessa pagina di ritorno.
   - Se il totale non torna, `event.paymentFailed` con un messaggio, e nessun
     addebito.

Sotto i bottoni compare «Pagando accetti le condizioni di vendita», con il link.

**Rotte nuove** in `route.api.php` dell'ecommerce (`api.ecommerce.express.*`):
`start` (click), `shipping` (indirizzo e tariffa), `place` (confirm). Sessione e
CSRF sono quelli del sito.

## 12. Prove

- **Gestionale (piano 1):**
  - eventi Stripe veri salvati in `tests/fixtures/stripe/*.json` (riuscito, fallito,
    annullato, rimborso), firmati nel test con un segreto di prova;
  - casi: evento ripetuto; evento `failed` rielaborato; importo, valuta o ordine
    sbagliati; ambiente sbagliato; firma non valida; `attach` più `confirm` che
    chiude la stessa riga; riuso dell'intento; riallineamento con la scala dei
    tempi; `Expiry` che cancella l'intento;
  - `FakePaymentProvider` per i flussi senza rete.
- **Ecommerce (piano 1):** `place` con `expected_total` diverso; secondo tentativo
  con lo stesso carrello e con un carrello cambiato; email non inviate al `place`;
  pagina di ritorno con ognuno degli stati e con un intento di un altro ordine;
  «Paga ora».
- **App (piano 1):** credenziali nuove da `.env` e da tabella; i calcolati seguono
  `stripe_test`.
- **Piano 2:** normalizzazione della provincia; tariffe per indirizzo parziale;
  zona assente; carrello a parte della scheda prodotto che lascia intatto quello
  vero; express nascosto con la fattura richiesta e con gli ospiti spenti.
- **E2E su `ecommerce.test` (D8)**, in modalità test con le carte di prova:
  riuscito, rifiutato e poi riuscito, 3DS annullato. Le chiavi di test le inserisce
  lo sviluppatore.
- **A mano:** Apple Pay su un dominio HTTPS registrato, secondo la checklist della
  guida (D56). Google Pay e Link si provano anche in locale con Chrome.

## 13. Piani

1. **Piano 1, pagamento con il Payment Element:** §4–§10 e le prove di §12 senza
   express. Da solo basta a far pagare il primo negozio.
2. **Piano 2, Express Checkout:** §11, prima nel checkout e poi nel carrello e
   sulla scheda prodotto, con le sue prove.

Il piano 2 parte solo dopo che il piano 1 è unito.

## 14. File toccati (indicativi)

- **app:** `class/App/Credentials.php`, `class/App/Resources/Config/SecurityResource.php`,
  `class/Plugin/Stripe/PaymentIntent.php` (nuovo), `class/Plugin/Stripe/Accounts.php`,
  l'azione «Collega Stripe» accanto a `app/http/api/service/stripe/onboarding*.php`.
- **gestionale:** `src/Providers/Payments/*` (nuovi), `src/Support/Payments/PaymentProviders.php`,
  `src/Support/Providers/ProviderEvents.php`, `Ledger` (`attach`), modello dei
  pagamenti (`environment`), `Checkout` (email), `Lifecycle` (avviso al
  commerciante alla conferma), `Expiry` (cancellazione dell'intento),
  `src/Scheduler/StripeReconcileTask.php` (nuovo), `config/routes/route.api.php`
  (nuovo), il bollino «Prova» nelle risorse degli ordini.
- **ecommerce:** `CheckoutController` (fetch, ritorno, «Paga ora»), viste e JS del
  checkout, pagina dell'ordine, `ExpressCheckout` e `route.api.php` (piano 2).
