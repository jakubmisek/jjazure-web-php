<?php

/**
 * This file is part of the Nette Framework (https://nette.org)
 * Copyright (c) 2004 David Grudl (https://davidgrudl.com)
 */

declare(strict_types=1);

namespace Nette;

use Nette;
use Nette\DI;
use Tracy;


/**
 * Initial system DI container generator.
 */
class Configurator
{
	use SmartObject;

	public const COOKIE_SECRET = 'nette-debug';

	/** @var callable[]  function (Configurator $sender, DI\Compiler $compiler); Occurs after the compiler is created */
	public $onCompile;

	/** @var array */
	public $defaultExtensions = [
		'php' => Nette\DI\Extensions\PhpExtension::class,
		'constants' => Nette\DI\Extensions\ConstantsExtension::class,
		'extensions' => Nette\DI\Extensions\ExtensionsExtension::class,
		'application' => [Nette\Bridges\ApplicationDI\ApplicationExtension::class, ['%debugMode%', ['%appDir%'], '%tempDir%/cache']],
		'decorator' => Nette\DI\Extensions\DecoratorExtension::class,
		'cache' => [Nette\Bridges\CacheDI\CacheExtension::class, ['%tempDir%']],
		'database' => [Nette\Bridges\DatabaseDI\DatabaseExtension::class, ['%debugMode%']],
		'di' => [Nette\DI\Extensions\DIExtension::class, ['%debugMode%']],
		'forms' => Nette\Bridges\FormsDI\FormsExtension::class,
		'http' => [Nette\Bridges\HttpDI\HttpExtension::class, ['%consoleMode%']],
		'latte' => [Nette\Bridges\ApplicationDI\LatteExtension::class, ['%tempDir%/cache/latte', '%debugMode%']],
		'mail' => Nette\Bridges\MailDI\MailExtension::class,
		'routing' => [Nette\Bridges\ApplicationDI\RoutingExtension::class, ['%debugMode%']],
		'security' => [Nette\Bridges\SecurityDI\SecurityExtension::class, ['%debugMode%']],
		'session' => [Nette\Bridges\HttpDI\SessionExtension::class, ['%debugMode%', '%consoleMode%']],
		'tracy' => [Tracy\Bridges\Nette\TracyExtension::class, ['%debugMode%', '%consoleMode%']],
		'inject' => Nette\DI\Extensions\InjectExtension::class,
	];

	/** @var string[] of classes which shouldn't be autowired */
	public $autowireExcludedClasses = [
		'stdClass',
	];

	/** @var array */
	protected $parameters;

	/** @var array */
	protected $dynamicParameters = [];

	/** @var array */
	protected $services = [];

	/** @var array of string|array */
	protected $configs = [];


	public function __construct()
	{
		$this->parameters = $this->getDefaultParameters();
	}


	/**
	 * Set parameter %debugMode%.
	 * @param  bool|string|array  $value
	 * @return static
	 */
	public function setDebugMode($value)
	{
		if (is_string($value) || is_array($value)) {
			$value = static::detectDebugMode($value);
		} elseif (!is_bool($value)) {
			throw new Nette\InvalidArgumentException(sprintf('Value must be either a string, array, or boolean, %s given.', gettype($value)));
		}
		$this->parameters['debugMode'] = $value;
		$this->parameters['productionMode'] = !$this->parameters['debugMode']; // compatibility
		return $this;
	}


	public function isDebugMode(): bool
	{
		return $this->parameters['debugMode'];
	}


	/**
	 * Sets path to temporary directory.
	 * @return static
	 */
	public function setTempDirectory(string $path)
	{
		$this->parameters['tempDir'] = $path;
		return $this;
	}


	/**
	 * Sets the default timezone.
	 * @return static
	 */
	public function setTimeZone(string $timezone)
	{
		date_default_timezone_set($timezone);
		@ini_set('date.timezone', $timezone); // @ - function may be disabled
		return $this;
	}


	/**
	 * Adds new parameters. The %params% will be expanded.
	 * @return static
	 */
	public function addParameters(array $params)
	{
		$this->parameters = DI\Config\Helpers::merge($params, $this->parameters);
		return $this;
	}


	/**
	 * Adds new dynamic parameters.
	 * @return static
	 */
	public function addDynamicParameters(array $params)
	{
		$this->dynamicParameters = $params + $this->dynamicParameters;
		return $this;
	}


	/**
	 * Add instances of services.
	 * @return static
	 */
	public function addServices(array $services)
	{
		$this->services = $services + $this->services;
		return $this;
	}


	protected function getDefaultParameters(): array
	{
		$trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
		$last = end($trace);
		$debugMode = static::detectDebugMode();
		return [
			'appDir' => isset($trace[1]['file']) ? dirname($trace[1]['file']) : null,
			'wwwDir' => isset($last['file']) ? dirname($last['file']) : null,
			'debugMode' => $debugMode,
			'productionMode' => !$debugMode,
			'consoleMode' => PHP_SAPI === 'cli',
		];
	}


	public function enableTracy(string $logDirectory = null, string $email = null): void
	{
		Tracy\Debugger::$strictMode = true;
		Tracy\Debugger::enable(!$this->parameters['debugMode'], $logDirectory, $email);
		Tracy\Bridges\Nette\Bridge::initialize();
	}


	/**
	 * Alias for enableTracy()
	 */
	public function enableDebugger(string $logDirectory = null, string $email = null): void
	{
		$this->enableTracy($logDirectory, $email);
	}


	/**
	 * @throws Nette\NotSupportedException if RobotLoader is not available
	 */
	public function createRobotLoader(): Nette\Loaders\RobotLoader
	{
		if (!class_exists(Nette\Loaders\RobotLoader::class)) {
			throw new Nette\NotSupportedException('RobotLoader not found, do you have `nette/robot-loader` package installed?');
		}

		$loader = new Nette\Loaders\RobotLoader;
		$loader->setTempDirectory($this->getCacheDirectory() . '/Nette.RobotLoader');
		$loader->setAutoRefresh($this->parameters['debugMode']);
		return $loader;
	}


	/**
	 * Adds configuration file.
	 * @param  string|array  $config
	 * @return static
	 */
	public function addConfig($config)
	{
		$this->configs[] = $config;
		return $this;
	}


	/**
	 * Returns system DI container.
	 */
	public function createContainer(): DI\Container
	{
		$class = $this->loadContainer();
		$container = new $class($this->dynamicParameters);
		foreach ($this->services as $name => $service) {
			$container->addService($name, $service);
		}
		$container->initialize();
		return $container;
	}


	/**
	 * Loads system DI container class and returns its name.
	 */
	public function loadContainer(): string
	{
		$loader = new DI\ContainerLoader(
			$this->getCacheDirectory() . '/Nette.Configurator',
			$this->parameters['debugMode']
		);
		$class = $loader->load(
			[$this, 'generateContainer'],
			[$this->parameters, array_keys($this->dynamicParameters), $this->configs, PHP_VERSION_ID - PHP_RELEASE_VERSION]
		);
		return $class;
	}


	/**
	 * @internal
	 */
	public function generateContainer(DI\Compiler $compiler): string
	{
		$compiler->addConfig(['parameters' => $this->parameters]);
		$compiler->setDynamicParameterNames(array_keys($this->dynamicParameters));

		$loader = $this->createLoader();
		$sources = '';
		foreach ($this->configs as $config) {
			if (is_string($config)) {
				$sources .= "// source: $config\n";
				$compiler->loadConfig($config, $loader);
			} else {
				$sources .= "// source: array\n";
				$compiler->addConfig($config);
			}
		}

		$builder = $compiler->getContainerBuilder();
		$builder->addExcludedClasses($this->autowireExcludedClasses);

		foreach ($this->defaultExtensions as $name => $extension) {
			[$class, $args] = is_string($extension) ? [$extension, []] : $extension;
			if (class_exists($class)) {
				$args = DI\Helpers::expand($args, $this->parameters, true);
				$compiler->addExtension($name, (new \ReflectionClass($class))->newInstanceArgs($args));
			}
		}

		$this->onCompile($this, $compiler);

		$class = $compiler->compile();
		return $sources . "\n" . $class;
	}


	protected function createLoader(): DI\Config\Loader
	{
		return new DI\Config\Loader;
	}


	protected function getCacheDirectory(): string
	{
		if (empty($this->parameters['tempDir'])) {
			throw new Nette\InvalidStateException('Set path to temporary directory using setTempDirectory().');
		}
		$dir = $this->parameters['tempDir'] . '/cache';
		Nette\Utils\FileSystem::createDir($dir);
		return $dir;
	}


	/********************* tools ****************d*g**/


	/**
	 * Detects debug mode by IP addresses or computer names whitelist detection.
	 * @param  string|array  $list
	 */
	public static function detectDebugMode($list = null): bool
	{
		$addr = $_SERVER['REMOTE_ADDR'] ?? php_uname('n');
		$secret = is_string($_COOKIE[self::COOKIE_SECRET] ?? null)
			? $_COOKIE[self::COOKIE_SECRET]
			: null;
		$list = is_string($list)
			? preg_split('#[,\s]+#', $list)
			: (array) $list;
		if (!isset($_SERVER['HTTP_X_FORWARDED_FOR']) && !isset($_SERVER['HTTP_FORWARDED'])) {
			$list[] = '127.0.0.1';
			$list[] = '::1';
		}
		return in_array($addr, $list, true) || in_array("$secret@$addr", $list, true);
	}
}
