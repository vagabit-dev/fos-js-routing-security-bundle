<?php

declare(strict_types=1);

namespace Vagabit\FOSJsRoutingSecurityBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Vagabit\FOSJsRoutingSecurityBundle\Service\JsRoutesCacheWarmer;

#[AsCommand(
    name: 'vagabit:js-routing:warmup',
    description: 'Warms up the role-filtered FOSJsRouting cache for configured or specified role combinations'
)]
class WarmupJsRoutesCacheCommand extends Command
{
    public function __construct(
        private readonly JsRoutesCacheWarmer $cacheWarmer
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
                'Specify specific roles for a single combination to warm up (e.g. --role=ROLE_ADMIN --role=ROLE_USER)'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Warming up FOSJsRouting Security Role-Based Route Caches');

        /** @var string[] $cliRoles */
        $cliRoles = $input->getOption('role');

        $combinationsToWarm = null;
        if (!empty($cliRoles)) {
            $combinationsToWarm = [$cliRoles];
        }

        $results = $this->cacheWarmer->warmUpAll($combinationsToWarm);

        $tableRows = [];
        foreach ($results as $cacheKey => $data) {
            $routeCount = isset($data['routes']) && is_countable($data['routes']) ? count($data['routes']) : 0;
            $tableRows[] = [
                $cacheKey,
                (string) $routeCount,
            ];
        }

        $io->table(['Cache Key', 'Exposed Routes Count'], $tableRows);
        $io->success(sprintf('Successfully warmed up %d role route combination(s).', count($results)));

        return Command::SUCCESS;
    }
}
