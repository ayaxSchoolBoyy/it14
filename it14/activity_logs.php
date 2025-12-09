<?php
require_once 'config.php';
requireSuperAdmin();

// Filters
$search = isset($_GET['q']) ? trim($_GET['q']) : '';
$actor = isset($_GET['actor']) ? trim($_GET['actor']) : '';
$action = isset($_GET['action']) ? trim($_GET['action']) : '';
$limit = isset($_GET['limit']) ? max(10, min(200, intval($_GET['limit']))) : 50;

// Fetch logs with optional filters
$where = [];
$params = [];
if ($search !== '') {
    $where[] = '(details LIKE ? OR u.full_name LIKE ? OR u.username LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($actor !== '') {
    $where[] = 'u.id = ?';
    $params[] = $actor;
}
if ($action !== '') {
    $where[] = 'l.action = ?';
    $params[] = $action;
}
$whereSql = count($where) ? ('WHERE ' . implode(' AND ', $where)) : '';

$stmt = $pdo->prepare("SELECT l.*, u.username, u.full_name FROM activity_logs l LEFT JOIN users u ON l.actor_user_id = u.id $whereSql ORDER BY l.created_at DESC LIMIT $limit");
$stmt->execute($params);
$logs = $stmt->fetchAll();

// Fetch actors and actions for filters
$actors = $pdo->query("SELECT id, username, full_name FROM users ORDER BY full_name")->fetchAll();
$actions = $pdo->query("SELECT DISTINCT action FROM activity_logs ORDER BY action")->fetchAll(PDO::FETCH_COLUMN);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Activity Logs - UMTC Announcement System</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
    <script src="https://unpkg.com/aos@2.3.1/dist/aos.js" defer></script>
    <script src="https://cdn.jsdelivr.net/npm/feather-icons/dist/feather.min.js" defer></script>
    <link rel="stylesheet" href="includes/design-system.css">
    <script src="includes/app-init.js" defer></script>
    <style>
        .gradient-bg { background: linear-gradient(135deg, #7c2020ff 0%, #832222ff 100%); }
        .card-hover { transition: transform .2s ease, box-shadow .2s ease; }
        .card-hover:hover { transform: translateY(-4px); box-shadow: 0 10px 20px rgba(0,0,0,0.08); }
    </style>
</head>
<body class="bg-gray-50">
    <nav class="gradient-bg text-white shadow">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between">
            <div class="flex items-center">
                <i data-feather="file-text" class="h-5 w-5 mr-2"></i>
                <span class="text-xl font-semibold">Activity Logs</span>
            </div>
            <div class="flex items-center">
                <a href="super_admin.php" class="inline-flex items-center gap-2 px-3 py-2 text-sm rounded-md bg-white/10 hover:bg-white/20 transition">
                    <i data-feather="arrow-left" class="w-4 h-4"></i>
                    <span>Back</span>
                </a>
            </div>
        </div>
    </nav>

    <main class="max-w-7xl mx-auto p-4 sm:p-6 lg:p-8">
        <div class="bg-white shadow rounded-lg p-4 sm:p-6 card-hover" data-aos="fade-up">
            <form class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 mb-6" method="GET">
                <div>
                    <label class="block text-sm text-gray-600 mb-1">Search</label>
                    <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>" class="w-full border rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-red-500" placeholder="Details, name or username">
                </div>
                <div>
                    <label class="block text-sm text-gray-600 mb-1">users</label>
                    <select name="actor" class="w-full border rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-red-500">
                        <option value="">All</option>
                        <?php foreach ($actors as $a): ?>
                            <option value="<?php echo $a['id']; ?>" <?php echo ($actor !== '' && $actor == $a['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($a['full_name'] . ' (' . $a['username'] . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm text-gray-600 mb-1">Activity</label>
                    <select name="action" class="w-full border rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-red-500">
                        <option value="">All</option>
                        <?php foreach ($actions as $act): ?>
                            <option value="<?php echo htmlspecialchars($act); ?>" <?php echo ($action !== '' && $action === $act) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($act); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="sm:col-span-2 lg:col-span-3 flex justify-end">
                    <button type="submit" class="px-4 py-2 bg-red-600 hover:bg-red-700 transition text-white rounded-md">Apply</button>
                </div>
            </form>

            <!-- Desktop Table View -->
            <div class="hidden lg:block overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">When</th>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actor</th>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Action</th>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Details</th>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">IP</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php if (count($logs) === 0): ?>
                            <tr>
                                <td colspan="5" class="px-4 py-6 text-center text-gray-500">No logs found.</td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($logs as $log): ?>
                            <tr class="hover:bg-gray-50 transition" data-aos="fade-up">
                                <td class="px-4 py-2 text-sm text-gray-700">
                                    <?php echo date('M j, Y g:i A', strtotime($log['created_at'])); ?>
                                </td>
                                <td class="px-4 py-2 text-sm text-gray-700">
                                    <?php echo $log['full_name'] ? htmlspecialchars($log['full_name']) : 'System'; ?>
                                    <?php if ($log['username']): ?>
                                        <span class="text-gray-400">(<?php echo htmlspecialchars($log['username']); ?>)</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-2 text-sm">
                                    <?php 
                                        $badge = 'bg-blue-100 text-blue-800';
                                        if (strpos($log['action'], 'delete') !== false) $badge = 'bg-red-100 text-red-800';
                                        if (strpos($log['action'], 'create') !== false) $badge = 'bg-green-100 text-green-800';
                                        if (strpos($log['action'], 'update') !== false) $badge = 'bg-yellow-100 text-yellow-800';
                                    ?>
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo $badge; ?>"><?php echo htmlspecialchars($log['action']); ?></span>
                                </td>
                                <td class="px-4 py-2 text-sm text-gray-700">
                                    <?php 
                                    $details = $log['details'];
                                    if ($details) {
                                        $decoded = json_decode($details, true);
                                        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                                            // Format the details in a more readable way
                                            echo '<div class="space-y-1">';
                                            foreach ($decoded as $key => $value) {
                                                if ($value !== null && $value !== '') {
                                                    $label = ucwords(str_replace('_', ' ', $key));
                                                    if ($key === 'is_published') {
                                                        $value = $value ? 'Yes' : 'No';
                                                    } elseif ($key === 'event_date' && $value) {
                                                        $value = date('M j, Y', strtotime($value));
                                                    } elseif ($key === 'event_time' && $value) {
                                                        $value = date('g:i A', strtotime($value));
                                                    } elseif ($key === 'department_id' && $value) {
                                                        // Try to get department name
                                                        $dept_stmt = $pdo->prepare("SELECT name FROM departments WHERE id = ?");
                                                        $dept_stmt->execute([$value]);
                                                        $dept = $dept_stmt->fetch();
                                                        $value = $dept ? $dept['name'] : "Department ID: $value";
                                                    } elseif ($key === 'program_id' && $value) {
                                                        // Try to get program name
                                                        $prog_stmt = $pdo->prepare("SELECT name FROM programs WHERE id = ?");
                                                        $prog_stmt->execute([$value]);
                                                        $prog = $prog_stmt->fetch();
                                                        $value = $prog ? $prog['name'] : "Program ID: $value";
                                                    }
                                                    echo '<div class="flex"><span class="font-medium text-gray-600 w-24">' . htmlspecialchars($label) . ':</span> <span class="text-gray-800">' . htmlspecialchars($value) . '</span></div>';
                                                }
                                            }
                                            echo '</div>';
                                        } else {
                                            // Fallback for non-JSON details
                                            echo '<div class="text-xs bg-gray-50 p-2 rounded">' . htmlspecialchars($details) . '</div>';
                                        }
                                    } else {
                                        echo '<span class="text-gray-400">No details</span>';
                                    }
                                    ?>
                                </td>
                                <td class="px-4 py-2 text-sm text-gray-700">
                                    <?php echo htmlspecialchars($log['ip_address']); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Mobile Card View -->
            <div class="lg:hidden space-y-4">
                <?php if (count($logs) === 0): ?>
                    <div class="text-center py-6 text-gray-500">No logs found.</div>
                <?php endif; ?>
                <?php foreach ($logs as $log): ?>
                    <div class="bg-white border border-gray-200 rounded-lg p-4 shadow-sm hover:shadow-md transition" data-aos="fade-up">
                        <div class="flex items-start justify-between mb-3">
                            <div class="flex-1">
                                <div class="text-sm font-medium text-gray-900">
                                    <?php echo $log['full_name'] ? htmlspecialchars($log['full_name']) : 'System'; ?>
                                    <?php if ($log['username']): ?>
                                        <span class="text-gray-500 text-xs">(<?php echo htmlspecialchars($log['username']); ?>)</span>
                                    <?php endif; ?>
                                </div>
                                <div class="text-xs text-gray-500 mt-1">
                                    <?php echo date('M j, Y g:i A', strtotime($log['created_at'])); ?>
                                </div>
                            </div>
                            <div>
                                <?php 
                                    $badge = 'bg-blue-100 text-blue-800';
                                    if (strpos($log['action'], 'delete') !== false) $badge = 'bg-red-100 text-red-800';
                                    if (strpos($log['action'], 'create') !== false) $badge = 'bg-green-100 text-green-800';
                                    if (strpos($log['action'], 'update') !== false) $badge = 'bg-yellow-100 text-yellow-800';
                                ?>
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo $badge; ?>"><?php echo htmlspecialchars($log['action']); ?></span>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <div class="text-xs text-gray-500 mb-1">Details:</div>
                            <div class="text-sm text-gray-700">
                                <?php 
                                $details = $log['details'];
                                if ($details) {
                                    $decoded = json_decode($details, true);
                                    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                                        // Format the details in a more readable way
                                        echo '<div class="space-y-1">';
                                        foreach ($decoded as $key => $value) {
                                            if ($value !== null && $value !== '') {
                                                $label = ucwords(str_replace('_', ' ', $key));
                                                if ($key === 'is_published') {
                                                    $value = $value ? 'Yes' : 'No';
                                                } elseif ($key === 'event_date' && $value) {
                                                    $value = date('M j, Y', strtotime($value));
                                                } elseif ($key === 'event_time' && $value) {
                                                    $value = date('g:i A', strtotime($value));
                                                } elseif ($key === 'department_id' && $value) {
                                                    // Try to get department name
                                                    $dept_stmt = $pdo->prepare("SELECT name FROM departments WHERE id = ?");
                                                    $dept_stmt->execute([$value]);
                                                    $dept = $dept_stmt->fetch();
                                                    $value = $dept ? $dept['name'] : "Department ID: $value";
                                                } elseif ($key === 'program_id' && $value) {
                                                    // Try to get program name
                                                    $prog_stmt = $pdo->prepare("SELECT name FROM programs WHERE id = ?");
                                                    $prog_stmt->execute([$value]);
                                                    $prog = $prog_stmt->fetch();
                                                    $value = $prog ? $prog['name'] : "Program ID: $value";
                                                }
                                                echo '<div class="flex flex-col sm:flex-row"><span class="font-medium text-gray-600 text-xs sm:w-20">' . htmlspecialchars($label) . ':</span> <span class="text-gray-800 text-xs">' . htmlspecialchars($value) . '</span></div>';
                                            }
                                        }
                                        echo '</div>';
                                    } else {
                                        // Fallback for non-JSON details
                                        echo '<div class="text-xs bg-gray-50 p-2 rounded">' . htmlspecialchars($details) . '</div>';
                                    }
                                } else {
                                    echo '<span class="text-gray-400 text-xs">No details</span>';
                                }
                                ?>
                            </div>
                        </div>
                        
                        <div class="text-xs text-gray-500">
                            IP: <?php echo htmlspecialchars($log['ip_address']); ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </main>

    <script>
AOS.init({ duration: 700, easing: 'ease-out', once: true });
feather.replace();
</script>
</body>
</html>

