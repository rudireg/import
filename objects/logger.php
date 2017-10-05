<?php
/**
 * Класс логгирования.
 * User: Rudi
 * Date: 18.04.2017
 */
namespace objects\logger;

class Logger
{
	/**
	 * Имя Файла, куда сохранятся логи
	 * @var string
	 */
	protected static $logFile = 'parser_import_log.txt';

	/**
	 * Массив хранит данные логирования
	 * @var array
	 */
	protected static $logData = [];

	/**
	 * Время запуска скрипта
	 * @var int
	 */
	protected static $startTime;

	/**
	 * Массив уникальных таймеров
	 * @var
	 */
	protected static $uniqueTimer;

	/**
	 * Имя импорта
	 * @var
	 */
	protected static $importName;

	// Закрываем
	final private function __construct() {}
	final private function __clone() {}


	/**
	 * Инициализация логгирования
	 * @param string $name - Имя импорта (cian, avito, etc.)
	 */
	public static function init($name = '')
	{
		self::$importName = $name;
		self::$startTime = microtime(true);
		self::log('>>>>>>>>>>>>>>>> СТАРТ импорта ' . self::$importName);
	}


	/**
	 * Завершение логирования
	 */
	public static function finish()
	{
		$sec = microtime(true) - self::$startTime;
		$t = date("H:i:s", mktime(0, 0, $sec));

		// Отчет о существовании индексов в массиве карты
        if (!empty(self::$logData['unknownIndex'])) {
        	$unknownIndexFileName = 'import_unknown_index.txt';
			self::flog('empty', $unknownIndexFileName);
			self::flog(print_r(self::$logData['unknownIndex'], true), $unknownIndexFileName);
        	$unknownIndex = PHP_EOL . 'Кол-во не определенных индексов: ' . array_sum(array_map('count', self::$logData['unknownIndex'])) . PHP_EOL .
				            'Подробнее в файле: ' . $unknownIndexFileName;
			self::log($unknownIndex);
		}

		// Отчет валидатора
		if (!empty(self::$logData['validate'])) {
			// Общий отчет валидатора
			$validatorStr = '';
			foreach (self::$logData['validate'] as $name => $value) {
				$validatorStr .= $name . ': ' . count($value) . PHP_EOL;
			};
			self::log(PHP_EOL . 'Отчет валидатора:' . PHP_EOL . $validatorStr);

			// Детальный отчет валидатора
			$detailValidatorStr = '';
			foreach (self::$logData['validate'] as $name => $value) {
				$fileName = 'import_validate_' . $name . '.txt';
				self::flog('empty', $fileName);
				self::flog(print_r($value, true), $fileName);
				$detailValidatorStr .= $name . ': ' . $fileName . PHP_EOL;
			}
			self::log(PHP_EOL . 'Детальный отчет валидатора:' . PHP_EOL . $detailValidatorStr);
		}

		// Отчет по объявлениям
		$insertObj      = !empty(self::$logData['insertObj'])?      array_sum(array_map('count', self::$logData['insertObj']))     : 0;
		$deleteObj      = !empty(self::$logData['deleteObj'])?      array_sum(array_map('count', self::$logData['deleteObj']))     : 0;
		$notUpdateObj   = !empty(self::$logData['notUpdateObj'])?   array_sum(array_map('count', self::$logData['notUpdateObj']))  : 0;
		$fullUpdateObj  = !empty(self::$logData['fullUpdateObj'])?  array_sum(array_map('count', self::$logData['fullUpdateObj'])) : 0;
		$dataUpdateObj  = !empty(self::$logData['dataUpdateObj'])?  array_sum(array_map('count', self::$logData['dataUpdateObj'])) : 0;
		$excludeObj     = !empty(self::$logData['excludeObj'])?     array_sum(array_map('count', self::$logData['excludeObj']))    : 0;
		$insertImg      = !empty(self::$logData['insertImg'])?      count(self::$logData['insertImg'])  : 0;
		$deleteImg      = !empty(self::$logData['deleteImg'])?      count(self::$logData['deleteImg'])  : 0;
		$excludeImg     = !empty(self::$logData['excludeImg'])?     count(self::$logData['excludeImg']) : 0;
		$totalCount     = $insertObj + $notUpdateObj + $fullUpdateObj + $dataUpdateObj;

		$str =  PHP_EOL . 'Отчет по объявлениям:'. PHP_EOL .
			   'Общее кол-во объявлений: ' . $totalCount . PHP_EOL .
               'Вставили новых: '  . $insertObj  . PHP_EOL .
			    self::arrToStr(@self::$logData['insertObj']) .      // Детально вставленные объявления
		       'Деактивировали: '  . $deleteObj  . PHP_EOL .
			    self::arrToStr(@self::$logData['deleteObj']) .      // Детально удаленные объявления
		       'Полностью Обновили, и адрес и данные объявления (будет перекладирование): ' . $fullUpdateObj  . PHP_EOL .
			    self::arrToStr(@self::$logData['fullUpdateObj']) .  // Детально полное обновленные объявления с перекладированием
			   'Обновили только данные (адрес не затронут, перекладирования НЕ будет): ' . $dataUpdateObj  . PHP_EOL .
			    self::arrToStr(@self::$logData['dataUpdateObj']) .  // Детально Обновили только данные (без адреса, перекладирования НЕ будет)
			   'Объявления без обновления (данные и адрес не изменились): ' . $notUpdateObj  . PHP_EOL .
			    self::arrToStr(@self::$logData['notUpdateObj']) .  // Детально объявления без обновления (данные и адрес не изменились)
		       'Отклонили: '. $excludeObj . PHP_EOL .
			    self::arrToStr(@self::$logData['excludeObj']) .    // Детально исключенные объявления
			   'Отчет по фотографиям:'. PHP_EOL .
		       'Вставили: '  . $insertImg . PHP_EOL .
			   'Удалили: '   . $deleteImg . PHP_EOL .
		       'Отклонили: ' . $excludeImg;

		self::log($str);

		$mess = '<<<<<<<<<<<<<<<< СТОП импорта ' . self::$importName . '. Время выполнения скрипта: ' . $t;
		self::log($mess);
	}


	/**
	 * @param array $arr
	 * @return string
	 */
	public static function arrToStr($arr)
	{
		if (empty($arr)) {
			return '';
		}
		$str = '';
		foreach ($arr as $name => $value) {
			$str .= $name . ': ' . count($value) . PHP_EOL;
		};
		return $str;
	}

	/**
	 * Занести данные логирования
	 * @param string $name  - Раздел логирования
	 * @param string|array $value - Значение логирования
	 */
	public static function setValue($name, $value)
	{
		if (empty($name) || empty($value)) {
			return;
		}
		if (is_array($value)) {
			foreach ($value as $k => $v) {
				self::$logData[$name][$k][] = $v;
			};
		} else {
			self::$logData[$name][] = $value;
		}
	}


	/**
	 * Логирование только в файл
	 * @param string $mess
	 * @param string $file
	 */
	public static function flog ($mess, $file = '__default__')
	{
		$fileName = ($file !== '__default__')? $file : self::$logFile;

		if ($mess != 'empty') {
			$mess = \date('[d.m.Y H:i:s] ') . $mess;
		}
		\CMS::flog('file:' . $fileName, $mess);
	}

	/**
	 * Логирование на монитор и в файл
	 * @param string $mess
	 * @param string $file
	 */
	public static function log($mess, $file = '__default__')
	{
		$fileName = ($file !== '__default__')? $file : self::$logFile;

		if ($mess != 'empty') {
			if (!is_array($mess)) {
				$mess = date('[d.m.Y H:i:s] ') . $mess;
			}

			\CMS::dp($mess);

			@ob_flush();
			@flush();
		}
		\CMS::flog('file:' . $fileName, $mess);
	}


	/**
	 * Вывод имени массива и подсчет кол-ва его элементов
	 * @param array $arr
	 */
	public static function arrayCountLog(array $arr)
	{
		if (empty($arr)) {
			self::log('Нет данных.');
			return;
		}
		if (!is_array($arr)) {
			$arr[] = $arr;
		}
		foreach ($arr as $name => $value) {
			self::log($name . ': ' . count($value));
		};
	}


	/**
	 * Фатальная ошибка
	 * @param string $msg
	 */
	public static function error($msg)
	{
		// Логирование на монитор и в файл
		self::log($msg);
		\CMS::dp($msg);
		die();
	}


	/**
	 * Инициализация уникального таймера
	 * @param string $name - Имя таймера
	 */
	public static function startTimer($name)
	{
		self::$uniqueTimer[$name] = microtime(true);
	}


	/**
	 * Показать время выполнения уникального таймера
	 * @param string $name - Имя таймера
	 * @return false|string
	 */
	public static function stopTimer($name)
	{
		if (empty(self::$uniqueTimer[$name])) {
			return 'Таймер ' . $name . ' не инициализирован.';
		}
		$sec = microtime(true) - self::$uniqueTimer[$name];
		return date("H:i:s", mktime(0, 0, $sec));
	}

}
