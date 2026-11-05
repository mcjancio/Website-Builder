<?php
/**
 * @var array $page
 * @var string $body HTML z App\Core\Markdown (już bezpieczny)
 */
?>
<article class="container section prose">
    <h1><?= e($page['title']) ?></h1>
    <?= $body ?>
</article>
