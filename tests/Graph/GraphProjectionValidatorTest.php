<?php

declare(strict_types=1);

namespace voku\AgentGraph\Tests\Graph;

use PHPUnit\Framework\TestCase;
use voku\AgentGraph\Graph\GraphProjection;
use voku\AgentGraph\Graph\GraphProjectionValidator;
use voku\AgentGraph\Graph\GraphRelation;

final class GraphProjectionValidatorTest extends TestCase
{
    public function testValidProjectionPasses(): void
    {
        $projection = new GraphProjection([
            new GraphRelation('r1', 'a', 'calls', ['b', 'c']),
        ]);

        $report = (new GraphProjectionValidator())->validate($projection);

        self::assertTrue($report->passed());
        self::assertSame([], $report->issues);
    }

    public function testEmptyProjectionFailsUnlessExplicitlyAllowed(): void
    {
        $projection = new GraphProjection([]);
        $validator = new GraphProjectionValidator();

        self::assertTrue($validator->validate($projection)->failed());
        self::assertTrue($validator->validate($projection, true)->passed());
    }

    public function testMultipleValidationProblemsAreReportedTogether(): void
    {
        $projection = new GraphProjection([
            new GraphRelation('', '', '', []),
            new GraphRelation('dup', 'source', 'kind', ['target', 'target', '']),
            new GraphRelation('dup', 'source', 'kind', ['other']),
        ], '999');

        $report = (new GraphProjectionValidator())->validate($projection);
        $codes = array_map(static fn ($issue): string => $issue->code, $report->issues);

        self::assertContains('projection.unsupported_version', $codes);
        self::assertContains('relation.empty_id', $codes);
        self::assertContains('relation.empty_source', $codes);
        self::assertContains('relation.empty_kind', $codes);
        self::assertContains('relation.empty_targets', $codes);
        self::assertContains('relation.duplicate_target', $codes);
        self::assertContains('relation.empty_target', $codes);
        self::assertContains('relation.duplicate_id', $codes);
    }

    public function testSelfRelationsAndUnknownTargetsAreValid(): void
    {
        $projection = new GraphProjection([
            new GraphRelation('self', 'node', 'references', ['node']),
            new GraphRelation('external', 'node', 'references', ['external:missing']),
        ]);

        self::assertTrue((new GraphProjectionValidator())->validate($projection)->passed());
    }
}
