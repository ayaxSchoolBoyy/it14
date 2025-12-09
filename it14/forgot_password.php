<?php
// forgot_password.php - Password recovery page
require_once 'config.php';

$message = '';
$error = '';

function sendPasswordResetEmail($recipientEmail, $recipientName, $resetLink) {
    if (empty($recipientEmail) || !filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $displayName = $recipientName ?: 'UMTC Admin';
    $subject = 'UMTC Announcement System Password Reset';
    $body = "Hello {$displayName},\n\n" .
        "A password reset was requested for your UMTC Announcement System account. " .
        "If you made this request, open the link below within the next hour to set a new password.\n\n" .
        $resetLink . "\n\n" .
        "If you did not request this change, you can safely ignore this email. Your existing password will remain active.\n\n" .
        "— UMTC Announcement System";

    $headers = "From: UMTC Announcement System <no-reply@umtc.local>\r\n" .
        "Reply-To: no-reply@umtc.local\r\n" .
        "Content-Type: text/plain; charset=UTF-8\r\n";

    return @mail($recipientEmail, $subject, $body, $headers);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email']);
    
    if (!empty($email)) {
        try {
            // Check if email exists
            $stmt = $pdo->prepare("SELECT id, username, full_name, email FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch();
            
            $genericSuccessMessage = "If an account with that email exists, we'll send instructions to reset the password. Please check your inbox and spam folder.";

            if ($user) {
                // Generate secure reset token
                $reset_token = bin2hex(random_bytes(32));
                $reset_token_expiry = date('Y-m-d H:i:s', strtotime('+1 hour')); // Token valid for 1 hour
                
                // Store token in database
                $stmt = $pdo->prepare("UPDATE users SET reset_token = ?, reset_token_expiry = ? WHERE id = ?");
                $stmt->execute([$reset_token, $reset_token_expiry, $user['id']]);
                
                // Create reset link
                $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
                $basePath = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
                $basePath = ($basePath === '.' ? '' : $basePath);
                $reset_link = $protocol . "://" . $_SERVER['HTTP_HOST'] . $basePath . "/reset_password.php?token=" . $reset_token;

                $mailSent = sendPasswordResetEmail($user['email'], $user['full_name'] ?? $user['username'], $reset_link);

                if (!$mailSent) {
                    error_log("Password reset email failed to send for user: " . $user['username'] . " (" . $email . ")");
                } else {
                    error_log("Password reset email sent for user: " . $user['username'] . " (" . $email . ")");
                }

                $message = $genericSuccessMessage;
            } else {
                // Don't reveal whether email exists or not for security
                $message = $genericSuccessMessage;
            }
        } catch (Exception $e) {
            $error = "An error occurred. Please try again later.";
            error_log("Forgot password error: " . $e->getMessage());
        }
    } else {
        $error = "Please enter your email address.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - UMTC Announcement System</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/feather-icons"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap');
        
        body {
            background: linear-gradient(135deg, #fab3b3ff 0%, #832222ff 100%);
            font-family: 'Poppins', sans-serif;
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 2rem;
        }
        
        .forgot-card {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 1rem;
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.15);
            overflow: hidden;
            width: 100%;
            max-width: 500px;
            transition: all 0.3s ease;
        }
        
        .forgot-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 25px 60px rgba(0, 0, 0, 0.2);
        }
        
        .forgot-header {
            background: linear-gradient(135deg, #4b1818ff 0%, #bd3434ff 100%);
            color: white;
            padding: 2rem;
            text-align: center;
        }
        
        .forgot-form {
            padding: 2rem;
        }
        
        .input-group {
            position: relative;
            margin-bottom: 1.5rem;
        }
        
        .input-icon {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: #ea6666ff;
        }
        
        .form-input {
            width: 100%;
            padding: 0.75rem 1rem 0.75rem 3rem;
            border: 2px solid #e2e8f0;
            border-radius: 0.5rem;
            font-size: 1rem;
            transition: all 0.3s;
        }
        
        .form-input:focus {
            outline: none;
            border-color: #ff7676ff;
            box-shadow: 0 0 0 3px rgba(0, 0, 0, 0.2);
        }
        
        .submit-btn {
            background: linear-gradient(135deg, #4b1818ff 0%, #bd3434ff 100%);
            color: white;
            border: none;
            padding: 0.75rem;
            border-radius: 0.5rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            width: 100%;
            margin-top: 1rem;
        }
        
        .submit-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(234, 102, 102, 0.4);
        }
        
        .back-link {
            display: inline-flex;
            align-items: center;
            color: #e26e6eff;
            transition: all 0.3s;
            text-decoration: none;
        }
        
        .back-link:hover {
            color: #ea6666ff;
            transform: translateX(-3px);
        }
        
        .success-message {
            background-color: #d1fae5;
            color: #065f46;
            padding: 1rem;
            border-radius: 0.5rem;
            margin-bottom: 1.5rem;
            font-size: 0.875rem;
            border-left: 4px solid #10b981;
        }
        
        .error-message {
            background-color: #fee2e2;
            color: #dc2626;
            padding: 0.75rem;
            border-radius: 0.5rem;
            margin-bottom: 1.5rem;
            font-size: 0.875rem;
            border-left: 4px solid #ef4444;
        }
        
        .info-text {
            color: #6b7280;
            font-size: 0.875rem;
            text-align: center;
            margin-bottom: 1.5rem;
            line-height: 1.5;
        }
        
        .tips-box {
            background-color: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 0.5rem;
            padding: 1rem;
            margin-bottom: 1.5rem;
            font-size: 0.875rem;
            color: #4b5563;
        }
    </style>
</head>
<body>
    <div class="forgot-card">
        <div class="forgot-header">
            <div class="flex items-center justify-center gap-2 text-xl font-bold">
                <i data-feather="key"></i>
                <span>Reset Password</span>
            </div>
            <p class="text-white/90 mt-2">Enter your email to reset your password</p>
        </div>
        
        <div class="forgot-form">
            <?php if (!empty($message)): ?>
                <div class="success-message">
                    <i data-feather="check-circle" class="inline mr-1"></i>
                    <div class="inline-block">
                        <?php echo $message; ?>
                    </div>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($error)): ?>
                <div class="error-message">
                    <i data-feather="alert-circle" class="inline mr-1"></i>
                    <?php echo $error; ?>
                </div>
            <?php endif; ?>
            
            <?php if (empty($message)): ?>
            <div class="tips-box">
                <strong class="block mb-1">Security reminder</strong>
                Use your registered UMTC email address. We'll email you a reset link that expires in one hour.
            </div>

            <form method="POST" action="">
                <div class="input-group">
                    <div class="input-icon">
                        <i data-feather="mail"></i>
                    </div>
                    <input type="email" class="form-input" id="email" name="email" placeholder="Enter your email address" required value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                </div>
                
                <button type="submit" class="submit-btn">
                    <i data-feather="send" class="inline mr-2"></i>
                    Send Reset Link
                </button>
            </form>
            <?php endif; ?>
            
            <div class="mt-4 text-center">
                <a href="login.php" class="back-link">
                    <i data-feather="arrow-left" class="mr-1"></i>
                    Back to Login
                </a>
            </div>
        </div>
    </div>

    <script>
        feather.replace();
    </script>
</body>
</html>