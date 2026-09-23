<?php
/**
 * L'email dei prodotti sotto scorta minima.
 *
 * Per cambiarla, copia questo file in
 * `custom/modules/gestionale/view/emails/low-stock.php` e riscrivilo: il
 * gestionale usa la copia del sito se c'è.
 *
 * Variabili:
 * - `$items`: le righe, ognuna con `product_id`, `article`, `option` (vuota
 *   per gli articoli senza varianti), `sku`, `threshold`, `available`;
 * - `$count`: quante sono;
 * - `$url`: l'indirizzo completo dell'elenco Giacenze, già filtrato sui
 *   prodotti sotto scorta;
 * - `$e($testo)`: il testo pronto per stare nell'HTML — usalo su tutto quello
 *   che viene dal catalogo;
 * - `$qty($numero)`: i pezzi come li legge una persona ("3", "2,5").
 *
 * @var list<array{product_id: int, article: string, option: string, sku: string, threshold: float, available: float}> $items
 * @var int $count
 * @var string $url
 * @var callable(mixed): string $e
 * @var callable(mixed): string $qty
 */
?>
<p><?= $count === 1 ? 'Questo prodotto è sceso' : 'Questi prodotti sono scesi' ?> sotto la scorta minima.</p>
<table cellpadding="6" cellspacing="0" border="0" style="width: 100%; border-collapse: collapse;">
    <?php foreach ($items as $item) { ?>
    <tr style="border-bottom: 1px solid #e5e5e5;">
        <td>
            <b><?= $e($item['article']) ?></b><?php if ($item['option'] !== '') { ?> — <?= $e($item['option']) ?><?php } ?>
            <?php if ($item['sku'] !== '') { ?><br><small style="color: #777;"><?= $e($item['sku']) ?></small><?php } ?>
        </td>
        <td style="text-align: right; white-space: nowrap;">
            disponibili <b><?= $e($qty($item['available'])) ?></b><br>
            <small style="color: #777;">scorta minima <?= $e($qty($item['threshold'])) ?></small>
        </td>
    </tr>
    <?php } ?>
</table>
<p><a href="<?= $e($url) ?>">Apri le giacenze sotto scorta</a></p>
<p style="color: #777;"><small>Per ogni prodotto l'avviso arriva una volta sola: torna solo se il prodotto risale sopra la soglia e poi ci ricade.</small></p>
