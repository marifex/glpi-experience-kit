<?php

declare(strict_types=1);

namespace GlpiPlugin\Experiencekit\Console;

use Glpi\Console\AbstractCommand;
use GlpiPlugin\Experiencekit\Infrastructure\Support\OrchestratorFactory;
use PluginExperiencekitHealthcheck;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `plugins:experiencekit:health-check` - the doc's §9 "post-generation
 * health check... as an automated regression test, so this exact bug class
 * can never ship silently again", runnable on demand or wired into CI.
 * Exits non-zero on any FAIL so it can gate a pipeline.
 */
final class HealthCheckCommand extends AbstractCommand
{
    protected function configure()
    {
        parent::configure();

        $this->setName('plugins:experiencekit:health-check');
        $this->setDescription('Runs regression checks against generated Experience Kit data (requester actor links, registry consistency)');

        $this->addOption('run', null, InputOption::VALUE_REQUIRED, 'Scope to one run id (default: every run this plugin has generated)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $runsId = $input->getOption('run') !== null ? (int) $input->getOption('run') : null;

        $service = OrchestratorFactory::makeHealthCheckService();
        $results = $service->run($runsId);

        $hasFailure = false;
        foreach ($results as $result) {
            $tag = match ($result->status) {
                PluginExperiencekitHealthcheck::STATUS_PASS => '<info>PASS</info>',
                PluginExperiencekitHealthcheck::STATUS_WARN => '<comment>WARN</comment>',
                default => '<error>FAIL</error>',
            };
            $output->writeln(sprintf('[%s] %s: %s', $tag, $result->label, $result->summary));

            if ($result->status === PluginExperiencekitHealthcheck::STATUS_FAIL) {
                $hasFailure = true;
            }
        }

        return $hasFailure ? self::FAILURE : self::SUCCESS;
    }
}
