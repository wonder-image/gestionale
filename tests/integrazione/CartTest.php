<?php
/** php tests/integrazione/CartTest.php */

const SITE = '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site';

chdir(SITE);
$GLOBALS['ROOT'] = SITE;

require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';
require __DIR__.'/../harness.php';
require __DIR__.'/supporto/compra.php';

use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Catalog\BundleComponent;
use Wonder\Plugin\Gestionale\Models\Catalog\BundleGroup;
use Wonder\Plugin\Gestionale\Models\Catalog\BundleGroupOption;
use Wonder\Plugin\Gestionale\Models\Catalog\Customization;
use Wonder\Plugin\Gestionale\Models\Catalog\CustomizationOption;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModelCustomization;
use Wonder\Plugin\Gestionale\Models\Sales\OrderItem;
use Wonder\Plugin\Gestionale\Support\Catalog\Bundles;
use Wonder\Plugin\Gestionale\Support\Errors\UserError;
use Wonder\Plugin\Gestionale\Support\Orders\Cart;
use Wonder\Sql\Transaction;

/** Serve a buttare via tutto quello che la prova scrive. */
final class Annulla extends RuntimeException {}

/** Fa girare il corpo dentro una transazione e poi la annulla. */
function prova(callable $corpo): mixed
{
    $esito = null;

    try {
        Transaction::run(static function () use ($corpo, &$esito): void {
            $esito = $corpo();

            throw new Annulla();
        });
    } catch (Annulla) {
        // Voluto: i dati della prova non restano.
    }

    return $esito;
}

check('un carrello nuovo nasce con il gettone dell\'ospite', function () {
    return prova(static function (): bool {
        $carrello = Cart::open(['cart_token' => 'tok-'.uniqid()]);

        return (int) $carrello['id'] > 0
            && $carrello['stage'] === 'cart'
            && $carrello['status'] === 'draft'
            && trim((string) $carrello['last_activity_at']) !== '';
    });
});

check('lo stesso gettone ritrova il carrello di prima', function () {
    return prova(static function (): bool {
        $gettone = 'tok-'.uniqid();

        return (int) Cart::open(['cart_token' => $gettone])['id']
            === (int) Cart::open(['cart_token' => $gettone])['id'];
    });
});

check('la riga aggiunta porta prezzo, quantità e totale', function () {
    return prova(static function (): bool {
        $prodotto = articoloConGiacenza(10, 'TST-CART-'.substr((string) microtime(true), -6));
        Product::update(['price' => '20.00'], $prodotto);

        $carrello = Cart::open(['cart_token' => 'tok-'.uniqid()]);
        $esito = Cart::add((int) $carrello['id'], ['product_id' => $prodotto, 'quantity' => 2]);
        $riga = $esito['items'][0] ?? [];

        return count($esito['items']) === 1
            && (string) $riga['unit_price'] === '20.00'
            && (string) $riga['line_total'] === '40.00'
            && (string) $esito['order']['products_total'] === '40.00';
    });
});

check('la riga copia il nome intero (articolo e opzione) e la foto di quel momento', function () {
    return prova(static function (): bool {
        $prodotto = articoloConGiacenza(10, 'TST-CART-'.substr((string) microtime(true), -6));
        Product::update(['price' => '20.00'], $prodotto);
        $riga = (array) Product::findById($prodotto);
        $modelId = (int) $riga['product_model_id'];
        $modello = (array) Wonder\Plugin\Gestionale\Models\Catalog\ProductModel::findById($modelId);
        $file = 'prova-riga-'.uniqid().'.jpg';
        Wonder\Plugin\Gestionale\Models\Catalog\ProductImage::create([
            'product_model_id' => $modelId, 'file' => json_encode([$file]), 'position' => 1, 'status' => 'ready', 'attempts' => 0,
        ]);

        $carrello = Cart::open(['cart_token' => 'tok-'.uniqid()]);
        $voce = Cart::add((int) $carrello['id'], ['product_id' => $prodotto, 'quantity' => 1])['items'][0] ?? [];
        $nome = (string) ($voce['name'] ?? '');

        return str_starts_with($nome, (string) $modello['name'])
            && str_contains($nome, (string) $riga['name'])
            && str_ends_with((string) ($voce['image'] ?? ''), $file);
    });
});

check('due volte lo stesso articolo fanno una riga sola', function () {
    return prova(static function (): bool {
        $prodotto = articoloConGiacenza(10, 'TST-CART-'.substr((string) microtime(true), -6));
        Product::update(['price' => '20.00'], $prodotto);

        $carrello = (int) Cart::open(['cart_token' => 'tok-'.uniqid()])['id'];
        Cart::add($carrello, ['product_id' => $prodotto, 'quantity' => 1]);
        $esito = Cart::add($carrello, ['product_id' => $prodotto, 'quantity' => 2]);

        return count($esito['items']) === 1
            && (float) $esito['items'][0]['quantity'] === 3.0
            && (string) $esito['order']['products_total'] === '60.00';
    });
});

check('più pezzi di quanti ce ne sono: rifiutato, e dice quanti restano', function () {
    return prova(static function (): string {
        $prodotto = articoloConGiacenza(3, 'TST-CART-'.substr((string) microtime(true), -6));
        $carrello = (int) Cart::open(['cart_token' => 'tok-'.uniqid()])['id'];

        try {
            Cart::add($carrello, ['product_id' => $prodotto, 'quantity' => 4]);
        } catch (UserError $errore) {
            return $errore->getMessage();
        }

        return 'nessun rifiuto';
    }) !== 'nessun rifiuto';
});

check('quantità zero: rifiutata', function () {
    return prova(static function (): bool {
        $prodotto = articoloConGiacenza(3, 'TST-CART-'.substr((string) microtime(true), -6));
        $carrello = (int) Cart::open(['cart_token' => 'tok-'.uniqid()])['id'];

        try {
            Cart::add($carrello, ['product_id' => $prodotto, 'quantity' => 0]);
        } catch (UserError) {
            return true;
        }

        return false;
    });
});

check('la quantità cambiata rifà il totale', function () {
    return prova(static function (): bool {
        $prodotto = articoloConGiacenza(10, 'TST-CART-'.substr((string) microtime(true), -6));
        Product::update(['price' => '15.00'], $prodotto);

        $carrello = (int) Cart::open(['cart_token' => 'tok-'.uniqid()])['id'];
        $esito = Cart::add($carrello, ['product_id' => $prodotto, 'quantity' => 1]);
        $riga = (int) $esito['items'][0]['id'];
        $dopo = Cart::setQuantity($carrello, $riga, 4);

        return (float) $dopo['items'][0]['quantity'] === 4.0
            && (string) $dopo['order']['products_total'] === '60.00';
    });
});

check('la quantità a zero toglie la riga', function () {
    return prova(static function (): bool {
        $prodotto = articoloConGiacenza(10, 'TST-CART-'.substr((string) microtime(true), -6));
        $carrello = (int) Cart::open(['cart_token' => 'tok-'.uniqid()])['id'];
        $esito = Cart::add($carrello, ['product_id' => $prodotto, 'quantity' => 1]);
        $dopo = Cart::setQuantity($carrello, (int) $esito['items'][0]['id'], 0);

        return $dopo['items'] === [] && (string) $dopo['order']['products_total'] === '0.00';
    });
});

check('la riga tolta non torna', function () {
    return prova(static function (): bool {
        $prodotto = articoloConGiacenza(10, 'TST-CART-'.substr((string) microtime(true), -6));
        $carrello = (int) Cart::open(['cart_token' => 'tok-'.uniqid()])['id'];
        $esito = Cart::add($carrello, ['product_id' => $prodotto, 'quantity' => 2]);

        return Cart::remove($carrello, (int) $esito['items'][0]['id'])['items'] === [];
    });
});

check('la riga di un altro carrello non si tocca', function () {
    return prova(static function (): bool {
        $prodotto = articoloConGiacenza(10, 'TST-CART-'.substr((string) microtime(true), -6));
        $mio = (int) Cart::open(['cart_token' => 'tok-'.uniqid()])['id'];
        $altrui = (int) Cart::open(['cart_token' => 'tok-'.uniqid()])['id'];
        $esito = Cart::add($altrui, ['product_id' => $prodotto, 'quantity' => 1]);

        try {
            Cart::remove($mio, (int) $esito['items'][0]['id']);
        } catch (UserError) {
            return true;
        }

        return false;
    });
});

check('due carrelli uniti sommano le righe uguali', function () {
    return prova(static function (): bool {
        $prodotto = articoloConGiacenza(10, 'TST-CART-'.substr((string) microtime(true), -6));
        Product::update(['price' => '10.00'], $prodotto);

        $ospite = (int) Cart::open(['cart_token' => 'tok-'.uniqid()])['id'];
        $cliente = (int) Cart::open(['cart_token' => 'tok-'.uniqid()])['id'];
        Cart::add($ospite, ['product_id' => $prodotto, 'quantity' => 2]);
        Cart::add($cliente, ['product_id' => $prodotto, 'quantity' => 1]);

        $unito = Cart::merge($ospite, $cliente);

        return count($unito['items']) === 1
            && (float) $unito['items'][0]['quantity'] === 3.0
            && (string) $unito['order']['products_total'] === '30.00'
            && empty(Wonder\Plugin\Gestionale\Models\Sales\Order::find(
                ['id' => $ospite, 'deleted' => 'false'],
                1
            ));
    });
});

check('l\'unione non scrive più pezzi di quanti ce ne sono', function () {
    return prova(static function (): bool {
        $prodotto = articoloConGiacenza(3, 'TST-CART-'.substr((string) microtime(true), -6));
        $ospite = (int) Cart::open(['cart_token' => 'tok-'.uniqid()])['id'];
        $cliente = (int) Cart::open(['cart_token' => 'tok-'.uniqid()])['id'];
        Cart::add($ospite, ['product_id' => $prodotto, 'quantity' => 2]);
        Cart::add($cliente, ['product_id' => $prodotto, 'quantity' => 2]);

        // Quattro pezzi chiesti, tre sul banco: l'unione si ferma a tre invece
        // di scrivere una quantità che il checkout non potrebbe prenotare.
        return (float) Cart::merge($ospite, $cliente)['items'][0]['quantity'] === 3.0;
    });
});

check('l\'articolo spento sotto il carrello esce, e si sa', function () {
    return prova(static function (): bool {
        $prodotto = articoloConGiacenza(10, 'TST-CART-'.substr((string) microtime(true), -6));
        $carrello = (int) Cart::open(['cart_token' => 'tok-'.uniqid()])['id'];
        Cart::add($carrello, ['product_id' => $prodotto, 'quantity' => 1]);

        Product::update(['active' => 'false'], $prodotto);
        $dopo = Cart::recalculate($carrello);

        return $dopo['items'] === []
            && count($dopo['removed']) === 1
            && (string) $dopo['order']['total'] === '0.00';
    });
});

/**
 * Un articolo con 10 pezzi a 20,00, con le funzionalità accese; ridà
 * [prodotto, modello, carrello].
 *
 * @return array{0:int, 1:int, 2:int}
 */
function articoloPersonalizzabile(): array
{
    accendiFunzionalita(['orders', 'customizations']);
    $prodotto = articoloConGiacenza(10, 'TST-CUS-'.uniqid());
    Product::update(['price' => '20.00'], $prodotto);
    $carrello = (int) Cart::open(['cart_token' => 'tok-'.uniqid()])['id'];

    return [$prodotto, modelloDi($prodotto), $carrello];
}

check('un\'incisione entra con il sovrapprezzo deciso dal server', function () {
    $esito = prova(static function (): array {
        [$prodotto, $modello, $carrello] = articoloPersonalizzabile();
        $incisione = personalizzazioneDiProva(['name' => 'Incisione', 'surcharge' => '5.00']);
        collegaPersonalizzazione($modello, $incisione);

        return Cart::add($carrello, ['product_id' => $prodotto, 'customization' => [$incisione => 'Marco']]);
    });
    Gestionale::reset();
    $riga = $esito['items'][0] ?? [];
    $campo = $riga['customization'][0] ?? [];

    return (string) ($riga['unit_price'] ?? '') === '25.00'
        && (string) ($riga['customization_surcharge'] ?? '') === '5.00'
        && is_array($riga['customization'])
        && count($riga['customization']) === 1
        && $campo['label'] === 'Incisione'
        && $campo['value'] === 'Marco'
        && $campo['option_id'] === 0
        && $campo['surcharge'] === '5.00';
});

check('il sovrapprezzo mandato dal client è ignorato', function () {
    $esito = prova(static function (): array {
        [$prodotto, $modello, $carrello] = articoloPersonalizzabile();
        $incisione = personalizzazioneDiProva(['surcharge' => '5.00']);
        collegaPersonalizzazione($modello, $incisione);

        return Cart::add($carrello, [
            'product_id' => $prodotto,
            'customization' => [$incisione => 'Marco'],
            'customization_surcharge' => '999',
        ]);
    });
    Gestionale::reset();

    return (string) ($esito['items'][0]['customization_surcharge'] ?? '') === '5.00'
        && (string) ($esito['items'][0]['unit_price'] ?? '') === '25.00';
});

check('testo con accenti, emoji e & torna identico e nel database è solo ASCII', function () {
    $esito = prova(static function (): array {
        [$prodotto, $modello, $carrello] = articoloPersonalizzabile();
        $uno = personalizzazioneDiProva(['name' => 'Uno', 'max_length' => 50]);
        $due = personalizzazioneDiProva(['name' => 'Due', 'max_length' => 50]);
        collegaPersonalizzazione($modello, $uno, false, 1);
        collegaPersonalizzazione($modello, $due, false, 2);
        $dopo = Cart::add($carrello, [
            'product_id' => $prodotto,
            'customization' => [$uno => 'Café ☕ 😀', $due => 'A & B'],
        ]);
        $grezzo = (string) (OrderItem::findById((int) $dopo['items'][0]['id'])['customization'] ?? '');

        return ['dopo' => Cart::contents($carrello), 'grezzo' => $grezzo];
    });
    Gestionale::reset();
    $valori = array_column($esito['dopo']['items'][0]['customization'] ?? [], 'value');

    return $valori === ['Café ☕ 😀', 'A & B']
        && $esito['grezzo'] !== ''
        && preg_match('/^[\x20-\x7E]+$/', $esito['grezzo']) === 1;
});

check('gli stessi valori fanno una riga sola, valori diversi due righe', function () {
    $esito = prova(static function (): array {
        [$prodotto, $modello, $carrello] = articoloPersonalizzabile();
        $uno = personalizzazioneDiProva(['name' => 'Uno']);
        $due = personalizzazioneDiProva(['name' => 'Due']);
        collegaPersonalizzazione($modello, $uno, false, 1);
        collegaPersonalizzazione($modello, $due, false, 2);

        Cart::add($carrello, ['product_id' => $prodotto, 'customization' => [$uno => 'a', $due => 'b']]);
        $stesse = Cart::add($carrello, ['product_id' => $prodotto, 'customization' => [(string) $due => 'b', (string) $uno => 'a']]);
        $diverse = Cart::add($carrello, ['product_id' => $prodotto, 'customization' => [$uno => 'a', $due => 'c']]);

        return ['stesse' => $stesse['items'], 'diverse' => $diverse['items']];
    });
    Gestionale::reset();

    return count($esito['stesse']) === 1
        && (float) $esito['stesse'][0]['quantity'] === 2.0
        && count($esito['diverse']) === 2;
});

check('la scelta somma il sovrapprezzo della personalizzazione e quello dell\'opzione', function () {
    $esito = prova(static function (): array {
        [$prodotto, $modello, $carrello] = articoloPersonalizzabile();
        $colore = personalizzazioneDiProva(['name' => 'Colore', 'surcharge' => '3.00'], [
            ['label' => 'Bianca', 'surcharge' => 0],
            ['label' => 'Rossa', 'surcharge' => 2],
        ]);
        collegaPersonalizzazione($modello, $colore);
        $rossa = (int) CustomizationOption::find(['customization_id' => $colore, 'label' => 'Rossa'], 1)['id'];

        return Cart::add($carrello, ['product_id' => $prodotto, 'customization' => [$colore => $rossa]]);
    });
    Gestionale::reset();
    $riga = $esito['items'][0] ?? [];

    return (string) ($riga['customization_surcharge'] ?? '') === '5.00'
        && ($riga['customization'][0]['value'] ?? '') === 'Rossa';
});

check('una personalizzazione obbligatoria mancante rifiuta la riga', function () {
    $esito = prova(static function (): array {
        [$prodotto, $modello, $carrello] = articoloPersonalizzabile();
        $incisione = personalizzazioneDiProva();
        collegaPersonalizzazione($modello, $incisione, true);

        try {
            Cart::add($carrello, ['product_id' => $prodotto, 'customization' => []]);
        } catch (UserError $e) {
            return ['chiave' => $e->key(), 'campo' => $e->field(), 'id' => $incisione, 'righe' => count(Cart::contents($carrello)['items'])];
        }

        return [];
    });
    Gestionale::reset();

    return ($esito['chiave'] ?? '') === 'customization.required'
        && ($esito['campo'] ?? null) === ($esito['id'] ?? 0)
        && ($esito['righe'] ?? -1) === 0;
});

check('il sovrapprezzo cambiato in anagrafica riprezza la riga nel carrello', function () {
    $esito = prova(static function (): array {
        [$prodotto, $modello, $carrello] = articoloPersonalizzabile();
        $incisione = personalizzazioneDiProva(['name' => 'Incisione', 'surcharge' => '5.00']);
        collegaPersonalizzazione($modello, $incisione);
        Cart::add($carrello, ['product_id' => $prodotto, 'customization' => [$incisione => 'Marco']]);

        Customization::update(['surcharge' => '8.00'], $incisione);

        return Cart::recalculate($carrello);
    });
    Gestionale::reset();
    $riga = $esito['items'][0] ?? [];

    return (string) ($riga['unit_price'] ?? '') === '28.00'
        && (string) ($riga['customization_surcharge'] ?? '') === '8.00'
        && (string) ($esito['order']['products_total'] ?? '') === '28.00';
});

check('l\'etichetta cambiata in anagrafica si copia finché la riga è nel carrello', function () {
    $esito = prova(static function (): array {
        [$prodotto, $modello, $carrello] = articoloPersonalizzabile();
        $incisione = personalizzazioneDiProva(['name' => 'Incisione', 'label' => 'Incisione']);
        collegaPersonalizzazione($modello, $incisione);
        Cart::add($carrello, ['product_id' => $prodotto, 'customization' => [$incisione => 'Marco']]);

        Customization::update(['label' => 'Nome inciso'], $incisione);

        return Cart::recalculate($carrello);
    });
    Gestionale::reset();

    return ($esito['items'][0]['customization'][0]['label'] ?? '') === 'Nome inciso';
});

/**
 * Prepara un carrello con una riga personalizzata, cambia l'anagrafica con
 * `$guasto` e ridà il ricalcolo.
 *
 * @param callable(int, int, int): void $guasto riceve (personalizzazione, modello, collegamento)
 *
 * @return array<string, mixed>
 */
function ricalcoloDopo(callable $guasto, string $tipo = 'text'): array
{
    return prova(static function () use ($guasto, $tipo): array {
        [$prodotto, $modello, $carrello] = articoloPersonalizzabile();
        $id = $tipo === 'choice'
            ? personalizzazioneDiProva(['name' => 'Colore'], [['label' => 'Rossa'], ['label' => 'Blu']])
            : personalizzazioneDiProva(['name' => 'Incisione']);
        $collegamento = collegaPersonalizzazione($modello, $id);
        $valore = $tipo === 'choice'
            ? (int) CustomizationOption::find(['customization_id' => $id, 'label' => 'Rossa'], 1)['id']
            : 'Marco';
        Cart::add($carrello, ['product_id' => $prodotto, 'customization' => [$id => $valore]]);

        $guasto($id, $modello, $collegamento);

        return Cart::recalculate($carrello) + ['nome' => 'x'];
    });
}

check('personalizzazione disattivata: la riga esce e il nome è in removed', function () {
    $dopo = ricalcoloDopo(static fn (int $id) => Customization::update(['active' => 'false'], $id));
    Gestionale::reset();

    return $dopo['items'] === [] && count($dopo['removed']) === 1;
});

check('collegamento tolto all\'articolo: la riga esce', function () {
    $dopo = ricalcoloDopo(static fn (int $id, int $m, int $c) => ProductModelCustomization::query()
        ->Update(ProductModelCustomization::$table, ['deleted' => 'true'], 'id', $c));
    Gestionale::reset();

    return $dopo['items'] === [] && count($dopo['removed']) === 1;
});

check('opzione cancellata: la riga esce', function () {
    $dopo = ricalcoloDopo(static function (int $id): void {
        CustomizationOption::query()->Update(CustomizationOption::$table, ['deleted' => 'true'], 'customization_id', $id);
    }, 'choice');
    Gestionale::reset();

    return $dopo['items'] === [] && count($dopo['removed']) === 1;
});

check('obbligatoria aggiunta dopo all\'articolo: la riga che non la ha esce', function () {
    $dopo = prova(static function (): array {
        [$prodotto, $modello, $carrello] = articoloPersonalizzabile();
        Cart::add($carrello, ['product_id' => $prodotto]);
        collegaPersonalizzazione($modello, personalizzazioneDiProva(), true);

        return Cart::recalculate($carrello);
    });
    Gestionale::reset();

    return $dopo['items'] === [] && count($dopo['removed']) === 1;
});

check('funzionalità spenta: la riga personalizzata tiene il suo sovrapprezzo', function () {
    $dopo = prova(static function (): array {
        [$prodotto, $modello, $carrello] = articoloPersonalizzabile();
        $incisione = personalizzazioneDiProva(['surcharge' => '5.00']);
        collegaPersonalizzazione($modello, $incisione);
        Cart::add($carrello, ['product_id' => $prodotto, 'customization' => [$incisione => 'Marco']]);

        spegniFunzionalita(['customizations']);

        return Cart::recalculate($carrello);
    });
    Gestionale::reset();
    $riga = $dopo['items'][0] ?? [];

    return count($dopo['items']) === 1
        && (string) $riga['customization_surcharge'] === '5.00'
        && (string) $riga['unit_price'] === '25.00'
        && ($riga['customization'][0]['value'] ?? '') === 'Marco';
});

check('funzionalità spenta: i valori sono ignorati e un\'obbligatoria rifiuta', function () {
    $esito = prova(static function (): array {
        [$prodotto, $modello, $carrello] = articoloPersonalizzabile();
        $incisione = personalizzazioneDiProva(['surcharge' => '5.00']);
        collegaPersonalizzazione($modello, $incisione);
        spegniFunzionalita(['customizations']);

        $senza = Cart::add($carrello, ['product_id' => $prodotto, 'customization' => [$incisione => 'Marco']]);

        $obbligatoria = personalizzazioneDiProva();
        collegaPersonalizzazione($modello, $obbligatoria, true, 2);
        $chiave = '';

        try {
            Cart::add($carrello, ['product_id' => $prodotto]);
        } catch (UserError $e) {
            $chiave = $e->key();
        }

        return ['senza' => $senza['items'], 'chiave' => $chiave];
    });
    Gestionale::reset();
    $riga = $esito['senza'][0] ?? [];

    return count($esito['senza']) === 1
        && $riga['customization'] === []
        && (string) $riga['customization_surcharge'] === '0.00'
        && $esito['chiave'] === 'customization.unavailable';
});

check('l\'unione sa distinguere le personalizzazioni: uguali si sommano, diverse restano due', function () {
    $esito = prova(static function (): array {
        [$prodotto, $modello, $cliente] = articoloPersonalizzabile();
        $incisione = personalizzazioneDiProva(['surcharge' => '5.00']);
        collegaPersonalizzazione($modello, $incisione);
        $ospiteUguale = (int) Cart::open(['cart_token' => 'tok-'.uniqid()])['id'];
        $ospiteDiverso = (int) Cart::open(['cart_token' => 'tok-'.uniqid()])['id'];
        Cart::add($cliente, ['product_id' => $prodotto, 'customization' => [$incisione => 'Marco']]);
        Cart::add($ospiteUguale, ['product_id' => $prodotto, 'quantity' => 2, 'customization' => [$incisione => 'Marco']]);
        Cart::add($ospiteDiverso, ['product_id' => $prodotto, 'customization' => [$incisione => 'Luca']]);

        $uguale = Cart::merge($ospiteUguale, $cliente);
        $diverso = Cart::merge($ospiteDiverso, $cliente);

        return ['uguale' => $uguale['items'], 'diverso' => $diverso['items']];
    });
    Gestionale::reset();

    return count($esito['uguale']) === 1
        && (float) $esito['uguale'][0]['quantity'] === 3.0
        && count($esito['diverso']) === 2
        && (string) $esito['diverso'][0]['customization'][0]['value'] !== (string) $esito['diverso'][1]['customization'][0]['value'];
});

check('un articolo senza personalizzazioni entra come prima, con la lista vuota', function () {
    $esito = prova(static function (): array {
        [$prodotto, , $carrello] = articoloPersonalizzabile();

        return Cart::add($carrello, ['product_id' => $prodotto, 'quantity' => 2]);
    });
    Gestionale::reset();
    $riga = $esito['items'][0] ?? [];

    return (string) $riga['unit_price'] === '20.00'
        && $riga['customization'] === []
        && (string) $riga['customization_surcharge'] === '0.00';
});

// ---------------------------------------------------------------- multiprodotti

/** Una prova con le funzionalità dei multiprodotti accese; a fine prova si butta la cache. */
function provaConfezione(callable $corpo): mixed
{
    $esito = prova(static function () use ($corpo): mixed {
        accendiFunzionalita(['orders', 'customizations', 'bundles']);

        return $corpo();
    });
    Gestionale::reset();

    return $esito;
}

/** La chiave dell'errore che la chiamata alza, o '' se non ne alza. */
function chiaveErrore(callable $fn): string
{
    try {
        $fn();
    } catch (UserError $e) {
        return $e->key();
    }

    return '';
}

/** Il messaggio dell'errore che la chiamata alza, o '' se non ne alza. */
function messaggioErrore(callable $fn): string
{
    try {
        $fn();
    } catch (UserError $e) {
        return $e->getMessage();
    }

    return '';
}

/** Esegue la prova con la vendita senza giacenza accesa. */
function conBackorder(callable $prova): mixed
{
    Gestionale::feature('backorders');
    $stato = new ReflectionProperty(Gestionale::class, 'features');
    $prima = $stato->getValue();
    $stato->setValue(null, array_merge((array) $prima, ['backorders' => true]));

    try {
        return $prova();
    } finally {
        $stato->setValue(null, $prima);
    }
}

/**
 * Una confezione da 25,00 con un carrello vuoto. Con `fixed`: a×2 e b×1.
 * Con `mixed`: a×2 più il gruppo «Colore» (min 1, max 1) con c a +1,00 e d a +2,50.
 * Con `choice`: un gruppo «Scelta» (min 1, max 2) con c (+1,00), d (+2,50) ed e.
 *
 * @return array{bundle:int, model:int, cart:int, a:int, b:int, c:int, d:int, e:int, options:list<int>}
 */
function confezione(string $modo = 'mixed', float $scorta = 20.0): array
{
    $sku = substr((string) microtime(true), -6);
    [$a, $b, $c, $d, $e] = array_map(
        static fn (string $n): int => articoloConGiacenza($scorta, 'TST-BND-'.$n.$sku),
        ['A', 'B', 'C', 'D', 'E']
    );
    $componenti = match ($modo) {
        'fixed' => [['product_id' => $a, 'quantity' => 2], ['product_id' => $b, 'quantity' => 1]],
        'mixed' => [['product_id' => $a, 'quantity' => 2]],
        default => [],
    };
    $gruppi = match ($modo) {
        'mixed' => [['name' => 'Colore', 'min' => 1, 'max' => 1, 'options' => [['product_id' => $c, 'surcharge' => 1], ['product_id' => $d, 'surcharge' => 2.5]]]],
        'choice' => [['name' => 'Scelta', 'min' => 1, 'max' => 2, 'options' => [['product_id' => $c, 'surcharge' => 1], ['product_id' => $d, 'surcharge' => 2.5], ['product_id' => $e]]]],
        default => [],
    };
    $bundle = multiprodottoDiProva($modo, $componenti, $gruppi);
    Product::update(['price' => '25.00'], $bundle);
    $options = [];

    foreach (Bundles::forModel(modelloDi($bundle))['groups'] as $gruppo) {
        foreach ($gruppo['options'] as $opzione) {
            $options[] = $opzione['id'];
        }
    }

    return [
        'bundle' => $bundle,
        'model' => modelloDi($bundle),
        'cart' => (int) Cart::open(['cart_token' => 'tok-'.uniqid()])['id'],
        'a' => $a, 'b' => $b, 'c' => $c, 'd' => $d, 'e' => $e,
        'options' => $options,
    ];
}

/** Le quantità delle figlie di una madre, per prodotto. */
function pezziFiglie(array $riga): array
{
    $pezzi = [];

    foreach ($riga['children'] ?? [] as $figlia) {
        $pezzi[(int) $figlia['product_id']] = ($pezzi[(int) $figlia['product_id']] ?? 0.0) + (float) $figlia['quantity'];
    }

    return $pezzi;
}

check('multiprodotto fisso: una madre a prezzo e le figlie a zero con i pezzi giusti', function () {
    $x = provaConfezione(static function (): array {
        $s = confezione('fixed');
        $r = Cart::add($s['cart'], ['product_id' => $s['bundle'], 'quantity' => 3]);

        return $s + ['r' => $r, 'righe' => OrderItem::find(['order_id' => $s['cart'], 'deleted' => 'false'])];
    });
    $madre = $x['r']['items'][0] ?? [];
    $figlie = $madre['children'] ?? [];

    return count($x['r']['items']) === 1
        && count($figlie) === 2
        && (string) $madre['unit_price'] === '25.00' && (string) $madre['line_total'] === '75.00'
        && pezziFiglie($madre) === [$x['a'] => 6.0, $x['b'] => 3.0]
        && (string) $figlie[0]['unit_price'] === '0.00' && (string) $figlie[0]['line_total'] === '0.00'
        && (int) $figlie[0]['parent_item_id'] === (int) $madre['id']
        && (string) $x['r']['order']['products_total'] === '75.00'
        && count($x['righe']) === 3;
});

check('multiprodotto a scelta: il sovrapprezzo dell\'opzione e della personalizzazione si sommano e quello in ingresso si ignora', function () {
    $x = provaConfezione(static function (): array {
        $s = confezione('mixed');
        $r1 = Cart::add($s['cart'], ['product_id' => $s['bundle'], 'choices' => [$s['options'][1]], 'customization_surcharge' => '99.00']);
        $incisione = personalizzazioneDiProva(['surcharge' => '5.00']);
        collegaPersonalizzazione($s['model'], $incisione);
        $s2 = confezione('mixed');
        collegaPersonalizzazione($s2['model'], $incisione);
        $r2 = Cart::add($s2['cart'], ['product_id' => $s2['bundle'], 'choices' => [$s2['options'][1]], 'customization' => [$incisione => 'Marco']]);
        $fisso = confezione('fixed');

        return [
            'r1' => $r1, 'r2' => $r2,
            'fisso' => chiaveErrore(static fn () => Cart::add($fisso['cart'], ['product_id' => $fisso['bundle'], 'choices' => [1]])),
        ];
    });

    return (string) $x['r1']['items'][0]['unit_price'] === '27.50'
        && (string) $x['r2']['items'][0]['unit_price'] === '32.50'
        && (string) $x['r2']['items'][0]['customization_surcharge'] === '7.50'
        && $x['fisso'] === 'bundle.unknown_option';
});

check('multiprodotto: scelte uguali in ordine diverso fanno una riga, scelte diverse due', function () {
    $x = provaConfezione(static function (): array {
        $s = confezione('choice');
        [$c, $d, $e] = $s['options'];
        Cart::add($s['cart'], ['product_id' => $s['bundle'], 'choices' => [$c, $d]]);
        $stesse = Cart::add($s['cart'], ['product_id' => $s['bundle'], 'choices' => [(string) $d, $c]]);
        $diverse = Cart::add($s['cart'], ['product_id' => $s['bundle'], 'choices' => [$c, $e]]);

        return ['stesse' => $stesse, 'diverse' => $diverse];
    });

    return count($x['stesse']['items']) === 1
        && (float) $x['stesse']['items'][0]['quantity'] === 2.0
        && count($x['stesse']['items'][0]['children']) === 2
        && (float) $x['stesse']['items'][0]['children'][0]['quantity'] === 2.0
        && count($x['diverse']['items']) === 2;
});

check('multiprodotto: minimo e massimo delle scelte con il nome del gruppo, carrello vuoto', function () {
    $x = provaConfezione(static function (): array {
        $s = confezione('choice');
        $poche = messaggioErrore(static fn () => Cart::add($s['cart'], ['product_id' => $s['bundle'], 'choices' => []]));
        $troppe = messaggioErrore(static fn () => Cart::add($s['cart'], ['product_id' => $s['bundle'], 'choices' => $s['options']]));

        return ['poche' => $poche, 'troppe' => $troppe, 'righe' => OrderItem::find(['order_id' => $s['cart'], 'deleted' => 'false'])];
    });

    return str_contains($x['poche'], 'Scelta') && str_contains($x['troppe'], 'Scelta')
        && $x['poche'] !== $x['troppe'] && ($x['righe'] === [] || $x['righe'] === null);
});

check('multiprodotto: la giacenza si guarda sui componenti, con D60 passa, e fisso più opzione si sommano', function () {
    $x = provaConfezione(static function (): array {
        $s = confezione('fixed', 5.0);
        $sforo = Cart::add($s['cart'], ['product_id' => $s['bundle'], 'quantity' => 2]);
        $nome = messaggioErrore(static fn () => Cart::add($s['cart'], ['product_id' => $s['bundle'], 'quantity' => 3]));
        Product::update(['allow_backorder' => 'true'], $s['a']);
        $scoperto = conBackorder(static fn (): array => Cart::add($s['cart'], ['product_id' => $s['bundle'], 'quantity' => 3]));

        $m = confezione('mixed', 3.0);
        $una = Cart::add($m['cart'], ['product_id' => $m['bundle'], 'choices' => [$m['options'][0]]]);
        $m2 = confezione('mixed', 3.0);
        // a×2 più l'opzione che è lo stesso prodotto: due confezioni chiedono 4 pezzi.
        $gruppo = BundleGroupOption::find(['product_id' => $m2['c']], 1);
        BundleGroupOption::update(['product_id' => $m2['a']], (int) $gruppo['id']);
        $somma = chiaveErrore(static fn () => Cart::add($m2['cart'], ['product_id' => $m2['bundle'], 'quantity' => 2, 'choices' => [(int) $gruppo['id']]]));
        $singola = Cart::add($m2['cart'], ['product_id' => $m2['bundle'], 'quantity' => 1, 'choices' => [(int) $gruppo['id']]]);

        return ['sforo' => $sforo, 'nome' => $nome, 's' => $s, 'scoperto' => $scoperto, 'una' => $una, 'somma' => $somma, 'singola' => $singola];
    });

    return count($x['sforo']['items']) === 1
        && stripos($x['nome'], 'TST-BND-A') !== false
        && (float) $x['scoperto']['items'][0]['quantity'] === 5.0
        && count($x['una']['items']) === 1
        && $x['somma'] === 'cart.not_enough_stock'
        && count($x['singola']['items']) === 1;
});

check('multiprodotto: la quantità della madre riporta le figlie, zero le toglie, sulle figlie non si lavora', function () {
    $x = provaConfezione(static function (): array {
        $s = confezione('fixed');
        $r = Cart::add($s['cart'], ['product_id' => $s['bundle'], 'quantity' => 3]);
        $madre = (int) $r['items'][0]['id'];
        $figlia = (int) $r['items'][0]['children'][0]['id'];
        $due = Cart::setQuantity($s['cart'], $madre, 2);
        $setFiglia = chiaveErrore(static fn () => Cart::setQuantity($s['cart'], $figlia, 1));
        $remFiglia = chiaveErrore(static fn () => Cart::remove($s['cart'], $figlia));
        $zero = Cart::setQuantity($s['cart'], $madre, 0);
        $r2 = Cart::add($s['cart'], ['product_id' => $s['bundle']]);
        $rimossa = Cart::remove($s['cart'], (int) $r2['items'][0]['id']);

        return ['due' => $due, 'setFiglia' => $setFiglia, 'remFiglia' => $remFiglia, 'zero' => $zero, 'rimossa' => $rimossa,
            'righe' => OrderItem::find(['order_id' => $s['cart'], 'deleted' => 'false']), 'a' => $s['a'], 'b' => $s['b']];
    });

    return pezziFiglie($x['due']['items'][0]) === [$x['a'] => 4.0, $x['b'] => 2.0]
        && (string) $x['due']['items'][0]['line_total'] === '50.00'
        && $x['setFiglia'] === 'cart.child_line' && $x['remFiglia'] === 'cart.child_line'
        && $x['zero']['items'] === [] && $x['rimossa']['items'] === []
        && ($x['righe'] === [] || $x['righe'] === null);
});

check('multiprodotto: se l\'anagrafica cambia la madre esce con le figlie e il nome in removed', function () {
    $guasti = [
        'componente spento' => static fn (array $s) => Product::update(['active' => 'false'], $s['a']),
        'componente cancellato' => static fn (array $s) => Product::query()->Update(Product::$table, ['deleted' => 'true'], 'id', $s['a']),
        'opzione tolta' => static fn (array $s) => BundleGroupOption::delete($s['options'][0]),
        'minimo alzato' => static fn (array $s) => BundleGroup::update(['min_choices' => 2, 'max_choices' => 2], (int) BundleGroup::find(['product_model_id' => $s['model']], 1)['id']),
        'composizione svuotata' => static function (array $s): void {
            foreach (BundleComponent::find(['product_model_id' => $s['model']]) as $riga) {
                BundleComponent::delete((int) $riga['id']);
            }
        },
        'multiprodotto spento' => static fn (array $s) => Product::update(['active' => 'false'], $s['bundle']),
    ];
    $esiti = [];

    foreach ($guasti as $nome => $guasto) {
        $esiti[$nome] = provaConfezione(static function () use ($guasto): array {
            $s = confezione('mixed');
            Cart::add($s['cart'], ['product_id' => $s['bundle'], 'choices' => [$s['options'][0]]]);
            $guasto($s);
            $r = Cart::recalculate($s['cart']);

            return ['items' => $r['items'], 'removed' => $r['removed'], 'righe' => OrderItem::find(['order_id' => $s['cart'], 'deleted' => 'false'])];
        });
    }

    foreach ($esiti as $nome => $e) {
        if ($e['items'] !== [] || count($e['removed']) !== 1 || stripos($e['removed'][0], 'Confezione') === false
            || !($e['righe'] === [] || $e['righe'] === null)) {
            fwrite(STDERR, "  caso: {$nome}\n");

            return false;
        }
    }

    return true;
});

check('multiprodotto: sovrapprezzo dell\'opzione o prezzo cambiati riprezzano la madre', function () {
    $x = provaConfezione(static function (): array {
        $s = confezione('mixed');
        Cart::add($s['cart'], ['product_id' => $s['bundle'], 'choices' => [$s['options'][0]]]);
        BundleGroupOption::update(['surcharge' => '4.00'], $s['options'][0]);
        $opzione = Cart::recalculate($s['cart']);
        Product::update(['price' => '30.00'], $s['bundle']);
        $prezzo = Cart::recalculate($s['cart']);

        return ['opzione' => $opzione, 'prezzo' => $prezzo];
    });

    return (string) $x['opzione']['items'][0]['unit_price'] === '29.00'
        && (string) $x['prezzo']['items'][0]['unit_price'] === '34.00';
});

check('multiprodotto: con la funzionalità spenta non si aggiunge, ma quello nel carrello resta', function () {
    $x = provaConfezione(static function (): array {
        $s = confezione('fixed');
        Cart::add($s['cart'], ['product_id' => $s['bundle'], 'quantity' => 2]);
        spegniFunzionalita(['bundles']);
        $nuova = chiaveErrore(static fn () => Cart::add($s['cart'], ['product_id' => $s['bundle']]));

        return ['nuova' => $nuova, 'resta' => Cart::recalculate($s['cart'])];
    });
    $madre = $x['resta']['items'][0] ?? [];

    return $x['nuova'] === 'bundle.feature_off'
        && count($x['resta']['items']) === 1 && count($madre['children']) === 2
        && (string) $madre['unit_price'] === '25.00';
});

check('multiprodotto: l\'unione somma le confezioni uguali e porta le figlie delle diverse', function () {
    $x = provaConfezione(static function (): array {
        $s = confezione('mixed');
        [$c, $d] = $s['options'];
        $ospite = (int) Cart::open(['cart_token' => 'tok-'.uniqid()])['id'];
        Cart::add($ospite, ['product_id' => $s['bundle'], 'choices' => [$c]]);
        Cart::add($ospite, ['product_id' => $s['bundle'], 'choices' => [$d]]);
        Cart::add($s['cart'], ['product_id' => $s['bundle'], 'choices' => [$c]]);
        $unito = Cart::merge($ospite, $s['cart']);

        return ['unito' => $unito, 'ospite' => OrderItem::find(['order_id' => $ospite, 'deleted' => 'false']), 'tutte' => OrderItem::find(['order_id' => $s['cart'], 'deleted' => 'false'])];
    });
    $righe = $x['unito']['items'];

    return count($righe) === 2
        && (float) $righe[0]['quantity'] === 2.0 && (float) $righe[1]['quantity'] === 1.0
        && count($righe[0]['children']) === 2 && count($righe[1]['children']) === 2
        && count($x['tutte']) === 6
        && ($x['ospite'] === [] || $x['ospite'] === null);
});


check('multiprodotto: con la funzionalità spenta il sovrapprezzo dell\'opzione non sparisce al ricalcolo', function () {
    $x = provaConfezione(static function (): array {
        $s = confezione('mixed');
        Cart::add($s['cart'], ['product_id' => $s['bundle'], 'choices' => [$s['options'][1]]]);
        spegniFunzionalita(['bundles']);

        return ['resta' => Cart::recalculate($s['cart'])];
    });
    $madre = $x['resta']['items'][0] ?? [];

    return (string) ($madre['unit_price'] ?? '') === '27.50'
        && (string) ($madre['customization_surcharge'] ?? '') === '2.50'
        && count($madre['children'] ?? []) === 2;
});

check('multiprodotto: l\'unione taglia a confezioni intere, mai a mezze', function () {
    $x = provaConfezione(static function (): array {
        $s = confezione('fixed', 5.0);
        $ospite = (int) Cart::open(['cart_token' => 'tok-'.uniqid()])['id'];
        Cart::add($ospite, ['product_id' => $s['bundle'], 'quantity' => 2]);
        Cart::add($s['cart'], ['product_id' => $s['bundle'], 'quantity' => 1]);

        return ['unito' => Cart::merge($ospite, $s['cart'])] + $s;
    });
    $madre = $x['unito']['items'][0] ?? [];

    return (float) ($madre['quantity'] ?? 0) === 2.0
        && pezziFiglie($madre) === [$x['a'] => 4.0, $x['b'] => 2.0];
});

check('multiprodotto: con la funzionalità spenta l\'unione porta le figlie con la madre', function () {
    $x = provaConfezione(static function (): array {
        $s = confezione('fixed');
        $ospite = (int) Cart::open(['cart_token' => 'tok-'.uniqid()])['id'];
        Cart::add($ospite, ['product_id' => $s['bundle'], 'quantity' => 2]);
        Cart::add($s['cart'], ['product_id' => $s['bundle'], 'quantity' => 1]);
        spegniFunzionalita(['bundles']);

        return ['unito' => Cart::merge($ospite, $s['cart'])] + $s;
    });
    $madre = $x['unito']['items'][0] ?? [];

    return (float) ($madre['quantity'] ?? 0) === 3.0
        && pezziFiglie($madre) === [$x['a'] => 6.0, $x['b'] => 3.0];
});

summary();
