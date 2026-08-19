# Plan: porting the custom Adminer changes to the `axenox/adminer` 6.0 fork

This is the execution plan for re-applying the customizations documented in
[Changes.md](Changes.md) on top of the new **`axenox/adminer`** fork (currently
**Adminer 6.0.2-dev**), and for switching `axenox.IDE` from the bundled 4.8.2 sources to the
Composer package.

The overriding goal is that the fork **stays mergeable with upstream**
(`https://github.com/vrana/adminer`). Every change should therefore be implemented in the
least invasive way possible, in this order of preference:

1. **Use a stock plugin** shipped with 6.x (many of our old customizations are now stock).
2. **Add a plugin** (`extends Adminer\Plugin`) or a **driver subclass** in our own namespace.
3. **Patch a core file** only when there is no hook – and mark it clearly so it can be
   re-applied after every upstream merge.

## 1. What changed in Adminer 6.x (why 4.8.2 patches don't apply)

Adminer 6.x is a near-complete rewrite compared to 4.8.2:

- **Namespaced + object-oriented**: every file starts with `namespace Adminer;`. Drivers are
  now `class Db extends SqlDb` / `class Driver extends SqlDriver`; there are no more
  `Min_DB` classes.
- **No global variables**: code uses accessor functions such as `adminer()`, `driver()`,
  `connection()`, and the `VERSION` constant (was `$VERSION`). The
  `global $adminer, $connection, …` line in `bootstrap.inc.php` is gone.
- **Rich override API**: the `Adminer` class exposes typed, overridable methods
  (`name()`, `head()`, `css()`, `navigation()`, `tablesPrint()`, `schemas()`,
  `foreignKeys()`, `headers()`, …). Plugins extend `Adminer\Plugin` and chain via the
  `Plugins` manager.
- **Typed signatures** everywhere (`function copy_tables(array $tables, array $views, string $target): bool`).
- **Built-in features** that we previously had to add: table-list filter, a schema visualizer
  (`schema.inc.php`), SQL editor with syntax highlighting **and autocomplete**
  (`highlight-monaco` / `highlight-codemirror` plugins), `frames`, `login-ssl`,
  `login-password-less`, `database-hide`, `designs` (design switching), `menu-links`.

**Consequence:** none of the inline diffs from 4.8.2 can be applied as-is. Each must be
re-implemented against the new API, and most MS SQL bug-fixes are already upstream.

## 2. Per-change assessment

Legend for **Action**:
`DROP` = no longer needed · `STOCK` = use a built-in 6.x plugin · `PLUGIN` = re-implement as
our plugin/driver subclass · `PATCH` = unavoidable core edit (mark for re-merge) ·
`VERIFY` = confirm upstream already fixed it, otherwise re-implement · `DECIDE` = product
decision required.

### 2.1 Editor / autocomplete

| Old change | Action | Notes |
|------------|--------|-------|
| `plugins/autocomplete.php` (custom SQL autocomplete) | **IMPLEMENTED** | Use the built-in jush editor (not Monaco). Adminer's stock 6.x editor is **jush**, which provides BOTH syntax highlighting and SQL autocomplete via `jush-autocomplete-sql.js`. The stock `highlight-monaco` plugin *replaces* jush entirely, but Monaco's bundled `sql`/`mysql`/`pgsql` languages have no completion provider – tested and verified. So DO NOT enable the Monaco plugin; let the core jush editor run unmodified. |
| jush git submodule issue | **RESOLVED** | Adminer keeps jush as a git submodule (`adminer/static/jush`), which Composer does **not** populate (dist archives strip submodules, and even `--prefer-source` does not guarantee submodule init). Fresh installs have an empty `static/jush/` folder, causing 404s on `<link href='static/jush/jush.css'>` and breaking the editor. **Solution:** vendor jush files in `axenox/ide/Adminer6/assets/jush/` (CSS + all modules), and have `AdminerExfaceDesign` plugin own the loading via `head()` and `syntaxHighlighting()` overrides (point at `exface/jush/…` URLs served by the existing `exface/` prefix). Both overrides must `return true` to short-circuit the core hooks. This keeps the fork **byte-for-byte identical to upstream**, so future merges stay clean. |
| `plugins/disable-jush.php` | **DROP** | Only existed to disable jush when ACE autocomplete was enabled. No longer relevant. |
| `externals/ace/*` | **DROP** | ACE editor removed in 6.x. |
| `plugins/save-menu-pos.php` | **DROP** | Menu position persistence not a priority; not a stock plugin. Drop. |

### 2.2 Integration / session / errors (marked `MOD exface`)

| Old change | Action | Notes |
|------------|--------|-------|
| `auth.inc.php` – disable `session_regenerate_id()` | **PATCH** | Still present in 6.x (`auth.inc.php` lines ~133 and ~168). Adminer runs inside the workbench session, so regeneration must still be suppressed. No hook exists → minimal, clearly-marked core patch. Investigate whether `restart_session()` can be neutralized at the integration layer instead. |
| `bootstrap.inc.php` – disable `error_reporting()` + `set_error_handler()` | **VERIFY → PATCH** | Error handling moved to `include/errors.inc.php` (`error_reporting(24575)` + custom handler). Re-check whether it interferes with the workbench; if so, neutralize there with a marked patch. |

### 2.3 MS SQL driver (`drivers/mssql.inc.php`)

The 6.x driver is fully rewritten and already has `schemas()`, `foreign_keys()`,
`foreign_keys_sql()`, proper view/DDL export and typed methods. **Verify each fix against the
new driver before porting** – most are already upstream.

| Old change | Action | Notes |
|------------|--------|-------|
| Schemas other than `dbo` (driver, schema diagram, FKs) | **VERIFY** | `schemas()` + `foreign_keys()` are built in. Almost certainly upstream. |
| FK display on non-`dbo` schemas | **VERIFY** | Covered by `foreign_keys()`. |
| `nvarchar`/`ntext` length shown doubled | **VERIFY** | Re-test; likely fixed. |
| Binary numbers not displayed | **VERIFY** | Re-test. |
| Escape curly braces | **VERIFY** | Re-test. |
| MS SQL connection options | **PLUGIN/VERIFY** | Our connection-option passthrough may still be needed for the ExFace connection config; if so implement in the integration/driver subclass. |
| Missing error messages | **VERIFY** | Re-test. |
| Custom queries in SQL admin | **VERIFY** | Re-test. |
| Copy tables incl. pkey, no data; no duplicate constraints | **PATCH** | Part of the required copy-without-data feature (see §2.4). Skip the data `INSERT` and keep the duplicate-constraint-name handling. |
| Export as SQL / DDL with `DROP+CREATE` | **VERIFY** | `foreign_keys_sql()` + dump improvements are upstream; re-test DDL export used by `AdminerAPI::exportDDL()`. |
| Drop table/column with constraints | **VERIFY** | Re-test. |
| View definitions truncated | **VERIFY** | Re-test. |
| `IDENTITY()` start at 1000 / setter | **DECIDE** | This was our business rule, not a bug fix. Re-implement only if still required, as a driver subclass. |
| Improved `EXPLAIN` / `FakeResult` | **VERIFY** | Re-test the SQL output; the refactor was around 4.8.2 internals that no longer exist. |
| Workbench cache for large DBs | **PLUGIN** | Re-implement the `$workbench->getCache()` lookup **without** editing the core driver – e.g. a thin driver subclass or a cache injected by the integration layer. Do **not** add a `global $workbench` to the core file. |
| `plugins/drivers/mssql-mod.php` (2nd MS SQL driver) | **DROP** | The stock `mssql` driver supersedes it. |

### 2.4 Other core edits

| Old change | Action | Notes |
|------------|--------|-------|
| `mysql.inc.php` `copy_tables()` **without data** | **PATCH** | **Required feature – keep it.** 6.x still runs `INSERT … SELECT` (mysql line ~912; same for pgsql/mssql). Re-apply as a marked driver patch that skips the data `INSERT`. Preferred, upstream-friendly form: add a "copy data" checkbox to the copy form (default off for us) and guard the `INSERT` with it, so the patch is small and contributable. Apply consistently across `mysql`, `pgsql` and `mssql` drivers. |
| `pgsql.inc.php` copy tables | **PATCH** | Same copy-without-data behaviour as MySQL (see above). |
| `foreign.inc.php` + `editing.inc.php` custom FK syntax | **VERIFY** | 6.x `edit_type()` takes `$foreign_keys` and there is an overridable `Adminer::foreignKeys()`. Likely achievable via override instead of a core edit. |
| `create.inc.php` nav tabs / navigation fixes | **VERIFY** | UI restructured; re-test whether the problems still exist before porting. |
| `view.inc.php` nav tabs | **VERIFY** | Same. |
| `functions.inc.php` tab navigation | **VERIFY** | Same. |
| `adminer.inc.php` remove "New item" link for views | **PLUGIN** | Override `Adminer::navigation()` in a plugin instead of editing core. |
| `adminer.inc.php` / `design.inc.php` reading-only mode | **PLUGIN/DECIDE** | Re-implement as a plugin (`head()`/`css()`/`navigation()` overrides) if still needed. |
| `lang/de.inc.php` translation fix | **DROP/VERIFY** | Almost certainly upstream; re-test the German string. |

### 2.5 ER diagram (custom Mermaid feature)

**Decision: keep it.** The Mermaid ER diagram is still required (interactive pan/zoom and
clickable links go beyond the built-in `schema.inc.php` visual schema, which stays available
alongside it).

| Old change | Action | Notes |
|------------|--------|-------|
| `adminer/erdiagram.inc.php` + `erdiagram=` route + nav link | **PLUGIN** | Re-implement as **our own plugin** rather than a new core file + `index.php`/`adminer.inc.php` edits: register an extra `erdiagram` page/route and add the nav entry via an `Adminer::navigation()` override. Port the diagram-building PHP from `erdiagram.inc.php` and adapt the schema/foreign-key lookups to the 6.x driver API (`schemas()`, `foreign_keys()`). |
| `externals/mermaid/*` (`mermaid.min.js`, `svg-pan-zoom.min.js`) | **KEEP** | Ship these assets with the plugin. |
| `externals/vuerd*` (already removed) | **DROP** | Already deleted in `24ed9a5`; do not port. |

### 2.6 Plugins & theme

| Old change | Action | Notes |
|------------|--------|-------|
| `tables-filter-mod.php` | **STOCK** | Use the stock `AdminerTablesFilter` (`plugins/tables-filter.php`). Drop our fork unless a specific extra behaviour is missing. |
| `tree-viewer.php` + `tree-viewer/` | **PLUGIN** | No stock equivalent. Port to `class AdminerTreeViewer extends Adminer\Plugin`, adapt JS to 6.x markup. Evaluate whether `menu-links` covers the need first. |
| `frames.php` usage | **STOCK** | Use stock `AdminerFrames`. |
| `designs/exface/adminer.css` (ExFace theme) | **PLUGIN** | Keep the theme in `axenox/ide/Adminer6/assets/adminer.css` and load it via `AdminerExfaceDesign::css()` override (returns array with `exface/adminer.css` URL, served by AdminerAPI's `exface/` prefix). Reconcile CSS selectors with the new 6.x HTML/classes. The `/* MOD exface */` tweaks (tab styling, sticky SQL headers, duplicated logo fix, table-list button spacing) have been verified and fixed for 6.x pepa-linha theme baseline. |
| MySQL SSL certificates | **STOCK/VERIFY** | Stock `AdminerLoginSsl` exists. Verify it accepts key/cert/ca paths the way `AdminerAPI::getAdminerAuth()` provides them. |

## 3. Integration layer changes (`axenox.IDE`, outside the fork)

These are not edits to Adminer but are required to run the 6.x fork:

1. **`Adminer6/adminer.php` wrapper** – bootstrap the plugin stack with 6.x class names:
   - Keep: `AdminerLoginPasswordLess`, `AdminerTablesFilter`, `AdminerFrames`, `AdminerLoginSsl`.
   - Drop: `AdminerHighlightMonaco` (Monaco has no SQL autocomplete; use the built-in jush editor instead).
   - Keep: `AdminerExfaceDesign` (now owns both the ExFace theme CSS and jush editor loading via
     `head()` and `syntaxHighlighting()` overrides to serve vendored jush from `Adminer6/assets/jush/`).
   - Port: `AdminerTreeViewer`, Mermaid ER-diagram plugin.
   - Note: the plugin bootstrap uses namespaced `Adminer\Plugin` and the `Plugins` manager.
2. **`Common/AdminerAPI.php`**:
   - Jush asset serving: no special routing needed. `AdminerExfaceDesign` loads jush from our
     vendored copy under the existing `exface/` prefix (served by the `startsWith('exface/')`
     case in `runAdminer()`). The fork's empty `static/jush/` folder is never accessed.
   - `getAdminerDriver()` mapping – the MS SQL driver key is now **`mssql`** (drop the
     `mssql_mod` custom driver).
   - Re-verify the `$_POST['auth']` array shape against 6.x `auth.inc.php`.
   - Re-verify `exportDDL()` / `runSql()` – they call driver functions
     (`create_sql()`, `view()`, `get_rows()`, `table_status()`) that may have changed
     names/signatures in 6.x.
   - Confirm the `runAdminer()` file-serving paths still match the fork's folder layout.
3. **`composer.json`** – add `axenox/adminer` as a dependency; once the integration is
   verified, remove the vendored `Adminer/` tree from `axenox.IDE`.
4. Confirm the fork ships **uncompiled** source (it does) so the IDE can keep including
   individual files for debugging, per the project requirement (no build step after
   `composer install`).

## 4. Suggested execution order

| Step | Action | Adminer 6.0.1 | Adminneo 5.6.0 |
|------|--------|---------------|----------------|
| 1 | **Add the dependency**: pull the fork into the installation via Composer; get a bare, unmodified build running behind the IDE facade (update `AdminerAPI` paths + driver mapping + auth array). Confirm login and basic browsing work for MySQL and MS SQL. | Done | |
| 2 | **Wire the stock plugins** in `adminer.php` (tables-filter, frames, login-ssl, login-password-less, database-hide). DO NOT enable `highlight-monaco` — use the built-in jush editor for full SQL highlighting + autocomplete. Ensure `AdminerExfaceDesign` loads the vendored jush assets from `Adminer6/assets/jush/` via `head()` and `syntaxHighlighting()` overrides. | Done. But jush files had to be included in axenox/ide package. | |
| 3 | **Re-apply the two `MOD exface` neutralizations** (session/errors) as marked core patches; verify sessions survive. | Done | |
| 4 | **Port the ExFace theme** into `designs/` and load it; fix CSS against the new DOM. | Done. But the theme has lots of smaller issues. A lot of our 4.x improvements probably need to be hacked into v6 if we want them. | |
| 5 | **Re-verify every MS SQL item** in §2.3 one by one; only port what is still broken, using a driver subclass (never `global $workbench` in core). | | |
| 6 | **Re-apply copy-without-data** (§2.4) across the mysql/pgsql/mssql drivers, guarded by a "copy data" checkbox (default off). | | |
| 7 | **Decide** on the `IDENTITY()` business rule; implement via a driver subclass if needed. | | |
| 8 | Remove the vendored `Adminer/` folder from `axenox.IDE`. | | |
| 9 | **Port `AdminerTreeViewer`** to the new plugin base class. | | |
| 10 | **Port the Mermaid ER diagram** (§2.5) as a plugin with its own route, nav entry and bundled `mermaid`/`svg-pan-zoom` assets. | | |

## 5. Test checklist (per connection type: MySQL/MariaDB, MS SQL, PostgreSQL)

- Login via the ExFace data connection (incl. password-less and SSL).
- Browse tables/views, edit a row, run a custom SQL query, view SQL output.
- `AdminerAPI::exportDDL()` for a table and a view (incl. `DROP+CREATE`).
- `AdminerAPI::runSql()` returns rows.
- Copy a table (confirm data / no-data behaviour matches the decision).
- Schema visualization (built-in and/or Mermaid ER diagram).
- ExFace theme renders correctly; table-list filter and frames work.
- Sessions persist (no logout loop from `session_regenerate_id`).

## 6. Mapping to `Changes.md`

Every row in [Changes.md](Changes.md) is covered above. Quick index:

- Section 1.1 (session/errors) → §2.2
- Section 1.2 (MS SQL) → §2.3
- Section 1.3 (other core) → §2.4
- Section 2 (Mermaid ER diagram) → §2.5
- Section 3 (plugins) → §2.1 and §2.6
- Section 4 (ExFace theme) → §2.6
- Section 5 (externals) → §2.1 (ace), §2.5 (mermaid)
- Section 6 (integration layer) → §3
