<?php

namespace Hjsleijster\Literiser;

class DB
{
	private static $conn; // db connection

	private static function connect() {
		$dbConfig = Base::getConfig('db');
		self::$conn = mysqli_connect('localhost', $dbConfig['username'], $dbConfig['password'], $dbConfig['database']);
	}

	public static function getConnection() {
		return self::$conn;
	}

	public static function q($query) {
		if (! self::$conn) {
			self::connect();
		}

		$result = mysqli_query(self::$conn, $query);

		if (is_bool($result)) {
			if (str_starts_with($query, 'INSERT')) {
				$insert_id = mysqli_insert_id(self::$conn);
				return $insert_id;
			}

			return $result;
		} else {
			return mysqli_fetch_all($result, MYSQLI_ASSOC);
		}
	}

	public static function multiquery($queries) {
		if (! self::$conn) {
			self::connect();
		}

		$result = mysqli_multi_query(self::$conn, $queries);
		while (mysqli_next_result(self::$conn)) {}

		return $result;
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
