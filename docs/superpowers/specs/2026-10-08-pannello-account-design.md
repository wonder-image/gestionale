# Pannello account del cliente — design

Data: 2026-10-08 · Branch: `pannello-account` (app, lib, ecommerce, gestionale)

## 1. Obiettivo

Il pannello account del cliente prende stile e UX del sito di riferimento
(elenajossifov.com, cartella `account/`) e diventa **del core**: qualsiasi sito
con clienti frontend lo ha, anche senza ecommerce; i moduli aggiungono solo le
loro sezioni. Il verde degli screen è il colore primary configurato nel backend:
nessun colore fisso.

Criteri di riuscita:

- menu Panoramica · Ordini · Coupon · Dati personali · Indirizzi · Fatturazione ·
  Esci, con Ordini e Coupon presenti solo se un modulo li fornisce;
- «Dati personali» raccoglie tutti i dati del cliente, compresi password e
  metodi di pagamento;
- **ogni bottone dei form ha la classe `wi-input-submit`**;
- URL del pannello in italiano;
- un sito senza moduli ha Panoramica, Dati personali, Indirizzi, Fatturazione ed
  Esci funzionanti.

## 2. Decisioni

| Tema | Decisione |
|------|-----------|
| Dove vive il pannello | Core (`packages/app`): route, controller, pagine, testi. Supera §2.3 dell'architettura («area cliente dell'ecommerce»): ora il pannello è del core e ordini, coupon, resi e abbonamenti sono sezioni dei moduli. |
| Estensione | `AccountExtension` registrate da qualsiasi modulo; più moduli convivono. |
| Stili | Componenti della lib al posto degli stili inline di oggi. |
| Cambio email | Password attuale + link di conferma alla nuova email (`OneTimeToken`, 24 ore). L'email cambia solo al clic. |
| Username | Non mostrato: il login frontend usa solo l'email. |
| Data di nascita | Nuova colonna facoltativa `birth_date` su `Contact` del core. |
| Password | Modal con «La tua password» e «Nuova password» con l'occhio, come lo screen: niente conferma della nuova password. Per gli account federati senza password resta «Imposta password» con il solo campo nuovo. |
| Metodi di pagamento | Riga in «Dati personali» aggiunta dall'ecommerce; fuori dal menu. Spenta finché C5 non collega Stripe. |
| Eliminazione indirizzo | Eliminazione vera della riga: nessuna tabella punta a `contact_addresses`, gli ordini hanno la propria copia. |
| URL | Italiani sotto `/account/`. Le URL di accesso e registrazione (`/account/auth/…`) restano come sono. Nessun rimando dalle vecchie URL inglesi: il modulo non è ancora in produzione. |

## 3. Architettura

### 3.1 Core (`packages/app`)

- **`Wonder\Auth\Frontend\AccountRoutes`** — come `AuthRoutes`, a richiesta:
  - `register(AccountPanel $panel)` registra il gruppo `/account/`, area
    frontend, nomi `account.*`, accesso solo con login e permessi
    `$panel->authorities()` (default `['client']`). Chiamate ripetute con la
    stessa classe sono innocue; una classe diversa lancia `LogicException`.
  - `extend(AccountExtension $extension)` aggiunge un'estensione; le sue route
    nascono nello stesso gruppo, con le stesse protezioni, sia che arrivi prima
    sia dopo `register`.
  - Handler unico `app/http/frontend/account.php` per le pagine del core.
- **`AccountController`** del core: le azioni di §4, con CSRF (`AuthSession`),
  SEO `NOINDEX,NOFOLLOW`, scheda collegata tramite `ContactAccount::link` e
  redirect con avviso dopo ogni salvataggio. Riprende la logica oggi in
  `ecommerce/src/Frontend/Account/AccountController.php`.
- **`AccountPanel`** (sovrascrivibile dal sito come `auth.profile`): layout,
  titolo, `authorities()`, `sections()` con le sezioni del core accese
  (`overview`, `personal`, `addresses`, `billing`; tutte accese di default) e
  gli hook che già esistono (`personalFields`, `validatePersonal`,
  `personalUserValues`, `afterPersonalSaved`, `overviewRows`). Gli hook
  chiamano a cascata quelli delle estensioni registrate.
- **`AccountExtension`** (interfaccia con classe base vuota):
  - `routes(): void` — route del modulo nel gruppo del pannello;
  - `navigation(array $items, object $user): array` — voci aggiunte, tolte o
    riordinate (ognuna con `key`, `label`, `icon`, `href`, `active`);
  - `personalRows(array $rows, object $user): array` — righe in più in «Dati
    personali» (stessa forma delle righe del core);
  - `head(): string` — markup da aggiungere alle pagine del pannello (per
    esempio i fogli di stile del negozio);
  - gli stessi hook su campi e salvataggio dei dati personali di `AccountPanel`.
- **Viste del core** (sigillate): `layout/frontend/account/panel.php` e i
  componenti `frontend/account/*` rifatti (§5), più le pagine
  `frontend/account/{index,personal,addresses,address-form,billing,message}.php`.
- **`AccountEmail`** — cambio email (§4.3).
- **`Contact`**: colonna `birth_date` (date, null) e campo nel data schema.
- **Testi** in `resources/lang/*/account.json` (it completo; en, de, es, fr con
  le stesse chiavi).

### 3.2 Lib (`packages/lib`)

Componenti CSS in `src/build/frontend/css/components/`, con i token del tema
(`--primary-color`, `--primary-color-10`, `--button-border-radius`,
`--spacer`):

- `side-nav.css` — menu laterale largo 220px; voce attiva con fondo
  `--primary-color-10` e barretta verticale primary di 3px a sinistra; icona
  primary; sotto i 768px diventa una riga orizzontale scorrevole senza
  barretta, e un piccolo script porta in vista la voce attiva;
- `row-table.css` — tabella a righe (intestazioni nascoste su mobile), colonne
  titolo + sottotitolo, piede con «Risultati da X a Y di Z» e paginazione a
  quadratini (attiva nera, spenta grigia);
- `address-card.css` — scheda con bordo nero di 2px e proporzione 3:2 (anche su
  mobile), contenuto in alto e bottoni in basso a destra; variante «aggiungi»
  con icona centrata.

### 3.3 Ecommerce (`packages/ecommerce`)

- Toglie route, controller e viste dell'account
  (`Frontend/Account/AccountController.php`, `view/pages/account/*`,
  `view/components/account/*`, `view/layout/frontend/ecommerce.account.php`).
- In `config/routes/route.frontend.php`, dopo l'auth: `AccountRoutes::register`
  con la classe in `account.panel` (default `AccountPanel` del core) e
  `AccountRoutes::extend(new EcommerceAccountExtension())`.
- `EcommerceAccountExtension` (al posto di `EcommerceAccountPanel`): voci Ordini
  e Coupon dopo Panoramica, riga Metodi di pagamento, `head()` con
  `StoreFont::style('account')` e `StoreStyle::sheet()`, route di §4.5–4.7.
  La configurazione `account.navigation` resta per spegnere o modificare le
  voci.
- Rimandi aggiornati: `ecommerce.account.*` → `account.*` (oggi solo la pagina
  «ordine completato»).

## 4. Pagine e flussi

Ogni pagina: titolo (`subtitle`) e divisore sottile sotto. Tutti i form
salvano con POST + CSRF e tornano alla pagina con un avviso; con errori la
pagina si riapre con il modal aperto, gli errori al suo interno e i valori
inseriti.

### 4.1 URL

| Nome | Metodo | URL | Di chi |
|------|--------|-----|--------|
| `account.index` | GET | `/account/` | core |
| `account.personal` | GET, POST | `/account/dati-personali/` | core |
| `account.email.confirm` | GET | `/account/email/conferma/?token=…` | core |
| `account.addresses` | GET | `/account/indirizzi/` | core |
| `account.addresses.create` | GET, POST | `/account/indirizzi/nuovo/` | core |
| `account.addresses.edit` | GET, POST | `/account/indirizzi/{id}/` | core |
| `account.addresses.delete` | POST | `/account/indirizzi/{id}/elimina/` | core |
| `account.billing` | GET, POST | `/account/fatturazione/` | core |
| `account.payment-methods` | GET | `/account/metodi-di-pagamento/` | ecommerce |
| `account.orders` | GET | `/account/ordini/` (`?pagina=N`) | ecommerce |
| `account.orders.show` | GET | `/account/ordini/{code}/` | ecommerce |
| `account.coupons` | GET | `/account/coupon/` (`?pagina=N`) | ecommerce |

«Esci» è una voce del menu che invia il POST con CSRF alla route di logout
dell'auth (`account.auth.logout`); non è un link GET.

### 4.2 Panoramica

«Il tuo account», divisore, «Ciao **Nome**, questo è il tuo account: qui
modifichi i tuoi dati e guardi i tuoi ordini.» e «Codice cliente: **code**» (il
`code` della scheda). Le righe di `overviewRows` restano possibili per le
estensioni e di default sono vuote.

### 4.3 Dati personali

Righe separate da divisori, ognuna con «Modifica» (bottone outline nero, icona
matita) che apre il proprio modal (`Modal` del core, `opensModal()`):

1. **Nome · Data di nascita · Cellulare** (tre colonne, una su mobile) → modal
   «Modifica dati»: Nome*, Cognome*, Data di nascita, Prefisso, Cellulare. Nome,
   cognome e cellulare sull'utente con le regole di oggi (cellulare canonico e
   unico); data di nascita sulla scheda.
2. **Email** → modal «Modifica email»: Email corrente (spenta), Nuova email*,
   Password* con l'occhio. `AccountEmail::request($user, $newEmail,
   $password)`:
   - password giusta; email valida, diversa dall'attuale e non usata da un
     altro utente;
   - `OneTimeToken('email_change', 86400)->issue($userId, metadata:
     ['email' => …])` (revoca le richieste precedenti);
   - email alla nuova casella con il link `account.email.confirm`; avviso «Ti
     abbiamo mandato un link a …».

   `AccountEmail::confirm($token)` (anche senza login, il token basta):
   consuma il token, ricontrolla l'unicità, aggiorna `user.email`,
   `email_verified=1`, `email_verified_at` e l'email della scheda collegata;
   pagina di esito (riuscito, scaduto o già usato, email nel frattempo presa).
3. **La tua password** (`**********`) → modal «Modifica password»: La tua
   password*, Nuova password*, entrambe con l'occhio; `AccountPassword`
   senza il campo di conferma.
4. **Righe delle estensioni** — l'ecommerce aggiunge **Metodi di pagamento**:
   «Gestisci» porta a `account.payment-methods` quando
   `account.payment_methods.enabled`; altrimenti il bottone è spento con
   «presto disponibile».

### 4.4 Indirizzi e Fatturazione

- **Indirizzi**: griglia di schede (3 colonne, 2 su tablet, 1 su mobile). Ogni
  scheda: nome e cognome grandi, telefono come link `tel:` sottolineato,
  «via numero, cap» e «città (provincia)»; in basso a destra il cestino (bottone
  nero) e «Modifica». In fondo la scheda «Aggiungi indirizzo». Senza indirizzi:
  icona, «Non hai indirizzi salvati» e bottone «Aggiungi un indirizzo».
- Modal «Nuovo indirizzo» / «Modifica indirizzo» con i campi a coppie dello
  screen (Nome*/Cognome*, Prefisso*/Cellulare, Paese*/Provincia*, Cap*/Città*,
  Via/Viale/Piazza*/Numero*, Altre indicazioni) tramite `AccountAddressForm`.
  Le pagine `nuovo/` e `{id}/` restano come ripiego senza JS.
- Elimina: modal di conferma con l'indirizzo per esteso, POST con CSRF; si
  elimina solo un indirizzo della scheda del cliente, altrimenti 404.
- **Fatturazione**: righe come «Dati personali» (dati fiscali e indirizzo) con
  un modal di modifica; validazione di oggi (`AccountAddressValidation`).

### 4.5 Ordini (ecommerce)

Ordini con `stage = order` e `customer_id` = scheda del cliente, dal più
recente, 10 per pagina. Righe: «N° `order_number`» con data e ora
(`ordered_at`), totale grande, «Visualizza ›» outline. Senza ordini: icona e
«Non hai ancora effettuato ordini».

### 4.6 Dettaglio ordine (ecommerce)

Per `code` (non l'id, che si potrebbe scorrere); un ordine che non è del cliente
dà 404. «Ordine N°…» e «del gg/mm/aaaa hh:mm», poi:

- **Informazioni**: Stato, Consegna (metodo di spedizione o ritiro), Pagamento
  (nome del metodo e loghi), Stato pagamento; se una spedizione ha tracking,
  anche N° spedizione e corriere con link. Etichette pensate per il cliente nei
  `lang` dell'ecommerce.
- **Lista prodotti**: immagine, nome con varianti e personalizzazioni,
  quantità, prezzo della riga.
- **Riepilogo**: Subtotale, sconti e coupon (`coupon_code`), Spedizione,
  commissioni se presenti, **Totale** grande.
- **Indirizzo di consegna** (o di ritiro) e **Indirizzo di fatturazione**
  affiancati (uno sotto l'altro su mobile), dalle copie salvate sull'ordine.

### 4.7 Coupon (ecommerce)

Coupon in `gst_coupon_customers` per la scheda del cliente, attivi e non
scaduti. Colonne Codice | Valore (10%, 5 €, Spedizione gratuita, Credito) | Usi
(utilizzi del cliente non annullati / `usage_limit_per_customer`, oppure
«illimitato»). Senza coupon: icona e «Non hai coupon attivi».

## 5. Stile

- Struttura: `<main id="account-page">` con il pannello a due colonne (menu
  220px + contenuto) sopra i 768px, menu orizzontale sopra il contenuto sotto.
- Righe: etichetta in grassetto e valore sotto; «Modifica» a destra.
- Modal: titolo `subtitle` con la X; campi con label flottante del tema; in
  fondo **Salva** nero a tutta larghezza, spento finché mancano i campi
  obbligatori (check del core).
- **Ogni bottone di invio dei form del pannello ha `wi-input-submit`**: Salva
  dei modal, ripieghi senza JS, conferma di eliminazione, Esci.
- Bottoni secondari: outline nero; nessun colore fisso oltre a nero/bianco e
  primary del tema.

## 6. Errori e sicurezza

- CSRF su ogni POST; accesso solo con login e permessi del pannello.
- Proprietà controllata lato server per indirizzi, ordini e coupon (404 se non
  è del cliente).
- Cambio email: password obbligatoria, token monouso a scadenza, unicità
  ricontrollata alla conferma; la vecchia email resta valida fino al clic.
- La scheda collegata non trovata o in conflitto mostra l'errore di oggi
  (`account.errors.contact`).

## 7. Migrazione

- Testi `account.*` già nel core: si aggiungono le chiavi nuove; quelle
  dell'ecommerce che restano sono solo di ordini, coupon e metodi di pagamento.
- Lo schema di `contacts` prende `birth_date` con l'aggiornamento normale del
  framework.
- `docs/superpowers/specs/2026-09-11-gestionale-ecommerce-architettura-design.md`
  §2.3 aggiornata.
- TODO ecommerce: C0 superato da questo lavoro; di C3 si chiude il cambio
  password (i consensi restano); C4 chiuso dal piano 2 (senza i resi); C0d
  chiuso dalla prova nel browser.

## 8. Prove

- **Core**: route registrate e protette; sezioni spente; estensioni prima e
  dopo `register`; dati personali con data di nascita; cambio email (token
  giusto, scaduto, riusato, email presa nel frattempo, password sbagliata);
  indirizzi (aggiunta, modifica, eliminazione propria e altrui, CSRF), tutto
  senza moduli caricati.
- **Ecommerce**: elenco e dettaglio ordini solo propri (404 sugli altri),
  paginazione, coupon riservati e usi, riga Metodi di pagamento accesa e spenta.
- **Markup**: ogni `type="submit"` del pannello ha `wi-input-submit`.
- **Browser** su `ecommerce.test` a 1280, 768 e 390 px: menu e voce attiva,
  modal con Salva spento/acceso, schede indirizzi, ordini e coupon.

## 9. Piani

1. **Pannello del core e componenti della lib**: `AccountRoutes`,
   `AccountController`, `AccountExtension`, `AccountEmail`, `birth_date`, viste
   e CSS; l'ecommerce passa al pannello del core con l'estensione (Metodi di
   pagamento compresa), stesse funzioni di oggi e nuovo stile.
2. **Ordini e Coupon** come sezioni dell'ecommerce.

## 10. Fuori ambito

Resi e abbonamenti nell'area cliente; collegamento a Stripe dei metodi di
pagamento (C5); consensi (resto di C3); data di nascita nelle schede del
backend del gestionale; URL in italiano per accesso e registrazione.
