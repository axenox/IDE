<?php
namespace axenox\IDE\AI\Tools;

use axenox\GenAI\Common\AbstractAiTool;
use axenox\GenAI\Common\AiToolResultString;
use axenox\GenAI\Exceptions\AiToolRuntimeError;
use axenox\GenAI\Interfaces\AiTaskHandlerInterface;
use axenox\GenAI\Interfaces\AiToolResultInterface;
use axenox\IDE\AI\Agents\SqlAdminAssistant;
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
use exface\Core\Interfaces\Tasks\TaskInterface;
use exface\Core\Interfaces\WorkbenchInterface;

/**
 * Performs an SQL statement on a database and returns the result as a Markdown table.
 * 
 * **WARNING:** This tool is dangerous! NEVER run it on production databases!!!
 * 
 * ```json
 * {"alias": "axenox.IDE.SQLPerformTool"}
 * 
 * ```
 * 
 * @author Andrej Kabachnik, Brooklyn Fränzschky
 */
class SQLPerformTool extends AbstractAiTool
{    
    /**
     * {@inheritDoc}
     * @see AiToolInterface::invoke()
     */
    public function invoke(AiTaskHandlerInterface $agent, TaskInterface $prompt, array $arguments): AiToolResultInterface
    {
        list($statement, $connectionAlias) = $arguments;
        
        $result = $this->callSQL($statement, $this->getConnection($agent, $prompt, $connectionAlias));
        return new AiToolResultString($this->getWorkbench(), $result, $this->getReturnDataType());
    }
    
    /**
     * Resolves the explicit or agent-provided SQL connection for this invocation.
     * 
     * @throws AiToolRuntimeError
     * @param AiTaskHandlerInterface $agent
     * @param TaskInterface $prompt
     * @param string|null $connectionAlias
     * @return SqlDataConnectorInterface
     */
    protected function getConnection(AiTaskHandlerInterface $agent, TaskInterface $prompt, ?string $connectionAlias = null) : SqlDataConnectorInterface
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
    
    /**
     * Executes the SQL statement and renders its returned rows as Markdown.
     * 
     * @param string $sql
     * @param SqlDataConnectorInterface $connection
     * @return string
     */
    protected function callSQL(string $sql, SqlDataConnectorInterface $connection): string
    {
        $array = $this->getSqlAdminApi()->runSql($connection, $sql);
        return MarkdownDataType::buildMarkdownTableFromArray($array);
    }

    /**
    * Instantiates the AdminNeo API shared by the SQL administration tools.
     * 
     * @return SqlAdminApiInterface
     */
    protected function getSqlAdminApi() : SqlAdminApiInterface
    {
        /** @var \axenox\IDE\Facades\IDEFacade $ideFacade */
        $ideFacade = FacadeFactory::createFromString(IDEFacade::class, $this->getWorkbench());
        $baseUrl = $ideFacade->getUrlRouteDefault() . '/';
        return new AdminneoAPI($this->getWorkbench(), $baseUrl, 'adminneo/', 'index.php', []);
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
                ->setDescription('SQL statement to be performed')
                ->setRequired(true),
            (new ServiceParameter($self))
                ->setName('connection')
                ->setRequired(false)
                ->setDescription('UID or namespaced alias of the SQL data connection. Omit or use null to use the SQL admin assistant connection. Required for other agents.')
                ->setExamples(['exface.Core.METAMODEL_DB', '0x11ea72c00f0fadeca3480205857feb80'])
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