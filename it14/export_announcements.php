<?php
// export_announcements.php - Export all announcements to CSV (Super Admin only)
require_once 'config.php';
requireSuperAdmin();

// Log the export action (non-blocking)
if (function_exists('logActivity')) {
    try {
        logActivity('export_announcements_csv');
    } catch (Exception $e) {
        // ignore
    }
}

$filename = 'announcements_' . date('Ymd_His') . '.csv';

// Send headers so the browser downloads the file
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename=' . $filename);
header('Pragma: no-cache');
header('Expires: 0');

// Output BOM for Excel UTF-8 compatibility
echo "\xEF\xBB\xBF";

$out = fopen('php://output', 'w');

// CSV header row
fputcsv($out, ['Title', 'Content', 'Date Posted', 'Program']);

// Fetch announcements with program name (if any)
$sql = "
    SELECT 
        a.title,
        a.content,
        a.created_at AS date_posted,
        p.name AS program
    FROM announcements a
    LEFT JOIN programs p ON a.program_id = p.id
    ORDER BY a.created_at DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute();

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    // Normalize values; ensure strings
    $title = (string)($row['title'] ?? '');
    $content = (string)($row['content'] ?? '');
    $datePosted = '';
    if (!empty($row['date_posted'])) {
        // Format date as ISO 8601 for portability; Excel will parse
        $datePosted = date('Y-m-d H:i:s', strtotime($row['date_posted']));
    }
    $program = (string)($row['program'] ?? '');

    fputcsv($out, [$title, $content, $datePosted, $program]);
}

fclose($out);
exit;
