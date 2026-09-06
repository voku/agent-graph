<?php

declare(strict_types=1);

namespace voku\AgentGraph\Graph;

final readonly class GraphValidationReport
{
    /**
     * @param list<GraphValidationIssue> $issues
     */
    public function __construct(public array $issues)
    {
    }

    public function failed(bool $strict = false): bool
    {
        foreach ($this->issues as $issue) {
            if ($issue->severity === GraphValidationIssue::ERROR) {
                return true;
            }

            if ($strict && $issue->severity === GraphValidationIssue::WARNING) {
                return true;
            }
        }

        return false;
    }

    public function passed(bool $strict = false): bool
    {
        return !$this->failed($strict);
    }

    /** @return list<GraphValidationIssue> */
    public function errors(): array
    {
        return array_values(array_filter(
            $this->issues,
            static fn (GraphValidationIssue $issue): bool => $issue->severity === GraphValidationIssue::ERROR,
        ));
    }

    /** @return list<GraphValidationIssue> */
    public function warnings(): array
    {
        return array_values(array_filter(
            $this->issues,
            static fn (GraphValidationIssue $issue): bool => $issue->severity === GraphValidationIssue::WARNING,
        ));
    }
}
