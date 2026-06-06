/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `activity_columns`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `activity_columns` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(255) NOT NULL,
  `label` varchar(255) NOT NULL,
  `points_weight` decimal(10,2) NOT NULL DEFAULT 1.00,
  `group` varchar(255) DEFAULT NULL,
  `input_type` varchar(255) NOT NULL DEFAULT 'number',
  `default_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(10) unsigned NOT NULL DEFAULT 100,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `activity_columns_key_unique` (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `activity_definition_calendar_classes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `activity_definition_calendar_classes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint(20) unsigned NOT NULL,
  `event_class` varchar(64) DEFAULT NULL,
  `activity_definition_id` bigint(20) unsigned NOT NULL,
  `trigger_kind` varchar(16) NOT NULL DEFAULT 'calendar',
  `slug` varchar(64) DEFAULT NULL,
  `subject_type` varchar(100) DEFAULT NULL,
  `value_per_event` int(11) NOT NULL DEFAULT 1,
  `requires_feedback` tinyint(1) NOT NULL DEFAULT 1,
  `auto_revoke_after_hours` int(11) DEFAULT 24,
  `daily_cap` int(11) DEFAULT NULL,
  `back_date_limit_hours` int(11) NOT NULL DEFAULT 48,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `updated_by` bigint(20) unsigned DEFAULT NULL,
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `activity_definitions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `scope` varchar(20) NOT NULL DEFAULT 'global',
  `agency_id` bigint(20) unsigned DEFAULT NULL,
  `branch_id` bigint(20) unsigned DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `weight` decimal(10,2) NOT NULL DEFAULT 1.00,
  `sort_order` int(11) NOT NULL DEFAULT 100,
  `scoring_mode` varchar(20) NOT NULL DEFAULT 'count',
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `activity_point_goals` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `branch_id` bigint(20) unsigned DEFAULT NULL,
  `period` varchar(7) NOT NULL,
  `points_target` decimal(12,2) NOT NULL DEFAULT 0.00,
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `activity_targets` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `period` varchar(7) NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `branch_id` bigint(20) unsigned DEFAULT NULL,
  `calls_made_target` int(11) NOT NULL DEFAULT 0,
  `doors_knocked_target` int(11) NOT NULL DEFAULT 0,
  `whatsapps_sent_target` int(11) NOT NULL DEFAULT 0,
  `referrals_asked_target` int(11) NOT NULL DEFAULT 0,
  `flyers_dropped_target` int(11) NOT NULL DEFAULT 0,
  `presentations_booked_target` int(11) NOT NULL DEFAULT 0,
  `presentations_done_target` int(11) NOT NULL DEFAULT 0,
  `oats_signed_target` int(11) NOT NULL DEFAULT 0,
  `eats_signed_target` int(11) NOT NULL DEFAULT 0,
  `buyer_leads_target` int(11) NOT NULL DEFAULT 0,
  `seller_leads_target` int(11) NOT NULL DEFAULT 0,
  `portal_leads_target` int(11) NOT NULL DEFAULT 0,
  `referral_leads_target` int(11) NOT NULL DEFAULT 0,
  `buyer_appointments_target` int(11) NOT NULL DEFAULT 0,
  `otps_written_target` int(11) NOT NULL DEFAULT 0,
  `otps_accepted_target` int(11) NOT NULL DEFAULT 0,
  `otps_collapsed_target` int(11) NOT NULL DEFAULT 0,
  `notes` text DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `updated_by` bigint(20) unsigned DEFAULT NULL,
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `agencies` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `trading_name` varchar(255) DEFAULT NULL,
  `tagline` varchar(255) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `phone` varchar(255) DEFAULT NULL,
  `phone_label` varchar(100) DEFAULT NULL,
  `phone_secondary` varchar(255) DEFAULT NULL,
  `phone_secondary_label` varchar(100) DEFAULT NULL,
  `fax` varchar(255) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `reg_no` varchar(255) DEFAULT NULL,
  `vat_no` varchar(255) DEFAULT NULL,
  `ffc_no` varchar(255) DEFAULT NULL,
  `ppra_number` varchar(32) DEFAULT NULL,
  `fic_no` varchar(255) DEFAULT NULL,
  `p24_agency_id` varchar(32) DEFAULT NULL,
  `p24_agency_label` varchar(100) DEFAULT NULL,
  `p24_username` varchar(255) DEFAULT NULL,
  `p24_password` text DEFAULT NULL,
  `p24_user_group_id` varchar(255) DEFAULT NULL,
  `p24_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `p24_locations_synced_at` timestamp NULL DEFAULT NULL,
  `p24_last_sync_error` text DEFAULT NULL,
  `pp_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `pp_username` varchar(255) DEFAULT NULL,
  `pp_password` text DEFAULT NULL,
  `pp_branch_guid` varchar(64) DEFAULT NULL,
  `pp_wsdl` varchar(255) DEFAULT NULL,
  `pp_sandbox` tinyint(1) NOT NULL DEFAULT 1,
  `pp_image_base_url` varchar(255) DEFAULT NULL,
  `pp_webhook_secret` text DEFAULT NULL,
  `pp_last_sync_error` text DEFAULT NULL,
  `slug` varchar(255) NOT NULL,
  `sidebar_color` varchar(20) NOT NULL DEFAULT '#0ea5e9',
  `icon_color` varchar(20) NOT NULL DEFAULT '#0ea5e9',
  `default_color` varchar(20) NOT NULL DEFAULT '#0b2a4a',
  `button_color` varchar(20) NOT NULL DEFAULT '#0ea5e9',
  `ai_voice_enabled` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Allow agents at this agency to use Ellie voice commands (advanced feature)',
  `ai_image_recognition_enabled` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Allow agents at this agency to use AI property image recognition (advanced feature, mobile-only)',
  `logo_path` varchar(255) DEFAULT NULL,
  `email_disclaimer` text DEFAULT NULL,
  `popi_url` varchar(500) DEFAULT NULL,
  `privacy_policy_markdown` longtext DEFAULT NULL,
  `privacy_policy_token` varchar(64) DEFAULT NULL,
  `privacy_policy_published_at` timestamp NULL DEFAULT NULL,
  `whatsapp_launch_mode_agent` varchar(20) NOT NULL DEFAULT 'whatsapp_web',
  `whatsapp_launch_mode_seller` varchar(20) NOT NULL DEFAULT 'whatsapp_web',
  `ai_monthly_budget_zar` decimal(10,2) NOT NULL DEFAULT 1000.00,
  `ai_budget_warning_pct` tinyint(3) unsigned NOT NULL DEFAULT 80,
  `ai_budget_hard_cap_pct` tinyint(3) unsigned NOT NULL DEFAULT 110,
  `ai_budget_overage_allowed` tinyint(1) NOT NULL DEFAULT 0,
  `ai_budget_last_warned_at` timestamp NULL DEFAULT NULL,
  `ai_budget_last_hard_stopped_at` timestamp NULL DEFAULT NULL,
  `prospecting_pitch_temp_lock_minutes` smallint(5) unsigned NOT NULL DEFAULT 30,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `is_demo` tinyint(1) NOT NULL DEFAULT 0,
  `require_external_access_authorization` tinyint(1) NOT NULL DEFAULT 0,
  `presentations_coverage_rich_threshold` smallint(5) unsigned NOT NULL DEFAULT 6,
  `presentations_coverage_moderate_threshold` smallint(5) unsigned NOT NULL DEFAULT 3,
  `presentations_coverage_thin_threshold` smallint(5) unsigned NOT NULL DEFAULT 1,
  `presentations_default_period_months` smallint(5) unsigned NOT NULL DEFAULT 12,
  `presentations_default_show_executive_summary` tinyint(1) NOT NULL DEFAULT 1,
  `presentations_default_show_market_overview` tinyint(1) NOT NULL DEFAULT 1,
  `presentations_default_show_recent_sales` tinyint(1) NOT NULL DEFAULT 1,
  `presentations_default_show_spatial_view` tinyint(1) NOT NULL DEFAULT 1,
  `presentations_default_show_cma_analysis` tinyint(1) NOT NULL DEFAULT 1,
  `presentations_default_show_active_competition` tinyint(1) NOT NULL DEFAULT 1,
  `presentations_default_show_inflow_absorption` tinyint(1) NOT NULL DEFAULT 1,
  `presentations_default_show_holding_cost` tinyint(1) NOT NULL DEFAULT 1,
  `presentations_default_show_pricing_strategy` tinyint(1) NOT NULL DEFAULT 1,
  `presentations_freshness_days` smallint(5) unsigned NOT NULL DEFAULT 90 COMMENT 'Build 5 — public view shows a "request revised analysis" CTA when the snapshot is older than this many days.',
  `cma_compute_recency_months` smallint(5) unsigned DEFAULT 36 COMMENT 'Build 8b — recency window (months) for CmaComputeService input pool. Decoupled from presentations_default_period_months which drives the hydrator + coverage badge. Null falls back to service constant.',
  `cma_compute_iqr_multiplier` decimal(4,2) DEFAULT 1.50 COMMENT 'Build 8b — IQR multiplier for R/m² lower-bound outlier fence (median − multiplier × IQR). 1.5 is Tukey standard. Null falls back to service constant.',
  `competitor_stock_default_beds_tolerance` tinyint(3) unsigned NOT NULL DEFAULT 1 COMMENT 'Competitor Stock — ± beds window for synthetic ContactMatch (Core Matches scorer).',
  `competitor_stock_default_price_tolerance_pct` tinyint(3) unsigned NOT NULL DEFAULT 20 COMMENT 'Competitor Stock — ± percent price band for synthetic match (e.g. 20 = ±20%).',
  `competitor_stock_min_score` tinyint(3) unsigned NOT NULL DEFAULT 50 COMMENT 'Competitor Stock — minimum match score (Core Matches 0-100) to include in section. 50 = Approximate tier floor.',
  `presentations_default_comp_scope` enum('radius_all','suburb_only') NOT NULL DEFAULT 'radius_all',
  `presentations_default_radius_m` smallint(5) unsigned NOT NULL DEFAULT 1000,
  `presentations_default_rates_per_million_zar` int(10) unsigned NOT NULL DEFAULT 800 COMMENT 'Monthly municipal rates per R1M of property value.',
  `presentations_default_levies_sectional_per_m2_zar` smallint(5) unsigned NOT NULL DEFAULT 25 COMMENT 'Monthly body-corporate levies per m² for sectional title only.',
  `presentations_default_insurance_per_million_zar` smallint(5) unsigned NOT NULL DEFAULT 200 COMMENT 'Monthly building insurance per R1M of property value.',
  `presentations_default_utilities_zar` smallint(5) unsigned NOT NULL DEFAULT 1200 COMMENT 'Flat monthly utilities estimate.',
  `presentations_default_opportunity_cost_pct` decimal(5,2) NOT NULL DEFAULT 8.00 COMMENT 'Annual % return on net equity; divided by 12 for monthly opportunity cost.',
  `presentations_default_garden_zar` smallint(5) unsigned NOT NULL DEFAULT 800 COMMENT 'Freehold garden service — Tier 2 default monthly Rands.',
  `presentations_default_pool_zar` smallint(5) unsigned NOT NULL DEFAULT 600 COMMENT 'Freehold pool service — Tier 2 default monthly Rands.',
  `presentations_default_security_zar` smallint(5) unsigned NOT NULL DEFAULT 1500 COMMENT 'Freehold security/estate fees — Tier 2 default monthly Rands.',
  `snapshot_link_default_expiry_days` smallint(5) unsigned NOT NULL DEFAULT 21 COMMENT 'Default expiry window for /p/{token} share links.',
  `snapshot_link_ip_masking` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'When true, store IPs masked to /24 (POPIA-respectful). Opt-out only when fraud investigation requires it.',
  `presentation_staleness_days` smallint(5) unsigned NOT NULL DEFAULT 21 COMMENT 'Days after issue before public viewer shows the data-may-be-dated banner. Range 7-90 enforced in app layer.',
  `teaser_default_show_suburb_stats` tinyint(1) NOT NULL DEFAULT 1,
  `teaser_default_show_market_position` tinyint(1) NOT NULL DEFAULT 0,
  `teaser_default_show_asking_range` tinyint(1) NOT NULL DEFAULT 1,
  `teaser_default_show_holding_cost_summary` tinyint(1) NOT NULL DEFAULT 0,
  `email_default_subject_template` varchar(300) DEFAULT NULL,
  `email_default_body_template` text DEFAULT NULL,
  `whatsapp_default_template` text DEFAULT NULL,
  `dashboard_settings_mode` varchar(10) NOT NULL DEFAULT 'user' COMMENT 'user = individual settings, agency = shared agency settings',
  `split_branches_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `default_branch_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `paye_registration_no` varchar(20) DEFAULT NULL,
  `uif_employer_no` varchar(20) DEFAULT NULL,
  `sdl_registration_no` varchar(20) DEFAULT NULL,
  `employer_bank_name` varchar(100) DEFAULT NULL,
  `employer_bank_account` varchar(30) DEFAULT NULL,
  `employer_bank_branch_code` varchar(10) DEFAULT NULL,
  `feedback_recipients` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'JSON array of email addresses to receive feedback reports' CHECK (json_valid(`feedback_recipients`)),
  `whistleblow_approver_user_ids` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`whistleblow_approver_user_ids`)),
  `whistleblow_compliance_officer_email` varchar(255) DEFAULT NULL,
  `whistleblow_tier_recipients` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`whistleblow_tier_recipients`)),
  `pp_locations_synced_at` timestamp NULL DEFAULT NULL,
  `pp_locations_last_error` text DEFAULT NULL,
  `competitor_stock_min_same_type` tinyint(3) unsigned NOT NULL DEFAULT 5 COMMENT 'Competitor Stock — minimum exact-property-type matches before stepping up to same-family-other-type. Level 1 (FH/SS) is never crossed.',
  `competitor_stock_default_display_count` tinyint(3) unsigned NOT NULL DEFAULT 10 COMMENT 'Competitor Stock — top-N display cap on the review screen + auto-tick floor. Rest live in the manual-picker modal.',
  `presentations_map_provider` varchar(32) NOT NULL DEFAULT 'svg_radial' COMMENT 'Presentation PDF map renderer: svg_radial (polar diagram, self-contained) or static_image (Google Static Maps PNG, requires API key).',
  `website_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `website_url` varchar(255) DEFAULT NULL,
  `website_tagline` varchar(255) DEFAULT NULL,
  `website_about` text DEFAULT NULL,
  `website_social_facebook` varchar(255) DEFAULT NULL,
  `website_social_instagram` varchar(255) DEFAULT NULL,
  `website_social_linkedin` varchar(255) DEFAULT NULL,
  `website_social_youtube` varchar(255) DEFAULT NULL,
  `website_contact_email` varchar(255) DEFAULT NULL,
  `website_contact_phone` varchar(255) DEFAULT NULL,
  `website_show_agents` tinyint(1) NOT NULL DEFAULT 1,
  `website_show_listings` tinyint(1) NOT NULL DEFAULT 1,
  `website_agent_order_mode` varchar(255) NOT NULL DEFAULT 'alphabetical',
  PRIMARY KEY (`id`),
  UNIQUE KEY `agencies_slug_unique` (`slug`),
  UNIQUE KEY `agencies_privacy_policy_token_unique` (`privacy_policy_token`),
  KEY `agencies_default_branch_id_foreign` (`default_branch_id`),
  KEY `agencies_req_ext_auth_idx` (`require_external_access_authorization`),
  CONSTRAINT `agencies_default_branch_id_foreign` FOREIGN KEY (`default_branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `agency_access_request_admins`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `agency_access_request_admins` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `request_id` bigint(20) unsigned NOT NULL,
  `admin_user_id` bigint(20) unsigned NOT NULL,
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `agency_access_requests` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `target_agency_id` bigint(20) unsigned NOT NULL,
  `requester_user_id` bigint(20) unsigned NOT NULL,
  `requester_role` varchar(255) NOT NULL,
  `status` enum('pending','approved','denied','expired','cancelled') NOT NULL DEFAULT 'pending',
  `reason` text DEFAULT NULL,
  `denial_reason` text DEFAULT NULL,
  `authorized_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `authorized_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NOT NULL DEFAULT current_timestamp(),
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `agency_api_keys` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint(20) unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  `key_prefix` varchar(24) NOT NULL,
  `secret_hash` varchar(255) NOT NULL,
  `scopes` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`scopes`)),
  `webhook_url` varchar(255) DEFAULT NULL,
  `webhook_secret` text DEFAULT NULL,
  `rate_limit_per_min` int(10) unsigned NOT NULL DEFAULT 120,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `revoked_at` timestamp NULL DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `agency_compliance_provisions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint(20) unsigned NOT NULL,
  `provision_type` varchar(50) NOT NULL,
  `document_type_config_id` bigint(20) unsigned DEFAULT NULL,
  `branch_id` bigint(20) unsigned DEFAULT NULL,
  `status` enum('active','expired','superseded') NOT NULL DEFAULT 'active',
  `document_path` varchar(500) DEFAULT NULL,
  `document_original_name` varchar(500) DEFAULT NULL,
  `policy_reference` varchar(200) DEFAULT NULL,
  `effective_from` date NOT NULL,
  `effective_until` date DEFAULT NULL,
  `applies_to_roles` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`applies_to_roles`)),
  `applies_to_branches` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`applies_to_branches`)),
  `notes` text DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
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
  CONSTRAINT `agency_compliance_provisions_document_type_config_id_foreign` FOREIGN KEY (`document_type_config_id`) REFERENCES `agency_document_type_configs` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `agency_contact_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `agency_contact_settings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint(20) unsigned NOT NULL,
  `sharing_mode` enum('open','branch','closed') NOT NULL DEFAULT 'branch',
  `buyer_pipeline_default_scope` enum('own','branch','agency') NOT NULL DEFAULT 'own' COMMENT 'Default pipeline view scope for agents. Independent of contact access.',
  `duplicate_mode` enum('auto_link','soft_warn','hard_block_override','hard_block_request') NOT NULL DEFAULT 'soft_warn',
  `duplicate_match_fields` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`duplicate_match_fields`)),
  `buyer_warm_days` int(10) unsigned NOT NULL DEFAULT 14,
  `buyer_cold_days` int(10) unsigned NOT NULL DEFAULT 30,
  `buyer_lost_days` int(10) unsigned NOT NULL DEFAULT 60,
  `contact_retention_years` int(10) unsigned NOT NULL DEFAULT 5,
  `consent_retention_years` int(10) unsigned NOT NULL DEFAULT 5,
  `access_log_retention_years` int(10) unsigned NOT NULL DEFAULT 5,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `agency_contact_settings_agency_id_unique` (`agency_id`),
  CONSTRAINT `agency_contact_settings_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `agency_dashboard_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `agency_dashboard_settings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint(20) unsigned NOT NULL,
  `idle_alerts_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `idle_threshold_days` smallint(5) unsigned NOT NULL DEFAULT 14,
  `idle_alert_day` varchar(20) DEFAULT NULL,
  `idle_alert_time` time NOT NULL DEFAULT '08:00:00',
  `doc_reminders_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `doc_reminder_hours_before` smallint(5) unsigned NOT NULL DEFAULT 24,
  `lease_expiry_reminders` tinyint(1) NOT NULL DEFAULT 1,
  `lease_reminder_days_before` smallint(5) unsigned NOT NULL DEFAULT 90,
  `fica_reminders` tinyint(1) NOT NULL DEFAULT 1,
  `ffc_reminders` tinyint(1) NOT NULL DEFAULT 1,
  `task_due_reminders` tinyint(1) NOT NULL DEFAULT 1,
  `task_reminder_hours_before` smallint(5) unsigned NOT NULL DEFAULT 4,
  `event_reminder_hours_before` smallint(5) unsigned NOT NULL DEFAULT 24,
  `auto_archive_done_days` smallint(5) unsigned DEFAULT NULL,
  `overdue_daily_digest` tinyint(1) NOT NULL DEFAULT 1,
  `digest_time` time NOT NULL DEFAULT '08:00:00',
  `default_calendar_view` varchar(20) NOT NULL DEFAULT 'month',
  `weekend_visible` tinyint(1) NOT NULL DEFAULT 0,
  `working_hours_start` time NOT NULL DEFAULT '08:00:00',
  `working_hours_end` time NOT NULL DEFAULT '17:00:00',
  `notify_in_app` tinyint(1) NOT NULL DEFAULT 1,
  `notify_email` tinyint(1) NOT NULL DEFAULT 1,
  `notify_push` tinyint(1) NOT NULL DEFAULT 1,
  `open_hours_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `open_hours_start` time NOT NULL DEFAULT '07:00:00',
  `open_hours_end` time NOT NULL DEFAULT '21:00:00',
  `min_minutes_between_same` smallint(5) unsigned NOT NULL DEFAULT 360,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `agency_dashboard_settings_agency_id_unique` (`agency_id`),
  CONSTRAINT `agency_dashboard_settings_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `agency_document_type_configs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `agency_document_type_configs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint(20) unsigned NOT NULL,
  `name` varchar(100) NOT NULL,
  `slug` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `has_expiry` tinyint(1) NOT NULL DEFAULT 1,
  `renewal_days` int(10) unsigned DEFAULT NULL,
  `required` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(10) unsigned NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `agency_document_type_configs_agency_id_slug_unique` (`agency_id`,`slug`),
  KEY `agency_document_type_configs_agency_id_is_active_index` (`agency_id`,`is_active`),
  CONSTRAINT `agency_document_type_configs_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `agency_feedback_options`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `agency_feedback_options` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint(20) unsigned DEFAULT NULL,
  `category` varchar(255) NOT NULL,
  `label` varchar(255) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_system_default` tinyint(1) NOT NULL DEFAULT 0,
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `agency_leave_visibility_matrix` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint(20) unsigned NOT NULL,
  `viewing_role` varchar(50) NOT NULL,
  `leave_owner_role` varchar(50) NOT NULL,
  `same_branch_only` tinyint(1) NOT NULL DEFAULT 1,
  `can_see` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `alvm_agency_viewer_owner_branch_unique` (`agency_id`,`viewing_role`,`leave_owner_role`,`same_branch_only`),
  CONSTRAINT `agency_leave_visibility_matrix_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `agency_lost_deal_reasons`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `agency_lost_deal_reasons` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint(20) unsigned NOT NULL,
  `code` varchar(50) NOT NULL,
  `label` varchar(150) NOT NULL,
  `category` enum('price','location','property','financial','timing','agent_service','competition','other') NOT NULL,
  `applies_to_buyers` tinyint(1) NOT NULL DEFAULT 1,
  `applies_to_sellers` tinyint(1) NOT NULL DEFAULT 0,
  `requires_notes` tinyint(1) NOT NULL DEFAULT 0,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `display_order` smallint(5) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `agency_lost_deal_reasons_agency_id_code_unique` (`agency_id`,`code`),
  CONSTRAINT `agency_lost_deal_reasons_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `agency_signing_parties`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `agency_signing_parties` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint(20) unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `agency_signing_parties_agency_id_deleted_at_index` (`agency_id`,`deleted_at`),
  CONSTRAINT `agency_signing_parties_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `agency_webhook_deliveries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `agency_webhook_deliveries` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint(20) unsigned NOT NULL,
  `agency_api_key_id` bigint(20) unsigned NOT NULL,
  `event_name` varchar(255) NOT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`payload`)),
  `response_status` smallint(5) unsigned DEFAULT NULL,
  `attempts` int(10) unsigned NOT NULL DEFAULT 0,
  `delivered_at` timestamp NULL DEFAULT NULL,
  `next_retry_at` timestamp NULL DEFAULT NULL,
  `failed_at` timestamp NULL DEFAULT NULL,
  `last_error` text DEFAULT NULL,
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `agent_activity_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint(20) unsigned DEFAULT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `event_type` varchar(100) NOT NULL COMMENT 'e.g. claim.created, pitch.sent, whatsapp.sent, feedback.recorded, property.created, mandate.signed',
  `subject_type` varchar(100) DEFAULT NULL,
  `subject_id` bigint(20) unsigned DEFAULT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Event-specific data. Schema varies by event_type — interpret per the listener.' CHECK (json_valid(`payload`)),
  `occurred_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_aae_agency_user_time` (`agency_id`,`user_id`,`occurred_at`),
  KEY `idx_aae_event_time` (`event_type`,`occurred_at`),
  KEY `idx_aae_subject` (`subject_type`,`subject_id`),
  KEY `agent_activity_events_user_id_foreign` (`user_id`),
  CONSTRAINT `agent_activity_events_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE SET NULL,
  CONSTRAINT `agent_activity_events_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Append-only agent activity log. Morphable subject. No updated_at.';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `agent_applications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `agent_applications` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint(20) unsigned NOT NULL,
  `branch_id` bigint(20) unsigned DEFAULT NULL,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `email` varchar(255) NOT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `id_number` varchar(20) DEFAULT NULL,
  `current_agency` varchar(255) DEFAULT NULL,
  `years_experience` int(11) NOT NULL DEFAULT 0,
  `ffc_number` varchar(100) DEFAULT NULL,
  `ffc_expiry` date DEFAULT NULL,
  `ppra_status` varchar(50) DEFAULT NULL,
  `designation` enum('property_practitioner','candidate_practitioner','intern') NOT NULL DEFAULT 'property_practitioner',
  `motivation` text DEFAULT NULL,
  `referral_source` varchar(255) DEFAULT NULL,
  `referred_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `status` enum('applied','documents_pending','compliance_review','mentor_assignment','training','activated','rejected','withdrawn') NOT NULL DEFAULT 'applied',
  `status_changed_at` timestamp NULL DEFAULT NULL,
  `status_notes` text DEFAULT NULL,
  `reviewed_by` bigint(20) unsigned DEFAULT NULL,
  `activated_at` timestamp NULL DEFAULT NULL,
  `activated_by` bigint(20) unsigned DEFAULT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
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
DROP TABLE IF EXISTS `agent_cap_periods`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `agent_cap_periods` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `agency_id` bigint(20) unsigned NOT NULL,
  `period_start` date NOT NULL,
  `period_end` date NOT NULL,
  `cap_amount` decimal(12,2) NOT NULL,
  `company_dollar_paid` decimal(12,2) NOT NULL DEFAULT 0.00,
  `is_capped` tinyint(1) NOT NULL DEFAULT 0,
  `capped_at` timestamp NULL DEFAULT NULL,
  `post_cap_fees_paid` decimal(10,2) NOT NULL DEFAULT 0.00,
  `risk_fees_paid` decimal(10,2) NOT NULL DEFAULT 0.00,
  `transactions_count` int(11) NOT NULL DEFAULT 0,
  `transactions_mentored` int(11) NOT NULL DEFAULT 0,
  `gross_commission_income` decimal(14,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `agent_cap_periods_user_id_period_start_index` (`user_id`,`period_start`),
  KEY `agent_cap_periods_agency_id_foreign` (`agency_id`),
  CONSTRAINT `agent_cap_periods_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `agent_cap_periods_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `agent_mentors`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `agent_mentors` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `mentee_user_id` bigint(20) unsigned NOT NULL,
  `agency_id` bigint(20) unsigned NOT NULL,
  `mentor_user_id` bigint(20) unsigned NOT NULL,
  `assigned_at` date NOT NULL,
  `graduated_at` date DEFAULT NULL,
  `transactions_completed` int(11) NOT NULL DEFAULT 0,
  `transactions_required` int(11) NOT NULL DEFAULT 3,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `agent_overrides` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint(20) unsigned NOT NULL,
  `presentation_version_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `override_type` enum('comp_excluded','comp_included','category_added','category_removed','condition_changed','section_toggled','field_edited','review_takeover','comp_unavailable') NOT NULL,
  `target_id` varchar(64) DEFAULT NULL,
  `before_value` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`before_value`)),
  `after_value` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`after_value`)),
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `agent_scorecards` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `agency_id` bigint(20) unsigned NOT NULL,
  `period_type` varchar(20) NOT NULL COMMENT 'daily, weekly, monthly',
  `period_start` date NOT NULL,
  `period_end` date NOT NULL,
  `tasks_completed` int(10) unsigned NOT NULL DEFAULT 0,
  `tasks_overdue` int(10) unsigned NOT NULL DEFAULT 0,
  `tasks_total` int(10) unsigned NOT NULL DEFAULT 0,
  `properties_attended` int(10) unsigned NOT NULL DEFAULT 0,
  `properties_total` int(10) unsigned NOT NULL DEFAULT 0,
  `documents_uploaded` int(10) unsigned NOT NULL DEFAULT 0,
  `fica_complete` int(10) unsigned NOT NULL DEFAULT 0,
  `fica_total` int(10) unsigned NOT NULL DEFAULT 0,
  `avg_response_hours` decimal(8,2) NOT NULL DEFAULT 0.00,
  `deals_progressed` int(10) unsigned NOT NULL DEFAULT 0,
  `events_completed` int(10) unsigned NOT NULL DEFAULT 0,
  `events_total` int(10) unsigned NOT NULL DEFAULT 0,
  `activity_points` int(10) unsigned NOT NULL DEFAULT 0,
  `overall_score` tinyint(3) unsigned NOT NULL DEFAULT 0 COMMENT '0-100',
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
DROP TABLE IF EXISTS `agent_social_accounts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `agent_social_accounts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `agency_id` bigint(20) unsigned NOT NULL,
  `platform` enum('facebook','instagram') NOT NULL,
  `platform_page_id` varchar(255) NOT NULL,
  `platform_page_name` varchar(255) NOT NULL,
  `access_token` text NOT NULL,
  `token_expires_at` timestamp NULL DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `agent_sponsorships` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `agent_user_id` bigint(20) unsigned NOT NULL,
  `agency_id` bigint(20) unsigned NOT NULL,
  `sponsor_user_id` bigint(20) unsigned NOT NULL,
  `sponsored_at` date NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `notes` text DEFAULT NULL,
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ai_conversations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `title` varchar(255) DEFAULT NULL,
  `status` varchar(255) NOT NULL DEFAULT 'active',
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ai_daily_briefings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `briefing_date` date NOT NULL,
  `content` text NOT NULL,
  `data_snapshot` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`data_snapshot`)),
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ai_feedback` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `message_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `rating` enum('up','down') NOT NULL,
  `comment` text DEFAULT NULL,
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ai_messages` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `conversation_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `role` varchar(20) NOT NULL,
  `content` longtext NOT NULL,
  `prompt_tokens` int(10) unsigned DEFAULT NULL,
  `completion_tokens` int(10) unsigned DEFAULT NULL,
  `total_tokens` int(10) unsigned DEFAULT NULL,
  `cost_cents` int(10) unsigned DEFAULT NULL,
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ai_narrative_cache` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint(20) unsigned DEFAULT NULL,
  `narrative_type` varchar(50) NOT NULL COMMENT 'weekly_brief | tile_copy | listing_tooltip | suburb_pocket | audit_finding',
  `cache_key` varchar(255) NOT NULL COMMENT 'Composed deterministically, e.g. weekly_brief:agency:1:week:2026-21',
  `input_hash` varchar(64) NOT NULL COMMENT 'sha256 of the input data — mismatch forces regeneration.',
  `prompt_version` varchar(20) NOT NULL COMMENT 'Track prompt evolution for A/B comparison.',
  `model` varchar(50) NOT NULL COMMENT 'e.g. claude-haiku-4-5, claude-sonnet-4-6',
  `input_tokens` int(11) NOT NULL DEFAULT 0,
  `output_tokens` int(11) NOT NULL DEFAULT 0,
  `cost_zar` decimal(10,4) NOT NULL DEFAULT 0.0000,
  `output_text` text NOT NULL,
  `output_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'When structured output required.' CHECK (json_valid(`output_json`)),
  `generated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `expires_at` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
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
DROP TABLE IF EXISTS `amendment_acceptances`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `amendment_acceptances` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `amendment_id` bigint(20) unsigned NOT NULL,
  `signature_request_id` bigint(20) unsigned NOT NULL,
  `accepted` tinyint(1) NOT NULL DEFAULT 0,
  `rejected` tinyint(1) NOT NULL DEFAULT 0,
  `rejection_reason` text DEFAULT NULL,
  `initial_image` text DEFAULT NULL,
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `application_documents` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `application_id` bigint(20) unsigned NOT NULL,
  `document_type` enum('id_copy','ffc_certificate','qualifications','pi_insurance','tax_clearance','proof_of_address','cv','other') NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `status` enum('uploaded','verified','rejected') NOT NULL DEFAULT 'uploaded',
  `rejection_reason` varchar(500) DEFAULT NULL,
  `verified_by` bigint(20) unsigned DEFAULT NULL,
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `article_pool` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `source` varchar(255) NOT NULL,
  `title` varchar(255) NOT NULL,
  `url` text NOT NULL,
  `url_hash` char(64) NOT NULL,
  `snippet` text DEFAULT NULL,
  `published_at` timestamp NULL DEFAULT NULL,
  `tags_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`tags_json`)),
  `scraped_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `article_pool_url_hash_unique` (`url_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `automation_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `automation_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `rule_id` bigint(20) unsigned NOT NULL,
  `trigger_model_type` varchar(120) NOT NULL,
  `trigger_model_id` bigint(20) unsigned NOT NULL,
  `action_type` varchar(50) NOT NULL,
  `action_result_type` varchar(120) DEFAULT NULL,
  `action_result_id` bigint(20) unsigned DEFAULT NULL,
  `executed_at` datetime NOT NULL,
  `success` tinyint(1) NOT NULL DEFAULT 1,
  `error_message` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `automation_log_rule_id_foreign` (`rule_id`),
  CONSTRAINT `automation_log_rule_id_foreign` FOREIGN KEY (`rule_id`) REFERENCES `automation_rules` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `automation_rules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `automation_rules` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `is_system` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'System rules cannot be deleted',
  `trigger_model` varchar(120) NOT NULL COMMENT 'Property, Contact, DealV2, User, etc.',
  `trigger_event` varchar(80) NOT NULL COMMENT 'created, updated, status_changed, date_approaching, idle',
  `trigger_conditions` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`trigger_conditions`)),
  `action_type` varchar(50) NOT NULL COMMENT 'create_event, create_task, send_notification, create_event_and_task',
  `action_config` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`action_config`)),
  `agency_id` bigint(20) unsigned DEFAULT NULL,
  `branch_id` bigint(20) unsigned DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
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
DROP TABLE IF EXISTS `bedroom_segments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `bedroom_segments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint(20) unsigned NOT NULL,
  `name` varchar(50) NOT NULL,
  `beds_min` tinyint(3) unsigned NOT NULL,
  `beds_max` tinyint(3) unsigned DEFAULT NULL,
  `display_order` smallint(5) unsigned NOT NULL DEFAULT 0,
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `branch_activity_columns` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `branch_id` bigint(20) unsigned NOT NULL,
  `agency_id` bigint(20) unsigned NOT NULL,
  `key` varchar(255) NOT NULL,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(10) unsigned DEFAULT NULL,
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `branch_assignments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `branch_id` bigint(20) unsigned NOT NULL,
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `branch_settings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `branch_id` bigint(20) unsigned NOT NULL,
  `agency_id` bigint(20) unsigned NOT NULL,
  `key` varchar(255) NOT NULL,
  `value` varchar(255) DEFAULT NULL,
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `branches` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint(20) unsigned DEFAULT NULL,
  `trading_name` varchar(255) DEFAULT NULL,
  `tagline` varchar(255) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `phone` varchar(255) DEFAULT NULL,
  `phone_label` varchar(100) DEFAULT NULL,
  `phone_secondary` varchar(255) DEFAULT NULL,
  `phone_secondary_label` varchar(100) DEFAULT NULL,
  `fax` varchar(255) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `reg_no` varchar(255) DEFAULT NULL,
  `vat_no` varchar(255) DEFAULT NULL,
  `ffc_no` varchar(255) DEFAULT NULL,
  `ppra_number` varchar(32) DEFAULT NULL,
  `fic_no` varchar(255) DEFAULT NULL,
  `p24_agency_id` varchar(32) DEFAULT NULL,
  `syndication_override_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `pp_agency_id` varchar(255) DEFAULT NULL,
  `pp_credentials` text DEFAULT NULL,
  `p24_credentials` text DEFAULT NULL,
  `logo_path` varchar(255) DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `code` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `privacy_policy_markdown` longtext DEFAULT NULL,
  `privacy_policy_token` varchar(64) DEFAULT NULL,
  `privacy_policy_published_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `branches_privacy_policy_token_unique` (`privacy_policy_token`),
  KEY `branches_agency_id_foreign` (`agency_id`),
  CONSTRAINT `branches_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `buyer_activity_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `buyer_activity_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `contact_id` bigint(20) unsigned NOT NULL,
  `agency_id` bigint(20) unsigned NOT NULL,
  `activity_type` enum('viewing_completed','presentation','contact_access','note_added','call_logged','email_sent','whatsapp_sent','manual','retention_action','feedback_captured') NOT NULL,
  `activity_date` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `related_event_id` bigint(20) unsigned DEFAULT NULL,
  `related_property_id` bigint(20) unsigned DEFAULT NULL,
  `related_feedback_id` bigint(20) unsigned DEFAULT NULL,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata`)),
  `logged_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
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
DROP TABLE IF EXISTS `buyer_lost_records`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `buyer_lost_records` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `contact_id` bigint(20) unsigned NOT NULL,
  `agency_id` bigint(20) unsigned NOT NULL,
  `reason_code` varchar(50) NOT NULL,
  `reason_label` varchar(150) NOT NULL,
  `notes` text DEFAULT NULL,
  `outcome` text DEFAULT NULL,
  `recorded_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `recorded_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `source` varchar(30) NOT NULL DEFAULT 'manual',
  `buyer_state_at_loss` varchar(20) DEFAULT NULL,
  `days_in_pipeline_at_loss` int(10) unsigned DEFAULT NULL,
  `days_since_last_activity_at_loss` int(10) unsigned DEFAULT NULL,
  `agent_owner_user_id_at_loss` bigint(20) unsigned DEFAULT NULL,
  `branch_id_at_loss` bigint(20) unsigned DEFAULT NULL,
  `preapproval_amount_at_loss` decimal(14,2) DEFAULT NULL,
  `recovered_at` timestamp NULL DEFAULT NULL,
  `recovered_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `recovered_notes` text DEFAULT NULL,
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `buyer_lost_risk_scores` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `contact_id` bigint(20) unsigned NOT NULL,
  `agency_id` bigint(20) unsigned NOT NULL,
  `score` smallint(5) unsigned NOT NULL,
  `factors_breakdown` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`factors_breakdown`)),
  `computed_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `buyer_lost_risk_scores_contact_id_computed_at_index` (`contact_id`,`computed_at`),
  KEY `buyer_lost_risk_scores_agency_id_idx` (`agency_id`),
  CONSTRAINT `buyer_lost_risk_scores_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `buyer_lost_risk_scores_contact_id_foreign` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `buyer_match_tiers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `buyer_match_tiers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint(20) unsigned NOT NULL,
  `strong_min_score` tinyint(3) unsigned NOT NULL DEFAULT 80,
  `mid_min_score` tinyint(3) unsigned NOT NULL DEFAULT 50,
  `weak_min_score` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `strong_label` varchar(30) NOT NULL DEFAULT 'Strong',
  `mid_label` varchar(30) NOT NULL DEFAULT 'Mid',
  `weak_label` varchar(30) NOT NULL DEFAULT 'Weak',
  `show_weak_in_badge` tinyint(1) NOT NULL DEFAULT 1,
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `buyer_portal_links` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `contact_id` bigint(20) unsigned NOT NULL,
  `agency_id` bigint(20) unsigned NOT NULL,
  `token` varchar(64) NOT NULL,
  `generated_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `generated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `last_accessed_at` timestamp NULL DEFAULT NULL,
  `access_count` int(10) unsigned NOT NULL DEFAULT 0,
  `revoked_at` timestamp NULL DEFAULT NULL,
  `revoked_by_user_id` bigint(20) unsigned DEFAULT NULL,
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `buyer_preferences` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `contact_id` bigint(20) unsigned NOT NULL,
  `agency_id` bigint(20) unsigned NOT NULL,
  `budget_min` decimal(14,2) DEFAULT NULL,
  `budget_max` decimal(14,2) DEFAULT NULL,
  `bedrooms_min` smallint(5) unsigned DEFAULT NULL,
  `bedrooms_max` smallint(5) unsigned DEFAULT NULL,
  `must_have_features` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`must_have_features`)),
  `deal_breakers` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`deal_breakers`)),
  `preapproval_amount` decimal(14,2) DEFAULT NULL COMMENT 'Pre-approved amount in ZAR',
  `preapproval_expires_at` date DEFAULT NULL,
  `preapproval_institution` varchar(100) DEFAULT NULL,
  `preferred_areas` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`preferred_areas`)),
  `preferred_property_types` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`preferred_property_types`)),
  `updated_by_user_id` bigint(20) unsigned DEFAULT NULL,
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `buyer_property_responses` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `contact_id` bigint(20) unsigned NOT NULL,
  `agency_id` bigint(20) unsigned NOT NULL,
  `property_id` bigint(20) unsigned NOT NULL,
  `response` enum('interested','not_interested','viewing_requested') NOT NULL,
  `reason` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `source` varchar(30) NOT NULL DEFAULT 'buyer_portal',
  `responded_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `buyer_property_views` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `contact_id` bigint(20) unsigned NOT NULL,
  `agency_id` bigint(20) unsigned NOT NULL,
  `property_id` bigint(20) unsigned NOT NULL,
  `last_viewed_at` timestamp NULL DEFAULT NULL,
  `view_count` int(10) unsigned NOT NULL DEFAULT 0,
  `most_recent_feedback_id` bigint(20) unsigned DEFAULT NULL,
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `buyer_state_transitions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `contact_id` bigint(20) unsigned NOT NULL,
  `agency_id` bigint(20) unsigned NOT NULL,
  `from_state` varchar(20) DEFAULT NULL,
  `to_state` varchar(20) NOT NULL,
  `reason` enum('auto_recompute','manual_override','first_activity') NOT NULL,
  `triggered_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `occurred_at` timestamp NOT NULL DEFAULT current_timestamp(),
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cache` (
  `key` varchar(255) NOT NULL,
  `value` mediumtext NOT NULL,
  `expiration` int(11) NOT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cache_locks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cache_locks` (
  `key` varchar(255) NOT NULL,
  `owner` varchar(255) NOT NULL,
  `expiration` int(11) NOT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `calculator_fee_scales`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `calculator_fee_scales` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `type` varchar(255) NOT NULL,
  `brackets` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`brackets`)),
  `source_document` varchar(255) DEFAULT NULL,
  `effective_date` date DEFAULT NULL,
  `additional_costs_note` text DEFAULT NULL,
  `uploaded_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `calculator_fee_scales_uploaded_by_foreign` (`uploaded_by`),
  CONSTRAINT `calculator_fee_scales_uploaded_by_foreign` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `calendar_event_audit_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `calendar_event_audit_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `calendar_event_id` bigint(20) unsigned NOT NULL,
  `agency_id` bigint(20) unsigned NOT NULL,
  `action` varchar(255) NOT NULL,
  `old_values` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`old_values`)),
  `new_values` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`new_values`)),
  `performed_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `performed_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `calendar_event_audit_log_performed_by_user_id_foreign` (`performed_by_user_id`),
  KEY `cea_event_time_idx` (`calendar_event_id`,`performed_at`),
  KEY `calendar_event_audit_log_agency_id_idx` (`agency_id`),
  CONSTRAINT `calendar_event_audit_log_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `calendar_event_audit_log_calendar_event_id_foreign` FOREIGN KEY (`calendar_event_id`) REFERENCES `calendar_events` (`id`) ON DELETE CASCADE,
  CONSTRAINT `calendar_event_audit_log_performed_by_user_id_foreign` FOREIGN KEY (`performed_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `calendar_event_class_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `calendar_event_class_settings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint(20) unsigned DEFAULT NULL,
  `event_class` varchar(60) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `event_nature` varchar(20) NOT NULL DEFAULT 'actionable',
  `green_days` smallint(5) unsigned NOT NULL,
  `amber_days` smallint(5) unsigned NOT NULL,
  `red_days` smallint(5) unsigned NOT NULL,
  `show_days` smallint(5) unsigned DEFAULT NULL,
  `green_visibility` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`green_visibility`)),
  `amber_visibility` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`amber_visibility`)),
  `red_visibility` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`red_visibility`)),
  `green_notifications` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`green_notifications`)),
  `amber_notifications` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`amber_notifications`)),
  `red_notifications` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`red_notifications`)),
  `daily_digest_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `daily_digest_roles` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`daily_digest_roles`)),
  `allow_multiple_properties` tinyint(1) NOT NULL DEFAULT 0,
  `buyer_facing` tinyint(1) NOT NULL DEFAULT 0,
  `actor_role` varchar(20) NOT NULL DEFAULT 'neither',
  `completion_behaviour` varchar(20) NOT NULL DEFAULT 'freeform',
  `feedback_mode` varchar(30) NOT NULL DEFAULT 'per_contact',
  `label` varchar(100) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `calendar_event_feedback` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `calendar_event_id` bigint(20) unsigned NOT NULL,
  `contact_id` bigint(20) unsigned DEFAULT NULL,
  `feedback_kind` varchar(30) NOT NULL DEFAULT 'viewing',
  `property_id` bigint(20) unsigned DEFAULT NULL,
  `outcome_option_id` bigint(20) unsigned DEFAULT NULL,
  `concern_option_ids` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`concern_option_ids`)),
  `seller_visible_notes` text DEFAULT NULL,
  `internal_notes` text DEFAULT NULL,
  `next_action_notes` text DEFAULT NULL,
  `captured_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `captured_at` timestamp NULL DEFAULT NULL,
  `agency_id` bigint(20) unsigned NOT NULL,
  `branch_id` bigint(20) unsigned DEFAULT NULL,
  `visibility` varchar(30) NOT NULL DEFAULT 'public_to_seller',
  `kind_specific_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`kind_specific_data`)),
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `calendar_event_invitations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `event_id` bigint(20) unsigned NOT NULL,
  `agency_id` bigint(20) unsigned NOT NULL,
  `invitee_user_id` bigint(20) unsigned NOT NULL,
  `inviter_user_id` bigint(20) unsigned NOT NULL,
  `status` enum('pending','accepted','tentative','declined','cancelled') NOT NULL DEFAULT 'pending',
  `acknowledged_at` timestamp NULL DEFAULT NULL,
  `response_at` timestamp NULL DEFAULT NULL,
  `response_notes` text DEFAULT NULL,
  `conflict_at_invite` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`conflict_at_invite`)),
  `notified_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `calendar_event_invitations_event_id_invitee_user_id_unique` (`event_id`,`invitee_user_id`),
  KEY `calendar_event_invitations_inviter_user_id_foreign` (`inviter_user_id`),
  KEY `calendar_event_invitations_invitee_user_id_status_index` (`invitee_user_id`,`status`),
  KEY `calendar_event_invitations_agency_id_idx` (`agency_id`),
  CONSTRAINT `calendar_event_invitations_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `calendar_event_invitations_event_id_foreign` FOREIGN KEY (`event_id`) REFERENCES `calendar_events` (`id`) ON DELETE CASCADE,
  CONSTRAINT `calendar_event_invitations_invitee_user_id_foreign` FOREIGN KEY (`invitee_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `calendar_event_invitations_inviter_user_id_foreign` FOREIGN KEY (`inviter_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `calendar_event_links`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `calendar_event_links` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `calendar_event_id` bigint(20) unsigned NOT NULL,
  `agency_id` bigint(20) unsigned NOT NULL,
  `linkable_type` varchar(255) NOT NULL,
  `linkable_id` bigint(20) unsigned NOT NULL,
  `role` varchar(255) NOT NULL DEFAULT 'attendee',
  `created_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `cel_event_linkable_role_unique` (`calendar_event_id`,`linkable_type`,`linkable_id`,`role`),
  KEY `calendar_event_links_created_by_user_id_foreign` (`created_by_user_id`),
  KEY `cel_linkable_idx` (`linkable_type`,`linkable_id`),
  KEY `calendar_event_links_agency_id_idx` (`agency_id`),
  CONSTRAINT `calendar_event_links_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `calendar_event_links_calendar_event_id_foreign` FOREIGN KEY (`calendar_event_id`) REFERENCES `calendar_events` (`id`) ON DELETE CASCADE,
  CONSTRAINT `calendar_event_links_created_by_user_id_foreign` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `calendar_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `calendar_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `created_by_id` bigint(20) unsigned DEFAULT NULL,
  `created_by_ai` tinyint(1) NOT NULL DEFAULT 0,
  `ai_source` varchar(32) DEFAULT NULL COMMENT 'ellie_voice, ellie_chat, future sources',
  `ai_transcript` text DEFAULT NULL COMMENT 'Raw voice transcript or AI input for audit',
  `event_type` varchar(50) NOT NULL COMMENT 'deal, lease, compliance, document, prospecting, portal, property, manual',
  `category` varchar(80) DEFAULT NULL COMMENT 'Sub-type: bond_deadline, lease_expiry, ffc_expiry, viewing, etc.',
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `event_date` datetime NOT NULL,
  `end_date` datetime DEFAULT NULL,
  `all_day` tinyint(1) NOT NULL DEFAULT 1,
  `priority` varchar(20) NOT NULL DEFAULT 'normal' COMMENT 'low, normal, high, critical',
  `send_reminder` tinyint(1) NOT NULL DEFAULT 1,
  `status` varchar(20) NOT NULL DEFAULT 'pending' COMMENT 'pending, completed, overdue, dismissed',
  `completion_reason_code` varchar(50) DEFAULT NULL,
  `completion_reason` text DEFAULT NULL,
  `resolution` varchar(30) DEFAULT NULL COMMENT 'completed, extended, did_not_happen',
  `resolution_note` text DEFAULT NULL,
  `colour` varchar(7) DEFAULT NULL COMMENT 'Hex colour, auto-set from event_type if null',
  `source_type` varchar(255) DEFAULT NULL,
  `source_id` bigint(20) unsigned DEFAULT NULL,
  `property_id` bigint(20) unsigned DEFAULT NULL,
  `contact_id` bigint(20) unsigned DEFAULT NULL,
  `branch_id` bigint(20) unsigned DEFAULT NULL,
  `agency_id` bigint(20) unsigned DEFAULT NULL,
  `reminder_offsets` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Array of offsets in minutes' CHECK (json_valid(`reminder_offsets`)),
  `reminders_sent` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Tracks which offsets have been sent' CHECK (json_valid(`reminders_sent`)),
  `is_recurring` tinyint(1) NOT NULL DEFAULT 0,
  `recurrence_rule` varchar(255) DEFAULT NULL COMMENT 'RRULE format',
  `parent_event_id` bigint(20) unsigned DEFAULT NULL,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata`)),
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `calendar_reminders_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `calendar_event_id` bigint(20) unsigned NOT NULL,
  `agency_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `channel` varchar(20) NOT NULL COMMENT 'app, email, sms',
  `offset_minutes` int(11) NOT NULL,
  `sent_at` datetime NOT NULL,
  `read_at` datetime DEFAULT NULL,
  `actioned_at` datetime DEFAULT NULL,
  `escalated` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `calendar_reminders_log_calendar_event_id_foreign` (`calendar_event_id`),
  KEY `calendar_reminders_log_user_id_foreign` (`user_id`),
  KEY `calendar_reminders_log_agency_id_idx` (`agency_id`),
  CONSTRAINT `calendar_reminders_log_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `calendar_reminders_log_calendar_event_id_foreign` FOREIGN KEY (`calendar_event_id`) REFERENCES `calendar_events` (`id`) ON DELETE CASCADE,
  CONSTRAINT `calendar_reminders_log_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `calendar_user_preferences`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `calendar_user_preferences` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `default_view` varchar(20) NOT NULL DEFAULT 'month',
  `working_hours_start` time NOT NULL DEFAULT '08:00:00',
  `working_hours_end` time NOT NULL DEFAULT '17:00:00',
  `weekend_visible` tinyint(1) NOT NULL DEFAULT 0,
  `ical_token` varchar(64) DEFAULT NULL,
  `email_reminders` tinyint(1) NOT NULL DEFAULT 1,
  `app_reminders` tinyint(1) NOT NULL DEFAULT 1,
  `digest_email` varchar(20) NOT NULL DEFAULT 'daily' COMMENT 'none, daily, weekly',
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cds_drafts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `agency_id` bigint(20) unsigned DEFAULT NULL,
  `template_name` varchar(255) NOT NULL,
  `cds_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`cds_json`)),
  `tags` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`tags`)),
  `mappings` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`mappings`)),
  `tagged_html` longtext DEFAULT NULL,
  `settings` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`settings`)),
  `source_template_id` bigint(20) unsigned DEFAULT NULL,
  `status` varchar(255) NOT NULL DEFAULT 'draft',
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `client_access_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `client_user_id` bigint(20) unsigned DEFAULT NULL,
  `agency_id` bigint(20) unsigned DEFAULT NULL,
  `contact_id` bigint(20) unsigned DEFAULT NULL,
  `event` varchar(64) NOT NULL,
  `meta` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`meta`)),
  `ip` varchar(255) DEFAULT NULL,
  `user_agent` varchar(500) DEFAULT NULL,
  `device_name` varchar(255) DEFAULT NULL,
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `client_otps` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `client_user_id` bigint(20) unsigned DEFAULT NULL,
  `email` varchar(255) NOT NULL,
  `purpose` varchar(255) NOT NULL DEFAULT 'activation',
  `code_hash` varchar(255) NOT NULL,
  `expires_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `used_at` timestamp NULL DEFAULT NULL,
  `attempts` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `ip` varchar(255) DEFAULT NULL,
  `user_agent` varchar(500) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `client_otps_client_user_id_foreign` (`client_user_id`),
  KEY `client_otps_email_used_at_index` (`email`,`used_at`),
  KEY `client_otps_email_index` (`email`),
  CONSTRAINT `client_otps_client_user_id_foreign` FOREIGN KEY (`client_user_id`) REFERENCES `client_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `client_signin_attempts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `client_signin_attempts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `identifier` varchar(255) NOT NULL,
  `matched` tinyint(1) NOT NULL DEFAULT 0,
  `agency_count` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `ip` varchar(255) DEFAULT NULL,
  `user_agent` varchar(500) DEFAULT NULL,
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `client_users` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(255) NOT NULL,
  `password` varchar(255) DEFAULT NULL,
  `password_must_change` tinyint(1) NOT NULL DEFAULT 0,
  `password_set_at` timestamp NULL DEFAULT NULL,
  `activated_at` timestamp NULL DEFAULT NULL,
  `first_login_at` timestamp NULL DEFAULT NULL,
  `last_login_at` timestamp NULL DEFAULT NULL,
  `preferred_agency_id` bigint(20) unsigned DEFAULT NULL,
  `locked_to_agency_id` bigint(20) unsigned DEFAULT NULL,
  `current_agency_id` bigint(20) unsigned DEFAULT NULL,
  `created_by_agency_id` bigint(20) unsigned DEFAULT NULL,
  `last_ip` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `client_users_email_unique` (`email`),
  KEY `client_users_preferred_agency_id_foreign` (`preferred_agency_id`),
  KEY `client_users_locked_to_agency_id_foreign` (`locked_to_agency_id`),
  KEY `client_users_current_agency_id_foreign` (`current_agency_id`),
  KEY `client_users_created_by_agency_id_foreign` (`created_by_agency_id`),
  CONSTRAINT `client_users_created_by_agency_id_foreign` FOREIGN KEY (`created_by_agency_id`) REFERENCES `agencies` (`id`) ON DELETE SET NULL,
  CONSTRAINT `client_users_current_agency_id_foreign` FOREIGN KEY (`current_agency_id`) REFERENCES `agencies` (`id`) ON DELETE SET NULL,
  CONSTRAINT `client_users_locked_to_agency_id_foreign` FOREIGN KEY (`locked_to_agency_id`) REFERENCES `agencies` (`id`) ON DELETE SET NULL,
  CONSTRAINT `client_users_preferred_agency_id_foreign` FOREIGN KEY (`preferred_agency_id`) REFERENCES `agencies` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `command_document_expectations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `command_document_expectations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `property_type` varchar(50) NOT NULL COMMENT 'sale, rental, commercial, vacant_land',
  `document_type_id` bigint(20) unsigned DEFAULT NULL,
  `required` tinyint(1) NOT NULL DEFAULT 1,
  `due_offset_hours` int(10) unsigned NOT NULL DEFAULT 72,
  `label` varchar(255) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `agency_id` bigint(20) unsigned DEFAULT NULL,
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `command_task_notes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `command_task_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `body` text NOT NULL,
  `agency_id` bigint(20) unsigned DEFAULT NULL,
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `command_tasks` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `task_type` varchar(50) NOT NULL COMMENT 'document_upload, follow_up, compliance, review, deal_action, custom',
  `status` varchar(20) NOT NULL DEFAULT 'todo' COMMENT 'todo, in_progress, awaiting, done, dismissed',
  `resolution` varchar(30) DEFAULT NULL COMMENT 'completed, extended, did_not_happen',
  `resolution_note` text DEFAULT NULL,
  `priority` varchar(20) NOT NULL DEFAULT 'normal' COMMENT 'low, normal, high, critical',
  `send_reminder` tinyint(1) NOT NULL DEFAULT 1,
  `assigned_to` bigint(20) unsigned NOT NULL,
  `assigned_by` bigint(20) unsigned DEFAULT NULL,
  `due_date` datetime DEFAULT NULL,
  `started_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `property_id` bigint(20) unsigned DEFAULT NULL,
  `contact_id` bigint(20) unsigned DEFAULT NULL,
  `deal_id` bigint(20) unsigned DEFAULT NULL,
  `source_type` varchar(50) DEFAULT NULL COMMENT 'automation_rule, manual, calendar_event',
  `source_id` bigint(20) unsigned DEFAULT NULL,
  `calendar_event_id` bigint(20) unsigned DEFAULT NULL,
  `checklist` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`checklist`)),
  `notes` text DEFAULT NULL,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata`)),
  `branch_id` bigint(20) unsigned DEFAULT NULL,
  `agency_id` bigint(20) unsigned DEFAULT NULL,
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `commercial_evaluation_assets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `commercial_evaluation_assets` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `commercial_evaluation_id` bigint(20) unsigned NOT NULL,
  `agency_id` bigint(20) unsigned NOT NULL,
  `category` varchar(255) NOT NULL,
  `description` varchar(255) NOT NULL,
  `quantity` int(10) unsigned DEFAULT NULL,
  `estimated_value` bigint(20) DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `commercial_evaluation_comparables` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `commercial_evaluation_id` bigint(20) unsigned NOT NULL,
  `agency_id` bigint(20) unsigned NOT NULL,
  `address` varchar(255) NOT NULL,
  `suburb` varchar(255) DEFAULT NULL,
  `property_type` varchar(255) NOT NULL,
  `size_m2` decimal(12,2) DEFAULT NULL,
  `size_ha` decimal(10,4) DEFAULT NULL,
  `sale_price` bigint(20) DEFAULT NULL,
  `sale_date` date DEFAULT NULL,
  `price_per_m2` bigint(20) DEFAULT NULL,
  `price_per_ha` bigint(20) DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `source` varchar(255) DEFAULT NULL,
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `commercial_evaluation_crops` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `commercial_evaluation_id` bigint(20) unsigned NOT NULL,
  `agency_id` bigint(20) unsigned NOT NULL,
  `crop_type` varchar(255) NOT NULL,
  `variety` varchar(255) DEFAULT NULL,
  `hectares` decimal(10,2) NOT NULL,
  `year_planted` smallint(5) unsigned DEFAULT NULL,
  `age_years` smallint(5) unsigned DEFAULT NULL,
  `expected_lifespan_years` smallint(5) unsigned DEFAULT NULL,
  `remaining_productive_years` smallint(5) unsigned DEFAULT NULL,
  `trees_per_hectare` int(10) unsigned DEFAULT NULL,
  `total_trees` int(10) unsigned DEFAULT NULL,
  `current_yield_tons_per_ha` decimal(10,2) DEFAULT NULL,
  `expected_peak_yield_tons_per_ha` decimal(10,2) DEFAULT NULL,
  `yield_percentage` decimal(5,2) DEFAULT NULL,
  `current_price_per_ton` bigint(20) DEFAULT NULL,
  `annual_revenue` bigint(20) DEFAULT NULL,
  `annual_cost_per_ha` bigint(20) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `guidance_answers` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`guidance_answers`)),
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `commercial_evaluation_financials` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `commercial_evaluation_id` bigint(20) unsigned NOT NULL,
  `agency_id` bigint(20) unsigned NOT NULL,
  `financial_year` varchar(255) NOT NULL,
  `period_months` smallint(5) unsigned NOT NULL DEFAULT 12,
  `gross_revenue` bigint(20) DEFAULT NULL,
  `rental_income` bigint(20) DEFAULT NULL,
  `room_revenue` bigint(20) DEFAULT NULL,
  `food_beverage_revenue` bigint(20) DEFAULT NULL,
  `other_income` bigint(20) DEFAULT NULL,
  `vacancy_rate` decimal(5,2) DEFAULT NULL,
  `rates_taxes` bigint(20) DEFAULT NULL,
  `insurance` bigint(20) DEFAULT NULL,
  `utilities` bigint(20) DEFAULT NULL,
  `maintenance` bigint(20) DEFAULT NULL,
  `management_fees` bigint(20) DEFAULT NULL,
  `salaries_wages` bigint(20) DEFAULT NULL,
  `security` bigint(20) DEFAULT NULL,
  `marketing` bigint(20) DEFAULT NULL,
  `food_beverage_cost` bigint(20) DEFAULT NULL,
  `farm_operating_costs` bigint(20) DEFAULT NULL,
  `other_expenses` bigint(20) DEFAULT NULL,
  `total_expenses` bigint(20) DEFAULT NULL,
  `net_operating_income` bigint(20) DEFAULT NULL,
  `ebitda` bigint(20) DEFAULT NULL,
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `commercial_evaluation_livestock` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `commercial_evaluation_id` bigint(20) unsigned NOT NULL,
  `agency_id` bigint(20) unsigned NOT NULL,
  `livestock_type` varchar(255) NOT NULL,
  `breed` varchar(255) DEFAULT NULL,
  `head_count` int(10) unsigned NOT NULL,
  `breeding_stock_count` int(10) unsigned DEFAULT NULL,
  `value_per_head` bigint(20) DEFAULT NULL,
  `total_value` bigint(20) DEFAULT NULL,
  `carrying_capacity_ha_per_lsu` decimal(5,2) DEFAULT NULL,
  `hectares_used` decimal(10,2) DEFAULT NULL,
  `annual_revenue` bigint(20) DEFAULT NULL,
  `annual_cost` bigint(20) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `guidance_answers` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`guidance_answers`)),
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `commercial_evaluation_units` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `commercial_evaluation_id` bigint(20) unsigned NOT NULL,
  `agency_id` bigint(20) unsigned NOT NULL,
  `unit_name` varchar(255) NOT NULL,
  `tenant_name` varchar(255) DEFAULT NULL,
  `size_m2` decimal(12,2) DEFAULT NULL,
  `monthly_rental` bigint(20) DEFAULT NULL,
  `lease_start` date DEFAULT NULL,
  `lease_end` date DEFAULT NULL,
  `is_vacant` tinyint(1) NOT NULL DEFAULT 0,
  `escalation_rate` decimal(5,2) DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `commercial_evaluations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `created_by_user_id` bigint(20) unsigned NOT NULL,
  `agency_id` bigint(20) unsigned NOT NULL,
  `branch_id` bigint(20) unsigned DEFAULT NULL,
  `status` enum('draft','completed','archived') NOT NULL DEFAULT 'draft',
  `property_type` enum('commercial','industrial','hospitality','agricultural') NOT NULL,
  `property_name` varchar(255) NOT NULL,
  `address` text DEFAULT NULL,
  `suburb` varchar(255) DEFAULT NULL,
  `town` varchar(255) DEFAULT NULL,
  `province` varchar(255) NOT NULL DEFAULT 'KwaZulu-Natal',
  `erf_number` varchar(255) DEFAULT NULL,
  `zoning` varchar(255) DEFAULT NULL,
  `total_land_size_m2` decimal(12,2) DEFAULT NULL,
  `total_land_size_ha` decimal(10,4) DEFAULT NULL,
  `total_building_size_m2` decimal(12,2) DEFAULT NULL,
  `year_built` smallint(5) unsigned DEFAULT NULL,
  `condition` enum('excellent','good','fair','poor') DEFAULT NULL,
  `asking_price` bigint(20) DEFAULT NULL,
  `municipal_evaluation` bigint(20) DEFAULT NULL,
  `seller_name` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `evaluation_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`evaluation_json`)),
  `recommended_range_low` bigint(20) DEFAULT NULL,
  `recommended_range_mid` bigint(20) DEFAULT NULL,
  `recommended_range_high` bigint(20) DEFAULT NULL,
  `primary_method` varchar(255) DEFAULT NULL,
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `commission_ledger` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `agency_id` bigint(20) unsigned NOT NULL,
  `branch_id` bigint(20) unsigned DEFAULT NULL,
  `cap_period_id` bigint(20) unsigned NOT NULL,
  `deal_id` bigint(20) unsigned DEFAULT NULL,
  `property_id` bigint(20) unsigned DEFAULT NULL,
  `transaction_type` enum('sale','rental_letting','rental_management','referral','other') NOT NULL,
  `description` varchar(500) NOT NULL,
  `gross_commission` decimal(12,2) NOT NULL,
  `vat_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `commission_excl_vat` decimal(12,2) NOT NULL,
  `agent_split_percent` int(11) NOT NULL,
  `agent_amount` decimal(12,2) NOT NULL,
  `agency_amount` decimal(12,2) NOT NULL,
  `transaction_fee` decimal(10,2) NOT NULL DEFAULT 0.00,
  `risk_fee` decimal(10,2) NOT NULL DEFAULT 0.00,
  `mentor_fee` decimal(10,2) NOT NULL DEFAULT 0.00,
  `is_post_cap` tinyint(1) NOT NULL DEFAULT 0,
  `net_agent_amount` decimal(12,2) NOT NULL,
  `company_dollar` decimal(12,2) NOT NULL,
  `revenue_share_pool` decimal(12,2) NOT NULL DEFAULT 0.00,
  `status` enum('pending','confirmed','paid','cancelled') NOT NULL DEFAULT 'pending',
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
DROP TABLE IF EXISTS `commission_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `commission_settings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint(20) unsigned NOT NULL,
  `commission_split_agent` int(11) NOT NULL DEFAULT 80,
  `commission_split_agency` int(11) NOT NULL DEFAULT 20,
  `annual_cap` decimal(12,2) NOT NULL DEFAULT 160000.00,
  `post_cap_transaction_fee` decimal(10,2) NOT NULL DEFAULT 2500.00,
  `post_cap_fee_cap` decimal(10,2) NOT NULL DEFAULT 50000.00,
  `post_cap_reduced_fee` decimal(10,2) NOT NULL DEFAULT 750.00,
  `monthly_platform_fee` decimal(10,2) NOT NULL DEFAULT 850.00,
  `mentor_extra_split` int(11) NOT NULL DEFAULT 20,
  `mentor_transactions` int(11) NOT NULL DEFAULT 3,
  `risk_management_fee` decimal(10,2) NOT NULL DEFAULT 400.00,
  `risk_management_cap` decimal(10,2) NOT NULL DEFAULT 5000.00,
  `revenue_share_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `revenue_share_pool_percent` int(11) NOT NULL DEFAULT 50,
  `tier_1_percent` decimal(5,2) NOT NULL DEFAULT 3.50,
  `tier_2_percent` decimal(5,2) NOT NULL DEFAULT 4.00,
  `tier_3_percent` decimal(5,2) NOT NULL DEFAULT 2.50,
  `tier_4_percent` decimal(5,2) NOT NULL DEFAULT 1.50,
  `tier_5_percent` decimal(5,2) NOT NULL DEFAULT 1.00,
  `tier_6_percent` decimal(5,2) NOT NULL DEFAULT 0.50,
  `tier_7_percent` decimal(5,2) NOT NULL DEFAULT 0.25,
  `tier_4_flqa_requirement` int(11) NOT NULL DEFAULT 5,
  `tier_5_flqa_requirement` int(11) NOT NULL DEFAULT 10,
  `tier_6_flqa_requirement` int(11) NOT NULL DEFAULT 15,
  `tier_7_flqa_requirement` int(11) NOT NULL DEFAULT 20,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `commission_settings_agency_id_unique` (`agency_id`),
  CONSTRAINT `commission_settings_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `company_expenses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `company_expenses` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `period` varchar(255) NOT NULL,
  `monthly_expenses` decimal(12,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `condition_initials`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `condition_initials` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `initialable_type` varchar(255) NOT NULL,
  `initialable_id` bigint(20) unsigned NOT NULL,
  `party_key` varchar(50) NOT NULL,
  `signature_request_id` bigint(20) unsigned DEFAULT NULL,
  `amendment_id` bigint(20) unsigned DEFAULT NULL,
  `initialed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `initial_image_path` varchar(255) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(500) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `cond_init_morph_idx` (`initialable_type`,`initialable_id`),
  KEY `cond_init_party_idx` (`party_key`,`initialed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `contact_access_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `contact_access_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint(20) unsigned NOT NULL,
  `contact_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `action_type` enum('view','edit','export','share','delete','merge') NOT NULL,
  `accessed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `request_id` varchar(50) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `contact_access_log_user_id_foreign` (`user_id`),
  KEY `contact_access_log_contact_id_accessed_at_index` (`contact_id`,`accessed_at`),
  KEY `contact_access_log_agency_id_accessed_at_index` (`agency_id`,`accessed_at`),
  CONSTRAINT `contact_access_log_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `contact_access_log_contact_id_foreign` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `contact_access_log_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `contact_consent_records`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `contact_consent_records` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `contact_id` bigint(20) unsigned NOT NULL,
  `agency_id` bigint(20) unsigned NOT NULL,
  `consent_type` enum('fica_processing','marketing_communications','data_sharing','channel_email','channel_sms','channel_whatsapp','channel_call') NOT NULL,
  `given_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `given_by_user_id` bigint(20) unsigned NOT NULL,
  `method` enum('verbal','written','electronic','signed_document') NOT NULL,
  `evidence_document_id` bigint(20) unsigned DEFAULT NULL,
  `revoked_at` timestamp NULL DEFAULT NULL,
  `revoked_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `revoked_reason` varchar(500) DEFAULT NULL,
  `notes` text DEFAULT NULL,
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
DROP TABLE IF EXISTS `contact_documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `contact_documents` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `contact_id` bigint(20) unsigned NOT NULL,
  `agency_id` bigint(20) unsigned NOT NULL,
  `uploaded_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `original_name` varchar(255) NOT NULL,
  `storage_path` varchar(255) NOT NULL,
  `mime_type` varchar(100) DEFAULT NULL,
  `size` bigint(20) unsigned NOT NULL DEFAULT 0,
  `document_type_id` bigint(20) unsigned DEFAULT NULL,
  `property_id` bigint(20) unsigned DEFAULT NULL,
  `source_type` varchar(20) NOT NULL DEFAULT 'upload',
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `contact_duplicate_clusters` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint(20) unsigned NOT NULL,
  `contact_ids` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`contact_ids`)),
  `match_field` varchar(50) NOT NULL,
  `match_value` varchar(255) NOT NULL,
  `status` enum('pending','reviewed','merged','dismissed') NOT NULL DEFAULT 'pending',
  `reviewed_by_user_id` bigint(20) unsigned DEFAULT NULL,
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `contact_duplicate_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint(20) unsigned NOT NULL,
  `attempted_by_user_id` bigint(20) unsigned NOT NULL,
  `mode_at_attempt` varchar(30) NOT NULL,
  `match_field` varchar(50) NOT NULL,
  `match_value` varchar(255) NOT NULL,
  `existing_contact_id` bigint(20) unsigned DEFAULT NULL,
  `attempted_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`attempted_data`)),
  `action_taken` enum('auto_linked','used_existing','created_anyway','override_with_reason','request_pending','rejected') NOT NULL,
  `override_reason` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `contact_duplicate_log_agency_id_foreign` (`agency_id`),
  KEY `contact_duplicate_log_attempted_by_user_id_foreign` (`attempted_by_user_id`),
  KEY `contact_duplicate_log_existing_contact_id_foreign` (`existing_contact_id`),
  CONSTRAINT `contact_duplicate_log_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `contact_duplicate_log_attempted_by_user_id_foreign` FOREIGN KEY (`attempted_by_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `contact_duplicate_log_existing_contact_id_foreign` FOREIGN KEY (`existing_contact_id`) REFERENCES `contacts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `contact_match_feedback`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `contact_match_feedback` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `contact_match_id` bigint(20) unsigned NOT NULL,
  `agency_id` bigint(20) unsigned NOT NULL,
  `property_id` bigint(20) unsigned NOT NULL,
  `reaction` varchar(20) NOT NULL,
  `note` text DEFAULT NULL,
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
DROP TABLE IF EXISTS `contact_match_notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `contact_match_notifications` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `contact_match_id` bigint(20) unsigned NOT NULL,
  `agency_id` bigint(20) unsigned NOT NULL,
  `property_id` bigint(20) unsigned NOT NULL,
  `score` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `notified_user_id` bigint(20) unsigned DEFAULT NULL,
  `notification_id` char(36) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `cmn_match_property_unique` (`contact_match_id`,`property_id`),
  KEY `contact_match_notifications_notified_user_id_foreign` (`notified_user_id`),
  KEY `cmn_property_idx` (`property_id`),
  KEY `contact_match_notifications_agency_id_idx` (`agency_id`),
  CONSTRAINT `contact_match_notifications_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `contact_match_notifications_contact_match_id_foreign` FOREIGN KEY (`contact_match_id`) REFERENCES `contact_matches` (`id`) ON DELETE CASCADE,
  CONSTRAINT `contact_match_notifications_notified_user_id_foreign` FOREIGN KEY (`notified_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `contact_match_notifications_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `contact_matches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `contact_matches` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint(20) unsigned DEFAULT NULL,
  `share_token` varchar(64) DEFAULT NULL,
  `share_slug` varchar(120) DEFAULT NULL,
  `contact_id` bigint(20) unsigned NOT NULL,
  `created_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `updated_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `name` varchar(255) DEFAULT NULL,
  `listing_type` varchar(255) NOT NULL DEFAULT 'sale',
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `is_primary` tinyint(1) NOT NULL DEFAULT 0,
  `category` varchar(255) DEFAULT NULL,
  `property_type` varchar(255) DEFAULT NULL,
  `property_types` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`property_types`)),
  `price_min` int(10) unsigned DEFAULT NULL,
  `price_max` int(10) unsigned DEFAULT NULL,
  `beds_min` tinyint(3) unsigned DEFAULT NULL,
  `bedrooms_max` tinyint(3) unsigned DEFAULT NULL,
  `baths_min` tinyint(3) unsigned DEFAULT NULL,
  `garages_min` tinyint(3) unsigned DEFAULT NULL,
  `parking_min` tinyint(3) unsigned DEFAULT NULL,
  `floor_size_min` int(10) unsigned DEFAULT NULL,
  `floor_size_max` int(10) unsigned DEFAULT NULL,
  `erf_size_min` int(10) unsigned DEFAULT NULL,
  `erf_size_max` int(10) unsigned DEFAULT NULL,
  `suburbs` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`suburbs`)),
  `p24_suburb_ids` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`p24_suburb_ids`)),
  `must_have_features` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`must_have_features`)),
  `nice_to_have_features` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`nice_to_have_features`)),
  `deal_breakers` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`deal_breakers`)),
  `notes` text DEFAULT NULL,
  `hidden_property_ids` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`hidden_property_ids`)),
  `hidden_property_reasons` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`hidden_property_reasons`)),
  `property_view_counts` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`property_view_counts`)),
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
  CONSTRAINT `contact_matches_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE SET NULL,
  CONSTRAINT `contact_matches_contact_id_foreign` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `contact_matches_created_by_user_id_foreign` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `contact_matches_updated_by_user_id_foreign` FOREIGN KEY (`updated_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `contact_notes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `contact_notes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `contact_id` bigint(20) unsigned NOT NULL,
  `agency_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `body` text NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `contact_notes_contact_id_foreign` (`contact_id`),
  KEY `contact_notes_user_id_foreign` (`user_id`),
  KEY `contact_notes_agency_id_idx` (`agency_id`),
  CONSTRAINT `contact_notes_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `contact_notes_contact_id_foreign` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `contact_notes_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `contact_outreach_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `contact_outreach_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint(20) unsigned NOT NULL,
  `contact_id` bigint(20) unsigned NOT NULL,
  `send_id` bigint(20) unsigned DEFAULT NULL,
  `event_kind` enum('sent','clicked','opted_out') NOT NULL,
  `occurred_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `summary` varchar(255) NOT NULL,
  `actor_user_id` bigint(20) unsigned DEFAULT NULL,
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
DROP TABLE IF EXISTS `contact_property`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `contact_property` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `contact_id` bigint(20) unsigned NOT NULL,
  `property_id` bigint(20) unsigned NOT NULL,
  `role` varchar(50) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `contact_property_contact_id_property_id_unique` (`contact_id`,`property_id`),
  KEY `contact_property_property_id_foreign` (`property_id`),
  CONSTRAINT `contact_property_contact_id_foreign` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `contact_property_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `contact_sources`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `contact_sources` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `color` varchar(7) NOT NULL DEFAULT '#6366f1',
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `agency_id` bigint(20) unsigned NOT NULL,
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `contact_tag` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `contact_id` bigint(20) unsigned NOT NULL,
  `contact_tag_id` bigint(20) unsigned NOT NULL,
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `contact_tags` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `color` varchar(7) NOT NULL DEFAULT '#6366f1',
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `agency_id` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `contact_tags_agency_id_idx` (`agency_id`),
  CONSTRAINT `contact_tags_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `contact_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `contact_types` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `color` varchar(7) NOT NULL DEFAULT '#6366f1',
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `esign_role` varchar(20) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `contact_types_esign_role_index` (`esign_role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `contacts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `contacts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `contact_type_id` bigint(20) unsigned DEFAULT NULL,
  `contact_source_id` bigint(20) unsigned DEFAULT NULL,
  `created_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `client_user_id` bigint(20) unsigned DEFAULT NULL,
  `first_name` varchar(255) NOT NULL,
  `last_name` varchar(255) NOT NULL,
  `phone` varchar(255) NOT NULL,
  `email` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `birthday` date DEFAULT NULL,
  `id_number` varchar(20) DEFAULT NULL,
  `id_number_captured_at` timestamp NULL DEFAULT NULL,
  `id_number_source` varchar(60) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `loaded_at` timestamp NULL DEFAULT NULL,
  `modified_at` timestamp NULL DEFAULT NULL,
  `last_contacted_at` timestamp NULL DEFAULT NULL,
  `whatsapp_count` int(10) unsigned NOT NULL DEFAULT 0,
  `email_count` int(10) unsigned NOT NULL DEFAULT 0,
  `bank_name` varchar(255) DEFAULT NULL,
  `bank_account_name` varchar(255) DEFAULT NULL,
  `bank_account_number` varchar(100) DEFAULT NULL,
  `bank_branch_name` varchar(255) DEFAULT NULL,
  `bank_branch_code` varchar(50) DEFAULT NULL,
  `bank_account_type` varchar(50) DEFAULT NULL,
  `preapproval_amount` decimal(14,2) DEFAULT NULL,
  `preapproval_expires_at` date DEFAULT NULL,
  `preapproval_institution` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `purged_at` timestamp NULL DEFAULT NULL,
  `purged_reason` varchar(255) DEFAULT NULL,
  `opt_out_email` tinyint(1) NOT NULL DEFAULT 0,
  `opt_out_sms` tinyint(1) NOT NULL DEFAULT 0,
  `opt_out_whatsapp` tinyint(1) NOT NULL DEFAULT 0,
  `opt_out_call` tinyint(1) NOT NULL DEFAULT 0,
  `last_consent_check_at` timestamp NULL DEFAULT NULL,
  `is_buyer` tinyint(1) NOT NULL DEFAULT 0,
  `buyer_state` varchar(20) DEFAULT NULL,
  `last_activity_at` timestamp NULL DEFAULT NULL,
  `buyer_pipeline_entered_at` timestamp NULL DEFAULT NULL,
  `buyer_pipeline_notes` text DEFAULT NULL,
  `agency_id` bigint(20) unsigned DEFAULT NULL,
  `branch_id` bigint(20) unsigned NOT NULL,
  `messaging_opt_out_at` timestamp NULL DEFAULT NULL,
  `messaging_opt_out_reason` varchar(255) DEFAULT NULL,
  `messaging_opt_out_recorded_by_user_id` bigint(20) unsigned DEFAULT NULL,
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
  CONSTRAINT `contacts_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  CONSTRAINT `contacts_client_user_id_foreign` FOREIGN KEY (`client_user_id`) REFERENCES `client_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `contacts_contact_source_id_foreign` FOREIGN KEY (`contact_source_id`) REFERENCES `contact_sources` (`id`) ON DELETE SET NULL,
  CONSTRAINT `contacts_contact_type_id_foreign` FOREIGN KEY (`contact_type_id`) REFERENCES `contact_types` (`id`) ON DELETE SET NULL,
  CONSTRAINT `contacts_created_by_user_id_foreign` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `contacts_msg_optout_recorded_by_fk` FOREIGN KEY (`messaging_opt_out_recorded_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `daily_activities`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `daily_activities` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `activity_date` date NOT NULL,
  `period` varchar(7) NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `agency_id` bigint(20) unsigned NOT NULL,
  `branch_id` bigint(20) unsigned DEFAULT NULL,
  `calls_made` int(11) NOT NULL DEFAULT 0,
  `doors_knocked` int(11) NOT NULL DEFAULT 0,
  `whatsapps_sent` int(11) NOT NULL DEFAULT 0,
  `referrals_asked` int(11) NOT NULL DEFAULT 0,
  `flyers_dropped` int(11) NOT NULL DEFAULT 0,
  `presentations_booked` int(11) NOT NULL DEFAULT 0,
  `presentations_done` int(11) NOT NULL DEFAULT 0,
  `oats_signed` int(11) NOT NULL DEFAULT 0,
  `eats_signed` int(11) NOT NULL DEFAULT 0,
  `buyer_leads` int(11) NOT NULL DEFAULT 0,
  `seller_leads` int(11) NOT NULL DEFAULT 0,
  `portal_leads` int(11) NOT NULL DEFAULT 0,
  `referral_leads` int(11) NOT NULL DEFAULT 0,
  `buyer_appointments` int(11) NOT NULL DEFAULT 0,
  `otps_written` int(11) NOT NULL DEFAULT 0,
  `otps_accepted` int(11) NOT NULL DEFAULT 0,
  `otps_collapsed` int(11) NOT NULL DEFAULT 0,
  `notes` text DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `updated_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `prospecting` int(10) unsigned NOT NULL DEFAULT 0,
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `daily_activity_entries` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `activity_date` date NOT NULL,
  `period` varchar(7) NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `agency_id` bigint(20) unsigned NOT NULL,
  `branch_id` bigint(20) unsigned DEFAULT NULL,
  `activity_definition_id` bigint(20) unsigned NOT NULL,
  `value` int(11) NOT NULL DEFAULT 0,
  `point_state` varchar(20) NOT NULL DEFAULT 'confirmed',
  `source` varchar(20) NOT NULL DEFAULT 'manual',
  `calendar_event_id` bigint(20) unsigned DEFAULT NULL,
  `subject_type` varchar(100) DEFAULT NULL,
  `subject_id` bigint(20) unsigned DEFAULT NULL,
  `confirmed_at` timestamp NULL DEFAULT NULL,
  `revoked_at` timestamp NULL DEFAULT NULL,
  `revoke_reason` text DEFAULT NULL,
  `overridden_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `override_reason` text DEFAULT NULL,
  `override_audit_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`override_audit_json`)),
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `updated_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
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
DROP TABLE IF EXISTS `deal_activity_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `deal_activity_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `deal_id` bigint(20) unsigned NOT NULL,
  `agency_id` bigint(20) unsigned NOT NULL,
  `deal_step_instance_id` bigint(20) unsigned DEFAULT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `action` varchar(255) NOT NULL,
  `description` text NOT NULL,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `deal_activity_log_user_id_foreign` (`user_id`),
  KEY `deal_activity_log_deal_step_instance_id_foreign` (`deal_step_instance_id`),
  KEY `deal_activity_log_deal_id_created_at_index` (`deal_id`,`created_at`),
  KEY `deal_activity_log_agency_id_idx` (`agency_id`),
  CONSTRAINT `deal_activity_log_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `deal_activity_log_deal_id_foreign` FOREIGN KEY (`deal_id`) REFERENCES `deals_v2` (`id`) ON DELETE CASCADE,
  CONSTRAINT `deal_activity_log_deal_step_instance_id_foreign` FOREIGN KEY (`deal_step_instance_id`) REFERENCES `deal_step_instances` (`id`) ON DELETE SET NULL,
  CONSTRAINT `deal_activity_log_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `deal_branches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `deal_branches` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `deal_id` bigint(20) unsigned NOT NULL,
  `branch_id` bigint(20) unsigned NOT NULL,
  `role` enum('originator','co_branch') NOT NULL DEFAULT 'co_branch',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `deal_branches_deal_id_branch_id_unique` (`deal_id`,`branch_id`),
  KEY `deal_branches_branch_id_index` (`branch_id`),
  CONSTRAINT `deal_branches_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE,
  CONSTRAINT `deal_branches_deal_id_foreign` FOREIGN KEY (`deal_id`) REFERENCES `deals` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `deal_link_review_queue`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `deal_link_review_queue` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `deal_id` bigint(20) unsigned NOT NULL,
  `agency_id` bigint(20) unsigned NOT NULL,
  `matched_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `match_status` enum('pending','resolved_linked','resolved_unlinked','resolved_skip') NOT NULL DEFAULT 'pending',
  `candidates_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`candidates_json`)),
  `chosen_property_id` bigint(20) unsigned DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `reviewed_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `review_note` text DEFAULT NULL,
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `deal_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `deal_id` bigint(20) unsigned NOT NULL,
  `agency_id` bigint(20) unsigned NOT NULL,
  `actor_user_id` bigint(20) unsigned DEFAULT NULL,
  `event_type` varchar(50) NOT NULL,
  `from_value` text DEFAULT NULL,
  `to_value` text DEFAULT NULL,
  `message` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `deal_logs_deal_id_created_at_index` (`deal_id`,`created_at`),
  KEY `deal_logs_actor_user_id_created_at_index` (`actor_user_id`,`created_at`),
  KEY `deal_logs_event_type_created_at_index` (`event_type`,`created_at`),
  KEY `deal_logs_agency_id_idx` (`agency_id`),
  CONSTRAINT `deal_logs_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `deal_money_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `deal_money_lines` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `deal_id` bigint(20) unsigned NOT NULL,
  `agency_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `period` varchar(7) NOT NULL,
  `branch_id` bigint(20) unsigned DEFAULT NULL,
  `side` varchar(20) DEFAULT NULL,
  `side_pool_ex_vat` decimal(14,2) NOT NULL DEFAULT 0.00,
  `allocation_percent` decimal(6,2) NOT NULL DEFAULT 0.00,
  `pool_share_ex_vat` decimal(14,2) NOT NULL DEFAULT 0.00,
  `agent_cut_percent` decimal(6,2) NOT NULL DEFAULT 0.00,
  `agent_income_ex_vat` decimal(14,2) NOT NULL DEFAULT 0.00,
  `company_retained_ex_vat` decimal(14,2) NOT NULL DEFAULT 0.00,
  `paye_method` varchar(20) DEFAULT NULL,
  `paye_value` decimal(14,2) NOT NULL DEFAULT 0.00,
  `paye_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `deductions` decimal(14,2) NOT NULL DEFAULT 0.00,
  `deductions_description` varchar(255) DEFAULT NULL,
  `agent_net_ex_vat` decimal(14,2) NOT NULL DEFAULT 0.00,
  `paid_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `agent_gross_ex_vat` decimal(14,2) NOT NULL DEFAULT 0.00,
  `company_gross_ex_vat` decimal(14,2) NOT NULL DEFAULT 0.00,
  `source` varchar(30) DEFAULT NULL,
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
DROP TABLE IF EXISTS `deal_pipeline_steps`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `deal_pipeline_steps` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `pipeline_template_id` bigint(20) unsigned NOT NULL,
  `agency_id` bigint(20) unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `position` int(11) NOT NULL DEFAULT 0,
  `is_locked` tinyint(1) NOT NULL DEFAULT 0,
  `is_milestone` tinyint(1) NOT NULL DEFAULT 0,
  `completion_type` enum('manual_tick','date_input','amount_input','document_upload','document_signed','text_input','multi_field','auto_from_linked_deal') NOT NULL DEFAULT 'manual_tick',
  `completion_config` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`completion_config`)),
  `trigger_type` enum('on_creation','after_step','manual','on_date') NOT NULL DEFAULT 'on_creation',
  `trigger_step_id` bigint(20) unsigned DEFAULT NULL,
  `days_offset` int(11) NOT NULL DEFAULT 0,
  `rag_green_days` int(11) NOT NULL DEFAULT 14,
  `rag_amber_days` int(11) NOT NULL DEFAULT 7,
  `rag_red_days` int(11) NOT NULL DEFAULT 3,
  `notify_agent` tinyint(1) NOT NULL DEFAULT 1,
  `notify_bm` tinyint(1) NOT NULL DEFAULT 1,
  `notify_admin` tinyint(1) NOT NULL DEFAULT 0,
  `status_trigger` varchar(255) DEFAULT NULL,
  `negative_status_trigger` varchar(255) DEFAULT NULL,
  `negative_outcome_label` varchar(255) DEFAULT NULL,
  `requires_bm_approval` tinyint(1) NOT NULL DEFAULT 0,
  `escalation_config` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`escalation_config`)),
  `required_before` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`required_before`)),
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `deal_pipeline_templates` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `deal_type` enum('bond','cash','sale_of_2nd') NOT NULL,
  `branch_id` bigint(20) unsigned DEFAULT NULL,
  `agency_id` bigint(20) unsigned NOT NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by_id` bigint(20) unsigned NOT NULL,
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
DROP TABLE IF EXISTS `deal_settlements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `deal_settlements` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `deal_id` bigint(20) unsigned NOT NULL,
  `agency_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `side` varchar(255) NOT NULL,
  `share_percent` decimal(8,2) NOT NULL DEFAULT 0.00,
  `agent_cut_percent` decimal(8,2) NOT NULL DEFAULT 0.00,
  `paye_method` varchar(255) NOT NULL DEFAULT 'percentage',
  `paye_value` decimal(12,2) NOT NULL DEFAULT 0.00,
  `deductions` decimal(12,2) NOT NULL DEFAULT 0.00,
  `deductions_description` varchar(255) DEFAULT NULL,
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
DROP TABLE IF EXISTS `deal_step_documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `deal_step_documents` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `deal_step_instance_id` bigint(20) unsigned NOT NULL,
  `agency_id` bigint(20) unsigned NOT NULL,
  `document_id` bigint(20) unsigned DEFAULT NULL,
  `file_path` varchar(255) DEFAULT NULL,
  `file_name` varchar(255) DEFAULT NULL,
  `uploaded_by_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `deal_step_documents_deal_step_instance_id_foreign` (`deal_step_instance_id`),
  KEY `deal_step_documents_uploaded_by_id_foreign` (`uploaded_by_id`),
  KEY `deal_step_documents_agency_id_idx` (`agency_id`),
  CONSTRAINT `deal_step_documents_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `deal_step_documents_deal_step_instance_id_foreign` FOREIGN KEY (`deal_step_instance_id`) REFERENCES `deal_step_instances` (`id`) ON DELETE CASCADE,
  CONSTRAINT `deal_step_documents_uploaded_by_id_foreign` FOREIGN KEY (`uploaded_by_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `deal_step_instances`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `deal_step_instances` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `deal_id` bigint(20) unsigned NOT NULL,
  `agency_id` bigint(20) unsigned NOT NULL,
  `pipeline_step_id` bigint(20) unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `position` int(11) NOT NULL DEFAULT 0,
  `is_locked` tinyint(1) NOT NULL DEFAULT 0,
  `is_milestone` tinyint(1) NOT NULL DEFAULT 0,
  `completion_type` enum('manual_tick','date_input','amount_input','document_upload','document_signed','text_input','multi_field','auto_from_linked_deal') NOT NULL DEFAULT 'manual_tick',
  `completion_config` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`completion_config`)),
  `status` enum('not_started','active','completed','overdue','skipped') NOT NULL DEFAULT 'not_started',
  `trigger_type` enum('on_creation','after_step','manual','on_date') NOT NULL,
  `trigger_step_instance_id` bigint(20) unsigned DEFAULT NULL,
  `days_offset` int(11) NOT NULL DEFAULT 0,
  `due_date` date DEFAULT NULL,
  `activated_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `completed_by_id` bigint(20) unsigned DEFAULT NULL,
  `completion_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`completion_data`)),
  `rag_green_days` int(11) NOT NULL DEFAULT 14,
  `rag_amber_days` int(11) NOT NULL DEFAULT 7,
  `rag_red_days` int(11) NOT NULL DEFAULT 3,
  `current_rag` enum('grey','green','amber','red','overdue') NOT NULL DEFAULT 'grey',
  `notify_agent` tinyint(1) NOT NULL DEFAULT 1,
  `notify_bm` tinyint(1) NOT NULL DEFAULT 1,
  `notify_admin` tinyint(1) NOT NULL DEFAULT 0,
  `status_trigger` varchar(255) DEFAULT NULL,
  `negative_status_trigger` varchar(255) DEFAULT NULL,
  `negative_outcome_label` varchar(255) DEFAULT NULL,
  `requires_bm_approval` tinyint(1) NOT NULL DEFAULT 0,
  `approval_status` enum('not_required','pending','approved','rejected') NOT NULL DEFAULT 'not_required',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `approved_by_id` bigint(20) unsigned DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `approval_notes` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `deal_step_instances_deal_id_foreign` (`deal_id`),
  KEY `deal_step_instances_pipeline_step_id_foreign` (`pipeline_step_id`),
  KEY `deal_step_instances_completed_by_id_foreign` (`completed_by_id`),
  KEY `deal_step_instances_trigger_step_instance_id_foreign` (`trigger_step_instance_id`),
  KEY `deal_step_instances_approved_by_id_foreign` (`approved_by_id`),
  KEY `deal_step_instances_agency_id_idx` (`agency_id`),
  CONSTRAINT `deal_step_instances_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `deal_step_instances_approved_by_id_foreign` FOREIGN KEY (`approved_by_id`) REFERENCES `users` (`id`),
  CONSTRAINT `deal_step_instances_completed_by_id_foreign` FOREIGN KEY (`completed_by_id`) REFERENCES `users` (`id`),
  CONSTRAINT `deal_step_instances_deal_id_foreign` FOREIGN KEY (`deal_id`) REFERENCES `deals_v2` (`id`) ON DELETE CASCADE,
  CONSTRAINT `deal_step_instances_pipeline_step_id_foreign` FOREIGN KEY (`pipeline_step_id`) REFERENCES `deal_pipeline_steps` (`id`),
  CONSTRAINT `deal_step_instances_trigger_step_instance_id_foreign` FOREIGN KEY (`trigger_step_instance_id`) REFERENCES `deal_step_instances` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `deal_user`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `deal_user` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `deal_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `side` enum('listing','selling') NOT NULL,
  `agent_split_percent` decimal(5,2) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `agent_cut_percent` decimal(8,2) DEFAULT NULL,
  `paye_method` varchar(255) DEFAULT NULL,
  `paye_value` decimal(12,2) DEFAULT NULL,
  `deductions` decimal(12,2) DEFAULT NULL,
  `deductions_description` varchar(255) DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL,
  `sliding_granted_month` text DEFAULT NULL,
  `sliding_sequence_in_month` int(11) DEFAULT NULL,
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `deal_v2_agents` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `deal_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `side` enum('listing','selling') NOT NULL,
  `agent_split_percent` decimal(5,2) DEFAULT NULL,
  `agent_cut_percent` decimal(8,2) DEFAULT NULL,
  `paye_method` varchar(255) DEFAULT NULL,
  `paye_value` decimal(12,2) DEFAULT NULL,
  `deductions` decimal(12,2) DEFAULT NULL,
  `deductions_description` varchar(255) DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL,
  `sliding_granted_month` text DEFAULT NULL,
  `sliding_sequence_in_month` int(11) DEFAULT NULL,
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `deal_v2_contacts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `deal_id` bigint(20) unsigned NOT NULL,
  `contact_id` bigint(20) unsigned NOT NULL,
  `role` enum('buyer','seller','co_buyer','co_seller','conveyancer','bond_originator','other') NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `deal_v2_contacts_deal_id_foreign` (`deal_id`),
  KEY `deal_v2_contacts_contact_id_foreign` (`contact_id`),
  CONSTRAINT `deal_v2_contacts_contact_id_foreign` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `deal_v2_contacts_deal_id_foreign` FOREIGN KEY (`deal_id`) REFERENCES `deals_v2` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `deal_v2_settlements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `deal_v2_settlements` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `deal_id` bigint(20) unsigned NOT NULL,
  `agency_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `side` varchar(255) NOT NULL,
  `share_percent` decimal(8,2) NOT NULL DEFAULT 0.00,
  `agent_cut_percent` decimal(8,2) NOT NULL DEFAULT 0.00,
  `paye_method` varchar(255) NOT NULL DEFAULT 'percentage',
  `paye_value` decimal(12,2) NOT NULL DEFAULT 0.00,
  `deductions` decimal(12,2) NOT NULL DEFAULT 0.00,
  `deductions_description` varchar(255) DEFAULT NULL,
  `paid_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `deal_v2_settlements_deal_id_user_id_side_unique` (`deal_id`,`user_id`,`side`),
  KEY `deal_v2_settlements_user_id_foreign` (`user_id`),
  KEY `deal_v2_settlements_deal_id_side_index` (`deal_id`,`side`),
  KEY `deal_v2_settlements_agency_id_idx` (`agency_id`),
  CONSTRAINT `deal_v2_settlements_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `deal_v2_settlements_deal_id_foreign` FOREIGN KEY (`deal_id`) REFERENCES `deals_v2` (`id`) ON DELETE CASCADE,
  CONSTRAINT `deal_v2_settlements_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `deals`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `deals` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `deal_no` int(10) unsigned DEFAULT NULL,
  `file_no` varchar(255) DEFAULT NULL,
  `branch_id` bigint(20) unsigned DEFAULT NULL,
  `period` varchar(255) NOT NULL,
  `deal_date` date NOT NULL,
  `property_address` varchar(255) DEFAULT NULL,
  `seller_name` varchar(255) DEFAULT NULL,
  `buyer_name` varchar(255) DEFAULT NULL,
  `attorney_name` varchar(255) DEFAULT NULL,
  `accepted_status` varchar(1) DEFAULT NULL,
  `granted_at` datetime DEFAULT NULL,
  `commission_status` varchar(255) DEFAULT NULL,
  `registration_date` date DEFAULT NULL,
  `sale_date` date DEFAULT NULL COMMENT 'Phase 3i analytics alias of registration_date.',
  `link_source` enum('manual','auto_address_match','auto_address_date_match','presentation_link','admin_review') DEFAULT NULL,
  `link_confidence` enum('exact','high','medium','low') DEFAULT NULL,
  `link_reviewed_at` timestamp NULL DEFAULT NULL,
  `link_reviewed_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `property_value` decimal(12,2) NOT NULL,
  `sale_price` bigint(20) unsigned DEFAULT NULL COMMENT 'Phase 3i canonical sale price in Rands (no cents). Mirrors property_value.',
  `total_commission` decimal(12,2) NOT NULL,
  `listing_external` tinyint(1) NOT NULL DEFAULT 0,
  `listing_external_agency` varchar(255) DEFAULT NULL,
  `listing_our_share_percent` decimal(5,2) NOT NULL DEFAULT 100.00,
  `selling_external` tinyint(1) NOT NULL DEFAULT 0,
  `selling_external_agency` varchar(255) DEFAULT NULL,
  `listing_split_percent` decimal(5,2) NOT NULL DEFAULT 50.00,
  `selling_split_percent` decimal(5,2) NOT NULL DEFAULT 50.00,
  `selling_our_share_percent` decimal(5,2) NOT NULL DEFAULT 100.00,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `agency_id` bigint(20) unsigned DEFAULT NULL,
  `property_id` bigint(20) unsigned DEFAULT NULL,
  `presentation_id` bigint(20) unsigned DEFAULT NULL,
  `is_demo` tinyint(1) NOT NULL DEFAULT 0,
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
  CONSTRAINT `deals_link_reviewer_fk` FOREIGN KEY (`link_reviewed_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `deals_presentation_fk` FOREIGN KEY (`presentation_id`) REFERENCES `presentations` (`id`) ON DELETE SET NULL,
  CONSTRAINT `deals_property_fk` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `deals_v2`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `deals_v2` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `reference` varchar(255) NOT NULL,
  `deal_type` enum('bond','cash','sale_of_2nd') NOT NULL,
  `status` enum('active','completed','cancelled','on_hold') NOT NULL DEFAULT 'active',
  `property_id` bigint(20) unsigned NOT NULL,
  `listing_agent_id` bigint(20) unsigned NOT NULL,
  `selling_agent_id` bigint(20) unsigned DEFAULT NULL,
  `pipeline_template_id` bigint(20) unsigned NOT NULL,
  `linked_deal_id` bigint(20) unsigned DEFAULT NULL,
  `purchase_price` decimal(14,2) NOT NULL,
  `commission_percentage` decimal(5,2) DEFAULT NULL,
  `commission_amount` decimal(12,2) NOT NULL,
  `commission_vat` decimal(12,2) NOT NULL,
  `listing_split_percent` decimal(5,2) NOT NULL DEFAULT 50.00,
  `listing_external` tinyint(1) NOT NULL DEFAULT 0,
  `listing_our_share_percent` decimal(5,2) NOT NULL DEFAULT 100.00,
  `listing_external_agency` varchar(255) DEFAULT NULL,
  `selling_split_percent` decimal(5,2) NOT NULL DEFAULT 50.00,
  `selling_external` tinyint(1) NOT NULL DEFAULT 0,
  `selling_our_share_percent` decimal(5,2) NOT NULL DEFAULT 100.00,
  `selling_external_agency` varchar(255) DEFAULT NULL,
  `offer_date` date NOT NULL,
  `expected_registration` date DEFAULT NULL,
  `actual_registration` date DEFAULT NULL,
  `overall_rag` enum('grey','green','amber','red','overdue') NOT NULL DEFAULT 'grey',
  `commission_status` varchar(255) NOT NULL DEFAULT 'Not Paid',
  `notes` text DEFAULT NULL,
  `branch_id` bigint(20) unsigned NOT NULL,
  `agency_id` bigint(20) unsigned NOT NULL,
  `created_by_id` bigint(20) unsigned NOT NULL,
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
DROP TABLE IF EXISTS `deposit_interest_calculations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `deposit_interest_calculations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `property_name` varchar(255) NOT NULL,
  `deposit_amount` decimal(12,2) NOT NULL,
  `invest_date` date NOT NULL,
  `refund_date` date NOT NULL,
  `topups` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`topups`)),
  `total_deposited` decimal(12,2) NOT NULL,
  `total_interest` decimal(12,2) NOT NULL,
  `grand_total` decimal(12,2) NOT NULL,
  `breakdown` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`breakdown`)),
  `notes` text DEFAULT NULL,
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `deposit_trust_interest` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `interest_date` date NOT NULL,
  `total_invested_funds` decimal(14,2) NOT NULL,
  `interest_earned` decimal(10,2) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `deposit_trust_interest_interest_date_unique` (`interest_date`),
  KEY `deposit_trust_interest_interest_date_index` (`interest_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `designations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `designations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `designations_name_unique` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `dev_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `dev_settings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(255) NOT NULL,
  `value` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `dev_settings_key_unique` (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `device_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `device_tokens` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `platform` varchar(16) NOT NULL,
  `token` varchar(512) NOT NULL,
  `app_version` varchar(32) DEFAULT NULL,
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `document_amendments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `document_id` bigint(20) unsigned NOT NULL,
  `signature_template_id` bigint(20) unsigned NOT NULL,
  `amended_by_request_id` bigint(20) unsigned DEFAULT NULL,
  `amendment_type` enum('addition','strikeout','modification','flag_raised') NOT NULL DEFAULT 'addition',
  `flag_origin` enum('agent_preparation','compliance_officer','signing_party') DEFAULT NULL,
  `flag_clause_ref` varchar(255) DEFAULT NULL,
  `flag_reason` text DEFAULT NULL,
  `section_reference` varchar(255) DEFAULT NULL,
  `original_text` text DEFAULT NULL,
  `new_text` text NOT NULL,
  `document_version_before` int(10) unsigned NOT NULL DEFAULT 1,
  `document_version_after` int(10) unsigned NOT NULL DEFAULT 2,
  `document_hash_before` varchar(64) DEFAULT NULL,
  `document_hash_after` varchar(64) DEFAULT NULL,
  `status` enum('pending','accepted','rejected') NOT NULL DEFAULT 'pending',
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `document_clause_strikethroughs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `signature_template_id` bigint(20) unsigned NOT NULL,
  `agency_id` bigint(20) unsigned DEFAULT NULL,
  `clause_ref` varchar(255) NOT NULL,
  `clause_original_text` text NOT NULL,
  `replacement_condition_id` bigint(20) unsigned DEFAULT NULL,
  `proposed_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `proposed_by_party_id` bigint(20) unsigned DEFAULT NULL,
  `amendment_id` bigint(20) unsigned NOT NULL,
  `status` enum('proposed','approved','rejected','superseded') NOT NULL,
  `approved_by_agent_at` timestamp NULL DEFAULT NULL,
  `rejected_by_agent_at` timestamp NULL DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `doc_strk_tpl_idx` (`signature_template_id`),
  KEY `doc_strk_agency_tpl_idx` (`agency_id`,`signature_template_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `document_conditions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `document_conditions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `signature_template_id` bigint(20) unsigned NOT NULL,
  `agency_id` bigint(20) unsigned DEFAULT NULL,
  `block_id` varchar(255) NOT NULL,
  `block_purpose` enum('other_conditions','included_items','excluded_items','custom_named') NOT NULL,
  `custom_label` varchar(255) DEFAULT NULL,
  `condition_number` int(10) unsigned NOT NULL,
  `content` text NOT NULL,
  `is_locked` tinyint(1) NOT NULL DEFAULT 0,
  `is_override` tinyint(1) NOT NULL DEFAULT 0,
  `overrides_clause_ref` varchar(255) DEFAULT NULL,
  `relates_to_clause_ref` varchar(255) DEFAULT NULL,
  `added_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `added_by_party_id` bigint(20) unsigned DEFAULT NULL,
  `added_via` enum('agent_preparation','agent_signing','recipient_signing','system_default') NOT NULL,
  `source` enum('library','custom') NOT NULL,
  `library_clause_id` bigint(20) unsigned DEFAULT NULL,
  `amendment_id` bigint(20) unsigned DEFAULT NULL,
  `approved_by_agent_at` timestamp NULL DEFAULT NULL,
  `approved_by_agent_user_id` bigint(20) unsigned DEFAULT NULL,
  `superseded_at` timestamp NULL DEFAULT NULL,
  `superseded_by_condition_id` bigint(20) unsigned DEFAULT NULL,
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `document_contact` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `document_id` bigint(20) unsigned NOT NULL,
  `contact_id` bigint(20) unsigned NOT NULL,
  `party_role` varchar(50) DEFAULT NULL,
  `document_type` varchar(50) DEFAULT NULL,
  `is_signed` tinyint(1) NOT NULL DEFAULT 0,
  `signed_at` timestamp NULL DEFAULT NULL,
  `signed_pdf_path` varchar(255) DEFAULT NULL,
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `document_contacts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `document_id` bigint(20) unsigned NOT NULL,
  `contact_id` bigint(20) unsigned NOT NULL,
  `party_role` varchar(50) DEFAULT NULL,
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `document_custom_fields` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `template_id` bigint(20) unsigned NOT NULL,
  `field_key` varchar(255) NOT NULL,
  `label` varchar(255) NOT NULL,
  `assigned_to` enum('agent','lessor','lessee','buyer','seller') NOT NULL DEFAULT 'agent',
  `field_type` enum('text','date','number') NOT NULL DEFAULT 'text',
  `default_value` varchar(255) DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `document_filing_register` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `branch_id` bigint(20) unsigned NOT NULL,
  `agency_id` bigint(20) unsigned NOT NULL,
  `agent_id` bigint(20) unsigned NOT NULL,
  `document_type` enum('OA','EA','Other') NOT NULL DEFAULT 'OA',
  `file_reference` varchar(255) NOT NULL,
  `sequence_number` varchar(255) NOT NULL,
  `property_address` varchar(255) NOT NULL,
  `seller_name` varchar(255) DEFAULT NULL,
  `expiry_date` date DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `captured_by` bigint(20) unsigned NOT NULL,
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
  CONSTRAINT `document_filing_register_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `document_filing_register_agent_id_foreign` FOREIGN KEY (`agent_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `document_filing_register_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE,
  CONSTRAINT `document_filing_register_captured_by_foreign` FOREIGN KEY (`captured_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `document_library_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `document_library_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uploaded_by_user_id` bigint(20) unsigned NOT NULL,
  `agency_id` bigint(20) unsigned NOT NULL,
  `original_name` varchar(255) NOT NULL,
  `stored_path` varchar(255) NOT NULL,
  `mime_type` varchar(255) DEFAULT NULL,
  `bytes` bigint(20) unsigned NOT NULL DEFAULT 0,
  `doc_type` varchar(255) NOT NULL DEFAULT 'other',
  `title` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `tags_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`tags_json`)),
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `document_library_types` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(50) NOT NULL,
  `label` varchar(100) NOT NULL,
  `sort_order` int(10) unsigned NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `document_types_key_unique` (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `document_properties`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `document_properties` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `document_id` bigint(20) unsigned NOT NULL,
  `property_id` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `document_properties_document_id_property_id_unique` (`document_id`,`property_id`),
  KEY `document_properties_property_id_foreign` (`property_id`),
  CONSTRAINT `document_properties_document_id_foreign` FOREIGN KEY (`document_id`) REFERENCES `documents` (`id`) ON DELETE CASCADE,
  CONSTRAINT `document_properties_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `document_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `document_types` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `slug` varchar(50) NOT NULL,
  `label` varchar(100) NOT NULL,
  `sort_order` int(10) unsigned NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `grouping` varchar(20) NOT NULL DEFAULT 'shared',
  `listing_types` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`listing_types`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `splitter_doc_types_slug_unique` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `documents` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `original_name` varchar(255) NOT NULL,
  `storage_path` varchar(500) NOT NULL,
  `disk` varchar(20) NOT NULL DEFAULT 'local',
  `mime_type` varchar(100) DEFAULT NULL,
  `size` bigint(20) unsigned NOT NULL DEFAULT 0,
  `document_type_id` bigint(20) unsigned DEFAULT NULL,
  `source_type` varchar(20) NOT NULL DEFAULT 'upload',
  `source_id` bigint(20) unsigned DEFAULT NULL,
  `uploaded_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `agency_id` bigint(20) unsigned DEFAULT NULL,
  `branch_id` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `documents_document_type_id_foreign` (`document_type_id`),
  KEY `documents_uploaded_by_foreign` (`uploaded_by`),
  KEY `documents_agency_id_index` (`agency_id`),
  KEY `documents_branch_id_foreign` (`branch_id`),
  KEY `documents_agency_branch_idx` (`agency_id`,`branch_id`),
  CONSTRAINT `documents_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `documents_document_type_id_foreign` FOREIGN KEY (`document_type_id`) REFERENCES `document_types` (`id`) ON DELETE SET NULL,
  CONSTRAINT `documents_uploaded_by_foreign` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `docuperfect_clause_branches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `docuperfect_clause_branches` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `clause_id` bigint(20) unsigned NOT NULL,
  `branch_id` bigint(20) unsigned NOT NULL,
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `docuperfect_clauses` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `text` text NOT NULL,
  `is_global` tinyint(1) NOT NULL DEFAULT 0,
  `owner_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `docuperfect_clauses_owner_id_foreign` (`owner_id`),
  CONSTRAINT `docuperfect_clauses_owner_id_foreign` FOREIGN KEY (`owner_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `docuperfect_document_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `docuperfect_document_types` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `docuperfect_documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `docuperfect_documents` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `template_id` bigint(20) unsigned DEFAULT NULL,
  `fields_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`fields_json`)),
  `web_template_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`web_template_data`)),
  `signed_paginated_html` longtext DEFAULT NULL,
  `owner_id` bigint(20) unsigned NOT NULL,
  `branch_id` bigint(20) unsigned DEFAULT NULL,
  `pack_instance_id` bigint(20) unsigned DEFAULT NULL,
  `archived_at` timestamp NULL DEFAULT NULL,
  `document_type` varchar(255) DEFAULT NULL,
  `property_address` varchar(255) DEFAULT NULL,
  `property_id` bigint(20) unsigned DEFAULT NULL,
  `lease_expiry_date` date DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `docuperfect_documents_template_id_foreign` (`template_id`),
  KEY `docuperfect_documents_owner_id_foreign` (`owner_id`),
  KEY `docuperfect_documents_branch_id_foreign` (`branch_id`),
  KEY `idx_dpdocs_prop_type_id` (`property_id`,`document_type`,`id`),
  CONSTRAINT `docuperfect_documents_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `docuperfect_documents_owner_id_foreign` FOREIGN KEY (`owner_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `docuperfect_documents_template_id_foreign` FOREIGN KEY (`template_id`) REFERENCES `docuperfect_templates` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `docuperfect_field_corrections`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `docuperfect_field_corrections` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `context` varchar(500) NOT NULL,
  `claude_suggested_key` varchar(255) NOT NULL,
  `claude_suggested_label` varchar(255) NOT NULL,
  `user_corrected_key` varchar(255) NOT NULL,
  `user_corrected_label` varchar(255) NOT NULL,
  `correction_reason` text DEFAULT NULL,
  `document_type` varchar(255) DEFAULT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `docuperfect_field_groups` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint(20) unsigned DEFAULT NULL,
  `created_by` bigint(20) unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `fields` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`fields`)),
  `layout` enum('vertical','horizontal') NOT NULL DEFAULT 'vertical',
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_global` tinyint(1) NOT NULL DEFAULT 0,
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `docuperfect_import_drafts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `filename` varchar(255) NOT NULL,
  `html` longtext NOT NULL,
  `fields_json` longtext DEFAULT NULL,
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `docuperfect_named_fields` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `field_type` varchar(255) NOT NULL DEFAULT 'text',
  `default_options` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`default_options`)),
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `source_type` enum('property','contact','agent','deal','static','computed','manual') NOT NULL DEFAULT 'manual',
  `source_column` varchar(255) DEFAULT NULL,
  `source_contact_type` varchar(50) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `docuperfect_pack_attachments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `docuperfect_pack_attachments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `pack_instance_id` bigint(20) unsigned NOT NULL,
  `knowledge_document_id` bigint(20) unsigned NOT NULL,
  `slot_label` varchar(255) NOT NULL,
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `docuperfect_pack_branches` (
  `pack_id` bigint(20) unsigned NOT NULL,
  `branch_id` bigint(20) unsigned NOT NULL,
  UNIQUE KEY `docuperfect_pack_branches_pack_id_branch_id_unique` (`pack_id`,`branch_id`),
  KEY `docuperfect_pack_branches_branch_id_foreign` (`branch_id`),
  CONSTRAINT `docuperfect_pack_branches_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE,
  CONSTRAINT `docuperfect_pack_branches_pack_id_foreign` FOREIGN KEY (`pack_id`) REFERENCES `docuperfect_packs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `docuperfect_pack_instance_values`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `docuperfect_pack_instance_values` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `pack_instance_id` bigint(20) unsigned NOT NULL,
  `named_field_id` bigint(20) unsigned NOT NULL,
  `value` text DEFAULT NULL,
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `docuperfect_pack_slots` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `pack_id` bigint(20) unsigned NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `label` varchar(255) NOT NULL,
  `slot_type` enum('required','selectable','attachment') NOT NULL,
  `template_id` bigint(20) unsigned DEFAULT NULL,
  `document_type_id` bigint(20) unsigned DEFAULT NULL,
  `knowledge_category_id` bigint(20) unsigned DEFAULT NULL,
  `allow_multiple` tinyint(1) NOT NULL DEFAULT 0,
  `is_optional` tinyint(1) NOT NULL DEFAULT 0,
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `docuperfect_pack_templates` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `pack_id` bigint(20) unsigned NOT NULL,
  `template_id` bigint(20) unsigned NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `docuperfect_packs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `is_global` tinyint(1) NOT NULL DEFAULT 0,
  `creation_mode` enum('individual','linked') NOT NULL DEFAULT 'linked',
  `owner_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `docuperfect_packs_owner_id_foreign` (`owner_id`),
  CONSTRAINT `docuperfect_packs_owner_id_foreign` FOREIGN KEY (`owner_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `docuperfect_template_branches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `docuperfect_template_branches` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `template_id` bigint(20) unsigned NOT NULL,
  `branch_id` bigint(20) unsigned NOT NULL,
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `docuperfect_template_signature_zones` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `template_id` bigint(20) unsigned NOT NULL,
  `page_index` int(11) NOT NULL,
  `x_position` decimal(8,4) NOT NULL,
  `y_position` decimal(8,4) NOT NULL,
  `width` decimal(8,4) NOT NULL DEFAULT 25.0000,
  `height` decimal(8,4) NOT NULL DEFAULT 6.0000,
  `type` enum('signature','initial') NOT NULL DEFAULT 'signature',
  `assigned_parties` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`assigned_parties`)),
  `label` varchar(255) DEFAULT NULL,
  `required` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `dtsz_template_page_idx` (`template_id`,`page_index`),
  CONSTRAINT `docuperfect_template_signature_zones_template_id_foreign` FOREIGN KEY (`template_id`) REFERENCES `docuperfect_templates` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `docuperfect_templates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `docuperfect_templates` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `template_type` varchar(255) NOT NULL DEFAULT 'sales',
  `render_type` enum('pdf','web') NOT NULL DEFAULT 'pdf',
  `blade_view` varchar(255) DEFAULT NULL,
  `document_type_id` bigint(20) unsigned DEFAULT NULL,
  `category` enum('sales','rentals') DEFAULT NULL,
  `page_count` int(11) NOT NULL DEFAULT 0,
  `fields_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`fields_json`)),
  `cds_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`cds_json`)),
  `field_mappings` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`field_mappings`)),
  `allowed_delivery_modes` varchar(100) NOT NULL DEFAULT 'esign,wet_ink,download',
  `security_tier` varchar(20) NOT NULL DEFAULT 'enhanced',
  `editor_state` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`editor_state`)),
  `wizard_config` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`wizard_config`)),
  `is_global` tinyint(1) NOT NULL DEFAULT 0,
  `is_esign` tinyint(1) NOT NULL DEFAULT 0,
  `party_mode` varchar(20) NOT NULL DEFAULT 'shared',
  `signing_parties` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`signing_parties`)),
  `insertable_blocks` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`insertable_blocks`)),
  `header_display` varchar(20) NOT NULL DEFAULT 'first_page',
  `owner_id` bigint(20) unsigned DEFAULT NULL,
  `archived_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `sections` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`sections`)),
  PRIMARY KEY (`id`),
  KEY `docuperfect_templates_owner_id_foreign` (`owner_id`),
  KEY `docuperfect_templates_document_type_id_foreign` (`document_type_id`),
  CONSTRAINT `docuperfect_templates_document_type_id_foreign` FOREIGN KEY (`document_type_id`) REFERENCES `document_types` (`id`) ON DELETE SET NULL,
  CONSTRAINT `docuperfect_templates_owner_id_foreign` FOREIGN KEY (`owner_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `domain_event_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `domain_event_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `event_id` char(36) NOT NULL,
  `trace_id` char(36) DEFAULT NULL,
  `event_name` varchar(150) NOT NULL,
  `agency_id` bigint(20) unsigned DEFAULT NULL,
  `actor_user_id` bigint(20) unsigned DEFAULT NULL,
  `subject_type` varchar(150) DEFAULT NULL,
  `subject_id` bigint(20) unsigned DEFAULT NULL,
  `payload_snapshot` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`payload_snapshot`)),
  `context` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`context`)),
  `occurred_at` datetime(6) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `dom_evt_event_id_unique` (`event_id`),
  KEY `dom_evt_trace_idx` (`trace_id`),
  KEY `dom_evt_name_idx` (`event_name`),
  KEY `dom_evt_agency_idx` (`agency_id`),
  KEY `dom_evt_actor_idx` (`actor_user_id`),
  KEY `dom_evt_subject_idx` (`subject_type`,`subject_id`),
  KEY `dom_evt_occurred_idx` (`occurred_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `employee_screening_checks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `employee_screening_checks` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `employee_screening_id` bigint(20) unsigned NOT NULL,
  `agency_id` bigint(20) unsigned NOT NULL,
  `check_type` enum('employment_history_verified','qualification_verified','references_checked','ppra_ffc_verified','criminal_record_check','credit_check','id_verification','address_verification','tfs_screening','previous_aml_role_review','high_risk_association_check') NOT NULL,
  `result` enum('clear','concerns','fail','not_applicable','pending') NOT NULL DEFAULT 'pending',
  `checked_on` date DEFAULT NULL,
  `checked_by` bigint(20) unsigned DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `reference_number` varchar(100) DEFAULT NULL,
  `supporting_document_id` bigint(20) unsigned DEFAULT NULL,
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `employee_screenings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint(20) unsigned NOT NULL,
  `branch_id` bigint(20) unsigned DEFAULT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `screening_type` enum('pre_employment','periodic','tfs_list_update','triggered') NOT NULL DEFAULT 'periodic',
  `risk_tier` enum('high','medium','low') NOT NULL DEFAULT 'medium',
  `status` enum('in_progress','completed','flagged','cancelled') NOT NULL DEFAULT 'in_progress',
  `initiated_on` date NOT NULL,
  `completed_on` date DEFAULT NULL,
  `next_due_on` date DEFAULT NULL,
  `initiated_by` bigint(20) unsigned DEFAULT NULL,
  `completed_by` bigint(20) unsigned DEFAULT NULL,
  `overall_result` enum('pass','concerns_flagged','fail') DEFAULT NULL,
  `summary_notes` text DEFAULT NULL,
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `esign_consent_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `flow_id` bigint(20) unsigned DEFAULT NULL,
  `document_id` bigint(20) unsigned DEFAULT NULL,
  `signature_request_id` bigint(20) unsigned DEFAULT NULL,
  `signing_party_id` bigint(20) unsigned DEFAULT NULL,
  `contact_id` bigint(20) unsigned DEFAULT NULL,
  `id_number_entered` text NOT NULL,
  `id_verified` tinyint(1) NOT NULL DEFAULT 0,
  `consent_text` text NOT NULL,
  `consent_accepted_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `ip_address` varchar(45) NOT NULL,
  `user_agent` text NOT NULL,
  `device_info` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`device_info`)),
  `document_hash` varchar(64) NOT NULL,
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
DROP TABLE IF EXISTS `esign_signing_parties`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `esign_signing_parties` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `flow_id` bigint(20) unsigned NOT NULL,
  `contact_id` bigint(20) unsigned NOT NULL,
  `role` varchar(30) NOT NULL,
  `display_name` varchar(255) NOT NULL,
  `id_number` text DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `signing_order` smallint(5) unsigned NOT NULL DEFAULT 1,
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `consented_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `declined_at` timestamp NULL DEFAULT NULL,
  `decline_reason` text DEFAULT NULL,
  `proxy_for_party_id` bigint(20) unsigned DEFAULT NULL,
  `proxy_poa_reference` varchar(255) DEFAULT NULL,
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
DROP TABLE IF EXISTS `failed_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `failed_jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(255) NOT NULL,
  `connection` text NOT NULL,
  `queue` text NOT NULL,
  `payload` longtext NOT NULL,
  `exception` longtext NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `fault_reports`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `fault_reports` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `type` enum('backend','frontend','manual') NOT NULL,
  `severity` enum('error','warning','info') NOT NULL DEFAULT 'error',
  `title` varchar(500) NOT NULL,
  `message` text DEFAULT NULL,
  `exception_class` varchar(255) DEFAULT NULL,
  `file` varchar(500) DEFAULT NULL,
  `line` int(11) DEFAULT NULL,
  `trace` text DEFAULT NULL,
  `url` varchar(1000) DEFAULT NULL,
  `method` varchar(10) DEFAULT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `agency_id` bigint(20) unsigned NOT NULL,
  `user_agent` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `request_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`request_data`)),
  `screenshot_path` varchar(500) DEFAULT NULL,
  `status` enum('new','investigating','fixed','ignored') NOT NULL DEFAULT 'new',
  `notes` text DEFAULT NULL,
  `resolved_by` bigint(20) unsigned DEFAULT NULL,
  `resolved_at` timestamp NULL DEFAULT NULL,
  `occurrence_count` int(11) NOT NULL DEFAULT 1,
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `feedback_attachments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `feedback_report_id` bigint(20) unsigned NOT NULL,
  `filename` varchar(255) NOT NULL,
  `original_name` varchar(255) NOT NULL,
  `mime_type` varchar(100) NOT NULL,
  `size_bytes` int(10) unsigned NOT NULL,
  `storage_path` varchar(500) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `feedback_attachments_feedback_report_id_foreign` (`feedback_report_id`),
  CONSTRAINT `feedback_attachments_feedback_report_id_foreign` FOREIGN KEY (`feedback_report_id`) REFERENCES `feedback_reports` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `feedback_reports`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `feedback_reports` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `type` enum('bug','enhancement','question','compliment','other') NOT NULL,
  `severity` enum('critical','major','minor') DEFAULT NULL,
  `title` varchar(200) NOT NULL,
  `description` text NOT NULL,
  `steps_to_reproduce` text DEFAULT NULL,
  `expected_behaviour` text DEFAULT NULL,
  `actual_behaviour` text DEFAULT NULL,
  `page_url` varchar(500) DEFAULT NULL,
  `page_title` varchar(200) DEFAULT NULL,
  `module_tag` varchar(50) DEFAULT NULL,
  `browser` varchar(100) DEFAULT NULL,
  `os` varchar(50) DEFAULT NULL,
  `viewport_width` smallint(5) unsigned DEFAULT NULL,
  `viewport_height` smallint(5) unsigned DEFAULT NULL,
  `submitted_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `server_log_window_start` timestamp NULL DEFAULT NULL,
  `server_log_window_end` timestamp NULL DEFAULT NULL,
  `status` enum('new','reviewing','in_progress','fixed','wont_fix','duplicate','deferred') NOT NULL DEFAULT 'new',
  `assigned_to_user_id` bigint(20) unsigned DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `reviewed_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `resolution_notes` text DEFAULT NULL,
  `resolved_at` timestamp NULL DEFAULT NULL,
  `resolved_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `related_commit` varchar(100) DEFAULT NULL,
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `fica_compliance_officers_deprecated_20260421` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `assigned_by` bigint(20) unsigned NOT NULL,
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `fica_documents` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `fica_submission_id` bigint(20) unsigned NOT NULL,
  `agency_id` bigint(20) unsigned NOT NULL,
  `document_type` varchar(50) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_size` int(10) unsigned NOT NULL DEFAULT 0,
  `mime_type` varchar(100) DEFAULT NULL,
  `status` enum('uploaded','accepted','rejected') NOT NULL DEFAULT 'uploaded',
  `rejection_reason` varchar(255) DEFAULT NULL,
  `uploaded_at` datetime DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `uploaded_by` bigint(20) unsigned DEFAULT NULL,
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `fica_officer_appointments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint(20) unsigned NOT NULL,
  `branch_id` bigint(20) unsigned DEFAULT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `role` enum('primary_compliance_officer','mlro') NOT NULL,
  `full_name` varchar(200) NOT NULL,
  `id_number` varchar(20) DEFAULT NULL,
  `cell` varchar(50) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `title` varchar(100) NOT NULL DEFAULT 'FICA Compliance Officer',
  `appointed_on` date NOT NULL,
  `ended_on` date DEFAULT NULL,
  `appointed_by` bigint(20) unsigned DEFAULT NULL,
  `appointment_letter_path` varchar(500) DEFAULT NULL,
  `notes` text DEFAULT NULL,
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `fica_resend_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `fica_submission_id` bigint(20) unsigned NOT NULL,
  `agency_id` bigint(20) unsigned NOT NULL,
  `resent_by` bigint(20) unsigned NOT NULL,
  `resent_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `reason_code` varchar(50) DEFAULT NULL,
  `notes` text DEFAULT NULL,
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
DROP TABLE IF EXISTS `fica_submissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `fica_submissions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `contact_id` bigint(20) unsigned DEFAULT NULL,
  `agency_id` bigint(20) unsigned NOT NULL,
  `branch_id` bigint(20) unsigned DEFAULT NULL,
  `requested_by` bigint(20) unsigned NOT NULL,
  `token` varchar(64) DEFAULT NULL,
  `token_expires_at` datetime DEFAULT NULL,
  `entity_type` enum('natural','company','trust','partnership') NOT NULL DEFAULT 'natural',
  `form_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`form_data`)),
  `status` enum('draft','submitted','under_review','agent_approved','corrections_requested','approved','rejected','cancelled') DEFAULT 'draft',
  `intake_type` enum('online','wet_ink') NOT NULL DEFAULT 'online',
  `wet_ink_received_date` date DEFAULT NULL,
  `wet_ink_confirmed_by` bigint(20) unsigned DEFAULT NULL,
  `risk_rating` tinyint(4) DEFAULT NULL,
  `verification_method` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`verification_method`)),
  `verified_by` bigint(20) unsigned DEFAULT NULL,
  `verified_at` datetime DEFAULT NULL,
  `fica_expires_at` date DEFAULT NULL,
  `reviewer_notes` text DEFAULT NULL,
  `agent_verified_by` bigint(20) unsigned DEFAULT NULL,
  `agent_verified_at` datetime DEFAULT NULL,
  `agent_verification_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`agent_verification_data`)),
  `agent_notes` text DEFAULT NULL,
  `co_verified_by` bigint(20) unsigned DEFAULT NULL,
  `co_verified_at` datetime DEFAULT NULL,
  `co_verification_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`co_verification_data`)),
  `co_notes` text DEFAULT NULL,
  `co_signature_data` longtext DEFAULT NULL,
  `pdf_path` varchar(255) DEFAULT NULL,
  `signature_data` longtext DEFAULT NULL,
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
  KEY `fica_submissions_branch_id_foreign` (`branch_id`),
  KEY `fica_submissions_agency_branch_idx` (`agency_id`,`branch_id`),
  KEY `fica_submissions_wet_ink_confirmed_by_foreign` (`wet_ink_confirmed_by`),
  KEY `fica_submissions_fica_expires_at_index` (`fica_expires_at`),
  CONSTRAINT `fica_submissions_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fica_submissions_agent_verified_by_foreign` FOREIGN KEY (`agent_verified_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fica_submissions_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fica_submissions_co_verified_by_foreign` FOREIGN KEY (`co_verified_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fica_submissions_contact_id_foreign` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fica_submissions_requested_by_foreign` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fica_submissions_verified_by_foreign` FOREIGN KEY (`verified_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fica_submissions_wet_ink_confirmed_by_foreign` FOREIGN KEY (`wet_ink_confirmed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `finance_audit_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `finance_audit_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `audit_run_id` bigint(20) unsigned NOT NULL,
  `agency_id` bigint(20) unsigned NOT NULL,
  `definition_key` varchar(255) NOT NULL,
  `entity_type` varchar(255) NOT NULL,
  `entity_id` bigint(20) unsigned NOT NULL,
  `period` varchar(255) DEFAULT NULL,
  `expected_numeric` decimal(18,6) DEFAULT NULL,
  `actual_numeric` decimal(18,6) DEFAULT NULL,
  `diff_numeric` decimal(18,6) DEFAULT NULL,
  `expected_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`expected_json`)),
  `actual_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`actual_json`)),
  `diff_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`diff_json`)),
  `severity` varchar(255) NOT NULL DEFAULT 'info' COMMENT 'info|warn|error',
  `message` varchar(255) DEFAULT NULL,
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `finance_audit_runs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `period` varchar(255) NOT NULL COMMENT 'YYYY-MM',
  `scope` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'e.g. {branch_id: 1, deal_ids: []}' CHECK (json_valid(`scope`)),
  `status` varchar(255) NOT NULL DEFAULT 'running' COMMENT 'running|complete|failed',
  `engine_version` varchar(20) NOT NULL DEFAULT 'v0',
  `started_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `finished_at` timestamp NULL DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `agency_id` bigint(20) unsigned NOT NULL,
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `finance_computed_values` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `definition_id` bigint(20) unsigned NOT NULL,
  `definition_key` varchar(255) NOT NULL,
  `definition_version` int(10) unsigned NOT NULL,
  `entity_type` varchar(255) NOT NULL,
  `entity_id` bigint(20) unsigned NOT NULL,
  `period` varchar(255) DEFAULT NULL COMMENT 'YYYY-MM',
  `value_numeric` decimal(18,6) DEFAULT NULL,
  `value_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`value_json`)),
  `input_hash` varchar(64) DEFAULT NULL,
  `engine_version` varchar(20) NOT NULL DEFAULT 'v0',
  `computed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `audit_run_id` bigint(20) unsigned DEFAULT NULL,
  `agency_id` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fcv_def_entity_period_unique` (`definition_id`,`entity_type`,`entity_id`,`period`),
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `finance_definitions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(255) NOT NULL COMMENT 'e.g. deal.total_commission_ex_vat',
  `version` int(10) unsigned NOT NULL DEFAULT 1,
  `status` varchar(255) NOT NULL DEFAULT 'draft' COMMENT 'draft|active|retired',
  `entity_type` varchar(255) NOT NULL COMMENT 'e.g. deal',
  `value_type` varchar(255) NOT NULL COMMENT 'money_ex_vat|money_inc_vat|percent|count|json',
  `expression` text DEFAULT NULL,
  `dependencies` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`dependencies`)),
  `rounding_scale` smallint(5) unsigned NOT NULL DEFAULT 2,
  `notes` text DEFAULT NULL,
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `flag_removal_requests` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `signature_template_id` bigint(20) unsigned NOT NULL,
  `document_amendment_id` bigint(20) unsigned NOT NULL,
  `clause_ref` varchar(50) NOT NULL,
  `requested_by_user_id` bigint(20) unsigned NOT NULL,
  `requested_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `reason` text NOT NULL,
  `recipient_signing_party_id` bigint(20) unsigned NOT NULL,
  `consent_token` varchar(64) NOT NULL,
  `consent_sent_at` timestamp NULL DEFAULT NULL,
  `consent_received_at` timestamp NULL DEFAULT NULL,
  `consent_ip_address` varchar(45) DEFAULT NULL,
  `consent_user_agent` text DEFAULT NULL,
  `consent_signature_data` text DEFAULT NULL,
  `status` enum('pending','consented','rejected','expired','cancelled') NOT NULL DEFAULT 'pending',
  `expires_at` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `flows` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `type` varchar(50) NOT NULL,
  `template_id` bigint(20) unsigned DEFAULT NULL,
  `pack_id` bigint(20) unsigned DEFAULT NULL,
  `pack_type` varchar(255) DEFAULT NULL,
  `flow_sequence` int(10) unsigned NOT NULL DEFAULT 0,
  `parent_flow_id` bigint(20) unsigned DEFAULT NULL,
  `pack_status` varchar(255) DEFAULT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `property_id` bigint(20) unsigned DEFAULT NULL,
  `contact_id` bigint(20) unsigned DEFAULT NULL,
  `current_step` int(10) unsigned NOT NULL DEFAULT 1,
  `step_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`step_data`)),
  `status` enum('active','completed','abandoned','draft') NOT NULL DEFAULT 'active',
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `geocoding_cache` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `address_normalised` varchar(500) NOT NULL,
  `address_raw` varchar(500) NOT NULL,
  `latitude` decimal(10,7) DEFAULT NULL,
  `longitude` decimal(10,7) DEFAULT NULL,
  `confidence` enum('exact','street','suburb','town','failed') NOT NULL DEFAULT 'failed',
  `google_location_type` varchar(30) DEFAULT NULL,
  `source` enum('market_report','portal_capture','p24','google','nominatim','manual','cache') NOT NULL DEFAULT 'cache',
  `source_ref` varchar(200) DEFAULT NULL,
  `resolved_address` varchar(500) DEFAULT NULL,
  `municipality_name` varchar(100) DEFAULT NULL,
  `suburb_normalised` varchar(100) DEFAULT NULL,
  `failure_reason` varchar(200) DEFAULT NULL,
  `hit_count` int(10) unsigned NOT NULL DEFAULT 0,
  `last_hit_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `last_attempted_at` timestamp NOT NULL DEFAULT current_timestamp(),
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `geocoding_runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `geocoding_runs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `batch_id` char(36) DEFAULT NULL,
  `entity_type` varchar(50) DEFAULT NULL,
  `entity_id` bigint(20) unsigned DEFAULT NULL,
  `address` varchar(500) NOT NULL,
  `result` enum('resolved','failed','cached') NOT NULL DEFAULT 'failed',
  `source` varchar(30) DEFAULT NULL,
  `confidence` varchar(20) DEFAULT NULL,
  `latency_ms` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `geocoding_runs_entity_type_entity_id_index` (`entity_type`,`entity_id`),
  KEY `geocoding_runs_result_index` (`result`),
  KEY `geocoding_runs_created_at_index` (`created_at`),
  KEY `geocoding_runs_batch_id_index` (`batch_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `holding_cost_data_points`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `holding_cost_data_points` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint(20) unsigned NOT NULL,
  `presentation_version_id` bigint(20) unsigned DEFAULT NULL,
  `property_id` bigint(20) unsigned DEFAULT NULL,
  `tracked_property_id` bigint(20) unsigned DEFAULT NULL,
  `component` enum('rates','levy','insurance','utilities','garden','pool','security','bond','opportunity_cost') NOT NULL,
  `monthly_value_zar` bigint(20) unsigned NOT NULL,
  `scheme_name` varchar(255) DEFAULT NULL,
  `suburb_normalised` varchar(100) DEFAULT NULL,
  `municipality` varchar(100) DEFAULT NULL,
  `property_type` varchar(64) DEFAULT NULL,
  `title_type` enum('full_title','sectional_title','vacant_land','other') DEFAULT NULL,
  `property_value_band` varchar(16) DEFAULT NULL,
  `source` enum('agent_override','cma_import','manual_capture','property_record') NOT NULL,
  `source_ref` varchar(200) DEFAULT NULL COMMENT 'e.g. property_id, market_report_id, presentation_id',
  `entered_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `is_excluded` tinyint(1) NOT NULL DEFAULT 0,
  `excluded_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `excluded_at` timestamp NULL DEFAULT NULL,
  `exclusion_reason` varchar(200) DEFAULT NULL,
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `impersonation_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `admin_user_id` bigint(20) unsigned NOT NULL,
  `target_user_id` bigint(20) unsigned NOT NULL,
  `action` enum('start','stop') NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `impersonation_logs_target_user_id_created_at_index` (`target_user_id`,`created_at`),
  KEY `impersonation_logs_admin_user_id_created_at_index` (`admin_user_id`,`created_at`),
  CONSTRAINT `impersonation_logs_admin_user_id_foreign` FOREIGN KEY (`admin_user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `impersonation_logs_target_user_id_foreign` FOREIGN KEY (`target_user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `information_officer_appointments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `information_officer_appointments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint(20) unsigned NOT NULL,
  `branch_id` bigint(20) unsigned DEFAULT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `role` enum('primary_information_officer','deputy_information_officer') NOT NULL,
  `full_name` varchar(200) NOT NULL,
  `id_number` varchar(20) DEFAULT NULL,
  `cell` varchar(50) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `title` varchar(100) NOT NULL DEFAULT 'Information Officer',
  `appointed_on` date NOT NULL,
  `ended_on` date DEFAULT NULL,
  `appointed_by` bigint(20) unsigned DEFAULT NULL,
  `appointment_letter_path` varchar(500) DEFAULT NULL,
  `notes` text DEFAULT NULL,
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `job_batches` (
  `id` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `total_jobs` int(11) NOT NULL,
  `pending_jobs` int(11) NOT NULL,
  `failed_jobs` int(11) NOT NULL,
  `failed_job_ids` longtext NOT NULL,
  `options` mediumtext DEFAULT NULL,
  `cancelled_at` int(11) DEFAULT NULL,
  `created_at` int(11) NOT NULL,
  `finished_at` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `queue` varchar(255) NOT NULL,
  `payload` longtext NOT NULL,
  `attempts` tinyint(3) unsigned NOT NULL,
  `reserved_at` int(10) unsigned DEFAULT NULL,
  `available_at` int(10) unsigned NOT NULL,
  `created_at` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `jobs_queue_index` (`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `knowledge_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `knowledge_categories` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `slug` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `icon` varchar(255) DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `knowledge_categories_slug_unique` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `knowledge_chunks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `knowledge_chunks` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `document_id` bigint(20) unsigned NOT NULL,
  `chunk_index` int(10) unsigned NOT NULL,
  `content` text NOT NULL,
  `section_title` varchar(255) DEFAULT NULL,
  `page_number` int(10) unsigned DEFAULT NULL,
  `char_count` int(10) unsigned NOT NULL DEFAULT 0,
  `word_count` int(10) unsigned NOT NULL DEFAULT 0,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata`)),
  `embedding` longtext DEFAULT NULL,
  `has_embedding` tinyint(1) NOT NULL DEFAULT 0,
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `knowledge_documents` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `category_id` bigint(20) unsigned NOT NULL,
  `uploaded_by` bigint(20) unsigned NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `file_path` varchar(255) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_type` varchar(20) NOT NULL,
  `file_size` int(10) unsigned NOT NULL DEFAULT 0,
  `chunk_count` int(10) unsigned NOT NULL DEFAULT 0,
  `page_count` int(10) unsigned DEFAULT NULL,
  `status` enum('processing','ready','error') NOT NULL DEFAULT 'processing',
  `error_message` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `is_ellie_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `version` varchar(50) DEFAULT NULL,
  `effective_date` date DEFAULT NULL,
  `expiry_date` date DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `knowledge_documents_uploaded_by_foreign` (`uploaded_by`),
  KEY `knowledge_documents_category_id_is_active_index` (`category_id`,`is_active`),
  KEY `knowledge_documents_status_index` (`status`),
  CONSTRAINT `knowledge_documents_category_id_foreign` FOREIGN KEY (`category_id`) REFERENCES `knowledge_categories` (`id`) ON DELETE CASCADE,
  CONSTRAINT `knowledge_documents_uploaded_by_foreign` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `lease_records`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `lease_records` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `document_id` bigint(20) unsigned NOT NULL,
  `signature_template_id` bigint(20) unsigned NOT NULL,
  `property_id` bigint(20) unsigned DEFAULT NULL,
  `property_address` varchar(255) NOT NULL,
  `tenant_name` varchar(255) NOT NULL,
  `tenant_email` varchar(255) NOT NULL,
  `landlord_name` varchar(255) NOT NULL,
  `landlord_email` varchar(255) NOT NULL,
  `rental_amount` decimal(12,2) NOT NULL,
  `lease_start_date` date NOT NULL,
  `lease_end_date` date NOT NULL,
  `status` enum('active','expiring_soon','expired','renewed','terminated') NOT NULL DEFAULT 'active',
  `previous_lease_id` bigint(20) unsigned DEFAULT NULL,
  `renewed_lease_id` bigint(20) unsigned DEFAULT NULL,
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
DROP TABLE IF EXISTS `leave_application_documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `leave_application_documents` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `leave_application_id` bigint(20) unsigned NOT NULL,
  `document_id` bigint(20) unsigned NOT NULL,
  `document_role` enum('medical_certificate','supporting','signed_application_form','other') NOT NULL,
  `uploaded_by_user_id` bigint(20) unsigned NOT NULL,
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `leave_applications` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint(20) unsigned NOT NULL,
  `branch_id` bigint(20) unsigned DEFAULT NULL,
  `payroll_employee_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `leave_type_id` bigint(20) unsigned NOT NULL,
  `application_number` varchar(30) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `is_half_day` tinyint(1) NOT NULL DEFAULT 0,
  `half_day_period` enum('morning','afternoon') DEFAULT NULL,
  `working_days_requested` decimal(5,2) NOT NULL,
  `calendar_days_requested` smallint(5) unsigned NOT NULL,
  `reason` text DEFAULT NULL,
  `status` enum('draft','submitted','approved','rejected','cancelled','taken','no_show') NOT NULL DEFAULT 'submitted',
  `submitted_at` timestamp NULL DEFAULT NULL,
  `decided_at` timestamp NULL DEFAULT NULL,
  `decided_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `decided_by_role` enum('branch_manager','admin','owner') DEFAULT NULL,
  `decision_reason` text DEFAULT NULL,
  `taken_at` timestamp NULL DEFAULT NULL,
  `cancelled_at` timestamp NULL DEFAULT NULL,
  `cancelled_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `cancellation_reason` text DEFAULT NULL,
  `payslip_id` bigint(20) unsigned DEFAULT NULL,
  `affects_payroll` tinyint(1) NOT NULL DEFAULT 0,
  `payroll_impact_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `notes` text DEFAULT NULL,
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `leave_entitlements` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint(20) unsigned NOT NULL,
  `branch_id` bigint(20) unsigned DEFAULT NULL,
  `payroll_employee_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `leave_type_id` bigint(20) unsigned NOT NULL,
  `cycle_start_date` date NOT NULL,
  `cycle_end_date` date NOT NULL,
  `entitlement_days` decimal(5,2) NOT NULL DEFAULT 0.00,
  `accrued_days` decimal(5,2) NOT NULL DEFAULT 0.00,
  `carryover_from_previous_cycle` decimal(5,2) NOT NULL DEFAULT 0.00,
  `taken_days` decimal(5,2) NOT NULL DEFAULT 0.00,
  `pending_days` decimal(5,2) NOT NULL DEFAULT 0.00,
  `available_days` decimal(5,2) NOT NULL DEFAULT 0.00 COMMENT 'Derived: accrued + carryover - taken - pending. Updated by LeaveBalanceService.',
  `last_accrual_run_at` timestamp NULL DEFAULT NULL,
  `notes` text DEFAULT NULL,
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `leave_transactions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint(20) unsigned NOT NULL,
  `payroll_employee_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `leave_type_id` bigint(20) unsigned NOT NULL,
  `cycle_start_date` date NOT NULL,
  `transaction_type` enum('opening_balance','accrual','application_approved','application_cancelled','manual_adjustment','carry_over','forfeiture','termination_payout','reversal') NOT NULL,
  `days_delta` decimal(7,3) NOT NULL,
  `effective_date` date NOT NULL,
  `description` text NOT NULL,
  `source_type` varchar(255) DEFAULT NULL,
  `source_id` bigint(20) unsigned DEFAULT NULL,
  `created_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `reversal_of_transaction_id` bigint(20) unsigned DEFAULT NULL,
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `leave_types` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint(20) unsigned NOT NULL,
  `code` varchar(50) NOT NULL,
  `label` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `category` enum('annual','sick','family_responsibility','parental','study','unpaid','special','other') NOT NULL,
  `is_paid` tinyint(1) NOT NULL DEFAULT 1,
  `is_uif_claimable` tinyint(1) NOT NULL DEFAULT 0,
  `requires_documentation` tinyint(1) NOT NULL DEFAULT 0,
  `documentation_label` varchar(255) DEFAULT NULL,
  `documentation_threshold_days` smallint(5) unsigned DEFAULT NULL,
  `entitlement_days_per_cycle` decimal(5,2) NOT NULL DEFAULT 0.00,
  `entitlement_days_per_cycle_six_day` decimal(5,2) NOT NULL DEFAULT 0.00,
  `cycle_months` smallint(5) unsigned NOT NULL DEFAULT 12,
  `accrual_method` enum('full_at_start','accrual_per_day_worked','accrual_first_six_months','none') NOT NULL,
  `accrual_rate_per_days` smallint(5) unsigned NOT NULL DEFAULT 17,
  `accrual_starts_at_employment_date` tinyint(1) NOT NULL DEFAULT 1,
  `requires_pre_approval` tinyint(1) NOT NULL DEFAULT 1,
  `min_advance_notice_days` smallint(5) unsigned NOT NULL DEFAULT 0,
  `allows_negative_balance` tinyint(1) NOT NULL DEFAULT 0,
  `carries_over_to_next_cycle` tinyint(1) NOT NULL DEFAULT 1,
  `forfeit_after_months` smallint(5) unsigned DEFAULT NULL,
  `payout_on_termination` tinyint(1) NOT NULL DEFAULT 0,
  `affects_payroll` tinyint(1) NOT NULL DEFAULT 0,
  `is_system` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `legal_block_audit_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint(20) unsigned DEFAULT NULL,
  `template_id` bigint(20) unsigned NOT NULL,
  `template_name` varchar(255) NOT NULL,
  `document_type_slug` varchar(255) DEFAULT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `block_reason` enum('document_type_match','name_pattern_match') NOT NULL,
  `matched_pattern` varchar(255) DEFAULT NULL,
  `request_context` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`request_context`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `legal_block_audit_log_agency_id_created_at_index` (`agency_id`,`created_at`),
  KEY `legal_block_audit_log_template_id_index` (`template_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `listing_import_rows`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `listing_import_rows` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `run_id` bigint(20) unsigned NOT NULL,
  `agency_id` bigint(20) unsigned NOT NULL,
  `external_id` varchar(100) DEFAULT NULL,
  `external_ref` varchar(100) DEFAULT NULL,
  `property` varchar(255) DEFAULT NULL,
  `status` varchar(50) DEFAULT NULL,
  `price_cents` bigint(20) DEFAULT NULL,
  `file_agent` varchar(255) DEFAULT NULL,
  `resolved_user_id` bigint(20) unsigned DEFAULT NULL,
  `matched_listing_stock_id` bigint(20) unsigned DEFAULT NULL,
  `match_confidence` varchar(20) DEFAULT NULL,
  `decision` varchar(30) NOT NULL DEFAULT 'pending',
  `row_payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`row_payload`)),
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `listing_import_runs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `imported_by_user_id` bigint(20) unsigned NOT NULL,
  `agency_id` bigint(20) unsigned NOT NULL,
  `branch_id` bigint(20) unsigned DEFAULT NULL,
  `source` varchar(50) NOT NULL DEFAULT 'propcon',
  `original_filename` varchar(255) DEFAULT NULL,
  `header_row` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`header_row`)),
  `column_mapping` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`column_mapping`)),
  `agent_mapping` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`agent_mapping`)),
  `status` varchar(30) NOT NULL DEFAULT 'draft',
  `error_message` text DEFAULT NULL,
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `listing_snapshots` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `period` varchar(255) NOT NULL,
  `branch_id` bigint(20) unsigned DEFAULT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `agency_id` bigint(20) unsigned NOT NULL,
  `listing_count` int(11) NOT NULL DEFAULT 0,
  `avg_listing_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `updated_by` bigint(20) unsigned DEFAULT NULL,
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `listing_stock_agents` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `listing_stock_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `listing_stocks` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `agency_id` bigint(20) unsigned NOT NULL,
  `branch_id` bigint(20) unsigned DEFAULT NULL,
  `source` varchar(50) NOT NULL DEFAULT 'propcon',
  `external_id` varchar(100) DEFAULT NULL,
  `external_ref` varchar(100) DEFAULT NULL,
  `property` varchar(255) DEFAULT NULL,
  `status` varchar(50) DEFAULT NULL,
  `price_cents` bigint(20) DEFAULT NULL,
  `cma_price_cents` bigint(20) DEFAULT NULL,
  `cma_updated_at` timestamp NULL DEFAULT NULL,
  `category` varchar(50) DEFAULT NULL,
  `type` varchar(80) DEFAULT NULL,
  `region` varchar(120) DEFAULT NULL,
  `mandate` varchar(80) DEFAULT NULL,
  `listed_at` timestamp NULL DEFAULT NULL,
  `modified_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `raw_payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`raw_payload`)),
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `listing_targets` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `agency_id` bigint(20) unsigned NOT NULL,
  `period` varchar(7) NOT NULL,
  `target_listings` int(11) NOT NULL DEFAULT 0,
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
DROP TABLE IF EXISTS `map_saved_searches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `map_saved_searches` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `name` varchar(120) NOT NULL,
  `filter_payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`filter_payload`)),
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `market_analytics_runs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `model_version` varchar(32) NOT NULL COMMENT 'Semver-style model identifier e.g. v1.0.0',
  `inputs_hash` varchar(64) NOT NULL COMMENT 'SHA-256 of canonical inputs JSON',
  `inputs_json` text NOT NULL COMMENT 'Canonical serialised input parameters',
  `outputs_json` text DEFAULT NULL COMMENT 'Flat key-value of computed metrics',
  `breakdown_json` text DEFAULT NULL COMMENT 'Detailed per-metric breakdown',
  `data_sources_json` text DEFAULT NULL COMMENT 'Records of data sources consulted',
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `agency_id` bigint(20) unsigned NOT NULL,
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `market_data_discrepancies` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `report_id` bigint(20) unsigned DEFAULT NULL,
  `data_point_id` bigint(20) unsigned NOT NULL,
  `parsed_value` text NOT NULL COMMENT 'What the deterministic parser said.',
  `audit_value` text NOT NULL COMMENT 'What the AI re-extraction said.',
  `discrepancy_type` enum('value_mismatch','date_mismatch','address_mismatch','missing','extra') NOT NULL,
  `severity` enum('low','medium','high') NOT NULL DEFAULT 'low',
  `resolved` tinyint(1) NOT NULL DEFAULT 0,
  `resolved_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `resolved_at` timestamp NULL DEFAULT NULL,
  `resolution_notes` text DEFAULT NULL,
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `market_data_points` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint(20) unsigned NOT NULL,
  `report_id` bigint(20) unsigned DEFAULT NULL,
  `tracked_property_id` bigint(20) unsigned DEFAULT NULL,
  `suburb_normalised` varchar(100) DEFAULT NULL COMMENT 'Lowercase + strip punctuation; used for suburb-level data points.',
  `town` varchar(100) DEFAULT NULL,
  `metric_key` varchar(100) NOT NULL COMMENT 'e.g. median_price_3bed_house, total_sales_yoy, municipal_valuation, last_sale_price',
  `metric_value_numeric` decimal(15,2) DEFAULT NULL,
  `metric_value_date` date DEFAULT NULL,
  `metric_value_string` text DEFAULT NULL,
  `metric_date` date NOT NULL COMMENT 'The date the metric applies to (e.g. "Q1 2026" → 2026-01-01).',
  `confidence` enum('low','medium','high','verified') NOT NULL DEFAULT 'medium',
  `source_type` varchar(50) NOT NULL COMMENT 'Mirrors market_reports.source_type but allows API origins (lightstone_api, deeds_api, …).',
  `source_ref` varchar(200) DEFAULT NULL,
  `is_superseded` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Newer report invalidates this point.',
  `superseded_by_id` bigint(20) unsigned DEFAULT NULL,
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `market_report_comp_rows` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `market_report_id` bigint(20) unsigned DEFAULT NULL,
  `agency_id` bigint(20) unsigned NOT NULL,
  `row_index` smallint(5) unsigned NOT NULL DEFAULT 0 COMMENT '0-based order within the report; subject row is typically 0.',
  `row_type` enum('subject','comp','listing','owner') NOT NULL COMMENT 'subject = the property being valued; comp = sold comparable; listing = active for-sale; owner = scheme owner entry.',
  `scheme_name` varchar(255) DEFAULT NULL,
  `section_number` varchar(32) DEFAULT NULL,
  `flat_number` varchar(32) DEFAULT NULL,
  `ss_number` varchar(32) DEFAULT NULL COMMENT 'Scheme Sectional Title (SS) registration number.',
  `ss_year` smallint(5) unsigned DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `suburb_normalised` varchar(100) DEFAULT NULL,
  `property_type` varchar(64) DEFAULT NULL,
  `extent_m2` int(10) unsigned DEFAULT NULL,
  `sale_date` date DEFAULT NULL,
  `sale_price` bigint(20) unsigned DEFAULT NULL COMMENT 'Rands (whole), matches presentation_sold_comps.sold_price_inc convention.',
  `estimated_value` bigint(20) unsigned DEFAULT NULL,
  `r_per_m2` int(10) unsigned DEFAULT NULL,
  `list_price` bigint(20) unsigned DEFAULT NULL,
  `days_on_market` smallint(5) unsigned DEFAULT NULL,
  `municipal_valuation` bigint(20) unsigned DEFAULT NULL,
  `municipal_valuation_year` smallint(5) unsigned DEFAULT NULL,
  `condition` varchar(64) DEFAULT NULL,
  `distance_to_subject_m` smallint(5) unsigned DEFAULT NULL,
  `latitude` decimal(10,7) DEFAULT NULL,
  `longitude` decimal(10,7) DEFAULT NULL,
  `raw_row_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Full extracted row payload for audit + future re-parse.' CHECK (json_valid(`raw_row_json`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `is_demo` tinyint(1) NOT NULL DEFAULT 0,
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `market_report_types` (
  `id` smallint(5) unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(255) NOT NULL COMMENT 'Stable identifier, e.g. cma_info_market_analysis',
  `display_name` varchar(255) NOT NULL COMMENT 'Human-readable, e.g. "CMA Info Market Analysis"',
  `parser_class` varchar(255) NOT NULL COMMENT 'FQCN of the parser, e.g. App\\Services\\MarketReports\\Parsers\\CmaInfoMarketAnalysisParser',
  `expected_fields_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT 'What the parser yields — used for validation + spot-check.' CHECK (json_valid(`expected_fields_json`)),
  `auto_approve` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'If true, skip manual review when spot-check passes.',
  `sample_file_path` varchar(255) DEFAULT NULL COMMENT 'Path to a representative sample for parser regression tests.',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `market_report_types_key_unique` (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Lookup of supported report types. Seeded in Phase A2.';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `market_reports`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `market_reports` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint(20) unsigned NOT NULL,
  `uploaded_by_user_id` bigint(20) unsigned NOT NULL,
  `report_type_id` smallint(5) unsigned DEFAULT NULL,
  `file_path` varchar(255) NOT NULL COMMENT 'Storage path under storage/app/',
  `file_name` varchar(255) NOT NULL COMMENT 'Original filename as uploaded',
  `file_hash` varchar(64) NOT NULL COMMENT 'sha256 hex; dedup within agency',
  `source_suburb` varchar(255) DEFAULT NULL COMMENT 'Auto-detected from filename / first-page OCR, or agent-supplied at upload',
  `source_town` varchar(255) DEFAULT NULL,
  `subject_address` varchar(255) DEFAULT NULL,
  `subject_scheme_name` varchar(255) DEFAULT NULL,
  `subject_section_number` varchar(32) DEFAULT NULL,
  `subject_latitude` decimal(10,7) DEFAULT NULL,
  `subject_longitude` decimal(10,7) DEFAULT NULL,
  `subject_extent_m2` int(10) unsigned DEFAULT NULL,
  `radius_metres` int(10) unsigned DEFAULT NULL,
  `report_date` date NOT NULL COMMENT 'Date the report was generated (per the document), NOT uploaded_at',
  `parse_status` enum('pending','parsing','parsed','failed','manual_review') NOT NULL DEFAULT 'pending',
  `parse_started_at` timestamp NULL DEFAULT NULL,
  `parse_completed_at` timestamp NULL DEFAULT NULL,
  `parser_version` varchar(255) DEFAULT NULL COMMENT 'Track parser revisions for accuracy metrics',
  `raw_extracted_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Everything the parser pulled, before normalisation into market_data_points' CHECK (json_valid(`raw_extracted_json`)),
  `data_points_count` smallint(5) unsigned NOT NULL DEFAULT 0 COMMENT 'Cached count of extracted market_data_points',
  `spot_check_status` enum('pending','running','passed','flagged','manual') NOT NULL DEFAULT 'pending',
  `spot_check_results` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'AI audit re-extraction results (see market_data_discrepancies for diffs)' CHECK (json_valid(`spot_check_results`)),
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `is_demo` tinyint(1) NOT NULL DEFAULT 0,
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `marketing_share_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `property_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `agency_id` bigint(20) unsigned NOT NULL,
  `channel` varchar(50) NOT NULL,
  `recipient_context` varchar(255) DEFAULT NULL,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `marketing_share_log_property_id_foreign` (`property_id`),
  KEY `marketing_share_log_user_id_foreign` (`user_id`),
  KEY `marketing_share_log_agency_id_foreign` (`agency_id`),
  CONSTRAINT `marketing_share_log_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`),
  CONSTRAINT `marketing_share_log_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`),
  CONSTRAINT `marketing_share_log_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `migrations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) NOT NULL,
  `batch` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `monthly_target_goals`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `monthly_target_goals` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `branch_id` bigint(20) unsigned DEFAULT NULL,
  `agency_id` bigint(20) unsigned NOT NULL,
  `period` varchar(7) NOT NULL,
  `listings_target` int(11) NOT NULL DEFAULT 0,
  `deals_target` int(11) NOT NULL DEFAULT 0,
  `value_target` decimal(14,2) NOT NULL DEFAULT 0.00,
  `branch_budget` decimal(12,2) NOT NULL DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `updated_by` bigint(20) unsigned DEFAULT NULL,
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `nexus_permissions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(255) NOT NULL,
  `label` varchar(255) NOT NULL,
  `section` varchar(255) NOT NULL,
  `type` varchar(20) NOT NULL DEFAULT 'access',
  `module` varchar(80) DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `nexus_permissions_key_unique` (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `notification_dispatch_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `notification_dispatch_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `notification_event_type_id` bigint(20) unsigned NOT NULL,
  `subject_type` varchar(255) DEFAULT NULL,
  `subject_id` bigint(20) unsigned DEFAULT NULL,
  `threshold_hit_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `dispatched_at` timestamp NULL DEFAULT NULL,
  `channel` varchar(16) NOT NULL,
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `notification_event_types` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(255) NOT NULL,
  `pillar` varchar(32) NOT NULL,
  `group_label` varchar(255) DEFAULT NULL,
  `label` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `default_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `threshold_unit` varchar(16) NOT NULL DEFAULT 'none',
  `default_threshold` int(10) unsigned DEFAULT NULL,
  `threshold_min` int(10) unsigned DEFAULT NULL,
  `threshold_max` int(10) unsigned DEFAULT NULL,
  `supports_in_app` tinyint(1) NOT NULL DEFAULT 1,
  `supports_email` tinyint(1) NOT NULL DEFAULT 1,
  `supports_push` tinyint(1) NOT NULL DEFAULT 1,
  `is_adapter` tinyint(1) NOT NULL DEFAULT 0,
  `adapter_column` varchar(255) DEFAULT NULL,
  `sort_order` int(10) unsigned NOT NULL DEFAULT 0,
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `notifications` (
  `id` char(36) NOT NULL,
  `type` varchar(255) NOT NULL,
  `notifiable_type` varchar(255) NOT NULL,
  `notifiable_id` bigint(20) unsigned NOT NULL,
  `data` text NOT NULL,
  `read_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `notifications_notifiable_type_notifiable_id_index` (`notifiable_type`,`notifiable_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `onboarding_checklists`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `onboarding_checklists` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `application_id` bigint(20) unsigned NOT NULL,
  `item_key` varchar(100) NOT NULL,
  `item_label` varchar(255) NOT NULL,
  `is_required` tinyint(1) NOT NULL DEFAULT 1,
  `is_completed` tinyint(1) NOT NULL DEFAULT 0,
  `completed_at` timestamp NULL DEFAULT NULL,
  `completed_by` bigint(20) unsigned DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `onboarding_checklists_application_id_item_key_unique` (`application_id`,`item_key`),
  KEY `onboarding_checklists_completed_by_foreign` (`completed_by`),
  CONSTRAINT `onboarding_checklists_application_id_foreign` FOREIGN KEY (`application_id`) REFERENCES `agent_applications` (`id`) ON DELETE CASCADE,
  CONSTRAINT `onboarding_checklists_completed_by_foreign` FOREIGN KEY (`completed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `oversight_nudges`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `oversight_nudges` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint(20) unsigned NOT NULL,
  `from_user_id` bigint(20) unsigned NOT NULL,
  `to_user_id` bigint(20) unsigned NOT NULL,
  `subject_type` varchar(255) DEFAULT NULL,
  `subject_id` bigint(20) unsigned DEFAULT NULL,
  `category` varchar(64) NOT NULL,
  `message` text DEFAULT NULL,
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `p24_cities` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `p24_id` bigint(20) unsigned NOT NULL,
  `p24_province_id` bigint(20) unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `p24_cities_p24_id_unique` (`p24_id`),
  KEY `p24_cities_p24_province_id_name_index` (`p24_province_id`,`name`),
  CONSTRAINT `p24_cities_p24_province_id_foreign` FOREIGN KEY (`p24_province_id`) REFERENCES `p24_provinces` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `p24_countries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `p24_countries` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `p24_id` bigint(20) unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `p24_countries_p24_id_unique` (`p24_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `p24_import_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `p24_import_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint(20) unsigned NOT NULL,
  `email_uid` varchar(255) NOT NULL,
  `email_subject` varchar(255) NOT NULL,
  `email_date` datetime NOT NULL,
  `listings_found` int(10) unsigned NOT NULL DEFAULT 0,
  `listings_new` int(10) unsigned NOT NULL DEFAULT 0,
  `listings_updated` int(10) unsigned NOT NULL DEFAULT 0,
  `status` enum('success','error','skipped') NOT NULL DEFAULT 'success',
  `error_message` text DEFAULT NULL,
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `p24_import_rows` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `run_id` bigint(20) unsigned NOT NULL,
  `row_type` enum('agent','listing','image') NOT NULL,
  `external_id` varchar(255) DEFAULT NULL,
  `payload_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`payload_json`)),
  `mapped_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`mapped_json`)),
  `action` enum('create','update','skip') NOT NULL DEFAULT 'create',
  `status` enum('pending','confirmed','excluded','error') NOT NULL DEFAULT 'pending',
  `resolved_agent_id` bigint(20) unsigned DEFAULT NULL,
  `target_id` bigint(20) unsigned DEFAULT NULL,
  `errors_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`errors_json`)),
  `image_urls_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`image_urls_json`)),
  `confirmed_at` timestamp NULL DEFAULT NULL,
  `excluded_at` timestamp NULL DEFAULT NULL,
  `confirmed_by` bigint(20) unsigned DEFAULT NULL,
  `confirmed_via` varchar(255) DEFAULT NULL,
  `confirmed_by_portal_id` bigint(20) unsigned DEFAULT NULL,
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `p24_import_runs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `agency_id` bigint(20) unsigned DEFAULT NULL,
  `kind` enum('agents','listings_images') NOT NULL,
  `mark_compliant_on_confirm` tinyint(1) NOT NULL DEFAULT 0,
  `status` enum('parsing','pending_confirm','importing','completed','failed','cancelled') NOT NULL DEFAULT 'parsing',
  `agents_csv_path` varchar(255) DEFAULT NULL,
  `listings_csv_path` varchar(255) DEFAULT NULL,
  `images_csv_path` varchar(255) DEFAULT NULL,
  `counts_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`counts_json`)),
  `confirmed_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `error_message` text DEFAULT NULL,
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `p24_listings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint(20) unsigned NOT NULL,
  `p24_listing_number` varchar(255) NOT NULL,
  `asking_price` decimal(15,2) NOT NULL,
  `property_type` varchar(255) DEFAULT NULL,
  `suburb` varchar(255) DEFAULT NULL,
  `area` varchar(255) DEFAULT NULL,
  `bedrooms` tinyint(3) unsigned DEFAULT NULL,
  `bathrooms` tinyint(3) unsigned DEFAULT NULL,
  `garages` tinyint(3) unsigned DEFAULT NULL,
  `is_mandated` tinyint(1) NOT NULL DEFAULT 0,
  `listing_status` varchar(255) NOT NULL DEFAULT 'active',
  `p24_url` varchar(255) DEFAULT NULL,
  `first_seen_date` date NOT NULL,
  `last_seen_date` date NOT NULL,
  `original_price` decimal(15,2) DEFAULT NULL,
  `times_seen` int(10) unsigned NOT NULL DEFAULT 1,
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
  CONSTRAINT `p24_listings_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `p24_onboarding_portals`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `p24_onboarding_portals` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint(20) unsigned NOT NULL,
  `token` varchar(64) NOT NULL,
  `slug` varchar(160) DEFAULT NULL,
  `label` varchar(255) DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `revoked_at` timestamp NULL DEFAULT NULL,
  `revoked_reason` varchar(255) DEFAULT NULL,
  `last_opened_at` timestamp NULL DEFAULT NULL,
  `open_count` int(10) unsigned NOT NULL DEFAULT 0,
  `completed_at` timestamp NULL DEFAULT NULL,
  `run_ids_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`run_ids_json`)),
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `p24_portal_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `portal_id` bigint(20) unsigned DEFAULT NULL,
  `agency_id` bigint(20) unsigned NOT NULL,
  `actor_type` varchar(255) NOT NULL DEFAULT 'portal_visitor',
  `actor_label` varchar(255) DEFAULT NULL,
  `event` varchar(255) NOT NULL,
  `target_row_id` bigint(20) unsigned DEFAULT NULL,
  `target_external_id` varchar(255) DEFAULT NULL,
  `meta_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`meta_json`)),
  `ip` varchar(64) DEFAULT NULL,
  `user_agent` varchar(512) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `p24_price_changes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `listing_id` bigint(20) unsigned NOT NULL,
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `p24_provinces` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `p24_id` bigint(20) unsigned NOT NULL,
  `p24_country_id` bigint(20) unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `p24_provinces_p24_id_unique` (`p24_id`),
  KEY `p24_provinces_p24_country_id_name_index` (`p24_country_id`,`name`),
  CONSTRAINT `p24_provinces_p24_country_id_foreign` FOREIGN KEY (`p24_country_id`) REFERENCES `p24_countries` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `p24_suburbs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `p24_suburbs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `slug` varchar(255) NOT NULL,
  `p24_id` int(10) unsigned DEFAULT NULL,
  `p24_city_id` bigint(20) unsigned DEFAULT NULL,
  `region` varchar(255) NOT NULL DEFAULT 'kzn-south-coast',
  `surrounding_ids` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`surrounding_ids`)),
  `confirmed` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `p24_suburbs_p24_city_id_foreign` (`p24_city_id`),
  KEY `p24_suburbs_slug_index` (`slug`),
  CONSTRAINT `p24_suburbs_p24_city_id_foreign` FOREIGN KEY (`p24_city_id`) REFERENCES `p24_cities` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `p24_syndication_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `p24_syndication_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `property_id` bigint(20) unsigned NOT NULL,
  `action` varchar(50) NOT NULL,
  `request_payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`request_payload`)),
  `response_payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`response_payload`)),
  `status_code` smallint(6) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `p24_logs_property_created_idx` (`property_id`,`created_at`),
  CONSTRAINT `p24_syndication_logs_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `password_reset_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `password_reset_tokens` (
  `email` varchar(255) NOT NULL,
  `token` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `payroll_deduction_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `payroll_deduction_types` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint(20) unsigned NOT NULL,
  `code` varchar(30) NOT NULL,
  `label` varchar(100) NOT NULL,
  `sars_source_code` varchar(4) DEFAULT NULL,
  `is_statutory` tinyint(1) NOT NULL DEFAULT 0,
  `is_system` tinyint(1) NOT NULL DEFAULT 0,
  `sort_order` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `payroll_deduction_types_agency_id_code_unique` (`agency_id`,`code`),
  CONSTRAINT `payroll_deduction_types_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `payroll_earning_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `payroll_earning_types` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint(20) unsigned NOT NULL,
  `code` varchar(30) NOT NULL,
  `label` varchar(100) NOT NULL,
  `sars_source_code` varchar(4) DEFAULT NULL,
  `is_taxable` tinyint(1) NOT NULL DEFAULT 1,
  `is_fringe_benefit` tinyint(1) NOT NULL DEFAULT 0,
  `affects_uif_remuneration` tinyint(1) NOT NULL DEFAULT 1,
  `affects_sdl_remuneration` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `is_system` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `payroll_earning_types_agency_id_code_unique` (`agency_id`,`code`),
  CONSTRAINT `payroll_earning_types_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `payroll_employee_deductions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `payroll_employee_deductions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint(20) unsigned NOT NULL,
  `payroll_employee_id` bigint(20) unsigned NOT NULL,
  `deduction_type_id` bigint(20) unsigned NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `effective_from` date NOT NULL,
  `effective_to` date DEFAULT NULL,
  `override_statutory` tinyint(1) NOT NULL DEFAULT 0,
  `notes` varchar(255) DEFAULT NULL,
  `created_by` bigint(20) unsigned NOT NULL,
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
  CONSTRAINT `payroll_employee_deductions_deduction_type_id_foreign` FOREIGN KEY (`deduction_type_id`) REFERENCES `payroll_deduction_types` (`id`),
  CONSTRAINT `payroll_employee_deductions_payroll_employee_id_foreign` FOREIGN KEY (`payroll_employee_id`) REFERENCES `payroll_employees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `payroll_employee_earnings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `payroll_employee_earnings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint(20) unsigned NOT NULL,
  `payroll_employee_id` bigint(20) unsigned NOT NULL,
  `earning_type_id` bigint(20) unsigned NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `effective_from` date NOT NULL,
  `effective_to` date DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `created_by` bigint(20) unsigned NOT NULL,
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
  CONSTRAINT `payroll_employee_earnings_earning_type_id_foreign` FOREIGN KEY (`earning_type_id`) REFERENCES `payroll_earning_types` (`id`),
  CONSTRAINT `payroll_employee_earnings_payroll_employee_id_foreign` FOREIGN KEY (`payroll_employee_id`) REFERENCES `payroll_employees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `payroll_employees`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `payroll_employees` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint(20) unsigned NOT NULL,
  `branch_id` bigint(20) unsigned DEFAULT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `employment_date` date NOT NULL,
  `termination_date` date DEFAULT NULL,
  `designation_snapshot` varchar(150) NOT NULL,
  `pay_frequency` enum('monthly') NOT NULL DEFAULT 'monthly',
  `pay_day_of_month` tinyint(3) unsigned NOT NULL DEFAULT 25,
  `working_days_per_week` tinyint(3) unsigned NOT NULL DEFAULT 5,
  `working_pattern` enum('monday_to_friday','monday_to_saturday','custom') NOT NULL DEFAULT 'monday_to_friday',
  `working_days_mask` tinyint(3) unsigned NOT NULL DEFAULT 31 COMMENT 'Bitmap: bit 0=Mon, bit 1=Tue ... bit 6=Sun. Default 31 = Mon-Fri',
  `daily_rate_basis` enum('fixed_21_67','calendar_working_days','hours_per_day') NOT NULL DEFAULT 'fixed_21_67',
  `hours_per_day` decimal(4,2) NOT NULL DEFAULT 8.00,
  `take_on_completed_at` timestamp NULL DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `notes` text DEFAULT NULL,
  `created_by` bigint(20) unsigned NOT NULL,
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `payroll_payslip_lines` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `payroll_payslip_id` bigint(20) unsigned NOT NULL,
  `line_type` enum('earning','deduction','employer_contribution') NOT NULL,
  `source_type_id` bigint(20) unsigned NOT NULL,
  `code_snapshot` varchar(30) NOT NULL,
  `label_snapshot` varchar(100) NOT NULL,
  `sars_source_code_snapshot` varchar(4) DEFAULT NULL,
  `amount` decimal(15,2) NOT NULL,
  `is_taxable_snapshot` tinyint(1) NOT NULL,
  `sort_order` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `payroll_payslip_lines_payroll_payslip_id_line_type_index` (`payroll_payslip_id`,`line_type`),
  CONSTRAINT `payroll_payslip_lines_payroll_payslip_id_foreign` FOREIGN KEY (`payroll_payslip_id`) REFERENCES `payroll_payslips` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `payroll_payslips`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `payroll_payslips` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint(20) unsigned NOT NULL,
  `branch_id` bigint(20) unsigned DEFAULT NULL,
  `payroll_run_id` bigint(20) unsigned NOT NULL,
  `payroll_employee_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `payslip_number` varchar(40) NOT NULL,
  `employee_name_snapshot` varchar(255) NOT NULL,
  `id_number_snapshot` varchar(255) DEFAULT NULL,
  `tax_reference_snapshot` varchar(255) DEFAULT NULL,
  `employment_date_snapshot` date NOT NULL,
  `designation_snapshot` varchar(255) NOT NULL,
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
  `document_id` bigint(20) unsigned DEFAULT NULL,
  `pdf_generated_at` timestamp NULL DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `payroll_payslips_payroll_run_id_payroll_employee_id_unique` (`payroll_run_id`,`payroll_employee_id`),
  UNIQUE KEY `payroll_payslips_payslip_number_unique` (`payslip_number`),
  KEY `payroll_payslips_agency_id_foreign` (`agency_id`),
  KEY `payroll_payslips_branch_id_foreign` (`branch_id`),
  KEY `payroll_payslips_payroll_employee_id_foreign` (`payroll_employee_id`),
  KEY `payroll_payslips_document_id_foreign` (`document_id`),
  KEY `payroll_payslips_user_id_period_month_index` (`user_id`,`period_month`),
  CONSTRAINT `payroll_payslips_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`),
  CONSTRAINT `payroll_payslips_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  CONSTRAINT `payroll_payslips_document_id_foreign` FOREIGN KEY (`document_id`) REFERENCES `documents` (`id`),
  CONSTRAINT `payroll_payslips_payroll_employee_id_foreign` FOREIGN KEY (`payroll_employee_id`) REFERENCES `payroll_employees` (`id`),
  CONSTRAINT `payroll_payslips_payroll_run_id_foreign` FOREIGN KEY (`payroll_run_id`) REFERENCES `payroll_runs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `payroll_payslips_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `payroll_runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `payroll_runs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint(20) unsigned NOT NULL,
  `run_number` varchar(30) NOT NULL,
  `period_month` date NOT NULL,
  `pay_date` date NOT NULL,
  `status` enum('draft','finalised','cancelled') NOT NULL DEFAULT 'draft',
  `finalised_at` timestamp NULL DEFAULT NULL,
  `finalised_by` bigint(20) unsigned DEFAULT NULL,
  `cancelled_at` timestamp NULL DEFAULT NULL,
  `cancelled_by` bigint(20) unsigned DEFAULT NULL,
  `cancellation_reason` text DEFAULT NULL,
  `payslip_count` int(11) NOT NULL DEFAULT 0,
  `total_gross` decimal(15,2) NOT NULL DEFAULT 0.00,
  `total_paye` decimal(15,2) NOT NULL DEFAULT 0.00,
  `total_uif_employee` decimal(15,2) NOT NULL DEFAULT 0.00,
  `total_uif_employer` decimal(15,2) NOT NULL DEFAULT 0.00,
  `total_sdl` decimal(15,2) NOT NULL DEFAULT 0.00,
  `total_net` decimal(15,2) NOT NULL DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  `created_by` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `payroll_runs_agency_id_period_month_unique` (`agency_id`,`period_month`),
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `payroll_tax_rebates` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `payroll_tax_tables` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tax_year_start` date NOT NULL,
  `tax_year_end` date NOT NULL,
  `bracket_order` tinyint(3) unsigned NOT NULL,
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `pdf_splitter_feedback` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `base_name` varchar(160) NOT NULL,
  `page_number` smallint(5) unsigned NOT NULL,
  `auto_label` varchar(40) NOT NULL,
  `final_label` varchar(40) NOT NULL,
  `snippet` varchar(200) NOT NULL DEFAULT '',
  `scores` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`scores`)),
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `pdf_splitter_learned_phrases` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `bucket` varchar(40) NOT NULL,
  `phrase` varchar(120) NOT NULL,
  `weight` smallint(5) unsigned NOT NULL DEFAULT 1,
  `hits` int(10) unsigned NOT NULL DEFAULT 0,
  `enabled` tinyint(1) NOT NULL DEFAULT 0,
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
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `performance_settings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(255) NOT NULL,
  `value` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `performance_settings_key_unique` (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `personal_access_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `personal_access_tokens` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tokenable_type` varchar(255) NOT NULL,
  `tokenable_id` bigint(20) unsigned NOT NULL,
  `name` text NOT NULL,
  `token` varchar(64) NOT NULL,
  `abilities` text DEFAULT NULL,
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
DROP TABLE IF EXISTS `portal_captures`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `portal_captures` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `presentation_id` bigint(20) unsigned DEFAULT NULL,
  `source_site` varchar(100) NOT NULL,
  `page_type` varchar(20) NOT NULL,
  `source_url` text NOT NULL,
  `final_url` text NOT NULL,
  `page_title` varchar(500) DEFAULT NULL,
  `captured_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `extractor_version` varchar(50) NOT NULL,
  `dom_hash_sha256` char(64) NOT NULL,
  `html_bytes` int(10) unsigned NOT NULL,
  `raw_html_path` varchar(255) NOT NULL,
  `screenshot_path` varchar(255) DEFAULT NULL,
  `parse_status` varchar(30) NOT NULL,
  `extracted_fields_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`extracted_fields_json`)),
  `jsonld_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`jsonld_json`)),
  `found_image_urls_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`found_image_urls_json`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `portal_captures_presentation_id_source_site_index` (`presentation_id`,`source_site`),
  KEY `portal_captures_user_id_index` (`user_id`),
  CONSTRAINT `portal_captures_presentation_id_foreign` FOREIGN KEY (`presentation_id`) REFERENCES `presentations` (`id`) ON DELETE SET NULL,
  CONSTRAINT `portal_captures_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `portal_leads`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `portal_leads` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint(20) unsigned NOT NULL,
  `portal` enum('p24','pp') NOT NULL,
  `lead_type` varchar(32) NOT NULL,
  `listing_id` bigint(20) unsigned DEFAULT NULL,
  `listing_portal_ref` varchar(64) DEFAULT NULL,
  `contact_id` bigint(20) unsigned DEFAULT NULL,
  `contact_exists` tinyint(1) NOT NULL DEFAULT 0,
  `existing_contact_agent_id` bigint(20) unsigned DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) DEFAULT NULL,
  `phone` varchar(64) DEFAULT NULL,
  `message` text DEFAULT NULL,
  `is_whatsapp` tinyint(1) NOT NULL DEFAULT 0,
  `lead_source_raw` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`lead_source_raw`)),
  `received_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `notified_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `portal_leads_listing_id_foreign` (`listing_id`),
  KEY `portal_leads_contact_id_foreign` (`contact_id`),
  KEY `portal_leads_existing_contact_agent_id_foreign` (`existing_contact_agent_id`),
  KEY `portal_leads_agency_id_received_at_index` (`agency_id`,`received_at`),
  KEY `pl_portal_ref_recv_idx` (`portal`,`listing_portal_ref`,`received_at`),
  KEY `portal_leads_agency_id_notified_at_index` (`agency_id`,`notified_at`),
  KEY `portal_leads_received_at_index` (`received_at`),
  CONSTRAINT `portal_leads_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `portal_leads_contact_id_foreign` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `portal_leads_existing_contact_agent_id_foreign` FOREIGN KEY (`existing_contact_agent_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `portal_leads_listing_id_foreign` FOREIGN KEY (`listing_id`) REFERENCES `properties` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `portal_listing_observations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `portal_listing_observations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `portal_listing_id` bigint(20) unsigned NOT NULL,
  `capture_id` bigint(20) unsigned NOT NULL,
  `observed_at` timestamp NULL DEFAULT NULL,
  `observed_fields_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`observed_fields_json`)),
  `changed_fields_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`changed_fields_json`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `portal_obs_listing_observed_idx` (`portal_listing_id`,`observed_at`),
  KEY `portal_listing_observations_capture_id_index` (`capture_id`),
  CONSTRAINT `portal_listing_observations_capture_id_foreign` FOREIGN KEY (`capture_id`) REFERENCES `portal_captures` (`id`) ON DELETE CASCADE,
  CONSTRAINT `portal_listing_observations_portal_listing_id_foreign` FOREIGN KEY (`portal_listing_id`) REFERENCES `portal_listings` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `portal_listings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `portal_listings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `source_site` varchar(100) NOT NULL,
  `portal_listing_id` varchar(50) NOT NULL,
  `canonical_url` text DEFAULT NULL,
  `first_seen_at` timestamp NULL DEFAULT NULL,
  `last_seen_at` timestamp NULL DEFAULT NULL,
  `last_capture_id` bigint(20) unsigned DEFAULT NULL,
  `current_fields_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`current_fields_json`)),
  `primary_image_url` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `tracked_property_id` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `portal_listings_site_id_unique` (`source_site`,`portal_listing_id`),
  KEY `portal_listings_last_capture_id_index` (`last_capture_id`),
  KEY `idx_portal_listings_tracked` (`tracked_property_id`),
  CONSTRAINT `portal_listings_last_capture_id_foreign` FOREIGN KEY (`last_capture_id`) REFERENCES `portal_captures` (`id`) ON DELETE SET NULL,
  CONSTRAINT `portal_listings_tracked_property_id_foreign` FOREIGN KEY (`tracked_property_id`) REFERENCES `tracked_properties` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pp_cities`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `pp_cities` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `pp_city_id` bigint(20) unsigned NOT NULL,
  `pp_province_id` bigint(20) unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `pp_cities_pp_city_id_unique` (`pp_city_id`),
  KEY `pp_cities_pp_province_id_name_index` (`pp_province_id`,`name`),
  CONSTRAINT `pp_cities_pp_province_id_foreign` FOREIGN KEY (`pp_province_id`) REFERENCES `pp_provinces` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pp_event_feed_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `pp_event_feed_settings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(255) NOT NULL,
  `value` text DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `pp_event_feed_settings_key_unique` (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pp_provinces`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `pp_provinces` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `pp_province_id` bigint(20) unsigned NOT NULL,
  `pp_province_enum` varchar(255) DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `pp_provinces_pp_province_id_unique` (`pp_province_id`),
  KEY `pp_provinces_name_index` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pp_suburbs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `pp_suburbs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `pp_suburb_id` bigint(20) unsigned NOT NULL,
  `pp_city_id` bigint(20) unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  `normalised_name` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `pp_suburbs_pp_suburb_id_unique` (`pp_suburb_id`),
  KEY `pp_suburbs_pp_city_id_name_index` (`pp_city_id`,`name`),
  KEY `pp_suburbs_normalised_name_index` (`normalised_name`),
  CONSTRAINT `pp_suburbs_pp_city_id_foreign` FOREIGN KEY (`pp_city_id`) REFERENCES `pp_cities` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `presentation_active_listings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `presentation_active_listings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `mic_comp_row_id` bigint(20) unsigned DEFAULT NULL,
  `presentation_id` bigint(20) unsigned NOT NULL,
  `agency_id` bigint(20) unsigned NOT NULL,
  `source_upload_id` bigint(20) unsigned DEFAULT NULL,
  `source_snapshot_id` bigint(20) unsigned DEFAULT NULL,
  `listing_date` date DEFAULT NULL,
  `list_price_inc` bigint(20) unsigned DEFAULT NULL,
  `suburb` varchar(255) DEFAULT NULL,
  `property_type` varchar(255) DEFAULT NULL,
  `beds` smallint(5) unsigned DEFAULT NULL,
  `baths` smallint(5) unsigned DEFAULT NULL,
  `size_m2` smallint(5) unsigned DEFAULT NULL,
  `status` varchar(255) DEFAULT NULL,
  `raw_row_json` text NOT NULL,
  `parser_version` varchar(50) NOT NULL,
  `extraction_method` varchar(30) DEFAULT NULL,
  `external_key` varchar(255) DEFAULT NULL,
  `fingerprint` char(64) DEFAULT NULL,
  `first_seen_at` timestamp NULL DEFAULT NULL,
  `last_seen_at` timestamp NULL DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `source_rank` tinyint(3) unsigned NOT NULL DEFAULT 50,
  `merge_confidence` tinyint(3) unsigned DEFAULT NULL,
  `data_quality_score` tinyint(3) unsigned DEFAULT NULL,
  `conflict_flags_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`conflict_flags_json`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  `is_demo` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `presentation_active_listings_external_key_index` (`external_key`),
  KEY `presentation_active_listings_fingerprint_index` (`fingerprint`),
  KEY `presentation_active_listings_presentation_id_external_key_index` (`presentation_id`,`external_key`),
  KEY `presentation_active_listings_presentation_id_is_active_index` (`presentation_id`,`is_active`),
  KEY `presentation_active_listings_agency_id_idx` (`agency_id`),
  KEY `idx_presentation_active_listings_is_demo` (`is_demo`),
  KEY `presentation_active_listings_mic_comp_row_id_foreign` (`mic_comp_row_id`),
  CONSTRAINT `presentation_active_listings_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `presentation_active_listings_mic_comp_row_id_foreign` FOREIGN KEY (`mic_comp_row_id`) REFERENCES `market_report_comp_rows` (`id`) ON DELETE SET NULL,
  CONSTRAINT `presentation_active_listings_presentation_id_foreign` FOREIGN KEY (`presentation_id`) REFERENCES `presentations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `presentation_ai_summary_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `presentation_ai_summary_history` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `presentation_id` bigint(20) unsigned NOT NULL,
  `presentation_version_id` bigint(20) unsigned DEFAULT NULL,
  `ai_variant_id` smallint(5) unsigned NOT NULL,
  `generated_text` text DEFAULT NULL,
  `generated_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `generated_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `was_saved` tinyint(1) NOT NULL DEFAULT 0,
  `tokens_used` int(10) unsigned DEFAULT NULL,
  `latency_ms` int(10) unsigned DEFAULT NULL,
  `failure_reason` text DEFAULT NULL,
  `prompt_hash` varchar(64) DEFAULT NULL,
  `model` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `presentation_ai_summary_history_presentation_version_id_foreign` (`presentation_version_id`),
  KEY `presentation_ai_summary_history_ai_variant_id_foreign` (`ai_variant_id`),
  KEY `presentation_ai_summary_history_generated_by_user_id_foreign` (`generated_by_user_id`),
  KEY `pash_pres_gen_idx` (`presentation_id`,`generated_at`),
  KEY `pash_phash_idx` (`prompt_hash`),
  CONSTRAINT `presentation_ai_summary_history_ai_variant_id_foreign` FOREIGN KEY (`ai_variant_id`) REFERENCES `presentation_ai_variants` (`id`),
  CONSTRAINT `presentation_ai_summary_history_generated_by_user_id_foreign` FOREIGN KEY (`generated_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `presentation_ai_summary_history_presentation_id_foreign` FOREIGN KEY (`presentation_id`) REFERENCES `presentations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `presentation_ai_summary_history_presentation_version_id_foreign` FOREIGN KEY (`presentation_version_id`) REFERENCES `presentation_versions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `presentation_ai_variants`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `presentation_ai_variants` (
  `id` smallint(5) unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(50) NOT NULL,
  `display_name` varchar(100) NOT NULL,
  `description` varchar(300) NOT NULL,
  `prompt_template` text NOT NULL,
  `max_tokens` smallint(5) unsigned NOT NULL DEFAULT 800,
  `temperature` decimal(3,2) NOT NULL DEFAULT 0.50,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `presentation_ai_variants_key_unique` (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `presentation_articles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `presentation_articles` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `presentation_id` bigint(20) unsigned NOT NULL,
  `agency_id` bigint(20) unsigned NOT NULL,
  `url` text NOT NULL,
  `snapshot_text` longtext DEFAULT NULL,
  `content_hash` char(64) DEFAULT NULL,
  `fetched_at` timestamp NULL DEFAULT NULL,
  `ai_summary_text` longtext DEFAULT NULL,
  `ai_summary_model` varchar(100) DEFAULT NULL,
  `ai_summary_created_at` timestamp NULL DEFAULT NULL,
  `tags_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`tags_json`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `presentation_articles_presentation_id_index` (`presentation_id`),
  KEY `presentation_articles_agency_id_idx` (`agency_id`),
  CONSTRAINT `presentation_articles_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `presentation_articles_presentation_id_foreign` FOREIGN KEY (`presentation_id`) REFERENCES `presentations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `presentation_deliveries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `presentation_deliveries` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `snapshot_link_id` bigint(20) unsigned NOT NULL,
  `presentation_id` bigint(20) unsigned NOT NULL,
  `agency_id` bigint(20) unsigned NOT NULL,
  `sent_by_user_id` bigint(20) unsigned NOT NULL,
  `channel` enum('email','whatsapp','copy','sms') NOT NULL,
  `recipient_contact_id` bigint(20) unsigned DEFAULT NULL,
  `recipient_name` varchar(200) NOT NULL,
  `recipient_email` varchar(200) DEFAULT NULL,
  `recipient_phone` varchar(30) DEFAULT NULL,
  `mode` enum('full','teaser') NOT NULL,
  `status` enum('queued','sent','failed','bounced','delivered','opened') NOT NULL DEFAULT 'queued',
  `error_message` text DEFAULT NULL,
  `sent_at` timestamp NULL DEFAULT NULL,
  `delivered_at` timestamp NULL DEFAULT NULL,
  `opened_at` timestamp NULL DEFAULT NULL,
  `whatsapp_url` varchar(500) DEFAULT NULL,
  `whatsapp_click_through_at` timestamp NULL DEFAULT NULL,
  `subject_line` varchar(200) DEFAULT NULL,
  `message_body` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `presentation_deliveries_snapshot_link_id_foreign` (`snapshot_link_id`),
  KEY `presentation_deliveries_agency_id_foreign` (`agency_id`),
  KEY `presentation_deliveries_presentation_id_sent_at_index` (`presentation_id`,`sent_at`),
  KEY `presentation_deliveries_recipient_contact_id_index` (`recipient_contact_id`),
  KEY `presentation_deliveries_channel_status_index` (`channel`,`status`),
  KEY `presentation_deliveries_sent_by_user_id_index` (`sent_by_user_id`),
  KEY `pd_idempotency_idx` (`sent_by_user_id`,`presentation_id`,`recipient_email`),
  CONSTRAINT `presentation_deliveries_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`),
  CONSTRAINT `presentation_deliveries_presentation_id_foreign` FOREIGN KEY (`presentation_id`) REFERENCES `presentations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `presentation_deliveries_recipient_contact_id_foreign` FOREIGN KEY (`recipient_contact_id`) REFERENCES `contacts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `presentation_deliveries_sent_by_user_id_foreign` FOREIGN KEY (`sent_by_user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `presentation_deliveries_snapshot_link_id_foreign` FOREIGN KEY (`snapshot_link_id`) REFERENCES `presentation_snapshot_links` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `presentation_document_library_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `presentation_document_library_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `presentation_id` bigint(20) unsigned NOT NULL,
  `agency_id` bigint(20) unsigned NOT NULL,
  `document_library_item_id` bigint(20) unsigned NOT NULL,
  `attached_by_user_id` bigint(20) unsigned NOT NULL,
  `note` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `pdli_pres_doc_unique` (`presentation_id`,`document_library_item_id`),
  KEY `pdli_doc_lib_item_fk` (`document_library_item_id`),
  KEY `pdli_attached_by_fk` (`attached_by_user_id`),
  KEY `presentation_document_library_items_agency_id_idx` (`agency_id`),
  CONSTRAINT `pdli_attached_by_fk` FOREIGN KEY (`attached_by_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `pdli_doc_lib_item_fk` FOREIGN KEY (`document_library_item_id`) REFERENCES `document_library_items` (`id`) ON DELETE CASCADE,
  CONSTRAINT `presentation_document_library_items_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `presentation_document_library_items_presentation_id_foreign` FOREIGN KEY (`presentation_id`) REFERENCES `presentations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `presentation_fields`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `presentation_fields` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `presentation_id` bigint(20) unsigned NOT NULL,
  `agency_id` bigint(20) unsigned NOT NULL,
  `field_key` varchar(255) NOT NULL,
  `extracted_value` text DEFAULT NULL,
  `override_value` text DEFAULT NULL,
  `final_value` text DEFAULT NULL,
  `source_upload_id` bigint(20) unsigned DEFAULT NULL,
  `confidence` decimal(5,2) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `presentation_fields_presentation_id_foreign` (`presentation_id`),
  KEY `presentation_fields_source_upload_id_foreign` (`source_upload_id`),
  KEY `presentation_fields_agency_id_idx` (`agency_id`),
  CONSTRAINT `presentation_fields_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `presentation_fields_presentation_id_foreign` FOREIGN KEY (`presentation_id`) REFERENCES `presentations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `presentation_fields_source_upload_id_foreign` FOREIGN KEY (`source_upload_id`) REFERENCES `presentation_uploads` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `presentation_links`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `presentation_links` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `presentation_id` bigint(20) unsigned NOT NULL,
  `agency_id` bigint(20) unsigned NOT NULL,
  `type` varchar(30) NOT NULL DEFAULT 'other',
  `url` varchar(255) NOT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `asking_price_inc` bigint(20) unsigned DEFAULT NULL,
  `beds` smallint(5) unsigned DEFAULT NULL,
  `baths` smallint(5) unsigned DEFAULT NULL,
  `floor_area_m2` smallint(5) unsigned DEFAULT NULL,
  `erf_m2` smallint(5) unsigned DEFAULT NULL,
  `property_type` varchar(30) DEFAULT NULL,
  `suburb` varchar(100) DEFAULT NULL,
  `extraction_status` enum('pending','ok','failed') NOT NULL DEFAULT 'pending',
  `extracted_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`extracted_json`)),
  `extraction_error` text DEFAULT NULL,
  `extracted_at` timestamp NULL DEFAULT NULL,
  `override_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`override_json`)),
  `override_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `override_at` timestamp NULL DEFAULT NULL,
  `portal_capture_id` bigint(20) unsigned DEFAULT NULL,
  `created_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `presentation_links_presentation_id_foreign` (`presentation_id`),
  KEY `presentation_links_override_by_user_id_foreign` (`override_by_user_id`),
  KEY `presentation_links_portal_capture_id_index` (`portal_capture_id`),
  KEY `presentation_links_agency_id_idx` (`agency_id`),
  CONSTRAINT `presentation_links_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `presentation_links_override_by_user_id_foreign` FOREIGN KEY (`override_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `presentation_links_presentation_id_foreign` FOREIGN KEY (`presentation_id`) REFERENCES `presentations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `presentation_listing_price_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `presentation_listing_price_history` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `presentation_id` bigint(20) unsigned NOT NULL,
  `agency_id` bigint(20) unsigned NOT NULL,
  `active_listing_id` bigint(20) unsigned NOT NULL,
  `price_inc` bigint(20) unsigned NOT NULL,
  `captured_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `source_snapshot_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `presentation_listing_price_history_presentation_id_foreign` (`presentation_id`),
  KEY `plph_active_listing_captured_at_idx` (`active_listing_id`,`captured_at`),
  KEY `presentation_listing_price_history_agency_id_idx` (`agency_id`),
  CONSTRAINT `presentation_listing_price_history_active_listing_id_foreign` FOREIGN KEY (`active_listing_id`) REFERENCES `presentation_active_listings` (`id`) ON DELETE CASCADE,
  CONSTRAINT `presentation_listing_price_history_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `presentation_listing_price_history_presentation_id_foreign` FOREIGN KEY (`presentation_id`) REFERENCES `presentations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `presentation_outcome_prompts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `presentation_outcome_prompts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `presentation_id` bigint(20) unsigned NOT NULL,
  `agency_id` bigint(20) unsigned NOT NULL,
  `prompted_user_id` bigint(20) unsigned NOT NULL,
  `prompted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `channel` varchar(30) NOT NULL DEFAULT 'mail',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `pop_user_fk` (`prompted_user_id`),
  KEY `pop_pres_at_idx` (`presentation_id`,`prompted_at`),
  KEY `pop_agency_at_idx` (`agency_id`,`prompted_at`),
  CONSTRAINT `pop_agency_fk` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `pop_pres_fk` FOREIGN KEY (`presentation_id`) REFERENCES `presentations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `pop_user_fk` FOREIGN KEY (`prompted_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `presentation_outcomes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `presentation_outcomes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `presentation_id` bigint(20) unsigned NOT NULL,
  `agency_id` bigint(20) unsigned NOT NULL,
  `outcome` enum('won_mandate','won_sale','lost_to_competitor','lost_to_no_decision','lost_to_price_dispute','lost_to_no_response','still_pending','other') NOT NULL,
  `cancellation_reason` enum('price_too_high_seller','price_too_low_seller','commission_concerns','sole_mandate_concerns','family_pressure','existing_relationship','agency_reputation','agent_personality','timing_change','property_issues_discovered','price_match_with_other','other') DEFAULT NULL,
  `cancellation_competitor_agency` varchar(200) DEFAULT NULL,
  `cancellation_competitor_price` bigint(20) unsigned DEFAULT NULL,
  `decision_at` date DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `resulted_in_deal_id` bigint(20) unsigned DEFAULT NULL,
  `recorded_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `recorded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `locked` tinyint(1) NOT NULL DEFAULT 0,
  `locked_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `presentation_outcomes_presentation_id_unique` (`presentation_id`),
  KEY `po_agency_out_decision_idx` (`agency_id`,`outcome`,`decision_at`),
  KEY `po_deal_idx` (`resulted_in_deal_id`),
  KEY `presentation_outcomes_outcome_index` (`outcome`),
  KEY `po_recorder_fk` (`recorded_by_user_id`),
  CONSTRAINT `po_agency_fk` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `po_deal_fk` FOREIGN KEY (`resulted_in_deal_id`) REFERENCES `deals` (`id`) ON DELETE SET NULL,
  CONSTRAINT `po_pres_fk` FOREIGN KEY (`presentation_id`) REFERENCES `presentations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `po_recorder_fk` FOREIGN KEY (`recorded_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `presentation_refresh_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `presentation_refresh_requests` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint(20) unsigned NOT NULL,
  `presentation_id` bigint(20) unsigned NOT NULL,
  `snapshot_link_id` bigint(20) unsigned NOT NULL,
  `recipient_contact_id` bigint(20) unsigned DEFAULT NULL,
  `requester_name` varchar(120) NOT NULL,
  `requester_email` varchar(160) DEFAULT NULL,
  `requester_phone` varchar(40) DEFAULT NULL,
  `message` text DEFAULT NULL,
  `fingerprint_hash` varchar(64) DEFAULT NULL,
  `ip_masked` varchar(64) DEFAULT NULL,
  `user_agent` varchar(500) DEFAULT NULL,
  `status` enum('pending','acknowledged','resolved','declined','cancelled') NOT NULL DEFAULT 'pending',
  `acknowledged_at` timestamp NULL DEFAULT NULL,
  `acknowledged_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `resolved_at` timestamp NULL DEFAULT NULL,
  `resolved_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `resulting_link_id` bigint(20) unsigned DEFAULT NULL,
  `resolution_note` text DEFAULT NULL,
  `declined_at` timestamp NULL DEFAULT NULL,
  `declined_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `decline_reason` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `presentation_refresh_requests_recipient_contact_id_foreign` (`recipient_contact_id`),
  KEY `presentation_refresh_requests_acknowledged_by_user_id_foreign` (`acknowledged_by_user_id`),
  KEY `presentation_refresh_requests_resolved_by_user_id_foreign` (`resolved_by_user_id`),
  KEY `presentation_refresh_requests_resulting_link_id_foreign` (`resulting_link_id`),
  KEY `presentation_refresh_requests_declined_by_user_id_foreign` (`declined_by_user_id`),
  KEY `prr_link_created_idx` (`snapshot_link_id`,`created_at`),
  KEY `prr_pres_status_idx` (`presentation_id`,`status`),
  KEY `prr_agency_status_idx` (`agency_id`,`status`,`created_at`),
  CONSTRAINT `presentation_refresh_requests_acknowledged_by_user_id_foreign` FOREIGN KEY (`acknowledged_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `presentation_refresh_requests_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `presentation_refresh_requests_declined_by_user_id_foreign` FOREIGN KEY (`declined_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `presentation_refresh_requests_presentation_id_foreign` FOREIGN KEY (`presentation_id`) REFERENCES `presentations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `presentation_refresh_requests_recipient_contact_id_foreign` FOREIGN KEY (`recipient_contact_id`) REFERENCES `contacts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `presentation_refresh_requests_resolved_by_user_id_foreign` FOREIGN KEY (`resolved_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `presentation_refresh_requests_resulting_link_id_foreign` FOREIGN KEY (`resulting_link_id`) REFERENCES `presentation_snapshot_links` (`id`) ON DELETE SET NULL,
  CONSTRAINT `presentation_refresh_requests_snapshot_link_id_foreign` FOREIGN KEY (`snapshot_link_id`) REFERENCES `presentation_snapshot_links` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `presentation_sections`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `presentation_sections` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `presentation_id` bigint(20) unsigned NOT NULL,
  `agency_id` bigint(20) unsigned NOT NULL,
  `section_key` varchar(255) NOT NULL,
  `data_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`data_json`)),
  `sort_order` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `presentation_sections_presentation_id_foreign` (`presentation_id`),
  KEY `presentation_sections_agency_id_idx` (`agency_id`),
  CONSTRAINT `presentation_sections_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `presentation_sections_presentation_id_foreign` FOREIGN KEY (`presentation_id`) REFERENCES `presentations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `presentation_snapshot_links`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `presentation_snapshot_links` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `presentation_id` bigint(20) unsigned NOT NULL,
  `presentation_version_id` bigint(20) unsigned NOT NULL,
  `agency_id` bigint(20) unsigned NOT NULL,
  `token` varchar(64) NOT NULL,
  `mode` enum('full','teaser') NOT NULL DEFAULT 'full',
  `recipient_contact_id` bigint(20) unsigned DEFAULT NULL,
  `recipient_label` varchar(200) DEFAULT NULL,
  `created_by_user_id` bigint(20) unsigned NOT NULL,
  `expires_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `revoked_at` timestamp NULL DEFAULT NULL,
  `revoked_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `first_viewed_at` timestamp NULL DEFAULT NULL,
  `last_viewed_at` timestamp NULL DEFAULT NULL,
  `view_count` int(10) unsigned NOT NULL DEFAULT 0,
  `first_fingerprint` varchar(128) DEFAULT NULL,
  `flagged_at` timestamp NULL DEFAULT NULL,
  `flagged_reason` varchar(200) DEFAULT NULL,
  `last_flag_notified_at` timestamp NULL DEFAULT NULL,
  `refresh_requested_at` timestamp NULL DEFAULT NULL,
  `refresh_requested_by_name` varchar(200) DEFAULT NULL,
  `refresh_requested_message` text DEFAULT NULL,
  `refresh_request_count` int(10) unsigned NOT NULL DEFAULT 0,
  `refresh_acknowledged_at` timestamp NULL DEFAULT NULL,
  `refresh_acknowledged_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `refresh_resulted_in_link_id` bigint(20) unsigned DEFAULT NULL,
  `superseded_by_link_id` bigint(20) unsigned DEFAULT NULL,
  `superseded_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `presentation_snapshot_links_token_unique` (`token`),
  KEY `presentation_snapshot_links_agency_id_foreign` (`agency_id`),
  KEY `presentation_snapshot_links_created_by_user_id_foreign` (`created_by_user_id`),
  KEY `presentation_snapshot_links_revoked_by_user_id_foreign` (`revoked_by_user_id`),
  KEY `presentation_snapshot_links_presentation_id_index` (`presentation_id`),
  KEY `presentation_snapshot_links_presentation_version_id_index` (`presentation_version_id`),
  KEY `presentation_snapshot_links_recipient_contact_id_index` (`recipient_contact_id`),
  KEY `presentation_snapshot_links_expires_at_index` (`expires_at`),
  KEY `psl_refresh_ack_user_fk` (`refresh_acknowledged_by_user_id`),
  KEY `psl_refresh_result_link_fk` (`refresh_resulted_in_link_id`),
  KEY `psl_superseded_by_link_fk` (`superseded_by_link_id`),
  CONSTRAINT `presentation_snapshot_links_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`),
  CONSTRAINT `presentation_snapshot_links_created_by_user_id_foreign` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `presentation_snapshot_links_presentation_id_foreign` FOREIGN KEY (`presentation_id`) REFERENCES `presentations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `presentation_snapshot_links_presentation_version_id_foreign` FOREIGN KEY (`presentation_version_id`) REFERENCES `presentation_versions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `presentation_snapshot_links_recipient_contact_id_foreign` FOREIGN KEY (`recipient_contact_id`) REFERENCES `contacts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `presentation_snapshot_links_revoked_by_user_id_foreign` FOREIGN KEY (`revoked_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `psl_refresh_ack_user_fk` FOREIGN KEY (`refresh_acknowledged_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `psl_refresh_result_link_fk` FOREIGN KEY (`refresh_resulted_in_link_id`) REFERENCES `presentation_snapshot_links` (`id`) ON DELETE SET NULL,
  CONSTRAINT `psl_superseded_by_link_fk` FOREIGN KEY (`superseded_by_link_id`) REFERENCES `presentation_snapshot_links` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `presentation_snapshot_views`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `presentation_snapshot_views` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `snapshot_link_id` bigint(20) unsigned NOT NULL,
  `teaser_lead_id` bigint(20) unsigned DEFAULT NULL,
  `viewed_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(500) DEFAULT NULL,
  `fingerprint` varchar(128) NOT NULL,
  `referrer_url` varchar(500) DEFAULT NULL,
  `duration_seconds` int(10) unsigned DEFAULT NULL,
  `scroll_depth_pct` tinyint(3) unsigned DEFAULT NULL,
  `sections_viewed_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`sections_viewed_json`)),
  `is_first_view` tinyint(1) NOT NULL DEFAULT 0,
  `flagged_fingerprint_mismatch` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `presentation_snapshot_views_snapshot_link_id_viewed_at_index` (`snapshot_link_id`,`viewed_at`),
  KEY `presentation_snapshot_views_fingerprint_index` (`fingerprint`),
  KEY `presentation_snapshot_views_teaser_lead_id_foreign` (`teaser_lead_id`),
  CONSTRAINT `presentation_snapshot_views_snapshot_link_id_foreign` FOREIGN KEY (`snapshot_link_id`) REFERENCES `presentation_snapshot_links` (`id`) ON DELETE CASCADE,
  CONSTRAINT `presentation_snapshot_views_teaser_lead_id_foreign` FOREIGN KEY (`teaser_lead_id`) REFERENCES `presentation_teaser_leads` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `presentation_snapshots`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `presentation_snapshots` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `presentation_id` bigint(20) unsigned NOT NULL,
  `agency_id` bigint(20) unsigned NOT NULL,
  `generated_by_user_id` bigint(20) unsigned NOT NULL,
  `snapshot_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`snapshot_json`)),
  `computed_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`computed_json`)),
  `engine_versions_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`engine_versions_json`)),
  `generated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `inputs_json` text DEFAULT NULL,
  `market_analytics_run_id` bigint(20) unsigned DEFAULT NULL,
  `sale_probability_run_id` bigint(20) unsigned DEFAULT NULL,
  `output_summary_json` text DEFAULT NULL,
  `created_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `presentation_snapshots_presentation_id_foreign` (`presentation_id`),
  KEY `presentation_snapshots_generated_by_user_id_foreign` (`generated_by_user_id`),
  KEY `presentation_snapshots_market_analytics_run_id_foreign` (`market_analytics_run_id`),
  KEY `presentation_snapshots_sale_probability_run_id_foreign` (`sale_probability_run_id`),
  KEY `presentation_snapshots_created_by_user_id_foreign` (`created_by_user_id`),
  KEY `presentation_snapshots_agency_id_idx` (`agency_id`),
  CONSTRAINT `presentation_snapshots_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `presentation_snapshots_created_by_user_id_foreign` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `presentation_snapshots_generated_by_user_id_foreign` FOREIGN KEY (`generated_by_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `presentation_snapshots_market_analytics_run_id_foreign` FOREIGN KEY (`market_analytics_run_id`) REFERENCES `market_analytics_runs` (`id`) ON DELETE SET NULL,
  CONSTRAINT `presentation_snapshots_presentation_id_foreign` FOREIGN KEY (`presentation_id`) REFERENCES `presentations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `presentation_snapshots_sale_probability_run_id_foreign` FOREIGN KEY (`sale_probability_run_id`) REFERENCES `sale_probability_runs` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `presentation_sold_comps`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `presentation_sold_comps` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `mic_comp_row_id` bigint(20) unsigned DEFAULT NULL,
  `presentation_id` bigint(20) unsigned NOT NULL,
  `agency_id` bigint(20) unsigned NOT NULL,
  `source_upload_id` bigint(20) unsigned DEFAULT NULL,
  `sold_date` date DEFAULT NULL,
  `sold_price_inc` bigint(20) unsigned DEFAULT NULL,
  `suburb` varchar(255) DEFAULT NULL,
  `property_type` varchar(255) DEFAULT NULL,
  `beds` smallint(5) unsigned DEFAULT NULL,
  `baths` smallint(5) unsigned DEFAULT NULL,
  `size_m2` smallint(5) unsigned DEFAULT NULL,
  `listed_date` date DEFAULT NULL,
  `raw_row_json` text NOT NULL,
  `parser_version` varchar(50) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  `is_demo` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `presentation_sold_comps_presentation_id_foreign` (`presentation_id`),
  KEY `presentation_sold_comps_agency_id_idx` (`agency_id`),
  KEY `idx_presentation_sold_comps_is_demo` (`is_demo`),
  KEY `presentation_sold_comps_mic_comp_row_id_foreign` (`mic_comp_row_id`),
  CONSTRAINT `presentation_sold_comps_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `presentation_sold_comps_mic_comp_row_id_foreign` FOREIGN KEY (`mic_comp_row_id`) REFERENCES `market_report_comp_rows` (`id`) ON DELETE SET NULL,
  CONSTRAINT `presentation_sold_comps_presentation_id_foreign` FOREIGN KEY (`presentation_id`) REFERENCES `presentations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `presentation_teaser_leads`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `presentation_teaser_leads` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `snapshot_link_id` bigint(20) unsigned NOT NULL,
  `agency_id` bigint(20) unsigned NOT NULL,
  `presentation_id` bigint(20) unsigned NOT NULL,
  `contact_id` bigint(20) unsigned DEFAULT NULL,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `email` varchar(200) DEFAULT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `relationship` enum('owner','considering_selling','agent','researcher','other') NOT NULL DEFAULT 'other',
  `intent` enum('sell_now','sell_soon','just_curious','other') NOT NULL DEFAULT 'other',
  `consent_marketing` tinyint(1) NOT NULL DEFAULT 0,
  `consent_contact` tinyint(1) NOT NULL DEFAULT 1,
  `notes` text DEFAULT NULL,
  `captured_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `converted_to_contact_at` timestamp NULL DEFAULT NULL,
  `assigned_agent_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `presentation_teaser_leads_presentation_id_foreign` (`presentation_id`),
  KEY `presentation_teaser_leads_contact_id_foreign` (`contact_id`),
  KEY `presentation_teaser_leads_assigned_agent_id_foreign` (`assigned_agent_id`),
  KEY `presentation_teaser_leads_snapshot_link_id_index` (`snapshot_link_id`),
  KEY `presentation_teaser_leads_agency_id_captured_at_index` (`agency_id`,`captured_at`),
  KEY `presentation_teaser_leads_email_index` (`email`),
  KEY `presentation_teaser_leads_phone_index` (`phone`),
  CONSTRAINT `presentation_teaser_leads_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`),
  CONSTRAINT `presentation_teaser_leads_assigned_agent_id_foreign` FOREIGN KEY (`assigned_agent_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `presentation_teaser_leads_contact_id_foreign` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `presentation_teaser_leads_presentation_id_foreign` FOREIGN KEY (`presentation_id`) REFERENCES `presentations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `presentation_teaser_leads_snapshot_link_id_foreign` FOREIGN KEY (`snapshot_link_id`) REFERENCES `presentation_snapshot_links` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `presentation_uploads`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `presentation_uploads` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `presentation_id` bigint(20) unsigned NOT NULL,
  `agency_id` bigint(20) unsigned NOT NULL,
  `uploaded_by_user_id` bigint(20) unsigned NOT NULL,
  `type` varchar(255) NOT NULL,
  `original_filename` varchar(255) NOT NULL,
  `storage_path` varchar(255) NOT NULL,
  `file_slug` varchar(200) DEFAULT NULL,
  `content_hash` char(64) DEFAULT NULL,
  `text_extracted` longtext DEFAULT NULL,
  `extraction_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`extraction_json`)),
  `extraction_status` enum('pending','ok','failed') NOT NULL DEFAULT 'pending',
  `extracted_at` timestamp NULL DEFAULT NULL,
  `extraction_error` text DEFAULT NULL,
  `override_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`override_json`)),
  `override_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `override_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `presentation_uploads_presentation_id_foreign` (`presentation_id`),
  KEY `presentation_uploads_uploaded_by_user_id_foreign` (`uploaded_by_user_id`),
  KEY `presentation_uploads_override_by_user_id_foreign` (`override_by_user_id`),
  KEY `presentation_uploads_agency_id_idx` (`agency_id`),
  CONSTRAINT `presentation_uploads_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `presentation_uploads_override_by_user_id_foreign` FOREIGN KEY (`override_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `presentation_uploads_presentation_id_foreign` FOREIGN KEY (`presentation_id`) REFERENCES `presentations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `presentation_uploads_uploaded_by_user_id_foreign` FOREIGN KEY (`uploaded_by_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `presentation_url_snapshots`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `presentation_url_snapshots` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `presentation_id` bigint(20) unsigned NOT NULL,
  `agency_id` bigint(20) unsigned NOT NULL,
  `url` text NOT NULL,
  `final_url` text DEFAULT NULL,
  `snapshot_html` longtext DEFAULT NULL,
  `source_type` varchar(50) NOT NULL DEFAULT 'other',
  `http_status` smallint(5) unsigned DEFAULT NULL,
  `content_type` varchar(100) DEFAULT NULL,
  `content_bytes` int(10) unsigned DEFAULT NULL,
  `blocked_reason` varchar(255) DEFAULT NULL,
  `timed_out` tinyint(1) NOT NULL DEFAULT 0,
  `content_hash` char(64) DEFAULT NULL,
  `response_headers_json` text DEFAULT NULL,
  `fetched_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `presentation_url_snapshots_presentation_id_index` (`presentation_id`),
  KEY `presentation_url_snapshots_agency_id_idx` (`agency_id`),
  CONSTRAINT `presentation_url_snapshots_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `presentation_url_snapshots_presentation_id_foreign` FOREIGN KEY (`presentation_id`) REFERENCES `presentations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `presentation_versions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `presentation_versions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `presentation_id` bigint(20) unsigned NOT NULL,
  `agency_id` bigint(20) unsigned NOT NULL,
  `compiled_by` bigint(20) unsigned DEFAULT NULL,
  `reviewer_user_id` bigint(20) unsigned DEFAULT NULL,
  `reviewer_locked_at` timestamp NULL DEFAULT NULL,
  `blueprint_version` varchar(20) NOT NULL DEFAULT 'v1',
  `review_status` enum('draft','awaiting_review','published','archived') NOT NULL DEFAULT 'draft',
  `analytics_run_id` bigint(20) unsigned DEFAULT NULL,
  `probability_run_id` bigint(20) unsigned DEFAULT NULL,
  `data_snapshot_json` longtext NOT NULL,
  `included_comp_ids_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`included_comp_ids_json`)),
  `included_competitor_ids_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`included_competitor_ids_json`)),
  `condition_level_id` bigint(20) unsigned DEFAULT NULL,
  `condition_adjustment_pct` decimal(5,2) DEFAULT NULL COMMENT 'Snapshot at review/publish — defends historic PDF against later setting drift.',
  `condition_label` varchar(64) DEFAULT NULL,
  `enabled_sections_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Build 4 — per-version snapshot of which report sections render. Null means "use agency defaults at compile time".' CHECK (json_valid(`enabled_sections_json`)),
  `snapshot_payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Build 5 — full compiled report payload frozen at publish. Public view reads from this; live compile is fallback only.' CHECK (json_valid(`snapshot_payload`)),
  `snapshot_taken_at` timestamp NULL DEFAULT NULL COMMENT 'Build 5 — when snapshot_payload was last frozen. Drives the freshness window calc.',
  `hydration_summary_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`hydration_summary_json`)),
  `ai_variant_id` smallint(5) unsigned DEFAULT NULL,
  `ai_summary_text` text DEFAULT NULL,
  `ai_summary_raw_text` text DEFAULT NULL,
  `ai_summary_edited_by_agent` tinyint(1) NOT NULL DEFAULT 0,
  `ai_summary_generated_at` timestamp NULL DEFAULT NULL,
  `ai_summary_edited_at` timestamp NULL DEFAULT NULL,
  `ai_summary_model` varchar(100) DEFAULT NULL,
  `ai_summary_prompt_hash` varchar(64) DEFAULT NULL,
  `ai_summary_input_facts_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`ai_summary_input_facts_json`)),
  `compiled_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `awaiting_review_at` timestamp NULL DEFAULT NULL,
  `published_at` timestamp NULL DEFAULT NULL,
  `archived_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `presentation_versions_presentation_id_compiled_at_index` (`presentation_id`,`compiled_at`),
  KEY `presentation_versions_agency_id_idx` (`agency_id`),
  KEY `presentation_versions_ai_variant_id_foreign` (`ai_variant_id`),
  KEY `presentation_versions_reviewer_user_id_foreign` (`reviewer_user_id`),
  KEY `presentation_versions_presentation_id_review_status_index` (`presentation_id`,`review_status`),
  KEY `pv_condition_level_fk` (`condition_level_id`),
  CONSTRAINT `presentation_versions_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `presentation_versions_ai_variant_id_foreign` FOREIGN KEY (`ai_variant_id`) REFERENCES `presentation_ai_variants` (`id`) ON DELETE SET NULL,
  CONSTRAINT `presentation_versions_presentation_id_foreign` FOREIGN KEY (`presentation_id`) REFERENCES `presentations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `presentation_versions_reviewer_user_id_foreign` FOREIGN KEY (`reviewer_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `pv_condition_level_fk` FOREIGN KEY (`condition_level_id`) REFERENCES `property_setting_items` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `presentations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `presentations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `branch_id` bigint(20) unsigned NOT NULL,
  `created_by_user_id` bigint(20) unsigned NOT NULL,
  `listing_id` bigint(20) unsigned DEFAULT NULL,
  `property_id` bigint(20) unsigned DEFAULT NULL,
  `tracked_property_id` bigint(20) unsigned DEFAULT NULL,
  `seller_contact_id` bigint(20) unsigned DEFAULT NULL,
  `deal_id` bigint(20) unsigned DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `property_address` varchar(255) DEFAULT NULL,
  `suburb` varchar(100) DEFAULT NULL,
  `property_type` varchar(20) DEFAULT NULL,
  `bedrooms` smallint(5) unsigned DEFAULT NULL,
  `bathrooms` smallint(5) unsigned DEFAULT NULL,
  `garages_parking` smallint(5) unsigned DEFAULT NULL,
  `erf_size_m2` int(10) unsigned DEFAULT NULL,
  `floor_area_m2` smallint(5) unsigned DEFAULT NULL,
  `asking_price_inc` bigint(20) unsigned DEFAULT NULL,
  `monthly_bond` decimal(12,2) DEFAULT NULL,
  `monthly_rates` decimal(12,2) DEFAULT NULL,
  `monthly_levies` decimal(12,2) DEFAULT NULL,
  `monthly_insurance` decimal(12,2) DEFAULT NULL,
  `monthly_utilities` decimal(12,2) DEFAULT NULL,
  `monthly_garden` decimal(12,2) DEFAULT NULL,
  `monthly_pool` decimal(12,2) DEFAULT NULL,
  `monthly_security` decimal(12,2) DEFAULT NULL,
  `monthly_opportunity_cost` decimal(12,2) DEFAULT NULL,
  `cma_selected_range` varchar(10) NOT NULL DEFAULT 'middle',
  `vicinity_selected_range` varchar(10) NOT NULL DEFAULT 'middle',
  `comp_scope` enum('radius_all','suburb_only') DEFAULT NULL,
  `comp_radius_m` smallint(5) unsigned DEFAULT NULL,
  `excluded_active_listing_indices` text DEFAULT NULL,
  `simulator_config_json` text DEFAULT NULL,
  `seller_live_capture_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`seller_live_capture_json`)),
  `seller_name` varchar(255) DEFAULT NULL,
  `seller_email` varchar(255) DEFAULT NULL,
  `status` enum('draft','finalized') NOT NULL DEFAULT 'draft',
  `currency` varchar(10) NOT NULL DEFAULT 'ZAR',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `agency_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `presentations_branch_id_foreign` (`branch_id`),
  KEY `presentations_created_by_user_id_foreign` (`created_by_user_id`),
  KEY `presentations_agency_id_index` (`agency_id`),
  KEY `idx_presentations_agency_id` (`agency_id`),
  KEY `presentations_property_id_index` (`property_id`),
  KEY `presentations_tracked_property_id_index` (`tracked_property_id`),
  KEY `presentations_seller_contact_id_index` (`seller_contact_id`),
  KEY `presentations_deal_id_index` (`deal_id`),
  CONSTRAINT `presentations_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`),
  CONSTRAINT `presentations_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE,
  CONSTRAINT `presentations_created_by_user_id_foreign` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `price_bands`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `price_bands` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint(20) unsigned NOT NULL,
  `listing_type` enum('sale','rental') NOT NULL,
  `name` varchar(100) NOT NULL,
  `price_min` bigint(20) unsigned NOT NULL,
  `price_max` bigint(20) unsigned DEFAULT NULL,
  `display_order` smallint(5) unsigned NOT NULL DEFAULT 0,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `price_bands_agency_type_order_idx` (`agency_id`,`listing_type`,`display_order`),
  KEY `price_bands_agency_type_min_idx` (`agency_id`,`listing_type`,`price_min`),
  KEY `price_bands_deleted_idx` (`deleted_at`),
  CONSTRAINT `price_bands_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `properties`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `properties` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `external_id` char(36) NOT NULL,
  `title` varchar(255) NOT NULL,
  `headline` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `excerpt` text DEFAULT NULL,
  `price` bigint(20) unsigned NOT NULL DEFAULT 0,
  `price_on_application` tinyint(1) NOT NULL DEFAULT 0,
  `has_deposit` tinyint(1) NOT NULL DEFAULT 0,
  `lease_period` varchar(100) DEFAULT NULL,
  `price_per_day` decimal(12,2) DEFAULT NULL,
  `price_per_week` decimal(12,2) DEFAULT NULL,
  `price_per_year` decimal(12,2) DEFAULT NULL,
  `lease_type` varchar(100) DEFAULT NULL,
  `gross_price` decimal(12,2) DEFAULT NULL,
  `net_price` decimal(12,2) DEFAULT NULL,
  `yard_price` decimal(12,2) DEFAULT NULL,
  `primary_price_display` varchar(255) DEFAULT NULL,
  `rates_taxes` bigint(20) unsigned DEFAULT NULL,
  `municipal_valuation` decimal(15,2) DEFAULT NULL,
  `municipal_valuation_year` smallint(5) unsigned DEFAULT NULL,
  `levy` bigint(20) unsigned DEFAULT NULL,
  `special_levy` bigint(20) unsigned DEFAULT NULL,
  `rental_amount` decimal(12,2) DEFAULT NULL,
  `deposit_amount` decimal(12,2) DEFAULT NULL,
  `commission_percent` decimal(5,2) DEFAULT NULL,
  `admin_fee` decimal(12,2) DEFAULT NULL,
  `marketing_fee` decimal(12,2) DEFAULT NULL,
  `suburb` varchar(255) NOT NULL DEFAULT '',
  `suburb_normalised` varchar(100) DEFAULT NULL,
  `p24_suburb_id` bigint(20) unsigned DEFAULT NULL,
  `p24_city_id` bigint(20) unsigned DEFAULT NULL,
  `p24_province_id` bigint(20) unsigned DEFAULT NULL,
  `p24_suburb_mismatch` tinyint(1) NOT NULL DEFAULT 0,
  `address` varchar(255) DEFAULT NULL,
  `street_name` varchar(255) DEFAULT NULL,
  `street_name_normalised` varchar(200) DEFAULT NULL,
  `street_number` varchar(255) DEFAULT NULL,
  `city` varchar(255) DEFAULT NULL,
  `region` varchar(255) DEFAULT NULL,
  `district` varchar(255) DEFAULT NULL,
  `province` varchar(255) DEFAULT NULL,
  `town` varchar(255) DEFAULT NULL,
  `beds` tinyint(4) NOT NULL DEFAULT 0,
  `baths` tinyint(4) NOT NULL DEFAULT 0,
  `garages` tinyint(4) NOT NULL DEFAULT 0,
  `size_m2` int(10) unsigned DEFAULT NULL,
  `erf_size_m2` int(10) unsigned DEFAULT NULL,
  `property_number` varchar(100) DEFAULT NULL,
  `stand_number` varchar(100) DEFAULT NULL,
  `erf_number` varchar(100) DEFAULT NULL,
  `erf_portion` varchar(20) DEFAULT '0',
  `sg_province` varchar(30) DEFAULT NULL,
  `sg_rural_urban` enum('rural','urban') NOT NULL DEFAULT 'urban',
  `sg_farm_name` varchar(200) DEFAULT NULL,
  `sg_last_searched_at` timestamp NULL DEFAULT NULL,
  `title_deed_number` varchar(100) DEFAULT NULL,
  `zone_type` varchar(100) DEFAULT NULL,
  `address_internal_note` text DEFAULT NULL,
  `complex_name` varchar(255) DEFAULT NULL,
  `unit_number` varchar(100) DEFAULT NULL,
  `floor_number` varchar(50) DEFAULT NULL,
  `unit_section_block` varchar(255) DEFAULT NULL,
  `property_type` varchar(255) NOT NULL DEFAULT 'house',
  `title_type` enum('full_title','sectional_title','vacant_land','other') DEFAULT NULL COMMENT 'Keystone — derived from property_type by TitleTypeClassifier on every save. Source of truth for comp-filter and review-screen badge.',
  `condition_level_id` bigint(20) unsigned DEFAULT NULL COMMENT 'Build 3 — FK to property_setting_items where group=condition_level. Nullable: property may have no recorded condition.',
  `category` varchar(255) DEFAULT NULL,
  `mandate_type` varchar(255) DEFAULT NULL,
  `listing_type` varchar(255) DEFAULT NULL,
  `status` varchar(255) NOT NULL DEFAULT 'draft',
  `images_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`images_json`)),
  `dawn_images_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`dawn_images_json`)),
  `noon_images_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`noon_images_json`)),
  `dusk_images_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`dusk_images_json`)),
  `gallery_images_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`gallery_images_json`)),
  `gallery_categories_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`gallery_categories_json`)),
  `gallery_custom_tags` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`gallery_custom_tags`)),
  `features_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`features_json`)),
  `features_json_meta` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Per-feature audit: {pool:{source:ai|manual,confidence:0.92,confirmed_by_user_id:5,confirmed_at:...}}' CHECK (json_valid(`features_json_meta`)),
  `pet_friendly` tinyint(1) DEFAULT NULL,
  `spaces_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`spaces_json`)),
  `agent_id` bigint(20) unsigned NOT NULL,
  `pp_second_agent_id` bigint(20) unsigned DEFAULT NULL,
  `pp_agent_image_path` varchar(255) DEFAULT NULL,
  `pp_second_agent_image_path` varchar(255) DEFAULT NULL,
  `branch_id` bigint(20) unsigned NOT NULL,
  `agency_id` bigint(20) unsigned DEFAULT NULL,
  `published_at` timestamp NULL DEFAULT NULL,
  `listed_date` date DEFAULT NULL,
  `expiry_date` date DEFAULT NULL,
  `lease_start_date` date DEFAULT NULL,
  `lease_end_date` date DEFAULT NULL,
  `pp_syndication_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `pp_syndication_status` varchar(255) DEFAULT NULL,
  `pp_ref` varchar(255) DEFAULT NULL,
  `pp_listing_feed_ref` varchar(255) DEFAULT NULL,
  `pp_last_submitted_at` timestamp NULL DEFAULT NULL,
  `pp_activated_at` timestamp NULL DEFAULT NULL,
  `pp_exclusive_days` int(11) DEFAULT NULL,
  `pp_delay_until` timestamp NULL DEFAULT NULL,
  `pp_last_error` text DEFAULT NULL,
  `pp_images_last_synced_at` timestamp NULL DEFAULT NULL,
  `pp_listing_last_synced_at` timestamp NULL DEFAULT NULL,
  `pp_hide_street_name` tinyint(1) NOT NULL DEFAULT 0,
  `pp_hide_street_number` tinyint(1) NOT NULL DEFAULT 0,
  `pp_hide_complex_name` tinyint(1) NOT NULL DEFAULT 0,
  `pp_hide_unit_number` tinyint(1) NOT NULL DEFAULT 0,
  `youtube_video_id` varchar(11) DEFAULT NULL,
  `matterport_id` varchar(100) DEFAULT NULL,
  `virtual_tour_url` varchar(1000) DEFAULT NULL,
  `rental_price_type` varchar(255) DEFAULT NULL,
  `p24_syndication_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `p24_syndication_status` varchar(255) DEFAULT NULL,
  `p24_ref` varchar(255) DEFAULT NULL,
  `p24_last_submitted_at` timestamp NULL DEFAULT NULL,
  `p24_activated_at` timestamp NULL DEFAULT NULL,
  `p24_last_error` text DEFAULT NULL,
  `p24_images_last_synced_at` timestamp NULL DEFAULT NULL,
  `p24_listing_last_synced_at` timestamp NULL DEFAULT NULL,
  `pp_suburb_id` int(10) unsigned DEFAULT NULL,
  `latitude` decimal(10,7) DEFAULT NULL,
  `longitude` decimal(10,7) DEFAULT NULL,
  `geo_source` varchar(30) DEFAULT NULL,
  `geo_confidence` varchar(20) DEFAULT NULL,
  `geo_resolved_at` timestamp NULL DEFAULT NULL,
  `cma_gps_lat` decimal(10,7) DEFAULT NULL,
  `cma_gps_lng` decimal(10,7) DEFAULT NULL,
  `last_cma_at` timestamp NULL DEFAULT NULL,
  `last_cma_presentation_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `last_activity_at` datetime DEFAULT NULL,
  `compliance_snapshot_at` timestamp NULL DEFAULT NULL,
  `compliance_snapshot_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`compliance_snapshot_data`)),
  `compliance_evidence_flags` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`compliance_evidence_flags`)),
  `first_marketed_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `p24_listing_number` varchar(255) DEFAULT NULL,
  `is_demo` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `properties_external_id_unique` (`external_id`),
  KEY `properties_agent_id_foreign` (`agent_id`),
  KEY `properties_p24_listing_number_index` (`p24_listing_number`),
  KEY `properties_branch_id_foreign` (`branch_id`),
  KEY `properties_p24_suburb_id_foreign` (`p24_suburb_id`),
  KEY `properties_p24_city_id_foreign` (`p24_city_id`),
  KEY `properties_p24_province_id_foreign` (`p24_province_id`),
  KEY `idx_properties_last_cma_at` (`last_cma_at`),
  KEY `idx_properties_erf_number` (`erf_number`),
  KEY `idx_properties_title_deed_number` (`title_deed_number`),
  KEY `idx_properties_geo` (`latitude`,`longitude`),
  KEY `idx_properties_is_demo` (`is_demo`),
  KEY `idx_properties_address_key` (`agency_id`,`suburb_normalised`,`street_name_normalised`,`street_number`,`unit_number`),
  KEY `properties_condition_level_idx` (`condition_level_id`),
  KEY `properties_title_type_idx` (`title_type`),
  CONSTRAINT `properties_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE SET NULL,
  CONSTRAINT `properties_agent_id_foreign` FOREIGN KEY (`agent_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `properties_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  CONSTRAINT `properties_condition_level_fk` FOREIGN KEY (`condition_level_id`) REFERENCES `property_setting_items` (`id`) ON DELETE SET NULL,
  CONSTRAINT `properties_p24_city_id_foreign` FOREIGN KEY (`p24_city_id`) REFERENCES `p24_cities` (`id`) ON DELETE SET NULL,
  CONSTRAINT `properties_p24_province_id_foreign` FOREIGN KEY (`p24_province_id`) REFERENCES `p24_provinces` (`id`) ON DELETE SET NULL,
  CONSTRAINT `properties_p24_suburb_id_foreign` FOREIGN KEY (`p24_suburb_id`) REFERENCES `p24_suburbs` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `property_ad_templates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `property_ad_templates` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `agency_id` bigint(20) unsigned NOT NULL,
  `name` varchar(100) NOT NULL,
  `layout_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`layout_json`)),
  `is_global` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `property_ad_templates_user_id_foreign` (`user_id`),
  KEY `property_ad_templates_agency_id_idx` (`agency_id`),
  CONSTRAINT `property_ad_templates_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `property_ad_templates_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `property_audit_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `property_audit_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `property_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `agency_id` bigint(20) unsigned NOT NULL,
  `branch_id` bigint(20) unsigned DEFAULT NULL,
  `event_category` varchar(40) NOT NULL,
  `event_type` varchar(80) NOT NULL,
  `old_values` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`old_values`)),
  `new_values` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`new_values`)),
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata`)),
  `human_summary` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `property_audit_log_user_id_foreign` (`user_id`),
  KEY `property_audit_log_branch_id_foreign` (`branch_id`),
  KEY `property_audit_log_property_id_created_at_index` (`property_id`,`created_at`),
  KEY `property_audit_log_property_id_event_category_index` (`property_id`,`event_category`),
  KEY `property_audit_log_agency_id_created_at_index` (`agency_id`,`created_at`),
  CONSTRAINT `property_audit_log_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`),
  CONSTRAINT `property_audit_log_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  CONSTRAINT `property_audit_log_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE,
  CONSTRAINT `property_audit_log_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `property_buyer_matches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `property_buyer_matches` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `property_id` bigint(20) unsigned NOT NULL,
  `contact_id` bigint(20) unsigned NOT NULL,
  `agency_id` bigint(20) unsigned NOT NULL,
  `score` smallint(5) unsigned NOT NULL,
  `tier` varchar(20) NOT NULL,
  `breakdown` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`breakdown`)),
  `missing_features` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`missing_features`)),
  `computed_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `property_buyer_matches_property_id_contact_id_unique` (`property_id`,`contact_id`),
  KEY `property_buyer_matches_contact_id_score_index` (`contact_id`,`score`),
  KEY `property_buyer_matches_property_id_score_index` (`property_id`,`score`),
  KEY `pbm2_agency_contact_idx` (`agency_id`,`contact_id`),
  KEY `pbm2_agency_property_idx` (`agency_id`,`property_id`),
  CONSTRAINT `property_buyer_matches_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `property_buyer_matches_contact_id_foreign` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `property_buyer_matches_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `property_files`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `property_files` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `property_id` bigint(20) unsigned NOT NULL,
  `agency_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  `path` varchar(255) NOT NULL,
  `size` bigint(20) unsigned NOT NULL DEFAULT 0,
  `mime_type` varchar(255) DEFAULT NULL,
  `document_type_id` bigint(20) unsigned DEFAULT NULL,
  `contact_id` bigint(20) unsigned DEFAULT NULL,
  `source_type` varchar(20) NOT NULL DEFAULT 'upload',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `property_files_property_id_foreign` (`property_id`),
  KEY `property_files_user_id_foreign` (`user_id`),
  KEY `property_files_document_type_id_foreign` (`document_type_id`),
  KEY `property_files_contact_id_foreign` (`contact_id`),
  KEY `property_files_agency_id_idx` (`agency_id`),
  CONSTRAINT `property_files_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `property_files_contact_id_foreign` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `property_files_document_type_id_foreign` FOREIGN KEY (`document_type_id`) REFERENCES `document_types` (`id`) ON DELETE SET NULL,
  CONSTRAINT `property_files_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE,
  CONSTRAINT `property_files_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `property_health_scores`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `property_health_scores` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `property_id` bigint(20) unsigned NOT NULL,
  `agency_id` bigint(20) unsigned NOT NULL,
  `score` tinyint(3) unsigned NOT NULL DEFAULT 0 COMMENT '0-100',
  `grade` varchar(20) NOT NULL DEFAULT 'attention' COMMENT 'excellent, good, attention, critical',
  `factors` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Breakdown of each factor contribution' CHECK (json_valid(`factors`)),
  `last_calculated_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `property_health_scores_property_id_unique` (`property_id`),
  KEY `property_health_scores_agency_id_idx` (`agency_id`),
  CONSTRAINT `property_health_scores_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `property_health_scores_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `property_image_analyses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `property_image_analyses` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint(20) unsigned NOT NULL,
  `property_id` bigint(20) unsigned NOT NULL,
  `image_path` varchar(512) NOT NULL COMMENT 'Path relative to storage (matches values in properties.gallery_images_json etc.)',
  `status` enum('queued','processing','complete','failed') NOT NULL DEFAULT 'queued',
  `detected_features` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT '[{token, confidence}]' CHECK (json_valid(`detected_features`)),
  `detected_spaces` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT '[{token, confidence}]' CHECK (json_valid(`detected_spaces`)),
  `raw_response` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Full Claude vision response for debug' CHECK (json_valid(`raw_response`)),
  `cost_usd` decimal(8,5) DEFAULT NULL,
  `error` text DEFAULT NULL,
  `processed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `property_image_analyses_property_id_foreign` (`property_id`),
  KEY `property_image_analyses_agency_id_property_id_index` (`agency_id`,`property_id`),
  KEY `property_image_analyses_status_index` (`status`),
  KEY `property_image_analyses_image_path_index` (`image_path`),
  CONSTRAINT `property_image_analyses_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `property_image_analyses_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `property_marketing_activities`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `property_marketing_activities` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `property_id` bigint(20) unsigned NOT NULL,
  `agency_id` bigint(20) unsigned NOT NULL,
  `activity_type` enum('portal_listed','portal_renewed','photos_refreshed','price_adjusted','show_day_held','social_share','featured_upgrade','marketing_email','other') NOT NULL,
  `activity_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`activity_data`)),
  `occurred_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `logged_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `internal_only` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `property_marketing_activities_logged_by_user_id_foreign` (`logged_by_user_id`),
  KEY `property_marketing_activities_property_id_occurred_at_index` (`property_id`,`occurred_at`),
  KEY `property_marketing_activities_agency_id_idx` (`agency_id`),
  CONSTRAINT `property_marketing_activities_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `property_marketing_activities_logged_by_user_id_foreign` FOREIGN KEY (`logged_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `property_marketing_activities_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `property_marketing_posts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `property_marketing_posts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `property_id` bigint(20) unsigned NOT NULL,
  `agency_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `platform` enum('facebook','instagram') NOT NULL,
  `platform_post_id` varchar(255) DEFAULT NULL,
  `ad_copy` text NOT NULL,
  `image_urls` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`image_urls`)),
  `status` enum('draft','published','failed') NOT NULL DEFAULT 'draft',
  `published_at` timestamp NULL DEFAULT NULL,
  `last_synced_at` timestamp NULL DEFAULT NULL,
  `impressions` int(11) NOT NULL DEFAULT 0,
  `reach` int(11) NOT NULL DEFAULT 0,
  `likes` int(11) NOT NULL DEFAULT 0,
  `comments` int(11) NOT NULL DEFAULT 0,
  `shares` int(11) NOT NULL DEFAULT 0,
  `link_clicks` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `property_marketing_posts_property_id_foreign` (`property_id`),
  KEY `property_marketing_posts_user_id_foreign` (`user_id`),
  KEY `property_marketing_posts_agency_id_idx` (`agency_id`),
  CONSTRAINT `property_marketing_posts_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `property_marketing_posts_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE,
  CONSTRAINT `property_marketing_posts_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `property_notes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `property_notes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `property_id` bigint(20) unsigned NOT NULL,
  `agency_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `content` text NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `property_notes_property_id_foreign` (`property_id`),
  KEY `property_notes_user_id_foreign` (`user_id`),
  KEY `property_notes_agency_id_idx` (`agency_id`),
  CONSTRAINT `property_notes_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `property_notes_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE,
  CONSTRAINT `property_notes_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `property_presentation_snapshots`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `property_presentation_snapshots` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `property_id` bigint(20) unsigned NOT NULL,
  `agency_id` bigint(20) unsigned NOT NULL,
  `presentation_id` bigint(20) unsigned DEFAULT NULL,
  `generated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `generated_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `market_data_snapshot` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`market_data_snapshot`)),
  `recommended_price_at_time` decimal(14,2) DEFAULT NULL,
  `days_on_market_at_time` int(10) unsigned DEFAULT NULL,
  `is_dynamic` tinyint(1) NOT NULL DEFAULT 0,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `property_presentation_snapshots_generated_by_user_id_foreign` (`generated_by_user_id`),
  KEY `property_presentation_snapshots_property_id_generated_at_index` (`property_id`,`generated_at`),
  KEY `property_presentation_snapshots_agency_id_idx` (`agency_id`),
  CONSTRAINT `property_presentation_snapshots_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `property_presentation_snapshots_generated_by_user_id_foreign` FOREIGN KEY (`generated_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `property_presentation_snapshots_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `property_recommendations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `property_recommendations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `property_id` bigint(20) unsigned NOT NULL,
  `agency_id` bigint(20) unsigned NOT NULL,
  `recommendation_code` varchar(50) NOT NULL,
  `title` varchar(255) NOT NULL,
  `reasoning` text NOT NULL,
  `suggested_action` varchar(500) DEFAULT NULL,
  `seller_facing_title` varchar(255) DEFAULT NULL,
  `seller_facing_reasoning` text DEFAULT NULL,
  `seller_visible` tinyint(1) NOT NULL DEFAULT 1,
  `generated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `dismissed_at` timestamp NULL DEFAULT NULL,
  `dismissed_by` bigint(20) unsigned DEFAULT NULL,
  `actioned_at` timestamp NULL DEFAULT NULL,
  `actioned_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `property_recommendations_agency_id_foreign` (`agency_id`),
  KEY `property_recommendations_dismissed_by_foreign` (`dismissed_by`),
  KEY `property_recommendations_actioned_by_foreign` (`actioned_by`),
  KEY `property_recommendations_property_id_dismissed_at_index` (`property_id`,`dismissed_at`),
  CONSTRAINT `property_recommendations_actioned_by_foreign` FOREIGN KEY (`actioned_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `property_recommendations_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `property_recommendations_dismissed_by_foreign` FOREIGN KEY (`dismissed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `property_recommendations_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `property_seller_link_accesses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `property_seller_link_accesses` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `link_id` bigint(20) unsigned NOT NULL,
  `agency_id` bigint(20) unsigned NOT NULL,
  `accessed_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `property_seller_link_accesses_link_id_foreign` (`link_id`),
  KEY `property_seller_link_accesses_agency_id_idx` (`agency_id`),
  CONSTRAINT `property_seller_link_accesses_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `property_seller_link_accesses_link_id_foreign` FOREIGN KEY (`link_id`) REFERENCES `property_seller_links` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `property_seller_links`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `property_seller_links` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `property_id` bigint(20) unsigned NOT NULL,
  `agency_id` bigint(20) unsigned NOT NULL,
  `token` varchar(64) NOT NULL,
  `contact_id` bigint(20) unsigned NOT NULL,
  `generated_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `generated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `last_accessed_at` timestamp NULL DEFAULT NULL,
  `access_count` int(10) unsigned NOT NULL DEFAULT 0,
  `revoked_at` timestamp NULL DEFAULT NULL,
  `revoked_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `property_seller_links_token_unique` (`token`),
  KEY `property_seller_links_contact_id_foreign` (`contact_id`),
  KEY `property_seller_links_generated_by_user_id_foreign` (`generated_by_user_id`),
  KEY `property_seller_links_revoked_by_user_id_foreign` (`revoked_by_user_id`),
  KEY `property_seller_links_property_id_revoked_at_index` (`property_id`,`revoked_at`),
  KEY `property_seller_links_agency_id_idx` (`agency_id`),
  CONSTRAINT `property_seller_links_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `property_seller_links_contact_id_foreign` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `property_seller_links_generated_by_user_id_foreign` FOREIGN KEY (`generated_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `property_seller_links_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE,
  CONSTRAINT `property_seller_links_revoked_by_user_id_foreign` FOREIGN KEY (`revoked_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `property_setting_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `property_setting_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `group` varchar(50) NOT NULL,
  `name` varchar(255) NOT NULL,
  `title_type` enum('full_title','sectional_title','vacant_land','other') NOT NULL DEFAULT 'other' COMMENT 'Comp-selection discipline: houses do not compare to apartments. See .ai/specs/presentation-data-lineage.md §3-A.',
  `adjustment_pct` decimal(5,2) DEFAULT NULL COMMENT 'Build 3 — % adjustment applied to CMA Middle band when this condition_level is selected. Null for non-condition rows.',
  `sort_order` smallint(5) unsigned NOT NULL DEFAULT 0,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `agency_id` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `property_setting_items_group_index` (`group`),
  KEY `property_setting_items_agency_id_idx` (`agency_id`),
  CONSTRAINT `property_setting_items_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `property_sg_documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `property_sg_documents` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `property_id` bigint(20) unsigned NOT NULL,
  `agency_id` bigint(20) unsigned NOT NULL,
  `sg_document_number` varchar(50) NOT NULL,
  `sg_page_number` smallint(5) unsigned NOT NULL DEFAULT 1,
  `sg_doc_type` enum('DIAGRAM','GENERAL_PLAN','SERVITUDE','TITLE_DEED','OTHER') NOT NULL DEFAULT 'OTHER',
  `sg_source_url` varchar(500) NOT NULL,
  `storage_path` varchar(500) DEFAULT NULL,
  `file_size_bytes` int(10) unsigned DEFAULT NULL,
  `mime_type` varchar(50) DEFAULT NULL,
  `sha256` varchar(64) DEFAULT NULL,
  `is_saved` tinyint(1) NOT NULL DEFAULT 0,
  `saved_at` timestamp NULL DEFAULT NULL,
  `saved_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `psgd_prop_doc_page_uq` (`property_id`,`sg_document_number`,`sg_page_number`),
  KEY `psgd_saver_fk` (`saved_by_user_id`),
  KEY `psgd_prop_type_idx` (`property_id`,`sg_doc_type`),
  KEY `psgd_sha_idx` (`sha256`),
  KEY `psgd_agency_saved_idx` (`agency_id`,`is_saved`),
  CONSTRAINT `psgd_agency_fk` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `psgd_property_fk` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE,
  CONSTRAINT `psgd_saver_fk` FOREIGN KEY (`saved_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `property_showdays`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `property_showdays` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `property_id` bigint(20) unsigned NOT NULL,
  `agency_id` bigint(20) unsigned NOT NULL,
  `start_date` datetime NOT NULL,
  `end_date` datetime NOT NULL,
  `description` varchar(255) NOT NULL DEFAULT 'Open Showday',
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `synced_to_pp` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `property_showdays_property_id_foreign` (`property_id`),
  KEY `property_showdays_agency_id_idx` (`agency_id`),
  CONSTRAINT `property_showdays_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `property_showdays_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `property_sold_records`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `property_sold_records` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `property_id` bigint(20) unsigned DEFAULT NULL,
  `external_property_id` varchar(100) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `suburb` varchar(100) DEFAULT NULL,
  `area` varchar(100) DEFAULT NULL,
  `sold_price` decimal(14,2) NOT NULL,
  `sold_date` date NOT NULL,
  `bedrooms` smallint(5) unsigned DEFAULT NULL,
  `bathrooms` decimal(3,1) DEFAULT NULL,
  `sqm` decimal(8,2) DEFAULT NULL,
  `property_type` varchar(50) DEFAULT NULL,
  `days_on_market` int(10) unsigned DEFAULT NULL,
  `listing_price_at_sale` decimal(14,2) DEFAULT NULL,
  `source` enum('manual','tva_api','p24_capture','pp_capture','deeds_office') NOT NULL DEFAULT 'manual',
  `source_reference` varchar(255) DEFAULT NULL,
  `captured_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `captured_at` timestamp NULL DEFAULT NULL,
  `agency_id` bigint(20) unsigned DEFAULT NULL,
  `verified` tinyint(1) NOT NULL DEFAULT 0,
  `verified_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `verified_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `property_sold_records_property_id_foreign` (`property_id`),
  KEY `property_sold_records_captured_by_user_id_foreign` (`captured_by_user_id`),
  KEY `property_sold_records_verified_by_user_id_foreign` (`verified_by_user_id`),
  KEY `property_sold_records_suburb_sold_date_index` (`suburb`,`sold_date`),
  KEY `property_sold_records_area_sold_date_index` (`area`,`sold_date`),
  KEY `property_sold_records_agency_id_sold_date_index` (`agency_id`,`sold_date`),
  CONSTRAINT `property_sold_records_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE SET NULL,
  CONSTRAINT `property_sold_records_captured_by_user_id_foreign` FOREIGN KEY (`captured_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `property_sold_records_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE SET NULL,
  CONSTRAINT `property_sold_records_verified_by_user_id_foreign` FOREIGN KEY (`verified_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `property_type_options`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `property_type_options` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint(20) unsigned NOT NULL,
  `name` varchar(100) NOT NULL,
  `slug` varchar(120) NOT NULL,
  `display_order` smallint(5) unsigned NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `prop_types_agency_slug_unique` (`agency_id`,`slug`),
  KEY `prop_types_agency_order_active_idx` (`agency_id`,`display_order`,`is_active`),
  KEY `prop_types_deleted_idx` (`deleted_at`),
  CONSTRAINT `property_type_options_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `property_website_syndication`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `property_website_syndication` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint(20) unsigned NOT NULL,
  `property_id` bigint(20) unsigned NOT NULL,
  `agency_api_key_id` bigint(20) unsigned NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 0,
  `status` varchar(255) DEFAULT NULL,
  `last_submitted_at` timestamp NULL DEFAULT NULL,
  `activated_at` timestamp NULL DEFAULT NULL,
  `last_synced_at` timestamp NULL DEFAULT NULL,
  `last_error` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `property_website_unique` (`property_id`,`agency_api_key_id`),
  KEY `property_website_syndication_agency_id_index` (`agency_id`),
  KEY `property_website_syndication_property_id_index` (`property_id`),
  KEY `property_website_syndication_agency_api_key_id_index` (`agency_api_key_id`),
  CONSTRAINT `property_website_syndication_agency_api_key_id_foreign` FOREIGN KEY (`agency_api_key_id`) REFERENCES `agency_api_keys` (`id`) ON DELETE CASCADE,
  CONSTRAINT `property_website_syndication_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `property_website_syndication_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `prospecting_buyer_matches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `prospecting_buyer_matches` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `prospecting_listing_id` bigint(20) unsigned NOT NULL,
  `contact_id` bigint(20) unsigned NOT NULL,
  `agency_id` bigint(20) unsigned NOT NULL,
  `score` smallint(5) unsigned NOT NULL DEFAULT 0 COMMENT 'Match score 0-100',
  `tier` enum('perfect','strong','approximate') NOT NULL DEFAULT 'approximate',
  `matched_features` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'What criteria matched' CHECK (json_valid(`matched_features`)),
  `missing_features` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'What criteria are missing/gap' CHECK (json_valid(`missing_features`)),
  `matched_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `last_recompute_at` timestamp NULL DEFAULT NULL,
  `agent_notified_at` timestamp NULL DEFAULT NULL,
  `dismissed_at` timestamp NULL DEFAULT NULL,
  `dismissed_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `pbm_listing_contact_unique` (`prospecting_listing_id`,`contact_id`),
  KEY `prospecting_buyer_matches_dismissed_by_user_id_foreign` (`dismissed_by_user_id`),
  KEY `pbm_listing_score` (`prospecting_listing_id`,`score`),
  KEY `pbm_contact_score` (`contact_id`,`score`),
  KEY `pbm_tier_date` (`tier`,`matched_at`),
  KEY `pbm_agency_contact_idx` (`agency_id`,`contact_id`),
  KEY `pbm_agency_listing_idx` (`agency_id`,`prospecting_listing_id`),
  CONSTRAINT `prospecting_buyer_matches_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `prospecting_buyer_matches_contact_id_foreign` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `prospecting_buyer_matches_dismissed_by_user_id_foreign` FOREIGN KEY (`dismissed_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `prospecting_buyer_matches_prospecting_listing_id_foreign` FOREIGN KEY (`prospecting_listing_id`) REFERENCES `prospecting_listings` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `prospecting_claims`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `prospecting_claims` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint(20) unsigned NOT NULL,
  `prospecting_listing_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'claimed',
  `notes` text DEFAULT NULL,
  `claimed_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `feedback_at` timestamp NULL DEFAULT NULL,
  `last_updated_at` timestamp NULL DEFAULT NULL,
  `released_at` timestamp NULL DEFAULT NULL,
  `flagged_at` timestamp NULL DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `prospecting_claims_prospecting_listing_id_is_active_index` (`prospecting_listing_id`,`is_active`),
  KEY `prospecting_claims_user_id_is_active_index` (`user_id`,`is_active`),
  KEY `prospecting_claims_agency_id_status_index` (`agency_id`,`status`),
  CONSTRAINT `prospecting_claims_prospecting_listing_id_foreign` FOREIGN KEY (`prospecting_listing_id`) REFERENCES `prospecting_listings` (`id`),
  CONSTRAINT `prospecting_claims_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `prospecting_listings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `prospecting_listings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `matched_property_id` bigint(20) unsigned DEFAULT NULL,
  `tracked_property_id` bigint(20) unsigned DEFAULT NULL,
  `matched_at` timestamp NULL DEFAULT NULL,
  `agency_id` bigint(20) unsigned NOT NULL,
  `captured_by_user_id` bigint(20) unsigned NOT NULL,
  `portal_source` enum('p24','pp') NOT NULL,
  `portal_ref` varchar(50) NOT NULL,
  `portal_url` varchar(500) NOT NULL,
  `address` varchar(255) NOT NULL,
  `normalized_address` varchar(255) DEFAULT NULL,
  `property_group_id` bigint(20) unsigned DEFAULT NULL,
  `suburb` varchar(100) NOT NULL,
  `latitude` decimal(10,7) DEFAULT NULL COMMENT 'Resolved by AddressResolverService — building-level when street parts present, suburb_centroid as last resort. Indexed for radius queries.',
  `longitude` decimal(10,7) DEFAULT NULL,
  `district` varchar(100) DEFAULT NULL,
  `price` int(11) NOT NULL,
  `bedrooms` smallint(6) DEFAULT NULL,
  `bathrooms` smallint(6) DEFAULT NULL,
  `garages` smallint(6) DEFAULT NULL,
  `property_size_m2` decimal(10,2) DEFAULT NULL,
  `erf_size_m2` decimal(10,2) DEFAULT NULL,
  `property_type` varchar(50) DEFAULT NULL,
  `agent_name` varchar(100) DEFAULT NULL,
  `agency_name` varchar(100) DEFAULT NULL,
  `thumbnail_path` varchar(255) DEFAULT NULL,
  `first_seen_at` datetime NOT NULL,
  `last_seen_at` datetime NOT NULL,
  `first_seen_email_date` timestamp NULL DEFAULT NULL,
  `price_changed_at` datetime DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `prospecting_listings_agency_id_portal_source_portal_ref_unique` (`agency_id`,`portal_source`,`portal_ref`),
  KEY `prospecting_listings_agency_id_index` (`agency_id`),
  KEY `prospecting_listings_captured_by_user_id_index` (`captured_by_user_id`),
  KEY `prospecting_listings_suburb_index` (`suburb`),
  KEY `prospecting_listings_price_index` (`price`),
  KEY `prospecting_listings_property_type_index` (`property_type`),
  KEY `prospecting_listings_is_active_index` (`is_active`),
  KEY `prospecting_listings_agency_id_normalized_address_index` (`agency_id`,`normalized_address`),
  KEY `prospecting_listings_normalized_address_index` (`normalized_address`),
  KEY `prospecting_listings_property_group_id_index` (`property_group_id`),
  KEY `prospecting_listings_matched_property_id_index` (`matched_property_id`),
  KEY `idx_prospecting_listings_tracked` (`tracked_property_id`),
  KEY `idx_prospecting_listings_geo` (`latitude`,`longitude`),
  CONSTRAINT `prospecting_listings_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `prospecting_listings_captured_by_user_id_foreign` FOREIGN KEY (`captured_by_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `prospecting_listings_matched_property_id_foreign` FOREIGN KEY (`matched_property_id`) REFERENCES `properties` (`id`) ON DELETE SET NULL,
  CONSTRAINT `prospecting_listings_tracked_property_id_foreign` FOREIGN KEY (`tracked_property_id`) REFERENCES `tracked_properties` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `prospecting_pitch_locks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `prospecting_pitch_locks` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint(20) unsigned NOT NULL,
  `prospecting_listing_id` bigint(20) unsigned DEFAULT NULL,
  `tracked_property_id` bigint(20) unsigned DEFAULT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `locked_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `expires_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `released_at` timestamp NULL DEFAULT NULL,
  `release_reason` varchar(50) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `prospecting_pitch_locks_user_id_foreign` (`user_id`),
  KEY `idx_pitch_locks_active` (`prospecting_listing_id`,`released_at`,`expires_at`),
  KEY `idx_pitch_locks_agency_user` (`agency_id`,`user_id`),
  KEY `idx_pitch_locks_expires` (`expires_at`),
  KEY `idx_pitch_locks_tp_active` (`tracked_property_id`,`released_at`,`expires_at`),
  CONSTRAINT `prospecting_pitch_locks_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `prospecting_pitch_locks_prospecting_listing_id_foreign` FOREIGN KEY (`prospecting_listing_id`) REFERENCES `prospecting_listings` (`id`) ON DELETE CASCADE,
  CONSTRAINT `prospecting_pitch_locks_tracked_property_id_foreign` FOREIGN KEY (`tracked_property_id`) REFERENCES `tracked_properties` (`id`) ON DELETE CASCADE,
  CONSTRAINT `prospecting_pitch_locks_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `prospecting_price_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `prospecting_price_history` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `prospecting_listing_id` bigint(20) unsigned NOT NULL,
  `old_price` int(11) NOT NULL,
  `new_price` int(11) NOT NULL,
  `changed_at` datetime NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `prospecting_price_history_prospecting_listing_id_index` (`prospecting_listing_id`),
  CONSTRAINT `prospecting_price_history_prospecting_listing_id_foreign` FOREIGN KEY (`prospecting_listing_id`) REFERENCES `prospecting_listings` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `prospecting_searches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `prospecting_searches` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `portal_source` enum('p24','pp') NOT NULL,
  `search_url` text NOT NULL,
  `search_description` varchar(255) NOT NULL,
  `total_results` int(11) NOT NULL,
  `pages_captured` int(11) NOT NULL,
  `listing_count` int(11) NOT NULL,
  `captured_at` datetime NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `prospecting_searches_agency_id_index` (`agency_id`),
  KEY `prospecting_searches_user_id_index` (`user_id`),
  CONSTRAINT `prospecting_searches_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `prospecting_searches_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `public_holidays`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `public_holidays` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `country_code` varchar(2) NOT NULL DEFAULT 'ZA',
  `holiday_date` date NOT NULL,
  `name` varchar(100) NOT NULL,
  `is_movable` tinyint(1) NOT NULL DEFAULT 0,
  `applies_to_year` smallint(5) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `public_holidays_country_code_holiday_date_unique` (`country_code`,`holiday_date`),
  KEY `public_holidays_country_code_applies_to_year_index` (`country_code`,`applies_to_year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `rcr_answer_evidence`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `rcr_answer_evidence` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `answer_id` bigint(20) unsigned NOT NULL,
  `evidence_type` enum('document_upload','corex_record_reference','external_url','note') NOT NULL,
  `document_path` varchar(500) DEFAULT NULL,
  `corex_record_table` varchar(100) DEFAULT NULL,
  `corex_record_id` bigint(20) unsigned DEFAULT NULL,
  `external_url` varchar(500) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `added_by_user_id` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `rae_adder_fk` (`added_by_user_id`),
  KEY `rae_answer_type_idx` (`answer_id`,`evidence_type`),
  CONSTRAINT `rae_adder_fk` FOREIGN KEY (`added_by_user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `rae_answer_fk` FOREIGN KEY (`answer_id`) REFERENCES `rcr_answers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `rcr_answers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `rcr_answers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `submission_id` bigint(20) unsigned NOT NULL,
  `question_id` bigint(20) unsigned NOT NULL,
  `period_code` varchar(16) NOT NULL DEFAULT 'static',
  `answer_value` text DEFAULT NULL,
  `answer_data_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`answer_data_json`)),
  `is_auto_populated` tinyint(1) NOT NULL DEFAULT 0,
  `auto_population_source_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`auto_population_source_data`)),
  `manually_edited` tinyint(1) NOT NULL DEFAULT 0,
  `last_edited_at` timestamp NULL DEFAULT NULL,
  `last_edited_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('unanswered','auto_filled','in_progress','answered','reviewed','approved') NOT NULL DEFAULT 'unanswered',
  `reviewer_user_id` bigint(20) unsigned DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `copied_to_clipboard_at` timestamp NULL DEFAULT NULL,
  `copied_to_clipboard_count` int(10) unsigned NOT NULL DEFAULT 0,
  `transposed_to_goaml_at` timestamp NULL DEFAULT NULL,
  `final_answer_format` varchar(32) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `rca_sub_quest_period_uq` (`submission_id`,`question_id`,`period_code`),
  KEY `rca_quest_fk` (`question_id`),
  KEY `rca_editor_fk` (`last_edited_by_user_id`),
  KEY `rca_reviewer_fk` (`reviewer_user_id`),
  KEY `rca_sub_status_idx` (`submission_id`,`status`),
  KEY `rca_period_idx` (`period_code`),
  CONSTRAINT `rca_editor_fk` FOREIGN KEY (`last_edited_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `rca_quest_fk` FOREIGN KEY (`question_id`) REFERENCES `rcr_questions` (`id`),
  CONSTRAINT `rca_reviewer_fk` FOREIGN KEY (`reviewer_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `rca_sub_fk` FOREIGN KEY (`submission_id`) REFERENCES `rcr_submissions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `rcr_questionnaire_sections`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `rcr_questionnaire_sections` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `questionnaire_id` bigint(20) unsigned NOT NULL,
  `section_code` varchar(20) NOT NULL,
  `title` varchar(300) NOT NULL,
  `description` text DEFAULT NULL,
  `sort_order` int(10) unsigned NOT NULL DEFAULT 0,
  `has_period_columns` tinyint(1) NOT NULL DEFAULT 1,
  `applies_when_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`applies_when_json`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `rqs_quest_code_uq` (`questionnaire_id`,`section_code`),
  KEY `rqs_quest_sort_idx` (`questionnaire_id`,`sort_order`),
  CONSTRAINT `rqs_quest_fk` FOREIGN KEY (`questionnaire_id`) REFERENCES `rcr_questionnaires` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `rcr_questionnaires`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `rcr_questionnaires` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(100) NOT NULL,
  `title` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `issued_by` varchar(100) NOT NULL DEFAULT 'FIC',
  `directive_reference` varchar(100) DEFAULT NULL,
  `reporting_period_from` date NOT NULL,
  `reporting_period_to` date NOT NULL,
  `submission_deadline` date NOT NULL,
  `submission_platform` varchar(100) NOT NULL DEFAULT 'FIC goAML',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `rcr_questionnaires_key_unique` (`key`),
  KEY `rcr_q_active_deadline_idx` (`is_active`,`submission_deadline`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `rcr_questions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `rcr_questions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `questionnaire_id` bigint(20) unsigned NOT NULL,
  `section_id` bigint(20) unsigned NOT NULL,
  `question_code` varchar(30) NOT NULL,
  `parent_code` varchar(30) DEFAULT NULL,
  `question_text` text NOT NULL,
  `footnote` text DEFAULT NULL,
  `answer_type` enum('yes_no','yes_no_na','free_text','number','percentage','multi_select','single_select','file_upload','composite') NOT NULL DEFAULT 'free_text',
  `answer_options_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`answer_options_json`)),
  `is_required` tinyint(1) NOT NULL DEFAULT 1,
  `auto_population_source` varchar(100) DEFAULT NULL,
  `evidence_source_codes_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`evidence_source_codes_json`)),
  `auto_populate_hint` text DEFAULT NULL,
  `help_text` text DEFAULT NULL,
  `sort_order` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `rcq_quest_code_uq` (`questionnaire_id`,`question_code`),
  KEY `rcq_section_fk` (`section_id`),
  KEY `rcq_quest_sec_sort_idx` (`questionnaire_id`,`section_id`,`sort_order`),
  KEY `rcq_autopop_idx` (`auto_population_source`),
  KEY `rcq_parent_code_idx` (`parent_code`),
  CONSTRAINT `rcq_quest_fk` FOREIGN KEY (`questionnaire_id`) REFERENCES `rcr_questionnaires` (`id`) ON DELETE CASCADE,
  CONSTRAINT `rcq_section_fk` FOREIGN KEY (`section_id`) REFERENCES `rcr_questionnaire_sections` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `rcr_submission_snapshots`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `rcr_submission_snapshots` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `submission_id` bigint(20) unsigned NOT NULL,
  `snapshot_json` longtext NOT NULL,
  `questionnaire_version_hash` varchar(64) NOT NULL,
  `taken_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `taken_by_user_id` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `rss_taker_fk` (`taken_by_user_id`),
  KEY `rss_sub_idx` (`submission_id`),
  CONSTRAINT `rss_sub_fk` FOREIGN KEY (`submission_id`) REFERENCES `rcr_submissions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `rss_taker_fk` FOREIGN KEY (`taken_by_user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `rcr_submissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `rcr_submissions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint(20) unsigned NOT NULL,
  `questionnaire_id` bigint(20) unsigned NOT NULL,
  `status` enum('draft','in_review','approved_for_submission','submitted','locked') NOT NULL DEFAULT 'draft',
  `reporting_period_from` date NOT NULL,
  `reporting_period_to` date NOT NULL,
  `submission_deadline` date NOT NULL,
  `submitted_at` timestamp NULL DEFAULT NULL,
  `submitted_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `submitted_to_platform_reference` varchar(200) DEFAULT NULL,
  `locked_at` timestamp NULL DEFAULT NULL,
  `transposed_to_goaml_at` timestamp NULL DEFAULT NULL,
  `export_document_path` varchar(500) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `assigned_co_user_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `rcs_agency_quest_period_uq` (`agency_id`,`questionnaire_id`,`reporting_period_from`),
  KEY `rcs_quest_fk` (`questionnaire_id`),
  KEY `rcs_submitter_fk` (`submitted_by_user_id`),
  KEY `rcs_assigned_fk` (`assigned_co_user_id`),
  KEY `rcs_agency_status_idx` (`agency_id`,`status`),
  KEY `rcs_deadline_status_idx` (`submission_deadline`,`status`),
  CONSTRAINT `rcs_agency_fk` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `rcs_assigned_fk` FOREIGN KEY (`assigned_co_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `rcs_quest_fk` FOREIGN KEY (`questionnaire_id`) REFERENCES `rcr_questionnaires` (`id`),
  CONSTRAINT `rcs_submitter_fk` FOREIGN KEY (`submitted_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `rental_agents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `rental_agents` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `rental_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `rental_agents_rental_id_user_id_unique` (`rental_id`,`user_id`),
  KEY `rental_agents_user_id_index` (`user_id`),
  CONSTRAINT `rental_agents_rental_id_foreign` FOREIGN KEY (`rental_id`) REFERENCES `rentals` (`id`) ON DELETE CASCADE,
  CONSTRAINT `rental_agents_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `rental_amount_versions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `rental_amount_versions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `rental_id` bigint(20) unsigned NOT NULL,
  `effective_from` date NOT NULL,
  `rent_incl` decimal(12,2) NOT NULL,
  `rent_excl` decimal(12,2) NOT NULL,
  `commission_incl` decimal(12,2) NOT NULL,
  `commission_excl` decimal(12,2) NOT NULL,
  `created_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `rental_amount_versions_created_by_user_id_foreign` (`created_by_user_id`),
  KEY `rental_amount_versions_rental_id_effective_from_index` (`rental_id`,`effective_from`),
  CONSTRAINT `rental_amount_versions_created_by_user_id_foreign` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `rental_amount_versions_rental_id_foreign` FOREIGN KEY (`rental_id`) REFERENCES `rentals` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `rental_document_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `rental_document_types` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `slug` varchar(255) NOT NULL,
  `color` varchar(255) DEFAULT '#6B7280',
  `is_system` tinyint(1) NOT NULL DEFAULT 0,
  `is_lease` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `rental_document_types_slug_unique` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `rental_properties`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `rental_properties` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `address_line_1` varchar(255) NOT NULL,
  `address_line_2` varchar(255) DEFAULT NULL,
  `suburb` varchar(255) DEFAULT NULL,
  `city` varchar(255) DEFAULT NULL,
  `postal_code` varchar(255) DEFAULT NULL,
  `province` varchar(255) DEFAULT 'KwaZulu-Natal',
  `full_address` varchar(255) DEFAULT NULL,
  `property_type` varchar(255) DEFAULT NULL,
  `landlord_name` varchar(255) DEFAULT NULL,
  `landlord_email` varchar(255) DEFAULT NULL,
  `landlord_phone` varchar(255) DEFAULT NULL,
  `monthly_rental` decimal(10,2) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `rental_reminder_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `rental_reminder_settings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `mode` varchar(20) NOT NULL DEFAULT 'escalating',
  `gentle_after_days` tinyint(3) unsigned NOT NULL DEFAULT 2,
  `firm_after_days` tinyint(3) unsigned NOT NULL DEFAULT 5,
  `team_alert_after_days` tinyint(3) unsigned NOT NULL DEFAULT 7,
  `final_after_days` tinyint(3) unsigned NOT NULL DEFAULT 10,
  `max_escalating_reminders` tinyint(3) unsigned NOT NULL DEFAULT 3,
  `interval_days` tinyint(3) unsigned NOT NULL DEFAULT 3,
  `max_simple_reminders` tinyint(3) unsigned NOT NULL DEFAULT 5,
  `email_subject` text DEFAULT NULL,
  `email_body` text DEFAULT NULL,
  `updated_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `rental_reminder_settings_updated_by_foreign` (`updated_by`),
  CONSTRAINT `rental_reminder_settings_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `rentals`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `rentals` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `branch_id` bigint(20) unsigned NOT NULL,
  `lease_address` text NOT NULL,
  `lease_start_date` date NOT NULL,
  `lease_end_date` date DEFAULT NULL,
  `is_month_to_month` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `is_rental_assist` tinyint(1) NOT NULL DEFAULT 0,
  `created_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `rentals_created_by_user_id_foreign` (`created_by_user_id`),
  KEY `rentals_branch_id_is_active_index` (`branch_id`,`is_active`),
  KEY `rentals_lease_start_date_lease_end_date_index` (`lease_start_date`,`lease_end_date`),
  CONSTRAINT `rentals_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE,
  CONSTRAINT `rentals_created_by_user_id_foreign` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `revenue_share_ledger`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `revenue_share_ledger` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `commission_ledger_id` bigint(20) unsigned NOT NULL,
  `agency_id` bigint(20) unsigned NOT NULL,
  `producing_agent_id` bigint(20) unsigned NOT NULL,
  `receiving_agent_id` bigint(20) unsigned NOT NULL,
  `tier` int(11) NOT NULL,
  `company_dollar` decimal(12,2) NOT NULL,
  `share_percent` decimal(5,2) NOT NULL,
  `share_amount` decimal(10,2) NOT NULL,
  `status` enum('calculated','confirmed','paid') NOT NULL DEFAULT 'calculated',
  `period_month` date NOT NULL,
  `paid_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `revenue_share_ledger_receiving_agent_id_period_month_index` (`receiving_agent_id`,`period_month`),
  KEY `revenue_share_ledger_producing_agent_id_index` (`producing_agent_id`),
  KEY `revenue_share_ledger_commission_ledger_id_foreign` (`commission_ledger_id`),
  KEY `revenue_share_ledger_agency_id_idx` (`agency_id`),
  CONSTRAINT `revenue_share_ledger_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `revenue_share_ledger_commission_ledger_id_foreign` FOREIGN KEY (`commission_ledger_id`) REFERENCES `commission_ledger` (`id`) ON DELETE CASCADE,
  CONSTRAINT `revenue_share_ledger_producing_agent_id_foreign` FOREIGN KEY (`producing_agent_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `revenue_share_ledger_receiving_agent_id_foreign` FOREIGN KEY (`receiving_agent_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `rmcp_acknowledgements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `rmcp_acknowledgements` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint(20) unsigned NOT NULL,
  `branch_id` bigint(20) unsigned DEFAULT NULL,
  `rmcp_version_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `status` enum('in_progress','completed','expired','superseded') NOT NULL DEFAULT 'in_progress',
  `started_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `completed_at` timestamp NULL DEFAULT NULL,
  `valid_until` date DEFAULT NULL,
  `signature_path` varchar(500) DEFAULT NULL,
  `signature_type` varchar(50) DEFAULT NULL,
  `typed_signature_name` varchar(200) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(500) DEFAULT NULL,
  `device_fingerprint` varchar(100) DEFAULT NULL,
  `declaration_text` text DEFAULT NULL,
  `sections_acknowledged_count` int(10) unsigned NOT NULL DEFAULT 0,
  `sections_total_count` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `rmcp_acknowledgements_rmcp_version_id_status_index` (`rmcp_version_id`,`status`),
  KEY `rmcp_acknowledgements_user_id_status_index` (`user_id`,`status`),
  KEY `rmcp_acknowledgements_agency_id_status_index` (`agency_id`,`status`),
  KEY `rmcp_acknowledgements_valid_until_index` (`valid_until`),
  KEY `rmcp_acknowledgements_branch_id_foreign` (`branch_id`),
  KEY `rmcp_acknowledgements_agency_branch_idx` (`agency_id`,`branch_id`),
  CONSTRAINT `rmcp_acknowledgements_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `rmcp_acknowledgements_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `rmcp_acknowledgements_rmcp_version_id_foreign` FOREIGN KEY (`rmcp_version_id`) REFERENCES `rmcp_versions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `rmcp_acknowledgements_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `rmcp_compliance_officers_deprecated_20260421`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `rmcp_compliance_officers_deprecated_20260421` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `full_name` varchar(200) NOT NULL,
  `id_number` varchar(20) DEFAULT NULL,
  `cell` varchar(50) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `title` varchar(100) NOT NULL DEFAULT 'FICA Compliance Officer',
  `appointed_on` date NOT NULL,
  `ended_on` date DEFAULT NULL,
  `appointed_by` bigint(20) unsigned DEFAULT NULL,
  `appointment_notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `rmcp_compliance_officers_user_id_foreign` (`user_id`),
  KEY `rmcp_compliance_officers_appointed_by_foreign` (`appointed_by`),
  KEY `rmcp_compliance_officers_agency_id_ended_on_index` (`agency_id`,`ended_on`),
  CONSTRAINT `rmcp_compliance_officers_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `rmcp_compliance_officers_appointed_by_foreign` FOREIGN KEY (`appointed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `rmcp_compliance_officers_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `rmcp_section_acknowledgements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `rmcp_section_acknowledgements` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `rmcp_acknowledgement_id` bigint(20) unsigned NOT NULL,
  `agency_id` bigint(20) unsigned NOT NULL,
  `rmcp_section_id` bigint(20) unsigned NOT NULL,
  `acknowledged` tinyint(1) NOT NULL DEFAULT 0,
  `acknowledged_at` timestamp NULL DEFAULT NULL,
  `acknowledgement_response` varchar(100) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `rmcp_sec_ack_unique` (`rmcp_acknowledgement_id`,`rmcp_section_id`),
  KEY `rmcp_section_acknowledgements_rmcp_section_id_foreign` (`rmcp_section_id`),
  KEY `rmcp_section_acknowledgements_agency_id_idx` (`agency_id`),
  CONSTRAINT `rmcp_section_acknowledgements_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `rmcp_section_acknowledgements_rmcp_acknowledgement_id_foreign` FOREIGN KEY (`rmcp_acknowledgement_id`) REFERENCES `rmcp_acknowledgements` (`id`) ON DELETE CASCADE,
  CONSTRAINT `rmcp_section_acknowledgements_rmcp_section_id_foreign` FOREIGN KEY (`rmcp_section_id`) REFERENCES `rmcp_sections` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `rmcp_sections`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `rmcp_sections` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `rmcp_version_id` bigint(20) unsigned NOT NULL,
  `agency_id` bigint(20) unsigned NOT NULL,
  `section_type` enum('section','schedule','annexure','acknowledgement') NOT NULL DEFAULT 'section',
  `display_order` int(10) unsigned NOT NULL,
  `section_number` varchar(20) NOT NULL,
  `title` varchar(500) NOT NULL,
  `body_html` longtext NOT NULL,
  `requires_acknowledgement` tinyint(1) NOT NULL DEFAULT 1,
  `acknowledgement_prompt` varchar(500) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `rmcp_sections_rmcp_version_id_display_order_index` (`rmcp_version_id`,`display_order`),
  KEY `rmcp_sections_agency_id_idx` (`agency_id`),
  CONSTRAINT `rmcp_sections_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `rmcp_sections_rmcp_version_id_foreign` FOREIGN KEY (`rmcp_version_id`) REFERENCES `rmcp_versions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `rmcp_variables`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `rmcp_variables` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint(20) unsigned NOT NULL,
  `variable_key` varchar(100) NOT NULL,
  `value` text DEFAULT NULL,
  `data_source` varchar(50) NOT NULL DEFAULT 'manual',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `rmcp_variables_agency_id_variable_key_unique` (`agency_id`,`variable_key`),
  CONSTRAINT `rmcp_variables_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `rmcp_versions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `rmcp_versions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint(20) unsigned NOT NULL,
  `version_number` int(10) unsigned NOT NULL,
  `title` varchar(255) NOT NULL DEFAULT 'Risk Management and Compliance Programme',
  `status` enum('draft','active','superseded') NOT NULL DEFAULT 'draft',
  `approved_by` bigint(20) unsigned DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `approver_title` varchar(100) DEFAULT NULL,
  `board_approval_document_path` varchar(500) DEFAULT NULL,
  `approval_ip` varchar(45) DEFAULT NULL,
  `approval_notes` text DEFAULT NULL,
  `effective_from` date DEFAULT NULL,
  `superseded_at` timestamp NULL DEFAULT NULL,
  `superseded_by_version_id` bigint(20) unsigned DEFAULT NULL,
  `next_review_due` date DEFAULT NULL,
  `change_notes` text DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `rmcp_versions_agency_id_version_number_unique` (`agency_id`,`version_number`),
  KEY `rmcp_versions_approved_by_foreign` (`approved_by`),
  KEY `rmcp_versions_created_by_foreign` (`created_by`),
  KEY `rmcp_versions_agency_id_status_index` (`agency_id`,`status`),
  CONSTRAINT `rmcp_versions_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `rmcp_versions_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `rmcp_versions_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `role_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `role_permissions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `role` varchar(255) NOT NULL,
  `permission_key` varchar(255) NOT NULL,
  `scope` varchar(10) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `role_permissions_role_permission_key_unique` (`role`,`permission_key`),
  KEY `role_permissions_role_index` (`role`),
  KEY `role_permissions_permission_key_index` (`permission_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `roles` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `label` varchar(255) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `color` varchar(20) NOT NULL DEFAULT '#0d9488',
  `is_owner` tinyint(1) NOT NULL DEFAULT 0,
  `can_be_deleted` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `agency_id` bigint(20) unsigned DEFAULT NULL,
  `oversight_scope` enum('branch','agency') DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `roles_name_unique` (`name`),
  KEY `roles_agency_id_foreign` (`agency_id`),
  CONSTRAINT `roles_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sale_probability_runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sale_probability_runs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `market_analytics_run_id` bigint(20) unsigned NOT NULL,
  `market_analytics_model_version` varchar(32) NOT NULL,
  `market_analytics_inputs_hash` varchar(64) NOT NULL,
  `model_version` varchar(32) NOT NULL COMMENT 'e.g. prob-v1.0.0',
  `inputs_hash` varchar(64) NOT NULL COMMENT 'SHA-256 of canonical inputs JSON',
  `inputs_json` text NOT NULL COMMENT 'Canonical serialised input parameters',
  `outputs_json` text NOT NULL COMMENT 'Flat probabilities + expected_days',
  `breakdown_json` text NOT NULL COMMENT 'Signals, weights, composite score',
  `data_sources_json` text NOT NULL COMMENT 'market_analytics reference + future sources',
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `agency_id` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `sale_probability_runs_created_by_foreign` (`created_by`),
  KEY `spr_version_hash_idx` (`model_version`,`inputs_hash`),
  KEY `spr_ma_run_idx` (`market_analytics_run_id`),
  KEY `sale_probability_runs_agency_id_idx` (`agency_id`),
  CONSTRAINT `sale_probability_runs_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `sale_probability_runs_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `sale_probability_runs_market_analytics_run_id_foreign` FOREIGN KEY (`market_analytics_run_id`) REFERENCES `market_analytics_runs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sales_document_recipients`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sales_document_recipients` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `sales_document_send_id` bigint(20) unsigned NOT NULL,
  `signing_order` int(11) NOT NULL,
  `recipient_name` varchar(255) NOT NULL,
  `recipient_email` varchar(255) NOT NULL,
  `recipient_role` varchar(255) NOT NULL DEFAULT 'client',
  `id_number` varchar(20) DEFAULT NULL,
  `token` varchar(64) NOT NULL,
  `token_expires_at` timestamp NULL DEFAULT NULL,
  `status` enum('waiting','sent','downloaded','returned_pending_approval','approved','expired') NOT NULL DEFAULT 'waiting',
  `sent_at` timestamp NULL DEFAULT NULL,
  `downloaded_at` timestamp NULL DEFAULT NULL,
  `returned_at` timestamp NULL DEFAULT NULL,
  `returned_file_path` varchar(255) DEFAULT NULL,
  `return_method` enum('upload','email') DEFAULT NULL,
  `reminder_count` int(11) NOT NULL DEFAULT 0,
  `last_reminder_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `sales_document_recipients_token_unique` (`token`),
  KEY `sdr_send_id_signing_order_idx` (`sales_document_send_id`,`signing_order`),
  KEY `sales_document_recipients_status_index` (`status`),
  CONSTRAINT `sales_document_recipients_sales_document_send_id_foreign` FOREIGN KEY (`sales_document_send_id`) REFERENCES `sales_document_sends` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sales_document_sends`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sales_document_sends` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `document_id` bigint(20) unsigned DEFAULT NULL,
  `document_name` varchar(255) NOT NULL,
  `original_file_path` varchar(255) DEFAULT NULL,
  `sent_by` bigint(20) unsigned NOT NULL,
  `message` text DEFAULT NULL,
  `status` enum('in_progress','completed','expired') NOT NULL DEFAULT 'in_progress',
  `completed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `sales_document_sends_status_index` (`status`),
  KEY `sales_document_sends_sent_by_index` (`sent_by`),
  CONSTRAINT `sales_document_sends_sent_by_foreign` FOREIGN KEY (`sent_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `scheme_owners`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `scheme_owners` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint(20) unsigned NOT NULL,
  `market_report_id` bigint(20) unsigned NOT NULL,
  `scheme_name` varchar(255) NOT NULL,
  `scheme_ss_number` varchar(32) DEFAULT NULL,
  `section_number` varchar(32) DEFAULT NULL,
  `flat_number` varchar(32) DEFAULT NULL,
  `owner_name` varchar(255) NOT NULL,
  `extent_m2` int(10) unsigned DEFAULT NULL,
  `property_type` varchar(64) DEFAULT NULL,
  `latitude` decimal(10,7) DEFAULT NULL COMMENT 'Populated later via cross-link to scheme GPS.',
  `longitude` decimal(10,7) DEFAULT NULL,
  `contact_id` bigint(20) unsigned DEFAULT NULL COMMENT 'Set when the owner is matched to a CoreX Contact (Phase later).',
  `matched_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `is_demo` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_scheme_owners_agency_scheme_section_owner` (`agency_id`,`scheme_name`,`section_number`,`owner_name`),
  KEY `scheme_owners_market_report_id_foreign` (`market_report_id`),
  KEY `idx_scheme_owners_scheme` (`scheme_name`),
  KEY `idx_scheme_owners_owner` (`owner_name`),
  KEY `idx_scheme_owners_is_demo` (`is_demo`),
  CONSTRAINT `scheme_owners_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`),
  CONSTRAINT `scheme_owners_market_report_id_foreign` FOREIGN KEY (`market_report_id`) REFERENCES `market_reports` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `section_acceptances`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `section_acceptances` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `signature_request_id` bigint(20) unsigned NOT NULL,
  `section_index` int(10) unsigned NOT NULL,
  `section_label` varchar(255) NOT NULL,
  `accepted` tinyint(1) NOT NULL DEFAULT 0,
  `rejected` tinyint(1) NOT NULL DEFAULT 0,
  `rejection_reason` text DEFAULT NULL,
  `initialled_at` timestamp NULL DEFAULT NULL,
  `initial_image` longtext DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `section_accept_req_idx_unique` (`signature_request_id`,`section_index`),
  CONSTRAINT `section_acceptances_signature_request_id_foreign` FOREIGN KEY (`signature_request_id`) REFERENCES `signature_requests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `seller_info_share_links`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `seller_info_share_links` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tier` enum('tier_1','tier_2','tier_3') NOT NULL,
  `seller_name` varchar(255) DEFAULT NULL,
  `seller_email` varchar(255) DEFAULT NULL,
  `agent_message` text DEFAULT NULL,
  `property_id` bigint(20) unsigned DEFAULT NULL,
  `contact_id` bigint(20) unsigned DEFAULT NULL,
  `sent_by_user_id` bigint(20) unsigned NOT NULL,
  `agency_id` bigint(20) unsigned NOT NULL,
  `token` varchar(64) NOT NULL,
  `expires_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `accessed_count` int(10) unsigned NOT NULL DEFAULT 0,
  `last_accessed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `seller_info_share_links_token_unique` (`token`),
  KEY `seller_info_share_links_property_id_foreign` (`property_id`),
  KEY `seller_info_share_links_contact_id_foreign` (`contact_id`),
  KEY `seller_info_share_links_sent_by_user_id_foreign` (`sent_by_user_id`),
  KEY `seller_info_share_links_agency_id_foreign` (`agency_id`),
  CONSTRAINT `seller_info_share_links_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`),
  CONSTRAINT `seller_info_share_links_contact_id_foreign` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`),
  CONSTRAINT `seller_info_share_links_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`),
  CONSTRAINT `seller_info_share_links_sent_by_user_id_foreign` FOREIGN KEY (`sent_by_user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `seller_mandate_lost_records`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `seller_mandate_lost_records` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `property_id` bigint(20) unsigned NOT NULL,
  `agency_id` bigint(20) unsigned NOT NULL,
  `mandate_type` varchar(30) DEFAULT NULL,
  `reason_code` varchar(50) NOT NULL,
  `reason_label` varchar(150) NOT NULL,
  `notes` text DEFAULT NULL,
  `recorded_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `recorded_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `source` varchar(30) NOT NULL DEFAULT 'manual',
  `listing_value_at_loss` decimal(14,2) DEFAULT NULL,
  `days_listed_at_loss` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `seller_mandate_lost_records_property_id_foreign` (`property_id`),
  KEY `seller_mandate_lost_records_recorded_by_user_id_foreign` (`recorded_by_user_id`),
  KEY `seller_mandate_lost_records_agency_id_recorded_at_index` (`agency_id`,`recorded_at`),
  CONSTRAINT `seller_mandate_lost_records_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `seller_mandate_lost_records_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE,
  CONSTRAINT `seller_mandate_lost_records_recorded_by_user_id_foreign` FOREIGN KEY (`recorded_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `seller_outreach_callbacks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `seller_outreach_callbacks` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint(20) unsigned NOT NULL,
  `send_id` bigint(20) unsigned NOT NULL,
  `contact_id` bigint(20) unsigned NOT NULL,
  `requester_name` varchar(150) DEFAULT NULL,
  `requester_phone` varchar(30) DEFAULT NULL,
  `requester_email` varchar(255) DEFAULT NULL,
  `preferred_time` varchar(100) DEFAULT NULL,
  `message` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'pending',
  `handled_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `handled_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `seller_outreach_callbacks_contact_id_foreign` (`contact_id`),
  KEY `seller_outreach_callbacks_handled_by_user_id_foreign` (`handled_by_user_id`),
  KEY `outreach_cb_agency_status_idx` (`agency_id`,`status`,`created_at`),
  KEY `outreach_cb_send_idx` (`send_id`),
  CONSTRAINT `seller_outreach_callbacks_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `seller_outreach_callbacks_contact_id_foreign` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `seller_outreach_callbacks_handled_by_user_id_foreign` FOREIGN KEY (`handled_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `seller_outreach_callbacks_send_id_foreign` FOREIGN KEY (`send_id`) REFERENCES `seller_outreach_sends` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `seller_outreach_clicks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `seller_outreach_clicks` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint(20) unsigned NOT NULL,
  `send_id` bigint(20) unsigned NOT NULL,
  `clicked_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(500) DEFAULT NULL,
  `geo_country` char(2) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `outreach_click_agency_send_idx` (`agency_id`,`send_id`,`clicked_at`),
  KEY `outreach_click_send_idx` (`send_id`,`clicked_at`),
  CONSTRAINT `seller_outreach_clicks_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `seller_outreach_clicks_send_id_foreign` FOREIGN KEY (`send_id`) REFERENCES `seller_outreach_sends` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `seller_outreach_sends`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `seller_outreach_sends` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint(20) unsigned NOT NULL,
  `contact_id` bigint(20) unsigned NOT NULL,
  `property_id` bigint(20) unsigned NOT NULL,
  `agent_id` bigint(20) unsigned DEFAULT NULL,
  `template_id` bigint(20) unsigned DEFAULT NULL,
  `channel` enum('whatsapp','email') NOT NULL,
  `subject_snapshot` varchar(255) DEFAULT NULL,
  `body_snapshot` text NOT NULL,
  `facts_snapshot` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`facts_snapshot`)),
  `tracking_short_code` char(6) NOT NULL,
  `recipient_phone_snapshot` varchar(30) DEFAULT NULL,
  `recipient_email_snapshot` varchar(255) DEFAULT NULL,
  `sent_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `first_clicked_at` timestamp NULL DEFAULT NULL,
  `outcome` enum('sent','clicked','replied','booked','no_response','not_interested','bounced') NOT NULL DEFAULT 'sent',
  `outcome_note` text DEFAULT NULL,
  `outcome_set_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `outcome_set_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `outreach_send_agency_code_unique` (`agency_id`,`tracking_short_code`),
  KEY `seller_outreach_sends_contact_id_foreign` (`contact_id`),
  KEY `seller_outreach_sends_property_id_foreign` (`property_id`),
  KEY `seller_outreach_sends_agent_id_foreign` (`agent_id`),
  KEY `seller_outreach_sends_template_id_foreign` (`template_id`),
  KEY `seller_outreach_sends_outcome_set_by_user_id_foreign` (`outcome_set_by_user_id`),
  KEY `outreach_send_contact_idx` (`agency_id`,`contact_id`,`sent_at`),
  KEY `outreach_send_property_idx` (`agency_id`,`property_id`,`sent_at`),
  KEY `outreach_send_agent_idx` (`agency_id`,`agent_id`,`sent_at`),
  KEY `outreach_send_outcome_idx` (`agency_id`,`outcome`),
  KEY `outreach_send_code_idx` (`tracking_short_code`),
  KEY `outreach_send_deleted_idx` (`deleted_at`),
  CONSTRAINT `seller_outreach_sends_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `seller_outreach_sends_agent_id_foreign` FOREIGN KEY (`agent_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `seller_outreach_sends_contact_id_foreign` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `seller_outreach_sends_outcome_set_by_user_id_foreign` FOREIGN KEY (`outcome_set_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `seller_outreach_sends_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE,
  CONSTRAINT `seller_outreach_sends_template_id_foreign` FOREIGN KEY (`template_id`) REFERENCES `seller_outreach_templates` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `seller_outreach_templates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `seller_outreach_templates` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint(20) unsigned NOT NULL,
  `name` varchar(150) NOT NULL,
  `channel` enum('whatsapp','email') NOT NULL,
  `subject` varchar(255) DEFAULT NULL,
  `body` text NOT NULL,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `is_default_for_channel` tinyint(1) NOT NULL DEFAULT 0,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `outreach_tmpl_agency_chan_active_idx` (`agency_id`,`channel`,`is_active`),
  KEY `outreach_tmpl_agency_default_idx` (`agency_id`,`is_default_for_channel`),
  KEY `outreach_tmpl_deleted_idx` (`deleted_at`),
  CONSTRAINT `seller_outreach_templates_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sessions` (
  `id` varchar(255) NOT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `payload` longtext NOT NULL,
  `last_activity` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `sessions_user_id_index` (`user_id`),
  KEY `sessions_last_activity_index` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sg_search_cache`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sg_search_cache` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `query_hash` varchar(64) NOT NULL,
  `province` varchar(30) NOT NULL,
  `rural_urban` varchar(10) NOT NULL,
  `town` varchar(200) NOT NULL,
  `parcel_number` varchar(50) NOT NULL,
  `portion` varchar(20) NOT NULL,
  `farm_name` varchar(200) DEFAULT NULL,
  `response_body` longtext DEFAULT NULL,
  `parsed_documents_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`parsed_documents_json`)),
  `fetched_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `expires_at` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `sg_search_cache_query_hash_unique` (`query_hash`),
  KEY `sg_search_cache_expires_at_index` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `signature_audit_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `signature_audit_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `signature_template_id` bigint(20) unsigned NOT NULL,
  `signature_request_id` bigint(20) unsigned DEFAULT NULL,
  `action` varchar(255) NOT NULL,
  `actor_type` varchar(255) NOT NULL,
  `actor_id` bigint(20) unsigned DEFAULT NULL,
  `actor_name` varchar(255) NOT NULL,
  `actor_email` varchar(255) DEFAULT NULL,
  `actor_ip_address` varchar(45) DEFAULT NULL,
  `actor_user_agent` text DEFAULT NULL,
  `metadata_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata_json`)),
  `document_hash` varchar(64) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `signature_audit_log_signature_request_id_foreign` (`signature_request_id`),
  KEY `signature_audit_log_signature_template_id_created_at_index` (`signature_template_id`,`created_at`),
  KEY `signature_audit_log_action_index` (`action`),
  CONSTRAINT `signature_audit_log_signature_request_id_foreign` FOREIGN KEY (`signature_request_id`) REFERENCES `signature_requests` (`id`) ON DELETE SET NULL,
  CONSTRAINT `signature_audit_log_signature_template_id_foreign` FOREIGN KEY (`signature_template_id`) REFERENCES `signature_templates` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `signature_markers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `signature_markers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `signature_template_id` bigint(20) unsigned NOT NULL,
  `page_number` int(11) NOT NULL,
  `x_position` decimal(8,4) NOT NULL,
  `y_position` decimal(8,4) NOT NULL,
  `width` decimal(8,4) NOT NULL DEFAULT 20.0000,
  `height` decimal(8,4) NOT NULL DEFAULT 5.0000,
  `type` enum('signature','initial','date','text') NOT NULL DEFAULT 'signature',
  `assigned_party` varchar(255) NOT NULL,
  `assigned_email` varchar(255) DEFAULT NULL,
  `label` varchar(255) DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `required` tinyint(1) NOT NULL DEFAULT 1,
  `from_template_zone_id` bigint(20) unsigned DEFAULT NULL,
  `from_zone_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `signature_markers_signature_template_id_page_number_index` (`signature_template_id`,`page_number`),
  KEY `signature_markers_assigned_party_index` (`assigned_party`),
  KEY `signature_markers_from_template_zone_id_index` (`from_template_zone_id`),
  KEY `signature_markers_from_zone_id_foreign` (`from_zone_id`),
  CONSTRAINT `signature_markers_from_zone_id_foreign` FOREIGN KEY (`from_zone_id`) REFERENCES `signature_zones` (`id`) ON DELETE SET NULL,
  CONSTRAINT `signature_markers_signature_template_id_foreign` FOREIGN KEY (`signature_template_id`) REFERENCES `signature_templates` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `signature_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `signature_requests` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `signature_template_id` bigint(20) unsigned NOT NULL,
  `party_role` varchar(255) NOT NULL,
  `role_index` smallint(5) unsigned NOT NULL DEFAULT 1,
  `signing_order` int(11) NOT NULL DEFAULT 1,
  `signer_name` varchar(255) NOT NULL,
  `signer_email` varchar(255) NOT NULL,
  `signer_id_number` varchar(20) DEFAULT NULL,
  `token` varchar(64) NOT NULL,
  `token_expires_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `status` enum('waiting','pending','viewed','partially_signed','completed','expired','declined','deferred','cancelled') NOT NULL DEFAULT 'waiting',
  `returned_notes` text DEFAULT NULL,
  `authorised_by` bigint(20) unsigned DEFAULT NULL,
  `authorised_at` timestamp NULL DEFAULT NULL,
  `sent_at` timestamp NULL DEFAULT NULL,
  `viewed_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `reminder_sent_at` timestamp NULL DEFAULT NULL,
  `team_alerted_at` timestamp NULL DEFAULT NULL,
  `reminder_count` int(11) NOT NULL DEFAULT 0,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `sent_by` bigint(20) unsigned DEFAULT NULL,
  `message` text DEFAULT NULL,
  `signing_method` enum('electronic','wet_ink') DEFAULT NULL,
  `fica_required` tinyint(1) NOT NULL DEFAULT 0,
  `contact_id` bigint(20) unsigned DEFAULT NULL,
  `fica_submission_id` bigint(20) unsigned DEFAULT NULL,
  `wet_ink_upload_path` varchar(255) DEFAULT NULL,
  `wet_ink_upload_method` varchar(20) DEFAULT NULL,
  `wet_ink_status` enum('pending_upload','uploaded_pending_review','approved','rejected') DEFAULT NULL,
  `wet_ink_rejection_note` text DEFAULT NULL,
  `reviewed_by` bigint(20) unsigned DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `signature_requests_token_unique` (`token`),
  KEY `signature_requests_sent_by_foreign` (`sent_by`),
  KEY `signature_requests_reviewed_by_foreign` (`reviewed_by`),
  KEY `signature_requests_signature_template_id_status_index` (`signature_template_id`,`status`),
  KEY `signature_requests_party_role_index` (`party_role`),
  KEY `signature_requests_status_token_expires_at_index` (`status`,`token_expires_at`),
  KEY `signature_requests_authorised_by_foreign` (`authorised_by`),
  KEY `signature_requests_contact_id_foreign` (`contact_id`),
  KEY `signature_requests_fica_submission_id_foreign` (`fica_submission_id`),
  KEY `sigreq_template_role_index_idx` (`signature_template_id`,`party_role`,`role_index`),
  CONSTRAINT `signature_requests_authorised_by_foreign` FOREIGN KEY (`authorised_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `signature_requests_contact_id_foreign` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `signature_requests_fica_submission_id_foreign` FOREIGN KEY (`fica_submission_id`) REFERENCES `fica_submissions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `signature_requests_reviewed_by_foreign` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `signature_requests_sent_by_foreign` FOREIGN KEY (`sent_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `signature_requests_signature_template_id_foreign` FOREIGN KEY (`signature_template_id`) REFERENCES `signature_templates` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `signature_templates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `signature_templates` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `document_id` bigint(20) unsigned NOT NULL,
  `document_hash` varchar(64) DEFAULT NULL,
  `status` enum('draft','ready','signing','awaiting_tenant','awaiting_landlord','awaiting_buyer','awaiting_seller','awaiting_supervisor','awaiting_supervisor_final','pending_agent_approval','returned_to_candidate','completed','expired','declined','rejected','partial','awaiting_deferred','amendment_review','amendment_initialing','cancelled') NOT NULL DEFAULT 'draft',
  `document_version` int(10) unsigned NOT NULL DEFAULT 1,
  `amendment_status` varchar(255) DEFAULT NULL,
  `parties_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`parties_json`)),
  `signing_order_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`signing_order_json`)),
  `cosign_mode` varchar(20) DEFAULT NULL,
  `supersedes_id` bigint(20) unsigned DEFAULT NULL,
  `superseded_by_id` bigint(20) unsigned DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `is_candidate_flow` tinyint(1) NOT NULL DEFAULT 0,
  `supervisor_user_id` bigint(20) unsigned DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `rejected_at` timestamp NULL DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `cancellation_reason` text DEFAULT NULL,
  `cancelled_by` bigint(20) unsigned DEFAULT NULL,
  `cancelled_at` timestamp NULL DEFAULT NULL,
  `rejected_by` bigint(20) unsigned DEFAULT NULL,
  `signed_pdf_path` varchar(255) DEFAULT NULL,
  `signed_pdf_client_path` varchar(255) DEFAULT NULL,
  `flattened_pages_json` text DEFAULT NULL,
  `sections_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`sections_json`)),
  `other_conditions_text` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `signature_templates_document_id_status_index` (`document_id`,`status`),
  KEY `signature_templates_created_by_index` (`created_by`),
  KEY `signature_templates_supersedes_id_foreign` (`supersedes_id`),
  KEY `signature_templates_superseded_by_id_foreign` (`superseded_by_id`),
  KEY `signature_templates_supervisor_user_id_foreign` (`supervisor_user_id`),
  CONSTRAINT `signature_templates_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `signature_templates_document_id_foreign` FOREIGN KEY (`document_id`) REFERENCES `docuperfect_documents` (`id`) ON DELETE CASCADE,
  CONSTRAINT `signature_templates_superseded_by_id_foreign` FOREIGN KEY (`superseded_by_id`) REFERENCES `signature_templates` (`id`) ON DELETE SET NULL,
  CONSTRAINT `signature_templates_supersedes_id_foreign` FOREIGN KEY (`supersedes_id`) REFERENCES `signature_templates` (`id`) ON DELETE SET NULL,
  CONSTRAINT `signature_templates_supervisor_user_id_foreign` FOREIGN KEY (`supervisor_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `signature_zones`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `signature_zones` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `signature_template_id` bigint(20) unsigned NOT NULL,
  `zone_type` enum('signature','initial','other_conditions') DEFAULT 'signature',
  `party_role` varchar(255) NOT NULL,
  `assigned_parties` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`assigned_parties`)),
  `page_number` int(11) NOT NULL,
  `x_position` decimal(8,4) NOT NULL,
  `y_position` decimal(8,4) NOT NULL,
  `width` decimal(8,4) NOT NULL DEFAULT 25.0000,
  `height` decimal(8,4) NOT NULL DEFAULT 8.0000,
  `is_auto_placed` tinyint(1) NOT NULL DEFAULT 0,
  `source` enum('template','setup') NOT NULL DEFAULT 'setup',
  `label` varchar(255) DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `signature_zones_signature_template_id_page_number_index` (`signature_template_id`,`page_number`),
  KEY `signature_zones_party_role_index` (`party_role`),
  CONSTRAINT `signature_zones_signature_template_id_foreign` FOREIGN KEY (`signature_template_id`) REFERENCES `signature_templates` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `signatures`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `signatures` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `signature_template_id` bigint(20) unsigned NOT NULL,
  `signature_marker_id` bigint(20) unsigned NOT NULL,
  `signature_request_id` bigint(20) unsigned DEFAULT NULL,
  `signer_user_id` bigint(20) unsigned DEFAULT NULL,
  `signer_name` varchar(255) NOT NULL,
  `signer_email` varchar(255) NOT NULL,
  `signer_ip_address` varchar(45) NOT NULL,
  `signer_user_agent` text DEFAULT NULL,
  `signature_data` longtext DEFAULT NULL,
  `text_value` text DEFAULT NULL,
  `signature_type` enum('drawn','typed') NOT NULL DEFAULT 'drawn',
  `signed_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `signatures_signature_marker_id_foreign` (`signature_marker_id`),
  KEY `signatures_signer_user_id_foreign` (`signer_user_id`),
  KEY `signatures_signature_template_id_signed_at_index` (`signature_template_id`,`signed_at`),
  KEY `signatures_signature_request_id_index` (`signature_request_id`),
  CONSTRAINT `signatures_signature_marker_id_foreign` FOREIGN KEY (`signature_marker_id`) REFERENCES `signature_markers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `signatures_signature_request_id_foreign` FOREIGN KEY (`signature_request_id`) REFERENCES `signature_requests` (`id`) ON DELETE SET NULL,
  CONSTRAINT `signatures_signature_template_id_foreign` FOREIGN KEY (`signature_template_id`) REFERENCES `signature_templates` (`id`) ON DELETE CASCADE,
  CONSTRAINT `signatures_signer_user_id_foreign` FOREIGN KEY (`signer_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `signed_document_versions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `signed_document_versions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `document_id` bigint(20) unsigned NOT NULL,
  `signature_request_id` bigint(20) unsigned DEFAULT NULL,
  `version_number` int(11) NOT NULL DEFAULT 1,
  `file_path` varchar(255) NOT NULL,
  `file_type` varchar(10) NOT NULL,
  `uploaded_by_name` varchar(255) DEFAULT NULL,
  `uploaded_at` timestamp NULL DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `agent_approved` tinyint(1) NOT NULL DEFAULT 0,
  `agent_approved_at` timestamp NULL DEFAULT NULL,
  `approved_by` bigint(20) unsigned DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `signed_document_versions_signature_request_id_foreign` (`signature_request_id`),
  KEY `signed_document_versions_approved_by_foreign` (`approved_by`),
  KEY `signed_document_versions_document_id_version_number_index` (`document_id`,`version_number`),
  CONSTRAINT `signed_document_versions_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `signed_document_versions_document_id_foreign` FOREIGN KEY (`document_id`) REFERENCES `docuperfect_documents` (`id`) ON DELETE CASCADE,
  CONSTRAINT `signed_document_versions_signature_request_id_foreign` FOREIGN KEY (`signature_request_id`) REFERENCES `signature_requests` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `staff_take_on_records`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `staff_take_on_records` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint(20) unsigned NOT NULL,
  `branch_id` bigint(20) unsigned DEFAULT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `payroll_employee_id` bigint(20) unsigned DEFAULT NULL,
  `take_on_date` date NOT NULL,
  `previous_employer` text DEFAULT NULL,
  `previous_employment_start_date` date DEFAULT NULL,
  `original_employment_start_date` date NOT NULL,
  `take_on_type` enum('new_hire','migration_from_old_system','transfer_from_other_branch') NOT NULL,
  `personal_details_verified` tinyint(1) NOT NULL DEFAULT 0,
  `banking_details_verified` tinyint(1) NOT NULL DEFAULT 0,
  `tax_details_verified` tinyint(1) NOT NULL DEFAULT 0,
  `employment_terms_verified` tinyint(1) NOT NULL DEFAULT 0,
  `compensation_setup_verified` tinyint(1) NOT NULL DEFAULT 0,
  `leave_balances_captured` tinyint(1) NOT NULL DEFAULT 0,
  `compliance_documents_uploaded` tinyint(1) NOT NULL DEFAULT 0,
  `signed_employment_contract_uploaded` tinyint(1) NOT NULL DEFAULT 0,
  `completed_at` timestamp NULL DEFAULT NULL,
  `completed_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `current_step` enum('user','personal','tax_banking','employment','compensation','leave','compliance','review') DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `staff_take_on_records_branch_id_foreign` (`branch_id`),
  KEY `staff_take_on_records_payroll_employee_id_foreign` (`payroll_employee_id`),
  KEY `staff_take_on_records_completed_by_user_id_foreign` (`completed_by_user_id`),
  KEY `staff_take_on_records_agency_id_completed_at_index` (`agency_id`,`completed_at`),
  KEY `staff_take_on_records_user_id_index` (`user_id`),
  CONSTRAINT `staff_take_on_records_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `staff_take_on_records_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `staff_take_on_records_completed_by_user_id_foreign` FOREIGN KEY (`completed_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `staff_take_on_records_payroll_employee_id_foreign` FOREIGN KEY (`payroll_employee_id`) REFERENCES `payroll_employees` (`id`) ON DELETE SET NULL,
  CONSTRAINT `staff_take_on_records_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `suggested_action_thresholds`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `suggested_action_thresholds` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint(20) unsigned NOT NULL,
  `stale_listing_days` smallint(5) unsigned NOT NULL DEFAULT 14,
  `expiry_warning_hours` smallint(5) unsigned NOT NULL DEFAULT 6,
  `outcome_overdue_days` smallint(5) unsigned NOT NULL DEFAULT 2,
  `outcome_stale_days` smallint(5) unsigned NOT NULL DEFAULT 30,
  `follow_up_days` smallint(5) unsigned NOT NULL DEFAULT 7,
  `pitch_recency_days` smallint(5) unsigned NOT NULL DEFAULT 7,
  `high_value_strong_min` smallint(5) unsigned NOT NULL DEFAULT 3,
  `stock_repitch_days` smallint(5) unsigned NOT NULL DEFAULT 30,
  `colleague_claim_stale_days` smallint(5) unsigned NOT NULL DEFAULT 21,
  `investigate_mid_min` smallint(5) unsigned NOT NULL DEFAULT 5,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unq_suggested_action_thresholds_agency` (`agency_id`),
  CONSTRAINT `suggested_action_thresholds_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `targets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `targets` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `period` varchar(7) NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `agency_id` bigint(20) unsigned NOT NULL,
  `branch_id` bigint(20) unsigned DEFAULT NULL,
  `listings_target` int(11) NOT NULL DEFAULT 0,
  `deals_target` int(11) NOT NULL DEFAULT 0,
  `value_target` decimal(14,2) NOT NULL DEFAULT 0.00,
  `points_target` int(11) NOT NULL DEFAULT 0,
  `notes` text DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `updated_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `targets_period_user_id_unique` (`period`,`user_id`),
  KEY `targets_user_id_foreign` (`user_id`),
  KEY `targets_branch_id_foreign` (`branch_id`),
  KEY `targets_created_by_foreign` (`created_by`),
  KEY `targets_updated_by_foreign` (`updated_by`),
  KEY `targets_agency_id_idx` (`agency_id`),
  CONSTRAINT `targets_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `targets_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `targets_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `targets_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `targets_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tool_history_entries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `tool_history_entries` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `agency_id` bigint(20) unsigned NOT NULL,
  `branch_id` bigint(20) unsigned DEFAULT NULL,
  `type` varchar(255) NOT NULL,
  `ref` varchar(255) NOT NULL,
  `occurred_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `property` varchar(255) NOT NULL,
  `value` decimal(14,2) NOT NULL,
  `agent_name` varchar(255) NOT NULL,
  `payload` text NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tool_history_entries_ref_unique` (`ref`),
  KEY `tool_history_entries_branch_id_foreign` (`branch_id`),
  KEY `tool_history_entries_user_id_occurred_at_index` (`user_id`,`occurred_at`),
  KEY `tool_history_entries_agency_id_idx` (`agency_id`),
  CONSTRAINT `tool_history_entries_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `tool_history_entries_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `tool_history_entries_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `town_suburbs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `town_suburbs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint(20) unsigned NOT NULL,
  `town_id` bigint(20) unsigned NOT NULL,
  `suburb_name` varchar(150) NOT NULL,
  `suburb_normalised` varchar(150) NOT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `town_suburbs_agency_norm_unique` (`agency_id`,`suburb_normalised`),
  KEY `town_suburbs_town_id_foreign` (`town_id`),
  KEY `town_suburbs_agency_town_idx` (`agency_id`,`town_id`),
  KEY `town_suburbs_deleted_idx` (`deleted_at`),
  CONSTRAINT `town_suburbs_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `town_suburbs_town_id_foreign` FOREIGN KEY (`town_id`) REFERENCES `towns` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `towns`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `towns` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint(20) unsigned NOT NULL,
  `name` varchar(100) NOT NULL,
  `slug` varchar(120) NOT NULL,
  `region` varchar(100) DEFAULT NULL,
  `display_order` smallint(5) unsigned NOT NULL DEFAULT 0,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `towns_agency_slug_unique` (`agency_id`,`slug`),
  KEY `towns_agency_order_idx` (`agency_id`,`display_order`),
  KEY `towns_deleted_at_idx` (`deleted_at`),
  CONSTRAINT `towns_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tracked_properties`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `tracked_properties` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint(20) unsigned NOT NULL,
  `external_id` char(36) NOT NULL,
  `street_number` varchar(50) DEFAULT NULL,
  `street_name` varchar(200) DEFAULT NULL,
  `unit_number` varchar(50) DEFAULT NULL,
  `complex_name` varchar(200) DEFAULT NULL,
  `suburb` varchar(100) DEFAULT NULL,
  `suburb_normalised` varchar(100) DEFAULT NULL,
  `town` varchar(100) DEFAULT NULL,
  `province` varchar(100) DEFAULT NULL,
  `postal_code` varchar(20) DEFAULT NULL,
  `latitude` decimal(10,7) DEFAULT NULL,
  `longitude` decimal(10,7) DEFAULT NULL,
  `geo_source` varchar(30) DEFAULT NULL,
  `geo_confidence` varchar(20) DEFAULT NULL,
  `geocode_needs_review` tinyint(1) NOT NULL DEFAULT 0,
  `geo_resolved_at` timestamp NULL DEFAULT NULL,
  `cma_gps_lat` decimal(10,7) DEFAULT NULL,
  `cma_gps_lng` decimal(10,7) DEFAULT NULL,
  `erf_number` varchar(100) DEFAULT NULL,
  `title_deed_number` varchar(100) DEFAULT NULL,
  `cadastral_extent` varchar(50) DEFAULT NULL,
  `municipal_valuation` decimal(15,2) DEFAULT NULL,
  `municipal_valuation_year` smallint(5) unsigned DEFAULT NULL,
  `last_known_asking_price` decimal(15,2) DEFAULT NULL,
  `last_known_sold_price` decimal(15,2) DEFAULT NULL,
  `last_known_sold_date` date DEFAULT NULL,
  `property_type` varchar(50) DEFAULT NULL,
  `bedrooms` tinyint(3) unsigned DEFAULT NULL,
  `bathrooms` tinyint(3) unsigned DEFAULT NULL,
  `garages` tinyint(3) unsigned DEFAULT NULL,
  `floor_size_m2` decimal(10,2) DEFAULT NULL,
  `erf_size_m2` decimal(10,2) DEFAULT NULL,
  `promoted_to_property_id` bigint(20) unsigned DEFAULT NULL,
  `promoted_at` timestamp NULL DEFAULT NULL,
  `promoted_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `owner_contact_id` bigint(20) unsigned DEFAULT NULL,
  `source_chain` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`source_chain`)),
  `first_seen_at` timestamp NULL DEFAULT NULL,
  `last_enriched_at` timestamp NULL DEFAULT NULL,
  `last_enrichment_source` varchar(100) DEFAULT NULL,
  `status` enum('active','archived','duplicate','promoted') NOT NULL DEFAULT 'active',
  `duplicate_of_tracked_property_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `is_demo` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tracked_properties_external_id_unique` (`external_id`),
  KEY `tracked_properties_promoted_by_user_id_foreign` (`promoted_by_user_id`),
  KEY `idx_tracked_props_agency_suburb` (`agency_id`,`suburb_normalised`),
  KEY `idx_tracked_props_agency_erf` (`agency_id`,`erf_number`),
  KEY `idx_tracked_props_agency_status` (`agency_id`,`status`),
  KEY `idx_tracked_props_promoted` (`promoted_to_property_id`),
  KEY `idx_tracked_props_geo` (`latitude`,`longitude`),
  KEY `idx_tracked_props_cma_geo` (`cma_gps_lat`,`cma_gps_lng`),
  KEY `idx_tracked_properties_is_demo` (`is_demo`),
  KEY `idx_tracked_props_owner_contact` (`owner_contact_id`),
  CONSTRAINT `tracked_properties_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `tracked_properties_owner_contact_id_foreign` FOREIGN KEY (`owner_contact_id`) REFERENCES `contacts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `tracked_properties_promoted_by_user_id_foreign` FOREIGN KEY (`promoted_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `tracked_properties_promoted_to_property_id_foreign` FOREIGN KEY (`promoted_to_property_id`) REFERENCES `properties` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tracked_property_addresses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `tracked_property_addresses` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint(20) unsigned NOT NULL,
  `tracked_property_id` bigint(20) unsigned NOT NULL,
  `street_number` varchar(50) DEFAULT NULL,
  `street_name` varchar(200) DEFAULT NULL COMMENT 'Normalised on write (St→Street, Rd→Road, …) — see TrackedPropertyMatchOrCreateService::normaliseStreetName().',
  `unit_number` varchar(50) DEFAULT NULL,
  `complex_name` varchar(200) DEFAULT NULL,
  `suburb` varchar(100) DEFAULT NULL,
  `suburb_normalised` varchar(100) DEFAULT NULL COMMENT 'Lowercase + strip punctuation + collapse whitespace; see TrackedProperty::normaliseSuburb().',
  `town` varchar(100) DEFAULT NULL,
  `province` varchar(100) DEFAULT NULL,
  `postal_code` varchar(20) DEFAULT NULL,
  `latitude` decimal(10,7) DEFAULT NULL,
  `longitude` decimal(10,7) DEFAULT NULL,
  `source_type` varchar(50) NOT NULL COMMENT 'p24 | pp | chrome_capture | cmainfo | manual_agent | manual_admin | deeds_office',
  `source_ref` varchar(200) DEFAULT NULL COMMENT 'The originating record ID (portal listing id, presentation id, capture id, etc).',
  `confidence` enum('low','medium','high','verified') NOT NULL DEFAULT 'low' COMMENT 'verified = agent-confirmed; promotes to primary per spec §3.2.1.',
  `is_primary` tinyint(1) NOT NULL DEFAULT 0,
  `verified_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `verified_at` timestamp NULL DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `first_seen_at` timestamp NULL DEFAULT NULL,
  `last_seen_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `tracked_property_addresses_tracked_property_id_foreign` (`tracked_property_id`),
  KEY `tracked_property_addresses_verified_by_user_id_foreign` (`verified_by_user_id`),
  KEY `idx_tpa_agency_tp_primary` (`agency_id`,`tracked_property_id`,`is_primary`),
  KEY `idx_tpa_agency_suburb_street` (`agency_id`,`suburb_normalised`,`street_name`),
  KEY `idx_tpa_agency_geo` (`agency_id`,`latitude`,`longitude`),
  CONSTRAINT `tracked_property_addresses_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `tracked_property_addresses_tracked_property_id_foreign` FOREIGN KEY (`tracked_property_id`) REFERENCES `tracked_properties` (`id`) ON DELETE CASCADE,
  CONSTRAINT `tracked_property_addresses_verified_by_user_id_foreign` FOREIGN KEY (`verified_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Per-TP address history; one is_primary=true per tracked_property cached onto tracked_properties via observer (Phase A3).';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tracked_property_external_refs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `tracked_property_external_refs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint(20) unsigned NOT NULL,
  `tracked_property_id` bigint(20) unsigned NOT NULL,
  `source_type` varchar(50) NOT NULL,
  `source_ref` varchar(200) NOT NULL,
  `source_payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`source_payload`)),
  `first_seen_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `last_seen_at` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unq_tracked_external_ref` (`agency_id`,`source_type`,`source_ref`),
  KEY `idx_tracked_ext_refs_lookup` (`tracked_property_id`,`source_type`),
  CONSTRAINT `tracked_property_external_refs_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `tracked_property_external_refs_tracked_property_id_foreign` FOREIGN KEY (`tracked_property_id`) REFERENCES `tracked_properties` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `training_completions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `training_completions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `course_id` bigint(20) unsigned NOT NULL,
  `completed_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `acknowledged_at` timestamp NULL DEFAULT NULL,
  `acknowledgement_signature` text DEFAULT NULL,
  `certificate_path` varchar(500) DEFAULT NULL,
  `expires_at` date DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `training_completions_user_id_course_id_unique` (`user_id`,`course_id`),
  KEY `training_completions_user_id_index` (`user_id`),
  KEY `training_completions_expires_at_index` (`expires_at`),
  KEY `training_completions_course_id_foreign` (`course_id`),
  CONSTRAINT `training_completions_course_id_foreign` FOREIGN KEY (`course_id`) REFERENCES `training_courses` (`id`) ON DELETE CASCADE,
  CONSTRAINT `training_completions_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `training_courses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `training_courses` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint(20) unsigned NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `category` enum('compliance','onboarding','sales','systems','general') NOT NULL DEFAULT 'general',
  `is_required` tinyint(1) NOT NULL DEFAULT 0,
  `is_required_for_activation` tinyint(1) NOT NULL DEFAULT 0,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_published` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `training_courses_agency_id_category_index` (`agency_id`,`category`),
  KEY `training_courses_created_by_foreign` (`created_by`),
  CONSTRAINT `training_courses_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `training_courses_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `training_doc_bookmarks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `training_doc_bookmarks` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `doc_id` bigint(20) unsigned NOT NULL,
  `section_anchor` varchar(200) NOT NULL,
  `note` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `training_doc_bookmarks_doc_id_foreign` (`doc_id`),
  KEY `training_doc_bookmarks_user_id_doc_id_index` (`user_id`,`doc_id`),
  CONSTRAINT `training_doc_bookmarks_doc_id_foreign` FOREIGN KEY (`doc_id`) REFERENCES `training_docs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `training_doc_bookmarks_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `training_doc_chunks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `training_doc_chunks` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `doc_id` bigint(20) unsigned NOT NULL,
  `chunk_index` smallint(5) unsigned NOT NULL,
  `heading_path` varchar(500) DEFAULT NULL,
  `section_anchor` varchar(200) DEFAULT NULL,
  `content` text NOT NULL,
  `word_count` int(10) unsigned NOT NULL DEFAULT 0,
  `embedding` longtext DEFAULT NULL,
  `has_embedding` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `training_doc_chunks_doc_id_chunk_index_index` (`doc_id`,`chunk_index`),
  KEY `training_doc_chunks_has_embedding_index` (`has_embedding`),
  CONSTRAINT `training_doc_chunks_doc_id_foreign` FOREIGN KEY (`doc_id`) REFERENCES `training_docs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `training_doc_reads`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `training_doc_reads` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `doc_id` bigint(20) unsigned NOT NULL,
  `agency_id` bigint(20) unsigned DEFAULT NULL,
  `sections_completed` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`sections_completed`)),
  `last_section_read` varchar(200) DEFAULT NULL,
  `last_read_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `is_outdated_since` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `training_doc_reads_user_id_doc_id_unique` (`user_id`,`doc_id`),
  KEY `training_doc_reads_doc_id_foreign` (`doc_id`),
  KEY `training_doc_reads_agency_id_index` (`agency_id`),
  CONSTRAINT `training_doc_reads_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE SET NULL,
  CONSTRAINT `training_doc_reads_doc_id_foreign` FOREIGN KEY (`doc_id`) REFERENCES `training_docs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `training_doc_reads_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `training_docs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `training_docs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `slug` varchar(255) NOT NULL,
  `title` varchar(255) NOT NULL,
  `role_audience` varchar(50) NOT NULL DEFAULT 'all',
  `file_path` varchar(255) NOT NULL,
  `content_hash` varchar(64) NOT NULL,
  `word_count` int(10) unsigned NOT NULL DEFAULT 0,
  `reading_time_minutes` smallint(5) unsigned NOT NULL DEFAULT 0,
  `is_required` tinyint(1) NOT NULL DEFAULT 0,
  `sort_order` smallint(5) unsigned NOT NULL DEFAULT 0,
  `version` smallint(5) unsigned NOT NULL DEFAULT 1,
  `last_indexed_at` timestamp NULL DEFAULT NULL,
  `agency_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `training_docs_slug_unique` (`slug`),
  KEY `training_docs_agency_id_foreign` (`agency_id`),
  KEY `training_docs_role_audience_index` (`role_audience`),
  KEY `training_docs_sort_order_index` (`sort_order`),
  CONSTRAINT `training_docs_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `training_lessons`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `training_lessons` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `course_id` bigint(20) unsigned NOT NULL,
  `title` varchar(255) NOT NULL,
  `content` text DEFAULT NULL,
  `content_type` enum('text','video_url','document','link') NOT NULL DEFAULT 'text',
  `video_url` varchar(500) DEFAULT NULL,
  `document_path` varchar(500) DEFAULT NULL,
  `external_link` varchar(500) DEFAULT NULL,
  `duration_minutes` int(11) NOT NULL DEFAULT 10,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_published` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `training_lessons_course_id_sort_order_index` (`course_id`,`sort_order`),
  CONSTRAINT `training_lessons_course_id_foreign` FOREIGN KEY (`course_id`) REFERENCES `training_courses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `training_progress`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `training_progress` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `course_id` bigint(20) unsigned NOT NULL,
  `lesson_id` bigint(20) unsigned NOT NULL,
  `started_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `time_spent_seconds` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `training_progress_user_id_lesson_id_unique` (`user_id`,`lesson_id`),
  KEY `training_progress_user_id_course_id_index` (`user_id`,`course_id`),
  KEY `training_progress_course_id_foreign` (`course_id`),
  KEY `training_progress_lesson_id_foreign` (`lesson_id`),
  CONSTRAINT `training_progress_course_id_foreign` FOREIGN KEY (`course_id`) REFERENCES `training_courses` (`id`) ON DELETE CASCADE,
  CONSTRAINT `training_progress_lesson_id_foreign` FOREIGN KEY (`lesson_id`) REFERENCES `training_lessons` (`id`) ON DELETE CASCADE,
  CONSTRAINT `training_progress_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tv_access_codes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `tv_access_codes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `branch_id` bigint(20) unsigned DEFAULT NULL,
  `code` varchar(6) NOT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tv_access_codes_code_unique` (`code`),
  KEY `tv_access_codes_branch_id_is_active_index` (`branch_id`,`is_active`),
  KEY `tv_access_codes_branch_id_index` (`branch_id`),
  KEY `tv_access_codes_created_by_index` (`created_by`),
  KEY `tv_access_codes_is_active_index` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tv_messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `tv_messages` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `branch_id` bigint(20) unsigned DEFAULT NULL,
  `created_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `title` varchar(255) DEFAULT NULL,
  `message` text NOT NULL,
  `display_area` varchar(255) NOT NULL DEFAULT 'both',
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `starts_at` timestamp NULL DEFAULT NULL,
  `ends_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `tv_messages_branch_id_is_enabled_index` (`branch_id`,`is_enabled`),
  KEY `tv_messages_branch_id_index` (`branch_id`),
  KEY `tv_messages_created_by_user_id_index` (`created_by_user_id`),
  KEY `tv_messages_is_enabled_index` (`is_enabled`),
  KEY `tv_messages_starts_at_index` (`starts_at`),
  KEY `tv_messages_ends_at_index` (`ends_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `user_banking_details`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `user_banking_details` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `agency_id` bigint(20) unsigned NOT NULL,
  `account_holder` varchar(100) NOT NULL,
  `bank_name` varchar(100) NOT NULL,
  `branch_code` varchar(10) NOT NULL,
  `account_number` varchar(30) NOT NULL,
  `account_type` enum('cheque','savings','transmission') NOT NULL,
  `is_primary` tinyint(1) NOT NULL DEFAULT 1,
  `verified_at` timestamp NULL DEFAULT NULL,
  `verified_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_banking_details_user_id_unique` (`user_id`),
  KEY `user_banking_details_verified_by_foreign` (`verified_by`),
  KEY `user_banking_details_agency_id_user_id_index` (`agency_id`,`user_id`),
  CONSTRAINT `user_banking_details_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`),
  CONSTRAINT `user_banking_details_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `user_banking_details_verified_by_foreign` FOREIGN KEY (`verified_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `user_compliance_overrides`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `user_compliance_overrides` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `agency_id` bigint(20) unsigned NOT NULL,
  `branch_id` bigint(20) unsigned DEFAULT NULL,
  `compliance_item` varchar(50) NOT NULL,
  `override_type` enum('exempt','waived','not_applicable') NOT NULL,
  `reason` text NOT NULL,
  `created_by` bigint(20) unsigned NOT NULL,
  `expires_at` date DEFAULT NULL,
  `revoked_by` bigint(20) unsigned DEFAULT NULL,
  `revoked_at` timestamp NULL DEFAULT NULL,
  `revoke_reason` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `user_compliance_overrides_created_by_foreign` (`created_by`),
  KEY `user_compliance_overrides_revoked_by_foreign` (`revoked_by`),
  KEY `user_compliance_overrides_user_id_compliance_item_index` (`user_id`,`compliance_item`),
  KEY `user_compliance_overrides_branch_id_foreign` (`branch_id`),
  KEY `user_compliance_overrides_agency_branch_idx` (`agency_id`,`branch_id`),
  CONSTRAINT `user_compliance_overrides_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `user_compliance_overrides_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `user_compliance_overrides_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  CONSTRAINT `user_compliance_overrides_revoked_by_foreign` FOREIGN KEY (`revoked_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `user_compliance_overrides_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `user_dashboard_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `user_dashboard_settings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `idle_alerts_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `idle_threshold_days` smallint(5) unsigned NOT NULL DEFAULT 14,
  `idle_alert_day` varchar(20) DEFAULT NULL COMMENT 'monday-sunday or null for daily',
  `idle_alert_time` time NOT NULL DEFAULT '08:00:00',
  `doc_reminders_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `doc_reminder_hours_before` smallint(5) unsigned NOT NULL DEFAULT 24,
  `lease_expiry_reminders` tinyint(1) NOT NULL DEFAULT 1,
  `lease_reminder_days_before` smallint(5) unsigned NOT NULL DEFAULT 90,
  `fica_reminders` tinyint(1) NOT NULL DEFAULT 1,
  `ffc_reminders` tinyint(1) NOT NULL DEFAULT 1,
  `task_due_reminders` tinyint(1) NOT NULL DEFAULT 1,
  `task_reminder_hours_before` smallint(5) unsigned NOT NULL DEFAULT 4,
  `event_reminder_hours_before` smallint(5) unsigned NOT NULL DEFAULT 24,
  `auto_archive_done_days` smallint(5) unsigned DEFAULT NULL,
  `overdue_daily_digest` tinyint(1) NOT NULL DEFAULT 1,
  `digest_time` time NOT NULL DEFAULT '08:00:00',
  `default_calendar_view` varchar(20) NOT NULL DEFAULT 'month',
  `weekend_visible` tinyint(1) NOT NULL DEFAULT 0,
  `working_hours_start` time NOT NULL DEFAULT '08:00:00',
  `working_hours_end` time NOT NULL DEFAULT '17:00:00',
  `notify_in_app` tinyint(1) NOT NULL DEFAULT 1,
  `notify_email` tinyint(1) NOT NULL DEFAULT 1,
  `notify_push` tinyint(1) NOT NULL DEFAULT 1,
  `open_hours_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `open_hours_start` time NOT NULL DEFAULT '07:00:00',
  `open_hours_end` time NOT NULL DEFAULT '21:00:00',
  `min_minutes_between_same` smallint(5) unsigned NOT NULL DEFAULT 360,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_dashboard_settings_user_id_unique` (`user_id`),
  CONSTRAINT `user_dashboard_settings_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `user_documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `user_documents` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint(20) unsigned DEFAULT NULL,
  `branch_id` bigint(20) unsigned DEFAULT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `document_type` enum('ffc_certificate','id_copy','pi_insurance','tax_clearance','profile_photo','qualification','proof_of_address','bank_confirmation','police_clearance','credit_check_report','reference_letter','other','payslip') NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_size` int(10) unsigned DEFAULT NULL,
  `mime_type` varchar(100) DEFAULT NULL,
  `status` enum('pending','verified','rejected','expired') NOT NULL DEFAULT 'pending',
  `expiry_date` date DEFAULT NULL,
  `verified_by` bigint(20) unsigned DEFAULT NULL,
  `verified_at` timestamp NULL DEFAULT NULL,
  `rejected_reason` text DEFAULT NULL,
  `rejected_by` bigint(20) unsigned DEFAULT NULL,
  `rejected_at` timestamp NULL DEFAULT NULL,
  `uploaded_by` bigint(20) unsigned DEFAULT NULL,
  `uploaded_by_admin` tinyint(1) NOT NULL DEFAULT 0,
  `admin_upload_reason` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `user_documents_verified_by_foreign` (`verified_by`),
  KEY `user_documents_rejected_by_foreign` (`rejected_by`),
  KEY `user_documents_uploaded_by_foreign` (`uploaded_by`),
  KEY `user_documents_user_id_document_type_status_index` (`user_id`,`document_type`,`status`),
  KEY `user_documents_status_agency_id_index` (`status`,`agency_id`),
  KEY `user_documents_expiry_date_index` (`expiry_date`),
  KEY `user_documents_branch_id_foreign` (`branch_id`),
  KEY `user_documents_agency_branch_idx` (`agency_id`,`branch_id`),
  CONSTRAINT `user_documents_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE SET NULL,
  CONSTRAINT `user_documents_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `user_documents_rejected_by_foreign` FOREIGN KEY (`rejected_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `user_documents_uploaded_by_foreign` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `user_documents_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `user_documents_verified_by_foreign` FOREIGN KEY (`verified_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `user_notification_preferences`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `user_notification_preferences` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `notification_event_type_id` bigint(20) unsigned NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `threshold` int(10) unsigned DEFAULT NULL,
  `channel_in_app` tinyint(1) NOT NULL DEFAULT 1,
  `channel_email` tinyint(1) NOT NULL DEFAULT 0,
  `channel_push` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unp_user_event_unique` (`user_id`,`notification_event_type_id`),
  KEY `user_notification_preferences_notification_event_type_id_foreign` (`notification_event_type_id`),
  CONSTRAINT `user_notification_preferences_notification_event_type_id_foreign` FOREIGN KEY (`notification_event_type_id`) REFERENCES `notification_event_types` (`id`) ON DELETE CASCADE,
  CONSTRAINT `user_notification_preferences_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `user_oversight_preferences`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `user_oversight_preferences` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `category` varchar(64) NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `threshold_hours` int(10) unsigned DEFAULT NULL,
  `notify_channel` enum('email','in_app','both') NOT NULL DEFAULT 'in_app',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_oversight_preferences_user_id_category_unique` (`user_id`,`category`),
  KEY `user_oversight_preferences_agency_id_user_id_index` (`agency_id`,`user_id`),
  CONSTRAINT `user_oversight_preferences_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `user_oversight_preferences_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `qr_code_slug` varchar(16) DEFAULT NULL,
  `qr_reroute_user_id` bigint(20) unsigned DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `role` varchar(255) NOT NULL DEFAULT 'agent',
  `risk_tier` enum('high','medium','low') NOT NULL DEFAULT 'medium',
  `screening_status` enum('never_screened','pre_employment_pending','clear','concerns_flagged','overdue','expired') NOT NULL DEFAULT 'never_screened',
  `screening_due_on` date DEFAULT NULL,
  `designation` varchar(255) DEFAULT NULL,
  `employment_date` date DEFAULT NULL,
  `supervised_by` bigint(20) unsigned DEFAULT NULL,
  `is_admin` tinyint(1) NOT NULL DEFAULT 0,
  `target_listings` int(11) NOT NULL DEFAULT 0,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `remember_token` varchar(100) DEFAULT NULL,
  `api_token` varchar(64) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `branch_id` bigint(20) unsigned DEFAULT NULL,
  `agency_id` bigint(20) unsigned DEFAULT NULL,
  `anniversary_date` date DEFAULT NULL,
  `sponsored_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `agent_tier` varchar(20) NOT NULL DEFAULT 'standard',
  `is_mentor_eligible` tinyint(1) NOT NULL DEFAULT 0,
  `agent_photo_path` varchar(255) DEFAULT NULL,
  `ffc_certificate_path` varchar(255) DEFAULT NULL,
  `pi_insurance_path` varchar(500) DEFAULT NULL,
  `pi_insurance_expiry` date DEFAULT NULL,
  `tax_clearance_path` varchar(500) DEFAULT NULL,
  `tax_clearance_expiry` date DEFAULT NULL,
  `phone` varchar(255) DEFAULT NULL,
  `cell` varchar(255) DEFAULT NULL,
  `id_number` varchar(20) DEFAULT NULL,
  `date_of_birth` date DEFAULT NULL,
  `tax_reference_number` varchar(20) DEFAULT NULL,
  `id_document_path` varchar(500) DEFAULT NULL,
  `fax` varchar(255) DEFAULT NULL,
  `ffc_number` varchar(255) DEFAULT NULL,
  `ffc_expiry_date` date DEFAULT NULL,
  `ppra_status` enum('active','pending','expired','suspended') DEFAULT NULL,
  `ppra_last_verified_at` date DEFAULT NULL,
  `website` varchar(255) DEFAULT NULL,
  `theme` varchar(10) NOT NULL DEFAULT 'dark',
  `last_presentation_send_channel` varchar(20) DEFAULT NULL,
  `last_presentation_send_mode` varchar(10) DEFAULT NULL,
  `portal_show_api_token` tinyint(1) NOT NULL DEFAULT 1,
  `portal_show_social_accounts` tinyint(1) NOT NULL DEFAULT 1,
  `pp_unique_agent_id` varchar(100) DEFAULT NULL,
  `pp_external_ref` varchar(100) DEFAULT NULL,
  `agent_cut_percent` decimal(5,2) DEFAULT NULL,
  `paye_method` varchar(20) DEFAULT NULL,
  `paye_value` decimal(10,2) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `counts_for_branch_split` tinyint(1) NOT NULL DEFAULT 1,
  `can_capture_rentals` tinyint(1) NOT NULL DEFAULT 0,
  `sliding_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `sliding_tier1_cut_percent` decimal(5,2) DEFAULT NULL,
  `sliding_tier2_cut_percent` decimal(5,2) DEFAULT NULL,
  `sliding_tier3_cut_percent` decimal(5,2) DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `p24_agent_id` int(11) DEFAULT NULL,
  `source_reference` varchar(255) DEFAULT NULL,
  `emergency_contact_name` varchar(150) DEFAULT NULL,
  `emergency_contact_phone` varchar(30) DEFAULT NULL,
  `emergency_contact_relationship` varchar(50) DEFAULT NULL,
  `next_of_kin_name` varchar(150) DEFAULT NULL,
  `next_of_kin_phone` varchar(30) DEFAULT NULL,
  `next_of_kin_relationship` varchar(50) DEFAULT NULL,
  `home_address` text DEFAULT NULL,
  `marital_status` enum('single','married','divorced','widowed','life_partner','other') DEFAULT NULL,
  `dependents_count` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `medical_aid_provider` varchar(100) DEFAULT NULL,
  `medical_aid_number` varchar(50) DEFAULT NULL,
  `medical_aid_main_member` tinyint(1) NOT NULL DEFAULT 0,
  `medical_aid_dependents_count` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `show_on_website` tinyint(1) NOT NULL DEFAULT 0,
  `website_order` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`),
  UNIQUE KEY `users_api_token_unique` (`api_token`),
  UNIQUE KEY `users_qr_code_slug_unique` (`qr_code_slug`),
  KEY `users_branch_id_foreign` (`branch_id`),
  KEY `users_can_capture_rentals_index` (`can_capture_rentals`),
  KEY `users_agency_id_foreign` (`agency_id`),
  KEY `users_supervised_by_index` (`supervised_by`),
  KEY `users_sponsored_by_user_id_foreign` (`sponsored_by_user_id`),
  KEY `users_p24_agent_id_index` (`p24_agent_id`),
  KEY `users_source_reference_index` (`source_reference`),
  KEY `users_qr_reroute_user_id_index` (`qr_reroute_user_id`),
  KEY `users_show_on_website_index` (`show_on_website`),
  CONSTRAINT `users_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE SET NULL,
  CONSTRAINT `users_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `users_sponsored_by_user_id_foreign` FOREIGN KEY (`sponsored_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `users_supervised_by_foreign` FOREIGN KEY (`supervised_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `web_pack_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `web_pack_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `web_pack_id` bigint(20) unsigned NOT NULL,
  `template_id` bigint(20) unsigned NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `slot_type` varchar(20) NOT NULL DEFAULT 'required',
  `slot_group` int(10) unsigned DEFAULT NULL,
  `slot_label` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `web_pack_items_web_pack_id_foreign` (`web_pack_id`),
  KEY `web_pack_items_template_id_foreign` (`template_id`),
  CONSTRAINT `web_pack_items_template_id_foreign` FOREIGN KEY (`template_id`) REFERENCES `docuperfect_templates` (`id`) ON DELETE CASCADE,
  CONSTRAINT `web_pack_items_web_pack_id_foreign` FOREIGN KEY (`web_pack_id`) REFERENCES `web_packs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `web_packs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `web_packs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint(20) unsigned NOT NULL,
  `created_by` bigint(20) unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `web_packs_agency_id_foreign` (`agency_id`),
  KEY `web_packs_created_by_foreign` (`created_by`),
  CONSTRAINT `web_packs_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `web_packs_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `wet_ink_inspections`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `wet_ink_inspections` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `signature_request_id` bigint(20) unsigned NOT NULL,
  `inspector_user_id` bigint(20) unsigned NOT NULL,
  `checklist_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`checklist_json`)),
  `result` enum('approved','rejected') NOT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `wet_ink_inspections_inspector_user_id_foreign` (`inspector_user_id`),
  KEY `wet_ink_inspections_signature_request_id_created_at_index` (`signature_request_id`,`created_at`),
  CONSTRAINT `wet_ink_inspections_inspector_user_id_foreign` FOREIGN KEY (`inspector_user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `wet_ink_inspections_signature_request_id_foreign` FOREIGN KEY (`signature_request_id`) REFERENCES `signature_requests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `whistleblow_audit_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `whistleblow_audit_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `complaint_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `action` varchar(255) NOT NULL,
  `action_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`action_data`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `whistleblow_audit_log_complaint_id_foreign` (`complaint_id`),
  KEY `whistleblow_audit_log_user_id_foreign` (`user_id`),
  CONSTRAINT `whistleblow_audit_log_complaint_id_foreign` FOREIGN KEY (`complaint_id`) REFERENCES `whistleblow_complaints` (`id`) ON DELETE CASCADE,
  CONSTRAINT `whistleblow_audit_log_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `whistleblow_complaint_evidence`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `whistleblow_complaint_evidence` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `complaint_id` bigint(20) unsigned NOT NULL,
  `evidence_type` enum('screenshot','portal_html','seller_statement_pdf','photo','audio_recording','document_upload','other') NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `original_filename` varchar(255) DEFAULT NULL,
  `mime_type` varchar(255) DEFAULT NULL,
  `size_bytes` int(11) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `uploaded_by_user_id` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `whistleblow_complaint_evidence_complaint_id_foreign` (`complaint_id`),
  KEY `whistleblow_complaint_evidence_uploaded_by_user_id_foreign` (`uploaded_by_user_id`),
  CONSTRAINT `whistleblow_complaint_evidence_complaint_id_foreign` FOREIGN KEY (`complaint_id`) REFERENCES `whistleblow_complaints` (`id`) ON DELETE CASCADE,
  CONSTRAINT `whistleblow_complaint_evidence_uploaded_by_user_id_foreign` FOREIGN KEY (`uploaded_by_user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `whistleblow_complaint_subjects`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `whistleblow_complaint_subjects` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `complaint_id` bigint(20) unsigned NOT NULL,
  `agency_name` varchar(255) NOT NULL,
  `practitioner_name` varchar(255) DEFAULT NULL,
  `portal_url` varchar(255) NOT NULL,
  `portal_source` enum('p24','pp','other') NOT NULL,
  `portal_listing_ref` varchar(255) DEFAULT NULL,
  `display_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `whistleblow_complaint_subjects_complaint_id_foreign` (`complaint_id`),
  CONSTRAINT `whistleblow_complaint_subjects_complaint_id_foreign` FOREIGN KEY (`complaint_id`) REFERENCES `whistleblow_complaints` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `whistleblow_complaints`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `whistleblow_complaints` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint(20) unsigned NOT NULL,
  `branch_id` bigint(20) unsigned DEFAULT NULL,
  `reported_by_user_id` bigint(20) unsigned NOT NULL,
  `tier` enum('tier_1','tier_2','tier_3') NOT NULL,
  `property_id` bigint(20) unsigned DEFAULT NULL,
  `property_address` varchar(255) NOT NULL,
  `seller_contact_id` bigint(20) unsigned DEFAULT NULL,
  `seller_statement` text DEFAULT NULL,
  `agent_notes` text DEFAULT NULL,
  `status` enum('draft','pending_approval','changes_requested','rejected','approved','sent','acknowledged_by_ppra','closed') NOT NULL DEFAULT 'draft',
  `approved_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `approval_notes` text DEFAULT NULL,
  `rejected_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `rejected_at` timestamp NULL DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `sent_to_ppra_at` timestamp NULL DEFAULT NULL,
  `ppra_reference_number` varchar(255) DEFAULT NULL,
  `ppra_acknowledged_at` timestamp NULL DEFAULT NULL,
  `complaint_pdf_path` varchar(255) DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `whistleblow_complaints_agency_id_foreign` (`agency_id`),
  KEY `whistleblow_complaints_branch_id_foreign` (`branch_id`),
  KEY `whistleblow_complaints_reported_by_user_id_foreign` (`reported_by_user_id`),
  KEY `whistleblow_complaints_property_id_foreign` (`property_id`),
  KEY `whistleblow_complaints_seller_contact_id_foreign` (`seller_contact_id`),
  KEY `whistleblow_complaints_approved_by_user_id_foreign` (`approved_by_user_id`),
  KEY `whistleblow_complaints_rejected_by_user_id_foreign` (`rejected_by_user_id`),
  CONSTRAINT `whistleblow_complaints_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`),
  CONSTRAINT `whistleblow_complaints_approved_by_user_id_foreign` FOREIGN KEY (`approved_by_user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `whistleblow_complaints_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  CONSTRAINT `whistleblow_complaints_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`),
  CONSTRAINT `whistleblow_complaints_rejected_by_user_id_foreign` FOREIGN KEY (`rejected_by_user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `whistleblow_complaints_reported_by_user_id_foreign` FOREIGN KEY (`reported_by_user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `whistleblow_complaints_seller_contact_id_foreign` FOREIGN KEY (`seller_contact_id`) REFERENCES `contacts` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `whistleblow_email_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `whistleblow_email_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `complaint_id` bigint(20) unsigned DEFAULT NULL,
  `agency_id` bigint(20) unsigned NOT NULL,
  `sent_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `email_type` varchar(255) NOT NULL DEFAULT 'ppra_submission',
  `subject` varchar(255) NOT NULL,
  `recipients_to` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`recipients_to`)),
  `recipients_cc` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`recipients_cc`)),
  `recipients_bcc` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`recipients_bcc`)),
  `rendered_html` longtext NOT NULL,
  `rendered_text` longtext DEFAULT NULL,
  `attachments` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`attachments`)),
  `sent_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `mail_message_id` varchar(255) DEFAULT NULL,
  `status` enum('sent','failed') NOT NULL DEFAULT 'sent',
  `error_message` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `whistleblow_email_log_complaint_id_foreign` (`complaint_id`),
  KEY `whistleblow_email_log_sent_by_user_id_foreign` (`sent_by_user_id`),
  KEY `whistleblow_email_log_agency_id_idx` (`agency_id`),
  CONSTRAINT `whistleblow_email_log_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `whistleblow_email_log_complaint_id_foreign` FOREIGN KEY (`complaint_id`) REFERENCES `whistleblow_complaints` (`id`) ON DELETE CASCADE,
  CONSTRAINT `whistleblow_email_log_sent_by_user_id_foreign` FOREIGN KEY (`sent_by_user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `wishlist_migration_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `wishlist_migration_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `run_id` varchar(40) NOT NULL,
  `source_buyer_preference_id` bigint(20) unsigned NOT NULL,
  `target_contact_match_id` bigint(20) unsigned DEFAULT NULL,
  `contact_id` bigint(20) unsigned NOT NULL,
  `agency_id` bigint(20) unsigned NOT NULL,
  `action` enum('would_create','would_append','would_merge','would_skip','would_fail','created','appended','merged','skipped','failed') NOT NULL,
  `mode` enum('dry_run','live') NOT NULL,
  `notes` text DEFAULT NULL,
  `field_mapping_snapshot` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`field_mapping_snapshot`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `wml_run_idx` (`run_id`),
  KEY `wml_contact_idx` (`contact_id`),
  KEY `wml_action_idx` (`action`,`mode`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `worksheets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `worksheets` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `agency_id` bigint(20) unsigned NOT NULL,
  `period` varchar(255) NOT NULL,
  `personal_net_target` decimal(10,2) NOT NULL DEFAULT 0.00,
  `business_net_target` decimal(10,2) NOT NULL DEFAULT 0.00,
  `want_net_target` decimal(10,2) NOT NULL DEFAULT 0.00,
  `avg_sale_price` decimal(12,2) NOT NULL DEFAULT 1060000.00,
  `avg_sale_price_admin` decimal(12,2) DEFAULT NULL,
  `commission_percent` decimal(5,2) NOT NULL DEFAULT 7.50,
  `commission_percent_admin` decimal(5,2) DEFAULT NULL,
  `commission_percent_locked` tinyint(1) NOT NULL DEFAULT 0,
  `paye_percent` decimal(5,2) NOT NULL DEFAULT 18.00,
  `agent_split_percent` decimal(5,2) NOT NULL DEFAULT 50.00,
  `correctly_priced_percent` decimal(5,2) NOT NULL DEFAULT 40.00,
  `current_listings` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `worksheets_user_id_foreign` (`user_id`),
  KEY `worksheets_agency_id_idx` (`agency_id`),
  CONSTRAINT `worksheets_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `worksheets_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (1,'0001_01_01_000000_create_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (2,'0001_01_01_000001_create_cache_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (3,'0001_01_01_000002_create_jobs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (4,'2026_01_13_104554_create_worksheets_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (5,'2026_01_13_124030_add_is_admin_to_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (6,'2026_01_13_130106_create_company_expenses_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (7,'2026_01_13_132900_add_period_and_monthly_expenses_to_company_expenses_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (8,'2026_01_13_145701_add_target_listings_to_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (9,'2026_01_13_150652_create_listing_targets_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (10,'2026_01_15_073058_create_branches_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (11,'2026_01_15_073159_add_role_and_branch_to_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (12,'2026_01_15_083757_create_branch_assignments_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (13,'2026_01_15_084201_create_deals_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (14,'2026_01_15_084303_create_deal_user_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (15,'2026_01_15_094935_add_is_active_to_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (16,'2026_01_15_113405_add_register_fields_to_deals_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (17,'2026_01_15_120724_add_deal_no_to_deals_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (18,'2026_01_16_090043_add_settlement_fields_to_deal_user_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (19,'2026_01_16_092537_create_deal_settlements_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (20,'2026_01_16_104858_add_paid_at_to_deal_settlements_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (21,'2026_01_16_110802_add_commission_defaults_to_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (22,'2026_01_19_103922_add_sliding_scale_fields',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (23,'2026_01_19_104247_add_granted_at_to_deals_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (24,'2026_01_19_104324_add_sliding_audit_fields_to_deal_user',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (25,'2026_01_20_100017_create_targets_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (26,'2026_01_20_102657_create_activity_targets_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (27,'2026_01_20_102657_create_daily_activities_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (28,'2026_01_20_103403_create_activity_targets_table_v2',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (29,'2026_01_20_103403_create_daily_activities_table_v2',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (30,'2026_01_20_123534_create_activity_columns_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (31,'2026_01_20_123535_create_branch_activity_columns_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (32,'2026_01_21_091152_create_listing_snapshots_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (33,'2026_01_21_125551_add_avg_sale_price_admin_to_worksheets_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (34,'2026_01_21_130046_add_avg_sale_price_admin_to_worksheets_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (35,'2026_01_23_074633_add_points_weight_to_activity_columns_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (36,'2026_01_23_074633_create_activity_point_goals_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (37,'2026_01_23_115447_add_points_weight_to_branch_activity_columns_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (38,'2026_01_24_055854_add_company_income_columns_to_deals_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (39,'2026_01_24_062415_create_monthly_target_goals_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (40,'2026_01_24_141059_add_points_target_to_targets_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (41,'2026_01_24_150419_add_branch_budget_to_monthly_target_goals_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (42,'2026_01_26_062631_create_performance_settings_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (43,'2026_01_26_102646_add_side_split_percents_to_deals_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (44,'2026_01_28_150942_add_prospecting_to_daily_activities_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (45,'2026_01_29_081303_create_deal_money_lines_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (46,'2026_01_29_081921_patch_deal_money_lines_columns',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (47,'2026_01_30_091638_create_activity_definitions_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (48,'2026_01_30_091638_create_daily_activity_entries_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (49,'2026_02_03_073757_create_tool_history_entries_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (50,'2026_02_03_124938_create_listing_import_runs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (51,'2026_02_03_124938_create_listing_stocks_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (52,'2026_02_03_124939_create_listing_import_rows_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (53,'2026_02_05_070523_create_deal_logs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (54,'2026_02_05_133453_add_cma_to_listing_stocks_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (55,'2026_02_09_080642_create_listing_stock_agents_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (56,'2026_02_09_091604_create_branch_settings_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (57,'2026_02_09_091731_add_designation_to_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (58,'2026_02_09_112751_create_designations_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (59,'2026_02_10_034752_create_tv_messages_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (60,'2026_02_10_063905_add_display_area_to_tv_messages_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (61,'2026_02_10_072107_add_scoring_mode_to_activity_definitions_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (62,'2026_02_10_080611_create_rentals_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (63,'2026_02_10_080630_create_rental_agents_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (64,'2026_02_10_080641_create_rental_amount_versions_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (65,'2026_02_10_080652_add_can_capture_rentals_to_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (66,'2026_02_13_171336_create_ai_conversations_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (67,'2026_02_13_171337_create_ai_messages_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (68,'2026_02_14_132146_add_counts_for_branch_split_to_users',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (69,'2026_02_15_100000_create_nexus_permissions_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (70,'2026_02_18_000001_create_pdf_splitter_feedback_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (71,'2026_02_18_000002_create_pdf_splitter_learned_phrases_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (72,'2026_02_19_100000_create_finance_definitions_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (73,'2026_02_19_100001_create_finance_computed_values_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (74,'2026_02_19_100002_create_finance_audit_runs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (75,'2026_02_19_100003_create_finance_audit_items_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (76,'2026_02_19_100004_add_audit_run_id_to_finance_computed_values',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (77,'2026_02_20_200000_create_presentations_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (78,'2026_02_20_200001_create_presentation_sections_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (79,'2026_02_20_200002_create_presentation_snapshots_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (80,'2026_02_20_200003_create_presentation_uploads_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (81,'2026_02_20_200004_create_presentation_fields_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (82,'2026_02_20_300000_create_market_analytics_runs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (83,'2026_02_20_300001_create_sale_probability_runs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (84,'2026_02_20_300002_add_snapshot_lock_columns_to_presentation_snapshots_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (85,'2026_02_20_400001_add_address_and_seller_to_presentations_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (86,'2026_02_20_400002_add_property_fields_to_presentations_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (87,'2026_02_20_400003_create_presentation_links_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (88,'2026_02_20_500001_create_presentation_sold_comps_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (89,'2026_02_20_500002_create_presentation_active_listings_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (90,'2026_02_20_500003_add_metadata_to_presentation_links_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (91,'2026_02_20_600001_create_presentation_versions_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (92,'2026_02_20_600002_create_presentation_url_snapshots_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (93,'2026_02_20_600003_add_snapshot_fields_to_presentation_active_listings',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (94,'2026_02_20_600004_create_presentation_articles_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (95,'2026_02_20_700001_add_holding_cost_to_presentations_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (96,'2026_02_20_700002_add_file_slug_to_presentation_uploads',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (97,'2026_02_20_800001_add_dedupe_fields_to_presentation_active_listings',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (98,'2026_02_20_900001_create_presentation_listing_price_history_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (99,'2026_02_20_900002_add_data_quality_fields_to_presentation_active_listings',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (100,'2026_02_20_950001_add_extraction_override_to_presentation_uploads',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (101,'2026_02_20_950002_add_extraction_override_to_presentation_links',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (102,'2026_02_21_100001_add_diagnostics_to_presentation_url_snapshots',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (103,'2026_02_21_120001_add_response_headers_json_to_presentation_url_snapshots',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (104,'2026_02_21_200001_create_portal_captures_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (105,'2026_02_21_300001_add_portal_capture_id_to_presentation_links',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (106,'2026_02_21_400001_create_portal_listings_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (107,'2026_02_21_400002_create_portal_listing_observations_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (108,'2026_02_21_500001_create_document_library_items_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (109,'2026_02_21_500002_create_presentation_document_library_items_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (110,'2026_02_23_800001_add_asking_price_to_presentations_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (111,'2026_02_23_900001_add_api_token_to_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (112,'2026_02_23_900001_add_expanded_fields_to_presentations_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (113,'2026_02_24_100001_add_analysis_selections_to_presentations_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (114,'2026_02_24_200000_create_tv_access_codes_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (115,'2026_02_24_200001_add_simulator_config_to_presentations_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (116,'2026_02_24_300001_create_p24_suburbs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (117,'2026_02_24_400001_create_docuperfect_templates_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (118,'2026_02_24_400002_create_docuperfect_template_branches_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (119,'2026_02_24_400003_create_docuperfect_documents_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (120,'2026_02_24_400004_create_docuperfect_clauses_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (121,'2026_02_24_400005_create_docuperfect_clause_branches_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (122,'2026_02_24_400006_create_docuperfect_document_types_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (123,'2026_02_24_400007_add_document_type_id_to_docuperfect_templates',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (124,'2026_02_24_400008_create_docuperfect_packs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (125,'2026_02_24_400009_create_docuperfect_pack_templates_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (126,'2026_02_24_400010_create_docuperfect_pack_branches_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (127,'2026_02_24_400011_create_docuperfect_named_fields_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (128,'2026_02_24_400012_add_pack_instance_id_to_docuperfect_documents',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (129,'2026_02_24_400013_create_docuperfect_pack_instance_values_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (130,'2026_02_24_500000_create_document_filing_register_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (131,'2026_02_25_000001_create_knowledge_base_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (132,'2026_02_25_100001_create_agencies_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (133,'2026_02_25_100001_create_calculator_fee_scales_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (134,'2026_02_25_100002_add_agency_id_to_branches',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (135,'2026_02_25_100003_add_agency_id_to_users',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (136,'2026_02_25_100004_seed_hfc_coastal_agency_and_update_roles',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (137,'2026_02_25_100005_add_tertiary_color_to_agencies',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (138,'2026_02_25_154301_create_article_pool_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (139,'2026_02_25_201319_create_properties_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (140,'2026_02_25_400014_create_docuperfect_pack_slots_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (141,'2026_02_25_400015_add_creation_mode_to_docuperfect_packs',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (142,'2026_02_25_400016_create_docuperfect_pack_attachments_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (143,'2026_02_25_500001_create_p24_alert_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (144,'2026_02_25_600000_create_commercial_evaluations_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (145,'2026_02_25_600001_create_commercial_evaluation_financials_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (146,'2026_02_25_600002_create_commercial_evaluation_comparables_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (147,'2026_02_25_600003_create_commercial_evaluation_assets_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (148,'2026_02_25_600004_create_commercial_evaluation_units_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (149,'2026_02_25_600005_create_commercial_evaluation_crops_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (150,'2026_02_25_600006_create_commercial_evaluation_livestock_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (151,'2026_02_25_700000_add_guidance_answers_to_crops_and_livestock',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (152,'2026_02_25_800000_make_tv_access_codes_branch_id_nullable',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (153,'2026_02_26_100000_add_extended_fields_to_properties_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (154,'2026_02_26_600001_create_signature_templates_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (155,'2026_02_26_600002_create_signature_markers_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (156,'2026_02_26_600003_create_signature_requests_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (157,'2026_02_26_600004_create_signatures_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (158,'2026_02_26_600005_create_signature_audit_log_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (159,'2026_02_26_600006_add_pending_agent_approval_status_to_signature_templates',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (160,'2026_02_26_600006_create_wet_ink_inspections_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (161,'2026_02_26_600007_create_lease_records_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (162,'2026_02_26_600008_add_team_alerted_at_to_signature_requests',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (163,'2026_02_26_600009_add_signed_pdf_path_to_signature_templates',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (164,'2026_02_26_700001_create_sales_document_sends_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (165,'2026_02_26_700002_create_sales_document_recipients_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (166,'2026_02_26_800001_create_docuperfect_template_signature_zones_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (167,'2026_02_26_800002_add_from_template_zone_id_to_signature_markers',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (168,'2026_02_26_900001_add_signed_pdf_client_path_to_signature_templates',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (169,'2026_02_26_900002_add_flattened_pages_json_to_signature_templates',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (170,'2026_02_26_950001_create_rental_properties_and_document_types_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (171,'2026_02_27_000001_add_lease_expiry_to_docuperfect_documents',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (172,'2026_02_27_100001_create_rental_reminder_settings_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (173,'2026_02_27_121337_add_commission_percent_admin_and_locked_to_worksheets_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (174,'2026_02_27_200001_add_wet_ink_upload_method_to_signature_requests',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (175,'2026_02_27_300001_add_cosign_mode_to_signature_templates',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (176,'2026_02_27_400001_add_supersede_columns_to_signature_templates',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (177,'2026_02_28_100001_add_text_value_to_signatures_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (178,'2026_02_28_163349_make_template_id_nullable_on_docuperfect_documents',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (179,'2026_02_28_200001_add_id_number_to_sales_document_recipients',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (180,'2026_03_01_000001_create_property_ad_templates_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (181,'2026_03_02_000001_add_embedding_to_knowledge_chunks_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (182,'2026_03_03_000001_create_document_types_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (183,'2026_03_03_000002_create_splitter_doc_types_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (184,'2026_03_03_000003_add_seller_live_capture_json_to_presentations',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (185,'2026_03_03_000004_add_agent_uploads_to_users',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (186,'2026_03_04_000001_add_primary_image_url_to_portal_listings',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (187,'2026_03_05_000001_create_contact_types_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (188,'2026_03_05_000002_add_contact_details_notes_documents',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (189,'2026_03_05_100001_add_extra_fields_to_properties_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (190,'2026_03_05_100002_create_property_setting_items_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (191,'2026_03_05_100003_create_property_notes_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (192,'2026_03_05_100004_create_property_files_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (193,'2026_03_05_110912_add_type_and_module_to_nexus_permissions',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (194,'2026_03_05_115116_add_scope_to_role_permissions',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (195,'2026_03_05_200001_create_contact_property_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (196,'2026_03_05_300001_add_spaces_json_to_properties_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (197,'2026_03_05_300002_add_defaults_to_property_setting_items',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (198,'2026_03_05_300003_seed_default_setting_items',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (199,'2026_03_06_000001_add_contact_fields_to_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (200,'2026_03_06_000001_create_roles_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (201,'2026_03_06_000002_seed_existing_roles',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (202,'2026_03_06_100001_add_soft_deletes_tier1',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (203,'2026_03_06_100001_create_agent_social_accounts_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (204,'2026_03_06_100002_add_soft_deletes_tier2_docuperfect',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (205,'2026_03_06_100002_create_property_marketing_posts_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (206,'2026_03_06_100003_add_soft_deletes_tier3',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (207,'2026_03_06_100004_add_soft_deletes_tool_history_entries',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (208,'2026_03_06_200001_seed_office_admin_permissions_from_admin',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (209,'2026_03_07_100001_create_contact_matches_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (210,'2026_03_07_100001_create_flows_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (211,'2026_03_07_100002_add_share_token_to_contact_matches_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (212,'2026_03_07_100002_add_wizard_config_to_docuperfect_templates',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (213,'2026_03_07_100003_add_hidden_property_ids_to_contact_matches_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (214,'2026_03_07_100004_add_property_view_counts_to_contact_matches_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (215,'2026_03_07_200001_add_rental_lease_fields_to_properties',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (216,'2026_03_07_200002_seed_contact_types_seller_buyer_witness',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (217,'2026_03_07_200003_add_bank_details_to_contacts',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (218,'2026_03_07_200004_add_source_mapping_to_named_fields',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (219,'2026_03_09_111608_add_company_details_to_agencies_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (220,'2026_03_09_133314_add_contact_details_to_branches_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (221,'2026_03_09_141826_add_phone_secondary_to_agencies_and_branches_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (222,'2026_03_09_145301_add_phone_labels_to_agencies_and_branches_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (223,'2026_03_09_183429_rename_agency_brand_colours_to_semantic_roles',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (224,'2026_03_10_051542_add_render_type_to_docuperfect_templates_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (225,'2026_03_10_120000_add_web_template_data_to_docuperfect_documents',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (226,'2026_03_10_132257_create_web_packs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (227,'2026_03_10_132258_create_web_pack_items_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (228,'2026_03_10_140528_create_document_custom_fields_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (229,'2026_03_10_232742_add_theme_to_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (230,'2026_03_11_000001_add_is_esign_to_docuperfect_templates',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (231,'2026_03_11_100000_create_docuperfect_field_corrections_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (232,'2026_03_11_100001_add_soft_deletes_to_all_remaining_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (233,'2026_03_11_100002_add_soft_deletes_to_docuperfect_and_rental_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (234,'2026_03_11_120000_add_correction_reason_to_field_corrections',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (235,'2026_03_11_140000_create_docuperfect_import_drafts_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (236,'2026_03_11_141359_add_signing_parties_to_docuperfect_templates',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (237,'2026_03_12_175407_create_personal_access_tokens_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (238,'2026_03_13_100836_add_soft_deletes_to_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (239,'2026_03_13_101641_add_soft_deletes_to_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (240,'2026_03_13_120000_create_docuperfect_field_groups_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (241,'2026_03_13_130000_add_is_global_to_field_groups',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (242,'2026_03_16_100000_create_agency_signing_parties_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (243,'2026_03_16_110000_add_editor_state_to_docuperfect_templates',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (244,'2026_03_17_085414_add_deleted_at_to_contact_matches_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (245,'2026_03_17_100001_add_deal_to_named_fields_source_type',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (246,'2026_03_17_120000_add_header_display_to_docuperfect_templates',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (247,'2026_03_18_100000_add_email_disclaimer_to_agencies_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (248,'2026_03_18_100000_create_prospecting_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (249,'2026_03_18_120000_add_cross_portal_matching_to_prospecting_listings',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (250,'2026_03_18_140000_create_prospecting_claims_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (251,'2026_03_18_140001_add_first_seen_email_date_to_prospecting_listings',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (252,'2026_03_19_000001_add_party_mode_to_docuperfect_templates',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (253,'2026_03_19_000002_create_esign_signing_parties_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (254,'2026_03_19_000003_create_esign_consent_log_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (255,'2026_03_19_100000_create_contact_sources_tags_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (256,'2026_03_19_100001_add_cds_columns_to_docuperfect_templates',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (257,'2026_03_19_120000_add_date_fields_to_contacts_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (258,'2026_03_19_140000_add_contact_counters',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (259,'2026_03_19_160000_seed_prospecting_evaluation_permissions',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (260,'2026_03_20_100001_create_cds_drafts_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (261,'2026_03_20_120000_add_slot_columns_to_web_pack_items',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (262,'2026_03_22_184212_add_supervised_by_to_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (263,'2026_03_22_200000_add_columns_to_esign_consent_log',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (264,'2026_03_22_210000_create_signature_zones_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (265,'2026_03_22_220000_add_sections_to_docuperfect_templates',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (266,'2026_03_22_220001_create_section_acceptances_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (267,'2026_03_22_220002_add_deferred_partial_status_support',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (268,'2026_03_22_220003_seed_template_111_sections',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (269,'2026_03_22_230000_add_other_conditions_zone_type',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (270,'2026_03_22_230001_create_document_amendments_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (271,'2026_03_22_230002_create_amendment_acceptances_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (272,'2026_03_22_230003_add_pack_chaining_to_flows',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (273,'2026_03_22_240000_create_signed_document_versions_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (274,'2026_03_22_240001_create_document_contact_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (275,'2026_03_23_074727_expand_status_enums_for_esign_v2',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (276,'2026_03_23_100001_add_pp_syndication_columns_to_properties_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (277,'2026_03_23_100002_add_pp_suburb_id_and_coordinates_to_properties_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (278,'2026_03_23_140000_add_pp_visibility_and_rental_columns_to_properties_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (279,'2026_03_23_150000_add_pp_second_agent_id_to_properties_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (280,'2026_03_23_160000_add_address_detail_columns_to_properties_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (281,'2026_03_23_170000_create_property_showdays_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (282,'2026_03_23_181733_add_cancellation_fields_to_signature_templates_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (283,'2026_03_23_182523_add_cancelled_to_signature_status_enums',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (284,'2026_03_23_200000_add_authorised_columns_to_signature_requests',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (285,'2026_03_24_093448_add_listing_type_to_properties_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (286,'2026_03_24_094815_add_pricing_details_to_properties_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (287,'2026_03_24_100001_rename_splitter_doc_types_to_document_types',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (288,'2026_03_24_100002_add_columns_to_contact_documents_and_property_files',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (289,'2026_03_24_100003_repoint_docuperfect_templates_to_document_types',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (290,'2026_03_24_200001_create_unified_documents_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (291,'2026_03_24_200002_migrate_data_to_unified_documents',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (292,'2026_03_24_300001_add_category_to_docuperfect_templates',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (293,'2026_03_25_100209_create_notifications_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (294,'2026_03_26_100000_add_assigned_parties_and_soft_deletes_to_signature_zones',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (295,'2026_03_26_100000_create_fica_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (296,'2026_03_26_100001_add_p24_syndication_columns_to_properties_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (297,'2026_03_26_200000_create_fica_compliance_workflow',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (298,'2026_03_26_300000_add_fica_gate_to_signature_requests',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (299,'2026_03_27_100000_add_esign_role_to_contact_types',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (300,'2026_03_27_200000_create_fault_reports_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (301,'2026_03_27_300000_create_commission_engine_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (302,'2026_03_27_300001_add_commission_columns_to_users',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (303,'2026_03_27_400000_create_onboarding_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (304,'2026_03_27_500000_create_training_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (305,'2026_03_30_100000_create_deposit_trust_interest_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (306,'2026_03_30_100001_rename_property_status_items',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (307,'2026_03_30_100002_add_gallery_categories_to_properties',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (308,'2026_03_30_200000_create_deposit_interest_calculations_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (309,'2026_03_30_300001_create_deal_pipeline_templates_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (310,'2026_03_30_300002_create_deal_pipeline_steps_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (311,'2026_03_30_300003_create_deals_v2_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (312,'2026_03_30_300004_create_deal_v2_contacts_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (313,'2026_03_30_300005_create_deal_v2_agents_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (314,'2026_03_30_300006_create_deal_step_instances_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (315,'2026_03_30_300007_create_deal_step_documents_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (316,'2026_03_30_300008_create_deal_activity_log_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (317,'2026_03_30_400000_add_status_triggers_to_pipeline_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (318,'2026_03_30_500001_add_commission_columns_to_deals_v2',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (319,'2026_03_30_500002_rebuild_deal_v2_agents_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (320,'2026_03_30_500003_create_deal_v2_settlements_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (321,'2026_03_31_100000_backfill_null_contact_property_roles',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (322,'2026_03_31_200000_make_property_optional_columns_nullable',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (323,'2026_03_31_300001_create_calendar_events_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (324,'2026_03_31_300002_create_calendar_reminders_log_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (325,'2026_03_31_300003_create_calendar_user_preferences_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (326,'2026_03_31_300004_create_command_tasks_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (327,'2026_03_31_300005_create_automation_rules_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (328,'2026_03_31_300006_create_automation_log_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (329,'2026_03_31_300007_create_property_health_scores_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (330,'2026_03_31_300008_create_agent_scorecards_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (331,'2026_03_31_300009_create_command_document_expectations_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (332,'2026_03_31_300010_create_command_reminder_defaults_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (333,'2026_03_31_300011_add_last_activity_at_to_properties_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (334,'2026_03_31_400001_create_user_dashboard_settings_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (335,'2026_03_31_400002_add_event_reminder_hours_to_dashboard_settings',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (336,'2026_03_31_400003_add_resolution_to_tasks_and_events',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (337,'2026_03_31_400004_add_send_reminder_to_tasks_and_events',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (338,'2026_04_01_100001_add_listing_types_to_document_types',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (339,'2026_04_08_100000_add_pp_agent_image_columns_to_properties_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (340,'2026_04_09_100000_add_pp_unique_agent_id_to_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (341,'2026_04_09_100001_add_video_fields_to_properties_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (342,'2026_04_10_100000_add_property_created_index_to_p24_syndication_logs',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (343,'2026_04_14_000001_create_p24_import_runs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (344,'2026_04_14_000002_create_p24_import_rows_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (345,'2026_04_14_000003_add_p24_ids_to_users_and_properties',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (346,'2026_04_14_100000_add_agency_id_to_tenant_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (347,'2026_04_14_110000_detach_system_owners_from_agencies',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (348,'2026_04_14_120000_backfill_orphan_agency_ids',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (349,'2026_04_15_000001_create_p24_onboarding_portals_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (350,'2026_04_15_000002_add_portal_audit_to_p24_import_rows',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (351,'2026_04_15_000003_create_p24_portal_events_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (352,'2026_04_18_000001_add_slug_to_p24_onboarding_portals',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (353,'2026_04_18_000002_add_pet_friendly_to_properties_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (354,'2026_04_18_100001_add_auto_archive_done_to_dashboard_settings',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (355,'2026_04_18_120000_add_p24_agency_id_to_agencies_and_branches',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (356,'2026_04_21_000001_add_compliance_fields_to_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (357,'2026_04_21_000002_create_user_documents_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (358,'2026_04_21_000003_backfill_user_documents',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (359,'2026_04_21_100001_create_rmcp_versions_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (360,'2026_04_21_100002_create_rmcp_sections_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (361,'2026_04_21_100003_create_rmcp_variables_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (362,'2026_04_21_100004_create_rmcp_compliance_officers_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (363,'2026_04_21_110001_create_fica_officer_appointments_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (364,'2026_04_21_110002_migrate_fica_officers_to_appointments',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (365,'2026_04_21_120001_create_rmcp_acknowledgements_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (366,'2026_04_21_120002_create_rmcp_section_acknowledgements_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (367,'2026_04_21_121927_seed_fica_document_types_to_document_types_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (368,'2026_04_21_130001_create_employee_screenings_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (369,'2026_04_21_130002_create_employee_screening_checks_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (370,'2026_04_21_130003_add_screening_fields_to_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (371,'2026_04_21_130004_add_screening_document_types',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (372,'2026_04_21_140001_fix_rmcp_section_26_wording',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (373,'2026_04_21_140002_retire_placeholder_training_courses',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (374,'2026_04_21_150001_create_agency_compliance_provisions_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (375,'2026_04_21_150002_add_admin_upload_fields_to_user_documents',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (376,'2026_04_21_150003_create_user_compliance_overrides_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (377,'2026_04_21_184755_add_soft_deletes_to_branches_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (378,'2026_04_21_184800_add_split_branches_enabled_to_agencies_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (379,'2026_04_21_184900_add_branch_id_to_pillar_and_compliance_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (380,'2026_04_21_185000_create_deal_branches_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (381,'2026_04_21_185100_add_per_branch_syndication_columns_to_branches',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (382,'2026_04_22_080001_create_impersonation_logs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (383,'2026_04_22_090000_add_ppra_last_verified_at_to_users',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (384,'2026_04_22_090001_backfill_user_photos_to_user_documents',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (385,'2026_04_22_100000_fix_user_documents_agency_id',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (386,'2026_04_22_110000_add_wet_ink_fields_to_fica_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (387,'2026_04_22_110001_make_fica_submission_token_nullable',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (388,'2026_04_22_110002_add_cancelled_fica_status_and_resend_logs',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (389,'2026_04_22_131821_create_agency_document_type_configs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (390,'2026_04_22_133650_add_document_type_config_to_agency_compliance_provisions',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (391,'2026_04_22_135334_add_branch_id_to_agency_compliance_provisions',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (392,'2026_04_22_135335_drop_allows_branch_override_from_agency_document_type_configs',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (393,'2026_04_23_100001_add_payroll_fields_to_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (394,'2026_04_23_100002_create_user_banking_details_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (395,'2026_04_23_100003_add_payroll_fields_to_agencies_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (396,'2026_04_23_100004_create_payroll_tax_tables_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (397,'2026_04_23_100005_create_payroll_tax_rebates_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (398,'2026_04_23_100006_create_payroll_earning_types_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (399,'2026_04_23_100007_create_payroll_deduction_types_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (400,'2026_04_23_100008_create_payroll_employees_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (401,'2026_04_23_100009_create_payroll_employee_earnings_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (402,'2026_04_23_100010_create_payroll_employee_deductions_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (403,'2026_04_23_100011_create_payroll_runs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (404,'2026_04_23_100012_create_payroll_payslips_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (405,'2026_04_23_100013_create_payroll_payslip_lines_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (406,'2026_04_23_100014_add_payslip_to_user_documents_enum',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (407,'2026_04_27_000001_add_oversight_scope_to_roles_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (408,'2026_04_27_000002_create_user_oversight_preferences_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (409,'2026_04_27_000003_create_oversight_nudges_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (410,'2026_04_27_100001_create_notification_event_types_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (411,'2026_04_27_100002_create_user_notification_preferences_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (412,'2026_04_27_100003_create_notification_dispatch_log_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (413,'2026_04_27_100004_create_device_tokens_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (414,'2026_04_28_100000_create_pp_event_feed_settings_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (415,'2026_04_28_100001_extend_contact_matches',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (416,'2026_04_28_100002_create_contact_match_feedback_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (417,'2026_04_28_100003_create_contact_match_notifications_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (418,'2026_04_28_120000_add_share_slug_to_contact_matches',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (419,'2026_04_28_120000_add_virtual_tour_url_to_properties',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (420,'2026_04_28_130000_add_gallery_custom_tags_to_properties',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (421,'2026_04_29_000001_create_leave_types_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (422,'2026_04_29_000002_create_leave_entitlements_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (423,'2026_04_29_000003_create_leave_applications_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (424,'2026_04_29_000004_create_leave_application_documents_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (425,'2026_04_29_000005_create_leave_transactions_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (426,'2026_04_29_000006_create_public_holidays_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (427,'2026_04_29_000007_create_staff_take_on_records_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (428,'2026_04_29_000008_add_leave_columns_to_payroll_employees_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (429,'2026_04_29_000009_add_leave_columns_to_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (430,'2026_04_29_120000_add_pp_external_ref_to_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (431,'2026_04_29_123451_rebuild_template_116_marketing_permission_v11',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (432,'2026_04_30_140425_add_fica_expires_at_to_fica_submissions_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (433,'2026_04_30_142935_drop_command_reminder_defaults_and_create_calendar_event_class_settings_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (434,'2026_05_02_104539_make_calendar_events_user_id_nullable',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (435,'2026_05_04_100000_sync_notification_event_types_catalog',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (436,'2026_05_04_193122_create_command_task_notes_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (437,'2026_05_05_000001_create_calendar_event_links_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (438,'2026_05_05_000002_backfill_demo_calendar_event_links',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (439,'2026_05_05_000004_create_agency_feedback_options_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (440,'2026_05_05_000005_create_calendar_event_feedback_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (441,'2026_05_05_000006_create_calendar_event_audit_log_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (442,'2026_05_05_000007_backfill_demo_feedback',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (443,'2026_05_05_000008_backfill_manual_event_links',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (444,'2026_05_05_000009_rename_valuation_to_property_evaluation',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (445,'2026_05_05_000010_add_event_nature_to_calendar_event_class_settings',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (446,'2026_05_05_000011_create_agency_contact_settings_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (447,'2026_05_05_000012_create_agency_leave_visibility_matrix_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (448,'2026_05_05_000013_backfill_branch_id_on_contacts_and_properties',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (449,'2026_05_05_000014_add_default_branch_id_to_agencies',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (450,'2026_05_05_000015_make_branch_id_not_null_on_contacts_and_properties',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (451,'2026_05_05_000016_expand_duplicate_mode_and_create_duplicate_log',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (452,'2026_05_05_000017_create_contact_access_log_and_consent_records',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (453,'2026_05_05_000018_add_channel_opt_out_columns_to_contacts',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (454,'2026_05_05_000019_multi_property_events_support',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (455,'2026_05_05_000020_buyer_crm_foundation',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (456,'2026_05_05_000021_add_buyer_facing_to_calendar_event_class_settings',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (457,'2026_05_06_000001_add_actor_role_and_completion_to_class_settings',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (458,'2026_05_06_000002_create_property_recommendations_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (459,'2026_05_06_000003_create_property_presentation_snapshots_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (460,'2026_05_06_000004_add_seller_visible_to_property_recommendations',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (461,'2026_05_06_000005_create_buyer_preferences_and_risk_scores',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (462,'2026_05_06_000006_add_retention_action_to_buyer_activity_log',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (463,'2026_05_06_000007_create_seller_live_link_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (464,'2026_05_06_000008_create_buyer_matching_engine_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (465,'2026_05_06_000009_drop_old_feedback_unique_index',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (466,'2026_05_06_000010_create_lost_deal_module_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (467,'2026_05_06_000011_add_recovered_columns_to_buyer_lost_records',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (468,'2026_05_06_000012_create_property_sold_records_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (469,'2026_05_06_000013_create_calendar_event_invitations_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (470,'2026_05_06_000014_create_feedback_reports_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (471,'2026_05_06_000015_add_feedback_recipients_to_agencies',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (472,'2026_05_06_000016_add_buyer_pipeline_scope_and_deprecate_sharing_mode',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (473,'2026_05_06_000017_create_prospecting_buyer_matches_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (474,'2026_05_07_172111_add_is_demo_to_agencies_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (475,'2026_05_07_174002_add_require_external_access_authorization_to_agencies',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (476,'2026_05_07_174003_create_agency_access_requests_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (477,'2026_05_09_120001_create_client_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (478,'2026_05_09_120002_add_client_user_id_to_contacts',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (479,'2026_05_09_120003_create_client_otps_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (480,'2026_05_09_120004_create_client_access_logs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (481,'2026_05_09_120005_create_client_signin_attempts_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (482,'2026_05_11_081126_add_acknowledged_at_to_calendar_event_invitations',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (483,'2026_05_11_094044_add_feedback_mode_and_visibility_columns',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (484,'2026_05_11_105415_add_compliance_columns_to_properties',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (485,'2026_05_11_105419_create_marketing_share_log_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (486,'2026_05_11_132238_create_property_audit_log_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (487,'2026_05_11_135612_create_whistleblow_complaints_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (488,'2026_05_11_135613_create_whistleblow_complaint_evidence_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (489,'2026_05_11_135614_create_whistleblow_audit_log_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (490,'2026_05_11_135615_add_whistleblow_columns_to_agencies',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (491,'2026_05_11_135616_add_compliance_evidence_flags_to_properties',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (492,'2026_05_12_083334_create_whistleblow_complaint_subjects_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (493,'2026_05_12_083335_backfill_whistleblow_subjects_from_complaints',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (494,'2026_05_12_083336_drop_subject_columns_from_whistleblow_complaints',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (495,'2026_05_12_090643_replace_ppra_recipient_with_tier_recipients_on_agencies',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (496,'2026_05_12_090644_create_whistleblow_email_log_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (497,'2026_05_12_091937_create_seller_info_share_links_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (498,'2026_05_12_091938_make_complaint_id_nullable_on_whistleblow_email_log',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (499,'2026_05_12_105607_add_matched_property_id_to_prospecting_listings',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (500,'2026_05_12_111831_add_indexes_to_docuperfect_documents',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (501,'2026_05_12_160000_create_training_help_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (502,'2026_05_12_170000_add_created_by_agency_id_to_client_users',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (503,'2026_05_12_180000_add_qr_code_slug_to_users',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (504,'2026_05_13_100001_add_preapproval_to_contacts_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (505,'2026_05_13_100002_extend_contact_matches_for_unification',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (506,'2026_05_13_100003_add_agency_id_to_prospecting_buyer_matches',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (507,'2026_05_13_100004_add_agency_id_to_property_buyer_matches',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (508,'2026_05_13_120001_create_wishlist_migration_log_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (509,'2026_05_13_140001_create_domain_event_log_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (510,'2026_05_13_150001_add_p24_credentials_to_agencies',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (511,'2026_05_13_150001_create_towns_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (512,'2026_05_13_150002_create_p24_location_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (513,'2026_05_13_150002_create_town_suburbs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (514,'2026_05_13_150003_add_p24_location_refs_to_properties',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (515,'2026_05_13_150003_create_property_type_options_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (516,'2026_05_13_150004_create_bedroom_segments_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (517,'2026_05_13_150004_drop_unique_slug_on_p24_suburbs',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (518,'2026_05_13_150005_create_price_bands_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (519,'2026_05_13_150005_flag_p24_suburb_mismatches',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (520,'2026_05_14_080001_create_seller_outreach_templates_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (521,'2026_05_14_080002_create_seller_outreach_sends_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (522,'2026_05_14_080003_create_seller_outreach_clicks_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (523,'2026_05_14_080004_add_messaging_opt_out_to_contacts_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (524,'2026_05_14_090001_create_contact_outreach_log_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (525,'2026_05_14_100001_create_seller_outreach_callbacks_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (526,'2026_05_14_120001_add_whatsapp_launch_mode_to_agencies_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (527,'2026_05_14_130001_normalize_property_types',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (528,'2026_05_14_131648_create_suggested_action_thresholds_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (529,'2026_05_14_140000_add_market_intelligence_columns_to_properties',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (530,'2026_05_14_140001_create_dev_settings_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (531,'2026_05_14_150000_create_buyer_match_tiers_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (532,'2026_05_14_160000_add_prospecting_pitch_lock_to_agencies',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (533,'2026_05_14_160001_create_prospecting_pitch_locks_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (534,'2026_05_14_170000_create_tracked_properties_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (535,'2026_05_14_170001_create_tracked_property_external_refs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (536,'2026_05_14_180000_add_tracked_property_id_to_listings_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (537,'2026_05_17_120000_add_qr_reroute_user_id_to_users',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (538,'2026_05_20_000001_create_portal_leads_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (539,'2026_05_20_000002_grant_portal_leads_access_to_all_roles',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (540,'2026_05_20_100001_add_p24_suburb_ids_to_contact_matches',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (541,'2026_05_21_220001_create_legal_block_audit_log_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (542,'2026_05_21_220002_classify_otp_templates',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (543,'2026_05_22_100000_add_hidden_property_reasons_to_contact_matches_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (544,'2026_05_23_000001_wave3b_backfill_orphan_agency_ids',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (545,'2026_05_23_010100_add_agency_id_to_deal_logs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (546,'2026_05_23_010200_add_agency_id_to_deal_money_lines_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (547,'2026_05_23_010300_add_agency_id_to_deal_settlements_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (548,'2026_05_23_010400_add_agency_id_to_deals_v2_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (549,'2026_05_23_010500_add_agency_id_to_deal_activity_log_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (550,'2026_05_23_010600_add_agency_id_to_deal_pipeline_templates_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (551,'2026_05_23_010700_add_agency_id_to_deal_pipeline_steps_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (552,'2026_05_23_010800_add_agency_id_to_deal_step_instances_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (553,'2026_05_23_010900_add_agency_id_to_deal_step_documents_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (554,'2026_05_23_011000_add_agency_id_to_deal_v2_settlements_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (555,'2026_05_23_020100_add_agency_id_to_property_files_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (556,'2026_05_23_020200_add_agency_id_to_property_notes_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (557,'2026_05_23_020300_add_agency_id_to_property_showdays_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (558,'2026_05_23_020400_add_agency_id_to_property_marketing_activities_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (559,'2026_05_23_020500_add_agency_id_to_property_marketing_posts_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (560,'2026_05_23_020600_add_agency_id_to_property_presentation_snapshots_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (561,'2026_05_23_020700_add_agency_id_to_property_seller_links_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (562,'2026_05_23_020800_add_agency_id_to_property_seller_link_accesses_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (563,'2026_05_23_020900_add_agency_id_to_property_ad_templates_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (564,'2026_05_23_021000_add_agency_id_to_property_health_scores_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (565,'2026_05_23_030100_add_agency_id_to_contact_notes_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (566,'2026_05_23_030200_add_agency_id_to_contact_documents_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (567,'2026_05_23_030300_add_agency_id_to_contact_match_feedback_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (568,'2026_05_23_030400_add_agency_id_to_contact_match_notifications_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (569,'2026_05_23_030500_add_agency_id_to_buyer_property_views_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (570,'2026_05_23_030600_add_agency_id_to_buyer_property_responses_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (571,'2026_05_23_030700_add_agency_id_to_buyer_preferences_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (572,'2026_05_23_030800_add_agency_id_to_buyer_state_transitions_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (573,'2026_05_23_030900_add_agency_id_to_buyer_lost_risk_scores_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (574,'2026_05_23_031000_add_agency_id_to_buyer_portal_links_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (575,'2026_05_23_040100_add_agency_id_to_presentation_active_listings_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (576,'2026_05_23_040200_add_agency_id_to_presentation_articles_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (577,'2026_05_23_040300_add_agency_id_to_presentation_document_library_items_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (578,'2026_05_23_040400_add_agency_id_to_presentation_fields_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (579,'2026_05_23_040500_add_agency_id_to_presentation_links_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (580,'2026_05_23_040600_add_agency_id_to_presentation_listing_price_history_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (581,'2026_05_23_040700_add_agency_id_to_presentation_sections_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (582,'2026_05_23_040800_add_agency_id_to_presentation_snapshots_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (583,'2026_05_23_040900_add_agency_id_to_presentation_sold_comps_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (584,'2026_05_23_041000_add_agency_id_to_presentation_uploads_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (585,'2026_05_23_050100_add_agency_id_to_presentation_url_snapshots_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (586,'2026_05_23_050200_add_agency_id_to_presentation_versions_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (587,'2026_05_23_050300_add_agency_id_to_worksheets_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (588,'2026_05_23_050400_add_agency_id_to_targets_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (589,'2026_05_23_050500_add_agency_id_to_monthly_target_goals_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (590,'2026_05_23_050600_add_agency_id_to_listing_targets_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (591,'2026_05_23_050700_add_agency_id_to_tool_history_entries_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (592,'2026_05_23_050800_add_agency_id_to_daily_activities_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (593,'2026_05_23_050900_add_agency_id_to_daily_activity_entries_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (594,'2026_05_23_051000_add_agency_id_to_agent_scorecards_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (595,'2026_05_23_060100_add_agency_id_to_listing_stocks_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (596,'2026_05_23_060200_add_agency_id_to_listing_import_runs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (597,'2026_05_23_060300_add_agency_id_to_listing_import_rows_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (598,'2026_05_23_060400_add_agency_id_to_listing_snapshots_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (599,'2026_05_23_060500_add_agency_id_to_market_analytics_runs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (600,'2026_05_23_060600_add_agency_id_to_sale_probability_runs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (601,'2026_05_23_060700_add_agency_id_to_revenue_share_ledger_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (602,'2026_05_23_060800_add_agency_id_to_agent_mentors_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (603,'2026_05_23_060900_add_agency_id_to_agent_sponsorships_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (604,'2026_05_23_061000_add_agency_id_to_agent_social_accounts_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (605,'2026_05_23_070100_add_agency_id_to_commercial_evaluations_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (606,'2026_05_23_070200_add_agency_id_to_commercial_evaluation_assets_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (607,'2026_05_23_070300_add_agency_id_to_commercial_evaluation_comparables_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (608,'2026_05_23_070400_add_agency_id_to_commercial_evaluation_crops_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (609,'2026_05_23_070500_add_agency_id_to_commercial_evaluation_financials_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (610,'2026_05_23_070600_add_agency_id_to_commercial_evaluation_livestock_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (611,'2026_05_23_070700_add_agency_id_to_commercial_evaluation_units_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (612,'2026_05_23_070800_add_agency_id_to_finance_audit_runs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (613,'2026_05_23_070900_add_agency_id_to_finance_audit_items_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (614,'2026_05_23_071000_add_agency_id_to_finance_computed_values_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (615,'2026_05_23_080100_add_agency_id_to_calendar_event_audit_log_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (616,'2026_05_23_080200_add_agency_id_to_calendar_event_invitations_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (617,'2026_05_23_080300_add_agency_id_to_calendar_event_links_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (618,'2026_05_23_080400_add_agency_id_to_calendar_reminders_log_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (619,'2026_05_23_080500_add_agency_id_to_branch_settings_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (620,'2026_05_23_080600_add_agency_id_to_branch_activity_columns_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (621,'2026_05_23_080700_add_agency_id_to_fault_reports_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (622,'2026_05_23_080800_add_agency_id_to_contact_sources_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (623,'2026_05_23_080900_add_agency_id_to_contact_tags_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (624,'2026_05_23_081000_add_agency_id_to_property_setting_items_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (625,'2026_05_23_090100_add_agency_id_to_document_filing_register_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (626,'2026_05_23_090200_add_agency_id_to_document_library_items_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (627,'2026_05_23_090300_add_agency_id_to_fica_documents_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (628,'2026_05_23_090400_add_agency_id_to_fica_resend_logs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (629,'2026_05_23_090500_add_agency_id_to_rmcp_sections_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (630,'2026_05_23_090600_add_agency_id_to_rmcp_section_acknowledgements_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (631,'2026_05_23_090700_add_agency_id_to_employee_screening_checks_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (632,'2026_05_23_090800_add_agency_id_to_whistleblow_email_log_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (633,'2026_05_23_090900_add_agency_id_to_p24_listings_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (634,'2026_05_23_091000_add_agency_id_to_p24_import_log_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (635,'2026_05_19_120000_seed_esign_deal_named_fields',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (636,'2026_05_19_140000_add_signed_paginated_html_to_docuperfect_documents',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (637,'2026_05_20_000001_add_feedback_captured_to_buyer_activity_log_enum',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (638,'2026_05_21_120001_create_tracked_property_addresses_table',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (639,'2026_05_21_120002_create_market_report_types_table',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (640,'2026_05_21_120003_create_market_reports_table',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (641,'2026_05_21_120004_create_market_data_points_table',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (642,'2026_05_21_120005_create_market_data_discrepancies_table',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (643,'2026_05_21_120006_create_ai_narrative_cache_table',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (644,'2026_05_21_120007_create_agent_activity_events_table',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (645,'2026_05_21_120008_add_agency_id_to_p24_listings_table',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (646,'2026_05_21_120009_backfill_agency_id_on_p24_listings',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (647,'2026_05_21_120010_make_p24_listings_agency_id_not_null',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (648,'2026_05_21_120011_add_agency_id_to_presentations_table',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (649,'2026_05_21_120012_backfill_agency_id_on_presentations',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (650,'2026_05_21_120013_make_presentations_agency_id_not_null',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (651,'2026_05_21_120014_add_identifier_columns_to_properties_table',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (652,'2026_05_21_120015_backfill_property_identifiers_from_tracked_properties',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (653,'2026_05_21_120016_fix_prospecting_listings_null_addresses',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (654,'2026_05_21_120017_spatial_index_on_tracked_properties_geo',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (655,'2026_05_21_130001_seed_mic_permissions',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (656,'2026_05_21_140001_relax_agent_activity_events_user_id_nullable',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (657,'2026_05_21_150001_relax_agent_activity_events_agency_id_nullable',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (658,'2026_05_21_160001_add_ai_budget_to_agencies',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (659,'2026_05_21_160002_add_soft_deletes_to_ai_narrative_cache',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (660,'2026_05_21_170001_backfill_tracked_property_addresses',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (661,'2026_05_22_010001_create_document_conditions_tables',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (662,'2026_05_22_010002_amended_by_request_nullable_on_document_amendments',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (663,'2026_05_22_010003_add_amendment_initialing_to_signature_template_status',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (664,'2026_05_22_020001_extend_amendments_for_flags',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (665,'2026_05_22_120001_backfill_legacy_other_conditions',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (666,'2026_05_22_140001_add_relates_to_clause_ref_to_conditions',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (667,'2026_05_23_080001_add_pillar_fks_to_presentations',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (668,'2026_05_23_100001_add_presentation_settings_to_agency',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (669,'2026_05_23_100001_create_flag_removal_requests_table',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (670,'2026_05_23_120001_add_subject_geo_to_market_reports',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (671,'2026_05_23_120002_create_market_report_comp_rows_table',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (672,'2026_05_23_120003_create_scheme_owners_table',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (673,'2026_05_23_140001_add_comp_scope_to_agencies',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (674,'2026_05_23_140002_add_comp_scope_to_presentations',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (675,'2026_05_23_160001_add_hydration_summary_to_presentation_versions',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (676,'2026_05_23_180001_add_holding_cost_defaults_to_agencies',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (677,'2026_05_24_080001_create_geocoding_cache_table',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (678,'2026_05_24_080002_create_geocoding_runs_table',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (679,'2026_05_24_080003_add_geo_source_to_properties_and_tracked',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (680,'2026_05_25_080001_add_geo_index_to_properties',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (681,'2026_05_25_120000_add_portal_visibility_prefs_to_users_table',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (682,'2026_05_26_080001_add_is_demo_to_spatial_tables',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (683,'2026_05_27_080001_create_presentation_snapshot_links_table',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (684,'2026_05_27_080002_create_presentation_snapshot_views_table',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (685,'2026_05_27_080003_add_snapshot_link_settings_to_agencies',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (686,'2026_05_28_080001_create_presentation_teaser_leads_table',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (687,'2026_05_28_080002_add_teaser_lead_id_to_presentation_snapshot_views',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (688,'2026_05_28_080003_add_teaser_section_toggles_to_agencies',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (689,'2026_05_28_100001_create_pp_locations_tables',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (690,'2026_05_28_100002_add_pp_locations_sync_to_agencies',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (691,'2026_05_28_120000_add_pp_syndication_columns_to_agencies',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (692,'2026_05_28_140000_add_mark_compliant_on_confirm_to_p24_import_runs',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (693,'2026_05_28_180001_add_ai_attribution_to_calendar_events',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (694,'2026_05_28_180002_add_features_json_meta_to_properties',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (695,'2026_05_28_180003_create_property_image_analyses_table',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (696,'2026_05_28_180004_add_ai_feature_flags_to_agencies',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (697,'2026_05_29_080001_create_presentation_deliveries_table',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (698,'2026_05_29_080002_add_presentation_send_defaults_to_users',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (699,'2026_05_29_080003_add_delivery_templates_to_agencies',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (700,'2026_05_29_100000_add_open_hours_and_push_master_to_dashboard_settings',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (701,'2026_05_30_080001_create_presentation_ai_variants_table',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (702,'2026_05_30_080002_add_ai_summary_to_presentation_versions',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (703,'2026_05_30_080003_create_presentation_ai_summary_history_table',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (704,'2026_05_31_080001_extend_snapshot_links_for_refresh_phase7',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (705,'2026_05_31_080002_add_staleness_days_to_agencies',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (706,'2026_05_31_080003_create_presentation_refresh_requests_table',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (707,'2026_06_01_080001_create_presentation_outcomes_table',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (708,'2026_06_01_080002_create_presentation_outcome_prompts_table',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (709,'2026_06_01_201014_add_competitor_stock_min_same_type_to_agencies',3);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (710,'2026_06_01_204407_add_competitor_stock_default_display_count_to_agencies',3);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (711,'2026_06_01_211936_add_geo_columns_to_prospecting_listings_and_map_provider_to_agencies',3);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (712,'2026_06_02_080001_add_property_link_and_sale_columns_to_deals',3);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (713,'2026_06_02_080002_create_deal_link_review_queue_table',3);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (714,'2026_06_02_100001_create_agency_api_keys_table',3);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (715,'2026_06_02_100002_create_agency_webhook_deliveries_table',3);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (716,'2026_06_02_100003_create_property_website_syndication_table',3);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (717,'2026_06_02_100004_add_website_fields_to_agencies_table',3);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (718,'2026_06_02_100005_add_show_on_website_to_users_table',3);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (719,'2026_06_03_080001_add_sg_columns_to_properties',3);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (720,'2026_06_03_080002_create_property_sg_documents_table',3);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (721,'2026_06_03_080003_create_sg_search_cache_table',3);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (722,'2026_06_04_080001_phase9a_harden_outcome_fk_and_delivery_index',3);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (723,'2026_06_05_080001_create_rcr_questionnaires_table',3);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (724,'2026_06_05_080002_create_rcr_questionnaire_sections_table',3);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (725,'2026_06_05_080003_create_rcr_questions_table',3);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (726,'2026_06_05_080004_create_rcr_submissions_table',3);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (727,'2026_06_05_080005_create_rcr_answers_table',3);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (728,'2026_06_05_080006_create_rcr_answer_evidence_table',3);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (729,'2026_06_05_080007_create_rcr_submission_snapshots_table',3);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (730,'2026_06_06_080001_add_period_and_clipboard_tracking_to_rcr_tables',3);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (731,'2026_06_07_080001_extend_geocoding_cache_phase11a',3);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (732,'2026_06_10_120000_add_id_number_audit_to_contacts',3);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (733,'2026_06_15_120000_create_map_saved_searches_table',3);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (734,'2026_06_16_120000_add_ppra_number_to_agencies_and_branches',3);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (735,'2026_06_16_120100_create_information_officer_appointments_table',3);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (736,'2026_06_16_120300_add_module_6_activity_points_columns',3);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (737,'2026_06_16_120400_create_activity_definition_calendar_classes',3);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (738,'2026_06_16_120500_rollback_phase_9c3_company_documents',3);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (739,'2026_06_16_120600_add_privacy_policy_fields_to_agencies_and_branches',3);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (740,'2026_06_16_120700_add_role_index_to_signature_requests',3);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (741,'2026_06_16_121000_add_tp_outreach_columns',3);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (742,'2026_06_16_122000_fix_market_report_cascade_to_preserve_audit',3);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (743,'2026_06_16_122100_seed_mic_restore_reports_permission',3);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (744,'2026_06_16_122200_seed_cma_info_vicinity_sale_type',3);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (745,'2026_06_16_122300_make_market_reports_report_type_id_nullable',3);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (746,'2026_06_16_122400_add_normalised_address_columns_to_properties',3);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (747,'2026_06_16_122500_add_mic_comp_row_id_fk_to_presentation_tables',3);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (748,'2026_06_17_100000_add_title_type_to_property_setting_items',3);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (749,'2026_06_17_110000_add_review_flow_to_presentations',3);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (750,'2026_06_17_120000_add_condition_levels_to_presentations',3);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (751,'2026_06_17_130000_add_section_toggles_to_presentations',3);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (752,'2026_06_17_140000_add_snapshot_payload_to_presentations',3);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (753,'2026_06_17_150000_add_title_type_to_properties',3);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (754,'2026_06_17_160000_add_cma_compute_settings_to_agencies',3);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (755,'2026_06_19_120000_add_competitor_stock_settings_to_agencies',3);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (756,'2026_06_19_120100_add_included_competitor_ids_to_presentation_versions',3);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (757,'2026_06_19_140000_create_holding_cost_data_points_table',3);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (758,'2026_06_19_140100_add_freehold_holding_defaults_to_agencies',3);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (759,'2026_06_19_140200_add_freehold_monthly_to_presentations',3);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (760,'2026_06_02_101001_add_website_agent_order_to_agencies_table',4);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (761,'2026_06_02_101002_add_website_order_to_users_table',4);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (762,'2026_06_20_120000_seed_hfc_activity_calendar_mappings',4);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (763,'2026_06_20_140000_relax_daily_activity_unique_for_calendar_events',4);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (764,'2026_06_20_160000_extend_activity_actions_for_instant',4);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (765,'2026_06_20_160100_make_event_class_nullable_for_instant_rows',4);
