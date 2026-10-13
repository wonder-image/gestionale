<?php

/**
 * La scheda di un lotto (G3.20): dati, pezzi per sede, ultimi movimenti e i
 * clienti che l'hanno ricevuto, con *Scarica i clienti* per un richiamo.
 * Si legge e basta: *Modifica* (scadenza e nota) sta nell'intestazione.
 *
 * @var array $ITEM
 */

use Wonder\Plugin\Gestionale\Support\Stock\BatchSheet;
use Wonder\View\View;

$batch = (array) ($ITEM ?? []);
$sheet = BatchSheet::for($batch);
$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$numeric = ' style="font-variant-numeric: tabular-nums"';

View::layout('backend.show', ['TITLE' => 'Lotto '.$sheet['number']]);
?>
<div class="row g-3">
    <wi-card class="col-12">
        <div class="col-12">
            <dl class="row mb-0">
                <dt class="col-sm-3">Versione</dt>
                <dd class="col-sm-9"><a href="<?=$e($sheet['product_url'])?>"><?=$e($sheet['product'])?></a></dd>
                <dt class="col-sm-3">Scadenza</dt>
                <dd class="col-sm-9"><?=$sheet['expiry']?></dd>
                <?php if ($sheet['supplier'] !== '') { ?>
                <dt class="col-sm-3">Fornitore</dt>
                <dd class="col-sm-9"><?=$e($sheet['supplier'])?></dd>
                <?php } ?>
                <dt class="col-sm-3">Pezzi</dt>
                <dd class="col-sm-9"<?=$numeric?>><?=$e($sheet['total'])?></dd>
                <?php if ($sheet['note'] !== '') { ?>
                <dt class="col-sm-3">Nota</dt>
                <dd class="col-sm-9"><?=nl2br($e($sheet['note']))?></dd>
                <?php } ?>
            </dl>
        </div>
    </wi-card>

    <?php if ($sheet['locations'] !== []) { ?>
    <wi-card class="col-12 col-lg-4">
        <div class="col-12">
            <h5 class="mb-3">Pezzi per sede</h5>
            <table class="table table-sm mb-0">
                <tbody>
                    <?php foreach ($sheet['locations'] as $location) { ?>
                    <tr>
                        <td><?=$e($location['label'])?></td>
                        <td class="text-end"<?=$numeric?>><?=$e($location['pieces'])?></td>
                    </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>
    </wi-card>
    <?php } ?>

    <wi-card class="col-12">
        <div class="col-12">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                <h5 class="mb-0">Clienti che l'hanno ricevuto</h5>
                <?php if ($sheet['customers'] !== []) { ?>
                <a class="btn btn-sm btn-outline-dark" href="<?=$e($sheet['customers_url'])?>"><i class="bi bi-download me-1" aria-hidden="true"></i>Scarica i clienti</a>
                <?php } ?>
            </div>
            <?php if ($sheet['customers'] === []) { ?>
            <p class="text-body-secondary mb-0">Nessun ordine ha ricevuto pezzi di questo lotto (al netto di annulli e resi).</p>
            <?php } else { ?>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Ordine</th>
                            <th class="d-none d-md-table-cell">Data</th>
                            <th>Cliente</th>
                            <th class="d-none d-md-table-cell">Email</th>
                            <th class="d-none d-md-table-cell">Telefono</th>
                            <th class="text-end">Pezzi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sheet['customers'] as $customer) { ?>
                        <tr>
                            <td><a href="<?=$e($customer['url'])?>"><?=$e($customer['order_number'] !== '' ? $customer['order_number'] : '#'.$customer['order_id'])?></a></td>
                            <td class="d-none d-md-table-cell text-nowrap"><?=$e($customer['date'])?></td>
                            <td><?=$e($customer['customer'])?></td>
                            <td class="d-none d-md-table-cell"><?=$e($customer['email'])?></td>
                            <td class="d-none d-md-table-cell"><?=$e($customer['phone'])?></td>
                            <td class="text-end"<?=$numeric?>><?=$e($customer['pieces'])?></td>
                        </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
            <?php } ?>
        </div>
    </wi-card>

    <wi-card class="col-12">
        <div class="col-12">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                <h5 class="mb-0">Movimenti del lotto</h5>
                <a class="small" href="<?=$e($sheet['movements_url'])?>">Tutti i movimenti della versione</a>
            </div>
            <?php if ($sheet['movements'] === []) { ?>
            <p class="text-body-secondary mb-0">Nessun movimento.</p>
            <?php } else { ?>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Quando</th>
                            <th>Tipo</th>
                            <th class="text-end">Pezzi</th>
                            <th style="width: 3rem"><span class="visually-hidden">Apri</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sheet['movements'] as $movement) { ?>
                        <tr>
                            <td class="text-nowrap"><?=$e($movement['date'])?></td>
                            <td><?=$movement['type']?></td>
                            <td class="text-end"<?=$numeric?>><?=$e($movement['quantity'])?></td>
                            <td class="text-end"><a href="<?=$e($movement['url'])?>" aria-label="Apri il movimento"><i class="bi bi-chevron-right" aria-hidden="true"></i></a></td>
                        </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
            <?php if (count($sheet['movements']) >= BatchSheet::MOVEMENTS) { ?>
            <p class="small text-body-secondary mt-2 mb-0">Qui gli ultimi <?=BatchSheet::MOVEMENTS?>: gli altri nei Movimenti.</p>
            <?php } ?>
            <?php } ?>
        </div>
    </wi-card>
</div>
<?php View::end(); ?>
