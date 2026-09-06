# agent-graph

Small, deterministic SQLite graph index/query infrastructure for the `voku/agent-*` toolchain.

The package owns reusable graph storage/query mechanics and shared SQLite runtime assets. Domain semantics stay with their producers: `agent-map` owns repository/code relations, `agent-learning` owns learning lineage, and consumers use those owners rather than reading another package's graph database directly.

## GraphStore

`GraphStore` is the primary consumer boundary. Owners project only structural identity into ordered `GraphRelation` values:

- relation id;
- source id;
- relation kind;
- ordered target ids.

A relation may keep multiple targets. That grouping is part of the evidence and is not flattened into unrelated binary edges.

`GraphStore::replace()` accepts an `iterable<GraphRelation>` and replaces the complete derived graph transactionally without requiring callers to allocate a second graph array. The store records opaque source revision/fingerprint provenance so an owner can reject a stale derived database after its canonical data changes.

Reads provide:

- indexed incoming relations;
- indexed outgoing relations;
- unique deterministic neighbours;
- streamed whole-graph relation iteration;
- bounded, cycle-safe incoming/outgoing traversal with explicit truncation.

SQLite rows are an internal normalization. The public relation model stays grouped and domain-neutral.

## Structural graph contract

`GraphProjection` remains the versioned logical projection used by callers that already have an in-memory relation list. `GraphProjectionValidator` rejects malformed projections before storage. Empty projections fail by default and must be explicitly allowed when an owner legitimately has no relations. Self-relations and unresolved/external target ids remain legal because domain owners decide what those ids mean.

`GraphAdjacency` remains available as a deterministic in-memory view, but persistent consumers should prefer `GraphStore` when the point is to avoid decoding or indexing a large relation set in PHP.

## SQLite relation store

`SqliteRelationStore` is the low-level storage implementation behind `GraphStore`. It preserves relation order and target order, supports optional kind filters, replaces the complete derived graph atomically, records schema/projection provenance, and exposes integrity checks.

Ordinary relation storage stays a single SQLite artifact by default. The package does not force WAL or synchronous tuning without measured evidence. The store is independent from `sqlite-vec`; relation indexing works without vector support.

## sqlite-vec runtime

`agent-graph` ships the pinned `sqlite-vec` loadable SQLite extension used by graph/search consumers on the Linux platforms we actually support:

- `linux-gnu-x86_64`
- `linux-gnu-arm64`

The binary is a SQLite extension, not a PHP extension. One platform binary therefore serves all supported PHP versions. `voku\AgentGraph\Sqlite\SqliteVecBinary` resolves the current platform, verifies the bundled SHA-256 from `resources/sqlite-vec/manifest.json`, and returns `null` on unsupported platforms.

An explicit locally supplied binary can be selected with `AGENT_GRAPH_SQLITE_VEC`.

The bundled binaries are derived from `asg017/sqlite-vec`; see `docs/reference/third-party-notices.md` for attribution and licensing.

## Scope

This repository should remain boring. It is not an owner of PHP symbols, findings, LearningNotes, embeddings, ranking policy, workflow state, prompt generation, or an untyped metadata bag.
