<?php

/**
 * ExFace theme + jush loader for Adminer 6.x.
 *
 * This plugin does two things, both via overridable {@see Adminer\Adminer} hooks so the fork
 * stays untouched:
 *
 * 1. Injects the ExFace stylesheet via {@see css()}. It is served by
 *    {@see \axenox\IDE\Common\AdminerAPI} from `Adminer6/assets/adminer.css` under the
 *    `exface/` URL prefix, on top of Adminer's built-in `static/default.css`.
 * 2. Loads Adminer's built-in jush editor (SQL syntax highlighting AND autocomplete) from a
 *    copy vendored in `Adminer6/assets/jush/`. Adminer keeps jush as a git submodule, which
 *    Composer does not populate, so the fork's own `static/jush/` folder is empty. The
 *    {@see head()} and {@see syntaxHighlighting()} overrides therefore point at our copy under
 *    the `exface/` prefix instead of the (empty) `static/jush/` path.
 */
class AdminerExfaceDesign extends Adminer\Plugin
{
    /** URL prefix (relative to the Adminer script) under which AdminerAPI serves our assets. */
    const ASSET_URL = 'exface/';

    /**
     * URL of the stylesheet, relative to the Adminer script (api/ide/adminer/).
     *
     * @return string[] key is URL, value is 'light', 'dark' or '' (both)
     */
    function css()
    {
        // Cache-bust using the checksum of the served file.
        $file = __DIR__ . '/../assets/adminer.css';
        $version = file_exists($file) ? crc32(file_get_contents($file)) : '0';
        return array(self::ASSET_URL . 'adminer.css?v=' . $version => 'light');
    }

    /**
     * Load the jush stylesheet from our vendored copy instead of the fork's empty submodule.
     *
     * Mirrors {@see Adminer\Adminer::head()}. Returns true so it short-circuits the core hook,
     * which would otherwise emit `<link>`s to the missing `static/jush/*.css`.
     *
     * @param bool $dark dark CSS: false to disable, true to force, null to base on preferences
     * @return bool true to link favicon.ico
     */
    function head(?bool $dark = null): bool
    {
        echo "<link rel='stylesheet' href='" . self::ASSET_URL . "jush/jush.css'>\n";
        echo ($dark !== false ? "<link rel='stylesheet'" . ($dark ? "" : " media='(prefers-color-scheme: dark)'") . " href='" . self::ASSET_URL . "jush/jush-dark.css'>\n" : "");
        return true;
    }

    /**
     * Load the jush editor modules from our vendored copy and run the stock init logic.
     *
     * Adapted from {@see Adminer\Adminer::syntaxHighlighting()}: the module URLs point at our
     * `exface/jush/modules/` copy, and the driver-specific module is gated on OUR copy (not the
     * fork's empty submodule) so it is always loaded. Everything else (jushLinks, routines, the
     * table/column autocompleter and the init call) is a faithful copy of the core method and
     * must be re-checked when merging upstream Adminer changes.
     *
     * Returns true so it short-circuits the core hook; a void/null return would let the core
     * method also emit its `static/jush/*` tags.
     *
     * @param array $tables TableStatus[] keyed by table name
     * @return bool
     */
    function syntaxHighlighting(array $tables)
    {
        $base = self::ASSET_URL . 'jush/modules/';
        $jush = \Adminer\JUSH;

        echo \Adminer\script_src($base . "jush.js", true);
        echo \Adminer\script_src($base . "jush-autocomplete-sql.js", true);
        echo \Adminer\script_src($base . "jush-textarea.js", true);
        echo \Adminer\script_src($base . "jush-txt.js", true);
        echo \Adminer\script_src($base . "jush-json.js", true);
        // Driver-specific module (e.g. jush-mssql.js). Gated on our vendored copy so it is
        // emitted regardless of whether the fork's submodule was initialised.
        echo (file_exists(__DIR__ . "/../assets/jush/modules/jush-$jush.js") ? \Adminer\script_src($base . "jush-$jush.js", true) : "");

        $module = preg_replace('~<(?=/script)~i', '<\\', \Adminer\Driver::jushModule()); // it would close the inline <script>
        echo ($module ? \Adminer\script("addEventListener('DOMContentLoaded', () => {\n$module\n});") : "");

        if (\Adminer\support("sql")) {
            echo "<script" . \Adminer\nonce() . ">\n";
            if ($tables) {
                $links = array();
                foreach ($tables as $table => $type) {
                    $links[] = \Adminer\js_escape_re($table);
                }
                echo "var jushLinks = { " . $jush . ":";
                \Adminer\json_row(\Adminer\js_escape(\Adminer\ME) . (\Adminer\support("table") ? "table" : "select") . '=$&', '/\b(?<!\$)(' . implode('|', $links) . ')(?!\$)\b/g', false); // $ is used in PostgreSQL as part of name
                // routines() is slow so it is called only on the pages printing SQL where a routine name can appear
                $sql_pages = array("sql", "check", "event", "procedure", "trigger", "view", "type", "table", "processlist"); // ?function= sets ?procedure=
                if (\Adminer\support("routine") && array_intersect_key($_GET, array_flip($sql_pages))) {
                    foreach (\Adminer\routines() as $row) {
                        \Adminer\json_row(\Adminer\js_escape(\Adminer\ME) . 'function=' . \Adminer\url_escape($row["SPECIFIC_NAME"]) . '&name=$&', '/\b' . \Adminer\js_escape_re($row["ROUTINE_NAME"]) . '(?=["`\]]?\()/g', false);
                    }
                }
                \Adminer\json_row('');
                echo "};\n";
                foreach (array("bac", "bra", "sqlite_quo", "mssql_bra") as $val) {
                    echo "jushLinks.$val = jushLinks." . $jush . ";\n";
                }
                if (array_intersect_key($_GET, array_flip(array("sql", "check", "event", "procedure", "trigger", "view")))) { // the pages editing SQL in a <textarea>
                    $statements = (isset($_GET["trigger"]) ? array('INSERT INTO', 'UPDATE', 'DELETE FROM')
                        : (isset($_GET["check"]) ? array()
                        : (isset($_GET["view"]) ? array('SELECT') : null)));
                    $autocomplete = \Adminer\Driver::jushAutocomplete($tables, $statements);
                    echo ($autocomplete ? "addEventListener('DOMContentLoaded', () => { autocompleter = $autocomplete; });\n" : "");
                }
            }
            echo "</script>\n";
        }
        echo \Adminer\script("syntaxHighlighting('" . (preg_match('~^\d\.?\d~', \Adminer\connection()->server_info, $match) ? $match[0] : "") . "', '" . \Adminer\connection()->flavor . "');");
        return true;
    }

    /** @var array<string, array<string, string>> */
    protected $translations = array(
        'de' => array('' => 'ExFace-Design für Adminer'),
    );
}
