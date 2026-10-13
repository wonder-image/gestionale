<?php

/**
 * La scheda di un carico, scarico o inventario confermato o annullato
 * (G2b-bis 4.2). Nell'inventario le colonne sono Attesi · Contati · Differenza.
 *
 * Si legge e basta: *Duplica* e *Annulla* stanno nell'intestazione e inviano
 * i form nascosti di `hiddenForms()`. Il badge *Annullato* sta nel riquadro,
 * perché il layout escapa il titolo.
 *
 * @var array $ITEM
 */

use Wonder\Plugin\Gestionale\Support\Stock\StockDocumentSheet;
use Wonder\View\View;

/** @var class-string<\Wonder\Plugin\Gestionale\Resources\Stock\StockDocumentResource> $resource */
$resource = $RESOURCE_CLASS;
$document = (array) ($ITEM ?? []);
$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

// Una bozza si modifica: un link vecchio porta alla sua pagina.
if ((string) ($document['status'] ?? '') === 'draft') {
    header('Location: '.$resource::editUrl((int) ($document['id'] ?? 0)));
    exit();
}

$sheet = StockDocumentSheet::for($document);
$counting = (string) ($document['type'] ?? '') === 'stocktake';
$numeric = ' style="font-variant-numeric: tabular-nums"';
$tones = ['up' => ' text-success', 'down' => ' text-danger', '' => ''];
$number = html_entity_decode((string) ($document['number'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
$info = array_values(array_filter([$sheet['supplier'], $sheet['location'], $sheet['reason'], $sheet['note']], static fn (string $value): bool => $value !== ''));
$costs = $sheet['costs'];

View::layout('backend.show', ['TITLE' => $resource::singular().' '.$number]);
?>
<div class="row g-3">
    <wi-card class="col-12">
        <div class="col-12">
            <?php if ((string) ($document['status'] ?? '') === 'cancelled') { ?>
            <span class="badge text-bg-danger mb-2">Annullato</span>
            <?php } ?>
            <?php if ($info !== []) { ?>
            <p class="mb-2"><?=implode(' · ', array_map($e, $info))?></p>
            <?php } ?>
            <?php foreach ([$sheet['confirmed'], $sheet['cancelled']] as $line) { ?>
            <?php if ($line !== '') { ?>
            <div class="small text-body-secondary"><?=$e($line)?></div>
            <?php } ?>
            <?php } ?>
        </div>
    </wi-card>
    <wi-card class="col-12">
        <div class="col-12 table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th>Articolo</th>
                        <?php if ($counting) { ?>
                        <th class="text-end">Attesi</th>
                        <th class="text-end">Contati</th>
                        <th class="text-end">Differenza</th>
                        <?php } else { ?>
                        <th class="text-end">Pezzi</th>
                        <?php if ($costs) { ?>
                        <th class="text-end">Costo</th>
                        <th class="text-end">Totale</th>
                        <?php } ?>
                        <?php } ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sheet['lines'] as $line) { ?>
                    <tr>
                        <td>
                            <?=$e($line['label'])?>
                            <?php if ($line['sku'] !== '') { ?>
                            <div class="small text-body-secondary"><?=$e($line['sku'])?></div>
                            <?php } ?>
                            <?php if (($line['source'] ?? '') !== '') { ?>
                            <div class="small text-body-secondary"><?=$e($line['source'])?></div>
                            <?php } ?>
                        </td>
                        <?php if ($counting) { ?>
                        <td class="text-end"<?=$numeric?>><?=$e($line['expected'])?></td>
                        <td class="text-end"<?=$numeric?>><?=$e($line['quantity'])?></td>
                        <td class="text-end<?=$tones[$line['tone']] ?? ''?>"<?=$numeric?>><?=$e($line['difference'])?></td>
                        <?php } else { ?>
                        <td class="text-end"<?=$numeric?>><?=$e($line['quantity'])?></td>
                        <?php if ($costs) { ?>
                        <td class="text-end"<?=$numeric?>><?=$e($line['cost'])?></td>
                        <td class="text-end"<?=$numeric?>><?=$e($line['line_total'])?></td>
                        <?php } ?>
                        <?php } ?>
                    </tr>
                    <?php } ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="<?=$counting ? 4 : ($costs ? 4 : 2)?>" class="text-end text-body-secondary"><?=$e($sheet['total'])?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </wi-card>
</div>
<?=$resource::hiddenForms($document)?>
<?php View::end(); ?>
