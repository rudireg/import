<?php
/**
 * Класс является менеджером, который управляет классом импорта.
 * Author: Rudi
 * Date: 06.04.2017
 */
namespace objects\manager;

require_once (__DIR__ . '/../objects/repository.php');

use import_interface\import\Import as Import;
use objects\repository\Repository as Repository;
use objects\object\Object as Object;
use objects\logger\Logger as Logger;

class Manager
{
	/**
	 * Подключаем trait для работы с общими функциями БД
	 */
	use Repository;

	/**
	 * Объект для работы с БД MTK
	 * @var \DbSimple_Mysql
	 */
	private $db;

	/**
	 * Экземпляр класса импорта
	 * @var Import|null
	 */
	private $import;

	/**
	 * Константа - признак нового объявления
	 */
	const NEW_OBJ = 'NEW';

	/**
	 * Константа - признак старого объявления
	 */
	const OLD_OBJ = 'OLD';

	/**
	 * Константы для разделения типа обновления
	 */
	const FULL_UPDATE  = 'FULL_UPDATE';
	const DATA_UPDATE  = 'DATA_UPDATE';
	const NOT_UPDATE   = 'NOT_UPDATE';
	const ERROR_UPDATE = 'FULL_UPDATE';

	/**
	 * Массив хранит hash данные объявлений, которые встречаются в импортируемом списке объявлений
	 * @var array
	 */
    private $hashMtkIds = [];

	/**
	 * Массив хранит список id объявлений которые будут импортированы в базу MTK
	 * @var array
	 */
	private $importIds = [];

	/**
	 * Массив хранит список id объявлений, которые будут подготовленны для импорта в базу MTK
	 * Хранит данные в виде: $idsForPrepare = [
	 *                             'flats' => [111, 222, 333, 444, ...]
	 *                       ];
	 * @var array
	 */
	private $idsForPrepare = [];

	/**
	 * Массив хранит список статусов объявления.
	 * Объявление может принимать 2 статуса:
	 *                                      New - значит объявления еще нет в БД MTK
	 *                                      Update - значит объявление уже есть в БД MTK
	 * @var array
	 */
	private $statusObjects = [];

	/**
	 * Массив хранит список объектов объявлений, созданных из данных, полученных из спарсенной базы импорта
	 * @var array
	 */
    private $objectsList = [];

	/**
	 * Массив строк, для SQL запроса вставки либо обновления данных и фотографий объекта
	 * @var array
	 */
	private $arrSql = [];

	/**
	 * Массив хранит список фото, которые следует обновить
	 * @var array
	 */
	private $arrayPhoto = [];

	/**
	 * Массив данных источника
	 * @var array
	 */
	private $sourceData = [];

	/**
	 * Manager constructor.
	 * Конструктор менеджера.
	 * @param Import|null $objImport
	 */
	public function __construct(Import $objImport = null)
	{
		if (empty($objImport)) {
			Logger::error('Ошибка. При инициализации Manager получен пустой тип Import.');
		}

		// Инициализация объекта для работы с БД в пространстве MTK
		global $db;
		$this->db = $db;

        // Инициализация импортера
		$this->import = $objImport;

		// Инициализация id источника (например для cian он может быть равен 1)
		if (empty($this->sourceData = $this->getSourceData($this->import->getImportName()))) {
			Logger::error('Ошибка инициализации данных источника');
		}

		// Проверка активизирован ли данный тип импорта (cian, avito etc.) в БД mtk_othersources
		if (empty($this->sourceData['active'])){
			Logger::error('Ошибка. ' . $this->sourceData['source_name'] . ' не активизирован в БД. Таблица mtk_othersources');
		}

		// Устанавливаем id источника
		$this->import->setSourceId($this->sourceData['source_id']);
	}


	/**
	 * Вернуть массив построенных SQL строк
	 * @return array
	 */
	public function getSqlArray()
	{
		return $this->arrSql;
	}


	/**
	 * Для трейта Repository - возвращает объект для роботы с БД MTK
	 * @return \DbSimple_Mysql
	 */
	public function getDb()
	{
		return $this->db;
	}

	/**
	 * Деактивировать MTK объявления, которых нет в НОВОМ импорте
	 * ВНИМАНИЕ !!! Сначала получаем объявления из базы ИМПОРТА, а потом уже из базы MTK.
	 * @return bool
	 */
	public function deactivateObjects()
	{
		// Получить id объявлений подлежащих импорту в базу MTK
		// Полученные объявления имеют статус - активные, то есть они актуальны.
		$this->importIds = array_filter($this->import->initImportIds());

		// Если нет объявлений для импорта, возвращаем false - и останавливаем импорт
		if (empty($this->importIds)) {
			Logger::log('Нет объявлений для импорта');
			return false;
		}

		// Отобразить в логгере кол-во объявлений для импорта
		Logger::log('Общее Кол-во объявлений подлежащих импорту в базу MTK: ' . array_sum(array_map('count', $this->importIds)));
		Logger::arrayCountLog($this->importIds);

		// Получаем АКТИВНЫЕ объявления из базы MTK
		// Объявления хранятся как многомерный массив, где первый ключ тип (flats, rooms, etc.),
		// второй ключ source_id (id источника) и далее массив данных из таблицы mtkru_mtk_objects_flats__othersource_data
		// Пример: $mtkIds = [
		//                         'flats' => [
		//                         	            '111' => [
		//                         	            	'id' => 1,
		//											'object_id' => 1,
		//											'type_id' => 1,
		//											...
		//										],
		//                                      '222' => [
		//                                            'id' => 2,
		//                                            'object_id' => 2,
		//                                            'type_id' => 1,
		//											...
		//										],
		//                                      ...
	    //                                    ]
		//                   ];
		$mtkIds = array_filter($this->getMtkIds(['source_id' => $this->sourceData['source_id'], 'active' => 1]));
		if (empty($mtkIds)) {
			Logger::log('Нет АКТИВНЫХ объявлений в МТК');
			return true;
		}

		// Узнаем какие id объявлений следует отключить на MTK.
		// Что бы узнать какие объявления следует деактивировать, мы вычитаем из АКТИВНОГО списка объявлений MTK - объявления подлежащие ИМПОРТУ, и остаток следует деактивировать.
		foreach (array_keys($mtkIds) as $type) {

			// Если нет такого типа в таблице импорта
			if (empty($this->importIds[$type])) {
				continue;
			}

			// Получаем source_id (id источника) объявлений, которых нет в НОВОМ импорте
			$arrKeys = array_keys($mtkIds[$type]);
			$arrDiffKeys = array_diff($arrKeys, $this->importIds[$type]);

			// Так как имеем многомерный массив, делаем unset по ключам source_id (id источника)
			// Но сначала делаем значения => ключами для более быстрого поиска совпадений
			foreach (array_values($arrDiffKeys) as $v) {
				$new_haystack[$v] = 1;
			};
			foreach ($arrKeys as $key) {
				if (!isset($new_haystack[$key])) {
					unset($mtkIds[$type][$key]);
				}
			};
		};

		// Удаляем пустые типы объявлений (flats, rooms, etc.)
		$deactivateIds = array_filter($mtkIds);
		// Деактивировать MTK объявления, которых нет в НОВОМ импорте
		if (empty($deactivateIds)) {
			Logger::log('Нет объявлений на МТК для декативации.');
		} else {
			// Для логов, сохраняем список объявлений подлежащих деактивации
			foreach ($deactivateIds as $type => $arrObj) {
				foreach ($arrObj as $obj) {
					Logger::setValue('deleteObj', [$type => $obj['object_id']]);
				};
			};
			Logger::log('Старт Деактивации MTK объявлений. Общее Кол-во: ' . array_sum(array_map('count', $deactivateIds)));
			Logger::arrayCountLog($deactivateIds);
			Logger::startTimer('deactivate');
			$this->deleteMtkIds($deactivateIds);
			Logger::log('Деактивация MTK объявлений ЗАВЕРШЕНА. Время выполнения: ' . Logger::stopTimer('deactivate'));
		}

		return true;
	}


	/**
	 * Что бы получить объявления которые имеют статус NEW, и статус UPDATE
	 * следует сформировать 2 массива:
	 *     1) только АКТИВНЫЕ объявления ИМПОРТА
	 *     2) ВСЕ объявления MTK
	 * Разница между этими массивами ИМПОРТА и MTK - даст объявления со статусом NEW.
	 * Пересечение этих массивов даст объявления со статусом UPDATE
	 */
	public function getStatusObjects()
	{
		// Получить ВСЕ (и активные и НЕ активные) импортированные объявления MTK.
		// Формат: [Тип (flats)][source_id][id]
		// где: object_id - это id источника, id - это id (примари кей) объявления в базе MTK
		$this->hashMtkIds = $this->getMtkIds(['source_id' => $this->sourceData['source_id'], 'filter' => $this->importIds]);

		// Создаем массивы - контейнеры. В них в зависимости от статуса объявления, будет храниться id объявления.
		$this->statusObjects['new']    = [];
		$this->statusObjects['update'] = [];

		// Формируем массив source_id (id источника) объявлений со статусом NEW - то есть объявления которых еще не было в базе MTK
		foreach (array_keys($this->hashMtkIds) as $type) {
			// Если нет такого типа в таблице импорта
			if (empty($this->importIds[$type])) {
				continue;
			}
			$this->statusObjects['new'][$type] = array_diff($this->importIds[$type], array_keys($this->hashMtkIds[$type]));
		};

		// Получаем объявления со статусом UPDATE - то есть объявления, которые уже есть в базе MTK (объявление может быть активным или НЕ активным)
		foreach (array_keys($this->importIds) as $type) {
			$arrSourceId = array_diff($this->importIds[$type], $this->statusObjects['new'][$type]);
			foreach ($arrSourceId as $sourceId) {
				$this->statusObjects['update'][$type][$sourceId] = $this->hashMtkIds[$type][$sourceId];
			};
		};

		Logger::log('Общее Кол-во объявлений со статусом NEW: ' . array_sum(array_map('count', $this->statusObjects['new'])));
		Logger::arrayCountLog($this->statusObjects['new']);
		Logger::log('Общее Кол-во объявлений со статусом UPDATE: ' . array_sum(array_map('count', $this->statusObjects['update'])));
		Logger::arrayCountLog($this->statusObjects['update']);
	}


	/**
	 * Берем партию объявлений для их дальнейшей подготовки и загрузки в базу MTK.
	 * (Объявления отдаются партиями для экономии вычислительных ресурсов)
	 * @param int $limit - Ожидаемое кол-во объявлений в партии
	 * @return int       - Возвращает полученное кол-во объявлений в партии
	 */
    public function getImportObjects($limit = 5000)
	{
		// Проверка есть ли еще id для подготовке к загрузке в базу MTK
		if (empty($this->importIds = array_filter($this->importIds))) {
			return 0;
		}

		// Удаляем ранее полученные id (если они есть)
		$this->idsForPrepare = [];

		// Получили первый ключ-тип (например: flats)
        $type = array_keys($this->importIds)[0];

		// Получаем определенное в переменной $limit кол-во id для их дальнейщей подготовке к загрузке в базу MTK
		$this->idsForPrepare[$type] = array_splice($this->importIds[$type], 0, $limit);

		// Возвращаем полученное кол-во id
        return count($this->idsForPrepare[$type]);
	}


	/**
	 * Подготовить объявления к импорту в базу MTK.
	 * Подготовка подразумевает выборку из спарсенной базы импорта данных для объявлений
	 * @return bool
	 */
	public function getImportData()
	{
		// Если нет source_id для импорта
		if (empty($this->idsForPrepare)) {
			return false;
		}

		// Получить полные данные по source_id из базы импорта
		if(!$this->import->getImportData($this->idsForPrepare)) {
			return false;
		}

		return true;
	}


	/**
	 * Создаем объекты (в сыром неотвалидированом виде) из полученных данных из спарсенной базы импорта
	 * @return bool
	 */
	public function createObjects()
	{
		$this->objectsList = $this->import->createObjects();
		return !empty($this->objectsList)? true : false;
	}


	/**
	 * Валидация объявлений
	 * @return bool
	 */
	public function validateObjects()
	{
		foreach ($this->objectsList as &$object) {
			if (!$object->validate()) {
				Logger::setValue('excludeObj', [$object->getValue('service', 'type') => $object->getValue('service', 'id')]);
				$object = [];
			}
		};
		unset ($object);

		$this->objectsList = array_filter($this->objectsList);
		return !empty($this->objectsList)? true : false;
	}

	/**
	 * Загрузка новых, либо обновления существующих объявлений
	 */
	public function uploadObjects()
	{
		if (empty($this->objectsList)) {
			return;
		}

		// Здесь будут храниться фото - которые следует обновить
		$this->arrayPhoto = [];
		// Здесь будут храниться текстовые представления SQL запроса, из которых в последствии будет построен 1 SQL запрос в БД
		$this->arrSql = [];

		// В цикле готовим строки, для массовой вставки или обновления объявлений.
		foreach ($this->objectsList as $object) {
			// Определяем статус объявления: Новое, или Старое
			$status = $this->checkStatus($object);

			// В зависимости от статуса объявления (новое или старое) готовим SQL данные в строчном формате
			switch ($status) {
				case self::NEW_OBJ: // добавляем НОВОЕ объявление
					$this->prepareInsert($object);
					Logger::setValue('insertObj', [$object->getValue('service', 'type') => $object->getValue('service', 'id')]);
					break;
				case self::OLD_OBJ: // обновляем СТАРОЕ объявление
					switch ($this->prepareUpdate($object)) {
						case self::NOT_UPDATE: // Нет нужды обновлять объявление (данные и адрес не изменились)
							Logger::setValue('notUpdateObj', [$object->getValue('service', 'type') => $object->getValue('service', 'id')]);
							break;
						case self::FULL_UPDATE: // Полностью обновляем объявление (данные + адрес)
							Logger::setValue('fullUpdateObj', [$object->getValue('service', 'type') => $object->getValue('service', 'id')]);
							break;
						case self::DATA_UPDATE: // Обновляем только данные (адрес не изменился)
							Logger::setValue('dataUpdateObj', [$object->getValue('service', 'type') => $object->getValue('service', 'id')]);
							break;
						default:
							Logger::error('Ошибка. Подготовка SQL запроса для обновления объекта получила статус ошибки. id: ' . $object->getValue('service', 'id'));
					}
					break;
				default:
					Logger::error('Ошибка. Неопределенн статус объявления: (' . $status . ')');
			}
		};

		// Массовая вставка объявлений + вставка новых фото
		$this->doInsert();

		// Массовое обновление объявлений (без фото)
		$this->doUpdate();

		// Обновление фотографий объявлений подлежащих обновлению
		$this->doUpdateSourcePhoto();
	}


	/**
	 * Обновление фото
	 */
	private function doUpdateSourcePhoto()
	{
		if (empty($this->arrayPhoto)) {
			return;
		}
		// Узнаем какое фото следует удалить, какое добавить, а какое оставить без изменений
		foreach (array_keys($this->arrayPhoto) as $type) {
			$arrCalc = [];
			// Получили массив id объявлений, у которых фотографии ВОЗМОЖНО подлежат обновлению
			$ids = array_keys($this->arrayPhoto[$type]);
			// Получаем массив URL фото, существующие в MTK
			$arrCalc['mtk'] = $this->getMtkPhoto(['type' => $type, 'ids' => $ids]);

			$mtkUrls          = array_keys($arrCalc['mtk']);
			$importFliperUrls = $this->array_fliper($this->arrayPhoto[$type]);
			$importUrls       = array_keys($importFliperUrls);

			// Вычисляем фото, которые не следует изменять
			$arrCalc['intersect'] = array_intersect($mtkUrls, $importUrls);
			// Вычисляем фото которые следует удалить
			$arrCalc['delete'] = array_diff($mtkUrls, $arrCalc['intersect']);
			// Вычисляем фото которые следует добавить
			$arrCalc['insert'] = array_diff($importUrls, $arrCalc['intersect']);

			// Удаление фотографий
			if (!empty($arrCalc['delete'])) {
				$arrPhoto = [];
				// Помещаем в массив source_id фотографии - которую следует удалить
				foreach ($arrCalc['delete'] as $url) {
						$arrPhoto[]  = $arrCalc['mtk'][$url];
					    Logger::setValue('deleteImg', $url);
				};
				// Удалить фото
				if (!empty($arrPhoto)) {
					$this->deletePhoto(['type' => $type, 'arrPhoto' => $arrPhoto]);
				}
			}

			// Добавление фотографий
			if (!empty($arrCalc['insert'])) {
				// Только для Cian - Меняем порядок следования фотографий
				if (get_class($this->import) == 'importer\cian\Cian' || get_class($this->import) == 'Cian') {
					$arrCalc['insert'] = array_reverse($arrCalc['insert']);
				}

				// Очищаем массив для сохранения списка фото
				$this->arrSql['insert']['photo'] = [];
				$arrIds = [];
				// Подготовить массив фото для вставки
				foreach ($arrCalc['insert'] as $url) {
					$source_id = $importFliperUrls[$url];
					$arrIds[$source_id]['url'][] = $url;
					// Получить максимальное значение list_order (так как данное поле является уникальным, его следует увеличивать)
					$maxListOrder = 0;
					if (!empty($arrCalc['mtk'])) {
						foreach ($arrCalc['mtk'] as $url => $data) {
							if ($source_id == $data['source_id'] && $maxListOrder <= $data['list_order']) {
								$maxListOrder = $data['list_order'];
								$maxListOrder++;
							}
						};
					}
					$arrIds[$source_id]['maxListOrder'] = $maxListOrder;
				};
				// Добавить фото
				if (!empty($arrIds)) {
					// Строим SQL строку для фотографий объявления
					foreach ($arrIds as $id => $urls) {
						$arrPhoto = Object::validatePhotoSql($this->sourceData['source_id'], $id, $urls['url'], $urls['maxListOrder']);
						foreach ($arrPhoto as $photo) {
							$this->arrSql['insert']['photo'][$type][] = $photo;
						};
					};
				}
				// Вставялем фото объявлений
				$this->insertSourcePhotos();
			}
		};

	}


	/**
	 * Определяем статус объявления: Новое, или Старое
	 * @param \objects\object\Object $object - объект объявления
	 * @return string - Статус объявления (self::NEW_OBJ  |  self::OLD_OBJ)
	 */
	private function checkStatus($object)
	{
		if (empty($this->statusObjects['new'])) {
			return self::OLD_OBJ;
		}
		$id   = $object->getValue('service', 'id');
		$type = $object->getValue('service', 'type');
		if (in_array($id, $this->statusObjects['new'][$type])) {
			return self::NEW_OBJ;
		}
		return self::OLD_OBJ;
	}


	/**
	 * Подготовка к вставке нового объявления.
	 * Строим SQL строки.
	 * @param \objects\object\Object $object
	 */
	private function prepareInsert($object)
	{
		// Получаем Тип объекта (flats, rooms, ...)
		$type = $object->getValue('service', 'type');

        // Строим SQL строку для данных объявления
		$arrSqlData = $object->createSqlData();

		// Сохраняем строку, что содержит в себе развернутые параметры объявления
		$this->arrSql['insert']['data'][$type][] = $arrSqlData['arrSqlData'];

		// Сохраняем имена полей, которые будут использоваться для построения динамического SQL запроса
		if (empty($this->arrSql['insert']['fields'][$type])) {
			$this->arrSql['insert']['fields'][$type] = $arrSqlData['arrFields'];
		}

		// ФОТО. Строим SQL строку для фотографий объявления
		$arrPhoto = $object->createPhotoSql($this->sourceData['source_id']);
		foreach ($arrPhoto as $photo) {
			$this->arrSql['insert']['photo'][$type][] = $photo;
		}

		// HASH. Строим SQL строку для сохранения слепка объявления (hash данных и адреса)
		$this->arrSql['insert']['hash'][$type][] = $object->createHashSql();

		// Тип объекта. Для коммерческой или бизнес недвижимости
		if (in_array($type, ['business', 'commercial'])) {
			$this->arrSql['insert']['objType'][$type][] = $object->createObjTypeSql();
		}
	}


	/**
	 * Подготовка к обновлению нового объявления.
	 * @param \objects\object\Object $object - Объект объявления
	 * @return int - Возвращает: 1 - Нет нужды обновлять объявление (данные и адрес не изменились)
	 *                           2 - Полностью обновляем объявление (данные + адрес)
	 *                           3 - Обновляем только данные (адрес не изменился)
	 */
	private function prepareUpdate($object)
	{
		// Для того, что бы обновить объялвение, следует узнать, а что именно в нем изменилось? От этого зависит способ обновления объявления.
		// Если изменились данные которые не затрагивают поля адреса: region_id, district, locality, nas_punkt, street, housenumber.
		// Тогда обновляем данные кроме адреса.
		// Если же изменился строковое значение адреса (в массиве представленно как: full_address), то обновляем все данные, и в добавок обнуляем ocato = NULL
		// Благодаря обнуленному ocato = NULL, объявление будет перекладировано.
		// Сравнение происходит по двум критериям. Из данных объявления (которое является кандидатом на обновление)
		// 1) Создается hash адреса из поля: full_address
		// 2) Создается hash данных из полей данных, исключая: region_id, district, locality, nas_punkt, street, housenumber.
		// Далее эти 2 hash значения сравниваются с hash значениями, полученными из таблиц типа otherSource.
		// В РЕЗУЛЬТАТЕ после проверки hash данных имеем три варианта развития событий:
		// а) В случае полного равенства обоих hash, объявление не подлежит обновлению (функция вовзращает: false)
		// б) В случае если разница только в данных, и hash адресов равны, то обновляем только данные не затрагивая адрес
		// в) В случае если hash адресов не равны, обновляем и адрес и данные, и обнуляем ocato = NULL (ocato обнуляется для перекладирования объявления)

        // ПОЕХАЛИ !!!

		// Получаем Тип объекта
		$type = $object->getValue('service', 'type');
		// id объекта в источнике
		$source_object_id = $object->getValue('service', 'id');
		// Получаем hash данных НОВОГО объекта
		$newDataHash = $object->getDataHash();
		// Получаем hash адреса НОВОГО объекта
		$newAddressHash = $object->getAddressHash();
		// Получаем hash данных СТАРОГО объекта
		$oldDataHash = $this->hashMtkIds[$type][$source_object_id]['data_hash'];
		// Получаем hash адреса СТАРОГО объекта
        $oldAddressHash = $this->hashMtkIds[$type][$source_object_id]['address_hash'];

		// Сохраняем список фотографий объявления в массив фотографий
		$this->arrayPhoto[$type][$source_object_id] = array_filter(explode('##', $object->getValue('service', 'images')));

        // Если данные и адрес старого и новго объектов идентичны, отменяем обновление объявления
		if ($newDataHash == $oldDataHash && $newAddressHash == $oldAddressHash) {
			return self::NOT_UPDATE;
		}

		// Получаем ID объекта в базе MTK
		$mtkId = $this->statusObjects['update'][$type][$source_object_id]['object_id'];

		// Создаем SQL строку для массового обновления hash данных
		$this->arrSql['hashUpdate'][$type][] = $object->createHashSql($mtkId);

		// Полностью обновляем объявление (данные + адрес)
		if ($newAddressHash != $oldAddressHash) {
			// Для того что бы объявление было перекладировано, обнуляем ocato
			$object->setServiceField('ocato', 'null');
			// Создаем SQL строки для построения запроса в БД
			if(!$this->createUpdate(['object' => $object, 'updateType' => 'fullUpdate'])) {
				return self::ERROR_UPDATE;
			}
			return self::FULL_UPDATE;
		} else { // Обновляем только данные (адрес не изменился)
			// Удаляем поля адреса, что бы они не быди изменены в таблице объявлений. Данное объявление не будет перекладировано.
			$object->unsetField('common', ['region_id', 'district', 'locality', 'nas_punkt', 'street', 'housenumber']);
			// Создаем SQL строки для построения запроса в БД
			if (!$this->createUpdate(['object' => $object, 'updateType' => 'dataUpdate'])) {
				return self::ERROR_UPDATE;
			}
			return self::DATA_UPDATE;
		}
	}


	/**
	 * @param array $params - Массив параметров, имеет вид:
	 *                       $params = [
	 *                           'object'     => $object, // Объект объявления
	 *                           'updateType' => $type    // Тип обновления, может принимать значения: fullUpdate, dataUpdate
	 *                       ];
	 * @return bool
	 */
	private function createUpdate(array $params)
    {
    	if (empty($params['object']) || empty($params['updateType'])) {
    		return false;
		}

		// Получаем Тип объекта
		$type = $params['object']->getValue('service', 'type');

		// id в системе источника
		$source_id = $params['object']->getValue('service', 'id');

		// Получаем ID объекта в базе MTK
		$mtkId = ['id' => $this->statusObjects['update'][$type][$source_id]['object_id']];

		// Определяем, установлено ли свойство ocato (если oacto установлен, то его следует обнулить в БД для перекладирования объявления)
		if ($params['object']->isIsset('service', 'ocato')) {
			$mtkId['ocato'] = null;
		}

    	// Получаем тип обновления. Может быть: fullUpdate, dataUpdate
    	$updateType = $params['updateType'];

		// Строим SQL строку для данных объявления
		$arrSqlData = $params['object']->createSqlData($mtkId);

		// Сохраняем строку, что содержит в себе развернутые параметры объявления
		$this->arrSql[$updateType]['data'][$type][] = $arrSqlData['arrSqlData'];

		// Сохраняем имена полей, которые будут использоваться для построения динамического SQL запроса
		if (empty($this->arrSql[$updateType]['fields'][$type])) {
			$this->arrSql[$updateType]['fields'][$type] = $arrSqlData['arrFields'];
		}

		return true;
    }

	/**
	 * Развернуть массив (пеменять местами ключ => значение)
	 * @param array $arr
	 * @return array
	 */
	private function array_fliper(array $arr)
	{
		if (empty($arr)) {
			return [];
		}
		$rez = [];
		foreach ($arr as $key => $val) {
			if (!is_array($val)) {
				$val[] = $val;
			}
			foreach ($val as $v) {
				$rez[$v] = $key;
			};
		};
		return $rez;
	}


	/**
	 * Метод деактивирует объявления в базе MTK.
	 * @param array|null $objectsIds - Список id подлежащих деактивации
	 *                                 Пример массива: $objectsIds = [
	 *                                                      'flats' => [1111 => [...], 2222 => [...], 3333 => [...]],
	 *                                                      'rooms' => [4444 => [...], 5555 => [...], 666 => [...]]
	 *                                                 ];
	 * @return bool
	 */
	private function deleteMtkIds (array $objectsIds = null)
	{
		if (empty($objectsIds)) {
			return true;
		}

		// Перебираем предустановленные типы MTK таблиц (flats, rooms, etc.)
		foreach ($this->mtkTableTypes as $type) {
			if (empty($objectsIds[$type])) {
				continue;
			}

			// Готовим массив id объявлений в базе MTK для их последующей деактивации
			$forDeactivate = array_column($objectsIds[$type], 'object_id');

			// Деактивируем объявления заданного типа (передали массив ID объяв. и тип таблицы). Вызывается метод Repository::deactivateMtkObjects
			if (!empty($forDeactivate)) {
				$this->deactivateMtkObjects($forDeactivate, $type);
			}
		};
		return true;
	}

}
