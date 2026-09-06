<?php

declare(strict_types=1);

namespace voku\AgentGraph\Graph;

final class GraphProjectionValidator
{
    public function validate(GraphProjection $projection, bool $allowEmpty = false): GraphValidationReport
    {
        $issues = [];

        if ($projection->version !== GraphProjection::VERSION) {
            $issues[] = new GraphValidationIssue(
                GraphValidationIssue::ERROR,
                'projection.unsupported_version',
                sprintf('Unsupported graph projection version "%s".', $projection->version),
            );
        }

        if (!$allowEmpty && $projection->relations === []) {
            $issues[] = new GraphValidationIssue(
                GraphValidationIssue::ERROR,
                'projection.empty',
                'Graph projection is empty but emptiness was not explicitly allowed.',
            );
        }

        $seenRelationIds = [];
        foreach ($projection->relations as $relation) {
            if (trim($relation->id) === '') {
                $issues[] = $this->error('relation.empty_id', 'Relation id must be non-empty.', $relation->id);
            } elseif (isset($seenRelationIds[$relation->id])) {
                $issues[] = $this->error('relation.duplicate_id', 'Relation id must be unique.', $relation->id);
            } else {
                $seenRelationIds[$relation->id] = true;
            }

            if (trim($relation->sourceId) === '') {
                $issues[] = $this->error('relation.empty_source', 'Relation source id must be non-empty.', $relation->id);
            }

            if (trim($relation->kind) === '') {
                $issues[] = $this->error('relation.empty_kind', 'Relation kind must be non-empty.', $relation->id);
            }

            if ($relation->targetIds === []) {
                $issues[] = $this->error('relation.empty_targets', 'Relation must contain at least one target id.', $relation->id);
                continue;
            }

            $seenTargets = [];
            foreach ($relation->targetIds as $targetId) {
                if (trim($targetId) === '') {
                    $issues[] = $this->error('relation.empty_target', 'Relation target id must be non-empty.', $relation->id);
                    continue;
                }

                if (isset($seenTargets[$targetId])) {
                    $issues[] = $this->error('relation.duplicate_target', 'Relation target ids must be unique within one relation.', $relation->id);
                    continue;
                }

                $seenTargets[$targetId] = true;
            }
        }

        return new GraphValidationReport($issues);
    }

    private function error(string $code, string $message, string $relationId): GraphValidationIssue
    {
        return new GraphValidationIssue(GraphValidationIssue::ERROR, $code, $message, $relationId !== '' ? $relationId : null);
    }
}
