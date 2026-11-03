<?php
namespace axenox\IDE\Tests\PHPUnit\Support;

use AdminNeo\Connection;

/**
 * Database-free connection for exercising real AdminNeo authentication and error handling.
 */
class FakeAdminneoConnection extends Connection
{
    protected $version = '1.0.0';

    /**
     * Injects the requested failure at the real driver's connection boundary.
     *
     * {@inheritDoc}
     * @see \AdminNeo\Connection::open()
     */
    public function open(string $server, string $username, string $password) : bool
    {
        switch ($_SERVER['ADMINNEO_TEST_FAILURE']) {
            case 'native-warning':
                fopen(__DIR__ . '/missing-test-input', 'rb');
                break;
            case 'warning':
            case 'stream-warning':
                trigger_error('Unexpected test driver warning', E_USER_WARNING);
                if ($_SERVER['ADMINNEO_TEST_FAILURE'] === 'stream-warning') {
                    exit;
                }
                break;
            case 'missing-key':
                $optional = [];
                $unused = $optional['missing'];
                break;
            case 'suppressed':
                @trigger_error('Suppressed test driver warning', E_USER_WARNING);
                break;
            case 'user-fatal':
                trigger_error('Broken test driver', E_USER_ERROR);
                break;
            case 'type-error':
                strlen([]);
                break;
            case 'native-fatal':
                require __DIR__ . '/FatalAdminneoConnection.php';
                break;
            case 'none':
                break;
            default:
                throw new \LogicException('Unknown test failure mode');
        }
        return true;
    }

    /**
     * Fails explicitly because these tests must not select or query a database.
     *
     * {@inheritDoc}
     * @see \AdminNeo\Connection::selectDatabase()
     */
    public function selectDatabase(string $name) : bool
    {
        throw new \LogicException('The test driver does not implement database selection');
    }

    /**
     * Fails explicitly rather than pretending to implement SQL quoting.
     *
     * {@inheritDoc}
     * @see \AdminNeo\Connection::quote()
     */
    public function quote(string $string) : string
    {
        throw new \LogicException('The test driver does not implement SQL quoting');
    }

    /**
     * Rejects every query so accidental database-dependent coverage cannot pass.
     *
     * {@inheritDoc}
     * @see \AdminNeo\Connection::query()
     */
    public function query(string $query, bool $unbuffered = false)
    {
        throw new \LogicException('The test driver does not implement SQL queries');
    }
}
