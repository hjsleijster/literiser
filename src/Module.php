<?php

namespace Hjsleijster\Literiser;

trait Module
{
	private $config;
	private $uri = [];
	private $request = [];

	public function register() {
		return $this;
	}

	public function baseConfig($config) {
		$this->config = $config;
	}

	public function setUri($uri) {
		$this->uri = $uri;
	}

	public function setRequest($request) {
		$this->request = $request;
	}

	public function init() {
	}

	public function cli($action, $args = []) {
		$method = 'cli_' . $action;
		return call_user_func([$this, $method], $args);
	}

	public function xhr($action) {
		$method = 'xhr_' . $action;
		return call_user_func([$this, $method]);
	}
}
