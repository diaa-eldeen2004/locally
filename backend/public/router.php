<?php

declare(strict_types=1);

header('Content-Type: application/json');

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// remove /api prefix
$route = str_replace('/api', '', $uri);

// simple router
switch ($route) {

    case '':
    case '/':
        echo json_encode([
            "message" => "API is working"
        ]);
        break;

    case '/test':
        echo json_encode([
            "status" => "ok"
        ]);
        break;

    default:
        http_response_code(404);
        echo json_encode([
            "error" => "Route not found",
            "path" => $route
        ]);
        break;
}