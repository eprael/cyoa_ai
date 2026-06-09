-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jun 04, 2026 at 06:39 AM
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
-- Table structure for table `cyoa_ai_choices`
--

CREATE TABLE `cyoa_ai_choices` (
  `choiceID` int(11) NOT NULL,
  `sceneID` int(11) NOT NULL,
  `choiceText` varchar(255) NOT NULL,
  `destinationID` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `cyoa_ai_comments`
--

CREATE TABLE `cyoa_ai_comments` (
  `comment_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `story_id` int(11) NOT NULL,
  `comment` text NOT NULL,
  `reply_to_comment_id` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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

-- --------------------------------------------------------

--
-- Table structure for table `cyoa_ai_favorites`
--

CREATE TABLE `cyoa_ai_favorites` (
  `favorite_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `story_id` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `cyoa_ai_jobs`
--

CREATE TABLE `cyoa_ai_jobs` (
  `job_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `story_id` int(11) DEFAULT NULL,
  `scene_id` int(11) DEFAULT NULL,
  `job_type` enum('image','scene','story') NOT NULL,
  `status` enum('pending','running','completed','failed','cancelled','completed_with_errors') NOT NULL DEFAULT 'pending',
  `input_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`input_json`)),
  `result_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`result_json`)),
  `error_message` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `started_at` datetime DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `completed_at` datetime DEFAULT NULL,
  `seen_at` datetime DEFAULT NULL,
  `parent_job_id` int(11) DEFAULT NULL,
  `input_tokens` int(11) DEFAULT NULL,
  `output_tokens` int(11) DEFAULT NULL,
  `image_count` int(11) DEFAULT NULL,
  `cost_usd` decimal(10,6) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `cyoa_ai_password_resets`
--

CREATE TABLE `cyoa_ai_password_resets` (
  `id` int(11) NOT NULL,
  `email` varchar(256) NOT NULL,
  `token` varchar(255) NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `cyoa_ai_ratings`
--

CREATE TABLE `cyoa_ai_ratings` (
  `rating_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `story_id` int(11) NOT NULL,
  `rating` tinyint(4) NOT NULL CHECK (`rating` between 1 and 5),
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `cyoa_ai_scenes`
--

CREATE TABLE `cyoa_ai_scenes` (
  `sceneID` int(11) NOT NULL,
  `storyID` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` varchar(2048) NOT NULL,
  `image` varchar(255) DEFAULT NULL,
  `image_gen` varchar(2048) DEFAULT NULL,
  `hint` varchar(512) DEFAULT NULL,
  `enable_autoBack_nav` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `cyoa_ai_settings`
--

CREATE TABLE `cyoa_ai_settings` (
  `setting_key` varchar(64) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `cyoa_ai_stories`
--

CREATE TABLE `cyoa_ai_stories` (
  `storyID` int(11) NOT NULL,
  `title` varchar(128) NOT NULL,
  `description` varchar(512) NOT NULL,
  `genre` text DEFAULT NULL,
  `ai_image_category` varchar(50) DEFAULT NULL,
  `ai_image_style` varchar(100) DEFAULT NULL,
  `ai_image_mood` varchar(100) DEFAULT NULL,
  `ai_image_quality` varchar(10) DEFAULT NULL,
  `image` varchar(255) NOT NULL,
  `theme` varchar(255) NOT NULL,
  `theme_json` text DEFAULT NULL,
  `layout` varchar(100) NOT NULL,
  `userID` int(11) NOT NULL,
  `created_by` varchar(255) NOT NULL,
  `date_created` date NOT NULL DEFAULT current_timestamp(),
  `status` enum('published','draft','deleted') NOT NULL DEFAULT 'draft',
  `date_deleted` datetime DEFAULT NULL,
  `published_story_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `cyoa_ai_themes`
--

CREATE TABLE `cyoa_ai_themes` (
  `theme_id` int(11) NOT NULL,
  `name` varchar(80) NOT NULL,
  `slug` varchar(80) NOT NULL,
  `theme_json` text NOT NULL,
  `is_preset` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `cyoa_ai_users`
--

CREATE TABLE `cyoa_ai_users` (
  `userID` int(11) NOT NULL,
  `firstName` varchar(128) NOT NULL,
  `lastName` varchar(128) NOT NULL,
  `email` varchar(256) NOT NULL,
  `claude_api_key` varchar(255) DEFAULT NULL,
  `openai_api_key` varchar(255) DEFAULT NULL,
  `openai_image_quality` varchar(10) DEFAULT NULL,
  `password` varchar(256) NOT NULL,
  `profileImage` varchar(1024) NOT NULL,
  `isAdmin` int(11) NOT NULL DEFAULT 0,
  `created_date` date NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `cyoa_ai_views`
--

CREATE TABLE `cyoa_ai_views` (
  `view_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `story_id` int(11) NOT NULL,
  `viewed_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `cyoa_ai_choices`
--
ALTER TABLE `cyoa_ai_choices`
  ADD PRIMARY KEY (`choiceID`),
  ADD KEY `storypointID` (`sceneID`);

--
-- Indexes for table `cyoa_ai_comments`
--
ALTER TABLE `cyoa_ai_comments`
  ADD PRIMARY KEY (`comment_id`),
  ADD KEY `idx_story_id` (`story_id`),
  ADD KEY `idx_reply_to` (`reply_to_comment_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `cyoa_ai_cron_test_runs`
--
ALTER TABLE `cyoa_ai_cron_test_runs`
  ADD PRIMARY KEY (`run_id`);

--
-- Indexes for table `cyoa_ai_favorites`
--
ALTER TABLE `cyoa_ai_favorites`
  ADD PRIMARY KEY (`favorite_id`),
  ADD UNIQUE KEY `uq_user_story` (`user_id`,`story_id`),
  ADD KEY `story_id` (`story_id`);

--
-- Indexes for table `cyoa_ai_jobs`
--
ALTER TABLE `cyoa_ai_jobs`
  ADD PRIMARY KEY (`job_id`),
  ADD KEY `idx_status_created` (`status`,`created_at`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_story_id` (`story_id`),
  ADD KEY `scene_id` (`scene_id`),
  ADD KEY `idx_parent_job_id` (`parent_job_id`);

--
-- Indexes for table `cyoa_ai_password_resets`
--
ALTER TABLE `cyoa_ai_password_resets`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `cyoa_ai_ratings`
--
ALTER TABLE `cyoa_ai_ratings`
  ADD PRIMARY KEY (`rating_id`),
  ADD UNIQUE KEY `uq_user_story` (`user_id`,`story_id`),
  ADD KEY `story_id` (`story_id`);

--
-- Indexes for table `cyoa_ai_scenes`
--
ALTER TABLE `cyoa_ai_scenes`
  ADD PRIMARY KEY (`sceneID`);

--
-- Indexes for table `cyoa_ai_settings`
--
ALTER TABLE `cyoa_ai_settings`
  ADD PRIMARY KEY (`setting_key`);

--
-- Indexes for table `cyoa_ai_stories`
--
ALTER TABLE `cyoa_ai_stories`
  ADD PRIMARY KEY (`storyID`),
  ADD KEY `idx_published_story_id` (`published_story_id`);

--
-- Indexes for table `cyoa_ai_themes`
--
ALTER TABLE `cyoa_ai_themes`
  ADD PRIMARY KEY (`theme_id`),
  ADD UNIQUE KEY `slug` (`slug`);

--
-- Indexes for table `cyoa_ai_users`
--
ALTER TABLE `cyoa_ai_users`
  ADD PRIMARY KEY (`userID`);

--
-- Indexes for table `cyoa_ai_views`
--
ALTER TABLE `cyoa_ai_views`
  ADD PRIMARY KEY (`view_id`),
  ADD KEY `idx_story_id` (`story_id`),
  ADD KEY `user_id` (`user_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `cyoa_ai_choices`
--
ALTER TABLE `cyoa_ai_choices`
  MODIFY `choiceID` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `cyoa_ai_comments`
--
ALTER TABLE `cyoa_ai_comments`
  MODIFY `comment_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `cyoa_ai_cron_test_runs`
--
ALTER TABLE `cyoa_ai_cron_test_runs`
  MODIFY `run_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `cyoa_ai_favorites`
--
ALTER TABLE `cyoa_ai_favorites`
  MODIFY `favorite_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `cyoa_ai_jobs`
--
ALTER TABLE `cyoa_ai_jobs`
  MODIFY `job_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `cyoa_ai_password_resets`
--
ALTER TABLE `cyoa_ai_password_resets`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `cyoa_ai_ratings`
--
ALTER TABLE `cyoa_ai_ratings`
  MODIFY `rating_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `cyoa_ai_scenes`
--
ALTER TABLE `cyoa_ai_scenes`
  MODIFY `sceneID` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `cyoa_ai_stories`
--
ALTER TABLE `cyoa_ai_stories`
  MODIFY `storyID` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `cyoa_ai_themes`
--
ALTER TABLE `cyoa_ai_themes`
  MODIFY `theme_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `cyoa_ai_users`
--
ALTER TABLE `cyoa_ai_users`
  MODIFY `userID` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `cyoa_ai_views`
--
ALTER TABLE `cyoa_ai_views`
  MODIFY `view_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `cyoa_ai_comments`
--
ALTER TABLE `cyoa_ai_comments`
  ADD CONSTRAINT `cyoa_ai_comments_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `cyoa_ai_users` (`userID`) ON DELETE CASCADE,
  ADD CONSTRAINT `cyoa_ai_comments_ibfk_2` FOREIGN KEY (`story_id`) REFERENCES `cyoa_ai_stories` (`storyID`) ON DELETE CASCADE,
  ADD CONSTRAINT `cyoa_ai_comments_ibfk_3` FOREIGN KEY (`reply_to_comment_id`) REFERENCES `cyoa_ai_comments` (`comment_id`) ON DELETE CASCADE;

--
-- Constraints for table `cyoa_ai_favorites`
--
ALTER TABLE `cyoa_ai_favorites`
  ADD CONSTRAINT `cyoa_ai_favorites_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `cyoa_ai_users` (`userID`) ON DELETE CASCADE,
  ADD CONSTRAINT `cyoa_ai_favorites_ibfk_2` FOREIGN KEY (`story_id`) REFERENCES `cyoa_ai_stories` (`storyID`) ON DELETE CASCADE;

--
-- Constraints for table `cyoa_ai_jobs`
--
ALTER TABLE `cyoa_ai_jobs`
  ADD CONSTRAINT `cyoa_ai_jobs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `cyoa_ai_users` (`userID`) ON DELETE CASCADE,
  ADD CONSTRAINT `cyoa_ai_jobs_ibfk_2` FOREIGN KEY (`story_id`) REFERENCES `cyoa_ai_stories` (`storyID`) ON DELETE SET NULL,
  ADD CONSTRAINT `cyoa_ai_jobs_ibfk_3` FOREIGN KEY (`scene_id`) REFERENCES `cyoa_ai_scenes` (`sceneID`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_cyoa_ai_jobs_parent` FOREIGN KEY (`parent_job_id`) REFERENCES `cyoa_ai_jobs` (`job_id`) ON DELETE SET NULL;

--
-- Constraints for table `cyoa_ai_ratings`
--
ALTER TABLE `cyoa_ai_ratings`
  ADD CONSTRAINT `cyoa_ai_ratings_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `cyoa_ai_users` (`userID`) ON DELETE CASCADE,
  ADD CONSTRAINT `cyoa_ai_ratings_ibfk_2` FOREIGN KEY (`story_id`) REFERENCES `cyoa_ai_stories` (`storyID`) ON DELETE CASCADE;

--
-- Constraints for table `cyoa_ai_stories`
--
ALTER TABLE `cyoa_ai_stories`
  ADD CONSTRAINT `cyoa_ai_stories_ibfk_1` FOREIGN KEY (`published_story_id`) REFERENCES `cyoa_ai_stories` (`storyID`) ON DELETE CASCADE;

--
-- Constraints for table `cyoa_ai_views`
--
ALTER TABLE `cyoa_ai_views`
  ADD CONSTRAINT `cyoa_ai_views_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `cyoa_ai_users` (`userID`) ON DELETE SET NULL,
  ADD CONSTRAINT `cyoa_ai_views_ibfk_2` FOREIGN KEY (`story_id`) REFERENCES `cyoa_ai_stories` (`storyID`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
