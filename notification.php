<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header("Content-Type: application/json");

require_once "db.php";

$response = array();

// ================= MARK AS READ =================
if(isset($_GET['read_id'])){
    $id = $_GET['read_id'];

    $sql = "UPDATE inventory SET readable = 1 WHERE inventory_id = ?";
    $params = array($id);
    sqlsrv_query($conn, $sql, $params);

    $response['message'] = "Notification marked as read";
}

// ================= COUNT =================
$count_sql = "SELECT COUNT(*) as total FROM inventory WHERE readable = 0";
$count_res = sqlsrv_query($conn, $count_sql);
$count_row = sqlsrv_fetch_array($count_res);

$response['unread_count'] = $count_row['total'];

// ================= LIST =================
$sql = "SELECT inventory_id, product_name, invoice_no, created_on 
        FROM inventory 
        WHERE readable = 0
        ORDER BY inventory_id DESC";

$result = sqlsrv_query($conn, $sql);

$notifications = array();

while($row = sqlsrv_fetch_array($result)) {

    $notifications[] = array(
        "id" => $row['inventory_id'],
        "product_name" => trim($row['product_name']),
        "invoice_no" => trim(preg_replace('/\s+/', ' ', $row['invoice_no'])),
        "date" => $row['created_on'],
        "mark_read_url" => "notification.php?read_id=".$row['inventory_id']
    );
}

$response['notifications'] = $notifications;

// ================= FINAL OUTPUT =================
echo json_encode($response);
?>