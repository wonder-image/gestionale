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
use Wonder\Plugin\Gestionale\Models\Catalog\Customization;
use Wonder\Plugin\Gestionale\Models\Catalog\CustomizationOption;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModelCustomization;
use Wonder\Plugin\Gestionale\Models\Sales\OrderItem;
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

summary();
