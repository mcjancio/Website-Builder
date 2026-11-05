<?php
$title = __('errors.419.title');
$noindex = true;
?>
<section class="container section error-page">
    <p class="error-page__code" aria-hidden="true">419</p>
    <h1><?= e(__('errors.419.title')) ?></h1>
    <p><?= e(__('errors.419.text')) ?></p>
    <p><a class="button" href="<?= e(url('/')) ?>"><?= e(__('errors.back_home')) ?></a></p>
</section>
