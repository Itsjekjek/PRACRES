<?php
// DEVELOPMENT ONLY: show errors while debugging.
// Remove or disable these lines on a production server.
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start([
    'cookie_httponly' => true,
    'cookie_secure'   => !empty($_SERVER['HTTPS']),
    'cookie_samesite' => 'Strict'
]);

$host = 'localhost';
$user = 'root';
$pass = '';
$db   = 'code_typing_game';

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$error = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';

    // Basic validation
    if (empty($username) || empty($password) || empty($confirm)) {
        $error = "All fields are required.";
    } elseif ($password !== $confirm) {
        $error = "Passwords do not match.";
    } elseif (strlen($password) < 6) {
        $error = "Password must be at least 6 characters long.";
    } elseif (!preg_match('/^[a-zA-Z0-9_]{3,50}$/', $username)) {
        $error = "Username can only contain letters, numbers and underscore (3-50 characters).";
    } else {
        // Check if username already exists
        $stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
        if (!$stmt) {
            $error = "Database error: " . $conn->error;
        } else {
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $stmt->store_result();

            if ($stmt->num_rows > 0) {
                $error = "Username already taken.";
                $stmt->close();
            } else {
                $stmt->close();

                // Hash password and insert new user
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);

                $stmt_insert = $conn->prepare("
                    INSERT INTO users (username, password, created_at)
                    VALUES (?, ?, NOW())
                ");

                if (!$stmt_insert) {
                    $error = "Database error: " . $conn->error;
                } else {
                    $stmt_insert->bind_param("ss", $username, $hashed_password);

                    if ($stmt_insert->execute()) {
                        // Auto-login after successful registration
                        $new_user_id = $conn->insert_id;
                        $_SESSION['user_id']  = $new_user_id;
                        $_SESSION['username'] = $username;

                        header("Location: index.php");
                        exit;
                    } else {
                        $error = "Registration failed. Please try again.";
                    }

                    $stmt_insert->close();
                }
            }
        }
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TypePro - Sign Up</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
    <style>
        body { font-family: 'JetBrains Mono', monospace; }
    </style>
</head>
<body class="bg-gray-900 min-h-screen flex items-center justify-center">

    <div class="w-full max-w-md bg-gray-800 p-10 rounded-xl border border-gray-700 shadow-2xl">
        <h1 class="text-4xl font-bold text-center mb-8 text-white">Sign Up</h1>

        <?php if ($error): ?>
        <div class="bg-red-900/60 border border-red-700 text-red-100 px-4 py-3 rounded mb-6 text-center">
            <?= htmlspecialchars($error) ?>
        </div>
        <?php endif; ?>

        <form method="POST" class="space-y-6">
            <div>
                <label class="block text-gray-300 mb-2">Username</label>
                <input type="text" name="username" required autofocus
                       value="<?= htmlspecialchars($username ?? '') ?>"
                       class="w-full bg-gray-700 border border-gray-600 rounded px-4 py-3 text-white focus:outline-none focus:border-blue-500">
            </div>

            <div>
                <label class="block text-gray-300 mb-2">Password</label>
                <input type="password" name="password" required
                       class="w-full bg-gray-700 border border-gray-600 rounded px-4 py-3 text-white focus:outline-none focus:border-blue-500">
            </div>

            <div>
                <label class="block text-gray-300 mb-2">Confirm Password</label>
                <input type="password" name="confirm_password" required
                       class="w-full bg-gray-700 border border-gray-600 rounded px-4 py-3 text-white focus:outline-none focus:border-blue-500">
            </div>

            <button type="submit"
                    class="w-full bg-green-600 hover:bg-green-700 text-white font-bold py-3 rounded transition duration-200">
                CREATE ACCOUNT
            </button>
        </form>

        <p class="text-center mt-8 text-gray-400">
            Already have an account?
            <a href="login.php" class="text-blue-400 hover:underline">Log in</a>
        </p>
    </div>

</body>
</html>