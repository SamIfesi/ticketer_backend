<?php

// ADMIN ROUTES
// All protected: admin or dev only

// Platform stats
$router->get('/api/admin/stats', [AdminController::class, 'stats'], [AuthMiddleware::class, RoleMiddleware::class => ['admin', 'dev']]);

// User management
$router->get('/api/admin/users',                    [AdminController::class, 'users'],             [AuthMiddleware::class, RoleMiddleware::class => ['admin', 'dev']]);
$router->get('/api/admin/users/:id',                [AdminController::class, 'showUser'],           [AuthMiddleware::class, RoleMiddleware::class => ['admin', 'dev']]);
$router->put('/api/admin/users/:id/role',           [AdminController::class, 'updateRole'],         [AuthMiddleware::class, RoleMiddleware::class => ['admin', 'dev']]);
$router->put('/api/admin/users/:id/status',         [AdminController::class, 'updateStatus'],       [AuthMiddleware::class, RoleMiddleware::class => ['admin', 'dev']]);

// Event management
$router->get('/api/admin/events',                   [AdminController::class, 'events'],             [AuthMiddleware::class, RoleMiddleware::class => ['admin', 'dev']]);
$router->put('/api/admin/events/:id/status',        [AdminController::class, 'updateEventStatus'],  [AuthMiddleware::class, RoleMiddleware::class => ['admin', 'dev']]);
