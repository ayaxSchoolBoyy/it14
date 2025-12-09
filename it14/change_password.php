<?php
// change_password.php - Password change page for admins
require_once 'config.php';
requireLogin();

$dashboardTarget = isSuperAdmin() ? 'super_admin.php' : 'admin.php';

$current_user = getCurrentUser();
$general_errors = [];
$password_errors = [];
$success = '';
// Function to validate password strength
function validatePassword($password) {
    // Minimum 8 characters
    if (strlen($password) < 8) {
        return "Password must be at least 8 characters long.";
    }
    
    // At least one uppercase letter
    if (!preg_match('/[A-Z]/', $password)) {
        return "Password must contain at least one uppercase letter.";
    }
    
    // At least one lowercase letter
    if (!preg_match('/[a-z]/', $password)) {
        return "Password must contain at least one lowercase letter.";
    }
    
    // At least one number
    if (!preg_match('/[0-9]/', $password)) {
        return "Password must contain at least one number.";
    }
    
    // At least one special character
    if (!preg_match('/[!@#$%^&*()\-_=+{};:,<.>]/', $password)) {
        return "Password must contain at least one special character (!@#$%^&*()-_=+{};:,<.>).";
    }
    
    return true;
}

function notifySuperAdminsOfPasswordChange($changedUser) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT email, full_name FROM users WHERE role = 'super_admin' AND email IS NOT NULL AND email <> ''");
        $stmt->execute();
        $recipients = $stmt->fetchAll();

        if (empty($recipients)) {
            return;
        }

        $subject = 'Password Changed: ' . ($changedUser['full_name'] ?? $changedUser['username'] ?? 'Admin User');
        $body = "Hello Super Admin,\n\n" .
            ($changedUser['full_name'] ?? 'An admin') .
            " (" . ($changedUser['username'] ?? 'unknown username') . ") updated their password on " . date('M j, Y g:i A') . ".\n" .
            "If this was not expected, please review the activity logs immediately.\n\n" .
            "— UMTC Announcement System";
        $headers = "From: no-reply@umtc.local\r\n";

        foreach ($recipients as $recipient) {
            if (!filter_var($recipient['email'], FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            @mail($recipient['email'], $subject, $body, $headers);
        }
    } catch (Exception $e) {
        // Silently skip notification failures to avoid blocking password changes
    }
}

// Handle password change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $new_password = trim($_POST['new_password']);
    $confirm_password = trim($_POST['confirm_password']);
    $current_password = trim($_POST['current_password'] ?? '');
    
    // Check if password change is required to determine if current password is needed
    $stmt = $pdo->prepare("SELECT require_password_change FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    $password_change_required = $user['require_password_change'];
    
    if (!$password_change_required) {
        if ($current_password === '') {
            $general_errors[] = 'Current password is required.';
        } else {
            $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            $user = $stmt->fetch();

            if (!$user || !password_verify($current_password, $user['password'])) {
                $general_errors[] = 'Current password is incorrect.';
            }
        }
    }

    if ($new_password === '' || $confirm_password === '') {
        $password_errors[] = 'Please fill in and confirm your new password.';
    } else {
        if ($new_password !== $confirm_password) {
            $password_errors[] = 'New passwords do not match.';
        }

        $passwordValidation = validatePassword($new_password);
        if ($passwordValidation !== true) {
            $password_errors[] = $passwordValidation;
        }
    }

    if (empty($general_errors) && empty($password_errors)) {
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE users SET password = ?, require_password_change = 0 WHERE id = ?");
        $stmt->execute([$hashed_password, $_SESSION['user_id']]);

        if (function_exists('logActivity')) {
            $details = json_encode(['user_id' => $_SESSION['user_id']]);
            logActivity('password_change', $details);
        }

        notifySuperAdminsOfPasswordChange($current_user);

        $success = 'Password changed successfully!';

        if ($password_change_required) {
            header('Location: ' . $dashboardTarget);
            exit();
        }
    }
}

// Check if password change is required (for display purposes)
$stmt = $pdo->prepare("SELECT require_password_change FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();
$password_change_required = $user['require_password_change'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change Password - UMTC Announcement System</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/feather-icons"></script>
    <style>
        .password-requirement {
            transition: all 0.3s ease;
        }
        .requirement-met {
            color: #10b981;
        }
        .requirement-not-met {
            color: #ef4444;
        }
        .password-field {
            padding-right: 3rem !important;
        }
    </style>
</head>
<body class="bg-gray-50 min-h-screen flex items-center justify-center">
    <div class="max-w-md w-full bg-white rounded-lg shadow-md p-6">
        <div class="text-center mb-6">
            <h1 class="text-2xl font-bold text-gray-800">
                <?php echo $password_change_required ? 'Password Change Required' : 'Change Password'; ?>
            </h1>
            <p class="text-gray-600 mt-2">
                <?php if ($password_change_required): ?>
                    For security reasons, you must change your password before continuing.
                <?php else: ?>
                    Update your password to keep your account secure.
                <?php endif; ?>
            </p>
        </div>

        <?php if (!empty($general_errors)): ?>
            <div class="mb-4 p-3 bg-red-100 text-red-700 rounded-md">
                <ul class="list-disc pl-5 space-y-1 text-sm">
                    <?php foreach ($general_errors as $general_error): ?>
                        <li><?php echo htmlspecialchars($general_error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if (!empty($success)): ?>
            <div class="mb-4 p-3 bg-green-100 text-green-700 rounded-md">
                <?php echo $success; ?>
            </div>
        <?php endif; ?>

        <form method="POST" class="space-y-4">
            <?php if (!$password_change_required): ?>
            <div>
                <label for="current_password" class="block text-sm font-medium text-gray-700 mb-1">
                    Current Password
                </label>
                <div class="relative">
                          <input type="password" id="current_password" name="current_password" required
                              class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-red-500 password-field">
                    <button type="button" id="toggle_current" class="absolute right-2 top-1/2 -translate-y-1/2 text-gray-500 hover:text-gray-700">
                        <i data-feather="eye"></i>
                    </button>
                </div>
            </div>
            <?php endif; ?>

            <div>
                <label for="new_password" class="block text-sm font-medium text-gray-700 mb-1">
                    New Password
                </label>
                <div class="relative">
                              <input type="password" id="new_password" name="new_password" required
                                  class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-red-500 password-field">
                    <button type="button" id="toggle_new" class="absolute right-2 top-1/2 -translate-y-1/2 text-gray-500 hover:text-gray-700">
                        <i data-feather="eye"></i>
                    </button>
                </div>
            </div>

            <div>
                <label for="confirm_password" class="block text-sm font-medium text-gray-700 mb-1">
                    Confirm New Password
                </label>
                <div class="relative">
                          <input type="password" id="confirm_password" name="confirm_password" required
                              class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-red-500 password-field">
                    <button type="button" id="toggle_confirm" class="absolute right-2 top-1/2 -translate-y-1/2 text-gray-500 hover:text-gray-700">
                        <i data-feather="eye"></i>
                    </button>
                </div>
            </div>

            <?php if (!empty($password_errors)): ?>
            <div class="md:col-span-3 bg-red-50 border-l-4 border-red-400 rounded-md p-3 text-sm text-red-700">
                <ul class="list-disc pl-5 space-y-1">
                    <?php foreach ($password_errors as $password_error): ?>
                        <li><?php echo htmlspecialchars($password_error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <!-- Password Requirements -->
            <div class="bg-gray-50 p-4 rounded-md">
                <h3 class="text-sm font-medium text-gray-700 mb-2">Password Requirements:</h3>
                <ul class="text-sm space-y-1">
                    <li id="req-length" class="password-requirement requirement-not-met">
                        • At least 8 characters long
                    </li>
                    <li id="req-uppercase" class="password-requirement requirement-not-met">
                        • At least one uppercase letter (A-Z)
                    </li>
                    <li id="req-lowercase" class="password-requirement requirement-not-met">
                        • At least one lowercase letter (a-z)
                    </li>
                    <li id="req-number" class="password-requirement requirement-not-met">
                        • At least one number (0-9)
                    </li>
                    <li id="req-special" class="password-requirement requirement-not-met">
                        • At least one special character (!@#$%^&*()-_=+{};:,<.>)
                    </li>
                </ul>
            </div>

            <button type="submit" name="change_password" 
                    class="w-full bg-red-600 text-white py-2 px-4 rounded-md hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500 font-medium">
                Change Password
            </button>

            <?php if (!$password_change_required): ?>
            <div class="text-center">
                <a href="<?php echo $dashboardTarget; ?>" class="inline-flex items-center gap-2 px-5 py-2.5 mt-2 text-sm font-semibold text-red-700 border border-red-200 rounded-full hover:bg-red-50 transition">
                    <i data-feather="arrow-left" class="w-4 h-4"></i>
                    <span>Back to Dashboard</span>
                </a>
            </div>
            <?php endif; ?>
        </form>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const newPasswordInput = document.getElementById('new_password');
            const requirements = {
                length: document.getElementById('req-length'),
                uppercase: document.getElementById('req-uppercase'),
                lowercase: document.getElementById('req-lowercase'),
                number: document.getElementById('req-number'),
                special: document.getElementById('req-special')
            };

            newPasswordInput.addEventListener('input', function() {
                const password = this.value;

                // Check length
                if (password.length >= 8) {
                    requirements.length.classList.remove('requirement-not-met');
                    requirements.length.classList.add('requirement-met');
                } else {
                    requirements.length.classList.remove('requirement-met');
                    requirements.length.classList.add('requirement-not-met');
                }

                // Check uppercase
                if (/[A-Z]/.test(password)) {
                    requirements.uppercase.classList.remove('requirement-not-met');
                    requirements.uppercase.classList.add('requirement-met');
                } else {
                    requirements.uppercase.classList.remove('requirement-met');
                    requirements.uppercase.classList.add('requirement-not-met');
                }

                // Check lowercase
                if (/[a-z]/.test(password)) {
                    requirements.lowercase.classList.remove('requirement-not-met');
                    requirements.lowercase.classList.add('requirement-met');
                } else {
                    requirements.lowercase.classList.remove('requirement-met');
                    requirements.lowercase.classList.add('requirement-not-met');
                }

                // Check number
                if (/[0-9]/.test(password)) {
                    requirements.number.classList.remove('requirement-not-met');
                    requirements.number.classList.add('requirement-met');
                } else {
                    requirements.number.classList.remove('requirement-met');
                    requirements.number.classList.add('requirement-not-met');
                }

                // Check special character
                if (/[!@#$%^&*()\-_=+{};:,<.>]/.test(password)) {
                    requirements.special.classList.remove('requirement-not-met');
                    requirements.special.classList.add('requirement-met');
                } else {
                    requirements.special.classList.remove('requirement-met');
                    requirements.special.classList.add('requirement-not-met');
                }
            });
            // Show/hide toggles
            function bindToggle(btnId, inputId) {
                const btn = document.getElementById(btnId);
                const input = document.getElementById(inputId);
                if (!btn || !input) return;
                btn.addEventListener('click', function() {
                    const type = input.getAttribute('type') === 'password' ? 'text' : 'password';
                    input.setAttribute('type', type);
                    btn.innerHTML = type === 'text' ? '<i data-feather="eye-off"></i>' : '<i data-feather="eye"></i>';
                    feather.replace();
                });
            }
            bindToggle('toggle_current', 'current_password');
            bindToggle('toggle_new', 'new_password');
            bindToggle('toggle_confirm', 'confirm_password');
        });

        feather.replace();
    </script>
</body>
</html>