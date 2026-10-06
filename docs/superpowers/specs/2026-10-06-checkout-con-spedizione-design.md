# E1c — Checkout con spedizione, ritiro e coupon (D5)

- **Sotto-progetto:** compito D5 di `packages/ecommerce/TODO.md`, parte di E1c; viene dopo G7
- **Stato:** disegno approvato il 2026-10-06 (una pagina sola con riepilogo dal vivo, due piani)
- **Documento di riferimento:** [ordini e pagamenti](2026-09-29-ordini-e-pagamenti-design.md)
  (`Cart`, `Checkout`, `PaymentTiming`); [sconti e coupon](2026-10-05-sconti-e-coupon-design.md)
  (`Coupons`); [spedizioni](2026-10-05-spedizioni-design.md) (`Shipping`, ritiro in sede);
  [guscio del negozio](2026-09-29-negozio-online-guscio-design.md); `packages/ecommerce/docs/cart-checkout.md`
- **Dipende da:** G4, G6 (coupon), G7 (listini, ritiro); il modulo `ecommerce` con carrello e checkout (D2–D5, D9)

## Contesto

Il checkout del modulo `ecommerce` è un modulo unico: contatto, fatturazione,
indirizzo di spedizione, metodo di pagamento e note, inviati insieme a
`Checkout::place()`. Passa sempre `fulfillment_type = shipping` e
`shipping_method_id = 0`: l'ordine nasce senza riga di spedizione. Il riepilogo a
destra mostra le righe e il totale del carrello come era all'apertura della pagina.

G7 ha costruito tutto quello che serve per scegliere la consegna: `Shipping::options()`
dà i metodi che coprono la destinazione del carrello con il loro prezzo,
`Cart::recalculate()` tiene la riga `shipping` del metodo scelto (o la toglie, con
`shipping_dropped`), `Checkout::place()` scrive la `cod_fee` del contrassegno, le sedi
hanno `is_pickup_point`, i metodi di pagamento hanno `available_for`.

Il prezzo della spedizione dipende dalla destinazione, che il cliente scrive nella
stessa pagina. Per questo il riepilogo deve ricalcolarsi mentre il cliente compila.

Nel controller c'è anche un difetto da sistemare qui: rifiuta ogni metodo il cui
`provider` non è `manual`, ma da G7 i provider manuali si chiamano `bank_transfer` e
`cash` (`PaymentMethod::MANUAL_PROVIDERS`). Oggi il checkout non accetta nessun metodo.

## Decisioni prese nel brainstorming

| Tema | Scelta | Alternative scartate |
|---|---|---|
| Forma | **Una pagina sola**: moduli a sinistra, riepilogo a destra che si aggiorna mentre il cliente compila | due passi (indirizzi, poi spedizione e pagamento); una pagina con calcolo solo all'invio |
| Riepilogo | Righe, spedizione, sconto, commissione, totale; sotto il **coupon** e il pulsante | coupon solo nel carrello |
| Pulsante | «Invia ordine» per i metodi manuali, «Vai al pagamento» per i provider online | un testo unico |
| Provider online | **Fuori da D5**: il pulsante cambia testo, ma Stripe, PayPal e Nexi restano «non ancora disponibili» fino a D6 | D5 e D6 insieme |
| Calcolo dal vivo | Un servizio di anteprima nel gestionale, **lo stesso ricalcolo dell'ordine vero** | prezzi calcolati nel modulo `ecommerce` o nel browser |

## Design

### 1. Unità e flusso

```
pagina /checkout/ ──(cambio di paese, provincia, consegna, metodo, sede, pagamento)──▶
  checkout.js ──POST /checkout/summary/──▶ CheckoutController::summary
        ──▶ Checkout::preview(cartId, dati)      (gestionale: scrive, ricalcola, risponde)
        ◀── JSON: righe, totali, metodi di spedizione, sedi, pagamenti, coupon, avvisi
  checkout.js aggiorna riepilogo e scelte

coupon: POST /checkout/coupon/ ──▶ Coupons::apply / remove ──▶ Checkout::preview ──▶ stesso JSON

invio: POST /checkout/ ──▶ CheckoutController::place ──▶ Checkout::place (ricontrolla tutto)
```

Il modulo `ecommerce` non calcola niente: legge quello che il gestionale risponde. La
pagina resta un `<form>` vero: senza JavaScript si compila e si invia, solo senza
prezzi dal vivo, e il server ricontrolla tutto in `place`.

### 2. Piano 1 — Gestionale: anteprima del checkout

**`Checkout::preview(int $cartId, array $data): array`**, accanto a `place`, con gli
stessi nomi dei dati (`fulfillment_type`, `shipping_method_id`, `location_id`,
`payment_method_id`, `billing`, `shipping`). In una transazione:

1. **Scrive sul carrello** consegna, metodo di spedizione, sede, metodo di pagamento e
   indirizzi. Non scrive email, telefono e note: servono solo all'ordine vero. Un
   campo che il modello rifiuta non blocca l'anteprima: si riscrive senza quel campo
   e la risposta lo elenca in `invalid`. Il carrello deve essere ancora un carrello
   (`cart.not_a_cart`).
2. **Ripulisce le scelte che non valgono più**, invece di rifiutarle:
   - consegna che non esiste o ritiro senza sedi disponibili → `shipping`;
   - con il ritiro, sede che non è una sede di ritiro disponibile (attiva, di ritiro,
     con la sede della società dietro) → nessuna sede;
     se ce n'è una sola, la sceglie;
   - metodo di pagamento non attivo, non offerto online o non ammesso per la
     consegna (`available_for`) → nessun metodo.
3. **Sostituisce la commissione** del metodo di pagamento, con la stessa regola di
   `place` (`applyFee`, contrassegno compreso). Senza metodo, nessuna commissione.
4. **Ricalcola** con `Cart::recalculate`: prezzi, campagne, coupon e riga di
   spedizione passano dalle regole dell'ordine vero.
5. **Sceglie il metodo di spedizione** quando la consegna è `shipping`: se il metodo
   scelto non è più tra le opzioni lo toglie (`shipping_dropped` dice perché), se ce
   n'è uno solo e nessuno è scelto lo sceglie, e in tutti e due i casi ricalcola.

Risposta:

```
order:            totali del carrello (products_total, discount_total, shipping_total, fees_total, total, currency)
items:            le righe, come Cart::contents
fulfillment:      { type, choices: [shipping, pickup?] }   pickup solo con sedi di ritiro disponibili
shipping_methods: Shipping::options + selected            vuoto con il ritiro o con `shipping` spenta
pickup_locations: [{ id, name, address }] + selected      sedi di ritiro disponibili
payment_methods:  [{ id, name, provider, manual, instructions }] + selected   ammessi per la consegna
coupon:           { code, dropped }                        dropped = chiave del motivo, se è caduto
notices:          [shipping_dropped, coupon_dropped, …] come chiavi di traduzione
invalid:          campi rifiutati dal modello
```

`preview` non prenota merce, non prende numeri, non conta utilizzi del coupon e non
manda email: l'ordine nasce solo con `place`.

**`Checkout::place` controlla due cose in più**, perché adesso il cliente le sceglie:

- **ritiro:** la sede deve essere una sede di ritiro disponibile
  (`order.pickup_location_unavailable`), con la stessa regola di `Shipments`;
- **spedizione online:** con la funzionalità `shipping` accesa, consegna `shipping`,
  canale `online` e righe da spedire, serve un metodo che copra la destinazione
  (`order.shipping_method_required`; se nessun metodo la copre,
  `order.shipping_unavailable`). Gli ordini dell'ufficio e della demo restano come
  oggi.

La regola delle sedi di ritiro (attiva, di ritiro, non eliminata, con la sede della
società dietro) va in un posto solo, usato da `preview`, `place` e `Shipments`.
**L'orario non conta al checkout:** «aperta» in `SocietyLocations::isOpen` vuol dire
aperta *adesso*, e chi ordina la sera ritira domani. L'orario resta un controllo di
`Shipments::createPickup`, quando la merce è pronta.

### 3. Piano 2 — Modulo `ecommerce`: la pagina

**Sinistra**, nell'ordine:

1. contatto (email, cellulare);
2. fatturazione;
3. **consegna**: Spedizione / Ritiro in sede. La scelta compare solo se il ritiro è
   possibile; con la funzionalità `shipping` spenta la sezione non c'è e tutto resta
   come oggi;
4. con la spedizione: l'indirizzo di spedizione e i **metodi di spedizione** come
   scelte con nome, tempi di consegna e prezzo («Gratis» se è gratuita). Se nessun
   metodo copre la destinazione, un avviso dice che a quell'indirizzo non si spedisce;
5. con il ritiro: le **sedi** con indirizzo (preselezionata se è una sola);
   l'indirizzo di spedizione sparisce;
6. pagamento: solo i metodi ammessi per la consegna scelta, con le istruzioni;
7. note.

**Destra**, il riepilogo che resta visibile scorrendo:

- le righe, poi spedizione, sconto, commissione del pagamento e totale;
- il campo **coupon** («Hai un codice sconto?») con *Applica*; con un coupon attivo
  ne mostra il codice e *Togli*. Gli errori sono i messaggi del gestionale;
- il pulsante: **«Invia ordine»** con un metodo manuale, **«Vai al pagamento»** con
  un provider online. Con un provider online l'invio dice ancora «non ancora
  disponibile» (D6). Senza un metodo scelto il pulsante resta attivo e `place`
  risponde con l'errore;
- il ritorno al carrello.

**Rotte** nuove, con le guardie di `place` (POST, CSRF, cliente autenticato o ospite
ammesso; nessun reCAPTCHA sull'anteprima):

- `POST /checkout/summary/` → `Checkout::preview` → JSON;
- `POST /checkout/coupon/` con `action=apply|remove` e `code` → `Coupons::apply` o
  `remove`, poi `Checkout::preview` → stesso JSON più `error` se il codice è
  rifiutato. Senza JavaScript la stessa rotta torna alla pagina con il messaggio.

**`resources/assets/js/checkout.js`**, sul modello di `mini-cart.js`: ascolta paese,
provincia, consegna, metodo di spedizione, sede e pagamento; dopo una breve pausa
(~300 ms) manda il modulo all'anteprima; scarta le risposte arrivate dopo una più
recente; ridisegna riepilogo, metodi, sedi, pagamenti e testo del pulsante. Se la
chiamata fallisce lascia la pagina com'è con un avviso. All'apertura della pagina
chiama subito l'anteprima, così la prima vista è già quella vera.

**`place`** legge dal modulo anche `fulfillment_type`, `shipping_method_id` e
`location_id`, e riconosce i metodi manuali con `PaymentMethod::MANUAL_PROVIDERS`
(il difetto del Contesto). Il coupon resta quello del carrello.

**Testi** in `lang/it` e `lang/en` del modulo; i motivi dei rifiuti sono le chiavi del
gestionale.

### 4. Errori, casi limite e test

- **Funzionalità spenta** (`shipping`): nessuna scelta di consegna, nessun metodo,
  nessuna riga di spedizione; il checkout funziona come oggi. `coupons` spenta: il
  campo coupon non c'è.
- **Destinazione non coperta:** nessun metodo; l'avviso nella pagina; `place` rifiuta
  con `order.shipping_unavailable`.
- **Metodo che cade** mentre il cliente cambia indirizzo: lo toglie l'anteprima e lo
  dice l'avviso.
- **Coupon che cade** (spesa minima, prodotti esclusi): lo toglie il ricalcolo; il
  motivo arriva in `coupon.dropped`.
- **Sede che chiude** (spenta o tolta dai punti di ritiro) tra l'anteprima e l'invio:
  `place` rifiuta.
- **Due schede aperte** sullo stesso carrello: vince l'ultima anteprima; `place`
  ricalcola comunque con i dati del modulo inviato.

*Test del gestionale (Piano 1):* `CheckoutPreviewTest` d'integrazione: dati parziali
senza email; riga di spedizione che compare scegliendo un metodo e sparisce cambiando
paese verso una zona non coperta; preselezione dell'unico metodo; commissione che si
sostituisce cambiando pagamento, `cod_fee` col contrassegno; ritiro senza riga di
spedizione e con pagamenti filtrati; sede non di ritiro ignorata; coupon caduto;
nessuna prenotazione, nessun numero. Per `place`: ritiro con sede spenta o non di
ritiro rifiutato, e accettato fuori orario; ordine online senza metodo rifiutato con `shipping` accesa e
accettato con `shipping` spenta.

*Test del modulo (Piano 2):* controller con l'anteprima (JSON con i campi attesi,
CSRF obbligatorio, ospite non ammesso), coupon applicato e rifiutato, ordine con
spedizione e riga di spedizione, ordine con ritiro, metodo `bank_transfer` accettato
e `stripe` rifiutato con «non ancora disponibile»; il contenuto di `checkout.js`
controllato come quello di `mini-cart.js`.

*Documenti:* `docs/cart-checkout.md` del modulo, guide del gestionale sul checkout se
servono, i due `CHANGELOG`, i due `TODO.md` (D5 chiuso).

*Prova nel browser* dell'utente su `ecommerce.test`: spedizione in Italia e nelle
isole, zona non coperta, ritiro, coupon applicato e tolto, bonifico e contrassegno.

## I due piani

1. **Gestionale — anteprima del checkout:** `Checkout::preview`, le regole delle sedi
   di ritiro in un posto solo, i due controlli nuovi di `place`, test e guida.
2. **Ecommerce — la pagina:** rotte `summary` e `coupon`, pagina a due colonne,
   `checkout.js`, `place` con consegna e metodi manuali giusti, testi, test, documenti.

Il Piano 2 parte da un repository `ecommerce` pulito: oggi su `main` ci sono molte
modifiche non salvate (catalogo, scheda prodotto, mini-carrello) che vanno prima
chiuse in un commit da chi le sta facendo.

## Fuori da D5

Gli adapter dei pagamenti online e le loro callback (D6); il checkout ospite in
produzione (D4); la scelta dell'indirizzo tra quelli salvati nell'account;
«spedisci allo stesso indirizzo della fatturazione»; orari di ritiro prenotabili;
tariffe in tempo reale dei corrieri.
