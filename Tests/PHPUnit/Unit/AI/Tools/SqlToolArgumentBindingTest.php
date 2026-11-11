<?php
namespace axenox\IDE\Tests\PHPUnit\Unit\AI\Tools;

use axenox\IDE\AI\Tools\SQLPerformTool;
use axenox\IDE\AI\Tools\SQLReadTool;
use exface\Core\CommonLogic\Workbench;
use exface\Core\Exceptions\UnexpectedValueException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Verifies SQL tool argument binding without accessing a database or persisted metamodel.
 */
class SqlToolArgumentBindingTest extends TestCase
{
    /**
     * Omitted and explicitly null connections must reach the assistant connection resolver.
     *
     * @param class-string<SQLPerformTool> $toolClass
     * @param array<int,mixed> $expected
     * @return void
     */
    #[DataProvider('sqlTools')]
    public function testAcceptsMissingConnection(string $toolClass, array $expected) : void
    {
        $tool = new $toolClass(new Workbench());

        self::assertSame($expected, $tool->normalizeArguments(['statement' => 'SELECT 1']));
        self::assertSame($expected, $tool->normalizeArguments([
            'statement' => 'SELECT 1',
            'connection' => null
        ]));
    }

    /**
     * Explicit connections remain available instead of being replaced by the assistant context.
     *
     * @param class-string<SQLPerformTool> $toolClass
     * @param array<int,mixed> $expected
     * @return void
     */
    #[DataProvider('sqlTools')]
    public function testPreservesExplicitConnection(string $toolClass, array $expected) : void
    {
        $tool = new $toolClass(new Workbench());
        $expected[1] = 'exface.Core.METAMODEL_DB';

        self::assertSame($expected, $tool->normalizeArguments([
            'statement' => 'SELECT 1',
            'connection' => 'exface.Core.METAMODEL_DB'
        ]));
    }

    /**
     * Making the connection optional must not weaken statement validation.
     *
     * @param class-string<SQLPerformTool> $toolClass
     * @param array<int,mixed> $expected
     * @return void
     */
    #[DataProvider('sqlTools')]
    public function testRejectsMissingStatement(string $toolClass, array $expected) : void
    {
        $tool = new $toolClass(new Workbench());

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Missing required argument "statement".');
        $tool->normalizeArguments(['connection' => 'exface.Core.METAMODEL_DB']);
    }

    /**
     * Supplies both tools with their respective normalized argument layouts.
     *
     * @return iterable<string,array{class-string<SQLPerformTool>,array<int,mixed>}>
     */
    public static function sqlTools() : iterable
    {
        yield 'perform' => [SQLPerformTool::class, ['SELECT 1', null]];
        yield 'read' => [SQLReadTool::class, ['SELECT 1', null, '0']];
    }
}
