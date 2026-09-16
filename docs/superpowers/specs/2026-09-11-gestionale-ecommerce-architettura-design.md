# Architettura `wonder-image/gestionale` + `wonder-image/ecommerce`

- **Stato:** bozza — brainstorming in corso (sezioni 1–10 approvate; da trasformare in spec definitiva)
- **Avvio:** 2026-09-11
- **Avanzamento:** [TODO.md](../../../TODO.md)

> Documento vivo. Raccoglie le decisioni approvate durante il brainstorming e
> diventa la spec di architettura definitiva quando tutte le sezioni sono
> approvate. **Ciò che non compare in "Decisioni" non è deciso.**

## Obiettivo

Due moduli per `wonder-image/app` che permettano di avviare e gestire gestionali
ed ecommerce dei commercianti con il minimo lavoro per progetto: predisposti al
massimo, con le funzionalità sbloccabili dal pannello e la massima
personalizzazione per sito.

## Vocabolario

| Termine | Significato |
|---|---|
| Commerciante | Il cliente di Wonder Image, proprietario del sito. I dati aziendali sono già nel core (`Society`, `SocietyLegalAddress`). |
| Anagrafica | Scheda unica con ruolo cliente e/o fornitore: un fornitore può essere anche cliente e viceversa. |
| Cliente | Chi acquista e/o si iscrive presso il commerciante, con o senza account sul sito. |
| Fornitore | Chi vende i prodotti al commerciante. |
| Brand | Marchio del prodotto, indipendente dal fornitore. |
| Sede | Luogo del commerciante con magazzino proprio e/o punto di ritiro o vendita. |
| `admin` | Ruolo backend di Wonder Image. |
| `administrator` | Ruolo backend del commerciante. |

## Decisioni

### D1 — Due pacchetti

`wonder-image/gestionale` funziona da solo; `wonder-image/ecommerce` lo richiede
(`dependencies.modules: ["gestionale"]`). Gli usi senza vendita (noleggio,
produzione, inventario interno) installano il gestionale e lasciano bloccate le
funzionalità di vendita.

*Scartato:* tre pacchetti (magazzino → gestionale → ecommerce). Passare da solo
magazzino a vendite richiederebbe `composer require` + deploy, e le modifiche
trasversali (es. multiprodotto) rilasci coordinati su tre pacchetti.

### D2 — Perimetro del gestionale

Tutto ciò che fa cco-beverage, più le sedi con giacenze per sede:

- catalogo;
- magazzino multi-sede;
- anagrafiche clienti e fornitori;
- listini personalizzati per cliente;
- preventivi da cui si genera la vendita;
- ordini e resi;
- fatturazione;
- spedizionieri;
- vendita al banco (D7).

### D3 — Accessi e sblocco delle funzionalità

1. **Sblocco:** solo `admin`. Le funzionalità bloccate sono nascoste al commerciante.
2. **Configurazione tecnica:** solo `admin` — metodi di pagamento, spedizioni e
   corrieri, sedi e magazzini, credenziali delle integrazioni (D14), dati
   fiscali e numerazione dei documenti (D13).
3. **Operatività:** `administrator` — catalogo, giacenze, anagrafiche,
   preventivi, ordini, campagne di sconto.

### D4 — Lingue e valute

- **Contenuti del catalogo in una sola lingua**, per ora: nome, descrizione,
  attributi, slug e personalizzazioni non si traducono. Se servirà, le traduzioni
  si aggiungeranno con tabelle `*_translations`, senza cambiare le tabelle
  principali.
- **Interfaccia traducibile** con i file `lang/`, come nel resto del framework.
- **Prezzi solo in EUR**; gli importi salvati riportano comunque la valuta.

*Rivisto il 2026-09-11:* la prima versione prevedeva contenuti multilingua.

### D5 — Nessuna migrazione

Solo nuovi progetti. Il modello dati non ha vincoli di compatibilità con i
progetti analizzati.

### D6 — Frontend

L'ecommerce fornisce pagine complete e componenti (catalogo, scheda prodotto,
carrello, checkout, area cliente con ordini, indirizzi e abbonamenti) costruiti
su `wonder-image/lib`, sovrascrivibili dal sito con `php forge publish:module` e
override in `custom/modules/ecommerce/view/`, come in immobili. API JSON per le
parti dinamiche.

Login, registrazione e area cliente sono dell'ecommerce: il core ha pagine
account solo per il backend e `new-site` non ne fornisce.

### D7 — Canali di vendita

| Canale | Dove | Note |
|---|---|---|
| Online B2C | ecommerce | |
| Vendita da ufficio | gestionale | preventivi e ordini |
| Portale B2B online | ecommerce | clienti aziendali con il proprio listino e le proprie condizioni, sui dati del gestionale |
| Banco in sede | gestionale | scarica la giacenza della sede. Documento commerciale e corrispettivi telematici richiedono registratore telematico o servizio abilitato: sotto-progetto dedicato, preceduto da una ricerca sulle integrazioni; fase successiva (D43) |
| Marketplace e feed | — | non ora |

### D8 — Ordine dei lavori

Nessun commerciante prioritario: si costruisce in ordine di dipendenza tecnica e
si rilascia quando il nucleo è completo.

### D9 — Approccio "tabelle condivise"

Il gestionale possiede le tabelle, i controlli delle funzionalità stanno dove
servono e l'ecommerce usa direttamente Model e classi `Support` del gestionale,
senza eventi tra i pacchetti.

*Scartati:*

- **B — domini con servizi, eventi e catalogo delle funzionalità** con
  controllo centralizzato: più struttura iniziale.
- **C — un pacchetto Composer per funzionalità:** contraddice lo sblocco dal
  pannello e moltiplica i pacchetti da versionare.

### D10 — Proprietà di dati e backend (sezione 1)

**Gestionale = dati + backend.**

- Tutte le tabelle di entrambi i pacchetti, comprese quelle che servono solo
  online: carrello, account clienti, pagamenti online, campagne di sconto,
  personalizzazioni.
- Tutte le pagine di backend, comprese quelle della vendita online: ordini
  online, carrelli abbandonati, impostazioni del negozio, portale B2B. Queste
  pagine e i campi online (es. "visibile online" sul prodotto) compaiono solo con
  l'ecommerce abilitato e la funzionalità sbloccata.

**Ecommerce = frontend + flussi online.**

- Nessuna tabella e nessuna pagina di backend.
- Pagine e componenti del negozio, area cliente, portale B2B lato cliente, API
  del frontend, flusso di checkout, gateway di pagamento online (Stripe, PayPal,
  Nexi).

**Beneficio:** un commerciante che ha il gestionale aggiunge l'ecommerce senza
migrazioni né import. Prodotti, giacenze, clienti e listini sono subito
vendibili online.

| | gestionale | ecommerce |
|---|---|---|
| Composer | `wonder-image/gestionale` | `wonder-image/ecommerce` |
| Slug | `gestionale` | `ecommerce` |
| Namespace | `Wonder\Plugin\Gestionale\` | `Wonder\Plugin\Ecommerce\` |
| Dipendenze | — | `gestionale` |

Struttura interna (come immobili: radice trasversale, sottocartella = area):

```
gestionale/src/Models/{Catalog,Inventory,Locations,Contacts,Pricing,Tax,Sales,Promotions,
                       Invoicing,Shipping,Pos,OnlineStore,System}/
gestionale/src/Resources/…   stesse aree
gestionale/src/Support/      logica condivisa (prezzi e IVA, importi, codici documento)
gestionale/src/Providers/    integrazioni: adattatori sulle classi di wonder-image/app (D14)

ecommerce/src/{Storefront,Account,Cart,Checkout,B2B}/   flussi online
ecommerce/src/Providers/     gateway di pagamento online: adattatori sulle classi di wonder-image/app (D14)
ecommerce/http/  view/  lang/  config/
```

Aree con nomi inglesi secondo D22.

### D11 — L'ecommerce è un modulo, non un boilerplate

La logica resta nel pacchetto e si aggiorna con `composer update`:

- checkout;
- pagamenti e webhook dei gateway;
- prenotazione delle giacenze;
- applicazione di sconti e listini;
- creazione dell'ordine;
- area cliente.

La libertà grafica passa dal publish delle view: il publish fa da boilerplate
solo per la presentazione.

Regole:

1. **Niente logica nelle view:** ricevono dati pronti (prezzo finale,
   disponibilità, errori).
2. **Contratto dei dati documentato per ogni view**, con i cambi segnalati nel
   changelog.
3. **Hook di estensione** (pattern rsvp) per campi extra del checkout, SEO,
   azioni dopo l'ordine.

### D12 — Starter

Dopo i moduli: uno starter che parte da `wonder-image/new-site` con gestionale
ed ecommerce abilitati e view pubblicate. Solo configurazione, nessuna logica.

### D13 — Funzionalità e accessi (sezione 2)

**Catalogo nel codice.** Ogni pacchetto elenca le proprie funzionalità in
`config/features.php`:

- chiave, nome e descrizione;
- dipendenze da altre funzionalità;
- modulo richiesto, se serve (es. `ecommerce`);
- comportamento dei dati quando la funzionalità viene bloccata.

**Stato su database.** Una riga per funzionalità: sbloccata sì/no, chi l'ha
modificata e quando, più uno storico dei cambi. Bloccare non cancella mai i dati:
risbloccando si ritrova tutto.

**Stato effettivo.** Una funzionalità è attiva solo se:

- è sbloccata;
- tutte le sue dipendenze sono attive;
- il modulo richiesto è abilitato.

Si legge sempre da un unico punto, `Gestionale::feature('<chiave>')`, calcolato
una volta per richiesta e usato da entrambi i pacchetti.

**Una funzionalità bloccata sparisce ovunque:**

- **backend:** voce di menu, pagine, API, campi e colonne collegati nei form
  condivisi (es. i componenti nella scheda prodotto se il multiprodotto è
  bloccato);
- **frontend dell'ecommerce:** route non registrate, componenti non mostrati;
- **processi automatici:** i cron della funzionalità non fanno nulla.

Verifica tecnica: non risulta una cache delle route; il registrar legge
`pageSchema()` e `permissionSchema()` quando registra le route; il menu backend
chiama `navigationSchema()` (con `enabled()`) di ogni Resource mentre lo costruisce.

**Convenzione contro le dimenticanze:**

- ogni Resource dei moduli dichiara la propria funzionalità con
  `public static string $feature`;
- una Resource base del gestionale applica quel valore a menu, pagine e API;
- un test verifica che nessuna Resource ne sia priva, tranne quelle sempre attive.

I controlli in view, flussi e cron restano dove servono.

**Pannello Funzionalità (solo `admin`).** Pagina backend nella sezione Sistema:

- funzionalità raggruppate per area, ciascuna con interruttore, dipendenze e
  ultimo cambio;
- badge "richiede ecommerce", con interruttore disattivato se il modulo non è
  abilitato;
- lo sblocco propone di sbloccare anche le dipendenze;
- il blocco mostra quali funzionalità smetteranno di funzionare e chiede conferma;
- si usa in locale: lo stato arriva in produzione con il deploy, dove il pannello
  è in sola lettura (D54).

**Ruoli.**

| Ruolo | Cosa vede e fa |
|---|---|
| `admin` | tutto, pannello Funzionalità, configurazione tecnica |
| `administrator` | solo le funzionalità sbloccate, per il lavoro quotidiano |

Configurazione tecnica solo `admin`: metodi di pagamento, spedizioni e corrieri,
sedi e magazzini, credenziali delle integrazioni, dati fiscali e numerazione dei
documenti. Eventuali ruoli operativi (es. addetto al banco) si valutano nei
sotto-progetti.

### D14 — Credenziali tutte in `wonder-image/app`

Tutte le credenziali delle integrazioni stanno nel core: `Credentials::api()`,
tabella `security`, `.env` che sovrascrive in locale, pagina backend di `admin`.
Motivi: possono servire anche ad altri moduli, e le classi di integrazione
vivono (o vivranno) in `wonder-image/app`.

- **Già presenti:** Stripe, Fatture in Cloud (oltre a Klaviyo, Google, Apple,
  reCAPTCHA).
- **Da aggiungere al core:** PayPal e Nexi. Le classi esistono
  (`Plugin\PayPal\PayPal(clientId, clientSecret, live)`,
  `Plugin\Nexi\Nexi(apiKey, prod)`), ma le chiavi le passa il chiamante e non sono
  in `Credentials::api()`.
- **Integrazioni future** (corrieri, registratore telematico): classe e
  credenziali nel core; nei moduli solo l'adattamento al dominio.

*Scartato:* tabella delle credenziali nel gestionale. Duplicherebbe il core e
non servirebbe agli altri moduli.

**Conseguenza:** lavori preparatori in `wonder-image/app` da collocare nella
roadmap (sezione 10).

### D15 — Documentazione GitBook

`wonder-image/gestionale` ha una documentazione compatibile con GitBook, come
immobili e rsvp:

- `.gitbook.yaml` nella radice del pacchetto con `root: ./docs/`,
  `readme: README.md`, `summary: SUMMARY.md`;
- sezioni del `SUMMARY.md` in D57;
- scritta insieme a ogni sotto-progetto, non alla fine;
- spec e piani in `docs/superpowers/`, non elencati nel `SUMMARY.md` e quindi non
  pubblicati.

Questo documento si sposta in `gestionale/docs/superpowers/specs/` quando viene
creato il repository del gestionale.

**Rivisto da D32:** due guide, per sviluppatori e per commercianti.

### D16 — Mappa delle funzionalità (sezione 3)

**Sempre attive nel gestionale** (servono anche agli usi senza vendita):

- **Catalogo:** brand, categorie, tag, attributi, model → variant → product,
  immagini.
- **Magazzino:** giacenze della sede principale, movimenti con causale,
  rettifiche manuali.
- **Anagrafiche:** clienti e fornitori, indirizzi, dati di fatturazione.
- **Sistema:** pannello Funzionalità, impostazioni, configurazione tecnica.

**Sbloccabili nel gestionale:**

| Area | Funzionalità | Cosa aggiunge | Richiede |
|---|---|---|---|
| Vendite | Ordini | ordini da ufficio con stati e scarico del magazzino; base dei canali di vendita | — |
| Vendite | Preventivi | preventivi con PDF, convertibili in ordine | Ordini |
| Vendite | Resi | richiesta, approvazione, motivo e ricarico a magazzino; rimborsi come funzionalità futura (D40) | Ordini |
| Vendite | DDT | documento di trasporto per le consegne | Ordini |
| Vendite | Abbonamenti | piani, rinnovi, cambio piano; entità separata dagli ordini (D17) | — |
| Catalogo | Multiprodotto | prodotti composti da altri prodotti: vendendoli si scaricano i componenti | — |
| Catalogo | Personalizzazione | campi compilati al momento della vendita (testo, scelta, scelta del componente) con eventuale sovrapprezzo, salvati sulla riga del documento | Ordini (scelta del componente: anche Multiprodotto) |
| Catalogo | Etichette | etichette con codice a barre e prezzo in PDF, su foglio A4 o rotolo termico (D30); funzionalità futura (D59) | — |
| Magazzino | Più sedi | altre sedi con giacenze proprie e trasferimenti | — |
| Magazzino | Acquisti | costi d'acquisto per fornitore, documenti di carico, valore del magazzino | — |
| Magazzino | Lotti e scadenze | lotto e data di scadenza su carichi e scarichi (alimentare, cosmetica) | — |
| Magazzino | Avvisi di scorta minima | email ai destinatari scelti dal commerciante quando un prodotto scende sotto la propria scorta minima; riquadro in dashboard (D27) | — |
| Listini | Listini cliente | prezzi personalizzati per cliente | Ordini |
| Promozioni | Sconto massivo | campagne per categoria o tag, con data e ora, in € o %, attivabili e disattivabili | Ordini |
| Promozioni | Coupon | fisso, %, buono a scalare, spedizione gratuita, con limiti | Ordini |
| Fatturazione | Fatturazione elettronica | fatture (note di credito: funzionalità futura, D45), invio SDI, coda, stati, notifiche; fattura ciò che producono le funzionalità di vendita attive | — |
| Fatturazione | Fattura differita | fattura riepilogativa dei DDT del periodo | DDT, Fatturazione elettronica |
| Spedizioni | Spedizioni | listini per paese e peso, ritiro in sede | Ordini |
| Spedizioni | Corrieri | sincronizzazione con lo spedizioniere: etichette e tracking; funzionalità futura (D51) | Spedizioni |
| Banco | Banco | vendita in sede con documento commerciale e corrispettivi; fase successiva (D43) | Ordini |

**Dichiarate dall'ecommerce** (richiedono il modulo abilitato):

| Funzionalità | Cosa aggiunge | Richiede |
|---|---|---|
| Negozio online | vetrina, carrello, checkout, area cliente, pagamenti online; nel backend del gestionale: ordini online, carrelli abbandonati, impostazioni del negozio | Ordini |
| Portale B2B | accesso dei clienti aziendali con listino e condizioni proprie | Negozio online, Listini cliente |
| Abbonamenti online | sottoscrizione e gestione dall'area cliente, addebito ricorrente | Negozio online, Abbonamenti |

Spedizioni non è richiesta dal negozio online, così resta possibile vendere solo
servizi.

**Funzionalità future** (fuori dal primo rilascio): carte regalo (D36), rimborsi
dei resi e note di credito (D40, D45), banco con integrazione fiscale (D43),
abbonamenti con prodotti fisici (D44), accesso con Apple e Google e scontrino
digitale per i privati (D46), corrieri collegati (D51), etichette con codice a
barre (D59).

**Requisiti nuovi:** multiprodotto, personalizzazione e sconto massivo stanno nel
gestionale e funzionano su tutti i canali (online, ufficio, banco). La
combinazione dello sconto massivo con saldo, listino cliente e coupon è in D33.

**Statistiche:** ogni funzionalità porta i propri indicatori nella dashboard
(es. valore del magazzino con Acquisti, preventivi per stato con Preventivi).

**Fuori dai moduli:**

- annunci e popup: servono a qualsiasi sito, non solo agli ecommerce;
- agenti, commissioni e payout di spingy;
- giochi, analytics e impersonificazione di spingy (specifici di quel progetto).

### D17 — Abbonamenti separati dagli ordini

- Tutti gli abbonamenti stanno in un'unica tabella, separata dagli ordini: negli
  ordini ci sono gli ordini, negli abbonamenti gli abbonamenti.
- Un rinnovo non genera un ordine.
- La fattura di un abbonamento nasce dal pagamento, come in spingy: prima il
  pagamento, poi la fattura.

*Scartato:* ogni rinnovo genera un ordine.

### D18 — Gestione fiscale IVA come spingy

La gestione dell'IVA è identica a spingy, per gestire correttamente la
fatturazione elettronica. Funzionamento verificato nel codice di spingy-it:

**Aliquote.**

- Importate dai tipi IVA di Fatture in Cloud quando l'integrazione è configurata:
  nome `<valore>% - <descrizione>`, descrizione, descrizione in fattura
  (`ei_description`), valore, natura (`N` + `ei_type`), id Fatture in Cloud.
- Per ciascuna viene creata un'aliquota Stripe esclusiva (nome visualizzato
  "IVA") e ne viene salvato l'id.
- Dal backend non si creano a mano: si mostrano o nascondono.

**Regole IVA.**

- Paese (ISO) × tipo cliente (privato, azienda) × tipo prodotto → aliquota.
- Codice della regola generato come `paese tipo-cliente tipo-prodotto`.

**Risoluzione.** Tipo prodotto dal prodotto venduto; tipo e paese dal cliente;
regola con corrispondenza esatta. Senza regola si usa l'aliquota di ripiego (in
spingy la prima importata, id 1).

**Calcolo dei totali.**

- Prezzi IVA esclusa.
- Imponibile = prezzo (o saldo) meno coupon.
- Imposta = imponibile × aliquota, arrotondata a 2 decimali.
- Totale = imponibile + imposta.
- Sul documento restano fotografati aliquota, paese, percentuale, imposta e totale.

**Metodi e conti di pagamento.**

- Metodo: codice, nome, codice SDI (es. MP01 contanti, MP05 bonifico, MP08 carta,
  MP19 SEPA), id Fatture in Cloud, tipi di pagamento Stripe collegati.
- Conto: codice, nome, id Fatture in Cloud (es. Stripe).
- Entrambi sincronizzati su Fatture in Cloud, riusando per nome quelli già
  presenti; gli errori temporanei non toccano l'id salvato.

**Cliente su Fatture in Cloud.**

- Azienda: società, PEC, P.IVA con prefisso del paese, codice SDI, fattura
  elettronica attiva.
- Privato: persona con nome e cognome.
- Sempre: codice, codice fiscale, indirizzo, email, telefono, paese.
- L'id Fatture in Cloud viene salvato sul cliente.

**Documento.**

- Una fattura per pagamento, creata in modo idempotente e messa in coda "in
  attesa di invio".
- Fattura elettronica con: cliente, data del pagamento, oggetto con i codici di
  fattura e pagamento, metodo di pagamento (id Fatture in Cloud + codice SDI),
  righe con imponibile e id dell'aliquota su Fatture in Cloud, pagamento con
  importo, stato pagato e conto.
- Verifica dell'XML, poi invio allo SDI.
- Si blocca con errore se mancano pagamento, metodo o conto sincronizzati, o il
  cliente.
- Coda, stati, errori e notifiche: appendice A (spingy-it).

**Rivisto da D21:** il gestionale funziona anche senza Fatture in Cloud; le parti
che in spingy dipendono da Fatture in Cloud diventano locali.

### D19 — Prezzi e listini

- **Scheda prodotto:** il commerciante vede solo "Prezzo" e "Prezzo scontato",
  con l'indicazione IVA inclusa o esclusa.
- **IVA inclusa o esclusa per il catalogo:** impostazione fiscale scelta una sola
  volta da `admin` in configurazione.
- **Tipo fiscale del prodotto:** preimpostato; nascosto se ne esiste uno solo.
- **Listini cliente** (funzionalità sbloccabile): pagina Listini con nome,
  clienti assegnati, prezzi e scelta IVA inclusa/esclusa per ogni listino. Se la
  funzionalità è bloccata, nessun listino compare da nessuna parte.
- **Calcolo uguale per tutti:** documenti e fatture si calcolano come in D18.
  Con prezzi IVA inclusa l'imposta si scorpora: il cliente paga sempre la cifra
  esposta anche se l'aliquota cambia (es. privato tedesco) e varia solo
  l'imponibile.
- Differenze di un centesimo tra sito e Fatture in Cloud: controllo dei totali
  prima dell'invio (D49).

*Scartati:* prezzi sempre IVA esclusa come spingy; un'unica scelta per sito
anche per i listini cliente.

### D20 — Semplice per chi è piccolo, completo per chi cresce

Principio generale del design, valido per tutte le aree:

- la complessità compare solo con le funzionalità sbloccate;
- un campo con una sola scelta possibile non si mostra;
- le scelte fiscali e tecniche le fa `admin` una volta in configurazione; il
  commerciante vede solo il lavoro di tutti i giorni.

Una bottega con pochi ordini a settimana e un distributore B2B usano lo stesso
gestionale, con due pannelli molto diversi.

### D21 — Modello fiscale locale, Fatture in Cloud come provider

Rivede D18 nelle parti che in spingy dipendono da Fatture in Cloud. La struttura
resta quella di spingy, ma la fonte di verità è il gestionale.

- **Aliquote:** tabella locale precaricata all'installazione da una nuova classe
  di `wonder-image/app`, accanto a `Custom\Fattura\Valori\Natura`, con le
  aliquote italiane (22%, 10%, 5%, 4%) e le nature valide per le operazioni a 0%.
  `admin` può aggiungerne altre (es. aliquote estere per le vendite a privati UE).
- **Fatture in Cloud (opzionale):** ogni aliquota viene abbinata al tipo IVA con
  lo stesso valore e la stessa natura, oppure creata; l'id va in `external_references` (D49). Metodi e
  conti di pagamento e clienti restano locali e vengono sincronizzati come in
  spingy.
- **Aliquote Stripe:** create solo quando servono (es. abbonamenti online).
- **Regole IVA:** locali, come spingy (paese × tipo cliente × tipo prodotto).
- **Tipi di prodotto fiscali:** configurabili (es. beni 22%, alimentari 10% o 4%,
  libri, servizi); ogni prodotto ne ha uno.
- **Aliquota di ripiego:** scelta nelle impostazioni fiscali (in spingy è la riga
  con id 1).
- **Documenti a più righe:** ogni riga risolve la propria aliquota; l'imposta si
  calcola sul totale imponibile di ogni aliquota, come nei riepiloghi FatturaPA e
  in `Custom\Fattura`, non riga per riga.
- **Fattura:** con la fatturazione elettronica sbloccata è sempre generata in
  locale (numero, righe, riepiloghi IVA, pagamento) e poi affidata a un provider
  (dettagli nella sezione 6):
  - Fatture in Cloud: invio allo SDI con coda, stati e notifiche di spingy;
  - XML FatturaPA generato con `Custom\Fattura`: download per il commercialista o
    per il portale dell'Agenzia, in futuro invio tramite intermediario SDI.
- **Senza fatturazione elettronica** ordini, IVA e totali funzionano lo stesso.

*Scartato:* aliquote importate solo da Fatture in Cloud, come in spingy. Senza
Fatture in Cloud il sistema non funzionerebbe.

### D22 — Nomi in inglese

Tabelle, colonne e classi hanno nomi in inglese, in entrambi i pacchetti.

**Invariati:** pacchetto `wonder-image/gestionale`, slug `gestionale`, namespace
`Wonder\Plugin\Gestionale\` (identità del modulo, come immobili). Il prefisso
delle tabelle resta lo slug: `gestionale_`.

**Convenzioni:**

- tabelle al plurale in snake_case, classi al singolare in PascalCase
  (`gestionale_product_models` → `ProductModel`);
- chiavi esterne `<entità>_id`, con indici;
- le chiavi verso `contacts` prendono il nome del ruolo: `customer_id`,
  `supplier_id`;
- colonne tecniche del framework (`deleted`, `creation`, …) e colonne delle
  estensioni del core (es. `AddressExtension`: `cap`, `cf`, `pi`, `sdi`, `pec`)
  invariate.

**Nomi di spingy riusati:** `taxes`, `tax_rules`, `payment_methods`,
`payment_accounts`, `payments`, `invoices`, `subscriptions`, `plans`,
`plan_prices`, `coupons`. Differenze: `client` → `customer`; `product_type` →
`tax_category_id`, che punta alla tabella configurabile dei tipi fiscali.

**Glossario.**

| Termine | Inglese | Tabelle (`gestionale_…`) |
|---|---|---|
| Modello, variante, prodotto | product model, product variant, product | `product_models`, `product_variants`, `products` |
| Brand, categoria, tag | brand, category, tag | `brands`, `categories`, `tags` |
| Attributo | attribute | `attributes`, `attribute_values` |
| Multiprodotto | bundle | `bundle_components`, `bundle_groups`, `bundle_group_options` |
| Personalizzazione | customization | `customization_fields`, `customization_field_options` |
| Formato etichetta | label format | `label_formats` |
| Anagrafica, cliente, fornitore | contact, customer, supplier | `contacts`, `contact_addresses` |
| Sede | location | `locations`, `location_opening_hours`, `location_closures` |
| Documento di magazzino | stock document | `stock_documents`, `stock_document_items` |
| Fornitore del prodotto | product supplier | `product_suppliers` |
| Giacenza, movimento, prenotazione | stock, stock movement, stock reservation | `stock`, `stock_movements`, `stock_reservations` |
| Avviso di scorta minima | low stock alert | `stock_alerts` |
| Lotto | batch | `batches` |
| Listino | price list | `price_lists`, `price_list_items` |
| Sconto massivo | discount campaign | `discount_campaigns`, `discount_campaign_categories`, `discount_campaign_tags`, `discount_campaign_brands`, `discount_campaign_product_models` |
| Abbonamento, piano | subscription, plan | `subscriptions`, `subscription_plan_changes`, `plans`, `plan_features`, `plan_prices` |
| Coupon, buono a scalare | coupon, store credit | `coupons`, `coupon_categories`, `coupon_tags`, `coupon_brands`, `coupon_product_models`, `coupon_customers`, `coupon_redemptions` |
| Carrello, preventivo, ordine | cart, quote, order | `orders` (fasi `cart`, `quote`, `order`), `order_items`, `order_tax_summaries`, `order_quote_revisions` |
| Numerazione dei documenti | document sequence | `document_sequences` |
| Riferimento esterno | external reference | `external_references` |
| Evento del provider | provider event | `provider_events` |
| Segnalazione di errore | error report | `error_reports` |
| DDT, reso | delivery note, sales return | `delivery_notes`, `delivery_note_items`, `sales_returns`, `sales_return_items` |
| Pagamento, fattura | payment, invoice | `payments`, `invoices`, `invoice_items`, `invoice_tax_summaries`, `invoice_delivery_notes` |
| Metodo, conto e condizione di pagamento | payment method, payment account, payment term | `payment_methods`, `payment_accounts`, `payment_terms`, `payment_term_installments` |
| Aliquota, regola IVA, tipo fiscale | tax, tax rule, tax category | `taxes`, `tax_rules`, `tax_categories` |
| Spedizione, corriere | shipment, carrier | `shipments`, `shipment_items`, `carriers`, `shipping_methods`, `shipping_zones`, `shipping_zone_areas`, `shipping_rates`, `shipping_rate_brackets` |
| Banco | point of sale | prefisso `pos_`, `fiscal_receipts` (bozza in D43) |
| Funzionalità | feature | `features`, `feature_logs` |
| Log degli stati | status log | `<entità>_status_logs` (D26) |

Le tabelle delle parti non ancora approvate sono indicative: si definiscono in
quelle parti.

**Aree.**

- gestionale: `Catalog`, `Inventory`, `Locations`, `Contacts`, `Pricing`, `Tax`,
  `Sales`, `Promotions`, `Invoicing`, `Shipping`, `Pos`, `OnlineStore`, `System`;
- ecommerce: `Storefront`, `Account`, `Cart`, `Checkout`, `B2B`.

**Chiavi delle funzionalità (D16).**

| Funzionalità | Chiave |
|---|---|
| Ordini, Preventivi, Resi, DDT, Abbonamenti | `orders`, `quotes`, `returns`, `delivery_notes`, `subscriptions` |
| Multiprodotto, Personalizzazione, Etichette | `bundles`, `customizations`, `barcode_labels` |
| Più sedi, Acquisti, Lotti e scadenze, Avvisi di scorta minima | `multi_location`, `purchasing`, `batch_tracking`, `low_stock_alerts` |
| Listini cliente, Sconto massivo, Coupon | `customer_price_lists`, `discount_campaigns`, `coupons` |
| Fatturazione elettronica, Fattura differita | `e_invoicing`, `deferred_invoicing` |
| Spedizioni, Corrieri, Banco | `shipping`, `carriers`, `pos` |
| Negozio online, Portale B2B, Abbonamenti online | `online_store`, `b2b_portal`, `online_subscriptions` |

**Nomi obbligati:**

- `ProductModel` e non `Model`, per non confonderlo con la classe base
  `Wonder\App\Model`;
- `SalesReturn` e non `Return`, parola riservata di PHP.

### D23 — Catalogo, multiprodotto e personalizzazione (parte 4.1)

Relazioni in tabelle ponte con chiavi esterne e indici, mai in array JSON.
Contenuti in una sola lingua (D4).

| Tabella | Colonne principali |
|---|---|
| `brands` | name, slug, logo, description, position, visible |
| `categories` | parent_id, name, slug, image, description, position, visible |
| `tags` | name, slug, image, visible |
| `product_models` | brand_id, type (`simple`, `bundle`), tax_category_id, code, sku, unit (predefinita `pz`, D39), name, slug, short_description, description, peso e misure predefiniti, seo_title, seo_description, returnable (D40), requires_shipping (D41), visible, visible_online |
| `product_model_categories`, `product_model_tags` | tabelle ponte; per le categorie anche `is_main` e `position` |
| `product_variants` | product_model_id, name, slug, position, visible |
| `products` | product_model_id, product_variant_id, sku, ean, mpn, price, sale_price, peso e misure (se vuoti valgono quelli del modello), position, active |
| `product_images` | product_model_id, product_variant_id (vuoto = immagine del modello), file, alt, position |
| `attributes` | key, name, type, level (`model`, `variant`, `product`), unit, is_filterable, is_visible, group, position |
| `attribute_values` | attribute_id, label, color, image, position |
| `product_model_attributes`, `product_variant_attributes`, `product_attributes` | attribute_id + attribute_value_id oppure value_text / value_number |
| `bundle_components` | bundle_product_id, product_id, quantity, position |
| `bundle_groups` | bundle_product_id, name, min_choices, max_choices, position |
| `bundle_group_options` | bundle_group_id, product_id, surcharge, position |
| `customization_fields` | product_model_id, label, help_text, type (`text`, `textarea`, `number`, `select`, `date`, `file`), is_required, max_length, allowed_extensions, max_file_size, surcharge, position |
| `customization_field_options` | customization_field_id, label, surcharge, position |

- **Livelli:** il modello è la scheda; la variante è ciò che cambia l'aspetto
  (immagini, attributi di variante come il colore); il prodotto è ciò che si
  vende (SKU, prezzo, attributi di prodotto come la taglia). Un articolo senza
  varianti è un modello con una variante e un prodotto: il pannello nasconde i
  livelli superflui (D20).
- **Codici:**
  - `code` è il codice tecnico (D25); `sku` è il codice interno scelto dal
    commerciante, su due livelli come in WooCommerce e Magento: SKU padre del
    modello, facoltativo (es. `TSH-1234`), e SKU del prodotto, cioè di ciò che
    si vende e sta a magazzino (es. `TSH-1234-BLU-M`), copiato sulle righe
    d'ordine;
  - se il modello ha un solo prodotto, il pannello mostra un solo SKU, quello
    del prodotto (D20); creando un prodotto in un modello con SKU, il pannello
    propone SKU del modello più i valori di variante e prodotto, modificabile;
  - nel futuro feed Google Merchant lo SKU del modello diventa `item_group_id`;
  - `ean`: codice a barre del prodotto, inserito dal commerciante e mai generato
    in automatico; accetta solo 8 o 13 cifre numeriche; si stampa sulle
    etichette (D30) e nel futuro feed Google Merchant diventa `gtin`;
  - `mpn`: codice del produttore; il codice del fornitore sta in
    `product_suppliers.supplier_sku` (D29);
  - unicità: SKU unico nel proprio livello, EAN unico se compilato, MPN senza
    vincolo.
- **Peso e misure:** solo di spedizione; le misure descrittive sono attributi.
- **Immagini:** se una variante non ne ha, valgono quelle del modello.
- **Multiprodotto:** `type = bundle` sul modello, con il proprio
  `tax_category_id` e quindi la propria aliquota. Componenti fissi e gruppi di
  scelta (la "scelta del componente"). La disponibilità si calcola dai
  componenti e alla vendita si scaricano componenti e opzioni scelte.
- **Personalizzazione:** campi definiti sul modello, validi per prodotti e
  multiprodotti. Il tipo `file` salva in una cartella non pubblica, con
  estensioni e dimensione massima per campo. Alla vendita valori, etichette,
  file e sovrapprezzi vengono copiati sulla riga del documento.

*Scartati:* `reference` come nome del codice interno del modello; colonna `gtin`
(basta `ean`); EAN interni generati dal pannello; validatore con cifra di
controllo in `wonder-image/app`.

### D24 — Carrello, preventivi e ordini (parte 4.4, impostazione)

**Una sola tabella `orders`**, con una fase (`stage`) che avanza sulla stessa riga:

```
cart ──── checkout ─────────────────► order
  └── richiesta di preventivo ──► quote ── accettato ──► order
                                  (anche creato da backend)
```

- **Numerazione separata:** `quote_number` all'invio del preventivo,
  `order_number` alla conferma dell'ordine (online all'invio, D47); i carrelli
  non hanno numero.
- **Preventivo inviato:** ogni versione inviata viene archiviata in PDF; una
  modifica dopo l'invio aumenta la revisione.
- **Canale (`channel`):** `office`, `online`, `b2b_portal`, `pos`. Il banco crea
  direttamente un ordine confermato e pagato.
- **Carrelli abbandonati:** carrelli fermi da X giorni, senza stato dedicato; un
  cron elimina quelli anonimi vecchi.
- **Funzionalità:** la fase `quote` richiede `quotes`, la fase `cart` richiede
  `online_store`.

**Stati su tre assi**, invece dell'elenco unico dei vecchi progetti che mescolava
ordine, pagamento e consegna:

| Colonna | Valori |
|---|---|
| `status` del preventivo | `draft`, `sent`, `rejected`, `expired`; se accettato diventa ordine |
| `status` dell'ordine | `pending`, `confirmed`, `processing`, `completed`, `cancelled` |
| `payment_status` | `unpaid`, `pending`, `partially_paid`, `paid`, `partially_refunded`, `refunded` |
| `fulfillment_status` | `unfulfilled`, `ready_for_pickup`, `partially_fulfilled`, `fulfilled` |

Tracking del corriere su `shipments`; resi e fatture con stati propri.

**Righe (`order_items`):**

| Gruppo | Colonne |
|---|---|
| Riferimenti | order_id, type (D37), product_id (vuoto per le righe non di prodotto), parent_item_id, position |
| Copia del prodotto | sku, name, description, unit (D39) |
| Prezzo | quantity, list_price, unit_price, price_source, discount_type, discount_value, order_discount_amount, price_list_id, discount_campaign_id (D33) |
| IVA | tax_category_id, tax_id, tax_rate, tax_nature |
| Personalizzazione | customization (JSON: campo, etichetta, valore, file, sovrapprezzo), customization_surcharge |
| Totale | line_total |

- Componenti e opzioni scelte di un multiprodotto sono righe figlie
  (`parent_item_id`) a prezzo zero, per lo scarico del magazzino e il DDT.
- `order_tax_summaries`: imponibile e imposta per aliquota, come i riepiloghi
  FatturaPA (D21).
- Indirizzi di fatturazione e spedizione copiati sull'ordine con
  `AddressExtension` del core (prefissi `billing_` e `shipping_`).
- `prices_include_tax` sull'ordine, preso dal listino usato (D19).
- Documenti separati, collegati all'ordine: `delivery_notes`, `sales_returns`,
  `payments`, `invoices`, `shipments`.

*Scartato:* tabelle separate per carrello, preventivi e ordini, come nei vecchi
progetti.

### D25 — Codici con prefisso

Ogni entità operativa (catalogo, anagrafiche, documenti, movimenti) ha una
colonna `code` con prefisso e 7 caratteri casuali (es. `pay_k3x9d2a`). La genera
il framework con `Field::key('code')->text()->uniqueCode('<prefisso>')`: unica,
in minuscolo, creata all'inserimento e non più modificabile. Nessun lavoro nel
core: il Model `Popup` la usa già con `pop_`.

- Il codice è il riferimento tecnico: link inviati al cliente, metadati dei
  gateway, log. Non sostituisce SKU, numero d'ordine o numero di fattura.
- In `product_models` la colonna `code` di D23 è questo codice.
- **Eccezioni:** le tabelle di configurazione (`taxes`, `payment_methods`, …)
  hanno chiavi parlanti come in spingy (`bank-transfer`); nei coupon `code` è il
  codice digitato dal cliente (`SALDI20`).

| Entità | Prefisso | Entità | Prefisso |
|---|---|---|---|
| Modello | `mod_` | Ordine, carrello, preventivo | `ord_` |
| Variante | `var_` | DDT | `del_` |
| Prodotto | `pro_` | Reso | `ret_` |
| Brand | `bra_` | Pagamento | `pay_` |
| Categoria | `cat_` | Fattura, nota di credito | `inv_` |
| Tag | `tag_` | Spedizione | `shp_` |
| Anagrafica (cliente e/o fornitore) | `con_` | Abbonamento | `sub_` |
| Sede | `loc_` | Piano | `pln_` |
| Lotto | `bat_` | Listino | `prl_` |
| Movimento di magazzino | `mov_` | Campagna di sconto | `dsc_` |

Le entità delle parti ancora da definire ricevono il prefisso nella propria
parte.

*Scartato:* `cli_` per le anagrafiche: la stessa scheda può essere anche fornitore.

### D26 — Log degli stati

Ogni documento con stati ha la propria tabella di log, sempre con le stesse
colonne, come in spingy:

| Colonna | Contenuto |
|---|---|
| `<entità>_id` | documento |
| `field` | stato cambiato: negli ordini `stage`, `status`, `payment_status` o `fulfillment_status`; altrove `status` |
| `from_value`, `to_value` | valore precedente e nuovo |
| `source` | `user`, `system`, `cron`, `webhook`, `api` |
| `user_id` | autore, se `source = user` |
| `message` | nota o esito (es. `sent_to_sdi`) |
| `response` | JSON della risposta esterna (gateway, Fatture in Cloud, corriere) |

Tabelle: `order_status_logs`, `payment_status_logs`, `invoice_status_logs`,
`delivery_note_status_logs`, `sales_return_status_logs`,
`shipment_status_logs`, `subscription_status_logs`,
`stock_document_status_logs`.

Il log del magazzino è `stock_movements` (D27): ogni variazione di quantità è una
riga.

### D27 — Giacenze, prenotazioni e scorta minima (parte 4.2)

| Tabella | Colonne principali |
|---|---|
| `stock` | product_id, location_id, batch_id (vuoto senza lotti), supplier_id (vuoto senza acquisti, D29), quantity |
| `stock_reservations` | order_id, order_item_id, product_id, location_id, quantity, expires_at, released_at |
| `stock_movements` | code `mov_`, product_id, location_id, batch_id, supplier_id, type, reason (D29), quantity (con segno), quantity_before, quantity_after, unit_cost, reference_type + reference_id (ordine, DDT, reso…), source, user_id, note |
| `stock_alerts` | product_id, location_id (vuoto: soglia sul totale del prodotto), threshold, quantity_at_alert, notified_at, resolved_at |

- **Tipi di movimento:** `sale`, `sale_cancel`, `return`, `purchase`,
  `adjustment`, `transfer_in`, `transfer_out`.
- **Disponibile** = giacenza meno prenotazioni attive. Il checkout prenota; se il
  pagamento non arriva, la prenotazione scade. Evita l'overselling dei vecchi
  shop.
- **Multiprodotto:** nessuna giacenza propria; i movimenti riguardano i
  componenti (D23).

**Avvisi di scorta minima** (funzionalità sbloccabile `low_stock_alerts`):

- campo "Scorta minima" sul prodotto (`products.min_stock_quantity`); se è vuoto
  non ci sono avvisi;
- soglia sul disponibile totale del prodotto, somma di tutte le sedi;
- sotto soglia parte un'email ai destinatari scelti dal commerciante nelle
  impostazioni del gestionale;
- se una sola operazione porta sotto soglia più prodotti, arriva un'unica email
  con l'elenco;
- l'avviso non si ripete finché il prodotto non torna sopra soglia
  (`resolved_at`);
- riquadro della dashboard con i prodotti sotto soglia.

*Rimandato:* soglia per singola sede, finché non serve.

### D28 — Pagamenti e fatture (parte 4.5)

**Pagamenti (`payments`).** Ogni movimento di denaro è una riga, rimborsi
compresi: pagamento del checkout, bonifico registrato dall'ufficio, contanti al
banco, rate B2B.

- Colonne: code `pay_`, type (`payment`, `refund`), order_id, subscription_id,
  customer_id, payment_method_id, payment_account_id, amount, currency, status
  (`pending`, `paid`, `failed`, `cancelled`), provider (`stripe`, `paypal`,
  `nexi`, `manual`), provider_reference, due_date, paid_at, note.
- Il `payment_status` dell'ordine (D24) si calcola dalla somma delle righe.

**Fatture.**

| Tabella | Colonne principali |
|---|---|
| `invoices` | code `inv_`, document_type (`TD01` fattura, `TD04` nota di credito, `TD24` fattura differita), number, date, customer_id, order_id, subscription_id, payment_id, related_invoice_id (fattura corretta dalla nota di credito), dati di fatturazione copiati (`billing_`), prices_include_tax, subtotal, tax_total, total, currency, provider (`fatture_in_cloud`, `xml`), provider_document_id, document_url, xml_file, status, message, sent_at, confirmed_at, customer_notified_at |
| `invoice_items` | invoice_id, order_item_id, description, quantity, unit, unit_price, tax_id, tax_rate, tax_nature, line_total, position |
| `invoice_tax_summaries` | invoice_id, tax_id, tax_rate, tax_nature, taxable_amount, tax_amount |
| `invoice_delivery_notes` | invoice_id, delivery_note_id (fattura differita) |

- **Stati:** quelli di spingy (`pending_send`, `retry_send`, `sent`,
  `confirmed`, `rejected`, `error`), più `generated` per il provider XML.
- **Ancora da decidere:** origine della fattura (sezione 5) e numerazione
  (sezione 6).

### D29 — Sedi, documenti di magazzino, lotti e acquisti (parte 4.2)

**Sedi.**

| Tabella | Colonne principali |
|---|---|
| `locations` | code `loc_`, name, is_default (sede principale), has_stock, is_pickup_point, is_pos, indirizzo (`AddressExtension`), phone, email, position, active |
| `location_opening_hours` | location_id, day (`Mon`…`Sun`), from_time, to_time, position (più fasce per giorno; stesse colonne di `society_timetable` del core) |
| `location_closures` | location_id, starts_at, ends_at, note |

- Esiste sempre una sede principale. Con `multi_location` bloccata è l'unica e
  la scelta della sede non compare da nessuna parte (D20).
- "Punto di ritiro" compare solo con `shipping`, "Banco" solo con `pos`.
- Durante una chiusura programmata il ritiro non si può scegliere.
- Ogni sede ha i propri orari e le proprie chiusure, indipendenti da
  `society_timetable` del core.
- Sedi e orari sono configurazione di `admin` (D3), sincronizzata tra ambienti;
  le chiusure le gestisce il commerciante in produzione (D54).

**Documenti di magazzino.** Carichi, scarichi e trasferimenti in una sola
tabella, come i documenti di carico e scarico di cco:

| Tabella | Colonne principali |
|---|---|
| `stock_documents` | code `stk_`, type (`receipt`, `issue`, `transfer`), number, date, location_id, to_location_id, supplier_id, supplier_document_number, supplier_document_date, reason, status, total_cost, note, user_id |
| `stock_document_items` | stock_document_id, product_id, batch_id, supplier_id (vuoti: scelta automatica), quantity, unit_cost, line_total, position |

- **Stati:** `draft`, `completed`, `cancelled`; i trasferimenti anche
  `in_transit`. Log in `stock_document_status_logs` (D26).
- **Movimenti alla conferma:** carico `purchase`; scarico `adjustment` con la
  causale del documento; trasferimento `transfer_out` alla partenza e
  `transfer_in` all'arrivo.
- **Annullamento:** un documento confermato si annulla con movimenti di storno
  (stesso tipo, segno opposto), mai cancellando.
- **Funzionalità:** carico e scarico richiedono `purchasing`, il trasferimento
  `multi_location`.
- **Rettifica rapida** dalla scheda prodotto, sempre disponibile: movimento
  `adjustment` senza documento, con causale (`reason`: `damaged`, `gift`,
  `internal_use`, `expired`, `inventory`, `initial_stock` giacenza iniziale (D55),
  `other`).
- **Non ora:** ordini a fornitore, inventario con conteggio guidato.

**Lotti e scadenze (`batch_tracking`).**

- `batches`: code `bat_`, product_id, number, expiry_date, supplier_id, note.
- Il lotto nasce al carico.
- I lotti scaduti non sono disponibili online né per lo scarico automatico.
- Dai movimenti si risale ai clienti che hanno ricevuto un lotto (richiami).
- Riquadro della dashboard con i lotti in scadenza entro N giorni
  (impostazione).

**Acquisti (`purchasing`).**

- `product_suppliers`: product_id, supplier_id, supplier_sku, cost,
  is_preferred, position.
- Il carico aggiorna il costo del fornitore; lo storico dei costi resta nei
  movimenti (`unit_cost`).
- Riquadro della dashboard con il valore del magazzino.

**Giacenza per fornitore, come cco.**

- `supplier_id` su `stock`, `stock_movements` e righe dei documenti di
  magazzino; vuoto con `purchasing` bloccata.
- Disponibilità per fornitore; la disponibilità del prodotto è la somma.
- **Scarico automatico:** prima il lotto che scade prima (con
  `batch_tracking`), poi il fornitore con il costo più basso. Negli ordini da
  ufficio e nei documenti di magazzino lotto e fornitore si possono scegliere a
  mano; nel DDT in bozza il lotto si può correggere (D39).
- **Valore del magazzino** = quantità × costo del fornitore.

*Scartati:*

- costo medio ponderato: più semplice, ma perde la disponibilità per fornitore;
- strati di carico FIFO: valore esatto, ma rettifiche, resi e trasferimenti
  devono spezzare gli strati;
- orari e chiusure delle sedi modificabili dal commerciante.

### D30 — Etichette con codice a barre (funzionalità futura, D59)

Funzionalità sbloccabile `barcode_labels`, senza dipendenze.

- **Da dove si stampa:** elenco prodotti (scelta dei prodotti e numero di
  etichette per ciascuno), scheda prodotto, documento di carico con
  un'etichetta per pezzo ricevuto (con `purchasing`, D29).
- **Formati (`label_formats`, gestiti da `admin`):** pagina, righe e colonne,
  misure, margini e contenuto, scelto tra nome, variante, prezzo al pubblico IVA
  inclusa, SKU e codice a barre. Precaricati i formati più comuni su foglio A4 e
  su rotolo termico.
- **Codice a barre:** EAN-13 o EAN-8 dal campo `ean` del prodotto (D23); senza
  EAN, lo SKU in Code128.
- **Output:** PDF con FPDF, già nel core.
- **Codici a barre nel core:** EAN-13 ed EAN-8 si aggiungono a `createBarcode()`
  di `wonder-image/app`, che oggi disegna Code128, Code39, Code25 e Codabar
  (lavoro preparatorio).

### D31 — Anagrafiche e account (parte 4.3a)

| Tabella | Colonne principali |
|---|---|
| `contacts` | code `con_`, is_customer, is_supplier, dati di fatturazione con `AddressExtension::billing()` del core (type `private`/`business`, name, surname, business_name, cf, pi, sdi, pec, indirizzo, phone_prefix, phone), email, user_id, payment_method_id e payment_term_id (D38), color, note, custom_data (D52), active |
| `contact_addresses` | contact_id, label, indirizzo con `AddressExtension::simple()` più destinatario e telefono, is_default, position |

- **Una scheda = una sola identità fiscale**, con al massimo un account. Tipo e
  paese servono alle regole IVA (D18); partita IVA e codice fiscale sono validati
  dall'estensione del core.
- **Documenti:** ordini e fatture usano i dati copiati sul documento (D24, D28);
  modificare la scheda non cambia i documenti emessi.
- **D20:** il ruolo fornitore compare solo con `purchasing`; i campi aziendali
  solo per il tipo `business`.
- **Dashboard:** numero di clienti e fornitori.

**Account sul sito (ecommerce).**

- L'ecommerce configura il permesso `frontend.client` del core con i suoi hook:
  la registrazione crea l'utente del core e la scheda collegata
  (`contacts.user_id`); `$USER->client` restituisce la scheda.
- Se esiste già una scheda con la stessa email (creata dall'ufficio o da un
  ordine senza account), si collega solo dopo la verifica dell'email del core.
- Accesso con Google e Apple: funzionalità futura dell'ecommerce (D46), basata su
  `AuthFederated` del core, oggi non funzionante e da completare (lavoro nel core
  dopo il primo rilascio, D58).
- Newsletter e consensi con le tabelle dei consensi del core, nessuna tabella nel
  gestionale.

**Portale B2B:** l'account lo crea l'azienda cliente; si progetta in una fase
successiva, non urgente (sezione 10).

**Rimandati:** ID esterni (Fatture in Cloud, Stripe) alla sezione 6; condizioni
di pagamento predefinite in D38; listino del cliente in D33.

**Non ora:** più utenti della stessa azienda sul portale B2B, referenti
aziendali, gruppi di clienti.

### D32 — Documentazione per sviluppatori e per commercianti

Rivede D15. Ogni funzionalità ha due documentazioni.

| | Guida sviluppatori | Guida commercianti |
|---|---|---|
| Per chi | Wonder Image e chi sviluppa i siti | i commercianti; Wonder Image per spiegare e verificare |
| Dove | `docs/` di ciascun pacchetto (gestionale ed ecommerce), GitBook come immobili e rsvp | una sola guida per gestionale ed ecommerce, nel repository del gestionale (tutto il backend è lì, D10), in uno spazio GitBook separato |
| Contenuti | installazione, configurazione, tabelle, hook, override delle view e contratti dei dati (D11), provider | procedure passo passo per pagina, casi particolari (es. fattura rifiutata dallo SDI), glossario |
| Linguaggio | tecnico | senza termini tecnici, con screenshot |

- **Scrittura:** i contenuti si scrivono nel repository del modulo, insieme a
  ogni sotto-progetto. Un sotto-progetto è finito solo quando entrambe le guide
  sono aggiornate. Nella TODO va solo la preparazione.
- **Rimandi dal backend:** nuova opzione `docs()` nel `PageSchema` del core
  (lavoro preparatorio): ogni pagina backend mostra il pulsante "Guida" verso la
  pagina giusta, e la possono usare anche gli altri moduli. Messaggi di errore e
  stati vuoti possono rimandare a una sezione precisa. L'indirizzo base della
  guida sta nella configurazione del modulo.
- **Inclusa o da attivare:** ogni pagina di funzionalità della guida commercianti
  inizia con lo stesso riquadro, "Inclusa" oppure "Da attivare su richiesta", con
  i requisiti (altre funzionalità, modulo ecommerce). La tabella di tutte le
  funzionalità si genera con un comando da `config/features.php` (D13).
- Nel backend le funzionalità bloccate restano nascoste (D13): è la guida a
  mostrare cosa si può attivare.
- Struttura delle guide e dei `SUMMARY.md`: D57.

### D33 — Listini e combinazione dei prezzi (parte 4.3b)

**Listini cliente (`customer_price_lists`).**

| Tabella | Colonne principali |
|---|---|
| `price_lists` | code `prl_`, name, prices_include_tax, discount_percent (per i prodotti non elencati; vuoto = il listino non li copre), note, active |
| `price_list_items` | price_list_id, product_id, min_quantity (1 = prezzo normale, di più = scaglione), price |

- Assegnazione con `contacts.price_list_id`, dalla scheda o dalla pagina del
  listino (D19).
- Da ufficio e al banco l'ordine prende il listino del cliente, modificabile per
  il singolo documento.
- **Scaglioni:** vale la riga con la quantità minima più alta che non supera la
  quantità della riga d'ordine.
- Il prezzo scontato del prodotto non ha date: le promozioni programmate si
  fanno con lo sconto massivo (4.3c).

**Priorità fissa.** Per ogni riga vale il primo prezzo disponibile:

1. listino del cliente, se copre il prodotto (prezzo o scaglione del prodotto,
   oppure `discount_percent` sul prezzo base);
2. campagna di sconto attiva sul prodotto, calcolata sul prezzo base;
3. prezzo scontato del prodotto;
4. prezzo base.

Conseguenza accettata: durante una campagna un cliente con listino può pagare
più di un privato. Un prodotto con prezzo scontato, durante una campagna, prende
il prezzo della campagna, salvo l'opzione `exclude_sale_products` (D34).

**Calcolo della riga**, in un'unica classe del gestionale usata da ufficio,
banco, ecommerce e portale B2B:

1. prezzo secondo la priorità;
2. sconto manuale sulla riga, solo da ufficio e banco;
3. sovrapprezzi di personalizzazione e opzioni del multiprodotto, sempre a prezzo
   pieno: listino, campagna e prezzo scontato non li toccano;
4. sconti sul totale (coupon, sconto manuale sull'ordine) divisi tra le righe in
   proporzione all'importo: riepiloghi IVA per aliquota corretti (D21) e
   rimborsi dei resi calcolati riga per riga.

Sulla riga d'ordine (D24) restano `list_price`, `unit_price`, `price_source`
(`base`, `sale`, `price_list`, `campaign`) e `order_discount_amount`.

*Scartati:*

- vince il prezzo più basso: la priorità fissa è più comoda e pratica;
- sconti a cascata: margini a rischio, difficili da spiegare;
- sovrapprezzi scontati insieme al prodotto.

**Non ora:** importazione dei listini da file, listini per categoria.

### D34 — Sconto massivo (parte 4.3c)

| Tabella | Colonne principali |
|---|---|
| `discount_campaigns` | code `dsc_`, name, discount_type (`percent`, `amount`), discount_value, starts_at, ends_at (vuoto = senza fine), active, applies_to_all, exclude_sale_products, applies_online, applies_office, applies_pos, note |
| `discount_campaign_categories` | discount_campaign_id, category_id (sottocategorie comprese) |
| `discount_campaign_tags` | discount_campaign_id, tag_id |
| `discount_campaign_brands` | discount_campaign_id, brand_id |
| `discount_campaign_product_models` | discount_campaign_id, product_model_id, is_excluded |

- **Prodotti coinvolti:** tutto il catalogo, oppure i prodotti in almeno una
  delle categorie (sottocategorie comprese), dei tag, dei brand o dei modelli
  scelti; in entrambi i casi meno i modelli esclusi.
- **Sconto:** in percentuale o in euro per pezzo, sul prezzo base (D33); mai sotto
  zero, arrotondato al centesimo.
- **Validità:** tra inizio e fine e con l'interruttore acceso; senza fine resta
  attiva finché non si spegne. Nessun cron: il prezzo si calcola quando serve.
  Stato ricavato: programmata, in corso, terminata, disattivata.
- **Prodotti con prezzo scontato:** con `exclude_sale_products` mantengono il
  proprio prezzo scontato.
- **Canali:** di default tutti; limitabili (es. solo online). I clienti con
  listino non ricevono campagne (D33).
- **Sovrapposizioni:** se più campagne attive riguardano lo stesso prodotto vince
  lo sconto maggiore; il pannello avvisa della sovrapposizione al salvataggio.
- **Anteprima** prima di salvare: numero di prodotti coinvolti ed esempi di prezzo
  prima e dopo.
- **Negozio online:** la view riceve prezzo base da barrare, percentuale e fine
  della campagna (D11).

### D35 — Coupon (parte 4.3d)

| Tabella | Colonne principali |
|---|---|
| `coupons` | code, name, discount_type (`percent`, `amount`, `free_shipping`, `store_credit`), discount_value, min_order_amount, applies_to_all, exclude_discounted_products, first_order_only, usage_limit, usage_limit_per_customer, starts_at, ends_at, applies_online, applies_office, applies_pos, duration, duration_months (D44), active, note |
| `coupon_categories`, `coupon_tags`, `coupon_brands`, `coupon_product_models` | come le tabelle ponte delle campagne (D34); `coupon_product_models` con `is_excluded` |
| `coupon_customers` | coupon_id, customer_id (nessuna riga = tutti i clienti) |
| `coupon_redemptions` | coupon_id, order_id, customer_id, email, discount_amount, redeemed_at, released_at |

- **Codice:** quello digitato dal cliente, unico, senza distinzione tra maiuscole
  e minuscole (eccezione di D25).
- **Tipi:**
  - `percent`: percentuale sulle righe a cui si applica;
  - `amount`: importo fisso, la parte non usata si perde;
  - `free_shipping`: azzera la spedizione (eventuali limiti nella 4.6);
  - `store_credit` (buono a scalare gratuito): importo fisso con residuo per gli
    ordini successivi, calcolato dagli utilizzi. Fiscalmente è uno sconto.
- **Prodotti:** stesso selettore delle campagne (D34): tutto il catalogo
  (predefinito) oppure categorie, tag, brand e modelli, con modelli esclusi.
- **Un solo coupon per ordine.** Si applica dopo il prezzo delle righe (D33),
  sovrapprezzi compresi, diviso tra le righe in proporzione
  (`order_discount_amount`).
- **Prodotti già scontati:** righe con prezzo da listino, campagna o prezzo
  scontato, o con sconto manuale; `exclude_discounted_products` le salta.
- **Spesa minima** calcolata sulle righe a cui il coupon si applica.
- **Solo primo ordine** (`first_order_only`): es. coupon di benvenuto della
  newsletter.
- **Utilizzi** contati alla conferma dell'ordine; se l'ordine viene annullato,
  utilizzo e residuo tornano disponibili.
- **Limite per cliente e buono a scalare** richiedono di riconoscere il cliente:
  account, oppure email se ci sarà il checkout da ospite (sezione 5).
- **Messaggi di rifiuto** tradotti, uno per motivo, come le notifiche di spingy.
- **Sull'ordine:** coupon, codice e importo dello sconto nella testata (4.4).
- **Abbonamenti:** durata del coupon e sincronizzazione con Stripe (D44).

**Più avanti:** generazione in blocco di codici usa e getta, più coupon nello
stesso ordine.

### D36 — Carte regalo (funzionalità futura)

Rimandate a una fase successiva, fuori dal primo rilascio. Principi già decisi,
da riprendere quando si realizzano:

- **Separate dai coupon:** tabelle, pagina del backend (area `Sales`, accanto ai
  pagamenti) e campo al checkout diversi da quelli dei coupon.
- **Metodo di pagamento:** la carta regalo è una riga di `payment_methods` e ogni
  utilizzo è una riga di `payments`, con un nuovo provider `gift_card` (D28). Scala il
  totale da pagare, non l'imponibile: IVA e totale del documento non cambiano.
- **Esempio:** prodotti 80 €, coupon −10 € → totale 70 €, IVA calcolata su 70 €;
  carta regalo con saldo 50 € → restano da pagare 20 € con un altro metodo. In
  fattura: totale 70 €, pagamenti carta regalo 50 € e carta di credito 20 €.
- **Fiscale:** trattata come buono multiuso (vendita senza IVA, IVA quando si
  spende), da far confermare al commercialista insieme alla natura IVA della
  riga di vendita e al codice del metodo di pagamento in fattura.
- **Differenza con `store_credit` (D35):** il buono a scalare gratuito è uno
  sconto e si inserisce nel campo coupon; la carta regalo è stata pagata e si usa
  come pagamento.
- **Proposta iniziale, non ancora approvata:** modello di tipo `gift_card` con
  tagli fissi, solo digitale, email al destinatario con codice e PDF; carta
  creata a ordine pagato; tabelle `gift_cards` (saldo) e
  `gift_card_transactions` (movimenti, come D27); coupon e campagne non si
  applicano alle carte; niente carte comprate con carte; rimborsi che tornano
  sulla carta; codice visibile solo nelle ultime 4 cifre e tentativi limitati;
  funzionalità `gift_cards` nell'area Vendite.

### D37 — Testata e totali dell'ordine (parte 4.4a)

| Gruppo | Colonne |
|---|---|
| Documento | code `ord_`, stage, channel, quote_number, quote_revision, quote_sent_at, quote_expires_at, order_number, ordered_at, completed_at, cancelled_at, user_id (operatore) |
| Stati | status, payment_status, fulfillment_status (D24) |
| Cliente | customer_id, email, phone, dati `billing_` e `shipping_` copiati (D24), price_list_id, prices_include_tax |
| Consegna | fulfillment_type (`shipping`, `pickup`, `none`), location_id (sede che evade o di ritiro), shipping_method_id (4.6) |
| Pagamento | payment_method_id, payment_term_id (4.4b) |
| Sconti sul totale | coupon_id, coupon_code, manual_discount_type, manual_discount_value |
| Totali | currency, products_total, discount_total, shipping_total, fees_total, taxable_total, tax_total, total, total_weight |
| Note | customer_note (scritta dal cliente), internal_note (solo backend), document_note (stampata sul PDF) |
| Carrello | cart_token (carrello senza account), last_activity_at (carrelli abbandonati) |
| Sito | custom_data (JSON con i dati in più del sito, D52) |

- **Totali** salvati per elenchi e statistiche, sempre ricalcolati dalla classe
  dei prezzi (D33). Il "da pagare" non si salva: è il totale meno i pagamenti
  riusciti (D28).
- **Revisioni del preventivo:** `order_quote_revisions` (order_id, revision,
  pdf_file, sent_to, sent_at, user_id), archivio dei PDF inviati (D24).
- **Dati di trasporto** (a cura di, vettore, aspetto dei beni, colli): nel DDT
  (4.4c).

**Tipi di riga** (`order_items.type`): `product`, `custom` (riga libera con
prezzo), `text` (solo descrizione), `shipping`, `fee` (commissione del metodo di
pagamento, es. contrassegno). Spedizione e commissioni entrano così nei
riepiloghi IVA, in fattura e nei rimborsi come le altre righe.

**IVA di spedizione e commissioni:** aliquota fissa scelta da `admin` nelle
impostazioni fiscali.

**Numerazione dei documenti:** formato unico `{YYYY}/{mm}{nnnn}` (es.
`2026/090001`) per preventivi, ordini, DDT e resi, con `document_sequences`
(document_type, year, month, last_number). Il progressivo riparte ogni mese e
ogni tipo di documento ha la propria sequenza; oltre 9999 documenti nel mese la
numerazione continua con una cifra in più. Le fatture hanno una numerazione
annuale con sezionale (D49).

*Scartato:* IVA di spedizione e commissioni che segue quella dei prodotti (art. 12
DPR 633/72, ripartita per aliquota).

### D38 — Metodi e condizioni di pagamento (parte 4.4b)

Estende D18 con quanto serve a cco e ai vecchi shop.

| Tabella | Colonne principali |
|---|---|
| `payment_methods` | code (es. `bank-transfer`), name, sdi_code (MP01–MP23), provider (`stripe`, `paypal`, `nexi`, `manual`), payment_account_id, fee_type (`amount`, `percent`), fee_value, available_for (`all`, `shipping`, `pickup`), applies_online, applies_office, applies_pos, instructions, stripe_payment_method_types, active, position |
| `payment_accounts` | code, name, bank_name, iban, bic, active |
| `payment_terms` | code, name, sdi_condition (`TP01` a rate, `TP02` completo, `TP03` anticipo), active, position |
| `payment_term_installments` | payment_term_id, days, end_of_month, percentage, position |

- **Metodi con costo aggiuntivo** (es. contrassegno): `fee_type` e `fee_value`
  generano la riga `fee` dell'ordine, con l'aliquota fissa di D37; per il
  contrassegno prevale `cod_fee` del listino di spedizione, se impostato (D41).
- `available_for`: contrassegno solo con spedizione, pagamento al ritiro solo con
  ritiro. `instructions`: testo per il cliente e per il PDF (es. IBAN).
- Codici SDI da `Custom\Fattura\Valori\Pagamento` del core. Metodi, conti e
  condizioni sono configurazione di `admin` (D3). ID di Fatture in Cloud in
  `external_references` (D49).
- **Conti:** banca e IBAN su preventivi, fatture e fattura elettronica.
- **Condizioni a rate.** Esempi: "Rimessa diretta" (una rata al 100%, subito,
  TP02); "Bonifico 30 gg fine mese" (TP02); "RiBa 30/60 gg fine mese" (due rate
  al 50%, TP01).
- **Scadenzario:** alla conferma dell'ordine una riga di `payments` in attesa per
  ogni rata, con scadenza e importo (D28); se l'ordine cambia prima di un
  pagamento, le rate si ricalcolano. Online il pagamento è immediato: una sola
  riga. Riquadro della dashboard con pagamenti in scadenza e scaduti.
- **Predefiniti sulla scheda cliente:** `contacts.payment_method_id` e
  `contacts.payment_term_id`, proposti negli ordini da ufficio (D31).
- **D20:** con una sola condizione, o nessuna, il campo non compare.
- Regime fiscale, esigibilità IVA e P.IVA del trasmittente (cco): impostazioni
  fiscali, sezione 6.

### D39 — DDT e scarico del magazzino (parte 4.4c)

| Tabella | Colonne principali |
|---|---|
| `delivery_notes` | code `del_`, number (D37), date, status (`draft`, `issued`, `cancelled`), reason (`sale`, `transfer`), order_id, stock_document_id, customer_id, from_location_id, dati `billing_` e `shipping_` copiati, transport_by (`sender`, `recipient`, `carrier`), carrier_id e dati del vettore copiati (4.6), transport_started_at, goods_appearance, packages_count, gross_weight, net_weight, freight_terms (`prepaid` porto franco, `collect` porto assegnato), document_note, pdf_file, user_id |
| `delivery_note_items` | delivery_note_id, order_item_id, product_id, sku, description, quantity, unit, batch_id, supplier_id, position |

- **Da ordine** (causale vendita): propone le righe non ancora consegnate; più DDT
  per lo stesso ordine per le consegne parziali; aggiorna `fulfillment_status`
  (D24).
- **Da trasferimento tra sedi** (D29), con causale trasferimento.
- **Emissione:** in bozza si modificano righe e dati di trasporto; all'emissione
  prende numero (D37) e PDF e non si modifica più; si annulla con lo stato
  `cancelled`; log in `delivery_note_status_logs` (D26).
- **Lotti** stampati sul DDT. Nel DDT in bozza il lotto si può correggere: la
  correzione sposta lo scarico da un lotto all'altro con due movimenti.
- **Prezzi** non mostrati: la fattura li prende dalle righe d'ordine.
- **Valori predefiniti** nelle impostazioni, come in cco: a cura di, vettore,
  aspetto dei beni, porto.
- **Fattura differita** (`deferred_invoicing`): una fattura TD24 per cliente con
  i DDT emessi nel periodo e non ancora fatturati (`invoice_delivery_notes`,
  D28); riquadro della dashboard con i DDT da fatturare; flusso nella sezione 5.

**Scarico del magazzino alla conferma dell'ordine.** La merce venduta online può
essere ancora fisicamente in magazzino: scaricandola subito, l'operatore sa che
non è più disponibile.

- Carrello e checkout prenotano (D27); alla conferma la prenotazione diventa un
  movimento `sale`. Al banco lo scarico è immediato.
- Lotto e fornitore si scelgono alla conferma: in automatico secondo D29, a mano
  negli ordini da ufficio.
- Ordine annullato dopo la conferma: movimenti `sale_cancel`.
- Il DDT non muove la giacenza, salvo la correzione del lotto.

**Unità di misura:** `product_models.unit` (predefinita `pz`), copiata su righe
d'ordine, DDT e fattura.

*Scartato:* scarico all'evasione (emissione del DDT, spedizione o ritiro).

### D40 — Resi (parte 4.4d)

| Tabella | Colonne principali |
|---|---|
| `sales_returns` | code `ret_`, number (D37), order_id, customer_id, channel (`online`, `office`, `pos`), status, location_id (sede che riceve la merce), requested_at, approved_at, received_at, completed_at, customer_note, internal_note, user_id |
| `sales_return_items` | sales_return_id, order_item_id, quantity, reason (`damaged`, `defective`, `wrong_item`, `not_as_described`, `changed_mind`, `wrong_size`, `other`), restock, note |

- **Stati:** `requested` (richiesta online) → `approved` o `rejected` →
  `received` (merce arrivata e controllata) → `completed` (reso chiuso);
  `cancelled`. Da ufficio e banco si parte da `approved` o `received`. Log in
  `sales_return_status_logs` (D26).
- **Magazzino:** `restock` per riga; al passaggio a `received` la merce rientra
  con un movimento `return` nella sede che la riceve, sullo stesso lotto e
  fornitore della vendita (D27, D29). Proposto spento per `damaged` e
  `defective`.
- **Richiesta online** (con `online_store`): dall'area cliente, entro il termine
  impostato da `admin` con due voci del pannello: "Giorni di reso" (predefinito
  14) e "Da quando contare i giorni di reso": inserimento del tracking
  (`shipped_at`, D42, predefinito), consegna registrata (`delivered_at`) o data
  dell'ordine. Se la data scelta manca: dalla consegna si passa al tracking, dal
  tracking all'ordine.
  Escluse le righe personalizzate (art. 59 Codice del Consumo) e i modelli non
  rendibili (`product_models.returnable`, es. alimentari deperibili). Email al
  commerciante alla richiesta e al cliente a ogni cambio di stato.
- **Rimborsi: funzionalità futura.** Per ora il commerciante rimborsa fuori dal
  gestionale e chiude il reso. Proposta da riprendere: importo per riga dal
  prezzo pagato meno la quota di sconto sul totale (D33); spedizione
  rimborsabile (art. 56 Codice del Consumo in caso di recesso); riga di
  `payments` di tipo `refund`, tramite gateway o manuale; nota di credito TD04 se
  l'ordine è fatturato (D28).

**Più avanti:** cambio automatico con un altro prodotto, rimborso con carta
regalo (D36), etichetta di reso del corriere.

### D41 — Metodi, zone e listini di spedizione (parte 4.6a)

| Tabella | Colonne principali |
|---|---|
| `shipping_methods` | code, name (es. "Standard", "Espresso"), description (tempi di consegna), carrier_id (D42), provider_service_code (D51), applies_online, applies_office, active, position |
| `shipping_zones` | code, name (es. "Italia", "Isole", "UE") |
| `shipping_zone_areas` | shipping_zone_id, country, province (vuota = tutto il paese) |
| `shipping_rates` | shipping_method_id, shipping_zone_id, volumetric_divisor, excess_mode (`total_weight`, `excess_only`), fuel_surcharge_percent, markup_percent (positivo o negativo), rounding_step, min_price, free_over_amount, free_under_weight, cod_fee, active |
| `shipping_rate_brackets` | shipping_rate_id, type (`price` scaglione, `excess` €/kg), max_weight, amount |

**Calcolo** (come vallis-serinae, con gli scaglioni in tabelle invece che in JSON):

1. peso tassabile: per prodotto il maggiore tra peso reale e volume ÷
   `volumetric_divisor`, sommato sulle righe (D23);
2. prezzo dello scaglione; oltre l'ultimo, tariffa al kg su tutto il peso
   (`total_weight`) o solo sulla parte in più (`excess_only`);
3. + supplemento carburante %, poi ± margine %;
4. arrotondamento per eccesso al gradino scelto; mai sotto `min_price`;
5. gratuita se il totale dei prodotti dopo gli sconti supera `free_over_amount`
   e/o il peso è sotto `free_under_weight` (se impostate entrambe, devono valere
   tutte e due).

- **Metodi:** un listino per coppia metodo-zona; al checkout compaiono i metodi
  con un listino per la zona, con prezzo e tempi di consegna.
- **Zona:** vale la più specifica (provincia prima del paese).
- **IVA:** prezzi con l'impostazione del catalogo (D19), aliquota fissa di D37.
- **Contrassegno:** `cod_fee` del listino, se impostato, prevale sul costo del
  metodo di pagamento (D38).
- **Ritiro in sede:** sedi con `is_pickup_point` (D29), gratuito, non
  selezionabile durante le chiusure; ordine pronto → `ready_for_pickup` (D24) ed
  email al cliente, tramite la spedizione di tipo `pickup` (D42).
- **Servizi e prodotti digitali:** `product_models.requires_shipping`
  (predefinito sì); senza righe da spedire l'ordine ha consegna `none` e il
  checkout salta la spedizione.
- **Ordini da ufficio:** costo calcolato allo stesso modo, modificabile a mano.
- **Coupon di spedizione gratuita** (D35): azzera la riga di spedizione.

**Più avanti:** zone per CAP, orari di ritiro prenotabili.

### D42 — Spedizioni, tracking e corrieri (parte 4.6b)

| Tabella | Colonne principali |
|---|---|
| `carriers` | code, name, dati aziendali con `AddressExtension::billing()` (per il DDT, D39), tracking_url_template (es. `https://…?code={tracking}`), provider (`manual` o integrazione, sezione 6), active, position |
| `shipments` | code `shp_`, type (`delivery`, `pickup`), order_id, delivery_note_id, shipping_method_id, carrier_id, location_id (ritiro), status, tracking_number, tracking_url, label_file, packages_count, weight, cod_amount, shipping_cost, provider_reference, shipped_at, delivered_at, customer_notified_at, note, user_id |
| `shipment_items` | shipment_id, order_item_id, quantity |

**Stati**, separati da quelli dell'ordine (D24); log in `shipment_status_logs` con
la risposta del corriere (D26):

| Tipo | Stati |
|---|---|
| `delivery` | `pending`, `label_created`, `in_transit`, `out_for_delivery`, `delivered`, `failed_attempt`, `exception`, `returned`, `cancelled` |
| `pickup` | `pending`, `ready_for_pickup`, `picked_up`, `cancelled` |

- **Ritiro in sede** come spedizione di tipo `pickup`: evasione, log ed email in
  un solo punto ("pronto per il ritiro", conferma di ritiro).
- **Righe evase:** in un DDT emesso (D39), in una spedizione partita o in un
  ritiro completato. La spedizione collegata a un DDT usa le righe del DDT. Da qui
  il `fulfillment_status` dell'ordine.
- **Modalità manuale** (`shipping`): spedizione creata dall'ordine (tutte le righe
  rimaste o una parte); corriere e tracking → `in_transit` e `shipped_at`; email
  al cliente con il link da `tracking_url_template`. La consegna raramente viene
  segnata a mano: il termine dei resi parte dal tracking (D40).
- **Corrieri collegati** (`carriers`, funzionalità futura, D51): etichetta dalla spedizione (peso, colli,
  indirizzo, contrassegno) con PDF, tracking e costo reale; aggiornamenti da
  webhook o controllo periodico; email al cliente per `in_transit`,
  `out_for_delivery` e `delivered`, al commerciante per `failed_attempt` ed
  `exception`. Interfaccia unica nel gestionale (crea etichetta, annulla
  etichetta, tracking); classi e credenziali nel core (D14). Strategia e scelta
  del servizio in D51.
- **Dashboard:** spedizioni in eccezione e non consegnate dopo N giorni. **Area
  cliente:** stato e link di tracking di ogni spedizione.

**Più avanti:** tariffe in tempo reale dal corriere, prenotazione del ritiro del
corriere, tracking per singolo collo.

### D43 — Banco (fase successiva)

Il banco si realizza in una fase successiva, insieme all'integrazione fiscale:
il cassiere deve inserire ogni vendita una sola volta.

- **Già deciso:** vendita = ordine del canale `pos` confermato e pagato (D24);
  scarico immediato dalla sede (D39); metodi di pagamento abilitati al banco
  (D38); campagne e coupon del canale banco (D34, D35); scanner su EAN o SKU
  (D23, D30); cliente facoltativo, con il suo listino (D33) e la fattura su
  richiesta.
- **Ricerca preliminare:** documento commerciale e corrispettivi telematici con
  registratore telematico con API, server RT o procedura "documento commerciale
  online"; collegamento tra terminale POS e registratore richiesto dal 2026 (da
  verificare); scontrino digitale per i privati negli ordini online (D46).
- **Bozza delle tabelle, da verificare con l'integrazione:** `pos_registers`
  (cassa, sede, dispositivo fiscale), `pos_sessions` (apertura e chiusura, fondo
  cassa, contanti attesi e contati, differenza), `pos_cash_movements` (versamenti
  e prelievi), `fiscal_receipts` (documenti commerciali di vendita, reso e
  annullo, con numero, dispositivo, codice lotteria e risposta),
  `orders.pos_session_id`.
- **Più avanti ancora:** funzionamento senza connessione, stampante e cassetto,
  lotteria degli scontrini.

*Scartato:* banco nel primo rilascio senza documento fiscale (vendite inserite due
volte, totali che possono non tornare).

### D44 — Abbonamenti (parte 4.6d)

Base spingy, con D17 (tabella propria, rinnovi senza ordini, fattura dal
pagamento).

| Tabella | Colonne principali |
|---|---|
| `plans` | code `pln_`, slug, name, description, tax_category_id, visible_online, active, position |
| `plan_features` | plan_id, key, value (limiti del piano, es. `shops` = 3) |
| `plan_prices` | plan_id, name, billing_interval (`month`, `year`), term_months (mesi coperti da un addebito), commitment_months, trial_days, price, sale_price, renewal_plan_price_id (prezzo a fine vincolo), active, position |
| `subscriptions` | code `sub_`, customer_id, plan_price_id, channel (`online`, `office`), status, provider (`stripe`, `manual`), payment_method_id, payment_term_id, coupon_id, coupon_code, dati `billing_` copiati, prices_include_tax, subtotal, discount_total, tax_id, tax_country, tax_rate, tax_total, total, currency, started_at, trial_ends_at, commitment_ends_at, current_period_end, next_renewal_at, auto_renew, cancelled_at, ended_at, provider_reference, note |
| `subscription_plan_changes` | subscription_id, old_plan_price_id, new_plan_price_id, user_id, source, changed_at |

Nomi corretti rispetto a spingy: `plan_futures` → `plan_features`,
`subscription_plan_history` → `subscription_plan_changes`. ID di prodotti,
prezzi e codici promozionali Stripe in `external_references` (D49).

**Stati:** `pending` (in attesa del primo pagamento), `trialing`, `active`,
`past_due` (rinnovo non riuscito, tentativi in corso), `unpaid` (tentativi
esauriti), `cancelled`. Il cambio piano resta sulla stessa riga: nessuno stato
`changed`. Log in `subscription_status_logs` (D26).

**Regole (da spingy):**

- attivo al primo pagamento riuscito; con prova gratuita, primo addebito a fine
  prova; nessuna fattura per prova o importo zero;
- cambio piano immediato, nuovo importo dal rinnovo successivo, senza
  conguagli, promozioni mantenute;
- a fine vincolo si passa a `renewal_plan_price_id`;
- disdetta = rinnovo automatico spento; valido fino alla fine del periodo pagato;
- rinnovo non riuscito: `past_due` durante i tentativi, poi `unpaid` con email
  per aggiornare la carta;
- limiti del piano (`plan_features`) controllati dal sito solo alla creazione di
  nuovi elementi: nulla si cancella con un downgrade.

**Rinnovi:**

- **Online** (`online_subscriptions`): addebiti gestiti da Stripe; ogni fattura
  Stripe pagata → riga di `payments` (D28) → fattura (D21); controllo orario di
  riallineamento se un webhook non arriva.
- **Da ufficio** (senza Stripe): a ogni periodo un cron crea il pagamento in
  attesa con la scadenza delle condizioni di pagamento (D38); fattura al
  pagamento, proforma a inizio periodo e rate scadute in D45 e D48.
- **Coupon:** `coupons.duration` (`once`, `repeating`, `forever`) e
  `duration_months`; per gli abbonamenti online sincronizzati come coupon e
  codice promozionale Stripe (ID in `external_references`, D49).

**Funzionalità futura:** abbonamenti con prodotti fisici (`plan_items`,
spedizione e scarico del magazzino a ogni rinnovo senza ordine).

### D45 — Dal preventivo alla fattura (parte 5.1)

**Flusso da ufficio e B2B:**

1. **Preventivo:** bozza → inviato (numero, PDF archiviato, nuova revisione a ogni
   modifica dopo l'invio, D24, D37) → accettato, rifiutato o scaduto (un cron lo
   segna alla scadenza). Accetta l'operatore; con l'ecommerce anche il cliente,
   dal link nell'email o dall'area cliente.
2. **Conferma:** diventa ordine con il suo numero; scarico del magazzino (D39);
   rate di pagamento in attesa (D38); email di conferma con PDF.
3. **Evasione:** DDT, spedizione o ritiro (D39, D42); ordine in `processing`,
   `fulfillment_status` aggiornato.
4. **Pagamenti** registrati sulle rate, scadenze in dashboard, `payment_status`
   dai pagamenti (D28).
5. **Chiusura:** ordine evaso e pagato → `completed` in automatico.

**Fattura dopo il pagamento**, come in spingy (D17), anche per gli ordini e per
gli abbonamenti da ufficio, con un'eccezione per la merce consegnata prima di
essere pagata (art. 6 e 21 DPR 633/72):

1. pagamento completo prima dell'evasione → fattura al pagamento;
2. merce consegnata prima del pagamento (condizioni posticipate, D38):
   - con `deferred_invoicing` e DDT: fattura al pagamento se arriva prima della
     fattura differita, altrimenti il DDT entra nella fattura differita del mese
     (TD24, entro il 15 del mese successivo);
   - senza: fattura all'evasione, entro 12 giorni;
3. servizi e abbonamenti: sempre al pagamento.

Il tipo di documento (TD01 o TD24) dipende dalle date.

**Ogni fattura nasce da un ordine** o dal pagamento di un abbonamento (D17), in
automatico: niente fatture create a mano e niente bozze da controllare. Gli unici
stati prima dell'invio sono quelli della coda (D28: `pending_send`,
`retry_send`).

**Richiesta di pagamento per gli abbonamenti da ufficio:** all'inizio del periodo
parte un PDF non fiscale (proforma) con importo, scadenza e IBAN, generato dal
pagamento in attesa (D38).

**Note di credito:** funzionalità futura, insieme ai rimborsi (D40); per ora le
correzioni si fanno fuori dal gestionale.

*Scartati:* fattura all'evasione come regola generale; fattura all'inizio del
periodo per gli abbonamenti da ufficio; note di credito manuali nel primo
rilascio; fatture create a mano o in bozza da controllare; solo vendite pagate
prima della consegna (senza condizioni posticipate né fattura differita).

### D46 — Checkout online (parte 5.2a)

**Checkout dell'ecommerce con Stripe Elements e pulsanti rapidi.**

- Il checkout del sito raccoglie i dati di fatturazione
  (`AddressExtension::billing()`, D31), calcola spedizione (D41) e ritiro,
  applica i coupon (D35), crea l'ordine e prenota la merce (D27).
- Pagamento con il modulo carte di Stripe (Payment Element), con Link che compila
  email, indirizzo e carta; gli altri metodi di D38 restano disponibili.
- **Pulsanti rapidi** (Express Checkout Element: Link, Apple Pay, Google Pay,
  Klarna, Amazon Pay, secondo la configurazione Stripe; PayPal diretto a parte,
  D50) su scheda
  prodotto, carrello e checkout. Il wallet fornisce email, telefono e indirizzo;
  le opzioni di spedizione si aggiornano con i listini di D41. Solo per chi non
  chiede fattura o ha già i dati salvati sulla scheda.

**Ospite o account.**

- Checkout da ospite attivo di default, disattivabile da `admin`: l'ordine crea o
  aggiorna la scheda cliente tramite l'email; il cliente riceve un link sicuro
  per seguire l'ordine.
- Account facoltativo a fine ordine; la scheda si collega dopo la verifica
  dell'email (D31).
- Coupon con limite per cliente e solo primo ordine: cliente riconosciuto
  dall'email (D35).
- Accedendo durante l'acquisto, il carrello da ospite si unisce a quello
  dell'account.

**Fattura negli ordini online** (art. 22 DPR 633/72):

- aziende: sempre;
- privati: solo se spuntano "Voglio la fattura" e inseriscono il codice fiscale;
- ordini senza fattura: riepilogo mensile dei corrispettivi per aliquota per il
  commercialista;
- impostazione di `admin` per fatturare tutti gli ordini.

**Funzionalità future:** accesso al sito con Apple e Google (dopo `AuthFederated`
nel core, D31); scontrino digitale per i privati, da valutare con la ricerca
sull'integrazione fiscale del banco (D43).

*Scartati:*

- Stripe Checkout ospitato: raccoglie solo la partita IVA europea (niente codice
  fiscale dei privati, SDI, PEC), al massimo 3 campi personalizzati, spedizione
  non ricalcolabile in base all'indirizzo, bonifico, contrassegno, Nexi e ritiro
  fuori, coupon da duplicare su Stripe;
- Stripe Checkout incorporato: risolve la spedizione, ma restano i limiti su dati
  di fatturazione e metodi di pagamento.

### D47 — Pagamenti online, annullamenti ed email (parte 5.2b)

**Numero d'ordine online all'invio**, anche con il pagamento in attesa: il cliente
lo usa subito, anche come causale del bonifico. I buchi lasciati dagli ordini
annullati non contano (gli ordini non sono documenti fiscali). Rivede D24 per il
canale online.

**Pagamenti:**

| Metodo | All'invio dell'ordine | Conferma e scarico del magazzino |
|---|---|---|
| Carta, Link, wallet, PayPal | pagamento immediato | subito, alla notifica del gateway |
| Bonifico | email con IBAN e causale, merce prenotata | alla registrazione del bonifico |
| Contrassegno | ordine confermato subito | pagamento registrato quando il corriere versa l'incasso |
| Pagamento al ritiro | ordine confermato subito | pagamento registrato al ritiro |

- **Pagamento non riuscito:** ordine in attesa, il cliente riprova dal link;
  prenotazione durante il pagamento di 30 minuti (impostazione).
- **Bonifico non arrivato:** "Giorni di attesa del pagamento" (predefinito 7):
  promemoria a metà periodo; alla scadenza ordine annullato in automatico, merce
  liberata, email a cliente e commerciante.

**Ordine pagato e poi annullato** (rimborsi: funzionalità futura, D40): movimenti
`sale_cancel` (D39); il pannello rimanda al rimborso dal gateway con il link al
pagamento; alla notifica del rimborso il gestionale registra una riga `refund`
(D28) e aggiorna `payment_status`; nota di credito fuori dal gestionale se la
fattura c'era (D45); email al cliente.

**Email** con `sendMail()` del core (SMTP o Brevo, registro in `MailLog`),
template sovrascrivibili come le view del modulo (D11), testi in `lang/`:

| Destinatario | Email |
|---|---|
| Cliente | ordine ricevuto (con istruzioni per il bonifico), ordine confermato, promemoria di pagamento, ordine annullato, spedito con tracking, pronto per il ritiro, consegnato (con corriere collegato), fattura disponibile, aggiornamenti del reso |
| Commerciante | nuovo ordine, ordine annullato per mancato pagamento, scorta minima (D27), fatture in errore o rifiutate, spedizioni con problemi (D42), nuova richiesta di reso (D40) |

Destinatari delle email al commerciante nelle impostazioni.

**Più avanti:** email di recupero dei carrelli abbandonati.

### D48 — Abbonamenti, annullamenti e resi (parte 5.3)

**Abbonamenti online** (come spingy):

1. il cliente sceglie piano e prezzo nella pagina dei piani;
2. inserisce i dati di fatturazione sul sito (D46); fattura come negli ordini
   online: aziende sempre, privati su richiesta;
3. paga con Stripe Checkout in modalità abbonamento (prova gratuita, codici
   promozionali, autenticazione della carta): i limiti visti per gli ordini qui
   non contano (dati di fatturazione già raccolti, nessuna spedizione, rinnovo
   solo con carta o SEPA);
4. dalle notifiche di Stripe: abbonamento → pagamento (D28) → fattura (D45);
5. area cliente: piano, prossimo rinnovo, fatture, cambio piano, disdetta
   (rinnovo automatico spento), aggiornamento della carta con il portale clienti
   Stripe (`BillingPortal` del core);
6. rinnovo non riuscito, riallineamento e cambio piano come D44; l'email di
   pagamento non riuscito porta al portale per aggiornare la carta.

**Abbonamenti da ufficio:**

1. l'operatore crea l'abbonamento: prezzo del piano, data di inizio, metodo e
   condizioni di pagamento (D38);
2. a ogni inizio periodo un cron crea il pagamento in attesa e invia la proforma
   (D45);
3. pagamento registrato → fattura → periodo successivo;
4. rata scaduta → `past_due` con promemoria email; dopo N giorni (impostazione,
   predefinito 15) → `unpaid`; l'annullamento lo decide il commerciante;
5. disdetta: rinnovo automatico spento, fine alla chiusura del periodo.

**Annullamenti da ufficio:**

- preventivo: rifiutato o scaduto, senza effetti;
- ordine confermato non evaso: movimenti `sale_cancel` (D39), rate in attesa
  annullate (D28), utilizzo del coupon restituito (D35), email al cliente;
- ordine evaso in parte: si annullano solo le righe non evase (la merce rientra,
  le rate si ricalcolano, D38); le righe evase si gestiscono con un reso (D40);
- ordine pagato: come D47; ordine fatturato: nota di credito fuori dal gestionale
  (D45).

**Resi:** flusso di D40, senza modifiche.

### D49 — Provider e fatturazione elettronica (parte 6.1)

**Provider** (schema di immobili: `FeedProvider` + `ProviderRegistry`):

- ogni integrazione implementa un contratto (chiave, etichetta, campi di
  configurazione, operazioni); un registro statico carica i provider di default
  da `config/module.php` e siti o moduli ne aggiungono con `register()`;
- contratti e adattatori in `src/Providers/` del gestionale (D10); classi di
  integrazione e credenziali nel core (D14).

| Contratto | Provider di default | Più avanti |
|---|---|---|
| `InvoiceProvider` | `fatture_in_cloud`, `xml` | intermediario SDI |
| `PaymentProvider` | `stripe`, `paypal`, `nexi`, `manual` (6.2) | |
| `CarrierProvider` | `manual` (6.3) | corrieri dopo la ricerca |

**Riferimenti esterni in una tabella unica** `external_references`: entity_type,
entity_id, provider, environment (`live`, `test`), object_type (es. `customer`,
`price`, `tax_rate`), external_id, synced_at, sync_error. Indice unico su
provider + environment + object_type + external_id. Vale per le entità
sincronizzate (clienti, aliquote, metodi e conti di pagamento, piani, prezzi,
coupon): nessuna colonna `fatture_in_cloud_id` o `stripe_*_id` nelle tabelle.
Nuovi provider senza modificare le tabelle; ID di test e di produzione separati.
Documenti nati da un provider (pagamenti, fatture, spedizioni, abbonamenti):
riferimento sul documento (D50).

**Fatturazione:** fattura sempre locale (D21), poi il provider attivo scelto da
`admin`.

- **Fatture in Cloud** (coda di spingy): cron ogni 5 minuti che crea il documento
  (cliente, aliquote, metodo e conto di pagamento), verifica l'XML e invia allo
  SDI; controllo dell'esito ogni 12 ore circa; stati e notifiche di D28. Prima
  della creazione confronta i totali locali con `getNewIssuedDocumentTotals`: se
  differiscono anche di un centesimo, la fattura va in errore.
- **XML:** FatturaPA generata con `Custom\Fattura`, stato `generated`, download
  singolo o zip mensile per il commercialista.

**Numerazione delle fatture:** numero progressivo annuale assegnato dal gestionale
con un sezionale dedicato al sito (es. `125/WEB`); a Fatture in Cloud si passano
numero e sezionale (`number`, `numeration`), così le fatture fatte a mano su
Fatture in Cloud non entrano in conflitto; identico nell'XML.

**Impostazioni fiscali** (`admin`): provider attivo, regime fiscale (codici RF),
esigibilità IVA, dati del trasmittente (XML), aliquota di ripiego (D21), aliquota
di spedizione e commissioni (D37), sezionale (le email per le fatture in errore
seguono D56),
bollo da 2 € automatico sulle fatture senza IVA sopra 77,47 €.

*Scartati:* colonne con gli ID esterni in ogni tabella; numero delle fatture nel
formato `{YYYY}/{mm}{nnnn}` (su Fatture in Cloud il numero è un intero);
numerazione assegnata da Fatture in Cloud (solo con quel provider).

### D50 — Pagamenti (parte 6.2)

**Documenti nati da un provider** (pagamenti, fatture, spedizioni, abbonamenti):
`provider` e riferimento esterno restano sul documento (`provider_reference`,
`provider_document_id`): ogni documento ha un solo provider e le notifiche lo
cercano di continuo. `external_references` resta per le entità copiate nei
sistemi esterni (D49).

**Stripe** (`stripe`): modulo carte con Link e pulsanti rapidi (D46), Checkout in
modalità abbonamento e portale clienti (D48), rimborsi registrati alla notifica
(D47). Notifiche: pagamento riuscito o fallito, checkout completato, fattura
dell'abbonamento pagata o non pagata, abbonamento modificato o chiuso, rimborso;
controllo orario di riallineamento (D44).

**PayPal diretto** (`paypal`, classe `PayPal` del core): provider separato, non
tramite Stripe. Pulsante PayPal a parte nel checkout; notifiche e rimborsi
comunicati come per gli altri provider.

**Nexi** (`nexi`): contratti con alias e chiave MAC (XPay classico) e con API key
(XPay Web, XPay Global), a seconda della banca e del servizio. La classe `Nexi`
del core si estende con alias e chiave MAC (lavoro preparatorio). Pagamento sulla
pagina di Nexi, notifica che crea la riga di pagamento, rimborsi dal pannello
Nexi.

**Manuale** (`manual`): bonifico, contrassegno, contanti, pagamento al ritiro e
RiBa registrati dall'operatore (D38, D47).

**Contratto `PaymentProvider`:**

- avvio del pagamento (indirizzo di reindirizzamento o codice per il modulo
  carte);
- ricezione della notifica: crea o aggiorna la riga di `payments` e salva la
  risposta nel log degli stati (D26);
- controllo dello stato di un pagamento, per il riallineamento;
- registrazione dei rimborsi comunicati dal provider (D47).

Avviare un rimborso dal gestionale resta una funzionalità futura (D40).

*Scartato:* PayPal tramite Stripe (commissioni doppie).

### D51 — Corrieri (parte 6.3)

**Primo rilascio senza corrieri collegati:** solo modalità manuale (D42), con il
provider `manual`. I corrieri collegati (`carriers`) sono una funzionalità futura.

**Strategia per quando si realizzano:** prima un aggregatore (una sola
integrazione per i corrieri italiani principali, stati di tracking già
uniformati), poi corrieri diretti come BRT per chi non vuole abbonamenti.
L'interfaccia unica permette entrambi.

**Scelta del servizio** nel sotto-progetto dei corrieri, confrontando costi e
contratti di 2 o 3 commercianti reali. Ricerca iniziale (2026-09-15):

| Servizio | Cosa offre | Contratto |
|---|---|---|
| Sendcloud | API unica per Poste Delivery Business, BRT, GLS, DHL, DPD; tariffe, etichette, tracking, resi, ritiri; API in tutti i piani | tariffe negoziate per BRT e GLS dal piano Lite; contratto proprio in Italia da verificare |
| ShippyPro | oltre 190 corrieri (BRT, GLS, Poste, Crono, SDA) con API REST; etichette PDF A6 o ZPL, tracking unificato | contratto proprio da verificare |
| Qapla' | etichette, tracking con 9 stati standard, notifiche email/SMS/WhatsApp, resi, giacenze; API e webhook | contratto del commerciante; 1 o 2 corrieri inclusi a seconda del piano |
| BRT diretto | API REST Shipment: etichetta, tracking e segnacolli | contratto BRT, nessun abbonamento |

**Contratto `CarrierProvider`:** crea etichetta (PDF, tracking, costo) e la annulla;
tracking da webhook o controllo periodico, con gli stati del servizio tradotti in
quelli di D42 e registrati nel log con la risposta. Servizio del corriere per
metodo di spedizione in `shipping_methods.provider_service_code`. Credenziali del
servizio scelto nel core (D14).

### D52 — Estensibilità per sito (sezione 7)

**Presentazione.**

- **Pagine dell'ecommerce** (vetrina, carrello, checkout, area cliente):
  pubblicate con `php forge publish:module ecommerce` e modificate in
  `custom/modules/ecommerce/view/`, come in immobili. I dati che ricevono sono
  documentati (D11); ogni cambiamento è segnalato nel changelog del modulo.
- **Email** (D47): stesso meccanismo delle pagine.
- **PDF** (preventivo, ordine, DDT, proforma, etichette): una classe per layout,
  indicata nella configurazione del modulo (es. `pdf.quote`); il sito la
  sostituisce con una propria classe che estende quella base.
- **Testi:** il sito sovrascrive le chiavi dei `lang/` del modulo; il core registra
  la lingua del sito per ultima.
- **Backend del gestionale:** le sue pagine non si personalizzano per sito; un sito
  con esigenze proprie aggiunge Resource sue.

**File di configurazione o database.** Regola: ciò che `admin` o il commerciante
cambiano senza pubblicare codice sta nel database; ciò che richiede codice sta nel
file.

| Dove | Cosa |
|---|---|
| File (`config/module.php` → `custom/config/modules/<slug>.php`) | classi di estensione, classi dei PDF, provider aggiunti, funzionalità del sito |
| Database (pannello) | funzionalità sbloccate (D13), impostazioni fiscali (D49), giorni di reso (D40), giorni di attesa del pagamento (D47), destinatari delle email, altre impostazioni del commerciante |

**Hook per il codice del sito.**

- **Registrazione:** elenco `extensions` di classi nella configurazione, così
  possono estendere sia il sito sia altri moduli (in rsvp è una sola classe).
- **Classe base:** ogni classe estende una base astratta con metodi vuoti e
  riscrive solo quelli che le servono.
- **Esecuzione:** uno dopo l'altro, in ordine di elenco.

| Pacchetto | Hook | Quando e a cosa serve |
|---|---|---|
| ecommerce | `checkoutFields()` | campi in più nel checkout, con la loro validazione |
| ecommerce | `beforeOrderCreate($payload)` | controlla o modifica i dati prima di creare l'ordine; può bloccarlo con un messaggio |
| ecommerce | `productViewData($product, $data)` | dati in più per la scheda prodotto |
| ecommerce | `seo($page, $state)` | meta tag e dati SEO della pagina |
| gestionale | `afterOrderConfirmed($order)` | ordine confermato |
| gestionale | `afterPaymentRecorded($payment)` | pagamento registrato |
| gestionale | `afterInvoiceIssued($invoice)` | fattura emessa |
| gestionale | `afterShipmentUpdated($shipment)` | spedizione aggiornata |
| gestionale | `onStatusChanged($entity, $field, $from, $to)` | ogni cambio di stato registrato nei log (D26) |
| gestionale | `beforeEmailSend($key, $message)` | prima di ogni email, per cambiare destinatari o contenuto |
| gestionale | `lowStock($products)` | prodotti sotto la scorta minima (D27), es. avvisi su altri canali |

- **Dati in più del sito:** colonna JSON `custom_data` su `orders` e `contacts`;
  contiene valori, non relazioni (D23).
- **Prezzi:** nessun hook li modifica. Si cambiano solo con listini, campagne e
  coupon (D33), così totali, IVA e fatture restano coerenti.
- **Errori:** gli hook `before…` e la validazione dei campi del checkout possono
  bloccare l'operazione; negli altri hook l'errore finisce nel log e il flusso
  prosegue, perché l'operazione è già salvata.

**Provider e funzionalità del sito.**

- **Provider aggiuntivi:** il sito li registra nei registri di D49; gli ID esterni
  vanno in `external_references`, senza modifiche alle tabelle.
- **Funzionalità del sito:** voci proprie nel pannello Funzionalità dalla
  configurazione (chiave, nome, dipendenze), lette con `Gestionale::feature()`
  come quelle dei pacchetti (D13).

### D53 — Installazione, dati iniziali e sede principale (parte 8.1)

**Installazione.** Le tabelle nascono dai Model dei moduli. Nelle nuove versioni
di `wonder-image/app` non esistono più `build/row` e `build/update`, e i moduli
non li usano.

**Dati iniziali e configurazione tra ambienti.** Si preparano in locale e arrivano
in produzione con il sync del core:

1. modifiche nel backend locale;
2. `php forge export` scrive `shared/sync-data.json`, committato in git;
3. al deploy `forge update` lo importa nel database di produzione.

Le tabelle sincronizzate dichiarano `syncSchema()` nel Model. Quali tabelle e con
quali regole: D54.

**Regole dei dati precaricati.**

- **Codice come chiave (D25):** una riga si inserisce solo se il suo codice non
  esiste, contando anche le righe cancellate; ciò che il commerciante modifica o
  elimina non viene mai sovrascritto né ricreato.
- **Nuove versioni del modulo:** possono aggiungere righe con la stessa regola.
- **Lingua:** nomi in italiano (D4).
- **Funzionalità (D13):** una riga in `features` per ogni chiave dei pacchetti e
  del sito, bloccata di default. Il sito può elencare in configurazione le
  funzionalità da sbloccare quando la riga nasce (starter, D12), mai dopo.

**Dati precaricati del gestionale.** Solo ciò che non fa comparire campi o scelte
inutili a chi è piccolo (D20).

| Dati | Cosa si precarica | Stato |
|---|---|---|
| Aliquote (`taxes`, D21) | 22%, 10%, 5%, 4% e le operazioni a 0% con le nature valide, dalla nuova classe del core | aliquote attive, nature disattivate |
| Tipi fiscali e regole | solo "Aliquota ordinaria", con le regole Italia privato e azienda al 22% | con un solo tipo il campo non compare |
| Impostazioni fiscali (D49) | nessun provider, regime RF01, esigibilità immediata, ripiego e spedizione al 22%, sezionale `WEB`, bollo automatico, prezzi IVA inclusa | da confermare nella configurazione guidata (8.2) |
| Metodi di pagamento (D38) | bonifico (MP05), carta con Stripe (MP08), PayPal (MP08), contrassegno (MP01, solo spedizione), pagamento al ritiro (MP01, solo ritiro), contanti (MP01, ufficio), Ri.Ba. (MP12, ufficio) | tutti disattivati: si attivano dopo aver inserito IBAN o credenziali |
| Conti di pagamento | Stripe e PayPal; il conto bancario lo crea `admin` con l'IBAN | attivi |
| Condizioni di pagamento | solo "Rimessa diretta" (TP02, 100% subito) | con una sola condizione il campo non compare |
| Formati etichette (D30) | A4 3×8 (70×37 mm), A4 3×7 (70×42,3 mm), A4 5×13 (38,1×21,2 mm), rotolo 50×30 mm, rotolo 62×29 mm; elenco finale e precaricamento con il sotto-progetto delle etichette, funzionalità futura (D59) | attivi |
| Impostazioni | reso entro 14 giorni dal tracking (D40); attesa del pagamento 7 giorni e prenotazione 30 minuti (D47); `unpaid` dopo 15 giorni (D48); lotti in scadenza entro 30 giorni (D29); destinatari delle email = email della società, se compilata | modificabili |
| Nessun dato | spedizioni, listini, corrieri; le numerazioni nascono al primo numero (D37) | — |

**Sede principale.**

- Nasce all'installazione come "Sede principale" (`is_default`, con giacenza).
- È la fonte dei dati: a ogni salvataggio indirizzo, telefono, email e orari si
  copiano in `society_address` e `society_timetable` del core.
- Si modifica in locale e arriva in produzione con `php forge export`, insieme
  alle tabelle della società già sincronizzate dal core.

*Scartati:* `build/row` e `build/update` per modulo, non più presenti nel core;
sede principale copiata dai dati della società.

### D54 — Sincronizzazione tra locale e produzione (parte 8.1 bis)

Precisa D53 dopo la verifica di `TableSync` (vincoli del framework).

**Id stabili (lavoro preparatorio nel core).**

- Nuova opzione del sync per le tabelle con più righe (es.
  `SyncSchema::multiRow()->keepIds()`): esporta anche `id` e `deleted`.
- L'import inserisce o aggiorna per `id`, senza svuotare la tabella; le righe
  assenti dal file vengono segnate come cancellate, mai eliminate.
- Le righe di configurazione nascono solo in locale, quindi hanno lo stesso `id`
  in ogni ambiente e le chiavi esterne dei dati di produzione restano valide.

**Cosa si sincronizza.** Regola: la configurazione di `admin` si sincronizza e si
modifica solo in locale; i dati del commerciante e quelli operativi vivono solo in
produzione.

| Sincronizzate (locale → produzione) | Solo produzione |
|---|---|
| `features`, `feature_logs` | catalogo, anagrafiche, listini, campagne, coupon, piani |
| `taxes`, `tax_categories`, `tax_rules` | ordini, magazzino, pagamenti, fatture, DDT, resi, spedizioni, log |
| `payment_methods`, `payment_accounts`, `payment_terms`, `payment_term_installments` | `location_closures`: le chiusure le gestisce il commerciante |
| `locations`, `location_opening_hours` | impostazioni del commerciante: destinatari delle email, lotti in scadenza |
| metodi, zone e tariffe di spedizione; `carriers` (futura) | **mai sincronizzate:** `document_sequences` (contatori) ed `external_references` (ID di produzione) |
| `label_formats`, impostazioni tecniche e fiscali | |

- **Impostazioni in due righe uniche:** tecniche e sincronizzate (fiscali, giorni
  di reso, attesa del pagamento, prenotazione, `unpaid`, email per gli errori da
  risolvere nel codice, D56); del commerciante e solo in produzione (destinatari
  delle email, email per gli errori da risolvere dal commerciante, D56, lotti in
  scadenza).
- **Pagine delle tabelle sincronizzate:** in produzione in sola lettura, con
  l'avviso "Si modifica in locale e si pubblica con il deploy" (opzione del core);
  in locale chiamano `TableSync::autoExport()` dopo il salvataggio, come le pagine
  CSS.

**Righe precaricate.**

- Le crea in automatico `forge update` in locale: tabelle dai Model, import di
  `shared/sync-data.json`, poi la classe `Defaults` di ogni modulo abilitato
  (prima il gestionale, poi l'ecommerce) aggiunge le righe mancanti con le regole
  di D53. Se ha aggiunto righe, aggiorna il file da committare.
- In produzione non crea righe: arrivano solo dal file. Righe create in produzione
  avrebbero `id` diversi da quelli locali e al deploy successivo verrebbero
  segnate come cancellate perché assenti dal file.

**Sede principale.**

- Alla creazione riprende una sola volta i dati già presenti nella società, così
  un sito esistente non li perde; da lì in poi comanda la sede (D53).
- Con il gestionale abilitato le pagine "Indirizzo" e "Orari" della società del
  core sono in sola lettura, con un link alla sede principale (opzione del core
  che permette a un modulo di bloccare una Resource del core).

**Modifiche a decisioni precedenti:** D13, lo sblocco delle funzionalità si fa in
locale e il pannello in produzione è in sola lettura; D29, le chiusure delle sedi
le gestisce il commerciante in produzione.

### D55 — Ecommerce, configurazione guidata e dati di prova (parte 8.2)

**Dati iniziali dell'ecommerce.** L'ecommerce non ha tabelle (D10): la sua classe
`Defaults` crea le righe nelle tabelle del gestionale.

- **Impostazioni del negozio** (tecniche, sincronizzate, D54): checkout da ospite
  attivo, fattura su tutti gli ordini disattivata (D46).
- **Condizioni generali di vendita:** tipo `terms_of_sale` registrato con
  `LegalDocumentTypeContext::addType()` del core; accettazione obbligatoria al
  checkout, registrata con i consensi del core (D31). Il documento nasce con lo
  stesso meccanismo di privacy e cookie (`legal_documents` è versionata e non
  sincronizzata).
- **Testo:** bozza nei `lang/` del modulo con le informazioni del Codice del
  Consumo (recesso di 14 giorni, garanzia legale, spese e tempi di consegna),
  segnata come da far verificare al legale del commerciante.

**Configurazione guidata ("Primi passi").**

- Riquadro in cima alla dashboard del gestionale, visibile ad `admin` finché resta
  qualcosa da fare; ogni voce porta alla pagina da completare.
- Controlli calcolati al momento, senza stati salvati. In locale guidano la
  configurazione; in produzione verificano anche le credenziali di produzione.

| Controllo | Quando serve |
|---|---|
| Dati della società: ragione sociale, P.IVA o codice fiscale, email | sempre |
| Indirizzo della sede principale | sempre |
| Impostazioni fiscali confermate: regime e prezzi IVA inclusa o esclusa, con un salvataggio esplicito perché hanno valori precaricati (D53) | sempre |
| Almeno un metodo di pagamento attivo, con le credenziali se è online | per ogni canale in uso |
| Almeno un metodo di spedizione con tariffa, oppure un punto di ritiro | con l'ecommerce |
| Provider della fatturazione configurato: Fatture in Cloud oppure dati del trasmittente per l'XML | con la fatturazione elettronica |
| Condizioni generali di vendita pubblicate, invio delle email configurato | con l'ecommerce |

- **Blocco del checkout:** gli stessi controlli bloccano gli ordini online finché
  mancano pagamento, spedizione o ritiro, condizioni di vendita. La vetrina resta
  visibile; il checkout mostra "Il negozio non accetta ordini in questo momento";
  il motivo compare solo ad `admin`. Gli ordini da ufficio non vengono bloccati.

**Dati di prova.**

- Comando `php forge gestionale:demo`, solo in locale: in produzione si rifiuta di
  partire.
- Crea prodotti semplici, con varianti, multiprodotti e personalizzazioni; clienti
  privati e aziende; giacenze e lotti; ordini in stati diversi e, con l'ecommerce,
  carrelli.
- Scrive solo nelle tabelle non sincronizzate e usa la configurazione precaricata:
  `forge export` non li porta mai in produzione.
- Servono a sviluppo, schermate della guida commercianti e test (sezione 9).

**Giacenza iniziale.** Causale `initial_stock` ("Giacenza iniziale") nella
rettifica rapida (D29), usabile anche senza `purchasing`. L'importazione da file di
catalogo, clienti e giacenze iniziali è una funzionalità futura, insieme a quella
dei listini (D33).

### D56 — Errori e test (parti 9.1 e 9.2)

Base del core (verificata il 2026-09-16): `Logger::log()` scrive righe JSON in
`storage/logs/<file>.log` e in debug interrompe l'esecuzione; tabelle `SqlError`,
`MailLog`, `AuthLog`; nessun helper per le transazioni (`ConsentService` usa
direttamente mysqli); `UpdateLock` con `GET_LOCK`; test come script PHP con
`tests/harness.php`, senza PHPUnit né database.

**Tre tipi di errore.**

| Tipo | Esempi | Chi lo vede | Dove finisce |
|---|---|---|---|
| Dell'utente | giacenza insufficiente, coupon scaduto, P.IVA non valida | operatore o cliente, con un messaggio tradotto | nessun log |
| Di un servizio esterno | Stripe rifiuta, fattura scartata dallo SDI, Fatture in Cloud non risponde | nel documento e nel riquadro "Da controllare" | log degli stati del documento (D26) e `storage/logs/error/<provider>.log` |
| Interno | bug, dati incoerenti | messaggio generico all'utente | `storage/logs/error/gestionale.log`, con il contesto |

- **Errori dell'utente:** eccezione di dominio con chiave di traduzione e parametri
  (es. `stock.insufficient`), così il messaggio è identico in backend, checkout e
  API.
- **Esecuzioni in background:** in webhook, cron e code il log non interrompe mai
  l'esecuzione (`renderDebug: false`).

**Chi deve intervenire.** Ogni errore dichiara nel codice chi deve risolverlo.

| Chi interviene | Errori | Indirizzi email |
|---|---|---|
| Sviluppatore | interni; credenziali non valide o scadute; webhook con firma non valida; provider che non rispondono dopo tutti i tentativi; fatture scartate per XML non valido | uno o più indirizzi nelle impostazioni tecniche (sincronizzate, D54) |
| Commerciante | fatture scartate per i dati del cliente (es. codice destinatario, P.IVA); pagamenti falliti; spedizioni in eccezione; email non recapitate | uno o più indirizzi nelle impostazioni del commerciante (solo produzione, D54) |

- **Riquadro "Da controllare"** nella dashboard: `administrator` vede gli errori del
  commerciante, `admin` tutti. Ogni voce porta al documento e alla guida (D32).
- **Email riepilogative** per ciascun gruppo di destinatari: raggruppate e senza
  ripetere lo stesso errore finché non si risolve, come la scorta minima (D27).

**Dati sempre coerenti.**

- **Transazioni** per le operazioni su più tabelle: conferma dell'ordine (righe,
  scarico, prenotazioni, rate, numero), annullamento, reso ricevuto, carico. Helper
  `transaction(fn)` nel core, sul modello di `ConsentService` (lavoro preparatorio).
- **Contemporaneità:** `FOR UPDATE` sulle righe di `document_sequences` e `stock`,
  così due ordini contemporanei non prendono lo stesso numero né l'ultimo pezzo.
- **Cron:** lock con `GET_LOCK`, come `UpdateLock`, perché lo stesso cron non parta
  due volte in parallelo.

**Servizi esterni.**

- **`provider_events`:** provider, environment, event_id (unico), type, payload,
  status (`received`, `processed`, `failed`), attempts, error, processed_at. Un
  evento già elaborato si ignora: Stripe, PayPal e Nexi ripetono i webhook e un
  pagamento non si registra mai due volte.
- **Tentativi automatici** con attesa crescente (5 min, 30 min, 2 h, 12 h) per
  fatture, tracking ed eventi falliti; poi stato di errore e avviso.
- **Nessun tentativo alla cieca:** prima di ripetere una creazione si controlla se
  il documento esiste già sul provider (es. fattura su Fatture in Cloud con numero
  e sezionale, D49).

**Test.** Convenzione del core: script PHP con `tests/harness.php`, lanciati tutti
da `php tests/run.php`.

| Livello | Cosa copre | Database |
|---|---|---|
| Unitari | prezzi e priorità (D33), campagne, coupon e ripartizione degli sconti, IVA e totali per aliquota (D21), rate (D38), tariffe di spedizione (D41), finestra di reso (D40), scelta di lotto e fornitore (D29), stato delle funzionalità (D13), numerazioni | no |
| Integrazione | conferma e annullamento dell'ordine con scarico e prenotazioni, pagamento → fattura, reso ricevuto → carico, trasferimenti, sync con `id` stabili (D54) | database locale dedicato, creato dai Model e riempito con i dati di prova (D55) |
| Provider | provider finti nei registri (D49) per i flussi; risposte reali salvate su file (webhook Stripe, Fatture in Cloud); XML FatturaPA validato con lo schema XSD ufficiale | no |
| Convenzioni | ogni Resource dichiara la sua funzionalità (D13); le tabelle sincronizzate usano gli `id` stabili (D54); ogni chiave di traduzione esiste in `lang/it` | no |
| End-to-end | pochi percorsi nel browser sul sito di prova: carrello, checkout con Stripe in modalità test, area cliente | sito locale |

- **Classi di calcolo senza database:** in `src/Support` ricevono i dati e non
  leggono il database.
- **Database dei test:** scelto con le variabili `DB_*`, mai quello di sviluppo.
- **Esecuzione automatica:** unitari, provider e convenzioni su GitHub Actions a
  ogni push.
- **Prima di ogni rilascio:** unitari e integrazione superati; prova manuale con gli
  account di prova di Stripe e Fatture in Cloud, secondo una checklist nella guida
  sviluppatori.

### D57 — Struttura delle guide ed errori ripetuti (parte 9.3)

**Dove stanno.** GitBook Git Sync collega più spazi allo stesso repository, ognuno
con la propria "Project directory" e il proprio `.gitbook.yaml`; le immagini non si
condividono tra spazi (verificato il 2026-09-16).

| Guida | Cartella | Spazio GitBook |
|---|---|---|
| Sviluppatori del gestionale | `gestionale/docs/`, con `.gitbook.yaml` nella radice come immobili e rsvp | Wonder Gestionale |
| Sviluppatori dell'ecommerce | `ecommerce/docs/` | Wonder Ecommerce |
| Commercianti | `gestionale/guide/`, con `.gitbook.yaml` e immagini proprie | Guida commercianti (Project directory `guide`) |

- Solo italiano (D4).
- Solo le funzionalità esistenti: ogni sotto-progetto aggiorna entrambe le guide
  (D32); le funzionalità future si aggiungono quando si realizzano.
- `docs/superpowers/` resta fuori dal sommario (D15).

**Guida sviluppatori del gestionale** (schema di immobili e rsvp):

```
## Guida introduttiva   Installazione · Struttura del modulo · Sito di prova e dati di prova
## Concetti            Funzionalità e ruoli · Sincronizzazione tra ambienti ·
                       Codici, numerazioni e log degli stati · Prezzi, IVA e totali
## Aree                Catalogo · Magazzino e sedi · Anagrafiche · Listini e promozioni ·
                       Vendite (preventivi, ordini, DDT, resi) · Pagamenti · Fatturazione ·
                       Spedizioni · Abbonamenti
## Estendere il modulo Hook · Provider (pagamento, fatturazione, corrieri) ·
                       Funzionalità del sito · PDF ed email
## Riferimento         Tabelle · Impostazioni · Comandi forge · Errori e log · Traduzioni ·
                       Sviluppo e test · Checklist di rilascio
```

Ogni pagina delle aree spiega tabelle, stati, flussi, funzionalità coinvolte e hook
disponibili.

**Guida sviluppatori dell'ecommerce:**

```
## Guida introduttiva    Installazione (richiede il gestionale) · Struttura del modulo
## Frontend             Route e flusso · Pagine e dati ricevuti · Personalizzare pagine ed email · SEO
## Checkout e pagamenti Flusso del checkout · Stripe (Payment Element, Express Checkout) ·
                        PayPal e Nexi · Webhook
## Area cliente         Account, ordini, resi, abbonamenti
## Estendere il modulo  Hook del checkout e della vetrina
## Riferimento          API del frontend · Traduzioni e URL · Sviluppo e test
```

**Guida commercianti:** sezioni nell'ordine del menu del backend, senza termini
tecnici.

```
## Primi passi          Accedere al pannello · Primi passi · Funzionalità incluse e da attivare ·
                        Il riquadro "Da controllare"
## Catalogo             Prodotti e varianti · Categorie, tag e marchi · Multiprodotto ·
                        Personalizzazioni · Etichette con codice a barre
## Magazzino            Giacenze e rettifiche · Carichi, scarichi e trasferimenti · Lotti e scadenze ·
                        Sedi, orari e chiusure · Avvisi di scorta minima
## Clienti e fornitori  Anagrafiche · Account dei clienti
## Prezzi e promozioni  Listini · Sconto massivo · Coupon
## Vendite              Preventivi · Ordini · Pagamenti e scadenze · DDT · Resi
## Negozio online       Ordini online e carrelli · Impostazioni del negozio · Condizioni di vendita
## Fatturazione         Fatture · Fattura differita · Casi particolari (fattura scartata, bollo)
## Spedizioni           Metodi e tariffe · Spedizioni e tracking · Ritiro in sede
## Abbonamenti          Piani · Abbonamenti dei clienti
## Glossario
```

- **Schema di ogni pagina:** riquadro iniziale ("Inclusa"; "Da attivare su
  richiesta", con i requisiti; "Configurata da Wonder Image", per le impostazioni
  sincronizzate in sola lettura in produzione, D54); a cosa serve; passo passo con
  screenshot; casi particolari.
- **Screenshot** dal sito locale con i dati di prova (D55), mai con dati reali.
- **"Funzionalità incluse e da attivare":** tabella generata dal comando di D32 da
  `config/features.php` di entrambi i pacchetti.
- **Rimandi dal backend:** il pulsante "Guida" (`docs()`) e le voci di "Da
  controllare" puntano alla pagina giusta o direttamente a "Casi particolari".

**Errori ripetuti senza documento** (completa D56). Gli errori legati a un
documento si risolvono con il cambio di stato del documento. Per bug, credenziali
scadute, provider irraggiungibili e webhook con firma non valida:

- **`error_reports`:** fingerprint (unico, da servizio, azione, classe
  dell'eccezione, file e riga), audience (`developer`, `merchant`), service,
  action, message, context, occurrences, first_seen_at, last_seen_at, notified_at,
  resolved_at, resolved_by.
- **Email** alla prima occorrenza; le successive aumentano solo il contatore.
- **Pagina "Errori"** nella sezione Sistema, solo per `admin`: si segna un errore
  come risolto; se si ripresenta dopo, riparte l'email.

### D58 — Roadmap: lavori nel core, priorità all'online, sito di prova (parte 10.1)

- **Ordine:** dipendenze tecniche (D8), con **priorità all'online**: le vendite da
  ufficio vengono dopo il primo rilascio. Sequenza dei sotto-progetti: D59.
- **Lavori preparatori nel core:**
  - prima del gestionale: sync con `id` stabili, `Defaults` in `forge update`
    locale, pagine sincronizzate in sola lettura in produzione, sola lettura di una
    Resource del core da un modulo (D54); `transaction(fn)` (D56); `docs()` nel
    `PageSchema` (D32); classe delle aliquote IVA italiane (D21);
  - insieme al sotto-progetto che li usa: EAN-13 ed EAN-8 in `createBarcode()`,
    con le etichette (D30, funzionalità futura, D59); credenziali PayPal e Nexi, alias e MAC di Nexi, con
    PayPal e Nexi (D14, D50);
  - `AuthFederated` dopo il primo rilascio, insieme all'accesso con Apple e Google
    (D46).
- **Ciclo di ogni sotto-progetto:** spec, piano, implementazione, test, entrambe le
  guide aggiornate, dati di prova estesi (D55). Se manca un pezzo, il
  sotto-progetto non è finito.
- **Versioni:** entrambi i pacchetti in `0.x` durante il nucleo, `1.0.0` al termine,
  con semver e `CHANGELOG.md` come immobili e rsvp.
- **Sito di prova:** `boilerplates/ecommerce-site`, sul modello di `immobili-site`
  (da `new-site`, gestionale ed ecommerce collegati via path repository). Nasce in
  G1, ospita test d'integrazione, end-to-end e screenshot; dopo il rilascio diventa
  la base dello starter (D12).

### D59 — Sequenza dei sotto-progetti (parte 10.2)

Completa D58; le etichette con codice a barre diventano una funzionalità futura.

**Nucleo del primo rilascio** (`1.0.0`), in ordine di dipendenza:

| # | Sotto-progetto | Contenuto | Decisioni |
|---|---|---|---|
| — | Lavori preparatori nel core | quelli "prima del gestionale" di D58 | D58 |
| G1 | Fondamenta | repository `wonder-image/gestionale` (qui si sposta questa spec); scheletro del modulo; funzionalità e pannello; codici; log degli stati; numerazioni; impostazioni; sync e `Defaults`; errori (`error_reports`, `provider_events`, "Da controllare"); IVA e impostazioni fiscali; sede principale; base di "Primi passi"; hook; test e GitHub Actions; spazi GitBook; comando dei dati di prova; sito di prova | D13, D18, D21, D25, D26, D29, D37, D52–D58 |
| G2 | Catalogo, magazzino base, anagrafiche | modelli, varianti, prodotti, attributi, brand, categorie, tag, immagini, SKU, EAN e MPN; giacenze, movimenti, rettifiche, prenotazioni; clienti e fornitori; avvisi di scorta minima | D23, D27, D29, D31 |
| G3 | Magazzino avanzato | più sedi e trasferimenti; acquisti e documenti di carico; lotti e scadenze; giacenza per fornitore | D27, D29 |
| G4 | Ordini e pagamenti | classe dei prezzi (prezzo base e saldo); ordini e righe (fasi carrello e ordine, totali e IVA, numerazione); gestione degli ordini nel backend (elenco, dettaglio, stati, evasione, annullamento); metodi e conti di pagamento; pagamenti; prenotazioni e scarico alla conferma con sedi, lotti e fornitori; resi con ricarico | D24, D28, D33 (base), D37, D38 (senza rate), D39 (scarico), D40, D47 |
| G5 | Multiprodotto e personalizzazione | componenti e loro scarico; campi di personalizzazione sulle righe | D23 |
| G6 | Listini e promozioni | listini cliente con scaglioni; sconto massivo; coupon; ripartizione degli sconti sulle righe | D33–D35 |
| G7 | Spedizioni | metodi, zone e tariffe; spedizioni e tracking manuali; ritiro in sede | D41, D42 |
| G8 | Fatturazione elettronica | fatture dopo il pagamento; provider XML e Fatture in Cloud con coda; bollo | D45, D49 |
| E1 | Negozio online (`wonder-image/ecommerce`) | vetrina, carrello, checkout con Stripe Payment Element ed Express Checkout, acquisto da ospite, area cliente, webhook, richiesta di reso, email, condizioni di vendita, blocco del checkout, SEO | D46, D47, D50 (Stripe), D55 |

- **G3 subito dopo G2:** lo scarico degli ordini (G4) nasce già con sedi, lotti e
  fornitori.
- **G6 prima di spedizioni e fatture:** righe e totali nascono già con listini e
  sconti.

**Dopo il primo rilascio**, in quest'ordine:

1. **Starter** da `ecommerce-site` (D12).
2. **E2 PayPal e Nexi** (D50), con le credenziali nel core (D58).
3. **G9 Vendite da ufficio:** creazione degli ordini da ufficio, preventivi con
   revisioni e PDF, condizioni di pagamento a rate e scadenzario, DDT e fattura
   differita TD24, fattura della merce consegnata prima del pagamento (D24,
   D37–D39, D45).
4. **G10 + E3 Abbonamenti** da ufficio e online (D44, D48).
5. **E4 Portale B2B** (D31).
6. **`AuthFederated`** e accesso con Apple e Google (D46, D58).
7. **Funzionalità future**, comprese le etichette con codice a barre (D30) e le
   ricerche per banco (D43) e corrieri (D51).

## Requisiti nuovi dichiarati

Assenti, o presenti solo in forma base, nei progetti analizzati:

1. **Multiprodotto:** un prodotto composto da più prodotti; lo scarico dal
   magazzino scarica tutti i componenti.
2. **Sconto massivo:** pannello per scontare in blocco per categoria o tag, con
   data e ora di inizio e fine, in € o %, attivabile e disattivabile.
3. **Personalizzazione all'acquisto** di prodotti e multiprodotti: es. nome,
   scelta del componente, altro. Base esistente in elenajossifov (appendice A).
4. **Funzionalità sbloccabili dal pannello** (D3, D13): es. multiprodotto,
   fatturazione, sincronizzazione con lo spedizioniere, sconto massivo.
5. **Gestione IVA identica a spingy** (D18).
6. **DDT e fattura differita**, lotti e scadenze (D16).
7. **Codici con prefisso per tutte le entità principali**, come in spingy: ogni
   pagamento `pay_…`, ogni fattura `inv_…`, prodotti `pro_…`, anagrafiche
   `con_…`, ecc. (D25)
8. **Log degli stati** di ordini e magazzino (D26).
9. **Avvisi di scorta minima:** il commerciante riceve un'email quando un
   prodotto scende sotto una soglia minima definita da lui (D27).
10. **Pagamenti tracciati:** ogni pagamento registrato in una tabella; tabelle di
    pagamenti e fatture (D28).
11. **Codici EAN e MPN** sui prodotti, oltre allo SKU (D23).
12. **Etichette con codice a barre** (EAN) dei prodotti (D30), funzionalità
    futura (D59).
13. **Documentazione per sviluppatori e per commercianti:** il backend rimanda
    alla guida per spiegare procedure e specifiche; la guida indica se una
    funzionalità è inclusa o da attivare su richiesta (D32).

## Vincoli del framework (verificati il 2026-09-11)

- **Moduli.** `module.json` validato. Le dipendenze sono verificate a runtime:
  `Registry::enabled()` lancia un'eccezione se la dipendenza non è abilitata.
  Model e Resource sono scoperti dalle cartelle del modulo.
- **Config.** `config/module.php` → `custom/config/modules/<slug>.php` → chiave
  `config` in `custom/config/modules.php`. Solo file: nessuno sblocco da pannello
  nativo.
- **Nessun sistema di eventi.** La proposta sul sistema moduli
  (`docs/app/proposte/module-system-project.md`) prevede hook sincroni
  (`module.boot`, `module.installed`, …), non ancora implementati.
- **Dati iniziali.** Nelle nuove versioni `build/row` e `build/update` non
  esistono più: le tabelle nascono dai Model dei moduli (indicazione del
  2026-09-16; nella copia locale 2.2.0 `UpdateRunner` esegue ancora `app/build/row`
  e `custom/build/row`). Immobili crea l'utente API al primo accesso al pannello.
- **Sync tra ambienti** (`TableSync`, verificato il 2026-09-16 sulla 2.2.0):
  - tabelle con `syncSchema()` nel Model (`singleton` o `multiRow`, colonne
    escludibili); nel core `society`, `society_address`, `society_timetable`,
    `society_legal_address`, `society_social`, `seo` e le tabelle CSS;
  - `forge export` scrive `shared/sync-data.json`, `forge update` lo importa;
    `SYNC_AUTO_EXPORT` riscrive il file dopo il salvataggio nelle Resource che
    chiamano `TableSync::autoExport()`; `SYNC_TABLES` limita le tabelle;
  - l'export toglie `id`, `creation`, `last_modified` e `deleted` e non ordina le
    righe; l'import delle tabelle `multiRow` le svuota (`TRUNCATE` con chiavi
    esterne disattivate) e reinserisce le righe: gli `id` vengono rinumerati e le
    righe cancellate tornano visibili; le tabelle sono importate in ordine di
    chiavi esterne (`SyncTableSorter`);
  - ciò che si modifica in produzione su una tabella sincronizzata viene
    sovrascritto al deploy successivo.
- **Ruoli.** `admin` e `administrator` definiti nel core. Permesso frontend
  `client` con hook `creation`, `modify`, `info`, `validate` e verifica email.
- **Traduzioni.** Il core traduce i testi dell'interfaccia (`lang/*.json`) e gli
  slug delle route (`urls.json`); nessun campo di contenuto traducibile. Immobili
  usa una tabella con una riga per lingua (`immobili_descrizioni`).
- **Schema.** `TableSchema` supporta chiavi esterne (con on update/delete),
  indici, unique, enum, JSON. `FormField` offre `selectSearch` multiplo,
  `checkTree`, `price`, `percentige`, `dateRange` e `repeater` con
  `RepeaterRelation` (righe in tabella collegata, posizione, soft delete).
- **Credenziali.** `Credentials::api()` legge con precedenza `.env` → riga
  `security` → default: Stripe (test/produzione, account), Fatture in Cloud (app,
  client, company, token), Klaviyo, Google, Apple, reCAPTCHA, ipinfo.
- **Plugin già presenti:**
  - Stripe (Checkout, Subscription, SubscriptionSchedule, Customer, Product,
    ProductPrice, Coupon, PromotionCode, TaxRate, BillingPortal);
  - PayPal (`PayPal(clientId, clientSecret, live)`) e Nexi (`Nexi(apiKey, prod)`,
    solo API key: il pagamento XPay con alias/MAC dei vecchi shop non è
    coperto);
  - Fatture in Cloud (Client, Document, EInvoice, Vat, PaymentMethod,
    PaymentAccount);
  - Klaviyo, Brevo, Google Merchant;
  - `Custom\Fattura`: costruisce l'XML FatturaPA (intestazione, righe,
    riepiloghi per aliquota e natura con riferimento normativo, bollo, sconti,
    pagamento) e lo esporta come stringa o file; non risulta usato nel
    framework;
  - `Custom\Fattura\Valori`: `Natura` (N1–N7 con descrizione, senza aliquota;
    N2, N3 e N6 non più validi dal 2021 ma ancora presenti), `Pagamento`
    (MP01–MP23), `CondizioniPagamento` (TP01–TP03), `RegimiFiscali` (RF01–RF19),
    `TipiDocumento` (TD01–TD28, con TD24 e TD25 per la fattura differita);
  - `Custom\Tax\Check` (P.IVA e codice fiscale);
  - `AddressExtension::billing()`.
- **Frontend.** `wonder-image/lib` non ha componenti da shop; `new-site` non ha
  pagine account.
- **GitBook.** In immobili e rsvp `.gitbook.yaml` punta a `./docs/` con
  `README.md` e `SUMMARY.md`; `docs/superpowers/` non compare nel sommario.

## Da decidere

Sezioni del design ancora da presentare (ordine in [TODO.md](../../../TODO.md)):

4. **Modello dati di base:** approvata (D23–D44).
5. **Flussi principali:** approvata (D45–D48).
6. **Integrazioni e provider:** approvata (D49–D51).
7. **Estensibilità per sito:** approvata (D52).
8. **Installazione e dati iniziali:** approvata (D53–D55).
9. **Errori, test e documentazione:** approvata (D56, D57).
10. **Roadmap dei sotto-progetti:** approvata (D58, D59).

Questioni aperte: nessuna (il portale B2B è collocato in D59).

## Appendice A — Riferimento funzionale dai progetti analizzati

I progetti servono solo come elenco di funzionalità, non come codice da
riprendere.

### Shop: elenajossifov-com, textile-collection-it, shop-vallisserinae-com

- **Catalogo.**
  - Brand (logo, descrizione, rilevanza); categorie ad albero (`parent_id`,
    immagini); tag (titolo, immagini); attributi (textile, vallis; immagine).
  - Gerarchia model → variant → product.
  - Model: codice, descrizione, composizione, manutenzione, produzione, tempi di
    lavorazione, bullet list, pesi e misure del prodotto e di spedizione,
    rilevanza.
  - Variant: immagini, pesi e misure, posizione nel model.
  - Product: SKU, EAN/barcode, dettaglio, disponibilità, prezzo, saldo, aliquota
    IVA, immagini (textile, vallis), posizione nella variante.
  - Categorie, tag e attributi salvati come array JSON su model e variant.
- **Personalizzazione (elenajossifov).**
  - Sul model: flag `customizable` + definizione JSON dei campi
    `{label, type: text|number|select, price, options[{label, price}]}`.
  - Il server ricalcola i valori inviati e somma i sovrapprezzi.
  - Sulla riga di carrello e ordine salva lo snapshot `[{label, value}]` e
    `customization_price` per unità.
- **Magazzino.**
  - `availability` intero sul prodotto.
  - Log con azione (add/remove/update), disponibilità precedente e successiva,
    causale (ordine, utente).
  - `CHECK_AVAILABILITY` per aggiungere al carrello solo se disponibile.
- **Carrello.**
  - Per sessione o utente, con unione del carrello da ospite dopo il login.
  - Paese, metodo e indirizzo di spedizione, dati di fatturazione, metodo di
    pagamento.
  - Totali: peso, subtotale, offerta, scontabile, coupon, contrassegno,
    spedizione, totale.
- **Checkout e ordini.**
  - Verifica del checkout, creazione dell'ordine con copia dei dati di
    fatturazione e dell'indirizzo (con sede per il ritiro).
  - Righe con prezzo, saldo, aliquota e valore IVA.
  - Log degli stati dell'ordine e dei pagamenti, con la risposta JSON del gateway.
  - Corriere e numero di tracking inseriti a mano.
  - Stati: in attesa, confermato, in lavorazione, in attesa di ritiro, consegnato
    parzialmente, destinatario non disponibile, eccezione, ritirato, consegnato,
    rifiutato, reso.
  - `BILLING_TYPE`: privati, aziende o entrambi.
- **Pagamenti.**
  - Metodi: bonifico, Nexi XPay, Nexi API key, PayPal, Stripe, contrassegno
    (solo spedizione), pagamento al ritiro (solo ritiro).
  - Stati: successo, in attesa, errore.
  - Credenziali PayPal e Nexi salvate nella tabella `ecommerce`.
- **Spedizioni.**
  - Paesi abilitati → listino.
  - Listino: tariffe a scaglioni di peso (JSON), tariffa di eccedenza al kg (sul
    peso effettivo o sulla sola eccedenza), coefficiente del peso volumetrico,
    supplemento carburante %, margine +/- %, arrotondamento, gratuita sopra un
    importo e/o sotto un peso, importo minimo, costo del contrassegno, IVA
    inclusa o no.
  - Sedi con indirizzo, orari, chiusure programmate e ritiro abilitato.
- **Coupon.**
  - Tipi: fisso, percentuale, buono a scalare (con residuo), spedizione gratuita.
  - Vincoli: spesa minima, esclusione dei prodotti in saldo, utente specifico,
    limite per utente, limite totale, date di validità.
  - Richiedono l'accesso.
- **Resi.** Reso per ordine con righe, causale (danneggiato, difettoso, errato,
  acquistato per errore, taglia) e rimborso.
- **Altro.**
  - Annunci; popup con pagine di destinazione.
  - Sincronizzazione del cliente su Stripe; profilo Klaviyo (textile).
  - Feed Google Merchant (elenajossifov); sitemap.
  - Normalizzazione delle posizioni di varianti e prodotti (textile).
  - Aliquote IVA precaricate con i codici natura N1–N7.

### Gestionale: cco-beverage-it

- **Anagrafiche.** Contatti di tipo cliente o fornitore con codice, colore e dati
  personali; indirizzi multipli; dati di fatturazione (privato/azienda, P.IVA,
  CF, SDI, PEC).
- **Prodotti.** Codice univoco, nome, note, prezzo, saldo, disponibilità,
  aliquota IVA.
- **Costi e giacenze per fornitore.**
  - Costo e disponibilità per coppia prodotto-fornitore; disponibilità del
    prodotto = somma dei fornitori.
  - Storico dei costi con quantità e causale.
  - Nello scarico senza fornitore indicato si scala prima dal fornitore con il
    costo più basso.
- **Operazioni di magazzino.**
  - Documenti di carico e scarico (codice, contatto, utente, data, totale, note,
    stato salvato/caricato) con righe (azione, quantità, costo).
  - Log di magazzino con costo e causale (preventivo, utente, carico, scarico,
    reso, magazzino).
- **Listini per cliente.** Prezzo per coppia cliente-prodotto.
- **Preventivi.**
  - Numero e anno, cliente, ritiro o consegna, indirizzi e fatturazione copiati.
  - Pagamento: condizioni e metodo, scadenza, banca/IBAN.
  - Trasporto: a cura di, data, vettore, aspetto dei beni, colli, peso.
  - Importi: subtotale, offerta, scontabile, coupon, contrassegno, spedizione,
    imponibile, imposte, totale. Appunti e note.
  - Stati: in creazione, salvato, confermato, in attesa di ritiro, in attesa di
    consegna, ritirato, consegnato, rifiutato, reso; log degli stati.
  - PDF del preventivo e fattura PDF.
- **Resi.** Righe con quantità, motivo, rimborso e ricarico a magazzino.
- **Configurazione.** Aliquote, metodi di pagamento (con commissione), condizioni
  di pagamento, regimi fiscali, valori predefiniti dei preventivi (P.IVA
  trasmittente, regime fiscale, esigibilità IVA).
- **Statistiche.** Istantanee per periodo: valore e numero di pezzi in magazzino,
  valore ipotetico, preventivi per stato (numero e valore), numero di clienti e
  fornitori; dashboard del magazzino.

### Abbonamenti e fatturazione elettronica: spingy-it

- **Piani.** Tipo, descrizione, funzionalità e limiti per piano (es. numero di
  negozi), visibilità, prodotto Stripe.
- **Prezzi del piano.** Intervallo di addebito, mesi di servizio coperti (1 o
  12), vincolo minimo in mesi, giorni di prova, prezzo e saldo, valuta, prezzo
  Stripe.
- **Abbonamento.**
  - Totali: subtotale, offerta, coupon, netto, paese, aliquota e valore IVA,
    totale.
  - Abbonamento e promozione Stripe, inizio e fine del vincolo, prossimo
    rinnovo, rinnovo automatico, stato.
  - Storico dei cambi piano; log degli stati con risposta.
- **Regole degli abbonamenti.**
  - Diventa attivo al pagamento riuscito; con prova gratuita il primo addebito
    è a fine prova.
  - Il cambio piano aggiorna subito il piano, ma il nuovo importo parte dal
    rinnovo successivo, senza conguagli; le promozioni restano.
  - Finito il vincolo, il rinnovo diventa mensile e si disdice disattivando il
    rinnovo automatico; resta valido fino alla fine del periodo pagato.
  - Rinnovo fallito: "in attesa di pagamento", tentativi gestiti da Stripe
    (fino a 8 in 10 giorni), poi "non pagato" con email per aggiornare la carta.
  - L'accesso resta finché l'abbonamento non viene annullato.
  - I limiti del piano si controllano solo alla creazione di nuovi elementi:
    nulla viene cancellato in caso di downgrade.
  - Controllo orario per riallineare pagamenti e abbonamenti con Stripe se un
    webhook non arriva.
- **Pagamenti.** Uno per fattura Stripe, con metodo e conto di pagamento,
  importo, stato, data; log con risposta.
- **Fatturazione elettronica (Fatture in Cloud + SDI).**
  - Prima il pagamento, poi la fattura: nessuna fattura per prova gratuita,
    importo zero o pagamento fallito.
  - Coda asincrona con due processi CLI protetti da `flock`:
    - ogni 5 minuti crea su Fatture in Cloud, invia allo SDI, gestisce retry e
      rifiuti;
    - ogni ~12 ore controlla l'esito SDI.
  - Stati: in attesa di invio (`pending_send`), da reinviare (`retry_send`),
    inviata (`sent`), errore (`error`), rifiutata (`rejected`), confermata
    (`confirmed`: trasmessa al SDI o accettata).
  - Errori temporanei (es. 502): lo stato non cambia.
  - Una fattura trasmessa non si reinvia: si corregge con nota di credito.
  - Se risulta in errore ma su Fatture in Cloud è già trasmessa, si riallinea da
    sola.
  - Fattura rifiutata: torna in coda automaticamente quando si modificano i dati
    dopo il rifiuto; pulsante "Riprova invio" su errore e rifiutata.
  - Email all'amministrazione (impostazione `email_invoice_errors`) su rifiuto o
    errore; email al cliente con numero, importo e link del documento.
  - Log degli stati con origine (cron, utente) e risposta completa consultabile.
- **Fiscalità.** Vedi D18.
- **Fuori perimetro (D16):** agenti, commissioni e payout, giochi, analytics,
  impersonificazione.

### Limiti dei vecchi progetti da non ripetere

- Campi di settore fissi nel model (composizione, manutenzione, produzione):
  servono attributi configurabili.
- Categorie, tag e attributi in array JSON: niente indici, filtri lenti.
- Giacenza come intero unico, senza prenotazione durante il checkout e senza più
  magazzini.
- Nessuna fattura negli shop; IVA per prodotto senza regole per paese.
- Credenziali dei gateway nella tabella `ecommerce`.
- Tracking manuale; messaggi (es. dei coupon) scritti in italiano nel codice.

## Appendice B — Convenzioni dei moduli esistenti (immobili, rsvp)

- **`module.json`.** Nome, slug, versione, namespace `Wonder\Plugin\<Studly>\`,
  entrypoint che implementa `ModuleInterface`, compatibilità framework e PHP,
  dipendenze, path (src, http, view, resources/assets, lang, tests), route per
  area, permessi, cartella dei Model.
- **`composer.json`.** Tipo `library`, `extra.wonder.module: true`, autoload PSR-4
  + `src/helpers.php`, eventuale `bin/<slug>` per la CLI, repository `path`
  verso `../app` in sviluppo.
- **Entrypoint** (es. `Immobili.php`). `root()`, `viewPath()` con override in
  `custom/modules/<slug>/view/`, `component()`, `layout()`, `renderPage()`,
  `context()` memoizzato, `config()`, `asset()` e `styleOnce()`.
- **`config/module.php`.** Default e registrazione di lang e provider. Override
  del sito in `custom/config/modules/<slug>.php` e in `custom/config/modules.php`.
- **Impostazioni.** Model `Settings` a riga unica + `SingletonResource`.
- **Estensioni del sito (rsvp).** Classe indicata nella config del modulo che
  implementa un'interfaccia con hook (`beforeSubmit`, `afterSubmit`, `seo`, campi
  custom), con fallback a un'implementazione nulla.
- **Integrazioni (immobili).** Contratto `FeedProvider` + `ProviderRegistry` con
  provider di default estendibili dal sito.
- **Task.** Utente API dedicato con token Bearer creato al primo accesso;
  endpoint in `http/api/task/*`; CLI `bin/<slug>` per i cron.
- **Documentazione e test.** GitBook in `docs/` (`.gitbook.yaml` con
  `root: ./docs/`; sezioni getting-started, configurazione, riferimento,
  frontend) e test in `tests/`.
