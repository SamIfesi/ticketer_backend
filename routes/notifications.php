<?php

// routes/notifications.php
// NOTE: /unread MUST be before /:id — otherwise router matches
// "unread" as an :id param. Already in correct order here.
 
$router->get('/api/notifications/unread',       [NotificationController::class, 'unreadCount'], [AuthMiddleware::class]);
$router->get('/api/notifications',              [NotificationController::class, 'index'],       [AuthMiddleware::class]);
$router->put('/api/notifications/read-all',     [NotificationController::class, 'markAllRead'], [AuthMiddleware::class]);
$router->put('/api/notifications/:id/read',     [NotificationController::class, 'markRead'],    [AuthMiddleware::class]);
$router->delete('/api/notifications/:id',       [NotificationController::class, 'destroy'],     [AuthMiddleware::class]);