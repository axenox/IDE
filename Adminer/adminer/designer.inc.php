<?php
page_head_body(lang('Database schema'));

function gen_uuid() {
    $uuid = array(
        'time_low'  => 0,
        'time_mid'  => 0,
        'time_hi'  => 0,
        'clock_seq_hi' => 0,
        'clock_seq_low' => 0,
        'node'   => array()
    );
    
    $uuid['time_low'] = mt_rand(0, 0xffff) + (mt_rand(0, 0xffff) << 16);
    $uuid['time_mid'] = mt_rand(0, 0xffff);
    $uuid['time_hi'] = (4 << 12) | (mt_rand(0, 0x1000));
    $uuid['clock_seq_hi'] = (1 << 7) | (mt_rand(0, 128));
    $uuid['clock_seq_low'] = mt_rand(0, 255);
    
    for ($i = 0; $i < 6; $i++) {
        $uuid['node'][$i] = mt_rand(0, 255);
    }
    
    $uuid = sprintf('%08x-%04x-%04x-%02x%02x-%02x%02x%02x%02x%02x%02x',
        $uuid['time_low'],
        $uuid['time_mid'],
        $uuid['time_hi'],
        $uuid['clock_seq_hi'],
        $uuid['clock_seq_low'],
        $uuid['node'][0],
        $uuid['node'][1],
        $uuid['node'][2],
        $uuid['node'][3],
        $uuid['node'][4],
        $uuid['node'][5]
        );
    
    return $uuid;
}

function find_field(array $table, string $fieldName)
{
    foreach ($table['fields'] as $field) {
        if ($field["field"] === $fieldName) {
            return $field;
        }
    }
    return null;
}

function find_field_uuid(array $table, string $fieldName) : ?string
{
    $field = find_field($table, $fieldName);
    return $field === null ? null : $field['vuerd_uuid'];
}

$mode = $_GET['designer'] !== null ? 'designer' : 'viewer';
$diagram = $_GET[$mode];

$schema = array(); // table => array("fields" => array(name => field), "references" => array(table => array(left => array(source, target))))
foreach (table_status('', true) as $table => $table_status) {
	if (is_view($table_status)) {
		continue;
	}
	$schema[$table]["fields"] = array();
	foreach (fields($table) as $table_name => $field) {
		$pos += 1.25;
		$field["pos"] = $pos;
		$schema[$table]["fields"][$table_name] = $field;
	}
	foreach ($adminer->foreignKeys($table) as $val) {
		if (!$val["db"]) {
			$schema[$table]["references"][$val["table"]][] = array($val["source"], $val["target"]);
		}
	}
}

switch (DRIVER) {
    case 'mssql': $sql_dialect = 'MSSQL'; break;
    case 'oracle': $sql_dialect = 'Oracle'; break;
    case 'sqlite': $sql_dialect = 'SQLite'; break;
    case 'mysql': 
    default: $sql_dialect = 'MySQL'; break;
}

$canvas_size = max(count($schema) * 120, 2000);

$vuerdJson = [
    "canvas"=> [
        "version"=> "2.2.11",
        "width"=> $canvas_size,
        "height"=> $canvas_size,
        "scrollTop"=> 0,
        "scrollLeft"=> 0,
        "zoomLevel"=> 1,
        "show"=> [
            "tableComment"=> true,
            "columnComment"=> true,
            "columnDataType"=> true,
            "columnDefault"=> true,
            "columnAutoIncrement"=> false,
            "columnPrimaryKey"=> true,
            "columnUnique"=> false,
            "columnNotNull"=> true,
            "relationship"=> true
        ],
        "database"=> $sql_dialect,
        "databaseName"=> $_GET['db'] . ($_GET['ns'] ? '.' . $_GET['ns'] : ''),
        "canvasType"=> "ERD",
        "language"=> "GraphQL",
        "tableCase"=> "pascalCase",
        "columnCase"=> "pascalCase", //or camelCase
        "highlightTheme"=> "VS2015",
        "bracketType"=> "none",
        "setting"=> [
            "relationshipDataTypeSync"=> true,
            "relationshipOptimization"=> true,
            "columnOrder"=> [
                "columnName",
                "columnDataType",
                "columnNotNull",
                "columnUnique",
                "columnAutoIncrement",
                "columnDefault",
                "columnComment"
            ]
        ],
        "pluginSerializationMap"=> []
    ],
    "table"=> [
        "tables"=> [],
        "indexes"=> []
    ],
    "memo"=> [
        "memos"=> []
    ],
    "relationship"=> [
        "relationships"=> []
    ]
];

$i = 0;
foreach ($schema as $table_name => $table) {
    $schema[$table_name]['vuerd_uuid'] = gen_uuid();
    foreach ($table["fields"] as $field_idx => $field) {
        $schema[$table_name]['fields'][$field_idx]['vuerd_uuid'] = gen_uuid();
    }
    $i++;
    if ($i > 5) {
        // break;
    }
}

$i = 0;
foreach ($schema as $table_name => $table) {
    $tableJson = [
        "name"=> $table_name,
        "comment"=> "",
        "columns"=> [],
        "ui"=> [
            "active"=> false,
            "left"=> 200,
            "top"=> 100,
            "zIndex"=> 13,
            "widthName"=> 60,
            "widthComment"=> 60
        ],
        "visible"=> true,
        "id"=> $table['vuerd_uuid']
    ];
    foreach ($table["fields"] as $field) {
        $tableJson['columns'][] = [
            "name"=> $field["field"],
            "comment"=> $field["comment"] ?? '',
            "dataType"=> $field["full_type"],
            "default"=> $field["default"],
            "option"=> [
                "autoIncrement"=> $field["auto_increment"] ? true : false,
                "primaryKey"=> $field["primary"] ? true : false,
                "unique"=> $field["primary"] ? true : false,
                "notNull"=> ($field["null"] ? false : true)
            ],
            "ui"=> [
                "active"=> false,
                "pk"=> $field["primary"] ? true : false,
                "fk"=> false,
                "pfk"=> false,
                "widthName"=> 60,
                "widthComment"=> 60,
                "widthDataType"=> 60,
                "widthDefault"=> 60
            ],
            "id"=> $field['vuerd_uuid']
        ];
    }
    
    foreach ((array) $table["references"] as $to_table_name => $refs) {
        foreach ($refs as $ref) {
            $from_table = $table;
            $to_table = $schema[$to_table_name];
            list($from_keys, $to_keys) = $ref;
            $start = [
                "tableId" => $from_table['vuerd_uuid'],
                "columnIds" => [],
                "x" => 0,
                "y" => 0,
                "direction" => "left"
            ];
            $end = [
                "tableId" => $to_table['vuerd_uuid'],
                "columnIds" => [],
                "x" => 0,
                "y" => 0,
                "direction" => "right"
            ];
            $required = true;
            foreach ($from_keys as $from_key) {
                $from_field = find_field($from_table, $from_key);
                if ($from_field !== null) {
                    if ($from_field['null']) {
                        $required = false;
                    }
                    $start['columnIds'][] = $from_field['vuerd_uuid'];
                }
            }
            foreach ($to_keys as $to_key) {
                $to_field = find_field($to_table, $to_key);
                if ($to_field !== null) {
                    $end['columnIds'][] = $to_field['vuerd_uuid'];
                }
            }
            if (empty($start['columnIds']) || empty($end['columnIds']) || $from_table['vuerd_uuid'] === null || $to_table['vuerd_uuid'] === null) {
                continue;
            }
            $ref_json = [
                "identification" => false,
                "relationshipType" => $required ? "OneN" : "ZeroN",
                "startRelationshipType" => "Dash",
                "start" => $start,
                "end" => $end,
                "constraintName" => "{$table_name}[" . implode(', ', $from_keys) . "] -> {$to_table_name}[" . implode(', ', $to_keys) . "]",
                "visible" => true,
                "id" => gen_uuid()
            ];
            $vuerdJson['relationship']['relationships'][] = $ref_json;
        }
    }
    
    $vuerdJson['table']['tables'][] = $tableJson;
    $i++;
    if ($i > 5) {
        // break;
    }

}

/*


POST <url_to_adminer>?mssql=<db>&username=<user>&db=<db>&ns=dbo&create=<table_name>

name: Komponente
fields[1][field]: Betreiber
fields[1][orig]: Betreiber
fields[1][type]: nvarchar
fields[1][length]: 50
fields[1][collation]: SQL_Latin1_General_CP1_CI_AS
fields[1][on_delete]: 
fields[1][default]: 
fields[1][comment]: 
fields[2][field]: Id
fields[2][orig]: Id
fields[2][type]: bigint
fields[2][length]: 
fields[2][collation]: 
fields[2][on_delete]: 
fields[2][default]: 
fields[2][comment]: 
fields[3][field]: Name
fields[3][orig]: Name
fields[3][type]: nvarchar
fields[3][length]: 100
fields[3][collation]: SQL_Latin1_General_CP1_CI_AS
fields[3][on_delete]: 
fields[3][default]: 
fields[3][comment]: 
fields[4][field]: UserAend
fields[4][orig]: UserAend
fields[4][type]: nvarchar
fields[4][length]: 50
fields[4][collation]: SQL_Latin1_General_CP1_CI_AS
fields[4][on_delete]: 
fields[4][default]: 
fields[4][comment]: 
fields[5][field]: UserNeu
fields[5][orig]: UserNeu
fields[5][type]: nvarchar
fields[5][length]: 50
fields[5][collation]: SQL_Latin1_General_CP1_CI_AS
fields[5][on_delete]: 
fields[5][default]: 
fields[5][comment]: 
fields[6][field]: ZeitAend
fields[6][orig]: ZeitAend
fields[6][type]: datetime
fields[6][length]: 
fields[6][collation]: 
fields[6][on_delete]: 
fields[6][default]: 
fields[6][comment]: 
fields[7][field]: ZeitNeu
fields[7][orig]: ZeitNeu
fields[7][type]: datetime
fields[7][length]: 
fields[7][collation]: 
fields[7][on_delete]: 
fields[7][default]: 
fields[7][comment]: 
Auto_increment: 
Comment: 
token: 15360:155241

<input type="hidden" name="token" value="<?php echo $token; ?>">

*/

?>
<style>

:root {
    --shade0: #F5F9F9;
    --shade1: #D5D9DD;
    --shade2: #A5A9AA;
    --shade3: #757577;
    --shade4: #414141;
    --shade5: #353533;
    --shade6: #252525;
    --shade7: #1C1C1E;
    --shade8: #151516;
    --shade9: #010101
}

.shade0::before {
    color: var(--shade0)
}

.shade1::before {
    color: var(--shade1)
}

.shade2::before {
    color: var(--shade2)
}

.shade3::before {
    color: var(--shade3)
}

.shade4::before {
    color: var(--shade4)
}

.shade5::before {
    color: var(--shade5)
}

.shade6::before {
    color: var(--shade6)
}

.shade7::before {
    color: var(--shade7)
}

.shade8::before {
    color: var(--shade8)
}

.shade9::before {
    color: var(--shade9)
}

:root {
    --foreground: var(--shade4);
    --mideground: var(--shade6);
    --background: var(--shade8);
    --fontColorMajor: var(--shade0);
    --fontColorMinor: var(--shade2);
    --fontColorSmall: var(--shade3);
    --borderLight: solid 2px var(--shade3);
    --borderHeavy: solid 3px var(--shade5);
    --colorMajor: var(--blue);
    --colorMinor: var(--indigo);
    --colorSmall: var(--navy)
}

.exf-dialog {
    display: none;
    position: absolute;
    top: 15%;
    left: 50%;
    max-height: 70vh;
    max-width: 90vw;
    z-index: 99995;
    overflow-y: auto;
    min-height: 100px;
    min-width: 400px;
    border: var(--borderHeavy);
    color: white;
}

.exf-dialog table {
    width: calc(100% - 2 * 3px);
    margin: 3px;
}

.exf-dialog tbody {
    max-height: 410px;
    overflow-y: auto;
}

.exf-dialog table td,
.exf-dialog table th {
    vertical-align: middle;
    padding: 3px 10px;
    border: 1px solid var(--foreground);
    overflow: hidden;
    min-height: 44px;
}

.exf-dialog table td.action,
.exf-dialog table th.action {
    text-align: center
}

.exf-dialog table th {
    text-align: left;
    font-weight: bold;
    background: var(--background)
}

.exf-dialog > section {
    background-color: var(--background);
}

.exf-dialog > section > label.title {
    font-size: 20px;
    padding: 2px 8px;
    margin: 0;
    font-weight: 700;
    background-color: var(--foreground);
    display: block;
    height: 28px;
}

.exf-dialog > section > label > i {
    width: 1.25rem; 
    height: 1.25rem;
    display: inline-block;
    fill: white;
}

.exf-dialog button {
    display: inline-block;
    padding: 7px 10px;
    height: 31px;
    width: auto;
    margin: 3px 0;
    min-width: 80px;
    color: var(--background);
    font-weight: bold;
    background-color: var(--fontColorMajor);
    cursor: pointer
}

.exf-dialog dropdown, 
.exf-dialog input, 
.exf-dialog select, 
.exf-dialog textarea {
    width: 100%;
    height: 31px;
    margin: 5px 0;
    padding: 7px 10px;
    line-height: normal;
    border-radius: 0;
    background-color: var(--shade7);
    border: none;
    border-bottom: var(--borderLight);
    color: var(--fontColorMajor);
}

.exf-dialog toolbar {
    display: block;
    padding: 2px 8px;
    background-color: var(--foreground);
    text-align: right;
}

</style>

<div id="exf-dialog-files" class="exf-dialog" style="top: 15%; left: calc(50% - 250px); min-width: 500px;">
	<i class="close fas fa-times-circle"></i>
	<i class="drag fas fa-arrows-alt"></i>
	<section>
		<label class="title">
			<i><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><title>folder-open-outline</title><path d="M6.1,10L4,18V8H21A2,2 0 0,0 19,6H12L10,4H4A2,2 0 0,0 2,6V18A2,2 0 0,0 4,20H19C19.9,20 20.7,19.4 20.9,18.5L23.2,10H6.1M19,18H6L7.6,12H20.6L19,18Z" /></svg></i> DB diagrams:
		</label>
		<form>
			<table>
        		<thead>
        			<tr>
        				<th>Name</th>
        				<th>Status</th>
        				<th>Description</th>
        				<th>Actions</th>
        			</tr>
        		</thead>
        		<tbody>
					<tr data-diagram="Unnamed">
						<td>Unnamed</td>
						<td>Draft</td>
						<td></td>
						<td>
							<button class="git_diff" data-button="diff">Diff</button>
						</td>
					</tr>
				</tbody>
        	</table>
			<toolbar>
				<button class="btn-close">Cancel</button>
			</toolbar>
		</form>
	</section>
</div>
<erd-editor></erd-editor>

<script<?php echo nonce(); ?> src="../externals/vuerd/dist/vuerd.min.js"></script>
<!-- or module -->
<!-- <script type="module" src="https://cdn.jsdelivr.net/npm/vuerd/dist/vuerd.esm.js"></script> -->
<script<?php echo nonce(); ?>>
	var oDataOriginal = <?php echo json_encode($vuerdJson, JSON_PRETTY_PRINT); ?>;
	const editor = document.querySelector('erd-editor');
	const svgApplyDB = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M8.84 12L3.92 16.92L2.5 15.5L5 13H0V11H5L2.5 8.5L3.92 7.08L8.84 12M12 3C8.59 3 5.68 4.07 4.53 5.57L5 6L6.03 7.07C6 7.05 6 7 6 7C6 6.5 8.13 5 12 5S18 6.5 18 7 15.87 9 12 9C9.38 9 7.58 8.31 6.68 7.72L9.8 10.84C10.5 10.94 11.24 11 12 11C14.39 11 16.53 10.47 18 9.64V12.45C16.7 13.4 14.42 14 12 14C11.04 14 10.1 13.9 9.24 13.73L7.59 15.37C8.91 15.77 10.41 16 12 16C14.28 16 16.39 15.55 18 14.77V17C18 17.5 15.87 19 12 19S6 17.5 6 17V16.96L5 18L4.54 18.43C5.69 19.93 8.6 21 12 21C16.41 21 20 19.21 20 17V7C20 4.79 16.42 3 12 3Z" /></svg>';
	const svgSave = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="vuerd-icon"><path d="M17 3H5C3.89 3 3 3.9 3 5V19C3 20.1 3.89 21 5 21H19C20.1 21 21 20.1 21 19V7L17 3M19 19H5V5H16.17L19 7.83V19M12 12C10.34 12 9 13.34 9 15S10.34 18 12 18 15 16.66 15 15 13.66 12 12 12M6 6H15V10H6V6Z" /></svg>';
	const svgOpen = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M6.1,10L4,18V8H21A2,2 0 0,0 19,6H12L10,4H4A2,2 0 0,0 2,6V18A2,2 0 0,0 4,20H19C19.9,20 20.7,19.4 20.9,18.5L23.2,10H6.1M19,18H6L7.6,12H20.6L19,18Z" /></svg>';
	const svgBack = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M20,11V13H8L13.5,18.5L12.08,19.92L4.16,12L12.08,4.08L13.5,5.5L8,11H20Z" /></svg>';

	document.querySelectorAll('.exf-dialog .btn-close').forEach(function(domBtn) {
		domBtn.addEventListener('click', function(e){
			this.closest('.exf-dialog').style.display = 'none';
			e.stopPropagation();
			e.preventDefault();
			return false;
		});
	});


	editor.value = JSON.stringify(oDataOriginal);
	editor.automaticLayout = true;
	
    /*window.addEventListener('resize', () => {
        editor.width = window.innerWidth;
        editor.height = window.innerHeight;
    });
    window.dispatchEvent(new Event('resize'));*/

    editor.addMenuItem = function(sTitle, sIcon, fnClick){
        const domShadow = document.querySelector('erd-editor')
        	.shadowRoot.querySelector('vuerd-menubar')
        	.shadowRoot.querySelector('.vuerd-menubar');
        const divElement = document.createElement('div');
        divElement.className = 'vuerd-menubar-menu';
        divElement.setAttribute('data-tippy-content', sTitle);
        divElement.innerHTML = '<div style="width: 1.25rem; height: 1.25rem;" class="exf-menubar-btn">' + sIcon + '</div>';
        divElement.addEventListener('click', fnClick);
        domShadow.prepend(divElement);
        
    }

    editor.hideMenuItem = function(iIdx){
        const domShadow = document.querySelector('erd-editor')
        	.shadowRoot.querySelector('vuerd-menubar')
        	.shadowRoot.querySelector('.vuerd-menubar');
        domShadow.children[iIdx].style.display = 'none';
    }
    
<?php 
if ($mode === 'designer') {
?>
    setTimeout(function(){
    	editor.addMenuItem('Apply changes to SQL DB', svgApplyDB);
    	editor.addMenuItem('Save draft', svgSave);
    	editor.addMenuItem('Open diagram', svgOpen, function(){
        	document.querySelector('#exf-dialog-files').style.display = 'block'
    	});
    	editor.addMenuItem('Close', svgBack, function(){
    		history.back();
    	});
    }, 0);
<?php 
} else {
?>
	editor.readonly = true;
	setTimeout(function(){
    	editor.hideMenuItem(7);
    	editor.hideMenuItem(8);
    	editor.addMenuItem('Close', svgBack, function(){
    		history.back();
    	});
	}, 0);
<?php 
}
?>
</script>
<?php exit() ?>