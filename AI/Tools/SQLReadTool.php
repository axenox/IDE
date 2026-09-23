<?php
namespace axenox\IDE\AI\Tools;

use axenox\IDE\AI\Common\SqlSelectQueryValidator;
use axenox\GenAI\Common\AiToolResultString;
use axenox\GenAI\Exceptions\AiToolRuntimeError;
use axenox\GenAI\Interfaces\AiAgentInterface;
use axenox\GenAI\Interfaces\AiPromptInterface;
use axenox\GenAI\Interfaces\AiToolResultInterface;
use exface\Core\CommonLogic\Actions\ServiceParameter;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\DataTypes\BooleanDataType;
use exface\Core\DataTypes\MarkdownDataType;
use exface\Core\Interfaces\WorkbenchInterface;

/**
 * Runs read-oriented SQL queries and returns their result as a Markdown table.
 *
 * **WARNING:** This tool attempts to only allow read queries, but it cannot guarantee that all queries are safe! 
 * The validation is a defense against accidental writes, not a security boundary. Database
 * credentials restricted to read-only access are required to guarantee that the query cannot
 * modify data because SELECT statements and user-defined functions can have side effects.
 * 
 * @author Andrej Kabachnik
 */
class SQLReadTool extends SQLPerformTool
{
    private bool $allowMultipleQueries = false;

    /**
     * {@inheritDoc}
     * @see AiToolInterface::invoke()
     */
    public function invoke(AiAgentInterface $agent, AiPromptInterface $prompt, array $arguments): AiToolResultInterface
    {
        $statement = $arguments[0] ?? '';
        $connectionAlias = $arguments[1] ?? null;
        $explain = BooleanDataType::cast($arguments[2] ?? false) ?? false;
        $validationError = SqlSelectQueryValidator::validate($statement, $this->allowMultipleQueries);
        if ($validationError !== null) {
            throw new AiToolRuntimeError($this, $prompt, $validationError);
        }

        $connection = $this->getConnection($agent, $prompt, $connectionAlias);
        $sqlAdminApi = $this->getSqlAdminApi();
        $results = [];
        foreach (SqlSelectQueryValidator::splitStatements($statement) as $query) {
            if ($explain) {
                $executionResult = $sqlAdminApi->runSqlWithRuntimeStatistics($connection, $query);
                $queryResult = MarkdownDataType::buildMarkdownTableFromArray($executionResult['rows']);
                $explainResult = MarkdownDataType::buildMarkdownTableFromArray($sqlAdminApi->explainSql($connection, $query));
                $queryResult .= "\n\n#### Explain\n\n" . $explainResult;
                if ($executionResult['statistics'] !== []) {
                    $statisticsResult = MarkdownDataType::buildMarkdownTableFromArray($executionResult['statistics']);
                    $queryResult .= "\n\n#### Runtime statistics\n\n" . $statisticsResult;
                }
            } else {
                $queryResult = MarkdownDataType::buildMarkdownTableFromArray($sqlAdminApi->runSql($connection, $query));
            }
            $results[] = $queryResult;
        }
        if (count($results) === 1) {
            $result = $results[0];
        } else {
            $resultParts = [];
            foreach ($results as $index => $queryResult) {
                $resultParts[] = '### Query ' . ($index + 1) . "\n\n" . $queryResult;
            }
            $result = implode("\n\n", $resultParts);
        }
        return new AiToolResultString($this, $arguments, $result, $this->getReturnDataType());
    }

    /**
     * Set to TRUE to allow multiple read-oriented queries in one tool call.
     *
     * Every query is validated independently. This option does not make SQL validation a security
     * boundary; use database credentials restricted to read-only access for that guarantee.
     *
     * @uxon-property allow_multiple_queries
     * @uxon-type boolean
     * @uxon-default false
     *
     * @param bool $value
     * @return SQLReadTool
     */
    protected function setAllowMultipleQueries(bool $value) : SQLReadTool
    {
        $this->allowMultipleQueries = $value;
        return $this;
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
                ->setRequired(true)
                ->setDescription('One or more complete SELECT queries to execute'),
            (new ServiceParameter($self))
                ->setName('connection')
                ->setDescription('UID or namespaced alias of the SQL data connection.')
                ->setRequired(true)
                ->setExamples(['exface.Core.METAMODEL_DB', '0x11ea72c00f0fadeca3480205857feb80']),
            (new ServiceParameter($self))
                ->setDataType(new UxonObject(['alias' => 'exface.Core.Boolean']))
                ->setName('explain')
                ->setDescription('Whether to append dialect-specific EXPLAIN result')
                ->setDefaultValue(false)
        ];
    }
}