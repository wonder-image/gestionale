# Caricamento del catalogo da file (gestionale#84)

Data: 2026-11-03 · Stato: approvata (punti aperti decisi il 2026-11-03)

## Il problema

Un cliente nuovo (ricambi moto usati) manda il catalogo in un foglio, in
italiano: centinaia o migliaia di righe con articoli, versioni, prezzi e
giacenze. Oggi si inserisce scheda per scheda. Serve un caricamento da file
che crei gli articoli nuovi, aggiorni quelli che ci sono già senza
duplicarli, mostri gli errori prima di scrivere, regga file grandi e si possa
annullare.

La #119 (clienti e listini da file) userà lo stesso flusso. Per questo il
lavoro ha **due livelli**: un motore che non sa niente di catalogo e un
tracciato del catalogo che ci si appoggia.

Fuori da questa issue: traduzioni (app#124), foto da archivio zip o caricate
dopo, creazione di multiprodotti, clienti e listini (#119).

## 1. Due livelli

### Motore — `Support/Import/*`

Non nomina tabelle del catalogo. Contiene:

- `Reader`: legge .csv (virgola o punto e virgola, UTF-8 con o senza BOM) e
  .xlsx (primo foglio) e restituisce le righe come testo. Riprende
  `PriceListTransfer::readFile()`.
- `Mapping`: abbina le intestazioni del file alle colonne del tracciato
  (alias, senza maiuscole, spazi e accenti) e dice quali restano scoperte.
- `Layout` (contratto): ogni tracciato lo implementa.
- `Importer`: il ciclo di vita del lavoro (caricamento, anteprima, blocchi,
  annullamento) sopra le tabelle `import_*`.
- `ImportTask`: l'attività dello scheduler che manda avanti i lavori.

```php
interface Layout
{
    public static function key(): string;          // 'catalog', domani 'customers'
    /** @return list<Column> chiave, intestazione, alias, obbligatoria, tipo */
    public static function columns(): array;
    /** Errori della riga senza scrivere: list<array{key, params}> */
    public static function validate(array $row, Context $context): array;
    /** Scrive la riga e dice cosa ha cambiato: list<Change> */
    public static function apply(array $row, Context $context): array;
    /** Rimette com'era un cambiamento */
    public static function undo(Change $change): void;
}
```

`Context` porta l'utente, il lavoro e una cache per il lavoro (SKU già
visti nel file, categorie e marchi già cercati), così la validazione di una
riga può sapere dei duplicati nelle righe prima.

`PriceListTransfer` resta com'è; lo sposterà sul motore la #119.

### Tracciato del catalogo — `Support/Catalog/Import/CatalogLayout`

Le colonne dell'esportazione prodotti (`ProductExport`), così un file
esportato, corretto e ricaricato torna indietro uguale. Più una colonna per
ogni attributo delle versioni (§4).

### Tracciati personalizzati — pannello per soli admin

Importazione ed esportazione leggono lo stesso **tracciato**: quali colonne,
in che ordine e con quale intestazione. Di serie c'è quello dell'export di
oggi. Da Set Up → Gestionale → «Tracciati file» (sezione `['admin']`, come
Funzionalità) l'integratore ne scrive altri per il cliente: un nome, il
tipo (`catalog`, domani `customers`), «predefinito» sì/no e un elenco
ordinabile di righe *colonna del tracciato → intestazione nel file* («SKU»
→ «Cod. ricambio»). Le colonne possibili, attributi compresi, le dà
`Layout::columns()`.

- Tabelle `import_layouts` (nome, layout, predefinito) e
  `import_layout_columns` (colonna, intestazione, posizione), con il
  repeater del core.
- L'esportazione .xlsx/.csv dei prodotti usa il tracciato predefinito del
  catalogo, se c'è; senza, quella di sempre, uguale al byte.
- L'importazione propone il predefinito e si può sceglierne un altro: le
  sue intestazioni sono alias in più che vincono sugli altri.
- Il motore non sa niente del catalogo: `Profile::for($layout)` restituisce
  le colonne scelte, e #119 avrà i suoi tracciati gratis.

## 2. Persistenza

Tre tabelle nuove del modulo (Model in `src/Models/Import/`):

- `import_jobs`: layout, nome del file originale, percorso del file, mappatura
  (JSON), stato `uploaded → validated → running → done | failed`, più
  `undone`; contatori (righe, valide, con errori, fatte, create, aggiornate),
  utente, date di inizio e fine.
- `import_rows`: job, numero di riga del file, dati (JSON, già mappati),
  stato `pending | ok | error | skipped | done`, errori (JSON di chiavi e
  parametri).
- `import_changes`: job, riga, tabella, id, azione `created | updated |
  stock`, valori di prima (JSON), id del movimento di magazzino.

Il file si legge **una volta**, al caricamento, e finisce in `import_rows`:
anteprima, blocchi e annullamento leggono da lì. Il file sta sotto
`storage/imports/` (non in `assets/upload/`, che è pubblico) e si cancella a
lavoro finito.

## 3. Mappatura

Ogni colonna del tracciato ha alias: l'intestazione dell'esportazione
(«Codice articolo», «Prezzo (IVA inclusa)»), la chiave inglese (`sku`,
`price`) e qualche sinonimo comune («Codice», «Descrizione», «Quantità»,
«Giacenza»). Le intestazioni riconosciute si abbinano da sole; le altre
compaiono in un passo di mappatura dove si sceglie la colonna o «Ignora».
Il file di un cliente non avrà le nostre intestazioni: questo passo non è
facoltativo.

Il prezzo nel database è quello scritto in scheda, ivato o no secondo
l'impostazione `catalog_prices_include_tax`. Se l'intestazione dice «IVA
inclusa» e il negozio tiene i prezzi senza IVA (o il contrario), la colonna
viene rifiutata alla mappatura invece di caricare prezzi sbagliati.

## 4. Riconciliazione

Regola generale: **cella vuota = non toccare**. In aggiornamento si scrive
solo quello che la riga dice. In creazione una cella vuota prende il default
della scheda.

Chiavi: la versione (`Product`) si riconosce dallo **SKU**, l'articolo
(`ProductModel`) dal **Codice articolo**.

| Il file ha | Nel gestionale | Succede |
|---|---|---|
| SKU esistente | — | aggiorna quella versione e il suo articolo |
| SKU esistente + Codice articolo di un altro articolo | — | errore: non si sposta una versione fra articoli |
| SKU nuovo + Codice articolo esistente | — | nuova versione in quell'articolo |
| SKU nuovo + Codice articolo nuovo | — | nuovo articolo (`Skeleton`) con la sua versione |
| solo Codice articolo, nuovo | — | nuovo articolo, SKU = Codice articolo (come `Skeleton`) |
| né SKU né Codice articolo | — | errore |
| SKU ripetuto nel file | — | errore sulla seconda riga |
| ID | — | ignorato: si riconcilia per codice, non per id |

Righe dello stesso articolo nuovo si raggruppano per Codice articolo: la
prima crea l'articolo, le altre aggiungono versioni. L'articolo nuovo
prende nome, marchio, categorie e descrizioni dalla prima riga che li ha.

Le altre colonne:

- **Articolo**, **Descrizione breve**, **Descrizione**, **Marchio**,
  **Unità**, **Peso**, **Pubblicato**, **Online**: sull'articolo.
- **Opzione**, **EAN**, **MPN**, **Prezzo**, **Prezzo scontato**,
  **Attivo**, **Vendibile senza giacenza**: sulla versione. Scontato ≥
  prezzo è un errore, come in `product_update`.
- **Marchio**: per nome; uno sconosciuto si crea.
- **Categorie**: percorsi separati da ` | ` («Moto > Freni»); un livello che
  manca si crea, la prima è la principale. In aggiornamento la colonna
  piena **sostituisce** le categorie dell'articolo.
- **Tipo**: «Multiprodotto» su una riga nuova è un errore (i componenti non
  stanno nel file); su una esistente si ignora.
- **Disponibili**: la giacenza **da raggiungere**, non da aggiungere
  (`Adjustment::fromTarget`), con un movimento di magazzino motivato
  «Caricamento da file». «Illimitata» e i multiprodotti si ignorano.
- Booleani: Sì/No, Si, Vero/Falso, 1/0, x, true/false.
- Numeri: virgola o punto decimale, con o senza migliaia (`Numbers`).
- **Link**: ignorato (lo calcola il sito).
- **Attributi**: una colonna per attributo del catalogo a livello
  `variant` o `product` («Colore», «Taglia»), intestazione = nome
  dell'attributo. Il valore si cerca per nome fra i valori dell'attributo;
  uno sconosciuto si crea (attributi a scelta) o è un errore (numerici
  fuori formato). Su una versione nuova gli attributi `variant` scelgono la
  variante (righe dello stesso articolo con gli stessi valori `variant`
  stanno nella stessa variante, una nuova si crea con `Generator::newVariant`),
  quelli `product` si scrivono sulla versione. Su una versione esistente
  gli attributi pieni si sovrascrivono; la variante non cambia (cambiarla
  sposterebbe le foto). In esportazione le stesse colonne escono piene.
  **Opzione** resta il nome della versione; vuota su una nuova, si compone
  dai valori («Rosso / M»).

## 5. Anteprima

Al caricamento ogni riga passa da `Layout::validate()` senza scrivere niente
e prende `ok` o `error`. La pagina mostra: righe totali, da creare, da
aggiornare, con errori, e l'elenco degli errori col numero di riga del file
e la colonna. **Un errore blocca tutto**: finché c'è anche una riga con
errori «Avvia» resta spento e si ricarica il file corretto. Gli avvisi (una
foto con indirizzo strano) non bloccano.

Limite: 50.000 righe per file.

## 6. Esecuzione a blocchi

Una funzione sola: `Importer::step($jobId, $size = 100)` prende le prossime
righe `ok`, ognuna in una `Transaction` sua (una riga storta non porta via
il blocco), aggiorna i contatori e chiude il lavoro quando non resta
niente. Un `NamedLock` per lavoro impedisce due blocchi insieme.

La chiamano in due:

- la pagina dell'importazione, che finché è aperta chiama
  `POST import/step` in ciclo e mostra l'avanzamento dai contatori;
- l'attività `gestionale.import` dello scheduler (ogni minuto, accesa), per
  i lavori lasciati a metà quando si chiude la pagina.

Così un file grande va avanti anche senza cron, e col cron va avanti anche a
pagina chiusa.

## 7. Annulla

Su un lavoro `done` (o fermato a metà), «Annulla importazione» percorre
`import_changes` all'indietro:

- `created` → eliminazione logica (`deleted = true`) della riga creata;
- `updated` → rimette i valori di prima;
- `stock` → movimento opposto, motivo «Annullamento caricamento».

Rimette la fotografia presa dall'importazione: **non** cerca le modifiche
fatte a mano dopo, che vengono sovrascritte dai valori di prima. La pagina
lo dice nella conferma. Si può annullare un lavoro solo una volta.

## 8. Foto

**Immagine**: uno o più indirizzi `https://` separati da ` | `. In
esecuzione si scarica il file (timeout breve, solo immagini, dimensione
massima) in `ProductImages::folder()` e si crea un `ProductImage` `pending`
sull'articolo; le misure le fa `ImageQueue` come per le foto caricate a
mano. Una foto che non si scarica non ferma la riga: diventa un avviso del
lavoro. In aggiornamento una foto con lo stesso indirizzo già caricata non
si riscarica.

## 9. Pagina e permessi

Una pagina custom «Importa catalogo» (pulsante nella lista prodotti accanto
a «Esporta»), con i passi carica → mappa → anteprima → avanzamento, e
l'elenco degli ultimi lavori con «Annulla». Permesso: quello di modifica dei
prodotti. Nessuna funzionalità nuova da accendere.

File condivisi toccati: `lang/*` per le chiavi `import.*`, una riga di
`CHANGELOG.md`, la registrazione dell'attività. Niente in
`config/features.php`, `config/permissions.php`, `Seeding/Defaults.php`.

## 10. Test

- Senza database: `Mapping` (alias, accenti, colonne scoperte, prezzo IVA
  incoerente), lettura di csv e xlsx, validazione delle celle.
- Con database: riconciliazione (aggiorna senza duplicare, SKU su articolo
  diverso, duplicati nel file, cella vuota che non tocca), righe con errori,
  un file di 250 righe fatto in 3 blocchi con `step()`, annullamento che
  rimette prezzi, giacenze e righe create.

## Decisioni sui punti aperti (2026-11-03)

1. **Righe con errori**: bloccano l'intero caricamento (§5).
2. **Attributi**: una colonna per attributo (§4); importazione ed
   esportazione si personalizzano con i tracciati del pannello admin (§1).
