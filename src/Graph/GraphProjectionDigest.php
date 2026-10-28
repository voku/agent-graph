<?php

declare(strict_types=1);

namespace voku\AgentGraph\Graph;

use HashContext;

/**
 * Versioned, order-sensitive digest of the *complete* persisted relation projection.
 *
 * Length-prefixing every record makes record boundaries unambiguous. Targets and
 * relations must retain their original order; neither is a set.
 */
final class GraphProjectionDigest
{
    private const DOMAIN = "agent-graph-projection-v1\0";

    public static function start(): HashContext
    {
        $context = hash_init('sha256');
        hash_update($context, self::DOMAIN);

        return $context;
    }

    public static function append(HashContext $context, GraphRelation $relation): void
    {
        $bytes = json_encode($relation->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        hash_update($context, pack('N', strlen($bytes)));
        hash_update($context, $bytes);
    }

    public static function finish(HashContext $context): string
    {
        return 'v1:sha256:' . hash_final($context);
    }

    /** @param iterable<GraphRelation> $relations */
    public static function forRelations(iterable $relations): string
    {
        $context = self::start();
        foreach ($relations as $relation) {
            self::append($context, $relation);
        }

        return self::finish($context);
    }
}
