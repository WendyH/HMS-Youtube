<?php 

$player_id = isset($_REQUEST['id'   ]) ? $_REQUEST['id'   ] : '';   // Html5 JS Player id
$js_url    = isset($_REQUEST['jsurl']) ? $_REQUEST['jsurl'] : '';   // 

if (!$player_id) {
    $player_id = preg_match('@player\w*?-([^/]+)@', $js_url, $matches) ? $matches[1] : '';
    if (!$player_id) Die('No player id');
}

$algo_cache = "cache/algorithms.json"; // Каталог cache уже должен быть создан и с правами на запись

$algorithms = json_decode(@file_get_contents($algo_cache), true); // Загружаем таблицу алгоритмов из кэша

$algorithm  = $player_id && isset($algorithms[$player_id]) ? $algorithms[$player_id] : ''; // Попытка получить алгоритм из кэша по ID-плеера

if (!$algorithm) {
	if (!$js_url) die('No jsUrl');
	$algorithm = GetAlgorithm($js_url);
	if (!$algorithm) die("Can not find algorithm in the javascript");
	$algorithms[$player_id] = $algorithm;  // Устанавливаем соответствие ID-плеера алгоритму дешифровки
	SafeWrite($algo_cache, json_encode($algorithms, JSON_PRETTY_PRINT)); // Вызываем функцию сохранения кэша на диск
}

echo $algorithm;
exit();


///////////////////////////////////////////////////////////////////////////////
// Функция поиска алгоритма дешифровки подписи по ссылке на js-скрипт
function GetAlgorithm($jsUrl) {
	if      (substr($jsUrl, 0, 2)=="//") $jsUrl = "https:".$jsUrl;
	else if (substr($jsUrl, 0, 1)=="/" ) $jsUrl = "https://www.youtube.com".$jsUrl;
	$algo = "";
	$data = file_get_contents($jsUrl);
	$fns  = preg_match('/\b\w{2}=function\(a\)\{a=a\.split\(""\);(.*?)return/s', $data, $m) ? $m[1] : ''; // Шаблон поиска функции дешифровки
	$arr  = explode(';', $fns); // Получаем массив вызываемых команд в полученной функции дешифровки 
	// Перебираем все вызовы в полученной функции дешифровки из js-скрипта
	foreach ($arr as $func) {
		$textFunc = $func; // Текст вызываемой команды или функции
		// Если вызывается конкретная функция объекта - ищем объявление этого объекта и его функции в js-скрипте
		if (preg_match('/([\$\w]+)\.(\w+)\(/s', $textFunc, $m)) {
			$obj = $m[1]; // Имя объекта
			$fun = $m[2]; // Имя его вызываемой функции
			// Попытка найти объявление объекта и его функции по шаблону
			if (($obj!='a') && preg_match('/var '.$obj.'=\{.*?('.$fun.':function|function '.$fun.'\()(.*?})/s', $data, $m))
				$textFunc = $m[2]; // Если нашли - перезаписываем текст вызова дешифровки этой итерации
			else if (($obj!='a') && preg_match('/var \\'.$obj.'=\{.*?('.$fun.':function|function '.$fun.'\()(.*?})/s', $data, $m))
				$textFunc = $m[2]; // Если нашли - перезаписываем текст вызова дешифровки этой итерации
		}
		// Если вызывается именованная функция - поиск текста этой функции в js-скрипте
		if (preg_match('/a=(\w+)\(/s', $textFunc, $m)) {
			$fun = $m[1]; // Имя вызываемой функции
			// Попытки найти объявление этой функции по полученному имени в тексте js-скрипта
			if (preg_match('/var '.$obj.'=\{.*?('.$fun.':function|function '.$fun.'\())(.*?})/s', $data, $m))
				$textFunc = $m[2]; // Если нашли - перезаписываем текст вызова дешифровки этой итерации
			else if (preg_match('/var \\'.$obj.'=\{.*?('.$fun.':function|function '.$fun.'\())(.*?})/s', $data, $m))
				$textFunc = $m[2]; // Если нашли - перезаписываем текст вызова дешифровки этой итерации
		}
		// Получаем значение параметра в вызываемой команде или функции
		$numb = preg_match('/\(.*?(\d+)/s', $func, $m) ? $m[1] : '';
		// Определяем тип вызываемой функции в данной итерации
		$type = 'w'; // По-умолчанию w = Swap - поменять местами первый символ с символом по указанному индексу
		if     (preg_match('/revers/'        , $textFunc, $m)) $type = 'r'; // 'r' = Revers - перевернуть строку задом наперёд
		elseif (preg_match('/(splice|slice)/', $textFunc, $m)) $type = 's'; // 's' = Slice - обрезать строку на указанную длину
		if (($type!='r') && ($numb==='')) continue; // Если нет параметра у функции и это не Revers, то пропускаем, это не команда дешифровки
		$algo .= ($type=='r') ? $type.' ' : $type.$numb.' '; // Формируем алгоритм, указывая тип и значение параметра в виде "w4 r s29" 
	}
	return trim($algo);
}

///////////////////////////////////////////////////////////////////////////////
// Безопасная запись в файл кэша на диске (с ожиданием, если в данный файл уже кто-то пишет)
function SafeWrite($filename, $data) {
	// Открываем файл на запись, если отсутствует, пытаемся создать
	if ($fp = @fopen($filename, 'w')) {
		$start = microtime(TRUE);
		do {
			$can_write = flock($fp, LOCK_EX); // Попытка заблокировать файл
			if (!$can_write) usleep(round(rand(0, 100)*1000));   // Ожидаем от 0 до 100 миллисекунд
		} while ((!$can_write) && ((microtime(TRUE)-$start) < 5));   // Повторяем попытки заблокировать максимум 5 сек
	        if ($can_write) { fwrite($fp, $data); flock($fp, LOCK_UN); } // Пишем, разблокируем
	        fclose($fp); // Закрываем файл
	}
}