<?php
/**
 * L'email al commerciante: la stessa sostanza, senza convenevoli.
 *
 * @var string $title
 * @var string $intro
 * @var array<string, mixed> $order
 * @var list<array<string, mixed>> $items
 * @var string $url
 * @var callable(mixed): string $e
 * @var callable(mixed): string $money
 * @var callable(mixed): string $qty
 */
?>
<h2><?= $e($title) ?></h2>
<p><?= $e($intro) ?></p>
<?php if ($items !== []) { ?>
    <table cellpadding="6" cellspacing="0" border="0">
        <?php foreach ($items as $item) { ?>
            <?php if ((int) ($item['parent_item_id'] ?? 0) > 0) { ?>
                <tr>
                    <td><?= $e($item['sku'] ?? '') ?></td>
                    <td style="padding-left: 24px"><?= $e(((int) ($item['bundle_option_id'] ?? 0) > 0 ? 'Scelta: ' : '').($item['name'] ?? '')) ?></td>
                    <td></td>
                    <td></td>
                </tr>
                <?php continue; ?>
            <?php } ?>
            <tr>
                <td><?= $e($item['sku'] ?? '') ?></td>
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
<?php if (trim($url) !== '') { ?>
    <p><a href="<?= $e($url) ?>"><?= $e($url) ?></a></p>
<?php } ?>
