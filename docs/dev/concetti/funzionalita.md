---
icon: toggle-on
---

# Funzionalità e ruoli

Il gestionale contiene molto più di quello che un singolo commerciante usa. Le
funzionalità stanno tutte nel codice e si **sbloccano dal pannello**: finché una
funzionalità è bloccata, le sue pagine non esistono per il backend.

## Dove sta cosa

| Cosa | Dove |
|---|---|
| Catalogo delle funzionalità | `config/features.php` del pacchetto |
| Funzionalità aggiunte dal sito | `features.extra` nella configurazione del modulo |
| Funzionalità sbloccate alla prima installazione | `features.unlock` nella configurazione del modulo |
| Stato | tabella `gst_features`, storico in `gst_feature_logs` |
| Pannello | Set Up → Funzionalità, solo `admin` |

Una voce del catalogo ha chiave, nome, descrizione, area, dipendenze
(`requires`) e, per le funzionalità dell'ecommerce, il modulo richiesto
(`module`).

```php
'returns' => [
    'name' => 'Resi',
    'description' => 'Richiesta, approvazione, motivo e ricarico a magazzino.',
    'area' => 'Vendite',
    'requires' => ['orders'],
    'release' => 'G4',
],
```

## Stato effettivo

Una funzionalità è attiva solo se è sbloccata, **tutte** le sue dipendenze sono
attive e il modulo richiesto è abilitato. Si legge sempre così:

```php
if (Gestionale::feature('orders')) { … }
```

Il calcolo si fa una volta per richiesta. `Gestionale::reset()` lo azzera (serve
nei test e dopo un salvataggio del pannello).

## Pagine legate a una funzionalità

Ogni Resource del modulo dichiara la funzionalità che la governa:

```php
final class OrderResource extends GestionaleResource
{
    public static string $feature = 'orders';
    public static string $docsPage = 'ordini';
}
```

Con la funzionalità bloccata spariscono voce di menu, pagine e API: il router
non registra nemmeno le route. Un test di convenzione controlla che nessuna
Resource con dati resti senza `$feature`.

## Sblocco e blocco

Il pannello è una pagina sola con un interruttore per funzionalità, raggruppati
per area. Al salvataggio valgono due regole:

- **sbloccando** una funzionalità si sbloccano anche le dipendenze che mancano;
- **bloccando** una funzionalità si bloccano anche quelle che dipendono da lei.

Il messaggio dopo il salvataggio dice cosa è cambiato, e ogni cambio finisce in
`gst_feature_logs` con autore, valore precedente e nuovo, e la nota
"dipendenze" quando il cambio è arrivato di conseguenza.

Bloccare **non cancella mai i dati**: risbloccando si ritrova tutto.

## Esportazioni

L'area **Esportazioni** decide quali file offre «Esporta» nella tabella dei
prodotti: `export_xlsx`, `export_csv`, `export_meta` (Catalogo Meta, .csv) e
`export_google` (Google Merchant Center, .xml). Con nessuna concessa il
pulsante sparisce. Per un sito che le vuole dal primo giorno:

```php
'features' => ['unlock' => ['export_xlsx', 'export_csv', 'export_meta', 'export_google']],
```

Il registro è `Support\Catalog\Exports\ProductExports`: formato → funzionalità,
etichetta, estensione e nome del file. Una nuova esportazione è una voce lì,
una funzionalità qui e un ramo in `ProductExports::body()`; le classi pure
`Support\Export\Delimited` (csv/tsv), `XmlFeed` (rss 2.0 con namespace) e
`Download` (intestazioni dello scaricamento) fanno la scrittura.

Clienti e fornitori hanno il loro «Esporta» con `export_contacts_xlsx` e
`export_contacts_csv` (registro `Support\Contacts\ContactExports`): stesse
colonne della rubrica di `wonder-image/customer-account` (`ContactExport`:
anagrafica, recapiti, fatturazione, stato, creazione), celle scritte come
testo (`downloadAsText()` del core), tutte le righe dell'elenco senza i filtri
della tabella. Le due funzionalità sono bloccate di serie.

I feed leggono due impostazioni facoltative del modulo: `exports.currency`
(codice ISO, di serie `EUR`) e `exports.country` (il paese con cui si sceglie
l'aliquota per portare i prezzi al lordo quando il catalogo è senza IVA, di
serie `IT`).

### Feed a indirizzo fisso

Con `export_meta` e `export_google` sbloccate, gli stessi file stanno anche in
`shared/feeds/` (accanto alla sitemap; il `.htaccess` serve i file esistenti):
`catalogo-meta.csv` e `google-merchant.xml`. L'indirizzo che il pannello
mostra si costruisce da `APP_DOMAIN` (il dominio di produzione: il pannello si
compila in locale), con ripiego su `APP_URL`.

Li scrive l'attività dello scheduler `gestionale.product_feeds`
(`Scheduler\ProductFeedsTask`, `7 * * * *`, accesa di serie): per ogni feed,
funzionalità sbloccata e file scaduto → riscrive (su un file temporaneo e poi
`rename`, chi legge non vede mai un file a metà); bloccata → cancella il file.
Il primo file compare al primo passaggio dopo il deploy, quindi lo scheduler
deve girare in produzione.

Ogni quanto riscrivere si sceglie nel pannello, sotto l'interruttore del feed:
`ProductFeeds::EVERY` (1, 6, 24 ore; di serie `DEFAULT_EVERY = 6`, perché
Google e Meta leggono il file al massimo una volta al giorno). La scelta sta
nella colonna json `settings` di `gst_features` (`{"every": 6}`), letta da
`FeaturePanel::settings()` e salvata da `FeaturePanel::save(..., $settings)`:
così viaggia con `sync-data.json` come lo stato delle funzionalità, invece di
stare in `scheduler_schedules`, che vale per un solo ambiente. Logica in
`Support\Catalog\Exports\ProductFeeds` (`due()`, `refresh()`, `url()`).

## Ambienti

`gst_features` si sincronizza con `id` stabili e si modifica **solo in locale**:
in produzione il pannello è in sola lettura e un salvataggio forzato riceve 403.
Lo stato viaggia con `shared/sync-data.json` al deploy.

## Ruoli

| Ruolo | Chi | Cosa fa |
|---|---|---|
| `admin` | Wonder Image | sblocca le funzionalità, configura la parte tecnica |
| `administrator` | commerciante | usa solo le funzionalità attive |
