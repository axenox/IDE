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
 * This AI tool allows an LLM to fetch the JSON of a log details widget we see when clicking on a log entry in the log viewer.
 */
class GetSqlTableDdlTool extends CallSqlTool
{    
    /**
     * {@inheritDoc}
     * @see AiToolInterface::invoke()
     */
    public function invoke(AiAgentInterface $agent, AiPromptInterface $prompt, array $arguments): AiToolResultInterface
    {
        list($tableName, $schema) = $arguments;
        
        $result = $this->getDDL($this->getConnection($agent, $prompt), $tableName, $schema);
        return new AiToolResultString($this, $arguments, $result, $this->getReturnDataType());
    }

    /**
     * @param SqlDataConnectorInterface $connection
     * @param string $tableName
     * @param string|null $schema
     * @return string
     */
    protected function getDDL(SqlDataConnectorInterface $connection, string $tableName, ?string $schema = null): string
    {
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
                ->setDescription('Table name with schema prefix if necessary'),
            (new ServiceParameter($self))
                ->setName('schema')
                ->setDescription('Table name with schema prefix if necessary')
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