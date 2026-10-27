<?php

declare(strict_types=1);

namespace Vagabit\FOSJsRoutingSecurityBundle\Command;

use FOS\JsRoutingBundle\Extractor\ExposedRoutesExtractorInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Http\AccessMapInterface;
use Vagabit\FOSJsRoutingSecurityBundle\Service\RoleFilteredJsRoutesExtractor;

/**
 * Command to inspect and debug role-based JavaScript route exposure.
 *
 * This command provides complete transparency into which application routes are exposed
 * to the frontend JavaScript router and which security roles are authorized to access them.
 *
 * Default execution:
 *   Dumps all application routes separated into two sections:
 *     1. Exposed Routes - includes route name, path, methods, and the roles authorized in JS.
 *     2. Non-Exposed Routes - routes that are not exposed to JavaScript.
 *
 * Role-filtered execution (--role):
 *   Filters and displays only exposed routes that are accessible to the specified role(s).
 *   Accepts single roles, comma-separated lists, or multiple option flags.
 *
 * Usage examples:
 *   php bin/console vagabit:js-routing:debug
 *   php bin/console vagabit:js-routing:debug --role=ROLE_ADMIN
 *   php bin/console vagabit:js-routing:debug --role=ROLE_ADMIN,ROLE_USER
 *   php bin/console vagabit:js-routing:debug -r ROLE_MANAGER
 */
#[AsCommand(
    name: 'vagabit:js-routing:debug',
    description: 'Debugs application routes, separating exposed from non-exposed routes and showing role access rules for JavaScript'
)]
class DebugJsRoutesCommand extends Command
{
    public function __construct(
        private readonly RouterInterface $router,
        private readonly ExposedRoutesExtractorInterface $extractor,
        private readonly AccessMapInterface $accessMap,
        private readonly ?TokenStorageInterface $tokenStorage = null,
        private readonly ?AuthorizationCheckerInterface $authChecker = null
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'role',
                'r',
                InputOption::VALUE_IS_ARRAY | InputOption::VALUE_OPTIONAL,
                'Filter exposed routes accessible by the specified role(s) (comma-separated or multiple flags, e.g. --role=ROLE_ADMIN,ROLE_USER)'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $allRoutes = $this->router->getRouteCollection()->all();

        /** @var string[]|string|null $roleInput */
        $roleInput = $input->getOption('role');
        $filteredRoles = $this->parseRoles($roleInput);

        if (!empty($filteredRoles)) {
            return $this->executeFilteredByRole($io, $allRoutes, $filteredRoles);
        }

        return $this->executeDefault($io, $allRoutes);
    }

    /**
     * Dumps all routes separated into exposed and non-exposed tables.
     *
     * @param array<string, Route> $allRoutes
     */
    private function executeDefault(SymfonyStyle $io, array $allRoutes): int
    {
        $io->title('FOSJsRouting Security - Route Role Exposure Debugger');

        $exposedRows = [];
        $nonExposedRows = [];

        foreach ($allRoutes as $name => $route) {
            $methods = $this->formatMethods($route);
            $path = $route->getPath();

            if ($this->isRouteExposedInJs($route, $name)) {
                $allowedRoles = $this->determineAllowedRolesDescription($route);
                $exposedRows[] = [
                    $name,
                    $path,
                    $methods,
                    $allowedRoles,
                ];
            } else {
                $nonExposedRows[] = [
                    $name,
                    $path,
                    $methods,
                ];
            }
        }

        $io->section(sprintf('Exposed Routes (%d)', count($exposedRows)));
        if (!empty($exposedRows)) {
            $io->table(
                ['Route Name', 'Path', 'Method(s)', 'Accessible Roles in JS'],
                $exposedRows
            );
        } else {
            $io->info('No exposed routes found in the application.');
        }

        $io->section(sprintf('Non-Exposed Routes (%d)', count($nonExposedRows)));
        if (!empty($nonExposedRows)) {
            $io->table(
                ['Route Name', 'Path', 'Method(s)'],
                $nonExposedRows
            );
        } else {
            $io->info('No non-exposed routes found in the application.');
        }

        $io->success(sprintf(
            'Total routes: %d (Exposed: %d, Non-Exposed: %d)',
            count($allRoutes),
            count($exposedRows),
            count($nonExposedRows)
        ));

        return Command::SUCCESS;
    }

    /**
     * Dumps only exposed routes that are accessible to the specified role(s).
     *
     * @param array<string, Route> $allRoutes
     * @param string[] $roles
     */
    private function executeFilteredByRole(SymfonyStyle $io, array $allRoutes, array $roles): int
    {
        $rolesLabel = implode(', ', $roles);
        $io->title(sprintf('FOSJsRouting Security - Exposed Routes for Role(s): %s', $rolesLabel));

        $matchingRows = [];

        foreach ($allRoutes as $name => $route) {
            if (!$this->isRouteExposedInJs($route, $name)) {
                continue;
            }

            if ($this->isRouteAccessibleByRoles($route, $roles)) {
                $matchingRows[] = [
                    $name,
                    $route->getPath(),
                    $this->formatMethods($route),
                    $this->determineAllowedRolesDescription($route),
                ];
            }
        }

        $io->section(sprintf('Matching Exposed Routes (%d)', count($matchingRows)));

        if (!empty($matchingRows)) {
            $io->table(
                ['Route Name', 'Path', 'Method(s)', 'Configured Access Rule'],
                $matchingRows
            );
            $io->success(sprintf(
                'Found %d exposed route(s) accessible by [%s].',
                count($matchingRows),
                $rolesLabel
            ));
        } else {
            $io->warning(sprintf(
                'No exposed routes found that are accessible by role(s): %s',
                $rolesLabel
            ));
        }

        return Command::SUCCESS;
    }

    /**
     * Determines whether a route is marked as exposed for JavaScript generation.
     */
    private function isRouteExposedInJs(Route $route, string $name): bool
    {
        if ($this->extractor instanceof RoleFilteredJsRoutesExtractor) {
            return $this->extractor->getInnerExtractor()->isRouteExposed($route, $name);
        }

        if ($route->hasOption('expose')) {
            $status = $route->getOption('expose');

            return false !== $status && 'false' !== $status;
        }

        return $this->extractor->isRouteExposed($route, $name);
    }

    /**
     * Determines human-readable role access descriptions for an exposed route.
     */
    private function determineAllowedRolesDescription(Route $route): string
    {
        $roleOption = $route->getOption('role') ?? $route->getOption('roles');
        $optionRoles = null;
        if (null !== $roleOption && false !== $roleOption && '' !== $roleOption) {
            $optionRoles = is_array($roleOption) ? $roleOption : [$roleOption];
        }

        $attributes = $this->getAccessControlAttributes($route);

        if (null === $optionRoles && empty($attributes)) {
            return 'PUBLIC (All roles / Anonymous)';
        }

        $descriptions = [];
        if (!empty($optionRoles)) {
            $descriptions[] = sprintf('Route Option: [%s]', implode(', ', $optionRoles));
        }

        if (!empty($attributes)) {
            $descriptions[] = sprintf('access_control: [%s]', implode(', ', $attributes));
        }

        return implode(' AND ', $descriptions);
    }

    /**
     * Checks if the given route is accessible when authenticated with the specified roles.
     *
     * @param string[] $roles
     */
    private function isRouteAccessibleByRoles(Route $route, array $roles): bool
    {
        if (null === $this->tokenStorage || null === $this->authChecker) {
            return $this->fallbackCheckRouteAccessByRoles($route, $roles);
        }

        $originalToken = $this->tokenStorage->getToken();

        try {
            if ($roles === ['ROLE_ANONYMOUS'] || empty($roles)) {
                $this->tokenStorage->setToken(new NullToken());
            } else {
                $user = new InMemoryUser('debug_user', null, $roles);
                $this->tokenStorage->setToken(new UsernamePasswordToken($user, 'main', $roles));
            }

            if ($this->extractor instanceof RoleFilteredJsRoutesExtractor) {
                return $this->extractor->isGrantedForRoute($route);
            }

            return $this->fallbackCheckRouteAccessByRoles($route, $roles);
        } finally {
            $this->tokenStorage->setToken($originalToken);
        }
    }

    /**
     * Static fallback check when token storage / auth checker is not available.
     *
     * @param string[] $roles
     */
    private function fallbackCheckRouteAccessByRoles(Route $route, array $roles): bool
    {
        $roleOption = $route->getOption('role') ?? $route->getOption('roles');
        if (null !== $roleOption && false !== $roleOption && '' !== $roleOption) {
            $requiredRoles = is_array($roleOption) ? $roleOption : [$roleOption];
            $hasOptionMatch = false;
            foreach ($requiredRoles as $requiredRole) {
                if (in_array($requiredRole, $roles, true)) {
                    $hasOptionMatch = true;
                    break;
                }
            }
            if (!$hasOptionMatch) {
                return false;
            }
        }

        $attributes = $this->getAccessControlAttributes($route);
        if (!empty($attributes)) {
            $hasAccessControlMatch = false;
            foreach ($attributes as $attribute) {
                if ('PUBLIC_ACCESS' === $attribute
                    || 'IS_AUTHENTICATED_ANONYMOUSLY' === $attribute
                    || in_array($attribute, $roles, true)
                ) {
                    $hasAccessControlMatch = true;
                    break;
                }
            }
            if (!$hasAccessControlMatch) {
                return false;
            }
        }

        return true;
    }

    /**
     * Returns matching security.yaml access_control attributes for a route.
     *
     * @return string[]
     */
    private function getAccessControlAttributes(Route $route): array
    {
        $methods = $route->getMethods();
        $method = !empty($methods) ? $methods[0] : 'GET';
        $dummyRequest = Request::create($route->getPath(), $method);

        if ($route->getHost()) {
            $dummyRequest->headers->set('host', $route->getHost());
        }

        $schemes = $route->getSchemes();
        if (!empty($schemes)) {
            $dummyRequest->server->set('HTTPS', in_array('https', $schemes, true) ? 'on' : 'off');
        }

        [$attributes] = $this->accessMap->getPatterns($dummyRequest);

        return $attributes ?? [];
    }

    /**
     * Parses input role options into a list of normalized strings.
     *
     * @param string[]|string|null $roleInput
     *
     * @return string[]
     */
    private function parseRoles(array|string|null $roleInput): array
    {
        if (null === $roleInput) {
            return [];
        }

        $rawList = is_array($roleInput) ? $roleInput : [$roleInput];
        $parsed = [];

        foreach ($rawList as $item) {
            foreach (explode(',', $item) as $role) {
                $trimmed = trim($role);
                if ('' !== $trimmed) {
                    $parsed[] = $trimmed;
                }
            }
        }

        return array_values(array_unique($parsed));
    }

    /**
     * Formats route HTTP methods into a displayable string.
     */
    private function formatMethods(Route $route): string
    {
        $methods = $route->getMethods();

        return !empty($methods) ? implode('|', $methods) : 'ANY';
    }
}
