<?php
/**
 * Данный класс реализует работу с импортом объявлений из avito.
 * Класс реализует работу с БД, где хранятся данные для импорта.
 * В данном классе создается и инициализируется объект объявления.
 * User: Rudi
 * Date: 03.07.2017
 */
namespace importer\avito;

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

class Avito extends mtk_objects implements Import
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
	 * Объект для работы с БД Avito
	 * @var \DbSimple_Mysql
	 */
	private $aDb;

	/**
	 * Название БД, в которой хранятся таблицы для cian
	 * @var
	 */
	private $nameAvitoDb = 'rlt_avito';

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
	 * Спарсенные avito объявления, которые хранятся в таблице `rlt_avito.advert`
	 * Данный список id - подлежащит импортированию.
	 * @var array
	 */
	private $avitoIds = [];

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
	 * Массив хранит данные полученные из базы avito
	 * Из этих данных сформируются объекты объявления
	 * @var array
	 */
	private $arrObj = [];

	/**
	 * Имя источника импорта
	 * @var string
	 */
	private $importName = 'avito';

	/**
	 * id источника импорта
	 * @var
	 */
	private $source_id;

	/**
	 * Avito constructor.
	 * @param null $conf
	 */
	public function __construct($conf = null)
	{
		if ($conf === null) {
			Logger::error('Ошибка. Не передали в конструктор импорта конфигурацию.');
		}

		parent::__construct();

		// Конфигурация
		$this->config = $conf;

		global $db;
		$this->db = $db;
		$this->db->query('SET group_concat_max_len = 10000;');

		// Соединение с базой avito
		//$this->aDb = \DbSimple_Generic::connect("mysql://root@localhost/rlt_avito");
		$this->aDb = \DbSimple_Generic::connect("{$this->config['db']['dsn']}://{$this->config['db']['username']}:{$this->config['db']['password']}@{$this->config['db']['host']}/{$this->config['db']['dbname']}");

		@$this->aDb->setErrorHandler('dbError');
		if (!empty($this->aDb->error)) {
			Logger::error('Ошибка подключения к БД ' . $this->nameAvitoDb . '<br>' . print_r($this->aDb->error, true));
		}
		@$this->aDb->query("SET NAMES {$this->config['db']['charset']}");
		$this->aDb->query('SET group_concat_max_len = 10000;');

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
			Logger::error('Ошибка инициализации id источника. Метод Avito::setSourceId');
		}
		$this->source_id = $sourceId;
		return true;
	}

	/**
	 * Инициализация карты соответсвий
	 */
	private function initComparisons()
	{
		// Карта соответствия типов (AVITO => MTK) - используется для именования таблиц
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
			'rent' => 20,
			'want_rent' => 0
		];

		// Срок аренды
		$this->comparisons['rent_term'] = [
			'на длительный срок' => 140,
			'посуточно'          => 110,
			'-'                  => 0
		];

		// Тип объекта
		$this->comparisons['object_type'] = [
			'вторичка' => 0,
			'гостиница' => -1,
			'дача' => 70,
			'дом' => 10,
			'коттедж' => 30,
			'таунхаус' => 40,
			'новостройка' => 30,
			'офисное помещение' => 200,
			'помещение общественного питания' => 150,
			'помещение свободного назначения' => 140,
			'производственное помещение' => 240,
			'складское помещение' => 280,
			'торговое помещение' => 300,
			'земельные участки' => 110,
			'-' => 0
		];

		// Карта соответсвия метро
		$this->comparisons['metro'] = mtk_objects::getGlobalStatic("metro");

		// Тип здания
		$this->comparisons['house_type'] = [
			'блочный'       => 110,
			'деревянный'    => 180,
			'кирпичный'     => 120,
			'монолитный'    => 150,
			'панельный'     => 130,
			'-'             => 0
		];

		// Карта соответствий регионов
		$this->comparisons['region_id'] = [
			'1'  => '78', // Санкт-Петербург
			'2'  => '47', // Ленинградская область
			'3'  => '24', // Красноярский край
			'4'  => '23', // Краснодарский край
			'5'  => '16', // Республика Татарстан
			'6'  => '29', // Архангельская область
			'7'  => '11', // Республика Коми
			'8'  => '42', // Кемеровская область
			'9'  => '54', // Новосибирская область
			'10' => '72', // Тюменская область
			'11' => '58', // Пензенская область
			'12' => '52', // Нижегородская область
			'13' => '7',  // Кабардино-Балкарская Республика
			'14' => '86', // Ханты-Мансийский автономный округ
			'15' => '15', // Республика Северная Осетия - Алания
			'16' => '50', // Московская область
			'17' => '34', // Волгоградская область
			'18' => '53', // Новгородская область
			'19' => '63', // Самарская область
			'20' => '13', // Республика Мордовия
			'21' => '22', // Алтайский край
			'22' => '32', // Брянская область
			'23' => '61', // Ростовская область
			'24' => '2',  // Республика Башкортостан
			'25' => '73', // Ульяновская область
			'26' => '18', // Удмуртская Республика
			'27' => '76', // Ярославская область
			'28' => '74', // Челябинская область
			'29' => '77', // Москва
			'30' => '66', // Свердловская область
			'31' => '35', // Вологодская область
			'32' => '56', // Оренбургская область
			'33' => '91', // Крым
			'34' => '37', // Ивановская область
			'35' => '38', // Иркутская область
			'36' => '55', // Омская область
			'37' => '89', // Ямало-Ненецкий автономный округ
			'38' => '75', // Забайкальский край
			'39' => '67', // Смоленская область
			'40' => '60', // Псковская область
			'41' => '10', // Республика Карелия
			'42' => '27', // Хабаровский край
			'43' => '5',  // Республика Дагестан
			'44' => '39', // Калининградская область
			'45' => '12', // Республика Марий Эл
			'46' => '3',  // Республика Бурятия
			'47' => '71', // Тульская область
			'48' => '28', // Амурская область
			'49' => '59', // Пермский край
			'50' => '43', // Кировская область
			'51' => '57', // Орловская область
			'52' => '79', // Еврейская АО
			'53' => '46', // Курская область
			'54' => '19', // Республика Хакасия
			'55' => '62', // Рязанская область
			'56' => '8',  // Республика Калмыкия
			'57' => '40', // Калужская область
			'58' => '69', // Тверская область
			'59' => '64', // Саратовская область
			'60' => '33', // Владимирская область
			'61' => '30', // Астраханская область
			'62' => '36', // Воронежская область
			'63' => '48', // Липецкая область
			'64' => '21', // Чувашская Республика
			'65' => '26', // Ставропольский край
			'66' => '4',  // Республика Алтай
			'67' => '20', // Чеченская Республика
			'68' => '68', // Тамбовская область
			'69' => '51', // Мурманская область
			'70' => '31', // Белгородская область
			'71' => '44', // Костромская область
			'72' => '70', // Томская область
			'73' => '45', // Курганская область
			'74' => '14', // Республика Саха (Якутия)
			'75' => '1',  // Республика Адыгея
			'76' => '41', // Камчатский край
			'77' => '49', // Магаданская область
			'78' => '9',  // Карачаево-Черкесская Республика
			'79' => '25', // Приморский край
			'80' => '17', // Республика Тыва
			'81' => '65', // Сахалинская область
			'82' => '6',  // Ингушетия
			'83' => '83', // Ненецкий АО
			'84' => '87'  // Чукотский АО
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
	 * Получить id спарсенных объявлений из базы avito, подлежащих импортированию в базу MTK.
	 * Нас интересуют те объявления, для которых не проставлен статус: УДАЛЕН
	 * @return array
	 */
	public function initImportIds()
	{
		foreach ($this->comparisons['tables'] as $avitoType => $mtkType) {
			// Получить объявления
			$this->avitoIds[$mtkType] = $this->aDb->selectCol('
SELECT
    `id`
FROM
    ?#
WHERE
    `type` = ?
AND
    `is_closed` = 0',
				'advert',
				$avitoType
			);
		};

		return $this->avitoIds;
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
		return $this->avitoIds;
	}


	/**
	 * Выборка из БД avito. Получяем ВСЕ данные об объявлениях.
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

		// Получаем объекты и их спарсенные данные из базы avito
		$this->arrObj = [];
		$this->arrObj = $this->aDb->select('
SELECT
    adv.`id` AS ARRAY_KEY, adv.*, 
    GROUP_CONCAT(DISTINCT img.url SEPARATOR "##") AS images, -- фотографии (может быть несколько, разделяем ## )
    GROUP_CONCAT(DISTINCT met.name SEPARATOR "##") AS metro, -- метро (может быть несколько, разделяем ## )
    GROUP_CONCAT(DISTINCT CONCAT(param.param_id, "~~", param.value) SEPARATOR "##") AS params, -- параметры (может быть несколько, разделяем ##. Внутренний разделитель 2-а знака тильды ~~)
    GROUP_CONCAT(DISTINCT ph.phone SEPARATOR "##") AS phones, -- телефон (может быть несколько, разделяем ## )
    ct.name AS cityName, -- имя города
    ct.region_id AS avito_region_id, -- id региона в рамках cian
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
	 * @param array $objData - Данные полученные из спарсенной базы avito
	 * @return \objects\object\Object - Возвращаем объект объявления
	 */
	private function createOneObj(array $objData)
	{
		// Если это Крым - то не обрабатываем объявление
//		if (in_array($objData['avito_region_id'], [33])) {
//			$errorStr = 'Тип объявления: ' . $this->comparisons['tables'][$objData['type']] . ' id: ' . $objData['id'] . ' Регион КРЫМ';
//			Logger::setValue('validate', ['ERROR_REGION_CRIMEA' => $errorStr]);
//			return null;
//		}

		// Отбрасываем покупателей
		if ($objData['name'] == 'Покупатель') {
			$errorStr = 'Тип объявления: ' . $this->comparisons['tables'][$objData['type']] . ' id: ' . $objData['id'] . ' отбрасываем ПОКУПАТЕЛЕЙ';
			Logger::setValue('excludeObj', ['DROP_DEMAND' => $errorStr]);
			return null;
		}

		// Если не указан тип сделки (act) и поле name == 'Покупатель'
		// или если тип сделки act == 'want_rent'
		// То отбрасываем такое объявление
		if (empty($objData['act'])) {
			if ($objData['name'] == 'Покупатель') { // Отбрасываем объявления спроса
				$errorStr = 'Тип объявления: ' . $this->comparisons['tables'][$objData['type']] . ' id: ' . $objData['id'] . ' отбрасываем СПРОС';
				Logger::setValue('excludeObj', ['DROP_DEMAND' => $errorStr]);
				return null;
			} else {
				// Если Москва или СПБ
				if (in_array($objData['city_id'], [53, 3])) {
					if ($objData['price'] > 1000000) {
						$objData['act'] = 'sale';
					} else {
						$objData['act'] = 'rent';
					}
				} else {
					if ($objData['price'] > 200000) {
						$objData['act'] = 'sale';
					} else {
						$objData['act'] = 'rent';
					}
				}
			}
		} elseif ($objData['act'] == 'want_rent') { // Отбрасываем объявления спроса
			$errorStr = 'Тип объявления: ' . $this->comparisons['tables'][$objData['type']] . ' id: ' . $objData['id'] . ' отбрасываем СПРОС';
			Logger::setValue('excludeObj', ['DROP_DEMAND' => $errorStr]);
			return null;
		}

		// Если гостиница (-1), то не обрабатываем
		if (!empty($objData['subcategory']) && $this->getObjectType($objData['subcategory']) < 0) {
			$errorStr = 'Тип объявления: ' . $this->comparisons['tables'][$objData['type']] . ' id: ' . $objData['id'] . ' отбрасываем ГОСТИНИЦУ';
			Logger::setValue('excludeObj', ['DROP_HOSPITAL' => $errorStr]);
			return null;
		}

		$obj = new Object($this->config['validate']);

		// Инициалзируем технические данные объявления:
		// Тип спарсенного объявления (flats, rooms, cottages, commercial)
		$obj->setServiceField('type', $this->getParamValue($this->comparisons['tables'], $objData['type']));
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
		// Дата создания объявления (дата берется из avito базы)
		$objData['created_date'] = empty($objData['created_date'])? date('Y-m-d H:i:s') : $objData['created_date'];
		$obj->setCommonField('date_added', $objData['created_date']);
		// Дата обновления
		$objData['updated_date'] = empty($objData['updated_date'])? $objData['created_date'] : $objData['updated_date'];
		$obj->setCommonField('date_renew', $objData['updated_date']);
		// Дата деактивации объявления
		$obj->setCommonField('date_deleted', empty($objData['is_closed'])? null : date('Y-m-d H:i:s'));
		// Тип сделки (продажа - 10, аренда - 20)
		$obj->setCommonField('deal', $this->getParamValue($this->comparisons['deal'], $objData['act']));
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
		// Так как координаты нам отдает avito, мы устанавливаем точность координат уникальным и неповторяющимся значением: 32000
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
		$obj->setCommonField('region_id',  $this->getParamValue($this->comparisons['region_id'], @$objData['avito_region_id']));

		// Инициализация данных, которые относятся к конкретному типу объекта (flats, rooms, cottages, commercial)
		$this->setTypeData($obj, $objData, $this->getParamValue($this->comparisons['tables'], $objData['type']));

		// Разбиваем полный адрес на части.
		// Разбивать адрес будем только после срабатывания функции $this->setTypeData, потому как при разбивки адреса используется $type (flats, rooms etc.)
		$concatAddress = implode(', ', array_filter([$objData['regionName'], $objData['cityName'], $objData['address']]));

		// Пытаемся взять адрес геокодера ТОЛЬКО из кеша !!!
		$cacheGeoCoderId = \mtk_address_geocoder::fetchData($concatAddress, true);
		if ($cacheGeoCoderId === false) {
			// Так как нет адреса в кеше ГеоКодера, работаем с адресом своими силами. Разбиваем адреса на части.
			// В будущем импортер будет использовать hash распарсенного адреса для определения изменилось ли объявление при новом импорте.
			if (!$obj->separateAddress($concatAddress, $this->comparisons['tables'][$objData['type']])) {
				$errorStr = 'Тип объявления: ' . $this->comparisons['tables'][$objData['type']] . ' id: ' . $objData['id'] . ' Не полный адрес: ' . $objData['address'];
				Logger::setValue('validate', ['ERROR_ADDRESS' => $errorStr]);
				unset ($obj);
				return null;
			}
		} else {
			// Если мы тут, значит геокодер отдал положительный результат, далее работаем с кешем геокодера.
			// Получаем данные адреса объявления из таблицы: mtk_addresses
			$mtk_address = \mtk_addresses::manager()->get($cacheGeoCoderId);

			// Сохраняем в массив $arrayAddress данные адреса для их сохранения в аблицы, например: mtk_objects_flats
			$arrayAddress = array_fill_keys(['district', 'locality', 'nas_punkt', 'street', 'housenumber'], '');
			// district
			if (!in_array($mtk_address->region_id, [77, 78]) && !empty($mtk_address->level6_id)) {
				$arrayAddress['district'] = $mtk_address->getLevel(6)['name'];
			}
			// locality
			if (!empty($mtk_address->level8_id)) {
				$arrayAddress['locality'] = $mtk_address->getLevel(8)['name'];
			}
			// nas_punkt
			$arrayAddress['nas_punkt'] = $mtk_address->locality? : '';
			// street
			$arrayAddress['street'] = $mtk_address->street? : ($mtk_address->district? : '');
			// housenumber
			$arrayAddress['housenumber'] = $mtk_address->house_number? : '';

			$obj->setCommonField('district',    $arrayAddress['district']);
			$obj->setCommonField('locality',    $arrayAddress['locality']);
			$obj->setCommonField('nas_punkt',   $arrayAddress['nas_punkt']);
			$obj->setCommonField('street',      $arrayAddress['street']);
			$obj->setCommonField('housenumber', $arrayAddress['housenumber']);

			// Для Москвы и СПБ - удаляем district
			if (in_array($mtk_address->region_id, [77, 78])) {
				$obj->setCommonField('district', '');
			}
		}

		return $obj;
	}


	/**
	 * Инициализация данных, которые относятся к конкретному типу объекта (flats, rooms, cottages, commercial)
	 * @param \objects\object\Object $obj - Объект объявления
	 * @param array $objData - Исходные данные из спарсенной базы avito
	 * @param string $type - Тип объявления (flats, rooms, cottages, commercial)
	 */
	private function setTypeData(&$obj, array $objData, $type)
	{
		// Предварительная подготовка параметров объекта
		$params = $this->prepareParams($objData['params']);

		if (in_array($type, ['flats', 'rooms'])) {
			// TODO: Парсер avito не предоставляет подобной информации
			$items = ['tv', 'washmach', 'fridge', 'hypothec', 'floor_type', 'furniture'];
			foreach($items as $item) {
				$obj->setCustomField($type, $item, 0);
			}
			// commission_agency (комиссия агентсва)
			$obj->setCustomField($type, 'commission_agency', !empty($params[16])? $params[16] : 0);
			// commission_client (депозит) (умножаяем на 100, так как в БД: 1 = 100%)
			$obj->setCustomField($type, 'commission_client', !empty($params[17])? $params[17] * 100 : (!empty($params[30])? $params[30] * 100 : 0));
		}

		if (in_array($type, ['flats', 'rooms', 'parts', 'commercial', 'business', 'parkings'])) {
			// TODO: Парсер avito не предоставляет подобной информации
			$items = ['metro_distance_walk', 'metro_distance_transport'];
			foreach($items as $item) {
				$obj->setCustomField($type, $item, 0);
			}
			// Получить id метро
			$obj->setCustomField($type, 'metro_id', !empty($objData['metro'])? $this->getIdMetro($objData['metro'], $obj->getValue('common', 'region_id')) : 0);
		}

		if (in_array($type, ['flats', 'rooms', 'parts', 'commercial', 'parkings'])) {
			// Определить этаж, на котором находится объект
			$obj->setCustomField($type, 'floor', !empty($params[15])? $params[15] : 0);
		}

		// Номера параметров площади и их значения (7 = все m2, 8 = commerce m2, 11 = land сот, 28 = все сот)
		if (in_array($type, ['flats', 'rooms', 'parts', 'parkings'])) {
			// Берем из параметра номер 7
			$obj->setCustomField($type, 'area_total', !empty($params[7])? $params[7] : 0);
			// Является ли объект НОВОСТРОЙКОЙ
			$obj->setCustomField($type, 'type', strstr(mb_strtolower(!empty($objData['subcategory'])? $objData['subcategory'] : '', 'UTF-8'), 'новостройка')? 30 : 0);
		}

		// Этажность для house (param = 13)
		// Этажность для всех (включая house) (param = 19)
		if (in_array($type, ['flats', 'rooms', 'parts', 'commercial'])) {
			// Определить кол-во этажей в здании, в котором находится объект
			$obj->setCustomField($type, 'floors', !empty($params[19])? $params[19] : 0);
		}

		if (in_array($type, ['flats', 'rooms', 'parts'])) {
			// TODO: Парсер avito не предоставляет подобной информации
			$items = ['balcony', 'phone_on'];
			foreach($items as $item) {
				$obj->setCustomField($type, $item, 0);
			};
			// Кол-во комнат в объекте
			$obj->setCustomField($type, 'rooms', !empty($objData['rooms'])? $objData['rooms'] : 0);
			// Определение жилой площади объекта (Параметр номер 6)
			$obj->setCustomField($type, 'area_living', !empty($params[6])? $params[6] : 0);
			// Определение площади кухни объекта (Параметр номер 5)
			$obj->setCustomField($type, 'area_kitchen', !empty($params[5])? $params[5] : 0);
			// Определить тип здания (блочный, монолитный etc.)(Параметр номер 4)
			$obj->setCustomField($type, 'house_type', $this->getParamValue($this->comparisons['house_type'], @$params[4]));
		}

		if (in_array($type, ['flats', 'rooms', 'parts', 'cottages'])) {
			// TODO: Парсер avito не предоставляет подобной информации
			$items = ['wc_comb_num', 'wc_sep_num', 'repair_condition'];
			foreach($items as $item) {
				$obj->setCustomField($type, $item, 0);
			};
		}

		if (in_array($type, ['flats', 'rooms', 'cottages', 'parkings'])) {
			// Определить длительность аренды (Параметр номер 3)
			$obj->setCustomField($type, 'rent_term', $this->getParamValue($this->comparisons['rent_term'], @$params[3]));
		}

		if (in_array($type, ['flats'])) {
			// TODO: Парсер avito не предоставляет подобной информации
			$items = ['new_phase', 'new_quarter', 'new_year'];
			foreach($items as $item) {
				$obj->setCustomField($type, $item, 0);
			};
		}

		if (in_array($type, ['rooms'])) {
			// Определить кол-во комнат учавствующих в сделке
			$obj->setCustomField($type, 'rooms_deal', !empty($objData['rooms_in_deal'])? $objData['rooms_in_deal'] : 0);
		}

		if (in_array($type, ['parts'])) {
			// TODO: Парсер avito не предоставляет подобной информации
			$items = ['part', 'area_part'];
			foreach($items as $item) {
				$obj->setCustomField($type, $item, 0);
			};
		}

		if (in_array($type, ['cottages', 'commercial'])) {
			// TODO: Парсер avito не предоставляет подобной информации
			$items = ['guard'];
			foreach($items as $item) {
				$obj->setCustomField($type, $item, 0);
			};
		}

		if (in_array($type, ['cottages'])) {
			// TODO: Парсер avito не предоставляет подобной информации
			$items = ['gas', 'water', 'electro', 'sewer'];
			foreach($items as $item) {
				$obj->setCustomField($type, $item, 0);
			};
			// mkad_distance
			$obj->setCustomField($type, 'mkad_distance', !empty($params[8]) && is_numeric($params[8])? $params[8] : 0);
            // floors
			//$obj->setCustomField($type, 'floors', !empty($params[13])? $params[13] : (!empty($params[19])? $params[19] : 0));
			// Площадь дома (7 = все m2, 8 = commerce m2, 11 = land сот, 28 = все сот)
			$obj->setCustomField($type, 'area_house', !empty($params[7])? $params[7] : 0);
			// Площадь участка
			$obj->setCustomField($type, 'area_plot', $this->getAreaPlot(@$params[28]));
			// Тип объекта
			if (empty($objT = $this->getObjectType($objData['subcategory']))) {
				if (empty($objT = $this->getObjectType(@$params[12]))) {
					if (empty($objT = $this->getObjectType(@$params[1]))) {
						$objT = 10; // По умолчанию ДОМ
					}
				}
			}
			$obj->setCustomField($type, 'obj_type', $objT);
		}

		if (in_array($type, ['parkings'])) {
			// TODO: Парсер avito не предоставляет подобной информации
			$items = ['ceiling_height']; // Высота потолков
			foreach($items as $item) {
				$obj->setCustomField($type, $item, 0);
			};
		}

		if (in_array($type, ['commercial'])) {
			// TODO: Парсер avito не предоставляет подобной информации
			$items = ['parking', 'area_min', 'area_max'];
			foreach($items as $item) {
				$obj->setCustomField($type, $item, 0);
			};
			// Площадь: area_min, area_max
			if (!empty($params[18]) && is_numeric($params[18]) && $params[18] > 0) {
				$obj->setCustomField($type, 'area_min', $params[18]);
				$obj->setCustomField($type, 'area_max', $params[18]);
			}
		}

		if (in_array($type, ['business', 'commercial'])) {
			// Устанавливаем тип объекта коммерческой недвижимости
			if (empty($objT = $this->getObjectType($objData['subcategory']))) {
				if (empty($objT = $this->getObjectType(@$params[12]))) {
					if (empty($objT = $this->getObjectType(@$params[1]))) {
						$objT = 0;
					}
				}
			}
			$obj->setAdditionallyData($type, 'object_type', $objT);
		}
	}


	/**
	 * Определить тип здания (офис, торговая плащадт и т.д.)
	 * @param string $category - тип обхекта (может быть офис, торговая плащадт и т.д.)
	 * @return bool
	 */
	private function getObjectType($category)
	{
		if (empty($category)) {
			return 0;
		}

		$category = mb_strtolower($category, 'UTF-8');
		if (isset($this->comparisons['object_type'][$category])) {
			return $this->comparisons['object_type'][$category];
		}

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
		$index = ($index !== null)? mb_strtolower($index, 'UTF-8') : $defIndex;
		// Если $index не найден в массиве
		if (!isset($data[$index])) {
			// Если параметр не был найден в массиве соответсвий, то логируем данное событие
			Logger::setValue('unknownIndex', [$index => 'Нет параметра ' . $index . ' в массиве comparisons[' . implode(';', array_keys($data)) . ']']);
			return 0;
		}
		return $data[$index];
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