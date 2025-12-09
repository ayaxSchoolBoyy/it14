<?php
// admin.php - Admin dashboard with department-aware workflows
require_once 'config.php';
requireAdmin();

$current_user = getCurrentUser();
$user_department = getUserDepartment();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['create'])) {
        $title = trim($_POST['title'] ?? '');
        $content = trim($_POST['content'] ?? '');
        $category = $_POST['category'] ?? 'general';
        $department_id = !empty($_POST['department_id']) ? $_POST['department_id'] : null;
        $program_id = !empty($_POST['program_id']) ? $_POST['program_id'] : null;
        $event_date = normalizeDateInput($_POST['event_date'] ?? null);
        $event_time = normalizeTimeInput($_POST['event_time'] ?? null);
        $event_location = !empty($_POST['event_location']) ? trim($_POST['event_location']) : null;
        $is_published = isset($_POST['is_published']) ? 1 : 0;

        if (!isSuperAdmin() && $user_department) {
            $department_id = $user_department;
            $is_published = 0; // Department admins cannot self-publish
        }

        if ($title !== '' && $content !== '') {
            if (isSuperAdmin()) {
                $is_approved = 1;
                $approved_by = $_SESSION['user_id'];
                $approved_at = date('Y-m-d H:i:s');
            } else {
                $is_approved = null;
                $approved_by = null;
                $approved_at = null;
            }

            $stmt = $pdo->prepare("INSERT INTO announcements (title, content, category, author_id, department_id, program_id, event_date, event_time, event_location, is_published, is_approved, approved_by, approved_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $title,
                $content,
                $category,
                $_SESSION['user_id'],
                $department_id,
                $program_id,
                $event_date,
                $event_time,
                $event_location,
                $is_published,
                $is_approved,
                $approved_by,
                $approved_at
            ]);

            $newAnnouncementId = $pdo->lastInsertId();

            if (function_exists('logActivity')) {
                $details = json_encode([
                    'title' => $title,
                    'category' => $category,
                    'department_id' => $department_id,
                    'program_id' => $program_id,
                    'event_date' => $event_date,
                    'event_time' => $event_time,
                    'is_published' => $is_published,
                    'is_approved' => $is_approved
                ]);
                logActivity('announcement_create', $details);
            }

            if (!empty($is_published) && function_exists('notifySubscribersForAnnouncement')) {
                notifySubscribersForAnnouncement($newAnnouncementId);
            }

            header('Location: admin.php');
            exit();
        }
    } elseif (isset($_POST['update'])) {
        $id = (int)($_POST['id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $content = trim($_POST['content'] ?? '');
        $category = $_POST['category'] ?? 'general';
        $department_id = !empty($_POST['department_id']) ? $_POST['department_id'] : null;
        $program_id = !empty($_POST['program_id']) ? $_POST['program_id'] : null;
        $event_date = normalizeDateInput($_POST['event_date'] ?? null);
        $event_time = normalizeTimeInput($_POST['event_time'] ?? null);
        $event_location = !empty($_POST['event_location']) ? trim($_POST['event_location']) : null;
        $is_published = isset($_POST['is_published']) ? 1 : 0;

        if ($id > 0 && $title !== '' && $content !== '') {
            if (!isSuperAdmin() && $user_department) {
                $department_id = $user_department;

                $stmt = $pdo->prepare("SELECT department_id FROM announcements WHERE id = ?");
                $stmt->execute([$id]);
                $announcement = $stmt->fetch();

                if (!$announcement || (int)$announcement['department_id'] !== (int)$user_department) {
                    die("You don't have permission to edit this announcement.");
                }

                $is_published = 0;
                $resetApprovalSql = ", is_approved = NULL, approved_by = NULL, approved_at = NULL";
            } else {
                $resetApprovalSql = '';
            }

            $sql = "UPDATE announcements SET title = ?, content = ?, category = ?, department_id = ?, program_id = ?, event_date = ?, event_time = ?, event_location = ?, is_published = ?" . $resetApprovalSql . ", updated_at = NOW() WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $title,
                $content,
                $category,
                $department_id,
                $program_id,
                $event_date,
                $event_time,
                $event_location,
                $is_published,
                $id
            ]);

            if (function_exists('logActivity')) {
                $details = json_encode([
                    'id' => $id,
                    'title' => $title,
                    'category' => $category,
                    'department_id' => $department_id,
                    'program_id' => $program_id,
                    'event_date' => $event_date,
                    'event_time' => $event_time,
                    'is_published' => $is_published
                ]);
                logActivity('announcement_update', $details);
            }

            header('Location: admin.php');
            exit();
        }
    } elseif (isset($_POST['delete'])) {
        $id = (int)($_POST['id'] ?? 0);

        if ($id > 0) {
            if (!isSuperAdmin() && $user_department) {
                $stmt = $pdo->prepare("SELECT department_id FROM announcements WHERE id = ?");
                $stmt->execute([$id]);
                $announcement = $stmt->fetch();

                if (!$announcement || (int)$announcement['department_id'] !== (int)$user_department) {
                    die("You don't have permission to delete this announcement.");
                }
            }

            $stmt = $pdo->prepare("DELETE FROM announcements WHERE id = ?");
            $stmt->execute([$id]);

            if (function_exists('logActivity')) {
                $details = json_encode(['id' => $id]);
                logActivity('announcement_delete', $details);
            }

            header('Location: admin.php');
            exit();
        }
    }
}

if (isSuperAdmin()) {
    $stmt = $pdo->prepare("
        SELECT a.*, u.full_name AS author_name, d.code AS department_code, d.name AS department_name,
               p.code AS program_code, p.name AS program_name
        FROM announcements a
        JOIN users u ON a.author_id = u.id
        LEFT JOIN departments d ON a.department_id = d.id
        LEFT JOIN programs p ON a.program_id = p.id
        WHERE (a.is_archived IS NULL OR a.is_archived = 0)
        ORDER BY a.created_at DESC
    ");
    $stmt->execute();
} else {
    $stmt = $pdo->prepare("
        SELECT a.*, u.full_name AS author_name, d.code AS department_code, d.name AS department_name,
               p.code AS program_code, p.name AS program_name
        FROM announcements a
        JOIN users u ON a.author_id = u.id
        LEFT JOIN departments d ON a.department_id = d.id
        LEFT JOIN programs p ON a.program_id = p.id
        WHERE (a.department_id IS NULL OR a.department_id = ? OR a.author_id = ?)
          AND (a.is_archived IS NULL OR a.is_archived = 0)
        ORDER BY a.created_at DESC
    ");
    $stmt->execute([$user_department, $_SESSION['user_id']]);
}
$announcements = $stmt->fetchAll();

$departments = isSuperAdmin() ? getDepartments() : [];
$programs = $user_department ? getProgramsByDepartment($user_department) : [];
$user_dept_info = $user_department ? getDepartment($user_department) : null;

$profile_display_name = $_SESSION['full_name'] ?? ($current_user['full_name'] ?? 'Admin User');
$profile_avatar_url = !empty($current_user['profile_picture'])
    ? $current_user['profile_picture']
    : 'https://ui-avatars.com/api/?background=832222&color=fff&name=' . urlencode($profile_display_name);
$profile_subtitle = isSuperAdmin()
    ? 'Super Admin'
    : ($user_dept_info ? $user_dept_info['name'] : 'Network Admin');

$admin_brand_label = 'UMTC';

$total_announcements = count($announcements);
$published_announcements = 0;
$pending_announcements = 0;
$my_published_announcements = 0;

foreach ($announcements as $dashboard_announcement) {
    if (!empty($dashboard_announcement['is_published'])) {
        $published_announcements++;
        if ((int)$dashboard_announcement['author_id'] === (int)$_SESSION['user_id']) {
            $my_published_announcements++;
        }
    }
    if (is_null($dashboard_announcement['is_approved'])) {
        $pending_announcements++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - UMTC Announcement System</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
    <script src="https://unpkg.com/aos@2.3.1/dist/aos.js" defer></script>
    <script src="https://cdn.jsdelivr.net/npm/feather-icons/dist/feather.min.js" defer></script>
    <link rel="stylesheet" href="includes/design-system.css">
    <script src="includes/app-init.js" defer></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap');
        body { font-family: 'Poppins', sans-serif; }
        .gradient-bg { background: linear-gradient(135deg, #7c2020ff 0%, #832222ff 100%); }
        .nav-glass { backdrop-filter: blur(12px); background-color: rgba(255, 255, 255, 0.9); }
        .card-hover { transition: transform 0.25s ease, box-shadow 0.25s ease; }
        .card-hover:hover { transform: translateY(-4px); box-shadow: 0 20px 35px -15px rgba(0, 0, 0, 0.2); }
        .stat-card { background: white; border-radius: 1rem; padding: 1.5rem; box-shadow: 0 10px 25px -12px rgba(0,0,0,0.2); display: flex; align-items: center; }
        .stat-icon { width: 3.5rem; height: 3.5rem; border-radius: 999px; display: flex; align-items: center; justify-content: center; }
        .form-input, .form-select, .form-textarea { border: 1px solid #e5e7eb; border-radius: 0.5rem; padding: 0.65rem 0.85rem; width: 100%; }
        .form-input:focus, .form-select:focus, .form-textarea:focus { outline: none; border-color: #bd3434; box-shadow: 0 0 0 3px rgba(189, 52, 52, 0.15); }
        .form-checkbox { border-radius: 0.35rem; border: 1px solid #d1d5db; }
        .announcement-pill { display: inline-flex; align-items: center; padding: 0.2rem 0.75rem; border-radius: 999px; font-size: 0.75rem; font-weight: 600; margin-right: 0.35rem; }
        .announcement-card { border: 1px solid #e5e7eb; border-radius: 1rem; padding: 1.5rem; background-color: white; box-shadow: 0 15px 30px -20px rgba(0, 0, 0, 0.3); }
        .back-to-top-btn { position: fixed; bottom: 1.5rem; right: 1.5rem; background: #832222ff; color: #fff; border: none; border-radius: 999px; padding: 0.9rem 1.1rem; box-shadow: 0 15px 35px rgba(0,0,0,0.15); display: flex; align-items: center; gap: 0.35rem; font-weight: 600; cursor: pointer; }
        .filtered-out { display: none !important; }
    </style>
</head>
<body class="bg-gray-50">
    <nav class="fixed w-full z-40 nav-glass shadow-sm">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-20">
                <div class="flex items-center">
                    <i data-feather="megaphone" class="text-red-700"></i>
                    <span class="ml-2 text-2xl font-bold text-gray-900 tracking-tight"><?php echo htmlspecialchars($admin_brand_label); ?></span>
                </div>
                <div class="flex items-center space-x-4">
                    <?php if (isSuperAdmin()): ?>
                        <a href="super_admin.php" class="hidden md:inline-flex items-center px-4 py-2 text-sm font-medium border border-red-200 rounded-lg text-red-700 hover:bg-red-50 transition">
                            <i data-feather="sliders" class="w-4 h-4 mr-2"></i>
                            Super Admin
                        </a>
                    <?php endif; ?>
                    <div class="relative">
                        <button id="admin-profile-menu-button" class="pl-2 pr-3 py-1.5 rounded-full border border-gray-200 text-gray-800 bg-white hover:bg-gray-50 transition flex items-center gap-3" aria-haspopup="true" aria-expanded="false">
                            <img src="<?php echo htmlspecialchars($profile_avatar_url); ?>" alt="Profile photo" class="w-12 h-12 rounded-full object-cover border-2 border-red-100">
                            <div class="text-left">
                                <p class="text-sm font-semibold text-gray-900 leading-tight"><?php echo htmlspecialchars($profile_display_name); ?></p>
                                <p class="text-xs text-gray-500"><?php echo htmlspecialchars($profile_subtitle); ?></p>
                            </div>
                            <i data-feather="chevron-down" class="w-4 h-4 text-gray-500"></i>
                        </button>
                        <div id="admin-profile-menu" class="hidden absolute right-0 mt-2 w-48 bg-white border border-gray-200 rounded-md shadow-lg z-50">
                            <?php if (isSuperAdmin()): ?>
                                <a href="super_admin.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">Super Admin Dashboard</a>
                                <div class="border-t border-gray-100"></div>
                            <?php endif; ?>
                            <a href="profile.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">Profile</a>
                            <a href="change_password.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">Change Password</a>
                            <a href="logout.php" class="block px-4 py-2 text-sm text-red-600 hover:bg-red-50 border-t border-gray-100" onclick="return confirm('Are you sure you want to log out?');">Logout</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </nav>

    <main class="pt-28 pb-16 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-10">
        <section class="gradient-bg text-white rounded-3xl shadow-2xl p-8 flex flex-col md:flex-row items-start md:items-center justify-between gap-8" data-aos="fade-down">
            <div>
                <p class="uppercase text-white/70 tracking-[0.3em] text-xs">UMTC Announcement System</p>
                <h1 class="text-3xl md:text-4xl font-bold mt-3">Department Admin Dashboard</h1>
                <p class="mt-4 text-white/90 max-w-2xl">
                    Manage announcements for <?php echo $user_dept_info ? htmlspecialchars($user_dept_info['name']) : 'the entire UMTC community'; ?>, collaborate with coordinators, and keep your department up to date.
                </p>
            </div>
            <div class="bg-white/20 rounded-2xl p-6 w-full md:w-auto">
                <p class="text-sm uppercase tracking-widest text-white/80">Live posts</p>
                <p class="text-5xl font-extrabold mt-2"><?php echo $published_announcements; ?></p>
                <p class="text-white/80 text-sm">Announcements currently visible to students.</p>
                <a href="#create-announcement" class="mt-6 inline-flex items-center px-5 py-3 bg-white text-red-700 rounded-full font-semibold shadow-md hover:bg-white/90 transition">
                    <i data-feather="plus" class="w-4 h-4 mr-2"></i>
                    New Announcement
                </a>
            </div>
        </section>

        <section class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6" data-aos="fade-up" data-aos-delay="50">
            <button type="button" class="stat-card" data-scroll-target="manage-announcements">
                <div class="stat-icon bg-red-100 text-red-600">
                    <i data-feather="radio"></i>
                </div>
                <div class="ml-4 text-left">
                    <p class="text-sm text-gray-500">Total Announcements</p>
                    <p class="text-3xl font-bold text-gray-900"><?php echo $total_announcements; ?></p>
                </div>
            </button>
            <button type="button" class="stat-card" data-scroll-target="manage-announcements">
                <div class="stat-icon bg-green-100 text-green-600">
                    <i data-feather="check-circle"></i>
                </div>
                <div class="ml-4 text-left">
                    <p class="text-sm text-gray-500">Published by You</p>
                    <p class="text-3xl font-bold text-gray-900"><?php echo $my_published_announcements; ?></p>
                </div>
            </button>
            <button type="button" class="stat-card" data-scroll-target="manage-announcements">
                <div class="stat-icon bg-amber-100 text-amber-600">
                    <i data-feather="clock"></i>
                </div>
                <div class="ml-4 text-left">
                    <p class="text-sm text-gray-500">Pending Approval</p>
                    <p class="text-3xl font-bold text-gray-900"><?php echo $pending_announcements; ?></p>
                </div>
            </button>
        </section>

        <section id="create-announcement" class="bg-white rounded-3xl shadow-xl overflow-hidden card-hover" data-aos="fade-up" data-aos-delay="100">
            <div class="gradient-bg px-6 py-4 text-white flex items-center justify-between flex-wrap gap-4">
                <div>
                    <h2 class="text-xl font-semibold">Create New Announcement</h2>
                    <p class="text-white/80 text-sm">Share updates directly with your students. Super admins auto-publish.</p>
                </div>
                <div class="hidden sm:flex items-center text-sm">
                    <i data-feather="zap" class="w-4 h-4 mr-2"></i>
                    Fast publishing workflow
                </div>
            </div>
            <div class="p-6">
                <form method="POST" class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <input type="hidden" name="category" value="general">
                    <div class="lg:col-span-2 space-y-6">
                        <div>
                            <label for="title" class="block text-sm font-medium text-gray-700 mb-1">Title<span class="text-red-500">*</span></label>
                            <input id="title" name="title" type="text" class="form-input" placeholder="Enter announcement title" required>
                        </div>
                        <div>
                            <label for="content" class="block text-sm font-medium text-gray-700 mb-1">Content<span class="text-red-500">*</span></label>
                            <textarea id="content" name="content" rows="6" class="form-textarea" placeholder="Write your announcement details here..." required></textarea>
                        </div>
                    </div>
                    <div class="space-y-5">
                        <?php if (isSuperAdmin()): ?>
                            <div>
                                <label for="department_id" class="block text-sm font-medium text-gray-700 mb-1">Department</label>
                                <select id="department_id" name="department_id" class="form-select">
                                    <option value="">All Departments</option>
                                    <?php foreach ($departments as $dept): ?>
                                        <option value="<?php echo $dept['id']; ?>"><?php echo htmlspecialchars($dept['code'] . ' - ' . $dept['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php else: ?>
                            <input type="hidden" name="department_id" value="<?php echo $user_department; ?>">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Department</label>
                                <p class="form-input bg-gray-50 font-semibold"><?php echo $user_dept_info ? htmlspecialchars($user_dept_info['code']) : 'General'; ?></p>
                            </div>
                        <?php endif; ?>

                        <div>
                            <label for="program_id" class="block text-sm font-medium text-gray-700 mb-1">Program</label>
                            <select id="program_id" name="program_id" class="form-select">
                                <option value="">All Programs</option>
                                <?php foreach ($programs as $program): ?>
                                    <option value="<?php echo $program['id']; ?>"><?php echo htmlspecialchars($program['code'] . ' - ' . $program['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label for="event_date" class="block text-sm font-medium text-gray-700 mb-1">Event Date</label>
                                <input id="event_date" name="event_date" type="date" class="form-input">
                            </div>
                            <div>
                                <label for="event_time" class="block text-sm font-medium text-gray-700 mb-1">Event Time</label>
                                <input id="event_time" name="event_time" type="time" class="form-input">
                            </div>
                        </div>

                        <div>
                            <label for="event_location" class="block text-sm font-medium text-gray-700 mb-1">Event Location</label>
                            <input id="event_location" name="event_location" type="text" class="form-input" placeholder="E.g., UMTC Auditorium">
                        </div>

                        <?php if (isSuperAdmin()): ?>
                            <label class="inline-flex items-center text-sm text-gray-700">
                                <input id="create_is_published" name="is_published" type="checkbox" value="1" class="form-checkbox h-4 w-4 text-red-600 mr-2" checked>
                                Visible immediately after publishing
                            </label>
                        <?php endif; ?>

                        <button type="submit" name="create" class="w-full inline-flex items-center justify-center px-4 py-3 rounded-2xl bg-gradient-to-r from-red-600 to-red-400 text-white font-semibold shadow-lg hover:opacity-95 transition">
                            <i data-feather="send" class="w-4 h-4 mr-2"></i>
                            Create Announcement
                        </button>
                    </div>
                </form>
            </div>
        </section>

        <section id="manage-announcements" class="bg-white rounded-3xl shadow-xl card-hover overflow-hidden" data-aos="fade-up" data-aos-delay="150">
            <div class="px-6 py-5 border-b border-gray-100 flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h2 class="text-2xl font-semibold text-gray-900">Announcements</h2>
                    <p class="text-gray-500 text-sm">You currently manage <?php echo $total_announcements; ?> announcements.</p>
                </div>
                <div class="flex flex-wrap items-center gap-3">
                    <label class="text-xs font-semibold text-gray-600" for="adminEventFrom">Event Date</label>
                    <input id="adminEventFrom" type="date" class="form-input w-36 text-sm" aria-label="Event date from">
                    <span class="text-xs text-gray-400">to</span>
                    <input id="adminEventTo" type="date" class="form-input w-36 text-sm" aria-label="Event date to">
                    <button id="adminEventReset" type="button" class="inline-flex items-center px-3 py-2 rounded-full border border-gray-200 text-gray-700 hover:bg-gray-50 text-sm">Clear</button>
                    <?php if (count($announcements) > 4): ?>
                        <button id="adminToggleAnnouncements" type="button" class="inline-flex items-center px-5 py-2 rounded-full border border-gray-200 text-gray-700 hover:bg-gray-50 font-semibold">
                            Show All Announcements
                        </button>
                    <?php endif; ?>
                </div>
            </div>
            <div class="p-6 space-y-6">
                <?php if (count($announcements) > 0): ?>
                    <?php foreach ($announcements as $index => $announcement): ?>
                        <?php $is_hidden_preview = $index >= 4; ?>
                        <article class="announcement-card <?php echo $is_hidden_preview ? 'hidden limited-announcement-admin' : ''; ?>" data-event-date="<?php echo htmlspecialchars($announcement['event_date']); ?>" data-aos="fade-up" data-aos-delay="<?php echo ($index % 4) * 50; ?>">
                            <div class="flex flex-wrap items-start justify-between gap-4">
                                <div>
                                    <div class="mb-2">
                                        <span class="announcement-pill <?php echo $announcement['is_published'] ? 'bg-green-100 text-green-700' : 'bg-yellow-100 text-yellow-700'; ?>">
                                            <?php echo $announcement['is_published'] ? 'Published' : 'Hidden'; ?>
                                        </span>
                                        <?php if ($announcement['is_approved'] === null): ?>
                                            <span class="announcement-pill bg-amber-100 text-amber-700">Pending Approval</span>
                                        <?php elseif ((int)$announcement['is_approved'] === 0): ?>
                                            <span class="announcement-pill bg-red-100 text-red-700">Rejected</span>
                                        <?php endif; ?>
                                        <?php if (!isSuperAdmin() && $announcement['department_id'] != $user_department): ?>
                                            <span class="announcement-pill bg-blue-100 text-blue-700">Other Department</span>
                                        <?php endif; ?>
                                    </div>
                                    <h3 class="text-xl font-semibold text-gray-900"><?php echo htmlspecialchars($announcement['title']); ?></h3>
                                    <p class="text-gray-600 mt-2"><?php echo strlen($announcement['content']) > 200 ? substr(htmlspecialchars($announcement['content']), 0, 200) . '…' : htmlspecialchars($announcement['content']); ?></p>
                                    <div class="flex flex-wrap gap-4 text-sm text-gray-500 mt-4">
                                        <span class="flex items-center">
                                            <i data-feather="user" class="w-4 h-4 mr-1"></i>
                                            <?php echo htmlspecialchars($announcement['author_name']); ?>
                                        </span>
                                        <span class="flex items-center">
                                            <i data-feather="users" class="w-4 h-4 mr-1"></i>
                                            <?php echo !empty($announcement['department_code']) ? htmlspecialchars($announcement['department_code']) : 'All Departments'; ?>
                                        </span>
                                        <?php if ($announcement['event_date']): ?>
                                            <span class="flex items-center">
                                                <i data-feather="calendar" class="w-4 h-4 mr-1"></i>
                                                <?php echo date('M j, Y', strtotime($announcement['event_date'])); ?>
                                                <?php if ($announcement['event_time']): ?>
                                                    &nbsp;<?php echo date('g:i A', strtotime($announcement['event_time'])); ?>
                                                <?php endif; ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="flex items-center gap-3">
                                    <?php if (isSuperAdmin() || $announcement['department_id'] == $user_department): ?>
                                        <button type="button" onclick="editAnnouncement(<?php echo $announcement['id']; ?>)" class="inline-flex items-center px-4 py-2 rounded-full border border-gray-200 text-gray-700 hover:bg-gray-50">
                                            <i data-feather="edit-2" class="w-4 h-4 mr-2"></i>
                                            Edit
                                        </button>
                                        <form method="POST" onsubmit="return confirm('Delete this announcement?');">
                                            <input type="hidden" name="id" value="<?php echo $announcement['id']; ?>">
                                            <button type="submit" name="delete" class="inline-flex items-center px-4 py-2 rounded-full border border-red-200 text-red-600 hover:bg-red-50">
                                                <i data-feather="trash-2" class="w-4 h-4 mr-2"></i>
                                                Delete
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <span class="text-xs text-gray-400">View Only</span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <?php if (isSuperAdmin() || $announcement['department_id'] == $user_department): ?>
                                <div id="edit-form-<?php echo $announcement['id']; ?>" class="hidden mt-6 border-top border-gray-100 pt-6">
                                    <form method="POST" class="grid grid-cols-1 gap-4">
                                        <input type="hidden" name="id" value="<?php echo $announcement['id']; ?>">
                                        <input type="hidden" name="category" value="<?php echo htmlspecialchars($announcement['category']); ?>">
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 mb-1">Title</label>
                                            <input type="text" name="title" value="<?php echo htmlspecialchars($announcement['title']); ?>" class="form-input" required>
                                        </div>
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 mb-1">Content</label>
                                            <textarea name="content" rows="4" class="form-textarea" required><?php echo htmlspecialchars($announcement['content']); ?></textarea>
                                        </div>
                                        <?php if (isSuperAdmin()): ?>
                                            <div>
                                                <label class="block text-sm font-medium text-gray-700 mb-1">Department</label>
                                                <select name="department_id" class="form-select">
                                                    <option value="">All Departments</option>
                                                    <?php foreach ($departments as $dept): ?>
                                                        <option value="<?php echo $dept['id']; ?>" <?php echo $announcement['department_id'] == $dept['id'] ? 'selected' : ''; ?>>
                                                            <?php echo htmlspecialchars($dept['code'] . ' - ' . $dept['name']); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        <?php else: ?>
                                            <input type="hidden" name="department_id" value="<?php echo $user_department; ?>">
                                        <?php endif; ?>
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 mb-1">Program</label>
                                            <select name="program_id" class="form-select">
                                                <option value="">All Programs</option>
                                                <?php
                                                $announcement_programs = [];
                                                if ($announcement['department_id']) {
                                                    $announcement_programs = getProgramsByDepartment($announcement['department_id']);
                                                }
                                                ?>
                                                <?php foreach ($announcement_programs as $program): ?>
                                                    <option value="<?php echo $program['id']; ?>" <?php echo $announcement['program_id'] == $program['id'] ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($program['code'] . ' - ' . $program['name']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                                            <div>
                                                <label class="block text-sm font-medium text-gray-700 mb-1">Event Date</label>
                                                <input type="date" name="event_date" value="<?php echo $announcement['event_date']; ?>" class="form-input">
                                            </div>
                                            <div>
                                                <label class="block text-sm font-medium text-gray-700 mb-1">Event Time</label>
                                                <input type="time" name="event_time" value="<?php echo $announcement['event_time']; ?>" class="form-input">
                                            </div>
                                            <div>
                                                <label class="block text-sm font-medium text-gray-700 mb-1">Location</label>
                                                <input type="text" name="event_location" value="<?php echo htmlspecialchars($announcement['event_location']); ?>" class="form-input">
                                            </div>
                                        </div>
                                        <label class="inline-flex items-center text-sm text-gray-700">
                                            <input name="is_published" type="checkbox" value="1" class="form-checkbox h-4 w-4 text-red-600 mr-2" <?php echo $announcement['is_published'] ? 'checked' : ''; ?>>
                                            Visible to students
                                        </label>
                                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                            <button type="submit" name="update" class="inline-flex items-center justify-center px-4 py-2 rounded-2xl bg-red-600 text-white font-semibold hover:bg-red-500 transition">
                                                Save Changes
                                            </button>
                                            <button type="button" onclick="cancelEdit(<?php echo $announcement['id']; ?>)" class="inline-flex items-center justify-center px-4 py-2 rounded-2xl border border-gray-300 text-gray-700 hover:bg-gray-50">
                                                Cancel
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="text-center py-16">
                        <i data-feather="package" class="w-12 h-12 text-gray-300 mx-auto"></i>
                        <p class="mt-4 text-gray-500">No announcements yet. Be the first to create one!</p>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    </main>

    <?php if (count($announcements) > 4): ?>
        <button id="adminBackToTop" type="button" class="back-to-top-btn hidden" aria-label="Back to top">
            <i data-feather="arrow-up" class="w-4 h-4"></i>
            <span>Top</span>
        </button>
    <?php endif; ?>

    <script>
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

        setupProfileDropdown('admin-profile-menu-button', 'admin-profile-menu');

        document.querySelectorAll('[data-scroll-target]').forEach(function(button) {
            button.addEventListener('click', function() {
                const targetId = this.getAttribute('data-scroll-target');
                const target = document.getElementById(targetId);
                if (target) {
                    const offset = target.offsetTop - 60;
                    window.scrollTo({ top: offset, behavior: 'smooth' });
                }
            });
        });

        function editAnnouncement(id) {
            document.querySelectorAll('[id^="edit-form-"]').forEach(form => form.classList.add('hidden'));
            const editForm = document.getElementById('edit-form-' + id);
            if (editForm) {
                editForm.classList.remove('hidden');
                editForm.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }

        function cancelEdit(id) {
            const editForm = document.getElementById('edit-form-' + id);
            if (editForm) {
                editForm.classList.add('hidden');
            }
        }

        <?php if (isSuperAdmin()): ?>
        document.getElementById('department_id').addEventListener('change', function() {
            const programSelect = document.getElementById('program_id');
            const departmentId = this.value;

            if (departmentId) {
                fetch('get_programs.php?department_id=' + departmentId)
                    .then(response => response.json())
                    .then(programs => {
                        programSelect.innerHTML = '<option value="">All Programs</option>';
                        programs.forEach(program => {
                            programSelect.innerHTML += `<option value="${program.id}">${program.code} - ${program.name}</option>`;
                        });
                    })
                    .catch(error => console.error('Error loading programs:', error));
            } else {
                programSelect.innerHTML = '<option value="">All Programs</option>';
            }
        });
        <?php endif; ?>

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

        const adminShowAllBtn = document.getElementById('adminToggleAnnouncements');
        const adminBackToTopBtn = document.getElementById('adminBackToTop');
        const adminMainSection = document.querySelector('main');
        const adminAnnouncements = Array.from(document.querySelectorAll('.announcement-card'));
        let adminAnnouncementsExpanded = false;

        const syncAdminAnnouncementVisibility = () => {
            if (!adminAnnouncements.length) return;

            let visibleCount = 0;
            adminAnnouncements.forEach((card) => {
                if (card.classList.contains('filtered-out')) {
                    card.classList.add('hidden');
                    return;
                }

                if (!adminAnnouncementsExpanded && visibleCount >= 4) {
                    card.classList.add('hidden');
                } else {
                    card.classList.remove('hidden');
                }

                visibleCount++;
            });

            if (adminShowAllBtn) {
                adminShowAllBtn.textContent = adminAnnouncementsExpanded ? 'Show Less' : 'Show All Announcements';
                adminShowAllBtn.setAttribute('aria-label', adminAnnouncementsExpanded ? 'Show fewer announcements' : 'Show all announcements');
            }
        };

        initEventDateFilter('.announcement-card', 'adminEventFrom', 'adminEventTo', 'adminEventReset', syncAdminAnnouncementVisibility);

    syncAdminAnnouncementVisibility();

        const refreshAdminBackToTop = () => {
            if (!adminBackToTopBtn) return;
            if (!adminAnnouncementsExpanded || window.scrollY < 300) {
                adminBackToTopBtn.classList.add('hidden');
            } else {
                adminBackToTopBtn.classList.remove('hidden');
            }
        };

        if (adminShowAllBtn) {
            adminShowAllBtn.addEventListener('click', () => {
                adminAnnouncementsExpanded = !adminAnnouncementsExpanded;
                syncAdminAnnouncementVisibility();
                if (!adminAnnouncementsExpanded && adminMainSection) {
                    window.scrollTo({ top: adminMainSection.offsetTop - 60, behavior: 'smooth' });
                }
                refreshAdminBackToTop();
            });
        }

        if (adminBackToTopBtn) {
            adminBackToTopBtn.addEventListener('click', () => {
                const offset = adminMainSection ? adminMainSection.offsetTop - 40 : 0;
                window.scrollTo({ top: offset, behavior: 'smooth' });
            });
            window.addEventListener('scroll', refreshAdminBackToTop);
        }

        window.addEventListener('load', function() {
            if (window.AOS) {
                AOS.init({ once: true, duration: 700, easing: 'ease-out-cubic' });
            }
        });
    </script>
</body>
</html>
