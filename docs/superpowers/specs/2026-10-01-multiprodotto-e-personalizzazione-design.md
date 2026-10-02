# G5 — Multiprodotto e personalizzazione

- **Sotto-progetto:** G5, secondo del percorso di consegna (D61), dopo G4
- **Stato:** disegno approvato il 2026-10-01 (sezioni 1, 2, 3 e 4); due piani
- **Documento di riferimento:** [architettura](2026-09-11-gestionale-ecommerce-architettura-design.md)
  §4.2, §4.3, §4.6, §4.7 (D23, D60); [scheda prodotto, secondo giro](2026-09-22-scheda-prodotto-secondo-giro-design.md)
  P63, §22.4, P106; [ordini e pagamenti](2026-09-29-ordini-e-pagamenti-design.md)
- **Dipende da:** G2 (catalogo, scheda dell'articolo, repeater e `quickCreate()`
  del core), G2b (giacenze, prenotazioni, `Levels`), G4 (carrello, checkout,
  `Allocation`, ciclo di vita, resi)

## Contesto

G4 ha preparato il posto e non l'ha riempito: `gst_product_models.type` accetta
`bundle` ma vale sempre `simple`, `gst_order_items` ha `parent_item_id`,
`customization` e `customization_surcharge` sempre vuoti, `LinePrice` somma un
sovrapprezzo che vale sempre zero, e `Cart` distingue già due righe dello stesso
prodotto con personalizzazioni diverse. Le funzionalità `bundles` e
`customizations` sono dichiarate in `config/features.php` con `release` G5.

G5 riempie i due posti. Sono due funzionalità quasi indipendenti, ciascuna con il
suo interruttore, e si consegnano in due piani: prima le **personalizzazioni**,
che hanno il disegno già scritto (§22.4 della scheda prodotto) e toccano poche
cose; poi il **multiprodotto**, che tocca prenotazioni, scarico, annullamento e
resi.

In G5 nessuno schermo **compila** una personalizzazione o **sceglie** le opzioni
di un multiprodotto: la pagina prodotto del negozio è E1b, e creare un ordine
dal backend è G9 (spec G4). G5 costruisce il motore che E1b userà, la gestione
nel backend, e la lettura su ordini, email e resi; i dati di prova fanno nascere
ordini personalizzati e multiprodotti per vedere tutto nel pannello.

## Decisioni prese nel brainstorming

| Tema | Scelta | Alternative scartate |
|---|---|---|
| Divisione | **Una spec e due piani**: prima Personalizzazioni, poi Multiprodotto | due spec separate; una spec e un piano unico |
| Tipi di campo | **Solo `text` e `choice`** (P106); un sì/no è una scelta a due opzioni, un testo lungo è un testo con `max_length` alto. Il tipo `file` resta lavoro futuro | `text` + `file`; l'elenco completo `text\|textarea\|select\|boolean\|number\|date\|file` dell'architettura |
| Perimetro verso chi compra | **Solo il motore**: `Cart` controlla i valori e calcola i sovrapprezzi, `Cart::contents()` li restituisce; E1b disegna i campi | campi in vetrina già in G5; modifica della personalizzazione dal backend su un ordine confermato |
| Prezzo del multiprodotto | **Prezzo proprio dell'articolo** più i sovrapprezzi delle opzioni; in più il **valore dei componenti**, calcolato da G5 e mostrato da E1b solo se l'articolo lo chiede | somma dei prezzi dei componenti meno uno sconto |
| Reso del multiprodotto | **Si rende la confezione**, e per ogni componente si sceglie se rientra in magazzino; il rimborso è quello della riga madre | reso di un singolo componente con ripartizione del prezzo |
| Composizione nella scheda | **Componenti a righe** con il repeater; **gruppi a righe** [Nome · Min · Max · Opzioni] con la finestra delle opzioni in JSON (P103); tutto con «Salva» | gruppi come card fuori dal form; testata di gruppo con campi nel repeater del core |
| Tipo di composizione | **Scelto sull'articolo**: prodotti fissi, a scelta del cliente, oppure fissi e a scelta | dedotto dai riquadri compilati |
| Righe figlie | **Nascono nel carrello** (`Cart::add`): prenotazione, scarico, resi e schede leggono righe che esistono già | figlie create alla conferma; nessuna figlia ed espansione in `Allocation` |

## Design

### 1. Dati e funzionalità (approvata)

**Personalizzazioni** — funzionalità `customizations`, che richiede `orders`.

| Tabella | Colonne |
|---|---|
| `gst_customizations` | `code` (`cus_`), `name` (interno), `label` (per il cliente), `help_text`, `kind` (`text`, `choice`), `max_length`, `surcharge` `DECIMAL(12,2)`, `active`, `position` |
| `gst_customization_options` | `customization_id`, `label`, `surcharge` `DECIMAL(12,2)`, `position` |
| `gst_product_model_customizations` | `product_model_id`, `customization_id`, `is_required`, `position` |

- `max_length` serve solo a `text`: obbligatorio, fra 1 e 1000. Le opzioni
  servono solo a `choice`: almeno due.
- Il sovrapprezzo di una scelta è quello della personalizzazione **più** quello
  dell'opzione scelta: «Confezione regalo +3 €» con l'opzione «Oro +2 €» fa 5 €.
- Il collegamento all'articolo è dell'articolo (`product_model_id`), come i
  fornitori: vale per tutte le sue varianti.

**Multiprodotto** — funzionalità `bundles`.

| Tabella | Colonne |
|---|---|
| `gst_bundle_components` | `product_model_id`, `product_id`, `quantity` `DECIMAL(12,3)`, `position` |
| `gst_bundle_groups` | `product_model_id`, `name`, `min_choices`, `max_choices`, `position` |
| `gst_bundle_group_options` | `bundle_group_id`, `product_id`, `surcharge` `DECIMAL(12,2)`, `position` |
| `gst_product_models` (colonne nuove) | `bundle_mode` (`fixed`, `choice`, `mixed`; vuoto per un articolo semplice), `show_components_value` (`true`/`false`, predefinito `false`) |

- **Deviazione dall'architettura (§4.2).** Le tabelle puntano al modello
  (`product_model_id`) e non a `bundle_product_id`: la composizione è
  dell'articolo, e un multiprodotto ha **una sola riga** in `gst_products`, che
  porta SKU, prezzo e prezzo scontato. Non ha varianti: le scelte di chi compra
  passano dai gruppi.
- `product_id` di componenti e opzioni è un prodotto vero, variante compresa
  («Maglia blu M»), e **mai un multiprodotto**: niente confezioni dentro
  confezioni.
- Ogni opzione scelta vale **un pezzo** per confezione. Un componente fisso ha
  la sua quantità.
- Lo stesso prodotto può comparire come componente fisso e come opzione: i pezzi
  si sommano quando si controlla la disponibilità.

**Righe d'ordine** (`gst_order_items`).

- Una colonna nuova, `bundle_option_id` (intero, predefinito 0), sulle righe
  figlie: 0 per un componente fisso, l'id di `gst_bundle_group_options` per
  un'opzione scelta. Serve a riconoscere due confezioni uguali nel carrello, a
  ricalcolare il sovrapprezzo, e a scrivere «Scelta: …» su scheda ed email.
- Le righe figlie hanno `type` `product`, `parent_item_id` della madre, prezzo
  zero, la stessa `tax_category_id` della madre, e nome, SKU e unità del
  componente **copiati** come per ogni riga.
- Sulla madre `customization_surcharge` è la **somma dei sovrapprezzi della
  riga**: personalizzazioni più opzioni del multiprodotto. `LinePrice` lo somma
  già al prezzo unitario; listini, campagne e prezzo scontato non lo toccano
  (§4.6).
- `customization` è un JSON con una lista di
  `{customization_id, label, value, option_id, surcharge}`: gli id servono al
  ricalcolo nel carrello, le etichette copiate all'ordine. Per un testo
  `option_id` è 0; per una scelta `value` è l'etichetta dell'opzione.

**Ricalcolo e cose che spariscono.**

- Nel **carrello** i sovrapprezzi si rileggono dall'anagrafica a ogni ricalcolo,
  come i prezzi. Dopo la conferma sono fissati sulla riga.
- Una personalizzazione **disattivata** o tolta dall'articolo, un'opzione
  eliminata, un componente o un'opzione spenti fanno **uscire la riga intera**
  (madre e figlie) dal carrello, e il nome finisce in `removed` come per un
  prodotto spento: il cliente lo vede, non trova un totale cambiato in silenzio.

**Funzionalità spente (D20).** I campi spariscono dal backend, i dati restano.
`Cart::add` rifiuta un multiprodotto con `bundles` spento e ignora le
personalizzazioni con `customizations` spento, rifiutando l'articolo se ne ha di
obbligatorie: un errore leggibile, non una riga a metà.

### 2. Backend (approvata)

**Catalogo → Personalizzazioni.** Elenco e form disegnati come gli Attributi
(G2a, piano 2), con il form compatto:

- nome interno ed etichetta per il cliente affiancati; il testo d'aiuto come
  tooltip;
- tipo, `max_length` e sovrapprezzo sulla stessa riga; `max_length` compare solo
  con `text`;
- riquadro opzioni [Etichetta · Sovrapprezzo] solo con `choice`, con il repeater
  del core;
- elenco con nome, tipo, sovrapprezzo, numero di articoli che la usano, stato.

Una personalizzazione legata ad articoli **non si elimina**: si disattiva.
L'errore dice quanti articoli la usano. Una disattivata non si propone più nella
scheda dell'articolo e fa uscire dai carrelli le righe che la usano.

**Scheda dell'articolo.**

- **Tipo**: in creazione un campo *Semplice* / *Multiprodotto*, solo con
  `bundles` acceso. Dopo il primo salvataggio si legge e non si cambia: un
  articolo semplice ha giacenze e movimenti che un multiprodotto non può avere.
- **Multiprodotto, cosa sparisce**: varianti e generatore, giacenza e carico
  iniziale, giacenza per sede e scorta minima, fornitori, lotti.
- **Multiprodotto, cosa resta**: SKU, prezzo, prezzo scontato, tipo fiscale,
  foto, categorie, tag, marchio, testi, peso e misure (sono quelli della
  confezione).
- **Multiprodotto, cosa si aggiunge**:
  - **Composizione**: *Prodotti fissi* · *A scelta del cliente* · *Fissi e a
    scelta* (`bundle_mode`). Mostra i riquadri che servono; al salvataggio i
    dati dei riquadri nascosti si scartano.
  - Riquadro **Componenti**: repeater [Prodotto · Quantità]; la tendina dei
    prodotti esclude i multiprodotti.
  - Riquadro **Gruppi di scelta**: righe [Nome · Min · Max · Opzioni]; «Opzioni»
    è un bottone con il riassunto («3 opzioni») che apre una finestra a righe
    [Prodotto · Sovrapprezzo]. Le opzioni viaggiano come JSON nella riga del
    gruppo e il server le rilegge con lo stesso codice, come la giacenza per sede
    (P103).
  - Interruttore **«Mostra il valore dei componenti»**.
  - Casella in sola lettura **«Confezioni disponibili»**, da `Bundles::available()`.
- **Riquadro «Personalizzazioni»**, sotto «Scheda tecnica», per articoli semplici
  e multiprodotti, solo con `customizations` acceso: righe
  [Personalizzazione · Obbligatoria], «Aggiungi» e «Nuova personalizzazione…» con
  il `quickCreate()` del core (P106).

**Controlli al salvataggio.**

- *Prodotti fissi*: almeno un componente. *A scelta*: almeno un gruppo. *Fissi e
  a scelta*: almeno uno di ciascuno.
- In ogni gruppo `0 ≤ min ≤ max ≤ numero di opzioni`, `max ≥ 1`, e lo stesso
  prodotto non compare due volte.
- Quantità dei componenti maggiori di zero.
- Un prodotto usato come componente o come opzione **non si elimina** e non si
  spegne in silenzio: l'eliminazione si ferma con un errore che nomina il
  multiprodotto (come P99). Il controllo si aggiunge a quello dei movimenti che
  già ferma l'eliminazione di un prodotto venduto.

**Elenchi.** Nell'elenco articoli un badge «Multiprodotto». Giacenze, movimenti e
avvisi di scorta non mostrano i multiprodotti, come già oggi (G2b).

**Ordine, email, resi.** Sotto il nome della riga compaiono le personalizzazioni
(«Incisione: Marco»); le righe figlie stanno rientrate sotto la madre, senza
prezzo, con «Scelta: Vino rosso» per le opzioni. Vale per la scheda dell'ordine
(`OrderSheet`, tabella delle righe), per le email (`OrderEmail`) e per il modulo
del reso.

### 3. Il motore (approvata)

**`Support\Catalog\Customizations`** (nuova).

`resolve(int $modelId, array $values): array` riceve
`[customization_id => testo oppure id dell'opzione]` e confronta i valori con le
personalizzazioni **attive** dell'articolo:

- un'obbligatoria vuota è un errore;
- un testo si ripulisce (spazi ai bordi, caratteri di controllo tranne l'a capo)
  e la sua lunghezza, contata in caratteri con `mb_strlen`, sta entro
  `max_length`;
- una scelta è un'opzione di **quella** personalizzazione;
- un id che non appartiene all'articolo è un errore;
- una facoltativa vuota non entra nella lista.

Restituisce `['fields' => list<…>, 'surcharge' => string]`. Gli errori sono
`UserError` con il codice dell'errore e l'id del campo, così E1b li mette sotto
il campo giusto.

`forModel(int $modelId): list` restituisce le personalizzazioni attive
dell'articolo in ordine, con opzioni, sovrapprezzi e obbligo: è quello che E1b
disegna.

**`Support\Catalog\Bundles`** (nuova).

- `resolve(int $productId, array $optionIds): array` controlla che il prodotto
  sia un multiprodotto, che ogni opzione appartenga a un gruppo di quell'articolo,
  che ogni gruppo abbia fra `min` e `max` scelte, e che la composizione rispetti
  `bundle_mode`. Restituisce le righe figlie **per una confezione**
  (`product_id`, `quantity`, `bundle_option_id`) e il sovrapprezzo delle opzioni.
- `available(int $modelId): float` sono le confezioni vendibili. È il minimo fra
  i componenti fissi (disponibile diviso quantità, per difetto) e, per ogni
  gruppo, la disponibilità della `min`-esima opzione più fornita (un gruppo con
  `min` zero non limita). Un prodotto vendibile senza giacenza (D60) non limita.
  Il disponibile è quello di `Levels::of()`, già al netto delle prenotazioni.
- `componentsValue(int $modelId): string` è la somma dei prezzi correnti
  (scontato se c'è, altrimenti base) dei componenti fissi per la loro quantità,
  più, per ogni gruppo, le `min` opzioni più economiche.
- `forModel(int $modelId): array` restituisce composizione, gruppi e opzioni con
  nomi e sovrapprezzi, per E1b.

**`Support\Orders\Cart`.**

- **`add()`** riceve `product_id`, `quantity`, `customization`
  (`[customization_id => valore]`) e, per un multiprodotto, `choices` (gli id
  delle opzioni). Il `customization_surcharge` in ingresso **non si legge più**:
  lo calcola il server. Passi:
  1. `Customizations::resolve()`, se l'articolo ne ha o se ne arrivano;
  2. per un multiprodotto `Bundles::resolve()`;
  3. disponibilità: per un articolo semplice come oggi; per un multiprodotto
     ogni pezzo richiesto (quantità della confezione per pezzi della confezione,
     sommati per prodotto) contro `Levels::of()`, salvo D60;
  4. la riga uguale si cerca per prodotto, firma delle personalizzazioni e, per un
     multiprodotto, insieme ordinato dei `bundle_option_id` delle figlie; se c'è
     se ne aumenta la quantità, altrimenti si creano madre e figlie.

  La disponibilità si controlla sulla riga che entra, come oggi; la difesa vera
  resta la prenotazione del checkout con `FOR UPDATE`.
- **`setQuantity()`** sulla madre riporta la quantità sulle figlie (pezzi per
  confezione per confezioni). `setQuantity()` e `remove()` su una figlia sono
  rifiutati con un errore.
- **`remove()`** sulla madre toglie anche le figlie.
- **`merge()`** sposta e fonde la confezione intera, con lo stesso criterio di
  riga uguale di `add()`.
- **`recalculate()`** riprezza la madre con i sovrapprezzi riletti, tiene le
  figlie a zero, e fa uscire la confezione intera se manca un pezzo (sezione 1).
- **`contents()`** restituisce le righe di primo livello, ciascuna con
  `customization` letta come lista e, per un multiprodotto, `children`. La pagina
  del carrello del negozio che c'è oggi vede così una confezione come una riga
  sola; mostrare personalizzazioni e composizione è lavoro di E1b/E1c.

**`Support\Orders\OrderLines`** (nuova): le righe che contano, in un punto solo.

- `goods(array $items): list` sono le righe che muovono merce: `type` `product`,
  `product_id` > 0, quantità > 0, **senza figlie**. Una madre di multiprodotto
  non ha giacenza e non entra mai in `Allocation`.
- `sold(array $items): list` sono le righe di primo livello (`parent_item_id` =
  0), per contare i pezzi e per la richiesta di reso.

Li usano `Checkout` (prenotazione), `Lifecycle` (scarico alla conferma, rientro
all'annullamento) e le statistiche del cliente (`CustomerStats`, pezzi nel
carrello). Oggi ognuno ha il suo filtro; senza un punto solo, una madre finirebbe
in `Allocation` per errore. `Allocation` non cambia.

### 4. Magazzino, resi, dati di prova e test (approvata)

**Magazzino.** Prenotazione al checkout, scarico alla conferma e rientro
all'annullamento passano per le righe figlie, con il codice di `Allocation` di
oggi. Un multiprodotto si vende se ogni pezzo richiesto è disponibile o vendibile
senza giacenza (D60).

**Resi.**

- Nel modulo del reso la confezione è **una riga**: quante confezioni tornano e
  il motivo. Sotto, i componenti con «Rientra in magazzino», proposto dal motivo
  come oggi (`ReturnRules::defaultRestock()`) e modificabile uno per uno.
- `Returns::register()` scrive una riga di reso per la madre con la quantità, e
  una per ogni figlia con la quantità proporzionale e il proprio `restock`. Il
  rientro passa da `Allocation::returnGoods()` per le figlie con `restock`.
- Il rimborso è quello della riga madre; un componente rotto dentro la confezione
  si gestisce con un rimborso parziale registrato a mano, senza rientro.
- Annullare un ordine dopo un reso parziale toglie quanto già reso, riga figlia
  per riga figlia, come fa oggi `Returns::returned()`.
- Le righe con personalizzazioni sono escluse dalla richiesta di reso **online**
  (art. 59 del Codice del Consumo): `ReturnRules::onlineReturnable()` lo dice per
  E1c. Dal backend il commerciante le registra sempre.

**Dati di prova** (catalogo demo e `OrdersDemo`).

- Due personalizzazioni: «Incisione» (testo, 20 caratteri, +5 €) e «Confezione
  regalo» (scelta Rossa / Blu, +3 €).
- Tre multiprodotti: uno a prodotti fissi, uno a scelta, uno fisso e a scelta.
- Ordini che li usano in stati diversi, compreso un reso di confezione con un
  componente che non rientra.

**Test**, come in G4: unitari senza database, d'integrazione sul sito di prova.

- `Customizations::resolve`: obbligatoria vuota, `max_length` con lettere
  accentate, opzione di un'altra personalizzazione, id estraneo, facoltativa
  vuota, personalizzazione disattivata.
- `Bundles::resolve`: min e max, opzione di un altro articolo, scelte con
  `bundle_mode` fisso.
- `Bundles::available` e `componentsValue`: componente fisso esaurito, gruppo con
  una sola opzione disponibile e `min` 2, componente vendibile senza giacenza,
  stesso prodotto fisso e opzione.
- `Cart`: una confezione e la sua quantità sulle figlie; due confezioni con scelte
  diverse su due righe, uguali su una; una figlia non si tocca; un componente
  spento fa uscire la confezione; il sovrapprezzo in ingresso è ignorato; il
  sovrapprezzo cambiato in anagrafica entra nel carrello.
- Ciclo completo: il checkout prenota i componenti e non la madre, la conferma li
  scarica, l'annullamento li rimette.
- Reso di una confezione con un componente scartato.
- Scheda dell'articolo: composizione e controlli al salvataggio; un prodotto usato
  come componente non si elimina; una personalizzazione in uso non si elimina.

**Piani.**

1. **Personalizzazioni**: tabelle, pagina *Personalizzazioni*, riquadro
   nell'articolo, `Customizations`, `Cart` (resolve, firma, ricalcolo),
   personalizzazioni su scheda dell'ordine, email e reso, `onlineReturnable()`,
   dati di prova, documentazione.
2. **Multiprodotto**: colonne e tabelle, tipo e composizione nella scheda,
   `Bundles`, righe figlie in `Cart`, `OrderLines` in `Checkout`, `Lifecycle` e
   statistiche, resi della confezione, badge e blocco dell'eliminazione, dati di
   prova, documentazione.

## Fuori da G5

- Il tipo di personalizzazione `file` (cartella non pubblica, estensioni,
  dimensione, pulizia dei carrelli abbandonati).
- I campi delle personalizzazioni e le scelte del multiprodotto sulla pagina
  prodotto del negozio (E1b), e la loro lettura nel carrello e nell'area cliente
  (E1c).
- Modificare la personalizzazione di una riga su un ordine confermato, e creare
  un ordine dal backend (G9).
- Il reso di un singolo componente con ripartizione del prezzo.
- Multiprodotti dentro multiprodotti.
- Il DDT, che leggerà le righe figlie (G9).
