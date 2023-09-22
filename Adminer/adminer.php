<?php

function adminer_object() {
    global $adminer;
    
    // required to run any plugin
    require_once "../plugins/plugin.php";
    
    // autoloader
    foreach (glob("../plugins/*.php") as $filename) {
        require_once $filename;
    }

	return new AdminerPlugin([
	    new AdminerDesign('exface'),
		// TODO: inline the result of password_hash() so that the password is not visible in source codes
	    new AdminerLoginPasswordLess(password_hash(12345678, PASSWORD_DEFAULT)),
		new AdminerTablesFilter(),
		new AdminerForeignKeys(),
		// new AdminerDatabaseHide(),
	    new AdminerDisableJush(),
	    new AdminerAutocomplete(),
	    new AdminerSaveMenuPos(),
	    new AdminerTreeViewer('plugins/tree-viewer/script.js'),
	    new AdminerFrames(true),
	]);
}
$dir = __DIR__;
chdir($dir . "/adminer/");
require "index.php";
chdir($dir);
