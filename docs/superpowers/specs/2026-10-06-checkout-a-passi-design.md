# E1c — Checkout a passi: Carrello, Spedizione, Pagamento e ospite

- **Sotto-progetto:** seguito di D5 in `packages/ecommerce/TODO.md`, parte di E1c
- **Stato:** sostituita il 2026-10-07 da [checkout a pagina unica](2026-10-07-checkout-pagina-unica-design.md); restano validi §4, §6 e §7
- **Sostituisce:** la forma «una pagina sola» di
  [checkout con spedizione](2026-10-06-checkout-con-spedizione-design.md). Restano valide
  l'anteprima del gestionale (`Checkout::preview`), le rotte JSON `summary` e `coupon` e il
  `checkout.js` che scarta le risposte vecchie.
- **Documenti di riferimento:** [ordini e pagamenti](2026-09-29-ordini-e-pagamenti-design.md),
  [sconti e coupon](2026-10-05-sconti-e-coupon-design.md), [spedizioni](2026-10-05-spedizioni-design.md),
  `packages/ecommerce/docs/cart-checkout.md`, `packages/ecommerce/docs/authentication.md`,
  `packages/lib/docs/styles/components.md`
- **Moduli toccati:** `lib` (CSS), `app` (Element, renderer, `PasswordReset`), `gestionale`
  (anteprima, email dell'ordine), `ecommerce` (pagine, controller, ospite)

## Contesto

Oggi il checkout è una pagina sola: contatto, fatturazione, consegna, pagamento e note uno
sotto l'altro in box `wi-box`, con il riepilogo a destra. Il carrello è una griglia di
articoli con i totali a destra, senza coupon. All'utente non piace né lo stile né la forma.

Il riferimento è uno screen con due box:

- a sinistra un box bianco con il percorso «Cart › Shipping › Payment», l'indirizzo
  (nome, cognome, email, telefono, città, provincia, CAP, descrizione) e i metodi di
  spedizione come riquadri con radio, nome, tempi e prezzo a destra;
- a destra «Your Cart»: miniature con il badge della quantità, nome, variante e prezzo, il
  codice sconto con «Apply», subtotale, spedizione, tasse, totale e un pulsante nero a tutta
  larghezza «Continue to Payment».

L'utente chiede:

1. Spedizione e Pagamento come **due passi distinti** del checkout;
2. lo schema dello screen: il box sinistro cambia con il passo; il box destro è il carrello
   in Spedizione e Pagamento, mentre nella pagina del carrello ha **solo totali e coupon**;
3. lo stile dalla **lib**, CSS su misura solo se serve, e componenti nuovi in `lib` o `app`
   quando servono;
4. l'acquisto **da ospite**: l'account si crea comunque, ma il cliente non deve mettere la
   password.

## Decisioni prese nel brainstorming

| Tema | Scelta | Alternative scartate |
|---|---|---|
| Forma | **Una pagina per passo, generata dal server**: `/cart/`, `/checkout/` (Spedizione), `/checkout/payment/` (Pagamento). Funziona senza JS; indietro e ricarica funzionano | passi in una pagina sola mostrati e nascosti dal JS |
| Dati tra un passo e l'altro | Salvati **sul carrello** (`gst_orders`, `stage = cart`) | sessione PHP |
| JS | Aggiorna solo il riepilogo a destra, i metodi di spedizione e il coupon | guidare i passi |
| Email di un ospite già registrata | **L'ordine va su quell'account senza fare l'accesso**; l'ospite non vede ordini né indirizzi dell'account | chiedere l'accesso; ordine senza account |
| Fatturazione | Casella **«Uguale alla spedizione»** spuntata per default; togliendola si compila un altro indirizzo. Riquadro a parte **«Mi serve la fattura»** | fatturazione sempre da compilare |
| Ritiro in sede | La fatturazione chiede **sempre** l'indirizzo | copiare l'indirizzo della sede |
| Ospite | `checkout.guest_enabled` resta un'opzione del modulo, **spenta per default**; si accende sul sito di prova | sempre acceso |
| Password dell'ospite | Link **«Scegli la password»** nell'email dell'ordine, con un token monouso del core | email separata; password al checkout |
| Consensi | Privacy e condizioni di vendita al passo **Pagamento** | al passo Spedizione |
| Componenti | `.wi-choice`, `.wi-steps`, `.wi-thumb` nella **lib**; Element `Choice`, `ChoiceGroup` e `Steps` in **app** | CSS solo nel modulo |

## Design

### 1. Pagine, rotte e flusso

| Rotta | Nome | Cosa fa |
|---|---|---|
| `GET /cart/` | `ecommerce.cart.index` | passo **Carrello** (c'è già) |
| `GET /checkout/` | `ecommerce.checkout.index` | passo **Spedizione** (c'è già: cambia la vista) |
| `POST /checkout/shipping/` | `ecommerce.checkout.shipping` | **nuova**: salva la Spedizione sul carrello e manda al Pagamento |
| `GET /checkout/payment/` | `ecommerce.checkout.payment` | **nuova**: passo **Pagamento** |
| `POST /checkout/` | `ecommerce.checkout.place` | c'è già: ora parte dal Pagamento |
| `POST /checkout/summary/` | `ecommerce.checkout.summary` | c'è già (JSON) |
| `POST /checkout/coupon/` | `ecommerce.checkout.coupon` | c'è già (JSON); ora risponde anche **senza JS** |
| `GET /checkout/completed/` | `ecommerce.checkout.completed` | c'è già |

Il flusso:

1. **Carrello.** «Procedi alla spedizione» porta a `/checkout/`.
2. **Spedizione.** Il modulo va in POST a `ecommerce.checkout.shipping`. Il controller
   costruisce i dati con `CheckoutForm`, li scrive sul carrello con `Checkout::preview` e
   li controlla con le regole del passo (§3). Se mancano dati, torna a `/checkout/` con gli
   errori e i valori. Altrimenti reindirizza a `/checkout/payment/` (POST → redirect → GET).
3. **Pagamento.** Il modulo va in POST a `ecommerce.checkout.place`. Il controller unisce i
   dati della Spedizione letti dal carrello con quelli del Pagamento, poi chiama
   `Checkout::place`, che resta l'ultima parola su tutto.

**Guardie**, in quest'ordine, su ogni passo del checkout:

- ospite non abilitato e utente non autenticato → login con `?continue=`;
- carrello vuoto → `/cart/`;
- solo su `/checkout/payment/` e su `place`: Spedizione incompleta → `/checkout/` con un
  avviso («Completa prima i dati di spedizione»).

La Spedizione è **completa** quando sul carrello ci sono email valida, telefono, nome e
cognome e:

- con la spedizione: i campi obbligatori di `Order::shippingAddress()` e un
  `shipping_method_id` fra i metodi che l'anteprima propone (se le spedizioni non sono
  attive basta l'indirizzo);
- con il ritiro: un `location_id` fra le sedi che l'anteprima propone.

La regola sta in una classe sola del modulo, `CheckoutSteps::shippingComplete(array $cart,
array $preview): bool`. La usano il POST della Spedizione, la guardia del Pagamento e `place`.

### 2. Impostazione comune delle pagine

I tre passi usano il layout `ecommerce.checkout`. Il carrello ora usa lo stesso layout di
checkout e Pagamento invece di `ecommerce.shop`: i tre passi hanno così lo stesso aspetto.
La pagina è una griglia a due colonne della lib: il box sinistro largo circa due terzi, il
destro un terzo. Su telefono le colonne vanno una sotto l'altra.

**Box sinistro** (`wi-box`):

- in alto il percorso **Carrello › Spedizione › Pagamento** (`Steps`): i passi fatti sono
  link, quello in corso è evidenziato (`aria-current="step"`), i successivi sono spenti e
  non cliccabili;
- sotto, il contenuto del passo (§3).

**Box destro** (`wi-box`, `<aside>`):

- nel **Carrello**: codice sconto con «Applica» e «Togli», poi subtotale, sconto, totale e il
  pulsante a tutta larghezza «Procedi alla spedizione». Niente righe: gli articoli sono già
  a sinistra;
- in **Spedizione** e **Pagamento**: titolo «Il tuo carrello», poi una riga per articolo
  (miniatura `.wi-thumb` con il badge della quantità, nome, variante sotto il nome, prezzo
  della riga a destra), il codice sconto, i totali (subtotale, sconto se c'è, spedizione se
  si spedisce, commissioni se ci sono, totale) e il pulsante del passo:
  - in Spedizione «Continua al pagamento» (`form="checkout"`);
  - in Pagamento «Ordina» per i metodi manuali, «Vai al pagamento» per i provider online,
    come oggi.

Il pulsante principale è `btn` della lib, scuro e a tutta larghezza (`w-100`).

**Su telefono**, in Spedizione e Pagamento, sopra il box sinistro c'è un `<details>`
richiudibile, chiuso per default, con «Mostra carrello» e il totale nel `<summary>` e le
righe dentro. Il box destro completo (coupon, totali, pulsante) va sotto il box sinistro. Il
`<details>` c'è solo sotto la larghezza tablet; sopra c'è solo il box destro. Righe e totali
escono dalla stessa vista parziale. Il JS aggiorna tutte le copie con `querySelectorAll` sugli
stessi ganci `data-checkout-*`.

Gli avvisi della pagina (errori, notice, coupon tolto) restano nell'`Alert` del layout.
Quelli del riepilogo stanno in `data-checkout-notices`.

### 3. I tre passi

#### Carrello (`/cart/`)

- A sinistra: titolo, numero di articoli e un elenco di righe nello stile del box destro:
  miniatura, nome e variante, prezzo, quantità con «Aggiorna», «Rimuovi». Restano i form
  `cart_quantity_ID` e `cart_remove_ID`, perché GTM li riconosce.
- Carrello vuoto: un solo box con il messaggio e il link al negozio, senza box destro.
- Il coupon funziona anche senza JS (§5).

#### Spedizione (`/checkout/`)

Il modulo `#checkout` ha tre gruppi.

1. **Contatto**: nome, cognome, email, telefono. L'ospite vede sopra «Hai già un account?
   Accedi», che porta al login con `?continue=` verso `/checkout/`. L'utente autenticato
   trova i campi precompilati come oggi (`defaults()`).
2. **Consegna**: «Spedizione» e «Ritiro in sede» come due `Choice`. Il ritiro c'è solo se
   l'anteprima lo propone.
   - **Spedizione**: l'indirizzo (`Order::shippingAddress()`, senza nome, cognome e
     telefono, che vengono dal Contatto), poi «Metodo di spedizione» come `ChoiceGroup`. Ogni
     metodo mostra nome, tempi (`description`) e prezzo a destra. Se nessun metodo copre
     l'indirizzo, c'è l'avviso di oggi (`no_shipping`). Se le spedizioni non sono attive, i
     metodi non ci sono.
   - **Ritiro**: le sedi come `ChoiceGroup`, con nome e indirizzo.
3. Il pulsante «Continua al pagamento» nel box destro e, senza JS, lo stesso pulsante in
   fondo al box sinistro. Con il JS attivo quello in fondo si nasconde su desktop.

Dove vanno i dati del Contatto:

- `email` e `phone` vanno nelle colonne dell'ordine;
- nome, cognome e telefono vanno anche in `shipping_name`, `shipping_surname` e
  `shipping_phone`: sono il destinatario o, con il ritiro, chi ritira. Con il ritiro gli
  altri campi `shipping_*` restano vuoti.

Il JS chiama `summary` mentre il cliente compila, come oggi. Ridisegna i metodi di
spedizione e il riepilogo; non tocca i campi del modulo.

#### Pagamento (`/checkout/payment/`)

1. **Riepilogo** in un box leggero: «Contatto» (email, telefono) e «Consegna» (indirizzo e
   metodo, oppure la sede). Ognuno ha «Modifica», che porta a `/checkout/#contatto` o
   `/checkout/#consegna`.
2. **Metodo di pagamento** come `ChoiceGroup` (nome; istruzioni sotto il nome; commissione a
   destra se c'è). Cambiare metodo chiama `summary` e aggiorna commissioni e totale.
3. **Fatturazione**:
   - con la spedizione: casella «Fatturazione uguale alla spedizione», spuntata per default.
     Togliendola compaiono i campi di `Order::billingAddress()` (indirizzo, nome, cognome,
     telefono);
   - con il ritiro: niente casella, i campi ci sono sempre e nome, cognome e telefono sono
     precompilati dal Contatto;
   - spuntata, il controller copia in `billing_*` i campi `shipping_*` che hanno lo stesso
     nome, con `billing_type = private`.
4. **«Mi serve la fattura»**: una casella che apre il riquadro dei dati fiscali, presi da
   `Order::billingAddress()`: tipo (privato o azienda) come due `Choice`; per il privato il
   codice fiscale; per l'azienda ragione sociale, P.IVA, codice fiscale, SDI e PEC. Senza la
   casella i dati fiscali restano vuoti e il tipo è `private`.
5. **Note** (`customer_note`).
6. **Consensi**: privacy (`privacy_policy`) e condizioni di vendita (`terms_conditions`),
   come `FormField::acceptDocument`, obbligatori. Si vedono all'ospite e all'utente
   autenticato che non li ha ancora accettati (`ConsentService::getUserConsents`).
7. **reCAPTCHA** (`ecommerce_checkout`) per l'ospite, come oggi.
8. «Ordina» nel box destro (e in fondo al box sinistro senza JS).

Le caselle «Uguale alla spedizione» e «Mi serve la fattura» aprono e chiudono il loro
riquadro con un attributo `data-checkout-toggle` letto da `checkout.js`. Senza JS i riquadri
sono sempre aperti e il server legge solo lo stato delle caselle.

`place` controlla i campi obbligatori del passo: fatturazione completa se non è «uguale»,
dati fiscali se c'è «Mi serve la fattura» (CF per il privato; ragione sociale e P.IVA per
l'azienda), consensi se erano chiesti. Se mancano, torna a `/checkout/payment/` con errori
e valori, come oggi fa con `/checkout/`.

### 4. Ospite

`checkout.guest_enabled` resta `false` nel `config/module.php` del modulo. Il sito di prova
la accende con il suo override della configurazione.

**Alla conferma dell'ordine** (`place`, solo per l'ospite, dopo che i controlli del passo
sono passati e prima di `Checkout::place`):

1. si cerca l'utente con `EcommerceUserAccountGateway::findUserByEmail($email)`;
2. **email nuova** → si crea l'account con il nuovo
   `UserAccountGateway::createUserWithoutPassword(string $name, string $surname, string $email, string $area): int`.
   Il metodo segue `createUserFromFederatedIdentity`: `User::create` senza password, attivo,
   area del negozio, ma senza segnare l'email come verificata. Poi:
   - `ContactAccount::link($userId, ['phone' => …])` crea il contatto;
   - i consensi dati al Pagamento si salvano con `ConsentService::registerBaseConsents`;
3. **email esistente** → l'ordine si collega a quell'utente e al suo contatto. Si cerca il
   contatto con `user_id`; solo se non c'è, `ContactAccount::link($userId)` lo crea. I dati
   del contatto e dell'account **non si toccano**. I consensi si salvano con
   `ConsentService::registerLeadConsents($email, …)`, così l'accettazione resta tracciata
   senza cambiare quelli dell'account;
4. `Checkout::place` riceve `customer_id` del contatto e `user_id` dell'utente. La sessione
   resta da ospite: nessun accesso automatico.

Se `Checkout::place` fallisce dopo che l'account è stato creato, l'account resta senza
ordini. Al tentativo successivo la sua email è «esistente» e si riusa.

**Il link «Scegli la password».** Se l'utente non ha una password locale
(`hasLocalPassword` falso, quindi anche un ospite tornato una seconda volta), il controller
emette un token con `(new PasswordReset(7 * 86400))->issueForUser($userId, $ordersUrl)` e
passa a `Checkout::place` `$data['customer_email'] = ['account_url' => $restoreUrl]`.
`$restoreUrl` è la rotta `password.restore` con il token, in URL assoluto. Nel gestionale:

- `Checkout::place` passa `$data['customer_email']` come `$extra` all'email del cliente:
  `OrderNotifier::send('received', …, $extra)`, oppure, per il pagamento alla consegna,
  `Lifecycle::confirm(…, ['email_extra' => $extra])`, che lo inoltra a
  `OrderNotifier::send('confirmed', …)`;
- `OrderEmail` mostra il blocco «Crea la tua password per seguire i tuoi ordini» con il
  pulsante solo se `account_url` c'è. I testi sono in `lang/it` e `lang/en` del gestionale.

Scaduto il link, il cliente usa «Password dimenticata», che funziona perché l'account esiste.

**Verifica dell'email.** `PasswordReset::reset` segna l'email come verificata
(`markUserEmailVerified`): il token è arrivato a quella casella, quindi il possesso è
provato. Vale anche per il recupero della password normale.

**Pagina di conferma.** All'ospite a cui è partito il link dice «Ti abbiamo mandato
un'email per scegliere la password e seguire i tuoi ordini». Non mostra mai dati
dell'account.

### 5. Coupon senza JS

Il form del coupon (`#checkout-coupon`) va in POST alla rotta `coupon` con un campo nascosto
`return`, che può valere solo `cart`, `checkout` o `payment`.

- Richiesta con `X-Requested-With` → JSON come oggi.
- Senza → redirect alla pagina indicata, con il messaggio del coupon (applicato, tolto o
  errore) nel flash.

Il carrello usa lo stesso form; lì il JS fa solo il coupon, senza `summary`.

### 6. Gestionale: cosa cambia

- `Checkout::preview` scrive sul carrello anche `email` e `phone` con `writeLoosely`: un
  valore che il modello rifiuta finisce in `invalid` come gli altri. Il commento della classe
  va aggiornato: l'anteprima scrive email e telefono ma non li chiede.
- `Checkout::place` accetta `$data['customer_email']` (array, facoltativo) e lo passa
  all'email del cliente, come al §4.
- `Lifecycle::confirm` accetta l'opzione `email_extra` e la inoltra all'email `confirmed`.
- `OrderEmail`: blocco facoltativo `account_url` nelle email `received` e `confirmed`.

### 7. Componenti nuovi

**Lib** (`lib/src/build/frontend/css/components/`, documentati in
`lib/docs/styles/components.md`):

- `choice.css` — `.wi-choice`: un `<label>` che contiene l'`input` (radio o checkbox) e il
  contenuto, organizzato in tre parti:
  - `.wi-choice__body` con `.wi-choice__title` e `.wi-choice__text`;
  - `.wi-choice__aside` a destra, per prezzo o commissione;
  - i bordi e i colori vengono dalle variabili della lib.

  Il riquadro scelto (`:has(input:checked)`) ha il bordo scuro, il focus da tastiera
  (`:has(input:focus-visible)`) l'anello di focus e quello spento (`:has(input:disabled)`)
  l'opacità ridotta. `.wi-choice-group` è il `fieldset` che li impila con lo spazio della
  lib. Su telefono `.wi-choice__aside` va sotto il titolo.
- `steps.css` — `.wi-steps`: un `<ol>` in riga con il separatore «›». Il passo in corso
  (`[aria-current="step"]`) è in grassetto; i passi spenti (`.is-disabled`) sono attenuati.
  Su telefono il percorso resta su una riga e scorre in orizzontale.
- `thumb.css` — `.wi-thumb`: miniatura quadrata con angoli e sfondo della lib e un `.badge`
  della lib in alto a destra per la quantità.

**App** (`class/Elements/Components/`, con renderer in `class/Themes/Wonder/Components/` e
`class/Themes/Bootstrap/Components/`, come gli altri componenti):

- `Choice::make(string $name, string|int $value)` con `->type('radio'|'checkbox')`,
  `->title(string)`, `->text(string)`, `->aside(string)`, `->checked(bool)`,
  `->disabled(bool)` e `->attr()` del componente base.
  - Wonder rende il markup `.wi-choice`.
  - Bootstrap rende un `form-check` dentro un `card`.
- `ChoiceGroup::make(string $legend)` con `->choices(Choice ...$choices)` e
  `->attr()`: un `fieldset` con la `legend` e i `Choice`.
- `Steps::make()` con `->step(string $label, ?string $href = null, string $state = 'todo')`;
  `$state` vale `done`, `current` o `todo`.
  - Wonder rende `.wi-steps`.
  - Bootstrap rende il `breadcrumb`.
  - Un passo `done` con `$href` è un link; `current` ha `aria-current="step"`; `todo` non è
    cliccabile.

I testi passano da `e()`: nessun HTML grezzo nei parametri.

Il JS non duplica il markup. Le viste stampano un `<template data-checkout-choice>` reso da
`Choice`; `checkout.js` lo clona per ogni metodo e riempie i campi con `textContent`. Il
divieto di `innerHTML` nei test resta.

Il CSS su misura del modulo `ecommerce` resta a zero: non nasce un file CSS del checkout.

### 8. Errori e casi limite

- **Pagamento aperto direttamente** senza Spedizione completa → redirect a `/checkout/` con
  l'avviso.
- **Carrello cambiato tra i passi** (un articolo tolto in un'altra scheda, un metodo di
  spedizione che non copre più l'indirizzo, una sede chiusa): la guardia del Pagamento
  ricalcola con l'anteprima. Se la Spedizione non è più completa, torna a `/checkout/`.
- **Coupon tolto dal ricalcolo**: l'avviso dell'anteprima (`coupon.dropped`) compare nel
  riepilogo, come oggi.
- **Indietro dal Pagamento**: la Spedizione si apre con i valori del carrello, perché il
  carrello è la fonte.
- **Ospite che accede a metà** dal link «Accedi»: dopo il login torna a `/checkout/`. Il
  carrello del cookie si unisce a quello del cliente (`Cart::merge`, come oggi) e i campi si
  precompilano dal contatto.
- **Email esistente di un utente senza l'area del negozio** (per esempio un utente del
  backend): l'ordine si collega lo stesso all'utente e al contatto. L'area non cambia e il
  link della password non parte: per entrare nel negozio serve la registrazione normale.
- **Doppio invio di «Ordina»**: il pulsante si disattiva all'invio; `place` rifiuta già un
  carrello che non è più `stage = cart`.

### 9. Prove

- **Lib**: le classi nuove sono nel bundle compilato e nella documentazione dei componenti.
- **App**: test dei tre Element con i due temi. Per ogni tema:
  - markup con `.wi-choice` / `form-check`;
  - `checked` e `disabled`;
  - `aria-current` sul passo in corso;
  - link solo sui passi fatti;
  - testo con caratteri HTML escapato.
- **App**: `createUserWithoutPassword` crea un utente senza password e senza email
  verificata; `PasswordReset::reset` segna l'email verificata.
- **Gestionale**:
  - `preview` scrive email e telefono validi e mette in `invalid` un'email rifiutata;
  - `place` porta `account_url` nell'email `received` e, con il contrassegno, in
    `confirmed`;
  - senza `customer_email` le email non cambiano.
- **Ecommerce** (nello stile di `tests/CartCheckoutTest.php`, più prove con il database dove
  il modulo le ha):
  - rotte nuove e nomi;
  - `CheckoutSteps::shippingComplete` con spedizione, ritiro, spedizioni spente, metodo
    non più valido;
  - guardie del Pagamento;
  - «uguale alla spedizione» copia gli indirizzi; «mi serve la fattura» chiede i dati
    fiscali;
  - consensi chiesti all'ospite e non a chi li ha;
  - ospite con email nuova: account senza password, contatto, consensi, link nell'email;
  - ospite con email esistente: ordine sull'account, dati dell'account invariati, nessun
    accesso;
  - coupon senza JS con `return`;
  - testi in it/en;
  - nessun `render('wonder')` forzato;
  - nessun file CSS del checkout.
- **Browser** su `ecommerce.test`, con l'account di prova e da ospite, a larghezza desktop e
  telefono:
  - spedizione con coupon e bonifico;
  - ritiro con contrassegno;
  - ospite con email nuova (arriva il link, la password si sceglie);
  - ospite con l'email dell'account di prova;
  - indietro e ricarica in ogni passo.

## I tre piani

1. **Componenti** (`lib`, `app`): `.wi-choice`, `.wi-steps` e `.wi-thumb` con la loro
   documentazione; gli Element `Choice`, `ChoiceGroup` e `Steps` con i due renderer. Si
   prova da solo, con i test di app.
2. **Passi** (`gestionale`, `ecommerce`):
   - anteprima con email e telefono;
   - rotte `shipping` e `payment`, `CheckoutSteps`, le tre viste e il layout comune;
   - fatturazione «uguale» e «mi serve la fattura»;
   - consensi per l'utente autenticato che non li ha;
   - coupon senza JS e `checkout.js` aggiornato.

   Alla fine si compra da utente autenticato.
3. **Ospite** (`app`, `gestionale`, `ecommerce`):
   - `createUserWithoutPassword` e la verifica dell'email al reset;
   - `customer_email` ed `email_extra` nelle email dell'ordine;
   - account e consensi alla conferma, link della password e pagina di conferma;
   - opzione accesa sul sito di prova;
   - documentazione (`cart-checkout.md`, `authentication.md`), `CHANGELOG` e `TODO`.

## Fuori da questo lavoro

- I provider di pagamento online (Stripe, PayPal, Nexi): restano «non ancora disponibili»
  fino a D6.
- La scelta fra più indirizzi salvati dell'utente autenticato: si precompila quello di
  default, come oggi.
- Il checkout veloce (Apple Pay, Google Pay) e il carrello abbandonato.
- La tassa come riga a sé del riepilogo: i prezzi restano IVA inclusa, come oggi.
