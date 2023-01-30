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
                    $this->logIn($user, $app);
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
        $username = $user->getUsername();
        $password = $user->getPassword();
        $this->createUser($user, $app);
        if ($user) {
            // TODO
        }
        
        $_POST['username'] = $username;
        $_POST['password'] = $password;
        $_POST['language'] = 'en';
        $_POST['remember'] = 'on';
        $_POST['target'] = 'user';
        $_POST['action'] = 'authenticate';
        
        $output = $this->includeFile('controller.php');
        if ($output) {
            // TODO
        }
        
        unset($_POST['username']);
        unset($_POST['password']);
        unset($_POST['language']);
        unset($_POST['remember']);
        unset($_POST['target']);
        unset($_POST['action']);
        
        return $this;
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
                "password" => password_hash($user->getPassword(), PASSWORD_DEFAULT),
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
                "password" => password_hash($user->getPassword(), PASSWORD_DEFAULT),
                "resetPassword" => false,
                "activeProject" => null,
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