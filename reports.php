<?php
require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

$session = requireAuth($db);
$settings = getSettings($db);

// Get filter parameters
$date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$report_type = $_GET['report_type'] ?? 'activity';

// Validate and sanitize dates
$date_from = date('Y-m-d', strtotime($date_from));
$date_to = date('Y-m-d', strtotime($date_to));

// Statistics for the date range
$stmt = $db->prepare("SELECT 
    COUNT(CASE WHEN log_type = 'check_in' THEN 1 END) as total_check_ins,
    COUNT(CASE WHEN log_type = 'check_out' THEN 1 END) as total_check_outs,
    COUNT(DISTINCT visitor_id) as unique_visitors,
    COUNT(DISTINCT CASE WHEN vehicle_number IS NOT NULL THEN vehicle_number END) as total_vehicles
FROM gate_logs 
WHERE DATE(log_timestamp) BETWEEN ? AND ?");
$stmt->execute([$date_from, $date_to]);
$stats = $stmt->fetch();

// Daily breakdown
$stmt = $db->prepare("SELECT 
    DATE(log_timestamp) as log_date,
    COUNT(CASE WHEN log_type = 'check_in' THEN 1 END) as check_ins,
    COUNT(CASE WHEN log_type = 'check_out' THEN 1 END) as check_outs,
    COUNT(DISTINCT visitor_id) as unique_visitors
FROM gate_logs 
WHERE DATE(log_timestamp) BETWEEN ? AND ?
GROUP BY DATE(log_timestamp)
ORDER BY log_date ASC");
$stmt->execute([$date_from, $date_to]);
$daily_stats = $stmt->fetchAll();

// Most frequent visitors
$stmt = $db->prepare("SELECT 
    v.visitor_id,
    v.full_name,
    v.phone,
    v.company,
    COUNT(gl.id) as visit_count,
    MAX(gl.log_timestamp) as last_visit
FROM visitors v
JOIN gate_logs gl ON v.visitor_id = gl.visitor_id
WHERE DATE(gl.log_timestamp) BETWEEN ? AND ?
GROUP BY v.visitor_id
ORDER BY visit_count DESC
LIMIT 10");
$stmt->execute([$date_from, $date_to]);
$frequent_visitors = $stmt->fetchAll();

// Activity logs based on report type
if ($report_type === 'activity') {
    $stmt = $db->prepare("SELECT 
        gl.*,
        v.full_name,
        v.phone,
        v.company,
        v.vehicle_number,
        go.operator_name
    FROM gate_logs gl
    JOIN visitors v ON gl.visitor_id = v.visitor_id
    JOIN gate_operators go ON gl.operator_id = go.id
    WHERE DATE(gl.log_timestamp) BETWEEN ? AND ?
    ORDER BY gl.log_timestamp DESC
    LIMIT 500");
    $stmt->execute([$date_from, $date_to]);
    $activities = $stmt->fetchAll();
} elseif ($report_type === 'visitors') {
    $stmt = $db->prepare("SELECT 
        v.*,
        COUNT(gl.id) as total_visits,
        MAX(CASE WHEN gl.log_type = 'check_in' THEN gl.log_timestamp END) as last_checkin,
        MAX(CASE WHEN gl.log_type = 'check_out' THEN gl.log_timestamp END) as last_checkout
    FROM visitors v
    LEFT JOIN gate_logs gl ON v.visitor_id = gl.visitor_id AND DATE(gl.log_timestamp) BETWEEN ? AND ?
    WHERE v.created_at >= ? OR gl.id IS NOT NULL
    GROUP BY v.visitor_id
    ORDER BY total_visits DESC, v.created_at DESC
    LIMIT 200");
    $stmt->execute([$date_from, $date_to, $date_from]);
    $visitors_report = $stmt->fetchAll();
}

$message = getMessage();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($settings['system_name'] ?? 'Gate Management System'); ?> - Reports</title>
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
                    <div class="h-10 w-10 bg-orange-600 rounded-lg flex items-center justify-center">
                        <i class="fas fa-chart-bar text-white"></i>
                    </div>
                    <div class="ml-3">
                        <h1 class="text-xl font-semibold text-gray-900">Reports & Analytics</h1>
                        <p class="text-sm text-gray-500">View activity logs and statistics</p>
                    </div>
                </div>
                
                <div class="flex items-center space-x-4">
                    <button onclick="exportReport()" class="text-green-600 hover:text-green-800">
                        <i class="fas fa-download"></i>
                        <span class="ml-1">Export</span>
                    </button>
                    <a href="dashboard.php" class="text-blue-600 hover:text-blue-800">
                        <i class="fas fa-home"></i>
                        <span class="ml-1">Dashboard</span>
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

        <!-- Filters -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mb-6">
            <form method="GET" class="flex flex-col lg:flex-row lg:items-end space-y-4 lg:space-y-0 lg:space-x-4">
                <div>
                    <label for="date_from" class="block text-sm font-medium text-gray-700 mb-1">From Date</label>
                    <input type="date" id="date_from" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>" 
                           class="px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-orange-500 focus:border-orange-500">
                </div>
                <div>
                    <label for="date_to" class="block text-sm font-medium text-gray-700 mb-1">To Date</label>
                    <input type="date" id="date_to" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>" 
                           class="px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-orange-500 focus:border-orange-500">
                </div>
                <div>
                    <label for="report_type" class="block text-sm font-medium text-gray-700 mb-1">Report Type</label>
                    <select id="report_type" name="report_type" 
                            class="px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-orange-500 focus:border-orange-500">
                        <option value="activity" <?php echo $report_type === 'activity' ? 'selected' : ''; ?>>Activity Log</option>
                        <option value="visitors" <?php echo $report_type === 'visitors' ? 'selected' : ''; ?>>Visitors Report</option>
                    </select>
                </div>
                <button type="submit" class="bg-orange-600 hover:bg-orange-700 text-white px-4 py-2 rounded-lg font-medium transition-colors">
                    <i class="fas fa-search mr-2"></i>Generate Report
                </button>
            </form>
        </div>

        <!-- Summary Statistics -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
            <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-200">
                <div class="flex items-center">
                    <div class="p-2 bg-blue-100 rounded-lg">
                        <i class="fas fa-sign-in-alt text-blue-600"></i>
                    </div>
                    <div class="ml-4">
                        <p class="text-sm font-medium text-gray-600">Total Check-ins</p>
                        <p class="text-2xl font-bold text-gray-900"><?php echo number_format($stats['total_check_ins']); ?></p>
                    </div>
                </div>
            </div>
            
            <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-200">
                <div class="flex items-center">
                    <div class="p-2 bg-green-100 rounded-lg">
                        <i class="fas fa-sign-out-alt text-green-600"></i>
                    </div>
                    <div class="ml-4">
                        <p class="text-sm font-medium text-gray-600">Total Check-outs</p>
                        <p class="text-2xl font-bold text-gray-900"><?php echo number_format($stats['total_check_outs']); ?></p>
                    </div>
                </div>
            </div>
            
            <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-200">
                <div class="flex items-center">
                    <div class="p-2 bg-purple-100 rounded-lg">
                        <i class="fas fa-users text-purple-600"></i>
                    </div>
                    <div class="ml-4">
                        <p class="text-sm font-medium text-gray-600">Unique Visitors</p>
                        <p class="text-2xl font-bold text-gray-900"><?php echo number_format($stats['unique_visitors']); ?></p>
                    </div>
                </div>
            </div>
            
            <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-200">
                <div class="flex items-center">
                    <div class="p-2 bg-yellow-100 rounded-lg">
                        <i class="fas fa-car text-yellow-600"></i>
                    </div>
                    <div class="ml-4">
                        <p class="text-sm font-medium text-gray-600">Total Vehicles</p>
                        <p class="text-2xl font-bold text-gray-900"><?php echo number_format($stats['total_vehicles']); ?></p>
                    </div>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8 mb-8">
            <!-- Daily Activity Chart -->
            <div class="lg:col-span-2 bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-4">Daily Activity Trend</h3>
                <canvas id="dailyChart" height="300"></canvas>
            </div>

            <!-- Top Visitors -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-4">Most Frequent Visitors</h3>
                <?php if (empty($frequent_visitors)): ?>
                    <div class="text-center py-8 text-gray-500">
                        <i class="fas fa-users text-3xl mb-3 text-gray-300"></i>
                        <p>No visitor data for this period</p>
                    </div>
                <?php else: ?>
                    <div class="space-y-3">
                        <?php foreach ($frequent_visitors as $index => $visitor): ?>
                            <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                                <div class="flex items-center">
                                    <div class="w-8 h-8 bg-orange-600 text-white rounded-full flex items-center justify-center text-sm font-medium">
                                        <?php echo $index + 1; ?>
                                    </div>
                                    <div class="ml-3">
                                        <p class="text-sm font-medium text-gray-900">
                                            <?php echo htmlspecialchars($visitor['full_name']); ?>
                                        </p>
                                        <p class="text-xs text-gray-500">
                                            <?php echo htmlspecialchars($visitor['company'] ?: $visitor['phone']); ?>
                                        </p>
                                    </div>
                                </div>
                                <div class="text-right">
                                    <p class="text-sm font-medium text-orange-600">
                                        <?php echo $visitor['visit_count']; ?> visits
                                    </p>
                                    <p class="text-xs text-gray-500">
                                        Last: <?php echo date('M j', strtotime($visitor['last_visit'])); ?>
                                    </p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Report Content -->
        <?php if ($report_type === 'activity'): ?>
            <!-- Activity Log -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-semibold text-gray-900">
                        Activity Log (<?php echo count($activities); ?> entries)
                    </h3>
                    <p class="text-sm text-gray-600">
                        From <?php echo date('M j, Y', strtotime($date_from)); ?> to <?php echo date('M j, Y', strtotime($date_to)); ?>
                    </p>
                </div>
                
                <?php if (empty($activities)): ?>
                    <div class="px-6 py-12 text-center text-gray-500">
                        <i class="fas fa-clipboard-list text-4xl mb-4 text-gray-300"></i>
                        <p class="text-lg mb-2">No activity found</p>
                        <p>No gate activities recorded for the selected date range</p>
                    </div>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Time</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Action</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Visitor</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Details</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Operator</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach ($activities as $activity): ?>
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm text-gray-900">
                                                <?php echo date('M j, Y', strtotime($activity['log_timestamp'])); ?>
                                            </div>
                                            <div class="text-sm text-gray-500">
                                                <?php echo date('g:i A', strtotime($activity['log_timestamp'])); ?>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <span class="px-2 py-1 text-xs font-medium rounded-full <?php 
                                                echo $activity['log_type'] === 'check_in' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                                                <?php echo ucfirst(str_replace('_', ' ', $activity['log_type'])); ?>
                                            </span>
                                        </td>
                                        <td class="px-6 py-4">
                                            <div class="text-sm font-medium text-gray-900">
                                                <?php echo htmlspecialchars($activity['full_name']); ?>
                                            </div>
                                            <div class="text-sm text-gray-500">
                                                <?php echo htmlspecialchars($activity['phone']); ?>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4">
                                            <?php if ($activity['company']): ?>
                                                <div class="text-sm text-gray-900">
                                                    <?php echo htmlspecialchars($activity['company']); ?>
                                                </div>
                                            <?php endif; ?>
                                            <?php if ($activity['vehicle_number']): ?>
                                                <div class="text-sm text-gray-500">
                                                    <i class="fas fa-car mr-1"></i><?php echo htmlspecialchars($activity['vehicle_number']); ?>
                                                </div>
                                            <?php endif; ?>
                                            <?php if ($activity['purpose_of_visit']): ?>
                                                <div class="text-sm text-gray-500">
                                                    Purpose: <?php echo htmlspecialchars($activity['purpose_of_visit']); ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                            <?php echo htmlspecialchars($activity['operator_name']); ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

        <?php elseif ($report_type === 'visitors'): ?>
            <!-- Visitors Report -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-semibold text-gray-900">
                        Visitors Report (<?php echo count($visitors_report); ?> visitors)
                    </h3>
                    <p class="text-sm text-gray-600">
                        From <?php echo date('M j, Y', strtotime($date_from)); ?> to <?php echo date('M j, Y', strtotime($date_to)); ?>
                    </p>
                </div>
                
                <?php if (empty($visitors_report)): ?>
                    <div class="px-6 py-12 text-center text-gray-500">
                        <i class="fas fa-users text-4xl mb-4 text-gray-300"></i>
                        <p class="text-lg mb-2">No visitors found</p>
                        <p>No visitors registered or visited during the selected period</p>
                    </div>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Visitor</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Contact</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Company/Vehicle</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Visits</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Last Activity</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach ($visitors_report as $visitor): ?>
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-6 py-4">
                                            <div class="text-sm font-medium text-gray-900">
                                                <?php echo htmlspecialchars($visitor['full_name']); ?>
                                            </div>
                                            <div class="text-sm text-gray-500">
                                                ID: <?php echo htmlspecialchars($visitor['visitor_id']); ?>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4">
                                            <div class="text-sm text-gray-900"><?php echo htmlspecialchars($visitor['phone']); ?></div>
                                            <?php if ($visitor['email']): ?>
                                                <div class="text-sm text-gray-500"><?php echo htmlspecialchars($visitor['email']); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4">
                                            <?php if ($visitor['company']): ?>
                                                <div class="text-sm text-gray-900"><?php echo htmlspecialchars($visitor['company']); ?></div>
                                            <?php endif; ?>
                                            <?php if ($visitor['vehicle_number']): ?>
                                                <div class="text-sm text-gray-500">
                                                    <i class="fas fa-car mr-1"></i><?php echo htmlspecialchars($visitor['vehicle_number']); ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm font-medium text-gray-900">
                                                <?php echo $visitor['total_visits'] ?? 0; ?> visits
                                            </div>
                                            <?php if ($visitor['last_checkin'] && $visitor['last_checkout']): ?>
                                                <?php if (strtotime($visitor['last_checkin']) > strtotime($visitor['last_checkout'])): ?>
                                                    <span class="text-xs bg-blue-100 text-blue-800 px-2 py-1 rounded-full">Currently Inside</span>
                                                <?php else: ?>
                                                    <span class="text-xs bg-gray-100 text-gray-800 px-2 py-1 rounded-full">Outside</span>
                                                <?php endif; ?>
                                            <?php elseif ($visitor['last_checkin']): ?>
                                                <span class="text-xs bg-blue-100 text-blue-800 px-2 py-1 rounded-full">Currently Inside</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <?php if ($visitor['last_checkin'] || $visitor['last_checkout']): ?>
                                                <?php 
                                                $last_activity = max($visitor['last_checkin'], $visitor['last_checkout']);
                                                echo date('M j, g:i A', strtotime($last_activity)); 
                                                ?>
                                            <?php else: ?>
                                                Never visited
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <span class="px-2 py-1 text-xs font-medium rounded-full <?php 
                                                echo $visitor['status'] === 'active' ? 'bg-green-100 text-green-800' : 
                                                    ($visitor['status'] === 'blocked' ? 'bg-red-100 text-red-800' : 'bg-gray-100 text-gray-800'); ?>">
                                                <?php echo ucfirst($visitor['status']); ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <script>
        // Daily Activity Chart
        const dailyData = <?php echo json_encode($daily_stats); ?>;
        
        const ctx = document.getElementById('dailyChart').getContext('2d');
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: dailyData.map(d => {
                    const date = new Date(d.log_date);
                    return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
                }),
                datasets: [
                    {
                        label: 'Check-ins',
                        data: dailyData.map(d => d.check_ins),
                        borderColor: '#10b981',
                        backgroundColor: 'rgba(16, 185, 129, 0.1)',
                        tension: 0.4
                    },
                    {
                        label: 'Check-outs',
                        data: dailyData.map(d => d.check_outs),
                        borderColor: '#ef4444',
                        backgroundColor: 'rgba(239, 68, 68, 0.1)',
                        tension: 0.4
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top',
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1
                        }
                    }
                }
            }
        });

        // Export functionality
        function exportReport() {
            const params = new URLSearchParams(window.location.search);
            params.set('export', 'csv');
            
            const exportUrl = 'export-report.php?' + params.toString();
            window.open(exportUrl, '_blank');
        }

        // Auto-refresh every 5 minutes
        setTimeout(function() {
            window.location.reload();
        }, 300000);

        // Date validation
        document.getElementById('date_from').addEventListener('change', function() {
            const dateTo = document.getElementById('date_to');
            if (this.value > dateTo.value && dateTo.value) {
                dateTo.value = this.value;
            }
        });

        document.getElementById('date_to').addEventListener('change', function() {
            const dateFrom = document.getElementById('date_from');
            if (this.value < dateFrom.value) {
                this.value = dateFrom.value;
            }
        });
    </script>
</body>
</html>