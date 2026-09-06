<?php

declare(strict_types=1);

namespace voku\AgentGraph\Graph;

final readonly class GraphValidationIssue
{
    public const ERROR = 'error';
    public const WARNING = 'warning';

    public function __construct(
        public string $severity,
        public string $code,
        public string $message,
        public ?string $relationId = null,
    ) {
    }
}
