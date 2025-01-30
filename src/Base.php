<?php

namespace Literiser;

class Base
{
	private static $uri = ''; // exploded request uri
	private static $request; // get or post
	private static $module; // module to be loaded
	private static $moduleObject; // object of module
	private static $title = ''; // title of the website
	private static $config = []; // merged default en environment config
	private static $entrypoint; // web, xhr, cli

	public static function boot() {
		spl_autoload_register('Base::autoload');
		self::errorReporting();
		self::init();
		self::baseConfig();

		if (empty(self::$config['default_route'])) {
			throw new Exception('Default route error', 1);
		}

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
			} else {
				self::$entrypoint = 'web';
				$r = self::web();
			}
		}

		echo $r;
		exit;
	}

	public static function autoload($class) {
		$class = ucfirst($class);
		$file = 'classes/' . $class . '.php';

		if (file_exists($file)) {
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
			print_r($e);
		});
	}

	private static function baseConfig() {
		self::$config = array_merge(
			parse_ini_file('config.ini', true)
			, parse_ini_file('env.ini', true)
			, self::$config
		);
	}

	public static function config($var, $value) {
		self::$config[$var] = $value;
	}

	private static function init() {
		setlocale(LC_ALL, 'nl_NL.UTF-8');
		date_default_timezone_set('Europe/Amsterdam');
		chdir(dirname($_SERVER['SCRIPT_FILENAME']));
	}

	private static function cli() {
		$action = $GLOBALS['argv'][1] ?? '';
		if (!$action) {
			return false;
		}
		list($module, $action) = explode(':', $action);
		self::initModule($module);
		
		$method = [self::$moduleObject, 'cli'];
		$args = array_slice($GLOBALS['argv'], 2);

		return call_user_func($method, $action, $args);
	}

	private static function xhr() {
		$module = self::$uri[0];
		self::initModule($module);

		$method = [self::$moduleObject, 'xhr'];
		$action = self::$uri[1];
		$args = array_slice(self::$uri, 2);

		$r = call_user_func($method, $action, $args);

		if (isset($r) && is_array($r)) {
			header('Content-Type: application/json');
			return json_encode($r);
		} else {
			return $r;
		}
	}

	private static function web() {
		$module = self::$uri[0] ?? self::$config['default_route'];

		if (self::initModule($module)) {
			$r = self::$moduleObject->web();
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

		self::$moduleObject->baseConfig(self::$config[self::$module]);
		self::$moduleObject->setUri(self::$uri);
		if (is_array(self::$request)) {
			self::$moduleObject->setRequest(self::$request);
		}
		self::$moduleObject->init();

		return true;
	}

	private static function throw404() {
		header("HTTP/1.0 404 Not Found");

		return '<h1>404!</h1>';
	}

	private static function webOutput($content) {
		$r = '<!DOCTYPE html><html><head>
		<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
		<script src="/main.js?' . filemtime('main.js'). '"></script>
		<link rel="stylesheet" type="text/css" href="/style.css?' . filemtime('style.css'). '">
		<link rel="icon" href="/favicon.png?' . filemtime('favicon.png'). '" type="image/png" sizes="512x512" />
		<title>' . self::$title . '</title>
		</head><body>%content%</body></html>';

		$r = str_replace(["\n", "\t"], '', $r);

		$r = str_replace('%content%', $content, $r);

		return $r;
	}

	public static function setTitle($title) {
		self::$title = $title;
	}
}
