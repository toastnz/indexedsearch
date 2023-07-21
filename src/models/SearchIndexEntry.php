<?php

namespace Toast\IndexedSearch;

use SilverStripe\ORM\DB;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\Connect\MySQLSchemaManager;

class SearchIndexEntry extends DataObject
{
    private static $table_name = 'IndexedSearch_SearchIndexEntry';

    private static $db = [
        'RecordClass' => 'Varchar(255)',
        'RecordID' => 'Int',
        'RecordData' => 'Text',
        'IndexData' => 'Text',
        'RawData' => 'Text',
        'FuzzyData' => 'Text',
        'FilterableData' => 'Text',
        'SubsiteID' => 'Int'
    ];

    private static $create_table_options = [
        MySQLSchemaManager::ID => 'ENGINE=InnoDB'
    ];    

    private static $indexes = [
        'SearchFields' => [
            'type' => 'fulltext',
            'columns' => [
                'RawData'
            ]
        ]
    ];

    public function onAfterBuild()
    {        
        try {
            // Workaround InnoDB multiple fulltext indexes creation bug by creating it separately
            DB::query('ALTER TABLE `' . self::$table_name . '` ADD FULLTEXT INDEX `FuzzySearchFields` (`FuzzyData`);');
        } catch (\Throwable $e) {
        }
    }

    public function canView($member = null)
    {
        return false;
    }

    public function canEdit($member = null)
    {
        return false;
    }

    public function getObject()
    {
        try {
            $object = $this->RecordClass::get()
                ->byID($this->RecordID);

            if ($object) {
                return $object;
            }

        } catch (\Throwable $e) {
        }

        return false;
    }

}