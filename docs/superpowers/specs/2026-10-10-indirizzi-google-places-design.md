# Indirizzi con Google Places — design

Data: 2026-10-10 · Rami per il lavoro: `indirizzi-places` (lib, app, gestionale, ecommerce),
ciascuno nel suo worktree. Si parte dopo l'unione di `ritocchi-rapido` (ecommerce), che
tocca gli stessi file del checkout.

Riferimenti: spec del checkout `2026-10-07-checkout-pagina-unica-design.md`, spec del
pannello account `2026-10-08-pannello-account-design.md`.

## 1. Obiettivo

Ogni form con un indirizzo, nel frontend e nel backend, suggerisce gli indirizzi di
Google mentre si scrive la via e riempie gli altri campi. È un aiuto, mai un vincolo:
l'indirizzo scritto a mano resta sempre valido.

Criteri di riuscita:

- scrivendo la via compaiono i suggerimenti; scegliendone uno si riempiono via, civico,
  CAP, città, nazione e provincia;
- dove il modello lo chiede, si salvano anche il place ID di Google e le coordinate;
- senza chiave, con la quota finita o se Google non risponde, il form funziona come
  oggi, senza errori visibili;
- il motore vecchio (`initGoogleMapsPlace`) è sostituito e i siti che chiamano
  `googleAddress()` continuano a funzionare;
- nessun test chiama Google vero.

## 2. Fuori

- Mappe nei form (scegliere il punto trascinando un marker).
- Validazione dell'indirizzo lato server con Google (Address Validation API).
- Calcolo di distanze o zone di consegna dalle coordinate: le coordinate si salvano e
  basta, l'uso arriverà dopo.
- Riempire place ID e coordinate per gli indirizzi già salvati (nessuna migrazione dei
  dati vecchi: restano vuoti finché qualcuno non li modifica col suggerimento).
- Il check-out rapido (Express Checkout): l'indirizzo arriva dal wallet, place ID e
  coordinate restano vuoti.

## 3. Decisioni prese

| # | Decisione |
|---|-----------|
| P1 | Motore riscritto da zero su Places API (New): `AutocompleteSuggestion` + `Place.fetchFields`, con session token. Niente più `google.maps.places.Autocomplete`. |
| P2 | Si carica col loader moderno già nella lib (`requireGoogleMaps` in `src/build/global/js/gmaps.js`, `importLibrary`), senza un secondo `<script>` e senza callback globale. |
| P3 | Aggancio con un attributo sull'input della via: `data-wi-place="<prefisso>"`. Lo mette `AddressExtension`; i form fatti a mano lo scrivono loro. |
| P4 | L'interfaccia dei suggerimenti è nostra (lista sotto l'input), non il widget di Google: stessa resa nei due temi e nel backend. |
| P5 | Non bloccante: mai `disabled`, mai messaggi d'errore, mai obbligo di scegliere dall'elenco. |
| P6 | Place ID e coordinate si salvano solo dove il modello accende `->withGeo()` su `AddressExtension`. Altrove si riempiono solo i campi visibili. |
| P7 | `withGeo` acceso su: Sedi (indirizzo e sede legale), indirizzi dei contatti, indirizzo di spedizione degli ordini. |
| P8 | Le Sedi riusano la colonna esistente `google_place_id` per l'indirizzo principale e riempiono anche il link `gmaps`. |
| P9 | `googleAddress()` (helper procedurali, `FormField->googleAddress()`, componente) resta con la stessa firma e usa il motore nuovo: due siti cliente lo chiamano ancora. |

## 4. Lib: il motore (`src/build/global/js/form/places.js`)

Il file nuovo sostituisce `global/js/form/maps.js` ed entra in tutti e due i bundle.
Da `frontend/js/form/place.js` escono solo `checkInputPlace` e `setPlace`; nazione e
province (`setInputCountry`, `setCountryState`, `searchStates` nel frontend,
`searchStates` nel backend) restano dove sono e il motore le usa.

### 4.1 Avvio

- All'avvio della pagina (e dopo i contenuti caricati via AJAX: modali, righe nuove dei
  repeater, tramite lo stesso aggancio che usano gli altri componenti dei form) il
  motore cerca `input[data-wi-place]` non ancora agganciati.
- Se non ne trova, o se `GOOGLE_API_KEY` manca o è vuota, non fa nulla: nessun
  caricamento di Google.
- Al primo input trovato chiama `requireGoogleMaps(['places'])` con la lingua della
  pagina (`document.documentElement.lang`). Il caricamento è uno solo per pagina.
- Se il caricamento fallisce, l'errore va in `console.warn` e gli input restano normali.

### 4.2 I campi di un indirizzo

Dall'input della via (`name="<p>street"`, dove `<p>` è il valore di `data-wi-place`,
anche vuoto) il motore trova i fratelli per nome dentro il contenitore più vicino tra
`[data-wi-place-scope]` e la `form`:

| Campo | Nome | Obbligatorio per il motore |
|-------|------|----------------------------|
| via | `<p>street` | sì (è l'input agganciato) |
| civico | `<p>number` | no |
| CAP | `<p>cap` | no |
| città | `<p>city` | no |
| nazione | `<p>country` | no |
| provincia | `<p>province` | no |
| place ID | `<p>place_id`, o il nome in `data-wi-place-id` | no |
| latitudine | `<p>lat` | no |
| longitudine | `<p>lng` | no |
| link Maps | il nome in `data-wi-place-link` | no |

Nei repeater i nomi sono del tipo `addresses[3][street]`: in quel caso `data-wi-place`
vale `addresses[3][` e il motore costruisce i nomi come `<p>` + chiave + `]`. La regola:
se `<p>` finisce con `[`, si aggiunge `]` dopo la chiave.

### 4.3 Suggerimenti

- Partono da 3 caratteri, 250 ms dopo l'ultima battuta. Una richiesta superata da una
  più nuova viene ignorata quando arriva.
- `AutocompleteSuggestion.fetchAutocompleteSuggestions` con `input`, `sessionToken`,
  `language`, `includedPrimaryTypes: ['street_address','premise','subpremise','route']`
  e, se la select della nazione ha un valore, `includedRegionCodes: [<iso2 minuscolo>]`.
- Lista sotto l'input, massimo 5 voci, testo principale in grassetto e secondario sotto.
- Accessibile: input con `role="combobox"`, `aria-expanded`, `aria-controls`,
  `aria-activedescendant`; lista `role="listbox"`, voci `role="option"`.
- Tastiera: ↓ ↑ si muovono, Invio sceglie (e non invia la form se la lista è aperta),
  Esc chiude. Clic fuori chiude. `autocomplete="off"` sull'input solo mentre il motore è
  attivo, per non sovrapporre l'autocompletamento del browser.
- In fondo alla lista il logo «powered by Google», come chiedono i termini di Google
  quando i suggerimenti non stanno su una mappa.
- Un session token per ogni ricerca: si crea alla prima battuta, si usa per i
  suggerimenti e per `fetchFields`, poi si butta.

### 4.4 Scelta di un suggerimento

`place.fetchFields({ fields: ['addressComponents', 'location', 'id'] })` (solo campi
Essentials). Poi, in quest'ordine:

1. **Nazione e provincia.** `country` short, se il valore esiste tra le opzioni della
   nazione, si imposta con le funzioni che ci sono già, passando come provincia
   iniziale la chiave `administrative_area_level_2` short:
   - frontend: `setCountryState(input, iso, chiave)` (gestisce anche la nazione come
     lista di testo);
   - backend: valore della select, `change`, poi `searchStates(input, chiave)`.

   Tutte e due tengono da parte la provincia e la scelgono a fine caricamento; se la
   chiave non è tra le opzioni, la select resta vuota. Il resto della provincia è al
   punto 6.
2. **Via.** `route` long.
3. **Civico.** `street_number`. Se Google non lo dà, il civico già scritto resta.
4. **CAP.** `postal_code`.
5. **Città.** Il primo tra `locality`, `postal_town`, `administrative_area_level_3`.
6. **Provincia, altri tentativi.** Se dopo il caricamento la provincia è vuota, si
   prova sulle opzioni caricate, in quest'ordine:
   - chiave uguale a `administrative_area_level_1` short (Stati Uniti, Canada…);
   - nome uguale (senza accenti, maiuscole e spazi doppi) a level_2 long, poi level_1 long.

   Il caricamento è finito quando `dataset.wiListStates` vale la nazione scelta e la
   select non è più `disabled` (vale nei due bundle); si aspetta al massimo 5 s. Se
   nessuna opzione corrisponde, o il tempo scade, la provincia non si tocca. Se la
   nazione non è cambiata, i tentativi si fanno subito sulle opzioni presenti.
7. **Geo.** Se i campi esistono: place ID ← `id`; lat/lng ← `location` con 7 decimali;
   link ← `https://www.google.com/maps/search/?api=1&query=<lat>,<lng>&query_place_id=<id>`.

Ogni campo cambiato riceve `input` e `change`, così salvataggio, controlli e barra di
salvataggio del backend se ne accorgono.

### 4.5 Correzione a mano

Dopo una scelta, se l'utente modifica a mano via, civico, CAP, città o nazione, place ID,
coordinate e link si svuotano: non corrisponderebbero più all'indirizzo. Una modifica
fatta dal motore stesso non li svuota.

### 4.6 Nomi vecchi

- `initGoogleMapsPlace` e `setGoogleMapsApi` restano come funzioni globali che avviano il
  motore nuovo (§4.1).
- `checkInputPlace`, `setPlace` e `placeNeedCheck` vengono tolti.
- Un input vecchio con `data-wi-search-place` è trattato come `data-wi-place` senza
  prefisso. I suoi 6 campi nascosti `data-wi-spi` vengono riempiti come prima, così le
  pagine rimaste al markup vecchio continuano a ricevere i dati.

### 4.7 Stile

Le classi della lista (`wi-place-list`, `wi-place-option`, `wi-place-option--active`)
stanno nel CSS dei form della lib, nei due temi, con le variabili di colore già usate dai
menu a tendina.

## 5. App

### 5.1 `AddressExtension`

- Mette sempre `data-wi-place="<prefisso>"` sull'input della via, in tutti e due i temi.
- Nuovo `->withGeo(?string $placeIdKey = null)`:
  - colonne: `<p>place_id` varchar(255), `<p>lat` decimal(10,7), `<p>lng` decimal(10,7),
    tutte nullable;
  - campi nascosti nel form, con `data-wi-place-id` sull'input della via se il nome del
    place ID è personalizzato;
  - con `$placeIdKey` il place ID usa quella colonna invece di `<p>place_id` (le Sedi:
    `google_place_id`, che esiste già e non va ricreata);
  - se l'estensione ha il link (`withLink` / `linkKey`), l'input della via riceve
    `data-wi-place-link="<nome del link>"`.
- Pulizia in salvataggio (in `UploadSchema` dei tre campi): lat in [-90, 90], lng in
  [-180, 180], tutte e due o nessuna; place ID con
  `^[A-Za-z0-9_-]{10,255}$`. Un valore fuori regola diventa `null`, senza errore.

### 5.2 Dove si accende

- `SocietyLocation` / `SocietyLocationResource`: indirizzo principale
  `->withGeo('google_place_id')`, sede legale `->withGeo()`. Il campo di testo
  `google_place_id` nella scheda resta visibile (si può ancora scrivere a mano) ma
  riceve il valore dal motore.
- `ContactAddress` / `ContactAddressResource`: `->withGeo()`.
- Area cliente, indirizzi (`AccountAddressForm`): i campi nascosti e l'attributo; il
  controller accetta i tre valori e li pulisce con la stessa regola (§5.3).

### 5.3 Una sola regola di pulizia

`Wonder\App\Support\GoogleMaps::cleanGeo(array $input, string $prefix = ''): array`
restituisce `['<p>place_id' => ?string, '<p>lat' => ?float, '<p>lng' => ?float]` con la
regola del §5.1. La usano `AddressExtension`, l'area cliente e il checkout.

### 5.4 `googleAddress()`

- `FormField->googleAddress(array $restriction = [], ?string $alias = null)` e il
  componente `GoogleAddress` rendono l'input della via con `data-wi-place` (e
  `data-wi-restriction` diventa la nazione iniziale di `includedRegionCodes` se il form
  non ha una select della nazione). Niente più `disabled`.
- Gli helper procedurali `googleAddress()` di frontend e backend tengono la stessa firma
  e lo stesso markup di uscita (input + 6 nascosti `data-wi-spi`), ma col motore nuovo.
- Doc corretta in `app/docs/components/form/google-address.php` e
  `lib/docs/integrations/google-maps.md`.

## 6. Gestionale ed ecommerce

### 6.1 Ordini

`Order::shippingAddress()` accende `->withGeo()`: nascono `shipping_place_id`,
`shipping_lat`, `shipping_lng`. La fatturazione no.

### 6.2 Checkout

- Spedizione e fatturazione hanno l'aiuto (vengono da `AddressExtension` o ricevono
  l'attributo nella view).
- Solo la spedizione ha i tre campi nascosti. `CheckoutController` li passa da
  `GoogleMaps::cleanGeo($input, 'shipping_')` e li salva sull'ordine.
- Indirizzo salvato del cliente scelto al checkout: place ID e coordinate vengono
  copiati dal `ContactAddress` all'ordine. Indirizzo nuovo salvato dal checkout nella
  rubrica: si porta dietro i tre valori.
- Check-out rapido: i tre campi restano vuoti (§2).

### 6.3 Form fatti a mano nel gestionale

- Repeater indirizzi nella scheda cliente/fornitore (`CustomerResource`, righe
  `addresses[n][...]`): ogni riga è un `data-wi-place-scope` e la via ha
  `data-wi-place="addresses[n]["`. Niente campi geo nel repeater.
- Modale «Aggiungi indirizzo» (`ContactAddressResource` del gestionale): attributo sulla
  via e i tre campi nascosti, perché salva un `ContactAddress`.
- `LocationResource`: usa `AddressExtension`, riceve l'aiuto da sé.

## 7. Errori e casi limite

| Caso | Comportamento |
|------|---------------|
| Chiave vuota | Il motore non parte, nessuna richiesta a Google. |
| Script non caricato / quota finita / errore di rete | `console.warn`, lista chiusa, input normale. |
| Nessun suggerimento | Lista chiusa, nessun messaggio. |
| Nazione del suggerimento non tra le opzioni | Nessun campo toccato tranne via, civico, CAP e città. |
| Provincia non trovata o province lente (> 5 s) | Provincia lasciata vuota (o com'era, se la nazione non è cambiata). |
| Form inviata con la lista aperta e Invio | Sceglie la voce attiva, non invia. |
| Valori geo manomessi nel POST | Diventano `null` (§5.1). |
| Più indirizzi nella stessa pagina (spedizione + fatturazione) | Ogni input ha il suo stato, la sua lista e il suo token. |

## 8. Costi e privacy

- Con il session token, autocomplete + `fetchFields` contano come una sessione; i campi
  chiesti sono solo Essentials. Il link di Maps si costruisce, non si chiede a Google.
- Il testo digitato va a Google solo dopo 3 caratteri e solo nelle pagine col campo.
- La chiave resta quella di oggi (`GCP_CLIENT_API_KEY` → `GOOGLE_API_KEY`). Tocca
  all'utente abilitare **Places API (New)** nel progetto Google Cloud e limitare la
  chiave per referrer HTTP e alle API «Maps JavaScript API» e «Places API (New)».

## 9. Prove

**Lib** (`test/places.test.cjs`, nello stile dei `.test.cjs` esistenti, con un `google`
finto):

- dai componenti dell'indirizzo ai campi, con prefisso e con prefisso da repeater;
- nazione cambiata: la provincia si sceglie solo dopo la fine del caricamento;
- provincia per chiave level_2, per chiave level_1, per nome, e non trovata;
- civico assente da Google: resta quello scritto;
- correzione a mano che svuota place ID, coordinate e link; modifica del motore che non
  li svuota;
- risposta vecchia che arriva dopo una nuova: ignorata;
- nessun caricamento di Google senza chiave o senza `[data-wi-place]`;
- markup vecchio `data-wi-search-place`: riempie i `data-wi-spi`.

**App:**

- `AddressExtension` mette `data-wi-place` sulla via, con e senza prefisso;
- `withGeo()` aggiunge colonne e campi nascosti; con `$placeIdKey` usa quella colonna;
- `GoogleMaps::cleanGeo` accetta valori buoni e annulla quelli fuori regola;
- `googleAddress()` procedurale rende ancora input + 6 nascosti, senza `disabled`.

**Gestionale:** `Order` ha le tre colonne di spedizione e non quelle di fatturazione; il
repeater e la modale hanno attributo e scope.

**Ecommerce:** il checkout salva place ID e coordinate valide, scarta quelle non valide,
copia quelle di un indirizzo salvato.

**Browser** (ecommerce.test e backend, con la chiave dell'utente): checkout spedizione e
fatturazione, area cliente, scheda Sede, scheda cliente con repeater.

## 10. Ordine di lavoro

1. Lib: motore, test, build, nuova alpha (`2.1.2-alpha.25`).
2. App: `AddressExtension->withGeo`, `cleanGeo`, `googleAddress`, Sedi, contatti, area
   cliente, doc.
3. Gestionale: `Order`, repeater, modale.
4. Ecommerce: checkout.
5. Prova nel browser, poi unione dei quattro rami.
