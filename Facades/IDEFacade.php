<?php
namespace axenox\IDE\Facades;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use exface\Core\Facades\AbstractHttpFacade\AbstractHttpFacade;
use GuzzleHttp\Psr7\Response;
use exface\Core\DataTypes\StringDataType;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\DataTypes\ComparatorDataType;
use axenox\IDE\Common\AtheosAPI;
use exface\Core\DataTypes\JsonDataType;
use exface\Core\DataTypes\FilePathDataType;
use exface\Core\Exceptions\UnexpectedValueException;
use exface\Core\CommonLogic\Selectors\DataConnectionSelector;

/**
 * 
 * @author andrej.kabachnik
 *
 */

class IDEFacade extends AbstractHttpFacade
{
    protected function createResponse(ServerRequestInterface $request) : ResponseInterface
    {
        $uri = $request->getUri();
        $path = $uri->getPath();
        
        // api/ide/adminer/localhost/ -> adminer/localhost/
        $pathInFacade = mb_strtolower(StringDataType::substringAfter($path, $this->getUrlRouteDefault() . '/'));
        
        switch (true) {     
            // Autologin via function runAdminer
            case StringDataType::startsWith($pathInFacade, 'adminer/'):
                return $this->runAdminer($pathInFacade);
            case StringDataType::startsWith($pathInFacade, 'atheos/'):
                $path = $this->getApp()->getDirectoryAbsolutePath() . DIRECTORY_SEPARATOR . 'Atheos';
                $api = new AtheosAPI($this->getWorkbench(), 'atheos/', $path, 'index.php');
                return $api->handle($request);
                break;  
        }
        
        return new Response(404, [], 'Nothing here yet!');
  
    }

    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\Facades\AbstractHttpFacade\AbstractHttpFacade::getUrlRouteDefault()
     */
    public function getUrlRouteDefault(): string
    {
        return 'api/ide';
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
                $password = $connectionConfig['password'];
                if ($password === '' || $password === null) {
                    $password = '12345678';
                }
                $auth = [
                    'server' => $connectionConfig['host'],
                    'username' => $connectionConfig['user'],
                    'password' => $password,
                    'driver' => $this->getAdminerDriver($connectorClass),
                    'db'    => $connectionConfig['dbase']
                ];
                break;
            // Microsoft SQL Server
            case stripos($connectorClass, 'mssql') !== false:
                $password = $connectionConfig['PWD'] ?? $connectionConfig['password'];
                if ($password === '' || $password === null) {
                    $password = '12345678';
                }
                $auth = [
                    'server' => $connectionConfig['serverName'] ?? $connectionConfig['host'],
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
            'pgsql' => 'pgsql',
            'oracle' => 'oracle',
            'mssql' => 'mssql_mod',
            'mongo' => 'mongo',
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
        $target = StringDataType::substringAfter($pathInFacade, 'adminer/');
        $selector = rtrim($target, '/');
        $base = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'Adminer' . DIRECTORY_SEPARATOR;
        switch (true) {
            
            case ! file_exists($base . $selector):   
                if (isset($_POST['logout'])) {
                    $_GET = [];
                    $_POST = [];
                }
                if(!count($_GET)) {
                    
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
                }
                
                // open Adminer
                $html = $this->launchAdminer();
                // IDEA replacing X-Frme-Options did not work well. Sometimes the headers are sent earlier.
                // How to prevent sending headers??? Override header() function somehow?
                // remove Adminer denial of integration into iBrowser
                // header_remove('X-Frame-Options');
                $headers = headers_list();
                $headers['X-Frame-Options'] = 'SAMEORIGIN';
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
                return new Response(200, ['Content-Type' => $mimeType], $stream);
        }
    }
    
    /**
     * 
     * @return string|NULL
     */
    protected function launchAdminer() : ?string
    {
        global $adminer;
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
}