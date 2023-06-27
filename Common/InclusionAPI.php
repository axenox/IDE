<?php
namespace axenox\IDE\Common;

use exface\Core\Interfaces\WorkbenchInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use GuzzleHttp\Psr7\Response;
use exface\Core\Interfaces\WorkbenchDependantInterface;
use exface\Core\Interfaces\UserInterface;

class InclusionAPI implements RequestHandlerInterface, WorkbenchDependantInterface
{
    private $workbench = null;
    
    private $baseUrlPath = null;
    
    private $mainFile = null;
    
    private $baseFilePath = null;
    
    private $sessionIdOuter = null;
    
    private $sessionNameOuter = null;
    
    private $headers = [];
    
    /**
     * 
     * @param WorkbenchInterface $workbench
     * @param string $baseUrl
     * @param string $baseFilePath
     * @param string $mainFile
     * @param string[] $commonHeaders
     */
    public function __construct(WorkbenchInterface $workbench, string $baseUrl, string $baseFilePath, string $mainFile, array $commonHeaders = [])
    {
        $this->workbench = $workbench;
        $this->baseUrlPath = $baseUrl;
        $this->baseFilePath = ltrim($baseFilePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $this->mainFile = $mainFile;
        $this->headers = $commonHeaders;
    }
    
    protected function switchSession(UserInterface $user) : InclusionAPI
    {
        $this->sessionIdOuter = session_id();
        $this->sessionNameOuter = session_name();
        session_write_close();
        session_name($this->getSessionName());
        session_id($this->getSessionId($user));
        session_start();
        session_write_close();
        return $this;
    }
    
    /**
     * 
     * @param UserInterface $user
     * @return string
     */
    protected function getSessionId(UserInterface $user) : string
    {
        return md5($user->getUid());
    }
    
    /**
     * 
     * @return string
     */
    protected function getSessionName() : string
    {
        return md5(substr($this->baseFilePath, 0, -1));
    }
    
    /**
     * 
     * @return InclusionAPI
     */
    protected function restoreSession() : InclusionAPI
    {
        session_write_close();
        session_name($this->sessionNameOuter);
        session_id($this->sessionIdOuter);
        return $this;
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \Psr\Http\Server\RequestHandlerInterface::handle()
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // TODO
        return new Response(403, $this->getHeadersCommon());
    }
    
    /**
     * 
     * @return string
     */
    protected function getBaseFilePath() : string
    {
        return $this->baseFilePath;
    }

    /**
     * 
     * @return string
     */
    protected function getBaseUrlPath() : string
    {
        return $this->baseUrlPath;
    }
    
    /**
     * 
     * @return string
     */
    protected function getMainFilePath() : string
    {
        return $this->mainFile;
    }
    
    /**
     * 
     * @param string $pathRelativeToBase
     * @return string
     */
    protected function includeFile(string $pathRelativeToBase) : string
    {
        ob_start();
        require $pathRelativeToBase;
        $output = ob_get_contents();
        ob_end_clean();
        return $output;
    }
    
    /**
     * 
     * @param UserInterface $user
     * @return bool
     */
    protected function isLoggedIn(UserInterface $user) : bool
    {
        return false;
    }
    
    /**
     * 
     * @param UserInterface $user
     * @return InclusionAPI
     */
    protected function logIn(UserInterface $user) : InclusionAPI
    {
        
        return $this;
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\WorkbenchDependantInterface::getWorkbench()
     */
    public function getWorkbench()
    {
        return $this->workbench;
    }
    
    /**
     * 
     * @return string[]
     */
    protected function getHeadersCommon() : array
    {
        return $this->headers;
    }
}