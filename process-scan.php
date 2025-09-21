<?php
require_once 'config/database.php';

header('Content-Type: application/json');

$database = new Database();
$db = $database->getConnection();

$session = requireAuth($db);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$qr_data = sanitizeInput($_POST['qr_data'] ?? '');
$purpose_of_visit = sanitizeInput($_POST['purpose_of_visit'] ?? '');
$host_name = sanitizeInput($_POST['host_name'] ?? '');
$host_department = sanitizeInput($_POST['host_department'] ?? '');
$notes = sanitizeInput($_POST['notes'] ?? '');

if (empty($qr_data)) {
    echo json_encode(['success' => false, 'message' => 'QR code data is required']);
    exit;
}

try {
    // Find visitor by QR code
    $stmt = $db->prepare("SELECT * FROM visitors WHERE qr_code = ? AND status = 'active'");
    $stmt->execute([$qr_data]);
    $visitor = $stmt->fetch();
    
    if (!$visitor) {
        // Check if it's a pre-registration QR
        $stmt = $db->prepare("SELECT * FROM pre_registrations WHERE qr_code = ? AND status = 'approved' AND visit_date >= CURDATE()");
        $stmt->execute([$qr_data]);
        $pre_reg = $stmt->fetch();
        
        if ($pre_reg) {
            // Create visitor from pre-registration
            $visitor_id = generateUniqueId('VIS');
            $new_qr = generateQRCode($visitor_id . $pre_reg['phone']);
            
            $stmt = $db->prepare("INSERT INTO visitors (visitor_id, full_name, phone, email, company, vehicle_number, qr_code, is_pre_registered) VALUES (?, ?, ?, ?, ?, ?, ?, 1)");
            $stmt->execute([$visitor_id, $pre_reg['full_name'], $pre_reg['phone'], $pre_reg['email'], $pre_reg['company'], $pre_reg['vehicle_number'], $new_qr]);
            
            // Update pre-registration status
            $stmt = $db->prepare("UPDATE pre_registrations SET status = 'used' WHERE id = ?");
            $stmt->execute([$pre_reg['id']]);
            
            // Get the new visitor data
            $stmt = $db->prepare("SELECT * FROM visitors WHERE visitor_id = ?");
            $stmt->execute([$visitor_id]);
            $visitor = $stmt->fetch();
        }
    }
    
    if (!$visitor) {
        echo json_encode(['success' => false, 'message' => 'Invalid QR code or visitor not found']);
        exit;
    }
    // Record the activity
$stmt = $db->prepare("INSERT INTO gate_logs (visitor_id, log_type, operator_id, purpose_of_visit, host_name, host_department, vehicle_number, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
$stmt->execute([$visitor['visitor_id'], $next_action, $session['id'], $purpose_of_visit, $host_name, $host_department, $visitor['vehicle_number'], $notes]);

// Create notification
$action_text = $next_action === 'check_in' ? 'checked in' : 'checked out';
createNotification($db, $next_action, ucfirst(str_replace('_', ' ', $next_action)), "Visitor {$visitor['full_name']} has $action_text", $visitor['visitor_id'], $session['id']);
    // Get last activity to determine next action
    $stmt = $db->prepare("SELECT log_type FROM gate_logs WHERE visitor_id = ? ORDER BY log_timestamp DESC LIMIT 1");
    $stmt->execute([$visitor['visitor_id']]);
    $last_log = $stmt->fetch();
    
    $next_action = (!$last_log || $last_log['log_type'] == 'check_out') ? 'check_in' : 'check_out';
    
    // Record the activity
    $stmt = $db->prepare("INSERT INTO gate_logs (visitor_id, log_type, operator_id, purpose_of_visit, host_name, host_department, vehicle_number, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$visitor['visitor_id'], $next_action, $session['id'], $purpose_of_visit, $host_name, $host_department, $visitor['vehicle_number'], $notes]);
    
    // Log activity
    logActivity($db, $session['id'], 'gate_scan', "QR scan $next_action for visitor: {$visitor['full_name']}", $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']);
    
    echo json_encode([
        'success' => true,
        'action' => $next_action,
        'visitor' => [
            'visitor_id' => $visitor['visitor_id'],
            'full_name' => $visitor['full_name'],
            'phone' => $visitor['phone'],
            'company' => $visitor['company'],
            'vehicle_number' => $visitor['vehicle_number']
        ],
        'message' => ucfirst(str_replace('_', ' ', $next_action)) . ' successful for ' . $visitor['full_name'],
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    
} catch (Exception $e) {
    error_log("Scan processing error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'An error occurred while processing the scan']);
}
?>