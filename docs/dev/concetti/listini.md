---
icon: journal-text
---

# Listini: cliente, canale e regola in percentuale

Un listino (`PriceList`, `gst_price_lists`) è un elenco di prezzi riservati.
Vale per i clienti che ce l'hanno nella scheda e per i canali di vendita a cui
è assegnato (#162). Una sola funzionalità li accende tutti:
`customer_price_lists` (G1). Da spenta nessun listino si applica, ma i dati
restano.

## Chi fa cosa

| Classe | Compito |
| --- | --- |
| `Models\Pricing\PriceList` | testata: valuta, IVA inclusa/esclusa, date, stato, `price_rule`, `percent` |
| `Models\Pricing\PriceListItem` | righe: prodotto, quantità minima, prezzo |
| `Models\Pricing\PriceListChannel` | `gst_price_list_channels`: un canale (unico) → un listino |
| `Support\Pricing\PriceLists` | l'unica porta: carica, sceglie, risolve il prezzo |
| `Support\Pricing\PriceListChannels` | il registro dei canali che possono avere un listino |
| `Support\Pricing\ChannelPrice` | il prezzo di un prodotto su un canale: l'unico punto per export e integrazioni |
| `Support\Pricing\Import\PriceListLayout` | il tracciato `price_lists` per caricare righe da file |

## I canali

`office`, `online`, `b2b_portal`, `pos` sono i canali degli ordini;
`ebay`, `marketplace` (bindCommerce), `google` e `meta` sono quelli esterni dei
feed. Un canale ha **al più un listino**, un listino può stare su più canali.
Il form rifiuta un canale già di un altro listino
(`price_list.channel_taken`). Un listino con canali assegnati non si cancella.

```php
PriceLists::assignChannels($listId, ['online', 'pos']); // sostituisce i canali del listino
PriceLists::channelsOf($listId);                        // ['online', 'pos']
PriceLists::forChannel('online');                       // id, 0 se nessuno o funzionalità spenta
PriceLists::forChannelAny('online');                    // id, senza guardare la funzionalità
```

`assignChannels()` riscrive solo i canali del listino che riceve: non sposta un
canale da un altro listino, il controllo lo fa il chiamante.

## L'ordine di risoluzione

```
listino del cliente → listino del canale dell'ordine → prezzo base
```

```php
$ids   = PriceLists::candidates($contactListId, $channel); // [cliente, canale], senza doppioni
$lists = PriceLists::loadAll($ids, $now, $currency);       // salta scaduti, spenti, altra valuta
$hit   = PriceLists::resolve($lists, $productId, $qty, $basePrice, $includesTax, $taxRate);
// ['price' => ..., 'price_list_id' => ...] oppure null: vale il prezzo base
```

Regole di `resolve()`:

* **Vince il primo listino che trova il prodotto.** Non si sommano mai.
* In un listino, **una riga del prodotto vince sulla percentuale**: si prende
  lo scaglione più alto raggiunto dalla quantità.
* La **regola in percentuale** (`price_rule`: `none`, `markup`, `discount`;
  `percent`) vale per i prodotti senza riga, a ogni quantità, e per quelli con
  righe di cui la quantità non raggiunge nessuno scaglione. Si calcola sul
  prezzo pieno di catalogo (`PriceLists::byRule()`), non su quello scontato.
  Un listino con una regola trova quindi sempre il prodotto.
* Un listino scaduto, non ancora cominciato, spento o in un'altra valuta lo
  scarta `loadAll()`: si passa al successivo.

Il prezzo esce nella stessa base IVA dell'ordine: `resolve()` converte da
IVA inclusa a esclusa (e viceversa) con l'aliquota del prodotto.

## Negli ordini

`Cart::recalculate()` costruisce i candidati con il listino del cliente e il
canale dell'ordine. Ogni riga a prezzo automatico porta il `price_list_id` del
listino che l'ha prezzata; la testata `orders.price_list_id` è quella data da
`PriceLists::headerOf($lists, $usati)`: il primo listino, nell'ordine dei
candidati, che ha prezzato almeno una riga; se nessuno ne ha prezzate, il
primo listino valido (0 se non ce n'è). Le bozze da ufficio
tengono il listino scelto a mano in *Pagamento e sconto*. La priorità della
riga resta **manuale → listino → campagna → prezzo scontato → base**.

Il sito (`wonder-image/ecommerce`, `CustomerPriceList`) usa il canale `online`
anche per gli ospiti; la cassa usa `pos`.

## Export e feed

Ogni export e integrazione legge il prezzo da `ChannelPrice`, mai da
`PriceLists` o dai campi del prodotto:

```php
ChannelPrice::apply($rows, 'google', $includesTax); // righe con id, price, tax_rate
ChannelPrice::of('ebay', $productId, $basePrice, $includesTax, $taxRate);
// ['price' => ..., 'price_list_id' => ...] oppure null: vale il prezzo del prodotto
ChannelPrice::lists('meta');                         // i listini validi oggi, per i cicli
```

Il listino si risolve a quantità 1 (`of()` accetta una quantità diversa). Con un
prezzo di listino la riga perde il prezzo scontato (`sale_price` a null) e la
campagna. `ProductFeed` (Google e Meta) passa da qui; eBay e bindCommerce, quando
arrivano, fanno lo stesso con `ebay` e `marketplace`. L'export xlsx/csv del
catalogo resta a prezzi di catalogo. `ProductFeed::withPriceLists()` è
deprecato: usa `ChannelPrice::applyLists()`.

## Agganciare un nuovo canale

1. **Registra il canale.** In un modulo, nel `config/module.php`:

   ```php
   'price_lists' => ['channels' => ['amazon' => 'Amazon']],
   ```

   Nel sito, in un file di config: `PriceListChannels::set('amazon', 'Amazon')`.
   La chiave è minuscola, `[a-z][a-z0-9_]`, al più 30 caratteri; una voce non
   valida finisce nel log e si salta. `false` toglie un canale (anche del
   gestionale), il sito ha l'ultima parola sui moduli.
2. **Leggi il prezzo** con `ChannelPrice::apply()` / `of()` passando la chiave.
   Un canale non registrato lancia `InvalidArgumentException`: un refuso non
   manda mai il prezzo base per sbaglio.
3. **Non toccare** `LinePrice`, `PriceLists` né il form: il canale compare da
   solo tra i *Canali* del listino.

Un canale tolto dal registro sparisce dal form; il suo listino resta assegnato
finché qualcuno non risalva la scheda. Gli ordini non passano da
`ChannelPrice`: usano prima il listino del cliente, poi quello del canale
(vedi sopra).

## Il tracciato per i prezzi in blocco

`PriceListLayout` (chiave `price_lists`) è registrato in `import.layouts` e
compare come «Importa» nell'elenco dei listini. Colonne: **Listino** (nome o
id), **SKU**, Prodotto (non letta), **Quantità minima** (vuota = 1),
**Prezzo**. Una riga esistente (listino, prodotto, quantità) cambia prezzo, una
nuova si aggiunge; **non cancella mai** righe. Le frasi d'errore stanno in
`gestionale.errors.import.*`. Il campo «Importa righe» della scheda resta e
sostituisce invece tutte le righe di quel listino.
