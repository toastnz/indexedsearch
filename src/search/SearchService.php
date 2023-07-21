<?php

namespace Toast\IndexedSearch;

use SilverStripe\ORM\DB;
use SilverStripe\Core\Convert;
use SilverStripe\ORM\ArrayList;
use SilverStripe\Core\ClassInfo;
use SilverStripe\View\ArrayData;
use SilverStripe\Core\Extensible;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;

class SearchService
{
    use Injectable;
    use Configurable;
    use Extensible;

    public function doSearch($query, $classes = null, $boostFields = null, $boostClasses = null, $fuzzy = false, $filters = null, $allowEmptyQuery = false, $disableSubsiteFilter = false, $disableSubsiteFilterClasses = null)
    {
        $query = strtolower(!$query ? ' ' : $query);        
        $query = trim(preg_replace('/\s+/', ' ', $query));
        $query = $query ? strip_tags($query) : $query;

        $queryCheck = $this->getFulltextQuery(Convert::raw2sql($query));

        $isEmptyQuery = !$queryCheck && $allowEmptyQuery;
        
        $fulltextSelectDatabaseQueryPart = null;
        $fulltextWhereDatabaseQueryPart = null;
        
        if (!$isEmptyQuery) {

            if ($fuzzy) {
                // Fuzzy search
                $fuzzyQuery = $this->getFulltextQuery(Convert::raw2sql($query), true);
                $fulltextQuery = $this->getFulltextQuery(Convert::raw2sql($query));

                $fulltextSelectDatabaseQueryPart = '
                    (
                        MATCH(FuzzyData) AGAINST(\'' . $fuzzyQuery . '\' IN BOOLEAN MODE) + 
                        MATCH(RawData) AGAINST(\'' . $fulltextQuery . '\' IN BOOLEAN MODE)
                    ) AS Similarity
                ';

                $fulltextWhereDatabaseQueryPart = '
                    MATCH(FuzzyData) AGAINST(\'' . $fuzzyQuery . '\' IN BOOLEAN MODE) OR
                    MATCH(RawData) AGAINST(\'' . $fulltextQuery . '\' IN BOOLEAN MODE)
                ';
                
            } else {
                // Fulltext search
                $fulltextQuery = $this->getFulltextQuery(Convert::raw2sql($query));

                $fulltextSelectDatabaseQueryPart = 'MATCH(RawData) AGAINST(\'' . $fulltextQuery . '\' IN BOOLEAN MODE) AS Similarity';
                $fulltextWhereDatabaseQueryPart = 'MATCH(RawData) AGAINST(\'' . $fulltextQuery . '\' IN BOOLEAN MODE)';
            }
        }

        // Search classes
        $classesDatabaseQueryPart = '';

        if ($classes) {            
            $classesToSearch = [];
            $excludedClasses = Config::inst()->get(SearchIndex::class, 'exclude_classes');

            foreach($classes as $class) {
                foreach(ClassInfo::subclassesFor($class) as $subClass) {

                    if (!is_array($excludedClasses) || !in_array($subClass, $excludedClasses)) {
                        $classesToSearch[] = Convert::raw2sql($subClass);
                    }
                }
            }

            $classesDatabaseQueryPart = 'RecordClass IN (\'' . implode('\', \'', $classesToSearch) . '\')';    
        }

        // Subsite filter
        $subsiteDatabaseQueryPart = '';

        if (!$disableSubsiteFilter) {
            if (class_exists(\SilverStripe\Subsites\Model\Subsite::class)) {
                if (!\SilverStripe\Subsites\Model\Subsite::$disable_subsite_filter) {

                    if ($disableSubsiteFilterClasses) {                    
                        $disableClasses = array_map('addslashes', $disableSubsiteFilterClasses);

                        $subsiteDatabaseQueryPart = 'IF (RecordClass NOT IN (\'' . implode('\', \'', $disableClasses) . '\'), SubsiteID = ' . (int)\SilverStripe\Subsites\State\SubsiteState::singleton()->getSubsiteId() . ', 1=1)';

                    } else {
                        $subsiteDatabaseQueryPart = 'SubsiteID = ' . (int)\SilverStripe\Subsites\State\SubsiteState::singleton()->getSubsiteId();
                    }

                }
            }
        }

        // Fulltext query
        $databaseQuery = '
            SELECT 
                *
                ' . ($fulltextSelectDatabaseQueryPart ? ', ' . $fulltextSelectDatabaseQueryPart : '') . '
            FROM
                IndexedSearch_SearchIndexEntry
            WHERE
                ' . ($subsiteDatabaseQueryPart ? $subsiteDatabaseQueryPart . ($classesDatabaseQueryPart || $fulltextWhereDatabaseQueryPart ? ' AND ' : '') : '') . '
                ' . ($classesDatabaseQueryPart ? $classesDatabaseQueryPart . ($fulltextWhereDatabaseQueryPart ? ' AND ' : '') : '') . '
                ' . ($fulltextWhereDatabaseQueryPart ? $fulltextWhereDatabaseQueryPart : '') . '
        ';

        $this->extend('updateDatabaseQuery', $databaseQuery);
        
        $dbEntries = DB::query($databaseQuery);
        $entries = [];
        
        // Build result set
        $results = ArrayList::create();
        
        foreach($dbEntries as $entry) {
            $entries[] = $entry;

            // Apply filters
            if ($filters && !$this->passFilters($entry['FilterableData'], $filters)) {
                continue;
            }

            // Calculate boosting score
            $boostingScore = 0;

            if (!$isEmptyQuery && $boostFields) {
                foreach ($boostFields as $field => $boost) {                
                    $boostData = singleton(SearchIndex::class)->getClassAndField($field);                
    
                    if (is_array($boostData)) {
                        $field = $boostData['field'];
    
                        // do not update score if class does not match
                        if ($entry['RecordClass'] != $boostData['class']) {
                            continue;
                        }
                    }

                    $data = json_decode($entry['IndexData'], true);

                    if ($data && isset($data['IndexData'])) {
                        $data = $data['IndexData'];
                        
                        if (isset($data[$field])) {
                            $fieldValue = $data[$field];
                            $matchCount = 0;    
                            $queryWords = explode(' ', $query);
                            $queryWords = array_map('strtolower', $queryWords);
            
                            foreach ($queryWords as $word) {
                                if (stripos($fieldValue, $word) !== false) {
                                    $matchCount++;
                                }
                            }
            
                            if ($matchCount > 0) {
                                $boostingScore += ($matchCount / count($queryWords)) * $boost; 
                            }
                        }
                    }                
                }
            }


            if ($recordData = $entry['RecordData']) {
                if ($data = json_decode($recordData, true)) {
                    $data = array_merge($data, [
                        'Search___Similarity' => isset($entry['Similarity']) ? $entry['Similarity'] : 0,
                        'Search___BoostSimilarity' => $boostingScore,
                        'Search___ClassScore' => $boostClasses && isset($boostClasses[$data['ClassName']]) ? $boostClasses[$data['ClassName']] : 0
                    ]);

                    $results->push(ArrayData::create($data));
                }
            }

        }

        $this->extend('updateResults', $results);

        return ArrayData::create([
            'Matches' => $results,
            'IndexEntries' => $entries
        ]);

        return $results;
    }    


    private function passFilters($filterableData, $filters)
    {
        $filterableData = json_decode($filterableData, true);

        foreach($filters as $filter => $value) {

            if (isset($filterableData[$filter])) {
                $passValue = false;

                foreach($filterableData[$filter] as $filterValue) {

                    if (is_array($value)) {                                    
                        foreach($value as $valueItem) {
                            if ($filterValue == $valueItem) {
                                $passValue = true;
                                break;
                            }
                        }

                    } elseif ($filterValue == $value) {
                        $passValue = true;
                        break;
                    }

                    if ($passValue) {
                        break;
                    }
                }

                if (!$passValue) {
                    return false;
                }
            }
        }

        return true;
    }    

    private function getFulltextQuery($input, $fuzzy = false)
    {
        $reservedChars = ['%', '+', '!', '(', ')', '~', '*', '"', "'", "-", "@"];
        $cleanQuery = str_replace($reservedChars, ' ', $input);

        if ($fuzzy) {
            $parts = [];

            foreach (explode(' ', $cleanQuery) as $word) {
                $parts[] = '+' . soundex($word);
            }

            return implode(' ', $parts);
        }

        $query = str_replace(' ', '* ', $cleanQuery) . '*';
        $query = str_replace(' *', '', $query);
        $query = $query == '*' ? '' : $query;

        return $query;
    }


}
