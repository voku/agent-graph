<?php

declare(strict_types=1);

namespace voku\AgentGraph\Graph;

final readonly class GraphProjection
{
    public const VERSION = '1';

    /**
     * @param list<GraphRelation> $relations
     */
    public function __construct(
        public array $relations,
        public string $version = self::VERSION,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->relations === [];
    }
}
