# G2a — Catalogo

- **Sotto-progetto:** G2a, primo pezzo di G2 (l'altro è G2b: magazzino base e anagrafiche)
- **Stato:** da approvare
- **Documento di riferimento:** [architettura](2026-09-11-gestionale-ecommerce-architettura-design.md) §4.2, §3.4, §4.1
- **Dipende da:** G1 Fondamenta (codici, IVA, impostazioni, sedi, errori, hook)

## Contesto

G1 ha messo le fondamenta: codici, numerazioni, IVA, impostazioni, sedi, errori,
hook, test e guide. Non c'è ancora niente da vendere.

Il catalogo è la prima cosa che il commerciante vede riempirsi, ed è la base di
tutto il resto: il magazzino conta prodotti, gli ordini vendono prodotti, la
vetrina mostra modelli. Sbagliare qui si paga in ogni sotto-progetto dopo.

## Obiettivo

Un catalogo completo e usabile: marchi, categorie ad albero, tag, attributi,
modelli con le loro varianti e i loro prodotti, immagini, SKU, EAN e MPN.

Alla fine di G2a il commerciante deve poter caricare il suo catalogo vero, dal
negozio di abbigliamento (taglie e colori) all'alimentare (formati) alla
gioielleria (pezzo unico), senza che il pannello gli chieda cose che non gli
servono.

## Non obiettivi

| Fuori da G2a | Dove |
|---|---|
| Giacenze, movimenti, rettifiche | G2b |
| Clienti e fornitori | G2b |
| Multiprodotto e personalizzazione | G5 |
| Etichette con codice a barre | futura |
| Prezzi di listino, sconti massivi, coupon | G6 |
| Vetrina, filtri e ricerca pubblica | E1 |
| Import da file o da altri gestionali | fuori perimetro (D5) |

I prezzi (`price`, `sale_price`) sono **colonne di G2a**, perché stanno sul
prodotto e senza non si può nemmeno guardare una scheda; la logica dei prezzi
(listini, scaglioni, campagne) è G4/G6.

## Design

### 1. I tre livelli

| Livello | Cos'è | Esempio |
|---|---|---|
| **Modello** (`product_models`) | la scheda: nome, descrizione, brand, categorie, SEO | "T-shirt girocollo" |
| **Variante** (`product_variants`) | ciò che cambia l'aspetto: immagini proprie | "Blu" |
| **Prodotto** (`products`) | ciò che si vende e sta a magazzino: SKU, EAN, prezzo | "Blu / M" |

Un articolo senza varianti è **un modello con una variante e un prodotto**: la
variante esiste lo stesso, ma il pannello non la nomina. È la regola D20 —
semplice per chi è piccolo, completo per chi cresce — e vale in tre punti:

1. Un modello nuovo nasce già con una variante e un prodotto, senza chiederlo.
2. Finché la variante è una sola e senza attributi, la scheda non mostra il
   riquadro delle varianti: mostra direttamente i prodotti.
3. Finché il prodotto è uno solo, SKU, EAN e prezzo si vedono nel riquadro
   principale, non in una tabella di una riga.

### 2. Tabelle

Tutte con prefisso `gst_` e **nessuna sincronizzazione**: il catalogo è il
lavoro del commerciante, si scrive dove si lavora e non viaggia con il deploy.
È la differenza con aliquote e impostazioni, che sono configurazione.

| Tabella | Colonne | Note |
|---|---|---|
| `brands` | `code` (`bra_`), `name`, `slug`, `logo`, `description`, `position`, `visible` | |
| `categories` | `code` (`cat_`), `parent_id`, `name`, `slug`, `image`, `description`, `position`, `visible` | albero a profondità libera, con vincolo anti-ciclo |
| `tags` | `code` (`tag_`), `name`, `slug`, `image`, `visible` | |
| `product_models` | `code` (`mod_`), `brand_id`, `type` (`simple`/`bundle`), `tax_category_id`, `sku`, `unit` (default `pz`), `name`, `slug`, `short_description`, `description`, `weight`, `length`, `width`, `height`, `returnable`, `requires_shipping`, `visible`, `visible_online` | `type` c'è già ma in G2a vale solo `simple` |
| `product_model_categories` | `product_model_id`, `category_id`, `is_main`, `position` | una sola principale per modello |
| `product_model_tags` | `product_model_id`, `tag_id` | |
| `product_variants` | `code` (`var_`), `product_model_id`, `name`, `slug`, `position`, `visible` | |
| `products` | `code` (`pro_`), `product_model_id`, `product_variant_id`, `sku`, `ean`, `mpn`, `price`, `sale_price`, `min_stock_quantity`, `allow_backorder`, `backorder_lead_days`, `weight`, `length`, `width`, `height`, `position`, `active` | peso e misure vuoti valgono quelli del modello |
| `product_images` | `product_model_id`, `product_variant_id`, `file`, `alt`, `position`, `status` (`pending`/`ready`/`failed`), `processed_at`, `error` | variante vuota = immagine del modello; lo stato serve al resize in differita |
| `attributes` | `code`, `key`, `name`, `type` (`select`/`text`/`number`/`color`), `level` (`model`/`variant`/`product`), `unit`, `is_filterable`, `is_visible`, `group`, `position` | |
| `attribute_values` | `attribute_id`, `label`, `color`, `image`, `position` | solo per `type = select` o `color` |
| `product_model_attributes` | `product_model_id`, `attribute_id`, `attribute_value_id`, `value_text`, `value_number` | |
| `product_variant_attributes` | come sopra, su `product_variant_id` | |
| `product_attributes` | come sopra, su `product_id` | |

`min_stock_quantity`, `allow_backorder` e `backorder_lead_days` sono colonne di
G2a ma restano **invisibili** finché non arrivano magazzino (G2b) e vendita senza
giacenza (G4): nascono qui perché stanno sul prodotto e aggiungerle dopo
significherebbe rifare la scheda.

### 3. Codici

- `code` tecnico con prefisso, da `Support\Codes` (G1): `bra_`, `cat_`, `tag_`,
  `mod_`, `var_`, `pro_`. Generato all'inserimento, mai modificabile.
- `sku` su due livelli: quello del modello (facoltativo, es. `TSH-1234`) e quello
  del prodotto (es. `TSH-1234-BLU-M`). Unico nel proprio livello.
- Creando un prodotto in un modello che ha lo SKU, il pannello **propone** lo SKU
  del modello più i valori di variante e prodotto, e lo lascia modificare.
- `ean`: 8 o 13 cifre, scritto dal commerciante, mai generato; unico se compilato.
- `mpn`: codice del produttore, senza vincoli.
- Con un solo prodotto il pannello mostra solo lo SKU del prodotto.

### 4. Attributi

Un attributo dichiara **a che livello vive**, e questo decide tutto il resto:

| Livello | A cosa serve | Esempio |
|---|---|---|
| `model` | descrive l'articolo | Materiale: cotone |
| `variant` | distingue le varianti | Colore: blu |
| `product` | distingue i prodotti dentro una variante | Taglia: M |

- `type = select` usa i valori di `attribute_values`; `color` aggiunge il codice
  colore; `text` e `number` scrivono direttamente sul collegamento, con `unit`.
- `is_filterable` serve alla vetrina (E1) e ai filtri dell'elenco nel backend.
- `group` raggruppa gli attributi nella scheda ("Misure", "Materiali").
- Un attributo usato da un prodotto non si elimina: si nasconde.

**Attributi e nome della variante.** Il nome della variante resta un campo
scritto a mano (è quello che legge il cliente); gli attributi di variante sono
il dato strutturato. Il pannello propone il nome dai valori scelti, come per lo
SKU.

### 5. Immagini, con il resize in differita

Galleria sul modello e, per chi ha varianti con aspetti diversi, galleria sulla
variante. Se la variante non ha immagini valgono quelle del modello: la regola
sta in una classe pura (`Catalog\ProductImages::for()`), così vetrina e backend
rispondono uguale.

**Il commerciante non aspetta il resize.** Caricare venti foto da telefono
significa generare centinaia di file: il salvataggio ci mette minuti e il
pannello sembra bloccato. Qui il salvataggio scrive solo l'originale, e le
misure arrivano dopo.

- Il campo si dichiara **senza** `responsive()`: `uploadFiles()` salta il
  ridimensionamento e la scheda si salva subito.
- La riga di `product_images` nasce `pending`.
- Un lavoro in coda prende le righe `pending`, chiama `imageResize()` del core
  con le misure del sito, e segna `ready` (o `failed` con l'errore, che finisce
  anche in `error_reports`).
- **Finché una riga non è `ready` si mostra l'originale**: la scheda e la
  vetrina non aspettano, si vede solo un'immagine più pesante per qualche
  minuto.

Chi fa girare la coda:

| Come | Quando |
|---|---|
| `php forge gestionale:images` | sempre disponibile, anche a mano |
| Task dichiarato con `ModuleTasks` | quando arriva la gestione dei cron del core (`2.3.0`) |

Il comando lavora **a blocchi** (predefinito 20 immagini) e si può richiamare
finché la coda è vuota, così un cron ogni minuto non si accavalla con sé stesso.
Un'immagine che fallisce tre volte resta `failed` e non riprova da sola: la
riga si vede nella scheda con il suo messaggio.

### 6. Pagine del backend

Tutte sempre attive (3.4), nella sezione **Catalogo** del menu, per `admin` e
`administrator`.

| Pagina | Cosa fa |
|---|---|
| Modelli | l'elenco principale: ricerca per nome, SKU ed EAN, filtri per brand, categoria, tag e stato |
| Scheda del modello | riquadri: Dati, Descrizioni, Categorie e tag, Attributi, Immagini, Varianti, Prodotti |
| Prodotti | elenco piatto di tutti i prodotti, per cercare uno SKU o un EAN senza passare dal modello |
| Marchi, Categorie, Tag, Attributi | elenchi CRUD normali; le categorie con l'albero |

**Varianti e prodotti stanno dentro la scheda del modello**, come repeater: è
lì che si lavora, ed è l'unico modo per vedere insieme le righe di un articolo.
L'elenco "Prodotti" serve a trovare, non a modificare in massa. La scheda di un
prodotto singolo (attributi, misure, backorder) si apre dalla riga.

### 7. Indirizzi e visibilità

`slug` sta su modelli, varianti, categorie, marchi e tag: è l'indirizzo della
pagina, e lo useranno vetrina e sitemap (E1).

**Niente colonne SEO nel catalogo.** Titolo e descrizione per i motori di
ricerca li compone l'ecommerce da come è organizzato il sito, non il
commerciante prodotto per prodotto: sarebbero centinaia di campi lasciati vuoti
o scritti male. Se un giorno servirà l'eccezione per il singolo modello, si
aggiunge allora.

`visible` è la visibilità nel gestionale, `visible_online` quella nel negozio:
un prodotto può esistere per l'ufficio e non per il sito.

### 8. Dati di prova

`php forge gestionale:demo` (G1) si arricchisce del catalogo: un marchio, tre
categorie, due attributi (colore e taglia), tre modelli — uno semplice, uno con
varianti, uno con molti prodotti — con immagini finte. È quello che serve per
provare magazzino e ordini nei sotto-progetti dopo.

## Validazione

1. Test del modulo verdi, unitari e integrazione; CI verde.
2. `forge update` crea le tabelle; una seconda esecuzione non cambia niente.
3. Nel browser: si crea un modello senza varianti in meno di un minuto, e la
   scheda non nomina mai la variante.
4. Nel browser: si crea un modello con due colori e tre taglie, e i sei prodotti
   nascono con SKU proposti e modificabili.
5. Un EAN duplicato viene rifiutato con un messaggio chiaro; uno di 12 cifre pure.
6. Caricando dieci immagini la scheda si salva in un attimo, le righe nascono
   `pending` e `php forge gestionale:images` le porta a `ready`; nel frattempo
   la scheda mostra gli originali.
7. Una categoria non può diventare figlia di sé stessa né di una sua discendente.
8. `gestionale:demo` riempie il catalogo su un sito vuoto.

## Decisioni di questa spec

| # | Decisione |
|---|---|
| G2a.1 | Il catalogo **non si sincronizza**: è lavoro del commerciante, non configurazione |
| G2a.2 | La variante esiste sempre, anche quando è una sola; il pannello la nasconde |
| G2a.3 | Varianti e prodotti si modificano dentro la scheda del modello; l'elenco "Prodotti" serve a cercare |
| G2a.4 | Gli attributi nascono con il catalogo, con il livello dichiarato sull'attributo |
| G2a.5 | Nome della variante e SKU restano scritti a mano, con una proposta automatica |
| G2a.6 | Le colonne del magazzino (`min_stock_quantity`, `allow_backorder`) nascono qui ma restano nascoste fino a G2b/G4 |
| G2a.7 | Marchi, categorie, tag e attributi non si eliminano quando sono usati: si nascondono |
| G2a.8 | Le immagini si ridimensionano **in differita**: il salvataggio scrive l'originale, la coda genera le misure, e fino ad allora si mostra l'originale |
| G2a.9 | Niente colonne SEO nel catalogo: titolo e descrizione li compone l'ecommerce |

## Piani

1. **Tassonomie:** marchi, categorie (con albero e anti-ciclo), tag, con le loro pagine.
2. **Attributi:** attributi, valori, i tre livelli e le pagine.
3. **Modelli, varianti e prodotti:** tabelle, codici, SKU, EAN, la scheda con i repeater e l'elenco dei prodotti.
4. **Immagini in differita e dati di prova:** gallerie di modello e variante, regola dell'ereditarietà, coda del resize con comando e task, `gestionale:demo`, guide.
