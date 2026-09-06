# Changelog

## 0.2.0

- Add the versioned, domain-neutral `GraphProjection` and `GraphRelation` contract for ordered structural relations.
- Add deterministic projection validation with structured findings and explicit opt-in for intentionally empty graphs.
- Add the reusable in-memory `GraphAdjacency` view with deterministic incoming/outgoing relation ordering and optional kind filters.
- Add `SqliteRelationStore` as a rebuildable derived SQLite relation index with atomic replacement, ordered multi-target reconstruction, schema/projection provenance, integrity checks, and fail-closed reads.
- Keep graph mechanics independent from PHP symbols, LearningNotes, ranking policy, workflow state, embeddings, and sqlite-vec availability.

## 0.1.0

- Introduce `voku/agent-graph` as the shared owner for deterministic SQLite graph infrastructure.
- Ship pinned `sqlite-vec v0.1.7-alpha.2` loadable extensions for Linux GNU x86_64 and arm64.
- Add checksum-verified runtime resolution through `voku\AgentGraph\Sqlite\SqliteVecBinary`.
- Add a manifest-driven maintenance workflow for refreshing the vendored sqlite-vec runtime.
