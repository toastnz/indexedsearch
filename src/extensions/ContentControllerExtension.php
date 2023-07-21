<?php

namespace Toast\IndexedSearch;

use SilverStripe\Core\Extension;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\FormAction;
use SilverStripe\Forms\TextField;
use SilverStripe\ORM\FieldType\DBField;

class ContentControllerExtension extends Extension
{
    private static $allowed_actions = [
        'SearchForm',
        'results'
    ];

    public function SearchForm()
    {
        $searchText =  '';

        $currentRequest = $this->owner->getRequest();

        if ($currentRequest && $currentRequest->getVar('Search')) {
            $searchText = $currentRequest->getVar('Search');
        }

        $fields = FieldList::create(
            TextField::create('Search', false, $searchText)
                ->setAttribute('placeholder', 'Search')
        );

        $actions = FieldList::create(
            FormAction::create('searchResults', 'Go')
        );

        return SearchForm::create($this->owner, 'SearchForm', $fields, $actions);
    }

    public function searchResults($data, $form, $request)
    {
        $data = [
            'Results' => $form->getResults(),
            'Query' => DBField::create_field('Text', $form->getSearchQuery()),
            'Title' => 'Search Results'
        ];

        return $this->owner->customise($data)->renderWith([
            'Page_results_indexedsearch', 
            'Page_results', 
            'Page'
        ]);
    }
}
