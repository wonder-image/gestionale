---
icon: truck
---

# Spedizioni: zone, listini e calcolo

Il prezzo della spedizione dipende da **dove** va il pacco (la zona) e da
**quanto pesa**. Si accende con la funzionalità `shipping`; da spenta nessuna
riga di spedizione si scrive e il carrello non propone niente, ma i dati restano.
Le guide dei commercianti stanno in [`docs/user/spedizioni-listini.md`](../../user/spedizioni-listini.md).

## Chi fa cosa

| Classe | Compito | Tocca il database? |
| --- | --- | --- |
| `ShippingWeight` | il peso tassabile: il maggiore tra peso reale e volumetrico, per riga | no |
| `ShippingRates` | il prezzo di un listino dato peso e totale dei prodotti | no |
| `ShippingZones` | la zona di una destinazione: la provincia batte il solo paese, poi `position` | sì |
| `Shipping` | **l'unica porta**: opzioni, preventivo, riga del carrello, contrassegno | sì |
| `RateForm` | i listini come li scrive il form del metodo (campi piatti `rate_<zona>_*`) | sì |

Le classi pure non lanciano eccezioni. Chi ha bisogno di una frase per una persona
la prende da `Shipping`, che lancia `UserError` con le chiavi `shipping.*`.

## Il calcolo di un prezzo

Scaglione del peso → carburante (%) → margine (%) → arrotondamento per eccesso al
passo → minimo → gratis se i prodotti superano `free_over_amount` o il peso sta
sotto `free_under_weight`. Con `excess_mode = excess_only` oltre l'ultimo
scaglione si paga l'ultimo scaglione più i kg oltre per la tariffa al kg; senza
tariffa al kg un peso oltre l'ultimo scaglione significa **metodo non offerto**.
Il peso di un articolo senza peso vale zero: cade nel primo scaglione (il form
del metodo avvisa quanti articoli da spedire sono in questo caso).

## La porta: `Shipping`

```php
Shipping::options(int $cartId): array          // metodi offerti, col prezzo, in ordine
Shipping::quote(int $cartId, int $methodId): array   // ['price' => '9.90', 'free' => false]; UserError se non vale
Shipping::line(array $cart, array $computed): ?array // la riga da scrivere, o null
Shipping::dropped(array $cart, array $computed): string // perché il metodo scelto è caduto
Shipping::codFee(int $cartId): float           // il costo di contrassegno del listino scelto
```

`options` e `quote` leggono il carrello dal database; `line` e `dropped` lavorano
su ciò che `Cart::recalculate` ha già in mano (`$computed`), perché al momento del
ricalcolo le righe non sono ancora scritte. `resolveLine` dà i due risultati con
un solo calcolo. Il totale per la soglia gratuita è la somma dei `line_total`
delle righe che non sono spedizione né commissioni, quindi **dopo** gli sconti di
riga e di campagna e **prima** dello sconto del coupon.

### Dove si innesta

- **`Cart::recalculate`** → `writeShipping`: una sola riga `shipping` in fondo ai
  prodotti, con `price_source = base`. Se il metodo scelto non copre più la
  destinazione la riga esce e `dropped` dice perché. Una riga `manual` (messa
  dall'ufficio) non si tocca e toglie il posto a quella calcolata.
- **`Checkout::place` → `applyFee`**: se il pagamento è in contrassegno alla
  consegna e il listino scelto ha un `cod_fee`, quella cifra sostituisce la
  commissione del metodo di pagamento.

### Come deve usarlo E1c

1. Dopo l'indirizzo, `Shipping::options($cartId)` per l'elenco da mostrare; vuoto
   significa «non spediamo lì» (o niente da spedire).
2. Alla scelta si salva `shipping_method_id` sul carrello e si chiama il
   ricalcolo: la riga nasce da sola. `quote` serve solo a mostrare un prezzo
   prima di scegliere, o a rifiutare un id arrivato dal browser.
3. Se cambia l'indirizzo il ricalcolo può far cadere il metodo: leggere
   `dropped` e dire al cliente di sceglierne un altro.
4. Il link di tracciamento si compone con `Carrier.tracking_url_template`
   sostituendo `{tracking}`.

## Dati di prova

`ShippingDemo` (registrata in `Demo`, dopo `CatalogDemo`) crea le zone Italia,
Isole (CA SS NU OR PA CT ME) e UE (FR DE ES AT BE), un corriere, i metodi
Standard ed Espresso con i loro listini e, solo se mancano, il peso dei pochi
articoli di prova da spedire. `gestionale:demo --clear` toglie ciò che non è
usato da ordini o listini veri e dice cosa è rimasto.
