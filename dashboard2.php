<?php
require_once 'config/database.php';

date_default_timezone_set('Africa/Nairobi');

$database = new Database();
$db = $database->getConnection();

$session = requireAuth($db);
$settings = getSettings($db);

// Get current location or default to headquarters
$current_location = isset($_GET['location']) ? $_GET['location'] : 1;

// Enhanced statistics query with location support
$stmt = $db->prepare("SELECT 
    COUNT(CASE WHEN gl.log_type = 'check_in' AND DATE(CONVERT_TZ(gl.log_timestamp, '+00:00', '+03:00')) = CURDATE() AND (? = 'all' OR gl.location_id = ?) THEN 1 END) as today_check_ins,
    COUNT(CASE WHEN gl.log_type = 'check_out' AND DATE(CONVERT_TZ(gl.log_timestamp, '+00:00', '+03:00')) = CURDATE() AND (? = 'all' OR gl.location_id = ?) THEN 1 END) as today_check_outs,
    COUNT(DISTINCT CASE WHEN DATE(CONVERT_TZ(gl.log_timestamp, '+00:00', '+03:00')) = CURDATE() AND (? = 'all' OR gl.location_id = ?) THEN gl.visitor_id END) as today_unique_visitors,
    (SELECT COUNT(*) FROM visitors v WHERE v.status = 'active' AND (? = 'all' OR v.location_id = ?)) as total_active_visitors,
    (SELECT COUNT(*) FROM pre_registrations pr WHERE pr.status = 'pending' AND pr.visit_date >= CURDATE() AND (? = 'all' OR pr.location_id = ?)) as pending_pre_reg,
    (SELECT COUNT(*) FROM vehicles veh WHERE veh.status = 'inside' AND (? = 'all' OR veh.location_id = ?)) as vehicles_inside,
    (SELECT COUNT(*) FROM deliveries d WHERE d.status = 'active' AND (? = 'all' OR d.location_id = ?)) as active_deliveries,
    (SELECT COUNT(*) FROM vehicles veh WHERE veh.status = 'overdue' AND (? = 'all' OR veh.location_id = ?)) as overdue_vehicles,
    (SELECT COUNT(*) FROM security_alerts sa WHERE sa.status = 'open' AND DATE(sa.created_at) = CURDATE() AND (? = 'all' OR sa.location_id = ?)) as security_alerts
FROM gate_logs gl");

$location_param = $current_location == 'all' ? 'all' : $current_location;
$stmt->execute(array_fill(0, 16, $location_param));
$stats = $stmt->fetch();

// Count visitors currently inside
$stmt = $db->prepare("SELECT COUNT(*) as inside_count FROM (
    SELECT gl.visitor_id, 
           MAX(CASE WHEN gl.log_type = 'check_in' THEN gl.log_timestamp END) as last_checkin,
           MAX(CASE WHEN gl.log_type = 'check_out' THEN gl.log_timestamp END) as last_checkout
    FROM gate_logs gl
    WHERE (? = 'all' OR gl.location_id = ?)
    GROUP BY gl.visitor_id
    HAVING last_checkin IS NOT NULL AND (last_checkout IS NULL OR last_checkin > last_checkout)
) as inside_visitors");
$stmt->execute([$location_param, $location_param]);
$inside_count = $stmt->fetch()['inside_count'];

// Get recent activities with enhanced details
$stmt = $db->prepare("SELECT gl.*, v.full_name, v.phone, v.vehicle_number, v.company, go.operator_name, l.location_name,
                     CONVERT_TZ(gl.log_timestamp, '+00:00', '+03:00') as kenya_time,
                     CASE 
                        WHEN gl.log_type = 'vehicle_in' THEN 'vehicle'
                        WHEN gl.log_type = 'vehicle_out' THEN 'vehicle'
                        WHEN gl.log_type = 'delivery_in' THEN 'delivery'
                        WHEN gl.log_type = 'delivery_out' THEN 'delivery'
                        ELSE 'visitor'
                     END as entry_type
                     FROM gate_logs gl 
                     JOIN visitors v ON gl.visitor_id = v.visitor_id 
                     JOIN gate_operators go ON gl.operator_id = go.id 
                     LEFT JOIN locations l ON gl.location_id = l.id
                     WHERE (? = 'all' OR gl.location_id = ?)
                     ORDER BY gl.log_timestamp DESC 
                     LIMIT 15");
$stmt->execute([$location_param, $location_param]);
$recent_activities = $stmt->fetchAll();

// Get vehicles currently inside
$stmt = $db->prepare("SELECT v.*, vl.entry_time, vl.expected_exit, go.operator_name as authorized_by,
                     TIMESTAMPDIFF(MINUTE, vl.entry_time, NOW()) as minutes_inside,
                     CASE WHEN vl.expected_exit < NOW() THEN 'overdue' ELSE 'normal' END as status_type
                     FROM vehicles v 
                     JOIN vehicle_logs vl ON v.vehicle_id = vl.vehicle_id AND vl.status = 'inside'
                     LEFT JOIN gate_operators go ON vl.operator_id = go.id
                     WHERE (? = 'all' OR v.location_id = ?)
                     ORDER BY vl.entry_time ASC 
                     LIMIT 10");
$stmt->execute([$location_param, $location_param]);
$inside_vehicles = $stmt->fetchAll();

// Get locations for selector
$stmt = $db->prepare("SELECT id, location_name, address FROM locations WHERE status = 'active' ORDER BY location_name");
$stmt->execute();
$locations = $stmt->fetchAll();

// Get weekly stats
$stmt = $db->prepare("SELECT 
    DATE(CONVERT_TZ(log_timestamp, '+00:00', '+03:00')) as log_date,
    COUNT(CASE WHEN log_type IN ('check_in', 'vehicle_in', 'delivery_in') THEN 1 END) as entries,
    COUNT(CASE WHEN log_type IN ('check_out', 'vehicle_out', 'delivery_out') THEN 1 END) as exits,
    COUNT(CASE WHEN log_type IN ('check_in', 'check_out') THEN 1 END) as visitors,
    COUNT(CASE WHEN log_type IN ('vehicle_in', 'vehicle_out') THEN 1 END) as vehicles,
    COUNT(CASE WHEN log_type IN ('delivery_in', 'delivery_out') THEN 1 END) as deliveries
FROM gate_logs 
WHERE CONVERT_TZ(log_timestamp, '+00:00', '+03:00') >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    AND (? = 'all' OR location_id = ?)
GROUP BY DATE(CONVERT_TZ(log_timestamp, '+00:00', '+03:00'))
ORDER BY log_date ASC");
$stmt->execute([$location_param, $location_param]);
$weekly_stats = $stmt->fetchAll();

// Get notification count
$stmt = $db->prepare("SELECT COUNT(*) as unread FROM notifications WHERE is_read = 0 AND (? = 'all' OR location_id = ?)");
$stmt->execute([$location_param, $location_param]);
$unread = $stmt->fetch()['unread'];

$message = getMessage();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($settings['system_name'] ?? 'Gate Management System'); ?> - Dashboard</title>
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
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        /* Enhanced mobile-first responsive design */
        @media (max-width: 640px) {
            .mobile-hidden { display: none !important; }
            .mobile-grid-1 { grid-template-columns: repeat(1, minmax(0, 1fr)) !important; }
            .mobile-grid-2 { grid-template-columns: repeat(2, minmax(0, 1fr)) !important; }
            .mobile-text-xs { font-size: 0.75rem !important; }
            .mobile-text-sm { font-size: 0.875rem !important; }
            .mobile-p-2 { padding: 0.5rem !important; }
            .mobile-p-3 { padding: 0.75rem !important; }
            .mobile-mb-2 { margin-bottom: 0.5rem !important; }
            .mobile-space-y-2 > * + * { margin-top: 0.5rem !important; }
        }

        /* Enhanced navigation */
        .mobile-nav {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: white;
            border-top: 1px solid #e5e7eb;
            z-index: 50;
            padding: 0.5rem;
            box-shadow: 0 -4px 6px -1px rgba(0, 0, 0, 0.1);
        }

        .mobile-nav-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 0.5rem;
            text-decoration: none;
            color: #6b7280;
            transition: all 0.2s;
            border-radius: 0.5rem;
        }

        .mobile-nav-item:hover,
        .mobile-nav-item.active {
            color: #2563eb;
            background-color: #dbeafe;
        }

        .mobile-nav-item i {
            font-size: 1.25rem;
            margin-bottom: 0.25rem;
        }

        .mobile-nav-item span {
            font-size: 0.75rem;
            font-weight: 500;
        }

        /* Enhanced animations */
        .fade-in {
            animation: fadeIn 0.6s ease-in;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .scale-in {
            animation: scaleIn 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
        }

        @keyframes scaleIn {
            from { transform: scale(0.9); opacity: 0; }
            to { transform: scale(1); opacity: 1; }
        }

        .slide-in-right {
            animation: slideInRight 0.5s ease-out;
        }

        @keyframes slideInRight {
            from { transform: translateX(20px); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }

        /* Enhanced card styles */
        .stat-card {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
        }

        .action-button {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }

        .action-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.15);
        }

        .action-button:hover::before {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(45deg, transparent, rgba(255,255,255,0.1), transparent);
            transform: translateX(-100%);
            animation: shimmer 0.6s ease-out;
        }

        @keyframes shimmer {
            to { transform: translateX(100%); }
        }

        /* Real-time updates indicator */
        .pulse-dot {
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }

        /* Enhanced scrollbar */
        .custom-scroll::-webkit-scrollbar {
            width: 6px;
        }

        .custom-scroll::-webkit-scrollbar-track {
            background: #f1f5f9;
            border-radius: 3px;
        }

        .custom-scroll::-webkit-scrollbar-thumb {
            background: linear-gradient(180deg, #cbd5e1, #94a3b8);
            border-radius: 3px;
        }

        .custom-scroll::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(180deg, #94a3b8, #64748b);
        }

        /* Loading states */
        .loading {
            position: relative;
        }

        .loading::after {
            content: '';
            position: absolute;
            inset: 0;
            background: rgba(255, 255, 255, 0.8);
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: inherit;
        }

        /* Status indicators */
        .status-indicator {
            position: relative;
        }

        .status-indicator::before {
            content: '';
            position: absolute;
            top: -2px;
            right: -2px;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            border: 2px solid white;
        }

        .status-indicator.online::before {
            background-color: #10b981;
            animation: pulse 2s infinite;
        }

        .status-indicator.overdue::before {
            background-color: #f59e0b;
            animation: pulse 2s infinite;
        }

        .status-indicator.alert::before {
            background-color: #ef4444;
            animation: pulse 2s infinite;
        }

        /* Glass morphism effect */
        .glass {
            background: rgba(255, 255, 255, 0.25);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.18);
        }

        /* Gradient backgrounds */
        .gradient-bg-1 {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }

        .gradient-bg-2 {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
        }

        .gradient-bg-3 {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
        }

        .gradient-bg-4 {
            background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);
        }
    </style>
</head>
<body class="bg-gray-50 pb-20 sm:pb-0">
    
    <!-- Enhanced Navigation -->
    <nav class="bg-white shadow-sm border-b border-gray-200 sticky top-0 z-40">
        <div class="max-w-7xl mx-auto px-2 sm:px-4 lg:px-8">
            <div class="flex justify-between h-14 sm:h-16">
                <div class="flex items-center space-x-2 sm:space-x-4">
                    <div class="flex-shrink-0">
                        <div class="h-8 w-8 sm:h-10 sm:w-10 bg-gradient-to-br from-blue-600 to-blue-800 rounded-lg flex items-center justify-center shadow-md">
                            <i class="fas fa-shield-alt text-white text-sm sm:text-base"></i>
                        </div>
                    </div>
                    <div>
                        <h1 class="text-base sm:text-xl font-semibold text-gray-900 truncate">
                            <?php echo htmlspecialchars($settings['system_name'] ?? 'Gate Management'); ?>
                        </h1>
                        <p class="text-xs sm:text-sm text-gray-500 hidden sm:block">
                            Welcome, <?php echo htmlspecialchars($session['operator_name']); ?>
                        </p>
                    </div>
                </div>
                
                <div class="flex items-center space-x-2 sm:space-x-4">
                    <div class="flex items-center text-xs sm:text-sm text-gray-500">
                        <span class="pulse-dot w-2 h-2 bg-green-500 rounded-full mr-2"></span>
                        <span class="mobile-hidden"><?php echo date('D, M j, Y - g:i A'); ?></span>
                        <span class="sm:hidden"><?php echo date('H:i'); ?></span>
                    </div>
                    <button onclick="toggleNotifications()" class="relative text-gray-500 hover:text-gray-700 sm:hidden">
                        <i class="fas fa-bell"></i>
                        <?php if($unread > 0): ?>
                        <span class="absolute -top-1 -right-1 bg-red-500 text-white text-xs rounded-full h-4 w-4 flex items-center justify-center">
                            <?php echo $unread > 9 ? '9+' : $unread; ?>
                        </span>
                        <?php endif; ?>
                    </button>
                    <a href="logout.php" class="text-gray-500 hover:text-gray-700 mobile-hidden">
                        <i class="fas fa-sign-out-alt"></i>
                        <span class="ml-1">Logout</span>
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <!-- Location Selector Bar -->
    <div class="bg-white border-b border-gray-200 px-4 py-3 shadow-sm">
        <div class="max-w-7xl mx-auto flex items-center justify-between">
            <div class="flex items-center space-x-4">
                <i class="fas fa-map-marker-alt text-blue-600"></i>
                <select id="location_selector" onchange="changeLocation()" class="border-0 bg-transparent text-sm font-medium text-gray-900 focus:ring-0 cursor-pointer">
                    <option value="all" <?php echo $current_location == 'all' ? 'selected' : ''; ?>>All Locations</option>
                    <?php foreach($locations as $location): ?>
                    <option value="<?php echo $location['id']; ?>" <?php echo $current_location == $location['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($location['location_name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="flex items-center space-x-2 text-sm text-gray-500">
                <span class="pulse-dot w-2 h-2 bg-green-500 rounded-full"></span>
                <span class="mobile-hidden">Real-time Updates</span>
                <span class="sm:hidden">Live</span>
            </div>
        </div>
    </div>

    <div class="max-w-7xl mx-auto px-2 sm:px-4 lg:px-8 py-4 sm:py-8">
        <?php if ($message): ?>
            <div class="mb-4 sm:mb-6 p-3 sm:p-4 rounded-lg border scale-in <?php 
                echo $message['type'] === 'error' ? 'bg-red-50 border-red-200 text-red-700' : 
                    ($message['type'] === 'warning' ? 'bg-yellow-50 border-yellow-200 text-yellow-700' : 
                     'bg-green-50 border-green-200 text-green-700'); ?>">
                <div class="flex items-center">
                    <i class="fas <?php 
                        echo $message['type'] === 'error' ? 'fa-exclamation-circle' : 
                            ($message['type'] === 'warning' ? 'fa-exclamation-triangle' : 'fa-check-circle'); 
                        ?> mr-2"></i>
                    <span class="text-sm sm:text-base"><?php echo htmlspecialchars($message['message']); ?></span>
                </div>
            </div>
        <?php endif; ?>

        <!-- Enhanced Quick Actions with Vehicle Focus -->
        <div class="grid mobile-grid-2 sm:grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-6 mb-6 sm:mb-8">
            <a href="vehicle-scanner.php" class="action-button gradient-bg-1 text-white p-4 sm:p-6 rounded-xl shadow-lg transition-all fade-in group">
                <div class="flex flex-col items-center text-center">
                    <div class="w-12 h-12 bg-white/20 rounded-lg flex items-center justify-center mb-3 group-hover:scale-110 transition-transform">
                        <i class="fas fa-truck text-xl sm:text-2xl"></i>
                    </div>
                    <h3 class="text-sm sm:text-lg font-semibold">Vehicle Scanner</h3>
                    <p class="text-xs sm:text-sm opacity-90 mobile-hidden">Scan vehicle QR codes</p>
                </div>
            </a>
            
            <a href="scanner.php" class="action-button gradient-bg-2 text-white p-4 sm:p-6 rounded-xl shadow-lg transition-all fade-in group">
                <div class="flex flex-col items-center text-center">
                    <div class="w-12 h-12 bg-white/20 rounded-lg flex items-center justify-center mb-3 group-hover:scale-110 transition-transform">
                        <i class="fas fa-qrcode text-xl sm:text-2xl"></i>
                    </div>
                    <h3 class="text-sm sm:text-lg font-semibold">Visitor Scanner</h3>
                    <p class="text-xs sm:text-sm opacity-90 mobile-hidden">Scan visitor QR codes</p>
                </div>
            </a>
            
            <a href="delivery-tracking.php" class="action-button gradient-bg-3 text-white p-4 sm:p-6 rounded-xl shadow-lg transition-all fade-in group">
                <div class="flex flex-col items-center text-center">
                    <div class="w-12 h-12 bg-white/20 rounded-lg flex items-center justify-center mb-3 group-hover:scale-110 transition-transform">
                        <i class="fas fa-shipping-fast text-xl sm:text-2xl"></i>
                    </div>
                    <h3 class="text-sm sm:text-lg font-semibold">Delivery Tracking</h3>
                    <p class="text-xs sm:text-sm opacity-90 mobile-hidden">Track deliveries</p>
                </div>
            </a>
            
            <a href="quick-checkin.php" class="action-button gradient-bg-4 text-white p-4 sm:p-6 rounded-xl shadow-lg transition-all fade-in group">
                <div class="flex flex-col items-center text-center">
                    <div class="w-12 h-12 bg-white/20 rounded-lg flex items-center justify-center mb-3 group-hover:scale-110 transition-transform">
                        <i class="fas fa-tachometer-alt text-xl sm:text-2xl"></i>
                    </div>
                    <h3 class="text-sm sm:text-lg font-semibold">Quick Check</h3>
                    <p class="text-xs sm:text-sm opacity-90 mobile-hidden">Manual check-in/out</p>
                </div>
            </a>
        </div>

        <!-- Enhanced Statistics with Vehicle Focus -->
        <div class="grid mobile-grid-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 sm:gap-4 mb-6 sm:mb-8">
            <div class="stat-card bg-white p-3 sm:p-6 rounded-xl shadow-sm border border-gray-200 scale-in status-indicator online">
                <div class="flex items-center">
                    <div class="p-2 bg-blue-100 rounded-lg">
                        <i class="fas fa-users text-blue-600 text-sm sm:text-base"></i>
                    </div>
                    <div class="ml-3 sm:ml-4">
                        <p class="text-xs sm:text-sm font-medium text-gray-600">Visitors Inside</p>
                        <p class="text-lg sm:text-2xl font-bold text-gray-900" id="visitors-inside"><?php echo $inside_count; ?></p>
                    </div>
                </div>
            </div>
            
            <div class="stat-card bg-white p-3 sm:p-6 rounded-xl shadow-sm border border-gray-200 scale-in status-indicator online">
                <div class="flex items-center">
                    <div class="p-2 bg-green-100 rounded-lg">
                        <i class="fas fa-car text-green-600 text-sm sm:text-base"></i>
                    </div>
                    <div class="ml-3 sm:ml-4">
                        <p class="text-xs sm:text-sm font-medium text-gray-600">Vehicles Inside</p>
                        <p class="text-lg sm:text-2xl font-bold text-gray-900" id="vehicles-inside"><?php echo $stats['vehicles_inside']; ?></p>
                    </div>
                </div>
            </div>
            
            <div class="stat-card bg-white p-3 sm:p-6 rounded-xl shadow-sm border border-gray-200 scale-in status-indicator online">
                <div class="flex items-center">
                    <div class="p-2 bg-orange-100 rounded-lg">
                        <i class="fas fa-truck text-orange-600 text-sm sm:text-base"></i>
                    </div>
                    <div class="ml-3 sm:ml-4">
                        <p class="text-xs sm:text-sm font-medium text-gray-600">Active Deliveries</p>
                        <p class="text-lg sm:text-2xl font-bold text-gray-900" id="active-deliveries"><?php echo $stats['active_deliveries']; ?></p>
                    </div>
                </div>
            </div>
            
            <div class="stat-card bg-white p-3 sm:p-6 rounded-xl shadow-sm border border-gray-200 scale-in <?php echo $stats['overdue_vehicles'] > 0 ? 'status-indicator overdue' : ''; ?>">
                <div class="flex items-center">
                    <div class="p-2 bg-purple-100 rounded-lg">
                        <i class="fas fa-clock text-purple-600 text-sm sm:text-base"></i>
                    </div>
                    <div class="ml-3 sm:ml-4">
                        <p class="text-xs sm:text-sm font-medium text-gray-600">Overdue Vehicles</p>
                        <p class="text-lg sm:text-2xl font-bold text-gray-900" id="overdue-vehicles"><?php echo $stats['overdue_vehicles']; ?></p>
                    </div>
                </div>
            </div>
            
            <div class="stat-card bg-white p-3 sm:p-6 rounded-xl shadow-sm border border-gray-200 scale-in status-indicator online">
                <div class="flex items-center">
                    <div class="p-2 bg-indigo-100 rounded-lg">
                        <i class="fas fa-calendar-day text-indigo-600 text-sm sm:text-base"></i>
                    </div>
                    <div class="ml-3 sm:ml-4">
                        <p class="text-xs sm:text-sm font-medium text-gray-600">Today's Entries</p>
                        <p class="text-lg sm:text-2xl font-bold text-gray-900" id="today-entries"><?php echo $stats['today_check_ins'] + $stats['today_check_outs']; ?></p>
                    </div>
                </div>
            </div>
            
            <div class="stat-card bg-white p-3 sm:p-6 rounded-xl shadow-sm border border-gray-200 scale-in <?php echo $stats['security_alerts'] > 0 ? 'status-indicator alert' : ''; ?>">
                <div class="flex items-center">
                    <div class="p-2 bg-red-100 rounded-lg">
                        <i class="fas fa-exclamation-triangle text-red-600 text-sm sm:text-base"></i>
                    </div>
                    <div class="ml-3 sm:ml-4">
                        <p class="text-xs sm:text-sm font-medium text-gray-600">Security Alerts</p>
                        <p class="text-lg sm:text-2xl font-bold text-gray-900" id="security-alerts"><?php echo $stats['security_alerts']; ?></p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Enhanced Vehicle Status Overview and Analytics -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
            <!-- Currently Inside Vehicles -->
            <div class="lg:col-span-2 bg-white rounded-xl shadow-sm border border-gray-200 p-4 sm:p-6 fade-in">
                <div class="flex items-center justify-between mb-6">
                    <h3 class="text-base sm:text-lg font-semibold text-gray-900">
                        <i class="fas fa-car mr-2 text-green-600"></i>Vehicles Currently Inside
                    </h3>
                    <button onclick="refreshVehicleStatus()" class="text-green-600 hover:text-green-800 transition-colors">
                        <i class="fas fa-sync-alt" id="vehicle-refresh-icon"></i>
                    </button>
                </div>
                
                <div class="space-y-4 custom-scroll max-h-80 overflow-y-auto" id="inside-vehicles">
                    <?php if (empty($inside_vehicles)): ?>
                        <div class="text-center py-8 text-gray-500">
                            <i class="fas fa-car text-3xl mb-4 text-gray-300"></i>
                            <p class="text-sm">No vehicles currently inside</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($inside_vehicles as $vehicle): ?>
                        <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg border <?php echo $vehicle['status_type'] === 'overdue' ? 'border-yellow-300 bg-yellow-50' : ''; ?> slide-in-right">
                            <div class="flex items-center space-x-4">
                                <div class="w-12 h-12 <?php echo $vehicle['status_type'] === 'overdue' ? 'bg-yellow-100' : 'bg-green-100'; ?> rounded-lg flex items-center justify-center">
                                    <i class="fas <?php echo $vehicle['vehicle_type'] === 'truck' ? 'fa-truck' : 'fa-car'; ?> <?php echo $vehicle['status_type'] === 'overdue' ? 'text-yellow-600' : 'text-green-600'; ?>"></i>
                                </div>
                                <div>
                                    <h4 class="font-medium text-gray-900"><?php echo htmlspecialchars($vehicle['license_plate']); ?></h4>
                                    <p class="text-sm text-gray-600"><?php echo htmlspecialchars($vehicle['make'] . ' ' . $vehicle['model'] . ' • ' . ucfirst($vehicle['purpose'])); ?></p>
                                    <p class="text-xs text-gray-500">Driver: <?php echo htmlspecialchars($vehicle['driver_name']); ?> • Dest: <?php echo htmlspecialchars($vehicle['destination']); ?></p>
                                    <p class="text-xs text-gray-500">Authorized by: <?php echo htmlspecialchars($vehicle['authorized_by']); ?> • <?php echo floor($vehicle['minutes_inside']/60); ?>h <?php echo $vehicle['minutes_inside']%60; ?>m ago</p>
                                </div>
                            </div>
                            <div class="flex flex-col items-end space-y-2">
                                <span class="px-3 py-1 text-xs font-medium rounded-full <?php 
                                    echo $vehicle['status_type'] === 'overdue' ? 'bg-yellow-100 text-yellow-800' : 'bg-green-100 text-green-800'; ?>">
                                    <?php echo $vehicle['status_type'] === 'overdue' ? 'Overdue' : 'Inside'; ?>
                                </span>
                                <button onclick="checkOutVehicle('<?php echo $vehicle['vehicle_id']; ?>')" class="text-red-600 hover:text-red-800 text-sm transition-colors">
                                    <i class="fas fa-sign-out-alt mr-1"></i>Check Out
                                </button>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Enhanced Quick Stats & Actions -->
            <div class="space-y-6">
                <!-- Location Quick Stats -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 sm:p-6 fade-in">
                    <h3 class="text-base sm:text-lg font-semibold text-gray-900 mb-4">Location Overview</h3>
                    <div class="space-y-4" id="location-stats">
                        <?php foreach($locations as $location): 
                            // Get location-specific counts
                            $stmt = $db->prepare("SELECT 
                                (SELECT COUNT(*) FROM vehicles v WHERE v.status = 'inside' AND v.location_id = ?) as vehicles_count,
                                (SELECT COUNT(*) FROM gate_logs gl WHERE gl.location_id = ? AND DATE(CONVERT_TZ(gl.log_timestamp, '+00:00', '+03:00')) = CURDATE() AND gl.log_type IN ('check_in', 'vehicle_in', 'delivery_in')) as today_entries
                                ");
                            $stmt->execute([$location['id'], $location['id']]);
                            $loc_stats = $stmt->fetch();
                        ?>
                        <div class="flex justify-between items-center p-3 bg-gray-50 rounded-lg">
                            <div>
                                <span class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($location['location_name']); ?></span>
                                <p class="text-xs text-gray-500"><?php echo htmlspecialchars($location['address']); ?></p>
                            </div>
                            <div class="text-right">
                                <div class="flex items-center space-x-2 mb-1">
                                    <span class="w-3 h-3 bg-green-500 rounded-full"></span>
                                    <span class="text-sm font-medium"><?php echo $loc_stats['vehicles_count']; ?> vehicles</span>
                                </div>
                                <p class="text-xs text-gray-500"><?php echo $loc_stats['today_entries']; ?> entries today</p>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Enhanced Management Actions -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 sm:p-6 fade-in">
                    <h3 class="text-base sm:text-lg font-semibold text-gray-900 mb-4">Quick Management</h3>
                    <div class="space-y-3">
                        <a href="manage-vehicles.php" class="block w-full bg-blue-600 hover:bg-blue-700 text-white px-4 py-3 rounded-lg text-center font-medium transition-all action-button">
                            <i class="fas fa-car mr-2"></i>Manage Vehicles
                        </a>
                        <a href="manage-locations.php" class="block w-full bg-green-600 hover:bg-green-700 text-white px-4 py-3 rounded-lg text-center font-medium transition-all action-button">
                            <i class="fas fa-map-marker-alt mr-2"></i>Manage Locations
                        </a>
                        <a href="vehicle-reports.php" class="block w-full bg-purple-600 hover:bg-purple-700 text-white px-4 py-3 rounded-lg text-center font-medium transition-all action-button">
                            <i class="fas fa-chart-bar mr-2"></i>Vehicle Reports
                        </a>
                        <a href="pre-register.php" class="block w-full bg-orange-600 hover:bg-orange-700 text-white px-4 py-3 rounded-lg text-center font-medium transition-all action-button">
                            <i class="fas fa-calendar-plus mr-2"></i>Pre-Register
                        </a>
                    </div>
                </div>

                <!-- System Health Indicator -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 sm:p-6 fade-in">
                    <h3 class="text-base sm:text-lg font-semibold text-gray-900 mb-4">System Health</h3>
                    <div class="space-y-3">
                        <div class="flex justify-between items-center">
                            <span class="text-sm text-gray-600">Database</span>
                            <span class="flex items-center text-green-600">
                                <span class="w-2 h-2 bg-green-500 rounded-full mr-2"></span>
                                <span class="text-sm">Online</span>
                            </span>
                        </div>
                        <div class="flex justify-between items-center">
                            <span class="text-sm text-gray-600">Scanner Service</span>
                            <span class="flex items-center text-green-600">
                                <span class="w-2 h-2 bg-green-500 rounded-full mr-2"></span>
                                <span class="text-sm">Active</span>
                            </span>
                        </div>
                        <div class="flex justify-between items-center">
                            <span class="text-sm text-gray-600">Backup Status</span>
                            <span class="flex items-center text-blue-600">
                                <span class="w-2 h-2 bg-blue-500 rounded-full mr-2"></span>
                                <span class="text-sm">Up to date</span>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Enhanced Weekly Analytics Chart -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
            <div class="lg:col-span-2 bg-white rounded-xl shadow-sm border border-gray-200 p-4 sm:p-6 fade-in">
                <h3 class="text-base sm:text-lg font-semibold text-gray-900 mb-4 sm:mb-6">Weekly Activity Trends</h3>
                <div class="h-48 sm:h-64">
                    <canvas id="weeklyChart"></canvas>
                </div>
            </div>

            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 sm:p-6 fade-in">
                <h3 class="text-base sm:text-lg font-semibold text-gray-900 mb-4 sm:mb-6">Today's Summary</h3>
                <div class="space-y-3 sm:space-y-4">
                    <div class="flex justify-between items-center p-3 bg-blue-50 rounded-lg">
                        <span class="text-xs sm:text-sm font-medium text-blue-800">Check-ins</span>
                        <span class="text-sm sm:text-lg font-bold text-blue-600"><?php echo $stats['today_check_ins']; ?></span>
                    </div>
                    <div class="flex justify-between items-center p-3 bg-green-50 rounded-lg">
                        <span class="text-xs sm:text-sm font-medium text-green-800">Check-outs</span>
                        <span class="text-sm sm:text-lg font-bold text-green-600"><?php echo $stats['today_check_outs']; ?></span>
                    </div>
                    <div class="flex justify-between items-center p-3 bg-purple-50 rounded-lg">
                        <span class="text-xs sm:text-sm font-medium text-purple-800">Unique Visitors</span>
                        <span class="text-sm sm:text-lg font-bold text-purple-600"><?php echo $stats['today_unique_visitors']; ?></span>
                    </div>
                    <div class="flex justify-between items-center p-3 bg-orange-50 rounded-lg">
                        <span class="text-xs sm:text-sm font-medium text-orange-800">Pre-registered</span>
                        <span class="text-sm sm:text-lg font-bold text-orange-600"><?php echo $stats['pending_pre_reg']; ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Enhanced Recent Activity with Multi-type Support -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 fade-in">
            <div class="px-4 sm:px-6 py-3 sm:py-4 border-b border-gray-200">
                <div class="flex items-center justify-between">
                    <h3 class="text-base sm:text-lg font-semibold text-gray-900">Recent Activity</h3>
                    <div class="flex items-center space-x-4">
                        <div class="flex items-center space-x-2 mobile-hidden">
                            <span class="w-3 h-3 bg-green-500 rounded-full"></span>
                            <span class="text-sm text-gray-600">Vehicles</span>
                        </div>
                        <div class="flex items-center space-x-2 mobile-hidden">
                            <span class="w-3 h-3 bg-blue-500 rounded-full"></span>
                            <span class="text-sm text-gray-600">Visitors</span>
                        </div>
                        <div class="flex items-center space-x-2 mobile-hidden">
                            <span class="w-3 h-3 bg-orange-500 rounded-full"></span>
                            <span class="text-sm text-gray-600">Deliveries</span>
                        </div>
                        <a href="reports.php" class="text-blue-600 hover:text-blue-800 text-xs sm:text-sm font-medium">
                            View All <i class="fas fa-arrow-right ml-1"></i>
                        </a>
                    </div>
                </div>
            </div>
            
            <div class="divide-y divide-gray-200 custom-scroll max-h-96 overflow-y-auto" id="recent-activity">
                <?php if (empty($recent_activities)): ?>
                    <div class="px-4 sm:px-6 py-8 text-center text-gray-500">
                        <i class="fas fa-inbox text-3xl sm:text-4xl mb-4 text-gray-300"></i>
                        <p class="text-sm sm:text-base">No recent activities found</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($recent_activities as $activity): 
                        $icon_color = '';
                        $bg_color = '';
                        $icon = '';
                        $badge_color = '';
                        
                        switch($activity['entry_type']) {
                            case 'vehicle':
                                $icon_color = 'bg-green-100 text-green-600';
                                $icon = $activity['log_type'] === 'vehicle_in' ? 'fa-truck' : 'fa-truck';
                                $badge_color = $activity['log_type'] === 'vehicle_in' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800';
                                break;
                            case 'delivery':
                                $icon_color = 'bg-orange-100 text-orange-600';
                                $icon = 'fa-shipping-fast';
                                $badge_color = $activity['log_type'] === 'delivery_in' ? 'bg-orange-100 text-orange-800' : 'bg-red-100 text-red-800';
                                break;
                            default:
                                $icon_color = 'bg-blue-100 text-blue-600';
                                $icon = 'fa-user';
                                $badge_color = $activity['log_type'] === 'check_in' ? 'bg-blue-100 text-blue-800' : 'bg-red-100 text-red-800';
                        }
                    ?>
                    <div class="px-4 sm:px-6 py-3 sm:py-4 hover:bg-gray-50 transition-colors">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center space-x-3 sm:space-x-4 flex-1 min-w-0">
                                <div class="flex-shrink-0">
                                    <div class="w-8 h-8 sm:w-10 sm:h-10 rounded-full flex items-center justify-center <?php echo $icon_color; ?>">
                                        <i class="fas <?php echo $icon; ?> text-xs sm:text-sm"></i>
                                    </div>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center space-x-2">
                                        <p class="text-xs sm:text-sm font-medium text-gray-900 truncate">
                                            <?php echo htmlspecialchars($activity['full_name']); ?>
                                        </p>
                                        <span class="px-2 py-1 text-xs font-medium rounded-full flex-shrink-0 <?php echo $badge_color; ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $activity['log_type'])); ?>
                                        </span>
                                    </div>
                                    <div class="mt-1 text-xs text-gray-500">
                                        <span><?php echo htmlspecialchars($activity['phone']); ?></span>
                                        <?php if ($activity['company']): ?>
                                            <span class="ml-2">• <?php echo htmlspecialchars($activity['company']); ?></span>
                                        <?php endif; ?>
                                        <?php if ($activity['vehicle_number']): ?>
                                            <span class="ml-2 mobile-hidden">• Vehicle: <?php echo htmlspecialchars($activity['vehicle_number']); ?></span>
                                        <?php endif; ?>
                                        <span class="ml-2">• By: <?php echo htmlspecialchars($activity['operator_name']); ?></span>
                                        <?php if ($activity['location_name']): ?>
                                            <span class="ml-2 mobile-hidden">• <?php echo htmlspecialchars($activity['location_name']); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="text-xs text-gray-500 flex-shrink-0 ml-2">
                                <div class="sm:hidden"><?php echo date('H:i', strtotime($activity['kenya_time'])); ?></div>
                                <div class="mobile-hidden"><?php echo date('M j, g:i A', strtotime($activity['kenya_time'])); ?></div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Enhanced Quick Access Grid -->
        <div class="mt-8 sm:mt-12">
            <h3 class="text-lg font-semibold text-gray-900 mb-4 px-2 sm:px-0">Management Tools</h3>
            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 sm:gap-4">
                <a href="visitors.php" class="bg-white p-3 sm:p-4 rounded-lg shadow-sm border border-gray-200 text-center hover:shadow-md transition-all action-button">
                    <i class="fas fa-users text-blue-500 text-xl sm:text-2xl mb-2"></i>
                    <p class="text-xs sm:text-sm font-medium text-gray-700">Visitors</p>
                </a>
                
                <a href="reports.php" class="bg-white p-3 sm:p-4 rounded-lg shadow-sm border border-gray-200 text-center hover:shadow-md transition-all action-button">
                    <i class="fas fa-chart-bar text-orange-500 text-xl sm:text-2xl mb-2"></i>
                    <p class="text-xs sm:text-sm font-medium text-gray-700">Reports</p>
                </a>
                
                <a href="settings.php" class="bg-white p-3 sm:p-4 rounded-lg shadow-sm border border-gray-200 text-center hover:shadow-md transition-all action-button">
                    <i class="fas fa-cog text-gray-500 text-xl sm:text-2xl mb-2"></i>
                    <p class="text-xs sm:text-sm font-medium text-gray-700">Settings</p>
                </a>
                
                <a href="notifications.php" class="bg-white p-3 sm:p-4 rounded-lg shadow-sm border border-gray-200 text-center hover:shadow-md transition-all action-button relative">
                    <i class="fas fa-bell text-yellow-500 text-xl sm:text-2xl mb-2"></i>
                    <p class="text-xs sm:text-sm font-medium text-gray-700">Notifications</p>
                    <?php if ($unread > 0): ?>
                        <span class="absolute top-1 right-1 bg-red-500 text-white text-xs rounded-full h-5 w-5 flex items-center justify-center">
                            <?php echo $unread > 9 ? '9+' : $unread; ?>
                        </span>
                    <?php endif; ?>
                </a>
                
                <a href="backup-system.php" class="bg-white p-3 sm:p-4 rounded-lg shadow-sm border border-gray-200 text-center hover:shadow-md transition-all action-button">
                    <i class="fas fa-database text-green-500 text-xl sm:text-2xl mb-2"></i>
                    <p class="text-xs sm:text-sm font-medium text-gray-700">Backup</p>
                </a>
                
                <a href="operators.php" class="bg-white p-3 sm:p-4 rounded-lg shadow-sm border border-gray-200 text-center hover:shadow-md transition-all action-button">
                    <i class="fas fa-users-cog text-purple-500 text-xl sm:text-2xl mb-2"></i>
                    <p class="text-xs sm:text-sm font-medium text-gray-700">Operators</p>
                </a>
            </div>
        </div>
    </div>

    <!-- Enhanced Mobile Navigation -->
    <div class="mobile-nav sm:hidden">
        <div class="grid grid-cols-5 gap-1">
            <a href="dashboard.php" class="mobile-nav-item active">
                <i class="fas fa-home"></i>
                <span>Home</span>
            </a>
            <a href="scanner.php" class="mobile-nav-item">
                <i class="fas fa-qrcode"></i>
                <span>Scan</span>
            </a>
            <a href="visitors.php" class="mobile-nav-item">
                <i class="fas fa-users"></i>
                <span>Visitors</span>
            </a>
            <a href="reports.php" class="mobile-nav-item">
                <i class="fas fa-chart-bar"></i>
                <span>Reports</span>
            </a>
            <a href="settings.php" class="mobile-nav-item">
                <i class="fas fa-cog"></i>
                <span>Settings</span>
            </a>
        </div>
    </div>

    <!-- Enhanced Notification Panel -->
    <div id="notificationPanel" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden z-50 sm:hidden">
        <div class="fixed bottom-0 left-0 right-0 bg-white rounded-t-xl p-4 max-h-96 overflow-y-auto">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-semibold">Notifications</h3>
                <button onclick="toggleNotifications()" class="text-gray-500 hover:text-gray-700">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div id="notificationContent">
                <!-- Notifications will be loaded here -->
            </div>
        </div>
    </div>

    <!-- Enhanced JavaScript with Real-time Features -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            initializeWeeklyChart();
            startRealTimeUpdates();
            initializeAnimations();
            
            // Add loading states to action buttons
            document.querySelectorAll('.action-button').forEach(button => {
                button.addEventListener('click', function(e) {
                    if (!this.classList.contains('loading')) {
                        this.classList.add('loading');
                        const icon = this.querySelector('i');
                        const originalClass = icon.className;
                        icon.className = 'fas fa-spinner fa-spin text-xl sm:text-2xl mb-2';
                        
                        setTimeout(() => {
                            this.classList.remove('loading');
                            icon.className = originalClass;
                        }, 2000);
                    }
                });
            });
        });

        // Enhanced chart initialization with multi-dataset support
        function initializeWeeklyChart() {
            const weeklyData = <?php echo json_encode($weekly_stats); ?>;
            
            const ctx = document.getElementById('weeklyChart').getContext('2d');
            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: weeklyData.map(d => {
                        const date = new Date(d.log_date);
                        return date.toLocaleDateString('en-US', { 
                            month: 'short', 
                            day: 'numeric' 
                        });
                    }),
                    datasets: [{
                        label: 'Entries',
                        data: weeklyData.map(d => d.entries),
                        borderColor: '#10b981',
                        backgroundColor: 'rgba(16, 185, 129, 0.1)',
                        tension: 0.4,
                        fill: true
                    }, {
                        label: 'Exits',
                        data: weeklyData.map(d => d.exits),
                        borderColor: '#ef4444',
                        backgroundColor: 'rgba(239, 68, 68, 0.1)',
                        tension: 0.4,
                        fill: true
                    }, {
                        label: 'Vehicles',
                        data: weeklyData.map(d => d.vehicles),
                        borderColor: '#8b5cf6',
                        backgroundColor: 'rgba(139, 92, 246, 0.1)',
                        tension: 0.4,
                        fill: false
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'top',
                            labels: {
                                usePointStyle: true,
                                padding: 20,
                                font: {
                                    size: window.innerWidth < 640 ? 10 : 12
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                stepSize: 1,
                                font: {
                                    size: window.innerWidth < 640 ? 10 : 12
                                }
                            },
                            grid: {
                                color: 'rgba(0, 0, 0, 0.05)'
                            }
                        },
                        x: {
                            ticks: {
                                font: {
                                    size: window.innerWidth < 640 ? 10 : 12
                                }
                            },
                            grid: {
                                display: false
                            }
                        }
                    },
                    interaction: {
                        intersect: false,
                        mode: 'index'
                    }
                }
            });
        }

        // Enhanced real-time updates with location support
        function startRealTimeUpdates() {
            updateDashboardStats();
            setInterval(updateDashboardStats, 30000);
            setInterval(updateCurrentTime, 60000);
        }

        function updateDashboardStats() {
            const locationId = document.getElementById('location_selector').value;
            
            fetch(`api-realtime.php?endpoint=dashboard_stats&location_id=${locationId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        updateStatCards(data.data);
                        updateLocationStats(data.location_stats);
                        updateRecentActivities();
                        updateVehicleStatus();
                    }
                })
                .catch(error => {
                    console.error('Error updating stats:', error);
                });
        }

        function updateStatCards(stats) {
            const cardData = [
                { id: 'visitors-inside', value: stats.visitors_inside },
                { id: 'vehicles-inside', value: stats.vehicles_inside },
                { id: 'active-deliveries', value: stats.active_deliveries },
                { id: 'overdue-vehicles', value: stats.overdue_vehicles },
                { id: 'today-entries', value: stats.today_entries },
                { id: 'security-alerts', value: stats.security_alerts }
            ];

            cardData.forEach(card => {
                const element = document.getElementById(card.id);
                if (element && element.textContent !== card.value.toString()) {
                    animateNumber(element, parseInt(element.textContent) || 0, card.value);
                }
            });
        }

        function animateNumber(element, start, end) {
            const duration = 1000;
            const startTime = performance.now();
            
            function update(currentTime) {
                const elapsed = currentTime - startTime;
                const progress = Math.min(elapsed / duration, 1);
                
                const current = Math.round(start + (end - start) * progress);
                element.textContent = current;
                
                if (progress < 1) {
                    requestAnimationFrame(update);
                } else {
                    element.closest('.stat-card').classList.add('scale-in');
                    setTimeout(() => {
                        element.closest('.stat-card').classList.remove('scale-in');
                    }, 300);
                }
            }
            
            requestAnimationFrame(update);
        }

        function updateLocationStats(locationStats) {
            const container = document.getElementById('location-stats');
            if (container && locationStats) {
                // Update location statistics dynamically
                locationStats.forEach(location => {
                    const locationElement = container.querySelector(`[data-location-id="${location.id}"]`);
                    if (locationElement) {
                        const vehicleCount = locationElement.querySelector('.vehicle-count');
                        const entryCount = locationElement.querySelector('.entry-count');
                        if (vehicleCount) vehicleCount.textContent = `${location.vehicles_count} vehicles`;
                        if (entryCount) entryCount.textContent = `${location.today_entries} entries today`;
                    }
                });
            }
        }

        function updateRecentActivities() {
            const locationId = document.getElementById('location_selector').value;
            
            fetch(`api-realtime.php?endpoint=recent_activity&limit=15&location_id=${locationId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        updateActivitiesList(data.data);
                    }
                })
                .catch(error => {
                    console.error('Error updating activities:', error);
                });
        }

        function updateActivitiesList(activities) {
            const container = document.getElementById('recent-activity');
            if (!container || !activities.length) return;

            const firstActivity = container.querySelector('[data-activity-time]');
            const latestTime = firstActivity ? firstActivity.dataset.activityTime : '';
            
            if (activities[0] && activities[0].timestamp !== latestTime) {
                let html = '';
                activities.forEach(activity => {
                    const iconColor = getActivityIconColor(activity.type);
                    const badgeColor = getActivityBadgeColor(activity.type);
                    const icon = getActivityIcon(activity.type);
                    
                    html += `
                        <div class="px-4 sm:px-6 py-3 sm:py-4 hover:bg-gray-50 transition-colors slide-in-right" data-activity-time="${activity.timestamp}">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center space-x-3 sm:space-x-4 flex-1 min-w-0">
                                    <div class="flex-shrink-0">
                                        <div class="w-8 h-8 sm:w-10 sm:h-10 rounded-full flex items-center justify-center ${iconColor}">
                                            <i class="fas ${icon} text-xs sm:text-sm"></i>
                                        </div>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-center space-x-2">
                                            <p class="text-xs sm:text-sm font-medium text-gray-900 truncate">
                                                ${activity.visitor_name}
                                            </p>
                                            <span class="px-2 py-1 text-xs font-medium rounded-full flex-shrink-0 ${badgeColor}">
                                                ${activity.type.replace('_', ' ').replace(/\b\w/g, l => l.toUpperCase())}
                                            </span>
                                        </div>
                                        <div class="mt-1 text-xs text-gray-500">
                                            <span>${activity.phone || 'No phone'}</span>
                                            ${activity.company ? `<span class="ml-2">• ${activity.company}</span>` : ''}
                                            ${activity.vehicle ? `<span class="ml-2 mobile-hidden">• Vehicle: ${activity.vehicle}</span>` : ''}
                                            <span class="ml-2">• By: ${activity.operator}</span>
                                            ${activity.location ? `<span class="ml-2 mobile-hidden">• ${activity.location}</span>` : ''}
                                        </div>
                                    </div>
                                </div>
                                <div class="text-xs text-gray-500 flex-shrink-0 ml-2">
                                    <div class="sm:hidden">${activity.time}</div>
                                    <div class="mobile-hidden">${activity.time_ago}</div>
                                </div>
                            </div>
                        </div>
                    `;
                });
                
                container.innerHTML = html;
            }
        }

        function getActivityIconColor(type) {
            switch(type) {
                case 'vehicle_in':
                case 'vehicle_out':
                    return 'bg-green-100 text-green-600';
                case 'delivery_in':
                case 'delivery_out':
                    return 'bg-orange-100 text-orange-600';
                default:
                    return 'bg-blue-100 text-blue-600';
            }
        }

        function getActivityBadgeColor(type) {
            const isIn = type.includes('_in') || type === 'check_in';
            if (type.includes('vehicle')) {
                return isIn ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800';
            } else if (type.includes('delivery')) {
                return isIn ? 'bg-orange-100 text-orange-800' : 'bg-red-100 text-red-800';
            } else {
                return isIn ? 'bg-blue-100 text-blue-800' : 'bg-red-100 text-red-800';
            }
        }

        function getActivityIcon(type) {
            if (type.includes('vehicle')) {
                return 'fa-truck';
            } else if (type.includes('delivery')) {
                return 'fa-shipping-fast';
            } else {
                return 'fa-user';
            }
        }

        function updateVehicleStatus() {
            const locationId = document.getElementById('location_selector').value;
            
            fetch(`api-realtime.php?endpoint=vehicles_inside&location_id=${locationId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        updateVehiclesList(data.data);
                    }
                })
                .catch(error => {
                    console.error('Error updating vehicle status:', error);
                });
        }

        function updateVehiclesList(vehicles) {
            const container = document.getElementById('inside-vehicles');
            if (!container) return;

            if (vehicles.length === 0) {
                container.innerHTML = `
                    <div class="text-center py-8 text-gray-500">
                        <i class="fas fa-car text-3xl mb-4 text-gray-300"></i>
                        <p class="text-sm">No vehicles currently inside</p>
                    </div>
                `;
                return;
            }

            let html = '';
            vehicles.forEach(vehicle => {
                const isOverdue = vehicle.status_type === 'overdue';
                const statusColor = isOverdue ? 'border-yellow-300 bg-yellow-50' : '';
                const iconBg = isOverdue ? 'bg-yellow-100' : 'bg-green-100';
                const iconColor = isOverdue ? 'text-yellow-600' : 'text-green-600';
                const badgeColor = isOverdue ? 'bg-yellow-100 text-yellow-800' : 'bg-green-100 text-green-800';
                const statusText = isOverdue ? 'Overdue' : 'Inside';
                
                html += `
                    <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg border ${statusColor} slide-in-right">
                        <div class="flex items-center space-x-4">
                            <div class="w-12 h-12 ${iconBg} rounded-lg flex items-center justify-center">
                                <i class="fas ${vehicle.vehicle_type === 'truck' ? 'fa-truck' : 'fa-car'} ${iconColor}"></i>
                            </div>
                            <div>
                                <h4 class="font-medium text-gray-900">${vehicle.license_plate}</h4>
                                <p class="text-sm text-gray-600">${vehicle.make} ${vehicle.model} • ${vehicle.purpose}</p>
                                <p class="text-xs text-gray-500">Driver: ${vehicle.driver_name} • Dest: ${vehicle.destination}</p>
                                <p class="text-xs text-gray-500">Authorized by: ${vehicle.authorized_by} • ${Math.floor(vehicle.minutes_inside/60)}h ${vehicle.minutes_inside%60}m ago</p>
                            </div>
                        </div>
                        <div class="flex flex-col items-end space-y-2">
                            <span class="px-3 py-1 text-xs font-medium rounded-full ${badgeColor}">
                                ${statusText}
                            </span>
                            <button onclick="checkOutVehicle('${vehicle.vehicle_id}')" class="text-red-600 hover:text-red-800 text-sm transition-colors">
                                <i class="fas fa-sign-out-alt mr-1"></i>Check Out
                            </button>
                        </div>
                    </div>
                `;
            });
            
            container.innerHTML = html;
        }

        function updateCurrentTime() {
            const timeElements = document.querySelectorAll('[data-time]');
            const now = new Date();
            const timeString = now.toLocaleString('en-KE', {
                weekday: 'short',
                year: 'numeric',
                month: 'short',
                day: 'numeric',
                hour: 'numeric',
                minute: '2-digit',
                hour12: true,
                timeZone: 'Africa/Nairobi'
            });
            
            timeElements.forEach(element => {
                if (window.innerWidth < 640) {
                    element.textContent = now.toLocaleTimeString('en-KE', {
                        hour: '2-digit',
                        minute: '2-digit',
                        hour12: false,
                        timeZone: 'Africa/Nairobi'
                    });
                } else {
                    element.textContent = timeString;
                }
            });
        }

        function changeLocation() {
            const locationId = document.getElementById('location_selector').value;
            updateDashboardStats();
            // Add loading state
            document.querySelectorAll('.stat-card').forEach(card => {
                card.classList.add('loading');
            });
            setTimeout(() => {
                document.querySelectorAll('.stat-card').forEach(card => {
                    card.classList.remove('loading');
                });
            }, 1500);
        }

        function refreshVehicleStatus() {
            const icon = document.getElementById('vehicle-refresh-icon');
            icon.classList.add('fa-spin');
            updateVehicleStatus();
            setTimeout(() => {
                icon.classList.remove('fa-spin');
            }, 1000);
        }

        function checkOutVehicle(vehicleId) {
            if (confirm('Are you sure you want to check out this vehicle?')) {
                fetch('api-vehicle-checkout.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        vehicle_id: vehicleId,
                        action: 'checkout'
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showNotification('Vehicle checked out successfully', 'success');
                        updateVehicleStatus();
                        updateDashboardStats();
                    } else {
                        showNotification(data.message || 'Failed to check out vehicle', 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showNotification('An error occurred', 'error');
                });
            }
        }

        function toggleNotifications() {
            const panel = document.getElementById('notificationPanel');
            const content = document.getElementById('notificationContent');
            
            if (panel.classList.contains('hidden')) {
                const locationId = document.getElementById('location_selector').value;
                fetch(`api-realtime.php?endpoint=notifications&location_id=${locationId}`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            displayNotifications(data.data.notifications);
                            panel.classList.remove('hidden');
                        }
                    })
                    .catch(error => {
                        console.error('Error loading notifications:', error);
                    });
            } else {
                panel.classList.add('hidden');
            }
        }

        function displayNotifications(notifications) {
            const content = document.getElementById('notificationContent');
            
            if (notifications.length === 0) {
                content.innerHTML = `
                    <div class="text-center py-8 text-gray-500">
                        <i class="fas fa-bell-slash text-3xl mb-2"></i>
                        <p>No notifications</p>
                    </div>
                `;
                return;
            }
            
            let html = '';
            notifications.forEach(notification => {
                const iconColor = notification.type === 'alert' ? 'text-red-600' : 
                                 notification.type === 'warning' ? 'text-yellow-600' : 'text-blue-600';
                const icon = notification.type === 'alert' ? 'fa-exclamation-circle' : 
                            notification.type === 'warning' ? 'fa-exclamation-triangle' : 'fa-info-circle';
                
                html += `
                    <div class="p-3 border-b border-gray-200 last:border-b-0 hover:bg-gray-50 transition-colors">
                        <div class="flex items-start space-x-3">
                            <i class="fas ${icon} ${iconColor} mt-1"></i>
                            <div class="flex-1">
                                <p class="text-sm font-medium text-gray-900">${notification.title}</p>
                                <p class="text-xs text-gray-600 mt-1">${notification.message}</p>
                                <p class="text-xs text-gray-500 mt-1">${notification.time_ago}</p>
                            </div>
                            ${!notification.is_read ? '<span class="w-2 h-2 bg-blue-500 rounded-full"></span>' : ''}
                        </div>
                    </div>
                `;
            });
            
            content.innerHTML = html;
        }

        function showNotification(message, type = 'info') {
            const notification = document.createElement('div');
            notification.className = `fixed top-4 right-4 p-4 rounded-lg shadow-lg z-50 ${
                type === 'success' ? 'bg-green-100 border-green-200 text-green-700' :
                type === 'error' ? 'bg-red-100 border-red-200 text-red-700' :
                'bg-blue-100 border-blue-200 text-blue-700'
            } border`;
            
            notification.innerHTML = `
                <div class="flex items-center">
                    <i class="fas ${
                        type === 'success' ? 'fa-check-circle' :
                        type === 'error' ? 'fa-exclamation-circle' :
                        'fa-info-circle'
                    } mr-2"></i>
                    <span>${message}</span>
                </div>
            `;
            
            document.body.appendChild(notification);
            
            setTimeout(() => {
                notification.classList.add('fade-in');
            }, 100);
            
            setTimeout(() => {
                notification.remove();
            }, 5000);
        }

        function initializeAnimations() {
            // Stagger fade-in animations
            const fadeElements = document.querySelectorAll('.fade-in');
            fadeElements.forEach((element, index) => {
                element.style.animationDelay = `${index * 0.1}s`;
            });

            // Initialize intersection observer for scroll animations
            if ('IntersectionObserver' in window) {
                const observer = new IntersectionObserver((entries) => {
                    entries.forEach(entry => {
                        if (entry.isIntersecting) {
                            entry.target.classList.add('slide-in-right');
                            observer.unobserve(entry.target);
                        }
                    });
                });

                document.querySelectorAll('.stat-card, .action-button').forEach(card => {
                    observer.observe(card);
                });
            }
        }

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey || e.metaKey) {
                switch(e.key) {
                    case '1':
                        e.preventDefault();
                        window.location.href = 'vehicle-scanner.php';
                        break;
                    case '2':
                        e.preventDefault();
                        window.location.href = 'scanner.php';
                        break;
                    case '3':
                        e.preventDefault();
                        window.location.href = 'delivery-tracking.php';
                        break;
                    case '4':
                        e.preventDefault();
                        window.location.href = 'quick-checkin.php';
                        break;
                    case 'r':
                        e.preventDefault();
                        updateDashboardStats();
                        break;
                }
            }
        });

        // Handle visibility change to pause/resume updates
        document.addEventListener('visibilitychange', function() {
            if (document.hidden) {
                clearInterval(window.dashboardUpdateInterval);
            } else {
                startRealTimeUpdates();
            }
        });

        // Touch gestures for mobile
        let touchStartY = 0;
        let touchEndY = 0;

        document.addEventListener('touchstart', function(e) {
            touchStartY = e.changedTouches[0].screenY;
        });

        document.addEventListener('touchend', function(e) {
            touchEndY = e.changedTouches[0].screenY;
            handleSwipe();
        });

        function handleSwipe() {
            const swipeThreshold = 100;
            const diff = touchStartY - touchEndY;
            
            if (diff > swipeThreshold && window.scrollY < 100) {
                showNotification('Refreshing dashboard...', 'info');
                updateDashboardStats();
            }
        }

        // Service worker for offline functionality
        if ('serviceWorker' in navigator && window.innerWidth <= 768) {
            navigator.serviceWorker.register('/sw.js')
                .then(registration => {
                    console.log('SW registered: ', registration);
                })
                .catch(registrationError => {
                    console.log('SW registration failed: ', registrationError);
                });
        }

        // Initialize dashboard
        updateActiveNavItem();

        function updateActiveNavItem() {
            const currentPath = window.location.pathname;
            const navItems = document.querySelectorAll('.mobile-nav-item');
            
            navItems.forEach(item => {
                item.classList.remove('active');
                if (item.getAttribute('href') === currentPath.split('/').pop()) {
                    item.classList.add('active');
                }
            });
        }
    </script>
</body>
</html>
