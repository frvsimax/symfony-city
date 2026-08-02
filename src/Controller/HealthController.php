<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HealthController
{
    #[Route('/', name: 'home', methods: ['GET'])]
    public function home(): Response
    {
        return new JsonResponse([
            'app' => 'symfony-city',
            'status' => 'ok',
        ]);
    }

    #[Route('/health', name: 'health', methods: ['GET'])]
    public function health(Connection $connection): JsonResponse
    {
        $connection->executeQuery('SELECT 1');

        return new JsonResponse([
            'status' => 'ok',
            'database' => 'ok',
        ]);
    }
}
