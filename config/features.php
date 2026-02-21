<?php

return [
    'presentations'              => true,
    'presentation_blueprint'     => (bool) env('PRESENTATION_BLUEPRINT_ENABLED', true),
    'p24_ingestion'              => (bool) env('P24_INGESTION_ENABLED', true),
    'private_property_ingestion' => (bool) env('PP_INGESTION_ENABLED', true),
    'article_ingestion'              => (bool) env('ARTICLE_INGESTION_ENABLED', true),
    'presentation_simulate_snapshot' => (bool) env('PRESENTATION_SIMULATE_SNAPSHOT_ENABLED', false),
    'presentation_readiness_check'   => (bool) env('PRESENTATION_READINESS_CHECK_ENABLED', false),
    'presentation_pdf_v1'            => (bool) env('PRESENTATION_PDF_V1_ENABLED', false),
    'listing_dedupe_v1'              => (bool) env('LISTING_DEDUPE_V1_ENABLED', false),
    'listing_lifecycle_v1'           => (bool) env('LISTING_LIFECYCLE_V1_ENABLED', false),
    'listing_data_quality_v1'        => (bool) env('LISTING_DATA_QUALITY_V1_ENABLED', false),
    'trajectory_simulation_v1'       => (bool) env('TRAJECTORY_SIMULATION_V1_ENABLED', false),
    'price_band_v1'                  => (bool) env('PRICE_BAND_V1_ENABLED', false),
    'competitive_threat_v1'          => (bool) env('COMPETITIVE_THREAT_V1_ENABLED', false),
    'launch_position_v1'             => (bool) env('LAUNCH_POSITION_V1_ENABLED', false),
    'presentation_power_panel_v1'    => (bool) env('PRESENTATION_POWER_PANEL_V1_ENABLED', false),
    'presentation_brain_ui_v1'       => (bool) env('PRESENTATION_BRAIN_UI_V1_ENABLED', false),
    'presentation_link_extraction_v2' => (bool) env('PRESENTATION_LINK_EXTRACTION_V2_ENABLED', true),
    'portal_headless_fetch_v1'        => (bool) env('PORTAL_HEADLESS_FETCH_V1', false),
    'portal_extension_capture_v1'     => (bool) env('PORTAL_EXTENSION_CAPTURE_V1', true),
];
