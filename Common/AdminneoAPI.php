<?php
namespace axenox\IDE\Common;

use exface\Core\CommonLogic\Selectors\DataConnectionSelector;
use exface\Core\DataTypes\ComparatorDataType;
use exface\Core\DataTypes\FilePathDataType;
use exface\Core\DataTypes\JsonDataType;
use exface\Core\DataTypes\StringDataType;
use exface\Core\Exceptions\UnexpectedValueException;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Interfaces\DataSources\SqlDataConnectorInterface;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Integration for the axenox/adminneo fork - a cleaner alternative to Adminer.
 *
 * AdminNeo (a maintained fork of Adminer) is considerably easier to embed than Adminer:
 *
 * - It runs straight from source, so there is no build step and - unlike Adminer - no git
 *   submodule (jush) that Composer fails to populate. Nothing has to be vendored into axenox.IDE.
 * - It serves its own static assets through a `?file=` route, so this class never streams CSS/JS
 *   from disk.
 * - It is configured through a plain PHP array ({@see \AdminNeo\Admin::create()}). The current
 *   data connection is registered as a pre-configured server, which lets us auto-login without
 *   touching the fork's source (no session/CSRF core patch, as was needed for Adminer).
 * - Driver options (incl. the MS SQL TrustServerCertificate/Encrypt flags) are first-class config
 *   keys, so connection specifics are expressed as configuration rather than as core hacks.
 *
 * To use a different SQL admin front-end, instantiate {@see Adminer6API} or {@see Adminer4API}
 * instead - all of them implement {@see SqlAdminApiInterface}.
 *
 * @author andrej.kabachnik
 */
class AdminneoAPI extends InclusionAPI implements SqlAdminApiInterface
{
    /** Global variable through which the wrapper (Adminneo/adminneo.php) receives its context. */
    const CONTEXT_GLOBAL = 'axenox_ide_adminneo';

    /** Dedicated PHP session name so AdminNeo does not share the workbench session cookie. */
    const SESSION_NAME = 'axenox_ide_adminneo';

    /** URL segment (below the facade route) under which this API is mounted. */
    const URL_SEGMENT = 'adminneo/';

    /**
     *
     * {@inheritDoc}
     * @see \Psr\Http\Server\RequestHandlerInterface::handle()
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        $innerPath = StringDataType::substringAfter($path, $this->getBaseUrlPath(), '');
        return $this->runAdminneo($innerPath);
    }

    /**
     * Builds AdminNeo login credentials from an ExFace data connection config.
     *
     * @param array $connectionConfig
     * @param string $connectorClass
     * @return array|NULL
     */
    protected function getAdminneoAuth(array $connectionConfig, string $connectorClass) : ?array
    {
        $auth = null;
        
        // Check for placeholders and replace them if needed
        $connectionJson = JsonDataType::encodeJson($connectionConfig);
        if (mb_stripos($connectionJson, '[#') !== false) {
            $phRenderer = $this->getTemplateRenderer();
            $connectionJson = $phRenderer->render($connectionJson);
            $connectionConfig = JsonDataType::decodeJson($connectionJson);
        }
        
        // Translate to AdminNeo's expected keys and formats. AdminNeo does not support all ExFace connectors, so we
        // return NULL for unsupported ones.
        switch (true) {
            // MySQL, MariaDB, PostgreSQL
            case stripos($connectorClass, 'mariadb') !== false:
            case stripos($connectorClass, 'mysql') !== false:
            case stripos($connectorClass, 'postgresql') !== false:
                $auth = [
                    'server' => $connectionConfig['host'] . ($connectionConfig['port'] ? ':' . $connectionConfig['port'] : ''),
                    'username' => $connectionConfig['user'] ?? '',
                    'password' => $connectionConfig['password'] ?? '',
                    'driver' => $this->getAdminneoDriver($connectorClass),
                    'db'    => $connectionConfig['dbase'] ?? '',
                    'ssl'   => [],
                    'relationMatcher' => $connectionConfig['relation_matcher'] ?? null
                ];
                if (null !== $sslVal = $connectionConfig['ssl_key'] ?? null) {
                    $auth['ssl']['sslKey'] = $this->getPathInWorkbench($sslVal);
                }
                if (null !== $sslVal = $connectionConfig['ssl_certificate_path'] ?? null) {
                    $auth['ssl']['sslCertificate'] = $this->getPathInWorkbench($sslVal);
                }
                if (null !== $sslVal = $connectionConfig['ssl_ca_certificate_path'] ?? null) {
                    $auth['ssl']['sslCaCertificate'] = $this->getPathInWorkbench($sslVal);
                }
                break;
            // Microsoft SQL Server
            case stripos($connectorClass, 'mssql') !== false:
                $auth = [
                    'server' => $this->buildMssqlServer($connectionConfig['serverName'] ?? $connectionConfig['host'] ?? '', $connectionConfig['port'] ?? '1433'),
                    'username' => $connectionConfig['UID'] ?? $connectionConfig['user'] ?? '',
                    'password' => $connectionConfig['PWD'] ?? $connectionConfig['password'] ?? '',
                    'driver' => $this->getAdminneoDriver($connectorClass),
                    'db'    => $connectionConfig['database'] ?? $connectionConfig['dbase'] ?? '',
                    'ssl'   => [],
                    'relationMatcher' => $connectionConfig['relation_matcher'] ?? null
                ];
                // MS SQL connection specifics (TrustServerCertificate, Encrypt, ...) map onto
                // AdminNeo's first-class SSL config keys instead of being appended to the host.
                $options = $connectionConfig['connection_options'] ?? null;
                if (is_string($options)) {
                    $options = json_decode($options, true) ?: [];
                }
                foreach ((array) $options as $optKey => $optVal) {
                    switch (strtolower((string) $optKey)) {
                        case 'trustservercertificate':
                            $auth['ssl']['sslTrustServerCertificate'] = filter_var($optVal, FILTER_VALIDATE_BOOLEAN);
                            break;
                        case 'encrypt':
                            $auth['ssl']['sslEncrypt'] = filter_var($optVal, FILTER_VALIDATE_BOOLEAN);
                            break;
                    }
                }
                break;
        }
        return $auth;
    }

    /**
     * Builds the `host[:port]` string AdminNeo passes to sqlsrv for MS SQL.
     *
     * A port is mandatory here: AdminNeo's {@see \AdminNeo\host_port()} turns `host` (without a
     * port) into the connection string `"host,"` with a trailing comma, which sqlsrv rejects with
     * "Connection string is not valid [87]". We therefore default to the standard MS SQL port
     * 1433 unless the host already carries a port or addresses a named instance (`host\instance`).
     *
     * @param string $host
     * @param string|int|null $port
     * @return string
     */
    protected function buildMssqlServer(string $host, $port = null) : string
    {
        $host = trim($host);
        if ($port !== null && $port !== '' && $port !== 0) {
            return $host . ':' . $port;
        }
        // Host already contains a port or names an instance - leave it untouched.
        if (strpos($host, ':') !== false || strpos($host, '\\') !== false) {
            return $host;
        }
        return $host . ':1433';
    }

    /**
     *
     * @param string $path
     * @return string
     */
    protected function getPathInWorkbench(string $path) : string
    {
        if (FilePathDataType::isAbsolute($path)) {
            return $path;
        }
        return $this->getWorkbench()->getInstallationPath() . DIRECTORY_SEPARATOR . $path;
    }

    /**
     * Maps an ExFace SQL connector class onto an AdminNeo driver key (the login URL parameter).
     *
     * @param string $connector
     * @return string|NULL
     */
    protected function getAdminneoDriver(string $connector) : ?string
    {
        $drivers = [
            'mariadb' => 'mysql',
            'mysql' => 'mysql',
            'postgresql' => 'pgsql',
            'oraclesql' => 'oracle',
            'mssql' => 'mssql',
            'mongodb' => 'mongo',
            'elastic' => 'elastic',
            'sqlite' => 'sqlite'
        ];

        foreach ($drivers as $key => $driver) {
            if (stripos($connector, $key) !== false) {
                return $driver;
            }
        }

        return null;
    }

    /**
     * Handles an incoming request path (below `adminneo/`) and returns the response.
     *
     * @param string $pathInFacade
     * @throws UnexpectedValueException
     * @return ResponseInterface
     */
    protected function runAdminneo(string $pathInFacade) : ResponseInterface
    {
        $selector = rtrim(StringDataType::substringAfter($pathInFacade, self::URL_SEGMENT), '/');

        // Asset requests (`?file=...`) are served by AdminNeo itself: file.inc.php streams the
        // cached CSS/JS/image and exits before the Admin instance is even built. We therefore
        // stream the app's output directly and skip building any connection context.
        if (isset($_GET['file'])) {
            $this->setAdminneoContext([]);
            $this->launchAdminneo(false);
            return new Response(200, $this->getHeadersCommon());
        }

        switch (true) {
            case isset($_POST['logout']):
                $_GET = [];
                $_POST = [];
                $this->setAdminneoContext([]);
                $this->startIsolatedSession();
                break;
            default:
                // Build the config server for the addressed connection on every request, because
                // AdminNeo resolves the host, credentials and driver options from it.
                [$config, $connector] = $this->getConnectionConfig($selector);
                $auth = $this->getAdminneoAuth($config, $connector);
                if ($auth === null) {
                    throw new UnexpectedValueException('Cannot open SQL admin for connection "' . $selector . '": unsupported connector "' . $connector . '"');
                }
                $this->setAdminneoContext($this->buildAdminneoConfig($selector, $auth));

                // Auto-login without a form submit: we write the credentials straight into
                // AdminNeo's session instead of posting `$_POST['auth']`. This avoids AdminNeo's
                // CSRF check (which otherwise reports a misleading max_input_vars error on our
                // injected POST), its `session_regenerate_id()` and the post-login redirect - none
                // of which play well with running the fork inside the facade. AdminNeo then simply
                // sees an already authenticated session and renders the requested page.
                $this->startIsolatedSession();
                $this->injectLogin($selector, $auth);

                // First load (no query string yet): present the matching $_GET so AdminNeo opens
                // the database right away. Later navigations already carry these parameters, so we
                // must not overwrite them.
                if (! isset($_GET[$auth['driver']])) {
                    $_GET[$auth['driver']] = $selector;
                    $_GET['username'] = $auth['username'];
                    $_GET['db'] = $auth['db'];
                    if (($schema = $this->getSchemaFromConfig($config)) !== null && $schema !== '') {
                        $_GET['ns'] = $schema;
                    }
                }
                break;
        }

        $html = $this->launchAdminneo(true);
        $headers = array_merge(headers_list(), $this->getHeadersCommon());
        return new Response(200, $headers, $html);
    }

    /**
     * Writes the connection login into AdminNeo's session (mirrors AdminNeo\save_login()).
     *
     * We deliberately store the password in plain text: AdminNeo only encrypts it when the
     * client-side `neo_key` cookie is present, which never happens in this server-side flow.
     * Re-injecting on every request keeps the tool authenticated even if the session cookie does
     * not round-trip.
     *
     * @param string $selector
     * @param array $auth
     * @return void
     */
    protected function injectLogin(string $selector, array $auth) : void
    {
        $driver = $auth['driver'];
        $username = $auth['username'];
        $_SESSION['pwds'][$driver][$selector][$username] = $auth['password'];
        $_SESSION['db'][$driver][$selector][$username][$auth['db']] = true;
    }

    /**
     * Reads the config and connector class of a data connection addressed by its alias/UID.
     *
     * @param string $selector
     * @throws UnexpectedValueException
     * @return array [config array, connector class]
     */
    protected function getConnectionConfig(string $selector) : array
    {
        if (strcasecmp($selector, DataConnectionSelector::METAMODEL_CONNECTION_ALIAS) === 0 || strcasecmp($selector, DataConnectionSelector::METAMODEL_CONNECTION_UID) === 0) {
            $config = $this->getWorkbench()->getCoreApp()->getConfig()->getOption('METAMODEL.CONNECTOR_CONFIG')->toArray();
            $connector = $this->getWorkbench()->getCoreApp()->getConfig()->getOption('METAMODEL.CONNECTOR');
            return [$config, $connector];
        }

        $dataSheet = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), 'exface.Core.CONNECTION');
        $dataSheet->getFilters()->addConditionFromString('ALIAS_WITH_NS', $selector, ComparatorDataType::EQUALS);
        $dataSheet->getColumns()->addMultiple(['CONFIG', 'CONNECTOR', 'UID']);
        $dataSheet->dataRead();

        $row = $dataSheet->getRowsDecrypted()[0] ?? null;
        if ($row === null) {
            throw new UnexpectedValueException('Data connection "' . $selector . '" not found!');
        }

        $config = ($row['CONFIG'] ?? null) ? JsonDataType::decodeJson($row['CONFIG']) : [];
        return [$config, $row['CONNECTOR']];
    }

    /**
     * Returns the default schema/namespace of a connection config, if any.
     *
     * @param array $config
     * @return string|NULL
     */
    protected function getSchemaFromConfig(array $config) : ?string
    {
        return $config['schema'] ?? $config['ns'] ?? null;
    }

    /**
     * Assembles the AdminNeo configuration array for a single data connection.
     *
     * The connection is registered as a pre-configured server keyed by its alias, so the alias
     * doubles as the AdminNeo "server key" used throughout the URLs. SSL/driver options are put
     * into the server's own `config`, which AdminNeo merges into the global config once that
     * server is selected.
     *
     * @param string $selector
     * @param array $auth
     * The optional relationMatcher value originates from the connection UXON property
     * relation_matcher and enables AdminNeo's RegexForeignKeys plugin in the wrapper.
     *
     * @return array
     */
    protected function buildAdminneoConfig(string $selector, array $auth) : array
    {
        $server = [
            'driver' => $auth['driver'],
            'server' => $auth['server'],
            'username' => $auth['username'],
            'password' => $auth['password'],
            'database' => $auth['db'],
            'name' => $selector
        ];
        if (! empty($auth['ssl'])) {
            $server['config'] = $auth['ssl'];
        }

        $config = [
            // No default password required - AdminNeo connects with the configured credentials.
            'defaultPasswordHash' => '',
            'embeddedMode' => true,
            'servers' => [$selector => $server]
        ];
        if (! empty($auth['relationMatcher'])) {
            $config['relationMatcher'] = $auth['relationMatcher'];
        }

        // Optional user-provided defaults (theme, colorVariant, navigationMode, ...). Kept in a
        // JSON file so the look & feel and future behaviour flags are configurable without code.
        foreach ($this->getConfigFileDefaults() as $key => $val) {
            if (! array_key_exists($key, $config)) {
                $config[$key] = $val;
            }
        }

        return $config;
    }

    /**
     * Loads the optional AdminNeo defaults from `Adminneo/config/adminneo.config.json`.
     *
     * @return array
     */
    protected function getConfigFileDefaults() : array
    {
        $file = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'Adminneo' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'adminneo.config.json';
        if (! file_exists($file)) {
            return [];
        }
        return JsonDataType::decodeJson(file_get_contents($file)) ?: [];
    }

    /**
     * Publishes the context (config, service title) the wrapper reads to build the Admin instance.
     *
     * @param array $config
     * @return void
     */
    protected function setAdminneoContext(array $config) : void
    {
        $basePath = rtrim((string) parse_url($this->getWorkbench()->getUrl(), PHP_URL_PATH), '/') . '/';
        $GLOBALS[self::CONTEXT_GLOBAL] = [
            'config' => $config,
            'serviceTitle' => 'SQL Admin',
            'mermaidUrl' => $basePath . 'vendor/exface/core/Facades/AbstractAjaxFacade/js/mermaid.min.js',
            'svgPanZoomUrl' => $basePath . 'vendor/exface/core/Facades/AbstractAjaxFacade/js/svg-pan-zoom.min.js'
        ];
    }

    /**
     * Starts a dedicated session for AdminNeo, isolated from the workbench session.
     *
     * AdminNeo needs an active session (it stores the login in `$_SESSION` and calls
     * `session_regenerate_id()` on login). By giving it its own session name we avoid two problems
     * without patching the fork:
     *
     * - the "Session ID cannot be regenerated when there is no active session" warning that occurs
     *   when the workbench has already closed its session before dispatching, and
     * - any interference of AdminNeo's `session_regenerate_id()` with the workbench session, since
     *   it now rotates a separate cookie.
     *
     * Because AdminNeo's bootstrap only starts its own session while `SID` is undefined, starting
     * ours first makes the fork reuse it.
     *
     * @return void
     */
    protected function startIsolatedSession() : void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            if (session_name() === self::SESSION_NAME) {
                return;
            }
            session_write_close();
        }

        session_name(self::SESSION_NAME);
        $sessionId = $_COOKIE[self::SESSION_NAME] ?? '';
        if ($sessionId !== '' && preg_match('/^[a-zA-Z0-9,-]+$/', $sessionId)) {
            session_id($sessionId);
        } else {
            // ExFace leaves its own session ID configured after closing that session. Clear it so
            // AdminNeo does not reuse the workbench session and generate mismatched CSRF tokens.
            $sessionId = '';
            session_id('');
        }
        session_start();

        if ($sessionId !== session_id()) {
            $sessionId = session_id();
            $params = session_get_cookie_params();
            setcookie(
                self::SESSION_NAME,
                $sessionId,
                0,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }
        // Tell AdminNeo that a session cookie is in use so it emits clean, cookie-based URLs
        // instead of appending the session id to every link (see AdminNeo\sid()). Without this the
        // first rendered page carries `?<name>=<sid>` links and, because PHP ignores the URL id
        // (session.use_only_cookies), clicking them would start a fresh, logged-out session.
        $_COOKIE[self::SESSION_NAME] = $sessionId;
    }

    /**
     * Runs the AdminNeo fork from source and returns its output (when capturing).
     *
     * @param bool $capture
     * @return string|NULL
     */
    protected function launchAdminneo(bool $capture = true) : ?string
    {
        // Asset requests (capture=false) are served by file.inc.php, which streams the cached
        // file and exit()s before AdminNeo ever starts its own session. Opening our isolated
        // session here would leave a foreign session active when the workbench saves its context
        // scopes on shutdown: SessionContextScope::sessionOpen() then calls session_start() on the
        // still-active session and PHP appends a "session already active" notice to the streamed
        // JS/CSS, breaking it. The asset route needs no session at all, so skip it while streaming.
        if ($capture) {
            $this->startIsolatedSession();
        }
        $cwd = getcwd();
        chdir($this->getForkAdminPath());
        if ($capture) {
            // AdminNeo streams its output: slow_query() and the running-query feedback on the SQL
            // page call ob_flush()/flush() mid-render. The callback buffer below returns an empty
            // string to swallow the *content* of those flushes, but the bare flush() still forces
            // mod_php to commit the HTTP headers immediately - which then breaks the facade's own
            // PSR-7 response with "headers already sent". Embedded mode (see buildAdminneoConfig()
            // and \AdminNeo\flush_output()) tells the fork to skip these
            // mid-render flushes while we capture, so the whole page is emitted in one piece.
            $captured = '';
            $collector = function (string $chunk) use (&$captured) : string {
                $captured .= $chunk;
                return '';
            };
            ob_start($collector);
            $baseLevel = ob_get_level();
            // Marker byte so AdminNeo sees a "used" output buffer and skips zlib.output_compression
            // in page_header(); stripped from the result below.
            echo "\0";
            try {
                require $this->getWrapperPath();
            } finally {
                // Fold any buffers AdminNeo left open down into our collector, then flush it.
                while (ob_get_level() > $baseLevel) {
                    ob_end_flush();
                }
                if (ob_get_level() >= $baseLevel) {
                    ob_end_clean();
                }
                chdir($cwd);
            }
            return ltrim($captured, "\0");
        }
        // Streaming mode (asset requests): AdminNeo emits the file and exit()s, so anything below
        // the require is only reached if the app returns normally.
        require $this->getWrapperPath();
        chdir($cwd);
        return null;
    }

    /**
     * Absolute path to the `admin/` folder of the axenox/adminneo fork (its working directory).
     *
     * @return string
     */
    protected function getForkAdminPath() : string
    {
        return $this->getWorkbench()->filemanager()->getPathToVendorFolder()
            . DIRECTORY_SEPARATOR . 'axenox'
            . DIRECTORY_SEPARATOR . 'adminneo'
            . DIRECTORY_SEPARATOR . 'admin'
            . DIRECTORY_SEPARATOR;
    }

    /**
     * Absolute path to the wrapper that boots the fork with the ExFace context.
     *
     * @return string
     */
    protected function getWrapperPath() : string
    {
        return __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'Adminneo' . DIRECTORY_SEPARATOR . 'adminneo.php';
    }

    /**
     * {@inheritDoc}
     * @see SqlAdminApiInterface::exportDDL()
     */
    public function exportDDL(SqlDataConnectorInterface $connection, string $tableOrViewName, ?string $schema = null, ?string $style = 'CREATE') : string
    {
        $this->bootForConnection($connection, $schema);

        // AdminNeo exposes DDL helpers as namespaced functions defined by the active driver
        // (e.g. \AdminNeo\create_sql), mirroring how the fork's own pages call them.
        $tableStatus = \AdminNeo\table_status1($tableOrViewName);
        if ($tableStatus && \AdminNeo\is_view($tableStatus)) {
            $viewStatus = \AdminNeo\view($tableOrViewName);
            return "CREATE VIEW $tableOrViewName AS \n" . $viewStatus['select'];
        }

        $dump = \AdminNeo\create_sql($tableOrViewName, false, $style);
        if (empty($dump)) {
            $dump = '-- ERROR: table "' . $tableOrViewName . '" not found in schema/tablespace "' . $schema . '"';
        }
        return $dump;
    }

    /**
     * {@inheritDoc}
     * @see SqlAdminApiInterface::runSql()
     */
    public function runSql(SqlDataConnectorInterface $connection, string $sql) : array
    {
        $this->bootForConnection($connection);
        return \AdminNeo\get_rows($sql);
    }

    /**
     * Boots AdminNeo for programmatic access (exportDDL/runSql) and connects to the given data
     * connection without rendering a usable page.
     *
     * @param SqlDataConnectorInterface $connection
     * @param string|null $schema
     * @return void
     */
    protected function bootForConnection(SqlDataConnectorInterface $connection, ?string $schema = null) : void
    {
        if (class_exists('\AdminNeo\Connection', false) && \AdminNeo\Connection::exists()) {
            return;
        }

        $selector = $connection->getAliasWithNamespace();
        $config = $connection->exportUxonObject()->toArray();
        $auth = $this->getAdminneoAuth($config, get_class($connection));
        if ($auth === null) {
            throw new UnexpectedValueException('Cannot open SQL admin for connection "' . $selector . '": unsupported connector "' . get_class($connection) . '"');
        }

        $this->setAdminneoContext($this->buildAdminneoConfig($selector, $auth));

        $_GET = [];
        $_GET[$auth['driver']] = $selector;
        $_GET['username'] = $auth['username'];
        $_GET['db'] = $auth['db'];
        if (($schema = $schema ?? $this->getSchemaFromConfig($config)) !== null && $schema !== '') {
            $_GET['ns'] = $schema;
        }

        $this->startIsolatedSession();
        $this->injectLogin($selector, $auth);
        $this->launchAdminneo(true);
    }
}