---
icon: triangle-exclamation
---

# Errori e log

Tre tipi di errore, tre strade diverse. Sbagliare strada è il modo più veloce
per riempire il log di cose che non servono e perdere quelle che servono.

| Tipo | Come si solleva | Dove finisce |
|---|---|---|
| Dell'utente | `UserError::make('stock.insufficient', ['product' => $nome])` | messaggio tradotto, nessun log |
| Di un servizio esterno | `ProviderError::make($provider, $azione, $messaggio, $contesto)` | log del provider + `error_reports` |
| Interno | qualunque `Throwable` non gestito | `gestionale.log` con il contesto |

## Errore dell'utente

Non è un guasto, è una risposta: "non c'è abbastanza merce", "questo ordine è
già chiuso". Il testo sta in `lang/it/gestionale.json` sotto `errors`, così si
riscrive senza toccare il codice.

```php
throw UserError::make('stock.insufficient', ['product' => $prodotto['name']]);
```

## Errore di un servizio esterno

```php
Errors::provider(ProviderError::make(
    'fatture-in-cloud',
    'invoice.send',
    'Timeout',
    ['invoice' => $numero]
));
```

Finisce in `storage/logs/error/fatture-in-cloud.log` e diventa una riga di
`error_reports` del core. Il secondo argomento di `Errors::provider()` dice chi
avvisare: `developer` (default) o `merchant`.

## Errore interno

```php
Errors::internal($throwable, 'order.confirm', ['order' => $id]);
```

Log e basta: all'utente si mostra un messaggio generico. In webhook, cron e
comandi il log non interrompe mai l'esecuzione.

## Chi riceve le email

Gli indirizzi stanno nelle impostazioni, separati da virgola:
`developer_error_emails` in **Set Up → Impostazioni**, `merchant_error_emails`
nelle impostazioni del negozio. Il core non li conosce: glieli passa il modulo
al momento della segnalazione.

Il primo errore manda l'email, i successivi alzano solo il contatore. Segnando
l'errore risolto la riga si chiude; se il problema torna, la riga riapre e
l'email riparte — è così che ci si accorge che la correzione non ha tenuto.

La pagina è **Set Up → Errori** (`admin`); il riquadro "Da controllare" della
home mostra gli stessi errori, filtrati per chi guarda.
