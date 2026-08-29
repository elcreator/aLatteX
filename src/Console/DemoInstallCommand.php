<?php

declare(strict_types=1);

namespace Elcreator\aLatteX\Console;

use Elcreator\aLatteX\Demo\DemoContent;
use Elcreator\aLatteX\Demo\DemoSeeder;
use Illuminate\Console\Command;

class DemoInstallCommand extends Command
{
    /** @var string */
    protected $signature = 'alattex:demo:install {--force : Skip the confirmation prompt}';

    /** @var string */
    protected $description = 'Install the aLatteX demo pages, templates, chunks, snippets and TVs';

    public function handle(): int
    {
        $documents = count(DemoContent::documents());

        if (!$this->option('force') && $this->input->isInteractive()) {
            $this->line('This writes ' . $documents . ' documents and their elements into this site.');
            $this->line('Existing elements with the same names will be overwritten.');

            if (!$this->confirm('Install the aLatteX demo?', true)) {
                $this->warn('Nothing was installed.');

                return self::SUCCESS;
            }
        }

        foreach ((new DemoSeeder())->install() as $line) {
            $this->line('  ' . $line);
        }

        $this->newLine();
        $this->info('Demo installed. Open /' . DemoContent::documents()[0]['alias'] . '.html to see it.');
        $this->line('Templates are under the "' . DemoContent::category() . '" category in the manager.');

        if (function_exists('evo') && evo()->getConfig('chunk_processor') !== 'aLatteX') {
            $this->newLine();
            $this->warn('chunk_processor is not set to aLatteX, so the pages will not be');
            $this->warn('rendered by Latte. Set it in System Settings -> Site first.');
        }

        $this->line('Remove it again with: php artisan alattex:demo:remove');

        return self::SUCCESS;
    }
}
