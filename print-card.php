<?php
require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

$session = requireAuth($db);
$settings = getSettings($db);

$visitor_id = $_GET['id'] ?? '';
if (empty($visitor_id)) {
    setMessage('Visitor ID is required', 'error');
    header('Location: visitors.php');
    exit;
}

$stmt = $db->prepare("SELECT * FROM visitors WHERE visitor_id = ?");
$stmt->execute([$visitor_id]);
$visitor = $stmt->fetch();

if (!$visitor) {
    setMessage('Visitor not found', 'error');
    header('Location: visitors.php');
    exit;
}

// Get company logo if exists
$company_logo = null;
if (!empty($visitor['company'])) {
    $stmt = $db->prepare("SELECT logo_path FROM companies WHERE company_name = ?");
    $stmt->execute([$visitor['company']]);
    $company_data = $stmt->fetch();
    if ($company_data && $company_data['logo_path'] && file_exists($company_data['logo_path'])) {
        $company_logo = $company_data['logo_path'];
    }
}

// Log print activity
$stmt = $db->prepare("INSERT INTO card_print_logs (visitor_id, printed_by, print_quality, copies_printed) VALUES (?, ?, 'high', 1)");
$stmt->execute([$visitor_id, $session['id']]);

logActivity($db, $session['id'], 'print_card', "Printed professional card for visitor: {$visitor['full_name']}", $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']);

// Generate card expiry date
$expiry_days = (int)getSetting($db, 'card_expiry_days', '30');
$expiry_date = date('d/m/Y', strtotime("+$expiry_days days"));
$issue_date = date('d/m/Y');
$issue_time = date('H:i');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Professional ID Card - <?php echo htmlspecialchars($visitor['full_name']); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', Arial, sans-serif;
            margin: 0;
            padding: 0;
            background: #f5f5f5;
        }
        
        @page {
            size: A4 landscape;
            margin: 0.5in;
        }
        
        @media print {
            body { 
                margin: 0; 
                background: white;
                font-family: 'Inter', Arial, sans-serif;
            }
            
            .no-print { 
                display: none !important; 
            }
            
            .print-area {
                width: 100%;
                height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 1in;
            }
            
            .id-card { 
                width: 3.375in; 
                height: 2.125in; 
                border: 2px solid #000;
                border-radius: 12px;
                overflow: hidden;
                background: white;
                box-shadow: none;
                page-break-inside: avoid;
                position: relative;
                font-size: 10px;
                line-height: 1.2;
            }
            
            .card-front, .card-back {
                width: 3.375in;
                height: 2.125in;
            }
        }
        
        .id-card {
            width: 405px; /* 3.375in * 120dpi */
            height: 255px; /* 2.125in * 120dpi */
            border: 3px solid #333;
            border-radius: 15px;
            overflow: hidden;
            background: white;
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
            position: relative;
            font-family: 'Inter', Arial, sans-serif;
            margin: 20px;
        }
        
        .card-header {
            background: linear-gradient(135deg, <?php echo $settings['primary_color'] ?? '#1e40af'; ?> 0%, <?php echo $settings['accent_color'] ?? '#059669'; ?> 100%);
            color: white;
            padding: 12px 15px;
            position: relative;
            overflow: hidden;
        }
        
        .card-header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 100%;
            height: 200%;
            background: url("data:image/svg+xml,%3Csvg width='40' height='40' viewBox='0 0 40 40' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='%23ffffff' fill-opacity='0.05'%3E%3Cpath d='M20 20c0-5.5-4.5-10-10-10s-10 4.5-10 10 4.5 10 10 10 10-4.5 10-10zm10 0c0-5.5-4.5-10-10-10s-10 4.5-10 10 4.5 10 10 10 10-4.5 10-10z'/%3E%3C/g%3E%3C/svg%3E");
            animation: float 6s ease-in-out infinite;
        }
        
        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-10px); }
        }
        
        .photo-container {
            width: 75px;
            height: 90px;
            border: 3px solid white;
            border-radius: 10px;
            overflow: hidden;
            background: linear-gradient(145deg, #f0f0f0, #e0e0e0);
            box-shadow: inset 0 2px 8px rgba(0,0,0,0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
        }
        
        .photo-container img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            object-position: center;
        }
        
        .photo-placeholder {
            color: #999;
            text-align: center;
            font-size: 11px;
        }
        
        .visitor-name {
            font-size: 16px;
            font-weight: 800;
            color: #1a1a1a;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            line-height: 1.1;
            margin-bottom: 3px;
        }
        
        .visitor-id {
            font-family: 'Courier New', monospace;
            font-size: 11px;
            font-weight: 600;
            color: #666;
            letter-spacing: 1px;
            margin-bottom: 8px;
        }
        
        .info-row {
            display: flex;
            align-items: center;
            margin-bottom: 3px;
            font-size: 11px;
        }
        
        .info-label {
            font-weight: 600;
            color: #555;
            width: 45px;
            flex-shrink: 0;
        }
        
        .info-value {
            color: #1a1a1a;
            font-weight: 500;
            flex: 1;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        
        .validity-section {
            border-top: 1px solid #e0e0e0;
            padding-top: 8px;
            margin-top: 8px;
            display: flex;
            justify-content: space-between;
            font-size: 10px;
        }
        
        .validity-item {
            text-align: center;
        }
        
        .validity-label {
            color: #666;
            font-weight: 500;
            margin-bottom: 1px;
        }
        
        .validity-value {
            color: #1a1a1a;
            font-weight: 700;
        }
        
        .security-strip {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 8px;
            background: repeating-linear-gradient(
                90deg,
                #ff6b6b 0px,
                #ff6b6b 8px,
                #4ecdc4 8px,
                #4ecdc4 16px,
                #ffd93d 16px,
                #ffd93d 24px
            );
        }
        
        .hologram {
            position: absolute;
            top: 12px;
            right: 12px;
            width: 24px;
            height: 24px;
            background: radial-gradient(circle, #ff6b6b, #4ecdc4, #45b7d1);
            border-radius: 50%;
            opacity: 0.4;
            animation: shimmer 3s linear infinite;
        }
        
        @keyframes shimmer {
            0%, 100% { opacity: 0.4; }
            50% { opacity: 0.8; }
        }
        
        /* BACK SIDE STYLES */
        .card-back {
            background: linear-gradient(145deg, #f8f9fa, #e9ecef);
            position: relative;
        }
        
        .back-header {
            background: #2d3748;
            color: white;
            padding: 10px 15px;
            text-align: center;
        }
        
        .back-header h3 {
            margin: 0;
            font-size: 14px;
            font-weight: 700;
            letter-spacing: 0.5px;
        }
        
        .back-header p {
            margin: 2px 0 0 0;
            font-size: 9px;
            opacity: 0.8;
        }
        
        .qr-section {
            padding: 15px;
            text-align: center;
            background: white;
            margin: 12px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .qr-container {
            width: 80px;
            height: 80px;
            margin: 0 auto 8px auto;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            padding: 4px;
            background: white;
        }
        
        .instructions {
            background: white;
            margin: 0 12px 12px 12px;
            padding: 10px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .instructions h4 {
            margin: 0 0 6px 0;
            font-size: 11px;
            font-weight: 700;
            color: #2d3748;
        }
        
        .instructions ul {
            margin: 0;
            padding: 0 0 0 12px;
            font-size: 9px;
            color: #4a5568;
            line-height: 1.3;
        }
        
        .instructions li {
            margin-bottom: 2px;
        }
        
        .emergency-info {
            background: #fed7d7;
            border: 1px solid #fc8181;
            margin: 0 12px;
            padding: 8px;
            border-radius: 6px;
            text-align: center;
        }
        
        .emergency-title {
            font-size: 10px;
            font-weight: 700;
            color: #c53030;
            margin-bottom: 2px;
        }
        
        .emergency-number {
            font-size: 12px;
            font-weight: 800;
            color: #c53030;
            font-family: 'Courier New', monospace;
        }
        
        .card-number {
            position: absolute;
            bottom: 8px;
            right: 12px;
            font-family: 'Courier New', monospace;
            font-size: 8px;
            color: #999;
            letter-spacing: 0.5px;
        }
        
        .preview-scale {
            transform: scale(1.2);
            margin: 30px;
        }
    </style>
</head>
<body>
    <!-- Print Controls -->
    <div class="no-print bg-white shadow-lg border-b sticky top-0 z-50">
        <div class="max-w-7xl mx-auto px-4 py-4">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">ID Card System</h1>
                    <p class="text-gray-600">visitor identification for <strong><?php echo htmlspecialchars($visitor['full_name']); ?></strong></p>
                </div>
                <div class="flex space-x-4">
                    <button onclick="window.print()" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-3 rounded-lg font-semibold shadow-lg transition-all duration-200 hover:shadow-xl">
                        <i class="fas fa-print mr-2"></i>Print  Cards
                    </button>
                    <a href="visitors.php?action=view&id=<?php echo $visitor['visitor_id']; ?>" 
                       class="bg-gray-600 hover:bg-gray-700 text-white px-6 py-3 rounded-lg font-semibold shadow-lg transition-all duration-200">
                        <i class="fas fa-arrow-left mr-2"></i>Back to Visitor
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Card Preview Section -->
    <div class="no-print bg-gray-100 py-12">
        <div class="max-w-7xl mx-auto px-4">
            <div class="bg-white rounded-2xl shadow-xl p-8">
                <h2 class="text-2xl font-bold text-gray-900 mb-8 text-center">Card Preview - Both Sides</h2>
                
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-12 justify-items-center">
                    <!-- FRONT SIDE PREVIEW -->
                    <div class="text-center">
                        <h3 class="text-lg font-semibold text-gray-700 mb-4">Front Side</h3>
                        <div class="preview-scale">
                            <div class="id-card">
                                <!-- Header -->
                                <div class="card-header">
                                    <div class="hologram"></div>
                                    <div class="flex items-center justify-between relative z-10">
                                        <div>
                                            <h2 class="text-sm font-bold"><?php echo strtoupper(htmlspecialchars($settings['organization_name'] ?? 'VISITOR ACCESS')); ?></h2>
                                            <p class="text-xs opacity-90">AUTHORIZED PERSONNEL</p>
                                        </div>
                                        <?php if ($company_logo): ?>
                                            <img src="<?php echo htmlspecialchars($company_logo); ?>" alt="Logo" class="h-8 w-auto max-w-16 object-contain">
                                        <?php else: ?>
                                            <div class="text-white opacity-75">
                                                <i class="fas fa-shield-alt text-lg"></i>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <!-- Main Content -->
                                <div class="p-3 relative">
                                    <div class="flex items-start gap-3">
                                        <!-- Photo -->
                                        <div class="photo-container">
                                            <?php if ($visitor['photo_path'] && file_exists($visitor['photo_path'])): ?>
                                                <img src="<?php echo htmlspecialchars($visitor['photo_path']); ?>" alt="Visitor Photo">
                                            <?php else: ?>
                                                <div class="photo-placeholder">
                                                    <i class="fas fa-user text-xl text-gray-400"></i>
                                                    <div class="text-xs mt-1">PHOTO</div>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <!-- Information -->
                                        <div class="flex-1 min-w-0">
                                            <div class="visitor-name">
                                                <?php echo htmlspecialchars($visitor['full_name']); ?>
                                            </div>
                                            <div class="visitor-id">
                                                ID: <?php echo htmlspecialchars($visitor['visitor_id']); ?>
                                            </div>
                                            
                                            <div class="space-y-1">
                                                <div class="info-row">
                                                    <span class="info-label">PHONE:</span>
                                                    <span class="info-value"><?php echo htmlspecialchars($visitor['phone']); ?></span>
                                                </div>
                                                
                                                <?php if ($visitor['company']): ?>
                                                <div class="info-row">
                                                    <span class="info-label">ORG:</span>
                                                    <span class="info-value"><?php echo htmlspecialchars($visitor['company']); ?></span>
                                                </div>
                                                <?php endif; ?>
                                                
                                                <?php if ($visitor['vehicle_number']): ?>
                                                <div class="info-row">
                                                    <span class="info-label">CAR:</span>
                                                    <span class="info-value"><?php echo strtoupper(htmlspecialchars($visitor['vehicle_number'])); ?></span>
                                                </div>
                                                <?php endif; ?>
                                                
                                                <?php if ($visitor['email']): ?>
                                                <div class="info-row">
                                                    <span class="info-label">EMAIL:</span>
                                                    <span class="info-value"><?php echo htmlspecialchars($visitor['email']); ?></span>
                                                </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Validity Information -->
<div class="validity-section">
    <div class="validity-item">
        <div class="validity-label">ISSUED</div>
        <div class="validity-value"><?php echo $issue_date; ?></div>
    </div>
    <div class="validity-item">
        <div class="validity-label">STATUS</div>
        <div class="validity-value" style="color: #059669;">ACTIVE</div>
    </div>
    <div class="validity-item">
        <div class="validity-label">EXPIRES</div>
        <div class="validity-value" style="color: #dc2626;"><?php echo $expiry_date; ?></div>
    </div>
</div>
                                    
                                    <!-- Card Number -->
                                    <div class="card-number">
                                        #<?php echo strtoupper(substr(md5($visitor['visitor_id'] . $visitor['phone']), 0, 8)); ?>
                                    </div>
                                </div>
                                
                                <!-- Security Strip -->
                                <div class="security-strip"></div>
                            </div>
                        </div>
                    </div>

                    <!-- BACK SIDE PREVIEW -->
                    <div class="text-center">
                        <h3 class="text-lg font-semibold text-gray-700 mb-4">Back Side</h3>
                        <div class="preview-scale">
                            <div class="id-card card-back">
                                <!-- Back Header -->
                                <div class="back-header">
                                    <h3>ACCESS VERIFICATION</h3>
                                    <p>SCAN QR CODE FOR INSTANT VERIFICATION</p>
                                </div>
                                
                                <!-- QR Code Section -->
                                <div class="qr-section">
                                    <div class="qr-container">
                                        <div id="qrcode-preview"></div>
                                    </div>
                                    <div class="text-xs font-semibold text-gray-700">SCAN FOR ACCESS</div>
                                </div>
                                
                                <!-- Instructions -->
                                <div class="instructions">
                                    <h4>ACCESS PROCEDURES:</h4>
                                    <ul>
                                        <li>Present card at security checkpoint</li>
                                        <li>Allow QR code scanning verification</li>
                                        <li>Follow escort and safety protocols</li>
                                        <li>Return card when departing premises</li>
                                    </ul>
                                </div>
                                
                                <!-- Emergency Contact -->
                                <div class="emergency-info">
                                    <div class="emergency-title">24/7 SECURITY EMERGENCY</div>
                                    <div class="emergency-number"><?php echo htmlspecialchars($settings['security_contact'] ?? '+1-800-SECURITY'); ?></div>
                                </div>
                                
                                <!-- Unique Identifier -->
                                <div class="card-number">
                                    <?php echo strtoupper(substr(md5($visitor['qr_code'] . date('Y-m-d')), 0, 16)); ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Print Area (Hidden in Preview) -->
    <div class="print-area">
        <!-- FRONT SIDE FOR PRINTING -->
        <div class="id-card">
            <!-- Header -->
            <div class="card-header">
                <div class="hologram"></div>
                <div class="flex items-center justify-between relative z-10">
                    <div>
                        <h2 class="text-sm font-bold"><?php echo strtoupper(htmlspecialchars($settings['organization_name'] ?? 'VISITOR ACCESS')); ?></h2>
                        <p class="text-xs opacity-90">AUTHORIZED PERSONNEL</p>
                    </div>
                    <?php if ($company_logo): ?>
                        <img src="<?php echo htmlspecialchars($company_logo); ?>" alt="Logo" class="h-8 w-auto max-w-16 object-contain">
                    <?php else: ?>
                        <div class="text-white opacity-75">
                            <i class="fas fa-shield-alt text-lg"></i>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Main Content -->
            <div class="p-3 relative">
                <div class="flex items-start gap-3">
                    <!-- Photo -->
                    <div class="photo-container">
                        <?php if ($visitor['photo_path'] && file_exists($visitor['photo_path'])): ?>
                            <img src="<?php echo htmlspecialchars($visitor['photo_path']); ?>" alt="Visitor Photo">
                        <?php else: ?>
                            <div class="photo-placeholder">
                                <i class="fas fa-user text-xl text-gray-400"></i>
                                <div class="text-xs mt-1">PHOTO</div>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Information -->
                    <div class="flex-1 min-w-0">
                        <div class="visitor-name">
                            <?php echo htmlspecialchars($visitor['full_name']); ?>
                        </div>
                        <div class="visitor-id">
                            ID: <?php echo htmlspecialchars($visitor['visitor_id']); ?>
                        </div>
                        
                        <div class="space-y-1">
                            <div class="info-row">
                                <span class="info-label">PHONE:</span>
                                <span class="info-value"><?php echo htmlspecialchars($visitor['phone']); ?></span>
                            </div>
                            
                            <?php if ($visitor['company']): ?>
                            <div class="info-row">
                                <span class="info-label">ORG:</span>
                                <span class="info-value"><?php echo htmlspecialchars($visitor['company']); ?></span>
                            </div>
                            <?php endif; ?>
                            
                            <?php if ($visitor['vehicle_number']): ?>
                            <div class="info-row">
                                <span class="info-label">CAR:</span>
                                <span class="info-value"><?php echo strtoupper(htmlspecialchars($visitor['vehicle_number'])); ?></span>
                            </div>
                            <?php endif; ?>
                            
                            <?php if ($visitor['email']): ?>
                            <div class="info-row">
                                <span class="info-label">EMAIL:</span>
                                <span class="info-value"><?php echo htmlspecialchars($visitor['email']); ?></span>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Validity Information -->
                <div class="validity-section">
                    <div class="validity-item">
                        <div class="validity-label">ISSUED</div>
                        <div class="validity-value"><?php echo $issue_date; ?></div>
                    </div>
                    <div class="validity-item">
                        <div class="validity-label">TIME</div>
                        <div class="validity-value"><?php echo $issue_time; ?></div>
                    </div>
                    <div class="validity-item">
                        <div class="validity-label">EXPIRES</div>
                        <div class="validity-value" style="color: #dc2626;"><?php echo $expiry_date; ?></div>
                    </div>
                </div>
                
                <!-- Card Number -->
                <div class="card-number">
                    #<?php echo strtoupper(substr(md5($visitor['visitor_id'] . $visitor['phone']), 0, 8)); ?>
                </div>
            </div>
            
            <!-- Security Strip -->
            <div class="security-strip"></div>
        </div>

        <!-- BACK SIDE FOR PRINTING -->
        <div class="id-card card-back">
            <!-- Back Header -->
            <div class="back-header">
                <h3>ACCESS VERIFICATION</h3>
                <p>SCAN QR CODE FOR INSTANT VERIFICATION</p>
            </div>
            
            <!-- QR Code Section -->
            <div class="qr-section">
                <div class="qr-container">
                    <div id="qrcode-print"></div>
                </div>
                <div class="text-xs font-semibold text-gray-700">SCAN FOR ACCESS</div>
            </div>
            
            <!-- Instructions -->
            <div class="instructions">
                <h4>ACCESS PROCEDURES:</h4>
                <ul>
                    <li>Present card at security checkpoint</li>
                    <li>Allow QR code scanning verification</li>
                    <li>Follow escort and safety protocols</li>
                    <li>Return card when departing premises</li>
                </ul>
            </div>
            
            <!-- Emergency Contact -->
            <div class="emergency-info">
                <div class="emergency-title">24/7 SECURITY EMERGENCY</div>
                <div class="emergency-number"><?php echo htmlspecialchars($settings['security_contact'] ?? '+1-800-SECURITY'); ?></div>
            </div>
            
            <!-- Unique Identifier -->
            <div class="card-number">
                <?php echo strtoupper(substr(md5($visitor['qr_code'] . date('Y-m-d')), 0, 16)); ?>
            </div>
        </div>
    </div>

    <!-- Professional Printing Guide 
    <div class="no-print bg-blue-50 border-t border-blue-200">
        <div class="max-w-7xl mx-auto px-4 py-8">
            <div class="bg-white rounded-xl shadow-lg p-6">
                <h3 class="text-xl font-bold text-blue-900 mb-6">
                    <i class="fas fa-info-circle mr-2"></i>Professional Card Printing Guide
                </h3>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6 text-sm">
                    <div class="bg-blue-50 p-4 rounded-lg">
                        <h4 class="font-bold text-blue-900 mb-3">🖨️ Print Settings</h4>
                        <ul class="space-y-1 text-blue-800">
                            <li><strong>Paper:</strong> 300gsm Cardstock</li>
                            <li><strong>Quality:</strong> Best/Photo Quality</li>
                            <li><strong>Color:</strong> Full Color (CMYK)</li>
                            <li><strong>Size:</strong> A4 Landscape</li>
                            <li><strong>Margins:</strong> Minimum (0.25")</li>
                            <li><strong>Scaling:</strong> 100% (No shrinking)</li>
                        </ul>
                    </div>
                    <div class="bg-green-50<div class="bg-green-50 p-4 rounded-lg">
                        <h4 class="font-bold text-green-900 mb-3">✂️ Post-Print Processing</h4>
                        <ul class="space-y-1 text-green-800">
                            <li><strong>Cutting:</strong> Use precision card cutter</li>
                            <li><strong>Lamination:</strong> 125 micron pouches</li>
                            <li><strong>Corners:</strong> Round for professional look</li>
                            <li><strong>Attachment:</strong> Badge clips/lanyards</li>
                            <li><strong>Quality Check:</strong> Test QR scanning</li>
                            <li><strong>Storage:</strong> Protective sleeves</li>
                        </ul>
                    </div>
                    <div class="bg-purple-50 p-4 rounded-lg">
                        <h4 class="font-bold text-purple-900 mb-3">🔧 Pro Tips</h4>
                        <ul class="space-y-1 text-purple-800">
                            <li><strong>Printer:</strong> Use photo inkjet or card printer</li>
                            <li><strong>Test Print:</strong> Always do test on regular paper</li>
                            <li><strong>Alignment:</strong> Check card positioning</li>
                            <li><strong>Colors:</strong> Calibrate monitor vs printer</li>
                            <li><strong>Backup:</strong> Print extra copies</li>
                            <li><strong>Security:</strong> Store blank cards securely</li>
                        </ul>
                    </div>
                </div>
                
                <div class="mt-6 bg-yellow-50 border border-yellow-200 rounded-lg p-4">
                    <div class="flex items-start">
                        <i class="fas fa-lightbulb text-yellow-600 mt-1 mr-3"></i>
                        <div>
                            <h4 class="font-bold text-yellow-900 mb-2">Professional Results Guarantee</h4>
                            <p class="text-yellow-800 text-sm leading-relaxed">
                                This card system produces <strong>commercial-grade visitor ID cards</strong> when printed on quality cardstock. 
                                The layout is optimized for <strong>3.375" × 2.125" CR-80 standard</strong> with professional typography, 
                                security features, and clear QR codes. For best results, use a <strong>high-resolution printer (1200+ DPI)</strong> 
                                and laminate with professional-grade pouches.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>-->

    <!-- QR Code Library -->
    <script src="https://cdn.jsdelivr.net/npm/qrcode@1.5.3/build/qrcode.min.js"></script>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const qrData = '<?php echo htmlspecialchars($visitor['qr_code']); ?>';
            
            // Generate QR Code for preview
            const qrPreview = document.getElementById('qrcode-preview');
            if (qrPreview) {
                QRCode.toCanvas(qrPreview, qrData, {
                    width: 72,
                    height: 72,
                    colorDark: '#000000',
                    colorLight: '#ffffff',
                    correctLevel: QRCode.CorrectLevel.H,
                    margin: 1
                }, function (error) {
                    if (error) {
                        console.error('QR Preview Error:', error);
                        qrPreview.innerHTML = '<div class="text-red-600 text-xs">QR Error</div>';
                    }
                });
            }
            
            // Generate QR Code for printing
            const qrPrint = document.getElementById('qrcode-print');
            if (qrPrint) {
                QRCode.toCanvas(qrPrint, qrData, {
                    width: 72,
                    height: 72,
                    colorDark: '#000000',
                    colorLight: '#ffffff',
                    correctLevel: QRCode.CorrectLevel.H,
                    margin: 1
                }, function (error) {
                    if (error) {
                        console.error('QR Print Error:', error);
                        qrPrint.innerHTML = '<div class="text-red-600 text-xs">QR Error</div>';
                    }
                });
            }
        });

        // Enhanced print function
        function printCards() {
            // Add print-specific styling
            const printStyle = document.createElement('style');
            printStyle.innerHTML = `
                @media print {
                    .print-area {
                        display: flex !important;
                        justify-content: center !important;
                        align-items: center !important;
                        gap: 1in !important;
                        min-height: 100vh !important;
                    }
                    .id-card {
                        print-color-adjust: exact !important;
                        -webkit-print-color-adjust: exact !important;
                        color-adjust: exact !important;
                    }
                }
            `;
            document.head.appendChild(printStyle);
            
            // Trigger print
            window.print();
            
            // Remove print style after printing
            setTimeout(() => {
                document.head.removeChild(printStyle);
            }, 1000);
        }

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.key === 'p') {
                e.preventDefault();
                printCards();
            }
        });

        // Print preparation
        window.addEventListener('beforeprint', function() {
            console.log('Preparing professional card print...');
            // Ensure QR codes are rendered
            setTimeout(() => {
                console.log('QR codes ready for print');
            }, 100);
        });

        window.addEventListener('afterprint', function() {
            console.log('Professional cards printed successfully');
        });
        
        // Auto-hide print controls when printing
        const mediaQueryList = window.matchMedia('print');
        mediaQueryList.addListener(function(mql) {
            if (mql.matches) {
                document.body.classList.add('printing');
            } else {
                document.body.classList.remove('printing');
            }
        });
    </script>
    <script>
document.addEventListener('DOMContentLoaded', function() {
    const qrData = '<?php echo htmlspecialchars($visitor['qr_code']); ?>';
    
    // Common QR settings for better visibility
    const qrSettings = {
        width: 80,
        height: 80,
        colorDark: '#000000',
        colorLight: '#ffffff',
        correctLevel: QRCode.CorrectLevel.H,
        margin: 2
    };
    
    // Generate QR Code for preview
    const qrPreview = document.getElementById('qrcode-preview');
    if (qrPreview) {
        // Clear any existing content
        qrPreview.innerHTML = '';
        
        QRCode.toCanvas(qrPreview, qrData, qrSettings, function (error, canvas) {
            if (error) {
                console.error('QR Preview Error:', error);
                qrPreview.innerHTML = '<div class="text-red-600 text-xs text-center">QR Error</div>';
            } else {
                console.log('QR Preview generated successfully');
            }
        });
    }
    
    // Generate QR Code for printing
    const qrPrint = document.getElementById('qrcode-print');
    if (qrPrint) {
        // Clear any existing content
        qrPrint.innerHTML = '';
        
        QRCode.toCanvas(qrPrint, qrData, qrSettings, function (error, canvas) {
            if (error) {
                console.error('QR Print Error:', error);
                qrPrint.innerHTML = '<div class="text-red-600 text-xs text-center">QR Error</div>';
            } else {
                console.log('QR Print generated successfully');
            }
        });
    }
    
    // Debug: Log QR data
    console.log('QR Data:', qrData);
});
</script>
</body>
</html>