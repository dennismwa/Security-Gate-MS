<?php
require_once 'config/database.php';

// Set timezone to Kenya
date_default_timezone_set('Africa/Nairobi');

$database = new Database();
$db = $database->getConnection();

$session = requireAuth($db);
$settings = getSettings($db);

// Get today's statistics with Kenya timezone
$stmt = $db->prepare("SELECT 
    COUNT(CASE WHEN log_type = 'check_in' AND DATE(CONVERT_TZ(log_timestamp, '+00:00', '+03:00')) = CURDATE() THEN 1 END) as today_check_ins,
    COUNT(CASE WHEN log_type = 'check_out' AND DATE(CONVERT_TZ(log_timestamp, '+00:00', '+03:00')) = CURDATE() THEN 1 END) as today_check_outs,
    COUNT(DISTINCT CASE WHEN DATE(CONVERT_TZ(log_timestamp, '+00:00', '+03:00')) = CURDATE() THEN visitor_id END) as today_unique_visitors,
    (SELECT COUNT(*) FROM visitors WHERE status = 'active') as total_active_visitors,
    (SELECT COUNT(*) FROM pre_registrations WHERE status = 'pending' AND visit_date >= CURDATE()) as pending_pre_reg
FROM gate_logs");
$stmt->execute();
$stats = $stmt->fetch();

// Get recent activities with Kenya timezone
$stmt = $db->prepare("SELECT gl.*, v.full_name, v.phone, v.vehicle_number, go.operator_name,
                     CONVERT_TZ(gl.log_timestamp, '+00:00', '+03:00') as kenya_time
                     FROM gate_logs gl 
                     JOIN visitors v ON gl.visitor_id = v.visitor_id 
                     JOIN gate_operators go ON gl.operator_id = go.id 
                     ORDER BY gl.log_timestamp DESC 
                     LIMIT 10");
$stmt->execute();
$recent_activities = $stmt->fetchAll();

// Get currently inside count
$stmt = $db->prepare("SELECT COUNT(*) as inside_count FROM (
    SELECT visitor_id, 
           MAX(CASE WHEN log_type = 'check_in' THEN log_timestamp END) as last_checkin,
           MAX(CASE WHEN log_type = 'check_out' THEN log_timestamp END) as last_checkout
    FROM gate_logs 
    GROUP BY visitor_id
    HAVING last_checkin IS NOT NULL AND (last_checkout IS NULL OR last_checkin > last_checkout)
) as inside_visitors");
$stmt->execute();
$inside_count = $stmt->fetch()['inside_count'];

// Get weekly stats for the chart
$stmt = $db->prepare("SELECT 
    DATE(CONVERT_TZ(log_timestamp, '+00:00', '+03:00')) as log_date,
    COUNT(CASE WHEN log_type = 'check_in' THEN 1 END) as check_ins,
    COUNT(CASE WHEN log_type = 'check_out' THEN 1 END) as check_outs
FROM gate_logs 
WHERE CONVERT_TZ(log_timestamp, '+00:00', '+03:00') >= DATE_SUB(NOW(), INTERVAL 7 DAY)
GROUP BY DATE(CONVERT_TZ(log_timestamp, '+00:00', '+03:00'))
ORDER BY log_date ASC");
$stmt->execute();
$weekly_stats = $stmt->fetchAll();

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
        /* Mobile-first responsive design */
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

        /* Enhanced mobile navigation */
        .mobile-nav {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: white;
            border-top: 1px solid #e5e7eb;
            z-index: 50;
            padding: 0.5rem;
        }

        .mobile-nav-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 0.5rem;
            text-decoration: none;
            color: #6b7280;
            transition: color 0.2s;
        }

        .mobile-nav-item:hover,
        .mobile-nav-item.active {
            color: #2563eb;
        }

        .mobile-nav-item i {
            font-size: 1.25rem;
            margin-bottom: 0.25rem;
        }

        .mobile-nav-item span {
            font-size: 0.75rem;
            font-weight: 500;
        }

        /* Animation classes */
        .fade-in {
            animation: fadeIn 0.5s ease-in;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .scale-in {
            animation: scaleIn 0.3s ease-out;
        }

        @keyframes scaleIn {
            from { transform: scale(0.95); opacity: 0; }
            to { transform: scale(1); opacity: 1; }
        }

        /* Dashboard specific styles */
        .stat-card {
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        }

        .action-button {
            transition: all 0.2s ease;
        }

        .action-button:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }

        /* Real-time updates indicator */
        .pulse-dot {
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }

        /* Custom scrollbar for mobile */
        .custom-scroll::-webkit-scrollbar {
            width: 4px;
        }

        .custom-scroll::-webkit-scrollbar-track {
            background: #f1f5f9;
        }

        .custom-scroll::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 2px;
        }
    </style>
</head>
<body class="bg-gray-50 pb-20 sm:pb-0">
    <!-- Mobile-First Navigation -->
    <nav class="bg-white shadow-sm border-b border-gray-200">
        <div class="max-w-7xl mx-auto px-2 sm:px-4 lg:px-8">
            <div class="flex justify-between h-14 sm:h-16">
                <div class="flex items-center space-x-2 sm:space-x-4">
                    <div class="flex-shrink-0">
                        <div class="h-8 w-8 sm:h-10 sm:w-10 bg-blue-600 rounded-lg flex items-center justify-center">
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
                        <span class="absolute -top-1 -right-1 bg-red-500 text-white text-xs rounded-full h-4 w-4 flex items-center justify-center">
                            <?php
                            $stmt = $db->prepare("SELECT COUNT(*) as unread FROM notifications WHERE is_read = 0");
                            $stmt->execute();
                            $unread = $stmt->fetch()['unread'];
                            echo $unread > 9 ? '9+' : $unread;
                            ?>
                        </span>
                    </button>
                    <a href="logout.php" class="text-gray-500 hover:text-gray-700 mobile-hidden">
                        <i class="fas fa-sign-out-alt"></i>
                        <span class="ml-1">Logout</span>
                    </a>
                </div>
            </div>
        </div>
    </nav>

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

        <!-- Quick Actions - Mobile Priority -->
        <div class="grid grid-cols-2 sm:grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-6 mb-6 sm:mb-8">
            <a href="scanner.php" class="action-button bg-blue-600 hover:bg-blue-700 text-white p-4 sm:p-6 rounded-xl shadow-sm transition-colors fade-in">
                <div class="flex flex-col items-center text-center">
                    <i class="fas fa-qrcode text-xl sm:text-2xl mb-2"></i>
                    <h3 class="text-sm sm:text-lg font-semibold">QR Scanner</h3>
                    <p class="text-xs sm:text-sm opacity-90 mobile-hidden">Scan visitor codes</p>
                </div>
            </a>
            
            <a href="quick-checkin.php" class="action-button bg-orange-600 hover:bg-orange-700 text-white p-4 sm:p-6 rounded-xl shadow-sm transition-colors fade-in">
                <div class="flex flex-col items-center text-center">
                    <i class="fas fa-tachometer-alt text-xl sm:text-2xl mb-2"></i>
                    <h3 class="text-sm sm:text-lg font-semibold">Quick Check</h3>
                    <p class="text-xs sm:text-sm opacity-90 mobile-hidden">Manual check-in/out</p>
                </div>
            </a>
            
            <a href="visitors.php" class="action-button bg-green-600 hover:bg-green-700 text-white p-4 sm:p-6 rounded-xl shadow-sm transition-colors fade-in">
                <div class="flex flex-col items-center text-center">
                    <i class="fas fa-users text-xl sm:text-2xl mb-2"></i>
                    <h3 class="text-sm sm:text-lg font-semibold">Visitors</h3>
                    <p class="text-xs sm:text-sm opacity-90 mobile-hidden">Manage visitors</p>
                </div>
            </a>
            
            <a href="pre-register.php" class="action-button bg-purple-600 hover:bg-purple-700 text-white p-4 sm:p-6 rounded-xl shadow-sm transition-colors fade-in">
                <div class="flex flex-col items-center text-center">
                    <i class="fas fa-calendar-plus text-xl sm:text-2xl mb-2"></i>
                    <h3 class="text-sm sm:text-lg font-semibold">Pre-Register</h3>
                    <p class="text-xs sm:text-sm opacity-90 mobile-hidden">Schedule visits</p>
                </div>
            </a>
        </div>

        <!-- Statistics Cards - Mobile Responsive -->
        <div class="grid mobile-grid-2 sm:grid-cols-2 lg:grid-cols-5 gap-3 sm:gap-6 mb-6 sm:mb-8">
            <div class="stat-card bg-white p-3 sm:p-6 rounded-xl shadow-sm border border-gray-200 scale-in">
                <div class="flex items-center">
                    <div class="p-2 bg-blue-100 rounded-lg">
                        <i class="fas fa-sign-in-alt text-blue-600 text-sm sm:text-base"></i>
                    </div>
                    <div class="ml-3 sm:ml-4">
                        <p class="text-xs sm:text-sm font-medium text-gray-600">Check-ins</p>
                        <p class="text-lg sm:text-2xl font-bold text-gray-900"><?php echo $stats['today_check_ins']; ?></p>
                    </div>
                </div>
            </div>
            
            <div class="stat-card bg-white p-3 sm:p-6 rounded-xl shadow-sm border border-gray-200 scale-in">
                <div class="flex items-center">
                    <div class="p-2 bg-green-100 rounded-lg">
                        <i class="fas fa-sign-out-alt text-green-600 text-sm sm:text-base"></i>
                    </div>
                    <div class="ml-3 sm:ml-4">
                        <p class="text-xs sm:text-sm font-medium text-gray-600">Check-outs</p>
                        <p class="text-lg sm:text-2xl font-bold text-gray-900"><?php echo $stats['today_check_outs']; ?></p>
                    </div>
                </div>
            </div>
            
            <div class="stat-card bg-white p-3 sm:p-6 rounded-xl shadow-sm border border-gray-200 scale-in">
                <div class="flex items-center">
                    <div class="p-2 bg-purple-100 rounded-lg">
                        <i class="fas fa-building text-purple-600 text-sm sm:text-base"></i>
                    </div>
                    <div class="ml-3 sm:ml-4">
                        <p class="text-xs sm:text-sm font-medium text-gray-600">Inside</p>
                        <p class="text-lg sm:text-2xl font-bold text-gray-900"><?php echo $inside_count; ?></p>
                    </div>
                </div>
            </div>
            
            <div class="stat-card bg-white p-3 sm:p-6 rounded-xl shadow-sm border border-gray-200 scale-in">
                <div class="flex items-center">
                    <div class="p-2 bg-yellow-100 rounded-lg">
                        <i class="fas fa-user-friends text-yellow-600 text-sm sm:text-base"></i>
                    </div>
                    <div class="ml-3 sm:ml-4">
                        <p class="text-xs sm:text-sm font-medium text-gray-600">Unique</p>
                        <p class="text-lg sm:text-2xl font-bold text-gray-900"><?php echo $stats['today_unique_visitors']; ?></p>
                    </div>
                </div>
            </div>
            
            <div class="stat-card bg-white p-3 sm:p-6 rounded-xl shadow-sm border border-gray-200 scale-in">
                <div class="flex items-center">
                    <div class="p-2 bg-red-100 rounded-lg">
                        <i class="fas fa-clock text-red-600 text-sm sm:text-base"></i>
                    </div>
                    <div class="ml-3 sm:ml-4">
                        <p class="text-xs sm:text-sm font-medium text-gray-600">Pending</p>
                        <p class="text-lg sm:text-2xl font-bold text-gray-900"><?php echo $stats['pending_pre_reg']; ?></p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Charts and Activities - Mobile Stack -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 sm:gap-8 mb-6 sm:mb-8">
            <!-- Weekly Activity Chart -->
            <div class="lg:col-span-2 bg-white rounded-xl shadow-sm border border-gray-200 p-4 sm:p-6 fade-in">
                <h3 class="text-base sm:text-lg font-semibold text-gray-900 mb-4 sm:mb-6">Weekly Activity</h3>
                <div class="h-48 sm:h-64">
                    <canvas id="weeklyChart"></canvas>
                </div>
            </div>

            <!-- Quick Stats -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 sm:p-6 fade-in">
                <h3 class="text-base sm:text-lg font-semibold text-gray-900 mb-4 sm:mb-6">Quick Stats</h3>
                <div class="space-y-3 sm:space-y-4">
                    <div class="flex justify-between items-center p-3 bg-blue-50 rounded-lg">
                        <span class="text-xs sm:text-sm font-medium text-blue-800">Active Visitors</span>
                        <span class="text-sm sm:text-lg font-bold text-blue-600"><?php echo $stats['total_active_visitors']; ?></span>
                    </div>
                    <div class="flex justify-between items-center p-3 bg-green-50 rounded-lg">
                        <span class="text-xs sm:text-sm font-medium text-green-800">Today's Total</span>
                        <span class="text-sm sm:text-lg font-bold text-green-600"><?php echo $stats['today_check_ins'] + $stats['today_check_outs']; ?></span>
                    </div>
                    <div class="flex justify-between items-center p-3 bg-purple-50 rounded-lg">
                        <span class="text-xs sm:text-sm font-medium text-purple-800">Pre-registered</span>
                        <span class="text-sm sm:text-lg font-bold text-purple-600"><?php echo $stats['pending_pre_reg']; ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Activities -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 fade-in">
            <div class="px-4 sm:px-6 py-3 sm:py-4 border-b border-gray-200">
                <div class="flex items-center justify-between">
                    <h3 class="text-base sm:text-lg font-semibold text-gray-900">Recent Activities</h3>
                    <a href="reports.php" class="text-blue-600 hover:text-blue-800 text-xs sm:text-sm font-medium">
                        View All <i class="fas fa-arrow-right ml-1"></i>
                    </a>
                </div>
            </div>
            
            <div class="divide-y divide-gray-200 custom-scroll max-h-96 overflow-y-auto">
                <?php if (empty($recent_activities)): ?>
                    <div class="px-4 sm:px-6 py-8 text-center text-gray-500">
                        <i class="fas fa-inbox text-3xl sm:text-4xl mb-4 text-gray-300"></i>
                        <p class="text-sm sm:text-base">No recent activities found</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($recent_activities as $activity): ?>
                        <div class="px-4 sm:px-6 py-3 sm:py-4 hover:bg-gray-50 transition-colors">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center space-x-3 sm:space-x-4 flex-1 min-w-0">
                                    <div class="flex-shrink-0">
                                        <div class="w-8 h-8 sm:w-10 sm:h-10 rounded-full flex items-center justify-center <?php 
                                            echo $activity['log_type'] === 'check_in' ? 'bg-green-100 text-green-600' : 'bg-red-100 text-red-600'; ?>">
                                            <i class="fas <?php echo $activity['log_type'] === 'check_in' ? 'fa-sign-in-alt' : 'fa-sign-out-alt'; ?> text-xs sm:text-sm"></i>
                                        </div>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-center space-x-2">
                                            <p class="text-xs sm:text-sm font-medium text-gray-900 truncate">
                                                <?php echo htmlspecialchars($activity['full_name']); ?>
                                            </p>
                                            <span class="px-2 py-1 text-xs font-medium rounded-full flex-shrink-0 <?php 
                                                echo $activity['log_type'] === 'check_in' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                                                <?php echo ucfirst(str_replace('_', ' ', $activity['log_type'])); ?>
                                            </span>
                                        </div>
                                        <div class="mt-1 text-xs text-gray-500">
                                            <span><?php echo htmlspecialchars($activity['phone']); ?></span>
                                            <?php if ($activity['vehicle_number']): ?>
                                                <span class="ml-2 mobile-hidden">• Vehicle: <?php echo htmlspecialchars($activity['vehicle_number']); ?></span>
                                            <?php endif; ?>
                                            <span class="ml-2">• By: <?php echo htmlspecialchars($activity['operator_name']); ?></span>
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

        <!-- Major Links Section for Mobile -->
        <div class="mt-8 sm:mt-12">
            <h3 class="text-lg font-semibold text-gray-900 mb-4 px-2 sm:px-0">Quick Access</h3>
            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 sm:gap-4">
                <a href="reports.php" class="bg-white p-3 sm:p-4 rounded-lg shadow-sm border border-gray-200 text-center hover:shadow-md transition-shadow">
                    <i class="fas fa-chart-bar text-orange-500 text-xl sm:text-2xl mb-2"></i>
                    <p class="text-xs sm:text-sm font-medium text-gray-700">Reports</p>
                </a>
                
                <a href="settings.php" class="bg-white p-3 sm:p-4 rounded-lg shadow-sm border border-gray-200 text-center hover:shadow-md transition-shadow">
                    <i class="fas fa-cog text-gray-500 text-xl sm:text-2xl mb-2"></i>
                    <p class="text-xs sm:text-sm font-medium text-gray-700">Settings</p>
                </a>
                
                <a href="notifications.php" class="bg-white p-3 sm:p-4 rounded-lg shadow-sm border border-gray-200 text-center hover:shadow-md transition-shadow relative">
                    <i class="fas fa-bell text-yellow-500 text-xl sm:text-2xl mb-2"></i>
                    <p class="text-xs sm:text-sm font-medium text-gray-700">Notifications</p>
                    <?php if ($unread > 0): ?>
                        <span class="absolute top-1 right-1 bg-red-500 text-white text-xs rounded-full h-5 w-5 flex items-center justify-center">
                            <?php echo $unread > 9 ? '9+' : $unread; ?>
                        </span>
                    <?php endif; ?>
                </a>
                
                <a href="backup-system.php" class="bg-white p-3 sm:p-4 rounded-lg shadow-sm border border-gray-200 text-center hover:shadow-md transition-shadow">
                    <i class="fas fa-database text-green-500 text-xl sm:text-2xl mb-2"></i>
                    <p class="text-xs sm:text-sm font-medium text-gray-700">Backup</p>
                </a>
                
                <a href="system-health.php" class="bg-white p-3 sm:p-4 rounded-lg shadow-sm border border-gray-200 text-center hover:shadow-md transition-shadow">
                    <i class="fas fa-heartbeat text-red-500 text-xl sm:text-2xl mb-2"></i>
                    <p class="text-xs sm:text-sm font-medium text-gray-700">Health</p>
                </a>
                
                <a href="operators.php" class="bg-white p-3 sm:p-4 rounded-lg shadow-sm border border-gray-200 text-center hover:shadow-md transition-shadow">
                    <i class="fas fa-users-cog text-blue-500 text-xl sm:text-2xl mb-2"></i>
                    <p class="text-xs sm:text-sm font-medium text-gray-700">Operators</p>
                </a>
            </div>
        </div>
    </div>

    <!-- Mobile Bottom Navigation -->
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

    <!-- Notification Panel (Mobile) -->
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

    <script>
        // Initialize charts and real-time updates
        document.addEventListener('DOMContentLoaded', function() {
            initializeWeeklyChart();
            startRealTimeUpdates();
            
            // Add loading states
            document.querySelectorAll('.action-button').forEach(button => {
                button.addEventListener('click', function(e) {
                    if (!this.classList.contains('loading')) {
                        this.classList.add('loading');
                        const icon = this.querySelector('i');
                        const originalClass = icon.className;
                        icon.className = 'fas fa-spinner fa-spin text-xl sm:text-2xl mb-2';
                        
                        // Reset after navigation or timeout
                        setTimeout(() => {
                            this.classList.remove('loading');
                            icon.className = originalClass;
                        }, 2000);
                    }
                });
            });
        });

        // Weekly Activity Chart
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
                        label: 'Check-ins',
                        data: weeklyData.map(d => d.check_ins),
                        borderColor: '#10b981',
                        backgroundColor: 'rgba(16, 185, 129, 0.1)',
                        tension: 0.4,
                        fill: true
                    }, {
                        label: 'Check-outs',
                        data: weeklyData.map(d => d.check_outs),
                        borderColor: '#ef4444',
                        backgroundColor: 'rgba(239, 68, 68, 0.1)',
                        tension: 0.4,
                        fill: true
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

        // Real-time updates
        function startRealTimeUpdates() {
            // Update stats every 30 seconds
            setInterval(updateDashboardStats, 30000);
            
            // Update time every minute
            setInterval(updateCurrentTime, 60000);
        }

        function updateDashboardStats() {
            fetch('api-realtime.php?endpoint=dashboard_stats')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        updateStatCards(data.data);
                        updateRecentActivities();
                    }
                })
                .catch(error => {
                    console.error('Error updating stats:', error);
                });
        }

        function updateStatCards(stats) {
            // Update stat cards with animation
            const cards = [
                { element: document.querySelector('.stat-card:nth-child(1) .text-lg'), value: stats.today_check_ins },
                { element: document.querySelector('.stat-card:nth-child(2) .text-lg'), value: stats.today_check_outs },
                { element: document.querySelector('.stat-card:nth-child(3) .text-lg'), value: stats.currently_inside },
                { element: document.querySelector('.stat-card:nth-child(4) .text-lg'), value: stats.today_unique_visitors },
                { element: document.querySelector('.stat-card:nth-child(5) .text-lg'), value: stats.pending_prereg }
            ];

            cards.forEach(card => {
                if (card.element && card.element.textContent !== card.value.toString()) {
                    animateNumber(card.element, parseInt(card.element.textContent) || 0, card.value);
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
                    // Add pulse effect on completion
                    element.parentElement.parentElement.classList.add('scale-in');
                    setTimeout(() => {
                        element.parentElement.parentElement.classList.remove('scale-in');
                    }, 300);
                }
            }
            
            requestAnimationFrame(update);
        }

        function updateRecentActivities() {
            fetch('api-realtime.php?endpoint=recent_activity&limit=10')
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
            const container = document.querySelector('.divide-y.divide-gray-200');
            if (!container || !activities.length) return;

            // Only update if there are new activities
            const firstActivity = container.querySelector('[data-activity-time]');
            const latestTime = firstActivity ? firstActivity.dataset.activityTime : '';
            
            if (activities[0] && activities[0].timestamp !== latestTime) {
                // Rebuild the activities list
                let html = '';
                activities.forEach(activity => {
                    const bgColor = activity.type === 'check_in' ? 'bg-green-100 text-green-600' : 'bg-red-100 text-red-600';
                    const iconColor = activity.type === 'check_in' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800';
                    const icon = activity.type === 'check_in' ? 'fa-sign-in-alt' : 'fa-sign-out-alt';
                    
                    html += `
                        <div class="px-4 sm:px-6 py-3 sm:py-4 hover:bg-gray-50 transition-colors" data-activity-time="${activity.timestamp}">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center space-x-3 sm:space-x-4 flex-1 min-w-0">
                                    <div class="flex-shrink-0">
                                        <div class="w-8 h-8 sm:w-10 sm:h-10 rounded-full flex items-center justify-center ${bgColor}">
                                            <i class="fas ${icon} text-xs sm:text-sm"></i>
                                        </div>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-center space-x-2">
                                            <p class="text-xs sm:text-sm font-medium text-gray-900 truncate">
                                                ${activity.visitor_name}
                                            </p>
                                            <span class="px-2 py-1 text-xs font-medium rounded-full flex-shrink-0 ${iconColor}">
                                                ${activity.type.replace('_', ' ').replace(/\b\w/g, l => l.toUpperCase())}
                                            </span>
                                        </div>
                                        <div class="mt-1 text-xs text-gray-500">
                                            <span>${activity.company || 'No company'}</span>
                                            <span class="ml-2">• By: ${activity.operator}</span>
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

        // Mobile notification panel
        function toggleNotifications() {
            const panel = document.getElementById('notificationPanel');
            const content = document.getElementById('notificationContent');
            
            if (panel.classList.contains('hidden')) {
                // Load notifications
                fetch('api-realtime.php?endpoint=notifications')
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
                const iconColor = notification.type === 'check_in' ? 'text-green-600' : 
                                 notification.type === 'check_out' ? 'text-red-600' : 'text-blue-600';
                const icon = notification.type === 'check_in' ? 'fa-sign-in-alt' : 
                            notification.type === 'check_out' ? 'fa-sign-out-alt' : 'fa-info-circle';
                
                html += `
                    <div class="p-3 border-b border-gray-200 last:border-b-0">
                        <div class="flex items-start space-x-3">
                            <i class="fas ${icon} ${iconColor} mt-1"></i>
                            <div class="flex-1">
                                <p class="text-sm font-medium text-gray-900">${notification.title}</p>
                                <p class="text-xs text-gray-600 mt-1">${notification.message}</p>
                                <p class="text-xs text-gray-500 mt-1">${notification.time_ago}</p>
                            </div>
                        </div>
                    </div>
                `;
            });
            
            content.innerHTML = html;
        }

        // Handle mobile navigation active states
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
            const swipeThreshold = 50;
            const diff = touchStartY - touchEndY;
            
            // Swipe up to refresh
            if (diff > swipeThreshold) {
                if (window.scrollY < 100) {
                    showRefreshIndicator();
                    setTimeout(() => {
                        window.location.reload();
                    }, 1000);
                }
            }
        }

        function showRefreshIndicator() {
            const indicator = document.createElement('div');
            indicator.className = 'fixed top-16 left-1/2 transform -translate-x-1/2 bg-blue-600 text-white px-4 py-2 rounded-lg z-50 text-sm';
            indicator.innerHTML = '<i class="fas fa-sync-alt fa-spin mr-2"></i>Refreshing...';
            document.body.appendChild(indicator);
            
            setTimeout(() => {
                if (indicator.parentNode) {
                    indicator.parentNode.removeChild(indicator);
                }
            }, 2000);
        }

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey || e.metaKey) {
                switch(e.key) {
                    case '1':
                        e.preventDefault();
                        window.location.href = 'scanner.php';
                        break;
                    case '2':
                        e.preventDefault();
                        window.location.href = 'quick-checkin.php';
                        break;
                    case '3':
                        e.preventDefault();
                        window.location.href = 'visitors.php';
                        break;
                    case 'r':
                        e.preventDefault();
                        window.location.reload();
                        break;
                }
            }
        });

        // Handle visibility change (pause updates when tab is not active)
        document.addEventListener('visibilitychange', function() {
            if (document.hidden) {
                // Page is hidden, stop updates
                clearInterval(window.dashboardUpdateInterval);
            } else {
                // Page is visible, resume updates
                startRealTimeUpdates();
            }
        });

        // Initialize mobile navigation
        updateActiveNavItem();

        // Performance optimization for mobile
        if (window.innerWidth <= 768) {
            // Reduce animation complexity on mobile
            document.documentElement.style.setProperty('--animation-duration', '0.2s');
            
            // Lazy load non-critical elements
            setTimeout(() => {
                document.querySelectorAll('.fade-in').forEach(element => {
                    element.classList.add('fade-in');
                });
            }, 100);
        }

        // Service Worker registration for PWA capabilities (optional)
        if ('serviceWorker' in navigator && window.innerWidth <= 768) {
            navigator.serviceWorker.register('/sw.js')
                .then(registration => {
                    console.log('SW registered: ', registration);
                })
                .catch(registrationError => {
                    console.log('SW registration failed: ', registrationError);
                });
        }
    </script>
</body>
</html>
