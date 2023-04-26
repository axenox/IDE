<?php
namespace axenox\IDE\Common;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use exface\Core\DataTypes\StringDataType;
use exface\Core\DataTypes\FilePathDataType;
use exface\Core\DataTypes\MimeTypeDataType;
use GuzzleHttp\Psr7\Response;
use exface\Core\DataTypes\JsonDataType;
use exface\Core\Interfaces\UserInterface;
use exface\Core\DataTypes\DateTimeDataType;
use exface\Core\Interfaces\AppInterface;
use exface\Core\Factories\AppFactory;
use exface\Core\CommonLogic\Filemanager;
use exface\Core\Interfaces\Selectors\AliasSelectorInterface;
use exface\Core\Exceptions\RuntimeException;

class AtheosAPI extends InclusionAPI
{
    private $atheosBase = null;
    
    /**
     * 
     * {@inheritDoc}
     * @see \axenox\IDE\Common\InclusionAPI::handle()
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        $innerPath = StringDataType::substringAfter($path, $this->getBaseUrlPath(), '');
        $appSelector = StringDataType::substringBefore($innerPath, '/');
        $app = null;
        $file = substr($innerPath, strlen($appSelector)+1);
        if ($file === '') {
            $file = 'index.php';
            $app = AppFactory::createFromAnything($appSelector, $this->getWorkbench());
            $this->createProject($app);
        } else {
            if (mb_stripos($file, '..') !== false) {
                $this->getWorkbench()->getLogger()->logException(new RuntimeException('Suspicious request to Atheos API blocked: ' . $file));
                return new Response(404);
            }
        }
        
        $base = $this->getBaseFilePath();
        chdir($base);
        
        if (! file_exists($base . $file)) {
            $this->getWorkbench()->getLogger()->logException(new RuntimeException('IDE file not found: ' . $base . $file));
            return new Response(404);
        }
        
        $headers = [];
        if (strcasecmp(FilePathDataType::findExtension($file), 'php') === 0) {
            $this->createDataFolders();
            $user = $this->getWorkbench()->getSecurity()->getAuthenticatedUser();
            
            // Block certain actions
            switch (mb_strtolower($_POST['target'] ?? '')) {
                case 'user':
                    if ($_POST['action'] !== 'keepAlive') {
                        return new Response(200);
                    }
                    break;
            }
            
            $this->switchSession($user);
            if (! $this->isLoggedIn($user) || ! file_exists($this->getPathToAtheosData() . 'users.json')) {
                $app = $app ?? AppFactory::createFromAnything($appSelector, $this->getWorkbench());
                $this->logIn($user, $app);
            }
            
            $vendorFolder = str_replace(AliasSelectorInterface::ALIAS_NAMESPACE_DELIMITER, '/', $appSelector);
            if (! StringDataType::endsWith($_SESSION['projectPath'], $vendorFolder, false)) {
                $app = $app ?? AppFactory::createFromAnything($appSelector, $this->getWorkbench());
                $this->switchProject($app);
            }
            
            if (! $_SESSION["term_auth"]) {
                $_SESSION["term_auth"] = true;
            }
            
            try {
                $output = trim($this->includeFile($file));
            } catch (\Throwable $e) {
                $this->restoreSession();
                throw $e;
            }
            
            $this->restoreSession();
            
            $headers = headers_list();
            if (stripos($file, 'controller') !== false && (mb_substr($output, 0, 1) === '[' || mb_substr($output, 0, 1) === '{')) {
                $headers['Content-Type'] = 'application/json';
                // There are cases, when Atheos prints multiple JSON objects - see MOD in `Atheos/traits/reply.php`
                // Need to check if this is the case and just leave the first one.
                if (false !== ($pos = strpos($output, '}{')) && json_decode($output) === null) {
                    $output = mb_substr($output, 0, $pos+1);
                }
            }
            
        } else {
            $output = fopen($base . $file, 'r');
            switch (FilePathDataType::findExtension($file)) {
                case 'css':
                    $contentType = 'text/css';
                    break;
                case 'js':
                     $contentType = 'text/javascript';
                     break;
                default:
                    $contentType = MimeTypeDataType::findMimeTypeOfFile($base . $file);
                    break;
            }
            $headers['Content-Type'] = $contentType;
        }
        return new Response(200, $headers, $output);
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \axenox\IDE\Common\InclusionAPI::includeFile()
     */
    protected function includeFile(string $pathRelativeToBase) : string
    {
        global $i18n; 
        global $components; 
        global $libraries; 
        global $plugins;
        
        try {
            ob_start();
            require $pathRelativeToBase;
            $output = ob_get_contents();
            ob_end_clean();
        } catch (\Throwable $e) {
            throw new RuntimeException('Error in Atheos IDE: ' . $e->getMessage(), null, $e);
        }
        return $output;
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \axenox\IDE\Common\InclusionAPI::isLoggedIn()
     */
    protected function isLoggedIn(UserInterface $user) : bool
    {
        return $user->getUsername() === $_SESSION['user'];
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \axenox\IDE\Common\InclusionAPI::logIn()
     */
    protected function logIn(UserInterface $user, AppInterface $app = null) : InclusionAPI
    {
        $this->createUser($user, $app);
        
        $this->runController('user', 'authenticate', [
            'username' => $user->getUsername(),
            'password' => $this->getAtheosPassword($user),
            'language' => 'en',
            'remember' => 'on'
        ]);        
        
        return $this;
    }
    
    /**
     * 
     * @param AppInterface $app
     * @return AtheosAPI
     */
    protected function switchProject(AppInterface $app) : AtheosAPI
    {
        $this->runController('project', 'open', [
            'projectName' => $app->getName(),
            'projectPath' => $this->getProjectPath($app)
        ]);
        return $this;
    }
    
    /**
     * 
     * @param string $target
     * @param string $action
     * @param array $postVars
     * @return array
     */
    protected function runController(string $target, string $action, array $postVars = []) : array
    {
        $_POST['target'] = $target;
        $_POST['action'] = $action;
        foreach ($postVars as $var => $val) {
            $_POST[$var] = $val;
        }
        
        $output = $this->includeFile('controller.php');
        if ($output) {
            try {
                $result = JsonDataType::decodeJson($output);
            } catch (\Throwable $e) {
                throw new RuntimeException('Error in Atheos IDE: ' . $e->getMessage(), null, $e);
            }
            if ($result['status'] === 'error') {
                throw new RuntimeException('Error in Atheos IDE: ' . $result['text'] . '. Response: ' . JsonDataType::encodeJson($result));
            }
        } else {
            $result = [];
        }
        
        unset($_POST['target']);
        unset($_POST['action']);
        foreach (array_keys($postVars) as $var) {
            unset($_POST[$var]);
        }
        
        return $result;
    }
    
    protected function getPathToAtheosData() : string
    {
        return $this->getWorkbench()->filemanager()->getPathToDataFolder()
            . DIRECTORY_SEPARATOR . 'axenox'
            . DIRECTORY_SEPARATOR . 'IDE'
            . DIRECTORY_SEPARATOR . 'Atheos'
            . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR;
    }
    
    /**
     * 
     * @return string
     */
    protected function getPathToAtheosUsers() : string
    {
        return $this->getPathToAtheosData() . 'users' . DIRECTORY_SEPARATOR;
    }
    
    protected function getAtheosUsers() : array
    {
        return $this->getAtheosData('users.json');
    }
    
    protected function getAtheosProjects() : array
    {
        return $this->getAtheosData('projects.db.json');
    }
    
    protected function getAtheosData(string $filename) : array
    {
        $dataPath = $this->getPathToAtheosData();
        $filenamePath = $dataPath . $filename;
        if (! file_exists($filenamePath)) {
            return [];
        }
        $json = file_get_contents($filenamePath);
        if (! $json) {
            return [];
        }
        return JsonDataType::decodeJson($json);
    }
    
    protected function setAtheosUsers(array $userData) : AtheosAPI
    {
        $dataPath = $this->getPathToAtheosData();
        $this->getWorkbench()->filemanager()->dumpFile($dataPath . 'users.json', JsonDataType::encodeJson($userData, true));
        return $this;
    }
    
    protected function createProject(AppInterface $app) : AtheosAPI
    {
        $dataDir = $this->getPathToAtheosData();
        $projects = $this->getAtheosProjects();
        $appPath = $this->getProjectPath($app);
        foreach ($projects as $name => $projectPath) {
            // TODO rename project if names do not match
            if ($projectPath === $appPath) {
                return $this;
            }
        }
        $projects[$app->getName()] = $appPath;
        $this->getWorkbench()->filemanager()->dumpFile($dataDir . 'projects.db.json', JsonDataType::encodeJson($projects, true));
        return $this;
    }
    
    protected function getProjectPath(AppInterface $app) : string{
        return FilePathDataType::normalize($app->getDirectoryAbsolutePath(), '/');
    }
    
    protected function getAtheosPassword(UserInterface $user) : string
    {
        return $user->getPassword() ? $user->getPassword() : $user->getUid();
    }
    
    protected function createUser(UserInterface $user, AppInterface $activeProject) : AtheosAPI
    {
        $users = $this->getAtheosUsers();
        $userData = $users[$user->getUsername()] ?? null;
        if ($userData === null) {
            $users[$user->getUsername()] = [
                "password" => password_hash($this->getAtheosPassword($user), PASSWORD_DEFAULT),
                "resetPassword" => false,
                "activeProject" => $this->getProjectPath($activeProject),
                "activePath" => $this->getProjectPath($activeProject),
                "creationDate" => DateTimeDataType::now(),
                "lastLogin" => DateTimeDataType::now(),
                "permissions" => [
                    "configure",
                    "read",
                    "write"
                ],
                "userACL" => "full"
            ];
        } else {
            $users[$user->getUsername()] = [
                "password" => password_hash($this->getAtheosPassword($user), PASSWORD_DEFAULT),
                "resetPassword" => false,
                "activeProject" => $this->getProjectPath($activeProject),
                "activePath" => $this->getProjectPath($activeProject),
                "creationDate" => DateTimeDataType::now(),
                "lastLogin" => DateTimeDataType::now(),
                "permissions" => [
                    "configure",
                    "read",
                    "write"
                ],
                "userACL" => "full"
            ];
        }
        
        $this->setAtheosUsers($users);
        
        $usersPath = $this->getPathToAtheosUsers();
        $codeGitFile = $usersPath . $user->getUsername() . DIRECTORY_SEPARATOR . 'codegit.db.json';
        if (file_exists($codeGitFile)) {
            $codeGitJson = file_get_contents($codeGitFile);
            $codeGitData = JsonDataType::decodeJson($codeGitJson ? $codeGitJson : '[]');
        } else {
            Filemanager::pathConstruct($usersPath . $user->getUsername());
            $codeGitData = [
                [
                    "user" => $user->getUsername(),
                    "path" => "global",
                    "name" => $user->getFirstName() . ' ' . $user->getLastName(),
                    "email" => $user->getEmail()
                ]
            ];
        }
        $this->getWorkbench()->filemanager()->dumpFile($codeGitFile, JsonDataType::encodeJson($codeGitData, true));
        return $this;
    }
    
    protected function createDataFolders() : AtheosAPI
    {
        if (! file_exists($this->getPathToAtheosData())) {
            Filemanager::pathConstruct($this->getPathToAtheosData());
        }
        if (! file_exists($this->getPathToAtheosUsers())) {
            Filemanager::pathConstruct($this->getPathToAtheosUsers());
        }
        return $this;
    }
}