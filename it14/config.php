<?php
// config.php - Database configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'umtc_announcement_system');
define('DB_USER', 'root');
define('DB_PASS', '');

// EmailJS configuration (fill in service/template/private key via environment for security)
define('EMAILJS_PUBLIC_KEY', getenv('EMAILJS_PUBLIC_KEY') ?: 'U4hWYwSqwbgC0HM6s');
define('EMAILJS_PRIVATE_KEY', getenv('EMAILJS_PRIVATE_KEY') ?: '');
define('EMAILJS_SERVICE_ID', getenv('EMAILJS_SERVICE_ID') ?: '');
define('EMAILJS_TEMPLATE_ID', getenv('EMAILJS_TEMPLATE_ID') ?: '');
define('EMAILJS_API_URL', 'https://api.emailjs.com/api/v1.0/email/send');

//
// Create a new PDO instance
try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
} catch(PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Start session if not already active
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Prevent browsers from caching sensitive authenticated pages
function preventSensitiveCaching() {
    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
    header("Cache-Control: post-check=0, pre-check=0", false);
    header("Pragma: no-cache");
    header("Expires: 0");
}

// Ensure activity_logs table exists and provide logging helper
function ensureActivityLogsTable() {
    global $pdo;
    $pdo->exec("CREATE TABLE IF NOT EXISTS activity_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        actor_user_id INT NULL,
        action VARCHAR(100) NOT NULL,
        details TEXT NULL,
        target_user_id INT NULL,
        ip_address VARCHAR(45) NULL,
        user_agent TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (actor_user_id),
        INDEX (target_user_id),
        INDEX (action)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

ensureActivityLogsTable();

// Add approval columns if they do not exist (idempotent attempt)
function ensureAnnouncementApprovalColumns() {
    global $pdo;
    try {
        $pdo->exec("ALTER TABLE announcements ADD COLUMN IF NOT EXISTS is_approved TINYINT(1) DEFAULT 0");
        $pdo->exec("ALTER TABLE announcements ADD COLUMN IF NOT EXISTS approved_by INT NULL");
        $pdo->exec("ALTER TABLE announcements ADD COLUMN IF NOT EXISTS approved_at DATETIME NULL");
    } catch (Exception $e) {
        // ignore; migrations may be handled separately
    }
}
ensureAnnouncementApprovalColumns();

function ensureUserArchiveColumns() {
    global $pdo;
    try {
        $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS is_archived TINYINT(1) DEFAULT 0");
        $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS archived_at DATETIME NULL");
    } catch (Exception $e) {
        // ignore
    }
}
ensureUserArchiveColumns();

function ensureAnnouncementArchiveColumns() {
    global $pdo;
    try {
        $pdo->exec("ALTER TABLE announcements ADD COLUMN IF NOT EXISTS is_archived TINYINT(1) DEFAULT 0");
        $pdo->exec("ALTER TABLE announcements ADD COLUMN IF NOT EXISTS archived_at DATETIME NULL");
    } catch (Exception $e) {
        // ignore
    }
}
ensureAnnouncementArchiveColumns();

function ensureSubscriptionsTable() {
    global $pdo;
    $pdo->exec("CREATE TABLE IF NOT EXISTS course_subscriptions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(255) NOT NULL,
        program_id INT NOT NULL,
        unsubscribe_token VARCHAR(64) NOT NULL,
        is_active TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_subscription (email, program_id),
        INDEX idx_program (program_id),
        INDEX idx_token (unsubscribe_token),
        CONSTRAINT fk_subscriptions_program FOREIGN KEY (program_id) REFERENCES programs(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
ensureSubscriptionsTable();

function ensureUserLoginTrackingColumns() {
    global $pdo;
    try {
        $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS last_login_at DATETIME NULL");
        $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS last_login_ip VARCHAR(45) NULL");
    } catch (Exception $e) {
        // ignore
    }
}
ensureUserLoginTrackingColumns();

function ensureUserProfilePictureColumn() {
    global $pdo;
    try {
        $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS profile_picture VARCHAR(255) NULL");
    } catch (Exception $e) {
        // ignore
    }
}
ensureUserProfilePictureColumn();

function logActivity($action, $details = null, $target_user_id = null) {
    global $pdo;
    $actor_user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : null;
    $agent = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : null;
    try {
        $stmt = $pdo->prepare("INSERT INTO activity_logs (actor_user_id, action, details, target_user_id, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$actor_user_id, $action, $details, $target_user_id, $ip, $agent]);
    } catch (Exception $e) {
        // Intentionally suppress logging failures to avoid breaking primary flows
    }
}

// Check if user is logged in
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

// Check if user is super admin
function isSuperAdmin() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'super_admin';
}

// Check if user is admin
function isAdmin() {
    return isset($_SESSION['role']) && ($_SESSION['role'] === 'admin' || $_SESSION['role'] === 'super_admin');
}

// Get user's department ID
function getUserDepartment() {
    return isset($_SESSION['department_id']) ? $_SESSION['department_id'] : null;
}

// Redirect if not logged in
function requireLogin() {
    preventSensitiveCaching();
    if (!isLoggedIn()) {
        header("Location: login.php");
        exit();
    }
    enforceActiveAccount();
}

// Redirect if not admin
function requireAdmin() {
    requireLogin();
    if (!isAdmin()) {
        header("Location: index.php");
        exit();
    }
}

// Redirect if not super admin
function requireSuperAdmin() {
    requireLogin();
    if (!isSuperAdmin()) {
        header("Location: admin.php");
        exit();
    }
}

function enforceActiveAccount() {
    if (!isLoggedIn()) {
        return;
    }

    global $pdo;
    $stmt = $pdo->prepare("SELECT is_archived FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();

    if (!$user || !empty($user['is_archived'])) {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        header("Location: login.php?status=archived");
        exit();
    }
}

// Get all departments
function getDepartments() {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM departments ORDER BY name");
    $stmt->execute();
    return $stmt->fetchAll();
}

// Get programs by department
function getProgramsByDepartment($department_id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM programs WHERE department_id = ? ORDER BY name");
    $stmt->execute([$department_id]);
    return $stmt->fetchAll();
}

function getAllPrograms() {
    global $pdo;
    $stmt = $pdo->prepare("SELECT p.*, d.code AS department_code FROM programs p LEFT JOIN departments d ON p.department_id = d.id ORDER BY d.code, p.name");
    $stmt->execute();
    return $stmt->fetchAll();
}

// Get department by ID
function getDepartment($id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM departments WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch();
}

// Get program by ID
function getProgram($id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM programs WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch();
}

function getProgramNameWithDepartment($program_id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT p.name AS program_name, p.code AS program_code, d.name AS department_name FROM programs p LEFT JOIN departments d ON p.department_id = d.id WHERE p.id = ?");
    $stmt->execute([$program_id]);
    return $stmt->fetch();
}

function normalizeDateInput($value) {
    if (!isset($value) || $value === '') {
        return null;
    }

    $date = DateTime::createFromFormat('Y-m-d', $value);
    return ($date && $date->format('Y-m-d') === $value) ? $date->format('Y-m-d') : null;
}

function normalizeTimeInput($value) {
    if (!isset($value) || $value === '') {
        return null;
    }

    $value = trim($value);
    $time = DateTime::createFromFormat('H:i', $value);
    if ($time) {
        return $time->format('H:i');
    }

    $timeWithSeconds = DateTime::createFromFormat('H:i:s', $value);
    return $timeWithSeconds ? $timeWithSeconds->format('H:i') : null;
}

// Get current user
function getCurrentUser() {
    global $pdo;
    global $_SESSION;
    if (isLoggedIn()) {
        $stmt = $pdo->prepare("SELECT u.*, d.code as department_code FROM users u LEFT JOIN departments d ON u.department_id = d.id WHERE u.id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        return $stmt->fetch();
    }
    return null;
}

// Get total announcements count
function getTotalAnnouncements() {
    global $pdo;
    return $pdo->query("SELECT COUNT(*) FROM announcements WHERE is_published = TRUE AND is_approved = 1")->fetchColumn();
}

// Get total departments count
function getTotalDepartments() {
    global $pdo;
    return $pdo->query("SELECT COUNT(*) FROM departments")->fetchColumn();
}

// Get upcoming events count
function getUpcomingEventsCount() {
    global $pdo;
    return $pdo->query("SELECT COUNT(*) FROM announcements WHERE is_published = TRUE AND is_approved = 1 AND event_date >= CURDATE()")->fetchColumn();
}



// Get recent announcements
function getRecentAnnouncements($limit = 6) {
    global $pdo;
    $stmt = $pdo->prepare("
        SELECT a.*, u.full_name as author_name, d.code as department_code 
        FROM announcements a 
        JOIN users u ON a.author_id = u.id 
        LEFT JOIN departments d ON a.department_id = d.id
            WHERE a.is_published = TRUE AND a.is_approved = 1 
        ORDER BY a.created_at DESC 
        LIMIT :limit
    ");
    $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

// Subscription + EmailJS helpers
function createCourseSubscription($email, $program_id) {
    global $pdo;
    $email = trim(strtolower($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'message' => 'Please enter a valid email address.'];
    }
    if (empty($program_id)) {
        return ['success' => false, 'message' => 'Please select a course.'];
    }

    $token = bin2hex(random_bytes(16));
    try {
        $pdo->prepare("INSERT INTO course_subscriptions (email, program_id, unsubscribe_token, is_active) VALUES (?, ?, ?, 1)")
            ->execute([$email, $program_id, $token]);
        return ['success' => true, 'message' => 'Subscribed successfully. You will get emails for new announcements.', 'token' => $token];
    } catch (Exception $e) {
        try {
            $pdo->prepare("UPDATE course_subscriptions SET is_active = 1, unsubscribe_token = ? WHERE email = ? AND program_id = ?")
                ->execute([$token, $email, $program_id]);
            return ['success' => true, 'message' => 'Subscription updated. You will get emails for new announcements.', 'token' => $token];
        } catch (Exception $inner) {
            return ['success' => false, 'message' => 'Could not save subscription right now.'];
        }
    }
}

function unsubscribeCourseByToken($token) {
    global $pdo;
    if (empty($token)) {
        return ['success' => false, 'message' => 'Invalid unsubscribe token.'];
    }
    $stmt = $pdo->prepare("UPDATE course_subscriptions SET is_active = 0 WHERE unsubscribe_token = ?");
    $stmt->execute([$token]);
    if ($stmt->rowCount() > 0) {
        return ['success' => true, 'message' => 'You have been unsubscribed from this course.'];
    }
    return ['success' => false, 'message' => 'We could not find that subscription.'];
}

function getActiveSubscribersByProgram($program_id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT email, unsubscribe_token FROM course_subscriptions WHERE program_id = ? AND is_active = 1");
    $stmt->execute([$program_id]);
    return $stmt->fetchAll();
}

function getBaseUrl() {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $path = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return $scheme . '://' . $host . ($path ? $path . '/' : '/');
}

function sendEmailJsNotification($toEmail, $courseName, $announcementTitle, $announcementContent, $unsubscribeLink) {
    if (!EMAILJS_SERVICE_ID || !EMAILJS_TEMPLATE_ID || !EMAILJS_PRIVATE_KEY) {
        return false;
    }

    $payload = [
        'service_id' => EMAILJS_SERVICE_ID,
        'template_id' => EMAILJS_TEMPLATE_ID,
        'user_id' => EMAILJS_PUBLIC_KEY,
        'accessToken' => EMAILJS_PRIVATE_KEY,
        'template_params' => [
            'course_name' => $courseName,
            'announcement_title' => $announcementTitle,
            'announcement_content' => $announcementContent,
            'unsubscribe_link' => $unsubscribeLink,
            'to_email' => $toEmail
        ]
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, EMAILJS_API_URL);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if (curl_errno($ch)) {
        error_log('EmailJS send error: ' . curl_error($ch));
        curl_close($ch);
        return false;
    }
    curl_close($ch);
    if ($httpCode < 200 || $httpCode >= 300) {
        error_log('EmailJS send failed. HTTP ' . $httpCode . ' Response: ' . $response);
        return false;
    }
    return true;
}

function notifySubscribersForAnnouncement($announcement_id) {
    global $pdo;

    $stmt = $pdo->prepare("SELECT a.*, p.name AS program_name, p.code AS program_code FROM announcements a LEFT JOIN programs p ON a.program_id = p.id WHERE a.id = ?");
    $stmt->execute([$announcement_id]);
    $announcement = $stmt->fetch();

    if (!$announcement || empty($announcement['program_id']) || empty($announcement['is_published']) || (int)$announcement['is_approved'] !== 1) {
        return;
    }

    $subscribers = getActiveSubscribersByProgram($announcement['program_id']);
    if (empty($subscribers)) {
        return;
    }

    $courseName = $announcement['program_name'] ?: 'Your course';
    foreach ($subscribers as $sub) {
        $unsubscribeLink = getBaseUrl() . 'subscribe.php?unsubscribe_token=' . urlencode($sub['unsubscribe_token']);
        sendEmailJsNotification(
            $sub['email'],
            $courseName,
            $announcement['title'],
            $announcement['content'],
            $unsubscribeLink
        );
    }
}
?>