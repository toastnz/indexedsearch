<?php

namespace Toast\IndexedSearch;

use SilverStripe\ORM\DataExtension;
use SilverStripe\Versioned\Versioned;

class DataObjectExtension extends DataExtension
{

    public function onAfterWrite()
    {
        if (!$this->owner->hasExtension(Versioned::class)) {
            singleton(SearchIndex::class)
                ->updateIndex($this->owner);
        }
    }

    public function onAfterDelete()
    {
        if (!$this->owner->hasExtension(Versioned::class)) {
            singleton(SearchIndex::class)
                ->removeFromIndex($this->owner);
        }
    }

    public function onAfterPublish()
    {
        singleton(SearchIndex::class)
            ->updateIndex($this->owner);
    }

    public function onAfterUnpublish()
    {
        singleton(SearchIndex::class)
            ->removeFromIndex($this->owner);
    }

}
