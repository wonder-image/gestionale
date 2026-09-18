<?php \Wonder\View\View::layout('backend.main'); ?>

<?php
    $readonly = (bool) ($READONLY ?? false);
    $areas = (array) ($AREAS ?? []);
    $docs = trim((string) ($DOCS_URL ?? ''));
    $e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>

<form method="POST" onsubmit="loadingSpinner()">
<div class="row g-3">

    <wi-card class="col-12">
        <div class="d-flex align-items-start justify-content-between gap-3 flex-wrap">
            <div class="flex-grow-1 min-w-0">
                <h3 class="mb-0"><?=$e((string) ($TITLE ?? 'Funzionalità'))?></h3>
                <div class="text-body-secondary small mt-1">
                    Il gestionale è predisposto al massimo: qui si sblocca solo ciò che serve.
                    Bloccare non cancella mai i dati.
                </div>
            </div>
            <?php if ($docs !== '') { ?>
            <a href="<?=$e($docs)?>" class="btn btn-outline-secondary" target="_blank" rel="noopener noreferrer">
                <i class="bi bi-question-circle"></i> Guida
            </a>
            <?php } ?>
        </div>
    </wi-card>

    <?php if (trim((string) ($MESSAGE ?? '')) !== '') { ?>
    <wi-card class="col-12">
        <div class="alert alert-success mb-0"><?=$e((string) $MESSAGE)?></div>
    </wi-card>
    <?php } ?>

    <?php if ($readonly) { ?>
    <wi-card class="col-12">
        <div class="alert alert-warning mb-0">Si modifica in locale e si pubblica con il deploy.</div>
    </wi-card>
    <?php } ?>

    <?php foreach ($areas as $area => $features) { ?>
    <wi-card class="col-12 col-lg-6">
        <div class="col-12">
            <h6 class="mb-0"><?=$e((string) $area)?></h6>
        </div>
        <?php foreach ($features as $feature) { ?>
        <div class="col-12">
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" role="switch"
                       id="feature-<?=$e($feature['key'])?>"
                       name="features[<?=$e($feature['key'])?>]"
                       value="true"
                       <?=$feature['enabled'] ? 'checked' : ''?>
                       <?=$readonly || !$feature['available'] ? 'disabled' : ''?>>
                <label class="form-check-label" for="feature-<?=$e($feature['key'])?>">
                    <span class="fw-semibold"><?=$e($feature['name'])?></span>
                    <?php if (!$feature['available']) { ?>
                    <span class="badge text-bg-secondary ms-1">richiede il modulo <?=$e($feature['module'])?></span>
                    <?php } ?>
                    <span class="d-block text-body-secondary small"><?=$e($feature['description'])?></span>
                    <?php if ($feature['requires'] !== []) { ?>
                    <span class="d-block text-body-secondary small">Richiede: <?=$e(implode(', ', $feature['requires']))?></span>
                    <?php } ?>
                </label>
            </div>
        </div>
        <?php } ?>
    </wi-card>
    <?php } ?>

    <?php if (!$readonly) { ?>
    <wi-card class="col-12">
        <div class="col-12">
            <?php
                echo function_exists('submit')
                    ? submit('Salva', 'upload')
                    : '<button type="submit" class="btn btn-dark">Salva</button>';
            ?>
        </div>
    </wi-card>
    <?php } ?>

</div>
</form>

<?php \Wonder\View\View::end(); ?>
