<?php
/**
 * L'email al cliente: titolo, una frase, il riepilogo.
 *
 * Il sito la sostituisce copiandola in `custom/modules/gestionale/view/`.
 *
 * @var string $title
 * @var string $intro
 * @var list<string> $details
 * @var string $instructions
 * @var string $bank_title
 * @var list<array{label: string, value: string}> $bank
 * @var array<string, mixed> $order
 * @var list<array<string, mixed>> $items
 * @var string $url
 * @var string $order_url
 * @var string $order_button
 * @var string $account_url
 * @var string $account_title
 * @var string $account_button
 * @var callable(mixed): string $e
 * @var callable(mixed): string $money
 * @var callable(mixed): string $qty
 */
?>
<h2><?= $e($title) ?></h2>
<p><?= $e($intro) ?></p>
<?php foreach ($details as $line) { ?>
    <p><?= $e($line) ?></p>
<?php } ?>
<?php if (trim($instructions) !== '') { ?>
    <p><strong><?= $e($instructions) ?></strong></p>
<?php } ?>
<?php if (($bank ?? []) !== []) { ?>
    <p><strong><?= $e($bank_title) ?></strong></p>
    <table cellpadding="4" cellspacing="0" border="0">
        <?php foreach ($bank as $line) { ?>
            <tr>
                <td><?= $e($line['label']) ?></td>
                <td><strong><?= $e($line['value']) ?></strong></td>
            </tr>
        <?php } ?>
    </table>
<?php } ?>
<?php if ($items !== []) { ?>
    <table cellpadding="6" cellspacing="0" border="0">
        <?php foreach ($items as $item) { ?>
            <?php if ((int) ($item['parent_item_id'] ?? 0) > 0) { ?>
                <tr>
                    <td style="padding-left: 24px"><?= $e(((int) ($item['bundle_option_id'] ?? 0) > 0 ? 'Scelta: ' : '').($item['name'] ?? '')) ?></td>
                    <td></td>
                    <td></td>
                </tr>
                <?php continue; ?>
            <?php } ?>
            <tr>
                <td>
                    <?= $e($item['name'] ?? '') ?>
                    <?php foreach (Wonder\Plugin\Gestionale\Support\Catalog\Customizations::lines($item) as $line) { ?>
                        <div class="text-muted small"><?= $e($line) ?></div>
                    <?php } ?>
                </td>
                <td align="right"><?= $e($qty($item['quantity'] ?? 0)) ?></td>
                <td align="right"><?= $e($money($item['line_total'] ?? 0)) ?> &euro;</td>
            </tr>
        <?php } ?>
    </table>
<?php } ?>
<p><strong>Totale: <?= $e($money($order['total'] ?? 0)) ?> &euro;</strong></p>
<?php if (trim($order_url ?? '') !== '') { ?>
    <p><a href="<?= $e($order_url) ?>" style="display: inline-block; padding: 10px 16px; background: #111; color: #fff; text-decoration: none; border-radius: 4px"><?= $e($order_button) ?></a></p>
<?php } ?>
<?php if (trim($account_url ?? '') !== '') { ?>
    <p><strong><?= $e($account_title) ?></strong></p>
    <p><a href="<?= $e($account_url) ?>" style="display: inline-block; padding: 10px 16px; background: #111; color: #fff; text-decoration: none; border-radius: 4px"><?= $e($account_button) ?></a></p>
<?php } ?>
<?php if (trim($url) !== '') { ?>
    <p><a href="<?= $e($url) ?>"><?= $e($url) ?></a></p>
<?php } ?>
