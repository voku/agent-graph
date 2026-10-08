# Changelog

## Unreleased

- **Schema version 2.** Relations and their targets are now keyed by the integer `relation_position` instead of the text relation id, and the targets table is `WITHOUT ROWID`. On a 200k-relation graph this shrinks the file by about 40% (51.7 → 30.9 MB) and speeds up high-fan-in lookups, because rows are already clustered in canonical order. Query results, ordering and the public API are unchanged.
- A writable open (`new GraphStore($file)`, `SqliteRelationStore`) upgrades a version 1 file in place inside one transaction (verified target count, then `VACUUM`); the 200k-relation graph took about 4 s. Read-only opens (`GraphStore::openReadOnly()`) of a version 1 file keep failing closed with the schema-version error and never modify the file, so open it writable once, or rebuild it, to upgrade.

- Use a single SQLite connection per `GraphStore` and cache the schema/projection readiness check (invalidated on replacement) instead of re-validating on every `incoming()`/`outgoing()` call. `SqliteIncomingRelationQuery` is removed; its indexed `target_id` lookup now lives in `SqliteRelationStore::incoming()`.
- Answer `GraphStore::neighbours()` with one deduplicating SQL `UNION` over the indexed lookups instead of materializing full relations, and group relation rows while streaming.
- Roughly halve full-rebuild time by dropping the two secondary indexes inside the replacement transaction and rebuilding them once after the load (rolled back together with the data on failure).
- Add optional `maximumRelations` to `GraphStore::traverse()` (and `$limit` to `SqliteRelationStore::incoming()`/`outgoing()`) to bound work on high-degree nodes; hitting the cap sets `truncated`. Default behaviour is unchanged.
- Add `scripts/benchmark.php` and `scripts/compare-benchmark.sh` for repeatable before/after measurements with result checksums, and `scripts/update-sqlite-vec.sh` for sqlite-vec updates.

## 0.2.3

- Add `GraphStore::openReadOnly()` for deterministic graph queries and bounded traversal without creating directories, migrating schemas, or otherwise mutating persisted graph state.
- Enforce SQLite read-only access for both relation and indexed incoming-query connections; reject writes, missing databases, and invalid/missing graph schemas at open time.
- Add regression coverage across PHP 8.2-8.5 for read-only queries/traversal, unchanged database bytes, rejected replacement, missing databases, and invalid existing schemas.

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
