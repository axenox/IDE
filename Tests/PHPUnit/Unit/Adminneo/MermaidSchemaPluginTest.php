<?php

namespace axenox\IDE\Tests\PHPUnit\Unit\Adminneo;

use AdminNeo\MermaidSchemaPlugin;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 5) . '/adminneo/admin/core/Plugin.php';
require_once dirname(__DIR__, 4) . '/Adminneo/MermaidSchemaPlugin.php';

/**
 * Verifies schema type labels without a database or Workbench.
 */
class MermaidSchemaPluginTest extends TestCase
{
    /**
     * Common SQL types retain their names and parameters without generated suffixes.
     */
    public function testSqlTypeLabelsRemainReadable(): void
    {
        $method = new \ReflectionMethod(MermaidSchemaPlugin::class, 'mermaidType');
        foreach (['bigint', 'datetime', 'nvarchar(max)', 'nvarchar(255)', 'decimal(18,2)', 'int[]'] as $type) {
            $this->assertSame($type, $method->invoke(null, $type));
        }
    }

    /**
     * Whitespace and source delimiters cannot introduce additional Mermaid statements.
     */
    public function testSqlTypeLabelsAreSingleGrammarTokens(): void
    {
        $method = new \ReflectionMethod(MermaidSchemaPlugin::class, 'mermaidType');
        $this->assertSame('double_precision', $method->invoke(null, 'double precision'));
        $this->assertSame('v_123', $method->invoke(null, '123'));
        $this->assertSame('text____injected__', $method->invoke(null, "text\n} \"injected\"{"));
    }
}
