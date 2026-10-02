<?php

/** Le cose da comprare e da vendere che servono alle prove: articoli, ordini, resi. */

use Wonder\Plugin\Gestionale\Gestionale;
use Wonder\Plugin\Gestionale\Models\Catalog\Customization;
use Wonder\Plugin\Gestionale\Models\Catalog\CustomizationOption;
use Wonder\Plugin\Gestionale\Models\Catalog\Product;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModel;
use Wonder\Plugin\Gestionale\Models\Catalog\ProductModelCustomization;
use Wonder\Plugin\Gestionale\Models\Sales\Order;
use Wonder\Plugin\Gestionale\Models\Sales\SalesReturn;
use Wonder\Plugin\Gestionale\Models\System\Feature;
use Wonder\Plugin\Gestionale\Support\Catalog\Code;
use Wonder\Plugin\Gestionale\Support\Catalog\Skeleton;
use Wonder\Plugin\Gestionale\Support\Catalog\Slug;
use Wonder\Plugin\Gestionale\Support\Codes;
use Wonder\Plugin\Gestionale\Support\Stock\Stock;

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

/** Un reso di prova sull'ordine dato: serve un id vero per i movimenti. */
function resoDiProva(int $ordine, int $sede): int
{
    $reso = SalesReturn::create([
        'code' => Code::make(SalesReturn::class, Codes::SALES_RETURN),
        'number' => 'RES/'.date('Y').'/'.substr((string) microtime(true), -6),
        'order_id' => $ordine,
        'location_id' => $sede,
        'status' => 'received',
        'received_at' => date('Y-m-d H:i:s'),
    ]);

    return (int) ($reso->insert_id ?? 0);
}

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

/**
 * Come {@see accendiFunzionalita()}, al contrario: spegne le funzionalità
 * date. Anche questa vive dentro la transazione della prova.
 *
 * @param list<string> $chiavi
 */
function spegniFunzionalita(array $chiavi): void
{
    foreach ($chiavi as $chiave) {
        $riga = Feature::find(['feature_key' => $chiave, 'deleted' => 'false'], 1);

        if (is_array($riga) && isset($riga['id'])) {
            Feature::update(['enabled' => 'false'], (int) $riga['id']);
        } else {
            Feature::create(['feature_key' => $chiave, 'enabled' => 'false']);
        }
    }

    Gestionale::reset();
}

/** Il modello a cui appartiene l'articolo dato. */
function modelloDi(int $prodotto): int
{
    $riga = Product::find(['id' => $prodotto], 1);

    return (int) ($riga['product_model_id'] ?? 0);
}

/**
 * Una personalizzazione attiva: un testo da 20 caratteri, senza sovrapprezzo.
 * `$valori` sovrascrive i campi; `$opzioni` ([['label' => …, 'surcharge' => …]])
 * crea le opzioni, nell'ordine dato.
 *
 * @param array<string, mixed>                       $valori
 * @param list<array{label:string, surcharge?:mixed}> $opzioni
 */
function personalizzazioneDiProva(array $valori = [], array $opzioni = []): int
{
    $personalizzazione = Customization::create($valori + [
        'code' => Code::make(Customization::class, Codes::CUSTOMIZATION),
        'name' => 'Prova '.uniqid(),
        'label' => '',
        'help_text' => '',
        'kind' => $opzioni === [] ? 'text' : 'choice',
        'max_length' => 20,
        'decimals' => 0,
        'surcharge' => '0.00',
        'active' => 'true',
        'position' => 1,
    ]);
    $id = (int) ($personalizzazione->insert_id ?? 0);

    foreach (array_values($opzioni) as $i => $opzione) {
        CustomizationOption::create([
            'customization_id' => $id,
            'label' => $opzione['label'],
            'surcharge' => number_format((float) ($opzione['surcharge'] ?? 0), 2, '.', ''),
            'position' => $i + 1,
        ]);
    }

    return $id;
}

/** Collega una personalizzazione a un modello; ridà l'id del collegamento. */
function collegaPersonalizzazione(int $modello, int $personalizzazione, bool $obbligatoria = false, int $posizione = 1, ?string $sovrapprezzo = null): int
{
    $collegamento = ProductModelCustomization::create(($sovrapprezzo === null ? [] : ['surcharge' => $sovrapprezzo]) + [
        'product_model_id' => $modello,
        'customization_id' => $personalizzazione,
        'is_required' => $obbligatoria ? 'true' : 'false',
        'position' => $posizione,
    ]);

    return (int) ($collegamento->insert_id ?? 0);
}
