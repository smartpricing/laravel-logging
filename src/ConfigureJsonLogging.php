<?php

declare(strict_types=1);

namespace Smartness\LaravelLogging;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Log\Logger;
use Monolog\Handler\FallbackGroupHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Logger as MonologLogger;
use Smartness\LaravelLogging\Formatters\StructuredJsonFormatter;
use Smartness\LaravelLogging\Processors\ContextProcessor;

/** A Laravel tap: keep the native manager, channel routing and level thresholds. */
final class ConfigureJsonLogging
{
    public function __construct(private readonly Application $app) {}

    public function __invoke(Logger $logger, ?string $component = null): void
    {
        $monolog = $logger->getLogger();
        if (! $monolog instanceof MonologLogger) {
            return;
        }
        $options = $this->app['config']['structured-logging'];
        $formatter = new StructuredJsonFormatter($options, $component);
        $processor = new ContextProcessor($this->app);
        $handlers = [];
        foreach ($monolog->getHandlers() as $handler) {
            // Preserve non-stream transports, NullHandler and externally managed handlers.
            if (! $handler instanceof StreamHandler) {
                $handlers[] = $handler;

                continue;
            }
            $handler->setFormatter($formatter);
            $handler->pushProcessor($processor);
            if ($options['fallback_path'] && $handler->getUrl() !== $options['fallback_path']) {
                $fallback = new StreamHandler($options['fallback_path'], $handler->getLevel());
                $fallback->setFormatter($formatter);
                $fallback->pushProcessor($processor);
                $handler = new FallbackGroupHandler([$handler, $fallback], $handler->getBubble());
            }
            $handlers[] = $handler;
        }
        $monolog->setHandlers($handlers);
    }
}
