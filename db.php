<?php
error_reporting(0);
ini_set('display_errors', 0);
define('DB_SERVER', "LAPTOP-CUJSNHUV"); // or "localhost\SQLEXPRESS" if using Express edition
define('DB_USER', "sa"); // e.g., sa or the one you created
define('DB_PASSWORD', "12345");
define('DB_DATABASE', "ultramile"); 

// Connection array
$connectionInfo = array(
    "Database" => DB_DATABASE,
    "UID" => DB_USER,
    "PWD" => DB_PASSWORD
);
// Connect using sqlsrv
$conn =sqlsrv_connect(DB_SERVER, $connectionInfo);
if (!$conn) {
    die(print_r(sqlsrv_errors(), true));
}



?>