<?php
require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

$session = requireAuth($db);
$settings = getSettings($db);

// Get today's statistics
$stmt = $db->prepare("SELECT 
    COUNT(CASE WHEN log_type = 'check_in' AND DATE(log_timestamp) = CURDATE() THEN 1 END) as today_check_ins,
    COUNT(CASE WHEN log_type = 'check_out' AND DATE(log_timestamp) = CURDATE() THEN 1 END) as today_check_outs,
    COUNT(DISTINCT CASE WHEN DATE(log_timestamp) = CURDATE() THEN visitor_id END) as today_unique_visitors,
    (SELECT COUNT(*) FROM visitors WHERE status = 'active') as total_active_visitors,
    (SELECT COUNT(*) FROM pre_registrations WHERE status = 'pending' AND visit_date >= CURDATE()) as pending_pre_reg
FROM gate_logs");
$stmt->execute();
$stats = $stmt->fetch();

// Get recent activities
$stmt = $db->prepare("SELECT gl.*, v.full_name, v.phone, v.vehicle_number, go.operator_name 
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
</head>
<body class="bg-gray-50">
    <!-- Navigation -->
    <nav class="bg-white shadow-sm border-b border-gray-200">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between h-16">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <div class="h-10 w-10 bg-blue-600 rounded-lg flex items-center justify-center">
                            <i class="fas fa-shield-alt text-white"></i>
                        </div>
                    </div>
                    <div class="ml-3">
                        <h1 class="text-xl font-semibold text-gray-900">
                            <?php echo htmlspecialchars($settings['system_name'] ?? 'Gate Management'); ?>
                        </h1>
                        <p class="text-sm text-gray-500">Welcome, <?php echo htmlspecialchars($session['operator_name']); ?></p>
                    </div>
                </div>
                
                <div class="flex items-center space-x-4">
                    <span class="text-sm text-gray-500">
                        <i class="fas fa-clock mr-1"></i>
                        <?php echo date('D, M j, Y - g:i A'); ?>
                    </span>
                    <a href="logout.php" class="text-gray-500 hover:text-gray-700">
                        <i class="fas fa-sign-out-alt"></i>
                        <span class="ml-1">Logout</span>
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
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
        <!-- Add this link in the navigation section -->
<a href="notifications.php" class="text-gray-500 hover:text-gray-700 relative">
    <i class="fas fa-bell"></i>
    <span class="ml-1">Notifications</span>
    <?php
    // Get unread notifications count
    $stmt = $db->prepare("SELECT COUNT(*) as unread FROM notifications WHERE is_read = 0");
    $stmt->execute();
    $unread = $stmt->fetch()['unread'];
    if ($unread > 0):
    ?>
        <span class="absolute -top-1 -right-1 bg-red-500 text-white text-xs rounded-full h-5 w-5 flex items-center justify-center">
            <?php echo $unread > 9 ? '9+' : $unread; ?>
        </span>
    <?php endif; ?>
</a>

        <!-- Quick Actions -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
            <a href="scanner.php" class="bg-blue-600 hover:bg-blue-700 text-white p-6 rounded-xl shadow-sm transition-colors">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <i class="fas fa-qrcode text-2xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="text-lg font-semibold">QR Scanner</h3>
                        <p class="text-blue-100">Scan visitor QR codes</p>
                    </div>
                </div>
            </a>
            
            <a href="visitors.php" class="bg-green-600 hover:bg-green-700 text-white p-6 rounded-xl shadow-sm transition-colors">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <i class="fas fa-users text-2xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="text-lg font-semibold">Manage Visitors</h3>
                        <p class="text-green-100">Register & manage visitors</p>
                    </div>
                </div>
            </a>
            
            <a href="pre-register.php" class="bg-purple-600 hover:bg-purple-700 text-white p-6 rounded-xl shadow-sm transition-colors">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <i class="fas fa-calendar-plus text-2xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="text-lg font-semibold">Pre-Register</h3>
                        <p class="text-purple-100">Schedule future visits</p>
                    </div>
                </div>
            </a>
            
            <a href="reports.php" class="bg-orange-600 hover:bg-orange-700 text-white p-6 rounded-xl shadow-sm transition-colors">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <i class="fas fa-chart-bar text-2xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="text-lg font-semibold">Reports</h3>
                        <p class="text-orange-100">View analytics & logs</p>
                    </div>
                </div>
            </a>
        </div>

        <!-- Statistics Cards -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-6 mb-8">
            <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-200">
                <div class="flex items-center">
                    <div class="p-2 bg-blue-100 rounded-lg">
                        <i class="fas fa-sign-in-alt text-blue-600"></i>
                    </div>
                    <div class="ml-4">
                        <p class="text-sm font-medium text-gray-600">Today's Check-ins</p>
                        <p class="text-2xl font-bold text-gray-900"><?php echo $stats['today_check_ins']; ?></p>
                    </div>
                </div>
            </div>
            
            <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-200">
                <div class="flex items-center">
                    <div class="p-2 bg-green-100 rounded-lg">
                        <i class="fas fa-sign-out-alt text-green-600"></i>
                    </div>
                    <div class="ml-4">
                        <p class="text-sm font-medium text-gray-600">Today's Check-outs</p>
                        <p class="text-2xl font-bold text-gray-900"><?php echo $stats['today_check_outs']; ?></p>
                    </div>
                </div>
            </div>
            
            <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-200">
                <div class="flex items-center">
                    <div class="p-2 bg-purple-100 rounded-lg">
                        <i class="fas fa-building text-purple-600"></i>
                    </div>
                    <div class="ml-4">
                        <p class="text-sm font-medium text-gray-600">Currently Inside</p>
                        <p class="text-2xl font-bold text-gray-900"><?php echo $inside_count; ?></p>
                    </div>
                </div>
            </div>
            
            <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-200">
                <div class="flex items-center">
                    <div class="p-2 bg-yellow-100 rounded-lg">
                        <i class="fas fa-user-friends text-yellow-600"></i>
                    </div>
                    <div class="ml-4">
                        <p class="text-sm font-medium text-gray-600">Unique Visitors</p>
                        <p class="text-2xl font-bold text-gray-900"><?php echo $stats['today_unique_visitors']; ?></p>
                    </div>
                </div>
            </div>
            
            <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-200">
                <div class="flex items-center">
                    <div class="p-2 bg-red-100 rounded-lg">
                        <i class="fas fa-clock text-red-600"></i>
                    </div>
                    <div class="ml-4">
                        <p class="text-sm font-medium text-gray-600">Pending Pre-reg</p>
                        <p class="text-2xl font-bold text-gray-900"><?php echo $stats['pending_pre_reg']; ?></p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Activities -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-200">
            <div class="px-6 py-4 border-b border-gray-200">
                <div class="flex items-center justify-between">
                    <h3 class="text-lg font-semibold text-gray-900">Recent Activities</h3>
                    <a href="reports.php" class="text-blue-600 hover:text-blue-800 text-sm font-medium">
                        View All <i class="fas fa-arrow-right ml-1"></i>
                    </a>
                </div>
            </div>
            
            <div class="divide-y divide-gray-200">
                <?php if (empty($recent_activities)): ?>
                    <div class="px-6 py-8 text-center text-gray-500">
                        <i class="fas fa-inbox text-4xl mb-4 text-gray-300"></i>
                        <p>No recent activities found</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($recent_activities as $activity): ?>
                        <div class="px-6 py-4 hover:bg-gray-50">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center">
                                    <div class="flex-shrink-0">
                                        <div class="w-10 h-10 rounded-full flex items-center justify-center <?php 
                                            echo $activity['log_type'] === 'check_in' ? 'bg-green-100 text-green-600' : 'bg-red-100 text-red-600'; ?>">
                                            <i class="fas <?php echo $activity['log_type'] === 'check_in' ? 'fa-sign-in-alt' : 'fa-sign-out-alt'; ?>"></i>
                                        </div>
                                    </div>
                                    <div class="ml-4">
                                        <div class="flex items-center">
                                            <p class="text-sm font-medium text-gray-900">
                                                <?php echo htmlspecialchars($activity['full_name']); ?>
                                            </p>
                                            <span class="ml-2 px-2 py-1 text-xs font-medium rounded-full <?php 
                                                echo $activity['log_type'] === 'check_in' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                                                <?php echo ucfirst(str_replace('_', ' ', $activity['log_type'])); ?>
                                            </span>
                                        </div>
                                        <div class="mt-1 text-sm text-gray-500">
                                            <span><?php echo htmlspecialchars($activity['phone']); ?></span>
                                            <?php if ($activity['vehicle_number']): ?>
                                                <span class="ml-2">• Vehicle: <?php echo htmlspecialchars($activity['vehicle_number']); ?></span>
                                            <?php endif; ?>
                                            <span class="ml-2">• By: <?php echo htmlspecialchars($activity['operator_name']); ?></span>
                                        </div>
                                    </div>
                                </div>
                                <div class="text-sm text-gray-500">
                                    <?php echo date('M j, g:i A', strtotime($activity['log_timestamp'])); ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        // Auto-refresh page every 30 seconds
        setTimeout(function() {
            window.location.reload();
        }, 30000);
        
        // Show current time
        function updateTime() {
            const now = new Date();
            const timeString = now.toLocaleString('en-US', {
                weekday: 'short',
                year: 'numeric',
                month: 'short',
                day: 'numeric',
                hour: 'numeric',
                minute: '2-digit',
                hour12: true
            });
            // Update time display if element exists
            const timeElement = document.querySelector('span:has(i.fa-clock)');
            if (timeElement) {
                timeElement.innerHTML = '<i class="fas fa-clock mr-1"></i>' + timeString;
            }
        }
        
        // Update time every minute
        setInterval(updateTime, 60000);
    </script>
</body>
</html>