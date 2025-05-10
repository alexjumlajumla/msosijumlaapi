<?php

namespace App\Console\Commands;

use App\Traits\Notification;
use Illuminate\Console\Command;

class CleanFirebaseTokens extends Command
{
    use Notification;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'firebase:clean-tokens';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean up invalid and duplicate Firebase tokens';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting Firebase token cleanup...');
        
        try {
            $this->cleanInvalidTokens();
            $this->info('Firebase token cleanup completed successfully.');
        } catch (\Exception $e) {
            $this->error('Error cleaning Firebase tokens: ' . $e->getMessage());
        }
        
        return 0;
    }
} 