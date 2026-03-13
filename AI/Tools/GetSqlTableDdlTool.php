<?php
namespace axenox\IDE\AI\Tools;

use axenox\GenAI\Common\AbstractAiTool;
use axenox\GenAI\Interfaces\AiAgentInterface;
use axenox\GenAI\Interfaces\AiPromptInterface;
use axenox\GenAI\Interfaces\AiToolInterface;
use axenox\IDE\Common\AdminerAPI;
use axenox\IDE\Facades\IDEFacade;
use exface\Core\CommonLogic\Actions\ServiceParameter;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\DataTypes\ComparatorDataType;
use exface\Core\DataTypes\MarkdownDataType;
use exface\Core\DataTypes\SqlDataType;
use exface\Core\DataTypes\StringDataType;
use exface\Core\Facades\DocsFacade\MarkdownPrinters\LogEntryMarkdownPrinter;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Factories\DataTypeFactory;
use exface\Core\Factories\FacadeFactory;
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
    public function invoke(AiAgentInterface $agent, AiPromptInterface $prompt, array $arguments): string
    {
        list($tableName, $schema) = $arguments;
        return $this->getDDL($this->getConnection($agent, $prompt), $tableName, $schema);
    }

    /**
     * @param SqlDataConnectorInterface $connection
     * @param string $tableName
     * @param string|null $schema
     * @return string
     */
    protected function getDDL(SqlDataConnectorInterface $connection, string $tableName, ?string $schema = null): string
    {
        $ideFacade = FacadeFactory::createFromString(IDEFacade::class, $this->getWorkbench());
        $adminerAPI = new AdminerAPI($this->getWorkbench(), $ideFacade->getUrlRouteDefault() . '/', 'adminer/', 'index.php', []);
        return $adminerAPI->exportDDL($connection, $tableName, $schema);
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