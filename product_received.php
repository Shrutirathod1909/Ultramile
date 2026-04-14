    <?php
    error_reporting(E_ALL);
    ini_set('display_errors', 1);

    header("Content-Type: application/json");

    require_once "db.php";

    // ================= FUNCTION =================
    function barcode() {
        return "UM" . rand(100000, 999999);
    }

    // ================= MANUAL INSERT =================
    if (isset($_POST['save'])) {

        $name = $_POST['product_name'];
        $sku  = $_POST['sku_code'];
        $price = $_POST['price'];
        $qty  = $_POST['qty'];

        for ($i = 0; $i < $qty; $i++) {

            $sql = "INSERT INTO inventory 
            (product_name, sku_code, purchase_price, barcode, status)
            VALUES (?, ?, ?, ?, 'received')";

            $stmt = $conn->prepare($sql);
            $stmt->execute([$name, $sku, $price, barcode()]);
        }

        echo "Product Received Successfully";
    }

    // ================= EXCEL UPLOAD =================
    if (isset($_POST['upload'])) {

        require 'PHPExcel/IOFactory.php';

        $file = $_FILES['excel']['tmp_name'];
        $obj = PHPExcel_IOFactory::load($file);
        $sheet = $obj->getActiveSheet();
        $rows = $sheet->getHighestRow();

        for ($i = 2; $i <= $rows; $i++) {

            $name  = $sheet->getCell("B$i")->getValue();
            $sku   = $sheet->getCell("C$i")->getValue();
            $price = $sheet->getCell("E$i")->getValue();
            $qty   = $sheet->getCell("H$i")->getValue();

            for ($j = 0; $j < $qty; $j++) {

                $sql = "INSERT INTO inventory 
                (product_name, sku_code, purchase_price, barcode, status)
                VALUES (?, ?, ?, ?, 'received')";

                $stmt = $conn->prepare($sql);
                $stmt->execute([$name, $sku, $price, barcode()]);
            }
        }

        echo "Excel Uploaded Successfully";
    }
    ?>

