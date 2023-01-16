<?php
namespace axenox\IDE\Facades;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use exface\Core\Facades\AbstractHttpFacade\AbstractHttpFacade;

/**
 * 
 * @author andrej.kabachnik
 *
 */
class IDEFacade extends AbstractHttpFacade
{
    protected function createResponse(ServerRequestInterface $request): ResponseInterface
    {
        // TODO
    }

    public function getUrlRouteDefault(): string
    {
        'api/ide';
    }
}