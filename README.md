# Gaming Platform Backend API

## Key Features

- **User Management**: Register, login, logout, update profiles, upload avatars.
- **Role-Based Access**: Admin users can block/unblock users, delete messages/feedback, and assign/remove admin rights.
- **Game Management**: View game lists, search/filter them, like/unlike games, and view game details.
- **Feedback System**: Users can leave feedback (rating and comment) on games. Feedback affects the game's aggregated rating.
- **Real-Time Chat**: A WebSocket server (Ratchet) for sending and receiving messages in real-time.

## Technology Stack

- **Backend**: PHP 8.1+ with PDO (MySQL)
- **Real-Time**: Ratchet WebSocket server
- **Logging**: Monolog
- **Database**: MySQL
- **Sessions**: PHP sessions stored on the filesystem
- **HTTP**: RESTful APIs returning JSON responses

## Authentication and Authorization

- **Authentication**: Performed by `api/login.php`. On successful login, a session is established and `$_SESSION['user_id']` is set.
- **Session Validation**: `helpers/auth.php` checks `$_SESSION['user_id']` to determine if a user is logged in. `requireLogin()` denies access if not authenticated.
- **Admin Access**: Certain endpoints (e.g., `api/admin.php`) require the logged-in user to have `user_role = 'admin'`.
- **Blocking**: If a user is blocked (`is_blocked = 1` in DB), their session is invalidated and they cannot access secured endpoints.

## API Endpoints Detail

### Authentication

**POST `/api/login.php`**  
**Params (JSON)**:  
- `identifier` (string): user’s email or nickname  
- `password` (string)  

**Response**:  
- `200 { "message": "Login successful" }` on success  
- `401 { "error": "Invalid credentials" }` on failure

**POST `/api/register.php`**  
**Params (JSON)**:  
- `firstName`, `lastName`, `nickname`, `email`, `password`  
**Optional**: avatar file upload (via `$_FILES['avatar']`)  

**Response**:  
- `201 { "message": "User registered successfully" }` on success  
- `409 { "error": "Nickname or email already exists" }` if duplicate

**POST `/api/logout.php`**  
**Params**: None  
**Response**:  
- `200 { "message": "Logged out successfully" }` on success

### User Profile

**GET `/api/user.php`**  
- If logged in, returns user info. Admins or the user themselves get more detailed info (email, first/last name), otherwise restricted data is returned.  
**Params (Query)**:  
- `user_id` (optional, integer): if omitted, returns current user’s data. If provided, requires admin privileges or must match current user.  

**Response**:  
- `200 { "user": { ... } }` with user details  
- `401 { "error": "Unauthorized" }` if not logged in

**PUT `/api/user.php`**  
- Update user profile fields (first_name, last_name, user_nickname, email). Admins can update other users’ profiles, regular users can only update their own.  
**Params (JSON)**:  
- `userId` (optional, integer): target user’s ID (admin only)  
- Fields to update: `first_name`, `last_name`, `user_nickname`, `email`

**Response**:  
- `200 { "message": "Profile updated successfully" }` on success  
- `409 { "error": "Email/Nickname already exists" }` on conflict

**POST `/api/upload_avatar.php`**  
- Upload a new avatar image.  
**Params**: `avatar` file upload

**Response**:  
- `200 { "message": "Avatar updated successfully", "avatar_url": "uploads/avatars/..." }`

### Games
=======
- GET `/api/games.php` - List games
- GET `/api/game.php` - Get game details
- POST `/api/like_game.php` - Like a game
- DELETE `/api/unlike_game.php` - Unlike a game

**GET `/api/games.php`**  
- Lists games with optional filters.  
**Query Params**:  
- `type` (string): `popular`, `new`, `old`, `all`, `random_popular`  
- `count` (int): max number of results (default 10, max 100)  
- `offset` (int): offset for pagination  
- `favorite` (bool): if true, shows only favorites for logged-in user  
- `search` (string): full-text search by game name

**Response**:  
- `200 { "games": [ { ... }, ... ] }`

**GET `/api/game.php?id={game_id}`**  
- Fetch details of a single game (genres, tags, rating, feedbacks, etc.).  
**Params (Query)**: `id` (int) - required

**Response**:  
- `200 { "game": { ... } }` or `404 { "error": "Game not found" }`

**POST `/api/like_game.php`**  
- Adds a game to the user’s favorites. User must be logged in.  
**Params (JSON)**:  
- `game_id` (int)

**Response**:  
- `201 { "message": "Game added to favorites" }`  
- `409 { "error": "Game already in favorites" }` if duplicate

**DELETE `/api/unlike_game.php`**  
- Removes a game from favorites. User must be logged in.  
**Params (JSON)**:  
- `game_id` (int)

**Response**:  
- `200 { "message": "Game removed from favorites" }`  
- `404 { "error": "Game not in favorites" }`

### Feedback

**POST `/api/feedback.php`**  
- Submit feedback (rating and comment) for a game.  
**Params (JSON)**:  
- `game_id` (int)  
- `comment` (string)  
- `rating` (float, 0.5 to 5.0 in increments of 0.5)

**Response**:  
- `201 { "message": "Feedback submitted successfully", "feedback_id": ... }`  
- `409 { "error": "You have already submitted feedback..." }` if duplicate

**GET `/api/feedback_management.php?game_id={id}`**  
- Fetch all feedback for a specified game.  
**Response**:  
- `200 { "feedbacks": [ ... ] }`

**DELETE `/api/feedback_management.php`**  
- Delete feedback if user is the author or an admin.  
**Params (JSON)**:  
- `feedback_id` (int)

**Response**:  
- `200 { "message": "Feedback deleted successfully" }`

### Admin Actions

**POST `/api/admin.php`**  
- Admin-only endpoint for user and content moderation.  
**Params (JSON)**:  
- `action`: "block_user", "delete_message", "delete_comment", "edit_profile", "assign_admin", "toggle_admin"  
- Additional fields depend on the chosen action (e.g., `user_id`, `message_id`, `comment_id`, `data` for profile edits, etc.)

**Response**:  
- `200 { "message": "..." }` on success  
- `403 { "error": "Access denied" }` if not admin or invalid action

### Chat

**GET `/api/get_messages.php`**  
- Retrieve the most recent messages. Can filter by `user_id` to get messages with a specific user.  
**Query Params**:  
- `user_id` (int, optional)

**Response**:  
- `200 { "messages": [ ... ] }`

**POST `/api/send_message.php`**  
- Send a public message (logged-in users only).  
**Params (JSON)**:  
- `content` (string)

**Response**:  
- `200 { "message": "Message sent successfully" }`

### Real-Time Chat (WebSocket)

- The `websocket_server.php` runs a WebSocket on `ws://localhost:8080/ws`.
- After authenticating via PHP session (retrieved from cookies), users can send and receive real-time messages.
- Private messages can be directed to a specific user if both are connected.
