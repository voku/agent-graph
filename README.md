# agent-graph

Small, deterministic SQLite graph infrastructure for the `voku/agent-*` toolchain.

The package owns reusable graph storage/query mechanics and shared SQLite runtime assets. Domain semantics stay with their producers: `agent-map` owns repository/code relations, `agent-learning` owns learning lineage, and consumers use their typed owner APIs rather than reading graph databases directly.

## Structural graph contract

`GraphProjection` is the logical, versioned contract. SQLite is a derived implementation detail.

A projection contains ordered `GraphRelation` values with only:

- relation id;
- source id;
- relation kind;
- ordered target ids.

`GraphProjectionValidator` rejects malformed projections before storage. Empty projections fail by default and must be explicitly allowed when an owner legitimately has no relations. Self-relations and unresolved/external target ids remain legal because domain owners decide what those ids mean.

`GraphAdjacency` provides deterministic in-memory incoming/outgoing lookups without adding domain policy.

## SQLite relation store

`SqliteRelationStore` persists a validated projection as a rebuildable SQLite index. It preserves relation order and target order, supports incoming/outgoing queries with optional kind filters, replaces the complete derived graph atomically, records schema and projection provenance, and exposes integrity checks.

The store is intentionally independent from `sqlite-vec`; ordinary relation indexing works without vector support.

## sqlite-vec runtime

`agent-graph` ships the pinned `sqlite-vec` loadable SQLite extension used by graph/search consumers on the Linux platforms we actually support:

- `linux-gnu-x86_64`
- `linux-gnu-arm64`

The binary is a SQLite extension, not a PHP extension. One platform binary therefore serves all supported PHP versions. `voku\AgentGraph\Sqlite\SqliteVecBinary` resolves the current platform, verifies the bundled SHA-256 from `resources/sqlite-vec/manifest.json`, and returns `null` on unsupported platforms.

An explicit locally supplied binary can be selected with `AGENT_GRAPH_SQLITE_VEC`.

The bundled binaries are derived from `asg017/sqlite-vec`; see `docs/reference/third-party-notices.md` for attribution and licensing.

## Scope

This repository should remain boring. It is not an owner of PHP symbols, findings, LearningNotes, embeddings, ranking policy, workflow state, or prompt generation.
