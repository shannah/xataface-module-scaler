<?php
/**
 * @brief An output cache that aims minimize the database load and maximize
 * the number of requests that can be handled per seconds by a Xataface 
 * application.
 *
 */
class Xataface_Scaler {

	public $CACHE_PATH = 'cache';
	public $KEY_PATH = 'key.php';
	public $KEY = 'This is a secret key';
	public $DEFAULT_ENV = 'user=&lang=';
	public $EXPIRES = 360000;
	public $MTIME_INDEX_PATH = 'cache/mtime.index';
	public $DEBUG_ON= false;
	public $APC = false;
	public $NO_MEMCACHE = true;
	public $MEMCACHE = null;
	public $MEMCACHE_HOST = 'localhost';
	public $MEMCACHE_PORT = 11211;
	public $COOKIE_PATH = '/';
	public $RESPECT_HTTP_CACHE_CONTROL_HEADERS = false;
	public $RESPECT_HTTP_EXPIRES_HEADERS = false;
	public $RESPECT_TABLE_MODIFICATION_TIMES = true;
	
	
	protected $cachedContentPath = null;
	protected $cachedContentSize = null;
	protected $cachedHeaders = null;
	protected $mtimeIndex = null;
	private $key = null;
	
	
	private static $instance = null;
	public static function getInstance(){
		if ( !isset(self::$instance) ){
			self::$instance = new Xataface_Scaler;
		}
		return self::$instance;
	}
	
	public function isEnabled(){
		return (!@$_POST and @$_GET['-action'] != 'js' and @$_GET['-action'] != 'css');
	}
	
	protected function debug($msg){
		error_log('[Xataface_Scaler('.getmypid().')]['.$_SERVER['REQUEST_URI'].'] '.$msg);
	}
	
	public function __construct(){
		if ( defined('XATAFACE_SCALER_SECRET_KEY') ){
			$this->KEY = XATAFACE_SCALER_SECRET_KEY;
		}
		if ( function_exists('apc_fetch') ){
			if ( $this->DEBUG_ON ) $this->debug("APC is enabled");
			$this->APC = true;
		}
		if ( !$this->NO_MEMCACHE ){
			if ( function_exists('memcache_connect') ){
				$this->MEMCACHE = @memcache_connect($this->MEMCACHE_HOST, $this->MEMCACHE_PORT);
				
			}
		}
		if ( $this->MEMCACHE and $this->DEBUG_ON ){
			$this->debug("Memcache is enabled and connected...");
		} else if ( !$this->MEMCACHE and $this->DEBUG_ON ){
			$this->debug("Memcache is not enabled....");
		}
		$this->CACHE_PATH = dirname(__FILE__).'/../'.$this->CACHE_PATH;
		$this->KEY_PATH = dirname(__FILE__).'/../'.$this->KEY_PATH;
		$this->MTIME_INDEX_PATH = dirname(__FILE__).'/../'.$this->MTIME_INDEX_PATH;
		if ( $this->DEBUG_ON ){
			$this->debug("Cache Path: ".$this->CACHE_PATH);
			$this->debug("Key Path: ".$this->KEY_PATH);
			$this->debug("Index Path: ".$this->MTIME_INDEX_PATH);
		}
		
		if ( defined('XF_SCALER_EXPIRES') ){
			$this->EXPIRES = XF_SCALER_EXPIRES;
		}
		
	}
	
	public function getMtimeIndex(){
		if ( !isset($this->mtimeIndex) ){
			if ( $this->APC ){
				if ( $this->DEBUG_ON ){
					$this->debug("Fetching mtimes via APC");
				}
				$this->mtimeIndex = apc_fetch($this->MTIME_INDEX_PATH);
				if ( $this->mtimeIndex ) $this->mtimeIndex = unserialize($this->mtimeIndex);
			}
			if ( !$this->mtimeIndex ){
				if ( $this->DEBUG_ON ) $this->debug("Loading MtimeIndex");
				if ( is_readable($this->MTIME_INDEX_PATH) ){
					if ( $this->DEBUG_ON ) $this->debug("Mtime Index is readable");
					$contents = file_get_contents($this->MTIME_INDEX_PATH);
					if ( $contents ){
						if ( $this->DEBUG_ON ) $this->debug("Mtime Index is non-empty");
						$this->mtimeIndex = unserialize($contents);
					} else if ($this->DEBUG_ON ) {
						$this->debug("Mtime Index is empty");
					}
				} else if ( $this->DEBUG_ON ){
					$this->debug("Mtime Index is Not readable");
				}
				
				if ( !$this->mtimeIndex and !is_array($this->mtimeIndex) ){
					$this->mtimeIndex = array();
				}
			}
		}
		return $this->mtimeIndex;
	}
	
	public function saveMtimeIndex(){
		$this->getMtimeIndex();
		if ( $this->DEBUG_ON ){
			$this->debug("Saving Mtime Index...");
		}
		if ( $this->APC ){
			if ( $this->DEBUG_ON ){
				$this->debug("Saving mtime index with APC");
			}
			apc_store($this->MTIME_INDEX_PATH, serialize($this->mtimeIndex));
		} else {
			file_put_contents($this->MTIME_INDEX_PATH, serialize($this->mtimeIndex), LOCK_EX);
		}
	}
	
	
	protected function getKey(){
		return array('iv'=>'000', 'key'=>$this->KEY);
	}
	
	/**
	 * @brief Gets the secure key parameters used to encrypt the environment cookie.
	 *
	 * @return array Associative array with 2 keys: 'iv' containing the initialization
	 *  vector, and 'key' which contains the secret key.
	 */
	protected function getKeyOld(){
		if ( !isset($this->key) ){
			
			
			$key = null;
			
			if ( $this->APC ){
				if ( $this->DEBUG_ON ) $this->debug("Trying to load key from APC");
				$buf = apc_fetch($this->KEY_PATH);
				if ( $buf ) {
					$key = unserialize($buf);
					if ( $this->DEBUG_ON ) $this->debug("Key loaded from APC");
				}
			} else {
			//if ( !$key ){
				if ( file_exists($this->KEY_PATH) ){
					if ( $this->DEBUG_ON ) $this->debug("The Key File Exists");
					$buf = file($this->KEY_PATH);
					if ( count($buf) >= 3){
						if ( $this->DEBUG_ON ) $this->debug("The key file appears to have the correct format.");
						$key = array(
							'iv' => $buf[1],
							'key' => $buf[2]
						);
					} else if ( $this->DEBUG_ON ){
						$this->debug("The key file doesn't appear to have the correct format.  Wrong number of lines.");
					}
					
				}
			}
			
			if ( !$key ){
				if ( $this->DEBUG_ON ){
					$this->debug("The Key file is currently empty.  Trying to generate a new key.");
				}
				$iv_size = mcrypt_get_iv_size(MCRYPT_RIJNDAEL_256, MCRYPT_MODE_ECB);
				$iv = mcrypt_create_iv($iv_size, MCRYPT_RAND);
				$key = array(
					'iv'=>$iv,
					'key'=>$this->KEY
				);
				if ( $this->APC ){
					apc_store($this->KEY_PATH, serialize($key));
				} else {
					file_put_contents($this->KEY_PATH, "<"."?php exit;?".">\n$iv\n".$this->KEY."\n", LOCK_EX);
				}
				
			
			} else if ( $this->DEBUG_ON ) {
				$this->debug("The key file was not empty.");
			}
			
			$this->key = $key;
		}
		
		return $this->key;
	}
	
	
	
	public function getCookie(){
		if ( $cookie = @$_COOKIE['XATAFACE_ENV'] ){
			if ( $this->DEBUG_ON ){
				$this->debug("The Environment Cookie is set: ".$cookie);
			}
			return $cookie;
			//$key = $this->getKey();
			//$contents = trim(mcrypt_decrypt(MCRYPT_RIJNDAEL_256, $key['key'], $cookie, MCRYPT_MODE_ECB, $key['iv']));
			
			//if ( $this->DEBUG_ON ){
			//	$this->debug("The unencrypted cookie is [".$contents."]");
			//}
			//return $contents;
		} else {
			if ( $this->DEBUG_ON ){
				$this->debug("The browser did not contain a cookie");
			}
			return null;
		}
	}
	
	public function setCookie($content, $path){
		if ( !$path ) $path = '/';
		if ( $path{strlen($path)-1} != '/' ) $path .= '/';
		if ( $this->DEBUG_ON ) $this->debug("Setting cookie: ".$content." for path ".$path);
		$key = $this->getKey();
		// We need to determine whether this is a secure environment or a public one
		
		$content = $this->compileEnv($content);
		
		//$content = mcrypt_encrypt(MCRYPT_RIJNDAEL_256, $key['key'], $content, MCRYPT_MODE_ECB, $key['iv']);
		if ( $this->DEBUG_ON ) $this->debug("Encrypted cookie being set: ".$content);
		setcookie('XATAFACE_ENV', $content, null, $path);
	}
	
	public function getEnvironmentString(){
		$out = $this->getCookie();
		if ( !$out ) $out = $this->DEFAULT_ENV;
		return $out;
	}
	
	public function getEnvironmentId(){
		return sha1($this->getEnvironmentString());
	}
	
	public function getPageId(){
		return sha1($_SERVER['REQUEST_URI']);
	}
	
	
	public function rmdir($dir, $writer=null){
		$dh = opendir($dir);
		$i = 0;
		if ( !$dh ) return 0;
		while ( ( $file = readdir($dh)) !== false ){
			if ( $file === '.' or $file === '..' ) continue;
			$file = $dir.DIRECTORY_SEPARATOR.$file;
			if ( is_file($file) ){
				unlink($file);

			} else if ( is_dir($file) ){
				$i += $this->rmdir($file, $writer);
			}
			if ( !(++$i % 1000) ){
				if ( isset($writer) and is_callable($writer) ) call_user_func($writer("$i files removed in $dir"));
			}
			
		}
		closedir($dh);
	
	}
	
	
	public function clearCache($writer=null){
		$dh = opendir($this->CACHE_PATH);
		if ( !$dh ) return 0;
		while ( ( $file = readdir($dh)) !== false ){
			if ( $file === '.' or $file === '..' ) continue;
			$file = $this->CACHE_PATH.DIRECTORY_SEPARATOR.$file;
			if ( is_dir($file) ){
				//echo "Removing $file";exit;
				$this->rmdir($file);
			}
		}
		if ( isset($writer) and is_callable($writer) ) call_user_func($writer, '\nThe cache has been cleared');
	}
	
	public function checkCache(){
		
		$s = DIRECTORY_SEPARATOR;
		//echo "Env: ".$this->getEnvironmentString();exit;
		$fpath = $this->CACHE_PATH.$s.$this->getEnvironmentId().$s.$this->getPageId();
		$infoPath = $fpath.'.info';
		$headersPath = $fpath.'.headers';
		if ( $this->DEBUG_ON ){
			$this->debug("Info path is: ".$infoPath);
			$this->debug("Headers path is :".$headersPath);
			$this->debug("Content Path is : ".$fpath);
			$this->debug("Info path readable? :".$this->fileExists($infoPath));
			$this->debug("Info path mtime: ".$this->mtime($infoPath));
			$this->debug("Info path minimum good time: ".(time()-$this->EXPIRES));
		}	
		//echo "$infoPath<br>";
		
		if ( $this->fileExists($infoPath) and ((($mtime = $this->mtime($infoPath)) > time()-$this->EXPIRES) or !$this->EXPIRES) ){
			
			// Well it looks ok..
			if ( $this->DEBUG_ON ){
				$this->debug("Infopath is readable and appears ok.. checking table mtimes");
			}
			
			// Let's check the table index
			$info = unserialize($this->readFile($infoPath));
			if ( @$info['tables'] ){
				
				if ( $this->DEBUG_ON ){
					$this->debug("Checking tables [".implode(',', $info['tables'])."]");
				}
				// load the table modification times from the index
				$this->getMtimeIndex();
				foreach ( $info['tables'] as $t){
					if ( @$this->mtimeIndex[$t] and $this->mtimeIndex[$t] > $mtime ){
						// The table has been modified since this cache was saved
						if ( $this->DEBUG_ON ){
							$this->debug("Table '$t' has been modified");
						}
							
						return false;
					} else if ( $this->DEBUG_ON ){
						$this->debug("Table '$t' has not been modified.  mtime is [".date('Y-m-d H:i:s',$this->mtimeIndex[$t])."] but info mtime is [".date('Y-m-d H:i:s',$mtime)."]");
						
					}
				}
			} else {
				if ( $this->DEBUG_ON ){
					$this->debug("No dependent tables found.");
				}
			}
			
			
			$now = time();
			$this->cachedHeaders = unserialize($this->readFile($headersPath));
			if ( $this->RESPECT_HTTP_CACHE_CONTROL_HEADERS ){
				$cacheControlHeaders = preg_grep('/^Cache-control:/i', $this->cachedHeaders);
				
				foreach ($cacheControlHeaders as $h){
					if ( preg_match('/no-cache|no-store/', $h) ){
						if ( $this->DEBUG_ON ) $this->debug('Not using cached version because the no-cache or no-store header was set');
						return false;
					}
				
					if ( preg_match('/max-age=(\d+)/', $h, $matches) ){
						$maxAge = intval($matches[1]);
						$expires = $maxAge+$mtime;
						
						if ( $expires < $now ){
							if ( $this->DEBUG_ON ) $this->debug('Not using cached version because max-age header expires at '.$expires.' but now is '.$now);
							return false;
						} else {
							if ( $this->DEBUG_ON ) $this->debug('Cache-control s-maxage is '.$maxAge.' expires '.$expires.' which is greater than now: .'.$now);
						}
					}
					
					if ( preg_match('/s-maxage=(\d+)/', $h, $matches) ){
						$maxAge = intval($matches[1]);
						$expires = $maxAge+$mtime;
						
						if ( $expires < $now ){
							if ( $this->DEBUG_ON ) $this->debug('Not using cached version because s-maxage header expires at '.$expires.' but now is '.$now);
							return false;
						} else {
							if ( $this->DEBUG_ON ) $this->debug('Cache-control s-maxage is '.$maxAge.' expires '.$expires.' which is greater than now: .'.$now);
						}
					}
					
					
				}
			}
			if ( $this->RESPECT_HTTP_EXPIRES_HEADERS ){
				$expiresHeaders = preg_grep('/^Expires:/i', $this->cachedHeaders);
				foreach ($expiresHeaders as $h){
					if ( preg_match('/^Expires:(.*)$/', $h, $matches)){
						$expires = strtotime(trim($matches[1]));
						if ( $expires < $now ){
							if ( $this->DEBUG_ON ) $this->debug('Not using cached version because Expires header expires at '.$expires.' but now is '.$now);
							return false;
						}
					}
				}
			}
			
			if ( $this->DEBUG_ON ) $this->debug("Using cached version for ".$_SERVER['REQUEST_URI']);
			$this->cachedContentPath = $fpath;
			if ( !$this->MEMCACHE ) $this->cachedContentSize = filesize($fpath);
			return true;
			
		
		}
		if ( $this->DEBUG_ON ) $this->debug("Not using cached version.");
		return false;
	}
	
	private function writeFile($path, $contents){
		if ( $this->MEMCACHE ){
			$this->MEMCACHE->set($path, $contents);
			$this->MEMCACHE->set($path.'.mtime', time());
		} else {
			file_put_contents($path, $contents, LOCK_EX);
		}
	}
	
	private function readFile($path){
		if ( $this->MEMCACHE ){
			return $this->MEMCACHE->get($path);
		} else {
			return file_get_contents($path);
		}
	}
	
	private function mtime($path){
		if ( $this->MEMCACHE ){
			return $this->MEMCACHE->get($path.'.mtime');
		} else {
			return @filemtime($path);
		}
	}
	
	private function fileExists($path){
	
		if ( $this->MEMCACHE ){
			return $this->MEMCACHE->append($path, null);
		} else {
			return file_exists($path);
		}
	}
	
	public function writeCache($environmentId, $pageId, $content, $info=array()){
	
		$s = DIRECTORY_SEPARATOR;
		$fpath = $this->CACHE_PATH.$s.basename($environmentId).$s.basename($pageId);
		if ( $this->DEBUG_ON ) $this->debug("Writing $fpath for ".$_SERVER['REQUEST_URI']);
		if ( !$this->MEMCACHE ){
			if ( !is_dir(dirname($fpath)) ) mkdir(dirname($fpath), 0777);
		}
		$infoPath = $fpath.'.info';
		$headersPath = $fpath.'.headers';
		
		
		$headers = preg_grep('/^(Content|Location|ETag|Last|Server|Vary|Expires|Allow|Cache|Pragma)/i', headers_list());
		$now = time();
		if ( $this->RESPECT_HTTP_EXPIRES_HEADERS ){
			$expiresHeaders = preg_grep('/^Expires:/i', $headers);
			foreach ($expiresHeaders as $h){
				if ( preg_match('/^Expires:(.+)$/i', $h, $matches) ){
					$expires = strtotime(trim($matches[1]));
					if ( $expires <= $now ){
						if ( $this->DEBUG_ON ) $this->debug('Not writing '.$fpath.' because expires header is '.$matches[1].' which is present or in the past.');
						return;
					} else {
						if ( $this->DEBUG_ON ) $this->debug('Expires header $h has not expired yet.');
					}
				}
			}
		}
		
		if ( $this->RESPECT_HTTP_CACHE_CONTROL_HEADERS ){
			$expiresHeaders = preg_grep('/^Cache-control:/i', $headers);
			foreach ($expiresHeaders as $h){
				if ( preg_match('/max-age=(\d+)$/i', $h, $matches) ){
					$maxage = intval(trim($matches[1]));
					if ( $maxage <= 0 ){
						if ( $this->DEBUG_ON ) $this->debug('Not writing '.$fpath.' because max-age is set to zero.');
						return;
					} else {
						if ( $this->DEBUG_ON ) $this->debug('Cache-control max-age is '.$maxage.' which is greater than 0.');
						
					}
				}
				if ( preg_match('/s-maxage=(\d+)$/i', $h, $matches) ){
					$maxage = intval(trim($matches[1]));
					if ( $maxage <= 0 ){
						if ( $this->DEBUG_ON ) $this->debug('Not writing '.$fpath.' because s-maxage is set to zero.');
						return;
					} else {
						if ( $this->DEBUG_ON ) $this->debug('Cache-control s-maxage is '.$maxage.' which is greater than 0.');
					}
				}
			}
		}
		
		
		
		//file_put_contents($headersPath, serialize($headers), LOCK_EX);
		$this->writeFile($headersPath, serialize($headers));
		
		
		//file_put_contents($fpath, $content, LOCK_EX);
		$this->writeFile($fpath, $content);
		
		//file_put_contents($infoPath, serialize($info), LOCK_EX);
		$this->writeFile($infoPath, serialize($info));
		
		
	}
	
	
	public function outputHeaders(){
		
		foreach ( $this->cachedHeaders as $h){
			header($h);
		}
	}
	
	
	public function outputContent(){
		if ( $this->fileExists($this->cachedContentPath) ){
			
			
			if ( $this->MEMCACHE ){
				if ( $this->DEBUG_ON ) $this->debug("Using Memcache cache... output cached version of ".$_SERVER['REQUEST_URI']);
				$content = $this->readFile($this->cachedContentPath);
				header('Content-Length: '.strlen($content));
				header('Connection: close');
				echo $content;
			} else {
				if ( $this->DEBUG_ON ){
					$this->debug("Outputing cache from filesystem for ".$_SERVER['REQUEST_URI']);
				}
				header('Content-Length: '.$this->cachedContentSize);
				header('Connection: close');
				//header('Cache-Control: max-age=3600');
				$fp = fopen($this->cachedContentPath,'r');
				
				if ( !$fp ) throw new Exception("Failed to open file for writing");
				//fpassthru($fp);
				//echo file_get_contents($this->cachedContentPath);
				$bytesSent = 0;
				while(!feof($fp)) {
					$buf = fread($fp, 4096);
					echo $buf;
				   $bytesSent+=strlen($buf);    /* We know how many bytes were sent to the user */
				}
				//fpassthru($fh);
				fclose($fp);
			}
			
			
			
			exit;
			//echo $contents;exit;
		} else {
			throw new Exception("No content found to write");
		}
	}
	
	private function compileEnv($content){
		if ( !preg_match('/^\+/', $content)  ){
			$key = $this->getKey();
			//$content = crypt($content.$_SERVER['REMOTE_ADDR'], $key['key']);
			// Changed to SHA1 because crypt by default only works on first 8 characters..
			$content = sha1($content.$_SERVER['REMOTE_ADDR'].$key['key']);
		} else {
			$content = sha1($content);
		}
		return $content;
		
	}
	
	
	public function start(){
	
		// We don't handle POST requests
		if ( @$_GET['-action'] == 'js' or @$_GET['-action'] == 'css' ) return;
		
		$envId = $this->getEnvironmentId();
		$pageId = $this->getPageId();
		
		if ( $this->isEnabled() and $this->checkCache($envId, $pageId) ){
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
		
		ob_start(array($this, 'flushBuffer') );
	
	}
	
	public function flushBuffer($buffer){
	
		if ( class_exists('Dataface_Application') ){
			
			$app = Dataface_Application::getInstance();
			if ( @$app->_conf['nocache'] ) return $buffer;
			$del = $app->getDelegate();
			$env = $this->getEnvironmentString();
			$user = '';
			$anonymous = false;
			if ( class_exists('Dataface_AuthenticationTool') ){
				$auth = Dataface_AuthenticationTool::getInstance();
				$user = $auth->getLoggedInUserName();
				if ( $this->DEBUG_ON ) $this->debug("LoggedInUserName is '".$user."'");
				
			}
			
			if ( !$user ) $anonymous = true;
			if ( method_exists($del, 'getOutputCacheUserId') ){
				$nuser = $del->getOutputCacheUserId();
				if ( $nuser ) $user = $nuser;
				if ( $this->DEBUG_ON ) $this->debug("Custom Cache User ID: '".$user."'");
			}
			
			$env = 'user='.urlencode($user).'&lang='.urlencode($app->_conf['lang']);
			if ( $anonymous ) $env = '+'.$env;
			else $env = '-'.$env;
			if ( $this->DEBUG_ON ) $this->debug("The Environment is now $env");
			$this->setCookie($env, $this->COOKIE_PATH);
			
			
			// Now let's write the table modification times
			if ( class_exists('Dataface_Table') ){
				$mtimes = Dataface_Table::getTableModificationTimes(true);
				if ( $this->APC ){
					if ( $this->DEBUG_ON ){
						$this->debug("Saving mtime index with APC");
					}
					apc_store($this->MTIME_INDEX_PATH, serialize($mtimes));
				} else {
					if ( $this->DEBUG_ON ){
						$this->debug("Saving mtime index to file");
					}
					file_put_contents($this->MTIME_INDEX_PATH, serialize($mtimes), LOCK_EX);
				}
				
				
			}
			if ( $this->isEnabled() and !@$app->_conf['nocache'] ){
				if ( $this->DEBUG_ON ) $this->debug("Caching for action is enabled... cachine now");
				// Now let's write the headers
				if ( $this->RESPECT_TABLE_MODIFICATION_TIMES ){
					$info = array(
						'tables' => $app->tableNamesUsed
					);
				} else {
					$info = array('tables' => array());
				}
				if ( $this->DEBUG_ON ) $this->debug("Environment writing:".sha1($this->compileEnv($env)));
				$this->writeCache(sha1($this->compileEnv($env)), $this->getPageId(), $buffer, $info);
			} else {
				if ( $this->DEBUG_ON ) $this->debug("Caching for this action is disabled.");
			}
				
			return $buffer;
			
			
		}
	}
	
	
	

}