<?php
namespace axenox\IDE\Facades;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use exface\Core\Facades\AbstractHttpFacade\AbstractHttpFacade;
use GuzzleHttp\Psr7\Response;

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
        return new Response(404, [], 'Nothing here yet!');
    }

    public function getUrlRouteDefault(): string
    {
        return 'api/ide';
    }
}