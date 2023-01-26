<?php
namespace axenox\IDE\Facades;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use exface\Core\Facades\AbstractHttpFacade\AbstractHttpFacade;
use GuzzleHttp\Psr7\Response;
use exface\Core\DataTypes\StringDataType;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\DataTypes\ComparatorDataType;
use exface\Core\DataTypes\JsonDataType;
use exface\Core\DataTypes\FilePathDataType;
use exface\Core\Exceptions\UnexpectedValueException;

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
            // Autologin
            case (StringDataType::startsWith($pathInFacade, 'adminer/')):
                if(!count($_GET)) {
                    $target = StringDataType::substringAfter($pathInFacade, 'adminer/');
                    // adminer/localhost/ -> localhost
                    $selector = rtrim($target, '/');
                    $dataSheet = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), 'exface.Core.CONNECTION');
                    $dataSheet->getFilters()->addConditionFromString('ALIAS_WITH_NS', $selector, ComparatorDataType::EQUALS);
                    $dataSheet->getColumns()->addMultiple([
                        'CONFIG',
                        'CONNECTOR',
                        'UID',
                        
                    ]);
                    $dataSheet->dataRead();
                    
                    $row = $dataSheet->getRowsDecrypted()[0] ?? null;
                    if ($row === null) {
                        throw new UnexpectedValueException('Data connection "' . $selector . '" not found!');
                    }
                    
                    $configJSON = $row['CONFIG'];
                    $config = JsonDataType::decodeJson($configJSON);
                    $connector = $row['CONNECTOR'];
                    
                    $_POST['auth'] = $this->getAdminerAuth($config, $connector); 
                }
                
                // Adminer aufrufen
                $html = $this->launchAdminer();
                return new Response(200, [], $html);
                break; 
              
            //Dateien einlesen   
            case StringDataType::startsWith($pathInFacade, 'adminer/'):
                $file = StringDataType::substringAfter($pathInFacade, 'adminer/');
                $base = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'Adminer' . DIRECTORY_SEPARATOR;
                if(file_exists($base.$file)) {
                    $stream = fopen($base.$file, 'r');
                    switch (FilePathDataType::findExtension($file)) {
                        // CSS-Datei einlesen
                        case 'css':
                            $mimeType = 'text/css';
                            break;
                        // Andere Dateien einlesen
                        Default:
                            $mimeType = mime_content_type($base.$file);
                            break;
                    }
                    return new Response(200, ['content-type' => $mimeType], $stream);
                }
                
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
        switch (true) {
            case stripos($connectorClass, 'mysql') !== false:
                $password = $connectionConfig['password'];
                if ($password === '' || $password === null) {
                    $password = '12345678';
                }
                return [
                    'server' => $connectionConfig['host'],
                    'username' => $connectionConfig['user'],
                    'password' => $password,
                    'driver' => $this->getAdminerDriver($connectorClass),
                    'db'    => $connectionConfig['dbase']
                ]; 
            case stripos($connectorClass, 'mssql') !== false:
                $password = $connectionConfig['PWD'] ?? $connectionConfig['password'];
                if ($password === '' || $password === null) {
                    $password = '12345678';
                }
                return [
                    'server' => $connectionConfig['serverName'] ?? $connectionConfig['host'],
                    'username' => $connectionConfig['UID'] ?? $connectionConfig['user'],
                    'password' => $password,
                    'driver' => $this->getAdminerDriver($connectorClass),
                    'db'    => $connectionConfig['database'] ?? $connectionConfig['dbase']
                ]; 
        }
        return null;
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
            'mssql' => 'mssql',
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
     * @return string|NULL
     */
    protected function launchAdminer() : ?string
    {
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