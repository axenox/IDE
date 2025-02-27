<style>
	.svg-hover-highlight{
		stroke-width: 5px;
	}

	.svg-onclick-highlight{
		stroke-width: 6px;
	}

	.svg-tooltip{
		position: absolute;
		z-index: 5;
		padding: 5px 20px;
		background-color: #f4f8fd;
		color: rgba(33, 33, 33, 1);
		border-radius: 5px;
		border: 3px solid #dae8fa;
		visibility: hidden;
	}

	.svg-tooltip a {
		cursor: pointer;
	}

	.svg-tooltip a:visited{
		color:rgb(55, 115, 183);
	}

	.svg-tooltip a:hover{
		color: #34567c;
	}

	.svg-fullsize{
		height: 80%;
	}

	.svg-tooltip h4{
		color: #34567c;
	}

	.svg-tooltip p{
		color: #34567c;
		font-weight: bold;
	}

	.svg-tooltip button{
		cursor: pointer;
		display: inline-block;
		border: none;
		background-color: #b0c3da;
		padding: 5px 10px;
		margin-right: 10px;
		margin-bottom: 20px;
		color: #1f3a5d;
		border-radius: 3px;
	}

	.svg-tooltip button:hover{
		background-color:rgb(133, 153, 176);
	}

</style>
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
			svg.classList.add("svg-fullsize");
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
						tooltip.style.visibility = 'hidden'; //hide tooltip 
						unhighlightAll(); //remove selections
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

			/*
				Problem: 
					- no option to change/access underlying data  https://github.com/orgs/mermaid-js/discussions/5529

			*/

			const svgParent = svg.getElementsByTagName('g')[0];
			const tooltip = document.createElement('div');
			tooltip.classList.add('svg-tooltip');
			document.body.appendChild(tooltip);
			let highlightedElements = []; 

			//make relationships/paths more easily selectable by adding larger transparent layer
			function addBoundingBorder() {
				const paths = document.querySelectorAll('.er.relationshipLine'); 

				paths.forEach(path => {
					const clone = path.cloneNode(); 
					
					clone.style.stroke = 'transparent';
					clone.style.strokeWidth = '20'; 
					clone.style.fill = 'none';
					clone.removeAttribute('marker-start');
					clone.removeAttribute('marker-end');
					clone.classList.add('er-clone');

					path.parentNode.insertBefore(clone, path.nextSibling);
				});
			}

			addBoundingBorder();


			// On hover -> highlight element
			svgParent.addEventListener('mouseover', function(event) {
				const target = event.target;
				const entityAncestor = target.closest('[id^="entity"]');

				if (target.classList.contains('er-clone')) {
					const originalPath = target.previousElementSibling;  
					
					if (originalPath) {
						originalPath.classList.add('svg-hover-highlight');  //highlight original path, not clone
					}
				}
				else if (entityAncestor) {
					entityAncestor.classList.add('svg-hover-highlight');
				}
			});
			
			// On mouse leave -> remove highlight
			svgParent.addEventListener('mouseout', function(event) {
				const target = event.target;
				const entityAncestor = target.closest('[id^="entity"]');
				const originalPath = target.closest('.er.relationshipLine');

				if (target.classList.contains('er-clone')) {
					const originalPath = target.previousElementSibling;  
					if (originalPath) {
						originalPath.classList.remove('svg-hover-highlight'); 
					}
				} 
				else if (entityAncestor) {
					entityAncestor.classList.remove('svg-hover-highlight');
				}

			});

			// Onclick -> add permanent highlight + show tooltip menu
			svgParent.addEventListener('click', function(event) {
				const target = event.target;
				const entityAncestor = target.closest('[id^="entity"]');
				const originalPath = target.closest('.er.relationshipLine'); 
				
				if (target.classList.contains('er-clone')) {
					const originalPath = target.previousElementSibling;  
					if (originalPath) {
						originalPath.classList.toggle('svg-onclick-highlight');
						highlightedElements.push(originalPath);
						updateTooltipMenu(false, originalPath);
					}
				}
				else if (entityAncestor) {
					entityAncestor.classList.toggle('svg-onclick-highlight');
					updateTooltipMenu(true, entityAncestor);
					highlightedElements.push(entityAncestor);
				}
			});

			function unhighlightAll() {
				highlightedElements.forEach(element => {
					element.classList.remove('svg-onclick-highlight');
				});
				highlightedElements = []; 
			}
 

			function updateTooltipMenu(isEntity, target){
				if (isEntity){
					// ENTITY tooltip/menu
					//console.log("LOC:"+window.location);
					let tableName = target.id.toString().split('-')[1];  // get table name
					let baseUrl = window.location.toString();
					baseUrl = baseUrl.replace("&erdiagram=", "&");

					tooltip.innerHTML = `
						<h4>Selected Entity: ${tableName || "entity"}</h4>
						<p>Actions: </p>
						<ul>
							<li><a href="${baseUrl}select=${tableName}" target="_blank">Select Data</a></li>
							<li><a href="${baseUrl}table=${tableName}" target="_blank">Show Structure</a></li>
							<li><a href="${baseUrl}create=${tableName}" target="_blank">Alter Table</a></li>
						</ul>
					`;
				}
				else{
					// RELATIONSHIP tooltip/menu
					const { startCoordinates, endCoordinates } = getStartAndEndCoordinates(target.getAttribute('d'));
					const lastPath = target;

					//console.log(startCoordinates, endCoordinates);
					tooltip.innerHTML = `
						<h4>Selected Relationship</h4>
						<p>Actions: </p>
						<button type="button" id="panToSrc">Go to Source</button>
						<button type="button" id="panToTarget">Go to Target</button>
					`;
					
					document.getElementById("panToSrc").addEventListener('click', function() {
						//const { startCoordinates, endCoordinates } = getStartAndEndCoordinates(lastPath.getAttribute('d'));
						navigateToCoordinates(endCoordinates.x, endCoordinates.y);
					});

					document.getElementById("panToTarget").addEventListener('click', function() {
						//const { startCoordinates, endCoordinates } = getStartAndEndCoordinates(lastPath.getAttribute('d'));
						navigateToCoordinates(startCoordinates.x, startCoordinates.y);
					});
				}

				// tooltiip position
				const mouseX = event.pageX + 10; 
				const mouseY = event.pageY + 10; 
				tooltip.style.left = `${mouseX}px`;
				tooltip.style.top = `${mouseY}px`;
				tooltip.style.visibility = 'visible';
			}

			/*
				<path style="stroke: gray; fill: none;" marker-start="url(#ZERO_OR_ONE_START)" marker-end="url(#ZERO_OR_MORE_END)" d="M4663.831,2261L4659.861,2289.5C4655.892,2318,4647.953,2375,4678.395,2457.667C4708.837,2540.333,4777.659,2648.667,4812.07,2702.833L4846.481,2757" class="er relationshipLine svg-onclick-highlight"></path>
			*/

			// Function to extract the start and end coordinates of svg path
			function getStartAndEndCoordinates(d) {
				// Split the path data into commands (M, L, C, etc.)
				const commands = d.split(/(?=[MLCSTQAZ])/);  // Handle more commands

				let startCoordinates = null;
				let endCoordinates = null;

				// Parse commands
				commands.forEach((command, index) => {
					// Remove extra spaces and handle each command
					const cleanCommand = command.trim();

					if (cleanCommand.startsWith('M')) {
					// MoveTo command, marks the start of the path
					const start = cleanCommand.slice(1).split(','); // Remove 'M' and split the coordinates
					startCoordinates = { 
						x: parseFloat(start[0]), 
						y: parseFloat(start[1]) 
					};
					} else if (cleanCommand.startsWith('L')) {
					// LineTo command, update the end coordinates
					const end = cleanCommand.slice(1).split(',');
					endCoordinates = { 
						x: parseFloat(end[0]), 
						y: parseFloat(end[1]) 
					};
					} else if (cleanCommand.startsWith('C')) {
					// Cubic Bézier curve (C), extract the last control point as the end
					const controlPoints = cleanCommand.slice(1).split(',');
					endCoordinates = { 
						x: parseFloat(controlPoints[4]),  // 5th value is the end point of the curve
						y: parseFloat(controlPoints[5]) 
					};
					}
				});

				return { startCoordinates, endCoordinates };
			}

			

			function navigateToCoordinates(x, y) {
				// pan to given coords. in svg
				//https://stackoverflow.com/questions/28853055/pan-to-specific-x-and-y-coordinates-and-center-image-svg-pan-zoom
				var rz=panZoom.getSizes().realZoom;
				const sclae = 1;

				panZoom.pan({x: 0, y:0});
				
				panZoom.panBy({  
				x: -(x*rz)+(panZoom.getSizes().width/2),
				y: -(y*rz)+(panZoom.getSizes().height/2)
				});

				panZoom.zoomBy(1.5); //150% zoom - todo adapt (?)
			}



		}
	});

</script>