---
icon: percent
---

# Promozioni: campagne di sconto e coupon

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
quali i ponti. Il coupon usa lo stesso selettore con la sua voce
(`ProductScope::OWNERS['coupon']`, ponti con chiave `coupon_id`), senza duplicare niente.

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

## Coupon

Il **coupon** è un codice che il cliente digita. Si accende con la funzionalità `coupons`;
da spenta `Coupons::apply` lancia `coupon.inactive`, la pagina sparisce e i dati restano.

* **`CouponRules::check`** è pura (niente database, niente eccezioni, l'ora la passa chi chiama):
  i controlli hanno un ordine fisso e il primo che fallisce vince — attivo, canale, date,
  sconto manuale sulla testata, cliente riservato, utilizzi totali, per cliente, primo ordine,
  prodotti adatti, spesa minima. Il motivo è la chiave di `gestionale.errors.coupon`.
* **`Coupons`** è l'unica porta verso il database: `find`, `apply`/`remove` sul carrello,
  `evaluate` (chiamata da `Cart::recalculate`, che a ogni ricalcolo riverifica il codice e lo
  toglie se non regge), `redeem` e `release`. Lo sconto è ripartito sulle righe adatte in
  proporzione (`order_discount_amount`); la spedizione gratuita azzera la spedizione.
* **Utilizzi**: `redeem` gira **dentro la transazione di `Checkout::place`**, legge il coupon
  con `findForUpdate`, rivaluta le regole con i dati veri del checkout (email, cliente) e scrive
  la riga di `gst_coupon_redemptions`. Due processi sull'ultimo utilizzo: passa uno solo. Se
  `redeem` fallisce per un motivo del coupon, fuori dalla transazione il coupon esce dal
  carrello e nessun ordine nasce. `release` (da `Lifecycle::cancel`, quindi anche dalla scadenza)
  mette `released_at`; è idempotente. Un reso non rilascia.
* **Cliente**: l'account o, da ospite, l'email dell'ordine. Il limite per cliente e il primo
  ordine usano quella.
* **Stato** (programmato, in corso, finito) è `Campaigns::status`, lo stesso delle campagne.

## Dati di prova

`php forge gestionale:demo` crea con `PromotionsDemo` tre campagne, una per stato:
20 % su «Magliette e felpe» (in corso), 5 € sul tag «Saldi» (programmata), 10 % sul
marchio «Maglificio Aurora» (finita il mese scorso), e cinque coupon: `DEMO-PERCENTUALE10`,
`DEMO-IMPORTO5` (spesa minima 30 €), `DEMO-SPEDIZIONE`, `DEMO-ANNA15` (riservato alla scheda di
prova «Anna Verdi») e `DEMO-PRIMO` (solo primo ordine). Si appoggiano alle tassonomie e
ai clienti di prova; con `--fresh` si tolgono, con ponti, clienti riservati e utilizzi.
Il codice del coupon lo digita il cliente e non può portare il segno degli altri dati di
prova: si riconosce dal prefisso `DEMO-` **e** dalla nota «Dato di prova», quindi un coupon
vero che inizia per `DEMO-` non viene mai toccato.

Con la funzionalità `coupons` accesa `OrdersDemo` crea anche tre ordini con coupon
(`coupon-pagato`, `coupon-riservato`, `coupon-annullato`): il coupon si applica al carrello
con `Coupons::apply` prima di `Checkout::place`, e l'ordine annullato ha l'utilizzo rilasciato.
Con `coupons` spenta questi ordini non nascono.

