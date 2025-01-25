<?php
page_header(lang('Database schema'), "", array(), h(DB . ($_GET["ns"] ? ".$_GET[ns]" : "")));

function find_field(array $table, string $fieldName)
{
    foreach ($table['fields'] as $field) {
        if ($field["field"] === $fieldName) {
            return $field;
        }
    }
    return null;
}

function transliterate(string $string, string $translitRules = ':: Any-Latin; :: Latin-ASCII;', int $direction = Transliterator::FORWARD) : string
{
	if ($string === '') {
		return $string;
	}
	$transliterator = \Transliterator::createFromRules($translitRules);
	$result = $transliterator->transliterate($string);
	// Alternative with slightly different syntax. Need testing to find out, which is better
	// $result = transliterator_transliterate($translitRules, 'Any-Latin; Latin-ASCII;');
	if ($result === false) {
		throw new \RuntimeException('Cannot transliterate "' . mb_substr($string, 0, 100) . '": ' . $transliterator->getErrorMessage());
	}
	return $result;
}

$schema = array(); // table => array("fields" => array(name => field), "pos" => array(top, left), "references" => array(table => array(left => array(source, target))))
foreach (table_status('', true) as $table => $table_status) {
	if (is_view($table_status)) {
		continue;
	}
	$schema[$table]["fields"] = array();
	$schema[$table]["mermaid_alias"] = transliterate($table);
	foreach (fields($table) as $name => $field) {
		$schema[$table]["fields"][$name] = $field;
		$schema[$table]["fields"][$name]["mermaid_alias"] = transliterate($field["field"]);
	}
	foreach ($adminer->foreignKeys($table) as $val) {
		if (!$val["db"]) {
			$schema[$table]["references"][$val["table"]][] = array($val["source"], $val["target"]);
		}
	}
}

$i = 0;
foreach ($schema as $table_name => $table) {
    foreach ($table["fields"] as $field_idx => $field) {
    }
    $i++;
    if ($i > 5) {
        // break;
    }
}

$mermaid = 'erDiagram';
$i = 0;
foreach ($schema as $table_name => $table) {
	$tableRefs = '';
	$tableFkeys = [];
	foreach ((array) $table["references"] as $to_table_name => $refs) {
        foreach ($refs as $ref) {
            $from_table = $table;
            $to_table = $schema[$to_table_name];
            list($from_keys, $to_keys) = $ref;
			$tableFkeys = array_merge($tableFkeys, $from_keys);
            $required = true;
			$refKeys = implode(', ', $from_keys) . ' -> ' . implode(', ', $to_keys);
			
            foreach ($from_keys as $from_key) {
                $from_field = find_field($from_table, $from_key);
                if ($from_field !== null) {
                    if ($from_field['null']) {
                        $required = false;
                    }
                }
            }
			$fromEnd = $required ? '||' : '|o';
			$toEnd = 'o{';
			$tableRefs .= <<<MD

			{$table_name} {$fromEnd}--{$toEnd} $to_table_name : "{$refKeys}"
MD;
        }
    }
	$columns = '';
    foreach ($table["fields"] as $field) {
		$keys = [];
		if ($field["primary"]) {
			$keys[] = 'PK';
		}
		if (in_array($field["field"], $tableFkeys)) {
			$keys[] = 'FK';
		}
		$keys = implode(', ', $keys);
		$mermaidType = str_replace(',', '-', $field["full_type"]);
		$columns .= <<<MD

		{$mermaidType} {$field["mermaid_alias"]} {$keys} "{$field["comment"]}"
MD;
    }

	$mermaid .= <<<MD

	{$table['mermaid_alias']} {
        $columns
    }
	$tableRefs
MD;
}

?>
<pre id="schema" class="mermaid"><?php echo $mermaid; ?></pre>
<script<?php echo nonce(); ?> src="../externals/mermaid/mermaid.min.js"></script>
<script<?php echo nonce(); ?> src="../externals/mermaid/svg-pan-zoom.min.js"></script>
<script<?php echo nonce(); ?>>
	mermaid.initialize({
		startOnLoad: false,
		theme: 'default'
	});
	mermaid.run({
		querySelector: '.mermaid',
		postRenderCallback: (id) => {
			const element = document.querySelector('#schema');
			const svg = element.getElementsByTagName('svg')[0];
			var doPan = false;
			var eventsHandler;
			var panZoom;
			var mousepos;
			var sSvgId = id;

			// Set the SVG height explicitly because otherwise panZoom will break it.
			// see https://github.com/bumbu/svg-pan-zoom?tab=readme-ov-file#svg-height-is-broken
			svg.setAttribute("height", svg.height.animVal.value + 'px');

			// Only pan if clicked on an empty space. Click-drag on a node should select text.
			// Idea from here: https://github.com/bumbu/svg-pan-zoom/issues/81
			// TODO It does not seem to work though
			eventsHandler = {
				haltEventListeners: ['mousedown', 'mousemove', 'mouseup'], 
				mouseDownHandler: function (ev) {
					if (ev.target.id === sSvgId) {
						doPan = true;
						mousepos = { x: ev.clientX, y: ev.clientY }
					};
				}, 
				mouseMoveHandler: function (ev) {
					if (doPan) {
						panZoom.panBy({ x: ev.clientX - mousepos.x, y: ev.clientY - mousepos.y });
						mousepos = { x: ev.clientX, y: ev.clientY };
						window.getSelection().removeAllRanges();
					}
				},
				mouseUpHandler: function (ev) {
					doPan = false;
				}, 
				init: function (options) {
					options.svgElement.addEventListener('mousedown', this.mouseDownHandler, false);
					options.svgElement.addEventListener('mousemove', this.mouseMoveHandler, false);
					options.svgElement.addEventListener('mouseup', this.mouseUpHandler, false);
				}, 
				destroy: function (options) {
					options.svgElement.removeEventListener('mousedown', this.mouseDownHandler, false);
					options.svgElement.removeEventListener('mousemove', this.mouseMoveHandler, false);
					options.svgElement.removeEventListener('mouseup', this.mouseUpHandler, false);
				}
			}
			panZoom = svgPanZoom('#' + id, {
				zoomEnabled: true
				, controlIconsEnabled: true
				, fit: 1
				, center: 1
				, customEventsHandler: eventsHandler
				, preventMouseEventsDefault: false
			})
		}
	});
</script>