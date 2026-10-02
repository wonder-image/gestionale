---
icon: cart-shopping
---

# Vendite: ordini, pagamenti e resi

## Chi scrive cosa

Ogni colonna di stato ha **un solo scrittore**. Nessuna Resource e nessun
comando la cambia a mano: chiamano il servizio.

| Cosa | Chi lo scrive | Dove |
| --- | --- | --- |
| `status` e `fulfillment_status` dell'ordine | `Lifecycle::confirm()`, `cancel()`, `fulfill()` | `Support\Orders` |
| `payment_status` dell'ordine | `Ledger::sync()`, chiamato da `register()`, `refund()`, `fail()` | `Support\Payments` |
| Prenotazione, scarico e rientro della merce | `Allocation::reserve()`, `release()`, `commit()`, `restore()`, `returnGoods()` | `Support\Stock` |
| Stato del reso e sue righe | `Returns::register()`, `complete()`, `cancel()` | `Support\Returns` |
| Giacenza e movimenti | `Stock::apply()` (vedi [Magazzino](magazzino.md)) | `Support\Stock` |

`Allocation` non scrive mai la giacenza per conto suo: passa da `Stock::apply()`.
Per questo chi legge i movimenti trova sempre il motivo (`sale`,
`return`, …) e l'ordine o il reso che li ha causati (`reference_type` `order` o `sales_return`).

## Il percorso di un ordine

1. **Carrello** — `Cart::open()`, `add()`, `setQuantity()`, `remove()`,
   `merge()`. Ogni modifica passa da `recalculate()`, che rifà prezzi, IVA e
   totale.
2. **Ordine** — `Checkout::place()` trasforma il carrello in ordine `pending` e
   prenota la merce con `Allocation::reserve()`. La giacenza non cambia: la
   merce è solo messa da parte.
3. **Conferma** — `Lifecycle::confirm()` scarica la merce (`Allocation::commit()`),
   manda l'email al cliente e, se è passato un pagamento, lo registra col
   `Ledger`. Confermare **non** registra denaro da solo.
4. **Evasione** — `Lifecycle::fulfill()` cambia solo `fulfillment_status`: la
   merce è già uscita alla conferma.
5. **Annullo** — `Lifecycle::cancel()` libera la prenotazione se l'ordine era
   `pending`, rimette la merce in magazzino se era già confermato. Il denaro
   incassato non si rimborsa da qui: lo fa il gateway con `Ledger::refund()`.
6. **Scadenze** — `Expiry::run()` gira ogni ora (`ExpiryTask`, cron
   `0 * * * *`): manda il promemoria agli ordini che aspettano il pagamento e
   annulla quelli scaduti, secondo `order_payment_wait_days`.

### Le personalizzazioni sulla riga

`Cart::add($carrello, ['product_id' => …, 'quantity' => …, 'customization' => [id => valore]])`.
Con `customizations` accesa il carrello passa i valori da
`Customizations::resolve()`: un valore sbagliato o una obbligatoria mancante
lanciano `UserError` (`field()` è l'id della personalizzazione). Con la
funzionalità spenta i valori si ignorano, e un articolo con una personalizzazione
obbligatoria non si aggiunge (`customization.unavailable`).

- **Il sovrapprezzo lo calcola il server.** `customization_surcharge` che
  arriva dalla pagina non si legge più; la riga salva `customization`
  (stringa ASCII) e il sovrapprezzo, che entra nel prezzo di `LinePrice`.
- Due righe dello stesso articolo si fondono solo se hanno la stessa
  personalizzazione (`Customizations::signature()`), anche nel `merge()`.
- `Cart::contents()['items'][]['customization']` è una **lista** già decodificata
  (`label`, `value`, `option_id`, `surcharge`), mai la stringa del database:
  chi ha bisogno della colonna passa da `Customizations::decode()`.
- `recalculate()` ripassa ogni riga personalizzata contro l'anagrafica di oggi:
  riprezza se il sovrapprezzo è cambiato e riscrive le etichette; se la
  personalizzazione è stata disattivata, scollegata, l'opzione cancellata o è
  comparsa una obbligatoria, **la riga esce** e il suo nome va in `removed`,
  come per un articolo non più vendibile.
- Dopo l'ordine la riga è una copia: schede, email ed elenco resi mostrano
  `Customizations::lines()` sotto il nome. Un'incisione non si rimette in
  vendita: `ReturnRules::onlineReturnable($riga)` dice **no** a una riga
  personalizzata, ed è quello che E1c deve consultare per il reso fatto dal
  cliente. Il reso registrato dal commerciante (`Returns::register()`) non cambia.

## Il reso

`Returns::register($ordine, $righe, $opzioni)` fa tutto dentro una transazione,
con l'ordine bloccato (`FOR UPDATE`): due invii contemporanei si mettono in
fila e il secondo non può rendere più di quanto resta. Non c'è un controllo
sull'invio ripetuto: due invii uguali di una quantità parziale sono due resi.

- Il reso nasce `received`: la merce è già in mano al commerciante.
- `ReturnRules` (pura, senza database) decide se l'ordine può avere un reso, il
  massimo rendibile per riga e se il motivo propone il ricarico a magazzino.
- Per le righe con la spunta «Rientra a magazzino» il rientro passa da
  `Allocation::returnGoods()`: un movimento `return` sulla sede scelta, col
  reso come documento di riferimento.
- `complete()` chiude un reso ricevuto. `cancel()` lo annulla, ma **solo se
  nessuna riga è rientrata**: altrimenti `return.already_restocked` rimanda alla
  rettifica in Magazzino.
- Il reso **non** tocca `payment_status` né rimborsa: il denaro resta al gateway.

Una funzionalità accende l'altra a catena: `returns` richiede `orders`
(vedi [Funzionalità e ruoli](funzionalita.md)). Con `returns` spenta spariscono
la pagina *Registra reso*, la voce nel menu dell'ordine e la tabella dei resi
nella scheda.

## Errori che il commerciante legge

I servizi lanciano `UserError::make('gruppo.chiave')`; la frase sta in
`lang/it/gestionale.json` sotto `errors.<gruppo>`. I gruppi di questa area sono
`order`, `cart`, `payment` e `return`. Le pagine mostrano la frase così com'è:
vedi [Errori e log](errori.md).

## Provare a mano e nei test

- `php forge gestionale:demo` crea ordini in tutti gli stati, pagamenti e, con
  `returns` accesa, un reso di 1 pezzo (`changed_mind`) sull'ordine evaso;
  `--fresh` li toglie e li rifà (con `customizations` accesa, uno su due
  porta l'incisione «Auguri»). Il `purge` restituisce al magazzino solo la
  merce che il reso non aveva già rimesso.
- Nei test d'integrazione, `tests/integrazione/supporto/compra.php` offre
  `articoloConGiacenza()`, `ordineDiProva()`, `resoDiProva()` e
  `accendiFunzionalita(['orders', 'returns'])`. Si lavora dentro una
  transazione che alla fine si annulla, così il database del sito di prova
  resta com'era.
- Per spegnere una funzionalità solo dentro la prova:
  `sqlModify(Feature::$table, ['enabled' => 'false'], 'feature_key', 'returns')`
  seguito da `Gestionale::reset()`.

## Trappole già pagate

- `Lifecycle::cancel()` rimette a magazzino solo la merce non ancora resa
  (`quantità − Returns::returned()`): quella resa è già rientrata col reso, o
  era rotta e non va a scaffale.
- `Lifecycle::confirm()` su un ordine già confermato non rifà niente e non
  riscarica la merce: ripassare da un webhook è sicuro.
- `Ledger::sync()` non scrive né logga se lo stato non cambia: un webhook
  ripetuto non riempie la storia di righe uguali.
- Una riga d'ordine che non è un prodotto (spedizione, sconto) non è rendibile:
  `Returns::lines()` mostra solo i prodotti.
