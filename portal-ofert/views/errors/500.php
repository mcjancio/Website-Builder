<?php
$title = __('errors.500.title');
$noindex = true;
?>
<section class="container section error-page">
    <p class="error-page__code" aria-hidden="true">500</p>
    <h1><?= e(__('errors.500.title')) ?></h1>
    <p><?= e(__('errors.500.text')) ?></p>
    <?php if (!empty($exception)): /* tylko przy app.debug = true */ ?>
        <pre class="debug"><?= e(get_class($exception) . ': ' . $exception->getMessage() . "\n" . $exception->getFile() . ':' . $exception->getLine() . "\n\n" . $exception->getTraceAsString()) ?></pre>
    <?php endif; ?>
    <p><a class="button" href="<?= e(url('/')) ?>"><?= e(__('errors.back_home')) ?></a></p>
</section>
