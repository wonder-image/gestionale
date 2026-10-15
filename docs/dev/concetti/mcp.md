# Server MCP

Il gestionale espone un server [MCP](https://modelcontextprotocol.io) per gli
agenti IA: leggono e modificano il catalogo e cambiano lo stato degli ordini
con gli stessi Model, le stesse regole e gli stessi permessi del backend.

- Indirizzo: `https://<sito>/api/gestionale/mcp/` (rotta
  `api.gestionale.mcp`, gestore `http/api/mcp.php`).
- Trasporto Streamable HTTP, solo `POST` con un messaggio JSON-RPC alla volta
  (niente batch, sessioni o stream). `GET` risponde 405, una notifica 202 senza
  corpo, il resto 200 in JSON.
- Codice in `src/Support/Mcp/`: `Auth` (token), `Caller` (chi chiama),
  `Server` (protocollo), `Tools` (strumenti), `McpError` (errori tradotti in
  `gestionale.errors.mcp.*`).

## Autenticazione

Ci sono due modi di autenticarsi, entrambi con `Authorization: Bearer <token>`.

- **OAuth** (consigliato): connettore remoto su claude.ai o altri client MCP
  (ChatGPT, Cursor, Claude Code). L'URL è `https://<sito>/api/gestionale/mcp`;
  login e consenso si fanno nel browser, senza copiare token. La risorsa è
  registrata con `OAuth::resource()` in `config/routes/route.api.php`, la
  verifica è di `ResourceGuard::authenticate()` del framework. Il 401 senza
  token annuncia `resource_metadata`, da cui il client scopre l'authorization
  server. L'URL da incollare compare anche nella pagina backend "Connessioni
  MCP". Dettagli, `.env` e deploy: `docs/app/piattaforma/oauth.md` del framework.
- **JWT `api_users`**: resta per le integrazioni server-to-server. Stesse
  verifiche di `Endpoint.php`: HTTPS, JWT firmato con la chiave dell'app,
  utente attivo con l'autorità `api_internal_user`, riga del token attiva, IP e
  domini permessi. Senza token o con un token non valido risponde 401 con
  `WWW-Authenticate: Bearer`.

Il modulo richiede una versione di `wonder-image/app` con OAuth: al rilascio
va alzato il vincolo in `composer.json` (oggi `^2.4.0-beta.3 || dev-main`).
Dopo l'aggiornamento serve `php forge update`: crea le tabelle `oauth_*` e la
colonna `mcp_logs.oauth_grant_id`.

## Strumenti e permessi

Ogni strumento prende i ruoli dal `permissionSchema()` della Resource (o dal
`navigationSchema()` per le pagine): l'utente del token li deve avere oltre
ad `api_internal_user`. Chi non li ha non vede lo strumento in `tools/list` e
non lo può chiamare.

| Strumento | Fa | Permesso |
|---|---|---|
| `products_search` | cerca le versioni vendibili | ProductResource `list` |
| `products_list` | versioni a pagine (`limit`, `offset`), filtri categoria e attiva | ProductResource `list` |
| `product_create` | articolo semplice con opzione e versione: obbligatori `name` e `price`; di serie unità `pz`, IVA predefinita, pubblicato e visibile online; facoltativi SKU, EAN, prezzo scontato, descrizione breve, categorie | ProductModelResource `store` |
| `product_get` | una versione con prezzi, giacenza, articolo e categorie | ProductResource `view` |
| `product_update` | prezzo, prezzo scontato, attivo, ordinabile se esaurito, visibile online | ProductResource `update` |
| `stock_adjust` | rettifica di magazzino (aggiungi, togli, imposta) con causale | StockAdjustmentResource |
| `categories_list` | albero delle categorie | CategoryResource `list` |
| `category_create` | nuova categoria con codice e slug: obbligatorio `name`; di serie nella radice e visibile | CategoryResource `store` |
| `category_update` | nome, genitore, visibilità | CategoryResource `update` |
| `product_set_categories` | categorie e principale di un articolo | ProductResource `update` |
| `orders_list` | ultimi ordini con le azioni disponibili | OrderResource `list` |
| `order_get` | un ordine con le righe | OrderResource `view` |
| `order_action` | conferma, annulla, evadi, pronto per il ritiro, ritirato | OrderActionResource `update` |

Le liste restituiscono al massimo 50 righe. Le scritture girano in una
transazione; un errore delle regole torna come risultato con `isError`, non
come errore del protocollo.

## Registro

Ogni chiamata a uno strumento che scrive, riuscita o no, lascia una riga in
`gst_mcp_logs` (`Models\System\McpLog`): strumento, utente, token, soggetto,
argomenti, stato prima e dopo, esito, errore e IP. La riga si scrive fuori
dalla transazione dello strumento, quindi resta anche quando lo strumento
torna indietro.

## Collegare Claude Code

Con OAuth (consigliato):

```bash
claude mcp add --transport http wonder-gestionale https://<sito>/api/gestionale/mcp
```

Al primo uso Claude Code apre il browser per login e consenso. L'utente deve
avere `administrator` (o i ruoli degli strumenti che servono).

Alternativa con token JWT:

1. Un utente con `administrator` (o i ruoli degli strumenti che servono) e
   `api_internal_user`, con il suo token in `api_users`.
2. Dal sito:

   ```bash
   claude mcp add -s local --transport http wonder-gestionale https://<sito>/api/gestionale/mcp/ --header "Authorization: Bearer <token>"
   ```

`claude mcp list` deve mostrare il server connesso.

## Prove

- `php tests/integrazione/McpServerTest.php`: protocollo, permessi e tutti
  gli strumenti; le scritture tornano indietro alla fine.
- `php tests/integrazione/McpHttpTest.php`: il server vero via HTTPS. Usa
  l'utente `WI_TEST_MCP_USER` (di serie `@mcp`); senza, salta le prove
  autenticate.
