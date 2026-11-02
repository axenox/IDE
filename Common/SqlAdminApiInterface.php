<?php
namespace axenox\IDE\Common;

use exface\Core\Interfaces\DataSources\SqlDataConnectorInterface;
use exface\Core\Interfaces\WorkbenchDependantInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Contract shared by the embeddable SQL administration UI and AI tools.
 *
 * Implemented by {@see AdminneoAPI} using the axenox/adminneo package.
 *
 * @author andrej.kabachnik
 */
interface SqlAdminApiInterface extends RequestHandlerInterface, WorkbenchDependantInterface
{
    /**
     * Returns the DDL (CREATE script) for a given table or view.
     *
     * Returns a comment with an error if the table or view was not found.
     *
     * @param SqlDataConnectorInterface $connection
     * @param string $tableOrViewName
     * @param string|null $schema
     * @param string|null $style - e.g. `DROP+CREATE` or `CREATE`
     * @return string
     */
    public function exportDDL(SqlDataConnectorInterface $connection, string $tableOrViewName, ?string $schema = null, ?string $style = 'CREATE') : string;

    /**
     * Runs an SQL query through the SQL admin tool and returns the resulting rows.
     *
     * @param SqlDataConnectorInterface $connection
     * @param string $sql
     * @return array
     */
    public function runSql(SqlDataConnectorInterface $connection, string $sql) : array;

    /**
     * Runs an SQL query and returns its rows together with normalized runtime statistics.
     *
     * Implementations without runtime statistics support return an empty statistics array.
     *
     * @param SqlDataConnectorInterface $connection
     * @param string $sql
     * @return array{rows: array, statistics: array}
     */
    public function runSqlWithRuntimeStatistics(SqlDataConnectorInterface $connection, string $sql) : array;

    /**
     * Returns the dialect-specific EXPLAIN result for an SQL query when supported.
     *
     * Implementations without programmatic EXPLAIN support return an empty array.
     *
     * @param SqlDataConnectorInterface $connection
     * @param string $sql
     * @return array
     */
    public function explainSql(SqlDataConnectorInterface $connection, string $sql) : array;
}