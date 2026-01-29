<?php
namespace axenox\IDE\Common;

use exface\Core\CommonLogic\Selectors\DataConnectionSelector;
use exface\Core\DataTypes\ComparatorDataType;
use exface\Core\DataTypes\FilePathDataType;
use exface\Core\DataTypes\JsonDataType;
use exface\Core\DataTypes\StringDataType;
use exface\Core\Exceptions\UnexpectedValueException;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Interfaces\DataSources\SqlDataConnectorInterface;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
class AdminerAPI extends InclusionAPI
{
    const NO_PASSWROD = '12345678';
    
    /**
     * 
     * {@inheritDoc}
     * @see \Psr\Http\Server\RequestHandlerInterface::handle()
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        $innerPath = StringDataType::substringAfter($path, $this->getBaseUrlPath(), '');
        return $this->runAdminer($innerPath);
    }

    
    
    /**
     * 
     * @param array $connectionConfig
     * @param string $connectorClass
     * @return array|NULL
     */
    protected function getAdminerAuth(array $connectionConfig, string $connectorClass) : ?array
    {
        $auth = null;
        switch (true) {
            // MySQL, MariaDB
            case stripos($connectorClass, 'mariadb') !== false:
            case stripos($connectorClass, 'mysql') !== false:
            case stripos($connectorClass, 'postgresql') !== false:
                $password = $connectionConfig['password'];
                if ($password === '' || $password === null) {
                    $password = AdminerAPI::NO_PASSWROD;
                }
                $auth = [
                    'server' => $connectionConfig['host'] . ($connectionConfig['port'] ? ':' . $connectionConfig['port'] : ''),
                    'username' => $connectionConfig['user'],
                    'password' => $password,
                    'driver' => $this->getAdminerDriver($connectorClass),
                    'db'    => $connectionConfig['dbase']
                ];
                // SSL config
                if (null !== $sslVal = $connectionConfig['ssl_key'] ?? null) {
                    $auth['ssl']['key'] = $sslVal;
                }
                if (null !== $sslVal = $connectionConfig['ssl_certificate_path'] ?? null) {
                    $auth['ssl']['cert'] = $this->getPathInWorkbench($sslVal);
                }
                if (null !== $sslVal = $connectionConfig['ssl_ca_certificate_path'] ?? null) {
                    $auth['ssl']['ca'] = $this->getPathInWorkbench($sslVal);
                }
                break;
            // Microsoft SQL Server
            case stripos($connectorClass, 'mssql') !== false:
                $password = $connectionConfig['PWD'] ?? $connectionConfig['password'];
                if ($password === '' || $password === null) {
                    $password = AdminerAPI::NO_PASSWROD;
                }
                $auth = [
                    'server' => ($connectionConfig['serverName'] ?? $connectionConfig['host']) . ($connectionConfig['port'] ? ':' . $connectionConfig['port'] : ''),
                    'username' => $connectionConfig['UID'] ?? $connectionConfig['user'],
                    'password' => $password,
                    'driver' => $this->getAdminerDriver($connectorClass),
                    'db'    => $connectionConfig['database'] ?? $connectionConfig['dbase']
                ];
                if (array_key_exists('connection_options', $connectionConfig)) {
                    $auth['server'] .= ';Options=' . json_encode($connectionConfig['connection_options']);
                }
        }
        return $auth;
    }

    protected function getPathInWorkbench(string $path) : string
    {
        if (FilePathDataType::isAbsolute($path)) {
            return $path;
        }
        return $this->getWorkbench()->getInstallationPath() . DIRECTORY_SEPARATOR . $path;
    }
    
    /**
     *
     * @param string $connector
     * @return string|NULL
     */
    protected function getAdminerDriver(string $connector) : ?string
    {
        $adminerDrivers = [
            'mysql' => 'server',
            'sqlite' => 'sqlite', // what is the difference to sqlite2???
            'postgresql' => 'pgsql',
            'oraclesql' => 'oracle',
            'mssql' => 'mssql',
            'mongodb' => 'mongo',
            'elastic' => 'elastic'
        ];
        
        foreach ($adminerDrivers as $key => $driver) {
            if (stripos($connector, $key) !== false) {
                return $driver;
            }
        }
        
        return null;
    }
    
    /**
     * 
     * @return string|NULL
     */
    protected function getAdminerDbInSession() : ?string
    {
        $pwds = $_SESSION['pwds'];
        if ($pwds === null) {
            return null;
        }
        $servers = $pwds['server'];
        $serverName = null;
        foreach ($servers as $serverName => $serverData) {
            foreach ($serverData as $userName => $userData) {
                if (! empty($userData)) {
                    return $serverName;
                }
            }
        }
        return null;
    }
    
    /**
     * 
     * @param string $pathInFacade
     * @throws UnexpectedValueException
     * @return ResponseInterface
     */
    protected function runAdminer(string $pathInFacade) : ResponseInterface
    {
        if (StringDataType::startsWith($pathInFacade, 'externals/')) {
            $pathInFacade = 'adminer/' . $pathInFacade;
        }
        $target = StringDataType::substringAfter($pathInFacade, 'adminer/');
        $selector = rtrim($target, '/');
        $base = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'Adminer' . DIRECTORY_SEPARATOR;
        $baseSrc = $base . 'src' . DIRECTORY_SEPARATOR;
        $baseSrcAdminer = $base . 'adminer' . DIRECTORY_SEPARATOR;
        switch (true) {
            case file_exists($baseSrc . $selector):
            case file_exists($baseSrcAdminer . $selector):
                $file = $selector;
                $stream = fopen($baseSrcAdminer.$file, 'r');
                switch (FilePathDataType::findExtension($file)) {
                    // read css-file
                    case 'css':
                        $mimeType = 'text/css';
                        break;
                        // read different file
                    case 'js':
                        $mimeType = 'text/javascript';
                        break;
                        // read different file
                    Default:
                        $mimeType = mime_content_type($base.$file);
                        break;
                }
                $headers = $this->getHeadersCommon();
                $headers['Content-Type'] = $mimeType;
                return new Response(200, $headers, $stream);
            case ! file_exists($base . $selector):   
                switch (true) {
                    case isset($_POST['logout']):
                        $_GET = [];
                        $_POST = [];
                        break;
                    case !count($_GET):
                        // adminer/localhost/ -> localhost
                        $dataSheet = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), 'exface.Core.CONNECTION');
                        $dataSheet->getFilters()->addConditionFromString('ALIAS_WITH_NS', $selector, ComparatorDataType::EQUALS);
                        $dataSheet->getColumns()->addMultiple([
                            'CONFIG',
                            'CONNECTOR',
                            'UID',  
                        ]);
                        
                        $dataSheet->dataRead();
                        
                        if (strcasecmp($selector, DataConnectionSelector::METAMODEL_CONNECTION_ALIAS) === 0 || strcasecmp($selector, DataConnectionSelector::METAMODEL_CONNECTION_UID) === 0) {
                            $config = $this->getWorkbench()->getCoreApp()->getConfig()->getOption('METAMODEL.CONNECTOR_CONFIG')->toArray();
                            $connector = $this->getWorkbench()->getCoreApp()->getConfig()->getOption('METAMODEL.CONNECTOR');
                        } else {
                            $row = $dataSheet->getRowsDecrypted()[0] ?? null;
                            if ($row === null) {
                                throw new UnexpectedValueException('Data connection "' . $selector . '" not found!');
                            }
                            
                            if ($row['CONFIG'] ?? null) {
                                $config = JsonDataType::decodeJson($row['CONFIG']);
                            } else {
                                $config = [];
                            }
                            $connector = $row['CONNECTOR'];
                        }
                        
                        $_POST['auth'] = $this->getAdminerAuth($config, $connector);
                        break;
                }
                
                // open Adminer
                $html = $this->launchAdminer();
                // IDEA replacing X-Frme-Options did not work well. Sometimes the headers are sent earlier.
                // How to prevent sending headers??? Override header() function somehow?
                // remove Adminer denial of integration into iBrowser
                // header_remove('X-Frame-Options');
                $headers = headers_list();
                $headers = array_merge($headers, $this->getHeadersCommon());
                return new Response(200, $headers, $html);
            
                
            // read different files    
            default:
                $file = $selector;
                $stream = fopen($base.$file, 'r');
                switch (FilePathDataType::findExtension($file)) {
                    // read css-file
                    case 'css':
                        $mimeType = 'text/css';
                        break;
                        // read different file
                    case 'js':
                        $mimeType = 'text/javascript';
                        break;
                        // read different file
                    Default:
                        $mimeType = mime_content_type($base.$file);
                        break;
                }
                $headers = $this->getHeadersCommon();
                $headers['Content-Type'] = $mimeType;
                return new Response(200, $headers, $stream);
        }
    }
    
    /**
     * 
     * @return string|NULL
     */
    protected function launchAdminer() : ?string
    {
        global $adminer;
        $workbench = $this->getWorkbench();
        ob_start();
        session_start();
        $cwd = getcwd();
        chdir(__DIR__ . '/../Adminer/');
        require 'adminer.php';
        $output = ob_get_contents();
        ob_end_clean();
        chdir($cwd);
        return $output;
    }

    /**
     * Returns the DDL (CREATE script) for a given table or view
     * 
     * Returns a comment with an error if the table or view was not found
     * 
     * @param SqlDataConnectorInterface $connection
     * @param string $tableOrViewName
     * @param string|null $schema
     * @param string|null $style - e.g. `DROP+CREATE` or `CREATE`
     * @return string
     */
    public function exportDDL(SqlDataConnectorInterface $connection, string $tableOrViewName, ?string $schema = null, ?string $style = 'CREATE') : string
    {
        global $adminer;
        $facadePath = $this->getApiUrlPath($connection, null, $schema);
        // TODO only run adminer if it was not run yet during the current HTTP request.
        $this->runAdminer($facadePath);

        $tableStatus = table_status($tableOrViewName);
        if (is_view($tableStatus)) {
            $viewStatus = view($tableStatus);
            $dump = "CREATE VIEW $tableOrViewName AS \n" . $viewStatus["select"];
        } else {
            $dump = create_sql($tableOrViewName, false, $style);
            if (empty($dump)) {
                $dump = '-- ERROR: table "' . $tableOrViewName . '" not found in schema/tablespace "' . $schema . '"';
            }
        }
        return $dump;
    }
    
    public function runSql(string $sql) : array
    {
        // TODO only allow SELECT queries - no DELETE, DROP, UPDATE, etc.
        global $adminer;
        global $connection;
        $connection->multi_query($sql);
        $result = $connection->store_result();
        // TODO transform result to array
    }
    
    protected function getApiUrlPath(SqlDataConnectorInterface $connection, ?string $function = null, ?string $schema = null) : string
    {
        // adminer/suedlink_tpcde_db_azure_dev?mssql=kmtssqlsrvdev.database.windows.net&username=kmtsadmin&db=SuedLinkKmtsDev&ns=dbo&dump=
        $adminerAuth = $this->getAdminerAuth($connection->exportUxonObject()->toArray(), get_class($connection));
        $url = '/adminer/' . $connection->getAliasWithNamespace();
        $_GET[$adminerAuth['driver']] = $adminerAuth['server'];
        $_GET['username'] = $adminerAuth['username'];
        $_GET['db'] = $adminerAuth['db'];
        $_GET['ns'] = $schema ?? '';
        if ($function !== null) {
            $_GET[$function] = '';
        }
        return $url;
    }

    /**
     * Returns the Adminer CSRF token stored in the session
     * 
     * Adminer uses CSRF tokens for every request. It seems, though, they are not required to call adminer functions
     * without processing an entire request. Should the CSRF token be required in future, you can find its logic in
     * `functions.inc.php` in `get_token()` and `verify_token()`.
     * 
     * @return string
     */
    private function getApiToken() : string
    {
        return $_SESSION['token'];
    }
}