<?php
require_once 'config.php';
requireLogin();

$user = getCurrentUser();
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $full_name = trim($_POST['full_name'] ?? '');

    if ($username === '' || $full_name === '') {
        $error = 'Username and Full Name are required.';
    }

    // Handle profile picture upload
    $profile_picture_path = $user['profile_picture'] ?? null;
    if (empty($error) && isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
        $tmp = $_FILES['profile_picture']['tmp_name'];
        $name = basename($_FILES['profile_picture']['name']);
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $allowed = ['jpg','jpeg','png','gif','webp'];
        if (!in_array($ext, $allowed)) {
            $error = 'Invalid image type. Allowed: jpg, jpeg, png, gif, webp';
        } else {
            $uploadDir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads';
            if (!is_dir($uploadDir)) {
                @mkdir($uploadDir, 0775, true);
            }
            $newName = 'profile_' . $_SESSION['user_id'] . '_' . time() . '.' . $ext;
            $dest = $uploadDir . DIRECTORY_SEPARATOR . $newName;
            if (move_uploaded_file($tmp, $dest)) {
                $profile_picture_path = 'uploads/' . $newName;
            } else {
                $error = 'Failed to upload image.';
            }
        }
    }

    if (empty($error)) {
        try {
            $stmt = $pdo->prepare('UPDATE users SET username = ?, full_name = ?, profile_picture = ? WHERE id = ?');
            $stmt->execute([$username, $full_name, $profile_picture_path, $_SESSION['user_id']]);
            if (function_exists('logActivity')) {
                $details = json_encode(['username' => $username, 'full_name' => $full_name]);
                logActivity('profile_update', $details);
            }
            $_SESSION['username'] = $username;
            $_SESSION['full_name'] = $full_name;
            $success = 'Profile updated successfully.';
            $user = getCurrentUser();
        } catch (Exception $e) {
            $error = 'We could not save your profile changes right now. Please try again in a moment.';
            error_log('Profile update failed: ' . $e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile - UMTC Announcement System</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/feather-icons/dist/feather.min.js" defer></script>
    <link rel="stylesheet" href="includes/design-system.css">
    <script src="includes/app-init.js" defer></script>
</head>
<body class="bg-gray-100 min-h-screen py-12 px-4">
    <div class="max-w-3xl mx-auto bg-white rounded-2xl shadow-xl p-10 space-y-8">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <p class="text-sm font-semibold text-gray-400 uppercase tracking-[0.2em]">Account</p>
                <h1 class="text-3xl font-bold text-gray-900">My Profile</h1>
            </div>
            <a href="<?php echo isSuperAdmin() ? 'super_admin.php' : 'admin.php'; ?>" class="inline-flex items-center gap-2 px-5 py-2.5 text-sm font-semibold text-white bg-red-600 rounded-full shadow hover:bg-red-700 transition">
                <i data-feather="arrow-left" class="w-4 h-4"></i>
                <span>Back to Dashboard</span>
            </a>
        </div>

        <?php if (!empty($error)): ?>
            <div class="mb-4 p-3 bg-red-100 text-red-700 rounded"><?php echo $error; ?></div>
        <?php endif; ?>
        <?php if (!empty($success)): ?>
            <div class="mb-4 p-3 bg-green-100 text-green-700 rounded"><?php echo $success; ?></div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data" class="space-y-6">
            <div class="flex flex-wrap items-center gap-8 bg-gray-50 rounded-2xl p-6">
                <img src="<?php echo !empty($user['profile_picture']) ? htmlspecialchars($user['profile_picture']) : 'https://via.placeholder.com/100x100.png?text=User'; ?>" alt="Profile" class="w-32 h-32 rounded-full object-cover border-4 border-red-100 shadow">
                <div class="space-y-2">
                    <label class="block text-sm font-medium text-gray-700">Profile Picture</label>
                    <input type="file" name="profile_picture" accept="image/*" class="block text-sm">
                    <p class="text-xs text-gray-500">Recommended: square image, max 5&nbsp;MB.</p>
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700">Username</label>
                <input type="text" name="username" required value="<?php echo htmlspecialchars($user['username']); ?>" class="mt-2 w-full border-2 border-gray-200 rounded-xl px-4 py-3 text-base focus:outline-none focus:ring-2 focus:ring-red-500">
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700">Full Name</label>
                <input type="text" name="full_name" required value="<?php echo htmlspecialchars($user['full_name']); ?>" class="mt-2 w-full border-2 border-gray-200 rounded-xl px-4 py-3 text-base focus:outline-none focus:ring-2 focus:ring-red-500">
            </div>

            <div class="flex justify-end">
                <button type="submit" class="inline-flex items-center gap-2 px-6 py-3 bg-red-600 text-white rounded-full font-semibold shadow hover:bg-red-700 transition">
                    <i data-feather="save" class="w-4 h-4"></i>
                    <span>Save Changes</span>
                </button>
            </div>
        </form>
    </div>
</body>
</html>



