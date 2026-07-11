<?php

declare(strict_types=1);

defined('BASED') || exit;

Router::get('/health', 'handle_health');

Router::post('/auth/register', 'handle_register');
Router::post('/auth/login', 'handle_login');
Router::get('/auth/me', 'handle_me', ['Auth::required']);

Router::get('/users', 'handle_users_index', ['Auth::required']);
Router::get('/users/:id', 'handle_users_show', ['Auth::required']);
Router::patch('/users/:id', 'handle_users_update', ['Auth::required']);
Router::delete('/users/:id', 'handle_users_delete', ['Auth::required']);
