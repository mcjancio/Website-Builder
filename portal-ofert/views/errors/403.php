<?php
$title = __('errors.403.title');
$noindex = true;
?>
<section class="container section error-page">
    <p class="error-page__code" aria-hidden="true">403</p>
    <h1><?= e(__('errors.403.title')) ?></h1>
    <p><?= e(__('errors.403.text')) ?></p>
    <p><a class="button" href="<?= e(url('/')) ?>"><?= e(__('errors.back_home')) ?></a></p>
</section>
