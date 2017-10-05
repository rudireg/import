<?php
/**
 * Author: Rudi
 * Date: 06.04.2017
 * Description: Интерфейс, описывает какие методы должен реализовать каждый класс импорта. (cian, avito, winner etc.)
 */
namespace import_interface\import;

interface Import {

	/**
	 * Получить имя источника импорта
	 * @var
	 */
	public function getImportName();

	/**
	 * Установка свойства id источника
	 * @param int $sourceId - id источника
	 * @return mixed
	 */
	public function setSourceId($sourceId);

	/**
	 * Получить id объявлений, которые подлежат импорту в базу MTK
	 */
	public function initImportIds();

	/**
	 * Создает объекты объявлений, которые будут импортированы в базу MTK
	 * Объекты создаются на основе данных полученных из спарсенной базы импорта.
	 * Объекты после создания считаются еще сырыми, то есть неотвалидированными.
	 * @return array - Возвращает созданные объекты объявлений
	 */
	public function createObjects();

	/**
	 * Вернуть массив id АКТИВНЫХ объявлений, которые существуют в базе MTK
	 * Данный массив был получен вызовом метода initMtkIds();
	 * @return mixed
	 */
	public function getMtkIds();

	/**
	 * Вернуть массив id объявлений для импорта, которые будут загруженны а базу MTK
	 * Данный массив был получен вызовом метода initImportIds();
	 * @return mixed
	 */
	public function getImportIds();

	/**
	 * Получить данные объявлений из базы импорта
	 * @param array $idsForPrepare - Массив id, по которым будет выборка из базы импорта
	 * @return mixed
	 */
	public function getImportData(array $idsForPrepare);

}