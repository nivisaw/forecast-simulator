<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

$config = require_once __DIR__ . '/../config/config.php';

$uri      = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$basePath = rtrim(dirname((string) $_SERVER['SCRIPT_NAME']), '/');
$route    = str_replace($basePath, '', $uri);
$route    = str_replace('/index.php', '', $route);

if ($route === '') {
    $route = '/';
}

$optimizationController = new \App\Presentation\Controller\OptimizationController($config);
$geocodingController    = new \App\Presentation\Controller\GeocodingController($config);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($route === '/calculate' && $method === 'POST') {
    $optimizationController->calculate();
} elseif ($route === '/zones' && $method === 'GET') {
    $optimizationController->getZones();
} elseif ($route === '/search' && $method === 'GET') {
    $geocodingController->search();
} else {
    include __DIR__ . '/index.html';
}
