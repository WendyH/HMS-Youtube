<?php 

$player_id = isset($_REQUEST['id'   ]) ? $_REQUEST['id'   ] : '';   // Html5 JS Player id
$js_url    = isset($_REQUEST['jsurl']) ? $_REQUEST['jsurl'] : '';   // 

if (!$player_id) {
    $player_id = preg_match('@player\w*?-([^/]+)@', $js_url, $matches) ? $matches[1] : '';
    if (!$player_id) Die('No player id');
}

$algo_cache = "cache/algorithms.json"; // ������� cache ��� ������ ���� ������ � � ������� �� ������

$algorithms = json_decode(@file_get_contents($algo_cache), true); // ��������� ������� ���������� �� ����

$algorithm  = $player_id && isset($algorithms[$player_id]) ? $algorithms[$player_id] : ''; // ������� �������� �������� �� ���� �� ID-������

if (!$algorithm) {
	if (!$js_url) die('No jsUrl');
	$algorithm = GetAlgorithm($js_url);
	if (!$algorithm) die("Can not find algorithm in the javascript");
	$algorithms[$player_id] = $algorithm;  // ������������� ������������ ID-������ ��������� ����������
	SafeWrite($algo_cache, json_encode($algorithms, JSON_PRETTY_PRINT)); // �������� ������� ���������� ���� �� ����
}

echo $algorithm;
exit();


///////////////////////////////////////////////////////////////////////////////
// ������� ������ ��������� ���������� ������� �� ������ �� js-������
function GetAlgorithm($jsUrl) {
	if      (substr($jsUrl, 0, 2)=="//") $jsUrl = "https:".$jsUrl;
	else if (substr($jsUrl, 0, 1)=="/" ) $jsUrl = "https://www.youtube.com".$jsUrl;
	$algo = "";
	$data = file_get_contents($jsUrl);
	$fns  = preg_match('/\b\w{2}=function\(a\)\{a=a\.split\(""\);(.*?)return/s', $data, $m) ? $m[1] : ''; // ������ ������ ������� ����������
	$arr  = explode(';', $fns); // �������� ������ ���������� ������ � ���������� ������� ���������� 
	// ���������� ��� ������ � ���������� ������� ���������� �� js-�������
	foreach ($arr as $func) {
		$textFunc = $func; // ����� ���������� ������� ��� �������
		// ���� ���������� ���������� ������� ������� - ���� ���������� ����� ������� � ��� ������� � js-�������
		if (preg_match('/([\$\w]+)\.(\w+)\(/s', $textFunc, $m)) {
			$obj = $m[1]; // ��� �������
			$fun = $m[2]; // ��� ��� ���������� �������
			// ������� ����� ���������� ������� � ��� ������� �� �������
			if (($obj!='a') && preg_match('/var '.$obj.'=\{.*?('.$fun.':function|function '.$fun.'\()(.*?})/s', $data, $m))
				$textFunc = $m[2]; // ���� ����� - �������������� ����� ������ ���������� ���� ��������
			else if (($obj!='a') && preg_match('/var \\'.$obj.'=\{.*?('.$fun.':function|function '.$fun.'\()(.*?})/s', $data, $m))
				$textFunc = $m[2]; // ���� ����� - �������������� ����� ������ ���������� ���� ��������
		}
		// ���� ���������� ����������� ������� - ����� ������ ���� ������� � js-�������
		if (preg_match('/a=(\w+)\(/s', $textFunc, $m)) {
			$fun = $m[1]; // ��� ���������� �������
			// ������� ����� ���������� ���� ������� �� ����������� ����� � ������ js-�������
			if (preg_match('/var '.$obj.'=\{.*?('.$fun.':function|function '.$fun.'\())(.*?})/s', $data, $m))
				$textFunc = $m[2]; // ���� ����� - �������������� ����� ������ ���������� ���� ��������
			else if (preg_match('/var \\'.$obj.'=\{.*?('.$fun.':function|function '.$fun.'\())(.*?})/s', $data, $m))
				$textFunc = $m[2]; // ���� ����� - �������������� ����� ������ ���������� ���� ��������
		}
		// �������� �������� ��������� � ���������� ������� ��� �������
		$numb = preg_match('/\(.*?(\d+)/s', $func, $m) ? $m[1] : '';
		// ���������� ��� ���������� ������� � ������ ��������
		$type = 'w'; // ��-��������� w = Swap - �������� ������� ������ ������ � �������� �� ���������� �������
		if     (preg_match('/revers/'        , $textFunc, $m)) $type = 'r'; // 'r' = Revers - ����������� ������ ����� ������
		elseif (preg_match('/(splice|slice)/', $textFunc, $m)) $type = 's'; // 's' = Slice - �������� ������ �� ��������� �����
		if (($type!='r') && ($numb==='')) continue; // ���� ��� ��������� � ������� � ��� �� Revers, �� ����������, ��� �� ������� ����������
		$algo .= ($type=='r') ? $type.' ' : $type.$numb.' '; // ��������� ��������, �������� ��� � �������� ��������� � ���� "w4 r s29" 
	}
	return trim($algo);
}

///////////////////////////////////////////////////////////////////////////////
// ���������� ������ � ���� ���� �� ����� (� ���������, ���� � ������ ���� ��� ���-�� �����)
function SafeWrite($filename, $data) {
	// ��������� ���� �� ������, ���� �����������, �������� �������
	if ($fp = @fopen($filename, 'w')) {
		$start = microtime(TRUE);
		do {
			$can_write = flock($fp, LOCK_EX); // ������� ������������� ����
			if (!$can_write) usleep(round(rand(0, 100)*1000));   // ������� �� 0 �� 100 �����������
		} while ((!$can_write) && ((microtime(TRUE)-$start) < 5));   // ��������� ������� ������������� �������� 5 ���
	        if ($can_write) { fwrite($fp, $data); flock($fp, LOCK_UN); } // �����, ������������
	        fclose($fp); // ��������� ����
	}
}