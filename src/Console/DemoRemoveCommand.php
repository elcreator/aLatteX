<?php

declare(strict_types=1);

namespace Elcreator\aLatteX\Console;

use Elcreator\aLatteX\Demo\DemoSeeder;
use Illuminate\Console\Command;

class DemoRemoveCommand extends Command
{
    /** @var string */
    protected $signature = 'alattex:demo:remove {--force : Skip the confirmation prompt}';

    /** @var string */
    protected $description = 'Remove everything the aLatteX demo installed';

    public function handle(): int
    {
        if (!$this->option('force') && $this->input->isInteractive()) {
            $this->line('This permanently deletes the demo documents - they do not go to the');
            $this->line('recycle bin - along with the demo templates, chunks, snippets and TVs.');
            $this->line('Only the names listed in demo/manifest.php are touched.');

            if (!$this->confirm('Remove the aLatteX demo?', true)) {
                $this->warn('Nothing was removed.');

                return self::SUCCESS;
            }
        }

        foreach ((new DemoSeeder())->remove() as $line) {
            $this->line('  ' . $line);
        }

        $this->newLine();
        $this->info('Demo removed.');
        $this->line('Reinstall it with: php artisan alattex:demo:install');

        return self::SUCCESS;
    }
}
