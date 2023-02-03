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
        }
        $base = $this->getBaseFilePath();
        chdir($base);
        if (file_exists($base . $file)) {
            $headers = [];
            if (strcasecmp(FilePathDataType::findExtension($file), 'php') === 0) {
                if (! file_exists($this->getPathToAtheosData())) {
                    Filemanager::pathConstruct($this->getPathToAtheosData());
                }
                $this->switchSession();
                $user = $this->getWorkbench()->getSecurity()->getAuthenticatedUser();
                if (! $this->isLoggedIn($user) || ! file_exists($this->getPathToAtheosData() . 'users.json')) {
                    $app = $app ?? AppFactory::createFromAnything($appSelector, $this->getWorkbench());
                    $this->logIn($user, $app);
                }
                
                $vendorFolder = str_replace(AliasSelectorInterface::ALIAS_NAMESPACE_DELIMITER, '/', $appSelector);
                if (! StringDataType::endsWith($_SESSION['projectPath'], $vendorFolder, false)) {
                    $app = $app ?? AppFactory::createFromAnything($appSelector, $this->getWorkbench());
                    $this->switchProject($app);
                }
                $output = $this->includeFile($file);
                
                $this->restoreSession();
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
        
        ob_start();
        require $pathRelativeToBase;
        $output = ob_get_contents();
        ob_end_clean();
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
        $result = JsonDataType::decodeJson($output);
        
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
    
    protected function getAtheosUsers() : array
    {
        $dataPath = $this->getPathToAtheosData();
        return JsonDataType::decodeJson(file_get_contents($dataPath . 'users.json'));
    }
    
    protected function setAtheosUsers(array $userData) : array
    {
        $dataPath = $this->getPathToAtheosData();
        return file_put_contents($dataPath . 'users.json', JsonDataType::encodeJson($userData));
    }
    
    protected function createProject(AppInterface $app) : AtheosAPI
    {
        $dataDir = $this->getPathToAtheosData();
        if (file_exists($dataDir . 'projects.db.json')) {
            $projects = JsonDataType::decodeJson(file_get_contents($dataDir . 'projects.db.json'));
        } else {
            $projects = [];
        }
        $appPath = $this->getProjectPath($app);
        foreach ($projects as $name => $projectPath) {
            if ($projectPath === $appPath) {
                return $this;
            }
        }
        $projects[$app->getName()] = $appPath;
        file_put_contents($dataDir . 'projects.db.json', JsonDataType::encodeJson($projects, true));
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
        $dataPath = $this->getPathToAtheosData();
        if (file_exists($dataPath . 'users.json')) {
            $users = JsonDataType::decodeJson(file_get_contents($dataPath . 'users.json'));
        } else {
            $users = [];
        }
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
        
        file_put_contents($dataPath . 'users.json', JsonDataType::encodeJson($users, true));
        
        $codeGitFile = $dataPath . $user->getUsername() . DIRECTORY_SEPARATOR . 'codegit.db.json';
        if (file_exists($codeGitFile)) {
            $codeGitData = JsonDataType::decodeJson(file_get_contents($codeGitFile));
        } else {
            Filemanager::pathConstruct($dataPath . $user->getUsername());
            $codeGitData = [
                [
                    "user" => $user->getUsername(),
                    "path" => "global",
                    "name" => $user->getFirstName() . ' ' . $user->getLastName(),
                    "email" => $user->getEmail()
                ]
            ];
        }
        file_put_contents($codeGitFile, JsonDataType::encodeJson($codeGitData, true));
        return $this;
    }
}