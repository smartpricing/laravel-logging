<?php

// Only used against disposable applications created by install.sh.
$directory = $argv[1];
$manifest = json_decode(file_get_contents($directory.'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
$manifest['repositories'][] = [
    'type' => 'path', 'url' => $argv[2],
    'options' => ['symlink' => false, 'versions' => ['smartness/laravel-logging' => '0.1.x-dev']],
];
$manifest['require']['smartness/laravel-logging'] = '0.1.x-dev';
file_put_contents($directory.'/composer.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
file_put_contents($directory.'/.env', implode("\n", [
    'APP_NAME=LoggingSmoke', 'APP_ENV=testing', 'APP_DEBUG=false',
    'APP_KEY=base64:'.base64_encode(random_bytes(32)), 'LOG_CHANNEL=single',
    'SESSION_DRIVER=array', 'CACHE_STORE=array', 'QUEUE_CONNECTION=sync',
    'STRUCTURED_LOGGING_SERVICE=smoke-app', '',
]));
