<?php
namespace axenox\IDE\Common;

use exface\Core\Interfaces\DataSources\SqlDataConnectorInterface;
use exface\Core\Interfaces\WorkbenchDependantInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Common contract for the embeddable SQL administration tools (Adminer / AdminNeo).
 *
 * The IDE can run different SQL admin front-ends behind the same facade route. Each of them is
 * wrapped by an implementation of this interface, so {@see \axenox\IDE\Facades\IDEFacade} and the
 * AI tools can switch between them simply by instantiating a different class.
 *
 * Current implementations:
 *
 * - {@see AdminneoAPI} - the axenox/adminneo fork (cleaner default theme, config-file driven).
 * - {@see AdminerAPI} - the axenox/adminer 6.x fork.
 * - {@see Adminer4API} - the legacy bundled Adminer 4.8.2 sources.
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
}
