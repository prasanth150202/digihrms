<?php
// Migration: Surveys (EHI + Team Rubrics)
// Creates/upgrades: survey_participants, survey_responses (+ email, submitted_at), survey_settings
// Optional — the survey pages run the same idempotent setup on their own.
// Run once against u218702675_hrms

require_once 'config.php';
require_once 'survey_config.php';

try {
    survey_ensure_schema($conn);
    echo "✓ survey tables are up to date\n";
} catch (PDOException $e) {
    echo "✗ Migration failed: " . $e->getMessage() . "\n";
}
