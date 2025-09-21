<?php
require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

$session = requireAuth($db);

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $visitor_id = sanitizeInput($_POST['visitor_id']);
    $action = sanitizeInput($_POST['action']); // 'check_in' or 'check_out'
    $purpose_of_visit = sanitizeInput($_POST['purpose_of_visit'] ?? '');
    $host_name = sanitizeInput($_POST['host_name'] ?? '');
    $host_department = sanitizeInput($_POST['host_department'] ?? '');
    $notes = sanitizeInput($_POST['notes'] ?? '');
    
    // Validate visitor exists
    $stmt = $db->prepare("SELECT * FROM visitors WHERE visitor_id = ? AND status = 'active'");
    $stmt->execute([$visitor_id]);
    $visitor = $stmt->fetch();
    
    if ($visitor && in_array($action, ['check_in', 'check_out'])) {
        // Record the activity
        $stmt = $db->prepare("INSERT INTO gate_logs (visitor_id, log_type, operator_id, purpose_of_visit, host_name, host_department, vehicle_number, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$visitor_id, $action, $session['id'], $purpose_of_visit, $host_name, $host_department, $visitor['vehicle_number'], $notes]);
        
        logActivity($db, $session['id'], 'manual_' . $action, "Manual $action for visitor: {$visitor['full_name']}", $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']);
        
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'message' => ucfirst(str_replace('_', ' ', $action)) . ' successful for ' . $visitor['full_name'],
            'visitor' => $visitor,
            'action' => $action
        ]);
    } else {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Invalid visitor or action']);
    }
    exit;
}

$settings = getSettings($db);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($settings['system_name'] ?? 'Gate Management System'); ?> - Quick Check-in</title>
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
                    <div class="h-10 w-10 bg-orange-600 rounded-lg flex items-center justify-center">
                        <i class="fas fa-tachometer-alt text-white"></i>
                    </div>
                    <div class="ml-3">
                        <h1 class="text-xl font-semibold text-gray-900">Quick Check-in/out</h1>
                        <p class="text-sm text-gray-500">Manual visitor processing</p>
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

    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <!-- Search and Process -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
            <!-- Visitor Search -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-6">Find Visitor</h3>
                
                <div class="mb-6">
                    <label for="visitor_search" class="block text-sm font-medium text-gray-700 mb-2">Search by Name, Phone, or ID</label>
                    <input type="text" id="visitor_search" placeholder="Start typing to search..." 
                           class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                </div>
                
                <div id="search_results" class="space-y-3 max-h-96 overflow-y-auto">
                    <div class="text-center text-gray-500 py-8">
                        <i class="fas fa-search text-3xl mb-2 text-gray-300"></i>
                        <p>Start typing to search for visitors</p>
                    </div>
                </div>
            </div>

            <!-- Action Form -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-6">Check-in/out Details</h3>
                
                <div id="selected_visitor" class="mb-6 p-4 bg-gray-50 rounded-lg hidden">
                    <h4 class="font-medium text-gray-900 mb-2">Selected Visitor:</h4>
                    <div id="visitor_info"></div>
                </div>
                
                <form id="checkinForm" class="space-y-4">
                    <input type="hidden" id="visitor_id" name="visitor_id">
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Action</label>
                        <div class="grid grid-cols-2 gap-4">
                            <button type="button" id="checkin_btn" class="action-btn p-3 border-2 border-green-300 text-green-700 rounded-lg hover:bg-green-50 transition-colors">
                                <i class="fas fa-sign-in-alt mr-2"></i>Check In
                            </button>
                            <button type="button" id="checkout_btn" class="action-btn p-3 border-2 border-red-300 text-red-700 rounded-lg hover:bg-red-50 transition-colors">
                                <i class="fas fa-sign-out-alt mr-2"></i>Check Out
                            </button>
                        </div>
                        <input type="hidden" id="action" name="action">
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
                    
                    <button type="submit" id="submit_btn" class="w-full bg-blue-600 hover:bg-blue-700 text-white px-4 py-3 rounded-lg font-medium transition-colors disabled:opacity-50 disabled:cursor-not-allowed" disabled>
                        <i class="fas fa-check mr-2"></i>Process Action
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Success Modal -->
    <div id="successModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden overflow-y-auto h-full w-full z-50">
        <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
            <div class="mt-3 text-center">
                <div class="mx-auto flex items-center justify-center h-12 w-12 rounded-full bg-green-100">
                    <i class="fas fa-check text-green-600 text-xl"></i>
                </div>
                <h3 class="text-lg font-medium text-gray-900 mt-2" id="success_title">Success!</h3>
                <div class="mt-2 px-7 py-3">
                    <p class="text-sm text-gray-500" id="success_message"></p>
                </div>
                <div class="items-center px-4 py-3">
                    <button onclick="closeModal()" class="px-4 py-2 bg-green-500 text-white text-base font-medium rounded-md w-full shadow-sm hover:bg-green-600 focus:outline-none focus:ring-2 focus:ring-green-300">
                        OK
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        let searchTimeout;
        let selectedAction = null;

        // Visitor search
        document.getElementById('visitor_search').addEventListener('input', function() {
            const query = this.value.trim();
            clearTimeout(searchTimeout);
            
            if (query.length < 2) {
                document.getElementById('search_results').innerHTML = `
                    <div class="text-center text-gray-500 py-8">
                        <i class="fas fa-search text-3xl mb-2 text-gray-300"></i>
                        <p>Start typing to search for visitors</p>
                    </div>
                `;
                return;
            }
            
            searchTimeout = setTimeout(() => {
                fetch(`ajax-visitor-search.php?q=${encodeURIComponent(query)}`)
                    .then(response => response.json())
                    .then(data => {
                        const resultsContainer = document.getElementById('search_results');
                        
                        if (data.visitors && data.visitors.length > 0) {
                            resultsContainer.innerHTML = data.visitors.map(visitor => `
                                <div class="visitor-result p-4 border rounded-lg cursor-pointer hover:bg-blue-50 hover:border-blue-300 transition-colors" 
                                     onclick="selectVisitor('${visitor.visitor_id}', '${visitor.full_name}', '${visitor.phone}', '${visitor.company || ''}', '${visitor.vehicle_number || ''}', '${visitor.current_status}')">
                                    <div class="flex justify-between items-start">
                                        <div>
                                            <h4 class="font-medium text-gray-900">${visitor.full_name}</h4>
                                            <p class="text-sm text-gray-600">${visitor.phone}</p>
                                            ${visitor.company ? `<p class="text-sm text-gray-500">${visitor.company}</p>` : ''}
                                        </div>
                                        <div class="text-right">
                                            <span class="px-2 py-1 text-xs rounded-full ${visitor.current_status === 'Inside' ? 'bg-blue-100 text-blue-800' : 'bg-gray-100 text-gray-800'}">
                                                ${visitor.current_status}
                                            </span>
                                            <p class="text-xs text-gray-500 mt-1">${visitor.last_activity}</p>
                                        </div>
                                    </div>
                                </div>
                            `).join('');
                        } else {
                            resultsContainer.innerHTML = `
                                <div class="text-center text-gray-500 py-8">
                                    <i class="fas fa-user-slash text-3xl mb-2 text-gray-300"></i>
                                    <p>No visitors found</p>
                                </div>
                            `;
                        }
                    })
                    .catch(error => {
                        console.error('Search error:', error);
                    });
            }, 300);
        });

        // Select visitor
        function selectVisitor(id, name, phone, company, vehicle, status) {
            document.getElementById('visitor_id').value = id;
            document.getElementById('selected_visitor').classList.remove('hidden');
            document.getElementById('visitor_info').innerHTML = `
                <div class="grid grid-cols-2 gap-4 text-sm">
                    <div><span class="font-medium">Name:</span> ${name}</div>
                    <div><span class="font-medium">Phone:</span> ${phone}</div>
                    ${company ? `<div><span class="font-medium">Company:</span> ${company}</div>` : ''}
                    ${vehicle ? `<div><span class="font-medium">Vehicle:</span> ${vehicle}</div>` : ''}
                    <div><span class="font-medium">Status:</span> 
                        <span class="px-2 py-1 text-xs rounded-full ${status === 'Inside' ? 'bg-blue-100 text-blue-800' : 'bg-gray-100 text-gray-800'}">
                            ${status}
                        </span>
                    </div>
                </div>
            `;
            
            // Clear previous action selection
            selectedAction = null;
            document.querySelectorAll('.action-btn').forEach(btn => {
                btn.classList.remove('bg-green-500', 'text-white', 'bg-red-500');
                btn.classList.add('border-2');
            });
            document.getElementById('submit_btn').disabled = true;
        }

        // Action selection
        document.getElementById('checkin_btn').addEventListener('click', function() {
            selectAction('check_in', this);
        });

        document.getElementById('checkout_btn').addEventListener('click', function() {
            selectAction('check_out', this);
        });

        function selectAction(action, button) {
            selectedAction = action;
            document.getElementById('action').value = action;
            
            // Reset all buttons
            document.querySelectorAll('.action-btn').forEach(btn => {
                btn.classList.remove('bg-green-500', 'text-white', 'bg-red-500');
                btn.classList.add('border-2');
            });
            
            // Highlight selected button
            if (action === 'check_in') {
                button.classList.remove('border-2', 'text-green-700', 'border-green-300');
                button.classList.add('bg-green-500', 'text-white');
            } else {
                button.classList.remove('border-2', 'text-red-700', 'border-red-300');
                button.classList.add('bg-red-500', 'text-white');
            }
            
            // Enable submit if visitor is selected
            if (document.getElementById('visitor_id').value) {
                document.getElementById('submit_btn').disabled = false;
            }
        }

        // Form submission
        document.getElementById('checkinForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            if (!selectedAction || !document.getElementById('visitor_id').value) {
                alert('Please select a visitor and action');
                return;
            }
            
            const formData = new FormData(this);
            const submitBtn = document.getElementById('submit_btn');
            
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Processing...';
            
            fetch('quick-checkin.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('success_title').textContent = 
                        data.action === 'check_in' ? 'Check-in Successful!' : 'Check-out Successful!';
                    document.getElementById('success_message').textContent = data.message;
                    document.getElementById('successModal').classList.remove('hidden');
                    
                    // Reset form
                    this.reset();
                    document.getElementById('selected_visitor').classList.add('hidden');
                    selectedAction = null;
                    document.querySelectorAll('.action-btn').forEach(btn => {
                        btn.classList.remove('bg-green-500', 'text-white', 'bg-red-500');
                        btn.classList.add('border-2');
                    });
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred. Please try again.');
            })
            .finally(() => {
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fas fa-check mr-2"></i>Process Action';
            });
        });

        function closeModal() {
            document.getElementById('successModal').classList.add('hidden');
        }

        // Auto-focus search
        document.getElementById('visitor_search').focus();
    </script>
</body>
</html>