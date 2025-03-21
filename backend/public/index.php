<?php

use DI\Container;
use Slim\Factory\AppFactory;
use App\Controllers\ApiController;

require __DIR__ . '/../vendor/autoload.php';

// Load environment variables
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

// Create Container
$container = new Container();
AppFactory::setContainer($container);

// Create App
$app = AppFactory::create();

// Add Error Middleware
$app->addErrorMiddleware(true, true, true);

// Add CORS middleware
$app->add(function ($request, $handler) {
    $response = $handler->handle($request);
    return $response
        ->withHeader('Access-Control-Allow-Origin', '*')
        ->withHeader('Access-Control-Allow-Headers', 'X-Requested-With, Content-Type, Accept, Origin, Authorization')
        ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, PATCH, OPTIONS');
});

// Define routes
$app->get('/', function ($request, $response) {
    $response->getBody()->write(json_encode(['message' => 'Welcome to the API']));
    return $response->withHeader('Content-Type', 'application/json');
});

// API Routes
$app->group('/api', function ($group) {
    $group->get('/items', [ApiController::class, 'getItems']);
    $group->get('/items/{id}', [ApiController::class, 'getItem']);
    $group->post('/items', [ApiController::class, 'createItem']);
    $group->put('/items/{id}', [ApiController::class, 'updateItem']);
    $group->delete('/items/{id}', [ApiController::class, 'deleteItem']);
});

// Run app
$app->run(); 