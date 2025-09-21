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

// Log activity
logActivity($db, $session['operator_id'], 'print_card', "Printed card for visitor: {$visitor['full_name']}", $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Visitor Card - <?php echo htmlspecialchars($visitor['full_name']); ?></title>
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
    <style>
        @media print {
            body { margin: 0; }
            .no-print { display: none !important; }
            .print-card { 
                width: 3.375in; 
                height: 2.125in; 
                page-break-after: always;
                box-shadow: none !important;
                border: 1px solid #000 !important;
            }
        }
        
        .card-front, .card-back {
            width: 3.375in;
            height: 2.125in;
            border: 2px solid #e5e7eb;
        }
    </style>
</head>
<body class="bg-gray-100 py-8">
    <div class="max-w-4xl mx-auto px-4">
        <!-- Print Controls -->
        <div class="no-print mb-8 bg-white p-4 rounded-lg shadow-sm border">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-xl font-semibold text-gray-900">Visitor ID Card</h1>
                    <p class="text-sm text-gray-600">Print this card for <?php echo htmlspecialchars($visitor['full_name']); ?></p>
                </div>
                <div class="flex space-x-4">
                    <button onclick="window.print()" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg font-medium transition-colors">
                        <i class="fas fa-print mr-2"></i>Print Card
                    </button>
                    <a href="visitors.php?action=view&id=<?php echo $visitor['visitor_id']; ?>" 
                       class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg font-medium transition-colors">
                        <i class="fas fa-arrow-left mr-2"></i>Back to Visitor
                    </a>
                </div>
            </div>
        </div>

        <!-- Card Preview -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-8">
            <!-- Front of Card -->
            <div class="flex justify-center">
                <div class="print-card card-front bg-white rounded-lg shadow-lg overflow-hidden relative">
                    <!-- Header -->
                    <div class="bg-blue-600 text-white p-3 text-center">
                        <h2 class="text-sm font-bold"><?php echo htmlspecialchars($settings['system_name'] ?? 'VISITOR PASS'); ?></h2>
                    </div>
                    
                    <!-- Photo Placeholder -->
                    <div class="absolute top-16 left-4 w-16 h-16 bg-gray-200 rounded border-2 border-white shadow-sm flex items-center justify-center">
                        <i class="fas fa-user text-gray-400 text-xl"></i>
                    </div>
                    
                    <!-- Visitor Info -->
                    <div class="p-4 pt-6">
                        <div class="ml-20">
                            <h3 class="font-bold text-sm text-gray-900 leading-tight">
                                <?php echo htmlspecialchars($visitor['full_name']); ?>
                            </h3>
                            <p class="text-xs text-gray-600 mt-1">
                                ID: <?php echo htmlspecialchars($visitor['visitor_id']); ?>
                            </p>
                        </div>
                        
                        <div class="mt-4 space-y-1">
                            <?php if ($visitor['company']): ?>
                                <div class="text-xs">
                                    <span class="font-medium text-gray-600">Company:</span>
                                    <span class="text-gray-900"><?php echo htmlspecialchars($visitor['company']); ?></span>
                                </div>
                            <?php endif; ?>
                            
                            <div class="text-xs">
                                <span class="font-medium text-gray-600">Phone:</span>
                                <span class="text-gray-900"><?php echo htmlspecialchars($visitor['phone']); ?></span>
                            </div>
                            
                            <?php if ($visitor['vehicle_number']): ?>
                                <div class="text-xs">
                                    <span class="font-medium text-gray-600">Vehicle:</span>
                                    <span class="text-gray-900"><?php echo htmlspecialchars($visitor['vehicle_number']); ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Validity -->
                        <div class="absolute bottom-2 left-4 right-4">
                            <div class="text-xs text-center bg-gray-100 py-1 rounded">
                                <span class="font-medium">Valid from:</span> <?php echo date('M j, Y'); ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Back of Card -->
            <div class="flex justify-center">
                <div class="print-card card-back bg-white rounded-lg shadow-lg overflow-hidden relative">
                    <!-- QR Code Section -->
                    <div class="p-4 text-center h-full flex flex-col justify-between">
                        <div>
                            <h3 class="text-sm font-bold text-gray-900 mb-2">SCAN FOR ACCESS</h3>
                            <div class="flex justify-center mb-3">
                                <div id="qrcode" class="bg-white p-2 border rounded"></div>
                            </div>
                        </div>
                        
                        <div class="space-y-2">
                            <div class="text-xs text-gray-600">
                                <p class="font-medium mb-1">Instructions:</p>
                                <ul class="text-left space-y-1 text-xs">
                                    <li>• Present this card at gate</li>
                                    <li>• Allow QR code scan</li>
                                    <li>• Follow security instructions</li>
                                </ul>
                            </div>
                            
                            <div class="border-t pt-2">
                                <p class="text-xs text-gray-500">
                                    Emergency: Call Security
                                </p>
                                <p class="text-xs font-mono text-gray-700">
                                    <?php echo htmlspecialchars($visitor['qr_code']); ?>
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Print Instructions -->
        <div class="no-print bg-blue-50 border border-blue-200 rounded-lg p-4">
            <h3 class="text-lg font-semibold text-blue-900 mb-2">
                <i class="fas fa-info-circle mr-2"></i>Printing Instructions
            </h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm text-blue-800">
                <div>
                    <h4 class="font-medium mb-1">Recommended Settings:</h4>
                    <ul class="list-disc list-inside space-y-1">
                        <li>Paper size: A4 or Letter</li>
                        <li>Print quality: High</li>
                        <li>Color: Color (recommended)</li>
                        <li>Margins: Minimum</li>
                    </ul>
                </div>
                <div>
                    <h4 class="font-medium mb-1">After Printing:</h4>
                    <ul class="list-disc list-inside space-y-1">
                        <li>Cut along card borders</li>
                        <li>Laminate for durability</li>
                        <li>Attach lanyard or clip</li>
                        <li>Test QR code scanning</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <!-- QR Code Library -->
    <script src="https://cdn.jsdelivr.net/npm/qrcode@1.5.3/build/qrcode.min.js"></script>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Generate QR Code for the back of the card
            const qrCodeElement = document.getElementById('qrcode');
            if (qrCodeElement) {
                QRCode.toCanvas(qrCodeElement, '<?php echo htmlspecialchars($visitor['qr_code']); ?>', {
                    width: 120,
                    height: 120,
                    colorDark: '#000000',
                    colorLight: '#ffffff',
                    correctLevel: QRCode.CorrectLevel.M,
                    margin: 1
                }, function (error) {
                    if (error) {
                        console.error(error);
                        qrCodeElement.innerHTML = '<div class="text-xs text-red-600">QR Code Error</div>';
                    }
                });
            }
        });

        // Print function
        function printCard() {
            window.print();
        }

        // Keyboard shortcut for printing
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.key === 'p') {
                e.preventDefault();
                printCard();
            }
        });
    </script>
</body>
</html>