<?php

declare(strict_types=1);

namespace GlpiPlugin\Experiencekit\Console;

use Glpi\Console\AbstractCommand;
use GlpiPlugin\Experiencekit\Infrastructure\Persistence\RegistryRepository;
use PluginExperiencekitRun;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `plugins:experiencekit:status` - lists recent runs and their record
 * counts, for a quick "what's in this instance" check without opening the
 * admin UI.
 */
final class StatusCommand extends AbstractCommand
{
    protected function configure()
    {
        parent::configure();

        $this->setName('plugins:experiencekit:status');
        $this->setDescription('Lists recent Experience Kit generation runs');

        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Number of runs to show', '20');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        global $DB;

        $runItem = new PluginExperiencekitRun();
        $rows = $runItem->find([], ['date_creation DESC'], max(1, (int) $input->getOption('limit')));

        if (count($rows) === 0) {
            $output->writeln('No runs yet.');
            return self::SUCCESS;
        }

        $registry = new RegistryRepository($DB);

        $table = new Table($output);
        $table->setHeaders(['ID', 'Name', 'Profile', 'Status', 'Records', 'Created']);
        foreach ($rows as $row) {
            $run = new PluginExperiencekitRun();
            $run->getFromDB($row['id']);
            $total = array_sum($registry->countsByItemtypeForRun($run->getID()));
            $table->addRow([
                $run->getID(),
                $run->fields['name'],
                $run->fields['volume_profile'],
                $run->fields['status'],
                $total,
                $run->fields['date_creation'],
            ]);
        }
        $table->render();

        return self::SUCCESS;
    }
}
