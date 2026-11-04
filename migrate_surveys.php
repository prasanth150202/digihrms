<?php
// Migration: Anonymous surveys (EHI + Team Rubrics)
// Creates: survey_participants, survey_responses
// Run once against u218702675_hrms
//
// Anonymity: the two tables share NO key. survey_participants knows WHO responded
// (email) but not what they said; survey_responses knows WHAT was said but not who.
// survey_responses has a random id and no timestamp, so rows can't be matched up by
// insert order or time either.

require_once 'config.php';

try {
    $conn->exec("
        CREATE TABLE IF NOT EXISTS survey_participants (
            id           INT AUTO_INCREMENT PRIMARY KEY,
            survey       VARCHAR(20)  NOT NULL,
            period       CHAR(7)      NOT NULL,          -- YYYY-MM
            email        VARCHAR(190) NOT NULL,
            submitted_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_survey_period_email (survey, period, email),
            KEY idx_email (email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    echo "✓ survey_participants table created\n";

    $conn->exec("
        CREATE TABLE IF NOT EXISTS survey_responses (
            id         CHAR(32)     NOT NULL PRIMARY KEY, -- random, not sequential
            survey     VARCHAR(20)  NOT NULL,
            period     CHAR(7)      NOT NULL,             -- YYYY-MM, no timestamp on purpose
            team       VARCHAR(20)  NOT NULL,
            answers    JSON         NOT NULL,             -- {\"q1\":4, \"q2\":5, ...} ratings 1-5
            enps       TINYINT      NULL,                 -- 0-10, EHI only
            open_change     TEXT    NULL,
            open_keep       TEXT    NULL,
            open_frustrates TEXT    NULL,
            KEY idx_survey_period (survey, period, team)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    echo "✓ survey_responses table created\n";

    echo "\nDone.\n";
} catch (PDOException $e) {
    echo "✗ Migration failed: " . $e->getMessage() . "\n";
}
