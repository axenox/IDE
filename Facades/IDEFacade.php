<?php
namespace axenox\IDE\Facades;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use exface\Core\Facades\AbstractHttpFacade\AbstractHttpFacade;
use GuzzleHttp\Psr7\Response;
use exface\Core\DataTypes\StringDataType;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\DataTypes\ComparatorDataType;
use exface\Core\CommonLogic\UxonObject;
use axenox\IDE\Common\AtheosAPI;

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
        $target = mb_strtolower(StringDataType::substringAfter($path, $this->getUrlRouteDefault() . '/'));
        
        // api/ide/adminer/exface.Core.METAMODEL_DB
        
        $selector = 'exface.Core.METAMODEL_DB';
        $dataSheet = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), 'exface.Core.CONNECTION');
        $dataSheet->getFilters()->addConditionFromString('ALIAS_WITH_NS', $selector, ComparatorDataType::EQUALS);
        $dataSheet->getColumns()->addMultiple([
            'UID',
            'CONFIG'
        ]);
        $dataSheet->dataRead();
        
        $row = $dataSheet->getRow(0);
        $configUxon = UxonObject::fromJson($row['CONFIG']);
        
        switch (true) {
            case $target === 'adminer':
                $lastServer = $this->getAdminerDbInSession();
                $html = $this->launchAdminer();
                return new Response(200, [], $html);
                break;
            case StringDataType::startsWith($target, 'atheos'):
                $path = $this->getApp()->getDirectoryAbsolutePath() . DIRECTORY_SEPARATOR . 'Atheos';
                $api = new AtheosAPI($this->getWorkbench(), 'atheos/', $path, 'index.php');
                return $api->handle($request);
                break;  
        }
        
        return new Response(404, [], 'Nothing here yet!');
    }

    public function getUrlRouteDefault(): string
    {
        return 'api/ide';
    }
    
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
    
    protected function launchAdminer() : ?string
    {
        ob_start();
        session_start();
        require __DIR__ . '/../Adminer/adminer.php';
        $output = ob_get_contents();
        ob_end_clean();
        return $output;
    }
}