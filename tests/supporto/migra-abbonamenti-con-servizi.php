<?php
/**
 * php tests/supporto/migra-abbonamenti-con-servizi.php [root del sito] [--togli-vecchie]
 *
 * Porta i dati di prima di G10b nelle tabelle nuove, una volta, dopo
 * `php forge update --local`:
 * - le personalizzazioni degli articoli in `gst_customization_links`, se è vuota;
 * - un abbonamento senza righe col suo `plan_price_id` diventa una riga
 *   `active` con gli importi dell'abbonamento;
 * - i cambi di piano in `gst_subscription_item_changes` (`changed`), se è vuota.
 *
 * Si può rilanciare: quello già fatto non si rifà.
 *
 * Con `--togli-vecchie` toglie poi `gst_product_model_customizations` e
 * `gst_subscription_plan_changes`, quando la copia è fatta: `forge update`
 * non toglie le tabelle, e le loro chiavi esterne impedirebbero di eliminare
 * gli articoli e gli abbonamenti che nominano. Senza, ricorda solo di farlo.
 */
declare(strict_types=1);

$argomenti = array_slice($argv, 1);
$togliVecchie = in_array('--togli-vecchie', $argomenti, true);
$root = array_values(array_filter($argomenti, static fn (string $a): bool => !str_starts_with($a, '--')))[0] ?? null;

define('SITE', $root ?? (getenv('WI_TEST_SITE') ?: '/Users/andreamarinoni/Developer/boilerplates/ecommerce-site'));

chdir(SITE);
$GLOBALS['ROOT'] = SITE;
require SITE.'/vendor/autoload.php';
require SITE.'/vendor/wonder-image/app/wonder-image.php';

use Wonder\Plugin\Gestionale\Models\Catalog\CustomizationLink;
use Wonder\Plugin\Gestionale\Models\Subscriptions\SubscriptionItem;
use Wonder\Plugin\Gestionale\Models\Subscriptions\SubscriptionItemChange;

/** @return list<array<string, mixed>> */
function righe(string $sql): array
{
    $result = SubscriptionItem::query()->mysqli->query($sql);

    return $result instanceof mysqli_result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

function tabella(string $nome): bool
{
    return righe("SHOW TABLES LIKE '".$nome."'") !== [];
}

function colonna(string $tabella, string $nome): bool
{
    return righe('SHOW COLUMNS FROM `'.$tabella."` LIKE '".$nome."'") !== [];
}

function vuota(string $tabella): bool
{
    return (int) (righe('SELECT COUNT(*) AS n FROM `'.$tabella.'`')[0]['n'] ?? 0) === 0;
}

// 1. Personalizzazioni degli articoli.
if (!tabella('gst_product_model_customizations')) {
    echo "Personalizzazioni: nessuna tabella vecchia.\n";
} elseif (!vuota('gst_customization_links')) {
    echo "Personalizzazioni: gst_customization_links ha già righe, salto.\n";
} else {
    $copiate = 0;

    foreach (righe("SELECT * FROM `gst_product_model_customizations` WHERE `deleted` = 'false' ORDER BY `id`") as $riga) {
        CustomizationLink::create([
            'product_model_id' => (int) $riga['product_model_id'],
            'customization_id' => (int) $riga['customization_id'],
            'is_required' => (string) $riga['is_required'],
            'surcharge' => $riga['surcharge'] ?? '',
            'position' => (int) $riga['position'],
        ]);
        $copiate++;
    }

    echo "Personalizzazioni: {$copiate} legami copiati.\n";
}

// 2. Una riga per ogni abbonamento che non ne ha.
if (!colonna('gst_subscriptions', 'plan_price_id')) {
    echo "Righe: gst_subscriptions non ha più plan_price_id, salto.\n";
} else {
    $create = 0;
    $abbonamenti = righe(
        "SELECT s.* FROM `gst_subscriptions` s WHERE s.`deleted` = 'false' AND s.`plan_price_id` > 0
        AND NOT EXISTS (SELECT 1 FROM `gst_subscription_items` i WHERE i.`subscription_id` = s.`id` AND i.`deleted` = 'false')
        ORDER BY s.`id`"
    );

    foreach ($abbonamenti as $abbonamento) {
        $subtotale = (float) $abbonamento['subtotal'];
        SubscriptionItem::create([
            'subscription_id' => (int) $abbonamento['id'],
            'plan_price_id' => (int) $abbonamento['plan_price_id'],
            'parent_item_id' => 0,
            'quantity' => 1,
            'status' => in_array((string) $abbonamento['status'], ['cancelled'], true) ? 'ended' : 'active',
            'customization' => '',
            'unit_price' => number_format($subtotale, 2, '.', ''),
            'customization_total' => '0.00',
            'subtotal' => number_format($subtotale, 2, '.', ''),
            'tax_total' => number_format((float) $abbonamento['tax_total'], 2, '.', ''),
            'total' => number_format((float) $abbonamento['total'], 2, '.', ''),
            'tax_id' => (int) $abbonamento['tax_id'],
            'tax_rate' => number_format((float) $abbonamento['tax_rate'], 2, '.', ''),
            'starts_at' => (string) ($abbonamento['started_at'] ?? ''),
            'commitment_ends_at' => (string) ($abbonamento['commitment_ends_at'] ?? ''),
            'ends_at' => (string) ($abbonamento['ended_at'] ?? ''),
            'provider_reference' => '',
            'provider_price' => '',
            'position' => 1,
        ]);
        $create++;
    }

    echo "Righe: {$create} abbonamenti con la loro riga.\n";
}

// 3. Storia dei cambi di piano.
if (!tabella('gst_subscription_plan_changes')) {
    echo "Storia: nessuna tabella vecchia.\n";
} elseif (!vuota('gst_subscription_item_changes')) {
    echo "Storia: gst_subscription_item_changes ha già righe, salto.\n";
} else {
    $copiati = 0;

    foreach (righe("SELECT * FROM `gst_subscription_plan_changes` WHERE `deleted` = 'false' ORDER BY `id`") as $cambio) {
        $riga = righe('SELECT `id` FROM `gst_subscription_items` WHERE `subscription_id` = '.(int) $cambio['subscription_id']." AND `deleted` = 'false' ORDER BY `position`, `id` LIMIT 1");
        SubscriptionItemChange::create([
            'subscription_id' => (int) $cambio['subscription_id'],
            'item_id' => (int) ($riga[0]['id'] ?? 0),
            'action' => 'changed',
            'old_plan_price_id' => (int) $cambio['old_plan_price_id'],
            'new_plan_price_id' => (int) $cambio['new_plan_price_id'],
            'billing' => 'next_period',
            'amount' => '0.00',
            'credit_left' => '0.00',
            'effective_at' => (string) $cambio['changed_at'],
            'user_id' => (int) $cambio['user_id'],
            'source' => (string) $cambio['source'],
            'changed_at' => (string) $cambio['changed_at'],
            'note' => '',
        ]);
        $copiati++;
    }

    echo "Storia: {$copiati} cambi di piano copiati.\n";
}

// 4. Le tabelle vecchie, quando la copia è fatta.
foreach (['gst_product_model_customizations' => 'gst_customization_links', 'gst_subscription_plan_changes' => 'gst_subscription_item_changes'] as $vecchia => $nuova) {
    if (!tabella($vecchia)) {
        continue;
    }

    $daCopiare = (int) (righe('SELECT COUNT(*) AS n FROM `'.$vecchia."` WHERE `deleted` = 'false'")[0]['n'] ?? 0);

    if ($daCopiare > 0 && vuota($nuova)) {
        echo "{$vecchia}: la copia non è fatta, resta.\n";
    } elseif (!$togliVecchie) {
        echo "{$vecchia}: copiata, si toglie con --togli-vecchie.\n";
    } else {
        SubscriptionItem::query()->mysqli->query('DROP TABLE `'.$vecchia.'`');
        echo "{$vecchia}: tolta.\n";
    }
}
