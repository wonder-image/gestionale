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
| Webhook | Un endpoint Connect per ambiente, creato sulla piattaforma per i conti collegati (`Connect::webhook`), con il suo segreto salvato nelle credenziali dell'ambiente. La firma si prova con i due segreti: quello che la verifica dice l'ambiente dell'evento. |
| Fonte della conferma | Il webhook. La pagina di ritorno e il riallineamento chiamano la stessa `Lifecycle::confirm`, che è idempotente. |
| Eventi ripetuti | `ProviderEvents::receive` scarta solo gli eventi `processed`. Quelli `received` o `failed` si rielaborano. |
| Errore nell'elaborazione | `markFailed` e risposta 500: Stripe rimanda l'evento con i suoi tempi (fino a 3 giorni). Il riallineamento orario fa da rete (§9). |
| Importo | Il totale lo decide il server. Se differisce da quello mostrato al cliente, niente addebito e il riepilogo si aggiorna. Gli importi si confrontano in centesimi. Se un evento porta importo, valuta o ordine diversi da quelli attesi, l'ordine non si conferma, l'evento resta `failed` senza altri tentativi e il commerciante riceve la segnalazione. |
| Customer Stripe | Uno per scheda cliente e per ambiente, creato al primo pagamento Stripe (email, nome). L'id sta in `external_references` e i cambi successivi non si sincronizzano. |
| Prodotti e coupon | Non vanno su Stripe. Il PaymentIntent porta solo il totale e i metadati. |
| Tipi di pagamento | Nel checkout, dalla scelta del cliente (§11b): `card` o un altro metodo acceso nel conto; dalla barra rapida (§11), il tipo che il wallet comunica (`apple_pay`, `google_pay`, `link`). Per i pagamenti senza scelta (righe vecchie, pagamenti creati dal backend), da `stripe_payment_method_types` del metodo; se la colonna è vuota, quelli automatici del conto. Browser e server leggono la stessa fonte. |
| Rimborsi | Fatti dalla dashboard di Stripe e registrati da `charge.refunded`. |
| Email | Per gli ordini pagati online, al `place` non parte nulla. «Confermato» e l'avviso al commerciante partono alla conferma. Il link per la password dell'ospite viaggia con «confermato» (`OrderEmailExtras`). |
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

**Bottone «Collega webhook»** nella pagina Sicurezza, uno per ambiente (test e
produzione), accanto ai badge «Collega». Chiama la rotta
`api.service.stripe.connect?account=test|production`, che:

1. crea l'endpoint Connect del webhook sulla piattaforma, per i conti collegati,
   con `Connect::webhook(url)`. L'url è quello di §6 (`Route::url` della rotta
   `api.gestionale.stripe.webhook`) e gli eventi sono `Connect::EVENTS`, cioè
   quelli della tabella di §6;
2. salva il `secret` restituito nella colonna dell'ambiente
   (`Connect::secretColumn`);
3. registra il dominio del sito per Apple Pay e gli altri wallet
   (`Connect::domain`, con `stripe_account`). Questo passo serve al piano 2, ma
   il bottone lo fa già nel piano 1.

Se lo si preme di nuovo, l'endpoint dell'ambiente si rifà: quello vecchio con lo
stesso url si cancella e il segreto si sostituisce. Un dominio già registrato non
è un errore.

**Nuove classi** nel core, a sé e con le chiavi di chi chiama:

- `Wonder\Plugin\Stripe\PaymentIntent`: `create(params, idempotencyKey)`,
  `get(id)`, `update(id, params)`, `cancel(id)`, `createCustomer(params,
  idempotencyKey)`, `refundsOf(chargeId)`. Ogni chiamata porta `stripe_account`.
- `Wonder\Plugin\Stripe\Connect`: `webhook(url)`, `domain(domain)`,
  `environment(env, webhookUrl, domain)` (i tre passi qui sopra),
  `secretColumn(env)`, `EVENTS`.

## 5. Contratto e adapter (gestionale)

Cartella `src/Providers/Payments/`:

- `PaymentProvider`, interfaccia:
  - `code(): string`, `environment(): string` (`live` o `test`), `connected(): bool`;
  - `start(array $order, array $payment): PaymentStart`. Crea o riusa l'intento di
    pagamento e restituisce `reference` (`pi_…`), `client_secret` ed `environment`;
  - `status(string $reference): PaymentState`: uno tra `succeeded`,
    `processing`, `requires_payment_method`, `canceled`, `other`, più importo in
    centesimi, valuta e id dell'ordine dai metadati;
  - `event(string $raw, string $signature): ?PaymentEvent`. Verifica la firma e
    restituisce l'evento normalizzato: id, tipo, ambiente, riferimento, importo in
    centesimi, valuta, id dell'ordine dai metadati, rimborsi, carica, payload.
    Restituisce `null` se la firma non è valida;
  - `replay(array $payload, string $environment): ?PaymentEvent`: lo stesso
    evento rifatto dal payload salvato, senza firma, per il riallineamento;
  - `cancel(string $reference): void`: annulla un intento ancora aperto.
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
  - `metadata`: `order_id`, `order_code`, `payment_code`, `attempt`;
  - `payment_method_types` oppure `automatic_payment_methods` secondo §3;
  - chiave di idempotenza: il codice `pay_…`.
- **Riuso:** se il pagamento ha già un `pi_…` ancora `requires_payment_method`, lo
  riusa con lo stesso `client_secret`. Se è annullato, ne crea uno nuovo con
  `attempt` più uno e la chiave `pay_…-2`, `-3` e così via.

**Registro dei pagamenti:**

- `Ledger::attach(int $paymentId, string $provider, string $reference, string
  $environment)` scrive `pi_…` e l'ambiente sulla riga in attesa. Da quel momento
  `Ledger::byReference` la trova, e `Lifecycle::confirm` con lo stesso riferimento
  la chiude invece di aprirne un'altra.
- Nuova colonna `gst_payments.environment` (`live`, `test`, predefinita `live`).

## 6. Webhook ed eventi

Nuovo file `config/routes/route.api.php` nel gestionale, registrato come quello
dell'ecommerce: POST `/api/gestionale/stripe/webhook/`.

**Elaborazione** (`PaymentEvents::handle`):

1. Legge il corpo grezzo (`php://input`) e l'intestazione `Stripe-Signature`.
2. Passa entrambi a `StripeProvider::event`. La firma si prova prima con il
   segreto dell'ambiente attivo e poi con quello dell'altro, con la tolleranza
   predefinita (5 minuti); il segreto che la verifica dice l'ambiente. Se nessuno
   la verifica, risponde 400.
3. Se `livemode` non corrisponde a quell'ambiente, l'ambiente è incerto. Un evento
   dell'altro ambiente o incerto riceve 200 e non fa nulla.
4. `ProviderEvents::receive`. Se l'evento era già `processed`, risponde 200 e non
   fa nulla.
5. Elabora l'evento secondo la tabella qui sotto, poi `markProcessed` e risposta
   200.
6. Se c'è un errore: `markFailed` (che incrementa `attempts`), log in
   `storage/logs/error/stripe.log` e risposta 500. Un'incoerenza invece è
   definitiva: `markFailed` finale, segnalazione al commerciante e risposta 200,
   perché un rinvio non la cambierebbe.

Niente sessione e niente CSRF: lì vale la firma. Gli effetti passano da
`OnlinePayments`, che fa lo stesso lavoro per il webhook, la pagina di ritorno e
il riallineamento.

| Evento | Effetto |
|--------|---------|
| `payment_intent.succeeded` | `OnlinePayments::succeeded`: trova il pagamento con `Ledger::byReference`, controlla che ordine, centesimi e valuta corrispondano, registra l'incasso e chiama `Lifecycle::confirm` con l'avviso al commerciante (`source 'webhook'`). Se non corrispondono (`PaymentMismatch`): niente conferma, evento `failed` definitivo, segnalazione al commerciante. Se l'ordine è già annullato: l'incasso si registra comunque e il commerciante riceve l'avviso, perché il denaro va restituito a mano. |
| `payment_intent.payment_failed` | `OnlinePayments::fail`: la riga passa a `failed`, l'ordine resta in attesa e l'intento resta riusabile, così il cliente riprova con lo stesso. Se poi riesce, `succeeded` chiude la stessa riga. |
| `payment_intent.canceled` | `OnlinePayments::fail` sul pagamento. |
| `charge.refunded` | Per ogni rimborso nuovo e riuscito della carica (quelli falliti o annullati si saltano): `Ledger::refund` con l'id del rimborso (`re_…`) come riferimento, quindi idempotente. |
| Altri tipi | Registrati come `processed` senza effetto. |

**`ProviderEvents::receive`** cambia: restituisce `false` solo se l'evento esiste
già ed è `processed`. Se esiste `received` o `failed`, restituisce `true` e non
inserisce una seconda riga. `markFailed` con `final` porta `attempts` a
`ProviderEvents::MAX_ATTEMPTS` (4): il riallineamento non lo riprova più.

**Extra delle email.** `OrderEmailExtras` è un punto d'estensione del gestionale:
alla conferma raccoglie i dati in più per «confermato». L'ecommerce lo usa per
`account_url`, il link con cui l'ospite sceglie la password, che prima partiva con
«ricevuto».

## 7. Pagamento nel checkout (ecommerce)

**Pagina.** Quando il metodo scelto è Stripe e `connected('stripe')` è vero, sotto
il metodo compare il Payment Element:

- `Stripe(chiave pubblica, {stripeAccount})`;
- `elements({mode: 'payment', amount, currency, paymentMethodTypes?})`;
- a ogni ricalcolo del riepilogo (spedizione, coupon), `elements.update({amount})`.

Con `connected('stripe')` falso, il metodo non compare.

**Al «Paga»**, solo se il metodo scelto è online:

1. `elements.submit()`: se ci sono errori, si fermano lì.
2. Il modulo parte in fetch verso `ecommerce.checkout.place` con `expected_total`,
   chiedendo JSON. Valgono la stessa sessione e lo stesso CSRF di oggi. Senza
   JavaScript un metodo online non si può usare: il server risponde con l'errore
   `online_javascript`.
3. Il server:
   - convalida come oggi;
   - ricalcola il totale. Se è diverso da `expected_total`, risponde 409 con
     «il totale è cambiato» e il riepilogo nuovo, senza creare né ordine né
     intento;
   - annulla l'ordine in attesa rimasto in sessione da un tentativo precedente
     (`OnlinePayment::dropPending`), salvo che il denaro sia arrivato o in
     verifica;
   - esegue `Checkout::place`, che crea l'ordine in attesa, prenota la merce, apre
     il pagamento `pay_…` e trasforma il carrello nell'ordine;
   - esegue `StripeProvider::start` e poi `Ledger::attach`;
   - risponde con `client_secret` e `return_url`.
4. Il browser chiama `stripe.confirmPayment({elements, clientSecret, confirmParams:
   {return_url}})`. Gli errori immediati, come la carta rifiutata, compaiono sotto
   il Payment Element.

**Rifiuto: il modulo si riapre.**

- In sessione resta `ecommerce_checkout_pending` con l'ordine nato al `place`, e
  mentre si paga il modulo è bloccato.
- Se Stripe rifiuta (carta, 3DS fallito), il browser chiama
  `ecommerce.checkout.reopen` (POST, CSRF) e `OnlinePayment::reopen` annulla
  l'ordine (`dropPending`, che annulla anche l'intento) e con `Cart::restore`
  rimette nel carrello le righe, le scelte, gli indirizzi e il coupon. Le righe
  che non tornano perché spente o finite le elenca un avviso.
- Il modulo torna modificabile: il cliente cambia metodo, indirizzo o coupon, e il
  prossimo «Paga» o «Ordina» crea un ordine nuovo. L'ordine annullato resta nel
  backend. Se intanto il denaro è arrivato, si va alla pagina di ritorno.
- Non c'è «Annulla l'ordine»: se il cliente se ne va, la scadenza delle
  prenotazioni libera la merce (§8).
- Ricaricando il checkout con un ordine in sessione si arriva alla pagina
  «Paga ora» (§8).

**Email.** `Checkout::place` non manda «ricevuto» né l'avviso al commerciante
quando il metodo è online, e restituisce il fornitore. Alla conferma partono
«confermato» e l'avviso al commerciante: `Lifecycle::confirm` riceve l'opzione
`merchant_notice`.

## 8. Pagina di ritorno, «Paga ora» e ordini abbandonati

**Pagina di ritorno** `ecommerce.checkout.return` (GET, con `payment_intent` nella
query):

- non si fida di `redirect_status`: rilegge l'intento con `StripeProvider::status`
  (`OnlinePayment::settle`);
- l'intento deve appartenere all'ordine in sessione. Altrimenti risponde 404.

| Stato | Esito |
|-------|-------|
| `succeeded` | `OnlinePayments::succeeded` (idempotente: il webhook può arrivare prima o dopo), sessione dimenticata, pagina «ordine completato» che esiste già. |
| `processing` | Sessione dimenticata, pagina «ordine completato» con il messaggio «pagamento in verifica». Conferma il webhook. |
| `requires_payment_method` | Rifiuto, 3DS annullato o metodo a reindirizzamento abbandonato. Il modulo si riapre (§7): checkout con «pagamento non riuscito» e le righe di nuovo nel carrello. |
| `canceled` o altro | Come sopra, con «pagamento annullato». |

**«Paga ora».** La pagina `ecommerce.checkout.pay` (`/checkout/pay/`) vale solo per
l'ordine in sessione. Apre il Payment Element sull'intento del pagamento (con il
`client_secret`, senza la modalità differita), oppure su uno nuovo se quello vecchio
è annullato (§5), e offre «Cambia metodo di pagamento», che riapre il modulo
come dopo un rifiuto (§7). Serve solo se si ricarica a metà pagamento. L'esito passa dalla stessa pagina
di ritorno. «Paga ora» dal link sicuro dell'ospite e dall'area cliente resta fuori
dal piano 1 e va nel TODO.

**Ordine abbandonato.**

- `Expiry` libera la merce dopo la durata della prenotazione impostata nel backend;
- l'ordine resta in attesa e si annulla dopo `order_payment_wait_days`;
- un pagamento che arriva dopo la scadenza della prenotazione si conferma
  comunque: con il denaro incassato, `Allocation::commit` scarica senza prenotazione
  e, se manca merce, segna la vendita in eccesso come oggi;
- `Lifecycle::cancel` annulla gli intenti ancora aperti dell'ordine
  (`PaymentProvider::cancel`) dopo la transazione, così un pagamento tardivo non
  può più arrivare. Vale per `Expiry`, per il commerciante e per `dropPending`; un
  errore di Stripe non ferma l'annullo.

## 9. Riallineamento orario

`StripeReconcileTask` in `src/Scheduler/`, ogni ora, accanto a `StockAlertsTask`.
Il `NamedLock` del Worker evita le sovrapposizioni.

Nasce spento, come gli altri compiti; lo si accende insieme a `ExpiryTask`.

1. **Pagamenti aperti.** Prende i pagamenti `stripe` `pending` o `failed` (una
   carta rifiutata lascia l'intento riusabile, e il secondo tentativo riuscito può
   perdere il webhook), dell'ambiente attivo, con un `pi_…` e un ordine ancora in
   attesa. Per ognuno chiama `StripeProvider::status`:
   - `succeeded`: `OnlinePayments::succeeded`, con gli stessi controlli d'importo
     del webhook (`source 'cron'`). Un'incoerenza su una riga `pending` si segnala
     al commerciante e la riga passa a `failed`; su una riga già `failed` va solo
     nel log, così la segnalazione parte una volta;
   - `canceled`: `OnlinePayments::fail`, solo se la riga è ancora `pending`;
   - altri stati: nulla.
2. **Eventi rimasti indietro.** Prende gli eventi `stripe` dell'ambiente attivo in
   stato `received` (più vecchi di 10 minuti) o `failed` non definitivi, e li
   rifà dal payload salvato con `replay`, che ha già passato la firma. Segue la
   scala del §9.3: 5 min, 30 min e 2 h dall'ultimo tentativo, secondo `attempts`,
   con la granularità del giro orario. Al quarto fallimento
   (`ProviderEvents::MAX_ATTEMPTS`) l'evento non si riprova più e resta in «Da
   controllare».

Un errore su un pagamento o su un evento finisce nel log e non ferma gli altri,
come in `Expiry`. I rimborsi non si rileggono: li coprono il webhook e i rinvii di
Stripe.

## 10. Test e produzione, sicurezza

**Ambienti:**

- `environment` su `gst_payments` e su `gst_provider_events` (che esiste già);
  `external_references` è già separato per ambiente;
- il webhook ignora gli eventi dell'altro ambiente o di ambiente incerto, e il
  riallineamento interroga solo l'ambiente attivo;
- nel backend un ordine con un pagamento `test` porta il bollino «Prova» accanto a
  quello del pagamento, in elenco e nella scheda.

**Sicurezza:**

- nel browser arrivano solo la chiave pubblica e l'id del conto collegato;
- chiavi segrete e segreto del webhook restano nel server, e non compaiono né nei
  log né nelle risposte;
- il webhook verifica la firma sul corpo grezzo e controlla importo, valuta e
  ordine prima di confermare;
- la pagina di ritorno e «Paga ora» valgono solo per l'ordine della sessione;
- `place` in fetch mantiene sessione, CSRF e limiti del modulo di oggi;
- le chiavi vere non passano mai dalla chat né dal codice: le inserisce lo
  sviluppatore nel `.env` o nella pagina Sicurezza.

## 11. Express Checkout (piano 2)

**Dove compare:**

- in cima al checkout, nella sezione `#rapido` (`ExpressCheckout::buttons()`),
  sopra il modulo: chi la usa non compila nulla, i dati arrivano dal wallet;
- nel carrello;
- sulla scheda prodotto, sotto «Aggiungi al carrello».

L'elemento mostra bottoni separati, solo quelli disponibili sul dispositivo e
accesi nel conto: Apple Pay, Google Pay e Link (`paymentMethods` ad `auto` per
questi tre, `never` per gli altri, che hanno la loro scelta nel modulo, §11b).
Apple Pay e Google Pay stanno solo qui, non fra le scelte del modulo.

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
     `attach`. Il pagamento si salva con `provider` `stripe` e `provider_method`
     uguale a `event.expressPaymentType` (`apple_pay`, `google_pay`, `link`),
     convalidato contro i tipi accesi nel conto.
   - Il browser chiama `confirmPayment` e arriva alla stessa pagina di ritorno.
   - Se il totale non torna, `event.paymentFailed` con un messaggio, e nessun
     addebito.

Sotto i bottoni compare «Pagando accetti le condizioni di vendita», con il link.

**Rotte nuove** in `route.api.php` dell'ecommerce (`api.ecommerce.express.*`):
`start` (click), `shipping` (indirizzo e tariffa), `place` (confirm). Sessione e
CSRF sono quelli del sito.

## 11b. Metodi Stripe separati sotto la carta (piano 2a)

Richiesta del commerciante: Klarna, Link e gli altri non stanno dentro «Carta di
credito», ma sotto, uno per scelta, e compaiono da soli secondo i metodi accesi
nel conto Stripe. Apple Pay e Google Pay non sono fra le scelte: stanno nella
barra rapida in cima (§11, piano 2b).

**Una riga, più scelte.** Il metodo Stripe resta una sola riga di
`gst_payment_methods` (nome, commissione, icone dei circuiti). Il checkout ne
ricava più scelte con lo stesso `payment_method_id` e un campo in più,
`stripe_method_type`, portato dal valore del radio:

| Scelta | `stripe_method_type` | Pannello | Tipi dell'intento |
|---|---|---|---|
| Nome del metodo (es. «Carta di credito») | `card` | Payment Element con solo `card`, icone dei circuiti senza i wallet | `['card']` |
| Una per ogni altro metodo acceso (Klarna, Link, Satispay, PayPal, …) | il tipo Stripe (`klarna`, `link`, …) | Payment Element con solo quel tipo | `[tipo]` |

La commissione è quella della riga, uguale per tutte le scelte. Nel riepilogo
l'ordine porta il nome della riga; il tipo scelto si legge sul pagamento.

**Da dove viene l'elenco.**

- `StripeProvider::activeTypes(): list<string>` legge
  `GET /v1/payment_method_configurations` sul conto collegato (intestazione
  `Stripe-Account`, chiavi dell'ambiente attivo). Usa la configurazione con
  `is_default` e `active`; se non c'è, la prima `active`. Per ogni tipo vale
  `available === true`.
- La risposta si tiene in cache per 10 minuti in `gst_settings.stripe_methods_cache`
  (JSON `{environment, account, types, fetched_at}`, esclusa dal deploy come
  `merchant_notification_emails`), più una memoria statica per richiesta. Cambiano
  ambiente o conto: la cache non vale.
- Se la chiamata fallisce si usa la cache scaduta; se non c'è nemmeno quella,
  compare solo la carta. Nessun errore al cliente.
- Le scelte separate sono i tipi disponibili meno `card`, `apple_pay` e
  `google_pay`.
- `stripe_payment_method_types` della riga non filtra più le scelte del checkout:
  vale solo per i pagamenti senza tipo scelto (§3).

**Nomi e icone.** Una mappa tipo → nome («Klarna», «Satispay», «PayPal», «Bonifico
SEPA», «Bancontact», «iDEAL», …), con `ucfirst` del tipo come riserva. Icona dalla
cartella delle icone di pagamento quando c'è, altrimenti una generica.

**Server.**

- `Checkout::preview` restituisce le scelte nell'ordine: carta, poi le separate in
  ordine alfabetico del tipo.
- `Checkout::place` convalida `stripe_method_type`: deve essere fra le scelte
  calcolate in quel momento, altrimenti 422 «metodo di pagamento non disponibile».
  Per le righe non Stripe il campo si ignora.
- Il tipo si salva sul pagamento: colonna nuova `provider_method` (VARCHAR 40) su
  `gst_payments`, passata a `Ledger::open`. Un pagamento Stripe fatto dal modulo
  ha quindi `provider` `stripe` e `provider_method` `card`, `klarna`, `link`…
- `StripeProvider::start`: se `$payment['provider_method']` c'è, i tipi
  dell'intento vengono dalla tabella sopra; altrimenti dal CSV o automatici come
  oggi. Quando riusa un intento `pi_` e i tipi sono diversi, fa `update` dei
  `payment_method_types` prima di restituirlo.

**Browser.**

- Ogni scelta Stripe ha il suo gruppo `elements` (modalità differita, stesso
  importo e valuta) creato la prima volta che la si sceglie, e il suo elemento
  montato nel pannello. Gli importi si aggiornano su tutti i gruppi creati. Le
  scelte Stripe si conservano come oggi la carta quando `choices()` ridisegna
  l'elenco.
- **Separate:** Payment Element con `paymentMethodTypes: [tipo]`. Se l'elemento
  dà `loaderror` (per esempio Klarna fuori dai limiti d'importo), la scelta si
  nasconde e torna selezionata la carta.
- `stripe_method_type` si ricorda nello stato del modulo
  (`ecommerce_checkout_form_state`), come gli altri campi.
- Rifiuto e «Paga ora» restano quelli di §7–§8: il modulo si riapre con la scelta
  di prima; «Paga ora» monta il Payment Element sull'intento esistente con i suoi
  tipi.

## 12. Prove

- **Gestionale (piano 1):**
  - eventi Stripe veri salvati in `tests/fixtures/stripe/*.json` (riuscito, fallito,
    annullato, rimborso), firmati nel test con un segreto di prova;
  - casi: evento ripetuto; evento `failed` rielaborato; importo, valuta o ordine
    sbagliati (definitivi); ambiente sbagliato o incerto; firma non valida;
    `attach` più `confirm` che chiude la stessa riga; riuso dell'intento;
    riallineamento con la scala dei tempi; `Lifecycle::cancel` che annulla
    l'intento;
  - `FakePaymentProvider` per i flussi senza rete.
- **Ecommerce (piano 1):** `place` con `expected_total` diverso; nuovo `place`
  che annulla l'ordine in attesa rimasto in sessione; email non inviate al `place`;
  pagina di ritorno con ognuno degli stati e con un intento di un altro ordine;
  «Paga ora» e la riapertura del modulo dopo un rifiuto.
- **App (piano 1):** credenziali nuove da `.env` e da tabella; i calcolati seguono
  `stripe_test`.
- **Piano 2a:** elenco dei tipi dalla configurazione (default, `available`,
  configurazione non attiva); cache valida, scaduta, di un altro ambiente o conto;
  chiamata fallita con e senza cache; scelte nel `preview`; `place` con tipo non in
  elenco (422); `provider_method` salvato; tipi dell'intento per carta,
  separata e riga senza tipo; riuso dell'intento con tipi diversi (`update`).
- **Piano 2b:** `provider_method` dal `expressPaymentType`; normalizzazione della provincia; tariffe per indirizzo parziale;
  zona assente; carrello a parte della scheda prodotto che lascia intatto quello
  vero; express nascosto con la fattura richiesta e con gli ospiti spenti.
- **E2E su `ecommerce.test` (D8)**, in modalità test con le carte di prova e la
  Stripe CLI che inoltra gli eventi Connect: riuscito, rifiutato e poi riuscito,
  3DS annullato e poi pagato da «Paga ora», ordine annullato. Le chiavi di test e
  il segreto del webhook li inserisce lo sviluppatore.
- **A mano:** Apple Pay su un dominio HTTPS registrato, secondo la checklist della
  guida (D56). Google Pay e Link si provano anche in locale con Chrome.

## 13. Piani

1. **Piano 1, pagamento con il Payment Element:** §4–§10 e le prove di §12 senza
   express. Da solo basta a far pagare il primo negozio.
2. **Piano 2a, metodi separati nel checkout:** §11b con le sue prove.
3. **Piano 2b, Express Checkout:** §11 in cima al checkout, nel carrello e sulla
   scheda prodotto, con le sue prove.

Il piano 2a parte dopo che il piano 1 è unito; il 2b dopo il 2a.

## 14. File toccati (indicativi)

- **app:** `class/App/Credentials.php`, `class/App/Resources/Config/SecurityResource.php`,
  `class/Plugin/Stripe/PaymentIntent.php` e `Connect.php` (nuovi),
  `app/http/api/service/stripe/connect.php` (nuovo) per «Collega webhook».
- **gestionale:** `src/Providers/Payments/*` (nuovi), `src/Support/Payments/PaymentProviders.php`,
  `src/Support/Providers/ProviderEvents.php`, `Ledger` (`attach`, `byReference`),
  `OnlinePayments`, `PaymentEvents`, `OrderEmailExtras` (nuovi), modello dei
  pagamenti (`environment`), `Checkout` (email), `Lifecycle` (avviso al
  commerciante alla conferma, annullo degli intenti),
  `src/Scheduler/StripeReconcileTask.php` (nuovo), `config/routes/route.api.php`
  (nuovo), il bollino «Prova» nelle risorse degli ordini.
- **ecommerce:** `OnlinePayment` (nuovo), `CheckoutController` (fetch, ritorno,
  «Paga ora», annullo), viste e JS del checkout, `ExpressCheckout` e
  `route.api.php` (piano 2b).
- **Piano 2a:** app `class/Plugin/Stripe/PaymentIntent.php` (lettura delle
  configurazioni); gestionale `StripeProvider` (`activeTypes`, tipi in `start`),
  `StripeMethods` (nuovo: nomi, icone, scelte), `Setting`
  (`stripe_methods_cache`), `Payment` (`provider_method`), `Ledger::open`,
  `Checkout` (`preview`, `place`); ecommerce `CheckoutRules`, `CheckoutSummary`,
  `CheckoutController::place`, `view/pages/checkout/index.php`, `checkout.js`.
  `#rapido` e `ExpressCheckout` restano come sono: li riempie il piano 2b.
