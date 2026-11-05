<section class="container section auth">
    <h1><?= e(__('password.invalid_title')) ?></h1>
    <p><?= e(__('password.invalid_text')) ?></p>
    <p><a class="button" href="<?= e(url('/reset-hasla')) ?>"><?= e(__('password.send_link')) ?></a></p>
</section>
