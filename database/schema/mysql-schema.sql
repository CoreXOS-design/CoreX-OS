/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `_corex_lane_test_fingerprint`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `_corex_lane_test_fingerprint` (
  `id` tinyint NOT NULL,
  `fingerprint` char(64) NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `activity_columns`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `activity_columns` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `label` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `points_weight` decimal(10,2) NOT NULL DEFAULT '1.00',
  `group` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `input_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'number',
  `default_enabled` tinyint(1) NOT NULL DEFAULT '1',
  `sort_order` int unsigned NOT NULL DEFAULT '100',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `activity_columns_key_unique` (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `activity_definition_calendar_classes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `activity_definition_calendar_classes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `event_class` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `activity_definition_id` bigint unsigned NOT NULL,
  `trigger_kind` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'calendar',
  `slug` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `subject_type` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `value_per_event` int NOT NULL DEFAULT '1',
  `requires_feedback` tinyint(1) NOT NULL DEFAULT '1',
  `auto_revoke_after_hours` int DEFAULT '24',
  `daily_cap` int DEFAULT NULL,
  `back_date_limit_hours` int NOT NULL DEFAULT '48',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `adcc_agency_class_def_unique` (`agency_id`,`event_class`,`activity_definition_id`,`deleted_at`),
  UNIQUE KEY `adcc_agency_slug_unique` (`agency_id`,`slug`,`deleted_at`),
  KEY `adcc_def_fk` (`activity_definition_id`),
  KEY `adcc_created_by_fk` (`created_by`),
  KEY `adcc_updated_by_fk` (`updated_by`),
  KEY `adcc_agency_class_active_idx` (`agency_id`,`event_class`,`is_active`),
  KEY `adcc_agency_kind_active_idx` (`agency_id`,`trigger_kind`,`is_active`),
  CONSTRAINT `adcc_agency_fk` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `adcc_created_by_fk` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `adcc_def_fk` FOREIGN KEY (`activity_definition_id`) REFERENCES `activity_definitions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `adcc_updated_by_fk` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `activity_definitions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `activity_definitions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `scope` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'global',
  `agency_id` bigint unsigned DEFAULT NULL,
  `branch_id` bigint unsigned DEFAULT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `weight` decimal(10,2) NOT NULL DEFAULT '1.00',
  `sort_order` int NOT NULL DEFAULT '100',
  `scoring_mode` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'count',
  `is_enabled` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `activity_definitions_scope_branch_name_unique` (`scope`,`branch_id`,`name`),
  KEY `activity_definitions_branch_id_foreign` (`branch_id`),
  KEY `activity_definitions_scope_branch_id_sort_order_index` (`scope`,`branch_id`,`sort_order`),
  KEY `activity_definitions_agency_id_foreign` (`agency_id`),
  KEY `activity_definitions_scope_agency_idx` (`scope`,`agency_id`),
  CONSTRAINT `activity_definitions_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE SET NULL,
  CONSTRAINT `activity_definitions_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `activity_point_goals`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `activity_point_goals` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned DEFAULT NULL,
  `branch_id` bigint unsigned DEFAULT NULL,
  `period` varchar(7) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `points_target` decimal(12,2) NOT NULL DEFAULT '0.00',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `activity_point_goals_period_index` (`period`),
  KEY `activity_point_goals_user_id_index` (`user_id`),
  KEY `activity_point_goals_branch_id_index` (`branch_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `activity_targets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `activity_targets` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `period` varchar(7) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `branch_id` bigint unsigned DEFAULT NULL,
  `calls_made_target` int NOT NULL DEFAULT '0',
  `doors_knocked_target` int NOT NULL DEFAULT '0',
  `whatsapps_sent_target` int NOT NULL DEFAULT '0',
  `referrals_asked_target` int NOT NULL DEFAULT '0',
  `flyers_dropped_target` int NOT NULL DEFAULT '0',
  `presentations_booked_target` int NOT NULL DEFAULT '0',
  `presentations_done_target` int NOT NULL DEFAULT '0',
  `oats_signed_target` int NOT NULL DEFAULT '0',
  `eats_signed_target` int NOT NULL DEFAULT '0',
  `buyer_leads_target` int NOT NULL DEFAULT '0',
  `seller_leads_target` int NOT NULL DEFAULT '0',
  `portal_leads_target` int NOT NULL DEFAULT '0',
  `referral_leads_target` int NOT NULL DEFAULT '0',
  `buyer_appointments_target` int NOT NULL DEFAULT '0',
  `otps_written_target` int NOT NULL DEFAULT '0',
  `otps_accepted_target` int NOT NULL DEFAULT '0',
  `otps_collapsed_target` int NOT NULL DEFAULT '0',
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `activity_targets_period_user_id_unique` (`period`,`user_id`),
  KEY `activity_targets_user_id_foreign` (`user_id`),
  KEY `activity_targets_branch_id_foreign` (`branch_id`),
  KEY `activity_targets_created_by_foreign` (`created_by`),
  KEY `activity_targets_updated_by_foreign` (`updated_by`),
  KEY `activity_targets_period_index` (`period`),
  CONSTRAINT `activity_targets_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `activity_targets_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `activity_targets_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `activity_targets_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `agencies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `agencies` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `fica_referral_enabled` tinyint(1) NOT NULL DEFAULT '1',
  `fica_referral_recipient_user_id` bigint unsigned DEFAULT NULL,
  `payroll_default_cut_day` tinyint unsigned DEFAULT NULL,
  `payroll_default_daily_rate_basis` enum('fixed_21_67','calendar_working_days','hours_per_day') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'fixed_21_67',
  `wa_history_backfill` tinyint(1) NOT NULL DEFAULT '1',
  `wa_embargo_retention_days` smallint unsigned NOT NULL DEFAULT '30',
  `wa_transcription_enabled` tinyint(1) NOT NULL DEFAULT '1',
  `wa_transcription_time` varchar(5) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '22:00',
  `wa_transcription_language` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `trading_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tagline` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `address` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone_label` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone_secondary` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone_secondary_label` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fax` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `financial_year_start_month` tinyint unsigned NOT NULL DEFAULT '3',
  `ppra_pack_sales_sample_size` tinyint unsigned NOT NULL DEFAULT '5',
  `ppra_pack_rental_sample_size` tinyint unsigned NOT NULL DEFAULT '5',
  `ppra_pack_mandate_sample_size` tinyint unsigned NOT NULL DEFAULT '5',
  `ppra_mandate_register_red_threshold_pct` tinyint unsigned NOT NULL DEFAULT '10',
  `ppra_zip_max_files` smallint unsigned NOT NULL DEFAULT '200',
  `reg_no` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `vat_no` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `vat_registered` tinyint(1) NOT NULL DEFAULT '0',
  `vat_capture_mode` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'excl',
  `vat_settings_updated_at` timestamp NULL DEFAULT NULL,
  `vat_settings_updated_by_user_id` bigint unsigned DEFAULT NULL,
  `ffc_no` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ppra_number` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ppra_employment_letter_address_block` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `ppra_employment_letter_reminder_days` smallint unsigned NOT NULL DEFAULT '2',
  `ncc_registration_number` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `public_contact` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fic_no` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `p24_agency_id` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `p24_agency_label` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `p24_username` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `p24_password` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `p24_user_group_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `p24_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `p24_max_photos` smallint unsigned DEFAULT '150',
  `p24_http_read_timeout` smallint unsigned DEFAULT '120',
  `p24_locations_synced_at` timestamp NULL DEFAULT NULL,
  `p24_last_sync_error` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `pp_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `pp_lead_pull_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `pp_stats_pull_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `pp_username` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pp_password` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `pp_branch_guid` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pp_wsdl` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pp_sandbox` tinyint(1) NOT NULL DEFAULT '1',
  `pp_image_base_url` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pp_webhook_secret` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `pp_max_photos` smallint unsigned DEFAULT '150',
  `pp_last_sync_error` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `slug` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `viewing_pack_redaction_dpi` smallint unsigned DEFAULT NULL,
  `viewing_pack_default_duration_minutes` smallint unsigned DEFAULT NULL,
  `sidebar_color` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '#0ea5e9',
  `icon_color` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '#0ea5e9',
  `default_color` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '#0b2a4a',
  `button_color` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '#0ea5e9',
  `ai_voice_enabled` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Allow agents at this agency to use Ellie voice commands (advanced feature)',
  `ai_image_recognition_enabled` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Allow agents at this agency to use AI property image recognition (advanced feature, mobile-only)',
  `logo_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email_disclaimer` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `marketing_unsubscribe_footer` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `outreach_live_deal_statuses` json DEFAULT NULL,
  `popi_url` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `privacy_policy_markdown` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `privacy_policy_token` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `privacy_policy_published_at` timestamp NULL DEFAULT NULL,
  `whatsapp_launch_mode_agent` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'whatsapp_web',
  `whatsapp_launch_mode_seller` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'whatsapp_web',
  `outreach_send_window` json DEFAULT NULL,
  `communication_first_poll_days` smallint unsigned DEFAULT NULL,
  `communication_poll_chunk_size` smallint unsigned DEFAULT NULL,
  `communication_failure_alert_threshold` smallint unsigned DEFAULT NULL,
  `outreach_queue_expiry_hours` smallint unsigned DEFAULT NULL,
  `outreach_queue_daily_cap_per_agent` smallint unsigned DEFAULT NULL,
  `restrict_consent_outreach_to_full_status` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'AT-142 — when on, only full-status practitioners/principals may send consent-outreach templates',
  `ai_monthly_budget_zar` decimal(10,2) NOT NULL DEFAULT '1000.00',
  `ai_budget_warning_pct` tinyint unsigned NOT NULL DEFAULT '80',
  `ai_budget_hard_cap_pct` tinyint unsigned NOT NULL DEFAULT '110',
  `ai_budget_overage_allowed` tinyint(1) NOT NULL DEFAULT '0',
  `ai_budget_last_warned_at` timestamp NULL DEFAULT NULL,
  `ai_budget_last_hard_stopped_at` timestamp NULL DEFAULT NULL,
  `prospecting_pitch_temp_lock_minutes` smallint unsigned NOT NULL DEFAULT '30',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `is_demo` tinyint(1) NOT NULL DEFAULT '0',
  `maintenance_mode` tinyint(1) NOT NULL DEFAULT '0',
  `deal_v2_bm_approval_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `deal_v2_stage_gate_mode` enum('auto','prompt') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'auto',
  `show_prospected_badge` tinyint(1) NOT NULL DEFAULT '1',
  `maintenance_message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `maintenance_started_at` timestamp NULL DEFAULT NULL,
  `require_external_access_authorization` tinyint(1) NOT NULL DEFAULT '0',
  `presentations_coverage_rich_threshold` smallint unsigned NOT NULL DEFAULT '6',
  `presentations_coverage_moderate_threshold` smallint unsigned NOT NULL DEFAULT '3',
  `presentations_coverage_thin_threshold` smallint unsigned NOT NULL DEFAULT '1',
  `presentations_default_period_months` smallint unsigned NOT NULL DEFAULT '12',
  `presentations_default_show_executive_summary` tinyint(1) NOT NULL DEFAULT '1',
  `presentations_default_show_market_overview` tinyint(1) NOT NULL DEFAULT '1',
  `presentations_default_show_recent_sales` tinyint(1) NOT NULL DEFAULT '1',
  `presentations_default_show_spatial_view` tinyint(1) NOT NULL DEFAULT '1',
  `presentations_default_show_cma_analysis` tinyint(1) NOT NULL DEFAULT '1',
  `presentations_default_show_active_competition` tinyint(1) NOT NULL DEFAULT '1',
  `presentations_default_show_inflow_absorption` tinyint(1) NOT NULL DEFAULT '1',
  `presentations_default_show_holding_cost` tinyint(1) NOT NULL DEFAULT '1',
  `presentations_default_show_pricing_strategy` tinyint(1) NOT NULL DEFAULT '1',
  `presentations_freshness_days` smallint unsigned NOT NULL DEFAULT '90' COMMENT 'Build 5 — public view shows a "request revised analysis" CTA when the snapshot is older than this many days.',
  `cma_compute_recency_months` smallint unsigned DEFAULT '36' COMMENT 'Build 8b — recency window (months) for CmaComputeService input pool. Decoupled from presentations_default_period_months which drives the hydrator + coverage badge. Null falls back to service constant.',
  `cma_compute_iqr_multiplier` decimal(4,2) DEFAULT '1.50' COMMENT 'Build 8b — IQR multiplier for R/m² lower-bound outlier fence (median − multiplier × IQR). 1.5 is Tukey standard. Null falls back to service constant.',
  `comp_price_band_pct` decimal(5,2) DEFAULT '25.00' COMMENT 'AT-22 §1 — comp price band ± % around the cleaned-pool CMA anchor (not asking). Null → CompPoolBuilder constant.',
  `comp_erf_band_pct` decimal(5,2) DEFAULT '30.00' COMMENT 'AT-22 §1 — erf-size proximity ± % used as a ranking factor (not a hard drop). Null → constant.',
  `comp_radius_m` smallint unsigned DEFAULT '300' COMMENT 'AT-22 §1 — initial comp radius (m). Default 300 (the prior presentations_default_radius_m 1000 was too wide). Null → constant.',
  `comp_radius_widen_steps` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT '300,600,1000,1500,3000' COMMENT 'AT-22 §1 — CSV widen ladder for the radius when comps are thin. Null → constant ladder.',
  `comp_radius_max_m` smallint unsigned DEFAULT '3000' COMMENT 'AT-22 §1 — hard ceiling for the radius widen ladder. Default 3000 (rural mandates must resolve). Null → constant.',
  `comp_min_count` smallint unsigned DEFAULT '10' COMMENT 'AT-22 §1 — minimum comps before the widen ladder stops expanding. Default 10 (round-1: auto-widen 300→600→1000m to catch on-profile comps just outside 300m). Null → constant.',
  `comp_max_count` smallint unsigned DEFAULT '15' COMMENT 'AT-22 §1 — max comps shortlisted after ranking (PRES 87 curated 13; do not force-drop). Null → constant.',
  `anchor_divergence_pct` decimal(5,2) DEFAULT '25.00' COMMENT 'AT-22 §1.5 — widen the radius when the cleaned-pool estimate diverges from the vicinity average by more than this %. Null → constant.',
  `range_lower_pct` tinyint unsigned DEFAULT '25' COMMENT 'AT-22 §5 — lower percentile for the recommended range. Default 25 (P25). Null → constant.',
  `range_upper_pct` tinyint unsigned DEFAULT '75' COMMENT 'AT-22 §5 — upper percentile for the recommended range. Default 75 (P75). Null → constant.',
  `cma_band_lower_pct` decimal(5,2) DEFAULT '10.00' COMMENT 'PRES-CMA-REALFIX — recommended-band LOWER half-width: lower = middle × (1 − pct/100). Null → constant 7.',
  `cma_band_upper_pct` decimal(5,2) DEFAULT '13.00' COMMENT 'PRES-CMA-REALFIX — recommended-band UPPER half-width: upper = middle × (1 + pct/100). Null → constant 7.',
  `cma_hide_display_outliers` tinyint(1) NOT NULL DEFAULT '1',
  `competitor_stock_default_beds_tolerance` tinyint unsigned NOT NULL DEFAULT '1' COMMENT 'Competitor Stock — ± beds window for synthetic ContactMatch (Core Matches scorer).',
  `competitor_stock_default_price_tolerance_pct` tinyint unsigned NOT NULL DEFAULT '20' COMMENT 'Competitor Stock — ± percent price band for synthetic match (e.g. 20 = ±20%).',
  `competitor_stock_min_score` tinyint unsigned NOT NULL DEFAULT '50' COMMENT 'Competitor Stock — minimum match score (Core Matches 0-100) to include in section. 50 = Approximate tier floor.',
  `presentations_default_comp_scope` enum('radius_all','suburb_only') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'radius_all',
  `presentations_default_radius_m` smallint unsigned NOT NULL DEFAULT '1000',
  `ss_show_complex_section` tinyint(1) NOT NULL DEFAULT '1' COMMENT 'Show the dedicated sectional/complex sales section on presentations when such comps exist.',
  `presentations_default_rates_per_million_zar` int unsigned NOT NULL DEFAULT '800' COMMENT 'Monthly municipal rates per R1M of property value.',
  `presentations_default_levies_sectional_per_m2_zar` smallint unsigned NOT NULL DEFAULT '25' COMMENT 'Monthly body-corporate levies per m² for sectional title only.',
  `presentations_default_insurance_per_million_zar` smallint unsigned NOT NULL DEFAULT '200' COMMENT 'Monthly building insurance per R1M of property value.',
  `presentations_default_utilities_zar` smallint unsigned NOT NULL DEFAULT '1200' COMMENT 'Flat monthly utilities estimate.',
  `presentations_default_opportunity_cost_pct` decimal(5,2) NOT NULL DEFAULT '8.00' COMMENT 'Annual % return on net equity; divided by 12 for monthly opportunity cost.',
  `presentations_default_garden_zar` smallint unsigned NOT NULL DEFAULT '800' COMMENT 'Freehold garden service — Tier 2 default monthly Rands.',
  `presentations_default_pool_zar` smallint unsigned NOT NULL DEFAULT '600' COMMENT 'Freehold pool service — Tier 2 default monthly Rands.',
  `presentations_default_security_zar` smallint unsigned NOT NULL DEFAULT '1500' COMMENT 'Freehold security/estate fees — Tier 2 default monthly Rands.',
  `snapshot_link_default_expiry_days` smallint unsigned NOT NULL DEFAULT '21' COMMENT 'Default expiry window for /p/{token} share links.',
  `snapshot_link_ip_masking` tinyint(1) NOT NULL DEFAULT '1' COMMENT 'When true, store IPs masked to /24 (POPIA-respectful). Opt-out only when fraud investigation requires it.',
  `presentation_staleness_days` smallint unsigned NOT NULL DEFAULT '21' COMMENT 'Days after issue before public viewer shows the data-may-be-dated banner. Range 7-90 enforced in app layer.',
  `teaser_default_show_suburb_stats` tinyint(1) NOT NULL DEFAULT '1',
  `teaser_default_show_market_position` tinyint(1) NOT NULL DEFAULT '0',
  `teaser_default_show_asking_range` tinyint(1) NOT NULL DEFAULT '1',
  `teaser_default_show_holding_cost_summary` tinyint(1) NOT NULL DEFAULT '0',
  `email_default_subject_template` varchar(300) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email_default_body_template` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `whatsapp_default_template` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `dashboard_settings_mode` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'user' COMMENT 'user = individual settings, agency = shared agency settings',
  `split_branches_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `other_agency_stock_visible_roles` json DEFAULT NULL,
  `other_agency_stock_consent_wording` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `adhoc_document_distribution_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `assistants_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `ad_bg_removal_hole_min_px` int unsigned DEFAULT NULL,
  `ad_bg_removal_hole_max_px` int unsigned DEFAULT NULL,
  `ad_bg_removal_hole_max_dimension_px` int unsigned DEFAULT NULL,
  `ad_bg_removal_flood_fill_drift_cap_px` int unsigned DEFAULT NULL,
  `ad_bg_removal_api_enabled` tinyint(1) NOT NULL DEFAULT '1',
  `assistant_fica_required_default` tinyint(1) NOT NULL DEFAULT '1',
  `properties_sort_mode` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'created',
  `properties_status_priority` json DEFAULT NULL,
  `default_branch_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `paye_registration_no` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `uif_employer_no` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sdl_registration_no` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `employer_bank_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `employer_bank_account` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `employer_bank_branch_code` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `feedback_recipients` json DEFAULT NULL COMMENT 'JSON array of email addresses to receive feedback reports',
  `whistleblow_approver_user_ids` json DEFAULT NULL,
  `rental_application_ro_user_ids` json DEFAULT NULL,
  `rental_application_co_user_ids` json DEFAULT NULL,
  `whistleblow_compliance_officer_email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `whistleblow_tier_recipients` json DEFAULT NULL,
  `pp_locations_synced_at` timestamp NULL DEFAULT NULL,
  `pp_locations_last_error` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `competitor_stock_min_same_type` tinyint unsigned NOT NULL DEFAULT '5' COMMENT 'Competitor Stock — minimum exact-property-type matches before stepping up to same-family-other-type. Level 1 (FH/SS) is never crossed.',
  `competitor_stock_default_display_count` tinyint unsigned NOT NULL DEFAULT '10' COMMENT 'Competitor Stock — top-N display cap on the review screen + auto-tick floor. Rest live in the manual-picker modal.',
  `competitor_stock_weights` json DEFAULT NULL,
  `presentations_map_provider` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'svg_radial' COMMENT 'Presentation PDF map renderer: svg_radial (polar diagram, self-contained) or static_image (Google Static Maps PNG, requires API key).',
  `website_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `website_url` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `website_tagline` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `website_about` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `website_social_facebook` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `website_social_instagram` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `website_social_linkedin` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `website_social_youtube` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `website_contact_email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `website_contact_phone` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `website_address` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `website_open_hours` json DEFAULT NULL,
  `website_show_agents` tinyint(1) NOT NULL DEFAULT '1',
  `website_show_listings` tinyint(1) NOT NULL DEFAULT '1',
  `website_show_branches` tinyint(1) NOT NULL DEFAULT '0',
  `website_agent_order_mode` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'alphabetical',
  `website_branch_order_mode` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'alphabetical',
  `communication_ingest_drop_noreply` tinyint(1) DEFAULT NULL,
  `communication_ingest_blocklist_domains` json DEFAULT NULL,
  `communication_reconcile_window_minutes` int unsigned DEFAULT NULL,
  `communication_provisional_prune_hours` int unsigned DEFAULT NULL,
  `wa_self_link_enabled` tinyint(1) NOT NULL DEFAULT '1',
  `wa_session_prefix` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `communication_send_failure_alert_threshold` smallint unsigned DEFAULT NULL,
  `communication_pending_grace_days` smallint unsigned DEFAULT NULL,
  `communication_test_connection_max_attempts` tinyint unsigned DEFAULT NULL,
  `communication_test_connection_window_seconds` int unsigned DEFAULT NULL,
  `communication_poll_backoff_base_seconds` int unsigned DEFAULT NULL,
  `communication_poll_backoff_max_seconds` int unsigned DEFAULT NULL,
  `communication_poll_disable_threshold` tinyint unsigned DEFAULT NULL,
  `communication_circuit_breaker_min_mailboxes` tinyint unsigned DEFAULT NULL,
  `communication_circuit_breaker_failure_threshold_percent` tinyint unsigned DEFAULT NULL,
  `communication_circuit_breaker_probe_interval_minutes` int unsigned DEFAULT NULL,
  `communication_circuit_breaker_lookback_minutes` int unsigned DEFAULT NULL,
  `one_email_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `one_email_user_id` bigint unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `agencies_slug_unique` (`slug`),
  UNIQUE KEY `agencies_privacy_policy_token_unique` (`privacy_policy_token`),
  KEY `agencies_default_branch_id_foreign` (`default_branch_id`),
  KEY `agencies_req_ext_auth_idx` (`require_external_access_authorization`),
  KEY `agencies_fica_referral_recipient_user_id_foreign` (`fica_referral_recipient_user_id`),
  KEY `agencies_one_email_user_id_foreign` (`one_email_user_id`),
  KEY `agencies_vat_settings_updated_by_user_id_foreign` (`vat_settings_updated_by_user_id`),
  CONSTRAINT `agencies_default_branch_id_foreign` FOREIGN KEY (`default_branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `agencies_fica_referral_recipient_user_id_foreign` FOREIGN KEY (`fica_referral_recipient_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `agencies_one_email_user_id_foreign` FOREIGN KEY (`one_email_user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `agencies_vat_settings_updated_by_user_id_foreign` FOREIGN KEY (`vat_settings_updated_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=55 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `agency_access_request_admins`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `agency_access_request_admins` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `request_id` bigint unsigned NOT NULL,
  `admin_user_id` bigint unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `aar_admins_unique` (`request_id`,`admin_user_id`),
  KEY `agency_access_request_admins_admin_user_id_foreign` (`admin_user_id`),
  CONSTRAINT `agency_access_request_admins_admin_user_id_foreign` FOREIGN KEY (`admin_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `agency_access_request_admins_request_id_foreign` FOREIGN KEY (`request_id`) REFERENCES `agency_access_requests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `agency_access_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `agency_access_requests` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `target_agency_id` bigint unsigned NOT NULL,
  `requester_user_id` bigint unsigned NOT NULL,
  `requester_role` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('pending','approved','denied','expired','cancelled') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `denial_reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `authorized_by_user_id` bigint unsigned DEFAULT NULL,
  `authorized_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `granted_session_expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `agency_access_requests_authorized_by_user_id_foreign` (`authorized_by_user_id`),
  KEY `agency_access_requests_target_agency_id_status_index` (`target_agency_id`,`status`),
  KEY `agency_access_requests_requester_user_id_status_index` (`requester_user_id`,`status`),
  KEY `agency_access_requests_expires_at_index` (`expires_at`),
  CONSTRAINT `agency_access_requests_authorized_by_user_id_foreign` FOREIGN KEY (`authorized_by_user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `agency_access_requests_requester_user_id_foreign` FOREIGN KEY (`requester_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `agency_access_requests_target_agency_id_foreign` FOREIGN KEY (`target_agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `agency_api_keys`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `agency_api_keys` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `key_prefix` varchar(24) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `secret_hash` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `scopes` json DEFAULT NULL,
  `webhook_url` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `webhook_secret` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `rate_limit_per_min` int unsigned NOT NULL DEFAULT '120',
  `last_used_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `revoked_at` timestamp NULL DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `agency_api_keys_key_prefix_unique` (`key_prefix`),
  KEY `agency_api_keys_created_by_foreign` (`created_by`),
  KEY `agency_api_keys_agency_id_index` (`agency_id`),
  CONSTRAINT `agency_api_keys_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `agency_api_keys_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `agency_compliance_provisions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `agency_compliance_provisions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `provision_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `document_type_config_id` bigint unsigned DEFAULT NULL,
  `branch_id` bigint unsigned DEFAULT NULL,
  `status` enum('active','expired','superseded') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `document_path` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `document_original_name` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `policy_reference` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `effective_from` date NOT NULL,
  `effective_until` date DEFAULT NULL,
  `applies_to_roles` json DEFAULT NULL,
  `applies_to_branches` json DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `agency_compliance_provisions_created_by_foreign` (`created_by`),
  KEY `acp_agency_type_status_idx` (`agency_id`,`provision_type`,`status`),
  KEY `agency_compliance_provisions_document_type_config_id_foreign` (`document_type_config_id`),
  KEY `agency_compliance_provisions_branch_id_foreign` (`branch_id`),
  KEY `acp_agency_doctype_branch_deleted_idx` (`agency_id`,`document_type_config_id`,`branch_id`,`deleted_at`),
  CONSTRAINT `agency_compliance_provisions_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `agency_compliance_provisions_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `agency_compliance_provisions_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `agency_compliance_provisions_document_type_config_id_foreign` FOREIGN KEY (`document_type_config_id`) REFERENCES `agency_document_type_configs` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `agency_contact_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `agency_contact_settings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `sharing_mode` enum('open','branch','closed') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'branch',
  `buyer_pipeline_default_scope` enum('own','branch','agency') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'own' COMMENT 'Default pipeline view scope for agents. Independent of contact access.',
  `buyer_kanban_column_limit` smallint unsigned NOT NULL DEFAULT '50',
  `duplicate_mode` enum('auto_link','soft_warn','hard_block_override','hard_block_request') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'soft_warn',
  `duplicate_match_fields` json DEFAULT NULL,
  `address_match_mode` enum('off','standard','strict') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'standard',
  `warn_on_held_address_capture` tinyint(1) NOT NULL DEFAULT '1',
  `portal_lead_auto_seed_buyer` tinyint(1) NOT NULL DEFAULT '1',
  `buyer_warm_days` int unsigned NOT NULL DEFAULT '14',
  `buyer_cold_days` int unsigned NOT NULL DEFAULT '30',
  `buyer_lost_days` int unsigned NOT NULL DEFAULT '60',
  `core_matches_working_window_days` smallint unsigned DEFAULT NULL,
  `core_matches_allowed_statuses` json DEFAULT NULL,
  `core_matches_price_drop_threshold_pct` tinyint unsigned NOT NULL DEFAULT '3',
  `outreach_no_response_days` smallint unsigned NOT NULL DEFAULT '7',
  `min_countable_criteria` json DEFAULT NULL,
  `mic_match_threshold` tinyint unsigned NOT NULL DEFAULT '75',
  `mic_price_band_pct` tinyint unsigned NOT NULL DEFAULT '10',
  `contact_retention_years` int unsigned NOT NULL DEFAULT '5',
  `consent_retention_years` int unsigned NOT NULL DEFAULT '5',
  `access_log_retention_years` int unsigned NOT NULL DEFAULT '5',
  `calendar_max_occurrences` smallint unsigned DEFAULT NULL COMMENT 'Max occurrences materialised per recurring series per query',
  `calendar_max_expansion_days` smallint unsigned DEFAULT NULL COMMENT 'Max days a query window is expanded for recurring series',
  `calendar_deck_slots` tinyint unsigned DEFAULT NULL,
  `calendar_grid_max_rows` tinyint unsigned DEFAULT NULL,
  `calendar_poll_seconds` smallint unsigned DEFAULT NULL,
  `calendar_category_groups` json DEFAULT NULL,
  `calendar_default_layers` json DEFAULT NULL,
  `calendar_default_deck_layouts` json DEFAULT NULL,
  `calendar_reminder_lead_options` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `agency_contact_settings_agency_id_unique` (`agency_id`),
  CONSTRAINT `agency_contact_settings_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=55 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `agency_dashboard_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `agency_dashboard_settings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `idle_alerts_enabled` tinyint(1) NOT NULL DEFAULT '1',
  `idle_threshold_days` smallint unsigned NOT NULL DEFAULT '14',
  `idle_alert_day` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `idle_alert_time` time NOT NULL DEFAULT '08:00:00',
  `doc_reminders_enabled` tinyint(1) NOT NULL DEFAULT '1',
  `doc_reminder_hours_before` smallint unsigned NOT NULL DEFAULT '24',
  `lease_expiry_reminders` tinyint(1) NOT NULL DEFAULT '1',
  `lease_reminder_days_before` smallint unsigned NOT NULL DEFAULT '90',
  `fica_reminders` tinyint(1) NOT NULL DEFAULT '1',
  `ffc_reminders` tinyint(1) NOT NULL DEFAULT '1',
  `task_due_reminders` tinyint(1) NOT NULL DEFAULT '1',
  `task_reminder_hours_before` smallint unsigned NOT NULL DEFAULT '4',
  `event_reminder_hours_before` smallint unsigned NOT NULL DEFAULT '24',
  `event_reminder_minutes_before` smallint unsigned NOT NULL DEFAULT '60',
  `auto_archive_done_days` smallint unsigned DEFAULT NULL,
  `overdue_daily_digest` tinyint(1) NOT NULL DEFAULT '1',
  `digest_time` time NOT NULL DEFAULT '08:00:00',
  `default_calendar_view` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'month',
  `weekend_visible` tinyint(1) NOT NULL DEFAULT '0',
  `working_hours_start` time NOT NULL DEFAULT '08:00:00',
  `working_hours_end` time NOT NULL DEFAULT '17:00:00',
  `notify_in_app` tinyint(1) NOT NULL DEFAULT '1',
  `notify_email` tinyint(1) NOT NULL DEFAULT '1',
  `notify_push` tinyint(1) NOT NULL DEFAULT '1',
  `open_hours_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `open_hours_start` time NOT NULL DEFAULT '07:00:00',
  `open_hours_end` time NOT NULL DEFAULT '21:00:00',
  `open_hours_day_windows` json DEFAULT NULL,
  `min_minutes_between_same` smallint unsigned NOT NULL DEFAULT '360',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `agency_dashboard_settings_agency_id_unique` (`agency_id`),
  CONSTRAINT `agency_dashboard_settings_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `agency_deal_sync_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `agency_deal_sync_settings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `flag_property_under_offer_on_deal` tinyint(1) NOT NULL DEFAULT '0',
  `sold_milestone` enum('granted','registered') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `revert_property_on_deal_declined` tinyint(1) NOT NULL DEFAULT '1',
  `max_email_attachment_mb` int unsigned NOT NULL DEFAULT '20',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `agency_deal_sync_settings_agency_id_unique` (`agency_id`),
  CONSTRAINT `agency_deal_sync_settings_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `agency_document_type_compliance`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `agency_document_type_compliance` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `document_type_id` bigint unsigned NOT NULL,
  `is_compliance_required` tinyint(1) NOT NULL DEFAULT '0',
  `save_to_property` tinyint(1) DEFAULT NULL,
  `save_to_contact` tinyint(1) DEFAULT NULL,
  `contact_roles` json DEFAULT NULL,
  `fica_slot` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `buyer_pack_eligible` tinyint(1) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `agency_doc_type_compliance_unique` (`agency_id`,`document_type_id`),
  KEY `agency_document_type_compliance_document_type_id_foreign` (`document_type_id`),
  KEY `agency_doc_type_compliance_lookup` (`agency_id`,`is_compliance_required`),
  CONSTRAINT `agency_document_type_compliance_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `agency_document_type_compliance_document_type_id_foreign` FOREIGN KEY (`document_type_id`) REFERENCES `document_types` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `agency_document_type_configs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `agency_document_type_configs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `satisfies_group` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `has_expiry` tinyint(1) NOT NULL DEFAULT '1',
  `renewal_days` int unsigned DEFAULT NULL,
  `required` tinyint(1) NOT NULL DEFAULT '1',
  `sort_order` int unsigned NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `agency_document_type_configs_agency_id_slug_unique` (`agency_id`,`slug`),
  KEY `agency_document_type_configs_agency_id_is_active_index` (`agency_id`,`is_active`),
  CONSTRAINT `agency_document_type_configs_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `agency_features`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `agency_features` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `feature_key` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT '1',
  `updated_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `agency_features_agency_id_feature_key_unique` (`agency_id`,`feature_key`),
  KEY `agency_features_updated_by_foreign` (`updated_by`),
  KEY `agency_features_feature_key_index` (`feature_key`),
  CONSTRAINT `agency_features_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `agency_features_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `agency_feedback_options`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `agency_feedback_options` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned DEFAULT NULL,
  `category` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `label` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `sort_order` int NOT NULL DEFAULT '0',
  `is_system_default` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `afo_lookup_idx` (`agency_id`,`category`,`is_active`),
  CONSTRAINT `agency_feedback_options_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `agency_leave_visibility_matrix`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `agency_leave_visibility_matrix` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `viewing_role` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `leave_owner_role` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `same_branch_only` tinyint(1) NOT NULL DEFAULT '1',
  `can_see` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `alvm_agency_viewer_owner_branch_unique` (`agency_id`,`viewing_role`,`leave_owner_role`,`same_branch_only`),
  CONSTRAINT `agency_leave_visibility_matrix_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=811 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `agency_lost_deal_reasons`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `agency_lost_deal_reasons` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `code` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `label` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `category` enum('price','location','property','financial','timing','agent_service','competition','other') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `applies_to_buyers` tinyint(1) NOT NULL DEFAULT '1',
  `applies_to_sellers` tinyint(1) NOT NULL DEFAULT '0',
  `requires_notes` tinyint(1) NOT NULL DEFAULT '0',
  `active` tinyint(1) NOT NULL DEFAULT '1',
  `display_order` smallint unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `agency_lost_deal_reasons_agency_id_code_unique` (`agency_id`,`code`),
  CONSTRAINT `agency_lost_deal_reasons_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `agency_map_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `agency_map_settings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `center_lat` decimal(10,7) DEFAULT NULL,
  `center_lng` decimal(10,7) DEFAULT NULL,
  `default_zoom` tinyint unsigned DEFAULT NULL,
  `bounds_north` decimal(10,7) DEFAULT NULL,
  `bounds_south` decimal(10,7) DEFAULT NULL,
  `bounds_east` decimal(10,7) DEFAULT NULL,
  `bounds_west` decimal(10,7) DEFAULT NULL,
  `default_sold_window` varchar(8) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `layer_caps` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `agency_map_settings_agency_unique` (`agency_id`),
  CONSTRAINT `agency_map_settings_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `agency_onboarding_setups`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `agency_onboarding_setups` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `token` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `admin_user_id` bigint unsigned DEFAULT NULL,
  `invite_email_sent_at` timestamp NULL DEFAULT NULL,
  `current_step` tinyint unsigned NOT NULL DEFAULT '1',
  `completed_steps` json DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `revoked_at` timestamp NULL DEFAULT NULL,
  `revoked_reason` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_opened_at` timestamp NULL DEFAULT NULL,
  `open_count` int unsigned NOT NULL DEFAULT '0',
  `completed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `agency_onboarding_setups_token_unique` (`token`),
  UNIQUE KEY `agency_onboarding_setups_slug_unique` (`slug`),
  KEY `agency_onboarding_setups_created_by_foreign` (`created_by`),
  KEY `agency_onboarding_setups_admin_user_id_foreign` (`admin_user_id`),
  KEY `agency_onboarding_setups_agency_id_revoked_at_completed_at_index` (`agency_id`,`revoked_at`,`completed_at`),
  KEY `agency_onboarding_setups_agency_id_index` (`agency_id`),
  CONSTRAINT `agency_onboarding_setups_admin_user_id_foreign` FOREIGN KEY (`admin_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `agency_onboarding_setups_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `agency_policies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `agency_policies` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `policy_key` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `agency_policies_agency_id_policy_key_unique` (`agency_id`,`policy_key`),
  KEY `agency_policies_agency_id_is_active_index` (`agency_id`,`is_active`),
  CONSTRAINT `agency_policies_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `agency_proforma_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `agency_proforma_settings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `number_prefix` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'PRO-',
  `next_number` int unsigned NOT NULL DEFAULT '1',
  `number_padding` tinyint unsigned NOT NULL DEFAULT '4',
  `due_date_rule` enum('end_of_month','days_after','on_receipt') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'end_of_month',
  `due_days` int unsigned NOT NULL DEFAULT '30',
  `bank_details` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `agency_proforma_settings_agency_id_unique` (`agency_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `agency_service_provider_contacts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `agency_service_provider_contacts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `service_provider_id` bigint unsigned NOT NULL,
  `attorney_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `contact_person` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `role` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `default_delivery_mode` enum('secure_link','direct_attachment') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `default_channel` enum('email','whatsapp') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_by_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `agency_service_provider_contacts_service_provider_id_foreign` (`service_provider_id`),
  KEY `aspc_agency_firm_idx` (`agency_id`,`service_provider_id`),
  CONSTRAINT `agency_service_provider_contacts_service_provider_id_foreign` FOREIGN KEY (`service_provider_id`) REFERENCES `agency_service_providers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `agency_service_provider_service_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `agency_service_provider_service_types` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `service_provider_id` bigint unsigned NOT NULL,
  `service_type` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `aspst_agency_type_idx` (`agency_id`,`service_type`),
  KEY `aspst_provider_idx` (`service_provider_id`),
  CONSTRAINT `aspst_provider_fk` FOREIGN KEY (`service_provider_id`) REFERENCES `agency_service_providers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `agency_service_providers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `agency_service_providers` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `contact_id` bigint unsigned DEFAULT NULL,
  `name` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `specialty` enum('electrician','entomologist','plumber','gas','electric_fence','transfer_attorney','bond_attorney','conveyancer','bond_originator','external_agency','other') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'other',
  `is_transfer_attorney` tinyint(1) NOT NULL DEFAULT '0',
  `is_bond_attorney` tinyint(1) NOT NULL DEFAULT '0',
  `company` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `registration_number` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `address` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `is_preferred` tinyint(1) NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_by_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `agency_service_providers_agency_id_specialty_is_active_index` (`agency_id`,`specialty`,`is_active`),
  KEY `agency_service_providers_agency_id_is_preferred_index` (`agency_id`,`is_preferred`),
  KEY `asp_transfer_cap_idx` (`agency_id`,`is_transfer_attorney`,`is_active`),
  KEY `asp_bond_cap_idx` (`agency_id`,`is_bond_attorney`,`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `agency_service_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `agency_service_types` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `code` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `label` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `sort_order` int unsigned NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `agency_service_types_agency_id_code_index` (`agency_id`,`code`),
  KEY `agency_service_types_agency_id_index` (`agency_id`)
) ENGINE=InnoDB AUTO_INCREMENT=325 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `agency_signing_parties`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `agency_signing_parties` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `sort_order` int NOT NULL DEFAULT '0',
  `is_default` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `agency_signing_parties_agency_id_deleted_at_index` (`agency_id`,`deleted_at`),
  CONSTRAINT `agency_signing_parties_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `agency_subscriptions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `agency_subscriptions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `plan` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'team',
  `plan_changed_at` timestamp NULL DEFAULT NULL,
  `custom_amount_zar` decimal(12,2) DEFAULT NULL,
  `custom_amount_note` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `discount_percent` decimal(5,2) DEFAULT NULL,
  `discount_months` smallint unsigned DEFAULT NULL,
  `discount_starts_on` date DEFAULT NULL,
  `discount_note` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `agency_subscriptions_agency_id_unique` (`agency_id`),
  KEY `agency_subscriptions_plan_index` (`plan`),
  CONSTRAINT `agency_subscriptions_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `agency_transformation_notes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `agency_transformation_notes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `entry_type` enum('structured','document') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `structured_data` json DEFAULT NULL,
  `document_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `document_original_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `summary` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_by_user_id` bigint unsigned NOT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `agency_transformation_notes_created_by_user_id_foreign` (`created_by_user_id`),
  KEY `agency_transformation_notes_agency_id_created_at_index` (`agency_id`,`created_at`),
  CONSTRAINT `agency_transformation_notes_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `agency_transformation_notes_created_by_user_id_foreign` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `agency_webhook_deliveries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `agency_webhook_deliveries` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `agency_api_key_id` bigint unsigned NOT NULL,
  `event_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` json NOT NULL,
  `response_status` smallint unsigned DEFAULT NULL,
  `attempts` int unsigned NOT NULL DEFAULT '0',
  `delivered_at` timestamp NULL DEFAULT NULL,
  `next_retry_at` timestamp NULL DEFAULT NULL,
  `failed_at` timestamp NULL DEFAULT NULL,
  `last_error` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `agency_webhook_deliveries_agency_api_key_id_event_name_index` (`agency_api_key_id`,`event_name`),
  KEY `agency_webhook_deliveries_next_retry_at_index` (`next_retry_at`),
  KEY `agency_webhook_deliveries_agency_id_index` (`agency_id`),
  KEY `agency_webhook_deliveries_agency_api_key_id_index` (`agency_api_key_id`),
  CONSTRAINT `agency_webhook_deliveries_agency_api_key_id_foreign` FOREIGN KEY (`agency_api_key_id`) REFERENCES `agency_api_keys` (`id`) ON DELETE CASCADE,
  CONSTRAINT `agency_webhook_deliveries_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `agent_activity_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `agent_activity_events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned DEFAULT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `event_type` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'e.g. claim.created, pitch.sent, whatsapp.sent, feedback.recorded, property.created, mandate.signed',
  `subject_type` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `subject_id` bigint unsigned DEFAULT NULL,
  `payload` json DEFAULT NULL COMMENT 'Event-specific data. Schema varies by event_type — interpret per the listener.',
  `occurred_at` timestamp NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_aae_agency_user_time` (`agency_id`,`user_id`,`occurred_at`),
  KEY `idx_aae_event_time` (`event_type`,`occurred_at`),
  KEY `idx_aae_subject` (`subject_type`,`subject_id`),
  KEY `agent_activity_events_user_id_foreign` (`user_id`),
  CONSTRAINT `agent_activity_events_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE SET NULL,
  CONSTRAINT `agent_activity_events_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=61 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Append-only agent activity log. Morphable subject. No updated_at.';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `agent_applications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `agent_applications` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `branch_id` bigint unsigned DEFAULT NULL,
  `first_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `last_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `phone` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `id_number` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `current_agency` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `years_experience` int NOT NULL DEFAULT '0',
  `ffc_number` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ffc_expiry` date DEFAULT NULL,
  `ppra_status` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `designation` enum('property_practitioner','candidate_practitioner','intern') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'property_practitioner',
  `motivation` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `referral_source` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `referred_by_user_id` bigint unsigned DEFAULT NULL,
  `status` enum('applied','documents_pending','compliance_review','mentor_assignment','training','activated','rejected','withdrawn') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'applied',
  `status_changed_at` timestamp NULL DEFAULT NULL,
  `status_notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `reviewed_by` bigint unsigned DEFAULT NULL,
  `activated_at` timestamp NULL DEFAULT NULL,
  `activated_by` bigint unsigned DEFAULT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `agent_applications_status_index` (`status`),
  KEY `agent_applications_agency_id_index` (`agency_id`),
  KEY `agent_applications_email_index` (`email`),
  KEY `agent_applications_referred_by_user_id_foreign` (`referred_by_user_id`),
  KEY `agent_applications_reviewed_by_foreign` (`reviewed_by`),
  KEY `agent_applications_activated_by_foreign` (`activated_by`),
  KEY `agent_applications_user_id_foreign` (`user_id`),
  KEY `agent_applications_branch_id_foreign` (`branch_id`),
  KEY `agent_applications_agency_branch_idx` (`agency_id`,`branch_id`),
  CONSTRAINT `agent_applications_activated_by_foreign` FOREIGN KEY (`activated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `agent_applications_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `agent_applications_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `agent_applications_referred_by_user_id_foreign` FOREIGN KEY (`referred_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `agent_applications_reviewed_by_foreign` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `agent_applications_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `agent_articles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `agent_articles` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `excerpt` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cover_image_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `body` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `link_url` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tags` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_published` tinyint(1) NOT NULL DEFAULT '0',
  `published_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `agent_articles_agency_id_user_id_index` (`agency_id`,`user_id`),
  KEY `agent_articles_user_id_is_published_index` (`user_id`,`is_published`),
  CONSTRAINT `agent_articles_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `agent_articles_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `agent_cap_periods`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `agent_cap_periods` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `period_start` date NOT NULL,
  `period_end` date NOT NULL,
  `cap_amount` decimal(12,2) NOT NULL,
  `company_dollar_paid` decimal(12,2) NOT NULL DEFAULT '0.00',
  `is_capped` tinyint(1) NOT NULL DEFAULT '0',
  `capped_at` timestamp NULL DEFAULT NULL,
  `post_cap_fees_paid` decimal(10,2) NOT NULL DEFAULT '0.00',
  `risk_fees_paid` decimal(10,2) NOT NULL DEFAULT '0.00',
  `transactions_count` int NOT NULL DEFAULT '0',
  `transactions_mentored` int NOT NULL DEFAULT '0',
  `gross_commission_income` decimal(14,2) NOT NULL DEFAULT '0.00',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `agent_cap_periods_user_id_period_start_index` (`user_id`,`period_start`),
  KEY `agent_cap_periods_agency_id_foreign` (`agency_id`),
  CONSTRAINT `agent_cap_periods_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `agent_cap_periods_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `agent_capture_consent`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `agent_capture_consent` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `agent_user_id` bigint unsigned NOT NULL,
  `contact_id` bigint unsigned NOT NULL,
  `status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `decided_at` timestamp NULL DEFAULT NULL,
  `decided_by_user_id` bigint unsigned DEFAULT NULL,
  `admin_flagged` tinyint(1) NOT NULL DEFAULT '0',
  `admin_flag_note` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `admin_flag_by_user_id` bigint unsigned DEFAULT NULL,
  `admin_flagged_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `acc_agency_agent_contact_uq` (`agency_id`,`agent_user_id`,`contact_id`),
  KEY `acc_agent_fk` (`agent_user_id`),
  KEY `acc_contact_fk` (`contact_id`),
  KEY `acc_decided_by_fk` (`decided_by_user_id`),
  KEY `acc_flag_by_fk` (`admin_flag_by_user_id`),
  KEY `acc_agent_status_idx` (`agency_id`,`agent_user_id`,`status`),
  KEY `acc_agency_status_idx` (`agency_id`,`status`),
  CONSTRAINT `acc_agent_fk` FOREIGN KEY (`agent_user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `acc_contact_fk` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`),
  CONSTRAINT `acc_decided_by_fk` FOREIGN KEY (`decided_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `acc_flag_by_fk` FOREIGN KEY (`admin_flag_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `agent_capture_consent_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `agent_mentors`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `agent_mentors` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `mentee_user_id` bigint unsigned NOT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `mentor_user_id` bigint unsigned NOT NULL,
  `assigned_at` date NOT NULL,
  `graduated_at` date DEFAULT NULL,
  `transactions_completed` int NOT NULL DEFAULT '0',
  `transactions_required` int NOT NULL DEFAULT '3',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `agent_mentors_mentee_user_id_unique` (`mentee_user_id`),
  KEY `agent_mentors_mentor_user_id_foreign` (`mentor_user_id`),
  KEY `agent_mentors_agency_id_idx` (`agency_id`),
  CONSTRAINT `agent_mentors_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `agent_mentors_mentee_user_id_foreign` FOREIGN KEY (`mentee_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `agent_mentors_mentor_user_id_foreign` FOREIGN KEY (`mentor_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `agent_overrides`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `agent_overrides` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `presentation_version_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `override_type` enum('comp_excluded','comp_included','category_added','category_removed','condition_changed','section_toggled','field_edited','review_takeover','comp_unavailable','comp_bulk_set','comp_added','size_lift_toggled') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `target_id` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `before_value` json DEFAULT NULL,
  `after_value` json NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `agent_overrides_user_id_foreign` (`user_id`),
  KEY `idx_av_version_type` (`presentation_version_id`,`override_type`),
  KEY `idx_av_agency_created` (`agency_id`,`created_at`),
  CONSTRAINT `agent_overrides_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `agent_overrides_presentation_version_id_foreign` FOREIGN KEY (`presentation_version_id`) REFERENCES `presentation_versions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `agent_overrides_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `agent_scorecards`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `agent_scorecards` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `period_type` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'daily, weekly, monthly',
  `period_start` date NOT NULL,
  `period_end` date NOT NULL,
  `tasks_completed` int unsigned NOT NULL DEFAULT '0',
  `tasks_overdue` int unsigned NOT NULL DEFAULT '0',
  `tasks_total` int unsigned NOT NULL DEFAULT '0',
  `properties_attended` int unsigned NOT NULL DEFAULT '0',
  `properties_total` int unsigned NOT NULL DEFAULT '0',
  `documents_uploaded` int unsigned NOT NULL DEFAULT '0',
  `fica_complete` int unsigned NOT NULL DEFAULT '0',
  `fica_total` int unsigned NOT NULL DEFAULT '0',
  `avg_response_hours` decimal(8,2) NOT NULL DEFAULT '0.00',
  `deals_progressed` int unsigned NOT NULL DEFAULT '0',
  `events_completed` int unsigned NOT NULL DEFAULT '0',
  `events_total` int unsigned NOT NULL DEFAULT '0',
  `activity_points` int unsigned NOT NULL DEFAULT '0',
  `overall_score` tinyint unsigned NOT NULL DEFAULT '0' COMMENT '0-100',
  `computed_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `agent_scorecards_user_id_period_type_period_start_unique` (`user_id`,`period_type`,`period_start`),
  KEY `agent_scorecards_agency_id_idx` (`agency_id`),
  CONSTRAINT `agent_scorecards_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `agent_scorecards_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `agent_seat_releases`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `agent_seat_releases` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `released_at` timestamp NOT NULL,
  `released_by_user_id` bigint unsigned DEFAULT NULL,
  `release_reason` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `reinstatable_at` timestamp NOT NULL,
  `reinstated_at` timestamp NULL DEFAULT NULL,
  `reinstated_by_user_id` bigint unsigned DEFAULT NULL,
  `reinstated_via` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `override_reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `agent_seat_releases_released_by_user_id_foreign` (`released_by_user_id`),
  KEY `agent_seat_releases_reinstated_by_user_id_foreign` (`reinstated_by_user_id`),
  KEY `asr_user_open_idx` (`user_id`,`reinstated_at`),
  KEY `asr_agency_released_idx` (`agency_id`,`released_at`),
  CONSTRAINT `agent_seat_releases_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `agent_seat_releases_reinstated_by_user_id_foreign` FOREIGN KEY (`reinstated_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `agent_seat_releases_released_by_user_id_foreign` FOREIGN KEY (`released_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `agent_seat_releases_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `agent_signatures`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `agent_signatures` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `signature_image` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `initial_image` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `signing_pin` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pin_set_at` timestamp NULL DEFAULT NULL,
  `images_updated_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `agent_signatures_user_id_unique` (`user_id`),
  KEY `agent_signatures_agency_id_index` (`agency_id`),
  CONSTRAINT `agent_signatures_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `agent_social_accounts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `agent_social_accounts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `platform` enum('facebook','instagram') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `platform_page_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `platform_page_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `access_token` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `token_expires_at` timestamp NULL DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `agent_social_accounts_user_id_platform_unique` (`user_id`,`platform`),
  KEY `agent_social_accounts_agency_id_idx` (`agency_id`),
  CONSTRAINT `agent_social_accounts_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `agent_social_accounts_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `agent_sponsorships`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `agent_sponsorships` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agent_user_id` bigint unsigned NOT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `sponsor_user_id` bigint unsigned NOT NULL,
  `sponsored_at` date NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `agent_sponsorships_agent_user_id_unique` (`agent_user_id`),
  KEY `agent_sponsorships_sponsor_user_id_index` (`sponsor_user_id`),
  KEY `agent_sponsorships_agency_id_idx` (`agency_id`),
  CONSTRAINT `agent_sponsorships_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `agent_sponsorships_agent_user_id_foreign` FOREIGN KEY (`agent_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `agent_sponsorships_sponsor_user_id_foreign` FOREIGN KEY (`sponsor_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ai_conversations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ai_conversations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `last_message_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ai_conversations_user_id_index` (`user_id`),
  CONSTRAINT `ai_conversations_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ai_daily_briefings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ai_daily_briefings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `briefing_date` date NOT NULL,
  `content` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `data_snapshot` json DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT '0',
  `read_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ai_daily_briefings_user_id_briefing_date_unique` (`user_id`,`briefing_date`),
  KEY `ai_daily_briefings_user_id_is_read_index` (`user_id`,`is_read`),
  CONSTRAINT `ai_daily_briefings_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ai_feedback`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ai_feedback` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `message_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `rating` enum('up','down') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `comment` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ai_feedback_message_id_user_id_unique` (`message_id`,`user_id`),
  KEY `ai_feedback_user_id_foreign` (`user_id`),
  CONSTRAINT `ai_feedback_message_id_foreign` FOREIGN KEY (`message_id`) REFERENCES `ai_messages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ai_feedback_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ai_messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ai_messages` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `conversation_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `role` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `content` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `prompt_tokens` int unsigned DEFAULT NULL,
  `completion_tokens` int unsigned DEFAULT NULL,
  `total_tokens` int unsigned DEFAULT NULL,
  `cost_cents` int unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ai_messages_conversation_id_index` (`conversation_id`),
  KEY `ai_messages_user_id_index` (`user_id`),
  KEY `ai_messages_role_index` (`role`),
  CONSTRAINT `ai_messages_conversation_id_foreign` FOREIGN KEY (`conversation_id`) REFERENCES `ai_conversations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ai_messages_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ai_narrative_cache`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ai_narrative_cache` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned DEFAULT NULL,
  `narrative_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'weekly_brief | tile_copy | listing_tooltip | suburb_pocket | audit_finding',
  `cache_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Composed deterministically, e.g. weekly_brief:agency:1:week:2026-21',
  `input_hash` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'sha256 of the input data — mismatch forces regeneration.',
  `prompt_version` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Track prompt evolution for A/B comparison.',
  `model` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'e.g. claude-haiku-4-5, claude-sonnet-4-6',
  `input_tokens` int NOT NULL DEFAULT '0',
  `output_tokens` int NOT NULL DEFAULT '0',
  `cost_zar` decimal(10,4) NOT NULL DEFAULT '0.0000',
  `output_text` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `output_json` json DEFAULT NULL COMMENT 'When structured output required.',
  `generated_at` timestamp NOT NULL,
  `expires_at` timestamp NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_anc_cache_key_deleted_at` (`cache_key`,`deleted_at`),
  KEY `ai_narrative_cache_agency_id_foreign` (`agency_id`),
  KEY `idx_anc_type_expires` (`narrative_type`,`expires_at`),
  CONSTRAINT `ai_narrative_cache_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Ellie narrative cache with token + cost tracking. agency_id nullable for global narratives.';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ai_usage_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ai_usage_events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned DEFAULT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `source` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Stable surface key — mic_narrative | mobile_voice | image_analysis | docuperfect_* | marketing_copy | presentation_evidence',
  `surface_ref` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Correlation handle for drill-down — cache_key, analysis_id, template_id, etc.',
  `model` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Resolved model id, e.g. claude-haiku-4-5-20251001. " (fallback)" suffix mirrors the cache convention.',
  `input_tokens` int unsigned NOT NULL DEFAULT '0',
  `output_tokens` int unsigned NOT NULL DEFAULT '0',
  `cost_zar` decimal(10,4) NOT NULL DEFAULT '0.0000',
  `cache_hit` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'True when served from cache (no API spend). Drives the exact cache-hit-rate metric.',
  `fallback` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'True when the call degraded to fallback text (cost 0).',
  `occurred_at` timestamp NOT NULL COMMENT 'When the call happened.',
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ai_usage_events_user_id_foreign` (`user_id`),
  KEY `idx_aue_agency_occurred` (`agency_id`,`occurred_at`),
  KEY `idx_aue_source_occurred` (`source`,`occurred_at`),
  KEY `idx_aue_occurred` (`occurred_at`),
  CONSTRAINT `ai_usage_events_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE SET NULL,
  CONSTRAINT `ai_usage_events_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Append-only AI cost ledger — one row per Anthropic call, every surface. Source of truth for spend + budget.';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `amendment_acceptances`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `amendment_acceptances` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `amendment_id` bigint unsigned NOT NULL,
  `signature_request_id` bigint unsigned NOT NULL,
  `accepted` tinyint(1) NOT NULL DEFAULT '0',
  `rejected` tinyint(1) NOT NULL DEFAULT '0',
  `rejection_reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `initial_image` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `amendment_acceptances_amendment_id_signature_request_id_unique` (`amendment_id`,`signature_request_id`),
  KEY `amendment_acceptances_signature_request_id_index` (`signature_request_id`),
  CONSTRAINT `amendment_acceptances_amendment_id_foreign` FOREIGN KEY (`amendment_id`) REFERENCES `document_amendments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `amendment_acceptances_signature_request_id_foreign` FOREIGN KEY (`signature_request_id`) REFERENCES `signature_requests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `application_documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `application_documents` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `application_id` bigint unsigned NOT NULL,
  `document_type` enum('id_copy','ffc_certificate','qualifications','pi_insurance','tax_clearance','proof_of_address','cv','other') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_path` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('uploaded','verified','rejected') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'uploaded',
  `rejection_reason` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `verified_by` bigint unsigned DEFAULT NULL,
  `verified_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `application_documents_application_id_foreign` (`application_id`),
  KEY `application_documents_verified_by_foreign` (`verified_by`),
  CONSTRAINT `application_documents_application_id_foreign` FOREIGN KEY (`application_id`) REFERENCES `agent_applications` (`id`) ON DELETE CASCADE,
  CONSTRAINT `application_documents_verified_by_foreign` FOREIGN KEY (`verified_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `article_pool`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `article_pool` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `source` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `url` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `url_hash` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `snippet` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `published_at` timestamp NULL DEFAULT NULL,
  `tags_json` json DEFAULT NULL,
  `scraped_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `article_pool_url_hash_unique` (`url_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `assistant_activity_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `assistant_activity_log` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `assistant_assignment_id` bigint unsigned NOT NULL,
  `assistant_user_id` bigint unsigned NOT NULL,
  `agent_user_id` bigint unsigned DEFAULT NULL,
  `action` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `subject_type` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `subject_id` bigint unsigned DEFAULT NULL,
  `subject_label` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `route_name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `url` varchar(300) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `method` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `assistant_activity_log_agency_id_foreign` (`agency_id`),
  KEY `aal_agent_fk` (`agent_user_id`),
  KEY `aal_assignment_time_idx` (`assistant_assignment_id`,`created_at`),
  KEY `aal_assistant_time_idx` (`assistant_user_id`,`created_at`),
  KEY `aal_subject_idx` (`subject_type`,`subject_id`),
  CONSTRAINT `aal_agent_fk` FOREIGN KEY (`agent_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `aal_assignment_fk` FOREIGN KEY (`assistant_assignment_id`) REFERENCES `assistant_assignments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `aal_assistant_fk` FOREIGN KEY (`assistant_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `assistant_activity_log_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `assistant_assignment_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `assistant_assignment_permissions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `assistant_assignment_id` bigint unsigned NOT NULL,
  `permission_key` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `granted` tinyint(1) NOT NULL DEFAULT '0',
  `scope` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_locked` tinyint(1) NOT NULL DEFAULT '0',
  `is_new` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `aap_assignment_key_unique` (`assistant_assignment_id`,`permission_key`),
  KEY `assistant_assignment_permissions_agency_id_foreign` (`agency_id`),
  KEY `assistant_assignment_permissions_permission_key_index` (`permission_key`),
  CONSTRAINT `aap_assignment_fk` FOREIGN KEY (`assistant_assignment_id`) REFERENCES `assistant_assignments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `assistant_assignment_permissions_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `assistant_assignments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `assistant_assignments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `branch_id` bigint unsigned DEFAULT NULL,
  `assistant_user_id` bigint unsigned NOT NULL,
  `agent_user_id` bigint unsigned NOT NULL,
  `status` enum('active','suspended','revoked') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `can_manage_my_records` tinyint(1) NOT NULL DEFAULT '1',
  `show_attribution` tinyint(1) NOT NULL DEFAULT '1',
  `notify_on_action` tinyint(1) NOT NULL DEFAULT '0',
  `can_download_documents` tinyint(1) NOT NULL DEFAULT '1',
  `suspend_reason` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `snapshot_taken_at` timestamp NULL DEFAULT NULL,
  `created_by_user_id` bigint unsigned DEFAULT NULL,
  `revoked_by_user_id` bigint unsigned DEFAULT NULL,
  `revoked_at` timestamp NULL DEFAULT NULL,
  `revoke_reason` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `active_assistant_user_id` bigint unsigned GENERATED ALWAYS AS (if(((`status` = _utf8mb4'active') and (`deleted_at` is null)),`assistant_user_id`,NULL)) STORED,
  PRIMARY KEY (`id`),
  UNIQUE KEY `assistant_one_active_agent` (`active_assistant_user_id`),
  KEY `assistant_assignments_branch_id_foreign` (`branch_id`),
  KEY `aa_created_by_fk` (`created_by_user_id`),
  KEY `aa_revoked_by_fk` (`revoked_by_user_id`),
  KEY `assistant_assignments_agency_id_status_index` (`agency_id`,`status`),
  KEY `assistant_assignments_agent_user_id_status_index` (`agent_user_id`,`status`),
  KEY `assistant_assignments_assistant_user_id_status_index` (`assistant_user_id`,`status`),
  CONSTRAINT `aa_agent_fk` FOREIGN KEY (`agent_user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `aa_assistant_fk` FOREIGN KEY (`assistant_user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `aa_created_by_fk` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `aa_revoked_by_fk` FOREIGN KEY (`revoked_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `assistant_assignments_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `assistant_assignments_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `assistant_linked_agents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `assistant_linked_agents` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `assistant_assignment_id` bigint unsigned NOT NULL,
  `agent_user_id` bigint unsigned NOT NULL,
  `added_by_user_id` bigint unsigned DEFAULT NULL,
  `removed_by_user_id` bigint unsigned DEFAULT NULL,
  `removed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `active_agent_user_id` bigint unsigned GENERATED ALWAYS AS (if((`deleted_at` is null),`agent_user_id`,NULL)) STORED,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ala_assignment_agent_unique` (`assistant_assignment_id`,`active_agent_user_id`),
  KEY `assistant_linked_agents_agency_id_foreign` (`agency_id`),
  KEY `ala_added_by_fk` (`added_by_user_id`),
  KEY `ala_removed_by_fk` (`removed_by_user_id`),
  KEY `assistant_linked_agents_assistant_assignment_id_index` (`assistant_assignment_id`),
  KEY `assistant_linked_agents_agent_user_id_index` (`agent_user_id`),
  CONSTRAINT `ala_added_by_fk` FOREIGN KEY (`added_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `ala_agent_fk` FOREIGN KEY (`agent_user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `ala_assignment_fk` FOREIGN KEY (`assistant_assignment_id`) REFERENCES `assistant_assignments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ala_removed_by_fk` FOREIGN KEY (`removed_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `assistant_linked_agents_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `automation_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `automation_log` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `rule_id` bigint unsigned NOT NULL,
  `trigger_model_type` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `trigger_model_id` bigint unsigned NOT NULL,
  `action_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `action_result_type` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `action_result_id` bigint unsigned DEFAULT NULL,
  `executed_at` datetime NOT NULL,
  `success` tinyint(1) NOT NULL DEFAULT '1',
  `error_message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `automation_log_rule_id_foreign` (`rule_id`),
  CONSTRAINT `automation_log_rule_id_foreign` FOREIGN KEY (`rule_id`) REFERENCES `automation_rules` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `automation_rules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `automation_rules` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `is_system` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'System rules cannot be deleted',
  `trigger_model` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Property, Contact, DealV2, User, etc.',
  `trigger_event` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'created, updated, status_changed, date_approaching, idle',
  `trigger_conditions` json DEFAULT NULL,
  `action_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'create_event, create_task, send_notification, create_event_and_task',
  `action_config` json NOT NULL,
  `agency_id` bigint unsigned DEFAULT NULL,
  `branch_id` bigint unsigned DEFAULT NULL,
  `sort_order` int NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `automation_rules_agency_id_foreign` (`agency_id`),
  KEY `automation_rules_branch_id_foreign` (`branch_id`),
  CONSTRAINT `automation_rules_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE SET NULL,
  CONSTRAINT `automation_rules_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `backup_password_reveals`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `backup_password_reveals` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `revealed_by` bigint unsigned NOT NULL,
  `revealed_by_agency_id` bigint unsigned DEFAULT NULL,
  `revealed_at` timestamp NOT NULL,
  `ip_address` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` varchar(512) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `bkp_rvl_by_fk` (`revealed_by`),
  KEY `bkp_rvl_at_idx` (`revealed_at`),
  CONSTRAINT `bkp_rvl_by_fk` FOREIGN KEY (`revealed_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `bedroom_segments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `bedroom_segments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `name` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `beds_min` tinyint unsigned NOT NULL,
  `beds_max` tinyint unsigned DEFAULT NULL,
  `display_order` smallint unsigned NOT NULL DEFAULT '0',
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `bed_seg_agency_order_idx` (`agency_id`,`display_order`),
  KEY `bed_seg_agency_range_idx` (`agency_id`,`beds_min`,`beds_max`),
  KEY `bed_seg_deleted_idx` (`deleted_at`),
  CONSTRAINT `bedroom_segments_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `branch_activity_columns`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `branch_activity_columns` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `branch_id` bigint unsigned NOT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_enabled` tinyint(1) NOT NULL DEFAULT '1',
  `sort_order` int unsigned DEFAULT NULL,
  `points_weight` decimal(8,2) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `branch_activity_columns_branch_id_key_unique` (`branch_id`,`key`),
  KEY `branch_activity_columns_agency_id_idx` (`agency_id`),
  CONSTRAINT `branch_activity_columns_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `branch_activity_columns_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `branch_assignments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `branch_assignments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `branch_id` bigint unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `branch_assignments_user_id_unique` (`user_id`),
  KEY `branch_assignments_branch_id_foreign` (`branch_id`),
  CONSTRAINT `branch_assignments_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE,
  CONSTRAINT `branch_assignments_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `branch_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `branch_settings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `branch_id` bigint unsigned NOT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `branch_settings_branch_id_key_unique` (`branch_id`,`key`),
  KEY `branch_settings_agency_id_idx` (`agency_id`),
  CONSTRAINT `branch_settings_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `branch_settings_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `branches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `branches` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned DEFAULT NULL,
  `trading_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tagline` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `address` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone_label` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone_secondary` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone_secondary_label` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fax` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reg_no` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `vat_no` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ffc_no` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ppra_number` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fic_no` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `p24_agency_id` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `syndication_override_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `pp_agency_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pp_credentials` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `p24_credentials` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `logo_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `code` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `privacy_policy_markdown` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `privacy_policy_token` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `privacy_policy_published_at` timestamp NULL DEFAULT NULL,
  `website_order` int unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `branches_privacy_policy_token_unique` (`privacy_policy_token`),
  KEY `branches_agency_id_foreign` (`agency_id`),
  CONSTRAINT `branches_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=55 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `bulk_email_broadcasts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `bulk_email_broadcasts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `subject` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `body` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `target_type` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `target_agency_id` bigint unsigned DEFAULT NULL,
  `recipient_count` int unsigned NOT NULL,
  `sent_by_user_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `bulk_email_broadcasts_target_agency_id_foreign` (`target_agency_id`),
  KEY `bulk_email_broadcasts_sent_by_user_id_foreign` (`sent_by_user_id`),
  KEY `bulk_email_broadcasts_target_type_target_agency_id_index` (`target_type`,`target_agency_id`),
  KEY `bulk_email_broadcasts_created_at_index` (`created_at`),
  CONSTRAINT `bulk_email_broadcasts_sent_by_user_id_foreign` FOREIGN KEY (`sent_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `bulk_email_broadcasts_target_agency_id_foreign` FOREIGN KEY (`target_agency_id`) REFERENCES `agencies` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `buyer_activity_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `buyer_activity_log` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `contact_id` bigint unsigned NOT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `activity_type` enum('viewing_completed','presentation','contact_access','note_added','call_logged','email_sent','whatsapp_sent','manual','retention_action','feedback_captured') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `activity_date` timestamp NOT NULL,
  `related_event_id` bigint unsigned DEFAULT NULL,
  `related_property_id` bigint unsigned DEFAULT NULL,
  `related_feedback_id` bigint unsigned DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `logged_by_user_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `buyer_activity_log_related_event_id_foreign` (`related_event_id`),
  KEY `buyer_activity_log_related_property_id_foreign` (`related_property_id`),
  KEY `buyer_activity_log_related_feedback_id_foreign` (`related_feedback_id`),
  KEY `buyer_activity_log_logged_by_user_id_foreign` (`logged_by_user_id`),
  KEY `buyer_activity_log_contact_id_activity_date_index` (`contact_id`,`activity_date`),
  KEY `buyer_activity_log_agency_id_activity_type_activity_date_index` (`agency_id`,`activity_type`,`activity_date`),
  CONSTRAINT `buyer_activity_log_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `buyer_activity_log_contact_id_foreign` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `buyer_activity_log_logged_by_user_id_foreign` FOREIGN KEY (`logged_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `buyer_activity_log_related_event_id_foreign` FOREIGN KEY (`related_event_id`) REFERENCES `calendar_events` (`id`) ON DELETE SET NULL,
  CONSTRAINT `buyer_activity_log_related_feedback_id_foreign` FOREIGN KEY (`related_feedback_id`) REFERENCES `calendar_event_feedback` (`id`) ON DELETE SET NULL,
  CONSTRAINT `buyer_activity_log_related_property_id_foreign` FOREIGN KEY (`related_property_id`) REFERENCES `properties` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `buyer_client_page_links`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `buyer_client_page_links` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `contact_id` bigint unsigned NOT NULL,
  `slug` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `buyer_client_page_links_contact_id_unique` (`contact_id`),
  UNIQUE KEY `buyer_client_page_links_slug_unique` (`slug`),
  KEY `buyer_client_page_links_agency_id_foreign` (`agency_id`),
  CONSTRAINT `buyer_client_page_links_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `buyer_client_page_links_contact_id_foreign` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `buyer_lost_records`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `buyer_lost_records` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `contact_id` bigint unsigned NOT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `reason_code` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `reason_label` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `outcome` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `recorded_by_user_id` bigint unsigned DEFAULT NULL,
  `recorded_at` timestamp NOT NULL,
  `source` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'manual',
  `buyer_state_at_loss` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `days_in_pipeline_at_loss` int unsigned DEFAULT NULL,
  `days_since_last_activity_at_loss` int unsigned DEFAULT NULL,
  `agent_owner_user_id_at_loss` bigint unsigned DEFAULT NULL,
  `branch_id_at_loss` bigint unsigned DEFAULT NULL,
  `preapproval_amount_at_loss` decimal(14,2) DEFAULT NULL,
  `recovered_at` timestamp NULL DEFAULT NULL,
  `recovered_by_user_id` bigint unsigned DEFAULT NULL,
  `recovered_notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `buyer_lost_records_contact_id_foreign` (`contact_id`),
  KEY `buyer_lost_records_recorded_by_user_id_foreign` (`recorded_by_user_id`),
  KEY `buyer_lost_records_agent_owner_user_id_at_loss_foreign` (`agent_owner_user_id_at_loss`),
  KEY `buyer_lost_records_branch_id_at_loss_foreign` (`branch_id_at_loss`),
  KEY `buyer_lost_records_agency_id_recorded_at_index` (`agency_id`,`recorded_at`),
  KEY `buyer_lost_records_reason_code_recorded_at_index` (`reason_code`,`recorded_at`),
  KEY `buyer_lost_records_recovered_by_user_id_foreign` (`recovered_by_user_id`),
  CONSTRAINT `buyer_lost_records_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `buyer_lost_records_agent_owner_user_id_at_loss_foreign` FOREIGN KEY (`agent_owner_user_id_at_loss`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `buyer_lost_records_branch_id_at_loss_foreign` FOREIGN KEY (`branch_id_at_loss`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `buyer_lost_records_contact_id_foreign` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `buyer_lost_records_recorded_by_user_id_foreign` FOREIGN KEY (`recorded_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `buyer_lost_records_recovered_by_user_id_foreign` FOREIGN KEY (`recovered_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `buyer_lost_risk_scores`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `buyer_lost_risk_scores` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `contact_id` bigint unsigned NOT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `score` smallint unsigned NOT NULL,
  `factors_breakdown` json DEFAULT NULL,
  `computed_at` timestamp NOT NULL,
  PRIMARY KEY (`id`),
  KEY `buyer_lost_risk_scores_contact_id_computed_at_index` (`contact_id`,`computed_at`),
  KEY `buyer_lost_risk_scores_agency_id_idx` (`agency_id`),
  CONSTRAINT `buyer_lost_risk_scores_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `buyer_lost_risk_scores_contact_id_foreign` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `buyer_match_tiers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `buyer_match_tiers` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `strong_min_score` tinyint unsigned NOT NULL DEFAULT '80',
  `mid_min_score` tinyint unsigned NOT NULL DEFAULT '50',
  `weak_min_score` tinyint unsigned NOT NULL DEFAULT '0',
  `strong_label` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Strong',
  `mid_label` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Mid',
  `weak_label` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Weak',
  `show_weak_in_badge` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unq_buyer_match_tiers_agency` (`agency_id`),
  CONSTRAINT `buyer_match_tiers_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `buyer_portal_links`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `buyer_portal_links` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `contact_id` bigint unsigned NOT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `token` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `generated_by_user_id` bigint unsigned DEFAULT NULL,
  `generated_at` timestamp NOT NULL,
  `last_accessed_at` timestamp NULL DEFAULT NULL,
  `access_count` int unsigned NOT NULL DEFAULT '0',
  `revoked_at` timestamp NULL DEFAULT NULL,
  `revoked_by_user_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `buyer_portal_links_token_unique` (`token`),
  KEY `buyer_portal_links_contact_id_foreign` (`contact_id`),
  KEY `buyer_portal_links_generated_by_user_id_foreign` (`generated_by_user_id`),
  KEY `buyer_portal_links_revoked_by_user_id_foreign` (`revoked_by_user_id`),
  KEY `buyer_portal_links_agency_id_idx` (`agency_id`),
  CONSTRAINT `buyer_portal_links_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `buyer_portal_links_contact_id_foreign` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `buyer_portal_links_generated_by_user_id_foreign` FOREIGN KEY (`generated_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `buyer_portal_links_revoked_by_user_id_foreign` FOREIGN KEY (`revoked_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `buyer_preferences`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `buyer_preferences` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `contact_id` bigint unsigned NOT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `budget_min` decimal(14,2) DEFAULT NULL,
  `budget_max` decimal(14,2) DEFAULT NULL,
  `bedrooms_min` smallint unsigned DEFAULT NULL,
  `bedrooms_max` smallint unsigned DEFAULT NULL,
  `must_have_features` json DEFAULT NULL,
  `deal_breakers` json DEFAULT NULL,
  `preapproval_amount` decimal(14,2) DEFAULT NULL COMMENT 'Pre-approved amount in ZAR',
  `preapproval_expires_at` date DEFAULT NULL,
  `preapproval_institution` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `preferred_areas` json DEFAULT NULL,
  `preferred_property_types` json DEFAULT NULL,
  `updated_by_user_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `buyer_preferences_contact_id_unique` (`contact_id`),
  KEY `buyer_preferences_updated_by_user_id_foreign` (`updated_by_user_id`),
  KEY `buyer_preferences_agency_id_idx` (`agency_id`),
  CONSTRAINT `buyer_preferences_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `buyer_preferences_contact_id_foreign` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `buyer_preferences_updated_by_user_id_foreign` FOREIGN KEY (`updated_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `buyer_property_responses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `buyer_property_responses` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `contact_id` bigint unsigned NOT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `property_id` bigint unsigned NOT NULL,
  `response` enum('interested','not_interested','viewing_requested') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `reason` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `source` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'buyer_portal',
  `responded_at` timestamp NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `buyer_property_responses_property_id_foreign` (`property_id`),
  KEY `buyer_property_responses_contact_id_property_id_index` (`contact_id`,`property_id`),
  KEY `buyer_property_responses_agency_id_idx` (`agency_id`),
  CONSTRAINT `buyer_property_responses_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `buyer_property_responses_contact_id_foreign` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `buyer_property_responses_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `buyer_property_views`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `buyer_property_views` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `contact_id` bigint unsigned NOT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `property_id` bigint unsigned NOT NULL,
  `last_viewed_at` timestamp NULL DEFAULT NULL,
  `view_count` int unsigned NOT NULL DEFAULT '0',
  `most_recent_feedback_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `buyer_property_views_contact_id_property_id_unique` (`contact_id`,`property_id`),
  KEY `buyer_property_views_most_recent_feedback_id_foreign` (`most_recent_feedback_id`),
  KEY `buyer_property_views_property_id_last_viewed_at_index` (`property_id`,`last_viewed_at`),
  KEY `buyer_property_views_agency_id_idx` (`agency_id`),
  CONSTRAINT `buyer_property_views_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `buyer_property_views_contact_id_foreign` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `buyer_property_views_most_recent_feedback_id_foreign` FOREIGN KEY (`most_recent_feedback_id`) REFERENCES `calendar_event_feedback` (`id`) ON DELETE SET NULL,
  CONSTRAINT `buyer_property_views_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `buyer_state_transitions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `buyer_state_transitions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `contact_id` bigint unsigned NOT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `from_state` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `to_state` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `reason` enum('auto_recompute','manual_override','first_activity','wishlist_created','auto_landed','property_linked') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `triggered_by_user_id` bigint unsigned DEFAULT NULL,
  `occurred_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `buyer_state_transitions_contact_id_foreign` (`contact_id`),
  KEY `buyer_state_transitions_triggered_by_user_id_foreign` (`triggered_by_user_id`),
  KEY `buyer_state_transitions_agency_id_idx` (`agency_id`),
  CONSTRAINT `buyer_state_transitions_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `buyer_state_transitions_contact_id_foreign` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `buyer_state_transitions_triggered_by_user_id_foreign` FOREIGN KEY (`triggered_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cache`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cache` (
  `key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `expiration` int NOT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cache_locks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cache_locks` (
  `key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `owner` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `expiration` int NOT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `calculator_fee_scales`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `calculator_fee_scales` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `brackets` json NOT NULL,
  `source_document` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `effective_date` date DEFAULT NULL,
  `additional_costs_note` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `uploaded_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `calculator_fee_scales_uploaded_by_foreign` (`uploaded_by`),
  CONSTRAINT `calculator_fee_scales_uploaded_by_foreign` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `calendar_event_audit_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `calendar_event_audit_log` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `calendar_event_id` bigint unsigned NOT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `action` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `old_values` json DEFAULT NULL,
  `new_values` json DEFAULT NULL,
  `performed_by_user_id` bigint unsigned DEFAULT NULL,
  `performed_at` timestamp NOT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `on_behalf_of_user_id` bigint unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `calendar_event_audit_log_performed_by_user_id_foreign` (`performed_by_user_id`),
  KEY `cea_event_time_idx` (`calendar_event_id`,`performed_at`),
  KEY `calendar_event_audit_log_agency_id_idx` (`agency_id`),
  KEY `ceal_obo_fk` (`on_behalf_of_user_id`),
  CONSTRAINT `calendar_event_audit_log_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `calendar_event_audit_log_calendar_event_id_foreign` FOREIGN KEY (`calendar_event_id`) REFERENCES `calendar_events` (`id`) ON DELETE CASCADE,
  CONSTRAINT `calendar_event_audit_log_performed_by_user_id_foreign` FOREIGN KEY (`performed_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `ceal_obo_fk` FOREIGN KEY (`on_behalf_of_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `calendar_event_class_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `calendar_event_class_settings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned DEFAULT NULL,
  `event_class` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `event_nature` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'actionable',
  `green_days` smallint unsigned NOT NULL,
  `amber_days` smallint unsigned NOT NULL,
  `red_days` smallint unsigned NOT NULL,
  `show_days` smallint unsigned DEFAULT NULL,
  `green_visibility` json NOT NULL,
  `amber_visibility` json NOT NULL,
  `red_visibility` json NOT NULL,
  `green_notifications` json NOT NULL,
  `amber_notifications` json NOT NULL,
  `red_notifications` json NOT NULL,
  `daily_digest_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `daily_digest_roles` json DEFAULT NULL,
  `default_reminder_offsets` json DEFAULT NULL,
  `default_reminder_channels` json DEFAULT NULL,
  `allow_multiple_properties` tinyint(1) NOT NULL DEFAULT '0',
  `buyer_facing` tinyint(1) NOT NULL DEFAULT '0',
  `actor_role` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'neither',
  `autofill_buyers` tinyint(1) NOT NULL DEFAULT '0',
  `occupies_time` tinyint(1) NOT NULL DEFAULT '0',
  `completion_behaviour` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'freeform',
  `feedback_mode` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'per_contact',
  `label` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `cecs_agency_class_unique` (`agency_id`,`event_class`),
  KEY `calendar_event_class_settings_event_class_index` (`event_class`),
  CONSTRAINT `calendar_event_class_settings_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `calendar_event_feedback`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `calendar_event_feedback` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `calendar_event_id` bigint unsigned NOT NULL,
  `contact_id` bigint unsigned DEFAULT NULL,
  `feedback_kind` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'viewing',
  `property_id` bigint unsigned DEFAULT NULL,
  `outcome_option_id` bigint unsigned DEFAULT NULL,
  `concern_option_ids` json DEFAULT NULL,
  `seller_visible_notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `internal_notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `next_action_notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `captured_by_user_id` bigint unsigned DEFAULT NULL,
  `captured_at` timestamp NULL DEFAULT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `branch_id` bigint unsigned DEFAULT NULL,
  `visibility` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'public_to_seller',
  `kind_specific_data` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `cef_event_contact_property_unique` (`calendar_event_id`,`contact_id`,`property_id`),
  KEY `calendar_event_feedback_contact_id_foreign` (`contact_id`),
  KEY `calendar_event_feedback_outcome_option_id_foreign` (`outcome_option_id`),
  KEY `calendar_event_feedback_captured_by_user_id_foreign` (`captured_by_user_id`),
  KEY `calendar_event_feedback_branch_id_foreign` (`branch_id`),
  KEY `cef_agency_captured_idx` (`agency_id`,`captured_at`),
  KEY `calendar_event_feedback_property_id_foreign` (`property_id`),
  CONSTRAINT `calendar_event_feedback_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `calendar_event_feedback_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `calendar_event_feedback_calendar_event_id_foreign` FOREIGN KEY (`calendar_event_id`) REFERENCES `calendar_events` (`id`) ON DELETE CASCADE,
  CONSTRAINT `calendar_event_feedback_captured_by_user_id_foreign` FOREIGN KEY (`captured_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `calendar_event_feedback_contact_id_foreign` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `calendar_event_feedback_outcome_option_id_foreign` FOREIGN KEY (`outcome_option_id`) REFERENCES `agency_feedback_options` (`id`) ON DELETE SET NULL,
  CONSTRAINT `calendar_event_feedback_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `calendar_event_invitations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `calendar_event_invitations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `event_id` bigint unsigned NOT NULL,
  `agency_id` bigint unsigned DEFAULT NULL,
  `invitee_user_id` bigint unsigned NOT NULL,
  `inviter_user_id` bigint unsigned NOT NULL,
  `status` enum('pending','accepted','tentative','declined','cancelled') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `acknowledged_at` timestamp NULL DEFAULT NULL,
  `response_at` timestamp NULL DEFAULT NULL,
  `response_notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `conflict_at_invite` json DEFAULT NULL,
  `notified_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `calendar_event_invitations_event_id_invitee_user_id_unique` (`event_id`,`invitee_user_id`),
  KEY `calendar_event_invitations_inviter_user_id_foreign` (`inviter_user_id`),
  KEY `calendar_event_invitations_invitee_user_id_status_index` (`invitee_user_id`,`status`),
  KEY `calendar_event_invitations_agency_id_idx` (`agency_id`),
  CONSTRAINT `calendar_event_invitations_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE SET NULL,
  CONSTRAINT `calendar_event_invitations_event_id_foreign` FOREIGN KEY (`event_id`) REFERENCES `calendar_events` (`id`) ON DELETE CASCADE,
  CONSTRAINT `calendar_event_invitations_invitee_user_id_foreign` FOREIGN KEY (`invitee_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `calendar_event_invitations_inviter_user_id_foreign` FOREIGN KEY (`inviter_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `calendar_event_links`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `calendar_event_links` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `calendar_event_id` bigint unsigned NOT NULL,
  `agency_id` bigint unsigned DEFAULT NULL,
  `linkable_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `linkable_id` bigint unsigned NOT NULL,
  `role` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'attendee',
  `created_by_user_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `cel_event_linkable_role_unique` (`calendar_event_id`,`linkable_type`,`linkable_id`,`role`),
  KEY `calendar_event_links_created_by_user_id_foreign` (`created_by_user_id`),
  KEY `cel_linkable_idx` (`linkable_type`,`linkable_id`),
  KEY `calendar_event_links_agency_id_idx` (`agency_id`),
  CONSTRAINT `calendar_event_links_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE SET NULL,
  CONSTRAINT `calendar_event_links_calendar_event_id_foreign` FOREIGN KEY (`calendar_event_id`) REFERENCES `calendar_events` (`id`) ON DELETE CASCADE,
  CONSTRAINT `calendar_event_links_created_by_user_id_foreign` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `calendar_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `calendar_events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned DEFAULT NULL,
  `created_by_id` bigint unsigned DEFAULT NULL,
  `created_by_ai` tinyint(1) NOT NULL DEFAULT '0',
  `ai_source` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'ellie_voice, ellie_chat, future sources',
  `ai_transcript` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'Raw voice transcript or AI input for audit',
  `event_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'deal, lease, compliance, document, prospecting, portal, property, manual',
  `category` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Sub-type: bond_deadline, lease_expiry, ffc_expiry, viewing, etc.',
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `event_date` datetime NOT NULL,
  `end_date` datetime DEFAULT NULL,
  `all_day` tinyint(1) NOT NULL DEFAULT '1',
  `priority` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'normal' COMMENT 'low, normal, high, critical',
  `send_reminder` tinyint(1) NOT NULL DEFAULT '1',
  `status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending' COMMENT 'pending, completed, overdue, dismissed',
  `dismissal_reason_code` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `dismissal_reason_notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `completion_reason_code` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `completion_reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `resolution` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'completed, extended, did_not_happen',
  `resolution_note` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `colour` varchar(7) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Hex colour, auto-set from event_type if null',
  `source_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_id` bigint unsigned DEFAULT NULL,
  `property_id` bigint unsigned DEFAULT NULL,
  `contact_id` bigint unsigned DEFAULT NULL,
  `branch_id` bigint unsigned DEFAULT NULL,
  `agency_id` bigint unsigned DEFAULT NULL,
  `reminder_offsets` json DEFAULT NULL COMMENT 'Array of offsets in minutes',
  `reminder_channels` json DEFAULT NULL,
  `reminders_sent` json DEFAULT NULL COMMENT 'Tracks which offsets have been sent',
  `is_recurring` tinyint(1) NOT NULL DEFAULT '0',
  `recurrence_rule` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'RRULE format',
  `parent_event_id` bigint unsigned DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `calendar_events_created_by_id_foreign` (`created_by_id`),
  KEY `calendar_events_source_type_source_id_index` (`source_type`,`source_id`),
  KEY `calendar_events_contact_id_foreign` (`contact_id`),
  KEY `calendar_events_branch_id_foreign` (`branch_id`),
  KEY `calendar_events_agency_id_foreign` (`agency_id`),
  KEY `calendar_events_parent_event_id_foreign` (`parent_event_id`),
  KEY `calendar_events_user_id_event_date_index` (`user_id`,`event_date`),
  KEY `calendar_events_status_event_date_index` (`status`,`event_date`),
  KEY `calendar_events_property_id_event_date_index` (`property_id`,`event_date`),
  KEY `calendar_events_event_type_index` (`event_type`),
  KEY `calendar_events_category_index` (`category`),
  KEY `calendar_events_event_date_index` (`event_date`),
  KEY `calendar_events_status_index` (`status`),
  KEY `calendar_events_created_by_ai_index` (`created_by_ai`),
  CONSTRAINT `calendar_events_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE SET NULL,
  CONSTRAINT `calendar_events_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `calendar_events_contact_id_foreign` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `calendar_events_created_by_id_foreign` FOREIGN KEY (`created_by_id`) REFERENCES `users` (`id`),
  CONSTRAINT `calendar_events_parent_event_id_foreign` FOREIGN KEY (`parent_event_id`) REFERENCES `calendar_events` (`id`) ON DELETE SET NULL,
  CONSTRAINT `calendar_events_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE SET NULL,
  CONSTRAINT `calendar_events_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `calendar_reminders_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `calendar_reminders_log` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `calendar_event_id` bigint unsigned NOT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `channel` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'app, email, sms',
  `offset_minutes` int NOT NULL,
  `occurrence_key` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'single',
  `sent_at` datetime NOT NULL,
  `read_at` datetime DEFAULT NULL,
  `snoozed_until` datetime DEFAULT NULL,
  `actioned_at` datetime DEFAULT NULL,
  `escalated` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `cal_reminder_once_idx` (`calendar_event_id`,`user_id`,`channel`,`offset_minutes`,`occurrence_key`),
  KEY `calendar_reminders_log_user_id_foreign` (`user_id`),
  KEY `calendar_reminders_log_agency_id_idx` (`agency_id`),
  CONSTRAINT `calendar_reminders_log_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `calendar_reminders_log_calendar_event_id_foreign` FOREIGN KEY (`calendar_event_id`) REFERENCES `calendar_events` (`id`) ON DELETE CASCADE,
  CONSTRAINT `calendar_reminders_log_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `calendar_user_preferences`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `calendar_user_preferences` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `default_view` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'month',
  `working_hours_start` time NOT NULL DEFAULT '08:00:00',
  `working_hours_end` time NOT NULL DEFAULT '17:00:00',
  `weekend_visible` tinyint(1) NOT NULL DEFAULT '0',
  `ical_token` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email_reminders` tinyint(1) NOT NULL DEFAULT '1',
  `app_reminders` tinyint(1) NOT NULL DEFAULT '1',
  `digest_email` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'daily' COMMENT 'none, daily, weekly',
  `calendar_deck_layout` json DEFAULT NULL,
  `calendar_layers` json DEFAULT NULL,
  `calendar_cockpit` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `calendar_user_preferences_user_id_unique` (`user_id`),
  UNIQUE KEY `calendar_user_preferences_ical_token_unique` (`ical_token`),
  CONSTRAINT `calendar_user_preferences_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cds_drafts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cds_drafts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `agency_id` bigint unsigned DEFAULT NULL,
  `template_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `cds_json` json NOT NULL,
  `tags` json DEFAULT NULL,
  `mappings` json DEFAULT NULL,
  `tagged_html` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `settings` json DEFAULT NULL,
  `source_template_id` bigint unsigned DEFAULT NULL,
  `status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `cds_drafts_user_id_status_index` (`user_id`,`status`),
  CONSTRAINT `cds_drafts_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `client_access_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `client_access_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `client_user_id` bigint unsigned DEFAULT NULL,
  `agency_id` bigint unsigned DEFAULT NULL,
  `contact_id` bigint unsigned DEFAULT NULL,
  `event` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `meta` json DEFAULT NULL,
  `ip` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `device_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `client_access_logs_contact_id_foreign` (`contact_id`),
  KEY `client_access_logs_client_user_id_created_at_index` (`client_user_id`,`created_at`),
  KEY `client_access_logs_agency_id_created_at_index` (`agency_id`,`created_at`),
  KEY `client_access_logs_event_index` (`event`),
  CONSTRAINT `client_access_logs_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE SET NULL,
  CONSTRAINT `client_access_logs_client_user_id_foreign` FOREIGN KEY (`client_user_id`) REFERENCES `client_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `client_access_logs_contact_id_foreign` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `client_otps`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `client_otps` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `client_user_id` bigint unsigned DEFAULT NULL,
  `subject_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `subject_id` bigint unsigned DEFAULT NULL,
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `destination` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `purpose` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'activation',
  `channel` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'email',
  `code_hash` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `expires_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `used_at` timestamp NULL DEFAULT NULL,
  `attempts` tinyint unsigned NOT NULL DEFAULT '0',
  `ip` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `client_otps_client_user_id_foreign` (`client_user_id`),
  KEY `client_otps_email_used_at_index` (`email`,`used_at`),
  KEY `client_otps_email_index` (`email`),
  KEY `client_otps_subject_type_subject_id_index` (`subject_type`,`subject_id`),
  KEY `client_otps_destination_purpose_used_at_index` (`destination`,`purpose`,`used_at`),
  CONSTRAINT `client_otps_client_user_id_foreign` FOREIGN KEY (`client_user_id`) REFERENCES `client_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `client_signin_attempts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `client_signin_attempts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `identifier` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `matched` tinyint(1) NOT NULL DEFAULT '0',
  `agency_count` tinyint unsigned NOT NULL DEFAULT '0',
  `ip` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `client_signin_attempts_identifier_index` (`identifier`),
  KEY `client_signin_attempts_matched_created_at_index` (`matched`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `client_users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `client_users` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `password` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `password_must_change` tinyint(1) NOT NULL DEFAULT '0',
  `password_set_at` timestamp NULL DEFAULT NULL,
  `activated_at` timestamp NULL DEFAULT NULL,
  `first_login_at` timestamp NULL DEFAULT NULL,
  `last_login_at` timestamp NULL DEFAULT NULL,
  `preferred_agency_id` bigint unsigned DEFAULT NULL,
  `locked_to_agency_id` bigint unsigned DEFAULT NULL,
  `current_agency_id` bigint unsigned DEFAULT NULL,
  `created_by_agency_id` bigint unsigned DEFAULT NULL,
  `last_ip` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `active_email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci GENERATED ALWAYS AS (if((`deleted_at` is null),`email`,NULL)) VIRTUAL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `client_users_active_email_unique` (`active_email`),
  KEY `client_users_preferred_agency_id_foreign` (`preferred_agency_id`),
  KEY `client_users_locked_to_agency_id_foreign` (`locked_to_agency_id`),
  KEY `client_users_current_agency_id_foreign` (`current_agency_id`),
  KEY `client_users_created_by_agency_id_foreign` (`created_by_agency_id`),
  KEY `client_users_email_index` (`email`),
  CONSTRAINT `client_users_created_by_agency_id_foreign` FOREIGN KEY (`created_by_agency_id`) REFERENCES `agencies` (`id`) ON DELETE SET NULL,
  CONSTRAINT `client_users_current_agency_id_foreign` FOREIGN KEY (`current_agency_id`) REFERENCES `agencies` (`id`) ON DELETE SET NULL,
  CONSTRAINT `client_users_locked_to_agency_id_foreign` FOREIGN KEY (`locked_to_agency_id`) REFERENCES `agencies` (`id`) ON DELETE SET NULL,
  CONSTRAINT `client_users_preferred_agency_id_foreign` FOREIGN KEY (`preferred_agency_id`) REFERENCES `agencies` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `command_document_expectations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `command_document_expectations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `property_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'sale, rental, commercial, vacant_land',
  `document_type_id` bigint unsigned DEFAULT NULL,
  `required` tinyint(1) NOT NULL DEFAULT '1',
  `due_offset_hours` int unsigned NOT NULL DEFAULT '72',
  `label` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `sort_order` int NOT NULL DEFAULT '0',
  `agency_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `command_document_expectations_document_type_id_foreign` (`document_type_id`),
  KEY `command_document_expectations_agency_id_foreign` (`agency_id`),
  CONSTRAINT `command_document_expectations_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE SET NULL,
  CONSTRAINT `command_document_expectations_document_type_id_foreign` FOREIGN KEY (`document_type_id`) REFERENCES `document_types` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `command_task_notes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `command_task_notes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `command_task_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `body` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `agency_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `command_task_notes_user_id_foreign` (`user_id`),
  KEY `command_task_notes_command_task_id_created_at_index` (`command_task_id`,`created_at`),
  KEY `command_task_notes_agency_id_index` (`agency_id`),
  CONSTRAINT `command_task_notes_command_task_id_foreign` FOREIGN KEY (`command_task_id`) REFERENCES `command_tasks` (`id`) ON DELETE CASCADE,
  CONSTRAINT `command_task_notes_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `command_tasks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `command_tasks` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `task_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'document_upload, follow_up, compliance, review, deal_action, custom',
  `status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'todo' COMMENT 'todo, in_progress, awaiting, done, dismissed',
  `resolution` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'completed, extended, did_not_happen',
  `resolution_note` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `priority` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'normal' COMMENT 'low, normal, high, critical',
  `send_reminder` tinyint(1) NOT NULL DEFAULT '1',
  `assigned_to` bigint unsigned NOT NULL,
  `assigned_by` bigint unsigned DEFAULT NULL,
  `due_date` datetime DEFAULT NULL,
  `started_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `property_id` bigint unsigned DEFAULT NULL,
  `contact_id` bigint unsigned DEFAULT NULL,
  `deal_id` bigint unsigned DEFAULT NULL,
  `source_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'automation_rule, manual, calendar_event',
  `source_id` bigint unsigned DEFAULT NULL,
  `calendar_event_id` bigint unsigned DEFAULT NULL,
  `checklist` json DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `metadata` json DEFAULT NULL,
  `branch_id` bigint unsigned DEFAULT NULL,
  `agency_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `command_tasks_assigned_by_foreign` (`assigned_by`),
  KEY `command_tasks_property_id_foreign` (`property_id`),
  KEY `command_tasks_contact_id_foreign` (`contact_id`),
  KEY `command_tasks_calendar_event_id_foreign` (`calendar_event_id`),
  KEY `command_tasks_branch_id_foreign` (`branch_id`),
  KEY `command_tasks_agency_id_foreign` (`agency_id`),
  KEY `command_tasks_assigned_to_status_index` (`assigned_to`,`status`),
  KEY `command_tasks_assigned_to_due_date_index` (`assigned_to`,`due_date`),
  KEY `command_tasks_task_type_index` (`task_type`),
  KEY `command_tasks_status_index` (`status`),
  KEY `command_tasks_due_date_index` (`due_date`),
  KEY `command_tasks_deal_id_index` (`deal_id`),
  CONSTRAINT `command_tasks_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE SET NULL,
  CONSTRAINT `command_tasks_assigned_by_foreign` FOREIGN KEY (`assigned_by`) REFERENCES `users` (`id`),
  CONSTRAINT `command_tasks_assigned_to_foreign` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`),
  CONSTRAINT `command_tasks_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `command_tasks_calendar_event_id_foreign` FOREIGN KEY (`calendar_event_id`) REFERENCES `calendar_events` (`id`) ON DELETE SET NULL,
  CONSTRAINT `command_tasks_contact_id_foreign` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `command_tasks_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=37 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `commercial_evaluation_assets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `commercial_evaluation_assets` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `commercial_evaluation_id` bigint unsigned NOT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `category` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `quantity` int unsigned DEFAULT NULL,
  `estimated_value` bigint DEFAULT NULL,
  `notes` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ce_assets_eval_fk` (`commercial_evaluation_id`),
  KEY `commercial_evaluation_assets_agency_id_idx` (`agency_id`),
  CONSTRAINT `ce_assets_eval_fk` FOREIGN KEY (`commercial_evaluation_id`) REFERENCES `commercial_evaluations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `commercial_evaluation_assets_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `commercial_evaluation_comparables`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `commercial_evaluation_comparables` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `commercial_evaluation_id` bigint unsigned NOT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `address` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `suburb` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `property_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `size_m2` decimal(12,2) DEFAULT NULL,
  `size_ha` decimal(10,4) DEFAULT NULL,
  `sale_price` bigint DEFAULT NULL,
  `sale_date` date DEFAULT NULL,
  `price_per_m2` bigint DEFAULT NULL,
  `price_per_ha` bigint DEFAULT NULL,
  `notes` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ce_comparables_eval_fk` (`commercial_evaluation_id`),
  KEY `commercial_evaluation_comparables_agency_id_idx` (`agency_id`),
  CONSTRAINT `ce_comparables_eval_fk` FOREIGN KEY (`commercial_evaluation_id`) REFERENCES `commercial_evaluations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `commercial_evaluation_comparables_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `commercial_evaluation_crops`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `commercial_evaluation_crops` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `commercial_evaluation_id` bigint unsigned NOT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `crop_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `variety` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `hectares` decimal(10,2) NOT NULL,
  `year_planted` smallint unsigned DEFAULT NULL,
  `age_years` smallint unsigned DEFAULT NULL,
  `expected_lifespan_years` smallint unsigned DEFAULT NULL,
  `remaining_productive_years` smallint unsigned DEFAULT NULL,
  `trees_per_hectare` int unsigned DEFAULT NULL,
  `total_trees` int unsigned DEFAULT NULL,
  `current_yield_tons_per_ha` decimal(10,2) DEFAULT NULL,
  `expected_peak_yield_tons_per_ha` decimal(10,2) DEFAULT NULL,
  `yield_percentage` decimal(5,2) DEFAULT NULL,
  `current_price_per_ton` bigint DEFAULT NULL,
  `annual_revenue` bigint DEFAULT NULL,
  `annual_cost_per_ha` bigint DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `guidance_answers` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ce_crops_eval_fk` (`commercial_evaluation_id`),
  KEY `commercial_evaluation_crops_agency_id_idx` (`agency_id`),
  CONSTRAINT `ce_crops_eval_fk` FOREIGN KEY (`commercial_evaluation_id`) REFERENCES `commercial_evaluations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `commercial_evaluation_crops_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `commercial_evaluation_financials`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `commercial_evaluation_financials` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `commercial_evaluation_id` bigint unsigned NOT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `financial_year` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `period_months` smallint unsigned NOT NULL DEFAULT '12',
  `gross_revenue` bigint DEFAULT NULL,
  `rental_income` bigint DEFAULT NULL,
  `room_revenue` bigint DEFAULT NULL,
  `food_beverage_revenue` bigint DEFAULT NULL,
  `other_income` bigint DEFAULT NULL,
  `vacancy_rate` decimal(5,2) DEFAULT NULL,
  `rates_taxes` bigint DEFAULT NULL,
  `insurance` bigint DEFAULT NULL,
  `utilities` bigint DEFAULT NULL,
  `maintenance` bigint DEFAULT NULL,
  `management_fees` bigint DEFAULT NULL,
  `salaries_wages` bigint DEFAULT NULL,
  `security` bigint DEFAULT NULL,
  `marketing` bigint DEFAULT NULL,
  `food_beverage_cost` bigint DEFAULT NULL,
  `farm_operating_costs` bigint DEFAULT NULL,
  `other_expenses` bigint DEFAULT NULL,
  `total_expenses` bigint DEFAULT NULL,
  `net_operating_income` bigint DEFAULT NULL,
  `ebitda` bigint DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ce_financials_eval_fk` (`commercial_evaluation_id`),
  KEY `commercial_evaluation_financials_agency_id_idx` (`agency_id`),
  CONSTRAINT `ce_financials_eval_fk` FOREIGN KEY (`commercial_evaluation_id`) REFERENCES `commercial_evaluations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `commercial_evaluation_financials_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `commercial_evaluation_livestock`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `commercial_evaluation_livestock` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `commercial_evaluation_id` bigint unsigned NOT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `livestock_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `breed` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `head_count` int unsigned NOT NULL,
  `breeding_stock_count` int unsigned DEFAULT NULL,
  `value_per_head` bigint DEFAULT NULL,
  `total_value` bigint DEFAULT NULL,
  `carrying_capacity_ha_per_lsu` decimal(5,2) DEFAULT NULL,
  `hectares_used` decimal(10,2) DEFAULT NULL,
  `annual_revenue` bigint DEFAULT NULL,
  `annual_cost` bigint DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `guidance_answers` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ce_livestock_eval_fk` (`commercial_evaluation_id`),
  KEY `commercial_evaluation_livestock_agency_id_idx` (`agency_id`),
  CONSTRAINT `ce_livestock_eval_fk` FOREIGN KEY (`commercial_evaluation_id`) REFERENCES `commercial_evaluations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `commercial_evaluation_livestock_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `commercial_evaluation_units`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `commercial_evaluation_units` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `commercial_evaluation_id` bigint unsigned NOT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `unit_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `tenant_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `size_m2` decimal(12,2) DEFAULT NULL,
  `monthly_rental` bigint DEFAULT NULL,
  `lease_start` date DEFAULT NULL,
  `lease_end` date DEFAULT NULL,
  `is_vacant` tinyint(1) NOT NULL DEFAULT '0',
  `escalation_rate` decimal(5,2) DEFAULT NULL,
  `notes` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ce_units_eval_fk` (`commercial_evaluation_id`),
  KEY `commercial_evaluation_units_agency_id_idx` (`agency_id`),
  CONSTRAINT `ce_units_eval_fk` FOREIGN KEY (`commercial_evaluation_id`) REFERENCES `commercial_evaluations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `commercial_evaluation_units_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `commercial_evaluations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `commercial_evaluations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `created_by_user_id` bigint unsigned NOT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `branch_id` bigint unsigned DEFAULT NULL,
  `status` enum('draft','completed','archived') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `property_type` enum('commercial','industrial','hospitality','agricultural') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `property_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `address` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `suburb` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `town` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `province` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'KwaZulu-Natal',
  `erf_number` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `zoning` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `total_land_size_m2` decimal(12,2) DEFAULT NULL,
  `total_land_size_ha` decimal(10,4) DEFAULT NULL,
  `total_building_size_m2` decimal(12,2) DEFAULT NULL,
  `year_built` smallint unsigned DEFAULT NULL,
  `condition` enum('excellent','good','fair','poor') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `asking_price` bigint DEFAULT NULL,
  `municipal_evaluation` bigint DEFAULT NULL,
  `seller_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `evaluation_json` json DEFAULT NULL,
  `recommended_range_low` bigint DEFAULT NULL,
  `recommended_range_mid` bigint DEFAULT NULL,
  `recommended_range_high` bigint DEFAULT NULL,
  `primary_method` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `evaluated_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `commercial_evaluations_created_by_user_id_foreign` (`created_by_user_id`),
  KEY `commercial_evaluations_branch_id_foreign` (`branch_id`),
  KEY `commercial_evaluations_agency_id_idx` (`agency_id`),
  CONSTRAINT `commercial_evaluations_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `commercial_evaluations_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `commercial_evaluations_created_by_user_id_foreign` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `commission_ledger`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `commission_ledger` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `branch_id` bigint unsigned DEFAULT NULL,
  `cap_period_id` bigint unsigned NOT NULL,
  `deal_id` bigint unsigned DEFAULT NULL,
  `property_id` bigint unsigned DEFAULT NULL,
  `transaction_type` enum('sale','rental_letting','rental_management','referral','other') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `gross_commission` decimal(12,2) NOT NULL,
  `vat_amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `commission_excl_vat` decimal(12,2) NOT NULL,
  `agent_split_percent` int NOT NULL,
  `agent_amount` decimal(12,2) NOT NULL,
  `agency_amount` decimal(12,2) NOT NULL,
  `transaction_fee` decimal(10,2) NOT NULL DEFAULT '0.00',
  `risk_fee` decimal(10,2) NOT NULL DEFAULT '0.00',
  `mentor_fee` decimal(10,2) NOT NULL DEFAULT '0.00',
  `is_post_cap` tinyint(1) NOT NULL DEFAULT '0',
  `net_agent_amount` decimal(12,2) NOT NULL,
  `company_dollar` decimal(12,2) NOT NULL,
  `revenue_share_pool` decimal(12,2) NOT NULL DEFAULT '0.00',
  `status` enum('pending','confirmed','paid','cancelled') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `deal_date` date DEFAULT NULL,
  `paid_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `commission_ledger_user_id_status_index` (`user_id`,`status`),
  KEY `commission_ledger_agency_id_created_at_index` (`agency_id`,`created_at`),
  KEY `commission_ledger_cap_period_id_foreign` (`cap_period_id`),
  KEY `commission_ledger_branch_id_foreign` (`branch_id`),
  KEY `commission_ledger_agency_branch_idx` (`agency_id`,`branch_id`),
  CONSTRAINT `commission_ledger_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `commission_ledger_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `commission_ledger_cap_period_id_foreign` FOREIGN KEY (`cap_period_id`) REFERENCES `agent_cap_periods` (`id`) ON DELETE CASCADE,
  CONSTRAINT `commission_ledger_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `commission_setting_audit_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `commission_setting_audit_log` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `commission_setting_id` bigint unsigned DEFAULT NULL,
  `action` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `old_values` json DEFAULT NULL,
  `new_values` json DEFAULT NULL,
  `performed_by_user_id` bigint unsigned DEFAULT NULL,
  `performed_at` timestamp NOT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `commission_setting_audit_log_commission_setting_id_foreign` (`commission_setting_id`),
  KEY `commission_setting_audit_log_performed_by_user_id_foreign` (`performed_by_user_id`),
  KEY `csa_agency_time_idx` (`agency_id`,`performed_at`),
  CONSTRAINT `commission_setting_audit_log_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `commission_setting_audit_log_commission_setting_id_foreign` FOREIGN KEY (`commission_setting_id`) REFERENCES `commission_settings` (`id`) ON DELETE SET NULL,
  CONSTRAINT `commission_setting_audit_log_performed_by_user_id_foreign` FOREIGN KEY (`performed_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `commission_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `commission_settings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `commission_split_agent` int NOT NULL DEFAULT '80',
  `commission_split_agency` int NOT NULL DEFAULT '20',
  `annual_cap` decimal(12,2) NOT NULL DEFAULT '160000.00',
  `post_cap_transaction_fee` decimal(10,2) NOT NULL DEFAULT '2500.00',
  `post_cap_fee_cap` decimal(10,2) NOT NULL DEFAULT '50000.00',
  `post_cap_reduced_fee` decimal(10,2) NOT NULL DEFAULT '750.00',
  `monthly_platform_fee` decimal(10,2) NOT NULL DEFAULT '850.00',
  `mentor_program_enabled` tinyint(1) NOT NULL DEFAULT '1',
  `mentor_extra_split` int NOT NULL DEFAULT '20',
  `mentor_transactions` int NOT NULL DEFAULT '3',
  `risk_management_fee` decimal(10,2) NOT NULL DEFAULT '400.00',
  `risk_management_cap` decimal(10,2) NOT NULL DEFAULT '5000.00',
  `revenue_share_enabled` tinyint(1) NOT NULL DEFAULT '1',
  `revenue_share_pool_percent` int NOT NULL DEFAULT '50',
  `tier_1_percent` decimal(5,2) NOT NULL DEFAULT '3.50',
  `tier_2_percent` decimal(5,2) NOT NULL DEFAULT '4.00',
  `tier_3_percent` decimal(5,2) NOT NULL DEFAULT '2.50',
  `tier_4_percent` decimal(5,2) NOT NULL DEFAULT '1.50',
  `tier_5_percent` decimal(5,2) NOT NULL DEFAULT '1.00',
  `tier_6_percent` decimal(5,2) NOT NULL DEFAULT '0.50',
  `tier_7_percent` decimal(5,2) NOT NULL DEFAULT '0.25',
  `tier_4_flqa_requirement` int NOT NULL DEFAULT '5',
  `tier_5_flqa_requirement` int NOT NULL DEFAULT '10',
  `tier_6_flqa_requirement` int NOT NULL DEFAULT '15',
  `tier_7_flqa_requirement` int NOT NULL DEFAULT '20',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `commission_settings_agency_id_unique` (`agency_id`),
  CONSTRAINT `commission_settings_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `comms_access_audit_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `comms_access_audit_log` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `event_type` enum('request','grant','decline','session_expired','midnight_reset','ownership_transfer','revoke') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `actor_user_id` bigint unsigned DEFAULT NULL,
  `subject_user_id` bigint unsigned DEFAULT NULL,
  `contact_id` bigint unsigned DEFAULT NULL,
  `communication_id` bigint unsigned DEFAULT NULL,
  `detail` json DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `on_behalf_of_user_id` bigint unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `comms_audit_actor_fk` (`actor_user_id`),
  KEY `comms_audit_subject_fk` (`subject_user_id`),
  KEY `comms_audit_comm_fk` (`communication_id`),
  KEY `comms_audit_agency_created_idx` (`agency_id`,`created_at`),
  KEY `comms_audit_contact_idx` (`contact_id`),
  KEY `comms_audit_event_idx` (`event_type`),
  KEY `caal_obo_fk` (`on_behalf_of_user_id`),
  CONSTRAINT `caal_obo_fk` FOREIGN KEY (`on_behalf_of_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `comms_access_audit_log_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `comms_audit_actor_fk` FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `comms_audit_comm_fk` FOREIGN KEY (`communication_id`) REFERENCES `communications` (`id`) ON DELETE SET NULL,
  CONSTRAINT `comms_audit_contact_fk` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `comms_audit_subject_fk` FOREIGN KEY (`subject_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `comms_access_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `comms_access_requests` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `contact_id` bigint unsigned NOT NULL,
  `thread_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `communication_id` bigint unsigned DEFAULT NULL,
  `requester_user_id` bigint unsigned NOT NULL,
  `status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `grant_mode` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'session',
  `reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `denial_reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `authorized_by_user_id` bigint unsigned DEFAULT NULL,
  `authorized_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NOT NULL,
  `granted_session_expires_at` timestamp NULL DEFAULT NULL,
  `revoked_at` timestamp NULL DEFAULT NULL,
  `revoked_reason` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `session_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `car_contact_fk` (`contact_id`),
  KEY `car_authorizer_fk` (`authorized_by_user_id`),
  KEY `car_agency_contact_idx` (`agency_id`,`contact_id`),
  KEY `car_requester_status_idx` (`requester_user_id`,`status`),
  KEY `car_status_idx` (`status`),
  KEY `car_communication_fk` (`communication_id`),
  KEY `car_requester_thread_idx` (`requester_user_id`,`thread_key`),
  KEY `car_requester_comm_idx` (`requester_user_id`,`communication_id`),
  CONSTRAINT `car_authorizer_fk` FOREIGN KEY (`authorized_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `car_communication_fk` FOREIGN KEY (`communication_id`) REFERENCES `communications` (`id`) ON DELETE SET NULL,
  CONSTRAINT `car_contact_fk` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`),
  CONSTRAINT `car_requester_fk` FOREIGN KEY (`requester_user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `comms_access_requests_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `comms_thread_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `comms_thread_settings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `contact_id` bigint unsigned NOT NULL,
  `thread_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `hide_subject` tinyint(1) NOT NULL DEFAULT '0',
  `set_by_user_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `cts_agency_contact_thread_uq` (`agency_id`,`contact_id`,`thread_key`),
  KEY `cts_contact_fk` (`contact_id`),
  KEY `cts_set_by_fk` (`set_by_user_id`),
  KEY `cts_agency_thread_idx` (`agency_id`,`thread_key`),
  CONSTRAINT `comms_thread_settings_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `cts_contact_fk` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `cts_set_by_fk` FOREIGN KEY (`set_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `communication_attachments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `communication_attachments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `communication_id` bigint unsigned NOT NULL,
  `filename` varchar(512) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mime` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `size_bytes` bigint unsigned NOT NULL DEFAULT '0',
  `content_hash` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `storage_path` varchar(1024) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `media_status` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'stored',
  `retry_count` tinyint unsigned NOT NULL DEFAULT '0',
  `last_media_error` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `remote_ref` varchar(1024) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `duration_seconds` int unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `comm_att_agency_fk` (`agency_id`),
  KEY `comm_att_comm_fk` (`communication_id`),
  KEY `comm_att_hash_idx` (`content_hash`),
  KEY `comm_att_media_status_idx` (`media_status`),
  CONSTRAINT `comm_att_agency_fk` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `comm_att_comm_fk` FOREIGN KEY (`communication_id`) REFERENCES `communications` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `communication_dr2_dismissals`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `communication_dr2_dismissals` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `communication_id` bigint unsigned NOT NULL,
  `reason` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `reason_other` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `dismissed_by_user_id` bigint unsigned NOT NULL,
  `dismissed_at` timestamp NOT NULL,
  `restored_by_user_id` bigint unsigned DEFAULT NULL,
  `restored_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `dr2_dism_comm_uq` (`communication_id`),
  KEY `dr2_dism_by_fk` (`dismissed_by_user_id`),
  KEY `dr2_dism_restored_by_fk` (`restored_by_user_id`),
  KEY `dr2_dism_active_idx` (`agency_id`,`restored_at`),
  CONSTRAINT `dr2_dism_agency_fk` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `dr2_dism_by_fk` FOREIGN KEY (`dismissed_by_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `dr2_dism_comm_fk` FOREIGN KEY (`communication_id`) REFERENCES `communications` (`id`) ON DELETE CASCADE,
  CONSTRAINT `dr2_dism_restored_by_fk` FOREIGN KEY (`restored_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `communication_filing_suspense`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `communication_filing_suspense` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `communication_id` bigint unsigned NOT NULL,
  `channel` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'email',
  `suggested_deal_id` bigint unsigned DEFAULT NULL,
  `confidence` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'low',
  `status` varchar(12) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `resolved_deal_id` bigint unsigned DEFAULT NULL,
  `resolved_by_user_id` bigint unsigned DEFAULT NULL,
  `resolved_at` timestamp NULL DEFAULT NULL,
  `matched_signal_type` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `matched_signal_value` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `attorney_provider_id` bigint unsigned DEFAULT NULL,
  `attorney_provider_contact_id` bigint unsigned DEFAULT NULL,
  `note` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `cfs_agency_comm_uq` (`agency_id`,`communication_id`),
  KEY `cfs_agency_status_idx` (`agency_id`,`status`),
  KEY `cfs_agency_deal_idx` (`agency_id`,`suggested_deal_id`),
  KEY `communication_filing_suspense_agency_id_index` (`agency_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `communication_flag_alerts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `communication_flag_alerts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `identifier` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `original_flag_id` bigint unsigned NOT NULL,
  `contradicting_flag_id` bigint unsigned DEFAULT NULL,
  `alert_type` enum('agent_vs_agent','agent_vs_ai') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('open','reviewed','dismissed','actioned') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'open',
  `reviewed_by` bigint unsigned DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `cfa_orig_fk` (`original_flag_id`),
  KEY `cfa_contra_fk` (`contradicting_flag_id`),
  KEY `cfa_reviewedby_fk` (`reviewed_by`),
  KEY `cfa_agency_status_idx` (`agency_id`,`status`),
  KEY `cfa_identifier_idx` (`identifier`),
  CONSTRAINT `cfa_agency_fk` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `cfa_contra_fk` FOREIGN KEY (`contradicting_flag_id`) REFERENCES `communication_flags` (`id`) ON DELETE SET NULL,
  CONSTRAINT `cfa_orig_fk` FOREIGN KEY (`original_flag_id`) REFERENCES `communication_flags` (`id`) ON DELETE CASCADE,
  CONSTRAINT `cfa_reviewedby_fk` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `communication_flags`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `communication_flags` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `identifier` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `identifier_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_id` bigint unsigned NOT NULL,
  `flag` enum('not_real_estate','real_estate') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `ai_is_real_estate` tinyint(1) DEFAULT NULL,
  `ai_confidence` decimal(4,3) DEFAULT NULL,
  `message_external_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `flagged_at` timestamp NOT NULL,
  `contradicted_at` timestamp NULL DEFAULT NULL,
  `contradicted_by_user_id` bigint unsigned DEFAULT NULL,
  `review_status` enum('open','reviewed','actioned') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'open',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `cf_user_fk` (`user_id`),
  KEY `cf_contradby_fk` (`contradicted_by_user_id`),
  KEY `cf_agency_identifier_idx` (`agency_id`,`identifier`),
  KEY `cf_agency_user_flag_idx` (`agency_id`,`user_id`,`flag`),
  CONSTRAINT `cf_agency_fk` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `cf_contradby_fk` FOREIGN KEY (`contradicted_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `cf_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `communication_host_circuit_breakers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `communication_host_circuit_breakers` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `host` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `state` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'closed',
  `opened_at` timestamp NULL DEFAULT NULL,
  `last_probe_at` timestamp NULL DEFAULT NULL,
  `consecutive_probe_failures` int unsigned NOT NULL DEFAULT '0',
  `auth_failure_count` int unsigned NOT NULL DEFAULT '0',
  `auth_locked_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `communication_host_circuit_breakers_host_unique` (`host`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `communication_learned_refs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `communication_learned_refs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `deal_id` bigint unsigned NOT NULL,
  `attorney_provider_id` bigint unsigned DEFAULT NULL,
  `attorney_provider_contact_id` bigint unsigned DEFAULT NULL,
  `signal_type` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `signal_value` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_verified` tinyint(1) NOT NULL DEFAULT '0',
  `verified_by_user_id` bigint unsigned DEFAULT NULL,
  `verified_at` timestamp NULL DEFAULT NULL,
  `hits` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `clr_agency_signal_uq` (`agency_id`,`signal_type`,`signal_value`),
  KEY `clr_agency_verified_idx` (`agency_id`,`is_verified`),
  KEY `clr_agency_firm_idx` (`agency_id`,`attorney_provider_id`),
  KEY `communication_learned_refs_agency_id_index` (`agency_id`),
  KEY `communication_learned_refs_deal_id_index` (`deal_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `communication_links`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `communication_links` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `communication_id` bigint unsigned NOT NULL,
  `linkable_type` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `linkable_id` bigint unsigned NOT NULL,
  `source_attachment_id` bigint unsigned DEFAULT NULL,
  `link_method` enum('deterministic','attorney_ref','ellie_suggested','manual','attachment') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `confidence` decimal(5,2) DEFAULT NULL,
  `confirmed_by` bigint unsigned DEFAULT NULL,
  `confirmed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `comm_link_agency_fk` (`agency_id`),
  KEY `comm_link_comm_fk` (`communication_id`),
  KEY `comm_link_confby_fk` (`confirmed_by`),
  KEY `comm_link_morph_idx` (`linkable_type`,`linkable_id`),
  KEY `comm_link_src_att_fk` (`source_attachment_id`),
  CONSTRAINT `comm_link_agency_fk` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `comm_link_comm_fk` FOREIGN KEY (`communication_id`) REFERENCES `communications` (`id`) ON DELETE CASCADE,
  CONSTRAINT `comm_link_confby_fk` FOREIGN KEY (`confirmed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `comm_link_src_att_fk` FOREIGN KEY (`source_attachment_id`) REFERENCES `communication_attachments` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `communication_mailboxes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `communication_mailboxes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `outgoing_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `use_imap_credentials_for_smtp` tinyint(1) NOT NULL DEFAULT '1',
  `smtp_host` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `smtp_port` int unsigned NOT NULL DEFAULT '587',
  `smtp_encryption` enum('tls','ssl','none') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'tls',
  `smtp_username` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `smtp_encrypted_password` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `smtp_from_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `outgoing_active` tinyint(1) NOT NULL DEFAULT '1',
  `last_send_error` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_send_error_detail` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `last_send_error_at` timestamp NULL DEFAULT NULL,
  `consecutive_send_failures` int unsigned NOT NULL DEFAULT '0',
  `send_failure_notified_at` timestamp NULL DEFAULT NULL,
  `last_sent_at` timestamp NULL DEFAULT NULL,
  `last_sent_folder_append_error` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_sent_folder_append_error_detail` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `last_sent_folder_append_at` timestamp NULL DEFAULT NULL,
  `email_address` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `imap_host` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `imap_port` int unsigned NOT NULL DEFAULT '993',
  `username` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `encrypted_password` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `auth_type` enum('imap','oauth') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'imap',
  `set_by` enum('agency','user') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `poll_inbox` tinyint(1) NOT NULL DEFAULT '1',
  `poll_sent` tinyint(1) NOT NULL DEFAULT '1',
  `poll_interval_minutes` int unsigned NOT NULL DEFAULT '15',
  `last_polled_at` timestamp NULL DEFAULT NULL,
  `last_error` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_error_detail` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `last_error_at` timestamp NULL DEFAULT NULL,
  `consecutive_failures` int unsigned NOT NULL DEFAULT '0',
  `next_poll_earliest_at` timestamp NULL DEFAULT NULL,
  `poll_disabled_at` timestamp NULL DEFAULT NULL,
  `failure_notified_at` timestamp NULL DEFAULT NULL,
  `last_uid_seen` bigint unsigned DEFAULT NULL,
  `inbox_watermark_at` timestamp NULL DEFAULT NULL,
  `inbox_uid_validity` bigint unsigned DEFAULT NULL,
  `sent_watermark_at` timestamp NULL DEFAULT NULL,
  `sent_last_uid` bigint unsigned DEFAULT NULL,
  `sent_uid_validity` bigint unsigned DEFAULT NULL,
  `last_poll_duration_seconds` int unsigned DEFAULT NULL,
  `messages_behind_estimate` int unsigned DEFAULT NULL,
  `backfill_completed_at` timestamp NULL DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `comm_mbx_agency_active_idx` (`agency_id`,`active`),
  KEY `comm_mbx_user_fk` (`user_id`),
  KEY `comm_mbx_agency_user_idx` (`agency_id`,`user_id`),
  CONSTRAINT `comm_mbx_agency_fk` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `comm_mbx_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `communication_pending`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `communication_pending` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `channel` enum('email','whatsapp') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `direction` enum('inbound','outbound') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'inbound',
  `external_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `thread_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `from_identifier` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `participant_identifiers` json DEFAULT NULL,
  `occurred_at` datetime NOT NULL,
  `captured_at` datetime NOT NULL,
  `subject` varchar(1024) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `body_text` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `body_preview` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `raw_path` varchar(1024) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `has_attachments` tinyint(1) NOT NULL DEFAULT '0',
  `content_hash` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_ref` varchar(512) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `classification` enum('real_estate','personal','uncertain') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ai_is_real_estate` tinyint(1) DEFAULT NULL,
  `ai_confidence` decimal(4,3) DEFAULT NULL,
  `classified_at` timestamp NULL DEFAULT NULL,
  `classifier` enum('keyword','ai','manual') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `expires_at` datetime NOT NULL,
  `nudged_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `purged_at` timestamp NULL DEFAULT NULL,
  `purged_reason` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `comm_pend_agency_ext_uq` (`agency_id`,`external_id`),
  KEY `comm_pend_expires_idx` (`expires_at`),
  KEY `comm_pend_from_idx` (`from_identifier`),
  CONSTRAINT `comm_pend_agency_fk` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `communication_wa_devices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `communication_wa_devices` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `wa_number` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `waha_session` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `device_token` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_seen_at` timestamp NULL DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `comm_wa_token_uq` (`device_token`),
  UNIQUE KEY `comm_wa_session_uq` (`waha_session`),
  KEY `comm_wa_user_fk` (`user_id`),
  KEY `comm_wa_agency_active_idx` (`agency_id`,`active`),
  CONSTRAINT `comm_wa_agency_fk` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `comm_wa_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `communications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `communications` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `channel` enum('email','whatsapp') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `direction` enum('inbound','outbound') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `send_status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'sent',
  `send_status_set_by_user_id` bigint unsigned DEFAULT NULL,
  `send_status_set_at` datetime DEFAULT NULL,
  `resent_from_communication_id` bigint unsigned DEFAULT NULL,
  `external_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `thread_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `wa_chat_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `counterpart_lid` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `from_identifier` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `participant_identifiers` json DEFAULT NULL,
  `to_identifiers` json DEFAULT NULL,
  `cc_identifiers` json DEFAULT NULL,
  `occurred_at` datetime NOT NULL,
  `captured_at` datetime NOT NULL,
  `provisional_at` datetime DEFAULT NULL,
  `subject` varchar(1024) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `body_text` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `body_preview` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `body_display` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'AT-182 derived display body (email quote stripped); raw body_text untouched. Null → use body_text.',
  `body_status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `transcript_text` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `transcript_preview` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `transcript_status` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `transcript_retry_count` tinyint unsigned NOT NULL DEFAULT '0',
  `transcript_lang` varchar(8) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `transcript_model` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `transcript_error` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `transcript_at` timestamp NULL DEFAULT NULL,
  `raw_path` varchar(1024) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `has_attachments` tinyint(1) NOT NULL DEFAULT '0',
  `content_hash` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `text_hash` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_ref` varchar(512) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `owner_user_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `purged_at` timestamp NULL DEFAULT NULL,
  `purged_reason` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `comm_agency_ext_uq` (`agency_id`,`external_id`),
  KEY `comm_thread_idx` (`thread_key`),
  KEY `comm_occurred_idx` (`occurred_at`),
  KEY `comm_agency_channel_idx` (`agency_id`,`channel`),
  KEY `comm_hash_idx` (`content_hash`),
  KEY `comm_provisional_idx` (`agency_id`,`channel`,`provisional_at`),
  KEY `comm_texthash_idx` (`text_hash`),
  KEY `comm_owner_user_fk` (`owner_user_id`),
  KEY `comm_agency_owner_idx` (`agency_id`,`owner_user_id`),
  KEY `communications_counterpart_lid_index` (`counterpart_lid`),
  KEY `communications_wa_chat_id_index` (`wa_chat_id`),
  KEY `comm_body_status_idx` (`body_status`),
  KEY `comm_transcript_status_idx` (`transcript_status`),
  KEY `comm_send_status_actor_fk` (`send_status_set_by_user_id`),
  KEY `comms_send_status_idx` (`send_status`),
  KEY `comms_resent_from_fk` (`resent_from_communication_id`),
  CONSTRAINT `comm_agency_fk` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `comm_owner_user_fk` FOREIGN KEY (`owner_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `comm_send_status_actor_fk` FOREIGN KEY (`send_status_set_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `comms_resent_from_fk` FOREIGN KEY (`resent_from_communication_id`) REFERENCES `communications` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `communications_owner_backfill_246`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `communications_owner_backfill_246` (
  `communication_id` bigint unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`communication_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `company_expenses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `company_expenses` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `period` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `monthly_expenses` decimal(12,2) NOT NULL DEFAULT '0.00',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_company_expenses_agency_id` (`agency_id`),
  CONSTRAINT `company_expenses_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `compiled_template_field_bindings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `compiled_template_field_bindings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `compiled_template_id` bigint unsigned NOT NULL,
  `agency_id` bigint unsigned DEFAULT NULL,
  `block_id` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `field_id` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `field_label` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `dictionary_key` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'the bound Data Dictionary entry key',
  `dictionary_version` int unsigned NOT NULL DEFAULT '1',
  `source` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'agent_input' COMMENT 'auto|party_input|agent_input',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ctfb_template` (`compiled_template_id`),
  KEY `idx_ctfb_dict_key` (`dictionary_key`),
  KEY `idx_ctfb_agency_dict` (`agency_id`,`dictionary_key`),
  CONSTRAINT `compiled_template_field_bindings_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE SET NULL,
  CONSTRAINT `compiled_template_field_bindings_compiled_template_id_foreign` FOREIGN KEY (`compiled_template_id`) REFERENCES `compiled_templates` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `compiled_templates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `compiled_templates` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned DEFAULT NULL,
  `source_template_id` bigint unsigned DEFAULT NULL,
  `family` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'document family e.g. 116/117/119, otp_sale, mandate_sole',
  `version` int unsigned NOT NULL DEFAULT '1' COMMENT 'monotonic per (agency_id, family)',
  `content_hash` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'sha256 of the structural CDS; set at publish; the §5 pin',
  `data_dictionary_version` int unsigned NOT NULL DEFAULT '1' COMMENT 'pins the dictionary version bindings resolve against',
  `legal_class` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'general' COMMENT 'resolved from family; drives L7 e-sign legality',
  `delivery_modes` json NOT NULL COMMENT 'enabled modes: web_esign/pdf_wetink/download',
  `structure` json NOT NULL COMMENT 'the immutable CDS v2 tree — the SOLE runtime truth',
  `render_parity` json DEFAULT NULL COMMENT 'web/pdf parity hashes, written after L6',
  `lint_report` json DEFAULT NULL COMMENT 'auditable L1-L7 lint output attached to this version',
  `lint_status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending' COMMENT 'pending|passed|failed',
  `status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft' COMMENT 'draft|published|superseded',
  `published_at` timestamp NULL DEFAULT NULL,
  `published_by` bigint unsigned DEFAULT NULL,
  `compiled_by` bigint unsigned DEFAULT NULL,
  `superseded_by_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_compiled_templates_hash` (`content_hash`),
  KEY `compiled_templates_source_template_id_foreign` (`source_template_id`),
  KEY `compiled_templates_published_by_foreign` (`published_by`),
  KEY `compiled_templates_compiled_by_foreign` (`compiled_by`),
  KEY `compiled_templates_superseded_by_id_foreign` (`superseded_by_id`),
  KEY `idx_compiled_templates_afv` (`agency_id`,`family`,`version`),
  KEY `idx_compiled_templates_status` (`status`),
  KEY `idx_compiled_templates_family` (`family`),
  CONSTRAINT `compiled_templates_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE SET NULL,
  CONSTRAINT `compiled_templates_compiled_by_foreign` FOREIGN KEY (`compiled_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `compiled_templates_published_by_foreign` FOREIGN KEY (`published_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `compiled_templates_source_template_id_foreign` FOREIGN KEY (`source_template_id`) REFERENCES `docuperfect_templates` (`id`) ON DELETE SET NULL,
  CONSTRAINT `compiled_templates_superseded_by_id_foreign` FOREIGN KEY (`superseded_by_id`) REFERENCES `compiled_templates` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `condition_initials`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `condition_initials` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `initialable_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `initialable_id` bigint unsigned NOT NULL,
  `party_key` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `signature_request_id` bigint unsigned DEFAULT NULL,
  `amendment_id` bigint unsigned DEFAULT NULL,
  `initialed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `initial_image_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ip_address` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `cond_init_morph_idx` (`initialable_type`,`initialable_id`),
  KEY `cond_init_party_idx` (`party_key`,`initialed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `contact_access_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `contact_access_log` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `contact_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `impersonator_id` bigint unsigned DEFAULT NULL,
  `action_type` enum('view','edit','export','share','delete','merge') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `accessed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ip_address` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `request_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `on_behalf_of_user_id` bigint unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `contact_access_log_user_id_foreign` (`user_id`),
  KEY `contact_access_log_contact_id_accessed_at_index` (`contact_id`,`accessed_at`),
  KEY `contact_access_log_agency_id_accessed_at_index` (`agency_id`,`accessed_at`),
  KEY `cal_impersonator_fk` (`impersonator_id`),
  KEY `cal_obo_fk` (`on_behalf_of_user_id`),
  CONSTRAINT `cal_impersonator_fk` FOREIGN KEY (`impersonator_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `cal_obo_fk` FOREIGN KEY (`on_behalf_of_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `contact_access_log_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `contact_access_log_contact_id_foreign` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `contact_access_log_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `contact_audit_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `contact_audit_log` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `contact_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `actor_type` varchar(24) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `actor_label` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `agency_id` bigint unsigned DEFAULT NULL,
  `branch_id` bigint unsigned DEFAULT NULL,
  `event_category` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `event_type` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `old_values` json DEFAULT NULL,
  `new_values` json DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `human_summary` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `contact_audit_log_user_id_foreign` (`user_id`),
  KEY `contact_audit_log_branch_id_foreign` (`branch_id`),
  KEY `contact_audit_log_contact_id_created_at_index` (`contact_id`,`created_at`),
  KEY `contact_audit_log_contact_id_event_category_index` (`contact_id`,`event_category`),
  KEY `contact_audit_log_agency_id_created_at_index` (`agency_id`,`created_at`),
  CONSTRAINT `contact_audit_log_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`),
  CONSTRAINT `contact_audit_log_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  CONSTRAINT `contact_audit_log_contact_id_foreign` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `contact_audit_log_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=39 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `contact_consent_records`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `contact_consent_records` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `contact_id` bigint unsigned NOT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `consent_type` enum('fica_processing','marketing_communications','data_sharing','channel_email','channel_sms','channel_whatsapp','channel_call') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `decision` enum('given','declined') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'given',
  `given_at` timestamp NOT NULL,
  `given_by_user_id` bigint unsigned DEFAULT NULL,
  `method` enum('verbal','written','electronic','signed_document') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `evidence_document_id` bigint unsigned DEFAULT NULL,
  `revoked_at` timestamp NULL DEFAULT NULL,
  `revoked_by_user_id` bigint unsigned DEFAULT NULL,
  `revoked_reason` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `contact_consent_records_given_by_user_id_foreign` (`given_by_user_id`),
  KEY `contact_consent_records_evidence_document_id_foreign` (`evidence_document_id`),
  KEY `contact_consent_records_revoked_by_user_id_foreign` (`revoked_by_user_id`),
  KEY `contact_consent_records_contact_id_consent_type_index` (`contact_id`,`consent_type`),
  KEY `contact_consent_records_agency_id_consent_type_index` (`agency_id`,`consent_type`),
  CONSTRAINT `contact_consent_records_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `contact_consent_records_contact_id_foreign` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `contact_consent_records_evidence_document_id_foreign` FOREIGN KEY (`evidence_document_id`) REFERENCES `documents` (`id`) ON DELETE SET NULL,
  CONSTRAINT `contact_consent_records_given_by_user_id_foreign` FOREIGN KEY (`given_by_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `contact_consent_records_revoked_by_user_id_foreign` FOREIGN KEY (`revoked_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `contact_contact_type`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `contact_contact_type` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `contact_id` bigint unsigned NOT NULL,
  `contact_type_id` bigint unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `contact_contact_type_contact_id_contact_type_id_unique` (`contact_id`,`contact_type_id`),
  KEY `contact_contact_type_contact_type_id_foreign` (`contact_type_id`),
  CONSTRAINT `contact_contact_type_contact_id_foreign` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `contact_contact_type_contact_type_id_foreign` FOREIGN KEY (`contact_type_id`) REFERENCES `contact_types` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `contact_dead_end_flags`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `contact_dead_end_flags` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `contact_id` bigint unsigned NOT NULL,
  `property_id` bigint unsigned DEFAULT NULL,
  `reason` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'not_in_tva',
  `source` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `note` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_by_user_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `contact_dead_end_flags_contact_id_unique` (`contact_id`),
  KEY `contact_dead_end_flags_agency_id_contact_id_index` (`agency_id`,`contact_id`),
  KEY `contact_dead_end_flags_agency_id_index` (`agency_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `contact_documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `contact_documents` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `contact_id` bigint unsigned NOT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `uploaded_by_user_id` bigint unsigned DEFAULT NULL,
  `original_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `storage_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `mime_type` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `size` bigint unsigned NOT NULL DEFAULT '0',
  `document_type_id` bigint unsigned DEFAULT NULL,
  `property_id` bigint unsigned DEFAULT NULL,
  `source_type` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'upload',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `contact_documents_contact_id_foreign` (`contact_id`),
  KEY `contact_documents_uploaded_by_user_id_foreign` (`uploaded_by_user_id`),
  KEY `contact_documents_document_type_id_foreign` (`document_type_id`),
  KEY `contact_documents_property_id_foreign` (`property_id`),
  KEY `contact_documents_agency_id_idx` (`agency_id`),
  CONSTRAINT `contact_documents_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `contact_documents_contact_id_foreign` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `contact_documents_document_type_id_foreign` FOREIGN KEY (`document_type_id`) REFERENCES `document_types` (`id`) ON DELETE SET NULL,
  CONSTRAINT `contact_documents_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE SET NULL,
  CONSTRAINT `contact_documents_uploaded_by_user_id_foreign` FOREIGN KEY (`uploaded_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `contact_duplicate_clusters`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `contact_duplicate_clusters` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `contact_ids` json NOT NULL,
  `match_field` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `match_value` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('pending','reviewed','merged','dismissed') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `reviewed_by_user_id` bigint unsigned DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `contact_duplicate_clusters_reviewed_by_user_id_foreign` (`reviewed_by_user_id`),
  KEY `contact_duplicate_clusters_agency_id_status_index` (`agency_id`,`status`),
  CONSTRAINT `contact_duplicate_clusters_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `contact_duplicate_clusters_reviewed_by_user_id_foreign` FOREIGN KEY (`reviewed_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `contact_duplicate_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `contact_duplicate_log` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `attempted_by_user_id` bigint unsigned NOT NULL,
  `mode_at_attempt` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `match_field` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `match_value` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `existing_contact_id` bigint unsigned DEFAULT NULL,
  `attempted_data` json DEFAULT NULL,
  `action_taken` enum('auto_linked','used_existing','created_anyway','override_with_reason','request_pending','rejected') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `override_reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `contact_duplicate_log_agency_id_foreign` (`agency_id`),
  KEY `contact_duplicate_log_attempted_by_user_id_foreign` (`attempted_by_user_id`),
  KEY `contact_duplicate_log_existing_contact_id_foreign` (`existing_contact_id`),
  CONSTRAINT `contact_duplicate_log_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `contact_duplicate_log_attempted_by_user_id_foreign` FOREIGN KEY (`attempted_by_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `contact_duplicate_log_existing_contact_id_foreign` FOREIGN KEY (`existing_contact_id`) REFERENCES `contacts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `contact_emails`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `contact_emails` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `contact_id` bigint unsigned NOT NULL,
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `email_normalised` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `label` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `contact_identifier_label_id` bigint unsigned DEFAULT NULL,
  `is_primary` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `contact_emails_contact_norm_idx` (`contact_id`,`email_normalised`),
  KEY `contact_emails_agency_norm_idx` (`agency_id`,`email_normalised`),
  KEY `contact_emails_primary_idx` (`contact_id`,`is_primary`),
  KEY `contact_emails_contact_identifier_label_id_foreign` (`contact_identifier_label_id`),
  CONSTRAINT `contact_emails_agency_fk` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `contact_emails_contact_fk` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `contact_emails_contact_identifier_label_id_foreign` FOREIGN KEY (`contact_identifier_label_id`) REFERENCES `contact_identifier_labels` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `contact_identifier_labels`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `contact_identifier_labels` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `color` varchar(7) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '#6366f1',
  `sort_order` int NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `contact_identifier_labels_agency_id_is_active_index` (`agency_id`,`is_active`),
  CONSTRAINT `contact_identifier_labels_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=163 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `contact_match_feedback`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `contact_match_feedback` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `contact_match_id` bigint unsigned NOT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `property_id` bigint unsigned NOT NULL,
  `reaction` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `note` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `cmf_match_property_unique` (`contact_match_id`,`property_id`),
  KEY `cmf_property_reaction_idx` (`property_id`,`reaction`),
  KEY `contact_match_feedback_agency_id_idx` (`agency_id`),
  CONSTRAINT `contact_match_feedback_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `contact_match_feedback_contact_match_id_foreign` FOREIGN KEY (`contact_match_id`) REFERENCES `contact_matches` (`id`) ON DELETE CASCADE,
  CONSTRAINT `contact_match_feedback_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `contact_match_link_opens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `contact_match_link_opens` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `contact_match_id` bigint unsigned NOT NULL,
  `opened_at` timestamp NOT NULL,
  `created_at` timestamp NOT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `contact_match_link_opens_agency_id_foreign` (`agency_id`),
  KEY `contact_match_link_opens_contact_match_id_opened_at_index` (`contact_match_id`,`opened_at`),
  CONSTRAINT `contact_match_link_opens_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `contact_match_link_opens_contact_match_id_foreign` FOREIGN KEY (`contact_match_id`) REFERENCES `contact_matches` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `contact_match_notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `contact_match_notifications` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `contact_match_id` bigint unsigned NOT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `property_id` bigint unsigned NOT NULL,
  `score` tinyint unsigned NOT NULL DEFAULT '0',
  `notified_user_id` bigint unsigned DEFAULT NULL,
  `notification_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `emailed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `cmn_match_property_unique` (`contact_match_id`,`property_id`),
  KEY `cmn_property_idx` (`property_id`),
  KEY `contact_match_notifications_agency_id_idx` (`agency_id`),
  KEY `cmn_user_emailed_idx` (`notified_user_id`,`emailed_at`),
  CONSTRAINT `contact_match_notifications_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `contact_match_notifications_contact_match_id_foreign` FOREIGN KEY (`contact_match_id`) REFERENCES `contact_matches` (`id`) ON DELETE CASCADE,
  CONSTRAINT `contact_match_notifications_notified_user_id_foreign` FOREIGN KEY (`notified_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `contact_match_notifications_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `contact_match_reassignments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `contact_match_reassignments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `contact_match_id` bigint unsigned NOT NULL,
  `from_agent_id` bigint unsigned DEFAULT NULL,
  `to_agent_id` bigint unsigned NOT NULL,
  `moved_by_user_id` bigint unsigned NOT NULL,
  `reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `contact_match_reassignments_agency_id_foreign` (`agency_id`),
  KEY `contact_match_reassignments_from_agent_id_foreign` (`from_agent_id`),
  KEY `contact_match_reassignments_to_agent_id_foreign` (`to_agent_id`),
  KEY `contact_match_reassignments_moved_by_user_id_foreign` (`moved_by_user_id`),
  KEY `contact_match_reassignments_contact_match_id_created_at_index` (`contact_match_id`,`created_at`),
  CONSTRAINT `contact_match_reassignments_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `contact_match_reassignments_contact_match_id_foreign` FOREIGN KEY (`contact_match_id`) REFERENCES `contact_matches` (`id`) ON DELETE CASCADE,
  CONSTRAINT `contact_match_reassignments_from_agent_id_foreign` FOREIGN KEY (`from_agent_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `contact_match_reassignments_moved_by_user_id_foreign` FOREIGN KEY (`moved_by_user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `contact_match_reassignments_to_agent_id_foreign` FOREIGN KEY (`to_agent_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `contact_match_share_properties`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `contact_match_share_properties` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `contact_match_id` bigint unsigned NOT NULL,
  `contact_match_share_id` bigint unsigned NOT NULL,
  `property_id` bigint unsigned NOT NULL,
  `created_at` timestamp NOT NULL,
  PRIMARY KEY (`id`),
  KEY `contact_match_share_properties_agency_id_foreign` (`agency_id`),
  KEY `contact_match_share_properties_contact_match_share_id_foreign` (`contact_match_share_id`),
  KEY `contact_match_share_properties_property_id_foreign` (`property_id`),
  KEY `cmsp_match_property_idx` (`contact_match_id`,`property_id`),
  CONSTRAINT `contact_match_share_properties_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `contact_match_share_properties_contact_match_id_foreign` FOREIGN KEY (`contact_match_id`) REFERENCES `contact_matches` (`id`) ON DELETE CASCADE,
  CONSTRAINT `contact_match_share_properties_contact_match_share_id_foreign` FOREIGN KEY (`contact_match_share_id`) REFERENCES `contact_match_shares` (`id`) ON DELETE CASCADE,
  CONSTRAINT `contact_match_share_properties_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `contact_match_shares`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `contact_match_shares` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `token` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `contact_match_id` bigint unsigned NOT NULL,
  `shared_by_user_id` bigint unsigned NOT NULL,
  `channel` enum('copy_link','whatsapp','email','other') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `shared_at` timestamp NOT NULL,
  `confirmed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `contact_match_shares_token_unique` (`token`),
  KEY `contact_match_shares_agency_id_foreign` (`agency_id`),
  KEY `contact_match_shares_shared_by_user_id_foreign` (`shared_by_user_id`),
  KEY `contact_match_shares_contact_match_id_shared_at_index` (`contact_match_id`,`shared_at`),
  CONSTRAINT `contact_match_shares_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `contact_match_shares_contact_match_id_foreign` FOREIGN KEY (`contact_match_id`) REFERENCES `contact_matches` (`id`) ON DELETE CASCADE,
  CONSTRAINT `contact_match_shares_shared_by_user_id_foreign` FOREIGN KEY (`shared_by_user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `contact_matches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `contact_matches` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned DEFAULT NULL,
  `branch_id` bigint unsigned DEFAULT NULL,
  `share_token` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `share_slug` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `contact_id` bigint unsigned NOT NULL,
  `created_by_user_id` bigint unsigned DEFAULT NULL,
  `agent_id` bigint unsigned DEFAULT NULL,
  `updated_by_user_id` bigint unsigned DEFAULT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `listing_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'sale',
  `status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `set_aside_at` timestamp NULL DEFAULT NULL,
  `is_primary` tinyint(1) NOT NULL DEFAULT '0',
  `category` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `property_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `property_types` json DEFAULT NULL,
  `price_min` int unsigned DEFAULT NULL,
  `price_max` int unsigned DEFAULT NULL,
  `move_in_date` date DEFAULT NULL,
  `rental_term_months` smallint unsigned DEFAULT NULL,
  `beds_min` tinyint unsigned DEFAULT NULL,
  `bedrooms_max` tinyint unsigned DEFAULT NULL,
  `baths_min` tinyint unsigned DEFAULT NULL,
  `garages_min` tinyint unsigned DEFAULT NULL,
  `parking_min` tinyint unsigned DEFAULT NULL,
  `floor_size_min` int unsigned DEFAULT NULL,
  `floor_size_max` int unsigned DEFAULT NULL,
  `erf_size_min` int unsigned DEFAULT NULL,
  `erf_size_max` int unsigned DEFAULT NULL,
  `suburbs` json DEFAULT NULL,
  `p24_suburb_ids` json DEFAULT NULL,
  `must_have_features` json DEFAULT NULL,
  `nice_to_have_features` json DEFAULT NULL,
  `deal_breakers` json DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `hidden_property_ids` json DEFAULT NULL,
  `hidden_property_reasons` json DEFAULT NULL,
  `property_view_counts` json DEFAULT NULL,
  `last_engaged_at` timestamp NULL DEFAULT NULL,
  `auto_archive_at` date DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `contact_matches_share_token_unique` (`share_token`),
  UNIQUE KEY `cm_share_slug_unique` (`share_slug`),
  KEY `contact_matches_created_by_user_id_foreign` (`created_by_user_id`),
  KEY `cm_agency_status_idx` (`agency_id`,`status`),
  KEY `cm_contact_status_idx` (`contact_id`,`status`),
  KEY `cm_price_idx` (`price_min`,`price_max`),
  KEY `cm_listing_type_idx` (`listing_type`),
  KEY `contact_matches_updated_by_user_id_foreign` (`updated_by_user_id`),
  KEY `cm_contact_primary_idx` (`contact_id`,`is_primary`),
  KEY `contact_matches_branch_id_foreign` (`branch_id`),
  KEY `contact_matches_agent_id_foreign` (`agent_id`),
  CONSTRAINT `contact_matches_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE SET NULL,
  CONSTRAINT `contact_matches_agent_id_foreign` FOREIGN KEY (`agent_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `contact_matches_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `contact_matches_contact_id_foreign` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `contact_matches_created_by_user_id_foreign` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `contact_matches_updated_by_user_id_foreign` FOREIGN KEY (`updated_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `contact_notes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `contact_notes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `contact_id` bigint unsigned NOT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `type` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `body` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `contact_notes_contact_id_foreign` (`contact_id`),
  KEY `contact_notes_user_id_foreign` (`user_id`),
  KEY `contact_notes_agency_id_idx` (`agency_id`),
  KEY `contact_notes_agency_id_type_index` (`agency_id`,`type`),
  CONSTRAINT `contact_notes_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `contact_notes_contact_id_foreign` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `contact_notes_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `contact_outreach_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `contact_outreach_log` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `contact_id` bigint unsigned NOT NULL,
  `send_id` bigint unsigned DEFAULT NULL,
  `event_kind` enum('sent','clicked','opted_out') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `occurred_at` timestamp NOT NULL,
  `summary` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `actor_user_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `contact_outreach_log_contact_id_foreign` (`contact_id`),
  KEY `contact_outreach_log_send_id_foreign` (`send_id`),
  KEY `contact_outreach_log_actor_user_id_foreign` (`actor_user_id`),
  KEY `contact_outreach_log_contact_idx` (`agency_id`,`contact_id`,`occurred_at`),
  KEY `contact_outreach_log_kind_idx` (`agency_id`,`event_kind`),
  CONSTRAINT `contact_outreach_log_actor_user_id_foreign` FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `contact_outreach_log_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `contact_outreach_log_contact_id_foreign` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `contact_outreach_log_send_id_foreign` FOREIGN KEY (`send_id`) REFERENCES `seller_outreach_sends` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `contact_phones`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `contact_phones` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `contact_id` bigint unsigned NOT NULL,
  `phone` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `country_iso` char(2) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'ZA',
  `dial_code` varchar(8) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '+27',
  `phone_normalised` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `label` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `contact_identifier_label_id` bigint unsigned DEFAULT NULL,
  `is_primary` tinyint(1) NOT NULL DEFAULT '0',
  `is_whatsapp` tinyint(1) NOT NULL DEFAULT '0',
  `is_primary_whatsapp` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `contact_phones_contact_norm_idx` (`contact_id`,`phone_normalised`),
  KEY `contact_phones_agency_norm_idx` (`agency_id`,`phone_normalised`),
  KEY `contact_phones_primary_idx` (`contact_id`,`is_primary`),
  KEY `contact_phones_contact_identifier_label_id_foreign` (`contact_identifier_label_id`),
  CONSTRAINT `contact_phones_agency_fk` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `contact_phones_contact_fk` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `contact_phones_contact_identifier_label_id_foreign` FOREIGN KEY (`contact_identifier_label_id`) REFERENCES `contact_identifier_labels` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=39 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `contact_property`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `contact_property` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `contact_id` bigint unsigned NOT NULL,
  `property_id` bigint unsigned NOT NULL,
  `role` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_primary` tinyint(1) NOT NULL DEFAULT '0',
  `source` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `contact_property_contact_id_property_id_unique` (`contact_id`,`property_id`),
  KEY `contact_property_property_id_foreign` (`property_id`),
  CONSTRAINT `contact_property_contact_id_foreign` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `contact_property_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=21 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `contact_representatives`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `contact_representatives` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `entity_contact_id` bigint unsigned NOT NULL,
  `representative_contact_id` bigint unsigned NOT NULL,
  `is_primary` tinyint(1) NOT NULL DEFAULT '0',
  `capacity` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `signs_as_proxy` tinyint(1) NOT NULL DEFAULT '0',
  `asserted_by_user_id` bigint unsigned DEFAULT NULL,
  `sort_order` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `contact_representatives_unique` (`entity_contact_id`,`representative_contact_id`),
  KEY `contact_representatives_representative_contact_id_foreign` (`representative_contact_id`),
  KEY `contact_reps_entity_proxy_idx` (`entity_contact_id`,`signs_as_proxy`),
  KEY `contact_representatives_asserted_by_user_id_foreign` (`asserted_by_user_id`),
  CONSTRAINT `contact_representatives_asserted_by_user_id_foreign` FOREIGN KEY (`asserted_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `contact_representatives_entity_contact_id_foreign` FOREIGN KEY (`entity_contact_id`) REFERENCES `contacts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `contact_representatives_representative_contact_id_foreign` FOREIGN KEY (`representative_contact_id`) REFERENCES `contacts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `contact_sources`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `contact_sources` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `color` varchar(7) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '#6366f1',
  `sort_order` int NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `agency_id` bigint unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `contact_sources_agency_id_idx` (`agency_id`),
  CONSTRAINT `contact_sources_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `contact_tag`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `contact_tag` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `contact_id` bigint unsigned NOT NULL,
  `contact_tag_id` bigint unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `contact_tag_contact_id_contact_tag_id_unique` (`contact_id`,`contact_tag_id`),
  KEY `contact_tag_contact_tag_id_foreign` (`contact_tag_id`),
  CONSTRAINT `contact_tag_contact_id_foreign` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `contact_tag_contact_tag_id_foreign` FOREIGN KEY (`contact_tag_id`) REFERENCES `contact_tags` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `contact_tags`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `contact_tags` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `contact_type_id` bigint unsigned DEFAULT NULL,
  `name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `color` varchar(7) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '#6366f1',
  `sort_order` int NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `agency_id` bigint unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `contact_tags_agency_id_idx` (`agency_id`),
  KEY `contact_tags_contact_type_id_idx` (`contact_type_id`),
  CONSTRAINT `contact_tags_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `contact_tags_contact_type_id_foreign` FOREIGN KEY (`contact_type_id`) REFERENCES `contact_types` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `contact_testimonials`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `contact_testimonials` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `contact_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `agent_id` bigint unsigned DEFAULT NULL,
  `body` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `display_name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `rating` tinyint unsigned DEFAULT NULL,
  `published` tinyint(1) NOT NULL DEFAULT '0',
  `published_at` timestamp NULL DEFAULT NULL,
  `published_by_user_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `contact_testimonials_agency_id_published_index` (`agency_id`,`published`),
  KEY `contact_testimonials_contact_id_index` (`contact_id`),
  KEY `contact_testimonials_user_id_foreign` (`user_id`),
  KEY `contact_testimonials_published_by_user_id_foreign` (`published_by_user_id`),
  KEY `contact_testimonials_agency_id_agent_id_index` (`agency_id`,`agent_id`),
  KEY `contact_testimonials_agent_id_foreign` (`agent_id`),
  CONSTRAINT `contact_testimonials_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `contact_testimonials_agent_id_foreign` FOREIGN KEY (`agent_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `contact_testimonials_contact_id_foreign` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `contact_testimonials_published_by_user_id_foreign` FOREIGN KEY (`published_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `contact_testimonials_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `contact_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `contact_types` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `color` varchar(7) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '#6366f1',
  `sort_order` int NOT NULL DEFAULT '0',
  `esign_role` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `contact_types_esign_role_index` (`esign_role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `contacts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `contacts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `contact_type_id` bigint unsigned DEFAULT NULL,
  `contact_source_id` bigint unsigned DEFAULT NULL,
  `created_by_user_id` bigint unsigned DEFAULT NULL,
  `agent_id` bigint unsigned DEFAULT NULL,
  `second_agent_id` bigint unsigned DEFAULT NULL,
  `client_user_id` bigint unsigned DEFAULT NULL,
  `first_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `last_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `phone` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `birthday` date DEFAULT NULL,
  `birthday_reminder` tinyint(1) NOT NULL DEFAULT '0',
  `id_number` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `id_number_captured_at` timestamp NULL DEFAULT NULL,
  `id_number_source` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `passport_number` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `address` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `unit_number` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `floor_number` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `unit_section_block` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `complex_name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `street_number` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `street_name` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `suburb` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `city` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `province` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `p24_suburb_id` bigint unsigned DEFAULT NULL,
  `p24_city_id` bigint unsigned DEFAULT NULL,
  `p24_province_id` bigint unsigned DEFAULT NULL,
  `loaded_at` timestamp NULL DEFAULT NULL,
  `modified_at` timestamp NULL DEFAULT NULL,
  `last_contacted_at` timestamp NULL DEFAULT NULL,
  `contacted_marked_at` timestamp NULL DEFAULT NULL,
  `whatsapp_count` int unsigned NOT NULL DEFAULT '0',
  `email_count` int unsigned NOT NULL DEFAULT '0',
  `bank_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bank_account_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bank_account_number` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bank_branch_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bank_branch_code` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bank_account_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `preapproval_amount` decimal(14,2) DEFAULT NULL,
  `preapproval_expires_at` date DEFAULT NULL,
  `preapproval_institution` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `purged_at` timestamp NULL DEFAULT NULL,
  `purged_reason` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `opt_out_email` tinyint(1) NOT NULL DEFAULT '0',
  `opt_out_sms` tinyint(1) NOT NULL DEFAULT '0',
  `opt_out_whatsapp` tinyint(1) NOT NULL DEFAULT '0',
  `opt_out_call` tinyint(1) NOT NULL DEFAULT '0',
  `last_consent_check_at` timestamp NULL DEFAULT NULL,
  `is_buyer` tinyint(1) NOT NULL DEFAULT '0',
  `buyer_state` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_activity_at` timestamp NULL DEFAULT NULL,
  `buyer_pipeline_entered_at` timestamp NULL DEFAULT NULL,
  `buyer_pipeline_notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `buyer_source` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `rental_application_status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'none',
  `rental_application_status_updated_at` timestamp NULL DEFAULT NULL,
  `agency_id` bigint unsigned DEFAULT NULL,
  `branch_id` bigint unsigned NOT NULL,
  `messaging_opt_out_at` timestamp NULL DEFAULT NULL,
  `messaging_opt_out_reason` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `messaging_opt_out_recorded_by_user_id` bigint unsigned DEFAULT NULL,
  `messaging_opt_out_source` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `messaging_opt_out_kind` enum('declined','no_response') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `messaging_all_blocked` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'AT-50: true = all messages stopped; false = marketing-only opt-out (transactional still allowed).',
  `messaging_opted_in_at` timestamp NULL DEFAULT NULL,
  `outreach_permission_asked_at` timestamp NULL DEFAULT NULL,
  `messaging_opt_in_reason` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `messaging_opt_in_recorded_by_user_id` bigint unsigned DEFAULT NULL,
  `id_type` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `contact_kind` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'natural_person',
  `entity_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `entity_reg_no` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `entity_shape` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `buyer_matches_last_regenerated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `contacts_contact_type_id_foreign` (`contact_type_id`),
  KEY `contacts_created_by_user_id_foreign` (`created_by_user_id`),
  KEY `contacts_contact_source_id_foreign` (`contact_source_id`),
  KEY `contacts_agency_id_index` (`agency_id`),
  KEY `contacts_agency_branch_idx` (`agency_id`,`branch_id`),
  KEY `contacts_branch_id_foreign` (`branch_id`),
  KEY `contacts_buyer_pipeline_idx` (`agency_id`,`is_buyer`,`buyer_state`),
  KEY `contacts_client_user_agency_idx` (`client_user_id`,`agency_id`),
  KEY `contacts_msg_optout_recorded_by_fk` (`messaging_opt_out_recorded_by_user_id`),
  KEY `contacts_messaging_opt_out_at_idx` (`messaging_opt_out_at`),
  KEY `contacts_msg_optin_recorded_by_fk` (`messaging_opt_in_recorded_by_user_id`),
  KEY `contacts_messaging_opted_in_at_idx` (`messaging_opted_in_at`),
  KEY `contacts_agent_id_foreign` (`agent_id`),
  KEY `contacts_second_agent_id_foreign` (`second_agent_id`),
  KEY `contacts_p24_suburb_id_foreign` (`p24_suburb_id`),
  KEY `contacts_p24_city_id_foreign` (`p24_city_id`),
  KEY `contacts_p24_province_id_foreign` (`p24_province_id`),
  KEY `contacts_agency_outreach_pending_idx` (`agency_id`,`outreach_permission_asked_at`),
  KEY `contacts_buyer_source_idx` (`agency_id`,`buyer_source`),
  CONSTRAINT `contacts_agent_id_foreign` FOREIGN KEY (`agent_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `contacts_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `contacts_client_user_id_foreign` FOREIGN KEY (`client_user_id`) REFERENCES `client_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `contacts_contact_source_id_foreign` FOREIGN KEY (`contact_source_id`) REFERENCES `contact_sources` (`id`) ON DELETE SET NULL,
  CONSTRAINT `contacts_contact_type_id_foreign` FOREIGN KEY (`contact_type_id`) REFERENCES `contact_types` (`id`) ON DELETE SET NULL,
  CONSTRAINT `contacts_created_by_user_id_foreign` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `contacts_msg_optin_recorded_by_fk` FOREIGN KEY (`messaging_opt_in_recorded_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `contacts_msg_optout_recorded_by_fk` FOREIGN KEY (`messaging_opt_out_recorded_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `contacts_p24_city_id_foreign` FOREIGN KEY (`p24_city_id`) REFERENCES `p24_cities` (`id`) ON DELETE SET NULL,
  CONSTRAINT `contacts_p24_province_id_foreign` FOREIGN KEY (`p24_province_id`) REFERENCES `p24_provinces` (`id`) ON DELETE SET NULL,
  CONSTRAINT `contacts_p24_suburb_id_foreign` FOREIGN KEY (`p24_suburb_id`) REFERENCES `p24_suburbs` (`id`) ON DELETE SET NULL,
  CONSTRAINT `contacts_second_agent_id_foreign` FOREIGN KEY (`second_agent_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=39 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_0900_ai_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`lanetest`@`%`*/ /*!50003 TRIGGER `corex_contact_audit_after_insert` AFTER INSERT ON `contacts` FOR EACH ROW BEGIN
    IF (@corex_audit_handled IS NULL OR @corex_audit_handled = 0)
    THEN
        INSERT INTO contact_audit_log
            (contact_id, user_id, actor_type, actor_label, source,
             agency_id, branch_id, event_category, event_type,
             old_values, new_values, metadata, human_summary, created_at)
        VALUES
            (NEW.id, NULL, 'db-trigger', COALESCE(@corex_actor_label, 'unattributed'), 'db-trigger',
             NEW.agency_id, NEW.branch_id, 'system', 'contact_created',
             NULL,
             JSON_OBJECT('agent_id', NEW.agent_id, 'first_name', NEW.first_name, 'last_name', NEW.last_name,
                         'phone', NEW.phone, 'email', NEW.email),
             JSON_OBJECT('backstop', true, 'actor_user_id', @corex_actor_id),
             'Contact created (recorded by database backstop)',
             NOW());
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
/*!50003 SET collation_connection  = utf8mb4_0900_ai_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`lanetest`@`%`*/ /*!50003 TRIGGER `corex_contact_audit_after_update` AFTER UPDATE ON `contacts` FOR EACH ROW BEGIN
    IF (@corex_audit_handled IS NULL OR @corex_audit_handled = 0)
       AND (
            NOT (NEW.agent_id          <=> OLD.agent_id)
         OR NOT (NEW.second_agent_id   <=> OLD.second_agent_id)
         OR NOT (NEW.first_name        <=> OLD.first_name)
         OR NOT (NEW.last_name         <=> OLD.last_name)
         OR NOT (NEW.phone             <=> OLD.phone)
         OR NOT (NEW.email             <=> OLD.email)
         OR NOT (NEW.id_number         <=> OLD.id_number)
         OR NOT (NEW.address           <=> OLD.address)
         OR NOT (NEW.contact_type_id   <=> OLD.contact_type_id)
         OR NOT (NEW.contact_source_id <=> OLD.contact_source_id)
         OR NOT (NEW.is_buyer          <=> OLD.is_buyer)
         OR NOT (NEW.buyer_state       <=> OLD.buyer_state)
       )
    THEN
        INSERT INTO contact_audit_log
            (contact_id, user_id, actor_type, actor_label, source,
             agency_id, branch_id, event_category, event_type,
             old_values, new_values, metadata, human_summary, created_at)
        VALUES
            (NEW.id, NULL, 'db-trigger', COALESCE(@corex_actor_label, 'unattributed'), 'db-trigger',
             NEW.agency_id, NEW.branch_id, 'system', 'contact_updated',
             JSON_OBJECT('agent_id', OLD.agent_id, 'first_name', OLD.first_name, 'last_name', OLD.last_name,
                         'phone', OLD.phone, 'email', OLD.email, 'id_number', OLD.id_number),
             JSON_OBJECT('agent_id', NEW.agent_id, 'first_name', NEW.first_name, 'last_name', NEW.last_name,
                         'phone', NEW.phone, 'email', NEW.email, 'id_number', NEW.id_number),
             JSON_OBJECT('backstop', true, 'actor_user_id', @corex_actor_id),
             'Change recorded by database backstop (write bypassed the app audit layer)',
             NOW());
    END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `core_match_misses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `core_match_misses` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `contact_id` bigint unsigned NOT NULL,
  `property_id` bigint unsigned NOT NULL,
  `agent_id` bigint unsigned NOT NULL,
  `viewing_pack_id` bigint unsigned DEFAULT NULL,
  `buyer_criteria_snapshot` json DEFAULT NULL,
  `property_attributes_snapshot` json DEFAULT NULL,
  `captured_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `core_match_misses_contact_id_foreign` (`contact_id`),
  KEY `core_match_misses_property_id_foreign` (`property_id`),
  KEY `core_match_misses_agent_id_foreign` (`agent_id`),
  KEY `core_match_misses_agency_id_contact_id_index` (`agency_id`,`contact_id`),
  KEY `core_match_misses_viewing_pack_id_property_id_index` (`viewing_pack_id`,`property_id`),
  CONSTRAINT `core_match_misses_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `core_match_misses_agent_id_foreign` FOREIGN KEY (`agent_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `core_match_misses_contact_id_foreign` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `core_match_misses_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE,
  CONSTRAINT `core_match_misses_viewing_pack_id_foreign` FOREIGN KEY (`viewing_pack_id`) REFERENCES `viewing_packs` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `daily_activities`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `daily_activities` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `activity_date` date NOT NULL,
  `period` varchar(7) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `branch_id` bigint unsigned DEFAULT NULL,
  `calls_made` int NOT NULL DEFAULT '0',
  `doors_knocked` int NOT NULL DEFAULT '0',
  `whatsapps_sent` int NOT NULL DEFAULT '0',
  `referrals_asked` int NOT NULL DEFAULT '0',
  `flyers_dropped` int NOT NULL DEFAULT '0',
  `presentations_booked` int NOT NULL DEFAULT '0',
  `presentations_done` int NOT NULL DEFAULT '0',
  `oats_signed` int NOT NULL DEFAULT '0',
  `eats_signed` int NOT NULL DEFAULT '0',
  `buyer_leads` int NOT NULL DEFAULT '0',
  `seller_leads` int NOT NULL DEFAULT '0',
  `portal_leads` int NOT NULL DEFAULT '0',
  `referral_leads` int NOT NULL DEFAULT '0',
  `buyer_appointments` int NOT NULL DEFAULT '0',
  `otps_written` int NOT NULL DEFAULT '0',
  `otps_accepted` int NOT NULL DEFAULT '0',
  `otps_collapsed` int NOT NULL DEFAULT '0',
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `prospecting` int unsigned NOT NULL DEFAULT '0',
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `daily_activities_activity_date_user_id_unique` (`activity_date`,`user_id`),
  KEY `daily_activities_user_id_foreign` (`user_id`),
  KEY `daily_activities_branch_id_foreign` (`branch_id`),
  KEY `daily_activities_created_by_foreign` (`created_by`),
  KEY `daily_activities_updated_by_foreign` (`updated_by`),
  KEY `daily_activities_period_index` (`period`),
  KEY `daily_activities_agency_id_idx` (`agency_id`),
  CONSTRAINT `daily_activities_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `daily_activities_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `daily_activities_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `daily_activities_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `daily_activities_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `daily_activity_entries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `daily_activity_entries` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `activity_date` date NOT NULL,
  `period` varchar(7) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `branch_id` bigint unsigned DEFAULT NULL,
  `activity_definition_id` bigint unsigned NOT NULL,
  `value` int NOT NULL DEFAULT '0',
  `point_state` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'confirmed',
  `source` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'manual',
  `calendar_event_id` bigint unsigned DEFAULT NULL,
  `subject_type` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `subject_id` bigint unsigned DEFAULT NULL,
  `confirmed_at` timestamp NULL DEFAULT NULL,
  `revoked_at` timestamp NULL DEFAULT NULL,
  `revoke_reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `overridden_by_user_id` bigint unsigned DEFAULT NULL,
  `override_reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `override_audit_json` json DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `on_behalf_of_user_id` bigint unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `dae_def_user_date_event_unique` (`activity_definition_id`,`user_id`,`activity_date`,`calendar_event_id`),
  KEY `daily_activity_entries_user_id_foreign` (`user_id`),
  KEY `daily_activity_entries_branch_id_foreign` (`branch_id`),
  KEY `daily_activity_entries_created_by_foreign` (`created_by`),
  KEY `daily_activity_entries_updated_by_foreign` (`updated_by`),
  KEY `daily_activity_entries_period_user_id_index` (`period`,`user_id`),
  KEY `daily_activity_entries_activity_date_branch_id_index` (`activity_date`,`branch_id`),
  KEY `daily_activity_entries_agency_id_idx` (`agency_id`),
  KEY `daily_activity_entries_overridden_by_user_id_foreign` (`overridden_by_user_id`),
  KEY `dae_state_date_idx` (`point_state`,`activity_date`),
  KEY `dae_source_idx` (`source`),
  KEY `dae_calendar_event_idx` (`calendar_event_id`),
  KEY `dae_subject_idx` (`subject_type`,`subject_id`),
  KEY `dae_obo_fk` (`on_behalf_of_user_id`),
  CONSTRAINT `dae_obo_fk` FOREIGN KEY (`on_behalf_of_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `daily_activity_entries_activity_definition_id_foreign` FOREIGN KEY (`activity_definition_id`) REFERENCES `activity_definitions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `daily_activity_entries_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `daily_activity_entries_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `daily_activity_entries_calendar_event_id_foreign` FOREIGN KEY (`calendar_event_id`) REFERENCES `calendar_events` (`id`) ON DELETE SET NULL,
  CONSTRAINT `daily_activity_entries_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `daily_activity_entries_overridden_by_user_id_foreign` FOREIGN KEY (`overridden_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `daily_activity_entries_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `daily_activity_entries_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `data_dictionary_entries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `data_dictionary_entries` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned DEFAULT NULL,
  `key` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'stable entry key e.g. purchase_price, seller_id_number',
  `version` int unsigned NOT NULL DEFAULT '1',
  `category` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'money|identity|property|practitioner|date|party',
  `label` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `data_type` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'drives validation: zar_money|sa_id|ppra_no|ffc_no|date|erf_number|title_deed|scheme_name|unit_no|gps|full_name|marital_status|text',
  `validation` json DEFAULT NULL COMMENT 'validator params; may tighten never loosen (L5)',
  `format` json DEFAULT NULL COMMENT 'display formatting hints',
  `default_source` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'agent_input' COMMENT 'auto|party_input|agent_input',
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `superseded_by_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_dd_entries_akv` (`agency_id`,`key`,`version`),
  KEY `data_dictionary_entries_superseded_by_id_foreign` (`superseded_by_id`),
  KEY `idx_dd_entries_key` (`key`),
  KEY `idx_dd_entries_category` (`category`),
  CONSTRAINT `data_dictionary_entries_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE SET NULL,
  CONSTRAINT `data_dictionary_entries_superseded_by_id_foreign` FOREIGN KEY (`superseded_by_id`) REFERENCES `data_dictionary_entries` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `deal_activity_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `deal_activity_log` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `deal_id` bigint unsigned DEFAULT NULL,
  `dr1_deal_id` bigint unsigned DEFAULT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `deal_step_instance_id` bigint unsigned DEFAULT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `action` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `branch_id` bigint unsigned DEFAULT NULL,
  `on_behalf_of_user_id` bigint unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `deal_activity_log_user_id_foreign` (`user_id`),
  KEY `deal_activity_log_deal_step_instance_id_foreign` (`deal_step_instance_id`),
  KEY `deal_activity_log_deal_id_created_at_index` (`deal_id`,`created_at`),
  KEY `deal_activity_log_agency_id_idx` (`agency_id`),
  KEY `deal_activity_log_dr1_deal_id_foreign` (`dr1_deal_id`),
  KEY `deal_activity_log_branch_id_foreign` (`branch_id`),
  KEY `dal_obo_fk` (`on_behalf_of_user_id`),
  CONSTRAINT `dal_obo_fk` FOREIGN KEY (`on_behalf_of_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `deal_activity_log_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `deal_activity_log_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `deal_activity_log_deal_id_foreign` FOREIGN KEY (`deal_id`) REFERENCES `deals_v2` (`id`) ON DELETE CASCADE,
  CONSTRAINT `deal_activity_log_deal_step_instance_id_foreign` FOREIGN KEY (`deal_step_instance_id`) REFERENCES `deal_step_instances` (`id`) ON DELETE SET NULL,
  CONSTRAINT `deal_activity_log_dr1_deal_id_foreign` FOREIGN KEY (`dr1_deal_id`) REFERENCES `deals` (`id`) ON DELETE CASCADE,
  CONSTRAINT `deal_activity_log_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `deal_branches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `deal_branches` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `deal_id` bigint unsigned NOT NULL,
  `branch_id` bigint unsigned NOT NULL,
  `role` enum('originator','co_branch') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'co_branch',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `deal_branches_deal_id_branch_id_unique` (`deal_id`,`branch_id`),
  KEY `deal_branches_branch_id_index` (`branch_id`),
  CONSTRAINT `deal_branches_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE,
  CONSTRAINT `deal_branches_deal_id_foreign` FOREIGN KEY (`deal_id`) REFERENCES `deals` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `deal_conditions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `deal_conditions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `deal_id` bigint unsigned NOT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `key` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('active','met','failed','waived') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `options` json DEFAULT NULL,
  `waived_reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `addendum_ref` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `dc_deal_agency_idx` (`deal_id`,`agency_id`),
  KEY `dc_key_idx` (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `deal_contacts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `deal_contacts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `deal_id` bigint unsigned NOT NULL,
  `contact_id` bigint unsigned NOT NULL,
  `role` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `representative_email_mode` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `deal_contacts_unique` (`deal_id`,`contact_id`,`role`),
  KEY `deal_contacts_deal_id_role_index` (`deal_id`,`role`),
  KEY `deal_contacts_contact_id_foreign` (`contact_id`),
  CONSTRAINT `deal_contacts_contact_id_foreign` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `deal_contacts_deal_id_foreign` FOREIGN KEY (`deal_id`) REFERENCES `deals` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `deal_document_access_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `deal_document_access_log` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `distribution_id` bigint unsigned NOT NULL,
  `event` enum('link_clicked','otp_sent','otp_verified','otp_failed','downloaded','revoked') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `ip` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `meta` json DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `deal_document_access_log_distribution_id_event_index` (`distribution_id`,`event`),
  KEY `deal_document_access_log_agency_id_index` (`agency_id`),
  CONSTRAINT `deal_document_access_log_distribution_id_foreign` FOREIGN KEY (`distribution_id`) REFERENCES `deal_document_distributions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `deal_document_distributions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `deal_document_distributions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `deal_id` bigint unsigned NOT NULL,
  `document_id` bigint unsigned DEFAULT NULL,
  `party_role` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `recipient_contact_id` bigint unsigned DEFAULT NULL,
  `recipient_provider_id` bigint unsigned DEFAULT NULL,
  `recipient_email` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `delivery_mode` enum('secure_link','direct_attachment') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `channel` enum('email','whatsapp') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'email',
  `group_key` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `part_no` smallint unsigned NOT NULL DEFAULT '1',
  `part_of` smallint unsigned NOT NULL DEFAULT '1',
  `secure_token` char(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `otp_required` tinyint(1) NOT NULL DEFAULT '1',
  `status` enum('queued','sent','delivered_failed','opened','downloaded','revoked') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'queued',
  `communication_id` bigint unsigned DEFAULT NULL,
  `sent_by_id` bigint unsigned DEFAULT NULL,
  `sent_at` datetime DEFAULT NULL,
  `first_opened_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `branch_id` bigint unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `deal_document_distributions_secure_token_unique` (`secure_token`),
  KEY `deal_document_distributions_deal_id_foreign` (`deal_id`),
  KEY `deal_document_distributions_document_id_foreign` (`document_id`),
  KEY `deal_document_distributions_recipient_contact_id_foreign` (`recipient_contact_id`),
  KEY `deal_document_distributions_recipient_provider_id_foreign` (`recipient_provider_id`),
  KEY `deal_document_distributions_communication_id_foreign` (`communication_id`),
  KEY `deal_document_distributions_agency_id_deal_id_index` (`agency_id`,`deal_id`),
  KEY `deal_document_distributions_agency_id_index` (`agency_id`),
  KEY `ddd_group_idx` (`agency_id`,`group_key`),
  KEY `deal_document_distributions_branch_id_foreign` (`branch_id`),
  CONSTRAINT `deal_document_distributions_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `deal_document_distributions_communication_id_foreign` FOREIGN KEY (`communication_id`) REFERENCES `communications` (`id`) ON DELETE SET NULL,
  CONSTRAINT `deal_document_distributions_deal_id_foreign` FOREIGN KEY (`deal_id`) REFERENCES `deals_v2` (`id`) ON DELETE CASCADE,
  CONSTRAINT `deal_document_distributions_document_id_foreign` FOREIGN KEY (`document_id`) REFERENCES `documents` (`id`) ON DELETE SET NULL,
  CONSTRAINT `deal_document_distributions_recipient_contact_id_foreign` FOREIGN KEY (`recipient_contact_id`) REFERENCES `contacts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `deal_document_distributions_recipient_provider_id_foreign` FOREIGN KEY (`recipient_provider_id`) REFERENCES `agency_service_providers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `deal_link_review_queue`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `deal_link_review_queue` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `deal_id` bigint unsigned NOT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `matched_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `match_status` enum('pending','resolved_linked','resolved_unlinked','resolved_skip') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `candidates_json` json NOT NULL,
  `chosen_property_id` bigint unsigned DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `reviewed_by_user_id` bigint unsigned DEFAULT NULL,
  `review_note` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `dlrq_chosen_property_fk` (`chosen_property_id`),
  KEY `dlrq_reviewer_fk` (`reviewed_by_user_id`),
  KEY `dlrq_agency_status_idx` (`agency_id`,`match_status`),
  KEY `dlrq_deal_status_idx` (`deal_id`,`match_status`),
  CONSTRAINT `dlrq_agency_fk` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `dlrq_chosen_property_fk` FOREIGN KEY (`chosen_property_id`) REFERENCES `properties` (`id`) ON DELETE SET NULL,
  CONSTRAINT `dlrq_deal_fk` FOREIGN KEY (`deal_id`) REFERENCES `deals` (`id`) ON DELETE CASCADE,
  CONSTRAINT `dlrq_reviewer_fk` FOREIGN KEY (`reviewed_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `deal_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `deal_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `deal_id` bigint unsigned NOT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `actor_user_id` bigint unsigned DEFAULT NULL,
  `event_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `from_value` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `to_value` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `on_behalf_of_user_id` bigint unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `deal_logs_deal_id_created_at_index` (`deal_id`,`created_at`),
  KEY `deal_logs_actor_user_id_created_at_index` (`actor_user_id`,`created_at`),
  KEY `deal_logs_event_type_created_at_index` (`event_type`,`created_at`),
  KEY `deal_logs_agency_id_idx` (`agency_id`),
  KEY `dl_obo_fk` (`on_behalf_of_user_id`),
  CONSTRAINT `deal_logs_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `dl_obo_fk` FOREIGN KEY (`on_behalf_of_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `deal_money_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `deal_money_lines` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `deal_id` bigint unsigned NOT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `period` varchar(7) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `branch_id` bigint unsigned DEFAULT NULL,
  `side` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `side_pool_ex_vat` decimal(14,2) NOT NULL DEFAULT '0.00',
  `allocation_percent` decimal(6,2) NOT NULL DEFAULT '0.00',
  `pool_share_ex_vat` decimal(14,2) NOT NULL DEFAULT '0.00',
  `agent_cut_percent` decimal(6,2) NOT NULL DEFAULT '0.00',
  `agent_income_ex_vat` decimal(14,2) NOT NULL DEFAULT '0.00',
  `company_retained_ex_vat` decimal(14,2) NOT NULL DEFAULT '0.00',
  `paye_method` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `paye_value` decimal(14,2) NOT NULL DEFAULT '0.00',
  `paye_amount` decimal(14,2) NOT NULL DEFAULT '0.00',
  `deductions` decimal(14,2) NOT NULL DEFAULT '0.00',
  `deductions_description` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `agent_net_ex_vat` decimal(14,2) NOT NULL DEFAULT '0.00',
  `paid_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `agent_gross_ex_vat` decimal(14,2) NOT NULL DEFAULT '0.00',
  `company_gross_ex_vat` decimal(14,2) NOT NULL DEFAULT '0.00',
  `source` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `deal_money_lines_deal_id_side_index` (`deal_id`,`side`),
  KEY `deal_money_lines_user_id_period_index` (`user_id`,`period`),
  KEY `deal_money_lines_period_index` (`period`),
  KEY `deal_money_lines_branch_id_index` (`branch_id`),
  KEY `deal_money_lines_agency_id_idx` (`agency_id`),
  CONSTRAINT `deal_money_lines_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `deal_pipeline_condition_steps`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `deal_pipeline_condition_steps` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `condition_id` bigint unsigned NOT NULL,
  `step_key` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `follows_key` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `deps_keys` json DEFAULT NULL,
  `days_offset` int NOT NULL DEFAULT '0',
  `is_milestone` tinyint(1) NOT NULL DEFAULT '0',
  `is_suspensive` tinyint(1) NOT NULL DEFAULT '0',
  `is_anchor` tinyint(1) NOT NULL DEFAULT '0',
  `completion_type` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status_trigger` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `manual_due_option` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `requires_option` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `requires_funds_mode` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `expand` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pipeline_step_id` bigint unsigned DEFAULT NULL,
  `agency_id` bigint unsigned DEFAULT NULL,
  `position` int NOT NULL DEFAULT '0',
  `display_priority` int DEFAULT NULL,
  `is_grant_marker` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `dpcs_condition_idx` (`condition_id`),
  KEY `dpcs_step_idx` (`pipeline_step_id`),
  KEY `dpcs_step_key_idx` (`step_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `deal_pipeline_conditions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `deal_pipeline_conditions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `pipeline_template_id` bigint unsigned NOT NULL,
  `agency_id` bigint unsigned DEFAULT NULL,
  `key` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `label` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT '0',
  `options_schema` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `dpc_agency_template_idx` (`agency_id`,`pipeline_template_id`),
  KEY `dpc_template_idx` (`pipeline_template_id`),
  KEY `dpc_key_idx` (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `deal_pipeline_step_dependencies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `deal_pipeline_step_dependencies` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `pipeline_step_id` bigint unsigned NOT NULL,
  `depends_on_step_id` bigint unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `dpsd_step_dep_unique` (`pipeline_step_id`,`depends_on_step_id`),
  KEY `dpsd_dep_fk` (`depends_on_step_id`),
  KEY `dpsd_agency_idx` (`agency_id`),
  CONSTRAINT `dpsd_agency_fk` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `dpsd_dep_fk` FOREIGN KEY (`depends_on_step_id`) REFERENCES `deal_pipeline_steps` (`id`) ON DELETE CASCADE,
  CONSTRAINT `dpsd_step_fk` FOREIGN KEY (`pipeline_step_id`) REFERENCES `deal_pipeline_steps` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `deal_pipeline_step_work_orders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `deal_pipeline_step_work_orders` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `pipeline_step_id` bigint unsigned NOT NULL,
  `agency_id` bigint unsigned DEFAULT NULL,
  `service_type` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `trigger_point` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'activated',
  `sort_order` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `deal_pipeline_step_work_orders_pipeline_step_id_index` (`pipeline_step_id`),
  KEY `deal_pipeline_step_work_orders_agency_id_index` (`agency_id`),
  CONSTRAINT `deal_pipeline_step_work_orders_pipeline_step_id_foreign` FOREIGN KEY (`pipeline_step_id`) REFERENCES `deal_pipeline_steps` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `deal_pipeline_steps`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `deal_pipeline_steps` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `pipeline_template_id` bigint unsigned NOT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `position` int NOT NULL DEFAULT '0',
  `is_locked` tinyint(1) NOT NULL DEFAULT '0',
  `is_milestone` tinyint(1) NOT NULL DEFAULT '0',
  `is_suspensive` tinyint(1) NOT NULL DEFAULT '0',
  `completion_type` enum('manual_tick','date_input','amount_input','document_upload','document_signed','text_input','multi_field','auto_from_linked_deal') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'manual_tick',
  `completion_config` json DEFAULT NULL,
  `trigger_type` enum('on_creation','after_step','manual','on_date') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'on_creation',
  `trigger_step_id` bigint unsigned DEFAULT NULL,
  `days_offset` int NOT NULL DEFAULT '0',
  `rag_green_days` int NOT NULL DEFAULT '14',
  `rag_amber_days` int NOT NULL DEFAULT '7',
  `rag_red_days` int NOT NULL DEFAULT '3',
  `notify_agent` tinyint(1) NOT NULL DEFAULT '1',
  `notify_bm` tinyint(1) NOT NULL DEFAULT '1',
  `notify_admin` tinyint(1) NOT NULL DEFAULT '0',
  `status_trigger` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `negative_status_trigger` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `negative_outcome_label` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `requires_bm_approval` tinyint(1) NOT NULL DEFAULT '0',
  `escalation_config` json DEFAULT NULL,
  `required_before` json DEFAULT NULL,
  `sends_work_order` tinyint(1) NOT NULL DEFAULT '0',
  `work_order_service_type` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `work_order_trigger_point` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'activated',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `deal_pipeline_steps_pipeline_template_id_foreign` (`pipeline_template_id`),
  KEY `deal_pipeline_steps_trigger_step_id_foreign` (`trigger_step_id`),
  KEY `deal_pipeline_steps_agency_id_idx` (`agency_id`),
  CONSTRAINT `deal_pipeline_steps_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `deal_pipeline_steps_pipeline_template_id_foreign` FOREIGN KEY (`pipeline_template_id`) REFERENCES `deal_pipeline_templates` (`id`) ON DELETE CASCADE,
  CONSTRAINT `deal_pipeline_steps_trigger_step_id_foreign` FOREIGN KEY (`trigger_step_id`) REFERENCES `deal_pipeline_steps` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `deal_pipeline_templates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `deal_pipeline_templates` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `deal_type` enum('bond','cash','sale_of_2nd') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `branch_id` bigint unsigned DEFAULT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_by_id` bigint unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `deal_pipeline_templates_branch_id_foreign` (`branch_id`),
  KEY `deal_pipeline_templates_created_by_id_foreign` (`created_by_id`),
  KEY `deal_pipeline_templates_agency_id_idx` (`agency_id`),
  CONSTRAINT `deal_pipeline_templates_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `deal_pipeline_templates_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `deal_pipeline_templates_created_by_id_foreign` FOREIGN KEY (`created_by_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `deal_properties`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `deal_properties` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `deal_id` bigint unsigned NOT NULL,
  `property_id` bigint unsigned NOT NULL,
  `is_primary` tinyint(1) NOT NULL DEFAULT '0',
  `allocated_price` decimal(12,2) DEFAULT NULL,
  `allocated_commission` decimal(12,2) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `deal_properties_deal_id_index` (`deal_id`),
  KEY `deal_properties_property_id_index` (`property_id`),
  CONSTRAINT `deal_properties_deal_id_foreign` FOREIGN KEY (`deal_id`) REFERENCES `deals` (`id`) ON DELETE CASCADE,
  CONSTRAINT `deal_properties_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `deal_settlements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `deal_settlements` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `deal_id` bigint unsigned NOT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `side` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `share_percent` decimal(8,2) NOT NULL DEFAULT '0.00',
  `agent_cut_percent` decimal(8,2) NOT NULL DEFAULT '0.00',
  `paye_method` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'percentage',
  `paye_value` decimal(12,2) NOT NULL DEFAULT '0.00',
  `deductions` decimal(12,2) NOT NULL DEFAULT '0.00',
  `deductions_description` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `paid_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `deal_settlements_deal_id_user_id_side_unique` (`deal_id`,`user_id`,`side`),
  KEY `deal_settlements_user_id_foreign` (`user_id`),
  KEY `deal_settlements_deal_id_side_index` (`deal_id`,`side`),
  KEY `deal_settlements_agency_id_idx` (`agency_id`),
  CONSTRAINT `deal_settlements_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `deal_settlements_deal_id_foreign` FOREIGN KEY (`deal_id`) REFERENCES `deals` (`id`) ON DELETE CASCADE,
  CONSTRAINT `deal_settlements_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `deal_stage_document_rules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `deal_stage_document_rules` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `pipeline_step_id` bigint unsigned DEFAULT NULL,
  `document_type_id` bigint unsigned NOT NULL,
  `party_role` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `delivery_mode` enum('secure_link','direct_attachment') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'secure_link',
  `auto_on_stage_tick` tinyint(1) NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_by_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `dsdr_unique` (`agency_id`,`pipeline_step_id`,`document_type_id`,`party_role`),
  KEY `deal_stage_document_rules_pipeline_step_id_foreign` (`pipeline_step_id`),
  KEY `deal_stage_document_rules_document_type_id_foreign` (`document_type_id`),
  KEY `dsdr_stage_idx` (`agency_id`,`pipeline_step_id`,`is_active`),
  KEY `deal_stage_document_rules_agency_id_index` (`agency_id`),
  CONSTRAINT `deal_stage_document_rules_document_type_id_foreign` FOREIGN KEY (`document_type_id`) REFERENCES `document_types` (`id`) ON DELETE CASCADE,
  CONSTRAINT `deal_stage_document_rules_pipeline_step_id_foreign` FOREIGN KEY (`pipeline_step_id`) REFERENCES `deal_pipeline_steps` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `deal_stage_moves`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `deal_stage_moves` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `deal_id` bigint unsigned NOT NULL,
  `from_status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `to_status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `reason` enum('suspensive_conditions_met','registration','declined','manual') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'suspensive_conditions_met',
  `trigger_step_instance_id` bigint unsigned DEFAULT NULL,
  `mode` enum('auto','prompt') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'auto',
  `state` enum('applied','pending','confirmed','undone','dismissed') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'applied',
  `moved_by_id` bigint unsigned DEFAULT NULL,
  `moved_at` timestamp NULL DEFAULT NULL,
  `undone_by_id` bigint unsigned DEFAULT NULL,
  `undone_at` timestamp NULL DEFAULT NULL,
  `note` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `branch_id` bigint unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `dsm_step_fk` (`trigger_step_instance_id`),
  KEY `dsm_deal_state_idx` (`deal_id`,`state`),
  KEY `dsm_agency_idx` (`agency_id`),
  KEY `deal_stage_moves_branch_id_foreign` (`branch_id`),
  CONSTRAINT `deal_stage_moves_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `dsm_agency_fk` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `dsm_deal_fk` FOREIGN KEY (`deal_id`) REFERENCES `deals_v2` (`id`) ON DELETE CASCADE,
  CONSTRAINT `dsm_step_fk` FOREIGN KEY (`trigger_step_instance_id`) REFERENCES `deal_step_instances` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `deal_step_comments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `deal_step_comments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `deal_step_instance_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `body` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `branch_id` bigint unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `deal_step_comments_agency_id_foreign` (`agency_id`),
  KEY `deal_step_comments_user_id_foreign` (`user_id`),
  KEY `deal_step_comments_deal_step_instance_id_created_at_index` (`deal_step_instance_id`,`created_at`),
  KEY `deal_step_comments_branch_id_foreign` (`branch_id`),
  CONSTRAINT `deal_step_comments_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `deal_step_comments_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `deal_step_comments_deal_step_instance_id_foreign` FOREIGN KEY (`deal_step_instance_id`) REFERENCES `deal_step_instances` (`id`) ON DELETE CASCADE,
  CONSTRAINT `deal_step_comments_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `deal_step_documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `deal_step_documents` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `deal_step_instance_id` bigint unsigned NOT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `document_id` bigint unsigned DEFAULT NULL,
  `file_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `file_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `uploaded_by_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `branch_id` bigint unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `deal_step_documents_deal_step_instance_id_foreign` (`deal_step_instance_id`),
  KEY `deal_step_documents_uploaded_by_id_foreign` (`uploaded_by_id`),
  KEY `deal_step_documents_agency_id_idx` (`agency_id`),
  KEY `deal_step_documents_branch_id_foreign` (`branch_id`),
  CONSTRAINT `deal_step_documents_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `deal_step_documents_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `deal_step_documents_deal_step_instance_id_foreign` FOREIGN KEY (`deal_step_instance_id`) REFERENCES `deal_step_instances` (`id`) ON DELETE CASCADE,
  CONSTRAINT `deal_step_documents_uploaded_by_id_foreign` FOREIGN KEY (`uploaded_by_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `deal_step_escalations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `deal_step_escalations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `deal_id` bigint unsigned NOT NULL,
  `deal_step_instance_id` bigint unsigned NOT NULL,
  `level_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `kind` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'escalation',
  `recipient_user_id` bigint unsigned DEFAULT NULL,
  `channels` json DEFAULT NULL,
  `context` json DEFAULT NULL,
  `notified_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `branch_id` bigint unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `dse_step_level_recipient_uq` (`deal_step_instance_id`,`level_key`,`recipient_user_id`),
  KEY `deal_step_escalations_recipient_user_id_foreign` (`recipient_user_id`),
  KEY `deal_step_escalations_agency_id_index` (`agency_id`),
  KEY `deal_step_escalations_deal_id_index` (`deal_id`),
  KEY `deal_step_escalations_branch_id_foreign` (`branch_id`),
  CONSTRAINT `deal_step_escalations_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `deal_step_escalations_deal_step_instance_id_foreign` FOREIGN KEY (`deal_step_instance_id`) REFERENCES `deal_step_instances` (`id`) ON DELETE CASCADE,
  CONSTRAINT `deal_step_escalations_recipient_user_id_foreign` FOREIGN KEY (`recipient_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `deal_step_instance_dependencies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `deal_step_instance_dependencies` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `deal_step_instance_id` bigint unsigned NOT NULL,
  `depends_on_step_instance_id` bigint unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `dsid_instance_dep_unique` (`deal_step_instance_id`,`depends_on_step_instance_id`),
  KEY `dsid_dep_fk` (`depends_on_step_instance_id`),
  KEY `dsid_agency_idx` (`agency_id`),
  CONSTRAINT `dsid_agency_fk` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `dsid_dep_fk` FOREIGN KEY (`depends_on_step_instance_id`) REFERENCES `deal_step_instances` (`id`) ON DELETE CASCADE,
  CONSTRAINT `dsid_inst_fk` FOREIGN KEY (`deal_step_instance_id`) REFERENCES `deal_step_instances` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `deal_step_instances`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `deal_step_instances` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `deal_id` bigint unsigned DEFAULT NULL,
  `dr1_deal_id` bigint unsigned DEFAULT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `pipeline_step_id` bigint unsigned DEFAULT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `position` int NOT NULL DEFAULT '0',
  `display_priority` int DEFAULT NULL,
  `is_locked` tinyint(1) NOT NULL DEFAULT '0',
  `is_milestone` tinyint(1) NOT NULL DEFAULT '0',
  `is_grant_marker` tinyint(1) NOT NULL DEFAULT '0',
  `is_custom` tinyint(1) NOT NULL DEFAULT '0',
  `is_suspensive` tinyint(1) NOT NULL DEFAULT '0',
  `condition_key` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `completion_type` enum('manual_tick','date_input','amount_input','document_upload','document_signed','text_input','multi_field','auto_from_linked_deal') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'manual_tick',
  `completion_config` json DEFAULT NULL,
  `status` enum('not_started','active','completed','overdue','skipped') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'not_started',
  `na_reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `addendum_ref` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `waived_reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `trigger_type` enum('on_creation','after_step','manual','on_date') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `trigger_step_instance_id` bigint unsigned DEFAULT NULL,
  `days_offset` int NOT NULL DEFAULT '0',
  `due_date` date DEFAULT NULL,
  `planned_start_date` date DEFAULT NULL,
  `planned_start_manual` tinyint(1) NOT NULL DEFAULT '0',
  `actual_date` date DEFAULT NULL,
  `due_date_manual` tinyint(1) NOT NULL DEFAULT '0',
  `activated_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `completed_by_id` bigint unsigned DEFAULT NULL,
  `completion_data` json DEFAULT NULL,
  `rag_green_days` int NOT NULL DEFAULT '14',
  `rag_amber_days` int NOT NULL DEFAULT '7',
  `rag_red_days` int NOT NULL DEFAULT '3',
  `current_rag` enum('grey','green','amber','red','overdue') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'grey',
  `notify_agent` tinyint(1) NOT NULL DEFAULT '1',
  `notify_bm` tinyint(1) NOT NULL DEFAULT '1',
  `notify_admin` tinyint(1) NOT NULL DEFAULT '0',
  `status_trigger` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `negative_status_trigger` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `negative_outcome_label` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `requires_bm_approval` tinyint(1) NOT NULL DEFAULT '0',
  `approval_status` enum('not_required','pending','approved','rejected') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'not_required',
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `approved_by_id` bigint unsigned DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `approval_notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `branch_id` bigint unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `deal_step_instances_deal_id_foreign` (`deal_id`),
  KEY `deal_step_instances_pipeline_step_id_foreign` (`pipeline_step_id`),
  KEY `deal_step_instances_completed_by_id_foreign` (`completed_by_id`),
  KEY `deal_step_instances_trigger_step_instance_id_foreign` (`trigger_step_instance_id`),
  KEY `deal_step_instances_approved_by_id_foreign` (`approved_by_id`),
  KEY `deal_step_instances_agency_id_idx` (`agency_id`),
  KEY `deal_step_instances_dr1_deal_id_foreign` (`dr1_deal_id`),
  KEY `deal_step_instances_branch_id_foreign` (`branch_id`),
  CONSTRAINT `deal_step_instances_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `deal_step_instances_approved_by_id_foreign` FOREIGN KEY (`approved_by_id`) REFERENCES `users` (`id`),
  CONSTRAINT `deal_step_instances_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `deal_step_instances_completed_by_id_foreign` FOREIGN KEY (`completed_by_id`) REFERENCES `users` (`id`),
  CONSTRAINT `deal_step_instances_deal_id_foreign` FOREIGN KEY (`deal_id`) REFERENCES `deals_v2` (`id`) ON DELETE CASCADE,
  CONSTRAINT `deal_step_instances_dr1_deal_id_foreign` FOREIGN KEY (`dr1_deal_id`) REFERENCES `deals` (`id`) ON DELETE CASCADE,
  CONSTRAINT `deal_step_instances_pipeline_step_id_foreign` FOREIGN KEY (`pipeline_step_id`) REFERENCES `deal_pipeline_steps` (`id`),
  CONSTRAINT `deal_step_instances_trigger_step_instance_id_foreign` FOREIGN KEY (`trigger_step_instance_id`) REFERENCES `deal_step_instances` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `deal_step_work_orders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `deal_step_work_orders` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `deal_step_instance_id` bigint unsigned NOT NULL,
  `trigger_step_instance_id` bigint unsigned DEFAULT NULL,
  `dr1_deal_id` bigint unsigned DEFAULT NULL,
  `agency_id` bigint unsigned DEFAULT NULL,
  `service_type` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `responsible_party` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'supplier',
  `service_provider_id` bigint unsigned DEFAULT NULL,
  `recipient_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `recipient_email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cc_emails` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `document_id` bigint unsigned DEFAULT NULL,
  `sent_at` timestamp NULL DEFAULT NULL,
  `sent_by_id` bigint unsigned DEFAULT NULL,
  `send_error` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `deal_step_work_orders_deal_step_instance_id_index` (`deal_step_instance_id`),
  KEY `deal_step_work_orders_dr1_deal_id_index` (`dr1_deal_id`),
  KEY `deal_step_work_orders_agency_id_index` (`agency_id`),
  KEY `deal_step_work_orders_trigger_step_instance_id_index` (`trigger_step_instance_id`),
  CONSTRAINT `deal_step_work_orders_deal_step_instance_id_foreign` FOREIGN KEY (`deal_step_instance_id`) REFERENCES `deal_step_instances` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `deal_user`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `deal_user` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `deal_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `side` enum('listing','selling') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `agent_split_percent` decimal(5,2) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `agent_cut_percent` decimal(8,2) DEFAULT NULL,
  `paye_method` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `paye_value` decimal(12,2) DEFAULT NULL,
  `deductions` decimal(12,2) DEFAULT NULL,
  `deductions_description` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL,
  `sliding_granted_month` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `sliding_sequence_in_month` int DEFAULT NULL,
  `sliding_applied_cut_percent` decimal(5,2) DEFAULT NULL,
  `sliding_applied_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `deal_user_deal_id_user_id_side_unique` (`deal_id`,`user_id`,`side`),
  KEY `deal_user_user_id_foreign` (`user_id`),
  CONSTRAINT `deal_user_deal_id_foreign` FOREIGN KEY (`deal_id`) REFERENCES `deals` (`id`) ON DELETE CASCADE,
  CONSTRAINT `deal_user_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `deal_v2_agents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `deal_v2_agents` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `deal_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `side` enum('listing','selling') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `agent_split_percent` decimal(5,2) DEFAULT NULL,
  `agent_cut_percent` decimal(8,2) DEFAULT NULL,
  `paye_method` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `paye_value` decimal(12,2) DEFAULT NULL,
  `deductions` decimal(12,2) DEFAULT NULL,
  `deductions_description` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL,
  `sliding_granted_month` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `sliding_sequence_in_month` int DEFAULT NULL,
  `sliding_applied_cut_percent` decimal(5,2) DEFAULT NULL,
  `sliding_applied_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `deal_v2_agents_deal_id_user_id_side_unique` (`deal_id`,`user_id`,`side`),
  KEY `deal_v2_agents_user_id_foreign` (`user_id`),
  CONSTRAINT `deal_v2_agents_deal_id_foreign` FOREIGN KEY (`deal_id`) REFERENCES `deals_v2` (`id`) ON DELETE CASCADE,
  CONSTRAINT `deal_v2_agents_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `deal_v2_contacts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `deal_v2_contacts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `deal_id` bigint unsigned NOT NULL,
  `contact_id` bigint unsigned DEFAULT NULL,
  `agency_service_provider_id` bigint unsigned DEFAULT NULL,
  `role` enum('buyer','seller','co_buyer','co_seller','conveyancer','bond_originator','other','transfer_attorney','bond_attorney','electrician_coc','entomologist','originator','service_provider') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `deal_v2_contacts_deal_id_foreign` (`deal_id`),
  KEY `deal_v2_contacts_contact_id_foreign` (`contact_id`),
  KEY `deal_v2_contacts_agency_service_provider_id_index` (`agency_service_provider_id`),
  CONSTRAINT `deal_v2_contacts_contact_id_foreign` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `deal_v2_contacts_deal_id_foreign` FOREIGN KEY (`deal_id`) REFERENCES `deals_v2` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `deal_v2_remarks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `deal_v2_remarks` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `deal_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `body` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `branch_id` bigint unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `dvr_user_fk` (`user_id`),
  KEY `dvr_deal_created_idx` (`deal_id`,`created_at`),
  KEY `dvr_agency_idx` (`agency_id`),
  KEY `deal_v2_remarks_branch_id_foreign` (`branch_id`),
  CONSTRAINT `deal_v2_remarks_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `dvr_agency_fk` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `dvr_deal_fk` FOREIGN KEY (`deal_id`) REFERENCES `deals_v2` (`id`) ON DELETE CASCADE,
  CONSTRAINT `dvr_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `deal_v2_settlements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `deal_v2_settlements` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `deal_id` bigint unsigned NOT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `side` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `share_percent` decimal(8,2) NOT NULL DEFAULT '0.00',
  `agent_cut_percent` decimal(8,2) NOT NULL DEFAULT '0.00',
  `paye_method` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'percentage',
  `paye_value` decimal(12,2) NOT NULL DEFAULT '0.00',
  `deductions` decimal(12,2) NOT NULL DEFAULT '0.00',
  `deductions_description` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `paid_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `branch_id` bigint unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `deal_v2_settlements_deal_id_user_id_side_unique` (`deal_id`,`user_id`,`side`),
  KEY `deal_v2_settlements_user_id_foreign` (`user_id`),
  KEY `deal_v2_settlements_deal_id_side_index` (`deal_id`,`side`),
  KEY `deal_v2_settlements_agency_id_idx` (`agency_id`),
  KEY `deal_v2_settlements_branch_id_foreign` (`branch_id`),
  CONSTRAINT `deal_v2_settlements_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `deal_v2_settlements_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `deal_v2_settlements_deal_id_foreign` FOREIGN KEY (`deal_id`) REFERENCES `deals_v2` (`id`) ON DELETE CASCADE,
  CONSTRAINT `deal_v2_settlements_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `deals`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `deals` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `deal_pipeline_template_id` bigint unsigned DEFAULT NULL,
  `pipeline_started_at` timestamp NULL DEFAULT NULL,
  `deal_v2_id` bigint unsigned DEFAULT NULL,
  `deal_no` int unsigned DEFAULT NULL,
  `file_no` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `branch_id` bigint unsigned DEFAULT NULL,
  `managed_by_user_id` bigint unsigned DEFAULT NULL,
  `period` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `deal_date` date NOT NULL,
  `deal_type` enum('bond','cash','sale_of_2nd') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'DR2 capture: cash/bond/sale-of-2nd. Nullable — legacy DR1 rows stay NULL.',
  `property_address` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `seller_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `buyer_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `attorney_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `attorney_provider_id` bigint unsigned DEFAULT NULL,
  `attorney_contact_id` bigint unsigned DEFAULT NULL,
  `bond_originator_provider_id` bigint unsigned DEFAULT NULL,
  `bond_originator_contact_id` bigint unsigned DEFAULT NULL,
  `external_agency_provider_id` bigint unsigned DEFAULT NULL,
  `external_agency_contact_id` bigint unsigned DEFAULT NULL,
  `bond_attorney_provider_id` bigint unsigned DEFAULT NULL,
  `bond_attorney_contact_id` bigint unsigned DEFAULT NULL,
  `accepted_status` varchar(1) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `granted_at` datetime DEFAULT NULL,
  `commission_status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `registration_date` date DEFAULT NULL,
  `sale_date` date DEFAULT NULL COMMENT 'Phase 3i analytics alias of registration_date.',
  `link_source` enum('manual','auto_address_match','auto_address_date_match','presentation_link','admin_review') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `link_confidence` enum('exact','high','medium','low') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `link_reviewed_at` timestamp NULL DEFAULT NULL,
  `link_reviewed_by_user_id` bigint unsigned DEFAULT NULL,
  `remarks` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `property_value` decimal(12,2) NOT NULL,
  `sale_price` bigint unsigned DEFAULT NULL COMMENT 'Phase 3i canonical sale price in Rands (no cents). Mirrors property_value.',
  `total_commission` decimal(12,2) NOT NULL,
  `listing_external` tinyint(1) NOT NULL DEFAULT '0',
  `listing_external_agency` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `listing_external_agency_provider_id` bigint unsigned DEFAULT NULL,
  `listing_external_agency_contact_id` bigint unsigned DEFAULT NULL,
  `listing_our_share_percent` decimal(5,2) NOT NULL DEFAULT '100.00',
  `selling_external` tinyint(1) NOT NULL DEFAULT '0',
  `selling_external_agency` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `selling_external_agency_provider_id` bigint unsigned DEFAULT NULL,
  `selling_external_agency_contact_id` bigint unsigned DEFAULT NULL,
  `listing_split_percent` decimal(5,2) NOT NULL DEFAULT '50.00',
  `selling_split_percent` decimal(5,2) NOT NULL DEFAULT '50.00',
  `selling_our_share_percent` decimal(5,2) NOT NULL DEFAULT '100.00',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `agency_id` bigint unsigned DEFAULT NULL,
  `property_id` bigint unsigned DEFAULT NULL,
  `presentation_id` bigint unsigned DEFAULT NULL,
  `is_demo` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `deals_branch_id_index` (`branch_id`),
  KEY `deals_file_no_index` (`file_no`),
  KEY `deals_accepted_status_index` (`accepted_status`),
  KEY `deals_deal_no_index` (`deal_no`),
  KEY `deals_agency_id_index` (`agency_id`),
  KEY `idx_deals_is_demo` (`is_demo`),
  KEY `deals_link_reviewer_fk` (`link_reviewed_by_user_id`),
  KEY `deals_property_sale_date_idx` (`property_id`,`sale_date`),
  KEY `deals_presentation_idx` (`presentation_id`),
  KEY `deals_managed_by_user_id_foreign` (`managed_by_user_id`),
  KEY `deals_deal_v2_id_index` (`deal_v2_id`),
  KEY `deals_deal_pipeline_template_id_foreign` (`deal_pipeline_template_id`),
  CONSTRAINT `deals_deal_pipeline_template_id_foreign` FOREIGN KEY (`deal_pipeline_template_id`) REFERENCES `deal_pipeline_templates` (`id`) ON DELETE SET NULL,
  CONSTRAINT `deals_link_reviewer_fk` FOREIGN KEY (`link_reviewed_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `deals_managed_by_user_id_foreign` FOREIGN KEY (`managed_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `deals_presentation_fk` FOREIGN KEY (`presentation_id`) REFERENCES `presentations` (`id`) ON DELETE SET NULL,
  CONSTRAINT `deals_property_fk` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `deals_v2`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `deals_v2` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `legacy_deal_id` bigint unsigned DEFAULT NULL,
  `reference` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `deal_type` enum('bond','cash','sale_of_2nd') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('active','granted','completed','cancelled','on_hold','declined') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `property_id` bigint unsigned DEFAULT NULL,
  `listing_agent_id` bigint unsigned NOT NULL,
  `selling_agent_id` bigint unsigned DEFAULT NULL,
  `pipeline_template_id` bigint unsigned DEFAULT NULL,
  `linked_deal_id` bigint unsigned DEFAULT NULL,
  `purchase_price` decimal(14,2) NOT NULL,
  `commission_percentage` decimal(5,2) DEFAULT NULL,
  `commission_amount` decimal(12,2) NOT NULL,
  `commission_vat` decimal(12,2) NOT NULL,
  `listing_split_percent` decimal(5,2) NOT NULL DEFAULT '50.00',
  `listing_external` tinyint(1) NOT NULL DEFAULT '0',
  `listing_our_share_percent` decimal(5,2) NOT NULL DEFAULT '100.00',
  `listing_external_agency` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `selling_split_percent` decimal(5,2) NOT NULL DEFAULT '50.00',
  `selling_external` tinyint(1) NOT NULL DEFAULT '0',
  `selling_our_share_percent` decimal(5,2) NOT NULL DEFAULT '100.00',
  `selling_external_agency` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `offer_date` date NOT NULL,
  `expected_registration` date DEFAULT NULL,
  `actual_registration` date DEFAULT NULL,
  `overall_rag` enum('grey','green','amber','red','overdue') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'grey',
  `backfilled_at` timestamp NULL DEFAULT NULL,
  `commission_status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Not Paid',
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `branch_id` bigint unsigned NOT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `created_by_id` bigint unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `deals_v2_reference_unique` (`reference`),
  KEY `deals_v2_property_id_foreign` (`property_id`),
  KEY `deals_v2_listing_agent_id_foreign` (`listing_agent_id`),
  KEY `deals_v2_selling_agent_id_foreign` (`selling_agent_id`),
  KEY `deals_v2_pipeline_template_id_foreign` (`pipeline_template_id`),
  KEY `deals_v2_branch_id_foreign` (`branch_id`),
  KEY `deals_v2_created_by_id_foreign` (`created_by_id`),
  KEY `deals_v2_linked_deal_id_foreign` (`linked_deal_id`),
  KEY `deals_v2_agency_id_idx` (`agency_id`),
  KEY `deals_v2_legacy_deal_id_index` (`legacy_deal_id`),
  CONSTRAINT `deals_v2_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `deals_v2_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  CONSTRAINT `deals_v2_created_by_id_foreign` FOREIGN KEY (`created_by_id`) REFERENCES `users` (`id`),
  CONSTRAINT `deals_v2_linked_deal_id_foreign` FOREIGN KEY (`linked_deal_id`) REFERENCES `deals_v2` (`id`) ON DELETE SET NULL,
  CONSTRAINT `deals_v2_listing_agent_id_foreign` FOREIGN KEY (`listing_agent_id`) REFERENCES `users` (`id`),
  CONSTRAINT `deals_v2_pipeline_template_id_foreign` FOREIGN KEY (`pipeline_template_id`) REFERENCES `deal_pipeline_templates` (`id`),
  CONSTRAINT `deals_v2_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`),
  CONSTRAINT `deals_v2_selling_agent_id_foreign` FOREIGN KEY (`selling_agent_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `demo_access_grants`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `demo_access_grants` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `company_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `contact_email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `contact_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `contact_id` bigint unsigned DEFAULT NULL,
  `credential_hash` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `expiry_hours` int unsigned DEFAULT NULL,
  `first_login_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `revoked_at` timestamp NULL DEFAULT NULL,
  `revoked_by_user_id` bigint unsigned DEFAULT NULL,
  `archived_at` timestamp NULL DEFAULT NULL,
  `issued_by_user_id` bigint unsigned NOT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `demo_grants_email_idx` (`contact_email`),
  KEY `demo_grants_lifecycle_idx` (`archived_at`,`revoked_at`,`expires_at`),
  KEY `demo_grants_contact_fk` (`contact_id`),
  KEY `demo_grants_issuer_fk` (`issued_by_user_id`),
  KEY `demo_grants_revoker_fk` (`revoked_by_user_id`),
  CONSTRAINT `demo_grants_contact_fk` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `demo_grants_issuer_fk` FOREIGN KEY (`issued_by_user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `demo_grants_revoker_fk` FOREIGN KEY (`revoked_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `demo_connectors`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `demo_connectors` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Demo connector',
  `key_prefix` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `secret_hash` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `revoked_at` timestamp NULL DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `demo_connectors_key_prefix_unique` (`key_prefix`),
  KEY `demo_connectors_revoked_idx` (`revoked_at`),
  KEY `demo_connectors_creator_fk` (`created_by`),
  CONSTRAINT `demo_connectors_creator_fk` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `demo_page_views`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `demo_page_views` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `demo_session_id` bigint unsigned NOT NULL,
  `path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `route_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `viewed_at` timestamp NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `demo_page_views_session_idx` (`demo_session_id`,`viewed_at`),
  CONSTRAINT `demo_page_views_session_fk` FOREIGN KEY (`demo_session_id`) REFERENCES `demo_sessions` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `demo_sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `demo_sessions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `demo_access_grant_id` bigint unsigned NOT NULL,
  `session_token` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `started_at` timestamp NOT NULL,
  `last_seen_at` timestamp NOT NULL,
  `ip_address` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `demo_sessions_session_token_unique` (`session_token`),
  KEY `demo_sessions_grant_idx` (`demo_access_grant_id`,`started_at`),
  CONSTRAINT `demo_sessions_grant_fk` FOREIGN KEY (`demo_access_grant_id`) REFERENCES `demo_access_grants` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `demo_tnc_acceptances`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `demo_tnc_acceptances` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `demo_access_grant_id` bigint unsigned NOT NULL,
  `demo_tnc_version_id` bigint unsigned NOT NULL,
  `accepted_at` timestamp NOT NULL,
  `ip_address` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `demo_tnc_accept_unq` (`demo_access_grant_id`,`demo_tnc_version_id`),
  KEY `demo_tnc_accept_version_fk` (`demo_tnc_version_id`),
  CONSTRAINT `demo_tnc_accept_grant_fk` FOREIGN KEY (`demo_access_grant_id`) REFERENCES `demo_access_grants` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `demo_tnc_accept_version_fk` FOREIGN KEY (`demo_tnc_version_id`) REFERENCES `demo_tnc_versions` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `demo_tnc_versions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `demo_tnc_versions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `version` int unsigned NOT NULL,
  `body` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `published_at` timestamp NOT NULL,
  `published_by_user_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `demo_tnc_versions_version_unique` (`version`),
  KEY `demo_tnc_versions_publisher_fk` (`published_by_user_id`),
  CONSTRAINT `demo_tnc_versions_publisher_fk` FOREIGN KEY (`published_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `deposit_interest_calculations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `deposit_interest_calculations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `property_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `deposit_amount` decimal(12,2) NOT NULL,
  `invest_date` date NOT NULL,
  `refund_date` date NOT NULL,
  `topups` json DEFAULT NULL,
  `total_deposited` decimal(12,2) NOT NULL,
  `total_interest` decimal(12,2) NOT NULL,
  `grand_total` decimal(12,2) NOT NULL,
  `breakdown` json NOT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `deposit_interest_calculations_user_id_index` (`user_id`),
  KEY `deposit_interest_calculations_created_at_index` (`created_at`),
  CONSTRAINT `deposit_interest_calculations_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `deposit_trust_interest`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `deposit_trust_interest` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned DEFAULT NULL,
  `interest_date` date NOT NULL,
  `total_invested_funds` decimal(14,2) NOT NULL,
  `interest_earned` decimal(10,2) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `deposit_trust_interest_agency_date_unique` (`agency_id`,`interest_date`),
  KEY `deposit_trust_interest_interest_date_index` (`interest_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `designations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `designations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `sort_order` int NOT NULL DEFAULT '0',
  `is_enabled` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `designations_name_unique` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `dev_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `dev_settings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `dev_settings_key_unique` (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `device_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `device_tokens` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `platform` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(512) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `app_version` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_seen_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `device_tokens_user_token_unique` (`user_id`,`token`),
  KEY `device_tokens_token_index` (`token`),
  CONSTRAINT `device_tokens_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `document_amendments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `document_amendments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `document_id` bigint unsigned NOT NULL,
  `signature_template_id` bigint unsigned NOT NULL,
  `amended_by_request_id` bigint unsigned DEFAULT NULL,
  `amendment_type` enum('addition','strikeout','modification','flag_raised') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'addition',
  `flag_origin` enum('agent_preparation','compliance_officer','signing_party') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `flag_clause_ref` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `flag_reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `section_reference` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `original_text` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `new_text` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `document_version_before` int unsigned NOT NULL DEFAULT '1',
  `document_version_after` int unsigned NOT NULL DEFAULT '2',
  `document_hash_before` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `document_hash_after` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('pending','accepted','rejected') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `document_amendments_amended_by_request_id_foreign` (`amended_by_request_id`),
  KEY `document_amendments_signature_template_id_status_index` (`signature_template_id`,`status`),
  KEY `document_amendments_document_id_index` (`document_id`),
  CONSTRAINT `document_amendments_amended_by_request_id_foreign` FOREIGN KEY (`amended_by_request_id`) REFERENCES `signature_requests` (`id`) ON DELETE CASCADE,
  CONSTRAINT `document_amendments_document_id_foreign` FOREIGN KEY (`document_id`) REFERENCES `docuperfect_documents` (`id`) ON DELETE CASCADE,
  CONSTRAINT `document_amendments_signature_template_id_foreign` FOREIGN KEY (`signature_template_id`) REFERENCES `signature_templates` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `document_clause_strikethroughs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `document_clause_strikethroughs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `signature_template_id` bigint unsigned NOT NULL,
  `agency_id` bigint unsigned DEFAULT NULL,
  `clause_ref` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `clause_original_text` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `replacement_condition_id` bigint unsigned DEFAULT NULL,
  `proposed_by_user_id` bigint unsigned DEFAULT NULL,
  `proposed_by_party_id` bigint unsigned DEFAULT NULL,
  `amendment_id` bigint unsigned NOT NULL,
  `status` enum('proposed','approved','rejected','superseded') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `approved_by_agent_at` timestamp NULL DEFAULT NULL,
  `rejected_by_agent_at` timestamp NULL DEFAULT NULL,
  `rejection_reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `doc_strk_tpl_idx` (`signature_template_id`),
  KEY `doc_strk_agency_tpl_idx` (`agency_id`,`signature_template_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `document_conditions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `document_conditions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `signature_template_id` bigint unsigned NOT NULL,
  `agency_id` bigint unsigned DEFAULT NULL,
  `block_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `block_purpose` enum('other_conditions','included_items','excluded_items','custom_named') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `custom_label` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `condition_number` int unsigned NOT NULL,
  `content` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_locked` tinyint(1) NOT NULL DEFAULT '0',
  `is_override` tinyint(1) NOT NULL DEFAULT '0',
  `overrides_clause_ref` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `relates_to_clause_ref` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `added_by_user_id` bigint unsigned DEFAULT NULL,
  `added_by_party_id` bigint unsigned DEFAULT NULL,
  `added_via` enum('agent_preparation','agent_signing','recipient_signing','system_default') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source` enum('library','custom') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `library_clause_id` bigint unsigned DEFAULT NULL,
  `amendment_id` bigint unsigned DEFAULT NULL,
  `approved_by_agent_at` timestamp NULL DEFAULT NULL,
  `approved_by_agent_user_id` bigint unsigned DEFAULT NULL,
  `superseded_at` timestamp NULL DEFAULT NULL,
  `superseded_by_condition_id` bigint unsigned DEFAULT NULL,
  `rejected_at` timestamp NULL DEFAULT NULL,
  `rejected_by_user_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `doc_cond_tpl_block_idx` (`signature_template_id`,`block_id`),
  KEY `doc_cond_agency_tpl_idx` (`agency_id`,`signature_template_id`),
  KEY `doc_cond_relates_to_idx` (`signature_template_id`,`relates_to_clause_ref`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `document_contact`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `document_contact` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `document_id` bigint unsigned NOT NULL,
  `contact_id` bigint unsigned NOT NULL,
  `party_role` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `document_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_signed` tinyint(1) NOT NULL DEFAULT '0',
  `signed_at` timestamp NULL DEFAULT NULL,
  `signed_pdf_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `document_contact_document_id_contact_id_party_role_unique` (`document_id`,`contact_id`,`party_role`),
  KEY `document_contact_contact_id_document_type_index` (`contact_id`,`document_type`),
  CONSTRAINT `document_contact_contact_id_foreign` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `document_contact_document_id_foreign` FOREIGN KEY (`document_id`) REFERENCES `docuperfect_documents` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `document_contacts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `document_contacts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `document_id` bigint unsigned NOT NULL,
  `contact_id` bigint unsigned NOT NULL,
  `party_role` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `document_contacts_document_id_contact_id_party_role_unique` (`document_id`,`contact_id`,`party_role`),
  KEY `document_contacts_contact_id_foreign` (`contact_id`),
  CONSTRAINT `document_contacts_contact_id_foreign` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `document_contacts_document_id_foreign` FOREIGN KEY (`document_id`) REFERENCES `documents` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `document_custom_fields`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `document_custom_fields` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `template_id` bigint unsigned NOT NULL,
  `field_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `label` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `assigned_to` enum('agent','lessor','lessee','buyer','seller') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'agent',
  `field_type` enum('text','date','number') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'text',
  `default_value` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sort_order` int NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `document_custom_fields_template_id_foreign` (`template_id`),
  CONSTRAINT `document_custom_fields_template_id_foreign` FOREIGN KEY (`template_id`) REFERENCES `docuperfect_templates` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `document_filing_register`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `document_filing_register` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `branch_id` bigint unsigned NOT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `agent_id` bigint unsigned NOT NULL,
  `property_id` bigint unsigned DEFAULT NULL,
  `seller_contact_id` bigint unsigned DEFAULT NULL,
  `document_type` enum('OA','EA','Other') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'OA',
  `file_reference` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `sequence_number` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `property_address` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `seller_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `expiry_date` date DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `captured_by` bigint unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `document_filing_register_captured_by_foreign` (`captured_by`),
  KEY `document_filing_register_branch_id_index` (`branch_id`),
  KEY `document_filing_register_agent_id_index` (`agent_id`),
  KEY `document_filing_register_property_address_index` (`property_address`),
  KEY `document_filing_register_expiry_date_index` (`expiry_date`),
  KEY `document_filing_register_agency_id_idx` (`agency_id`),
  KEY `dfr_property_fk` (`property_id`),
  KEY `dfr_seller_contact_fk` (`seller_contact_id`),
  CONSTRAINT `dfr_property_fk` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE SET NULL,
  CONSTRAINT `dfr_seller_contact_fk` FOREIGN KEY (`seller_contact_id`) REFERENCES `contacts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `document_filing_register_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `document_filing_register_agent_id_foreign` FOREIGN KEY (`agent_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `document_filing_register_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE,
  CONSTRAINT `document_filing_register_captured_by_foreign` FOREIGN KEY (`captured_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `document_library_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `document_library_items` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `uploaded_by_user_id` bigint unsigned NOT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `original_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `stored_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `mime_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bytes` bigint unsigned NOT NULL DEFAULT '0',
  `doc_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'other',
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `tags_json` json DEFAULT NULL,
  `is_enabled` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `document_library_items_doc_type_index` (`doc_type`),
  KEY `document_library_items_uploaded_by_user_id_index` (`uploaded_by_user_id`),
  KEY `document_library_items_created_at_index` (`created_at`),
  KEY `document_library_items_agency_id_idx` (`agency_id`),
  CONSTRAINT `document_library_items_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `document_library_items_uploaded_by_user_id_foreign` FOREIGN KEY (`uploaded_by_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `document_library_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `document_library_types` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `label` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `sort_order` int unsigned NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `document_types_key_unique` (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `document_properties`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `document_properties` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `document_id` bigint unsigned NOT NULL,
  `property_id` bigint unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `document_properties_document_id_property_id_unique` (`document_id`,`property_id`),
  KEY `document_properties_property_id_foreign` (`property_id`),
  CONSTRAINT `document_properties_document_id_foreign` FOREIGN KEY (`document_id`) REFERENCES `documents` (`id`) ON DELETE CASCADE,
  CONSTRAINT `document_properties_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `document_sealed_versions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `document_sealed_versions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `document_id` bigint unsigned NOT NULL,
  `signature_template_id` bigint unsigned DEFAULT NULL,
  `version` int unsigned NOT NULL,
  `event_type` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `signer_identity` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `signer_user_id` bigint unsigned DEFAULT NULL,
  `actor_type` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `actor_name` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `actor_role` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sealed_html` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `content_hash` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `prev_hash` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ip_address` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `agency_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `doc_sealed_version_unique` (`document_id`,`version`),
  KEY `document_sealed_versions_document_id_created_at_index` (`document_id`,`created_at`),
  KEY `document_sealed_versions_signature_template_id_index` (`signature_template_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `document_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `document_types` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `slug` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `label` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `sort_order` int unsigned NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `grouping` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'shared',
  `contact_roles` json DEFAULT NULL,
  `fica_slot` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'none',
  `buyer_pack_eligible` tinyint(1) NOT NULL DEFAULT '0',
  `listing_types` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `splitter_doc_types_slug_unique` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `documents` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `original_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `storage_path` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `disk` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'local',
  `mime_type` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `size` bigint unsigned NOT NULL DEFAULT '0',
  `document_type_id` bigint unsigned DEFAULT NULL,
  `source_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'upload',
  `source_id` bigint unsigned DEFAULT NULL,
  `deal_id` bigint unsigned DEFAULT NULL,
  `custom_field_key` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `checklist_item_id` bigint unsigned DEFAULT NULL,
  `tenant_portal_visible` tinyint(1) NOT NULL DEFAULT '0',
  `landlord_portal_visible` tinyint(1) NOT NULL DEFAULT '0',
  `uploaded_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `agency_id` bigint unsigned DEFAULT NULL,
  `branch_id` bigint unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `documents_document_type_id_foreign` (`document_type_id`),
  KEY `documents_uploaded_by_foreign` (`uploaded_by`),
  KEY `documents_agency_id_index` (`agency_id`),
  KEY `documents_branch_id_foreign` (`branch_id`),
  KEY `documents_agency_branch_idx` (`agency_id`,`branch_id`),
  KEY `documents_deal_id_index` (`deal_id`),
  KEY `documents_checklist_item_id_foreign` (`checklist_item_id`),
  CONSTRAINT `documents_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `documents_checklist_item_id_foreign` FOREIGN KEY (`checklist_item_id`) REFERENCES `rental_application_checklist_items` (`id`) ON DELETE SET NULL,
  CONSTRAINT `documents_deal_id_foreign` FOREIGN KEY (`deal_id`) REFERENCES `deals_v2` (`id`) ON DELETE SET NULL,
  CONSTRAINT `documents_document_type_id_foreign` FOREIGN KEY (`document_type_id`) REFERENCES `document_types` (`id`) ON DELETE SET NULL,
  CONSTRAINT `documents_uploaded_by_foreign` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `docuperfect_clause_branches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `docuperfect_clause_branches` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `clause_id` bigint unsigned NOT NULL,
  `branch_id` bigint unsigned NOT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `docuperfect_clause_branches_clause_id_branch_id_unique` (`clause_id`,`branch_id`),
  KEY `docuperfect_clause_branches_branch_id_foreign` (`branch_id`),
  CONSTRAINT `docuperfect_clause_branches_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE,
  CONSTRAINT `docuperfect_clause_branches_clause_id_foreign` FOREIGN KEY (`clause_id`) REFERENCES `docuperfect_clauses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `docuperfect_clauses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `docuperfect_clauses` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned DEFAULT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `text` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_global` tinyint(1) NOT NULL DEFAULT '0',
  `owner_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `docuperfect_clauses_owner_id_foreign` (`owner_id`),
  KEY `docuperfect_clauses_agency_id_index` (`agency_id`),
  CONSTRAINT `docuperfect_clauses_owner_id_foreign` FOREIGN KEY (`owner_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `docuperfect_document_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `docuperfect_document_types` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `sort_order` int NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `docuperfect_documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `docuperfect_documents` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `template_id` bigint unsigned DEFAULT NULL,
  `fields_json` json DEFAULT NULL,
  `web_template_data` json DEFAULT NULL,
  `signed_paginated_html` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `owner_id` bigint unsigned NOT NULL,
  `branch_id` bigint unsigned DEFAULT NULL,
  `pack_instance_id` bigint unsigned DEFAULT NULL,
  `archived_at` timestamp NULL DEFAULT NULL,
  `document_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `property_address` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `property_id` bigint unsigned DEFAULT NULL,
  `lease_expiry_date` date DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `docuperfect_documents_template_id_foreign` (`template_id`),
  KEY `docuperfect_documents_owner_id_foreign` (`owner_id`),
  KEY `docuperfect_documents_branch_id_foreign` (`branch_id`),
  KEY `idx_dpdocs_prop_type_id` (`property_id`,`document_type`,`id`),
  KEY `docuperfect_documents_agency_id_index` (`agency_id`),
  KEY `idx_docuperfect_documents_agency_id` (`agency_id`),
  CONSTRAINT `docuperfect_documents_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `docuperfect_documents_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `docuperfect_documents_owner_id_foreign` FOREIGN KEY (`owner_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `docuperfect_documents_template_id_foreign` FOREIGN KEY (`template_id`) REFERENCES `docuperfect_templates` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `docuperfect_esign_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `docuperfect_esign_settings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `async_completion_enabled` tinyint(1) NOT NULL DEFAULT '1',
  `finalization_stuck_threshold_minutes` smallint unsigned NOT NULL DEFAULT '15',
  `whatsapp_resend_enabled` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `docuperfect_esign_settings_agency_id_unique` (`agency_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `docuperfect_field_corrections`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `docuperfect_field_corrections` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `context` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `claude_suggested_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `claude_suggested_label` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_corrected_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_corrected_label` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `correction_reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `document_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `docuperfect_field_corrections_user_id_foreign` (`user_id`),
  KEY `docuperfect_field_corrections_context_index` (`context`),
  KEY `docuperfect_field_corrections_document_type_index` (`document_type`),
  CONSTRAINT `docuperfect_field_corrections_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `docuperfect_field_groups`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `docuperfect_field_groups` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned DEFAULT NULL,
  `created_by` bigint unsigned NOT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fields` json NOT NULL,
  `layout` enum('vertical','horizontal') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'vertical',
  `sort_order` int NOT NULL DEFAULT '0',
  `is_global` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `docuperfect_field_groups_agency_id_foreign` (`agency_id`),
  KEY `docuperfect_field_groups_created_by_foreign` (`created_by`),
  CONSTRAINT `docuperfect_field_groups_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `docuperfect_field_groups_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `docuperfect_import_drafts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `docuperfect_import_drafts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `filename` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `html` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `fields_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `docuperfect_import_drafts_user_id_deleted_at_index` (`user_id`,`deleted_at`),
  CONSTRAINT `docuperfect_import_drafts_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `docuperfect_named_fields`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `docuperfect_named_fields` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `field_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'text',
  `default_options` json DEFAULT NULL,
  `sort_order` int NOT NULL DEFAULT '0',
  `source_type` enum('property','contact','agent','deal','static','computed','manual') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'manual',
  `source_column` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_contact_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=648 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `docuperfect_pack_attachments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `docuperfect_pack_attachments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `pack_instance_id` bigint unsigned NOT NULL,
  `knowledge_document_id` bigint unsigned NOT NULL,
  `slot_label` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `docuperfect_pack_attachments_knowledge_document_id_foreign` (`knowledge_document_id`),
  KEY `docuperfect_pack_attachments_pack_instance_id_index` (`pack_instance_id`),
  CONSTRAINT `docuperfect_pack_attachments_knowledge_document_id_foreign` FOREIGN KEY (`knowledge_document_id`) REFERENCES `knowledge_documents` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `docuperfect_pack_branches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `docuperfect_pack_branches` (
  `pack_id` bigint unsigned NOT NULL,
  `branch_id` bigint unsigned NOT NULL,
  UNIQUE KEY `docuperfect_pack_branches_pack_id_branch_id_unique` (`pack_id`,`branch_id`),
  KEY `docuperfect_pack_branches_branch_id_foreign` (`branch_id`),
  CONSTRAINT `docuperfect_pack_branches_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE,
  CONSTRAINT `docuperfect_pack_branches_pack_id_foreign` FOREIGN KEY (`pack_id`) REFERENCES `docuperfect_packs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `docuperfect_pack_instance_values`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `docuperfect_pack_instance_values` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `pack_instance_id` bigint unsigned NOT NULL,
  `named_field_id` bigint unsigned NOT NULL,
  `value` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `piv_instance_field_unique` (`pack_instance_id`,`named_field_id`),
  KEY `docuperfect_pack_instance_values_named_field_id_foreign` (`named_field_id`),
  CONSTRAINT `docuperfect_pack_instance_values_named_field_id_foreign` FOREIGN KEY (`named_field_id`) REFERENCES `docuperfect_named_fields` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `docuperfect_pack_slots`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `docuperfect_pack_slots` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `pack_id` bigint unsigned NOT NULL,
  `sort_order` int NOT NULL DEFAULT '0',
  `label` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `slot_type` enum('required','selectable','attachment') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `template_id` bigint unsigned DEFAULT NULL,
  `document_type_id` bigint unsigned DEFAULT NULL,
  `knowledge_category_id` bigint unsigned DEFAULT NULL,
  `allow_multiple` tinyint(1) NOT NULL DEFAULT '0',
  `is_optional` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `docuperfect_pack_slots_pack_id_foreign` (`pack_id`),
  KEY `docuperfect_pack_slots_template_id_foreign` (`template_id`),
  KEY `docuperfect_pack_slots_document_type_id_foreign` (`document_type_id`),
  CONSTRAINT `docuperfect_pack_slots_document_type_id_foreign` FOREIGN KEY (`document_type_id`) REFERENCES `document_types` (`id`) ON DELETE SET NULL,
  CONSTRAINT `docuperfect_pack_slots_pack_id_foreign` FOREIGN KEY (`pack_id`) REFERENCES `docuperfect_packs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `docuperfect_pack_slots_template_id_foreign` FOREIGN KEY (`template_id`) REFERENCES `docuperfect_templates` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `docuperfect_pack_templates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `docuperfect_pack_templates` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `pack_id` bigint unsigned NOT NULL,
  `template_id` bigint unsigned NOT NULL,
  `sort_order` int NOT NULL DEFAULT '0',
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `docuperfect_pack_templates_pack_id_template_id_unique` (`pack_id`,`template_id`),
  KEY `docuperfect_pack_templates_template_id_foreign` (`template_id`),
  CONSTRAINT `docuperfect_pack_templates_pack_id_foreign` FOREIGN KEY (`pack_id`) REFERENCES `docuperfect_packs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `docuperfect_pack_templates_template_id_foreign` FOREIGN KEY (`template_id`) REFERENCES `docuperfect_templates` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `docuperfect_packs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `docuperfect_packs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned DEFAULT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `is_global` tinyint(1) NOT NULL DEFAULT '0',
  `creation_mode` enum('individual','linked') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'linked',
  `owner_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `docuperfect_packs_owner_id_foreign` (`owner_id`),
  KEY `docuperfect_packs_agency_id_index` (`agency_id`),
  CONSTRAINT `docuperfect_packs_owner_id_foreign` FOREIGN KEY (`owner_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `docuperfect_template_branches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `docuperfect_template_branches` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `template_id` bigint unsigned NOT NULL,
  `branch_id` bigint unsigned NOT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `docuperfect_template_branches_template_id_branch_id_unique` (`template_id`,`branch_id`),
  KEY `docuperfect_template_branches_branch_id_foreign` (`branch_id`),
  CONSTRAINT `docuperfect_template_branches_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE,
  CONSTRAINT `docuperfect_template_branches_template_id_foreign` FOREIGN KEY (`template_id`) REFERENCES `docuperfect_templates` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `docuperfect_template_signature_zones`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `docuperfect_template_signature_zones` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `template_id` bigint unsigned NOT NULL,
  `page_index` int NOT NULL,
  `x_position` decimal(8,4) NOT NULL,
  `y_position` decimal(8,4) NOT NULL,
  `width` decimal(8,4) NOT NULL DEFAULT '25.0000',
  `height` decimal(8,4) NOT NULL DEFAULT '6.0000',
  `type` enum('signature','initial') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'signature',
  `assigned_parties` json NOT NULL,
  `label` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `required` tinyint(1) NOT NULL DEFAULT '1',
  `sort_order` int NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `dtsz_template_page_index` (`template_id`,`page_index`),
  CONSTRAINT `docuperfect_template_signature_zones_template_id_foreign` FOREIGN KEY (`template_id`) REFERENCES `docuperfect_templates` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `docuperfect_templates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `docuperfect_templates` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned DEFAULT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `template_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'sales',
  `render_type` enum('pdf','web') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pdf',
  `compiled_serving` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'AT-177/WS6: serve from published compiled CDS instead of legacy merged_html',
  `compiled_family` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'the published compiled_templates family this cutover binds to',
  `blade_view` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `document_type_id` bigint unsigned DEFAULT NULL,
  `category` enum('sales','rentals') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `page_count` int NOT NULL DEFAULT '0',
  `fields_json` json DEFAULT NULL,
  `cds_json` json DEFAULT NULL,
  `field_mappings` json DEFAULT NULL,
  `allowed_delivery_modes` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'esign,wet_ink,download',
  `security_tier` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'enhanced',
  `editor_state` json DEFAULT NULL,
  `wizard_config` json DEFAULT NULL,
  `is_global` tinyint(1) NOT NULL DEFAULT '0',
  `is_esign` tinyint(1) NOT NULL DEFAULT '0',
  `party_mode` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'shared',
  `signing_parties` json DEFAULT NULL,
  `insertable_blocks` json DEFAULT NULL,
  `header_display` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'first_page',
  `owner_id` bigint unsigned DEFAULT NULL,
  `archived_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `sections` json DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `docuperfect_templates_owner_id_foreign` (`owner_id`),
  KEY `docuperfect_templates_document_type_id_foreign` (`document_type_id`),
  KEY `docuperfect_templates_agency_id_index` (`agency_id`),
  CONSTRAINT `docuperfect_templates_document_type_id_foreign` FOREIGN KEY (`document_type_id`) REFERENCES `document_types` (`id`) ON DELETE SET NULL,
  CONSTRAINT `docuperfect_templates_owner_id_foreign` FOREIGN KEY (`owner_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `domain_event_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `domain_event_log` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `event_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `trace_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `event_name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `agency_id` bigint unsigned DEFAULT NULL,
  `actor_user_id` bigint unsigned DEFAULT NULL,
  `subject_type` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `subject_id` bigint unsigned DEFAULT NULL,
  `payload_snapshot` json DEFAULT NULL,
  `context` json DEFAULT NULL,
  `occurred_at` datetime(6) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `on_behalf_of_user_id` bigint unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `dom_evt_event_id_unique` (`event_id`),
  KEY `dom_evt_trace_idx` (`trace_id`),
  KEY `dom_evt_name_idx` (`event_name`),
  KEY `dom_evt_agency_idx` (`agency_id`),
  KEY `dom_evt_actor_idx` (`actor_user_id`),
  KEY `dom_evt_subject_idx` (`subject_type`,`subject_id`),
  KEY `dom_evt_occurred_idx` (`occurred_at`),
  KEY `del_obo_fk` (`on_behalf_of_user_id`),
  CONSTRAINT `del_obo_fk` FOREIGN KEY (`on_behalf_of_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=183 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ellie_reference_chunks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ellie_reference_chunks` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `source_id` bigint unsigned NOT NULL,
  `chunk_index` smallint unsigned NOT NULL,
  `content` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `embedding` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `has_embedding` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ellie_reference_chunks_source_id_chunk_index_index` (`source_id`,`chunk_index`),
  KEY `ellie_reference_chunks_has_embedding_index` (`has_embedding`),
  CONSTRAINT `ellie_reference_chunks_source_id_foreign` FOREIGN KEY (`source_id`) REFERENCES `ellie_reference_sources` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ellie_reference_sources`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ellie_reference_sources` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `url` varchar(2048) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `added_by_user_id` bigint unsigned NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `last_fetched_at` timestamp NULL DEFAULT NULL,
  `last_fetch_status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `fetch_error` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `content_hash` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ellie_reference_sources_added_by_user_id_foreign` (`added_by_user_id`),
  KEY `ellie_reference_sources_is_active_index` (`is_active`),
  KEY `ellie_reference_sources_last_fetch_status_index` (`last_fetch_status`),
  CONSTRAINT `ellie_reference_sources_added_by_user_id_foreign` FOREIGN KEY (`added_by_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `employee_screening_checks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `employee_screening_checks` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `employee_screening_id` bigint unsigned NOT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `check_type` enum('employment_history_verified','qualification_verified','references_checked','ppra_ffc_verified','criminal_record_check','credit_check','id_verification','address_verification','tfs_screening','previous_aml_role_review','high_risk_association_check') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `result` enum('clear','concerns','fail','not_applicable','pending') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `checked_on` date DEFAULT NULL,
  `checked_by` bigint unsigned DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `reference_number` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `supporting_document_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `employee_screening_checks_checked_by_foreign` (`checked_by`),
  KEY `employee_screening_checks_supporting_document_id_foreign` (`supporting_document_id`),
  KEY `employee_screening_checks_employee_screening_id_check_type_index` (`employee_screening_id`,`check_type`),
  KEY `employee_screening_checks_agency_id_idx` (`agency_id`),
  CONSTRAINT `employee_screening_checks_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `employee_screening_checks_checked_by_foreign` FOREIGN KEY (`checked_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `employee_screening_checks_employee_screening_id_foreign` FOREIGN KEY (`employee_screening_id`) REFERENCES `employee_screenings` (`id`) ON DELETE CASCADE,
  CONSTRAINT `employee_screening_checks_supporting_document_id_foreign` FOREIGN KEY (`supporting_document_id`) REFERENCES `user_documents` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `employee_screenings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `employee_screenings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `branch_id` bigint unsigned DEFAULT NULL,
  `user_id` bigint unsigned NOT NULL,
  `screening_type` enum('pre_employment','periodic','tfs_list_update','triggered') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'periodic',
  `risk_tier` enum('high','medium','low') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'medium',
  `status` enum('in_progress','completed','flagged','cancelled') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'in_progress',
  `initiated_on` date NOT NULL,
  `completed_on` date DEFAULT NULL,
  `next_due_on` date DEFAULT NULL,
  `initiated_by` bigint unsigned DEFAULT NULL,
  `completed_by` bigint unsigned DEFAULT NULL,
  `overall_result` enum('pass','concerns_flagged','fail') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `summary_notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `employee_screenings_user_id_foreign` (`user_id`),
  KEY `employee_screenings_initiated_by_foreign` (`initiated_by`),
  KEY `employee_screenings_completed_by_foreign` (`completed_by`),
  KEY `employee_screenings_agency_id_user_id_status_index` (`agency_id`,`user_id`,`status`),
  KEY `employee_screenings_status_next_due_on_index` (`status`,`next_due_on`),
  KEY `employee_screenings_branch_id_foreign` (`branch_id`),
  KEY `employee_screenings_agency_branch_idx` (`agency_id`,`branch_id`),
  CONSTRAINT `employee_screenings_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `employee_screenings_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `employee_screenings_completed_by_foreign` FOREIGN KEY (`completed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `employee_screenings_initiated_by_foreign` FOREIGN KEY (`initiated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `employee_screenings_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `esign_consent_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `esign_consent_log` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `flow_id` bigint unsigned DEFAULT NULL,
  `document_id` bigint unsigned DEFAULT NULL,
  `signature_request_id` bigint unsigned DEFAULT NULL,
  `signing_party_id` bigint unsigned DEFAULT NULL,
  `contact_id` bigint unsigned DEFAULT NULL,
  `id_number_entered` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `id_verified` tinyint(1) NOT NULL DEFAULT '0',
  `consent_text` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `consent_accepted_at` timestamp NOT NULL,
  `ip_address` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_agent` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `device_info` json DEFAULT NULL,
  `document_hash` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `esign_consent_log_flow_id_index` (`flow_id`),
  KEY `esign_consent_log_contact_id_index` (`contact_id`),
  KEY `esign_consent_log_signing_party_id_index` (`signing_party_id`),
  KEY `esign_consent_log_signature_request_id_index` (`signature_request_id`),
  KEY `esign_consent_log_document_id_index` (`document_id`),
  CONSTRAINT `esign_consent_log_contact_id_foreign` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`),
  CONSTRAINT `esign_consent_log_document_id_foreign` FOREIGN KEY (`document_id`) REFERENCES `docuperfect_documents` (`id`) ON DELETE SET NULL,
  CONSTRAINT `esign_consent_log_flow_id_foreign` FOREIGN KEY (`flow_id`) REFERENCES `flows` (`id`),
  CONSTRAINT `esign_consent_log_signature_request_id_foreign` FOREIGN KEY (`signature_request_id`) REFERENCES `signature_requests` (`id`) ON DELETE SET NULL,
  CONSTRAINT `esign_consent_log_signing_party_id_foreign` FOREIGN KEY (`signing_party_id`) REFERENCES `esign_signing_parties` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `esign_recipient_presets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `esign_recipient_presets` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned DEFAULT NULL,
  `name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `applies_to` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'entity',
  `is_system` tinyint(1) NOT NULL DEFAULT '0',
  `phrasing_template` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `signature_caption` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `proxy_phrasing_template` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `proxy_signature_caption` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `is_default` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `esign_recipient_presets_agency_id_is_default_index` (`agency_id`,`is_default`),
  KEY `esign_recipient_presets_is_system_index` (`is_system`),
  CONSTRAINT `esign_recipient_presets_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `esign_signing_parties`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `esign_signing_parties` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `flow_id` bigint unsigned NOT NULL,
  `contact_id` bigint unsigned NOT NULL,
  `role` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `display_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `id_number` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `signing_order` smallint unsigned NOT NULL DEFAULT '1',
  `status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `consented_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `declined_at` timestamp NULL DEFAULT NULL,
  `decline_reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `proxy_for_party_id` bigint unsigned DEFAULT NULL,
  `proxy_poa_reference` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `esign_signing_parties_proxy_for_party_id_foreign` (`proxy_for_party_id`),
  KEY `esign_signing_parties_flow_id_status_index` (`flow_id`,`status`),
  KEY `esign_signing_parties_contact_id_index` (`contact_id`),
  CONSTRAINT `esign_signing_parties_contact_id_foreign` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`),
  CONSTRAINT `esign_signing_parties_flow_id_foreign` FOREIGN KEY (`flow_id`) REFERENCES `flows` (`id`) ON DELETE CASCADE,
  CONSTRAINT `esign_signing_parties_proxy_for_party_id_foreign` FOREIGN KEY (`proxy_for_party_id`) REFERENCES `esign_signing_parties` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `evaluation_certificates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `evaluation_certificates` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `property_id` bigint unsigned DEFAULT NULL,
  `contact_id` bigint unsigned DEFAULT NULL,
  `address` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `property_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `analysis_date` date DEFAULT NULL,
  `estimated_market_value` bigint unsigned DEFAULT NULL,
  `bedrooms` tinyint unsigned DEFAULT NULL,
  `bathrooms` tinyint unsigned DEFAULT NULL,
  `parking` tinyint unsigned DEFAULT NULL,
  `key_features` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `created_by_user_id` bigint unsigned NOT NULL,
  `signed_by_user_id` bigint unsigned DEFAULT NULL,
  `authorised_by_user_id` bigint unsigned DEFAULT NULL,
  `reject_note` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `candidate_signature_image` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `signed_pdf_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `evaluation_certificates_property_id_foreign` (`property_id`),
  KEY `evaluation_certificates_contact_id_foreign` (`contact_id`),
  KEY `evaluation_certificates_created_by_user_id_foreign` (`created_by_user_id`),
  KEY `evaluation_certificates_signed_by_user_id_foreign` (`signed_by_user_id`),
  KEY `evaluation_certificates_authorised_by_user_id_foreign` (`authorised_by_user_id`),
  KEY `evaluation_certificates_agency_id_index` (`agency_id`),
  CONSTRAINT `evaluation_certificates_authorised_by_user_id_foreign` FOREIGN KEY (`authorised_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `evaluation_certificates_contact_id_foreign` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `evaluation_certificates_created_by_user_id_foreign` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `evaluation_certificates_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE SET NULL,
  CONSTRAINT `evaluation_certificates_signed_by_user_id_foreign` FOREIGN KEY (`signed_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `failed_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `failed_jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `connection` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `queue` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `exception` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `fault_reports`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `fault_reports` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `type` enum('backend','frontend','manual') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `severity` enum('error','warning','info') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'error',
  `title` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `exception_class` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `file` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `line` int DEFAULT NULL,
  `trace` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `url` varchar(1000) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `method` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `user_agent` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `ip_address` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `request_data` json DEFAULT NULL,
  `screenshot_path` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('new','investigating','fixed','ignored') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'new',
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `resolved_by` bigint unsigned DEFAULT NULL,
  `resolved_at` timestamp NULL DEFAULT NULL,
  `occurrence_count` int NOT NULL DEFAULT '1',
  `first_seen_at` timestamp NULL DEFAULT NULL,
  `last_seen_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fault_reports_status_index` (`status`),
  KEY `fault_reports_type_index` (`type`),
  KEY `fault_reports_last_seen_at_index` (`last_seen_at`),
  KEY `fault_reports_dedup_index` (`exception_class`,`file`,`line`),
  KEY `fault_reports_user_id_foreign` (`user_id`),
  KEY `fault_reports_resolved_by_foreign` (`resolved_by`),
  KEY `fault_reports_agency_id_idx` (`agency_id`),
  CONSTRAINT `fault_reports_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fault_reports_resolved_by_foreign` FOREIGN KEY (`resolved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fault_reports_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `feedback_attachments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `feedback_attachments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `feedback_report_id` bigint unsigned NOT NULL,
  `filename` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `original_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `mime_type` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `size_bytes` int unsigned NOT NULL,
  `storage_path` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `feedback_attachments_feedback_report_id_foreign` (`feedback_report_id`),
  CONSTRAINT `feedback_attachments_feedback_report_id_foreign` FOREIGN KEY (`feedback_report_id`) REFERENCES `feedback_reports` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `feedback_reports`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `feedback_reports` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `type` enum('bug','enhancement','question','compliment','other') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `severity` enum('critical','major','minor') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `title` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `steps_to_reproduce` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `expected_behaviour` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `actual_behaviour` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `page_url` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `page_title` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `module_tag` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `browser` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `os` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `viewport_width` smallint unsigned DEFAULT NULL,
  `viewport_height` smallint unsigned DEFAULT NULL,
  `submitted_at` timestamp NOT NULL,
  `server_log_window_start` timestamp NULL DEFAULT NULL,
  `server_log_window_end` timestamp NULL DEFAULT NULL,
  `status` enum('new','reviewing','in_progress','fixed','wont_fix','duplicate','deferred') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'new',
  `assigned_to_user_id` bigint unsigned DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `reviewed_by_user_id` bigint unsigned DEFAULT NULL,
  `resolution_notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `resolved_at` timestamp NULL DEFAULT NULL,
  `resolved_by_user_id` bigint unsigned DEFAULT NULL,
  `related_commit` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `feedback_reports_user_id_foreign` (`user_id`),
  KEY `feedback_reports_assigned_to_user_id_foreign` (`assigned_to_user_id`),
  KEY `feedback_reports_reviewed_by_user_id_foreign` (`reviewed_by_user_id`),
  KEY `feedback_reports_resolved_by_user_id_foreign` (`resolved_by_user_id`),
  KEY `feedback_reports_agency_id_submitted_at_index` (`agency_id`,`submitted_at`),
  KEY `feedback_reports_status_index` (`status`),
  KEY `feedback_reports_module_tag_index` (`module_tag`),
  CONSTRAINT `feedback_reports_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `feedback_reports_assigned_to_user_id_foreign` FOREIGN KEY (`assigned_to_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `feedback_reports_resolved_by_user_id_foreign` FOREIGN KEY (`resolved_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `feedback_reports_reviewed_by_user_id_foreign` FOREIGN KEY (`reviewed_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `feedback_reports_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `fica_compliance_officers_deprecated_20260421`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `fica_compliance_officers_deprecated_20260421` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `assigned_by` bigint unsigned NOT NULL,
  `assigned_at` datetime NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fica_compliance_officers_user_id_unique` (`user_id`),
  KEY `fica_compliance_officers_assigned_by_foreign` (`assigned_by`),
  CONSTRAINT `fica_compliance_officers_assigned_by_foreign` FOREIGN KEY (`assigned_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fica_compliance_officers_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `fica_documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `fica_documents` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `fica_submission_id` bigint unsigned NOT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `document_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_size` int unsigned NOT NULL DEFAULT '0',
  `mime_type` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('uploaded','accepted','rejected') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'uploaded',
  `rejection_reason` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `uploaded_at` datetime DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `uploaded_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fica_documents_fica_submission_id_index` (`fica_submission_id`),
  KEY `fica_documents_document_type_index` (`document_type`),
  KEY `fica_documents_uploaded_by_foreign` (`uploaded_by`),
  KEY `fica_documents_agency_id_idx` (`agency_id`),
  CONSTRAINT `fica_documents_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fica_documents_fica_submission_id_foreign` FOREIGN KEY (`fica_submission_id`) REFERENCES `fica_submissions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fica_documents_uploaded_by_foreign` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `fica_officer_appointments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `fica_officer_appointments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `branch_id` bigint unsigned DEFAULT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `role` enum('primary_compliance_officer','mlro') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `full_name` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `id_number` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cell` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `title` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'FICA Compliance Officer',
  `appointed_on` date NOT NULL,
  `ended_on` date DEFAULT NULL,
  `appointed_by` bigint unsigned DEFAULT NULL,
  `appointment_letter_path` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fica_officer_appointments_branch_id_foreign` (`branch_id`),
  KEY `fica_officer_appointments_appointed_by_foreign` (`appointed_by`),
  KEY `fica_officer_appointments_agency_id_role_ended_on_index` (`agency_id`,`role`,`ended_on`),
  KEY `fica_officer_appointments_user_id_ended_on_index` (`user_id`,`ended_on`),
  CONSTRAINT `fica_officer_appointments_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fica_officer_appointments_appointed_by_foreign` FOREIGN KEY (`appointed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fica_officer_appointments_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fica_officer_appointments_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `fica_resend_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `fica_resend_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `fica_submission_id` bigint unsigned NOT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `resent_by` bigint unsigned NOT NULL,
  `resent_at` timestamp NOT NULL,
  `reason_code` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fica_resend_logs_resent_by_foreign` (`resent_by`),
  KEY `fica_resend_logs_fica_submission_id_resent_at_index` (`fica_submission_id`,`resent_at`),
  KEY `fica_resend_logs_agency_id_idx` (`agency_id`),
  CONSTRAINT `fica_resend_logs_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fica_resend_logs_fica_submission_id_foreign` FOREIGN KEY (`fica_submission_id`) REFERENCES `fica_submissions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fica_resend_logs_resent_by_foreign` FOREIGN KEY (`resent_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `fica_status_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `fica_status_history` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `fica_submission_id` bigint unsigned NOT NULL,
  `from_status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `to_status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `action` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `actor_user_id` bigint unsigned DEFAULT NULL,
  `actor_tier` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `note` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `meta` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fica_hist_submission_idx` (`agency_id`,`fica_submission_id`,`id`),
  KEY `fica_hist_action_idx` (`agency_id`,`action`),
  KEY `fica_status_history_fica_submission_id_foreign` (`fica_submission_id`),
  KEY `fica_status_history_actor_user_id_foreign` (`actor_user_id`),
  CONSTRAINT `fica_status_history_actor_user_id_foreign` FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fica_status_history_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fica_status_history_fica_submission_id_foreign` FOREIGN KEY (`fica_submission_id`) REFERENCES `fica_submissions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `fica_submission_documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `fica_submission_documents` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `fica_submission_id` bigint unsigned NOT NULL,
  `document_id` bigint unsigned NOT NULL,
  `document_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'supporting',
  `linked_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fica_sub_doc_unique` (`fica_submission_id`,`document_id`),
  KEY `fica_submission_documents_linked_by_foreign` (`linked_by`),
  KEY `fica_submission_documents_document_id_index` (`document_id`),
  CONSTRAINT `fica_submission_documents_document_id_foreign` FOREIGN KEY (`document_id`) REFERENCES `documents` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fica_submission_documents_fica_submission_id_foreign` FOREIGN KEY (`fica_submission_id`) REFERENCES `fica_submissions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fica_submission_documents_linked_by_foreign` FOREIGN KEY (`linked_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `fica_submissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `fica_submissions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `contact_id` bigint unsigned DEFAULT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `branch_id` bigint unsigned DEFAULT NULL,
  `requested_by` bigint unsigned NOT NULL,
  `token` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `token_expires_at` datetime DEFAULT NULL,
  `entity_type` enum('natural','company','trust','partnership') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'natural',
  `form_data` json DEFAULT NULL,
  `status` enum('draft','submitted','under_review','agent_approved','corrections_requested','approved','rejected','cancelled','referred_to_co') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `intake_type` enum('online','wet_ink') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'online',
  `wet_ink_received_date` date DEFAULT NULL,
  `wet_ink_confirmed_by` bigint unsigned DEFAULT NULL,
  `risk_rating` tinyint DEFAULT NULL,
  `verification_method` json DEFAULT NULL,
  `verified_by` bigint unsigned DEFAULT NULL,
  `verified_at` datetime DEFAULT NULL,
  `fica_expires_at` date DEFAULT NULL,
  `reviewer_notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `agent_verified_by` bigint unsigned DEFAULT NULL,
  `agent_verified_at` datetime DEFAULT NULL,
  `agent_verification_data` json DEFAULT NULL,
  `agent_notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `co_verified_by` bigint unsigned DEFAULT NULL,
  `co_verified_at` datetime DEFAULT NULL,
  `co_verification_data` json DEFAULT NULL,
  `co_notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `referred_by` bigint unsigned DEFAULT NULL,
  `referred_at` timestamp NULL DEFAULT NULL,
  `referral_note` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `co_signature_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `pdf_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `signature_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `signed_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fica_submissions_token_unique` (`token`),
  KEY `fica_submissions_requested_by_foreign` (`requested_by`),
  KEY `fica_submissions_verified_by_foreign` (`verified_by`),
  KEY `fica_submissions_token_index` (`token`),
  KEY `fica_submissions_status_index` (`status`),
  KEY `fica_submissions_contact_id_index` (`contact_id`),
  KEY `fica_submissions_agent_verified_by_foreign` (`agent_verified_by`),
  KEY `fica_submissions_co_verified_by_foreign` (`co_verified_by`),
  KEY `fica_submissions_wet_ink_confirmed_by_foreign` (`wet_ink_confirmed_by`),
  KEY `fica_submissions_branch_id_foreign` (`branch_id`),
  KEY `fica_submissions_agency_branch_idx` (`agency_id`,`branch_id`),
  KEY `fica_submissions_fica_expires_at_index` (`fica_expires_at`),
  KEY `fica_submissions_referred_by_foreign` (`referred_by`),
  CONSTRAINT `fica_submissions_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fica_submissions_agent_verified_by_foreign` FOREIGN KEY (`agent_verified_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fica_submissions_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fica_submissions_co_verified_by_foreign` FOREIGN KEY (`co_verified_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fica_submissions_contact_id_foreign` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fica_submissions_referred_by_foreign` FOREIGN KEY (`referred_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fica_submissions_requested_by_foreign` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fica_submissions_verified_by_foreign` FOREIGN KEY (`verified_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fica_submissions_wet_ink_confirmed_by_foreign` FOREIGN KEY (`wet_ink_confirmed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `fica_tfs_screenings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `fica_tfs_screenings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `fica_submission_id` bigint unsigned NOT NULL,
  `agency_id` bigint unsigned DEFAULT NULL,
  `subject_kind` enum('individual','entity') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'individual',
  `screened_name` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `screened_name_normalised` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `screened_id_number` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `screened_id_normalised` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `screened_dob` date DEFAULT NULL,
  `outcome` enum('hit','review_required','passed','error') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'error',
  `auto_pass_trusted` tinyint(1) NOT NULL DEFAULT '0',
  `reason` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `import_id` bigint unsigned DEFAULT NULL,
  `list_fetched_at` datetime DEFAULT NULL,
  `match_count` int unsigned NOT NULL DEFAULT '0',
  `candidates` json DEFAULT NULL,
  `screened_by` bigint unsigned DEFAULT NULL,
  `screened_at` datetime NOT NULL,
  `decision` enum('pending','confirmed_hit','cleared_false_positive') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `decided_by` bigint unsigned DEFAULT NULL,
  `decided_at` datetime DEFAULT NULL,
  `decision_note` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fica_tfs_screenings_agency_id_foreign` (`agency_id`),
  KEY `fica_tfs_screenings_import_id_foreign` (`import_id`),
  KEY `fica_tfs_screenings_screened_by_foreign` (`screened_by`),
  KEY `fica_tfs_screenings_decided_by_foreign` (`decided_by`),
  KEY `fica_tfs_screenings_fica_submission_id_outcome_index` (`fica_submission_id`,`outcome`),
  KEY `fica_tfs_screenings_outcome_index` (`outcome`),
  CONSTRAINT `fica_tfs_screenings_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fica_tfs_screenings_decided_by_foreign` FOREIGN KEY (`decided_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fica_tfs_screenings_fica_submission_id_foreign` FOREIGN KEY (`fica_submission_id`) REFERENCES `fica_submissions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fica_tfs_screenings_import_id_foreign` FOREIGN KEY (`import_id`) REFERENCES `sanctions_list_imports` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fica_tfs_screenings_screened_by_foreign` FOREIGN KEY (`screened_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `finance_audit_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `finance_audit_items` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `audit_run_id` bigint unsigned NOT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `definition_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `entity_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `entity_id` bigint unsigned NOT NULL,
  `period` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `expected_numeric` decimal(18,6) DEFAULT NULL,
  `actual_numeric` decimal(18,6) DEFAULT NULL,
  `diff_numeric` decimal(18,6) DEFAULT NULL,
  `expected_json` json DEFAULT NULL,
  `actual_json` json DEFAULT NULL,
  `diff_json` json DEFAULT NULL,
  `severity` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'info' COMMENT 'info|warn|error',
  `message` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `finance_audit_items_audit_run_id_foreign` (`audit_run_id`),
  KEY `finance_audit_items_definition_key_index` (`definition_key`),
  KEY `finance_audit_items_entity_type_index` (`entity_type`),
  KEY `finance_audit_items_entity_id_index` (`entity_id`),
  KEY `finance_audit_items_agency_id_idx` (`agency_id`),
  CONSTRAINT `finance_audit_items_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `finance_audit_items_audit_run_id_foreign` FOREIGN KEY (`audit_run_id`) REFERENCES `finance_audit_runs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `finance_audit_runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `finance_audit_runs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `period` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'YYYY-MM',
  `scope` json DEFAULT NULL COMMENT 'e.g. {branch_id: 1, deal_ids: []}',
  `status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'running' COMMENT 'running|complete|failed',
  `engine_version` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'v0',
  `started_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `finished_at` timestamp NULL DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `finance_audit_runs_agency_id_idx` (`agency_id`),
  CONSTRAINT `finance_audit_runs_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `finance_computed_values`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `finance_computed_values` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `definition_id` bigint unsigned NOT NULL,
  `definition_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `definition_version` int unsigned NOT NULL,
  `entity_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `entity_id` bigint unsigned NOT NULL,
  `period` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'YYYY-MM',
  `value_numeric` decimal(18,6) DEFAULT NULL,
  `value_json` json DEFAULT NULL,
  `input_hash` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `engine_version` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'v0',
  `computed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `audit_run_id` bigint unsigned DEFAULT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fcv_agency_def_entity_period_unique` (`definition_id`,`agency_id`,`entity_type`,`entity_id`,`period`),
  KEY `finance_computed_values_definition_key_index` (`definition_key`),
  KEY `finance_computed_values_definition_version_index` (`definition_version`),
  KEY `finance_computed_values_entity_type_index` (`entity_type`),
  KEY `finance_computed_values_entity_id_index` (`entity_id`),
  KEY `finance_computed_values_audit_run_id_index` (`audit_run_id`),
  KEY `finance_computed_values_agency_id_idx` (`agency_id`),
  CONSTRAINT `finance_computed_values_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `finance_computed_values_definition_id_foreign` FOREIGN KEY (`definition_id`) REFERENCES `finance_definitions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `finance_definitions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `finance_definitions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'e.g. deal.total_commission_ex_vat',
  `version` int unsigned NOT NULL DEFAULT '1',
  `status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft' COMMENT 'draft|active|retired',
  `entity_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'e.g. deal',
  `value_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'money_ex_vat|money_inc_vat|percent|count|json',
  `expression` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `dependencies` json DEFAULT NULL,
  `rounding_scale` smallint unsigned NOT NULL DEFAULT '2',
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `finance_definitions_key_version_unique` (`key`,`version`),
  KEY `finance_definitions_key_status_index` (`key`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `flag_removal_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `flag_removal_requests` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `signature_template_id` bigint unsigned NOT NULL,
  `document_amendment_id` bigint unsigned NOT NULL,
  `clause_ref` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `requested_by_user_id` bigint unsigned NOT NULL,
  `requested_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `recipient_signing_party_id` bigint unsigned NOT NULL,
  `consent_token` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `consent_sent_at` timestamp NULL DEFAULT NULL,
  `consent_received_at` timestamp NULL DEFAULT NULL,
  `consent_ip_address` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `consent_user_agent` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `consent_signature_data` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `status` enum('pending','consented','rejected','expired','cancelled') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `expires_at` timestamp NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `flag_removal_requests_consent_token_unique` (`consent_token`),
  KEY `frr_tpl_status_idx` (`signature_template_id`,`status`),
  KEY `frr_amendment_idx` (`document_amendment_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `flows`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `flows` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `template_id` bigint unsigned DEFAULT NULL,
  `pack_id` bigint unsigned DEFAULT NULL,
  `pack_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `flow_sequence` int unsigned NOT NULL DEFAULT '0',
  `parent_flow_id` bigint unsigned DEFAULT NULL,
  `pack_status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_id` bigint unsigned NOT NULL,
  `property_id` bigint unsigned DEFAULT NULL,
  `contact_id` bigint unsigned DEFAULT NULL,
  `current_step` int unsigned NOT NULL DEFAULT '1',
  `step_data` json DEFAULT NULL,
  `status` enum('active','completed','abandoned','draft') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `flows_property_id_foreign` (`property_id`),
  KEY `flows_contact_id_foreign` (`contact_id`),
  KEY `flows_user_id_status_index` (`user_id`,`status`),
  KEY `flows_template_id_index` (`template_id`),
  KEY `flows_pack_id_flow_sequence_index` (`pack_id`,`flow_sequence`),
  KEY `flows_parent_flow_id_index` (`parent_flow_id`),
  CONSTRAINT `flows_contact_id_foreign` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `flows_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE SET NULL,
  CONSTRAINT `flows_template_id_foreign` FOREIGN KEY (`template_id`) REFERENCES `docuperfect_templates` (`id`) ON DELETE SET NULL,
  CONSTRAINT `flows_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `geocoding_cache`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `geocoding_cache` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `address_normalised` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `address_raw` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `latitude` decimal(10,7) DEFAULT NULL,
  `longitude` decimal(10,7) DEFAULT NULL,
  `confidence` enum('exact','street','suburb','town','failed') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'failed',
  `google_location_type` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source` enum('market_report','portal_capture','p24','google','nominatim','manual','cache') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'cache',
  `source_ref` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `resolved_address` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `municipality_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `suburb_normalised` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `failure_reason` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `hit_count` int unsigned NOT NULL DEFAULT '0',
  `last_hit_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `last_attempted_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `geocoding_cache_addr_unique` (`address_normalised`),
  KEY `geocoding_cache_latlng_idx` (`latitude`,`longitude`),
  KEY `geocoding_cache_confidence_index` (`confidence`),
  KEY `geocoding_cache_source_index` (`source`),
  KEY `geocoding_cache_suburb_normalised_index` (`suburb_normalised`),
  KEY `geocoding_cache_expires_idx` (`expires_at`)
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `geocoding_runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `geocoding_runs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `batch_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `entity_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `entity_id` bigint unsigned DEFAULT NULL,
  `address` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `result` enum('resolved','failed','cached') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'failed',
  `source` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `confidence` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `latency_ms` int unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `geocoding_runs_entity_type_entity_id_index` (`entity_type`,`entity_id`),
  KEY `geocoding_runs_result_index` (`result`),
  KEY `geocoding_runs_created_at_index` (`created_at`),
  KEY `geocoding_runs_batch_id_index` (`batch_id`)
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `holding_cost_data_points`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `holding_cost_data_points` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `presentation_version_id` bigint unsigned DEFAULT NULL,
  `property_id` bigint unsigned DEFAULT NULL,
  `tracked_property_id` bigint unsigned DEFAULT NULL,
  `component` enum('rates','levy','insurance','utilities','garden','pool','security','bond','opportunity_cost') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `monthly_value_zar` bigint unsigned NOT NULL,
  `scheme_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `suburb_normalised` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `municipality` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `property_type` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `title_type` enum('full_title','sectional_title','vacant_land','other') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `property_value_band` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source` enum('agent_override','cma_import','manual_capture','property_record') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_ref` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'e.g. property_id, market_report_id, presentation_id',
  `entered_by_user_id` bigint unsigned DEFAULT NULL,
  `is_excluded` tinyint(1) NOT NULL DEFAULT '0',
  `excluded_by_user_id` bigint unsigned DEFAULT NULL,
  `excluded_at` timestamp NULL DEFAULT NULL,
  `exclusion_reason` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `holding_cost_data_points_presentation_version_id_foreign` (`presentation_version_id`),
  KEY `holding_cost_data_points_entered_by_user_id_foreign` (`entered_by_user_id`),
  KEY `holding_cost_data_points_excluded_by_user_id_foreign` (`excluded_by_user_id`),
  KEY `idx_hcdp_levy_lookup` (`agency_id`,`component`,`scheme_name`),
  KEY `idx_hcdp_suburb_type_lookup` (`agency_id`,`component`,`suburb_normalised`,`property_type`),
  KEY `idx_hcdp_muni_lookup` (`agency_id`,`component`,`municipality`),
  KEY `idx_hcdp_override_lookup` (`agency_id`,`presentation_version_id`,`component`,`source`),
  KEY `holding_cost_data_points_property_id_index` (`property_id`),
  KEY `holding_cost_data_points_tracked_property_id_index` (`tracked_property_id`),
  KEY `holding_cost_data_points_component_index` (`component`),
  KEY `holding_cost_data_points_scheme_name_index` (`scheme_name`),
  KEY `holding_cost_data_points_suburb_normalised_index` (`suburb_normalised`),
  KEY `holding_cost_data_points_municipality_index` (`municipality`),
  KEY `holding_cost_data_points_property_value_band_index` (`property_value_band`),
  KEY `holding_cost_data_points_source_index` (`source`),
  KEY `holding_cost_data_points_is_excluded_index` (`is_excluded`),
  CONSTRAINT `holding_cost_data_points_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `holding_cost_data_points_entered_by_user_id_foreign` FOREIGN KEY (`entered_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `holding_cost_data_points_excluded_by_user_id_foreign` FOREIGN KEY (`excluded_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `holding_cost_data_points_presentation_version_id_foreign` FOREIGN KEY (`presentation_version_id`) REFERENCES `presentation_versions` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `impersonation_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `impersonation_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `admin_user_id` bigint unsigned NOT NULL,
  `target_user_id` bigint unsigned NOT NULL,
  `action` enum('start','stop') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `ip_address` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `impersonation_logs_target_user_id_created_at_index` (`target_user_id`,`created_at`),
  KEY `impersonation_logs_admin_user_id_created_at_index` (`admin_user_id`,`created_at`),
  CONSTRAINT `impersonation_logs_admin_user_id_foreign` FOREIGN KEY (`admin_user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `impersonation_logs_target_user_id_foreign` FOREIGN KEY (`target_user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `information_officer_appointments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `information_officer_appointments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `branch_id` bigint unsigned DEFAULT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `role` enum('primary_information_officer','deputy_information_officer') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `full_name` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `id_number` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cell` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `title` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Information Officer',
  `appointed_on` date NOT NULL,
  `ended_on` date DEFAULT NULL,
  `appointed_by` bigint unsigned DEFAULT NULL,
  `appointment_letter_path` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `information_officer_appointments_branch_id_foreign` (`branch_id`),
  KEY `information_officer_appointments_appointed_by_foreign` (`appointed_by`),
  KEY `information_officer_appointments_agency_id_role_ended_on_index` (`agency_id`,`role`,`ended_on`),
  KEY `information_officer_appointments_user_id_ended_on_index` (`user_id`,`ended_on`),
  CONSTRAINT `information_officer_appointments_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `information_officer_appointments_appointed_by_foreign` FOREIGN KEY (`appointed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `information_officer_appointments_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `information_officer_appointments_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `job_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `job_batches` (
  `id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `total_jobs` int NOT NULL,
  `pending_jobs` int NOT NULL,
  `failed_jobs` int NOT NULL,
  `failed_job_ids` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `options` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `cancelled_at` int DEFAULT NULL,
  `created_at` int NOT NULL,
  `finished_at` int DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `queue` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `attempts` tinyint unsigned NOT NULL,
  `reserved_at` int unsigned DEFAULT NULL,
  `available_at` int unsigned NOT NULL,
  `created_at` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `jobs_queue_index` (`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `knowledge_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `knowledge_categories` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `icon` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sort_order` int NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `knowledge_categories_slug_unique` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `knowledge_chunks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `knowledge_chunks` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `document_id` bigint unsigned NOT NULL,
  `chunk_index` int unsigned NOT NULL,
  `content` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `section_title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `page_number` int unsigned DEFAULT NULL,
  `char_count` int unsigned NOT NULL DEFAULT '0',
  `word_count` int unsigned NOT NULL DEFAULT '0',
  `metadata` json DEFAULT NULL,
  `embedding` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `has_embedding` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `knowledge_chunks_document_id_chunk_index_index` (`document_id`,`chunk_index`),
  KEY `knowledge_chunks_has_embedding_index` (`has_embedding`),
  CONSTRAINT `knowledge_chunks_document_id_foreign` FOREIGN KEY (`document_id`) REFERENCES `knowledge_documents` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `knowledge_documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `knowledge_documents` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned DEFAULT NULL,
  `category_id` bigint unsigned NOT NULL,
  `uploaded_by` bigint unsigned NOT NULL,
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `file_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_type` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_size` int unsigned NOT NULL DEFAULT '0',
  `chunk_count` int unsigned NOT NULL DEFAULT '0',
  `page_count` int unsigned DEFAULT NULL,
  `status` enum('processing','ready','error') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'processing',
  `error_message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `is_ellie_enabled` tinyint(1) NOT NULL DEFAULT '1',
  `version` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `effective_date` date DEFAULT NULL,
  `expiry_date` date DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `knowledge_documents_uploaded_by_foreign` (`uploaded_by`),
  KEY `knowledge_documents_category_id_is_active_index` (`category_id`,`is_active`),
  KEY `knowledge_documents_status_index` (`status`),
  KEY `knowledge_documents_agency_id_index` (`agency_id`),
  CONSTRAINT `knowledge_documents_category_id_foreign` FOREIGN KEY (`category_id`) REFERENCES `knowledge_categories` (`id`) ON DELETE CASCADE,
  CONSTRAINT `knowledge_documents_uploaded_by_foreign` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `lease_escalations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `lease_escalations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `lease_id` bigint unsigned NOT NULL,
  `effective_date` date NOT NULL,
  `previous_rental_amount` decimal(12,2) NOT NULL,
  `new_rental_amount` decimal(12,2) NOT NULL,
  `escalation_rate_percent` decimal(6,2) NOT NULL,
  `note` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by_user_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `lease_escalations_created_by_user_id_foreign` (`created_by_user_id`),
  KEY `lease_escalations_lease_id_effective_date_index` (`lease_id`,`effective_date`),
  CONSTRAINT `lease_escalations_created_by_user_id_foreign` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `lease_escalations_lease_id_foreign` FOREIGN KEY (`lease_id`) REFERENCES `leases` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `lease_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `lease_events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `lease_id` bigint unsigned NOT NULL,
  `event_type` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `actor_user_id` bigint unsigned DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `occurred_at` timestamp NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `lease_events_actor_user_id_foreign` (`actor_user_id`),
  KEY `lease_events_lease_id_occurred_at_index` (`lease_id`,`occurred_at`),
  CONSTRAINT `lease_events_actor_user_id_foreign` FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `lease_events_lease_id_foreign` FOREIGN KEY (`lease_id`) REFERENCES `leases` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `lease_records`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `lease_records` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `document_id` bigint unsigned NOT NULL,
  `signature_template_id` bigint unsigned NOT NULL,
  `property_id` bigint unsigned DEFAULT NULL,
  `property_address` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `tenant_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `tenant_email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `landlord_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `landlord_email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `rental_amount` decimal(12,2) NOT NULL,
  `lease_start_date` date NOT NULL,
  `lease_end_date` date NOT NULL,
  `status` enum('active','expiring_soon','expired','renewed','terminated') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `previous_lease_id` bigint unsigned DEFAULT NULL,
  `renewed_lease_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `lease_records_previous_lease_id_foreign` (`previous_lease_id`),
  KEY `lease_records_renewed_lease_id_foreign` (`renewed_lease_id`),
  KEY `lease_records_status_lease_end_date_index` (`status`,`lease_end_date`),
  KEY `lease_records_document_id_index` (`document_id`),
  KEY `lease_records_signature_template_id_index` (`signature_template_id`),
  CONSTRAINT `lease_records_document_id_foreign` FOREIGN KEY (`document_id`) REFERENCES `docuperfect_documents` (`id`) ON DELETE CASCADE,
  CONSTRAINT `lease_records_previous_lease_id_foreign` FOREIGN KEY (`previous_lease_id`) REFERENCES `lease_records` (`id`) ON DELETE SET NULL,
  CONSTRAINT `lease_records_renewed_lease_id_foreign` FOREIGN KEY (`renewed_lease_id`) REFERENCES `lease_records` (`id`) ON DELETE SET NULL,
  CONSTRAINT `lease_records_signature_template_id_foreign` FOREIGN KEY (`signature_template_id`) REFERENCES `signature_templates` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `lease_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `lease_settings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `expiry_notice_window_days` smallint unsigned DEFAULT NULL,
  `show_lease_type_field` tinyint(1) NOT NULL DEFAULT '0',
  `default_deposit_months` decimal(4,2) DEFAULT NULL,
  `tenant_notice_period_days` int DEFAULT NULL,
  `auto_readvertise_on_notice` tinyint(1) NOT NULL DEFAULT '1',
  `auto_restore_status_on_lease_ended` tinyint(1) NOT NULL DEFAULT '1',
  `auto_restore_status_on_lease_cancelled` tinyint(1) NOT NULL DEFAULT '1',
  `default_pre_let_status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `active_rental_statuses` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `lease_settings_agency_id_unique` (`agency_id`),
  CONSTRAINT `lease_settings_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `lease_tenants`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `lease_tenants` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `lease_id` bigint unsigned NOT NULL,
  `contact_id` bigint unsigned NOT NULL,
  `is_primary` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `lease_tenants_lease_id_contact_id_unique` (`lease_id`,`contact_id`),
  KEY `lease_tenants_contact_id_foreign` (`contact_id`),
  CONSTRAINT `lease_tenants_contact_id_foreign` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `lease_tenants_lease_id_foreign` FOREIGN KEY (`lease_id`) REFERENCES `leases` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=21 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `leases`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `leases` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `branch_id` bigint unsigned DEFAULT NULL,
  `property_id` bigint unsigned NOT NULL,
  `status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `rental_amount` decimal(12,2) NOT NULL,
  `deposit_amount` decimal(12,2) DEFAULT NULL,
  `start_date` date NOT NULL,
  `end_date` date DEFAULT NULL,
  `is_month_to_month` tinyint(1) NOT NULL DEFAULT '0',
  `notice_date` date DEFAULT NULL,
  `notice_given_by` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notice_note` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `move_out_date` date DEFAULT NULL,
  `notice_outcome` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `lease_type` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'manual',
  `rental_application_id` bigint unsigned DEFAULT NULL,
  `source_document_id` bigint unsigned DEFAULT NULL,
  `migrated_from_table` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `migrated_from_id` bigint unsigned DEFAULT NULL,
  `migrated_escalation_percent` decimal(6,2) DEFAULT NULL,
  `migrated_next_escalation_date` date DEFAULT NULL,
  `migrated_opening_arrears` decimal(12,2) DEFAULT NULL,
  `migrated_last_inspection_date` date DEFAULT NULL,
  `previous_lease_id` bigint unsigned DEFAULT NULL,
  `renewed_lease_id` bigint unsigned DEFAULT NULL,
  `renewal_draft_flow_id` bigint unsigned DEFAULT NULL,
  `created_by_user_id` bigint unsigned DEFAULT NULL,
  `cancelled_at` timestamp NULL DEFAULT NULL,
  `cancelled_by_user_id` bigint unsigned DEFAULT NULL,
  `cancel_reason` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `leases_branch_id_foreign` (`branch_id`),
  KEY `leases_property_id_foreign` (`property_id`),
  KEY `leases_rental_application_id_foreign` (`rental_application_id`),
  KEY `leases_previous_lease_id_foreign` (`previous_lease_id`),
  KEY `leases_renewed_lease_id_foreign` (`renewed_lease_id`),
  KEY `leases_created_by_user_id_foreign` (`created_by_user_id`),
  KEY `leases_cancelled_by_user_id_foreign` (`cancelled_by_user_id`),
  KEY `leases_agency_id_property_id_status_index` (`agency_id`,`property_id`,`status`),
  CONSTRAINT `leases_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `leases_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `leases_cancelled_by_user_id_foreign` FOREIGN KEY (`cancelled_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `leases_created_by_user_id_foreign` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `leases_previous_lease_id_foreign` FOREIGN KEY (`previous_lease_id`) REFERENCES `leases` (`id`) ON DELETE SET NULL,
  CONSTRAINT `leases_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE,
  CONSTRAINT `leases_renewed_lease_id_foreign` FOREIGN KEY (`renewed_lease_id`) REFERENCES `leases` (`id`) ON DELETE SET NULL,
  CONSTRAINT `leases_rental_application_id_foreign` FOREIGN KEY (`rental_application_id`) REFERENCES `rental_applications` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=21 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `leave_application_documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `leave_application_documents` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `leave_application_id` bigint unsigned NOT NULL,
  `document_id` bigint unsigned NOT NULL,
  `document_role` enum('medical_certificate','supporting','signed_application_form','other') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `uploaded_by_user_id` bigint unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `leave_application_documents_leave_application_id_foreign` (`leave_application_id`),
  KEY `leave_application_documents_document_id_foreign` (`document_id`),
  KEY `leave_application_documents_uploaded_by_user_id_foreign` (`uploaded_by_user_id`),
  CONSTRAINT `leave_application_documents_document_id_foreign` FOREIGN KEY (`document_id`) REFERENCES `documents` (`id`) ON DELETE CASCADE,
  CONSTRAINT `leave_application_documents_leave_application_id_foreign` FOREIGN KEY (`leave_application_id`) REFERENCES `leave_applications` (`id`) ON DELETE CASCADE,
  CONSTRAINT `leave_application_documents_uploaded_by_user_id_foreign` FOREIGN KEY (`uploaded_by_user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `leave_applications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `leave_applications` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `branch_id` bigint unsigned DEFAULT NULL,
  `payroll_employee_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `leave_type_id` bigint unsigned NOT NULL,
  `application_number` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `is_half_day` tinyint(1) NOT NULL DEFAULT '0',
  `half_day_period` enum('morning','afternoon') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `working_days_requested` decimal(5,2) NOT NULL,
  `calendar_days_requested` smallint unsigned NOT NULL,
  `reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `status` enum('draft','submitted','approved','rejected','cancelled','taken','no_show') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'submitted',
  `submitted_at` timestamp NULL DEFAULT NULL,
  `decided_at` timestamp NULL DEFAULT NULL,
  `decided_by_user_id` bigint unsigned DEFAULT NULL,
  `decided_by_role` enum('branch_manager','admin','owner') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `decision_reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `taken_at` timestamp NULL DEFAULT NULL,
  `cancelled_at` timestamp NULL DEFAULT NULL,
  `cancelled_by_user_id` bigint unsigned DEFAULT NULL,
  `cancellation_reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `payslip_id` bigint unsigned DEFAULT NULL,
  `affects_payroll` tinyint(1) NOT NULL DEFAULT '0',
  `payroll_impact_amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `leave_applications_application_number_unique` (`application_number`),
  KEY `leave_applications_branch_id_foreign` (`branch_id`),
  KEY `leave_applications_payroll_employee_id_foreign` (`payroll_employee_id`),
  KEY `leave_applications_leave_type_id_foreign` (`leave_type_id`),
  KEY `leave_applications_decided_by_user_id_foreign` (`decided_by_user_id`),
  KEY `leave_applications_cancelled_by_user_id_foreign` (`cancelled_by_user_id`),
  KEY `leave_applications_agency_id_status_index` (`agency_id`,`status`),
  KEY `leave_applications_agency_id_branch_id_status_index` (`agency_id`,`branch_id`,`status`),
  KEY `leave_applications_user_id_status_index` (`user_id`,`status`),
  KEY `leave_applications_start_date_end_date_index` (`start_date`,`end_date`),
  CONSTRAINT `leave_applications_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `leave_applications_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `leave_applications_cancelled_by_user_id_foreign` FOREIGN KEY (`cancelled_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `leave_applications_decided_by_user_id_foreign` FOREIGN KEY (`decided_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `leave_applications_leave_type_id_foreign` FOREIGN KEY (`leave_type_id`) REFERENCES `leave_types` (`id`),
  CONSTRAINT `leave_applications_payroll_employee_id_foreign` FOREIGN KEY (`payroll_employee_id`) REFERENCES `payroll_employees` (`id`),
  CONSTRAINT `leave_applications_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `leave_entitlements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `leave_entitlements` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `branch_id` bigint unsigned DEFAULT NULL,
  `payroll_employee_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `leave_type_id` bigint unsigned NOT NULL,
  `cycle_start_date` date NOT NULL,
  `cycle_end_date` date NOT NULL,
  `entitlement_days` decimal(5,2) NOT NULL DEFAULT '0.00',
  `accrued_days` decimal(5,2) NOT NULL DEFAULT '0.00',
  `carryover_from_previous_cycle` decimal(5,2) NOT NULL DEFAULT '0.00',
  `taken_days` decimal(5,2) NOT NULL DEFAULT '0.00',
  `pending_days` decimal(5,2) NOT NULL DEFAULT '0.00',
  `available_days` decimal(5,2) NOT NULL DEFAULT '0.00' COMMENT 'Derived: accrued + carryover - taken - pending. Updated by LeaveBalanceService.',
  `last_accrual_run_at` timestamp NULL DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `leave_entitlements_employee_type_cycle_unique` (`payroll_employee_id`,`leave_type_id`,`cycle_start_date`),
  KEY `leave_entitlements_branch_id_foreign` (`branch_id`),
  KEY `leave_entitlements_user_id_foreign` (`user_id`),
  KEY `leave_entitlements_leave_type_id_foreign` (`leave_type_id`),
  KEY `leave_entitlements_agency_id_user_id_index` (`agency_id`,`user_id`),
  KEY `leave_entitlements_agency_id_branch_id_index` (`agency_id`,`branch_id`),
  KEY `leave_entitlements_cycle_end_date_index` (`cycle_end_date`),
  CONSTRAINT `leave_entitlements_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `leave_entitlements_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `leave_entitlements_leave_type_id_foreign` FOREIGN KEY (`leave_type_id`) REFERENCES `leave_types` (`id`) ON DELETE CASCADE,
  CONSTRAINT `leave_entitlements_payroll_employee_id_foreign` FOREIGN KEY (`payroll_employee_id`) REFERENCES `payroll_employees` (`id`) ON DELETE CASCADE,
  CONSTRAINT `leave_entitlements_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `leave_transactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `leave_transactions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `payroll_employee_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `leave_type_id` bigint unsigned NOT NULL,
  `cycle_start_date` date NOT NULL,
  `transaction_type` enum('opening_balance','accrual','application_approved','application_cancelled','manual_adjustment','carry_over','forfeiture','termination_payout','reversal') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `days_delta` decimal(7,3) NOT NULL,
  `effective_date` date NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_id` bigint unsigned DEFAULT NULL,
  `created_by_user_id` bigint unsigned DEFAULT NULL,
  `reversal_of_transaction_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `leave_transactions_user_id_foreign` (`user_id`),
  KEY `leave_transactions_leave_type_id_foreign` (`leave_type_id`),
  KEY `leave_transactions_created_by_user_id_foreign` (`created_by_user_id`),
  KEY `leave_transactions_reversal_of_transaction_id_foreign` (`reversal_of_transaction_id`),
  KEY `leave_txn_employee_type_date` (`payroll_employee_id`,`leave_type_id`,`effective_date`),
  KEY `leave_transactions_agency_id_transaction_type_index` (`agency_id`,`transaction_type`),
  KEY `leave_transactions_source_type_source_id_index` (`source_type`,`source_id`),
  CONSTRAINT `leave_transactions_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `leave_transactions_created_by_user_id_foreign` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `leave_transactions_leave_type_id_foreign` FOREIGN KEY (`leave_type_id`) REFERENCES `leave_types` (`id`),
  CONSTRAINT `leave_transactions_payroll_employee_id_foreign` FOREIGN KEY (`payroll_employee_id`) REFERENCES `payroll_employees` (`id`),
  CONSTRAINT `leave_transactions_reversal_of_transaction_id_foreign` FOREIGN KEY (`reversal_of_transaction_id`) REFERENCES `leave_transactions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `leave_transactions_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `leave_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `leave_types` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `code` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `label` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `category` enum('annual','sick','family_responsibility','parental','study','unpaid','special','other') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_paid` tinyint(1) NOT NULL DEFAULT '1',
  `is_uif_claimable` tinyint(1) NOT NULL DEFAULT '0',
  `requires_documentation` tinyint(1) NOT NULL DEFAULT '0',
  `documentation_label` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `documentation_threshold_days` smallint unsigned DEFAULT NULL,
  `entitlement_days_per_cycle` decimal(5,2) NOT NULL DEFAULT '0.00',
  `entitlement_days_per_cycle_six_day` decimal(5,2) NOT NULL DEFAULT '0.00',
  `cycle_months` smallint unsigned NOT NULL DEFAULT '12',
  `accrual_method` enum('full_at_start','accrual_per_day_worked','accrual_first_six_months','none') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `accrual_rate_per_days` smallint unsigned NOT NULL DEFAULT '17',
  `accrual_starts_at_employment_date` tinyint(1) NOT NULL DEFAULT '1',
  `requires_pre_approval` tinyint(1) NOT NULL DEFAULT '1',
  `min_advance_notice_days` smallint unsigned NOT NULL DEFAULT '0',
  `allows_negative_balance` tinyint(1) NOT NULL DEFAULT '0',
  `carries_over_to_next_cycle` tinyint(1) NOT NULL DEFAULT '1',
  `forfeit_after_months` smallint unsigned DEFAULT NULL,
  `payout_on_termination` tinyint(1) NOT NULL DEFAULT '0',
  `affects_payroll` tinyint(1) NOT NULL DEFAULT '0',
  `is_system` tinyint(1) NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `sort_order` int NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `leave_types_agency_code_unique` (`agency_id`,`code`,`deleted_at`),
  KEY `leave_types_agency_id_is_active_index` (`agency_id`,`is_active`),
  CONSTRAINT `leave_types_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `legal_block_audit_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `legal_block_audit_log` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned DEFAULT NULL,
  `template_id` bigint unsigned NOT NULL,
  `template_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `document_type_slug` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `block_reason` enum('document_type_match','name_pattern_match') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `matched_pattern` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `request_context` json DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `on_behalf_of_user_id` bigint unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `legal_block_audit_log_agency_id_created_at_index` (`agency_id`,`created_at`),
  KEY `legal_block_audit_log_template_id_index` (`template_id`),
  KEY `lbal_obo_fk` (`on_behalf_of_user_id`),
  CONSTRAINT `lbal_obo_fk` FOREIGN KEY (`on_behalf_of_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `listing_import_rows`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `listing_import_rows` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `run_id` bigint unsigned NOT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `external_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `external_ref` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `property` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `price_cents` bigint DEFAULT NULL,
  `file_agent` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `resolved_user_id` bigint unsigned DEFAULT NULL,
  `matched_listing_stock_id` bigint unsigned DEFAULT NULL,
  `match_confidence` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `decision` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `row_payload` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `listing_import_rows_resolved_user_id_foreign` (`resolved_user_id`),
  KEY `listing_import_rows_matched_listing_stock_id_foreign` (`matched_listing_stock_id`),
  KEY `listing_import_rows_run_id_decision_index` (`run_id`,`decision`),
  KEY `listing_import_rows_external_id_index` (`external_id`),
  KEY `listing_import_rows_external_ref_index` (`external_ref`),
  KEY `listing_import_rows_agency_id_idx` (`agency_id`),
  CONSTRAINT `listing_import_rows_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `listing_import_rows_matched_listing_stock_id_foreign` FOREIGN KEY (`matched_listing_stock_id`) REFERENCES `listing_stocks` (`id`) ON DELETE SET NULL,
  CONSTRAINT `listing_import_rows_resolved_user_id_foreign` FOREIGN KEY (`resolved_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `listing_import_rows_run_id_foreign` FOREIGN KEY (`run_id`) REFERENCES `listing_import_runs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `listing_import_runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `listing_import_runs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `imported_by_user_id` bigint unsigned NOT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `branch_id` bigint unsigned DEFAULT NULL,
  `source` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'propcon',
  `original_filename` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `header_row` json DEFAULT NULL,
  `column_mapping` json DEFAULT NULL,
  `agent_mapping` json DEFAULT NULL,
  `status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `error_message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `listing_import_runs_imported_by_user_id_foreign` (`imported_by_user_id`),
  KEY `listing_import_runs_agency_id_idx` (`agency_id`),
  CONSTRAINT `listing_import_runs_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `listing_import_runs_imported_by_user_id_foreign` FOREIGN KEY (`imported_by_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `listing_snapshots`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `listing_snapshots` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `period` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `branch_id` bigint unsigned DEFAULT NULL,
  `user_id` bigint unsigned NOT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `listing_count` int NOT NULL DEFAULT '0',
  `avg_listing_price` decimal(12,2) NOT NULL DEFAULT '0.00',
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `listing_snapshots_period_user_id_unique` (`period`,`user_id`),
  KEY `listing_snapshots_period_branch_id_index` (`period`,`branch_id`),
  KEY `listing_snapshots_branch_id_foreign` (`branch_id`),
  KEY `listing_snapshots_user_id_foreign` (`user_id`),
  KEY `listing_snapshots_created_by_foreign` (`created_by`),
  KEY `listing_snapshots_updated_by_foreign` (`updated_by`),
  KEY `listing_snapshots_agency_id_idx` (`agency_id`),
  CONSTRAINT `listing_snapshots_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `listing_snapshots_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `listing_snapshots_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `listing_snapshots_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `listing_snapshots_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `listing_stock_agents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `listing_stock_agents` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `listing_stock_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `listing_stock_agents_listing_stock_id_user_id_unique` (`listing_stock_id`,`user_id`),
  KEY `listing_stock_agents_user_id_index` (`user_id`),
  KEY `listing_stock_agents_listing_stock_id_index` (`listing_stock_id`),
  CONSTRAINT `listing_stock_agents_listing_stock_id_foreign` FOREIGN KEY (`listing_stock_id`) REFERENCES `listing_stocks` (`id`) ON DELETE CASCADE,
  CONSTRAINT `listing_stock_agents_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `listing_stocks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `listing_stocks` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `branch_id` bigint unsigned DEFAULT NULL,
  `source` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'propcon',
  `external_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `external_ref` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `property` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `price_cents` bigint DEFAULT NULL,
  `cma_price_cents` bigint DEFAULT NULL,
  `cma_updated_at` timestamp NULL DEFAULT NULL,
  `category` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `type` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `region` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mandate` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `listed_at` timestamp NULL DEFAULT NULL,
  `modified_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `raw_payload` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `listing_stocks_user_id_status_index` (`user_id`,`status`),
  KEY `listing_stocks_source_external_id_index` (`source`,`external_id`),
  KEY `listing_stocks_source_external_ref_index` (`source`,`external_ref`),
  KEY `listing_stocks_user_id_cma_price_cents_index` (`user_id`,`cma_price_cents`),
  KEY `listing_stocks_agency_id_idx` (`agency_id`),
  CONSTRAINT `listing_stocks_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `listing_stocks_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `listing_targets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `listing_targets` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `period` varchar(7) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `target_listings` int NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `listing_targets_user_id_period_unique` (`user_id`,`period`),
  KEY `listing_targets_agency_id_idx` (`agency_id`),
  CONSTRAINT `listing_targets_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `listing_targets_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `listing_website_stat_totals`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `listing_website_stat_totals` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `site` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `property_id` bigint unsigned NOT NULL,
  `metric` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `reported_total` bigint unsigned NOT NULL DEFAULT '0',
  `reported_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `lwst_natural_uq` (`agency_id`,`site`,`property_id`,`metric`),
  KEY `lwst_property_metric_idx` (`property_id`,`metric`),
  CONSTRAINT `listing_website_stat_totals_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `listing_website_stat_totals_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `listing_website_stats`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `listing_website_stats` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `site` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `property_id` bigint unsigned NOT NULL,
  `stat_date` date NOT NULL,
  `metric` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `metric_count` bigint unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `lws_natural_uq` (`agency_id`,`site`,`property_id`,`stat_date`,`metric`),
  KEY `lws_agency_date_metric_idx` (`agency_id`,`stat_date`,`metric`),
  KEY `lws_property_metric_date_idx` (`property_id`,`metric`,`stat_date`),
  CONSTRAINT `listing_website_stats_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `listing_website_stats_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `login_histories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `login_histories` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `event` enum('login','logout') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `ip_address` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `login_histories_user_id_created_at_index` (`user_id`,`created_at`),
  CONSTRAINT `login_histories_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mailbox_credential_reveals`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `mailbox_credential_reveals` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `mailbox_id` bigint unsigned NOT NULL,
  `revealed_by` bigint unsigned NOT NULL,
  `revealed_for_user_id` bigint unsigned DEFAULT NULL,
  `revealed_at` timestamp NOT NULL,
  `ip_address` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `mbx_rvl_mailbox_fk` (`mailbox_id`),
  KEY `mbx_rvl_by_fk` (`revealed_by`),
  KEY `mbx_rvl_for_fk` (`revealed_for_user_id`),
  KEY `mbx_rvl_agency_mailbox_idx` (`agency_id`,`mailbox_id`),
  CONSTRAINT `mbx_rvl_agency_fk` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mbx_rvl_by_fk` FOREIGN KEY (`revealed_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mbx_rvl_for_fk` FOREIGN KEY (`revealed_for_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `mbx_rvl_mailbox_fk` FOREIGN KEY (`mailbox_id`) REFERENCES `communication_mailboxes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `map_saved_searches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `map_saved_searches` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `filter_payload` json NOT NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `map_saved_searches_user_name_unique` (`agency_id`,`user_id`,`name`),
  KEY `map_saved_searches_user_id_foreign` (`user_id`),
  KEY `map_saved_searches_owner_idx` (`agency_id`,`user_id`),
  CONSTRAINT `map_saved_searches_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `map_saved_searches_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `market_analytics_runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `market_analytics_runs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `model_version` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Semver-style model identifier e.g. v1.0.0',
  `inputs_hash` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'SHA-256 of canonical inputs JSON',
  `inputs_json` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Canonical serialised input parameters',
  `outputs_json` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'Flat key-value of computed metrics',
  `breakdown_json` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'Detailed per-metric breakdown',
  `data_sources_json` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'Records of data sources consulted',
  `created_by` bigint unsigned DEFAULT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `market_analytics_runs_created_by_foreign` (`created_by`),
  KEY `mar_version_hash_idx` (`model_version`,`inputs_hash`),
  KEY `market_analytics_runs_agency_id_idx` (`agency_id`),
  CONSTRAINT `market_analytics_runs_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `market_analytics_runs_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `market_data_discrepancies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `market_data_discrepancies` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `report_id` bigint unsigned DEFAULT NULL,
  `data_point_id` bigint unsigned NOT NULL,
  `parsed_value` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'What the deterministic parser said.',
  `audit_value` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'What the AI re-extraction said.',
  `discrepancy_type` enum('value_mismatch','date_mismatch','address_mismatch','missing','extra') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `severity` enum('low','medium','high') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'low',
  `resolved` tinyint(1) NOT NULL DEFAULT '0',
  `resolved_by_user_id` bigint unsigned DEFAULT NULL,
  `resolved_at` timestamp NULL DEFAULT NULL,
  `resolution_notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `market_data_discrepancies_data_point_id_foreign` (`data_point_id`),
  KEY `market_data_discrepancies_resolved_by_user_id_foreign` (`resolved_by_user_id`),
  KEY `idx_mdd_report_resolved` (`report_id`,`resolved`),
  KEY `idx_mdd_severity_resolved` (`severity`,`resolved`),
  CONSTRAINT `market_data_discrepancies_data_point_id_foreign` FOREIGN KEY (`data_point_id`) REFERENCES `market_data_points` (`id`) ON DELETE CASCADE,
  CONSTRAINT `market_data_discrepancies_report_id_foreign` FOREIGN KEY (`report_id`) REFERENCES `market_reports` (`id`) ON DELETE SET NULL,
  CONSTRAINT `market_data_discrepancies_resolved_by_user_id_foreign` FOREIGN KEY (`resolved_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='AI spot-check diffs vs deterministic parser output. ≥medium severity notifies super-admin.';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `market_data_points`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `market_data_points` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `report_id` bigint unsigned DEFAULT NULL,
  `tracked_property_id` bigint unsigned DEFAULT NULL,
  `suburb_normalised` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Lowercase + strip punctuation; used for suburb-level data points.',
  `town` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `metric_key` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'e.g. median_price_3bed_house, total_sales_yoy, municipal_valuation, last_sale_price',
  `metric_value_numeric` decimal(15,2) DEFAULT NULL,
  `metric_value_date` date DEFAULT NULL,
  `metric_value_string` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `metric_date` date NOT NULL COMMENT 'The date the metric applies to (e.g. "Q1 2026" → 2026-01-01).',
  `confidence` enum('low','medium','high','verified') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'medium',
  `source_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Mirrors market_reports.source_type but allows API origins (lightstone_api, deeds_api, …).',
  `source_ref` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_superseded` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Newer report invalidates this point.',
  `superseded_by_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `market_data_points_report_id_foreign` (`report_id`),
  KEY `market_data_points_tracked_property_id_foreign` (`tracked_property_id`),
  KEY `market_data_points_superseded_by_id_foreign` (`superseded_by_id`),
  KEY `idx_mdp_agency_tp_metric` (`agency_id`,`tracked_property_id`,`metric_key`,`metric_date`),
  KEY `idx_mdp_agency_suburb_metric` (`agency_id`,`suburb_normalised`,`metric_key`,`metric_date`),
  KEY `idx_mdp_global_metric` (`metric_key`,`metric_date`),
  CONSTRAINT `market_data_points_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`),
  CONSTRAINT `market_data_points_report_id_foreign` FOREIGN KEY (`report_id`) REFERENCES `market_reports` (`id`) ON DELETE SET NULL,
  CONSTRAINT `market_data_points_superseded_by_id_foreign` FOREIGN KEY (`superseded_by_id`) REFERENCES `market_data_points` (`id`) ON DELETE SET NULL,
  CONSTRAINT `market_data_points_tracked_property_id_foreign` FOREIGN KEY (`tracked_property_id`) REFERENCES `tracked_properties` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Normalised market data warehouse. SHARED-POOL: agency_id is audit-only, default reads union across agencies (spec §13).';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `market_report_comp_rows`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `market_report_comp_rows` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `market_report_id` bigint unsigned DEFAULT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `row_index` smallint unsigned NOT NULL DEFAULT '0' COMMENT '0-based order within the report; subject row is typically 0.',
  `row_type` enum('subject','comp','listing','owner') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'subject = the property being valued; comp = sold comparable; listing = active for-sale; owner = scheme owner entry.',
  `scheme_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `section_number` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `flat_number` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ss_number` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Scheme Sectional Title (SS) registration number.',
  `ss_year` smallint unsigned DEFAULT NULL,
  `address` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `suburb_normalised` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `property_type` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `extent_m2` int unsigned DEFAULT NULL,
  `sale_date` date DEFAULT NULL,
  `sale_price` bigint unsigned DEFAULT NULL COMMENT 'Rands (whole), matches presentation_sold_comps.sold_price_inc convention.',
  `estimated_value` bigint unsigned DEFAULT NULL,
  `r_per_m2` int unsigned DEFAULT NULL,
  `list_price` bigint unsigned DEFAULT NULL,
  `days_on_market` smallint unsigned DEFAULT NULL,
  `municipal_valuation` bigint unsigned DEFAULT NULL,
  `municipal_valuation_year` smallint unsigned DEFAULT NULL,
  `condition` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `distance_to_subject_m` smallint unsigned DEFAULT NULL,
  `latitude` decimal(10,7) DEFAULT NULL,
  `longitude` decimal(10,7) DEFAULT NULL,
  `raw_row_json` json DEFAULT NULL COMMENT 'Full extracted row payload for audit + future re-parse.',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `is_demo` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `market_report_comp_rows_agency_id_foreign` (`agency_id`),
  KEY `idx_mrcr_report_type` (`market_report_id`,`row_type`),
  KEY `idx_mrcr_suburb_date` (`suburb_normalised`,`sale_date`),
  KEY `idx_mrcr_geo` (`latitude`,`longitude`),
  KEY `idx_mrcr_scheme` (`scheme_name`),
  KEY `idx_market_report_comp_rows_is_demo` (`is_demo`),
  CONSTRAINT `market_report_comp_rows_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`),
  CONSTRAINT `market_report_comp_rows_market_report_id_foreign` FOREIGN KEY (`market_report_id`) REFERENCES `market_reports` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `market_report_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `market_report_types` (
  `id` smallint unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Stable identifier, e.g. cma_info_market_analysis',
  `display_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Human-readable, e.g. "CMA Info Market Analysis"',
  `parser_class` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'FQCN of the parser, e.g. App\\Services\\MarketReports\\Parsers\\CmaInfoMarketAnalysisParser',
  `expected_fields_json` json NOT NULL COMMENT 'What the parser yields — used for validation + spot-check.',
  `auto_approve` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'If true, skip manual review when spot-check passes.',
  `sample_file_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Path to a representative sample for parser regression tests.',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `market_report_types_key_unique` (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Lookup of supported report types. Seeded in Phase A2.';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `market_reports`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `market_reports` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `uploaded_by_user_id` bigint unsigned NOT NULL,
  `report_type_id` smallint unsigned DEFAULT NULL,
  `file_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Storage path under storage/app/',
  `file_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Original filename as uploaded',
  `file_hash` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'sha256 hex; dedup within agency',
  `source_suburb` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Auto-detected from filename / first-page OCR, or agent-supplied at upload',
  `source_town` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `subject_address` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `subject_scheme_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `subject_section_number` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `subject_latitude` decimal(10,7) DEFAULT NULL,
  `subject_longitude` decimal(10,7) DEFAULT NULL,
  `subject_extent_m2` int unsigned DEFAULT NULL,
  `radius_metres` int unsigned DEFAULT NULL,
  `report_date` date NOT NULL COMMENT 'Date the report was generated (per the document), NOT uploaded_at',
  `parse_status` enum('pending','parsing','parsed','failed','manual_review') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `parse_started_at` timestamp NULL DEFAULT NULL,
  `parse_completed_at` timestamp NULL DEFAULT NULL,
  `parser_version` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Track parser revisions for accuracy metrics',
  `raw_extracted_json` json DEFAULT NULL COMMENT 'Everything the parser pulled, before normalisation into market_data_points',
  `data_points_count` smallint unsigned NOT NULL DEFAULT '0' COMMENT 'Cached count of extracted market_data_points',
  `spot_check_status` enum('pending','running','passed','flagged','manual') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `spot_check_results` json DEFAULT NULL COMMENT 'AI audit re-extraction results (see market_data_discrepancies for diffs)',
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `is_demo` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_market_reports_agency_hash` (`agency_id`,`file_hash`),
  KEY `market_reports_uploaded_by_user_id_foreign` (`uploaded_by_user_id`),
  KEY `idx_market_reports_agency_parse` (`agency_id`,`parse_status`),
  KEY `idx_market_reports_agency_date` (`agency_id`,`report_date`),
  KEY `idx_market_reports_type` (`report_type_id`),
  KEY `idx_market_reports_geo` (`subject_latitude`,`subject_longitude`),
  KEY `idx_market_reports_is_demo` (`is_demo`),
  CONSTRAINT `market_reports_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `market_reports_report_type_id_foreign` FOREIGN KEY (`report_type_id`) REFERENCES `market_report_types` (`id`),
  CONSTRAINT `market_reports_uploaded_by_user_id_foreign` FOREIGN KEY (`uploaded_by_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Per-file upload record for CMA / market reports. Normalised values live in market_data_points.';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `marketing_share_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `marketing_share_log` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `property_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `channel` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `recipient_context` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NOT NULL,
  `on_behalf_of_user_id` bigint unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `marketing_share_log_property_id_foreign` (`property_id`),
  KEY `marketing_share_log_user_id_foreign` (`user_id`),
  KEY `marketing_share_log_agency_id_foreign` (`agency_id`),
  KEY `msl_obo_fk` (`on_behalf_of_user_id`),
  CONSTRAINT `marketing_share_log_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`),
  CONSTRAINT `marketing_share_log_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`),
  CONSTRAINT `marketing_share_log_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `msl_obo_fk` FOREIGN KEY (`on_behalf_of_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `marketing_suppressions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `marketing_suppressions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `identifier` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `identifier_type` enum('email','phone') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `contact_id` bigint unsigned DEFAULT NULL,
  `source` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `reason` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `send_id` bigint unsigned DEFAULT NULL,
  `suppressed_at` timestamp NOT NULL,
  `lifted_at` timestamp NULL DEFAULT NULL,
  `lifted_by_user_id` bigint unsigned DEFAULT NULL,
  `recorded_by_user_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `marketing_suppr_lookup_idx` (`agency_id`,`identifier`,`lifted_at`),
  KEY `marketing_suppr_agency_active_idx` (`agency_id`,`lifted_at`),
  KEY `marketing_suppressions_contact_id_foreign` (`contact_id`),
  KEY `marketing_suppressions_send_id_foreign` (`send_id`),
  KEY `marketing_suppressions_recorded_by_user_id_foreign` (`recorded_by_user_id`),
  KEY `marketing_suppressions_lifted_by_user_id_foreign` (`lifted_by_user_id`),
  CONSTRAINT `marketing_suppressions_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `marketing_suppressions_contact_id_foreign` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `marketing_suppressions_lifted_by_user_id_foreign` FOREIGN KEY (`lifted_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `marketing_suppressions_recorded_by_user_id_foreign` FOREIGN KEY (`recorded_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `marketing_suppressions_send_id_foreign` FOREIGN KEY (`send_id`) REFERENCES `seller_outreach_sends` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `migrations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `batch` int NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=1566 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `minion_capture_areas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `minion_capture_areas` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `p24_suburb_id` bigint unsigned NOT NULL,
  `added_by_user_id` bigint unsigned DEFAULT NULL,
  `last_captured_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `minion_capture_areas_agency_id_p24_suburb_id_unique` (`agency_id`,`p24_suburb_id`),
  KEY `minion_capture_areas_agency_id_index` (`agency_id`),
  KEY `minion_capture_areas_p24_suburb_id_index` (`p24_suburb_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `minion_capture_runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `minion_capture_runs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `p24_suburb_id` bigint unsigned DEFAULT NULL,
  `area_label` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `started_at` timestamp NULL DEFAULT NULL,
  `finished_at` timestamp NULL DEFAULT NULL,
  `status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'running',
  `triggered_by` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'manual',
  `triggered_by_user_id` bigint unsigned DEFAULT NULL,
  `pages_attempted` int unsigned NOT NULL DEFAULT '0',
  `captured` int unsigned NOT NULL DEFAULT '0',
  `listings_new` int unsigned NOT NULL DEFAULT '0',
  `listings_updated` int unsigned NOT NULL DEFAULT '0',
  `listings_deactivated` int unsigned NOT NULL DEFAULT '0',
  `failures` int unsigned NOT NULL DEFAULT '0',
  `failures_json` json DEFAULT NULL,
  `duration_ms` int unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `minion_capture_runs_agency_id_started_at_index` (`agency_id`,`started_at`),
  KEY `minion_capture_runs_agency_id_index` (`agency_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `minion_capture_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `minion_capture_settings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT '0',
  `targets_per_night` smallint unsigned NOT NULL DEFAULT '8',
  `cycle_days` smallint unsigned NOT NULL DEFAULT '7',
  `run_at` varchar(5) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '02:30',
  `run_days` json DEFAULT NULL,
  `pace_min_seconds` smallint unsigned NOT NULL DEFAULT '20',
  `pace_max_seconds` smallint unsigned NOT NULL DEFAULT '55',
  `alert_enabled` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `minion_capture_settings_agency_id_unique` (`agency_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mobile_photo_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `mobile_photo_events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `property_id` bigint unsigned NOT NULL,
  `client_upload_id` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `batch_id` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phase` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `occurred_at` timestamp NULL DEFAULT NULL,
  `meta` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_photo_event` (`property_id`,`client_upload_id`,`phase`),
  KEY `idx_photo_events_property_time` (`property_id`,`created_at`),
  KEY `mobile_photo_events_agency_id_index` (`agency_id`),
  KEY `mobile_photo_events_user_id_index` (`user_id`),
  KEY `mobile_photo_events_property_id_index` (`property_id`),
  KEY `mobile_photo_events_batch_id_index` (`batch_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `monthly_target_goals`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `monthly_target_goals` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned DEFAULT NULL,
  `branch_id` bigint unsigned DEFAULT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `period` varchar(7) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `listings_target` int NOT NULL DEFAULT '0',
  `deals_target` int NOT NULL DEFAULT '0',
  `value_target` decimal(14,2) NOT NULL DEFAULT '0.00',
  `branch_budget` decimal(12,2) NOT NULL DEFAULT '0.00',
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `monthly_target_goals_period_user_id_branch_id_unique` (`period`,`user_id`,`branch_id`),
  KEY `monthly_target_goals_created_by_foreign` (`created_by`),
  KEY `monthly_target_goals_updated_by_foreign` (`updated_by`),
  KEY `monthly_target_goals_period_index` (`period`),
  KEY `monthly_target_goals_user_id_index` (`user_id`),
  KEY `monthly_target_goals_branch_id_index` (`branch_id`),
  KEY `monthly_target_goals_period_branch_id_index` (`period`,`branch_id`),
  KEY `monthly_target_goals_period_user_id_index` (`period`,`user_id`),
  KEY `monthly_target_goals_agency_id_idx` (`agency_id`),
  CONSTRAINT `monthly_target_goals_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `monthly_target_goals_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `monthly_target_goals_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `nexus_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `nexus_permissions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `label` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `section` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'access',
  `module` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sort_order` int NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `nexus_permissions_key_unique` (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `notification_dispatch_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `notification_dispatch_log` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `notification_event_type_id` bigint unsigned NOT NULL,
  `subject_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `subject_id` bigint unsigned DEFAULT NULL,
  `threshold_hit_at` timestamp NOT NULL,
  `dispatched_at` timestamp NULL DEFAULT NULL,
  `channel` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `notification_dispatch_log_notification_event_type_id_foreign` (`notification_event_type_id`),
  KEY `notification_dispatch_log_subject_type_subject_id_index` (`subject_type`,`subject_id`),
  KEY `ndl_user_event_subject` (`user_id`,`notification_event_type_id`,`subject_type`,`subject_id`),
  KEY `notification_dispatch_log_threshold_hit_at_index` (`threshold_hit_at`),
  CONSTRAINT `notification_dispatch_log_notification_event_type_id_foreign` FOREIGN KEY (`notification_event_type_id`) REFERENCES `notification_event_types` (`id`) ON DELETE CASCADE,
  CONSTRAINT `notification_dispatch_log_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `notification_event_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `notification_event_types` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `pillar` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `group_label` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `label` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `default_enabled` tinyint(1) NOT NULL DEFAULT '1',
  `threshold_unit` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'none',
  `default_threshold` int unsigned DEFAULT NULL,
  `threshold_min` int unsigned DEFAULT NULL,
  `threshold_max` int unsigned DEFAULT NULL,
  `supports_in_app` tinyint(1) NOT NULL DEFAULT '1',
  `supports_email` tinyint(1) NOT NULL DEFAULT '1',
  `supports_push` tinyint(1) NOT NULL DEFAULT '1',
  `is_adapter` tinyint(1) NOT NULL DEFAULT '0',
  `adapter_column` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sort_order` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `notification_event_types_key_unique` (`key`),
  KEY `notification_event_types_pillar_sort_order_index` (`pillar`,`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `notifications` (
  `id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `notifiable_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `notifiable_id` bigint unsigned NOT NULL,
  `data` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `read_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `notifications_notifiable_type_notifiable_id_index` (`notifiable_type`,`notifiable_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `onboarding_checklists`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `onboarding_checklists` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `application_id` bigint unsigned NOT NULL,
  `item_key` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `item_label` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_required` tinyint(1) NOT NULL DEFAULT '1',
  `is_completed` tinyint(1) NOT NULL DEFAULT '0',
  `completed_at` timestamp NULL DEFAULT NULL,
  `completed_by` bigint unsigned DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `sort_order` int NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `onboarding_checklists_application_id_item_key_unique` (`application_id`,`item_key`),
  KEY `onboarding_checklists_completed_by_foreign` (`completed_by`),
  CONSTRAINT `onboarding_checklists_application_id_foreign` FOREIGN KEY (`application_id`) REFERENCES `agent_applications` (`id`) ON DELETE CASCADE,
  CONSTRAINT `onboarding_checklists_completed_by_foreign` FOREIGN KEY (`completed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `other_agency_stock_consents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `other_agency_stock_consents` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `property_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `consented_at` timestamp NOT NULL,
  `consent_wording` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `consent_wording_version` int unsigned NOT NULL DEFAULT '1',
  `portal` varchar(8) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `listing_ref` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `listing_url` varchar(2048) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_agency_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ip_address` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` varchar(512) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `other_agency_stock_consents_agency_id_foreign` (`agency_id`),
  KEY `other_agency_stock_consents_user_id_foreign` (`user_id`),
  KEY `other_agency_stock_consents_property_id_created_at_index` (`property_id`,`created_at`),
  CONSTRAINT `other_agency_stock_consents_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `other_agency_stock_consents_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE,
  CONSTRAINT `other_agency_stock_consents_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `other_agency_stock_unlocks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `other_agency_stock_unlocks` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `property_id` bigint unsigned NOT NULL,
  `event_type` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `requested_by_user_id` bigint unsigned DEFAULT NULL,
  `reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `request_id` bigint unsigned DEFAULT NULL,
  `decided_by_user_id` bigint unsigned DEFAULT NULL,
  `relocked_by_user_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `other_agency_stock_unlocks_agency_id_foreign` (`agency_id`),
  KEY `other_agency_stock_unlocks_requested_by_user_id_foreign` (`requested_by_user_id`),
  KEY `other_agency_stock_unlocks_request_id_foreign` (`request_id`),
  KEY `other_agency_stock_unlocks_decided_by_user_id_foreign` (`decided_by_user_id`),
  KEY `other_agency_stock_unlocks_relocked_by_user_id_foreign` (`relocked_by_user_id`),
  KEY `other_agency_stock_unlocks_property_id_id_index` (`property_id`,`id`),
  CONSTRAINT `other_agency_stock_unlocks_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `other_agency_stock_unlocks_decided_by_user_id_foreign` FOREIGN KEY (`decided_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `other_agency_stock_unlocks_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE,
  CONSTRAINT `other_agency_stock_unlocks_relocked_by_user_id_foreign` FOREIGN KEY (`relocked_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `other_agency_stock_unlocks_request_id_foreign` FOREIGN KEY (`request_id`) REFERENCES `other_agency_stock_unlocks` (`id`) ON DELETE SET NULL,
  CONSTRAINT `other_agency_stock_unlocks_requested_by_user_id_foreign` FOREIGN KEY (`requested_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `outbound_mail_guard_captures`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `outbound_mail_guard_captures` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `to_addresses` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `cc_addresses` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `bcc_addresses` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `subject` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `raw_mime` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `environment` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `forwarded_to_sink` tinyint(1) NOT NULL DEFAULT '0',
  `captured_at` timestamp NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `outbound_mail_guard_captures_captured_at_index` (`captured_at`),
  KEY `outbound_mail_guard_captures_environment_index` (`environment`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `outbound_mail_guard_toggle_audit`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `outbound_mail_guard_toggle_audit` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `direction` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `environment` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `outbound_mail_guard_toggle_audit_user_id_foreign` (`user_id`),
  KEY `outbound_mail_guard_toggle_audit_created_at_index` (`created_at`),
  CONSTRAINT `outbound_mail_guard_toggle_audit_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `outreach_queue`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `outreach_queue` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `branch_id` bigint unsigned DEFAULT NULL,
  `contact_id` bigint unsigned NOT NULL,
  `property_id` bigint unsigned DEFAULT NULL,
  `agent_id` bigint unsigned NOT NULL,
  `template_id` bigint unsigned DEFAULT NULL,
  `seller_outreach_send_id` bigint unsigned DEFAULT NULL,
  `channel` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'whatsapp',
  `source` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `body_snapshot` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `due_at` datetime DEFAULT NULL,
  `status` varchar(12) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `claimed_at` datetime DEFAULT NULL,
  `surfaced_at` datetime DEFAULT NULL,
  `sent_at` datetime DEFAULT NULL,
  `dropped_reason` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `outreach_queue_agency_id_foreign` (`agency_id`),
  KEY `outreach_queue_contact_id_foreign` (`contact_id`),
  KEY `outreach_queue_property_id_foreign` (`property_id`),
  KEY `outreach_queue_template_id_foreign` (`template_id`),
  KEY `outreach_queue_seller_outreach_send_id_foreign` (`seller_outreach_send_id`),
  KEY `outreach_queue_status_due_at_index` (`status`,`due_at`),
  KEY `outreach_queue_agent_id_status_index` (`agent_id`,`status`),
  KEY `outreach_queue_due_at_index` (`due_at`),
  KEY `outreach_queue_branch_id_status_index` (`branch_id`,`status`),
  CONSTRAINT `outreach_queue_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `outreach_queue_agent_id_foreign` FOREIGN KEY (`agent_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `outreach_queue_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `outreach_queue_contact_id_foreign` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `outreach_queue_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE SET NULL,
  CONSTRAINT `outreach_queue_seller_outreach_send_id_foreign` FOREIGN KEY (`seller_outreach_send_id`) REFERENCES `seller_outreach_sends` (`id`) ON DELETE SET NULL,
  CONSTRAINT `outreach_queue_template_id_foreign` FOREIGN KEY (`template_id`) REFERENCES `seller_outreach_templates` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `oversight_nudges`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `oversight_nudges` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `from_user_id` bigint unsigned NOT NULL,
  `to_user_id` bigint unsigned NOT NULL,
  `subject_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `subject_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `category` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `sent_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `oversight_nudges_from_user_id_foreign` (`from_user_id`),
  KEY `oversight_nudges_to_user_id_foreign` (`to_user_id`),
  KEY `oversight_nudges_agency_id_to_user_id_index` (`agency_id`,`to_user_id`),
  KEY `oversight_nudges_subject_type_subject_id_index` (`subject_type`,`subject_id`),
  CONSTRAINT `oversight_nudges_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `oversight_nudges_from_user_id_foreign` FOREIGN KEY (`from_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `oversight_nudges_to_user_id_foreign` FOREIGN KEY (`to_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `p24_cities`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `p24_cities` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `p24_id` bigint unsigned NOT NULL,
  `p24_province_id` bigint unsigned NOT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `p24_verified_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `p24_cities_p24_id_unique` (`p24_id`),
  KEY `p24_cities_p24_province_id_name_index` (`p24_province_id`,`name`),
  KEY `p24_cities_p24_verified_at_index` (`p24_verified_at`),
  CONSTRAINT `p24_cities_p24_province_id_foreign` FOREIGN KEY (`p24_province_id`) REFERENCES `p24_provinces` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `p24_countries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `p24_countries` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `p24_id` bigint unsigned NOT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `p24_countries_p24_id_unique` (`p24_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `p24_import_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `p24_import_log` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `email_uid` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `email_subject` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `email_date` datetime NOT NULL,
  `listings_found` int unsigned NOT NULL DEFAULT '0',
  `listings_new` int unsigned NOT NULL DEFAULT '0',
  `listings_updated` int unsigned NOT NULL DEFAULT '0',
  `status` enum('success','error','skipped') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'success',
  `error_message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `p24_import_log_email_uid_unique` (`email_uid`),
  KEY `p24_import_log_agency_id_idx` (`agency_id`),
  CONSTRAINT `p24_import_log_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `p24_import_rows`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `p24_import_rows` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `run_id` bigint unsigned NOT NULL,
  `row_type` enum('agent','listing','image') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `external_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `payload_json` json DEFAULT NULL,
  `mapped_json` json DEFAULT NULL,
  `action` enum('create','update','skip','choose','link') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'create',
  `status` enum('pending','confirmed','excluded','error') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `resolved_agent_id` bigint unsigned DEFAULT NULL,
  `target_id` bigint unsigned DEFAULT NULL,
  `errors_json` json DEFAULT NULL,
  `image_urls_json` json DEFAULT NULL,
  `confirmed_at` timestamp NULL DEFAULT NULL,
  `excluded_at` timestamp NULL DEFAULT NULL,
  `confirmed_by` bigint unsigned DEFAULT NULL,
  `confirmed_via` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `confirmed_by_portal_id` bigint unsigned DEFAULT NULL,
  `processing_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `p24_import_rows_resolved_agent_id_foreign` (`resolved_agent_id`),
  KEY `p24_import_rows_confirmed_by_foreign` (`confirmed_by`),
  KEY `p24_import_rows_run_id_row_type_status_index` (`run_id`,`row_type`,`status`),
  KEY `p24_import_rows_external_id_index` (`external_id`),
  KEY `p24_import_rows_confirmed_by_portal_id_index` (`confirmed_by_portal_id`),
  CONSTRAINT `p24_import_rows_confirmed_by_foreign` FOREIGN KEY (`confirmed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `p24_import_rows_resolved_agent_id_foreign` FOREIGN KEY (`resolved_agent_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `p24_import_rows_run_id_foreign` FOREIGN KEY (`run_id`) REFERENCES `p24_import_runs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `p24_import_runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `p24_import_runs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned DEFAULT NULL,
  `agency_id` bigint unsigned DEFAULT NULL,
  `kind` enum('agents','listings_images') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `mark_compliant_on_confirm` tinyint(1) NOT NULL DEFAULT '0',
  `status` enum('parsing','pending_confirm','importing','completed','failed','cancelled') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'parsing',
  `agents_csv_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `listings_csv_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `images_csv_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `counts_json` json DEFAULT NULL,
  `confirmed_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `error_message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `p24_import_runs_user_id_foreign` (`user_id`),
  KEY `p24_import_runs_agency_id_kind_status_index` (`agency_id`,`kind`,`status`),
  KEY `p24_import_runs_agency_id_index` (`agency_id`),
  CONSTRAINT `p24_import_runs_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `p24_listings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `p24_listings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `p24_listing_number` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `asking_price` decimal(15,2) NOT NULL,
  `property_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `suburb` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `area` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bedrooms` tinyint unsigned DEFAULT NULL,
  `bathrooms` tinyint unsigned DEFAULT NULL,
  `garages` tinyint unsigned DEFAULT NULL,
  `is_mandated` tinyint(1) NOT NULL DEFAULT '0',
  `listing_status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `p24_url` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `first_seen_date` date NOT NULL,
  `last_seen_date` date NOT NULL,
  `original_price` decimal(15,2) DEFAULT NULL,
  `times_seen` int unsigned NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `p24_listings_p24_listing_number_unique` (`p24_listing_number`),
  KEY `p24_listings_suburb_index` (`suburb`),
  KEY `p24_listings_property_type_index` (`property_type`),
  KEY `p24_listings_asking_price_index` (`asking_price`),
  KEY `p24_listings_first_seen_date_index` (`first_seen_date`),
  KEY `p24_listings_suburb_first_seen_date_index` (`suburb`,`first_seen_date`),
  KEY `p24_listings_agency_id_idx` (`agency_id`),
  CONSTRAINT `p24_listings_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `p24_onboarding_portals`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `p24_onboarding_portals` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `token` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `label` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `revoked_at` timestamp NULL DEFAULT NULL,
  `revoked_reason` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_opened_at` timestamp NULL DEFAULT NULL,
  `open_count` int unsigned NOT NULL DEFAULT '0',
  `completed_at` timestamp NULL DEFAULT NULL,
  `run_ids_json` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `p24_onboarding_portals_token_unique` (`token`),
  UNIQUE KEY `p24_onboarding_portals_slug_unique` (`slug`),
  KEY `p24_onboarding_portals_created_by_foreign` (`created_by`),
  KEY `p24_onboarding_portals_agency_id_revoked_at_completed_at_index` (`agency_id`,`revoked_at`,`completed_at`),
  KEY `p24_onboarding_portals_agency_id_index` (`agency_id`),
  CONSTRAINT `p24_onboarding_portals_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `p24_portal_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `p24_portal_events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `portal_id` bigint unsigned DEFAULT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `actor_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'portal_visitor',
  `actor_label` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `event` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `target_row_id` bigint unsigned DEFAULT NULL,
  `target_external_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `meta_json` json DEFAULT NULL,
  `ip` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` varchar(512) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `p24_portal_events_agency_id_created_at_index` (`agency_id`,`created_at`),
  KEY `p24_portal_events_portal_id_created_at_index` (`portal_id`,`created_at`),
  KEY `p24_portal_events_portal_id_index` (`portal_id`),
  KEY `p24_portal_events_agency_id_index` (`agency_id`),
  KEY `p24_portal_events_target_row_id_index` (`target_row_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `p24_price_changes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `p24_price_changes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `listing_id` bigint unsigned NOT NULL,
  `old_price` decimal(15,2) NOT NULL,
  `new_price` decimal(15,2) NOT NULL,
  `change_date` date NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `p24_price_changes_listing_id_foreign` (`listing_id`),
  CONSTRAINT `p24_price_changes_listing_id_foreign` FOREIGN KEY (`listing_id`) REFERENCES `p24_listings` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `p24_provinces`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `p24_provinces` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `p24_id` bigint unsigned NOT NULL,
  `p24_country_id` bigint unsigned NOT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `p24_verified_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `p24_provinces_p24_id_unique` (`p24_id`),
  KEY `p24_provinces_p24_country_id_name_index` (`p24_country_id`,`name`),
  KEY `p24_provinces_p24_verified_at_index` (`p24_verified_at`),
  CONSTRAINT `p24_provinces_p24_country_id_foreign` FOREIGN KEY (`p24_country_id`) REFERENCES `p24_countries` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `p24_suburbs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `p24_suburbs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `p24_id` int unsigned DEFAULT NULL,
  `p24_city_id` bigint unsigned DEFAULT NULL,
  `latitude` decimal(10,7) DEFAULT NULL,
  `longitude` decimal(10,7) DEFAULT NULL,
  `centroid_source` varchar(24) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `centroid_geocoded_at` timestamp NULL DEFAULT NULL,
  `region` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `surrounding_ids` json DEFAULT NULL,
  `confirmed` tinyint(1) NOT NULL DEFAULT '0',
  `p24_verified_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `p24_suburbs_p24_city_id_foreign` (`p24_city_id`),
  KEY `p24_suburbs_slug_index` (`slug`),
  KEY `p24_suburbs_p24_verified_at_index` (`p24_verified_at`),
  CONSTRAINT `p24_suburbs_p24_city_id_foreign` FOREIGN KEY (`p24_city_id`) REFERENCES `p24_cities` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `p24_syndication_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `p24_syndication_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `property_id` bigint unsigned NOT NULL,
  `action` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `request_payload` json DEFAULT NULL,
  `response_payload` json DEFAULT NULL,
  `status_code` smallint DEFAULT NULL,
  `round_trip_ms` int unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `p24_logs_property_created_idx` (`property_id`,`created_at`),
  CONSTRAINT `p24_syndication_logs_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `password_reset_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `password_reset_tokens` (
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `payroll_deduction_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `payroll_deduction_types` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `code` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `label` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `sars_source_code` varchar(4) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_statutory` tinyint(1) NOT NULL DEFAULT '0',
  `is_system` tinyint(1) NOT NULL DEFAULT '0',
  `sort_order` tinyint unsigned NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `active_code_key` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci GENERATED ALWAYS AS ((case when (`deleted_at` is null) then `code` else NULL end)) STORED,
  PRIMARY KEY (`id`),
  UNIQUE KEY `payroll_deduction_types_active_code_uq` (`agency_id`,`active_code_key`),
  CONSTRAINT `payroll_deduction_types_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `payroll_earning_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `payroll_earning_types` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `code` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `label` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `sars_source_code` varchar(4) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_taxable` tinyint(1) NOT NULL DEFAULT '1',
  `is_fringe_benefit` tinyint(1) NOT NULL DEFAULT '0',
  `affects_uif_remuneration` tinyint(1) NOT NULL DEFAULT '1',
  `affects_sdl_remuneration` tinyint(1) NOT NULL DEFAULT '1',
  `pro_rates_on_partial` tinyint(1) NOT NULL DEFAULT '0',
  `sort_order` tinyint unsigned NOT NULL DEFAULT '0',
  `is_system` tinyint(1) NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `active_code_key` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci GENERATED ALWAYS AS ((case when (`deleted_at` is null) then `code` else NULL end)) STORED,
  PRIMARY KEY (`id`),
  UNIQUE KEY `payroll_earning_types_active_code_uq` (`agency_id`,`active_code_key`),
  CONSTRAINT `payroll_earning_types_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `payroll_employee_deductions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `payroll_employee_deductions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `payroll_employee_id` bigint unsigned NOT NULL,
  `deduction_type_id` bigint unsigned NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `effective_from` date NOT NULL,
  `effective_to` date DEFAULT NULL,
  `override_statutory` tinyint(1) NOT NULL DEFAULT '0',
  `notes` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` bigint unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `payroll_employee_deductions_agency_id_foreign` (`agency_id`),
  KEY `payroll_employee_deductions_deduction_type_id_foreign` (`deduction_type_id`),
  KEY `payroll_employee_deductions_created_by_foreign` (`created_by`),
  KEY `ped_employee_effective_idx` (`payroll_employee_id`,`effective_from`,`effective_to`),
  CONSTRAINT `payroll_employee_deductions_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`),
  CONSTRAINT `payroll_employee_deductions_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  CONSTRAINT `payroll_employee_deductions_deduction_type_id_foreign` FOREIGN KEY (`deduction_type_id`) REFERENCES `payroll_deduction_types` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `payroll_employee_deductions_payroll_employee_id_foreign` FOREIGN KEY (`payroll_employee_id`) REFERENCES `payroll_employees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `payroll_employee_earnings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `payroll_employee_earnings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `payroll_employee_id` bigint unsigned NOT NULL,
  `earning_type_id` bigint unsigned NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `effective_from` date NOT NULL,
  `effective_to` date DEFAULT NULL,
  `notes` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` bigint unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `payroll_employee_earnings_agency_id_foreign` (`agency_id`),
  KEY `payroll_employee_earnings_earning_type_id_foreign` (`earning_type_id`),
  KEY `payroll_employee_earnings_created_by_foreign` (`created_by`),
  KEY `pee_employee_effective_idx` (`payroll_employee_id`,`effective_from`,`effective_to`),
  CONSTRAINT `payroll_employee_earnings_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`),
  CONSTRAINT `payroll_employee_earnings_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  CONSTRAINT `payroll_employee_earnings_earning_type_id_foreign` FOREIGN KEY (`earning_type_id`) REFERENCES `payroll_earning_types` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `payroll_employee_earnings_payroll_employee_id_foreign` FOREIGN KEY (`payroll_employee_id`) REFERENCES `payroll_employees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `payroll_employees`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `payroll_employees` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `branch_id` bigint unsigned DEFAULT NULL,
  `user_id` bigint unsigned NOT NULL,
  `employment_date` date NOT NULL,
  `termination_date` date DEFAULT NULL,
  `designation_snapshot` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `pay_frequency` enum('monthly') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'monthly',
  `pay_day_of_month` tinyint unsigned NOT NULL DEFAULT '25',
  `working_days_per_week` tinyint unsigned NOT NULL DEFAULT '5',
  `working_pattern` enum('monday_to_friday','monday_to_saturday','custom') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'monday_to_friday',
  `working_days_mask` tinyint unsigned NOT NULL DEFAULT '31' COMMENT 'Bitmap: bit 0=Mon, bit 1=Tue ... bit 6=Sun. Default 31 = Mon-Fri',
  `daily_rate_basis` enum('fixed_21_67','calendar_working_days','hours_per_day') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'fixed_21_67',
  `hours_per_day` decimal(4,2) NOT NULL DEFAULT '8.00',
  `take_on_completed_at` timestamp NULL DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_by` bigint unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `payroll_employees_agency_id_user_id_unique` (`agency_id`,`user_id`),
  KEY `payroll_employees_branch_id_foreign` (`branch_id`),
  KEY `payroll_employees_user_id_foreign` (`user_id`),
  KEY `payroll_employees_created_by_foreign` (`created_by`),
  KEY `payroll_employees_agency_id_branch_id_is_active_index` (`agency_id`,`branch_id`,`is_active`),
  CONSTRAINT `payroll_employees_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`),
  CONSTRAINT `payroll_employees_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `payroll_employees_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  CONSTRAINT `payroll_employees_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `payroll_payslip_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `payroll_payslip_lines` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `payroll_payslip_id` bigint unsigned NOT NULL,
  `line_type` enum('earning','deduction','employer_contribution') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_type_id` bigint unsigned NOT NULL,
  `code_snapshot` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `label_snapshot` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `sars_source_code_snapshot` varchar(4) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `amount` decimal(15,2) NOT NULL,
  `is_taxable_snapshot` tinyint(1) NOT NULL,
  `sort_order` tinyint unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `payroll_payslip_lines_payroll_payslip_id_line_type_index` (`payroll_payslip_id`,`line_type`),
  CONSTRAINT `payroll_payslip_lines_payroll_payslip_id_foreign` FOREIGN KEY (`payroll_payslip_id`) REFERENCES `payroll_payslips` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `payroll_payslips`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `payroll_payslips` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `branch_id` bigint unsigned DEFAULT NULL,
  `payroll_run_id` bigint unsigned NOT NULL,
  `payroll_employee_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `payslip_number` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `employee_name_snapshot` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `id_number_snapshot` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tax_reference_snapshot` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `employment_date_snapshot` date NOT NULL,
  `designation_snapshot` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `period_month` date NOT NULL,
  `pay_date` date NOT NULL,
  `total_earnings` decimal(15,2) NOT NULL,
  `total_deductions` decimal(15,2) NOT NULL,
  `taxable_income` decimal(15,2) NOT NULL,
  `paye_amount` decimal(15,2) NOT NULL,
  `uif_employee_amount` decimal(15,2) NOT NULL,
  `uif_employer_amount` decimal(15,2) NOT NULL,
  `sdl_amount` decimal(15,2) NOT NULL,
  `net_pay` decimal(15,2) NOT NULL,
  `document_id` bigint unsigned DEFAULT NULL,
  `pdf_generated_at` timestamp NULL DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `active_payslip_key` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci GENERATED ALWAYS AS ((case when (`deleted_at` is null) then `payslip_number` else NULL end)) STORED,
  PRIMARY KEY (`id`),
  UNIQUE KEY `payroll_payslips_payroll_run_id_payroll_employee_id_unique` (`payroll_run_id`,`payroll_employee_id`),
  UNIQUE KEY `payroll_payslips_active_number_unique` (`agency_id`,`active_payslip_key`),
  KEY `payroll_payslips_branch_id_foreign` (`branch_id`),
  KEY `payroll_payslips_payroll_employee_id_foreign` (`payroll_employee_id`),
  KEY `payroll_payslips_document_id_foreign` (`document_id`),
  KEY `payroll_payslips_user_id_period_month_index` (`user_id`,`period_month`),
  CONSTRAINT `payroll_payslips_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`),
  CONSTRAINT `payroll_payslips_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  CONSTRAINT `payroll_payslips_document_id_foreign` FOREIGN KEY (`document_id`) REFERENCES `documents` (`id`),
  CONSTRAINT `payroll_payslips_payroll_employee_id_foreign` FOREIGN KEY (`payroll_employee_id`) REFERENCES `payroll_employees` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `payroll_payslips_payroll_run_id_foreign` FOREIGN KEY (`payroll_run_id`) REFERENCES `payroll_runs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `payroll_payslips_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `payroll_runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `payroll_runs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `run_number` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `period_month` date NOT NULL,
  `pay_date` date NOT NULL,
  `cut_date` date DEFAULT NULL,
  `status` enum('draft','finalised','cancelled') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `finalised_at` timestamp NULL DEFAULT NULL,
  `finalised_by` bigint unsigned DEFAULT NULL,
  `cancelled_at` timestamp NULL DEFAULT NULL,
  `cancelled_by` bigint unsigned DEFAULT NULL,
  `cancellation_reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `payslip_count` int NOT NULL DEFAULT '0',
  `total_gross` decimal(15,2) NOT NULL DEFAULT '0.00',
  `total_paye` decimal(15,2) NOT NULL DEFAULT '0.00',
  `total_uif_employee` decimal(15,2) NOT NULL DEFAULT '0.00',
  `total_uif_employer` decimal(15,2) NOT NULL DEFAULT '0.00',
  `total_sdl` decimal(15,2) NOT NULL DEFAULT '0.00',
  `total_net` decimal(15,2) NOT NULL DEFAULT '0.00',
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_by` bigint unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `active_period_key` date GENERATED ALWAYS AS ((case when ((`deleted_at` is null) and (`status` <> _utf8mb4'cancelled')) then `period_month` else NULL end)) STORED,
  PRIMARY KEY (`id`),
  UNIQUE KEY `payroll_runs_active_period_unique` (`agency_id`,`active_period_key`),
  KEY `payroll_runs_finalised_by_foreign` (`finalised_by`),
  KEY `payroll_runs_cancelled_by_foreign` (`cancelled_by`),
  KEY `payroll_runs_created_by_foreign` (`created_by`),
  KEY `payroll_runs_agency_id_status_index` (`agency_id`,`status`),
  CONSTRAINT `payroll_runs_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`),
  CONSTRAINT `payroll_runs_cancelled_by_foreign` FOREIGN KEY (`cancelled_by`) REFERENCES `users` (`id`),
  CONSTRAINT `payroll_runs_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  CONSTRAINT `payroll_runs_finalised_by_foreign` FOREIGN KEY (`finalised_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `payroll_tax_rebates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `payroll_tax_rebates` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tax_year_start` date NOT NULL,
  `primary_rebate` decimal(15,2) NOT NULL,
  `secondary_rebate` decimal(15,2) NOT NULL,
  `tertiary_rebate` decimal(15,2) NOT NULL,
  `tax_threshold_under_65` decimal(15,2) NOT NULL,
  `tax_threshold_65_74` decimal(15,2) NOT NULL,
  `tax_threshold_75_plus` decimal(15,2) NOT NULL,
  `medical_credit_main` decimal(10,2) NOT NULL,
  `medical_credit_additional` decimal(10,2) NOT NULL,
  `uif_ceiling_monthly` decimal(15,2) NOT NULL,
  `uif_rate_percent` decimal(5,3) NOT NULL,
  `sdl_threshold_annual` decimal(15,2) NOT NULL,
  `sdl_rate_percent` decimal(5,3) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `payroll_tax_rebates_tax_year_start_unique` (`tax_year_start`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `payroll_tax_tables`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `payroll_tax_tables` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tax_year_start` date NOT NULL,
  `tax_year_end` date NOT NULL,
  `bracket_order` tinyint unsigned NOT NULL,
  `income_from` decimal(15,2) NOT NULL,
  `income_to` decimal(15,2) DEFAULT NULL,
  `base_tax` decimal(15,2) NOT NULL,
  `rate_percent` decimal(5,2) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `payroll_tax_tables_tax_year_start_bracket_order_unique` (`tax_year_start`,`bracket_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pdf_splitter_feedback`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pdf_splitter_feedback` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `base_name` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `page_number` smallint unsigned NOT NULL,
  `auto_label` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `final_label` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `snippet` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `scores` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `pdf_splitter_feedback_final_label_auto_label_index` (`final_label`,`auto_label`),
  KEY `pdf_splitter_feedback_base_name_index` (`base_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pdf_splitter_learned_phrases`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pdf_splitter_learned_phrases` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `bucket` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `phrase` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `weight` smallint unsigned NOT NULL DEFAULT '1',
  `hits` int unsigned NOT NULL DEFAULT '0',
  `enabled` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `pdf_splitter_learned_phrases_bucket_phrase_unique` (`bucket`,`phrase`),
  KEY `pdf_splitter_learned_phrases_bucket_enabled_index` (`bucket`,`enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `performance_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `performance_settings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned DEFAULT NULL,
  `key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `performance_settings_agency_key_unique` (`agency_id`,`key`),
  CONSTRAINT `performance_settings_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=55 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `personal_access_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `personal_access_tokens` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tokenable_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `tokenable_id` bigint unsigned NOT NULL,
  `name` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `abilities` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `personal_access_tokens_token_unique` (`token`),
  KEY `personal_access_tokens_tokenable_type_tokenable_id_index` (`tokenable_type`,`tokenable_id`),
  KEY `personal_access_tokens_expires_at_index` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pipeline_user_preferences`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pipeline_user_preferences` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `default_view` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'timeline',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `pipeline_user_preferences_user_id_unique` (`user_id`),
  CONSTRAINT `pipeline_user_preferences_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `policy_acknowledgements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `policy_acknowledgements` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `branch_id` bigint unsigned DEFAULT NULL,
  `policy_id` bigint unsigned NOT NULL,
  `policy_version_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `status` enum('in_progress','completed','expired','superseded') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'in_progress',
  `started_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `completed_at` timestamp NULL DEFAULT NULL,
  `valid_until` date DEFAULT NULL,
  `signature_path` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `signature_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `typed_signature_name` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ip_address` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `device_fingerprint` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `declaration_text` text CHARACTER SET utf8mb4 