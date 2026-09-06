<?php

declare(strict_types=1);

namespace voku\AgentGraph\Graph;

final readonly class GraphRelation
{
    /**
     * @param list<string> $targetIds
     */
    public function __construct(
        public string $id,
        public string $sourceId,
        public string $kind,
        public array $targetIds,
    ) {
    }

    /**
     * @return array{id: string, source_id: string, kind: string, target_ids: list<string>}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'source_id' => $this->sourceId,
            'kind' => $this->kind,
            'target_ids' => $this->targetIds,
        ];
    }
}
