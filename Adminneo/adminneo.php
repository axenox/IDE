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

        $embeddedMode = $config['embeddedMode'] ?? false;
        $plugins = [];
        if ($embeddedMode) {
            // The integration owns the authenticated user session, so embedded AdminNeo must not
            // provide a competing local logout control.
            require_once __DIR__ . '/HideLogoutPlugin.php';
            $plugins[] = new \AdminNeo\HideLogoutPlugin();
        }

        // Allow embedding the SQL admin in an IFrame. By default AdminNeo sends
        // "X-Frame-Options: DENY" (see \AdminNeo\page_headers()), which blocks the IDE from
        // showing the tool in an iframe - our primary way of using it. The stock
        // FrameSupportPlugin relaxes this to the configured frame ancestors and adds a matching
        // CSP "frame-ancestors" directive. Ancestors default to same-origin ("self") and can be
        // overridden via the "frameAncestors" key in Adminneo/config/adminneo.config.json. An
        // empty list disables the plugin (keeping the DENY default). The plugin classes are not
        // Composer-autoloaded, so require the file explicitly; getcwd() is the fork's admin/
        // folder, with plugins/ a sibling of it.
        $frameAncestors = $config['frameAncestors'] ?? ['self'];
        if ($embeddedMode && ! empty($frameAncestors)) {
            require_once dirname(getcwd()) . DIRECTORY_SEPARATOR . 'plugins' . DIRECTORY_SEPARATOR . 'FrameSupportPlugin.php';
            $plugins[] = new \AdminNeo\FrameSupportPlugin($frameAncestors);
        }

        // This integration-specific renderer uses the Mermaid distributions already shipped by
        // exface/core. It is opt-out because the native renderer remains AdminNeo's default when
        // the IDE plugin is not registered.
        if (!empty($context['mermaidUrl']) && !empty($context['svgPanZoomUrl'])) {
            require_once __DIR__ . '/MermaidSchemaPlugin.php';
            $plugins[] = new \AdminNeo\MermaidSchemaPlugin($context['mermaidUrl'], $context['svgPanZoomUrl']);
        }

        // Infer navigation links from column naming conventions when the connection explicitly
        // provides a relation_matcher regular expression.
        if (! empty($config['relationMatcher']) && is_string($config['relationMatcher'])) {
            $regexForeignKeysPath = dirname(getcwd()) . DIRECTORY_SEPARATOR . 'plugins' . DIRECTORY_SEPARATOR . 'RegexForeignKeys.php';
            if (file_exists($regexForeignKeysPath)) {
                require_once $regexForeignKeysPath;
                $plugins[] = new \AdminNeo\RegexForeignKeys($config['relationMatcher']);
            }
        }

        // Enable the forked tree viewer in the IDE by default. It adds a row action on select pages
        // for browsing direct and reverse foreign-key relations in a modal, which replaces the old
        // Adminer 4 plugin used by app designers. The plugin is stored in axenox/adminneo so it can
        // be maintained together with AdminNeo UI changes and reused outside the Power UI wrapper.
        $pluginPath = dirname(getcwd()) . DIRECTORY_SEPARATOR . 'plugins' . DIRECTORY_SEPARATOR . 'TreeViewerPlugin.php';
        if (file_exists($pluginPath)) {
            require_once $pluginPath;
            $plugins[] = new \AdminNeo\TreeViewerPlugin();
        }
        return \AdminNeo\ExfaceAdmin::create($config, $plugins);
    }
}

// Run the fork. index.php lives in the current working directory (the fork's admin/ folder).
require getcwd() . DIRECTORY_SEPARATOR . 'index.php';
