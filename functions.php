<?php
/*
 * Функция авторизируется в личном кабинете onliner,
 * скачивает архив с csv файлом - цены конкурентов магазинов,
 * сохроняет архив в указанную папку.
 * */

function getCompetitorsPricesArchive($dir)
{
    // URL скрипта авторизации
    $login_url = 'http://b2b.onliner.by/login';

    // параметры для отправки запроса - логин и пароль
    $post_data = 'email=...&password=...';

    // создание объекта curl
    $ch = curl_init();

    // используем User Agent браузера
    $agent = "Mozilla/5.0 (Windows NT 6.1; WOW64; rv:18.0) Gecko/20100101 Firefox/18.0";
    curl_setopt($ch, CURLOPT_USERAGENT, $agent);

    // задаем URL
    curl_setopt($ch, CURLOPT_URL, $login_url);

    // указываем что это POST запрос
    curl_setopt($ch, CURLOPT_POST, 1);

    // задаем параметры запроса
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);

    // указываем, чтобы нам вернулось содержимое после запроса
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

    // в случае необходимости, следовать по перенаправлени¤м
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);

    // в случае если необходимо подробно увидить заголовки
    // curl_setopt($ch, CURLOPT_VERBOSE, 1);

    /*
        Задаем параметры сохранени¤ cookie
        как правило Cookie необходимы для дальнейшей работы с авторизацией
    */

    curl_setopt($ch, CURLOPT_COOKIEJAR, dirname(__FILE__) . $dir . 'cookie.txt');
    curl_setopt($ch, CURLOPT_COOKIEFILE, dirname(__FILE__) . $dir . 'cookie.txt');

    // выполняем запрос для авторизации
    curl_exec($ch);

    // Url для скачивания файла
    $host = "http://b2b.onliner.by/shop/competitors_prices";

    // Задаем имя файлу
    $output_filename = 'competitors_prices.csv.gz';

    // Путь хранения
    $fp = fopen(dirname(__FILE__) . $dir . $output_filename, 'w');

    curl_setopt($ch, CURLOPT_URL, $host);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_FILE, $fp);

    curl_exec($ch);
    curl_close($ch);
    fclose($fp);
}

/*
 * Фукция принимает путь к архиву .gz и распаковывает
 * */
function unzipCompetitorsPrices($file_name)
{
    // Увеличение этого значения может повысить производительность
    $buffer_size = 4096; // read 4kb at a time
    $out_file_name = str_replace('.gz', '', $file_name);

    // Открываем файл (в двоичном режиме)
    $file = gzopen($file_name, 'rb');
    $out_file = fopen($out_file_name, 'wb');

    while (!gzeof($file)) {
        fwrite($out_file, gzread($file, $buffer_size));
    }

    fclose($out_file);
    gzclose($file);
}

/*
 * Функция возвращяет массив с ценами из onliner,
 * Массив ввиде onliner_id - цена
 * */
function getCompetitorsPrices($priceStep)
{
    $arrPriceData = array();

    if ($f = @fopen("data/competitors_prices.csv", "rt")) {
        // Первая строка представляет заголовки.
        // Предварительный вызов fgetcsv, позволит начать цикл со второй строки.
        fgetcsv($f,100000,';','"');
        for ($i=0; $data=fgetcsv($f,100000,';','"'); $i++ ) {
            // Формируем массив onliner_id - цена
            $arrPriceData[$data[2]] = getPrice($data[8], $priceStep);
        }
        fclose($f);
        return $arrPriceData;
    } else {
        return false;
    }
}

/*
 * Функция получает цену в денежных знаках образца 2009 года
 * Приводит string to float
 * Вычитает шаг цены
 * Возврашяет цену в денежных знаках образца 2000 года
 *
 * e.g. getPrice(374,70) return 3717000 (371.7)
 * */
function getPrice($price, $priceStep)
{
    if ($price) {
        $price = str_replace(",",".",$price);
        $price = $price - $priceStep;
        return $price * 10000;
    }
}

