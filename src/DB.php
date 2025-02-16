<?php

namespace Hjsleijster\Literiser;

class DB
{
	private static $conn; // db connection
	private static $sqlError; // db connection

	private static function connect() {
		$dbConfig = Base::getConfig('db');
		self::$conn = mysqli_connect('localhost', $dbConfig['username'], $dbConfig['password'], $dbConfig['database']);
	}

	public static function q($query) {
		if (! self::$conn) {
			self::connect();
		}

		try {
			$result = mysqli_query(self::$conn, $query);
		} catch (Exception $e) {
			$error = str_replace('; check the manual that corresponds to your MariaDB server version for the right syntax to use', '', mysqli_error(self::$conn));
			self::$sqlError = $error;
			return false;
		}

		return is_bool($result) ? $result : mysqli_fetch_all($result, MYSQLI_ASSOC);
	}

	public static function val($query) {
		$result = self::q($query);
		if ($result) {
			return reset($result[0]);
		}
	}

	public static function row($query) {
		$result = self::q($query);
		if ($result) {
			return $result[0];
		}
	}

	public static function rows($query) {
		return self::q($query);
	}
}
