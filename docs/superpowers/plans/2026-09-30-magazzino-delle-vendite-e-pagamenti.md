# Magazzino delle vendite e pagamenti — Piano 2 di 5 di G4

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** dare a G4 i due servizi che scrivono — `Support\Stock\Allocation`,
l'unico punto che tocca il magazzino per una vendita, e
`Support\Payments\Ledger`, l'unico che scrive le righe di denaro e ricalcola il
`payment_status` dell'ordine — con i test che la spec chiede per nome: due
processi che comprano l'ultimo pezzo e la notifica del gateway che arriva due
volte.

**Architecture:** `Allocation` sta accanto a `Stock`, che resta l'unica porta
di scrittura di `gst_stock` e `gst_stock_movements`: `Allocation` non scrive
mai un movimento da sé, lo chiede a `Stock::apply()` cambiando `type` e
`reference_*`. Tutti e cinque i suoi metodi hanno sede, lotto e fornitore nella
firma anche se oggi valgono i predefiniti: è il punto in cui G3 entrerà senza
riaprire gli ordini (D61). `Ledger` scrive `gst_payments` e poi chiama una
classe **pura**, `Support\Payments\PaymentStatus`, che dalla somma delle righe
dice com'è messo l'ordine: il `payment_status` non si scrive mai a mano e il
suo cambio finisce in `gst_payment_status_logs` con `StatusLogger`.

**Tech Stack:** PHP 8.2, `wonder-image/app` ^2.4.0-beta.1 (`Model`,
`Transaction`, `findForUpdate()`, `StatusLog`), harness di test del modulo
(`tests/harness.php`), MySQL del sito di prova
`/Users/andreamarinoni/Developer/boilerplates/ecommerce-site`.

**Spec:** [G4 — Ordini e pagamenti](../specs/2026-09-29-ordini-e-pagamenti-design.md) §1, §2, §3, §5

## Global Constraints

- Lingua: **italiano** nei commenti, nei testi e nei nomi dei test; **inglese**
  per classi, tabelle e colonne. Accenti corretti: `perché`, `più`, `così`.
- **Il magazzino ha una porta sola.** `Allocation` non scrive mai su
  `gst_stock` né su `gst_stock_movements`: passa sempre da `Stock::apply()`.
  L'unica tabella che `Allocation` scrive di suo è
  `gst_stock_reservations`.
- **Sede, lotto e fornitore stanno nella firma di tutti e cinque i metodi** di
  `Allocation`, anche se oggi la sede è `Locations::mainId()` e lotto e
  fornitore sono `0`: G3 cambierà solo il **dentro** dei metodi (D61, spec §1).
- **`payment_status` non si scrive mai a mano:** si ricalcola dalla somma delle
  righe con `PaymentStatus::of()` dopo ogni scrittura di `Ledger`.
- Le classi di `Support\Payments\PaymentStatus` **non leggono il database, non
  chiamano `Model::find()`, non usano `date()`**: prendono array e tornano
  stringhe, come `LinePrice` e `OrderTotals` del Piano 1.
- Ogni scrittura che tocca più tabelle sta dentro `Transaction::run()`, che si
  annida con i savepoint: chiamare `Stock::apply()` da dentro una transazione
  propria è previsto e non apre una seconda transazione.
- Le quantità si scrivono sempre come stringa canonica
  (`number_format($v, 3, '.', '')`), il denaro con
  `number_format($v, 2, '.', '')`.
- Un rifiuto che deve leggere una persona è **`UserError::make('chiave')`**,
  con il testo in `lang/it/gestionale.json` sotto `gestionale.errors`; lo
  controlla `tests/ErrorKeysTest.php`. Un guasto — una `create()` che torna
  `success` falso — è una `RuntimeException`, che la transazione annulla.
- I test d'integrazione stanno in `tests/integrazione/`, girano **dentro una
  transazione annullata** (`final class Annulla extends RuntimeException`) e
  cominciano con le sei righe di intestazione degli altri
  (`const SITE`, `chdir`, i due `require` del sito, `require harness.php`).
  **Eccezione:** i due test a processi separati del Task 6, che per forza
  scrivono davvero e si puliscono da sé.
- Ogni task finisce con un commit sul ramo
  `feature/ordini-magazzino-e-pagamenti` di `packages/gestionale`.
- Test: `php tests/run.php` deve restare verde.

## Review Focus

- **Pagamento a mano senza riferimento del gateway** (il bonifico, i contanti
  al banco): l'indice unico `(provider, provider_reference)` farebbe collidere
  il secondo incasso manuale con il primo, perché il riferimento è vuoto in
  tutti e due. `Ledger` firma la riga con il proprio codice `pay_…`, così due
  incassi manuali uguali restano due incassi. Test nel Task 5.
- **`commit()` di un ordine senza nessuna prenotazione viva** (scaduta e
  liberata dal task delle scadenze): con `backorders` spento e il pagamento
  non riuscito rifiuta con `order.reservation_lost` e non scarica niente; con
  il pagamento riuscito scarica sotto zero e lo dichiara nell'esito. Test nel
  Task 3.
- **Due `register()` con lo stesso `provider_reference`:** la seconda non
  scrive una riga nuova, non ripaga l'ordine e torna lo stesso esito della
  prima. Test nel Task 5, e a processi separati nel Task 6.
- **Reso di una riga con `restock` a `false`** (merce rotta): il reso si
  registra ma nessun movimento `return` entra in magazzino. Test nel Task 4.
- **Ordine di importo zero** (tutto a sconto, o un omaggio): `PaymentStatus`
  dice `paid` senza nessuna riga di pagamento, invece di lasciarlo `unpaid`
  per sempre. Test nel Task 1.

## File Structure

**Creati**

- `src/Support/Payments/PaymentStatus.php` — pura: dalla somma delle righe di
  `gst_payments` allo stato di pagamento dell'ordine.
- `src/Support/Payments/Ledger.php` — apre, registra, fallisce e rimborsa le
  righe di denaro; dopo ogni scrittura ricalcola il `payment_status`.
- `src/Support/Stock/Allocation.php` — i cinque metodi della merce venduta.
- `tests/PaymentStatusTest.php` — la classe pura, senza database.
- `tests/integrazione/AllocationTest.php` — prenotazione, rilascio, scarico,
  rientro da annullamento e da reso.
- `tests/integrazione/LedgerTest.php` — righe di denaro, ricalcolo dello
  stato, log, notifica doppia.
- `tests/integrazione/ContemporaneitaTest.php` — due processi sull'ultimo
  pezzo e due notifiche gemelle.
- `tests/integrazione/supporto/compra.php` — le funzioni che preparano
  articoli, ordini e resi di prova, condivise fra i test.
- `tests/integrazione/supporto/incassa.php` — le stesse, per i pagamenti: lo
  stato riletto dal database e l'ultima riga del registro.

**Modificati**

- `src/Support/Stock/Stock.php` — estrae `allowsBackorder()` (la regola che
  oggi è scritta dentro `apply()`) e accetta `allow_negative`, la chiave che
  solo `Allocation::commit()` usa.
- `lang/it/gestionale.json` — le chiavi d'errore nuove.
- `CHANGELOG.md` — una riga per il piano.

---
### Task 1: `PaymentStatus`, il conto puro di quanto è pagato un ordine

**Files:**
- Create: `src/Support/Payments/PaymentStatus.php`
- Test: `tests/PaymentStatusTest.php`

**Interfaces:**
- Consumes: niente (prima classe del piano).
- Produces:
  - `PaymentStatus::of(float $total, array $payments): string` — uno dei sei
    valori di `Order::PAYMENT_STATUSES`.
  - `PaymentStatus::sums(array $payments): array{paid: float, refunded: float, pending: float}`

- [ ] **Step 1: Scrivi il test rosso**

Crea `tests/PaymentStatusTest.php`:

```php
<?php
/** php tests/PaymentStatusTest.php */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/harness.php';

use Wonder\Plugin\Gestionale\Support\Payments\PaymentStatus;

check('un ordine senza nessuna riga di denaro non è pagato', fn () =>
    PaymentStatus::of(100, []) === 'unpaid'
);

check('una riga in attesa mette l\'ordine in attesa', fn () =>
    PaymentStatus::of(100, [
        ['type' => 'payment', 'status' => 'pending', 'amount' => 100],
    ]) === 'pending'
);

check('una riga fallita non conta: l\'ordine torna non pagato', fn () =>
    PaymentStatus::of(100, [
        ['type' => 'payment', 'status' => 'failed', 'amount' => 100],
    ]) === 'unpaid'
);

check('l\'incasso pieno paga l\'ordine', fn () =>
    PaymentStatus::of(100, [
        ['type' => 'payment', 'status' => 'paid', 'amount' => 100],
    ]) === 'paid'
);

check('un acconto lascia l\'ordine pagato a metà', fn () =>
    PaymentStatus::of(100, [
        ['type' => 'payment', 'status' => 'paid', 'amount' => 40],
    ]) === 'partially_paid'
);

check('due acconti che coprono il totale pagano l\'ordine', fn () =>
    PaymentStatus::of(100, [
        ['type' => 'payment', 'status' => 'paid', 'amount' => 40],
        ['type' => 'payment', 'status' => 'paid', 'amount' => 60],
    ]) === 'paid'
);

check('chi paga più del dovuto ha comunque pagato', fn () =>
    // Succede con le spese di spedizione tolte dopo l'incasso.
    PaymentStatus::of(100, [
        ['type' => 'payment', 'status' => 'paid', 'amount' => 120],
    ]) === 'paid'
);

check('un ordine che non costa niente nasce pagato', fn () =>
    // Tutto a sconto, o un omaggio: nessuno gli manderà mai un euro.
    PaymentStatus::of(0, []) === 'paid'
);

check('il rimborso di tutto rende l\'ordine rimborsato', fn () =>
    PaymentStatus::of(100, [
        ['type' => 'payment', 'status' => 'paid', 'amount' => 100],
        ['type' => 'refund', 'status' => 'paid', 'amount' => 100],
    ]) === 'refunded'
);

check('il rimborso di una parte lascia l\'ordine rimborsato a metà', fn () =>
    PaymentStatus::of(100, [
        ['type' => 'payment', 'status' => 'paid', 'amount' => 100],
        ['type' => 'refund', 'status' => 'paid', 'amount' => 30],
    ]) === 'partially_refunded'
);

check('un rimborso ancora in attesa non conta come rimborso', fn () =>
    PaymentStatus::of(100, [
        ['type' => 'payment', 'status' => 'paid', 'amount' => 100],
        ['type' => 'refund', 'status' => 'pending', 'amount' => 30],
    ]) === 'paid'
);

check('il rimborso di un acconto rimborsa tutto quello che c\'era', fn () =>
    PaymentStatus::of(100, [
        ['type' => 'payment', 'status' => 'paid', 'amount' => 40],
        ['type' => 'refund', 'status' => 'paid', 'amount' => 40],
    ]) === 'refunded'
);

check('gli importi scritti col segno meno valgono comunque', fn () =>
    // Un gateway che manda il rimborso come importo negativo non deve
    // ribaltare il conto.
    PaymentStatus::of(100, [
        ['type' => 'payment', 'status' => 'paid', 'amount' => 100],
        ['type' => 'refund', 'status' => 'paid', 'amount' => -100],
    ]) === 'refunded'
);

check('i centesimi non fanno perdere il pagamento pieno', fn () =>
    PaymentStatus::of(19.999, [
        ['type' => 'payment', 'status' => 'paid', 'amount' => 20],
    ]) === 'paid'
);

check('le somme si leggono anche da sole', function () {
    $somme = PaymentStatus::sums([
        ['type' => 'payment', 'status' => 'paid', 'amount' => 40],
        ['type' => 'payment', 'status' => 'pending', 'amount' => 60],
        ['type' => 'refund', 'status' => 'paid', 'amount' => 10],
        ['type' => 'payment', 'status' => 'failed', 'amount' => 999],
        'riga rotta',
    ]);

    return $somme === ['paid' => 40.0, 'refunded' => 10.0, 'pending' => 60.0];
});

summary();
```

- [ ] **Step 2: Guarda il test fallire**

Run: `php tests/PaymentStatusTest.php`
Expected: `PHP Fatal error: Uncaught Error: Class "Wonder\Plugin\Gestionale\Support\Payments\PaymentStatus" not found`

- [ ] **Step 3: Scrivi la classe**

Crea `src/Support/Payments/PaymentStatus.php`:

```php
<?php

namespace Wonder\Plugin\Gestionale\Support\Payments;

/**
 * Quanto è pagato un ordine, letto dalle sue righe di denaro.
 *
 * Il `payment_status` non si scrive mai a mano: lo si ricalcola da qui dopo
 * ogni riga nuova, ed è `Ledger` a salvarlo. Così un rimborso registrato a
 * mano e uno arrivato dal gateway lasciano l'ordine nello stesso stato.
 *
 * Contano solo le righe `paid`: una in attesa dice soltanto che qualcuno ha
 * cominciato a pagare, una fallita non dice niente. Il verso lo decide il
 * tipo, non il segno dell'importo: certi gateway mandano i rimborsi col meno
 * davanti e non devono ribaltare il conto.
 *
 * Pura: prende array, non tocca il database.
 */
final class PaymentStatus
{
    /**
     * Incassato, rimborsato e in attesa, in euro.
     *
     * @param list<array<string, mixed>> $payments righe di `gst_payments`
     * @return array{paid: float, refunded: float, pending: float}
     */
    public static function sums(array $payments): array
    {
        $sums = ['paid' => 0.0, 'refunded' => 0.0, 'pending' => 0.0];

        foreach ($payments as $payment) {
            if (!is_array($payment)) {
                continue;
            }

            $amount = abs(round((float) ($payment['amount'] ?? 0), 2));
            $isRefund = (string) ($payment['type'] ?? 'payment') === 'refund';
            $status = (string) ($payment['status'] ?? 'pending');

            if ($status === 'paid') {
                $key = $isRefund ? 'refunded' : 'paid';
                $sums[$key] = round($sums[$key] + $amount, 2);
            } elseif ($status === 'pending' && !$isRefund) {
                $sums['pending'] = round($sums['pending'] + $amount, 2);
            }
        }

        return $sums;
    }

    /**
     * Uno dei valori di `Order::PAYMENT_STATUSES`.
     *
     * @param list<array<string, mixed>> $payments righe di `gst_payments`
     */
    public static function of(float $total, array $payments): string
    {
        $sums = self::sums($payments);
        $total = round($total, 2);

        // Un rimborso ha la precedenza su tutto: dice com'è finita.
        if ($sums['refunded'] > 0) {
            return $sums['refunded'] >= $sums['paid'] ? 'refunded' : 'partially_refunded';
        }

        if ($sums['paid'] <= 0) {
            // Un ordine che non costa niente è pagato appena nasce, altrimenti
            // resterebbe da incassare per sempre.
            if ($total <= 0) {
                return 'paid';
            }

            return $sums['pending'] > 0 ? 'pending' : 'unpaid';
        }

        return $sums['paid'] >= $total ? 'paid' : 'partially_paid';
    }
}
```

- [ ] **Step 4: Guarda il test passare**

Run: `php tests/PaymentStatusTest.php`
Expected: `15/15` verdi, nessun avviso.

- [ ] **Step 5: Tutta la suite**

Run: `php tests/run.php`
Expected: tutti i file verdi (il runner trova da sé il file nuovo).

- [ ] **Step 6: Commit**

```bash
git add src/Support/Payments/PaymentStatus.php tests/PaymentStatusTest.php
git commit -m "Conta quanto è pagato un ordine dalle sue righe"
```

---
### Task 2: `Allocation::reserve()` e `release()` — la merce messa da parte

La prenotazione è il punto in cui due clienti che vogliono l'ultimo pezzo si
mettono in fila: il lock sulle righe di `gst_stock` si prende **prima** di
leggere le prenotazioni, così il secondo legge un disponibile che tiene già
conto del primo.

**Files:**
- Create: `src/Support/Stock/Allocation.php`
- Modify: `src/Support/Stock/Stock.php` (la regola del backorder esce da
  `apply()`, e `apply()` impara `allow_negative`)
- Modify: `lang/it/gestionale.json`
- Test: `tests/integrazione/AllocationTest.php`

**Interfaces:**
- Consumes: `Stock::apply(array $movement): array{movement_id,before,after,alert}`,
  `Availability::of(float, array, ?string): float`,
  `Availability::isActive(array, string): bool`,
  `Locations::mainId(): int`, `UserError::make(string, array): UserError`.
- Produces:
  - `Stock::allowsBackorder(array $product): bool`
  - `Stock::apply()` accetta la chiave `allow_negative` (bool, predefinita
    `false`): con `true` la giacenza può scendere sotto zero anche senza
    backorder. **Solo `Allocation` la passa.**
  - `Allocation::reserve(array $line): array{reservation_id: int, quantity: float, available: float, expires_at: string}`
  - `Allocation::release(array $line): int` (quante prenotazioni ha chiuso)
  - Chiavi di `$line` usate da tutti e cinque i metodi: `product_id`,
    `quantity`, `location_id`, `batch_id`, `supplier_id`, `order_id`,
    `order_item_id`.

- [ ] **Step 1: Scrivi il test rosso**

Crea `tests/integrazione/AllocationTest.php`:

```php
<?php
/** php tests/integrazione/AllocationTest.php */
declare(strict_types=1);

const SITE = '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site';

chdir(SITE);
$GLOBALS['ROOT'] = SITE;
require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__ . '/../harness.php';

use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Support\Catalog\Code;
use Wonder\Plugin\Gestionale\Support\Catalog\Skeleton;
use Wonder\Plugin\Gestionale\Support\Catalog\Slug;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Stock\Allocation;
use Wonder\Plugin\Gestionale\Support\Stock\Levels;
use Wonder\Plugin\Gestionale\Support\Stock\Locations;
use Wonder\Plugin\Gestionale\Support\Stock\Stock;
use Wonder\Sql\Transaction;

final class Annulla extends RuntimeException {}

/** Un articolo nuovo con la giacenza chiesta sulla sede principale. */
function articoloConGiacenza(float $pezzi, string $sku): int
{
    $modello = ProductModel::create([
        'code' => Code::make(ProductModel::class, Codes::MODEL),
        'name' => 'Prova vendite '.$sku,
        'slug' => Slug::make('prova-vendite-'.uniqid()),
        'sku' => $sku,
        'unit' => 'pz',
        'type' => 'simple',
        'visible' => 'true',
        'position' => 1,
    ]);
    $scheletro = Skeleton::forModel((int) ($modello->insert_id ?? 0), 'Prova vendite', $sku);
    $productId = $scheletro['product_id'];

    if ($pezzi > 0) {
        Stock::apply([
            'product_id' => $productId,
            'quantity' => $pezzi,
            'type' => 'purchase',
            'reason' => 'initial_stock',
        ]);
    }

    return $productId;
}

/** L'ordine di prova: serve un id vero per le prenotazioni e i log. */
function ordineDiProva(float $totale = 100.0): int
{
    $ordine = Order::create([
        'code' => Code::make(Order::class, Codes::ORDER),
        'stage' => 'order',
        'status' => 'pending',
        'payment_status' => 'unpaid',
        'total' => number_format($totale, 2, '.', ''),
    ]);

    return (int) ($ordine->insert_id ?? 0);
}

try {
    Transaction::run(static function (): void {
        $sede = Locations::mainId();

        check('la prenotazione toglie dal disponibile senza toccare la giacenza', function () use ($sede) {
            $productId = articoloConGiacenza(10, 'TST-ALL-1');
            $ordine = ordineDiProva();

            $esito = Allocation::reserve([
                'product_id' => $productId,
                'quantity' => 3,
                'location_id' => $sede,
                'order_id' => $ordine,
                'order_item_id' => 0,
            ]);

            $livelli = Levels::of($productId);

            return $esito['reservation_id'] > 0
                && $livelli['quantity'] === 10.0
                && $livelli['reserved'] === 3.0
                && $livelli['available'] === 7.0;
        });

        check('la prenotazione nasce con la scadenza delle impostazioni', function () use ($sede) {
            $productId = articoloConGiacenza(5, 'TST-ALL-2');

            $esito = Allocation::reserve([
                'product_id' => $productId,
                'quantity' => 1,
                'location_id' => $sede,
                'order_id' => ordineDiProva(),
            ]);

            // Trenta minuti è il predefinito; basta che sia nel futuro.
            return $esito['expires_at'] !== ''
                && $esito['expires_at'] > date('Y-m-d H:i:s');
        });

        check('non si prenota più di quello che c\'è', function () use ($sede) {
            $productId = articoloConGiacenza(2, 'TST-ALL-3');

            try {
                Allocation::reserve([
                    'product_id' => $productId,
                    'quantity' => 3,
                    'location_id' => $sede,
                    'order_id' => ordineDiProva(),
                ]);
            } catch (UserError $errore) {
                return $errore->getMessage() !== '';
            }

            return false;
        });

        check('due prenotazioni di fila non superano il disponibile', function () use ($sede) {
            $productId = articoloConGiacenza(2, 'TST-ALL-4');
            $ordine = ordineDiProva();

            Allocation::reserve([
                'product_id' => $productId,
                'quantity' => 2,
                'location_id' => $sede,
                'order_id' => $ordine,
            ]);

            try {
                Allocation::reserve([
                    'product_id' => $productId,
                    'quantity' => 1,
                    'location_id' => $sede,
                    'order_id' => ordineDiProva(),
                ]);
            } catch (UserError) {
                return Levels::of($productId)['available'] === 0.0;
            }

            return false;
        });

        check('una prenotazione di zero pezzi non si scrive', function () use ($sede) {
            $productId = articoloConGiacenza(5, 'TST-ALL-5');

            try {
                Allocation::reserve([
                    'product_id' => $productId,
                    'quantity' => 0,
                    'location_id' => $sede,
                    'order_id' => ordineDiProva(),
                ]);
            } catch (UserError) {
                return Levels::of($productId)['reserved'] === 0.0;
            }

            return false;
        });

        check('una prenotazione negativa non si scrive', function () use ($sede) {
            $productId = articoloConGiacenza(5, 'TST-ALL-6');

            try {
                Allocation::reserve([
                    'product_id' => $productId,
                    'quantity' => -2,
                    'location_id' => $sede,
                    'order_id' => ordineDiProva(),
                ]);
            } catch (UserError) {
                return Levels::of($productId)['reserved'] === 0.0;
            }

            return false;
        });

        check('il rilascio restituisce il disponibile', function () use ($sede) {
            $productId = articoloConGiacenza(4, 'TST-ALL-7');
            $ordine = ordineDiProva();

            Allocation::reserve([
                'product_id' => $productId,
                'quantity' => 4,
                'location_id' => $sede,
                'order_id' => $ordine,
            ]);

            $chiuse = Allocation::release(['order_id' => $ordine]);

            return $chiuse === 1 && Levels::of($productId)['available'] === 4.0;
        });

        check('il rilascio di un ordine non tocca le prenotazioni di un altro', function () use ($sede) {
            $productId = articoloConGiacenza(6, 'TST-ALL-8');
            $mio = ordineDiProva();
            $altrui = ordineDiProva();

            Allocation::reserve(['product_id' => $productId, 'quantity' => 2, 'location_id' => $sede, 'order_id' => $mio]);
            Allocation::reserve(['product_id' => $productId, 'quantity' => 3, 'location_id' => $sede, 'order_id' => $altrui]);
            Allocation::release(['order_id' => $mio]);

            return Levels::of($productId)['reserved'] === 3.0;
        });

        check('il rilascio si può restringere a una riga dell\'ordine', function () use ($sede) {
            $productId = articoloConGiacenza(6, 'TST-ALL-9');
            $ordine = ordineDiProva();

            Allocation::reserve(['product_id' => $productId, 'quantity' => 2, 'location_id' => $sede, 'order_id' => $ordine, 'order_item_id' => 11]);
            Allocation::reserve(['product_id' => $productId, 'quantity' => 1, 'location_id' => $sede, 'order_id' => $ordine, 'order_item_id' => 22]);

            $chiuse = Allocation::release(['order_id' => $ordine, 'order_item_id' => 11]);

            return $chiuse === 1 && Levels::of($productId)['reserved'] === 1.0;
        });

        check('rilasciare due volte non chiude niente la seconda', function () use ($sede) {
            $productId = articoloConGiacenza(3, 'TST-ALL-10');
            $ordine = ordineDiProva();

            Allocation::reserve(['product_id' => $productId, 'quantity' => 3, 'location_id' => $sede, 'order_id' => $ordine]);
            Allocation::release(['order_id' => $ordine]);

            return Allocation::release(['order_id' => $ordine]) === 0;
        });

        check('una prenotazione scaduta non impegna più niente', function () use ($sede) {
            $productId = articoloConGiacenza(3, 'TST-ALL-11');

            Allocation::reserve([
                'product_id' => $productId,
                'quantity' => 3,
                'location_id' => $sede,
                'order_id' => ordineDiProva(),
                'expires_at' => date('Y-m-d H:i:s', time() - 60),
            ]);

            return Levels::of($productId)['available'] === 3.0;
        });

        check('un articolo che non esiste non si prenota', function () use ($sede) {
            try {
                Allocation::reserve(['product_id' => 0, 'quantity' => 1, 'location_id' => $sede, 'order_id' => 0]);
            } catch (UserError) {
                return true;
            }

            return false;
        });

        summary();

        throw new Annulla('Fine della prova: niente resta scritto.');
    });
} catch (Annulla) {
    // Tutto annullato: era una prova.
}
```

- [ ] **Step 2: Guarda il test fallire**

Run: `php tests/integrazione/AllocationTest.php`
Expected: `PHP Fatal error: Uncaught Error: Class "Wonder\Plugin\Gestionale\Support\Stock\Allocation" not found`

- [ ] **Step 3: Estrai la regola del backorder e apri il canale dello scarico forzato**

In `src/Support/Stock/Stock.php`, sostituisci il blocco del backorder dentro
`apply()`:

```php
            // Sotto zero si va solo se la funzionalità lo permette **e**
            // l'opzione lo vuole: la funzionalità dice che si può, l'articolo
            // dice se lo vende scoperto. Altrimenti la giacenza è un muro, ma
            // solo per chi toglie: la merce che arriva entra sempre, anche se
            // non basta a colmare un buco rimasto da quando si vendeva scoperto.
            $backorder = Gestionale::feature('backorders')
                && ($product['allow_backorder'] ?? 'false') === 'true';

            if ($after < 0 && $quantity < 0 && !$backorder) {
```

con:

```php
            // Sotto zero si va in due casi. Il primo è il backorder: la
            // funzionalità dice che si può, l'articolo dice se lo vende
            // scoperto. Il secondo è `allow_negative`, e lo passa solo
            // `Allocation::commit()`: la merce di quell'ordine era già stata
            // messa da parte, o è già stata pagata, e non si rifiuta una
            // vendita chiusa per un numero che non torna. Chi toglie trova
            // comunque un muro; la merce che arriva entra sempre, anche se non
            // basta a colmare un buco rimasto da quando si vendeva scoperto.
            $negative = ($movement['allow_negative'] ?? false) === true
                || self::allowsBackorder($product);

            if ($after < 0 && $quantity < 0 && !$negative) {
```

e aggiungi il metodo pubblico, sotto `apply()`:

```php
    /**
     * L'opzione si vende scoperta?
     *
     * La funzionalità dice che si può, l'articolo dice se lo fa. Sta qui, e
     * non dentro chi chiama, perché la regola è una sola: `Allocation` la
     * legge prima di prenotare, `apply()` prima di scendere sotto zero.
     *
     * @param array<string, mixed> $product riga di `gst_products`
     */
    public static function allowsBackorder(array $product): bool
    {
        return Gestionale::feature('backorders')
            && ($product['allow_backorder'] ?? 'false') === 'true';
    }
```

- [ ] **Step 4: Scrivi `Allocation` con i due primi metodi**

Crea `src/Support/Stock/Allocation.php`:

```php
<?php

namespace Wonder\Plugin\Gestionale\Support\Stock;

use RuntimeException;
use Throwable;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Stock\Stock as StockRow;
use Wonder\Plugin\Gestionale\Models\Stock\StockReservation;
use Wonder\Plugin\Gestionale\Models\System\Setting;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Sql\Transaction;

/**
 * **L'unico punto che tocca il magazzino per una vendita.**
 *
 * Cinque metodi, in ordine di vita di un ordine: `reserve()` mette la merce da
 * parte, `release()` la lascia andare, `commit()` la scarica davvero,
 * `restore()` la fa rientrare da un ordine annullato, `returnGoods()` da un
 * reso. Nessuno di loro scrive un movimento di suo: lo chiede a `Stock`, che
 * resta l'unica porta di scrittura del magazzino. L'unica tabella che
 * `Allocation` scrive è `gst_stock_reservations`.
 *
 * Tutti e cinque prendono sede, lotto e fornitore. Oggi la sede è quella
 * principale e lotto e fornitore sono zero, ma la firma è già quella giusta:
 * G3 cambierà il dentro dei metodi senza riaprire gli ordini.
 *
 * Il lock è l'antioverselling: le righe di `gst_stock` si leggono con
 * `FOR UPDATE` **prima** delle prenotazioni, così due checkout sull'ultimo
 * pezzo si mettono in fila e il secondo legge un disponibile che tiene già
 * conto del primo.
 */
final class Allocation
{
    /**
     * Mette da parte la merce di una riga d'ordine.
     *
     * @param array<string, mixed> $line `product_id`, `quantity`, `location_id`,
     *     `batch_id`, `supplier_id`, `order_id`, `order_item_id`,
     *     `expires_at` (facoltativa: vince sulle impostazioni)
     * @return array{reservation_id: int, quantity: float, available: float, expires_at: string}
     */
    public static function reserve(array $line): array
    {
        $context = self::context($line);

        if ($context['quantity'] <= 0) {
            throw UserError::make('order.zero_quantity');
        }

        return Transaction::run(static function () use ($context, $line): array {
            $onHand = self::lockedQuantity($context);
            $reservations = self::liveReservations($context['product_id'], $context['location_id']);
            $available = Availability::of($onHand, $reservations);

            if ($available < $context['quantity'] && !Stock::allowsBackorder($context['product'])) {
                throw UserError::make('stock.insufficient', ['product' => self::label($context['product'])]);
            }

            $expiresAt = array_key_exists('expires_at', $line)
                ? trim((string) $line['expires_at'])
                : self::defaultExpiry();

            $created = StockReservation::create([
                'product_id' => $context['product_id'],
                'location_id' => $context['location_id'],
                'quantity' => self::number($context['quantity']),
                'order_id' => (int) ($line['order_id'] ?? 0),
                'order_item_id' => (int) ($line['order_item_id'] ?? 0),
                'expires_at' => $expiresAt,
            ]);

            if (($created->success ?? false) !== true) {
                // Non è un errore da far leggere: è un guasto, e la transazione
                // riporta indietro tutto.
                throw new RuntimeException('Prenotazione di magazzino non scritta.');
            }

            return [
                'reservation_id' => (int) ($created->insert_id ?? 0),
                'quantity' => $context['quantity'],
                'available' => round($available - $context['quantity'], 3),
                'expires_at' => $expiresAt,
            ];
        });
    }

    /**
     * Lascia andare la merce messa da parte: tutta quella di un ordine, o solo
     * quella di una sua riga.
     *
     * Una prenotazione già chiusa non si richiude: torna quante ne ha chiuse
     * davvero, che è zero quando il carrello era già scaduto.
     *
     * @param array<string, mixed> $line `order_id` (obbligatoria),
     *     `order_item_id`, `product_id`, `location_id`, `batch_id`, `supplier_id`
     */
    public static function release(array $line): int
    {
        $orderId = (int) ($line['order_id'] ?? 0);

        if ($orderId <= 0) {
            return 0;
        }

        $itemId = (int) ($line['order_item_id'] ?? 0);
        $productId = (int) ($line['product_id'] ?? 0);
        $now = date('Y-m-d H:i:s');
        $closed = 0;

        foreach (self::reservationsOfOrder($orderId) as $reservation) {
            if ($itemId > 0 && (int) ($reservation['order_item_id'] ?? 0) !== $itemId) {
                continue;
            }

            if ($productId > 0 && (int) ($reservation['product_id'] ?? 0) !== $productId) {
                continue;
            }

            if (!Availability::isActive($reservation, $now)) {
                continue;
            }

            StockReservation::update(['released_at' => $now], (int) $reservation['id']);
            ++$closed;
        }

        return $closed;
    }

    /**
     * Prodotto, quantità e chiavi di magazzino, controllati una volta sola.
     *
     * @param array<string, mixed> $line
     * @return array{product: array<string, mixed>, product_id: int, quantity: float, location_id: int, batch_id: int, supplier_id: int}
     */
    private static function context(array $line): array
    {
        $productId = (int) ($line['product_id'] ?? 0);
        $product = $productId > 0 ? Product::findById($productId) : null;

        if (!is_array($product) || $product === []) {
            throw UserError::make('stock.product_missing');
        }

        $locationId = (int) ($line['location_id'] ?? 0) ?: Locations::mainId();

        if ($locationId <= 0) {
            throw UserError::make('stock.no_location');
        }

        return [
            'product' => $product,
            'product_id' => $productId,
            'quantity' => round((float) ($line['quantity'] ?? 0), 3),
            'location_id' => $locationId,
            'batch_id' => (int) ($line['batch_id'] ?? 0),
            'supplier_id' => (int) ($line['supplier_id'] ?? 0),
        ];
    }

    /**
     * La giacenza della sede, letta con il lock.
     *
     * Blocca tutte le righe del prodotto in quella sede — lotti e fornitori
     * diversi sono righe diverse — perché è la sede l'unità che si vende. Con
     * lotto o fornitore nella richiesta si restringe a quella riga: è G3 che
     * comincerà a passarli.
     *
     * @param array{product_id: int, location_id: int, batch_id: int, supplier_id: int} $context
     */
    private static function lockedQuantity(array $context): float
    {
        $where = [
            'product_id' => $context['product_id'],
            'location_id' => $context['location_id'],
            'deleted' => 'false',
        ];

        if ($context['batch_id'] > 0) {
            $where['batch_id'] = $context['batch_id'];
        }

        if ($context['supplier_id'] > 0) {
            $where['supplier_id'] = $context['supplier_id'];
        }

        $quantity = 0.0;

        foreach (self::rows(StockRow::findForUpdate($where)) as $row) {
            $quantity += (float) ($row['quantity'] ?? 0);
        }

        return round($quantity, 3);
    }

    /**
     * Le prenotazioni vive del prodotto in quella sede.
     *
     * Non si restringono per lotto: la tabella non ha la colonna, e contarle
     * tutte è il verso prudente dell'errore.
     *
     * @return list<array<string, mixed>>
     */
    private static function liveReservations(int $productId, int $locationId): array
    {
        return self::rows(StockReservation::find(
            "product_id = {$productId} AND location_id = {$locationId} AND deleted = 'false'"
        ));
    }

    /** @return list<array<string, mixed>> */
    private static function reservationsOfOrder(int $orderId): array
    {
        return self::rows(StockReservation::find(
            "order_id = {$orderId} AND deleted = 'false'"
        ));
    }

    /**
     * Il ritorno di `find()` in una forma sola: una riga sola arriva senza
     * l'indice, e senza database non arriva niente.
     *
     * @return list<array<string, mixed>>
     */
    private static function rows(mixed $rows): array
    {
        if (!is_array($rows) || $rows === []) {
            return [];
        }

        return isset($rows['id']) ? [$rows] : array_values(array_filter($rows, 'is_array'));
    }

    /**
     * La scadenza dalle impostazioni; zero minuti vuol dire che non scade.
     */
    private static function defaultExpiry(): string
    {
        try {
            $minutes = (int) (Setting::current()['order_reservation_minutes'] ?? 30);
        } catch (Throwable) {
            $minutes = 30;
        }

        return $minutes > 0 ? date('Y-m-d H:i:s', time() + $minutes * 60) : '';
    }

    /** Il nome che legge una persona, o lo SKU se il nome manca. */
    private static function label(array $product): string
    {
        $name = trim((string) ($product['name'] ?? ''));

        return $name !== '' ? $name : (string) ($product['sku'] ?? '');
    }

    /** La forma che il database accetta sempre: punto, non virgola. */
    private static function number(float $value): string
    {
        return number_format($value, 3, '.', '');
    }
}
```

- [ ] **Step 5: La frase dell'errore**

`stock.zero_quantity` esiste già ma parla di rettifiche («Questa rettifica non
cambia niente…»): qui serve una frase sua. In `lang/it/gestionale.json`, dentro
`gestionale.errors`, aggiungi il gruppo `order` dopo il gruppo `stock`:

```json
        "order": {
            "zero_quantity": "Una riga d'ordine deve avere una quantità maggiore di zero."
        },
```

- [ ] **Step 6: Guarda il test passare**

Run: `php tests/integrazione/AllocationTest.php`
Expected: `13/13` verdi.

- [ ] **Step 7: Tutta la suite**

Run: `php tests/run.php`
Expected: verde, `StockTest` e `ErrorKeysTest` compresi (il backorder è
cambiato di posto, non di regola).

- [ ] **Step 8: Commit**

```bash
git add src/Support/Stock/Allocation.php src/Support/Stock/Stock.php \
    lang/it/gestionale.json tests/integrazione/AllocationTest.php
git commit -m "Mette da parte e lascia andare la merce di un ordine"
```

---
### Task 3: `Allocation::commit()` — lo scarico vero, e le tre regole della prenotazione persa

Con una prenotazione valida `commit()` scarica **sempre**, anche se la merce
nel frattempo è sparita: quel pezzo era già stato messo da parte per questo
cliente. Senza prenotazione — scaduta, o mai fatta perché l'ordine arriva dal
banco — decide il resto: `backorders` accesa manda sotto zero e basta; spenta,
un ordine già pagato va comunque sotto zero e il commerciante viene avvisato;
spenta e non pagato, l'ordine resta in attesa e chi ha comprato legge perché.

**Files:**
- Modify: `src/Support/Stock/Allocation.php`
- Modify: `lang/it/gestionale.json`
- Test: `tests/integrazione/AllocationTest.php` (si aggiunge in fondo, prima
  di `summary()`)

**Interfaces:**
- Consumes: `Allocation::release()`, `Allocation::context()` e
  `Allocation::lockedQuantity()` (privati, Task 2), `Stock::apply()` con
  `allow_negative`, `Stock::allowsBackorder()`,
  `StatusLogger::record(string $logClass, int $entityId, string $field, string $from, string $to, string $source = 'user', ?int $userId = null, string $message = '', ?array $response = null): bool`.
- Produces:
  - `Allocation::commit(array $line): array{movement_id: int, before: float, after: float, reserved: float, oversold: bool, merchant_alert: bool}`
  - Chiavi nuove di `$line`: `payment_ok` (bool), `source`, `user_id`.
  - Chiave d'errore `gestionale.errors.order.reservation_lost`.

- [ ] **Step 1: Scrivi i test rossi**

In `tests/integrazione/AllocationTest.php`, aggiungi questi `check` subito
prima di `summary();`:

```php
        check('lo scarico consuma la prenotazione e abbassa la giacenza', function () use ($sede) {
            $productId = articoloConGiacenza(10, 'TST-CMT-1');
            $ordine = ordineDiProva();

            Allocation::reserve(['product_id' => $productId, 'quantity' => 4, 'location_id' => $sede, 'order_id' => $ordine]);
            $esito = Allocation::commit([
                'product_id' => $productId,
                'quantity' => 4,
                'location_id' => $sede,
                'order_id' => $ordine,
            ]);

            $livelli = Levels::of($productId);

            return $esito['movement_id'] > 0
                && $esito['after'] === 6.0
                && $esito['oversold'] === false
                && $livelli['quantity'] === 6.0
                && $livelli['reserved'] === 0.0;
        });

        check('lo scarico scrive un movimento di vendita legato all\'ordine', function () use ($sede) {
            $productId = articoloConGiacenza(3, 'TST-CMT-2');
            $ordine = ordineDiProva();

            Allocation::reserve(['product_id' => $productId, 'quantity' => 1, 'location_id' => $sede, 'order_id' => $ordine]);
            $esito = Allocation::commit(['product_id' => $productId, 'quantity' => 1, 'location_id' => $sede, 'order_id' => $ordine]);

            $movimento = StockMovement::findById($esito['movement_id']);

            return is_array($movimento)
                && $movimento['type'] === 'sale'
                && $movimento['reference_type'] === 'order'
                && (int) $movimento['reference_id'] === $ordine
                && (float) $movimento['quantity'] === -1.0;
        });

        check('con la prenotazione in mano si scarica anche a magazzino vuoto', function () use ($sede) {
            $productId = articoloConGiacenza(1, 'TST-CMT-3');
            $ordine = ordineDiProva();

            Allocation::reserve(['product_id' => $productId, 'quantity' => 1, 'location_id' => $sede, 'order_id' => $ordine]);

            // Intanto qualcuno rompe l'ultimo pezzo e lo toglie dal magazzino.
            Stock::apply(['product_id' => $productId, 'quantity' => -1, 'reason' => 'damaged']);

            $esito = Allocation::commit(['product_id' => $productId, 'quantity' => 1, 'location_id' => $sede, 'order_id' => $ordine]);

            return $esito['after'] === -1.0 && $esito['oversold'] === true;
        });

        check('senza prenotazione ma con merce lo scarico è normale', function () use ($sede) {
            $productId = articoloConGiacenza(5, 'TST-CMT-4');

            $esito = Allocation::commit([
                'product_id' => $productId,
                'quantity' => 2,
                'location_id' => $sede,
                'order_id' => ordineDiProva(),
            ]);

            return $esito['after'] === 3.0
                && $esito['reserved'] === 0.0
                && $esito['merchant_alert'] === false;
        });

        check('senza prenotazione, senza merce e senza pagamento l\'ordine resta in attesa', function () use ($sede) {
            $productId = articoloConGiacenza(1, 'TST-CMT-5');

            try {
                Allocation::commit([
                    'product_id' => $productId,
                    'quantity' => 2,
                    'location_id' => $sede,
                    'order_id' => ordineDiProva(),
                    'payment_ok' => false,
                ]);
            } catch (UserError $errore) {
                // Niente scarico: la giacenza è rimasta quella di prima.
                return Levels::of($productId)['quantity'] === 1.0
                    && $errore->getMessage() !== '';
            }

            return false;
        });

        check('un ordine già pagato si scarica lo stesso e avvisa il commerciante', function () use ($sede) {
            $productId = articoloConGiacenza(1, 'TST-CMT-6');
            $ordine = ordineDiProva();

            $esito = Allocation::commit([
                'product_id' => $productId,
                'quantity' => 3,
                'location_id' => $sede,
                'order_id' => $ordine,
                'payment_ok' => true,
            ]);

            $log = OrderStatusLog::find("order_id = {$ordine} AND field = 'stock'");

            return $esito['after'] === -2.0
                && $esito['oversold'] === true
                && $esito['merchant_alert'] === true
                && is_array($log) && $log !== [];
        });

        check('lo scarico di zero pezzi non si fa', function () use ($sede) {
            $productId = articoloConGiacenza(5, 'TST-CMT-7');

            try {
                Allocation::commit(['product_id' => $productId, 'quantity' => 0, 'location_id' => $sede, 'order_id' => ordineDiProva()]);
            } catch (UserError) {
                return Levels::of($productId)['quantity'] === 5.0;
            }

            return false;
        });

        check('la prenotazione consumata non serve una seconda volta', function () use ($sede) {
            $productId = articoloConGiacenza(5, 'TST-CMT-8');
            $ordine = ordineDiProva();

            Allocation::reserve(['product_id' => $productId, 'quantity' => 2, 'location_id' => $sede, 'order_id' => $ordine]);
            Allocation::commit(['product_id' => $productId, 'quantity' => 2, 'location_id' => $sede, 'order_id' => $ordine]);
            $secondo = Allocation::commit(['product_id' => $productId, 'quantity' => 2, 'location_id' => $sede, 'order_id' => $ordine]);

            // Il secondo scarico c'è comunque — la merce c'era — ma senza
            // prenotazione da consumare.
            return $secondo['reserved'] === 0.0 && Levels::of($productId)['quantity'] === 1.0;
        });

        check('con la vendita senza giacenza accesa si scarica sotto zero senza avvisi', function () use ($sede) {
            $productId = articoloConGiacenza(1, 'TST-CMT-9');
            Product::update(['allow_backorder' => 'true'], $productId);
            accendiFunzionalita(['orders', 'backorders']);

            if (!Gestionale::feature('backorders')) {
                return false;
            }

            $esito = Allocation::commit([
                'product_id' => $productId,
                'quantity' => 4,
                'location_id' => $sede,
                'order_id' => ordineDiProva(),
            ]);

            // La cache delle funzionalità resta accesa per questo processo:
            // spegnila subito, così i prossimi check ripartono puliti.
            Gestionale::reset();

            return $esito['after'] === -3.0
                && $esito['oversold'] === true
                && $esito['merchant_alert'] === false;
        });
```

Aggiungi in cima al file, agli `use`:

```php
use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Sales\OrderStatusLog;
use Wonder\Plugin\Gestionale\Models\Stock\StockMovement;
use Wonder\Plugin\Gestionale\Models\System\Feature;
```

e questa funzione di supporto, accanto alle altre due:

```php
/**
 * Accende delle funzionalità per la prova.
 *
 * Lo stato sta nel database e `Gestionale` lo tiene in cache: dopo la
 * scrittura la cache va buttata, altrimenti si continua a leggere quella di
 * prima. Tutto dentro la transazione, quindi alla fine non resta niente.
 *
 * @param list<string> $chiavi
 */
function accendiFunzionalita(array $chiavi): void
{
    foreach ($chiavi as $chiave) {
        $riga = Feature::find(['feature_key' => $chiave, 'deleted' => 'false'], 1);

        if (is_array($riga) && isset($riga['id'])) {
            Feature::update(['enabled' => 'true'], (int) $riga['id']);
        } else {
            Feature::create(['feature_key' => $chiave, 'enabled' => 'true']);
        }
    }

    Gestionale::reset();
}
```

- [ ] **Step 2: Guarda i test fallire**

Run: `php tests/integrazione/AllocationTest.php`
Expected: i 13 del Task 2 verdi, i 9 nuovi rossi con
`Error: Call to undefined method ...Allocation::commit()`.

- [ ] **Step 3: La frase dell'errore**

In `lang/it/gestionale.json`, dentro il gruppo `order` nato nel Task 2,
aggiungi la seconda chiave:

```json
        "order": {
            "zero_quantity": "Una riga d'ordine deve avere una quantità maggiore di zero.",
            "reservation_lost": "I pezzi che avevi messo da parte non ci sono più: l'ordine resta in attesa e ti scriviamo appena rientrano."
        },
```

- [ ] **Step 4: Scrivi `commit()`**

In `src/Support/Stock/Allocation.php`, aggiungi dopo `release()`:

```php
    /**
     * Scarica davvero la merce di una riga d'ordine.
     *
     * Con una prenotazione valida scarica **sempre**, anche se il magazzino
     * nel frattempo si è svuotato: quel pezzo era di questo cliente. Senza
     * prenotazione, e senza merce, decide il resto — `backorders` accesa manda
     * sotto zero; spenta, un ordine già pagato va sotto zero e il commerciante
     * viene avvisato; spenta e non pagato, l'ordine resta in attesa.
     *
     * @param array<string, mixed> $line come `reserve()`, più `payment_ok`
     *     (bool: il pagamento è riuscito), `source` e `user_id`
     * @return array{movement_id: int, before: float, after: float, reserved: float, oversold: bool, merchant_alert: bool}
     */
    public static function commit(array $line): array
    {
        $context = self::context($line);

        if ($context['quantity'] <= 0) {
            throw UserError::make('order.zero_quantity');
        }

        return Transaction::run(static function () use ($context, $line): array {
            $onHand = self::lockedQuantity($context);
            $reserved = self::reservedFor($line, $context);
            $hasReservation = $reserved + 0.0005 >= $context['quantity'];
            $backorder = Stock::allowsBackorder($context['product']);
            $paid = ($line['payment_ok'] ?? false) === true;

            if (!$hasReservation && $onHand < $context['quantity'] && !$backorder && !$paid) {
                // La merce non c'è, nessuno l'aveva messa da parte e nessuno ha
                // ancora pagato: l'ordine aspetta invece di scavare un buco.
                throw UserError::make('order.reservation_lost');
            }

            if ($hasReservation) {
                self::release($line);
            }

            $movement = Stock::apply([
                'product_id' => $context['product_id'],
                'location_id' => $context['location_id'],
                'batch_id' => $context['batch_id'],
                'supplier_id' => $context['supplier_id'],
                'quantity' => -$context['quantity'],
                'type' => 'sale',
                'reference_type' => 'order',
                'reference_id' => (int) ($line['order_id'] ?? 0),
                'source' => (string) ($line['source'] ?? 'backend'),
                'user_id' => (int) ($line['user_id'] ?? 0),
                'allow_negative' => $hasReservation || $backorder || $paid,
            ]);

            $oversold = $movement['after'] < 0;
            $alert = $oversold && !$backorder;

            if ($alert) {
                self::warnMerchant((int) ($line['order_id'] ?? 0), $context, $movement['after'], $line);
            }

            return [
                'movement_id' => $movement['movement_id'],
                'before' => $movement['before'],
                'after' => $movement['after'],
                'reserved' => $reserved,
                'oversold' => $oversold,
                'merchant_alert' => $alert,
            ];
        });
    }

    /**
     * Quanta merce di questa riga era messa da parte e vale ancora.
     *
     * Con `order_item_id` conta solo quella riga; senza, tutte le prenotazioni
     * di quel prodotto in quell'ordine — un ordine dal banco non ha righe
     * numerate.
     *
     * @param array<string, mixed> $line
     * @param array{product_id: int, location_id: int} $context
     */
    private static function reservedFor(array $line, array $context): float
    {
        $orderId = (int) ($line['order_id'] ?? 0);

        if ($orderId <= 0) {
            return 0.0;
        }

        $itemId = (int) ($line['order_item_id'] ?? 0);
        $now = date('Y-m-d H:i:s');
        $total = 0.0;

        foreach (self::reservationsOfOrder($orderId) as $reservation) {
            if ((int) ($reservation['product_id'] ?? 0) !== $context['product_id']) {
                continue;
            }

            if ($itemId > 0 && (int) ($reservation['order_item_id'] ?? 0) !== $itemId) {
                continue;
            }

            if (Availability::isActive($reservation, $now)) {
                $total += (float) ($reservation['quantity'] ?? 0);
            }
        }

        return round($total, 3);
    }

    /**
     * Scrive nel log dell'ordine che si è venduto qualcosa che non c'era.
     *
     * Qui resta scritto; l'email al commerciante la manda il piano 3, che
     * porta tutte le email di G4. Un log che non si scrive non ferma una
     * vendita già incassata.
     *
     * @param array{product: array<string, mixed>, location_id: int} $context
     * @param array<string, mixed> $line
     */
    private static function warnMerchant(int $orderId, array $context, float $after, array $line): void
    {
        if ($orderId <= 0) {
            return;
        }

        StatusLogger::record(
            OrderStatusLog::class,
            $orderId,
            'stock',
            'available',
            'oversold',
            (string) ($line['source'] ?? 'system') === 'backend' ? 'user' : 'system',
            (int) ($line['user_id'] ?? 0) ?: null,
            sprintf(
                'Venduto senza giacenza: %s resta a %s pezzi nella sede %d.',
                self::label($context['product']),
                self::number($after),
                $context['location_id']
            )
        );
    }
```

e agli `use` del file:

```php
use Wonder\Plugin\Gestionale\Models\Sales\OrderStatusLog;
use Wonder\Plugin\Gestionale\Support\Status\StatusLogger;
```

- [ ] **Step 5: Guarda i test passare**

Run: `php tests/integrazione/AllocationTest.php`
Expected: `22/22` verdi.

- [ ] **Step 6: Le chiavi d'errore e tutta la suite**

Run: `php tests/run.php`
Expected: verde, `ErrorKeysTest` compreso — trova `order.reservation_lost`
in `src/` e la frase nel file di lingua.

- [ ] **Step 7: Commit**

```bash
git add src/Support/Stock/Allocation.php lang/it/gestionale.json tests/integrazione/AllocationTest.php
git commit -m "Scarica il magazzino quando l'ordine si conferma"
```

---
### Task 4: `Allocation::restore()` e `returnGoods()` — la merce che rientra

Due strade diverse per la stessa direzione. `restore()` è l'annullamento di un
ordine confermato: la merce non è mai partita, torna dov'era con un movimento
`sale_cancel`. `returnGoods()` è il reso: la merce è tornata indietro davvero,
movimento `return` — ma solo se è rivendibile. Una riga con `restock` a `false`
— rotta, aperta, usata — si registra e basta: nel magazzino non rientra.

**Files:**
- Modify: `src/Support/Stock/Allocation.php`
- Test: `tests/integrazione/AllocationTest.php`

**Interfaces:**
- Consumes: `Allocation::context()`, `Stock::apply()`.
- Produces:
  - `Allocation::restore(array $line): array{movement_id: int, before: float, after: float}`
  - `Allocation::returnGoods(array $line): array{movement_id: int, before: float, after: float, restocked: bool}`
  - Chiavi nuove di `$line`: `sales_return_id` (per `returnGoods`), `restock`
    (bool, predefinita `true`).

- [ ] **Step 1: Scrivi i test rossi**

In `tests/integrazione/AllocationTest.php`, prima di `summary();`:

```php
        check('l\'annullamento fa rientrare la merce con un movimento di vendita annullata', function () use ($sede) {
            $productId = articoloConGiacenza(5, 'TST-RST-1');
            $ordine = ordineDiProva();

            Allocation::commit(['product_id' => $productId, 'quantity' => 2, 'location_id' => $sede, 'order_id' => $ordine]);
            $esito = Allocation::restore(['product_id' => $productId, 'quantity' => 2, 'location_id' => $sede, 'order_id' => $ordine]);

            $movimento = StockMovement::findById($esito['movement_id']);

            return $esito['after'] === 5.0
                && is_array($movimento)
                && $movimento['type'] === 'sale_cancel'
                && $movimento['reference_type'] === 'order'
                && (int) $movimento['reference_id'] === $ordine;
        });

        check('l\'annullamento risana anche una giacenza sotto zero', function () use ($sede) {
            $productId = articoloConGiacenza(1, 'TST-RST-2');
            $ordine = ordineDiProva();

            Allocation::commit(['product_id' => $productId, 'quantity' => 3, 'location_id' => $sede, 'order_id' => $ordine, 'payment_ok' => true]);
            $esito = Allocation::restore(['product_id' => $productId, 'quantity' => 3, 'location_id' => $sede, 'order_id' => $ordine]);

            return $esito['before'] === -2.0 && $esito['after'] === 1.0;
        });

        check('l\'annullamento di zero pezzi non si fa', function () use ($sede) {
            $productId = articoloConGiacenza(4, 'TST-RST-3');

            try {
                Allocation::restore(['product_id' => $productId, 'quantity' => 0, 'location_id' => $sede, 'order_id' => ordineDiProva()]);
            } catch (UserError) {
                return Levels::of($productId)['quantity'] === 4.0;
            }

            return false;
        });

        check('il reso rimette la merce in magazzino', function () use ($sede) {
            $productId = articoloConGiacenza(5, 'TST-RET-1');
            $ordine = ordineDiProva();
            $reso = resoDiProva($ordine, $sede);

            Allocation::commit(['product_id' => $productId, 'quantity' => 2, 'location_id' => $sede, 'order_id' => $ordine]);
            $esito = Allocation::returnGoods([
                'product_id' => $productId,
                'quantity' => 2,
                'location_id' => $sede,
                'order_id' => $ordine,
                'sales_return_id' => $reso,
                'restock' => true,
            ]);

            $movimento = StockMovement::findById($esito['movement_id']);

            return $esito['restocked'] === true
                && $esito['after'] === 5.0
                && is_array($movimento)
                && $movimento['type'] === 'return'
                && $movimento['reference_type'] === 'sales_return'
                && (int) $movimento['reference_id'] === $reso;
        });

        check('la merce rotta non rientra in magazzino', function () use ($sede) {
            $productId = articoloConGiacenza(5, 'TST-RET-2');
            $ordine = ordineDiProva();
            $reso = resoDiProva($ordine, $sede);

            Allocation::commit(['product_id' => $productId, 'quantity' => 2, 'location_id' => $sede, 'order_id' => $ordine]);
            $esito = Allocation::returnGoods([
                'product_id' => $productId,
                'quantity' => 2,
                'location_id' => $sede,
                'order_id' => $ordine,
                'sales_return_id' => $reso,
                'restock' => false,
            ]);

            return $esito['restocked'] === false
                && $esito['movement_id'] === 0
                && Levels::of($productId)['quantity'] === 3.0;
        });

        check('il reso senza ricarico non scrive nessun movimento', function () use ($sede) {
            $productId = articoloConGiacenza(2, 'TST-RET-3');
            $ordine = ordineDiProva();
            $reso = resoDiProva($ordine, $sede);

            Allocation::returnGoods([
                'product_id' => $productId,
                'quantity' => 1,
                'location_id' => $sede,
                'order_id' => $ordine,
                'sales_return_id' => $reso,
                'restock' => false,
            ]);

            $movimenti = StockMovement::find("product_id = {$productId} AND type = 'return'");

            return !is_array($movimenti) || $movimenti === [];
        });

        check('il reso di zero pezzi non si fa', function () use ($sede) {
            $productId = articoloConGiacenza(4, 'TST-RET-4');
            $ordine = ordineDiProva();

            try {
                Allocation::returnGoods([
                    'product_id' => $productId,
                    'quantity' => 0,
                    'location_id' => $sede,
                    'order_id' => $ordine,
                    'sales_return_id' => resoDiProva($ordine, $sede),
                ]);
            } catch (UserError) {
                return Levels::of($productId)['quantity'] === 4.0;
            }

            return false;
        });
```

e la funzione di supporto, accanto alle altre:

```php
/** Un reso di prova sull'ordine dato: serve un id vero per i movimenti. */
function resoDiProva(int $ordine, int $sede): int
{
    $reso = SalesReturn::create([
        'number' => 'RES/'.date('Y').'/'.substr((string) microtime(true), -6),
        'order_id' => $ordine,
        'location_id' => $sede,
        'status' => 'received',
        'received_at' => date('Y-m-d H:i:s'),
    ]);

    return (int) ($reso->insert_id ?? 0);
}
```

più l'`use` di `Wonder\Plugin\Gestionale\Models\Sales\SalesReturn;`.

- [ ] **Step 2: Guarda i test fallire**

Run: `php tests/integrazione/AllocationTest.php`
Expected: i 22 di prima verdi, i 7 nuovi rossi con
`Error: Call to undefined method ...Allocation::restore()`.

- [ ] **Step 3: Scrivi i due metodi**

In `src/Support/Stock/Allocation.php`, dopo `commit()`:

```php
    /**
     * Fa rientrare la merce di un ordine annullato.
     *
     * L'ordine era confermato e scaricato, ma non è mai partito: il movimento
     * è `sale_cancel`, e la giacenza torna dov'era. Se era andata sotto zero,
     * questo la risana.
     *
     * @param array<string, mixed> $line come `commit()`
     * @return array{movement_id: int, before: float, after: float}
     */
    public static function restore(array $line): array
    {
        return self::giveBack($line, 'sale_cancel', 'order', (int) ($line['order_id'] ?? 0));
    }

    /**
     * Fa rientrare la merce di un reso.
     *
     * Solo se è rivendibile: una riga con `restock` a `false` — rotta, aperta,
     * usata — si registra nel reso e basta, e in magazzino non torna niente.
     * Il movimento porta il numero del reso, non quello dell'ordine: è il reso
     * il documento che lo giustifica.
     *
     * @param array<string, mixed> $line come `commit()`, più `sales_return_id`
     *     e `restock` (bool, predefinita `true`)
     * @return array{movement_id: int, before: float, after: float, restocked: bool}
     */
    public static function returnGoods(array $line): array
    {
        $restock = ($line['restock'] ?? true) !== false;
        $context = self::context($line);

        if ($context['quantity'] <= 0) {
            throw UserError::make('order.zero_quantity');
        }

        if (!$restock) {
            $onHand = self::lockedQuantity($context);

            return ['movement_id' => 0, 'before' => $onHand, 'after' => $onHand, 'restocked' => false];
        }

        return self::giveBack($line, 'return', 'sales_return', (int) ($line['sales_return_id'] ?? 0))
            + ['restocked' => true];
    }

    /**
     * Il rientro vero e proprio, uguale per l'annullamento e per il reso:
     * cambiano solo il tipo del movimento e il documento che lo giustifica.
     *
     * @param array<string, mixed> $line
     * @return array{movement_id: int, before: float, after: float}
     */
    private static function giveBack(array $line, string $type, string $referenceType, int $referenceId): array
    {
        $context = self::context($line);

        if ($context['quantity'] <= 0) {
            throw UserError::make('order.zero_quantity');
        }

        $movement = Stock::apply([
            'product_id' => $context['product_id'],
            'location_id' => $context['location_id'],
            'batch_id' => $context['batch_id'],
            'supplier_id' => $context['supplier_id'],
            'quantity' => $context['quantity'],
            'type' => $type,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'source' => (string) ($line['source'] ?? 'backend'),
            'user_id' => (int) ($line['user_id'] ?? 0),
            'note' => (string) ($line['note'] ?? ''),
        ]);

        return [
            'movement_id' => $movement['movement_id'],
            'before' => $movement['before'],
            'after' => $movement['after'],
        ];
    }
```

- [ ] **Step 4: Guarda i test passare**

Run: `php tests/integrazione/AllocationTest.php`
Expected: `29/29` verdi.

- [ ] **Step 5: Tutta la suite**

Run: `php tests/run.php`
Expected: verde.

- [ ] **Step 6: Commit**

```bash
git add src/Support/Stock/Allocation.php tests/integrazione/AllocationTest.php
git commit -m "Fa rientrare la merce di un annullamento e di un reso"
```

---
### Task 5: `Support\Payments\Ledger` — il registro del denaro

Ogni movimento di denaro è una riga. Il `payment_status` dell'ordine non si
scrive mai a mano: si ricalcola dalla somma delle righe e lo tocca solo questa
classe, con `PaymentStatus::of()` del Task 1 a decidere la parola.

Due difese contro la notifica doppia del gateway, che arriva anche due volte
per lo stesso incasso. La prima: prima di scrivere, `register()` cerca la riga
con quel `provider` e quel `provider_reference` **con `FOR UPDATE`**. La
condizione è sull'indice unico, quindi quando la riga non c'è InnoDB blocca lo
spazio dove andrebbe: la seconda notifica aspetta la prima, poi la trova e non
scrive niente. La seconda difesa è l'indice unico stesso, che regge anche se
domani qualcuno chiama `register()` fuori da una transazione.

Un pagamento senza riferimento — il bonifico registrato a mano, i contanti al
banco — non ha niente da confrontare: due incassi manuali uguali sono due
incassi veri, e vanno tenuti tutti e due. Ma l'indice unico li farebbe
collidere sul riferimento vuoto. Per questo, quando il riferimento manca, si
usa il codice della riga (`pay_…`), che `Code::make()` sa dare **prima**
dell'inserimento: resta unico e resta leggibile in elenco.

**Files:**
- Create: `src/Support/Payments/Ledger.php`
- Create: `tests/integrazione/supporto/compra.php`
- Create: `tests/integrazione/supporto/incassa.php`
- Create: `tests/integrazione/LedgerTest.php`
- Modify: `tests/integrazione/AllocationTest.php`
- Modify: `lang/it/gestionale.json`

**Interfaces:**
- Consumes: `PaymentStatus::of()` (Task 1), `Code::make()`, `StatusLogger::record()`.
- Produces:
  - `Ledger::open(array $data): array{payment_id: int, created: bool, payment_status: string}`
  - `Ledger::register(array $data): array{payment_id: int, created: bool, payment_status: string}`
  - `Ledger::refund(array $data): array{payment_id: int, created: bool, payment_status: string}`
  - `Ledger::fail(int $paymentId, string $message = ''): string` (torna il `payment_status` dell'ordine)
  - `Ledger::sync(int $orderId): string`
  - Chiavi di `$data`: `order_id`, `amount`, `provider`, `provider_reference`,
    `customer_id`, `payment_method_id`, `payment_account_id`, `currency`,
    `note`, `user_id`, `source`.

- [ ] **Step 1: Porta fuori le funzioni di supporto**

Ora servono a due file di test, non più a uno. Crea
`tests/integrazione/supporto/compra.php` con dentro, **tali e quali**,
`articoloConGiacenza()`, `ordineDiProva()`, `resoDiProva()` e
`accendiFunzionalita()` che stanno in fondo a `tests/integrazione/AllocationTest.php`,
preceduti da `<?php` e dagli `use` che gli servono:

```php
<?php

use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Models\Sales\SalesReturn;
use Wonder\Plugin\Gestionale\Models\Stock\Stock as StockRow;
use Wonder\Plugin\Gestionale\Models\System\Feature;
use Wonder\Plugin\Gestionale\Support\Catalog\Code;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Plugin\Gestionale\Support\Stock\Locations;
```

Togli le funzioni da `AllocationTest.php` e mettici al loro posto, sotto gli
altri require:

```php
require __DIR__.'/supporto/compra.php';
```

- [ ] **Step 2: Controlla di non aver rotto niente**

Run: `php tests/integrazione/AllocationTest.php`
Expected: `29/29` verdi, come prima dello spostamento.

- [ ] **Step 3: Le funzioni di supporto dei pagamenti**

Crea `tests/integrazione/supporto/incassa.php`:

```php
<?php

use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Models\Sales\OrderStatusLog;

/** Com'è messo l'ordine adesso, riletto dal database. */
function statoPagamento(int $ordine): string
{
    $riga = Order::findById($ordine);

    return is_array($riga) ? (string) $riga['payment_status'] : '';
}

/** L'ultimo cambio di stato registrato sull'ordine, per il campo dato. */
function ultimoLog(int $ordine, string $campo): array
{
    $righe = sqlSelect(
        OrderStatusLog::$table,
        "order_id = {$ordine} AND field = '{$campo}'",
        order: 'id',
        orderDirection: 'DESC'
    );

    if (!is_array($righe) || $righe === []) {
        return [];
    }

    return isset($righe['id']) ? $righe : (array) reset($righe);
}
```

Se la firma di `sqlSelect()` nel core non accetta quegli argomenti con nome,
leggila in `packages/app/class/App/functions.php` e passa gli stessi valori
nell'ordine giusto: qui serve solo l'ultima riga in ordine di `id`.

- [ ] **Step 4: Scrivi i test rossi**

Crea `tests/integrazione/LedgerTest.php`:

```php
<?php

/** I pagamenti: incassi, rimborsi e lo stato che ne discende. */

const SITE = '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site';

chdir(SITE);
$GLOBALS['ROOT'] = SITE;

require SITE.'/vendor/autoload.php';
require SITE.'/wonder/start.php';
require __DIR__.'/../harness.php';
require __DIR__.'/supporto/compra.php';
require __DIR__.'/supporto/incassa.php';

use Wonder\Plugin\Gestionale\Models\Payments\Payment;
use Wonder\Plugin\Gestionale\Models\Payments\PaymentStatusLog;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Payments\Ledger;
use Wonder\Sql\Transaction;

final class Annulla extends RuntimeException {}

try {
    Transaction::run(static function (): void {
        check('il pagamento aperto lascia l\'ordine in attesa', function () {
            $ordine = ordineDiProva(100.0);
            $esito = Ledger::open(['order_id' => $ordine, 'amount' => 100.0]);

            $riga = Payment::findById($esito['payment_id']);

            return $esito['created'] === true
                && $esito['payment_status'] === 'pending'
                && is_array($riga)
                && $riga['status'] === 'pending'
                && $riga['type'] === 'payment'
                && statoPagamento($ordine) === 'pending';
        });

        check('l\'incasso pieno segna l\'ordine pagato', function () {
            $ordine = ordineDiProva(100.0);
            $esito = Ledger::register([
                'order_id' => $ordine,
                'amount' => 100.0,
                'provider' => 'stripe',
                'provider_reference' => 'pi_'.uniqid(),
            ]);

            $riga = Payment::findById($esito['payment_id']);

            return $esito['payment_status'] === 'paid'
                && is_array($riga)
                && $riga['status'] === 'paid'
                && trim((string) $riga['paid_at']) !== ''
                && statoPagamento($ordine) === 'paid';
        });

        check('un acconto lascia l\'ordine pagato in parte', function () {
            $ordine = ordineDiProva(100.0);
            $esito = Ledger::register(['order_id' => $ordine, 'amount' => 40.0]);

            return $esito['payment_status'] === 'partially_paid'
                && statoPagamento($ordine) === 'partially_paid';
        });

        check('l\'incasso chiude un pagamento già aperto invece di aggiungerne un altro', function () {
            $ordine = ordineDiProva(100.0);
            $riferimento = 'pi_'.uniqid();

            $aperto = Ledger::open([
                'order_id' => $ordine,
                'amount' => 100.0,
                'provider' => 'stripe',
                'provider_reference' => $riferimento,
            ]);
            $incassato = Ledger::register([
                'order_id' => $ordine,
                'amount' => 100.0,
                'provider' => 'stripe',
                'provider_reference' => $riferimento,
            ]);

            $righe = sqlCount(Payment::$table, "order_id = {$ordine}");

            return $incassato['payment_id'] === $aperto['payment_id']
                && $incassato['created'] === false
                && (int) $righe === 1
                && statoPagamento($ordine) === 'paid';
        });

        check('la stessa notifica due volte non incassa due volte', function () {
            $ordine = ordineDiProva(100.0);
            $riferimento = 'pi_'.uniqid();
            $dati = [
                'order_id' => $ordine,
                'amount' => 100.0,
                'provider' => 'stripe',
                'provider_reference' => $riferimento,
            ];

            $prima = Ledger::register($dati);
            $seconda = Ledger::register($dati);

            return $seconda['payment_id'] === $prima['payment_id']
                && $seconda['created'] === false
                && (int) sqlCount(Payment::$table, "order_id = {$ordine}") === 1
                && statoPagamento($ordine) === 'paid';
        });

        check('due incassi a mano senza riferimento restano due incassi', function () {
            $ordine = ordineDiProva(100.0);

            $prima = Ledger::register(['order_id' => $ordine, 'amount' => 50.0]);
            $seconda = Ledger::register(['order_id' => $ordine, 'amount' => 50.0]);

            return $seconda['payment_id'] !== $prima['payment_id']
                && $seconda['created'] === true
                && statoPagamento($ordine) === 'paid';
        });

        check('l\'incasso senza riferimento si firma con il proprio codice', function () {
            $esito = Ledger::register(['order_id' => ordineDiProva(30.0), 'amount' => 30.0]);
            $riga = Payment::findById($esito['payment_id']);

            return is_array($riga)
                && $riga['provider'] === 'manual'
                && $riga['provider_reference'] === $riga['code']
                && str_starts_with((string) $riga['code'], 'pay_');
        });

        check('un incasso di zero non si registra', function () {
            $ordine = ordineDiProva(100.0);

            try {
                Ledger::register(['order_id' => $ordine, 'amount' => 0]);
            } catch (UserError) {
                return (int) sqlCount(Payment::$table, "order_id = {$ordine}") === 0;
            }

            return false;
        });

        check('il pagamento fallito riporta l\'ordine a non pagato', function () {
            $ordine = ordineDiProva(100.0);
            $aperto = Ledger::open(['order_id' => $ordine, 'amount' => 100.0]);

            $stato = Ledger::fail($aperto['payment_id'], 'carta rifiutata');
            $riga = Payment::findById($aperto['payment_id']);

            return $stato === 'unpaid'
                && is_array($riga)
                && $riga['status'] === 'failed'
                && statoPagamento($ordine) === 'unpaid';
        });

        check('il rimborso pieno segna l\'ordine rimborsato', function () {
            $ordine = ordineDiProva(100.0);
            Ledger::register(['order_id' => $ordine, 'amount' => 100.0]);

            $esito = Ledger::refund(['order_id' => $ordine, 'amount' => 100.0]);
            $riga = Payment::findById($esito['payment_id']);

            return $esito['payment_status'] === 'refunded'
                && is_array($riga)
                && $riga['type'] === 'refund'
                && $riga['status'] === 'paid'
                && statoPagamento($ordine) === 'refunded';
        });

        check('il rimborso di una parte lascia l\'ordine rimborsato in parte', function () {
            $ordine = ordineDiProva(100.0);
            Ledger::register(['order_id' => $ordine, 'amount' => 100.0]);

            return Ledger::refund(['order_id' => $ordine, 'amount' => 30.0])['payment_status'] === 'partially_refunded'
                && statoPagamento($ordine) === 'partially_refunded';
        });

        check('l\'ordine a importo zero è pagato senza incassi', function () {
            $ordine = ordineDiProva(0.0);

            return Ledger::sync($ordine) === 'paid' && statoPagamento($ordine) === 'paid';
        });

        check('il cambio di stato dell\'ordine finisce nel registro', function () {
            $ordine = ordineDiProva(100.0);
            Ledger::register(['order_id' => $ordine, 'amount' => 100.0]);

            $log = ultimoLog($ordine, 'payment_status');

            return $log !== []
                && $log['from_value'] === 'unpaid'
                && $log['to_value'] === 'paid'
                && $log['source'] === 'system';
        });

        check('lo stato che non cambia non scrive righe nel registro', function () {
            $ordine = ordineDiProva(100.0);
            Ledger::register(['order_id' => $ordine, 'amount' => 100.0]);
            Ledger::sync($ordine);
            Ledger::sync($ordine);

            $righe = sqlCount(OrderStatusLog::$table, "order_id = {$ordine} AND field = 'payment_status'");

            return (int) $righe === 1;
        });

        check('anche il pagamento ha la sua storia', function () {
            $ordine = ordineDiProva(100.0);
            $aperto = Ledger::open(['order_id' => $ordine, 'amount' => 100.0]);
            Ledger::fail($aperto['payment_id'], 'carta rifiutata');

            $righe = sqlSelect(PaymentStatusLog::$table, 'payment_id = '.$aperto['payment_id']);

            return is_array($righe) && $righe !== [];
        });

        throw new Annulla();
    });
} catch (Annulla) {
    // Il database torna com'era.
}

summary();
```

Aggiungi in cima agli `use` anche `Wonder\Plugin\Gestionale\Models\Sales\OrderStatusLog;`.

- [ ] **Step 5: Guarda i test fallire**

Run: `php tests/integrazione/LedgerTest.php`
Expected: tutti rossi, con `Class "...Support\Payments\Ledger" not found`.

- [ ] **Step 6: Scrivi il registro**

Crea `src/Support/Payments/Ledger.php`:

```php
<?php

namespace Wonder\Plugin\Gestionale\Support\Payments;

use RuntimeException;
use Wonder\Plugin\Gestionale\Models\Payments\Payment;
use Wonder\Plugin\Gestionale\Models\Payments\PaymentStatusLog;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Models\Sales\OrderStatusLog;
use Wonder\Plugin\Gestionale\Support\Catalog\Code;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Status\StatusLogger;
use Wonder\Sql\Transaction;

/**
 * **L'unico che tocca il `payment_status` di un ordine.**
 *
 * Ogni movimento di denaro è una riga di `gst_payments`, rimborsi compresi, e
 * lo stato dell'ordine non è un campo che si imposta: è la conseguenza della
 * somma delle righe riuscite, ricalcolata da `PaymentStatus::of()` ogni volta
 * che una riga nasce o cambia.
 *
 * Contro la notifica doppia del gateway ci sono due difese. La prima è qui:
 * prima di scrivere si cerca la riga con quel `provider` e quel
 * `provider_reference` **con `FOR UPDATE`**, e siccome la condizione è
 * sull'indice unico, quando la riga non c'è InnoDB blocca lo spazio dove
 * andrebbe — la seconda notifica aspetta la prima, poi la trova e non scrive.
 * La seconda difesa è l'indice unico stesso, che regge comunque.
 *
 * Un pagamento senza riferimento — il bonifico a mano, i contanti al banco —
 * non ha niente da confrontare, e due incassi manuali uguali sono due incassi
 * veri: per non farli collidere sul riferimento vuoto si firmano con il
 * proprio codice (`pay_…`), che si conosce già prima di scrivere.
 */
final class Ledger
{
    /**
     * Apre un pagamento in attesa: la carta è stata avviata, il bonifico è
     * stato chiesto. L'ordine risulta «in attesa», non ancora pagato.
     *
     * @param array<string, mixed> $data
     * @return array{payment_id: int, created: bool, payment_status: string}
     */
    public static function open(array $data): array
    {
        return self::write($data, 'payment', 'pending');
    }

    /**
     * Incassa. Se il pagamento era già aperto con lo stesso riferimento, non
     * ne nasce un altro: quello si chiude.
     *
     * @param array<string, mixed> $data
     * @return array{payment_id: int, created: bool, payment_status: string}
     */
    public static function register(array $data): array
    {
        return self::write($data, 'payment', 'paid');
    }

    /**
     * Rimborsa. È una riga come le altre, di tipo `refund`: il denaro uscito
     * si racconta come quello entrato, e lo stato lo sa.
     *
     * @param array<string, mixed> $data
     * @return array{payment_id: int, created: bool, payment_status: string}
     */
    public static function refund(array $data): array
    {
        return self::write($data, 'refund', 'paid');
    }

    /**
     * Segna fallito un pagamento e ricalcola l'ordine: una carta rifiutata non
     * lascia l'ordine «in attesa» per sempre.
     *
     * @return string il nuovo `payment_status` dell'ordine
     */
    public static function fail(int $paymentId, string $message = ''): string
    {
        return Transaction::run(static function () use ($paymentId, $message): string {
            $row = Payment::findForUpdate(['id' => $paymentId], 1);

            if (!is_array($row) || $row === []) {
                throw new RuntimeException("Pagamento {$paymentId} non trovato.");
            }

            self::move($row, 'failed', $message);

            return self::sync((int) $row['order_id']);
        });
    }

    /**
     * Ricalcola il `payment_status` dell'ordine dalle sue righe e lo salva.
     *
     * È l'unico punto in cui quella colonna si scrive. Se lo stato non cambia
     * non si scrive niente e non si logga niente: un webhook che ripassa non
     * deve riempire la storia di righe uguali.
     */
    public static function sync(int $orderId): string
    {
        return Transaction::run(static function () use ($orderId): string {
            $order = Order::findForUpdate(['id' => $orderId], 1);

            if (!is_array($order) || $order === []) {
                throw new RuntimeException("Ordine {$orderId} non trovato.");
            }

            $status = PaymentStatus::of((float) $order['total'], self::paymentsOf($orderId));
            $before = (string) $order['payment_status'];

            if ($status === $before) {
                return $status;
            }

            Order::update(['payment_status' => $status], $orderId);

            StatusLogger::record(
                OrderStatusLog::class,
                $orderId,
                'payment_status',
                $before,
                $status,
                'system'
            );

            return $status;
        });
    }

    /**
     * La riga nuova, o quella che c'era già.
     *
     * @param array<string, mixed> $data
     * @return array{payment_id: int, created: bool, payment_status: string}
     */
    private static function write(array $data, string $type, string $status): array
    {
        $orderId = (int) ($data['order_id'] ?? 0);
        $amount = round((float) ($data['amount'] ?? 0), 2);

        if ($amount <= 0) {
            throw UserError::make('payment.zero_amount');
        }

        return Transaction::run(static function () use ($data, $type, $status, $orderId, $amount): array {
            $provider = self::provider($data);
            $reference = trim((string) ($data['provider_reference'] ?? ''));
            $existing = $reference === '' ? null : self::findByReference($provider, $reference);

            if (is_array($existing)) {
                self::move($existing, $status, (string) ($data['note'] ?? ''));

                return [
                    'payment_id' => (int) $existing['id'],
                    'created' => false,
                    'payment_status' => self::sync((int) $existing['order_id']),
                ];
            }

            $code = Code::make(Payment::class, Codes::PAYMENT);
            $created = Payment::create([
                'code' => $code,
                'type' => $type,
                'order_id' => $orderId,
                'customer_id' => (int) ($data['customer_id'] ?? 0),
                'payment_method_id' => (int) ($data['payment_method_id'] ?? 0),
                'payment_account_id' => (int) ($data['payment_account_id'] ?? 0),
                'amount' => self::money($amount),
                'currency' => (string) ($data['currency'] ?? 'EUR'),
                'status' => $status,
                'provider' => $provider,
                // Senza riferimento del gateway ci si firma con il proprio
                // codice: l'indice unico vuole un valore diverso per riga.
                'provider_reference' => $reference !== '' ? $reference : $code,
                'paid_at' => $status === 'paid' ? date('Y-m-d H:i:s') : '',
                'note' => (string) ($data['note'] ?? ''),
            ]);

            if (($created->success ?? false) !== true) {
                throw new RuntimeException('Pagamento non scritto.');
            }

            $paymentId = (int) ($created->insert_id ?? 0);

            StatusLogger::record(
                PaymentStatusLog::class,
                $paymentId,
                'status',
                '',
                $status,
                self::source($data),
                (int) ($data['user_id'] ?? 0) ?: null
            );

            return [
                'payment_id' => $paymentId,
                'created' => true,
                'payment_status' => self::sync($orderId),
            ];
        });
    }

    /**
     * Porta una riga già scritta a un altro stato, e lo annota.
     *
     * Chi è già in quello stato non si tocca: è la notifica ripetuta.
     *
     * @param array<string, mixed> $row
     */
    private static function move(array $row, string $status, string $message = ''): void
    {
        $before = (string) $row['status'];

        if ($before === $status) {
            return;
        }

        $changes = ['status' => $status];

        if ($status === 'paid' && trim((string) ($row['paid_at'] ?? '')) === '') {
            $changes['paid_at'] = date('Y-m-d H:i:s');
        }

        Payment::update($changes, (int) $row['id']);

        StatusLogger::record(
            PaymentStatusLog::class,
            (int) $row['id'],
            'status',
            $before,
            $status,
            'system',
            null,
            $message
        );
    }

    /**
     * La riga di quel gateway con quel riferimento, bloccata.
     *
     * La condizione è sull'indice unico: se la riga non c'è, il blocco resta
     * sullo spazio dove andrebbe, e la notifica gemella aspetta qui.
     *
     * @return array<string, mixed>|null
     */
    private static function findByReference(string $provider, string $reference): ?array
    {
        $row = Payment::findForUpdate([
            'provider' => $provider,
            'provider_reference' => $reference,
        ], 1);

        return is_array($row) && $row !== [] ? $row : null;
    }

    /**
     * Le righe di denaro dell'ordine, nella forma che `PaymentStatus` vuole.
     *
     * @return list<array<string, mixed>>
     */
    private static function paymentsOf(int $orderId): array
    {
        $rows = Payment::find(['order_id' => $orderId, 'deleted' => 'false']);

        if (!is_array($rows) || $rows === []) {
            return [];
        }

        return isset($rows['id']) ? [$rows] : array_values(array_filter($rows, 'is_array'));
    }

    /** @param array<string, mixed> $data */
    private static function provider(array $data): string
    {
        $provider = trim((string) ($data['provider'] ?? ''));

        return in_array($provider, Payment::PROVIDERS, true) ? $provider : 'manual';
    }

    /** @param array<string, mixed> $data */
    private static function source(array $data): string
    {
        $source = trim((string) ($data['source'] ?? ''));

        return in_array($source, StatusLogger::SOURCES, true) ? $source : 'system';
    }

    /** Il denaro si scrive con il punto e due decimali, mai con la virgola. */
    private static function money(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}
```

Se `Payment::find()` non accetta l'array di condizioni, passa la condizione
come stringa (`"order_id = {$orderId} AND deleted = 'false'"`): la forma
giusta è quella che usano gli altri supporti del modulo.

- [ ] **Step 7: La frase dell'errore**

In `lang/it/gestionale.json`, dentro `errors`, dopo il gruppo `order`:

```json
    "payment": {
      "zero_amount": "Un pagamento deve avere un importo maggiore di zero."
    },
```

- [ ] **Step 8: Guarda i test passare**

Run: `php tests/integrazione/LedgerTest.php`
Expected: `15/15` verdi.

- [ ] **Step 9: Tutta la suite**

Run: `php tests/run.php`
Expected: verde, `ErrorKeysTest` compreso — è quello che controlla che
`payment.zero_amount` abbia la sua frase.

- [ ] **Step 10: Commit**

```bash
git add src/Support/Payments/Ledger.php tests/integrazione/LedgerTest.php \
    tests/integrazione/supporto lang/it/gestionale.json tests/integrazione/AllocationTest.php
git commit -m "Tiene il registro dei pagamenti e ricalcola lo stato dell'ordine"
```

---
### Task 6: I due casi che una transazione sola non sa provare

Tutto il resto della suite gira dentro una transazione annullata: comoda,
veloce, e cieca proprio sulle due cose che contano qui. Due checkout che si
contendono l'ultimo pezzo e due notifiche gemelle dello stesso incasso sono
guasti fra transazioni diverse: per vederli servono due processi veri, dati
committati e una pulizia a mano.

Questo test non aggiunge codice di produzione: mette alla prova i blocchi
scritti nei Task 2 e 5. Perciò il passo rosso non è «non compila», è
**spegnere il blocco e guardare il guasto succedere davvero**. Un test di
contemporaneità che non l'ha mai visto fallire non sta misurando niente.

**Files:**
- Create: `tests/integrazione/ContemporaneitaTest.php`

**Interfaces:**
- Consumes: `Allocation::reserve()` (Task 2), `Ledger::register()` (Task 5).
- Produces: niente. È solo prova.

- [ ] **Step 1: Scrivi il test**

Crea `tests/integrazione/ContemporaneitaTest.php`:

```php
<?php

/**
 * Le due gare che una transazione sola non sa vedere.
 *
 * Il file fa da padre e da figlio: senza argomenti prepara i dati, lancia due
 * processi e giudica; con un argomento è uno dei due processi. I dati qui
 * sono committati davvero — è tutto il punto — e la pulizia è a mano, in
 * fondo.
 */

const SITE = '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site';

chdir(SITE);
$GLOBALS['ROOT'] = SITE;

require SITE.'/vendor/autoload.php';
require SITE.'/wonder/start.php';

use Wonder\Plugin\Gestionale\Models\Payments\Payment;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Models\Stock\Stock as StockRow;
use Wonder\Plugin\Gestionale\Models\Stock\StockReservation;
use Wonder\Plugin\Gestionale\Support\Payments\Ledger;
use Wonder\Plugin\Gestionale\Support\Stock\Allocation;
use Wonder\Sql\Transaction;

$ruolo = $argv[1] ?? 'padre';

/* ---------------------------------------------------------------- figli -- */

if ($ruolo === 'pezzo') {
    [$prodotto, $ordine, $barriera] = [(int) $argv[2], (int) $argv[3], (string) $argv[4]];

    attendi($barriera);

    try {
        Transaction::run(static function () use ($prodotto, $ordine): void {
            Allocation::reserve(['product_id' => $prodotto, 'quantity' => 1, 'order_id' => $ordine]);
            // Tiene il blocco quel tanto che basta perché l'altro ci sbatta.
            usleep(250000);
        });

        echo 'OK';
    } catch (Throwable) {
        echo 'KO';
    }

    exit;
}

if ($ruolo === 'incasso') {
    [$ordine, $riferimento, $barriera] = [(int) $argv[2], (string) $argv[3], (string) $argv[4]];

    attendi($barriera);

    try {
        $esito = Transaction::run(static function () use ($ordine, $riferimento): array {
            $registrato = Ledger::register([
                'order_id' => $ordine,
                'amount' => 50.0,
                'provider' => 'stripe',
                'provider_reference' => $riferimento,
            ]);
            usleep(250000);

            return $registrato;
        });

        echo $esito['payment_id'];
    } catch (Throwable $e) {
        echo 'KO: '.$e->getMessage();
    }

    exit;
}

/* ---------------------------------------------------------------- padre -- */

require __DIR__.'/../harness.php';
require __DIR__.'/supporto/compra.php';

$spazzatura = ['prodotti' => [], 'ordini' => []];

register_shutdown_function(static function () use (&$spazzatura): void {
    foreach ($spazzatura['prodotti'] as $prodotto) {
        sqlDelete(StockReservation::$table, "product_id = {$prodotto}");
        sqlDelete(StockRow::$table, "product_id = {$prodotto}");
        sqlDelete('gst_stock_movements', "product_id = {$prodotto}");
        sqlDelete('gst_products', "id = {$prodotto}");
    }

    foreach ($spazzatura['ordini'] as $ordine) {
        sqlDelete(Payment::$table, "order_id = {$ordine}");
        sqlDelete('gst_order_status_logs', "order_id = {$ordine}");
        sqlDelete(Order::$table, "id = {$ordine}");
    }
});

check('l\'ultimo pezzo lo prende uno solo dei due', function () use (&$spazzatura) {
    $prodotto = articoloConGiacenza(1, 'TST-GARA-'.substr((string) microtime(true), -6));
    $primo = ordineDiProva();
    $secondo = ordineDiProva();

    $spazzatura['prodotti'][] = $prodotto;
    $spazzatura['ordini'][] = $primo;
    $spazzatura['ordini'][] = $secondo;

    $barriera = barriera();
    $processi = [
        avvia('pezzo', [$prodotto, $primo, $barriera]),
        avvia('pezzo', [$prodotto, $secondo, $barriera]),
    ];

    via($barriera);
    $esiti = array_map('esito', $processi);
    sort($esiti);

    // Il perdente non scrive niente: la sua transazione è tornata indietro.
    $righe = (int) sqlCount(StockReservation::$table, "product_id = {$prodotto} AND deleted = 'false'");

    return $esiti === ['KO', 'OK'] && $righe === 1;
});

check('la stessa notifica da due processi incassa una volta sola', function () use (&$spazzatura) {
    $ordine = ordineDiProva(100.0);
    $spazzatura['ordini'][] = $ordine;

    $riferimento = 'pi_gara_'.uniqid();
    $barriera = barriera();
    $processi = [
        avvia('incasso', [$ordine, $riferimento, $barriera]),
        avvia('incasso', [$ordine, $riferimento, $barriera]),
    ];

    via($barriera);
    $esiti = array_map('esito', $processi);

    $righe = (int) sqlCount(Payment::$table, "order_id = {$ordine}");
    $riga = Order::findById($ordine);

    return $esiti[0] === $esiti[1]
        && ctype_digit($esiti[0])
        && $righe === 1
        && is_array($riga)
        && $riga['payment_status'] === 'partially_paid';
});

summary();

/* -------------------------------------------------------------- arnesi -- */

/** Il file che dà il via: i figli aspettano che compaia. */
function barriera(): string
{
    return sys_get_temp_dir().'/wi-gara-'.uniqid().'.via';
}

function via(string $barriera): void
{
    // Un istante perché i due figli arrivino entrambi all'attesa.
    usleep(200000);
    touch($barriera);
}

function attendi(string $barriera): void
{
    $scadenza = microtime(true) + 10;

    while (!file_exists($barriera)) {
        if (microtime(true) > $scadenza) {
            echo 'KO: via mai dato';
            exit;
        }

        usleep(2000);
    }
}

/**
 * Lancia questo stesso file in un altro processo.
 *
 * @param list<int|string> $argomenti
 * @return array{0: mixed, 1: array<int, resource>}
 */
function avvia(string $ruolo, array $argomenti): array
{
    $comando = escapeshellarg(PHP_BINARY).' '.escapeshellarg(__FILE__).' '.escapeshellarg($ruolo);

    foreach ($argomenti as $argomento) {
        $comando .= ' '.escapeshellarg((string) $argomento);
    }

    $pipe = [];
    $processo = proc_open($comando, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipe);

    return [$processo, $pipe];
}

/** @param array{0: mixed, 1: array<int, resource>} $avviato */
function esito(array $avviato): string
{
    [$processo, $pipe] = $avviato;

    $uscita = trim((string) stream_get_contents($pipe[1]));
    $errore = trim((string) stream_get_contents($pipe[2]));

    fclose($pipe[1]);
    fclose($pipe[2]);
    proc_close($processo);

    return $uscita !== '' ? $uscita : 'ERRORE: '.$errore;
}
```

`ordineDiProva()` e `articoloConGiacenza()` arrivano da `supporto/compra.php`
(Task 5): qui girano fuori da ogni transazione, quindi quello che scrivono
resta, ed è la lista `$spazzatura` a portarlo via alla fine.

- [ ] **Step 2: Dimostra che il test sa vedere il guasto**

Questo è il passo rosso, e va fatto in due tempi.

Primo: in `src/Support/Stock/Allocation.php`, dentro `lockedQuantity()`,
sostituisci `findForUpdate` con `find` — cioè leggi la giacenza **senza**
bloccarla.

Run: `php tests/integrazione/ContemporaneitaTest.php`
Expected: il primo check fallisce, perché i due processi riescono tutti e due
(`['OK', 'OK']`) e le prenotazioni vive sono 2. Rimetti `findForUpdate`.

Secondo: in `src/Support/Payments/Ledger.php`, dentro `findByReference()`,
sostituisci `Payment::findForUpdate` con `Payment::find`.

Run: `php tests/integrazione/ContemporaneitaTest.php`
Expected: il secondo check fallisce — due `payment_id` diversi, oppure uno dei
due processi che muore sull'indice unico. In entrambi i casi la difesa che
volevamo — la prima, quella che fa aspettare — non c'era. Rimetti
`findForUpdate`.

Se **non** fallisce, il test non sta misurando la gara: quasi sempre è perché
i figli non partono insieme. Alza l'attesa in `via()` e riprova prima di
andare avanti.

- [ ] **Step 3: Guarda i test passare**

Run: `php tests/integrazione/ContemporaneitaTest.php`
Expected: `2/2` verdi.

- [ ] **Step 4: Controlla di non aver lasciato sporco**

Run: `php tests/integrazione/ContemporaneitaTest.php` (una seconda volta)
Expected: di nuovo `2/2`. Un test che la seconda volta non passa è un test che
si è lasciato dietro righe: guarda la pulizia in fondo al file.

- [ ] **Step 5: Tutta la suite**

Run: `php tests/run.php`
Expected: verde.

- [ ] **Step 6: Una riga nel changelog**

In `CHANGELOG.md`, sotto `## 0.1.0 — non rilasciata` → `### Aggiunto`, in fondo
all'elenco:

```markdown
- Magazzino delle vendite e pagamenti: `Support\Stock\Allocation` mette da
  parte la merce di un carrello, la scarica alla conferma senza vendere due
  volte l'ultimo pezzo e la fa rientrare da annullamenti e resi;
  `Support\Payments\Ledger` tiene le righe di denaro, regge la notifica doppia
  del gateway e ricalcola da solo il `payment_status` dell'ordine.
```

- [ ] **Step 7: Commit**

```bash
git add tests/integrazione/ContemporaneitaTest.php CHANGELOG.md
git commit -m "Prova con due processi l'ultimo pezzo conteso e la notifica doppia"
```

---

## Come sapere che è finita

```bash
php tests/run.php
```

Tutto verde, `ContemporaneitaTest` compreso. Dopo questo piano il modulo sa
mettere da parte la merce di un carrello, scaricarla alla conferma senza
vendere due volte l'ultimo pezzo, farla rientrare da un annullamento o da un
reso, e tenere il conto del denaro con uno stato dell'ordine che nessuno
scrive a mano. Restano fuori, e arrivano con il Piano 3: la scadenza delle
prenotazioni a tempo, le email al cliente e al commerciante, e le pagine di
backend che mostrano tutto questo.
