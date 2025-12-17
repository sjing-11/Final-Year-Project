<?php
// public/api/export_po.php
declare(strict_types=1);
session_start();

/* ----------------------------
 * 1) LOAD DEPENDENCIES
 * ---------------------------- */
require_once __DIR__ . '/../../vendor/autoload.php';     
require_once __DIR__ . '/../../app/db.php';              
require_once __DIR__ . '/../../app/Auth.php';
require_once __DIR__ . '/../../app/ActivityLogger.php';
require_once __DIR__ . '/../../app/s3_client.php';       

// Ensure FPDF exists
if (!class_exists('FPDF')) {
    $ROOT = realpath(__DIR__ . '/../../');
    foreach ([
        $ROOT . '/vendor/setasign/fpdf/fpdf.php', // composer package file
        $ROOT . '/libraries/fpdf/fpdf.php',       // manual library fallback
    ] as $maybe) {
        if (is_file($maybe)) { require_once $maybe; break; }
    }
    if (!class_exists('FPDF')) {
        http_response_code(500);
        exit('FPDF not loaded. Run: composer require setasign/fpdf');
    }
}

/* ----------------------------
 * 2) AUTH & INPUT
 * ---------------------------- */
// Check for login and 'export_po' capability
Auth::check_staff(['export_po']);

$user_id = (int)($_SESSION['user']['user_id']);

$po_id = (int)($_GET['id'] ?? 0);
if ($po_id <= 0) {
    http_response_code(400);
    exit('Invalid Purchase Order ID.');
}

/* ----------------------------
 * 3) FETCH DATA
 * ---------------------------- */
try {
    // Company settings
    $company_settings = [];
    $stmt_settings = $pdo->query("SELECT setting_key, setting_value FROM company_settings");
    while ($row = $stmt_settings->fetch(PDO::FETCH_ASSOC)) {
        $company_settings[$row['setting_key']] = $row['setting_value'];
    }

    $company_name         = $company_settings['company_name']         ?? 'Your Company Name';
    $company_address_1    = $company_settings['company_address_line1'] ?? '123 Main Street';
    $company_address_2    = $company_settings['company_address_line2'] ?? 'City, Postcode';
    $company_email        = $company_settings['company_email']        ?? 'info@company.com';
    $company_phone        = $company_settings['company_phone']        ?? '+60 12-345 6789';
    $sst_rate             = (float)($company_settings['sst_rate']     ?? 0.08);

    // PO header data
    $sql = "
        SELECT
            po.po_id, po.issue_date, po.expected_date, po.status,
            po.description AS po_description,
            s.company_name AS supplier_name,
            s.street_address, s.city, s.postcode, s.state, s.country,
            s.phone AS supplier_phone, s.email AS supplier_email,
            u.username AS creator_username
        FROM purchase_order po
        LEFT JOIN supplier s ON po.supplier_id = s.supplier_id
        LEFT JOIN user u ON po.created_by_user_id = u.user_id
        WHERE po.po_id = :po_id
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':po_id' => $po_id]);
    $po_data = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$po_data) {
        http_response_code(404);
        exit('Purchase Order not found.');
    }

    // PO line items
    $sql_items = "
        SELECT 
            pod.quantity, pod.unit_price,
            i.item_code, i.item_name, i.measurement
        FROM purchase_order_details pod
        LEFT JOIN item i ON pod.item_id = i.item_id
        WHERE pod.po_id = :po_id
        ORDER BY i.item_name
    ";
    $stmt_items = $pdo->prepare($sql_items);
    $stmt_items->execute([':po_id' => $po_id]);
    $po_items = $stmt_items->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {
    http_response_code(500);
    error_log('[export_po] DB fetch error: ' . $e->getMessage());
    exit('Database error.');
}

/* ----------------------------
 * 4) GENERATE PDF
 * ---------------------------- */
class PDF extends FPDF
{
    function Header() {
        $this->SetFont('Arial', 'B', 18);
        $this->Cell(0, 10, 'PURCHASE ORDER', 0, 1, 'C');
        $this->Ln(5);
    }
    function Footer() {
        $this->SetY(-15);
        $this->SetFont('Arial', 'I', 8);
        $this->Cell(0, 10, 'Page '.$this->PageNo().'/{nb}', 0, 0, 'C');
    }
}

$pdf = new PDF('P', 'mm', 'A4');
$pdf->AliasNbPages();
$pdf->AddPage();
$pdf->SetFont('Arial', '', 10);
$pdf->SetMargins(10, 10, 10);

// Company Header
$pdf->SetFont('Arial', 'B', 11);
$pdf->Cell(0, 6, $company_name, 0, 1, 'L');
$pdf->SetFont('Arial', '', 10);
$pdf->Cell(0, 6, $company_address_1, 0, 1, 'L');
$pdf->Cell(0, 6, $company_address_2, 0, 1, 'L');
$pdf->Cell(0, 6, "Email: {$company_email}", 0, 1, 'L');
$pdf->Cell(0, 6, "Phone: {$company_phone}", 0, 1, 'L');
$pdf->Ln(10);

// Two-column layout positions
$current_y = $pdf->GetY();

// Supplier Info (Left column)
$pdf->SetFont('Arial', 'B', 11);
$pdf->Cell(95, 7, 'SUPPLIER', 0, 1, 'L');
$pdf->SetFont('Arial', '', 10);
$pdf->Cell(95, 6, $po_data['supplier_name'], 0, 1, 'L');
$pdf->Cell(95, 6, $po_data['street_address'], 0, 1, 'L');
$pdf->Cell(95, 6, "{$po_data['city']}, {$po_data['postcode']}", 0, 1, 'L');
$pdf->Cell(95, 6, $po_data['state'] . ($po_data['country'] ? ", {$po_data['country']}" : ''), 0, 1, 'L');
$pdf->Cell(95, 6, "Email: {$po_data['supplier_email']}", 0, 1, 'L');
$pdf->Cell(95, 6, "Phone: {$po_data['supplier_phone']}", 0, 1, 'L');

// PO Info (Right column)
$pdf->SetY($current_y);
$pdf->SetX(105);
$pdf->SetFont('Arial', 'B', 11);
$pdf->Cell(40, 7, 'PO Number:', 0, 0, 'L');
$pdf->SetFont('Arial', '', 11);
$pdf->Cell(45, 7, 'PO-' . $po_data['po_id'], 0, 1, 'R');
$pdf->SetX(105);
$pdf->SetFont('Arial', 'B', 11);
$pdf->Cell(40, 7, 'Issue Date:', 0, 0, 'L');
$pdf->SetFont('Arial', '', 11);
$pdf->Cell(45, 7, (string)$po_data['issue_date'], 0, 1, 'R');
$pdf->SetX(105);
$pdf->SetFont('Arial', 'B', 11);
$pdf->Cell(40, 7, 'Delivery Date:', 0, 0, 'L');
$pdf->SetFont('Arial', '', 11);
$pdf->Cell(45, 7, (string)$po_data['expected_date'], 0, 1, 'R');
$pdf->SetX(105);
$pdf->SetFont('Arial', 'B', 11);
$pdf->Cell(40, 7, 'Created By:', 0, 0, 'L');
$pdf->SetFont('Arial', '', 11);
$pdf->Cell(45, 7, (string)$po_data['creator_username'], 0, 1, 'R');
$pdf->SetX(105);
$pdf->SetFont('Arial', 'B', 11);
$pdf->Cell(40, 7, 'Status:', 0, 0, 'L');
$pdf->SetFont('Arial', 'B', 11);
$pdf->Cell(45, 7, (string)$po_data['status'], 0, 1, 'R');

$pdf->Ln(20);

// Items table header
$pdf->SetFont('Arial', 'B', 10);
$pdf->SetFillColor(240, 240, 240);
$pdf->Cell(80, 8, 'Item Description', 1, 0, 'C', true);
$pdf->Cell(25, 8, 'Item Code', 1, 0, 'C', true);
$pdf->Cell(20, 8, 'Qty', 1, 0, 'C', true);
$pdf->Cell(30, 8, 'Unit Price', 1, 0, 'C', true);
$pdf->Cell(35, 8, 'Total', 1, 1, 'C', true);

// Items table rows
$pdf->SetFont('Arial', '', 10);
$subtotal = 0.0;
foreach ($po_items as $item) {
    $qty   = (int)$item['quantity'];
    $price = (float)$item['unit_price'];
    $line  = $qty * $price;
    $subtotal += $line;

    $pdf->Cell(80, 7, (string)$item['item_name'], 1);
    $pdf->Cell(25, 7, (string)$item['item_code'], 1);
    $pdf->Cell(20, 7, (string)$qty, 1, 0, 'C');
    $pdf->Cell(30, 7, '$' . number_format($price, 2), 1, 0, 'R');
    $pdf->Cell(35, 7, '$' . number_format($line, 2), 1, 1, 'R');
}

$pdf->Ln(5);

// Totals section
$tax = $subtotal * $sst_rate;
$grand_total = $subtotal + $tax;

$pdf->SetFont('Arial', '', 10);
$pdf->Cell(125, 7, '', 0);
$pdf->Cell(30, 7, 'Subtotal', 1, 0, 'L');
$pdf->Cell(35, 7, '$' . number_format($subtotal, 2), 1, 1, 'R');

$pdf->Cell(125, 7, '', 0);
$pdf->Cell(30, 7, 'SST (' . (int)($sst_rate * 100) . '%)', 1, 0, 'L');
$pdf->Cell(35, 7, '$' . number_format($tax, 2), 1, 1, 'R');

$pdf->SetFont('Arial', 'B', 11);
$pdf->Cell(125, 8, '', 0);
$pdf->Cell(30, 8, 'Grand Total', 1, 0, 'L');
$pdf->Cell(35, 8, '$' . number_format($grand_total, 2), 1, 1, 'R');

// Notes
if (!empty($po_data['po_description'])) {
    $pdf->Ln(10);
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(0, 7, 'Notes:', 0, 1, 'L');
    $pdf->SetFont('Arial', '', 10);
    $pdf->MultiCell(190, 6, (string)$po_data['po_description'], 0, 'L');
}

// Footer note
$pdf->Ln(10);
$pdf->SetFont('Arial', 'I', 8);
$note = 'This is a computer generated document. Everything stated in this document is certified true and correct, and requires no signature.';
$pdf->MultiCell(0, 4, $note, 0, 'C');

// Get PDF as string
$pdfBytes = $pdf->Output('S');

/* ----------------------------
 * 5) S3 UPLOAD + HISTORY
 * ---------------------------- */
try {
    $pdo->beginTransaction();

    $s3     = s3_client();          // uses your instance profile or env creds
    $bucket = s3_bucket();          // reads your configured bucket
    $fileName = 'PO-' . $po_data['po_id'] . '-' . time() . '.pdf';
    $fileKey  = 'exports/purchase-orders/' . $fileName;

    // Upload to S3
    $s3->putObject([
        'Bucket'      => $bucket,
        'Key'         => $fileKey,
        'Body'        => $pdfBytes,
        'ACL'         => 'private',
        'ContentType' => 'application/pdf',
    ]);

    // Save S3 key to export_history
    $stmt = $pdo->prepare("
        INSERT INTO export_history (user_id, export_type, module_exported, file_name, file_path)
        VALUES (:uid, :type, :module, :fname, :path)
    ");
    $stmt->execute([
        ':uid'    => $user_id,
        ':type'   => 'pdf',
        ':module' => 'PurchaseOrder',
        ':fname'  => $fileName,
        ':path'   => $fileKey,
    ]);

    // Log this action
    if (class_exists('ActivityLogger')) {
        ActivityLogger::log($pdo, 'Export', 'PurchaseOrder', "Exported PO #{$po_id} to PDF ({$fileName})");
    }

    $pdo->commit();

} catch (\Aws\Exception\AwsException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[export_po] S3 error: ' . $e->getMessage());
    // Continue, user still gets the PDF stream

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[export_po] General error: ' . $e->getMessage());
    // Continue, user still gets the PDF stream
}

/* ----------------------------
 * 6) STREAM PDF TO BROWSER
 * ---------------------------- */
while (ob_get_level()) { ob_end_clean(); }
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="PO-' . $po_data['po_id'] . '.pdf"');
header('Content-Length: ' . strlen($pdfBytes));
echo $pdfBytes;
exit;
