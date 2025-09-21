<?php
require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

$session = requireAuth($db);
$settings = getSettings($db);

// Only admin can access settings
if ($session['role'] !== 'admin') {
    setMessage('Access denied. Admin privileges required.', 'error');
    header('Location: dashboard.php');
    exit;
}

// Handle settings update
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $updates = [
        'system_name' => sanitizeInput($_POST['system_name']),
        'primary_color' => sanitizeInput($_POST['primary_color']),
        'secondary_color' => sanitizeInput($_POST['secondary_color']),
        'accent_color' => sanitizeInput($_POST['accent_color']),
        'email_notifications' => isset($_POST['email_notifications']) ? 'true' : 'false',
        'smtp_host' => sanitizeInput($_POST['smtp_host']),
        'smtp_port' => sanitizeInput($_POST['smtp_port']),
        'smtp_username' => sanitizeInput($_POST['smtp_username']),
        'session_timeout' => intval($_POST['session_timeout'])
    ];
    
    // Only update SMTP password if provided
    if (!empty($_POST['smtp_password'])) {
        $updates['smtp_password'] = sanitizeInput($_POST['smtp_password']);
    }
    
    $success = true;
    foreach ($updates as $key => $value) {
        if (!updateSetting($db, $key, $value)) {
            $success = false;
            break;
        }
    }
    
    if ($success) {
        logActivity($db, $session['operator_id'], 'settings_update', 'Updated system settings', $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']);
        setMessage('Settings updated successfully', 'success');
    } else {
        setMessage('Failed to update some settings', 'error');
    }
    
    header('Location: settings.php');
    exit;
}

$message = getMessage();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($settings['system_name'] ?? 'Gate Management System'); ?> - Settings</title>
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
                    <div class="h-10 w-10 bg-gray-600 rounded-lg flex items-center justify-center">
                        <i class="fas fa-cog text-white"></i>
                    </div>
                    <div class="ml-3">
                        <h1 class="text-xl font-semibold text-gray-900">System Settings</h1>
                        <p class="text-sm text-gray-500">Configure system preferences</p>
                    </div>
                </div>
                
                
                    
                    <div class="flex items-center space-x-4">
    <a href="system-health.php" class="text-blue-600 hover:text-blue-800">
        <i class="fas fa-heartbeat"></i>
        <span class="ml-1">Health</span>
    </a>
    <a href="backup-system.php" class="text-green-600 hover:text-green-800">
        <i class="fas fa-database"></i>
        <span class="ml-1">Backup</span>
    </a>
    <a href="manage-departments.php" class="text-purple-600 hover:text-purple-800">
        <i class="fas fa-building"></i>
        <span class="ml-1">Departments</span>
    </a>
    <a href="operators.php" class="text-blue-600 hover:text-blue-800">
        <i class="fas fa-users-cog"></i>
        <span class="ml-1">Operators</span>
    </a>
    <a href="dashboard.php" class="text-blue-600 hover:text-blue-800">
        <i class="fas fa-home"></i>
        <span class="ml-1">Dashboard</span>
    </a>
</div>
                    
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

        <form method="POST" class="space-y-8">
            <!-- General Settings -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-6">General Settings</h3>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="md:col-span-2">
                        <label for="system_name" class="block text-sm font-medium text-gray-700">System Name</label>
                        <input type="text" id="system_name" name="system_name" 
                               value="<?php echo htmlspecialchars($settings['system_name'] ?? 'Gate Management System'); ?>"
                               class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500" 
                               placeholder="Gate Management System">
                    </div>
                    
                    <div>
                        <label for="session_timeout" class="block text-sm font-medium text-gray-700">Session Timeout (seconds)</label>
                        <select id="session_timeout" name="session_timeout" 
                                class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                            <option value="1800" <?php echo ($settings['session_timeout'] ?? '3600') == '1800' ? 'selected' : ''; ?>>30 minutes</option>
                            <option value="3600" <?php echo ($settings['session_timeout'] ?? '3600') == '3600' ? 'selected' : ''; ?>>1 hour</option>
                            <option value="7200" <?php echo ($settings['session_timeout'] ?? '3600') == '7200' ? 'selected' : ''; ?>>2 hours</option>
                            <option value="14400" <?php echo ($settings['session_timeout'] ?? '3600') == '14400' ? 'selected' : ''; ?>>4 hours</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Theme Settings -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-6">Theme & Colors</h3>
                
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <div>
                        <label for="primary_color" class="block text-sm font-medium text-gray-700">Primary Color</label>
                        <div class="mt-1 flex items-center space-x-3">
                            <input type="color" id="primary_color" name="primary_color" 
                                   value="<?php echo $settings['primary_color'] ?? '#2563eb'; ?>"
                                   class="h-10 w-16 border border-gray-300 rounded cursor-pointer">
                            <input type="text" 
                                   value="<?php echo $settings['primary_color'] ?? '#2563eb'; ?>"
                                   class="flex-1 px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                   readonly>
                        </div>
                    </div>
                    
                    <div>
                        <label for="secondary_color" class="block text-sm font-medium text-gray-700">Secondary Color</label>
                        <div class="mt-1 flex items-center space-x-3">
                            <input type="color" id="secondary_color" name="secondary_color" 
                                   value="<?php echo $settings['secondary_color'] ?? '#1f2937'; ?>"
                                   class="h-10 w-16 border border-gray-300 rounded cursor-pointer">
                            <input type="text" 
                                   value="<?php echo $settings['secondary_color'] ?? '#1f2937'; ?>"
                                   class="flex-1 px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                   readonly>
                        </div>
                    </div>
                    
                    <div>
                        <label for="accent_color" class="block text-sm font-medium text-gray-700">Accent Color</label>
                        <div class="mt-1 flex items-center space-x-3">
                            <input type="color" id="accent_color" name="accent_color" 
                                   value="<?php echo $settings['accent_color'] ?? '#10b981'; ?>"
                                   class="h-10 w-16 border border-gray-300 rounded cursor-pointer">
                            <input type="text" 
                                   value="<?php echo $settings['accent_color'] ?? '#10b981'; ?>"
                                   class="flex-1 px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                   readonly>
                        </div>
                    </div>
                </div>
                
                <div class="mt-4 p-4 bg-gray-50 rounded-lg">
                    <p class="text-sm text-gray-600 mb-2">Preview:</p>
                    <div class="flex space-x-4">
                        <div id="color_preview_primary" class="w-12 h-12 rounded border-2 border-white shadow" 
                             style="background-color: <?php echo $settings['primary_color'] ?? '#2563eb'; ?>"></div>
                        <div id="color_preview_secondary" class="w-12 h-12 rounded border-2 border-white shadow" 
                             style="background-color: <?php echo $settings['secondary_color'] ?? '#1f2937'; ?>"></div>
                        <div id="color_preview_accent" class="w-12 h-12 rounded border-2 border-white shadow" 
                             style="background-color: <?php echo $settings['accent_color'] ?? '#10b981'; ?>"></div>
                    </div>
                </div>
            </div>

            <!-- Email Settings -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-6">Email Notifications</h3>
                
                <div class="space-y-6">
                    <div class="flex items-center">
                        <input type="checkbox" id="email_notifications" name="email_notifications" 
                               <?php echo ($settings['email_notifications'] ?? 'false') === 'true' ? 'checked' : ''; ?>
                               class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                        <label for="email_notifications" class="ml-2 block text-sm text-gray-900">
                            Enable email notifications
                        </label>
                    </div>
                    
                    <div id="smtp_settings" class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label for="smtp_host" class="block text-sm font-medium text-gray-700">SMTP Host</label>
                            <input type="text" id="smtp_host" name="smtp_host" 
                                   value="<?php echo htmlspecialchars($settings['smtp_host'] ?? ''); ?>"
                                   class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500" 
                                   placeholder="smtp.gmail.com">
                        </div>
                        
                        <div>
                            <label for="smtp_port" class="block text-sm font-medium text-gray-700">SMTP Port</label>
                            <input type="number" id="smtp_port" name="smtp_port" 
                                   value="<?php echo htmlspecialchars($settings['smtp_port'] ?? '587'); ?>"
                                   class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500" 
                                   placeholder="587">
                        </div>
                        
                        <div>
                            <label for="smtp_username" class="block text-sm font-medium text-gray-700">SMTP Username</label>
                            <input type="email" id="smtp_username" name="smtp_username" 
                                   value="<?php echo htmlspecialchars($settings['smtp_username'] ?? ''); ?>"
                                   class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500" 
                                   placeholder="your-email@gmail.com">
                        </div>
                        
                        <div>
                            <label for="smtp_password" class="block text-sm font-medium text-gray-700">SMTP Password</label>
                            <input type="password" id="smtp_password" name="smtp_password" 
                                   class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500" 
                                   placeholder="Leave blank to keep current password">
                        </div>
                    </div>
                    
                    <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                        <div class="flex">
                            <i class="fas fa-info-circle text-blue-600 mt-0.5 mr-2"></i>
                            <div class="text-sm text-blue-800">
                                <p class="font-medium mb-1">Email Configuration Tips:</p>
                                <ul class="list-disc list-inside space-y-1">
                                    <li>For Gmail: Use app passwords instead of your regular password</li>
                                    <li>Common ports: 587 (TLS), 465 (SSL), 25 (unsecured)</li>
                                    <li>Test email functionality after saving settings</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Save Button -->
            <div class="flex justify-end">
                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-3 rounded-lg font-medium transition-colors">
                    <i class="fas fa-save mr-2"></i>Save Settings
                </button>
            </div>
        </form>
    </div>

    <script>
        // Color picker functionality
        document.querySelectorAll('input[type="color"]').forEach(picker => {
            const textInput = picker.nextElementSibling;
            const previewElement = document.getElementById('color_preview_' + picker.name.replace('_color', ''));
            
            picker.addEventListener('input', function() {
                textInput.value = this.value;
                if (previewElement) {
                    previewElement.style.backgroundColor = this.value;
                }
            });
        });

        // Email notifications toggle
        document.getElementById('email_notifications').addEventListener('change', function() {
            const smtpSettings = document.getElementById('smtp_settings');
            if (this.checked) {
                smtpSettings.style.opacity = '1';
                smtpSettings.querySelectorAll('input').forEach(input => input.disabled = false);
            } else {
                smtpSettings.style.opacity = '0.5';
                smtpSettings.querySelectorAll('input').forEach(input => input.disabled = true);
            }
        });

        // Initialize email settings state
        document.addEventListener('DOMContentLoaded', function() {
            const emailCheckbox = document.getElementById('email_notifications');
            emailCheckbox.dispatchEvent(new Event('change'));
        });

        // Form submission
        document.querySelector('form').addEventListener('submit', function(e) {
            const button = this.querySelector('button[type="submit"]');
            button.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Saving...';
            button.disabled = true;
        });
    </script>
</body>
</html>