---
icon: percent
---

# Promozioni: campagne di sconto

Una **campagna** abbassa il prezzo di un gruppo di prodotti per un periodo.
Non è uno sconto sulla riga e non è un coupon: nessuno la digita, il carrello la
applica da solo. Si accende con la funzionalità `discount_campaigns`; da spenta
nessuna campagna si applica, ma i dati restano.

## Chi fa cosa

| Classe | Compito | Tocca il database? |
| --- | --- | --- |
| `ScopeMatcher` | dice se il selettore di una campagna prende un prodotto | no |
| `CampaignPrice` | tra più campagne sceglie quella che costa meno | no |
| `ProductScope` | legge dal database il selettore e i fatti di un prodotto | sì |
| `Campaigns` | l'unica porta: stato, prezzo, vetrina, anteprima, sovrapposizioni, validazione | sì |
| `LinePrice` | il prezzo di una riga; conosce la sorgente `campaign` | no |

Le classi pure non lanciano eccezioni e non leggono l'orologio: l'istante
(`$now`, `Y-m-d H:i:s`) lo passa sempre il chiamante, così le prove non dipendono
dall'ora. Gli errori che una persona deve leggere li lancia `Campaigns::validate()`
con `UserError::make('campaign.<chiave>')`.

## Lo stato non si scrive

`Campaigns::status($riga, $now)` lo ricava da `active` e dalle date:
`inactive`, `scheduled`, `running`, `ended`. Gli estremi sono **compresi**;
`ends_at` vuoto vuol dire senza fine. Nessuna colonna `status`: non può
andare fuori sincrono.

## A chi si applica: il selettore

Una campagna sceglie i prodotti per **tutto il catalogo**, **categorie** (con le
sottocategorie), **tag**, **marchi** o **articoli**, più un elenco di articoli
**esclusi**. I ponti stanno in `gst_discount_campaign_*`; `ProductScope::OWNERS`
dice, per ciascun proprietario del selettore, quale Model tiene la testata e
quali i ponti. Il coupon (piano 2) userà lo stesso selettore aggiungendo la sua
voce, senza duplicare niente.

## Dal carrello alla riga

`Cart::recalculate()` chiede il prezzo a `Campaigns::forProduct($productId, $now, $channel)`
solo per le righe di prodotto a prezzo automatico e lo passa a `LinePrice` come
`campaign_price`. La priorità è **manuale → listino → campagna → prezzo scontato →
base**; la riga porta `price_source = 'campaign'` e `discount_campaign_id`.
Un prezzo di campagna a `0` è un omaggio, non l'assenza di campagna.

Le campagne **non si cumulano**: ne vince una sola, quella che costa meno, e a
parità la più vecchia.

## Per la vetrina

`Campaigns::display($productId, $now)` dà quello che serve a mostrare l'offerta:
`campaign_id`, `name`, `base_price` (da barrare), `price`, `percent`, `ends_at`.
La vetrina non fa conti e non conosce il selettore.

## Aggiungere una sorgente di prezzo

1. La sorgente va in `LinePrice::SOURCES` e in `OrderItem::PRICE_SOURCES`.
2. Una classe pura sceglie il prezzo; il servizio che la legge dal database lo
   passa a `LinePrice` con una chiave sua (`<sorgente>_price`).
3. Il punto in `LinePrice::source()` è l'unico che decide la priorità.
4. `Cart::recalculate()` la chiama solo per le righe che non hanno un prezzo a
   mano.

## Dati di prova

`php forge gestionale:demo` crea con `PromotionsDemo` tre campagne, una per stato:
20 % su «Magliette e felpe» (in corso), 5 € sul tag «Saldi» (programmata), 10 % sul
marchio «Maglificio Aurora» (finita il mese scorso). Si appoggiano alle tassonomie
di `CatalogDemo`; con `--fresh` si tolgono, con i loro ponti.
