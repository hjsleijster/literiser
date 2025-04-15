<?php

namespace Hjsleijster\Literiser;

class Base
{
	private static $uri = ''; // exploded request uri
	private static $request; // get or post
	private static $module; // module to be loaded
	private static $moduleObject; // object of module
	private static $title = ''; // title of the website
	private static $config = []; // merged default en environment config
	private static $entrypoint; // cli, xhr, api, web
	private static $webAssets = []; // js & css files
	private static $headTags = []; // head tags

	public static function boot() {
		spl_autoload_register(__NAMESPACE__ . '\Base::autoload');
		class_alias(__NAMESPACE__. '\Base', 'Literiser');
		class_alias(__NAMESPACE__. '\Module', 'LiteriserModule');
		class_alias(__NAMESPACE__. '\DB', 'LiteriserDB');

		self::errorReporting();
		self::init();
		self::baseConfig();

		if (PHP_SAPI == 'cli') {
			self::$entrypoint = 'cli';
			$r = self::cli();
		} else {
			$uri = $_SERVER['PATH_INFO'] ?? str_replace('?' . ($_SERVER['QUERY_STRING'] ?? ''), '', $_SERVER['REQUEST_URI']);
			self::$uri = array_filter(explode('/', ltrim($uri, '/')));
			self::$request = $_REQUEST;

			if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
				self::$entrypoint = 'xhr';
				$r = self::xhr();
			} elseif (self::$uri[0] == 'api') {
				self::$entrypoint = 'api';
				$r = self::api();
			} else {
				self::$entrypoint = 'web';
				$r = self::web();
			}
		}

		echo $r;
		exit;
	}

	public static function autoload($class) {
		$dir = 'modules';
		$contents = glob($dir . '/*');
		$file = current(preg_grep('/^' . $dir . '\/' . preg_quote($class) . '.php$/i', $contents));

		if ($file) {
			require_once($file);
		}
	}

	public static function errorReporting() {
		ini_set('display_errors', true);
		ini_set('display_startup_errors', true);

		set_error_handler(function(...$error) {
			if (self::$entrypoint != 'cli') {
				header('Content-Type: text/plain');
			}
			echo 'File: ' . $error[2] . ':' . $error[3] . PHP_EOL . 'Error: ' . $error[1] . PHP_EOL . PHP_EOL;
		});

		set_exception_handler(function($e) {
			if (self::$entrypoint != 'cli') {
				header('Content-Type: text/plain');
			}

			if (is_a($e, 'mysqli_sql_exception')) {
				$error = str_replace('; check the manual that corresponds to your MariaDB server version for the right syntax to use', '', mysqli_error(DB::getConnection()));
				foreach ($e->getTrace() as $trace) {
					if (basename($trace['file']) != 'DB.php') {
						$errorLocation = $trace;
						break;
					}
				}
				echo $error . PHP_EOL . $errorLocation['file'] . ' (line ' . $errorLocation['line'] . ')';
			} else {
				print_r($e);
			}
		});
	}

	private static function baseConfig() {
		self::$config = parse_ini_file('literiser.ini', true);
		self::$config['base_module'] ??= basename(getcwd());

		if (is_file('literiser-env.ini')) {
			self::$config = array_merge(self::$config, parse_ini_file('literiser-env.ini', true));
		}
	}

	public static function config($var, $value) {
		self::$config[$var] = $value;
	}

	public static function getConfig($section, $var = null) {
		if (is_null($section) && !is_null($var)) {
			return self::$config[$var];
		} elseif (!is_null($var)) {
			return self::$config[$section][$var];
		} else {
			return self::$config[$section];
		}
	}

	private static function init() {
		setlocale(LC_ALL, 'nl_NL.UTF-8');
		date_default_timezone_set('Europe/Amsterdam');
		chdir(dirname($_SERVER['SCRIPT_FILENAME']));
	}

	private static function cli() {
		$action = $GLOBALS['argv'][1] ?? '';
		$args = array_slice($GLOBALS['argv'], 2);
		if (!$action) {
			return false;
		}

		if (str_contains($action, ':')) {
			list($module, $action) = explode(':', $action);
		} else {
			return call_user_func([__CLASS__, 'cli_' . $action], ...$args);
		}

		self::initModule($module);
		
		$method = [self::$moduleObject, 'cli'];

		return call_user_func($method, $action, ...$args);
	}

	private static function cli_dbUpgrade() {
		chdir('db');
		if (!is_file('version')) {
			touch('version');
		}
		$currentVersion = file_get_contents('version') ?: 0;

		$files = glob('*sql');
		natsort($files);
		foreach ($files as $file) {
			$version = str_replace(['db', '.sql'], '', $file);
			if ($version <= $currentVersion) {
				continue;
			}
			$queries = file_get_contents($file);
			$result = DB::multiquery($queries);

			if ($result === false) {
				return 'error: ' . $version . PHP_EOL;
			} else {
				file_put_contents('version', $version);
				echo 'success: ' . $version . PHP_EOL;
			}
		}

		if (empty($result)) {
			return 'nothing to do' . PHP_EOL;
		}
	}

	private static function xhr() {
		$module = self::$uri[0];
		self::initModule($module);

		$method = [self::$moduleObject, 'xhr'];
		$action = self::$uri[1];

		$r = call_user_func($method, $action);

		if (isset($r) && is_array($r)) {
			header('Content-Type: application/json');
			return json_encode($r);
		} else {
			return $r;
		}
	}

	private static function api() {
		$module = 'api';

		if (self::initModule($module)) {
			$r = self::$moduleObject->getResponse();
		} else {
			$r = self::throw404();
		}

		if (isset($r) && is_array($r)) {
			header('Content-Type: application/json');
			return json_encode($r);
		} else {
			return $r;
		}
	}

	private static function web() {
		$module = self::$uri[0] ?? self::$config['base_module'];

		self::addAsset('assets/main.js');
		self::addAsset('assets/main.css');

		if (self::initModule($module) && method_exists(self::$moduleObject, 'web')) {
			$r = self::$moduleObject->web();
			self::addAsset('assets/' . $module . '.css');
			self::addAsset('assets/' . $module . '.js');
		} else {
			$r = self::throw404();
		}

		return self::webOutput($r);
	}

	private static function initModule($module) {
		if (!class_exists($module)) {
			return false;
		}

		self::$module = $module;
		self::$moduleObject = (new self::$module)->register();

		if (!empty(self::$config[self::$module])) {
			self::$moduleObject->baseConfig(self::$config[self::$module]);
		}
		self::$moduleObject->setUri(self::$uri);
		if (is_array(self::$request)) {
			self::$moduleObject->setRequest(self::$request);
		}
		self::$moduleObject->init();

		return true;
	}

	private static function throw404() {
		header("HTTP/1.0 404 Not Found");

		if (self::$entrypoint == 'web') {
			return '<h1>404</h1>';
		} else {
			return json_encode('404');
		}
	}

	public static function addAsset($asset) {
		self::$webAssets[] = $asset;
	}

	public static function addHeadTag($headTag) {
		self::$headTags[] = $headTag;
	}

	private static function webOutput($content) {
		$r = '<!DOCTYPE html><html><head>';

		if (!empty(self::$config['jquery'])) {
			$r .= '<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>';
		}

		$r.= implode('', self::$headTags);

		foreach (self::$webAssets as $asset) {
			if (!is_file($asset)) {
				continue;
			}

			if (str_contains($asset, '.js')) {
				$r .= '<script src="/' . $asset . '?' . filemtime($asset). '"></script>';
			} elseif (str_contains($asset, '.css')) {
				$r .= '<link rel="stylesheet" type="text/css" href="/' . $asset . '?' . filemtime($asset). '">';
			}
		}

		if (is_file('assets/favicon.png')) {
			$r .= '<link rel="icon" href="/assets/favicon.png?' . filemtime('assets/favicon.png'). '" type="image/png" sizes="512x512" />';
		}

		$r .= '<title>' . self::$title . '</title>';
		$r .= '</head><body>%content%</body></html>';

		$r = str_replace('%content%', $content, $r);

		return $r;
	}

	public static function setTitle($title) {
		self::$title = $title;
	}
}
