<?php

declare(strict_types=1);

namespace Vagabit\FOSJsRoutingSecurityBundle\Tests\Functional;

use FOS\JsRoutingBundle\Extractor\ExposedRoutesExtractorInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Vagabit\FOSJsRoutingSecurityBundle\Kernel;

class RoleBasedRoutingIntegrationTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    protected function setUp(): void
    {
        self::bootKernel(['environment' => 'test', 'debug' => true]);
    }

    public function testAnonymousUserCannotSeeAdminOrAccountRoutes(): void
    {
        $container = self::getContainer();
        /** @var ExposedRoutesExtractorInterface $extractor */
        $extractor = $container->get('fos_js_routing.extractor');

        $routes = $extractor->getRoutes();

        // Public route should be present
        $this->assertNotNull($routes->get('public_home'));

        // Admin route protected by security.yaml should NOT be present
        $this->assertNull($routes->get('admin_dashboard'));

        // Account route protected by security.yaml should NOT be present
        $this->assertNull($routes->get('account_profile'));

        // Manager route protected by custom 'role' option should NOT be present
        $this->assertNull($routes->get('manager_reports'));
    }

    public function testAdminUserCanSeeAdminAndPublicRoutes(): void
    {
        $container = self::getContainer();

        $user = new InMemoryUser('admin', 'password', ['ROLE_ADMIN']);
        $token = new UsernamePasswordToken($user, 'main', $user->getRoles());

        /** @var TokenStorageInterface $tokenStorage */
        $tokenStorage = $container->get('security.token_storage');
        $tokenStorage->setToken($token);

        /** @var ExposedRoutesExtractorInterface $extractor */
        $extractor = $container->get('fos_js_routing.extractor');
        $routes = $extractor->getRoutes();

        // Public route
        $this->assertNotNull($routes->get('public_home'));

        // Admin dashboard should be visible for ROLE_ADMIN
        $this->assertNotNull($routes->get('admin_dashboard'));

        // Manager route should NOT be visible unless user has ROLE_MANAGER
        $this->assertNull($routes->get('manager_reports'));
    }

    public function testManagerUserCanSeeManagerRouteViaCustomOption(): void
    {
        $container = self::getContainer();

        $user = new InMemoryUser('manager', 'password', ['ROLE_MANAGER']);
        $token = new UsernamePasswordToken($user, 'main', $user->getRoles());

        /** @var TokenStorageInterface $tokenStorage */
        $tokenStorage = $container->get('security.token_storage');
        $tokenStorage->setToken($token);

        /** @var ExposedRoutesExtractorInterface $extractor */
        $extractor = $container->get('fos_js_routing.extractor');
        $routes = $extractor->getRoutes();

        // Public route
        $this->assertNotNull($routes->get('public_home'));

        // Manager route should be visible for ROLE_MANAGER
        $this->assertNotNull($routes->get('manager_reports'));

        // Admin route should NOT be visible
        $this->assertNull($routes->get('admin_dashboard'));
    }

    public function testUserRoleCanSeeAccountRoute(): void
    {
        $container = self::getContainer();

        $user = new InMemoryUser('user', 'password', ['ROLE_USER']);
        $token = new UsernamePasswordToken($user, 'main', $user->getRoles());

        /** @var TokenStorageInterface $tokenStorage */
        $tokenStorage = $container->get('security.token_storage');
        $tokenStorage->setToken($token);

        /** @var ExposedRoutesExtractorInterface $extractor */
        $extractor = $container->get('fos_js_routing.extractor');
        $routes = $extractor->getRoutes();

        // Public route
        $this->assertNotNull($routes->get('public_home'));

        // Account route is visible for ROLE_USER
        $this->assertNotNull($routes->get('account_profile'));

        // Admin and manager routes are not visible
        $this->assertNull($routes->get('admin_dashboard'));
        $this->assertNull($routes->get('manager_reports'));
    }
}
