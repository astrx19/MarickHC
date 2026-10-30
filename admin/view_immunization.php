<?php
/**
 * LEGACY PAGE - retired.
 * This page queried a table (`child_immunization`) and columns that do not exist in the
 * current schema, so it could only error. Nothing links to it any more: child immunization
 * forms, vaccines and child consultations are handled by admin/immunization.php, which uses
 * the real tables (child_immunization_records, immunization_records, child_consultation_logs).
 * Kept as a redirect so old bookmarks still land somewhere useful.
 */
require_once __DIR__ . '/../includes/admin_auth.php';
header('Location: immunization.php');
exit();
