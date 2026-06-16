/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
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
  `reg_no` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `vat_no` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ffc_no` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ppra_number` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `public_contact` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ppra_registered_at` date DEFAULT NULL,
  `fic_no` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `p24_agency_id` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `p24_agency_label` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `p24_username` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `p24_password` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `p24_user_group_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `p24_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `p24_locations_synced_at` timestamp NULL DEFAULT NULL,
  `p24_last_sync_error` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `pp_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `pp_username` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pp_password` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `pp_branch_guid` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pp_wsdl` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pp_sandbox` tinyint(1) NOT NULL DEFAULT '1',
  `pp_image_base_url` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pp_webhook_secret` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `pp_last_sync_error` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `slug` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `sidebar_color` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '#0ea5e9',
  `icon_color` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '#0ea5e9',
  `default_color` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '#0b2a4a',
  `button_color` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '#0ea5e9',
  `ai_voice_enabled` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Allow agents at this agency to use Ellie voice commands (advanced feature)',
  `ai_image_recognition_enabled` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Allow agents at this agency to use AI property image recognition (advanced feature, mobile-only)',
  `logo_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email_disclaimer` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `marketing_unsubscribe_footer` text COLLATE utf8mb4_unicode_ci,
  `popi_url` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `privacy_policy_markdown` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `privacy_policy_token` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `privacy_policy_published_at` timestamp NULL DEFAULT NULL,
  `whatsapp_launch_mode_agent` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'whatsapp_web',
  `whatsapp_launch_mode_seller` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'whatsapp_web',
  `ai_monthly_budget_zar` decimal(10,2) NOT NULL DEFAULT '1000.00',
  `ai_budget_warning_pct` tinyint unsigned NOT NULL DEFAULT '80',
  `ai_budget_hard_cap_pct` tinyint unsigned NOT NULL DEFAULT '110',
  `ai_budget_overage_allowed` tinyint(1) NOT NULL DEFAULT '0',
  `ai_budget_last_warned_at` timestamp NULL DEFAULT NULL,
  `ai_budget_last_hard_stopped_at` timestamp NULL DEFAULT NULL,
  `prospecting_pitch_temp_lock_minutes` smallint unsigned NOT NULL DEFAULT '30',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `is_demo` tinyint(1) NOT NULL DEFAULT '0',
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
  `comp_radius_widen_steps` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT '300,600,1000,1500,3000' COMMENT 'AT-22 §1 — CSV widen ladder for the radius when comps are thin. Null → constant ladder.',
  `comp_radius_max_m` smallint unsigned DEFAULT '3000' COMMENT 'AT-22 §1 — hard ceiling for the radius widen ladder. Default 3000 (rural mandates must resolve). Null → constant.',
  `comp_min_count` smallint unsigned DEFAULT '5' COMMENT 'AT-22 §1 — minimum comps before the widen ladder stops expanding. Null → constant.',
  `comp_max_count` smallint unsigned DEFAULT '15' COMMENT 'AT-22 §1 — max comps shortlisted after ranking (PRES 87 curated 13; do not force-drop). Null → constant.',
  `anchor_divergence_pct` decimal(5,2) DEFAULT '25.00' COMMENT 'AT-22 §1.5 — widen the radius when the cleaned-pool estimate diverges from the vicinity average by more than this %. Null → constant.',
  `range_lower_pct` tinyint unsigned DEFAULT '25' COMMENT 'AT-22 §5 — lower percentile for the recommended range. Default 25 (P25). Null → constant.',
  `range_upper_pct` tinyint unsigned DEFAULT '75' COMMENT 'AT-22 §5 — upper percentile for the recommended range. Default 75 (P75). Null → constant.',
  `cma_band_lower_pct` decimal(5,2) DEFAULT '10.00' COMMENT 'PRES-CMA-REALFIX — recommended-band LOWER half-width: lower = middle × (1 − pct/100). Null → constant 7.',
  `cma_band_upper_pct` decimal(5,2) DEFAULT '13.00' COMMENT 'PRES-CMA-REALFIX — recommended-band UPPER half-width: upper = middle × (1 + pct/100). Null → constant 7.',
  `competitor_stock_default_beds_tolerance` tinyint unsigned NOT NULL DEFAULT '1' COMMENT 'Competitor Stock — ± beds window for synthetic ContactMatch (Core Matches scorer).',
  `competitor_stock_default_price_tolerance_pct` tinyint unsigned NOT NULL DEFAULT '20' COMMENT 'Competitor Stock — ± percent price band for synthetic match (e.g. 20 = ±20%).',
  `competitor_stock_min_score` tinyint unsigned NOT NULL DEFAULT '50' COMMENT 'Competitor Stock — minimum match score (Core Matches 0-100) to include in section. 50 = Approximate tier floor.',
  `presentations_default_comp_scope` enum('radius_all','suburb_only') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'radius_all',
  `presentations_default_radius_m` smallint unsigned NOT NULL DEFAULT '1000',
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
  `whistleblow_compliance_officer_email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `whistleblow_tier_recipients` json DEFAULT NULL,
  `pp_locations_synced_at` timestamp NULL DEFAULT NULL,
  `pp_locations_last_error` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `competitor_stock_min_same_type` tinyint unsigned NOT NULL DEFAULT '5' COMMENT 'Competitor Stock — minimum exact-property-type matches before stepping up to same-family-other-type. Level 1 (FH/SS) is never crossed.',
  `competitor_stock_default_display_count` tinyint unsigned NOT NULL DEFAULT '10' COMMENT 'Competitor Stock — top-N display cap on the review screen + auto-tick floor. Rest live in the manual-picker modal.',
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
  `duplicate_mode` enum('auto_link','soft_warn','hard_block_override','hard_block_request') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'soft_warn',
  `duplicate_match_fields` json DEFAULT NULL,
  `buyer_warm_days` int unsigned NOT NULL DEFAULT '14',
  `buyer_cold_days` int unsigned NOT NULL DEFAULT '30',
  `buyer_lost_days` int unsigned NOT NULL DEFAULT '60',
  `contact_retention_years` int unsigned NOT NULL DEFAULT '5',
  `consent_retention_years` int unsigned NOT NULL DEFAULT '5',
  `access_log_retention_years` int unsigned NOT NULL DEFAULT '5',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `agency_contact_settings_agency_id_unique` (`agency_id`),
  CONSTRAINT `agency_contact_settings_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
DROP TABLE IF EXISTS `agency_document_type_configs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `agency_document_type_configs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
DROP TABLE IF EXISTS `agency_policies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `agency_policies` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `policy_key` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Append-only agent activity log. Morphable subject. No updated_at.';
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
  `override_type` enum('comp_excluded','comp_included','category_added','category_removed','condition_changed','section_toggled','field_edited','review_takeover','comp_unavailable','comp_bulk_set','comp_added') COLLATE utf8mb4_unicode_ci NOT NULL,
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
  `reason` enum('auto_recompute','manual_override','first_activity') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
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
  `allow_multiple_properties` tinyint(1) NOT NULL DEFAULT '0',
  `buyer_facing` tinyint(1) NOT NULL DEFAULT '0',
  `actor_role` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'neither',
  `completion_behaviour` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'freeform',
  `feedback_mode` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'per_contact',
  `label` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
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
  `agency_id` bigint unsigned NOT NULL,
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
  CONSTRAINT `calendar_event_invitations_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
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
  `agency_id` bigint unsigned NOT NULL,
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
  CONSTRAINT `calendar_event_links_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
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
  `sent_at` datetime NOT NULL,
  `read_at` datetime DEFAULT NULL,
  `actioned_at` datetime DEFAULT NULL,
  `escalated` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
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
  `extraction_verification` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `cds_drafts_user_id_status_index` (`user_id`,`status`),
  CONSTRAINT `cds_drafts_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cds_extraction_flags`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cds_extraction_flags` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `cds_draft_id` bigint unsigned DEFAULT NULL,
  `template_id` bigint unsigned DEFAULT NULL,
  `severity` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'high',
  `divergence_type` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'unknown',
  `location` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_snippet` text COLLATE utf8mb4_unicode_ci,
  `extracted_snippet` text COLLATE utf8mb4_unicode_ci,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `resolution_note` text COLLATE utf8mb4_unicode_ci,
  `resolved_by` bigint unsigned DEFAULT NULL,
  `resolved_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `cds_flags_gate_idx` (`template_id`,`severity`,`status`),
  KEY `cds_extraction_flags_resolved_by_foreign` (`resolved_by`),
  KEY `cds_extraction_flags_cds_draft_id_index` (`cds_draft_id`),
  KEY `cds_extraction_flags_template_id_index` (`template_id`),
  CONSTRAINT `cds_extraction_flags_cds_draft_id_foreign` FOREIGN KEY (`cds_draft_id`) REFERENCES `cds_drafts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `cds_extraction_flags_resolved_by_foreign` FOREIGN KEY (`resolved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `cds_extraction_flags_template_id_foreign` FOREIGN KEY (`template_id`) REFERENCES `docuperfect_templates` (`id`) ON DELETE SET NULL
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
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `purpose` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'activation',
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
DROP TABLE IF EXISTS `communication_attachments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `communication_attachments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `communication_id` bigint unsigned NOT NULL,
  `filename` varchar(512) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mime` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `size_bytes` bigint unsigned NOT NULL DEFAULT '0',
  `content_hash` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `storage_path` varchar(1024) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `comm_att_agency_fk` (`agency_id`),
  KEY `comm_att_comm_fk` (`communication_id`),
  KEY `comm_att_hash_idx` (`content_hash`),
  CONSTRAINT `comm_att_agency_fk` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `comm_att_comm_fk` FOREIGN KEY (`communication_id`) REFERENCES `communications` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `communication_flag_alerts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `communication_flag_alerts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `identifier` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `original_flag_id` bigint unsigned NOT NULL,
  `contradicting_flag_id` bigint unsigned DEFAULT NULL,
  `alert_type` enum('agent_vs_agent','agent_vs_ai') COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('open','reviewed','dismissed','actioned') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'open',
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
  `identifier` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `identifier_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_id` bigint unsigned NOT NULL,
  `flag` enum('not_real_estate','real_estate') COLLATE utf8mb4_unicode_ci NOT NULL,
  `ai_is_real_estate` tinyint(1) DEFAULT NULL,
  `ai_confidence` decimal(4,3) DEFAULT NULL,
  `message_external_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `flagged_at` timestamp NOT NULL,
  `contradicted_at` timestamp NULL DEFAULT NULL,
  `contradicted_by_user_id` bigint unsigned DEFAULT NULL,
  `review_status` enum('open','reviewed','actioned') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'open',
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
DROP TABLE IF EXISTS `communication_links`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `communication_links` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `communication_id` bigint unsigned NOT NULL,
  `linkable_type` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `linkable_id` bigint unsigned NOT NULL,
  `link_method` enum('deterministic','attorney_ref','ellie_suggested','manual') COLLATE utf8mb4_unicode_ci NOT NULL,
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
  CONSTRAINT `comm_link_agency_fk` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `comm_link_comm_fk` FOREIGN KEY (`communication_id`) REFERENCES `communications` (`id`) ON DELETE CASCADE,
  CONSTRAINT `comm_link_confby_fk` FOREIGN KEY (`confirmed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `communication_mailboxes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `communication_mailboxes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `email_address` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `imap_host` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `imap_port` int unsigned NOT NULL DEFAULT '993',
  `username` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `encrypted_password` text COLLATE utf8mb4_unicode_ci,
  `auth_type` enum('imap','oauth') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'imap',
  `set_by` enum('agency','user') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `poll_inbox` tinyint(1) NOT NULL DEFAULT '1',
  `poll_sent` tinyint(1) NOT NULL DEFAULT '1',
  `poll_interval_minutes` int unsigned NOT NULL DEFAULT '15',
  `last_polled_at` timestamp NULL DEFAULT NULL,
  `last_uid_seen` bigint unsigned DEFAULT NULL,
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
  `channel` enum('email','whatsapp') COLLATE utf8mb4_unicode_ci NOT NULL,
  `direction` enum('inbound','outbound') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'inbound',
  `external_id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `thread_key` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `from_identifier` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `participant_identifiers` json DEFAULT NULL,
  `occurred_at` datetime NOT NULL,
  `captured_at` datetime NOT NULL,
  `subject` varchar(1024) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `body_text` mediumtext COLLATE utf8mb4_unicode_ci,
  `body_preview` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `raw_path` varchar(1024) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `has_attachments` tinyint(1) NOT NULL DEFAULT '0',
  `content_hash` char(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_ref` varchar(512) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `classification` enum('real_estate','personal','uncertain') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ai_is_real_estate` tinyint(1) DEFAULT NULL,
  `ai_confidence` decimal(4,3) DEFAULT NULL,
  `classified_at` timestamp NULL DEFAULT NULL,
  `classifier` enum('keyword','ai','manual') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `expires_at` datetime NOT NULL,
  `nudged_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `purged_at` timestamp NULL DEFAULT NULL,
  `purged_reason` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
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
  `wa_number` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `device_token` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `last_seen_at` timestamp NULL DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `comm_wa_token_uq` (`device_token`),
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
  `channel` enum('email','whatsapp') COLLATE utf8mb4_unicode_ci NOT NULL,
  `direction` enum('inbound','outbound') COLLATE utf8mb4_unicode_ci NOT NULL,
  `external_id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `thread_key` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `from_identifier` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `participant_identifiers` json DEFAULT NULL,
  `occurred_at` datetime NOT NULL,
  `captured_at` datetime NOT NULL,
  `subject` varchar(1024) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `body_text` mediumtext COLLATE utf8mb4_unicode_ci,
  `body_preview` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `raw_path` varchar(1024) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `has_attachments` tinyint(1) NOT NULL DEFAULT '0',
  `content_hash` char(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_ref` varchar(512) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `purged_at` timestamp NULL DEFAULT NULL,
  `purged_reason` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `comm_agency_ext_uq` (`agency_id`,`external_id`),
  KEY `comm_thread_idx` (`thread_key`),
  KEY `comm_occurred_idx` (`occurred_at`),
  KEY `comm_agency_channel_idx` (`agency_id`,`channel`),
  KEY `comm_hash_idx` (`content_hash`),
  CONSTRAINT `comm_agency_fk` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `company_expenses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `company_expenses` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `period` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `monthly_expenses` decimal(12,2) NOT NULL DEFAULT '0.00',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
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
  `action_type` enum('view','edit','export','share','delete','merge') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `accessed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ip_address` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `request_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `contact_consent_records` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `contact_id` bigint unsigned NOT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `consent_type` enum('fica_processing','marketing_communications','data_sharing','channel_email','channel_sms','channel_whatsapp','channel_call') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `given_at` timestamp NOT NULL,
  `given_by_user_id` bigint unsigned NOT NULL,
  `method` enum('verbal','written','electronic','signed_document') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
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
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `contact_matches` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned DEFAULT NULL,
  `share_token` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `share_slug` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `contact_id` bigint unsigned NOT NULL,
  `created_by_user_id` bigint unsigned DEFAULT NULL,
  `updated_by_user_id` bigint unsigned DEFAULT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `listing_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'sale',
  `status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `is_primary` tinyint(1) NOT NULL DEFAULT '0',
  `category` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `property_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `property_types` json DEFAULT NULL,
  `price_min` int unsigned DEFAULT NULL,
  `price_max` int unsigned DEFAULT NULL,
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
  CONSTRAINT `contact_matches_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE SET NULL,
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
  `body` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
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
DROP TABLE IF EXISTS `contact_property`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `contact_property` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `contact_id` bigint unsigned NOT NULL,
  `property_id` bigint unsigned NOT NULL,
  `role` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
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
  CONSTRAINT `contact_tags_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE
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
  `client_user_id` bigint unsigned DEFAULT NULL,
  `first_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `last_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `phone` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `birthday` date DEFAULT NULL,
  `birthday_reminder` tinyint(1) NOT NULL DEFAULT '0',
  `id_number` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `id_number_captured_at` timestamp NULL DEFAULT NULL,
  `id_number_source` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `address` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `loaded_at` timestamp NULL DEFAULT NULL,
  `modified_at` timestamp NULL DEFAULT NULL,
  `last_contacted_at` timestamp NULL DEFAULT NULL,
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
  `agency_id` bigint unsigned DEFAULT NULL,
  `branch_id` bigint unsigned NOT NULL,
  `messaging_opt_out_at` timestamp NULL DEFAULT NULL,
  `messaging_opt_out_reason` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `messaging_opt_out_recorded_by_user_id` bigint unsigned DEFAULT NULL,
  `messaging_opt_out_source` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `messaging_opted_in_at` timestamp NULL DEFAULT NULL,
  `messaging_opt_in_reason` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `messaging_opt_in_recorded_by_user_id` bigint unsigned DEFAULT NULL,
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
  CONSTRAINT `contacts_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `contacts_client_user_id_foreign` FOREIGN KEY (`client_user_id`) REFERENCES `client_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `contacts_contact_source_id_foreign` FOREIGN KEY (`contact_source_id`) REFERENCES `contact_sources` (`id`) ON DELETE SET NULL,
  CONSTRAINT `contacts_contact_type_id_foreign` FOREIGN KEY (`contact_type_id`) REFERENCES `contact_types` (`id`) ON DELETE SET NULL,
  CONSTRAINT `contacts_created_by_user_id_foreign` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `contacts_msg_optin_recorded_by_fk` FOREIGN KEY (`messaging_opt_in_recorded_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `contacts_msg_optout_recorded_by_fk` FOREIGN KEY (`messaging_opt_out_recorded_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `deal_activity_log` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `deal_id` bigint unsigned NOT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `deal_step_instance_id` bigint unsigned DEFAULT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `action` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `deal_step_instances` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `deal_id` bigint unsigned NOT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `pipeline_step_id` bigint unsigned NOT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `position` int NOT NULL DEFAULT '0',
  `is_locked` tinyint(1) NOT NULL DEFAULT '0',
  `is_milestone` tinyint(1) NOT NULL DEFAULT '0',
  `completion_type` enum('manual_tick','date_input','amount_input','document_upload','document_signed','text_input','multi_field','auto_from_linked_deal') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'manual_tick',
  `completion_config` json DEFAULT NULL,
  `status` enum('not_started','active','completed','overdue','skipped') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'not_started',
  `trigger_type` enum('on_creation','after_step','manual','on_date') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `trigger_step_instance_id` bigint unsigned DEFAULT NULL,
  `days_offset` int NOT NULL DEFAULT '0',
  `due_date` date DEFAULT NULL,
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
  `contact_id` bigint unsigned NOT NULL,
  `role` enum('buyer','seller','co_buyer','co_seller','conveyancer','bond_originator','other') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `deal_v2_contacts_deal_id_foreign` (`deal_id`),
  KEY `deal_v2_contacts_contact_id_foreign` (`contact_id`),
  CONSTRAINT `deal_v2_contacts_contact_id_foreign` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `deal_v2_contacts_deal_id_foreign` FOREIGN KEY (`deal_id`) REFERENCES `deals_v2` (`id`) ON DELETE CASCADE
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `deals` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `deal_no` int unsigned DEFAULT NULL,
  `file_no` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `branch_id` bigint unsigned DEFAULT NULL,
  `period` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `deal_date` date NOT NULL,
  `property_address` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `seller_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `buyer_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `attorney_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
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
  `listing_our_share_percent` decimal(5,2) NOT NULL DEFAULT '100.00',
  `selling_external` tinyint(1) NOT NULL DEFAULT '0',
  `selling_external_agency` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
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
  CONSTRAINT `deals_link_reviewer_fk` FOREIGN KEY (`link_reviewed_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `deals_presentation_fk` FOREIGN KEY (`presentation_id`) REFERENCES `presentations` (`id`) ON DELETE SET NULL,
  CONSTRAINT `deals_property_fk` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `deals_v2`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `deals_v2` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `reference` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `deal_type` enum('bond','cash','sale_of_2nd') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('active','completed','cancelled','on_hold') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `property_id` bigint unsigned NOT NULL,
  `listing_agent_id` bigint unsigned NOT NULL,
  `selling_agent_id` bigint unsigned DEFAULT NULL,
  `pipeline_template_id` bigint unsigned NOT NULL,
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
  `listing_types` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `splitter_doc_types_slug_unique` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `document_versions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `document_versions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `document_id` bigint unsigned NOT NULL,
  `version_number` int unsigned NOT NULL,
  `version_type` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `content_hash` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `merged_html_blob_id` bigint unsigned DEFAULT NULL,
  `pdf_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `scan_image_paths` json DEFAULT NULL,
  `created_by_user_id` bigint unsigned NOT NULL,
  `created_by_signature_request_id` bigint unsigned DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `doc_versions_doc_versionnum_uq` (`document_id`,`version_number`),
  KEY `doc_versions_doc_created_idx` (`document_id`,`created_at`),
  KEY `document_versions_created_by_user_id_foreign` (`created_by_user_id`),
  KEY `document_versions_created_by_signature_request_id_foreign` (`created_by_signature_request_id`),
  CONSTRAINT `document_versions_created_by_signature_request_id_foreign` FOREIGN KEY (`created_by_signature_request_id`) REFERENCES `signature_requests` (`id`) ON DELETE SET NULL,
  CONSTRAINT `document_versions_created_by_user_id_foreign` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `document_versions_document_id_foreign` FOREIGN KEY (`document_id`) REFERENCES `docuperfect_documents` (`id`) ON DELETE CASCADE
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
  `source_type` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'upload',
  `source_id` bigint unsigned DEFAULT NULL,
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
  CONSTRAINT `documents_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
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
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `text` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `category` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_global` tinyint(1) NOT NULL DEFAULT '0',
  `is_system` tinyint(1) NOT NULL DEFAULT '0',
  `owner_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `docuperfect_clauses_owner_id_foreign` (`owner_id`),
  KEY `docuperfect_clauses_category_index` (`category`),
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
  CONSTRAINT `docuperfect_documents_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `docuperfect_documents_owner_id_foreign` FOREIGN KEY (`owner_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `docuperfect_documents_template_id_foreign` FOREIGN KEY (`template_id`) REFERENCES `docuperfect_templates` (`id`) ON DELETE CASCADE
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `template_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'sales',
  `render_type` enum('pdf','web') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pdf',
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
  `extraction_verification` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `docuperfect_templates_owner_id_foreign` (`owner_id`),
  KEY `docuperfect_templates_document_type_id_foreign` (`document_type_id`),
  KEY `docuperfect_templates_extraction_verification_index` (`extraction_verification`),
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
  `status` enum('draft','submitted','under_review','agent_approved','corrections_requested','approved','rejected','cancelled') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'draft',
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
  `regulator_registered_on` date DEFAULT NULL,
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
  CONSTRAINT `knowledge_documents_category_id_foreign` FOREIGN KEY (`category_id`) REFERENCES `knowledge_categories` (`id`) ON DELETE CASCADE,
  CONSTRAINT `knowledge_documents_uploaded_by_foreign` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
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
  PRIMARY KEY (`id`),
  KEY `legal_block_audit_log_agency_id_created_at_index` (`agency_id`,`created_at`),
  KEY `legal_block_audit_log_template_id_index` (`template_id`)
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
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
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
  PRIMARY KEY (`id`),
  KEY `marketing_share_log_property_id_foreign` (`property_id`),
  KEY `marketing_share_log_user_id_foreign` (`user_id`),
  KEY `marketing_share_log_agency_id_foreign` (`agency_id`),
  CONSTRAINT `marketing_share_log_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`),
  CONSTRAINT `marketing_share_log_property_id_foreign` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`),
  CONSTRAINT `marketing_share_log_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `marketing_suppressions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `marketing_suppressions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `identifier` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `identifier_type` enum('email','phone') COLLATE utf8mb4_unicode_ci NOT NULL,
  `contact_id` bigint unsigned DEFAULT NULL,
  `source` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `reason` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
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
DROP TABLE IF EXISTS `oversight_nudges`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `oversight_nudges` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `from_user_id` bigint unsigned NOT NULL,
  `to_user_id` bigint unsigned NOT NULL,
  `subject_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `subject_id` bigint unsigned DEFAULT NULL,
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
  `action` enum('create','update','skip') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'create',
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `p24_suburbs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `p24_id` int unsigned DEFAULT NULL,
  `p24_city_id` bigint unsigned DEFAULT NULL,
  `region` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'kzn-south-coast',
  `surrounding_ids` json DEFAULT NULL,
  `confirmed` tinyint(1) NOT NULL DEFAULT '0',
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `p24_syndication_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `property_id` bigint unsigned NOT NULL,
  `action` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `request_payload` json DEFAULT NULL,
  `response_payload` json DEFAULT NULL,
  `status_code` smallint DEFAULT NULL,
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
  PRIMARY KEY (`id`),
  UNIQUE KEY `payroll_deduction_types_agency_id_code_unique` (`agency_id`,`code`),
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
  `sort_order` tinyint unsigned NOT NULL DEFAULT '0',
  `is_system` tinyint(1) NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
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
  `key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `performance_settings_key_unique` (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
  `status` enum('in_progress','completed','expired','superseded') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'in_progress',
  `started_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `completed_at` timestamp NULL DEFAULT NULL,
  `valid_until` date DEFAULT NULL,
  `signature_path` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `signature_type` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `typed_signature_name` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `device_fingerprint` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `declaration_text` text COLLATE utf8mb4_unicode_ci,
  `sections_acknowledged_count` int unsigned NOT NULL DEFAULT '0',
  `sections_total_count` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `policy_acknowledgements_branch_id_foreign` (`branch_id`),
  KEY `policy_acknowledgements_policy_version_id_status_index` (`policy_version_id`,`status`),
  KEY `policy_acknowledgements_user_id_status_index` (`user_id`,`status`),
  KEY `policy_acknowledgements_agency_id_status_index` (`agency_id`,`status`),
  KEY `policy_acknowledgements_policy_id_status_index` (`policy_id`,`status`),
  KEY `policy_acknowledgements_valid_until_index` (`valid_until`),
  CONSTRAINT `policy_acknowledgements_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `policy_acknowledgements_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `policy_acknowledgements_policy_id_foreign` FOREIGN KEY (`policy_id`) REFERENCES `agency_policies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `policy_acknowledgements_policy_version_id_foreign` FOREIGN KEY (`policy_version_id`) REFERENCES `policy_versions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `policy_acknowledgements_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `policy_section_acknowledgements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `policy_section_acknowledgements` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `policy_acknowledgement_id` bigint unsigned NOT NULL,
  `policy_section_id` bigint unsigned NOT NULL,
  `acknowledged` tinyint(1) NOT NULL DEFAULT '0',
  `acknowledged_at` timestamp NULL DEFAULT NULL,
  `acknowledgement_response` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `policy_sec_ack_unique` (`policy_acknowledgement_id`,`policy_section_id`),
  KEY `psa_agency_fk` (`agency_id`),
  KEY `psa_section_fk` (`policy_section_id`),
  CONSTRAINT `psa_ack_fk` FOREIGN KEY (`policy_acknowledgement_id`) REFERENCES `policy_acknowledgements` (`id`) ON DELETE CASCADE,
  CONSTRAINT `psa_agency_fk` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `psa_section_fk` FOREIGN KEY (`policy_section_id`) REFERENCES `policy_sections` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `policy_sections`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `policy_sections` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `policy_version_id` bigint unsigned NOT NULL,
  `section_type` enum('section','schedule','annexure','acknowledgement') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'section',
  `display_order` int unsigned NOT NULL,
  `section_number` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `body_html` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `requires_acknowledgement` tinyint(1) NOT NULL DEFAULT '1',
  `acknowledgement_prompt` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `policy_sections_agency_id_foreign` (`agency_id`),
  KEY `policy_sections_policy_version_id_display_order_index` (`policy_version_id`,`display_order`),
  CONSTRAINT `policy_sections_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `policy_sections_policy_version_id_foreign` FOREIGN KEY (`policy_version_id`) REFERENCES `policy_versions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `policy_versions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `policy_versions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `policy_id` bigint unsigned NOT NULL,
  `version_number` int unsigned NOT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Agency Policy',
  `status` enum('draft','active','superseded') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `approved_by` bigint unsigned DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `approver_title` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `board_approval_document_path` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `approval_ip` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `approval_notes` text COLLATE utf8mb4_unicode_ci,
  `effective_from` date DEFAULT NULL,
  `superseded_at` timestamp NULL DEFAULT NULL,
  `superseded_by_version_id` bigint unsigned DEFAULT NULL,
  `next_review_due` date DEFAULT NULL,
  `change_notes` text COLLATE utf8mb4_unicode_ci,
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `policy_versions_policy_id_version_number_unique` (`policy_id`,`version_number`),
  KEY `policy_versions_approved_by_foreign` (`approved_by`),
  KEY `policy_versions_created_by_foreign` (`created_by`),
  KEY `policy_versions_agency_id_status_index` (`agency_id`,`status`),
  KEY `policy_versions_policy_id_status_index` (`policy_id`,`status`),
  CONSTRAINT `policy_versions_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `policy_versions_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `policy_versions_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `policy_versions_policy_id_foreign` FOREIGN KEY (`policy_id`) REFERENCES `agency_policies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `portal_captures`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `portal_captures` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `presentation_id` bigint unsigned DEFAULT NULL,
  `source_site` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `page_type` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_url` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `final_url` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `page_title` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `captured_at` timestamp NOT NULL,
  `extractor_version` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `dom_hash_sha256` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `html_bytes` int unsigned NOT NULL,
  `raw_html_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `screenshot_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `parse_status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `extracted_fields_json` json DEFAULT NULL,
  `jsonld_json` json DEFAULT NULL,
  `found_image_urls_json` json DEFAULT NULL,
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `portal_leads` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `portal` enum('p24','pp') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `lead_type` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `listing_id` bigint unsigned DEFAULT NULL,
  `listing_portal_ref` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `contact_id` bigint unsigned DEFAULT NULL,
  `contact_exists` tinyint(1) NOT NULL DEFAULT '0',
  `existing_contact_agent_id` bigint unsigned DEFAULT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `is_whatsapp` tinyint(1) NOT NULL DEFAULT '0',
  `lead_source_raw` json NOT NULL,
  `received_at` timestamp NOT NULL,
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `portal_listing_observations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `portal_listing_id` bigint unsigned NOT NULL,
  `capture_id` bigint unsigned NOT NULL,
  `observed_at` timestamp NULL DEFAULT NULL,
  `observed_fields_json` json DEFAULT NULL,
  `changed_fields_json` json DEFAULT NULL,
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `portal_listings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `source_site` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `portal_listing_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `canonical_url` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `first_seen_at` timestamp NULL DEFAULT NULL,
  `last_seen_at` timestamp NULL DEFAULT NULL,
  `last_capture_id` bigint unsigned DEFAULT NULL,
  `current_fields_json` json DEFAULT NULL,
  `primary_image_url` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `tracked_property_id` bigint unsigned DEFAULT NULL,
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pp_cities` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `pp_city_id` bigint unsigned NOT NULL,
  `pp_province_id` bigint unsigned NOT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pp_event_feed_settings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `pp_event_feed_settings_key_unique` (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pp_provinces`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pp_provinces` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `pp_province_id` bigint unsigned NOT NULL,
  `pp_province_enum` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `pp_provinces_pp_province_id_unique` (`pp_province_id`),
  KEY `pp_provinces_name_index` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pp_suburbs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pp_suburbs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `pp_suburb_id` bigint unsigned NOT NULL,
  `pp_city_id` bigint unsigned NOT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `normalised_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `presentation_active_listings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `mic_comp_row_id` bigint unsigned DEFAULT NULL,
  `presentation_id` bigint unsigned NOT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `source_upload_id` bigint unsigned DEFAULT NULL,
  `source_snapshot_id` bigint unsigned DEFAULT NULL,
  `listing_date` date DEFAULT NULL,
  `list_price_inc` bigint unsigned DEFAULT NULL,
  `suburb` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `property_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `beds` smallint unsigned DEFAULT NULL,
  `baths` smallint unsigned DEFAULT NULL,
  `size_m2` smallint unsigned DEFAULT NULL,
  `status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `raw_row_json` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `parser_version` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `extraction_method` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `external_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fingerprint` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `first_seen_at` timestamp NULL DEFAULT NULL,
  `last_seen_at` timestamp NULL DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `source_rank` tinyint unsigned NOT NULL DEFAULT '50',
  `merge_confidence` tinyint unsigned DEFAULT NULL,
  `data_quality_score` tinyint unsigned DEFAULT NULL,
  `conflict_flags_json` json DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `is_demo` tinyint(1) NOT NULL DEFAULT '0',
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `presentation_ai_summary_history` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `presentation_id` bigint unsigned NOT NULL,
  `presentation_version_id` bigint unsigned DEFAULT NULL,
  `ai_variant_id` smallint unsigned NOT NULL,
  `generated_text` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `generated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `generated_by_user_id` bigint unsigned DEFAULT NULL,
  `was_saved` tinyint(1) NOT NULL DEFAULT '0',
  `tokens_used` int unsigned DEFAULT NULL,
  `latency_ms` int unsigned DEFAULT NULL,
  `failure_reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `prompt_hash` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `model` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `presentation_ai_variants` (
  `id` smallint unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `display_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(300) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `prompt_template` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `max_tokens` smallint unsigned NOT NULL DEFAULT '800',
  `temperature` decimal(3,2) NOT NULL DEFAULT '0.50',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `sort_order` tinyint unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `presentation_ai_variants_key_unique` (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `presentation_articles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `presentation_articles` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `presentation_id` bigint unsigned NOT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `url` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `snapshot_text` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `content_hash` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fetched_at` timestamp NULL DEFAULT NULL,
  `ai_summary_text` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `ai_summary_model` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ai_summary_created_at` timestamp NULL DEFAULT NULL,
  `tags_json` json DEFAULT NULL,
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `presentation_deliveries` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `snapshot_link_id` bigint unsigned NOT NULL,
  `presentation_id` bigint unsigned NOT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `sent_by_user_id` bigint unsigned NOT NULL,
  `channel` enum('email','whatsapp','copy','sms') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `recipient_contact_id` bigint unsigned DEFAULT NULL,
  `recipient_name` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `recipient_email` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `recipient_phone` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mode` enum('full','teaser') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('queued','sent','failed','bounced','delivered','opened') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'queued',
  `error_message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `sent_at` timestamp NULL DEFAULT NULL,
  `delivered_at` timestamp NULL DEFAULT NULL,
  `opened_at` timestamp NULL DEFAULT NULL,
  `whatsapp_url` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `whatsapp_click_through_at` timestamp NULL DEFAULT NULL,
  `subject_line` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `message_body` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `presentation_document_library_items` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `presentation_id` bigint unsigned NOT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `document_library_item_id` bigint unsigned NOT NULL,
  `attached_by_user_id` bigint unsigned NOT NULL,
  `note` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `presentation_fields` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `presentation_id` bigint unsigned NOT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `field_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `extracted_value` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `override_value` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `final_value` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `source_upload_id` bigint unsigned DEFAULT NULL,
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `presentation_links` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `presentation_id` bigint unsigned NOT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `type` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'other',
  `url` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `notes` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `asking_price_inc` bigint unsigned DEFAULT NULL,
  `beds` smallint unsigned DEFAULT NULL,
  `baths` smallint unsigned DEFAULT NULL,
  `floor_area_m2` smallint unsigned DEFAULT NULL,
  `erf_m2` smallint unsigned DEFAULT NULL,
  `property_type` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `suburb` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `extraction_status` enum('pending','ok','failed') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `extracted_json` json DEFAULT NULL,
  `extraction_error` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `extracted_at` timestamp NULL DEFAULT NULL,
  `override_json` json DEFAULT NULL,
  `override_by_user_id` bigint unsigned DEFAULT NULL,
  `override_at` timestamp NULL DEFAULT NULL,
  `portal_capture_id` bigint unsigned DEFAULT NULL,
  `created_by_user_id` bigint unsigned DEFAULT NULL,
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `presentation_listing_price_history` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `presentation_id` bigint unsigned NOT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `active_listing_id` bigint unsigned NOT NULL,
  `price_inc` bigint unsigned NOT NULL,
  `captured_at` timestamp NOT NULL,
  `source_snapshot_id` bigint unsigned DEFAULT NULL,
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `presentation_outcome_prompts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `presentation_id` bigint unsigned NOT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `prompted_user_id` bigint unsigned NOT NULL,
  `prompted_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `channel` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'mail',
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `presentation_outcomes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `presentation_id` bigint unsigned NOT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `outcome` enum('won_mandate','won_sale','lost_to_competitor','lost_to_no_decision','lost_to_price_dispute','lost_to_no_response','still_pending','other') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `cancellation_reason` enum('price_too_high_seller','price_too_low_seller','commission_concerns','sole_mandate_concerns','family_pressure','existing_relationship','agency_reputation','agent_personality','timing_change','property_issues_discovered','price_match_with_other','other') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cancellation_competitor_agency` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cancellation_competitor_price` bigint unsigned DEFAULT NULL,
  `decision_at` date DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `resulted_in_deal_id` bigint unsigned DEFAULT NULL,
  `recorded_by_user_id` bigint unsigned DEFAULT NULL,
  `recorded_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `locked` tinyint(1) NOT NULL DEFAULT '0',
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `presentation_refresh_requests` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `presentation_id` bigint unsigned NOT NULL,
  `snapshot_link_id` bigint unsigned NOT NULL,
  `recipient_contact_id` bigint unsigned DEFAULT NULL,
  `requester_name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `requester_email` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `requester_phone` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `fingerprint_hash` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ip_masked` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('pending','acknowledged','resolved','declined','cancelled') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `acknowledged_at` timestamp NULL DEFAULT NULL,
  `acknowledged_by_user_id` bigint unsigned DEFAULT NULL,
  `resolved_at` timestamp NULL DEFAULT NULL,
  `resolved_by_user_id` bigint unsigned DEFAULT NULL,
  `resulting_link_id` bigint unsigned DEFAULT NULL,
  `resolution_note` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `declined_at` timestamp NULL DEFAULT NULL,
  `declined_by_user_id` bigint unsigned DEFAULT NULL,
  `decline_reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `presentation_sections` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `presentation_id` bigint unsigned NOT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `section_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `data_json` json NOT NULL,
  `sort_order` int unsigned NOT NULL DEFAULT '0',
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `presentation_snapshot_links` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `presentation_id` bigint unsigned NOT NULL,
  `presentation_version_id` bigint unsigned NOT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `token` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `mode` enum('full','teaser') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'full',
  `recipient_contact_id` bigint unsigned DEFAULT NULL,
  `recipient_label` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by_user_id` bigint unsigned NOT NULL,
  `expires_at` timestamp NOT NULL,
  `revoked_at` timestamp NULL DEFAULT NULL,
  `revoked_by_user_id` bigint unsigned DEFAULT NULL,
  `first_viewed_at` timestamp NULL DEFAULT NULL,
  `last_viewed_at` timestamp NULL DEFAULT NULL,
  `view_count` int unsigned NOT NULL DEFAULT '0',
  `first_fingerprint` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `flagged_at` timestamp NULL DEFAULT NULL,
  `flagged_reason` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_flag_notified_at` timestamp NULL DEFAULT NULL,
  `refresh_requested_at` timestamp NULL DEFAULT NULL,
  `refresh_requested_by_name` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `refresh_requested_message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `refresh_request_count` int unsigned NOT NULL DEFAULT '0',
  `refresh_acknowledged_at` timestamp NULL DEFAULT NULL,
  `refresh_acknowledged_by_user_id` bigint unsigned DEFAULT NULL,
  `refresh_resulted_in_link_id` bigint unsigned DEFAULT NULL,
  `superseded_by_link_id` bigint unsigned DEFAULT NULL,
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `presentation_snapshot_views` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `snapshot_link_id` bigint unsigned NOT NULL,
  `teaser_lead_id` bigint unsigned DEFAULT NULL,
  `viewed_at` timestamp NOT NULL,
  `ip_address` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fingerprint` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `referrer_url` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `duration_seconds` int unsigned DEFAULT NULL,
  `scroll_depth_pct` tinyint unsigned DEFAULT NULL,
  `sections_viewed_json` json DEFAULT NULL,
  `is_first_view` tinyint(1) NOT NULL DEFAULT '0',
  `flagged_fingerprint_mismatch` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `presentation_snapshots` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `presentation_id` bigint unsigned NOT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `generated_by_user_id` bigint unsigned NOT NULL,
  `snapshot_json` json NOT NULL,
  `computed_json` json DEFAULT NULL,
  `engine_versions_json` json DEFAULT NULL,
  `generated_at` timestamp NOT NULL,
  `inputs_json` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `market_analytics_run_id` bigint unsigned DEFAULT NULL,
  `sale_probability_run_id` bigint unsigned DEFAULT NULL,
  `output_summary_json` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_by_user_id` bigint unsigned DEFAULT NULL,
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `presentation_sold_comps` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `mic_comp_row_id` bigint unsigned DEFAULT NULL,
  `presentation_id` bigint unsigned NOT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `source_upload_id` bigint unsigned DEFAULT NULL,
  `sold_date` date DEFAULT NULL,
  `sold_price_inc` bigint unsigned DEFAULT NULL,
  `suburb` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `property_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `beds` smallint unsigned DEFAULT NULL,
  `baths` smallint unsigned DEFAULT NULL,
  `size_m2` smallint unsigned DEFAULT NULL,
  `listed_date` date DEFAULT NULL,
  `raw_row_json` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `parser_version` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `is_demo` tinyint(1) NOT NULL DEFAULT '0',
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `presentation_teaser_leads` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `snapshot_link_id` bigint unsigned NOT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `presentation_id` bigint unsigned NOT NULL,
  `contact_id` bigint unsigned DEFAULT NULL,
  `first_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `last_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `relationship` enum('owner','considering_selling','agent','researcher','other') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'other',
  `intent` enum('sell_now','sell_soon','just_curious','other') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'other',
  `consent_marketing` tinyint(1) NOT NULL DEFAULT '0',
  `consent_contact` tinyint(1) NOT NULL DEFAULT '1',
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `captured_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `converted_to_contact_at` timestamp NULL DEFAULT NULL,
  `assigned_agent_id` bigint unsigned DEFAULT NULL,
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `presentation_uploads` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `presentation_id` bigint unsigned NOT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `uploaded_by_user_id` bigint unsigned NOT NULL,
  `type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `original_filename` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `storage_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_slug` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `content_hash` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `text_extracted` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `extraction_json` json DEFAULT NULL,
  `extraction_status` enum('pending','ok','failed') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `extracted_at` timestamp NULL DEFAULT NULL,
  `extraction_error` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `override_json` json DEFAULT NULL,
  `override_by_user_id` bigint unsigned DEFAULT NULL,
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `presentation_url_snapshots` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `presentation_id` bigint unsigned NOT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `url` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `final_url` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `snapshot_html` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `source_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'other',
  `http_status` smallint unsigned DEFAULT NULL,
  `content_type` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `content_bytes` int unsigned DEFAULT NULL,
  `blocked_reason` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `timed_out` tinyint(1) NOT NULL DEFAULT '0',
  `content_hash` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `response_headers_json` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `presentation_versions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `presentation_id` bigint unsigned NOT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `compiled_by` bigint unsigned DEFAULT NULL,
  `reviewer_user_id` bigint unsigned DEFAULT NULL,
  `reviewer_locked_at` timestamp NULL DEFAULT NULL,
  `blueprint_version` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'v1',
  `review_status` enum('draft','awaiting_review','in_analysis','published','archived') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `analytics_run_id` bigint unsigned DEFAULT NULL,
  `probability_run_id` bigint unsigned DEFAULT NULL,
  `data_snapshot_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `included_comp_ids_json` json DEFAULT NULL,
  `included_competitor_ids_json` json DEFAULT NULL,
  `condition_level_id` bigint unsigned DEFAULT NULL,
  `condition_adjustment_pct` decimal(5,2) DEFAULT NULL COMMENT 'Snapshot at review/publish — defends historic PDF against later setting drift.',
  `condition_label` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `enabled_sections_json` json DEFAULT NULL COMMENT 'Build 4 — per-version snapshot of which report sections render. Null means "use agency defaults at compile time".',
  `snapshot_payload` json DEFAULT NULL COMMENT 'Build 5 — full compiled report payload frozen at publish. Public view reads from this; live compile is fallback only.',
  `snapshot_taken_at` timestamp NULL DEFAULT NULL COMMENT 'Build 5 — when snapshot_payload was last frozen. Drives the freshness window calc.',
  `hydration_summary_json` json DEFAULT NULL,
  `ai_variant_id` smallint unsigned DEFAULT NULL,
  `ai_summary_text` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `ai_summary_raw_text` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `ai_summary_edited_by_agent` tinyint(1) NOT NULL DEFAULT '0',
  `ai_summary_generated_at` timestamp NULL DEFAULT NULL,
  `ai_summary_edited_at` timestamp NULL DEFAULT NULL,
  `ai_summary_model` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ai_summary_prompt_hash` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ai_summary_input_facts_json` json DEFAULT NULL,
  `compiled_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `presentations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `branch_id` bigint unsigned NOT NULL,
  `created_by_user_id` bigint unsigned NOT NULL,
  `listing_id` bigint unsigned DEFAULT NULL,
  `property_id` bigint unsigned DEFAULT NULL,
  `tracked_property_id` bigint unsigned DEFAULT NULL,
  `seller_contact_id` bigint unsigned DEFAULT NULL,
  `deal_id` bigint unsigned DEFAULT NULL,
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `property_address` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `suburb` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `property_type` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bedrooms` smallint unsigned DEFAULT NULL,
  `bathrooms` smallint unsigned DEFAULT NULL,
  `garages_parking` smallint unsigned DEFAULT NULL,
  `erf_size_m2` int unsigned DEFAULT NULL,
  `floor_area_m2` smallint unsigned DEFAULT NULL,
  `asking_price_inc` bigint unsigned DEFAULT NULL,
  `monthly_bond` decimal(12,2) DEFAULT NULL,
  `monthly_rates` decimal(12,2) DEFAULT NULL,
  `monthly_levies` decimal(12,2) DEFAULT NULL,
  `monthly_insurance` decimal(12,2) DEFAULT NULL,
  `monthly_utilities` decimal(12,2) DEFAULT NULL,
  `monthly_garden` decimal(12,2) DEFAULT NULL,
  `monthly_pool` decimal(12,2) DEFAULT NULL,
  `monthly_security` decimal(12,2) DEFAULT NULL,
  `monthly_opportunity_cost` decimal(12,2) DEFAULT NULL,
  `cma_selected_range` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'middle',
  `vicinity_selected_range` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'middle',
  `comp_scope` enum('radius_all','suburb_only') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `comp_radius_m` smallint unsigned DEFAULT NULL,
  `excluded_active_listing_indices` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `simulator_config_json` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `seller_live_capture_json` json DEFAULT NULL,
  `seller_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `seller_email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('draft','finalized') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `currency` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'ZAR',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `agency_id` bigint unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `presentations_branch_id_foreign` (`branch_id`),
  KEY `presentations_created_by_user_id_foreign` (`created_by_user_id`),
  KEY `presentations_agency_id_index` (`agency_id`),
  KEY `idx_presentations_agency_id` (`agency_id`),
  KEY `presentations_property_id_index` (`property_id`),
  KEY `presentations_tracked_property_id_index` (`tracked_property_id`),
  KEY `presentations_seller_contact_id_index` (`seller_contact_id`),
  KEY `presentations_deal_id_index` (`deal_id`),
  CONSTRAINT `presentations_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `presentations_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE,
  CONSTRAINT `presentations_created_by_user_id_foreign` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `price_bands`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `price_bands` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `listing_type` enum('sale','rental') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `price_min` bigint unsigned NOT NULL,
  `price_max` bigint unsigned DEFAULT NULL,
  `display_order` smallint unsigned NOT NULL DEFAULT '0',
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `properties` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `external_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `headline` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `excerpt` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `price` bigint unsigned NOT NULL DEFAULT '0',
  `price_on_application` tinyint(1) NOT NULL DEFAULT '0',
  `has_deposit` tinyint(1) NOT NULL DEFAULT '0',
  `lease_period` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `price_per_day` decimal(12,2) DEFAULT NULL,
  `price_per_week` decimal(12,2) DEFAULT NULL,
  `price_per_year` decimal(12,2) DEFAULT NULL,
  `lease_type` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `gross_price` decimal(12,2) DEFAULT NULL,
  `net_price` decimal(12,2) DEFAULT NULL,
  `yard_price` decimal(12,2) DEFAULT NULL,
  `primary_price_display` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `rates_taxes` bigint unsigned DEFAULT NULL,
  `municipal_valuation` decimal(15,2) DEFAULT NULL,
  `municipal_valuation_year` smallint unsigned DEFAULT NULL,
  `levy` bigint unsigned DEFAULT NULL,
  `special_levy` bigint unsigned DEFAULT NULL,
  `rental_amount` decimal(12,2) DEFAULT NULL,
  `deposit_amount` decimal(12,2) DEFAULT NULL,
  `commission_percent` decimal(5,2) DEFAULT NULL,
  `admin_fee` decimal(12,2) DEFAULT NULL,
  `marketing_fee` decimal(12,2) DEFAULT NULL,
  `suburb` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `suburb_normalised` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `p24_suburb_id` bigint unsigned DEFAULT NULL,
  `p24_city_id` bigint unsigned DEFAULT NULL,
  `p24_province_id` bigint unsigned DEFAULT NULL,
  `p24_suburb_mismatch` tinyint(1) NOT NULL DEFAULT '0',
  `address` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `street_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `street_name_normalised` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `street_number` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `city` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `region` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `district` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `province` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `town` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `beds` tinyint NOT NULL DEFAULT '0',
  `baths` tinyint NOT NULL DEFAULT '0',
  `garages` tinyint NOT NULL DEFAULT '0',
  `size_m2` int unsigned DEFAULT NULL,
  `erf_size_m2` int unsigned DEFAULT NULL,
  `property_number` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `stand_number` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `erf_number` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `erf_portion` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT '0',
  `sg_province` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sg_rural_urban` enum('rural','urban') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'urban',
  `sg_farm_name` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sg_last_searched_at` timestamp NULL DEFAULT NULL,
  `title_deed_number` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `zone_type` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `address_internal_note` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `complex_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `unit_number` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `floor_number` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `unit_section_block` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `property_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'house',
  `title_type` enum('full_title','sectional_title','vacant_land','other') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Keystone — derived from property_type by TitleTypeClassifier on every save. Source of truth for comp-filter and review-screen badge.',
  `condition_level_id` bigint unsigned DEFAULT NULL COMMENT 'Build 3 — FK to property_setting_items where group=condition_level. Nullable: property may have no recorded condition.',
  `category` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mandate_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `listing_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `images_json` json DEFAULT NULL,
  `dawn_images_json` json DEFAULT NULL,
  `noon_images_json` json DEFAULT NULL,
  `dusk_images_json` json DEFAULT NULL,
  `gallery_images_json` json DEFAULT NULL,
  `gallery_categories_json` json DEFAULT NULL,
  `gallery_custom_tags` json DEFAULT NULL,
  `features_json` json DEFAULT NULL,
  `features_json_meta` json DEFAULT NULL COMMENT 'Per-feature audit: {pool:{source:ai|manual,confidence:0.92,confirmed_by_user_id:5,confirmed_at:...}}',
  `pet_friendly` tinyint(1) DEFAULT NULL,
  `spaces_json` json DEFAULT NULL,
  `agent_id` bigint unsigned NOT NULL,
  `pp_second_agent_id` bigint unsigned DEFAULT NULL,
  `pp_agent_image_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pp_second_agent_image_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `branch_id` bigint unsigned NOT NULL,
  `agency_id` bigint unsigned DEFAULT NULL,
  `published_at` timestamp NULL DEFAULT NULL,
  `listed_date` date DEFAULT NULL,
  `expiry_date` date DEFAULT NULL,
  `lease_start_date` date DEFAULT NULL,
  `lease_end_date` date DEFAULT NULL,
  `pp_syndication_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `pp_syndication_status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pp_ref` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pp_listing_feed_ref` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pp_last_submitted_at` timestamp NULL DEFAULT NULL,
  `pp_activated_at` timestamp NULL DEFAULT NULL,
  `pp_exclusive_days` int DEFAULT NULL,
  `pp_delay_until` timestamp NULL DEFAULT NULL,
  `pp_last_error` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `pp_images_last_synced_at` timestamp NULL DEFAULT NULL,
  `pp_listing_last_synced_at` timestamp NULL DEFAULT NULL,
  `pp_hide_street_name` tinyint(1) NOT NULL DEFAULT '0',
  `pp_hide_street_number` tinyint(1) NOT NULL DEFAULT '0',
  `pp_hide_complex_name` tinyint(1) NOT NULL DEFAULT '0',
  `pp_hide_unit_number` tinyint(1) NOT NULL DEFAULT '0',
  `youtube_video_id` varchar(11) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `matterport_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `virtual_tour_url` varchar(1000) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `rental_price_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `p24_syndication_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `p24_syndication_status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `p24_ref` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `p24_last_submitted_at` timestamp NULL DEFAULT NULL,
  `p24_activated_at` timestamp NULL DEFAULT NULL,
  `p24_last_error` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `p24_images_last_synced_at` timestamp NULL DEFAULT NULL,
  `p24_listing_last_synced_at` timestamp NULL DEFAULT NULL,
  `pp_suburb_id` int unsigned DEFAULT NULL,
  `latitude` decimal(10,7) DEFAULT NULL,
  `longitude` decimal(10,7) DEFAULT NULL,
  `geo_source` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `geo_confidence` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `geo_resolved_at` timestamp NULL DEFAULT NULL,
  `cma_gps_lat` decimal(10,7) DEFAULT NULL,
  `cma_gps_lng` decimal(10,7) DEFAULT NULL,
  `last_cma_at` timestamp NULL DEFAULT NULL,
  `last_cma_presentation_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `last_activity_at` datetime DEFAULT NULL,
  `compliance_snapshot_at` timestamp NULL DEFAULT NULL,
  `compliance_snapshot_data` json DEFAULT NULL,
  `compliance_evidence_flags` json DEFAULT NULL,
  `first_marketed_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `p24_listing_number` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_demo` tinyint(1) NOT NULL DEFAULT '0',
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
  CONSTRAINT `properties_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `properties_condition_level_fk` FOREIGN KEY (`condition_level_id`) REFERENCES `property_setting_items` (`id`) ON DELETE SET NULL,
  CONSTRAINT `properties_p24_city_id_foreign` FOREIGN KEY (`p24_city_id`) REFERENCES `p24_cities` (`id`) ON DELETE SET NULL,
  CONSTRAINT `properties_p24_province_id_foreign` FOREIGN KEY (`p24_province_id`) REFERENCES `p24_provinces` (`id`) ON DELETE SET NULL,
  CONSTRAINT `properties_p24_suburb_id_foreign` FOREIGN KEY (`p24_suburb_id`) REFERENCES `p24_suburbs` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `property_ad_templates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `property_ad_templates` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `layout_json` json NOT NULL,
  `is_global` tinyint(1) NOT NULL DEFAULT '0',
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `property_audit_log` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `property_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `branch_id` bigint unsigned DEFAULT NULL,
  `event_category` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `event_type` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `old_values` json DEFAULT NULL,
  `new_values` json DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `human_summary` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL,
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `property_buyer_matches` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `property_id` bigint unsigned NOT NULL,
  `contact_id` bigint unsigned NOT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `score` smallint unsigned NOT NULL,
  `tier` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `breakdown` json DEFAULT NULL,
  `missing_features` json DEFAULT NULL,
  `computed_at` timestamp NOT NULL,
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `property_files` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `property_id` bigint unsigned NOT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `size` bigint unsigned NOT NULL DEFAULT '0',
  `mime_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `document_type_id` bigint unsigned DEFAULT NULL,
  `contact_id` bigint unsigned DEFAULT NULL,
  `source_type` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'upload',
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `property_health_scores` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `property_id` bigint unsigned NOT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `score` tinyint unsigned NOT NULL DEFAULT '0' COMMENT '0-100',
  `grade` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'attention' COMMENT 'excellent, good, attention, critical',
  `factors` json DEFAULT NULL COMMENT 'Breakdown of each factor contribution',
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `property_image_analyses` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `property_id` bigint unsigned NOT NULL,
  `image_path` varchar(512) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Path relative to storage (matches values in properties.gallery_images_json etc.)',
  `status` enum('queued','processing','complete','failed') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'queued',
  `detected_features` json DEFAULT NULL COMMENT '[{token, confidence}]',
  `detected_spaces` json DEFAULT NULL COMMENT '[{token, confidence}]',
  `raw_response` json DEFAULT NULL COMMENT 'Full Claude vision response for debug',
  `cost_usd` decimal(8,5) DEFAULT NULL,
  `error` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `processed_at` timestamp NULL DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL COMMENT 'When an agent reviewed/dismissed these AI suggestions in the property workspace',
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `property_marketing_activities` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `property_id` bigint unsigned NOT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `activity_type` enum('portal_listed','portal_renewed','photos_refreshed','price_adjusted','show_day_held','social_share','featured_upgrade','marketing_email','other') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `activity_data` json DEFAULT NULL,
  `occurred_at` timestamp NOT NULL,
  `logged_by_user_id` bigint unsigned DEFAULT NULL,
  `internal_only` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `property_marketing_posts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `property_id` bigint unsigned NOT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `platform` enum('facebook','instagram') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `platform_post_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ad_copy` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `image_urls` json DEFAULT NULL,
  `status` enum('draft','published','failed') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `published_at` timestamp NULL DEFAULT NULL,
  `last_synced_at` timestamp NULL DEFAULT NULL,
  `impressions` int NOT NULL DEFAULT '0',
  `reach` int NOT NULL DEFAULT '0',
  `likes` int NOT NULL DEFAULT '0',
  `comments` int NOT NULL DEFAULT '0',
  `shares` int NOT NULL DEFAULT '0',
  `link_clicks` int NOT NULL DEFAULT '0',
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `property_notes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `property_id` bigint unsigned NOT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `content` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `property_presentation_snapshots` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `property_id` bigint unsigned NOT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `presentation_id` bigint unsigned DEFAULT NULL,
  `generated_at` timestamp NOT NULL,
  `generated_by_user_id` bigint unsigned DEFAULT NULL,
  `market_data_snapshot` json DEFAULT NULL,
  `recommended_price_at_time` decimal(14,2) DEFAULT NULL,
  `days_on_market_at_time` int unsigned DEFAULT NULL,
  `is_dynamic` tinyint(1) NOT NULL DEFAULT '0',
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `property_recommendations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `property_id` bigint unsigned NOT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `recommendation_code` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `reasoning` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `suggested_action` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `seller_facing_title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `seller_facing_reasoning` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `seller_visible` tinyint(1) NOT NULL DEFAULT '1',
  `generated_at` timestamp NOT NULL,
  `dismissed_at` timestamp NULL DEFAULT NULL,
  `dismissed_by` bigint unsigned DEFAULT NULL,
  `actioned_at` timestamp NULL DEFAULT NULL,
  `actioned_by` bigint unsigned DEFAULT NULL,
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `property_seller_link_accesses` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `link_id` bigint unsigned NOT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `accessed_at` timestamp NOT NULL,
  `ip_address` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`),
  KEY `property_seller_link_accesses_link_id_foreign` (`link_id`),
  KEY `property_seller_link_accesses_agency_id_idx` (`agency_id`),
  CONSTRAINT `property_seller_link_accesses_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `property_seller_link_accesses_link_id_foreign` FOREIGN KEY (`link_id`) REFERENCES `property_seller_links` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `property_seller_links`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `property_seller_links` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `property_id` bigint unsigned NOT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `token` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `contact_id` bigint unsigned NOT NULL,
  `generated_by_user_id` bigint unsigned DEFAULT NULL,
  `generated_at` timestamp NOT NULL,
  `last_accessed_at` timestamp NULL DEFAULT NULL,
  `access_count` int unsigned NOT NULL DEFAULT '0',
  `revoked_at` timestamp NULL DEFAULT NULL,
  `revoked_by_user_id` bigint unsigned DEFAULT NULL,
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `property_setting_items` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `group` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `title_type` enum('full_title','sectional_title','vacant_land','other') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'other' COMMENT 'Comp-selection discipline: houses do not compare to apartments. See .ai/specs/presentation-data-lineage.md §3-A.',
  `adjustment_pct` decimal(5,2) DEFAULT NULL COMMENT 'Build 3 — % adjustment applied to CMA Middle band when this condition_level is selected. Null for non-condition rows.',
  `sort_order` smallint unsigned NOT NULL DEFAULT '0',
  `is_default` tinyint(1) NOT NULL DEFAULT '0',
  `active` tinyint(1) NOT NULL DEFAULT '1',
  `agency_id` bigint unsigned NOT NULL,
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `property_sg_documents` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `property_id` bigint unsigned NOT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `sg_document_number` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `sg_page_number` smallint unsigned NOT NULL DEFAULT '1',
  `sg_doc_type` enum('DIAGRAM','GENERAL_PLAN','SERVITUDE','TITLE_DEED','OTHER') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'OTHER',
  `sg_source_url` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `storage_path` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `file_size_bytes` int unsigned DEFAULT NULL,
  `mime_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sha256` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_saved` tinyint(1) NOT NULL DEFAULT '0',
  `saved_at` timestamp NULL DEFAULT NULL,
  `saved_by_user_id` bigint unsigned DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `property_showdays` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `property_id` bigint unsigned NOT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `start_date` datetime NOT NULL,
  `end_date` datetime NOT NULL,
  `description` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Open Showday',
  `active` tinyint(1) NOT NULL DEFAULT '1',
  `synced_to_pp` tinyint(1) NOT NULL DEFAULT '0',
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `property_sold_records` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `property_id` bigint unsigned DEFAULT NULL,
  `external_property_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `address` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `suburb` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `area` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sold_price` decimal(14,2) NOT NULL,
  `sold_date` date NOT NULL,
  `bedrooms` smallint unsigned DEFAULT NULL,
  `bathrooms` decimal(3,1) DEFAULT NULL,
  `sqm` decimal(8,2) DEFAULT NULL,
  `property_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `days_on_market` int unsigned DEFAULT NULL,
  `listing_price_at_sale` decimal(14,2) DEFAULT NULL,
  `source` enum('manual','tva_api','p24_capture','pp_capture','deeds_office') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'manual',
  `source_reference` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `captured_by_user_id` bigint unsigned DEFAULT NULL,
  `captured_at` timestamp NULL DEFAULT NULL,
  `agency_id` bigint unsigned DEFAULT NULL,
  `verified` tinyint(1) NOT NULL DEFAULT '0',
  `verified_by_user_id` bigint unsigned DEFAULT NULL,
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `property_type_options` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `display_order` smallint unsigned NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `property_website_syndication` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `property_id` bigint unsigned NOT NULL,
  `agency_api_key_id` bigint unsigned NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT '0',
  `status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_submitted_at` timestamp NULL DEFAULT NULL,
  `activated_at` timestamp NULL DEFAULT NULL,
  `last_synced_at` timestamp NULL DEFAULT NULL,
  `last_error` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `prospecting_buyer_matches` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `prospecting_listing_id` bigint unsigned NOT NULL,
  `contact_id` bigint unsigned NOT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `score` smallint unsigned NOT NULL DEFAULT '0' COMMENT 'Match score 0-100',
  `tier` enum('perfect','strong','approximate') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'approximate',
  `matched_features` json DEFAULT NULL COMMENT 'What criteria matched',
  `missing_features` json DEFAULT NULL COMMENT 'What criteria are missing/gap',
  `matched_at` timestamp NOT NULL,
  `last_recompute_at` timestamp NULL DEFAULT NULL,
  `agent_notified_at` timestamp NULL DEFAULT NULL,
  `dismissed_at` timestamp NULL DEFAULT NULL,
  `dismissed_by_user_id` bigint unsigned DEFAULT NULL,
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `prospecting_claims` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `prospecting_listing_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'claimed',
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `claimed_at` timestamp NOT NULL,
  `feedback_at` timestamp NULL DEFAULT NULL,
  `last_updated_at` timestamp NULL DEFAULT NULL,
  `released_at` timestamp NULL DEFAULT NULL,
  `flagged_at` timestamp NULL DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `prospecting_listings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `matched_property_id` bigint unsigned DEFAULT NULL,
  `tracked_property_id` bigint unsigned DEFAULT NULL,
  `matched_at` timestamp NULL DEFAULT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `captured_by_user_id` bigint unsigned NOT NULL,
  `portal_source` enum('p24','pp') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `portal_ref` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `portal_url` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `address` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `normalized_address` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `property_group_id` bigint unsigned DEFAULT NULL,
  `suburb` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `latitude` decimal(10,7) DEFAULT NULL COMMENT 'Resolved by AddressResolverService — building-level when street parts present, suburb_centroid as last resort. Indexed for radius queries.',
  `longitude` decimal(10,7) DEFAULT NULL,
  `district` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `price` int NOT NULL,
  `bedrooms` smallint DEFAULT NULL,
  `bathrooms` smallint DEFAULT NULL,
  `garages` smallint DEFAULT NULL,
  `property_size_m2` decimal(10,2) DEFAULT NULL,
  `erf_size_m2` decimal(10,2) DEFAULT NULL,
  `property_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `agent_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `agency_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `thumbnail_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `thumbnail_source_url` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Original portal image URL the thumbnail was downloaded from — enables prospecting:rehydrate-thumbnails to re-fetch without a re-capture (AT-22 item 7).',
  `thumbnail_blocked_reason` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Why this thumbnail is blocked from seller surfaces (e.g. brand:remax, graphic). Set by ListingImageValidator content inspection. Null = not blocked (AT-22 item 2).',
  `first_seen_at` datetime NOT NULL,
  `last_seen_at` datetime NOT NULL,
  `first_seen_email_date` timestamp NULL DEFAULT NULL,
  `price_changed_at` datetime DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `prospecting_pitch_locks` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `prospecting_listing_id` bigint unsigned DEFAULT NULL,
  `tracked_property_id` bigint unsigned DEFAULT NULL,
  `user_id` bigint unsigned NOT NULL,
  `locked_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `released_at` timestamp NULL DEFAULT NULL,
  `release_reason` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `prospecting_price_history` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `prospecting_listing_id` bigint unsigned NOT NULL,
  `old_price` int NOT NULL,
  `new_price` int NOT NULL,
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `prospecting_searches` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `portal_source` enum('p24','pp') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `search_url` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `search_description` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `total_results` int NOT NULL,
  `pages_captured` int NOT NULL,
  `listing_count` int NOT NULL,
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `public_holidays` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `country_code` varchar(2) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'ZA',
  `holiday_date` date NOT NULL,
  `name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_movable` tinyint(1) NOT NULL DEFAULT '0',
  `applies_to_year` smallint unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `public_holidays_country_code_holiday_date_unique` (`country_code`,`holiday_date`),
  KEY `public_holidays_country_code_applies_to_year_index` (`country_code`,`applies_to_year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `rcr_answer_evidence`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `rcr_answer_evidence` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `answer_id` bigint unsigned NOT NULL,
  `evidence_type` enum('document_upload','corex_record_reference','external_url','note') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `document_path` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `corex_record_table` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `corex_record_id` bigint unsigned DEFAULT NULL,
  `external_url` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `added_by_user_id` bigint unsigned NOT NULL,
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `rcr_answers` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `submission_id` bigint unsigned NOT NULL,
  `question_id` bigint unsigned NOT NULL,
  `period_code` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'static',
  `answer_value` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `answer_data_json` json DEFAULT NULL,
  `is_auto_populated` tinyint(1) NOT NULL DEFAULT '0',
  `auto_population_source_data` json DEFAULT NULL,
  `manually_edited` tinyint(1) NOT NULL DEFAULT '0',
  `last_edited_at` timestamp NULL DEFAULT NULL,
  `last_edited_by_user_id` bigint unsigned DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `status` enum('unanswered','auto_filled','in_progress','answered','reviewed','approved') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'unanswered',
  `reviewer_user_id` bigint unsigned DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `copied_to_clipboard_at` timestamp NULL DEFAULT NULL,
  `copied_to_clipboard_count` int unsigned NOT NULL DEFAULT '0',
  `transposed_to_goaml_at` timestamp NULL DEFAULT NULL,
  `final_answer_format` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `rcr_questionnaire_sections` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `questionnaire_id` bigint unsigned NOT NULL,
  `section_code` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(300) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `sort_order` int unsigned NOT NULL DEFAULT '0',
  `has_period_columns` tinyint(1) NOT NULL DEFAULT '1',
  `applies_when_json` json DEFAULT NULL,
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `rcr_questionnaires` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `issued_by` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'FIC',
  `directive_reference` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reporting_period_from` date NOT NULL,
  `reporting_period_to` date NOT NULL,
  `submission_deadline` date NOT NULL,
  `submission_platform` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'FIC goAML',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `sort_order` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `rcr_questionnaires_key_unique` (`key`),
  KEY `rcr_q_active_deadline_idx` (`is_active`,`submission_deadline`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `rcr_questions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `rcr_questions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `questionnaire_id` bigint unsigned NOT NULL,
  `section_id` bigint unsigned NOT NULL,
  `question_code` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `parent_code` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `question_text` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `footnote` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `answer_type` enum('yes_no','yes_no_na','free_text','number','percentage','multi_select','single_select','file_upload','composite') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'free_text',
  `answer_options_json` json DEFAULT NULL,
  `is_required` tinyint(1) NOT NULL DEFAULT '1',
  `auto_population_source` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `evidence_source_codes_json` json DEFAULT NULL,
  `auto_populate_hint` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `help_text` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `sort_order` int unsigned NOT NULL DEFAULT '0',
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `rcr_submission_snapshots` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `submission_id` bigint unsigned NOT NULL,
  `snapshot_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `questionnaire_version_hash` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `taken_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `taken_by_user_id` bigint unsigned NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `rss_taker_fk` (`taken_by_user_id`),
  KEY `rss_sub_idx` (`submission_id`),
  CONSTRAINT `rss_sub_fk` FOREIGN KEY (`submission_id`) REFERENCES `rcr_submissions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `rss_taker_fk` FOREIGN KEY (`taken_by_user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `rcr_submissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `rcr_submissions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `questionnaire_id` bigint unsigned NOT NULL,
  `status` enum('draft','in_review','approved_for_submission','submitted','locked') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `reporting_period_from` date NOT NULL,
  `reporting_period_to` date NOT NULL,
  `submission_deadline` date NOT NULL,
  `submitted_at` timestamp NULL DEFAULT NULL,
  `submitted_by_user_id` bigint unsigned DEFAULT NULL,
  `submitted_to_platform_reference` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `locked_at` timestamp NULL DEFAULT NULL,
  `transposed_to_goaml_at` timestamp NULL DEFAULT NULL,
  `export_document_path` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `assigned_co_user_id` bigint unsigned DEFAULT NULL,
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `rental_agents` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `rental_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `rental_amount_versions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `rental_id` bigint unsigned NOT NULL,
  `effective_from` date NOT NULL,
  `rent_incl` decimal(12,2) NOT NULL,
  `rent_excl` decimal(12,2) NOT NULL,
  `commission_incl` decimal(12,2) NOT NULL,
  `commission_excl` decimal(12,2) NOT NULL,
  `created_by_user_id` bigint unsigned DEFAULT NULL,
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `rental_document_types` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `color` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT '#6B7280',
  `is_system` tinyint(1) NOT NULL DEFAULT '0',
  `is_lease` tinyint(1) NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `sort_order` int NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `rental_document_types_slug_unique` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `rental_properties`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `rental_properties` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `address_line_1` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `address_line_2` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `suburb` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `city` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `postal_code` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `province` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'KwaZulu-Natal',
  `full_address` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `property_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `landlord_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `landlord_email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `landlord_phone` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `monthly_rental` decimal(10,2) DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `rental_reminder_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `rental_reminder_settings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `enabled` tinyint(1) NOT NULL DEFAULT '1',
  `mode` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'escalating',
  `gentle_after_days` tinyint unsigned NOT NULL DEFAULT '2',
  `firm_after_days` tinyint unsigned NOT NULL DEFAULT '5',
  `team_alert_after_days` tinyint unsigned NOT NULL DEFAULT '7',
  `final_after_days` tinyint unsigned NOT NULL DEFAULT '10',
  `max_escalating_reminders` tinyint unsigned NOT NULL DEFAULT '3',
  `interval_days` tinyint unsigned NOT NULL DEFAULT '3',
  `max_simple_reminders` tinyint unsigned NOT NULL DEFAULT '5',
  `email_subject` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `email_body` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `updated_by` bigint unsigned DEFAULT NULL,
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `rentals` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `branch_id` bigint unsigned NOT NULL,
  `lease_address` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `lease_start_date` date NOT NULL,
  `lease_end_date` date DEFAULT NULL,
  `is_month_to_month` tinyint(1) NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `is_rental_assist` tinyint(1) NOT NULL DEFAULT '0',
  `created_by_user_id` bigint unsigned DEFAULT NULL,
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `revenue_share_ledger` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `commission_ledger_id` bigint unsigned NOT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `producing_agent_id` bigint unsigned NOT NULL,
  `receiving_agent_id` bigint unsigned NOT NULL,
  `tier` int NOT NULL,
  `company_dollar` decimal(12,2) NOT NULL,
  `share_percent` decimal(5,2) NOT NULL,
  `share_amount` decimal(10,2) NOT NULL,
  `status` enum('calculated','confirmed','paid') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'calculated',
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `rmcp_acknowledgements` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `branch_id` bigint unsigned DEFAULT NULL,
  `rmcp_version_id` bigint unsigned NOT NULL,
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
  `declaration_text` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `sections_acknowledged_count` int unsigned NOT NULL DEFAULT '0',
  `sections_total_count` int unsigned NOT NULL DEFAULT '0',
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `rmcp_compliance_officers_deprecated_20260421` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `full_name` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `id_number` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cell` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `title` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'FICA Compliance Officer',
  `appointed_on` date NOT NULL,
  `ended_on` date DEFAULT NULL,
  `appointed_by` bigint unsigned DEFAULT NULL,
  `appointment_notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `rmcp_section_acknowledgements` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `rmcp_acknowledgement_id` bigint unsigned NOT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `rmcp_section_id` bigint unsigned NOT NULL,
  `acknowledged` tinyint(1) NOT NULL DEFAULT '0',
  `acknowledged_at` timestamp NULL DEFAULT NULL,
  `acknowledgement_response` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ip_address` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `rmcp_sections` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `rmcp_version_id` bigint unsigned NOT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `section_type` enum('section','schedule','annexure','acknowledgement') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'section',
  `display_order` int unsigned NOT NULL,
  `section_number` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `body_html` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `requires_acknowledgement` tinyint(1) NOT NULL DEFAULT '1',
  `acknowledgement_prompt` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `rmcp_variables` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `variable_key` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `data_source` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'manual',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `rmcp_variables_agency_id_variable_key_unique` (`agency_id`,`variable_key`),
  CONSTRAINT `rmcp_variables_agency_id_foreign` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `rmcp_versions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `rmcp_versions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `version_number` int unsigned NOT NULL,
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Risk Management and Compliance Programme',
  `status` enum('draft','active','superseded') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `approved_by` bigint unsigned DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `approver_title` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `board_approval_document_path` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `approval_ip` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `approval_notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `effective_from` date DEFAULT NULL,
  `superseded_at` timestamp NULL DEFAULT NULL,
  `superseded_by_version_id` bigint unsigned DEFAULT NULL,
  `next_review_due` date DEFAULT NULL,
  `change_notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_by` bigint unsigned DEFAULT NULL,
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `role_permissions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `role` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `permission_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `scope` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `roles` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `label` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `color` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '#0d9488',
  `is_owner` tinyint(1) NOT NULL DEFAULT '0',
  `can_be_deleted` tinyint(1) NOT NULL DEFAULT '1',
  `sort_order` int NOT NULL DEFAULT '0',
  `agency_id` bigint unsigned DEFAULT NULL,
  `oversight_scope` enum('branch','agency') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sale_probability_runs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `market_analytics_run_id` bigint unsigned NOT NULL,
  `market_analytics_model_version` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `market_analytics_inputs_hash` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `model_version` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'e.g. prob-v1.0.0',
  `inputs_hash` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'SHA-256 of canonical inputs JSON',
  `inputs_json` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Canonical serialised input parameters',
  `outputs_json` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Flat probabilities + expected_days',
  `breakdown_json` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Signals, weights, composite score',
  `data_sources_json` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'market_analytics reference + future sources',
  `created_by` bigint unsigned DEFAULT NULL,
  `agency_id` bigint unsigned NOT NULL,
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sales_document_recipients` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `sales_document_send_id` bigint unsigned NOT NULL,
  `signing_order` int NOT NULL,
  `recipient_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `recipient_email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `recipient_role` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'client',
  `id_number` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `token` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `token_expires_at` timestamp NULL DEFAULT NULL,
  `status` enum('waiting','sent','downloaded','returned_pending_approval','approved','expired') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'waiting',
  `sent_at` timestamp NULL DEFAULT NULL,
  `downloaded_at` timestamp NULL DEFAULT NULL,
  `returned_at` timestamp NULL DEFAULT NULL,
  `returned_file_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `return_method` enum('upload','email') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reminder_count` int NOT NULL DEFAULT '0',
  `last_reminder_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `sdr_send_id_order_index` (`sales_document_send_id`,`signing_order`),
  CONSTRAINT `sales_document_recipients_sales_document_send_id_foreign` FOREIGN KEY (`sales_document_send_id`) REFERENCES `sales_document_sends` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sales_document_sends`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sales_document_sends` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `document_id` bigint unsigned DEFAULT NULL,
  `document_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `original_file_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sent_by` bigint unsigned NOT NULL,
  `message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `status` enum('in_progress','completed','expired') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'in_progress',
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `scheme_owners` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `market_report_id` bigint unsigned NOT NULL,
  `scheme_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `scheme_ss_number` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `section_number` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `flat_number` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `owner_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `extent_m2` int unsigned DEFAULT NULL,
  `property_type` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `latitude` decimal(10,7) DEFAULT NULL COMMENT 'Populated later via cross-link to scheme GPS.',
  `longitude` decimal(10,7) DEFAULT NULL,
  `contact_id` bigint unsigned DEFAULT NULL COMMENT 'Set when the owner is matched to a CoreX Contact (Phase later).',
  `matched_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `is_demo` tinyint(1) NOT NULL DEFAULT '0',
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `section_acceptances` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `signature_request_id` bigint unsigned NOT NULL,
  `section_index` int unsigned NOT NULL,
  `section_label` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `accepted` tinyint(1) NOT NULL DEFAULT '0',
  `rejected` tinyint(1) NOT NULL DEFAULT '0',
  `rejection_reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `initialled_at` timestamp NULL DEFAULT NULL,
  `initial_image` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `section_accept_req_idx_unique` (`signature_request_id`,`section_index`),
  CONSTRAINT `section_acceptances_signature_request_id_foreign` FOREIGN KEY (`signature_request_id`) REFERENCES `signature_requests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `seller_info_share_links`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `seller_info_share_links` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tier` enum('tier_1','tier_2','tier_3') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `seller_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `seller_email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `agent_message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `property_id` bigint unsigned DEFAULT NULL,
  `contact_id` bigint unsigned DEFAULT NULL,
  `sent_by_user_id` bigint unsigned NOT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `token` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `expires_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `accessed_count` int unsigned NOT NULL DEFAULT '0',
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `seller_mandate_lost_records` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `property_id` bigint unsigned NOT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `mandate_type` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reason_code` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `reason_label` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `recorded_by_user_id` bigint unsigned DEFAULT NULL,
  `recorded_at` timestamp NOT NULL,
  `source` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'manual',
  `listing_value_at_loss` decimal(14,2) DEFAULT NULL,
  `days_listed_at_loss` int unsigned DEFAULT NULL,
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `seller_outreach_callbacks` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `send_id` bigint unsigned NOT NULL,
  `contact_id` bigint unsigned NOT NULL,
  `requester_name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `requester_phone` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `requester_email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `preferred_time` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `ip_address` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `handled_by_user_id` bigint unsigned DEFAULT NULL,
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `seller_outreach_clicks` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `send_id` bigint unsigned NOT NULL,
  `clicked_at` timestamp NOT NULL,
  `ip_address` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `geo_country` char(2) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `seller_outreach_sends` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `contact_id` bigint unsigned NOT NULL,
  `property_id` bigint unsigned NOT NULL,
  `agent_id` bigint unsigned DEFAULT NULL,
  `template_id` bigint unsigned DEFAULT NULL,
  `channel` enum('whatsapp','email') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `subject_snapshot` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `body_snapshot` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `facts_snapshot` json NOT NULL,
  `tracking_short_code` char(6) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `opt_out_token` varchar(48) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `recipient_phone_snapshot` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `recipient_email_snapshot` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sent_at` timestamp NOT NULL,
  `first_clicked_at` timestamp NULL DEFAULT NULL,
  `outcome` enum('sent','clicked','replied','booked','no_response','not_interested','bounced') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'sent',
  `outcome_note` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `outcome_set_by_user_id` bigint unsigned DEFAULT NULL,
  `outcome_set_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `outreach_send_agency_code_unique` (`agency_id`,`tracking_short_code`),
  UNIQUE KEY `outreach_send_optout_token_uq` (`opt_out_token`),
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `seller_outreach_templates` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `channel` enum('whatsapp','email') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `subject` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `body` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `is_default_for_channel` tinyint(1) NOT NULL DEFAULT '0',
  `include_tracking_link` tinyint(1) NOT NULL DEFAULT '1',
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sessions` (
  `id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `ip_address` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `last_activity` int NOT NULL,
  PRIMARY KEY (`id`),
  KEY `sessions_user_id_index` (`user_id`),
  KEY `sessions_last_activity_index` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sg_search_cache`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sg_search_cache` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `query_hash` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `province` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `rural_urban` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `town` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `parcel_number` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `portion` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `farm_name` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `response_body` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `parsed_documents_json` json NOT NULL,
  `fetched_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` timestamp NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `sg_search_cache_query_hash_unique` (`query_hash`),
  KEY `sg_search_cache_expires_at_index` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `signature_audit_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `signature_audit_log` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `signature_template_id` bigint unsigned NOT NULL,
  `signature_request_id` bigint unsigned DEFAULT NULL,
  `action` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `actor_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `actor_id` bigint unsigned DEFAULT NULL,
  `actor_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `actor_email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `actor_ip_address` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `actor_user_agent` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `metadata_json` json DEFAULT NULL,
  `document_hash` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL,
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `signature_markers` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `signature_template_id` bigint unsigned NOT NULL,
  `page_number` int NOT NULL,
  `x_position` decimal(8,4) NOT NULL,
  `y_position` decimal(8,4) NOT NULL,
  `width` decimal(8,4) NOT NULL DEFAULT '20.0000',
  `height` decimal(8,4) NOT NULL DEFAULT '5.0000',
  `type` enum('signature','initial','date','text') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'signature',
  `assigned_party` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `assigned_email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `label` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sort_order` int NOT NULL DEFAULT '0',
  `required` tinyint(1) NOT NULL DEFAULT '1',
  `from_template_zone_id` bigint unsigned DEFAULT NULL,
  `from_zone_id` bigint unsigned DEFAULT NULL,
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `signature_requests` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `signature_template_id` bigint unsigned NOT NULL,
  `party_role` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `role_index` smallint unsigned NOT NULL DEFAULT '1',
  `signing_order` int NOT NULL DEFAULT '1',
  `signer_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `signer_email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `signer_id_number` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `token` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `token_expires_at` timestamp NOT NULL,
  `status` enum('waiting','pending','viewed','partially_signed','completed','expired','declined','deferred','cancelled') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'waiting',
  `returned_notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `authorised_by` bigint unsigned DEFAULT NULL,
  `authorised_at` timestamp NULL DEFAULT NULL,
  `sent_at` timestamp NULL DEFAULT NULL,
  `viewed_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `reminder_sent_at` timestamp NULL DEFAULT NULL,
  `team_alerted_at` timestamp NULL DEFAULT NULL,
  `reminder_count` int NOT NULL DEFAULT '0',
  `ip_address` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `sent_by` bigint unsigned DEFAULT NULL,
  `message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `signing_method` enum('electronic','wet_ink') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fica_required` tinyint(1) NOT NULL DEFAULT '0',
  `contact_id` bigint unsigned DEFAULT NULL,
  `fica_submission_id` bigint unsigned DEFAULT NULL,
  `wet_ink_upload_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `wet_ink_upload_method` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `wet_ink_status` enum('pending_upload','uploaded_pending_review','approved','rejected') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `wet_ink_rejection_note` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `reviewed_by` bigint unsigned DEFAULT NULL,
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `signature_templates` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `document_id` bigint unsigned NOT NULL,
  `document_hash` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('draft','ready','signing','awaiting_tenant','awaiting_landlord','awaiting_buyer','awaiting_seller','awaiting_supervisor','awaiting_supervisor_final','pending_agent_approval','returned_to_candidate','completed','expired','declined','rejected','partial','awaiting_deferred','amendment_review','amendment_initialing','cancelled') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `document_version` int unsigned NOT NULL DEFAULT '1',
  `amendment_status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `parties_json` json DEFAULT NULL,
  `signing_order_json` json DEFAULT NULL,
  `cosign_mode` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `supersedes_id` bigint unsigned DEFAULT NULL,
  `superseded_by_id` bigint unsigned DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `is_candidate_flow` tinyint(1) NOT NULL DEFAULT '0',
  `supervisor_user_id` bigint unsigned DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `rejected_at` timestamp NULL DEFAULT NULL,
  `rejection_reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `cancellation_reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `cancelled_by` bigint unsigned DEFAULT NULL,
  `cancelled_at` timestamp NULL DEFAULT NULL,
  `rejected_by` bigint unsigned DEFAULT NULL,
  `signed_pdf_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `signed_pdf_client_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `flattened_pages_json` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `sections_json` json DEFAULT NULL,
  `other_conditions_text` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `signature_zones` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `signature_template_id` bigint unsigned NOT NULL,
  `zone_type` enum('signature','initial','other_conditions') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'signature',
  `party_role` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `assigned_parties` json DEFAULT NULL,
  `page_number` int NOT NULL,
  `x_position` decimal(8,4) NOT NULL,
  `y_position` decimal(8,4) NOT NULL,
  `width` decimal(8,4) NOT NULL DEFAULT '25.0000',
  `height` decimal(8,4) NOT NULL DEFAULT '8.0000',
  `is_auto_placed` tinyint(1) NOT NULL DEFAULT '0',
  `source` enum('template','setup') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'setup',
  `label` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sort_order` int NOT NULL DEFAULT '0',
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `signatures` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `signature_template_id` bigint unsigned NOT NULL,
  `signature_marker_id` bigint unsigned NOT NULL,
  `signature_request_id` bigint unsigned DEFAULT NULL,
  `signer_user_id` bigint unsigned DEFAULT NULL,
  `signer_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `signer_email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `signer_ip_address` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `signer_user_agent` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `signature_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `text_value` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `signature_type` enum('drawn','typed') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'drawn',
  `signed_at` timestamp NOT NULL,
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `signed_document_versions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `document_id` bigint unsigned NOT NULL,
  `signature_request_id` bigint unsigned DEFAULT NULL,
  `version_number` int NOT NULL DEFAULT '1',
  `file_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_type` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `uploaded_by_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `uploaded_at` timestamp NULL DEFAULT NULL,
  `ip_address` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `agent_approved` tinyint(1) NOT NULL DEFAULT '0',
  `agent_approved_at` timestamp NULL DEFAULT NULL,
  `approved_by` bigint unsigned DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
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
DROP TABLE IF EXISTS `soft_delete_restorations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `soft_delete_restorations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `model_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `model_id` bigint unsigned NOT NULL,
  `model_label` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `agency_id` bigint unsigned DEFAULT NULL,
  `restored_by_user_id` bigint unsigned NOT NULL,
  `restored_at` timestamp NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `soft_delete_restorations_model_type_model_id_index` (`model_type`,`model_id`),
  KEY `soft_delete_restorations_agency_id_index` (`agency_id`),
  KEY `soft_delete_restorations_restored_by_user_id_index` (`restored_by_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `staff_take_on_records`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `staff_take_on_records` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `branch_id` bigint unsigned DEFAULT NULL,
  `user_id` bigint unsigned NOT NULL,
  `payroll_employee_id` bigint unsigned DEFAULT NULL,
  `take_on_date` date NOT NULL,
  `previous_employer` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `previous_employment_start_date` date DEFAULT NULL,
  `original_employment_start_date` date NOT NULL,
  `take_on_type` enum('new_hire','migration_from_old_system','transfer_from_other_branch') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `personal_details_verified` tinyint(1) NOT NULL DEFAULT '0',
  `banking_details_verified` tinyint(1) NOT NULL DEFAULT '0',
  `tax_details_verified` tinyint(1) NOT NULL DEFAULT '0',
  `employment_terms_verified` tinyint(1) NOT NULL DEFAULT '0',
  `compensation_setup_verified` tinyint(1) NOT NULL DEFAULT '0',
  `leave_balances_captured` tinyint(1) NOT NULL DEFAULT '0',
  `compliance_documents_uploaded` tinyint(1) NOT NULL DEFAULT '0',
  `signed_employment_contract_uploaded` tinyint(1) NOT NULL DEFAULT '0',
  `completed_at` timestamp NULL DEFAULT NULL,
  `completed_by_user_id` bigint unsigned DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `current_step` enum('user','personal','tax_banking','employment','compensation','leave','compliance','review') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `suggested_action_thresholds` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `stale_listing_days` smallint unsigned NOT NULL DEFAULT '14',
  `expiry_warning_hours` smallint unsigned NOT NULL DEFAULT '6',
  `outcome_overdue_days` smallint unsigned NOT NULL DEFAULT '2',
  `outcome_stale_days` smallint unsigned NOT NULL DEFAULT '30',
  `follow_up_days` smallint unsigned NOT NULL DEFAULT '7',
  `pitch_recency_days` smallint unsigned NOT NULL DEFAULT '7',
  `high_value_strong_min` smallint unsigned NOT NULL DEFAULT '3',
  `stock_repitch_days` smallint unsigned NOT NULL DEFAULT '30',
  `colleague_claim_stale_days` smallint unsigned NOT NULL DEFAULT '21',
  `investigate_mid_min` smallint unsigned NOT NULL DEFAULT '5',
  `new_listing_lookback_days` smallint unsigned NOT NULL DEFAULT '3',
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `targets` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `period` varchar(7) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `branch_id` bigint unsigned DEFAULT NULL,
  `listings_target` int NOT NULL DEFAULT '0',
  `deals_target` int NOT NULL DEFAULT '0',
  `value_target` decimal(14,2) NOT NULL DEFAULT '0.00',
  `points_target` int NOT NULL DEFAULT '0',
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
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
DROP TABLE IF EXISTS `template_validation_errors`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `template_validation_errors` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `template_id` bigint unsigned NOT NULL,
  `error_code` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `error_message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `resolved_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `tve_template_resolved_idx` (`template_id`,`resolved_at`),
  KEY `tve_error_code_idx` (`error_code`),
  CONSTRAINT `template_validation_errors_template_id_foreign` FOREIGN KEY (`template_id`) REFERENCES `docuperfect_templates` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tool_history_entries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tool_history_entries` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `branch_id` bigint unsigned DEFAULT NULL,
  `type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `ref` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `occurred_at` timestamp NOT NULL,
  `property` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` decimal(14,2) NOT NULL,
  `agent_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `town_suburbs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `town_id` bigint unsigned NOT NULL,
  `suburb_name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `suburb_normalised` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `towns` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `region` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `display_order` smallint unsigned NOT NULL DEFAULT '0',
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tracked_properties` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `external_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `street_number` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `street_name` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `unit_number` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `complex_name` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `suburb` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `suburb_normalised` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `town` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `province` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `postal_code` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `latitude` decimal(10,7) DEFAULT NULL,
  `longitude` decimal(10,7) DEFAULT NULL,
  `geo_source` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `geo_confidence` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `geocode_needs_review` tinyint(1) NOT NULL DEFAULT '0',
  `geo_resolved_at` timestamp NULL DEFAULT NULL,
  `cma_gps_lat` decimal(10,7) DEFAULT NULL,
  `cma_gps_lng` decimal(10,7) DEFAULT NULL,
  `erf_number` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `title_deed_number` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cadastral_extent` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `municipal_valuation` decimal(15,2) DEFAULT NULL,
  `municipal_valuation_year` smallint unsigned DEFAULT NULL,
  `last_known_asking_price` decimal(15,2) DEFAULT NULL,
  `last_known_sold_price` decimal(15,2) DEFAULT NULL,
  `last_known_sold_date` date DEFAULT NULL,
  `property_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bedrooms` tinyint unsigned DEFAULT NULL,
  `bathrooms` tinyint unsigned DEFAULT NULL,
  `garages` tinyint unsigned DEFAULT NULL,
  `floor_size_m2` decimal(10,2) DEFAULT NULL,
  `erf_size_m2` decimal(10,2) DEFAULT NULL,
  `promoted_to_property_id` bigint unsigned DEFAULT NULL,
  `promoted_at` timestamp NULL DEFAULT NULL,
  `promoted_by_user_id` bigint unsigned DEFAULT NULL,
  `owner_contact_id` bigint unsigned DEFAULT NULL,
  `source_chain` json DEFAULT NULL,
  `first_seen_at` timestamp NULL DEFAULT NULL,
  `last_enriched_at` timestamp NULL DEFAULT NULL,
  `last_enrichment_source` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('active','archived','duplicate','promoted') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `duplicate_of_tracked_property_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `is_demo` tinyint(1) NOT NULL DEFAULT '0',
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tracked_property_addresses` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `tracked_property_id` bigint unsigned NOT NULL,
  `street_number` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `street_name` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Normalised on write (St→Street, Rd→Road, …) — see TrackedPropertyMatchOrCreateService::normaliseStreetName().',
  `unit_number` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `complex_name` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `suburb` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `suburb_normalised` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Lowercase + strip punctuation + collapse whitespace; see TrackedProperty::normaliseSuburb().',
  `town` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `province` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `postal_code` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `latitude` decimal(10,7) DEFAULT NULL,
  `longitude` decimal(10,7) DEFAULT NULL,
  `source_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'p24 | pp | chrome_capture | cmainfo | manual_agent | manual_admin | deeds_office',
  `source_ref` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'The originating record ID (portal listing id, presentation id, capture id, etc).',
  `confidence` enum('low','medium','high','verified') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'low' COMMENT 'verified = agent-confirmed; promotes to primary per spec §3.2.1.',
  `is_primary` tinyint(1) NOT NULL DEFAULT '0',
  `verified_by_user_id` bigint unsigned DEFAULT NULL,
  `verified_at` timestamp NULL DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tracked_property_external_refs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `tracked_property_id` bigint unsigned NOT NULL,
  `source_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_ref` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_payload` json DEFAULT NULL,
  `first_seen_at` timestamp NOT NULL,
  `last_seen_at` timestamp NOT NULL,
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `training_completions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `course_id` bigint unsigned NOT NULL,
  `completed_at` timestamp NOT NULL,
  `acknowledged_at` timestamp NULL DEFAULT NULL,
  `acknowledgement_signature` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `certificate_path` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `training_courses` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `category` enum('compliance','onboarding','sales','systems','general') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'general',
  `is_required` tinyint(1) NOT NULL DEFAULT '0',
  `is_required_for_activation` tinyint(1) NOT NULL DEFAULT '0',
  `sort_order` int NOT NULL DEFAULT '0',
  `is_published` tinyint(1) NOT NULL DEFAULT '1',
  `created_by` bigint unsigned DEFAULT NULL,
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `training_doc_bookmarks` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `doc_id` bigint unsigned NOT NULL,
  `section_anchor` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `note` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `training_doc_chunks` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `doc_id` bigint unsigned NOT NULL,
  `chunk_index` smallint unsigned NOT NULL,
  `heading_path` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `section_anchor` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `content` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `word_count` int unsigned NOT NULL DEFAULT '0',
  `embedding` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `has_embedding` tinyint(1) NOT NULL DEFAULT '0',
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `training_doc_reads` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `doc_id` bigint unsigned NOT NULL,
  `agency_id` bigint unsigned DEFAULT NULL,
  `sections_completed` json DEFAULT NULL,
  `last_section_read` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `training_docs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `slug` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `role_audience` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'all',
  `file_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `content_hash` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `word_count` int unsigned NOT NULL DEFAULT '0',
  `reading_time_minutes` smallint unsigned NOT NULL DEFAULT '0',
  `is_required` tinyint(1) NOT NULL DEFAULT '0',
  `sort_order` smallint unsigned NOT NULL DEFAULT '0',
  `version` smallint unsigned NOT NULL DEFAULT '1',
  `last_indexed_at` timestamp NULL DEFAULT NULL,
  `agency_id` bigint unsigned DEFAULT NULL,
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `training_lessons` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `course_id` bigint unsigned NOT NULL,
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `content` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `content_type` enum('text','video_url','document','link') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'text',
  `video_url` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `document_path` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `external_link` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `duration_minutes` int NOT NULL DEFAULT '10',
  `sort_order` int NOT NULL DEFAULT '0',
  `is_published` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `training_lessons_course_id_sort_order_index` (`course_id`,`sort_order`),
  CONSTRAINT `training_lessons_course_id_foreign` FOREIGN KEY (`course_id`) REFERENCES `training_courses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `training_progress`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `training_progress` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `course_id` bigint unsigned NOT NULL,
  `lesson_id` bigint unsigned NOT NULL,
  `started_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `time_spent_seconds` int NOT NULL DEFAULT '0',
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tv_access_codes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `branch_id` bigint unsigned DEFAULT NULL,
  `code` varchar(6) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tv_messages` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `branch_id` bigint unsigned DEFAULT NULL,
  `created_by_user_id` bigint unsigned DEFAULT NULL,
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `display_area` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'both',
  `is_enabled` tinyint(1) NOT NULL DEFAULT '1',
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_banking_details` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `account_holder` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `bank_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `branch_code` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `account_number` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `account_type` enum('cheque','savings','transmission') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_primary` tinyint(1) NOT NULL DEFAULT '1',
  `verified_at` timestamp NULL DEFAULT NULL,
  `verified_by` bigint unsigned DEFAULT NULL,
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_compliance_overrides` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `branch_id` bigint unsigned DEFAULT NULL,
  `compliance_item` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `override_type` enum('exempt','waived','not_applicable') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_by` bigint unsigned NOT NULL,
  `expires_at` date DEFAULT NULL,
  `revoked_by` bigint unsigned DEFAULT NULL,
  `revoked_at` timestamp NULL DEFAULT NULL,
  `revoke_reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_dashboard_settings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `idle_alerts_enabled` tinyint(1) NOT NULL DEFAULT '1',
  `idle_threshold_days` smallint unsigned NOT NULL DEFAULT '14',
  `idle_alert_day` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'monday-sunday or null for daily',
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
  UNIQUE KEY `user_dashboard_settings_user_id_unique` (`user_id`),
  CONSTRAINT `user_dashboard_settings_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `user_documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_documents` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned DEFAULT NULL,
  `branch_id` bigint unsigned DEFAULT NULL,
  `user_id` bigint unsigned NOT NULL,
  `document_type` enum('ffc_certificate','id_copy','pi_insurance','tax_clearance','profile_photo','qualification','proof_of_address','bank_confirmation','police_clearance','credit_check_report','reference_letter','other','payslip') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_path` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_size` int unsigned DEFAULT NULL,
  `mime_type` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('pending','verified','rejected','expired') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `expiry_date` date DEFAULT NULL,
  `verified_by` bigint unsigned DEFAULT NULL,
  `verified_at` timestamp NULL DEFAULT NULL,
  `rejected_reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `rejected_by` bigint unsigned DEFAULT NULL,
  `rejected_at` timestamp NULL DEFAULT NULL,
  `uploaded_by` bigint unsigned DEFAULT NULL,
  `uploaded_by_admin` tinyint(1) NOT NULL DEFAULT '0',
  `admin_upload_reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_notification_preferences` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `notification_event_type_id` bigint unsigned NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT '1',
  `threshold` int unsigned DEFAULT NULL,
  `channel_in_app` tinyint(1) NOT NULL DEFAULT '1',
  `channel_email` tinyint(1) NOT NULL DEFAULT '0',
  `channel_push` tinyint(1) NOT NULL DEFAULT '1',
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_oversight_preferences` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `category` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT '1',
  `threshold_hours` int unsigned DEFAULT NULL,
  `notify_channel` enum('email','in_app','both') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'in_app',
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `qr_code_slug` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `qr_reroute_user_id` bigint unsigned DEFAULT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `role` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'agent',
  `risk_tier` enum('high','medium','low') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'medium',
  `screening_status` enum('never_screened','pre_employment_pending','clear','concerns_flagged','overdue','expired') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'never_screened',
  `screening_due_on` date DEFAULT NULL,
  `designation` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `employment_date` date DEFAULT NULL,
  `supervised_by` bigint unsigned DEFAULT NULL,
  `is_admin` tinyint(1) NOT NULL DEFAULT '0',
  `target_listings` int NOT NULL DEFAULT '0',
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `remember_token` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `api_token` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `branch_id` bigint unsigned DEFAULT NULL,
  `agency_id` bigint unsigned DEFAULT NULL,
  `anniversary_date` date DEFAULT NULL,
  `sponsored_by_user_id` bigint unsigned DEFAULT NULL,
  `agent_tier` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'standard',
  `is_mentor_eligible` tinyint(1) NOT NULL DEFAULT '0',
  `agent_photo_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ffc_certificate_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pi_insurance_path` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pi_insurance_expiry` date DEFAULT NULL,
  `tax_clearance_path` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tax_clearance_expiry` date DEFAULT NULL,
  `phone` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cell` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `id_number` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `date_of_birth` date DEFAULT NULL,
  `tax_reference_number` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `id_document_path` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fax` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ffc_number` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ffc_expiry_date` date DEFAULT NULL,
  `ppra_status` enum('active','pending','expired','suspended') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ppra_last_verified_at` date DEFAULT NULL,
  `website` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `about_me` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `theme` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'dark',
  `last_presentation_send_channel` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_presentation_send_mode` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `portal_show_api_token` tinyint(1) NOT NULL DEFAULT '1',
  `portal_show_social_accounts` tinyint(1) NOT NULL DEFAULT '1',
  `pp_unique_agent_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pp_external_ref` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `agent_cut_percent` decimal(5,2) DEFAULT NULL,
  `paye_method` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `paye_value` decimal(10,2) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `counts_for_branch_split` tinyint(1) NOT NULL DEFAULT '1',
  `can_capture_rentals` tinyint(1) NOT NULL DEFAULT '0',
  `sliding_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `sliding_tier1_cut_percent` decimal(5,2) DEFAULT NULL,
  `sliding_tier2_cut_percent` decimal(5,2) DEFAULT NULL,
  `sliding_tier3_cut_percent` decimal(5,2) DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `p24_agent_id` int DEFAULT NULL,
  `source_reference` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `emergency_contact_name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `emergency_contact_phone` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `emergency_contact_relationship` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `next_of_kin_name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `next_of_kin_phone` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `next_of_kin_relationship` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `home_address` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `marital_status` enum('single','married','divorced','widowed','life_partner','other') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `dependents_count` tinyint unsigned NOT NULL DEFAULT '0',
  `medical_aid_provider` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `medical_aid_number` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `medical_aid_main_member` tinyint(1) NOT NULL DEFAULT '0',
  `medical_aid_dependents_count` tinyint unsigned NOT NULL DEFAULT '0',
  `show_on_website` tinyint(1) NOT NULL DEFAULT '0',
  `website_order` int unsigned DEFAULT NULL,
  `website_social_facebook` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `website_social_instagram` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `website_social_linkedin` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `website_social_youtube` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `web_pack_items` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `web_pack_id` bigint unsigned NOT NULL,
  `template_id` bigint unsigned NOT NULL,
  `sort_order` int NOT NULL DEFAULT '0',
  `slot_type` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'required',
  `slot_group` int unsigned DEFAULT NULL,
  `slot_label` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `web_packs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `created_by` bigint unsigned NOT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `wet_ink_inspections` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `signature_request_id` bigint unsigned NOT NULL,
  `inspector_user_id` bigint unsigned NOT NULL,
  `checklist_json` json NOT NULL,
  `result` enum('approved','rejected') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `whistleblow_audit_log` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `complaint_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `action` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `action_data` json DEFAULT NULL,
  `created_at` timestamp NOT NULL,
  PRIMARY KEY (`id`),
  KEY `whistleblow_audit_log_complaint_id_foreign` (`complaint_id`),
  KEY `whistleblow_audit_log_user_id_foreign` (`user_id`),
  CONSTRAINT `whistleblow_audit_log_complaint_id_foreign` FOREIGN KEY (`complaint_id`) REFERENCES `whistleblow_complaints` (`id`) ON DELETE CASCADE,
  CONSTRAINT `whistleblow_audit_log_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `whistleblow_complaint_evidence`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `whistleblow_complaint_evidence` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `complaint_id` bigint unsigned NOT NULL,
  `evidence_type` enum('screenshot','portal_html','seller_statement_pdf','photo','audio_recording','document_upload','other') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `original_filename` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mime_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `size_bytes` int DEFAULT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `uploaded_by_user_id` bigint unsigned NOT NULL,
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `whistleblow_complaint_subjects` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `complaint_id` bigint unsigned NOT NULL,
  `agency_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `practitioner_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `portal_url` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `portal_source` enum('p24','pp','other') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `portal_listing_ref` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `display_order` int NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `whistleblow_complaint_subjects_complaint_id_foreign` (`complaint_id`),
  CONSTRAINT `whistleblow_complaint_subjects_complaint_id_foreign` FOREIGN KEY (`complaint_id`) REFERENCES `whistleblow_complaints` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `whistleblow_complaints`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `whistleblow_complaints` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `agency_id` bigint unsigned NOT NULL,
  `branch_id` bigint unsigned DEFAULT NULL,
  `reported_by_user_id` bigint unsigned NOT NULL,
  `tier` enum('tier_1','tier_2','tier_3') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `property_id` bigint unsigned DEFAULT NULL,
  `property_address` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `seller_contact_id` bigint unsigned DEFAULT NULL,
  `seller_statement` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `agent_notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `status` enum('draft','pending_approval','changes_requested','rejected','approved','sent','acknowledged_by_ppra','closed') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `approved_by_user_id` bigint unsigned DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `approval_notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `rejected_by_user_id` bigint unsigned DEFAULT NULL,
  `rejected_at` timestamp NULL DEFAULT NULL,
  `rejection_reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `sent_to_ppra_at` timestamp NULL DEFAULT NULL,
  `ppra_reference_number` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ppra_acknowledged_at` timestamp NULL DEFAULT NULL,
  `complaint_pdf_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `whistleblow_email_log` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `complaint_id` bigint unsigned DEFAULT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `sent_at` timestamp NOT NULL,
  `email_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'ppra_submission',
  `subject` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `recipients_to` json NOT NULL,
  `recipients_cc` json DEFAULT NULL,
  `recipients_bcc` json DEFAULT NULL,
  `rendered_html` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `rendered_text` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `attachments` json DEFAULT NULL,
  `sent_by_user_id` bigint unsigned DEFAULT NULL,
  `mail_message_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('sent','failed') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'sent',
  `error_message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `wishlist_migration_log` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `run_id` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_buyer_preference_id` bigint unsigned NOT NULL,
  `target_contact_match_id` bigint unsigned DEFAULT NULL,
  `contact_id` bigint unsigned NOT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `action` enum('would_create','would_append','would_merge','would_skip','would_fail','created','appended','merged','skipped','failed') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `mode` enum('dry_run','live') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `field_mapping_snapshot` json DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `wml_run_idx` (`run_id`),
  KEY `wml_contact_idx` (`contact_id`),
  KEY `wml_action_idx` (`action`,`mode`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `worksheets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `worksheets` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `agency_id` bigint unsigned NOT NULL,
  `period` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `personal_net_target` decimal(10,2) NOT NULL DEFAULT '0.00',
  `business_net_target` decimal(10,2) NOT NULL DEFAULT '0.00',
  `want_net_target` decimal(10,2) NOT NULL DEFAULT '0.00',
  `avg_sale_price` decimal(12,2) NOT NULL DEFAULT '1060000.00',
  `avg_sale_price_admin` decimal(12,2) DEFAULT NULL,
  `commission_percent` decimal(5,2) NOT NULL DEFAULT '7.50',
  `commission_percent_admin` decimal(5,2) DEFAULT NULL,
  `commission_percent_locked` tinyint(1) NOT NULL DEFAULT '0',
  `paye_percent` decimal(5,2) NOT NULL DEFAULT '18.00',
  `agent_split_percent` decimal(5,2) NOT NULL DEFAULT '50.00',
  `correctly_priced_percent` decimal(5,2) NOT NULL DEFAULT '40.00',
  `current_listings` int NOT NULL DEFAULT '0',
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
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (154,'2026_02_26_600001_create_signature_templates_table',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (155,'2026_02_26_600002_create_signature_markers_table',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (156,'2026_02_26_600003_create_signature_requests_table',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (157,'2026_02_26_600004_create_signatures_table',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (158,'2026_02_26_600005_create_signature_audit_log_table',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (159,'2026_02_26_600006_create_wet_ink_inspections_table',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (160,'2026_02_26_600007_create_lease_records_table',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (161,'2026_02_26_600008_add_team_alerted_at_to_signature_requests',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (162,'2026_02_26_600009_add_signed_pdf_path_to_signature_templates',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (163,'2026_02_26_700001_add_pending_agent_approval_status_to_signature_templates',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (164,'2026_02_26_700001_create_sales_document_sends_table',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (165,'2026_02_26_700002_create_sales_document_recipients_table',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (166,'2026_02_26_800001_create_docuperfect_template_signature_zones_table',3);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (167,'2026_02_26_800002_add_from_template_zone_id_to_signature_markers',4);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (168,'2026_02_26_900001_add_signed_pdf_client_path_to_signature_templates',4);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (169,'2026_02_26_900002_add_flattened_pages_json_to_signature_templates',4);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (170,'2026_02_26_950001_create_rental_properties_and_document_types_tables',4);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (171,'2026_02_27_000001_add_lease_expiry_to_docuperfect_documents',4);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (172,'2026_02_27_100001_create_rental_reminder_settings_table',4);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (173,'2026_02_27_121337_add_commission_percent_admin_and_locked_to_worksheets_table',4);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (174,'2026_02_27_200001_add_wet_ink_upload_method_to_signature_requests',4);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (175,'2026_02_27_300001_add_cosign_mode_to_signature_templates',4);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (176,'2026_02_27_400001_add_supersede_columns_to_signature_templates',4);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (177,'2026_02_28_100001_add_text_value_to_signatures_table',4);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (178,'2026_02_28_163349_make_template_id_nullable_on_docuperfect_documents',4);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (179,'2026_02_28_200001_add_id_number_to_sales_document_recipients',4);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (180,'2026_03_01_000001_create_property_ad_templates_table',4);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (181,'2026_03_02_000001_add_embedding_to_knowledge_chunks_table',5);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (182,'2026_02_26_151853_add_pending_agent_approval_status_to_signature_templates',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (183,'2026_03_03_000001_create_document_types_table',7);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (184,'2026_03_03_000002_create_splitter_doc_types_table',8);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (185,'2026_03_03_000003_add_seller_live_capture_json_to_presentations',9);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (186,'2026_03_03_000004_add_agent_uploads_to_users',10);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (187,'2026_03_04_000001_add_primary_image_url_to_portal_listings',11);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (188,'2026_03_05_110912_add_type_and_module_to_nexus_permissions',12);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (189,'2026_03_05_115116_add_scope_to_role_permissions',12);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (190,'2026_03_06_000001_create_roles_table',12);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (191,'2026_03_06_000002_seed_existing_roles',12);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (192,'2026_03_06_100001_add_soft_deletes_tier1',12);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (193,'2026_03_06_100002_add_soft_deletes_tier2_docuperfect',12);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (194,'2026_03_06_100003_add_soft_deletes_tier3',12);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (195,'2026_03_06_100004_add_soft_deletes_tool_history_entries',12);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (196,'2026_03_05_000001_create_contact_types_table',13);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (197,'2026_03_05_000002_add_contact_details_notes_documents',13);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (198,'2026_03_05_100001_add_extra_fields_to_properties_table',13);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (199,'2026_03_05_100002_create_property_setting_items_table',13);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (200,'2026_03_05_100003_create_property_notes_table',13);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (201,'2026_03_05_100004_create_property_files_table',13);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (202,'2026_03_05_200001_create_contact_property_table',13);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (203,'2026_03_05_300001_add_spaces_json_to_properties_table',13);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (204,'2026_03_05_300002_add_defaults_to_property_setting_items',13);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (205,'2026_03_05_300003_seed_default_setting_items',13);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (206,'2026_03_06_000001_add_contact_fields_to_users_table',14);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (207,'2026_03_06_100001_create_agent_social_accounts_table',15);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (208,'2026_03_06_100002_create_property_marketing_posts_table',15);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (209,'2026_03_06_200001_seed_office_admin_permissions_from_admin',15);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (210,'2026_03_07_100001_create_contact_matches_table',16);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (211,'2026_03_07_100001_create_flows_table',16);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (212,'2026_03_07_100002_add_share_token_to_contact_matches_table',99);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (213,'2026_03_07_100002_add_wizard_config_to_docuperfect_templates',100);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (214,'2026_03_07_100003_add_hidden_property_ids_to_contact_matches_table',100);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (215,'2026_03_07_100004_add_property_view_counts_to_contact_matches_table',100);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (216,'2026_03_07_200001_add_rental_lease_fields_to_properties',100);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (217,'2026_03_07_200002_seed_contact_types_seller_buyer_witness',100);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (218,'2026_03_07_200003_add_bank_details_to_contacts',100);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (219,'2026_03_07_200004_add_source_mapping_to_named_fields',100);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (220,'2026_03_09_111608_add_company_details_to_agencies_table',100);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (221,'2026_03_09_133314_add_contact_details_to_branches_table',100);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (222,'2026_03_09_141826_add_phone_secondary_to_agencies_and_branches_table',100);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (223,'2026_03_09_145301_add_phone_labels_to_agencies_and_branches_table',100);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (224,'2026_03_09_183429_rename_agency_brand_colours_to_semantic_roles',100);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (225,'2026_03_10_051542_add_render_type_to_docuperfect_templates_table',100);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (226,'2026_03_10_120000_add_web_template_data_to_docuperfect_documents',100);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (227,'2026_03_10_132257_create_web_packs_table',100);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (228,'2026_03_10_132258_create_web_pack_items_table',100);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (229,'2026_03_10_140528_create_document_custom_fields_table',100);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (230,'2026_03_10_232742_add_theme_to_users_table',100);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (231,'2026_03_11_100001_add_soft_deletes_to_all_remaining_tables',100);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (232,'2026_03_11_100002_add_soft_deletes_to_docuperfect_and_rental_tables',100);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (233,'2026_03_11_000001_add_is_esign_to_docuperfect_templates',101);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (234,'2026_03_11_100000_create_docuperfect_field_corrections_table',101);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (235,'2026_03_11_120000_add_correction_reason_to_field_corrections',101);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (236,'2026_03_11_140000_create_docuperfect_import_drafts_table',101);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (237,'2026_03_11_141359_add_signing_parties_to_docuperfect_templates',101);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (238,'2026_03_13_100836_add_soft_deletes_to_users_table',101);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (239,'2026_03_13_101641_add_soft_deletes_to_users_table',101);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (240,'2026_03_13_120000_create_docuperfect_field_groups_table',101);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (241,'2026_03_13_130000_add_is_global_to_field_groups',101);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (242,'2026_03_16_100000_create_agency_signing_parties_table',102);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (243,'2026_03_16_110000_add_editor_state_to_docuperfect_templates',102);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (244,'2026_03_17_085414_add_deleted_at_to_contact_matches_table',103);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (245,'2026_03_17_100001_add_deal_to_named_fields_source_type',104);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (246,'2026_03_17_120000_add_header_display_to_docuperfect_templates',105);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (247,'2026_03_18_100000_add_email_disclaimer_to_agencies_table',106);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (248,'2026_03_12_175407_create_personal_access_tokens_table',107);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (249,'2026_03_18_100000_create_prospecting_tables',108);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (250,'2026_03_18_120000_add_cross_portal_matching_to_prospecting_listings',108);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (251,'2026_03_18_140000_create_prospecting_claims_table',108);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (252,'2026_03_18_140001_add_first_seen_email_date_to_prospecting_listings',108);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (253,'2026_02_26_600006_add_pending_agent_approval_status_to_signature_templates',109);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (254,'2026_03_19_100000_create_contact_sources_tags_tables',110);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (255,'2026_03_19_120000_add_date_fields_to_contacts_table',110);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (256,'2026_03_19_140000_add_contact_counters',110);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (257,'2026_03_19_160000_seed_prospecting_evaluation_permissions',111);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (258,'2026_03_19_000001_add_party_mode_to_docuperfect_templates',112);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (259,'2026_03_19_000002_create_esign_signing_parties_table',112);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (260,'2026_03_19_000003_create_esign_consent_log_table',112);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (261,'2026_03_19_100001_add_cds_columns_to_docuperfect_templates',112);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (262,'2026_03_20_100001_create_cds_drafts_table',112);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (263,'2026_03_20_120000_add_slot_columns_to_web_pack_items',113);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (264,'2026_03_22_184212_add_supervised_by_to_users_table',113);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (265,'2026_03_22_200000_add_columns_to_esign_consent_log',113);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (266,'2026_03_22_210000_create_signature_zones_table',113);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (267,'2026_03_22_220000_add_sections_to_docuperfect_templates',113);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (268,'2026_03_22_220001_create_section_acceptances_table',113);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (269,'2026_03_22_220002_add_deferred_partial_status_support',113);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (270,'2026_03_22_220003_seed_template_111_sections',113);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (271,'2026_03_22_230000_add_other_conditions_zone_type',113);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (272,'2026_03_22_230001_create_document_amendments_table',113);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (273,'2026_03_22_230002_create_amendment_acceptances_table',113);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (274,'2026_03_22_230003_add_pack_chaining_to_flows',113);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (275,'2026_03_22_240000_create_signed_document_versions_table',113);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (276,'2026_03_22_240001_create_document_contact_table',113);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (277,'2026_03_23_074727_expand_status_enums_for_esign_v2',113);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (278,'2026_03_23_181733_add_cancellation_fields_to_signature_templates_table',113);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (279,'2026_03_23_182523_add_cancelled_to_signature_status_enums',113);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (280,'2026_03_23_200000_add_authorised_columns_to_signature_requests',113);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (281,'2026_03_24_100001_rename_splitter_doc_types_to_document_types',113);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (282,'2026_03_24_100002_add_columns_to_contact_documents_and_property_files',113);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (283,'2026_03_24_100003_repoint_docuperfect_templates_to_document_types',113);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (284,'2026_03_24_200001_create_unified_documents_table',113);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (285,'2026_03_24_200002_migrate_data_to_unified_documents',113);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (286,'2026_03_24_300001_add_category_to_docuperfect_templates',113);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (287,'2026_03_25_100209_create_notifications_table',113);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (288,'2026_03_26_100000_add_assigned_parties_and_soft_deletes_to_signature_zones',113);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (289,'2026_03_26_100000_create_fica_tables',113);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (290,'2026_03_26_200000_create_fica_compliance_workflow',113);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (291,'2026_03_26_300000_add_fica_gate_to_signature_requests',113);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (292,'2026_03_27_100000_add_esign_role_to_contact_types',113);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (293,'2026_03_23_100001_add_pp_syndication_columns_to_properties_table',114);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (294,'2026_03_23_100002_add_pp_suburb_id_and_coordinates_to_properties_table',114);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (295,'2026_03_23_140000_add_pp_visibility_and_rental_columns_to_properties_table',114);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (296,'2026_03_23_150000_add_pp_second_agent_id_to_properties_table',114);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (297,'2026_03_23_160000_add_address_detail_columns_to_properties_table',114);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (298,'2026_03_23_170000_create_property_showdays_table',114);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (299,'2026_03_24_093448_add_listing_type_to_properties_table',114);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (300,'2026_03_24_094815_add_pricing_details_to_properties_table',114);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (301,'2026_03_26_100001_add_p24_syndication_columns_to_properties_table',114);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (302,'2026_03_27_200000_create_fault_reports_table',114);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (303,'2026_03_27_300000_create_commission_engine_tables',114);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (304,'2026_03_27_300001_add_commission_columns_to_users',114);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (305,'2026_03_27_400000_create_onboarding_tables',114);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (306,'2026_03_27_500000_create_training_tables',114);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (307,'2026_03_30_100000_create_deposit_trust_interest_table',114);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (308,'2026_03_30_100001_rename_property_status_items',114);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (309,'2026_03_30_100002_add_gallery_categories_to_properties',114);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (310,'2026_03_30_200000_create_deposit_interest_calculations_table',114);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (311,'2026_03_30_300001_create_deal_pipeline_templates_table',114);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (312,'2026_03_30_300002_create_deal_pipeline_steps_table',114);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (313,'2026_03_30_300003_create_deals_v2_table',114);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (314,'2026_03_30_300004_create_deal_v2_contacts_table',114);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (315,'2026_03_30_300005_create_deal_v2_agents_table',114);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (316,'2026_03_30_300006_create_deal_step_instances_table',114);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (317,'2026_03_30_300007_create_deal_step_documents_table',114);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (318,'2026_03_30_300008_create_deal_activity_log_table',114);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (319,'2026_03_30_400000_add_status_triggers_to_pipeline_tables',114);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (320,'2026_03_30_500001_add_commission_columns_to_deals_v2',114);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (321,'2026_03_30_500002_rebuild_deal_v2_agents_table',114);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (322,'2026_03_30_500003_create_deal_v2_settlements_table',114);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (323,'2026_03_31_100000_backfill_null_contact_property_roles',114);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (324,'2026_03_31_200000_make_property_optional_columns_nullable',114);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (325,'2026_03_31_300001_create_calendar_events_table',114);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (326,'2026_03_31_300002_create_calendar_reminders_log_table',114);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (327,'2026_03_31_300003_create_calendar_user_preferences_table',114);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (328,'2026_03_31_300004_create_command_tasks_table',114);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (329,'2026_03_31_300005_create_automation_rules_table',114);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (330,'2026_03_31_300006_create_automation_log_table',114);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (331,'2026_03_31_300007_create_property_health_scores_table',114);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (332,'2026_03_31_300008_create_agent_scorecards_table',114);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (333,'2026_03_31_300009_create_command_document_expectations_table',114);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (334,'2026_03_31_300010_create_command_reminder_defaults_table',114);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (335,'2026_03_31_300011_add_last_activity_at_to_properties_table',114);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (336,'2026_03_31_400001_create_user_dashboard_settings_table',114);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (337,'2026_03_31_400002_add_event_reminder_hours_to_dashboard_settings',114);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (338,'2026_03_31_400003_add_resolution_to_tasks_and_events',114);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (339,'2026_03_31_400004_add_send_reminder_to_tasks_and_events',114);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (340,'2026_04_01_100001_add_listing_types_to_document_types',114);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (341,'2026_04_08_100000_add_pp_agent_image_columns_to_properties_table',114);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (342,'2026_04_09_100000_add_pp_unique_agent_id_to_users_table',114);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (343,'2026_04_09_100001_add_video_fields_to_properties_table',114);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (344,'2026_04_10_100000_add_property_created_index_to_p24_syndication_logs',114);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (345,'2026_04_14_000001_create_p24_import_runs_table',114);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (346,'2026_04_14_000002_create_p24_import_rows_table',114);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (347,'2026_04_14_000003_add_p24_ids_to_users_and_properties',114);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (348,'2026_04_14_100000_add_agency_id_to_tenant_tables',114);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (349,'2026_04_14_110000_detach_system_owners_from_agencies',114);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (350,'2026_04_14_120000_backfill_orphan_agency_ids',114);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (351,'2026_04_15_000001_create_p24_onboarding_portals_table',114);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (352,'2026_04_15_000002_add_portal_audit_to_p24_import_rows',114);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (353,'2026_04_15_000003_create_p24_portal_events_table',114);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (354,'2026_04_18_000001_add_slug_to_p24_onboarding_portals',114);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (355,'2026_04_18_000002_add_pet_friendly_to_properties_table',114);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (356,'2026_04_18_100001_add_auto_archive_done_to_dashboard_settings',114);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (357,'2026_04_18_120000_add_p24_agency_id_to_agencies_and_branches',114);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (358,'2026_04_21_000001_add_compliance_fields_to_users_table',114);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (359,'2026_04_21_000002_create_user_documents_table',114);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (360,'2026_04_21_000003_backfill_user_documents',114);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (361,'2026_04_21_100001_create_rmcp_versions_table',114);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (362,'2026_04_21_100002_create_rmcp_sections_table',114);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (363,'2026_04_21_100003_create_rmcp_variables_table',114);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (364,'2026_04_21_100004_create_rmcp_compliance_officers_table',114);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (365,'2026_04_21_110001_create_fica_officer_appointments_table',114);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (366,'2026_04_21_110002_migrate_fica_officers_to_appointments',114);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (367,'2026_04_21_120001_create_rmcp_acknowledgements_table',114);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (368,'2026_04_21_120002_create_rmcp_section_acknowledgements_table',114);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (369,'2026_04_21_121927_seed_fica_document_types_to_document_types_table',114);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (370,'2026_04_21_130001_create_employee_screenings_table',114);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (371,'2026_04_21_130002_create_employee_screening_checks_table',114);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (372,'2026_04_21_130003_add_screening_fields_to_users_table',114);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (373,'2026_04_21_130004_add_screening_document_types',114);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (374,'2026_04_21_140001_fix_rmcp_section_26_wording',114);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (375,'2026_04_21_140002_retire_placeholder_training_courses',114);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (376,'2026_04_21_150001_create_agency_compliance_provisions_table',114);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (377,'2026_04_21_150002_add_admin_upload_fields_to_user_documents',114);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (378,'2026_04_21_150003_create_user_compliance_overrides_table',114);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (379,'2026_04_22_080001_create_impersonation_logs_table',114);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (380,'2026_04_22_090000_add_ppra_last_verified_at_to_users',114);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (381,'2026_04_22_090001_backfill_user_photos_to_user_documents',114);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (382,'2026_04_22_100000_fix_user_documents_agency_id',114);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (383,'2026_04_22_110000_add_wet_ink_fields_to_fica_tables',114);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (384,'2026_04_22_110001_make_fica_submission_token_nullable',114);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (385,'2026_04_22_110002_add_cancelled_fica_status_and_resend_logs',114);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (386,'2026_04_21_184755_add_soft_deletes_to_branches_table',115);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (387,'2026_04_21_184800_add_split_branches_enabled_to_agencies_table',115);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (388,'2026_04_21_184900_add_branch_id_to_pillar_and_compliance_tables',115);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (389,'2026_04_21_185000_create_deal_branches_table',115);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (390,'2026_04_21_185100_add_per_branch_syndication_columns_to_branches',115);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (391,'2026_04_22_131821_create_agency_document_type_configs_table',115);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (392,'2026_04_22_133650_add_document_type_config_to_agency_compliance_provisions',115);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (393,'2026_04_22_135334_add_branch_id_to_agency_compliance_provisions',115);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (394,'2026_04_22_135335_drop_allows_branch_override_from_agency_document_type_configs',115);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (395,'2026_04_27_000001_add_oversight_scope_to_roles_table',116);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (396,'2026_04_27_000002_create_user_oversight_preferences_table',116);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (397,'2026_04_27_000003_create_oversight_nudges_table',116);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (398,'2026_04_27_100001_create_notification_event_types_table',116);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (399,'2026_04_27_100002_create_user_notification_preferences_table',116);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (400,'2026_04_27_100003_create_notification_dispatch_log_table',116);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (401,'2026_04_27_100004_create_device_tokens_table',116);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (402,'2026_04_28_100000_create_pp_event_feed_settings_table',116);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (403,'2026_04_28_100001_extend_contact_matches',116);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (404,'2026_04_28_100002_create_contact_match_feedback_table',116);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (405,'2026_04_28_100003_create_contact_match_notifications_table',116);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (406,'2026_04_28_120000_add_share_slug_to_contact_matches',116);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (407,'2026_04_28_120000_add_virtual_tour_url_to_properties',116);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (408,'2026_04_28_130000_add_gallery_custom_tags_to_properties',116);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (409,'2026_04_29_120000_add_pp_external_ref_to_users_table',117);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (410,'2026_04_23_100001_add_payroll_fields_to_users_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (411,'2026_04_23_100002_create_user_banking_details_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (412,'2026_04_23_100003_add_payroll_fields_to_agencies_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (413,'2026_04_23_100004_create_payroll_tax_tables_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (414,'2026_04_23_100005_create_payroll_tax_rebates_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (415,'2026_04_23_100006_create_payroll_earning_types_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (416,'2026_04_23_100007_create_payroll_deduction_types_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (417,'2026_04_23_100008_create_payroll_employees_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (418,'2026_04_23_100009_create_payroll_employee_earnings_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (419,'2026_04_23_100010_create_payroll_employee_deductions_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (420,'2026_04_23_100011_create_payroll_runs_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (421,'2026_04_23_100012_create_payroll_payslips_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (422,'2026_04_23_100013_create_payroll_payslip_lines_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (423,'2026_04_23_100014_add_payslip_to_user_documents_enum',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (424,'2026_04_29_000001_create_leave_types_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (425,'2026_04_29_000002_create_leave_entitlements_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (426,'2026_04_29_000003_create_leave_applications_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (427,'2026_04_29_000004_create_leave_application_documents_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (428,'2026_04_29_000005_create_leave_transactions_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (429,'2026_04_29_000006_create_public_holidays_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (430,'2026_04_29_000007_create_staff_take_on_records_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (431,'2026_04_29_000008_add_leave_columns_to_payroll_employees_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (432,'2026_04_29_000009_add_leave_columns_to_users_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (433,'2026_04_29_123451_rebuild_template_116_marketing_permission_v11',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (434,'2026_04_30_140425_add_fica_expires_at_to_fica_submissions_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (435,'2026_04_30_142935_drop_command_reminder_defaults_and_create_calendar_event_class_settings_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (436,'2026_05_02_104539_make_calendar_events_user_id_nullable',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (437,'2026_05_04_100000_sync_notification_event_types_catalog',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (438,'2026_05_04_193122_create_command_task_notes_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (439,'2026_05_05_000001_create_calendar_event_links_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (440,'2026_05_05_000002_backfill_demo_calendar_event_links',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (441,'2026_05_05_000004_create_agency_feedback_options_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (442,'2026_05_05_000005_create_calendar_event_feedback_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (443,'2026_05_05_000006_create_calendar_event_audit_log_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (444,'2026_05_05_000007_backfill_demo_feedback',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (445,'2026_05_05_000008_backfill_manual_event_links',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (446,'2026_05_05_000009_rename_valuation_to_property_evaluation',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (447,'2026_05_05_000010_add_event_nature_to_calendar_event_class_settings',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (448,'2026_05_05_000011_create_agency_contact_settings_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (449,'2026_05_05_000012_create_agency_leave_visibility_matrix_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (450,'2026_05_05_000013_backfill_branch_id_on_contacts_and_properties',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (451,'2026_05_05_000014_add_default_branch_id_to_agencies',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (452,'2026_05_05_000015_make_branch_id_not_null_on_contacts_and_properties',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (453,'2026_05_05_000016_expand_duplicate_mode_and_create_duplicate_log',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (454,'2026_05_05_000017_create_contact_access_log_and_consent_records',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (455,'2026_05_05_000018_add_channel_opt_out_columns_to_contacts',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (456,'2026_05_05_000019_multi_property_events_support',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (457,'2026_05_05_000020_buyer_crm_foundation',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (458,'2026_05_05_000021_add_buyer_facing_to_calendar_event_class_settings',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (459,'2026_05_06_000001_add_actor_role_and_completion_to_class_settings',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (460,'2026_05_06_000002_create_property_recommendations_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (461,'2026_05_06_000003_create_property_presentation_snapshots_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (462,'2026_05_06_000004_add_seller_visible_to_property_recommendations',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (463,'2026_05_06_000005_create_buyer_preferences_and_risk_scores',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (464,'2026_05_06_000006_add_retention_action_to_buyer_activity_log',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (465,'2026_05_06_000007_create_seller_live_link_tables',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (466,'2026_05_06_000008_create_buyer_matching_engine_tables',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (467,'2026_05_06_000009_drop_old_feedback_unique_index',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (468,'2026_05_06_000010_create_lost_deal_module_tables',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (469,'2026_05_06_000011_add_recovered_columns_to_buyer_lost_records',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (470,'2026_05_06_000012_create_property_sold_records_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (471,'2026_05_06_000013_create_calendar_event_invitations_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (472,'2026_05_06_000014_create_feedback_reports_tables',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (473,'2026_05_06_000015_add_feedback_recipients_to_agencies',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (474,'2026_05_06_000016_add_buyer_pipeline_scope_and_deprecate_sharing_mode',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (475,'2026_05_06_000017_create_prospecting_buyer_matches_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (476,'2026_05_07_172111_add_is_demo_to_agencies_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (477,'2026_05_07_174002_add_require_external_access_authorization_to_agencies',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (478,'2026_05_07_174003_create_agency_access_requests_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (479,'2026_05_09_120001_create_client_users_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (480,'2026_05_09_120002_add_client_user_id_to_contacts',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (481,'2026_05_09_120003_create_client_otps_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (482,'2026_05_09_120004_create_client_access_logs_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (483,'2026_05_09_120005_create_client_signin_attempts_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (484,'2026_05_11_081126_add_acknowledged_at_to_calendar_event_invitations',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (485,'2026_05_11_094044_add_feedback_mode_and_visibility_columns',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (486,'2026_05_11_105415_add_compliance_columns_to_properties',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (487,'2026_05_11_105419_create_marketing_share_log_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (488,'2026_05_11_132238_create_property_audit_log_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (489,'2026_05_11_135612_create_whistleblow_complaints_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (490,'2026_05_11_135613_create_whistleblow_complaint_evidence_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (491,'2026_05_11_135614_create_whistleblow_audit_log_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (492,'2026_05_11_135615_add_whistleblow_columns_to_agencies',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (493,'2026_05_11_135616_add_compliance_evidence_flags_to_properties',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (494,'2026_05_12_083334_create_whistleblow_complaint_subjects_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (495,'2026_05_12_083335_backfill_whistleblow_subjects_from_complaints',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (496,'2026_05_12_083336_drop_subject_columns_from_whistleblow_complaints',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (497,'2026_05_12_090643_replace_ppra_recipient_with_tier_recipients_on_agencies',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (498,'2026_05_12_090644_create_whistleblow_email_log_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (499,'2026_05_12_091937_create_seller_info_share_links_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (500,'2026_05_12_091938_make_complaint_id_nullable_on_whistleblow_email_log',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (501,'2026_05_12_105607_add_matched_property_id_to_prospecting_listings',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (502,'2026_05_12_111831_add_indexes_to_docuperfect_documents',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (503,'2026_05_12_160000_create_training_help_tables',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (504,'2026_05_12_170000_add_created_by_agency_id_to_client_users',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (505,'2026_05_12_180000_add_qr_code_slug_to_users',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (506,'2026_05_13_100001_add_preapproval_to_contacts_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (507,'2026_05_13_100002_extend_contact_matches_for_unification',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (508,'2026_05_13_100003_add_agency_id_to_prospecting_buyer_matches',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (509,'2026_05_13_100004_add_agency_id_to_property_buyer_matches',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (510,'2026_05_13_120001_create_wishlist_migration_log_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (511,'2026_05_13_140001_create_domain_event_log_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (512,'2026_05_13_150001_add_p24_credentials_to_agencies',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (513,'2026_05_13_150001_create_towns_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (514,'2026_05_13_150002_create_p24_location_tables',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (515,'2026_05_13_150002_create_town_suburbs_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (516,'2026_05_13_150003_add_p24_location_refs_to_properties',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (517,'2026_05_13_150003_create_property_type_options_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (518,'2026_05_13_150004_create_bedroom_segments_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (519,'2026_05_13_150004_drop_unique_slug_on_p24_suburbs',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (520,'2026_05_13_150005_create_price_bands_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (521,'2026_05_13_150005_flag_p24_suburb_mismatches',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (522,'2026_05_14_080001_create_seller_outreach_templates_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (523,'2026_05_14_080002_create_seller_outreach_sends_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (524,'2026_05_14_080003_create_seller_outreach_clicks_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (525,'2026_05_14_080004_add_messaging_opt_out_to_contacts_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (526,'2026_05_14_090001_create_contact_outreach_log_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (527,'2026_05_14_100001_create_seller_outreach_callbacks_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (528,'2026_05_14_120001_add_whatsapp_launch_mode_to_agencies_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (529,'2026_05_14_130001_normalize_property_types',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (530,'2026_05_14_131648_create_suggested_action_thresholds_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (531,'2026_05_14_140000_add_market_intelligence_columns_to_properties',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (532,'2026_05_14_140001_create_dev_settings_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (533,'2026_05_14_150000_create_buyer_match_tiers_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (534,'2026_05_14_160000_add_prospecting_pitch_lock_to_agencies',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (535,'2026_05_14_160001_create_prospecting_pitch_locks_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (536,'2026_05_14_170000_create_tracked_properties_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (537,'2026_05_14_170001_create_tracked_property_external_refs_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (538,'2026_05_14_180000_add_tracked_property_id_to_listings_tables',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (539,'2026_05_17_120000_add_qr_reroute_user_id_to_users',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (540,'2026_05_20_000001_create_portal_leads_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (541,'2026_05_20_000002_grant_portal_leads_access_to_all_roles',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (542,'2026_05_20_100001_add_p24_suburb_ids_to_contact_matches',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (543,'2026_05_21_220001_create_legal_block_audit_log_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (544,'2026_05_21_220002_classify_otp_templates',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (545,'2026_05_22_100000_add_hidden_property_reasons_to_contact_matches_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (546,'2026_05_23_000001_wave3b_backfill_orphan_agency_ids',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (547,'2026_05_23_010100_add_agency_id_to_deal_logs_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (548,'2026_05_23_010200_add_agency_id_to_deal_money_lines_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (549,'2026_05_23_010300_add_agency_id_to_deal_settlements_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (550,'2026_05_23_010400_add_agency_id_to_deals_v2_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (551,'2026_05_23_010500_add_agency_id_to_deal_activity_log_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (552,'2026_05_23_010600_add_agency_id_to_deal_pipeline_templates_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (553,'2026_05_23_010700_add_agency_id_to_deal_pipeline_steps_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (554,'2026_05_23_010800_add_agency_id_to_deal_step_instances_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (555,'2026_05_23_010900_add_agency_id_to_deal_step_documents_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (556,'2026_05_23_011000_add_agency_id_to_deal_v2_settlements_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (557,'2026_05_23_020100_add_agency_id_to_property_files_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (558,'2026_05_23_020200_add_agency_id_to_property_notes_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (559,'2026_05_23_020300_add_agency_id_to_property_showdays_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (560,'2026_05_23_020400_add_agency_id_to_property_marketing_activities_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (561,'2026_05_23_020500_add_agency_id_to_property_marketing_posts_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (562,'2026_05_23_020600_add_agency_id_to_property_presentation_snapshots_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (563,'2026_05_23_020700_add_agency_id_to_property_seller_links_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (564,'2026_05_23_020800_add_agency_id_to_property_seller_link_accesses_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (565,'2026_05_23_020900_add_agency_id_to_property_ad_templates_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (566,'2026_05_23_021000_add_agency_id_to_property_health_scores_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (567,'2026_05_23_030100_add_agency_id_to_contact_notes_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (568,'2026_05_23_030200_add_agency_id_to_contact_documents_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (569,'2026_05_23_030300_add_agency_id_to_contact_match_feedback_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (570,'2026_05_23_030400_add_agency_id_to_contact_match_notifications_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (571,'2026_05_23_030500_add_agency_id_to_buyer_property_views_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (572,'2026_05_23_030600_add_agency_id_to_buyer_property_responses_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (573,'2026_05_23_030700_add_agency_id_to_buyer_preferences_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (574,'2026_05_23_030800_add_agency_id_to_buyer_state_transitions_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (575,'2026_05_23_030900_add_agency_id_to_buyer_lost_risk_scores_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (576,'2026_05_23_031000_add_agency_id_to_buyer_portal_links_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (577,'2026_05_23_040100_add_agency_id_to_presentation_active_listings_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (578,'2026_05_23_040200_add_agency_id_to_presentation_articles_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (579,'2026_05_23_040300_add_agency_id_to_presentation_document_library_items_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (580,'2026_05_23_040400_add_agency_id_to_presentation_fields_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (581,'2026_05_23_040500_add_agency_id_to_presentation_links_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (582,'2026_05_23_040600_add_agency_id_to_presentation_listing_price_history_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (583,'2026_05_23_040700_add_agency_id_to_presentation_sections_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (584,'2026_05_23_040800_add_agency_id_to_presentation_snapshots_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (585,'2026_05_23_040900_add_agency_id_to_presentation_sold_comps_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (586,'2026_05_23_041000_add_agency_id_to_presentation_uploads_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (587,'2026_05_23_050100_add_agency_id_to_presentation_url_snapshots_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (588,'2026_05_23_050200_add_agency_id_to_presentation_versions_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (589,'2026_05_23_050300_add_agency_id_to_worksheets_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (590,'2026_05_23_050400_add_agency_id_to_targets_table',118);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (591,'2026_05_23_050500_add_agency_id_to_monthly_target_goals_table',119);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (592,'2026_05_23_050600_add_agency_id_to_listing_targets_table',119);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (593,'2026_05_23_050700_add_agency_id_to_tool_history_entries_table',119);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (594,'2026_05_23_050800_add_agency_id_to_daily_activities_table',119);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (595,'2026_05_23_050900_add_agency_id_to_daily_activity_entries_table',119);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (596,'2026_05_23_051000_add_agency_id_to_agent_scorecards_table',119);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (597,'2026_05_23_060100_add_agency_id_to_listing_stocks_table',119);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (598,'2026_05_23_060200_add_agency_id_to_listing_import_runs_table',119);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (599,'2026_05_23_060300_add_agency_id_to_listing_import_rows_table',119);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (600,'2026_05_23_060400_add_agency_id_to_listing_snapshots_table',119);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (601,'2026_05_23_060500_add_agency_id_to_market_analytics_runs_table',119);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (602,'2026_05_23_060600_add_agency_id_to_sale_probability_runs_table',119);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (603,'2026_05_23_060700_add_agency_id_to_revenue_share_ledger_table',119);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (604,'2026_05_23_060800_add_agency_id_to_agent_mentors_table',119);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (605,'2026_05_23_060900_add_agency_id_to_agent_sponsorships_table',119);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (606,'2026_05_23_061000_add_agency_id_to_agent_social_accounts_table',119);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (607,'2026_05_23_070100_add_agency_id_to_commercial_evaluations_table',119);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (608,'2026_05_23_070200_add_agency_id_to_commercial_evaluation_assets_table',119);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (609,'2026_05_23_070300_add_agency_id_to_commercial_evaluation_comparables_table',119);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (610,'2026_05_23_070400_add_agency_id_to_commercial_evaluation_crops_table',119);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (611,'2026_05_23_070500_add_agency_id_to_commercial_evaluation_financials_table',119);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (612,'2026_05_23_070600_add_agency_id_to_commercial_evaluation_livestock_table',119);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (613,'2026_05_23_070700_add_agency_id_to_commercial_evaluation_units_table',119);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (614,'2026_05_23_070800_add_agency_id_to_finance_audit_runs_table',119);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (615,'2026_05_23_070900_add_agency_id_to_finance_audit_items_table',119);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (616,'2026_05_23_071000_add_agency_id_to_finance_computed_values_table',119);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (617,'2026_05_23_080100_add_agency_id_to_calendar_event_audit_log_table',119);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (618,'2026_05_23_080200_add_agency_id_to_calendar_event_invitations_table',119);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (619,'2026_05_23_080300_add_agency_id_to_calendar_event_links_table',119);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (620,'2026_05_23_080400_add_agency_id_to_calendar_reminders_log_table',119);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (621,'2026_05_23_080500_add_agency_id_to_branch_settings_table',119);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (622,'2026_05_23_080600_add_agency_id_to_branch_activity_columns_table',119);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (623,'2026_05_23_080700_add_agency_id_to_fault_reports_table',119);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (624,'2026_05_23_080800_add_agency_id_to_contact_sources_table',119);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (625,'2026_05_23_080900_add_agency_id_to_contact_tags_table',119);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (626,'2026_05_23_081000_add_agency_id_to_property_setting_items_table',119);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (627,'2026_05_23_090100_add_agency_id_to_document_filing_register_table',119);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (628,'2026_05_23_090200_add_agency_id_to_document_library_items_table',119);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (629,'2026_05_23_090300_add_agency_id_to_fica_documents_table',119);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (630,'2026_05_23_090400_add_agency_id_to_fica_resend_logs_table',119);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (631,'2026_05_23_090500_add_agency_id_to_rmcp_sections_table',119);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (632,'2026_05_23_090600_add_agency_id_to_rmcp_section_acknowledgements_table',119);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (633,'2026_05_23_090700_add_agency_id_to_employee_screening_checks_table',119);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (634,'2026_05_23_090800_add_agency_id_to_whistleblow_email_log_table',119);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (635,'2026_05_23_090900_add_agency_id_to_p24_listings_table',119);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (636,'2026_05_23_091000_add_agency_id_to_p24_import_log_table',119);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (637,'2026_05_25_120000_add_portal_visibility_prefs_to_users_table',119);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (638,'2026_05_28_120000_add_pp_syndication_columns_to_agencies',120);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (639,'2026_05_28_100001_create_pp_locations_tables',121);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (640,'2026_05_28_100002_add_pp_locations_sync_to_agencies',121);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (641,'2026_05_28_140000_add_mark_compliant_on_confirm_to_p24_import_runs',122);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (642,'2026_05_28_180001_add_ai_attribution_to_calendar_events',122);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (643,'2026_05_28_180002_add_features_json_meta_to_properties',122);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (644,'2026_05_28_180003_create_property_image_analyses_table',122);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (645,'2026_05_28_180004_add_ai_feature_flags_to_agencies',122);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (646,'2026_05_29_100000_add_open_hours_and_push_master_to_dashboard_settings',123);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (647,'2026_05_19_120000_seed_esign_deal_named_fields',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (648,'2026_05_19_140000_add_signed_paginated_html_to_docuperfect_documents',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (649,'2026_05_20_000001_add_feedback_captured_to_buyer_activity_log_enum',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (650,'2026_05_21_120001_create_tracked_property_addresses_table',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (651,'2026_05_21_120002_create_market_report_types_table',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (652,'2026_05_21_120003_create_market_reports_table',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (653,'2026_05_21_120004_create_market_data_points_table',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (654,'2026_05_21_120005_create_market_data_discrepancies_table',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (655,'2026_05_21_120006_create_ai_narrative_cache_table',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (656,'2026_05_21_120007_create_agent_activity_events_table',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (657,'2026_05_21_120008_add_agency_id_to_p24_listings_table',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (658,'2026_05_21_120009_backfill_agency_id_on_p24_listings',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (659,'2026_05_21_120010_make_p24_listings_agency_id_not_null',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (660,'2026_05_21_120011_add_agency_id_to_presentations_table',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (661,'2026_05_21_120012_backfill_agency_id_on_presentations',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (662,'2026_05_21_120013_make_presentations_agency_id_not_null',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (663,'2026_05_21_120014_add_identifier_columns_to_properties_table',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (664,'2026_05_21_120015_backfill_property_identifiers_from_tracked_properties',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (665,'2026_05_21_120016_fix_prospecting_listings_null_addresses',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (666,'2026_05_21_120017_spatial_index_on_tracked_properties_geo',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (667,'2026_05_21_130001_seed_mic_permissions',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (668,'2026_05_21_140001_relax_agent_activity_events_user_id_nullable',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (669,'2026_05_21_150001_relax_agent_activity_events_agency_id_nullable',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (670,'2026_05_21_160001_add_ai_budget_to_agencies',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (671,'2026_05_21_160002_add_soft_deletes_to_ai_narrative_cache',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (672,'2026_05_21_170001_backfill_tracked_property_addresses',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (673,'2026_05_22_010001_create_document_conditions_tables',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (674,'2026_05_22_010002_amended_by_request_nullable_on_document_amendments',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (675,'2026_05_22_010003_add_amendment_initialing_to_signature_template_status',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (676,'2026_05_22_020001_extend_amendments_for_flags',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (677,'2026_05_22_120001_backfill_legacy_other_conditions',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (678,'2026_05_22_140001_add_relates_to_clause_ref_to_conditions',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (679,'2026_05_23_080001_add_pillar_fks_to_presentations',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (680,'2026_05_23_100001_add_presentation_settings_to_agency',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (681,'2026_05_23_100001_create_flag_removal_requests_table',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (682,'2026_05_23_120001_add_subject_geo_to_market_reports',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (683,'2026_05_23_120002_create_market_report_comp_rows_table',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (684,'2026_05_23_120003_create_scheme_owners_table',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (685,'2026_05_23_140001_add_comp_scope_to_agencies',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (686,'2026_05_23_140002_add_comp_scope_to_presentations',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (687,'2026_05_23_160001_add_hydration_summary_to_presentation_versions',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (688,'2026_05_23_180001_add_holding_cost_defaults_to_agencies',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (689,'2026_05_24_080001_create_geocoding_cache_table',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (690,'2026_05_24_080002_create_geocoding_runs_table',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (691,'2026_05_24_080003_add_geo_source_to_properties_and_tracked',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (692,'2026_05_25_080001_add_geo_index_to_properties',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (693,'2026_05_26_080001_add_is_demo_to_spatial_tables',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (694,'2026_05_27_080001_create_presentation_snapshot_links_table',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (695,'2026_05_27_080002_create_presentation_snapshot_views_table',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (696,'2026_05_27_080003_add_snapshot_link_settings_to_agencies',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (697,'2026_05_28_080001_create_presentation_teaser_leads_table',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (698,'2026_05_28_080002_add_teaser_lead_id_to_presentation_snapshot_views',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (699,'2026_05_28_080003_add_teaser_section_toggles_to_agencies',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (700,'2026_05_29_080001_create_presentation_deliveries_table',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (701,'2026_05_29_080002_add_presentation_send_defaults_to_users',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (702,'2026_05_29_080003_add_delivery_templates_to_agencies',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (703,'2026_05_30_080001_create_presentation_ai_variants_table',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (704,'2026_05_30_080002_add_ai_summary_to_presentation_versions',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (705,'2026_05_30_080003_create_presentation_ai_summary_history_table',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (706,'2026_05_31_080001_extend_snapshot_links_for_refresh_phase7',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (707,'2026_05_31_080002_add_staleness_days_to_agencies',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (708,'2026_05_31_080003_create_presentation_refresh_requests_table',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (709,'2026_06_01_080001_create_presentation_outcomes_table',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (710,'2026_06_01_080002_create_presentation_outcome_prompts_table',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (711,'2026_06_01_201014_add_competitor_stock_min_same_type_to_agencies',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (712,'2026_06_01_204407_add_competitor_stock_default_display_count_to_agencies',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (713,'2026_06_01_211936_add_geo_columns_to_prospecting_listings_and_map_provider_to_agencies',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (714,'2026_06_02_080001_add_property_link_and_sale_columns_to_deals',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (715,'2026_06_02_080002_create_deal_link_review_queue_table',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (716,'2026_06_02_100001_create_agency_api_keys_table',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (717,'2026_06_02_100002_create_agency_webhook_deliveries_table',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (718,'2026_06_02_100003_create_property_website_syndication_table',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (719,'2026_06_02_100004_add_website_fields_to_agencies_table',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (720,'2026_06_02_100005_add_show_on_website_to_users_table',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (721,'2026_06_03_080001_add_sg_columns_to_properties',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (722,'2026_06_03_080002_create_property_sg_documents_table',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (723,'2026_06_03_080003_create_sg_search_cache_table',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (724,'2026_06_04_080001_phase9a_harden_outcome_fk_and_delivery_index',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (725,'2026_06_05_080001_create_rcr_questionnaires_table',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (726,'2026_06_05_080002_create_rcr_questionnaire_sections_table',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (727,'2026_06_05_080003_create_rcr_questions_table',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (728,'2026_06_05_080004_create_rcr_submissions_table',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (729,'2026_06_05_080005_create_rcr_answers_table',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (730,'2026_06_05_080006_create_rcr_answer_evidence_table',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (731,'2026_06_05_080007_create_rcr_submission_snapshots_table',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (732,'2026_06_06_080001_add_period_and_clipboard_tracking_to_rcr_tables',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (733,'2026_06_07_080001_extend_geocoding_cache_phase11a',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (734,'2026_06_10_120000_add_id_number_audit_to_contacts',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (735,'2026_06_15_120000_create_map_saved_searches_table',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (736,'2026_06_16_120000_add_ppra_number_to_agencies_and_branches',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (737,'2026_06_16_120100_create_information_officer_appointments_table',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (738,'2026_06_16_120300_add_module_6_activity_points_columns',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (739,'2026_06_16_120400_create_activity_definition_calendar_classes',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (740,'2026_06_16_120500_rollback_phase_9c3_company_documents',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (741,'2026_06_16_120600_add_privacy_policy_fields_to_agencies_and_branches',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (742,'2026_06_16_120700_add_role_index_to_signature_requests',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (743,'2026_06_16_121000_add_tp_outreach_columns',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (744,'2026_06_16_122000_fix_market_report_cascade_to_preserve_audit',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (745,'2026_06_16_122100_seed_mic_restore_reports_permission',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (746,'2026_06_16_122200_seed_cma_info_vicinity_sale_type',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (747,'2026_06_16_122300_make_market_reports_report_type_id_nullable',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (748,'2026_06_16_122400_add_normalised_address_columns_to_properties',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (749,'2026_06_16_122500_add_mic_comp_row_id_fk_to_presentation_tables',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (750,'2026_06_17_100000_add_title_type_to_property_setting_items',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (751,'2026_06_17_110000_add_review_flow_to_presentations',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (752,'2026_06_17_120000_add_condition_levels_to_presentations',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (753,'2026_06_17_130000_add_section_toggles_to_presentations',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (754,'2026_06_17_140000_add_snapshot_payload_to_presentations',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (755,'2026_06_17_150000_add_title_type_to_properties',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (756,'2026_06_17_160000_add_cma_compute_settings_to_agencies',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (757,'2026_06_19_120000_add_competitor_stock_settings_to_agencies',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (758,'2026_06_19_120100_add_included_competitor_ids_to_presentation_versions',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (759,'2026_06_19_140000_create_holding_cost_data_points_table',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (760,'2026_06_19_140100_add_freehold_holding_defaults_to_agencies',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (761,'2026_06_19_140200_add_freehold_monthly_to_presentations',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (762,'2026_06_20_120000_seed_hfc_activity_calendar_mappings',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (763,'2026_06_20_140000_relax_daily_activity_unique_for_calendar_events',124);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (764,'2026_06_20_160000_extend_activity_actions_for_instant',125);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (765,'2026_06_20_160100_make_event_class_nullable_for_instant_rows',125);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (766,'2026_06_02_101001_add_website_agent_order_to_agencies_table',126);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (767,'2026_06_02_101002_add_website_order_to_users_table',126);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (768,'2026_06_06_100001_create_contact_testimonials_table',126);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (769,'2026_06_06_100002_add_agent_id_to_contact_testimonials_table',126);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (770,'2026_06_06_100003_add_agent_public_profile_and_articles',126);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (771,'2026_06_06_100004_add_media_to_agent_articles',126);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (772,'2026_06_06_120000_add_website_address_and_open_hours_to_agencies',126);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (773,'2026_06_21_000000_create_soft_delete_restorations_table',126);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (774,'2026_06_22_120000_add_website_show_branches_to_agencies',126);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (775,'2026_06_22_130000_add_birthday_reminder_to_contacts',126);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (776,'2026_06_23_000000_create_ai_usage_events_table',127);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (777,'2026_06_09_000000_add_reviewed_at_to_property_image_analyses',128);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (778,'2026_06_09_120001_add_website_branch_order_to_agencies_table',128);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (779,'2026_06_09_120002_add_website_order_to_branches_table',128);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (780,'2026_06_10_120000_add_new_listing_lookback_days_to_suggested_action_thresholds',129);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (782,'2026_06_10_000001_add_new_listing_lookback_days_to_suggested_action_thresholds',130);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (784,'2026_06_24_000000_add_category_and_is_system_to_docuperfect_clauses',131);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (785,'2026_06_25_000000_create_cds_extraction_flags_table',132);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (786,'2026_06_23_100000_add_ppra_registered_at_to_agencies',133);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (787,'2026_06_23_100100_add_regulator_registered_on_to_information_officer_appointments',133);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (788,'2026_06_11_180000_add_comp_selection_settings_to_agencies',134);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (789,'2026_06_11_180500_add_thumbnail_source_url_to_prospecting_listings',134);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (790,'2026_06_23_120000_normalize_user_phone_numbers',134);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (791,'2026_06_11_190000_add_comp_curation_override_types',135);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (792,'2026_06_12_090000_add_thumbnail_blocked_reason_to_prospecting_listings',136);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (793,'2026_06_24_000000_add_in_analysis_to_presentation_versions_review_status',137);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (794,'2026_06_14_120000_add_day_windows_to_dashboard_settings',138);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (795,'2026_06_25_000001_create_agency_policies_table',138);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (796,'2026_06_25_000002_create_policy_versions_table',138);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (797,'2026_06_25_000003_create_policy_sections_table',138);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (798,'2026_06_25_000004_create_policy_acknowledgements_table',138);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (799,'2026_06_25_000005_create_policy_section_acknowledgements_table',139);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (800,'2026_06_26_000001_create_communications_table',140);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (801,'2026_06_26_000002_create_communication_attachments_table',140);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (802,'2026_06_26_000003_create_communication_links_table',140);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (803,'2026_06_26_000004_create_communication_mailboxes_table',140);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (804,'2026_06_26_000005_create_communication_wa_devices_table',140);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (805,'2026_06_26_000006_create_communication_pending_table',140);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (809,'2026_06_28_000001_add_user_fields_to_communication_mailboxes_table',142);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (810,'2026_06_28_000002_create_mailbox_credential_reveals_table',142);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (811,'2026_06_27_000001_create_communication_flags_table',143);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (812,'2026_06_27_000002_create_communication_flag_alerts_table',143);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (813,'2026_06_27_000003_add_classification_to_communication_pending_table',143);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (814,'2026_06_15_140000_add_communication_ingest_filter_to_agencies',144);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (815,'2026_06_16_140000_add_cma_band_pct_to_agencies',145);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (816,'2026_06_16_160000_set_cma_band_pct_asymmetric_default',146);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (818,'2026_06_16_180000_add_messaging_opt_in_to_contacts_table',147);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (821,'2026_06_16_181000_add_include_tracking_link_to_seller_outreach_templates',148);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (822,'2026_06_16_182000_add_public_contact_to_agencies',148);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (823,'2026_06_16_190000_add_opt_out_token_to_seller_outreach_sends',149);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (824,'2026_06_16_190001_add_messaging_opt_out_source_to_contacts',149);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (825,'2026_06_16_190002_create_marketing_suppressions_table',150);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (826,'2026_06_16_190003_add_marketing_unsubscribe_footer_to_agencies',150);
