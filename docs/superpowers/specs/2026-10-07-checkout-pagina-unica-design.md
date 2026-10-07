# E1c — Checkout a pagina unica: Carrello e Checkout

- **Sotto-progetto:** seguito di D5 in `packages/ecommerce/TODO.md`, parte di E1c
- **Stato:** disegno approvato il 2026-10-07 (carrello + una pagina di checkout nello stile
  di Shopify; tre piani)
- **Sostituisce:** la forma a tre pagine di [checkout a passi](2026-10-06-checkout-a-passi-design.md).
  Di quella spec restano validi, e qui si richiamano senza ripeterli:
  - §4 **Ospite** (account senza password, link «Scegli la password», email esistente);
  - §6 **Gestionale** (anteprima con email e telefono, `customer_email`, `email_extra`);
  - §7 **Componenti** già fatti (`Choice`, `ChoiceGroup`, `Thumb`; `Steps` resta in lib e
    app ma esce dalle viste del checkout).
- **Documenti di riferimento:** [ordini e pagamenti](2026-09-29-ordini-e-pagamenti-design.md),
  [sconti e coupon](2026-10-05-sconti-e-coupon-design.md), [spedizioni](2026-10-05-spedizioni-design.md),
  `packages/ecommerce/docs/cart-checkout.md`, `packages/lib/docs/styles/components.md`
- **Moduli toccati:** `lib` (CSS), `app` (Element, catalogo dei font), `gestionale`
  (icone dei metodi, font nelle Impostazioni), `ecommerce` (pagine, controller, JS)

## Contesto

Il branch `e1c-checkout-a-passi` ha fatto il checkout a tre pagine (Carrello, Spedizione,
Pagamento). Vedendolo nel browser e confrontandolo con il checkout di Shopify, l'utente
preferisce **il carrello e poi una sola pagina di checkout con tutto**. Gli piacciono:

1. la pulizia della pagina: niente riquadri, titoli e spazio;
2. il **Check-out rapido** in alto (GPay, PayPal) quando c'è un provider attivo;
3. «Subtotale» al posto di «Prodotti»;
4. il link **«Accedi»** accanto al titolo Contatti;
5. la fatturazione «Uguale all'indirizzo di spedizione» **senza campi**;
6. «Mi serve la fattura» con privato/azienda e **solo i campi necessari** a ciascuno;
7. sotto il metodo di pagamento scelto, un **pannello** («Verrai reindirizzato a…») e le
   **icone** dei metodi;
8. il riepilogo di destra **sempre in vista** durante lo scroll;
9. un **font** per accesso, account, checkout e carrello **scelto dal backend**.

## Decisioni prese nel brainstorming

| Tema | Scelta | Alternative scartate |
|---|---|---|
| Forma | **Carrello** (`/cart/`, layout `ecommerce.shop`) + **una pagina di checkout** (`/checkout/`, layout `ecommerce.checkout` su `frontend.minimal`) | tre pagine; una pagina con passi guidati dal JS |
| Branch | **Si adatta** `e1c-checkout-a-passi`: si tiene ciò che vale anche con la pagina unica | ripartire da main; unire prima le tre pagine |
| Check-out rapido | **Predisposto, visibile solo se attivo**: oggi nessun provider è collegato, quindi non si vede | collegare Stripe ora; rimandarlo del tutto |
| Font | **Catalogo di 9 font in `app`**, file serviti dal sito (niente Google Fonts); **quattro impostazioni** nel gestionale | campo libero con Google Fonts; caricamento di file |
| Icone dei pagamenti | Campo **«Icone»** sul metodo, set chiuso di SVG da [activemerchant/payment_icons](https://github.com/activemerchant/payment_icons) (MIT, lo stesso set di Shopify), **precompilato dal provider** | icone fisse per provider; immagine libera |
| Righe del riepilogo | Miniatura, nome e variante, prezzo: **senza SKU** | con SKU |
| Fattura dell'azienda | Ragione sociale, partita IVA, SDI, PEC: **senza codice fiscale** | codice fiscale sempre |
| Metodi online senza provider | **Non compaiono** | compaiono e danno errore alla conferma |

## Design

### 1. Pagine, rotte e flusso

| Rotta | Nome | Cosa fa |
|---|---|---|
| `GET /cart/` | `ecommerce.cart.index` | **Carrello**, ora sul layout `ecommerce.shop` |
| `GET /checkout/` | `ecommerce.checkout.index` | la **pagina di checkout** |
| `POST /checkout/` | `ecommerce.checkout.place` | conferma l'ordine |
| `POST /checkout/summary/` | `ecommerce.checkout.summary` | ricalcolo (JSON), c'è già |
| `POST /checkout/coupon/` | `ecommerce.checkout.coupon` | coupon (JSON o redirect), c'è già; `return` vale solo `cart` o `checkout` |
| `GET /checkout/completed/` | `ecommerce.checkout.completed` | c'è già |

Si **tolgono** `POST /checkout/shipping/` (`ecommerce.checkout.shipping`) e
`GET /checkout/payment/` (`ecommerce.checkout.payment`), con il loro codice nel controller.

Il flusso: dal carrello «Vai al checkout» porta a `/checkout/`. La pagina è **un solo form**
`#checkout` in POST a `place`. `place` costruisce i dati con `CheckoutForm`, li controlla con
le regole (§6) e chiama `Checkout::place`, che resta l'ultima parola. Se mancano dati torna
a `/checkout/` con gli errori nel flash e i valori; altrimenti va a `completed`.

**Guardie** di `/checkout/` e `place`, in quest'ordine:

- ospite non abilitato e utente non autenticato → login con `?continue=` (come oggi);
- carrello vuoto → `/cart/`.

Mentre il cliente compila, `checkout.js` chiama `summary` quando cambiano indirizzo,
Spedisci/Ritiro, metodo di spedizione o metodo di pagamento. La risposta ridisegna metodi di
spedizione, metodi di pagamento ammessi e riepilogo. È il meccanismo di D5, con le risposte
vecchie scartate.

### 2. Impaginazione

- **Due colonne** da tablet in su: a sinistra il form, largo al massimo circa 560px e
  allineato verso il centro; a destra il riepilogo, con un fondo grigio chiaro che arriva in
  fondo alla pagina e una linea verticale che lo separa.
- **Niente `wi-box`** attorno alle sezioni: ogni sezione è un titolo (`subtitle`) e i suoi
  campi, separati dallo spazio della lib.
- **Riepilogo fisso**: la colonna di destra è `position: sticky; top: 0` con
  `max-height: 100vh` e scorrimento interno se più alta dello schermo.
- **Su telefono**: una colonna. In alto il `<details>` chiuso «Mostra riepilogo» con il
  totale nel `<summary>` (la vista `mobile.php` di oggi), poi il form.
- **Testi** dei bottoni principali: «Ordina» per i metodi manuali, «Paga ora» per i provider
  online. Bottone scuro della lib a tutta larghezza, in fondo al form.
- Le viste rispettano il float del sito: ogni `div` dentro una section ha una `w-*` e
  nessuna griglia è anche uno span (la prova di `0e729ed` resta).

### 3. La colonna di sinistra, in ordine

#### 3.1 Check-out rapido

Un riquadro con il titolo «Check-out rapido», i bottoni dei provider in riga e sotto il
separatore «OPPURE». Si stampa **solo se `ExpressCheckout::buttons($order)` non è vuoto** (§7).
Oggi è sempre vuoto, quindi il riquadro non c'è.

#### 3.2 Contatti

- **Utente autenticato**: niente campi. Una riga con l'email dell'account e, a destra, il
  link «Esci» (logout, poi login con `?continue=` al checkout). L'email dell'ordine è quella
  dell'account; il server ignora un `email` in POST.
- **Ospite** (solo con `checkout.guest_enabled`, piano 3): titolo «Contatti» con a destra il
  link **«Accedi»** (login con `?continue=` a `/checkout/`), poi il campo email.

#### 3.3 Consegna

1. **Interruttore a due segmenti** «Spedisci» (`bi-truck`) / «Ritiro» (`bi-shop`):
   `ChoiceGroup` nella variante a segmenti (§8). «Ritiro» c'è solo se l'anteprima propone
   delle sedi; senza ritiro l'interruttore non si stampa.
2. **Nome e cognome**, per la spedizione e per il ritiro (`shipping_name`,
   `shipping_surname`).
3. Con **Spedisci**: i campi di `Order::shippingAddress()` senza nome, cognome e telefono.
   Con **Ritiro**: le sedi come `ChoiceGroup` a lista unita, con nome e indirizzo.
4. **Telefono** (`phone`, copiato anche in `shipping_phone`).
5. **Metodo di spedizione** (solo con Spedisci e spedizioni attive): `ChoiceGroup` a lista
   unita; ogni metodo ha nome, tempi (`description`) e a destra il prezzo o «GRATIS». Finché
   l'anteprima non ha metodi, al posto della lista c'è un riquadro grigio: «Inserisci
   l'indirizzo di spedizione per vedere i metodi disponibili» se l'indirizzo è incompleto,
   l'avviso `no_shipping` di oggi se nessun metodo copre l'indirizzo.

#### 3.4 Pagamento

- Sotto il titolo: «Tutte le transazioni sono sicure e crittografate».
- `ChoiceGroup` a lista unita dei metodi ammessi. Ogni metodo ha radio, nome, a destra le
  **icone** (al massimo tre, poi «+N») e la commissione se c'è.
- Il metodo scelto apre il suo **pannello** grigio (`Choice::panel`, §8, aperto dal CSS con
  `:has(input:checked)`, senza JS):
  - metodi manuali: le `instructions` del metodo; senza istruzioni il pannello non c'è;
  - provider online: icona e «Dopo aver cliccato «Paga ora» verrai reindirizzato a
    {nome del metodo} per completare l'acquisto in modo sicuro».
- **I metodi con un provider online non collegato non compaiono** (§7). Un
  `payment_method_id` non ammesso inviato a mano dà l'errore `payment_method` di oggi.

#### 3.5 Indirizzo di fatturazione

- Con **Spedisci**: due radio, «Uguale all'indirizzo di spedizione» (scelta per default,
  nessun campo) e «Utilizza un indirizzo diverso», che apre i campi di
  `Order::billingAddress()` (indirizzo, nome, cognome, telefono). Il campo si chiama
  `same_as_shipping` con valori `1` e `0`. Con «Uguale» il server copia in `billing_*` i
  campi `shipping_*` che hanno lo stesso nome, come oggi.
- Con **Ritiro**: niente radio, i campi ci sono sempre; nome, cognome e telefono sono
  precompilati da quelli della Consegna.

#### 3.6 Fattura

- La casella «Mi serve la fattura» apre l'interruttore a segmenti «Privato / Azienda».
- **Privato**: solo il codice fiscale (`billing_cf`).
- **Azienda**: ragione sociale, partita IVA, codice SDI, PEC (`billing_business_name`,
  `billing_pi`, `billing_sdi`, `billing_pec`); SDI e PEC facoltativi. Niente codice fiscale.
- Il server tiene solo i campi del tipo scelto e svuota gli altri. Senza la casella i dati
  fiscali restano vuoti e il tipo è `private`.

#### 3.7 Fondo del form

Nota (`customer_note`), consensi (privacy e condizioni, solo se chiesti come oggi),
reCAPTCHA per l'ospite, bottone «Ordina» / «Paga ora».

**Apri e chiudi.** I riquadri che dipendono da una scelta (indirizzo o sedi, metodi di
spedizione, fatturazione diversa, fattura, privato/azienda) usano `data-checkout-toggle`
letto da `checkout.js`. Senza JS sono tutti aperti e il server legge solo le scelte.

### 4. La colonna di destra: riepilogo

In ordine, dalla vista `aside.php` (con le modifiche dell'utente già in locale: coupon prima
dei totali, `h-auto`):

1. **Righe**: miniatura `.wi-thumb` di 64px con il badge della quantità, nome con la
   variante, prezzo della riga a destra. **Niente SKU**.
2. **Codice sconto**: il form del coupon con campo e «Applica» sulla stessa linea (fatto
   dall'utente in `coupon.php`).
3. **Totali**: **Subtotale** (chiave `products_total`, etichetta rinominata anche nel
   carrello), Sconto se c'è, **Spedizione** (solo con Spedisci: «Inserisci l'indirizzo di
   spedizione» finché non è calcolabile, poi il prezzo o «GRATIS»), Commissioni se ci sono,
   **Totale** in grande con «EUR» piccolo davanti.
4. Gli avvisi del riepilogo (`data-checkout-notices`).

Il bottone «Ordina» non sta nel riepilogo: è in fondo al form (§3.7).

### 5. Il carrello

- Layout `ecommerce.shop` (header e footer del sito), con le modifiche locali dell'utente.
- A sinistra le righe con quantità e «Rimuovi» come oggi (`cart_quantity_ID`,
  `cart_remove_ID` restano per GTM); a destra coupon, **Subtotale**, sconto, totale e «Vai
  al checkout».
- Niente `Steps`.

### 6. Regole del form

`CheckoutSteps` diventa **`CheckoutRules`**: non ci sono più passi. Tiene:

- `deliveryErrors(array $order, array $preview, bool $shipping): array` (era
  `shippingErrors`): nome, cognome, telefono, indirizzo o sede, metodo di spedizione fra
  quelli proposti;
- `billing(array $post, array $order): array`: «uguale» e privato/azienda come al §3.5 e §3.6;
- `paymentErrors(array $post, array $billing, array $asked): array`;
- `askedConsents(int $userId): array`.

Si tolgono `shippingComplete` e `fromCart`, che servivano solo tra un passo e l'altro.
`place` chiama `Checkout::preview` sui dati del POST, poi `deliveryErrors` e
`paymentErrors`, e unisce gli errori in un solo flash.

**Errori sotto il campo.** Al clic su «Ordina», `checkout.js` controlla i campi visibili
con la validazione del browser. Per ogni campo non valido scrive sotto il campo un messaggio
«Inserisci {etichetta}» (testo `ecommerce.checkout.field_required`), segna il campo con
`aria-invalid="true"` e porta la pagina al primo errore. Il messaggio sparisce quando il
campo cambia. Gli errori del server restano nell'`Alert` in alto, con i valori ripresi.

### 7. Provider e Check-out rapido predisposti

- **Gestionale**: `Wonder\Plugin\Gestionale\Support\Payments\PaymentProviders` con
  `connected(string $provider): bool`. Vero per i provider manuali (`bank_transfer`, `cash`)
  e per un provider vuoto, **falso** per `stripe`, `paypal`, `nexi` finché D6 non li collega.
- I metodi proposti dal checkout (l'anteprima e `place`) escludono quelli il cui provider
  non è `connected`.
- **Ecommerce**: `ExpressCheckout::buttons(array $order): array` restituisce la lista dei
  bottoni rapidi (`provider`, `label`, `html`) dei provider collegati che li offrono. Oggi
  restituisce `[]`. La vista stampa il riquadro §3.1 solo se la lista non è vuota.

Quando si collegheranno Stripe o PayPal, si toccano solo questi due punti.

### 8. Componenti (lib e app)

Sul branch `checkout-a-passi-componenti` di `lib` e `app` (piano 1, non ancora unito):

- **`ChoiceGroup`**:
  - `->variant('segmented')`: i `Choice` in riga, uniti, con l'icona prima del titolo
    (`.wi-choice-group--segmented`);
  - `->variant('list')`: i `Choice` impilati e uniti, bordi condivisi, angoli solo in cima e
    in fondo (`.wi-choice-group--list`), come le liste di Shopify.
- **`Choice`**:
  - `->icon(string $bootstrapIcon)`: icona prima del titolo (usata dai segmenti);
  - `->icons(array $icons, int $max = 3)`: miniature SVG a destra, ognuna
    `['src' => …, 'alt' => …]`; oltre `$max` un'etichetta «+N»;
  - `->panel(string $text)`: un pannello grigio sotto il `Choice`, visibile solo quando
    l'input è scelto. Testo escapato come gli altri.
- Il renderer Bootstrap rende gli stessi dati con `btn-group` (segmenti), `list-group`
  (lista) e un `div` sotto il `form-check` (pannello).
- **Font**: catalogo in `app`, classe `Wonder\View\WebFonts`:
  - `all(): array<string, string>` chiave → nome (`inter` → «Inter», …);
  - `css(string $key): string` restituisce i `@font-face` e la regola che ridefinisce
    `--font-family`, `--title-big-font-family`, `--title-font-family` e
    `--subtitle-font-family` su `:root`; per una chiave vuota o sconosciuta restituisce `''`.
  - I file stanno in `app/resources/assets/font/web/<Nome>/`, presi da **Fontsource**
    (licenza OFL): versione variable, solo latino, per Inter, Roboto, Open Sans, Montserrat,
    DM Sans, Nunito e Work Sans; pesi 400, 500, 600 e 700 per **Lato** e **Poppins**, che
    non hanno la variable. Accanto, un `LICENSE` per font.
  - Gli URL dei file passano da `Wonder\App\Module\Assets`, come gli altri asset.

### 9. Gestionale

- **Icone dei metodi**: colonna `icons` (TEXT, chiavi separate da virgola) su
  `gst_payment_methods`. Le 14 chiavi ammesse: `visa`, `master`, `maestro`,
  `american_express`, `paypal`, `google_pay`, `apple_pay`, `satispay`, `klarna`, `scalapay`,
  `bancomat`, `postepay`, `genericbank`, `cash`. `PaymentMethod::defaultIcons(string
  $provider): array`:
  - `stripe` → `visa`, `master`, `maestro`, `american_express`, `google_pay`, `apple_pay`;
  - `paypal` → `paypal`;
  - `nexi` → `visa`, `master`, `maestro`;
  - `bank_transfer` → `genericbank`; `cash` → `cash`; altro → nessuna.

  Nella scheda del metodo, il campo a scelta multipla «Icone» parte da `defaultIcons` per i
  metodi nuovi. La migrazione compila i metodi esistenti con `icons` vuoto.
  I file SVG stanno in `ecommerce/resources/assets/payment-icons/`, con il `LICENSE` MIT di
  activemerchant.
- **Font**: quattro colonne su `MerchantSetting` (`font_auth`, `font_account`,
  `font_checkout`, `font_cart`), default `''` tranne `font_checkout` = `inter`. Nella pagina
  Impostazioni, gruppo «Negozio online» (visibile con la vendita online), quattro menu a
  tendina «Font accesso», «Font account», «Font checkout», «Font carrello» con «Come il sito»
  (valore vuoto) e i nomi di `WebFonts::all()`.
- **Pagamenti**: `PaymentProviders` (§7) e il filtro dei metodi nell'anteprima.

### 10. Dove si applica il font

Un helper del modulo ecommerce, `StoreFont::style(string $area): string` con `$area` fra
`auth`, `account`, `checkout`, `cart`, legge la colonna `font_<area>` e restituisce
`<style>` con `WebFonts::css()`, oppure `''`. Lo stampano:

- il layout auth e il layout `ecommerce.account` (aree `auth` e `account`);
- `ecommerce.checkout` (area `checkout`);
- la pagina del carrello (area `cart`), non tutto `ecommerce.shop`.

### 11. Errori e casi limite

- **Carrello vuoto** su `/checkout/` o su `place` → `/cart/`.
- **Indirizzo cambiato** che rende il metodo di spedizione non disponibile: `checkout.js`
  sceglie il primo disponibile e mostra l'avviso sotto la lista; `place` rifiuta un metodo
  non proposto.
- **Coupon tolto dal ricalcolo**: l'avviso `coupon.dropped` nel riepilogo, come oggi.
- **Senza JavaScript**: tutti i riquadri aperti, la conferma funziona; il server ignora i
  campi non pertinenti (dati azienda di un privato, fatturazione con «Uguale»).
- **Doppio invio**: il bottone si disattiva all'invio; `place` rifiuta un carrello che non è
  più `stage = cart`.
- **Font sconosciuto** salvato in una colonna (per esempio tolto dal catalogo): vale come
  «Come il sito».
- **Icona sconosciuta** in `icons`: si salta.

### 12. Prove

- **App**: `WebFonts::all()` ha 9 font e i file esistono; `css('inter')` contiene
  `@font-face` e `--font-family`; `css('')` e `css('boh')` sono vuoti. `ChoiceGroup` nelle
  varianti `segmented` e `list`, `Choice::icons` con «+N», `Choice::panel` escapato, con i
  due temi.
- **Lib**: le classi nuove sono nel bundle e nella documentazione dei componenti.
- **Gestionale**: `defaultIcons` per ogni provider; la migrazione compila `icons`; le
  quattro colonne del font con Inter per il checkout; `PaymentProviders::connected`;
  l'anteprima non propone metodi con provider non collegato.
- **Ecommerce** (nello stile di `tests/CartCheckoutTest.php`):
  - le rotte `shipping` e `payment` non esistono; il carrello usa `ecommerce.shop`, il
    checkout `ecommerce.checkout`;
  - le sezioni sono nell'ordine del §3; niente `Steps` nelle viste;
  - «Subtotale» nel riepilogo e nel carrello; righe senza SKU;
  - Check-out rapido assente con `buttons()` vuoto;
  - «Uguale» scelto per default; privato con solo `billing_cf`, azienda senza `billing_cf`;
    il server svuota i campi dell'altro tipo;
  - `place` completo con spedizione e bonifico, e con ritiro;
  - `StoreFont::style` per le quattro aree;
  - la prova sui float delle viste;
  - testi in it/en; nessun `render('wonder')` forzato.
- **Browser** su `ecommerce.test` con l'account di prova (l'accesso lo fa l'utente nel
  pannello), a larghezza desktop e telefono: spedizione con coupon e bonifico, ritiro,
  riepilogo che resta in vista, errori sotto i campi, font del checkout.

## I tre piani

1. **Componenti e font** (`lib`, `app`, sul branch `checkout-a-passi-componenti`): varianti
   di `ChoiceGroup`, `Choice::icon`, `icons`, `panel`; `WebFonts` con i 9 font.
2. **Pagina unica** (`gestionale`, `ecommerce`, sul branch `e1c-checkout-a-passi`): prima
   un commit con le modifiche locali dell'utente in ecommerce (dopo sua conferma), poi icone e
   font nel gestionale, `PaymentProviders`, `ExpressCheckout`, `CheckoutRules`, la vista unica,
   il carrello su shop, `checkout.js`, `StoreFont`, documentazione, `CHANGELOG` e `TODO`.
3. **Ospite**: come il piano 3 della spec a passi (§4 lì), più il link «Accedi» nei Contatti.

## Fuori da questo lavoro

- Il collegamento reale di Stripe, PayPal e Nexi e quindi i bottoni del Check-out rapido (D6).
- La newsletter nei Contatti: il modulo non la gestisce.
- Il codice fiscale dell'azienda diverso dalla partita IVA (ditte individuali): non serve
  senza fatturazione elettronica (D61).
- La scelta fra più indirizzi salvati e il carrello abbandonato.
- La tassa come riga a sé: i prezzi restano IVA inclusa.
