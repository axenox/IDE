<?php

/**
 * Autocomplete for keywords, tables and columns.
 * @author David Grudl
 * @license BSD
 */
class AdminerAutocomplete
{
	public $keywords = [
		'AS', 'DELETE FROM', 'DISTINCT', 'EXPLAIN', 'FROM', 'GROUP BY', 'HAVING', 'INSERT INTO', 'INNER JOIN', 'IGNORE',
		'LIMIT', 'LEFT JOIN', 'NULL', 'ORDER BY', 'ON DUPLICATE KEY UPDATE', 'SELECT', 'UPDATE', 'WHERE', 'ON', 'USING',
	    'CROSS_JOIN'
	];


	public function head()
	{
		if (! isset($_GET['sql']) && ! isset($_GET['view'])) {
			return;
		}

		$suggests = [];
		foreach (array_keys(tables_list()) as $table) {
			$suggests[] = trim($table);
			foreach (fields($table) as $field => $foo) {
				$suggests[] = "$table.$field";
			}
		} ?>
<style<?php echo nonce();?>>
.ace_editor {
	width: 100%;
    height: 500px;
	resize: both;
	border: 1px solid black;
}
</style>
<script<?php echo nonce();?> src="../externals/ace/ace.js"></script>
<script<?php echo nonce();?> src="../externals/ace/ext-language_tools.js"></script>
<script<?php echo nonce();?>>
document.addEventListener('DOMContentLoaded', () => {

	var keywords = <?php echo json_encode($this->keywords) ?>;
	var suggests = <?php echo json_encode($suggests) ?>;
	var textarea = document.querySelector('.sqlarea');
	var form = textarea.closest('form'); //textarea.form;
	var editor;

	ace.config.set('basePath', 'Assets/ace');
	editor = ace.edit(textarea);
	editor.setTheme('ace/theme/tomorrow');
	editor.session.setMode('ace/mode/sql');
	editor.setOptions({
		fontSize: 14,
		enableBasicAutocompletion: [{
			// note, won't fire if caret is at a word that does not have these letters
			identifierRegexps: [/[a-zA-Z_0-9\.\-\u00A2-\uFFFF]/], // added dot
			getCompletions: (editor, session, pos, prefix, callback) => {
				var oDoc = session.getDocument();
				var sValue = oDoc.getValue();
				var aSuggestions = [
					...keywords.map((word) => ({value: word + ' ', score: 1, meta: 'keyword'})),
					...suggests.map((word) => ({value: word, score: 2, meta: 'name'}))
				];
				
				// Add table aliases from the current text 
				aMatches = [...sValue.matchAll(/(from|join)\s+(\w+\.)?((\w*) (as )?(\w+))/gmi)];
				aMatches.forEach(function(aMatch){
					/*
					[
						0: [
        					0: "FROM dbo.date_dimension AS dd",
                            1: "FROM",
                            2: "dbo."
                            3: "date_dimension AS dd",
                            4: "date_dimension",
                            5: "AS"
                            6: "dd",
                        ],
                    ]
                    */
					var sTable = aMatch[4];
					var sAlias = aMatch[6];
					var sOp = aMatch[1];
					if ((sAlias.toUpperCase() === 'ON' || sAlias.toUpperCase() === 'USING') && sOp.toUpperCase() === 'JOIN') {
						return;
					}
					if (sTable !== undefined && sAlias !== undefined) {
						aSuggestions.push({value: sAlias, score: 3, meta: 'name'});
						suggests.forEach(function(sWord){
							if (sWord.startsWith(sTable+'.')) {
								aSuggestions.push({value: sAlias + '.' + sWord.substring((sTable + '.').length), score: 3, meta: 'name'});
							}
						});
					}
				});
				
				callback(null, aSuggestions);
			},
		}],
		// to make popup appear automatically, without explicit ctrl+space
		enableLiveAutocompletion: true,
	});

	textarea.hidden = true;
	form.appendChild(textarea);
	editor.getSession().on('change', () => {
		textarea.value = editor.getSession().getValue();
	});
});
</script>
<?php
	}
}
