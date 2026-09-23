<?php
namespace axenox\IDE\AI\Tools;

use axenox\GenAI\Common\AiToolResultString;
use axenox\GenAI\Interfaces\AiAgentInterface;
use axenox\GenAI\Interfaces\AiPromptInterface;
use axenox\GenAI\Interfaces\AiToolResultInterface;
use exface\Core\CommonLogic\Actions\ServiceParameter;
use exface\Core\DataTypes\SqlDataType;
use exface\Core\Factories\DataTypeFactory;
use exface\Core\Interfaces\DataSources\SqlDataConnectorInterface;
use exface\Core\Interfaces\DataTypes\DataTypeInterface;
use exface\Core\Interfaces\WorkbenchInterface;

/**
 * Returns the DDL of a table, view, stored procedure or function from an SQL database.
 * 
 * @author Andrej Kabachnik, Brooklyn Fränzschky
 */
class SQLDumpDDLTool extends SQLPerformTool
{    
    /**
     * {@inheritDoc}
     * @see AiToolInterface::invoke()
     */
    public function invoke(AiAgentInterface $agent, AiPromptInterface $prompt, array $arguments): AiToolResultInterface
    {
        list($tableName, $schema, $connectionAlias) = $arguments;
        
        $result = $this->getDDL($this->getConnection($agent, $prompt, $connectionAlias), $tableName, $schema);
        return new AiToolResultString($this, $arguments, $result, $this->getReturnDataType());
    }

    /**
     * Returns the CREATE statement for a table, view or routine supported by the active SQL admin API.
     *
     * @param SqlDataConnectorInterface $connection
     * @param string $tableName Legacy argument name; may also contain a view, procedure or function name.
     * @param string|null $schema
     * @return string
     */
    protected function getDDL(SqlDataConnectorInterface $connection, string $tableName, ?string $schema = null): string
    {
        // Stored procedure and function export is currently implemented by AdminneoAPI only.
        return $this->getSqlAdminApi()->exportDDL($connection, $tableName, $schema);
    }

    /**
     * {@inheritDoc}
     * @see AbstractAiTool::getArgumentsTemplates()
     */
    protected static function getArgumentsTemplates(WorkbenchInterface $workbench) : array
    {
        $self = new self($workbench);
        return [
            (new ServiceParameter($self))
                ->setName('table_name')
                ->setDescription('Name of the table, view, stored procedure or function without schema prefix')
                ->setRequired(true),
            (new ServiceParameter($self))
                ->setName('schema')
                ->setRequired(true)
                ->setDescription('Schema containing the table, view, stored procedure or function. Use `null` if it is not applicable'),
            (new ServiceParameter($self))
                ->setName('connection')
                ->setRequired(true)
                ->setDescription('UID or namespaced alias of the SQL data connection.')
                ->setExamples(['exface.Core.METAMODEL_DB', '0x11ea72c00f0fadeca3480205857feb80'])
        ];
    }

    /**
     * {@inheritDoc}
     * @see AiToolInterface::getReturnDataType()
     */
    public function getReturnDataType(): DataTypeInterface
    {
        return DataTypeFactory::createFromPrototype($this->getWorkbench(), SqlDataType::class);
    }
}