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
  - [x] `wonder-image/lib`: ramo `fix/backend-error-messages` rilasciato con `2.1.2-alpha.16` (2026-09-29); `ecommerce-site` aggiornato (`npm install wonder-image@2.1.2-alpha.16` e copia del `dist`), nel bundle c'è `ajaxResponseMessage()`: i rifiuti 4xx mostrano il messaggio vero al posto di «Errore 802». Da confermare nel browser al primo rifiuto utile (eliminazione di un articolo con movimenti)
  - [ ] `wonder-image`: gli altri boilerplate sono fermi a versioni vecchie — `new-site` `^2.1.2-alpha.15`, `immobili-site` `^2.1.1-alpha.5`, `rsvp-site` `^2.1.1-alpha.2`; ognuno va aggiornato e riprovato
  - [x] Verificato in `wonder-image/immobili` il repeater delle immagini (2026-09-17): con il core installato in `immobili-site` salvare un immobile manuale **cancellava davvero** tutte le sue immagini (`softDelete(false)`); con la correzione del core (c030ce0f) restano, anche riordinate. Prova in transazione annullata. Su `immobili-site` nessun danno: tutti i 31 immobili arrivano dal feed. Il bug è in `app` da 77b878b8 (v2.1.0, v.2.1.1, v.2.1.2)
  - [ ] `clients/agliati/projects/agliati-com` usa `wonder-image/immobili`: controllare in produzione se ci sono immobili manuali salvati con immagini sparite (i file restano in `assets/upload/immobili`) e aggiornare `wonder-image/app`, ora rilasciato fino a `v2.4.0-beta.1`
  - [x] Ramo `feature/prerequisiti-moduli-gestionale` unito in `main` in locale (avanzamento diretto fino a 223adf4b, 72 file di test verdi) e cancellato in locale (2026-09-17)
  - [x] Push di `main` di `wonder-image/app`, rilasci fino a `v2.4.0-beta.1` e ramo remoto `feature/prerequisiti-moduli-gestionale` cancellato (verificato il 2026-09-29)
- [ ] D60 (2026-09-18): vendita senza giacenza per prodotto con giacenza sotto zero (funzionalità `backorders`, G4) e prefisso delle tabelle `gst_`; spec di architettura aggiornata (1316556)
- [ ] D61 (2026-09-29): **sequenza rivista per la consegna del primo negozio** (entro il 2026-10-29). Il negozio vende da una sede sola, con spedizioni, coupon e sconti, prodotti composti e personalizzabili; senza fatturazione elettronica. G3 e i documenti di magazzino (G2b-bis piani 4-6) escono dal percorso della consegna: restano dietro i loro interruttori (`multi_location`, `purchasing`, `batch_tracking` spenti) e la merce entra con la rettifica rapida. Vincolo su G4: prenotazione e scarico passano da **un unico servizio**, che riceve già sede, lotto e fornitore anche se `gst_stock.batch_id` e `supplier_id` restano a zero, così G3 toccherà solo quel punto. Spec di architettura §10.3 aggiornata
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
    - [x] `wonder-image/app`: `class/Console/Commands/ScheduleRun.php` è committato (verificato il 2026-09-29); `forge` non è più rotto per chi installa da git
    - [x] Database dei test: si usa quello del sito, `ecommerce_site`, con le modifiche annullate dalle transazioni (G1.11, 2026-09-18)
  - [x] Piano 2 scritto ed eseguito (2026-09-18): `docs/superpowers/plans/2026-09-18-funzionalita-e-sincronizzazione.md` — catalogo, stato effettivo, tabelle, righe precaricate, pagine legate alla funzionalità, pannello, sincronizzazione. Unito in `main` e pubblicato (10c8528); 21 funzionalità precaricate, pannello rifatto come pagina unica con gli interruttori, dichiarata con formSchema e formLayoutSchema su una pagina-form del core (G1.12 rivista; core `2.2.3` campo `toggle`, `2.2.4` pagine-form) e provato nel browser (sblocco e blocco a catena, sola lettura in produzione con POST rifiutato), giro completo del sync verificato
  - [x] `wonder-image/app`: `class/App/Scheduler/ConfiguredTask.php` e `Presentation.php` sono committati (verificato il 2026-09-29)
  - [x] `wonder-image/app`: `fix/app-env-load-order` unito e rilasciato — `Environment::current()` chiama `Credentials::loadEnv()` prima di decidere (verificato il 2026-09-29)
  - [ ] `ecommerce-site`: dal 2026-09-29 `vendor/wonder-image/app` è di nuovo un symlink a `packages/app`, dichiarato come `repositories` di tipo `path` in `composer.json` (come `gestionale`), con il vincolo `^2.4.0-beta.1 || dev-main`. Serve a provare subito le correzioni del core mentre si sviluppa il gestionale; **va tolto** quando il sito diventerà lo starter di D12, e finché c'è il sito non prova più l'installazione dal pacchetto. Test del modulo verdi con il core a `898034f3`
  - [x] Piano 3 scritto ed eseguito (2026-09-21): `docs/superpowers/plans/2026-09-18-documenti-iva-e-sedi.md` — `Support\Codes` con i prefissi di tutte le entità, numerazione `{YYYY}/{mm}{nnnn}` con la riga bloccata (`gst_document_sequences`), base dei log degli stati e `StatusLogger`, riferimenti esterni con `save()`/`fail()` separati, tre tabelle fiscali con le loro pagine in Set Up, `TaxResolver` e `TaxTotals` puri, impostazioni tecniche (`admin`, sincronizzate) e del commerciante (`administrator`, mai sincronizzate), sede del gestionale che estende la pagina del core con il riquadro Magazzino, 31 righe precaricate. Core `2.2.7`/`2.2.8` (notifiche di salvataggio come toast con `FlashAlert`), `2.2.9` (pulsante Guida `btn-info btn-sm`), `2.2.10` (`SocietyLocationResource` estendibile). Verificato nel browser sul sito di prova.
  - [x] Piano 4 scritto ed eseguito (2026-09-21): `docs/superpowers/plans/2026-09-21-errori-hook-e-contorno.md` — `error_reports` **nel core** (scelta dell'utente: serve a qualsiasi sito) con `ErrorReporter` (impronta, contatore, riapertura, destinatari passati da chi segnala) e pagina "Errori" in Set Up, core `2.2.11`; nel modulo i tre tipi di errore (`UserError`, `ProviderError`, `Errors`), `gst_provider_events` con `ProviderEvents::receive()` che dice se l'evento è nuovo, hook del sito (`GestionaleExtension` + `Extensions::run()`/`filter()`, agganciati a `StatusLogger`), riquadri della home "Primi passi" (con `SetupChecks` puro) e "Da controllare", comandi `gestionale:demo` e `gestionale:features-doc`, GitHub Actions verde (unitari e convenzioni, con `config.platform.php` a 8.2), guida sviluppatori (errori, hook, sviluppo e test) e guida commercianti (accedere, primi passi, da controllare, sedi, impostazioni).
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
    - [x] Piano scritto ed eseguito (2026-09-21): `docs/superpowers/plans/2026-09-21-scheda-prodotto-semplice.md` — vocabolario (Modelli → Prodotti su `app/gestionale/prodotti`, elenco piatto fuori dal menu su `app/gestionale/versioni` filtrato per articolo), creazione a quattro campi, scheda da dieci riquadri a sei, `Combinations::plan()` a N assi + `Generator` fuori dalla Resource, colonna `name` su `gst_products` prima nella griglia, "Come si usa" al posto del "Livello", elenco con miniatura/prezzo/versioni. **G2a-bis chiuso.**
      - L'`Accordion` **non** funziona dentro un form: il suo corpo lo disegna il tema Bootstrap (`col-span-6`) mentre i campi del form portano il `col-6` del tema Wonder, e finiscono ammassati. `GestionaleResource::foldable()` torna una `Card` normale e la mette in fondo. Da rivedere se un giorno si tocca il rendering del core.
      - `wonder-image/app`: aggiunto `redirectUrl($action, $id)` con il caso `edit` (commit `cb1716bf`, **da rilasciare** insieme a `7d6df162` e `81e1323f`). Finché il sito resta indietro, dopo la creazione si torna all'elenco invece che sulla scheda.
      - Rinominare la pagina vuol dire rinominare `ProductImages::DIR`: il repeater scrive i file nella cartella del Model e li rilegge in quella della Resource.
      - Corretto anche `tests/integrazione/CatalogDemoTest.php`: cancellava le foto dal disco e la transazione non le riportava indietro, lasciando il sito con righe che puntavano a file inesistenti.
    - `wonder-image/app`: servirà `redirectUrl($action, $id)` con il caso `edit`, per atterrare sulla scheda appena creata. Da rilasciare con `7d6df162` e `81e1323f`.
  - [x] **G2b Magazzino base e anagrafiche** — spec: `docs/superpowers/specs/2026-09-21-magazzino-e-anagrafiche-design.md` (decisioni G2b.1–G2b.12). **G2b chiuso il 2026-09-24.**
    - [x] Spec scritta (2026-09-21): giacenze, movimenti, prenotazioni (solo tabella e disponibile), avvisi di scorta, clienti e fornitori; quattro piani
    - [x] Revisione della spec da parte dell'utente (2026-09-22)
    - [x] Piano 1 scritto ed eseguito (2026-09-22): `docs/superpowers/plans/2026-09-22-fondamenta-del-magazzino.md` — le quattro tabelle del magazzino, `Stock::apply()` come unica porta di scrittura (transazione, `FOR UPDATE`, avviso di scorta), quattro classi pure (`Reasons`, `Availability`, `Adjustment`, `LowStock`) e quattro di servizio (`Locations`, `Levels`, `Alerts`, `StockHistory`), elenco *Movimenti* in sola lettura con filtri per tipo, causale, versione e periodo, guida sviluppatore e guida commerciante. Verificato nel browser sul sito di prova.
      - **Il core genera `DECIMAL(10,2)` per qualsiasi campo numerico** e ignora `decimals()`, che vale solo per i form (`Data\Fields\Number::sqlSchema()` ha `'length' => '10,2'` fisso): le quantità perdevano il terzo decimale. Le colonne del magazzino le dichiara `Support\Columns::decimal()`. **Da correggere nel core**: `gst_products.weight` e le altre colonne del catalogo nate da `sqlColumnsFromDataSchema()` hanno lo stesso difetto.
      - Confrontare un `DATETIME` con la stringa vuota fa fallire la query in MySQL strict mode: l'avviso aperto si cerca con `resolved_at IS NULL`.
      - Un articolo con movimenti **non si elimina più**: lo impedirebbe comunque la chiave esterna, ma con una pagina di errore. Il rifiuto usa `UserError::refusal()` (una `RuntimeException`) perché `api/backend/delete` del core intercetta quella, mentre il controller del form intercetta `InvalidArgumentException`. Il sito mostra ancora «Errore 802» al posto del messaggio: serve il rilascio del ramo `fix/backend-error-messages` di `wonder-image/lib` (già in elenco qui sopra). La risposta del server è corretta, 422 con il testo giusto.
      - `gestionale:demo --fresh` cancella la storia di magazzino dei suoi articoli prima di eliminarli (`StockHistory::purge()`).
      - `escape()` è salito in `GestionaleResource`: era copiato in due Resource e mancava nella terza, che caricava l'elenco con un 500.
    - [x] Piano 2 scritto ed eseguito (2026-09-22): `docs/superpowers/plans/2026-09-22-giacenze-e-rettifiche.md` — `Stocktake` puro (una casella vuota non è uno zero, uno zero scritto sì), pagina-form **Giacenze** con ricerca, filtro "solo sotto scorta", paginazione a 50 righe e salvataggio in blocco dentro una transazione, pagina-form **Rettifica** con causale e nota, colonna *Giacenza* nella griglia delle versioni, casella e link nella scheda a versione unica, riquadro *Magazzino* con gli ultimi dieci movimenti nella scheda della versione, giacenza iniziale nei dati di prova (una versione per articolo nasce sotto scorta). Verificato nel browser sul sito di prova.
      - **La rotta del salvataggio di una pagina-form non ha query string.** Filtri, pagina e versione viaggiano in campi nascosti: rileggerli da `$_GET` avrebbe salvato la prima pagina invece di quella aperta, e la Rettifica andava in 500 perché non sapeva più di quale versione parlasse.
      - **Il controller delle pagine-form non intercetta niente:** un `UserError` che vola via da `submitFormPage()` è una pagina 500. I rifiuti si catturano lì dentro e diventano `FlashAlert` più redirect.
      - `backUrlFrom()` accetta anche gli indirizzi assoluti di questo sito, perché è quello che tornano le rotte del core; rifiuta tutto il resto (redirect aperto).
      - Chiavi dello schema da ricordare per i test: le voci di un select stanno in `options`, le colonne di un repeater in `context.columns`, `readonly()` finisce dentro `attribute`, la larghezza in `columnSpan['default']`, la sezione del menu in `section_key`.
      - I campi numerici del backend mostrano il punto come separatore decimale e nessun separatore di migliaia (`20.000` sono venti pezzi): è la configurazione AutoNumeric del sito, uguale per i prezzi.
    - [x] Piano 3 scritto ed eseguito (2026-09-22): `docs/superpowers/plans/2026-09-22-anagrafiche.md` — `gst_contacts` e `gst_contact_addresses` con i dati di fatturazione di `AddressExtension::billing()` del core, `Support\Contacts\Contacts` (nome da mostrare, ruoli, duplicati), due elenchi *Clienti* e *Fornitori* sulla **stessa scheda** (`SupplierResource extends CustomerResource`), unicità di partita IVA/codice fiscale/email con il nome di chi li ha già, indirizzi di consegna come repeater, riquadro *Anagrafiche* nella home, quattro schede di prova. Verificato nel browser.
      - **La partita IVA si valida con il paese:** senza `country` nello stesso salvataggio il campo del core rifiuta tutto ("devi impostare countryField()"). Nel form il paese ha il valore predefinito `IT`, da codice va passato.
      - **Il campo email del core controlla anche il dominio:** `@qualcosa.example.com` inventato viene rifiutato, `@example.com` no. I dati di prova usano solo quello.
      - **`decorate()` non può girare nei comandi di `forge`:** compone l'indirizzo con le funzioni globali del sito, che lì non esistono. Ora torna la riga com'è invece di far esplodere `gestionale:demo`.
      - `Gestionale::features()` non esplode più senza database: fuori dal sito tutto risulta bloccato, che è la risposta che non mostra niente per sbaglio. Serviva perché le pagine che leggono una funzionalità sono le prime a girare nei test degli schemi.
      - Ordine dei controlli in `mutateRequestValues()`: il ruolo della lista si accende **dopo** aver tolto `is_supplier` bloccato, altrimenti su *Fornitori* si spegneva da solo.
      - **Ruolo come domanda sola (2026-09-23, commit `2e9c9cb`):** al posto delle due caselle «È un cliente» e «È anche un fornitore» c'è un campo unico *Ruolo* con tre risposte (`customer`, `supplier`, `both`), che nasce sul ruolo dell'elenco da cui si arriva. `Contacts::roleChoice()` e `Contacts::rolesFromChoice()` fanno la conversione, `mutateFormValues()` compone il campo e `mutateRequestValues()` lo riscompone e **toglie** `roles`, `is_customer`, `is_supplier` dai valori salvati (senza l'`unset` il framework riscriverebbe le colonne con il default e la scheda cambierebbe ruolo da sola). Con *Acquisti* bloccato il campo non si stampa affatto e i ruoli in archivio restano come sono; il controllo `contact.no_role` legge allora il ruolo dal database con `storedRole()`. La forzatura del ruolo dell'elenco in `store` vale solo se nessuno ha scelto altro, così da *Clienti* si può creare un fornitore. Verificato nel browser con *Acquisti* sbloccato (tre opzioni, passaggio a «Cliente e fornitore», scheda in entrambi gli elenchi) e poi ribloccato.
    - [x] Piano 4 scritto ed eseguito (2026-09-24): `docs/superpowers/plans/2026-09-23-avvisi-di-scorta-minima.md` — campo *Scorta minima* nelle schede dell'articolo e della versione (visibile con `low_stock_alerts`), righe di `gst_stock_alerts` aperte e chiuse da `Stock::apply()` con `LowStock::decide()`/`Alerts::refresh()`, *Destinatari degli avvisi* validati nelle impostazioni del commerciante, `Support\Mail\Mailer` con l'hook `beforeEmailSend` e `Recipients`, elenco dei prodotti sotto scorta e la sua email con vista sostituibile (`LowStockReport`, `LowStockEmail`), `LowStockNotifier` con **una email raggruppata** per giro, attività `gestionale.stock_alerts` ogni quarto d'ora **nata spenta** e comando d'anteprima `gestionale:stock-alerts`, riquadro *Sotto scorta* nella home, giacenze negative nel riquadro *Da controllare* (`NegativeStock`), guide del commerciante (`magazzino-avvisi`) e dello sviluppatore. Verificato nel browser sul sito di prova, tranne due punti: l'**email vera non è partita** (rimandata dall'utente; la coprono i test d'integrazione con il mailer finto) e le **impostazioni del negozio** si sono controllate da riga di comando, perché sul sito c'è solo `admin` (Wonder Image) e la pagina è del solo `administrator` (il commerciante).
      - **La chiave esterna degli avvisi**: eliminare un prodotto con l'avviso aperto andava in errore; `StockHistory::dropAlerts()` li toglie prima, con la stessa condizione di `purge()`.
      - **Avvisi orfani e avvisi vecchi**: prodotti eliminati o tolti dalla griglia, e prodotti tornati sopra la soglia senza movimenti; il giro dell'attività li chiude, l'anteprima no. Un errore di lettura dei prodotti ora sale invece di far sembrare orfani tutti gli avvisi.
      - **`sanitizeEcho()` del core rovina il corpo delle email** (toglie le barre rovesciate e decodifica le entità): `Mailer::shield()` lo protegge.
      - **I comandi di `forge` non hanno `sendMail()`**: `gestionale:stock-alerts` è solo un'anteprima; l'invio vero lo fa lo scheduler, che carica `wonder-image.php`.
      - **L'`ErrorReporter` del core riscrive a chi sviluppa a ogni occorrenza**: l'invio fallito si segnala con un messaggio fisso, così le ripetizioni si contano sulla stessa riga, ma un indirizzo rifiutato per sempre manda un'email tecnica ogni quarto d'ora finché qualcuno non corregge i destinatari (scritto nella guida sviluppatori).
      - **Il link della guida di `MerchantSettingResource` era rotto** (`impostazioni/negozio`): corretto, e `DocsPagesTest` ora controlla anche i link fra le pagine delle guide.
      - **Il `Mailer` risponde all'email del negozio**, non a un indirizzo vuoto. Nel core `mail.php` Brevo riceve ancora un `replyTo` vuoto quando `from` manca: **da correggere nel core** con `if (!empty($from)) { $BREVO->replyTo($from, $SOCIETY_NAME); }`.
      - **`FILTER_VALIDATE_EMAIL` rifiuta i domini internazionali e la forma «Nome <a@x.it>»**: i destinatari si scrivono come indirizzi semplici. Dal 2026-10-08 anche `merchant_notification_emails` e `developer_error_emails` si validano così, nelle Impostazioni di Set Up.
      - Giro di correzioni della revisione finale (2026-09-24): il link «Aggiungi i destinatari» del riquadro compare solo a chi può aprire le *Impostazioni*, gli altri leggono che li aggiunge il commerciante; una versione con movimenti non si elimina più nemmeno da codice (`ProductResource::assertDeletable()`); il ritorno `torna=` della Rettifica rifiuta barre rovesciate, spazi, caratteri di controllo e `//` anche dopo l'host (redirect aperto); «Azzera i filtri» nelle Giacenze azzera davvero; un hook che lascia solo indirizzi non validi viene segnalato fra gli errori, un hook che svuota i destinatari apposta no.
      - AutoNumeric mostra le giacenze negative come `5,000-` (segno in fondo): è la configurazione del sito, come per i prezzi.
      - La pagina delle impostazioni del negozio, aperta da chi non ha il permesso, porta al Login invece di un 403: comportamento del core.
  - [ ] **G2b-bis Giacenze, movimenti e documenti di magazzino** — revisione di G2b prima di G3; spec: `docs/superpowers/specs/2026-09-23-giacenze-movimenti-documenti-design.md`
    - [x] §1 Aggiunte al core: azioni-array, `TableLayoutSchema::select()`, filtri (approvata il 2026-09-23, rivista il 2026-09-24: tolta la pagina-form in popup)
    - [x] §2 Giacenze: elenco datatable in sola consultazione, una colonna per sede con più sedi, niente rettifica (approvata il 2026-09-23, rivista il 2026-09-24)
    - [x] §3 Movimenti: sede con più sedi, documento dentro *Tipo* e nel menu ⋯, *Chi*, *Prima*, filtro *Periodo*, ricerca sull'articolo (approvata il 2026-09-24)
    - [x] §4 Documenti di magazzino: Carico, Scarico, Inventario — dati, stati, movimenti e pagine (approvata il 2026-09-24)
    - [x] §5 Documentazione, test, piani: guide, dati di prova, test, validazione e sei piani, Giacenze e Movimenti subito dopo il core (approvata il 2026-09-24)
    - [x] Revisione della spec completa da parte dell'utente e commit (2026-09-25)
    - [x] Piano 1 Tabelle del core eseguito (2026-09-25, senza piano scritto): azioni-array, `TableLayoutSchema::select()`, `filterQuery()`, `FilterCustom` sicuro, ricerca annidata; nel `main` del core (`2d2fdc14`) e nel sito di prova
    - [x] Piano 2 Giacenze eseguito (2026-09-29): elenco del core su `Product` in sola consultazione, una colonna per sede con `Locations::shown()`, *Totale*, *Scorta minima*, *Impegnati* e *Disponibili* calcolati nella query (`LevelsSql`) e ordinabili, ricerca sul nome dell'articolo, filtri *Stato*, *Marchio*, *Categoria* e *Scorta*, menu ⋯ con *Movimenti* e *Apri la versione*, `lowStockUrl()` che apre il filtro *Scorta*; via la pagina-form con le caselle e il salvataggio in blocco
    - [x] Piano 3 Movimenti eseguito (2026-10-01, dentro il Piano 4 di G4): colonne *Chi*, *Prima*, *Sede*, documento dentro *Tipo* col link all'ordine, filtri *Causale*, *Sede* e *Periodo* (`MovementPeriod`), ricerca sull'articolo, *Ultimi movimenti* nella scheda della versione
    - [ ] **Rimandati dopo la consegna (D61)** i piani 4-6: carichi, scarichi, inventario e trasferimenti. Fino ad allora la merce entra ed esce con la rettifica rapida della scheda prodotto
    - [ ] Piano 4 Documenti, dati e servizio: tabelle, Model, regole, conferma, annullamento, duplica, inventario; documento e storni dentro *Tipo* nei Movimenti
    - [ ] Piano 5 Carichi e scarichi, pagine: menu, elenchi, bozza con i componenti, rotta JSON e indice `ean`, pagina in sola lettura, azioni, dati di prova
    - [ ] Piano 6 Inventario, pagine: Attesi · Contati · Differenza, *Aggiungi versioni*, limite di 500 righe, dati di prova; chiusura di G2b-bis
  - [ ] **G2c La scheda prodotto, secondo giro** — spec: `docs/superpowers/specs/2026-09-22-scheda-prodotto-secondo-giro-design.md` (sedici giri chiusi, decisioni P1–P128)
    - [x] Tredicesimo giro eseguito (spec §22, 2026-09-25, commit `0a04ef9`): fornitori dell'articolo, giacenza e scorta minima per sede (`gst_stock_thresholds`, via `gst_products.min_stock_quantity`), colonna «Scontato» nella griglia, personalizzazioni scritte in spec per G5. Architettura 4.3 aggiornata; G2b-bis §2 «la scorta minima è della versione» superata
    - [x] Quattordicesimo giro eseguito (spec §23, 2026-09-29, commit `7b55c80` e `5265522`): i fornitori tornano all'opzione in `gst_product_suppliers` (via `gst_product_model_suppliers` e `ProductModelSupplier`, decisioni P107–P115), due campi con un fornitore solo e bottone «Fornitori» con finestra a righe da due in su, «Salva per tutte le opzioni», fornitori copiati accendendo le varianti; con più sedi la griglia non ha la colonna *Giacenza*. Guide, CHANGELOG e test allineati; prova nel browser chiusa senza difetti
    - [x] Quindicesimo giro eseguito (spec §24, 2026-09-29, commit `a11de76`, decisioni P116–P122): «Dettagli delle opzioni» piccolo e in finestra (Opzione · SKU · Prezzo · Stato, con «Apri l'elenco completo» in fondo), scheda dell'opzione in due colonne con i codici a destra, rettifica del magazzino con tre azioni dentro la scheda
    - [x] Sedicesimo giro eseguito (spec §25, 2026-09-29, dal commit `896cba2` al `1f2cb6b`, decisioni P123–P128): la scheda dell'articolo si apre **in lettura** — il nome dell'elenco porta lì, «Modifica» (giallo e piccolo) apre il cantiere — con le *Opzioni in vendita* come tabella vera del core, stato PUBBLICATO/BOZZA che si cambia da lì e guida in riga col titolo; movimenti presi dallo schema dei *Movimenti*, colonne dell'elenco dei prodotti rifatte, attributo con foto proprie letto dall'articolo. La colonna di destra resta libera per le statistiche, che arrivano con G4
    - [ ] Diciassettesimo giro, se serve: da aprire dopo la prossima prova nel browser
**Percorso della consegna (D61)** — nell'ordine; quello che non serve al primo negozio resta spento.

- [x] G4 Ordini e pagamenti — [spec](docs/superpowers/specs/2026-09-29-ordini-e-pagamenti-design.md) scritta il 2026-09-29, cinque piani; **con dentro il Piano 3 di G2b-bis** (Movimenti: *Chi*, *Prima*, filtri, ricerca, riferimento al documento): la pagina si tocca una volta sola, quando gli ordini cominciano a scaricare. Il blocco grosso: prezzi, ordini e righe, stati, pagamenti, prenotazione e scarico da un unico servizio, resi
  - [x] Piano 1 **fatto** (2026-09-29): `docs/superpowers/plans/2026-09-29-tabelle-e-prezzi-degli-ordini.md` — gli otto Model più i tre log (undici tabelle), `LinePrice` e `OrderTotals` puri con i loro test, le due impostazioni della vendita, i prefissi dei codici. Sei task, ramo `feature/ordini-tabelle-e-prezzi`
  - [x] Piano 2 **fatto** (2026-09-30): `docs/superpowers/plans/2026-09-30-magazzino-delle-vendite-e-pagamenti.md` — `PaymentStatus` puro, `Allocation` (prenota, rilascia, scarica, annulla, reso) con `Stock::apply()` come unica porta, `Ledger` con lo stato dell'ordine ricalcolato e la notifica doppia respinta, più i due test a processi separati (ultimo pezzo conteso, incasso gemello). Sei task, ramo `feature/ordini-magazzino-e-pagamenti`. La revisione finale ha trovato due difetti di contemporaneità (l'ultimo pezzo conteso, la prenotazione liberata tutto-o-niente) e quattro sul denaro (riferimento del gateway riusato su un altro ordine e sul rimborso, importo non riconciliato, incasso dichiarabile fallito, gateway scritto storto preso per un incasso a mano): corretti tutti, con i loro test. L'indice unico di `gst_payments` ora comprende il `type`: **un'installazione che aggiorna deve rilanciare `Payment::createTable()`**
  - [x] Piano 3 **fatto** (2026-09-30): `docs/superpowers/plans/2026-09-30-carrello-checkout-e-ciclo-di-vita.md` — `Cart` (apri, aggiungi, cambia, togli, unisci, ricalcola) che non prenota niente, `PaymentTiming` con la colonna `timing` sui metodi di pagamento, le sei email su due viste con i testi nel file di lingua, `Lifecycle` come unico punto che scrive lo `status`, `Checkout` in una transazione sola (dati, commissione, ricalcolo, prenotazione, numero, pagamento, riepiloghi IVA), `Expiry` con `ExpiryTask` orario e `Allocation::expire()` per chiudere le prenotazioni scadute. Sette task, ramo `piano-3-carrello-checkout-ciclo-di-vita`. **Chi aggiorna deve rilanciare `PaymentMethod::createTable()`** per la colonna `timing`.
  - [x] Piano 4 **fatto** (2026-10-01): `docs/superpowers/plans/2026-10-01-backend-vendite.md` — elenco *Ordini* con le tre etichette, filtri e ricerca; scheda in sola lettura da sette riquadri; azioni *Conferma*, *Segna evaso*, *Annulla* (finestra che dice cosa succede al magazzino, `OrderActions`) e *Registra pagamento* (pagina-form, `torna=`) che chiamano `Lifecycle` e `Ledger`; *Metodi* e *Conti* in Set Up (`admin`) con tre metodi predefiniti; sette ordini di prova nel `DemoCommand` con pulizia che rimette in magazzino la merce; dentro il Piano 3 di G2b-bis. Otto task, ramo `piano-4-backend-vendite`. Nessuna tabella cambia.
  - [x] Piano 5 **fatto** (2026-10-01, prova nel browser fatta il 2026-10-01: trovato e corretto un 500 su *Registra reso*, vedi `REVISIONI-scheda-ordine.md`): Resi, guide e prova nel browser: `Returns`, la pagina di registrazione, le tre guide utente e la guida per sviluppatori; **Registra reso e la sua voce nel menu dell'ordine** (`OrderActions::available` ha già `returns` e `$features` nella firma)
- [x] G5 Multiprodotto e personalizzazione — componenti e campi compilati in vendita, sulle righe di G4
  - [x] [Spec](docs/superpowers/specs/2026-10-01-multiprodotto-e-personalizzazione-design.md) scritta e approvata il 2026-10-01. Due piani: 1 Personalizzazioni (solo `text`/`choice`), 2 Multiprodotto (righe figlie nel carrello, `OrderLines`, reso della confezione). G5 si ferma al motore: i campi in vetrina sono di E1b
  - [x] Piano 1 [Personalizzazioni](docs/superpowers/plans/2026-10-01-personalizzazioni.md) **fatto e unito in `main`** (2026-10-02): PR #7, #8 e #9 del gestionale più wonder-image/app#55 nel core; due giri di ritocchi dopo la prova nel browser (tipo Numero con decimali, sovrapprezzo scelto alla creazione e non modificabile per articolo, elenco con l'icona «usata»)
  - [x] Piano 2 [Multiprodotto](docs/superpowers/plans/2026-10-02-multiprodotto.md) **fatto e unito in `main`** (2026-10-02, PR #10): tabelle, `OrderLines`, `Bundles`, righe figlie in `Cart`, reso della confezione, scheda dell'articolo, blocchi/ordine/email, demo (tre cesti e quattro ordini) e guide. Prova nel browser fatta: cesto fisso, scheda dell'ordine con i componenti sotto la madre, reso con «rientra a magazzino» per componente (giacenze 19 → 20), eliminazione del multiprodotto
  - [x] Ritocchi dopo la prova (2026-10-02, branch `piano-2-ritocchi`): scheda in lettura col nome come titolo e senza bottoni di aggiunta nelle opzioni; per i multiprodotti badge e riquadro «Composizione» (tipologia, componenti, gruppi di scelta); elenco con filtri (stato, marchio, categoria, tipo) e stato Pubblicato/Bozza (PR #11)
- [~] G6 ridotto — sconto sulla riga, coupon e sconto massivo; **listini cliente rimandati** (B2B, D61)
  - [x] [Spec](docs/superpowers/specs/2026-10-05-sconti-e-coupon-design.md) approvata il 2026-10-05, due piani. Decisioni: sconto di riga solo motore e prova (l'inserimento da schermata è G9); coupon a percentuale, importo e spedizione gratuita (il buono a scalare resta fuori dal modulo); un selettore dei prodotti solo (tutto, categorie con sottocategorie, tag, marchi, articoli, meno gli esclusi) usato da campagne e coupon; rifiniture di §4.6 tutte dentro; utilizzi del coupon contati **alla creazione dell'ordine**, non alla conferma
  - [x] Piano 1 [Campagne di sconto](docs/superpowers/plans/2026-10-05-campagne-di-sconto.md) **fatto** (2026-10-05, ramo `g6-sconti-e-coupon`): cinque tabelle, `ScopeMatcher` e `CampaignPrice` puri, `Campaigns` come unica porta, prezzo di campagna in `Cart::recalculate`, pagina *Promozioni → Campagne di sconto* con anteprima e avviso di sovrapposizione, tre campagne di prova, guide. **Chi aggiorna deve lanciare `php forge update`.** La prova nel browser (Task 7) aspetta l'utente
  - [x] Piano 2 [Coupon](docs/superpowers/plans/2026-10-05-coupon.md) **fatto** (2026-10-05, ramo `g6-sconti-e-coupon`): tabelle, `CouponRules` pura, `Coupons` come unica porta, ricontrollo a ogni ricalcolo del carrello, utilizzi presi in `Checkout::place` e rilasciati all'annullo/scadenza (anche con due processi), pagina *Promozioni → Coupon* con tabella degli utilizzi, coupon sulle schede ordine e cliente, cinque coupon e tre ordini di prova, guide. **Chi aggiorna deve lanciare `php forge update`.** La prova nel browser (Task 10, e Task 7 del piano 1) aspetta l'utente; sconto sulla riga da schermata = G9, listini cliente rimandati; il coupon in vetrina e nel checkout è di E1b/E1c
  - [x] Ritocchi richiesti dopo i due piani (2026-10-05): coupon e campagne si aprono in una **scheda di lettura** (utilizzi, anteprima e dettagli fuori dal form); **canali di vendita** (`online_sales`, `office_sales`, `pos` — questa `created => false`) accesi dal pannello Funzionalità, «Dove vale» nei form solo con più di un canale acceso (`Support\Sales\Channels`). Prova nel browser ancora da fare
- [x] G7 Spedizioni — metodi, zone e tariffe, tracking a mano, ritiro in sede
  - [x] [Spec](docs/superpowers/specs/2026-10-05-spedizioni-design.md) approvata il 2026-10-05
  - [x] Piano 1 [Listini e calcolo](docs/superpowers/plans/2026-10-05-listini-di-spedizione.md) **fatto** (2026-10-05, ramo `g7-spedizioni`): corrieri, zone, metodi e listini, `Shipping` come unica porta, riga di spedizione in `Cart::recalculate`, `cod_fee` del contrassegno, tre pagine in *Spedizioni*, dati di prova e guide. **Chi aggiorna deve lanciare `php forge update`.** La prova nel browser (Task 8) aspetta l'utente
  - [x] Piano 2 [Spedizioni e ritiro](docs/superpowers/plans/2026-10-05-spedizioni-e-ritiro.md) **fatto e unito su main con la PR #18** (2026-10-06, ramo eliminato): tabelle e Model, `Shipments` come unica porta (quantità assegnabili con blocco `FOR UPDATE`, spedizioni parziali, tracking, ritiro in sede), evasione dell'ordine ricavata dalle quantità, email di spedito e pronto per il ritiro, pagina *Spedizioni → Spedizioni*, azioni dalla scheda ordine, due riquadri nella bacheca, spedizioni di prova (`ShipmentsDemo`), guide. **Chi aggiorna deve lanciare `php forge update`.** La prova nel browser (Task 8 di entrambi i piani) aspetta l'utente; il termine dei resi non esiste ancora nel codice (`shipped_at` è già il dato da usare); il ritiro di prova non c'è (serve una sede di ritiro aperta nelle impostazioni del sito)
    - [x] Ritocchi dopo la prima occhiata (2026-10-06): listino a prezzo fisso o a scaglioni; corriere, tracking e stato modificabili dopo (`Shipments::update`, azione *Modifica spedizione*, voci dai tre puntini); elenco cercabile per numero d'ordine; Metodi/Zone/Corrieri nel *Set-up* e *Spedizioni* nelle *Vendite*; sei corrieri di serie col solo link di tracking
    - [x] Ritocchi alle schede (2026-10-06, PR #18): il menu *Spedizioni* nel *Set-up*; scheda del metodo con un'unica card «Zone» (+ Aggiungi zona e Nuova zona… dentro, «Togli zona» con conferma, zona nuova a prezzo fisso, niente codice del servizio nel modulo — la colonna `provider_service_code` resta per le API dei corrieri); pagamenti con tipi bonifico/contanti, codici automatici, commissione fissa + percentuale e intestatario del conto. Prova nel browser della scheda del metodo ancora da fare
    - [ ] Corrieri: sito, logo e dati API (DHL: `api_key`, `api_secret`, server e server di prova, `/shipments`) rimandati — servono colonne nuove in `gst_carriers` quando si collegherà il tracking via API; per ora il link `{tracking}` basta
- [ ] E1 Negozio online — vetrina, carrello, checkout con Stripe, area cliente, email, condizioni di vendita, SEO. Pacchetto nuovo `wonder-image/ecommerce`, diviso in tre fette; il dettaglio dei compiti sta in [`packages/ecommerce/TODO.md`](../ecommerce/TODO.md), che è lo stato del lavoro del modulo
  - [~] E1a Guscio — [spec](docs/superpowers/specs/2026-09-29-negozio-online-guscio-design.md) approvata il 2026-09-29, tre piani: pacchetto e collegamento a `ecommerce-site` (**fatto**: repository privato `wonder-image/ecommerce`, due moduli validi sul sito di prova), i tre layout sottili con le view sigillate e gli slot, account dei clienti sul permesso `frontend.client` del core. **Non dipende da G4: aperto ora, in parallelo**
  - [ ] E1b Vetrina, catalogo pubblico e scheda prodotto — legge G2, apribile senza G4
  - [ ] E1c Carrello, checkout con Stripe, ordini e resi nell'area cliente, email, condizioni di vendita — **dopo G4**
    - [x] D5 checkout con spedizione, ritiro e coupon — [spec](docs/superpowers/specs/2026-10-06-checkout-con-spedizione-design.md); piano 1 gestionale ([piano](docs/superpowers/plans/2026-10-06-anteprima-del-checkout.md)) fatto: `Checkout::preview`, controlli di `place`, `PickupPoints`; piano 2 ecommerce ([piano](docs/superpowers/plans/2026-10-06-pagina-del-checkout.md)) fatto: rotte `summary` e `coupon`, `CheckoutForm`, `CheckoutSummary`, pagina a due colonne, `checkout.js`. **Resta la prova nel browser dell'utente su `ecommerce.test`**: spedizione in Italia e nelle isole, zona non coperta, ritiro, coupon applicato e tolto, bonifico e contrassegno
    - [~] D6 pagamento con Stripe — [spec](docs/superpowers/specs/2026-10-09-pagamento-stripe-design.md); **piano 1 fatto** ([piano](docs/superpowers/plans/2026-10-09-pagamento-stripe-piano-1.md), 2026-10-09): Payment Element, ritorno e webhook, riapertura del modulo dopo un rifiuto (`Cart::restore`), link all'ordine nelle email, stripe-php ^19.1 con la versione API fissata. **Piano 2a fatto** ([piano](docs/superpowers/plans/2026-10-09-pagamento-stripe-piano-2a.md), 2026-10-09, ramo `stripe-express`): i metodi accesi nel conto Stripe (Klarna, PayPal, Satispay…) compaiono da soli sotto «Carta di credito», ognuno con la sua voce e la sua icona; la carta mostra solo i circuiti; Link, Apple Pay e Google Pay vanno nella barra rapida. Provato nel browser fino al pagamento con Klarna. Resta il **piano 2b**: checkout rapido in `#rapido` (Apple Pay, Google Pay, Link), poi carrello e scheda prodotto. Al primo collaudo vanno accesi `ExpiryTask` e `StripeReconcileTask`, che nascono spenti. I minori della revisione finale sono corretti. Uniti in `main` **in locale** nei tre repository, **non ancora spinti**.
    - [ ] Checkout a passi (Carrello, Spedizione, Pagamento) e acquisto da ospite — [spec](docs/superpowers/specs/2026-10-06-checkout-a-passi-design.md)
      - [x] Piano 1 [componenti](docs/superpowers/plans/2026-10-06-checkout-a-passi-1-componenti.md) **fatto** (2026-10-06): in lib `.wi-choice`, `.wi-steps`, `.wi-thumb`; in app `Choice`, `ChoiceGroup`, `Steps` nei due temi. Rami `checkout-a-passi-componenti` in lib e app, **non ancora spinti né uniti**. Otto minor rimandati (accessibilità di `Steps`, stato «scelto» e `hidden` su `d-block` nel tema Bootstrap): elenco nella risposta finale della sessione
      - [ ] Piano 2 Passi (pagine Carrello, Spedizione, Pagamento; rilascio della lib ed `extra.wonder.lib`)
      - [ ] Piano 3 Ospite (account creato senza password, ordine su un account esistente)
    - [ ] Checkout a pagina unica (sostituisce i tre passi) — [spec](docs/superpowers/specs/2026-10-07-checkout-pagina-unica-design.md)
      - [x] Piano 1 [componenti e font](docs/superpowers/plans/2026-10-07-checkout-pagina-unica-1-componenti.md) **fatto** (2026-10-07): `Choice` con icona, loghi, pannello selezionabile e varianti segmented/list; `Wonder\View\WebFonts` con 9 font in `resources/assets/font/web/`. Uniti in `main` **in locale** in app (merge 256859e0) e lib (9b0364e), **non ancora spinti**; `dist` della lib da rilasciare. Per il piano 2: `hidden` su un intero `.wi-choice` non lo nasconde, togliere o clonare le scelte. Minor rimandati: varianti Bootstrap non unite e pannello sempre visibile, ultimo segmento 1px corto, spazio sopra il pannello
      - [x] Piano 2 [pagina unica](docs/superpowers/plans/2026-10-07-checkout-pagina-unica-2-pagina.md) **fatto** (2026-10-07) in gestionale ed ecommerce, unito su `main` (gestionale#20, ecommerce#4). **Resta la prova nel browser dell'utente** su `ecommerce.test/checkout/` (serve l'accesso): spedizione e ritiro, pannello del pagamento, errori sotto i campi, invio di un ordine
      - [x] Piano 3 [ospite](docs/superpowers/plans/2026-10-07-checkout-pagina-unica-3-ospite.md) **fatto** (2026-10-07) in app, gestionale ed ecommerce, unito su `main` in locale (2026-10-08), **non ancora spinto**: `GuestCheckout` trova o crea l'account dall'email (senza password), collega contatto e consensi; l'email dell'ordine porta il link «Scegli la password» (7 giorni), che verifica anche l'email. L'ospite si accende con «Ordini senza account» nelle impostazioni di Set Up. **Resta la prova nel browser dell'utente da ospite** (senza accesso)
- [x] Elenco dei prodotti e scheda in lettura (2026-10-08, ramo `lista-disponibilita`): colonna «D.tà» con `StockBadge` (rosso ≤ 0, giallo sotto la scorta minima con gli avvisi accesi, verde; un multiprodotto conta le confezioni, «∞» se niente limita), icona Prodotto/Multiprodotto prima della foto (solo con `bundles`), SKU sotto il nome al posto della colonna, «Vedi sul sito» nei tre puntini per gli articoli in vetrina; nella scheda la D.tà di ogni opzione e di ogni pezzo della composizione
- [x] Slug del catalogo: `/prodotto/{modello}/`, `/prodotto/{modello}/{variante}/`, `?attributo=valore` per l'opzione; slug della variante dal solo valore (oggi `Generator.php:79` lo fa `nome-id-valore`), un solo costruttore di URL nell'ecommerce. Spec approvata: `docs/superpowers/specs/2026-10-08-slug-catalogo-design.md`; piano: `docs/superpowers/plans/2026-10-08-slug-catalogo.md` (unito su main in locale in gestionale ed ecommerce il 2026-10-08, non spinto; gli slug vecchi si rifanno tutti per posizione; sul sito lanciare `php forge gestionale:variant-slugs`). Foto con il nome di chi le porta: `{modello}-{rand}`, `{modello}-{variante}-{rand}`, `{modello}-{variante}-{opzione}-{rand}`
- [x] Pannello account del cliente nel core — [spec](docs/superpowers/specs/2026-10-08-pannello-account-design.md): il pannello passa dall'ecommerce al core (`AccountRoutes`, `AccountExtension`), con URL italiani; i moduli vi aggiungono le loro sezioni. Due piani, **uniti su `main`** il 2026-10-09 (lib#7, app#69, app#70, gestionale#22, ecommerce#5, ecommerce#6); rami, worktree e sito di prova `ecommerce-site-account` eliminati
  - [x] Piano 1 [core](docs/superpowers/plans/2026-10-08-pannello-account-1-core.md) **fatto** (2026-10-08, ramo `pannello-account` in app, lib, ecommerce e gestionale, unito il 2026-10-09): Panoramica, Dati personali (data di nascita, cambio email con link di conferma, password in un modal), Indirizzi a schede con modal, Fatturazione; `EcommerceAccountExtension` con i metodi di pagamento; vecchio pannello dell'ecommerce rimosso. **Chi aggiorna deve lanciare `php forge update`** (colonna `birth_date` sul contatto) e rinominare nei menu del sito `ecommerce.account.*` in `account.*` (migrazione nel CHANGELOG dell'ecommerce). La prova nel browser (C0d dell'ecommerce) si fa dopo il piano 2
  - [x] Piano 2 [Ordini e coupon](docs/superpowers/plans/2026-10-08-pannello-account-2-ordini-coupon.md) **fatto** (2026-10-08, ramo `pannello-account`, unito il 2026-10-09): Ordini con paginazione, dettaglio dell'ordine e Coupon (solo con la funzionalità accesa) come sezioni di `EcommerceAccountExtension`; `Coupons::reserved()` conta gli usi in un posto solo (backend e pannello); C4 dell'ecommerce chiuso senza i resi. Prova nel browser **fatta** (2026-10-09) su `ecommerce-account.test` a 1280, 768 e 386 px, con le correzioni al CSS legacy `section div {float:left}` del sito (lib, app, ecommerce)
  - [x] **Fatto** il 2026-10-09: lib `2.1.2-alpha.24` rilasciata, vincoli alzati con app#70, `CatalogSearchHttpTest` con ecommerce#6. Prima di unire il ramo `pannello-account` (piani 1 e 2): costruire e rilasciare il `dist` della lib con tutto il CSS e JS del pannello dopo `v2.1.2-alpha.23` (menu laterale `side-nav` CSS e JS, schede indirizzo `address-card` CSS, JS dei modal che il server manda già aperti, righe a tabella con piede e paginazione `wi-row-table__foot` e `wi-pagination`) e alzare `extra.wonder.lib` in `app/composer.json` (oggi `^2.1.2-alpha.23`) insieme a `wonder-image` in `app/package.json` (`LibConstraintTest` li vuole uguali); senza, i pannelli dei due piani e il CHANGELOG dell'ecommerce descrivono CSS e JS che il sito non ha ancora. Ordine del merge: lib → rilascio → aumento delle versioni in app → app → gestionale → ecommerce. Prima di unire l'ecommerce va unito il suo `main` nel ramo (il merge è pulito, nella sessione del piano 2 non è stato fatto); dopo, `tests/integrazione/CatalogSearchHttpTest.php:67` (dal `main`) ha l'URL `https://ecommerce.test` fisso e fallisce con `WI_TEST_URL` del sito parallelo
  - [ ] Prima di aggiornare un sito: cercare nel suo `custom/` `ecommerce.account.`, le vecchie chiavi di `account.navigation` e `EcommerceAccountPanel`
  - [ ] Seguiti del pannello account, non bloccanti (review finale 2026-10-09):
    - decidere se il salvataggio dei Dati personali può riscrivere nome e cognome della fatturazione: `ContactAccount::link` (`app/class/Auth/Frontend/ContactAccount.php:27-34`) li copia dall'utente a ogni salvataggio, e un telefono svuotato non svuota quello del contatto (`:36`); il comportamento c'era già prima;
    - gli altri cambi di password (modifica dell'utente nel backend, reset fatto dall'amministratore) non revocano i link di cambio email ancora aperti, come fanno invece il pannello e il reset del cliente;
    - controllare i chiamanti di `\unique()` del core che gli passano input grezzo;
    - `Coupons::reserved()` fa 2N+1 query e pagina in memoria (`src/Support/Promotions/Coupons.php`): va bene per pochi coupon a cliente, se crescono `id IN (...)`;
    - EUR fisso nel valore dei coupon (`ecommerce/src/Frontend/Account/AccountCoupons.php:38`), mentre gli ordini usano la valuta dell'ordine;
    - alzare i vincoli dell'ecommerce (`wonder-image/app`, `wonder-image/gestionale` in `composer.json`, `frameworkCompatibility` in `module.json`) al prossimo rilascio di app e gestionale;
    - campo «Prefisso» del telefono tagliato nel componente del core;
    - `ExpiryTest` («a metà strada parte il promemoria») conta tutti gli ordini in attesa del sito: va ristretto all'ordine che crea;
    - la pagina Coupon mostra anche i coupon validi solo in negozio (`applies_online=false`) e i `store_credit`: è voluto (sono coupon del cliente), da cambiare se si vogliono solo quelli usabili online
  - [ ] `boilerplates/ecommerce-site/custom/config/navigation.php:92` usa ancora `'route' => 'ecommerce.account.index'`: va rinominato in `account.index`. Ogni sito già creato va rinominato allo stesso modo, come dice la migrazione nel CHANGELOG dell'ecommerce (una voce che punta a `ecommerce.account.index` passa a `account.index`); era rinominato solo il sito di prova `ecommerce-site-account`, eliminato il 2026-10-09. Il vecchio nome non ha alias (non si risolve più). Non l'ha toccato la sessione del piano 2: il boilerplate è di un'altra sessione
- [ ] Rilascio `1.0.0` di gestionale ed ecommerce
- [ ] Sito del cliente sopra `boilerplates/ecommerce-site`: tema, contenuti, prodotti veri, Stripe in produzione, collaudo

**Fuori dal percorso della consegna (D61)**

- [ ] G3 Magazzino avanzato — più sedi e trasferimenti, lotti e scadenze, giacenza e costi per fornitore, valore del magazzino
- [ ] G8 Fatturazione elettronica — il checkout è già previsto con la fatturazione spenta (§8.6); serve se il negozio fattura a partite IVA o su richiesta

**Dopo il primo rilascio**

- [ ] Starter da `boilerplates/ecommerce-site` (D12)
- [ ] E2 PayPal e Nexi
- [ ] G9 Vendite da ufficio
- [ ] G10 + E3 Abbonamenti
- [ ] E4 Portale B2B con account creato dall'azienda cliente (D31)
- [ ] `AuthFederated` e accesso al sito con Apple e Google (D46)

### Debiti tecnici del gestionale

- [ ] Dati di prova: `CatalogDemo::model()` e `CatalogDemo::attribute()` non rimettono al suo posto una riga cancellata dal backend, come fanno ora `ensure()` e `ContactsDemo::contact()` con `DemoCode::revive()`. Il loro ripristino tocca anche le righe figlie (versioni, immagini, valori dell'attributo), che restano cancellate: va deciso se rimetterle in vita insieme alla scheda o rifarle

- [ ] Finestre e campi scritti a mano invece dei componenti del core. `ProductResource::stockAdjustModal()` e `stockAdjustScript()` compongono la finestra della rettifica con HTML in stringa — `<div class="modal">`, `<input>`, `<button>` — al posto di `Modal`, `FormField` e `Button`. Il blocco è che la finestra ha una **form propria** (posta su `StockAdjustmentResource::submitUrl()`) e il `Modal` del core non ammette un `<form>` dentro: va prima aggiunto al core un modo di fare finestre con form, poi riscritte qui. Stesso debito, in piccolo, nei quattro widget della dashboard e in altri punti di `ProductModelResource`

- [ ] Prezzi all'inglese nelle tabelle del core. `Backend\Table\Field` rende il tipo `price` con `number_format($v, 2, '.', '').'€'`: nella scheda dell'articolo la stessa pagina scrive «da 9,90 €» nel riquadro «Prodotto» (lo compone il modulo) e «9.90€» nella tabella delle opzioni (lo compone il core). Va deciso se il formato lo prende dalla lingua del sito o da un'impostazione, e sistemato nel core: riguarda tutte le tabelle, non solo il gestionale

### Lavori preparatori in `wonder-image/app` (D58)

- [x] `Data\Fields\Number::sqlSchema()` torna `'length' => '10,2'` fisso e ignora `decimals()`: ogni colonna nata da `sqlColumnsFromDataSchema()` perde i decimali oltre il secondo (le quantità del magazzino, `gst_products.weight`). Nel modulo c'è il ripiego `Support\Columns::decimal()`
- [x] `app/function/mail.php` riga 158: Brevo riceve `->replyTo($from, ...)` anche quando `$from` è vuoto — il ramo PHPMailer (riga 208) la guardia ce l'ha già. Le email senza mittente sono quelle dello scheduler e dei comandi

- [x] Credenziali PayPal e Nexi in `Credentials::api()`, tabella `security` e pagina backend delle credenziali (D14)
- [x] Classe delle aliquote IVA italiane accanto a `Custom\Fattura\Valori\Natura` (D21)
- [x] EAN-13 ed EAN-8 in `createBarcode()`, insieme alle etichette (funzionalità futura, D30, D59)
- [x] Nexi: alias e chiave MAC nella classe `Nexi`, oltre all'API key (D50)
- [x] Opzione `docs()` nel `PageSchema` per il pulsante "Guida" del backend (D32)
- [ ] Dopo il primo rilascio: completare l'accesso con Google e Apple (`AuthFederated`), oggi non funzionante (D31, D58)
- [x] Sync con `id` stabili: opzione di `SyncSchema` che esporta `id` e `deleted`, import che inserisce o aggiorna per `id` senza `TRUNCATE`, righe assenti segnate come cancellate (D54)
- [x] Pagine delle tabelle sincronizzate in sola lettura in produzione, con avviso (D54)
- [x] `forge update` in locale: classe `Defaults` di ogni modulo abilitato, in ordine di dipendenza, poi aggiornamento di `shared/sync-data.json` (D54)
- [x] Opzione che permette a un modulo di rendere in sola lettura una Resource del core, es. indirizzo e orari della società (D54)
- [x] Helper `transaction(fn)` per le transazioni, sul modello di `ConsentService` (D56)

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
