<?php
namespace axenox\IDE\Facades;

use axenox\IDE\Common\Adminer4API;
use axenox\IDE\Common\AdminneoAPI;
use exface\Core\DataTypes\FilePathDataType;
use exface\Core\Exceptions\Facades\FacadeRoutingError;
use exface\Core\Interfaces\Selectors\AliasSelectorInterface;
use GuzzleHttp\Psr7\Uri;
use kabachello\Codiware\Middleware\CodiwareMiddleware;
use kabachello\Codiware\Middleware\CodiwareConfig;
use kabachello\Codiware\Middleware\UserContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use exface\Core\Facades\AbstractHttpFacade\AbstractHttpFacade;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use exface\Core\DataTypes\StringDataType;
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
            case StringDataType::startsWith($pathInFacade, 'codiware/'):
                return $this->createResponseFromCodiware($request, $pathInFacade);
            // AdminNeo - the cleaner Adminer fork. Reachable simultaneously with Adminer, so both
            // engines can be compared side by side. Switch the default engine used by the UI/AI
            // tools via the config option SQL_ADMIN.ENGINE.
            case StringDataType::startsWith($pathInFacade, 'adminneo/'):
                $api = new AdminneoAPI($this->getWorkbench(), $this->getUrlRouteDefault() . '/', $path, 'index.php', $this->buildHeadersCommon());
                return $api->handle($request);
            // Adminer with autologin
            case StringDataType::startsWith($pathInFacade, 'adminer/'):
            case StringDataType::startsWith($pathInFacade, 'externals/'):
                $api = new Adminer4API($this->getWorkbench(), $this->getUrlRouteDefault() . '/', $path, 'index.php', $this->buildHeadersCommon());
                return $api->handle($request);
        }
        
        return new Response(404, $this->buildHeadersCommon(), 'Nothing here yet!');
  
    }

    /**
     * Mount the Codiware PSR-15 middleware under /api/ide/codiware.
     */
    protected function createResponseFromCodiware(ServerRequestInterface $request, string $pathInFacade) : ResponseInterface
    {
        if (! class_exists(CodiwareMiddleware::class)) {
            return new Response(503, $this->buildHeadersCommon(), 'Codiware package is not installed.');
        }

        $pathInFacade = StringDataType::substringAfter($request->getUri()->getPath(), $this->getUrlRouteDefault() . '/');
        $user = $this->getWorkbench()->getSecurity()->getAuthenticatedUser();
        $absUriToAPI = new Uri($this->getWorkbench()->getUrl());
        $baseUriPath = $absUriToAPI->getPath();
        $apiUriPath = $baseUriPath . $this->getUrlRouteDefault() . '/codiware';
        $vendorFolder = $this->getWorkbench()->filemanager()->getPathToVendorFolder();

        if ($this->getConfig()->getOption('FACADE.BUST_BROWSER_CACHE') === true) {
            $assetVersion = date('YmdHis');
        } else {
            $assetVersion = str_replace(['-', ' ', ':'], '', $this->getWorkbench()->getContext()->getScopeInstallation()->getVariable('last_metamodel_install') ?? '');
        }

        $config = [
            'URL_BASE' => $baseUriPath,
            'URL_TO_API' => $this->getUrlRouteDefault() . '/codiware',
            'URL_TO_APP' => '/vendor/kabachello/codiware/public',
            'URL_TO_NPM' => '/vendor/npm-asset',
            'BASE_FOLDER' => $vendorFolder,
            'CACHE_BUST' => $assetVersion,
            "EXTENSIONS.CONFIG" => [
                "codiware.markdown" => [
                    "INCLUDES.EDITOR_JS" => $baseUriPath . "vendor/exface/jeasyuifacade/Facades/js/toastui-editor-all.min.js",
                    "INCLUDES.MERMAID_JS" => $baseUriPath . "vendor/exface/core/Facades/AbstractAjaxFacade/js/mermaid.min.js",
                    "INCLUDES.PREVIEW_CSS" => [
                        "npm-asset/github-markdown-css/github-markdown.css",
                        "exface/core/Facades/DocsFacade/template.css"
                    ],
                    'CSS_CLASS_FOR_PREVIEW_CONTAINER' => 'markdown-body'
                ]
            ]
        ];
        
        if (StringDataType::startsWith($pathInFacade, 'codiware/repo/')) {
            $appAlias = StringDataType::substringBefore(StringDataType::substringAfter($pathInFacade, 'codiware/repo/'), '/', '');
            if ($appAlias === '') {
                throw new FacadeRoutingError('No app alias specified in URL - expected format: /api/ide/codiware/repo/{appAlias}/...');
            }
            $appFolder = str_replace(AliasSelectorInterface::ALIAS_NAMESPACE_DELIMITER, '/', $appAlias);
            $appFolder = FilePathDataType::findPathCaseInsensitive($appFolder, $vendorFolder);
            $config['ALLOWED_ROOTS'] = [$appFolder];
            $config['CONSOLE.PRESETS'] = array_merge(
                $this->buildCodiwareConsolePresets($appAlias),
                (array) ($config['CONSOLE.PRESETS'] ?? [])
            );
            $request = $request->withUri($request->getUri()->withPath(str_replace($appAlias, $appFolder, $request->getUri()->getPath())));
        }
        
        $factory = new HttpFactory();
        $middleware = new CodiwareMiddleware(
            config: $config = CodiwareConfig::fromArray($config),
            responseFactory: $factory,
            streamFactory: $factory,
            logger: $this->getWorkbench()->getLogger(),
            userContext: new UserContext($user->getUsername(), $user->getEmail(), $user->getUid()),
            basePath: $apiUriPath
        );

        $passThrough = new class($this->buildHeadersCommon()) implements RequestHandlerInterface {

            private array $headers;

            public function __construct(array $headers)
            {
                $this->headers = $headers;
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(404, $this->headers, 'Nothing here yet!');
            }
        };

        return $middleware->process($request, $passThrough);
    }

    private function buildCodiwareConsolePresets(string $appAlias): array
    {
        return [
            [
                'label' => 'Export model',
                'command' => '../../bin/action axenox.PackageManager:ExportAppModel ' . $appAlias,
            ],
            [
                'label' => 'Repair app',
                'command' => '../../bin/action axenox.PackageManager:InstallApp ' . $appAlias,
            ],
        ];
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