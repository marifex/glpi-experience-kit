<?php

declare(strict_types=1);

namespace GlpiPlugin\Experiencekit\Console;

use Glpi\Console\AbstractCommand;
use GlpiPlugin\Experiencekit\Domain\Exception\GenerationException;
use GlpiPlugin\Experiencekit\Domain\VolumeProfileFactory;
use GlpiPlugin\Experiencekit\Infrastructure\Support\OrchestratorFactory;
use PluginExperiencekitRun;
use Session;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `plugins:experiencekit:generate` - the fast, synchronous SSH-driven path
 * the doc's §9 recommends, as an alternative to waiting on cron ticks.
 * Wraps the exact same GenerationOrchestrator the admin UI and cron use -
 * there is only ever one place that knows how to advance a run.
 */
final class GenerateCommand extends AbstractCommand
{
    protected function configure()
    {
        parent::configure();

        $this->setName('plugins:experiencekit:generate');
        $this->setDescription('Generates a realistic enterprise GLPI demo environment');

        $this->addOption('username', 'u', InputOption::VALUE_REQUIRED, 'GLPI user to run as (required - actor/history attribution needs a real session)');
        $this->addOption('profile', 'p', InputOption::VALUE_REQUIRED, 'Volume profile: ' . implode('|', VolumeProfileFactory::names()), VolumeProfileFactory::MEDIUM);
        $this->addOption('organization', 'o', InputOption::VALUE_REQUIRED, 'Organization name for the generated entity tree', 'MarifeX');
        $this->addOption('name', null, InputOption::VALUE_REQUIRED, 'Optional name for this run');
        $this->addOption('run', null, InputOption::VALUE_REQUIRED, 'Resume an existing run by id instead of starting a new one');
        $this->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Records processed per batch', '200');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $username = $input->getOption('username');
        if (empty($username)) {
            $output->writeln('<error>--username is required.</error>');
            return self::FAILURE;
        }
        $this->loadUserSession($username);

        $batchSize = max(1, (int) $input->getOption('batch-size'));
        $orchestrator = OrchestratorFactory::make();

        $run = new PluginExperiencekitRun();
        if ($input->getOption('run')) {
            if (!$run->getFromDB((int) $input->getOption('run'))) {
                $output->writeln('<error>Run not found.</error>');
                return self::FAILURE;
            }
            if ($run->fields['status'] !== PluginExperiencekitRun::STATUS_RUNNING) {
                $orchestrator->resumeRun($run);
            }
            $output->writeln(sprintf('<info>Resuming run #%d.</info>', $run->getID()));
        } else {
            try {
                $run = $orchestrator->startRun(
                    (string) $input->getOption('profile'),
                    Session::getLoginUserID(),
                    $input->getOption('name'),
                    (string) $input->getOption('organization'),
                );
            } catch (GenerationException $e) {
                $output->writeln('<error>' . $e->getMessage() . '</error>');
                return self::FAILURE;
            }
            $output->writeln(sprintf('<info>Started run #%d (%s profile).</info>', $run->getID(), $input->getOption('profile')));
        }

        $lastPhase = null;
        while (true) {
            $run->getFromDB($run->getID());
            if ($run->fields['status'] !== PluginExperiencekitRun::STATUS_RUNNING) {
                break;
            }
            if ($run->fields['current_phase'] !== $lastPhase) {
                $lastPhase = $run->fields['current_phase'];
                $output->writeln('<comment>Phase: ' . $lastPhase . '</comment>');
            }

            try {
                $orchestrator->runNextBatch($run, $batchSize);
            } catch (\Throwable $e) {
                $output->writeln('<error>' . $e->getMessage() . '</error>');
                return self::FAILURE;
            }
        }

        $run->getFromDB($run->getID());
        if ($run->fields['status'] === PluginExperiencekitRun::STATUS_COMPLETED) {
            $output->writeln(sprintf('<info>Run #%d completed.</info>', $run->getID()));
            return self::SUCCESS;
        }

        $output->writeln(sprintf(
            '<error>Run #%d ended with status "%s": %s</error>',
            $run->getID(),
            $run->fields['status'],
            $run->fields['error_message'] ?? ''
        ));
        return self::FAILURE;
    }
}
