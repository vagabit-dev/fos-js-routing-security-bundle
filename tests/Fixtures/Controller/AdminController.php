<?php

declare(strict_types=1);

namespace Vagabit\FOSJsRoutingSecurityBundle\Tests\Fixtures\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class AdminController extends AbstractController
{
    #[Route('/admin/dashboard', name: 'admin_dashboard', options: ['expose' => true])]
    public function dashboard(): Response
    {
        return new JsonResponse(['status' => 'admin_dashboard']);
    }

    #[Route('/account/profile', name: 'account_profile', options: ['expose' => true])]
    public function profile(): Response
    {
        return new JsonResponse(['status' => 'account_profile']);
    }

    #[Route('/manager/reports', name: 'manager_reports', options: ['expose' => true, 'role' => 'ROLE_MANAGER'])]
    public function managerReports(): Response
    {
        return new JsonResponse(['status' => 'manager_reports']);
    }

    #[Route('/public/home', name: 'public_home', options: ['expose' => true])]
    public function publicHome(): Response
    {
        return new JsonResponse(['status' => 'public_home']);
    }
}
