<?php
// api/user.php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once '../config/config.php';
require_once '../helpers/response.php';
require_once '../helpers/auth.php';
require_once '../helpers/image.php';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (isLoggedIn()) {
        $currentUserId = $_SESSION['user_id'];
        $isAdmin = false;

        $stmt = $pdo->prepare("SELECT user_role FROM users WHERE user_id = :user_id");
        $stmt->execute(['user_id' => $currentUserId]);
        $currentUser = $stmt->fetch();

        if ($currentUser && $currentUser['user_role'] === 'admin') {
            $isAdmin = true;
        }

        $requestedUserId = isset($_GET['user_id']) ? $_GET['user_id'] : $currentUserId;
        $fields = "user_id, user_nickname, avatar_filename, user_role, is_blocked";

        if ($isAdmin || $requestedUserId == $currentUserId) {
            $fields .= ", first_name, last_name, email, created_at";
        }

        $stmt = $pdo->prepare("SELECT $fields FROM users WHERE user_id = :requested_user_id");
        $stmt->execute(['requested_user_id' => $requestedUserId]);
        $user = $stmt->fetch();

        if ($user) {
            $user['avatar_url'] = $user['avatar_filename'] ? 'uploads/avatars/' . $user['avatar_filename'] : null;
            unset($user['avatar_filename']);
            
            // Set admin status based on user_role
            $user['admin'] = $user['user_role'] === 'admin';
            unset($user['user_role']);

            // Remove sensitive data for non-admin users viewing other profiles
            if (!$isAdmin && $requestedUserId != $currentUserId) {
                unset($user['email']);
                unset($user['first_name']);
                unset($user['last_name']);
            }

            sendResponse(['user' => $user], 200);
        } else {
            sendResponse(['error' => 'Uživatel nenalezen'], 404);
        }
    } else {
        sendResponse(['error' => 'Neautorizovaný přístup'], 401);
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    requireLogin();

    $input = json_decode(file_get_contents("php://input"), true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        sendResponse(['error' => 'Neplatný formát JSON'], 400);
    }

    // Check if user is admin
    $currentUserId = $_SESSION['user_id'];
    $stmt = $pdo->prepare("SELECT user_role FROM users WHERE user_id = :user_id");
    $stmt->execute(['user_id' => $currentUserId]);
    $currentUser = $stmt->fetch();
    $isAdmin = $currentUser && $currentUser['user_role'] === 'admin';

    // Determine which user's profile to update
    $targetUserId = isset($input['userId']) ? $input['userId'] : $currentUserId;

    // Check permissions
    if ($targetUserId !== $currentUserId && !$isAdmin) {
        sendResponse(['error' => 'Přístup odepřen. Pouze administrátoři mohou upravovat profily jiných uživatelů'], 403);
    }

    // Get current user data for comparison
    $stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = :user_id");
    $stmt->execute(['user_id' => $targetUserId]);
    $existingUser = $stmt->fetch();

    if (!$existingUser) {
        sendResponse(['error' => 'Uživatel nenalezen'], 404);
    }

    $updates = [];
    $params = ['user_id' => $targetUserId];

    // Handle field updates
    $allowedFields = ['first_name', 'last_name', 'user_nickname', 'email'];
    foreach ($allowedFields as $field) {
        if (isset($input[$field]) && $input[$field] !== $existingUser[$field]) {
            // Validate email format
            if ($field === 'email') {
                if (!filter_var($input[$field], FILTER_VALIDATE_EMAIL)) {
                    sendResponse(['error' => 'Neplatný formát emailu'], 400);
                }
                // Check if email is already used
                $stmt = $pdo->prepare("SELECT user_id FROM users WHERE email = :email AND user_id != :user_id");
                $stmt->execute(['email' => $input[$field], 'user_id' => $targetUserId]);
                if ($stmt->fetch()) {
                    sendResponse(['error' => 'Email je již používán'], 409);
                }
            }

            // Check if nickname is already used
            if ($field === 'user_nickname') {
                $stmt = $pdo->prepare("SELECT user_id FROM users WHERE user_nickname = :nickname AND user_id != :user_id");
                $stmt->execute(['nickname' => $input[$field], 'user_id' => $targetUserId]);
                if ($stmt->fetch()) {
                    sendResponse(['error' => 'Přezdívka je již používána'], 409);
                }
            }

            $updates[] = "$field = :$field";
            $params[$field] = htmlspecialchars(trim($input[$field]));
        }
    }

    if (empty($updates)) {
        sendResponse(['message' => 'Žádné změny k uložení'], 200);
        return;
    }

    try {
        $sql = "UPDATE users SET " . implode(', ', $updates) . " WHERE user_id = :user_id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        sendResponse(['message' => 'Profil byl úspěšně aktualizován'], 200);
    } catch (PDOException $e) {
        sendResponse(['error' => 'Chyba databáze: ' . $e->getMessage()], 500);
    }
} else {
    sendResponse(['error' => 'Neplatná metoda požadavku'], 405);
}
?>