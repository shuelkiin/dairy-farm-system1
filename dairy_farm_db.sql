-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: localhost
-- Generation Time: Oct 16, 2026 at 02:53 PM
-- Server version: 10.4.28-MariaDB
-- PHP Version: 8.2.4

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `dairy_farm_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `activity_logs`
--

CREATE TABLE `activity_logs` (
  `log_id` bigint(20) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(150) NOT NULL,
  `details` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `calf_milk_allocations`
--

CREATE TABLE `calf_milk_allocations` (
  `allocation_id` int(11) NOT NULL,
  `farm_id` int(11) NOT NULL,
  `calf_id` int(11) NOT NULL,
  `recorded_by` int(11) DEFAULT NULL,
  `allocation_date` date NOT NULL,
  `quantity_litres` decimal(10,2) NOT NULL,
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `cows`
--

CREATE TABLE `cows` (
  `cow_id` int(11) NOT NULL,
  `farm_id` int(11) NOT NULL,
  `tag_number` varchar(50) NOT NULL,
  `cow_name` varchar(100) DEFAULT NULL,
  `date_of_birth` date NOT NULL,
  `breed` varchar(100) DEFAULT NULL,
  `gender` enum('female','male') NOT NULL,
  `origin` enum('born_on_farm','acquired') NOT NULL DEFAULT 'acquired',
  `mother_id` int(11) DEFAULT NULL,
  `father_id` int(11) DEFAULT NULL,
  `status` enum('active','sick','pregnant','dry','sold','deceased') DEFAULT 'active',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `manager_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `cows`
--

INSERT INTO `cows` (`cow_id`, `farm_id`, `tag_number`, `cow_name`, `date_of_birth`, `breed`, `gender`, `origin`, `mother_id`, `father_id`, `status`, `notes`, `created_at`, `manager_id`) VALUES
(1, 1, 'cow-0001', 'belle', '2024-02-06', 'Ayrshire', 'female', 'acquired', NULL, NULL, 'active', NULL, '2026-10-14 17:02:07', NULL),
(2, 1, 'cow-0002', 'kiin', '2026-10-14', 'Friesian', 'female', 'born_on_farm', 1, NULL, 'dry', NULL, '2026-10-14 17:03:10', NULL),
(3, 1, 'cow-0003', 'john', '2026-02-25', 'Guernsey', 'male', 'born_on_farm', 1, NULL, 'dry', NULL, '2026-10-15 13:40:56', NULL),
(4, 2, 'cow-0004', 'joy', '2024-06-15', 'Brown Swiss', 'female', 'acquired', NULL, NULL, 'pregnant', NULL, '2026-10-15 13:50:10', NULL),
(5, 2, 'cow-0005', 'freedom', '2026-10-15', 'Jersey', 'female', 'born_on_farm', 4, NULL, 'sold', NULL, '2026-10-15 13:53:07', NULL),
(6, 1, 'cow-0006', 'kesh', '2025-12-12', 'Ayrshire', 'female', 'born_on_farm', 1, NULL, 'active', NULL, '2026-10-15 17:24:58', 8),
(7, 1, 'cow-0007', NULL, '2021-10-01', 'Zebu', 'female', 'born_on_farm', 2, NULL, 'active', NULL, '2026-10-15 17:25:49', 8),
(8, 1, 'cow-0008', 'kish', '2026-09-12', 'Ayrshire', 'female', 'born_on_farm', 2, NULL, 'active', NULL, '2026-10-15 17:26:56', 8),
(9, 1, 'cow-0009', 'kemoo', '2026-01-12', 'Crossbreed', 'female', 'acquired', NULL, NULL, 'active', NULL, '2026-10-15 17:29:21', 6),
(10, 2, 'cow-0010', 'cheps', '2023-04-02', 'Zebu', 'female', 'acquired', NULL, NULL, 'active', NULL, '2026-10-15 17:53:35', 7),
(11, 1, 'cow-0011', 'example', '2023-10-10', 'Brown Swiss', 'female', 'born_on_farm', 7, NULL, 'active', NULL, '2026-10-16 12:01:13', 6),
(12, 1, 'cow-0012', 'service', '2025-12-12', 'Crossbreed', 'female', 'born_on_farm', 1, NULL, 'dry', NULL, '2026-10-16 12:10:49', 6);

-- --------------------------------------------------------

--
-- Table structure for table `cow_weights`
--

CREATE TABLE `cow_weights` (
  `weight_id` int(11) NOT NULL,
  `cow_id` int(11) NOT NULL,
  `recorded_by` int(11) DEFAULT NULL,
  `weight_kg` decimal(8,2) NOT NULL,
  `recorded_on` date NOT NULL,
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `farms`
--

CREATE TABLE `farms` (
  `farm_id` int(11) NOT NULL,
  `farm_name` varchar(150) NOT NULL,
  `location` varchar(255) DEFAULT NULL,
  `contact_phone` varchar(30) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` enum('active','inactive') DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `farms`
--

INSERT INTO `farms` (`farm_id`, `farm_name`, `location`, `contact_phone`, `created_at`, `status`) VALUES
(1, 'dawns farm', 'meru', '0712345678', '2026-10-14 15:34:46', 'active'),
(2, 'kings farm', 'narok', '1234567890', '2026-10-14 15:35:09', 'active'),
(3, 'shuels farm', 'kariandusi', '1234567890', '2026-10-15 13:27:17', 'active');

-- --------------------------------------------------------

--
-- Table structure for table `feed_movements`
--

CREATE TABLE `feed_movements` (
  `movement_id` int(11) NOT NULL,
  `farm_id` int(11) NOT NULL,
  `feed_id` int(11) NOT NULL,
  `recorded_by` int(11) DEFAULT NULL,
  `movement_type` enum('purchase','usage','adjustment') NOT NULL,
  `quantity` decimal(12,2) NOT NULL,
  `movement_date` datetime DEFAULT current_timestamp(),
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `feed_stock`
--

CREATE TABLE `feed_stock` (
  `stock_id` int(11) NOT NULL,
  `farm_id` int(11) NOT NULL,
  `feed_id` int(11) NOT NULL,
  `quantity` decimal(12,2) NOT NULL DEFAULT 0.00,
  `reorder_level` decimal(12,2) NOT NULL DEFAULT 0.00,
  `last_updated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `feed_types`
--

CREATE TABLE `feed_types` (
  `feed_id` int(11) NOT NULL,
  `feed_name` varchar(120) NOT NULL,
  `unit` varchar(30) NOT NULL DEFAULT 'kg',
  `description` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `health_records`
--

CREATE TABLE `health_records` (
  `health_id` int(11) NOT NULL,
  `cow_id` int(11) NOT NULL,
  `recorded_by` int(11) DEFAULT NULL,
  `condition_name` varchar(150) NOT NULL,
  `symptoms` text DEFAULT NULL,
  `treatment` text DEFAULT NULL,
  `veterinarian` varchar(150) DEFAULT NULL,
  `record_date` date NOT NULL,
  `next_checkup` date DEFAULT NULL,
  `status` enum('under_treatment','recovered','ongoing') DEFAULT 'ongoing'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `milk_movements`
--

CREATE TABLE `milk_movements` (
  `movement_id` int(11) NOT NULL,
  `farm_id` int(11) NOT NULL,
  `recorded_by` int(11) DEFAULT NULL,
  `movement_type` enum('received','calf_allocation','sale','waste','adjustment') NOT NULL,
  `quantity_litres` decimal(12,2) NOT NULL,
  `movement_date` datetime DEFAULT current_timestamp(),
  `reference_note` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `milk_production`
--

CREATE TABLE `milk_production` (
  `production_id` int(11) NOT NULL,
  `farm_id` int(11) NOT NULL,
  `cow_id` int(11) DEFAULT NULL,
  `recorded_by` int(11) DEFAULT NULL,
  `production_date` date NOT NULL,
  `milking_session` enum('morning','afternoon','evening') NOT NULL,
  `quantity_litres` decimal(10,2) NOT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `milk_production`
--

INSERT INTO `milk_production` (`production_id`, `farm_id`, `cow_id`, `recorded_by`, `production_date`, `milking_session`, `quantity_litres`, `notes`, `created_at`) VALUES
(1, 1, 1, 2, '2026-10-15', 'morning', 50.00, NULL, '2026-10-15 14:10:25'),
(2, 1, 1, 2, '2026-10-15', 'afternoon', 30.00, NULL, '2026-10-15 14:10:41'),
(3, 1, 1, 2, '2026-10-15', 'evening', 40.00, NULL, '2026-10-15 14:10:52'),
(4, 1, 1, 2, '2026-10-15', 'morning', 23.00, NULL, '2026-10-15 16:59:22'),
(5, 1, 8, 8, '2026-10-15', 'morning', 32.00, NULL, '2026-10-15 17:27:57'),
(6, 1, 9, 6, '2026-10-15', 'morning', 34.00, NULL, '2026-10-15 17:29:59'),
(7, 2, 10, 7, '2026-10-15', 'morning', 45.00, NULL, '2026-10-15 17:53:56'),
(8, 1, 6, 8, '2026-10-16', 'morning', 20.00, NULL, '2026-10-16 11:52:09'),
(9, 1, 11, 6, '2026-10-16', 'morning', 30.00, NULL, '2026-10-16 12:01:41'),
(10, 1, 6, 2, '2026-10-16', 'afternoon', 34.00, NULL, '2026-10-16 12:15:10'),
(11, 2, 10, 7, '2026-10-16', 'morning', 100.00, NULL, '2026-10-16 12:18:56');

-- --------------------------------------------------------

--
-- Table structure for table `roles`
--

CREATE TABLE `roles` (
  `role_id` int(11) NOT NULL,
  `role_name` enum('super_admin','admin','farm_manager','store_manager') NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `roles`
--

INSERT INTO `roles` (`role_id`, `role_name`) VALUES
(1, 'super_admin'),
(2, 'admin'),
(3, 'farm_manager'),
(4, 'store_manager');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `user_id` int(11) NOT NULL,
  `role_id` int(11) NOT NULL,
  `farm_id` int(11) DEFAULT NULL,
  `full_name` varchar(150) NOT NULL,
  `email` varchar(150) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`user_id`, `role_id`, `farm_id`, `full_name`, `email`, `password_hash`, `phone`, `status`, `created_at`) VALUES
(1, 1, NULL, 'shuel', 'kiinshuel8@gmail.com', '$2y$10$acBRRntBgHlHjNOfdc5MyOmhBRNpYQY14zZYLGTZKKNa6i.tIjSPa', NULL, 'active', '2026-10-14 15:27:03'),
(2, 2, 1, 'kudos', 'kudos@gmail.com', '$2y$10$Ofd4aOK/W1cv7PMYOQojV.EWAfn0h1jx.IIgFpIMTBgmynmPsyBeq', '0700000000', 'active', '2026-10-14 15:36:27'),
(3, 4, 2, 'mike', 'mike@gmail.com', '$2y$10$bADyKmeyqBsZUui3hPUSjOZLBrlohEfDo1PBI6n8SFOvtF7kj3Bsq', '1111111111', 'active', '2026-10-14 15:37:19'),
(5, 2, 2, 'kings', 'kings@gmail.com', '$2y$10$GKPKo1kHUQIpwblqyutktuphMqipVl0xWx.jZjBlyrmAsIA5G117C', '1234123412', 'active', '2026-10-15 13:26:23'),
(6, 3, 1, 'john', 'john@gmail.com', '$2y$10$N98kS0FcY.4NhAzHTLoQWusZQfk4SjHVlgfDjDFehAjn45StQ.aIK', '1234567890', 'active', '2026-10-15 13:35:13'),
(7, 3, 2, 'chebet', 'chebet@gmail.com', '$2y$10$Mcm6ND7U/OnDYw//N2dg4uUOjByMPD7tSmsZvxAE8TkjF2i26fIVK', '1234567890', 'active', '2026-10-15 13:37:42'),
(8, 3, 1, 'rita', 'rita@gmail.com', '$2y$10$YadvFb4y5ObPAYyHdXywi.gMwg3iIH/1NKNhDiMWJJgNlLD5kmYzO', '1234567890', 'active', '2026-10-15 14:16:05');

-- --------------------------------------------------------

--
-- Table structure for table `vaccinations`
--

CREATE TABLE `vaccinations` (
  `vaccination_id` int(11) NOT NULL,
  `cow_id` int(11) NOT NULL,
  `vaccine_name` varchar(150) NOT NULL,
  `vaccination_date` date NOT NULL,
  `next_due_date` date DEFAULT NULL,
  `administered_by` varchar(150) DEFAULT NULL,
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `activity_logs`
--
ALTER TABLE `activity_logs`
  ADD PRIMARY KEY (`log_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `calf_milk_allocations`
--
ALTER TABLE `calf_milk_allocations`
  ADD PRIMARY KEY (`allocation_id`),
  ADD KEY `calf_id` (`calf_id`),
  ADD KEY `recorded_by` (`recorded_by`),
  ADD KEY `idx_calf_milk_date` (`farm_id`,`allocation_date`);

--
-- Indexes for table `cows`
--
ALTER TABLE `cows`
  ADD PRIMARY KEY (`cow_id`),
  ADD UNIQUE KEY `tag_number` (`tag_number`),
  ADD KEY `mother_id` (`mother_id`),
  ADD KEY `father_id` (`father_id`),
  ADD KEY `idx_cows_farm` (`farm_id`),
  ADD KEY `idx_cows_status` (`status`),
  ADD KEY `fk_cows_manager` (`manager_id`);

--
-- Indexes for table `cow_weights`
--
ALTER TABLE `cow_weights`
  ADD PRIMARY KEY (`weight_id`),
  ADD UNIQUE KEY `unique_cow_weight_date` (`cow_id`,`recorded_on`),
  ADD KEY `recorded_by` (`recorded_by`);

--
-- Indexes for table `farms`
--
ALTER TABLE `farms`
  ADD PRIMARY KEY (`farm_id`);

--
-- Indexes for table `feed_movements`
--
ALTER TABLE `feed_movements`
  ADD PRIMARY KEY (`movement_id`),
  ADD KEY `farm_id` (`farm_id`),
  ADD KEY `feed_id` (`feed_id`),
  ADD KEY `recorded_by` (`recorded_by`);

--
-- Indexes for table `feed_stock`
--
ALTER TABLE `feed_stock`
  ADD PRIMARY KEY (`stock_id`),
  ADD UNIQUE KEY `unique_farm_feed` (`farm_id`,`feed_id`),
  ADD KEY `feed_id` (`feed_id`);

--
-- Indexes for table `feed_types`
--
ALTER TABLE `feed_types`
  ADD PRIMARY KEY (`feed_id`),
  ADD UNIQUE KEY `feed_name` (`feed_name`);

--
-- Indexes for table `health_records`
--
ALTER TABLE `health_records`
  ADD PRIMARY KEY (`health_id`),
  ADD KEY `cow_id` (`cow_id`),
  ADD KEY `recorded_by` (`recorded_by`);

--
-- Indexes for table `milk_movements`
--
ALTER TABLE `milk_movements`
  ADD PRIMARY KEY (`movement_id`),
  ADD KEY `recorded_by` (`recorded_by`),
  ADD KEY `idx_milk_movement_date` (`farm_id`,`movement_date`);

--
-- Indexes for table `milk_production`
--
ALTER TABLE `milk_production`
  ADD PRIMARY KEY (`production_id`),
  ADD KEY `cow_id` (`cow_id`),
  ADD KEY `recorded_by` (`recorded_by`),
  ADD KEY `idx_milk_farm_date` (`farm_id`,`production_date`);

--
-- Indexes for table `roles`
--
ALTER TABLE `roles`
  ADD PRIMARY KEY (`role_id`),
  ADD UNIQUE KEY `role_name` (`role_name`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `role_id` (`role_id`),
  ADD KEY `farm_id` (`farm_id`);

--
-- Indexes for table `vaccinations`
--
ALTER TABLE `vaccinations`
  ADD PRIMARY KEY (`vaccination_id`),
  ADD KEY `cow_id` (`cow_id`),
  ADD KEY `idx_vaccination_due` (`next_due_date`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `activity_logs`
--
ALTER TABLE `activity_logs`
  MODIFY `log_id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `calf_milk_allocations`
--
ALTER TABLE `calf_milk_allocations`
  MODIFY `allocation_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `cows`
--
ALTER TABLE `cows`
  MODIFY `cow_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `cow_weights`
--
ALTER TABLE `cow_weights`
  MODIFY `weight_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `farms`
--
ALTER TABLE `farms`
  MODIFY `farm_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `feed_movements`
--
ALTER TABLE `feed_movements`
  MODIFY `movement_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `feed_stock`
--
ALTER TABLE `feed_stock`
  MODIFY `stock_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `feed_types`
--
ALTER TABLE `feed_types`
  MODIFY `feed_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `health_records`
--
ALTER TABLE `health_records`
  MODIFY `health_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `milk_movements`
--
ALTER TABLE `milk_movements`
  MODIFY `movement_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `milk_production`
--
ALTER TABLE `milk_production`
  MODIFY `production_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `roles`
--
ALTER TABLE `roles`
  MODIFY `role_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `vaccinations`
--
ALTER TABLE `vaccinations`
  MODIFY `vaccination_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `activity_logs`
--
ALTER TABLE `activity_logs`
  ADD CONSTRAINT `activity_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `calf_milk_allocations`
--
ALTER TABLE `calf_milk_allocations`
  ADD CONSTRAINT `calf_milk_allocations_ibfk_1` FOREIGN KEY (`farm_id`) REFERENCES `farms` (`farm_id`),
  ADD CONSTRAINT `calf_milk_allocations_ibfk_2` FOREIGN KEY (`calf_id`) REFERENCES `cows` (`cow_id`),
  ADD CONSTRAINT `calf_milk_allocations_ibfk_3` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `cows`
--
ALTER TABLE `cows`
  ADD CONSTRAINT `cows_ibfk_1` FOREIGN KEY (`farm_id`) REFERENCES `farms` (`farm_id`),
  ADD CONSTRAINT `cows_ibfk_2` FOREIGN KEY (`mother_id`) REFERENCES `cows` (`cow_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `cows_ibfk_3` FOREIGN KEY (`father_id`) REFERENCES `cows` (`cow_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_cows_manager` FOREIGN KEY (`manager_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `cow_weights`
--
ALTER TABLE `cow_weights`
  ADD CONSTRAINT `cow_weights_ibfk_1` FOREIGN KEY (`cow_id`) REFERENCES `cows` (`cow_id`),
  ADD CONSTRAINT `cow_weights_ibfk_2` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `feed_movements`
--
ALTER TABLE `feed_movements`
  ADD CONSTRAINT `feed_movements_ibfk_1` FOREIGN KEY (`farm_id`) REFERENCES `farms` (`farm_id`),
  ADD CONSTRAINT `feed_movements_ibfk_2` FOREIGN KEY (`feed_id`) REFERENCES `feed_types` (`feed_id`),
  ADD CONSTRAINT `feed_movements_ibfk_3` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `feed_stock`
--
ALTER TABLE `feed_stock`
  ADD CONSTRAINT `feed_stock_ibfk_1` FOREIGN KEY (`farm_id`) REFERENCES `farms` (`farm_id`),
  ADD CONSTRAINT `feed_stock_ibfk_2` FOREIGN KEY (`feed_id`) REFERENCES `feed_types` (`feed_id`);

--
-- Constraints for table `health_records`
--
ALTER TABLE `health_records`
  ADD CONSTRAINT `health_records_ibfk_1` FOREIGN KEY (`cow_id`) REFERENCES `cows` (`cow_id`),
  ADD CONSTRAINT `health_records_ibfk_2` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `milk_movements`
--
ALTER TABLE `milk_movements`
  ADD CONSTRAINT `milk_movements_ibfk_1` FOREIGN KEY (`farm_id`) REFERENCES `farms` (`farm_id`),
  ADD CONSTRAINT `milk_movements_ibfk_2` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `milk_production`
--
ALTER TABLE `milk_production`
  ADD CONSTRAINT `milk_production_ibfk_1` FOREIGN KEY (`farm_id`) REFERENCES `farms` (`farm_id`),
  ADD CONSTRAINT `milk_production_ibfk_2` FOREIGN KEY (`cow_id`) REFERENCES `cows` (`cow_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `milk_production_ibfk_3` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `users_ibfk_1` FOREIGN KEY (`role_id`) REFERENCES `roles` (`role_id`),
  ADD CONSTRAINT `users_ibfk_2` FOREIGN KEY (`farm_id`) REFERENCES `farms` (`farm_id`) ON DELETE SET NULL;

--
-- Constraints for table `vaccinations`
--
ALTER TABLE `vaccinations`
  ADD CONSTRAINT `vaccinations_ibfk_1` FOREIGN KEY (`cow_id`) REFERENCES `cows` (`cow_id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
