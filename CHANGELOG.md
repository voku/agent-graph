# Changelog

## 0.1.0

- Introduce `voku/agent-graph` as the shared owner for deterministic SQLite graph infrastructure.
- Ship pinned `sqlite-vec v0.1.7-alpha.2` loadable extensions for Linux GNU x86_64 and arm64.
- Add checksum-verified runtime resolution through `voku\AgentGraph\Sqlite\SqliteVecBinary`.
- Add a manifest-driven maintenance workflow for refreshing the vendored sqlite-vec runtime.
