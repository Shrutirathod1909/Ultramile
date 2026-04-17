<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header("Content-Type: application/json");
include "db.php";

$response = [
    "status" => true,
    "pincodes" => [],
    "cities" => [],
    "states" => []
];

// 🔹 PINCODES
$sql1 = "SELECT DISTINCT pincode 
         FROM sample_pincode_list 
         WHERE pincode IS NOT NULL 
         ORDER BY pincode";

$stmt1 = sqlsrv_query($conn, $sql1);

while ($row = sqlsrv_fetch_array($stmt1, SQLSRV_FETCH_ASSOC)) {
    $response["pincodes"][] = (string)$row["pincode"];
}

// 🔹 CITIES
$sql2 = "SELECT DISTINCT city_name 
         FROM sample_pincode_list 
         WHERE city_name IS NOT NULL 
         ORDER BY city_name";

$stmt2 = sqlsrv_query($conn, $sql2);

while ($row = sqlsrv_fetch_array($stmt2, SQLSRV_FETCH_ASSOC)) {
    $response["cities"][] = $row["city_name"];
}

// 🔹 FULL LOCATION DATA (IMPORTANT 🔥)
$sql3 = "SELECT 
            pincode, 
            city_name, 
            state_name 
         FROM sample_pincode_list 
         WHERE pincode IS NOT NULL";

$stmt3 = sqlsrv_query($conn, $sql3);

while ($row = sqlsrv_fetch_array($stmt3, SQLSRV_FETCH_ASSOC)) {
    $response["states"][] = [
        "pincode" => (string)$row["pincode"],
        "city_name" => $row["city_name"],
        "state_name" => $row["state_name"]
    ];
}

echo json_encode($response);
?>