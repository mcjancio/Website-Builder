<?php
$title = __('errors.405.title');
$noindex = true;
?>
<section class="container section error-page">
    <p class="error-page__code" aria-hidden="true">405</p>
    <h1><?= e(__('errors.405.title')) ?></h1>
    <p><?= e(__('errors.405.text')) ?></p>
    <p><a class="button" href="<?= e(url('/')) ?>"><?= e(__('errors.back_home')) ?></a></p>
</section>
