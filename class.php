<?php
/**
 *
___|____|____|____|____
_|____|____|____|____
___|____|Битца|___|____|__
_|____| головой |____|_____
___|____|сюды !|____|____|_
_|____|____|____|____|____
___|____|____|____|____
 *
 * Author: Rudi
 * Date: 05.04.2017
 * Description: Класс для загрузки объявлений из спарсеной базы импорта.
 *              Алгоритм работы:
 *              1) Деактивировать mtk объявления, которые: a) помечены как удаленные в базе импорта
 *                                                         б) отсутсвуют в базе импорта
 *              2) Создать новые (либо обновить старые) mtk объявления, которые существуют в базе импорта как активные
 */

set_time_limit(0);

require_once dirname(__FILE__) . '/objects/manager.php';
require_once dirname(__FILE__) . '/importer/cian.php';
require_once dirname(__FILE__) . '/importer/avito.php';
require_once dirname(__FILE__) . '/objects/logger.php';
require_once dirname(__FILE__) . '/objects/validate.php';

use objects\manager\Manager as Manager;
use objects\logger\Logger as Logger;
use importer\cian\Cian as Cian;
use importer\avito\Avito as Avito;

class mtk_othersources
{
	/**
	 * Менеджер который управляет импортом
	 * @var null|Manager
	 */
	private $manager = null;

	/**
	 * Кол-во объявлений, которое будет обработано и импортировано в базу MTK.
	 * Данное значение используется в цикле, для того, что бы экономить ресурсы, если слишком многообъявлений для импорта
	 * @var int
	 */
	private $limit = 1000;

	/**
	 * mtk_cian_import constructor.
	 * Конструктор создает менеджера, который управляет импортом.
	 * Менеджер может реализовывать разные виды импорта (cian, winner и т.д.)
	 * @param null|array $params
	 */
	public function __construct(array $params = null)
	{
		\CMS::closesession();

		if ($params === NULL) {
			return;
		}

		if (empty($params['type'])) {
			Logger::error('Ошибка. Не передали тип парсера.');
		}

		// Инициализация логгера
		Logger::init($params['type']);

		// Подгружаем конфигурацию
		$config = require(__DIR__ . '/config/config.php');

		switch (strtolower($params['type'])) {
			case 'cian':
				$this->manager = new Manager(new Cian($config['cian']));
				break;
			case 'avito':
				$this->manager = new Manager(new Avito($config['avito']));
				break;
			default:
				Logger::error('Ошибка инициализации Менеджера импорта.');
		}
	}

	/**
	 * ЗАПУСКАТЬ ИМПОРТ
	 * @param $type
	 */
	public static function s($type = NULL)
	{
		if (empty($type)) {
			Logger::error('Ошибка othersource. Не передали параметры.');
			return;
		}

        $inst = new self($type);

		if (!$inst) {
			Logger::error('Ошибка конструктора othersource.');
			return;
		}

		$inst->start();
	}


	/**
	 * Приватная входная функция.
	 * СТАРТ импорта объявлений.
	 * Принцп работы:
	 *     1) Деактивируем объявления, которые не получили в НОВОМ списке объявлений импорта
	 *     2) Приводим данные полученные из импорта к формату MTK
	 *     3) Создаем объекты объявлений, и валидируем их.
	 *     4) Загружаем новые либо обновляем старые объявления
	 */
	private function start()
	{
		ini_set('error_reporting', E_ALL);
		ini_set('display_errors', 1);
		ini_set('display_startup_errors', 1);

		// Деактивировать MTK объявления, которых нет в НОВОМ импорте
		if (!$this->manager->deactivateObjects()) {
			Logger::log('Нет объявлений для импортирования');
			return;
		}

		// Узнать, какое объявление новое, а какое уже есть в БД MTK
		// Это нужно что бы понять какие SQL запросы строить для объявления (Вставить новое или обновить существующее)
		$this->manager->getStatusObjects();

		// Счетчик показывающий кол-во обработанных объявлений
		$totalCount = 0;

		// В цикле берем партию объявлений для импорта (объем партии объявлен в переменной $this->limit),
		// подготавливаем объявления и загружаем их в базу MTK.
		// (Цикл нужен что бы не обработать сразу все объявления, а экономить ресурсы)
		while ($cnt = $this->manager->getImportObjects($this->limit)) {

			// Получить данные по объектам из базы импорта. Если ф-ция вернула false, значит нет объявлений для обработки и делаем новую иттерацию цикла.
			if (!$this->manager->getImportData()) {
				continue;
			}

			// Создать объекты объявлений из полученных данных импорта, которые в будущем будут импортированы в базу MTK
			if (!$this->manager->createObjects()) {
				continue;
			}

			// Отвалидировать объекты. Если после валидации не осталось объектов удовлетворяющих требованиям, то делаем новую иттерацию цикла
			if (!$this->manager->validateObjects()) {
				continue;
			}

			// Создать (обновить) объявление в базе MTK из данных созданого объекта объявления
			$this->manager->uploadObjects();

			// Увеличиваем общий счетчик кол-ва обработанных объявлений
			$totalCount += $cnt;
			Logger::log('Обработали: ' . $totalCount . ' объявлений');
		};

		// Завершение логирования
		Logger::finish();
	}
}
