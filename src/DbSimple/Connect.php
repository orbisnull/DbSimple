<?php

namespace DbSimple;

/**
 * Класс обертка для DbSimple
 *
 * <br>нужен для ленивой инициализации коннекта к базе
 *
 * @package DbSimple
 * @method mixed transaction(string $mode=null)
 * @method mixed commit()
 * @method mixed rollback()
 * @method mixed select(string $query, $argOrArgsByComma)
 * @method mixed selectRow(string $query, $argOrArgsByComma)
 * @method array selectCol(string $query, $argOrArgsByComma)
 * @method string selectCell(string $query, $argOrArgsByComma)
 * @method mixed query(string $query, $argOrArgsByComma)
 * @method string escape(mixed $s, bool $isIdent=false)
 * @method SubQuery subquery(string $query, $argOrArgsByComma)
 */
class Connect
{
	/** @var Database База данных */
	protected $dbSimple;
	/** @var string DSN подключения */
	protected $dsn;
	/** @var string Тип базы данных */
	protected $shema;
	/** @var array Что выставить при коннекте */
	protected $init;
	/** @var integer код ошибки */
	public $error = null;
	/** @var string сообщение об ошибке */
	public $errmsg = null;

	/**
	 * Конструктор только запоминает переданный DSN
	 * создание класса и коннект происходит позже
	 *
	 * @param string $dsn DSN строка БД
	 */
	public function __construct($dsn)
	{
		$this->dbSimple = null;
		$this->dsn      = $dsn;
		$this->init     = array();
		$this->shema    = ucfirst(substr($dsn, 0, strpos($dsn, ':')));
	}

	/**
	 * Взять базу из пула коннектов
	 *
	 * @param string $dsn DSN строка БД
	 * @return DbSimple_Connect
	 */
	public static function get($dsn)
	{
		static $pool = array();
		return isset($pool[$dsn]) ? $pool[$dsn] : $pool[$dsn] = new self($dsn);
	}

	/**
	 * Возвращает тип базы данных
	 *
	 * @return string имя типа БД
	 */
	public function getShema()
	{
		return $this->shema;
	}

	/**
	 * Коннект при первом запросе к базе данных
	 */
	public function __call($method, $params)
	{
		if ($this->dbSimple === null)
			$this->connect($this->dsn);
		return call_user_func_array(array($this->dbSimple, $method), $params);
	}

	/**
	 * mixed selectPage(int &$total, string $query [, $arg1] [,$arg2] ...)
	 * Функцию нужно вызвать отдельно из-за передачи по ссылке
	 */
	public function selectPage(&$total, $query)
	{
		if ($this->dbSimple === null)
			$this->connect($this->dsn);
		$args = func_get_args();
		$args[0] = &$total;
		return call_user_func_array(array(&$this->dbSimple, 'selectPage'), $args);
	}

	/**
	 * Подключение к базе данных
	 * @param string $dsn DSN строка БД
	 */
	protected function connect($dsn)
	{
		$parsed = $this->parseDSN($dsn);
		if (!$parsed)
			$this->errorHandler('Ошибка разбора строки DSN', $dsn);
		if (!isset($parsed['scheme']))
			$this->errorHandler('Невозможно загрузить драйвер базы данных', $parsed);
		$this->shema = ucfirst($parsed['scheme']);
		require_once dirname(__FILE__).'/'.$this->shema.'.php';
		$class = $this->shema;
		$this->dbSimple = new $class($parsed);
		$this->errmsg = &$this->dbSimple->errmsg;
		$this->error = &$this->dbSimple->error;
		$prefix = isset($parsed['prefix']) ? $parsed['prefix'] : ($this->_identPrefix ? $this->_identPrefix : false);
		if ($prefix)
			$this->dbSimple->setIdentPrefix($prefix);
		if ($this->_cachePrefix) $this->dbSimple->setCachePrefix($this->_cachePrefix);
		if ($this->_cacher) $this->dbSimple->setCacher($this->_cacher);
		if ($this->_logger) $this->dbSimple->setLogger($this->_logger);
		$this->dbSimple->setErrorHandler($this->errorHandler!==null ? $this->errorHandler : array(&$this, 'errorHandler'));
		//выставление переменных
		foreach($this->init as $query)
			call_user_func_array(array($this->dbSimple, 'query'), $query);
		$this->init = array();
	}

	/**
	 * Функция обработки ошибок - стандартный обработчик
	 * Все вызовы без @ прекращают выполнение скрипта
	 *
	 * @param string $msg Сообщение об ошибке
	 * @param array $info Подробная информация о контексте ошибки
	 */
	public function errorHandler($msg, $info)
	{
		// Если использовалась @, ничего не делать.
		if (!error_reporting()) return;
		// Выводим подробную информацию об ошибке.
		echo "SQL Error: $msg<br><pre>";
		print_r($info);
		echo "</pre>";
		exit();
	}

	/**
	 * Выставляет запрос для инициализации
	 *
	 * @param string $query запрос
	 */
	public function addInit($query)
	{
		$args = func_get_args();
		if ($this->dbSimple !== null)
			return call_user_func_array(array(&$this->dbSimple, 'query'), $args);
		$this->init[] = $args;
	}

	/**
	 * Устанавливает новый обработчик ошибок
	 * Обработчик получает 2 аргумента:
	 * - сообщение об ошибке
	 * - массив (код, сообщение, запрос, контекст)
	 *
	 * @param callback|null|false $handler обработчик ошибок
	 * <br>  null - по умолчанию
	 * <br>  false - отключен
	 * @return callback|null|false предыдущий обработчик
	 */
	public function setErrorHandler($handler)
	{
		$prev = $this->errorHandler;
		$this->errorHandler = $handler;
		if ($this->dbSimple)
			$this->dbSimple->setErrorHandler($handler);
		return $prev;
	}

	/** @var callback обработчик ошибок */
	private $errorHandler = null;
	private $_cachePrefix = '';
	private $_identPrefix = null;
	private $_logger = null;
	private $_cacher = null;

	/**
	 * callback setLogger(callback $logger)
	 * Set query logger called before each query is executed.
	 * Returns previous logger.
	 */
	public function setLogger($logger)
	{
		$prev = $this->_logger;
		$this->_logger = $logger;
		if ($this->dbSimple)
			$this->dbSimple->setLogger($logger);
		return $prev;
	}

	/**
	 * callback setCacher(callback $cacher)
	 * Set cache mechanism called during each query if specified.
	 * Returns previous handler.
	 */
	public function setCacher(Zend_Cache_Backend_Interface $cacher=null)
	{
		$prev = $this->_cacher;
		$this->_cacher = $cacher;
		if ($this->dbSimple)
			$this->dbSimple->setCacher($cacher);
		return $prev;
	}

	/**
	 * string setIdentPrefix($prx)
	 * Set identifier prefix used for $_ placeholder.
	 */
	public function setIdentPrefix($prx)
	{
		$old = $this->_identPrefix;
		if ($prx !== null) $this->_identPrefix = $prx;
		if ($this->dbSimple)
			$this->dbSimple->setIdentPrefix($prx);
		return $old;
	}

	/**
	 * string setCachePrefix($prx)
	 * Set cache prefix used in key caclulation.
	 */
	public function setCachePrefix($prx)
	{
		$old = $this->_cachePrefix;
		if ($prx !== null) $this->_cachePrefix = $prx;
		if ($this->dbSimple)
			$this->dbSimple->setCachePrefix($prx);
		return $old;
	}

	/**
	 * Разбирает строку DSN в массив параметров подключения к базе
	 *
	 * @param string $dsn строка DSN для разбора
	 * @return array Параметры коннекта
	 */
	protected function parseDSN($dsn)
	{
		$parsed = parse_url($dsn);
		if (!$parsed)
			return null;
		$params = null;
		if (!empty($parsed['query']))
		{
			parse_str($parsed['query'], $params);
			$parsed += $params;
		}
		$parsed['dsn'] = $dsn;
		return $parsed;
	}
}

?>
