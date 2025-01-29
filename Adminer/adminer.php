<?php

function adminer_object() {
    global $adminer;
	    
    // required to run any plugin
    require_once "../plugins/plugin.php";
    
    // autoloader
    foreach (glob("../plugins/*.php") as $filename) {
        require_once $filename;
    }

	$plugins =[
	    new AdminerDesign('exface'),
		// TODO use constant AdminerAPI::NO_PASSWROD
	    new AdminerLoginPasswordLess(password_hash(12345678, PASSWORD_DEFAULT)),
		new AdminerTablesFilter(),
		new AdminerForeignKeys(),
		// new AdminerDatabaseHide(),
	    new AdminerDisableJush(),
	    new AdminerAutocomplete(),
	    new AdminerSaveMenuPos(),
	    new AdminerTreeViewer('plugins/tree-viewer/script.js'),
	    new AdminerFrames(true),
	];

	// See if the AdminerAPI requires SSL for this connection.
	// If so, make sure to remember this setting from the login call and
	// remember it till the next login attempt.
	if (array_key_exists('auth', $_POST)) {
		if (null !== $ssl = $_POST['auth']['ssl'] ?? null) {
			$_SESSION['ssl'] = $_POST['auth']['ssl'];
		} else {
			unset($_SESSION['ssl']);
		}
	}
	if (array_key_exists('ssl', $_SESSION)) {
		$plugins[] = new AdminerLoginSsl($_SESSION['ssl']);
	}
	
	return new AdminerPlugin($plugins);
}
$dir = __DIR__;
chdir($dir . "/adminer/");
require "index.php";
chdir($dir);
