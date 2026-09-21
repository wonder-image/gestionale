---
icon: file-lines
---

# Codici, numerazione e log degli stati

Tre cose che tutti i documenti del gestionale condividono. Sono pronte da G1,
anche se i documenti veri nascono nei sotto-progetti dopo.

## Codici con prefisso

Ogni entità operativa ha una colonna `code` con prefisso e sette caratteri
casuali, per esempio `pay_k3x9d2a`. Si dichiara nel Model:

```php
Field::key('code')->text()->uniqueCode(Codes::PAYMENT),
```

I prefissi stanno tutti in `Support\Codes`, uno per entità, e un test controlla
che siano diversi e nel formato `xxx_`. Il codice è il riferimento tecnico —
link mandati al cliente, metadati dei gateway, log — e **non sostituisce** SKU,
numero d'ordine o numero di fattura.

Le tabelle di configurazione non hanno codici generati: aliquote, tipi fiscali
e regole usano codici parlanti (`22`, `ordinaria`, `italia-privato-ordinaria`).

`Model::prepare()` formatta i valori ma non genera il codice: chi inserisce una
riga da codice se lo fa dare dal Model, come fa `Location::newCode()`.

## Numerazione dei documenti

Formato unico `{YYYY}/{mm}{nnnn}`, per esempio `2026/090001`. Il progressivo
riparte ogni mese e **ogni tipo di documento ha la sua sequenza**; oltre i 9999
documenti nel mese il numero continua con una cifra in più.

```php
$numero = DocumentSequences::next('order');          // adesso
$numero = DocumentSequences::next('order', $data);   // a una data precisa
```

La riga di `gst_document_sequences` si legge con `FOR UPDATE` dentro una
transazione: due documenti creati nello stesso istante aspettano l'uno l'altro
invece di prendere lo stesso numero. Se una transazione è già aperta, il core ci
aggancia un savepoint, quindi chiamare `next()` dentro il salvataggio di un
documento è la cosa giusta.

La tabella **non si sincronizza mai**: i numeri li fa l'ambiente dove il
documento nasce.

## Log degli stati

Ogni documento con stati ha la sua tabella di log, sempre con le stesse
colonne. La base è `Models\System\StatusLog`: una sottoclasse dichiara solo la
tabella, la colonna verso il documento e la tabella del documento.

| Colonna | Contenuto |
|---|---|
| `<entità>_id` | documento |
| `field` | stato cambiato (`stage`, `status`, `payment_status`, …) |
| `from_value`, `to_value` | valore prima e dopo |
| `source` | `user`, `system`, `cron`, `webhook`, `api` |
| `user_id` | autore, quando `source` è `user` |
| `message` | nota o esito (es. `sent_to_sdi`) |
| `response` | JSON della risposta esterna |

La riga si scrive sempre con il logger, mai a mano:

```php
StatusLogger::record(OrderStatusLog::class, $ordineId, 'status', 'draft', 'confirmed', 'user', $utenteId);
```

Un'origine che non è nell'elenco diventa `system`: un log non deve poter dire
qualcosa che nessuno sa leggere.

## Riferimenti esterni

`gst_external_references` tiene il nome delle nostre entità dentro i sistemi
esterni (Fatture in Cloud, Stripe, corrieri), diviso per ambiente `live` e
`test`.

```php
ExternalReferences::save('invoice', $id, 'fatture-in-cloud', 'invoice', 'FIC-1');
ExternalReferences::fail('invoice', $id, 'fatture-in-cloud', 'invoice', 'timeout');
$riferimento = ExternalReferences::find('invoice', $id, 'fatture-in-cloud', 'invoice');
```

`save()` e `fail()` sono separati apposta: quando una sincronizzazione va male,
l'id già ottenuto non si tocca, altrimenti al tentativo dopo si creerebbe un
doppione dall'altra parte. Il primo `save()` riuscito cancella l'errore.
