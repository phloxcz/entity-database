<?php
/**
 * This file is part of phloxcz/entity-database.
 *
 * (c) Phlox <https://github.com/phloxcz>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Phlox\EntityDatabase\Console\Command;

use Phlox\EntityDatabase\Generator\EntityGenerator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'generate', description: 'Generate entity classes from database schema')]
class Generate extends Command
{
    public function __construct(private EntityGenerator $generator)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'bootstrap',
                'b',
                InputOption::VALUE_REQUIRED,
                'Path to the application bootstrap file (index.php). '
                . 'When omitted the bin script searches for index.php automatically.'
            )
            ->setHelp(<<<EOT
The <info>generate</info> command generates typed entity classes from your database schema.

  <info>vendor/bin/entity-database generate</info>

Specify a custom bootstrap when the auto-detection fails:

  <info>vendor/bin/entity-database generate --bootstrap app/bootstrap.php</info>

Note: <comment>--bootstrap</comment> is consumed by the bin script before Nette boots,
so it does not need to be passed to this command at runtime.
EOT);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<info>Generating entities…</info>');

        $generated = $this->generator->generate();

        if (empty($generated)) {
            $output->writeln('<comment>No entities were generated.</comment>');
            return Command::SUCCESS;
        }

        foreach ($generated as $file) {
            $output->writeln("  <info>✔</info> {$file}");
        }

        $output->writeln('');
        $output->writeln(sprintf('<info>Done. %d file(s) written.</info>', count($generated)));

        return Command::SUCCESS;
    }
}
