<?php

declare(strict_types=1);

namespace Vagabit\FOSJsRoutingSecurityBundle\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Vagabit\FOSJsRoutingSecurityBundle\Kernel;

class JsRoutingControllerWebTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        parent::getKernelClass();
        return Kernel::class;
    }

    public function testGetRoutesEndpointAnonymous(): void
    {
        $client = static::createClient();
        $client->request('GET', '/vagabit-secure-js-routes.json');

        $this->assertResponseIsSuccessful();
        $this->assertTrue($client->getResponse()->headers->hasCacheControlDirective('private'));
        $this->assertTrue($client->getResponse()->headers->hasCacheControlDirective('no-store'));

        $content = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertIsArray($content);
        $this->assertArrayHasKey('routes', $content);

        $routes = $content['routes'];
        $this->assertArrayHasKey('public_home', $routes);
        $this->assertArrayNotHasKey('admin_dashboard', $routes);
        $this->assertArrayNotHasKey('account_profile', $routes);
        $this->assertArrayNotHasKey('manager_reports', $routes);
    }

    public function testGetRoutesEndpointAdmin(): void
    {
        $client = static::createClient();
        $client->request('GET', '/vagabit-secure-js-routes.json', [], [], [
            'PHP_AUTH_USER' => 'admin',
            'PHP_AUTH_PW' => 'password',
        ]);

        $this->assertResponseIsSuccessful();
        $content = json_decode((string) $client->getResponse()->getContent(), true);

        $routes = $content['routes'];
        $this->assertArrayHasKey('public_home', $routes);
        $this->assertArrayHasKey('admin_dashboard', $routes);
        $this->assertArrayNotHasKey('manager_reports', $routes);
    }

    public function testGetRoutesEndpointManager(): void
    {
        $client = static::createClient();
        $client->request('GET', '/vagabit-secure-js-routes.json', [], [], [
            'PHP_AUTH_USER' => 'manager',
            'PHP_AUTH_PW' => 'password',
        ]);

        $this->assertResponseIsSuccessful();
        $content = json_decode((string) $client->getResponse()->getContent(), true);

        $routes = $content['routes'];
        $this->assertArrayHasKey('public_home', $routes);
        $this->assertArrayHasKey('manager_reports', $routes);
        $this->assertArrayNotHasKey('admin_dashboard', $routes);
    }

    public function testGetRoutesEndpointUser(): void
    {
        $client = static::createClient();
        $client->request('GET', '/vagabit-secure-js-routes.json', [], [], [
            'PHP_AUTH_USER' => 'user',
            'PHP_AUTH_PW' => 'password',
        ]);

        $this->assertResponseIsSuccessful();
        $content = json_decode((string) $client->getResponse()->getContent(), true);

        $routes = $content['routes'];
        $this->assertArrayHasKey('public_home', $routes);
        $this->assertArrayHasKey('account_profile', $routes);
        $this->assertArrayNotHasKey('admin_dashboard', $routes);
        $this->assertArrayNotHasKey('manager_reports', $routes);
    }

    public function testGetRoutesEndpointWithCallback(): void
    {
        $client = static::createClient();
        $client->request('GET', '/vagabit-secure-js-routes.json?callback=fos.Router.setData');

        $this->assertResponseIsSuccessful();
        $this->assertStringStartsWith('text/javascript', (string) $client->getResponse()->headers->get('Content-Type'));
        $content = (string) $client->getResponse()->getContent();
        $this->assertStringStartsWith('/**/fos.Router.setData({', $content);
        $this->assertStringEndsWith(');', $content);
    }

    public function testGetRoutesEndpointWithInvalidCallbackReturns400(): void
    {
        $client = static::createClient();
        $client->request('GET', '/vagabit-secure-js-routes.json?callback=invalid(callback)');

        $this->assertResponseStatusCodeSame(400);
    }
}
