# API REST

Prodotti, giacenze, ordini e clienti si leggono e si modificano anche con
richieste HTTP normali, per le integrazioni che non parlano MCP (gestionali
esterni, script, automazioni).

Ogni rotta chiama uno strumento del [server MCP](mcp.md), quindi valgono gli
stessi permessi, scope, regole e registro delle scritture. Nel codice ci sono
due pezzi:

- `Support\Api\Rest` traduce la richiesta in strumento e argomenti, e l'esito
  in un codice HTTP;
- `http/api/v1.php` gestisce la richiesta: autentica, chiama
  `Server::invoke()` e risponde.

## Rotte

Base: `https://<sito>/api/gestionale/v1`. Le rotte si chiamano
`api.gestionale.v1.*` e le registra `config/routes/route.api.php` da
`Rest::routes()`.

| Metodo e percorso | Strumento | Note |
|---|---|---|
| `GET /prodotti/` | `products_list` | `limit`, `offset`, `categoria`, `attivo`; con `q` diventa `products_search` |
| `GET /prodotti/{id}/` | `product_get` | |
| `POST /prodotti/` | `product_create` | 201 |
| `PATCH /prodotti/{id}/` | `product_update` | |
| `POST /prodotti/{id}/giacenza/` | `stock_adjust` | `mode`, `quantity`, … |
| `GET /ordini/` | `orders_list` | `stato`, `q`, `limit`, `offset` |
| `GET /ordini/{id}/` | `order_get` | |
| `POST /ordini/{id}/azioni/` | `order_action` | `action` e i suoi campi |
| `GET /clienti/` | `customers_list` | `q`, `limit`, `offset` |
| `GET /clienti/{id}/` | `customer_get` | anagrafica, fatturazione, indirizzi |

Gli argomenti hanno gli stessi nomi degli strumenti MCP:

- le `GET` li prendono dalla query; `q`, `stato`, `categoria` e `attivo`
  diventano `query`, `status`, `category_id` e `active`;
- le altre richieste li prendono dal corpo in JSON (`Content-Type:
  application/json`); il form vale solo per `POST`, perché PHP non legge il
  corpo di una `PATCH` in form;
- `{id}` del percorso vince sull'id del corpo.

I clienti si possono solo leggere. La scheda cliente si modifica dal backend,
dove passa per le sue regole (partita IVA unica, export, ecc.).

## Autenticazione

Ogni richiesta manda `Authorization: Bearer <token>`.

- **JWT di `api_users`**: funziona sempre, anche con la funzionalità `mcp`
  spenta. Le verifiche sono quelle del server MCP: HTTPS, utente con
  `api_internal_user`, IP e domini permessi.
- **Token OAuth `wio_at_`**: funziona solo con `mcp` accesa, perché il token è
  legato alla risorsa `/api/gestionale/mcp` e non c'è una seconda risorsa
  OAuth. Gli scope limitano le rotte come limitano gli strumenti.

Senza token, o con un token non valido, la risposta è 401 con
`WWW-Authenticate` (`Auth::challenge()`).

## Risposte

Le risposte usano la busta comune del framework (`ApiRequest::ok()` /
`error()`):

```json
{"success": true, "status": 200, "response": { … }}
{"success": false, "status": 404, "response": "Prodotto 12 non trovato."}
```

| Codice | Quando |
|---|---|
| 200 / 201 | riuscita (201 per `POST /prodotti/`) |
| 401 | token assente o non valido |
| 403 | il token non ha il permesso o lo scope della rotta |
| 404 | rotta inesistente, o risorsa non trovata (`McpError` con chiave `*_not_found`) |
| 422 | campi obbligatori assenti o regola violata; il messaggio dice quale |
| 500 | errore interno, scritto nel log di PHP |

Il `kind` restituito da `Server::invoke()` decide il codice (`Rest::status()`).

## Prove

- `php tests/RestApiTest.php` prova la traduzione delle rotte, i codici e gli
  esiti di `Server::invoke()`.
- Il percorso HTTP si prova sul sito di prova con il token dell'utente `@mcp`.
