<?php
// api/register.php

require_once '../config/config.php';
require_once '../helpers/response.php';
require_once '../helpers/image.php';
require_once '../helpers/auth.php';

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(['error' => 'Invalid request method'], 405);
}

// Get input data
$input = json_decode(file_get_contents('php://input'), true);

$requiredFields = ['firstName', 'lastName', 'nickname', 'email', 'password'];

foreach ($requiredFields as $field) {
    if (empty($input[$field])) {
        sendResponse(['error' => "$field is required"], 400);
    }
}

// Sanitize inputs
$firstName = htmlspecialchars(trim($input['firstName']));
$lastName = htmlspecialchars(trim($input['lastName']));
$nickname = htmlspecialchars(trim($input['nickname']));
$email = filter_var(trim($input['email']), FILTER_VALIDATE_EMAIL);
$password = trim($input['password']);

if (!$email) {
    sendResponse(['error' => 'Invalid email format'], 400);
}

// Check if nickname or email already exists
$stmt = $pdo->prepare("SELECT user_id FROM users WHERE user_nickname = :nickname OR email = :email");
$stmt->execute(['nickname' => $nickname, 'email' => $email]);
if ($stmt->fetch()) {
    sendResponse(['error' => 'Nickname or email already exists'], 409);
}

// Hash password
$hashedPassword = password_hash($password, PASSWORD_DEFAULT);

// Handle avatar upload if exists
$avatarFilename = null;
if (isset($_FILES['avatar'])) {
    $avatarResult = processAvatarImage($_FILES['avatar']);
    if (isset($avatarResult['error'])) {
        sendResponse(['error' => $avatarResult['error']], 400);
    }
    $avatarFilename = $avatarResult['filename'];
}

// Insert user into database
$stmt = $pdo->prepare("INSERT INTO users (first_name, last_name, email, user_nickname, user_password, avatar_filename) 
                       VALUES (:first_name, :last_name, :email, :nickname, :password, :avatar)");

try {
    $stmt->execute([
        'first_name' => $firstName,
        'last_name' => $lastName,
        'email' => $email,
        'nickname' => $nickname,
        'password' => $hashedPassword,
        'avatar' => $avatarFilename,
    ]);

    // Optionally, auto-login the user after registration
    $userId = $pdo->lastInsertId();
    $_SESSION['user_id'] = $userId;
    $_SESSION['last_activity'] = time();

    sendResponse(['message' => 'User registered successfully'], 201);
} catch (PDOException $e) {
    sendResponse(['error' => 'Registration failed: ' . $e->getMessage()], 500);
}
?>
