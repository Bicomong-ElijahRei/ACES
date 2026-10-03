-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: localhost    Database: aces_db
-- ------------------------------------------------------
-- Server version	10.4.32-MariaDB

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `attendance`
--

DROP TABLE IF EXISTS `attendance`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `attendance` (
  `attendance_id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` varchar(50) NOT NULL,
  `session_id` int(11) NOT NULL,
  `subtopic_id` int(11) NOT NULL,
  `attendance_date` datetime DEFAULT current_timestamp(),
  `validated` tinyint(1) DEFAULT 0,
  `auto_generated` tinyint(1) NOT NULL DEFAULT 0,
  `recorded_by` int(11) NOT NULL,
  `is_deleted` tinyint(1) DEFAULT 0,
  `attendance_status` enum('pending','present','absent') DEFAULT 'pending',
  PRIMARY KEY (`attendance_id`),
  KEY `session_id` (`session_id`),
  KEY `subtopic_id` (`subtopic_id`),
  KEY `recorded_by` (`recorded_by`),
  KEY `student_id` (`student_id`,`session_id`,`subtopic_id`),
  CONSTRAINT `attendance_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`) ON DELETE CASCADE,
  CONSTRAINT `attendance_ibfk_2` FOREIGN KEY (`session_id`) REFERENCES `sessions` (`session_id`) ON DELETE CASCADE,
  CONSTRAINT `attendance_ibfk_3` FOREIGN KEY (`subtopic_id`) REFERENCES `subtopics` (`subtopic_id`) ON DELETE CASCADE,
  CONSTRAINT `attendance_ibfk_4` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=144 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `attendance`
--

LOCK TABLES `attendance` WRITE;
/*!40000 ALTER TABLE `attendance` DISABLE KEYS */;
INSERT INTO `attendance` VALUES (80,'2023-403-001',30,40,'2026-05-03 01:37:12',1,0,20,0,'present'),(81,'2023-403-002',30,40,'2026-05-03 01:37:12',1,0,20,0,'present'),(82,'2023-410-001',30,40,'2026-05-03 01:37:12',0,0,20,0,'pending'),(83,'2023-410-003',30,41,'2026-05-03 01:37:12',0,0,20,0,'pending'),(85,'2023-403-006',30,42,'2026-05-03 01:37:12',1,0,20,0,'present'),(86,'2023-403-001',31,43,'2026-05-03 01:37:12',1,0,20,0,'present'),(87,'2023-410-004',31,44,'2026-05-03 01:37:12',1,0,20,0,'present'),(88,'2023-403-006',31,45,'2026-05-03 01:37:12',1,0,20,0,'present'),(89,'2023-403-002',32,46,'2026-05-03 01:37:12',1,0,20,0,'present'),(90,'2023-410-004',32,47,'2026-05-03 01:37:12',1,0,20,0,'present'),(91,'2023-410-002',30,40,'2026-05-03 01:37:12',0,0,20,0,'pending'),(92,'2023-403-005',30,41,'2026-05-03 01:37:12',1,0,20,0,'present'),(93,'2023-403-007',31,45,'2026-05-03 01:37:12',0,0,20,0,'absent'),(94,'2023-410-007',32,48,'2026-05-03 01:37:12',0,0,20,0,'absent'),(95,'2023-403-003',30,40,'2026-05-03 01:37:12',1,0,20,0,'present'),(96,'2023-403-008',31,45,'2026-05-03 01:37:12',0,0,20,0,'pending'),(97,'2023-410-003',32,47,'2026-05-03 01:37:12',0,0,20,0,'pending'),(99,'2023-402-02',50,50,'2026-05-04 05:19:40',0,0,20,0,'pending'),(100,'2023-403-03',50,50,'2026-05-04 05:19:40',0,0,20,0,'pending'),(101,'2023-404-04',50,50,'2026-05-04 05:19:40',0,0,20,0,'pending'),(102,'2023-405-05',50,50,'2026-05-04 05:19:40',0,0,20,0,'pending'),(103,'2023-408-08',50,51,'2026-05-04 05:19:40',0,0,20,0,'pending'),(104,'2023-409-09',50,51,'2026-05-04 05:19:40',0,0,20,0,'pending'),(105,'2023-410-10',50,51,'2026-05-04 05:19:40',0,0,20,0,'pending'),(106,'2023-414-14',50,52,'2026-05-04 05:19:40',0,0,20,0,'pending'),(107,'2023-415-15',50,52,'2026-05-04 05:19:40',0,0,20,0,'pending'),(108,'2023-401-16',50,52,'2026-05-04 05:19:40',1,0,20,0,'present'),(143,'2023-403-001',32,47,'2026-10-01 19:23:50',0,0,5,0,'pending');
/*!40000 ALTER TABLE `attendance` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `compliance`
--

DROP TABLE IF EXISTS `compliance`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `compliance` (
  `compliance_id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` varchar(50) NOT NULL,
  `session_id` int(11) NOT NULL,
  `subtopic_id` int(11) NOT NULL,
  `completion_status` enum('completed','incomplete') DEFAULT 'incomplete',
  `completion_date` datetime DEFAULT NULL,
  `updated_by` int(11) NOT NULL,
  PRIMARY KEY (`compliance_id`),
  UNIQUE KEY `unique_compliance` (`student_id`,`session_id`,`subtopic_id`),
  KEY `session_id` (`session_id`),
  KEY `subtopic_id` (`subtopic_id`),
  KEY `updated_by` (`updated_by`),
  CONSTRAINT `compliance_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`) ON DELETE CASCADE,
  CONSTRAINT `compliance_ibfk_2` FOREIGN KEY (`session_id`) REFERENCES `sessions` (`session_id`) ON DELETE CASCADE,
  CONSTRAINT `compliance_ibfk_3` FOREIGN KEY (`subtopic_id`) REFERENCES `subtopics` (`subtopic_id`) ON DELETE CASCADE,
  CONSTRAINT `compliance_ibfk_4` FOREIGN KEY (`updated_by`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `compliance`
--

LOCK TABLES `compliance` WRITE;
/*!40000 ALTER TABLE `compliance` DISABLE KEYS */;
/*!40000 ALTER TABLE `compliance` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `distribution_rules`
--

DROP TABLE IF EXISTS `distribution_rules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `distribution_rules` (
  `rule_id` int(11) NOT NULL AUTO_INCREMENT,
  `session_id` int(11) NOT NULL,
  `deadline` datetime NOT NULL,
  `max_per_subtopic` int(11) DEFAULT NULL,
  `auto_assign_enabled` tinyint(1) DEFAULT 1,
  PRIMARY KEY (`rule_id`),
  UNIQUE KEY `unique_session_rule` (`session_id`),
  CONSTRAINT `distribution_rules_ibfk_1` FOREIGN KEY (`session_id`) REFERENCES `sessions` (`session_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `distribution_rules`
--

LOCK TABLES `distribution_rules` WRITE;
/*!40000 ALTER TABLE `distribution_rules` DISABLE KEYS */;
/*!40000 ALTER TABLE `distribution_rules` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `holidays`
--

DROP TABLE IF EXISTS `holidays`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `holidays` (
  `holiday_id` int(11) NOT NULL AUTO_INCREMENT,
  `date` date NOT NULL,
  `name` varchar(100) NOT NULL,
  PRIMARY KEY (`holiday_id`),
  UNIQUE KEY `unique_holiday` (`date`)
) ENGINE=InnoDB AUTO_INCREMENT=18 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `holidays`
--

LOCK TABLES `holidays` WRITE;
/*!40000 ALTER TABLE `holidays` DISABLE KEYS */;
INSERT INTO `holidays` VALUES (1,'2026-01-01','New Year\'s Day'),(2,'2026-04-09','Araw ng Kagitingan'),(3,'2026-05-01','Labor Day'),(4,'2026-06-12','Independence Day'),(5,'2026-08-21','Ninoy Aquino Day'),(6,'2026-08-31','National Heroes Day'),(7,'2026-11-30','Bonifacio Day'),(8,'2026-12-25','Christmas Day'),(9,'2026-12-30','Rizal Day'),(17,'2026-05-14','Tech Day');
/*!40000 ALTER TABLE `holidays` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `login_attempts`
--

DROP TABLE IF EXISTS `login_attempts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `login_attempts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ip_address` varchar(45) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `attempted_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ip_time` (`ip_address`,`attempted_at`),
  KEY `idx_email_time` (`email`,`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `login_attempts`
--

LOCK TABLES `login_attempts` WRITE;
/*!40000 ALTER TABLE `login_attempts` DISABLE KEYS */;
/*!40000 ALTER TABLE `login_attempts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `login_logs`
--

DROP TABLE IF EXISTS `login_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `login_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `logged_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `login_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=63 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `login_logs`
--

LOCK TABLES `login_logs` WRITE;
/*!40000 ALTER TABLE `login_logs` DISABLE KEYS */;
INSERT INTO `login_logs` VALUES (1,5,'::1','2026-04-27 03:58:52'),(2,4,'::1','2026-04-27 04:21:04'),(3,5,'::1','2026-04-27 04:30:23'),(4,4,'::1','2026-04-28 07:04:54'),(5,5,'::1','2026-04-28 07:05:11'),(6,4,'::1','2026-04-28 07:14:33'),(7,5,'::1','2026-04-28 07:14:50'),(8,7,'::1','2026-04-28 07:28:54'),(9,5,'::1','2026-04-28 07:29:30'),(10,4,'::1','2026-04-28 07:42:28'),(11,5,'::1','2026-04-28 07:44:08'),(12,5,'::1','2026-04-28 10:58:28'),(13,5,'::1','2026-04-28 11:03:08'),(16,5,'::1','2026-04-28 11:06:59'),(18,4,'::1','2026-04-29 12:17:59'),(19,14,'::1','2026-04-29 12:22:55'),(20,5,'::1','2026-04-29 12:23:55'),(21,5,'::1','2026-04-29 16:03:32'),(22,5,'::1','2026-04-29 16:06:39'),(23,7,'::1','2026-04-29 16:06:56'),(24,5,'::1','2026-04-30 11:09:31'),(25,4,'::1','2026-04-30 11:11:06'),(26,5,'::1','2026-04-30 11:31:05'),(27,4,'::1','2026-04-30 11:39:40'),(28,7,'::1','2026-04-30 11:43:10'),(29,5,'::1','2026-05-02 21:35:23'),(30,4,'::1','2026-05-02 21:36:01'),(31,5,'::1','2026-05-02 21:36:58'),(32,5,'::1','2026-05-02 22:20:36'),(33,20,'::1','2026-05-03 01:32:05'),(34,5,'::1','2026-05-03 10:49:56'),(35,5,'::1','2026-05-03 15:23:26'),(38,4,'::1','2026-05-03 15:28:44'),(39,5,'::1','2026-05-03 15:30:03'),(40,7,'::1','2026-05-03 15:52:41'),(41,5,'::1','2026-05-03 15:53:12'),(42,22,'::1','2026-05-03 15:53:26'),(43,4,'::1','2026-05-03 22:44:10'),(44,5,'::1','2026-05-03 22:49:09'),(45,5,'::1','2026-05-04 05:21:28'),(46,5,'::1','2026-05-04 07:17:35'),(47,4,'::1','2026-05-04 07:31:42'),(48,4,'::1','2026-05-04 08:26:00'),(49,5,'::1','2026-05-04 08:30:46'),(50,5,'::1','2026-05-04 10:55:23'),(51,321,'::1','2026-05-04 11:00:39'),(52,321,'::1','2026-05-04 11:02:21'),(53,5,'::1','2026-05-04 11:17:03'),(54,5,'::1','2026-10-01 16:09:22'),(55,321,'::1','2026-10-01 16:39:18'),(56,5,'::1','2026-10-01 16:43:11'),(57,321,'::1','2026-10-02 19:23:23'),(58,5,'::1','2026-10-02 20:14:47'),(59,5,'::1','2026-10-03 09:25:31'),(60,5,'::1','2026-10-03 09:26:59'),(61,5,'::1','2026-10-03 09:28:06'),(62,5,'::1','2026-10-03 09:33:45');
/*!40000 ALTER TABLE `login_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `modules`
--

DROP TABLE IF EXISTS `modules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `modules` (
  `module_id` int(11) NOT NULL AUTO_INCREMENT,
  `subtopic_id` int(11) NOT NULL,
  `title` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `type` enum('module','assessment') NOT NULL DEFAULT 'module',
  `content` text DEFAULT NULL,
  `is_deleted` tinyint(1) DEFAULT 0,
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`module_id`),
  KEY `subtopic_id` (`subtopic_id`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `modules_ibfk_1` FOREIGN KEY (`subtopic_id`) REFERENCES `subtopics` (`subtopic_id`) ON DELETE CASCADE,
  CONSTRAINT `modules_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=83 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `modules`
--

LOCK TABLES `modules` WRITE;
/*!40000 ALTER TABLE `modules` DISABLE KEYS */;
INSERT INTO `modules` VALUES (51,9,'test upload','test upload','2026-04-08',5,'2026-04-01 14:13:49','module','file:1775024029_Quantitative Methods Lab Manual 3 (2).pdf',0,NULL),(60,47,'Stress Management Basics','Read the article and answer the quiz.','2026-05-28',20,'2026-05-03 01:37:12','module','text:Learn about stress management techniques.',0,NULL),(61,47,'Resilience Self-Assessment','Complete the self-assessment quiz.','2026-05-28',20,'2026-05-03 01:37:12','assessment','quiz:[{\"text\":\"I can bounce back from setbacks quickly.\",\"type\":\"true_false\",\"correct\":\"True\",\"points\":\"10\",\"options\":null},{\"text\":\"Which is a healthy coping strategy?\",\"type\":\"multiple_choice\",\"correct\":\"Exercise\",\"points\":\"10\",\"options\":[\"Exercise\",\"Avoidance\",\"Overeating\",\"Isolation\"]}]',0,NULL),(62,40,'test','test','2026-06-05',5,'2026-05-03 15:47:50','module','file:1777794470_attendance_19 (1).pdf',0,'2026-05-03 23:05:00'),(70,52,'Email Etiquette Guide','Read the article and answer the quiz.','2026-06-04',20,'2026-05-04 05:20:09','module','text:Learn the basics of professional email writing.',0,NULL),(71,52,'Email Etiquette Quiz','Short quiz on email best practices.','2026-06-04',20,'2026-05-04 05:20:09','assessment','quiz:[{\"text\":\"Should you use emojis in a formal business email?\",\"type\":\"true_false\",\"correct\":\"False\",\"points\":\"10\",\"options\":null},{\"text\":\"Which is the most appropriate way to start a professional email?\",\"type\":\"multiple_choice\",\"correct\":\"Dear Mr. Santos,\",\"points\":\"10\",\"options\":[\"Hey\",\"Dear Mr. Santos,\",\"Hi there!\",\"Yo\"]}]',0,NULL),(73,10,'sdsadsa','asdasdasdas','2026-10-23',5,'2026-10-01 18:16:38','assessment','quiz:[{\"text\":\"\",\"type\":\"multiple_choice\",\"correct\":\"\",\"points\":\"1\",\"options\":[\"\"]},{\"text\":\"as or ss\",\"type\":\"multiple_choice\",\"correct\":\"as\",\"points\":\"3\",\"options\":[\"as\",\"ss\",\"ss\",\"ss\",\"ss\",\"fs\"]}]',0,'2026-10-01 18:21:20'),(74,66,'TEST: Long Reading Material','Scroll to bottom to complete.','2026-10-04',5,'2026-10-02 19:37:03','module','text:This is a test module. Keep scrolling to the bottom.\r\n\r\nLorem ipsum dolor sit amet, consectetur adipiscing elit. Sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.\r\n\r\nUt enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat.\r\n\r\nDuis aute irure dolor in reprehenderit in voluptate velit esse cillum dolore eu fugiat nulla pariatur.\r\n\r\nExcepteur sint occaecat cupidatat non proident, sunt in culpa qui officia deserunt mollit anim id est laborum.\r\n\r\nLine 6\r\n\r\nLine 7\r\n\r\nLine 8\r\n\r\nLine 9\r\n\r\nLine 10\r\n\r\nLine 11\r\n\r\nLine 12\r\n\r\nLine 13\r\n\r\nLine 14\r\n\r\nLine 15\r\n\r\nThat is the end of the long test module.',0,NULL),(75,67,'TEST: Module 1 — Text','Short text','2026-10-04',5,'2026-10-02 19:37:03','module','text:This is module 1. Short content.',0,NULL),(76,67,'TEST: Module 2 — External Link','Opens example.com','2026-10-04',5,'2026-10-02 19:37:03','module','link:https://example.com',0,NULL),(77,67,'TEST: Module 3 — Text','Final text','2026-10-04',5,'2026-10-02 19:37:03','module','text:This is module 3. Complete all three to test auto-attendance.',0,NULL),(78,68,'TEST: Sample Quiz','3 questions','2026-10-04',5,'2026-10-02 19:37:03','assessment','quiz:[{\"text\":\"What is 2+2?\",\"type\":\"multiple_choice\",\"options\":[\"3\",\"4\",\"5\",\"6\"],\"correct\":\"4\",\"points\":1},{\"text\":\"The sky is blue.\",\"type\":\"true_false\",\"options\":[\"True\",\"False\"],\"correct\":\"True\",\"points\":1},{\"text\":\"Write a brief note about yourself.\",\"type\":\"short_answer\",\"correct\":\"\",\"points\":2}]',0,NULL),(79,68,'TEST: External Assessment','Google Form link','2026-10-04',5,'2026-10-02 19:37:03','assessment','link:https://forms.gle/example',0,NULL),(80,69,'TEST: Welcome Text','Intro text','2026-10-04',5,'2026-10-02 19:37:03','module','text:Welcome to this subtopic. Read and continue.',0,NULL),(81,69,'TEST: Empty Assessment','Acknowledge to mark done','2026-10-04',5,'2026-10-02 19:37:03','assessment','form:',0,NULL),(82,66,'TEST: Book-Style PDF','Click through the pages to reach the confirmation page.','2026-10-07',5,'2026-10-02 19:43:58','module','file:test-book-module.pdf',0,NULL);
/*!40000 ALTER TABLE `modules` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `registrations`
--

DROP TABLE IF EXISTS `registrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `registrations` (
  `registration_id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` varchar(50) NOT NULL,
  `session_id` int(11) NOT NULL,
  `subtopic_id` int(11) NOT NULL,
  `status` enum('pending','assigned','auto_assigned') DEFAULT 'pending',
  `registration_date` datetime DEFAULT current_timestamp(),
  `is_attended` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`registration_id`),
  UNIQUE KEY `unique_registration` (`student_id`,`session_id`,`subtopic_id`),
  KEY `session_id` (`session_id`),
  KEY `subtopic_id` (`subtopic_id`),
  CONSTRAINT `registrations_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`) ON DELETE CASCADE,
  CONSTRAINT `registrations_ibfk_2` FOREIGN KEY (`session_id`) REFERENCES `sessions` (`session_id`) ON DELETE CASCADE,
  CONSTRAINT `registrations_ibfk_3` FOREIGN KEY (`subtopic_id`) REFERENCES `subtopics` (`subtopic_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=248 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `registrations`
--

LOCK TABLES `registrations` WRITE;
/*!40000 ALTER TABLE `registrations` DISABLE KEYS */;
INSERT INTO `registrations` VALUES (146,'2023-403-001',30,40,'assigned','2026-05-03 01:37:12',0),(147,'2023-403-002',30,40,'assigned','2026-05-03 01:37:12',0),(148,'2023-403-003',30,40,'assigned','2026-05-03 01:37:12',0),(149,'2023-410-001',30,40,'assigned','2026-05-03 01:37:12',0),(150,'2023-410-002',30,40,'assigned','2026-05-03 01:37:12',0),(151,'2023-410-003',30,41,'assigned','2026-05-03 01:37:12',0),(153,'2023-403-005',30,41,'assigned','2026-05-03 01:37:12',0),(154,'2023-410-004',30,41,'assigned','2026-05-03 01:37:12',0),(155,'2023-410-005',30,41,'assigned','2026-05-03 01:37:12',0),(156,'2023-403-006',30,42,'assigned','2026-05-03 01:37:12',0),(157,'2023-403-007',30,42,'assigned','2026-05-03 01:37:12',0),(158,'2023-410-006',30,42,'assigned','2026-05-03 01:37:12',0),(159,'2023-403-008',30,42,'assigned','2026-05-03 01:37:12',0),(160,'2023-410-007',30,42,'assigned','2026-05-03 01:37:12',0),(161,'2023-403-001',31,43,'assigned','2026-05-03 01:37:12',0),(162,'2023-403-002',31,43,'assigned','2026-05-03 01:37:12',0),(163,'2023-403-003',31,43,'assigned','2026-05-03 01:37:12',0),(164,'2023-410-001',31,43,'assigned','2026-05-03 01:37:12',0),(165,'2023-410-002',31,43,'assigned','2026-05-03 01:37:12',0),(166,'2023-410-003',31,44,'assigned','2026-05-03 01:37:12',0),(168,'2023-403-005',31,44,'assigned','2026-05-03 01:37:12',0),(169,'2023-410-004',31,44,'assigned','2026-05-03 01:37:12',0),(170,'2023-410-005',31,44,'assigned','2026-05-03 01:37:12',0),(171,'2023-403-006',31,45,'assigned','2026-05-03 01:37:12',0),(172,'2023-403-007',31,45,'assigned','2026-05-03 01:37:12',0),(173,'2023-410-006',31,45,'assigned','2026-05-03 01:37:12',0),(174,'2023-403-008',31,45,'assigned','2026-05-03 01:37:12',0),(175,'2023-410-007',31,45,'assigned','2026-05-03 01:37:12',0),(176,'2023-403-001',32,46,'assigned','2026-05-03 01:37:12',0),(177,'2023-403-002',32,46,'assigned','2026-05-03 01:37:12',0),(178,'2023-403-003',32,46,'assigned','2026-05-03 01:37:12',0),(179,'2023-410-001',32,46,'assigned','2026-05-03 01:37:12',0),(180,'2023-410-002',32,46,'assigned','2026-05-03 01:37:12',0),(181,'2023-410-003',32,47,'assigned','2026-05-03 01:37:12',0),(183,'2023-403-005',32,47,'assigned','2026-05-03 01:37:12',0),(184,'2023-410-004',32,47,'assigned','2026-05-03 01:37:12',0),(185,'2023-410-005',32,47,'assigned','2026-05-03 01:37:12',0),(186,'2023-403-006',32,48,'assigned','2026-05-03 01:37:12',0),(187,'2023-403-007',32,48,'assigned','2026-05-03 01:37:12',0),(188,'2023-410-006',32,48,'assigned','2026-05-03 01:37:12',0),(189,'2023-403-008',32,48,'assigned','2026-05-03 01:37:12',0),(190,'2023-410-007',32,48,'assigned','2026-05-03 01:37:12',0),(191,'2023-401-01',50,50,'assigned','2026-05-04 05:19:34',0),(192,'2023-402-02',50,50,'assigned','2026-05-04 05:19:34',0),(193,'2023-403-03',50,50,'assigned','2026-05-04 05:19:34',0),(194,'2023-404-04',50,50,'assigned','2026-05-04 05:19:34',0),(195,'2023-405-05',50,50,'assigned','2026-05-04 05:19:34',0),(196,'2023-406-06',50,50,'assigned','2026-05-04 05:19:34',0),(197,'2023-407-07',50,50,'assigned','2026-05-04 05:19:34',0),(198,'2023-408-08',50,51,'assigned','2026-05-04 05:19:34',0),(199,'2023-409-09',50,51,'assigned','2026-05-04 05:19:34',0),(200,'2023-410-10',50,51,'assigned','2026-05-04 05:19:34',0),(201,'2023-411-11',50,51,'assigned','2026-05-04 05:19:34',0),(202,'2023-412-12',50,51,'assigned','2026-05-04 05:19:34',0),(203,'2023-413-13',50,51,'assigned','2026-05-04 05:19:34',0),(204,'2023-414-14',50,52,'assigned','2026-05-04 05:19:34',0),(205,'2023-415-15',50,52,'assigned','2026-05-04 05:19:34',0),(206,'2023-401-16',50,52,'assigned','2026-05-04 05:19:34',0),(207,'2023-402-17',50,52,'assigned','2026-05-04 05:19:34',0),(208,'2023-403-18',50,52,'assigned','2026-05-04 05:19:34',0),(209,'2023-404-19',50,52,'assigned','2026-05-04 05:19:34',0),(210,'2023-405-20',50,52,'assigned','2026-05-04 05:19:34',0),(232,'2023-2-000677',50,51,'assigned','2026-05-04 07:32:23',0),(234,'2023-2-009999',50,50,'assigned','2026-05-04 11:06:40',0),(236,'2023-403-001',32,47,'assigned','2026-10-01 19:23:50',0),(237,'2023-2-009999',53,66,'assigned','2026-10-02 19:37:03',0),(238,'2023-2-009999',53,67,'assigned','2026-10-02 19:37:03',0),(239,'2023-2-009999',53,68,'assigned','2026-10-02 19:37:03',0),(240,'2023-2-009999',53,69,'assigned','2026-10-02 19:37:03',0),(241,'2023-2-000677',59,75,'assigned','2026-10-02 19:55:30',0),(242,'2023-2-009999',58,74,'auto_assigned','2026-10-02 19:56:12',0),(244,'2023-2-009999',60,78,'assigned','2026-10-02 19:56:40',0),(245,'2023-2-009999',60,77,'assigned','2026-10-02 19:56:44',0),(247,'2023-2-009999',54,70,'assigned','2026-10-02 20:05:25',0);
/*!40000 ALTER TABLE `registrations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `remember_tokens`
--

DROP TABLE IF EXISTS `remember_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `remember_tokens` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `selector` varchar(24) NOT NULL,
  `token_hash` varchar(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_selector` (`selector`),
  KEY `idx_user` (`user_id`),
  KEY `idx_expires` (`expires_at`),
  CONSTRAINT `remember_tokens_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `remember_tokens`
--

LOCK TABLES `remember_tokens` WRITE;
/*!40000 ALTER TABLE `remember_tokens` DISABLE KEYS */;
/*!40000 ALTER TABLE `remember_tokens` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `reminders_sent`
--

DROP TABLE IF EXISTS `reminders_sent`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `reminders_sent` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `subtopic_id` int(11) NOT NULL,
  `student_id` varchar(20) NOT NULL,
  `sent_at` datetime DEFAULT current_timestamp(),
  `type` enum('auto_reminder','manual_notify') NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_reminder` (`subtopic_id`,`student_id`,`type`),
  KEY `student_id` (`student_id`),
  CONSTRAINT `reminders_sent_ibfk_1` FOREIGN KEY (`subtopic_id`) REFERENCES `subtopics` (`subtopic_id`) ON DELETE CASCADE,
  CONSTRAINT `reminders_sent_ibfk_2` FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=39 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `reminders_sent`
--

LOCK TABLES `reminders_sent` WRITE;
/*!40000 ALTER TABLE `reminders_sent` DISABLE KEYS */;
INSERT INTO `reminders_sent` VALUES (2,9,'2024','2026-04-26 06:39:39','manual_notify'),(4,9,'2023-2-000677','2026-04-26 06:39:49','manual_notify'),(6,10,'2024','2026-04-26 06:39:59','manual_notify'),(8,10,'2023-2-000677','2026-04-26 06:40:08','manual_notify'),(9,46,'2023-403-001','2026-05-03 15:41:09','manual_notify'),(10,46,'2023-403-002','2026-05-03 15:41:13','manual_notify'),(11,46,'2023-403-003','2026-05-03 15:41:18','manual_notify'),(12,46,'2023-410-001','2026-05-03 15:41:21','manual_notify'),(13,46,'2023-410-002','2026-05-03 15:41:26','manual_notify'),(14,47,'2023-410-003','2026-05-03 15:41:30','manual_notify'),(15,47,'2023-403-004','2026-05-03 15:41:34','manual_notify'),(16,47,'2023-403-005','2026-05-03 15:41:38','manual_notify'),(17,47,'2023-410-004','2026-05-03 15:41:42','manual_notify'),(18,47,'2023-410-005','2026-05-03 15:41:46','manual_notify'),(19,48,'2023-403-006','2026-05-03 15:41:50','manual_notify'),(20,48,'2023-403-007','2026-05-03 15:41:53','manual_notify'),(21,48,'2023-410-006','2026-05-03 15:41:58','manual_notify'),(22,48,'2023-403-008','2026-05-03 15:42:02','manual_notify'),(23,48,'2023-410-007','2026-05-03 15:42:07','manual_notify'),(24,43,'2023-403-001','2026-05-03 15:45:06','manual_notify'),(25,43,'2023-403-002','2026-05-03 15:45:11','manual_notify'),(26,43,'2023-403-003','2026-05-03 15:45:15','manual_notify'),(27,43,'2023-410-001','2026-05-03 15:45:20','manual_notify'),(28,43,'2023-410-002','2026-05-03 15:45:24','manual_notify'),(29,44,'2023-410-003','2026-05-03 15:45:29','manual_notify'),(30,44,'2023-403-004','2026-05-03 15:45:35','manual_notify'),(31,44,'2023-403-005','2026-05-03 15:45:39','manual_notify'),(32,44,'2023-410-004','2026-05-03 15:45:43','manual_notify'),(33,44,'2023-410-005','2026-05-03 15:45:47','manual_notify'),(34,45,'2023-403-006','2026-05-03 15:45:51','manual_notify'),(35,45,'2023-403-007','2026-05-03 15:45:55','manual_notify'),(36,45,'2023-410-006','2026-05-03 15:45:59','manual_notify'),(37,45,'2023-403-008','2026-05-03 15:46:04','manual_notify'),(38,45,'2023-410-007','2026-05-03 15:46:09','manual_notify');
/*!40000 ALTER TABLE `reminders_sent` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `school_events`
--

DROP TABLE IF EXISTS `school_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `school_events` (
  `event_id` int(11) NOT NULL AUTO_INCREMENT,
  `date` date NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  PRIMARY KEY (`event_id`),
  UNIQUE KEY `unique_event` (`date`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `school_events`
--

LOCK TABLES `school_events` WRITE;
/*!40000 ALTER TABLE `school_events` DISABLE KEYS */;
INSERT INTO `school_events` VALUES (1,'2026-04-10','Semestral Break','No classes'),(2,'2026-04-15','Career Fair','Main gymnasium'),(3,'2026-05-20','Enrollment','Online registration'),(4,'2026-06-01','Start of Classes','First day of school'),(5,'2026-06-08','Resume Writing Workshop','Mandatory'),(6,'2026-04-16','Tech Day','a blblblabla'),(7,'2026-05-18','Career Fair 2026','Annual university career fair'),(10,'2026-05-01','event','');
/*!40000 ALTER TABLE `school_events` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sessions`
--

DROP TABLE IF EXISTS `sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sessions` (
  `session_id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(100) NOT NULL,
  `phase` enum('Preparation','Pre-Employment','Career Fair') NOT NULL,
  `date` date NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `description` text DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `proctor` varchar(100) DEFAULT 'TBA',
  `location` varchar(200) DEFAULT 'TBA',
  `is_deleted` tinyint(1) DEFAULT 0,
  `allow_multiple` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`session_id`),
  KEY `created_by` (`created_by`),
  KEY `phase` (`phase`),
  CONSTRAINT `sessions_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=61 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sessions`
--

LOCK TABLES `sessions` WRITE;
/*!40000 ALTER TABLE `sessions` DISABLE KEYS */;
INSERT INTO `sessions` VALUES (5,'Resume Writing Workshop','Preparation','2026-04-07','10:00:00','12:00:00','Learn to write a professional resume',5,'Ms. Jane Proctor','Room 101',0,0),(6,'Interview Skills Seminar','Pre-Employment','2026-04-14','13:00:00','15:00:00','Master the art of interviewing',5,'Mr. John Smith','Online (Zoom)',0,0),(7,'Career Fair Prep','Career Fair','2026-04-30','09:00:00','17:00:00','Prepare for the annual career fair',5,'Dr. Emily Brown','Gymnasium',0,0),(30,'Professional Foundations (Workplace Ethics and Professionalism)','Preparation','2026-05-15','08:00:00','17:00:00','Core session covering workplace ethics and professional conduct.',20,'Ms. Ma. Fatima B. Estacion','Manny B. Villar Jr Hall',0,0),(31,'Soft Skills for Success (Effective Communication and Teamwork)','Pre-Employment','2026-05-22','08:00:00','17:00:00','Workshop on communication, teamwork, and problem-solving.',20,'Various','Multiple Rooms',0,0),(32,'Mental Readiness (Management and Workplace Wellness)','Career Fair','2026-05-29','08:00:00','17:00:00','Focus on mental health, transition to professional life, and lifelong learning.',20,'Various','Multiple Rooms',0,0),(50,'Networking Skills Workshop','Preparation','2026-06-05','08:00:00','12:00:00','Learn how to build professional networks.',20,'Ms. Ana Reyes','RM 2507',0,0),(52,'title','Pre-Employment','2026-06-05','00:00:00','00:00:00','description',5,'TBA','TBA',0,1),(53,'TEST Session — Modules Demo','Preparation','2026-10-05','09:00:00','17:00:00','Auto-generated test session for module/assessment testing.',5,'Test Proctor','Test Room 101',0,0),(54,'TEST: SC1 — Public','Preparation','2026-10-06','09:00:00','10:00:00','Public subtopic — should be visible',5,'Proctor SC1','Room 1',0,0),(55,'TEST: SC2 — Section 412 Visible','Preparation','2026-10-06','10:00:00','11:00:00','Only section 412 sees this',5,'Proctor SC2','Room 2',0,0),(56,'TEST: SC3 — Section 999 Hidden','Preparation','2026-10-06','11:00:00','12:00:00','Only section 999 sees this — HIDDEN from you',5,'Proctor SC3','Room 3',0,0),(57,'TEST: SC4 — BS Psych Visible','Preparation','2026-10-06','13:00:00','14:00:00','BS Psychology only',5,'Proctor SC4','Room 4',0,0),(58,'TEST: SC5 — Required','Preparation','2026-10-06','14:00:00','15:00:00','Required — auto-registers on page load',5,'Proctor SC5','Room 5',0,0),(59,'TEST: SC6 — Full','Preparation','2026-10-06','15:00:00','16:00:00','Full capacity — button disabled',5,'Proctor SC6','Room 6',0,0),(60,'TEST: SC7 — Multi-Registration','Preparation','2026-10-06','16:00:00','19:00:00','Register for MULTIPLE subtopics',5,'Proctor SC7','Room 7',0,1);
/*!40000 ALTER TABLE `sessions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `staff_action_log`
--

DROP TABLE IF EXISTS `staff_action_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `staff_action_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `performed_by` int(11) NOT NULL COMMENT 'user_id of the admin who did the action',
  `affected_staff_id` int(11) NOT NULL COMMENT 'user_id of the staff member affected',
  `action` varchar(50) NOT NULL COMMENT 'create, update_role, deactivate, reactivate, delete',
  `details` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `performed_by` (`performed_by`),
  KEY `affected_staff_id` (`affected_staff_id`),
  CONSTRAINT `staff_action_log_ibfk_1` FOREIGN KEY (`performed_by`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  CONSTRAINT `staff_action_log_ibfk_2` FOREIGN KEY (`affected_staff_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=22 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `staff_action_log`
--

LOCK TABLES `staff_action_log` WRITE;
/*!40000 ALTER TABLE `staff_action_log` DISABLE KEYS */;
INSERT INTO `staff_action_log` VALUES (1,5,7,'deactivate','Deactivated account','2026-04-26 08:18:38'),(10,5,7,'reactivate','Reactivated account','2026-04-26 22:53:39'),(14,5,7,'update_role','Changed role to lead','2026-04-29 16:06:48'),(16,5,7,'update_role','Changed role to admin','2026-04-30 11:10:01'),(17,5,7,'update_role','Changed role to lead','2026-04-30 11:10:08'),(18,5,7,'deactivate','Deactivated account','2026-04-30 11:10:23'),(20,5,7,'reactivate','Reactivated account','2026-05-03 15:52:28'),(21,5,322,'create','Created staff account for elijah the staff (nigzlegend@gmail.com) with role lead','2026-10-02 20:16:52');
/*!40000 ALTER TABLE `staff_action_log` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `student_module_progress`
--

DROP TABLE IF EXISTS `student_module_progress`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `student_module_progress` (
  `progress_id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` varchar(50) NOT NULL,
  `module_id` int(11) NOT NULL,
  `completed` tinyint(1) DEFAULT 0,
  `score` decimal(5,2) DEFAULT NULL,
  `completion_date` datetime DEFAULT NULL,
  PRIMARY KEY (`progress_id`),
  UNIQUE KEY `unique_progress` (`student_id`,`module_id`),
  KEY `module_id` (`module_id`),
  CONSTRAINT `student_module_progress_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`) ON DELETE CASCADE,
  CONSTRAINT `student_module_progress_ibfk_2` FOREIGN KEY (`module_id`) REFERENCES `modules` (`module_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=53 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `student_module_progress`
--

LOCK TABLES `student_module_progress` WRITE;
/*!40000 ALTER TABLE `student_module_progress` DISABLE KEYS */;
INSERT INTO `student_module_progress` VALUES (18,'2023-2-000677',51,1,NULL,'2026-04-25 04:03:39'),(38,'2023-410-003',60,1,NULL,'2026-05-25 10:00:00'),(39,'2023-410-003',61,1,100.00,'2026-05-25 10:05:00'),(40,'2023-403-004',60,1,NULL,'2026-05-26 09:30:00'),(41,'2023-403-004',61,1,80.00,'2026-05-26 09:35:00'),(42,'2023-403-005',60,1,NULL,'2026-05-27 14:00:00'),(43,'2023-410-004',61,1,90.00,'2026-05-27 15:20:00'),(44,'2023-414-14',70,1,NULL,'2026-06-03 10:00:00'),(45,'2023-414-14',71,1,100.00,'2026-06-03 10:05:00'),(46,'2023-415-15',70,1,NULL,'2026-06-03 11:00:00'),(47,'2023-415-15',71,1,80.00,'2026-06-03 11:10:00'),(48,'2023-2-009999',78,1,50.00,'2026-10-02 19:37:46'),(49,'2023-2-009999',80,1,NULL,'2026-10-02 19:38:37'),(50,'2023-2-009999',81,1,NULL,'2026-10-02 19:38:44'),(51,'2023-2-009999',75,1,NULL,'2026-10-02 19:39:22'),(52,'2023-2-009999',82,1,NULL,'2026-10-02 19:44:28');
/*!40000 ALTER TABLE `student_module_progress` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `students`
--

DROP TABLE IF EXISTS `students`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `students` (
  `student_id` varchar(50) NOT NULL,
  `user_id` int(11) NOT NULL,
  `section` varchar(50) NOT NULL,
  `program` varchar(100) NOT NULL,
  `middle_name` varchar(50) DEFAULT NULL,
  `suffix` varchar(10) DEFAULT NULL,
  `telephone` varchar(20) DEFAULT NULL,
  `mobile` varchar(20) DEFAULT NULL,
  `username` varchar(50) DEFAULT NULL,
  `is_deleted` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`student_id`),
  UNIQUE KEY `user_id` (`user_id`),
  UNIQUE KEY `username` (`username`),
  CONSTRAINT `students_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `students`
--

LOCK TABLES `students` WRITE;
/*!40000 ALTER TABLE `students` DISABLE KEYS */;
INSERT INTO `students` VALUES ('2023-2-000677',4,'401','BS Psychology','Zaragoza','asdsad','09898899988','09898899988','azogara',0),('2023-2-009999',321,'412','BS Psychology','test','test','09898899988','091236845777','testsssss',0),('2023-401-01',300,'401','BS Information Systems','','','','09171000001','angelica.santos',0),('2023-401-16',315,'401','BS Information Systems','','','','09171000016','paolo.villanueva',0),('2023-402-02',301,'402','BS Information Systems','','','','09171000002','bernardo.reyes',0),('2023-402-17',316,'402','BS Information Systems','','','','09171000017','quennie.salazar',0),('2023-403-001',100,'401','BS Information Systems','Santos',NULL,NULL,'09171234567','juan.dc',0),('2023-403-002',101,'402','BS Information Systems','Reyes',NULL,NULL,'09171234568','maria.reyes',0),('2023-403-003',102,'403','BS Information Systems','Gonzales',NULL,NULL,'09171234569','pedro.lim',0),('2023-403-004',106,'407','BS Information Systems','Aguilar',NULL,NULL,'09191234567','luis.padilla',0),('2023-403-005',107,'408','BS Information Systems','Marquez',NULL,NULL,'09191234568','sofia.d',0),('2023-403-006',110,'411','BS Information Systems','Navarro',NULL,NULL,'09201234568','rico.a',0),('2023-403-007',111,'412','BS Information Systems','Ocampo',NULL,NULL,'09201234569','gina.p',0),('2023-403-008',113,'414','BS Information Systems','Marie',NULL,NULL,'09211234568','clara.m',0),('2023-403-03',302,'403','BS Psychology','','','','09171000003','catherine.tol',0),('2023-403-18',317,'403','BS Psychology','','','','09171000018','roberto.abaya',0),('2023-404-04',303,'404','BS Psychology','','','','09171000004','daniel.mendoza',0),('2023-404-19',318,'404','BS Psychology','','','','09171000019','sofia.uy',0),('2023-405-05',304,'405','BS Information Systems','','','','09171000005','elena.garcia',0),('2023-405-20',319,'405','BS Information Systems','','','','09171000020','tomas.valencia',0),('2023-406-06',305,'406','BS Information Systems','','','','09171000006','francisco.aguilar',0),('2023-407-07',306,'407','BS Psychology','','','','09171000007','gabriela.herrera',0),('2023-408-08',307,'408','BS Psychology','','','','09171000008','hector.mercado',0),('2023-409-09',308,'409','BS Information Systems','','','','09171000009','isabel.navarro',0),('2023-410-001',103,'404','BS Psychology','Feliciano',NULL,NULL,'09181234567','anna.bautista',0),('2023-410-002',104,'405','BS Psychology','Mendoza',NULL,NULL,'09181234568','jose.v',0),('2023-410-003',105,'406','BS Information Systems','Rivera',NULL,NULL,'09181234569','carmen.s',0),('2023-410-004',108,'409','BS Psychology','Torres',NULL,NULL,'09191234569','ramon.c',0),('2023-410-005',109,'410','BS Psychology','Soriano',NULL,NULL,'09201234567','elena.h',0),('2023-410-006',112,'413','BS Information Systems','De Leon',NULL,NULL,'09211234567','benigno.d',0),('2023-410-007',114,'415','BS Psychology','Lacuna',NULL,NULL,'09211234569','andres.l',0),('2023-410-10',309,'410','BS Information Systems','','','','09171000010','jose.orcullo',0),('2023-411-11',310,'411','BS Psychology','','','','09171000011','kristine.pascual',0),('2023-412-12',311,'412','BS Information Systems','','','','09171000012','luis.quinto',0),('2023-413-13',312,'413','BS Information Systems','','','','09171000013','maria.rosario',0),('2023-414-14',313,'414','BS Psychology','','','','09171000014','nino.salazar',0),('2023-415-15',314,'415','BS Psychology','','','','09171000015','olivia.torres',0),('2024',2,'410','BS Information Systems','Zaragoza','','09898899988','09898899988','michwada3',0),('Elijah',14,'406','BS Engineering','Elijah','Elijah','','09123684557','Elijahcuh',0);
/*!40000 ALTER TABLE `students` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `subtopic_section_capacity`
--

DROP TABLE IF EXISTS `subtopic_section_capacity`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `subtopic_section_capacity` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `subtopic_id` int(11) NOT NULL,
  `section` varchar(50) NOT NULL,
  `capacity` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_subtopic_section` (`subtopic_id`,`section`),
  CONSTRAINT `subtopic_section_capacity_ibfk_1` FOREIGN KEY (`subtopic_id`) REFERENCES `subtopics` (`subtopic_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=57 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `subtopic_section_capacity`
--

LOCK TABLES `subtopic_section_capacity` WRITE;
/*!40000 ALTER TABLE `subtopic_section_capacity` DISABLE KEYS */;
INSERT INTO `subtopic_section_capacity` VALUES (9,9,'403',1),(10,9,'410',1),(11,10,'403',1),(12,10,'410',1),(21,19,'403',1),(22,19,'406',1),(23,19,'410',1),(24,20,'403',1),(25,20,'406',1),(26,20,'410',1);
/*!40000 ALTER TABLE `subtopic_section_capacity` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `subtopics`
--

DROP TABLE IF EXISTS `subtopics`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `subtopics` (
  `subtopic_id` int(11) NOT NULL AUTO_INCREMENT,
  `session_id` int(11) NOT NULL,
  `title` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `capacity` int(11) DEFAULT 30,
  `deadline` datetime DEFAULT NULL,
  `subtopic_date` date DEFAULT NULL,
  `subtopic_start_time` time DEFAULT NULL,
  `subtopic_end_time` time DEFAULT NULL,
  `subtopic_proctor` varchar(100) DEFAULT NULL,
  `subtopic_location` varchar(200) DEFAULT NULL,
  `is_required` tinyint(1) DEFAULT 0,
  `required_for` text DEFAULT NULL,
  `visible_for` text DEFAULT NULL,
  `visible_courses` text DEFAULT NULL,
  `required_courses` text DEFAULT NULL,
  `is_deleted` tinyint(1) DEFAULT 0,
  `attendance_type` enum('physical','module') DEFAULT 'physical',
  PRIMARY KEY (`subtopic_id`),
  KEY `session_id` (`session_id`),
  CONSTRAINT `subtopics_ibfk_1` FOREIGN KEY (`session_id`) REFERENCES `sessions` (`session_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=80 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `subtopics`
--

LOCK TABLES `subtopics` WRITE;
/*!40000 ALTER TABLE `subtopics` DISABLE KEYS */;
INSERT INTO `subtopics` VALUES (9,5,'Basic Resume','Create a basic resume template',2,'2026-04-03 00:00:00',NULL,NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,NULL,0,'physical'),(10,5,'Advanced Resume','Tailor resumes for specific jobs',2,'2026-04-05 00:00:00',NULL,NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,NULL,0,'physical'),(19,7,'Company Research','How to research potential employers',3,'2026-04-20 00:00:00',NULL,NULL,NULL,'','',0,NULL,'[]','[]',NULL,0,'physical'),(20,7,'Elevator Pitch','Create a 30-second pitch',3,'2026-04-22 00:00:00',NULL,NULL,NULL,'','',0,NULL,'[]','[]',NULL,0,'physical'),(40,30,'Code of Conduct in the Workplace','Understanding organizational rules and ethical behavior.',5,'2026-05-10 23:59:00',NULL,NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,NULL,0,'physical'),(41,30,'Data Privacy and Confidentiality in the Workplace','Importance of data privacy laws and confidentiality.',5,'2026-05-10 23:59:00',NULL,NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,NULL,0,'physical'),(42,30,'Professional Image and Behavior','Developing a professional image and behavior in the workplace.',5,'2026-05-10 23:59:00',NULL,NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,NULL,0,'physical'),(43,31,'Cross-Cultural and Generational Communication','Communicating effectively across cultures and age groups.',5,'2026-05-17 23:59:00',NULL,NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,NULL,0,'physical'),(44,31,'Self-Management and Accountability','Taking ownership of tasks and managing oneself.',5,'2026-05-17 23:59:00',NULL,NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,NULL,0,'physical'),(45,31,'Problem-Solving and Critical Thinking','Developing analytical and problem-solving skills.',5,'2026-05-17 23:59:00',NULL,NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,NULL,0,'physical'),(46,32,'Transitioning from Student to Professional Life','Preparing for the shift from academics to employment.',5,'2026-05-24 23:59:00',NULL,NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,NULL,0,'physical'),(47,32,'Stress Management and Resilience','Techniques for managing stress in the workplace.',5,'2026-05-24 23:59:00',NULL,NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,NULL,0,'module'),(48,32,'Growth Mindset and Lifelong Learning','Adopting a growth mindset for continuous improvement.',5,'2026-05-24 23:59:00',NULL,NULL,NULL,NULL,NULL,0,NULL,NULL,NULL,NULL,0,'physical'),(50,50,'Elevator Pitch Practice','Create and deliver a 60‑second pitch.',10,'2026-06-01 23:59:00',NULL,NULL,NULL,NULL,NULL,1,NULL,NULL,NULL,NULL,0,'physical'),(51,50,'LinkedIn Profile Optimization','Make your profile recruiter‑ready.',10,'2026-06-01 23:59:00',NULL,NULL,NULL,NULL,NULL,1,NULL,NULL,NULL,NULL,0,'physical'),(52,50,'Professional Email Etiquette','Write clear and concise business emails.',10,'2026-06-01 23:59:00',NULL,NULL,NULL,NULL,NULL,1,NULL,NULL,NULL,NULL,0,'module'),(63,6,'Answering Difficult Questions','Learn to handle tricky interview questions',2,'2026-04-12 00:00:00',NULL,NULL,NULL,'','',0,'[]','[]','[]','[]',0,'physical'),(66,53,'TEST: Physical Attendance','Attendance marked by staff.',50,'2026-10-04 19:37:03','2026-10-05','09:00:00','11:00:00','Test Proctor','Room 101',0,NULL,NULL,NULL,NULL,0,'physical'),(67,53,'TEST: Module-Based','Complete all modules for auto-attendance.',50,'2026-10-04 19:37:03','2026-10-05','11:00:00','13:00:00','Test Proctor','Room 102',0,NULL,NULL,NULL,NULL,0,'module'),(68,53,'TEST: Assessments (Quiz + Link)','Quiz and external link.',50,'2026-10-04 19:37:03','2026-10-05','14:00:00','16:00:00','Test Proctor','Room 103',0,NULL,NULL,NULL,NULL,0,'physical'),(69,53,'TEST: Mixed Content','Text + empty assessment.',50,'2026-10-04 19:37:03','2026-10-05','16:00:00','18:00:00','Test Proctor','Room 104',0,NULL,NULL,NULL,NULL,0,'physical'),(70,54,'SC1: Public Subtopic','Visible to everyone',10,'2026-10-05 19:55:29','2026-10-06','09:00:00','10:00:00','Proctor SC1','Room 1',0,NULL,NULL,NULL,NULL,0,'physical'),(71,55,'SC2: Section 412 Only','You SHOULD see this',10,'2026-10-05 19:55:29','2026-10-06','10:00:00','11:00:00','Proctor SC2','Room 2',0,NULL,'[\"412\"]',NULL,NULL,0,'physical'),(72,56,'SC3: Hidden From You','You should NOT see this',10,'2026-10-05 19:55:29','2026-10-06','11:00:00','12:00:00','Proctor SC3','Room 3',0,NULL,'[\"999\"]',NULL,NULL,0,'physical'),(73,57,'SC4: BS Psych Only','You SHOULD see this (program match)',10,'2026-10-05 19:55:30','2026-10-06','13:00:00','14:00:00','Proctor SC4','Room 4',0,NULL,NULL,'[\"BS Psychology\"]',NULL,0,'physical'),(74,58,'SC5: Required for BS Psych','Auto-registers you on page load',10,'2026-10-05 19:55:30','2026-10-06','14:00:00','15:00:00','Proctor SC5','Room 5',1,NULL,NULL,NULL,'[\"BS Psychology\"]',0,'physical'),(75,59,'SC6: Full Subtopic','Capacity is 1, already filled',1,'2026-10-05 19:55:30','2026-10-06','15:00:00','16:00:00','Proctor SC6','Room 6',0,NULL,NULL,NULL,NULL,0,'physical'),(76,60,'SC7a: Multi Option A','Pick A',10,'2026-10-05 19:55:30','2026-10-06','16:00:00','17:00:00','Proctor SC7','Room 7',0,NULL,NULL,NULL,NULL,0,'physical'),(77,60,'SC7b: Multi Option B','Pick B',10,'2026-10-05 19:55:30','2026-10-06','17:00:00','18:00:00','Proctor SC7','Room 7',0,NULL,NULL,NULL,NULL,0,'physical'),(78,60,'SC7c: Multi Option C','Pick C',10,'2026-10-05 19:55:30','2026-10-06','18:00:00','19:00:00','Proctor SC7','Room 7',0,NULL,NULL,NULL,NULL,0,'physical'),(79,54,'SC1b: Second Subtopic','Test single-per-session',10,'2026-10-05 19:58:15','2026-10-06','10:00:00','11:00:00','Proctor','Room 1',0,NULL,NULL,NULL,NULL,0,'physical');
/*!40000 ALTER TABLE `subtopics` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `user_id` int(11) NOT NULL AUTO_INCREMENT,
  `email` varchar(100) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `is_verified` tinyint(1) NOT NULL DEFAULT 0,
  `verification_token` varchar(64) DEFAULT NULL,
  `verification_expires` datetime DEFAULT NULL,
  `reset_token` varchar(64) DEFAULT NULL,
  `reset_expires` datetime DEFAULT NULL,
  `full_name` varchar(100) NOT NULL,
  `role` enum('student','staff') NOT NULL,
  `staff_role` enum('admin','lead','viewer') DEFAULT 'viewer',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `email` (`email`),
  KEY `idx_staff_role` (`staff_role`)
) ENGINE=InnoDB AUTO_INCREMENT=323 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (2,'erzbicoomonng@kld.edu.ph','$2y$10$WekqBk623mbwvzTCPCLSBOInDIT879.C8wEHF4s.nZzKRYn/XI1Lu',1,NULL,NULL,NULL,NULL,'Elili Zaragoza Bibico','student','viewer',1,NULL,'2026-03-30 11:44:53'),(4,'4zogara@gmail.com','$2y$10$jcNvSeSZ1CdTKuAFD4X3fek6u1xYb9qFKROSyTmI0KC4c50tmO1BC',1,NULL,NULL,NULL,NULL,'Elili Bibico Zaragoza ahdsad','student','viewer',1,NULL,'2026-03-30 14:17:12'),(5,'staff@aces.edu','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',0,NULL,NULL,NULL,NULL,'ACES Staff','staff','admin',1,NULL,'2026-03-31 13:22:00'),(7,'another.staff@kld.edu','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',0,NULL,NULL,NULL,NULL,'Staff Name','staff','lead',1,NULL,'2026-03-31 23:32:24'),(14,'elijah4tfolio@gmail.com','$2y$10$41F8l/6pZa/KB5C30XhmSe2Z/4tPlEpbRzuHsnVKrS04sU55X4ki2',1,'b589df0bda23ddd08680eebb8ab6ecedc8fea35a9197c63870a203f6a2c881ff',NULL,NULL,NULL,'Elijah Elijah Elijah Elijah','student','viewer',1,NULL,'2026-04-27 03:44:01'),(20,'adminstaff@aces.edu','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',1,NULL,NULL,NULL,NULL,'ACES Admin','staff','admin',1,NULL,'2026-05-03 01:29:20'),(21,'lead.staff@kld.edu.ph','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',1,NULL,NULL,NULL,NULL,'Lead Staff Name','staff','lead',1,20,'2026-05-03 01:29:20'),(22,'viewer.staff@kld.edu.ph','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',1,NULL,NULL,NULL,NULL,'Viewer Staff Name','staff','viewer',1,20,'2026-05-03 01:29:20'),(100,'jSdelacruz@kld.edu.ph','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',1,NULL,NULL,NULL,NULL,'Juan Santos Dela Cruz','student','viewer',1,NULL,'2026-05-03 01:29:20'),(101,'mRreyes@kld.edu.ph','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',1,NULL,NULL,NULL,NULL,'Maria Reyes Reyes','student','viewer',1,NULL,'2026-05-03 01:29:20'),(102,'pGlim@kld.edu.ph','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',1,NULL,NULL,NULL,NULL,'Pedro Gonzales Lim','student','viewer',1,NULL,'2026-05-03 01:29:20'),(103,'aFbautista@kld.edu.ph','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',1,NULL,NULL,NULL,NULL,'Anna Feliciano Bautista','student','viewer',1,NULL,'2026-05-03 01:29:20'),(104,'jMvillanueva@kld.edu.ph','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',1,NULL,NULL,NULL,NULL,'Jose Mendoza Villanueva','student','viewer',1,NULL,'2026-05-03 01:29:20'),(105,'cRsalazar@kld.edu.ph','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',1,NULL,NULL,NULL,NULL,'Carmen Rivera Salazar','student','viewer',1,NULL,'2026-05-03 01:29:20'),(106,'lApadilla@kld.edu.ph','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',1,NULL,NULL,NULL,NULL,'Luis Aguilar Padilla','student','viewer',1,NULL,'2026-05-03 01:29:20'),(107,'sMdomingo@kld.edu.ph','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',1,NULL,NULL,NULL,NULL,'Sofia Marquez Domingo','student','viewer',1,NULL,'2026-05-03 01:29:20'),(108,'rTcastro@kld.edu.ph','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',1,NULL,NULL,NULL,NULL,'Ramon Torres Castro','student','viewer',1,NULL,'2026-05-03 01:29:20'),(109,'eShernandez@kld.edu.ph','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',1,NULL,NULL,NULL,NULL,'Elena Soriano Hernandez','student','viewer',1,NULL,'2026-05-03 01:29:20'),(110,'rNaquino@kld.edu.ph','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',1,NULL,NULL,NULL,NULL,'Rico Navarro Aquino','student','viewer',1,NULL,'2026-05-03 01:29:20'),(111,'gOpascual@kld.edu.ph','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',1,NULL,NULL,NULL,NULL,'Gina Ocampo Pascual','student','viewer',1,NULL,'2026-05-03 01:29:20'),(112,'bDeleon@kld.edu.ph','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',1,NULL,NULL,NULL,NULL,'Benigno De Leon','student','viewer',1,NULL,'2026-05-03 01:29:20'),(113,'cFmiranda@kld.edu.ph','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',1,NULL,NULL,NULL,NULL,'Clara Flores Marie Miranda','student','viewer',1,NULL,'2026-05-03 01:29:20'),(114,'aLacuna@kld.edu.ph','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',1,NULL,NULL,NULL,NULL,'Andres Lacuna','student','viewer',1,NULL,'2026-05-03 01:29:20'),(300,'aBsantos@kld.edu.ph','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',1,NULL,NULL,NULL,NULL,'Angelica Benitez Santos','student','viewer',1,NULL,'2026-05-04 04:58:32'),(301,'bCreyes@kld.edu.ph','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',1,NULL,NULL,NULL,NULL,'Bernardo Cruz Reyes','student','viewer',1,NULL,'2026-05-04 04:58:32'),(302,'cDtolentino@kld.edu.ph','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',1,NULL,NULL,NULL,NULL,'Catherine Domingo Tolentino','student','viewer',1,NULL,'2026-05-04 04:58:32'),(303,'dEmendoza@kld.edu.ph','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',1,NULL,NULL,NULL,NULL,'Daniel Escobar Mendoza','student','viewer',1,NULL,'2026-05-04 04:58:32'),(304,'eFgarcia@kld.edu.ph','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',1,NULL,NULL,NULL,NULL,'Elena Flores Garcia','student','viewer',1,NULL,'2026-05-04 04:58:32'),(305,'fGaguilar@kld.edu.ph','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',1,NULL,NULL,NULL,NULL,'Francisco Gonzales Aguilar','student','viewer',1,NULL,'2026-05-04 04:58:32'),(306,'gHherrera@kld.edu.ph','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',1,NULL,NULL,NULL,NULL,'Gabriela Hernandez Herrera','student','viewer',1,NULL,'2026-05-04 04:58:32'),(307,'hImercado@kld.edu.ph','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',1,NULL,NULL,NULL,NULL,'Hector Ignacio Mercado','student','viewer',1,NULL,'2026-05-04 04:58:32'),(308,'iJnavarro@kld.edu.ph','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',1,NULL,NULL,NULL,NULL,'Isabel Javier Navarro','student','viewer',1,NULL,'2026-05-04 04:58:32'),(309,'jKorcullo@kld.edu.ph','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',1,NULL,NULL,NULL,NULL,'Jose Karlo Orcullo','student','viewer',1,NULL,'2026-05-04 04:58:32'),(310,'kLpascual@kld.edu.ph','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',1,NULL,NULL,NULL,NULL,'Kristine Leyva Pascual','student','viewer',1,NULL,'2026-05-04 04:58:32'),(311,'lMquinto@kld.edu.ph','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',1,NULL,NULL,NULL,NULL,'Luis Miguel Quinto','student','viewer',1,NULL,'2026-05-04 04:58:32'),(312,'mNrosario@kld.edu.ph','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',1,NULL,NULL,NULL,NULL,'Maria Nelia Rosario','student','viewer',1,NULL,'2026-05-04 04:58:32'),(313,'nOsalazar@kld.edu.ph','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',1,NULL,NULL,NULL,NULL,'Niño Orencio Salazar','student','viewer',1,NULL,'2026-05-04 04:58:32'),(314,'oPtorres@kld.edu.ph','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',1,NULL,NULL,NULL,NULL,'Olivia Pauline Torres','student','viewer',1,NULL,'2026-05-04 04:58:32'),(315,'pQvillanueva@kld.edu.ph','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',1,NULL,NULL,NULL,NULL,'Paolo Quirino Villanueva','student','viewer',1,NULL,'2026-05-04 04:58:32'),(316,'qRysalazar@kld.edu.ph','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',1,NULL,NULL,NULL,NULL,'Quennie Reyes Salazar','student','viewer',1,NULL,'2026-05-04 04:58:32'),(317,'rSabaya@kld.edu.ph','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',1,NULL,NULL,NULL,NULL,'Roberto Santos Abaya','student','viewer',1,NULL,'2026-05-04 04:58:32'),(318,'sTuyo@kld.edu.ph','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',1,NULL,NULL,NULL,NULL,'Sofia Tiu Uy','student','viewer',1,NULL,'2026-05-04 04:58:32'),(319,'tUvalencia@kld.edu.ph','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',1,NULL,NULL,NULL,NULL,'Tomas Uy Valencia','student','viewer',1,NULL,'2026-05-04 04:58:32'),(321,'erzbicomong@kld.edu.ph','$2y$12$llY57iH.sN6WxIq4WX.e4OxBLpkxinRZiwBmTgeGtX2EWGmyeW.yK',1,NULL,NULL,NULL,NULL,'test test test test','student','viewer',1,NULL,'2026-05-04 10:56:37'),(322,'nigzlegend@gmail.com','$2y$10$FvFa4A5pTZ.83gImlt2jIerLkIi33T/y6izzcWUGfCpmhNMexKQ8u',1,NULL,NULL,NULL,NULL,'elijah the staff','staff','lead',1,5,'2026-10-02 20:16:52');
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping events for database 'aces_db'
--

--
-- Dumping routines for database 'aces_db'
--
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-10-03 12:18:10
