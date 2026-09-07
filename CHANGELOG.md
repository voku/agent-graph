# Changelog

## 0.2.2

- Update the bundled sqlite-vec runtime from `v0.1.7-alpha.2` to stable `v0.1.9` for Linux GNU x86_64 and arm64 while preserving the existing `SqliteVecBinary` API and checksum-verified resolution contract.
- Harden sqlite-vec maintenance so updates verify GitHub release-asset SHA-256 digests, run package CI plus load/create/insert/k-NN/delete smoke proof before publishing the versioned automation branch, and keep the manifest as the single version authority without requiring Actions permission to create pull requests.

## 0.2.1

- Fix target-based `GraphStore::incoming()` reads so SQLite starts from the indexed `(target_id, relation_id)` relation-target lookup instead of scanning relations through a correlated `EXISTS` predicate.
- Preserve canonical relation order, grouped multi-target reconstruction, kind filtering, and existing GraphStore semantics while removing the pathological reverse-traversal cost exposed by agent-map's 70 MiB graph benchmark.

## 0.2.0

- Add the versioned, domain-neutral `GraphProjection` and `GraphRelation` contract for ordered structural relations.
- Add deterministic projection validation with structured findings and explicit opt-in for intentionally empty graphs.
- Add `GraphStore` as the public SQLite graph index/query boundary with streaming whole-graph replacement, streamed relation iteration, source revision/fingerprint provenance, indexed incoming/outgoing/neighbour queries, and deterministic bounded cycle-safe traversal.
- Preserve relation order and grouped multi-target order without requiring callers to materialize a second graph array in PHP.
- Keep `SqliteRelationStore` as the low-level rebuildable SQLite implementation with atomic replacement, schema/projection checks, integrity checks, and fail-closed reads.
- Keep ordinary relation storage single-file by default rather than forcing WAL/synchronous tuning without benchmark evidence.
- Keep graph mechanics independent from PHP symbols, LearningNotes, ranking policy, workflow state, embeddings, and sqlite-vec availability.

## 0.1.0

- Introduce `voku/agent-graph` as the shared owner for deterministic SQLite graph infrastructure.
- Ship pinned `sqlite-vec v0.1.7-alpha.2` loadable extensions for Linux GNU x86_64 and arm64.
- Add checksum-verified runtime resolution through `voku\AgentGraph\Sqlite\SqliteVecBinary`.
- Add a manifest-driven maintenance workflow for refreshing the vendored sqlite-vec runtime.
