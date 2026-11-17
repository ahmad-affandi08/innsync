/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `access_position_roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `access_position_roles` (
  `property_id` char(26) NOT NULL,
  `position_key` varchar(80) NOT NULL,
  `position` varchar(80) NOT NULL,
  `role_id` char(26) NOT NULL,
  `updated_by` char(26) NOT NULL,
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`property_id`,`position_key`),
  KEY `access_position_roles_role_id_foreign` (`role_id`),
  CONSTRAINT `access_position_roles_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `access_position_roles_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `activity_bookings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `activity_bookings` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `number` varchar(20) NOT NULL,
  `service_id` char(26) NOT NULL,
  `starts_on` date NOT NULL,
  `starts_at` char(5) NOT NULL,
  `duration_minutes` smallint unsigned NOT NULL,
  `guests` tinyint unsigned NOT NULL,
  `reservation_id` char(26) NOT NULL,
  `guest_name` varchar(120) NOT NULL,
  `service_name` varchar(120) NOT NULL,
  `price_minor` bigint unsigned NOT NULL,
  `status` varchar(9) NOT NULL DEFAULT 'booked',
  `note` varchar(200) DEFAULT NULL,
  `reason` varchar(200) DEFAULT NULL,
  `posting_id` char(26) DEFAULT NULL,
  `created_by` char(26) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `activity_bookings_property_id_number_unique` (`property_id`,`number`),
  KEY `activity_bookings_property_id_service_id_starts_on_index` (`property_id`,`service_id`,`starts_on`),
  KEY `activity_bookings_property_id_reservation_id_index` (`property_id`,`reservation_id`),
  CONSTRAINT `activity_bookings_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_activity_bookings` CHECK (((`status` in (_utf8mb4'booked',_utf8mb4'done',_utf8mb4'cancelled',_utf8mb4'no_show')) and (`guests` between 1 and 50) and ((`status` <> _utf8mb4'cancelled') or (`reason` is not null))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `activity_bookings_guard` BEFORE UPDATE ON `activity_bookings` FOR EACH ROW BEGIN IF NEW.price_minor <> OLD.price_minor OR NEW.service_id <> OLD.service_id OR NEW.reservation_id <> OLD.reservation_id OR NEW.starts_on <> OLD.starts_on OR NEW.starts_at <> OLD.starts_at OR NEW.guests <> OLD.guests OR OLD.status <> 'booked' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a settled activity booking cannot be changed'; END IF; END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `activity_bookings_no_delete` BEFORE DELETE ON `activity_bookings` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'an activity booking cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `activity_services`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `activity_services` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `code` varchar(20) NOT NULL,
  `name` varchar(120) NOT NULL,
  `category` varchar(8) NOT NULL,
  `duration_minutes` smallint unsigned NOT NULL,
  `price_minor` bigint unsigned NOT NULL,
  `capacity` smallint unsigned NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `activity_services_property_id_code_unique` (`property_id`,`code`),
  CONSTRAINT `activity_services_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_activity_services` CHECK (((`category` in (_utf8mb4'spa',_utf8mb4'tour',_utf8mb4'sport',_utf8mb4'other')) and (`duration_minutes` between 15 and 480) and (`capacity` between 1 and 50)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ap_credit_applications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ap_credit_applications` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `credit_id` char(26) NOT NULL,
  `payable_id` char(26) NOT NULL,
  `amount_minor` bigint unsigned NOT NULL,
  `applied_by` char(26) NOT NULL,
  `business_date` date NOT NULL,
  `created_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ap_credit_applications_property_id_foreign` (`property_id`),
  KEY `ap_credit_applications_applied_by_foreign` (`applied_by`),
  KEY `ap_credit_applications_credit_id_index` (`credit_id`),
  KEY `ap_credit_applications_payable_id_index` (`payable_id`),
  CONSTRAINT `ap_credit_applications_applied_by_foreign` FOREIGN KEY (`applied_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `ap_credit_applications_credit_id_foreign` FOREIGN KEY (`credit_id`) REFERENCES `ap_credits` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `ap_credit_applications_payable_id_foreign` FOREIGN KEY (`payable_id`) REFERENCES `ap_payables` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `ap_credit_applications_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_ap_credit_application` CHECK ((`amount_minor` > 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `ap_credit_applications_no_update` BEFORE UPDATE ON `ap_credit_applications` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a credit application cannot be changed' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `ap_credit_applications_no_delete` BEFORE DELETE ON `ap_credit_applications` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a credit application cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `ap_credits`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ap_credits` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `supplier_id` char(26) NOT NULL,
  `supplier_code` varchar(12) NOT NULL,
  `supplier_name` varchar(120) NOT NULL,
  `source_type` varchar(24) NOT NULL,
  `source_id` char(26) NOT NULL,
  `source_number` varchar(20) NOT NULL,
  `credit_note_number` varchar(40) NOT NULL,
  `business_date` date NOT NULL,
  `amount_minor` bigint unsigned NOT NULL,
  `currency` char(3) NOT NULL,
  `event_id` char(26) NOT NULL,
  `correlation_id` char(26) DEFAULT NULL,
  `occurred_at` timestamp(6) NOT NULL,
  `created_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ap_credits_once` (`property_id`,`source_type`,`source_id`),
  KEY `ap_credits_supplier_id_index` (`supplier_id`),
  CONSTRAINT `ap_credits_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `ap_credits_no_update` BEFORE UPDATE ON `ap_credits` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a supplier credit cannot be changed' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `ap_credits_no_delete` BEFORE DELETE ON `ap_credits` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a supplier credit cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `ap_payables`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ap_payables` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `supplier_id` char(26) NOT NULL,
  `supplier_code` varchar(12) NOT NULL,
  `supplier_name` varchar(120) NOT NULL,
  `source_type` varchar(24) NOT NULL,
  `source_id` char(26) NOT NULL,
  `source_number` varchar(20) NOT NULL,
  `document_number` varchar(40) NOT NULL,
  `order_number` varchar(20) DEFAULT NULL,
  `issued_on` date NOT NULL,
  `due_date` date NOT NULL,
  `business_date` date NOT NULL,
  `amount_minor` bigint unsigned NOT NULL,
  `tax_minor` bigint unsigned NOT NULL DEFAULT '0',
  `currency` char(3) NOT NULL,
  `expense_account_id` char(26) DEFAULT NULL,
  `event_id` char(26) NOT NULL,
  `correlation_id` char(26) DEFAULT NULL,
  `actor_id` char(26) DEFAULT NULL,
  `occurred_at` timestamp(6) NOT NULL,
  `created_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ap_payables_once` (`property_id`,`source_type`,`source_id`),
  KEY `ap_payables_property_id_due_date_index` (`property_id`,`due_date`),
  KEY `ap_payables_supplier_id_index` (`supplier_id`),
  CONSTRAINT `ap_payables_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `ap_payables_guard` BEFORE UPDATE ON `ap_payables` FOR EACH ROW BEGIN
    IF NEW.amount_minor <> OLD.amount_minor OR NEW.tax_minor <> OLD.tax_minor OR NEW.due_date <> OLD.due_date OR NEW.issued_on <> OLD.issued_on OR NEW.supplier_id <> OLD.supplier_id OR NEW.source_id <> OLD.source_id OR NEW.business_date <> OLD.business_date OR NEW.document_number <> OLD.document_number THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'the facts of a payable cannot be changed';
    END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `ap_payables_no_delete` BEFORE DELETE ON `ap_payables` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a payable cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `ap_payment_proofs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ap_payment_proofs` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `payment_id` char(26) NOT NULL,
  `file_id` char(26) NOT NULL,
  `display_name` varchar(120) DEFAULT NULL,
  `created_by` char(26) NOT NULL,
  `created_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ap_payment_proofs_property_id_foreign` (`property_id`),
  KEY `ap_payment_proofs_created_by_foreign` (`created_by`),
  KEY `ap_payment_proofs_payment_id_index` (`payment_id`),
  CONSTRAINT `ap_payment_proofs_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `ap_payment_proofs_payment_id_foreign` FOREIGN KEY (`payment_id`) REFERENCES `ap_payments` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `ap_payment_proofs_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `ap_payment_proofs_no_update` BEFORE UPDATE ON `ap_payment_proofs` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a payment proof cannot be changed' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `ap_payment_proofs_no_delete` BEFORE DELETE ON `ap_payment_proofs` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a payment proof cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `ap_payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ap_payments` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `number` varchar(20) NOT NULL,
  `payable_id` char(26) NOT NULL,
  `reverses_id` char(26) DEFAULT NULL,
  `supplier_id` char(26) NOT NULL,
  `amount_minor` bigint unsigned NOT NULL,
  `method` varchar(12) NOT NULL,
  `paid_on` date NOT NULL,
  `reference` varchar(60) DEFAULT NULL,
  `note` varchar(200) DEFAULT NULL,
  `status` varchar(16) NOT NULL,
  `decision_note` varchar(200) DEFAULT NULL,
  `approval_id` char(26) DEFAULT NULL,
  `created_by` char(26) NOT NULL,
  `business_date` date NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  `correlation_id` char(26) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ap_payments_property_id_number_unique` (`property_id`,`number`),
  UNIQUE KEY `ap_payments_reverses_once` (`reverses_id`),
  KEY `ap_payments_created_by_foreign` (`created_by`),
  KEY `ap_payments_payable_id_status_index` (`payable_id`,`status`),
  KEY `ap_payments_property_id_paid_on_index` (`property_id`,`paid_on`),
  KEY `ap_payments_correlation_idx` (`correlation_id`),
  CONSTRAINT `ap_payments_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `ap_payments_payable_id_foreign` FOREIGN KEY (`payable_id`) REFERENCES `ap_payables` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `ap_payments_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_ap_payment` CHECK (((`amount_minor` > 0) and (`method` in (_utf8mb4'transfer',_utf8mb4'cash',_utf8mb4'giro',_utf8mb4'other')) and (`status` in (_utf8mb4'pending_approval',_utf8mb4'paid',_utf8mb4'rejected',_utf8mb4'cancelled',_utf8mb4'reversal')) and ((`status` = _utf8mb4'reversal') = (`reverses_id` is not null))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `ap_payments_guard` BEFORE UPDATE ON `ap_payments` FOR EACH ROW BEGIN
    IF OLD.status IN ('paid', 'rejected', 'cancelled', 'reversal') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a decided payment cannot be changed'; END IF;
    IF NEW.amount_minor <> OLD.amount_minor OR NEW.payable_id <> OLD.payable_id OR NEW.number <> OLD.number OR NEW.created_by <> OLD.created_by OR NEW.paid_on <> OLD.paid_on THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'the facts of a payment cannot be changed';
    END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `ap_payments_no_delete` BEFORE DELETE ON `ap_payments` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a payment cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `approval_decisions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `approval_decisions` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `request_id` char(26) NOT NULL,
  `step` smallint unsigned NOT NULL,
  `approver_id` char(26) NOT NULL,
  `decision` varchar(16) NOT NULL,
  `reason` varchar(500) DEFAULT NULL,
  `decided_at` timestamp(6) NOT NULL,
  `correlation_id` char(26) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `approval_decision_person_unique` (`request_id`,`approver_id`),
  KEY `approval_decisions_approver_id_foreign` (`approver_id`),
  KEY `approval_decisions_approver` (`property_id`,`approver_id`,`decided_at`),
  CONSTRAINT `approval_decisions_approver_id_foreign` FOREIGN KEY (`approver_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `approval_decisions_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `approval_decisions_request_id_foreign` FOREIGN KEY (`request_id`) REFERENCES `approval_requests` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_approval_decision` CHECK ((`decision` in (_utf8mb4'approve',_utf8mb4'reject'))),
  CONSTRAINT `chk_approval_reject_reason` CHECK (((`decision` = _utf8mb4'approve') or ((`reason` is not null) and (`reason` <> _utf8mb4''))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `approval_decisions_prevent_update` BEFORE UPDATE ON `approval_decisions` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'approval decisions are immutable evidence and cannot be updated' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `approval_decisions_prevent_delete` BEFORE DELETE ON `approval_decisions` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'approval decisions are immutable evidence and cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `approval_policies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `approval_policies` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `subject_type` varchar(120) NOT NULL,
  `band_min_amount_minor` bigint unsigned NOT NULL DEFAULT '0',
  `version` int unsigned NOT NULL,
  `steps` json NOT NULL,
  `created_by` char(26) NOT NULL,
  `change_reason` varchar(500) NOT NULL,
  `superseded_at` timestamp(6) NULL DEFAULT NULL,
  `created_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `approval_policy_version_unique` (`property_id`,`subject_type`,`band_min_amount_minor`,`version`),
  KEY `approval_policies_created_by_foreign` (`created_by`),
  KEY `approval_policy_lookup` (`property_id`,`subject_type`,`superseded_at`),
  CONSTRAINT `approval_policies_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `approval_policies_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `approval_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `approval_requests` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `subject_type` varchar(120) NOT NULL,
  `subject_ref` varchar(64) NOT NULL,
  `maker_id` char(26) NOT NULL,
  `reason` varchar(500) NOT NULL,
  `amount_minor` bigint unsigned DEFAULT NULL,
  `currency` char(3) DEFAULT NULL,
  `scope_type` varchar(16) DEFAULT NULL,
  `scope_id` char(26) DEFAULT NULL,
  `payload_hash` char(64) NOT NULL,
  `payload` json NOT NULL,
  `before_state` json DEFAULT NULL,
  `policy_id` char(26) NOT NULL,
  `policy_steps` json NOT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'pending',
  `current_step` smallint unsigned NOT NULL DEFAULT '0',
  `supersedes_id` char(26) DEFAULT NULL,
  `completed_at` timestamp(6) NULL DEFAULT NULL,
  `consumed_at` timestamp(6) NULL DEFAULT NULL,
  `correlation_id` char(26) NOT NULL,
  `lock_version` bigint unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `approval_requests_maker_id_foreign` (`maker_id`),
  KEY `approval_requests_policy_id_foreign` (`policy_id`),
  KEY `approval_requests_inbox` (`property_id`,`status`,`created_at`),
  KEY `approval_requests_maker` (`property_id`,`maker_id`,`created_at`),
  KEY `approval_requests_subject` (`property_id`,`subject_type`,`subject_ref`),
  CONSTRAINT `approval_requests_maker_id_foreign` FOREIGN KEY (`maker_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `approval_requests_policy_id_foreign` FOREIGN KEY (`policy_id`) REFERENCES `approval_policies` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `approval_requests_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_approval_completion` CHECK ((((`status` = _utf8mb4'pending') and (`completed_at` is null)) or ((`status` <> _utf8mb4'pending') and (`completed_at` is not null)))),
  CONSTRAINT `chk_approval_consumed` CHECK (((`consumed_at` is null) or (`status` = _utf8mb4'approved'))),
  CONSTRAINT `chk_approval_scope` CHECK ((((`scope_type` is null) and (`scope_id` is null)) or ((`scope_type` in (_utf8mb4'outlet',_utf8mb4'department')) and (`scope_id` is not null)))),
  CONSTRAINT `chk_approval_status` CHECK ((`status` in (_utf8mb4'pending',_utf8mb4'approved',_utf8mb4'rejected',_utf8mb4'cancelled')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `approval_requests_prevent_delete` BEFORE DELETE ON `approval_requests` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'approval requests are evidence and cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `ar_notes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ar_notes` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `receivable_id` char(26) NOT NULL,
  `kind` varchar(10) NOT NULL,
  `note` varchar(300) NOT NULL,
  `promised_on` date DEFAULT NULL,
  `promised_minor` bigint unsigned DEFAULT NULL,
  `created_by` char(26) NOT NULL,
  `business_date` date NOT NULL,
  `created_at` timestamp(6) NOT NULL,
  `correlation_id` char(26) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ar_notes_property_id_foreign` (`property_id`),
  KEY `ar_notes_created_by_foreign` (`created_by`),
  KEY `ar_notes_receivable_id_created_at_index` (`receivable_id`,`created_at`),
  KEY `ar_notes_correlation_idx` (`correlation_id`),
  CONSTRAINT `ar_notes_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `ar_notes_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `ar_notes_receivable_id_foreign` FOREIGN KEY (`receivable_id`) REFERENCES `ar_receivables` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_ar_note_kind` CHECK ((`kind` in (_utf8mb4'reminder',_utf8mb4'call',_utf8mb4'promise',_utf8mb4'dispute',_utf8mb4'note'))),
  CONSTRAINT `chk_ar_note_promise` CHECK ((((`kind` = _utf8mb4'promise') and (`promised_on` is not null)) or ((`kind` <> _utf8mb4'promise') and (`promised_on` is null) and (`promised_minor` is null))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `ar_notes_no_update` BEFORE UPDATE ON `ar_notes` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a collection note cannot be changed' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `ar_notes_no_delete` BEFORE DELETE ON `ar_notes` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a collection note cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `ar_receipts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ar_receipts` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `number` varchar(20) NOT NULL,
  `kind` varchar(12) NOT NULL DEFAULT 'receipt',
  `receivable_id` char(26) NOT NULL,
  `reverses_id` char(26) DEFAULT NULL,
  `amount_minor` bigint unsigned NOT NULL,
  `method` varchar(12) DEFAULT NULL,
  `received_on` date NOT NULL,
  `reference` varchar(80) DEFAULT NULL,
  `note` varchar(200) DEFAULT NULL,
  `business_date` date NOT NULL,
  `created_by` char(26) NOT NULL,
  `created_at` timestamp(6) NOT NULL,
  `correlation_id` char(26) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ar_receipts_property_id_number_unique` (`property_id`,`number`),
  UNIQUE KEY `ar_receipts_reverses_once` (`reverses_id`),
  KEY `ar_receipts_created_by_foreign` (`created_by`),
  KEY `ar_receipts_receivable_id_index` (`receivable_id`),
  KEY `ar_receipts_correlation_idx` (`correlation_id`),
  CONSTRAINT `ar_receipts_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `ar_receipts_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `ar_receipts_receivable_id_foreign` FOREIGN KEY (`receivable_id`) REFERENCES `ar_receivables` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_ar_receipt_amount` CHECK ((`amount_minor` > 0)),
  CONSTRAINT `chk_ar_receipt_kind` CHECK (((`kind` in (_utf8mb4'receipt',_utf8mb4'reversal',_utf8mb4'credit_note',_utf8mb4'write_off')) and (((`kind` = _utf8mb4'receipt') and (`method` in (_utf8mb4'transfer',_utf8mb4'giro',_utf8mb4'online')) and (`reference` is not null) and (`reverses_id` is null)) or ((`kind` = _utf8mb4'reversal') and (`reverses_id` is not null) and (`note` is not null)) or ((`kind` in (_utf8mb4'credit_note',_utf8mb4'write_off')) and (`reverses_id` is null) and (`note` is not null)))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `ar_receipts_no_update` BEFORE UPDATE ON `ar_receipts` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a receipt cannot be changed' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `ar_receipts_no_delete` BEFORE DELETE ON `ar_receipts` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a receipt cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `ar_receivables`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ar_receivables` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `number` varchar(20) NOT NULL,
  `customer_id` char(26) NOT NULL,
  `source_type` varchar(16) NOT NULL,
  `source_id` char(26) NOT NULL,
  `source_number` varchar(30) NOT NULL,
  `description` varchar(200) NOT NULL,
  `reference` varchar(60) DEFAULT NULL,
  `issued_on` date NOT NULL,
  `due_date` date NOT NULL,
  `business_date` date NOT NULL,
  `amount_minor` bigint unsigned NOT NULL,
  `currency` char(3) NOT NULL,
  `event_id` char(26) DEFAULT NULL,
  `correlation_id` char(26) DEFAULT NULL,
  `actor_id` char(26) DEFAULT NULL,
  `occurred_at` timestamp(6) NOT NULL,
  `created_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ar_receivables_property_id_number_unique` (`property_id`,`number`),
  UNIQUE KEY `ar_receivables_once` (`property_id`,`source_type`,`source_id`),
  KEY `ar_receivables_property_id_due_date_index` (`property_id`,`due_date`),
  KEY `ar_receivables_customer_id_index` (`customer_id`),
  CONSTRAINT `ar_receivables_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `fin_customers` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `ar_receivables_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_ar_receivable_amount` CHECK ((`amount_minor` > 0)),
  CONSTRAINT `chk_ar_receivable_due` CHECK ((`due_date` >= `issued_on`)),
  CONSTRAINT `chk_ar_receivable_source` CHECK ((`source_type` in (_utf8mb4'company_folio',_utf8mb4'manual')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `ar_receivables_no_update` BEFORE UPDATE ON `ar_receivables` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a receivable cannot be changed' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `ar_receivables_no_delete` BEFORE DELETE ON `ar_receivables` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a receivable cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `audit_entries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `audit_entries` (
  `id` char(26) NOT NULL,
  `property_id` char(26) DEFAULT NULL,
  `actor_id` char(26) DEFAULT NULL,
  `action` varchar(120) NOT NULL,
  `aggregate_type` varchar(120) NOT NULL,
  `aggregate_id` char(26) NOT NULL,
  `before_state` json DEFAULT NULL,
  `after_state` json DEFAULT NULL,
  `reason` varchar(500) DEFAULT NULL,
  `approval_reference` varchar(120) DEFAULT NULL,
  `correlation_id` char(26) NOT NULL,
  `occurred_at` timestamp(6) NOT NULL,
  `minimum_retention_until` timestamp(6) NULL DEFAULT NULL,
  `payload_checksum` char(64) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `audit_entries_property_id_occurred_at_index` (`property_id`,`occurred_at`),
  KEY `audit_entries_aggregate_type_aggregate_id_occurred_at_index` (`aggregate_type`,`aggregate_id`,`occurred_at`),
  KEY `audit_entries_actor_id_occurred_at_index` (`actor_id`,`occurred_at`),
  KEY `audit_entries_correlation_id_index` (`correlation_id`),
  KEY `audit_entries_minimum_retention_until_index` (`minimum_retention_until`),
  CONSTRAINT `audit_entries_actor_id_foreign` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `audit_entries_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_audit_state_present` CHECK (((`before_state` is not null) or (`after_state` is not null)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `audit_entries_prevent_update` BEFORE UPDATE ON `audit_entries` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'immutable evidence cannot be updated' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `audit_entries_prevent_delete` BEFORE DELETE ON `audit_entries` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'immutable evidence cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `backup_runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `backup_runs` (
  `id` char(26) NOT NULL,
  `kind` varchar(20) NOT NULL,
  `status` varchar(20) NOT NULL,
  `backup_set` varchar(80) DEFAULT NULL,
  `started_at` timestamp(6) NOT NULL,
  `finished_at` timestamp(6) NULL DEFAULT NULL,
  `duration_ms` bigint unsigned DEFAULT NULL,
  `size_bytes` bigint unsigned DEFAULT NULL,
  `error_type` varchar(255) DEFAULT NULL,
  `error_fingerprint` char(64) DEFAULT NULL,
  `details` json DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `backup_runs_kind_status_finished_at_index` (`kind`,`status`,`finished_at`),
  KEY `backup_runs_started_at_index` (`started_at`),
  CONSTRAINT `chk_backup_runs_kind` CHECK ((`kind` in (_utf8mb4'backup',_utf8mb4'restore_test'))),
  CONSTRAINT `chk_backup_runs_status` CHECK ((`status` in (_utf8mb4'running',_utf8mb4'succeeded',_utf8mb4'failed')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `booking_policies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `booking_policies` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `rate_plan_id` char(26) DEFAULT NULL,
  `source` varchar(10) DEFAULT NULL,
  `effective_from` date NOT NULL,
  `guarantee_required` tinyint(1) NOT NULL DEFAULT '0',
  `deposit_basis` varchar(12) NOT NULL,
  `deposit_value` bigint NOT NULL DEFAULT '0',
  `deposit_due_days` smallint unsigned NOT NULL DEFAULT '0',
  `cancel_free_days` smallint unsigned NOT NULL DEFAULT '0',
  `cancel_penalty_kind` varchar(12) NOT NULL,
  `cancel_penalty_value` bigint NOT NULL DEFAULT '0',
  `noshow_penalty_kind` varchar(12) NOT NULL,
  `noshow_penalty_value` bigint NOT NULL DEFAULT '0',
  `reason` varchar(300) NOT NULL,
  `scope_key` varchar(40) NOT NULL,
  `created_by` char(26) NOT NULL,
  `created_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `booking_policies_version_unique` (`property_id`,`scope_key`,`effective_from`),
  KEY `booking_policies_created_by_foreign` (`created_by`),
  KEY `booking_policies_property_id_effective_from_index` (`property_id`,`effective_from`),
  CONSTRAINT `booking_policies_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `booking_policies_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_policy_cancel` CHECK (((`cancel_penalty_kind` in (_utf8mb4'none',_utf8mb4'first_night',_utf8mb4'all_nights',_utf8mb4'percent',_utf8mb4'fixed')) and (`cancel_penalty_value` >= 0) and ((`cancel_penalty_kind` <> _utf8mb4'percent') or (`cancel_penalty_value` <= 10000)) and ((`cancel_penalty_kind` not in (_utf8mb4'none',_utf8mb4'first_night',_utf8mb4'all_nights')) or (`cancel_penalty_value` = 0)))),
  CONSTRAINT `chk_policy_deposit` CHECK (((`deposit_basis` in (_utf8mb4'none',_utf8mb4'first_night',_utf8mb4'percent',_utf8mb4'fixed')) and (`deposit_value` >= 0) and ((`deposit_basis` <> _utf8mb4'percent') or (`deposit_value` <= 10000)) and ((`deposit_basis` not in (_utf8mb4'none',_utf8mb4'first_night')) or (`deposit_value` = 0)))),
  CONSTRAINT `chk_policy_noshow` CHECK (((`noshow_penalty_kind` in (_utf8mb4'none',_utf8mb4'first_night',_utf8mb4'all_nights',_utf8mb4'percent',_utf8mb4'fixed')) and (`noshow_penalty_value` >= 0) and ((`noshow_penalty_kind` <> _utf8mb4'percent') or (`noshow_penalty_value` <= 10000)) and ((`noshow_penalty_kind` not in (_utf8mb4'none',_utf8mb4'first_night',_utf8mb4'all_nights')) or (`noshow_penalty_value` = 0)))),
  CONSTRAINT `chk_policy_source` CHECK (((`source` is null) or (`source` in (_utf8mb4'direct',_utf8mb4'phone',_utf8mb4'ota',_utf8mb4'corporate',_utf8mb4'walk_in'))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `booking_policies_scope_insert` BEFORE INSERT ON `booking_policies` FOR EACH ROW BEGIN
    SET NEW.scope_key = CONCAT(COALESCE(NEW.rate_plan_id, '*'), '|', COALESCE(NEW.source, '*'));
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `booking_policies_no_update` BEFORE UPDATE ON `booking_policies` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a booking policy cannot be changed; add a new version' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `booking_policies_no_delete` BEFORE DELETE ON `booking_policies` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a booking policy cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `cache`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cache` (
  `key` varchar(255) NOT NULL,
  `value` mediumtext NOT NULL,
  `expiration` bigint NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cache_locks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cache_locks` (
  `key` varchar(255) NOT NULL,
  `owner` varchar(255) NOT NULL,
  `expiration` bigint NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_locks_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cash_drops`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cash_drops` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `shift_id` char(26) NOT NULL,
  `amount_minor` bigint NOT NULL,
  `reference` varchar(80) DEFAULT NULL,
  `note` varchar(300) DEFAULT NULL,
  `created_by` char(26) NOT NULL,
  `created_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `cash_drops_shift_id_reference_unique` (`shift_id`,`reference`),
  KEY `cash_drops_property_id_foreign` (`property_id`),
  KEY `cash_drops_created_by_foreign` (`created_by`),
  CONSTRAINT `cash_drops_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `cash_drops_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `cash_drops_shift_id_foreign` FOREIGN KEY (`shift_id`) REFERENCES `cashier_shifts` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_drops_amount` CHECK ((`amount_minor` > 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `cash_drops_only_open` BEFORE INSERT ON `cash_drops` FOR EACH ROW BEGIN
    IF (SELECT status FROM cashier_shifts WHERE id = NEW.shift_id) <> 'open' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a cash drop cannot be added to a closed shift';
    END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `cash_drops_no_update` BEFORE UPDATE ON `cash_drops` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a cash drop cannot be changed' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `cash_drops_no_delete` BEFORE DELETE ON `cash_drops` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a cash drop cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `cashier_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cashier_settings` (
  `property_id` char(26) NOT NULL,
  `require_open_shift` tinyint(1) NOT NULL DEFAULT '0',
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `updated_by` char(26) DEFAULT NULL,
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`property_id`),
  KEY `cashier_settings_updated_by_foreign` (`updated_by`),
  CONSTRAINT `cashier_settings_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `cashier_settings_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cashier_shift_postings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cashier_shift_postings` (
  `posting_id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `shift_id` char(26) NOT NULL,
  `created_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`posting_id`),
  KEY `cashier_shift_postings_property_id_foreign` (`property_id`),
  KEY `cashier_shift_postings_shift_id_index` (`shift_id`),
  CONSTRAINT `cashier_shift_postings_posting_id_foreign` FOREIGN KEY (`posting_id`) REFERENCES `folio_postings` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `cashier_shift_postings_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `cashier_shift_postings_shift_id_foreign` FOREIGN KEY (`shift_id`) REFERENCES `cashier_shifts` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `cashier_shift_postings_only_open` BEFORE INSERT ON `cashier_shift_postings` FOR EACH ROW BEGIN
    IF (SELECT status FROM cashier_shifts WHERE id = NEW.shift_id) <> 'open' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a shift posting cannot be added to a closed shift';
    END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `cashier_shift_postings_no_update` BEFORE UPDATE ON `cashier_shift_postings` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a shift posting cannot be changed' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `cashier_shift_postings_no_delete` BEFORE DELETE ON `cashier_shift_postings` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a shift posting cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `cashier_shifts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cashier_shifts` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `number` varchar(30) NOT NULL,
  `cashier_id` char(26) NOT NULL,
  `status` varchar(8) NOT NULL,
  `currency_code` char(3) NOT NULL,
  `opened_at` timestamp(6) NOT NULL,
  `opened_business_date` date NOT NULL,
  `opening_float_minor` bigint NOT NULL,
  `closed_at` timestamp(6) NULL DEFAULT NULL,
  `closed_business_date` date DEFAULT NULL,
  `closed_by` char(26) DEFAULT NULL,
  `expected_cash_minor` bigint DEFAULT NULL,
  `counted_cash_minor` bigint DEFAULT NULL,
  `variance_minor` bigint DEFAULT NULL,
  `variance_reason` varchar(300) DEFAULT NULL,
  `totals` json DEFAULT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  `open_cashier_key` char(26) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `cashier_shifts_property_id_number_unique` (`property_id`,`number`),
  UNIQUE KEY `cashier_shifts_one_open_per_cashier` (`property_id`,`open_cashier_key`),
  KEY `cashier_shifts_cashier_id_foreign` (`cashier_id`),
  KEY `cashier_shifts_closed_by_foreign` (`closed_by`),
  KEY `cashier_shifts_property_id_status_opened_at_index` (`property_id`,`status`,`opened_at`),
  CONSTRAINT `cashier_shifts_cashier_id_foreign` FOREIGN KEY (`cashier_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `cashier_shifts_closed_by_foreign` FOREIGN KEY (`closed_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `cashier_shifts_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_shifts_closed` CHECK ((((`status` = _utf8mb4'open') and (`closed_at` is null) and (`counted_cash_minor` is null)) or ((`status` = _utf8mb4'closed') and (`closed_at` is not null) and (`closed_by` is not null) and (`counted_cash_minor` is not null) and (`expected_cash_minor` is not null) and (`variance_minor` is not null) and (`totals` is not null)))),
  CONSTRAINT `chk_shifts_float` CHECK ((`opening_float_minor` >= 0)),
  CONSTRAINT `chk_shifts_status` CHECK ((`status` in (_utf8mb4'open',_utf8mb4'closed'))),
  CONSTRAINT `chk_shifts_variance` CHECK (((`variance_minor` is null) or (`variance_minor` = (`counted_cash_minor` - `expected_cash_minor`)))),
  CONSTRAINT `chk_shifts_variance_reason` CHECK (((`variance_minor` is null) or (`variance_minor` = 0) or ((`variance_reason` is not null) and (char_length(trim(`variance_reason`)) > 0))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `cashier_shifts_open_key_insert` BEFORE INSERT ON `cashier_shifts` FOR EACH ROW BEGIN
    SET NEW.open_cashier_key = CASE WHEN NEW.status = 'open' THEN NEW.cashier_id ELSE NULL END;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `cashier_shifts_facts_immutable` BEFORE UPDATE ON `cashier_shifts` FOR EACH ROW BEGIN
    IF NOT (NEW.id <=> OLD.id AND NEW.property_id <=> OLD.property_id AND NEW.number <=> OLD.number AND NEW.cashier_id <=> OLD.cashier_id
        AND NEW.currency_code <=> OLD.currency_code AND NEW.opened_at <=> OLD.opened_at AND NEW.opened_business_date <=> OLD.opened_business_date
        AND NEW.opening_float_minor <=> OLD.opening_float_minor) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'the facts of an opened shift cannot be changed';
    END IF;
    IF OLD.status = 'closed' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a closed shift cannot be changed';
    END IF;
    SET NEW.open_cashier_key = CASE WHEN NEW.status = 'open' THEN NEW.cashier_id ELSE NULL END;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `cashier_shifts_no_delete` BEFORE DELETE ON `cashier_shifts` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a cashier shift cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `charge_schemes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `charge_schemes` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `scope` varchar(60) NOT NULL,
  `effective_from` date NOT NULL,
  `service_charge_bp` int unsigned NOT NULL,
  `tax_bp` int unsigned NOT NULL,
  `tax_on_service_charge` tinyint(1) NOT NULL,
  `change_reason` varchar(500) NOT NULL,
  `created_by` char(26) NOT NULL,
  `created_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `charge_schemes_property_id_scope_effective_from_unique` (`property_id`,`scope`,`effective_from`),
  KEY `charge_schemes_created_by_foreign` (`created_by`),
  CONSTRAINT `charge_schemes_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `charge_schemes_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_charge_schemes_rates` CHECK (((`service_charge_bp` <= 100000) and (`tax_bp` <= 100000)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `charge_schemes_no_update` BEFORE UPDATE ON `charge_schemes` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'charge_schemes is append-only' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `charge_schemes_no_delete` BEFORE DELETE ON `charge_schemes` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'charge_schemes is append-only' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `commission_accruals`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `commission_accruals` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `partner_id` char(26) NOT NULL,
  `reservation_id` char(26) NOT NULL,
  `reservation_number` varchar(20) NOT NULL,
  `stay_out_on` date NOT NULL,
  `base_minor` bigint NOT NULL,
  `rate_bp` smallint unsigned NOT NULL,
  `commission_minor` bigint NOT NULL,
  `status` varchar(8) NOT NULL DEFAULT 'accrued',
  `statement_id` char(26) DEFAULT NULL,
  `void_reason` varchar(200) DEFAULT NULL,
  `voided_by` char(26) DEFAULT NULL,
  `created_at` timestamp(6) NOT NULL,
  `updated_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `commission_accruals_once` (`property_id`,`reservation_id`),
  KEY `commission_accruals_property_id_partner_id_status_index` (`property_id`,`partner_id`,`status`),
  KEY `commission_accruals_property_id_stay_out_on_index` (`property_id`,`stay_out_on`),
  CONSTRAINT `commission_accruals_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_commission_accruals` CHECK (((`status` in (_utf8mb4'accrued',_utf8mb4'paid',_utf8mb4'void')) and (`commission_minor` >= 0) and ((`status` <> _utf8mb4'paid') or (`statement_id` is not null)) and ((`status` <> _utf8mb4'void') or (`void_reason` is not null))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `commission_accruals_guard` BEFORE UPDATE ON `commission_accruals` FOR EACH ROW BEGIN IF NEW.base_minor <> OLD.base_minor OR NEW.commission_minor <> OLD.commission_minor OR NEW.rate_bp <> OLD.rate_bp OR NEW.partner_id <> OLD.partner_id OR NEW.reservation_id <> OLD.reservation_id OR OLD.status <> 'accrued' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a commission accrual cannot be changed once settled'; END IF; END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `commission_accruals_no_delete` BEFORE DELETE ON `commission_accruals` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a commission accrual cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `commission_partners`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `commission_partners` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `partner_kind` varchar(8) NOT NULL,
  `partner_key` varchar(40) NOT NULL,
  `name` varchar(120) NOT NULL,
  `rate_bp` smallint unsigned NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `commission_partners_once` (`property_id`,`partner_kind`,`partner_key`),
  CONSTRAINT `commission_partners_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_commission_partners` CHECK (((`partner_kind` in (_utf8mb4'company',_utf8mb4'channel')) and (`rate_bp` between 1 and 5000)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `commission_statements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `commission_statements` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `partner_id` char(26) NOT NULL,
  `number` varchar(20) NOT NULL,
  `paid_on` date NOT NULL,
  `method` varchar(12) NOT NULL,
  `reference` varchar(60) DEFAULT NULL,
  `total_minor` bigint unsigned NOT NULL,
  `accruals` smallint unsigned NOT NULL,
  `created_by` char(26) NOT NULL,
  `created_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `commission_statements_property_id_number_unique` (`property_id`,`number`),
  KEY `commission_statements_property_id_partner_id_index` (`property_id`,`partner_id`),
  CONSTRAINT `commission_statements_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `commission_statements_no_update` BEFORE UPDATE ON `commission_statements` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a commission statement cannot be changed' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `commission_statements_no_delete` BEFORE DELETE ON `commission_statements` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a commission statement cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `company_folios`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `company_folios` (
  `folio_id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `reservation_id` char(26) NOT NULL,
  `company_id` char(26) NOT NULL,
  `created_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`folio_id`),
  UNIQUE KEY `company_folios_reservation_id_unique` (`reservation_id`),
  KEY `company_folios_property_id_foreign` (`property_id`),
  KEY `company_folios_company_id_foreign` (`company_id`),
  CONSTRAINT `company_folios_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `company_profiles` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `company_folios_folio_id_foreign` FOREIGN KEY (`folio_id`) REFERENCES `folios` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `company_folios_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `company_folios_reservation_id_foreign` FOREIGN KEY (`reservation_id`) REFERENCES `reservations` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `company_folios_no_update` BEFORE UPDATE ON `company_folios` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a company folio mark cannot be changed' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `company_folios_no_delete` BEFORE DELETE ON `company_folios` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a company folio mark cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `company_profiles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `company_profiles` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `code` varchar(20) NOT NULL,
  `name` varchar(120) NOT NULL,
  `kind` varchar(7) NOT NULL,
  `contact_name` varchar(100) DEFAULT NULL,
  `contact_phone` varchar(30) DEFAULT NULL,
  `contact_email` varchar(190) DEFAULT NULL,
  `tax_id` varchar(30) DEFAULT NULL,
  `billing_instruction` varchar(500) DEFAULT NULL,
  `credit_limit_minor` bigint DEFAULT NULL,
  `route_rooms` tinyint(1) NOT NULL DEFAULT '1',
  `route_extras` tinyint(1) NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `company_profiles_property_id_code_unique` (`property_id`,`code`),
  CONSTRAINT `company_profiles_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_company_kind` CHECK (((`kind` in (_utf8mb4'company',_utf8mb4'agent')) and ((`credit_limit_minor` is null) or (`credit_limit_minor` >= 0))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `company_profiles_no_delete` BEFORE DELETE ON `company_profiles` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a company profile cannot be deleted; deactivate it' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `consent_records`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `consent_records` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `subject_type` varchar(40) NOT NULL,
  `subject_id` char(26) NOT NULL,
  `purpose` varchar(80) NOT NULL,
  `notice_version` varchar(40) NOT NULL,
  `granted` tinyint(1) NOT NULL,
  `evidence_ref` varchar(120) DEFAULT NULL,
  `recorded_by` char(26) NOT NULL,
  `recorded_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `consent_records_recorded_by_foreign` (`recorded_by`),
  KEY `consent_subject_purpose_index` (`property_id`,`subject_type`,`subject_id`,`purpose`,`recorded_at`),
  CONSTRAINT `consent_records_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `consent_records_recorded_by_foreign` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `consent_records_no_update` BEFORE UPDATE ON `consent_records` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'consent_records is append-only' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `consent_records_no_delete` BEFORE DELETE ON `consent_records` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'consent_records cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `dashboard_preferences`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `dashboard_preferences` (
  `property_id` char(26) NOT NULL,
  `user_id` char(26) NOT NULL,
  `card_order` json NOT NULL,
  `hidden_cards` json NOT NULL,
  `updated_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`property_id`,`user_id`),
  KEY `dashboard_preferences_user_id_foreign` (`user_id`),
  CONSTRAINT `dashboard_preferences_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `dashboard_preferences_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `data_subject_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `data_subject_requests` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `subject_type` varchar(40) NOT NULL,
  `subject_id` char(26) NOT NULL,
  `request_type` varchar(30) NOT NULL,
  `status` varchar(20) NOT NULL,
  `channel` varchar(40) NOT NULL,
  `verification_note` varchar(500) NOT NULL,
  `received_at` timestamp(6) NOT NULL,
  `due_at` timestamp(6) NOT NULL,
  `decision_basis` varchar(500) DEFAULT NULL,
  `decision_note` varchar(500) DEFAULT NULL,
  `handled_by` char(26) DEFAULT NULL,
  `completed_at` timestamp(6) NULL DEFAULT NULL,
  `created_by` char(26) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `data_subject_requests_handled_by_foreign` (`handled_by`),
  KEY `data_subject_requests_created_by_foreign` (`created_by`),
  KEY `data_subject_requests_property_id_status_due_at_index` (`property_id`,`status`,`due_at`),
  KEY `data_subject_requests_property_id_subject_type_subject_id_index` (`property_id`,`subject_type`,`subject_id`),
  CONSTRAINT `data_subject_requests_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `data_subject_requests_handled_by_foreign` FOREIGN KEY (`handled_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `data_subject_requests_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_dsr_final` CHECK ((((`status` in (_utf8mb4'received',_utf8mb4'in_progress')) and (`completed_at` is null)) or ((`status` = _utf8mb4'completed') and (`completed_at` is not null) and (`decision_note` is not null)) or ((`status` = _utf8mb4'refused') and (`completed_at` is not null) and (`decision_basis` is not null) and (`decision_note` is not null)))),
  CONSTRAINT `chk_dsr_status` CHECK ((`status` in (_utf8mb4'received',_utf8mb4'in_progress',_utf8mb4'completed',_utf8mb4'refused'))),
  CONSTRAINT `chk_dsr_type` CHECK ((`request_type` in (_utf8mb4'access',_utf8mb4'correction',_utf8mb4'deletion',_utf8mb4'withdraw_consent',_utf8mb4'objection')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `data_subject_requests_no_delete` BEFORE DELETE ON `data_subject_requests` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'data_subject_requests cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `day_use_bookings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `day_use_bookings` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `number` varchar(20) NOT NULL,
  `room_type_id` char(26) NOT NULL,
  `room_id` char(26) NOT NULL,
  `room_number` varchar(20) NOT NULL,
  `starts_on` date NOT NULL,
  `starts_at` char(5) NOT NULL,
  `ends_at` char(5) NOT NULL,
  `guest_name` varchar(120) NOT NULL,
  `guest_phone` varchar(30) DEFAULT NULL,
  `guests` tinyint unsigned NOT NULL,
  `price_minor` bigint unsigned NOT NULL,
  `service_charge_minor` bigint NOT NULL DEFAULT '0',
  `tax_minor` bigint NOT NULL DEFAULT '0',
  `total_minor` bigint NOT NULL DEFAULT '0',
  `status` varchar(9) NOT NULL DEFAULT 'booked',
  `payment_method` varchar(8) DEFAULT NULL,
  `payment_reference` varchar(60) DEFAULT NULL,
  `shift_id` char(26) DEFAULT NULL,
  `paid_on` date DEFAULT NULL,
  `note` varchar(200) DEFAULT NULL,
  `reason` varchar(200) DEFAULT NULL,
  `started_at` timestamp(6) NULL DEFAULT NULL,
  `finished_at` timestamp(6) NULL DEFAULT NULL,
  `created_by` char(26) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `day_use_bookings_property_id_number_unique` (`property_id`,`number`),
  KEY `day_use_bookings_property_id_room_id_starts_on_index` (`property_id`,`room_id`,`starts_on`),
  KEY `day_use_bookings_property_id_starts_on_status_index` (`property_id`,`starts_on`,`status`),
  CONSTRAINT `day_use_bookings_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_day_use_bookings` CHECK (((`status` in (_utf8mb4'booked',_utf8mb4'in_use',_utf8mb4'done',_utf8mb4'cancelled',_utf8mb4'no_show')) and (`guests` between 1 and 20) and (`ends_at` > `starts_at`) and ((`status` not in (_utf8mb4'in_use',_utf8mb4'done')) or ((`payment_method` in (_utf8mb4'cash',_utf8mb4'card',_utf8mb4'qris')) and (`total_minor` > 0))) and ((`status` <> _utf8mb4'cancelled') or (`reason` is not null))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `day_use_bookings_guard` BEFORE UPDATE ON `day_use_bookings` FOR EACH ROW BEGIN IF NEW.room_id <> OLD.room_id OR NEW.starts_on <> OLD.starts_on OR NEW.price_minor <> OLD.price_minor OR OLD.status IN ('done', 'cancelled', 'no_show') OR (OLD.status = 'in_use' AND (NEW.total_minor <> OLD.total_minor OR NEW.payment_method <> OLD.payment_method)) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a settled day use booking cannot be changed'; END IF; END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `day_use_bookings_no_delete` BEFORE DELETE ON `day_use_bookings` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a day use booking cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `day_use_rates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `day_use_rates` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `room_type_id` char(26) NOT NULL,
  `price_minor` bigint unsigned NOT NULL,
  `max_hours` tinyint unsigned NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `day_use_rates_type` (`property_id`,`room_type_id`),
  CONSTRAINT `day_use_rates_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_day_use_rates` CHECK (((`max_hours` between 1 and 12) and (`price_minor` > 0)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `department_budgets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `department_budgets` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `department` varchar(16) NOT NULL,
  `period` char(7) NOT NULL,
  `amount_minor` bigint unsigned NOT NULL,
  `set_by` char(26) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `department_budgets_property_id_department_period_unique` (`property_id`,`department`,`period`),
  KEY `department_budgets_set_by_foreign` (`set_by`),
  CONSTRAINT `department_budgets_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `department_budgets_set_by_foreign` FOREIGN KEY (`set_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `document_sequences`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `document_sequences` (
  `property_id` char(26) NOT NULL,
  `doc_type` varchar(20) NOT NULL,
  `last_number` bigint unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`property_id`,`doc_type`),
  CONSTRAINT `document_sequences_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `door_lock_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `door_lock_jobs` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `source_key` char(64) NOT NULL,
  `stay_id` char(26) NOT NULL,
  `reservation_number` varchar(20) DEFAULT NULL,
  `kind` varchar(8) NOT NULL,
  `room_number` varchar(20) NOT NULL,
  `key_count` tinyint unsigned NOT NULL,
  `valid_until` date DEFAULT NULL,
  `status` varchar(10) NOT NULL DEFAULT 'pending',
  `attempts` smallint unsigned NOT NULL DEFAULT '0',
  `message` varchar(200) DEFAULT NULL,
  `claimed_at` timestamp(6) NULL DEFAULT NULL,
  `finished_at` timestamp(6) NULL DEFAULT NULL,
  `settled_by` char(26) DEFAULT NULL,
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `door_lock_jobs_once` (`property_id`,`source_key`,`kind`,`room_number`),
  KEY `door_lock_jobs_property_id_status_created_at_index` (`property_id`,`status`,`created_at`),
  CONSTRAINT `door_lock_jobs_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_door_lock_jobs` CHECK (((`kind` in (_utf8mb4'issue',_utf8mb4'revoke',_utf8mb4'update')) and (`status` in (_utf8mb4'pending',_utf8mb4'sent',_utf8mb4'done',_utf8mb4'failed',_utf8mb4'manual'))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `door_lock_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `door_lock_settings` (
  `property_id` char(26) NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT '0',
  `vendor` varchar(20) NOT NULL DEFAULT 'other',
  `key_count` tinyint unsigned NOT NULL DEFAULT '2',
  `token_hash` char(64) DEFAULT NULL,
  `token_hint` char(4) DEFAULT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `updated_by` char(26) NOT NULL,
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`property_id`),
  CONSTRAINT `door_lock_settings_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_door_lock_settings` CHECK (((`key_count` between 1 and 6) and (`vendor` in (_utf8mb4'visionline',_utf8mb4'onity',_utf8mb4'salto',_utf8mb4'dormakaba',_utf8mb4'hafele',_utf8mb4'other'))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `exchange_rates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `exchange_rates` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `currency_code` char(3) NOT NULL,
  `version` int unsigned NOT NULL,
  `rate_e4` bigint NOT NULL,
  `reason` varchar(300) NOT NULL,
  `set_by` char(26) NOT NULL,
  `created_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `exchange_rates_version_unique` (`property_id`,`currency_code`,`version`),
  KEY `exchange_rates_set_by_foreign` (`set_by`),
  CONSTRAINT `exchange_rates_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `exchange_rates_set_by_foreign` FOREIGN KEY (`set_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_exchange_rate` CHECK (((`rate_e4` > 0) and (`version` >= 1)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `exchange_rates_no_update` BEFORE UPDATE ON `exchange_rates` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'an exchange rate cannot be changed' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `exchange_rates_no_delete` BEFORE DELETE ON `exchange_rates` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'an exchange rate cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `failed_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `failed_jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(255) NOT NULL,
  `connection` varchar(255) NOT NULL,
  `queue` varchar(255) NOT NULL,
  `payload` longtext NOT NULL,
  `exception` longtext NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`),
  KEY `failed_jobs_connection_queue_failed_at_index` (`connection`,`queue`,`failed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `fin_bank_imports`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `fin_bank_imports` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `account_label` varchar(40) NOT NULL,
  `filename` varchar(120) NOT NULL,
  `rows_new` int unsigned NOT NULL,
  `rows_duplicate` int unsigned NOT NULL,
  `imported_by` char(26) NOT NULL,
  `created_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `fin_bank_imports_property_id_created_at_index` (`property_id`,`created_at`),
  CONSTRAINT `fin_bank_imports_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `fin_bank_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `fin_bank_lines` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `import_id` char(26) NOT NULL,
  `account_label` varchar(40) NOT NULL,
  `line_date` date NOT NULL,
  `description` varchar(250) NOT NULL,
  `reference` varchar(60) DEFAULT NULL,
  `direction` varchar(3) NOT NULL,
  `amount_minor` bigint unsigned NOT NULL,
  `balance_minor` bigint DEFAULT NULL,
  `fingerprint` char(64) NOT NULL,
  `status` varchar(7) NOT NULL DEFAULT 'open',
  `match_type` varchar(20) DEFAULT NULL,
  `match_id` char(26) DEFAULT NULL,
  `note` varchar(200) DEFAULT NULL,
  `category` varchar(16) DEFAULT NULL,
  `settled_by` char(26) DEFAULT NULL,
  `settled_at` timestamp(6) NULL DEFAULT NULL,
  `created_at` timestamp(6) NOT NULL,
  `updated_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fin_bank_lines_once` (`property_id`,`fingerprint`),
  UNIQUE KEY `fin_bank_lines_match_once` (`property_id`,`match_type`,`match_id`),
  KEY `fin_bank_lines_property_id_status_line_date_index` (`property_id`,`status`,`line_date`),
  CONSTRAINT `fin_bank_lines_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_fin_bank_lines` CHECK (((`direction` in (_utf8mb4'in',_utf8mb4'out')) and (`amount_minor` > 0) and (`status` in (_utf8mb4'open',_utf8mb4'matched',_utf8mb4'ignored')) and ((`status` <> _utf8mb4'matched') or ((`match_type` is not null) and (`match_id` is not null))) and ((`status` <> _utf8mb4'ignored') or (`note` is not null)))),
  CONSTRAINT `chk_fin_bank_lines_category` CHECK (((`category` is null) or ((`status` = _utf8mb4'ignored') and (((`direction` = _utf8mb4'out') and (`category` in (_utf8mb4'bank_fee',_utf8mb4'interest_tax',_utf8mb4'other_expense'))) or ((`direction` = _utf8mb4'in') and (`category` in (_utf8mb4'interest',_utf8mb4'other_income')))))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `fin_bank_lines_guard` BEFORE UPDATE ON `fin_bank_lines` FOR EACH ROW BEGIN IF NEW.amount_minor <> OLD.amount_minor OR NEW.line_date <> OLD.line_date OR NEW.direction <> OLD.direction OR NEW.fingerprint <> OLD.fingerprint OR NEW.description <> OLD.description THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a bank statement line cannot be changed'; END IF; END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `fin_bank_lines_no_delete` BEFORE DELETE ON `fin_bank_lines` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a bank statement line cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `fin_budgets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `fin_budgets` (
  `property_id` char(26) NOT NULL,
  `month` date NOT NULL,
  `department` varchar(16) NOT NULL,
  `revenue_minor` bigint unsigned NOT NULL,
  `cost_minor` bigint unsigned NOT NULL,
  `updated_by` char(26) NOT NULL,
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`property_id`,`month`,`department`),
  CONSTRAINT `fin_budgets_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_fin_budget_month` CHECK ((dayofmonth(`month`) = 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `fin_cash_deposits`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `fin_cash_deposits` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `number` varchar(20) NOT NULL,
  `cash_shift_id` char(26) NOT NULL,
  `deposited_minor` bigint unsigned NOT NULL,
  `expected_minor` bigint NOT NULL,
  `variance_minor` bigint NOT NULL,
  `reason` varchar(300) DEFAULT NULL,
  `note` varchar(200) DEFAULT NULL,
  `business_date` date NOT NULL,
  `received_by` char(26) NOT NULL,
  `created_at` timestamp(6) NOT NULL,
  `correlation_id` char(26) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fin_cash_deposits_property_id_number_unique` (`property_id`,`number`),
  UNIQUE KEY `fin_cash_deposits_cash_shift_id_unique` (`cash_shift_id`),
  KEY `fin_cash_deposits_received_by_foreign` (`received_by`),
  KEY `fin_cash_deposits_correlation_idx` (`correlation_id`),
  CONSTRAINT `fin_cash_deposits_cash_shift_id_foreign` FOREIGN KEY (`cash_shift_id`) REFERENCES `fin_cash_shifts` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fin_cash_deposits_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fin_cash_deposits_received_by_foreign` FOREIGN KEY (`received_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_fin_deposit_reason` CHECK (((`variance_minor` = 0) or ((`reason` is not null) and (char_length(trim(`reason`)) > 0)))),
  CONSTRAINT `chk_fin_deposit_variance` CHECK ((`variance_minor` = (cast(`deposited_minor` as signed) - `expected_minor`)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `fin_cash_deposits_no_update` BEFORE UPDATE ON `fin_cash_deposits` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a cash deposit cannot be changed' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `fin_cash_deposits_no_delete` BEFORE DELETE ON `fin_cash_deposits` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a cash deposit cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `fin_cash_exceptions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `fin_cash_exceptions` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `deposit_id` char(26) NOT NULL,
  `variance_minor` bigint NOT NULL,
  `status` varchar(10) NOT NULL DEFAULT 'open',
  `resolution` varchar(300) DEFAULT NULL,
  `resolved_at` timestamp(6) NULL DEFAULT NULL,
  `resolved_by` char(26) DEFAULT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fin_cash_exceptions_deposit_id_unique` (`deposit_id`),
  KEY `fin_cash_exceptions_property_id_status_index` (`property_id`,`status`),
  CONSTRAINT `fin_cash_exceptions_deposit_id_foreign` FOREIGN KEY (`deposit_id`) REFERENCES `fin_cash_deposits` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fin_cash_exceptions_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_fin_exception_resolved` CHECK ((((`status` = _utf8mb4'open') and (`resolved_at` is null) and (`resolved_by` is null) and (`resolution` is null)) or ((`status` <> _utf8mb4'open') and (`resolved_at` is not null) and (`resolved_by` is not null) and (`resolution` is not null) and (char_length(trim(`resolution`)) > 0)))),
  CONSTRAINT `chk_fin_exception_status` CHECK ((`status` in (_utf8mb4'open',_utf8mb4'explained',_utf8mb4'recovered',_utf8mb4'waived'))),
  CONSTRAINT `chk_fin_exception_variance` CHECK ((`variance_minor` <> 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `fin_cash_exceptions_guard` BEFORE UPDATE ON `fin_cash_exceptions` FOR EACH ROW BEGIN
    IF NOT (NEW.id <=> OLD.id AND NEW.property_id <=> OLD.property_id AND NEW.deposit_id <=> OLD.deposit_id AND NEW.variance_minor <=> OLD.variance_minor AND NEW.created_at <=> OLD.created_at) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'the facts of a cash exception cannot be changed';
    END IF;
    IF OLD.status <> 'open' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a settled cash exception cannot be changed';
    END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `fin_cash_exceptions_no_delete` BEFORE DELETE ON `fin_cash_exceptions` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a cash exception cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `fin_cash_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `fin_cash_settings` (
  `property_id` char(26) NOT NULL,
  `cash_opening_minor` bigint NOT NULL,
  `bank_opening_minor` bigint NOT NULL,
  `opening_date` date NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `updated_by` char(26) NOT NULL,
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`property_id`),
  CONSTRAINT `fin_cash_settings_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `fin_cash_shifts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `fin_cash_shifts` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `shift_id` char(26) NOT NULL,
  `number` varchar(30) NOT NULL,
  `cashier_id` char(26) NOT NULL,
  `closed_by` char(26) DEFAULT NULL,
  `closed_business_date` date NOT NULL,
  `currency` char(3) NOT NULL,
  `opening_float_minor` bigint NOT NULL,
  `expected_cash_minor` bigint NOT NULL,
  `counted_cash_minor` bigint NOT NULL,
  `shift_variance_minor` bigint NOT NULL,
  `drops_minor` bigint NOT NULL,
  `cash_net_minor` bigint NOT NULL,
  `event_id` char(26) NOT NULL,
  `correlation_id` char(26) DEFAULT NULL,
  `occurred_at` timestamp(6) NOT NULL,
  `created_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fin_cash_shifts_once` (`property_id`,`shift_id`),
  KEY `fin_cash_shifts_property_id_closed_business_date_index` (`property_id`,`closed_business_date`),
  CONSTRAINT `fin_cash_shifts_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `fin_cash_shifts_no_update` BEFORE UPDATE ON `fin_cash_shifts` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a cash shift cannot be changed' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `fin_cash_shifts_no_delete` BEFORE DELETE ON `fin_cash_shifts` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a cash shift cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `fin_correction_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `fin_correction_lines` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `correction_id` char(26) NOT NULL,
  `kind` varchar(8) NOT NULL,
  `outlet_code` varchar(20) DEFAULT NULL,
  `outlet_name` varchar(60) DEFAULT NULL,
  `method` varchar(20) DEFAULT NULL,
  `base_minor` bigint NOT NULL DEFAULT '0',
  `service_charge_minor` bigint NOT NULL DEFAULT '0',
  `tax_minor` bigint NOT NULL DEFAULT '0',
  `total_minor` bigint NOT NULL DEFAULT '0',
  `received_minor` bigint NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `fin_correction_lines_property_id_foreign` (`property_id`),
  KEY `fin_correction_lines_correction_id_index` (`correction_id`),
  CONSTRAINT `fin_correction_lines_correction_id_foreign` FOREIGN KEY (`correction_id`) REFERENCES `fin_corrections` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fin_correction_lines_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_fin_correction_line` CHECK ((((`kind` = _utf8mb4'revenue') and (`outlet_code` is not null) and (`method` is null) and (`received_minor` = 0) and (`total_minor` = ((`base_minor` + `service_charge_minor`) + `tax_minor`)) and (`total_minor` <> 0)) or ((`kind` = _utf8mb4'payment') and (`method` is not null) and (`outlet_code` is null) and (`base_minor` = 0) and (`service_charge_minor` = 0) and (`tax_minor` = 0) and (`total_minor` = 0) and (`received_minor` <> 0))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `fin_correction_lines_no_update` BEFORE UPDATE ON `fin_correction_lines` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a correction line cannot be changed' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `fin_correction_lines_no_delete` BEFORE DELETE ON `fin_correction_lines` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a correction line cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `fin_corrections`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `fin_corrections` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `number` varchar(20) NOT NULL,
  `day_id` char(26) NOT NULL,
  `business_date` date NOT NULL,
  `reason` varchar(300) NOT NULL,
  `status` varchar(10) NOT NULL DEFAULT 'pending',
  `requested_by` char(26) NOT NULL,
  `requested_at` timestamp(6) NOT NULL,
  `decided_by` char(26) DEFAULT NULL,
  `decided_at` timestamp(6) NULL DEFAULT NULL,
  `decision_note` varchar(300) DEFAULT NULL,
  `effective_date` date DEFAULT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp(6) NOT NULL,
  `correlation_id` char(26) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fin_corrections_property_id_number_unique` (`property_id`,`number`),
  KEY `fin_corrections_day_id_foreign` (`day_id`),
  KEY `fin_corrections_requested_by_foreign` (`requested_by`),
  KEY `fin_corrections_property_id_status_index` (`property_id`,`status`),
  KEY `fin_corrections_property_id_effective_date_index` (`property_id`,`effective_date`),
  KEY `fin_corrections_correlation_idx` (`correlation_id`),
  CONSTRAINT `fin_corrections_day_id_foreign` FOREIGN KEY (`day_id`) REFERENCES `fin_revenue_days` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fin_corrections_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fin_corrections_requested_by_foreign` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_fin_correction_decided` CHECK ((((`status` = _utf8mb4'pending') and (`decided_by` is null) and (`decided_at` is null) and (`effective_date` is null)) or ((`status` = _utf8mb4'approved') and (`decided_by` is not null) and (`decided_at` is not null) and (`effective_date` is not null)) or ((`status` = _utf8mb4'rejected') and (`decided_by` is not null) and (`decided_at` is not null) and (`effective_date` is null) and (`decision_note` is not null) and (char_length(trim(`decision_note`)) > 0)))),
  CONSTRAINT `chk_fin_correction_status` CHECK ((`status` in (_utf8mb4'pending',_utf8mb4'approved',_utf8mb4'rejected')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `fin_corrections_guard` BEFORE UPDATE ON `fin_corrections` FOR EACH ROW BEGIN
    IF NOT (NEW.id <=> OLD.id AND NEW.property_id <=> OLD.property_id AND NEW.number <=> OLD.number AND NEW.day_id <=> OLD.day_id AND NEW.business_date <=> OLD.business_date
        AND NEW.reason <=> OLD.reason AND NEW.requested_by <=> OLD.requested_by AND NEW.requested_at <=> OLD.requested_at) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'the facts of a correction cannot be changed';
    END IF;
    IF OLD.status <> 'pending' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a decided correction cannot be changed';
    END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `fin_corrections_no_delete` BEFORE DELETE ON `fin_corrections` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a correction cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `fin_customers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `fin_customers` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `code` varchar(20) NOT NULL,
  `name` varchar(120) NOT NULL,
  `kind` varchar(8) NOT NULL,
  `terms_days` smallint unsigned NOT NULL DEFAULT '30',
  `company_id` char(26) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fin_customers_property_id_code_unique` (`property_id`,`code`),
  UNIQUE KEY `fin_customers_company_once` (`property_id`,`company_id`),
  CONSTRAINT `fin_customers_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_fin_customer_kind` CHECK ((`kind` in (_utf8mb4'company',_utf8mb4'agent',_utf8mb4'ota',_utf8mb4'other'))),
  CONSTRAINT `chk_fin_customer_terms` CHECK ((`terms_days` <= 180))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `fin_customers_guard` BEFORE UPDATE ON `fin_customers` FOR EACH ROW BEGIN
    IF NOT (NEW.id <=> OLD.id AND NEW.property_id <=> OLD.property_id AND NEW.code <=> OLD.code AND NEW.kind <=> OLD.kind AND NEW.company_id <=> OLD.company_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'the code, kind and company of a customer cannot be changed';
    END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `fin_customers_no_delete` BEFORE DELETE ON `fin_customers` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a customer cannot be deleted; deactivate it' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `fin_depreciation_entries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `fin_depreciation_entries` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `asset_id` char(26) NOT NULL,
  `period` char(7) NOT NULL,
  `amount_minor` bigint unsigned NOT NULL,
  `accumulated_after_minor` bigint unsigned NOT NULL,
  `created_by` char(26) NOT NULL,
  `created_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fin_depreciation_once` (`asset_id`,`period`),
  KEY `fin_depreciation_entries_property_id_period_index` (`property_id`,`period`),
  CONSTRAINT `fin_depreciation_entries_asset_id_foreign` FOREIGN KEY (`asset_id`) REFERENCES `fin_fixed_assets` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fin_depreciation_entries_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `fin_depreciation_entries_no_update` BEFORE UPDATE ON `fin_depreciation_entries` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a depreciation entry cannot be changed' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `fin_depreciation_entries_no_delete` BEFORE DELETE ON `fin_depreciation_entries` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a depreciation entry cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `fin_exceptions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `fin_exceptions` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `number` varchar(20) NOT NULL,
  `kind` varchar(24) NOT NULL,
  `business_date` date NOT NULL,
  `amount_minor` bigint unsigned NOT NULL,
  `method` varchar(14) DEFAULT NULL,
  `reference` varchar(80) DEFAULT NULL,
  `folio_ref` varchar(40) DEFAULT NULL,
  `description` varchar(300) NOT NULL,
  `status` varchar(10) NOT NULL DEFAULT 'open',
  `resolution` varchar(300) DEFAULT NULL,
  `correction_id` char(26) DEFAULT NULL,
  `resolved_by` char(26) DEFAULT NULL,
  `resolved_at` timestamp(6) NULL DEFAULT NULL,
  `created_by` char(26) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp(6) NOT NULL,
  `correlation_id` char(26) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fin_exceptions_property_id_number_unique` (`property_id`,`number`),
  KEY `fin_exceptions_created_by_foreign` (`created_by`),
  KEY `fin_exceptions_property_id_status_business_date_index` (`property_id`,`status`,`business_date`),
  KEY `fin_exceptions_correlation_idx` (`correlation_id`),
  CONSTRAINT `fin_exceptions_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fin_exceptions_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_fin_exception_kind` CHECK ((`kind` in (_utf8mb4'refund',_utf8mb4'chargeback',_utf8mb4'settlement_discrepancy',_utf8mb4'unknown_payment',_utf8mb4'late_sale',_utf8mb4'late_refund'))),
  CONSTRAINT `chk_fin_exceptions_amount` CHECK ((`amount_minor` > 0)),
  CONSTRAINT `chk_fin_exceptions_resolved` CHECK ((((`status` = _utf8mb4'open') and (`resolved_by` is null) and (`resolved_at` is null) and (`resolution` is null) and (`correction_id` is null)) or ((`status` <> _utf8mb4'open') and (`resolved_by` is not null) and (`resolved_at` is not null) and (`resolution` is not null) and (char_length(trim(`resolution`)) > 0) and ((`status` = _utf8mb4'adjusted') = (`correction_id` is not null))))),
  CONSTRAINT `chk_fin_exceptions_status` CHECK ((`status` in (_utf8mb4'open',_utf8mb4'matched',_utf8mb4'adjusted',_utf8mb4'waived')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `fin_exceptions_guard` BEFORE UPDATE ON `fin_exceptions` FOR EACH ROW BEGIN
    IF NOT (NEW.id <=> OLD.id AND NEW.property_id <=> OLD.property_id AND NEW.number <=> OLD.number AND NEW.kind <=> OLD.kind AND NEW.business_date <=> OLD.business_date
        AND NEW.amount_minor <=> OLD.amount_minor AND NEW.method <=> OLD.method AND NEW.reference <=> OLD.reference AND NEW.folio_ref <=> OLD.folio_ref
        AND NEW.description <=> OLD.description AND NEW.created_by <=> OLD.created_by AND NEW.created_at <=> OLD.created_at) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'the facts of an exception cannot be changed';
    END IF;
    IF OLD.status <> 'open' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a reconciled exception cannot be changed';
    END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `fin_exceptions_no_delete` BEFORE DELETE ON `fin_exceptions` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'an exception cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `fin_fixed_assets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `fin_fixed_assets` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `code` varchar(20) NOT NULL,
  `name` varchar(120) NOT NULL,
  `category` varchar(12) NOT NULL,
  `acquired_on` date NOT NULL,
  `cost_minor` bigint unsigned NOT NULL,
  `salvage_minor` bigint unsigned NOT NULL DEFAULT '0',
  `life_months` smallint unsigned NOT NULL,
  `maintenance_asset_id` char(26) DEFAULT NULL,
  `notes` varchar(300) DEFAULT NULL,
  `status` varchar(8) NOT NULL DEFAULT 'active',
  `disposed_on` date DEFAULT NULL,
  `disposal_kind` varchar(8) DEFAULT NULL,
  `disposal_proceeds_minor` bigint unsigned DEFAULT NULL,
  `disposal_method` varchar(8) DEFAULT NULL,
  `disposal_reason` varchar(200) DEFAULT NULL,
  `created_by` char(26) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fin_fixed_assets_property_id_code_unique` (`property_id`,`code`),
  KEY `fin_fixed_assets_property_id_status_index` (`property_id`,`status`),
  CONSTRAINT `fin_fixed_assets_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_fin_fixed_assets` CHECK (((`cost_minor` > 0) and (`salvage_minor` < `cost_minor`) and (`life_months` between 1 and 600) and (`category` in (_utf8mb4'building',_utf8mb4'furniture',_utf8mb4'equipment',_utf8mb4'vehicle',_utf8mb4'it',_utf8mb4'other')) and (`status` in (_utf8mb4'active',_utf8mb4'disposed')) and ((`status` = _utf8mb4'active') or ((`disposed_on` is not null) and (`disposal_kind` in (_utf8mb4'sale',_utf8mb4'scrap',_utf8mb4'lost')) and (`disposal_proceeds_minor` is not null) and (`disposal_reason` is not null)))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `fin_fixed_assets_guard` BEFORE UPDATE ON `fin_fixed_assets` FOR EACH ROW BEGIN IF NEW.cost_minor <> OLD.cost_minor OR NEW.salvage_minor <> OLD.salvage_minor OR NEW.life_months <> OLD.life_months OR NEW.acquired_on <> OLD.acquired_on OR OLD.status = 'disposed' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'the facts of a fixed asset cannot be changed'; END IF; END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `fin_fixed_assets_no_delete` BEFORE DELETE ON `fin_fixed_assets` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a fixed asset cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `fin_food_cost_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `fin_food_cost_settings` (
  `property_id` char(26) NOT NULL,
  `target_bp` smallint unsigned NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `updated_by` char(26) NOT NULL,
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`property_id`),
  CONSTRAINT `fin_food_cost_settings_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_food_cost_target` CHECK ((`target_bp` between 500 and 9000))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `fin_journal_accounts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `fin_journal_accounts` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `account_key` varchar(40) NOT NULL,
  `code` varchar(30) NOT NULL,
  `name` varchar(80) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp(6) NOT NULL,
  `updated_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fin_journal_accounts_key` (`property_id`,`account_key`),
  CONSTRAINT `fin_journal_accounts_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `fin_payment_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `fin_payment_lines` (
  `property_id` char(26) NOT NULL,
  `day_id` char(26) NOT NULL,
  `business_date` date NOT NULL,
  `method` varchar(20) NOT NULL,
  `received_minor` bigint NOT NULL,
  `paid_back_minor` bigint NOT NULL,
  `entries` int unsigned NOT NULL,
  PRIMARY KEY (`day_id`,`method`),
  KEY `fin_payment_lines_property_id_business_date_index` (`property_id`,`business_date`),
  CONSTRAINT `fin_payment_lines_day_id_foreign` FOREIGN KEY (`day_id`) REFERENCES `fin_revenue_days` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fin_payment_lines_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `fin_payment_lines_no_update` BEFORE UPDATE ON `fin_payment_lines` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a payment line cannot be changed' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `fin_payment_lines_no_delete` BEFORE DELETE ON `fin_payment_lines` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a payment line cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `fin_petty_entries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `fin_petty_entries` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `fund_id` char(26) NOT NULL,
  `seq` int unsigned NOT NULL,
  `kind` varchar(14) NOT NULL,
  `signed_minor` bigint NOT NULL,
  `voucher_id` char(26) DEFAULT NULL,
  `settlement_id` char(26) DEFAULT NULL,
  `note` varchar(200) DEFAULT NULL,
  `business_date` date NOT NULL,
  `created_by` char(26) NOT NULL,
  `created_at` timestamp(6) NOT NULL,
  `correlation_id` char(26) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fin_petty_entries_fund_id_seq_unique` (`fund_id`,`seq`),
  KEY `fin_petty_entries_property_id_foreign` (`property_id`),
  KEY `fin_petty_entries_created_by_foreign` (`created_by`),
  KEY `fin_petty_entries_correlation_idx` (`correlation_id`),
  CONSTRAINT `fin_petty_entries_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fin_petty_entries_fund_id_foreign` FOREIGN KEY (`fund_id`) REFERENCES `fin_petty_funds` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fin_petty_entries_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_fin_petty_entry` CHECK ((((`kind` = _utf8mb4'expense') and (`signed_minor` < 0)) or ((`kind` in (_utf8mb4'opening',_utf8mb4'void',_utf8mb4'replenishment')) and (`signed_minor` > 0)) or ((`kind` = _utf8mb4'difference') and (`signed_minor` <> 0))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `fin_petty_entries_not_below_zero` BEFORE INSERT ON `fin_petty_entries` FOR EACH ROW BEGIN
    IF (SELECT COALESCE(SUM(signed_minor), 0) FROM fin_petty_entries WHERE fund_id = NEW.fund_id) + NEW.signed_minor < 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a petty cash fund cannot go below zero';
    END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `fin_petty_entries_no_update` BEFORE UPDATE ON `fin_petty_entries` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a petty cash entry cannot be changed' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `fin_petty_entries_no_delete` BEFORE DELETE ON `fin_petty_entries` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a petty cash entry cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `fin_petty_funds`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `fin_petty_funds` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `code` varchar(12) NOT NULL,
  `name` varchar(80) NOT NULL,
  `custodian_id` char(26) NOT NULL,
  `imprest_minor` bigint unsigned NOT NULL,
  `max_voucher_minor` bigint unsigned DEFAULT NULL,
  `currency` char(3) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fin_petty_funds_property_id_code_unique` (`property_id`,`code`),
  CONSTRAINT `fin_petty_funds_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_fin_petty_fund` CHECK (((`imprest_minor` > 0) and ((`max_voucher_minor` is null) or (`max_voucher_minor` > 0))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `fin_petty_funds_guard` BEFORE UPDATE ON `fin_petty_funds` FOR EACH ROW BEGIN
    IF NOT (NEW.id <=> OLD.id AND NEW.property_id <=> OLD.property_id AND NEW.code <=> OLD.code AND NEW.imprest_minor <=> OLD.imprest_minor AND NEW.currency <=> OLD.currency) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'the code, the amount and the currency of a petty cash fund cannot be changed';
    END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `fin_petty_funds_no_delete` BEFORE DELETE ON `fin_petty_funds` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a petty cash fund cannot be deleted; deactivate it' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `fin_petty_proofs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `fin_petty_proofs` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `voucher_id` char(26) NOT NULL,
  `file_id` char(26) NOT NULL,
  `display_name` varchar(120) DEFAULT NULL,
  `created_by` char(26) NOT NULL,
  `created_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `fin_petty_proofs_property_id_foreign` (`property_id`),
  KEY `fin_petty_proofs_created_by_foreign` (`created_by`),
  KEY `fin_petty_proofs_voucher_id_index` (`voucher_id`),
  CONSTRAINT `fin_petty_proofs_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fin_petty_proofs_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fin_petty_proofs_voucher_id_foreign` FOREIGN KEY (`voucher_id`) REFERENCES `fin_petty_vouchers` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `fin_petty_proofs_no_update` BEFORE UPDATE ON `fin_petty_proofs` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a petty cash proof cannot be changed' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `fin_petty_proofs_no_delete` BEFORE DELETE ON `fin_petty_proofs` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a petty cash proof cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `fin_petty_settlement_vouchers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `fin_petty_settlement_vouchers` (
  `settlement_id` char(26) NOT NULL,
  `voucher_id` char(26) NOT NULL,
  PRIMARY KEY (`settlement_id`,`voucher_id`),
  KEY `fin_petty_settlement_vouchers_voucher_id_index` (`voucher_id`),
  CONSTRAINT `fin_petty_settlement_vouchers_settlement_id_foreign` FOREIGN KEY (`settlement_id`) REFERENCES `fin_petty_settlements` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fin_petty_settlement_vouchers_voucher_id_foreign` FOREIGN KEY (`voucher_id`) REFERENCES `fin_petty_vouchers` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `fin_petty_settlement_vouchers_no_update` BEFORE UPDATE ON `fin_petty_settlement_vouchers` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a settlement voucher cannot be changed' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `fin_petty_settlement_vouchers_no_delete` BEFORE DELETE ON `fin_petty_settlement_vouchers` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a settlement voucher cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `fin_petty_settlements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `fin_petty_settlements` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `fund_id` char(26) NOT NULL,
  `number` varchar(20) NOT NULL,
  `status` varchar(10) NOT NULL DEFAULT 'submitted',
  `voucher_total_minor` bigint unsigned NOT NULL,
  `book_minor` bigint NOT NULL,
  `counted_minor` bigint unsigned NOT NULL,
  `variance_minor` bigint NOT NULL,
  `variance_reason` varchar(300) DEFAULT NULL,
  `replenish_minor` bigint unsigned NOT NULL,
  `business_date` date NOT NULL,
  `submitted_by` char(26) NOT NULL,
  `submitted_at` timestamp(6) NOT NULL,
  `decided_by` char(26) DEFAULT NULL,
  `decided_at` timestamp(6) NULL DEFAULT NULL,
  `decision_note` varchar(300) DEFAULT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp(6) NOT NULL,
  `pending_fund_key` char(26) DEFAULT NULL,
  `correlation_id` char(26) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fin_petty_settlements_property_id_number_unique` (`property_id`,`number`),
  UNIQUE KEY `fin_petty_one_pending_per_fund` (`pending_fund_key`),
  KEY `fin_petty_settlements_submitted_by_foreign` (`submitted_by`),
  KEY `fin_petty_settlements_fund_id_status_index` (`fund_id`,`status`),
  KEY `fin_petty_settlements_correlation_idx` (`correlation_id`),
  CONSTRAINT `fin_petty_settlements_fund_id_foreign` FOREIGN KEY (`fund_id`) REFERENCES `fin_petty_funds` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fin_petty_settlements_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fin_petty_settlements_submitted_by_foreign` FOREIGN KEY (`submitted_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_fin_petty_settlement_decided` CHECK ((((`status` = _utf8mb4'submitted') and (`decided_by` is null) and (`decided_at` is null)) or ((`status` <> _utf8mb4'submitted') and (`decided_by` is not null) and (`decided_at` is not null)))),
  CONSTRAINT `chk_fin_petty_settlement_status` CHECK ((`status` in (_utf8mb4'submitted',_utf8mb4'approved',_utf8mb4'rejected'))),
  CONSTRAINT `chk_fin_petty_settlement_variance` CHECK (((`variance_minor` = (cast(`counted_minor` as signed) - `book_minor`)) and ((`variance_minor` = 0) or ((`variance_reason` is not null) and (char_length(trim(`variance_reason`)) > 0)))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `fin_petty_settlements_pending_key_insert` BEFORE INSERT ON `fin_petty_settlements` FOR EACH ROW BEGIN
    SET NEW.pending_fund_key = CASE WHEN NEW.status = 'submitted' THEN NEW.fund_id ELSE NULL END;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `fin_petty_settlements_guard` BEFORE UPDATE ON `fin_petty_settlements` FOR EACH ROW BEGIN
    IF NOT (NEW.id <=> OLD.id AND NEW.property_id <=> OLD.property_id AND NEW.fund_id <=> OLD.fund_id AND NEW.number <=> OLD.number AND NEW.voucher_total_minor <=> OLD.voucher_total_minor
        AND NEW.book_minor <=> OLD.book_minor AND NEW.counted_minor <=> OLD.counted_minor AND NEW.variance_minor <=> OLD.variance_minor AND NEW.replenish_minor <=> OLD.replenish_minor
        AND NEW.submitted_by <=> OLD.submitted_by AND NEW.submitted_at <=> OLD.submitted_at) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'the facts of a petty cash settlement cannot be changed';
    END IF;
    IF OLD.status <> 'submitted' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a decided petty cash settlement cannot be changed';
    END IF;
    SET NEW.pending_fund_key = CASE WHEN NEW.status = 'submitted' THEN NEW.fund_id ELSE NULL END;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `fin_petty_settlements_no_delete` BEFORE DELETE ON `fin_petty_settlements` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a petty cash settlement cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `fin_petty_voids`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `fin_petty_voids` (
  `voucher_id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `reason` varchar(200) NOT NULL,
  `voided_by` char(26) NOT NULL,
  `business_date` date NOT NULL,
  `created_at` timestamp(6) NOT NULL,
  `correlation_id` char(26) DEFAULT NULL,
  PRIMARY KEY (`voucher_id`),
  KEY `fin_petty_voids_property_id_foreign` (`property_id`),
  KEY `fin_petty_voids_voided_by_foreign` (`voided_by`),
  KEY `fin_petty_voids_correlation_idx` (`correlation_id`),
  CONSTRAINT `fin_petty_voids_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fin_petty_voids_voided_by_foreign` FOREIGN KEY (`voided_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fin_petty_voids_voucher_id_foreign` FOREIGN KEY (`voucher_id`) REFERENCES `fin_petty_vouchers` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `fin_petty_voids_no_update` BEFORE UPDATE ON `fin_petty_voids` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a petty cash void cannot be changed' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `fin_petty_voids_no_delete` BEFORE DELETE ON `fin_petty_voids` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a petty cash void cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `fin_petty_vouchers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `fin_petty_vouchers` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `fund_id` char(26) NOT NULL,
  `number` varchar(20) NOT NULL,
  `voucher_date` date NOT NULL,
  `payee` varchar(120) NOT NULL,
  `description` varchar(200) NOT NULL,
  `expense_account_id` char(26) NOT NULL,
  `receipt_ref` varchar(40) DEFAULT NULL,
  `no_receipt_reason` varchar(200) DEFAULT NULL,
  `amount_minor` bigint unsigned NOT NULL,
  `business_date` date NOT NULL,
  `created_by` char(26) NOT NULL,
  `created_at` timestamp(6) NOT NULL,
  `correlation_id` char(26) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fin_petty_vouchers_property_id_number_unique` (`property_id`,`number`),
  KEY `fin_petty_vouchers_created_by_foreign` (`created_by`),
  KEY `fin_petty_vouchers_fund_id_voucher_date_index` (`fund_id`,`voucher_date`),
  KEY `fin_petty_vouchers_correlation_idx` (`correlation_id`),
  CONSTRAINT `fin_petty_vouchers_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fin_petty_vouchers_fund_id_foreign` FOREIGN KEY (`fund_id`) REFERENCES `fin_petty_funds` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fin_petty_vouchers_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_fin_petty_voucher` CHECK ((`amount_minor` > 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `fin_petty_vouchers_no_update` BEFORE UPDATE ON `fin_petty_vouchers` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a petty cash voucher cannot be changed' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `fin_petty_vouchers_no_delete` BEFORE DELETE ON `fin_petty_vouchers` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a petty cash voucher cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `fin_pnl_outlet_map`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `fin_pnl_outlet_map` (
  `property_id` char(26) NOT NULL,
  `outlet_code` varchar(20) NOT NULL,
  `department` varchar(16) NOT NULL,
  `updated_by` char(26) NOT NULL,
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`property_id`,`outlet_code`),
  CONSTRAINT `fin_pnl_outlet_map_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `fin_pos_refunds`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `fin_pos_refunds` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `bill_id` char(26) NOT NULL,
  `bill_number` varchar(20) NOT NULL,
  `refund_number` varchar(20) NOT NULL,
  `outlet_id` char(26) NOT NULL,
  `outlet_code` varchar(12) NOT NULL,
  `source` varchar(40) NOT NULL,
  `business_date` date NOT NULL,
  `currency` char(3) NOT NULL,
  `base_minor` bigint NOT NULL,
  `service_charge_minor` bigint NOT NULL,
  `tax_minor` bigint NOT NULL,
  `total_minor` bigint NOT NULL,
  `cash_minor` bigint NOT NULL DEFAULT '0',
  `card_minor` bigint NOT NULL DEFAULT '0',
  `qris_minor` bigint NOT NULL DEFAULT '0',
  `late` tinyint(1) NOT NULL DEFAULT '0',
  `event_id` char(26) NOT NULL,
  `actor_id` char(26) DEFAULT NULL,
  `occurred_at` timestamp(6) NOT NULL,
  `created_at` timestamp(6) NOT NULL,
  `correlation_id` char(26) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fin_pos_refunds_once` (`property_id`,`bill_id`),
  KEY `fin_pos_refunds_property_id_business_date_index` (`property_id`,`business_date`),
  KEY `fin_pos_refunds_correlation_idx` (`correlation_id`),
  CONSTRAINT `fin_pos_refunds_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_fin_pos_refund_total` CHECK (((`total_minor` = ((`base_minor` + `service_charge_minor`) + `tax_minor`)) and (`total_minor` = ((`cash_minor` + `card_minor`) + `qris_minor`)) and (`total_minor` > 0)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `fin_pos_refunds_no_update` BEFORE UPDATE ON `fin_pos_refunds` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a refund cannot be changed' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `fin_pos_refunds_no_delete` BEFORE DELETE ON `fin_pos_refunds` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a refund cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `fin_pos_sales`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `fin_pos_sales` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `bill_id` char(26) NOT NULL,
  `bill_number` varchar(20) NOT NULL,
  `outlet_id` char(26) NOT NULL,
  `outlet_code` varchar(12) NOT NULL,
  `source` varchar(40) NOT NULL,
  `business_date` date NOT NULL,
  `currency` char(3) NOT NULL,
  `base_minor` bigint NOT NULL,
  `service_charge_minor` bigint NOT NULL,
  `tax_minor` bigint NOT NULL,
  `total_minor` bigint NOT NULL,
  `cash_minor` bigint NOT NULL DEFAULT '0',
  `card_minor` bigint NOT NULL DEFAULT '0',
  `qris_minor` bigint NOT NULL DEFAULT '0',
  `room_minor` bigint NOT NULL DEFAULT '0',
  `late` tinyint(1) NOT NULL DEFAULT '0',
  `event_id` char(26) NOT NULL,
  `correlation_id` char(26) DEFAULT NULL,
  `actor_id` char(26) DEFAULT NULL,
  `occurred_at` timestamp(6) NOT NULL,
  `created_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fin_pos_sales_once` (`property_id`,`bill_id`),
  KEY `fin_pos_sales_property_id_business_date_index` (`property_id`,`business_date`),
  CONSTRAINT `fin_pos_sales_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_fin_pos_total` CHECK (((`total_minor` = ((`base_minor` + `service_charge_minor`) + `tax_minor`)) and (`total_minor` = (((`cash_minor` + `card_minor`) + `qris_minor`) + `room_minor`))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `fin_pos_sales_no_update` BEFORE UPDATE ON `fin_pos_sales` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a point of sale bill cannot be changed' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `fin_pos_sales_no_delete` BEFORE DELETE ON `fin_pos_sales` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a point of sale bill cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `fin_recurring_expenses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `fin_recurring_expenses` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `name` varchar(80) NOT NULL,
  `expense_account_id` char(26) NOT NULL,
  `payee` varchar(120) DEFAULT NULL,
  `amount_minor` bigint unsigned NOT NULL,
  `frequency` varchar(10) NOT NULL,
  `due_day` tinyint unsigned NOT NULL,
  `start_month` date NOT NULL,
  `end_date` date DEFAULT NULL,
  `remind_days` tinyint unsigned NOT NULL DEFAULT '7',
  `next_due` date NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fin_recurring_expenses_property_id_is_active_next_due_index` (`property_id`,`is_active`,`next_due`),
  CONSTRAINT `fin_recurring_expenses_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_fin_recurring` CHECK (((`amount_minor` > 0) and (`frequency` in (_utf8mb4'monthly',_utf8mb4'quarterly',_utf8mb4'yearly')) and (`due_day` between 1 and 31) and (`remind_days` <= 60) and (dayofmonth(`start_month`) = 1)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `fin_recurring_guard` BEFORE UPDATE ON `fin_recurring_expenses` FOR EACH ROW BEGIN
    IF NOT (NEW.id <=> OLD.id AND NEW.property_id <=> OLD.property_id AND NEW.frequency <=> OLD.frequency AND NEW.due_day <=> OLD.due_day AND NEW.start_month <=> OLD.start_month) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'the schedule of a recurring expense cannot be changed';
    END IF;
    IF NEW.next_due < OLD.next_due THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'the next due date of a recurring expense only moves forward';
    END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `fin_recurring_no_delete` BEFORE DELETE ON `fin_recurring_expenses` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a recurring expense cannot be deleted; deactivate it' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `fin_recurring_occurrences`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `fin_recurring_occurrences` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `recurring_id` char(26) NOT NULL,
  `due_date` date NOT NULL,
  `status` varchar(8) NOT NULL,
  `amount_minor` bigint unsigned DEFAULT NULL,
  `paid_on` date DEFAULT NULL,
  `method` varchar(12) DEFAULT NULL,
  `reference` varchar(60) DEFAULT NULL,
  `note` varchar(200) DEFAULT NULL,
  `business_date` date NOT NULL,
  `settled_by` char(26) NOT NULL,
  `created_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fin_recurring_occurrences_recurring_id_due_date_unique` (`recurring_id`,`due_date`),
  KEY `fin_recurring_occurrences_settled_by_foreign` (`settled_by`),
  KEY `fin_recurring_occurrences_property_id_paid_on_index` (`property_id`,`paid_on`),
  CONSTRAINT `fin_recurring_occurrences_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fin_recurring_occurrences_recurring_id_foreign` FOREIGN KEY (`recurring_id`) REFERENCES `fin_recurring_expenses` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fin_recurring_occurrences_settled_by_foreign` FOREIGN KEY (`settled_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_fin_occurrence` CHECK ((((`status` = _utf8mb4'paid') and (`amount_minor` > 0) and (`paid_on` is not null) and (`method` in (_utf8mb4'cash',_utf8mb4'transfer',_utf8mb4'giro',_utf8mb4'other'))) or ((`status` = _utf8mb4'skipped') and (`amount_minor` is null) and (`paid_on` is null) and (`method` is null) and (`note` is not null) and (char_length(trim(`note`)) > 0))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `fin_recurring_occurrences_no_update` BEFORE UPDATE ON `fin_recurring_occurrences` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a settled due date cannot be changed' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `fin_recurring_occurrences_no_delete` BEFORE DELETE ON `fin_recurring_occurrences` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a settled due date cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `fin_revenue_days`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `fin_revenue_days` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `business_date` date NOT NULL,
  `currency` char(3) NOT NULL,
  `night_audit_id` char(26) NOT NULL,
  `base_minor` bigint NOT NULL,
  `service_charge_minor` bigint NOT NULL,
  `tax_minor` bigint NOT NULL,
  `total_minor` bigint NOT NULL,
  `collected_minor` bigint NOT NULL,
  `status` varchar(10) NOT NULL DEFAULT 'recorded',
  `verified_at` timestamp(6) NULL DEFAULT NULL,
  `verified_by` char(26) DEFAULT NULL,
  `verification_note` varchar(300) DEFAULT NULL,
  `event_id` char(26) NOT NULL,
  `correlation_id` char(26) DEFAULT NULL,
  `actor_id` char(26) DEFAULT NULL,
  `occurred_at` timestamp(6) NOT NULL,
  `created_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fin_revenue_days_property_id_business_date_unique` (`property_id`,`business_date`),
  UNIQUE KEY `fin_revenue_days_once` (`property_id`,`night_audit_id`),
  CONSTRAINT `fin_revenue_days_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_fin_days_status` CHECK ((`status` in (_utf8mb4'recorded',_utf8mb4'verified'))),
  CONSTRAINT `chk_fin_days_total` CHECK ((`total_minor` = ((`base_minor` + `service_charge_minor`) + `tax_minor`))),
  CONSTRAINT `chk_fin_days_verified` CHECK ((((`status` = _utf8mb4'recorded') and (`verified_at` is null) and (`verified_by` is null)) or ((`status` = _utf8mb4'verified') and (`verified_at` is not null) and (`verified_by` is not null))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `fin_revenue_days_guard` BEFORE UPDATE ON `fin_revenue_days` FOR EACH ROW BEGIN
    IF NOT (NEW.id <=> OLD.id AND NEW.property_id <=> OLD.property_id AND NEW.business_date <=> OLD.business_date AND NEW.currency <=> OLD.currency AND NEW.night_audit_id <=> OLD.night_audit_id
        AND NEW.base_minor <=> OLD.base_minor AND NEW.service_charge_minor <=> OLD.service_charge_minor AND NEW.tax_minor <=> OLD.tax_minor AND NEW.total_minor <=> OLD.total_minor
        AND NEW.collected_minor <=> OLD.collected_minor AND NEW.event_id <=> OLD.event_id AND NEW.actor_id <=> OLD.actor_id AND NEW.occurred_at <=> OLD.occurred_at) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'the figures of a revenue day cannot be changed';
    END IF;
    IF OLD.status = 'verified' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a verified revenue day cannot be changed';
    END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `fin_revenue_days_no_delete` BEFORE DELETE ON `fin_revenue_days` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a revenue day cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `fin_revenue_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `fin_revenue_lines` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `day_id` char(26) NOT NULL,
  `business_date` date NOT NULL,
  `source` varchar(40) NOT NULL,
  `outlet_code` varchar(20) DEFAULT NULL,
  `outlet_name` varchar(60) DEFAULT NULL,
  `base_minor` bigint NOT NULL,
  `service_charge_minor` bigint NOT NULL,
  `tax_minor` bigint NOT NULL,
  `total_minor` bigint NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fin_revenue_lines_day_id_source_unique` (`day_id`,`source`),
  KEY `fin_revenue_lines_property_id_business_date_index` (`property_id`,`business_date`),
  CONSTRAINT `fin_revenue_lines_day_id_foreign` FOREIGN KEY (`day_id`) REFERENCES `fin_revenue_days` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fin_revenue_lines_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_fin_lines_total` CHECK ((`total_minor` = ((`base_minor` + `service_charge_minor`) + `tax_minor`)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `fin_revenue_lines_no_update` BEFORE UPDATE ON `fin_revenue_lines` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a revenue line cannot be changed' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `fin_revenue_lines_no_delete` BEFORE DELETE ON `fin_revenue_lines` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a revenue line cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `fin_settlements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `fin_settlements` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `number` varchar(20) NOT NULL,
  `method` varchar(8) NOT NULL,
  `provider` varchar(80) NOT NULL,
  `covers_from` date NOT NULL,
  `covers_to` date NOT NULL,
  `settled_on` date NOT NULL,
  `gross_minor` bigint unsigned NOT NULL,
  `fee_minor` bigint unsigned NOT NULL,
  `net_minor` bigint unsigned NOT NULL,
  `bank_reference` varchar(60) NOT NULL,
  `system_minor` bigint NOT NULL,
  `gross_diff_minor` bigint NOT NULL,
  `net_diff_minor` bigint NOT NULL,
  `fee_bp` smallint unsigned NOT NULL,
  `exception_id` char(26) DEFAULT NULL,
  `note` varchar(200) DEFAULT NULL,
  `recorded_by` char(26) NOT NULL,
  `created_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fin_settlements_property_id_number_unique` (`property_id`,`number`),
  UNIQUE KEY `fin_settlements_property_id_bank_reference_unique` (`property_id`,`bank_reference`),
  KEY `fin_settlements_property_id_method_covers_from_index` (`property_id`,`method`,`covers_from`),
  KEY `fin_settlements_property_id_settled_on_index` (`property_id`,`settled_on`),
  CONSTRAINT `fin_settlements_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_fin_settlement` CHECK (((`method` in (_utf8mb4'qris',_utf8mb4'card')) and (`covers_to` >= `covers_from`) and (`fee_minor` <= `gross_minor`) and (`gross_minor` > 0)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `fin_settlements_no_update` BEFORE UPDATE ON `fin_settlements` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a settlement cannot be changed' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `fin_settlements_no_delete` BEFORE DELETE ON `fin_settlements` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a settlement cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `fin_tax_filings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `fin_tax_filings` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `period` char(7) NOT NULL,
  `base_minor` bigint NOT NULL,
  `service_charge_minor` bigint NOT NULL,
  `tax_minor` bigint NOT NULL,
  `reported_on` date NOT NULL,
  `report_reference` varchar(80) DEFAULT NULL,
  `reported_by` char(26) NOT NULL,
  `deposited_on` date DEFAULT NULL,
  `deposited_minor` bigint DEFAULT NULL,
  `deposit_reference` varchar(80) DEFAULT NULL,
  `deposited_by` char(26) DEFAULT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fin_tax_filings_property_id_period_unique` (`property_id`,`period`),
  CONSTRAINT `fin_tax_filings_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_fin_tax_filings` CHECK ((((`deposited_on` is null) and (`deposited_minor` is null) and (`deposit_reference` is null) and (`deposited_by` is null)) or ((`deposited_on` is not null) and (`deposited_minor` is not null) and (`deposit_reference` is not null) and (`deposited_by` is not null) and (`deposited_on` >= `reported_on`))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `fin_tax_filings_guard` BEFORE UPDATE ON `fin_tax_filings` FOR EACH ROW BEGIN IF NOT (NEW.period <=> OLD.period AND NEW.base_minor <=> OLD.base_minor AND NEW.service_charge_minor <=> OLD.service_charge_minor AND NEW.tax_minor <=> OLD.tax_minor AND NEW.reported_on <=> OLD.reported_on AND NEW.reported_by <=> OLD.reported_by) OR OLD.deposited_on IS NOT NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a tax filing cannot be changed'; END IF; END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `fin_tax_filings_no_delete` BEFORE DELETE ON `fin_tax_filings` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a tax filing cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `fin_tax_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `fin_tax_settings` (
  `property_id` char(26) NOT NULL,
  `report_day` tinyint unsigned NOT NULL,
  `updated_by` char(26) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`property_id`),
  CONSTRAINT `fin_tax_settings_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_fin_tax_settings` CHECK ((`report_day` between 1 and 28))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `finance_expense_accounts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `finance_expense_accounts` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `code` varchar(12) NOT NULL,
  `name` varchar(80) NOT NULL,
  `department` varchar(16) NOT NULL,
  `category` varchar(16) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `finance_expense_accounts_property_id_code_unique` (`property_id`,`code`),
  CONSTRAINT `finance_expense_accounts_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `finance_payroll_disbursements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `finance_payroll_disbursements` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `run_id` char(26) NOT NULL,
  `number` varchar(24) NOT NULL,
  `period` char(7) NOT NULL,
  `employees_count` int unsigned NOT NULL,
  `net_minor` bigint NOT NULL,
  `tax_minor` bigint NOT NULL,
  `employee_social_minor` bigint NOT NULL,
  `employer_social_minor` bigint NOT NULL,
  `status` varchar(10) NOT NULL,
  `verified_by` char(26) DEFAULT NULL,
  `verified_at` timestamp(6) NULL DEFAULT NULL,
  `paid_by` char(26) DEFAULT NULL,
  `paid_at` timestamp(6) NULL DEFAULT NULL,
  `method` varchar(12) DEFAULT NULL,
  `reference` varchar(80) DEFAULT NULL,
  `received_by` char(26) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `finance_payroll_disbursements_property_id_run_id_unique` (`property_id`,`run_id`),
  KEY `finance_payroll_disbursements_property_id_status_index` (`property_id`,`status`),
  CONSTRAINT `finance_payroll_disbursements_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_finance_payroll_disbursements` CHECK (((`status` in (_utf8mb4'awaiting',_utf8mb4'verified',_utf8mb4'paid',_utf8mb4'withdrawn')) and ((`status` <> _utf8mb4'paid') or ((`paid_at` is not null) and (`method` is not null) and (`reference` is not null)))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `finance_payroll_disbursements_no_delete` BEFORE DELETE ON `finance_payroll_disbursements` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a payroll disbursement cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `fnb_bill_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `fnb_bill_lines` (
  `id` char(26) NOT NULL,
  `bill_id` char(26) NOT NULL,
  `line_no` smallint unsigned NOT NULL,
  `item_id` char(26) NOT NULL,
  `variant_id` char(26) DEFAULT NULL,
  `item_code` varchar(16) NOT NULL,
  `item_name` varchar(80) NOT NULL,
  `variant_name` varchar(40) DEFAULT NULL,
  `station` varchar(8) NOT NULL,
  `unit_price_minor` bigint NOT NULL,
  `price_rule_id` char(26) DEFAULT NULL,
  `list_price_minor` bigint unsigned DEFAULT NULL,
  `modifiers` json NOT NULL,
  `modifiers_minor` bigint NOT NULL DEFAULT '0',
  `gross_minor` bigint unsigned DEFAULT NULL,
  `quantity` smallint unsigned NOT NULL,
  `note` varchar(120) DEFAULT NULL,
  `line_total_minor` bigint NOT NULL,
  `discount_kind` varchar(8) DEFAULT NULL,
  `discount_value` int unsigned DEFAULT NULL,
  `discount_minor` bigint unsigned NOT NULL DEFAULT '0',
  `discount_reason` varchar(200) DEFAULT NULL,
  `discount_by` char(26) DEFAULT NULL,
  `discount_approval_id` char(26) DEFAULT NULL,
  `discounted_at` timestamp(6) NULL DEFAULT NULL,
  `status` varchar(8) NOT NULL DEFAULT 'pending',
  `prep_status` varchar(10) NOT NULL DEFAULT 'new',
  `batch_id` char(26) DEFAULT NULL,
  `sent_at` timestamp(6) NULL DEFAULT NULL,
  `voided_by` char(26) DEFAULT NULL,
  `voided_at` timestamp(6) NULL DEFAULT NULL,
  `void_reason` varchar(200) DEFAULT NULL,
  `void_approval_id` char(26) DEFAULT NULL,
  `created_by` char(26) NOT NULL,
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fnb_bill_lines_bill_id_line_no_unique` (`bill_id`,`line_no`),
  KEY `fnb_bill_lines_item_id_foreign` (`item_id`),
  CONSTRAINT `fnb_bill_lines_bill_id_foreign` FOREIGN KEY (`bill_id`) REFERENCES `fnb_bills` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fnb_bill_lines_item_id_foreign` FOREIGN KEY (`item_id`) REFERENCES `fnb_menu_items` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_fnb_line` CHECK (((`status` in (_utf8mb4'pending',_utf8mb4'sent',_utf8mb4'voided',_utf8mb4'removed')) and (`quantity` between 1 and 99) and (`line_total_minor` >= 0))),
  CONSTRAINT `chk_fnb_line_discount` CHECK (((`discount_kind` is null) or (`discount_kind` in (_utf8mb4'percent',_utf8mb4'amount',_utf8mb4'comp'))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `fnb_bills`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `fnb_bills` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `outlet_id` char(26) NOT NULL,
  `number` varchar(20) NOT NULL,
  `table_id` char(26) DEFAULT NULL,
  `room_id` char(26) DEFAULT NULL,
  `stay_id` char(26) DEFAULT NULL,
  `reservation_id` char(26) DEFAULT NULL,
  `split_from_id` char(26) DEFAULT NULL,
  `merged_into_id` char(26) DEFAULT NULL,
  `covers` smallint unsigned NOT NULL DEFAULT '1',
  `note` varchar(200) DEFAULT NULL,
  `waive_service_charge` tinyint(1) NOT NULL DEFAULT '0',
  `waive_tax` tinyint(1) NOT NULL DEFAULT '0',
  `status` varchar(10) NOT NULL DEFAULT 'open',
  `source` varchar(5) NOT NULL DEFAULT 'staff',
  `business_date` date NOT NULL,
  `opened_by` char(26) NOT NULL,
  `opened_at` timestamp(6) NOT NULL,
  `closed_by` char(26) DEFAULT NULL,
  `closed_at` timestamp(6) NULL DEFAULT NULL,
  `cancel_reason` varchar(200) DEFAULT NULL,
  `cancel_approval_id` char(26) DEFAULT NULL,
  `subtotal_minor` bigint DEFAULT NULL,
  `base_minor` bigint DEFAULT NULL,
  `service_charge_minor` bigint DEFAULT NULL,
  `tax_minor` bigint DEFAULT NULL,
  `total_minor` bigint DEFAULT NULL,
  `scheme` json DEFAULT NULL,
  `reprint_count` smallint unsigned NOT NULL DEFAULT '0',
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  `open_table_id` char(26) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fnb_bills_property_id_number_unique` (`property_id`,`number`),
  UNIQUE KEY `fnb_bills_one_open_per_table` (`open_table_id`),
  KEY `fnb_bills_property_id_outlet_id_status_index` (`property_id`,`outlet_id`,`status`),
  KEY `fnb_bills_property_id_business_date_index` (`property_id`,`business_date`),
  KEY `fnb_bills_outlet_id_foreign` (`outlet_id`),
  KEY `fnb_bills_table_id_foreign` (`table_id`),
  KEY `fnb_bills_opened_by_foreign` (`opened_by`),
  CONSTRAINT `fnb_bills_opened_by_foreign` FOREIGN KEY (`opened_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fnb_bills_outlet_id_foreign` FOREIGN KEY (`outlet_id`) REFERENCES `fnb_outlets` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fnb_bills_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fnb_bills_table_id_foreign` FOREIGN KEY (`table_id`) REFERENCES `fnb_tables` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_fnb_bill_source` CHECK ((`source` in (_utf8mb4'staff',_utf8mb4'qr'))),
  CONSTRAINT `chk_fnb_bill_status` CHECK ((`status` in (_utf8mb4'open',_utf8mb4'settled',_utf8mb4'cancelled',_utf8mb4'refunded')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `fnb_bills_open_table_insert` BEFORE INSERT ON `fnb_bills` FOR EACH ROW BEGIN
    SET NEW.open_table_id = CASE WHEN NEW.status = 'open' THEN NEW.table_id ELSE NULL END;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `fnb_bills_open_table_update` BEFORE UPDATE ON `fnb_bills` FOR EACH ROW BEGIN
    SET NEW.open_table_id = CASE WHEN NEW.status = 'open' THEN NEW.table_id ELSE NULL END;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `fnb_cashier_shifts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `fnb_cashier_shifts` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `outlet_id` char(26) NOT NULL,
  `number` varchar(20) NOT NULL,
  `cashier_id` char(26) NOT NULL,
  `status` varchar(8) NOT NULL DEFAULT 'open',
  `currency` varchar(3) NOT NULL,
  `opening_float_minor` bigint NOT NULL,
  `business_date` date NOT NULL,
  `opened_at` timestamp(6) NOT NULL,
  `closed_at` timestamp(6) NULL DEFAULT NULL,
  `closed_business_date` date DEFAULT NULL,
  `expected_cash_minor` bigint DEFAULT NULL,
  `counted_cash_minor` bigint DEFAULT NULL,
  `variance_minor` bigint DEFAULT NULL,
  `variance_reason` varchar(200) DEFAULT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  `open_cashier_id` char(26) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fnb_cashier_shifts_property_id_number_unique` (`property_id`,`number`),
  UNIQUE KEY `fnb_cashier_shifts_one_open` (`open_cashier_id`),
  KEY `fnb_cashier_shifts_property_id_outlet_id_status_index` (`property_id`,`outlet_id`,`status`),
  KEY `fnb_cashier_shifts_outlet_id_foreign` (`outlet_id`),
  KEY `fnb_cashier_shifts_cashier_id_foreign` (`cashier_id`),
  CONSTRAINT `fnb_cashier_shifts_cashier_id_foreign` FOREIGN KEY (`cashier_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fnb_cashier_shifts_outlet_id_foreign` FOREIGN KEY (`outlet_id`) REFERENCES `fnb_outlets` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fnb_cashier_shifts_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_fnb_shift` CHECK (((`status` in (_utf8mb4'open',_utf8mb4'closed')) and (`opening_float_minor` >= 0)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `fnb_cashier_shifts_open_insert` BEFORE INSERT ON `fnb_cashier_shifts` FOR EACH ROW BEGIN
    SET NEW.open_cashier_id = CASE WHEN NEW.status = 'open' THEN NEW.cashier_id ELSE NULL END;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `fnb_cashier_shifts_open_update` BEFORE UPDATE ON `fnb_cashier_shifts` FOR EACH ROW BEGIN
    SET NEW.open_cashier_id = CASE WHEN NEW.status = 'open' THEN NEW.cashier_id ELSE NULL END;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `fnb_item_modifier_groups`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `fnb_item_modifier_groups` (
  `item_id` char(26) NOT NULL,
  `group_id` char(26) NOT NULL,
  PRIMARY KEY (`item_id`,`group_id`),
  KEY `fnb_item_modifier_groups_group_id_foreign` (`group_id`),
  CONSTRAINT `fnb_item_modifier_groups_group_id_foreign` FOREIGN KEY (`group_id`) REFERENCES `fnb_modifier_groups` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fnb_item_modifier_groups_item_id_foreign` FOREIGN KEY (`item_id`) REFERENCES `fnb_menu_items` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `fnb_item_variants`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `fnb_item_variants` (
  `id` char(26) NOT NULL,
  `item_id` char(26) NOT NULL,
  `name` varchar(40) NOT NULL,
  `price_minor` bigint NOT NULL,
  `sort_order` smallint unsigned NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fnb_item_variants_item_id_name_unique` (`item_id`,`name`),
  CONSTRAINT `fnb_item_variants_item_id_foreign` FOREIGN KEY (`item_id`) REFERENCES `fnb_menu_items` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `fnb_menu_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `fnb_menu_categories` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `outlet_id` char(26) NOT NULL,
  `code` varchar(12) NOT NULL,
  `name` varchar(80) NOT NULL,
  `station` varchar(8) NOT NULL,
  `sort_order` smallint unsigned NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fnb_menu_categories_outlet_id_code_unique` (`outlet_id`,`code`),
  KEY `fnb_menu_categories_property_id_foreign` (`property_id`),
  CONSTRAINT `fnb_menu_categories_outlet_id_foreign` FOREIGN KEY (`outlet_id`) REFERENCES `fnb_outlets` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fnb_menu_categories_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `fnb_menu_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `fnb_menu_items` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `category_id` char(26) NOT NULL,
  `code` varchar(16) NOT NULL,
  `name` varchar(80) NOT NULL,
  `description` varchar(200) DEFAULT NULL,
  `photo_file_id` char(26) DEFAULT NULL,
  `price_minor` bigint NOT NULL,
  `station` varchar(8) DEFAULT NULL,
  `is_available` tinyint(1) NOT NULL DEFAULT '1',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `sort_order` smallint unsigned NOT NULL DEFAULT '0',
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fnb_menu_items_property_id_code_unique` (`property_id`,`code`),
  KEY `fnb_menu_items_category_id_foreign` (`category_id`),
  CONSTRAINT `fnb_menu_items_category_id_foreign` FOREIGN KEY (`category_id`) REFERENCES `fnb_menu_categories` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fnb_menu_items_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `fnb_minibar_check_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `fnb_minibar_check_lines` (
  `check_id` char(26) NOT NULL,
  `item_id` char(26) NOT NULL,
  `item_code` varchar(12) NOT NULL,
  `item_name` varchar(80) NOT NULL,
  `unit_price_minor` bigint unsigned NOT NULL,
  `consumed` smallint unsigned NOT NULL,
  `refilled` smallint unsigned NOT NULL,
  `stock_after` smallint unsigned NOT NULL,
  PRIMARY KEY (`check_id`,`item_id`),
  CONSTRAINT `fnb_minibar_check_lines_check_id_foreign` FOREIGN KEY (`check_id`) REFERENCES `fnb_minibar_checks` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `fnb_minibar_check_lines_no_update` BEFORE UPDATE ON `fnb_minibar_check_lines` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a mini bar check cannot be changed' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `fnb_minibar_check_lines_no_delete` BEFORE DELETE ON `fnb_minibar_check_lines` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a mini bar check cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `fnb_minibar_checks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `fnb_minibar_checks` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `number` varchar(20) NOT NULL,
  `room_id` char(26) NOT NULL,
  `room_number` varchar(20) NOT NULL,
  `stay_id` char(26) DEFAULT NULL,
  `reservation_id` char(26) DEFAULT NULL,
  `guest_name` varchar(150) DEFAULT NULL,
  `checked_by` char(26) NOT NULL,
  `checked_at` timestamp(6) NOT NULL,
  `business_date` date NOT NULL,
  `consumed_minor` bigint unsigned NOT NULL,
  `folio_posting_id` char(26) DEFAULT NULL,
  `charged_total_minor` bigint unsigned DEFAULT NULL,
  `waive_service_charge` tinyint(1) NOT NULL DEFAULT '0',
  `waive_tax` tinyint(1) NOT NULL DEFAULT '0',
  `waive_reason` varchar(200) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fnb_minibar_checks_property_id_number_unique` (`property_id`,`number`),
  KEY `fnb_minibar_checks_property_id_room_id_checked_at_index` (`property_id`,`room_id`,`checked_at`),
  KEY `fnb_minibar_checks_property_id_checked_by_checked_at_index` (`property_id`,`checked_by`,`checked_at`),
  CONSTRAINT `fnb_minibar_checks_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_minibar_waiver` CHECK ((((`waive_service_charge` = 0) and (`waive_tax` = 0)) or (`waive_reason` is not null)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `fnb_minibar_checks_no_update` BEFORE UPDATE ON `fnb_minibar_checks` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a mini bar check cannot be changed' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `fnb_minibar_checks_no_delete` BEFORE DELETE ON `fnb_minibar_checks` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a mini bar check cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `fnb_minibar_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `fnb_minibar_items` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `code` varchar(12) NOT NULL,
  `name` varchar(80) NOT NULL,
  `price_minor` bigint unsigned NOT NULL,
  `par_qty` smallint unsigned NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fnb_minibar_items_property_id_code_unique` (`property_id`,`code`),
  CONSTRAINT `fnb_minibar_items_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_fnb_minibar_items` CHECK (((`par_qty` between 1 and 99) and (`price_minor` > 0)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `fnb_minibar_stock`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `fnb_minibar_stock` (
  `property_id` char(26) NOT NULL,
  `room_id` char(26) NOT NULL,
  `item_id` char(26) NOT NULL,
  `qty_now` smallint unsigned NOT NULL,
  `updated_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`room_id`,`item_id`),
  KEY `fnb_minibar_stock_property_id_foreign` (`property_id`),
  KEY `fnb_minibar_stock_item_id_foreign` (`item_id`),
  CONSTRAINT `fnb_minibar_stock_item_id_foreign` FOREIGN KEY (`item_id`) REFERENCES `fnb_minibar_items` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fnb_minibar_stock_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `fnb_modifier_groups`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `fnb_modifier_groups` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `code` varchar(12) NOT NULL,
  `name` varchar(60) NOT NULL,
  `min_select` tinyint unsigned NOT NULL DEFAULT '0',
  `max_select` tinyint unsigned NOT NULL DEFAULT '1',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fnb_modifier_groups_property_id_code_unique` (`property_id`,`code`),
  CONSTRAINT `fnb_modifier_groups_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `fnb_modifiers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `fnb_modifiers` (
  `id` char(26) NOT NULL,
  `group_id` char(26) NOT NULL,
  `name` varchar(40) NOT NULL,
  `price_delta_minor` bigint NOT NULL DEFAULT '0',
  `sort_order` smallint unsigned NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fnb_modifiers_group_id_name_unique` (`group_id`,`name`),
  CONSTRAINT `fnb_modifiers_group_id_foreign` FOREIGN KEY (`group_id`) REFERENCES `fnb_modifier_groups` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `fnb_order_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `fnb_order_batches` (
  `id` char(26) NOT NULL,
  `bill_id` char(26) NOT NULL,
  `number` smallint unsigned NOT NULL,
  `line_count` smallint unsigned NOT NULL,
  `sent_by` char(26) NOT NULL,
  `sent_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fnb_order_batches_bill_id_number_unique` (`bill_id`,`number`),
  CONSTRAINT `fnb_order_batches_bill_id_foreign` FOREIGN KEY (`bill_id`) REFERENCES `fnb_bills` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `fnb_outlets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `fnb_outlets` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `code` varchar(12) NOT NULL,
  `name` varchar(80) NOT NULL,
  `kind` varchar(14) NOT NULL,
  `charge_scope` varchar(16) NOT NULL DEFAULT 'fnb',
  `prices_include_charges` tinyint(1) NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fnb_outlets_property_id_code_unique` (`property_id`,`code`),
  CONSTRAINT `fnb_outlets_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `fnb_payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `fnb_payments` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `bill_id` char(26) NOT NULL,
  `shift_id` char(26) NOT NULL,
  `method` varchar(12) NOT NULL,
  `status` varchar(10) NOT NULL,
  `amount_minor` bigint NOT NULL,
  `tendered_minor` bigint DEFAULT NULL,
  `change_minor` bigint NOT NULL DEFAULT '0',
  `reference` varchar(60) DEFAULT NULL,
  `room_id` char(26) DEFAULT NULL,
  `guest_name` varchar(80) DEFAULT NULL,
  `folio_posting_id` char(26) DEFAULT NULL,
  `status_reason` varchar(200) DEFAULT NULL,
  `business_date` date NOT NULL,
  `created_by` char(26) NOT NULL,
  `status_changed_at` timestamp(6) NULL DEFAULT NULL,
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fnb_payments_property_id_foreign` (`property_id`),
  KEY `fnb_payments_bill_id_index` (`bill_id`),
  KEY `fnb_payments_shift_id_method_status_index` (`shift_id`,`method`,`status`),
  CONSTRAINT `fnb_payments_bill_id_foreign` FOREIGN KEY (`bill_id`) REFERENCES `fnb_bills` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fnb_payments_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fnb_payments_shift_id_foreign` FOREIGN KEY (`shift_id`) REFERENCES `fnb_cashier_shifts` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_fnb_payment` CHECK (((`method` in (_utf8mb4'cash',_utf8mb4'card',_utf8mb4'qris',_utf8mb4'room',_utf8mb4'gift_card')) and (`status` in (_utf8mb4'initiated',_utf8mb4'pending',_utf8mb4'paid',_utf8mb4'failed',_utf8mb4'expired',_utf8mb4'unknown',_utf8mb4'refunded')) and (`amount_minor` > 0)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `fnb_price_rules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `fnb_price_rules` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `outlet_id` char(26) NOT NULL,
  `item_id` char(26) NOT NULL,
  `variant_id` char(26) DEFAULT NULL,
  `channel` varchar(12) NOT NULL DEFAULT 'all',
  `kind` varchar(5) NOT NULL DEFAULT 'price',
  `name` varchar(80) NOT NULL,
  `price_minor` bigint unsigned NOT NULL,
  `valid_from` date NOT NULL,
  `valid_to` date DEFAULT NULL,
  `days` tinyint unsigned NOT NULL DEFAULT '127',
  `from_time` time DEFAULT NULL,
  `to_time` time DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_by` char(26) NOT NULL,
  `retired_by` char(26) DEFAULT NULL,
  `retired_at` timestamp(6) NULL DEFAULT NULL,
  `retire_reason` varchar(200) DEFAULT NULL,
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fnb_price_rules_property_id_outlet_id_item_id_is_active_index` (`property_id`,`outlet_id`,`item_id`,`is_active`),
  KEY `fnb_price_rules_outlet_id_foreign` (`outlet_id`),
  KEY `fnb_price_rules_item_id_foreign` (`item_id`),
  CONSTRAINT `fnb_price_rules_item_id_foreign` FOREIGN KEY (`item_id`) REFERENCES `fnb_menu_items` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fnb_price_rules_outlet_id_foreign` FOREIGN KEY (`outlet_id`) REFERENCES `fnb_outlets` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fnb_price_rules_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_fnb_price_rule` CHECK (((`channel` in (_utf8mb4'all',_utf8mb4'dine_in',_utf8mb4'room_service',_utf8mb4'takeaway')) and (`kind` in (_utf8mb4'price',_utf8mb4'promo')) and (`days` between 1 and 127) and ((`valid_to` is null) or (`valid_to` >= `valid_from`)) and (((`from_time` is null) and (`to_time` is null)) or ((`from_time` is not null) and (`to_time` is not null) and (`from_time` <> `to_time`)))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `fnb_price_rules_retire_only` BEFORE UPDATE ON `fnb_price_rules` FOR EACH ROW BEGIN
                IF NOT (OLD.outlet_id <=> NEW.outlet_id AND OLD.item_id <=> NEW.item_id AND OLD.variant_id <=> NEW.variant_id AND OLD.channel <=> NEW.channel AND OLD.kind <=> NEW.kind AND OLD.name <=> NEW.name
                    AND OLD.price_minor <=> NEW.price_minor AND OLD.valid_from <=> NEW.valid_from AND OLD.valid_to <=> NEW.valid_to AND OLD.days <=> NEW.days AND OLD.from_time <=> NEW.from_time AND OLD.to_time <=> NEW.to_time AND OLD.created_by <=> NEW.created_by)
                    OR (OLD.is_active = 0 AND NEW.is_active = 1) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a price rule can only be retired';
                END IF;
            END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `fnb_price_rules_no_delete` BEFORE DELETE ON `fnb_price_rules` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a price rule cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `fnb_refund_payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `fnb_refund_payments` (
  `id` char(26) NOT NULL,
  `refund_id` char(26) NOT NULL,
  `payment_id` char(26) NOT NULL,
  `method` varchar(12) NOT NULL,
  `amount_minor` bigint NOT NULL,
  `reference` varchar(60) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fnb_refund_payments_payment_id_unique` (`payment_id`),
  KEY `fnb_refund_payments_refund_id_foreign` (`refund_id`),
  CONSTRAINT `fnb_refund_payments_payment_id_foreign` FOREIGN KEY (`payment_id`) REFERENCES `fnb_payments` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fnb_refund_payments_refund_id_foreign` FOREIGN KEY (`refund_id`) REFERENCES `fnb_refunds` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_fnb_refund_payment` CHECK (((`method` in (_utf8mb4'cash',_utf8mb4'card',_utf8mb4'qris',_utf8mb4'gift_card')) and (`amount_minor` > 0)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `fnb_refund_payments_no_update` BEFORE UPDATE ON `fnb_refund_payments` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a refund cannot be changed' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `fnb_refund_payments_no_delete` BEFORE DELETE ON `fnb_refund_payments` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a refund cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `fnb_refunds`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `fnb_refunds` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `bill_id` char(26) NOT NULL,
  `number` varchar(20) NOT NULL,
  `shift_id` char(26) NOT NULL,
  `business_date` date NOT NULL,
  `base_minor` bigint NOT NULL,
  `service_charge_minor` bigint NOT NULL,
  `tax_minor` bigint NOT NULL,
  `total_minor` bigint NOT NULL,
  `reason` varchar(200) NOT NULL,
  `approval_id` char(26) DEFAULT NULL,
  `refunded_by` char(26) NOT NULL,
  `created_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fnb_refunds_property_id_bill_id_unique` (`property_id`,`bill_id`),
  UNIQUE KEY `fnb_refunds_property_id_number_unique` (`property_id`,`number`),
  KEY `fnb_refunds_bill_id_foreign` (`bill_id`),
  KEY `fnb_refunds_shift_id_foreign` (`shift_id`),
  CONSTRAINT `fnb_refunds_bill_id_foreign` FOREIGN KEY (`bill_id`) REFERENCES `fnb_bills` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fnb_refunds_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fnb_refunds_shift_id_foreign` FOREIGN KEY (`shift_id`) REFERENCES `fnb_cashier_shifts` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `fnb_refunds_no_update` BEFORE UPDATE ON `fnb_refunds` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a refund cannot be changed' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `fnb_refunds_no_delete` BEFORE DELETE ON `fnb_refunds` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a refund cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `fnb_room_service_orders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `fnb_room_service_orders` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `bill_id` char(26) NOT NULL,
  `room_id` char(26) NOT NULL,
  `room_number` varchar(20) NOT NULL,
  `guest_name` varchar(150) DEFAULT NULL,
  `promised_at` timestamp(6) NOT NULL,
  `status` varchar(10) NOT NULL,
  `delivered_at` timestamp(6) NULL DEFAULT NULL,
  `status_changed_by` char(26) NOT NULL,
  `status_changed_at` timestamp(6) NOT NULL,
  `created_by` char(26) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fnb_room_service_orders_bill_id_unique` (`bill_id`),
  KEY `fnb_room_service_orders_property_id_status_promised_at_index` (`property_id`,`status`,`promised_at`),
  CONSTRAINT `fnb_room_service_orders_bill_id_foreign` FOREIGN KEY (`bill_id`) REFERENCES `fnb_bills` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fnb_room_service_orders_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_fnb_room_service_orders` CHECK (((`status` in (_utf8mb4'ordered',_utf8mb4'on_the_way',_utf8mb4'delivered')) and ((`status` <> _utf8mb4'delivered') or (`delivered_at` is not null))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `fnb_room_service_orders_no_delete` BEFORE DELETE ON `fnb_room_service_orders` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a room service order cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `fnb_tables`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `fnb_tables` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `outlet_id` char(26) NOT NULL,
  `code` varchar(8) NOT NULL,
  `area` varchar(40) DEFAULT NULL,
  `seats` smallint unsigned NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fnb_tables_outlet_id_code_unique` (`outlet_id`,`code`),
  KEY `fnb_tables_property_id_foreign` (`property_id`),
  CONSTRAINT `fnb_tables_outlet_id_foreign` FOREIGN KEY (`outlet_id`) REFERENCES `fnb_outlets` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fnb_tables_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `fo_reminders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `fo_reminders` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `due_on` date NOT NULL,
  `due_time` char(5) DEFAULT NULL,
  `text` varchar(300) NOT NULL,
  `reservation_id` char(26) DEFAULT NULL,
  `room_id` char(26) DEFAULT NULL,
  `status` varchar(8) NOT NULL DEFAULT 'open',
  `auto_key` varchar(80) DEFAULT NULL,
  `created_by` char(26) DEFAULT NULL,
  `done_by` char(26) DEFAULT NULL,
  `done_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fo_reminders_auto_key` (`property_id`,`auto_key`),
  KEY `fo_reminders_reservation_id_foreign` (`reservation_id`),
  KEY `fo_reminders_room_id_foreign` (`room_id`),
  KEY `fo_reminders_created_by_foreign` (`created_by`),
  KEY `fo_reminders_done_by_foreign` (`done_by`),
  KEY `fo_reminders_property_id_status_due_on_index` (`property_id`,`status`,`due_on`),
  CONSTRAINT `fo_reminders_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fo_reminders_done_by_foreign` FOREIGN KEY (`done_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fo_reminders_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fo_reminders_reservation_id_foreign` FOREIGN KEY (`reservation_id`) REFERENCES `reservations` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fo_reminders_room_id_foreign` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_fo_reminders_status` CHECK ((`status` in (_utf8mb4'open',_utf8mb4'done')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `folio_postings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `folio_postings` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `folio_id` char(26) NOT NULL,
  `seq` int unsigned NOT NULL,
  `entry_type` varchar(10) NOT NULL,
  `code` varchar(20) NOT NULL,
  `description` varchar(200) NOT NULL,
  `currency_code` char(3) NOT NULL,
  `base_minor` bigint NOT NULL,
  `service_charge_minor` bigint NOT NULL,
  `tax_minor` bigint NOT NULL,
  `total_minor` bigint NOT NULL,
  `business_date` date NOT NULL,
  `posted_at` timestamp(6) NOT NULL,
  `posted_by` char(26) DEFAULT NULL,
  `source` varchar(40) NOT NULL,
  `source_ref` varchar(80) DEFAULT NULL,
  `reverses_id` char(26) DEFAULT NULL,
  `reason` varchar(500) DEFAULT NULL,
  `payment_method` varchar(20) DEFAULT NULL,
  `payment_reference` varchar(80) DEFAULT NULL,
  `payment_purpose` varchar(12) DEFAULT NULL,
  `approval_id` char(26) DEFAULT NULL,
  `scheme_snapshot` json DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `folio_postings_folio_id_seq_unique` (`folio_id`,`seq`),
  UNIQUE KEY `folio_postings_source_unique` (`property_id`,`source`,`source_ref`),
  UNIQUE KEY `folio_postings_reverses_id_unique` (`reverses_id`),
  KEY `folio_postings_posted_by_foreign` (`posted_by`),
  KEY `folio_postings_day_index` (`property_id`,`business_date`,`entry_type`),
  KEY `folio_postings_folio_id_business_date_index` (`folio_id`,`business_date`),
  CONSTRAINT `folio_postings_folio_id_foreign` FOREIGN KEY (`folio_id`) REFERENCES `folios` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `folio_postings_posted_by_foreign` FOREIGN KEY (`posted_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `folio_postings_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_folio_postings_charge` CHECK (((`entry_type` <> _utf8mb4'charge') or ((`total_minor` = ((`base_minor` + `service_charge_minor`) + `tax_minor`)) and (`total_minor` > 0) and (`base_minor` >= 0) and (`service_charge_minor` >= 0) and (`tax_minor` >= 0)))),
  CONSTRAINT `chk_folio_postings_payment` CHECK (((`entry_type` <> _utf8mb4'payment') or ((`total_minor` < 0) and (`base_minor` = 0) and (`service_charge_minor` = 0) and (`tax_minor` = 0) and (`payment_method` is not null) and (`payment_purpose` in (_utf8mb4'deposit',_utf8mb4'settlement'))))),
  CONSTRAINT `chk_folio_postings_refund` CHECK (((`entry_type` <> _utf8mb4'refund') or ((`total_minor` > 0) and (`base_minor` = 0) and (`service_charge_minor` = 0) and (`tax_minor` = 0) and (`payment_method` is not null) and (`reason` is not null)))),
  CONSTRAINT `chk_folio_postings_reversal` CHECK (((`entry_type` <> _utf8mb4'reversal') or ((`reverses_id` is not null) and (`reason` is not null) and (`total_minor` <> 0) and ((`total_minor` = ((`base_minor` + `service_charge_minor`) + `tax_minor`)) or ((`base_minor` = 0) and (`service_charge_minor` = 0) and (`tax_minor` = 0)))))),
  CONSTRAINT `chk_folio_postings_reverses` CHECK (((`reverses_id` is null) or (`entry_type` = _utf8mb4'reversal'))),
  CONSTRAINT `chk_folio_postings_type` CHECK ((`entry_type` in (_utf8mb4'charge',_utf8mb4'payment',_utf8mb4'refund',_utf8mb4'reversal')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `folio_postings_day_locked` BEFORE INSERT ON `folio_postings` FOR EACH ROW BEGIN
    IF EXISTS (SELECT 1 FROM night_audits WHERE property_id = NEW.property_id AND business_date >= NEW.business_date) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'the business date is closed by night audit';
    END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `folio_postings_no_update` BEFORE UPDATE ON `folio_postings` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'folio_postings cannot be changed; post a reversal instead' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `folio_postings_no_delete` BEFORE DELETE ON `folio_postings` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'folio_postings cannot be deleted; post a reversal instead' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `folios`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `folios` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `number` varchar(30) NOT NULL,
  `reservation_id` char(26) NOT NULL,
  `window_no` tinyint unsigned NOT NULL,
  `label` varchar(60) NOT NULL,
  `origin_folio_id` char(26) DEFAULT NULL,
  `currency_code` char(3) NOT NULL,
  `status` varchar(10) NOT NULL,
  `balance_minor` bigint NOT NULL DEFAULT '0',
  `last_seq` int unsigned NOT NULL DEFAULT '0',
  `closed_at` timestamp(6) NULL DEFAULT NULL,
  `closed_by` char(26) DEFAULT NULL,
  `created_by` char(26) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `folios_property_id_number_unique` (`property_id`,`number`),
  UNIQUE KEY `folios_reservation_id_window_no_unique` (`reservation_id`,`window_no`),
  KEY `folios_closed_by_foreign` (`closed_by`),
  KEY `folios_created_by_foreign` (`created_by`),
  KEY `folios_origin_index` (`origin_folio_id`),
  CONSTRAINT `folios_closed_by_foreign` FOREIGN KEY (`closed_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `folios_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `folios_origin_foreign` FOREIGN KEY (`origin_folio_id`) REFERENCES `folios` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `folios_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `folios_reservation_id_foreign` FOREIGN KEY (`reservation_id`) REFERENCES `reservations` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_folios_closed` CHECK ((((`status` = _utf8mb4'open') and (`closed_at` is null) and (`closed_by` is null)) or ((`status` = _utf8mb4'closed') and (`closed_at` is not null) and (`closed_by` is not null)))),
  CONSTRAINT `chk_folios_status` CHECK ((`status` in (_utf8mb4'open',_utf8mb4'closed'))),
  CONSTRAINT `chk_folios_window` CHECK ((`window_no` between 1 and 20))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `folios_identity_immutable` BEFORE UPDATE ON `folios` FOR EACH ROW BEGIN
    IF NOT (NEW.id <=> OLD.id AND NEW.property_id <=> OLD.property_id AND NEW.number <=> OLD.number AND NEW.reservation_id <=> OLD.reservation_id
        AND NEW.window_no <=> OLD.window_no AND NEW.currency_code <=> OLD.currency_code AND NEW.created_by <=> OLD.created_by AND NEW.origin_folio_id <=> OLD.origin_folio_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'the identity of a folio cannot be changed';
    END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `folios_no_delete` BEFORE DELETE ON `folios` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'folios cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `foreign_payment_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `foreign_payment_settings` (
  `property_id` char(26) NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT '0',
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `updated_by` char(26) DEFAULT NULL,
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`property_id`),
  KEY `foreign_payment_settings_updated_by_foreign` (`updated_by`),
  CONSTRAINT `foreign_payment_settings_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `foreign_payment_settings_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `foreign_payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `foreign_payments` (
  `posting_id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `currency_code` char(3) NOT NULL,
  `foreign_minor` bigint NOT NULL,
  `rate_e4` bigint NOT NULL,
  `rate_version` int unsigned NOT NULL,
  `booked_minor` bigint NOT NULL,
  `created_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`posting_id`),
  KEY `foreign_payments_recent_index` (`property_id`,`created_at`),
  CONSTRAINT `foreign_payments_posting_id_foreign` FOREIGN KEY (`posting_id`) REFERENCES `folio_postings` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `foreign_payments_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_foreign_payment` CHECK (((`foreign_minor` > 0) and (`booked_minor` > 0) and (`rate_e4` > 0)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `foreign_payments_no_update` BEFORE UPDATE ON `foreign_payments` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a foreign payment record cannot be changed' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `foreign_payments_no_delete` BEFORE DELETE ON `foreign_payments` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a foreign payment record cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `ge_checkin_attempts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ge_checkin_attempts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `property_id` char(26) NOT NULL,
  `subject_hash` char(64) NOT NULL,
  `created_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ge_checkin_attempts_property_id_subject_hash_created_at_index` (`property_id`,`subject_hash`,`created_at`),
  CONSTRAINT `ge_checkin_attempts_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ge_checkin_links`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ge_checkin_links` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `kind` varchar(12) NOT NULL,
  `reservation_id` char(26) DEFAULT NULL,
  `token_hash` char(64) NOT NULL,
  `token_cipher` text NOT NULL,
  `expires_at` timestamp(6) NOT NULL,
  `revoked_at` timestamp(6) NULL DEFAULT NULL,
  `created_by` char(26) DEFAULT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp(6) NOT NULL,
  `updated_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ge_checkin_links_token_hash_unique` (`token_hash`),
  KEY `ge_checkin_links_property_id_reservation_id_index` (`property_id`,`reservation_id`),
  CONSTRAINT `ge_checkin_links_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_ge_link` CHECK ((((`kind` = _utf8mb4'lobby') and (`reservation_id` is null)) or ((`kind` = _utf8mb4'reservation') and (`reservation_id` is not null))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ge_checkins`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ge_checkins` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `link_id` char(26) NOT NULL,
  `reservation_id` char(26) NOT NULL,
  `status` varchar(10) NOT NULL,
  `data` mediumtext,
  `adults` tinyint unsigned NOT NULL,
  `children` tinyint unsigned NOT NULL,
  `id_photo_file_id` char(26) DEFAULT NULL,
  `signature_file_id` char(26) DEFAULT NULL,
  `notice_version` int unsigned NOT NULL,
  `notice_digest` char(64) NOT NULL,
  `consent_locale` varchar(2) NOT NULL,
  `consented_at` timestamp(6) NOT NULL,
  `deposit_method` varchar(10) DEFAULT NULL,
  `deposit_amount_minor` bigint unsigned DEFAULT NULL,
  `deposit_reference` varchar(60) DEFAULT NULL,
  `submitted_at` timestamp(6) NOT NULL,
  `decided_by` char(26) DEFAULT NULL,
  `decided_at` timestamp(6) NULL DEFAULT NULL,
  `reject_reason` varchar(300) DEFAULT NULL,
  `stay_id` char(26) DEFAULT NULL,
  `room_id` char(26) DEFAULT NULL,
  `room_number` varchar(20) DEFAULT NULL,
  `key_note` varchar(300) DEFAULT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `updated_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ge_checkins_link_id_unique` (`link_id`),
  KEY `ge_checkins_property_id_status_submitted_at_index` (`property_id`,`status`,`submitted_at`),
  KEY `ge_checkins_property_id_reservation_id_index` (`property_id`,`reservation_id`),
  CONSTRAINT `ge_checkins_link_id_foreign` FOREIGN KEY (`link_id`) REFERENCES `ge_checkin_links` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `ge_checkins_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_ge_checkin` CHECK (((`status` in (_utf8mb4'submitted',_utf8mb4'verified',_utf8mb4'rejected')) and ((`status` = _utf8mb4'verified') = (`stay_id` is not null)) and ((`status` = _utf8mb4'submitted') or (`decided_by` is not null)) and ((`status` <> _utf8mb4'rejected') or (`reject_reason` is not null)) and (`consent_locale` in (_utf8mb4'id',_utf8mb4'en')) and ((`deposit_method` is null) or (`deposit_method` = _utf8mb4'qris'))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `ge_checkins_no_delete` BEFORE DELETE ON `ge_checkins` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a self check-in cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `ge_orders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ge_orders` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `session_id` char(26) NOT NULL,
  `qr_point_id` char(26) NOT NULL,
  `client_key` varchar(40) NOT NULL,
  `kind` varchar(5) NOT NULL,
  `outlet_id` char(26) NOT NULL,
  `bill_id` char(26) NOT NULL,
  `bill_number` varchar(20) NOT NULL,
  `line_ids` json NOT NULL,
  `subtotal_minor` bigint NOT NULL,
  `payment_preference` varchar(6) NOT NULL,
  `room_charge_state` varchar(10) NOT NULL DEFAULT 'none',
  `verified_by` char(26) DEFAULT NULL,
  `verified_at` timestamp(6) NULL DEFAULT NULL,
  `verify_note` varchar(200) DEFAULT NULL,
  `note` varchar(200) DEFAULT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ge_orders_session_id_client_key_unique` (`session_id`,`client_key`),
  KEY `ge_orders_property_id_created_at_index` (`property_id`,`created_at`),
  KEY `ge_orders_property_id_room_charge_state_index` (`property_id`,`room_charge_state`),
  CONSTRAINT `ge_orders_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `ge_orders_session_id_foreign` FOREIGN KEY (`session_id`) REFERENCES `ge_sessions` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_ge_order` CHECK (((`kind` in (_utf8mb4'room',_utf8mb4'table')) and (`payment_preference` in (_utf8mb4'qris',_utf8mb4'room',_utf8mb4'later')) and (`room_charge_state` in (_utf8mb4'none',_utf8mb4'pending',_utf8mb4'verified',_utf8mb4'rejected')) and ((`payment_preference` = _utf8mb4'room') or (`room_charge_state` = _utf8mb4'none'))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ge_privacy_notices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ge_privacy_notices` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `version` int unsigned NOT NULL,
  `body_id` text NOT NULL,
  `body_en` text NOT NULL,
  `reason` varchar(300) NOT NULL,
  `created_by` char(26) NOT NULL,
  `created_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ge_privacy_notices_property_id_version_unique` (`property_id`,`version`),
  CONSTRAINT `ge_privacy_notices_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `ge_privacy_notices_no_update` BEFORE UPDATE ON `ge_privacy_notices` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a privacy notice cannot be changed' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `ge_privacy_notices_no_delete` BEFORE DELETE ON `ge_privacy_notices` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a privacy notice cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `ge_qr_points`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ge_qr_points` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `kind` varchar(5) NOT NULL,
  `target_id` char(26) NOT NULL,
  `label` varchar(40) NOT NULL,
  `token_hash` char(64) NOT NULL,
  `token_cipher` text NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_by` char(26) NOT NULL,
  `rotated_at` timestamp(6) NULL DEFAULT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ge_qr_points_token_hash_unique` (`token_hash`),
  UNIQUE KEY `ge_qr_points_property_id_kind_target_id_unique` (`property_id`,`kind`,`target_id`),
  CONSTRAINT `ge_qr_points_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_ge_qr_kind` CHECK ((`kind` in (_utf8mb4'room',_utf8mb4'table')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ge_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ge_requests` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `session_id` char(26) NOT NULL,
  `stay_id` char(26) NOT NULL,
  `kind` varchar(9) NOT NULL,
  `category` varchar(20) DEFAULT NULL,
  `ref_id` char(26) NOT NULL,
  `ref_number` varchar(20) NOT NULL,
  `title` varchar(150) NOT NULL,
  `client_key` varchar(40) NOT NULL,
  `created_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ge_requests_session_id_client_key_unique` (`session_id`,`client_key`),
  KEY `ge_requests_property_id_stay_id_created_at_index` (`property_id`,`stay_id`,`created_at`),
  CONSTRAINT `ge_requests_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `ge_requests_session_id_foreign` FOREIGN KEY (`session_id`) REFERENCES `ge_sessions` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_ge_request` CHECK ((`kind` in (_utf8mb4'request',_utf8mb4'complaint')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `ge_requests_no_update` BEFORE UPDATE ON `ge_requests` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a guest request reference cannot be changed' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `ge_requests_no_delete` BEFORE DELETE ON `ge_requests` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a guest request reference cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `ge_sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ge_sessions` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `qr_point_id` char(26) NOT NULL,
  `token_hash` char(64) NOT NULL,
  `expires_at` timestamp(6) NOT NULL,
  `stay_id` char(26) DEFAULT NULL,
  `reservation_id` char(26) DEFAULT NULL,
  `room_id` char(26) DEFAULT NULL,
  `room_number` varchar(20) DEFAULT NULL,
  `guest_name` varchar(150) DEFAULT NULL,
  `verified_at` timestamp(6) NULL DEFAULT NULL,
  `failed_attempts` smallint unsigned NOT NULL DEFAULT '0',
  `locked_until` timestamp(6) NULL DEFAULT NULL,
  `created_at` timestamp(6) NOT NULL,
  `last_seen_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ge_sessions_token_hash_unique` (`token_hash`),
  KEY `ge_sessions_property_id_expires_at_index` (`property_id`,`expires_at`),
  KEY `ge_sessions_qr_point_id_foreign` (`qr_point_id`),
  CONSTRAINT `ge_sessions_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `ge_sessions_qr_point_id_foreign` FOREIGN KEY (`qr_point_id`) REFERENCES `ge_qr_points` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ge_surveys`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ge_surveys` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `stay_id` char(26) NOT NULL,
  `reservation_id` char(26) NOT NULL,
  `session_id` char(26) NOT NULL,
  `overall` tinyint unsigned NOT NULL,
  `room_rating` tinyint unsigned DEFAULT NULL,
  `service_rating` tinyint unsigned DEFAULT NULL,
  `food_rating` tinyint unsigned DEFAULT NULL,
  `value_rating` tinyint unsigned DEFAULT NULL,
  `comment` varchar(500) DEFAULT NULL,
  `complaint_id` char(26) DEFAULT NULL,
  `created_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ge_surveys_property_id_stay_id_unique` (`property_id`,`stay_id`),
  KEY `ge_surveys_property_id_created_at_index` (`property_id`,`created_at`),
  KEY `ge_surveys_session_id_foreign` (`session_id`),
  CONSTRAINT `ge_surveys_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `ge_surveys_session_id_foreign` FOREIGN KEY (`session_id`) REFERENCES `ge_sessions` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_ge_survey` CHECK (((`overall` between 1 and 5) and ((`room_rating` is null) or (`room_rating` between 1 and 5)) and ((`service_rating` is null) or (`service_rating` between 1 and 5)) and ((`food_rating` is null) or (`food_rating` between 1 and 5)) and ((`value_rating` is null) or (`value_rating` between 1 and 5))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `ge_surveys_no_update` BEFORE UPDATE ON `ge_surveys` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a survey cannot be changed' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `ge_surveys_no_delete` BEFORE DELETE ON `ge_surveys` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a survey cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `gift_card_entries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `gift_card_entries` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `card_id` char(26) NOT NULL,
  `kind` varchar(8) NOT NULL,
  `delta_minor` bigint NOT NULL,
  `balance_after_minor` bigint unsigned NOT NULL,
  `source_type` varchar(20) DEFAULT NULL,
  `source_ref` char(26) DEFAULT NULL,
  `disposition` varchar(14) DEFAULT NULL,
  `note` varchar(200) DEFAULT NULL,
  `business_date` date NOT NULL,
  `actor_id` char(26) NOT NULL,
  `created_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `gift_card_entries_once` (`property_id`,`kind`,`source_type`,`source_ref`),
  KEY `gift_card_entries_card_id_created_at_index` (`card_id`,`created_at`),
  KEY `gift_card_entries_property_id_business_date_index` (`property_id`,`business_date`),
  CONSTRAINT `gift_card_entries_card_id_foreign` FOREIGN KEY (`card_id`) REFERENCES `gift_cards` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `gift_card_entries_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_gift_card_entries` CHECK (((`kind` in (_utf8mb4'issue',_utf8mb4'redeem',_utf8mb4'restore',_utf8mb4'void')) and (`delta_minor` <> 0)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `gift_card_entries_no_update` BEFORE UPDATE ON `gift_card_entries` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a gift card entry cannot be changed' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `gift_card_entries_no_delete` BEFORE DELETE ON `gift_card_entries` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a gift card entry cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `gift_cards`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `gift_cards` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `code_hash` char(64) NOT NULL,
  `code_last4` char(4) NOT NULL,
  `kind` varchar(10) NOT NULL DEFAULT 'gift_card',
  `currency` char(3) NOT NULL,
  `face_minor` bigint unsigned NOT NULL,
  `balance_minor` bigint unsigned NOT NULL,
  `expires_on` date DEFAULT NULL,
  `status` varchar(8) NOT NULL DEFAULT 'active',
  `holder_note` varchar(120) DEFAULT NULL,
  `issue_method` varchar(20) NOT NULL,
  `issue_reference` varchar(60) DEFAULT NULL,
  `issue_shift_id` char(26) DEFAULT NULL,
  `issued_on` date NOT NULL,
  `issued_by` char(26) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `gift_cards_code` (`property_id`,`code_hash`),
  KEY `gift_cards_property_id_status_expires_on_index` (`property_id`,`status`,`expires_on`),
  CONSTRAINT `gift_cards_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_gift_cards` CHECK (((`kind` in (_utf8mb4'gift_card',_utf8mb4'voucher')) and (`status` in (_utf8mb4'active',_utf8mb4'void')) and (`face_minor` > 0) and (`balance_minor` <= `face_minor`) and (`issue_method` in (_utf8mb4'cash',_utf8mb4'card',_utf8mb4'qris',_utf8mb4'bank_transfer',_utf8mb4'complimentary'))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `goods_receipt_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `goods_receipt_lines` (
  `id` char(26) NOT NULL,
  `receipt_id` char(26) NOT NULL,
  `order_line_id` char(26) NOT NULL,
  `item_id` char(26) NOT NULL,
  `unit` varchar(8) NOT NULL,
  `accepted_qty_milli` bigint unsigned NOT NULL,
  `rejected_qty_milli` bigint unsigned NOT NULL DEFAULT '0',
  `goods_condition` varchar(16) NOT NULL,
  `rejection_reason` varchar(16) DEFAULT NULL,
  `note` varchar(200) DEFAULT NULL,
  `unit_price_minor` bigint unsigned NOT NULL,
  `value_minor` bigint unsigned NOT NULL,
  `movement_id` char(26) DEFAULT NULL,
  `expires_on` date DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `goods_receipt_lines_receipt_id_order_line_id_unique` (`receipt_id`,`order_line_id`),
  KEY `goods_receipt_lines_item_id_foreign` (`item_id`),
  KEY `goods_receipt_lines_order_line_id_index` (`order_line_id`),
  CONSTRAINT `goods_receipt_lines_item_id_foreign` FOREIGN KEY (`item_id`) REFERENCES `inventory_items` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `goods_receipt_lines_order_line_id_foreign` FOREIGN KEY (`order_line_id`) REFERENCES `purchase_order_lines` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `goods_receipt_lines_receipt_id_foreign` FOREIGN KEY (`receipt_id`) REFERENCES `goods_receipts` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_goods_receipt_line` CHECK ((((`accepted_qty_milli` > 0) or (`rejected_qty_milli` > 0)) and (`goods_condition` in (_utf8mb4'good',_utf8mb4'minor_damage')) and ((`rejected_qty_milli` = 0) or (`rejection_reason` in (_utf8mb4'damaged',_utf8mb4'expired',_utf8mb4'wrong_item',_utf8mb4'quality',_utf8mb4'short',_utf8mb4'other')))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `goods_receipt_lines_no_update` BEFORE UPDATE ON `goods_receipt_lines` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a goods receipt line cannot be changed' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `goods_receipt_lines_no_delete` BEFORE DELETE ON `goods_receipt_lines` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a goods receipt line cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `goods_receipt_photos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `goods_receipt_photos` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `receipt_line_id` char(26) NOT NULL,
  `file_id` char(26) NOT NULL,
  `created_by` char(26) NOT NULL,
  `created_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `goods_receipt_photos_property_id_foreign` (`property_id`),
  KEY `goods_receipt_photos_created_by_foreign` (`created_by`),
  KEY `goods_receipt_photos_receipt_line_id_index` (`receipt_line_id`),
  CONSTRAINT `goods_receipt_photos_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `goods_receipt_photos_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `goods_receipt_photos_receipt_line_id_foreign` FOREIGN KEY (`receipt_line_id`) REFERENCES `goods_receipt_lines` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `goods_receipt_photos_no_update` BEFORE UPDATE ON `goods_receipt_photos` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a goods receipt photo cannot be changed' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `goods_receipt_photos_no_delete` BEFORE DELETE ON `goods_receipt_photos` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a goods receipt photo cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `goods_receipts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `goods_receipts` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `number` varchar(20) NOT NULL,
  `order_id` char(26) NOT NULL,
  `supplier_id` char(26) NOT NULL,
  `location_id` char(26) NOT NULL,
  `order_revision` smallint unsigned NOT NULL,
  `delivery_note` varchar(40) DEFAULT NULL,
  `note` varchar(200) DEFAULT NULL,
  `received_on` date NOT NULL,
  `value_minor` bigint unsigned NOT NULL DEFAULT '0',
  `received_by` char(26) NOT NULL,
  `created_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `goods_receipts_property_id_number_unique` (`property_id`,`number`),
  KEY `goods_receipts_location_id_foreign` (`location_id`),
  KEY `goods_receipts_received_by_foreign` (`received_by`),
  KEY `goods_receipts_order_id_index` (`order_id`),
  KEY `goods_receipts_supplier_id_received_on_index` (`supplier_id`,`received_on`),
  CONSTRAINT `goods_receipts_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `inventory_locations` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `goods_receipts_order_id_foreign` FOREIGN KEY (`order_id`) REFERENCES `purchase_orders` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `goods_receipts_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `goods_receipts_received_by_foreign` FOREIGN KEY (`received_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `goods_receipts_supplier_id_foreign` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `goods_receipts_no_update` BEFORE UPDATE ON `goods_receipts` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a goods receipt cannot be changed' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `goods_receipts_no_delete` BEFORE DELETE ON `goods_receipts` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a goods receipt cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `group_master_folios`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `group_master_folios` (
  `group_id` char(26) NOT NULL,
  `folio_id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `created_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`group_id`),
  UNIQUE KEY `group_master_folios_folio_id_unique` (`folio_id`),
  KEY `group_master_folios_property_id_foreign` (`property_id`),
  CONSTRAINT `group_master_folios_folio_id_foreign` FOREIGN KEY (`folio_id`) REFERENCES `folios` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `group_master_folios_group_id_foreign` FOREIGN KEY (`group_id`) REFERENCES `reservation_groups` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `group_master_folios_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `group_master_folios_no_update` BEFORE UPDATE ON `group_master_folios` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a group master folio mark cannot be changed' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `group_master_folios_no_delete` BEFORE DELETE ON `group_master_folios` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a group master folio mark cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `guest_corrections`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `guest_corrections` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `guest_id` char(26) NOT NULL,
  `stay_id` char(26) NOT NULL,
  `field` varchar(20) NOT NULL,
  `old_enc` text,
  `new_enc` text,
  `reason` varchar(300) NOT NULL,
  `approval_id` char(26) DEFAULT NULL,
  `created_by` char(26) NOT NULL,
  `created_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `guest_corrections_property_id_foreign` (`property_id`),
  KEY `guest_corrections_guest_id_foreign` (`guest_id`),
  KEY `guest_corrections_created_by_foreign` (`created_by`),
  KEY `guest_corrections_stay_id_created_at_index` (`stay_id`,`created_at`),
  CONSTRAINT `guest_corrections_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `guest_corrections_guest_id_foreign` FOREIGN KEY (`guest_id`) REFERENCES `guests` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `guest_corrections_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `guest_corrections_stay_id_foreign` FOREIGN KEY (`stay_id`) REFERENCES `stays` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_corrections_field` CHECK ((`field` in (_utf8mb4'full_name',_utf8mb4'nationality',_utf8mb4'id_type',_utf8mb4'id_number',_utf8mb4'id_valid_until',_utf8mb4'visa_number',_utf8mb4'address'))),
  CONSTRAINT `chk_corrections_reason` CHECK ((char_length(trim(`reason`)) > 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `guest_corrections_no_update` BEFORE UPDATE ON `guest_corrections` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a guest correction cannot be changed' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `guest_corrections_no_delete` BEFORE DELETE ON `guest_corrections` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a guest correction cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `guest_feedback`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `guest_feedback` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `number` varchar(30) NOT NULL,
  `client_key` varchar(80) DEFAULT NULL,
  `kind` varchar(12) NOT NULL,
  `severity` varchar(8) DEFAULT NULL,
  `channel` varchar(10) NOT NULL,
  `reservation_id` char(26) DEFAULT NULL,
  `stay_id` char(26) DEFAULT NULL,
  `room_id` char(26) DEFAULT NULL,
  `guest_name` varchar(150) DEFAULT NULL,
  `summary` varchar(150) NOT NULL,
  `detail` varchar(1000) DEFAULT NULL,
  `status` varchar(12) NOT NULL,
  `owner_id` char(26) DEFAULT NULL,
  `follow_up_by` date DEFAULT NULL,
  `resolution` varchar(500) DEFAULT NULL,
  `evidence_ref` varchar(120) DEFAULT NULL,
  `resolved_at` timestamp(6) NULL DEFAULT NULL,
  `closed_at` timestamp(6) NULL DEFAULT NULL,
  `created_by` char(26) NOT NULL,
  `created_at` timestamp(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `updated_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `guest_feedback_property_id_number_unique` (`property_id`,`number`),
  UNIQUE KEY `guest_feedback_property_id_client_key_unique` (`property_id`,`client_key`),
  KEY `guest_feedback_stay_id_foreign` (`stay_id`),
  KEY `guest_feedback_room_id_foreign` (`room_id`),
  KEY `guest_feedback_owner_id_foreign` (`owner_id`),
  KEY `guest_feedback_created_by_foreign` (`created_by`),
  KEY `guest_feedback_property_id_status_severity_index` (`property_id`,`status`,`severity`),
  KEY `guest_feedback_reservation_id_index` (`reservation_id`),
  CONSTRAINT `guest_feedback_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `guest_feedback_owner_id_foreign` FOREIGN KEY (`owner_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `guest_feedback_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `guest_feedback_reservation_id_foreign` FOREIGN KEY (`reservation_id`) REFERENCES `reservations` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `guest_feedback_room_id_foreign` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `guest_feedback_stay_id_foreign` FOREIGN KEY (`stay_id`) REFERENCES `stays` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_feedback_channel` CHECK ((`channel` in (_utf8mb4'in_person',_utf8mb4'phone',_utf8mb4'email',_utf8mb4'online',_utf8mb4'other'))),
  CONSTRAINT `chk_feedback_kind` CHECK ((`kind` in (_utf8mb4'complaint',_utf8mb4'compliment',_utf8mb4'suggestion'))),
  CONSTRAINT `chk_feedback_resolved` CHECK (((`status` in (_utf8mb4'open',_utf8mb4'in_progress')) or ((`resolution` is not null) and (`resolved_at` is not null)))),
  CONSTRAINT `chk_feedback_severity` CHECK ((((`kind` = _utf8mb4'complaint') and (`severity` in (_utf8mb4'low',_utf8mb4'medium',_utf8mb4'high',_utf8mb4'critical'))) or ((`kind` <> _utf8mb4'complaint') and (`severity` is null)))),
  CONSTRAINT `chk_feedback_status` CHECK ((`status` in (_utf8mb4'open',_utf8mb4'in_progress',_utf8mb4'resolved',_utf8mb4'closed'))),
  CONSTRAINT `chk_feedback_summary` CHECK ((char_length(trim(`summary`)) > 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `guest_feedback_facts_immutable` BEFORE UPDATE ON `guest_feedback` FOR EACH ROW BEGIN
    IF NOT (NEW.id <=> OLD.id AND NEW.property_id <=> OLD.property_id AND NEW.number <=> OLD.number AND NEW.client_key <=> OLD.client_key AND NEW.kind <=> OLD.kind
        AND NEW.channel <=> OLD.channel AND NEW.reservation_id <=> OLD.reservation_id AND NEW.stay_id <=> OLD.stay_id AND NEW.room_id <=> OLD.room_id
        AND NEW.guest_name <=> OLD.guest_name AND NEW.summary <=> OLD.summary AND NEW.detail <=> OLD.detail AND NEW.created_by <=> OLD.created_by AND NEW.created_at <=> OLD.created_at) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'what the guest said cannot be changed';
    END IF;
    IF OLD.status = 'closed' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a closed item cannot be changed';
    END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `guest_feedback_no_delete` BEFORE DELETE ON `guest_feedback` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'guest feedback cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `guest_feedback_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `guest_feedback_events` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `feedback_id` char(26) NOT NULL,
  `kind` varchar(12) NOT NULL,
  `text` varchar(500) DEFAULT NULL,
  `actor_id` char(26) NOT NULL,
  `created_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `guest_feedback_events_property_id_foreign` (`property_id`),
  KEY `guest_feedback_events_actor_id_foreign` (`actor_id`),
  KEY `guest_feedback_events_feedback_id_created_at_index` (`feedback_id`,`created_at`),
  CONSTRAINT `guest_feedback_events_actor_id_foreign` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `guest_feedback_events_feedback_id_foreign` FOREIGN KEY (`feedback_id`) REFERENCES `guest_feedback` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `guest_feedback_events_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `guest_feedback_events_no_update` BEFORE UPDATE ON `guest_feedback_events` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a feedback event cannot be changed' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `guest_feedback_events_no_delete` BEFORE DELETE ON `guest_feedback_events` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a feedback event cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `guest_message_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `guest_message_log` (
  `property_id` char(26) NOT NULL,
  `reservation_id` char(26) NOT NULL,
  `kind` varchar(16) NOT NULL,
  `sent_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`reservation_id`,`kind`),
  KEY `guest_message_log_property_id_foreign` (`property_id`),
  CONSTRAINT `guest_message_log_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `guest_message_log_reservation_id_foreign` FOREIGN KEY (`reservation_id`) REFERENCES `reservations` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `guest_notes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `guest_notes` (
  `property_id` char(26) NOT NULL,
  `guest_key` char(64) NOT NULL,
  `flag` varchar(16) DEFAULT NULL,
  `note` varchar(500) DEFAULT NULL,
  `updated_by` char(26) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`property_id`,`guest_key`),
  KEY `guest_notes_updated_by_foreign` (`updated_by`),
  CONSTRAINT `guest_notes_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `guest_notes_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_guest_notes_flag` CHECK (((`flag` is null) or (`flag` in (_utf8mb4'vip',_utf8mb4'attention',_utf8mb4'do_not_rent'))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `guest_preferences`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `guest_preferences` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `id_number_index` char(64) NOT NULL,
  `preferences_enc` text NOT NULL,
  `updated_by` char(26) NOT NULL,
  `lock_version` bigint unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp(6) NOT NULL,
  `updated_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `guest_preferences_property_id_id_number_index_unique` (`property_id`,`id_number_index`),
  CONSTRAINT `guest_preferences_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `guest_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `guest_requests` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `number` varchar(30) NOT NULL,
  `client_key` varchar(80) DEFAULT NULL,
  `stay_id` char(26) NOT NULL,
  `reservation_id` char(26) NOT NULL,
  `room_id` char(26) NOT NULL,
  `category` varchar(20) NOT NULL,
  `priority` varchar(8) NOT NULL,
  `title` varchar(120) NOT NULL,
  `detail` varchar(500) DEFAULT NULL,
  `due_at` timestamp(6) NULL DEFAULT NULL,
  `status` varchar(12) NOT NULL,
  `hk_task_id` char(26) DEFAULT NULL,
  `work_order_id` char(26) DEFAULT NULL,
  `resolution` varchar(300) DEFAULT NULL,
  `created_by` char(26) NOT NULL,
  `created_at` timestamp(6) NOT NULL,
  `closed_at` timestamp(6) NULL DEFAULT NULL,
  `closed_by` char(26) DEFAULT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `updated_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `guest_requests_property_id_number_unique` (`property_id`,`number`),
  UNIQUE KEY `guest_requests_property_id_client_key_unique` (`property_id`,`client_key`),
  KEY `guest_requests_reservation_id_foreign` (`reservation_id`),
  KEY `guest_requests_created_by_foreign` (`created_by`),
  KEY `guest_requests_closed_by_foreign` (`closed_by`),
  KEY `guest_requests_property_id_status_category_index` (`property_id`,`status`,`category`),
  KEY `guest_requests_room_id_status_index` (`room_id`,`status`),
  KEY `guest_requests_stay_id_index` (`stay_id`),
  KEY `guest_requests_work_order_id_index` (`work_order_id`),
  CONSTRAINT `guest_requests_closed_by_foreign` FOREIGN KEY (`closed_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `guest_requests_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `guest_requests_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `guest_requests_reservation_id_foreign` FOREIGN KEY (`reservation_id`) REFERENCES `reservations` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `guest_requests_room_id_foreign` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `guest_requests_stay_id_foreign` FOREIGN KEY (`stay_id`) REFERENCES `stays` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_requests_category` CHECK ((`category` in (_utf8mb4'housekeeping',_utf8mb4'food_beverage',_utf8mb4'maintenance',_utf8mb4'front_office',_utf8mb4'other'))),
  CONSTRAINT `chk_requests_closed` CHECK ((((`status` in (_utf8mb4'open',_utf8mb4'in_progress')) and (`closed_at` is null)) or ((`status` in (_utf8mb4'done',_utf8mb4'cancelled')) and (`closed_at` is not null) and (`closed_by` is not null)))),
  CONSTRAINT `chk_requests_priority` CHECK ((`priority` in (_utf8mb4'normal',_utf8mb4'urgent'))),
  CONSTRAINT `chk_requests_status` CHECK ((`status` in (_utf8mb4'open',_utf8mb4'in_progress',_utf8mb4'done',_utf8mb4'cancelled'))),
  CONSTRAINT `chk_requests_title` CHECK ((char_length(trim(`title`)) > 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `guest_requests_facts_immutable` BEFORE UPDATE ON `guest_requests` FOR EACH ROW BEGIN
    IF NOT (NEW.id <=> OLD.id AND NEW.property_id <=> OLD.property_id AND NEW.number <=> OLD.number AND NEW.client_key <=> OLD.client_key AND NEW.stay_id <=> OLD.stay_id
        AND NEW.reservation_id <=> OLD.reservation_id AND NEW.room_id <=> OLD.room_id AND NEW.category <=> OLD.category AND NEW.title <=> OLD.title AND NEW.due_at <=> OLD.due_at
        AND NEW.created_by <=> OLD.created_by AND NEW.created_at <=> OLD.created_at) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'what a guest asked for cannot be changed';
    END IF;
    IF OLD.status IN ('done', 'cancelled') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a closed guest request cannot be changed';
    END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `guest_requests_no_delete` BEFORE DELETE ON `guest_requests` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a guest request cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `guests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `guests` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `full_name` varchar(150) NOT NULL,
  `nationality` char(2) NOT NULL,
  `id_type` varchar(12) NOT NULL,
  `id_number_enc` text NOT NULL,
  `id_number_index` char(64) NOT NULL,
  `id_valid_until` date DEFAULT NULL,
  `visa_number_enc` text,
  `address_enc` text NOT NULL,
  `created_by` char(26) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `guests_created_by_foreign` (`created_by`),
  KEY `guests_property_id_id_number_index_index` (`property_id`,`id_number_index`),
  KEY `guests_property_id_full_name_index` (`property_id`,`full_name`),
  CONSTRAINT `guests_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `guests_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_guests_id_type` CHECK ((`id_type` in (_utf8mb4'ktp',_utf8mb4'passport',_utf8mb4'sim',_utf8mb4'kitas',_utf8mb4'other'))),
  CONSTRAINT `chk_guests_nationality` CHECK (regexp_like(`nationality`,_utf8mb4'^[A-Z]{2}$'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `guests_no_delete` BEFORE DELETE ON `guests` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'guests cannot be deleted; erase through the retention process' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `hk_checklist_completions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `hk_checklist_completions` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `run_id` char(26) NOT NULL,
  `item_id` varchar(20) NOT NULL,
  `note` varchar(300) DEFAULT NULL,
  `photo_file_id` char(26) DEFAULT NULL,
  `completed_by` char(26) NOT NULL,
  `completed_at` timestamp(6) NOT NULL,
  `business_date` date NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `hk_checklist_completions_run_id_item_id_unique` (`run_id`,`item_id`),
  KEY `hk_checklist_completions_completed_by_foreign` (`completed_by`),
  KEY `hk_completion_person_idx` (`property_id`,`completed_by`,`business_date`),
  CONSTRAINT `hk_checklist_completions_completed_by_foreign` FOREIGN KEY (`completed_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `hk_checklist_completions_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `hk_checklist_completions_run_id_foreign` FOREIGN KEY (`run_id`) REFERENCES `hk_checklist_runs` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `hk_checklist_completions_no_update` BEFORE UPDATE ON `hk_checklist_completions` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a ticked checklist item cannot be changed' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `hk_checklist_completions_no_delete` BEFORE DELETE ON `hk_checklist_completions` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a ticked checklist item cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `hk_checklist_runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `hk_checklist_runs` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `template_id` char(26) NOT NULL,
  `name` varchar(80) NOT NULL,
  `period_key` varchar(10) NOT NULL,
  `period_start` date NOT NULL,
  `period_end` date NOT NULL,
  `target_ref` varchar(60) NOT NULL,
  `target_label` varchar(60) NOT NULL,
  `items` json NOT NULL,
  `created_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `hk_run_unique` (`property_id`,`name`,`period_key`,`target_ref`),
  KEY `hk_checklist_runs_template_id_foreign` (`template_id`),
  CONSTRAINT `hk_checklist_runs_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `hk_checklist_runs_template_id_foreign` FOREIGN KEY (`template_id`) REFERENCES `hk_checklist_templates` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `hk_checklist_runs_no_update` BEFORE UPDATE ON `hk_checklist_runs` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a checklist run cannot be changed' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `hk_checklist_runs_no_delete` BEFORE DELETE ON `hk_checklist_runs` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a checklist run cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `hk_checklist_templates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `hk_checklist_templates` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `name` varchar(80) NOT NULL,
  `version` int unsigned NOT NULL,
  `frequency` varchar(8) NOT NULL,
  `scope` varchar(4) NOT NULL,
  `areas` json NOT NULL,
  `items` json NOT NULL,
  `is_active` tinyint(1) NOT NULL,
  `created_by` char(26) NOT NULL,
  `created_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `hk_checklist_templates_property_id_name_version_unique` (`property_id`,`name`,`version`),
  KEY `hk_checklist_templates_created_by_foreign` (`created_by`),
  CONSTRAINT `hk_checklist_templates_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `hk_checklist_templates_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_hk_frequency` CHECK ((`frequency` in (_utf8mb4'daily',_utf8mb4'weekly',_utf8mb4'monthly'))),
  CONSTRAINT `chk_hk_scope` CHECK ((`scope` in (_utf8mb4'room',_utf8mb4'area')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `hk_checklist_templates_no_update` BEFORE UPDATE ON `hk_checklist_templates` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a checklist template cannot be changed' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `hk_checklist_templates_no_delete` BEFORE DELETE ON `hk_checklist_templates` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a checklist template cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `housekeeping_rooms`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `housekeeping_rooms` (
  `room_id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `status` varchar(10) NOT NULL,
  `status_changed_at` timestamp(6) NOT NULL,
  `status_changed_by` char(26) DEFAULT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`room_id`),
  KEY `housekeeping_rooms_status_changed_by_foreign` (`status_changed_by`),
  KEY `housekeeping_rooms_property_id_status_index` (`property_id`,`status`),
  CONSTRAINT `housekeeping_rooms_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `housekeeping_rooms_room_id_foreign` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `housekeeping_rooms_status_changed_by_foreign` FOREIGN KEY (`status_changed_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_hk_rooms_status` CHECK ((`status` in (_utf8mb4'dirty',_utf8mb4'cleaning',_utf8mb4'clean',_utf8mb4'ready',_utf8mb4'rework')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `housekeeping_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `housekeeping_settings` (
  `property_id` char(26) NOT NULL,
  `inspection_required` tinyint(1) NOT NULL DEFAULT '1',
  `updated_by` char(26) DEFAULT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`property_id`),
  KEY `housekeeping_settings_updated_by_foreign` (`updated_by`),
  CONSTRAINT `housekeeping_settings_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `housekeeping_settings_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `housekeeping_status_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `housekeeping_status_log` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `room_id` char(26) NOT NULL,
  `from_status` varchar(10) DEFAULT NULL,
  `to_status` varchar(10) NOT NULL,
  `actor_id` char(26) DEFAULT NULL,
  `reason` varchar(300) DEFAULT NULL,
  `task_id` char(26) DEFAULT NULL,
  `occurred_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `housekeeping_status_log_room_id_foreign` (`room_id`),
  KEY `housekeeping_status_log_actor_id_foreign` (`actor_id`),
  KEY `housekeeping_status_log_property_id_room_id_occurred_at_index` (`property_id`,`room_id`,`occurred_at`),
  CONSTRAINT `housekeeping_status_log_actor_id_foreign` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `housekeeping_status_log_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `housekeeping_status_log_room_id_foreign` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `housekeeping_status_log_no_update` BEFORE UPDATE ON `housekeeping_status_log` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'housekeeping_status_log is append-only' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `housekeeping_status_log_no_delete` BEFORE DELETE ON `housekeeping_status_log` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'housekeeping_status_log is append-only' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `housekeeping_tasks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `housekeeping_tasks` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `room_id` char(26) NOT NULL,
  `kind` varchar(10) NOT NULL,
  `status` varchar(12) NOT NULL,
  `priority` tinyint unsigned NOT NULL,
  `assigned_to` char(26) DEFAULT NULL,
  `assigned_by` char(26) DEFAULT NULL,
  `source` varchar(40) NOT NULL,
  `source_ref` varchar(80) DEFAULT NULL,
  `created_by` char(26) DEFAULT NULL,
  `started_at` timestamp(6) NULL DEFAULT NULL,
  `finished_at` timestamp(6) NULL DEFAULT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  `active_room_key` char(26) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `hk_tasks_source_unique` (`property_id`,`source`,`source_ref`),
  UNIQUE KEY `hk_tasks_one_active_per_room` (`active_room_key`),
  KEY `housekeeping_tasks_room_id_foreign` (`room_id`),
  KEY `housekeeping_tasks_assigned_by_foreign` (`assigned_by`),
  KEY `housekeeping_tasks_created_by_foreign` (`created_by`),
  KEY `housekeeping_tasks_property_id_status_priority_index` (`property_id`,`status`,`priority`),
  KEY `housekeeping_tasks_assigned_to_status_index` (`assigned_to`,`status`),
  CONSTRAINT `housekeeping_tasks_assigned_by_foreign` FOREIGN KEY (`assigned_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `housekeeping_tasks_assigned_to_foreign` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `housekeeping_tasks_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `housekeeping_tasks_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `housekeeping_tasks_room_id_foreign` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_hk_tasks_assigned` CHECK (((`status` <> _utf8mb4'assigned') or (`assigned_to` is not null))),
  CONSTRAINT `chk_hk_tasks_kind` CHECK ((`kind` in (_utf8mb4'departure',_utf8mb4'vacant',_utf8mb4'request',_utf8mb4'stayover',_utf8mb4'rework'))),
  CONSTRAINT `chk_hk_tasks_status` CHECK ((`status` in (_utf8mb4'open',_utf8mb4'assigned',_utf8mb4'in_progress',_utf8mb4'done',_utf8mb4'cancelled'))),
  CONSTRAINT `chk_hk_tasks_times` CHECK ((((`status` in (_utf8mb4'open',_utf8mb4'assigned')) and (`started_at` is null) and (`finished_at` is null)) or ((`status` = _utf8mb4'in_progress') and (`started_at` is not null) and (`finished_at` is null)) or ((`status` = _utf8mb4'done') and (`started_at` is not null) and (`finished_at` is not null) and (`finished_at` >= `started_at`)) or (`status` = _utf8mb4'cancelled')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `housekeeping_tasks_active_key_insert` BEFORE INSERT ON `housekeeping_tasks` FOR EACH ROW BEGIN
    SET NEW.active_room_key = CASE WHEN NEW.status IN ('open', 'assigned', 'in_progress') THEN NEW.room_id ELSE NULL END;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `housekeeping_tasks_facts` BEFORE UPDATE ON `housekeeping_tasks` FOR EACH ROW BEGIN
    IF NOT (NEW.id <=> OLD.id AND NEW.property_id <=> OLD.property_id AND NEW.room_id <=> OLD.room_id AND NEW.kind <=> OLD.kind
        AND NEW.source <=> OLD.source AND NEW.source_ref <=> OLD.source_ref AND NEW.created_by <=> OLD.created_by) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'the origin of a housekeeping task cannot be changed';
    END IF;
    IF OLD.status IN ('done', 'cancelled') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a finished housekeeping task cannot be changed';
    END IF;
    SET NEW.active_room_key = CASE WHEN NEW.status IN ('open', 'assigned', 'in_progress') THEN NEW.room_id ELSE NULL END;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `housekeeping_tasks_no_delete` BEFORE DELETE ON `housekeeping_tasks` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'housekeeping tasks cannot be deleted; cancel them' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `hr_announcement_reads`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `hr_announcement_reads` (
  `announcement_id` char(26) NOT NULL,
  `employee_id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `read_at` timestamp(6) NOT NULL,
  `acknowledged_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`announcement_id`,`employee_id`),
  KEY `hr_announcement_reads_property_id_foreign` (`property_id`),
  KEY `hr_announcement_reads_employee_id_foreign` (`employee_id`),
  CONSTRAINT `hr_announcement_reads_announcement_id_foreign` FOREIGN KEY (`announcement_id`) REFERENCES `hr_announcements` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `hr_announcement_reads_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `hr_employees` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `hr_announcement_reads_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `hr_announcement_reads_guard` BEFORE UPDATE ON `hr_announcement_reads` FOR EACH ROW BEGIN IF OLD.acknowledged_at IS NOT NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a confirmation cannot be changed'; END IF; END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `hr_announcement_reads_no_delete` BEFORE DELETE ON `hr_announcement_reads` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a read record cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `hr_announcements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `hr_announcements` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `kind` varchar(12) NOT NULL,
  `title` varchar(120) NOT NULL,
  `body` varchar(4000) NOT NULL,
  `audience` varchar(16) NOT NULL,
  `requires_ack` tinyint(1) NOT NULL,
  `expires_on` date DEFAULT NULL,
  `file_id` char(26) DEFAULT NULL,
  `status` varchar(10) NOT NULL,
  `withdraw_reason` varchar(200) DEFAULT NULL,
  `published_by` char(26) NOT NULL,
  `published_at` timestamp(6) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `hr_announcements_property_id_status_published_at_index` (`property_id`,`status`,`published_at`),
  CONSTRAINT `hr_announcements_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_hr_announcements` CHECK (((`kind` in (_utf8mb4'announcement',_utf8mb4'policy')) and (`status` in (_utf8mb4'published',_utf8mb4'withdrawn')) and ((`status` <> _utf8mb4'withdrawn') or (`withdraw_reason` is not null))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `hr_announcements_no_delete` BEFORE DELETE ON `hr_announcements` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'an announcement cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `hr_appraisal_forms`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `hr_appraisal_forms` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `name` varchar(80) NOT NULL,
  `criteria` json NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_by` char(26) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `hr_appraisal_forms_property_id_name_unique` (`property_id`,`name`),
  CONSTRAINT `hr_appraisal_forms_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `hr_appraisals`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `hr_appraisals` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `number` varchar(24) NOT NULL,
  `employee_id` char(26) NOT NULL,
  `form_id` char(26) NOT NULL,
  `form_name` varchar(80) NOT NULL,
  `criteria` json NOT NULL,
  `period_label` varchar(30) NOT NULL,
  `period_start` date NOT NULL,
  `period_end` date NOT NULL,
  `status` varchar(16) NOT NULL,
  `scores` json DEFAULT NULL,
  `comment` varchar(1000) DEFAULT NULL,
  `overall_x100` smallint unsigned DEFAULT NULL,
  `rating` varchar(12) DEFAULT NULL,
  `metrics` json DEFAULT NULL,
  `appraiser_id` char(26) DEFAULT NULL,
  `appraiser_signed_at` timestamp(6) NULL DEFAULT NULL,
  `appraiser_hash` char(64) DEFAULT NULL,
  `employee_agrees` tinyint(1) DEFAULT NULL,
  `employee_comment` varchar(500) DEFAULT NULL,
  `employee_signed_by` char(26) DEFAULT NULL,
  `employee_signed_at` timestamp(6) NULL DEFAULT NULL,
  `employee_hash` char(64) DEFAULT NULL,
  `cancel_reason` varchar(200) DEFAULT NULL,
  `created_by` char(26) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `hr_appraisals_property_id_number_unique` (`property_id`,`number`),
  KEY `hr_appraisals_employee_id_period_end_index` (`employee_id`,`period_end`),
  KEY `hr_appraisals_form_id_foreign` (`form_id`),
  CONSTRAINT `hr_appraisals_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `hr_employees` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `hr_appraisals_form_id_foreign` FOREIGN KEY (`form_id`) REFERENCES `hr_appraisal_forms` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `hr_appraisals_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_hr_appraisals` CHECK (((`status` in (_utf8mb4'draft',_utf8mb4'signed_appraiser',_utf8mb4'completed',_utf8mb4'cancelled')) and (`period_end` >= `period_start`) and ((`status` not in (_utf8mb4'signed_appraiser',_utf8mb4'completed')) or ((`appraiser_signed_at` is not null) and (`appraiser_hash` is not null) and (`overall_x100` is not null))) and ((`status` <> _utf8mb4'completed') or ((`employee_signed_at` is not null) and (`employee_hash` is not null) and (`employee_agrees` is not null))) and ((`status` <> _utf8mb4'cancelled') or (`cancel_reason` is not null))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `hr_appraisals_guard` BEFORE UPDATE ON `hr_appraisals` FOR EACH ROW BEGIN
    IF OLD.status IN ('signed_appraiser', 'completed') AND NOT (NEW.criteria <=> OLD.criteria AND NEW.scores <=> OLD.scores AND NEW.comment <=> OLD.comment AND NEW.overall_x100 <=> OLD.overall_x100 AND NEW.metrics <=> OLD.metrics AND NEW.appraiser_hash <=> OLD.appraiser_hash AND NEW.appraiser_id <=> OLD.appraiser_id AND NEW.employee_id <=> OLD.employee_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a signed appraisal cannot be changed';
    END IF;
    IF OLD.status = 'completed' AND NOT (NEW.status <=> OLD.status AND NEW.employee_hash <=> OLD.employee_hash AND NEW.employee_agrees <=> OLD.employee_agrees AND NEW.employee_comment <=> OLD.employee_comment) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a completed appraisal cannot be changed';
    END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `hr_appraisals_no_delete` BEFORE DELETE ON `hr_appraisals` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'an appraisal cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `hr_attendance`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `hr_attendance` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `employee_id` char(26) NOT NULL,
  `work_date` date NOT NULL,
  `in_at` timestamp(6) NOT NULL,
  `in_method` varchar(6) NOT NULL,
  `in_distance_m` int unsigned DEFAULT NULL,
  `in_accuracy_m` smallint unsigned DEFAULT NULL,
  `in_device` char(64) DEFAULT NULL,
  `in_photo_file_id` char(26) DEFAULT NULL,
  `in_photo_hash` char(64) DEFAULT NULL,
  `out_at` timestamp(6) NULL DEFAULT NULL,
  `out_method` varchar(6) DEFAULT NULL,
  `out_distance_m` int unsigned DEFAULT NULL,
  `out_accuracy_m` smallint unsigned DEFAULT NULL,
  `out_device` char(64) DEFAULT NULL,
  `out_photo_file_id` char(26) DEFAULT NULL,
  `out_photo_hash` char(64) DEFAULT NULL,
  `recorded_by` char(26) DEFAULT NULL,
  `manual_reason` varchar(200) DEFAULT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  `in_face` varchar(10) DEFAULT NULL,
  `in_face_x` smallint unsigned DEFAULT NULL,
  `out_face` varchar(10) DEFAULT NULL,
  `out_face_x` smallint unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `hr_attendance_employee_id_work_date_unique` (`employee_id`,`work_date`),
  KEY `hr_attendance_property_id_work_date_index` (`property_id`,`work_date`),
  KEY `hr_attendance_property_id_in_device_index` (`property_id`,`in_device`),
  KEY `hr_attendance_property_id_in_photo_hash_index` (`property_id`,`in_photo_hash`),
  CONSTRAINT `hr_attendance_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `hr_employees` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `hr_attendance_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_hr_attendance` CHECK (((`in_method` in (_utf8mb4'mobile',_utf8mb4'manual')) and ((`out_at` is null) or (`out_at` > `in_at`)) and (((`out_at` is null) and (`out_method` is null)) or ((`out_at` is not null) and (`out_method` in (_utf8mb4'mobile',_utf8mb4'manual')))) and (((`in_method` = _utf8mb4'mobile') and ((`out_method` is null) or (`out_method` = _utf8mb4'mobile'))) or (`manual_reason` is not null)))),
  CONSTRAINT `chk_hr_attendance_face` CHECK ((((`in_face` is null) or (`in_face` in (_utf8mb4'match',_utf8mb4'mismatch',_utf8mb4'none'))) and ((`out_face` is null) or (`out_face` in (_utf8mb4'match',_utf8mb4'mismatch',_utf8mb4'none')))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `hr_attendance_no_delete` BEFORE DELETE ON `hr_attendance` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'an attendance record cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `hr_attendance_corrections`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `hr_attendance_corrections` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `employee_id` char(26) NOT NULL,
  `work_date` date NOT NULL,
  `status` varchar(16) NOT NULL,
  `reason` varchar(200) NOT NULL,
  `record_id` char(26) DEFAULT NULL,
  `old_in_at` timestamp(6) NULL DEFAULT NULL,
  `old_out_at` timestamp(6) NULL DEFAULT NULL,
  `new_in_at` timestamp(6) NOT NULL,
  `new_out_at` timestamp(6) NULL DEFAULT NULL,
  `approval_id` char(26) DEFAULT NULL,
  `requested_by` char(26) NOT NULL,
  `applied_by` char(26) DEFAULT NULL,
  `applied_at` timestamp(6) NULL DEFAULT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `hr_attendance_corrections_property_id_created_at_index` (`property_id`,`created_at`),
  KEY `hr_attendance_corrections_employee_id_work_date_index` (`employee_id`,`work_date`),
  CONSTRAINT `hr_attendance_corrections_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `hr_employees` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `hr_attendance_corrections_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_hr_attendance_corrections` CHECK (((`status` in (_utf8mb4'pending_approval',_utf8mb4'applied',_utf8mb4'rejected',_utf8mb4'cancelled')) and ((`new_out_at` is null) or (`new_out_at` > `new_in_at`)) and ((`status` <> _utf8mb4'applied') or (`applied_at` is not null))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `hr_attendance_corrections_values` BEFORE UPDATE ON `hr_attendance_corrections` FOR EACH ROW BEGIN
                IF NOT (OLD.employee_id <=> NEW.employee_id AND OLD.work_date <=> NEW.work_date AND OLD.reason <=> NEW.reason AND OLD.record_id <=> NEW.record_id AND OLD.old_in_at <=> NEW.old_in_at AND OLD.old_out_at <=> NEW.old_out_at
                    AND OLD.new_in_at <=> NEW.new_in_at AND OLD.new_out_at <=> NEW.new_out_at AND OLD.requested_by <=> NEW.requested_by) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'the values of a correction cannot change';
                END IF;
            END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `hr_attendance_corrections_no_delete` BEFORE DELETE ON `hr_attendance_corrections` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a correction cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `hr_attendance_reviews`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `hr_attendance_reviews` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `attendance_id` char(26) NOT NULL,
  `side` varchar(3) NOT NULL,
  `flags` json NOT NULL,
  `decision` varchar(10) NOT NULL,
  `note` varchar(300) DEFAULT NULL,
  `reviewed_by` char(26) NOT NULL,
  `reviewed_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `hr_attendance_reviews_attendance_id_side_unique` (`attendance_id`,`side`),
  KEY `hr_attendance_reviews_property_id_reviewed_at_index` (`property_id`,`reviewed_at`),
  CONSTRAINT `hr_attendance_reviews_attendance_id_foreign` FOREIGN KEY (`attendance_id`) REFERENCES `hr_attendance` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `hr_attendance_reviews_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_hr_attendance_review` CHECK (((`side` in (_utf8mb4'in',_utf8mb4'out')) and (`decision` in (_utf8mb4'ok',_utf8mb4'questioned')) and ((`decision` = _utf8mb4'ok') or ((`note` is not null) and (char_length(`note`) > 0)))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `hr_attendance_reviews_no_update` BEFORE UPDATE ON `hr_attendance_reviews` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a review decision cannot be changed' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `hr_attendance_reviews_no_delete` BEFORE DELETE ON `hr_attendance_reviews` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a review decision cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `hr_attendance_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `hr_attendance_settings` (
  `property_id` char(26) NOT NULL,
  `latitude` decimal(9,6) DEFAULT NULL,
  `longitude` decimal(9,6) DEFAULT NULL,
  `radius_m` smallint unsigned NOT NULL,
  `require_selfie` tinyint(1) NOT NULL,
  `late_grace_minutes` smallint unsigned NOT NULL,
  `early_grace_minutes` smallint unsigned NOT NULL,
  `extra_after_minutes` smallint unsigned NOT NULL,
  `updated_by` char(26) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  `face_mode` varchar(8) NOT NULL DEFAULT 'off',
  PRIMARY KEY (`property_id`),
  CONSTRAINT `hr_attendance_settings_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_hr_attendance_face_mode` CHECK ((`face_mode` in (_utf8mb4'off',_utf8mb4'flag',_utf8mb4'require'))),
  CONSTRAINT `chk_hr_attendance_settings` CHECK (((`radius_m` between 20 and 5000) and (`late_grace_minutes` <= 120) and (`early_grace_minutes` <= 120) and (`extra_after_minutes` <= 240) and (((`latitude` is null) and (`longitude` is null)) or ((`latitude` between -(90) and 90) and (`longitude` between -(180) and 180)))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `hr_bank_accounts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `hr_bank_accounts` (
  `employee_id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `bank_name` varchar(60) NOT NULL,
  `account_holder` varchar(120) NOT NULL,
  `account_number_enc` text NOT NULL,
  `account_last4` char(4) NOT NULL,
  `updated_by` char(26) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`employee_id`),
  KEY `hr_bank_accounts_property_id_foreign` (`property_id`),
  CONSTRAINT `hr_bank_accounts_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `hr_employees` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `hr_bank_accounts_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `hr_commission_entries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `hr_commission_entries` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `employee_id` char(26) NOT NULL,
  `type_id` char(26) NOT NULL,
  `earned_on` date NOT NULL,
  `amount_minor` bigint NOT NULL,
  `note` varchar(200) DEFAULT NULL,
  `reverses_id` char(26) DEFAULT NULL,
  `source_ref` char(26) DEFAULT NULL,
  `entered_by` char(26) NOT NULL,
  `created_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `hr_commission_entries_reversed_once` (`property_id`,`reverses_id`),
  UNIQUE KEY `hr_commission_entries_source_once` (`property_id`,`source_ref`),
  KEY `hr_commission_entries_day_index` (`property_id`,`earned_on`),
  KEY `hr_commission_entries_employee_index` (`employee_id`,`earned_on`),
  KEY `hr_commission_entries_type_id_foreign` (`type_id`),
  CONSTRAINT `hr_commission_entries_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `hr_employees` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `hr_commission_entries_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `hr_commission_entries_type_id_foreign` FOREIGN KEY (`type_id`) REFERENCES `hr_commission_types` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_hr_commission_amount` CHECK (((`amount_minor` <> 0) and ((`reverses_id` is null) or (`amount_minor` < 0))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `hr_commission_entries_no_update` BEFORE UPDATE ON `hr_commission_entries` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a commission entry cannot be changed' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `hr_commission_entries_no_delete` BEFORE DELETE ON `hr_commission_entries` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a commission entry cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `hr_commission_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `hr_commission_types` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `code` varchar(20) NOT NULL,
  `name` varchar(80) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `hr_commission_types_code_unique` (`property_id`,`code`),
  CONSTRAINT `hr_commission_types_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `hr_conduct_records`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `hr_conduct_records` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `employee_id` char(26) NOT NULL,
  `kind` varchar(8) NOT NULL,
  `issued_on` date NOT NULL,
  `valid_until` date DEFAULT NULL,
  `reason` varchar(500) NOT NULL,
  `file_id` char(26) DEFAULT NULL,
  `status` varchar(8) NOT NULL,
  `revoke_reason` varchar(200) DEFAULT NULL,
  `revoked_by` char(26) DEFAULT NULL,
  `revoked_at` timestamp(6) NULL DEFAULT NULL,
  `issued_by` char(26) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `hr_conduct_records_property_id_issued_on_index` (`property_id`,`issued_on`),
  KEY `hr_conduct_records_employee_id_issued_on_index` (`employee_id`,`issued_on`),
  CONSTRAINT `hr_conduct_records_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `hr_employees` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `hr_conduct_records_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_hr_conduct_records` CHECK (((`kind` in (_utf8mb4'verbal',_utf8mb4'sp1',_utf8mb4'sp2',_utf8mb4'sp3',_utf8mb4'award')) and (`status` in (_utf8mb4'issued',_utf8mb4'revoked')) and ((`valid_until` is null) or (`valid_until` >= `issued_on`)) and ((`kind` <> _utf8mb4'award') or (`valid_until` is null)) and ((`status` <> _utf8mb4'revoked') or ((`revoke_reason` is not null) and (`revoked_at` is not null)))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `hr_conduct_records_no_delete` BEFORE DELETE ON `hr_conduct_records` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a conduct record cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `hr_documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `hr_documents` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `employee_id` char(26) NOT NULL,
  `kind` varchar(12) NOT NULL,
  `title` varchar(120) NOT NULL,
  `issued_on` date DEFAULT NULL,
  `valid_until` date DEFAULT NULL,
  `file_id` char(26) NOT NULL,
  `is_current` tinyint(1) NOT NULL DEFAULT '1',
  `uploaded_by` char(26) NOT NULL,
  `created_at` timestamp(6) NOT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `hr_documents_employee_id_kind_is_current_index` (`employee_id`,`kind`,`is_current`),
  KEY `hr_documents_property_id_valid_until_index` (`property_id`,`valid_until`),
  CONSTRAINT `hr_documents_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `hr_employees` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `hr_documents_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_hr_document` CHECK (((`kind` in (_utf8mb4'contract',_utf8mb4'identity',_utf8mb4'certificate',_utf8mb4'medical',_utf8mb4'other')) and ((`valid_until` is null) or (`issued_on` is null) or (`valid_until` >= `issued_on`))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `hr_documents_no_delete` BEFORE DELETE ON `hr_documents` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a personnel document cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `hr_employees`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `hr_employees` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `number` varchar(20) NOT NULL,
  `full_name` varchar(120) NOT NULL,
  `department` varchar(16) NOT NULL,
  `position` varchar(80) NOT NULL,
  `joined_on` date NOT NULL,
  `contract_type` varchar(10) NOT NULL,
  `contract_end_on` date DEFAULT NULL,
  `supervisor_id` char(26) DEFAULT NULL,
  `user_id` char(26) DEFAULT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `email` varchar(120) DEFAULT NULL,
  `status` varchar(10) NOT NULL DEFAULT 'active',
  `offboarded_on` date DEFAULT NULL,
  `offboard_kind` varchar(14) DEFAULT NULL,
  `offboard_reason` varchar(200) DEFAULT NULL,
  `offboarded_by` char(26) DEFAULT NULL,
  `created_by` char(26) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `hr_employees_property_id_number_unique` (`property_id`,`number`),
  UNIQUE KEY `hr_employees_property_id_user_id_unique` (`property_id`,`user_id`),
  KEY `hr_employees_property_id_status_department_index` (`property_id`,`status`,`department`),
  KEY `hr_employees_supervisor_id_index` (`supervisor_id`),
  CONSTRAINT `hr_employees_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `hr_employees_supervisor_id_foreign` FOREIGN KEY (`supervisor_id`) REFERENCES `hr_employees` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_hr_employee` CHECK (((`status` in (_utf8mb4'active',_utf8mb4'offboarded')) and (`contract_type` in (_utf8mb4'permanent',_utf8mb4'contract',_utf8mb4'probation',_utf8mb4'daily',_utf8mb4'intern')) and ((`contract_type` = _utf8mb4'permanent') or (`contract_end_on` is not null)) and ((`contract_type` <> _utf8mb4'permanent') or (`contract_end_on` is null)) and ((`contract_end_on` is null) or (`contract_end_on` >= `joined_on`)) and ((`supervisor_id` is null) or (`supervisor_id` <> `id`)) and ((`status` = _utf8mb4'active') or ((`offboarded_on` is not null) and (`offboard_kind` in (_utf8mb4'resigned',_utf8mb4'terminated',_utf8mb4'contract_ended',_utf8mb4'retired',_utf8mb4'other')) and (`offboarded_by` is not null) and (`offboarded_on` >= `joined_on`))) and ((`status` = _utf8mb4'offboarded') or ((`offboarded_on` is null) and (`offboard_kind` is null)))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `hr_face_templates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `hr_face_templates` (
  `property_id` char(26) NOT NULL,
  `employee_id` char(26) NOT NULL,
  `template` text NOT NULL,
  `samples` tinyint unsigned NOT NULL,
  `enrolled_by` char(26) NOT NULL,
  `enrolled_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`employee_id`),
  KEY `hr_face_templates_enrolled_by_foreign` (`enrolled_by`),
  KEY `hr_face_templates_property_id_index` (`property_id`),
  CONSTRAINT `hr_face_templates_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `hr_employees` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `hr_face_templates_enrolled_by_foreign` FOREIGN KEY (`enrolled_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `hr_face_templates_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `hr_leave_adjustments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `hr_leave_adjustments` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `employee_id` char(26) NOT NULL,
  `leave_type_id` char(26) NOT NULL,
  `year` smallint unsigned NOT NULL,
  `days_delta` smallint NOT NULL,
  `reason` varchar(200) NOT NULL,
  `created_by` char(26) NOT NULL,
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `hr_leave_adjustments_property_id_foreign` (`property_id`),
  KEY `hr_leave_adjustments_employee_id_leave_type_id_year_index` (`employee_id`,`leave_type_id`,`year`),
  KEY `hr_leave_adjustments_leave_type_id_foreign` (`leave_type_id`),
  CONSTRAINT `hr_leave_adjustments_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `hr_employees` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `hr_leave_adjustments_leave_type_id_foreign` FOREIGN KEY (`leave_type_id`) REFERENCES `hr_leave_types` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `hr_leave_adjustments_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_hr_leave_adjustments` CHECK ((`days_delta` <> 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `hr_leave_adjustments_no_update` BEFORE UPDATE ON `hr_leave_adjustments` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a leave adjustment cannot change' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `hr_leave_adjustments_no_delete` BEFORE DELETE ON `hr_leave_adjustments` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a leave adjustment cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `hr_leave_days`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `hr_leave_days` (
  `employee_id` char(26) NOT NULL,
  `work_date` date NOT NULL,
  `property_id` char(26) NOT NULL,
  `leave_id` char(26) NOT NULL,
  `type_code` varchar(8) NOT NULL,
  `counted` tinyint(1) NOT NULL,
  `entry_snapshot` json DEFAULT NULL,
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`employee_id`,`work_date`),
  KEY `hr_leave_days_property_id_work_date_index` (`property_id`,`work_date`),
  KEY `hr_leave_days_leave_id_index` (`leave_id`),
  CONSTRAINT `hr_leave_days_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `hr_employees` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `hr_leave_days_leave_id_foreign` FOREIGN KEY (`leave_id`) REFERENCES `hr_leave_requests` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `hr_leave_days_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `hr_leave_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `hr_leave_requests` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `employee_id` char(26) NOT NULL,
  `leave_type_id` char(26) NOT NULL,
  `type_code` varchar(8) NOT NULL,
  `type_name` varchar(60) NOT NULL,
  `deducts_balance` tinyint(1) NOT NULL,
  `from_date` date NOT NULL,
  `to_date` date NOT NULL,
  `days` smallint unsigned NOT NULL,
  `reason` varchar(200) NOT NULL,
  `status` varchar(16) NOT NULL,
  `approval_id` char(26) DEFAULT NULL,
  `evidence_file_id` char(26) DEFAULT NULL,
  `requested_by` char(26) NOT NULL,
  `decided_at` timestamp(6) NULL DEFAULT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `hr_leave_requests_property_id_from_date_index` (`property_id`,`from_date`),
  KEY `hr_leave_requests_employee_id_from_date_index` (`employee_id`,`from_date`),
  KEY `hr_leave_requests_leave_type_id_foreign` (`leave_type_id`),
  CONSTRAINT `hr_leave_requests_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `hr_employees` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `hr_leave_requests_leave_type_id_foreign` FOREIGN KEY (`leave_type_id`) REFERENCES `hr_leave_types` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `hr_leave_requests_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_hr_leave_requests` CHECK (((`to_date` >= `from_date`) and (`status` in (_utf8mb4'pending_approval',_utf8mb4'approved',_utf8mb4'rejected',_utf8mb4'cancelled')) and ((`status` <> _utf8mb4'approved') or (`decided_at` is not null))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `hr_leave_requests_no_delete` BEFORE DELETE ON `hr_leave_requests` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a leave request cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `hr_leave_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `hr_leave_types` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `code` varchar(8) NOT NULL,
  `name` varchar(60) NOT NULL,
  `deducts_balance` tinyint(1) NOT NULL,
  `entitlement_days` smallint unsigned NOT NULL,
  `eligible_after_months` smallint unsigned NOT NULL,
  `evidence_after_days` smallint unsigned DEFAULT NULL,
  `paid` tinyint(1) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `hr_leave_types_property_id_code_unique` (`property_id`,`code`),
  CONSTRAINT `hr_leave_types_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_hr_leave_types` CHECK (((`entitlement_days` <= 365) and (`eligible_after_months` <= 60) and (((`deducts_balance` = 1) and (`entitlement_days` > 0)) or ((`deducts_balance` = 0) and (`entitlement_days` = 0)))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `hr_offboard_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `hr_offboard_items` (
  `id` char(26) NOT NULL,
  `employee_id` char(26) NOT NULL,
  `item` varchar(120) NOT NULL,
  `returned` tinyint(1) NOT NULL,
  `note` varchar(200) DEFAULT NULL,
  `created_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `hr_offboard_items_employee_id_index` (`employee_id`),
  CONSTRAINT `hr_offboard_items_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `hr_employees` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `hr_offboard_items_no_update` BEFORE UPDATE ON `hr_offboard_items` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'an offboarding record cannot be changed' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `hr_offboard_items_no_delete` BEFORE DELETE ON `hr_offboard_items` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'an offboarding record cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `hr_overtime`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `hr_overtime` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `employee_id` char(26) NOT NULL,
  `work_date` date NOT NULL,
  `minutes` smallint unsigned NOT NULL,
  `reason` varchar(200) NOT NULL,
  `status` varchar(16) NOT NULL,
  `approval_id` char(26) DEFAULT NULL,
  `requested_by` char(26) NOT NULL,
  `approved_at` timestamp(6) NULL DEFAULT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `hr_overtime_property_id_work_date_index` (`property_id`,`work_date`),
  KEY `hr_overtime_employee_id_work_date_index` (`employee_id`,`work_date`),
  CONSTRAINT `hr_overtime_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `hr_employees` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `hr_overtime_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_hr_overtime` CHECK (((`minutes` between 15 and 480) and (`status` in (_utf8mb4'pending_approval',_utf8mb4'approved',_utf8mb4'rejected',_utf8mb4'cancelled')) and ((`status` <> _utf8mb4'approved') or (`approved_at` is not null))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `hr_overtime_no_delete` BEFORE DELETE ON `hr_overtime` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'an overtime request cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `hr_pay_components`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `hr_pay_components` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `code` varchar(8) NOT NULL,
  `name` varchar(60) NOT NULL,
  `kind` varchar(20) NOT NULL,
  `taxable` tinyint(1) NOT NULL DEFAULT '1',
  `social_base` tinyint(1) NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `hr_pay_components_property_id_code_unique` (`property_id`,`code`),
  CONSTRAINT `hr_pay_components_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_hr_pay_components` CHECK ((`kind` in (_utf8mb4'basic',_utf8mb4'fixed_allowance',_utf8mb4'variable_allowance',_utf8mb4'meal',_utf8mb4'transport')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `hr_pay_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `hr_pay_items` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `employee_id` char(26) NOT NULL,
  `component_id` char(26) NOT NULL,
  `amount_minor` bigint unsigned NOT NULL,
  `effective_from` date NOT NULL,
  `reason` varchar(200) NOT NULL,
  `created_by` char(26) NOT NULL,
  `created_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `hr_pay_items_once` (`employee_id`,`component_id`,`effective_from`),
  KEY `hr_pay_items_property_id_effective_from_index` (`property_id`,`effective_from`),
  KEY `hr_pay_items_component_id_foreign` (`component_id`),
  CONSTRAINT `hr_pay_items_component_id_foreign` FOREIGN KEY (`component_id`) REFERENCES `hr_pay_components` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `hr_pay_items_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `hr_employees` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `hr_pay_items_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `hr_pay_items_no_update` BEFORE UPDATE ON `hr_pay_items` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a pay line cannot be changed' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `hr_pay_items_no_delete` BEFORE DELETE ON `hr_pay_items` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a pay line cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `hr_pay_profiles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `hr_pay_profiles` (
  `employee_id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `ptkp_status` varchar(3) NOT NULL,
  `has_npwp` tinyint(1) NOT NULL,
  `in_health` tinyint(1) NOT NULL,
  `in_employment` tinyint(1) NOT NULL,
  `updated_by` char(26) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`employee_id`),
  KEY `hr_pay_profiles_property_id_foreign` (`property_id`),
  CONSTRAINT `hr_pay_profiles_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `hr_employees` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `hr_pay_profiles_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_hr_pay_profiles` CHECK ((`ptkp_status` in (_utf8mb4'TK0',_utf8mb4'TK1',_utf8mb4'TK2',_utf8mb4'TK3',_utf8mb4'K0',_utf8mb4'K1',_utf8mb4'K2',_utf8mb4'K3')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `hr_payroll_adjustments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `hr_payroll_adjustments` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `employee_id` char(26) NOT NULL,
  `amount_minor` bigint NOT NULL,
  `taxable` tinyint(1) NOT NULL,
  `label` varchar(60) NOT NULL,
  `reason` varchar(200) NOT NULL,
  `source_run_id` char(26) DEFAULT NULL,
  `status` varchar(10) NOT NULL,
  `applied_run_id` char(26) DEFAULT NULL,
  `created_by` char(26) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `hr_payroll_adjustments_property_id_status_index` (`property_id`,`status`),
  KEY `hr_payroll_adjustments_employee_id_foreign` (`employee_id`),
  KEY `hr_payroll_adjustments_source_run_id_foreign` (`source_run_id`),
  KEY `hr_payroll_adjustments_applied_run_id_foreign` (`applied_run_id`),
  CONSTRAINT `hr_payroll_adjustments_applied_run_id_foreign` FOREIGN KEY (`applied_run_id`) REFERENCES `hr_payroll_runs` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `hr_payroll_adjustments_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `hr_employees` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `hr_payroll_adjustments_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `hr_payroll_adjustments_source_run_id_foreign` FOREIGN KEY (`source_run_id`) REFERENCES `hr_payroll_runs` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_hr_payroll_adjustments` CHECK (((`amount_minor` <> 0) and (`status` in (_utf8mb4'open',_utf8mb4'applied',_utf8mb4'cancelled'))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `hr_payroll_adjustments_no_delete` BEFORE DELETE ON `hr_payroll_adjustments` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'an adjustment cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `hr_payroll_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `hr_payroll_lines` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `run_id` char(26) NOT NULL,
  `employee_id` char(26) NOT NULL,
  `number` varchar(20) NOT NULL,
  `full_name` varchar(120) NOT NULL,
  `department` varchar(16) NOT NULL,
  `position` varchar(80) NOT NULL,
  `ptkp_status` varchar(3) NOT NULL,
  `scheduled_days` smallint unsigned NOT NULL,
  `present_days` smallint unsigned NOT NULL,
  `absent_days` smallint unsigned NOT NULL,
  `unpaid_leave_days` smallint unsigned NOT NULL,
  `late_minutes` int unsigned NOT NULL,
  `overtime_minutes` int unsigned NOT NULL,
  `gross_minor` bigint NOT NULL,
  `taxable_minor` bigint NOT NULL,
  `tax_minor` bigint NOT NULL,
  `employee_social_minor` bigint NOT NULL,
  `employer_social_minor` bigint NOT NULL,
  `other_deductions_minor` bigint NOT NULL,
  `net_minor` bigint NOT NULL,
  `items` json NOT NULL,
  `warnings` json NOT NULL,
  `created_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `hr_payroll_lines_run_id_employee_id_unique` (`run_id`,`employee_id`),
  KEY `hr_payroll_lines_property_id_foreign` (`property_id`),
  KEY `hr_payroll_lines_employee_id_foreign` (`employee_id`),
  CONSTRAINT `hr_payroll_lines_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `hr_employees` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `hr_payroll_lines_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `hr_payroll_lines_run_id_foreign` FOREIGN KEY (`run_id`) REFERENCES `hr_payroll_runs` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `hr_payroll_lines_frozen_insert` BEFORE INSERT ON `hr_payroll_lines` FOR EACH ROW BEGIN IF (SELECT status FROM hr_payroll_runs WHERE id = NEW.run_id) IN ('approved', 'paid', 'locked') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'the lines of an approved payroll run cannot be changed'; END IF; END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `hr_payroll_lines_frozen_update` BEFORE UPDATE ON `hr_payroll_lines` FOR EACH ROW BEGIN IF (SELECT status FROM hr_payroll_runs WHERE id = OLD.run_id) IN ('approved', 'paid', 'locked') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'the lines of an approved payroll run cannot be changed'; END IF; END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `hr_payroll_lines_frozen_delete` BEFORE DELETE ON `hr_payroll_lines` FOR EACH ROW BEGIN IF (SELECT status FROM hr_payroll_runs WHERE id = OLD.run_id) IN ('approved', 'paid', 'locked') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'the lines of an approved payroll run cannot be changed'; END IF; END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `hr_payroll_revisions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `hr_payroll_revisions` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `run_id` char(26) NOT NULL,
  `revision` smallint unsigned NOT NULL,
  `snapshot` json NOT NULL,
  `reason` varchar(200) NOT NULL,
  `created_by` char(26) NOT NULL,
  `created_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `hr_payroll_revisions_run_id_revision_unique` (`run_id`,`revision`),
  KEY `hr_payroll_revisions_property_id_foreign` (`property_id`),
  CONSTRAINT `hr_payroll_revisions_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `hr_payroll_revisions_run_id_foreign` FOREIGN KEY (`run_id`) REFERENCES `hr_payroll_runs` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `hr_payroll_revisions_no_update` BEFORE UPDATE ON `hr_payroll_revisions` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a revision cannot be changed' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `hr_payroll_revisions_no_delete` BEFORE DELETE ON `hr_payroll_revisions` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a revision cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `hr_payroll_runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `hr_payroll_runs` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `number` varchar(24) NOT NULL,
  `period` char(7) NOT NULL,
  `period_start` date NOT NULL,
  `period_end` date NOT NULL,
  `status` varchar(12) NOT NULL,
  `settings_snapshot` json DEFAULT NULL,
  `employees_count` int unsigned NOT NULL DEFAULT '0',
  `gross_minor` bigint NOT NULL DEFAULT '0',
  `deductions_minor` bigint NOT NULL DEFAULT '0',
  `net_minor` bigint NOT NULL DEFAULT '0',
  `tax_minor` bigint NOT NULL DEFAULT '0',
  `employee_social_minor` bigint NOT NULL DEFAULT '0',
  `employer_social_minor` bigint NOT NULL DEFAULT '0',
  `approval_id` char(26) DEFAULT NULL,
  `approval_by` char(26) DEFAULT NULL,
  `calculated_by` char(26) DEFAULT NULL,
  `calculated_at` timestamp(6) NULL DEFAULT NULL,
  `reviewed_by` char(26) DEFAULT NULL,
  `reviewed_at` timestamp(6) NULL DEFAULT NULL,
  `approved_at` timestamp(6) NULL DEFAULT NULL,
  `paid_at` timestamp(6) NULL DEFAULT NULL,
  `paid_reference` varchar(80) DEFAULT NULL,
  `locked_at` timestamp(6) NULL DEFAULT NULL,
  `revision` smallint unsigned NOT NULL DEFAULT '0',
  `created_by` char(26) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `hr_payroll_runs_property_id_number_unique` (`property_id`,`number`),
  UNIQUE KEY `hr_payroll_runs_property_id_period_unique` (`property_id`,`period`),
  CONSTRAINT `hr_payroll_runs_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_hr_payroll_runs` CHECK (((`status` in (_utf8mb4'draft',_utf8mb4'calculated',_utf8mb4'reviewed',_utf8mb4'approved',_utf8mb4'paid',_utf8mb4'locked')) and (`period_end` >= `period_start`) and ((`status` not in (_utf8mb4'approved',_utf8mb4'paid',_utf8mb4'locked')) or (`approved_at` is not null))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `hr_payroll_runs_no_delete` BEFORE DELETE ON `hr_payroll_runs` FOR EACH ROW BEGIN IF OLD.status <> 'draft' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a payroll run past the draft cannot be deleted'; END IF; END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `hr_payroll_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `hr_payroll_settings` (
  `property_id` char(26) NOT NULL,
  `health_employee_bp` smallint unsigned NOT NULL,
  `health_employer_bp` smallint unsigned NOT NULL,
  `health_cap_minor` bigint unsigned NOT NULL,
  `jht_employee_bp` smallint unsigned NOT NULL,
  `jht_employer_bp` smallint unsigned NOT NULL,
  `jp_employee_bp` smallint unsigned NOT NULL,
  `jp_employer_bp` smallint unsigned NOT NULL,
  `jp_cap_minor` bigint unsigned NOT NULL,
  `jkk_employer_bp` smallint unsigned NOT NULL,
  `jkm_employer_bp` smallint unsigned NOT NULL,
  `job_cost_bp` smallint unsigned NOT NULL,
  `job_cost_cap_year_minor` bigint unsigned NOT NULL,
  `no_npwp_surcharge_bp` smallint unsigned NOT NULL,
  `ptkp` json NOT NULL,
  `brackets` json NOT NULL,
  `overtime_divisor` smallint unsigned NOT NULL,
  `overtime_first_x100` smallint unsigned NOT NULL,
  `overtime_next_x100` smallint unsigned NOT NULL,
  `absence_divisor` smallint unsigned NOT NULL,
  `late_minute_deduction_minor` bigint unsigned NOT NULL,
  `updated_by` char(26) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`property_id`),
  CONSTRAINT `hr_payroll_settings_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_hr_payroll_settings` CHECK (((`health_employee_bp` <= 2000) and (`health_employer_bp` <= 2000) and (`jht_employee_bp` <= 2000) and (`jht_employer_bp` <= 2000) and (`jp_employee_bp` <= 2000) and (`jp_employer_bp` <= 2000) and (`jkk_employer_bp` <= 2000) and (`jkm_employer_bp` <= 2000) and (`job_cost_bp` <= 2000) and (`no_npwp_surcharge_bp` <= 10000) and (`overtime_divisor` between 1 and 744) and (`overtime_first_x100` between 100 and 1000) and (`overtime_next_x100` between 100 and 1000) and (`absence_divisor` between 1 and 31)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `hr_roster_entries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `hr_roster_entries` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `employee_id` char(26) NOT NULL,
  `work_date` date NOT NULL,
  `pattern_id` char(26) NOT NULL,
  `pattern_code` varchar(8) NOT NULL,
  `department` varchar(16) NOT NULL,
  `is_off` tinyint(1) NOT NULL,
  `starts_at` char(5) DEFAULT NULL,
  `ends_at` char(5) DEFAULT NULL,
  `starts2_at` char(5) DEFAULT NULL,
  `ends2_at` char(5) DEFAULT NULL,
  `minutes` smallint unsigned NOT NULL DEFAULT '0',
  `planned_by` char(26) NOT NULL,
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `hr_roster_entries_employee_id_work_date_unique` (`employee_id`,`work_date`),
  KEY `hr_roster_entries_property_id_work_date_department_index` (`property_id`,`work_date`,`department`),
  KEY `hr_roster_entries_pattern_id_foreign` (`pattern_id`),
  CONSTRAINT `hr_roster_entries_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `hr_employees` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `hr_roster_entries_pattern_id_foreign` FOREIGN KEY (`pattern_id`) REFERENCES `hr_shift_patterns` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `hr_roster_entries_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `hr_service_distributions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `hr_service_distributions` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `number` varchar(24) NOT NULL,
  `period` char(7) NOT NULL,
  `period_start` date NOT NULL,
  `period_end` date NOT NULL,
  `status` varchar(10) NOT NULL,
  `days_booked` int unsigned NOT NULL DEFAULT '0',
  `collected_minor` bigint NOT NULL DEFAULT '0',
  `pool_minor` bigint NOT NULL DEFAULT '0',
  `reserve_minor` bigint NOT NULL DEFAULT '0',
  `distributed_minor` bigint NOT NULL DEFAULT '0',
  `residue_minor` bigint NOT NULL DEFAULT '0',
  `staff_share_bp` smallint unsigned NOT NULL DEFAULT '0',
  `reserve_bp` smallint unsigned NOT NULL DEFAULT '0',
  `sources` json DEFAULT NULL,
  `approval_id` char(26) DEFAULT NULL,
  `approval_by` char(26) DEFAULT NULL,
  `approved_at` timestamp(6) NULL DEFAULT NULL,
  `calculated_by` char(26) DEFAULT NULL,
  `created_by` char(26) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `hr_service_distributions_property_id_number_unique` (`property_id`,`number`),
  UNIQUE KEY `hr_service_distributions_property_id_period_unique` (`property_id`,`period`),
  CONSTRAINT `hr_service_distributions_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_hr_service_distributions` CHECK (((`status` in (_utf8mb4'draft',_utf8mb4'approved')) and ((`status` <> _utf8mb4'approved') or (`approved_at` is not null))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `hr_service_distributions_no_delete` BEFORE DELETE ON `hr_service_distributions` FOR EACH ROW BEGIN IF OLD.status <> 'draft' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'an approved distribution cannot be deleted'; END IF; END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `hr_service_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `hr_service_lines` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `distribution_id` char(26) NOT NULL,
  `employee_id` char(26) NOT NULL,
  `number` varchar(20) NOT NULL,
  `full_name` varchar(120) NOT NULL,
  `position` varchar(80) NOT NULL,
  `points_x100` int unsigned NOT NULL,
  `scheduled_days` smallint unsigned NOT NULL,
  `present_days` smallint unsigned NOT NULL,
  `attendance_bp` smallint unsigned NOT NULL,
  `weight` bigint unsigned NOT NULL,
  `share_minor` bigint NOT NULL,
  `default_points` tinyint(1) NOT NULL,
  `created_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `hr_service_lines_distribution_id_employee_id_unique` (`distribution_id`,`employee_id`),
  KEY `hr_service_lines_property_id_foreign` (`property_id`),
  KEY `hr_service_lines_employee_id_foreign` (`employee_id`),
  CONSTRAINT `hr_service_lines_distribution_id_foreign` FOREIGN KEY (`distribution_id`) REFERENCES `hr_service_distributions` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `hr_service_lines_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `hr_employees` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `hr_service_lines_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `hr_service_lines_frozen_insert` BEFORE INSERT ON `hr_service_lines` FOR EACH ROW BEGIN IF (SELECT status FROM hr_service_distributions WHERE id = NEW.distribution_id) = 'approved' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'the shares of an approved distribution cannot be changed'; END IF; END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `hr_service_lines_frozen_update` BEFORE UPDATE ON `hr_service_lines` FOR EACH ROW BEGIN IF (SELECT status FROM hr_service_distributions WHERE id = OLD.distribution_id) = 'approved' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'the shares of an approved distribution cannot be changed'; END IF; END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `hr_service_lines_frozen_delete` BEFORE DELETE ON `hr_service_lines` FOR EACH ROW BEGIN IF (SELECT status FROM hr_service_distributions WHERE id = OLD.distribution_id) = 'approved' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'the shares of an approved distribution cannot be changed'; END IF; END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `hr_service_points`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `hr_service_points` (
  `property_id` char(26) NOT NULL,
  `position_key` varchar(80) NOT NULL,
  `position` varchar(80) NOT NULL,
  `points_x100` int unsigned NOT NULL,
  PRIMARY KEY (`property_id`,`position_key`),
  CONSTRAINT `hr_service_points_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_hr_service_points` CHECK ((`points_x100` between 1 and 10000))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `hr_service_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `hr_service_settings` (
  `property_id` char(26) NOT NULL,
  `staff_share_bp` smallint unsigned NOT NULL,
  `reserve_bp` smallint unsigned NOT NULL,
  `default_points_x100` smallint unsigned NOT NULL,
  `updated_by` char(26) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`property_id`),
  CONSTRAINT `hr_service_settings_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_hr_service_settings` CHECK (((`staff_share_bp` between 0 and 10000) and (`reserve_bp` between 0 and 10000) and (`default_points_x100` between 1 and 10000)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `hr_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `hr_settings` (
  `property_id` char(26) NOT NULL,
  `expiry_warn_days` smallint unsigned NOT NULL,
  `required_kinds` varchar(60) NOT NULL,
  `updated_by` char(26) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`property_id`),
  CONSTRAINT `hr_settings_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_hr_settings` CHECK ((`expiry_warn_days` between 1 and 365))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `hr_shift_patterns`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `hr_shift_patterns` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `code` varchar(8) NOT NULL,
  `name` varchar(40) NOT NULL,
  `is_off` tinyint(1) NOT NULL DEFAULT '0',
  `starts_at` char(5) DEFAULT NULL,
  `ends_at` char(5) DEFAULT NULL,
  `starts2_at` char(5) DEFAULT NULL,
  `ends2_at` char(5) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `hr_shift_patterns_property_id_code_unique` (`property_id`,`code`),
  CONSTRAINT `hr_shift_patterns_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_hr_shift_pattern` CHECK ((((`is_off` = 1) and (`starts_at` is null) and (`ends_at` is null) and (`starts2_at` is null) and (`ends2_at` is null)) or ((`is_off` = 0) and (`starts_at` is not null) and (`ends_at` is not null) and (`starts_at` <> `ends_at`) and (((`starts2_at` is null) and (`ends2_at` is null)) or ((`starts2_at` is not null) and (`ends2_at` is not null) and (`ends_at` > `starts_at`) and (`starts2_at` > `ends_at`) and (`ends2_at` > `starts2_at`))))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `hr_shift_swaps`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `hr_shift_swaps` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `requester_id` char(26) NOT NULL,
  `partner_id` char(26) NOT NULL,
  `work_date` date NOT NULL,
  `requester_pattern_id` char(26) NOT NULL,
  `requester_code` varchar(8) NOT NULL,
  `partner_pattern_id` char(26) NOT NULL,
  `partner_code` varchar(8) NOT NULL,
  `reason` varchar(200) NOT NULL,
  `status` varchar(20) NOT NULL,
  `partner_decided_by` char(26) DEFAULT NULL,
  `partner_decided_at` timestamp(6) NULL DEFAULT NULL,
  `decided_by` char(26) DEFAULT NULL,
  `decided_at` timestamp(6) NULL DEFAULT NULL,
  `decision_note` varchar(200) DEFAULT NULL,
  `requested_by` char(26) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `hr_shift_swaps_property_id_status_work_date_index` (`property_id`,`status`,`work_date`),
  KEY `hr_shift_swaps_requester_id_work_date_index` (`requester_id`,`work_date`),
  KEY `hr_shift_swaps_partner_id_work_date_index` (`partner_id`,`work_date`),
  CONSTRAINT `hr_shift_swaps_partner_id_foreign` FOREIGN KEY (`partner_id`) REFERENCES `hr_employees` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `hr_shift_swaps_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `hr_shift_swaps_requester_id_foreign` FOREIGN KEY (`requester_id`) REFERENCES `hr_employees` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_hr_shift_swaps` CHECK (((`requester_id` <> `partner_id`) and (`status` in (_utf8mb4'awaiting_partner',_utf8mb4'awaiting_supervisor',_utf8mb4'approved',_utf8mb4'rejected',_utf8mb4'declined',_utf8mb4'cancelled')) and ((`status` <> _utf8mb4'approved') or (`decided_at` is not null))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `hr_shift_swaps_no_delete` BEFORE DELETE ON `hr_shift_swaps` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a shift swap cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `hr_sop_credits`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `hr_sop_credits` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `source` varchar(16) NOT NULL,
  `run_id` char(26) NOT NULL,
  `item_key` varchar(60) NOT NULL,
  `user_id` char(26) NOT NULL,
  `weight` smallint unsigned NOT NULL,
  `credited_on` date NOT NULL,
  `occurred_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `hr_sop_credits_once` (`property_id`,`source`,`run_id`,`item_key`),
  KEY `hr_sop_credits_property_id_user_id_credited_on_index` (`property_id`,`user_id`,`credited_on`),
  CONSTRAINT `hr_sop_credits_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `hr_sop_credits_no_update` BEFORE UPDATE ON `hr_sop_credits` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a credit cannot be changed' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `hr_sop_credits_no_delete` BEFORE DELETE ON `hr_sop_credits` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a credit cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `hr_sop_runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `hr_sop_runs` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `source` varchar(16) NOT NULL,
  `run_id` char(26) NOT NULL,
  `checklist` varchar(80) NOT NULL,
  `frequency` varchar(8) NOT NULL,
  `period_key` varchar(10) NOT NULL,
  `period_start` date NOT NULL,
  `total` smallint unsigned NOT NULL,
  `completed` smallint unsigned NOT NULL,
  `percent` tinyint unsigned NOT NULL,
  `updated_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `hr_sop_runs_property_id_source_run_id_unique` (`property_id`,`source`,`run_id`),
  KEY `hr_sop_runs_property_id_period_start_index` (`property_id`,`period_start`),
  CONSTRAINT `hr_sop_runs_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `hr_staffing_minimums`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `hr_staffing_minimums` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `department` varchar(16) NOT NULL,
  `pattern_id` char(26) NOT NULL,
  `minimum` smallint unsigned NOT NULL,
  `updated_by` char(26) NOT NULL,
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `hr_staffing_minimums_property_id_department_pattern_id_unique` (`property_id`,`department`,`pattern_id`),
  KEY `hr_staffing_minimums_pattern_id_foreign` (`pattern_id`),
  CONSTRAINT `hr_staffing_minimums_pattern_id_foreign` FOREIGN KEY (`pattern_id`) REFERENCES `hr_shift_patterns` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `hr_staffing_minimums_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `idempotency_operations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `idempotency_operations` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `actor_id` char(26) DEFAULT NULL,
  `operation` varchar(120) NOT NULL,
  `key_hash` char(64) NOT NULL,
  `request_hash` char(64) NOT NULL,
  `result_payload` longtext,
  `result_version` smallint unsigned NOT NULL DEFAULT '1',
  `correlation_id` char(26) NOT NULL,
  `last_replay_correlation_id` char(26) DEFAULT NULL,
  `replay_count` int unsigned NOT NULL DEFAULT '0',
  `first_requested_at` timestamp(6) NOT NULL,
  `completed_at` timestamp(6) NULL DEFAULT NULL,
  `last_replayed_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idem_property_operation_key_unique` (`property_id`,`operation`,`key_hash`),
  KEY `idempotency_operations_property_id_first_requested_at_index` (`property_id`,`first_requested_at`),
  KEY `idempotency_operations_actor_id_first_requested_at_index` (`actor_id`,`first_requested_at`),
  KEY `idempotency_operations_correlation_id_index` (`correlation_id`),
  KEY `idempotency_operations_last_replay_correlation_id_index` (`last_replay_correlation_id`),
  KEY `idempotency_operations_property_id_completed_at_index` (`property_id`,`completed_at`),
  CONSTRAINT `idempotency_operations_actor_id_foreign` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `idempotency_operations_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_idempotency_completion` CHECK ((((`result_payload` is null) and (`completed_at` is null)) or ((`result_payload` is not null) and (`completed_at` is not null))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `import_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `import_batches` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `kind` varchar(40) NOT NULL,
  `checksum` char(64) NOT NULL,
  `status` varchar(10) NOT NULL,
  `error_count` int unsigned NOT NULL DEFAULT '0',
  `report` json NOT NULL,
  `actor_id` char(26) NOT NULL,
  `created_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `import_batches_actor_id_foreign` (`actor_id`),
  KEY `import_batches_property_id_kind_checksum_status_index` (`property_id`,`kind`,`checksum`,`status`),
  CONSTRAINT `import_batches_actor_id_foreign` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `import_batches_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_import_batches_status` CHECK ((`status` in (_utf8mb4'validated',_utf8mb4'rejected',_utf8mb4'applied')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `import_batches_no_update` BEFORE UPDATE ON `import_batches` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'an import batch record cannot be changed' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `import_batches_no_delete` BEFORE DELETE ON `import_batches` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'an import batch record cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `inspection_findings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `inspection_findings` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `inspection_id` char(26) NOT NULL,
  `room_id` char(26) NOT NULL,
  `description` varchar(300) NOT NULL,
  `mandatory` tinyint(1) NOT NULL DEFAULT '1',
  `status` varchar(8) NOT NULL DEFAULT 'open',
  `closed_by` char(26) DEFAULT NULL,
  `closed_at` timestamp(6) NULL DEFAULT NULL,
  `waive_reason` varchar(300) DEFAULT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `inspection_findings_inspection_id_foreign` (`inspection_id`),
  KEY `inspection_findings_room_id_foreign` (`room_id`),
  KEY `inspection_findings_closed_by_foreign` (`closed_by`),
  KEY `inspection_findings_property_id_room_id_status_index` (`property_id`,`room_id`,`status`),
  CONSTRAINT `inspection_findings_closed_by_foreign` FOREIGN KEY (`closed_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `inspection_findings_inspection_id_foreign` FOREIGN KEY (`inspection_id`) REFERENCES `room_inspections` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `inspection_findings_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `inspection_findings_room_id_foreign` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_findings_closed` CHECK ((((`status` = _utf8mb4'open') and (`closed_by` is null) and (`closed_at` is null) and (`waive_reason` is null)) or ((`status` = _utf8mb4'resolved') and (`closed_by` is not null) and (`closed_at` is not null) and (`waive_reason` is null)) or ((`status` = _utf8mb4'waived') and (`closed_by` is not null) and (`closed_at` is not null) and (`waive_reason` is not null)))),
  CONSTRAINT `chk_findings_status` CHECK ((`status` in (_utf8mb4'open',_utf8mb4'resolved',_utf8mb4'waived')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `inspection_findings_closed_once` BEFORE UPDATE ON `inspection_findings` FOR EACH ROW BEGIN
    IF NOT (NEW.id <=> OLD.id AND NEW.property_id <=> OLD.property_id AND NEW.inspection_id <=> OLD.inspection_id AND NEW.room_id <=> OLD.room_id
        AND NEW.description <=> OLD.description AND NEW.mandatory <=> OLD.mandatory) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'what an inspection found cannot be changed';
    END IF;
    IF OLD.status <> 'open' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a closed finding cannot be reopened or changed';
    END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `inspection_findings_no_delete` BEFORE DELETE ON `inspection_findings` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a finding cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `integration_circuits`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `integration_circuits` (
  `property_id` char(26) NOT NULL,
  `provider` varchar(40) NOT NULL,
  `consecutive_failures` int unsigned NOT NULL DEFAULT '0',
  `opened_at` timestamp(6) NULL DEFAULT NULL,
  `trial_started_at` timestamp(6) NULL DEFAULT NULL,
  `version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`property_id`,`provider`),
  CONSTRAINT `integration_circuits_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `integration_unknowns`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `integration_unknowns` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `provider` varchar(40) NOT NULL,
  `operation` varchar(40) NOT NULL,
  `idempotency_key` varchar(128) NOT NULL,
  `correlation_id` char(26) NOT NULL,
  `status` varchar(20) NOT NULL,
  `resolved_by` char(26) DEFAULT NULL,
  `resolved_at` timestamp(6) NULL DEFAULT NULL,
  `resolution_reason` varchar(500) DEFAULT NULL,
  `created_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `integration_unknowns_key_unique` (`property_id`,`provider`,`idempotency_key`),
  KEY `integration_unknowns_resolved_by_foreign` (`resolved_by`),
  KEY `integration_unknowns_property_id_status_created_at_index` (`property_id`,`status`,`created_at`),
  CONSTRAINT `integration_unknowns_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `integration_unknowns_resolved_by_foreign` FOREIGN KEY (`resolved_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_integration_unknown_status` CHECK ((((`status` = _utf8mb4'open') and (`resolved_at` is null) and (`resolved_by` is null) and (`resolution_reason` is null)) or ((`status` in (_utf8mb4'resolved_succeeded',_utf8mb4'resolved_failed')) and (`resolved_at` is not null) and (`resolved_by` is not null) and (`resolution_reason` is not null))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `integration_unknowns_no_delete` BEFORE DELETE ON `integration_unknowns` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'integration_unknowns cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `inventory_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `inventory_categories` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `code` varchar(12) NOT NULL,
  `name` varchar(80) NOT NULL,
  `negative_blocked` tinyint(1) NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `inventory_categories_property_id_code_unique` (`property_id`,`code`),
  CONSTRAINT `inventory_categories_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `inventory_holds`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `inventory_holds` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `room_type_id` char(26) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `rooms` smallint unsigned NOT NULL,
  `reason` varchar(500) NOT NULL,
  `expires_at` timestamp(6) NULL DEFAULT NULL,
  `created_by` char(26) NOT NULL,
  `created_at` timestamp(6) NOT NULL,
  `released_at` timestamp(6) NULL DEFAULT NULL,
  `released_by` char(26) DEFAULT NULL,
  `release_reason` varchar(500) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `inventory_holds_room_type_id_foreign` (`room_type_id`),
  KEY `inventory_holds_created_by_foreign` (`created_by`),
  KEY `inventory_holds_released_by_foreign` (`released_by`),
  KEY `inventory_holds_lookup` (`property_id`,`room_type_id`,`released_at`,`start_date`),
  CONSTRAINT `inventory_holds_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `inventory_holds_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `inventory_holds_released_by_foreign` FOREIGN KEY (`released_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `inventory_holds_room_type_id_foreign` FOREIGN KEY (`room_type_id`) REFERENCES `room_types` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_inventory_holds_release` CHECK ((((`released_at` is null) and (`released_by` is null) and (`release_reason` is null)) or ((`released_at` is not null) and (`released_by` is not null) and (`release_reason` is not null)))),
  CONSTRAINT `chk_inventory_holds_shape` CHECK (((`end_date` >= `start_date`) and (`rooms` >= 1)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `inventory_holds_release_only` BEFORE UPDATE ON `inventory_holds` FOR EACH ROW BEGIN
    IF NOT (OLD.released_at IS NULL AND NEW.released_at IS NOT NULL AND NEW.id <=> OLD.id AND NEW.property_id <=> OLD.property_id AND NEW.room_type_id <=> OLD.room_type_id AND NEW.start_date <=> OLD.start_date AND NEW.end_date <=> OLD.end_date AND NEW.rooms <=> OLD.rooms AND NEW.reason <=> OLD.reason AND NEW.expires_at <=> OLD.expires_at AND NEW.created_by <=> OLD.created_by AND NEW.created_at <=> OLD.created_at) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'inventory_holds rows can only be released, once';
    END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `inventory_holds_no_delete` BEFORE DELETE ON `inventory_holds` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'inventory_holds cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `inventory_item_units`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `inventory_item_units` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `item_id` char(26) NOT NULL,
  `unit` varchar(8) NOT NULL,
  `version` int unsigned NOT NULL,
  `factor_milli` bigint unsigned NOT NULL,
  `reason` varchar(200) NOT NULL,
  `created_by` char(26) NOT NULL,
  `created_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `inventory_item_units_item_id_unit_version_unique` (`item_id`,`unit`,`version`),
  KEY `inventory_item_units_property_id_foreign` (`property_id`),
  KEY `inventory_item_units_created_by_foreign` (`created_by`),
  CONSTRAINT `inventory_item_units_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `inventory_item_units_item_id_foreign` FOREIGN KEY (`item_id`) REFERENCES `inventory_items` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `inventory_item_units_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_inventory_unit` CHECK ((regexp_like(`unit`,_utf8mb4'^[A-Z0-9]{1,8}$') and (`factor_milli` between 1 and 1000000000) and (`version` >= 1)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `inventory_item_units_no_update` BEFORE UPDATE ON `inventory_item_units` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a unit conversion version cannot be changed' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `inventory_item_units_no_delete` BEFORE DELETE ON `inventory_item_units` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a unit conversion version cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `inventory_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `inventory_items` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `code` varchar(20) NOT NULL,
  `name` varchar(120) NOT NULL,
  `category_id` char(26) NOT NULL,
  `department` varchar(16) NOT NULL,
  `base_unit` varchar(8) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `inventory_items_property_id_code_unique` (`property_id`,`code`),
  KEY `inventory_items_category_id_index` (`category_id`),
  CONSTRAINT `inventory_items_category_id_foreign` FOREIGN KEY (`category_id`) REFERENCES `inventory_categories` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `inventory_items_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_inventory_item_unit` CHECK (regexp_like(`base_unit`,_utf8mb4'^[A-Z0-9]{1,8}$'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `inventory_items_base_unit_fixed` BEFORE UPDATE ON `inventory_items` FOR EACH ROW BEGIN IF NEW.base_unit <> OLD.base_unit OR NEW.code <> OLD.code THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'the code and base unit of an item cannot be changed'; END IF; END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `inventory_items_no_delete` BEFORE DELETE ON `inventory_items` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'an inventory item cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `inventory_locations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `inventory_locations` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `code` varchar(12) NOT NULL,
  `name` varchar(80) NOT NULL,
  `kind` varchar(12) NOT NULL,
  `negative_blocked` tinyint(1) NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `inventory_locations_property_id_code_unique` (`property_id`,`code`),
  CONSTRAINT `inventory_locations_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_inventory_location_kind` CHECK ((`kind` in (_utf8mb4'main',_utf8mb4'bar',_utf8mb4'kitchen',_utf8mb4'housekeeping',_utf8mb4'engineering',_utf8mb4'galley',_utf8mb4'other')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `inventory_lots`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `inventory_lots` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `item_id` char(26) NOT NULL,
  `location_id` char(26) NOT NULL,
  `movement_id` char(26) NOT NULL,
  `lot_number` varchar(40) DEFAULT NULL,
  `expires_on` date DEFAULT NULL,
  `received_milli` bigint unsigned NOT NULL,
  `remaining_milli` bigint unsigned NOT NULL,
  `created_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `inventory_lots_item_id_foreign` (`item_id`),
  KEY `inventory_lots_location_id_foreign` (`location_id`),
  KEY `inventory_lots_fefo` (`property_id`,`item_id`,`location_id`,`expires_on`),
  KEY `inventory_lots_property_id_expires_on_index` (`property_id`,`expires_on`),
  CONSTRAINT `inventory_lots_item_id_foreign` FOREIGN KEY (`item_id`) REFERENCES `inventory_items` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `inventory_lots_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `inventory_locations` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `inventory_lots_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_inventory_lots` CHECK (((`remaining_milli` <= `received_milli`) and (`received_milli` > 0) and ((`lot_number` is not null) or (`expires_on` is not null))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `inventory_policies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `inventory_policies` (
  `property_id` char(26) NOT NULL,
  `room_type_id` char(26) NOT NULL,
  `overbooking_allowance_rooms` tinyint unsigned NOT NULL DEFAULT '0',
  `updated_by` char(26) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`property_id`,`room_type_id`),
  KEY `inventory_policies_room_type_id_foreign` (`room_type_id`),
  KEY `inventory_policies_updated_by_foreign` (`updated_by`),
  CONSTRAINT `inventory_policies_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `inventory_policies_room_type_id_foreign` FOREIGN KEY (`room_type_id`) REFERENCES `room_types` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `inventory_policies_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `inventory_requisition_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `inventory_requisition_lines` (
  `id` char(26) NOT NULL,
  `requisition_id` char(26) NOT NULL,
  `item_id` char(26) NOT NULL,
  `unit` varchar(8) NOT NULL,
  `quantity_milli` bigint unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `inventory_requisition_lines_requisition_id_item_id_unique` (`requisition_id`,`item_id`),
  KEY `inventory_requisition_lines_item_id_foreign` (`item_id`),
  CONSTRAINT `inventory_requisition_lines_item_id_foreign` FOREIGN KEY (`item_id`) REFERENCES `inventory_items` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `inventory_requisition_lines_requisition_id_foreign` FOREIGN KEY (`requisition_id`) REFERENCES `inventory_requisitions` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_inventory_requisition_lines` CHECK ((`quantity_milli` > 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `inventory_requisition_lines_no_update` BEFORE UPDATE ON `inventory_requisition_lines` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a requisition line cannot change' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `inventory_requisition_lines_no_delete` BEFORE DELETE ON `inventory_requisition_lines` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a requisition line cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `inventory_requisitions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `inventory_requisitions` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `number` varchar(20) NOT NULL,
  `requesting_location_id` char(26) NOT NULL,
  `supplying_location_id` char(26) NOT NULL,
  `status` varchar(12) NOT NULL,
  `note` varchar(200) DEFAULT NULL,
  `requested_by` char(26) NOT NULL,
  `decided_by` char(26) DEFAULT NULL,
  `decided_at` timestamp(6) NULL DEFAULT NULL,
  `decision_note` varchar(200) DEFAULT NULL,
  `transfer_id` char(26) DEFAULT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `inventory_requisitions_property_id_number_unique` (`property_id`,`number`),
  KEY `inventory_requisitions_requesting_location_id_foreign` (`requesting_location_id`),
  KEY `inventory_requisitions_supplying_location_id_foreign` (`supplying_location_id`),
  KEY `inventory_requisitions_property_id_status_index` (`property_id`,`status`),
  CONSTRAINT `inventory_requisitions_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `inventory_requisitions_requesting_location_id_foreign` FOREIGN KEY (`requesting_location_id`) REFERENCES `inventory_locations` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `inventory_requisitions_supplying_location_id_foreign` FOREIGN KEY (`supplying_location_id`) REFERENCES `inventory_locations` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_inventory_requisitions` CHECK (((`status` in (_utf8mb4'requested',_utf8mb4'fulfilled',_utf8mb4'rejected',_utf8mb4'cancelled')) and (`requesting_location_id` <> `supplying_location_id`)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `inventory_requisitions_no_delete` BEFORE DELETE ON `inventory_requisitions` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a requisition cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `inventory_stock_limits`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `inventory_stock_limits` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `item_id` char(26) NOT NULL,
  `location_id` char(26) NOT NULL,
  `min_milli` bigint unsigned NOT NULL,
  `max_milli` bigint unsigned DEFAULT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `inventory_stock_limits_item_id_location_id_unique` (`item_id`,`location_id`),
  KEY `inventory_stock_limits_property_id_foreign` (`property_id`),
  KEY `inventory_stock_limits_location_id_foreign` (`location_id`),
  CONSTRAINT `inventory_stock_limits_item_id_foreign` FOREIGN KEY (`item_id`) REFERENCES `inventory_items` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `inventory_stock_limits_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `inventory_locations` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `inventory_stock_limits_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_inventory_limits` CHECK (((`max_milli` is null) or (`max_milli` >= `min_milli`)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `job_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `job_batches` (
  `id` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `total_jobs` int NOT NULL,
  `pending_jobs` int NOT NULL,
  `failed_jobs` int NOT NULL,
  `failed_job_ids` longtext NOT NULL,
  `options` mediumtext,
  `cancelled_at` int DEFAULT NULL,
  `created_at` int NOT NULL,
  `finished_at` int DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `queue` varchar(255) NOT NULL,
  `payload` longtext NOT NULL,
  `attempts` smallint unsigned NOT NULL,
  `reserved_at` int unsigned DEFAULT NULL,
  `available_at` int unsigned NOT NULL,
  `created_at` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `jobs_queue_index` (`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `kitchen_consumptions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `kitchen_consumptions` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `bill_id` char(26) NOT NULL,
  `bill_number` varchar(20) NOT NULL,
  `line_id` char(26) NOT NULL,
  `menu_item_id` char(26) NOT NULL,
  `item_name` varchar(80) NOT NULL,
  `portions` smallint unsigned NOT NULL,
  `version_id` char(26) NOT NULL,
  `version` smallint unsigned NOT NULL,
  `business_date` date NOT NULL,
  `ingredient_item_id` char(26) NOT NULL,
  `ingredient_name` varchar(80) NOT NULL,
  `unit` varchar(12) NOT NULL,
  `quantity_milli` bigint unsigned NOT NULL,
  `created_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `kitchen_consumption_once` (`line_id`,`ingredient_item_id`),
  KEY `kitchen_consumptions_property_id_business_date_index` (`property_id`,`business_date`),
  KEY `kitchen_consumptions_version_id_foreign` (`version_id`),
  CONSTRAINT `kitchen_consumptions_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `kitchen_consumptions_version_id_foreign` FOREIGN KEY (`version_id`) REFERENCES `kitchen_recipe_versions` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `kitchen_consumptions_no_update` BEFORE UPDATE ON `kitchen_consumptions` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a consumption cannot be changed' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `kitchen_consumptions_no_delete` BEFORE DELETE ON `kitchen_consumptions` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a consumption cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `kitchen_prep_formula_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `kitchen_prep_formula_lines` (
  `id` char(26) NOT NULL,
  `formula_id` char(26) NOT NULL,
  `ingredient_item_id` char(26) NOT NULL,
  `ingredient_code` varchar(20) NOT NULL,
  `ingredient_name` varchar(80) NOT NULL,
  `unit` varchar(12) NOT NULL,
  `quantity_milli` bigint unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `kitchen_prep_formula_lines_formula_id_ingredient_item_id_unique` (`formula_id`,`ingredient_item_id`),
  CONSTRAINT `kitchen_prep_formula_lines_formula_id_foreign` FOREIGN KEY (`formula_id`) REFERENCES `kitchen_prep_formulas` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_prep_formula_line` CHECK ((`quantity_milli` > 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `kitchen_prep_formula_lines_no_update` BEFORE UPDATE ON `kitchen_prep_formula_lines` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a formula cannot be changed' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `kitchen_prep_formula_lines_no_delete` BEFORE DELETE ON `kitchen_prep_formula_lines` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a formula cannot be changed' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `kitchen_prep_formulas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `kitchen_prep_formulas` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `code` varchar(20) NOT NULL,
  `name` varchar(80) NOT NULL,
  `output_item_id` char(26) NOT NULL,
  `output_name` varchar(80) NOT NULL,
  `output_unit` varchar(12) NOT NULL,
  `standard_output_milli` bigint unsigned NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_by` char(26) NOT NULL,
  `retired_by` char(26) DEFAULT NULL,
  `retired_at` timestamp(6) NULL DEFAULT NULL,
  `retire_reason` varchar(200) DEFAULT NULL,
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `kitchen_prep_formulas_property_id_code_unique` (`property_id`,`code`),
  CONSTRAINT `kitchen_prep_formulas_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_prep_formula` CHECK ((`standard_output_milli` > 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `kitchen_prep_formulas_retire_only` BEFORE UPDATE ON `kitchen_prep_formulas` FOR EACH ROW BEGIN
                IF NOT (OLD.code <=> NEW.code AND OLD.name <=> NEW.name AND OLD.output_item_id <=> NEW.output_item_id AND OLD.output_unit <=> NEW.output_unit AND OLD.standard_output_milli <=> NEW.standard_output_milli AND OLD.created_by <=> NEW.created_by)
                    OR (OLD.is_active = 0 AND NEW.is_active = 1) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a formula can only be retired';
                END IF;
            END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `kitchen_prep_formulas_no_delete` BEFORE DELETE ON `kitchen_prep_formulas` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a formula cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `kitchen_production_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `kitchen_production_lines` (
  `id` char(26) NOT NULL,
  `production_id` char(26) NOT NULL,
  `ingredient_item_id` char(26) NOT NULL,
  `ingredient_name` varchar(80) NOT NULL,
  `unit` varchar(12) NOT NULL,
  `quantity_milli` bigint unsigned NOT NULL,
  `value_minor` bigint DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `kitchen_production_lines_production_id_ingredient_item_id_unique` (`production_id`,`ingredient_item_id`),
  CONSTRAINT `kitchen_production_lines_production_id_foreign` FOREIGN KEY (`production_id`) REFERENCES `kitchen_productions` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `kitchen_production_lines_no_update` BEFORE UPDATE ON `kitchen_production_lines` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a production batch cannot be changed' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `kitchen_production_lines_no_delete` BEFORE DELETE ON `kitchen_production_lines` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a production batch cannot be changed' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `kitchen_productions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `kitchen_productions` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `number` varchar(20) NOT NULL,
  `formula_id` char(26) NOT NULL,
  `formula_code` varchar(20) NOT NULL,
  `formula_name` varchar(80) NOT NULL,
  `batches` smallint unsigned NOT NULL,
  `output_item_id` char(26) NOT NULL,
  `output_name` varchar(80) NOT NULL,
  `output_unit` varchar(12) NOT NULL,
  `standard_output_milli` bigint unsigned NOT NULL,
  `actual_output_milli` bigint unsigned NOT NULL,
  `yield_bp` smallint unsigned NOT NULL,
  `input_value_minor` bigint NOT NULL,
  `input_value_complete` tinyint(1) NOT NULL,
  `expires_on` date DEFAULT NULL,
  `lot_number` varchar(40) DEFAULT NULL,
  `note` varchar(200) DEFAULT NULL,
  `business_date` date NOT NULL,
  `recorded_by` char(26) NOT NULL,
  `created_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `kitchen_productions_property_id_number_unique` (`property_id`,`number`),
  KEY `kitchen_productions_property_id_business_date_index` (`property_id`,`business_date`),
  KEY `kitchen_productions_formula_id_foreign` (`formula_id`),
  CONSTRAINT `kitchen_productions_formula_id_foreign` FOREIGN KEY (`formula_id`) REFERENCES `kitchen_prep_formulas` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `kitchen_productions_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_production` CHECK (((`batches` between 1 and 100) and (`actual_output_milli` > 0) and (`standard_output_milli` > 0)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `kitchen_productions_no_update` BEFORE UPDATE ON `kitchen_productions` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a production batch cannot be changed' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `kitchen_productions_no_delete` BEFORE DELETE ON `kitchen_productions` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a production batch cannot be changed' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `kitchen_recipe_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `kitchen_recipe_lines` (
  `id` char(26) NOT NULL,
  `version_id` char(26) NOT NULL,
  `ingredient_item_id` char(26) NOT NULL,
  `ingredient_code` varchar(20) NOT NULL,
  `ingredient_name` varchar(80) NOT NULL,
  `unit` varchar(12) NOT NULL,
  `quantity_milli` int unsigned NOT NULL,
  `waste_bp` smallint unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `kitchen_recipe_lines_version_id_ingredient_item_id_unique` (`version_id`,`ingredient_item_id`),
  CONSTRAINT `kitchen_recipe_lines_version_id_foreign` FOREIGN KEY (`version_id`) REFERENCES `kitchen_recipe_versions` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_recipe_line` CHECK (((`quantity_milli` > 0) and (`waste_bp` <= 5000)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `kitchen_recipe_lines_no_update` BEFORE UPDATE ON `kitchen_recipe_lines` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a recipe version cannot be changed' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `kitchen_recipe_lines_no_delete` BEFORE DELETE ON `kitchen_recipe_lines` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a recipe version cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `kitchen_recipe_versions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `kitchen_recipe_versions` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `recipe_id` char(26) NOT NULL,
  `version` smallint unsigned NOT NULL,
  `effective_from` date NOT NULL,
  `yield_portions` smallint unsigned NOT NULL,
  `reason` varchar(200) NOT NULL,
  `created_by` char(26) NOT NULL,
  `created_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `kitchen_recipe_versions_recipe_id_version_unique` (`recipe_id`,`version`),
  UNIQUE KEY `kitchen_recipe_versions_recipe_id_effective_from_unique` (`recipe_id`,`effective_from`),
  KEY `kitchen_recipe_versions_property_id_foreign` (`property_id`),
  CONSTRAINT `kitchen_recipe_versions_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `kitchen_recipe_versions_recipe_id_foreign` FOREIGN KEY (`recipe_id`) REFERENCES `kitchen_recipes` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_recipe_yield` CHECK ((`yield_portions` between 1 and 1000))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `kitchen_recipe_versions_no_update` BEFORE UPDATE ON `kitchen_recipe_versions` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a recipe version cannot be changed' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `kitchen_recipe_versions_no_delete` BEFORE DELETE ON `kitchen_recipe_versions` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a recipe version cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `kitchen_recipes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `kitchen_recipes` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `menu_item_id` char(26) NOT NULL,
  `item_code` varchar(20) NOT NULL,
  `item_name` varchar(80) NOT NULL,
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `kitchen_recipes_property_id_menu_item_id_unique` (`property_id`,`menu_item_id`),
  CONSTRAINT `kitchen_recipes_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `kitchen_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `kitchen_settings` (
  `property_id` char(26) NOT NULL,
  `late_after_minutes` smallint unsigned NOT NULL,
  `stock_location_id` char(26) DEFAULT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `updated_by` char(26) NOT NULL,
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`property_id`),
  CONSTRAINT `kitchen_settings_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_kitchen_late` CHECK ((`late_after_minutes` between 1 and 240))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `kitchen_ticket_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `kitchen_ticket_lines` (
  `id` char(26) NOT NULL,
  `ticket_id` char(26) NOT NULL,
  `line_id` char(26) NOT NULL,
  `name` varchar(80) NOT NULL,
  `variant` varchar(40) DEFAULT NULL,
  `modifiers` json NOT NULL,
  `quantity` smallint unsigned NOT NULL,
  `note` varchar(120) DEFAULT NULL,
  `cancelled` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `kitchen_ticket_lines_line_id_unique` (`line_id`),
  KEY `kitchen_ticket_lines_ticket_id_index` (`ticket_id`),
  CONSTRAINT `kitchen_ticket_lines_ticket_id_foreign` FOREIGN KEY (`ticket_id`) REFERENCES `kitchen_tickets` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `kitchen_tickets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `kitchen_tickets` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `outlet_id` char(26) NOT NULL,
  `outlet_code` varchar(12) NOT NULL,
  `station` varchar(8) NOT NULL,
  `source` varchar(5) NOT NULL DEFAULT 'staff',
  `batch_id` char(26) NOT NULL,
  `batch_number` smallint unsigned NOT NULL,
  `bill_id` char(26) NOT NULL,
  `bill_number` varchar(20) NOT NULL,
  `place_kind` varchar(8) NOT NULL,
  `place` varchar(40) DEFAULT NULL,
  `business_date` date NOT NULL,
  `status` varchar(10) NOT NULL DEFAULT 'new',
  `received_at` timestamp(6) NOT NULL,
  `started_at` timestamp(6) NULL DEFAULT NULL,
  `ready_at` timestamp(6) NULL DEFAULT NULL,
  `served_at` timestamp(6) NULL DEFAULT NULL,
  `started_by` char(26) DEFAULT NULL,
  `ready_by` char(26) DEFAULT NULL,
  `served_by` char(26) DEFAULT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `kitchen_tickets_once` (`property_id`,`batch_id`,`station`),
  KEY `kitchen_tickets_property_id_station_status_received_at_index` (`property_id`,`station`,`status`,`received_at`),
  CONSTRAINT `kitchen_tickets_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_kitchen_ticket_status` CHECK ((`status` in (_utf8mb4'new',_utf8mb4'preparing',_utf8mb4'ready',_utf8mb4'served',_utf8mb4'cancelled')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `kitchen_waste`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `kitchen_waste` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `number` varchar(20) NOT NULL,
  `kind` varchar(10) NOT NULL,
  `menu_item_id` char(26) DEFAULT NULL,
  `menu_item_name` varchar(80) DEFAULT NULL,
  `portions` smallint unsigned DEFAULT NULL,
  `recipe_version_id` char(26) DEFAULT NULL,
  `reason` varchar(12) NOT NULL,
  `reference` varchar(40) DEFAULT NULL,
  `note` varchar(200) DEFAULT NULL,
  `business_date` date NOT NULL,
  `value_minor` bigint DEFAULT NULL,
  `value_complete` tinyint(1) NOT NULL DEFAULT '1',
  `recorded_by` char(26) NOT NULL,
  `created_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `kitchen_waste_property_id_number_unique` (`property_id`,`number`),
  KEY `kitchen_waste_property_id_business_date_index` (`property_id`,`business_date`),
  CONSTRAINT `kitchen_waste_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_kitchen_waste` CHECK (((`kind` in (_utf8mb4'ingredient',_utf8mb4'dish')) and (`reason` in (_utf8mb4'spoiled',_utf8mb4'expired',_utf8mb4'damaged',_utf8mb4'overcooked',_utf8mb4'returned',_utf8mb4'wrong_order',_utf8mb4'other'))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `kitchen_waste_no_update` BEFORE UPDATE ON `kitchen_waste` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a waste entry cannot be changed' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `kitchen_waste_no_delete` BEFORE DELETE ON `kitchen_waste` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a waste entry cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `kitchen_waste_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `kitchen_waste_lines` (
  `id` char(26) NOT NULL,
  `waste_id` char(26) NOT NULL,
  `ingredient_item_id` char(26) NOT NULL,
  `ingredient_name` varchar(80) NOT NULL,
  `unit` varchar(12) NOT NULL,
  `quantity_milli` bigint unsigned NOT NULL,
  `value_minor` bigint DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `kitchen_waste_lines_waste_id_ingredient_item_id_unique` (`waste_id`,`ingredient_item_id`),
  CONSTRAINT `kitchen_waste_lines_waste_id_foreign` FOREIGN KEY (`waste_id`) REFERENCES `kitchen_waste` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `kitchen_waste_lines_no_update` BEFORE UPDATE ON `kitchen_waste_lines` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a waste entry cannot be changed' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `kitchen_waste_lines_no_delete` BEFORE DELETE ON `kitchen_waste_lines` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a waste entry cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `laundry_claim_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `laundry_claim_settings` (
  `property_id` char(26) NOT NULL,
  `cap_multiple` smallint unsigned NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `updated_by` char(26) DEFAULT NULL,
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`property_id`),
  KEY `laundry_claim_settings_updated_by_foreign` (`updated_by`),
  CONSTRAINT `laundry_claim_settings_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `laundry_claim_settings_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `laundry_claims`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `laundry_claims` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `number` varchar(20) NOT NULL,
  `order_id` char(26) NOT NULL,
  `line_id` char(26) DEFAULT NULL,
  `pieces` smallint unsigned NOT NULL DEFAULT '1',
  `kind` varchar(6) NOT NULL,
  `description` varchar(300) NOT NULL,
  `claimed_minor` bigint NOT NULL,
  `currency_code` char(3) NOT NULL,
  `photo_file_id` char(26) DEFAULT NULL,
  `status` varchar(8) NOT NULL DEFAULT 'open',
  `approved_minor` bigint DEFAULT NULL,
  `decision_note` varchar(300) DEFAULT NULL,
  `recorded_by` char(26) NOT NULL,
  `decided_by` char(26) DEFAULT NULL,
  `decided_at` timestamp(6) NULL DEFAULT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `laundry_claims_property_id_number_unique` (`property_id`,`number`),
  KEY `laundry_claims_recorded_by_foreign` (`recorded_by`),
  KEY `laundry_claims_decided_by_foreign` (`decided_by`),
  KEY `laundry_claims_order_id_index` (`order_id`),
  CONSTRAINT `laundry_claims_decided_by_foreign` FOREIGN KEY (`decided_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `laundry_claims_order_id_foreign` FOREIGN KEY (`order_id`) REFERENCES `laundry_orders` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `laundry_claims_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `laundry_claims_recorded_by_foreign` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_laundry_claim` CHECK (((`kind` in (_utf8mb4'damage',_utf8mb4'loss')) and (`claimed_minor` > 0) and (`pieces` >= 1) and (((`status` = _utf8mb4'open') and (`approved_minor` is null) and (`decided_by` is null) and (`decided_at` is null)) or ((`status` = _utf8mb4'approved') and (`approved_minor` is not null) and (`approved_minor` > 0) and (`approved_minor` <= `claimed_minor`) and (`decided_by` is not null) and (`decided_at` is not null)) or ((`status` = _utf8mb4'rejected') and (`approved_minor` is null) and (`decision_note` is not null) and (`decided_by` is not null) and (`decided_at` is not null)))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `laundry_claims_decide_once` BEFORE UPDATE ON `laundry_claims` FOR EACH ROW BEGIN
    IF OLD.status <> 'open' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a decided laundry claim cannot be changed'; END IF;
    IF NEW.order_id <> OLD.order_id OR NEW.kind <> OLD.kind OR NEW.description <> OLD.description OR NEW.claimed_minor <> OLD.claimed_minor OR NEW.recorded_by <> OLD.recorded_by
        OR NEW.number <> OLD.number OR NEW.pieces <> OLD.pieces OR NOT (NEW.line_id <=> OLD.line_id) OR NOT (NEW.photo_file_id <=> OLD.photo_file_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'the facts of a laundry claim cannot be changed';
    END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `laundry_claims_no_delete` BEFORE DELETE ON `laundry_claims` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a laundry claim cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `laundry_order_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `laundry_order_lines` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `order_id` char(26) NOT NULL,
  `price_item_id` char(26) NOT NULL,
  `item_name` varchar(80) NOT NULL,
  `brand` varchar(60) DEFAULT NULL,
  `quantity` smallint unsigned NOT NULL,
  `unit_price_minor` bigint NOT NULL,
  `condition_note` varchar(200) DEFAULT NULL,
  `treatment_name` varchar(80) DEFAULT NULL,
  `treatment_extra_minor` bigint NOT NULL DEFAULT '0',
  `express_extra_minor` bigint NOT NULL DEFAULT '0',
  `verified_quantity` smallint unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `laundry_order_lines_property_id_foreign` (`property_id`),
  KEY `laundry_order_lines_order_id_index` (`order_id`),
  CONSTRAINT `laundry_order_lines_order_id_foreign` FOREIGN KEY (`order_id`) REFERENCES `laundry_orders` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `laundry_order_lines_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_laundry_line_extras` CHECK (((`treatment_extra_minor` >= 0) and (`express_extra_minor` >= 0))),
  CONSTRAINT `chk_laundry_line_qty` CHECK (((`quantity` >= 1) and (`unit_price_minor` >= 0)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `laundry_order_lines_facts` BEFORE UPDATE ON `laundry_order_lines` FOR EACH ROW BEGIN
    IF NOT (NEW.id <=> OLD.id AND NEW.order_id <=> OLD.order_id AND NEW.price_item_id <=> OLD.price_item_id AND NEW.item_name <=> OLD.item_name AND NEW.brand <=> OLD.brand
        AND NEW.quantity <=> OLD.quantity AND NEW.unit_price_minor <=> OLD.unit_price_minor AND NEW.condition_note <=> OLD.condition_note
        AND NEW.treatment_name <=> OLD.treatment_name AND NEW.treatment_extra_minor <=> OLD.treatment_extra_minor AND NEW.express_extra_minor <=> OLD.express_extra_minor) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'what was handed over cannot be changed';
    END IF;
    IF OLD.verified_quantity IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a verified quantity cannot be changed';
    END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `laundry_order_lines_no_delete` BEFORE DELETE ON `laundry_order_lines` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'laundry order lines cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `laundry_orders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `laundry_orders` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `number` varchar(20) NOT NULL,
  `barcode` varchar(40) NOT NULL,
  `room_id` char(26) NOT NULL,
  `guest_name` varchar(120) DEFAULT NULL,
  `stay_id` char(26) NOT NULL,
  `reservation_id` char(26) NOT NULL,
  `status` varchar(10) NOT NULL,
  `express` tinyint(1) NOT NULL DEFAULT '0',
  `is_foc` tinyint(1) NOT NULL DEFAULT '0',
  `foc_reason` varchar(300) DEFAULT NULL,
  `foc_by` char(26) DEFAULT NULL,
  `waive_service_charge` tinyint(1) NOT NULL DEFAULT '0',
  `waive_tax` tinyint(1) NOT NULL DEFAULT '0',
  `waive_reason` varchar(300) DEFAULT NULL,
  `pickup_date` date NOT NULL,
  `promised_at` timestamp(6) NOT NULL,
  `escalated_at` timestamp(6) NULL DEFAULT NULL,
  `notes` varchar(500) DEFAULT NULL,
  `has_discrepancy` tinyint(1) NOT NULL DEFAULT '0',
  `discrepancy_note` varchar(500) DEFAULT NULL,
  `charged_minor` bigint DEFAULT NULL,
  `currency_code` varchar(3) DEFAULT NULL,
  `created_by` char(26) NOT NULL,
  `processed_by` char(26) DEFAULT NULL,
  `ready_at` timestamp(6) NULL DEFAULT NULL,
  `delivered_by` char(26) DEFAULT NULL,
  `delivered_at` timestamp(6) NULL DEFAULT NULL,
  `receipt_note` varchar(200) DEFAULT NULL,
  `cancel_reason` varchar(300) DEFAULT NULL,
  `settlement` varchar(12) DEFAULT NULL,
  `settlement_approval_id` char(26) DEFAULT NULL,
  `settlement_reason` varchar(300) DEFAULT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  `active_barcode_key` varchar(80) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `laundry_orders_property_id_number_unique` (`property_id`,`number`),
  UNIQUE KEY `laundry_one_active_per_bag` (`active_barcode_key`),
  KEY `laundry_orders_room_id_foreign` (`room_id`),
  KEY `laundry_orders_created_by_foreign` (`created_by`),
  KEY `laundry_orders_processed_by_foreign` (`processed_by`),
  KEY `laundry_orders_delivered_by_foreign` (`delivered_by`),
  KEY `laundry_orders_property_id_status_promised_at_index` (`property_id`,`status`,`promised_at`),
  KEY `laundry_orders_property_id_stay_id_index` (`property_id`,`stay_id`),
  CONSTRAINT `laundry_orders_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `laundry_orders_delivered_by_foreign` FOREIGN KEY (`delivered_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `laundry_orders_processed_by_foreign` FOREIGN KEY (`processed_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `laundry_orders_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `laundry_orders_room_id_foreign` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_laundry_cancelled` CHECK (((`status` <> _utf8mb4'cancelled') or (`cancel_reason` is not null))),
  CONSTRAINT `chk_laundry_claimed` CHECK (((`status` = _utf8mb4'claimed') = coalesce((`settlement` = _utf8mb4'claim'),0))),
  CONSTRAINT `chk_laundry_delivered` CHECK (((`status` <> _utf8mb4'delivered') or ((`delivered_at` is not null) and (`delivered_by` is not null) and (`receipt_note` is not null)))),
  CONSTRAINT `chk_laundry_discrepancy` CHECK (((`has_discrepancy` = 0) or (`discrepancy_note` is not null))),
  CONSTRAINT `chk_laundry_foc` CHECK (((`is_foc` = 0) or ((`foc_reason` is not null) and (`foc_by` is not null)))),
  CONSTRAINT `chk_laundry_ready` CHECK (((`status` not in (_utf8mb4'ready',_utf8mb4'delivered')) or ((`ready_at` is not null) and (`charged_minor` is not null)))),
  CONSTRAINT `chk_laundry_settlement` CHECK (((`settlement` is null) or ((`settlement` in (_utf8mb4'late_charge',_utf8mb4'claim')) and (`settlement_approval_id` is not null) and (`settlement_reason` is not null)))),
  CONSTRAINT `chk_laundry_status` CHECK ((`status` in (_utf8mb4'sent',_utf8mb4'received',_utf8mb4'washing',_utf8mb4'drying',_utf8mb4'ironing',_utf8mb4'ready',_utf8mb4'delivered',_utf8mb4'cancelled',_utf8mb4'claimed'))),
  CONSTRAINT `chk_laundry_waiver` CHECK ((((`waive_service_charge` = 0) and (`waive_tax` = 0)) or (`waive_reason` is not null)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `laundry_orders_active_key_insert` BEFORE INSERT ON `laundry_orders` FOR EACH ROW BEGIN
    SET NEW.active_barcode_key = CASE WHEN NEW.status IN ('delivered', 'cancelled', 'claimed') THEN NULL ELSE CONCAT(NEW.property_id, '|', NEW.barcode) END;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `laundry_orders_facts` BEFORE UPDATE ON `laundry_orders` FOR EACH ROW BEGIN
    IF NOT (NEW.id <=> OLD.id AND NEW.property_id <=> OLD.property_id AND NEW.number <=> OLD.number AND NEW.barcode <=> OLD.barcode AND NEW.room_id <=> OLD.room_id
        AND NEW.stay_id <=> OLD.stay_id AND NEW.reservation_id <=> OLD.reservation_id AND NEW.pickup_date <=> OLD.pickup_date AND NEW.created_by <=> OLD.created_by) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'the origin of a laundry order cannot be changed';
    END IF;
    IF OLD.status IN ('delivered', 'cancelled', 'claimed') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a finished laundry order cannot be changed';
    END IF;
    IF OLD.settlement IS NOT NULL AND NOT (NEW.settlement <=> OLD.settlement AND NEW.settlement_approval_id <=> OLD.settlement_approval_id AND NEW.settlement_reason <=> OLD.settlement_reason) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'how a laundry order was settled at check-out cannot be changed';
    END IF;
    SET NEW.active_barcode_key = CASE WHEN NEW.status IN ('delivered', 'cancelled', 'claimed') THEN NULL ELSE CONCAT(NEW.property_id, '|', NEW.barcode) END;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `laundry_orders_no_delete` BEFORE DELETE ON `laundry_orders` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'laundry orders cannot be deleted; cancel them' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `laundry_price_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `laundry_price_items` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `code` varchar(20) NOT NULL,
  `name` varchar(80) NOT NULL,
  `unit_price_minor` bigint NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `laundry_price_items_property_id_code_unique` (`property_id`,`code`),
  CONSTRAINT `laundry_price_items_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_laundry_price_nonneg` CHECK ((`unit_price_minor` >= 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `laundry_price_items_no_delete` BEFORE DELETE ON `laundry_price_items` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'laundry price items cannot be deleted; deactivate them' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `laundry_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `laundry_settings` (
  `property_id` char(26) NOT NULL,
  `standard_hours` smallint unsigned NOT NULL,
  `express_hours` smallint unsigned NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `updated_by` char(26) DEFAULT NULL,
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`property_id`),
  KEY `laundry_settings_updated_by_foreign` (`updated_by`),
  CONSTRAINT `laundry_settings_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `laundry_settings_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `laundry_status_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `laundry_status_log` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `order_id` char(26) NOT NULL,
  `from_status` varchar(10) DEFAULT NULL,
  `to_status` varchar(10) NOT NULL,
  `actor_id` char(26) NOT NULL,
  `occurred_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `laundry_status_log_property_id_foreign` (`property_id`),
  KEY `laundry_status_log_actor_id_foreign` (`actor_id`),
  KEY `laundry_status_log_order_id_occurred_at_index` (`order_id`,`occurred_at`),
  CONSTRAINT `laundry_status_log_actor_id_foreign` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `laundry_status_log_order_id_foreign` FOREIGN KEY (`order_id`) REFERENCES `laundry_orders` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `laundry_status_log_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `laundry_status_log_no_update` BEFORE UPDATE ON `laundry_status_log` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'laundry_status_log is append-only' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `laundry_status_log_no_delete` BEFORE DELETE ON `laundry_status_log` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'laundry_status_log is append-only' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `laundry_treatments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `laundry_treatments` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `code` varchar(20) NOT NULL,
  `name` varchar(80) NOT NULL,
  `kind` varchar(7) NOT NULL,
  `pricing` varchar(7) NOT NULL,
  `value` bigint NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  `active_express_key` char(26) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `laundry_treatments_property_id_code_unique` (`property_id`,`code`),
  UNIQUE KEY `laundry_one_active_express` (`active_express_key`),
  CONSTRAINT `laundry_treatments_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_treatment_kind` CHECK ((`kind` in (_utf8mb4'service',_utf8mb4'express'))),
  CONSTRAINT `chk_treatment_pricing` CHECK ((`pricing` in (_utf8mb4'percent',_utf8mb4'fixed'))),
  CONSTRAINT `chk_treatment_value` CHECK (((`value` >= 0) and ((`pricing` <> _utf8mb4'percent') or (`value` <= 100000))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `laundry_treatments_active_key_insert` BEFORE INSERT ON `laundry_treatments` FOR EACH ROW BEGIN
    SET NEW.active_express_key = CASE WHEN NEW.kind = 'express' AND NEW.is_active = 1 THEN NEW.property_id ELSE NULL END;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `laundry_treatments_active_key_update` BEFORE UPDATE ON `laundry_treatments` FOR EACH ROW BEGIN
    SET NEW.active_express_key = CASE WHEN NEW.kind = 'express' AND NEW.is_active = 1 THEN NEW.property_id ELSE NULL END;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `laundry_treatments_no_delete` BEFORE DELETE ON `laundry_treatments` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'laundry treatments cannot be deleted; deactivate them' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `legal_holds`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `legal_holds` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `scope_type` varchar(20) NOT NULL,
  `purpose` varchar(80) DEFAULT NULL,
  `owner_type` varchar(80) DEFAULT NULL,
  `owner_id` char(26) DEFAULT NULL,
  `reason` varchar(500) NOT NULL,
  `placed_by` char(26) NOT NULL,
  `placed_at` timestamp(6) NOT NULL,
  `released_by` char(26) DEFAULT NULL,
  `released_at` timestamp(6) NULL DEFAULT NULL,
  `release_reason` varchar(500) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `legal_holds_placed_by_foreign` (`placed_by`),
  KEY `legal_holds_released_by_foreign` (`released_by`),
  KEY `legal_holds_property_id_released_at_index` (`property_id`,`released_at`),
  CONSTRAINT `legal_holds_placed_by_foreign` FOREIGN KEY (`placed_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `legal_holds_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `legal_holds_released_by_foreign` FOREIGN KEY (`released_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_legal_holds_release` CHECK ((((`released_at` is null) and (`released_by` is null) and (`release_reason` is null)) or ((`released_at` is not null) and (`released_by` is not null) and (`release_reason` is not null)))),
  CONSTRAINT `chk_legal_holds_scope` CHECK ((((`scope_type` = _utf8mb4'property') and (`purpose` is null) and (`owner_type` is null) and (`owner_id` is null)) or ((`scope_type` = _utf8mb4'purpose') and (`purpose` is not null) and (`owner_type` is null) and (`owner_id` is null)) or ((`scope_type` = _utf8mb4'owner') and (`purpose` is null) and (`owner_type` is not null) and (`owner_id` is not null))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `legal_holds_release_only` BEFORE UPDATE ON `legal_holds` FOR EACH ROW BEGIN
    IF NOT (OLD.released_at IS NULL AND NEW.released_at IS NOT NULL
        AND NEW.id <=> OLD.id AND NEW.property_id <=> OLD.property_id AND NEW.scope_type <=> OLD.scope_type
        AND NEW.purpose <=> OLD.purpose AND NEW.owner_type <=> OLD.owner_type AND NEW.owner_id <=> OLD.owner_id
        AND NEW.reason <=> OLD.reason AND NEW.placed_by <=> OLD.placed_by AND NEW.placed_at <=> OLD.placed_at) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a legal hold can only be released, once';
    END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `legal_holds_no_delete` BEFORE DELETE ON `legal_holds` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'legal_holds cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `linen_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `linen_items` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `code` varchar(20) NOT NULL,
  `name` varchar(80) NOT NULL,
  `kind` varchar(8) NOT NULL,
  `unit` varchar(12) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `linen_items_property_id_code_unique` (`property_id`,`code`),
  CONSTRAINT `linen_items_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_linen_kind` CHECK ((`kind` in (_utf8mb4'linen',_utf8mb4'amenity')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `linen_items_no_delete` BEFORE DELETE ON `linen_items` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a linen item cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `linen_par_levels`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `linen_par_levels` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `item_id` char(26) NOT NULL,
  `scope_kind` varchar(9) NOT NULL,
  `room_type_id` char(26) DEFAULT NULL,
  `area` varchar(40) DEFAULT NULL,
  `scope_key` varchar(46) NOT NULL,
  `par_quantity` int unsigned NOT NULL,
  `use_quantity` int unsigned NOT NULL DEFAULT '0',
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `updated_by` char(26) NOT NULL,
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `linen_par_scope_unique` (`property_id`,`item_id`,`scope_kind`,`scope_key`),
  KEY `linen_par_levels_item_id_foreign` (`item_id`),
  KEY `linen_par_levels_updated_by_foreign` (`updated_by`),
  CONSTRAINT `linen_par_levels_item_id_foreign` FOREIGN KEY (`item_id`) REFERENCES `linen_items` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `linen_par_levels_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `linen_par_levels_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_par_scope` CHECK ((((`scope_kind` = _utf8mb4'room_type') and (`room_type_id` is not null) and (`area` is null)) or ((`scope_kind` = _utf8mb4'area') and (`room_type_id` is null) and (`area` is not null) and (`use_quantity` = 0))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `linen_par_levels_scope_insert` BEFORE INSERT ON `linen_par_levels` FOR EACH ROW BEGIN
    SET NEW.scope_key = COALESCE(NEW.room_type_id, CONCAT('area:', NEW.area));
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `linen_par_levels_scope_update` BEFORE UPDATE ON `linen_par_levels` FOR EACH ROW BEGIN
    SET NEW.scope_key = COALESCE(NEW.room_type_id, CONCAT('area:', NEW.area));
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `linen_transfers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `linen_transfers` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `number` varchar(30) NOT NULL,
  `item_id` char(26) NOT NULL,
  `from_location` varchar(10) NOT NULL,
  `to_location` varchar(10) NOT NULL,
  `quantity_sent` int unsigned NOT NULL,
  `status` varchar(10) NOT NULL,
  `note` varchar(200) DEFAULT NULL,
  `client_key` varchar(80) DEFAULT NULL,
  `sent_by` char(26) NOT NULL,
  `sent_at` timestamp(6) NOT NULL,
  `quantity_received` int unsigned DEFAULT NULL,
  `variance_kind` varchar(8) DEFAULT NULL,
  `variance_note` varchar(200) DEFAULT NULL,
  `received_by` char(26) DEFAULT NULL,
  `received_at` timestamp(6) NULL DEFAULT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `updated_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `linen_transfers_property_id_number_unique` (`property_id`,`number`),
  UNIQUE KEY `linen_transfers_property_id_client_key_unique` (`property_id`,`client_key`),
  KEY `linen_transfers_sent_by_foreign` (`sent_by`),
  KEY `linen_transfers_received_by_foreign` (`received_by`),
  KEY `linen_transfers_property_id_status_index` (`property_id`,`status`),
  KEY `linen_transfers_item_id_status_index` (`item_id`,`status`),
  CONSTRAINT `linen_transfers_item_id_foreign` FOREIGN KEY (`item_id`) REFERENCES `linen_items` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `linen_transfers_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `linen_transfers_received_by_foreign` FOREIGN KEY (`received_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `linen_transfers_sent_by_foreign` FOREIGN KEY (`sent_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_transfer_locations` CHECK (((`from_location` in (_utf8mb4'external',_utf8mb4'store',_utf8mb4'floor',_utf8mb4'laundry')) and (`to_location` in (_utf8mb4'store',_utf8mb4'floor',_utf8mb4'laundry',_utf8mb4'discard')) and (`from_location` <> `to_location`))),
  CONSTRAINT `chk_transfer_quantity` CHECK ((`quantity_sent` > 0)),
  CONSTRAINT `chk_transfer_received` CHECK ((((`status` = _utf8mb4'received') and (`quantity_received` is not null) and (`quantity_received` <= `quantity_sent`) and (`received_at` is not null) and (`received_by` is not null)) or ((`status` <> _utf8mb4'received') and (`quantity_received` is null) and (`received_at` is null)))),
  CONSTRAINT `chk_transfer_status` CHECK ((`status` in (_utf8mb4'pending',_utf8mb4'received',_utf8mb4'cancelled'))),
  CONSTRAINT `chk_transfer_variance` CHECK ((((`status` = _utf8mb4'received') and (`quantity_received` < `quantity_sent`) and (`variance_kind` in (_utf8mb4'loss',_utf8mb4'damage')) and (`variance_note` is not null)) or (((`status` <> _utf8mb4'received') or (`quantity_received` >= `quantity_sent`)) and (`variance_kind` is null))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `linen_transfers_facts_immutable` BEFORE UPDATE ON `linen_transfers` FOR EACH ROW BEGIN
    IF NOT (NEW.id <=> OLD.id AND NEW.property_id <=> OLD.property_id AND NEW.number <=> OLD.number AND NEW.item_id <=> OLD.item_id AND NEW.from_location <=> OLD.from_location
        AND NEW.to_location <=> OLD.to_location AND NEW.quantity_sent <=> OLD.quantity_sent AND NEW.sent_by <=> OLD.sent_by AND NEW.sent_at <=> OLD.sent_at AND NEW.client_key <=> OLD.client_key) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'what was sent cannot be changed';
    END IF;
    IF OLD.status <> 'pending' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a confirmed or cancelled transfer cannot be changed';
    END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `linen_transfers_no_delete` BEFORE DELETE ON `linen_transfers` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a linen transfer cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `linen_usage`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `linen_usage` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `room_id` char(26) NOT NULL,
  `item_id` char(26) NOT NULL,
  `quantity` int unsigned NOT NULL,
  `usage_date` date NOT NULL,
  `note` varchar(200) DEFAULT NULL,
  `recorded_by` char(26) NOT NULL,
  `recorded_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `linen_usage_item_id_foreign` (`item_id`),
  KEY `linen_usage_recorded_by_foreign` (`recorded_by`),
  KEY `linen_usage_property_id_usage_date_index` (`property_id`,`usage_date`),
  KEY `linen_usage_room_id_usage_date_index` (`room_id`,`usage_date`),
  CONSTRAINT `linen_usage_item_id_foreign` FOREIGN KEY (`item_id`) REFERENCES `linen_items` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `linen_usage_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `linen_usage_recorded_by_foreign` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `linen_usage_room_id_foreign` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_usage_quantity` CHECK ((`quantity` > 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `linen_usage_no_update` BEFORE UPDATE ON `linen_usage` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a usage record cannot be changed' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `linen_usage_no_delete` BEFORE DELETE ON `linen_usage` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a usage record cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `lost_found_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `lost_found_items` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `number` varchar(20) NOT NULL,
  `description` varchar(200) NOT NULL,
  `room_id` char(26) DEFAULT NULL,
  `stay_id` char(26) DEFAULT NULL,
  `place` varchar(80) DEFAULT NULL,
  `found_business_date` date NOT NULL,
  `found_by` char(26) NOT NULL,
  `found_at` timestamp(6) NOT NULL,
  `photo_file_id` char(26) DEFAULT NULL,
  `stored_at` varchar(80) NOT NULL,
  `status` varchar(8) NOT NULL,
  `closed_note` varchar(300) DEFAULT NULL,
  `returned_to` varchar(100) DEFAULT NULL,
  `closed_by` char(26) DEFAULT NULL,
  `closed_at` timestamp(6) NULL DEFAULT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `lost_found_items_property_id_number_unique` (`property_id`,`number`),
  KEY `lost_found_items_room_id_foreign` (`room_id`),
  KEY `lost_found_items_found_by_foreign` (`found_by`),
  KEY `lost_found_items_closed_by_foreign` (`closed_by`),
  KEY `lost_found_items_property_id_status_found_business_date_index` (`property_id`,`status`,`found_business_date`),
  KEY `lost_found_items_property_id_stay_id_index` (`property_id`,`stay_id`),
  CONSTRAINT `lost_found_items_closed_by_foreign` FOREIGN KEY (`closed_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `lost_found_items_found_by_foreign` FOREIGN KEY (`found_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `lost_found_items_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `lost_found_items_room_id_foreign` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_lost_found_closed` CHECK ((((`status` = _utf8mb4'stored') and (`closed_at` is null) and (`closed_by` is null) and (`returned_to` is null) and (`closed_note` is null)) or ((`status` = _utf8mb4'returned') and (`closed_at` is not null) and (`closed_by` is not null) and (`returned_to` is not null)) or ((`status` = _utf8mb4'disposed') and (`closed_at` is not null) and (`closed_by` is not null) and (`closed_note` is not null) and (`returned_to` is null)))),
  CONSTRAINT `chk_lost_found_status` CHECK ((`status` in (_utf8mb4'stored',_utf8mb4'returned',_utf8mb4'disposed'))),
  CONSTRAINT `chk_lost_found_where` CHECK (((`room_id` is not null) or (`place` is not null)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `lost_found_facts` BEFORE UPDATE ON `lost_found_items` FOR EACH ROW BEGIN
    IF NOT (NEW.id <=> OLD.id AND NEW.property_id <=> OLD.property_id AND NEW.number <=> OLD.number AND NEW.description <=> OLD.description AND NEW.room_id <=> OLD.room_id
        AND NEW.place <=> OLD.place AND NEW.found_business_date <=> OLD.found_business_date AND NEW.found_by <=> OLD.found_by AND NEW.found_at <=> OLD.found_at AND NEW.photo_file_id <=> OLD.photo_file_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'what was found cannot be changed';
    END IF;
    IF OLD.status <> 'stored' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a returned or disposed item cannot be changed';
    END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `lost_found_no_delete` BEFORE DELETE ON `lost_found_items` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a lost and found item cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `loyalty_accounts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `loyalty_accounts` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `guest_key` char(64) NOT NULL,
  `guest_name` varchar(120) NOT NULL,
  `phone_last4` char(4) DEFAULT NULL,
  `balance_points` int unsigned NOT NULL DEFAULT '0',
  `earned_points` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `loyalty_accounts_guest` (`property_id`,`guest_key`),
  KEY `loyalty_accounts_property_id_balance_points_index` (`property_id`,`balance_points`),
  CONSTRAINT `loyalty_accounts_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `loyalty_entries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `loyalty_entries` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `account_id` char(26) NOT NULL,
  `kind` varchar(8) NOT NULL,
  `points` int NOT NULL,
  `balance_after` int unsigned NOT NULL,
  `source_type` varchar(12) NOT NULL,
  `source_ref` char(26) NOT NULL,
  `note` varchar(200) DEFAULT NULL,
  `business_date` date NOT NULL,
  `actor_id` char(26) DEFAULT NULL,
  `created_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `loyalty_entries_once` (`property_id`,`kind`,`source_type`,`source_ref`),
  KEY `loyalty_entries_account_id_created_at_index` (`account_id`,`created_at`),
  CONSTRAINT `loyalty_entries_account_id_foreign` FOREIGN KEY (`account_id`) REFERENCES `loyalty_accounts` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `loyalty_entries_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_loyalty_entries` CHECK (((`kind` in (_utf8mb4'earn',_utf8mb4'redeem',_utf8mb4'adjust',_utf8mb4'expire')) and (`points` <> 0)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `loyalty_entries_no_update` BEFORE UPDATE ON `loyalty_entries` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a loyalty entry cannot be changed' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `loyalty_entries_no_delete` BEFORE DELETE ON `loyalty_entries` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a loyalty entry cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `maintenance_assets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `maintenance_assets` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `number` varchar(20) NOT NULL,
  `name` varchar(80) NOT NULL,
  `category` varchar(14) NOT NULL,
  `serial` varchar(40) DEFAULT NULL,
  `room_id` char(26) DEFAULT NULL,
  `room_number` varchar(12) DEFAULT NULL,
  `area` varchar(80) DEFAULT NULL,
  `acquired_on` date NOT NULL,
  `warranty_until` date DEFAULT NULL,
  `meter_unit` varchar(8) DEFAULT NULL,
  `notes` varchar(300) DEFAULT NULL,
  `status` varchar(8) NOT NULL DEFAULT 'active',
  `retired_on` date DEFAULT NULL,
  `retired_reason` varchar(200) DEFAULT NULL,
  `created_by` char(26) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `maintenance_assets_property_id_number_unique` (`property_id`,`number`),
  KEY `maintenance_assets_property_id_status_index` (`property_id`,`status`),
  CONSTRAINT `maintenance_assets_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_mtc_asset` CHECK (((`category` in (_utf8mb4'machine',_utf8mb4'equipment',_utf8mb4'vehicle',_utf8mb4'building',_utf8mb4'it',_utf8mb4'other')) and (`status` in (_utf8mb4'active',_utf8mb4'retired')) and ((`meter_unit` is null) or (`meter_unit` in (_utf8mb4'hours',_utf8mb4'km',_utf8mb4'cycles'))) and ((`warranty_until` is null) or (`warranty_until` >= `acquired_on`)) and ((`status` = _utf8mb4'active') or (`retired_reason` is not null))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `maintenance_duties`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `maintenance_duties` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `title` varchar(80) NOT NULL,
  `frequency` varchar(8) NOT NULL,
  `shift` varchar(5) NOT NULL DEFAULT 'any',
  `weekday` tinyint unsigned DEFAULT NULL,
  `month_day` tinyint unsigned DEFAULT NULL,
  `asset_id` char(26) DEFAULT NULL,
  `area` varchar(80) DEFAULT NULL,
  `category` varchar(12) NOT NULL DEFAULT 'other',
  `starts_on` date NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_by` char(26) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `maintenance_duties_property_id_is_active_index` (`property_id`,`is_active`),
  KEY `maintenance_duties_asset_id_foreign` (`asset_id`),
  CONSTRAINT `maintenance_duties_asset_id_foreign` FOREIGN KEY (`asset_id`) REFERENCES `maintenance_assets` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `maintenance_duties_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_mtc_duty` CHECK (((`frequency` in (_utf8mb4'daily',_utf8mb4'weekly',_utf8mb4'monthly')) and (`shift` in (_utf8mb4'any',_utf8mb4'day',_utf8mb4'night')) and ((`frequency` <> _utf8mb4'weekly') or (`weekday` between 1 and 7)) and ((`frequency` <> _utf8mb4'monthly') or (`month_day` between 1 and 28)) and ((`asset_id` is not null) or (`area` is not null))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `maintenance_duty_run_steps`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `maintenance_duty_run_steps` (
  `id` char(26) NOT NULL,
  `run_id` char(26) NOT NULL,
  `position` smallint unsigned NOT NULL,
  `text` varchar(200) NOT NULL,
  `result` varchar(7) NOT NULL DEFAULT 'pending',
  `note` varchar(200) DEFAULT NULL,
  `work_order_id` char(26) DEFAULT NULL,
  `checked_by` char(26) DEFAULT NULL,
  `checked_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `maintenance_duty_run_steps_run_id_position_unique` (`run_id`,`position`),
  CONSTRAINT `maintenance_duty_run_steps_run_id_foreign` FOREIGN KEY (`run_id`) REFERENCES `maintenance_duty_runs` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_mtc_duty_run_step` CHECK (((`result` in (_utf8mb4'pending',_utf8mb4'ok',_utf8mb4'issue',_utf8mb4'na')) and ((`result` = _utf8mb4'pending') or (`checked_by` is not null))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `maintenance_duty_run_steps_closed` BEFORE UPDATE ON `maintenance_duty_run_steps` FOR EACH ROW BEGIN
                IF (SELECT status FROM maintenance_duty_runs WHERE id = OLD.run_id) <> 'open' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'the steps of a closed duty cannot be changed';
                END IF;
            END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `maintenance_duty_runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `maintenance_duty_runs` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `duty_id` char(26) NOT NULL,
  `title` varchar(80) NOT NULL,
  `shift` varchar(5) NOT NULL,
  `asset_id` char(26) DEFAULT NULL,
  `area` varchar(80) DEFAULT NULL,
  `category` varchar(12) NOT NULL,
  `due_on` date NOT NULL,
  `status` varchar(6) NOT NULL DEFAULT 'open',
  `done_by` char(26) DEFAULT NULL,
  `done_at` timestamp(6) NULL DEFAULT NULL,
  `note` varchar(300) DEFAULT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `maintenance_duty_runs_duty_id_due_on_unique` (`duty_id`,`due_on`),
  KEY `maintenance_duty_runs_property_id_status_due_on_index` (`property_id`,`status`,`due_on`),
  CONSTRAINT `maintenance_duty_runs_duty_id_foreign` FOREIGN KEY (`duty_id`) REFERENCES `maintenance_duties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `maintenance_duty_runs_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_mtc_duty_run` CHECK (((`status` in (_utf8mb4'open',_utf8mb4'done',_utf8mb4'missed')) and ((`status` <> _utf8mb4'done') or ((`done_by` is not null) and (`done_at` is not null)))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `maintenance_duty_runs_closed` BEFORE UPDATE ON `maintenance_duty_runs` FOR EACH ROW BEGIN
                IF OLD.status <> 'open' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a closed duty cannot be changed';
                END IF;
            END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `maintenance_duty_steps`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `maintenance_duty_steps` (
  `id` char(26) NOT NULL,
  `duty_id` char(26) NOT NULL,
  `position` smallint unsigned NOT NULL,
  `text` varchar(200) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `maintenance_duty_steps_duty_id_position_unique` (`duty_id`,`position`),
  CONSTRAINT `maintenance_duty_steps_duty_id_foreign` FOREIGN KEY (`duty_id`) REFERENCES `maintenance_duties` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `maintenance_escalations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `maintenance_escalations` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `work_order_id` char(26) NOT NULL,
  `level` tinyint unsigned NOT NULL,
  `target` varchar(10) NOT NULL,
  `shift` varchar(5) NOT NULL,
  `raised_at` timestamp(6) NOT NULL,
  `acknowledged_by` char(26) DEFAULT NULL,
  `acknowledged_at` timestamp(6) NULL DEFAULT NULL,
  `note` varchar(200) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `maintenance_escalations_work_order_id_level_unique` (`work_order_id`,`level`),
  KEY `maintenance_escalations_property_id_acknowledged_at_index` (`property_id`,`acknowledged_at`),
  CONSTRAINT `maintenance_escalations_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `maintenance_escalations_work_order_id_foreign` FOREIGN KEY (`work_order_id`) REFERENCES `maintenance_work_orders` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_mtc_escalation` CHECK (((`level` in (1,2)) and (`target` in (_utf8mb4'supervisor',_utf8mb4'mod')) and (`shift` in (_utf8mb4'day',_utf8mb4'night'))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `maintenance_meter_readings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `maintenance_meter_readings` (
  `id` char(26) NOT NULL,
  `asset_id` char(26) NOT NULL,
  `reading` bigint unsigned NOT NULL,
  `read_on` date NOT NULL,
  `read_by` char(26) NOT NULL,
  `created_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `maintenance_meter_readings_asset_id_reading_index` (`asset_id`,`reading`),
  CONSTRAINT `maintenance_meter_readings_asset_id_foreign` FOREIGN KEY (`asset_id`) REFERENCES `maintenance_assets` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `maintenance_meter_readings_no_update` BEFORE UPDATE ON `maintenance_meter_readings` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a meter reading cannot be changed' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `maintenance_meter_readings_no_delete` BEFORE DELETE ON `maintenance_meter_readings` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a meter reading cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `maintenance_part_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `maintenance_part_requests` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `work_order_id` char(26) NOT NULL,
  `purchase_request_id` char(26) NOT NULL,
  `purchase_request_number` varchar(20) NOT NULL,
  `requested_by` char(26) NOT NULL,
  `created_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `maintenance_part_requests_purchase_request_id_unique` (`purchase_request_id`),
  KEY `maintenance_part_requests_property_id_foreign` (`property_id`),
  KEY `maintenance_part_requests_work_order_id_index` (`work_order_id`),
  CONSTRAINT `maintenance_part_requests_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `maintenance_part_requests_work_order_id_foreign` FOREIGN KEY (`work_order_id`) REFERENCES `maintenance_work_orders` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `maintenance_part_requests_no_update` BEFORE UPDATE ON `maintenance_part_requests` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a part request link cannot be changed' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `maintenance_part_requests_no_delete` BEFORE DELETE ON `maintenance_part_requests` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a part request link cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `maintenance_part_uses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `maintenance_part_uses` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `work_order_id` char(26) NOT NULL,
  `item_id` char(26) NOT NULL,
  `item_code` varchar(40) NOT NULL,
  `item_name` varchar(120) NOT NULL,
  `unit` varchar(12) NOT NULL,
  `quantity_milli` bigint unsigned NOT NULL,
  `location_id` char(26) NOT NULL,
  `location_name` varchar(80) NOT NULL,
  `value_minor` bigint DEFAULT NULL,
  `note` varchar(200) DEFAULT NULL,
  `used_on` date NOT NULL,
  `used_by` char(26) NOT NULL,
  `created_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `maintenance_part_uses_work_order_id_index` (`work_order_id`),
  KEY `maintenance_part_uses_property_id_used_on_index` (`property_id`,`used_on`),
  CONSTRAINT `maintenance_part_uses_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `maintenance_part_uses_work_order_id_foreign` FOREIGN KEY (`work_order_id`) REFERENCES `maintenance_work_orders` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_mtc_part_use` CHECK (((`quantity_milli` > 0) and ((`value_minor` is null) or (`value_minor` >= 0))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `maintenance_part_uses_no_update` BEFORE UPDATE ON `maintenance_part_uses` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a part use cannot be changed' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `maintenance_part_uses_no_delete` BEFORE DELETE ON `maintenance_part_uses` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a part use cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `maintenance_pm_plans`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `maintenance_pm_plans` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `asset_id` char(26) NOT NULL,
  `title` varchar(80) NOT NULL,
  `description` varchar(300) DEFAULT NULL,
  `category` varchar(12) NOT NULL,
  `priority` varchar(8) NOT NULL,
  `trigger_kind` varchar(8) NOT NULL,
  `interval_value` int unsigned NOT NULL,
  `lead_days` smallint unsigned NOT NULL DEFAULT '0',
  `next_due_on` date DEFAULT NULL,
  `last_meter` bigint unsigned DEFAULT NULL,
  `last_done_on` date DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_by` char(26) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `maintenance_pm_plans_property_id_is_active_index` (`property_id`,`is_active`),
  KEY `maintenance_pm_plans_asset_id_foreign` (`asset_id`),
  CONSTRAINT `maintenance_pm_plans_asset_id_foreign` FOREIGN KEY (`asset_id`) REFERENCES `maintenance_assets` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `maintenance_pm_plans_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_mtc_plan` CHECK (((`trigger_kind` in (_utf8mb4'calendar',_utf8mb4'meter')) and (`interval_value` >= 1) and (`lead_days` <= 60) and (`priority` in (_utf8mb4'urgent',_utf8mb4'high',_utf8mb4'normal',_utf8mb4'low')) and (((`trigger_kind` = _utf8mb4'calendar') and (`next_due_on` is not null)) or ((`trigger_kind` = _utf8mb4'meter') and (`last_meter` is not null)))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `maintenance_room_blocks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `maintenance_room_blocks` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `work_order_id` char(26) NOT NULL,
  `block_id` char(26) NOT NULL,
  `room_id` char(26) NOT NULL,
  `room_number` varchar(12) NOT NULL,
  `kind` varchar(14) NOT NULL,
  `from_date` date NOT NULL,
  `until_date` date NOT NULL,
  `released_on` date DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `maintenance_room_blocks_block_id_unique` (`block_id`),
  KEY `maintenance_room_blocks_property_id_from_date_index` (`property_id`,`from_date`),
  KEY `maintenance_room_blocks_work_order_id_foreign` (`work_order_id`),
  CONSTRAINT `maintenance_room_blocks_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `maintenance_room_blocks_work_order_id_foreign` FOREIGN KEY (`work_order_id`) REFERENCES `maintenance_work_orders` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `maintenance_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `maintenance_settings` (
  `property_id` char(26) NOT NULL,
  `sla_urgent_minutes` int unsigned NOT NULL,
  `sla_high_minutes` int unsigned NOT NULL,
  `sla_normal_minutes` int unsigned NOT NULL,
  `sla_low_minutes` int unsigned NOT NULL,
  `warn_percent` smallint unsigned NOT NULL DEFAULT '75',
  `escalate_percent` smallint unsigned NOT NULL DEFAULT '100',
  `night_from_hour` tinyint unsigned NOT NULL DEFAULT '22',
  `night_to_hour` tinyint unsigned NOT NULL DEFAULT '6',
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `updated_by` char(26) NOT NULL,
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`property_id`),
  CONSTRAINT `maintenance_settings_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_mtc_escalation_settings` CHECK (((`warn_percent` between 10 and 100) and (`escalate_percent` between 50 and 300) and (`warn_percent` <= `escalate_percent`) and (`night_from_hour` <= 23) and (`night_to_hour` <= 23))),
  CONSTRAINT `chk_mtc_sla` CHECK (((`sla_urgent_minutes` between 5 and 43200) and (`sla_high_minutes` between 5 and 43200) and (`sla_normal_minutes` between 5 and 43200) and (`sla_low_minutes` between 5 and 43200) and (`sla_urgent_minutes` <= `sla_high_minutes`) and (`sla_high_minutes` <= `sla_normal_minutes`) and (`sla_normal_minutes` <= `sla_low_minutes`)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `maintenance_vendor_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `maintenance_vendor_jobs` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `work_order_id` char(26) NOT NULL,
  `number` varchar(20) NOT NULL,
  `scope` varchar(300) NOT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'quoting',
  `quote_id` char(26) DEFAULT NULL,
  `supplier_id` char(26) DEFAULT NULL,
  `supplier_name` varchar(120) DEFAULT NULL,
  `agreed_minor` bigint DEFAULT NULL,
  `choice_reason` varchar(200) DEFAULT NULL,
  `approval_id` char(26) DEFAULT NULL,
  `scheduled_on` date DEFAULT NULL,
  `schedule_note` varchar(200) DEFAULT NULL,
  `actual_minor` bigint DEFAULT NULL,
  `invoice_ref` varchar(40) DEFAULT NULL,
  `done_note` varchar(300) DEFAULT NULL,
  `over_quote` tinyint(1) NOT NULL DEFAULT '0',
  `proof_file_id` char(26) DEFAULT NULL,
  `done_on` date DEFAULT NULL,
  `done_at` timestamp(6) NULL DEFAULT NULL,
  `cancel_reason` varchar(200) DEFAULT NULL,
  `created_by` char(26) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `maintenance_vendor_jobs_property_id_number_unique` (`property_id`,`number`),
  KEY `maintenance_vendor_jobs_property_id_status_index` (`property_id`,`status`),
  KEY `maintenance_vendor_jobs_work_order_id_index` (`work_order_id`),
  CONSTRAINT `maintenance_vendor_jobs_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `maintenance_vendor_jobs_work_order_id_foreign` FOREIGN KEY (`work_order_id`) REFERENCES `maintenance_work_orders` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_mtc_vendor_job` CHECK (((`status` in (_utf8mb4'quoting',_utf8mb4'pending_approval',_utf8mb4'approved',_utf8mb4'scheduled',_utf8mb4'done',_utf8mb4'rejected',_utf8mb4'cancelled')) and ((`agreed_minor` is null) or (`agreed_minor` > 0)) and ((`actual_minor` is null) or (`actual_minor` >= 0)) and ((`status` <> _utf8mb4'done') or ((`actual_minor` is not null) and (`proof_file_id` is not null) and (`done_at` is not null) and (`done_note` is not null))) and ((`status` not in (_utf8mb4'approved',_utf8mb4'scheduled',_utf8mb4'done')) or ((`quote_id` is not null) and (`agreed_minor` is not null))) and ((`status` <> _utf8mb4'cancelled') or (`cancel_reason` is not null))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `maintenance_vendor_quotes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `maintenance_vendor_quotes` (
  `id` char(26) NOT NULL,
  `job_id` char(26) NOT NULL,
  `supplier_id` char(26) NOT NULL,
  `supplier_name` varchar(120) NOT NULL,
  `amount_minor` bigint NOT NULL,
  `valid_until` date DEFAULT NULL,
  `note` varchar(200) DEFAULT NULL,
  `added_by` char(26) NOT NULL,
  `created_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `maintenance_vendor_quotes_job_id_supplier_id_unique` (`job_id`,`supplier_id`),
  CONSTRAINT `maintenance_vendor_quotes_job_id_foreign` FOREIGN KEY (`job_id`) REFERENCES `maintenance_vendor_jobs` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_mtc_vendor_quote` CHECK ((`amount_minor` > 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `maintenance_vendor_quotes_no_update` BEFORE UPDATE ON `maintenance_vendor_quotes` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a quotation cannot be changed' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `maintenance_vendor_quotes_no_delete` BEFORE DELETE ON `maintenance_vendor_quotes` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a quotation cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `maintenance_work_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `maintenance_work_events` (
  `id` char(26) NOT NULL,
  `work_order_id` char(26) NOT NULL,
  `kind` varchar(16) NOT NULL,
  `note` varchar(300) DEFAULT NULL,
  `actor_id` char(26) NOT NULL,
  `at` timestamp(6) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `maintenance_work_events_work_order_id_at_index` (`work_order_id`,`at`),
  CONSTRAINT `maintenance_work_events_work_order_id_foreign` FOREIGN KEY (`work_order_id`) REFERENCES `maintenance_work_orders` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `maintenance_work_events_no_update` BEFORE UPDATE ON `maintenance_work_events` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'the history of a work order cannot be changed' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `maintenance_work_events_no_delete` BEFORE DELETE ON `maintenance_work_events` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'the history of a work order cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `maintenance_work_orders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `maintenance_work_orders` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `number` varchar(20) NOT NULL,
  `title` varchar(80) NOT NULL,
  `description` varchar(500) DEFAULT NULL,
  `category` varchar(12) NOT NULL,
  `reporter_department` varchar(14) NOT NULL,
  `room_id` char(26) DEFAULT NULL,
  `room_number` varchar(12) DEFAULT NULL,
  `area` varchar(80) DEFAULT NULL,
  `asset_id` char(26) DEFAULT NULL,
  `plan_id` char(26) DEFAULT NULL,
  `pm_due` varchar(24) DEFAULT NULL,
  `priority` varchar(8) NOT NULL,
  `status` varchar(12) NOT NULL DEFAULT 'open',
  `reported_at` timestamp(6) NOT NULL,
  `reported_by` char(26) NOT NULL,
  `due_at` timestamp(6) NOT NULL,
  `assigned_to` char(26) DEFAULT NULL,
  `assigned_at` timestamp(6) NULL DEFAULT NULL,
  `started_at` timestamp(6) NULL DEFAULT NULL,
  `hold_reason` varchar(16) DEFAULT NULL,
  `hold_note` varchar(200) DEFAULT NULL,
  `done_at` timestamp(6) NULL DEFAULT NULL,
  `done_by` char(26) DEFAULT NULL,
  `done_note` varchar(300) DEFAULT NULL,
  `cancel_reason` varchar(200) DEFAULT NULL,
  `report_photo_file_id` char(26) DEFAULT NULL,
  `done_photo_file_id` char(26) DEFAULT NULL,
  `block_id` char(26) DEFAULT NULL,
  `block_kind` varchar(14) DEFAULT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `maintenance_work_orders_property_id_number_unique` (`property_id`,`number`),
  UNIQUE KEY `maintenance_pm_once` (`plan_id`,`pm_due`),
  KEY `maintenance_work_orders_property_id_status_due_at_index` (`property_id`,`status`,`due_at`),
  KEY `maintenance_work_orders_property_id_assigned_to_status_index` (`property_id`,`assigned_to`,`status`),
  KEY `maintenance_work_orders_property_id_room_id_index` (`property_id`,`room_id`),
  KEY `maintenance_work_orders_property_id_asset_id_index` (`property_id`,`asset_id`),
  CONSTRAINT `maintenance_work_orders_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_mtc_work_order` CHECK (((`status` in (_utf8mb4'open',_utf8mb4'assigned',_utf8mb4'in_progress',_utf8mb4'on_hold',_utf8mb4'done',_utf8mb4'cancelled')) and (`priority` in (_utf8mb4'urgent',_utf8mb4'high',_utf8mb4'normal',_utf8mb4'low')) and (`category` in (_utf8mb4'electrical',_utf8mb4'plumbing',_utf8mb4'hvac',_utf8mb4'furniture',_utf8mb4'appliance',_utf8mb4'structural',_utf8mb4'it',_utf8mb4'other')) and ((`room_id` is not null) or (`area` is not null)) and ((`status` <> _utf8mb4'done') or ((`done_photo_file_id` is not null) and (`done_at` is not null))) and ((`hold_reason` is null) or (`hold_reason` in (_utf8mb4'waiting_parts',_utf8mb4'waiting_vendor',_utf8mb4'waiting_access')))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `messaging_channels`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `messaging_channels` (
  `channel` varchar(10) NOT NULL,
  `provider` varchar(24) NOT NULL,
  `settings` text NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT '1',
  `last_test_at` timestamp NULL DEFAULT NULL,
  `last_test_ok` tinyint(1) DEFAULT NULL,
  `last_test_error` varchar(200) DEFAULT NULL,
  `updated_by` char(26) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`channel`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `migrations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) NOT NULL,
  `batch` int NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `night_audits`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `night_audits` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `business_date` date NOT NULL,
  `next_business_date` date NOT NULL,
  `report` json NOT NULL,
  `waivers` json NOT NULL,
  `run_by` char(26) NOT NULL,
  `completed_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `night_audits_property_id_business_date_unique` (`property_id`,`business_date`),
  KEY `night_audits_run_by_foreign` (`run_by`),
  CONSTRAINT `night_audits_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `night_audits_run_by_foreign` FOREIGN KEY (`run_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_night_audits_next` CHECK ((`next_business_date` > `business_date`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `night_audits_no_update` BEFORE UPDATE ON `night_audits` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a night audit record cannot be changed' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `night_audits_no_delete` BEFORE DELETE ON `night_audits` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a night audit record cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `obligation_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `obligation_settings` (
  `property_id` char(26) NOT NULL,
  `tax_report_day` tinyint unsigned NOT NULL,
  `service_employee_share_bp` smallint unsigned NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `updated_by` char(26) NOT NULL,
  `updated_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`property_id`),
  KEY `obligation_settings_updated_by_foreign` (`updated_by`),
  CONSTRAINT `obligation_settings_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `obligation_settings_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_obligation_values` CHECK (((`tax_report_day` between 1 and 28) and (`service_employee_share_bp` <= 10000)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `offline_device_status`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `offline_device_status` (
  `property_id` char(26) NOT NULL,
  `device_id` char(26) NOT NULL,
  `actor_id` char(26) NOT NULL,
  `pending` int unsigned NOT NULL,
  `oldest_pending_seconds` int unsigned NOT NULL,
  `reported_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`property_id`,`device_id`),
  KEY `offline_device_status_actor_id_foreign` (`actor_id`),
  KEY `offline_device_status_reported_at_index` (`reported_at`),
  CONSTRAINT `offline_device_status_actor_id_foreign` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `offline_device_status_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `offline_sync_exceptions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `offline_sync_exceptions` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `operation_id` char(26) NOT NULL,
  `operation_type` varchar(80) NOT NULL,
  `device_id` char(26) NOT NULL,
  `client_sequence` bigint unsigned NOT NULL,
  `actor_id` char(26) NOT NULL,
  `kind` varchar(16) NOT NULL,
  `reason_code` varchar(64) NOT NULL,
  `conflict_action` varchar(16) DEFAULT NULL,
  `server_version` bigint unsigned DEFAULT NULL,
  `payload_version` smallint unsigned NOT NULL,
  `encrypted_payload` longtext NOT NULL,
  `device_time` timestamp(6) NOT NULL,
  `received_at` timestamp(6) NOT NULL,
  `correlation_id` char(26) NOT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'open',
  `resolved_at` timestamp(6) NULL DEFAULT NULL,
  `resolved_by` char(26) DEFAULT NULL,
  `resolution_note` varchar(500) DEFAULT NULL,
  `lock_version` bigint unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `offline_exc_property_operation_unique` (`property_id`,`operation_id`),
  KEY `offline_sync_exceptions_actor_id_foreign` (`actor_id`),
  KEY `offline_sync_exceptions_resolved_by_foreign` (`resolved_by`),
  KEY `offline_exc_property_status_received` (`property_id`,`status`,`received_at`),
  KEY `offline_sync_exceptions_device_id_index` (`device_id`),
  KEY `offline_sync_exceptions_correlation_id_index` (`correlation_id`),
  CONSTRAINT `offline_sync_exceptions_actor_id_foreign` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `offline_sync_exceptions_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `offline_sync_exceptions_resolved_by_foreign` FOREIGN KEY (`resolved_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_offline_exc_kind` CHECK ((`kind` in (_utf8mb4'conflict',_utf8mb4'rejected'))),
  CONSTRAINT `chk_offline_exc_resolution` CHECK ((((`status` = _utf8mb4'open') and (`resolved_at` is null) and (`resolved_by` is null)) or ((`status` = _utf8mb4'resolved') and (`resolved_at` is not null) and (`resolved_by` is not null)))),
  CONSTRAINT `chk_offline_exc_status` CHECK ((`status` in (_utf8mb4'open',_utf8mb4'resolved')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `offline_sync_exceptions_prevent_delete` BEFORE DELETE ON `offline_sync_exceptions` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'offline sync exceptions are reconciliation evidence and cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `online_booking_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `online_booking_settings` (
  `property_id` char(26) NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT '0',
  `rate_plan_id` char(26) DEFAULT NULL,
  `max_nights` tinyint unsigned NOT NULL DEFAULT '14',
  `notify_email` varchar(190) DEFAULT NULL,
  `notice` varchar(500) DEFAULT NULL,
  `actor_user_id` char(26) DEFAULT NULL,
  `updated_by` char(26) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `remind_before_arrival` tinyint(1) NOT NULL DEFAULT '0',
  `thank_after_stay` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`property_id`),
  KEY `online_booking_settings_rate_plan_id_foreign` (`rate_plan_id`),
  KEY `online_booking_settings_updated_by_foreign` (`updated_by`),
  CONSTRAINT `online_booking_settings_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `online_booking_settings_rate_plan_id_foreign` FOREIGN KEY (`rate_plan_id`) REFERENCES `rate_plans` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `online_booking_settings_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `operational_alerts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `operational_alerts` (
  `id` char(26) NOT NULL,
  `alert_key` varchar(120) NOT NULL,
  `open_key` varchar(120) DEFAULT NULL,
  `severity` varchar(20) NOT NULL,
  `summary` varchar(255) NOT NULL,
  `context` json NOT NULL,
  `occurrences` int unsigned NOT NULL,
  `first_seen_at` timestamp(6) NOT NULL,
  `last_seen_at` timestamp(6) NOT NULL,
  `resolved_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `operational_alerts_open_key_unique` (`open_key`),
  KEY `operational_alerts_alert_key_first_seen_at_index` (`alert_key`,`first_seen_at`),
  KEY `operational_alerts_resolved_at_severity_index` (`resolved_at`,`severity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `outbox_messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `outbox_messages` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `event_type` varchar(120) NOT NULL,
  `aggregate_id` char(26) NOT NULL,
  `payload_version` smallint unsigned NOT NULL,
  `encrypted_payload` longtext NOT NULL,
  `correlation_id` char(26) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `attempt_count` smallint unsigned NOT NULL DEFAULT '0',
  `requeue_count` smallint unsigned NOT NULL DEFAULT '0',
  `available_at` timestamp(6) NULL DEFAULT NULL,
  `queued_at` timestamp(6) NULL DEFAULT NULL,
  `last_attempt_at` timestamp(6) NULL DEFAULT NULL,
  `completed_at` timestamp(6) NULL DEFAULT NULL,
  `dead_lettered_at` timestamp(6) NULL DEFAULT NULL,
  `last_error_type` varchar(255) DEFAULT NULL,
  `last_error_fingerprint` char(64) DEFAULT NULL,
  `occurred_at` timestamp(6) NOT NULL,
  `created_at` timestamp(6) NOT NULL,
  `updated_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `outbox_property_event_unique` (`property_id`,`id`),
  KEY `outbox_due_messages_index` (`status`,`available_at`,`occurred_at`),
  KEY `outbox_property_status_index` (`property_id`,`status`,`occurred_at`),
  KEY `outbox_property_type_index` (`property_id`,`event_type`,`occurred_at`),
  KEY `outbox_property_aggregate_index` (`property_id`,`aggregate_id`,`occurred_at`),
  KEY `outbox_property_dead_letter_index` (`property_id`,`dead_lettered_at`),
  KEY `outbox_messages_correlation_id_index` (`correlation_id`),
  CONSTRAINT `outbox_messages_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_outbox_error_identity` CHECK ((((`last_error_type` is null) and (`last_error_fingerprint` is null)) or ((`last_error_type` is not null) and (`last_error_fingerprint` is not null)))),
  CONSTRAINT `chk_outbox_status` CHECK ((`status` in (_utf8mb4'pending',_utf8mb4'queued',_utf8mb4'processing',_utf8mb4'retrying',_utf8mb4'completed',_utf8mb4'dead_letter'))),
  CONSTRAINT `chk_outbox_terminal_timestamps` CHECK ((((`status` = _utf8mb4'completed') and (`completed_at` is not null) and (`dead_lettered_at` is null)) or ((`status` = _utf8mb4'dead_letter') and (`completed_at` is null) and (`dead_lettered_at` is not null)) or ((`status` not in (_utf8mb4'completed',_utf8mb4'dead_letter')) and (`completed_at` is null) and (`dead_lettered_at` is null))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `password_reset_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `password_reset_tokens` (
  `email` varchar(255) NOT NULL,
  `token` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `permissions` (
  `id` char(26) NOT NULL,
  `code` varchar(120) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `permissions_code_unique` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `processed_outbox_messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `processed_outbox_messages` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `event_id` char(26) NOT NULL,
  `consumer` varchar(120) NOT NULL,
  `processed_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `outbox_consumer_event_unique` (`event_id`,`consumer`),
  KEY `processed_outbox_messages_property_id_processed_at_index` (`property_id`,`processed_at`),
  KEY `processed_outbox_property_event_foreign` (`property_id`,`event_id`),
  CONSTRAINT `processed_outbox_messages_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `processed_outbox_property_event_foreign` FOREIGN KEY (`property_id`, `event_id`) REFERENCES `outbox_messages` (`property_id`, `id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `properties`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `properties` (
  `id` char(26) NOT NULL,
  `name` varchar(255) NOT NULL,
  `timezone` varchar(64) NOT NULL,
  `currency_code` char(3) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `property_branding_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `property_branding_settings` (
  `property_id` char(26) NOT NULL,
  `show_powered_by` tinyint(1) NOT NULL DEFAULT '1',
  `updated_by` char(26) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`property_id`),
  KEY `property_branding_settings_updated_by_foreign` (`updated_by`),
  CONSTRAINT `property_branding_settings_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `property_branding_settings_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `property_features`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `property_features` (
  `property_id` char(26) NOT NULL,
  `feature` varchar(32) NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT '0',
  `updated_by` char(26) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`property_id`,`feature`),
  KEY `property_features_updated_by_foreign` (`updated_by`),
  CONSTRAINT `property_features_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `property_features_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `property_logos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `property_logos` (
  `property_id` char(26) NOT NULL,
  `mime` varchar(20) NOT NULL,
  `content` mediumblob NOT NULL,
  `sha256` char(64) NOT NULL,
  `updated_by` char(26) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`property_id`),
  KEY `property_logos_updated_by_foreign` (`updated_by`),
  CONSTRAINT `property_logos_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `property_logos_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_property_logo_mime` CHECK ((`mime` in (_utf8mb4'image/png',_utf8mb4'image/jpeg',_utf8mb4'image/webp',_utf8mb4'image/svg+xml')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `property_profiles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `property_profiles` (
  `property_id` char(26) NOT NULL,
  `profile` varchar(16) NOT NULL,
  `disabled_modules` json NOT NULL,
  `updated_by` char(26) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`property_id`),
  KEY `property_profiles_updated_by_foreign` (`updated_by`),
  CONSTRAINT `property_profiles_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `property_profiles_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_property_profile` CHECK ((`profile` in (_utf8mb4'hotel',_utf8mb4'small_resort',_utf8mb4'villa')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `property_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `property_settings` (
  `property_id` char(26) NOT NULL,
  `business_date` date DEFAULT NULL,
  `check_in_time` char(5) NOT NULL,
  `check_out_time` char(5) NOT NULL,
  `night_audit_earliest_time` char(5) NOT NULL,
  `rounding_increment_minor` int unsigned NOT NULL,
  `rounding_mode` varchar(12) NOT NULL,
  `availability_horizon_days` smallint unsigned NOT NULL,
  `updated_by` char(26) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`property_id`),
  KEY `property_settings_updated_by_foreign` (`updated_by`),
  CONSTRAINT `property_settings_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `property_settings_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_property_settings_horizon` CHECK ((`availability_horizon_days` between 30 and 1095)),
  CONSTRAINT `chk_property_settings_rounding` CHECK (((`rounding_increment_minor` between 1 and 1000000) and (`rounding_mode` in (_utf8mb4'half_up',_utf8mb4'half_even',_utf8mb4'down',_utf8mb4'up'))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `property_settings_business_date_forward` BEFORE UPDATE ON `property_settings` FOR EACH ROW BEGIN
    IF OLD.business_date IS NOT NULL AND (NEW.business_date IS NULL OR NEW.business_date < OLD.business_date) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'the business date only moves forward';
    END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `property_settings_no_delete` BEFORE DELETE ON `property_settings` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'property_settings cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `purchase_order_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `purchase_order_lines` (
  `id` char(26) NOT NULL,
  `order_id` char(26) NOT NULL,
  `line_no` smallint unsigned NOT NULL,
  `item_id` char(26) NOT NULL,
  `unit` varchar(8) NOT NULL,
  `qty_milli` bigint unsigned NOT NULL,
  `unit_price_minor` bigint unsigned NOT NULL,
  `line_total_minor` bigint unsigned NOT NULL,
  `department` varchar(16) DEFAULT NULL,
  `request_line_id` char(26) DEFAULT NULL,
  `received_qty_milli` bigint unsigned NOT NULL DEFAULT '0',
  `rejected_qty_milli` bigint unsigned NOT NULL DEFAULT '0',
  `returned_qty_milli` bigint unsigned NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`),
  UNIQUE KEY `purchase_order_lines_order_id_line_no_unique` (`order_id`,`line_no`),
  KEY `purchase_order_lines_item_id_index` (`item_id`),
  CONSTRAINT `purchase_order_lines_item_id_foreign` FOREIGN KEY (`item_id`) REFERENCES `inventory_items` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `purchase_order_lines_order_id_foreign` FOREIGN KEY (`order_id`) REFERENCES `purchase_orders` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_purchase_order_line` CHECK ((`qty_milli` > 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `purchase_order_revisions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `purchase_order_revisions` (
  `id` char(26) NOT NULL,
  `order_id` char(26) NOT NULL,
  `revision` smallint unsigned NOT NULL,
  `snapshot` json NOT NULL,
  `reason` varchar(200) NOT NULL,
  `needed_approval` tinyint(1) NOT NULL DEFAULT '0',
  `approval_id` char(26) DEFAULT NULL,
  `created_by` char(26) NOT NULL,
  `created_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `purchase_order_revisions_order_id_revision_unique` (`order_id`,`revision`),
  KEY `purchase_order_revisions_created_by_foreign` (`created_by`),
  CONSTRAINT `purchase_order_revisions_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `purchase_order_revisions_order_id_foreign` FOREIGN KEY (`order_id`) REFERENCES `purchase_orders` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `purchase_order_revisions_no_update` BEFORE UPDATE ON `purchase_order_revisions` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a purchase order revision cannot be changed' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `purchase_order_revisions_no_delete` BEFORE DELETE ON `purchase_order_revisions` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a purchase order revision cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `purchase_orders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `purchase_orders` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `number` varchar(20) NOT NULL,
  `revision` smallint unsigned NOT NULL DEFAULT '0',
  `supplier_id` char(26) NOT NULL,
  `location_id` char(26) NOT NULL,
  `status` varchar(18) NOT NULL DEFAULT 'draft',
  `resume_status` varchar(18) DEFAULT NULL,
  `pending_revision` json DEFAULT NULL,
  `order_date` date NOT NULL,
  `expected_date` date DEFAULT NULL,
  `payment_terms_days` smallint unsigned NOT NULL DEFAULT '30',
  `tax_bp` smallint unsigned NOT NULL DEFAULT '0',
  `subtotal_minor` bigint unsigned NOT NULL DEFAULT '0',
  `tax_minor` bigint unsigned NOT NULL DEFAULT '0',
  `total_minor` bigint unsigned NOT NULL DEFAULT '0',
  `note` varchar(200) DEFAULT NULL,
  `created_by` char(26) NOT NULL,
  `approval_id` char(26) DEFAULT NULL,
  `issued_at` timestamp(6) NULL DEFAULT NULL,
  `close_reason` varchar(200) DEFAULT NULL,
  `business_date` date NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `purchase_orders_property_id_number_unique` (`property_id`,`number`),
  KEY `purchase_orders_location_id_foreign` (`location_id`),
  KEY `purchase_orders_created_by_foreign` (`created_by`),
  KEY `purchase_orders_property_id_status_index` (`property_id`,`status`),
  KEY `purchase_orders_supplier_id_status_index` (`supplier_id`,`status`),
  CONSTRAINT `purchase_orders_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `purchase_orders_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `inventory_locations` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `purchase_orders_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `purchase_orders_supplier_id_foreign` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_purchase_order` CHECK (((`status` in (_utf8mb4'draft',_utf8mb4'pending_approval',_utf8mb4'approved',_utf8mb4'issued',_utf8mb4'partially_received',_utf8mb4'received',_utf8mb4'closed',_utf8mb4'cancelled')) and (`total_minor` = (`subtotal_minor` + `tax_minor`))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `purchase_orders_guard` BEFORE UPDATE ON `purchase_orders` FOR EACH ROW BEGIN
    IF OLD.status IN ('closed', 'cancelled', 'received') AND NEW.status <> OLD.status THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a finished purchase order cannot change state'; END IF;
    IF NEW.number <> OLD.number OR NEW.created_by <> OLD.created_by OR NEW.business_date <> OLD.business_date THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'the facts of a purchase order cannot be changed'; END IF;
    IF NEW.revision < OLD.revision THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a purchase order revision cannot go back'; END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `purchase_orders_no_delete` BEFORE DELETE ON `purchase_orders` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a purchase order cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `purchase_request_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `purchase_request_lines` (
  `id` char(26) NOT NULL,
  `request_id` char(26) NOT NULL,
  `item_id` char(26) NOT NULL,
  `unit` varchar(8) NOT NULL,
  `qty_milli` bigint unsigned NOT NULL,
  `est_unit_cost_minor` bigint unsigned NOT NULL DEFAULT '0',
  `note` varchar(200) DEFAULT NULL,
  `po_id` char(26) DEFAULT NULL,
  `po_line_id` char(26) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `purchase_request_lines_item_id_foreign` (`item_id`),
  KEY `purchase_request_lines_request_id_index` (`request_id`),
  KEY `purchase_request_lines_po_id_index` (`po_id`),
  CONSTRAINT `purchase_request_lines_item_id_foreign` FOREIGN KEY (`item_id`) REFERENCES `inventory_items` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `purchase_request_lines_request_id_foreign` FOREIGN KEY (`request_id`) REFERENCES `purchase_requests` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_purchase_request_line` CHECK ((`qty_milli` > 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `purchase_request_lines_guard` BEFORE UPDATE ON `purchase_request_lines` FOR EACH ROW BEGIN
    IF (SELECT status FROM purchase_requests WHERE id = OLD.request_id) <> 'draft' AND (NEW.item_id <> OLD.item_id OR NEW.unit <> OLD.unit OR NEW.qty_milli <> OLD.qty_milli OR NEW.est_unit_cost_minor <> OLD.est_unit_cost_minor) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a line of a submitted purchase request cannot be edited';
    END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `purchase_request_lines_no_delete` BEFORE DELETE ON `purchase_request_lines` FOR EACH ROW IF (SELECT status FROM purchase_requests WHERE id = OLD.request_id) <> 'draft' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a line of a submitted purchase request cannot be deleted'; END IF */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `purchase_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `purchase_requests` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `number` varchar(20) NOT NULL,
  `department` varchar(16) NOT NULL,
  `urgency` varchar(8) NOT NULL,
  `reason` varchar(200) NOT NULL,
  `needed_by` date NOT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'draft',
  `requested_by` char(26) NOT NULL,
  `approval_id` char(26) DEFAULT NULL,
  `total_minor` bigint unsigned NOT NULL DEFAULT '0',
  `decision_note` varchar(200) DEFAULT NULL,
  `submitted_at` timestamp(6) NULL DEFAULT NULL,
  `business_date` date NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `purchase_requests_property_id_number_unique` (`property_id`,`number`),
  KEY `purchase_requests_requested_by_foreign` (`requested_by`),
  KEY `purchase_requests_property_id_status_index` (`property_id`,`status`),
  CONSTRAINT `purchase_requests_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `purchase_requests_requested_by_foreign` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_purchase_request` CHECK (((`urgency` in (_utf8mb4'low',_utf8mb4'normal',_utf8mb4'high',_utf8mb4'urgent')) and (`status` in (_utf8mb4'draft',_utf8mb4'pending_approval',_utf8mb4'approved',_utf8mb4'on_hold',_utf8mb4'rejected',_utf8mb4'cancelled',_utf8mb4'ordered'))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `purchase_requests_guard` BEFORE UPDATE ON `purchase_requests` FOR EACH ROW BEGIN
    IF OLD.status IN ('rejected', 'cancelled') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a decided purchase request cannot be changed'; END IF;
    IF NEW.number <> OLD.number OR NEW.requested_by <> OLD.requested_by OR NEW.business_date <> OLD.business_date THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'the facts of a purchase request cannot be changed'; END IF;
    IF OLD.status <> 'draft' AND (NEW.department <> OLD.department OR NEW.urgency <> OLD.urgency OR NEW.reason <> OLD.reason OR NEW.needed_by <> OLD.needed_by OR NEW.total_minor <> OLD.total_minor) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a submitted purchase request cannot be edited';
    END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `purchase_requests_no_delete` BEFORE DELETE ON `purchase_requests` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a purchase request cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `purchase_return_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `purchase_return_lines` (
  `id` char(26) NOT NULL,
  `return_id` char(26) NOT NULL,
  `receipt_line_id` char(26) NOT NULL,
  `order_line_id` char(26) NOT NULL,
  `item_id` char(26) NOT NULL,
  `unit` varchar(8) NOT NULL,
  `qty_milli` bigint unsigned NOT NULL,
  `unit_price_minor` bigint unsigned NOT NULL,
  `value_minor` bigint unsigned NOT NULL,
  `movement_id` char(26) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `purchase_return_lines_return_id_receipt_line_id_unique` (`return_id`,`receipt_line_id`),
  KEY `purchase_return_lines_order_line_id_foreign` (`order_line_id`),
  KEY `purchase_return_lines_item_id_foreign` (`item_id`),
  KEY `purchase_return_lines_receipt_line_id_index` (`receipt_line_id`),
  CONSTRAINT `purchase_return_lines_item_id_foreign` FOREIGN KEY (`item_id`) REFERENCES `inventory_items` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `purchase_return_lines_order_line_id_foreign` FOREIGN KEY (`order_line_id`) REFERENCES `purchase_order_lines` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `purchase_return_lines_receipt_line_id_foreign` FOREIGN KEY (`receipt_line_id`) REFERENCES `goods_receipt_lines` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `purchase_return_lines_return_id_foreign` FOREIGN KEY (`return_id`) REFERENCES `purchase_returns` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_purchase_return_line` CHECK ((`qty_milli` > 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `purchase_return_lines_no_update` BEFORE UPDATE ON `purchase_return_lines` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a purchase return line cannot be changed' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `purchase_return_lines_no_delete` BEFORE DELETE ON `purchase_return_lines` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a purchase return line cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `purchase_returns`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `purchase_returns` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `number` varchar(20) NOT NULL,
  `receipt_id` char(26) NOT NULL,
  `order_id` char(26) NOT NULL,
  `supplier_id` char(26) NOT NULL,
  `location_id` char(26) NOT NULL,
  `reason` varchar(16) NOT NULL,
  `note` varchar(200) DEFAULT NULL,
  `value_minor` bigint unsigned NOT NULL,
  `credit_tax_minor` bigint unsigned NOT NULL DEFAULT '0',
  `credit_note_number` varchar(40) DEFAULT NULL,
  `returned_by` char(26) NOT NULL,
  `business_date` date NOT NULL,
  `created_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `purchase_returns_property_id_number_unique` (`property_id`,`number`),
  KEY `purchase_returns_order_id_foreign` (`order_id`),
  KEY `purchase_returns_location_id_foreign` (`location_id`),
  KEY `purchase_returns_returned_by_foreign` (`returned_by`),
  KEY `purchase_returns_receipt_id_index` (`receipt_id`),
  KEY `purchase_returns_supplier_id_business_date_index` (`supplier_id`,`business_date`),
  CONSTRAINT `purchase_returns_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `inventory_locations` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `purchase_returns_order_id_foreign` FOREIGN KEY (`order_id`) REFERENCES `purchase_orders` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `purchase_returns_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `purchase_returns_receipt_id_foreign` FOREIGN KEY (`receipt_id`) REFERENCES `goods_receipts` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `purchase_returns_returned_by_foreign` FOREIGN KEY (`returned_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `purchase_returns_supplier_id_foreign` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_purchase_return` CHECK ((`reason` in (_utf8mb4'damaged',_utf8mb4'expired',_utf8mb4'wrong_item',_utf8mb4'quality',_utf8mb4'overstock',_utf8mb4'other')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `purchase_returns_no_update` BEFORE UPDATE ON `purchase_returns` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a purchase return cannot be changed' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `purchase_returns_no_delete` BEFORE DELETE ON `purchase_returns` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a purchase return cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `purchasing_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `purchasing_settings` (
  `property_id` char(26) NOT NULL,
  `budget_policy` varchar(8) NOT NULL DEFAULT 'warn',
  `tax_bp` smallint unsigned NOT NULL DEFAULT '1100',
  `po_tolerance_bp` smallint unsigned NOT NULL DEFAULT '500',
  `over_receipt_bp` smallint unsigned NOT NULL DEFAULT '1000',
  `invoice_price_tolerance_bp` smallint unsigned NOT NULL DEFAULT '100',
  `invoice_qty_tolerance_bp` smallint unsigned NOT NULL DEFAULT '0',
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`property_id`),
  CONSTRAINT `purchasing_settings_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_purchasing_settings` CHECK (((`budget_policy` in (_utf8mb4'off',_utf8mb4'warn',_utf8mb4'block')) and (`tax_bp` <= 5000) and (`po_tolerance_bp` <= 10000) and (`over_receipt_bp` <= 10000) and (`invoice_price_tolerance_bp` <= 10000) and (`invoice_qty_tolerance_bp` <= 10000)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `push_devices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `push_devices` (
  `id` char(26) NOT NULL,
  `user_id` char(26) NOT NULL,
  `endpoint_hash` char(64) NOT NULL,
  `endpoint_sealed` text NOT NULL,
  `label` varchar(80) DEFAULT NULL,
  `failures` smallint unsigned NOT NULL DEFAULT '0',
  `last_pushed_at` timestamp(6) NULL DEFAULT NULL,
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `push_devices_endpoint_hash_unique` (`endpoint_hash`),
  KEY `push_devices_user_id_index` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `push_notices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `push_notices` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `device_id` char(26) NOT NULL,
  `title` varchar(120) NOT NULL,
  `body` varchar(300) NOT NULL,
  `url` varchar(200) NOT NULL,
  `created_at` timestamp(6) NOT NULL,
  `fetched_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `push_notices_device_id_fetched_at_created_at_index` (`device_id`,`fetched_at`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `push_preferences`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `push_preferences` (
  `user_id` char(26) NOT NULL,
  `kind` varchar(20) NOT NULL,
  `created_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`user_id`,`kind`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `rate_periods`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `rate_periods` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `rate_plan_id` char(26) NOT NULL,
  `room_type_id` char(26) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `weekday_mask` tinyint unsigned NOT NULL,
  `amount_minor` bigint NOT NULL,
  `currency_code` char(3) NOT NULL,
  `change_reason` varchar(500) NOT NULL,
  `created_by` char(26) NOT NULL,
  `created_at` timestamp(6) NOT NULL,
  `superseded_at` timestamp(6) NULL DEFAULT NULL,
  `superseded_by` char(26) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `rate_periods_rate_plan_id_foreign` (`rate_plan_id`),
  KEY `rate_periods_room_type_id_foreign` (`room_type_id`),
  KEY `rate_periods_created_by_foreign` (`created_by`),
  KEY `rate_periods_superseded_by_foreign` (`superseded_by`),
  KEY `rate_periods_lookup` (`property_id`,`rate_plan_id`,`room_type_id`,`superseded_at`,`start_date`),
  CONSTRAINT `rate_periods_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `rate_periods_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `rate_periods_rate_plan_id_foreign` FOREIGN KEY (`rate_plan_id`) REFERENCES `rate_plans` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `rate_periods_room_type_id_foreign` FOREIGN KEY (`room_type_id`) REFERENCES `room_types` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `rate_periods_superseded_by_foreign` FOREIGN KEY (`superseded_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_rate_periods_shape` CHECK (((`end_date` >= `start_date`) and (`weekday_mask` between 1 and 127) and (`amount_minor` >= 0)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `rate_periods_supersede_only` BEFORE UPDATE ON `rate_periods` FOR EACH ROW BEGIN
    IF NOT (OLD.superseded_at IS NULL AND NEW.superseded_at IS NOT NULL AND NEW.superseded_by IS NOT NULL
        AND NEW.id <=> OLD.id AND NEW.property_id <=> OLD.property_id AND NEW.rate_plan_id <=> OLD.rate_plan_id
        AND NEW.start_date <=> OLD.start_date AND NEW.end_date <=> OLD.end_date
        AND NEW.change_reason <=> OLD.change_reason AND NEW.created_by <=> OLD.created_by AND NEW.created_at <=> OLD.created_at AND NEW.room_type_id <=> OLD.room_type_id AND NEW.weekday_mask <=> OLD.weekday_mask AND NEW.amount_minor <=> OLD.amount_minor AND NEW.currency_code <=> OLD.currency_code) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'rate_periods rows can only be superseded, once';
    END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `rate_periods_no_delete` BEFORE DELETE ON `rate_periods` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'rate_periods cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `rate_plans`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `rate_plans` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `code` varchar(20) NOT NULL,
  `name` varchar(100) NOT NULL,
  `kind` varchar(20) NOT NULL,
  `inclusions` varchar(500) DEFAULT NULL,
  `prices_include_charges` tinyint(1) NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `rate_plans_property_id_code_unique` (`property_id`,`code`),
  CONSTRAINT `rate_plans_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_rate_plans_kind` CHECK ((`kind` in (_utf8mb4'public',_utf8mb4'corporate',_utf8mb4'package',_utf8mb4'ota',_utf8mb4'promotion')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `rate_plans_no_delete` BEFORE DELETE ON `rate_plans` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'rate_plans cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `rate_restrictions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `rate_restrictions` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `rate_plan_id` char(26) NOT NULL,
  `room_type_id` char(26) DEFAULT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `min_stay` smallint unsigned DEFAULT NULL,
  `max_stay` smallint unsigned DEFAULT NULL,
  `closed_to_arrival` tinyint(1) NOT NULL DEFAULT '0',
  `closed_to_departure` tinyint(1) NOT NULL DEFAULT '0',
  `stop_sell` tinyint(1) NOT NULL DEFAULT '0',
  `change_reason` varchar(500) NOT NULL,
  `created_by` char(26) NOT NULL,
  `created_at` timestamp(6) NOT NULL,
  `superseded_at` timestamp(6) NULL DEFAULT NULL,
  `superseded_by` char(26) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `rate_restrictions_rate_plan_id_foreign` (`rate_plan_id`),
  KEY `rate_restrictions_room_type_id_foreign` (`room_type_id`),
  KEY `rate_restrictions_created_by_foreign` (`created_by`),
  KEY `rate_restrictions_superseded_by_foreign` (`superseded_by`),
  KEY `rate_restrictions_lookup` (`property_id`,`rate_plan_id`,`superseded_at`,`start_date`),
  CONSTRAINT `rate_restrictions_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `rate_restrictions_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `rate_restrictions_rate_plan_id_foreign` FOREIGN KEY (`rate_plan_id`) REFERENCES `rate_plans` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `rate_restrictions_room_type_id_foreign` FOREIGN KEY (`room_type_id`) REFERENCES `room_types` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `rate_restrictions_superseded_by_foreign` FOREIGN KEY (`superseded_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_rate_restrictions_shape` CHECK (((`end_date` >= `start_date`) and ((`min_stay` is null) or (`min_stay` between 1 and 365)) and ((`max_stay` is null) or (`max_stay` between 1 and 365)) and ((`min_stay` is null) or (`max_stay` is null) or (`min_stay` <= `max_stay`))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `rate_restrictions_supersede_only` BEFORE UPDATE ON `rate_restrictions` FOR EACH ROW BEGIN
    IF NOT (OLD.superseded_at IS NULL AND NEW.superseded_at IS NOT NULL AND NEW.superseded_by IS NOT NULL
        AND NEW.id <=> OLD.id AND NEW.property_id <=> OLD.property_id AND NEW.rate_plan_id <=> OLD.rate_plan_id
        AND NEW.start_date <=> OLD.start_date AND NEW.end_date <=> OLD.end_date
        AND NEW.change_reason <=> OLD.change_reason AND NEW.created_by <=> OLD.created_by AND NEW.created_at <=> OLD.created_at AND NEW.room_type_id <=> OLD.room_type_id AND NEW.min_stay <=> OLD.min_stay AND NEW.max_stay <=> OLD.max_stay AND NEW.closed_to_arrival <=> OLD.closed_to_arrival AND NEW.closed_to_departure <=> OLD.closed_to_departure AND NEW.stop_sell <=> OLD.stop_sell) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'rate_restrictions rows can only be superseded, once';
    END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `rate_restrictions_no_delete` BEFORE DELETE ON `rate_restrictions` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'rate_restrictions cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `registration_cards`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `registration_cards` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `stay_id` char(26) NOT NULL,
  `terms_body` text,
  `terms_version` int unsigned DEFAULT NULL,
  `signature_file_id` char(26) NOT NULL,
  `recorded_by` char(26) NOT NULL,
  `signed_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `registration_cards_stay_id_unique` (`stay_id`),
  KEY `registration_cards_property_id_foreign` (`property_id`),
  KEY `registration_cards_recorded_by_foreign` (`recorded_by`),
  CONSTRAINT `registration_cards_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `registration_cards_recorded_by_foreign` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `registration_cards_stay_id_foreign` FOREIGN KEY (`stay_id`) REFERENCES `stays` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `registration_cards_no_update` BEFORE UPDATE ON `registration_cards` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a signed registration card cannot be changed' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `registration_cards_no_delete` BEFORE DELETE ON `registration_cards` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a signed registration card cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `registration_terms`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `registration_terms` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `version` int unsigned NOT NULL,
  `body` text NOT NULL,
  `reason` varchar(300) NOT NULL,
  `created_by` char(26) NOT NULL,
  `created_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `registration_terms_property_id_version_unique` (`property_id`,`version`),
  KEY `registration_terms_created_by_foreign` (`created_by`),
  CONSTRAINT `registration_terms_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `registration_terms_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `registration_terms_no_update` BEFORE UPDATE ON `registration_terms` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'registration terms cannot be changed' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `registration_terms_no_delete` BEFORE DELETE ON `registration_terms` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'registration terms cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `report_export_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `report_export_jobs` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `report` varchar(20) NOT NULL,
  `params` json NOT NULL,
  `purpose` varchar(300) DEFAULT NULL,
  `schedule_id` char(26) DEFAULT NULL,
  `requested_by` char(26) NOT NULL,
  `status` varchar(7) NOT NULL,
  `row_count` int unsigned DEFAULT NULL,
  `file_id` char(26) DEFAULT NULL,
  `filename` varchar(120) DEFAULT NULL,
  `error` varchar(300) DEFAULT NULL,
  `requested_at` timestamp(6) NOT NULL,
  `started_at` timestamp(6) NULL DEFAULT NULL,
  `finished_at` timestamp(6) NULL DEFAULT NULL,
  `seen_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `export_jobs_queue_index` (`property_id`,`status`,`requested_at`),
  KEY `export_jobs_owner_index` (`requested_by`,`requested_at`),
  CONSTRAINT `report_export_jobs_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `report_export_jobs_requested_by_foreign` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_export_job` CHECK (((`status` in (_utf8mb4'queued',_utf8mb4'running',_utf8mb4'done',_utf8mb4'failed')) and (((`status` in (_utf8mb4'queued',_utf8mb4'running')) and (`file_id` is null) and (`finished_at` is null)) or ((`status` = _utf8mb4'done') and (`file_id` is not null) and (`finished_at` is not null) and (`filename` is not null)) or ((`status` = _utf8mb4'failed') and (`file_id` is null) and (`error` is not null) and (`finished_at` is not null)))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `report_export_jobs_facts` BEFORE UPDATE ON `report_export_jobs` FOR EACH ROW BEGIN
    IF NOT (NEW.report <=> OLD.report AND NEW.requested_by <=> OLD.requested_by AND NEW.requested_at <=> OLD.requested_at AND NEW.purpose <=> OLD.purpose AND NEW.property_id <=> OLD.property_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'what was asked of an export cannot be changed';
    END IF;
    IF OLD.status IN ('done', 'failed') AND NOT (NEW.status <=> OLD.status AND NEW.file_id <=> OLD.file_id AND NEW.error <=> OLD.error AND NEW.finished_at <=> OLD.finished_at) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a finished export cannot be changed';
    END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `report_export_jobs_no_delete` BEFORE DELETE ON `report_export_jobs` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'an export request cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `report_schedule_recipients`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `report_schedule_recipients` (
  `schedule_id` char(26) NOT NULL,
  `user_id` char(26) NOT NULL,
  PRIMARY KEY (`schedule_id`,`user_id`),
  CONSTRAINT `report_schedule_recipients_schedule_id_foreign` FOREIGN KEY (`schedule_id`) REFERENCES `report_schedules` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `report_schedule_runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `report_schedule_runs` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `schedule_id` char(26) NOT NULL,
  `due_at` timestamp(6) NOT NULL,
  `ran_at` timestamp(6) NOT NULL,
  `queued` smallint unsigned NOT NULL,
  `skipped` smallint unsigned NOT NULL,
  `skipped_note` varchar(300) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `report_schedule_runs_property_id_foreign` (`property_id`),
  KEY `report_schedule_runs_schedule_id_ran_at_index` (`schedule_id`,`ran_at`),
  CONSTRAINT `report_schedule_runs_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `report_schedule_runs_schedule_id_foreign` FOREIGN KEY (`schedule_id`) REFERENCES `report_schedules` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `report_schedule_runs_no_update` BEFORE UPDATE ON `report_schedule_runs` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a run of a schedule cannot be changed' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `report_schedule_runs_no_delete` BEFORE DELETE ON `report_schedule_runs` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a run of a schedule cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `report_schedules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `report_schedules` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `name` varchar(80) NOT NULL,
  `report` varchar(20) NOT NULL,
  `params` json NOT NULL,
  `cadence` varchar(8) NOT NULL,
  `weekday` tinyint unsigned DEFAULT NULL,
  `month_day` tinyint unsigned DEFAULT NULL,
  `at_time` time NOT NULL,
  `notify_email` tinyint(1) NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `next_run_at` timestamp(6) NULL DEFAULT NULL,
  `last_run_at` timestamp(6) NULL DEFAULT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `created_by` char(26) NOT NULL,
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `report_schedules_property_id_is_active_next_run_at_index` (`property_id`,`is_active`,`next_run_at`),
  CONSTRAINT `report_schedules_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_report_schedule` CHECK (((`cadence` in (_utf8mb4'daily',_utf8mb4'weekly',_utf8mb4'monthly')) and ((`cadence` <> _utf8mb4'weekly') or (`weekday` between 1 and 7)) and ((`cadence` <> _utf8mb4'monthly') or (`month_day` between 1 and 28))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `reservation_amendments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `reservation_amendments` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `reservation_id` char(26) NOT NULL,
  `kind` varchar(12) NOT NULL,
  `old_departure` date NOT NULL,
  `new_departure` date NOT NULL,
  `nights` json NOT NULL,
  `added_base_minor` bigint NOT NULL,
  `added_service_charge_minor` bigint NOT NULL,
  `added_tax_minor` bigint NOT NULL,
  `added_total_minor` bigint NOT NULL,
  `business_date` date NOT NULL,
  `reason` varchar(300) NOT NULL,
  `created_by` char(26) NOT NULL,
  `created_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `reservation_amendments_property_id_foreign` (`property_id`),
  KEY `reservation_amendments_created_by_foreign` (`created_by`),
  KEY `reservation_amendments_reservation_id_created_at_index` (`reservation_id`,`created_at`),
  CONSTRAINT `reservation_amendments_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `reservation_amendments_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `reservation_amendments_reservation_id_foreign` FOREIGN KEY (`reservation_id`) REFERENCES `reservations` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_amendments_dates` CHECK ((`new_departure` > `old_departure`)),
  CONSTRAINT `chk_amendments_kind` CHECK ((`kind` = _utf8mb4'extension')),
  CONSTRAINT `chk_amendments_total` CHECK ((`added_total_minor` = ((`added_base_minor` + `added_service_charge_minor`) + `added_tax_minor`)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `reservation_amendments_no_update` BEFORE UPDATE ON `reservation_amendments` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a reservation amendment cannot be changed' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `reservation_amendments_no_delete` BEFORE DELETE ON `reservation_amendments` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a reservation amendment cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `reservation_companies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `reservation_companies` (
  `reservation_id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `company_id` char(26) NOT NULL,
  `linked_by` char(26) NOT NULL,
  `linked_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`reservation_id`),
  KEY `reservation_companies_property_id_foreign` (`property_id`),
  KEY `reservation_companies_company_id_foreign` (`company_id`),
  KEY `reservation_companies_linked_by_foreign` (`linked_by`),
  CONSTRAINT `reservation_companies_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `company_profiles` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `reservation_companies_linked_by_foreign` FOREIGN KEY (`linked_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `reservation_companies_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `reservation_companies_reservation_id_foreign` FOREIGN KEY (`reservation_id`) REFERENCES `reservations` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `reservation_companies_no_update` BEFORE UPDATE ON `reservation_companies` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'the company of a reservation cannot be changed' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `reservation_companies_no_delete` BEFORE DELETE ON `reservation_companies` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'the company of a reservation cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `reservation_external_refs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `reservation_external_refs` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `channel` varchar(30) NOT NULL,
  `external_ref` varchar(60) NOT NULL,
  `reservation_id` char(26) NOT NULL,
  `imported_by` char(26) NOT NULL,
  `created_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `reservation_external_refs_once` (`property_id`,`channel`,`external_ref`),
  KEY `reservation_external_refs_imported_by_foreign` (`imported_by`),
  KEY `reservation_external_refs_property_id_reservation_id_index` (`property_id`,`reservation_id`),
  KEY `reservation_external_refs_reservation_id_foreign` (`reservation_id`),
  CONSTRAINT `reservation_external_refs_imported_by_foreign` FOREIGN KEY (`imported_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `reservation_external_refs_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `reservation_external_refs_reservation_id_foreign` FOREIGN KEY (`reservation_id`) REFERENCES `reservations` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `reservation_external_refs_no_update` BEFORE UPDATE ON `reservation_external_refs` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a channel reference cannot be changed' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `reservation_external_refs_no_delete` BEFORE DELETE ON `reservation_external_refs` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a channel reference cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `reservation_group_members`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `reservation_group_members` (
  `reservation_id` char(26) NOT NULL,
  `group_id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `line` smallint unsigned NOT NULL,
  `added_by` char(26) NOT NULL,
  `added_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`reservation_id`),
  UNIQUE KEY `reservation_group_members_group_id_line_unique` (`group_id`,`line`),
  KEY `reservation_group_members_property_id_foreign` (`property_id`),
  KEY `reservation_group_members_added_by_foreign` (`added_by`),
  CONSTRAINT `reservation_group_members_added_by_foreign` FOREIGN KEY (`added_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `reservation_group_members_group_id_foreign` FOREIGN KEY (`group_id`) REFERENCES `reservation_groups` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `reservation_group_members_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `reservation_group_members_reservation_id_foreign` FOREIGN KEY (`reservation_id`) REFERENCES `reservations` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `reservation_group_members_no_update` BEFORE UPDATE ON `reservation_group_members` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a group membership cannot be changed' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `reservation_group_members_no_delete` BEFORE DELETE ON `reservation_group_members` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a group membership cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `reservation_groups`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `reservation_groups` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `number` varchar(20) NOT NULL,
  `name` varchar(120) NOT NULL,
  `booker_name` varchar(150) NOT NULL,
  `booker_phone` varchar(30) DEFAULT NULL,
  `booker_email` varchar(190) DEFAULT NULL,
  `source` varchar(20) NOT NULL,
  `arrival_date` date NOT NULL,
  `departure_date` date NOT NULL,
  `billing_mode` varchar(8) NOT NULL,
  `route_extras` tinyint(1) NOT NULL DEFAULT '0',
  `notes` varchar(500) DEFAULT NULL,
  `created_by` char(26) NOT NULL,
  `created_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `reservation_groups_property_id_number_unique` (`property_id`,`number`),
  KEY `reservation_groups_created_by_foreign` (`created_by`),
  CONSTRAINT `reservation_groups_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `reservation_groups_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_group_billing` CHECK (((`billing_mode` in (_utf8mb4'master',_utf8mb4'per_room')) and (`departure_date` > `arrival_date`)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `reservation_groups_no_update` BEFORE UPDATE ON `reservation_groups` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a group booking cannot be changed' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `reservation_groups_no_delete` BEFORE DELETE ON `reservation_groups` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a group booking cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `reservation_nights`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `reservation_nights` (
  `reservation_id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `room_type_id` char(26) NOT NULL,
  `night` date NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`reservation_id`,`night`),
  KEY `reservation_nights_room_type_id_foreign` (`room_type_id`),
  KEY `reservation_nights_inventory` (`property_id`,`room_type_id`,`night`,`is_active`),
  CONSTRAINT `reservation_nights_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `reservation_nights_reservation_id_foreign` FOREIGN KEY (`reservation_id`) REFERENCES `reservations` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `reservation_nights_room_type_id_foreign` FOREIGN KEY (`room_type_id`) REFERENCES `room_types` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `reservation_nights_no_delete` BEFORE DELETE ON `reservation_nights` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'reservation_nights cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `reservation_rate_changes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `reservation_rate_changes` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `reservation_id` char(26) NOT NULL,
  `effective_from` date NOT NULL,
  `nights` json NOT NULL,
  `previous_nights` json NOT NULL,
  `old_total_minor` bigint NOT NULL,
  `new_total_minor` bigint NOT NULL,
  `discount_minor` bigint NOT NULL,
  `discount_bp` int unsigned NOT NULL,
  `reason` varchar(300) NOT NULL,
  `approval_id` char(26) DEFAULT NULL,
  `business_date` date NOT NULL,
  `created_by` char(26) NOT NULL,
  `created_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `reservation_rate_changes_property_id_foreign` (`property_id`),
  KEY `reservation_rate_changes_created_by_foreign` (`created_by`),
  KEY `reservation_rate_changes_reservation_id_created_at_index` (`reservation_id`,`created_at`),
  CONSTRAINT `reservation_rate_changes_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `reservation_rate_changes_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `reservation_rate_changes_reservation_id_foreign` FOREIGN KEY (`reservation_id`) REFERENCES `reservations` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_rate_changes_discount` CHECK ((`discount_minor` = (`old_total_minor` - `new_total_minor`))),
  CONSTRAINT `chk_rate_changes_reason` CHECK ((char_length(trim(`reason`)) > 0)),
  CONSTRAINT `chk_rate_changes_totals` CHECK (((`old_total_minor` >= 0) and (`new_total_minor` >= 0)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `reservation_rate_changes_no_update` BEFORE UPDATE ON `reservation_rate_changes` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a rate change cannot be changed' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `reservation_rate_changes_no_delete` BEFORE DELETE ON `reservation_rate_changes` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a rate change cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `reservations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `reservations` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `number` varchar(30) NOT NULL,
  `status` varchar(20) NOT NULL,
  `source` varchar(20) NOT NULL,
  `guest_name` varchar(150) NOT NULL,
  `guest_phone` varchar(30) DEFAULT NULL,
  `guest_email` varchar(190) DEFAULT NULL,
  `arrival_date` date NOT NULL,
  `departure_date` date NOT NULL,
  `adults` tinyint unsigned NOT NULL,
  `children` tinyint unsigned NOT NULL,
  `room_type_id` char(26) NOT NULL,
  `rate_plan_id` char(26) NOT NULL,
  `room_id` char(26) DEFAULT NULL,
  `notes` varchar(1000) DEFAULT NULL,
  `currency_code` char(3) NOT NULL,
  `total_base_minor` bigint NOT NULL,
  `total_service_charge_minor` bigint NOT NULL,
  `total_tax_minor` bigint NOT NULL,
  `total_minor` bigint NOT NULL,
  `price_snapshot` json NOT NULL,
  `oversold` tinyint(1) NOT NULL DEFAULT '0',
  `oversell_reason` varchar(500) DEFAULT NULL,
  `status_reason` varchar(500) DEFAULT NULL,
  `status_changed_at` timestamp(6) NULL DEFAULT NULL,
  `status_changed_by` char(26) DEFAULT NULL,
  `created_by` char(26) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  `policy_snapshot` json DEFAULT NULL,
  `deposit_required_minor` bigint NOT NULL DEFAULT '0',
  `deposit_due_date` date DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `reservations_property_id_number_unique` (`property_id`,`number`),
  KEY `reservations_room_type_id_foreign` (`room_type_id`),
  KEY `reservations_rate_plan_id_foreign` (`rate_plan_id`),
  KEY `reservations_room_id_foreign` (`room_id`),
  KEY `reservations_status_changed_by_foreign` (`status_changed_by`),
  KEY `reservations_created_by_foreign` (`created_by`),
  KEY `reservations_property_id_status_arrival_date_index` (`property_id`,`status`,`arrival_date`),
  KEY `reservations_property_id_arrival_date_index` (`property_id`,`arrival_date`),
  KEY `reservations_property_id_departure_date_index` (`property_id`,`departure_date`),
  CONSTRAINT `reservations_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `reservations_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `reservations_rate_plan_id_foreign` FOREIGN KEY (`rate_plan_id`) REFERENCES `rate_plans` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `reservations_room_id_foreign` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `reservations_room_type_id_foreign` FOREIGN KEY (`room_type_id`) REFERENCES `room_types` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `reservations_status_changed_by_foreign` FOREIGN KEY (`status_changed_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_reservations_dates` CHECK ((`departure_date` > `arrival_date`)),
  CONSTRAINT `chk_reservations_guests` CHECK ((`adults` >= 1)),
  CONSTRAINT `chk_reservations_oversell` CHECK (((`oversold` = 0) or (`oversell_reason` is not null))),
  CONSTRAINT `chk_reservations_reason` CHECK (((`status` not in (_utf8mb4'cancelled',_utf8mb4'no_show')) or (`status_reason` is not null))),
  CONSTRAINT `chk_reservations_source` CHECK ((`source` in (_utf8mb4'direct',_utf8mb4'phone',_utf8mb4'ota',_utf8mb4'corporate',_utf8mb4'walk_in'))),
  CONSTRAINT `chk_reservations_status` CHECK ((`status` in (_utf8mb4'tentative',_utf8mb4'confirmed',_utf8mb4'guaranteed',_utf8mb4'checked_in',_utf8mb4'completed',_utf8mb4'cancelled',_utf8mb4'no_show'))),
  CONSTRAINT `chk_reservations_total` CHECK ((`total_minor` = ((`total_base_minor` + `total_service_charge_minor`) + `total_tax_minor`)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `reservations_snapshot_immutable` BEFORE UPDATE ON `reservations` FOR EACH ROW BEGIN
    IF NOT (NEW.number <=> OLD.number AND NEW.property_id <=> OLD.property_id AND NEW.created_by <=> OLD.created_by
        AND NEW.currency_code <=> OLD.currency_code AND NEW.total_minor <=> OLD.total_minor AND NEW.total_base_minor <=> OLD.total_base_minor
        AND NEW.total_service_charge_minor <=> OLD.total_service_charge_minor AND NEW.total_tax_minor <=> OLD.total_tax_minor
        AND CAST(NEW.price_snapshot AS CHAR) <=> CAST(OLD.price_snapshot AS CHAR)
        AND CAST(NEW.policy_snapshot AS CHAR) <=> CAST(OLD.policy_snapshot AS CHAR)
        AND NEW.deposit_required_minor <=> OLD.deposit_required_minor AND NEW.deposit_due_date <=> OLD.deposit_due_date) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a reservation price snapshot cannot be changed';
    END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `reservations_no_delete` BEFORE DELETE ON `reservations` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'reservations cannot be deleted; cancel instead' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `retention_overrides`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `retention_overrides` (
  `property_id` char(26) NOT NULL,
  `category` varchar(60) NOT NULL,
  `retention_days` int unsigned NOT NULL,
  `updated_by` char(26) NOT NULL,
  `updated_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`property_id`,`category`),
  KEY `retention_overrides_updated_by_foreign` (`updated_by`),
  CONSTRAINT `retention_overrides_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `retention_overrides_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `revenue_outlet_sources`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `revenue_outlet_sources` (
  `property_id` char(26) NOT NULL,
  `source` varchar(40) NOT NULL,
  `outlet_id` char(26) NOT NULL,
  `created_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`property_id`,`source`),
  KEY `revenue_outlet_sources_outlet_id_foreign` (`outlet_id`),
  CONSTRAINT `revenue_outlet_sources_outlet_id_foreign` FOREIGN KEY (`outlet_id`) REFERENCES `revenue_outlets` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `revenue_outlet_sources_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `revenue_outlets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `revenue_outlets` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `code` varchar(20) NOT NULL,
  `name` varchar(60) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `revenue_outlets_property_id_code_unique` (`property_id`,`code`),
  CONSTRAINT `revenue_outlets_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `role_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `role_permissions` (
  `property_id` char(26) NOT NULL,
  `role_id` char(26) NOT NULL,
  `permission_id` char(26) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`role_id`,`permission_id`),
  KEY `role_permissions_permission_id_foreign` (`permission_id`),
  KEY `role_permissions_role_id_property_id_foreign` (`role_id`,`property_id`),
  KEY `role_permissions_property_id_permission_id_role_id_index` (`property_id`,`permission_id`,`role_id`),
  CONSTRAINT `role_permissions_permission_id_foreign` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `role_permissions_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `role_permissions_role_id_property_id_foreign` FOREIGN KEY (`role_id`, `property_id`) REFERENCES `roles` (`id`, `property_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `roles` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `name` varchar(100) NOT NULL,
  `requires_mfa` tinyint(1) NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `roles_property_id_name_unique` (`property_id`,`name`),
  UNIQUE KEY `roles_id_property_id_unique` (`id`,`property_id`),
  KEY `roles_property_id_is_active_index` (`property_id`,`is_active`),
  CONSTRAINT `roles_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `room_blocks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `room_blocks` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `room_id` char(26) NOT NULL,
  `kind` varchar(20) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `reason` varchar(500) NOT NULL,
  `created_by` char(26) NOT NULL,
  `created_at` timestamp(6) NOT NULL,
  `released_at` timestamp(6) NULL DEFAULT NULL,
  `released_by` char(26) DEFAULT NULL,
  `release_reason` varchar(500) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `room_blocks_room_id_foreign` (`room_id`),
  KEY `room_blocks_created_by_foreign` (`created_by`),
  KEY `room_blocks_released_by_foreign` (`released_by`),
  KEY `room_blocks_room_lookup` (`property_id`,`room_id`,`released_at`,`start_date`),
  KEY `room_blocks_range_lookup` (`property_id`,`released_at`,`start_date`,`end_date`),
  CONSTRAINT `room_blocks_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `room_blocks_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `room_blocks_released_by_foreign` FOREIGN KEY (`released_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `room_blocks_room_id_foreign` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_room_blocks_dates` CHECK ((`end_date` >= `start_date`)),
  CONSTRAINT `chk_room_blocks_kind` CHECK ((`kind` in (_utf8mb4'out_of_order',_utf8mb4'out_of_service'))),
  CONSTRAINT `chk_room_blocks_release` CHECK ((((`released_at` is null) and (`released_by` is null) and (`release_reason` is null)) or ((`released_at` is not null) and (`released_by` is not null) and (`release_reason` is not null))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `room_blocks_release_only` BEFORE UPDATE ON `room_blocks` FOR EACH ROW BEGIN
    IF NOT (OLD.released_at IS NULL AND NEW.released_at IS NOT NULL AND NEW.id <=> OLD.id AND NEW.property_id <=> OLD.property_id AND NEW.room_id <=> OLD.room_id AND NEW.kind <=> OLD.kind AND NEW.start_date <=> OLD.start_date AND NEW.end_date <=> OLD.end_date AND NEW.reason <=> OLD.reason AND NEW.created_by <=> OLD.created_by AND NEW.created_at <=> OLD.created_at) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'room_blocks rows can only be released, once';
    END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `room_blocks_no_delete` BEFORE DELETE ON `room_blocks` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'room_blocks cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `room_inspections`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `room_inspections` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `room_id` char(26) NOT NULL,
  `inspector_id` char(26) NOT NULL,
  `result` varchar(8) NOT NULL,
  `notes` varchar(500) DEFAULT NULL,
  `inspected_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `room_inspections_room_id_foreign` (`room_id`),
  KEY `room_inspections_inspector_id_foreign` (`inspector_id`),
  KEY `room_inspections_property_id_room_id_inspected_at_index` (`property_id`,`room_id`,`inspected_at`),
  CONSTRAINT `room_inspections_inspector_id_foreign` FOREIGN KEY (`inspector_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `room_inspections_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `room_inspections_room_id_foreign` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_room_inspections_result` CHECK ((`result` in (_utf8mb4'passed',_utf8mb4'rework')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `room_inspections_no_update` BEFORE UPDATE ON `room_inspections` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'an inspection cannot be changed' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `room_inspections_no_delete` BEFORE DELETE ON `room_inspections` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'an inspection cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `room_plans`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `room_plans` (
  `reservation_id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `room_id` char(26) NOT NULL,
  `planned_by` char(26) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`reservation_id`),
  KEY `room_plans_room_id_foreign` (`room_id`),
  KEY `room_plans_planned_by_foreign` (`planned_by`),
  KEY `room_plans_property_id_room_id_index` (`property_id`,`room_id`),
  CONSTRAINT `room_plans_planned_by_foreign` FOREIGN KEY (`planned_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `room_plans_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `room_plans_reservation_id_foreign` FOREIGN KEY (`reservation_id`) REFERENCES `reservations` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `room_plans_room_id_foreign` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `room_service_flags`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `room_service_flags` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `room_id` char(26) NOT NULL,
  `kind` varchar(16) NOT NULL,
  `note` varchar(200) DEFAULT NULL,
  `started_at` timestamp(6) NOT NULL,
  `started_by` char(26) NOT NULL,
  `ended_at` timestamp(6) NULL DEFAULT NULL,
  `ended_by` char(26) DEFAULT NULL,
  `open_key` varchar(60) DEFAULT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `flags_one_open_per_kind` (`open_key`),
  KEY `room_service_flags_started_by_foreign` (`started_by`),
  KEY `room_service_flags_ended_by_foreign` (`ended_by`),
  KEY `room_service_flags_property_id_ended_at_index` (`property_id`,`ended_at`),
  KEY `room_service_flags_room_id_started_at_index` (`room_id`,`started_at`),
  CONSTRAINT `room_service_flags_ended_by_foreign` FOREIGN KEY (`ended_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `room_service_flags_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `room_service_flags_room_id_foreign` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `room_service_flags_started_by_foreign` FOREIGN KEY (`started_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_flags_ended` CHECK ((((`ended_at` is null) and (`ended_by` is null)) or ((`ended_at` is not null) and (`ended_by` is not null)))),
  CONSTRAINT `chk_flags_kind` CHECK ((`kind` in (_utf8mb4'dnd',_utf8mb4'refused_service',_utf8mb4'make_up_room',_utf8mb4'privacy'))),
  CONSTRAINT `chk_flags_times` CHECK (((`ended_at` is null) or (`ended_at` >= `started_at`)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `room_service_flags_open_key_insert` BEFORE INSERT ON `room_service_flags` FOR EACH ROW BEGIN
    SET NEW.open_key = CASE WHEN NEW.ended_at IS NULL THEN CONCAT(NEW.room_id, NEW.kind) ELSE NULL END;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `room_service_flags_facts_immutable` BEFORE UPDATE ON `room_service_flags` FOR EACH ROW BEGIN
    IF NOT (NEW.id <=> OLD.id AND NEW.property_id <=> OLD.property_id AND NEW.room_id <=> OLD.room_id AND NEW.kind <=> OLD.kind AND NEW.note <=> OLD.note
        AND NEW.started_at <=> OLD.started_at AND NEW.started_by <=> OLD.started_by) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'what a service flag recorded cannot be changed';
    END IF;
    IF OLD.ended_at IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'an ended service flag cannot be changed';
    END IF;
    SET NEW.open_key = CASE WHEN NEW.ended_at IS NULL THEN CONCAT(NEW.room_id, NEW.kind) ELSE NULL END;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `room_service_flags_no_delete` BEFORE DELETE ON `room_service_flags` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a service flag cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `room_type_photos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `room_type_photos` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `room_type_id` char(26) NOT NULL,
  `position` tinyint unsigned NOT NULL,
  `full_image` mediumblob NOT NULL,
  `thumb_image` mediumblob NOT NULL,
  `sha256` char(64) NOT NULL,
  `created_by` char(26) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `room_type_photos_room_type_id_foreign` (`room_type_id`),
  KEY `room_type_photos_created_by_foreign` (`created_by`),
  KEY `room_type_photos_property_id_room_type_id_position_index` (`property_id`,`room_type_id`,`position`),
  CONSTRAINT `room_type_photos_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `room_type_photos_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `room_type_photos_room_type_id_foreign` FOREIGN KEY (`room_type_id`) REFERENCES `room_types` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `room_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `room_types` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `code` varchar(20) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` varchar(500) DEFAULT NULL,
  `max_adults` tinyint unsigned NOT NULL,
  `max_children` tinyint unsigned NOT NULL,
  `sort_order` smallint unsigned NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `room_types_property_id_code_unique` (`property_id`,`code`),
  KEY `room_types_property_id_is_active_sort_order_index` (`property_id`,`is_active`,`sort_order`),
  CONSTRAINT `room_types_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_room_types_occupancy` CHECK (((`max_adults` between 1 and 20) and (`max_children` between 0 and 20)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `room_types_no_delete` BEFORE DELETE ON `room_types` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'room_types cannot be deleted; deactivate instead' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `rooms`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `rooms` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `room_type_id` char(26) NOT NULL,
  `number` varchar(20) NOT NULL,
  `floor` varchar(10) DEFAULT NULL,
  `building` varchar(40) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `rooms_property_id_number_unique` (`property_id`,`number`),
  KEY `rooms_room_type_id_foreign` (`room_type_id`),
  KEY `rooms_property_id_room_type_id_is_active_index` (`property_id`,`room_type_id`,`is_active`),
  CONSTRAINT `rooms_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `rooms_room_type_id_foreign` FOREIGN KEY (`room_type_id`) REFERENCES `room_types` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `rooms_no_delete` BEFORE DELETE ON `rooms` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'rooms cannot be deleted; deactivate instead' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `routine_completions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `routine_completions` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `run_id` char(26) NOT NULL,
  `item_id` varchar(20) NOT NULL,
  `note` varchar(300) DEFAULT NULL,
  `completed_by` char(26) NOT NULL,
  `completed_at` timestamp(6) NOT NULL,
  `business_date` date NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `routine_completions_run_id_item_id_unique` (`run_id`,`item_id`),
  KEY `routine_completions_property_id_completed_by_business_date_index` (`property_id`,`completed_by`,`business_date`),
  CONSTRAINT `routine_completions_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `routine_completions_run_id_foreign` FOREIGN KEY (`run_id`) REFERENCES `routine_runs` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `routine_completions_no_update` BEFORE UPDATE ON `routine_completions` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a completed checklist item cannot be changed' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `routine_completions_no_delete` BEFORE DELETE ON `routine_completions` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a completed checklist item cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `routine_runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `routine_runs` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `department` varchar(12) NOT NULL,
  `template_id` char(26) NOT NULL,
  `name` varchar(80) NOT NULL,
  `period_key` varchar(10) NOT NULL,
  `period_start` date NOT NULL,
  `period_end` date NOT NULL,
  `items` json NOT NULL,
  `created_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `routine_runs_property_id_department_name_period_key_unique` (`property_id`,`department`,`name`,`period_key`),
  KEY `routine_runs_template_id_foreign` (`template_id`),
  CONSTRAINT `routine_runs_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `routine_runs_template_id_foreign` FOREIGN KEY (`template_id`) REFERENCES `routine_templates` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `routine_runs_no_update` BEFORE UPDATE ON `routine_runs` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a checklist run cannot be changed' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `routine_runs_no_delete` BEFORE DELETE ON `routine_runs` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a checklist run cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `routine_temperature_points`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `routine_temperature_points` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `department` varchar(12) NOT NULL,
  `name` varchar(60) NOT NULL,
  `min_tenth` smallint NOT NULL,
  `max_tenth` smallint NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `routine_temperature_points_property_id_department_name_unique` (`property_id`,`department`,`name`),
  CONSTRAINT `routine_temperature_points_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_routine_temperature_points` CHECK (((`min_tenth` < `max_tenth`) and (`min_tenth` >= -(600)) and (`max_tenth` <= 1500)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `routine_temperature_readings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `routine_temperature_readings` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `point_id` char(26) NOT NULL,
  `department` varchar(12) NOT NULL,
  `value_tenth` smallint NOT NULL,
  `min_tenth` smallint NOT NULL,
  `max_tenth` smallint NOT NULL,
  `in_range` tinyint(1) NOT NULL,
  `action_taken` varchar(200) DEFAULT NULL,
  `recorded_by` char(26) NOT NULL,
  `business_date` date NOT NULL,
  `recorded_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `routine_readings_dept_time` (`property_id`,`department`,`recorded_at`),
  KEY `routine_temperature_readings_point_id_recorded_at_index` (`point_id`,`recorded_at`),
  CONSTRAINT `routine_temperature_readings_point_id_foreign` FOREIGN KEY (`point_id`) REFERENCES `routine_temperature_points` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `routine_temperature_readings_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_routine_temperature_readings` CHECK (((`in_range` = 1) or (`action_taken` is not null)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `routine_temperature_readings_no_update` BEFORE UPDATE ON `routine_temperature_readings` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a temperature reading cannot be changed' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `routine_temperature_readings_no_delete` BEFORE DELETE ON `routine_temperature_readings` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a temperature reading cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `routine_templates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `routine_templates` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `department` varchar(12) NOT NULL,
  `name` varchar(80) NOT NULL,
  `version` int unsigned NOT NULL,
  `frequency` varchar(8) NOT NULL,
  `items` json NOT NULL,
  `is_active` tinyint(1) NOT NULL,
  `created_by` char(26) NOT NULL,
  `created_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `routine_templates_property_id_department_name_version_unique` (`property_id`,`department`,`name`,`version`),
  KEY `routine_templates_property_id_department_is_active_index` (`property_id`,`department`,`is_active`),
  CONSTRAINT `routine_templates_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_routine_templates` CHECK (((`frequency` in (_utf8mb4'daily',_utf8mb4'weekly',_utf8mb4'monthly')) and (`department` in (_utf8mb4'kitchen',_utf8mb4'fnb'))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `routine_templates_no_update` BEFORE UPDATE ON `routine_templates` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a checklist template cannot be changed' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `routine_templates_no_delete` BEFORE DELETE ON `routine_templates` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a checklist template cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `security_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `security_events` (
  `id` char(26) NOT NULL,
  `property_id` char(26) DEFAULT NULL,
  `actor_id` char(26) DEFAULT NULL,
  `event_type` varchar(120) NOT NULL,
  `outcome` varchar(16) NOT NULL,
  `source_ip_hash` char(64) DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `correlation_id` char(26) NOT NULL,
  `occurred_at` timestamp(6) NOT NULL,
  `minimum_retention_until` timestamp(6) NULL DEFAULT NULL,
  `payload_checksum` char(64) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `security_events_property_id_occurred_at_index` (`property_id`,`occurred_at`),
  KEY `security_events_actor_id_occurred_at_index` (`actor_id`,`occurred_at`),
  KEY `security_events_event_type_outcome_occurred_at_index` (`event_type`,`outcome`,`occurred_at`),
  KEY `security_events_correlation_id_index` (`correlation_id`),
  KEY `security_events_minimum_retention_until_index` (`minimum_retention_until`),
  CONSTRAINT `security_events_actor_id_foreign` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `security_events_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_security_event_outcome` CHECK ((`outcome` in (_utf8mb4'success',_utf8mb4'failure',_utf8mb4'denied')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `security_events_prevent_update` BEFORE UPDATE ON `security_events` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'immutable evidence cannot be updated' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `security_events_prevent_delete` BEFORE DELETE ON `security_events` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'immutable evidence cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sessions` (
  `id` varchar(255) NOT NULL,
  `user_id` char(26) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text,
  `payload` longtext NOT NULL,
  `last_activity` int NOT NULL,
  PRIMARY KEY (`id`),
  KEY `sessions_user_id_index` (`user_id`),
  KEY `sessions_last_activity_index` (`last_activity`),
  CONSTRAINT `sessions_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `shift_log_entries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `shift_log_entries` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `business_date` date NOT NULL,
  `shift` varchar(9) NOT NULL,
  `priority` varchar(9) NOT NULL,
  `body` varchar(2000) NOT NULL,
  `author_id` char(26) NOT NULL,
  `created_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `shift_log_entries_author_id_foreign` (`author_id`),
  KEY `shift_log_entries_property_id_created_at_index` (`property_id`,`created_at`),
  CONSTRAINT `shift_log_entries_author_id_foreign` FOREIGN KEY (`author_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `shift_log_entries_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_shift_log_body` CHECK ((char_length(trim(`body`)) > 0)),
  CONSTRAINT `chk_shift_log_priority` CHECK ((`priority` in (_utf8mb4'normal',_utf8mb4'important'))),
  CONSTRAINT `chk_shift_log_shift` CHECK ((`shift` in (_utf8mb4'morning',_utf8mb4'afternoon',_utf8mb4'night')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `shift_log_entries_no_update` BEFORE UPDATE ON `shift_log_entries` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a log entry cannot be changed' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `shift_log_entries_no_delete` BEFORE DELETE ON `shift_log_entries` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a log entry cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `shift_log_reads`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `shift_log_reads` (
  `entry_id` char(26) NOT NULL,
  `user_id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `read_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`entry_id`,`user_id`),
  KEY `shift_log_reads_user_id_foreign` (`user_id`),
  KEY `shift_log_reads_property_id_foreign` (`property_id`),
  CONSTRAINT `shift_log_reads_entry_id_foreign` FOREIGN KEY (`entry_id`) REFERENCES `shift_log_entries` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `shift_log_reads_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `shift_log_reads_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `shift_log_reads_no_update` BEFORE UPDATE ON `shift_log_reads` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a read mark cannot be changed' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `shift_log_reads_no_delete` BEFORE DELETE ON `shift_log_reads` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a read mark cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `sop_completions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sop_completions` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `run_id` char(26) NOT NULL,
  `item_id` varchar(20) NOT NULL,
  `note` varchar(300) DEFAULT NULL,
  `completed_by` char(26) NOT NULL,
  `completed_at` timestamp(6) NOT NULL,
  `business_date` date NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `sop_completions_run_id_item_id_unique` (`run_id`,`item_id`),
  KEY `sop_completions_completed_by_foreign` (`completed_by`),
  KEY `sop_completions_property_id_completed_by_business_date_index` (`property_id`,`completed_by`,`business_date`),
  CONSTRAINT `sop_completions_completed_by_foreign` FOREIGN KEY (`completed_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `sop_completions_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `sop_completions_run_id_foreign` FOREIGN KEY (`run_id`) REFERENCES `sop_runs` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `sop_completions_no_update` BEFORE UPDATE ON `sop_completions` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a completed checklist item cannot be changed' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `sop_completions_no_delete` BEFORE DELETE ON `sop_completions` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a completed checklist item cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `sop_runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sop_runs` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `template_id` char(26) NOT NULL,
  `name` varchar(80) NOT NULL,
  `period_key` varchar(10) NOT NULL,
  `period_start` date NOT NULL,
  `period_end` date NOT NULL,
  `items` json NOT NULL,
  `created_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `sop_runs_property_id_name_period_key_unique` (`property_id`,`name`,`period_key`),
  KEY `sop_runs_template_id_foreign` (`template_id`),
  CONSTRAINT `sop_runs_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `sop_runs_template_id_foreign` FOREIGN KEY (`template_id`) REFERENCES `sop_templates` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `sop_runs_no_update` BEFORE UPDATE ON `sop_runs` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a checklist run cannot be changed' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `sop_runs_no_delete` BEFORE DELETE ON `sop_runs` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a checklist run cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `sop_templates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sop_templates` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `name` varchar(80) NOT NULL,
  `version` int unsigned NOT NULL,
  `frequency` varchar(8) NOT NULL,
  `items` json NOT NULL,
  `is_active` tinyint(1) NOT NULL,
  `created_by` char(26) NOT NULL,
  `created_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `sop_templates_property_id_name_version_unique` (`property_id`,`name`,`version`),
  KEY `sop_templates_created_by_foreign` (`created_by`),
  KEY `sop_templates_property_id_is_active_frequency_index` (`property_id`,`is_active`,`frequency`),
  CONSTRAINT `sop_templates_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `sop_templates_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_sop_frequency` CHECK ((`frequency` in (_utf8mb4'daily',_utf8mb4'weekly',_utf8mb4'monthly')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `sop_templates_no_update` BEFORE UPDATE ON `sop_templates` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a checklist template cannot be changed' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `sop_templates_no_delete` BEFORE DELETE ON `sop_templates` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a checklist template cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `spa_attendance`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `spa_attendance` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `therapist_id` char(26) NOT NULL,
  `business_date` date NOT NULL,
  `clock_in_at` timestamp(6) NOT NULL,
  `clock_out_at` timestamp(6) NULL DEFAULT NULL,
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `spa_attendance_day` (`therapist_id`,`business_date`),
  KEY `spa_attendance_property_id_business_date_index` (`property_id`,`business_date`),
  CONSTRAINT `spa_attendance_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `spa_bookings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `spa_bookings` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `number` varchar(20) NOT NULL,
  `room_id` char(26) NOT NULL,
  `room_name` varchar(60) NOT NULL,
  `treatment_id` char(26) NOT NULL,
  `treatment_name` varchar(80) NOT NULL,
  `minutes` smallint unsigned NOT NULL,
  `therapist_id` char(26) NOT NULL,
  `therapist_name` varchar(80) NOT NULL,
  `booking_date` date NOT NULL,
  `start_minute` smallint unsigned NOT NULL,
  `guest_name` varchar(120) NOT NULL,
  `reservation_id` char(26) DEFAULT NULL,
  `guest_room` varchar(20) DEFAULT NULL,
  `price_minor` bigint unsigned NOT NULL,
  `waive_service_charge` tinyint(1) NOT NULL DEFAULT '0',
  `waive_tax` tinyint(1) NOT NULL DEFAULT '0',
  `service_charge_minor` bigint NOT NULL DEFAULT '0',
  `tax_minor` bigint NOT NULL DEFAULT '0',
  `total_minor` bigint NOT NULL DEFAULT '0',
  `status` varchar(12) NOT NULL DEFAULT 'booked',
  `payment_method` varchar(8) DEFAULT NULL,
  `payment_reference` varchar(60) DEFAULT NULL,
  `shift_id` char(26) DEFAULT NULL,
  `paid_on` date DEFAULT NULL,
  `folio_posting_id` char(26) DEFAULT NULL,
  `commission_bp` smallint unsigned NOT NULL,
  `commission_minor` bigint NOT NULL DEFAULT '0',
  `started_at` timestamp(6) NULL DEFAULT NULL,
  `ended_at` timestamp(6) NULL DEFAULT NULL,
  `reason` varchar(200) DEFAULT NULL,
  `created_by` char(26) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `spa_bookings_property_id_number_unique` (`property_id`,`number`),
  KEY `spa_bookings_property_id_booking_date_status_index` (`property_id`,`booking_date`,`status`),
  KEY `spa_bookings_property_id_room_id_booking_date_index` (`property_id`,`room_id`,`booking_date`),
  KEY `spa_bookings_property_id_therapist_id_booking_date_index` (`property_id`,`therapist_id`,`booking_date`),
  CONSTRAINT `spa_bookings_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_spa_bookings` CHECK (((`status` in (_utf8mb4'booked',_utf8mb4'accepted',_utf8mb4'declined',_utf8mb4'in_progress',_utf8mb4'done',_utf8mb4'cancelled',_utf8mb4'no_show')) and (`start_minute` between 0 and 1439) and (`minutes` between 5 and 480) and (`commission_bp` <= 10000)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `spa_bookings_no_delete` BEFORE DELETE ON `spa_bookings` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a spa booking cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `spa_rooms`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `spa_rooms` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `code` varchar(12) NOT NULL,
  `name` varchar(60) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `spa_rooms_property_id_code_unique` (`property_id`,`code`),
  CONSTRAINT `spa_rooms_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `spa_therapists`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `spa_therapists` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `name` varchar(80) NOT NULL,
  `user_id` char(26) DEFAULT NULL,
  `commission_bp` smallint unsigned NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `spa_therapists_property_id_name_unique` (`property_id`,`name`),
  KEY `spa_therapists_property_id_user_id_index` (`property_id`,`user_id`),
  CONSTRAINT `spa_therapists_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_spa_therapists` CHECK ((`commission_bp` between 0 and 10000))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `spa_treatments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `spa_treatments` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `name` varchar(80) NOT NULL,
  `minutes` smallint unsigned NOT NULL,
  `price_minor` bigint unsigned NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `spa_treatments_property_id_name_unique` (`property_id`,`name`),
  CONSTRAINT `spa_treatments_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_spa_treatments` CHECK (((`minutes` between 5 and 480) and (`price_minor` > 0)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `stay_laundry_exceptions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `stay_laundry_exceptions` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `stay_id` char(26) NOT NULL,
  `reservation_id` char(26) NOT NULL,
  `mode` varchar(12) NOT NULL,
  `reason` varchar(300) NOT NULL,
  `approval_id` char(26) NOT NULL,
  `order_ids` text NOT NULL,
  `created_by` char(26) NOT NULL,
  `created_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `stay_laundry_exceptions_property_id_stay_id_unique` (`property_id`,`stay_id`),
  KEY `stay_laundry_exceptions_created_by_foreign` (`created_by`),
  KEY `stay_laundry_exceptions_property_id_reservation_id_index` (`property_id`,`reservation_id`),
  CONSTRAINT `stay_laundry_exceptions_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `stay_laundry_exceptions_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_stay_laundry_exception_mode` CHECK ((`mode` in (_utf8mb4'late_charge',_utf8mb4'claim')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `stay_laundry_exceptions_no_update` BEFORE UPDATE ON `stay_laundry_exceptions` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a laundry exception at check-out cannot be changed' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `stay_laundry_exceptions_no_delete` BEFORE DELETE ON `stay_laundry_exceptions` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a laundry exception at check-out cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `stay_room_moves`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `stay_room_moves` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `stay_id` char(26) NOT NULL,
  `from_room_id` char(26) NOT NULL,
  `to_room_id` char(26) NOT NULL,
  `from_type_id` char(26) NOT NULL,
  `to_type_id` char(26) NOT NULL,
  `business_date` date NOT NULL,
  `reason` varchar(300) NOT NULL,
  `moved_by` char(26) NOT NULL,
  `moved_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `stay_room_moves_property_id_foreign` (`property_id`),
  KEY `stay_room_moves_from_room_id_foreign` (`from_room_id`),
  KEY `stay_room_moves_to_room_id_foreign` (`to_room_id`),
  KEY `stay_room_moves_moved_by_foreign` (`moved_by`),
  KEY `stay_room_moves_stay_id_moved_at_index` (`stay_id`,`moved_at`),
  CONSTRAINT `stay_room_moves_from_room_id_foreign` FOREIGN KEY (`from_room_id`) REFERENCES `rooms` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `stay_room_moves_moved_by_foreign` FOREIGN KEY (`moved_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `stay_room_moves_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `stay_room_moves_stay_id_foreign` FOREIGN KEY (`stay_id`) REFERENCES `stays` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `stay_room_moves_to_room_id_foreign` FOREIGN KEY (`to_room_id`) REFERENCES `rooms` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_stay_moves_rooms` CHECK ((`from_room_id` <> `to_room_id`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `stay_room_moves_no_update` BEFORE UPDATE ON `stay_room_moves` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a room move cannot be changed' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `stay_room_moves_no_delete` BEFORE DELETE ON `stay_room_moves` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a room move cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `stay_time_fees`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `stay_time_fees` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `stay_id` char(26) NOT NULL,
  `kind` varchar(14) NOT NULL,
  `status` varchar(7) NOT NULL,
  `minutes` int unsigned NOT NULL,
  `percent_bp` smallint unsigned NOT NULL,
  `night_base_minor` bigint NOT NULL,
  `fee_base_minor` bigint NOT NULL,
  `policy_id` char(26) NOT NULL,
  `posting_id` char(26) DEFAULT NULL,
  `reason` varchar(300) DEFAULT NULL,
  `decided_by` char(26) NOT NULL,
  `decided_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `stay_time_fees_stay_id_kind_unique` (`stay_id`,`kind`),
  KEY `stay_time_fees_property_id_foreign` (`property_id`),
  KEY `stay_time_fees_policy_id_foreign` (`policy_id`),
  KEY `stay_time_fees_decided_by_foreign` (`decided_by`),
  CONSTRAINT `stay_time_fees_decided_by_foreign` FOREIGN KEY (`decided_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `stay_time_fees_policy_id_foreign` FOREIGN KEY (`policy_id`) REFERENCES `stay_time_policies` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `stay_time_fees_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `stay_time_fees_stay_id_foreign` FOREIGN KEY (`stay_id`) REFERENCES `stays` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_stay_time_fee` CHECK (((`kind` in (_utf8mb4'early_checkin',_utf8mb4'late_checkout')) and (((`status` = _utf8mb4'charged') and (`posting_id` is not null)) or ((`status` = _utf8mb4'waived') and (`reason` is not null) and (`posting_id` is null)))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `stay_time_fees_no_update` BEFORE UPDATE ON `stay_time_fees` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a stay time fee decision cannot be changed' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `stay_time_fees_no_delete` BEFORE DELETE ON `stay_time_fees` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a stay time fee decision cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `stay_time_policies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `stay_time_policies` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `kind` varchar(14) NOT NULL,
  `effective_from` date NOT NULL,
  `grace_minutes` smallint unsigned NOT NULL,
  `bands` json NOT NULL,
  `beyond_bp` smallint unsigned NOT NULL,
  `reason` varchar(300) NOT NULL,
  `created_by` char(26) NOT NULL,
  `created_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `stay_time_policy_unique` (`property_id`,`kind`,`effective_from`),
  KEY `stay_time_policies_created_by_foreign` (`created_by`),
  CONSTRAINT `stay_time_policies_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `stay_time_policies_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_stay_time_policy` CHECK (((`kind` in (_utf8mb4'early_checkin',_utf8mb4'late_checkout')) and (`beyond_bp` <= 10000)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `stay_time_policies_no_update` BEFORE UPDATE ON `stay_time_policies` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a stay time policy cannot be changed' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `stay_time_policies_no_delete` BEFORE DELETE ON `stay_time_policies` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a stay time policy cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `stays`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `stays` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `reservation_id` char(26) NOT NULL,
  `guest_id` char(26) NOT NULL,
  `room_id` char(26) NOT NULL,
  `status` varchar(12) NOT NULL,
  `adults` tinyint unsigned NOT NULL,
  `children` tinyint unsigned NOT NULL,
  `checked_in_business_date` date NOT NULL,
  `checked_in_at` timestamp(6) NOT NULL,
  `checked_in_by` char(26) NOT NULL,
  `expected_departure` date NOT NULL,
  `checked_out_business_date` date DEFAULT NULL,
  `checked_out_at` timestamp(6) NULL DEFAULT NULL,
  `checked_out_by` char(26) DEFAULT NULL,
  `id_photo_file_id` char(26) DEFAULT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  `in_house_room_key` char(26) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `stays_reservation_id_unique` (`reservation_id`),
  UNIQUE KEY `stays_one_in_house_per_room` (`in_house_room_key`),
  KEY `stays_guest_id_foreign` (`guest_id`),
  KEY `stays_room_id_foreign` (`room_id`),
  KEY `stays_checked_in_by_foreign` (`checked_in_by`),
  KEY `stays_checked_out_by_foreign` (`checked_out_by`),
  KEY `stays_property_id_status_expected_departure_index` (`property_id`,`status`,`expected_departure`),
  KEY `stays_property_id_room_id_status_index` (`property_id`,`room_id`,`status`),
  CONSTRAINT `stays_checked_in_by_foreign` FOREIGN KEY (`checked_in_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `stays_checked_out_by_foreign` FOREIGN KEY (`checked_out_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `stays_guest_id_foreign` FOREIGN KEY (`guest_id`) REFERENCES `guests` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `stays_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `stays_reservation_id_foreign` FOREIGN KEY (`reservation_id`) REFERENCES `reservations` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `stays_room_id_foreign` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_stays_dates` CHECK ((`expected_departure` > `checked_in_business_date`)),
  CONSTRAINT `chk_stays_out` CHECK ((((`status` = _utf8mb4'in_house') and (`checked_out_at` is null) and (`checked_out_by` is null) and (`checked_out_business_date` is null)) or ((`status` = _utf8mb4'checked_out') and (`checked_out_at` is not null) and (`checked_out_by` is not null) and (`checked_out_business_date` is not null)))),
  CONSTRAINT `chk_stays_status` CHECK ((`status` in (_utf8mb4'in_house',_utf8mb4'checked_out')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `stays_in_house_key_insert` BEFORE INSERT ON `stays` FOR EACH ROW BEGIN
    SET NEW.in_house_room_key = CASE WHEN NEW.status = 'in_house' THEN NEW.room_id ELSE NULL END;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `stays_facts_immutable` BEFORE UPDATE ON `stays` FOR EACH ROW BEGIN
    IF NOT (NEW.id <=> OLD.id AND NEW.property_id <=> OLD.property_id AND NEW.reservation_id <=> OLD.reservation_id
        AND NEW.checked_in_at <=> OLD.checked_in_at AND NEW.checked_in_by <=> OLD.checked_in_by AND NEW.checked_in_business_date <=> OLD.checked_in_business_date) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'the facts of a check-in cannot be changed';
    END IF;
    IF OLD.status = 'checked_out' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a completed stay cannot be changed';
    END IF;
    SET NEW.in_house_room_key = CASE WHEN NEW.status = 'in_house' THEN NEW.room_id ELSE NULL END;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `stays_no_delete` BEFORE DELETE ON `stays` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'stays cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `stock_count_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `stock_count_lines` (
  `id` char(26) NOT NULL,
  `count_id` char(26) NOT NULL,
  `item_id` char(26) NOT NULL,
  `snapshot_qty_milli` bigint NOT NULL,
  `counted_unit` varchar(8) DEFAULT NULL,
  `counted_unit_qty_milli` bigint unsigned DEFAULT NULL,
  `counted_conversion_id` char(26) DEFAULT NULL,
  `counted_factor_milli` bigint unsigned DEFAULT NULL,
  `counted_base_milli` bigint unsigned DEFAULT NULL,
  `variance_milli` bigint DEFAULT NULL,
  `reason_code` varchar(16) DEFAULT NULL,
  `note` varchar(200) DEFAULT NULL,
  `movement_id` char(26) DEFAULT NULL,
  `value_minor` bigint DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `stock_count_lines_count_id_item_id_unique` (`count_id`,`item_id`),
  KEY `stock_count_lines_item_id_foreign` (`item_id`),
  CONSTRAINT `stock_count_lines_count_id_foreign` FOREIGN KEY (`count_id`) REFERENCES `stock_counts` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `stock_count_lines_item_id_foreign` FOREIGN KEY (`item_id`) REFERENCES `inventory_items` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_stock_count_line` CHECK ((((`counted_base_milli` is null) = (`counted_unit` is null)) and ((`counted_base_milli` is null) = (`counted_factor_milli` is null)) and ((`counted_base_milli` is null) = (`counted_unit_qty_milli` is null))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `stock_count_lines_final` BEFORE UPDATE ON `stock_count_lines` FOR EACH ROW BEGIN
    IF (SELECT status FROM stock_counts WHERE id = OLD.count_id) IN ('approved', 'cancelled') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a line of a decided stock count cannot be changed'; END IF;
    IF NEW.item_id <> OLD.item_id OR NEW.snapshot_qty_milli <> OLD.snapshot_qty_milli OR NEW.count_id <> OLD.count_id THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'the snapshot of a stock count line cannot be changed'; END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `stock_count_lines_no_delete` BEFORE DELETE ON `stock_count_lines` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a stock count line cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `stock_counts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `stock_counts` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `number` varchar(20) NOT NULL,
  `location_id` char(26) NOT NULL,
  `category_id` char(26) DEFAULT NULL,
  `kind` varchar(10) NOT NULL,
  `status` varchar(10) NOT NULL DEFAULT 'counting',
  `scheduled_for` date DEFAULT NULL,
  `note` varchar(200) DEFAULT NULL,
  `started_by` char(26) NOT NULL,
  `started_at` timestamp(6) NOT NULL,
  `submitted_by` char(26) DEFAULT NULL,
  `submitted_at` timestamp(6) NULL DEFAULT NULL,
  `decided_by` char(26) DEFAULT NULL,
  `decided_at` timestamp(6) NULL DEFAULT NULL,
  `decision_note` varchar(200) DEFAULT NULL,
  `business_date` date NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `stock_counts_property_id_number_unique` (`property_id`,`number`),
  KEY `stock_counts_category_id_foreign` (`category_id`),
  KEY `stock_counts_started_by_foreign` (`started_by`),
  KEY `stock_counts_submitted_by_foreign` (`submitted_by`),
  KEY `stock_counts_decided_by_foreign` (`decided_by`),
  KEY `stock_counts_property_id_status_index` (`property_id`,`status`),
  KEY `stock_counts_location_id_status_index` (`location_id`,`status`),
  CONSTRAINT `stock_counts_category_id_foreign` FOREIGN KEY (`category_id`) REFERENCES `inventory_categories` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `stock_counts_decided_by_foreign` FOREIGN KEY (`decided_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `stock_counts_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `inventory_locations` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `stock_counts_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `stock_counts_started_by_foreign` FOREIGN KEY (`started_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `stock_counts_submitted_by_foreign` FOREIGN KEY (`submitted_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_stock_count` CHECK (((`kind` in (_utf8mb4'scheduled',_utf8mb4'spot')) and (`status` in (_utf8mb4'counting',_utf8mb4'submitted',_utf8mb4'approved',_utf8mb4'cancelled')) and ((`status` not in (_utf8mb4'submitted',_utf8mb4'approved')) or ((`submitted_by` is not null) and (`submitted_at` is not null))) and (((`status` in (_utf8mb4'approved',_utf8mb4'cancelled')) and (`decided_by` is not null) and (`decided_at` is not null)) or ((`status` in (_utf8mb4'counting',_utf8mb4'submitted')) and (`decided_by` is null) and (`decided_at` is null)))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `stock_counts_final` BEFORE UPDATE ON `stock_counts` FOR EACH ROW BEGIN
    IF OLD.status IN ('approved', 'cancelled') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a decided stock count cannot be changed'; END IF;
    IF NEW.number <> OLD.number OR NEW.location_id <> OLD.location_id OR NEW.kind <> OLD.kind OR NEW.started_by <> OLD.started_by OR NEW.started_at <> OLD.started_at OR NEW.business_date <> OLD.business_date OR NOT (NEW.category_id <=> OLD.category_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'the facts of a stock count cannot be changed';
    END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `stock_counts_no_delete` BEFORE DELETE ON `stock_counts` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a stock count cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `stock_movements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `stock_movements` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `item_id` char(26) NOT NULL,
  `location_id` char(26) NOT NULL,
  `kind` varchar(16) NOT NULL,
  `reason_code` varchar(16) DEFAULT NULL,
  `unit` varchar(8) NOT NULL,
  `unit_qty_milli` bigint NOT NULL,
  `conversion_id` char(26) DEFAULT NULL,
  `factor_milli` bigint unsigned NOT NULL,
  `base_qty_milli` bigint NOT NULL,
  `value_minor` bigint NOT NULL DEFAULT '0',
  `unit_cost_minor` bigint unsigned DEFAULT NULL,
  `reference` varchar(40) DEFAULT NULL,
  `source_type` varchar(24) DEFAULT NULL,
  `source_ref` varchar(64) DEFAULT NULL,
  `transfer_id` char(26) DEFAULT NULL,
  `note` varchar(200) DEFAULT NULL,
  `override_reason` varchar(200) DEFAULT NULL,
  `business_date` date NOT NULL,
  `posted_by` char(26) NOT NULL,
  `created_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `stock_movements_source_once` (`property_id`,`source_type`,`source_ref`,`item_id`,`location_id`),
  KEY `stock_movements_item_id_foreign` (`item_id`),
  KEY `stock_movements_location_id_foreign` (`location_id`),
  KEY `stock_movements_posted_by_foreign` (`posted_by`),
  KEY `stock_movements_property_id_item_id_location_id_index` (`property_id`,`item_id`,`location_id`),
  KEY `stock_movements_property_id_created_at_index` (`property_id`,`created_at`),
  KEY `stock_movements_transfer_id_index` (`transfer_id`),
  CONSTRAINT `stock_movements_item_id_foreign` FOREIGN KEY (`item_id`) REFERENCES `inventory_items` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `stock_movements_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `inventory_locations` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `stock_movements_posted_by_foreign` FOREIGN KEY (`posted_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `stock_movements_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_stock_movement` CHECK (((`unit_qty_milli` <> 0) and (`base_qty_milli` <> 0) and (`factor_milli` >= 1) and (((`kind` in (_utf8mb4'opening',_utf8mb4'receipt',_utf8mb4'adjustment_in',_utf8mb4'transfer_in')) and (`unit_qty_milli` > 0) and (`base_qty_milli` > 0)) or ((`kind` in (_utf8mb4'issue',_utf8mb4'adjustment_out',_utf8mb4'write_off',_utf8mb4'transfer_out',_utf8mb4'return_out')) and (`unit_qty_milli` < 0) and (`base_qty_milli` < 0))) and ((`kind` not in (_utf8mb4'adjustment_in',_utf8mb4'adjustment_out',_utf8mb4'write_off')) or (`reason_code` is not null)) and ((`source_type` is null) = (`source_ref` is null)))),
  CONSTRAINT `chk_stock_value` CHECK ((((`base_qty_milli` > 0) and (`value_minor` >= 0)) or ((`base_qty_milli` < 0) and (`value_minor` <= 0))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `stock_movements_no_update` BEFORE UPDATE ON `stock_movements` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a stock movement cannot be changed' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `stock_movements_no_delete` BEFORE DELETE ON `stock_movements` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a stock movement cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `stock_transfer_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `stock_transfer_lines` (
  `id` char(26) NOT NULL,
  `transfer_id` char(26) NOT NULL,
  `item_id` char(26) NOT NULL,
  `unit` varchar(8) NOT NULL,
  `unit_qty_milli` bigint unsigned NOT NULL,
  `conversion_id` char(26) DEFAULT NULL,
  `factor_milli` bigint unsigned NOT NULL,
  `base_qty_milli` bigint unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `stock_transfer_lines_transfer_id_item_id_unique` (`transfer_id`,`item_id`),
  KEY `stock_transfer_lines_item_id_foreign` (`item_id`),
  CONSTRAINT `stock_transfer_lines_item_id_foreign` FOREIGN KEY (`item_id`) REFERENCES `inventory_items` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `stock_transfer_lines_transfer_id_foreign` FOREIGN KEY (`transfer_id`) REFERENCES `stock_transfers` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_stock_transfer_line` CHECK (((`unit_qty_milli` > 0) and (`base_qty_milli` > 0) and (`factor_milli` >= 1)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `stock_transfer_lines_no_update` BEFORE UPDATE ON `stock_transfer_lines` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a stock transfer line cannot be changed' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `stock_transfer_lines_no_delete` BEFORE DELETE ON `stock_transfer_lines` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a stock transfer line cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `stock_transfers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `stock_transfers` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `number` varchar(20) NOT NULL,
  `return_of` char(26) DEFAULT NULL,
  `from_location_id` char(26) NOT NULL,
  `to_location_id` char(26) NOT NULL,
  `status` varchar(10) NOT NULL DEFAULT 'sent',
  `note` varchar(200) DEFAULT NULL,
  `created_by` char(26) NOT NULL,
  `decided_by` char(26) DEFAULT NULL,
  `decided_at` timestamp(6) NULL DEFAULT NULL,
  `decision_note` varchar(200) DEFAULT NULL,
  `business_date` date NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `stock_transfers_property_id_number_unique` (`property_id`,`number`),
  KEY `stock_transfers_from_location_id_foreign` (`from_location_id`),
  KEY `stock_transfers_to_location_id_foreign` (`to_location_id`),
  KEY `stock_transfers_created_by_foreign` (`created_by`),
  KEY `stock_transfers_decided_by_foreign` (`decided_by`),
  KEY `stock_transfers_property_id_status_index` (`property_id`,`status`),
  KEY `stock_transfers_return_of_index` (`return_of`),
  CONSTRAINT `stock_transfers_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `stock_transfers_decided_by_foreign` FOREIGN KEY (`decided_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `stock_transfers_from_location_id_foreign` FOREIGN KEY (`from_location_id`) REFERENCES `inventory_locations` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `stock_transfers_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `stock_transfers_to_location_id_foreign` FOREIGN KEY (`to_location_id`) REFERENCES `inventory_locations` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_stock_transfer` CHECK (((`from_location_id` <> `to_location_id`) and (`status` in (_utf8mb4'sent',_utf8mb4'received',_utf8mb4'rejected',_utf8mb4'cancelled')) and (((`status` = _utf8mb4'sent') and (`decided_by` is null) and (`decided_at` is null)) or ((`status` <> _utf8mb4'sent') and (`decided_by` is not null) and (`decided_at` is not null)))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `stock_transfers_decide_once` BEFORE UPDATE ON `stock_transfers` FOR EACH ROW BEGIN
    IF OLD.status <> 'sent' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a decided stock transfer cannot be changed'; END IF;
    IF NEW.number <> OLD.number OR NEW.from_location_id <> OLD.from_location_id OR NEW.to_location_id <> OLD.to_location_id OR NEW.created_by <> OLD.created_by OR NEW.business_date <> OLD.business_date THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'the facts of a stock transfer cannot be changed';
    END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `stock_transfers_no_delete` BEFORE DELETE ON `stock_transfers` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a stock transfer cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `stored_files`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `stored_files` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `purpose` varchar(80) NOT NULL,
  `owner_type` varchar(80) NOT NULL,
  `owner_id` char(26) NOT NULL,
  `sensitivity` varchar(20) NOT NULL,
  `storage_key` char(64) NOT NULL,
  `mime_type` varchar(127) NOT NULL,
  `size_bytes` bigint unsigned NOT NULL,
  `checksum_sha256` char(64) NOT NULL,
  `display_name` varchar(120) DEFAULT NULL,
  `expires_at` timestamp(6) NULL DEFAULT NULL,
  `erased_at` timestamp(6) NULL DEFAULT NULL,
  `erasure_reason` varchar(40) DEFAULT NULL,
  `uploaded_by` char(26) NOT NULL,
  `correlation_id` char(26) NOT NULL,
  `created_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `stored_files_storage_key_unique` (`storage_key`),
  KEY `stored_files_uploaded_by_foreign` (`uploaded_by`),
  KEY `stored_files_owner_index` (`property_id`,`owner_type`,`owner_id`,`created_at`),
  KEY `stored_files_purpose_index` (`property_id`,`purpose`,`created_at`),
  KEY `stored_files_expiry_index` (`property_id`,`expires_at`),
  KEY `stored_files_correlation_id_index` (`correlation_id`),
  CONSTRAINT `stored_files_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `stored_files_uploaded_by_foreign` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_stored_files_sensitivity` CHECK ((`sensitivity` in (_utf8mb4'standard',_utf8mb4'sensitive'))),
  CONSTRAINT `chk_stored_files_size` CHECK ((`size_bytes` > 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `stored_files_controlled_changes` BEFORE UPDATE ON `stored_files` FOR EACH ROW BEGIN
    DECLARE same_core BOOLEAN;
    SET same_core = (NEW.id <=> OLD.id AND NEW.property_id <=> OLD.property_id AND NEW.purpose <=> OLD.purpose
        AND NEW.owner_type <=> OLD.owner_type AND NEW.owner_id <=> OLD.owner_id AND NEW.sensitivity <=> OLD.sensitivity
        AND NEW.storage_key <=> OLD.storage_key AND NEW.mime_type <=> OLD.mime_type AND NEW.size_bytes <=> OLD.size_bytes
        AND NEW.checksum_sha256 <=> OLD.checksum_sha256 AND NEW.uploaded_by <=> OLD.uploaded_by
        AND NEW.correlation_id <=> OLD.correlation_id AND NEW.created_at <=> OLD.created_at);
    IF NOT same_core THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'stored_files only allows setting an expiry once or erasure tombstoning';
    END IF;
    IF OLD.erased_at IS NULL AND NEW.erased_at IS NOT NULL AND NEW.erasure_reason IS NOT NULL AND NEW.display_name IS NULL AND NEW.expires_at <=> OLD.expires_at THEN
        SET same_core = TRUE;
    ELSEIF OLD.erased_at IS NULL AND NEW.erased_at IS NULL AND OLD.expires_at IS NULL AND NEW.expires_at IS NOT NULL AND NEW.display_name <=> OLD.display_name AND NEW.erasure_reason <=> OLD.erasure_reason THEN
        SET same_core = TRUE;
    ELSE
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'stored_files only allows setting an expiry once or erasure tombstoning';
    END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `stored_files_no_delete` BEFORE DELETE ON `stored_files` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'stored_files is append-only' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `supplier_invoice_documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `supplier_invoice_documents` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `invoice_id` char(26) NOT NULL,
  `file_id` char(26) NOT NULL,
  `display_name` varchar(120) DEFAULT NULL,
  `created_by` char(26) NOT NULL,
  `created_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `supplier_invoice_documents_property_id_foreign` (`property_id`),
  KEY `supplier_invoice_documents_created_by_foreign` (`created_by`),
  KEY `supplier_invoice_documents_invoice_id_index` (`invoice_id`),
  CONSTRAINT `supplier_invoice_documents_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `supplier_invoice_documents_invoice_id_foreign` FOREIGN KEY (`invoice_id`) REFERENCES `supplier_invoices` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `supplier_invoice_documents_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `supplier_invoice_documents_no_update` BEFORE UPDATE ON `supplier_invoice_documents` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a supplier invoice document cannot be changed' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `supplier_invoice_documents_no_delete` BEFORE DELETE ON `supplier_invoice_documents` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a supplier invoice document cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `supplier_invoice_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `supplier_invoice_lines` (
  `id` char(26) NOT NULL,
  `invoice_id` char(26) NOT NULL,
  `order_line_id` char(26) NOT NULL,
  `item_id` char(26) NOT NULL,
  `unit` varchar(8) NOT NULL,
  `qty_milli` bigint unsigned NOT NULL,
  `unit_price_minor` bigint unsigned NOT NULL,
  `line_total_minor` bigint unsigned NOT NULL,
  `order_price_minor` bigint unsigned NOT NULL,
  `open_received_milli` bigint unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `supplier_invoice_lines_invoice_id_order_line_id_unique` (`invoice_id`,`order_line_id`),
  KEY `supplier_invoice_lines_item_id_foreign` (`item_id`),
  KEY `supplier_invoice_lines_order_line_id_index` (`order_line_id`),
  CONSTRAINT `supplier_invoice_lines_invoice_id_foreign` FOREIGN KEY (`invoice_id`) REFERENCES `supplier_invoices` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `supplier_invoice_lines_item_id_foreign` FOREIGN KEY (`item_id`) REFERENCES `inventory_items` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `supplier_invoice_lines_order_line_id_foreign` FOREIGN KEY (`order_line_id`) REFERENCES `purchase_order_lines` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_supplier_invoice_line` CHECK ((`qty_milli` > 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `supplier_invoice_lines_no_update` BEFORE UPDATE ON `supplier_invoice_lines` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a supplier invoice line cannot be changed' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `supplier_invoice_lines_no_delete` BEFORE DELETE ON `supplier_invoice_lines` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a supplier invoice line cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `supplier_invoices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `supplier_invoices` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `number` varchar(20) NOT NULL,
  `supplier_id` char(26) NOT NULL,
  `order_id` char(26) NOT NULL,
  `invoice_number` varchar(40) NOT NULL,
  `invoice_key` varchar(40) NOT NULL,
  `invoice_date` date NOT NULL,
  `due_date` date NOT NULL,
  `tax_number` varchar(24) DEFAULT NULL,
  `subtotal_minor` bigint unsigned NOT NULL,
  `tax_minor` bigint unsigned NOT NULL,
  `total_minor` bigint unsigned NOT NULL,
  `accrual_minor` bigint unsigned NOT NULL DEFAULT '0',
  `status` varchar(10) NOT NULL,
  `variances` json DEFAULT NULL,
  `entered_by` char(26) NOT NULL,
  `decided_by` char(26) DEFAULT NULL,
  `decided_at` timestamp(6) NULL DEFAULT NULL,
  `decision_note` varchar(200) DEFAULT NULL,
  `note` varchar(200) DEFAULT NULL,
  `business_date` date NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `supplier_invoices_property_id_number_unique` (`property_id`,`number`),
  UNIQUE KEY `supplier_invoices_once` (`property_id`,`supplier_id`,`invoice_key`),
  KEY `supplier_invoices_supplier_id_foreign` (`supplier_id`),
  KEY `supplier_invoices_entered_by_foreign` (`entered_by`),
  KEY `supplier_invoices_decided_by_foreign` (`decided_by`),
  KEY `supplier_invoices_order_id_index` (`order_id`),
  KEY `supplier_invoices_property_id_status_index` (`property_id`,`status`),
  CONSTRAINT `supplier_invoices_decided_by_foreign` FOREIGN KEY (`decided_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `supplier_invoices_entered_by_foreign` FOREIGN KEY (`entered_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `supplier_invoices_order_id_foreign` FOREIGN KEY (`order_id`) REFERENCES `purchase_orders` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `supplier_invoices_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `supplier_invoices_supplier_id_foreign` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_supplier_invoice` CHECK (((`status` in (_utf8mb4'matched',_utf8mb4'variance',_utf8mb4'approved',_utf8mb4'rejected')) and (`total_minor` = (`subtotal_minor` + `tax_minor`))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `supplier_invoices_guard` BEFORE UPDATE ON `supplier_invoices` FOR EACH ROW BEGIN
    IF OLD.status <> 'variance' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a decided supplier invoice cannot be changed'; END IF;
    IF NEW.number <> OLD.number OR NEW.supplier_id <> OLD.supplier_id OR NEW.order_id <> OLD.order_id OR NEW.invoice_number <> OLD.invoice_number OR NEW.invoice_date <> OLD.invoice_date OR NEW.total_minor <> OLD.total_minor OR NEW.entered_by <> OLD.entered_by THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'the facts of a supplier invoice cannot be changed';
    END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `supplier_invoices_no_delete` BEFORE DELETE ON `supplier_invoices` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a supplier invoice cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `supplier_ledger_entries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `supplier_ledger_entries` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `supplier_id` char(26) NOT NULL,
  `kind` varchar(16) NOT NULL,
  `amount_minor` bigint NOT NULL,
  `ref_type` varchar(24) NOT NULL,
  `ref_id` char(26) NOT NULL,
  `ref_number` varchar(40) NOT NULL,
  `business_date` date NOT NULL,
  `created_by` char(26) NOT NULL,
  `created_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `supplier_ledger_entries_property_id_foreign` (`property_id`),
  KEY `supplier_ledger_entries_created_by_foreign` (`created_by`),
  KEY `supplier_ledger_entries_supplier_id_business_date_index` (`supplier_id`,`business_date`),
  KEY `supplier_ledger_entries_ref_type_ref_id_index` (`ref_type`,`ref_id`),
  CONSTRAINT `supplier_ledger_entries_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `supplier_ledger_entries_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `supplier_ledger_entries_supplier_id_foreign` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_supplier_ledger` CHECK (((`kind` in (_utf8mb4'goods_received',_utf8mb4'goods_returned',_utf8mb4'invoice',_utf8mb4'invoice_accrual',_utf8mb4'credit_note')) and (`amount_minor` <> 0)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `supplier_ledger_entries_no_update` BEFORE UPDATE ON `supplier_ledger_entries` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a supplier ledger entry cannot be changed' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `supplier_ledger_entries_no_delete` BEFORE DELETE ON `supplier_ledger_entries` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a supplier ledger entry cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `supplier_prices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `supplier_prices` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `supplier_id` char(26) NOT NULL,
  `item_id` char(26) NOT NULL,
  `unit` varchar(8) NOT NULL,
  `unit_price_minor` bigint unsigned NOT NULL,
  `valid_from` date NOT NULL,
  `reason` varchar(200) DEFAULT NULL,
  `created_by` char(26) NOT NULL,
  `created_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `supplier_prices_item_id_foreign` (`item_id`),
  KEY `supplier_prices_created_by_foreign` (`created_by`),
  KEY `supplier_prices_supplier_id_item_id_unit_valid_from_index` (`supplier_id`,`item_id`,`unit`,`valid_from`),
  KEY `supplier_prices_property_id_item_id_index` (`property_id`,`item_id`),
  CONSTRAINT `supplier_prices_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `supplier_prices_item_id_foreign` FOREIGN KEY (`item_id`) REFERENCES `inventory_items` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `supplier_prices_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `supplier_prices_supplier_id_foreign` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_supplier_price` CHECK ((`unit_price_minor` <= 10000000000))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `supplier_prices_no_update` BEFORE UPDATE ON `supplier_prices` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a supplier price cannot be changed' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `supplier_prices_no_delete` BEFORE DELETE ON `supplier_prices` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a supplier price cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `supplier_quotes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `supplier_quotes` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `supplier_id` char(26) NOT NULL,
  `item_id` char(26) NOT NULL,
  `unit` varchar(8) NOT NULL,
  `unit_price_minor` bigint unsigned NOT NULL,
  `min_qty_milli` bigint unsigned NOT NULL DEFAULT '0',
  `quoted_on` date NOT NULL,
  `valid_until` date NOT NULL,
  `lead_time_days` smallint unsigned NOT NULL DEFAULT '0',
  `reference` varchar(40) DEFAULT NULL,
  `note` varchar(200) DEFAULT NULL,
  `created_by` char(26) NOT NULL,
  `created_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `supplier_quotes_item_id_foreign` (`item_id`),
  KEY `supplier_quotes_created_by_foreign` (`created_by`),
  KEY `supplier_quotes_property_id_item_id_valid_until_index` (`property_id`,`item_id`,`valid_until`),
  KEY `supplier_quotes_supplier_id_index` (`supplier_id`),
  CONSTRAINT `supplier_quotes_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `supplier_quotes_item_id_foreign` FOREIGN KEY (`item_id`) REFERENCES `inventory_items` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `supplier_quotes_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `supplier_quotes_supplier_id_foreign` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_supplier_quote` CHECK (((`unit_price_minor` <= 10000000000) and (`valid_until` >= `quoted_on`) and (`lead_time_days` <= 365)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `supplier_quotes_no_update` BEFORE UPDATE ON `supplier_quotes` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a supplier quote cannot be changed' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `supplier_quotes_no_delete` BEFORE DELETE ON `supplier_quotes` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a supplier quote cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `supplier_ratings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `supplier_ratings` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `supplier_id` char(26) NOT NULL,
  `score` tinyint unsigned NOT NULL,
  `aspect` varchar(12) NOT NULL,
  `comment` varchar(200) DEFAULT NULL,
  `ref_type` varchar(24) DEFAULT NULL,
  `ref_id` char(26) DEFAULT NULL,
  `created_by` char(26) NOT NULL,
  `created_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `supplier_ratings_property_id_foreign` (`property_id`),
  KEY `supplier_ratings_created_by_foreign` (`created_by`),
  KEY `supplier_ratings_supplier_id_created_at_index` (`supplier_id`,`created_at`),
  CONSTRAINT `supplier_ratings_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `supplier_ratings_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `supplier_ratings_supplier_id_foreign` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_supplier_rating` CHECK (((`score` between 1 and 5) and (`aspect` in (_utf8mb4'overall',_utf8mb4'quality',_utf8mb4'delivery',_utf8mb4'price',_utf8mb4'service'))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `supplier_ratings_no_update` BEFORE UPDATE ON `supplier_ratings` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a supplier rating cannot be changed' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `supplier_ratings_no_delete` BEFORE DELETE ON `supplier_ratings` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a supplier rating cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `suppliers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `suppliers` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `code` varchar(12) NOT NULL,
  `name` varchar(120) NOT NULL,
  `contact_name` varchar(80) DEFAULT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `email` varchar(120) DEFAULT NULL,
  `address` varchar(200) DEFAULT NULL,
  `tax_id` varchar(24) DEFAULT NULL,
  `payment_terms_days` smallint unsigned NOT NULL DEFAULT '30',
  `note` varchar(200) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `suppliers_property_id_code_unique` (`property_id`,`code`),
  CONSTRAINT `suppliers_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_supplier` CHECK ((`payment_terms_days` <= 180))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tax_filings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tax_filings` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `period_month` char(7) NOT NULL,
  `reported_on` date NOT NULL,
  `reference` varchar(60) NOT NULL,
  `tax_minor` bigint NOT NULL,
  `reported_by` char(26) NOT NULL,
  `created_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tax_filings_property_id_period_month_unique` (`property_id`,`period_month`),
  KEY `tax_filings_reported_by_foreign` (`reported_by`),
  CONSTRAINT `tax_filings_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `tax_filings_reported_by_foreign` FOREIGN KEY (`reported_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `tax_filings_no_update` BEFORE UPDATE ON `tax_filings` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a tax filing cannot be changed' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `tax_filings_no_delete` BEFORE DELETE ON `tax_filings` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a tax filing cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `user_role_assignments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_role_assignments` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `user_id` char(26) NOT NULL,
  `role_id` char(26) NOT NULL,
  `scope_type` varchar(16) NOT NULL,
  `scope_id` char(26) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ura_user_role_scope_unique` (`user_id`,`role_id`,`scope_type`,`scope_id`),
  KEY `user_role_assignments_role_id_property_id_foreign` (`role_id`,`property_id`),
  KEY `user_role_assignments_user_id_property_id_is_active_index` (`user_id`,`property_id`,`is_active`),
  KEY `ura_scope_lookup_index` (`property_id`,`scope_type`,`scope_id`,`user_id`),
  CONSTRAINT `user_role_assignments_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `user_role_assignments_role_id_property_id_foreign` FOREIGN KEY (`role_id`, `property_id`) REFERENCES `roles` (`id`, `property_id`) ON DELETE RESTRICT,
  CONSTRAINT `user_role_assignments_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chk_property_scope_id` CHECK (((`scope_type` <> _utf8mb4'property') or (`scope_id` = `property_id`))),
  CONSTRAINT `chk_user_role_scope_type` CHECK ((`scope_type` in (_utf8mb4'property',_utf8mb4'outlet',_utf8mb4'department')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` char(26) NOT NULL,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `failed_login_attempts` smallint unsigned NOT NULL DEFAULT '0',
  `locked_until` timestamp NULL DEFAULT NULL,
  `last_login_at` timestamp NULL DEFAULT NULL,
  `password_changed_at` timestamp NULL DEFAULT NULL,
  `must_change_password` tinyint(1) NOT NULL DEFAULT '0',
  `two_factor_secret` text,
  `two_factor_recovery_codes` text,
  `two_factor_confirmed_at` timestamp NULL DEFAULT NULL,
  `remember_token` varchar(100) DEFAULT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`),
  KEY `users_locked_until_index` (`locked_until`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `waitlist_entries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `waitlist_entries` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `guest_name` varchar(150) NOT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `email` varchar(120) DEFAULT NULL,
  `room_type_id` char(26) NOT NULL,
  `arrival` date NOT NULL,
  `departure` date NOT NULL,
  `adults` tinyint unsigned NOT NULL,
  `children` tinyint unsigned NOT NULL DEFAULT '0',
  `note` varchar(300) DEFAULT NULL,
  `status` varchar(10) NOT NULL DEFAULT 'waiting',
  `offered_at` timestamp(6) NULL DEFAULT NULL,
  `reservation_id` char(26) DEFAULT NULL,
  `cancel_reason` varchar(200) DEFAULT NULL,
  `created_by` char(26) NOT NULL,
  `lock_version` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `waitlist_entries_property_id_status_arrival_index` (`property_id`,`status`,`arrival`),
  CONSTRAINT `waitlist_entries_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_waitlist` CHECK (((`status` in (_utf8mb4'waiting',_utf8mb4'offered',_utf8mb4'booked',_utf8mb4'cancelled')) and (`departure` > `arrival`) and (`adults` >= 1) and ((`status` <> _utf8mb4'booked') or (`reservation_id` is not null)) and ((`status` <> _utf8mb4'cancelled') or (`cancel_reason` is not null))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `waitlist_entries_no_delete` BEFORE DELETE ON `waitlist_entries` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a waiting list entry cannot be deleted' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `webhook_receipts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `webhook_receipts` (
  `id` char(26) NOT NULL,
  `property_id` char(26) NOT NULL,
  `provider` varchar(40) NOT NULL,
  `event_id` varchar(128) NOT NULL,
  `payload` longtext NOT NULL,
  `received_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `webhook_receipts_event_unique` (`property_id`,`provider`,`event_id`),
  CONSTRAINT `webhook_receipts_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `webhook_receipts_no_update` BEFORE UPDATE ON `webhook_receipts` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'webhook_receipts is append-only' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `webhook_receipts_no_delete` BEFORE DELETE ON `webhook_receipts` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'webhook_receipts is append-only' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (1,'0001_01_01_000000_create_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (2,'0001_01_01_000001_create_cache_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (3,'0001_01_01_000002_create_jobs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (4,'0001_01_01_000003_create_properties_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (5,'0001_01_01_000004_create_identity_access_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (6,'0001_01_01_000005_create_audit_and_security_event_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (7,'0001_01_01_000006_create_idempotency_operations_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (8,'0001_01_01_000007_create_outbox_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (9,'0001_01_01_000008_create_stored_files_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (10,'0001_01_01_000009_create_operational_alerts_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (11,'0001_01_01_000010_create_backup_runs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (12,'0001_01_01_000011_create_offline_sync_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (13,'0001_01_01_000012_create_approval_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (14,'0001_01_01_000013_create_privacy_and_retention_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (15,'0001_01_01_000014_create_integration_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (16,'0001_01_01_000015_create_property_configuration_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (17,'0001_01_01_000016_create_rate_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (18,'0001_01_01_000017_create_front_office_inventory_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (19,'0001_01_01_000018_create_folio_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (20,'0001_01_01_000019_create_guest_and_stay_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (21,'0001_01_01_000020_create_night_audit_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (22,'0001_01_01_000021_create_housekeeping_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (23,'0001_01_01_000022_create_laundry_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (24,'0001_01_01_000023_create_import_batches_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (25,'0001_01_01_000024_create_stay_amendment_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (26,'0001_01_01_000025_create_booking_policy_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (27,'0001_01_01_000026_create_cashier_shift_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (28,'0001_01_01_000027_create_reservation_rate_changes_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (29,'0001_01_01_000028_add_origin_to_folios',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (30,'0001_01_01_000029_create_guest_requests_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (31,'0001_01_01_000030_create_guest_feedback_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (32,'0001_01_01_000031_create_front_desk_routine_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (33,'0001_01_01_000032_create_guest_corrections_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (34,'0001_01_01_000033_add_request_deadline_and_room_service_flags',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (35,'0001_01_01_000034_create_linen_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (36,'0001_01_01_000035_create_housekeeping_checklist_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (37,'0001_01_01_000036_create_laundry_treatments',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (38,'0001_01_01_000037_create_obligation_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (39,'0001_01_01_000038_add_building_to_rooms',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (40,'0001_01_01_000039_create_stay_time_fee_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (41,'0001_01_01_000040_create_lost_found_items_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (42,'0001_01_01_000041_add_photo_to_checklist_completions',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (43,'0001_01_01_000042_create_dashboard_preferences_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (44,'0001_01_01_000043_create_registration_card_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (45,'0001_01_01_000044_create_company_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (46,'0001_01_01_000045_create_foreign_payment_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (47,'0001_01_01_000046_create_group_booking_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (48,'0001_01_01_000047_create_revenue_outlet_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (49,'0001_01_01_000048_create_laundry_claim_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (50,'0001_01_01_000049_create_linen_par_levels',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (51,'0001_01_01_000050_create_report_export_jobs',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (52,'0001_01_01_000051_create_inventory_catalog_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (53,'0001_01_01_000052_create_stock_ledger',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (54,'0001_01_01_000053_create_stock_movements_and_transfers',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (55,'0001_01_01_000054_add_stock_values',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (56,'0001_01_01_000055_create_stock_counts',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (57,'0001_01_01_000056_create_suppliers',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (58,'0001_01_01_000057_create_purchase_requests_and_orders',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (59,'0001_01_01_000058_create_goods_receipts',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (60,'0001_01_01_000059_create_supplier_invoices',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (61,'0001_01_01_000060_create_returns',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (62,'0001_01_01_000061_create_supplier_quotes',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (63,'0001_01_01_000062_create_accounts_payable',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (64,'0001_01_01_000063_create_revenue_reconciliation',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (65,'0001_01_01_000064_create_accounts_receivable',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (66,'0001_01_01_000065_create_petty_cash',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (67,'0001_01_01_000066_create_management_reporting_settings',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (68,'0001_01_01_000067_create_recurring_expenses_and_budgets',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (69,'0001_01_01_000068_create_corrections_and_exceptions',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (70,'0001_01_01_000069_add_payment_reversals_and_receivable_adjustments',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (71,'0001_01_01_000070_create_food_cost_settings',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (72,'0001_01_01_000071_create_fnb_setup',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (73,'0001_01_01_000072_create_fnb_bills',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (74,'0001_01_01_000073_create_fnb_payments_and_shifts',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (75,'0001_01_01_000074_create_fnb_sales_revenue',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (76,'0001_01_01_000075_create_kitchen_tickets',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (77,'0001_01_01_000076_create_kitchen_recipes',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (78,'0001_01_01_000077_add_discounts_to_fnb_bill_lines',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (79,'0001_01_01_000078_create_fnb_refunds',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (80,'0001_01_01_000079_create_fin_pos_refunds',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (81,'0001_01_01_000080_create_kitchen_waste',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (82,'0001_01_01_000081_create_maintenance_work_orders',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (83,'0001_01_01_000082_add_escalation_and_reports_to_maintenance',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (84,'0001_01_01_000083_create_maintenance_assets',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (85,'0001_01_01_000084_create_maintenance_parts',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (86,'0001_01_01_000085_create_maintenance_vendor_jobs',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (87,'0001_01_01_000086_create_maintenance_duties',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (88,'0001_01_01_000087_add_work_order_to_guest_requests',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (89,'0001_01_01_000088_create_hr_employees',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (90,'0001_01_01_000089_create_hr_roster',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (91,'0001_01_01_000090_create_hr_attendance',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (92,'0001_01_01_000091_create_hr_attendance_adjustments',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (93,'0001_01_01_000092_create_hr_leave',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (94,'0001_01_01_000093_create_inventory_lots',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (95,'0001_01_01_000094_create_inventory_requisitions',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (96,'0001_01_01_000095_create_routines',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (97,'0001_01_01_000096_create_hr_performance',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (98,'0001_01_01_000097_create_hr_payroll_basis',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (99,'0001_01_01_000098_create_hr_payroll_runs',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (100,'0001_01_01_000099_create_finance_payroll_disbursements',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (101,'0001_01_01_000100_create_hr_service_charge',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (102,'0001_01_01_000101_create_hr_shift_swaps',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (103,'0001_01_01_000102_create_hr_conduct_and_announcements',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (104,'0001_01_01_000103_create_hr_appraisals',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (105,'0001_01_01_000104_create_fnb_minibar_and_room_service',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (106,'0001_01_01_000105_create_finance_tax',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (107,'0001_01_01_000106_create_fnb_bill_rearranging_and_price_rules',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (108,'0001_01_01_000107_create_kitchen_production',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (109,'0001_01_01_000108_create_finance_settlements',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (110,'0001_01_01_000109_create_report_schedules',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (111,'0001_01_01_000110_create_guest_experience_qr',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (112,'0001_01_01_000111_create_guest_experience_help',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (113,'0001_01_01_000112_create_guest_self_checkin',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (114,'0001_01_01_000113_create_guest_preferences_and_laundry_escalation',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (115,'0001_01_01_000114_add_photo_to_fnb_menu_items',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (116,'0001_01_01_000115_add_correlation_to_finance_postings',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (117,'0001_01_01_000116_create_laundry_checkout_exception',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (118,'0001_01_01_000117_restore_in_house_room_key_on_update',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (119,'0001_01_01_000118_add_user_administration',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (120,'0001_01_01_000119_install_default_roles',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (121,'0001_01_01_000120_install_default_approvals',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (122,'0001_01_01_000121_add_property_profile',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (123,'0001_01_01_000122_add_attendance_evidence_and_reviews',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (124,'0001_01_01_000123_add_messaging_channels',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (125,'0001_01_01_000124_add_property_logos',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (126,'0001_01_01_000125_add_property_branding_settings',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (127,'0001_01_01_000126_add_front_desk_helpers',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (128,'0001_01_01_000127_add_online_booking',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (129,'0001_01_01_000128_add_room_type_photos',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (130,'0001_01_01_000129_add_automatic_reminders',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (131,'0001_01_01_000130_add_guest_messages',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (132,'0001_01_01_000131_add_face_attendance',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (133,'0001_01_01_000132_converge_generated_key_columns',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (134,'0001_01_01_000133_create_reservation_external_refs',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (135,'0001_01_01_000134_create_fin_journal_accounts',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (136,'0001_01_01_000135_create_hr_bank_accounts',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (137,'0001_01_01_000136_add_do_not_rent_flag',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (138,'0001_01_01_000137_create_door_locks',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (139,'0001_01_01_000138_create_gift_cards',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (140,'0001_01_01_000139_create_waitlist_entries',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (141,'0001_01_01_000140_create_commissions',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (142,'0001_01_01_000141_create_fixed_assets',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (143,'0001_01_01_000142_create_bank_statements',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (144,'0001_01_01_000143_create_loyalty',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (145,'0001_01_01_000144_create_activities',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (146,'0001_01_01_000145_create_web_push',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (147,'0001_01_01_000146_add_new_feature_permissions',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (148,'0001_01_01_000147_grant_new_feature_permissions_to_starting_roles',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (149,'0001_01_01_000148_create_day_use',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (150,'0001_01_01_000149_add_bank_line_category',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (151,'0001_01_01_000150_loyalty_points_expire',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (152,'0001_01_01_000151_create_push_preferences',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (153,'0001_01_01_000152_create_laundry_settings',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (154,'0001_01_01_000153_add_stay_to_lost_found_items',3);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (155,'0001_01_01_000154_add_routine_permissions_of_fnb_and_kitchen',4);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (156,'0001_01_01_000155_create_property_features',4);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (157,'0001_01_01_000156_add_charge_waivers_to_fnb_bills',4);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (158,'0001_01_01_000157_create_spa',4);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (159,'0001_01_01_000158_add_free_of_charge_to_laundry_orders',5);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (160,'0001_01_01_000159_allow_purchase_requests_on_hold',5);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (161,'0001_01_01_000160_add_charge_waivers_to_minibar_checks',5);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (162,'0001_01_01_000161_create_employee_commissions',5);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (163,'0001_01_01_000162_add_guest_name_to_laundry_orders',5);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (164,'0001_01_01_000163_create_access_position_roles',6);
