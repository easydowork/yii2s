<?php

/**
 * Demo parameters. The API key is read from the environment so a real
 * deployment never ships the demo value; the fallback keeps the example
 * runnable out of the box.
 */
return [
    'apiKey' => getenv('BLOG_API_KEY') ?: 'blog-demo-api-key',
];
