<?php 
header("Content-Type: text/javascript; charset=utf-8");        // Указываем заголовок, что будем отдавать json
header("Access-Control-Allow-Origin: http://api.lostcut.net"); // Заголовок, разрешающий вызывать из браузеров с этого домена

$video_id = isset($_REQUEST['v'    ]) ? $_REQUEST['v'    ] : ''  ; // Youtube video id
$lang     = isset($_REQUEST['lang' ]) ? $_REQUEST['lang' ] : 'ru'; // Language code (optional)
$token    = isset($_REQUEST['token']) ? $_REQUEST['token'] : ''  ; // Access token (optional)

if (!$video_id) die(json_encode(["status"=>"error", "message"=>"No video id in parameters"]));	

$video_url  = "https://www.youtube.com/watch?v=$video_id";
$algo_cache = "cache/algorithms.json"; // Каталог cache уже должен быть создан и с правами на запись

// Загружаем страницу видео с youtube
$options = array(
  'http'=>array(
    'method'=>"GET",
    'header'=>"User-Agent: Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/43.0.2357.124 Safari/537.36\r\n".
              "Origin: https://www.youtube.com\r\n"
  )
);
// Если передан token, то устанавливаем его в заголовок Authorization
if ($token) $options['http']['header'] .= "Authorization: Bearer $token\r\n" ;

$page_html = @file_get_contents($video_url, false, stream_context_create($options));

// Ищем в загруженной странице json конфиг
if (!preg_match('/player.config\s*?=\s*?({.*?});/s', $page_html, $matches)) {
	// Если не найден config, пытаемся найти сообщение, почему видео не доступно или просто сообщаем об этом
	$msg = preg_match('/<h[^>]+unavailable-message.*?<\/h\d>/s', $page_html, $matches) ? $matches[0] : '';
	if (!$msg) $msg = preg_match('@<p[^>]+largeText.*?</p>@s'  , $page_html, $matches) ? $matches[0] : '';
	if ($msg) $msg = "Youtube: ".trim(strip_tags($msg));
	else      $msg = "Video page do not contains player.config json object";
	die(json_encode(["status"=>"error", "message"=>$msg]));
}

$player_config   = json_decode($matches[1], true);    // Превращаем найденный config в именованный массив
$player_response = json_decode($player_config["args"]["player_response"], true); // Там же должны быть json данные в поле "player_response"
$streaming_data  = $player_response["streamingData"]; // Получаем массив с данными о доступных форматах

$algorithms = json_decode(@file_get_contents($algo_cache), true); // Загружаем таблицу алгоритмов из кэша
$js_url     = $player_config["assets"]["js"];                     // Получаем url js-скрипта, из которого можно получить алгоритм расшифровки
$player_id  = preg_match('@player\w*?-([^/]+)@', $js_url, $matches) ? $matches[1   ] : ''; // ID-плеера получаем из имени js-скрипта
$algorithm  = $player_id && isset($algorithms[$player_id]) ? $algorithms[$player_id] : ''; // Попытка получить алгоритм из кэша по ID-плеера


$formats          = array(); // Объект для хранения ссылок на файлы обычных видео со звуком
$adaptive_formats = array(); // Объект для хранения ссылок на раздельные видео или аудио файлы 

// Проверяем, есть ли в streamingData таблица видео со звуком
if (isset($streaming_data["formats"])) {
	foreach ($streaming_data["formats"] as $format_info) {
		// Если в информации о формате есть поле "cipher", то url нужно подписывать
		if (isset($format_info["cipher"])) {
			// Если алгоритм ещё не получен из кэша по ID-плеера, вызываем функцию получения этого алгоритма из js-скрипта
			if (!$algorithm) $algorithm = GetAlgorithm($js_url);
			parse_str($format_info["cipher"], $cipher);      // Разбираем форматную строку в массив
			$s   = $cipher["s"  ];                           // Заготовка подписи (её нужно будет декодировать)
			$par = $cipher["sp" ];                           // Имя параметра подписи
			$url = $cipher["url"];                           // Подписываемый url
			$sig = YoutubeDecrypt($s, $algorithm);           // Получаем подпись из заготовки "s" по указанному алгоритму
			$format_info["url" ] = "$url&$par=$sig";         // Сохраняем url с добавлением полученной подписи
			unset($format_info["cipher"]);                   // Удаляем за ненадобностью поле "cipher" из информации о формате
		}
		$formats[] = $format_info; // Добавляем в коллекцию ссылок информацию о формате со ссылкой на медиа-поток
	}
}

// Проверяем, есть ли в streamingData таблица на раздельные аудио и видео потоки
if (isset($streaming_data["adaptiveFormats"])) {
	foreach ($streaming_data["adaptiveFormats"] as $format_info) {
		// Если в информации о формате есть поле "cipher", то url нужно подписывать
		if (isset($format_info["cipher"])) {
			// Если алгоритм ещё не получен из кэша по ID-плеера, вызываем функцию получения этого алгоритма из js-скрипта
			if (!$algorithm) $algorithm = GetAlgorithm($js_url);
			parse_str($format_info["cipher"], $cipher);      // Разбираем форматную строку в массив
			$s   = $cipher["s"  ];                           // Заготовка подписи (её нужно будет декодировать)
			$par = $cipher["sp" ];                           // Имя параметра подписи
			$url = $cipher["url"];                           // Подписываемый url
			$sig = YoutubeDecrypt($s, $algorithm);           // Получаем подпись из заготовки "s" по указанному алгоритму
			$format_info["url" ] = "$url&$par=$sig";         // Сохраняем url с добавлением полученной подписи
			unset($format_info["cipher"]);                   // Удаляем за ненадобностью поле "cipher" из информации о формате
		}
		$adaptive_formats[] = $format_info; // Добавляем в коллекцию ссылок информацию о формате со ссылкой на медиа-поток
	}
}

// Если в кэше алгоритмов такого ID-плеера ещё не было, то устанавливаем и сохраняем
if (!isset($algorithms[$player_id]) || !$algorithms[$player_id]) {
	$algorithms[$player_id] = $algorithm;  // Устанавливаем соответствие ID-плеера алгоритму дешифровки
	SafeWrite($algo_cache, json_encode($algorithms, JSON_PRETTY_PRINT)); // Вызываем функцию сохранения кэша на диск
}

// Поиск субтитров для языка, указанного языка в переменной $lang
$subtUrl = ""; // Ссылка на субтитры указанного языка
$subtEng = ""; // Ссылка на английские субтитры (для перевода с них, если указанного языка не найдено)
if (isset($player_response["captions"]["playerCaptionsTracklistRenderer"]["captionTracks"])) {
	foreach ($player_response["captions"]["playerCaptionsTracklistRenderer"]["captionTracks"] as $subt) {
		if ($subt["languageCode"]==$lang) $subtUrl = $subt["baseUrl"];
		if ($subt["languageCode"]=="en" ) $subtEng = $subt["baseUrl"];
	}
}
if (!$subtUrl && $subtEng) $subtUrl = "$subtEng&tlang=$lang"; // Если нашего языка субтитров нет, переводим с английского


// Всё готово. Формируем ответ.
$result = array();
$result["status"       ] = "ok";
$result["videoId"      ] = isset($player_response["videoDetails"]["videoId"         ]) ? $player_response["videoDetails"]["videoId"         ] : "";
$result["title"        ] = isset($player_response["videoDetails"]["title"           ]) ? $player_response["videoDetails"]["title"           ] : "";
$result["lengthSeconds"] = isset($player_response["videoDetails"]["lengthSeconds"   ]) ? $player_response["videoDetails"]["lengthSeconds"   ] : "";
$result["channelId"    ] = isset($player_response["videoDetails"]["channelId"       ]) ? $player_response["videoDetails"]["channelId"       ] : "";
$result["description"  ] = isset($player_response["videoDetails"]["shortDescription"]) ? $player_response["videoDetails"]["shortDescription"] : "";
$result["author"       ] = isset($player_response["videoDetails"]["author"          ]) ? $player_response["videoDetails"]["author"          ] : "";
$result["hlsUrl"       ] = isset($streaming_data["hlsManifestUrl"]) ? $streaming_data["hlsManifestUrl"] : "";
$result["captions"     ] = $subtUrl."&fmt=srv3";
$result["expiresInSeconds"] = $streaming_data["expiresInSeconds"];
$result["formats"         ] = $formats;
$result["adaptiveFormats" ] = $adaptive_formats;
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

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
		elseif (preg_match('/(splice|slice)/', $textFunc, $m)) $type = 's'; // 's' = Slice - получить строку c указанного индекса
		if (($type!='r') && ($numb==='')) continue; // Если нет параметра у функции и это не Revers, то пропускаем, это не команда дешифровки
		$algo .= ($type=='r') ? $type.' ' : $type.$numb.' '; // Формируем алгоритм, указывая тип и значение параметра в виде "w4 r s29" 
	}
	return trim($algo);
}

///////////////////////////////////////////////////////////////////////////////
// Функция дешифровки заготовки подписи по указанному алгоритму в виде строки "w12 s34 r w9"
function YoutubeDecrypt($sig, $algorithm) {
	$method = explode(" ", $algorithm); // Получаем массив команд дешифровки
	if (!$sig) return "";               // Если нет заготовки подписи, то и дешифровать нечего
	foreach($method as $m)
	{	// Первая буква команды - тип: r - revers,  s - slice,  w - swap
		// Вторая буква команды - значение параметра вызываемой команды
		if           ($m     =='r') $sig = strrev($sig);
		elseif(substr($m,0,1)=='s') $sig = substr($sig, (int)substr($m, 1));
		elseif(substr($m,0,1)=='w') $sig =   swap($sig, (int)substr($m, 1));
	}
	return $sig;
}

///////////////////////////////////////////////////////////////////////////////
// Поменять местами первый символ в строке с символом по указанному индексу
function swap($str, $b) {
	$c = $str[0]; $str[0] = $str[$b]; $str[$b] = $c;
	return $str;
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
