<?php \Wonder\View\View::layout('backend.main'); ?>

<?php
    use Wonder\Backend\Support\ResourceFormLayoutRenderer;

    $readonly = (bool) ($READONLY ?? false);
    $docs = trim((string) ($DOCS_URL ?? ''));
    $message = trim((string) ($MESSAGE ?? ''));
    $e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>

<div class="row g-3">
    <wi-card class="col-12">
        <div class="d-flex align-items-start justify-content-between gap-3 flex-wrap">
            <div class="flex-grow-1 min-w-0">
                <h3 class="mb-0"><?=$e((string) ($TITLE ?? ''))?></h3>
                <div class="text-body-secondary small mt-1"><?=$e((string) ($SUBTITLE ?? ''))?></div>
            </div>
            <?php if ($docs !== '') { ?>
            <a href="<?=$e($docs)?>" class="btn btn-outline-secondary" target="_blank" rel="noopener noreferrer">
                <i class="bi bi-question-circle"></i> Guida
            </a>
            <?php } ?>
        </div>
    </wi-card>

    <?php if ($message !== '') { ?>
    <wi-card class="col-12">
        <div class="alert alert-success mb-0"><?=$e($message)?></div>
    </wi-card>
    <?php } ?>
</div>

<?php
    $footer = $readonly
        ? '<div class="col-12"><wi-card class="col-12"><div class="alert alert-warning mb-0">'
            .$e((string) ($READONLY_NOTICE ?? '')).'</div></wi-card></div>'
        : '<div class="col-12"><wi-card class="col-12"><div class="col-12">'
            .(function_exists('submit') ? submit('Salva', 'upload') : '<button type="submit" class="btn btn-dark">Salva</button>')
            .'</div></wi-card></div>';

    echo ResourceFormLayoutRenderer::render($FORM_LAYOUT, [
        'id' => 'features-form',
        'method' => 'POST',
        'action' => $readonly ? '' : '',
        'footer' => $footer,
    ]);
?>

<?php \Wonder\View\View::end(); ?>
