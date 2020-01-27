<?php header("Content-Type: text/html; charset=utf-8");

include 'classes.php';

$Algorithms = new IniDatabase('algorithms.ini');

$playerId = isset($_REQUEST['id'   ]) ? $_REQUEST['id'   ] : '';   // Html5 JS Player id
$jsUrl    = isset($_REQUEST['jsurl']) ? $_REQUEST['jsurl'] : '';   // 

if (!$playerId) {
    $playerId = preg_match('@player\w*?-([^/]+)@', $jsUrl, $matches) ? $matches[1] : '';
    if (!$playerId) Die('No player id');
//var_dump($Algorithms);

}

$algorithm  = $playerId ? $Algorithms[$playerId] : '';

if (!$algorithm) {
    if (!$jsUrl) die('No jsUrl');
    $algorithm = GetAlgorithm($jsUrl);
    if ($algorithm) $Algorithms[$playerId] = $algorithm;
    else die("Can not find algorithm in player javascript");
}

echo $algorithm;
exit();


//////////////////////////////////////////////////////////////////////////////////////
function GetAlgorithm($jsUrl) {
	if      (substr($jsUrl, 0, 2)=="//") $jsUrl = "https:".$jsUrl;
	else if (substr($jsUrl, 0, 1)=="/" ) $jsUrl = "https://www.youtube.com".$jsUrl;
	$algo = "";
	$data = file_get_contents($jsUrl);
	$fns  = preg_match('/\b\w{2}=function\(a\)\{a=a\.split\(""\);(.*?)return/s', $data, $m) ? $m[1] : '';
	$arr  = explode(';', $fns);
	// Iterate all operations in algorithm
	foreach ($arr as $func) {
		$textFunc = $func;
		// if called function of object - search the object and its function
		if (preg_match('/([\$\w]+)\.(\w+)\(/s', $textFunc, $m)) {
			$obj = $m[1];
			$fun = $m[2];
			if (($obj!='a') && preg_match('/var '.$obj.'=\{.*?('.$fun.':function|function '.$fun.'\()(.*?})/s', $data, $m))
				$textFunc = $m[2];
			else if (($obj!='a') && preg_match('/var \\'.$obj.'=\{.*?('.$fun.':function|function '.$fun.'\()(.*?})/s', $data, $m))
				$textFunc = $m[2];
		}
		// if called named function - search text of this function
		if (preg_match('/a=(\w+)\(/s', $textFunc, $m)) {
			$fun = $m[1];
			if (preg_match('/var '.$obj.'=\{.*?('.$fun.':function|function '.$fun.'\())(.*?})/s', $data, $m))
				$textFunc = $m[2];
			else if (preg_match('/var \\'.$obj.'=\{.*?('.$fun.':function|function '.$fun.'\())(.*?})/s', $data, $m))
				$textFunc = $m[2];
		}
		// get the value of parameter
		$numb = preg_match('/\(.*?(\d+)/s', $func, $m) ? $m[1] : '';
		// determine the type of the function
		$type = 'w';
		if     (preg_match('/revers/'        , $textFunc, $m)) $type = 'r';
		elseif (preg_match('/(splice|slice)/', $textFunc, $m)) $type = 's';
		if (($type!='r') && ($numb==='')) continue; // it's no cypher function
		$algo .= ($type=='r') ? $type.' ' : $type.$numb.' ';
	}
	return trim($algo);
}

///////////////////////////////////////////////////////////////////////////////
function LoadPage($url) {
	$headers = "User-Agent: Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/50.0.2661.102 Safari/537.36\r\n" .
	           "Accept-Encoding: gzip, deflate\r\n" .
	           "Referer: https://www.youtube.com/\r\n";
    $options = array();
    $options['http'] = array('method' => 'GET',
                             'protocol_version' => 1.1,
                             'header' => $headers);
    $context = stream_context_create($options);
    $page    = file_get_contents($url, false, $context);
    return $page;
}
