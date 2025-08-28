<?php

namespace Toast\IndexedSearch;

use SilverStripe\Dev\BuildTask;
use SilverStripe\Control\Director;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;


class IndexedSearchReindexTask extends BuildTask
{
    protected static string $commandName = 'IndexedSearchReindexTask';

    protected string $title = 'Indexed Search: Reindex search data';

    protected static string $description = 'Reindex all search data based on the current index configuration';

    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        if (!Director::is_cli()) {
            echo 'This task can cannot be run from the browser.';
            return Command::FAILURE;
        }
        
        singleton(SearchIndex::class)->doIndex();

        echo PHP_EOL .'Finished.' . PHP_EOL;
        return Command::SUCCESS;
    }

}
