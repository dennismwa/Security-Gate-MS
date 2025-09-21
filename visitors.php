<?php
require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

$session = requireAuth($db);
$settings = getSettings($db);

$action = $_GET['action'] ?? 'list';

// Handle visitor registration
if ($_SERVER['REQUEST_METHOD'] == 'POST' && $action == 'register') {
    $full_name = sanitizeInput($_POST['full_name']);
    $phone = sanitizeInput($_POST['phone']);
    $email = sanitizeInput($_POST['email']);
    $id_number = sanitizeInput($_POST['id_number']);
    $company = sanitizeInput($_POST['company']);
    $vehicle_number = sanitizeInput($_POST['vehicle_number']);
    
    // Validation
    $errors = [];
    if (empty($full_name)) $errors[] = 'Full name is required';
    if (empty($phone)) $errors[] = 'Phone number is required';
    if (!validatePhone($phone)) $errors[] = 'Invalid phone number format';
    if (!empty($email) && !validateEmail($email)) $errors[] = 'Invalid email format';
    
    // Check if phone already exists
    $stmt = $db->prepare("SELECT visitor_id FROM visitors WHERE phone = ?");
    $stmt->execute([$phone]);
    if ($stmt->fetch()) {
        $errors[] = 'A visitor with this phone number already exists';
    }
    
    if (empty($errors)) {
        $visitor_id = generateUniqueId('VIS');
        $qr_code = generateQRCode($visitor_id . $phone);
        
        $stmt = $db->prepare("INSERT INTO visitors (visitor_id, full_name, phone, email, id_number, company, vehicle_number, qr_code) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        
        if ($stmt->execute([$visitor_id, $full_name, $phone, $email, $id_number, $company, $vehicle_number, $qr_code])) {
            logActivity($db, $session['id'], 'visitor_registration', "Registered new visitor: $full_name", $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']);
            setMessage("Visitor registered successfully! Visitor ID: $visitor_id", 'success');
            header('Location: visitors.php?action=view&id=' . $visitor_id);
            exit;
        } else {
            $errors[] = 'Failed to register visitor';
        }
    }
}

// Handle visitor update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && $action == 'update') {
    $visitor_id = sanitizeInput($_POST['visitor_id']);
    $full_name = sanitizeInput($_POST['full_name']);
    $phone = sanitizeInput($_POST['phone']);
    $email = sanitizeInput($_POST['email']);
    $id_number = sanitizeInput($_POST['id_number']);
    $company = sanitizeInput($_POST['company']);
    $vehicle_number = sanitizeInput($_POST['vehicle_number']);
    $status = sanitizeInput($_POST['status']);
    
    $stmt = $db->prepare("UPDATE visitors SET full_name = ?, phone = ?, email = ?, id_number = ?, company = ?, vehicle_number = ?, status = ? WHERE visitor_id = ?");
    
    if ($stmt->execute([$full_name, $phone, $email, $id_number, $company, $vehicle_number, $status, $visitor_id])) {
        logActivity($db, $session['id'], 'visitor_update', "Updated visitor: $full_name", $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']);
        setMessage('Visitor updated successfully', 'success');
        
    }
    if ($stmt->execute([$visitor_id, $full_name, $phone, $email, $id_number, $company, $vehicle_number, $qr_code])) {
    // Create notification
    createNotification($db, 'check_in', 'New Visitor Registered', "New visitor $full_name has been registered in the system");
    
    logActivity($db, $session['id'], 'visitor_registration', "Registered new visitor: $full_name", $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']);
    setMessage("Visitor registered successfully! Visitor ID: $visitor_id", 'success');
    header('Location: visitors.php?action=view&id=' . $visitor_id);
    exit;
}
else {
        setMessage('Failed to update visitor', 'error');
    }
    
    header('Location: visitors.php?action=view&id=' . $visitor_id);
    exit;
}

// Get visitor data for view/edit
if ($action == 'view' || $action == 'edit') {
    $visitor_id = $_GET['id'] ?? '';
    $stmt = $db->prepare("SELECT * FROM visitors WHERE visitor_id = ?");
    $stmt->execute([$visitor_id]);
    $visitor = $stmt->fetch();
    
    if (!$visitor) {
        setMessage('Visitor not found', 'error');
        header('Location: visitors.php');
        exit;
    }
    
    // Get visitor logs
    $stmt = $db->prepare("SELECT gl.*, go.operator_name FROM gate_logs gl JOIN gate_operators go ON gl.operator_id = go.id WHERE gl.visitor_id = ? ORDER BY gl.log_timestamp DESC LIMIT 20");
    $stmt->execute([$visitor_id]);
    $visitor_logs = $stmt->fetchAll();
}

// Get visitors list
if ($action == 'list') {
    $search = $_GET['search'] ?? '';
    $status_filter = $_GET['status'] ?? '';
    $page = max(1, intval($_GET['page'] ?? 1));
    $per_page = 20;
    $offset = ($page - 1) * $per_page;
    
    $where_conditions = ['1=1'];
    $params = [];
    
    if (!empty($search)) {
        $where_conditions[] = "(full_name LIKE ? OR phone LIKE ? OR company LIKE ? OR vehicle_number LIKE ? OR visitor_id LIKE ?)";
        $search_param = "%$search%";
        $params = array_merge($params, [$search_param, $search_param, $search_param, $search_param, $search_param]);
    }
    
    if (!empty($status_filter)) {
        $where_conditions[] = "status = ?";
        $params[] = $status_filter;
    }
    
    $where_clause = implode(' AND ', $where_conditions);
    
    // Get total count
    $stmt = $db->prepare("SELECT COUNT(*) as total FROM visitors WHERE $where_clause");
    $stmt->execute($params);
    $total_visitors = $stmt->fetch()['total'];
    $total_pages = ceil($total_visitors / $per_page);
    
    // Get visitors
    $stmt = $db->prepare("SELECT v.*, 
                         (SELECT log_type FROM gate_logs gl WHERE gl.visitor_id = v.visitor_id ORDER BY log_timestamp DESC LIMIT 1) as last_action,
                         (SELECT log_timestamp FROM gate_logs gl WHERE gl.visitor_id = v.visitor_id ORDER BY log_timestamp DESC LIMIT 1) as last_activity
                         FROM visitors v 
                         WHERE $where_clause 
                         ORDER BY v.created_at DESC 
                         LIMIT $per_page OFFSET $offset");
    $stmt->execute($params);
    $visitors = $stmt->fetchAll();
}

$message = getMessage();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($settings['system_name'] ?? 'Gate Management System'); ?> - Visitor Management</title>
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
                    <div class="h-10 w-10 bg-green-600 rounded-lg flex items-center justify-center">
                        <i class="fas fa-users text-white"></i>
                    </div>
                    <div class="ml-3">
                        <h1 class="text-xl font-semibold text-gray-900">Visitor Management</h1>
                        <p class="text-sm text-gray-500">Register and manage visitors</p>
                    </div>
                </div>
                
                <div class="flex items-center space-x-4">
                    <a href="scanner.php" class="text-blue-600 hover:text-blue-800">
                        <i class="fas fa-qrcode"></i>
                        <span class="ml-1">Scanner</span>
                    </a>
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

        <?php if ($action == 'list'): ?>
            <!-- Search and Filter Bar -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mb-6">
                <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between space-y-4 lg:space-y-0">
                    <form method="GET" class="flex flex-col sm:flex-row space-y-2 sm:space-y-0 sm:space-x-4">
                        <input type="hidden" name="action" value="list">
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <i class="fas fa-search text-gray-400"></i>
                            </div>
                            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" 
                                   class="pl-10 pr-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 w-full sm:w-64" 
                                   placeholder="Search visitors...">
                        </div>
                        <select name="status" class="px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                            <option value="">All Status</option>
                            <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                            <option value="blocked" <?php echo $status_filter === 'blocked' ? 'selected' : ''; ?>>Blocked</option>
                        </select>
                        <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg font-medium transition-colors">
                            <i class="fas fa-search mr-2"></i>Search
                        </button>
                    </form>
                    
                    <a href="visitors.php?action=register" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg font-medium transition-colors inline-flex items-center">
                        <i class="fas fa-user-plus mr-2"></i>Register New Visitor
                    </a>
                </div>
            </div>

            <!-- Visitors Table -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-semibold text-gray-900">
                        Visitors List (<?php echo number_format($total_visitors); ?> total)
                    </h3>
                </div>
                
                <?php if (empty($visitors)): ?>
                    <div class="px-6 py-12 text-center text-gray-500">
                        <i class="fas fa-users text-4xl mb-4 text-gray-300"></i>
                        <p class="text-lg mb-2">No visitors found</p>
                        <p>Start by registering your first visitor</p>
                        <a href="visitors.php?action=register" class="mt-4 inline-flex items-center bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg font-medium transition-colors">
                            <i class="fas fa-user-plus mr-2"></i>Register Visitor
                        </a>
                    </div>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Visitor</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Contact</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Company/Vehicle</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Last Activity</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach ($visitors as $visitor): ?>
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div>
                                                <div class="text-sm font-medium text-gray-900">
                                                    <?php echo htmlspecialchars($visitor['full_name']); ?>
                                                </div>
                                                <div class="text-sm text-gray-500">
                                                    ID: <?php echo htmlspecialchars($visitor['visitor_id']); ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm text-gray-900"><?php echo htmlspecialchars($visitor['phone']); ?></div>
                                            <?php if ($visitor['email']): ?>
                                                <div class="text-sm text-gray-500"><?php echo htmlspecialchars($visitor['email']); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
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
                                            <span class="px-2 py-1 text-xs font-medium rounded-full <?php 
                                                echo $visitor['status'] === 'active' ? 'bg-green-100 text-green-800' : 
                                                    ($visitor['status'] === 'blocked' ? 'bg-red-100 text-red-800' : 'bg-gray-100 text-gray-800'); ?>">
                                                <?php echo ucfirst($visitor['status']); ?>
                                            </span>
                                            <?php if ($visitor['last_action']): ?>
                                                <div class="mt-1">
                                                    <span class="px-2 py-1 text-xs font-medium rounded-full <?php 
                                                        echo $visitor['last_action'] === 'check_in' ? 'bg-blue-100 text-blue-800' : 'bg-gray-100 text-gray-800'; ?>">
                                                        <?php echo $visitor['last_action'] === 'check_in' ? 'Inside' : 'Outside'; ?>
                                                    </span>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <?php if ($visitor['last_activity']): ?>
                                                <?php echo date('M j, g:i A', strtotime($visitor['last_activity'])); ?>
                                            <?php else: ?>
                                                Never visited
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                            <div class="flex space-x-2">
                                                <a href="visitors.php?action=view&id=<?php echo $visitor['visitor_id']; ?>" 
                                                   class="text-blue-600 hover:text-blue-900">
                                                    <i class="fas fa-eye mr-1"></i>View
                                                </a>
                                                <a href="visitors.php?action=edit&id=<?php echo $visitor['visitor_id']; ?>" 
                                                   class="text-green-600 hover:text-green-900">
                                                    <i class="fas fa-edit mr-1"></i>Edit
                                                </a>
                                                <a href="print-card.php?id=<?php echo $visitor['visitor_id']; ?>" 
                                                   class="text-purple-600 hover:text-purple-900" target="_blank">
                                                    <i class="fas fa-print mr-1"></i>Print
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                        <div class="px-6 py-4 border-t border-gray-200">
                            <div class="flex items-center justify-between">
                                <div class="text-sm text-gray-700">
                                    Showing <?php echo (($page - 1) * $per_page) + 1; ?> to <?php echo min($page * $per_page, $total_visitors); ?> of <?php echo $total_visitors; ?> results
                                </div>
                                <div class="flex space-x-2">
                                    <?php if ($page > 1): ?>
                                        <a href="?action=list&page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>" 
                                           class="px-3 py-2 border border-gray-300 rounded-lg text-sm text-gray-600 hover:bg-gray-50">Previous</a>
                                    <?php endif; ?>
                                    
                                    <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                                        <a href="?action=list&page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>" 
                                           class="px-3 py-2 border rounded-lg text-sm <?php echo $i === $page ? 'bg-blue-600 text-white border-blue-600' : 'border-gray-300 text-gray-600 hover:bg-gray-50'; ?>">
                                            <?php echo $i; ?>
                                        </a>
                                    <?php endfor; ?>
                                    
                                    <?php if ($page < $total_pages): ?>
                                        <a href="?action=list&page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>" 
                                           class="px-3 py-2 border border-gray-300 rounded-lg text-sm text-gray-600 hover:bg-gray-50">Next</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

        <?php elseif ($action == 'register'): ?>
            <!-- Registration Form -->
            <div class="max-w-2xl mx-auto">
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                    <div class="mb-6">
                        <h3 class="text-lg font-semibold text-gray-900">Register New Visitor</h3>
                        <p class="text-sm text-gray-600">Fill in the visitor's information to register them in the system</p>
                    </div>
                    
                    <?php if (isset($errors) && !empty($errors)): ?>
                        <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-lg">
                            <div class="flex items-center mb-2">
                                <i class="fas fa-exclamation-circle text-red-600 mr-2"></i>
                                <span class="font-medium text-red-700">Please correct the following errors:</span>
                            </div>
                            <ul class="list-disc list-inside text-red-600 text-sm">
                                <?php foreach ($errors as $error): ?>
                                    <li><?php echo htmlspecialchars($error); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                    
                    <form method="POST" class="space-y-6">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label for="full_name" class="block text-sm font-medium text-gray-700">Full Name *</label>
                                <input type="text" id="full_name" name="full_name" required 
                                       value="<?php echo htmlspecialchars($_POST['full_name'] ?? ''); ?>"
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500" 
                                       placeholder="Enter full name">
                            </div>
                            
                            <div>
                                <label for="phone" class="block text-sm font-medium text-gray-700">Phone Number *</label>
                                <input type="tel" id="phone" name="phone" required 
                                       value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>"
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500" 
                                       placeholder="+1234567890">
                            </div>
                            
                            <div>
                                <label for="email" class="block text-sm font-medium text-gray-700">Email</label>
                                <input type="email" id="email" name="email" 
                                       value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500" 
                                       placeholder="email@example.com">
                            </div>
                            
                            <div>
                                <label for="id_number" class="block text-sm font-medium text-gray-700">ID Number</label>
                                <input type="text" id="id_number" name="id_number" 
                                       value="<?php echo htmlspecialchars($_POST['id_number'] ?? ''); ?>"
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500" 
                                       placeholder="Government ID or passport number">
                            </div>
                            
                            <div>
                                <label for="company" class="block text-sm font-medium text-gray-700">Company</label>
                                <input type="text" id="company" name="company" 
                                       value="<?php echo htmlspecialchars($_POST['company'] ?? ''); ?>"
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500" 
                                       placeholder="Company or organization">
                            </div>
                            
                            <div>
                                <label for="vehicle_number" class="block text-sm font-medium text-gray-700">Vehicle Number</label>
                                <input type="text" id="vehicle_number" name="vehicle_number" 
                                       value="<?php echo htmlspecialchars($_POST['vehicle_number'] ?? ''); ?>"
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500" 
                                       placeholder="Vehicle registration number">
                            </div>
                        </div>
                        
                        <div class="flex justify-end space-x-4">
                            <a href="visitors.php" class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition-colors">
                                Cancel
                            </a>
                            <button type="submit" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg font-medium transition-colors">
                                <i class="fas fa-user-plus mr-2"></i>Register Visitor
                            </button>
                        </div>
                    </form>
                </div>
            </div>

        <?php elseif ($action == 'view' && isset($visitor)): ?>
            <!-- Visitor Details View -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                <div class="lg:col-span-2">
                    <!-- Visitor Information -->
                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mb-6">
                        <div class="flex items-center justify-between mb-6">
                            <h3 class="text-lg font-semibold text-gray-900">Visitor Information</h3>
                            <div class="flex space-x-2">
                                <a href="visitors.php?action=edit&id=<?php echo $visitor['visitor_id']; ?>" 
                                   class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-2 rounded-lg text-sm font-medium transition-colors">
                                    <i class="fas fa-edit mr-1"></i>Edit
                                </a>
                                <a href="print-card.php?id=<?php echo $visitor['visitor_id']; ?>" 
                                   class="bg-purple-600 hover:bg-purple-700 text-white px-3 py-2 rounded-lg text-sm font-medium transition-colors" target="_blank">
                                    <i class="fas fa-print mr-1"></i>Print Card
                                </a>
                            </div>
                        </div>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label class="block text-sm font-medium text-gray-600">Email</label>
                                <p class="text-gray-900"><?php echo htmlspecialchars($visitor['email'] ?: 'Not provided'); ?></p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-600">ID Number</label>
                                <p class="text-gray-900"><?php echo htmlspecialchars($visitor['id_number'] ?: 'Not provided'); ?></p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-600">Company</label>
                                <p class="text-gray-900"><?php echo htmlspecialchars($visitor['company'] ?: 'Not provided'); ?></p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-600">Vehicle Number</label>
                                <p class="text-gray-900"><?php echo htmlspecialchars($visitor['vehicle_number'] ?: 'Not provided'); ?></p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-600">Status</label>
                                <span class="px-2 py-1 text-xs font-medium rounded-full <?php 
                                    echo $visitor['status'] === 'active' ? 'bg-green-100 text-green-800' : 
                                        ($visitor['status'] === 'blocked' ? 'bg-red-100 text-red-800' : 'bg-gray-100 text-gray-800'); ?>">
                                    <?php echo ucfirst($visitor['status']); ?>
                                </span>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-600">Registered</label>
                                <p class="text-gray-900"><?php echo date('M j, Y g:i A', strtotime($visitor['created_at'])); ?></p>
                            </div>
                        </div>
                    </div>
                    <!-- Add this in the visitor view section, after the visitor information div -->
<?php if ($action == 'view' && isset($visitor)): ?>
    <!-- Add Photo Upload Section -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mb-6">
        <h3 class="text-lg font-semibold text-gray-900 mb-4">Visitor Photo</h3>
        
        <div class="flex items-start space-x-6">
            <!-- Current Photo Display -->
            <div class="w-32 h-40 border-2 border-gray-300 rounded-lg overflow-hidden bg-gray-50 flex items-center justify-center">
                <?php if ($visitor['photo_path'] && file_exists($visitor['photo_path'])): ?>
                    <img id="current-photo" src="<?php echo htmlspecialchars($visitor['photo_path']); ?>" 
                         alt="Visitor Photo" class="w-full h-full object-cover">
                <?php else: ?>
                    <div id="photo-placeholder" class="text-center text-gray-400">
                        <i class="fas fa-user text-3xl mb-2"></i>
                        <p class="text-xs">No Photo</p>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Upload Form -->
            <div class="flex-1">
                <form id="photo-upload-form" enctype="multipart/form-data" class="space-y-4">
                    <input type="hidden" name="visitor_id" value="<?php echo $visitor['visitor_id']; ?>">
                    
                    <div>
                        <label for="photo" class="block text-sm font-medium text-gray-700 mb-2">
                            Upload New Photo
                        </label>
                        <input type="file" id="photo" name="photo" accept="image/*" 
                               class="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100">
                        <p class="text-xs text-gray-500 mt-1">JPG, PNG, or GIF. Max size: 5MB</p>
                    </div>
                    
                    <button type="submit" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg font-medium transition-colors">
                        <i class="fas fa-upload mr-2"></i>Upload Photo
                    </button>
                </form>
                
                <div id="upload-status" class="mt-4 hidden"></div>
            </div>
        </div>
    </div>
<?php endif; ?>

                    <!-- Activity History -->
                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                        <h3 class="text-lg font-semibold text-gray-900 mb-6">Recent Activity</h3>
                        
                        <?php if (empty($visitor_logs)): ?>
                            <div class="text-center py-8 text-gray-500">
                                <i class="fas fa-history text-4xl mb-4 text-gray-300"></i>
                                <p>No activity recorded yet</p>
                            </div>
                        <?php else: ?>
                            <div class="space-y-4">
                                <?php foreach ($visitor_logs as $log): ?>
                                    <div class="flex items-center p-4 bg-gray-50 rounded-lg">
                                        <div class="flex-shrink-0">
                                            <div class="w-10 h-10 rounded-full flex items-center justify-center <?php 
                                                echo $log['log_type'] === 'check_in' ? 'bg-green-100 text-green-600' : 'bg-red-100 text-red-600'; ?>">
                                                <i class="fas <?php echo $log['log_type'] === 'check_in' ? 'fa-sign-in-alt' : 'fa-sign-out-alt'; ?>"></i>
                                            </div>
                                        </div>
                                        <div class="ml-4 flex-1">
                                            <div class="flex items-center justify-between">
                                                <div>
                                                    <p class="text-sm font-medium text-gray-900">
                                                        <?php echo ucfirst(str_replace('_', ' ', $log['log_type'])); ?>
                                                    </p>
                                                    <p class="text-sm text-gray-500">
                                                        By: <?php echo htmlspecialchars($log['operator_name']); ?>
                                                        <?php if ($log['purpose_of_visit']): ?>
                                                            • Purpose: <?php echo htmlspecialchars($log['purpose_of_visit']); ?>
                                                        <?php endif; ?>
                                                    </p>
                                                    <?php if ($log['host_name']): ?>
                                                        <p class="text-sm text-gray-500">
                                                            Host: <?php echo htmlspecialchars($log['host_name']); ?>
                                                            <?php if ($log['host_department']): ?>
                                                                (<?php echo htmlspecialchars($log['host_department']); ?>)
                                                            <?php endif; ?>
                                                        </p>
                                                    <?php endif; ?>
                                                    <?php if ($log['notes']): ?>
                                                        <p class="text-sm text-gray-500">
                                                            Notes: <?php echo htmlspecialchars($log['notes']); ?>
                                                        </p>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="text-sm text-gray-500">
                                                    <?php echo date('M j, g:i A', strtotime($log['log_timestamp'])); ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- QR Code & Quick Actions -->
                <div class="space-y-6">
                    <!-- QR Code -->
                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 text-center">
                        <h3 class="text-lg font-semibold text-gray-900 mb-4">QR Code</h3>
                        <div class="bg-gray-100 p-4 rounded-lg mb-4">
                            <div id="qrcode" class="flex justify-center"></div>
                        </div>
                        <p class="text-sm text-gray-600 mb-4">Scan this QR code for quick check-in/check-out</p>
                        <button onclick="downloadQRCode()" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors">
                            <i class="fas fa-download mr-2"></i>Download QR
                        </button>
                    </div>

                    <!-- Quick Actions -->
                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                        <h3 class="text-lg font-semibold text-gray-900 mb-4">Quick Actions</h3>
                        <div class="space-y-3">
                            <a href="scanner.php" class="block w-full bg-green-600 hover:bg-green-700 text-white px-4 py-3 rounded-lg text-center font-medium transition-colors">
                                <i class="fas fa-qrcode mr-2"></i>Open Scanner
                            </a>
                            <a href="print-card.php?id=<?php echo $visitor['visitor_id']; ?>" target="_blank" 
                               class="block w-full bg-purple-600 hover:bg-purple-700 text-white px-4 py-3 rounded-lg text-center font-medium transition-colors">
                                <i class="fas fa-print mr-2"></i>Print ID Card
                            </a>
                            <a href="visitors.php?action=edit&id=<?php echo $visitor['visitor_id']; ?>" 
                               class="block w-full bg-blue-600 hover:bg-blue-700 text-white px-4 py-3 rounded-lg text-center font-medium transition-colors">
                                <i class="fas fa-edit mr-2"></i>Edit Details
                            </a>
                            <button onclick="quickAction('check_in')" id="checkin-btn" 
                                    class="block w-full bg-green-500 hover:bg-green-600 text-white px-4 py-3 rounded-lg text-center font-medium transition-colors">
                                <i class="fas fa-sign-in-alt mr-2"></i>Quick Check-in
                            </button>
                            <button onclick="quickAction('check_out')" id="checkout-btn" 
                                    class="block w-full bg-red-500 hover:bg-red-600 text-white px-4 py-3 rounded-lg text-center font-medium transition-colors">
                                <i class="fas fa-sign-out-alt mr-2"></i>Quick Check-out
                            </button>
                        </div>
                    </div>
                </div>
            </div>

        <?php elseif ($action == 'edit' && isset($visitor)): ?>
            <!-- Edit Form -->
            <div class="max-w-2xl mx-auto">
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                    <div class="mb-6">
                        <h3 class="text-lg font-semibold text-gray-900">Edit Visitor</h3>
                        <p class="text-sm text-gray-600">Update visitor information</p>
                    </div>
                    
                    <form method="POST" class="space-y-6">
                        <input type="hidden" name="visitor_id" value="<?php echo htmlspecialchars($visitor['visitor_id']); ?>">
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label for="full_name" class="block text-sm font-medium text-gray-700">Full Name *</label>
                                <input type="text" id="full_name" name="full_name" required 
                                       value="<?php echo htmlspecialchars($visitor['full_name']); ?>"
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                            </div>
                            
                            <div>
                                <label for="phone" class="block text-sm font-medium text-gray-700">Phone Number *</label>
                                <input type="tel" id="phone" name="phone" required 
                                       value="<?php echo htmlspecialchars($visitor['phone']); ?>"
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                            </div>
                            
                            <div>
                                <label for="email" class="block text-sm font-medium text-gray-700">Email</label>
                                <input type="email" id="email" name="email" 
                                       value="<?php echo htmlspecialchars($visitor['email']); ?>"
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                            </div>
                            
                            <div>
                                <label for="id_number" class="block text-sm font-medium text-gray-700">ID Number</label>
                                <input type="text" id="id_number" name="id_number" 
                                       value="<?php echo htmlspecialchars($visitor['id_number']); ?>"
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                            </div>
                            
                            <div>
                                <label for="company" class="block text-sm font-medium text-gray-700">Company</label>
                                <input type="text" id="company" name="company" 
                                       value="<?php echo htmlspecialchars($visitor['company']); ?>"
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                            </div>
                            
                            <div>
                                <label for="vehicle_number" class="block text-sm font-medium text-gray-700">Vehicle Number</label>
                                <input type="text" id="vehicle_number" name="vehicle_number" 
                                       value="<?php echo htmlspecialchars($visitor['vehicle_number']); ?>"
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                            </div>
                            
                            <div class="md:col-span-2">
                                <label for="status" class="block text-sm font-medium text-gray-700">Status</label>
                                <select id="status" name="status" 
                                        class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                    <option value="active" <?php echo $visitor['status'] === 'active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="inactive" <?php echo $visitor['status'] === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                    <option value="blocked" <?php echo $visitor['status'] === 'blocked' ? 'selected' : ''; ?>>Blocked</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="flex justify-end space-x-4">
                            <a href="visitors.php?action=view&id=<?php echo $visitor['visitor_id']; ?>" 
                               class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition-colors">
                                Cancel
                            </a>
                            <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg font-medium transition-colors">
                                <i class="fas fa-save mr-2"></i>Update Visitor
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- QR Code Library -->
    <script src="https://cdn.jsdelivr.net/npm/qrcode@1.5.3/build/qrcode.min.js"></script>
    
    <script>
        <?php if ($action == 'view' && isset($visitor)): ?>
        // Generate QR Code
        document.addEventListener('DOMContentLoaded', function() {
            const qrCodeElement = document.getElementById('qrcode');
            if (qrCodeElement) {
                QRCode.toCanvas(qrCodeElement, '<?php echo htmlspecialchars($visitor['qr_code']); ?>', {
                    width: 200,
                    height: 200,
                    colorDark: '#000000',
                    colorLight: '#ffffff',
                    correctLevel: QRCode.CorrectLevel.M
                }, function (error) {
                    if (error) console.error(error);
                });
            }
        });

        function downloadQRCode() {
            const canvas = document.querySelector('#qrcode canvas');
            if (canvas) {
                const link = document.createElement('a');
                link.download = 'visitor-qr-<?php echo $visitor['visitor_id']; ?>.png';
                link.href = canvas.toDataURL();
                link.click();
            }
        }

        // Quick action functions
        function quickAction(action) {
            const button = document.getElementById(action === 'check_in' ? 'checkin-btn' : 'checkout-btn');
            const originalText = button.innerHTML;
            
            button.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Processing...';
            button.disabled = true;
            
            const formData = new FormData();
            formData.append('qr_data', '<?php echo $visitor['qr_code']; ?>');
            formData.append('action', action);
            
            fetch('process-scan.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert(data.message);
                    // Refresh the page to show updated activity
                    window.location.reload();
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred. Please try again.');
            })
            .finally(() => {
                button.innerHTML = originalText;
                button.disabled = false;
            });
        }
        <?php endif; ?>

        // Form validation
        document.addEventListener('DOMContentLoaded', function() {
            const forms = document.querySelectorAll('form');
            forms.forEach(form => {
                form.addEventListener('submit', function(e) {
                    const button = this.querySelector('button[type="submit"]');
                    if (button) {
                        const originalText = button.innerHTML;
                        button.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Processing...';
                        button.disabled = true;
                        
                        // Re-enable button after 5 seconds if form doesn't submit
                        setTimeout(() => {
                            button.innerHTML = originalText;
                            button.disabled = false;
                        }, 5000);
                    }
                });
            });
        });

        // Phone number formatting
        document.addEventListener('DOMContentLoaded', function() {
            const phoneInputs = document.querySelectorAll('input[type="tel"]');
            phoneInputs.forEach(input => {
                input.addEventListener('input', function() {
                    // Remove non-numeric characters except + and spaces
                    this.value = this.value.replace(/[^\d+\s-()]/g, '');
                });
            });
        });

        // Auto-refresh for list view
        <?php if ($action == 'list'): ?>
        setInterval(function() {
            // Only refresh if no form is being filled out
            const activeElement = document.activeElement;
            if (!activeElement || activeElement.tagName !== 'INPUT') {
                window.location.reload();
            }
        }, 60000); // Refresh every minute
        <?php endif; ?>
    </script>
    <script>
// Photo upload functionality
document.getElementById('photo-upload-form').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    const fileInput = document.getElementById('photo');
    const statusDiv = document.getElementById('upload-status');
    const submitBtn = this.querySelector('button[type="submit"]');
    
    if (!fileInput.files[0]) {
        showStatus('Please select a photo to upload', 'error');
        return;
    }
    
    // Show loading state
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Uploading...';
    submitBtn.disabled = true;
    
    fetch('upload-photo.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showStatus(data.message, 'success');
            
            // Update photo display
            const currentPhoto = document.getElementById('current-photo');
            const placeholder = document.getElementById('photo-placeholder');
            
            if (currentPhoto) {
                currentPhoto.src = data.photo_path + '?t=' + Date.now(); // Add timestamp to force reload
            } else if (placeholder) {
                placeholder.parentElement.innerHTML = `<img id="current-photo" src="${data.photo_path}" alt="Visitor Photo" class="w-full h-full object-cover">`;
            }
            
            // Clear file input
            fileInput.value = '';
        } else {
            showStatus(data.message, 'error');
        }
    })
    .catch(error => {
        console.error('Upload error:', error);
        showStatus('Upload failed. Please try again.', 'error');
    })
    .finally(() => {
        // Reset button
        submitBtn.innerHTML = '<i class="fas fa-upload mr-2"></i>Upload Photo';
        submitBtn.disabled = false;
    });
});

function showStatus(message, type) {
    const statusDiv = document.getElementById('upload-status');
    statusDiv.className = `mt-4 p-3 rounded-lg ${type === 'success' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'}`;
    statusDiv.innerHTML = `<i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'} mr-2"></i>${message}`;
    statusDiv.classList.remove('hidden');
    
    // Hide status after 5 seconds
    setTimeout(() => {
        statusDiv.classList.add('hidden');
    }, 5000);
}
</script>
</body>
</html>