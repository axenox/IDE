# AI tools

[Deutsch](index_german.md)

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
  result for this invocation. Defaults to `false`. Adminer integrations return an empty result.

### Result

The result rows are formatted as a Markdown table. When multiple queries are enabled, they are
executed separately and each result table is placed below a numbered heading. Enabled EXPLAIN output
is appended below its query result.

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