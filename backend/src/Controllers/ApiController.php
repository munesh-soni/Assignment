<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class ApiController
{
    public function getItems(Request $request, Response $response): Response
    {
        $data = [
            ['id' => 1, 'name' => 'Item 1'],
            ['id' => 2, 'name' => 'Item 2'],
            ['id' => 3, 'name' => 'Item 3']
        ];

        $response->getBody()->write(json_encode($data));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function getItem(Request $request, Response $response, array $args): Response
    {
        $id = $args['id'];
        $data = ['id' => $id, 'name' => "Item {$id}"];

        $response->getBody()->write(json_encode($data));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function createItem(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        
        // Here you would typically save to database
        $responseData = [
            'message' => 'Item created successfully',
            'data' => $data
        ];

        $response->getBody()->write(json_encode($responseData));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(201);
    }

    public function updateItem(Request $request, Response $response, array $args): Response
    {
        $id = $args['id'];
        $data = $request->getParsedBody();
        
        // Here you would typically update in database
        $responseData = [
            'message' => "Item {$id} updated successfully",
            'data' => $data
        ];

        $response->getBody()->write(json_encode($responseData));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function deleteItem(Request $request, Response $response, array $args): Response
    {
        $id = $args['id'];
        
        // Here you would typically delete from database
        $responseData = [
            'message' => "Item {$id} deleted successfully"
        ];

        $response->getBody()->write(json_encode($responseData));
        return $response->withHeader('Content-Type', 'application/json');
    }
} 