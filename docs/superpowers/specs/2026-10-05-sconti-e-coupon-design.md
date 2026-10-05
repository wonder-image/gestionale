# G6 ridotto — Campagne di sconto e coupon

- **Sotto-progetto:** G6 ridotto, terzo del percorso di consegna (D61), dopo G4 e G5
- **Stato:** disegno approvato il 2026-10-05 (sezioni 1, 2 e 3); due piani
- **Documento di riferimento:** [architettura](2026-09-11-gestionale-ecommerce-architettura-design.md)
  §4.5, §4.6, §4.7, §10.3 (D33, D34, D35, D61); [ordini e pagamenti](2026-09-29-ordini-e-pagamenti-design.md)
  (`LinePrice`, `OrderTotals`); [multiprodotto e personalizzazione](2026-10-01-multiprodotto-e-personalizzazione-design.md)
- **Dipende da:** G2 (categorie, tag, marchi, articoli), G4 (carrello, checkout,
  ciclo di vita, `LinePrice`, `OrderTotals`), G5 (confezioni e sovrapprezzi)

## Contesto

G4 ha preparato il posto e non l'ha riempito. `LinePrice` accetta già
`campaign_price` e uno sconto manuale di riga, `OrderTotals` ripartisce già uno
sconto sul totale in `order_discount_amount`, `gst_orders` ha `coupon_id` e
`coupon_code`, `gst_order_items` ha `discount_campaign_id`, `Codes` ha il
prefisso `dsc_` e la scheda cliente ha un segnaposto «Coupon assegnati». Le
funzionalità `discount_campaigns` e `coupons` sono dichiarate in
`config/features.php` con `release` G6.

G6 riempie i posti con **le campagne di sconto** (prezzi che cambiano per
categoria, tag, marchio o articolo in un periodo) e **i coupon** (codici che il
cliente digita). Le regole sono già scritte in §4.6 dell'architettura e qui non
si ripetono: questa spec fissa solo ciò che §4.6 lascia aperto, ciò che la
riduzione di D61 toglie e i punti dove G6 si innesta nel codice di G4.

Come per G5, nessuno schermo **digita** un codice in vetrina: il carrello del
negozio è E1c. G6 costruisce il motore che E1c userà, la gestione nel backend e
la lettura su ordini e scheda cliente; i dati di prova fanno nascere ordini con
campagne e coupon per vedere tutto nel pannello.

## Decisioni prese nel brainstorming

| Tema | Scelta | Alternative scartate |
|---|---|---|
| Sconto manuale di riga | **Solo motore e prova**: `LinePrice`, `Cart` e `OrderTotals` già lo calcolano e lo scrivono; G6 lo verifica con test e una riga di prova. L'inserimento da schermata è G9 | modifica dell'ordine dal backend prima della conferma; toglierlo del tutto |
| Tipi di coupon | **Percentuale, importo e spedizione gratuita.** Il buono a scalare (`store_credit`) resta nell'elenco dei tipi della colonna ma il modulo non lo offre | solo percentuale e importo; tutti e quattro |
| Selettore dei prodotti | **Tutto il catalogo, oppure categorie (con sottocategorie), tag, marchi e articoli, meno gli articoli esclusi**, in un solo componente usato da campagne e coupon | selettore ridotto; solo tutto il catalogo |
| Rifiniture di §4.6 | **Tutte dentro G6**: anteprima della campagna, coupon riservati a clienti, limite per cliente e solo primo ordine, avviso di sovrapposizione | rimandarne una o più |
| Piani | **Due: Campagne, poi Coupon.** Il selettore nasce nel Piano 1 e lo riusa il Piano 2 | tre piani; un piano unico |
| Utilizzi del coupon | **Si contano alla creazione dell'ordine** (`Checkout::place`), come la prenotazione della merce, e tornano disponibili se l'ordine scade o viene annullato. **Cambia la frase di §4.6** «contati alla conferma» | alla conferma: col pagamento online il limite si supera tra creazione e conferma |

## Design

### 1. Unità e flusso (approvata)

Due classi pure e quattro servizi, in `src/Support/Promotions/`. Le classi pure
non toccano il database: prendono numeri e restituiscono numeri o un motivo, e
sono quelle su cui i test dicono davvero qualcosa. I servizi scrivono, e ognuno
è l'unica porta della sua area.

| Unità | Cosa fa | Tocca |
|---|---|---|
| `ScopeMatcher` (pura) | dato un selettore e i fatti di un prodotto, dice sì o no | niente |
| `ProductScope` | legge dal database il selettore di una campagna o di un coupon e i fatti di un prodotto (categorie con gli antenati, tag, marchio, articolo) | `gst_discount_campaign_*`, `gst_coupon_*` |
| `CampaignPrice` (pura) | dal prezzo base, dal prezzo scontato e dalle campagne valide, il `campaign_price` | niente |
| `Campaigns` | campagne in corso ora per canale e prodotto, stato ricavato, anteprima, sovrapposizioni, dati per la vetrina | `ProductScope`, `CampaignPrice` |
| `CouponRules` (pura) | un coupon e un carrello: valido, oppure il motivo del rifiuto | niente |
| `Coupons` | applica e toglie il codice dal carrello, prende e rilascia gli utilizzi | `ProductScope`, `CouponRules`, `Cart` |

**Il flusso.**

1. `Cart::recalculate`, il punto unico del prezzo, chiede a `Campaigns` il
   `campaign_price` di ogni riga prodotto e lo passa a `LinePrice`.
2. Poi `Coupons` rivaluta il codice. Se non regge più (scaduto, esaurito, la
   spesa minima non c'è più) il coupon **esce dal carrello** con un avviso,
   come le righe non più vendibili.
3. `OrderTotals` riceve due novità: una spunta per riga («scontabile dal
   coupon», così spesa minima e sconto contano solo le righe adatte) e
   l'azzeramento della riga `shipping` quando il coupon è di spedizione gratuita.
   Il resto resta com'è.
4. `Checkout::place` prende l'utilizzo e scrive `coupon_id` e `coupon_code`
   sull'ordine; `Lifecycle::cancel` e `Expiry` lo rilasciano.

### 2. Dati e backend (approvata)

**Campagne** — funzionalità `discount_campaigns`, che richiede `orders`.

| Tabella | Colonne |
|---|---|
| `gst_discount_campaigns` | `code` (`dsc_`), `name`, `discount_type` (`percent`, `amount`), `discount_value`, `starts_at`, `ends_at` (vuoto = senza fine), `active`, `applies_to_all`, `exclude_sale_products`, `applies_online`, `applies_office`, `applies_pos`, `note` |
| `gst_discount_campaign_categories`, `…_tags`, `…_brands` | `discount_campaign_id` più `category_id`, `tag_id` o `brand_id` |
| `gst_discount_campaign_product_models` | `discount_campaign_id`, `product_model_id`, `is_excluded` |

**Coupon** — funzionalità `coupons`, che richiede `orders`.

| Tabella | Colonne |
|---|---|
| `gst_coupons` | `code` (quello che digita il cliente, unico senza distinzione tra maiuscole e minuscole), `name`, `discount_type` (`percent`, `amount`, `free_shipping`, `store_credit`), `discount_value`, `min_order_amount`, `applies_to_all`, `exclude_discounted_products`, `first_order_only`, `usage_limit`, `usage_limit_per_customer`, `starts_at`, `ends_at`, `applies_online`, `applies_office`, `applies_pos`, `active`, `note` |
| `gst_coupon_categories`, `…_tags`, `…_brands`, `…_product_models` | come le tabelle ponte delle campagne |
| `gst_coupon_customers` | `coupon_id`, `customer_id` (nessuna riga = tutti i clienti) |
| `gst_coupon_redemptions` | `coupon_id`, `order_id`, `customer_id`, `email`, `discount_amount`, `redeemed_at`, `released_at` |

- `duration` e `duration_months` (abbonamenti, G10) non nascono ora.
- Sull'ordine ci sono già `coupon_id` e `coupon_code`. Per la spedizione
  gratuita l'importo risparmiato sta in `coupon_redemptions.discount_amount`,
  perché non è uno sconto sulla merce e non entra in `discount_total`.
- Il canale è quello dell'ordine (`gst_orders.channel`). In G6 nasce solo
  `online`: le colonne `applies_office` e `applies_pos` esistono e il motore le
  rispetta, ma nessuno schermo le usa finché non c'è G9.
- Con le funzionalità spente non compare niente e gli ordini già fatti non
  cambiano.

**Il backend.** Due voci nell'area Promozioni.

- **Campagne di sconto.** Elenco con lo stato ricavato: programmata, in corso,
  terminata, disattivata. Il modulo ha il selettore dei prodotti (un solo campo
  del backend, lo stesso dei coupon), il bottone **Anteprima** (numero di
  prodotti ed esempi di prezzo prima e dopo) e, al salvataggio, un **avviso** se
  un'altra campagna attiva copre gli stessi prodotti. L'avviso non blocca: il
  calcolo sceglie comunque lo sconto maggiore.
- **Coupon.** Elenco, modulo con lo stesso selettore e i clienti riservati. In
  sola lettura la tabella degli **utilizzi**: ordine, cliente, importo,
  rilasciato o no.
- **Scheda cliente.** «Coupon assegnati» smette di essere un segnaposto e
  mostra i coupon riservati a quel cliente.
- **Vetrina.** Il gestionale mette a disposizione di E1b il prezzo base da
  barrare, la percentuale e la fine della campagna. La grafica è di E1b.
- **Dati di prova (`gestionale:demo`).** Tre campagne (in corso, programmata,
  terminata) e cinque coupon (percentuale, importo, spedizione gratuita,
  riservato, primo ordine), con qualche ordine che li usa.

### 3. Errori, casi limite e test (approvata)

**Rifiuti.** Gli errori di una persona sono `UserError` con un messaggio
tradotto per motivo, nel file di lingua: scaduto, non ancora valido, spesa
minima, esaurito, già usato da te, non è il tuo primo ordine, riservato ad
altri, nessun prodotto adatto, codice sconosciuto. Le classi pure non lanciano
mai: restituiscono il motivo.

**Casi limite.**

- **Campagna al 100 %.** `LinePrice` oggi scambia un `campaign_price` a zero
  per «nessuna campagna». Si cambia come per il prezzo a mano: vuoto = nessuna
  campagna, `0` = omaggio. Una piccola modifica a `LinePrice`, con il suo test.
- **Più campagne sullo stesso prodotto:** vince lo sconto maggiore; a parità la
  più vecchia. Una campagna vale per l'articolo intero e quindi per tutte le sue
  opzioni.
- **Confezioni:** la campagna sconta il prezzo della confezione; i sovrapprezzi
  di personalizzazione e delle opzioni restano a prezzo pieno (§4.6).
- **Coupon:** la spesa minima conta le righe adatte dopo le campagne; con
  `exclude_discounted_products` sono escluse le righe con `price_source` diverso
  da `base`; un coupon senza righe adatte è rifiutato. Importo fisso:
  `min(importo, righe adatte)`; percentuale: sulle righe adatte.
- **Spedizione gratuita senza riga di spedizione** (finché non c'è G7): il
  coupon è accettato e sconta zero. Si prova con una riga `shipping` inserita a
  mano.
- **Cliente da ospite:** limite per cliente e primo ordine si controllano
  all'applicazione se il cliente è noto, e di nuovo in `Checkout::place` con
  l'email. Se lì falliscono, nessun ordine nasce e il coupon esce dal carrello
  con l'avviso.
- **Limite totale:** `Checkout::place` blocca la riga del coupon (`FOR UPDATE`)
  dentro la transazione, quindi il limite regge anche con carrelli in
  contemporanea. Gli utilizzi tornano disponibili alla scadenza o all'annullo
  (`released_at`), **non** con un reso.
- **Funzionalità spente:** il carrello ignora campagne e coupon.

**Test.**

- *Unitari, sulle classi pure:* `ScopeMatcher` (tutto, categorie con
  sottocategorie, tag, marchi, esclusi, selezione vuota), `CampaignPrice`
  (percentuale, importo, mai sotto zero, parità, `exclude_sale_products`,
  omaggio), `CouponRules` (un test per ogni rifiuto), `OrderTotals` (righe non
  adatte, spedizione azzerata, resto dei centesimi), `LinePrice` con omaggio.
- *D'integrazione:* campagne per data, canale e sovrapposizione; carrello con
  campagna e coupon; utilizzo preso alla creazione e rilasciato all'annullo e
  alla scadenza; **due processi sull'ultimo utilizzo**, come per l'ultimo pezzo
  di G4; funzionalità spente; pagine del backend; scheda cliente; demo.
- *Documenti:* guide utente di campagne e coupon, una guida per sviluppatori,
  `CHANGELOG.md` e `TODO.md`.

## I due piani

1. **Piano 1 — Campagne.** Tabelle e Model, `ScopeMatcher`, `ProductScope` e il
   campo selettore del backend, `CampaignPrice` e `Campaigns`, la modifica di
   `LinePrice` (omaggio), l'innesto in `Cart::recalculate`, la pagina del
   backend con stato, anteprima e avviso, i dati per la vetrina, la verifica
   dello sconto di riga, dati di prova, guida.
2. **Piano 2 — Coupon.** Tabelle e Model, `CouponRules` e `Coupons`, la spunta
   per riga e la spedizione gratuita in `OrderTotals`, `Cart` (applica e toglie),
   utilizzi in `Checkout::place`, rilascio in `Lifecycle::cancel` ed `Expiry`,
   clienti riservati e limiti, la pagina del backend con la tabella degli
   utilizzi, la scheda cliente, dati di prova, guida.

## Fuori da G6

- **Listini cliente** (`customer_price_lists`): B2B, rimandati da D61. La classe
  `LinePrice` continua a saltare `price_list_price`.
- **Buono a scalare** (`store_credit`): la colonna lo ammette, il modulo no.
- **Durata del coupon per gli abbonamenti** (`duration`, `duration_months`): G10.
- **Più coupon nello stesso ordine** e **generazione in blocco di codici usa e
  getta** (§10.5).
- **Inserimento dello sconto manuale da schermata:** G9.
- **Il campo per digitare il codice e la grafica del prezzo barrato:** E1b, E1c.

## Revisione

Al termine di ogni piano: revisione finale del ramo e prova nel browser dei casi
nuovi (una campagna in corso con il suo prezzo, un coupon di ogni tipo, l'ordine
con coupon annullato che libera l'utilizzo).
