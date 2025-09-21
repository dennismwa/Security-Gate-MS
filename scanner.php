<?php
require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

$session = requireAuth($db);
$settings = getSettings($db);

// Handle QR code scanning result
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['qr_data'])) {
    $qr_data = sanitizeInput($_POST['qr_data']);
    $purpose_of_visit = sanitizeInput($_POST['purpose_of_visit'] ?? '');
    $host_name = sanitizeInput($_POST['host_name'] ?? '');
    $host_department = sanitizeInput($_POST['host_department'] ?? '');
    $notes = sanitizeInput($_POST['notes'] ?? '');
    
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
            
            // Use the new visitor data
            $stmt = $db->prepare("SELECT * FROM visitors WHERE visitor_id = ?");
            $stmt->execute([$visitor_id]);
            $visitor = $stmt->fetch();
        }
    }
    
    if ($visitor) {
        // Get last activity
        $stmt = $db->prepare("SELECT log_type FROM gate_logs WHERE visitor_id = ? ORDER BY log_timestamp DESC LIMIT 1");
        $stmt->execute([$visitor['visitor_id']]);
        $last_log = $stmt->fetch();
        
        $next_action = (!$last_log || $last_log['log_type'] == 'check_out') ? 'check_in' : 'check_out';
        
        // Record the activity
        $stmt = $db->prepare("INSERT INTO gate_logs (visitor_id, log_type, operator_id, purpose_of_visit, host_name, host_department, vehicle_number, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$visitor['visitor_id'], $next_action, $session['id'], $purpose_of_visit, $host_name, $host_department, $visitor['vehicle_number'], $notes]);
        
        logActivity($db, $session['id'], 'gate_scan', "QR scan $next_action for visitor: {$visitor['full_name']}", $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']);
        
        $scan_result = [
            'success' => true,
            'visitor' => $visitor,
            'action' => $next_action,
            'message' => ucfirst(str_replace('_', ' ', $next_action)) . ' successful'
        ];
    } else {
        $scan_result = [
            'success' => false,
            'message' => 'Invalid QR code or visitor not found'
        ];
    }
}

$message = getMessage();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($settings['system_name'] ?? 'Gate Management System'); ?> - QR Scanner</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: '<?php echo $settings['primary_color'] ?? '#2563eb'; ?>',
                        secondary: '<?php echo $settings['secondary_color'] ?? '#1f2937'; ?>',
                        accent: '<?php echo $settings['accent_color'] ?? '#10b981'; ?>'
                    }
                }
            }
        }
    </script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-gray-50">
    <!-- Navigation -->
    <nav class="bg-white shadow-sm border-b border-gray-200">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between h-16">
                <div class="flex items-center">
                    <a href="dashboard.php" class="text-gray-600 hover:text-gray-900 mr-4">
                        <i class="fas fa-arrow-left"></i>
                    </a>
                    <div class="h-10 w-10 bg-blue-600 rounded-lg flex items-center justify-center">
                        <i class="fas fa-qrcode text-white"></i>
                    </div>
                    <div class="ml-3">
                        <h1 class="text-xl font-semibold text-gray-900">QR Code Scanner</h1>
                        <p class="text-sm text-gray-500">Scan visitor QR codes for check-in/out</p>
                    </div>
                </div>
                
                <div class="flex items-center space-x-4">
                    <span class="text-sm text-gray-500">
                        Operator: <?php echo htmlspecialchars($session['operator_name']); ?>
                    </span>
                    <a href="dashboard.php" class="text-blue-600 hover:text-blue-800">
                        <i class="fas fa-home"></i>
                        <span class="ml-1">Dashboard</span>
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <?php if ($message): ?>
            <div class="mb-6 p-4 rounded-lg border <?php 
                echo $message['type'] === 'error' ? 'bg-red-50 border-red-200 text-red-700' : 
                    ($message['type'] === 'warning' ? 'bg-yellow-50 border-yellow-200 text-yellow-700' : 
                     'bg-green-50 border-green-200 text-green-700'); ?>">
                <div class="flex items-center">
                    <i class="fas <?php 
                        echo $message['type'] === 'error' ? 'fa-exclamation-circle' : 
                            ($message['type'] === 'warning' ? 'fa-exclamation-triangle' : 'fa-check-circle'); 
                        ?> mr-2"></i>
                    <?php echo htmlspecialchars($message['message']); ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if (isset($scan_result)): ?>
            <!-- Scan Result -->
            <div class="mb-6 p-6 rounded-xl border-2 <?php echo $scan_result['success'] ? 'bg-green-50 border-green-200' : 'bg-red-50 border-red-200'; ?>">
                <div class="flex items-center mb-4">
                    <div class="flex-shrink-0">
                        <div class="w-12 h-12 rounded-full flex items-center justify-center <?php echo $scan_result['success'] ? 'bg-green-100 text-green-600' : 'bg-red-100 text-red-600'; ?>">
                            <i class="fas <?php echo $scan_result['success'] ? 'fa-check' : 'fa-times'; ?> text-xl"></i>
                        </div>
                    </div>
                    <div class="ml-4">
                        <h3 class="text-lg font-semibold <?php echo $scan_result['success'] ? 'text-green-900' : 'text-red-900'; ?>">
                            <?php echo htmlspecialchars($scan_result['message']); ?>
                        </h3>
                        <?php if ($scan_result['success'] && isset($scan_result['visitor'])): ?>
                            <p class="text-sm <?php echo $scan_result['success'] ? 'text-green-700' : 'text-red-700'; ?>">
                                <?php echo htmlspecialchars($scan_result['visitor']['full_name']); ?> - 
                                <?php echo htmlspecialchars($scan_result['visitor']['phone']); ?>
                                <?php if ($scan_result['visitor']['company']): ?>
                                    (<?php echo htmlspecialchars($scan_result['visitor']['company']); ?>)
                                <?php endif; ?>
                            </p>
                        <?php endif; ?>
                    </div>
                </div>
                
                <?php if ($scan_result['success'] && isset($scan_result['visitor'])): ?>
                    <div class="bg-white p-4 rounded-lg border">
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                            <div>
                                <span class="font-medium text-gray-600">Action:</span>
                                <span class="ml-1 px-2 py-1 text-xs rounded-full <?php echo $scan_result['action'] === 'check_in' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                                    <?php echo ucfirst(str_replace('_', ' ', $scan_result['action'])); ?>
                                </span>
                            </div>
                            <div>
                                <span class="font-medium text-gray-600">Time:</span>
                                <span class="ml-1"><?php echo date('g:i A'); ?></span>
                            </div>
                            <div>
                                <span class="font-medium text-gray-600">ID:</span>
                                <span class="ml-1"><?php echo htmlspecialchars($scan_result['visitor']['visitor_id']); ?></span>
                            </div>
                            <?php if ($scan_result['visitor']['vehicle_number']): ?>
                                <div>
                                    <span class="font-medium text-gray-600">Vehicle:</span>
                                    <span class="ml-1"><?php echo htmlspecialchars($scan_result['visitor']['vehicle_number']); ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- Scanner Interface -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
            <!-- Camera Scanner -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-6">
                    <i class="fas fa-camera mr-2"></i>Camera Scanner
                </h3>
                
                <div class="relative">
                    <video id="video" class="w-full h-64 bg-gray-900 rounded-lg"></video>
                    <canvas id="canvas" class="hidden"></canvas>
                    
                    <div class="absolute inset-0 flex items-center justify-center pointer-events-none">
                        <div class="w-48 h-48 border-2 border-blue-500 rounded-lg opacity-75"></div>
                    </div>
                    
                    <div id="scanning-indicator" class="absolute top-4 right-4 bg-red-500 text-white px-3 py-1 rounded-full text-sm font-medium hidden">
                        <i class="fas fa-circle animate-pulse mr-1"></i>Scanning...
                    </div>
                </div>
                
                <div class="mt-4 flex space-x-4">
                    <button id="startCamera" class="flex-1 bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg font-medium transition-colors">
                        <i class="fas fa-play mr-2"></i>Start Camera
                    </button>
                    <button id="stopCamera" class="flex-1 bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg font-medium transition-colors" disabled>
                        <i class="fas fa-stop mr-2"></i>Stop Camera
                    </button>
                </div>
                
                <div class="mt-4 text-sm text-gray-600">
                    <div class="flex items-center mb-2">
                        <i class="fas fa-info-circle mr-2 text-blue-500"></i>
                        <span>Position the QR code within the blue frame</span>
                    </div>
                    <div class="flex items-center">
                        <i class="fas fa-lightbulb mr-2 text-yellow-500"></i>
                        <span>Ensure good lighting for best results</span>
                    </div>
                </div>
            </div>

            <!-- Manual Entry & Additional Info -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-6">
                    <i class="fas fa-keyboard mr-2"></i>Additional Information
                </h3>
                
                <form id="scanForm" method="POST" class="space-y-4">
                    <input type="hidden" id="qr_data" name="qr_data">
                    
                    <div>
                        <label for="manual_qr" class="block text-sm font-medium text-gray-700">Manual QR Entry</label>
                        <input type="text" id="manual_qr" placeholder="Enter QR code manually" 
                               class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    </div>
                    
                    <div>
                        <label for="purpose_of_visit" class="block text-sm font-medium text-gray-700">Purpose of Visit</label>
                        <input type="text" id="purpose_of_visit" name="purpose_of_visit" 
                               class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500" 
                               placeholder="Meeting, delivery, etc.">
                    </div>
                    
                    <div>
                        <label for="host_name" class="block text-sm font-medium text-gray-700">Host Name</label>
                        <input type="text" id="host_name" name="host_name" 
                               class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500" 
                               placeholder="Person being visited">
                    </div>
                    
                    <div>
                        <label for="host_department" class="block text-sm font-medium text-gray-700">Department</label>
                        <select id="host_department" name="host_department" 
                                class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                            <option value="">Select department</option>
                            <?php
                            $stmt = $db->query("SELECT department_name FROM departments WHERE is_active = 1 ORDER BY department_name");
                            while ($dept = $stmt->fetch()) {
                                echo "<option value='" . htmlspecialchars($dept['department_name']) . "'>" . htmlspecialchars($dept['department_name']) . "</option>";
                            }
                            ?>
                        </select>
                    </div>
                    
                    <div>
                        <label for="notes" class="block text-sm font-medium text-gray-700">Notes</label>
                        <textarea id="notes" name="notes" rows="3" 
                                  class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500" 
                                  placeholder="Additional notes..."></textarea>
                    </div>
                    
                    <button type="button" id="processManualQR" class="w-full bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg font-medium transition-colors">
                        <i class="fas fa-check mr-2"></i>Process Manual Entry
                    </button>
                </form>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="mt-8 grid grid-cols-1 md:grid-cols-3 gap-6">
            <a href="visitors.php?action=register" class="bg-blue-600 hover:bg-blue-700 text-white p-4 rounded-xl text-center transition-colors">
                <i class="fas fa-user-plus text-2xl mb-2"></i>
                <h4 class="font-semibold">Register New Visitor</h4>
                <p class="text-sm opacity-90">Add a new visitor to the system</p>
            </a>
            
            <a href="pre-register.php?action=create" class="bg-purple-600 hover:bg-purple-700 text-white p-4 rounded-xl text-center transition-colors">
                <i class="fas fa-calendar-plus text-2xl mb-2"></i>
                <h4 class="font-semibold">Pre-Register Visit</h4>
                <p class="text-sm opacity-90">Schedule a future visit</p>
            </a>
            
            <a href="visitors.php" class="bg-green-600 hover:bg-green-700 text-white p-4 rounded-xl text-center transition-colors">
                <i class="fas fa-users text-2xl mb-2"></i>
                <h4 class="font-semibold">View All Visitors</h4>
                <p class="text-sm opacity-90">Manage existing visitors</p>
            </a>
        </div>
    </div>

    <!-- QR Scanner Library -->
    <script src="https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.js"></script>
    
    <script>
        let video = document.getElementById('video');
        let canvas = document.getElementById('canvas');
        let context = canvas.getContext('2d');
        let scanning = false;
        let stream = null;

        const startCameraBtn = document.getElementById('startCamera');
        const stopCameraBtn = document.getElementById('stopCamera');
        const scanningIndicator = document.getElementById('scanning-indicator');

        startCameraBtn.addEventListener('click', startCamera);
        stopCameraBtn.addEventListener('click', stopCamera);

        async function startCamera() {
            try {
                stream = await navigator.mediaDevices.getUserMedia({ 
                    video: { 
                        facingMode: 'environment',
                        width: { ideal: 1280 },
                        height: { ideal: 720 }
                    } 
                });
                video.srcObject = stream;
                video.play();
                
                startCameraBtn.disabled = true;
                stopCameraBtn.disabled = false;
                scanningIndicator.classList.remove('hidden');
                scanning = true;
                
                video.addEventListener('loadedmetadata', () => {
                    canvas.width = video.videoWidth;
                    canvas.height = video.videoHeight;
                    scanForQR();
                });
            } catch (err) {
                console.error('Error accessing camera:', err);
                alert('Unable to access camera. Please ensure camera permissions are granted.');
            }
        }

        function stopCamera() {
            scanning = false;
            if (stream) {
                stream.getTracks().forEach(track => track.stop());
            }
            video.srcObject = null;
            
            startCameraBtn.disabled = false;
            stopCameraBtn.disabled = true;
            scanningIndicator.classList.add('hidden');
        }

        function scanForQR() {
            if (!scanning) return;
            
            if (video.readyState === video.HAVE_ENOUGH_DATA) {
                canvas.width = video.videoWidth;
                canvas.height = video.videoHeight;
                context.drawImage(video, 0, 0, canvas.width, canvas.height);
                
                const imageData = context.getImageData(0, 0, canvas.width, canvas.height);
                const code = jsQR(imageData.data, imageData.width, imageData.height);
                
                if (code) {
                    processQRCode(code.data);
                    return;
                }
            }
            
            requestAnimationFrame(scanForQR);
        }

        function processQRCode(qrData) {
            stopCamera();
            document.getElementById('qr_data').value = qrData;
            document.getElementById('manual_qr').value = qrData;
            
            // Auto-submit the form
            document.getElementById('scanForm').submit();
        }

        // Manual QR processing
        document.getElementById('processManualQR').addEventListener('click', function() {
            const manualQR = document.getElementById('manual_qr').value.trim();
            if (manualQR) {
                document.getElementById('qr_data').value = manualQR;
                document.getElementById('scanForm').submit();
            } else {
                alert('Please enter a QR code or scan using the camera');
            }
        });

        // Auto-focus on manual input when clicked
        document.getElementById('manual_qr').addEventListener('focus', function() {
            if (scanning) {
                stopCamera();
            }
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            if (e.key === 'F1') {
                e.preventDefault();
                startCamera();
            } else if (e.key === 'Escape') {
                stopCamera();
            }
        });

        // Auto-refresh every 30 seconds if no activity
        let lastActivity = Date.now();
        setInterval(() => {
            if (Date.now() - lastActivity > 30000 && !scanning) {
                window.location.reload();
            }
        }, 30000);

        document.addEventListener('click', () => lastActivity = Date.now());
        document.addEventListener('keypress', () => lastActivity = Date.now());
    </script>
</body>
</html>