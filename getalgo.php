<?php header("Content-Type: text/html; charset=utf-8");
include 'classes.php';

$playerId = isset($_REQUEST['id']) ? $_REQUEST['id'] : '';   // Html5 JS Player id

$Algorithms = new IniDatabase('algorithms.ini');
$algo = $Algorithms[$playerId];

echo $algo;