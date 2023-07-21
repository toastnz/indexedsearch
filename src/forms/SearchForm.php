<?php

namespace Toast\IndexedSearch;

use SilverStripe\Forms\Form;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\TextField;
use SilverStripe\Forms\FormAction;
use SilverStripe\ORM\PaginatedList;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\RequestHandler;

class SearchForm extends Form
{
    private static $boost_fields = [];

    private static $boost_classes = [];

    private static $search_classes = [];

    private static $results_per_page = 10;

    private static $enable_pagination = true;

    private static $casting = [
        'SearchQuery' => 'Text'
    ];

    public function __construct(RequestHandler $controller = null, $name = 'SearchForm', FieldList $fields = null, FieldList $actions = null)
    {
        if (!$fields) {
            $fields = FieldList::create(
                TextField::create('Search', 'Search')
            );
        }

        if (!$actions) {
            $actions = FieldList::create(
                FormAction::create("results", 'Go')
            );
        }

        parent::__construct($controller, $name, $fields, $actions);

        $this->setFormMethod('get');
        $this->disableSecurityToken();

        $this->extend('updateForm', $this);
    }


    public function getResults($searchClasses = null, $boostFields = null, $boostClasses = null)
    {
        $searchClasses = $searchClasses ?: ($this->config()->get('search_classes') ?: [SiteTree::class]);
        $boostFields = $boostFields ?: ($this->config()->get('boost_fields') ?: []);
        $boostClasses = $boostClasses ?: ($this->config()->get('boost_classes') ?: []);
        $disableSubsiteFilterClasses = $this->config()->get('disable_subsite_filter_classes') ?: null;

        $request = $this->getRequestHandler()->getRequest();

        $searchTerm = $request->requestVar('Search');

        $this->extend('updateSearchTerm', $searchTerm);

        $result = SearchIndex::singleton();
        $result->config()->set('search_allow_empty_query', true);

        if ($searchClasses) {
            $result = $result
                ->setSearchClasses($searchClasses);
        }

        if ($boostFields) {
            $result = $result
                ->setSearchBoostFields($boostFields);
        }

        if ($boostClasses) {
            $result = $result
                ->setSearchBoostClasses($boostClasses);
        }        

        if ($disableSubsiteFilterClasses) {
            $result = $result
                ->setDisableSubsiteFilterClasses($disableSubsiteFilterClasses);
        }

        $result = $result
            ->search($searchTerm);


        $this->extend('updateResults', $result);

        if ($this->config()->get('enable_pagination') === false) {
            return $result->Matches;
        }

        $results = PaginatedList::create($result->Matches, $this->getRequest())
            ->setPageLength($this->config()->get('results_per_page'))
            ->setPaginationGetVar('start');

        $this->extend('updatePaginatedResults', $results);

        return $results;
    }


    public function getSearchQuery()
    {
        return $this->getRequestHandler()->getRequest()->requestVar('Search');
    }
}