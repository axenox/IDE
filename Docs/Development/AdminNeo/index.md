# AdminNeo integration as SQL admin

## Database diagrams

The IDE's custom schema view renders Mermaid ER syntax with
[Pele](https://pele.run/), installed through Composer as `npm-asset/pele`.
The locally hosted `dist/pele.global.js` bundle has no runtime dependencies.
Other Markdown widgets and editors retain their existing Mermaid renderer.

The schema plugin keeps table filtering, relation depth, table actions,
relationship navigation and svg-pan-zoom controls. Pele colors inherit AdminNeo's
theme through CSS variables. Its layout differs from Mermaid.js.
Table cards use AdminNeo's table header, row borders and hover/selection colors,
with a smaller rendering font for more compact rows.
Relation depth defaults to 1, including when opening a schema from a table tab.
SQL type labels retain their parameters without generated identifier suffixes.

To check an update, open a database's schema page and test table selection,
relationship selection, both "Go to" buttons, zoom controls and resizing.
Include parallel foreign keys, self-references and a filtered schema.

## Error handling

- [SQL admin error logging](SQL_admin_errors.md)