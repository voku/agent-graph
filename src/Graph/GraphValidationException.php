<?php

declare(strict_types=1);

namespace voku\AgentGraph\Graph;

use InvalidArgumentException;

final class GraphValidationException extends InvalidArgumentException
{
    public function __construct(public readonly GraphValidationReport $report)
    {
        $errors = $report->errors();
        $message = $errors === []
            ? 'Graph projection validation failed.'
            : $errors[0]->message;

        parent::__construct($message);
    }
}
