<?php
namespace axenox\IDE\AI\Tools;

use axenox\GenAI\Common\AbstractAiTool;
use axenox\GenAI\Exceptions\AiToolRuntimeError;
use axenox\GenAI\Interfaces\AiAgentInterface;
use axenox\GenAI\Interfaces\AiPromptInterface;
use axenox\GenAI\Interfaces\AiToolInterface;
use axenox\IDE\AI\Agents\SqlAdminAssistant;
use axenox\IDE\Common\AdminerAPI;
use axenox\IDE\Facades\IDEFacade;
use exface\Core\CommonLogic\Actions\ServiceParameter;
use exface\Core\DataTypes\MarkdownDataType;
use exface\Core\Factories\DataConnectionFactory;
use exface\Core\Factories\DataTypeFactory;
use exface\Core\Factories\FacadeFactory;
use exface\Core\Interfaces\DataSources\SqlDataConnectorInterface;
use exface\Core\Interfaces\DataTypes\DataTypeInterface;
use exface\Core\Interfaces\WorkbenchInterface;

/**
 * This AI tool allows an LLM to fetch the JSON of a log details widget we see when clicking on a log entry in the log viewer.
 */
class CallSqlTool extends AbstractAiTool
{    
    /**
     * {@inheritDoc}
     * @see AiToolInterface::invoke()
     */
    public function invoke(AiAgentInterface $agent, AiPromptInterface $prompt, array $arguments): string
    {
        list($statement, $connectionAlias) = $arguments;
        
        return $this->callSQL($statement, $this->getConnection($agent, $prompt, $connectionAlias));
    }
    
    protected function getConnection(AiAgentInterface $agent, AiPromptInterface $prompt, ?string $connectionAlias = null) : SqlDataConnectorInterface
    {
        switch (true) {
            case $connectionAlias:
                $connection = DataConnectionFactory::createFromModel($this->getWorkbench(), $connectionAlias);
                break;
            case $agent instanceof SqlAdminAssistant:
                $connection = $agent->getSqlConnection($prompt);
                break;
            default:
                throw new AiToolRuntimeError($this, 'No connection provided for CallSqlTool');
        }
        return $connection;
    }
    
    protected function callSQL(string $sql, SqlDataConnectorInterface $connection): string
    {
        $ideFacade = FacadeFactory::createFromString(IDEFacade::class, $this->getWorkbench());
        $adminerAPI = new AdminerAPI($this->getWorkbench(), $ideFacade->getUrlRouteDefault() . '/', 'adminer/', 'index.php', []);
        $array = $adminerAPI->runSql($connection, $sql);
        return MarkdownDataType::buildMarkdownTableFromArray($array);
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
                ->setName('statement')
                ->setDescription('SQL statement to be performed'),
        ];
    }

    /**
     * {@inheritDoc}
     * @see AiToolInterface::getReturnDataType()
     */
    public function getReturnDataType(): DataTypeInterface
    {
        return DataTypeFactory::createFromPrototype($this->getWorkbench(), MarkdownDataType::class);
    }
}