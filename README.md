# Gaming Platform Backend API

Backend REST API for a gaming platform with real-time chat functionality, user authentication, and game management.

## Core Features

- User Authentication (JWT)
- Game Management (CRUD)
- Real-time Chat (WebSocket)
- User Feedback System
- Admin Dashboard
- Avatar Upload System
- Game Like/Unlike System

## Tech Stack

- PHP 8.1+
- MySQL
- WebSocket (Ratchet)
- JWT Authentication
- Monolog for Logging

## Project Structure

```
├── api/                  # API endpoints
├── config/              # Configuration files
├── helpers/             # Helper functions
├── logs/                # Application logs
├── sessions/            # PHP sessions
├── src/                 # Source code
├── uploads/             # User uploads
└── websocket_server.php # WebSocket server
```

## API Endpoints

### Authentication
- POST `/api/login.php` - User login
- POST `/api/register.php` - User registration
- POST `/api/logout.php` - User logout

### Games
- GET `/api/games.php` - List games
- GET `/api/game.php` - Get game details
- POST `/api/like_game.php` - Like a game
- DELETE `/api/unlike_game.php` - Unlike a game

### User Management
- GET `/api/user.php` - User profile
- POST `/api/upload_avatar.php` - Upload avatar
- GET `/api/admin.php` - Admin dashboard

### Chat
- GET `/api/get_messages.php` - Get chat messages
- POST `/api/send_message.php` - Send message

### Feedback
- GET `/api/feedback.php` - Get feedback
- POST `/api/feedback_management.php` - Manage feedback

## Setup

1. Clone repository
2. Install dependencies:
```bash
composer install
```
3. Configure database in `config/config.php`
4. Start WebSocket server:
```bash
php websocket_server.php
```

## Dependencies

- monolog/monolog: Logging
- cboden/ratchet: WebSocket server
- firebase/php-jwt: JWT authentication

## Security Features

- JWT-based authentication
- Session management
- Input validation
- File upload restrictions
- Admin authorization
