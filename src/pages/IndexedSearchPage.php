<?php

namespace Toast\IndexedSearch;

use SilverShop\Page\Product;
use SilverStripe\Control\Director;
use Toast\IndexedSearch\SearchForm;
use SilverStripe\Control\Controller;
use SilverStripe\Core\Config\Config;
use SilverStripe\Control\HTTPRequest;

class IndexedSearchPage extends \Page
{
    private static $singular_name = 'Indexed Search Page';

    private static $plural_name = 'Indexed Search Pages';

    private static $description = 'Customisable search page';

    private static $table_name = 'IndexedSearchPage';

    private static $icon_class = 'font-icon-block-search';

    private static $defaults = [
        'ShowInSearch' => false
    ];

}

class IndexedSearchPageController extends \PageController
{

    private static $allowed_actions = [
        'get_results'
    ];

    public function init()
    {
        parent::init();
        // Requirements::css('toastnz/indexed-search: client/dist/styles/indexed-search.css');
        // Requirements::javascript('toastnz/indexed-search: client/dist/scripts/indexed-search.js');
        $this->getResponse()->addHeader('X-Robots-Tag', 'noindex');
    }

    public function get_results(HTTPRequest $request)
    {
        $output = [];
        $filters = [];

        $searchTerms = $request->requestVar('search');

        $result = SearchIndex::singleton()
            ->setSearchClasses(Config::inst()->get(SearchForm::class, 'search_classes'))
            ->setSearchBoostClasses(Config::inst()->get(SearchForm::class, 'boost_classes'))
            ->setSearchBoostFields(Config::inst()->get(SearchForm::class, 'boost_fields'))
            ->setSearchFilterableFields([
                'Categories.Title' => 'Categories',
                'Tags.Title' => 'Tags',
                'Subcategories.Title' => 'Subcategories',
                'MatchingRanges.Title' => 'Matching Ranges',
                'Colour.Title' => 'Colour',
                'Range.Title' => 'Range',
                'Traps.Title' => 'Traps'
            ]);

        
        if ($searchFilters = $request->requestVar('filters')) {
            $searchFilters = json_decode($searchFilters, true);

            if (is_array($searchFilters)) {
                $result = $result
                    ->setSearchFilters($searchFilters);
            }
        }

        $result = $result
            ->search($searchTerms);


        foreach ($result->Matches as $record) {
            $output[] = [
                'title' => $record->Title,
                'link' => $record->Link,
                'class' => $record->ClassName
            ];
        }

        foreach ($result->Filters as $filter) {
            $options = [];

            foreach($filter->Options as $option) {
                $options[] = [
                    'value' => $option->Value,
                    'count' => $option->Total
                ];
            }

            $filters[] = [
                'field' => $filter->Field,
                'label' => $filter->Label,
                'options' => $options
            ];
        }


        $this->getResponse()->addHeader('Content-Type', 'application/json');

        return json_encode([
            'filters' => $filters,
            'results' => $output
        ], JSON_UNESCAPED_SLASHES);
    }

}