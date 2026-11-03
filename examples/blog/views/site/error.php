<?php

use yii\helpers\Html;

/** @var string $name */
/** @var string $message */
/** @var Throwable|null $exception */

$this->title = $name;
?>
<div id="blog-error-page">
<h1><?= Html::encode($this->title) ?></h1>
<div class="flash" style="background:#ffecec">
    <?= nl2br(Html::encode($message)) ?>
</div>

<?php if (YII_DEBUG && isset($exception)): ?>
    <h2><?= Html::encode(get_class($exception)) ?></h2>
    <p class="meta"><?= Html::encode($exception->getFile() . ':' . $exception->getLine()) ?></p>
    <pre style="overflow:auto;background:#f6f6f6;padding:1rem"><?= Html::encode($exception->getTraceAsString()) ?></pre>
<?php endif; ?>

<p><?= Html::a('&larr; home', ['/site/index']) ?></p>
</div>
