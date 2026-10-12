<?php

declare(strict_types=1);

namespace Smartness\LaravelLogging\Context;

use Illuminate\Http\Request;

/** A distinct instance belongs to each Octane sandbox; the base worker has no request. */
final class OctaneContext
{
    public function __construct(public readonly ?Request $request = null) {}
}
