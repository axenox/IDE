<?php
namespace axenox\IDE\AI\Agents;

use axenox\GenAI\AI\Agents\GenericAssistant;
use axenox\GenAI\Exceptions\AiAgentRuntimeError;
use axenox\GenAI\Factories\AiFactory;
use axenox\GenAI\Interfaces\AiPromptInterface;
use axenox\IDE\AI\Tools\CallSqlTool;
use axenox\IDE\AI\Tools\GetSqlTableDdlTool;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Factories\DataConnectionFactory;
use exface\Core\Interfaces\DataSources\SqlDataConnectorInterface;
use exface\Core\Templates\Placeholders\ArrayPlaceholders;
use exface\Core\Templates\BracketHashStringTemplateRenderer;

/**
 * AI assistant for use with the built-in SQL admin tool (based on PHP Adminer)
 * 
 * @author Andrej Kabachnik
 */
class SqlAdminAssistant extends GenericAssistant
{
    private $sqlConnectionCache = [];
    
    public function getSqlConnection(AiPromptInterface $prompt) : SqlDataConnectorInterface
    {
        foreach ($this->sqlConnectionCache as $cache) {
            if ($cache['prompt'] === $prompt) {
                return $cache['connection'];
            }
        }

        $inputSheet = $prompt->getInputData();

        if (! $inputSheet->getMetaObject()->is('exface.Core.CONNECTION')) {
            throw new AiAgentRuntimeError($this, 'Cannot use object "' . $inputSheet->getMetaObject()->__toString() . '" in SQL AI agent ' . $this->getAliasWithNamespace());
        }

        // TODO add more validation: must have 1 row, must have UID column, etc.

        $connectionUid = $inputSheet->getUidColumn()->getValue(0);
        $connection = DataConnectionFactory::createFromModel($this->getWorkbench(), $connectionUid);
        if (! $connection instanceof SqlDataConnectorInterface) {
            throw new AiAgentRuntimeError($this, 'Invalid connection type for SQL AI agent: "' . $connection->getAliasWithNamespace() . '" is not an SQL connection!');
        }
        
        $this->sqlConnectionCache[] = [
            'prompt' => $prompt,
            'connection' => $connection
        ];
        
        return $connection;
    }
    
    protected function getConcepts(AiPromptInterface $prompt, BracketHashStringTemplateRenderer $configRenderer) : array
    {
        $concepts = parent::getConcepts($prompt, $configRenderer);
        $connection = $this->getSqlConnection($prompt);
        $concepts[] = new ArrayPlaceholders([
            '~sql_dialect' => $connection->getSqlDialect()
        ]);
        
        return $concepts;
    }
}