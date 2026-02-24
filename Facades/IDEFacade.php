<?php
namespace axenox\IDE\Facades;

use axenox\IDE\Common\AdminerAPI;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use exface\Core\Facades\AbstractHttpFacade\AbstractHttpFacade;
use GuzzleHttp\Psr7\Response;
use exface\Core\DataTypes\StringDataType;
use axenox\IDE\Common\AtheosAPI;
use exface\Core\DataTypes\UrlDataType;

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
            case StringDataType::startsWith($pathInFacade, 'externals/'):
                $basePath = $this->getApp()->getDirectoryAbsolutePath() . DIRECTORY_SEPARATOR . 'Atheos';
                $api = new AdminerAPI($this->getWorkbench(), $this->getUrlRouteDefault() . '/', $path, 'index.php', $this->buildHeadersCommon());
                return $api->handle($request);
            case StringDataType::startsWith($pathInFacade, 'atheos/'):
                $basePath = $this->getApp()->getDirectoryAbsolutePath() . DIRECTORY_SEPARATOR . 'Atheos';
                $api = new AtheosAPI($this->getWorkbench(), 'atheos/', $basePath, 'index.php', $this->buildHeadersCommon());
                return $api->handle($request);
        }
        
        return new Response(404, $this->buildHeadersCommon(), 'Nothing here yet!');
  
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
     * @return array
     */
    protected function buildHeadersCommon() : array
    {
        $baseHeaders = parent::buildHeadersCommon();
        $facadeHeaders = array_filter($this->getConfig()->getOption('FACADE.HEADERS.COMMON')->toArray());
        
        $workbenchHosts = [];
        foreach ($this->getWorkbench()->getConfig()->getOption('SERVER.BASE_URLS') as $url) {
            $host = UrlDataType::findHost($url);
            if ($host) {
                $workbenchHosts[] = $host;
            }
        }
        
        $cspString = '';
        foreach ($this->getConfig()->getOptionGroup('FACADE.HEADERS.CONTENT_SECURITY_POLICY', true) as $directive => $values) {
            // Skip the directive if the config option has no value (thus removing the directive)
            if (empty($values)) {
                continue;
            }
            // Otherwise add this directive to the policy
            $directive = str_replace('_', '-', mb_strtolower($directive));
            if ($directive === 'flags') {
                $cspString .= $values . ' ; ';
            } else {
                // Add the hosts of the workbench base URLs to every directive to aviod issues
                // with workbenches behind reverse proxies, where the same workbench can be
                // reached through different URLs.
                $cspString .= $directive . ' ' . implode(' ', $workbenchHosts) . ' ' . $values . ' ; ';
            }
        }
        
        $secPolHeaders = ['Content-Security-Policy' => $cspString];
        return array_merge($baseHeaders, $secPolHeaders, $facadeHeaders);
    }
}