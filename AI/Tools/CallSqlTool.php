<?php
namespace axenox\IDE\AI\Tools;

use axenox\GenAI\Common\AbstractAiTool;
use axenox\GenAI\Common\AiToolResultString;
use axenox\GenAI\Exceptions\AiToolRuntimeError;
use axenox\GenAI\Interfaces\AiAgentInterface;
use axenox\GenAI\Interfaces\AiPromptInterface;
use axenox\GenAI\Interfaces\AiToolInterface;
use axenox\GenAI\Interfaces\AiToolResultInterface;
use axenox\IDE\AI\Agents\SqlAdminAssistant;
use axenox\IDE\Common\AdminerAPI;
use axenox\IDE\Common\AdminneoAPI;
use axenox\IDE\Common\SqlAdminApiInterface;
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
    public function invoke(AiAgentInterface $agent, AiPromptInterface $prompt, array $arguments): AiToolResultInterface
    {
        list($statement, $connectionAlias) = $arguments;
        
        $result = $this->callSQL($statement, $this->getConnection($agent, $prompt, $connectionAlias));
        return new AiToolResultString($this, $arguments, $result, $this->getReturnDataType());
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
                throw new AiToolRuntimeError($this, $prompt, 'No connection provided for CallSqlTool');
        }
        return $connection;
    }
    
    protected function callSQL(string $sql, SqlDataConnectorInterface $connection): string
    {
        $array = $this->getSqlAdminApi()->runSql($connection, $sql);
        return MarkdownDataType::buildMarkdownTableFromArray($array);
    }

    /**
     * Instantiates the configured SQL admin API (Adminer or AdminNeo).
     *
     * The engine is selected via the `SQL_ADMIN.ENGINE` option of the axenox.IDE app, so the AI
     * tools follow the same switch as the facade.
     *
     * @return SqlAdminApiInterface
     */
    protected function getSqlAdminApi() : SqlAdminApiInterface
    {
        /** @var \axenox\IDE\Facades\IDEFacade $ideFacade */
        $ideFacade = FacadeFactory::createFromString(IDEFacade::class, $this->getWorkbench());
        $baseUrl = $ideFacade->getUrlRouteDefault() . '/';
        $engine = mb_strtolower((string) $this->getWorkbench()->getApp('axenox.IDE')->getConfig()->getOption('SQL_ADMIN.ENGINE'));
        switch ($engine) {
            case 'adminneo':
                return new AdminneoAPI($this->getWorkbench(), $baseUrl, 'adminneo/', 'index.php', []);
            default:
                return new AdminerAPI($this->getWorkbench(), $baseUrl, 'adminer/', 'index.php', []);
        }
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