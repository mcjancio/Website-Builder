<?php
/** @var array $errors */
use App\Core\View;
?>
<section class="container section">
    <?= View::partial('account/partials/nav', ['active' => 'security']) ?>
    <h1><?= e(__('delete.title')) ?></h1>
    <div class="prose">
        <p><?= e(__('delete.intro')) ?></p>
        <ul>
            <li><?= e(__('delete.point_data')) ?></li>
            <li><?= e(__('delete.point_listings')) ?></li>
            <li><?= e(__('delete.point_consents')) ?></li>
        </ul>
    </div>
    <form method="post" action="<?= e(url('/konto/usun')) ?>" class="form" novalidate>
        <?= csrf_field() ?>
        <div class="field">
            <label for="password"><?= e(__('delete.password')) ?></label>
            <input id="password" name="password" type="password" required autocomplete="current-password"<?= field_aria($errors, 'password') ?>>
            <?= field_error($errors, 'password') ?>
        </div>
        <div class="field field--check">
            <label><input type="checkbox" name="confirm" value="1" required<?= field_aria($errors, 'confirm') ?>> <span><?= e(__('delete.confirm_label')) ?></span></label>
            <?= field_error($errors, 'confirm') ?>
        </div>
        <button type="submit" class="button button--danger"><?= e(__('delete.submit')) ?></button>
        <p><a href="<?= e(url('/konto')) ?>"><?= e(__('delete.cancel')) ?></a></p>
    </form>
</section>
