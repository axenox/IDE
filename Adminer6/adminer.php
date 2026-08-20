<?php
/**
 * Bootstrap wrapper for the axenox/adminer 6.x fork within the ExFace IDE.
 *
 * This file is required by {@see \axenox\IDE\Common\Adminer6API::launchAdminer()} after the
 * current working directory has been changed to the fork's `adminer/` folder. It defines the
 * global `adminer_object()` factory expected by Adminer's `bootstrap.inc.php`, assembles the
 * plugin stack (stock plugins shipped with the fork + our own ExFace plugins) and then runs
 * the fork by including its `index.php`.
 *
 * NOTE: everything here lives in the global namespace, just like the Adminer plugin classes.
 * The Adminer core classes are namespaced under `Adminer\`.
 */

use axenox\IDE\Common\Adminer6API;

if (! function_exists('adminer_object')) {
    /**
     * Factory called by Adminer's bootstrap to obtain the plugin manager.
     *
     * @return \Adminer\Plugins
     */
    function adminer_object()
    {
        // Stock plugins shipped with the fork. The current working directory is the fork's
        // `adminer/` folder, so its `plugins/` sibling is reachable via `../plugins/`.
        $stockDir = '../plugins/';
        require_once $stockDir . 'login-password-less.php';
        require_once $stockDir . 'tables-filter.php';
        require_once $stockDir . 'frames.php';
        require_once $stockDir . 'login-ssl.php';
        // Available but not enabled by default:
        // require_once $stockDir . 'database-hide.php';

        // Custom ExFace plugins shipped with axenox.IDE.
        require_once __DIR__ . '/plugins/ExfaceDesign.php';

        // No editor plugin is registered on purpose: Adminer's built-in jush editor provides
        // SQL syntax highlighting AND autocomplete out of the box. Its assets are a git
        // submodule that Composer does not populate, so AdminerAPI serves a vendored copy
        // under `static/jush/` (see AdminerAPI::runAdminer()).
        $plugins = [
            new \AdminerLoginPasswordLess(password_hash(Adminer6API::NO_PASSWROD, PASSWORD_DEFAULT)),
            new \AdminerTablesFilter(),
            new \AdminerExfaceDesign(),
            new \AdminerFrames(true),
        ];

        // The AdminerAPI passes SSL settings for a connection inside the `auth` POST array on
        // login. Remember them in the session so that subsequent requests keep using SSL.
        if (array_key_exists('auth', $_POST)) {
            if (null !== $ssl = $_POST['auth']['ssl'] ?? null) {
                $_SESSION['ssl'] = $ssl;
            } else {
                unset($_SESSION['ssl']);
            }
        }
        if (array_key_exists('ssl', $_SESSION)) {
            $plugins[] = new \AdminerLoginSsl($_SESSION['ssl']);
        }

        return new \Adminer\Plugins($plugins);
    }
}

// Run the fork. index.php lives in the current working directory (the fork's adminer/ folder).
require getcwd() . DIRECTORY_SEPARATOR . 'index.php';