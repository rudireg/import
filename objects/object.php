<?php
/**
 * Каждое объявление, которое следует импортировать в базу MTK - представляется объектом класса Object.
 * Данный класс реализует в себе валидацию объекта.
 * User: Rudi
 * Date: 07.04.2017
 */
namespace objects\object;

use objects\repository\Repository as Repository;
use objects\logger\Logger as Logger;
use objects\validate\Validate as Validate;

class Object
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
	 * Конфигурация
	 * @var
	 */
	private $config;

	/**
	 * Карта типов объявлений в пространстве MTK
	 * @var array
	 */
	private $typeMap = [
		'flats'      => 1,
		'business'   => 2,
		'rooms'      => 3,
		'parts'      => 4,
		'cottages'   => 5,
		'commercial' => 6,
		'parkings'   => 7,
	];

	/**
	 * Object constructor.
	 * @param $conf
	 */
	public function __construct($conf = null)
	{
		// Конфигурация
		if ($conf === null) {
			Logger::error('Ошибка. Тип Object не получил кофигурацию.');
		}
		$this->config = $conf;

		global $db;
		$this->db = $db;
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
	 * Метод - Заглушка, так как он объявлен как абстрактый метод в Трейте Repository
	 */
	public function getSqlArray() {}

	/**
	 *  Массив хранит данные объекта
	 * @var array
	 */
	private $data = [];

	/**
	 * Поля адреса
	 * @var array
	 */
	private $addressFields = ['region_id' => 0, 'district' => 0, 'locality' => 0, 'nas_punkt' => 0, 'street' => 0, 'housenumber' => 0];

	/**
	 * @param string $name - Имя свойства
	 * @param mixed $value - Значение свойства
	 */
	public function setSpecificField($name, $value)
	{
		$this->data['specific'][$name] = $value;
	}


	/**
	 * Сохранить данные для отдельных таблиц данных. Для них будут сформированы отдельные запросы в БД
	 * @param string $objType - Тип объекта
	 * @param string $name    - Имя свойства
	 * @param string $value   - Значение свойства
	 */
	public function setAdditionallyData($objType, $name, $value)
	{
		$this->data['additionally'][$objType][$name] = $value;
	}


	/**
	 * Подготовить данные для установки типа объекта, Применяется для  коммерческой и бизенс недвижимости
	 * @param int $id - id объявления в базе MTK
	 * @return array
	 */
    public function createObjTypeSql($id = 0)
	{
		// Тип объявления (commercial, bussines etc.)
		$type = $this->data['service']['type'];

		return ['object_id'          => $id, // Если объект еще не вставлен, то данный id будет равен 0
			    'commercial_type_id' => $this->data['additionally'][$type]['object_type']
		];
	}


	/**
	 * Инициализация общих свойств объекта объявления
	 * @param string $name - Имя свойства
	 * @param mixed $value - Значение свойства
	 * @return bool
	 */
    public function setCommonField($name, $value)
	{
		if (!in_array($name, $this->mtkFields['common'])) {
			return false;
		}
		$this->data['common'][$name] = $value;

		return true;
	}


	/**
	 * @param string $fieldType - Имя типа данных (common, service, специфическое имя)
	 * @param array $arrName - массив имен полей поля
	 */
	public function unsetField($fieldType, array $arrName)
	{
		if (!is_array($arrName)) {
			$arrName[] = $arrName;
		}
		foreach ($arrName as $name) {
			if (isset($this->data[$fieldType][$name])) {
				unset ($this->data[$fieldType][$name]);
			}
		};
	}


	/**
	 * @param string $name - Имя свойства
	 * @param mixed $value  - Значение свойства
	 */
	public function setServiceField($name, $value)
	{
		$this->data['service'][$name] = $value;
	}


	/**
	 * @param string $objType - Тип объекта (flats, rooms, cottages, commercial)
	 * @param string $name - Имя свойства
	 * @param mixed $value - Значение свойства
	 */
	public function setCustomField($objType, $name, $value)
	{
		$this->data[$objType][$name] = $value;
	}


	/**
	 * Получить значение объекта
	 * @param string $type - Тип данных (service, common, etc.)
	 * @param string $name - Ключ значения (deal, status, etc.)
	 * @return null
	 */
	public function getValue($type, $name)
	{
		if (isset($this->data[$type][$name])) {
			return $this->data[$type][$name];
		}
		return null;
	}


	/**
	 * @param string $type - Тип данных (service, common, etc.)
	 * @param string $name - Ключ значения (deal, status, etc.)
	 * @return bool - TRUE если есть данное поле в массиве, иначе FALSE
	 */
	public function isIsset($type, $name)
	{
		if (isset($this->data[$type][$name])) {
			return true;
		}
		return false;
	}


	/**
	 * Разбить адрес на части.
	 * Для cottages и commercial - номер дома не обязателен
	 * @param string $address - Адрес в форме строки
	 * @param string $type - Тип объявления (flats, rooms, ...)
	 * @return bool
	 */
	public function separateAddress($address, $type)
	{
		if (empty($address)) {
			return false;
		}

		$addressAr = array_values(array_filter(array_map('trim', explode(',', $address))));
		$district = $city = $street = $houseNumber = '';

		// Если кол-во разделов адреса не входит в рамки
		if (count($addressAr) <= 2 || count($addressAr) > 8) {
			return false;
			// Если длина = 3 и это НЕ москва и НЕ СПБ, то НЕ обрабатываем адрес
		} elseif (count($addressAr) == 3 && !in_array(mb_strtolower($addressAr[0], 'UTF-8'), ['москва', 'санкт-петербург'])) {
			// в avito бывают адреса вида: Ставропольский край, Пятигорск, зеленый переулок 1
			// где номер дома не отделен запятой, тогда пытаемся отделить номер дома от улицы или мкр
			$res = $this->extractHouseNumberFromStreet($addressAr[2], $type);
			if ($res) {
				$addressAr[2] = $res[0];
				$addressAr[3] = $res[1];
			} else {
				// Исключение типы: cottages и commercial (так как для них номер дома не обязателен)
				if (!in_array($type, ['cottages', 'commercial'])) {
					;//return false;
				}
			}
		}

        // Разбиваем адрес
		switch (count($addressAr)) {
			case 8:
				list($region, $district, ,$locality , , $city, $street, $houseNumber) =  $addressAr;
				break;
			case 7:
				if (validate::validateHouseNumber(['houseNumber' => $addressAr[6], 'type' => !empty($this->data[$type]['type'])? $this->data[$type]['type'] : 0])) {
					if ((strstr($addressAr[1], 'район') || strstr($addressAr[1], 'округ'))) {
						list($region, $district, , $city, , $street, $houseNumber) = $addressAr;
					} else {
						list($region, , $city, $district, , $street, $houseNumber) = $addressAr;
					}
				} else {
					if ((strstr($addressAr[1], 'район') || strstr($addressAr[1], 'округ'))) {
						list($region, $district, , $locality, , $city, $street) = $addressAr;
					} else {
						list($region, $locality, , $district, , $city, $street) = $addressAr;
					}
				}
				break;
			case 6:
				if(strstr($addressAr[2], 'район') || strstr($addressAr[2], 'округ')) {
					if (validate::validateHouseNumber(['houseNumber' => $addressAr[5], 'type' => !empty($this->data[$type]['type'])? $this->data[$type]['type'] : 0])) {
						if ((strstr($addressAr[2], 'район') || strstr($addressAr[2], 'округ'))) {
							list($region, $locality, $district, $city, $street, $houseNumber) = $addressAr;
						} else {
							list($region, , $district, $city, $street, $houseNumber) = $addressAr;
						}
					} else {
						if ((strstr($addressAr[1], 'район') || strstr($addressAr[1], 'округ'))) {
							if ($this->isNasPunkt($addressAr[4])) {
								list($region, $district, , $locality, $city, $street) = $addressAr;
							} else {
								list($region, $district, , $city, , $street) = $addressAr;
							}
						} else {
							list($region, $locality, ,$district , $city, $street) = $addressAr;
						}
					}
				} elseif (strstr($addressAr[5], 'ЖК') || strstr($addressAr[5], 'кп')) {
					list($region, $district, , , $city,) = $addressAr;
				} elseif ($this->isNasPunkt($addressAr[5])) {
					list($region, , , $district, , $city) = $addressAr;
				} elseif ($this->isStreet($addressAr[5])) {
					list($region, $locality, , $district, $city, $street) =  $addressAr;
				} else {
					if (validate::validateHouseNumber(['houseNumber' => $addressAr[5], 'type' => !empty($this->data[$type]['type'])? $this->data[$type]['type'] : 0])) {
						if ((strstr($addressAr[3], 'район') || strstr($addressAr[3], 'округ'))) {
							list($region, $locality, $city, $district, $street, $houseNumber) =  $addressAr;
						} else {
							list($region, $district, , $city, $street, $houseNumber) =  $addressAr;
						}
					} else {
						if ((strstr($addressAr[1], 'район') || strstr($addressAr[1], 'округ'))) {
							list($region, $district, $locality, $city, , ) =  $addressAr;
						} else {
							list($region, $locality, $district, $city, , ) =  $addressAr;
						}
					}
				}
				break;
			case 5:
				if (mb_strtolower($addressAr[0], 'UTF-8') == 'москва' || mb_strtolower($addressAr[0], 'UTF-8') == 'санкт-петербург') {
					if (validate::validateHouseNumber(['houseNumber' => $addressAr[4], 'type' => !empty($this->data[$type]['type'])? $this->data[$type]['type'] : 0])) {
						list($region, $city, $district, $street, $houseNumber) =  $addressAr;
					} else {
						if ((strstr($addressAr[2], 'район') || strstr($addressAr[1], 'округ'))) {
							list($region, $locality, $district, $city, ) =  $addressAr;
						} else {
							if ($this->isStreet($addressAr[4])) {
								list($region, $locality, $city, $district, $street) =  $addressAr;
							} else {
								list($region, $locality, $city, $district,) =  $addressAr;
							}
						}
					}
				} elseif(strstr($addressAr[2], 'район') || strstr($addressAr[2], 'округ')) {
					if ($this->isStreet($addressAr[4])) {
						list($region, , $district, $city, $street) = $addressAr;
					} elseif($this->isNasPunkt($addressAr[4])) {
						list($region, $locality, $district, , $city) = $addressAr;
					} else {
						if ($this->isNasPunkt($addressAr[3])) {
							list($region, , $district, $city, $houseNumber) = $addressAr;
						} elseif ($this->isNasPunkt($addressAr[4])) {
							list($region, $district, , , $city) =  $addressAr;
						} else {
							if (validate::validateHouseNumber(['houseNumber' => $addressAr[4], 'type' => !empty($this->data[$type]['type'])? $this->data[$type]['type'] : 0])) {
								list($region, $city, $district, $street, $houseNumber) =  $addressAr;
							} else {
								if ((strstr($addressAr[1], 'район') || strstr($addressAr[1], 'округ'))) {
									list($region, $district, $locality, $city, ) =  $addressAr;
								} else {
									list($region, $locality, $district, $city, ) =  $addressAr;
								}
							}
						}
					}
				} else {
					if ($this->isStreet($addressAr[4])) {
						list($region, $district, , $city, $street) =  $addressAr;
					} elseif ($this->isNasPunkt($addressAr[4])) {
						list($region, $district, , , $city) = $addressAr;
					} elseif (strstr($addressAr[4], 'кп') || strstr($addressAr[4], 'ЖК')) {
						list($region, , $city, $district, ) =  $addressAr;
					}else {
						if (validate::validateHouseNumber(['houseNumber' => $addressAr[4], 'type' => !empty($this->data[$type]['type'])? $this->data[$type]['type'] : 0])) {
							if ($this->isNasPunkt($addressAr[3])) {
								list($region, $district, , $city, $houseNumber) = $addressAr;
							} else {
								list($region, $district, $city, $street, $houseNumber) = $addressAr;
							}
						} else {
							if ((strstr($addressAr[1], 'район') || strstr($addressAr[1], 'округ'))) {
								list($region, $district, $locality, $city, ) =  $addressAr;
							} else {
								list($region, $locality, $district, $city, ) =  $addressAr;
							}
						}
					}
				}
				break;
			case 4:
				if (mb_strtolower($addressAr[0], 'UTF-8') == 'москва' || mb_strtolower($addressAr[0], 'UTF-8') == 'санкт-петербург') {
					if ($this->isStreet($addressAr[3])) {
						list($region, $city, $district, $street) = $addressAr;
					} elseif ($this->isNasPunkt($addressAr[3])) {
						list($region, , $district, $city) = $addressAr;
					} elseif (strstr($addressAr[3], 'район') || strstr($addressAr[3], 'округ')) {
						list($region, , $city, $district) = $addressAr;
					} else {
						if (validate::validateHouseNumber(['houseNumber' => $addressAr[3], 'type' => !empty($this->data[$type]['type'])? $this->data[$type]['type'] : 0])) {
							list($region, $district, $street, $houseNumber) = $addressAr;
						} else {
							if ((strstr($addressAr[1], 'район') || strstr($addressAr[1], 'округ'))) {
								list($region, $district, $city, ) =  $addressAr;
							} else {
								list($region, $city, $district, ) =  $addressAr;
							}
						}
					}
				} else {
					if(strstr($addressAr[1], 'район') || strstr($addressAr[1], 'округ')) {
						if ($this->isNasPunkt($addressAr[3])) {
							list($region, $district, , $city) = $addressAr;
						} elseif ($this->isStreet($addressAr[3])) {
							list($region, $district, $city, $street) = $addressAr;
						} elseif (strstr($addressAr[3], 'кп') || strstr($addressAr[3], 'ЖК')){
							list($region, $district, $city, ) =  $addressAr;
						}else {
							if (validate::validateHouseNumber(['houseNumber' => $addressAr[3], 'type' => !empty($this->data[$type]['type'])? $this->data[$type]['type'] : 0])) {
								list($region, $district, $city, $houseNumber) =  $addressAr;
							} else {
								if ((strstr($addressAr[1], 'район') || strstr($addressAr[1], 'округ'))) {
									list($region, $district, $city, ) =  $addressAr;
								} else {
									list($region, $city, $district, ) =  $addressAr;
								}
							}
						}
					} else {
						if ($this->isStreet($addressAr[3])) {
							list($region, $city, $district, $street) = $addressAr;
						} elseif ($this->isNasPunkt($addressAr[3])) {
							list($region, $locality , $district, $city) = $addressAr;
						} elseif (strstr($addressAr[3], 'район') || strstr($addressAr[3], 'округ')) {
							list($region, $locality, $city, $district) =  $addressAr;
						} else {
							if (validate::validateHouseNumber(['houseNumber' => $addressAr[3], 'type' => !empty($this->data[$type]['type'])? $this->data[$type]['type'] : 0])) {
								list($region, $city, $street, $houseNumber) = $addressAr;
							} else {
								if ((strstr($addressAr[1], 'район') || strstr($addressAr[1], 'округ'))) {
									list($region, $district, $city, ) =  $addressAr;
								} else {
									list($region, $city, $district, ) =  $addressAr;
								}
							}
						}
					}
				}
				break;
			case 3:
				if (strstr($addressAr[1], 'район') || strstr($addressAr[1], 'округ')) {
					if ($this->isStreet($addressAr[2])) {
						list($region, $district, $street) =  $addressAr;
					} else {
						list($region, $district, $city) =  $addressAr;
					}
				} elseif (strstr($addressAr[2], 'район') || strstr($addressAr[2], 'округ')){
					list($region, $city, $district) =  $addressAr;
				} else {
					list($region, $city, $street) =  $addressAr;
				}
				break;
			default:
				Logger::error('Необработанное сочетание элементов адреса: ' . $address);
		}

		// Уточняем: 'locality' VS 'nas_punkt'
		if (empty($locality)) {
			$locality = $nas_punkt = '';
			$lowerCity = mb_strtolower($city, 'UTF-8');
			if ($this->isNasPunkt($lowerCity) || strstr($lowerCity, 'жк') || strstr($lowerCity, 'кп') || strstr($lowerCity, 'товарищество')) {
				$nas_punkt = $city;
			} else {
				$locality = $city;
			}
		} else {
			$nas_punkt = $city;
		}

		// Если в улицу попал нас. пункт
		if ($this->isNasPunkt($street)) {
			$nas_punkt = $street;
			$street = '';
		}

		// Сохраняем результат
		$this->data['common']['district']    = $district;
		$this->data['common']['locality']    = $locality;
		$this->data['common']['nas_punkt']   = $nas_punkt;
		$this->data['common']['street']      = $street;
		$this->data['common']['housenumber'] = $houseNumber;

		// Для Москвы и СПБ - удаляем district
		if (in_array(mb_strtolower($addressAr[0], 'UTF-8'), ['москва', 'санкт-петербург'])) {
			$this->data['common']['district'] = '';
		}

        return true;
	}


	/**
	 * Явояется ли строка улицей
	 * @param string $str
	 * @return bool
	 */
	private function isStreet($str)
	{
		if (strstr($str, 'ул.')
			|| strstr($str, 'пл.')
			|| strstr($str, 'проезд')
			|| strstr($str, 'бул.')
			|| strstr($str, 'ш.')
			|| strstr($str, 'пер.')
			|| strstr($str, 'просп.')
			|| strstr($str, 'наб.')
		) {
			return true;
		}

		return false;
	}


	/**
	 * Явояется ли строка Нас. пунктом
	 * @param string $str
	 * @return bool
	 */
	private function isNasPunkt($str)
	{
		if ((
			strstr($str, 'пос.')
			|| strstr($str, 'с/пос')
			|| strstr($str, 'с/с')
			|| strstr($str, 'пгт')
			|| strstr($str, 'мкр')
			|| strstr($str, 'поселение')
			|| strstr($str, 'д.')
			|| strstr($str, 'п.')
			|| strstr($str, 'с.')
			|| strstr($str, 'тер.')
			|| strstr($str, 'городок')
		   )
			&& !strstr($str, 'просп.')
		) {
			return true;
		}

		return false;
	}


	/**
	 * Из строки вида: зеленый переулок 5
	 * Извлекает номер дома, и возвращает массив которые будет содержать улицу и номер дома отдельно-разделенными
	 * Пример: $res = [
	 *      0 => 'зеленый переулок',
	 *      1 => '5',
	 * ];
	 *
	 * @param string $street улица с номером дома, который не отделен запятой
	 * @param string $type (тип объявления, flats, rooms, etc.)
	 * @return array|null
	 */
	private function extractHouseNumberFromStreet($street, $type)
	{
		$street = trim($street);
		if (empty($street)) {
			return null;
		}

		$arrStreet = explode(' ', $street);
		$res = [];
		if (count($arrStreet) > 1) {
			if (validate::validateHouseNumber(['houseNumber' => $arrStreet[count($arrStreet)-1], 'type' => !empty($this->data[$type]['type'])? $this->data[$type]['type'] : 0])) {
				$houseNumber = array_pop($arrStreet);
				$res[] = implode(' ', $arrStreet);
				$res[] = $houseNumber;
				return $res;
			}
		}

		return null;
	}


	/**
	 * Валидация объявления
	 * @return bool
	 */
	public function validate ()
	{
		// Получаем тип объекта (flats, rooms,  etc.)
		$objType = $this->data['service']['type'];

        // Проверяем hash адреса и данных
		if(empty($this->getDataHash()) || empty($this->getAddressHash())) {
			$errorStr = 'Тип объявления: ' . $objType . ' id: ' . $this->data['service']['id'] .
				' Валидатор не пройден: Неудачное формирование HASH данных.';
			Logger::setValue('validate', ['ERROR_HASH_DATA' => $errorStr]);
			return false;
		}

		// Приводим существующие площади объекта к дроби, где запятые заменяются на точки, и точность равна двум
		$arrAreas = ['area_living', 'area_kitchen', 'area_part', 'area_house', 'area_plot', 'area_min', 'area_max', 'area_total'];
		foreach ($arrAreas as $area) {
			if (!empty($this->data[$objType][$area])) {
				// Заменяем запятые на точки и приводим к точности 2 (c запятыми данные в БД не вставляются - тип DECIMAL)
				$this->data[$objType][$area] = round(str_replace(',', '.', $this->data[$objType][$area]), 2);

				// Максимальное значение площади
				if ((int) $this->data[$objType][$area] > 200000) {
					$errorStr = 'Тип объявления: ' . $objType . ' id: ' . $this->data['service']['id'] .
						        ' Валидатор не пройден: Значение площади слишком велико: ' . $area . ' (значение: ' . $this->data[$objType][$area] . ')';
					Logger::setValue('validate', ['LONG_AREA' => $errorStr]);
					return false;
				}
			}
		};

		// Длина населенного пнкта не должна привышать 75 символа
		if (strlen($this->data['common']['nas_punkt']) > 75) {
			$errorStr = 'Тип объявления: ' . $objType . ' id: ' . $this->data['service']['id'] .
				' Валидатор не пройден: ДЛИНА  NAS_PUNKT (значение: ' . $this->data['common']['nas_punkt'] . ')';
			Logger::setValue('validate', ['LONG_NAS_PUNKT' => $errorStr]);
			return false;
		}

		// Длина района не должна привышать 128 символов
		if (strlen($this->data['common']['district']) > 128) {
			$errorStr = 'Тип объявления: ' . $objType . ' id: ' . $this->data['service']['id'] .
				' Валидатор не пройден: ДЛИНА  DISTRICT (значение: ' . $this->data['common']['district'] . ')';
			Logger::setValue('validate', ['LONG_DISTRICT' => $errorStr]);
			return false;
		}

		// Длина улицы не должна привышать 255 символа
		if (strlen($this->data['common']['street']) > 255) {
			$errorStr = 'Тип объявления: ' . $objType . ' id: ' . $this->data['service']['id'] .
				' Валидатор не пройден: ДЛИНА STREET (значение: ' . $this->data['common']['street'] . ')';
			Logger::setValue('validate', ['LONG_STREET' => $errorStr]);
			return false;
		}

		// Длина номера дома не должна привышать 32 символа
		if (strlen($this->data['common']['housenumber']) > 32) {
			$errorStr = 'Тип объявления: ' . $objType . ' id: ' . $this->data['service']['id'] .
				' Валидатор не пройден: ДЛИНА HOUSENUMBER (значение: ' . $this->data['common']['housenumber'] . ')';
			Logger::setValue('validate', ['LONG_HOUSENUMBER' => $errorStr]);
			return false;
		}

		//Для квартир только !!!
		if ($this->config['house_number'] === 1 && in_array($objType, ['flats', 'rooms'])) {
			// Проверка номера дома
			$isValidHouseNumber = Validate::validateHouseNumber(['type' => !empty($this->data[$objType]['type'])? $this->data[$objType]['type'] : 0 ,
				'houseNumber' => $this->data['common']['housenumber']]);
			if ($isValidHouseNumber !== true) {
				$errorStr = 'Тип объявления: ' . $objType . ' id: ' . $this->data['service']['id'] .
					' Валидатор не пройден: НОМЕР ДОМА (значение: ' . $this->data['common']['housenumber'] . ')';
				Logger::setValue('validate', ['ERROR_HOUSE_NUMBER' => $errorStr]);
				return false;
			}
		}

        // Проверка типа сделки
		if (empty($this->data['common']['deal']) || !in_array($this->data['common']['deal'], [10, 20])) {
			$errorStr = 'Тип объявления: ' . $objType . ' id: ' . $this->data['service']['id'] .
				        ' Валидатор не пройден: ТИП СДЕЛКИ (значение: ' . $this->data['common']['deal'] . ')';
			Logger::setValue('validate', ['ERROR_DEAL_TYPE' => $errorStr]);
			return false;
		}

		// Проверка цен
		if (!Validate::validatePrice((int)$this->data['common']['price'], $objType, $this->data['common']['deal'], $this->data['common']['region_id'])) {
			$errorStr = 'Тип объявления: ' . $objType . ' id: ' . $this->data['service']['id'] .
				' Валидатор не пройден: ПРОВЕРКА ЦЕН (значение: ' . $this->data['common']['price'] . ')';
			Logger::setValue('validate', ['ERROR_PRICE' => $errorStr]);
			return false;
		}

		// Проверка описания объекта
		if (!empty($this->data['common']['description'])) {
			while (mb_strstr($this->data['common']['description'], 'http', 'UTF-8')
				|| mb_strstr($this->data['common']['description'], 'www.', 'UTF-8'))
			{
				$this->data['common']['description'] = preg_replace('/(http[^\s]*|www[^\s]*)/', '', $this->data['common']['description']);
			}
			$this->data['common']['description'] = trim($this->data['common']['description']);

			// Убираем строку циан
			$this->data['common']['description'] = str_replace('циан', '', $this->data['common']['description']);
		}

		// Проверка региона
		if (empty($this->data['common']['region_id'])) {
			$errorStr = 'Тип объявления: ' . $objType . ' id: ' . $this->data['service']['id'] .
				        ' Валидатор не пройден: ПРОВЕРКА РЕГИОНА (значение: ' . $this->data['common']['region_id'] . ')';
			Logger::setValue('validate', ['ERROR_REGION' => $errorStr]);
			return false;
		}

		// Проверка даты создания объявления
		if (empty($this->data['common']['date_added'])) {
			$this->data['common']['date_added'] = time();
		}

		if (in_array($objType, ['rooms'])) {
			// Проверка кол-ва комнат в сделке, если операция с комнатой
			if (empty($this->data[$objType]['rooms_deal'])) {
				$errorStr = 'Тип объявления: ' . $objType . ' id: ' . $this->data['service']['id'] .
				     	    ' Валидатор не пройден: КОЛ-ВО КОМНАТ В СДЕЛКЕ (значение: 0)';
				Logger::setValue('validate', ['ERROR_ROOMS_IN_DEAL' => $errorStr]);
				return false;
			}
		}

		if (in_array($objType, ['flats', 'rooms', 'parts'])) {
			// Проверка наличия комнат у объекта
			if (empty($this->data[$objType]['rooms'])) {
				if ($objType == 'flats') {
					// Если тип объявления flats - то считаем что это СТУДИЯ
					$this->data[$objType]['rooms'] = 100;
				} else {
					$errorStr = 'Тип объявления: ' . $objType . ' id: ' . $this->data['service']['id'] .
						' Валидатор не пройден: НАЛИЧИЕ КОМНАТ У ОБЪЕКТА (значение: 0)';
					Logger::setValue('validate', ['ERROR_ROOMS_IN_OBJECT' => $errorStr]);
					return false;
				}
			}
			// Проверка этажа объекта
			if ($this->config['floor'] === 1 && empty($this->data[$objType]['floor'])) {
				$errorStr = 'Тип объявления: ' . $objType . ' id: ' . $this->data['service']['id'] .
					        ' Валидатор не пройден: ПРОВЕРКА ЭТАЖА (значение: 0)';
				Logger::setValue('validate', ['ERROR_FLOOR' => $errorStr]);
				return false;
			}
			// Проверка этажности здания
			if ($this->config['floors'] === 1 && empty($this->data[$objType]['floors'])) {
				$errorStr = 'Тип объявления: ' . $objType . ' id: ' . $this->data['service']['id'] .
					        ' Валидатор не пройден: ПРОВЕРКА ЭТАЖНОСТИ ЗДАНИЯ (значение: 0)';
				Logger::setValue('validate', ['ERROR_BUILDING_FLOORS' => $errorStr]);
				return false;
			}
			// Этаж не может быть больше этажности
			if ($this->config['floors'] === 1 && $this->data[$objType]['floor'] > $this->data[$objType]['floors']) {
				$errorStr = 'Тип объявления: ' . $objType . ' id: ' . $this->data['service']['id'] .
					' Валидатор не пройден: ЭТАЖ НЕ МОЖЕТ БЫТЬ БОЛЬШЕ ЭТАЖНОСТИ (значения: ' .
					$this->data[$objType]['floor'] . ' > ' . $this->data[$objType]['floors'];
				Logger::setValue('validate', ['ERROR_MAX_MIN_FLOORS' => $errorStr]);
				return false;
			}
		}

		return true;
	}


	/**
	 * Создать SQL строку для вставки данных объявления
	 * @param array $specificParam - Массив параметров, что будут помещены в специфическое поле
	 * @return array
	 */
	public function createSqlData(array $specificParam = [])
	{
		// Узнаем Тип объявления (flats, rooms etc.)
		$type = $this->data['service']['type'];

		$arrSqlData = array_merge(array_values($specificParam),
			                      array_values($this->data['common']),
			                      array_values($this->data[$type]));

		$arrFields = array_merge(array_keys($specificParam),
			                     array_keys($this->data['common']),
			                     array_keys($this->data[$type]));

		return ['arrSqlData' => $arrSqlData, 'arrFields' => $arrFields];
	}


	/**
	 * Создать SQL строку для вставки фото объявления
	 * @param int $sourceId - ИД источника
	 * @return array
	 */
	public function createPhotoSql($sourceId)
	{
        if (empty($this->data['service']['images'])) {
        	return [];
		}

		$arrImages = explode('##', $this->data['service']['images']);
		$arrImages = array_filter($arrImages);
		if (empty($arrImages)) {
			return [];
		}

		return self::validatePhotoSql($sourceId, $this->data['service']['id'], $arrImages);
	}


	/**
	 * Создать hash данные объекта.
	 * Полученные хеши будут использоваться для определения, нужно ли обновлять только адрес объявления или только данные объявления, либо и адрес и данные объявления.
	 * Данные будут вставленны например в таблицу: mtkru_mtk_objects_flats__othersource_addresses
	 * @param int $object_id - id объявления в БД МТК. Это поле не передается, если объявления еще нет в МТК
	 * @return array
	 */
	public function createHashSql($object_id = 0)
	{
		// Тип объявления
		$type = $this->data['service']['type'];

		return [
		    'object_id'             => $object_id,                          // наш id объекта (Может быть не инициализирован, если объявление еще не вставленно в БД)
		    'type_id'               => $this->typeMap[$type],               // наш id типа объекта - flats, rooms, ...
	  	    'source_id'             => $this->data['service']['source_id'], // id источника
		    'source_object_id'      => $this->data['service']['id'],        // id объекта в источнике
		    'source_object_type_id' => $this->data['service']['source_object_type_id'], // id типа объекта источника
			'address_hash'          => $this->getAddressHash(),             // Формируем hash адреса
			'data_hash'             => $this->getDataHash()                 // Формируем hash данных (Исключаем из данных адрес, который храниться в свойстве $this->addressFields)
		];
	}


	/**
	 * Подготовить массив фото для их загрузки в БД
	 * @param int $sourceId - ИД источника
	 * @param int $id - id объявления
	 * @param array $arrImages -  массив изображений
	 * @param int $maxListOrder - Счетчик list_order
	 * @return array
	 */
	public static function validatePhotoSql($sourceId, $id, array $arrImages, $maxListOrder = 0)
	{
		$arrStr = [];
		foreach ($arrImages as $img) {
			// Проверка расширения файла (jpg, jpeg, png, gif)
			if (preg_match('/(.jpg$|.jpeg$|.png$|.gif$)/i', $img)) {
				$arrStr[] = [$sourceId, $id, $maxListOrder, $img];
				$maxListOrder ++;
				Logger::setValue('insertImg', $img);
			} else {
				Logger::setValue('excludeImg', $img);
			}
		};

		return $arrStr;
	}


	/**
	 * Получаем hash данных объекта
	 * @return string|null
	 */
    public function getDataHash()
	{
		// Тип объявления
		if (empty($type = $this->data['service']['type'])) {
			return null;
		}

        // Объединяем общие данные с данными специфичными для типа объявления (flats, rooms, ...)
		if (empty($arrData  = array_merge($this->data['common'], $this->data[$type]))) {
			return null;
		}

		// Формируем hash данных (Исключаем из данных адрес, который храниться в свойстве $this->addressFields)
		if (empty($dataDiff = array_diff_key($arrData, $this->addressFields))) {
			return null;
		}

		return md5(implode('', $dataDiff));
	}


	/**
	 * Формируем hash адреса
	 * @return string|null
	 */
	public function getAddressHash()
	{
		if (empty($this->data['service']['full_address'])) {
			return null;
		}

		return md5($this->data['common']['region_id'] .
			       $this->data['common']['district'] .
			       $this->data['common']['locality'] .
			       $this->data['common']['nas_punkt'] .
			       $this->data['common']['street'] .
			       $this->data['common']['housenumber']);
	}

}


