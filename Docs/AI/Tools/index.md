# AI tools

[Deutsch](index_german.md)

SQL tool results use GenAI's detached textual result contract. The conversation retains the
complete value and durable diagnostics independently of the original tool instance. Continuing a
conversation restores the recorded result; it never reruns a historical SQL statement.

## SQL connection arguments

The `connection` argument of [SQLPerformTool](../../../AI/Tools/SQLPerformTool.php)
(`axenox.IDE.SQLPerformTool`) and [SQLReadTool](../../../AI/Tools/SQLReadTool.php)
(`axenox.IDE.SQLReadTool`) accepts a connection UID or namespaced alias. Omit it or pass
JSON `null` to use the SQL admin assistant's connection from the current prompt.
Other agents must provide a connection explicitly; otherwise the call fails with a
missing-connection error. The `statement` argument remains required for both tools.

## SQLSelectTool

Runs one read-oriented SQL query and returns the result as a Markdown table.

Typical uses include inspecting table rows, aggregating data and reading database metadata. Do not
use this tool for migrations, maintenance commands or any intentional data change.

### Configuration

- **Alias:** `axenox.IDE.SQLSelectTool`
- **UXON prototype:** [SQLSelectTool.php](../../../AI/Tools/SQLSelectTool.php)
- **allow_multiple_queries:** Set to `true` to permit multiple semicolon-separated queries. Every
  query is validated independently. Defaults to `false`.
- **statement:** One or more complete `SELECT` queries. A query may start with `WITH` when its final
  operation is a `SELECT`.
- **data_connection_alias:** Optional namespaced alias of the SQL data connection. The SQL admin
  assistant can instead obtain the connection from its prompt.
- **explain:** Boolean tool argument. Set it to `true` to append AdminNeo's dialect-specific EXPLAIN
  result and, when supported by the database driver, actual runtime and I/O statistics for this
  invocation. Defaults to `false`.

SQL tools use AdminNeo from the `axenox/adminneo` dependency. The bundled Adminer 4 engine and
the `SQL_ADMIN.ENGINE` switch have been removed. The facade accepts both `/api/ide/adminneo/`
and the legacy `/api/ide/adminer/` prefix, with both routes handled by AdminNeo.

### Result

The result rows are formatted as a Markdown table. When multiple queries are enabled, they are
executed separately and each result table is placed below a numbered heading. Enabled EXPLAIN and
runtime statistics output is appended below its query result.

### Read-only limitations

By default, the tool rejects multiple statements. It always rejects non-`SELECT` statements,
data-changing common table expressions, `SELECT INTO`, row-locking selects and common write, DDL and session-control keywords.
It parses quoted values and comments before checking keywords, rather than validating raw SQL with
a regular expression.

This validation prevents common accidental writes but is not a security boundary. SQL dialects can
provide side-effecting functions and other extensions inside a syntactically valid `SELECT`. Use a
database account whose privileges permit only the intended read operations whenever read-only access
must be guaranteed.

Prepending `SELECT` to arbitrary input does not improve the guarantee: writable constructs can still
occur later in the statement, and valid `WITH` queries would be damaged.