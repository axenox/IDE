<?php

namespace AdminNeo;

/**
 * ExFace customization of the AdminNeo {@see Admin} class.
 *
 * AdminNeo is customized by subclassing {@see Admin} (see the AdminNeo docs on advanced
 * customizations). This subclass is intentionally thin: almost everything the IDE needs is
 * expressed through the plain configuration array that {@see \axenox\IDE\Common\AdminneoAPI}
 * passes to {@see Admin::create()} - the data connection is registered there as a pre-configured
 * server, together with SSL/driver options.
 *
 * The class is required lazily from the wrapper (`Adminneo/adminneo.php`) inside
 * `adminneo_instance()`, i.e. only after AdminNeo has loaded the base {@see Admin} class.
 *
 * ## Extending the SQL behaviour (e.g. named constraints)
 *
 * AdminNeo keeps SQL generation in the driver classes. To add options such as "always generate
 * named constraints" in an upstream-friendly way, add a config key (read via
 * `$this->getConfig()` / a custom `Config` getter) and consume it in a thin `Driver` subclass.
 * Because the option defaults to the stock behaviour, such a change can be contributed upstream
 * without breaking existing setups.
 *
 * @author andrej.kabachnik
 */
class ExfaceAdmin extends Admin
{
    /**
     * {@inheritDoc}
     *
     * Returns a plain-text service title (no AdminNeo logo) so the embedded tool blends into the
     * IDE. The title can be overridden via the `serviceTitle` key of the context array that
     * {@see \axenox\IDE\Common\AdminneoAPI} exposes in `$GLOBALS`.
     *
     * @see Admin::getServiceTitle()
     */
    public function getServiceTitle(): string
    {
        $title = $GLOBALS['axenox_ide_adminneo']['serviceTitle'] ?? '';

        return h($title !== "" ? $title : "SQL Admin");
    }
}
