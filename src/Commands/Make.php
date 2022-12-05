<?php
namespace SingleQuote\ModelSeeder\Commands;

use Illuminate\Console\Command;

class Make extends Command
{

    /**
     * @var  string
     */
    protected $signature = 'seed:make';

    /**
     * @var  string
     */
    protected $description = 'Create seeders from your models using the database';
    
    
    
    public function __construct()
    {
        parent::__construct();
    }
    
    /**
     * 
     */
    public function handle()
    {                
        dd('yessss');
    }
}
