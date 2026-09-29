---
icon: truck
---

# Fornitori e costi d'acquisto

Funzionalità `purchasing` (*Acquisti*). Oggi porta due cose: il ruolo
**fornitore** nella rubrica e, per ogni opzione in vendita, **da chi si
compra, con quale codice e a quanto** (P107). Documenti di carico, valore del
magazzino e costo aggiornato dai carichi arriveranno dopo, e leggeranno la
stessa tabella.

Come la scheda dell'articolo e quella dell'opzione li fanno compilare sta in
[Catalogo](catalogo.md#i-fornitori-delle-opzioni). Qui c'è quello che vale per
tutti: la tabella, le regole e chi le fa rispettare. Con la funzionalità
bloccata non si vede e non si scrive niente, e i legami salvati restano dove
sono.

## Una tabella sola

I fornitori sono **del prodotto**, come la giacenza: l'articolo senza varianti
li ha sul suo unico prodotto, quello con le varianti su ogni opzione. Non c'è
un livello dell'articolo da sommare a quello dell'opzione: quello che vale per
un'opzione è quello che ha scritto. `gst_product_model_suppliers`, il modello
`ProductModelSupplier` ed `effective()` del tredicesimo giro non ci sono più
(P107 supera P95 e P96).

`gst_product_suppliers`, modello `Models\Catalog\ProductSupplier`:

| Colonna | Note |
|---|---|
| `product_id` | l'opzione, chiave esterna su `gst_products` |
| `supplier_id` | la scheda della rubrica, chiave esterna su `gst_contacts` |
| `supplier_sku` | il codice con cui il fornitore lo chiama, fino a 100 caratteri |
| `cost` | `DECIMAL(12,4)` dichiarato con `Columns::decimal()`; `NULL` vuol dire «non lo so» |
| `position` | l'ordine delle righe nella finestra |

Indici `ind_product` e `ind_supplier`. `syncSchema()` torna `null`: i costi
d'acquisto sono lavoro di chi vende, non configurazione, e non viaggiano con
il deploy. Un **preferito non c'è** (P98): l'ordine delle righe è l'unico
ordine.

Un sito che ha già girato con il tredicesimo giro può avere ancora la tabella
`gst_product_model_suppliers`: nessuno la legge più, e si può togliere a mano.

Tre scelte da sapere prima di toccarla:

1. **È il costo di oggi, non storia.** Lo storico sta nell'`unit_cost` dei
   movimenti. Per questo un legame si cancella **davvero**, quando se ne va la
   riga, l'opzione o l'articolo, e non resta niente in `deleted = 'true'` a
   tenere ferma la chiave esterna del fornitore.
2. **Vuoto è `NULL`, non zero** (P91). Uno zero farebbe del fornitore il più
   conveniente e abbasserebbe il valore del magazzino. Nella scheda il costo si
   scrive con due decimali; nella tabella ne ha quattro, come il costo dei
   movimenti, e un salvataggio che non cambia il numero arrotondato non li
   perde (`keepStoredCosts()`).
3. **Nessun indice unico su opzione e fornitore** (P93). `sync()` prima scrive
   e poi toglie: uno scambio di righe inciamperebbe a metà salvataggio. I
   doppioni li fermano la finestra e il server, che possono spiegarlo con una
   frase (`product.supplier_duplicate`).

## Uno, nessuno o più fornitori

Come si compilano dipende da **quanti fornitori la scheda propone** (P109), la
stessa regola delle sedi: la finestra c'è solo quando serve.

| `supplierMode()` | Quando | Cosa si vede |
|---|---|---|
| `null` | `purchasing` bloccata | niente |
| `none` | nessun fornitore da proporre | niente nella scheda dell'articolo; la nota «Nessun fornitore da proporre» nella scheda dell'opzione |
| `flat` | un fornitore solo | «Codice fornitore» e «Costo d'acquisto», senza tendina: il nome sta nel tooltip |
| `modal` | due o più | il bottone «Fornitori» con il riassunto accanto, e la finestra a righe |

I fornitori proposti sono quelli di [`Contacts::supplierOptions()`](#contactssupplieroptions):
gli attivi, più quelli già legati anche se non lo sono più. Nella scheda
dell'articolo il conto è **dell'articolo** (`ProductModelResource::supplierChoices()`,
con i legami di tutte le sue opzioni), nella scheda dell'opzione è **di
quell'opzione** (`ProductResource::supplierChoices()`): una sorella con un
fornitore non più attivo può chiedere la finestra dove questa chiede i due
campi.

## `ProductSuppliers`

`Support\Purchasing\ProductSuppliers` è l'unico posto con le regole dei
legami.

| Metodo | Pura | Cosa fa |
|---|---|---|
| `normalize($rows)` | sì | toglie le righe vuote, legge il costo come lo scrive una persona («12,50», «1.234,50 €»), vuoto a `null` |
| `isEmptyRow($row)` | sì | niente fornitore, niente codice, niente costo |
| `fromFields($supplierId, $sku, $cost)` | sì | le righe **grezze** dei due campi del fornitore unico: nessuna se sono vuoti tutti e due, una altrimenti |
| `replaceOne($links, $supplierId, $rows)` | sì | i legami di un'opzione con quello di un fornitore solo riscritto: al suo posto se c'era, in coda se non c'era, via se `$rows` è vuoto |
| `fromJson($json)` | sì | le righe del campo nascosto della finestra; `null` se è vuoto o non è un elenco (la finestra non l'ha toccato), `[]` per `'[]'` (la finestra l'ha svuotato) |
| `keepStoredCosts($posted, $stored)` | sì | rimette il costo salvato, a quattro decimali, dove la casella dice lo stesso numero arrotondato a due |
| `summary($rows, $names)` | sì | il riassunto accanto al bottone: «Filati Nord 12,00 € · Lana Sud», o «Nessun fornitore» |
| `assertValid($rows, $ids, $labels)` | sì | i rifiuti, sulle righe **grezze** (vedi sotto) |
| `linksFor($productIds)` | no | i legami per opzione, in ordine di posizione, già normalizzati |
| `sync($productId, $rows)` | no | scrive i legami di un'opzione com'erano nella pagina |
| `dropFor($productIds)` | no | toglie i legami di opzioni che stanno per sparire |
| `dropRemovedOptions($modelId)` | no | toglie i legami delle opzioni che la griglia ha messo nel cestino |
| `dropForRemovedProducts($contactId)` | no | toglie i legami di un fornitore con opzioni non più in vendita |
| `countForSupplier($contactId)` | no | su quante opzioni in vendita un fornitore ha un legame |

`MAX_COST` (99.999.999,9999) e `SKU_MAX_LENGTH` (100) sono i limiti delle
colonne, e li usano anche le schede per il `maxLength()` del codice e la
finestra per i suoi controlli.

`assertValid()` lavora sulle righe come sono arrivate, perché un codice o un
costo senza fornitore si deve vedere, non sparire:

| Chiave | Quando |
|---|---|
| `product.supplier_missing` | codice o costo scritti, fornitore vuoto |
| `product.supplier_invalid` | un fornitore che la pagina non propone: eliminato, non più fornitore, o disattivato e mai legato a quell'opzione; oppure i due campi compilati quando il fornitore unico non c'è più |
| `product.supplier_sku_too_long` | un codice del fornitore oltre cento caratteri (`SKU_MAX_LENGTH`) |
| `product.supplier_cost_invalid` | un costo scritto che non è un numero («12..5»): non diventa «non lo so» in silenzio |
| `product.supplier_cost_negative` | costo sotto zero |
| `product.supplier_cost_too_high` | un costo oltre `MAX_COST`, 99.999.999,9999: quello che tiene `DECIMAL(12,4)` |
| `product.supplier_duplicate` | lo stesso fornitore due volte; `{{supplier}}` è il suo nome |

I limiti della colonna si controllano qui, prima di scrivere: il core salva
l'articolo o l'opzione prima dei legami, e un rifiuto di MySQL a metà
lascerebbe la pagina salvata a metà, con un errore in inglese.

`sync()` confronta **per fornitore**: chi c'era si aggiorna, chi manca nasce,
chi non c'è più se ne va davvero. La posizione è l'ordine delle righe, il
costo vuoto diventa `NULL`, e tutto sta in una `Transaction`. Se trova righe in
`deleted = 'true'` (nessuno ce le mette, ma può capitare) le toglie con le
altre. Un salvataggio che fallisce è un guasto, non una risposta: lancia una
`RuntimeException` e la transazione riporta indietro tutto.

**Un'opzione è in vendita** quando né lei né il suo articolo sono stati
eliminati. L'elenco degli articoli li mette in `deleted = 'true'` senza
toccare le opzioni, e un articolo nel cestino non deve tenere fermo un
fornitore: `countForSupplier()` e `dropForRemovedProducts()` guardano tutti e
due i livelli (`liveProducts()`, sottoquery con il `JOIN` sui modelli).

Le `drop…()` passano da `deleteWhere()`, che scrive il `WHERE` da sé: il core
prende così com'è una condizione che contiene già un `WHERE`, e quello della
sottoquery lo ingannerebbe. Senza tabella o senza database tutti i metodi che
leggono tornano vuoto invece di rompersi.

## Chi scrive i legami

Non c'è più nessun repeater del core: i fornitori arrivano come **campi che
non sono colonne**, e li scrive il modulo dopo che l'articolo o l'opzione sono
salvati. Il server **legge quello che arriva**, non il modo in cui crede di
essere (P114): fra l'apertura della scheda e il salvataggio qualcuno può aver
aggiunto il secondo fornitore.

| Da dove | Campi | Chi controlla | Chi scrive |
|---|---|---|---|
| scheda dell'articolo, senza varianti | `product_suppliers` (JSON) oppure `product_supplier_sku` e `product_supplier_cost` | `ProductModelResource::assertSupplierRows()`, da `mutateRequestValues()` | `saveSuppliers()`, da `saveExtras()` |
| scheda dell'articolo, riga della griglia | `products[i][suppliers]` (JSON) oppure `products[i][supplier_sku]` e `products[i][supplier_cost]` | lo stesso | lo stesso |
| scheda dell'opzione | `suppliers` (JSON) oppure `supplier_sku` e `supplier_cost` | `ProductResource::mutateRequestValues()` | `ProductResource::afterUpdate()` |

Tutti e tre passano da `postedSuppliers($posted, $prefix, $id)`, che torna
`['json', righe]`, `['flat', righe]` oppure `null`, e da `writeSuppliers()`:

- **Il JSON vince**, e **sostituisce** i legami dell'opzione (`normalize()` e
  `sync()`). `'[]'` li toglie tutti; un campo vuoto vuol dire che la finestra
  non è mai stata salvata, e non arriva niente.
- **I due campi sono del fornitore unico** (`soleSupplierId()`): compilati
  scrivono il legame con lui, vuoti lo tolgono, e gli altri legami
  dell'opzione non si toccano (`replaceOne()`). Se quando arrivano la scheda
  non propone più un fornitore solo, compilati non si sa di chi siano:
  `product.supplier_invalid`, e la pagina ricaricata mostra il modo giusto.
  Vuoti non dicono niente, e passano.
- **`null`**: non è arrivato niente, e i legami restano quelli che sono.
- In tutti e due i casi il costo passa da `keepStoredCosts()`.

Con le varianti contano i campi delle righe, e quelli con il prefisso
`product_` non si guardano: sono nascosti, ma arrivano lo stesso. Fa eccezione
l'articolo con **un prodotto solo** a cui si stanno accendendo le varianti
(P115): quello scritto nel riquadro «Prodotto» va al suo prodotto, e le opzioni
che nascono senza niente di scritto nella loro riga lo copiano, come il prezzo
e lo sconto. Una riga nuova che ha qualcosa di scritto vince. Le opzioni
aggiunte dopo nascono con quello che ha la loro riga.

Un id che non è di un'opzione dell'articolo non si scrive. Alla fine
`saveSuppliers()` azzera quello che la richiesta aveva letto (`$supplierLinks`,
`$supplierChoices`, `$inactiveSuppliers`); la scheda dell'opzione fa lo stesso
con le sue letture, e `forgetCatalogCache()` le azzera tutte.

Chi scrive senza un form — i dati di prova, i test — chiama `sync()`.

## `Contacts::supplierOptions()`

I fornitori da proporre, `id => nome`, in ordine naturale di nome:

```php
Contacts::supplierOptions();          // gli attivi
Contacts::supplierOptions([7, 12]);   // gli attivi, più il 7 e il 12 anche se non lo sono
```

Sono le schede con `is_supplier = 'true'` e non eliminate, **attive** oppure
fra quelle di `$keepIds`: i fornitori già legati a quello che si sta
modificando. Senza, la finestra non li avrebbe fra le scelte, e il salvataggio
dopo li staccherebbe solo perché sono stati messi su «Non attiva». Un fornitore
tenuto così compare con «(non attivo)» accanto al nome. Senza database torna
`[]`.

Chi la chiama:

| Dove | `$keepIds` |
|---|---|
| `ProductModelResource::supplierChoices($modelId)` | i fornitori legati a una qualunque opzione dell'articolo |
| `ProductResource::supplierChoices($productId)` | i fornitori legati a quell'opzione |

Un fornitore non attivo si propone, e si accetta, **solo per l'opzione che lo
ha già** (P92): nella scheda dell'articolo `supplierChoicesFor($modelId,
$productId)` toglie dalle scelte i non attivi che non sono suoi, la finestra
li nasconde nella tendina riga per riga (`data-wi-supplier-inactive`), e «Salva
per tutte le opzioni» non li porta sulle opzioni che non li avevano.

## Un fornitore in uso non si toglie

Un legame punta alla scheda della rubrica con una chiave esterna, e una scheda
che non è più fornitore sparirebbe dalle scelte delle opzioni che comprano da
lei: al primo salvataggio la perderebbero. Per questo `CustomerResource` (e
`SupplierResource`, che la estende) rifiuta due cose quando
`countForSupplier()` è più di zero:

| Cosa | Dove | Chiave |
|---|---|---|
| eliminare la scheda | `assertDeletable()`, su tutte e due le porte (elenco ed eliminazione dalla scheda) | `contact.supplier_in_use`, con `UserError::refusal()` |
| togliere il ruolo di fornitore | `mutateRequestValues()`, solo su una scheda salvata che era fornitore | `contact.supplier_role_in_use`, con `UserError::make()` |

Il conto è delle **opzioni**: un articolo con tre taglie comprate dallo stesso
fornitore fa tre.

L'eliminazione è rifiutata **anche con `purchasing` bloccata**: il costo resta
salvato e tornerà quando la si riaccende. Il ruolo invece, a funzionalità
bloccata, non cambia comunque: il campo Ruolo non si stampa e
`mutateRequestValues()` lascia le due colonne come sono. La strada giusta, in
tutti e due i casi, è la scheda su «Non attiva»: i legami restano e le schede
che li hanno continuano a proporre quel fornitore.

Superato il controllo, `deleteRecord()` toglie prima i legami del fornitore con
opzioni che non sono più in vendita (`dropForRemovedProducts()`): nessuno li
vede più, e la chiave esterna li terrebbe fermi. L'elenco, che chiama solo
`assertDeletable()` e mette la scheda nel cestino, non ne ha bisogno.

## Chi toglie i legami

| Quando | Chi |
|---|---|
| una riga tolta dalla finestra, o i due campi svuotati | `sync()`, al salvataggio della scheda: toglie i legami spariti |
| un'opzione eliminata dalla sua scheda | `ProductResource::deleteRecord()` → `dropFor()`, anche a funzionalità bloccata |
| un'opzione tolta dalla griglia dell'articolo | `ProductModelResource::saveExtras()` → `dropRemovedOptions()`, nello stesso salvataggio; anche a funzionalità bloccata |
| un articolo eliminato | `ProductModelResource::deleteRecord()` → `dropFor()` su tutte le opzioni, anche quelle nel cestino; anche a funzionalità bloccata |
| un fornitore eliminato | `CustomerResource::deleteRecord()` → `dropForRemovedProducts()` |

Un'opzione tolta dalla griglia dell'articolo finisce nel cestino senza i suoi
fornitori: il repeater del core la mette nel cestino prima di `afterUpdate()`,
e `saveExtras()` glieli toglie nello stesso salvataggio, prima di
`saveSuppliers()`, perché il legame è il costo di oggi, non storia (P93, P99).

I dati di prova seguono le stesse regole: `CatalogDemo::SUPPLIERS` dice da chi
si comprano le opzioni di ogni articolo di prova, `LAST_OPTION_SUPPLIERS` cosa
cambia per l'ultima opzione dei calzini, e `suppliers()` li scrive con
`sync()` opzione per opzione, solo dove non c'è ancora nessuna riga; la
pulizia di `gestionale:demo --fresh` lascia al suo posto un fornitore di prova
che un'opzione vera nomina ancora (vedi [Catalogo](catalogo.md#dati-di-prova)).
