# Agent workflow

Read the repository’s `AGENTS.md`, [contributing](contributing.md), and [architecture](../architecture.md). Trace the public trait method, persisted entry, and emitted event before changing ledger behavior.

Keep package names consistent across runtime code, Composer, migration/config tags, tests, docs, and Boost. Use local installed framework source to verify APIs. Add regression tests for the affected behavior and run the checks in [testing](testing.md).

Report checks actually run and any untested boundary. A SQLite pass does not prove row-locking behavior on MySQL/PostgreSQL. Keep caches, local dependencies, and fixtures out of the distribution. Do not publish or install global/application agent configuration as a side effect of maintenance.
