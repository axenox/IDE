<?php

namespace AdminNeo;

/**
 * Replaces AdminNeo's draggable schema view with a Pele-rendered Mermaid ER diagram.
 *
 * Pele and svg-pan-zoom are supplied by the host application so the plugin neither downloads
 * assets nor imposes a second library version. Views and cross-database foreign keys are omitted,
 * matching AdminNeo's native schema page.
 */
class MermaidSchemaPlugin extends Plugin
{
    /** Keep generated Mermaid source below Pele's default 50,000 character safety limit. */
    private const MAX_DIAGRAM_TEXT_SIZE = 48000;

    /** Default and user-selectable bounds for recursive foreign-key traversal. */
    private const DEFAULT_RELATION_DEPTH = 1;
    private const MAX_RELATION_DEPTH = 10;

    private string $peleUrl;
    private string $panZoomUrl;

    /**
     * Creates a schema renderer using locally hosted JavaScript distributions.
     */
    public function __construct(string $peleUrl, string $panZoomUrl)
    {
        $this->peleUrl = $peleUrl;
        $this->panZoomUrl = $panZoomUrl;
    }

    /** Loads diagram assets and AdminNeo-themed interaction styles and table actions. */
    public function printToHead(): void
    {
        if (!isset($_GET['schema'])) {
            $tableName = $_GET['table'] ?? $_GET['select'] ?? null;
            if ($tableName !== null) {
                echo script($this->openSchemaLinkScript((string) $tableName));
            }
            return;
        }
        echo script_src($this->peleUrl), script_src($this->panZoomUrl);
        echo <<<'HTML'
<style>
.mermaid-schema-filter .fieldset-content { display: flex; flex-wrap: wrap; align-items: end; gap: .75rem; }
.mermaid-schema-filter label { display: grid; gap: .25rem; }
.mermaid-schema-filter input[type="search"] { min-width: min(26rem, 55vw); }
.mermaid-schema-filter input[type="number"] { width: 6rem; }
.mermaid-schema-filter input[type="submit"] { margin-bottom: 3px; }
.mermaid-schema { position: relative; height: calc(100vh - 13rem); min-height: 30rem; overflow: visible; }
.mermaid-schema > svg { width: 100%; height: 100%; }
.mermaid-schema .pele { --pele-bg: var(--table-bg); --pele-fg: var(--body-text); --pele-muted: var(--note-text); --pele-line: var(--body-text); --pele-surface: var(--table-header-bg); --pele-border: var(--table-border); --pele-radius: 0px; }
.mermaid-schema .pele-entity > .pele-label { fill: var(--link-text); }
.mermaid-schema .pele-er-stripes { display: none; }
.mermaid-schema .schema-row { fill: var(--table-bg); }
.mermaid-schema .schema-row-border { stroke: var(--table-row-border); stroke-width: 1px; }
.mermaid-schema .pele-entity.svg-hover-highlight .schema-row { fill: var(--list-hover); }
.mermaid-schema .pele-entity.svg-selected .schema-row { fill: var(--list-active); }
.mermaid-schema .pele-entity.svg-selected.svg-hover-highlight .schema-row { fill: var(--list-active-hover); }
.mermaid-schema .pele-entity { cursor: pointer; }
.mermaid-schema path.svg-hover-highlight { stroke-width: 4px !important; }
.mermaid-schema path.svg-selected { stroke-width: 6px !important; }
.mermaid-schema-menu { position: fixed; z-index: 20; min-width: 14rem; padding: .75rem 1rem; background: var(--panel-bg); color: var(--body-text); border: 1px solid var(--panel-border); border-radius: var(--box-border-radius); box-shadow: 0 4px 18px rgba(0,0,0,.2); }
.mermaid-schema-menu h3 { margin: 0 0 .5rem; color: var(--header-text); font-size: 1rem; }
.mermaid-schema-menu p { margin: .4rem 0; color: var(--note-text); }
.mermaid-schema-menu ul { margin: 0; padding-left: 1.25rem; }
.mermaid-schema-menu .button { margin: .3rem .4rem 0 0; }
.mermaid-schema-error { padding: 1rem; color: var(--message-error-text); background: var(--message-error-bg); border: 1px solid var(--message-error-border); }
</style>
HTML;
    }

    /** Collects table metadata and prints the Mermaid diagram plus its interaction controller. */
    public function printDatabaseSchema(): ?bool
    {
        $filter = trim((string) ($_GET['schema-filter'] ?? ''));
        $depth = ($_GET['schema-depth'] ?? '') === '' ? self::DEFAULT_RELATION_DEPTH : (int) $_GET['schema-depth'];
        $depth = max(0, min(self::MAX_RELATION_DEPTH, $depth));

        $tables = [];
        $allFields = Driver::get()->getAllFields();
        foreach (table_status('', true) as $tableName => $status) {
            if (is_view($status)) continue;
            $tables[$tableName] = ['id' => self::identifier('t', $tableName), 'name' => $tableName, 'fields' => $allFields[$tableName] ?? [], 'relations' => []];
        }

        foreach ($tables as $tableName => &$table) {
            $fieldsByName = [];
            foreach ($table['fields'] as $field) $fieldsByName[$field['field']] = $field;
            foreach ($this->admin->getForeignKeys($tableName) as $foreignKey) {
                $target = $foreignKey['table'] ?? '';
                // Drivers may qualify local keys with the current catalog/schema. Only omit keys
                // that really leave the schema represented by this diagram.
                if (!self::isLocalForeignKey($foreignKey) || !isset($tables[$target])) continue;
                $nullable = false;
                foreach ((array) $foreignKey['source'] as $source) if (!empty($fieldsByName[$source]['null'])) $nullable = true;
                $table['relations'][] = ['target' => $target, 'sourceColumns' => array_values((array) $foreignKey['source']), 'targetColumns' => array_values((array) $foreignKey['target']), 'nullable' => $nullable];
            }
        }
        unset($table);

        // Discover related tables against the complete graph before pruning edges to the subset
        // that Mermaid will render.
        $tables = self::filterRelatedTables($tables, $filter, $depth);
        foreach ($tables as &$table) {
            $table['relations'] = array_values(array_filter($table['relations'], function (array $relation) use ($tables): bool {
                return isset($tables[$relation['target']]);
            }));
        }
        unset($table);

        echo '<form class="mermaid-schema-filter" action="', h(BASE_URL), '" method="get">';
        hidden_fields_get();
        echo input_hidden('db', DB), input_hidden('ns', $_GET['ns'] ?? ''), input_hidden('schema', '');
        // Reuse the database overview's fieldset classes for consistent themed framing.
        echo '<div class="field-sets"><fieldset><legend>Filter tables</legend><div class="fieldset-content">';
        echo '<label>', lang('Table'), '<input type="search" class="input" name="schema-filter" value="', h($filter), '" autofocus></label>';
        echo '<label>Relation depth<input type="number" class="input" name="schema-depth" value="', $depth, '" min="0" max="', self::MAX_RELATION_DEPTH, '"></label>';
        echo '<input type="submit" class="button" value="', lang('Search'), '"></div></fieldset></div></form>';

        if (!$tables) {
            echo '<p class="message">', lang('No tables.'), '</p>';
            return true;
        }

        $lines = ['erDiagram'];
        $commentLines = [];
        foreach ($tables as $tableName => $table) {
            $lines[] = '    ' . $table['id'] . '["' . self::mermaidText($table['name']) . '"] {';
            $foreignColumns = [];
            foreach ($table['relations'] as $relation) $foreignColumns = array_merge($foreignColumns, $relation['sourceColumns']);
            foreach ($table['fields'] as $field) {
                $keys = [];
                if (!empty($field['primary'])) $keys[] = 'PK';
                if (in_array($field['field'], $foreignColumns, true)) $keys[] = 'FK';
                $type = self::mermaidType($field['full_type'] ?? $field['type'] ?? 'value');
                $name = self::mermaidToken($field['field']);
                $comment = trim(($field['field'] !== $name ? $field['field'] . ' ' : '') . ($field['comment'] ?? ''));
                $line = "        $type $name" . ($keys ? ' ' . implode(',', $keys) : '');
                if ($comment !== '') {
                    $commentLines[count($lines)] = ['base' => $line, 'comment' => $comment, 'table' => $tableName, 'field' => $field['field']];
                    $line .= ' "' . self::mermaidText($comment) . '"';
                }
                $lines[] = $line;
            }
            $lines[] = '    }';
        }

        $relations = [];
        foreach ($tables as $table) {
            foreach ($table['relations'] as $relation) {
                // The source is the child/many side. Its FK points to exactly one or optionally one target.
                $targetEnd = $relation['nullable'] ? 'o|' : '||';
                $label = implode(', ', $relation['sourceColumns']) . ' → ' . implode(', ', $relation['targetColumns']);
                $lines[] = '    ' . $table['id'] . ' }o--' . $targetEnd . ' ' . $tables[$relation['target']]['id'] . ' : "' . self::mermaidText($label) . '"';
                $relations[] = ['source' => $table['id'], 'target' => $tables[$relation['target']]['id'], 'label' => $label];
            }
        }

        // Pele rejects the complete diagram definition (not an individual label) above its
        // default 50,000 character limit. Reduce the longest optional column descriptions first,
        // while retaining their full values in metadata for SVG tooltips.
        $diagramText = implode("\n", $lines);
        uasort($commentLines, function (array $left, array $right): int {
            return strlen(self::mermaidText($right['comment'])) <=> strlen(self::mermaidText($left['comment']));
        });
        foreach ($commentLines as $lineNumber => $commentLine) {
            if (strlen($diagramText) <= self::MAX_DIAGRAM_TEXT_SIZE) break;
            $excess = strlen($diagramText) - self::MAX_DIAGRAM_TEXT_SIZE;
            $escapedLength = strlen(self::mermaidText($commentLine['comment']));
            $shortened = self::truncateMermaidText($commentLine['comment'], max(3, $escapedLength - $excess));
            if ($shortened === $commentLine['comment']) continue;
            $lines[$lineNumber] = $commentLine['base'] . ' "' . self::mermaidText($shortened) . '"';
            $tables[$commentLine['table']]['truncatedFields'][] = $commentLine['field'];
            $diagramText = implode("\n", $lines);
        }

        $metadata = ['tables' => array_values($tables), 'relations' => $relations];
        echo '<div id="mermaid-schema" class="mermaid-schema"><pre class="mermaid">', h($diagramText), '</pre></div>';
        echo '<div id="mermaid-schema-menu" class="mermaid-schema-menu hidden"></div>';
        echo '<script type="application/json" id="mermaid-schema-data">', self::jsonForHtml($metadata), '</script>';
        echo script($this->clientScript());
        return true;
    }

    /** Returns whether a foreign key points to the database and schema currently being rendered. */
    private static function isLocalForeignKey(array $foreignKey): bool
    {
        $database = (string) ($foreignKey['db'] ?? '');
        if ($database !== '' && strcasecmp($database, DB) !== 0) return false;

        $schema = (string) ($foreignKey['ns'] ?? '');
        $currentSchema = (string) ($_GET['ns'] ?? '');
        return $schema === '' || $currentSchema === '' || strcasecmp($schema, $currentSchema) === 0;
    }

    /** Keeps name matches and every table reachable through foreign keys up to the given depth. */
    private static function filterRelatedTables(array $tables, string $filter, int $depth): array
    {
        if ($filter === '') return $tables;

        // Treat foreign keys as undirected for discovery: users need both referenced tables and
        // tables that reference a name match.
        $adjacent = array_fill_keys(array_keys($tables), []);
        foreach ($tables as $tableName => $table) {
            foreach ($table['relations'] as $relation) {
                $target = $relation['target'];
                if (!isset($adjacent[$target])) continue;
                $adjacent[$tableName][$target] = true;
                $adjacent[$target][$tableName] = true;
            }
        }

        $included = [];
        $queue = [];
        foreach ($tables as $tableName => $table) {
            if (stripos($tableName, $filter) === false) continue;
            $included[$tableName] = 0;
            $queue[] = $tableName;
        }

        // Breadth-first traversal assigns the shortest relation distance from any name match.
        for ($offset = 0; isset($queue[$offset]); $offset++) {
            $tableName = $queue[$offset];
            $distance = $included[$tableName];
            if ($distance >= $depth) continue;
            foreach ($adjacent[$tableName] as $relatedName => $unused) {
                if (isset($included[$relatedName])) continue;
                $included[$relatedName] = $distance + 1;
                $queue[] = $relatedName;
            }
        }

        return array_intersect_key($tables, $included);
    }

    /** Converts arbitrary database identifiers to stable Mermaid grammar identifiers. */
    public static function identifier(string $prefix, string $value): string { return $prefix . '_' . substr(sha1($value), 0, 16); }

    /** Converts a value to a Mermaid-safe unquoted token while preserving uniqueness. */
    private static function mermaidToken(string $value): string
    {
        $readable = preg_replace('/[^A-Za-z0-9_]/', '_', $value);
        if ($readable === '' || ctype_digit($readable[0])) $readable = 'v_' . $readable;
        return substr($readable, 0, 40) . '_' . substr(sha1($value), 0, 8);
    }

    /**
     * Keeps type parameters readable without adding identifier uniqueness hashes.
     */
    private static function mermaidType(string $value): string
    {
        $type = preg_replace('/[^A-Za-z0-9_(),\[\].-]/', '_', $value);
        return $type === '' || !ctype_alpha($type[0]) ? 'v_' . $type : $type;
    }

    /** Escapes text embedded in a Mermaid quoted label or comment. */
    private static function mermaidText(string $value): string { return str_replace(["\\", '"', "\r", "\n"], ['\\\\', '&quot;', ' ', ' '], $value); }

    /** Truncates Unicode text to an escaped byte budget and marks the omitted part. */
    private static function truncateMermaidText(string $value, int $maxBytes): string
    {
        if (strlen(self::mermaidText($value)) <= $maxBytes) return $value;
        $characters = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);
        if ($characters === false) return '...';
        $low = 0;
        $high = count($characters);
        while ($low < $high) {
            $length = (int) ceil(($low + $high) / 2);
            $candidate = implode('', array_slice($characters, 0, $length)) . '...';
            if (strlen(self::mermaidText($candidate)) <= $maxBytes) $low = $length;
            else $high = $length - 1;
        }
        return implode('', array_slice($characters, 0, $low)) . '...';
    }

    /** Encodes metadata safely inside a non-executable HTML script element. */
    private static function jsonForHtml($value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    }

    /** Returns a controller that adds a filtered schema link to native table tabs. */
    private function openSchemaLinkScript(string $tableName): string
    {
        $icon = icon('schema');
        $label = 'Open schema';
        $tableJson = self::jsonForHtml($tableName);
        $contentJson = self::jsonForHtml($icon . $label);
        $defaultDepth = self::DEFAULT_RELATION_DEPTH;
        return <<<JS
addEventListener('DOMContentLoaded', () => {
    const tabs = document.querySelector('.top-tabs');
    if (!tabs) return;
    // AdminNeo has no hook for appending one native table tab, so augment the rendered menu while
    // retaining its ordering, icons and help link.
    const url = new URL(location.href);
    ['table', 'select', 'create', 'view', 'edit'].forEach(key => url.searchParams.delete(key));
    url.searchParams.set('schema', '');
    url.searchParams.set('schema-filter', $tableJson);
    url.searchParams.set('schema-depth', '$defaultDepth');
    const link = document.createElement('a');
    link.href = url.href;
    link.innerHTML = $contentJson;
    const help = tabs.querySelector('a[target="_blank"]');
    tabs.insertBefore(document.createTextNode(' '), help);
    tabs.insertBefore(link, help);
});
JS;
    }

    /**
     * Returns the browser controller for rendering, pan/zoom, selection and context actions.
     */
    private function clientScript(): string
    {
        return <<<'JS'
(async () => {
    const host = document.getElementById('mermaid-schema');
    const menu = document.getElementById('mermaid-schema-menu');
    const metadata = JSON.parse(document.getElementById('mermaid-schema-data').textContent);
    const showError = error => host.replaceChildren(Object.assign(document.createElement('div'), {className: 'mermaid-schema-error', textContent: 'The database diagram could not be rendered: ' + (error.message || error)}));
    try {
        const source = host.querySelector('.mermaid').textContent;
        host.innerHTML = pele.render(source, {idPrefix: 'adminneo-schema', limit: Math.max(50000, source.length + 1), autoDirection: false, responsive: false, fontSize: 14, fontFamily: getComputedStyle(host).fontFamily, links: false, images: false}).svg;
        const svg = host.querySelector('svg');
        if (!svg) throw new Error('Pele did not create an SVG element.');
        // Pele lays out SVG text rather than HTML cells. Derive row borders from the rendered
        // baselines so wrapped comments and varying attribute counts keep their layout.
        svg.querySelectorAll('.pele-entity').forEach(node => {
            const attributes = node.querySelector('.pele-er-attributes');
            if (!attributes) return;
            const outline = node.querySelector('rect[fill="none"]');
            const divider = node.querySelector('.pele-er-divider');
            const rows = Array.from(attributes.querySelectorAll('.pele-er-name'));
            const backgrounds = document.createElementNS(svg.namespaceURI, 'g');
            const x = outline.x.baseVal.value, width = outline.width.baseVal.value;
            const bottom = outline.y.baseVal.value + outline.height.baseVal.value;
            let top = divider.getBBox().y;
            const baselineOffset = rows.length ? rows[0].y.baseVal[0].value - top : 0;
            const body = document.createElementNS(svg.namespaceURI, 'rect');
            body.setAttribute('x', x);
            body.setAttribute('y', top);
            body.setAttribute('width', width);
            body.setAttribute('height', bottom - top);
            body.setAttribute('fill', 'var(--table-bg)');
            backgrounds.appendChild(body);
            rows.forEach((row, index) => {
                const next = rows[index + 1];
                const end = next ? next.y.baseVal[0].value - baselineOffset : bottom;
                const rect = document.createElementNS(svg.namespaceURI, 'rect');
                rect.setAttribute('class', 'schema-row');
                rect.setAttribute('x', x);
                rect.setAttribute('y', top);
                rect.setAttribute('width', width);
                rect.setAttribute('height', end - top);
                backgrounds.appendChild(rect);
                if (next) {
                    const border = document.createElementNS(svg.namespaceURI, 'path');
                    border.setAttribute('class', 'schema-row-border');
                    border.setAttribute('d', `M${x},${end}h${width}`);
                    backgrounds.appendChild(border);
                }
                top = end;
            });
            node.insertBefore(backgrounds, divider);
        });
        svg.setAttribute('height', host.clientHeight + 'px');
        svg.style.maxWidth = 'none';
        const panZoom = svgPanZoom(svg, {zoomEnabled: true, controlIconsEnabled: true, fit: true, center: true, preventMouseEventsDefault: false});
        const selected = new Set();
        const clearSelection = () => { selected.forEach(node => node.classList.remove('svg-selected')); selected.clear(); menu.classList.add('hidden'); };
        const placeMenu = event => { menu.style.left = Math.min(event.clientX + 10, innerWidth - 250) + 'px'; menu.style.top = Math.min(event.clientY + 10, innerHeight - 180) + 'px'; menu.classList.remove('hidden'); };
        const addText = (parent, tag, text) => { const child = document.createElement(tag); child.textContent = text; parent.appendChild(child); return child; };
        const actionUrl = (action, table) => { const url = new URL(location.href); ['schema', 'table', 'select', 'create'].forEach(key => url.searchParams.delete(key)); url.searchParams.set(action, table); return url.href; };
        const centerNode = node => { const box = node.getBBox(), transform = node.transform.baseVal.consolidate(), center = new DOMPoint(box.x + box.width / 2, box.y + box.height / 2).matrixTransform(transform ? transform.matrix : new DOMMatrix()), sizes = panZoom.getSizes(), zoom = sizes.realZoom; panZoom.pan({x: sizes.width / 2 - center.x * zoom, y: sizes.height / 2 - center.y * zoom}); };
        const entityNodes = Array.from(svg.querySelectorAll('.pele-entity[data-id]'));
        entityNodes.forEach(node => {
            const table = metadata.tables.find(candidate => node.dataset.id === candidate.id);
            if (!table) return;
            if (table.truncatedFields && table.truncatedFields.length) {
                const descriptions = table.fields.filter(field => table.truncatedFields.includes(field.field)).map(field => field.field + ': ' + (field.comment || ''));
                const title = document.createElementNS('http://www.w3.org/2000/svg', 'title');
                title.textContent = descriptions.join('\n');
                node.insertBefore(title, node.firstChild);
            }
            node.addEventListener('mouseenter', () => node.classList.add('svg-hover-highlight'));
            node.addEventListener('mouseleave', () => node.classList.remove('svg-hover-highlight'));
            node.addEventListener('click', event => {
                event.stopPropagation(); clearSelection(); node.classList.add('svg-selected'); selected.add(node); menu.replaceChildren();
                addText(menu, 'h3', table.name); addText(menu, 'p', 'Actions');
                const list = document.createElement('ul');
                [['select', 'Select data'], ['table', 'Show structure'], ['create', 'Alter table']].forEach(action => { const item = document.createElement('li'); const link = document.createElement('a'); link.textContent = action[1]; link.href = actionUrl(action[0], table.name); link.target = '_blank'; item.appendChild(link); list.appendChild(item); });
                menu.appendChild(list); placeMenu(event);
            });
        });
        Array.from(svg.querySelectorAll('.pele-edges > .pele-edge > path:not(.pele-marker)')).forEach((path, index) => {
            const hit = path.cloneNode(false); hit.removeAttribute('marker-start'); hit.removeAttribute('marker-end'); hit.style.cssText = 'stroke:transparent;stroke-width:20;fill:none;pointer-events:stroke'; path.after(hit);
            hit.addEventListener('mouseenter', () => path.classList.add('svg-hover-highlight'));
            hit.addEventListener('mouseleave', () => path.classList.remove('svg-hover-highlight'));
            hit.addEventListener('click', event => {
                event.stopPropagation(); clearSelection(); path.classList.add('svg-selected'); selected.add(path); menu.replaceChildren();
                const relation = metadata.relations[index]; addText(menu, 'h3', 'Relationship'); addText(menu, 'p', relation ? relation.label : '');
                [['Source', relation && relation.source], ['Target', relation && relation.target]].forEach(action => { const button = addText(menu, 'button', 'Go to ' + action[0]); button.type = 'button'; button.className = 'button'; button.addEventListener('click', () => { const target = entityNodes.find(node => node.dataset.id === action[1]); if (target) centerNode(target); }); });
                placeMenu(event);
            });
        });
        host.addEventListener('click', event => { if (event.target === host || event.target === svg) clearSelection(); });
        addEventListener('resize', () => panZoom.resize());
    } catch (error) { showError(error); }
})();
JS;
    }
}
