<?php
namespace AdminNeo;

use axenox\IDE\Tests\PHPUnit\Support\FakeAdminneoConnection;

/**
 * Minimal driver for the settings page; loaded explicitly by the subprocess test factory only.
 */
class FakeAdminneoDriver extends Driver
{
}

Drivers::add('ide_test', 'IDE test driver', []);
define('AdminNeo\DRIVER', 'ide_test');
define('AdminNeo\DIALECT', 'sqlite');
define('AdminNeo\DRIVER_EXTENSION', 'IDE test fixture');

/**
 * Creates and opens the test connection through AdminNeo's normal authentication path.
 *
 * @param bool $primary
 * @param string|null $error
 * @return Connection|null
 */
function connect(bool $primary = false, ?string &$error = null) : ?Connection
{
    $connection = $primary ? FakeAdminneoConnection::create() : FakeAdminneoConnection::createSecondary();
    $connection->open(...Admin::get()->getCredentials());
    return $connection;
}

/**
 * Instantiates the real base driver with the test connection.
 *
 * @param Connection $connection
 * @return Driver
 */
function create_driver(Connection $connection) : Driver
{
    return FakeAdminneoDriver::create($connection, Admin::get());
}

/**
 * Disables optional database capabilities for the settings-only fixture.
 *
 * @param string $feature
 * @return bool
 */
function support(string $feature) : bool
{
    return false;
}

/**
 * Returns no databases because the fixture never uses a database server.
 *
 * @param bool $flush
 * @return string[]
 */
function get_databases(bool $flush) : array
{
    return [];
}
