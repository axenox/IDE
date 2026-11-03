<?php
/**
 * Triggers a native declaration fatal at runtime while keeping the fixture valid for linting.
 */
eval(<<<'PHP'
namespace axenox\IDE\Tests\PHPUnit\Support;
/**
 * Deliberately incompatible declaration for testing PHP's uncatchable compile-fatal path.
 */
class FatalAdminneoConnection extends FakeAdminneoConnection
{
    /**
     * Deliberately violates the parent signature; never load outside the fatal subprocess.
     *
     * {@inheritDoc}
     * @see FakeAdminneoConnection::open()
     */
    public function open(array $server) : bool
    {
        return true;
    }
}
PHP
);
