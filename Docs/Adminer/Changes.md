# Custom changes to the bundled Adminer

This document lists every customization made to the [Adminer](https://github.com/vrana/adminer/)
database management tool that is bundled inside `axenox.IDE` (folder `Adminer/`).

It is a preparation step for upgrading the bundled Adminer to **6.0** and moving to a
Composer-based fork. Most of the changes below will have to be **re-applied** on top of the
new Adminer version, because they are edits inside the original Adminer source files.

## Baseline

- Bundled version: **Adminer 4.8.2-dev** (see `Adminer/adminer/include/version.inc.php`).
- Adminer is included as **individual, non-compiled source files** (not the single-file
  build) since commit `8289483` – *"NEW Switched to non-compiled adminer version"*. This was
  done deliberately to allow debugging and patching. The old single-file build
  (`adminer-4.8.1.php`) was removed at that point.
- Adminer is launched through the integration layer in `Common/AdminerAPI.php` /
  `Common/InclusionAPI.php` and the thin wrapper `Adminer/adminer.php`. These files are
  **ours** and are not part of Adminer – they configure the plugin stack, inject the ExFace
  data-connection credentials and run Adminer inside the workbench session.

## How to use this document for the 6.0 upgrade

The changes fall into three re-application categories:

1. **Edits inside original Adminer files** – the hard part. Must be re-applied manually,
   file by file, because 6.0 restructured the sources. Search the current code for the
   markers `MOD exface` / `MOD ExFace` to find the most sensitive ones. These are listed in
   section [1](#1-edits-inside-original-adminer-source-files).
2. **New files we added** (custom driver, ER-diagram feature, plugins, theme). These can
   mostly be copied over, but may need API adjustments. See sections
   [2](#2-new-files-added-to-the-adminer-core)–[5](#5-custom-design-theme).
3. **Bundled third-party libraries** used by our features (mermaid, ace, jush).
   See section [6](#6-bundled-external-libraries).

---

## 1. Edits inside original Adminer source files

These are modifications of Adminer's own files. They are the ones most likely to break or
need re-applying after the 6.0 upgrade.

### 1.1 Integration / session (marked `MOD exface`)

| File | Change | Reason | Commits |
|------|--------|--------|---------|
| `adminer/include/bootstrap.inc.php` | Commented out `error_reporting()` + `set_error_handler('adminer_errors', …)` | Let the workbench control error handling; Adminer's handler interfered with ExFace | `8289483` |
| `adminer/include/auth.inc.php` | Commented out both `session_regenerate_id()` calls (in the `$auth` branch and the permanent-login branch) | Adminer runs inside the existing workbench PHP session; regenerating the id would destroy it | `8289483`, `9e8d18c` |

> Tip: after the upgrade, re-search for `session_regenerate_id`, `set_error_handler` and
> `error_reporting` in the new Adminer sources and re-apply the same neutralizations.

### 1.2 MS SQL driver – heavily modified

`adminer/drivers/mssql.inc.php` is the single most customized Adminer file. It also gained a
dependency on the workbench (`global $workbench; … $workbench->getCache()->getPool('adminer')`)
and a `Psr\SimpleCache\CacheInterface` import – neither exists in vanilla Adminer.

Accumulated changes (each row ≈ one commit):

| Change | Commits |
|--------|---------|
| Works with schemas other than `dbo` (driver + schema diagram + foreign keys) | `0e71cab`, `660bad6`, `f9bed67` |
| `nvarchar`/`ntext` length no longer shown doubled | `9e8d18c`, `8def76e` |
| Binary numbers now displayed | `58a1ac2` |
| Escape curly braces in identifiers/values | `5a09ec1` |
| MS SQL connection options support | `42cd76c` |
| Missing error messages now surfaced | `3180ba9` |
| Custom queries in SQL admin fixed | `edb4610` |
| Copy tables (incl. primary-key columns, **no data**), avoid duplicate constraint names | `dc795af`, `4543850`, `9cccab1` |
| Export as SQL / export DDL (with `DROP+CREATE`) | `95a59b8`, `0990aab`, `b4dadb1` |
| Drop table/column with constraints via SQL admin | `fe14f19`, `f14b712` |
| View definitions no longer truncated | `6e28090` |
| `IDENTITY()` handling: start at 1000, improved setter | `8ecc950`, `985725b` |
| Improved `EXPLAIN` output + refactored `FakeResult` class | `68e3783`, `d3cb59b` |
| Performance on large DBs via workbench cache | `e2de58d` |
| Changing views did not work | `a844d2c` |


### 1.3 Other core files

| File | Change | Commits |
|------|--------|---------|
| `adminer/drivers/mysql.inc.php` | `copy_tables()` copies structure/triggers **without data** (the `INSERT … SELECT` is commented with `// MOD ExFace do not copy data`); fixed naming of copied tables (`_copy`) | `2ed7702`, `d895e9a` |
| `adminer/drivers/pgsql.inc.php` | Support copying tables in SQL admin | `cc33986` |
| `adminer/foreign.inc.php` + `adminer/include/editing.inc.php` | Allow custom foreign-key syntax provided by drivers | `b4412e0` |
| `adminer/create.inc.php` | Always show nav tabs when editing table structure; fixed broken navigation when switching from structure change to data | `355a384`, `cfc8bf9`, `08381ed` |
| `adminer/view.inc.php` | View editor now shows nav tabs | `29ac9fe` |
| `adminer/include/functions.inc.php` | Tab navigation in table/row edit | `355a384` |
| `adminer/include/adminer.inc.php` | Added *ER diagram* nav link; removed "New item" link for views; hooks for DDL export and reading mode | `a0d0af6`, `3f1f560`, `b4dadb1`, `0198e98` |
| `adminer/include/design.inc.php` | Reading-only mode + switch to frames plugin instead of iframe hack | `0198e98`, `ef9604f` |
| `adminer/index.php` | Route `erdiagram=` to the new `erdiagram.inc.php`; reading mode | `a0d0af6`, `0198e98` |
| `adminer/lang/de.inc.php` | Translation fix | `d1c8723` |

---

## 2. New files added to the Adminer core

| File | Purpose | Commits |
|------|---------|---------|
| `adminer/erdiagram.inc.php` | **Completely new** feature: read-only ER diagram rendered with Mermaid + `svg-pan-zoom`, including SVG hover/click interactions and correct entity naming | `a0d0af6`, `4618539`, `a0c6726` |

This file is self-contained and not part of vanilla Adminer, so it can be copied as-is, but
it relies on the `erdiagram=` route added to `adminer/index.php` and the nav link in
`adminer/include/adminer.inc.php` (see section 1.3).

> **Removed:** the earlier, vuerd-based ER *designer* (`adminer/designer.inc.php`) and its
> bundled libraries (`externals/vuerd`, `externals/vuerd--sql-ddl-parser`) were deleted in
> `24ed9a5` because they never worked. Do **not** carry them over to 6.0. The Mermaid-based
> ER *diagram* above is the only ER feature that remains.

---

## 3. Custom and modified plugins (`Adminer/plugins/`)

The plugins actually enabled are configured in `Adminer/adminer.php`.

| Plugin file | Status | Notes | Commits |
|-------------|--------|-------|---------|
| `autocomplete.php` | Custom (David Grudl base, heavily modified) | SQL autocomplete for keywords/tables/columns; added table-alias support, DB-schema awareness and general improvements | `3b4f868`, `f8bab12`, `5643e0b`, `6e35939`, `9cfcf48`, `935a4e2` |
| `disable-jush.php` | Added (David Grudl) | Disables JUSH in the SQL textarea so the ACE autocomplete works | `366ebb1` |
| `tables-filter-mod.php` | New (`AdminerTablesFilterMOD`) | Modified copy of the stock `tables-filter` plugin | `b48ebf3` |
| `tree-viewer.php` + `tree-viewer/` | New (`AdminerTreeViewer`) | DB object tree viewer; later replaced close-button, removed stray `console.log()` | `20fdf83`, `a8e33d8`, `20856e0` |
| `frames.php` | Stock plugin, now used instead of the previous iframe hack | Enabled in wrapper | `ef9604f` |

---

## 4. Custom design (theme)

| File | Notes | Commits |
|------|-------|---------|
| `designs/exface/adminer.css` | **New ExFace theme** (`AdminerDesign('exface')`). Many CSS tweaks marked `/* MOD exface */`: tab styling when a message is shown, half-visible bottom buttons in the table list, sticky headers in SQL output, general CSS fixes | `5fb0c54`, `9ccc7b1`, `0acff61`, `f43d2d4`, `68e3783` |

---

## 5. Bundled external libraries (`Adminer/externals/`)

These libraries are bundled to support our custom features and are not part of the stock
Adminer distribution. After the upgrade, check whether Adminer 6.0 already ships equivalents.

| Folder | Used by | Commits |
|--------|---------|---------|
| `mermaid/` (`mermaid.min.js`, `svg-pan-zoom.min.js`) | ER diagram (`erdiagram.inc.php`) | `a0d0af6`, `4618539` |
| `ace/` (`ace.js`, `ext-language_tools.js`, `ext-searchbox.js`, `mode-sql.js`, `theme-tomorrow.js`) | SQL statement & view editor incl. search & replace and autocomplete | `935a4e2` |
| `jush/` | Syntax highlighting (bundled sources) | `8289483` |
| `JsShrink/` | JS minification helper | – |

---

## 6. Integration layer (outside `Adminer/`)

Not Adminer edits, but required for the integration and worth reviewing during the upgrade:

- `Common/AdminerAPI.php` – launches Adminer, builds auth from ExFace data connections
  (MySQL/MariaDB/PostgreSQL/MS SQL incl. SSL), exposes `exportDDL()` / `runSql()` helpers.
- `Common/InclusionAPI.php` – base class for including third-party tools.
- `Adminer/adminer.php` – wrapper that assembles the plugin stack and remembers SSL settings
  between login attempts.
- MySQL SSL certificate support was added in `56e8e13`.

---

## 7. Full commit history (oldest → newest)

All commits that touched `Adminer/`, for reference during re-application:

| Hash | Subject |
|------|---------|
| `24ed9a5` | REF removed obsolete adminer vuerd integration (never worked) |
| `d261f10` | Initial commit |
| `18f8e4e` | DEV Adminer in IDE facade |
| `a7116f7` | FIX Launching Adminer via IDE facade |
| `1d1c68a` | DEV Adminer works with MS SQL and MySQL connections |
| `5330416` | CSS-Datei einlesen & Button "DB Admin" gefixt |
| `5fb0c54` | FIX Adminer theme improved |
| `334741d` | FIX issues with Adminer |
| `9e8d18c` | FIX adminer bugs in MS SQL for nvarchar length + logout disabled |
| `58a1ac2` | FIX binary numbers were not shown in MS SQL driver |
| `5a09ec1` | FIX escape curly braces in MS SQL Adminer driver |
| `8def76e` | FIX length of MS SQL ntext shown double in Adminer |
| `42cd76c` | NEW Support for MS SQL connection options for Adminer |
| `3b4f868` | NEW Adminer autocomplete, FIX many improvements |
| `f8bab12` | NEW SQL autocomplete in Adminer now supports table aliases |
| `a844d2c` | FIX Changing views did not work in MS SQL |
| `0e71cab` | FIX MS SQL driver for Adminer now works with schemas other than dbo |
| `f9bed67` | FIX Adminer schema diagram did not show foreign keys for MS SQL |
| `20fdf83` | NEW AdminerTreeView plugin |
| `a8e33d8` | FIX replaced close-button in Adminer tree view |
| `3180ba9` | FIX missing error messages in Adminer on MS SQL |
| `660bad6` | FIX MS SQL foreign keys were not displayed on schemas other than dbo |
| `a4cf25f` | NEW links to reverse foreign keys in MS SQL and MySQL |
| `366ebb1` | FIX backward-keys plugin prevented autocomplete in SQL editor |
| `8289483` | NEW Switched to non-compiled adminer version |
| `b48ebf3` | NEW plugin tables-filter-mod |
| `ef9604f` | FIX now using frames plugin instead of hack for iframes |
| `edb4610` | FIX error on custom queries in MS SQL |
| `5643e0b` | FIX SQL autocomplete now includes schema |
| `9ccc7b1` | FIX CSS issues in adminer |
| `0acff61` | FIX Adminer tabs styling if message shown |
| `355a384` | FIX Adminer tab-navigation now in table/row edit too |
| `dc795af` | NEW SQL admin can now copy MS SQL tables |
| `f43d2d4` | FIX SQL admin bottom buttons in table list half-visible |
| `20856e0` | FIX removed unused console.log() |
| `d1c8723` | FIX translation |
| `0198e98` | DEV DB diagram in reading mode |
| `4543850` | FIX copying tables in MS SQL now incl pkey cols, but no data |
| `6e35939` | FIX autocomplete with DB schemas |
| `29ac9fe` | FIX View editor now shows nav tabs |
| `9cfcf48` | FIX improved autocomplete |
| `cfc8bf9` | FIX always show nav links when editing table structure |
| `3f1f560` | FIX removed "New item" link for views |
| `ba79f5f` | FIX replaced deprecated dependency vuerd 2 |
| `6e28090` | FIX view definitions being truncated for MS SQL |
| `08381ed` | FIX broken navigation if going from SQL table change to data |
| `d895e9a` | FIX naming of copied MySQL tables |
| `9cccab1` | FIX duplicate constraint names when copying tables in MS SQL |
| `2ed7702` | FIX improved Adminer copying tables and views (now without data) |
| `b4412e0` | FIX Adminer allow custom foreign key syntax in drivers |
| `95a59b8` | NEW Adminer export as SQL now supported for MS SQL databases |
| `b4dadb1` | FIX Adminer export DDL on MS SQL with DROP+CREATE |
| `0990aab` | FIX MS SQL export DDL |
| `fe14f19` | FIX MS SQL drop table or column with constraints via SQL admin |
| `f14b712` | FIX error in SQL admin on MS SQL drop table |
| `935a4e2` | NEW search&replace in SQL admin statement&view editor + updated ACE |
| `a0d0af6` | NEW ER Diagram in SQL admin |
| `56e8e13` | NEW support for MySQL connections with SSL certificates |
| `e2de58d` | FIX improved performance on large MS SQL DBs by using workbench cache |
| `4618539` | NEW erddiagram added svg interactions |
| `a0c6726` | FIX erddiagram get correct entity name from table |
| `8ecc950` | FIX MS SQL IDENTITY() columns now start with 1000 |
| `985725b` | FIX improved setting IDENTITY() for MS SQL |
| `68e3783` | NEW sticky headers in SQL output and improved MS SQL explain |
| `d3cb59b` | REF improved FakeResult class for MS SQL explain output |
| `cc33986` | NEW support for copying tables in SQL admin for PostgreSQL |
