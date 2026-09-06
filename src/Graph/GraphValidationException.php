<?php

declare(strict_types=1);

namespace voku\AgentGraph\Graph;

use InvalidArgumentException;

final class GraphValidationException extends InvalidArgumentException
{
    public function __construct(public readonly GraphValidationReport $report)
    {
        $first = $report->errors()[0] ?? null;

        parent::__construct($first?->message ?? 'Graph projection validation failed.');
    }
}
