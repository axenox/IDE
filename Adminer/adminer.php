<?php

function adminer_object() {
    global $adminer;
    
	require_once "Plugins/plugin.php";
	require_once "Plugins/login-password-less.php";
	require_once "Plugins/tables-filter.php";
	// require_once "Plugins/AdminerForeignKeysPlugin.php";
	// require_once "Plugins/readable-dates.php";
	require_once "Plugins/disableJush.php";
	require_once "Plugins/autocomplete.php";
	require_once "Plugins/SaveMenuPos.php";
	require_once "Drivers/mssql-mod.php"; // the driver is enabled just by including

	return new AdminerPlugin([
		// TODO: inline the result of password_hash() so that the password is not visible in source codes
	    new AdminerLoginPasswordLess(password_hash(12345678, PASSWORD_DEFAULT)),
		new AdminerTablesFilter(),
		// new AdminerForeignKeys(),
		// new AdminerDatabaseHide(),
	    new AdminerDisableJush(),
	    new AdminerAutocomplete(),
	    new AdminerSaveMenuPos()
	]);
}

require __DIR__ . "/adminer-4.8.1.php";

