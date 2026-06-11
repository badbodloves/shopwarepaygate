<?php declare(strict_types=1);

namespace PayGateTo\PayGatePayment\Command;

use PayGateTo\PayGatePayment\Service\CloudflareSetupService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class CloudflareSetupCommand extends Command
{
    protected static $defaultName = 'paygate:cloudflare:setup';

    private CloudflareSetupService $setup;

    public function __construct(CloudflareSetupService $setup)
    {
        parent::__construct();
        $this->setup = $setup;
    }

    protected function configure(): void
    {
        $this
            ->setName('paygate:cloudflare:setup')
            ->setDescription('Create Cloudflare DNS records, worker and routes for the PayGate.to custom domain.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('PayGate.to Cloudflare setup');

        try {
            $progress = $this->setup->setup();
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        foreach ($progress as $line) {
            $io->writeln('  <info>✓</info> ' . $line);
        }

        $io->success('Setup complete. Open the checkout subdomain in a browser to verify (you should see the German checkout page).');
        return Command::SUCCESS;
    }
}
