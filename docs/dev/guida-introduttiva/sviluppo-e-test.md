---
icon: flask
---

# Sviluppo, test e sito di prova

## Sito di prova

`boilerplates/ecommerce-site` risponde su `https://ecommerce.test` con il
database `ecommerce_site` e ha il modulo collegato da repository `path`. È lì
che si prova tutto: `forge update`, il backend nel browser, gli screenshot
della guida.

```bash
cd /Users/andreamarinoni/Developer/boilerplates/ecommerce-site
php forge update
```

`APP_ENV=local` nel `.env` tiene il sito in locale; mettendo `production` si
provano la sola lettura e la sincronizzazione — ricordarsi di rimetterlo.

## Test

```bash
php tests/run.php            # tutto
php tests/TaxTotalsTest.php  # un file solo
```

| Livello | Cosa copre | Database |
|---|---|---|
| Unitari | classi pure: codici, numeri, IVA, stato delle funzionalità, controlli dei Primi passi | no |
| Convenzioni | ogni Resource dichiara la sua funzionalità, prefisso `gst_`, id stabili nelle tabelle sincronizzate | no |
| Integrazione | righe precaricate, numerazione, log degli stati, sync, sola lettura | `ecommerce_site` |

I test d'integrazione stanno in `tests/integrazione/` e girano **dentro una
transazione che annulla sempre**, anche quando falliscono: il database del sito
resta com'era. Le righe che servono se le creano da soli, senza dare per
scontato cosa c'è nel sito.

GitHub Actions esegue unitari e convenzioni a ogni push; l'integrazione resta
locale, perché vuole il database.

## Comandi

```bash
php forge gestionale:demo              # dati di prova, solo in locale
php forge gestionale:demo --fresh      # toglie i dati di prova e li rifà
php forge gestionale:features-doc      # riscrive la tabella nella guida
```

## Trappole già pagate

- Il registro dei Model legge la **prima** parola `class` del file, commenti
  compresi: un esempio di codice in un docblock fa fallire il boot.
- `Model::prepare()` non genera i codici `uniqueCode()`: chi inserisce da codice
  se li fa dare dal Model.
- Una colonna che vive in un'altra tabella si mostra nell'elenco con un
  formatter a closure, non dichiarandola e basta.
- `$ALERT` non vuoto significa errore e blocca il salvataggio: per dire che è
  andata bene c'è il toast.

## Interfaccia del backend

- Niente `<wi-card>`: il core lo dà per dismesso. I riquadri della home
  passano da `WidgetCard::wrap()` (tutta riga) o `WidgetCard::half()` (mezza
  riga da `md`), che rendono una `Card` del core. Nelle viste con cicli PHP
  (scheda lotto, documenti di magazzino) il riquadro è scritto con le stesse
  classi della `Card`: `card border` e `card-body row g-3`, così i figli
  `col-*` restano dove erano.
- Gli script delle pagine non dipendono più dalla ricopiatura dei
  `<wi-card>`: l'editor dei documenti parte con «loaded» o subito se la
  pagina è già pronta, e i bottoni del form si cercano a ogni uso.
- Un widget della home usa prima una card del core (`StatusCard` per un
  numero che apre un elenco, `LinkListCard` per un elenco di link), messa in
  colonna con `WidgetCard::column()`. `WidgetCard::wrap()` resta per i
  riquadri con elenchi di dati; i bottoni dentro passano da
  `WidgetCard::button()`, cioè da `Button` del core.
- I badge (`<span class="badge text-bg-*">`) restano scritti a mano, negli
  helper (`StatusLabels`, `StockBadge`, `BatchExpiry`, `DuePayments`,
  `PromotionSheet`, `OrderSheet`) come nelle Resource: `Badge` del core li
  avvolge sempre in un `<div class="col-span-*">`, che non può stare in una
  cella accanto a un testo, in un titolo o in un `<span>`. Si passa a `Badge`
  quando il core avrà un modo in linea come quello di `Button`.
