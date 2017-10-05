<?php
/**
 * Template: Singleton
 * Валидатор объявления
 * User: Rudi
 * Date: 20.04.2017
 */

namespace objects\validate;

class Validate
{
	/**
	 * Флаг указывает на то, что инициализирован ли валидатор
	 * @var bool
	 */
	protected static $isInit = false;

	/**
	 * Минимальная цена (НЕ для продажи)
	 * @var int
	 */
    protected static $DEFAULT_RUR_PRICE_MIN = 100;

	/**
	 * Максимальная цена
	 * @var int
	 */
    protected static $RUR_PRICE_MAX = 2000000000;

	/**
	 * Массив сокращений, будут удалены из описания номера дома, используются
	 * при проверке номера дома
	 * @var array
	 */
	protected static $houseNumSanitize = [
		'default' => [
			'домовладение',
			'сооружение',
			'строение',
			'владение',
			'подъезд',
			'участок',
			'позиция',
			'литера',
			'секция',
			'корпус',
			'объект',
			'здание',
			'номер',
			'блок',
			'корп.',
			'влад.',
			'соор.',
			'КВ-Л',
			'двлд',
			'кор.',
			'лит.',
			'лит',
			'соор',
			'стр.',
			'мкр.',
			'сек.',
			'поз.',
			'под.',
			'лит.',
			'дом',
			'поз',
			'мкр',
			'вл.',
			'уч.',
			'ВЛ',
			'Гк',
			'уч',
			'д.',
			'/',
			'№'
		],
		'new_flat' => []
	];


	/**
	 * Инициализация валидатора
	 */
	public static function init()
	{
		if(self::$isInit === false) {
			self::$houseNumSanitize['new_flat'] = array_merge(self::$houseNumSanitize['default'], ['участок', 'уч.', 'уч']);
			// Переводим все вхождения массива $houseNumSanitize в нижний регистр и сортируем по размеру строки
			self::$houseNumSanitize = array_map(function ($exclude) {
				return \CMS::length_sort(array_map(function ($option) { return mb_strtolower($option, 'utf-8'); }, $exclude));
			}, self::$houseNumSanitize);

			// Сигнализируем о том, что инициализация валидатора произошла
			self::$isInit = true;
		}
	}


	/**
	 * @param array $param
	 * @return bool
	 */
	public static function validateHouseNumber(array $param)
	{
		// Инициализация валидатора, если это нужно.
		if (self::$isInit === false) {
			self::init();
		}

		if (empty($param['houseNumber'])) {
			return false;
		}

		// Является ли объект новостройкой
		$sanitizeKey = !empty($param['type']) && $param['type'] == 30 ? 'new_flat' : 'default';

		// Удаляем из номера дома слова, предопределенные в массиве self::$houseNumSanitize
		$shotHouseNumber = trim((str_ireplace(self::$houseNumSanitize[$sanitizeKey], '', mb_strtolower($param['houseNumber'], 'utf-8'))));

		// Удаление пробелов
		$shotHouseNumber = str_ireplace(' ', '', $shotHouseNumber);

		// Если поле адреса разделено запятыми и последняя часть адреса содержит цифры(допускаем, что могут быть
		// и буквы, например: 12к2 или 110стр4), а длина не превышает 15 символов, предполагаем, что это номер дома
		if (preg_replace('/\D/', '', $shotHouseNumber) && mb_strlen($shotHouseNumber, 'utf-8') <= 15) {
			// Проверяем процентное соотношение букв к цифрам (цифр должно быть не менее 50%, если длина строки более 10 символов)
			// Встречаются и такие номера дома: 111К111С111
			if (\CMS::checkNumPercent($shotHouseNumber) >= 50) {
				return true;
			} elseif (mb_strlen($shotHouseNumber, 'utf-8') <= 4 && \CMS::checkNumPercent($shotHouseNumber) >= 25) {
				return true;
			}
		}

		return false;
	}


	/**
	 * Валидация цены
	 * @param $price int - Цена
	 * @param $realtyType string - Тип объявления (flats, rooms, etc.)
	 * @param $dealType int - Тип сделки (10 - продажа, 20 - аренда)
	 * @param null $regionID int - номер региона
	 * @return bool|string
	 */
	public static function validatePrice ($price, $realtyType, $dealType, $regionID = null)
	{
		$minPrice = 0;

		// Продажа
		if ($dealType == 10) {
			if (in_array($realtyType, ['flats'])) {
				$minPrice = (isset($regionID) && in_array($regionID, [77, 78]))? 1000000 :  100000;
			} else {
				$minPrice = 50000;
			}
		}

		// Остальное
		$minPrice = empty($minPrice) ? self::$DEFAULT_RUR_PRICE_MIN : $minPrice;

		// Проверка
		if ($price < $minPrice || $price > self::$RUR_PRICE_MAX) {
			return false;
		}

		return true;
	}

}


