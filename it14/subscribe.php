<?php
require_once 'config.php';

// Handle unsubscribe via token
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['unsubscribe_token'])) {
    $result = unsubscribeCourseByToken($_GET['unsubscribe_token']);
    $status = $result['success'] ? 'Success' : 'Error';
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?php echo htmlspecialchars($status); ?> - Galendar Subscriptions</title>
        <link rel="stylesheet" href="includes/design-system.css">
        <script src="https://cdn.tailwindcss.com"></script>
    </head>
    <body class="min-h-screen bg-gray-50 flex items-center justify-center p-6">
        <div class="bg-white shadow-xl rounded-2xl p-8 max-w-lg w-full text-center">
            <h1 class="text-2xl font-bold text-gray-900 mb-3"><?php echo htmlspecialchars($status); ?></h1>
            <p class="text-gray-600 mb-6"><?php echo htmlspecialchars($result['message']); ?></p>
            <a href="index.php" class="inline-flex items-center px-5 py-3 rounded-full bg-red-600 text-white font-semibold hover:bg-red-500">Return to Announcements</a>
        </div>
    </body>
    </html>
    <?php
    exit();
}

// Handle subscribe via POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $email = $_POST['email'] ?? '';
    $program_id = $_POST['program_id'] ?? '';
    $result = createCourseSubscription($email, $program_id);
    echo json_encode($result);
    exit();
}

// Fallback for unsupported methods
http_response_code(405);
echo 'Method not allowed';
