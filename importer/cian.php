<?php
/**
 * Данный класс реализует работу с импортом объявлений из cian.
 * Класс реализует работу с БД, где хранятся данные для импорта.
 * В данном классе создается и инициализируется объект объявления.
 * Author: Rudi
 * Date: 06.04.2017
 */
namespace importer\cian;

require_once (MOD_ROOT."/mtk_objects/class.php");
require_once (MOD_ROOT."/mtk_phones/class.php");
require_once (LIB_ROOT."/DbSimple/Generic.php");
require_once (__DIR__ . '/../import_interface/import.php');
require_once (__DIR__ . '/../objects/repository.php');
require_once (__DIR__ . '/../objects/object.php');

use import_interface\import\Import as Import;
use objects\repository\Repository as Repository;
use objects\object\Object as Object;
use objects\logger\Logger as Logger;
use \mtk_objects;

class Cian extends mtk_objects implements Import
{
	/**
	 * Подключаем trait для работы с общими функциями БД
	 */
	use Repository;

	/**
	 * Объект для работы с БД
	 * @var \DbSimple_Mysql
	 */
	private $db;

	/**
	 * Объект для работы с БД Cian
	 * @var \DbSimple_Mysql
	 */
	private $cDb;

	/**
	 * Название БД, в которой хранятся таблицы для cian
	 * @var
	 */
	private $nameCianDb = 'cian_kocherov';

	/**
	 * Массив хранит список id АКТИВНЫХ обявлений MTK.
	 * Объявления, которые показываются на сайте MTK.
	 * @var array
	 */
    private $mtkIds = [];

	/**
	 * Конфигурация импортера
	 * @var array
	 */
	private $config = [];

	/**
	 * Массив хранит список id объявлений.
	 * Спарсенные cian объявления, которые хранятся в таблице `cian_kocherov.advert`
	 * Данный список id - подлежащит импортированию.
	 * @var array
	 */
    private $cianIds = [];

	/**
	 * Курсы валют
	 * @var
	 */
    private $rates;

	/**
	 * Карта соответствий
	 * @var array
	 */
	private $comparisons = [];

	/**
	 * Массив хранит данные полученные из базы cian
	 * Из этих данных сформируются объекты объявления
	 * @var array
	 */
    private $arrObj = [];

	/**
	 * Имя источника импорта
	 * @var string
	 */
    private $importName = 'cian';

	/**
	 * id источника импорта
	 * @var
	 */
    private $source_id;

	/**
	 * Cian constructor.
	 * @param null $conf
	 */
	public function __construct($conf = null)
	{
		if ($conf === null) {
			Logger::error('Ошибка. Не передали в конструктор импорта конфигурацию.');
		}

		parent::__construct();

		$this->config = $conf;

		global $db;
		$this->db = $db;
		$this->db->query('SET group_concat_max_len = 10000;');

		// Соединение с базой cian
		//$this->cDb = \DbSimple_Generic::connect("mysql://rlt_cian:36166962859be3cb113cf164dbe58a4f@highload.kocherov.net/rlt_cian");
		//$this->cDb = \DbSimple_Generic::connect("mysql://root:@localhost/cian_kocherov"); // Для тестов - подключение к локальной БД
		$this->cDb = \DbSimple_Generic::connect("{$this->config['db']['dsn']}://{$this->config['db']['username']}:{$this->config['db']['password']}@{$this->config['db']['host']}/{$this->config['db']['dbname']}");

		@$this->cDb->setErrorHandler('dbError');
		if(!empty($this->cDb->error)) {
			Logger::error('Ошибка подключения к БД ' . $this->nameCianDb . '<br>' . print_r($this->cDb->error, true));
		}
		@$this->cDb->query("SET NAMES {$this->config['db']['charset']}");
		$this->cDb->query('SET group_concat_max_len = 10000;');

		// Получить курс валют
		$this->rates =  mtk_objects::getCurrencies();

		// Инициализация карты соответсвий
		$this->initComparisons();
	}

	/**
	 * Метод - Заглушка, так как он объявлен как абстрактый метод в Трейте Repository
	 */
	public function getSqlArray() {}


	/**
	 * Получить имя источника импорта
	 * @return string
	 */
	public function getImportName()
	{
		return $this->importName;
	}


	/**
	 * Установить id источника
	 * @param int $sourceId int  - id источника
	 * @return bool
	 */
	public function setSourceId($sourceId)
	{
		if (empty($sourceId)) {
			Logger::error('Ошибка инициализации id источника. Метод Cian::setSourceId');
		}
		$this->source_id = $sourceId;
		return true;
	}


	/**
	 * Инициализация карты соответсвий
	 */
	private function initComparisons()
	{
		// Карта соответствия типов (CIAN => MTK) - используется для именования таблиц
		$this->comparisons['tables'] = [
			'apart'    => 'flats',      // Квартиры
			'land'     => 'cottages',   // Участки
			'house'    => 'cottages',   // Дома
			'room'     => 'rooms',      // Комнаты
			'commerce' => 'commercial'  // Коммерческая недвижимость
		];

		// Карта id типов объявлений в источнике
		$this->comparisons['source_object_type_id'] = [
			'apart'    => 1, // Квартиры
			'land'     => 2, // Участки
			'house'    => 3, // Дома
			'room'     => 4, // Комнаты
			'commerce' => 5  // Коммерческая недвижимость
		];

		// Карта типа сделки
		$this->comparisons['deal'] = [
			'sale' => 10,
			'rent' => 20
		];

		// Охрана
		$this->comparisons['guard'] = [
			'есть' => 1,
			'-' => 0
		];

		// Наличие телефона
		$this->comparisons['phone_on'] = [
			'да'   => 1,
			'есть' => 1,
			'нет'  => -1,
			'–'    => 0,
			'-'    => 0
		];

		// Наличие мебели
		$this->comparisons['furniture'] = [
			'есть' => 1,
			'нет'  => -1,
			'–'    => 0,
			'-'    => 0
		];

		// Срок аренды
		$this->comparisons['rent_term'] = [
			'длительный'           => 140,
			'на несколько месяцев' => 120,
			'Посуточно'            => 110,
			'–'                    => 0,
			'-'                    => 0
		];

		// Состояние объекта (ремонт)
		$this->comparisons['repair_condition'] = [
            'дизайнерский ремонт'            => 80,
			'дизайнерский'                   => 80,
			'евроремонт'                     => 60,
            'офисная отделка'                => 30,
            'под чистовую отделку'           => 50,
            'типовой ремонт'                 => 60,
            'требуется капитальный ремонт'   => 40,
            'требуется косметический ремонт' => 40,
			'косметический'                  => 70,
			'отсутствует'                    => 40,
			'–'                              => 20,
			'-'                              => 20
		];

		// Стадия строительства объекта
		$this->comparisons['new_phase'] = [
			'действующий' => 30,
			'проект'      => 10,
			'строящийся'  => 10,
			'–'           => 0,
			'-'           => 0
		];

		// Тип объекта (используется для коммерческой и бизнес недвижимости)
		$this->comparisons['object_type'] = [
			'гараж'                                  => 70,
			'здание'                                 => 210,
			'земля'                                  => 120,
			'офис'                                   => 200,
			'здание в деловом центре'                => 60,
			'здание в особняке'                      => 140,
			'здание в многофункциональном комплексе' => 60,
			'помещение под производство'             => 240,
			'бизнес'                                 => 110,
			'помещение свободного назначения'        => 140,
			'свободного назначения'                  => 140,
			'производство'                           => 240,
			'склад'                                  => 280,
			'торговая площадь'                       => 300
		];

		// Наличие газа на объекте
		$this->comparisons['gas'] = [
			'есть'                          => 30,
			'на участке'                    => 30,
			'на участке (давление высокое)' => 30,
			'на участке (давление низкое)'  => 30,
			'на участке (давление среднее)' => 30,
			'на участке (емкость 121 м³/ч)' => 30,
			'на участке (емкость 20 м³/ч)'  => 30,
			'на участке (емкость 250 м³/ч, давление среднее)' => 30,
			'нет'                           => 10,
			'нет (подключение возможно)'    => 10,
			'по границе участка'                    => 150,
			'по границе участка (давление высокое)' => 150,
			'по границе участка (давление низкое)'  => 150,
			'по границе участка (давление среднее)' => 150,
			'по границе участка (емкость 250 м³/ч, давление высокое)' => 150,
			'по границе участка (емкость 600 м³/ч, давление среднее)' => 150,
			'по границе участка (емкость 999 м³/ч, давление среднее)' => 150,
			'–' => 10,
			'-' => 10
		];

		// Наличие канализации на объекте
		$this->comparisons['sewer'] = [
			'есть'                     => 30,
			'на участке'               => 30,
			'на участке (автономная)'  => 30,
			'на участке (центральная)' => 120,
			'на участке (центральная, объем 500 м³/сутки)' => 120,
			'нет'                              => 10,
			'нет (подключение возможно)'       => 10,
			'по границе участка'               => 130,
			'по границе участка (автономная)'  => 130,
			'по границе участка (центральная)' => 130,
			'–'                                => 10,
			'-'                                => 10
		];

		// Наличие водоснабжения на объекте
		$this->comparisons['water'] = [
			'есть'       => 30,
			'на участке' => 30,
			'на участке (автономное)'  => 30,
			'на участке (автономное, объем 100 м³/сутки)' => 30,
			'на участке (центральное)' => 110,
			'на участке (центральное, объем 900 м³/сутки)' => 110,
			'нет'                        => 10,
			'нет (подключение возможно)' => 10,
			'по границе участка'         => 40,
			'по границе участка (автономное)' => 110,
			'по границе участка (автономное, объем 800 м³/сутки)'  => 110,
			'по границе участка (центральное)'                     => 110,
			'по границе участка (центральное, объем 300 м³/сутки)' => 110,
			'–' => 10,
			'-' => 10
		];

		// Наличие электричества
		$this->comparisons['electro'] = [
			'есть'                          => 30,
			'на участке'                    => 30,
			'на участке (мощность 10 кВт)'  => 140,
			'на участке (мощность 100 кВт)' => 30,
			'на участке (мощность 110 кВт)' => 30,
			'на участке (мощность 140 кВт)' => 30,
			'на участке (мощность 15 кВт)'  => 30,
			'на участке (мощность 150 кВт)' => 30,
			'на участке (мощность 16 кВт)'  => 30,
			'на участке (мощность 180 кВт)' => 30,
			'на участке (мощность 20 кВт)'  => 30,
			'на участке (мощность 200 кВт)' => 30,
			'на участке (мощность 220 кВт)' => 30,
			'на участке (мощность 25 кВт)'  => 30,
			'на участке (мощность 250 кВт)' => 30,
			'на участке (мощность 30 кВт)'  => 30,
			'на участке (мощность 300 кВт)' => 30,
			'на участке (мощность 350 кВт)' => 30,
			'на участке (мощность 400 кВт)' => 30,
			'на участке (мощность 430 кВт)' => 30,
			'на участке (мощность 50 кВт)'  => 30,
			'на участке (мощность 500 кВт)' => 30,
			'на участке (мощность 630 кВт)' => 30,
			'на участке (мощность 70 кВт)'  => 30,
			'на участке (мощность 80 кВт)'  => 30,
			'на участке (мощность 800 кВт)' => 30,
			'на участке (мощность 900 кВт)' => 30,
			'нет'                           => 10,
			'нет (подключение возможно)'    => 110,
			'по границе участка'                    => 150,
			'по границе участка (мощность 100 кВт)' => 150,
			'по границе участка (мощность 15 кВт)'  => 150,
			'по границе участка (мощность 150 кВт)' => 150,
			'по границе участка (мощность 200 кВт)' => 150,
			'по границе участка (мощность 220 кВт)' => 150,
			'по границе участка (мощность 300 кВт)' => 150,
			'по границе участка (мощность 350 кВт)' => 150,
			'по границе участка (мощность 380 кВт)' => 150,
			'по границе участка (мощность 500 кВт)' => 150,
			'по границе участка (мощность 60 кВт)'  => 150,
			'по границе участка (мощность 700 кВт)' => 150,
			'по границе участка (мощность 900 кВт)' => 150,
			'по границе участка (мощность 999 кВт)' => 150,
			'–'                                     => 10,
			'-'                                     => 10
		];

        // Карта соответсвия метро
		$this->comparisons['metro'] = mtk_objects::getGlobalStatic("metro");

		// Тип здания
		$this->comparisons['house_type'] = [
            'блочный'       => 110,
            'деревянный'    => 180,
            'кирпично-монолитный' => 160,
            'кирпичный'     => 120,
            'монолитный'    => 150,
            'панельный дом' => 130,
			'панельный'     => 130,
            'сталинский'    => 140,
            'щитовой'       => 180,
            'старый фонд'   => 210
		];

		// Тип балкона
		$this->comparisons['balcony'] = [
			'есть балкон' => 110,
			'1 балк.'  => 110,
			'-1 балк.' => 110,
			'2 балк.'  => 120,
			'3 балк.'  => 130,
			'4 балк.'  => 130,
			'5 балк.'  => 130,
			'8 балк.'  => 130,
			'18 балк.' => 130,
			'есть лоджия' => 145,
			'1 лодж.'     => 145,
			'-1 лодж.'    => 145,
			'2 лодж.' => 180,
			'3 лодж.' => 190,
			'4 лодж.' => 200,
			'5 лодж.' => 200,
			'6 лодж.' => 200,
			'7 лодж.' => 200,
			'8 лодж.' => 200,
			'9 лодж.' => 200,
			'10 лодж.' => 200,
			'1 балк. + 1 лодж.' => 140,
			'есть балкон / есть лоджия' => 140,
			'1 балк. + 2 лодж.' => 150,
			'1 балк. + 3 лодж.' => 150,
			'-1 балк. + 3 лодж.' => 150,
			'1 балк. + 4 лодж.' => 150,
			'2 балк. + 1 лодж.' => 170,
			'2 балк. + 2 лодж.' => 160,
			'2 балк. + 3 лодж.' => 160,
			'2 балк. + 4 лодж.' => 160,
			'3 балк. + 1 лодж.' => 170,
			'3 балк. + 2 лодж.' => 160,
			'3 балк. + 3 лодж.' => 160,
			'3 балк. + 4 лодж.' => 160,
			'4 балк. + 1 лодж.' => 170,
			'4 балк. + 2 лодж.' => 160,
			'4 балк. + 3 лодж.' => 160,
			'4 балк. + 4 лодж.' => 160,
			'нет'     => 10,
			'–'       => 20,
			'-'       => 20
		];

		// Карта соответствий регионов
		$this->comparisons['region_id'] = [
		    '1' => '77',  // Москва
			'2' => '74',  // Челябинская область
            '3' => '78',  // Санкт-Петербург
            '4' => '50',  // Московская область
		    '5' => '52',  // Нижегородская область
            '6' => '2',   // Республика Башкортостан
            '7' => '71',  // Тульская область
            '8' => '61',  // Ростовская область
            '9' => '47',  // Ленинградская область
		    '10' => '54', // Новосибирская область
            '11' => '60', // Псковская область
            '12' => '40', // Калужская область
            '13' => '92', // Севастополь
            '14' => '23', // Краснодарский край
            '15' => '16', // Республика Татарстан
	    	'16' => '58', // Пензенская область
	    	'17' => '91', // Крым
            '18' => '13', // Республика Мордовия
            '19' => '33', // Владимирская область
            '20' => '63', // Самарская область
            '21' => '26', // Ставропольский край
            '22' => '24', // Красноярский край
            '23' => '62', // Рязанская область
	        '24' => '31', // Белгородская область
            '25' => '21', // Чувашская Республика
            '26' => '66', // Свердловская область
            '27' => '64', // Саратовская область
            '28' => '69', // Тверская область
            '29' => '44', // Костромская область
            '30' => '37', // Ивановская область
            '31' => '73', // Ульяновская область
            '32' => '22', // Алтайский край
            '33' => '28', // Амурская область
            '34' => '59', // Пермский край
	    	'35' => '42', // Кемеровская область
            '36' => '5',  // Республика Дагестан
            '37' => '48', // Липецкая область
            '38' => '76', // Ярославская область
            '39' => '55', // Омская область
            '40' => '1',  // Республика Адыгея
	    	'41' => '39', // Калининградская область
            '42' => '36', // Воронежская область
            '43' => '67', // Смоленская область
            '44' => '30', // Астраханская область
            '45' => '34', // Волгоградская область
            '46' => '10', // Республика Карелия
            '47' => '53', // Новгородская область
            '48' => '38', // Иркутская область
            '49' => '56', // Оренбургская область
            '50' => '51', // Мурманская область
	    	'51' => '57', // Орловская область
	    	'52' => '89', // Ямало-Ненецкий автономный округ
            '53' => '72', // Тюменская область
            '54' => '32', // Брянская область
            '55' => '43', // Кировская область
            '56' => '46', // Курская область
            '57' => '35', // Вологодская область
            '58' => '18', // Удмуртская Республика
            '59' => '27', // Хабаровский край
            '60' => '86', // Ханты-Мансийский автономный округ
            '61' => '4',  // Республика Алтай
	    	'62' => '70', // Томская область
            '63' => '11', // Республика Коми
            '64' => '68', // Тамбовская область
            '65' => '20', // Чеченская Республика
            '66' => '19', // Республика Хакасия
            '67' => '12', // Республика Марий Эл
            '68' => '25', // Приморский край
            '69' => '45', // Курганская область
            '70' => '15', // Республика Северная Осетия - Алания
            '71' => '3',  // Республика Бурятия
            '72' => '29', // Архангельская область
            '73' => '75', // Забайкальский край
            '74' => '49', // Магаданская область
            '75' => '17', // Республика Тыва
            '76' => '65', // Сахалинская область
            '77' => '7',  // Кабардино-Балкарская Республика
            '78' => '9',  // Карачаево-Черкесская Республика
            '79' => '14', // Республика Саха (Якутия)
            '80' => '8',  // Республика Калмыкия
            '81' => '41'  // Камчатский край
	    ];
	}

	/**
	 * Вернуть объект для работы с БД
	 * @return \DbSimple_Mysql
	 */
	public function getDb()
	{
        return $this->db;
	}


	/**
	 * Получить id спарсенных объявлений из базы cian, подлежащих импортированию в базу MTK.
	 * Нас интересуют те объявления, для которых не проставлен статус: УДАЛЕН
	 * @return array
	 */
	public function initImportIds()
	{
		$totalExcludeParts = [];
	    foreach ($this->comparisons['tables'] as $cianType => $mtkType) {
	    	// Получить объявления без учета доли
			$this->cianIds[$mtkType] = $this->cDb->selectCol('
SELECT
    `id`
FROM
    ?#
WHERE
    `type` = ?
AND
    `is_closed` = 0',
				'advert',
				$cianType
		    );

			// Получить объявления по типу с учетом доли (Наличие параметра номер 79 говорит о наличии доли)
			$parts = $this->cDb->selectCol('
SELECT DISTINCT
    adv.`id`
FROM
    ?# adv
LEFT JOIN
    advert_param_value param
ON
    adv.unique_id = param.unique_id
WHERE
    `type` = ?
AND
    `is_closed` = 0
AND
	param.param_id = 79',
				'advert',
				$cianType
			);

			// Вычитаем доли
			if (!empty($parts)) {
				$totalExcludeParts = array_merge($totalExcludeParts, $parts);
				$this->cianIds[$mtkType] = array_diff($this->cianIds[$mtkType], $parts);
			}
		};

        // Для логирования, сохраняем список исключений долей
		if (!empty($totalExcludeParts)) {
			foreach ($totalExcludeParts as $parts) {
				Logger::setValue('validate', ['EXCLUDE_PARTS' => $parts]);
			};
		}

		return $this->cianIds;
	}


	/**
	 * Вернуть массив id АКТИВНЫХ объявлений, которые существуют в базе MTK
	 * @return array
	 */
	public function getMtkIds()
	{
		return $this->mtkIds;
	}


	/**
	 * Вернуть массив id объявлений для импорта, которые будут загруженны а базу MTK
	 * @return array
	 */
	public function getImportIds()
	{
		return $this->cianIds;
	}


	/**
	 * Выборка из БД cian. Получяем ВСЕ данные об объявлениях
	 * @param array $idsForPrepare - Массив id, по которым будет выборка из базы импорта
	 * @return int - Возвращаем полученное кол-во данных для объявлений
	 */
	public function getImportData(array $idsForPrepare)
	{
		if (empty($idsForPrepare)) {
			return 0;
		}
		// Получаем тип недвижимости
		$type = array_keys($idsForPrepare)[0];
		//$idsForPrepare[$type] = [155962902, 155962887];

		// Получаем объекты и их спарсенные данные из базы cian
		$this->arrObj = [];
		$this->arrObj = $this->cDb->select('
SELECT
    adv.`id` AS ARRAY_KEY, adv.*, 
    GROUP_CONCAT(DISTINCT img.url SEPARATOR "##") AS images, -- фотографии (может быть несколько, разделяем ## )
    GROUP_CONCAT(DISTINCT met.name SEPARATOR "##") AS metro, -- метро (может быть несколько, разделяем ## )
    GROUP_CONCAT(DISTINCT CONCAT(param.param_id, "~~", param.value) SEPARATOR "##") AS params, -- параметры (может быть несколько, разделяем ##. Внутренний разделитель 2-а знака тильды ~~)
    GROUP_CONCAT(DISTINCT ph.phone SEPARATOR "##") AS phones, -- телефон (может быть несколько, разделяем ## )
    ct.name AS cityName, -- имя города
    ct.region_id AS cian_region_id, -- id региона в рамках cian
    rgn.name AS regionName -- имя региона
FROM
    `advert` adv
LEFT JOIN
    advert_image img
ON
    adv.unique_id = img.unique_id
LEFT JOIN
    advert_metro met
ON
    adv.unique_id = met.unique_id
LEFT JOIN
    advert_param_value param
ON
    adv.unique_id = param.unique_id
LEFT JOIN
    advert_phone ph
ON
    adv.unique_id = ph.unique_id
LEFT JOIN
    city ct
ON
    adv.city_id = ct.id
LEFT JOIN
    region rgn
ON
    ct.region_id = rgn.id
WHERE
    adv.`type` IN (?a)
AND
    adv.`is_closed` = 0
AND
    adv.`id` IN (?a)
GROUP BY
    adv.`id`',
			array_keys($this->comparisons['tables'], $type),
			$idsForPrepare[$type]);

		// Возвращаем полученное кол-во данных для объявлений
        return count($this->arrObj);
	}


	/**
	 * Создает объекты объявлений, которые будут импортированы в базу MTK.
	 * Объекты создаются на основе данных полученных из спарсенной базы импорта (данные получаем методом getImportData(...) ).
	 * Объекты после создания считаются еще сырыми, то есть неотвалидированными.
	 * @return array - Возвращает созданные объекты
	 */
	public function createObjects()
	{
		// Проверка, есть ли данные для создания объектов объявлений
		if (empty($this->arrObj = array_filter($this->arrObj))) {
			return [];
		}

        // Создаем объекты объявления
		$objList = [];
		foreach ($this->arrObj as $objData) {
			// Создаем один объект и Заносим в общий список объектов объявлений
			$obj = $this->createOneObj($objData);
			if (!empty($obj)) {
				$objList[] = $obj;
			} else {
				Logger::setValue('excludeObj', [$this->comparisons['tables'][$objData['type']] => $objData['id']]);
			}
		};

		return $objList;
	}


	/**
	 * Создает один объект объявления используя переданные данные в аргументе $obj
	 * @param array $objData - Данные полученные из спарсенной базы cian
	 * @return \objects\object\Object - Возвращаем объект объявления
	 */
	private function createOneObj(array $objData)
	{
		// Если это Крым или Севастополь - то не обрабатываем объявление
//		if (in_array($objData['cian_region_id'], [13, 17])) {
//			$errorStr = 'Тип объявления: ' . $this->comparisons['tables'][$objData['type']] . ' id: ' . $objData['id'] . ' Регион КРЫМ';
//			Logger::setValue('validate', ['ERROR_REGION_CRIMEA' => $errorStr]);
//			return null;
//		}

		$obj = new Object($this->config['validate']);

		// Инициалзируем технические данные объявления:
		// Тип спарсенного объявления (flats, rooms, cottages, commercial)
		$obj->setServiceField('type', $this->getParamValue($this->comparisons['tables'], @$objData['type']));
		// Установка id источника объявления
		$obj->setServiceField('id', $objData['id']);
		// Имя Города
		$obj->setServiceField('cityName', $objData['cityName']);
		// Имя Региона
		$obj->setServiceField('regionName', $objData['regionName']);
		// Инициализируем список фотографий
		$obj->setServiceField('images', $objData['images']);
		// Полный адрес строкой
		$obj->setServiceField('full_address', $objData['address']);
		// Устанавливаем тип источника
		$obj->setServiceField('source_id', $this->source_id);
		// Устанавливаем id типа объекта источника
		$obj->setServiceField('source_object_type_id', $this->comparisons['source_object_type_id'][$objData['type']]);

		// Инициализируем информационные данные объявления:
		// Статус
		$obj->setCommonField('status', -1);
		// Дата создания объявления (дата берется из cian базы)
		$objData['created_date'] = empty($objData['created_date'])? date('Y-m-d H:i:s') : $objData['created_date'];
		$obj->setCommonField('date_added', $objData['created_date']);
		// Дата обновления
		$objData['updated_date'] = empty($objData['updated_date'])? $objData['created_date'] : $objData['updated_date'];
		$obj->setCommonField('date_renew', $objData['updated_date']);
        // Дата деактивации объявления
		$obj->setCommonField('date_deleted', empty($objData['is_closed'])? null : date('Y-m-d H:i:s'));
		// Тип сделки (продажа - 10, аренда - 20)
		$obj->setCommonField('deal', $this->getParamValue($this->comparisons['deal'], @$objData['act']));
		// Тип валюты - в рублях
		$obj->setCommonField('currency', 1);
        // Цена в рублях
		$obj->setCommonField('price', $objData['price']);
		// Цена в долларах
		$obj->setCommonField('price_usd', round($objData['price'] / $this->rates['USD']['rate']));
        // Цена в евро
		$obj->setCommonField('price_eur', round($objData['price'] / $this->rates['EUR']['rate']));
        // В БД данное значение не имеет значения по умолчанию (имеет тип TEXT)
		$obj->setCommonField('prices_old', '');
		// В БД данное значение не имеет значения по умолчанию (имеет тип TEXT и может принимать значение NULL)
		$obj->setCommonField('note', null);
        // Так как координаты нам отдает cian, мы устанавливаем точность координат уникальным и неповторяющимся значением: 32000
		// Если оставить данное поле как 0, то будет считаться что точность координат плохая.
		$obj->setCommonField('coords_accuracy', 32000);
        // Статус агентсва
		$obj->setCommonField('status_agency', 0);
		// Телефон
		$obj->setCommonField('phone', \mtk_phones::preparePhone($objData['phones']));
		// Описание объявления
		$obj->setCommonField('description', htmlspecialchars($objData['description']));
		// Долгота
		$obj->setCommonField('longitude', $objData['longitude']);
		// Широта
		$obj->setCommonField('latitude', $objData['latitude']);
		// Region ID
		$obj->setCommonField('region_id',  $this->getParamValue($this->comparisons['region_id'], @$objData['cian_region_id']));

		// Инициализация данных, которые относятся к конкретному типу объекта (flats, rooms, cottages, commercial)
		$this->setTypeData($obj, $objData, $this->getParamValue($this->comparisons['tables'], @$objData['type']));

		// Разбиваем полный адрес на части.
		// Разбивать адрес будем только после срабатывания функции $this->setTypeData, потому как при разбивки адреса используется $type (flats, rooms etc.)
		if (!$obj->separateAddress($objData['address'], $this->comparisons['tables'][$objData['type']])) {
			$errorStr = 'Тип объявления: ' . $this->comparisons['tables'][$objData['type']] . ' id: ' . $objData['id'] . ' Не полный адрес: ' . $objData['address'];
			Logger::setValue('validate', ['ERROR_ADDRESS' => $errorStr]);
			unset ($obj);
			return null;
		}

        return $obj;
	}

	/**
	 * Инициализация данных, которые относятся к конкретному типу объекта (flats, rooms, cottages, commercial)
	 * @param \objects\object\Object $obj - Объект объявления
	 * @param array $objData - Исходные данные из спарсенной базы cian
	 * @param string $type - Тип объявления (flats, rooms, cottages, commercial)
	 */
	private function setTypeData(&$obj, array $objData, $type)
	{
		// Предварительная подготовка параметров объекта
		$params = $this->prepareParams($objData['params']);

		if (in_array($type, ['flats', 'rooms'])) {
			// TODO: Парсер cian не предоставляет подобной информации
			$items = ['tv', 'washmach', 'commission_agency', 'commission_client', 'fridge', 'hypothec', 'floor_type'];
			foreach($items as $item) {
				$obj->setCustomField($type, $item, 0);
			}
			// Определить наличие мебели (Параметр номер 37)
			$obj->setCustomField($type, 'furniture', $this->getParamValue($this->comparisons['furniture'], @$params[37]));
		}

		if (in_array($type, ['flats', 'rooms', 'parts', 'commercial', 'parkings'])) {
			// TODO: Парсер cian не предоставляет подобной информации
			$items = ['metro_distance_walk', 'metro_distance_transport'];
			foreach($items as $item) {
				$obj->setCustomField($type, $item, 0);
			}
			// Получить id метро
			$obj->setCustomField($type, 'metro_id', !empty($objData['metro'])? $this->getIdMetro($objData['metro'], $obj->getValue('common', 'region_id')) : 0);
			// Определить этаж, на котором находится объект
			$obj->setCustomField($type, 'floor', $this->getFloor(@$params[1])['floor']);
		}

		if (in_array($type, ['flats', 'rooms', 'parts', 'parkings'])) {
			// Общая площадь объекта (Параметр номер 6)
			$obj->setCustomField($type, 'area_total', !empty($params[6])? $this->getArea($params[6]) : 0);
			// Является ли объект НОВОСТРОЙКОЙ (Параметр номер 2)
			$obj->setCustomField($type, 'type', strstr(mb_strtolower(!empty($params[2])? $params[2] : '', 'UTF-8'), 'новостройка')? 30 : 0);
		}

		if (in_array($type, ['flats', 'rooms', 'parts', 'commercial'])) {
			// Определить кол-во этажей в здании, в котором находится объект
			$obj->setCustomField($type, 'floors', $this->getFloors($params));
		}

		if (in_array($type, ['flats', 'rooms', 'parts'])) {
			// Кол-во комнат в объекте
			$obj->setCustomField($type, 'rooms', !empty($objData['rooms'])? trim($objData['rooms']) : 0);
			// Определить наличие балкона (Параметр номер 11)
			$obj->setCustomField($type, 'balcony', $this->getParamValue($this->comparisons['balcony'], @$params[11]));
			// Определить наличие телефона (Параметр номер 58)
			$obj->setCustomField($type, 'phone_on', $this->getParamValue($this->comparisons['phone_on'], @$params[58]));
			// Определение жилой площади объекта (Параметр номер 8)
			$obj->setCustomField($type, 'area_living', !empty($params[8])? $this->getArea($params[8]) : 0);
			// Определение площади кухни объекта (Параметр номер 9)
			$obj->setCustomField($type, 'area_kitchen', !empty($params[9])? $this->getArea($params[9]) : 0);
			// Определить тип здания (блочный, монолитный etc.)(Параметр номер 2)
			$obj->setCustomField($type, 'house_type', $this->getHouseType(@$params[2]));
		}

		if (in_array($type, ['flats', 'rooms', 'parts', 'cottages'])) {
			// Определить кол-во совмещенных санузлов (Параметр номер 23)
			$obj->setCustomField($type, 'wc_comb_num', !empty($params[23])? $params[23] : 10);
			// Определить кол-во разделенных санузлов (Параметр номер 25)
			$obj->setCustomField($type, 'wc_sep_num', !empty($params[25])? $params[25] : 10);
			// Определить Состояние объекта (Параметр номер 28 либо 15)
			$repair_condition = !empty($params[28])? $params[28] : (!empty($params[15])? $params[15] : '-');
			$obj->setCustomField($type, 'repair_condition', $this->getParamValue($this->comparisons['repair_condition'], @$repair_condition));
		}

		if (in_array($type, ['flats', 'rooms', 'cottages', 'parkings'])) {
			// Определить длительность аренды (Параметр номер 18)
			$obj->setCustomField($type, 'rent_term', $this->getParamValue($this->comparisons['rent_term'], @$params[18]));
		}

		if (in_array($type, ['flats'])) {
			// Определить Стадию строительства дома (Параметр номер 32)
			$obj->setCustomField($type, 'new_phase', $this->getParamValue($this->comparisons['new_phase'], @$params[32]));
			// Определить квартал сдачи объекта (Параметр номер 60)
			$obj->setCustomField($type, 'new_quarter', $this->getNewQuarter(@$params[60]));
			// Определить год сдачи объекта (Параметр номер 60)
			$obj->setCustomField($type, 'new_year', $this->getNewYear(@$params[60]));
		}

		if (in_array($type, ['rooms'])) {
			// Определить кол-во комнта учавствующих в сделке
			$obj->setCustomField($type, 'rooms_deal', !empty($objData['rooms_in_deal'])? $objData['rooms_in_deal'] : 0);
		}

		if (in_array($type, ['parts'])) {
			// Размер доли (Параметр номер 79)
			$obj->setCustomField($type, 'part', !empty($params[79])? $params[79] : 0);
			// TODO: реализовать area_part (Площадь доли)
		}

		if (in_array($type, ['cottages', 'commercial'])) {
			// Опреелить есть ли охрана (Параметр номер 64)
			$obj->setCustomField($type, 'guard', $this->getParamValue($this->comparisons['guard'], @$params[64]));
		}

		if (in_array($type, ['cottages'])) {
			// Наличие Газа (Параметр номер 53)
			$obj->setCustomField($type, 'gas', $this->getParamValue($this->comparisons['gas'], @$params[53]));
			// Наличие водоснабжения (Параметр номер 55)
			$obj->setCustomField($type, 'water', $this->getParamValue($this->comparisons['water'], @$params[55]));
			// Наличие электричества (Параметр номер 50)
			$obj->setCustomField($type, 'electro', $this->getParamValue($this->comparisons['electro'], @$params[50]));
			// Площадь дома (Параметр номер 41)
			$obj->setCustomField($type, 'area_house', $this->getArea(@$params[41]));
			// Площадь участка (Параметр номер 42)
			$obj->setCustomField($type, 'area_plot', $this->getAreaPlot(@$params[42]));
			// Тип объекта Дом, Таунхаус ...
			$obj->setCustomField($type, 'obj_type', '10'); // Значение по умолчанию: ДОМ
			if (!empty($objData['subcategory'])) {
				if (strstr('Таунхаус', $objData['subcategory'])) {
					$obj->setCustomField($type, 'obj_type', '40');
				}
			}
			// Наличие канализации (Параметр номер 54)
			$obj->setCustomField($type, 'sewer', $this->getParamValue($this->comparisons['sewer'], @$params[54]));
		}

		if (in_array($type, ['parkings'])) {
            // Высота потолков (Параметр номер 4)
			$obj->setCustomField($type, 'ceiling_height', $this->getArea(@$params[4]));
		}

		if (in_array($type, ['commercial'])) {
			// Определение наличия парковки (Параметры номер 78 и 13)
			$obj->setCustomField($type, 'parking', !empty($params[78])? 1 : (!empty($params[13])? 1 : -1));

			// Определение минимальной и максимальной площадей для коммерческой недвижимости (Параметр номер 6)
			$area_min = 0;
			$area_max = 0;
			$area_min_max = !empty($params[6])? $params[6] : 0;
			if(!empty($area_min_max)) {
				preg_match('/(\d+,?\d?)\s–\s(\d+,?\d?)/', $area_min_max, $matches);
				if (count($matches) > 2) {
					$area_min = $matches[1];
					$area_max = $matches[2];
				}
			}
			$obj->setCustomField($type, 'area_min', $area_min);
			$obj->setCustomField($type, 'area_max', $area_max);
		}

		if (in_array($type, ['business', 'commercial'])) {

			// Устанавливаем тип объекта коммерческой недвижимости
			$objT = $this->getObjectType($objData['subcategory']);
			if (!empty($objT)) {
				$obj->setAdditionallyData($type, 'object_type', $objT);
			} else {
				$objT = $this->getObjectType(@$params[47]);
				if (!empty($objT)) {
					$obj->setAdditionallyData($type, 'object_type', $objT);
				} else {
					$obj->setAdditionallyData($type, 'object_type', 0);
				}
			}
		}

	}


	/**
	 * Определить тип здания (офис, торговая плащадт и т.д.)
	 * @param string $category            - тип обхекта (может быть офис, торговая плащадт и т.д.)
	 * @return bool
	 */
	private function getObjectType($category)
	{
		if (empty($category)) {
			return 0;
		}

		$category = mb_strtolower($category, 'UTF-8');
		foreach ($this->comparisons['object_type'] as $key => $value) {
            if (strpos($key, $category)) {
            	return $value;
			}
		};

        return 0;
	}


	/**
	 * Получить площадь объекта (общая, кухня, жилая)
	 * @param string|int $paramValue - Номер параметра
	 * @return int - Значение площади
	 */
	private function getArea($paramValue)
	{
		$area = !empty($paramValue)? $paramValue : 0;
		if(empty($area)) {
			return 0;
		}
		preg_match('(\d+,?\d?)', $area, $matches);
		return !empty($matches[0])? $matches[0] : 0;
	}


	/**
	 * Определить площадь участка (храним в сотках)
	 * @param string|int $paramValue -  могут поступать значения: 0.15 сот.  либо  1.3 га
	 * @return float
	 */
	private function getAreaPlot($paramValue)
	{
		$area = !empty($paramValue)? $paramValue : 0;
		if(empty($area)) {
			return 0;
		}
		preg_match('(\d+,?\d?)', $area, $matches);
		if (empty($matches[0])) {
			return 0;
	    } else {
			$rez = $matches[0];
		}
		// Переводим в Га в сотки
        if (strstr($area, 'га')) {
			$rez *= 100;
		}
		return $rez;
	}


	/**
	 * Извлекает значение из предопределенного массива по индексу $index.
	 * Если $index не найден в массиве, логируем данное событие. (Возникновение данного события говорит о том, что не все значения определены а массиве )
	 * @param array $data - Массив с предопределенными значениями
	 * @param string $index - Имя индекса в массиве $data
	 * @param string $defIndex - индекс по умолчанию
	 * @return mixed
	 */
	private function getParamValue(array $data, $index, $defIndex = '-')
	{
		$index = ($index !== null)? $index : $defIndex;
		// Если $index не найден в массиве
		if (!isset($data[$index])) {
			// Если параметр не был найден в массиве соответсвий, то логируем данное событие
			// Исключение являются индексы: вторичка, новостройка.
			if (!in_array($index, ['вторичка', 'новостройка'])) {
				Logger::setValue('unknownIndex', [$index => 'Нет параметра ' . $index . ' в массиве comparisons[' . implode(';', array_keys($data)) . ']']);
			}
			return 0;
		}
		return $data[$index];
	}


	/**
	 * Получить тип объекта (деревянный, кирпичный, etc.)
	 * @param int|string $param
	 * @return array|int|string
	 */
	private function getHouseType($param)
	{
		$house_type = !empty($param)? $param : 0;
		if (!empty($house_type)) {
			// Если содержит строку: 'вторичка, деревянный' - то отделяем по запятой тип дома: деревянный
			$house_type = explode(',', $param);
			$house_type = trim($house_type[(count($house_type) > 1)? 1 : 0]);
			$house_type = $this->getParamValue($this->comparisons['house_type'], @$house_type);
		}
		return $house_type;
	}


	/**
	 * Получить квартал завершения строительства объекта
	 * @param int|string $param
	 * @return int
	 */
	private function getNewQuarter($param)
	{
		$quarter = !empty($param)? $param : 0;
		if (!empty($quarter)) {
			if (strstr($quarter, '1 кв.')) {
				$quarter = 1;
			} elseif (strstr($quarter, '2 кв.')) {
				$quarter = 2;
			} elseif (strstr($quarter, '3 кв.')) {
				$quarter = 3;
			} elseif (strstr($quarter, '4 кв.')) {
				$quarter = 4;
			} else {
				$quarter = 0;
			}
		}
		return $quarter;
	}


	/**
	 * Получить год завершения строительства объекта
	 * @param int|string $param
	 * @return int|string
	 */
	private function getNewYear($param)
	{
		$year = !empty($param)? $param : 0;
		if (!empty($year)) {
			preg_match('/(\d+) г/', $year,  $matches);
			$year = !empty($matches[1])? $matches[1] : 0;
			// Если год указан как 17, добавляем 20, что бы получить 2017
			if(strlen($year) == 2) {
				$year = '20' . $year;
			}
            // Если указаный год меньше текущего года, то устанавливаем как 0
			if ($year < date('Y')) {
				$year = 0;
			}
		}
		return $year;
	}


	/**
	 * Определить этаж, на котором находится объек
	 * Могут встречаться выражения: 10
	 *                              -1 / 7
	 *                              -4 из 95
	 *                              1 из 10
	 *                              подвал
	 *                              полуподвал
	 * @param int|string $param
	 * @return array
	 */
	private function getFloor($param)
	{
		$Floors = 0;
		$floor = !empty($param)? $param : 0;
		if (!empty($floor)) {
			// Если подвал или полуподвал
			if ($floor == 'подвал' || $floor == 'полуподвал') {
				$floor = -1;
			} else {
				preg_match('/(\d+)\D*(\d+)?/', trim($floor),  $matches);
				$floor = !empty($matches[1])? $matches[1] : 0;
				$Floors = !empty($matches[2])? $matches[2] : 0;
			}
		}
		return ['floor' => $floor, 'floors' => $Floors];
	}


	/**
	 * Определить кол-во этажей в здании, в котором находится объект
	 * Парсер cian собирает этажность в 2 параметра: 65 и 44. Если эти параметры пусты, пытаемся взять данные из параметра номер 1
	 * @param int|string $params
	 * @return int|mixed
	 */
	private function getFloors($params)
	{
		$floors = !empty($params[65])? $params[65] : (!empty($params[44])? $params[44] : 0);
		if (empty($floors)) {
			$floors = $this->getFloor(@$params[1])['floors'];
		} else {
			preg_match('/(\d+)/', trim($floors), $matches);
			$floors = !empty($matches[1])? $matches[1] : 0;
		}
		return $floors;
	}


	/**
	 * Получить id метро
	 * @param string $data - На входе получаем список метро разделенных символами ## (например: '')
	 * @param int $region_id - Регион в котором расположенно метро
	 * @return int - Возвращаем id метро, либо 0 - если нет метро
	 */
	private function getIdMetro ($data, $region_id)
	{
		if (empty($data) || empty($region_id) || empty($this->comparisons['metro'][$region_id])) {
			return 0;
		}

		// Для регионов: 47, 50 - не существует своих собсвенных массивов метро. Они наследуют метро из регинов: 47 = 78; 50 = 77;
		if (in_array($region_id, [47, 50])) {
			$region_id = $this->comparisons['metro'][$region_id];
		}

		$metroList = array_filter(explode("##", $data));
		if (empty($metroList)) {
			return 0;
		}
		$metroData = array_filter($this->comparisons['metro'][$region_id], function ($arr) use ($metroList) {
			return $arr['name'] == $metroList[0];
		});

		return !empty($metroData)? array_keys($metroData)[0] : 0;
	}


	/**
	 * Подготовка параметров объекта
	 * @param string $data - строка параметров, разделенная символами ##
	 * @return array - отформатированный набор параметров
	 */
	private function prepareParams ($data)
	{
		if (empty($data)) {
			return [];
		}
		$params = [];
		$paramsPairs = explode("##", $data);
		foreach ($paramsPairs as $pair) {
			if (empty($pair)) {
				continue;
			}
			$pairList = explode("~~", $pair);
			if (count($pairList) != 2 || empty($pairList[1])) {
				continue;
			}
			$params[$pairList[0]] = $pairList[1];
		};
		return $params;
	}

}