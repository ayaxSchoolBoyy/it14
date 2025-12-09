
<?php
// super_admin.php - Super Admin Dashboard
require_once 'config.php';
requireSuperAdmin();

$current_user = getCurrentUser();
$profile_display_name = $current_user['full_name'] ?? ($_SESSION['full_name'] ?? 'Super Admin');
$profile_avatar_url = !empty($current_user['profile_picture'])
    ? $current_user['profile_picture']
    : 'https://ui-avatars.com/api/?background=832222&color=fff&name=' . urlencode($profile_display_name);
$profile_role_label = !empty($current_user['role'])
    ? ucwords(str_replace('_', ' ', $current_user['role']))
    : 'Administrator';
$message = '';
$error = '';

// Handle user creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_user'])) {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']); // Super Admin sets the default password
    $email = trim($_POST['email']);
    $full_name = trim($_POST['full_name']);
    $role = $_POST['role'];
    $department_id = ($role === 'admin') ? $_POST['department_id'] : NULL;
    $require_password_change = 1; // Require password change on first login
    
    if (!empty($username) && !empty($password) && !empty($email) && !empty($full_name)) {
        // Enforce UM email domain
        if (!preg_match('/@umindanao\.edu\.ph$/i', $email)) {
            $error = "Email must end with @umindanao.edu.ph";
        } else {
        try {
            // Check if username exists
            $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
            $stmt->execute([$username]);
            
            if ($stmt->fetch()) {
                $error = "Username already exists.";
            } else {
                // Hash the default password
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                
                // Insert new user with password change requirement
                $stmt = $pdo->prepare("INSERT INTO users (username, password, email, role, full_name, department_id, require_password_change) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$username, $hashed_password, $email, $role, $full_name, $department_id, $require_password_change]);
                if (function_exists('logActivity')) {
                    $details = json_encode([
                        'username' => $username,
                        'email' => $email,
                        'full_name' => $full_name,
                        'role' => $role,
                        'department_id' => $department_id
                    ]);
                    logActivity('user_create', $details);
                }
                
                $message = "User account created successfully! The user will be required to change their password on first login.";
            }
        } catch (Exception $e) {
            error_log('Create user failed: ' . $e->getMessage());
            $error = "We couldn't save that user right now. Please try again.";
        }
        }
    } else {
        $error = "Please fill in all fields.";
    }
}

// Handle user archive/unarchive actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['archive_user'])) {
    $user_id = (int)($_POST['user_id'] ?? 0);

    if ($user_id === (int)$_SESSION['user_id']) {
        $error = "You cannot archive your own account.";
    } elseif ($user_id > 0) {
        try {
            $stmt = $pdo->prepare("UPDATE users SET is_archived = 1, archived_at = NOW() WHERE id = ?");
            $stmt->execute([$user_id]);

            if (function_exists('logActivity')) {
                $details = json_encode(['archived_user_id' => $user_id]);
                logActivity('user_archive', $details, $user_id);
            }

            $message = "User archived. They can no longer sign in, but their posts stay visible.";
        } catch (Exception $e) {
            $error = "We couldn't archive that user right now. Please try again.";
            error_log('Archive user failed: ' . $e->getMessage());
        }
    } else {
        $error = "Please pick a valid user.";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['unarchive_user'])) {
    $user_id = (int)($_POST['user_id'] ?? 0);

    if ($user_id > 0) {
        try {
            $stmt = $pdo->prepare("UPDATE users SET is_archived = 0, archived_at = NULL WHERE id = ?");
            $stmt->execute([$user_id]);

            if (function_exists('logActivity')) {
                $details = json_encode(['unarchived_user_id' => $user_id]);
                logActivity('user_unarchive', $details, $user_id);
            }

            $message = "User access restored. They can sign in again.";
        } catch (Exception $e) {
            $error = "We couldn't restore that user right now. Please try again.";
            error_log('Unarchive user failed: ' . $e->getMessage());
        }
    } else {
        $error = "Please pick a valid user.";
    }
}

// Handle super admin announcement creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_sa_announcement'])) {
    $title = trim($_POST['sa_title'] ?? '');
    $content = trim($_POST['sa_content'] ?? '');
    $department_id = !empty($_POST['sa_department_id']) ? (int)$_POST['sa_department_id'] : NULL;
    $program_id = !empty($_POST['sa_program_id']) ? (int)$_POST['sa_program_id'] : NULL;
    $event_date = normalizeDateInput($_POST['sa_event_date'] ?? null);
    $event_time = normalizeTimeInput($_POST['sa_event_time'] ?? null);
    $event_location = trim($_POST['sa_event_location'] ?? '') ?: NULL;
    $is_published = 1;

    if (!empty($title) && !empty($content)) {
        try {
            $stmt = $pdo->prepare("INSERT INTO announcements (
                title, content, department_id, program_id, event_date, event_time, event_location,
                is_published, is_approved, approved_by, approved_at, author_id
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?, NOW(), ?)");
            $stmt->execute([
                $title,
                $content,
                $department_id,
                $program_id,
                $event_date,
                $event_time,
                $event_location,
                $is_published,
                $_SESSION['user_id'],
                $_SESSION['user_id']
            ]);

            $newAnnouncementId = $pdo->lastInsertId();

            if (function_exists('logActivity')) {
                $details = json_encode([
                    'announcement_id' => $newAnnouncementId,
                    'title' => $title,
                    'is_published' => $is_published
                ]);
                logActivity('announcement_create', $details);
            }

            if (!empty($is_published) && function_exists('notifySubscribersForAnnouncement')) {
                notifySubscribersForAnnouncement($newAnnouncementId);
            }

            $message = "Announcement published successfully.";
        } catch (Exception $e) {
            error_log('Super admin publish failed: ' . $e->getMessage());
            $error = "We couldn't publish that announcement yet. Please try again.";
        }
    } else {
        $error = "Title and content are required.";
    }
}

// Handle super admin announcement updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sa_update_announcement'])) {
    $announcement_id = (int)($_POST['announcement_id'] ?? 0);
    $title = trim($_POST['edit_title'] ?? '');
    $content = trim($_POST['edit_content'] ?? '');
    $department_id = !empty($_POST['edit_department_id']) ? (int)$_POST['edit_department_id'] : NULL;
    $program_id = !empty($_POST['edit_program_id']) ? (int)$_POST['edit_program_id'] : NULL;
    $event_date = normalizeDateInput($_POST['edit_event_date'] ?? null);
    $event_time = normalizeTimeInput($_POST['edit_event_time'] ?? null);
    $event_location = trim($_POST['edit_event_location'] ?? '') ?: NULL;
    $is_published = isset($_POST['edit_is_published']) ? 1 : 0;

    if ($announcement_id && !empty($title) && !empty($content)) {
        try {
            // Preserve current approval state so editing does not auto-approve or unpublish unintentionally
            $currentStmt = $pdo->prepare("SELECT a.is_approved, a.is_published, a.author_id, u.role AS author_role FROM announcements a LEFT JOIN users u ON a.author_id = u.id WHERE a.id = ?");
            $currentStmt->execute([$announcement_id]);
            $currentAnnouncement = $currentStmt->fetch();

            if (!$currentAnnouncement) {
                throw new Exception('Announcement not found.');
            }

            $author_is_super_admin = isset($currentAnnouncement['author_role']) && $currentAnnouncement['author_role'] === 'super_admin';

            $stmt = $pdo->prepare("UPDATE announcements SET
                title = ?,
                content = ?,
                department_id = ?,
                program_id = ?,
                event_date = ?,
                event_time = ?,
                event_location = ?,
                is_published = ?,
                updated_at = NOW()
            WHERE id = ?");
            $stmt->execute([
                $title,
                $content,
                $department_id,
                $program_id,
                $event_date,
                $event_time,
                $event_location,
                $is_published,
                $announcement_id
            ]);

            if ($author_is_super_admin) {
                $autoApproveStmt = $pdo->prepare("UPDATE announcements SET is_approved = 1, approved_by = ?, approved_at = NOW() WHERE id = ?");
                $autoApproveStmt->execute([$_SESSION['user_id'], $announcement_id]);
            }

            if (function_exists('logActivity')) {
                $details = json_encode([
                    'announcement_id' => $announcement_id,
                    'title' => $title,
                    'is_published' => $is_published,
                    'is_approved' => $currentAnnouncement['is_approved']
                ]);
                logActivity('announcement_update', $details);
            }

            $wasPublished = !empty($currentAnnouncement['is_published']);
            $wasApproved = (int)($currentAnnouncement['is_approved'] ?? 0) === 1;
            $nowPublished = !empty($is_published);
            $nowApproved = $author_is_super_admin ? true : $wasApproved;
            $shouldNotify = $nowPublished && $nowApproved && (!$wasPublished || !$wasApproved);

            if ($shouldNotify && function_exists('notifySubscribersForAnnouncement')) {
                notifySubscribersForAnnouncement($announcement_id);
            }

            if ($author_is_super_admin) {
                $message = "Announcement updated and published automatically.";
            } else {
                $message = is_null($currentAnnouncement['is_approved'])
                    ? "Changes saved. Announcement remains pending until you approve it."
                    : "Announcement updated.";
            }
        } catch (Exception $e) {
            error_log('Super admin update failed: ' . $e->getMessage());
            $error = "We couldn't save those changes. Please try again.";
        }
    } else {
        $error = "Announcement title and content are required.";
    }
}

// Handle super admin announcement deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sa_delete_announcement'])) {
    $announcement_id = (int)($_POST['announcement_id'] ?? 0);

    if ($announcement_id) {
        try {
            $stmt = $pdo->prepare("DELETE FROM announcements WHERE id = ?");
            $stmt->execute([$announcement_id]);

            if (function_exists('logActivity')) {
                $details = json_encode(['announcement_id' => $announcement_id]);
                logActivity('announcement_delete', $details);
            }

            $message = "Announcement deleted.";
        } catch (Exception $e) {
            error_log('Super admin delete failed: ' . $e->getMessage());
            $error = "We couldn't remove that announcement right now. Please try again.";
        }
    } else {
        $error = "Please pick a valid announcement.";
    }
}

// Handle approvals
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['approve_announcement'])) {
    $announcement_id = (int)($_POST['announcement_id'] ?? 0);
    if ($announcement_id) {
        try {
            $stmt = $pdo->prepare("UPDATE announcements SET is_approved = 1, is_published = 1, approved_by = ?, approved_at = NOW() WHERE id = ?");
            $stmt->execute([$_SESSION['user_id'], $announcement_id]);
            if (function_exists('logActivity')) {
                logActivity('announcement_approve', json_encode(['announcement_id' => $announcement_id]));
            }
            if (function_exists('notifySubscribersForAnnouncement')) {
                notifySubscribersForAnnouncement($announcement_id);
            }
            $message = "Announcement approved and published.";
        } catch (Exception $e) {
            error_log('Announcement approve failed: ' . $e->getMessage());
            $error = "We couldn't approve that post right now. Please try again.";
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reject_announcement'])) {
    $announcement_id = (int)($_POST['announcement_id'] ?? 0);
    if ($announcement_id) {
        try {
            $stmt = $pdo->prepare("UPDATE announcements SET is_approved = 0, is_published = 0, approved_by = ?, approved_at = NOW() WHERE id = ?");
            $stmt->execute([$_SESSION['user_id'], $announcement_id]);
            if (function_exists('logActivity')) {
                logActivity('announcement_reject', json_encode(['announcement_id' => $announcement_id]));
            }
            $message = "Announcement rejected.";
        } catch (Exception $e) {
            error_log('Announcement reject failed: ' . $e->getMessage());
            $error = "We couldn't reject that post right now. Please try again.";
        }
    }
}

// Get all users with their activity stats
$stmt = $pdo->prepare("
    SELECT 
        u.*, 
        d.code as department_code, 
        d.name as department_name,
        COUNT(a.id) as total_announcements,
        MAX(a.created_at) as last_activity
    FROM users u 
    LEFT JOIN departments d ON u.department_id = d.id
    LEFT JOIN announcements a ON u.id = a.author_id AND (a.is_archived IS NULL OR a.is_archived = 0)
    GROUP BY u.id
    ORDER BY COALESCE(u.is_archived, 0) ASC, u.created_at DESC
");
$stmt->execute();
$all_users = $stmt->fetchAll();

// Get all departments
$departments = getDepartments();

// Get all programs for filtering/assignment
$program_stmt = $pdo->prepare("SELECT p.*, d.code as department_code FROM programs p LEFT JOIN departments d ON p.department_id = d.id ORDER BY d.code, p.name");
$program_stmt->execute();
$all_programs = $program_stmt->fetchAll();

// Fetch all announcements with related metadata
$announcement_stmt = $pdo->prepare("SELECT 
        a.*, 
        u.full_name AS author_name,
        u.role AS author_role,
        d.code AS department_code,
        d.name AS department_name,
        p.name AS program_name,
        p.code AS program_code
    FROM announcements a
    LEFT JOIN users u ON a.author_id = u.id
    LEFT JOIN departments d ON a.department_id = d.id
    LEFT JOIN programs p ON a.program_id = p.id
    WHERE (a.is_archived IS NULL OR a.is_archived = 0)
    ORDER BY a.created_at DESC");
$announcement_stmt->execute();
$all_announcements = $announcement_stmt->fetchAll();

$user_recent_posts = [];
foreach ($all_announcements as $announcement) {
    $author_id = isset($announcement['author_id']) ? (int)$announcement['author_id'] : 0;
    if ($author_id === 0) {
        continue;
    }

    if (!isset($user_recent_posts[$author_id])) {
        $user_recent_posts[$author_id] = [];
    }

    $user_recent_posts[$author_id][] = [
        'title' => $announcement['title'],
        'created_at' => $announcement['created_at'],
        'is_published' => $announcement['is_published']
    ];
}

foreach ($user_recent_posts as $author_id => $posts) {
    usort($posts, function ($a, $b) {
        return strtotime($b['created_at']) <=> strtotime($a['created_at']);
    });
    $user_recent_posts[$author_id] = array_slice($posts, 0, 5);
}

$pending_announcements = array_filter($all_announcements, function ($announcement) {
    return is_null($announcement['is_approved']) && ($announcement['author_role'] ?? '') !== 'super_admin';
});

// Function to determine user activity status
function getUserActivityStatus($user) {
    if (!empty($user['is_archived'])) {
        return [
            'status' => 'archived',
            'label' => 'Archived',
            'color' => 'bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-300',
            'indicator' => 'inactive-user'
        ];
    }

    $last_login = !empty($user['last_login_at']) ? strtotime($user['last_login_at']) : null;
    $threshold = strtotime('-30 days');

    if ($last_login && $last_login >= $threshold) {
        return [
            'status' => 'active',
            'label' => 'Active',
            'color' => 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200',
            'indicator' => 'active-user'
        ];
    }

    return [
        'status' => 'inactive',
        'label' => 'Inactive',
        'color' => 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200',
        'indicator' => 'inactive-user'
    ];
}

// Count users by activity status for stats
$activity_stats = [
    'active' => 0,
    'inactive' => 0
];

foreach ($all_users as $user) {
    $activity_status = getUserActivityStatus($user);
    if (isset($activity_stats[$activity_status['status']])) {
        $activity_stats[$activity_status['status']]++;
    }
}

// Site-wide statistics for dashboard cards
$stats_total_announcements = (int)$pdo->query("SELECT COUNT(*) FROM announcements WHERE is_published = TRUE AND is_approved = 1")->fetchColumn();
$stats_total_departments = (int)$pdo->query("SELECT COUNT(*) FROM departments")->fetchColumn();
$stats_upcoming_events = (int)$pdo->query("SELECT COUNT(*) FROM announcements WHERE is_published = TRUE AND is_approved = 1 AND event_date IS NOT NULL AND (event_date > CURDATE() OR (event_date = CURDATE() AND (event_time IS NULL OR event_time > CURTIME())))")->fetchColumn();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super Admin - UMTC Announcement System</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
    <script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/feather-icons/dist/feather.min.js"></script>
    <style>
        .gradient-bg {
            background: linear-gradient(135deg, #7c2020ff 0%, #832222ff 100%);
        }
        .card-hover:hover {
            transform: translateY(-5px);
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
        }
        .nav-glass {
            backdrop-filter: blur(10px);
            background-color: rgba(255, 255, 255, 0.8);
        }
        .dark .nav-glass {
            background-color: rgba(0, 0, 0, 0.8);
        }
        .form-input, .form-select {
            border: 1px solid #e5e7eb;
            border-radius: 0.5rem;
            padding: 0.5rem 0.75rem;
            width: 100%;
        }
        .form-input:focus, .form-select:focus {
            outline: none;
            border-color: #02040cff;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        .activity-indicator {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 6px;
        }
        .active-user {
            background-color: #10b981;
        }
        .inactive-user {
            background-color: #ef4444;
        }
        .user-row-indicator {
            transition: transform 0.2s ease;
        }
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        .modal.active {
            display: flex;
        }
        .modal-content {
            background: white;
            border-radius: 0.75rem;
            max-width: 90%;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
        }
        .filtered-out { display: none !important; }
    </style>
</head>
<body class="bg-gray-50 dark:bg-gray-900 transition-colors duration-300">
    <!-- Navigation -->
    <nav class="fixed w-full z-50 nav-glass shadow-sm dark:shadow-gray-800">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-20">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <i data-feather="megaphone" class="text-red-600 dark:text-red-400"></i>
                        <span class="ml-2 text-xl font-bold text-gray-800 dark:text-white">UMTC</span>
                    </div>
                </div>
                <div class="flex items-center space-x-4">
                    <div class="relative">
                        <button id="super-profile-menu-button" class="pl-2 pr-3 py-1.5 rounded-full border border-gray-300 text-gray-700 bg-white hover:bg-gray-50 transition-colors duration-300 flex items-center gap-3 dark:bg-gray-800 dark:text-gray-200 dark:border-gray-600" aria-haspopup="true" aria-expanded="false">
                            <img src="<?php echo htmlspecialchars($profile_avatar_url); ?>" alt="Profile photo" class="w-12 h-12 rounded-full object-cover border-2 border-red-100 dark:border-gray-700">
                            <div class="text-left">
                                <p class="text-sm font-semibold leading-tight text-gray-900 dark:text-white"><?php echo htmlspecialchars($profile_display_name); ?></p>
                                <p class="text-xs text-gray-500 dark:text-gray-400"><?php echo htmlspecialchars($profile_role_label); ?></p>
                            </div>
                            <i data-feather="chevron-down" class="w-4 h-4 text-gray-500"></i>
                        </button>
                        <div id="super-profile-menu" class="hidden absolute right-0 mt-2 w-48 bg-white border border-gray-200 rounded-md shadow-lg z-50 dark:bg-gray-800 dark:border-gray-700">
                            <a href="profile.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 dark:text-gray-100 dark:hover:bg-gray-700">Profile</a>
                            <a href="change_password.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 dark:text-gray-100 dark:hover:bg-gray-700">Change Password</a>
                            <a href="logout.php" class="block px-4 py-2 text-sm text-red-600 hover:bg-red-50 border-t border-gray-100 dark:border-gray-700 dark:text-red-400 dark:hover:bg-red-900/20" onclick="return confirm('Are you sure you want to log out?');">Logout</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="pt-28 pb-12 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <h1 class="text-3xl font-bold text-gray-900 dark:text-white mb-8" data-aos="fade-down">Super Admin Dashboard</h1>

        <!-- Site Overview Stats -->
        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6 mb-10" data-aos="fade-up" data-aos-delay="50">
            <button type="button" data-scroll-target="manage-announcements" class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-6 flex items-center text-left w-full focus:outline-none focus:ring-2 focus:ring-red-500">
                <div class="w-14 h-14 rounded-full bg-red-100 dark:bg-red-900 flex items-center justify-center">
                    <i data-feather="radio" class="text-red-600 dark:text-red-300"></i>
                </div>
                <div class="ml-4">
                    <p class="text-sm text-gray-500 dark:text-gray-400">Total Announcements</p>
                    <p class="text-3xl font-bold text-gray-900 dark:text-white"><?php echo $stats_total_announcements; ?></p>
                </div>
            </button>

            <button type="button" data-scroll-target="user-management" class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-6 flex items-center text-left w-full focus:outline-none focus:ring-2 focus:ring-red-500">
                <div class="w-14 h-14 rounded-full bg-pink-100 dark:bg-pink-900 flex items-center justify-center">
                    <i data-feather="globe" class="text-pink-600 dark:text-pink-300"></i>
                </div>
                <div class="ml-4">
                    <p class="text-sm text-gray-500 dark:text-gray-400">Departments</p>
                    <p class="text-3xl font-bold text-gray-900 dark:text-white"><?php echo $stats_total_departments; ?></p>
                </div>
            </button>

            <button type="button" data-scroll-target="quick-publisher" class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-6 flex items-center text-left w-full focus:outline-none focus:ring-2 focus:ring-red-500">
                <div class="w-14 h-14 rounded-full bg-amber-100 dark:bg-amber-900 flex items-center justify-center">
                    <i data-feather="calendar" class="text-amber-600 dark:text-amber-300"></i>
                </div>
                <div class="ml-4">
                    <p class="text-sm text-gray-500 dark:text-gray-400">Upcoming Events</p>
                    <p class="text-3xl font-bold text-gray-900 dark:text-white"><?php echo $stats_upcoming_events; ?></p>
                </div>
            </button>

            <button type="button" data-scroll-target="user-management" class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-6 flex items-center text-left w-full focus:outline-none focus:ring-2 focus:ring-red-500">
                <div class="w-14 h-14 rounded-full bg-red-100 dark:bg-red-900 flex items-center justify-center">
                    <i data-feather="users" class="text-red-600 dark:text-red-300"></i>
                </div>
                <div class="ml-4">
                    <p class="text-sm text-gray-500 dark:text-gray-400">Total Users</p>
                    <p class="text-3xl font-bold text-gray-900 dark:text-white"><?php echo count($all_users); ?></p>
                </div>
            </button>

            <button type="button" data-scroll-target="user-management" class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-6 flex items-center text-left w-full focus:outline-none focus:ring-2 focus:ring-red-500">
                <div class="w-14 h-14 rounded-full bg-green-100 dark:bg-green-900 flex items-center justify-center">
                    <i data-feather="user-check" class="text-green-600 dark:text-green-300"></i>
                </div>
                <div class="ml-4">
                    <p class="text-sm text-gray-500 dark:text-gray-400">Active Users (30 days)</p>
                    <p class="text-3xl font-bold text-gray-900 dark:text-white"><?php echo $activity_stats['active']; ?></p>
                </div>
            </button>

            <button type="button" data-scroll-target="user-management" class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-6 flex items-center text-left w-full focus:outline-none focus:ring-2 focus:ring-red-500">
                <div class="w-14 h-14 rounded-full bg-red-100 dark:bg-red-900 flex items-center justify-center">
                    <i data-feather="user-x" class="text-red-600 dark:text-red-300"></i>
                </div>
                <div class="ml-4">
                    <p class="text-sm text-gray-500 dark:text-gray-400">Inactive Users</p>
                    <p class="text-3xl font-bold text-gray-900 dark:text-white"><?php echo $activity_stats['inactive']; ?></p>
                </div>
            </button>
        </div>

        <!-- User Management Card -->
        <div id="user-management" class="bg-white dark:bg-gray-800 rounded-xl shadow-md overflow-hidden mb-8 transition-all duration-300 card-hover" data-aos="fade-up">
            <div class="gradient-bg px-6 py-4">
                <h2 class="text-xl font-semibold text-white">User Management</h2>
            </div>
            <div class="p-6">
                <!-- Alerts -->
                <?php if (!empty($message)): ?>
                    <div class="mb-6 px-4 py-3 rounded-md bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200">
                        <?php echo $message; ?>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($error)): ?>
                    <div class="mb-6 px-4 py-3 rounded-md bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200">
                        <?php echo $error; ?>
                    </div>
                <?php endif; ?>

                <!-- Quick Action Buttons -->
                <div class="flex flex-wrap gap-4 mb-6">
                    <a href="create_user.php" class="inline-flex items-center justify-center px-6 py-3 border border-green-600 text-green-600 rounded-lg font-medium hover:bg-green-600 hover:text-white transition-colors duration-300">
                        <i data-feather="user-plus" class="w-5 h-5 mr-2"></i>
                        Create User
                    </a>
                    
                    <a href="activity_logs.php" class="inline-flex items-center justify-center px-6 py-3 border border-blue-600 text-blue-600 rounded-lg font-medium hover:bg-blue-600 hover:text-white transition-colors duration-300">
                        <i data-feather="file-text" class="w-5 h-5 mr-2"></i>
                        Reports / Logs
                    </a>
                </div>

                

                <!-- Existing Users Table -->
                <h3 class="text-lg font-medium text-gray-900 dark:text-white mt-8 mb-4">Existing Admins</h3>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-700">
                            <tr>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">User Info</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Role & Department</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Activity Status</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Activity Details</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Account Created</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                            <?php foreach ($all_users as $user): ?>
                            <?php 
                                $activity_status = getUserActivityStatus($user);
                                $isArchived = !empty($user['is_archived']);
                                $user_avatar = !empty($user['profile_picture'])
                                    ? $user['profile_picture']
                                    : 'https://ui-avatars.com/api/?background=832222&color=fff&name=' . urlencode($user['full_name'] ?: $user['username']);
                            ?>
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-150 cursor-pointer <?php echo $isArchived ? 'opacity-70' : ''; ?>" data-user-row="<?php echo $user['id']; ?>" aria-expanded="false">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="flex items-center">
                                        <span class="user-row-indicator mr-3 text-gray-400 transform">
                                            <i data-feather="chevron-right" class="w-4 h-4"></i>
                                        </span>
                                        <div class="flex-shrink-0 h-12 w-12">
                                            <img src="<?php echo htmlspecialchars($user_avatar); ?>" alt="<?php echo htmlspecialchars($user['full_name'] ?? $user['username']); ?>" class="h-12 w-12 rounded-full object-cover border-2 border-red-100 dark:border-gray-700" loading="lazy">
                                        </div>
                                        <div class="ml-4">
                                            <div class="text-sm font-medium text-gray-900 dark:text-white">
                                                <?php echo htmlspecialchars($user['username']); ?>
                                            </div>
                                            <div class="text-sm text-gray-500 dark:text-gray-400">
                                                <?php echo htmlspecialchars($user['full_name']); ?>
                                            </div>
                                            <div class="text-xs text-gray-400 dark:text-gray-500">
                                                <?php echo htmlspecialchars($user['email']); ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo $user['role'] === 'super_admin' ? 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200' : 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200'; ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', $user['role'])); ?>
                                    </span>
                                    <div class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                                        <?php echo !empty($user['department_code']) ? $user['department_code'] : 'N/A'; ?>
                                    </div>
                                    <?php if ($isArchived): ?>
                                        <div class="mt-1">
                                            <span class="px-2 py-0.5 text-xs rounded-full bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-200">Archived</span>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo $activity_status['color']; ?>">
                                        <?php echo $activity_status['label']; ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-300">
                                    <div class="flex items-center">
                                        <span class="activity-indicator <?php echo $activity_status['indicator']; ?>"></span>
                                        <div>
                                            <div class="font-medium">
                                                <?php echo $user['total_announcements']; ?> announcements
                                            </div>
                                            <div class="text-xs text-gray-400">
                                                Last Login: <?php echo $user['last_login_at'] ? date('M j, Y g:i A', strtotime($user['last_login_at'])) : 'Never'; ?>
                                             </div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-300">
                                    <?php echo date('M j, Y', strtotime($user['created_at'])); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium" data-no-toggle="true">
                                    <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                        <?php if ($isArchived): ?>
                                            <form method="POST" class="inline" onsubmit="return confirm('Unarchive this user and let them sign in again?');">
                                                <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                <button type="submit" name="unarchive_user" class="text-green-600 hover:text-green-800 dark:text-green-400 dark:hover:text-green-300 transition-colors duration-200 flex items-center">
                                                    <i data-feather="rotate-ccw" class="w-4 h-4 mr-1"></i>
                                                    Unarchive
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <form method="POST" class="inline" onsubmit="return confirm('Archive this user? They will no longer be able to sign in.');">
                                                <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                <button type="submit" name="archive_user" class="text-yellow-600 hover:text-yellow-800 dark:text-yellow-400 dark:hover:text-yellow-300 transition-colors duration-200 flex items-center">
                                                    <i data-feather="archive" class="w-4 h-4 mr-1"></i>
                                                    Archive
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-gray-400 dark:text-gray-500 text-xs">Current User</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr id="user-activity-<?php echo $user['id']; ?>" class="user-activity-row hidden bg-gray-50 dark:bg-gray-900/40">
                                <td colspan="6" class="px-12 py-4">
                                    <div class="text-sm text-gray-700 dark:text-gray-200">
                                        <div class="flex items-center justify-between mb-3">
                                            <span class="font-semibold">Recent Posts</span>
                                            <span class="text-xs text-gray-500">
                                                Showing up to 5 latest announcements
                                            </span>
                                        </div>
                                        <?php if (!empty($user_recent_posts[$user['id']])): ?>
                                            <ul class="space-y-2">
                                                <?php foreach ($user_recent_posts[$user['id']] as $recent_post): ?>
                                                    <li class="flex flex-wrap items-center justify-between gap-2 border border-gray-200 dark:border-gray-700 rounded-md px-3 py-2">
                                                        <div class="font-medium text-gray-900 dark:text-white">
                                                            <?php echo htmlspecialchars($recent_post['title']); ?>
                                                        </div>
                                                        <div class="flex items-center gap-3 text-xs text-gray-500 dark:text-gray-400">
                                                            <span class="px-2 py-0.5 rounded-full border border-gray-300 dark:border-gray-600">
                                                                <?php echo $recent_post['is_published'] ? 'Published' : 'Hidden'; ?>
                                                            </span>
                                                            <span><?php echo date('M j, Y g:i A', strtotime($recent_post['created_at'])); ?></span>
                                                        </div>
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>
                                        <?php else: ?>
                                            <p class="text-sm text-gray-500">No announcements recorded for this user.</p>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Quick Announcement Publisher -->
        <div id="quick-publisher" class="bg-white dark:bg-gray-800 rounded-xl shadow-md overflow-hidden mb-8" data-aos="fade-up" data-aos-delay="150">
            <div class="gradient-bg px-6 py-4 flex items-center justify-between">
                <div>
                    <h2 class="text-xl font-semibold text-white">Quick Announcement Publisher</h2>
                    <p class="text-white/80 text-sm">Post network-wide updates instantly with automatic approval.</p>
                </div>
                <div class="hidden sm:flex items-center text-white text-sm">
                    <i data-feather="zap" class="w-4 h-4 mr-2"></i>
                    Immediate publish
                </div>
            </div>
            <div class="p-6">
                <form method="POST" class="space-y-6">
                    <input type="hidden" name="create_sa_announcement" value="1">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Title <span class="text-red-500">*</span></label>
                            <input type="text" name="sa_title" class="form-input" placeholder="Enter announcement title" required>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Department</label>
                                <select name="sa_department_id" class="form-select">
                                    <option value="">All Departments</option>
                                    <?php foreach ($departments as $dept): ?>
                                        <option value="<?php echo $dept['id']; ?>">
                                            <?php echo htmlspecialchars($dept['code']); ?> - <?php echo htmlspecialchars($dept['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Program</label>
                                <select name="sa_program_id" class="form-select">
                                    <option value="">All Programs</option>
                                    <?php foreach ($all_programs as $program): ?>
                                        <option value="<?php echo $program['id']; ?>">
                                            <?php echo htmlspecialchars($program['code'] ?? $program['name']); ?>
                                            <?php if (!empty($program['department_code'])): ?>
                                                (<?php echo htmlspecialchars($program['department_code']); ?>)
                                            <?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Announcement Content <span class="text-red-500">*</span></label>
                        <textarea name="sa_content" rows="4" class="form-input" placeholder="Share the details with the community" required></textarea>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Event Date</label>
                            <input type="date" name="sa_event_date" class="form-input">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Event Time</label>
                            <input type="time" name="sa_event_time" class="form-input">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Location</label>
                            <input type="text" name="sa_event_location" class="form-input" placeholder="Optional location details">
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center justify-between gap-4">
                        <p class="text-sm text-gray-500 dark:text-gray-400">Super admin announcements publish instantly and are auto-approved.</p>
                        <button type="submit" class="inline-flex items-center px-6 py-3 rounded-lg gradient-bg text-white font-medium shadow-md hover:opacity-95 transition">
                            <i data-feather="send" class="w-4 h-4 mr-2"></i>
                            Publish Announcement
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Pending Approvals -->
        <div id="pending-announcements" class="bg-white dark:bg-gray-800 rounded-xl shadow-md overflow-hidden mb-8" data-aos="fade-up" data-aos-delay="200">
            <div class="px-6 py-4 border-b border-gray-100 dark:border-gray-700 flex items-center justify-between">
                <div>
                    <h2 class="text-xl font-semibold text-gray-900 dark:text-white">Pending Announcements</h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400">Review administrator submissions awaiting approval.</p>
                </div>
                <span class="inline-flex items-center px-3 py-1 text-sm rounded-full <?php echo empty($pending_announcements) ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800'; ?>">
                    <?php echo count($pending_announcements); ?> pending
                </span>
            </div>
            <div class="p-6 space-y-4">
                <?php if (empty($pending_announcements)): ?>
                    <div class="text-center py-8 text-gray-500 dark:text-gray-400">
                        <i data-feather="check-circle" class="w-10 h-10 mx-auto mb-3 text-green-500"></i>
                        All caught up! No pending announcements.
                    </div>
                <?php else: ?>
                    <?php foreach ($pending_announcements as $pending): ?>
                        <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-4 bg-gray-50 dark:bg-gray-900/40">
                            <div class="flex flex-wrap justify-between gap-2 mb-3">
                                <div>
                                    <h3 class="text-lg font-semibold text-gray-900 dark:text-white"><?php echo htmlspecialchars($pending['title']); ?></h3>
                                    <p class="text-sm text-gray-500 dark:text-gray-400">Submitted by <?php echo htmlspecialchars($pending['author_name'] ?? 'Unknown'); ?> on <?php echo date('M j, Y g:i A', strtotime($pending['created_at'])); ?></p>
                                </div>
                                <div class="flex items-center gap-2 text-xs">
                                    <?php if ($pending['department_code']): ?>
                                        <span class="px-2 py-1 rounded-full bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200">
                                            <?php echo htmlspecialchars($pending['department_code']); ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php if ($pending['program_code']): ?>
                                        <span class="px-2 py-1 rounded-full bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-200">
                                            <?php echo htmlspecialchars($pending['program_code']); ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <p class="text-gray-700 dark:text-gray-300 text-sm mb-4 overflow-hidden"><?php echo nl2br(htmlspecialchars($pending['content'])); ?></p>
                            <div class="flex flex-wrap gap-3">
                                <form method="POST">
                                    <input type="hidden" name="announcement_id" value="<?php echo $pending['id']; ?>">
                                    <button type="submit" name="approve_announcement" class="inline-flex items-center px-4 py-2 rounded-lg bg-green-600 text-white text-sm font-medium hover:bg-green-700 transition">
                                        <i data-feather="check" class="w-4 h-4 mr-2"></i>
                                        Approve & Publish
                                    </button>
                                </form>
                                <form method="POST" onsubmit="return confirm('Reject this announcement?');">
                                    <input type="hidden" name="announcement_id" value="<?php echo $pending['id']; ?>">
                                    <button type="submit" name="reject_announcement" class="inline-flex items-center px-4 py-2 rounded-lg bg-red-600 text-white text-sm font-medium hover:bg-red-700 transition">
                                        <i data-feather="x" class="w-4 h-4 mr-2"></i>
                                        Reject
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Manage All Announcements -->
        <div id="manage-announcements" class="bg-white dark:bg-gray-800 rounded-xl shadow-md overflow-hidden mb-12" data-aos="fade-up" data-aos-delay="250">
            <div class="px-6 py-4 border-b border-gray-100 dark:border-gray-700">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 class="text-xl font-semibold text-gray-900 dark:text-white">Manage All Announcements</h2>
                        <p class="text-sm text-gray-500 dark:text-gray-400">Edit, republish, or delete any post across departments.</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-3">
                        <label class="text-xs font-semibold text-gray-600 dark:text-gray-300" for="saEventFrom">Event Date</label>
                        <input id="saEventFrom" type="date" class="form-input w-36 text-sm" aria-label="Event date from">
                        <span class="text-xs text-gray-400 dark:text-gray-500">to</span>
                        <input id="saEventTo" type="date" class="form-input w-36 text-sm" aria-label="Event date to">
                        <button id="saEventReset" type="button" class="inline-flex items-center px-3 py-2 rounded-full border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700 text-sm">Clear</button>
                        <div class="text-xs text-gray-400 dark:text-gray-500">
                            Showing <?php echo count($all_announcements); ?> announcements
                        </div>
                    </div>
                </div>
            </div>
            <div class="announcement-accordion divide-y divide-gray-200 dark:divide-gray-700">
                <?php if (empty($all_announcements)): ?>
                    <div class="p-6 text-center text-gray-500 dark:text-gray-400">
                        <i data-feather="inbox" class="w-12 h-12 mx-auto mb-4"></i>
                        No announcements have been posted yet.
                    </div>
                <?php else: ?>
                    <?php foreach ($all_announcements as $index => $announcement): ?>
                        <details class="group sa-announcement" data-event-date="<?php echo htmlspecialchars($announcement['event_date']); ?>" data-list-index="<?php echo $index; ?>">
                            <summary class="list-none cursor-pointer flex items-center justify-between px-6 py-4 bg-gray-50 dark:bg-gray-900/40">
                                <div>
                                    <div class="flex items-center gap-3">
                                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white">
                                            <?php echo htmlspecialchars($announcement['title']); ?>
                                        </h3>
                                        <?php if ((int)$announcement['is_approved'] === 1): ?>
                                            <span class="px-2 py-0.5 text-xs rounded-full bg-green-100 text-green-800">Approved</span>
                                        <?php elseif (is_null($announcement['is_approved'])): ?>
                                            <span class="px-2 py-0.5 text-xs rounded-full bg-yellow-100 text-yellow-800">Pending</span>
                                        <?php else: ?>
                                            <span class="px-2 py-0.5 text-xs rounded-full bg-red-100 text-red-800">Rejected</span>
                                        <?php endif; ?>
                                    </div>
                                    <p class="text-sm text-gray-500 dark:text-gray-400">
                                        Posted by <?php echo htmlspecialchars($announcement['author_name'] ?? 'Unknown'); ?> · <?php echo date('M j, Y g:i A', strtotime($announcement['created_at'])); ?>
                                    </p>
                                </div>
                                <i data-feather="chevron-down" class="w-5 h-5 text-gray-400 group-open:rotate-180 transition-transform"></i>
                            </summary>
                            <div class="px-6 py-5 bg-white dark:bg-gray-800">
                                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                                    <form method="POST" class="space-y-4">
                                        <input type="hidden" name="sa_update_announcement" value="1">
                                        <input type="hidden" name="announcement_id" value="<?php echo $announcement['id']; ?>">
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Title</label>
                                            <input type="text" name="edit_title" class="form-input" value="<?php echo htmlspecialchars($announcement['title']); ?>" required>
                                        </div>
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Content</label>
                                            <textarea name="edit_content" rows="4" class="form-input" required><?php echo htmlspecialchars($announcement['content']); ?></textarea>
                                        </div>
                                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                            <div>
                                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Department</label>
                                                <select name="edit_department_id" class="form-select">
                                                    <option value="">All Departments</option>
                                                    <?php foreach ($departments as $dept): ?>
                                                        <option value="<?php echo $dept['id']; ?>" <?php echo ($announcement['department_id'] == $dept['id']) ? 'selected' : ''; ?>>
                                                            <?php echo htmlspecialchars($dept['code']); ?> - <?php echo htmlspecialchars($dept['name']); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div>
                                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Program</label>
                                                <select name="edit_program_id" class="form-select">
                                                    <option value="">All Programs</option>
                                                    <?php foreach ($all_programs as $program): ?>
                                                        <option value="<?php echo $program['id']; ?>" <?php echo ($announcement['program_id'] == $program['id']) ? 'selected' : ''; ?>>
                                                            <?php echo htmlspecialchars($program['code'] ?? $program['name']); ?>
                                                            <?php if (!empty($program['department_code'])): ?>
                                                                (<?php echo htmlspecialchars($program['department_code']); ?>)
                                                            <?php endif; ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                                            <div>
                                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Event Date</label>
                                                <input type="date" name="edit_event_date" class="form-input" value="<?php echo $announcement['event_date'] ? date('Y-m-d', strtotime($announcement['event_date'])) : ''; ?>">
                                            </div>
                                            <div>
                                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Event Time</label>
                                                <input type="time" name="edit_event_time" class="form-input" value="<?php echo $announcement['event_time']; ?>">
                                            </div>
                                            <div>
                                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Location</label>
                                                <input type="text" name="edit_event_location" class="form-input" value="<?php echo htmlspecialchars($announcement['event_location']); ?>" placeholder="Optional">
                                            </div>
                                        </div>
                                        <label class="inline-flex items-center">
                                            <input type="checkbox" name="edit_is_published" class="h-4 w-4 text-red-600 border-gray-300 rounded focus:ring-red-500" <?php echo $announcement['is_published'] ? 'checked' : ''; ?>>
                                            <span class="ml-2 text-sm text-gray-700 dark:text-gray-300">Published</span>
                                        </label>
                                        <div class="flex flex-wrap gap-3">
                                            <button type="submit" class="inline-flex items-center px-4 py-2 rounded-lg bg-blue-600 text-white text-sm font-medium hover:bg-blue-700 transition">
                                                <i data-feather="save" class="w-4 h-4 mr-2"></i>
                                                Save Changes
                                            </button>
                                            <?php if (is_null($announcement['is_approved'])): ?>
                                                <button type="submit" name="approve_announcement" class="inline-flex items-center px-4 py-2 rounded-lg bg-green-600 text-white text-sm font-medium hover:bg-green-700 transition">
                                                    <i data-feather="check" class="w-4 h-4 mr-2"></i>
                                                    Approve Now
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </form>
                                    <div class="space-y-4">
                                        <div class="p-4 rounded-lg bg-gray-50 dark:bg-gray-900/40">
                                            <h4 class="text-sm font-semibold text-gray-900 dark:text-white mb-2">Audience & Visibility</h4>
                                            <ul class="text-sm text-gray-600 dark:text-gray-300 space-y-1">
                                                <li>Department: <strong><?php echo $announcement['department_code'] ? htmlspecialchars($announcement['department_code']) : 'All'; ?></strong></li>
                                                <li>Program: <strong><?php echo $announcement['program_code'] ? htmlspecialchars($announcement['program_code']) : 'All'; ?></strong></li>
                                                <li>Event Date: <strong><?php echo $announcement['event_date'] ? date('M j, Y', strtotime($announcement['event_date'])) : 'N/A'; ?></strong></li>
                                                <li>Location: <strong><?php echo $announcement['event_location'] ? htmlspecialchars($announcement['event_location']) : 'N/A'; ?></strong></li>
                                            </ul>
                                        </div>
                                        <div class="flex items-center justify-between text-sm text-gray-500 dark:text-gray-400">
                                            <span><?php echo $announcement['is_published'] ? 'Visible on portal' : 'Not published'; ?></span>
                                            <span>Updated <?php echo date('M j, Y g:i A', strtotime($announcement['created_at'])); ?></span>
                                        </div>
                                        <form method="POST" onsubmit="return confirm('Delete this announcement permanently?');">
                                            <input type="hidden" name="announcement_id" value="<?php echo $announcement['id']; ?>">
                                            <button type="submit" name="sa_delete_announcement" class="inline-flex items-center px-4 py-2 rounded-lg bg-red-50 text-red-600 border border-red-200 hover:bg-red-100 dark:bg-red-900/20 dark:text-red-300 dark:border-red-900">
                                                <i data-feather="trash" class="w-4 h-4 mr-2"></i>
                                                Delete Announcement
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </details>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <?php if (count($all_announcements) > 5): ?>
                <div class="px-6 py-4 border-t border-gray-100 dark:border-gray-700 bg-gray-50 dark:bg-gray-900/40 text-center">
                    <button id="saToggleAnnouncements" type="button" class="inline-flex items-center px-5 py-2 rounded-full border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700 font-semibold">
                        Show More
                    </button>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- User Management Modal -->
    <div id="userManagementModal" class="modal">
        <div class="modal-content w-full max-w-6xl mx-4">
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg">
                <!-- Modal Header -->
                <div class="gradient-bg px-6 py-4 rounded-t-xl">
                    <div class="flex items-center justify-between">
                        <h2 class="text-xl font-semibold text-white">User Management</h2>
                        <div class="flex items-center space-x-2">
                            <button onclick="closeUserManagement()" class="px-3 py-1 rounded-md bg-white/10 text-white hover:bg-white/20 transition">
                                Back
                            </button>
                            <button onclick="closeUserManagement()" class="text-white hover:text-gray-200 transition-colors" aria-label="Close">
                                <i data-feather="x" class="w-6 h-6"></i>
                            </button>
                        </div>
                    </div>
                </div>
                
                <!-- Modal Body -->
                <div class="p-6 max-h-[70vh] overflow-y-auto">
                    <!-- Activity Status Legend -->
                    <div class="mb-6 p-4 bg-gray-50 dark:bg-gray-700 rounded-lg">
                        <h4 class="text-sm font-medium text-gray-900 dark:text-white mb-2">Activity Status Legend:</h4>
                        <div class="flex flex-wrap gap-4 text-xs">
                            <div class="flex items-center">
                                <span class="activity-indicator active-user mr-2"></span>
                                <span class="px-2 py-1 bg-green-100 text-green-800 rounded">Recently Active</span>
                                <span class="ml-2 text-gray-500">(Last 7 days)</span>
                            </div>
                            <div class="flex items-center">
                                <span class="activity-indicator active-user mr-2"></span>
                                <span class="px-2 py-1 bg-blue-100 text-blue-800 rounded">Active</span>
                                <span class="ml-2 text-gray-500">(Last 30 days)</span>
                            </div>
                            <div class="flex items-center">
                                <span class="activity-indicator inactive-user mr-2"></span>
                                <span class="px-2 py-1 bg-yellow-100 text-yellow-800 rounded">Historically Active</span>
                                <span class="ml-2 text-gray-500">(Over 30 days ago)</span>
                            </div>
                            <div class="flex items-center">
                                <span class="activity-indicator inactive-user mr-2"></span>
                                <span class="px-2 py-1 bg-red-100 text-red-800 rounded">Never Active</span>
                                <span class="ml-2 text-gray-500">(No announcements)</span>
                            </div>
                        </div>
                    </div>

                    <!-- Alerts -->
                    <?php if (!empty($message)): ?>
                        <div class="mb-6 px-4 py-3 rounded-md bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200">
                            <?php echo $message; ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($error)): ?>
                        <div class="mb-6 px-4 py-3 rounded-md bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200">
                            <?php echo $error; ?>
                        </div>
                    <?php endif; ?>

                    

                    <!-- Existing Users Table -->
                    <div>
                        <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-4">Existing Admins</h3>
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                <thead class="bg-gray-50 dark:bg-gray-700">
                                    <tr>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">User Info</th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Role & Department</th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Activity Status</th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Activity Details</th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Account Created</th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                    <?php foreach ($all_users as $user): ?>
                                    <?php 
                                        $activity_status = getUserActivityStatus($user);
                                        $isArchived = !empty($user['is_archived']);
                                        $user_avatar = !empty($user['profile_picture'])
                                            ? $user['profile_picture']
                                            : 'https://ui-avatars.com/api/?background=832222&color=fff&name=' . urlencode($user['full_name'] ?: $user['username']);
                                    ?>
                                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-150 <?php echo $isArchived ? 'opacity-70' : ''; ?>">
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="flex items-center">
                                                <div class="flex-shrink-0 h-12 w-12">
                                                    <img src="<?php echo htmlspecialchars($user_avatar); ?>" alt="<?php echo htmlspecialchars($user['full_name'] ?? $user['username']); ?>" class="h-12 w-12 rounded-full object-cover border-2 border-red-100 dark:border-gray-700" loading="lazy">
                                                </div>
                                                <div class="ml-4">
                                                    <div class="text-sm font-medium text-gray-900 dark:text-white">
                                                        <?php echo htmlspecialchars($user['username']); ?>
                                                    </div>
                                                    <div class="text-sm text-gray-500 dark:text-gray-400">
                                                        <?php echo htmlspecialchars($user['full_name']); ?>
                                                    </div>
                                                    <div class="text-xs text-gray-400 dark:text-gray-500">
                                                        <?php echo htmlspecialchars($user['email']); ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo $user['role'] === 'super_admin' ? 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200' : 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200'; ?>">
                                                <?php echo ucfirst(str_replace('_', ' ', $user['role'])); ?>
                                            </span>
                                            <div class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                                                <?php echo !empty($user['department_code']) ? $user['department_code'] : 'N/A'; ?>
                                            </div>
                                            <?php if ($isArchived): ?>
                                                <div class="mt-1">
                                                    <span class="px-2 py-0.5 text-xs rounded-full bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-200">Archived</span>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo $activity_status['color']; ?>">
                                                <?php echo $activity_status['label']; ?>
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-300">
                                            <div class="flex items-center">
                                                <span class="activity-indicator <?php echo $activity_status['indicator']; ?>"></span>
                                                <div>
                                                    <div class="font-medium">
                                                        <?php echo $user['total_announcements']; ?> announcements
                                                    </div>
                                                    <div class="text-xs text-gray-400">
                                                        Last Login: <?php echo $user['last_login_at'] ? date('M j, Y g:i A', strtotime($user['last_login_at'])) : 'Never'; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-300">
                                            <?php echo date('M j, Y', strtotime($user['created_at'])); ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                            <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                                <?php if ($isArchived): ?>
                                                    <form method="POST" class="inline" onsubmit="return confirm('Unarchive this user and let them sign in again?');">
                                                        <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                        <button type="submit" name="unarchive_user" class="text-green-600 hover:text-green-800 dark:text-green-400 dark:hover:text-green-300 transition-colors duration-200 flex items-center">
                                                            <i data-feather="rotate-ccw" class="w-4 h-4 mr-1"></i>
                                                            Unarchive
                                                        </button>
                                                    </form>
                                                <?php else: ?>
                                                    <form method="POST" class="inline" onsubmit="return confirm('Archive this user? They will no longer be able to sign in.');">
                                                        <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                        <button type="submit" name="archive_user" class="text-yellow-600 hover:text-yellow-800 dark:text-yellow-400 dark:hover:text-yellow-300 transition-colors duration-200 flex items-center">
                                                            <i data-feather="archive" class="w-4 h-4 mr-1"></i>
                                                            Archive
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                            <?php else: ?>
                                            <span class="text-gray-400 dark:text-gray-500 text-xs">Current User</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Initialize AOS animations
        AOS.init({
            duration: 800,
            easing: 'ease-in-out',
            once: true
        });

        // Show/hide department field based on role selection (main form) - guard if elements exist
        const roleSelect = document.getElementById('roleSelect');
        const departmentSelect = document.getElementById('departmentSelect');
        if (roleSelect && departmentSelect) {
            roleSelect.addEventListener('change', function() {
                if (this.value === 'admin') {
                    departmentSelect.disabled = false;
                    departmentSelect.required = true;
                } else {
                    departmentSelect.disabled = true;
                    departmentSelect.required = false;
                    departmentSelect.value = '';
                }
            });
            if (roleSelect.value !== 'admin') {
                departmentSelect.disabled = true;
                departmentSelect.required = false;
            }
        }

        // Show/hide department field based on role selection (modal form) - guard if elements exist
        const modalRoleSelect = document.getElementById('modalRoleSelect');
        const modalDepartmentSelect = document.getElementById('modalDepartmentSelect');
        if (modalRoleSelect && modalDepartmentSelect) {
            modalRoleSelect.addEventListener('change', function() {
                if (this.value === 'admin') {
                    modalDepartmentSelect.disabled = false;
                    modalDepartmentSelect.required = true;
                } else {
                    modalDepartmentSelect.disabled = true;
                    modalDepartmentSelect.required = false;
                    modalDepartmentSelect.value = '';
                }
            });
            if (modalRoleSelect.value !== 'admin') {
                modalDepartmentSelect.disabled = true;
                modalDepartmentSelect.required = false;
            }
        }

        function setupProfileDropdown(buttonId, menuId) {
            const button = document.getElementById(buttonId);
            const menu = document.getElementById(menuId);
            if (!button || !menu) return;

            const hideMenu = () => {
                if (!menu.classList.contains('hidden')) {
                    menu.classList.add('hidden');
                    button.setAttribute('aria-expanded', 'false');
                }
            };

            button.addEventListener('click', function(event) {
                event.preventDefault();
                event.stopPropagation();
                if (menu.classList.contains('hidden')) {
                    menu.classList.remove('hidden');
                    button.setAttribute('aria-expanded', 'true');
                } else {
                    hideMenu();
                }
            });

            document.addEventListener('click', function(event) {
                if (!menu.contains(event.target) && !button.contains(event.target)) {
                    hideMenu();
                }
            });
        }

        setupProfileDropdown('super-profile-menu-button', 'super-profile-menu');

        const initEventDateFilter = (cardSelector, fromId, toId, resetId, afterFilter) => {
            const fromInput = document.getElementById(fromId);
            const toInput = document.getElementById(toId);
            const resetBtn = resetId ? document.getElementById(resetId) : null;
            const cards = document.querySelectorAll(cardSelector);

            if (!fromInput || !toInput || !cards.length) return;

            const applyFilter = () => {
                const fromDate = fromInput.value ? new Date(fromInput.value + 'T00:00:00') : null;
                const toDate = toInput.value ? new Date(toInput.value + 'T23:59:59') : null;

                cards.forEach((card) => {
                    const dateStr = card.dataset.eventDate;
                    if (!dateStr) {
                        card.classList.remove('filtered-out');
                        return;
                    }

                    const cardDate = new Date(dateStr + 'T12:00:00');
                    let visible = true;
                    if (fromDate && cardDate < fromDate) visible = false;
                    if (toDate && cardDate > toDate) visible = false;
                    card.classList.toggle('filtered-out', !visible);
                });

                if (typeof afterFilter === 'function') {
                    afterFilter();
                }
            };

            fromInput.addEventListener('change', applyFilter);
            toInput.addEventListener('change', applyFilter);
            if (resetBtn) {
                resetBtn.addEventListener('click', () => {
                    fromInput.value = '';
                    toInput.value = '';
                    applyFilter();
                });
            }

            applyFilter();
        };

        const statButtons = document.querySelectorAll('[data-scroll-target]');
        statButtons.forEach((button) => {
            button.addEventListener('click', function() {
                const targetId = this.getAttribute('data-scroll-target');
                const section = document.getElementById(targetId);
                if (!section) return;
                section.scrollIntoView({ behavior: 'smooth', block: 'start' });
                section.classList.add('ring-4', 'ring-red-200');
                setTimeout(() => {
                    section.classList.remove('ring-4', 'ring-red-200');
                }, 800);
            });
        });

        const userRows = document.querySelectorAll('[data-user-row]');
        const userActivityRows = document.querySelectorAll('.user-activity-row');
        if (userRows.length) {
            userRows.forEach((row) => {
                row.addEventListener('click', function(event) {
                    if (event.target.closest('[data-no-toggle]') || event.target.closest('button') || event.target.closest('a')) {
                        return;
                    }

                    const userId = this.getAttribute('data-user-row');
                    const detailsRow = document.getElementById(`user-activity-${userId}`);
                    if (!detailsRow) return;

                    const willOpen = detailsRow.classList.contains('hidden');

                    userActivityRows.forEach((activityRow) => {
                        if (activityRow !== detailsRow) {
                            activityRow.classList.add('hidden');
                        }
                    });

                    userRows.forEach((otherRow) => {
                        if (otherRow !== this) {
                            otherRow.setAttribute('aria-expanded', 'false');
                            const indicator = otherRow.querySelector('.user-row-indicator');
                            if (indicator) indicator.classList.remove('rotate-90');
                        }
                    });

                    if (willOpen) {
                        detailsRow.classList.remove('hidden');
                        this.setAttribute('aria-expanded', 'true');
                        const indicator = this.querySelector('.user-row-indicator');
                        if (indicator) indicator.classList.add('rotate-90');
                    } else {
                        detailsRow.classList.add('hidden');
                        this.setAttribute('aria-expanded', 'false');
                        const indicator = this.querySelector('.user-row-indicator');
                        if (indicator) indicator.classList.remove('rotate-90');
                    }
                });
            });
        }

        // Modal functions
        function openUserManagement() {
            document.getElementById('userManagementModal').classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeUserManagement() {
            document.getElementById('userManagementModal').classList.remove('active');
            document.body.style.overflow = 'auto';
        }

        // Close modal when clicking outside
        document.getElementById('userManagementModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeUserManagement();
            }
        });

        // Close modal with Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeUserManagement();
            }
        });

        // Ensure only one announcement accordion panel is open at a time
        const announcementAccordion = document.querySelector('.announcement-accordion');
        if (announcementAccordion) {
            const accordionItems = announcementAccordion.querySelectorAll('details');
            accordionItems.forEach((item) => {
                item.addEventListener('toggle', () => {
                    if (item.open) {
                        accordionItems.forEach((otherItem) => {
                            if (otherItem !== item) {
                                otherItem.removeAttribute('open');
                            }
                        });
                    }
                });
            });
        }

        const saShowMoreBtn = document.getElementById('saToggleAnnouncements');
        const saAnnouncements = Array.from(document.querySelectorAll('.sa-announcement'));
        let saAnnouncementsExpanded = false;

        const syncSaLimitedVisibility = () => {
            if (!saAnnouncements.length) return;

            let visibleCount = 0;
            saAnnouncements.forEach((item) => {
                if (item.classList.contains('filtered-out')) {
                    item.classList.add('hidden');
                    return;
                }

                if (!saAnnouncementsExpanded && visibleCount >= 5) {
                    item.classList.add('hidden');
                } else {
                    item.classList.remove('hidden');
                }

                visibleCount++;
            });

            if (saShowMoreBtn) {
                saShowMoreBtn.textContent = saAnnouncementsExpanded ? 'Show Less' : 'Show More';
            }
        };

        if (saShowMoreBtn) {
            saShowMoreBtn.addEventListener('click', () => {
                saAnnouncementsExpanded = !saAnnouncementsExpanded;
                syncSaLimitedVisibility();
            });
        }

        syncSaLimitedVisibility();

        initEventDateFilter('.sa-announcement', 'saEventFrom', 'saEventTo', 'saEventReset', syncSaLimitedVisibility);

        // Feather icons
        feather.replace();
    </script>
</body>
</html>
