<?php
/**
 * L'email di un abbonamento al cliente: titolo, una frase, le date, il link
 * all'area cliente.
 *
 * Il sito la sostituisce copiandola in `custom/modules/gestionale/view/`.
 *
 * @var string $title
 * @var string $intro
 * @var list<string> $details
 * @var string $url
 * @var string $button
 * @var callable(mixed): string $e
 */
?>
<h2><?= $e($title) ?></h2>
<p><?= $e($intro) ?></p>
<?php foreach ($details as $line) { ?>
    <p><?= $e($line) ?></p>
<?php } ?>
<?php if ($url !== '' && $button !== '') { ?>
    <p><a href="<?= $e($url) ?>"><?= $e($button) ?></a></p>
<?php } ?>
