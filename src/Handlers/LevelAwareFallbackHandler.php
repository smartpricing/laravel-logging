<?php

declare(strict_types=1);

namespace Smartness\LaravelLogging\Handlers;

use Monolog\Handler\FallbackGroupHandler;
use Monolog\LogRecord;

/** A filtered record must never stop delivery to later handlers in a stack. */
final class LevelAwareFallbackHandler extends FallbackGroupHandler
{
    public function handle(LogRecord $record): bool
    {
        if (! $this->isHandling($record)) {
            return false;
        }

        return parent::handle($record);
    }
}
