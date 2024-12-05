<?php
// api/login.php

require_once '../config/config.php';
require_once '../helpers/response.php';
require_once '../helpers/auth.php';

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(['error' => 'Invalid request method'], 405);
}

// Get input data
$input = json_decode(file_get_contents('php://input'), true);

if (empty($input['identifier']) || empty($input['password'])) {
    sendResponse(['error' => 'Identifier and password are required'], 400);
}

// Sanitize inputs
$identifier = trim($input['identifier']);
$password = trim($input['password']);

// Find user by email or nickname
$stmt = $pdo->prepare("SELECT user_id, user_password, is_blocked, user_role FROM users WHERE email = :identifier OR user_nickname = :identifier");
$stmt->execute(['identifier' => $identifier]);
$user = $stmt->fetch();

if (!$user || !password_verify($password, $user['user_password'])) {
    sendResponse(['error' => 'Invalid credentials'], 401);
}

// Check if user is blocked
if ($user['is_blocked']) {
    sendResponse(['error' => 'Your account has been blocked.'], 403);
}

// Successful login, set session
$_SESSION['user_id'] = $user['user_id'];
$_SESSION['user_role'] = $user['user_role'];
$_SESSION['last_activity'] = time();

sendResponse(['message' => 'Login successful'], 200);
?>
