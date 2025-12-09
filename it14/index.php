<?php
// index.php - Public announcement dashboard with different dashboard views
require_once 'config.php';

// Get filter parameters
$department_id = isset($_GET['department_id']) ? $_GET['department_id'] : null;
$program_id = isset($_GET['program_id']) ? $_GET['program_id'] : null;
$event_date_from = normalizeDateInput($_GET['event_date_from'] ?? null);
$event_date_to = normalizeDateInput($_GET['event_date_to'] ?? null);

// Get active dashboard from URL or default to calendar view
$active_dashboard = isset($_GET['dashboard']) ? $_GET['dashboard'] : 'calendar';
if ($active_dashboard === 'home') {
    $active_dashboard = 'calendar';
}

// Build query based on filters - FIXED: Show ALL announcements regardless of date
$query = "
    SELECT a.*, u.full_name as author_name, d.code as department_code, d.name as department_name, p.code as program_code, p.name as program_name
    FROM announcements a 
    JOIN users u ON a.author_id = u.id 
    LEFT JOIN departments d ON a.department_id = d.id
    LEFT JOIN programs p ON a.program_id = p.id
    WHERE a.is_published = TRUE AND a.is_approved = 1 AND (a.is_archived IS NULL OR a.is_archived = 0)
";

$params = [];

if (!empty($department_id)) {
    $query .= " AND a.department_id = ?";
    $params[] = $department_id;
}

if (!empty($program_id)) {
    $query .= " AND a.program_id = ?";
    $params[] = $program_id;
}

// Add event date range filters
if (!empty($event_date_from)) {
    $query .= " AND a.event_date >= ?";
    $params[] = $event_date_from;
}

if (!empty($event_date_to)) {
    $query .= " AND a.event_date <= ?";
    $params[] = $event_date_to;
}

// Order by nearest event date first (future events before past events)
$query .= " ORDER BY 
    CASE 
        WHEN a.event_date IS NULL THEN 1
        WHEN a.event_date >= CURDATE() THEN 0
        ELSE 2
    END,
    ABS(DATEDIFF(COALESCE(a.event_date, CURDATE()), CURDATE())),
    a.created_at DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$announcements = $stmt->fetchAll();

// Get events for calendar (include all events for calendar view)
$events_query = "
    SELECT id, title, event_date, event_time, event_location, department_id
    FROM announcements 
    WHERE is_published = TRUE AND is_approved = 1 AND event_date IS NOT NULL AND (is_archived IS NULL OR is_archived = 0)
";

$events_params = [];

if (!empty($department_id)) {
    $events_query .= " AND department_id = ?";
    $events_params[] = $department_id;
}

if (!empty($program_id)) {
    $events_query .= " AND program_id = ?";
    $events_params[] = $program_id;
}

$events_query .= " ORDER BY event_date, event_time LIMIT 10";

$stmt = $pdo->prepare($events_query);
$stmt->execute($events_params);
$events = $stmt->fetchAll();

// Get UPCOMING events only (for upcoming events sections) - EXCLUDE PAST EVENTS
$upcoming_events_query = "
    SELECT id, title, event_date, event_time, event_location, department_id
    FROM announcements 
    WHERE is_published = TRUE AND is_approved = 1 AND event_date IS NOT NULL AND (is_archived IS NULL OR is_archived = 0)
    AND (event_date > CURDATE() OR (event_date = CURDATE() AND event_time > CURTIME()))
";

$upcoming_params = [];

if (!empty($department_id)) {
    $upcoming_events_query .= " AND department_id = ?";
    $upcoming_params[] = $department_id;
}

if (!empty($program_id)) {
    $upcoming_events_query .= " AND program_id = ?";
    $upcoming_params[] = $program_id;
}

$upcoming_events_query .= " ORDER BY event_date, event_time LIMIT 3";

$stmt = $pdo->prepare($upcoming_events_query);
$stmt->execute($upcoming_params);
$upcoming_events = $stmt->fetchAll();

// Get all departments for filter dropdown
$departments = getDepartments();

// Get programs for selected department
$programs = [];
if (!empty($department_id)) {
    $programs = getProgramsByDepartment($department_id);
}
$all_programs = getAllPrograms();

// Get stats for home dashboard
$total_announcements = $pdo->query("SELECT COUNT(*) FROM announcements WHERE is_published = TRUE AND is_approved = 1 AND (is_archived IS NULL OR is_archived = 0)")->fetchColumn();
$total_departments = $pdo->query("SELECT COUNT(*) FROM departments")->fetchColumn();
$upcoming_events_count = $pdo->query("SELECT COUNT(*) FROM announcements WHERE is_published = TRUE AND is_approved = 1 AND (is_archived IS NULL OR is_archived = 0) AND (event_date > CURDATE() OR (event_date = CURDATE() AND event_time > CURTIME()))")->fetchColumn();

// Handle event detail view
$event_detail = null;
if (isset($_GET['event_id'])) {
    $stmt = $pdo->prepare("
        SELECT a.*, u.full_name as author_name, d.code as department_code, d.name as department_name, p.code as program_code, p.name as program_name
        FROM announcements a 
        JOIN users u ON a.author_id = u.id 
        LEFT JOIN departments d ON a.department_id = d.id
        LEFT JOIN programs p ON a.program_id = p.id
    WHERE a.id = ? AND a.is_published = TRUE AND a.is_approved = 1
    ");
    $stmt->execute([$_GET['event_id']]);
    $event_detail = $stmt->fetch();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GAlendar - College Announcements</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
    <script src="https://unpkg.com/aos@2.3.1/dist/aos.js" defer></script>
    <script src="https://cdn.jsdelivr.net/npm/feather-icons/dist/feather.min.js" defer></script>
    <link rel="stylesheet" href="includes/design-system.css">
    <script src="includes/app-init.js" defer></script>
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.css" rel="stylesheet">
    <style>
        :root {
            --primary: #e64f4fff;
            --primary-light: #832222ff;
            --primary-dark: #000000ff;
            --secondary: #10b981;
            --dark: #741f1fff;
            --light: #f8fafc;
        }
        
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f1f5f9;
            color: #000000ff;
        }
        
        .gradient-bg {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
        }
        
        .announcement-card {
            transition: all 0.3s ease;
            border-left: 4px solid var(--primary);
        }
        
        .announcement-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
        }
        
        .event-card {
            border-left: 4px solid var(--secondary);
        }
        
        .stat-card {
            background: white;
            transition: all 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
        }
        .back-to-top-btn {
            position: fixed;
            bottom: 1.5rem;
            right: 1.5rem;
            background: var(--primary);
            color: #fff;
            border: none;
            border-radius: 999px;
            padding: 0.85rem 1rem;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.15);
            cursor: pointer;
            z-index: 60;
            display: flex;
            align-items: center;
            gap: 0.35rem;
            font-weight: 600;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .back-to-top-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.18);
        }
        
        .nav-tabs .nav-link {
            color: #64748b;
            border: none;
            padding: 1rem;
            font-weight: 500;
        }
        
        .nav-tabs .nav-link.active {
            color: var(--primary);
            border-bottom: 3px solid var(--primary);
            background: transparent;
        }
        
        .fc .fc-button-primary {
            background-color: var(--primary);
            border-color: var(--primary);
        }
        
        .fc .fc-button-primary:hover {
            background-color: var(--primary-dark);
            border-color: var(--primary-dark);
        }
        
        .fc-event {
            background-color: var(--primary);
            border-color: var(--primary);
        }
        
        /* Dashboard sections */
        .dashboard-section {
            display: none;
        }
        
        .dashboard-section.active {
            display: block;
        }
        
        
        /* Mobile menu */
        #mobile-menu {
            display: none;
        }
        
        #mobile-menu.show {
            display: block;

        }
        @media only screen and (min-width: 761px) 
        {
            #mobile-menu.show {
            display: none;
        
        }
        }
        /* Loading spinner */
        .spinner {
            border: 2px solid #f3f3f3;
            border-top: 2px solid #721010ff;
            border-radius: 50%;
            width: 16px;
            height: 16px;
            animation: spin 1s linear infinite;
            display: inline-block;
            margin-left: 5px;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        /* Auto-apply form */
        .auto-apply-form .form-select {
            cursor: pointer;
        }
        
        .filter-section {
            margin-bottom: 1rem;
        }
        
        .past-event {
            opacity: 0.7;
            background-color: #f9fafb;
        }
        
        .past-event:hover {
            opacity: 0.9;
        }
        
        .date-range-inputs {
            display: flex;
            gap: 0.5rem;
        }
        
        .date-range-inputs input {
            flex: 1;
        }
        
        .no-event-date {
            border-left: 4px solid #9ca3af;
        }

        /* Calendar redesign */
        .calendar-shell {
            background: #ffffff;
            border-radius: 1.5rem;
            box-shadow: 0 12px 30px rgba(124, 32, 32, 0.12);
            overflow: hidden;
        }

        .calendar-header {
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            gap: 1rem;
            padding: 1.5rem;
            border-bottom: 1px solid #f2f2f7;
        }

        .calendar-heading {
            font-size: 1.125rem;
            font-weight: 600;
            color: #541414;
        }

        .calendar-view-buttons {
            display: inline-flex;
            border: 1px solid rgba(124, 32, 32, 0.2);
            border-radius: 999px;
            overflow: hidden;
            background: #fdf5f5;
        }

        .calendar-view-buttons button {
            font-size: 0.85rem;
            padding: 0.45rem 1.15rem;
            border: none;
            background: transparent;
            cursor: pointer;
            font-weight: 600;
            color: #8b4b4b;
            transition: background 0.2s, color 0.2s;
        }

        .calendar-view-buttons button.is-active {
            background: #7c2020;
            color: #fff;
            box-shadow: 0 6px 16px rgba(124, 32, 32, 0.25);
        }

        .calendar-nav {
            display: flex;
            align-items: center;
            
            gap: 0.75rem;
        }

        .calendar-nav button {
            width: 36px;
            height: 36px;
            border-radius: 0.5rem;
            border: 1px solid rgba(124, 32, 32, 0.2);
            background: #fff;
            color: #7c2020;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s;
        }

        .calendar-nav button:hover {
            background: #e44c4cff;
        }

        .calendar-month-group {
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        .calendar-month-label {
            font-weight: 700;
            font-size: 1rem;
            color: #3a1212;
            min-width: 140px;
            text-align: center;
        }

        .calendar-today-btn {
            margin-top: 0.5rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0.55rem 1.5rem;
            font-size: 0.85rem;
            font-weight: 600;
            border: 1px solid rgba(124, 32, 32, 0.4);
            color: #7c2020;
            background: #fff5f3;
            transition: background 0.2s ease, color 0.2s ease;
        }

        .calendar-today-btn:hover {
            background: #7c2020;
            color: #ffffff;
        }

        .fc {
            font-family: 'Inter', sans-serif;
        }

        .fc .fc-daygrid-day-number {
            font-weight: 600;
            color: #4a1c1c;
        }

        .fc .fc-col-header-cell {
            background: #7c2020;
            color: #fff;
            border: none;
        }

        .fc .fc-col-header-cell-cushion {
            padding: 0.85rem 0;
            font-weight: 600;
            letter-spacing: 0.05em;
            text-transform: uppercase;
        }

        .fc .fc-daygrid-day {
            border-color: #f1edf9;
        }

        .fc .fc-daygrid-day-frame {
            padding: 0.25rem 0.5rem 2rem;
        }

        .fc .fc-daygrid-event {
            background: linear-gradient(90deg, #7c2020, #5d1818);
            border: none;
            border-radius: 999px;
            font-size: 0.78rem;
            font-weight: 600;
            padding: 0.2rem 0.8rem;
            color: #fff;
        }

        .fc .fc-daygrid-event.pink-event {
            background: linear-gradient(90deg, #b83232, #ee6c6c);
        }

        .fc .fc-daygrid-block-event .fc-event-time,
        .fc .fc-daygrid-block-event .fc-event-title {
            display: inline;
        }

        .fc .fc-daygrid-day-top {
            justify-content: flex-start;
            padding-top: 0.5rem;
        }

        .fc .fc-day-today {
            background: #fff7f5;
        }

        .event-pill {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.85rem 1.1rem;
            border-radius: 1rem;
            background: #fdf5f5;
            border: 1px solid rgba(124, 32, 32, 0.1);
            min-width: 220px;
        }

        .event-pill .icon-wrap {
            width: 36px;
            height: 36px;
            border-radius: 999px;
            background: rgba(124, 32, 32, 0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #7c2020;
        }

        .event-pill .pill-label {
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            color: #b04444;
        }

        .event-pill .pill-value {
            font-size: 0.9rem;
            font-weight: 600;
            color: #3c1c1c;
        }
    </style>
</head>
<body>
    <!-- Navigation -->

<nav class="gradient-bg text-white shadow-lg" role="navigation" aria-label="Main">
    <div class="container mx-auto px-4 py-4">
        <div class="flex justify-between items-center">
            <a href="index.php" class="flex items-center space-x-2 text-2xl font-bold">
                <i data-feather="calendar"></i>
                <span>GAlendar</span>
            </a>
            <div class="hidden md:flex items-center space-x-6">
                <a href="index.php?dashboard=calendar" class="text-white hover:text-gray-200 transition <?php echo $active_dashboard === 'calendar' ? 'font-bold border-b-2 border-white' : ''; ?>">Home</a>
                <a href="index.php?dashboard=announcements" class="text-white hover:text-gray-200 transition <?php echo $active_dashboard === 'announcements' ? 'font-bold border-b-2 border-white' : ''; ?>">Announcements</a>
                <?php if (isLoggedIn()): ?>
                    <?php $adminTarget = isSuperAdmin() ? 'super_admin.php' : 'admin.php'; ?>
                    <a href="<?php echo $adminTarget; ?>" class="border border-white/70 text-white px-4 py-2 rounded-lg font-medium hover:bg-white hover:text-red-900 transition flex items-center space-x-2">
                        <i data-feather="lock" class="w-4 h-4"></i>
                        <span>Admin Panel</span>
                    </a>
                <?php else: ?>
                    <a href="login.php" class="border border-white/70 text-white px-4 py-2 rounded-lg font-medium hover:bg-white hover:text-red-900 transition flex items-center space-x-2" title="Admin Login">
                        <i data-feather="lock" class="w-4 h-4"></i>
                        <span>Admin Login</span>
                    </a>
                <?php endif; ?>
            </div>
            
            <!-- Mobile: Name + Menu Button -->
            <div class="flex md:hidden items-center space-x-4">
                <?php if (isLoggedIn()): ?>
                    <span class="text-sm bg-white/20 px-3 py-1 rounded-lg"><?php echo $_SESSION['full_name']; ?></span>
                <?php endif; ?>
                <button class="text-white focus:outline-none" id="mobile-menu-button" data-toggle="mobile-menu" aria-controls="mobile-menu" aria-expanded="false" aria-label="Toggle menu">
                    <i data-feather="menu"></i>
                </button>
            </div>
        </div>
    </div>
</nav>

   <!-- Mobile Menu -->
<div class="md:hidden hidden bg-white shadow-lg" id="mobile-menu">
    <div class="container mx-auto px-2 py-2">
        <div class="flex flex-row items-center justify-between">
            <!-- Left side: Navigation links -->
            <div class="flex flex-row items-center space-x-2">
                <a href="index.php?dashboard=calendar" class="py-2 px-3 hover:bg-gray-100 rounded transition text-sm whitespace-nowrap <?php echo $active_dashboard === 'calendar' ? 'bg-red-200 font-medium' : ''; ?>">Home</a>
                <a href="index.php?dashboard=announcements" class="py-2 px-3 hover:bg-gray-100 rounded transition text-sm whitespace-nowrap <?php echo $active_dashboard === 'announcements' ? 'bg-red-200 font-medium' : ''; ?>">Announcements</a>
            </div>

            <!-- Right side: Admin action -->
            <div class="flex flex-row items-center">
                <?php if (isLoggedIn()): ?>
                    <?php $adminTarget = isSuperAdmin() ? 'super_admin.php' : 'admin.php'; ?>
                    <a href="<?php echo $adminTarget; ?>" class="py-2 px-3 bg-gray-700 text-white rounded font-medium hover:bg-gray-800 transition text-sm whitespace-nowrap flex items-center space-x-1">
                        <i data-feather="lock" class="w-3 h-3"></i>
                        <span>Admin Panel</span>
                    </a>
                <?php else: ?>
                    <a href="login.php" class="py-2 px-3 bg-gray-700 text-white rounded font-medium hover:bg-gray-800 transition text-sm whitespace-nowrap flex items-center space-x-1">
                        <i data-feather="lock" class="w-3 h-3"></i>
                        <span>Admin Login</span>
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

    <!-- Main Content -->
    <main class="container mx-auto px-4 py-8">
        <!-- Announcements Dashboard -->
        <div id="announcements-dashboard" class="dashboard-section <?php echo $active_dashboard === 'announcements' ? 'active' : ''; ?>">
            <div class="grid grid-cols-1 lg:grid-cols-4 gap-8">
                <!-- Filters -->
                <div class="lg:col-span-1">
                    <div class="bg-white rounded-xl shadow-sm overflow-hidden">
                        <div class="p-6 border-b border-gray-200">
                            <h2 class="text-xl font-semibold text-gray-800">Filter Announcements</h2>
                        </div>
                        <div class="p-6 space-y-4">
                            <!-- Event Date Range Filter -->
                            <div class="filter-section">
                                <label class="block text-sm font-medium text-gray-700 mb-2">Event Date Range</label>
                                <div class="space-y-2">
                                    <div>
                                        <label for="event_date_from" class="block text-xs text-gray-500 mb-1">From Event Date</label>
                                        <input type="date" id="event_date_from" name="event_date_from" value="<?php echo htmlspecialchars($event_date_from); ?>" 
                                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-500">
                                    </div>
                                    <div>
                                        <label for="event_date_to" class="block text-xs text-gray-500 mb-1">To Event Date</label>
                                        <input type="date" id="event_date_to" name="event_date_to" value="<?php echo htmlspecialchars($event_date_to); ?>" 
                                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-500">
                                    </div>
                                </div>
                            </div>

                            <div class="filter-section">
                                <label for="department_id_filter" class="block text-sm font-medium text-gray-700 mb-1">Department</label>
                                <select id="department_id_filter" name="department_id" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-800">
                                    <option value="">All Departments</option>
                                    <?php foreach ($departments as $dept): ?>
                                        <option value="<?php echo $dept['id']; ?>" <?php echo $department_id == $dept['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($dept['code'] . ' - ' . $dept['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="filter-section">
                                <label for="program_id_filter" class="block text-sm font-medium text-gray-700 mb-1">Program</label>
                                <select id="program_id_filter" name="program_id" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-500" <?php echo empty($department_id) ? 'disabled' : ''; ?>>
                                    <option value="">All Programs</option>
                                    <?php if (!empty($department_id)): ?>
                                        <?php foreach ($programs as $program): ?>
                                            <option value="<?php echo $program['id']; ?>" <?php echo $program_id == $program['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($program['code'] . ' - ' . $program['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </select>
                            </div>
                            <div class="pt-2 space-y-2">
                                <button type="button" onclick="applyEventDateFilters()" class="w-full text-center bg-red-600 text-white py-2 rounded-lg hover:bg-red-700 transition font-medium">
                                    Apply Filters
                                </button>
                                <a href="index.php?dashboard=announcements" class="w-full text-center border border-gray-300 text-gray-700 py-2 rounded-lg hover:bg-gray-50 transition block">
                                    Clear All Filters
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mt-6 lg:mt-0">
                    <div class="bg-white rounded-xl shadow-sm overflow-hidden">
                        <div class="p-6 border-b border-gray-200">
                            <h2 class="text-xl font-semibold text-gray-800">Subscribe to Course Alerts</h2>
                            <p class="text-sm text-gray-500 mt-1">Get emails when new announcements are posted for a course.</p>
                        </div>
                        <div class="p-6 space-y-4">
                            <div>
                                <label for="subscribe_email" class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                                <input type="email" id="subscribe_email" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-500" placeholder="you@example.com">
                            </div>
                            <div>
                                <label for="subscribe_program" class="block text-sm font-medium text-gray-700 mb-1">Course</label>
                                <select id="subscribe_program" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-500">
                                    <option value="">Select a course</option>
                                    <?php foreach ($all_programs as $program): ?>
                                        <option value="<?php echo $program['id']; ?>">
                                            <?php echo htmlspecialchars(($program['department_code'] ? $program['department_code'] . ' · ' : '') . ($program['code'] ?? '') . ' - ' . $program['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <button type="button" id="subscribe_button" class="w-full text-center bg-red-600 text-white py-2 rounded-lg hover:bg-red-700 transition font-medium">Subscribe</button>
                            <p id="subscribe_message" class="text-sm"></p>
                        </div>
                    </div>
                </div>
                
                <!-- Announcements List -->
                <div class="lg:col-span-3">
                    <div class="flex justify-between items-center mb-6">
                        <h1 class="text-2xl font-bold text-gray-800">All Announcements</h1>
                        <div class="flex items-center space-x-2">
                            <span class="text-sm text-gray-500"><?php echo count($announcements); ?> results</span>
                            <?php if ($event_date_from || $event_date_to): ?>
                                <span class="text-xs bg-red-100 text-red-800 px-2 py-1 rounded">
                                    <?php if ($event_date_from && $event_date_to): ?>
                                        Event Date: <?php echo date('M j, Y', strtotime($event_date_from)); ?> - <?php echo date('M j, Y', strtotime($event_date_to)); ?>
                                    <?php elseif ($event_date_from): ?>
                                        Events From: <?php echo date('M j, Y', strtotime($event_date_from)); ?>
                                    <?php elseif ($event_date_to): ?>
                                        Events Until: <?php echo date('M j, Y', strtotime($event_date_to)); ?>
                                    <?php endif; ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="space-y-4">
                        <?php if (count($announcements) > 0): ?>
                            <?php foreach ($announcements as $index => $announcement): ?>
                            <?php 
                            $is_past_event = $announcement['event_date'] && strtotime($announcement['event_date']) < strtotime('today');
                            $has_event_date = !empty($announcement['event_date']);
                            $is_hidden_preview = $index >= 4;
                            ?>
                            <div class="announcement-card bg-white rounded-xl shadow-sm overflow-hidden <?php echo $is_past_event ? 'past-event' : ''; ?> <?php echo !$has_event_date ? 'no-event-date' : ''; ?> <?php echo $is_hidden_preview ? 'hidden limited-announcement' : ''; ?>">
                                <div class="p-6">
                                    <div class="flex justify-between items-start mb-3">
                                        <span class="px-2 py-1 text-xs font-medium bg-red-100 text-red-800 rounded"><?php echo ucfirst($announcement['category']); ?></span>
                                        <div class="flex items-center space-x-2">
                                            <?php if ($has_event_date): ?>
                                                <span class="text-sm text-gray-500">
                                                    <i data-feather="calendar" class="w-4 h-4 inline mr-1"></i> 
                                                    Event: <?php echo date('M j, Y', strtotime($announcement['event_date'])); ?>
                                                    <?php if ($announcement['event_time']): ?>
                                                        at <?php echo date('g:i A', strtotime($announcement['event_time'])); ?>
                                                    <?php endif; ?>
                                                    <?php if ($is_past_event): ?>
                                                        <span class="text-xs text-red-500">(Past)</span>
                                                    <?php endif; ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-sm text-gray-500">
                                                    <i data-feather="info" class="w-4 h-4 inline mr-1"></i> 
                                                    No Event Date
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <h2 class="text-xl font-semibold text-gray-800 mb-2"><?php echo htmlspecialchars($announcement['title']); ?></h2>
                                    <p class="text-gray-600 mb-4">
                                        <?php echo strlen($announcement['content']) > 150 ? substr(htmlspecialchars($announcement['content']), 0, 150) . '...' : htmlspecialchars($announcement['content']); ?>
                                    </p>
                                    <div class="flex justify-between items-center">
                                        <div class="flex items-center space-x-2">
                                            <?php if (!empty($announcement['department_code'])): ?>
                                                <span class="px-2 py-1 text-xs bg-gray-100 text-gray-800 rounded"><?php echo $announcement['department_code']; ?></span>
                                            <?php endif; ?>
                                            <?php if (!empty($announcement['program_code'])): ?>
                                                <span class="px-2 py-1 text-xs bg-gray-100 text-gray-800 rounded"><?php echo $announcement['program_code']; ?></span>
                                            <?php endif; ?>
                                            <span class="text-sm text-gray-500">
                                                Posted by <?php echo htmlspecialchars($announcement['author_name']); ?>
                                                on <?php echo date('M j, Y', strtotime($announcement['created_at'])); ?>
                                            </span>
                                        </div>
                                        <?php if ($has_event_date): ?>
                                            <a href="index.php?event_id=<?php echo $announcement['id']; ?>" class="text-red-600 hover:text-red-800 font-medium flex items-center">
                                                <span>Event Details</span>
                                                <i data-feather="chevron-right" class="w-4 h-4 ml-1"></i>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="bg-white rounded-xl shadow-sm p-6 text-center">
                                <i data-feather="inbox" class="h-12 w-12 text-gray-400 mx-auto mb-4"></i>
                                <h3 class="text-lg font-medium text-gray-900 mb-2">No announcements found</h3>
                                <p class="text-gray-500">
                                    <?php if ($event_date_from || $event_date_to || $department_id || $program_id): ?>
                                        Try adjusting your filters or clear them to see all announcements.
                                    <?php else: ?>
                                        Check back later for new announcements.
                                    <?php endif; ?>
                                </p>
                                <?php if ($event_date_from || $event_date_to || $department_id || $program_id): ?>
                                    <a href="index.php?dashboard=announcements" class="inline-block mt-4 px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition">
                                        Clear All Filters
                                    </a>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php if (count($announcements) > 4): ?>
                    <div class="text-center mt-6">
                        <button id="showAllAnnouncements" type="button" class="inline-flex items-center px-6 py-3 bg-red-600 text-white rounded-full font-semibold hover:bg-red-700 transition" aria-label="Show all announcements">
                            Show All Announcements
                        </button>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Calendar Dashboard -->
        <div id="calendar-dashboard" class="dashboard-section <?php echo $active_dashboard === 'calendar' ? 'active' : ''; ?>">
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                <!-- Calendar -->
                <div class="lg:col-span-2">
                    <div class="calendar-shell mb-6">
                        <div class="calendar-header">
                            <div>
                                <p class="calendar-heading">Calendar View</p>
                                <div class="calendar-view-buttons mt-3" id="calendarViewSwitch">
                                    <button type="button" data-calendar-view="timeGridWeek">Week</button>
                                    <button type="button" data-calendar-view="dayGridMonth" class="is-active">Month</button>
                                    <button type="button" data-calendar-view="listYear">Year</button>
                                </div>
                            </div>
                            <div class="calendar-nav">
                                <button type="button" data-calendar-nav="prev" aria-label="Previous"><i data-feather="chevron-left" class="w-4 h-4"></i></button>
                                <div class="calendar-month-group">
                                    <div class="calendar-month-label" id="calendarMonthLabel">&nbsp;</div>
                                    <button type="button" id="calendarTodayBtn" class="calendar-today-btn">Today</button>
                                </div>
                                <button type="button" data-calendar-nav="next" aria-label="Next"><i data-feather="chevron-right" class="w-4 h-4"></i></button>
                                
                            </div>
                        </div>
                        <div id="calendar"></div>
                    </div>
                </div>
                
                <!-- Upcoming Events & Filters -->
                <div class="space-y-6">
                    <div class="bg-white rounded-xl shadow-sm overflow-hidden">
                        <div class="p-6 border-b border-gray-200">
                            <h2 class="text-xl font-semibold text-gray-800">Upcoming Events</h2>
                        </div>
                        <div class="divide-y divide-gray-200">
                            <?php if (count($upcoming_events) > 0): ?>
                                <?php foreach ($upcoming_events as $event): ?>
                                    <?php
                                        $eventLinkParams = [
                                            'dashboard' => 'calendar',
                                            'event_id' => $event['id']
                                        ];
                                        if (!empty($department_id)) {
                                            $eventLinkParams['department_id'] = $department_id;
                                        }
                                        if (!empty($program_id)) {
                                            $eventLinkParams['program_id'] = $program_id;
                                        }
                                        $eventLink = 'index.php?' . http_build_query($eventLinkParams);
                                    ?>
                                    <a href="<?php echo htmlspecialchars($eventLink); ?>" class="block p-4 hover:bg-gray-50 transition focus:outline-none focus:ring-2 focus:ring-red-200 focus:ring-offset-2 focus:ring-offset-white" aria-label="View details for <?php echo htmlspecialchars($event['title']); ?>">
                                        <div class="flex items-start space-x-4">
                                            <div class="flex-shrink-0 bg-red-100 text-red-800 rounded-lg p-3 text-center">
                                                <div class="text-sm font-medium"><?php echo date('M', strtotime($event['event_date'])); ?></div>
                                                <div class="text-xl font-bold"><?php echo date('j', strtotime($event['event_date'])); ?></div>
                                            </div>
                                            <div>
                                                <h3 class="font-medium text-gray-800"><?php echo htmlspecialchars($event['title']); ?></h3>
                                                <p class="text-sm text-gray-500">
                                                    <?php if ($event['event_time']): ?>
                                                        <?php echo date('g:i A', strtotime($event['event_time'])); ?> - 
                                                    <?php endif; ?>
                                                    <?php echo htmlspecialchars($event['event_location']); ?>
                                                </p>
                                                <?php if ($event['department_id']): ?>
                                                    <?php $dept = getDepartment($event['department_id']); ?>
                                                    <?php if ($dept): ?>
                                                        <span class="inline-block mt-1 px-2 py-1 text-xs bg-gray-100 text-gray-800 rounded"><?php echo htmlspecialchars($dept['code']); ?></span>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </a>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="p-4">
                                    <p class="text-gray-500">No upcoming events.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="px-6 py-4 border-t border-gray-200">
                            <a href="index.php?dashboard=announcements#announcements-dashboard" class="inline-flex items-center text-sm font-semibold text-red-600 hover:text-red-800">
                                Show more
                                <i data-feather="arrow-right" class="w-4 h-4 ml-1"></i>
                            </a>
                        </div>
                    </div>
                    
                    <div class="bg-white rounded-xl shadow-sm overflow-hidden">
                        <div class="p-6 border-b border-gray-200">
                            <h2 class="text-xl font-semibold text-gray-800">Event Filters</h2>
                        </div>
                        <div class="p-6 space-y-4">
                            <div class="filter-section">
                                <label for="calendar_department" class="block text-sm font-medium text-gray-700 mb-1">Department</label>
                                <select id="calendar_department" name="department_id" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-500">
                                    <option value="">All Departments</option>
                                    <?php foreach ($departments as $dept): ?>
                                        <option value="<?php echo $dept['id']; ?>" <?php echo $department_id == $dept['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($dept['code'] . ' - ' . $dept['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="filter-section">
                                <label for="calendar_program" class="block text-sm font-medium text-gray-700 mb-1">Program</label>
                                <select id="calendar_program" name="program_id" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-500" <?php echo empty($department_id) ? 'disabled' : ''; ?>>
                                    <option value="">All Programs</option>
                                    <?php if (!empty($department_id)): ?>
                                        <?php foreach ($programs as $program): ?>
                                            <option value="<?php echo $program['id']; ?>" <?php echo $program_id == $program['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($program['code'] . ' - ' . $program['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </select>
                            </div>
                            <div class="pt-2">
                                <a href="index.php?dashboard=calendar" class="w-full text-center border border-gray-300 text-gray-700 py-2 rounded-lg hover:bg-gray-50 transition block">Clear Filters</a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <?php if (count($announcements) > 4): ?>
    <button id="backToTopAnnouncements" type="button" class="back-to-top-btn hidden" aria-label="Back to top">
        <i data-feather="arrow-up" class="w-4 h-4"></i>
        <span>Top</span>
    </button>
    <?php endif; ?>

    <!-- Footer -->
    <footer class="bg-gray-800 text-white py-8" style="position: relative; bottom: 0; width: 100%; margin-top: 100px ; z-index: 10;">
        <div class="container mx-auto px-4">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                <div>
                    <h3 class="text-xl font-bold mb-4">UM Tagum College</h3>
                    <p class="text-gray-400">Announcement System for students and faculty members.</p>
                </div>
                <div>
                    <h3 class="text-lg font-semibold mb-4">Quick Links</h3>
                    <ul class="space-y-2">
                        <li><a href="index.php?dashboard=calendar" class="text-gray-400 hover:text-white transition">Home</a></li>
                        <li><a href="index.php?dashboard=announcements" class="text-gray-400 hover:text-white transition">Announcements</a></li>
                        <li><a href="login.php" class="text-gray-400 hover:text-white transition">Admin Login</a></li>
                    </ul>
                </div>
                <div>
                    <h3 class="text-lg font-semibold mb-4">Contact</h3>
                    <ul class="space-y-2 text-gray-400">
                        <li class="flex items-center space-x-2">
                            <i data-feather="mail" class="w-4 h-4"></i>
                            <span>GAlendar@umtagum.edu.ph</span>
                        </li>
                        <li class="flex items-center space-x-2">
                            <i data-feather="phone" class="w-4 h-4"></i>
                            <span>(0950) 077 1385</span>
                        </li>
                        <li class="flex items-center space-x-2">
                            <i data-feather="map-pin" class="w-4 h-4"></i>
                            <span>Tagum City, Davao del Norte</span>
                        </li>
                    </ul>
                </div>
            </div>
            <div class="border-t border-gray-700 mt-8 pt-6 text-center text-gray-400">
                <p>&copy; 2025 UM Tagum College. All rights reserved.</p>
            </div>
        </div>
    </footer>

    <!-- Event Detail Modal -->
    <?php if ($event_detail): 
        $modalCloseParams = [
            'dashboard' => $active_dashboard ?: 'home'
        ];

        if ($active_dashboard === 'announcements') {
            if (!empty($event_date_from)) {
                $modalCloseParams['event_date_from'] = $event_date_from;
            }
            if (!empty($event_date_to)) {
                $modalCloseParams['event_date_to'] = $event_date_to;
            }
            if (!empty($department_id)) {
                $modalCloseParams['department_id'] = $department_id;
            }
            if (!empty($program_id)) {
                $modalCloseParams['program_id'] = $program_id;
            }
        } elseif ($active_dashboard === 'calendar') {
            if (!empty($department_id)) {
                $modalCloseParams['department_id'] = $department_id;
            }
            if (!empty($program_id)) {
                $modalCloseParams['program_id'] = $program_id;
            }
        }

        $modalCloseUrl = 'index.php?' . http_build_query($modalCloseParams);
    ?>
    <div id="eventModal" class="fixed inset-0 z-50 overflow-y-auto" style="display: block;">
        <div class="flex items-center justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
            <div class="fixed inset-0 transition-opacity" aria-hidden="true">
                <div class="absolute inset-0 bg-gray-500 opacity-75"></div>
            </div>
            <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>
            <div class="inline-block align-bottom text-left transform transition-all sm:my-8 sm:align-middle sm:max-w-2xl sm:w-full">
                <div class="bg-white rounded-3xl overflow-hidden shadow-2xl">
                    <div class="relative bg-[#7c2020] px-6 py-6 text-white">
                        <div class="space-y-2">
                            <p class="text-xs uppercase tracking-[0.35em] text-white/70">Event highlight</p>
                            <h3 class="text-3xl font-semibold leading-tight" id="modal-title"><?php echo htmlspecialchars($event_detail['title']); ?></h3>
                            <?php if (!empty($event_detail['author_name'])): ?>
                            <p class="flex items-center gap-2 text-sm text-white/80">
                                <i data-feather="user" class="w-4 h-4"></i>
                                <?php echo htmlspecialchars($event_detail['author_name']); ?>
                            </p>
                            <?php endif; ?>
                        </div>
                        <a href="<?php echo htmlspecialchars($modalCloseUrl); ?>" class="absolute top-5 right-5 inline-flex items-center justify-center w-10 h-10 rounded-full bg-white/20 text-white backdrop-blur hover:bg-white/30 transition" aria-label="Close announcement details">
                            <i data-feather="x" class="w-5 h-5"></i>
                        </a>
                    </div>
                    <div class="px-6 py-6 space-y-6">
                        <div class="flex flex-wrap gap-3">
                            <?php if (!empty($event_detail['event_date'])): ?>
                            <div class="event-pill">
                                <span class="icon-wrap">
                                    <i data-feather="calendar" class="w-4 h-4"></i>
                                </span>
                                <div>
                                    <p class="pill-label">Schedule</p>
                                    <p class="pill-value">
                                        <?php echo date('M j, Y', strtotime($event_detail['event_date'])); ?>
                                        <?php if (!empty($event_detail['event_time'])): ?>
                                            · <?php echo date('g:i A', strtotime($event_detail['event_time'])); ?>
                                        <?php endif; ?>
                                    </p>
                                </div>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($event_detail['event_location'])): ?>
                            <div class="event-pill">
                                <span class="icon-wrap">
                                    <i data-feather="map-pin" class="w-4 h-4"></i>
                                </span>
                                <div>
                                    <p class="pill-label">Location</p>
                                    <p class="pill-value"><?php echo htmlspecialchars($event_detail['event_location']); ?></p>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>

                        <div class="rounded-2xl border border-gray-200 p-5">
                            <p class="text-sm font-semibold text-[#7c2020] mb-2">Overview</p>
                            <div class="text-sm text-gray-700 leading-relaxed">
                                <?php echo nl2br(htmlspecialchars($event_detail['content'])); ?>
                            </div>
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="rounded-2xl border border-gray-200 p-4">
                                <p class="text-xs uppercase text-gray-500 tracking-[0.2em] mb-1">Department</p>
                                <p class="text-base font-semibold text-gray-900">
                                    <?php echo !empty($event_detail['department_name']) ? htmlspecialchars($event_detail['department_name']) : 'All Departments'; ?>
                                </p>
                            </div>
                            <div class="rounded-2xl border border-gray-200 p-4">
                                <p class="text-xs uppercase text-gray-500 tracking-[0.2em] mb-1">Program</p>
                                <p class="text-base font-semibold text-gray-900">
                                    <?php echo !empty($event_detail['program_name']) ? htmlspecialchars($event_detail['program_name']) : 'All Programs'; ?>
                                </p>
                            </div>
                        </div>
                    </div>
                    <div class="px-6 py-4 bg-gray-50 border-t border-gray-100 flex justify-end">
                        <a href="<?php echo htmlspecialchars($modalCloseUrl); ?>" class="inline-flex items-center justify-center rounded-xl bg-[#7c2020] px-5 py-2 text-sm font-semibold text-white shadow hover:bg-[#5d1818] transition">
                            <i data-feather="arrow-left" class="w-4 h-4 mr-2"></i>
                            Close Details
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize Feather Icons
            feather.replace();
            
            // Initialize AOS
            AOS.init();

            const showAllBtn = document.getElementById('showAllAnnouncements');
            const limitedAnnouncements = document.querySelectorAll('.limited-announcement');
            const backToTopBtn = document.getElementById('backToTopAnnouncements');
            const announcementsSection = document.getElementById('announcements-dashboard');
            let announcementsExpanded = false;

            const toggleAnnouncements = (expand) => {
                announcementsExpanded = expand;
                limitedAnnouncements.forEach((card) => {
                    if (expand) {
                        card.classList.remove('hidden');
                    } else {
                        card.classList.add('hidden');
                    }
                });
                if (showAllBtn) {
                    showAllBtn.textContent = expand ? 'Show Less' : 'Show All Announcements';
                    showAllBtn.setAttribute('aria-label', expand ? 'Show fewer announcements' : 'Show all announcements');
                }
                if (!expand && backToTopBtn) {
                    backToTopBtn.classList.add('hidden');
                }
            };

            const refreshBackToTopVisibility = () => {
                if (!backToTopBtn) return;
                if (!announcementsExpanded || window.scrollY < 300) {
                    backToTopBtn.classList.add('hidden');
                } else {
                    backToTopBtn.classList.remove('hidden');
                }
            };

            if (showAllBtn) {
                showAllBtn.addEventListener('click', function() {
                    const shouldExpand = !announcementsExpanded;
                    toggleAnnouncements(shouldExpand);
                    if (!shouldExpand && announcementsSection) {
                        window.scrollTo({ top: announcementsSection.offsetTop - 80, behavior: 'smooth' });
                    }
                    refreshBackToTopVisibility();
                });
            }

            if (backToTopBtn) {
                backToTopBtn.addEventListener('click', function() {
                    const topOffset = announcementsSection ? announcementsSection.offsetTop - 60 : 0;
                    window.scrollTo({ top: topOffset, behavior: 'smooth' });
                });
                window.addEventListener('scroll', refreshBackToTopVisibility);
            }
            
            // Mobile menu toggle
            document.getElementById('mobile-menu-button').addEventListener('click', function() {
                document.getElementById('mobile-menu').classList.toggle('show');
            });

            // Function to apply event date filters
            window.applyEventDateFilters = function() {
                const eventDateFrom = document.getElementById('event_date_from').value;
                const eventDateTo = document.getElementById('event_date_to').value;
                const departmentId = document.getElementById('department_id_filter').value;
                const programId = document.getElementById('program_id_filter').value;
                
                // Validate date range
                if (eventDateFrom && eventDateTo && new Date(eventDateFrom) > new Date(eventDateTo)) {
                    alert('"From Event Date" cannot be after "To Event Date"');
                    return;
                }
                
                // Build URL with all filters
                let url = `index.php?dashboard=announcements`;
                
                if (eventDateFrom) url += `&event_date_from=${eventDateFrom}`;
                if (eventDateTo) url += `&event_date_to=${eventDateTo}`;
                if (departmentId) url += `&department_id=${departmentId}`;
                if (programId) url += `&program_id=${programId}`;
                
                window.location.href = url;
            };

            // Enter key support for date inputs
            document.getElementById('event_date_from').addEventListener('keypress', function(e) {
                if (e.key === 'Enter') applyEventDateFilters();
            });
            
            document.getElementById('event_date_to').addEventListener('keypress', function(e) {
                if (e.key === 'Enter') applyEventDateFilters();
            });

            const subscribeBtn = document.getElementById('subscribe_button');
            const subscribeEmail = document.getElementById('subscribe_email');
            const subscribeProgram = document.getElementById('subscribe_program');
            const subscribeMessage = document.getElementById('subscribe_message');

            if (subscribeBtn && subscribeEmail && subscribeProgram && subscribeMessage) {
                subscribeBtn.addEventListener('click', function() {
                    subscribeMessage.textContent = '';
                    subscribeMessage.className = 'text-sm';
                    const email = subscribeEmail.value.trim();
                    const programId = subscribeProgram.value;
                    if (!email || !programId) {
                        subscribeMessage.textContent = 'Please enter your email and choose a course.';
                        subscribeMessage.classList.add('text-red-600');
                        return;
                    }

                    subscribeBtn.disabled = true;
                    subscribeBtn.textContent = 'Subscribing...';

                    const formData = new FormData();
                    formData.append('email', email);
                    formData.append('program_id', programId);

                    fetch('subscribe.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(res => res.json())
                    .then(data => {
                        subscribeMessage.textContent = data.message || 'Subscription saved.';
                        subscribeMessage.classList.add(data.success ? 'text-green-600' : 'text-red-600');
                    })
                    .catch(() => {
                        subscribeMessage.textContent = 'We could not save your subscription right now. Please try again.';
                        subscribeMessage.classList.add('text-red-600');
                    })
                    .finally(() => {
                        subscribeBtn.disabled = false;
                        subscribeBtn.textContent = 'Subscribe';
                    });
                });
            }

            // Initialize FullCalendar
            function initCalendar() {
                const calendarEl = document.getElementById('calendar');
                if (!calendarEl) return;

                const calendarEvents = <?php 
                    echo json_encode(
                        array_map(function($event, $idx) {
                            return [
                                'id' => $event['id'],
                                'title' => $event['title'],
                                'start' => $event['event_date'] . ($event['event_time'] ? 'T' . $event['event_time'] : ''),
                                'allDay' => empty($event['event_time']),
                                'className' => ($idx % 3 === 0) ? 'pink-event' : ''
                            ];
                        }, $events, array_keys($events))
                    ); 
                ?>;

                const calendarNavButtons = document.querySelectorAll('[data-calendar-nav]');
                const calendarViewButtons = document.querySelectorAll('[data-calendar-view]');
                const calendarMonthLabel = document.getElementById('calendarMonthLabel');
                const calendarTodayBtn = document.getElementById('calendarTodayBtn');

                const calendar = new FullCalendar.Calendar(calendarEl, {
                    initialView: 'dayGridMonth',
                    headerToolbar: false,
                    height: 'auto',
                    events: calendarEvents,
                    eventClick: function(info) {
                        info.jsEvent.preventDefault();
                        const targetUrl = new URL(window.location.href);
                        targetUrl.searchParams.set('dashboard', 'calendar');
                        targetUrl.searchParams.set('event_id', info.event.id);
                        window.location.href = targetUrl.toString();
                    }
                });

                const updateCalendarLabel = () => {
                    if (calendarMonthLabel) {
                        calendarMonthLabel.textContent = calendar.view.title;
                    }
                    calendarViewButtons.forEach((btn) => {
                        const view = btn.getAttribute('data-calendar-view');
                        if (view === calendar.view.type) {
                            btn.classList.add('is-active');
                        } else {
                            btn.classList.remove('is-active');
                        }
                    });
                };

                calendar.on('datesSet', updateCalendarLabel);

                calendar.render();
                updateCalendarLabel();

                calendarNavButtons.forEach((btn) => {
                    btn.addEventListener('click', () => {
                        const direction = btn.getAttribute('data-calendar-nav');
                        if (direction === 'prev') {
                            calendar.prev();
                        } else if (direction === 'next') {
                            calendar.next();
                        }
                    });
                });

                calendarViewButtons.forEach((btn) => {
                    btn.addEventListener('click', () => {
                        const view = btn.getAttribute('data-calendar-view');
                        try {
                            calendar.changeView(view);
                        } catch (e) {
                            console.warn('View not supported:', view, e);
                        }
                        updateCalendarLabel();
                    });
                });

                if (calendarTodayBtn) {
                    calendarTodayBtn.addEventListener('click', () => {
                        calendar.today();
                        updateCalendarLabel();
                    });
                }
            }
            
            // Initialize calendar if on calendar dashboard
            if (document.getElementById('calendar-dashboard').classList.contains('active')) {
                initCalendar();
            }
            
            // Department filter change handler for program dropdown
            const departmentFilter = document.getElementById('department_id_filter');
            const programFilter = document.getElementById('program_id_filter');
            
            if (departmentFilter) {
                departmentFilter.addEventListener('change', function() {
                    const departmentId = this.value;
                    
                    if (departmentId) {
                        // Show loading indicator
                        programFilter.disabled = true;
                        programFilter.innerHTML = '<option value="">Loading programs...</option>';
                        
                        // Fetch programs for selected department
                        fetch(`get_programs.php?department_id=${departmentId}`)
                            .then(response => response.json())
                            .then(programs => {
                                // Clear existing options
                                programFilter.innerHTML = '<option value="">All Programs</option>';
                                
                                // Add new options
                                programs.forEach(program => {
                                    const option = document.createElement('option');
                                    option.value = program.id;
                                    option.textContent = `${program.code} - ${program.name}`;
                                    programFilter.appendChild(option);
                                });
                                
                                // Enable program dropdown
                                programFilter.disabled = false;
                            })
                            .catch(error => {
                                console.error('Error fetching programs:', error);
                                programFilter.innerHTML = '<option value="">Error loading programs</option>';
                            });
                    } else {
                        programFilter.disabled = true;
                        programFilter.innerHTML = '<option value="">All Programs</option>';
                    }
                });
            }
            
            // Calendar department filter change handler
            const calendarDepartmentFilter = document.getElementById('calendar_department');
            const calendarProgramFilter = document.getElementById('calendar_program');
            
            if (calendarDepartmentFilter) {
                calendarDepartmentFilter.addEventListener('change', function() {
                    const departmentId = this.value;
                    const currentDashboard = 'calendar';
                    
                    if (departmentId) {
                        // Show loading indicator
                        calendarProgramFilter.disabled = true;
                        calendarProgramFilter.innerHTML = '<option value="">Loading programs...</option>';
                        
                        // Fetch programs for selected department
                        fetch(`get_programs.php?department_id=${departmentId}`)
                            .then(response => response.json())
                            .then(programs => {
                                // Clear existing options
                                calendarProgramFilter.innerHTML = '<option value="">All Programs</option>';
                                
                                // Add new options
                                programs.forEach(program => {
                                    const option = document.createElement('option');
                                    option.value = program.id;
                                    option.textContent = `${program.code} - ${program.name}`;
                                    calendarProgramFilter.appendChild(option);
                                });
                                
                                // Enable program dropdown
                                calendarProgramFilter.disabled = false;
                                
                                // Create a hidden form to submit
                                const form = document.createElement('form');
                                form.method = 'GET';
                                form.action = 'index.php';
                                
                                const dashboardInput = document.createElement('input');
                                dashboardInput.type = 'hidden';
                                dashboardInput.name = 'dashboard';
                                dashboardInput.value = currentDashboard;
                                form.appendChild(dashboardInput);
                                
                                const deptInput = document.createElement('input');
                                deptInput.type = 'hidden';
                                deptInput.name = 'department_id';
                                deptInput.value = departmentId;
                                form.appendChild(deptInput);
                                
                                document.body.appendChild(form);
                                form.submit();
                            })
                            .catch(error => {
                                console.error('Error fetching programs:', error);
                                calendarProgramFilter.innerHTML = '<option value="">Error loading programs</option>';
                            });
                    } else {
                        // Redirect without department filter but keep dashboard
                        window.location.href = `index.php?dashboard=${currentDashboard}`;
                    }
                });
            }
            
            // Program filter change handler for calendar dashboard
            if (calendarProgramFilter) {
                calendarProgramFilter.addEventListener('change', function() {
                    const programId = this.value;
                    const departmentId = calendarDepartmentFilter ? calendarDepartmentFilter.value : '';
                    const currentDashboard = 'calendar';
                    
                    // Create URL with both department and program filters
                    let url = `index.php?dashboard=${currentDashboard}`;
                    if (departmentId) {
                        url += `&department_id=${departmentId}`;
                    }
                    if (programId) {
                        url += `&program_id=${programId}`;
                    }
                    
                    window.location.href = url;
                });
            }
            
            // Close modal if clicked outside
            <?php if ($event_detail): ?>
            document.getElementById('eventModal').addEventListener('click', function(e) {
                if (e.target === this) {
                    window.location.href = '<?php echo htmlspecialchars($modalCloseUrl); ?>';
                }
            });
            <?php endif; ?>
        });
    </script>
</body>
</html>