# TODO — gestionale + ecommerce

Documento di riferimento: [architettura](docs/superpowers/specs/2026-09-11-gestionale-ecommerce-architettura-design.md)

Regola: dopo ogni decisione approvata aggiorna la spec e spunta la voce qui.

## Fase 1 — Brainstorming dell'architettura

- [x] Analisi del contesto: moduli immobili e rsvp, framework `app`, 5 progetti di riferimento
- [x] Perimetro: due pacchetti, gestionale autonomo (D1, D2)
- [x] Accessi e sblocco delle funzionalità (D3)
- [x] Lingue e valute (D4)
- [x] Migrazione dei progetti esistenti: nessuna (D5)
- [x] Consegna del frontend (D6)
- [x] Canali di vendita (D7)
- [x] Ordine dei lavori (D8)
- [x] Approccio architetturale: tabelle condivise (D9)
- [x] Sezione 1 — pacchetti e tabelle (D10)
- [x] Ecommerce come modulo, non boilerplate (D11); starter da `wonder-image/new-site` (D12)
- [x] Sezione 2 — funzionalità e accessi (D13)
- [x] Credenziali tutte in `wonder-image/app` (D14)
- [x] Documentazione GitBook per `wonder-image/gestionale` (D15)
- [x] Documentazione per sviluppatori e per commercianti (D32)
- [x] Sezione 3 — mappa delle funzionalità (D16)
- [x] Abbonamenti separati dagli ordini (D17)
- [x] Gestione fiscale IVA come spingy (D18)
- [x] Prezzi e listini: IVA inclusa o esclusa, listini solo se sbloccati (D19)
- [x] Principio: semplice per chi è piccolo, completo per chi cresce (D20)
- [x] Modello fiscale locale, Fatture in Cloud come provider (D21)
- [x] Nomi in inglese per tabelle, colonne e classi (D22)
- [x] Contenuti del catalogo in una sola lingua per ora (D4 rivisto)
- [x] Glossario dei nomi inglesi (D22)
- [x] Sezione 4 — modello dati di base (D23–D44)
  - [x] 4.1 Catalogo, multiprodotto e personalizzazione (D23)
  - [x] 4.1 integrazione: SKU di modello e prodotto, GTIN e MPN, unicità (D23)
  - [x] 4.1 integrazione: EAN (D23) ed etichette con codice a barre (D30)
  - [x] Codici a barre EAN-13 ed EAN-8 disegnati dal core (D30)
  - [x] 4.2 Sedi, giacenze, lotti e scadenze, acquisti, giacenza per fornitore (D27, D29)
  - [x] 4.3 Anagrafiche, listini e promozioni
    - [x] 4.3a Anagrafiche e account (D31)
    - [x] 4.3b Listini e combinazione dei prezzi (D33)
    - [x] Sovrapprezzi sempre a prezzo pieno (D33)
    - [x] 4.3c Sconto massivo (D34)
    - [x] 4.3d Coupon e buono a scalare (D35)
    - [x] 4.3e Carte regalo: rimandate come funzionalità futura (D36)
  - [x] 4.4 Documenti di vendita (D24, D37–D40)
    - [x] 4.4a Testata e totali (D37)
    - [x] Numerazione `{YYYY}/{mm}{nnnn}` (D37)
    - [x] 4.4b Metodi e condizioni di pagamento (D38)
    - [x] 4.4c DDT, scarico del magazzino alla conferma, unità di misura (D39)
    - [x] 4.4d Resi, con i rimborsi come funzionalità futura (D40)
  - [x] Codici con prefisso per tutte le entità (D25)
  - [x] Log degli stati (D26)
  - [x] Avvisi email di scorta minima, funzionalità `low_stock_alerts` (D27)
  - [x] 4.5 Pagamenti e fatture (D28); origine e numerazione nelle sezioni 5 e 6
  - [x] 4.6 Spedizioni, banco, abbonamenti (D41–D44)
    - [x] 4.6a Metodi, zone e listini di spedizione, ritiro in sede (D41)
    - [x] 4.6b Spedizioni, tracking e corrieri (D42)
    - [x] 4.6c Banco: fase successiva con integrazione fiscale (D43)
    - [x] 4.6d Abbonamenti (D44)
- [x] Sezione 5 — flussi principali (D45–D48)
  - [x] 5.1 Dal preventivo alla fattura (D45)
  - [x] 5.2 Checkout online (D46, D47)
    - [x] 5.2a Account o ospite, richiesta di fattura, checkout proprio con Stripe Elements (D46)
    - [x] 5.2b Pagamenti in attesa, ordini pagati e annullati, email (D47)
  - [x] 5.3 Abbonamenti, resi e annullamenti (D48)
- [x] Sezione 6 — integrazioni e provider (D49–D51)
  - [x] 6.1 Architettura dei provider e fatturazione elettronica (D49)
  - [x] Riferimenti dei documenti nati da un provider: sul documento (D50)
  - [x] 6.2 Pagamenti: Stripe, PayPal diretto, Nexi (D50)
  - [x] 6.3 Corrieri: strategia e contratto, integrazione futura (D51)
- [x] Sezione 7 — estensibilità per sito (D52)
  - [x] Pagine ed email pubblicabili, PDF sostituibili da configurazione, testi sovrascrivibili
  - [x] Regola tra file di configurazione e database
  - [x] Hook per il codice del sito, `custom_data`, nessun hook sui prezzi
  - [x] Provider e funzionalità aggiunti dal sito
- [x] Sezione 8 — installazione e dati iniziali (D53–D55)
  - [x] 8.1 Tabelle dai Model, dati precaricati, sede principale che comanda i dati della società (D53)
  - [x] 8.1 bis Sincronizzazione tra locale e produzione: tabelle, `id` stabili, righe precaricate da `forge update` in locale (D54)
  - [x] 8.2 Ecommerce, configurazione guidata "Primi passi" con blocco del checkout, dati di prova, giacenza iniziale (D55)
- [x] Sezione 9 — errori, test e documentazione (D56, D57)
  - [x] 9.1 Errori: tre tipi, email separate per sviluppatore e commerciante, transazioni, `provider_events` (D56)
  - [x] 9.2 Test: cinque livelli con l'harness del core, database di test, GitHub Actions (D56)
  - [x] 9.3 Struttura delle due guide, tre spazi GitBook, errori ripetuti con `error_reports` (D57)
- [x] Sezione 10 — roadmap dei sotto-progetti (D58, D59)
  - [x] 10.1 Lavori preparatori nel core, `AuthFederated` dopo il primo rilascio (D58)
  - [x] Priorità all'online con le vendite da ufficio dopo il rilascio, versioni, sito di prova `boilerplates/ecommerce-site` (D58)
  - [x] 10.2 Sequenza: nucleo con magazzino avanzato e listini, vendite da ufficio dopo il rilascio, etichette future (D59)

## Fase 2 — Spec di architettura

- [x] Trasformare la bozza in spec definitiva, riorganizzata per capitoli
- [x] Autorevisione: segnaposto, contraddizioni, ambiguità, perimetro (correzioni nell'appendice E)
- [x] Approvazione finale della spec (2026-09-16)
- [x] Repository git locale `packages/gestionale` e commit della spec

## Fase 3 — Sotto-progetti

Sequenza in D59. Ogni sotto-progetto segue: spec → piano → implementazione → test, con entrambe le guide e i dati di prova aggiornati nello stesso sotto-progetto (D32, D58).

**Nucleo del primo rilascio (`1.0.0`)**

- [ ] Lavori preparatori nel core, prima del gestionale (D58)
  - Spec: `packages/app/docs/superpowers/specs/2026-09-16-prerequisiti-moduli-gestionale-design.md`
  - [x] Parte 1 del design: `APP_ENV`, sync con `id` stabili, `localOnly()`, righe precaricate dei moduli (2026-09-16)
  - [x] Parte 2 del design: transazioni e lock, pulsante "Guida", classi fiscali (2026-09-16)
  - [x] Parte E rivista: più sedi della società in "Dati aziendali", orari e chiusure sul modello di Google, migrazione dei dati esistenti (2026-09-17)
  - [x] Spec scritta e committata sul ramo `feature/prerequisiti-moduli-gestionale` di `wonder-image/app` (b06e4f5f)
  - [x] Revisione della spec scritta (2026-09-17)
  - [x] Piano 1 di 3 (ambiente e sincronizzazione, parti A–D): `packages/app/docs/superpowers/plans/2026-09-17-ambiente-e-sincronizzazione.md`
  - [x] Piano 1 implementato con test e documentazione in `docs/app/` (task 1–11, 2026-09-17)
  - [x] Piano 1, task 12: verifica con database su `boilerplates/new-site` (righe precaricate, export e import con `id` stabili, sola lettura in produzione) (2026-09-17)
  - [x] `APP_ENV` negli `.env.example` dei boilerplate (`new-site`, `immobili-site`, `rsvp-site`), rami `feature/app-env`
  - [x] Piano 2 di 3: transazioni, lock nominali, pulsante "Guida", classi fiscali (parti F–H), implementato con test e documentazione (2026-09-17): `packages/app/docs/superpowers/plans/2026-09-17-transazioni-guida-classi-fiscali.md`
  - [x] Piano 2, verifiche con database su `new-site`: `sqlInsert()` e `Model::create()` annullati insieme, letture `ForUpdate` dentro e fuori transazione, `NamedLock` con due processi, pulsante "Guida" nei dati del form (2026-09-17)
  - [x] Piano 3 di 3: sedi della società in "Dati aziendali" (parte E), implementato con test, documentazione e verifica con database su `new-site` (migrazione reale, più sedi, eredità, form resi) (2026-09-17): `packages/app/docs/superpowers/plans/2026-09-17-sedi-della-societa.md`
  - [x] Revisione dopo il controllo visivo (2026-09-17): pagina "Sedi" (percorso `app/config/locations`, solo `admin`) con orari e chiusure nella scheda, slug generato alla creazione e non modificabile, nome dell'attività unico nella sede predefinita (`$SOCIETY->name`) distinto dal nome della sede (`$SOCIETY->location->name`); verificata su `new-site` da riga di comando e nel browser (elenco, scheda, nuova sede)
  - [x] Prova di salvataggio dal browser su `new-site` (2026-09-17): nuova sede con orari e chiusure, orari non validi, cambio e ritorno della predefinita, eliminazione della predefinita rifiutata; corretti nel core: repeater che cancellava le righe aggiornate, `alertToast()` rotto dai messaggi testuali (e iniettabile da `?alert=`), 24:00 perso dal campo orario, eliminazione dall'elenco che ignorava le regole della Resource, nome con escape in `infoSociety()`, posizione delle nuove sedi
  - [x] Scheda "Sedi" rivista dall'utente (2026-09-17): slug fuori da aggiunta e modifica ma nell'elenco, contatti su una riga con PEC email e telefoni `phone()`; corretto l'indice UNIQUE dello slug che impediva di ricreare una sede eliminata con lo stesso nome
  - [x] `wonder-image/lib` 2.1.2-alpha.12: i campi `phone()` tengono il prefisso internazionale (prima tenevano solo 10 cifre e toglievano il "+", rovinando anche i numeri già salvati)
  - [x] Eliminazione rifiutata dall'elenco (2026-09-17): `ajaxRequest()` di `lib` mostra il messaggio delle risposte 4xx invece di "Errore 802" (ramo `fix/backend-error-messages`, 4a2380a); in `app` l'endpoint `api/backend/alert` accetta `alertType`, `alertTitle`, `alertText` che `lib` invia (223adf4b), prima gli avvisi con testo personalizzato uscivano vuoti. Verificato nel browser su `new-site`
  - [ ] `wonder-image/lib`: rilasciare il ramo `fix/backend-error-messages` e aggiornare `wonder-image` nei boilerplate (serve anche il rilascio di `app` con 223adf4b)
  - [x] Verificato in `wonder-image/immobili` il repeater delle immagini (2026-09-17): con il core installato in `immobili-site` salvare un immobile manuale **cancellava davvero** tutte le sue immagini (`softDelete(false)`); con la correzione del core (c030ce0f) restano, anche riordinate. Prova in transazione annullata. Su `immobili-site` nessun danno: tutti i 31 immobili arrivano dal feed. Il bug è in `app` da 77b878b8 (v2.1.0, v.2.1.1, v.2.1.2)
  - [ ] `clients/agliati/projects/agliati-com` usa `wonder-image/immobili`: controllare in produzione se ci sono immobili manuali salvati con immagini sparite (i file restano in `assets/upload/immobili`) e aggiornare `wonder-image/app` appena rilasciato
  - [x] Ramo `feature/prerequisiti-moduli-gestionale` unito in `main` in locale (avanzamento diretto fino a 223adf4b, 72 file di test verdi) e cancellato in locale (2026-09-17)
  - [ ] Push di `main` di `wonder-image/app`, rilascio minore e cancellazione del ramo remoto `feature/prerequisiti-moduli-gestionale` (a cura dell'utente)
- [ ] D60 (2026-09-18): vendita senza giacenza per prodotto con giacenza sotto zero (funzionalità `backorders`, G4) e prefisso delle tabelle `gst_`; spec di architettura aggiornata (1316556)
- [x] G1 Fondamenta (chiuso il 2026-09-21)
  - Spec: `docs/superpowers/specs/2026-09-18-fondamenta-gestionale-design.md`
  - [x] Repository `wonder-image/gestionale` creato su GitHub (privato) e primo push (2026-09-18)
  - [x] Spec di G1 scritta (2026-09-18): decisioni G1.1–G1.9, quattro piani
  - [x] Revisione della spec da parte dell'utente (2026-09-18): prefisso delle tabelle `gst_`, rilasci del core `2.2.1` e `2.2.2` prima della `2.3.0` (che porterà la gestione dei cron), nuovo requisito della vendita senza giacenza
  - [x] Sito di prova `boilerplates/ecommerce-site` creato dall'utente (2026-09-18): `https://ecommerce.test`, database `ecommerce_site`
  - [ ] Database dei test del sito di prova, creato dall'utente
  - [x] Piano 1 scritto ed eseguito (2026-09-18): `docs/superpowers/plans/2026-09-18-core-e-scheletro-del-modulo.md` — 7 task: campi modificabili in sola lettura, riquadri della home, comandi forge dei moduli, documentazione e versione `2.2.2`, scheletro del modulo, collegamento del sito di prova
    - [x] Task 1–5 nel core, sul ramo `feature/gestionale-core-additions` di `wonder-image/app` (e269df20 → 246fb94e), test verdi; merge in `main` da concordare perché l'utente sta lavorando nella stessa cartella
    - [x] Task 6: scheletro del modulo unito in `main` e pubblicato (ace56ea)
    - [x] Task 7: `ecommerce-site` con il modulo abilitato e valido, `forge update` riuscito (a3e8ed2)
    - [ ] `wonder-image/app`: `class/Console/Forge.php` cita `Wonder\Console\Commands\ScheduleRun`, che non è committato: chi installa il core da git trova `forge` rotto (a cura dell'utente, lavoro sui cron in corso)
    - [x] Database dei test: si usa quello del sito, `ecommerce_site`, con le modifiche annullate dalle transazioni (G1.11, 2026-09-18)
  - [x] Piano 2 scritto ed eseguito (2026-09-18): `docs/superpowers/plans/2026-09-18-funzionalita-e-sincronizzazione.md` — catalogo, stato effettivo, tabelle, righe precaricate, pagine legate alla funzionalità, pannello, sincronizzazione. Unito in `main` e pubblicato (10c8528); 21 funzionalità precaricate, pannello rifatto come pagina unica con gli interruttori, dichiarata con formSchema e formLayoutSchema su una pagina-form del core (G1.12 rivista; core `2.2.3` campo `toggle`, `2.2.4` pagine-form) e provato nel browser (sblocco e blocco a catena, sola lettura in produzione con POST rifiutato), giro completo del sync verificato
  - [ ] `wonder-image/app`: due file dello scheduler non committati (`class/App/Scheduler/ConfiguredTask.php`, `Presentation.php`) usati da codice committato; il tag `v.2.2.2` non li contiene e il runner va in errore al primo tick dovuto (a cura dell'utente)
  - [ ] `wonder-image/app`: ramo `fix/app-env-load-order` da unire e rilasciare — `Environment::current()` decideva prima del caricamento del `.env`, quindi in una richiesta web le pagine locali risultavano in sola lettura; contiene anche il badge delle sedi secondarie
  - [ ] `ecommerce-site`: `vendor/wonder-image/app` è collegato a `packages/app` (copia del pacchetto in `.app-pacchetto`); si torna al pacchetto dopo quel rilascio
  - [x] Piano 3 scritto ed eseguito (2026-09-21): `docs/superpowers/plans/2026-09-18-documenti-iva-e-sedi.md` — `Support\Codes` con i prefissi di tutte le entità, numerazione `{YYYY}/{mm}{nnnn}` con la riga bloccata (`gst_document_sequences`), base dei log degli stati e `StatusLogger`, riferimenti esterni con `save()`/`fail()` separati, tre tabelle fiscali con le loro pagine in Set Up, `TaxResolver` e `TaxTotals` puri, impostazioni tecniche (`admin`, sincronizzate) e del commerciante (`administrator`, mai sincronizzate), sede del gestionale che estende la pagina del core con il riquadro Magazzino, 31 righe precaricate. Core `2.2.7`/`2.2.8` (notifiche di salvataggio come toast con `FlashAlert`), `2.2.9` (pulsante Guida `btn-info btn-sm`), `2.2.10` (`SocietyLocationResource` estendibile). Verificato nel browser sul sito di prova.
  - [x] Piano 4 scritto ed eseguito (2026-09-21): `docs/superpowers/plans/2026-09-21-errori-hook-e-contorno.md` — `error_reports` **nel core** (scelta dell'utente: serve a qualsiasi sito) con `ErrorReporter` (impronta, contatore, riapertura, destinatari passati da chi segnala) e pagina "Errori" in Set Up, core `2.2.11`; nel modulo i tre tipi di errore (`UserError`, `ProviderError`, `Errors`), `gst_provider_events` con `ProviderEvents::receive()` che dice se l'evento è nuovo, hook del sito (`GestionaleExtension` + `Extensions::run()`/`filter()`, agganciati a `StatusLogger`), riquadri della home "Primi passi" (con `SetupChecks` puro) e "Da controllare", comandi `gestionale:demo` e `gestionale:features-doc`, GitHub Actions verde (unitari e convenzioni, con `config.platform.php` a 8.2), guida sviluppatori (errori, hook, sviluppo e test) e guida commercianti (accedere, primi passi, da controllare, sedi, impostazioni).
  - [ ] Piano 4: errori, riquadri della home, hook, dati di prova, GitHub Actions, guida sviluppatori e guida commercianti
- [ ] G2 Catalogo, magazzino base, anagrafiche (G2a chiuso il 2026-09-21)
  - **G2a Catalogo** — spec: `docs/superpowers/specs/2026-09-21-catalogo-design.md` (decisioni G2a.1–G2a.9)
    - [x] Piano 1 scritto ed eseguito (2026-09-21): `docs/superpowers/plans/2026-09-21-tassonomie-del-catalogo.md` — marchi, categorie ad albero e tag, `CategoryTree` puro (ordine, percorso, discendenti, cicli), le tre pagine nella nuova sezione "Catalogo", dati di prova. Verificato nel browser; unito in `main` (aa93112), CI verde.
    - [x] Piano 2 scritto ed eseguito (2026-09-21): `docs/superpowers/plans/2026-09-21-attributi-del-catalogo.md` — `gst_attributes` e `gst_attribute_values`, `Support\Catalog\Attributes` puro (livelli, tipi, `assignment()`, `format()`), pagina "Attributi" con i valori come repeater che compare solo per i tipi a elenco, tipo bloccato finché ci sono valori, due attributi nei dati di prova, guida sviluppatori e guida commerciante. Verificato nel browser.
      - Colonne `slug` e `group_name` invece di `key` e `group` della spec: sono parole riservate di MySQL e il core mette le virgolette ai nomi solo in INSERT, UPDATE e WHERE (spec aggiornata).
      - `UserError` ora estende `InvalidArgumentException`: è il tipo che il controller del backend trasforma in `$ALERT`. Prima un rifiuto di `mutateRequestValues()` (anello nelle categorie, tipo bloccato) usciva come pagina 500.
      - Aggiunto il controllo che la categoria padre esista ancora: prima un `parent_id` vecchio arrivava al database e tornava come 500.
      - `forge update` non prova più a reinserire le regole IVA già presenti con il codice nel formato vecchio.
    - [x] Piano 3 scritto ed eseguito (2026-09-21): `docs/superpowers/plans/2026-09-21-modelli-varianti-prodotti.md` — `gst_product_models` con categorie e tag, `gst_product_variants`, `gst_products`, le tre tabelle dei collegamenti degli attributi, `ProductAttributes`/`Sku`/`Ean`/`Skeleton`/`Combinations`, scheda del modello che si adatta (G2a.2), generatore delle combinazioni dalle spunte, elenco piatto "Prodotti" con la scheda del singolo. Verificato nel browser.
      - `wonder-image/app`: corretto in locale (commit `7d6df162`, **da rilasciare**) il `prepare()` dei form che arrotondava i decimali — `24,50` diventava `25,00` e `19,90` diventava `1990,00`, su qualsiasi campo numerico del backend (anche le aliquote IVA). Finché il sito di prova resta sulla 2.2.12 il prezzo scritto dalla scheda del prodotto continua ad arrotondarsi.
      - Nel modulo `Support\Numbers::fromForm()` per i numeri scritti con `Model::update()`, che non passa dal `prepare()` dei form.
      - Un'etichetta di valore non passa più da `sanitizeFirst()`: "XL" non deve diventare "Xl".
    - [x] Piano 4 scritto ed eseguito (2026-09-21): `docs/superpowers/plans/2026-09-21-immagini-e-dati-di-prova.md` — `gst_product_images` con lo stato e i tentativi, `ProductImages` (regola dell'ereditarietà, pura), `ImageQueue` a blocchi con tre tentativi, comando `gestionale:images` e attività `gestionale.images` dichiarata al core con `ModuleTasks`, galleria nella scheda del modello, tre articoli di prova con le loro foto, guide del commerciante (modelli, foto) e dello sviluppatore. **G2a chiuso.**
      - `wonder-image/app`: aggiunto `Image::deferResize()` (commit `81e1323f`, **da rilasciare** insieme a `7d6df162`): senza, un campo immagine prende da sé le misure responsive e il salvataggio ridimensiona tutto, che è esattamente quello che G2a.8 vuole evitare. Nel modulo c'è il ripiego (`method_exists`) per non rompere un sito con il core vecchio.
      - `wonder-image/app`: `ResponsiveImage` non pretende più `APP_URL`/`ROOT` per ridimensionare (stesso commit): servivano solo a comporre un indirizzo pubblico che chi ridimensiona non usa. Finché il sito di prova resta sulla 2.2.12, `php forge gestionale:images` si ferma e lo dice invece di bruciare i tentativi.
      - Cartella delle foto: è quella della pagina dei modelli, perché il repeater scrive i file nella cartella del Model e li rilegge in quella della Resource.
  - [ ] **G2a-bis La scheda prodotto semplice** — spec: `docs/superpowers/specs/2026-09-21-scheda-prodotto-semplice-design.md` (decisioni S1–S12)
    - Perché: la scheda di G2a ha dieci riquadri tutti uguali, le righe dei prodotti non dicono chi sono, e "livello dell'attributo" e "valori delle varianti" chiedono di conoscere il modello dati. Prima che magazzino, ordini e vetrina ci si appoggino sopra.
    - [ ] Piano scritto (2026-09-21): `docs/superpowers/plans/2026-09-21-scheda-prodotto-semplice.md` — dieci task, dal riquadro che si chiude alle guide riscritte. Da eseguire.
    - `wonder-image/app`: servirà `redirectUrl($action, $id)` con il caso `edit`, per atterrare sulla scheda appena creata. Da rilasciare con `7d6df162` e `81e1323f`.
  - [ ] G2b Magazzino base e anagrafiche
- [ ] G3 Magazzino avanzato
- [ ] G4 Ordini e pagamenti
- [ ] G5 Multiprodotto e personalizzazione
- [ ] G6 Listini e promozioni
- [ ] G7 Spedizioni
- [ ] G8 Fatturazione elettronica
- [ ] E1 Negozio online
- [ ] Rilascio `1.0.0` di gestionale ed ecommerce

**Dopo il primo rilascio**

- [ ] Starter da `boilerplates/ecommerce-site` (D12)
- [ ] E2 PayPal e Nexi
- [ ] G9 Vendite da ufficio
- [ ] G10 + E3 Abbonamenti
- [ ] E4 Portale B2B con account creato dall'azienda cliente (D31)
- [ ] `AuthFederated` e accesso al sito con Apple e Google (D46)

### Lavori preparatori in `wonder-image/app` (D58)

- [ ] Credenziali PayPal e Nexi in `Credentials::api()`, tabella `security` e pagina backend delle credenziali (D14)
- [ ] Classe delle aliquote IVA italiane accanto a `Custom\Fattura\Valori\Natura` (D21)
- [ ] EAN-13 ed EAN-8 in `createBarcode()`, insieme alle etichette (funzionalità futura, D30, D59)
- [ ] Nexi: alias e chiave MAC nella classe `Nexi`, oltre all'API key (D50)
- [ ] Opzione `docs()` nel `PageSchema` per il pulsante "Guida" del backend (D32)
- [ ] Dopo il primo rilascio: completare l'accesso con Google e Apple (`AuthFederated`), oggi non funzionante (D31, D58)
- [ ] Sync con `id` stabili: opzione di `SyncSchema` che esporta `id` e `deleted`, import che inserisce o aggiorna per `id` senza `TRUNCATE`, righe assenti segnate come cancellate (D54)
- [ ] Pagine delle tabelle sincronizzate in sola lettura in produzione, con avviso (D54)
- [ ] `forge update` in locale: classe `Defaults` di ogni modulo abilitato, in ordine di dipendenza, poi aggiornamento di `shared/sync-data.json` (D54)
- [ ] Opzione che permette a un modulo di rendere in sola lettura una Resource del core, es. indirizzo e orari della società (D54)
- [ ] Helper `transaction(fn)` per le transazioni, sul modello di `ConsentService` (D56)

### Documentazione

- [x] Spostare questa spec in `gestionale/docs/superpowers/specs/` alla creazione del repository del gestionale (D15)
- [ ] Creare i tre spazi GitBook con i sommari di D57: sviluppatori del gestionale, sviluppatori dell'ecommerce, commercianti (Project directory `guide`)
- [ ] Riquadro standard "Inclusa / Da attivare su richiesta" per le pagine della guida commercianti (D32)
- [ ] Comando che genera la tabella delle funzionalità da `config/features.php` (D32)
- [ ] Guida sviluppatori: hook, dati passati alle pagine e alle email, classi dei PDF sostituibili (D52)
- [ ] Guida sviluppatori: checklist di prova manuale prima di ogni rilascio, con gli account di prova di Stripe e Fatture in Cloud (D56)

### Verifiche con il commercialista

- [ ] IVA di spedizione e commissioni ad aliquota fissa, rispetto all'art. 12 DPR 633/72 per chi vende prodotti al 10% o al 4% (D37)
- [ ] Regole di emissione delle fatture: dopo il pagamento, merce consegnata prima all'evasione o in differita, scelta TD01/TD24 dalle date (D45, art. 6 e 21 DPR 633/72)
- [ ] Sezionale dedicato alle fatture emesse dal sito (D49)
- [ ] Carte regalo, quando si realizzano: buono multiuso, natura IVA della riga di vendita, codice del metodo di pagamento in fattura (D36)

### Verifiche legali

- [ ] Bozza delle condizioni generali di vendita nei `lang/` dell'ecommerce: informazioni obbligatorie del Codice del Consumo (D55)

## Funzionalità future e fasi successive

Fuori dal primo rilascio; progettate a grandi linee o solo annotate.

- [ ] Carte regalo vendute (D36)
- [ ] Etichette con codice a barre, con i formati precaricati (D30, D59)
- [ ] Rimborsi dei resi: importo per riga, spedizione, gateway, nota di credito (D40)
- [ ] Note di credito manuali e automatiche (D40, D45)
- [ ] Resi: cambio automatico con un altro prodotto, rimborso con carta regalo, etichetta di reso del corriere (D40)
- [ ] Coupon: generazione in blocco di codici usa e getta, più coupon nello stesso ordine (D35)
- [ ] Listini: importazione da file, listini per categoria (D33)
- [ ] Importazione da file di catalogo, clienti e giacenze iniziali (D55)
- [ ] Magazzino: ordini a fornitore, inventario con conteggio guidato, soglia di scorta minima per sede (D27, D29)
- [ ] Anagrafiche: più utenti della stessa azienda sul portale B2B, referenti aziendali, gruppi di clienti (D31)
- [ ] Marketplace e feed, compreso Google Merchant (D7, D23)
- [ ] Dati aziendali da Google (core): cron che verifica orari e chiusure dalla scheda Google tramite Place ID, Place ID dall'autocomplete, embed automatico della mappa
- [ ] Spedizioni: zone per CAP, orari di ritiro prenotabili (D41)
- [ ] Spedizioni: tariffe in tempo reale dal corriere, prenotazione del ritiro del corriere, tracking per singolo collo (D42)
- [ ] Corrieri collegati: prima un aggregatore scelto con 2-3 commercianti reali, poi corrieri diretti come BRT (D51)
- [ ] Banco con integrazione fiscale: ricerca (registratore telematico con API, server RT, documento commerciale online, collegamento POS-RT) e sotto-progetto, con verifica delle tabelle in bozza (D43)
- [ ] Banco: funzionamento senza connessione, stampante e cassetto, lotteria degli scontrini (D43)
- [ ] Abbonamenti con prodotti fisici: prodotti del piano, spedizione e scarico a ogni rinnovo (D44)
- [ ] Email di recupero dei carrelli abbandonati (D47)
- [ ] Scontrino digitale per i privati negli ordini online, da valutare con la ricerca del banco (D43, D46)
