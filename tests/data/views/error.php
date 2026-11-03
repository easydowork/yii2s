<?php

/**
 * Custom error view used by tests/compat/CompatErrorController.
 *
 * @var string $name
 * @var string $message
 * @var Throwable|null $exception
 */
?>
<div id="compat-custom-error">
    <h1><?= htmlspecialchars((string) $name, ENT_QUOTES, 'UTF-8') ?></h1>
    <p><?= htmlspecialchars((string) $message, ENT_QUOTES, 'UTF-8') ?></p>
</div>
