<?php
/**
 * Author: Rudi
 * Date: 06.04.2017
 * Description: Класс для работы с БД
 */
namespace objects\repository;

require_once (LIB_ROOT."/DbSimple/Generic.php");

use objects\logger\Logger as Logger;

trait Repository
{
	/**
	 * Список таблиц в базе MTK
	 * @var array
	 */
    protected $mtkTableTypes = ['flats', 'commercial', 'parts', 'parkings', 'rooms', 'cottages', 'business'];

	/**
	 * Поля БД для импорта в MTK
	 * @var array
	 */
	protected $mtkFields = array(
		'common' => [
			'date_added',
			'date_renew',
			'date_deleted',
			'deal',
			'status',
			'status_agency',
			'price',
			'price_usd',
			'price_eur',
			'prices_old',
			'currency',
			'phone',
			'description',
			'longitude',
			'latitude',
			'region_id',
			'district',
			'locality',
			'nas_punkt',
			'street',
			'housenumber',
			'note',
			'coords_accuracy'
		],

		'flats' => [
			'fields' => ['type', 'rooms', 'metro_id', 'metro_distance_walk', 'metro_distance_transport', 'floor', 'floors', 'house_type',
				'floor_type', 'area_total', 'area_living', 'area_kitchen', 'balcony', 'phone_on', 'wc_comb_num',
				'wc_sep_num', 'hypothec', 'fridge', 'furniture', 'tv', 'washmach', 'commission_agency', 'commission_client',
				'rent_term', 'repair_condition', 'new_phase', 'new_quarter', 'new_year'
			]
		],

		'rooms' => [
			'fields' => ['type', 'rooms', 'metro_id', 'metro_distance_walk', 'metro_distance_transport', 'floor', 'floors', 'house_type',
				'floor_type', 'area_total', 'area_living', 'area_kitchen', 'balcony', 'phone_on', 'wc_comb_num',
				'wc_sep_num', 'hypothec', 'fridge', 'furniture', 'tv', 'washmach', 'commission_agency', 'commission_client',
				'rent_term', 'rooms_deal', 'repair_condition'
			]
		],

		'parts' => [
			'fields' => ['type', 'rooms', 'metro_id', 'metro_distance_walk', 'metro_distance_transport', 'floor', 'floors', 'house_type',
				'area_total', 'area_living', 'area_kitchen', 'balcony', 'phone_on', 'wc_comb_num', 'wc_sep_num',
				'price', 'part', 'area_part', 'repair_condition'
			]
		],

		'cottages' => [
			'fields' => ['obj_type', 'road', 'mkad_distance', 'area_house', 'area_plot', 'gas', 'water', 'electro',
				'sewer', 'guard', 'wc_comb_num', 'wc_sep_num', 'repair_condition', 'rent_term'
			]
		],

		'commercial' => [
			'fields' => ['metro_id', 'metro_distance_walk', 'metro_distance_transport', 'floor',
				'floors', 'area_min', 'area_max', 'parking', 'guard'
			]
		],

		'business' => [
			'fields' => ['metro_id', 'metro_distance_walk', 'metro_distance_transport', 'profit', 'org_legal_form', 'existence',
				'part', 'proceeds', 'receivables', 'costs', 'payables', 'tax_debt', 'fot'
			]
		],

		'parkings' => [
			'fields' => ['type', 'metro_id', 'metro_distance_walk', 'metro_distance_transport',
				'floor', 'area_total', 'ceiling_height', 'rent_term'
			]
		]
	);

	/**
	 * Получить объект для работы с БД
	 * @return mixed
	 */
	abstract function getDb();

	/**
	 * Получить SQL строку для составления запроса в БД
	 * для занесения объявлений и их фото
	 * @return mixed
	 */
	abstract function getSqlArray();

	/**
	 * Выполнить SQL операции Insert для объявлений и для фото
	 */
	private function doInsert()
	{
		// Вставляем данные НОВЫХ объявлений
		$this->insertObjects();

		// Вставялем фото объявлений
		$this->insertSourcePhotos();
	}


	/**
	 * Выполнить SQL операции Update
	 */
	private function doUpdate()
	{
		// Обновить данные СУЩЕСТВУЮЩИХ объявлений
		$this->updateObjects();

		// Обновить hash данные обновленных объявлений
		$this->updateHashData();
	}


	/**
	 * Вставить НОВОЕ объявление в БД
	 * @return int
	 */
	private function insertObjects()
	{
		// Получаем объект для работы с БД MTK
		if(!$db = $this->getDb()) {
			Logger::error('Repository: Ошибка инициализации БД');
		}

		$arrSqlData = $this->getSqlArray();
		if (empty($arrSqlData['insert']['data'])) {
			return 0;
		}

		// Получаем ТИПЫ объявления (flats, rooms, ...)
		$types = array_keys($arrSqlData['insert']['data']);
		$totalCount = 0; // Общее кол-во вставленных объявлений
		foreach ($types as $type) {
			if (empty($arrSqlData['insert']['data'][$type])) {
				continue;
			}
			$totalCount += $transactionCount = count($arrSqlData['insert']['data'][$type]);
			$arrayValues = array_fill(0, count($arrSqlData['insert']['data'][$type]), '(?a)');
			$insertStr = 'INSERT INTO ?_mtk_objects_' . $type . ' (?#) VALUES ' . implode(', ', $arrayValues);

			// Массив имеет три типа данных. 1 элемент = SQL строка
			//                               2 элемент = Поля БД
			//                               3 элемент = Данные БД
			$args = array_merge([$insertStr, $arrSqlData['insert']['fields'][$type]], $arrSqlData['insert']['data'][$type]);

			// Вставляем объявление в БД. В перемнной $firstId будет хранится id первого вставленного объявления
			$db->transaction();
			$firstId = @call_user_func_array([$db, 'query'], $args);
			if(!empty($db->error) || empty($firstId)) {
				$err = $db->error;
				$db->rollback();
				Logger::error('Ошибка вставки НОВЫХ объявлений в БД. ' . print_r($err, true));
			}
			$db->commit();

			// Заполняем данные для таблицы типа otherSource
			$this->setForeignKey($type, $firstId);

			// Заполняем данные для таблицы типа объекта
			if (in_array($type, ['commercial', 'business'])) {
				$this->setForeignKeyForObjectType($type, $firstId);
				$this->insertObjectType($type);
			}

			// Вставляем данные в таблицу учета типа otherSource
			$this->insertOtherSource($type);
		};

		return $totalCount;
	}


	/**
	 * Обновить СУЩЕСТВУЮЩИЕ объявления в БД
	 * @return int
	 */
	private function updateObjects()
	{
		// Получаем объект для работы с БД MTK
		if(!$db = $this->getDb()) {
			Logger::error('Repository: Ошибка инициализации БД');
		}

		// Получаем массив подготовленных объектов к SQL построению запроса в БД
		$arrSqlStrings = $this->getSqlArray();
		if (empty($arrSqlStrings['fullUpdate']) && empty($arrSqlStrings['dataUpdate'])) {
			return 0;
		}

		$count = 0;
        foreach (['fullUpdate', 'dataUpdate'] as $typeUpdate) {
        	if (empty($arrSqlStrings[$typeUpdate])) {
        		continue;
			}
			// Получяем ТИПы объявления (flats, rooms, etc.)
			$types = array_keys($arrSqlStrings[$typeUpdate]['data']);

			// Перебирвем ТИПы объявления (flats, rooms, etc.)
			foreach ($types as $type) {
				// Поля для БД
				$fields =  $arrSqlStrings[$typeUpdate]['fields'][$type];
				// Готовим имена переменных для: ON DUPLICATE KEY UPDATE
				$onDublicateString = [];
				foreach ($fields as $field) {
					if ($field != 'id') {
						$onDublicateString[] = $field. ' = VALUES(' . $field . ')';
					}
				};

				$count += count($arrSqlStrings[$typeUpdate]['data'][$type]);
				$arrayValues = array_fill(0, count($arrSqlStrings[$typeUpdate]['data'][$type]), '(?a)');
				$insertSourceStr = '
INSERT INTO
    ?_mtk_objects_' . $type . ' (?#) 
VALUES ' . implode(', ', $arrayValues) . ' 
ON DUPLICATE KEY UPDATE ' . implode(', ', $onDublicateString);
				$args = array_merge([$insertSourceStr, $fields], $arrSqlStrings[$typeUpdate]['data'][$type]);
				call_user_func_array([$db, 'query'], $args);
			};
		};

		return $count;
	}


	/**
	 * Обновить hash данные обновленных объявлений
	 * @return bool
	 */
	private function updateHashData()
	{
		// Получаем объект для работы с БД MTK
		if(!$db = $this->getDb()) {
			Logger::error('Repository: Ошибка инициализации БД');
		}

		// Получаем массив подготовленных SQL данных
		$arrSqlStrings = $this->getSqlArray();
		if (empty($arrSqlStrings['hashUpdate'])) {
			return false;
		}

		// Получяем ТИПы (flats, rooms, etc.)
		$types = array_keys($arrSqlStrings['hashUpdate']);

		foreach ($types as $type) {
			if (empty($arrSqlStrings['hashUpdate'][$type])) {
				continue;
			}

			// Получаем массив полей для sql запроса
			$fields = array_keys($arrSqlStrings['hashUpdate'][$type][0]);

			// Готовим имена переменных для: ON DUPLICATE KEY UPDATE
			$onDublicateString = [];
			foreach ($fields as $field) {
				if ($field != 'id') {
					$onDublicateString[] = $field. ' = VALUES(' . $field . ')';
				}
			};

			$arrayValues = array_fill(0, count($this->arrSql['hashUpdate'][$type]), '(?a)');
			$insertSourceStr = '
INSERT INTO
    ?_mtk_objects_'.$type.'__othersource_data (?#)
VALUES ' . implode(', ', $arrayValues) . ' 
ON DUPLICATE KEY UPDATE ' . implode(', ', $onDublicateString);

			// !!! Так как массив ассоциативный, мы сбрасываем имена ключей на цыфры
			$clearKeysData = array_map('array_values', $this->arrSql['hashUpdate'][$type]);

			$args = array_merge([$insertSourceStr, $fields], $clearKeysData);
			call_user_func_array([$db, 'query'], $args);
		};
		return true;
	}


	/**
	 * Вставить фотографии в БД
	 * @return int
	 */
	private function insertSourcePhotos()
	{
		// Получаем объект для работы с БД MTK
		if(!$db = $this->getDb()) {
			Logger::error('Repository: Ошибка инициализации БД');
		}

		$arrSqlStrings = $this->getSqlArray();
		if (empty($arrSqlStrings['insert']['photo'])) {
			return 0;
		}

		// Получяем ТИП объявления
		$types = array_keys($arrSqlStrings['insert']['photo']);

		// Поля для БД
		$fields = ['source', 'source_id', 'list_order', 'url'];

		$count = 0;
		foreach ($types as $type) {
			if (empty($arrSqlStrings['insert']['photo'][$type])) {
				continue;
			}
			$count += count($arrSqlStrings['insert']['photo'][$type]);
			$arrayValues = array_fill(0, count($arrSqlStrings['insert']['photo'][$type]), '(?a)');
			$insertSourceStr = 'INSERT IGNORE INTO ?_mtk_objects_'.$type.'_source_photos (?#) VALUES ' . implode(', ', $arrayValues);
			$args = array_merge([$insertSourceStr, $fields], $arrSqlStrings['insert']['photo'][$type]);
			call_user_func_array([$db, 'query'], $args);
		};

		return $count;
	}


	/**
	 * Получить массив фотографий из базы MTK
	 * @param array $params - массив source_id
	 * @return array
	 */
	private function getMtkPhoto(array $params)
	{
		// Проверка аргументов
		if (empty($params['type']) || empty($params['ids'])) {
			Logger::error('Repository:getMtkPhoto: Не верный список параметров.');
		}

		// Получаем объект для работы с БД MTK
		if (!$db = $this->getDb()) {
			Logger::error('Repository: Ошибка инициализации БД');
		}

		return $db->select('
SELECT
    url AS ARRAY_KEY, id, source_id, list_order
FROM
    ?_?#
WHERE
    source_id IN (?a)',
			'mtk_objects_' . $params['type'] . '_source_photos' ,
			$params['ids']);
	}


	/**
	 * Удалить фотографии объявления
	 * @param array $params
	 */
	public function deletePhoto(array $params = [])
	{
		// Проверка аргументов
		if (empty($params['type']) || empty($params['arrPhoto'])) {
			Logger::error('Repository:deletePhoto: Не верный список параметров.');
		}

		// Получаем объект для работы с БД MTK
		if (!$db = $this->getDb()) {
			Logger::error('Repository: Ошибка инициализации БД');
		}

		$ids       = [];
		$sourceIds = [];
		// Получаем id фото а базе MTK (не путать с source_id)
		foreach ($params['arrPhoto'] as $photo) {
			$ids[] = $photo['id'];                                    // Массив для удаления фото из таблицы source_photo
			$sourceIds[$photo['source_id']][] = $photo['list_order']; // Массив для уадления фото из таблицы _photo
		};

		// Удаление из базы source
		$db->query('
DELETE FROM
    ?_?#
WHERE
    id IN (?a)',
			'mtk_objects_' . $params['type'] . '_source_photos',
			$ids);

		// Удаление из базы photos
		foreach ($sourceIds as $sourceId => $arrListOrder) {
			$db->query('
DELETE FROM
    ?_?#
WHERE
    object_id = ?d
AND
    list_order IN (?a)',
				'mtk_objects_' . $params['type'] . '_photos',
				$sourceId,
				$arrListOrder);
		};
	}


	/**
	 * Инициализация id источника
	 * @param string $source_name
	 * @return array|null
	 */
	public function getSourceData($source_name)
	{
		if (empty($source_name)) {
			Logger::error('Repository: Ошибка Инициализация id источника. Не получили имя источника.');
			return null;
		}

		// Имя таблицы, в которой храниться список источников
		$otherSourcesTable = 'mtk_othersources';

		// Получаем объект для работы с БД MTK
		if (!$db = $this->getDb()) {
			Logger::error('Repository: Ошибка инициализации БД');
		}

		$sourceData = $db->selectRow('
SELECT
    *
FROM
    ?_?#
WHERE
    source_name = ?',
			$otherSourcesTable,
		    strtolower($source_name)
			);

		if (empty($sourceData)) {
			Logger::error('Repository: Ошибка. Имя источника: ' . $source_name . ' не обнаружена в таблице источников импорта: ' . $otherSourcesTable);
			return null;
		}

		return $sourceData;
	}


	/**
	 * Деактивация объявлений в базе MTK
	 * @param array $ids - Список id которые следует деактивировать
	 * @param string $tableType - Имя таблицы, в которой следует деактивировать объявления
	 * @return array|bool|null|void
	 */
	public function deactivateMtkObjects(array $ids = null, $tableType = null)
	{
		if (empty($ids) || empty($tableType)) {
			Logger::error('Ошибка. Деактивация MTK обяъвлений получила пустые параметры.');
		}

		// Получаем объект для работы с БД MTK
		if (!$db = $this->getDb()) {
			Logger::error('Repository: Ошибка инициализации БД');
		}

		$db->query('
UPDATE
    ?_?#
SET
    `date_deleted` = NOW()
WHERE
    `id` IN (?a)',
			'mtk_objects_' . $tableType,
			$ids);

		// Сбрасываем хэш данных при деактивации, это нужно для того, что бы данные объявления могли обрабатываться при его повторном включении
		$emptyHash = md5(1);
		$db->query('
UPDATE
    ?_?#
SET
    `data_hash` = ?
WHERE
    `object_id` IN (?a)',
			'mtk_objects_' . $tableType . '__othersource_data',
			$emptyHash,
			$ids);
	}


	/**
	 * Получить id ранее уже импортированных объявлений, расположенные в базе MTK.
	 * Так как объекты хранятся в разных таблицах (flats, commercial и т.д.),
	 * то следует пройти по всем таблицам объявлений (их список здесь Repository::$mtkTableTypes).
	 * @param array $params - Массив аргументов, может содержать следующее:
	 *                                                 $params = [
	 *                                                       'source_id' => 1, // id источника (не обязательный параметр)
	 *                                                       'active' => 1,    // Флаг выборки только АКТИВНЫХ объявлений (date_deleted IS NULL)
	 *                                                       'filter' => [     // Массив id, выборку которых следует сделать (при их наличии)
	 *                                                            'flats'   => [111, 222, 333, ...],
	 *                                                            'rooms'   => [111, 222, 333, ...],
	 *                                                            'cottage' => [111, 222, 333, ...],
	 *                                                             ...
	 *                                                        ]
	 *                                                 ];
	 * @return array - Возвращает массив значений из таблиц типа otherSource
	 */
	public function getMtkIds(array $params)
	{
		// Получаем объект для работы с БД MTK
		if (!$db = $this->getDb()) {
			Logger::error('Repository: Ошибка инициализации БД');
		}
        if (!empty($params['source_id'])) {
			$this->source_id = $params['source_id'];
		}
		if (empty($this->source_id)) {
			Logger::error('Ошибка. Не смогли получить объявления в базе MTK. Метод getMtkIds обнаружил непроинициализированный source_id.');
		}

		$mtkIds = [];

		// Перебираем типы таблиц (flats, commercial и т.д.)
		foreach ($this->mtkTableTypes as $tableType) {
			$mtkIds[$tableType] = $db->select('
SELECT
    source.*, source.source_object_id AS ARRAY_KEY
FROM
    ?_?# source
LEFT JOIN
    ?_?# mtk
ON
    source.object_id = mtk.id
WHERE
    source.source_id = ?d
{AND
    1 = ?d
AND
    mtk.date_deleted IS NULL}
{AND
    source.source_object_id IN (?a)}',
				'mtk_objects_' . $tableType . '__othersource_data',
				'mtk_objects_'. $tableType,
				$this->source_id,
				empty($params['active'])? \DBSIMPLE_SKIP : 1,
				empty($params['filter'])? \DBSIMPLE_SKIP : (!empty($params['filter'][$tableType])? $params['filter'][$tableType] : [0]));
		};

		return $mtkIds;
	}


	/**
	 * Проставляем значения для внешних ключей для таблицы типа otherSource
	 * @param string $type - Тип объявления (flats, rooms, ...)
	 * @param int $firstId - Значение ПЕРВОГО ключа. Его мы получаем через вставку мультистрок объявлений (функция: insertObjects)
	 */
    private function setForeignKey($type, $firstId)
	{
        foreach ($this->arrSql['insert']['hash'][$type] as &$hashData) {
			$hashData['object_id'] = $firstId;
			$firstId ++;
		};
		unset($hashData);
	}


    private function setForeignKeyForObjectType($type, $firstId)
	{
		foreach ($this->arrSql['insert']['objType'][$type] as &$data) {
			$data['object_id'] = $firstId;
			$firstId ++;
		};
		unset($data);
	}


	/**
	 * Ф-ция вставляет данные об импортированных объявлениях в таблицу типа otherSource
	 * @param string $type - тип таблицы (flats, rooms, ...)
	 * @return bool
	 */
    private function insertOtherSource($type)
	{
		if (empty($this->arrSql['insert']['hash'][$type])) {
			return false;
		}

		// Получаем объект для работы с БД MTK
		if(!$db = $this->getDb()) {
			Logger::error('Repository: Ошибка инициализации БД');
		}

		// Получаем массив полей для sql запроса
		$fields = array_keys($this->arrSql['insert']['hash'][$type][0]);

		// Строим запрос
		$arrayValues = array_fill(0, count($this->arrSql['insert']['hash'][$type]), '(?a)');
		$insertSourceStr = 'INSERT IGNORE INTO ?_mtk_objects_' . $type . '__othersource_data (?#) VALUES ' . implode(', ', $arrayValues);

	    // !!! Так как массив ассоциативный, мы сбрасываем имена ключей на цыфры
		$clearKeysData = array_map('array_values', $this->arrSql['insert']['hash'][$type]);

		$args = array_merge([$insertSourceStr, $fields], $clearKeysData);

		return call_user_func_array([$db, 'query'], $args);
	}


	/**
	 * Вставляем в БД тип объекта. Применимо для коммерческой и бизнес недвижимости
	 * @param string $type - тип данных (commercial, business, etc.)
	 * @return bool
	 */
    private function insertObjectType($type)
	{
		if (empty($this->arrSql['insert']['objType'][$type])) {
			return false;
		}

		// Получаем массив полей для sql запроса
		$fields = array_keys($this->arrSql['insert']['objType'][$type][0]);

		// Удаляем пустые значения object_type
		foreach ($this->arrSql['insert']['objType'][$type] as $key => $val) {
			if (empty($val['commercial_type_id'])) {
				unset($this->arrSql['insert']['objType'][$type][$key]);
			}
		};

		// Перепроверяем данные на пустоту
		if (empty($this->arrSql['insert']['objType'][$type])) {
			return false;
		}

		// Получаем объект для работы с БД MTK
		if(!$db = $this->getDb()) {
			Logger::error('Repository: Ошибка инициализации БД');
		}

		// Строим запрос
		$arrayValues = array_fill(0, count($this->arrSql['insert']['objType'][$type]), '(?a)');
		$insertSourceStr = 'INSERT IGNORE INTO ?_mtk_objects_' . $type . '_types (?#) VALUES ' . implode(', ', $arrayValues);

		// !!! Так как массив ассоциативный, мы сбрасываем имена ключей на цыфры
		$clearKeysData = array_map('array_values', $this->arrSql['insert']['objType'][$type]);

		$args = array_merge([$insertSourceStr, $fields], $clearKeysData);

		return call_user_func_array([$db, 'query'], $args);
	}

}