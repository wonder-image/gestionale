# Changelog

Il formato segue [Keep a Changelog](https://keepachangelog.com/it/1.1.0/) e il
versionamento semantico.

## 0.1.0 — non rilasciata

### Aggiunto

- **Selezioni e ordine nel sito** (con *Vendita online*): in Catalogo →
  Selezioni gli elenchi scelti a mano per i blocchi del sito («In evidenza»
  della home, il prodotto del mese), con titolo, sottotitolo, testo e immagine
  del blocco e una regola d'ordine (`manual`, `newest`, `name`, `price_asc`,
  `price_desc`). Categorie e tag hanno il riquadro «Ordine nel sito»: gli
  articoli «in evidenza» da mettere in cima e la regola per gli altri
  (`sort_mode`, di serie «Come il catalogo»); le impostazioni hanno
  `catalog_sort`, l'ordine di tutto il catalogo (di serie `position`, quello di
  prima). Ogni riga è un articolo o una sola variante, con una foto di contesto
  facoltativa. Tabelle `gst_product_selections` e `gst_product_placements`
  (`context_type` + `context_id`); regole e chiavi in
  `Support\Catalog\Placements`, il repeater in `Support\Catalog\PlacementsForm`.
  L'ordine lo applica il sito (ecommerce). Passo a mano: `php forge update`.
- **FAQ**: in Catalogo → FAQ domande e risposte da mostrare nella scheda dei
  prodotti scelti (tutti, oppure categorie con le sottocategorie, tag, marchi
  e articoli, con gli esclusi) e, con «Mostra nella home», nella home. Tabelle
  `gst_faqs` e i ponti `gst_faq_categories`, `gst_faq_tags`, `gst_faq_brands`,
  `gst_faq_product_models`; il selettore è quello di campagne e coupon
  (`ProductScope` owner `faq`). Per il frontend `Support\Catalog\Faqs::forModel()`
  e `Faqs::home()`. Passo a mano: `php forge update`.
- **Scheda prodotto essenziale**: nel pannello Funzionalità la card nuova
  «Scheda prodotto» con *Articoli suggeriti* (`related_suggested`), *Articoli
  alternativi* (`related_alternative`), *Varianti* (`variants`), *Misure*
  (`measures`) e *Aliquote* (`tax_categories`); *Imballaggi* (`packages`) va in
  «Spedizioni» e richiede `shipping`. Spente, la scheda non mostra i loro campi:
  senza *Misure* le spedizioni, il carrello e i feed contano il peso come non
  inserito (`Support\Catalog\Measures::of()`), senza *Aliquote* vale il tipo
  fiscale predefinito, senza *Varianti* un articolo nuovo ha una versione sola
  (quelli salvati in più versioni restano). *Varianti* non si spegne finché un
  articolo ha più versioni (`feature.variants_in_use`). I due elenchi di
  articoli collegati si compilano con la tabella di «Nuovo carico» (ricerca,
  righe da trascinare) in `Support\Catalog\RelatedProductsEditor`; MCP:
  `product_set_related` risponde `feature_off` sul tipo spento.
  Nei siti esistenti le funzionalità nuove nascono spente: vanno accese dal
  pannello.

- **Paese di riserva per la spedizione del carrello**: `Shipping::options($cartId,
  $country)` usa `$country` finché il carrello non ha un indirizzo (né di
  consegna né di fatturazione), al posto di `IT`; con l'indirizzo vale quello.
  Nuovo `Shipping::country($cart, $fallback)`: il paese con la stessa regola.
  Serve al carrello del sito per mostrare la spedizione del paese del
  visitatore. Senza `$country` non cambia niente.

- **Articoli suggeriti e alternativi** (#74, #75): nella scheda articolo il
  riquadro «Articoli collegati» con due liste ordinabili, «Suggeriti» (da
  abbinare) e «Alternativi» (al posto suo), scelte a mano da chi vende.
  Tabella `gst_product_relations` (`Models\Catalog\ProductRelation`), senza
  collegamenti a sé stesso né doppioni. `Support\Catalog\RelatedProducts`
  dà al sito solo gli articoli visibili, online e con una versione attiva
  (`forModel()`, `suggestedFor()` per il carrello). MCP: `product_get` mostra
  `model.related`, nuovo strumento `product_set_related`.
  Passo a mano: `php forge update` (tabella `gst_product_relations`).

- **Esigibilità IVA per cliente** (gestionale#81): campo `vat_collectability`
  (I, D, S) sulla scheda cliente; vuoto vale quello di Set Up. Alla vendita si
  copia sull'ordine (`Support\Tax\VatCollectability`) e da lì va ai dati dei
  documenti e alla fattura (`toInvoice()` → `EsigibilitaIVA`). Con la scissione
  dei pagamenti (S) si incassa il totale meno l'IVA: rate, scadenze, registro
  dei pagamenti, pagamenti online e saldo dell'ordine usano
  `VatCollectability::payable()`; il PDF stampa «Da pagare (scissione)».
  Passo a mano: `php forge update` (colonne `vat_collectability` su contatti e
  ordini).

- **Attributi e Varianti** (#85, #83): nel catalogo due voci al posto di
  opzioni e caratteristiche, e il campo «Uso» sparisce. Nuovi tipi di
  attributo Url, File e Box testo. Nella scheda articolo l'interruttore
  «Una pagina per ogni valore» si accende su una sola variante: acceso, i
  valori stanno in `gst_product_variant_attributes` e ogni valore ha la sua
  pagina; spento, stanno in `gst_product_attributes`. Le foto valgono per
  tutte le varianti. Cambiare l'interruttore sposta le versioni fra le
  tabelle (`Support\Catalog\PageAxis`); le pagine tolte vanno nel cestino
  con il loro slug, così il negozio fa il 301 nei due versi
  (`Support\Catalog\PageRedirect`). Su un articolo online la scheda chiede
  conferma prima di salvare, e feed e sitemap si rifanno al giro dopo dello
  scheduler (`Support\Catalog\CatalogRefresh`, `ProductFeeds::expire()`).
  Gli strumenti MCP del catalogo scelgono la pagina per articolo.

- **Listini cliente** (funzionalità `customer_price_lists`, #78): Model
  `Pricing\PriceList` (`gst_price_lists`) e `Pricing\PriceListItem`
  (scaglioni per prodotto e quantità minima), Resource in Promozioni →
  Listini con import/export xlsx e csv (l'import sostituisce le righe).
  Il listino si sceglie nella scheda cliente (`price_list_id`) ed è
  memorizzato sull'ordine; `LinePrice` lo applica con lo scaglione giusto
  (fonte `price_list`, prima della campagna) e torna al catalogo per i
  prodotti fuori listino o col listino scaduto o spento. Le bozze da ufficio
  nascono col listino del cliente e lo possono cambiare; il carrello online
  segue quello del cliente loggato; gli ordini confermati non cambiano. Le
  colonne le crea `forge update`.

- **Funzionalità «Server MCP»** (`mcp`, area Integrazioni): il server MCP si
  accende e si spegne dal pannello Funzionalità. Spenta, `/api/gestionale/mcp/`
  risponde 404, `Server` non elenca né esegue strumenti e la risorsa OAuth non
  viene annunciata.

- **Limiti di tentativi** (`Support\Throttle`): la primitiva dell'ecommerce,
  con la stessa API, ora anche nel gestionale; l'ecommerce passerà a usarla. `attempt()` conta e decide in un colpo solo con un
  UPSERT atomico a finestra fissa sulla tabella `gst_throttles` (Model
  `System\Throttle`); se la tabella non risponde il tentativo passa e
  l'errore va nel registro con l'azione `gestionale.throttle`. `allowed()` e
  `hit()` restano su file in `storage/gestionale/throttle`. Le chiavi sono lo
  sha256 di «gruppo|chiave», come prima. La tabella la crea `forge update`.

- **Azienda negli indirizzi**: campo facoltativo «Ragione sociale / Azienda»
  negli indirizzi dei contatti (`business_name`) e nella spedizione di ordini
  e DDT (`shipping_business_name`); il PDF lo stampa come prima riga
  dell'indirizzo di spedizione.

- **Costi dei carichi IVA esclusa o inclusa**: impostazione
  `stock_prices_include_tax` (di serie esclusa) e scelta per documento
  (`prices_include_tax`). Con IVA inclusa il costo si scorpora con
  l'aliquota dell'articolo (`PurchaseTax`), fissata sulla riga
  (`tax_rate`) accanto al costo scritto (`entered_cost`); `unit_cost`,
  movimenti e costo del fornitore restano sempre netti.

- **Aliquota IVA d'acquisto per riga** nei carichi: colonna «IVA %» con
  un menu delle aliquote visibili (`PurchaseTax::choices()`, con la natura
  per quelle a 0%), di serie quella di vendita dell'articolo. Serve quando
  acquisto e vendita hanno IVA diverse (es. forfettario che compra da una
  srl). La riga salva l'aliquota scelta (`tax_id`) e il suo valore
  (`tax_rate`) anche con IVA esclusa; entrambi passano nelle copie e la
  stampa riporta l'etichetta (es. «IVA 0% N2.1»).

- **Stato dalle liste**: nei 3 puntini di aliquote IVA e tipi fiscali
  (Nascondi/Mostra), metodi, conti e condizioni di pagamento e corrieri
  (Disattiva/Attiva) e imballaggi (Ferma/Rimetti in uso).

- **Stato di regole IVA e zone di spedizione** (`active`, di serie
  attiva), con la voce Disattiva/Attiva nei 3 puntini. Una regola
  disattiva non si applica (`TaxResolver` passa all'aliquota di ripiego);
  una zona disattiva non copre nessuna destinazione e non conta negli
  avvisi di aree doppie, ma tiene aree e listini. Nella scheda del metodo
  di spedizione il suo listino porta «(zona non attiva)».

- **Server MCP** su `/api/gestionale/mcp/` (Streamable HTTP, solo POST,
  JSON-RPC): un agente IA (Claude Code, Claude Desktop…) elenca, crea e
  modifica prodotti, prezzi, giacenze e categorie e cambia lo stato degli
  ordini. `product_create` crea articolo, opzione e versione come la scheda
  del backend (obbligatori nome e prezzo; unità, IVA e visibilità di serie);
  `products_list` elenca le versioni a pagine, per categoria e attive.
  Token di `api_users` (stesse verifiche di `Endpoint.php`), strumenti
  filtrati con i permessi delle Resource, ogni scrittura registrata in
  `gst_mcp_logs` (`McpLog`) con prima e dopo. Vedi
  `docs/dev/concetti/mcp.md`.

- **Esporta** in clienti e fornitori: Excel (.xlsx) e CSV con le colonne di
  `ContactExport` (wonder-image/customer-account) e celle in testo, dietro le
  funzionalità `export_contacts_xlsx` / `export_contacts_csv` dell'area
  Esportazioni (`Support\Contacts\ContactExports`).

- **Esporta** nella tabella prodotti: scarica tutti gli articoli non
  cancellati in Excel (.xlsx) o in .csv, una riga per opzione in vendita, con
  codice articolo, SKU, EAN, prezzi, disponibili, stati, categorie, link e
  foto. Passa dalla rotta `export` della risorsa con un gestore proprio
  (`downloadUsing()`, wonder-image/app) (D64).
- **Catalogo Meta** (.csv) e **Google Merchant Center** (.xml RSS) nello stesso
  «Esporta»: solo ciò che si compra sul sito, prezzi IVA compresa con la
  campagna in corso, `id` uguale a quello del pixel (D64).
- Area **Esportazioni** nel pannello Funzionalità (`export_xlsx`,
  `export_csv`, `export_meta`, `export_google`; TikTok Shop e Amazon previste):
  «Esporta» offre solo i file concessi. Registro in
  `Support\Catalog\Exports\ProductExports`, scrittura in
  `Support\Export\Delimited` (csv/tsv), `XmlFeed` (rss) e `Download` (D64).
- **Feed a indirizzo fisso** per Google Merchant Center e Catalogo Meta:
  con `export_google`/`export_meta` sbloccate, l'attività
  `gestionale.product_feeds` (ogni ora, al minuto 7) riscrive
  `shared/feeds/google-merchant.xml` e `shared/feeds/catalogo-meta.csv` quando
  sono scaduti; bloccate, il file viene cancellato. Il pannello Funzionalità
  mostra l'indirizzo, l'ultimo aggiornamento e fa scegliere ogni quanto
  aggiornare (ogni ora, ogni 6 ore — di serie — o una volta al giorno), salvato
  nella nuova colonna `settings` (json) di `gst_features`
  (`Support\Catalog\Exports\ProductFeeds`, `Scheduler\ProductFeedsTask`) (D64).
- `php forge gestionale:demo` crea anche una vetrina: l'«Impermeabile da città»
  con tutto quello che un articolo può avere (12 versioni su colore, taglia e
  materiale, marchio, imballo, dimensioni, descrizione, scheda tecnica,
  personalizzazioni facoltative e obbligatorie, fornitori, una versione
  disattivata e una ordinabile anche esaurita), l'«Ombrello pieghevole» in
  offerta e il multiprodotto «Kit pioggia» con un componente fisso e due
  gruppi a scelta. La pulizia toglie solo questi tre articoli.
- `php forge gestionale:demo --clear` cancella i dati di prova senza rifarli:
  il sito torna come prima, ed è lo stato da cui partono i test
  d'integrazione. La sede di prova cancellata dal backend si rimette in uso
  invece di bloccare i dati di prova.

- Le pagine **Nuovo ordine da ufficio**, **Nuovo preventivo** e del **DDT**
  si disegnano con i componenti del core (`Card`, `FormField`, `Button`):
  campi e pulsanti con lo stile del backend, conferme della lib, isola di
  salvataggio; indirizzo e costo di spedizione compaiono solo con la
  spedizione, il valore dello sconto solo se c'è uno sconto. L'invio al
  cliente è un interruttore. L'editor delle righe dell'ordine resta quello di
  prima.

- Scheda ordine riordinata: in testa il menu **Documenti** (*Proforma*, che
  vale anche come conferma d'ordine ed è disponibile **già dalla bozza**;
  *Crea DDT*), i pulsanti che portano avanti l'ordine sempre a vista
  (*Conferma*, *Segna evaso*, *Crea spedizione*, *Pronto per il ritiro*,
  *Ritirato*, *Registra pagamento*) e il menu **Altro** (*Registra reso*,
  *Annulla*). Il numero sta nel titolo, sotto **Creato il … · canale**; la
  riga degli stati dice **Spedizione**, **Ritiro** o **Consegna** al posto di
  *Evasione*. Nelle tabelle della scheda il menu **⋯** in fondo alla riga:
  *Annulla riga* sulle righe di un ordine da ufficio (al posto di *Annulla
  righe*), *Registra pagamento* sulle rate in attesa (e il pulsante sopra i
  pagamenti), *Chiudi*/*Annulla* sui resi, le azioni delle spedizioni, ognuna
  con la sua finestra di conferma. Nella **scheda della spedizione** le
  azioni stanno in alto a destra. Nell'elenco ordini le colonne delle icone
  *Canale* e *Consegna* non hanno titolo.

- Ritocchi dalla prova nel browser: **Proforma** dell'ordine in PDF (ogni
  ordine non bozza né annullato, anche dal sito; tipo `order_proforma`); in
  testa alle schede di ordine, abbonamento e documento di magazzino i
  pulsanti stanno nei menu **Documenti** e **Azioni**; *Piani* diventa
  **Catalogo → Abbonamenti**; negli Ordini le icone del **canale** e della
  **consegna** con il tooltip; gli **abbonamenti** nella scheda del cliente;
  la **scheda del fornitore** con carichi e articoli forniti; negli elenchi di
  magazzino il numero apre il documento e lo **stato** ha la sua colonna.
  Corretto: le pagine *Nuovo ordine da ufficio*, *Nuovo preventivo* e del
  **DDT** uscivano senza stile (il layout non si chiudeva).

- G10b parte 1, **abbonamenti con più servizi**: un abbonamento ha delle
  righe (`gst_subscription_items`, una per servizio, con quantità, importi e
  personalizzazioni) e la loro storia (`gst_subscription_item_changes`); ogni
  riga rinnova dalla data d'inizio a multipli del suo intervallo. Piani con
  **Si lega a** (`gst_plan_links`, un livello) e **personalizzazioni**: la
  tabella dei legami diventa `gst_customization_links` (articoli e piani), la
  personalizzazione ha gli interruttori *Per i prodotti* / *Per gli
  abbonamenti* e la funzionalità chiede *Ordini* **o** *Abbonamenti*. Da
  ufficio, *Nuovo abbonamento* con le righe e, nella card **Servizi** della
  scheda, *Aggiungi servizio* (quota ora, gratis o dal prossimo periodo),
  *Cambia*, *Togli* (a fine periodo o subito con un credito) e *Correggi
  personalizzazioni*; il credito riduce il proforma dopo. Impostazione
  **Abbonamenti in corso per cliente** (di serie 1). Dati di prova: tre servizi
  (Dominio .it, Hosting, Assistenza) e un abbonamento con le tre righe. Guide
  `vendite-abbonamenti.md` e `catalogo-personalizzazioni.md`.
  **Dopo `php forge update`** lancia `php tests/supporto/migra-abbonamenti-con-servizi.php`
  (copia legami, una riga per abbonamento, storia dei cambi); verificato il
  risultato, rilancialo con `--togli-vecchie` per togliere
  `gst_product_model_customizations` e `gst_subscription_plan_changes`.
  `plan_price_id` di `gst_subscriptions` resta fino ad allora.
- G10b parte 3, per il negozio: `OnlineSubscriptions::lines()` prepara e
  controlla una selezione di più servizi (madri, personalizzazioni, intervalli)
  e `preview()` ne dà gli importi del primo addebito, prima di Stripe.
- G10b parte 2, **servizi degli abbonamenti online**: ogni riga è una
  subscription item di Stripe (`metadata.item_id`); il Checkout ha una riga per
  servizio con `billing_mode=flexible`. Dalla card **Servizi** *Aggiungi*
  (`subscription_items.create`, quota con `always_invoice`), *Togli* col credito
  (`create_prorations`) e *Cambia* (`subscription_items.update`); quello che vale
  dal rinnovo va in un solo *Subscription Schedule* (`end_behavior=release`),
  tolto prima di una disdetta. Le righe di prima di G10b prendono il loro `si_`
  da Stripe alla prima modifica. *Cambia piano* cambia la riga principale.
  `OnlineSubscriptions::quote()` dà la quota dall'anteprima di Stripe. Errori
  nuovi `subscription.stripe_failed` e `subscription.last_item_online`.
- G10 piano 3, **Abbonamenti da ufficio**: **Nuovo abbonamento** (cliente,
  prezzo, data d'inizio, metodo e condizione di pagamento, coupon); a ogni
  periodo le **rate** della condizione di pagamento e il **proforma** PDF per
  email; rata scaduta → *da pagare* con un promemoria, oltre i giorni
  dell'impostazione **Giorni prima di «non pagato»** → *non pagato*;
  **Registra pagamento** sulle rate, anche dallo **Scadenzario**, che ora
  mostra le rate degli abbonamenti. Dalla scheda **Cambia piano**,
  **Disdici** (anche per gli online), **Annulla** e **Rimanda il proforma**.
  Attività **periodi e scadenze degli abbonamenti da ufficio**.
  `gestionale:demo` aggiunge un abbonamento da ufficio su «Base».

- G10 piano 2, **Abbonamenti online** (`online_subscriptions`, da attivare,
  richiede *Vendita online* e *Abbonamenti*): sottoscrizione con **Stripe
  Checkout** in modalità abbonamento (carta e Link), prova gratuita una volta
  per cliente e per piano, codici sconto passati a Stripe con la loro durata;
  piani, prezzi, aliquote e coupon creati in Stripe alla prima occorrenza.
  Le notifiche `checkout.session.completed`, `customer.subscription.*`,
  `invoice.paid` e `invoice.payment_failed` decidono stato, periodo e
  pagamenti (una riga di pagamento per rinnovo pagato). Cambio piano senza
  conguagli, disdetta a fine periodo o a fine vincolo, prezzo di rinnovo a fine
  vincolo (attività **fine vincolo degli abbonamenti**), riallineamento orario
  e chiusura degli abbandonati dopo 24 ore. Email di attivazione, rinnovo non
  riuscito e disdetta.

- G10 piano 1, **Abbonamenti** costruita (`subscriptions`, da attivare):
  pagina **Vendite → Piani** con i prezzi in un repeater (intervallo, mesi
  per addebito, vincolo, prova, prezzo scontato, prezzo di rinnovo) e i limiti
  a coppie chiave/valore; un prezzo usato da un abbonamento non si toglie, un
  piano con abbonamenti in corso non si elimina. Pagina **Vendite →
  Abbonamenti** in sola lettura, con filtri per stato e canale e scheda con
  periodo, importi, pagamenti, cambi di piano e storia. Coupon: **Vale per gli
  abbonamenti** con la durata (primo addebito, N mesi, per sempre).
  `Subscriptions::limit()` per il sito; il pannello **non spegne
  *Abbonamenti*** con abbonamenti in corso. Guida `vendite-abbonamenti.md` e
  guida sviluppatori `concetti/abbonamenti.md`. `gestionale:demo` crea i piani
  «Base» e «Pro».

- G9 piano 4, **DDT** (funzionalità costruita): **Crea DDT** sulla scheda di
  un ordine confermato, bozza con le righe da consegnare (una per lotto),
  luogo di consegna e trasporto (predefiniti in *Impostazioni → Vendite →
  DDT*); **Emetti** con numero, data e PDF senza prezzi; **Annulla DDT**;
  **Invia per email** con il PDF. **Vendite → DDT** con l'elenco. Le righe
  evase contano il massimo fra DDT emessi e spedizioni, mai la somma
  (`FulfilledLines`): la merce di un DDT non si rispedisce. Guida
  `vendite-ddt.md`. **Chi aggiorna deve lanciare `php forge update`.**

- G9 piano 3, **Preventivi** (funzionalità costruita, chiede *Vendita in
  ufficio*): **Vendite → Preventivi** con la stessa pagina dell'ordine da
  ufficio e la validità; **Invia al cliente** con numero, revisione e PDF
  archiviato, email con il PDF allegato; una modifica dopo l'invio è una
  revisione nuova; **Accettato** fa del preventivo una bozza d'ordine,
  **Rifiutato**, scaduti segnati ogni mattina (attività *preventivi
  scaduti*, spenta). PDF del preventivo e della **conferma d'ordine** (anche
  allegata all'email di conferma e da scaricare sulla scheda), sostituibili
  dal sito con l'hook `documentPdf`. *Validità dei preventivi* nelle
  Impostazioni. Guida `vendite-preventivi.md`. **Chi aggiorna deve lanciare
  `php forge update`.**

- G9 piano 2, **Ordini da ufficio** (con *Vendita in ufficio*): **Vendite →
  Ordini da ufficio** con le bozze e **Nuovo ordine**: la bozza senza numero né merce impegnata (cliente con i
  dati della sua scheda, fatturazione, consegna con costo di spedizione a mano,
  sede, righe da «Cerca o scansiona» con prezzo e sconto di riga, righe libere
  e di testo, lotto e fornitore, disponibile della sede, sconto sul totale,
  note). **Conferma** con il numero, la merce che esce (rifiuto col nome se
  manca, salvo vendita senza giacenza), le rate e l'email facoltativa;
  **Elimina bozza**. Negli Ordini le bozze col badge e il filtro, e **Annulla
  righe** sugli ordini da ufficio confermati: la merce rientra, totali e rate
  si rifanno. Guida `vendite-ordini-da-ufficio.md`. Corretto il ricalcolo del
  carrello con uno sconto di riga: il prezzo a mano sta in
  `manual_unit_price` e lo sconto non si applica più due volte.
  **Chi aggiorna deve lanciare `php forge update`.**

- G9 piano 1, **Condizioni di pagamento e scadenzario**: *Set Up → Pagamenti
  → Condizioni di pagamento* con le rate (giorni, fine mese, percentuale che
  fa 100), tre già pronte (rimessa diretta, bonifico 30 gg fine mese, RiBa
  30/60); una condizione in uso non si elimina. Le rate di un ordine sono
  pagamenti in attesa con la scadenza (`Installments`): un incasso minore
  lascia il resto alla stessa scadenza, uno maggiore scala le rate dopo; il
  registro libero sull'ordine chiude prima la rata più vicina. Con *Vendita in
  ufficio*: **Vendite → Scadenzario** (filtri *Scadute*, *In scadenza*,
  *Tutte in attesa*, *Pagate*; *Registra pagamento* sulla rata), riquadro
  della home **Pagamenti in scadenza**, *Giorni di preavviso per le scadenze*
  nelle Impostazioni, metodo e condizione predefiniti sulla scheda del
  cliente. Guida `vendite-pagamenti-a-rate.md`. `gestionale:demo` con
  *Vendita in ufficio* aggiunge un ordine da ufficio con due rate, una
  scaduta. **Chi aggiorna deve lanciare `php forge update`.**

- G3 piano 4, **Trasferimenti** (con *Più sedi*): documento in due passi,
  **Invia** (la merce lascia la partenza ed è *in viaggio*) e **Ricevi**
  (entra tutta all'arrivo), annullabile in viaggio e da ricevuto; lotto e
  fornitore scelti a mano come nello scarico; la merce che non c'è non parte,
  nemmeno con la vendita senza giacenza. Colonna **In viaggio** nelle
  Giacenze; *Visualizza trasferimento* nei Movimenti. Il pannello delle
  funzionalità **non spegne *Più sedi*** con pezzi fuori dalla sede principale
  o trasferimenti in viaggio, e dice dove sono. Guida
  `magazzino-trasferimenti.md`, ritocchi a *Sedi*, *Giacenze*, *Movimenti*.
  `gestionale:demo` con *Più sedi* aggiunge una sede di prova e un
  trasferimento in viaggio.

- G3 piano 3b, **Lotti e scadenze** costruita (`batch_tracking`, da
  attivare): pagina **Magazzino → Lotti** con i pezzi, la scadenza colorata
  (rossa scaduto, gialla entro i giorni scelti) e i filtri *Stato* e *Sede*;
  scheda del lotto con pezzi per sede, movimenti e **clienti che l'hanno
  ricevuto** al netto di annulli e resi, **Scarica i clienti** (CSV) per i
  richiami; scadenza e nota modificabili, un lotto con movimenti non si
  elimina. Riquadro della home **Lotti in scadenza**; *Giorni prima della
  scadenza* nella card *Magazzino* delle Impostazioni; colonna e ricerca
  **Lotto** nei Movimenti. Guida `magazzino-lotti.md`. `gestionale:demo` con
  la funzionalità accesa aggiunge un carico con due lotti.

- G3 piano 3a, **Lotti e scadenze**: interruttore *Gestisci lotti e
  scadenze* nella scheda dell'articolo; nel carico caselle **Lotto** e
  **Scadenza** e bottone **+ lotto** per le versioni che li gestiscono, lotto
  trovato o creato alla conferma (`Batches`) e merce nel secchio del lotto;
  nello scarico la scelta a mano del **lotto** e, con *Acquisti*, del
  **fornitore** (vuota = automatico, prima il lotto che scade prima); i lotti
  scaduti escono dal disponibile e hanno la colonna *Scaduti* nelle Giacenze;
  lotto, scadenza e fornitore scelto sulle righe della scheda del documento.

- G3 piano 2, **Acquisti** (funzionalità `purchasing`, inerte se spenta):
  carico con **Fornitore**, documento e data del fornitore in testata e
  **Costo** per riga (IVA esclusa) con il totale, riempito dal costo
  dell'opzione per quel fornitore; alla conferma la merce entra nel secchio
  del fornitore, il costo diventa quello di oggi in `gst_product_suppliers`
  (legame creato se manca), e la vendita scoperta si **compensa** (due
  movimenti *Assegnazione lotto*). Scarichi, inventari in meno e rettifiche in
  meno escono dai secchi (`StockOut`), prima la merce senza fornitore e poi il
  fornitore che costa meno. **Valore del magazzino**: riquadro della home
  (`StockValueWidget`) e colonna *Valore* nelle Giacenze. Colonne e filtri
  *Fornitore* in Carichi e Movimenti, giacenza per fornitore nella scheda della
  versione. Annullare un documento storna, dentro la stessa versione,
  dall'ultimo movimento al primo. Guida `magazzino-acquisti.md`.
  `gestionale:demo` con *Acquisti* accesa aggiunge un carico da un fornitore
  di prova.

- G3 piano 1, magazzino a secchi (inerte a funzionalità spente): tabella
  `gst_batches` (lotti), `reference_item_id` sui movimenti (la riga d'ordine o
  di documento), `batch_number`/`batch_expiry` sulle righe dei documenti,
  `track_batches` sull'articolo, `batch_expiry_days` nelle impostazioni (non
  sincronizzata), stato `in_transit` dei documenti, causale interna
  `batch_assignment`. `StockPicking`, `StockCosts`, `StockBuckets` e
  `StockMovements` dividono la vendita fra lotti e fornitori e rimettono
  annulli e resi nei secchi da cui la merce era uscita; `Levels::forSale()` è
  il disponibile del carrello (solo la sede delle vendite, senza lotti
  scaduti). `Returns` passa la riga d'ordine ad `Allocation::returnGoods()`.
  **Chi aggiorna lancia `php forge update`.**

- Inventari, pagine: *Magazzino → Inventari* (`StockStocktakeResource` su
  `StockDocumentResource`) con le colonne *Contate* e *Con differenza*
  nell'elenco, editor in modo inventario (Attesi · Contati · Differenza, righe
  non contate vuote, attesi che seguono `StockDocumentRules::expected()`) e
  *Aggiungi versioni* per tutte, una categoria con le sottocategorie o un
  marchio (`StockVersionSearch::scope()`, `scope`/`scope_id` sulla rotta delle
  versioni; oltre 500 righe rifiuta). Conferma che riassume contate, differenze
  e correzione; scheda in sola lettura con attesi, contati e differenza
  colorata (`StockDocumentSheet`, `StockDocumentEditor::stocktakeTotals()`).
  Nei Movimenti *Visualizza inventario*. `gestionale:demo` aggiunge un
  inventario in bozza. Guida `magazzino-inventario.md`.
- Carichi e scarichi, pagine: *Magazzino → Carichi* e *Magazzino → Scarichi*
  (`StockReceiptResource`, `StockIssueResource` su `StockDocumentResource`) con
  elenco, bozza con «Cerca o scansiona» e lettore di codici a barre, *Salva
  bozza* e *Conferma*, scheda in sola lettura con *Annulla* e *Duplica*
  (`StockDocumentActionResource`). Ricerca delle versioni `StockVersionSearch`
  dietro `api.gestionale.backend.stock.versions`, indice su `gst_products.ean`.
  Nei Movimenti *Visualizza carico* e *Visualizza scarico* nel menu ⋯, con il
  documento letto nella query dell'elenco. `gestionale:demo` crea un carico e
  uno scarico confermati (`StockDocumentsDemo`). `StockDocuments::create()`
  accetta un `code`; `StockDocumentRules::lines()` controlla il limite di righe
  prima di leggerle e rifiuta un `product_id` che non è un intero positivo;
  conferma e annullamento applicano i movimenti in ordine di versione;
  *Duplica* non blocca più il documento d'origine.
- Documenti di magazzino, dati e servizio: `gst_stock_documents`,
  `gst_stock_document_items`, `gst_stock_document_status_logs`; Carico, Scarico e
  Inventario con bozza, conferma (numero per tipo, un `Stock::apply()` per riga),
  annullamento con gli storni (`stock_document_cancel`), duplica ed eliminazione della
  bozza (`Support\Stock\StockDocuments`, regole pure in `StockDocumentRules`);
  `Reasons::forDocument()`. Nei Movimenti *Tipo* mostra il documento e i suoi storni, e
  il filtro *Tipo* ha *Carico*, *Scarico* e *Inventario*. Le pagine sono nelle due
  voci sopra.
- `Coupons::reserved(int $customerId)`: i coupon riservati al cliente, con i suoi
  utilizzi non rilasciati (`list<array{coupon, used}>`). Vuoto con `id` minore o uguale
  a 0 o con la funzionalità Coupon spenta. `CustomerSheet::coupons()` lo usa: la scheda
  del backend e il pannello del cliente contano gli utilizzi allo stesso modo.
- Checkout dell'ospite: `Checkout::place` accetta `customer_email.account_url` e lo porta
  nell'email al cliente (ricevuto, o confermato col pagamento alla consegna); l'email
  mostra «Crea la tua password per seguire i tuoi ordini» con il pulsante. `place`
  restituisce `customer_email_sent`, vero se l'email al cliente è partita.
- Scheletro del modulo: manifest, entrypoint, configurazione, Resource base.
- Test del modulo con harness proprio e `php tests/run.php`.
- Funzionalità sbloccabili: catalogo nel codice, stato su database sincronizzato
  con `id` stabili, pannello "Funzionalità" con un interruttore per funzionalità
  (`formSchema` e `formLayoutSchema` su una pagina-form del core `2.2.4`, con il
  campo `toggle` e il contenitore `masonry`), righe
  precaricate create da `forge update`, pagine che spariscono quando la
  funzionalità è bloccata.
- Documentazione: `gitbook-docs.yaml` con gli spazi GitBook "Sviluppatori"
  (`docs/`) e "Guida commercianti" (`guide/`), con le prime pagine.
- Avvisi di scorta minima (`low_stock_alerts`): campo *Scorta minima* nelle due
  schede, avviso aperto e chiuso da `Stock::apply()` e dal salvataggio della
  soglia, attività `gestionale.stock_alerts` (ogni quarto d'ora, nata spenta)
  che manda una email raggruppata ai *Destinatari degli avvisi* con la view
  sovrascrivibile e l'hook `beforeEmailSend` (`stock.low_stock`), anteprima
  `php forge gestionale:stock-alerts`, riquadro *Sotto scorta* nella home e
  giacenze negative in *Da controllare*.
- Scheda articolo, ottavo giro: prezzi con l'input prezzo del core (in euro),
  giacenza con l'unità dell'articolo (*pz*, *kg*), descrizione breve su una
  riga, descrizione con l'editor (grassetto, corsivo, link) salvata come HTML
  ripulito. Riquadro *Scheda tecnica* sempre presente, con *Nuova
  caratteristica* (Testo o Numero, creata al volo) e gli attributi a valori
  come pillole da spuntare, più d'uno per articolo
  (`ProductAttributes::rows()`). Il tipo di attributo *Icona* ha un'immagine
  per valore al posto dell'icona del font.
- Acquisti (`purchasing`), fornitori per opzione: da chi si compra ogni
  opzione in vendita, con quale codice e a quanto, in `gst_product_suppliers`
  (`ProductSuppliers`). Si compilano dietro «Compila le informazioni
  avanzate», nel riquadro *Prodotto* o nella riga della griglia, e nella
  scheda dell'opzione: con un fornitore solo *Codice fornitore* e *Costo
  d'acquisto*, con due o più il bottone *Fornitori* con la finestra a righe e
  *Salva per tutte le opzioni*. Accendendo le varianti le opzioni che nascono
  prendono i fornitori dell'articolo. Un fornitore con dei costi non si
  elimina e non perde il ruolo.
- Con più sedi la griglia delle opzioni non ha la colonna *Giacenza*: i pezzi
  si scrivono sede per sede dal bottone *Giacenza*.
- Giacenze in sola consultazione: l'elenco del core su `Product`, con una
  colonna per sede (`Locations::shown()`), *Totale*, *Scorta minima*,
  *Impegnati* e *Disponibili* secondo le funzionalità, numeri calcolati nella
  query (`LevelsSql`) e ordinabili, ricerca anche sul nome dell'articolo, filtri
  *Stato*, *Marchio*, *Categoria* e *Scorta*, menu ⋯ con *Movimenti* e *Apri la
  versione*. Tolta la pagina-form con le caselle e il salvataggio in blocco;
  `lowStockUrl()` apre l'elenco con il filtro *Scorta*.
- Scheda opzione, quindicesimo giro: dalla scheda dell'articolo *Dettagli delle
  opzioni* è un bottone piccolo che apre una finestra con Opzione · SKU ·
  Prezzo · Stato — il nome e i tre puntini portano alla scheda, in fondo resta
  *Apri l'elenco completo*. La scheda dell'opzione va su due colonne: a destra
  *Identificazione* con SKU, EAN, MPN (prima *Codice del produttore*) e le
  misure, e il riquadro *Misure* sparisce. Gli attributi si dividono in
  *Opzioni di vendita*, in sola lettura perché si cambiano dalla griglia
  dell'articolo, e *Scheda tecnica* con *Aggiungi caratteristica*: tutti e due
  mostrano solo quello che è compilato. Nel riquadro *Magazzino* la rettifica
  si fa in una finestra e i movimenti sono un datatable da cinque righe, con
  *Vedi tutti i movimenti* per la storia lunga. Nella rettifica *Come la
  scrivi* si chiama *Azione* e ha tre voci — *Aggiungi* (il predefinito),
  *Sottrai* e *Imposta*: il segno lo mette l'azione, la quantità si legge
  sempre in valore assoluto.
- Scheda articolo, sedicesimo giro: il nome dell'elenco apre la **scheda in
  lettura**, con lo stesso disegno a due colonne della modifica — *Prodotto* e
  *Opzioni in vendita* a sinistra, *Foto e video*, *Stato* e *Dove si trova* a
  destra, e sotto lo spazio per le statistiche che arriveranno con gli ordini.
  Le opzioni sono la tabella di `ProductResource` ristretta all'articolo
  (Opzione · SKU · Prezzo · Stato, tre puntini alla scheda), e lo stato si
  commuta con un click: quello dell'articolo dalla pillola *Pubblicato /
  Bozza*, quello di ogni opzione dalla sua riga (*Attiva / Ferma*). Dalla
  modifica spariscono il bottone *Dettagli delle opzioni*, la sua finestra e la
  tabella scritta a mano. Nell'elenco dei prodotti le colonne sono foto, nome,
  SKU, prezzo e numero di opzioni: il prezzo porta l'euro, barra il pieno
  quando c'è lo sconto e scrive *da 19,90 €* quando le opzioni costano diverso;
  il marchio non c'è più. I movimenti della scheda dell'opzione nascono dalle
  colonne che `StockMovementResource` dichiara (`Resource::backendTable()`), e
  le foto proprie seguono l'attributo che l'articolo usa davvero, non il primo
  del negozio.
- Magazzino delle vendite e pagamenti: `Support\Stock\Allocation` mette da
  parte la merce di un carrello, la scarica alla conferma senza vendere due
  volte l'ultimo pezzo e la fa rientrare da annullamenti e resi;
  `Support\Payments\Ledger` tiene le righe di denaro, regge la notifica doppia
  del gateway e ricalcola da solo il `payment_status` dell'ordine.
- Backend *Vendite*: l'elenco **Ordini** (tre etichette — ordine, pagamento,
  evasione — filtri per i tre stati e per periodo, ricerca per numero, cliente
  ed email) e la **scheda** «Ordine <numero>» (intestazione, riepilogo IVA e
  totali in riquadri; *Righe* — con la foto del prodotto —, *Pagamenti*, *Resi*
  e *Storico* in accordion, ciascuno una tabella del core con
  `TableLayoutSchema` e `TableColumn`: `OrderItemTableResource`,
  `OrderPaymentTableResource`, `OrderReturnTableResource`,
  `OrderHistoryTableResource`, senza pagina e senza menu). Il cliente è un link
  alla sua scheda. Le **note** interna e sul documento si modificano da una
  finestra (`OrderNoteResource`); la nota del cliente resta sua. Le azioni
  stanno nei pulsanti della scheda e chiamano `Lifecycle` e `Ledger` senza
  regole proprie: *Conferma*, *Segna evaso* e *Annulla* con una finestra che
  dice cosa succede al magazzino (`OrderActions`), *Registra pagamento* in una
  finestra che posta a `OrderPaymentResource`. Il ritorno all'elenco è la
  chevron del titolo. Nell'elenco il totale è una colonna importo
  (`TableColumn::price()`, dal core: importo all'italiana, a destra, cifre
  tabulari). In alto a destra, accanto all'intestazione, stanno *Totali* e poi
  *Riepilogo IVA*. Ogni riga d'ordine salva il **nome completo** dell'articolo
  (`ProductNames::full()`: articolo e opzione) e l'**indirizzo della foto** che
  aveva al momento dell'ordine (colonna `image`); le righe già esistenti ne
  sono prive e la tabella ripiega sulla foto del catalogo.
  Le *note* di un ordine evaso e pagato sono bloccate: lucchetto al posto
  della matita, e il salvataggio le rifiuta anche se la richiesta arriva a
  mano. Il cliente nella scheda ordine è un link alla **scheda cliente**.
- Scheda cliente (sola lettura, `view` della Resource *Clienti*; il nome
  nell'elenco la apre, il pulsante *Modifica* porta al form): *Statistiche*
  (ordini, speso, scontrino medio, primo e ultimo ordine, da pagare, carrello),
  *Ordini* (tabella del core, con i tre stati), *Prodotti nel carrello* e, in
  fondo, *Coupon assegnati* (per ora solo la frase: i coupon non esistono
  ancora). *Tutti i suoi dati* è una card sempre aperta (tipo, nome, ruolo,
  stato, contatti, accesso al sito, note) scritta con `DataItem` del core; i
  **dati di fatturazione** stanno in una card a parte, in alto a destra. Gli
  *Indirizzi di consegna* hanno una card con una card per indirizzo e, da
  finestre, **aggiungi, modifica, rendi predefinito, elimina**
  (`ContactAddressResource`, pagina-form senza menu: un solo predefinito, il
  primo lo diventa da sé, l'eliminazione manda l'indirizzo nel cestino). Senza
  la funzionalità «orders» restano dati, fatturazione, indirizzi e coupon. Le
  statistiche stanno in `Support\Contacts\CustomerStats` (non contano gli
  ordini annullati o rimborsati per intero né i carrelli) e il disegno in
  `Support\Contacts\CustomerSheet`. I fornitori non hanno scheda: il nome apre
  la modifica. Dati di prova: nuova cliente *Anna Verdi* con i suoi ordini; solo
  l'ospite resta senza scheda.
- `Support\Catalog\ProductPhotos`: la foto di un articolo (opzione, colore,
  poi modello), usata dalle giacenze e dalle righe dell'ordine.
  *Metodi di pagamento* e *Conti di pagamento* in Set Up, per l'`admin`.
  *Registra reso* e la sua voce nel menu arrivano col Piano 5.
- *Movimenti* leggibili: colonne *Chi*, *Prima*, *Sede*, il documento dentro
  *Tipo* col link all'ordine, filtri *Causale*, *Sede* e *Periodo*
  (`MovementPeriod`), ricerca sull'articolo, *Ultimi movimenti* nella scheda
  della versione.
- `gestionale:demo` crea **sette ordini di prova** in tutti gli stati (in
  attesa, pagato, evaso, annullato, pagamento parziale, ospite, azienda) e li
  toglie rimettendo in magazzino la merce; con `--fresh` cancella tutto in
  ordine inverso di registrazione prima di ricreare.
- Per chi aggiorna: `PaymentMethod` e `PaymentAccount` **non cambiano tabelle**
  in questo giro; `Defaults` semina tre metodi nuovi (bonifico, contanti, carta
  — la carta nasce spenta).
- Resi (`returns`): `Returns` registra, chiude e annulla i resi; il reso nasce
  *ricevuto* e il rientro a magazzino passa da `Allocation::returnGoods()`, solo
  per le righe con la spunta. Le regole pure stanno in `ReturnRules` (massimo
  rendibile, ricarico proposto dal motivo, quantità). Un reso si annulla solo se
  nessuna riga è rientrata (`return.already_restocked`): per correggere la
  giacenza c'è la rettifica in Magazzino. Il rimborso del denaro non fa parte
  dei resi: resta al gateway con `Ledger::refund()`.
- *Registra reso*: pagina con le righe dell'ordine, motivo, ricarico e sede, voce
  nel menu dell'ordine (`OrderActions::canRegisterReturn`) e tabella *Resi
  dell'ordine* nella scheda con *Chiudi* e *Annulla*.
- `gestionale:demo` crea anche un reso di un pezzo sull'ordine evaso (con `returns`
  accesa) e lo toglie restituendo al magazzino solo quel che non era già rientrato.
- Guide: tre pagine per il commerciante (*Ordini*, *Pagamenti*, *Resi*, sezione
  *Vendite*) e *Vendite: ordini, pagamenti e resi* per gli sviluppatori.
- Personalizzazioni (funzionalità `customizations`, richiede `orders`): campi che il
  cliente compila comprando, di testo o a scelta, con sovrapprezzo. Pagina *Catalogo →
  Personalizzazioni* con le opzioni, riquadro *Personalizzazioni* nella scheda
  dell'articolo con «Nuova personalizzazione» al volo (nome, tipo Testo o Numero,
  sovrapprezzo), `Customizations` (controllo,
  sovrapprezzo, codifica per latin1), `Cart::add()` che le controlla e le prezza dal
  server e fa uscire la riga se l'anagrafica cambia, righe d'ordine, email e reso
  (online escluso). `gestionale:demo` crea «Incisione» e «Confezione regalo» e mette
  l'incisione su metà degli ordini di prova. Chi aggiorna esegua `php forge update`
  per le tre tabelle nuove.
- Multiprodotto (funzionalità `bundles`): un articolo che ne contiene altri, in tre
  modi (fisso, a scelta del cliente, misto) con gruppi di scelta, minimo e massimo e
  sovrapprezzo per opzione. Nella lista degli articoli due bottoni («Aggiungi Prodotto»,
  «Aggiungi Multiprodotto») scelgono il tipo; composizione nella scheda, valore
  dei componenti facoltativo, disponibilità dal componente più scarso. `Bundles`
  (composizione, `resolve()`, `available()`, `usedBy()`), `OrderLines` (madre e
  figlie), `Cart::add()` con `choices` e righe figlie che il server riscrive, scheda
  dell'ordine ed email con i componenti sotto la confezione, reso della confezione
  con il «rientra a magazzino» di ogni componente. Un prodotto usato da un
  multiprodotto non si elimina né si spegne (`bundle.in_use`). `gestionale:demo`
  crea tre cesti e, con la funzionalità accesa, quattro ordini (uno con un reso).
  Chi aggiorna deve lanciare `php forge update`: tre tabelle e tre colonne nuove
  su `gst_product_models`.
- Elenco e scheda degli articoli: stato **Pubblicato**/**Bozza** (non più
  visibile/nascosto), filtri per stato, marchio, categoria (con le sottocategorie)
  e, con `bundles`, tipo. La scheda in lettura ha per titolo il nome dell'articolo,
  non ha i bottoni di aggiunta in «Opzioni in vendita» e, per un multiprodotto,
  mostra il badge e il riquadro «Composizione» con la tipologia, i «Componenti» e i
  «Gruppi di scelta».
- Campagne di sconto (`discount_campaigns`, G6 piano 1): sconto in percentuale o
  in importo su categorie, tag, marchi o articoli per un periodo e per canale,
  con prezzo applicato dal carrello (`price_source = 'campaign'`, `discount_campaign_id`
  sulla riga), pagina *Promozioni → Campagne di sconto* con anteprima dei
  prodotti coinvolti e avviso di sovrapposizione, tre campagne di prova
  (`PromotionsDemo`) e guide. Chi aggiorna deve lanciare `php forge update`: cinque
  tabelle nuove e una colonna su `gst_order_items`.
- Coupon (`coupons`, G6 piano 2): codice in percentuale, importo o spedizione gratuita,
  con spesa minima, limiti totali e per cliente, solo primo ordine, clienti riservati e lo
  stesso selettore dei prodotti delle campagne. Il carrello ricontrolla il codice a ogni
  ricalcolo; gli utilizzi si contano alla creazione dell'ordine (anche con due processi
  insieme) e si liberano all'annullo o alla scadenza. Pagina *Promozioni → Coupon* con la
  tabella degli utilizzi, coupon sulla scheda dell'ordine e sulla scheda cliente, cinque
  coupon e tre ordini di prova (`PromotionsDemo`, `OrdersDemo`) e guide. Chi aggiorna deve
  lanciare `php forge update`.
- Schede di lettura per coupon e campagne: dall'elenco si apre una pagina con i dettagli, i
  canali, l'ambito e (coupon) gli utilizzi o (campagne) anteprima e avviso di sovrapposizione;
  il form di modifica ha solo i campi.
- Canali di vendita: tre funzionalità (`online_sales`, `office_sales`, `pos`, quest'ultima
  ancora non accendibile) nell'area *Canali di vendita*. Coupon, campagne e metodi di pagamento
  mostrano «Dove vale» solo con più di un canale acceso e fanno nascere i nuovi record sui
  canali accesi; senza canali sbloccati vale solo il sito. Il motore non cambia. Chi aggiorna
  deve lanciare `php forge update` (due righe nuove in `gst_features`); la funzionalità `pos`
  si chiamava «Banco».

- Spedizioni, listini e calcolo (`shipping`, G7 piano 1): corrieri col link di tracking,
  zone per paese e provincia, metodi con un listino per zona (scaglioni di peso, tariffa al
  kg oltre l'ultimo, peso volumetrico, carburante, margine, arrotondamento, minimo, gratis
  sopra un importo o sotto un peso, costo di contrassegno), `Shipping` come unica porta
  (`options`, `quote`, `line`, `codFee`), riga di spedizione calcolata in `Cart::recalculate`
  e contrassegno in `Checkout`, pagine *Spedizioni → Corrieri / Zone / Metodi*, dati di prova
  (`ShippingDemo`) e guide. Chi aggiorna deve lanciare `php forge update`.

- Spedizioni sugli ordini (`shipping`, G7 piano 2): spedizioni vere con le loro righe, anche
  parziali (mai più di quanto ordinato), tracking e link del corriere, stati di consegna
  (`pending`, `in_transit`, `delivered`, `exception`…), ritiro in sede (*Pronto per il ritiro*,
  *Ritirato*) e `Shipments` come unica porta; l'evasione dell'ordine si ricava dalle quantità
  spedite e con le spedizioni accese «Segna evaso» resta solo per gli ordini senza consegna né
  ritiro; email al cliente di *spedito* e *pronto per il ritiro*; pagina *Spedizioni →
  Spedizioni* con elenco filtrabile e scheda, blocco *Spedizioni* e sei azioni nella scheda
  ordine, due riquadri nella bacheca (*Spedizioni da controllare*, *Spedite e non consegnate*
  da più di 7 giorni), spedizioni di prova (`ShipmentsDemo`) e guide. Corriere, tracking e
  stato si cambiano anche dopo (*Modifica spedizione*, dai tre puntini dell'elenco o dalla
  scheda), l'elenco si cerca per numero d'ordine, il listino può essere a **prezzo fisso**
  (`price_type`, `fixed_price`: colonne nuove), sei corrieri di serie (Poste Italiane, DHL,
  GLS, UPS, Bartolini, FedEx) e le pagine Metodi, Zone e Corrieri passano nel *Set-up*,
  *Spedizioni* nelle *Vendite*. Il nome del corriere non cambia più le maiuscole (DHL resta
  DHL). Chi aggiorna deve lanciare `php forge update`.
- Spedizioni, ritocchi alla scheda del metodo: nel *Set-up* le pagine stanno nel gruppo
  *Spedizioni* (Metodi di spedizione, Zone di spedizione, Corrieri, Imballaggi); a prezzo
  fisso scompaiono gli scaglioni e i campi a peso; al posto dell'interruttore «Spedisce verso
  questa zona» c'è **+ Aggiungi zona**, con **Nuova zona…** per crearla lì (nome e prima area)
  e **Togli zona** per toglierla senza perdere il listino.
- Spedizioni, ancora sulla scheda del metodo: tutte le zone stanno in un'unica card «Zone», con
  «+ Aggiungi zona» in fondo e senza il bottone «Nuova zona» fuori dalla card; «Togli zona» chiede
  conferma; una zona appena aggiunta parte a prezzo fisso; il «Codice del servizio» non c'è più
  nel modulo (la colonna resta).
- Pagamenti, ritocchi alle schede: il tipo del metodo è *Bonifico bancario*, *Contanti*, *Stripe*,
  *PayPal* o *Nexi* (il vecchio «A mano» si divide in due: i siti già installati si sistemano da
  soli); il codice del metodo e del conto lo crea il sistema (`pme_…`, `pac_…`) e non si scrive
  più; la commissione ha un nuovo tipo *Importo fisso + percentuale* e importo e percentuale
  compaiono solo quando servono (`fee_percent`: colonna nuova; `fee_value` è ora sempre l'importo
  fisso, la vecchia percentuale passa a `fee_percent`); la modalità di pagamento della fattura si
  sceglie da `Pagamento::Valori`, «MP05 - Bonifico»; il conto si sceglie solo per il bonifico; il
  conto ha l'**Intestatario** (`holder`: colonna nuova); «Quando arriva il denaro» ha etichette
  più chiare e una spiegazione. Chi aggiorna deve lanciare `php forge update`.
- Checkout con spedizione: `Checkout::preview()` scrive le scelte del cliente sul
  carrello e risponde con totali, metodi di spedizione, sedi di ritiro e pagamenti;
  `Checkout::place()` rifiuta il ritiro senza sede di ritiro
  (`order.pickup_location_unavailable`) e la spedizione dal sito senza metodo
  (`order.shipping_method_required`, `order.shipping_unavailable`). Le sedi di ritiro
  stanno in `PickupPoints`, usato anche dalle spedizioni.
- Icone dei metodi di pagamento; font del negozio online nelle impostazioni del
  commerciante; l'anteprima del checkout mostra solo i provider collegati.

### Cambiato

- **Niente aliquota di ripiego** (gestionale#81): il `TaxResolver` è l'unico
  punto che decide l'aliquota e, senza una regola per paese, tipo di cliente
  e categoria fiscale, blocca la vendita con l'errore `tax.rule_missing`.
  Tolta l'impostazione `fallback_tax_id`. Passo a mano: prima di
  `php forge update` controllare che ogni modello abbia una categoria fiscale
  coperta da una regola IVA, altrimenti i carrelli si fermano.

- **Spedizione e commissione con l'aliquota della merce** (gestionale#81, art.
  12 DPR 633/72): con una sola aliquota fra le merci la prendono tutta; con più
  aliquote si dividono in proporzione all'imponibile (`Support\Tax\ChargeSplit`,
  arrotondamento sull'ultima quota), una riga per quota. Tolta l'impostazione
  `shipping_tax_id`; `php forge update` ne toglie la colonna.

- **Letture ripetute in copia per la durata della richiesta**:
  `Support\RequestMemo` tiene in memoria, per processo, impostazioni
  (`Setting::current()`), regole IVA (`TaxRule::active()`, solo le non
  cancellate: `active` lo filtra chi le usa), campagne e coupon con i loro
  ponti (`Campaigns::running`, `ProductScope::of`) e l'albero delle categorie
  (`ProductScope::parents`). `Cart::recalculate` legge prodotti e modelli una
  volta sola con `id IN (...)` (`Bundles::modelsOf()` / `isBundleRow()`)
  invece che riga per riga. Stessi risultati: le condizioni passano dal Model
  e tengono `deleted = 'false'`. Contratto: **le scritture passano dai
  Model/Resource oppure chiamano `::forget()`** (trait `ForgetsRequestMemo`,
  hook `after*` delle Resource, `Setting::forget()`). Dentro una transazione
  non si tiene in copia niente di nuovo e il gruppo scritto si rilegge sempre
  dal database fino alla chiusura. `Setting::fresh()` legge senza copia
  (usato da `ShippingSync` durante l'import). Limiti noti: le scritture
  dirette (`query()`, `sqlModify`, gli endpoint AJAX del core per stato,
  eliminazione e ordinamento) non svuotano la copia; un processo lungo (task
  dello scheduler) non vede le modifiche fatte da altri processi e può
  chiamare `RequestMemo::reset()`; la copia è separata da quella del core,
  che non la svuota.

- **Posizione dei nuovi indirizzi da customer-account**: un indirizzo creato
  dalla scheda cliente prende la posizione da
  `AccountAddresses::nextPosition()` invece di un calcolo locale. Stesso
  risultato: il `MAX` di customer-account conta già solo gli indirizzi non
  eliminati (il Model aggiunge `deleted = 'false'`).

- **Ricerca degli editor in un file solo**: `say`, `request` e `lookup`
  dell'editor dei documenti di magazzino e di quello degli ordini d'ufficio
  stanno in `resources/js/search-client.js` (`window.wiSearchClient`), fuori
  da `paths.assets` perché `publish:module --assets` non lo copi nel sito.
  Ogni editor lo stampa con `SearchClient::script()`; il guard
  `window.wiSearchClient || …` evita il doppio. Stesso comportamento.

- **Widget della home con i componenti del core**: «Spedizioni da
  controllare» e «Spedizioni in ritardo» diventano `StatusCard` (tutto il
  riquadro è il link all'elenco filtrato, senza più il bottone «Apri le
  spedizioni»); «Anagrafiche» e «Primi passi» diventano `LinkListCard` («Primi
  passi» perde la riga «Manca ancora qualcosa prima di partire.»; i bottoni
  «Vai» di «Primi passi» e «Apri» di «Anagrafiche» spariscono, la riga intera
  è il link). I bottoni
  rimasti negli altri riquadri passano da `Button`. Lotti in scadenza, Valore
  del magazzino, Sotto scorta, Da controllare e Pagamenti in scadenza restano
  in `WidgetCard`: hanno elenchi con più righe di dati che nessuna card del
  core rappresenta. Le due card delle spedizioni stanno a mezza riga una
  accanto all'altra: «Pagamenti in scadenza» passa da `order()` 26 a 24
  (prima di «Sotto scorta») per non finire in mezzo.

- **Bottoni in testa agli elenchi come `Button`**: «Mostra tutti» nei
  Movimenti filtrati per versione e «Nuovo abbonamento» negli Abbonamenti
  passano da `buttonCustom(Button::to(...))` invece che da HTML scritto a
  mano. Stesso aspetto; l'icona prende lo spazio dal `gap` del bottone.

- **Riquadri senza `<wi-card>`**: scheda lotto, form e scheda dei documenti
  di magazzino escono già con le classi che produceva `<wi-card>` (`card
  border` › `card-body row g-3`); i riquadri «Lotti in scadenza» e «Valore del
  magazzino» della home usano `WidgetCard::half()`, una `Card` del core a
  mezza riga. Nessun JavaScript dipende più dalla ricopiatura di
  `createCard()`.

- **Precedenza sulle tabelle dei contatti fissata dai test**: nuovo
  `tests/integrazione/ContactRegistryTest.php`. Su `contacts`
  `ResourceRegistry::resolveByTable()` risponde con `CustomerResource`
  (Clienti e Fornitori non sono fallback, `ContactResource` di
  customer-account sì); su `contact_addresses` resta `ContactAddressResource`
  di customer-account; `ModelRegistry` usa i Model `Contact` e
  `ContactAddress` del gestionale. Nessun cambio di comportamento: docblock
  dei due Model, `docs/user/anagrafiche.md` e `TODO.md` dicono che le schede
  sono di `wonder-image/customer-account`.

- **Contatti da `wonder-image/customer-account`**: `Contact` e `ContactAddress`
  del gestionale estendono i modelli del nuovo modulo (non più quelli di
  `wonder-image/app`) e aggiungono solo i campi commerciali. Il modulo è una
  dipendenza (`dependencies.modules`) da abilitare in `modules.php` prima del
  gestionale. Tabelle e dati non cambiano; «Clienti» resta l'unica voce del
  menu su `contacts`.

- Scheda articolo: i menu *Aggiungi un attributo* e *Aggiungi caratteristica*
  escono dal `Dropdown` del core (`ProductModelResource::addMenu()`) invece che
  da HTML in stringa. Aspetto e voci restano quelli di prima. Chiede il core con
  `Dropdown::icon()`, `caret()`, `toggleAttributes()` e le opzioni di `text()`:
  chi aggiorna il gestionale aggiorna anche `wonder-image/app` alla versione
  che li porta.
- Riquadri della home (*Primi passi*, *Da controllare*, *Sotto scorta*,
  *Anagrafiche*, *Spedizioni da controllare*, *Spedizioni in ritardo*): la
  cornice è una `Card` del core (`WidgetCard::wrap()`) al posto di
  `<wi-card>`, che il core dà per dismesso. Esce già fatta dal server invece
  di essere ricopiata dal JavaScript della lib al caricamento; il contenuto
  non cambia.
- Il gestionale chiede `wonder-image/app` `^2.4.0-beta.2`: `Query::Conditions()`
  del core toglie solo il `WHERE` iniziale, e *Giacenze* torna a escludere i
  multiprodotti con una sottoquery invece di leggerne gli id prima, in PHP.
  **Chi aggiorna deve avere il core almeno a `2.4.0-beta.2`.** Nel lock di
  sviluppo del modulo sale anche `stripe/stripe-php` a 19, come chiede il core.

- Checkout con Stripe: Apple Pay, Google Pay e Link sono anche fra le scelte del
  modulo, subito dopo la carta (`StripeMethods::choices()`), per chi chiede fattura
  o ritira in negozio e non può usare la barra rapida. Tipi dell'intento automatici
  (`intentTypes()` vuoto), Link senza icona; `Checkout::create` li accetta anche
  senza `express` e li salva in `provider_method`.
- Giacenze: nel menu ⋯ «Vai al prodotto» prende il posto di «Apri la versione» e apre
  la scheda in lettura dell'articolo (`view` di `ProductModelResource`, con
  `{product_model_id}`), non più la modifica della versione. Su mobile restano solo
  articolo, giacenza e menu (`hiddenDevice('mobile')` sulle altre colonne).
- Movimenti: nel menu ⋯ «Visualizza» prende il posto di «Apri la versione» e apre
  la scheda del movimento (`view`, `showLayoutSchema()`, `detailUrl()`, vista
  `pages/stock-movement-show.php`), con codice, costo unitario, lotto, fornitore e
  nota intera; la versione è un link dalla scheda. Permessi `list` e `view`.
- I destinatari degli avvisi di scorta minima (`low_stock_emails`) stanno in
  `gst_settings`, card *Email* di Set Up → Gestionale → Impostazioni («Email per la
  scorta minima», solo con `low_stock_alerts`): solo `admin`, fuori dal sync, si
  cambiano anche in produzione. Tolti `MerchantSetting`, `MerchantSettingResource` e la
  pagina Gestionale → Impostazioni; la tabella `gst_merchant_settings` resta senza
  Model. **Chi aggiorna deve lanciare `php forge update`**; un indirizzo già scritto
  nella vecchia pagina non si copia: va reinserito in Set Up.
- Il gestionale chiede `wonder-image/app` `^2.4.0-beta.6 || dev-main`: il
  pannello del cliente in ufficio (`OfficeCustomerPanel::update()`) cerca lo
  schema della tabella solo con `Table::has()` del core, arrivato in
  `2.4.0-beta.6`; tolto il ripiego su `Table::$list` per la beta.5.
  **Chi aggiorna deve avere il core almeno a `2.4.0-beta.6`.**

### Corretto

- Categorie: «Nessuna, sta in cima» si salva come `parent_id` NULL e non più
  come `0`, che la foreign key su `gst_categories` rifiutava (errore 1452 nel
  creare una categoria in cima, o nello spostarla in cima). Vale anche per
  `category_create` e `category_update` dell'MCP. In modifica, una categoria
  senza padre torna a spuntare «Nessuna, sta in cima» nell'albero.

- L'azione del menu ⋯ su una bozza d'ordine o un preventivo inesistente mostra
  la pagina 404 del backend invece di una pagina con il solo «Non trovato»:
  lancia `Wonder\Http\Exceptions\NotFoundHttpException` del core, che deve
  già contenere quella classe.

- `OrderOwner::claim` non prende più i carrelli (`stage = 'cart'`): un ospite
  che scrive l'email di un altro nel checkout lasciava un carrello senza utente
  con quell'email, che al primo ingresso del proprietario finiva sul suo
  account e poteva sostituire il suo. Ordini e preventivi passano come prima.
- Pagamento online: due conferme dello stesso intento (webhook e ritorno dal
  checkout, o due webhook) registrano un solo incasso e mandano una sola
  coppia di email. `OnlinePayments::succeeded()` gira in una transazione con
  la riga del pagamento bloccata (`findForUpdate`); le email partono dopo il
  commit.
- Abbonamenti: `StripeBilling` usa il client del core
  (`Stripe::clientConfig()`), con timeout, 2 retry e la versione API fissata.
- Webhook Stripe: il modulo aggiunge i suoi eventi (pagamenti e abbonamenti,
  `PaymentEvents::types()`) a `Connect::events()`. **Dopo l'aggiornamento va
  rifatto «Collega webhook»**, in test e in live, perché l'endpoint li riceva.
- Ordine da ufficio: il pannello del cliente legge e modifica solo schede
  cliente; un fornitore non passa, nemmeno dagli indirizzi.
- CSV: una sola regola anti-formula, `SpreadsheetText::cell()` del core, anche
  per l'export dei clienti di un lotto.

- `StockDocuments`: ripristinati sette metodi privati (`draft`, `locked`,
  `location`, `isStockLocation`, `assertProducts`, `log`, `decimal`) persi in
  un commit del piano 2 di G3; senza, carichi, scarichi e inventari andavano
  in errore. Nuovo `SelfCallsTest`: ogni `self::metodo()` di `src` deve avere
  il suo metodo.
- Pulizia dei dati di prova del catalogo: un articolo o un attributo di prova
  già cancellato dal backend che qualcosa usa ancora resta in silenzio, come
  prima di guardare anche le righe cancellate. Prima `--fresh` lo nominava a
  ogni giro fra i dati «ancora in uso» (per esempio i vecchi «Prova Colore» e
  «Prova Taglia»).
- Dati di prova del catalogo: un articolo o un attributo di prova cancellato dal
  backend (che lo segna e basta, tenendo il codice) non si rifaceva più e il
  comando lo saltava. Ora si toglie davvero, con le sue righe figlie, e rinasce
  da zero; `--fresh` toglie anche quelli cancellati. Se un multiprodotto o un
  articolo vero lo usa ancora resta, e il comando lo dice.
- Carichi, scarichi e inventari: un documento nuovo che non nasce (CSRF
  scaduto, sede sconosciuta, versione eliminata nel frattempo) riapre «Nuovo»
  con le righe, la sede, la causale e la nota appena scritte, i pezzi come
  erano (`StockDocumentForm::remember()` e `recall()`), invece di ripartire
  vuoto.
- Dati di prova dei documenti di magazzino: creazione e conferma in una
  transazione sola; `--fresh` toglie anche i documenti di prova eliminati dal
  backend, e un giro dopo un'eliminazione li rifà invece di fermarsi sul
  codice già preso.
- Scheda di un documento confermato o annullato: «da …» resta anche quando
  l'utente che l'ha confermato è stato eliminato.
- Guida *Carichi e scarichi*: il pezzo in più su una riga che c'è già lo dà
  solo il lettore; col clic il cursore va sui pezzi di quella riga.
- Ritiro in una sede che non è la principale: la merce si prenotava e si
  scaricava dalla sede di ritiro, che non è un magazzino, e il checkout
  rispondeva «non disponibile» (o, con la vendita senza giacenza, la sede di
  ritiro andava sotto zero). Ora la vendita esce sempre dalla principale
  (`Locations::forSale()`): il ritiro si fa dove il cliente preferisce e la
  merce ci arriva da lì. Il reso rientra nella sede scelta da chi lo registra,
  con *Più sedi* attiva (`Locations::forReturn()`).
- Bozza di carico, scarico e inventario: la ricerca per nome, *Aggiungi
  versioni*, *Salva bozza* e *Conferma* non rispondevano. Il core ricopia il
  contenuto dei `<wi-card>` al caricamento e gli ascolti legati prima andavano
  persi: l'editor ora parte con l'evento `loaded` del core e la pagina ascolta
  *Conferma* sul form.
- *Magazzino → Giacenze* dava errore SQL: `Query::Conditions(…, false)` del core
  toglie ogni `WHERE` dalla condizione, anche quello della sottoquery che escludeva i
  multiprodotti. Gli `id` dei multiprodotti ora si leggono prima, in PHP.
- *Registra reso* dava errore 500: `Returns::returned()` chiedeva il blocco delle
  righe anche quando la pagina leggeva soltanto; ora blocca solo dentro una
  transazione.
- Tabella *Righe* dell'ordine: il nome con un trattino lungo (o altre entità) si
  leggeva come codice, `&#8212;`; ora si decodifica e si escapa una volta sola
  (`GestionaleResource::escapeStored()`).
- Tag: il nome è obbligatorio anche lato server. Il form aveva già
  `required`, ma un nome di soli spazi passava e il tag nasceva con `name`
  NULL; ora `TagResource::mutateRequestValues()` toglie gli spazi ai lati e
  rifiuta il nome vuoto (`tag.name_required`), in creazione e in modifica.
  Nessuna migrazione: i tag già salvati senza nome restano, e alla prossima
  modifica chiedono un nome.
- Marchi e categorie: stesso controllo del nome dei tag, lato server. Il
  controllo ora sta in `GestionaleResource::requireName()`, usato da
  `BrandResource`, `CategoryResource` e `TagResource` (`brand.name_required`,
  `category.name_required`, `tag.name_required`). Nessuna migrazione.

### Sicurezza

- API di Resource del catalogo (marchi, attributi e valori, categorie,
  personalizzazioni, imballaggi, tipi fiscali, zone di spedizione): lo store
  del quick-create risponde solo a `api_internal_user`, mai al token di pagina.
  Ogni API di Resource dichiara le sue authority; senza, risponde 403.
- Nessuna API di Resource per coupon, campagne di sconto, corrieri e metodi di
  spedizione (`apiSchema()->enabled(false)`).
- Logo SVG dei marchi: ripulito dal core (`SvgSanitizer`); test sul modulo.
- Il modulo richiede `wonder-image/app` `^2.4.0-beta.4 || dev-main`.
