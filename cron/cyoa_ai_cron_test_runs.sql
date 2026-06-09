-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Mar 14, 2026 at 04:30 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `evan`
--

-- --------------------------------------------------------

--
-- Table structure for table `cyoa_ai_cron_test_runs`
--

CREATE TABLE `cyoa_ai_cron_test_runs` (
  `run_id` int(11) NOT NULL,
  `run_source` varchar(20) NOT NULL,
  `run_note` varchar(255) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `cyoa_ai_cron_test_runs`
--

INSERT INTO `cyoa_ai_cron_test_runs` (`run_id`, `run_source`, `run_note`, `created_at`) VALUES
(1, 'web', 'Cron test run', '2026-03-12 20:59:04'),
(2, 'web', 'Cron test run', '2026-03-13 20:26:48');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `cyoa_ai_cron_test_runs`
--
ALTER TABLE `cyoa_ai_cron_test_runs`
  ADD PRIMARY KEY (`run_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `cyoa_ai_cron_test_runs`
--
ALTER TABLE `cyoa_ai_cron_test_runs`
  MODIFY `run_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
