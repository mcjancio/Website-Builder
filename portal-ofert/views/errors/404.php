<?php
$title = __('errors.404.title');
$noindex = true;
?>
<section class="container section error-page">
    <p class="error-page__code" aria-hidden="true">404</p>
    <h1><?= e(__('errors.404.title')) ?></h1>
    <p><?= e(__('errors.404.text')) ?></p>
    <p><a class="button" href="<?= e(url('/')) ?>"><?= e(__('errors.back_home')) ?></a></p>
</section>
