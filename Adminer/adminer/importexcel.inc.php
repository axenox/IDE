<?php
$TABLE = $_GET["importexcel"];
$fields = fields($TABLE);
if (!$fields) {
	$error = error();
}
$table_status = table_status1($TABLE, true);
$name = $adminer->tableName($table_status);

page_header(($fields && is_view($table_status) ? $table_status['Engine'] == 'materialized view' ? lang('Materialized view') : lang('View') : lang('Table')) . ": " . ($name != "" ? $name : h($TABLE)), $error);

$adminer->selectLinks($table_status);
$comment = $table_status["Comment"];
if ($comment != "") {
	echo "<p class='nowrap'>" . lang('Comment') . ": " . h($comment) . "\n";
}

if ($fields) {
	$adminer->tableStructurePrint($fields);
}
