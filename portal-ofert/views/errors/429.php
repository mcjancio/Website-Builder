<?php
$title = __('errors.429.title');
$noindex = true;
?>
<section class="container section error-page">
    <p class="error-page__code" aria-hidden="true">429</p>
    <h1><?= e(__('errors.429.title')) ?></h1>
    <p><?= e(__('errors.429.text')) ?></p>
    <p><a class="button" href="<?= e(url('/')) ?>"><?= e(__('errors.back_home')) ?></a></p>
</section>
