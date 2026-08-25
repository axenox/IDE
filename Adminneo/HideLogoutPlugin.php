<?php

namespace AdminNeo;

/**
 * Removes AdminNeo's local logout control when it is embedded in ExFace.
 *
 * The surrounding ExFace integration owns the authenticated user session, so AdminNeo must not
 * offer a second logout flow that only clears its isolated session.
 *
 * @author andrej.kabachnik
 */
class HideLogoutPlugin extends Plugin
{
    /**
     * Suppresses the default username and logout form.
     *
     * A non-null result stops Pluginer from invoking {@see Origin::printLogout()}.
     *
     * @return bool
     */
    public function printLogout(): bool
    {
        return true;
    }
}
