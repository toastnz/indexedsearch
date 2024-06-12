<?php

namespace Toast\IndexedSearch;

use SilverStripe\ORM\DB;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\ArrayList;
use SilverStripe\Core\ClassInfo;
use SilverStripe\ORM\DataObject;
use SilverStripe\View\ArrayData;
use SilverStripe\Core\Extensible;
use SilverStripe\Control\Director;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\ErrorPage\ErrorPage;
use SilverStripe\Versioned\Versioned;
use SilverStripe\CMS\Model\RedirectorPage;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\View\Parsers\ShortcodeParser;

class SearchIndex
{
    use Injectable;
    use Configurable;
    use Extensible;

    private static $index_classes;

    private static $exclude_classes;

    private static $index_fields;

    private static $searchable_flag_check = 'ShowInSearch';

    private static $search_allow_empty_query = false;

    private static $search_split_special_chars = true;

    public static $summary_separator = ' ';

    protected static $index_data = [];

    private $searchClasses = null;

    private $searchBoostFields = null;

    private $searchBoostClasses = null;

    private $searchRankFields = null;
    
    private $searchFilterableFields = null;

    private $searchFilters = null;

    private $searchFuzzy = false;

    private $disableSubsiteFilterClasses = null;

    private $searchExcludeClasses = null;

    private $searchScoreSortSequence = ['class', 'field', 'similarity'];

    
    public function doIndex()
    {
        $indexedEntryIDs = [];

        if (class_exists(\SilverStripe\Subsites\Model\Subsite::class)) {
            \SilverStripe\Subsites\Model\Subsite::disable_subsite_filter();
        }

        foreach($this->getIndexClasses() as $class) {                        
            foreach(ClassInfo::subclassesFor($class) as $subClass) {
                if (in_array($subClass, $this->getExcludeClasses())) {
                    continue;
                }

                $objects = $subClass::get()
                    ->filter('ClassName', $subClass);

                foreach($objects as $object) {
                    if ($entry = $this->addToIndex($object)) {
                        $indexedEntryIDs[] = $entry->ID;
                    }
                }            
            }
        }

        // removing old entries
        if (count($indexedEntryIDs)) {
            $removals = SearchIndexEntry::get()
                ->exclude('ID', $indexedEntryIDs);

            foreach($removals as $entry) {
                $entry->delete();
            }
        }
    }

    public function addToIndex($object)
    {
        // class is not allowed
        if (!$this->isClassIndexed($object->ClassName)) {
            return;
        }

        // object not indexable
        if (!$this->isObjectIndexable($object)) {
            return;
        }

        // ensure live version when versioned
        if ($object->hasExtension(Versioned::class)) {            
            $readingMode = Versioned::get_reading_mode();
            Versioned::set_reading_mode(Versioned::DEFAULT_MODE);
    
            $object = $object->ClassName::get()
                ->byID($object->ID);

            Versioned::set_reading_mode($readingMode);

            if (!$object) {
                return;
            }           
        }

        $values = [];
        $valuesFilterable = [];
        $searchFieldExclusions = [];

        foreach($this->getIndexFields() as $fieldData) {

            $fieldData = $this->getClassAndField($fieldData);

            if (is_array($fieldData)) {
                if ($fieldData['class'] != $object->ClassName) {
                    continue;
                }
                $field = $fieldData['field'];

            } else {
                $field = $fieldData;
            }

            if (substr($field, -1) === '*') {
                $field = substr($field, 0, -1);
                $searchFieldExclusions[] = $field;
            }


            $valuesForPath = $this->getValuesForPath($object, $field);
            $values[$field] = $valuesForPath[$field];

            $valuesForPathFilterable = $this->getValuesForPath($object, $field, null, false, true);
            $valuesFilterable[$field] = $valuesForPathFilterable;
        }

        $entry = SearchIndexEntry::get()
            ->filter([
                'RecordClass' => $object->ClassName,
                'RecordID' => $object->ID
            ])
            ->first();

        if (!$entry) {
            $entry = SearchIndexEntry::create([
                'RecordClass' => $object->ClassName,
                'RecordID' => $object->ID
            ]);
        }

        $values = [
            'IndexData' => $values
        ];        

        $entry->IndexData = json_encode($values);
        $entry->RawData = trim($this->getRawDataFromValues($values, $searchFieldExclusions));
        $entry->FuzzyData = $this->makeFuzzyData($entry->RawData);
        $entry->FilterableData = json_encode($valuesFilterable);
        $entry->SubsiteID = isset($object->SubsiteID) ? (int)$object->SubsiteID : -1;

        // set object data
        $dataFields = $this->getDataFields();

        $data = [
            'ID' => $object->ID,
            'ClassName' => $object->ClassName
        ];

        if (is_array($dataFields)) {
            foreach($dataFields as $dataField) {
                $dataValue = $object->hasMethod($dataField) ? $object->$dataField() : (isset($object->$dataField) ? $object->$dataField : null);

                if (!is_null($dataValue) && !is_bool($dataValue) && !is_string($dataValue) && !is_numeric($dataValue)) {
                    throw new \Exception('Data field "' . $dataField . '" must be a string, numeric or boolean value.');
                }

                $data[$dataField] = $dataValue ? str_replace(['?stage=Stage', '&stage=Stage', '?stage=Live', '&stage=Live'], '', $dataValue) : $dataValue;
            }
        }

        $entry->RecordData = json_encode($data);
        $entry->write();

        if (Director::is_cli()) {
            echo 'Indexed ' . $object->ClassName . ' #' . $object->ID . PHP_EOL;
        }

        return $entry;
    }

    public function removeFromIndex($object)
    {
        $entry = SearchIndexEntry::get()
            ->filter([
                'RecordClass' => $object->ClassName,
                'RecordID' => $object->ID
            ])
            ->first();

        if ($entry) {
            $entry->delete();
        }
    }

    public function updateIndex($object)
    {
        $this->removeFromIndex($object);
        $this->addToIndex($object);
    }

    public function isInIndex($object)
    {
        return (bool)SearchIndexEntry::get()
            ->filter([
                'RecordClass' => $object->ClassName,
                'RecordID' => $object->ID
            ])
            ->first();
    }

    public function isClassIndexed($class)
    {
        foreach($this->getIndexClasses() as $indexClass) {
            if (in_array($class, ClassInfo::subclassesFor($indexClass))) {
                return true;
            }
        }

        return false;

    }
    
    public function clearIndex()
    {
        try {
            DB::query('TRUNCATE "' . SearchIndexEntry::config()->get('table_name') . '"');
        } catch (\Throwable $e) {
            throw new \Exception('Could not clear search index: ' . $e->getMessage());
        }
    }

    protected function getValuesForPath($object, $path, $originalPath = null, $processing = false, $skipConcat = false)
    {
        if (!$processing) {
            self::$index_data = [];
        }

        $processing = true;

        $originalPath = $originalPath ?: $path;

        if (!$skipConcat) {
            if (!isset(self::$index_data[$originalPath])) {
                self::$index_data[$originalPath] = '';
            }
        }

        if (!strpos($path, '.')) {
            if (isset($object->$path) || ($object->hasMethod($path) && is_string($object->$path()))) {

                $objectPathValue = $object->hasMethod($path) ? $object->$path() : $object->$path;

                if ($skipConcat) {
                    if ($cleanString = $this->cleanString($objectPathValue)) {                    
                        if (!in_array($cleanString, self::$index_data)) {
                            self::$index_data[] = $cleanString;
                        }
                    }

                } else {
                    if ($objectPathValue) {
                        if (!strstr(self::$index_data[$originalPath], $objectPathValue)) {                        
                            if ($cleanString = $this->cleanString($objectPathValue)) {                    
                                self::$index_data[$originalPath] .= self::$summary_separator . $cleanString;
                            }
                        }
                    }
                }
            }
        
        } else {
            
            $fields = explode('.', $path);
            $dbField = array_pop($fields);

            foreach ($fields as $field) {
                if ($object->hasMethod($field)) {
                    $items = $object->$field();

                    if ($items instanceof DataObject) {                
                        $value = $object->$field()->hasMethod($dbField) ? $object->$field()->$dbField() : $object->$field()->$dbField;

                        if ($skipConcat) {                            
                            if ($cleanString = $this->cleanString($value)) {
                                if (!in_array($cleanString, self::$index_data)) {                                
                                    self::$index_data[] = $cleanString;
                                }
                            }

                        } else {
                            if ($value) {
                                if (!strstr(self::$index_data[$originalPath], $value)) {                                
                                    if ($cleanString = $this->cleanString($value)) {
                                        self::$index_data[$originalPath] .= self::$summary_separator . $cleanString;
                                    }
                                }
                            }
                        }
                            
                    } elseif ($items instanceof DataList || $items instanceof ArrayList) {
                        foreach($items as $item) {
                            $partialPath = explode($field, $path);

                            if (isset($partialPath[1])) {
                                $partialPath = str_replace('.', '', $partialPath[1]);

                                $subValues = $this->getValuesForPath($item, $partialPath, $originalPath, true, $skipConcat);

                                if ($skipConcat) {
                                    if (!in_array($subValues, self::$index_data)) {
                                        if (isset($subValues[$originalPath]) && $subValues[$originalPath]) {
                                            self::$index_data[] = $subValues[$originalPath];
                                        }
                                    }

                                } else {
                                    $stringSubValues = $subValues ? implode(self::$summary_separator, $subValues) : null;
                                    
                                    if ($stringSubValues) {
                                        if (!strstr(self::$index_data[$originalPath], $stringSubValues)) {
                                            if ($cleanString = $this->cleanString($stringSubValues)) {
                                                self::$index_data[$originalPath] .= self::$summary_separator . $cleanString;
                                            }
                                        }
                                    }
                                }

                            }
                        }

                    } 
                }
            }    
        }

        return self::$index_data;
    }


    private function cleanString($string)
    {
        if (!$string) {
            return $string;
        }

        $string = (string)ShortcodeParser::get_active()->parse($string);
        $string = str_replace(['<br>', '<br/>', '<br /></p>'], ' ', $string);
        $string = html_entity_decode($string);
        $string = strip_tags($string);
        $string = preg_replace('/\s+/', ' ', $string);
        $string = trim($string);
        return $string;
    }

    private function getRawDataFromValues(array $values, array $exclusions = [])
    {
        $output = '';

        foreach($values['IndexData'] as $field => $value) {
            if (!in_array($field, $exclusions)) {
                $output .= self::$summary_separator . $value;
            }
        }

        return $output;
    }

    private function makeFuzzyData($string)
    {
        $words = explode(' ', $string);        
        $output = [];

        foreach($words as $word) {
            $soundex = soundex($word);
            if (($soundex != '0000') && !in_array($soundex, $output)) {
                $output[] = $soundex;
            }
        }
        
        return implode(' ', $output);
    }

    public function search($query, array $searchClasses = null, array $boostFields = null, array $boostClasses = null, $fuzzy = false, array $filterDataFields = null, array $filters = null, $rankFields = null, $searchScoreSortSequence = null)
    {
        $searchClasses = $searchClasses ?: $this->getSearchClasses();
        $boostFields = $boostFields ?: $this->getSearchBoostFields();
        $boostClasses = $boostClasses ?: $this->getSearchBoostClasses();
        $rankFields = $rankFields ?: $this->getSearchRankFields();
        $excludeClasses = $this->getSearchExcludeClasses();
        $filterDataFields = $filterDataFields ?: $this->getSearchFilterableFields();
        $filters = $filters ?: $this->getSearchFilters();
        $fuzzy = $fuzzy ?: $this->getSearchFuzzy();
        $disableSubsiteFilterClasses = $this->getDisableSubsiteFilterClasses();
        $searchScoreSortSequence = $this->getSearchScoreSortSequence();
        $allowEmptyQuery = self::config()->get('search_allow_empty_query') ?? false;
        $disableSubsiteFilter = self::config()->get('search_disable_subsite_filter') ?? false;
        $splitSpecialChars = self::config()->get('search_split_special_chars') ?? true;

        $searchServiceClass = SearchService::class;
        $this->extend('updateSearchServiceClass', $searchServiceClass);

        $searchService = singleton($searchServiceClass);
        
        $result = $searchService->doSearch($query, $searchClasses, $boostFields, $boostClasses, $fuzzy, $filters, $allowEmptyQuery, $disableSubsiteFilter, $disableSubsiteFilterClasses, $splitSpecialChars, $excludeClasses);

        if ($filterDataFields && ($indexFields = $this->getIndexFields())) {
            $missingFields = array_diff(array_keys($filterDataFields), $indexFields);
            if (!empty($missingFields)) {
                throw new \Exception('Unable to generate filter data for fields that are not in the index: ' . implode(', ', $missingFields));
            }
        }

        // set filterable structure if enabled
        $filtersArray = [];

        if ($filterDataFields) {    
            $c = 0;

            for ($m = 0; $m < count($result->Matches); $m++) {
                $indexEntry = $result->IndexEntries[$c];

                foreach(array_keys($filterDataFields) as $field) {
                    if ($indexEntry) {
                        $filterValues = json_decode($indexEntry['FilterableData'], true);

                        $values = [];

                        foreach ($filterValues[$field] as $filterValue) {
                            if (!isset($filtersArray[$field]) || !in_array($filterValue, $values)) {                        
                                $values[] = $filterValue;
                        
                                if (isset($filtersArray[$field])) {
                                    foreach ($filtersArray[$field] as &$existingFilter) {
                                        if ($existingFilter['value'] === $filterValue) {
                                            $existingFilter['count']++;
                                            continue 2;
                                        }
                                    }
                                }
                        
                                $filtersArray[$field][] = [
                                    'value' => $filterValue,
                                    'count' => 1
                                ];
                            }
                        }
                        
                    }
                }

                $c++;
            }
        }

        // filterable data
        $filterableItems = ArrayList::create();

        foreach($filtersArray as $field => $options) {
            $filterOptions = ArrayList::create();

            foreach(array_values($options) as $data) {
                $filterOptions->push(ArrayData::create([
                    'Value' => $data['value'],
                    'Total' => $data['count']
                ]));
            }

            $filterableItems->push(ArrayData::create([
                'Field' => $field,
                'Label' => $filterDataFields[$field],
                'Options' => $filterOptions
            ]));
        }

        $sortFields = [];

        if ($rankFields && is_array($rankFields)) {
            foreach($rankFields as $rankField) {
                $sortFields[$rankField] = 'ASC';
            }
        }

        if ($boostFields) {
            $scoreSortMap = [
                'class' => 'Search___ClassScore',
                'field' => 'Search___BoostSimilarity',
                'similarity' => 'Search___Similarity'
            ];    
        } else {
            $scoreSortMap = [
                'class' => 'Search___ClassScore',
                'similarity' => 'Search___Similarity'
            ];
        }

        foreach($searchScoreSortSequence as $sortField) {
            if (isset($scoreSortMap[$sortField])) {
                $sortFields[$scoreSortMap[$sortField]] = 'DESC';
            }
        }

        $result->Matches = $result->Matches
            ->sort($sortFields);

        return ArrayData::create([
            'Matches' => $result->Matches,
            'Filters' => $filterableItems
        ]);
    }


    public function isObjectIndexable($object)
    {
        if ($flag = self::config()->get('searchable_flag_check')) {
            if ($object->hasMethod($flag)) {
                return (bool)$object->$flag();

            } elseif (isset($object->$flag)) {
                return (bool)$object->$flag;

            }
        }

        return false;
    }

    public function setSearchClasses(array $searchClasses)
    {
        $this->searchClasses = $searchClasses;
        return $this;
    }

    public function getSearchClasses()
    {
        return $this->searchClasses;
    }

    public function getSearchExcludeClasses()
    {
        return $this->searchExcludeClasses;
    }

    public function setSearchExcludeClasses(array $classes)
    {
        $this->searchExcludeClasses = $classes;
        return $this;
    }

    public function setSearchBoostFields(array $boostFields)
    {
        $this->searchBoostFields = $boostFields;
        return $this;
    }   

    public function getSearchBoostFields()
    {
        return $this->searchBoostFields;
    }

    public function setSearchBoostClasses(array $boostClasses)
    {
        $this->searchBoostClasses = $boostClasses;
        return $this;
    }

    public function setSearchRankFields(array $rankFields)
    {
        $this->searchRankFields = $rankFields;
        return $this;
    }

    public function getSearchRankFields()
    {
        return $this->searchRankFields;
    }

    public function getSearchScoreSortSequence()
    {
        return $this->searchScoreSortSequence;
    }

    public function setSearchScoreSortSequence(array $sequence)
    {
        $this->searchScoreSortSequence = $sequence;
        return $this;
    }

    public function getSearchBoostClasses()
    {
        return $this->searchBoostClasses;
    }

    public function setSearchFilterableFields(array $fields)
    {
        $this->searchFilterableFields = $fields;
        return $this;
    }

    public function getSearchFilterableFields()
    {
        return $this->searchFilterableFields;
    }

    public function setSearchFilters(array $filters)
    {
        $this->searchFilters = $filters;
        return $this;
    }

    public function getSearchFilters()
    {
        return $this->searchFilters;
    }

    public function getSearchFuzzy()
    {
        return $this->searchFuzzy;
    }

    public function setSearchFuzzy($fuzzy)
    {
        $this->searchFuzzy = $fuzzy;
        return $this;
    }

    public function getDisableSubsiteFilterClasses()
    {
        return $this->disableSubsiteFilterClasses;
    }

    public function setDisableSubsiteFilterClasses(array $classes)
    {
        $this->disableSubsiteFilterClasses = $classes;
        return $this;
    }

    public function getClassAndField($string) 
    {
        $lastUnderscorePos = strrpos($string, '_');
        
        if ($lastUnderscorePos !== false) {
            $prefix = substr($string, 0, $lastUnderscorePos);
            $suffix = substr($string, $lastUnderscorePos + 1);
            
            return [
                'class' => $prefix,
                'field' => $suffix
            ];
        }

        return $string;
    }

    public function getIndexClasses()
    {
        return $this->config()->get('index_classes') ?: [SiteTree::class];
    }

    public function getExcludeClasses()
    {
        return $this->config()->get('exclude_classes') ?: [ErrorPage::class, RedirectorPage::class];
    }

    public function getIndexFields()
    {
        return $this->config()->get('index_fields') ?: ['Title', 'Content'];
    }

    public function getDataFields()
    {
        return $this->config()->get('data_fields') ?: ['Title', 'Content', 'Link'];
    }
    
}