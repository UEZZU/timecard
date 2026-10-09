-- Adminer 6.1.1 MariaDB 11.8.6-MariaDB-0+deb13u1 from Debian dump

SET NAMES utf8;
SET time_zone = '+00:00';
SET foreign_key_checks = 0;
SET sql_mode = 'NO_AUTO_VALUE_ON_ZERO';

SET NAMES utf8mb4;

DROP TABLE IF EXISTS `cars`;
CREATE TABLE `cars` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `plate` varchar(32) NOT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `plate` (`plate`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `cars` (`id`, `plate`, `active`, `sort_order`) VALUES
(1,	'沖縄330 も 2400',	1,	1),
(2,	'沖縄330 ぬ 1190',	1,	2),
(3,	'沖縄530 せ 850',	1,	3),
(4,	'沖縄534 ま 1217',	1,	4),
(5,	'沖縄330 り 1190',	1,	5),
(6,	'沖縄502 す 8587',	1,	6),
(7,	'沖縄480 つ 8132',	1,	7),
(8,	'沖縄480 な 9491',	1,	8),
(9,	'沖縄480 な 9492',	1,	9),
(10,	'沖縄483 ま 101',	1,	10),
(11,	'沖縄580 ま 2652',	1,	11),
(12,	'沖縄480 ぬ 2129',	1,	12),
(13,	'沖縄480ね4964',	1,	13);

DROP TABLE IF EXISTS `cash_logs`;
CREATE TABLE `cash_logs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `shop` varchar(8) NOT NULL,
  `cash_date` date NOT NULL,
  `amount` int(10) unsigned NOT NULL,
  `noted_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_date_shop` (`user_id`,`cash_date`,`shop`),
  KEY `idx_user` (`user_id`,`noted_at`),
  KEY `shop` (`shop`),
  CONSTRAINT `cash_logs_ibfk_1` FOREIGN KEY (`shop`) REFERENCES `shops` (`code`) ON UPDATE CASCADE,
  CONSTRAINT `fk_cash_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `daily_lunch`;
CREATE TABLE `daily_lunch` (
  `user_id` int(10) unsigned NOT NULL,
  `punch_date` date NOT NULL,
  `lunch` enum('yes','no') NOT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`user_id`,`punch_date`),
  CONSTRAINT `fk_lunch_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `drive_logs`;
CREATE TABLE `drive_logs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `drv_date` date NOT NULL,
  `car_id` int(10) unsigned NOT NULL,
  `pre_value` decimal(4,2) DEFAULT NULL,
  `pre_time` time DEFAULT NULL,
  `pre_method` tinyint(4) DEFAULT NULL,
  `pre_result` tinyint(4) NOT NULL DEFAULT 0,
  `post_value` decimal(4,2) DEFAULT NULL,
  `post_time` time DEFAULT NULL,
  `post_method` tinyint(4) DEFAULT NULL,
  `post_result` tinyint(4) NOT NULL DEFAULT 0,
  `noted_at` timestamp NULL DEFAULT current_timestamp(),
  `notes` text DEFAULT NULL COMMENT 'その他の指示事項',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_date_car` (`user_id`,`drv_date`,`car_id`),
  KEY `idx_user` (`user_id`,`drv_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `punches`;
CREATE TABLE `punches` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `punch_date` date NOT NULL,
  `punch_type` enum('clock_in','leave_out','leave_in','clock_out') NOT NULL,
  `punch_time` time NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_date_type` (`user_id`,`punch_date`,`punch_type`),
  KEY `idx_user_date` (`user_id`,`punch_date`),
  CONSTRAINT `fk_punch_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `shops`;
CREATE TABLE `shops` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(8) NOT NULL,
  `name` varchar(64) NOT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `shops` (`id`, `code`, `name`, `active`, `sort_order`) VALUES
(1,	'KK',	'国際通り店',	1,	1),
(2,	'MH',	'美浜店',	1,	2),
(3,	'C',	'店舗 C',	0,	3),
(4,	'NG',	'名護店',	1,	4),
(5,	'MD',	'みどり町店',	1,	5);

DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(64) NOT NULL,
  `cash_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `remote_punch` tinyint(1) NOT NULL DEFAULT 0,
  `drive_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `default_car_id` int(10) unsigned DEFAULT NULL,
  `token` varchar(64) NOT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`),
  KEY `default_car_id` (`default_car_id`),
  CONSTRAINT `users_ibfk_1` FOREIGN KEY (`default_car_id`) REFERENCES `cars` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- 2026-10-09 00:41:58 UTC
