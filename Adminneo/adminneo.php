<?php
/**
 * Bootstrap wrapper for the axenox/adminneo fork within the ExFace IDE.
 *
 * This file is required by {@see \axenox\IDE\Common\AdminneoAPI::launchAdminneo()} after the
 * current working directory has been changed to the fork's `admin/` folder. It defines the global
 * `adminneo_instance()` factory that AdminNeo's bootstrap calls to obtain the customized
 * {@see \AdminNeo\Admin} instance, and then runs the fork by including its `index.php`.
 *
 * Unlike Adminer, AdminNeo runs straight from source - there is no compile step and no static
 * assets to serve from disk (the app serves its own CSS/JS via `?file=`). The whole integration
 * therefore comes down to this wrapper plus the plain configuration array assembled by
 * AdminneoAPI and handed over through `$GLOBALS['axenox_ide_adminneo']`.
 *
 * NOTE: this file lives in the global namespace on purpose, because AdminNeo looks up
 * `\adminneo_instance()` there. The AdminNeo core classes are namespaced under `AdminNeo\`.
 */

if (! function_exists('adminneo_instance')) {
    /**
     * Factory called by AdminNeo's bootstrap to obtain the customized Admin instance.
     *
     * @return \AdminNeo\Admin|\AdminNeo\Pluginer
     */
    function adminneo_instance()
    {
        // The ExFace Admin subclass extends AdminNeo\Admin, which is only loaded by the time this
        // function is called (during AdminNeo's bootstrap). Requiring it here - not at the top of
        // the wrapper - keeps the fork's own class loading intact.
        require_once __DIR__ . '/ExfaceAdmin.php';

        $context = $GLOBALS['axenox_ide_adminneo'] ?? [];
        $config = $context['config'] ?? [];

        // No custom plugins are enabled by default: AdminNeo ships SQL highlighting and
        // autocomplete (jush is vendored, no submodule), plus a clean default theme. Add stock or
        // ExFace plugins here if needed - the Plugins manager chains them automatically.
        $plugins = [];

        return \AdminNeo\ExfaceAdmin::create($config, $plugins);
    }
}

// Run the fork. index.php lives in the current working directory (the fork's admin/ folder).
require getcwd() . DIRECTORY_SEPARATOR . 'index.php';
