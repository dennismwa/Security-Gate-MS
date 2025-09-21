-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: Sep 22, 2025 at 12:20 AM
-- Server version: 8.0.42
-- PHP Version: 8.4.11

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `vxjtgclw_security`
--

-- --------------------------------------------------------

--
-- Table structure for table `activity_logs`
--

CREATE TABLE `activity_logs` (
  `id` int NOT NULL,
  `operator_id` int DEFAULT NULL,
  `activity_type` varchar(50) NOT NULL,
  `description` text NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `activity_logs`
--

INSERT INTO `activity_logs` (`id`, `operator_id`, `activity_type`, `description`, `ip_address`, `user_agent`, `created_at`) VALUES
(1, 1, 'login', 'Operator logged in', '41.209.3.54', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', '2025-09-21 20:09:46'),
(2, 1, 'visitor_registration', 'Registered new visitor: Dennis Mwangi', '41.209.3.54', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', '2025-09-21 20:25:36'),
(3, 1, 'print_card', 'Printed card for visitor: Dennis Mwangi', '41.209.3.54', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', '2025-09-21 20:25:41'),
(4, 1, 'gate_scan', 'QR scan check_in for visitor: Dennis Mwangi', '41.209.3.54', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', '2025-09-21 20:26:29'),
(5, 1, 'gate_scan', 'QR scan check_out for visitor: Dennis Mwangi', '41.209.3.54', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', '2025-09-21 20:27:31'),
(6, 1, 'print_card', 'Printed card for visitor: Dennis Mwangi', '41.209.3.54', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', '2025-09-21 20:35:49'),
(7, 1, 'print_card', 'Printed professional card for visitor: Dennis Mwangi', '41.209.3.54', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', '2025-09-21 20:56:04'),
(8, 1, 'print_card', 'Printed professional card for visitor: Dennis Mwangi', '41.209.3.54', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', '2025-09-21 20:58:21'),
(9, 1, 'print_card', 'Printed professional card for visitor: Dennis Mwangi', '41.209.3.54', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', '2025-09-21 21:02:04');

-- --------------------------------------------------------

--
-- Table structure for table `card_print_logs`
--

CREATE TABLE `card_print_logs` (
  `id` int NOT NULL,
  `visitor_id` varchar(20) NOT NULL,
  `template_id` int DEFAULT NULL,
  `printed_by` int NOT NULL,
  `print_timestamp` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `print_quality` enum('draft','normal','high','photo') DEFAULT 'normal',
  `copies_printed` int DEFAULT '1',
  `printer_used` varchar(100) DEFAULT NULL,
  `notes` text
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `card_print_logs`
--

INSERT INTO `card_print_logs` (`id`, `visitor_id`, `template_id`, `printed_by`, `print_timestamp`, `print_quality`, `copies_printed`, `printer_used`, `notes`) VALUES
(1, 'VIS68D05F4001FAC', NULL, 1, '2025-09-21 20:56:04', 'high', 1, NULL, NULL),
(2, 'VIS68D05F4001FAC', NULL, 1, '2025-09-21 20:58:21', 'high', 1, NULL, NULL),
(3, 'VIS68D05F4001FAC', NULL, 1, '2025-09-21 21:02:04', 'high', 1, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `card_templates`
--

CREATE TABLE `card_templates` (
  `id` int NOT NULL,
  `template_name` varchar(100) NOT NULL,
  `background_color` varchar(7) DEFAULT '#ffffff',
  `header_color` varchar(7) DEFAULT '#2563eb',
  `text_color` varchar(7) DEFAULT '#000000',
  `logo_position` enum('top-left','top-right','center','bottom') DEFAULT 'top-right',
  `show_photo` tinyint(1) DEFAULT '1',
  `show_qr_front` tinyint(1) DEFAULT '0',
  `show_qr_back` tinyint(1) DEFAULT '1',
  `security_features` json DEFAULT NULL,
  `is_default` tinyint(1) DEFAULT '0',
  `is_active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `card_templates`
--

INSERT INTO `card_templates` (`id`, `template_name`, `background_color`, `header_color`, `text_color`, `logo_position`, `show_photo`, `show_qr_front`, `show_qr_back`, `security_features`, `is_default`, `is_active`, `created_at`) VALUES
(1, 'Professional Default', '#ffffff', '#2563eb', '#000000', 'top-right', 1, 0, 1, '{\"hologram\": true, \"watermark\": false, \"security_strip\": true}', 1, 1, '2025-09-21 20:34:47'),
(2, 'Professional Default', '#ffffff', '#2563eb', '#000000', 'top-right', 1, 0, 1, '{\"hologram\": true, \"watermark\": false, \"security_strip\": true}', 1, 1, '2025-09-21 20:37:13');

-- --------------------------------------------------------

--
-- Table structure for table `companies`
--

CREATE TABLE `companies` (
  `id` int NOT NULL,
  `company_name` varchar(100) NOT NULL,
  `logo_path` varchar(255) DEFAULT NULL,
  `contact_person` varchar(100) DEFAULT NULL,
  `contact_email` varchar(100) DEFAULT NULL,
  `contact_phone` varchar(20) DEFAULT NULL,
  `address` text,
  `is_active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `companies`
--

INSERT INTO `companies` (`id`, `company_name`, `logo_path`, `contact_person`, `contact_email`, `contact_phone`, `address`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'Acme Corporation', NULL, 'John Manager', 'contact@acme.com', '+1234567890', NULL, 1, '2025-09-21 20:34:47', '2025-09-21 20:34:47'),
(2, 'Tech Solutions Ltd', NULL, 'Jane Director', 'info@techsolutions.com', '+0987654321', NULL, 1, '2025-09-21 20:34:47', '2025-09-21 20:34:47'),
(3, 'Global Industries', NULL, 'Mike CEO', 'admin@global.com', '+1122334455', NULL, 1, '2025-09-21 20:34:47', '2025-09-21 20:34:47');

-- --------------------------------------------------------

--
-- Stand-in structure for view `daily_statistics`
-- (See below for the actual view)
--
CREATE TABLE `daily_statistics` (
`log_date` date
,`total_check_ins` bigint
,`total_check_outs` bigint
,`unique_visitors` bigint
,`vehicles_count` bigint
);

-- --------------------------------------------------------

--
-- Table structure for table `departments`
--

CREATE TABLE `departments` (
  `id` int NOT NULL,
  `department_name` varchar(100) NOT NULL,
  `description` text,
  `contact_person` varchar(100) DEFAULT NULL,
  `contact_phone` varchar(20) DEFAULT NULL,
  `contact_email` varchar(100) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `departments`
--

INSERT INTO `departments` (`id`, `department_name`, `description`, `contact_person`, `contact_phone`, `contact_email`, `is_active`, `created_at`) VALUES
(1, 'Administration', NULL, 'Admin Officer', NULL, NULL, 1, '2025-09-21 16:53:07'),
(2, 'Security', NULL, 'Security Manager', NULL, NULL, 1, '2025-09-21 16:53:07'),
(3, 'HR', NULL, 'HR Manager', NULL, NULL, 1, '2025-09-21 16:53:07'),
(4, 'IT', NULL, 'IT Support', NULL, NULL, 1, '2025-09-21 16:53:07'),
(5, 'Maintenance', NULL, 'Maintenance Supervisor', NULL, NULL, 1, '2025-09-21 16:53:07');

-- --------------------------------------------------------

--
-- Table structure for table `gate_logs`
--

CREATE TABLE `gate_logs` (
  `id` int NOT NULL,
  `visitor_id` varchar(20) NOT NULL,
  `log_type` enum('check_in','check_out') NOT NULL,
  `gate_location` varchar(100) DEFAULT 'Main Gate',
  `operator_id` int NOT NULL,
  `purpose_of_visit` text,
  `host_name` varchar(100) DEFAULT NULL,
  `host_department` varchar(100) DEFAULT NULL,
  `vehicle_number` varchar(20) DEFAULT NULL,
  `notes` text,
  `log_timestamp` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `gate_logs`
--

INSERT INTO `gate_logs` (`id`, `visitor_id`, `log_type`, `gate_location`, `operator_id`, `purpose_of_visit`, `host_name`, `host_department`, `vehicle_number`, `notes`, `log_timestamp`) VALUES
(1, 'VIS68D05F4001FAC', 'check_in', 'Main Gate', 1, '', '', '', 'KDQ 123J', '', '2025-09-21 20:26:29'),
(2, 'VIS68D05F4001FAC', 'check_out', 'Main Gate', 1, '', '', '', 'KDQ 123J', '', '2025-09-21 20:27:31');

-- --------------------------------------------------------

--
-- Table structure for table `gate_operators`
--

CREATE TABLE `gate_operators` (
  `id` int NOT NULL,
  `operator_name` varchar(100) NOT NULL,
  `operator_code` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('admin','operator') DEFAULT 'operator',
  `is_active` tinyint(1) DEFAULT '1',
  `last_login` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `gate_operators`
--

INSERT INTO `gate_operators` (`id`, `operator_name`, `operator_code`, `password_hash`, `role`, `is_active`, `last_login`, `created_at`, `updated_at`) VALUES
(1, 'Admin', 'ADMIN001', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 1, '2025-09-21 20:09:46', '2025-09-21 16:53:06', '2025-09-21 20:09:46');

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` int NOT NULL,
  `type` enum('check_in','check_out','pre_registration','alert') NOT NULL,
  `title` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `visitor_id` varchar(20) DEFAULT NULL,
  `operator_id` int DEFAULT NULL,
  `is_read` tinyint(1) DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `operator_sessions`
--

CREATE TABLE `operator_sessions` (
  `id` int NOT NULL,
  `operator_id` int NOT NULL,
  `session_token` varchar(255) NOT NULL,
  `expires_at` timestamp NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `operator_sessions`
--

INSERT INTO `operator_sessions` (`id`, `operator_id`, `session_token`, `expires_at`, `created_at`) VALUES
(1, 1, '9e163d5ab60e6157671867456653a54453e268754f9b03bfc88666f318d0fc4d', '2025-09-21 22:19:58', '2025-09-21 20:09:46');

-- --------------------------------------------------------

--
-- Table structure for table `pre_registrations`
--

CREATE TABLE `pre_registrations` (
  `id` int NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `phone` varchar(20) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `company` varchar(100) DEFAULT NULL,
  `vehicle_number` varchar(20) DEFAULT NULL,
  `purpose_of_visit` text,
  `host_name` varchar(100) DEFAULT NULL,
  `host_department` varchar(100) DEFAULT NULL,
  `visit_date` date NOT NULL,
  `visit_time_from` time DEFAULT NULL,
  `visit_time_to` time DEFAULT NULL,
  `status` enum('pending','approved','rejected','used') DEFAULT 'pending',
  `qr_code` varchar(255) DEFAULT NULL,
  `created_by_operator` int DEFAULT NULL,
  `approved_by_operator` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `settings`
--

CREATE TABLE `settings` (
  `id` int NOT NULL,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `settings`
--

INSERT INTO `settings` (`id`, `setting_key`, `setting_value`, `created_at`, `updated_at`) VALUES
(1, 'system_name', 'Gate Management System', '2025-09-21 16:53:06', '2025-09-21 16:53:06'),
(2, 'primary_color', '#2563eb', '2025-09-21 16:53:06', '2025-09-21 16:53:06'),
(3, 'secondary_color', '#1f2937', '2025-09-21 16:53:06', '2025-09-21 16:53:06'),
(4, 'accent_color', '#10b981', '2025-09-21 16:53:06', '2025-09-21 16:53:06'),
(5, 'email_notifications', 'false', '2025-09-21 16:53:06', '2025-09-21 16:53:06'),
(6, 'smtp_host', '', '2025-09-21 16:53:06', '2025-09-21 16:53:06'),
(7, 'smtp_port', '587', '2025-09-21 16:53:06', '2025-09-21 16:53:06'),
(8, 'smtp_username', '', '2025-09-21 16:53:06', '2025-09-21 16:53:06'),
(9, 'smtp_password', '', '2025-09-21 16:53:06', '2025-09-21 16:53:06'),
(10, 'session_timeout', '3600', '2025-09-21 16:53:06', '2025-09-21 16:53:06'),
(11, 'card_logo_path', '', '2025-09-21 20:34:47', '2025-09-21 20:34:47'),
(12, 'card_background_image', '', '2025-09-21 20:34:47', '2025-09-21 20:34:47'),
(13, 'card_expiry_days', '30', '2025-09-21 20:34:47', '2025-09-21 20:34:47'),
(14, 'card_security_features', 'true', '2025-09-21 20:34:47', '2025-09-21 20:34:47'),
(15, 'card_double_sided', 'true', '2025-09-21 20:34:47', '2025-09-21 20:34:47'),
(16, 'print_resolution', 'high', '2025-09-21 20:34:47', '2025-09-21 20:34:47'),
(17, 'card_paper_size', 'cr80', '2025-09-21 20:34:47', '2025-09-21 20:34:47'),
(18, 'organization_name', 'Gate Management System', '2025-09-21 20:34:47', '2025-09-21 20:34:47'),
(19, 'security_contact', '+1-800-SECURITY', '2025-09-21 20:34:47', '2025-09-21 20:34:47'),
(20, 'enable_photo_capture', 'true', '2025-09-21 20:34:47', '2025-09-21 20:34:47');

-- --------------------------------------------------------

--
-- Table structure for table `vehicle_types`
--

CREATE TABLE `vehicle_types` (
  `id` int NOT NULL,
  `type_name` varchar(50) NOT NULL,
  `description` text,
  `is_active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `vehicle_types`
--

INSERT INTO `vehicle_types` (`id`, `type_name`, `description`, `is_active`, `created_at`) VALUES
(1, 'Car', 'Personal or company cars', 1, '2025-09-21 16:53:07'),
(2, 'Truck', 'Delivery trucks and heavy vehicles', 1, '2025-09-21 16:53:07'),
(3, 'Motorcycle', 'Motorcycles and scooters', 1, '2025-09-21 16:53:07'),
(4, 'Van', 'Vans and mini buses', 1, '2025-09-21 16:53:07'),
(5, 'Bicycle', 'Bicycles', 1, '2025-09-21 16:53:07'),
(6, 'Other', 'Other vehicle types', 1, '2025-09-21 16:53:07');

-- --------------------------------------------------------

--
-- Table structure for table `visitors`
--

CREATE TABLE `visitors` (
  `id` int NOT NULL,
  `visitor_id` varchar(20) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `phone` varchar(20) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `id_number` varchar(50) DEFAULT NULL,
  `company` varchar(100) DEFAULT NULL,
  `vehicle_number` varchar(20) DEFAULT NULL,
  `photo_path` varchar(255) DEFAULT NULL,
  `qr_code` varchar(255) NOT NULL,
  `is_pre_registered` tinyint(1) DEFAULT '0',
  `status` enum('active','inactive','blocked') DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `visitors`
--

INSERT INTO `visitors` (`id`, `visitor_id`, `full_name`, `phone`, `email`, `id_number`, `company`, `vehicle_number`, `photo_path`, `qr_code`, `is_pre_registered`, `status`, `created_at`, `updated_at`) VALUES
(1, 'VIS68D05F4001FAC', 'Dennis Mwangi', '+254758256440', 'mwangidennis546@gmail.com', '123456789', 'Zurihub', 'KDQ 123J', NULL, '22f7f2b15367991d7820a2cdc7018b913a073a4e1eaf5ae6f213ca76d5e49c01', 0, 'active', '2025-09-21 20:25:36', '2025-09-21 20:25:36');

-- --------------------------------------------------------

--
-- Stand-in structure for view `visitor_current_status`
-- (See below for the actual view)
--
CREATE TABLE `visitor_current_status` (
`company` varchar(100)
,`current_status` varchar(13)
,`full_name` varchar(100)
,`last_activity` timestamp
,`last_operator` varchar(100)
,`phone` varchar(20)
,`vehicle_number` varchar(20)
,`visitor_id` varchar(20)
);

-- --------------------------------------------------------

--
-- Table structure for table `visitor_photos`
--

CREATE TABLE `visitor_photos` (
  `id` int NOT NULL,
  `visitor_id` varchar(20) NOT NULL,
  `photo_path` varchar(255) NOT NULL,
  `file_size` int DEFAULT NULL,
  `mime_type` varchar(50) DEFAULT NULL,
  `uploaded_by` int DEFAULT NULL,
  `upload_date` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `is_active` tinyint(1) DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `activity_logs`
--
ALTER TABLE `activity_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `operator_id` (`operator_id`);

--
-- Indexes for table `card_print_logs`
--
ALTER TABLE `card_print_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `visitor_id` (`visitor_id`),
  ADD KEY `template_id` (`template_id`),
  ADD KEY `printed_by` (`printed_by`);

--
-- Indexes for table `card_templates`
--
ALTER TABLE `card_templates`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_card_templates_default` (`is_default`,`is_active`);

--
-- Indexes for table `companies`
--
ALTER TABLE `companies`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `company_name` (`company_name`),
  ADD KEY `idx_companies_active` (`is_active`);

--
-- Indexes for table `departments`
--
ALTER TABLE `departments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `department_name` (`department_name`);

--
-- Indexes for table `gate_logs`
--
ALTER TABLE `gate_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_visitor_id` (`visitor_id`),
  ADD KEY `idx_log_timestamp` (`log_timestamp`),
  ADD KEY `operator_id` (`operator_id`),
  ADD KEY `idx_gate_logs_visitor_timestamp` (`visitor_id`,`log_timestamp`);

--
-- Indexes for table `gate_operators`
--
ALTER TABLE `gate_operators`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `operator_code` (`operator_code`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `visitor_id` (`visitor_id`),
  ADD KEY `operator_id` (`operator_id`);

--
-- Indexes for table `operator_sessions`
--
ALTER TABLE `operator_sessions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `session_token` (`session_token`),
  ADD KEY `operator_id` (`operator_id`);

--
-- Indexes for table `pre_registrations`
--
ALTER TABLE `pre_registrations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_visit_date` (`visit_date`),
  ADD KEY `created_by_operator` (`created_by_operator`),
  ADD KEY `approved_by_operator` (`approved_by_operator`),
  ADD KEY `idx_pre_reg_status_date` (`status`,`visit_date`);

--
-- Indexes for table `settings`
--
ALTER TABLE `settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `setting_key` (`setting_key`);

--
-- Indexes for table `vehicle_types`
--
ALTER TABLE `vehicle_types`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `type_name` (`type_name`);

--
-- Indexes for table `visitors`
--
ALTER TABLE `visitors`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `visitor_id` (`visitor_id`),
  ADD UNIQUE KEY `qr_code` (`qr_code`),
  ADD KEY `idx_visitors_qr_code` (`qr_code`),
  ADD KEY `idx_visitors_phone` (`phone`),
  ADD KEY `idx_visitors_company` (`company`),
  ADD KEY `idx_visitors_photo` (`photo_path`);

--
-- Indexes for table `visitor_photos`
--
ALTER TABLE `visitor_photos`
  ADD PRIMARY KEY (`id`),
  ADD KEY `visitor_id` (`visitor_id`),
  ADD KEY `uploaded_by` (`uploaded_by`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `activity_logs`
--
ALTER TABLE `activity_logs`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `card_print_logs`
--
ALTER TABLE `card_print_logs`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `card_templates`
--
ALTER TABLE `card_templates`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `companies`
--
ALTER TABLE `companies`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `departments`
--
ALTER TABLE `departments`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `gate_logs`
--
ALTER TABLE `gate_logs`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `gate_operators`
--
ALTER TABLE `gate_operators`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `operator_sessions`
--
ALTER TABLE `operator_sessions`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `pre_registrations`
--
ALTER TABLE `pre_registrations`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `settings`
--
ALTER TABLE `settings`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=31;

--
-- AUTO_INCREMENT for table `vehicle_types`
--
ALTER TABLE `vehicle_types`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `visitors`
--
ALTER TABLE `visitors`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `visitor_photos`
--
ALTER TABLE `visitor_photos`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

-- --------------------------------------------------------

--
-- Structure for view `daily_statistics`
--
DROP TABLE IF EXISTS `daily_statistics`;

CREATE ALGORITHM=UNDEFINED DEFINER=`vxjtgclw`@`localhost` SQL SECURITY DEFINER VIEW `daily_statistics`  AS SELECT cast(`gl`.`log_timestamp` as date) AS `log_date`, count((case when (`gl`.`log_type` = 'check_in') then 1 end)) AS `total_check_ins`, count((case when (`gl`.`log_type` = 'check_out') then 1 end)) AS `total_check_outs`, count(distinct `gl`.`visitor_id`) AS `unique_visitors`, count(distinct (case when (`gl`.`vehicle_number` is not null) then `gl`.`vehicle_number` end)) AS `vehicles_count` FROM `gate_logs` AS `gl` GROUP BY cast(`gl`.`log_timestamp` as date) ORDER BY `log_date` DESC ;

-- --------------------------------------------------------

--
-- Structure for view `visitor_current_status`
--
DROP TABLE IF EXISTS `visitor_current_status`;

CREATE ALGORITHM=UNDEFINED DEFINER=`vxjtgclw`@`localhost` SQL SECURITY DEFINER VIEW `visitor_current_status`  AS SELECT `v`.`visitor_id` AS `visitor_id`, `v`.`full_name` AS `full_name`, `v`.`phone` AS `phone`, `v`.`vehicle_number` AS `vehicle_number`, `v`.`company` AS `company`, (case when (`latest_log`.`log_type` = 'check_in') then 'Inside' when (`latest_log`.`log_type` = 'check_out') then 'Outside' else 'Never Visited' end) AS `current_status`, `latest_log`.`log_timestamp` AS `last_activity`, `latest_log`.`operator_name` AS `last_operator` FROM (`visitors` `v` left join (select `gl`.`visitor_id` AS `visitor_id`,`gl`.`log_type` AS `log_type`,`gl`.`log_timestamp` AS `log_timestamp`,`go`.`operator_name` AS `operator_name`,row_number() OVER (PARTITION BY `gl`.`visitor_id` ORDER BY `gl`.`log_timestamp` desc )  AS `rn` from (`gate_logs` `gl` join `gate_operators` `go` on((`gl`.`operator_id` = `go`.`id`)))) `latest_log` on(((`v`.`visitor_id` = `latest_log`.`visitor_id`) and (`latest_log`.`rn` = 1)))) ;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `activity_logs`
--
ALTER TABLE `activity_logs`
  ADD CONSTRAINT `activity_logs_ibfk_1` FOREIGN KEY (`operator_id`) REFERENCES `gate_operators` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `card_print_logs`
--
ALTER TABLE `card_print_logs`
  ADD CONSTRAINT `card_print_logs_ibfk_1` FOREIGN KEY (`visitor_id`) REFERENCES `visitors` (`visitor_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `card_print_logs_ibfk_2` FOREIGN KEY (`template_id`) REFERENCES `card_templates` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `card_print_logs_ibfk_3` FOREIGN KEY (`printed_by`) REFERENCES `gate_operators` (`id`) ON DELETE RESTRICT;

--
-- Constraints for table `gate_logs`
--
ALTER TABLE `gate_logs`
  ADD CONSTRAINT `gate_logs_ibfk_1` FOREIGN KEY (`visitor_id`) REFERENCES `visitors` (`visitor_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `gate_logs_ibfk_2` FOREIGN KEY (`operator_id`) REFERENCES `gate_operators` (`id`) ON DELETE RESTRICT;

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`visitor_id`) REFERENCES `visitors` (`visitor_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `notifications_ibfk_2` FOREIGN KEY (`operator_id`) REFERENCES `gate_operators` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `operator_sessions`
--
ALTER TABLE `operator_sessions`
  ADD CONSTRAINT `operator_sessions_ibfk_1` FOREIGN KEY (`operator_id`) REFERENCES `gate_operators` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `pre_registrations`
--
ALTER TABLE `pre_registrations`
  ADD CONSTRAINT `pre_registrations_ibfk_1` FOREIGN KEY (`created_by_operator`) REFERENCES `gate_operators` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `pre_registrations_ibfk_2` FOREIGN KEY (`approved_by_operator`) REFERENCES `gate_operators` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `visitor_photos`
--
ALTER TABLE `visitor_photos`
  ADD CONSTRAINT `visitor_photos_ibfk_1` FOREIGN KEY (`visitor_id`) REFERENCES `visitors` (`visitor_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `visitor_photos_ibfk_2` FOREIGN KEY (`uploaded_by`) REFERENCES `gate_operators` (`id`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
