---
icon: truck
---

# Fornitori e costi d'acquisto

Funzionalità `purchasing` (*Acquisti*). Oggi porta due cose: il ruolo
**fornitore** nella rubrica e, per ogni articolo, **da chi si compra, con
quale codice e a quanto**, con l'opzione che fa eccezione (P95). Documenti di
carico, valore del magazzino e costo aggiornato dai carichi arriveranno dopo, e
leggeranno le stesse tabelle.

Come la scheda dell'articolo e quella dell'opzione scrivono i legami sta in
[Catalogo](catalogo.md#i-fornitori-dellarticolo). Qui c'è quello che vale per
tutti: le tabelle, le regole e chi le fa rispettare. Con la funzionalità
bloccata non si vede e non si scrive niente, e i legami salvati restano dove
sono.

## Le due tabelle

I fornitori stanno **sull'articolo**: una taglia in più non cambia da chi si
compra. L'opzione ha una tabella sua solo per l'**eccezione** — un altro
fornitore, o lo stesso con un altro codice o un altro costo.

| Tabella | Model | Il padre | Cosa dice |
|---|---|---|---|
| `gst_product_model_suppliers` | `Models\Catalog\ProductModelSupplier` | `product_model_id`, chiave esterna su `gst_product_models` | i fornitori dell'articolo, validi per tutte le sue opzioni |
| `gst_product_suppliers` | `Models\Catalog\ProductSupplier` | `product_id`, chiave esterna su `gst_products` | le eccezioni di una singola opzione |

Le altre colonne sono le stesse:

| Colonna | Note |
|---|---|
| `supplier_id` | la scheda della rubrica, chiave esterna su `gst_contacts` |
| `supplier_sku` | il codice con cui il fornitore lo chiama, fino a 100 caratteri |
| `cost` | `DECIMAL(12,4)` dichiarato con `Columns::decimal()`; `NULL` vuol dire «non lo so» |
| `position` | l'ordine delle righe nella pagina |

Indici `ind_model` (o `ind_product`) e `ind_supplier`. `syncSchema()` torna
`null`: i costi d'acquisto sono lavoro di chi vende, non configurazione, e non
viaggiano con il deploy. Un **preferito non c'è** (P98): l'ordine delle righe
è l'unico ordine, e `is_preferred` se n'è andata.

Tre scelte da sapere prima di toccarle:

1. **È il costo di oggi, non storia.** Lo storico sta nell'`unit_cost` dei
   movimenti. Per questo un legame si cancella **davvero**, quando se ne va la
   riga, l'opzione o l'articolo, e non resta niente in `deleted = 'true'` a
   tenere ferma la chiave esterna del fornitore.
2. **Vuoto è `NULL`, non zero** (P91). Uno zero farebbe del fornitore il più
   conveniente e abbasserebbe il valore del magazzino. Nella scheda il costo si
   scrive con due decimali; nella tabella ne ha quattro, come il costo dei
   movimenti, e un salvataggio che non cambia il numero arrotondato non li
   perde.
3. **Nessun indice unico su padre e fornitore** (P93). Il repeater del core
   prima scrive e poi toglie: uno scambio di righe inciamperebbe a metà
   salvataggio. I doppioni li ferma la scheda, che può spiegarlo con una frase
   (`product.supplier_duplicate`).

**Chi vale per un'opzione** lo dice `ProductSuppliers::effective($modelRows,
$optionRows)`, pura: fornitore per fornitore vince la riga dell'opzione, i
fornitori dell'articolo senza eccezione valgono anche per lei, e un fornitore
che ha solo l'opzione si aggiunge in coda. L'ordine è quello dell'articolo,
poi le righe solo dell'opzione. Oggi la usano i test e la riga di contesto
della scheda dell'opzione (vedi
[Catalogo](catalogo.md#i-fornitori-dellarticolo)); domani gli ordini ai
fornitori.

## `ProductSuppliers`

`Support\Purchasing\ProductSuppliers` è l'unico posto con le regole dei
legami. I metodi vanno a coppie: quello con `Model` nel nome lavora su
`gst_product_model_suppliers`, il gemello su `gst_product_suppliers`.

| Metodo | Pura | Cosa fa |
|---|---|---|
| `normalize($rows)` | sì | toglie le righe vuote, legge il costo come lo scrive una persona («12,50», «1.234,50 €»), vuoto a `null` |
| `isEmptyRow($row)` | sì | niente fornitore, niente codice, niente costo; l'`id` del repeater non conta |
| `effective($modelRows, $optionRows)` | sì | i fornitori che valgono per un'opzione (vedi sopra) |
| `assertValid($rows, $ids, $labels)` | sì | i rifiuti, sulle righe **grezze** (vedi sotto) |
| `modelLinksFor($modelIds)` / `linksFor($productIds)` | no | i legami per articolo, o per opzione, in ordine di posizione, già normalizzati |
| `syncModel($modelId, $rows)` / `sync($productId, $rows)` | no | scrivono i legami di un articolo, o di un'opzione, com'erano nella pagina |
| `dropForModels($modelIds)` / `dropFor($productIds)` | no | tolgono i legami di articoli, o di opzioni, che stanno per sparire |
| `dropRemovedOptions($modelId)` | no | toglie le eccezioni delle opzioni che la griglia ha messo nel cestino; la chiama `ProductModelResource::saveExtras()` a ogni salvataggio dell'articolo |
| `dropForRemovedModels($contactId)` / `dropForRemovedProducts($contactId)` | no | tolgono i legami di un fornitore con articoli, o opzioni, non più in vendita |
| `countForSupplier($contactId)` | no | su quanti articoli e opzioni in vendita un fornitore ha un legame, **sommando le due tabelle** |

`MAX_COST` (99.999.999,9999) e `SKU_MAX_LENGTH` (100) sono i limiti delle
colonne, e li usano anche le schede per il `maxLength()` del codice.

`assertValid()` lavora sulle righe come sono arrivate, perché un codice o un
costo senza fornitore si deve vedere, non sparire:

| Chiave | Quando |
|---|---|
| `product.supplier_missing` | codice o costo scritti, fornitore vuoto |
| `product.supplier_invalid` | un fornitore che la pagina non propone: eliminato, non più fornitore, o disattivato e mai legato |
| `product.supplier_sku_too_long` | un codice del fornitore oltre cento caratteri (`SKU_MAX_LENGTH`) |
| `product.supplier_cost_invalid` | un costo scritto che non è un numero («12..5»): non diventa «non lo so» in silenzio |
| `product.supplier_cost_negative` | costo sotto zero |
| `product.supplier_cost_too_high` | un costo oltre `MAX_COST`, 99.999.999,9999: quello che tiene `DECIMAL(12,4)` |
| `product.supplier_duplicate` | lo stesso fornitore due volte; `{{supplier}}` è il suo nome |

I limiti della colonna si controllano qui, prima di scrivere: il core salva
l'articolo o l'opzione prima dei legami, e un rifiuto di MySQL a metà
lascerebbe la pagina salvata a metà, con un errore in inglese.

Nelle due schede le righe le scrive il **repeater del core** (`RepeaterRelation`
con `softDelete(false)`): `syncModel()` e `sync()` servono a chi scrive senza
un form, come i dati di prova e i test. Confrontano **per fornitore**: chi
c'era si aggiorna, chi manca nasce, chi non c'è più se ne va davvero. La
posizione è l'ordine delle righe, il costo vuoto diventa `NULL`, e tutto sta in
una `Transaction`. Se trovano righe in `deleted = 'true'` (nessuno ce le mette,
ma può capitare) le tolgono con le altre. Un salvataggio che fallisce è un
guasto, non una risposta: lancia una `RuntimeException` e la transazione
riporta indietro tutto.

**Un articolo è in vendita** quando non è stato eliminato; **un'opzione**
quando né lei né il suo articolo lo sono stati. L'elenco degli articoli li
mette in `deleted = 'true'` senza toccare le opzioni, e un articolo nel cestino
non deve tenere fermo un fornitore: `countForSupplier()`,
`dropForRemovedModels()` e `dropForRemovedProducts()` guardano tutti e due i
livelli (`liveModels()` e `liveProducts()`, sottoquery con il `JOIN` sui
modelli).

Le `drop…()` passano da `deleteWhere()`, che scrive il `WHERE` da sé: il core
prende così com'è una condizione che contiene già un `WHERE`, e quello della
sottoquery lo ingannerebbe. Senza tabella o senza database tutti i metodi che
leggono tornano vuoto invece di rompersi.

## `Contacts::supplierOptions()`

I fornitori da proporre in una tendina, `id => nome`, in ordine naturale di
nome:

```php
Contacts::supplierOptions();          // gli attivi
Contacts::supplierOptions([7, 12]);   // gli attivi, più il 7 e il 12 anche se non lo sono
```

Sono le schede con `is_supplier = 'true'` e non eliminate, **attive** oppure
fra quelle di `$keepIds`: i fornitori già legati a quello che si sta
modificando. Senza, la tendina posterebbe vuoto e staccherebbe un fornitore
solo perché è stato messo su «Non attiva». Un fornitore tenuto così compare con
«(non attivo)» accanto al nome. Senza database torna `[]`.

Chi la chiama:

| Dove | `$keepIds` |
|---|---|
| `ProductModelResource::supplierChoices()` | i fornitori legati all'articolo (`modelLinksFor()`) |
| `ProductResource::supplierCardChoices()` | i fornitori legati a quell'opzione (`linksFor()`) |

Le scelte sono le stesse per tutte le righe del riquadro (P92): un fornitore
non attivo si propone, e si accetta, solo dove è già legato, e se ne va quando
lo si toglie. Senza nessuna scelta la scheda dell'articolo mostra la nota
«Nessun fornitore da proporre» invece del repeater; la scheda dell'opzione
mette la nota sopra il repeater, che resta.

## Un fornitore in uso non si toglie

Un legame punta alla scheda della rubrica con una chiave esterna, e una scheda
che non è più fornitore sparirebbe dalla tendina degli articoli e delle opzioni
che comprano da lei: al primo salvataggio la perderebbero. Per questo
`CustomerResource` (e `SupplierResource`, che la estende) rifiuta due cose
quando `countForSupplier()` — le due tabelle insieme (P99) — è più di zero:

| Cosa | Dove | Chiave |
|---|---|---|
| eliminare la scheda | `assertDeletable()`, su tutte e due le porte (elenco ed eliminazione dalla scheda) | `contact.supplier_in_use`, con `UserError::refusal()` |
| togliere il ruolo di fornitore | `mutateRequestValues()`, solo su una scheda salvata che era fornitore | `contact.supplier_role_in_use`, con `UserError::make()` |

L'eliminazione è rifiutata **anche con `purchasing` bloccata**: il costo resta
salvato e tornerà quando la si riaccende. Il ruolo invece, a funzionalità
bloccata, non cambia comunque: il campo Ruolo non si stampa e
`mutateRequestValues()` lascia le due colonne come sono. La strada giusta, in
tutti e due i casi, è la scheda su «Non attiva»: i legami restano e le schede
che li hanno continuano a proporre quel fornitore.

Superato il controllo, `deleteRecord()` toglie prima i legami del fornitore con
articoli e opzioni che non sono più in vendita (`dropForRemovedModels()`, poi
`dropForRemovedProducts()`): nessuno li vede più, e la chiave esterna li
terrebbe fermi. L'elenco, che chiama solo `assertDeletable()` e mette la
scheda nel cestino, non ne ha bisogno.

## Chi toglie i legami

| Quando | Chi |
|---|---|
| una riga tolta dal riquadro «Fornitori» dell'articolo o dell'opzione, o svuotata | il repeater del core, con `softDelete(false)`: una riga vuota non gli arriva (`prepareRepeaterRows()`), e lui toglie quelle sparite |
| un'opzione eliminata dalla sua scheda | `ProductResource::deleteRecord()` → `dropFor()`, anche a funzionalità bloccata |
| un'opzione tolta dalla griglia dell'articolo | `ProductModelResource::saveExtras()` → `dropRemovedOptions()`, nello stesso salvataggio; anche a funzionalità bloccata |
| un articolo eliminato | `ProductModelResource::deleteRecord()` → `dropFor()` su tutte le opzioni, anche quelle nel cestino, poi `dropForModels()`; anche a funzionalità bloccata |
| un fornitore eliminato | `CustomerResource::deleteRecord()` → `dropForRemovedModels()` e `dropForRemovedProducts()` |

Un'opzione tolta dalla griglia dell'articolo finisce nel cestino senza le
sue eccezioni: il repeater del core la mette nel cestino prima di
`afterUpdate()`, e `saveExtras()` gliele toglie nello stesso salvataggio,
perché il legame è il costo di oggi, non storia (P93, P99).

I dati di prova seguono le stesse regole: `CatalogDemo::SUPPLIERS` scrive i
fornitori sugli articoli di prova con `syncModel()`, `SUPPLIER_EXCEPTION`
un'eccezione su un'opzione con `sync()`, solo dove non c'è ancora nessuna
riga; e la pulizia di `gestionale:demo --fresh` lascia al suo posto un
fornitore di prova che un articolo o un'opzione veri nominano ancora (vedi
[Catalogo](catalogo.md#dati-di-prova)).
