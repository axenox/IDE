<?php
function adminer_object() {
	include_once "Plugins/plugin.php";
	include_once "Plugins/login-password-less.php";
	return new AdminerPlugin(array(
		// TODO: inline the result of password_hash() so that the password is not visible in source codes
		new AdminerLoginPasswordLess(password_hash("12345678", PASSWORD_DEFAULT)),
	));
}

require __DIR__ . "/adminer-4.8.1.php";