# G2a-bis — La scheda prodotto semplice

- **Sotto-progetto:** revisione di G2a, prima di G2b
- **Stato:** da approvare
- **Documento di riferimento:** [G2a — Catalogo](2026-09-21-catalogo-design.md),
  [architettura](2026-09-11-gestionale-ecommerce-architettura-design.md) §4.2
- **Dipende da:** G2a chiuso (commit `1b34c73`)

## Contesto

G2a ha consegnato un catalogo completo e provato. È anche difficile da usare:
chi lo ha scritto fatica a compilarlo, e un commerciante non ci arriva.

Non è una sensazione vaga. La scheda ha **dieci riquadri** — Articolo,
Prodotto, Descrizioni, Categorie e tag, Attributi, Spedizione, Genera varianti
e prodotti, Varianti, Immagini, Prodotti — tutti aperti e tutti con lo stesso
peso visivo. Un cappello di lana ne usa tre. E sotto ci sono tre difetti
concreti:

1. **Le righe dei prodotti non dicono chi sono.** Il repeater mostra SKU, EAN,
   prezzo, scontato, stato. Con dodici prodotti si vedono dodici righe
   identiche: manca la colonna che dice "Blu / M".
2. **"Genera varianti e prodotti" chiede di conoscere il modello dati.** Due
   alberi affiancati, *Valori delle varianti* e *Valori dei prodotti*: per
   sapere dove spuntare bisogna sapere cos'è una variante.
3. **Il livello dell'attributo è una parola interna.** Il menu a tendina offre
   "Modello / Variante / Prodotto", e la scelta va fatta alla creazione
   dell'attributo, lontano dall'articolo che la subirà.

La gestione dei prodotti decide come il cliente percepisce l'intero gestionale:
è la pagina su cui passerà il suo tempo. Vale la pena rifarne l'aspetto adesso,
prima che il magazzino, gli ordini e la vetrina ci si appoggino sopra.

## Obiettivo

Una parola sola — **prodotto** — e una scheda che chiede solo quello che serve
a quell'articolo. Chi vende cappelli non deve vedere niente che parli di
varianti; chi vende magliette deve arrivare a dodici righe con prezzi e codici
senza aprire dodici pagine.

Nessuna funzione nuova per il negoziante, nessuna tolta. Cambiano le parole,
l'ordine, il numero dei riquadri e il generatore delle combinazioni.

## Non obiettivi

| Fuori da questa revisione | Dove |
|---|---|
| Duplica un articolo | dopo, se serve davvero |
| Import da listino del fornitore | fuori perimetro (D5) |
| Multiprodotto e personalizzazione all'acquisto | G5 |
| Sconti massivi | G6 |
| Giacenze in griglia | G2b |
| Vetrina e selettore pubblico | E1 |

## Design

### 1. Vocabolario e navigazione

Una parola sola nel pannello. Le tre tabelle restano come sono.

| Nel codice | Quello che legge il cliente |
|---|---|
| `ProductModel` | **Prodotto** — la scheda, la voce di menu, l'elenco |
| `ProductVariant` | mai nominata: quando è pubblica prende il nome dell'opzione che la genera ("Colori") |
| `Product` | una riga di **Versioni in vendita**, chiamata con i suoi valori ("Blu / M") |

Quando l'articolo ha una versione sola, nessuna di queste parole compare da
nessuna parte.

**Menu Catalogo:** `Prodotti · Categorie · Tag · Marchi · Attributi`.

- `ProductModelResource`: indirizzo `app/gestionale/prodotti`, titolo
  "Prodotti", `titleLabel()` e `textSchema()` riscritti di conseguenza.
- `ProductResource`: esce dalla navigazione con
  `navigationSchema()->enabled(false)`, indirizzo `app/gestionale/versioni`,
  titolo "Versione in vendita". Il suo elenco si filtra sull'articolo
  sovrascrivendo `querySchema()`, che il core rivaluta a ogni richiesta: la
  condizione legge `?prodotto=<id>` dalla query string. Resta raggiungibile perché una singola versione ha campi che nella
  griglia non stanno: codice del produttore, peso e misure proprie, ordinabile
  su richiesta con i giorni d'attesa, attributi suoi.

Quando arriverà G2b, le giacenze avranno il loro elenco con le colonne del
magazzino: è quello il posto per una lista piatta di articoli in vendita.

### 2. L'elenco

Alle colonne di oggi (nome, SKU, marchio, stato) se ne aggiungono tre:

- **miniatura** della prima foto pronta dell'articolo;
- **prezzo**, come intervallo quando le versioni hanno prezzi diversi
  ("da 19,90");
- **versioni**, quante ne ha.

### 3. La creazione: quattro campi

`create` mostra **Nome** (obbligatorio), **Categoria principale**,
**Prezzo**, **SKU**.
Nient'altro: niente descrizioni, niente spedizione, niente attributi.

Si salva e si atterra sulla **scheda piena dell'articolo appena creato**, che
nel frattempo si è portato dietro la sua versione unica (`Skeleton::forModel`,
già esistente). Prezzo e SKU finiscono sulla versione unica per la strada che
già c'è (`saveSoleProduct`).

### 4. La scheda: sette riquadri, due chiusi

| | Riquadro | Contenuto |
|---|---|---|
| 1 | **Prodotto** | Nome, SKU, stato, vetrina; prezzo, prezzo scontato ed EAN *finché la versione è una sola* |
| 2 | **Versioni in vendita** | Le opzioni da spuntare e la griglia. Assente se il sito non ha opzioni |
| 3 | **Foto** | Come oggi, con la colonna "Vale per" |
| 4 | **Descrizione** | Breve e lunga |
| 5 | **Dove si trova** | Marchio, categoria principale, categorie, tag |
| 6 | ⌄ **Scheda tecnica** | Attributi che descrivono. Chiuso; assente se non ce ne sono |
| 7 | ⌄ **Spedizione e fisco** | Unità, tipo fiscale, peso, misure, resi, si spedisce. Chiuso |

L'ordine segue la compilazione: chi è, com'è fatto in vendita, come si vede,
cosa si legge, dove sta, e in fondo le due cose che si toccano una volta
l'anno. Per il cappello la scheda è *Prodotto · Foto · Descrizione · Dove si
trova*.

I riquadri 6 e 7 usano `Elements\Components\Accordion`, che esiste nel core ma
non è mai stato usato dentro un form. **Da verificare come primo passo del
piano:** se il tema non lo regge, quei due restano `Card` normali in fondo alla
pagina e il resto del disegno non cambia.

### 5. Gli attributi: una domanda sola

Il menu a tendina "Livello" con "Modello / Variante / Prodotto" diventa **"Come
si usa"**, stessi tre valori nel database, nessuna migrazione:

| Valore salvato | Etichetta | Tooltip |
|---|---|---|
| `model` | Descrive l'articolo | Finisce nella scheda tecnica e non crea niente da vendere. Materiale, Composizione, Paese d'origine. |
| `variant` | Crea versioni con pagina e foto proprie | In vetrina ogni valore è un articolo a sé. Il Colore, nei negozi che lavorano così. |
| `product` | Crea versioni da scegliere nel carrello | Una pagina sola, il cliente sceglie lì dentro. La Taglia. |

`Attributes::LEVELS` cambia solo le etichette. La decisione si prende una volta
per negozio: dentro un sito la stessa opzione si comporta sempre allo stesso
modo.

### 6. Versioni in vendita: un elenco solo

I due alberi diventano **un campo solo**, `option_values`, un blocco per
opzione con i suoi valori — Colore, Taglia — senza mai dire a che livello
stiano. Al salvataggio le spunte si smistano per livello leggendo l'attributo.

Finché l'articolo ha una versione sola il blocco sta **chiuso**, sotto la riga
"Si vende in più versioni? (colori, taglie…)". Appena ne ha più di una si apre
e resta in evidenza. Vale la stessa riserva di §4: se l'`Accordion` non funziona
dentro un form, il blocco resta aperto in fondo alla scheda.

Le tre regole, nel tooltip:

- spuntare e salvare **crea le righe che mancano**;
- **togliere una spunta non cancella niente** — i prodotti venduti non
  spariscono per una svista;
- per eliminare una versione la si elimina dalla griglia.

**La griglia** ha per prima la colonna **Versione** (§7), poi SKU, EAN, prezzo,
scontato, stato. In testa al riquadro un pulsante
(`pageSchema()->actions('edit', …)`) apre `app/gestionale/versioni?prodotto=<id>`
per i campi rari. Se il repeater regge un
collegamento per riga, meglio: da verificare nel piano, con il pulsante come
ripiego.

### 7. Il nome della versione

`gst_products` prende una colonna **`name`**, riempita alla generazione con
l'etichetta della combinazione ("Blu / M", oppure "M" quando l'asse è uno solo)
e **modificabile** in griglia.

Non è solo per la griglia: il selettore della vetrina, la riga dell'ordine e
l'elenco delle giacenze di G2b vogliono tutti quell'etichetta senza rifare il
join sugli attributi. È una fotografia: rinominare un valore ("Blu" → "Blu
notte") **non** riscrive i nomi già generati.

### 8. Combinazioni a più assi

`Combinations::plan()` oggi ragiona su due liste piatte — un'opzione "variante"
per una "prodotto". Spuntare due opzioni dello stesso livello (Vita e Lunghezza
di un jeans) produce righe sbagliate, in silenzio.

Diventa: **N assi**, uno dei quali può essere quello con pagina propria.

```php
/**
 * @param list<array{id:int,label:string}> $variantValues  l'asse con pagina
 *        propria, vuoto quando l'articolo non ne usa
 * @param list<list<array{id:int,label:string}>> $axes  gli altri assi, uno per
 *        opzione spuntata
 * @param array{variants: array<int,int>, products: array<string,bool>} $existing
 * @return array{
 *     variants: list<array{value_id:int,label:string}>,
 *     products: list<array{variant_value_id:int,value_ids:list<int>,labels:list<string>}>
 * }
 */
public static function plan(array $variantValues, array $axes, array $existing): array
```

La chiave di una combinazione esistente passa da `"variante-prodotto"` a
`"variante:v1-v2-v3"` con gli id degli assi **ordinati**, così l'ordine delle
spunte non genera doppioni. `ProductAttributes::save('product', …)` scrive un
collegamento per ogni asse, non più uno solo.

Resta un limite, e questo si spiega in una riga: **una sola opzione può avere
pagina e foto proprie**. Due spuntate → rifiuto.

È il pezzo di logica nuova più grosso della revisione: classe pura, si prova
senza database.

### 9. L'aggiunta al core

`ResourcePagePresenter::redirectUrl()` conosce solo `create` e `list`. Serve il
caso `edit`:

```php
public function redirectUrl(string $action, int|string|null $id = null): string
```

`editUrl(int $id)` esiste già nel presenter; `ResourcePageController` ha già
`$insertId` (riga 100) prima del redirect (riga 129). La modifica è passare
l'id a `redirectToConfiguredPage()` e aggiungere il ramo.

Va rilasciata **insieme alle due che aspettano**: `7d6df162` (decimali) e
`81e1323f` (`deferResize`). Finché il sito di prova non fa `composer update`,
la creazione riporta alla lista: fastidioso, non bloccante.

### 10. Dati esistenti

- `gst_products.name`: colonna nuova. La riempie il generatore quando crea la
  riga. Per le righe nate prima non c'è nessun passaggio di migrazione: il
  gancio dei moduli (`ModuleDefaults::seed()`) sa solo inserire righe mancanti,
  e un comando apposta non si giustifica per zero installazioni in produzione.
  Dove il nome è vuoto la griglia mostra lo SKU, e salvando lo fissa.
- Il livello dell'attributo non cambia nel database: cambiano le etichette.
- Cambiano due indirizzi (`modelli` → `prodotti`, la versione a `versioni`). Non
  ci sono installazioni in produzione: nessun redirect di compatibilità.

### 11. Rifiuti

Tutti con `UserError`, l'unico tipo che il core trasforma in avviso invece che
in errore 500.

| Quando | Messaggio |
|---|---|
| Due opzioni con pagina propria spuntate | Un articolo può avere una sola opzione con pagina e foto proprie. |
| Si elimina l'ultima riga della griglia | Un articolo deve avere almeno una versione in vendita. |
| SKU o EAN già presi | come oggi |

## Validazione

**Senza database** (`php tests/run.php`):

- tre opzioni spuntate generano tutte le combinazioni, e rilanciare non
  duplica;
- l'ordine delle spunte non cambia le chiavi (niente doppioni);
- due opzioni con pagina propria vengono rifiutate;
- il nome si compone come atteso su uno, due e tre assi;
- le spunte si smistano per livello leggendo l'attributo.

**Con il sito, dentro una transazione annullata:**

- creare un articolo con quattro campi porta alla sua scheda;
- due colori e tre taglie fanno sei righe, con nomi giusti e SKU proposti;
- risalvare non crea niente;
- l'ultima riga non si elimina;
- un articolo senza opzioni spuntate non mostra griglia.

**Nel browser,** su `https://ecommerce.test/backend/`: compilare la scheda di un
cappello e quella di una maglietta come le compilerebbe un negoziante, e
contare i riquadri che si è dovuto leggere.

## Documentazione

- `docs/user/catalogo-modelli.md` → `catalogo-prodotti.md`, senza le tre
  parole. La tabella "Modello / Variante / Prodotto" che apre la guida oggi è
  esattamente ciò che vogliamo smettere di far leggere.
- `docs/user/catalogo-attributi.md`: la domanda nuova al posto del livello.
- `docs/dev/concetti/catalogo.md`: tiene la verità interna, perché chi scrive
  codice deve sapere che sotto ci sono tre tabelle.

## Decisioni di questa spec

| # | Decisione | Perché |
|---|---|---|
| S1 | Una parola sola: prodotto | Il negoziante non deve imparare un modello dati per caricare un cappello |
| S2 | L'elenco piatto esce dal menu | Modificare uno a uno è lungo; la griglia dentro la scheda fa lo stesso lavoro in un colpo |
| S3 | La pagina della singola versione resta, fuori menu | Codice produttore, misure proprie e attributi suoi non stanno in griglia |
| S4 | Creazione a quattro campi, poi la scheda | Nessun riquadro vuoto prima di aver deciso cos'è l'articolo |
| S5 | Sette riquadri, due chiusi | Dieci sezioni uguali non hanno gerarchia: tutto sembra obbligatorio |
| S6 | Il livello diventa "Come si usa" | Stesso dato, parole che un negoziante può scegliere senza sbagliare |
| S7 | Un elenco solo di opzioni da spuntare | Il livello lo sa il sistema: chi compila non deve saperlo |
| S8 | `gst_products.name`, modificabile | Dodici righe indistinguibili sono il difetto più grave di oggi; e l'etichetta serve a vetrina, ordini e giacenze |
| S9 | Combinazioni a N assi | Toglie una regola che non si può spiegare ("non due opzioni dello stesso tipo") |
| S10 | Una sola opzione con pagina propria | Limite spiegabile in una riga, e copre ogni negozio visto finora |
| S11 | Togliere una spunta non cancella | Un prodotto venduto non sparisce per una svista |
| S12 | Nessun redirect dai vecchi indirizzi | Non ci sono installazioni in produzione |

## Piani

Da scrivere dopo l'approvazione.
