---
icon: truck
---

# Fornitori e costi d'acquisto

Funzionalità `purchasing` (*Acquisti*). Oggi porta due cose: il ruolo
**fornitore** nella rubrica e, per ogni opzione in vendita, **da chi si compra,
con quale codice e a quanto**. Documenti di carico, valore del magazzino e
costo aggiornato dai carichi arriveranno dopo, e leggeranno la stessa tabella.

Come la scheda dell'articolo e quella dell'opzione scrivono i legami sta in
[Catalogo](catalogo.md#i-fornitori-di-ogni-opzione). Qui c'è quello che vale
per tutti: la tabella, le regole e chi le fa rispettare.

## La tabella

`gst_product_suppliers`, Model `Models\Catalog\ProductSupplier`: una riga per
opzione e fornitore.

| Colonna | Note |
|---|---|
| `product_id` | l'opzione, chiave esterna su `gst_products` |
| `supplier_id` | la scheda della rubrica, chiave esterna su `gst_contacts` |
| `supplier_sku` | il codice con cui il fornitore la chiama, fino a 100 caratteri |
| `cost` | `DECIMAL(12,4)` dichiarato con `Columns::decimal()`; `NULL` vuol dire «non lo so» |
| `is_preferred` | `true` o `false`: **uno solo** per opzione |
| `position` | l'ordine delle righe nella pagina |

Indici `ind_product` e `ind_supplier`. `syncSchema()` torna `null`: i costi
d'acquisto sono lavoro di chi vende, non configurazione, e non viaggiano con il
deploy.

Tre scelte da sapere prima di toccarla:

1. **È il costo di oggi, non storia.** Lo storico sta nell'`unit_cost` dei
   movimenti. Per questo un legame si cancella **davvero**, quando se ne va la
   riga o l'opzione, e non resta niente in `deleted = 'true'` a tenere ferma la
   chiave esterna del fornitore.
2. **Vuoto è `NULL`, non zero** (P91). Uno zero farebbe del fornitore il più
   conveniente e abbasserebbe il valore del magazzino. Nella scheda il costo si
   scrive con due decimali; nella tabella ne ha quattro, come il costo dei
   movimenti, e un salvataggio che non cambia il numero arrotondato non li
   perde.
3. **Nessun indice unico su opzione e fornitore** (P93). Il repeater del core
   prima scrive e poi toglie: uno scambio di righe inciamperebbe a metà
   salvataggio. I doppioni li ferma la scheda, che può spiegarlo con una frase
   (`product.supplier_duplicate`).

Il **preferito** è quello che si mostra accanto al bottone «Costo» e che
useranno gli ordini ai fornitori. Se nessuna riga lo è, lo diventa la prima.

## `ProductSuppliers`

`Support\Purchasing\ProductSuppliers` è l'unico posto con le regole dei legami.
Le righe arrivano da due strade — il repeater della scheda dell'opzione e il
JSON della finestra «Costo» dell'articolo — e passano tutte e due di qui.

| Metodo | Pura | Cosa fa |
|---|---|---|
| `normalize($rows)` | sì | toglie le righe vuote, legge il costo come lo scrive una persona («12,50», «1.234,50 €»), vuoto a `null`, un preferito solo |
| `isEmptyRow($row)` | sì | niente fornitore, niente codice, niente costo; il preferito non conta |
| `preferOne($rows)` | sì | vince la prima riga segnata, altrimenti la prima |
| `preferred($rows)` | sì | la riga preferita, o `null` |
| `assertValid($rows, $ids, $labels)` | sì | i rifiuti, sulle righe **grezze** (vedi sotto) |
| `summary($preferita, $scelte)` | sì | il testo accanto al bottone: «Filati Nord · 12,00 €», il solo nome se il costo non si sa, «Nessun fornitore» |
| `linksFor($productIds)` | no | i legami per opzione, in ordine di posizione, già normalizzati |
| `sync($productId, $rows)` | no | scrive i legami di un'opzione com'erano nella pagina |
| `dropFor($productIds)` | no | toglie i legami di opzioni che stanno per sparire |
| `dropRemovedOptions($modelId)` | no | toglie i legami delle opzioni che la griglia ha messo nel cestino |
| `dropForRemovedProducts($contactId)` | no | toglie i legami di un fornitore con opzioni non più in vendita |
| `countForSupplier($contactId)` | no | su quante opzioni in vendita un fornitore ha un legame |

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

`sync()` confronta **per fornitore**: chi c'era si aggiorna, chi manca nasce,
chi non c'è più se ne va davvero. La posizione è l'ordine delle righe, il costo
vuoto diventa `NULL`, e tutto sta in una `Transaction`. Se trova righe in
`deleted = 'true'` (nessuno ce le mette, ma può capitare) le toglie con le
altre. Un salvataggio che fallisce è un guasto, non una risposta: lancia una
`RuntimeException` e la transazione riporta indietro tutto.

**Un'opzione è in vendita** quando né lei né il suo articolo sono stati
eliminati. L'elenco degli articoli li mette in `deleted = 'true'` senza toccare
le opzioni, e un articolo nel cestino non deve tenere fermo un fornitore:
`countForSupplier()` e `dropForRemovedProducts()` guardano tutti e due i
livelli (`liveProducts()`, una sottoquery con il `JOIN` sui modelli).

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
| `ProductModelResource::supplierChoices()` | i fornitori legati a una qualunque opzione dell'articolo |
| `ProductModelResource::supplierChoicesFor()` | le scelte dell'articolo, meno i non attivi che quell'opzione non ha |
| `ProductResource::supplierCardChoices()` | i fornitori legati a quell'opzione |

Il numero di scelte dell'articolo decide anche la forma della scheda: da due
in su il bottone «Costo» con la finestra, altrimenti tre caselle (P92). Un
fornitore non attivo però si propone, e si accetta, solo sulle opzioni che lo
hanno già: una riga nuova ha solo gli attivi (vedi
[Catalogo](catalogo.md#i-fornitori-di-ogni-opzione)).

## Un fornitore in uso non si toglie

Un legame punta alla scheda della rubrica con una chiave esterna, e una scheda
che non è più fornitore sparirebbe dalla tendina delle opzioni che comprano da
lei: al primo salvataggio le perderebbe. Per questo `CustomerResource` (e
`SupplierResource`, che la estende) rifiuta due cose quando
`countForSupplier()` è più di zero:

| Cosa | Dove | Chiave |
|---|---|---|
| eliminare la scheda | `assertDeletable()`, su tutte e due le porte (elenco ed eliminazione dalla scheda) | `contact.supplier_in_use`, con `UserError::refusal()` |
| togliere il ruolo di fornitore | `mutateRequestValues()`, solo su una scheda salvata che era fornitore | `contact.supplier_role_in_use`, con `UserError::make()` |

L'eliminazione è rifiutata **anche con `purchasing` bloccata**: il costo resta
salvato e tornerà quando la si riaccende. Il ruolo invece, a funzionalità
bloccata, non cambia comunque: il campo Ruolo non si stampa e
`mutateRequestValues()` lascia le due colonne come sono. La strada giusta, in
tutti e due i casi, è la scheda su «Non attiva»: i legami restano e le opzioni
che li hanno continuano a proporre quel fornitore.

Superato il controllo, `deleteRecord()` toglie prima i legami del fornitore con
opzioni che non sono più in vendita (`dropForRemovedProducts()`): nessuno li
vede più, e la chiave esterna li terrebbe fermi. L'elenco, che chiama solo
`assertDeletable()` e mette la scheda nel cestino, non ne ha bisogno.

## Chi toglie i legami

| Quando | Chi |
|---|---|
| una riga tolta dalla scheda dell'opzione | il repeater del core, con `softDelete(false)` |
| un fornitore staccato con la «x» della finestra «Costo» o svuotato nelle tre caselle | `ProductSuppliers::sync()` |
| un'opzione tolta dalla griglia dell'articolo | `saveSupplierCosts()` → `dropRemovedOptions()`, solo con `purchasing` attiva |
| un'opzione eliminata dalla sua scheda | `ProductResource::deleteRecord()` → `dropFor()`, anche a funzionalità bloccata |
| un articolo eliminato | `ProductModelResource::deleteRecord()` → `dropFor()` su tutte le opzioni, anche quelle nel cestino, anche a funzionalità bloccata |
| un fornitore eliminato | `CustomerResource::deleteRecord()` → `dropForRemovedProducts()` |

I dati di prova seguono le stesse regole: la pulizia di `gestionale:demo
--fresh` lascia al suo posto un fornitore di prova che il costo di un'opzione
vera nomina ancora (vedi [Catalogo](catalogo.md#dati-di-prova)).
