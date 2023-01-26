<?php
namespace axenox\IDE\Common;

use exface\Core\Interfaces\WorkbenchInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use exface\Core\DataTypes\StringDataType;
use exface\Core\DataTypes\FilePathDataType;
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
    
    public function __construct(WorkbenchInterface $workbench, string $baseUrl, string $baseFilePath, string $mainFile)
    {
        $this->workbench = $workbench;
        $this->baseUrlPath = $baseUrl;
        $this->baseFilePath = ltrim($baseFilePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $this->mainFile = $mainFile;
    }
    
    protected function switchSession() : InclusionAPI
    {
        $this->sessionIdOuter = session_id();
        $this->sessionNameOuter = session_name();
        session_write_close();
        session_name($this->getSessionName());
        session_id($this->getSessionId());
        session_start();
        session_write_close();
        return $this;
    }
    
    protected function getSessionId() : string
    {
        return 'mijnintvksngnkbff8cbnsg0ui';
    }
    
    protected function getSessionName() : string
    {
        return md5(substr($this->baseFilePath, 0, -1));
    }
    
    protected function restoreSession() : InclusionAPI
    {
        session_write_close();
        session_name($this->sessionNameOuter);
        session_id($this->sessionIdOuter);
        return $this;
    }
    
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // TODO
        return new Response(403);
    }
    
    protected function getBaseFilePath() : string
    {
        return $this->baseFilePath;
    }
    
    protected function getBaseUrlPath() : string
    {
        return $this->baseUrlPath;
    }
    
    protected function getMainFilePath() : string
    {
        return $this->mainFile;
    }
    
    protected function includeFile(string $pathRelativeToBase) : string
    {
        ob_start();
        require $pathRelativeToBase;
        $output = ob_get_contents();
        ob_end_clean();
        return $output;
    }
    
    protected function isLoggedIn(UserInterface $user) : bool
    {
        return false;
    }
    
    protected function logIn(UserInterface $user) : InclusionAPI
    {
        
        return $this;
    }
    
    public function getWorkbench()
    {
        return $this->workbench;
    }

}