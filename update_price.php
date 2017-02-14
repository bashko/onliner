<?php
/*
 * Скрипт автаризируется на onliner,
 * скачивает архив с данными о магазинах конкурентов,
 * берет общую минимальную цену.
 * Вычитает шаг цены.
 * Если цены не равны и цена не меньше закупочной - обновляет(понижает или повышает)
 * Скрипт обновляет цены только товарам РБ у которых задан onliner model id,
 * при условие что у товара не стоит МРЦ.
 * Скрипт работает с ценами в денежных знаках образца 2000 года
 * Запускается по крону.
 *
 * */
require_once($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_before.php");

if(!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED!==true)die();

CModule::IncludeModule('iblock');
CModule::IncludeModule('currency');

// Вспомогательные функций
require_once "functions.php";

// Шаг цены 3р, в в денежных знаках образца 2009 года
$priceStep = 3;

$arrCompetitorsPrices = array();

// Для лога
$date = date('d.m.Y');
$time = date('H:i:s');
$log_path = dirname(__FILE__) . '/log/'.$date.'.txt';

// Скачиваем .gz архив с ценами в папку data
getCompetitorsPricesArchive("/data/");

// Распаковываем .gz архив с ценами
unzipCompetitorsPrices("data/competitors_prices.csv.gz");

// Массив с ценами из onliner
$arrCompetitorsPrices = getCompetitorsPrices($priceStep);

// Если цены не получены, пробуем получить еще раз
// В связи с тем что в некоторых случаях, при первой попытки скачать,
// файл сначала генерируется, и только при второй попытке скачивается.
if (empty($arrCompetitorsPrices)) {
    sleep(10);
    getCompetitorsPricesArchive("/data/");
    unzipCompetitorsPrices("data/competitors_prices.csv.gz");
    $arrCompetitorsPrices = getCompetitorsPrices($priceStep);

}

// Если массив цен не пустой, обновляем цены на сайте
if (!empty($arrCompetitorsPrices)) {

    $clearCashe = false;
    $arResult = array();

    // Идентификатор типа цены для РБ
    $PRICE_TYPE_ID = 7;

    // Фильтр на товары у котрых задан onliner id
    $arFilter = array(
        "IBLOCK_ID" => 8,
        //"ID" => 443,
        "PROPERTY_VIEW_SITE_RB_VALUE" => "Да",      // товары РБ
        "CATALOG_PRICE_10" => 0.00,                 // не МРЦ товары
        "!PROPERTY_MODEL_ID_FOR_ONLINERBY" => false // товары с onliner id
    );

    // Задаем доп. элементы при выборки, выбираем и записываем в массив.
    $arSelect = array(
        "ID",
        "IBLOCK_ID",
        "NAME",                             // название товара
        "CATALOG_GROUP_".$PRICE_TYPE_ID,    // цена
        "PROPERTY_MODEL_ID_FOR_ONLINERBY",  // onliner id
        "CATALOG_GROUP_10"                  // МРЦ
    );
    $res = CIBlockElement::GetList(array(), $arFilter, false, false, $arSelect);
    while($value = $res->Fetch()){
        $arResult[] = $value;
    }

    // Обновляем цены
    foreach ($arResult as $item) {

        // Получим закупочную цену в текущей валюте, есщи её нет закупочная цена = МРЦ
        if (empty($item["CATALOG_PURCHASING_PRICE"])) {
            $purchasingPrice = $item["CATALOG_PRICE_10"];
        } else {
            $purchasingPrice = CCurrencyRates::ConvertCurrency($item["CATALOG_PURCHASING_PRICE"], $item["CATALOG_PURCHASING_CURRENCY"], "BYR");
        }
        $purchasingPrice = number_format($purchasingPrice, 0, '.', '');

        // Цена onliner
        $onlinerMinPrice = $arrCompetitorsPrices[$item["PROPERTY_MODEL_ID_FOR_ONLINERBY_VALUE"]];
        // Цена с сайта, значение до точки
        $currentPrice = number_format($item["CATALOG_PRICE_7"], 0, '.', '');

        // Проверяем, нужно ли менять цену
        if ($currentPrice != $onlinerMinPrice && $currentPrice >= $purchasingPrice) {

            $arFields = array(
                "PRODUCT_ID" => $item["ID"],
                "CATALOG_GROUP_ID" => $PRICE_TYPE_ID,
                "PRICE" => $onlinerMinPrice
            );

            $res = CPrice::GetList(
                array(),
                array(
                    "PRODUCT_ID" => $item["ID"],
                    "CATALOG_GROUP_ID" => $PRICE_TYPE_ID
                )
            );

            if ($arr = $res->Fetch())
            {
                $result = CPrice::Update($arr["ID"], $arFields);

                $result = true;

                // Пишем результат в лог
                if ($result) {
                    error_log("\n". $time ." Цена успешно обновлена для ".$item["NAME"].", старая цена: " . $currentPrice . ", новая цена: " . $onlinerMinPrice ."\n", 3, $log_path);
                } else {
                    error_log("\n". $time ." Цена не была обновлена для ".$item["NAME"]."\n", 3, $log_path);
                }

                $clearCashe = true;
            }

        } else {
            error_log("\n". $time ." Нет необходимости менять цену ".$item["NAME"].", старая цена: " . $currentPrice . ", новая цена: " . $onlinerMinPrice . " закупочная цена: " . $purchasingPrice . "\n", 3, $log_path);
        }
    }

    // Обновляем кэш
    if ($clearCashe) {
        global $CACHE_MANAGER;
        $CACHE_MANAGER->ClearByTag("iblock_id_8");
    }

} else {
    error_log("\n". $time ." Цены не были обновлены. Вернулся пустой массив цен. "."\n", 3, $log_path);
}


