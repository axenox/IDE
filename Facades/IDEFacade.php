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
                ob_start();
                $this->launchAdminer();
                $html = ob_get_contents();
                ob_end_clean();
                return new Response(200);
                break;
        }
        
        return new Response(404, [], 'Nothing here yet!');
    }

    public function getUrlRouteDefault(): string
    {
        return 'api/ide';
    }
    
    protected function launchAdminer()
    {
        include __DIR__ . '/../Adminer/adminer.php';
    }
}