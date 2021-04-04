<?php
/**
 * @brief An output cache that aims minimize the database load and maximize
 * the number of requests that can be handled per seconds by a Xataface 
 * application.
 *
 */
class Xataface_Scaler {

	public $CACHE_PATH = 'cache';
	public $KEY = 'This is a secret key';
	public $DEFAULT_ENV = '+user=&lang=en';
    private $environmentString;
	public $EXPIRES = 3600;
    public $USER_DETAILS_EXPIRY = 3600000;
    public $APP_DETAILS_EXPIRY = 3600000;

    public const DEBUG_LEVEL_VERBOSE = 100;
    public const DEBUG_LEVEL_SHOW_MISSES = 75;
    public const DEBUG_LEVEL_INFO = 50;
    public const DEBUG_LEVEL_WARNING = 25;
    public const DEBUG_LEVEL_ERROR = 10;
    public const DEBUG_LEVEL_NONE = 0;

	public $DEBUG_LEVEL= 0;
	public $MEMCACHE = null;
	public $MEMCACHE_HOST = 'localhost';
	public $MEMCACHE_PORT = 11211;
	public $COOKIE_PATH = '/';
	public $RESPECT_HTTP_CACHE_CONTROL_HEADERS = false;
	public $RESPECT_HTTP_EXPIRES_HEADERS = false;
	public $RESPECT_TABLE_MODIFICATION_TIMES = true;
    public $KEY_PREFIX = '';
    private $pageId;
    private $ignoreGetParams = ['--referrer', '--referer'];
    private $fsVersion;
    
    
    private $userDetails;
    private $appDetails;
    private $pageDetails;
    private $environmentId;
    
    /**
     * A local data-structure to store items retrieved from the cache.
     */
    private $cache = [];
	
    private $cachedContent = null;
	protected $cachedHeaders = null;
	protected $mtimeIndex = null;
	
	
	private static $instance = null;
	public static function getInstance(){
		if ( !isset(self::$instance) ){
			self::$instance = new Xataface_Scaler;
		}
		return self::$instance;
	}
    

    
    /**
     * Creates the scaler.
     * Looks for $SCALER_PARAMS global associative array, and will set properties according
     * to values there.
     * 
     * @throws Exception if memcache is not installed of if failedto connect.
     */
	public function __construct(){
        global $SCALER_PARAMS;
        if (!empty($SCALER_PARAMS)) {
            foreach ($SCALER_PARAMS as $k=>$v) {
                $this->{$k} = $v;
            }
        }
		
		if ( function_exists('memcache_connect') ){
			$this->MEMCACHE = new Memcache;
            $res = $this->MEMCACHE->connect($this->MEMCACHE_HOST, $this->MEMCACHE_PORT);
            if (!$res) {
                throw new Exception("Failed to connect to memcache");
            }
            $this->CACHE_PATH = '';
		} else {
		    throw new Exception("Memcache not installed");
		}
		if (!empty($_SERVER['HTTP_HOST'])) {
		    if (empty($this->KEY_PREFIX)) {
		        $this->KEY_PREFIX = $_SERVER['HTTP_HOST'];
		    } else {
		        $this->KEY_PREFIX .= '@'.$_SERVER['HTTP_HOST'];
		    }
		    
		}
	}
    
    private function getFSVersion() {
    
        if (!isset($this->fsVersion)) {
            $included_files = get_included_files();
            $basePath = '';
            if (!empty($included_files)) {
                $basePath = dirname($included_files[0]) . DIRECTORY_SEPARATOR;
            }
			if ( file_exists($basePath.'version.txt') ){
			    if ($this->DEBUG_LEVEL >= self::DEBUG_LEVEL_VERBOSE) {
			        $this->debug("getFSVersion() : version.txt file FOUND"); 
			    }
				$varr = file($basePath.'version.txt');
			
				$fs_version = '0';
				if ( $varr ){
					list($fs_version) = $varr;
				}
				$fs_version = explode(' ', $fs_version);
                $fs_version = intval($fs_version[count($fs_version)-1]);
                
			} else {
			    if ($this->DEBUG_LEVEL >= self::DEBUG_LEVEL_VERBOSE) {
			        $this->debug("getFSVersion() : version.txt file not found"); 
			    }
				$fs_version = '0';
			}
		    if ($this->DEBUG_LEVEL >= self::DEBUG_LEVEL_VERBOSE) {
                $this->debug("getFSVersion() = ".$fs_version);
            }
			
			$this->fsVersion = $fs_version;
        }
        if ($this->DEBUG_LEVEL >= self::DEBUG_LEVEL_VERBOSE) {
            $this->debug("returning fsVersion = ".$this->fsVersion);
        }
        return $this->fsVersion;
    }
  
    /**
     * Gets the version string for the current user or null if none is cached,.
     *
     * This depends on appDetails, userDetails, and pageDetails all being available
     * in order to compute this version string.
     *
     * @return mixed The version string, or null if it couldn't compute the version string.
     */
    private function getVersionString() {
        $appDetails = $this->getAppDetails(); // Cached app-wide details
        if ($this->DEBUG_LEVEL >= self::DEBUG_LEVEL_VERBOSE) {
            $this->debug("in getVersionString, appDetails=".json_encode($appDetails));
        }
        $userDetails = $this->getUserDetails(); // Cached user details
        $pageDetails = $this->getPageDetails(); // Cached page details
        
        if (!isset($pageDetails['tables']) or !isset($appDetails['version']) or !isset($userDetails['appVersion']) ) {
            // All of appDetails, userDetails, and pageDetails *must* be valid in order for the 
            // cache to be considered consistent.
            // We can't guarantee that memcached won't purge one of these but leave another
            // so we must demand that they *all* are consistent, or we might accidentally
            // return an old version of the content from the cache.
            if ($this->DEBUG_LEVEL >= self::DEBUG_LEVEL_VERBOSE) {
                if (!isset($pageDetails['tables'])) {
                    $this->debug('getVersionString() : failed because pageDetails not found or was invalid.  pageDetails='.json_encode($pageDetails));
                }
                if (!isset($appDetails['version'])) {
                    $this->debug('getVersionString() : failed because appDetails not found or was invalid. appDetails='.json_encode($appDetails));
                }
                if (!isset($userDetails['appVersion'])) {
                    $this->debug('getVersionString() : failed because userDetails was not found or was invalid. userDetails='.json_encode($userDetails));
                }
            }
            return null;
        }
        $appVersion = 0;
        $userAppVersion = 0;
        $baseVersion = 0;
        if (!empty($appDetails['baseVersion'])) {
            $baseVersion = intval($appDetails['baseVersion']);
        }
        if (!empty($appDetails['version'])) {
            $appVersion = intval($appDetails['version']);
        }
        if (!empty($userDetails['appVersion'])) {
            $userAppVersion = intval($userDetails['appVersion']);
        }
        
        $data = ['appVersion' => $baseVersion . '.' . $appVersion . '.' . $userAppVersion];
        
        $userTableVersionsExist = array_key_exists('tableVersions', $userDetails);
        $appTableVersionsExist = array_key_exists('tableVersions', $appDetails);
        foreach ($this->getRequestTables() as $t) {
            $appTableVersion = 0;
            $userTableVersion = 0; 
            
            if ($userTableVersionsExist and !empty($userDetails['tableVersions'][$t])) {
                $userTableVersion = $userDetails['tableVersions'][$t];
            }
            if ($appTableVersionsExist and !empty($appDetails['tableVersions'][$t])) {
                $appTableVersion = $appDetails['tableVersions'][$t];
            }
            $data['tables.'.$t] = $appTableVersion . '.' . $userTableVersion;
        }
        
        ksort($data);
        return http_build_query($data);
    }
    

    
    /**
     * Gets the version hash for the current user or null if none is cached.
     *
     * This depends on appDetails, userDetails, and pageDetails all being available so that
     * it can properly compute the version hash for the current request.
     *
     * @return mixed String with version hash or null if version hash can't be computed.
     */
    private function getVersionHash() {
        $str = $this->getVersionString();
        if ($str===null) return null;
        return md5($str);
    }
    
    /**
     * Get app details key.  Depends on the file system version.
     * @return string the app details key.
     * @see #getAppDetails()
     */
    private function getAppDetailsKey() {
        return $this->KEY_PREFIX . 'scaler.appDetails@' . $this->getFSVersion();
    }
    
    /**
     * Gets cached app details array.  May be an empty array if no details are currently cached.
     * This can be purged by incrementing version in version.txt file.
     *
     * Data structure:
     * [
     *      version => $appVerson:int,
     *      tableVersions => [$tablename => $tableVersion:int]
     * ]
     */
    private function getAppDetails() {

        if (!isset($this->appDetails)) {
            $details = $this->MEMCACHE->get($this->getAppDetailsKey());
            if ($details) {
                if ($this->DEBUG_LEVEL >= self::DEBUG_LEVEL_VERBOSE) $this->debug("getAppDetails(): Found at key ".$this->getAppDetailsKey());
                $this->appDetails = $details;
            } else {
                if ($this->DEBUG_LEVEL >= self::DEBUG_LEVEL_VERBOSE) $this->debug("getAppDetails(): Not found at key ".$this->getAppDetailsKey());
                $this->appDetails = [];
            }
        }
        return $this->appDetails;
    }
    
    /**
     * Gets the user details memcache key.
     *
     * This depends on the version in version.txt so you can purge it by incrementing that version.
     *
     * @return string The key.
     */
    private function getUserDetailsKey() {
        return $this->KEY_PREFIX . 'scaler.users.' . $this->getEnvironmentId() . '@' . $this->getFSVersion();
    }
    
    /**
     * Gets the user details array.  May be an empty arry if no details are currently cached.
     *
     * @return array The user details array.  Array will be empty if userDetails for the current environment
     *  are not cached.
     *
     * Data structure:
     * [
     *      appVersion => $appVersion:int,
     *      tableVersions => [$tablename => $tableVersion:int]
     * ]
     */
    private function getUserDetails() {

        if (!isset($this->userDetails)) {
            $details = $this->MEMCACHE->get($this->getUserDetailsKey());
            if ($details) {
                if ($this->DEBUG_LEVEL >= self::DEBUG_LEVEL_VERBOSE) $this->debug("getUserDetails(): Found at key ".$this->getUserDetailsKey());
                $this->userDetails = $details;
            } else {
            if ($this->DEBUG_LEVEL >= self::DEBUG_LEVEL_VERBOSE) $this->debug("getUserDetails(): NOT Found at key ".$this->getUserDetailsKey());
                $this->userDetails = [];
            }
        }
        return $this->userDetails;
    }
    
    /**
     * Gets the page details key used to store and get page details from memcache.
     *
     * Depends on version in version.txt file, so you can purge this entry by incrementing that version.
     *
     * @return string The page details key.
     */
    private function getPageDetailsKey() {
        return $this->KEY_PREFIX . 'scaler.pages.' . $this->getEnvironmentId() . '. '. $this->getPageId() . '@' . $this->getFSVersion();
    }
    
    /**
     * Gets details about the page being requested (in the current environment).  
     *
     * Data structure:
     *
     * [
     *      tables => [$tablename:string]
     * ]
     */
    private function getPageDetails() {
        if (!isset($this->pageDetails)) {
            $details = $this->MEMCACHE->get($this->getPageDetailsKey());
            if (is_array($details)) {
                if ($this->DEBUG_LEVEL >= self::DEBUG_LEVEL_VERBOSE) $this->debug("getPageDetails(): Found at key ".$this->getPageDetailsKey());
                $this->pageDetails = $details;
            } else {
                if ($this->DEBUG_LEVEL >= self::DEBUG_LEVEL_VERBOSE) $this->debug("getPageDetails(): NOT Found at key ".$this->getPageDetailsKey());
                $this->pageDetails = [];
            }
        }
        return $this->pageDetails;
    }
    
    /**
     * Gets list of tables that the specified table depends on.  This is used by {@link #createPageDetails()} for constructing
     * the "tables" key.
     * @param string $tablename The name of the table.
     * @return [$tablename:string]{0..}
     */
    private function getDependsForTable($tablename) {
        $path = [];
        $out = $this->_getDependsForTable($tablename, true, $path);
        asort($out);
        return $out;
        
    }
    
    /**
     * Recursive function called by {@link #getDependsForTable()}.
     */
    private function _getDependsForTable($tablename, $recurse, &$path) {
        if (in_array($tablename, $path)) {
            throw new Exception("Circular dependency in table discovered.  Path: " . implode('/', $path) . ' tablename='.$tablename);
        }
        if ($this->DEBUG_LEVEL >= self::DEBUG_LEVEL_VERBOSE) $this->debug("_getDependsForTable($tablename, $recurse, ".json_encode($path).")");
        array_push($path, $tablename);
        $tableObj = (strstr($tablename, '_my_') == $tablename) ? Dataface_Table::loadTable(substr($tablename, 4)) : Dataface_Table::loadTable($tablename);
        $del = isset($tableObj) ? $tableObj->getDelegate() : null;
        if ($del and method_exists($del, '__depends__')) {
            $depends = $del->__depends__();
        } else {
            $depends = $tableObj->getAttribute('depends');
            if ($depends) {
                $depends = array_map('trim', explode(',', $depends));
            } else {
                $depends = [];
            }
        }
        if ($recurse) {
            foreach ($depends as $dep) {
                $childDeps = $this->_getDependsForTable($dep, true, $path);
                $depends = array_merge($depends, $childDeps);
            } 
        }
        
        array_pop($path);
        
        return $depends;
        
        
    }
    
    /**
     * Method to construct page details for the current request.  This would be called in flushBuffer() after
     * a request (that didn't use the cache) is completed.
     */
    private function createPageDetails() {
        if ($this->DEBUG_LEVEL >= self::DEBUG_LEVEL_VERBOSE) $this->debug("createPageDetails()");
        $app = Dataface_Application::getInstance();
        $query = $app->getQuery();
        $actionTool = Dataface_ActionTool::getInstance();
        $scalerConfig = isset($app->_conf['_cache']) ? $app->_conf['_cache'] : [];
        $trackUsedTables = true;
        if (isset($scalerConfig['trackUsedTables']) and empty($scalerConfig['trackUsedTables'])) {
            if ($this->DEBUG_LEVEL >= self::DEBUG_LEVEL_VERBOSE) $this->debug("trackUsedTables disabled in root scaler config.");
            $trackUsedTables = false;
        }
        

        $mainTableName = $query['-table'];
        $mainTableObj = Dataface_Table::loadTable($mainTableName);
        if ($this->DEBUG_LEVEL >= self::DEBUG_LEVEL_VERBOSE) $this->debug("createPageDetails: mainTableName:$mainTableName");
        $res = $mainTableObj->getAttribute('scaler.trackUsedTables');
        if (isset($res)) {
            $trackUsedTables = $res ? true : false;
            if ($this->DEBUG_LEVEL >= self::DEBUG_LEVEL_VERBOSE) $this->debug("trackUsedTables ".$res." in ".$mainTableName." scaler config.");
        }
        
        if ($this->DEBUG_LEVEL >= self::DEBUG_LEVEL_VERBOSE) $this->debug("createPageDetails: action.name=".$query['-action']);
        $overrideDepends = [];
        if (!empty($query['-action'])) {
            $action = $actionTool->getAction(['name' => $query['-action']]);
            if (is_array($action)) {
                if ($this->DEBUG_LEVEL >= self::DEBUG_LEVEL_VERBOSE) $this->debug("createPageDetails: action=".json_encode($action));

                if (isset($action['scaler.trackUsedTables'])) {
                    if ($this->DEBUG_LEVEL >= self::DEBUG_LEVEL_VERBOSE) $this->debug("trackUsedTables =".(!empty($action['scaler.trackUsedTables']))." in action ".$action['name']." scaler config.");
                    $trackUsedTables = !empty($action['scaler.trackUsedTables']);
                }
                
                if (isset($action['scaler.depends'])) {
                    $overrideDepends = array_map('trim', explode(',', $action['scaler.depends']));
                }
            }    
        }
        
        
        $appDel = $app->getDelegate();
        if ($appDel and method_exists($appDel, 'scaler__trackUsedTables')) {
            $res = $appDel->scaler__trackUsedTables();
            if (isset($res)) {
                if ($this->DEBUG_LEVEL >= self::DEBUG_LEVEL_VERBOSE) $this->debug("trackUsedTables ".$res." in application delegate ");
                $trackUsedTables = $res ? true : false;
            }
        }
        
        $del = $mainTableObj->getDelegate();
        if ($del and method_exists($del, 'scaler__trackUsedTables')) {
            $res = $del->scaler__trackUsedTables();
            if (isset($res)) {
                if ($this->DEBUG_LEVEL >= self::DEBUG_LEVEL_VERBOSE) $this->debug("trackUsedTables ".$res." in ".$mainTableName." delegate.");
                $trackUsedTables = $res ? true : false;
            }
        }
        
        $tables = !empty($overrideDepends) ? $overrideDepends : [$mainTableName];
        if ($trackUsedTables and !empty($app->tableNamesUsed)) {
            if ($this->DEBUG_LEVEL >= self::DEBUG_LEVEL_VERBOSE) $this->debug("trackUsedTables enabled.  Tables Used: ".json_encode($app->tableNamesUsed));
            $tables = array_merge($tables, $app->tableNamesUsed);
        } else {
            if ($this->DEBUG_LEVEL >= self::DEBUG_LEVEL_VERBOSE) $this->debug("trackUsedTables disabled");
        }
        if (empty($overrideDepends)) {
            
        }
        $tables2 = [];
        foreach ($tables as $table) {
            $tables2[] = $table;
            $tables2 = array_merge($tables2, $this->getDependsForTable($table));
        }
        $tables2 = array_unique($tables2);
        asort($tables2);
        
        $out['tables'] = $tables2;
        if ($this->DEBUG_LEVEL >= self::DEBUG_LEVEL_VERBOSE) $this->debug("createPageDetails() DONE ".json_encode($out));
        return $out;
        
    }
    
    /**
     * Creates user details structure from the database.  This is called in the flushBuffer phase in cases
     * where the userDetails entry cannot be found in memcache, either because it was purged, or because it
     * hasn't been createed yet.
     *
     * @param string $username The username to create the user details structure for.
     * @return array The user details structure.
     *
     * Data structure: @see #getUserDetails()
     */
    private function createUserDetails($username) {
        $sql = "select `tablename`, `version` from `dataface__content_versions` where `username` = '".addslashes($username)."'";
        $res = xf_db_query($sql, df_db());
        if (!$res) {
            error_log("Error getting content versions: " . xf_db_error(df_db()));
            throw new Exception("SQL error getting content versions");
        }
        $out = ['tableVersions' => [], 'appVersion' => 0];
        $tableVersions =& $out['tableVersions'];
        while ($row = xf_db_fetch_row($res)) {
            list($tablename, $version) = $row;
            if ($tablename) {
                $tableVersions[$tablename] = intval($version);
            } else {
                $out['appVersion'] = intval($version);
            }
        }
        
        return $out;
    }
    
    /**
     * Updates a given user details structure using the Dataface_Application#getCacheUpdates() to get a list
     * of cache commands that need to be executed from the current request.
     *
     * Note: This is run in the flushBuffer phase after a cache miss, so it has access to All of Xataface Application
     *  and the database.
     * 
     * @param string $username The username of the user details.
     * @param array $userDetails Array structure that will be updated in place.  Structure is as
     *  described in #getUserDetails()
     * @return boolean true if the details were updated.  false if no changes were made to the user details.
     */
    private function updateUserDetails($username, array &$userDetails) {
        if ($this->DEBUG_LEVEL >= self::DEBUG_LEVEL_VERBOSE) $this->debug("updateUserDetails($username, ".json_encode($userDetails).")");
        $app = Dataface_Application::getInstance();
        $updated = false;
        $updates = $app->getCacheUpdates();
        if (!isset($userDetails['tableVersions'])) {
            $userDetails['tableVersions'] = [];
        }
        $tableVersions =& $userDetails['tableVersions'];
        foreach ($updates as $cmd) {
            if ($cmd['user'] === '' or $cmd['user'] == $username) {
                if ($cmd['table'] === '') {
                    if (!isset($userDetails['appVersion'])) {
                        $userDetails['appVersion'] = 0;
                    }
                    $userDetails['appVersion']++;
                    $updated = true;
                } else {
                    $table = $cmd['table'];
                    if (!isset($tableVersions[$table])) {
                        $tableVersions[$table] = 0;
                    }
                    $tableVersions[$table]++;
                    $updated = true;
                }
            }
        }
        if ($this->DEBUG_LEVEL >= self::DEBUG_LEVEL_VERBOSE) $this->debug("after updateUserDetails: userDetails=".json_encode($userDetails));
        return $updated;
    }
    
   
    
    /**
     * Creates the appDetails structure based on the state in the dataface__content_versions table.
     * 
     * @return array app details structure.  See #getAppDetails() for the structure format.
     */
    private function createAppDetails() {
        $sql = "select `tablename`, `version` from `dataface__content_versions` where `username` = ''";
        $res = xf_db_query($sql, df_db());
        if (!$res) {
            error_log("Error getting content versions: " . xf_db_error(df_db()));
            throw new Exception("SQL error getting content versions");
        }
        $out = ['tableVersions' => [], 'version' => 0];
        $tableVersions =& $out['tableVersions'];
        while ($row = xf_db_fetch_row($res)) {
            list($tablename, $version) = $row;
            if ($tablename) {
                $tableVersions[$tablename] = intval($version);
            } else {
                $out['version'] = intval($version);
            }
        }
        xf_db_free_result($res);
        $out['baseVersion'] = df_get_database_version();
        return $out;
    }
    
    /**
     * Updates an appDetails structure using the Dataface_Application::getCacheUpdates() method to get
     * the cache update commands that should be executed after this request to purge cache as necessary.
     * 
     * @param array $appDetails Datastructure for app details that will be updated in place.  See #getAppDetails()
     *  for the array structure.
     * @return boolean true if changes were made to the structure.  false otherwise.
     */
    private function updateAppDetails(array &$appDetails) {
        if ($this->DEBUG_LEVEL >= self::DEBUG_LEVEL_VERBOSE) $this->debug('updateAppDetails(): '.json_encode($appDetails));
        $app = Dataface_Application::getInstance();
        $updated = false;
        $updates = $app->getCacheUpdates();
        if (!isset($appDetails['tableVersions'])) {
            $appDetails['tableVersions'] = [];
        }
        $tableVersions =& $appDetails['tableVersions'];
        foreach ($updates as $cmd) {
            if ($cmd['user'] !== '') {
                continue;
            }
            if ($cmd['table'] === '') {
                if (!isset($appDetails['version'])) {
                    $appDetails['version'] = 0;
                }
                $appDetails['version']++;
                $updated = true;
            } else {
                $table = $cmd['table'];
                if (!isset($tableVersions[$table])) {
                    $tableVersions[$table] = 0;
                }
                $tableVersions[$table]++;
                $updated = true;
            }
        }
        $dbVersion = df_get_database_version();
        if (!isset($appDetails['baseVersion']) or $dbVersion !== $appDetails['baseVersion']) {
            $appDetails['baseVersion'] = intval($dbVersion);
            $updated = true;
        }
        
        if ($this->DEBUG_LEVEL >= self::DEBUG_LEVEL_VERBOSE) {
            if ($updated) {
                $this->debug('updateAppDetails() finished.  updated=true; appDetails='.json_encode($appDetails));
            }
        }
        return $updated;
        
    }
    
    /**
     * Goes through the cache updates from the past request, and "persists" them to the database.
     */
    private function commitCacheUpdatesToPersistentStorage() {
        $app = Dataface_Application::getInstance();
        $updated = false;
        $updates = $app->getCacheUpdates();
        if ($this->DEBUG_LEVEL >= self::DEBUG_LEVEL_VERBOSE) {
            $this->debug("commitCacheUpdatesToPersistentStorage() : Updates: ".count($updates));
        }
        
        foreach ($updates as $cmd) {
            $user = $cmd['user'];
            $table = $cmd['table'];
            $sql = "update `dataface__content_versions` set `version`=`version`+1 where `username`='".addslashes($user)."' and `tablename`='".addslashes($table)."'";
            $res = xf_db_query($sql, df_db());
            if (!$res) {
                $this->debug('['.__FILE__.':'.__LINE__.'] '."SQL query failure: ".$sql.", ERROR: ".xf_db_error(df_db()));
                throw new Exception("SQL query failure");
            }
            if (xf_db_affected_rows(df_db()) === 0) {
                $sql = "insert into `dataface__content_versions` (`username`, `tablename`, `version`) values ('".addslashes($user)."', '".addslashes($table)."', 1)";
                $res = xf_db_query($sql, df_db());
                if (!$res) {
                    $this->debug('['.__FILE__.':'.__LINE__.'] '."SQL query failure". $sql.", ERROR: ".xf_db_error(df_db()));
                    throw new Exception("SQL query failure");
                }
            }
        }
    }
    
    /**
     * Processes all cache updates that occurred during the request, updating both the MySQL persistent
     * storage of content versions in the dataface__content_versions table, and the memcache caches.
     * @see Dataface_Application::getCacheUpdates()
     */
    private function processCacheUpdates($username) {
        if ($this->DEBUG_LEVEL >= self::DEBUG_LEVEL_VERBOSE) $this->debug('processCacheUpdates()');
        $this->commitCacheUpdatesToPersistentStorage();
        $userDetails = $this->getUserDetails();
        $updatedUserDetails = false;
        if (array_key_exists('appVersion', $userDetails)) {
            $updatedUserDetails = $this->updateUserDetails($username, $userDetails);
        } else {
            $userDetails = $this->createUserDetails($username);
            $updatedUserDetails = true;
        }
        if ($updatedUserDetails) {
            if ($this->DEBUG_LEVEL >= self::DEBUG_LEVEL_VERBOSE) $this->debug("processCacheUpdates($username): userDetailsUpdated.  Writing to ".$this->getUserDetailsKey());
            $res = $this->MEMCACHE->set($this->getUserDetailsKey(), $userDetails, 0, 0);
            if (!$res) {
                if ($this->DEBUG_LEVEL >= self::DEBUG_LEVEL_VERBOSE) {
                    $this->debug("MEMCACHE->set(".$this->getUserDetailsKey().") failed");
                }
            }
            $this->userDetails = $userDetails;
        }
        
        $appDetails = $this->getAppDetails();
        $updatedAppDetails = false;
        if (array_key_exists('version', $appDetails)) {
            $updatedAppDetails = $this->updateAppDetails($appDetails);
        } else {
            $appDetails = $this->createAppDetails();
            $updatedAppDetails = true;
        }
        if ($updatedAppDetails) {
            if ($this->DEBUG_LEVEL >= self::DEBUG_LEVEL_VERBOSE) $this->debug("processCacheUpdates($username): appDetailsUpdated. Writing to ".$this->getAppDetailsKey()." value ".json_encode($appDetails));
            $res = $this->MEMCACHE->set($this->getAppDetailsKey(), $appDetails, 0, 0);
            if (!$res) {
                if ($this->DEBUG_LEVEL >= self::DEBUG_LEVEL_WARNING) {
                    $this->debug("MEMCACHE->set(".$this->getAppDetailsKey().") failed");
                }
            }
            $this->appDetails = $appDetails;
        }
        
        
        
    }
    
    /**
     * A wraps {@link #getPageDetails()} but just returns the 'tables' array.
     * @return Array of tables used in the current request according to the cache.
     * Will return null if the cache contains no page details for this request.
     */
    private function getRequestTables() {
        $pageDetails = $this->getPageDetails();
        if (!isset($pageDetails['tables'])) {
            return [];
        }
        return $pageDetails['tables'];
        
        
    }
    
    private static function array_get(&$array, $key, $defaultValue = null) {
        return isset($array[$key]) ? $array[$key] : $defaultValue; 
    }
	
    /**
     * Returns true if the cache is enabled for this request.  
     * Will return false for post requests and where -action is js or css.
     */
	public function isEnabled(){
		return (empty($_POST) and empty($_GET['--msg']) and self::array_get($_GET, '-action') != 'js' and self::array_get($_GET, '-action') != 'css');
	}
	
    /**
     * Prints Debug message to the errorlog
     */
	protected function debug($msg){
		error_log('[Xataface_Scaler('.getmypid().')]['.$_SERVER['REQUEST_URI'].'] '.$msg);
	}
	
    /**
     * Gets key that is used as a salt for compiling the environment cookie.
     */
	protected function getKey(){
		return $this->KEY;
	}
	
    /**
     * Get XATAFACE_ENV cookie.
     * @return string cookie string or null if cookie not set.
     * @see #getEnvironmentId() To get the environemnt string whether cookie is set or not.
     */
	public function getCookie(){
		if ( !empty($_COOKIE['XATAFACE_ENV']) ){
            $cookie = $_COOKIE['XATAFACE_ENV'];
			if ( $this->DEBUG_LEVEL >= self::DEBUG_LEVEL_VERBOSE ){
				$this->debug("The Environment Cookie is set: ".$cookie);
			}
			return $cookie;
		} else {
			if ( $this->DEBUG_LEVEL >= self::DEBUG_LEVEL_VERBOSE ){
				$this->debug("The browser did not contain a cookie");
			}
			return null;
		}
	}
    
    /**
     * Sets XATAFACE_ENV cookie.
     * @param string $content The unencrypted contents to set for the XATAFACE_ENV cookie.
     * @param string $path The cookie path to set. Default is '/'
     * @return void
     */
    public function setCookie($content, $path = '/'){
		if ( !$path ) $path = '/';
		if ( $path[strlen($path)-1] != '/' ) $path .= '/';
		if ( $this->DEBUG_LEVEL >= self::DEBUG_LEVEL_VERBOSE ) $this->debug("Setting cookie: ".$content." for path ".$path);
		$content = $this->compileEnv($content);
        if ( $this->DEBUG_LEVEL >= self::DEBUG_LEVEL_VERBOSE) $this->debug("Encrypted cookie being set: ".$content);
		setcookie('XATAFACE_ENV', $content, null, $path);
	}
    
	/**
     * Gets the current environment ID.  It will try to get it from the XATAFACE_ENV cookie if it is set.  
     * Otherwise it will use the DEFAULT_ENV.
     *
     * Always returns as a sha1 hash.
     */
	public function getEnvironmentId(){
        if (!isset($this->environmentId)) {
            if ($this->DEBUG_LEVEL >= self::DEBUG_LEVEL_VERBOSE) {
                $this->debug("getEnvironmentId()");
            }
            $cookie = $this->getCookie();
            if (!$cookie) {
                if ($this->DEBUG_LEVEL >= self::DEBUG_LEVEL_VERBOSE) {
                    $this->debug("getEnvironmentId(): no cookie found.  Using default environment ".$this->DEFAULT_ENV);
                }
                $cookie = $this->compileEnv($this->DEFAULT_ENV);
            }
    		$this->environmentId = $cookie;
            if ($this->DEBUG_LEVEL >= self::DEBUG_LEVEL_VERBOSE) {
                $this->debug("getEnvironmentId() -> $cookie");
            }
        }
        return $this->environmentId;
        
	}
	
    /**
     * Gets the page ID of the current request as a sha1 hash.
     * This will generally just use the REQUEST_URI, but will strip out any GET parameters
     * listed in {@link #ignoreGetParams}
     */
	public function getPageId(){
        if (!isset($this->pageId)) {
            $url = $_SERVER['REQUEST_URI'];
            if (($pos = strpos($url, '#')) !== false) {
                $url = substr($url, strpos($url, 0, $pos));
            }
            foreach ($this->ignoreGetParams as $k) {
                $quotedK = preg_quote(urlencode($k), '/');
                $url = preg_replace('/&'.$quotedK.'=[^&]*&/', '&', $url);
                $url = preg_replace('/\?'.$quotedK.'=[^&]*&/', '?', $url);
                $url = preg_replace('/&'.$quotedK.'=[^&]*/', '', $url);
                $url = preg_replace('/\?'.$quotedK.'=[^&]*/', '', $url);
            }
            if ($url[strlen($url)-1] == '?') {
                $url = substr($url, 0, strlen($url)-1);
            }
            $this->pageId =  sha1($url);
        }
        
		return $this->pageId;
	}
    
    /**
     * Gets the memcache key for the current request.
     * @return string The memecache key or null if the key can't be computed (due to no version string).
     */
    private function getPageKey() {
		$s = DIRECTORY_SEPARATOR;
        $versionString = $this->getVersionHash();
        if ($versionString === null) {
            // If there is no version string,
            // then the cache won't function properly..  We may end up with no version string if
            // memcache has purged the app details or user details keys, or if these haven't yet 
            // been set in the cache.
            if ($this->DEBUG_LEVEL >= self::DEBUG_LEVEL_VERBOSE) {
                $this->debug("getPageKey: no version string hash cached");
                header('X-Scaler-Debug-Reason: No version string hash cached');
            }
            return null;
        }
		return $this->KEY_PREFIX . $this->CACHE_PATH.$s.$this->getEnvironmentId().$s.$this->getPageId().'@'.$versionString;
    }
	
	/**
     * Checks if the current request is cached.
     * @return boolean true if the current request can be served by the cache.
     *
     */
	public function checkCache(){
		
		
		$fpath = $this->getPageKey();
        if ($this->DEBUG_LEVEL >= self::DEBUG_LEVEL_VERBOSE) $this->debug('checkCache: pageKey='.$fpath);
        if (!isset($fpath)) {
            if ($this->DEBUG_LEVEL >= self::DEBUG_LEVEL_VERBOSE) {
                $this->debug('checkCache(): Skipping because no pageKey');
            }
            return false;
        }
		
        $content = $this->MEMCACHE->get($fpath);
		
		if ($content){
            if ($this->DEBUG_LEVEL >= self::DEBUG_LEVEL_VERBOSE) {
                $this->debug("Page found in cache at ".$fpath);
                header('X-Scaler-Version-String: ' . $this->getVersionString());
            }
            $headerEndPos = strpos($content, "\r\n\r\n");
            $headersString = substr($content, 0, $headerEndPos);
            $this->cachedContent = substr($content, $headerEndPos + 4);
			$this->cachedHeaders = explode("\r\n", $headersString);
			
			return true;
			
		
		}
        if ($this->DEBUG_LEVEL >= self::DEBUG_LEVEL_VERBOSE) {
            $this->debug("Page NOT found in cache at ".$fpath);
        }
		return false;
	}
	
	/**
     * Writes content to the cache.
     * @param string $environmentId The compiled environment ID for the request.
     * @param string $pageId The page ID for the request.
     * @param string $content The page content.
     * @return void
     */
	public function writeCache($environmentId, $pageId, $content){
	
        $versionString = $this->getVersionHash();
    
		$s = DIRECTORY_SEPARATOR;
		$fpath = $this->KEY_PREFIX . $this->CACHE_PATH.$s.basename($environmentId).$s.basename($pageId).'@'.$versionString;
		
		$headers = preg_grep('/^(Content|Location|ETag|Last|Server|Vary|Expires|Allow|Cache|Pragma)/i', headers_list());
		
        $content = implode("\r\n", $headers). "\r\n\r\n" . $content;
        if (strlen($content) > 1000000) {
            // Too big.  Not writing
            return;
        }
        if ($this->DEBUG_LEVEL >= self::DEBUG_LEVEL_VERBOSE) $this->debug('Writing to '.$fpath);
        $expires = $this->EXPIRES;
        $app = class_exists('Dataface_Application') ? Dataface_Application::getInstance() : null;
        if ($app) {
            $appExpiry = $app->getCacheExpiry();
            if (isset($appExpiry)) {
                $expires = $appExpiry;
            }
        }
		$this->MEMCACHE->set($fpath, $content, MEMCACHE_COMPRESSED, $expires);
		
	}
	
    /**
     * Some headers (e.g. location headers) may include references to ignored get parameters.
     * We need to interpolate the get parameters from the current request, since the cache
     * will have the parameters from the original request.  This is currently only used for the --referrer
     * parameter, which is by Xataface to manage the "back" button.
     * @param string $h The header.  E.g. "Location: http://example.com"
     * @return The fiiltered header.
     */
    private function filterCachedHeader($h) {
        
        foreach ($this->ignoreGetParams as $k) {
            if (isset($_GET[$k])) {
                $encodedK = urlencode($k);
                $encodedV = urlencode($_GET[$k]);
                $quotedK = preg_quote($encodedK, '/');
                $encodedKV = $encodedK . '=' . $encodedV;
                $h = preg_replace('/&'.$quotedK.'=[^&]*&/', '&' . $encodedKV .'&', $h);
                $h = preg_replace('/\?'.$quotedK.'=[^&]*&/', '?' . $encodedKV .'&', $h);
                $h = preg_replace('/&'.$quotedK.'=[^&]*/', '&' . $encodedKV, $h);
                $h = preg_replace('/\?'.$quotedK.'=[^&]*/', '?' . $encodedKV, $h);
            }
            
        }
        return $h;
    }
    
	/**
     * Outputs the cached output headers.  Headers will be filtered on the way out by {@link #filterCachedHeader()}
     * @return void
     */
	public function outputHeaders(){
		
		foreach ( $this->cachedHeaders as $h){
            
            $h = $this->filterCachedHeader($h);
            
			header($h);
		}
	}
	
	/**
     * Outputs the cached content.
     */
	public function outputContent(){
        if  (isset($this->cachedContent)) {
			header('Content-Length: '.strlen($this->cachedContent));
			header('Connection: close');
			echo $this->cachedContent;
          
        } 
		 exit;
	}
	
    /**
     * Compiles the environment string.  
     * @param string $content The environment string.  By convention a string beginning with "+" is 
     *  public (and can be cached by proxies), and a string beginning with "-" is private and 
     * cannot be cached by proxies.
     * @return string The compiled string (sha1 encrypted)
     */
	private function compileEnv($content){
	    // Using REMOTE_ADDR as salt is problematic when using Cloudflare because the REMOTE_ADDR
	    // may vary from request to request.
		//if ( !preg_match('/^\+/', $content)  ){
		//	$content = sha1($content.$_SERVER['REMOTE_ADDR'].$this->KEY);
		//} else {
		//	$content = sha1($content.$this->KEY);
		//}
		
		$content = sha1($content.$this->KEY);
		return $content;
		
	}
	
	/**
     * Starts the scaler.  Checks the cache for the current request and outputs that if found.
     * Otherwise it starts an output buffer and registers the {@link #flushBuffer()} callback
     * to run on completion.
     */
	public function start(){
	    
		// We don't handle POST requests
		if (@$_GET['-action'] == 'js' or @$_GET['-action'] == 'css' ) return;
		
		$envId = $this->getEnvironmentId();
		$pageId = $this->getPageId();
		
		if ($this->isEnabled() and $this->checkCache($envId, $pageId) ){
			// Check the headers to see when the cache should expire
			header('X-Scaler-Cache: Cached');
			$this->outputHeaders();
			$this->outputContent();
			
		}
		
		if ( defined('XF_SCALER_READ_ONLY') and XF_SCALER_READ_ONLY ){
			header('X-Scaler-Cache: DISABLED (READ ONLY) '.$this->EXPIRES);
			return;
		} else {
			header('X-Scaler-Cache: Not cached');
		}
		
		ob_start([$this, 'flushBuffer']);
	
	}
	
    /**
     * Callback for ob_start() that outputs the buffered content.  This also updates the cache.
     * @param string $buffer The buffer contents.
     * @return string The modified buffer contents.
     */
	public function flushBuffer($buffer){
        try {
    		if ( class_exists('Dataface_Application') ){
			
    			$app = Dataface_Application::getInstance();
    			$del = $app->getDelegate();
    			$user = '';
    			$anonymous = false;
    			if ( class_exists('Dataface_AuthenticationTool') ){
    				$auth = Dataface_AuthenticationTool::getInstance();
    				$user = $auth->getLoggedInUserName();
    				if ( $this->DEBUG_LEVEL >= self::DEBUG_LEVEL_VERBOSE) $this->debug("LoggedInUserName is '".$user."'");
				
    			}
			
    			if ( !$user ) $anonymous = true;
    			if ( method_exists($del, 'getOutputCacheUserId') ){
    				$nuser = $del->getOutputCacheUserId();
    				if ( $nuser ) $user = $nuser;
    				if ($this->DEBUG_LEVEL >= self::DEBUG_LEVEL_VERBOSE) $this->debug("Custom Cache User ID: '".$user."'");
    			}
			
    			$env = 'user='.urlencode($user).'&lang='.urlencode($app->_conf['lang']);
    			if ( $anonymous ) $env = '+'.$env;
    			else $env = '-'.$env;
                $this->environmentId = $this->compileEnv($env);
            
    			$this->setCookie($env, $this->COOKIE_PATH);
            
                // Update the app details and user details.
    			$this->processCacheUpdates($user);
            
            
    			if ( $this->isEnabled() and empty($app->_conf['nocache']) ){
    				if ( $this->DEBUG_LEVEL >= self::DEBUG_LEVEL_VERBOSE ) {
    				    $this->debug("Caching for action is enabled... cachine now");
                        $this->debug("Getting page detials...");
    				}
                
                
    				$pageDetails = $this->getPageDetails();
                    if ($this->DEBUG_LEVEL >= self::DEBUG_LEVEL_VERBOSE) $this->debug("Page details: ".json_encode($pageDetails));
                    if (!isset($pageDetails['tables'])) {
                        // The page details keeps a list of the tables that are relevant for this page.
                        // This needs to be cached because these tables are used to calculate the version hash
                        // so needs to be available before database is connected.
                        if ($this->DEBUG_LEVEL >= self::DEBUG_LEVEL_VERBOSE) $this->debug("Creating page details");
                        $pageDetails = $this->createPageDetails();
                        if ($this->DEBUG_LEVEL >= self::DEBUG_LEVEL_VERBOSE) $this->debug("Page details now ".json_encode($pageDetails));
                        if ( $this->DEBUG_LEVEL >= self::DEBUG_LEVEL_VERBOSE ) $this->debug("Writing page details at ".$this->getPageDetailsKey());
                        $res = $this->MEMCACHE->set($this->getPageDetailsKey(), $pageDetails, 0, $this->EXPIRES);
                        if (!$res) {
                            if ($this->DEBUG_LEVEL >= self::DEBUG_LEVEL_ERROR) {
                                $this->debug("Failed to save page details in memcache at key " . $this->getPageDetailsKey() . " pageDetails=".json_encode($pageDetails).", expires=".$this->EXPIRES);
                            }
                        } else {
                            if ($this->DEBUG_LEVEL >= self::DEBUG_LEVEL_VERBOSE) {
                                $this->debug("Successfully saved page details to ".$this->getPageDetailsKey().", content=".$pageDetails);
                            }
                        }
                        $this->pageDetails = $pageDetails;
                    }
				
    				if ( $this->DEBUG_LEVEL >= self::DEBUG_LEVEL_VERBOSE ) $this->debug("Environment writing:".sha1($this->compileEnv($env)));
    				$this->writeCache($this->compileEnv($env), $this->getPageId(), $buffer);
    			} else {
    				if ( $this->DEBUG_LEVEL >= self::DEBUG_LEVEL_VERBOSE ) $this->debug("Caching for this action is disabled.");
    			}
				
			
			
			
    		}
        } catch (Exception $ex) {
            $this->debug("Failure while updating the cache");
            $this->debug($ex->getMessage());
           
        } finally {
            if ($this->DEBUG_LEVEL >= self::DEBUG_LEVEL_VERBOSE) {
                $lastErr = error_get_last();
                if ($lastErr) {
                    $this->debug("flushBuffer(). Errors: ".json_encode($lastErr));
                }
            }
            
            return $buffer;
        }
		
	}
	
    function printDetails(){
        $status = $this->MEMCACHE->getStats();
    echo "<table border='1'>";

            echo "<tr><td>Memcache Server version:</td><td> ".$status ["version"]."</td></tr>";
            echo "<tr><td>Process id of this server process </td><td>".$status ["pid"]."</td></tr>";
            echo "<tr><td>Number of seconds this server has been running </td><td>".$status ["uptime"]."</td></tr>";
            echo "<tr><td>Accumulated user time for this process </td><td>".$status ["rusage_user"]." seconds</td></tr>";
            echo "<tr><td>Accumulated system time for this process </td><td>".$status ["rusage_system"]." seconds</td></tr>";
            echo "<tr><td>Total number of items stored by this server ever since it started </td><td>".$status ["total_items"]."</td></tr>";
            echo "<tr><td>Number of open connections </td><td>".$status ["curr_connections"]."</td></tr>";
            echo "<tr><td>Total number of connections opened since the server started running </td><td>".$status ["total_connections"]."</td></tr>";
            echo "<tr><td>Number of connection structures allocated by the server </td><td>".$status ["connection_structures"]."</td></tr>";
            echo "<tr><td>Cumulative number of retrieval requests </td><td>".$status ["cmd_get"]."</td></tr>";
            echo "<tr><td> Cumulative number of storage requests </td><td>".$status ["cmd_set"]."</td></tr>";

            if ($status["cmd_get"]) {
                $percCacheHit=((float)$status ["get_hits"]/ (float)$status ["cmd_get"] *100);
                $percCacheHit=round($percCacheHit,3);
                $percCacheMiss=100-$percCacheHit;

                echo "<tr><td>Number of keys that have been requested and found present </td><td>".$status ["get_hits"]." ($percCacheHit%)</td></tr>";
                echo "<tr><td>Number of items that have been requested and not found </td><td>".$status ["get_misses"]."($percCacheMiss%)</td></tr>";
            }
            

            $MBRead= (float)$status["bytes_read"]/(1024*1024);

            echo "<tr><td>Total number of bytes read by this server from network </td><td>".$MBRead." Mega Bytes</td></tr>";
            $MBWrite=(float) $status["bytes_written"]/(1024*1024) ;
            echo "<tr><td>Total number of bytes sent by this server to network </td><td>".$MBWrite." Mega Bytes</td></tr>";
            $MBSize=(float) $status["limit_maxbytes"]/(1024*1024) ;
            echo "<tr><td>Number of bytes this server is allowed to use for storage.</td><td>".$MBSize." Mega Bytes</td></tr>";
            echo "<tr><td>Number of valid items removed from cache to free memory for new items.</td><td>".$status ["evictions"]."</td></tr>";

    echo "</table>";

        }
	
	

}