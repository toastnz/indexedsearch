<?php

namespace Toast\IndexedSearch;

use SilverStripe\Control\Director;
use SilverStripe\Dev\BuildTask;


class IndexedSearchReindexTask extends BuildTask
{
    private static $segment = 'IndexedSearchReindexTask';

    protected $title = 'Indexed Search: Reindex search data';

    protected $description = 'Reindex all search data based on the current index configuration';

    public function run($request)
    {
        if (!Director::is_cli()) {
            echo 'This task can cannot be run from the browser.';
            return;
        }
        
        singleton(SearchIndex::class)->doIndex();

        echo PHP_EOL .'Finished.' . PHP_EOL;
    }

}