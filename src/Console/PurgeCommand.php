<?php

declare(strict_types=1);

namespace GlpiPlugin\Experiencekit\Console;

use Glpi\Console\AbstractCommand;
use GlpiPlugin\Experiencekit\Infrastructure\Support\OrchestratorFactory;
use PluginExperiencekitRun;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `plugins:experiencekit:purge` - the "matching purge command" the doc's
 * §9 asks for. Registry-driven, same as the admin UI's Purge button: a run
 * can only ever remove what it itself created.
 */
final class PurgeCommand extends AbstractCommand
{
    protected function configure()
    {
        parent::configure();

        $this->setName('plugins:experiencekit:purge');
        $this->setDescription('Removes a previously generated Experience Kit environment');

        $this->addOption('username', 'u', InputOption::VALUE_REQUIRED, 'GLPI user to run as');
        $this->addOption('run', null, InputOption::VALUE_REQUIRED, 'Run id to purge');
        $this->addOption('all', null, InputOption::VALUE_NONE, 'Purge every run not already purged');
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

        if (!$input->getOption('run') && !$input->getOption('all')) {
            $output->writeln('<error>Specify --run=ID or --all.</error>');
            return self::FAILURE;
        }

        $purgeOrchestrator = OrchestratorFactory::makePurgeOrchestrator();
        $batchSize = max(1, (int) $input->getOption('batch-size'));

        $runIds = [];
        if ($input->getOption('all')) {
            $runItem = new PluginExperiencekitRun();
            foreach ($runItem->find([
                'status' => ['<>', PluginExperiencekitRun::STATUS_PURGED],
            ]) as $row) {
                $runIds[] = (int) $row['id'];
            }
        } else {
            $runIds[] = (int) $input->getOption('run');
        }

        foreach ($runIds as $runsId) {
            $run = new PluginExperiencekitRun();
            if (!$run->getFromDB($runsId)) {
                $output->writeln("<error>Run #{$runsId} not found.</error>");
                continue;
            }

            $preview = $purgeOrchestrator->preview($runsId);
            $total = array_sum($preview);
            if ($total === 0) {
                $output->writeln("<comment>Run #{$runsId}: nothing to purge.</comment>");
                continue;
            }

            $output->writeln(sprintf('Run #%d: %d record(s) across %d type(s) will be permanently deleted.', $runsId, $total, count($preview)));
            $this->askForConfirmation(false);

            if ($run->fields['status'] !== PluginExperiencekitRun::STATUS_PURGING) {
                $purgeOrchestrator->startPurge($run);
            }

            while (true) {
                $run->getFromDB($runsId);
                if ($run->fields['status'] !== PluginExperiencekitRun::STATUS_PURGING) {
                    break;
                }
                $purgeOrchestrator->purgeNextBatch($run, $batchSize);
            }

            $output->writeln(sprintf('<info>Run #%d purged.</info>', $runsId));
        }

        return self::SUCCESS;
    }
}
