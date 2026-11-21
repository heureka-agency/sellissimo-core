<?php

namespace Sellissimo\Core\Search;

use Sellissimo\Core\OrderNumber;
use Statamic\Contracts\Search\Searchable;
use Statamic\Contracts\Search\Result;

final class StoreRecord implements Searchable
{
    public function __construct(public string $kind, public object $row) {}

    public function getSearchReference(): string
    {
        return 'sellissimo::'.$this->kind.'/'.($this->row->id ?? $this->row->code);
    }

    public function getSearchValue(string $field)
    {
        return $field === 'title' ? $this->getCpSearchResultTitle() : null;
    }

    public function getCpSearchResultTitle(): string
    {
        return $this->kind === 'order' ? __('Order').' '.OrderNumber::display($this->row) : $this->row->code;
    }

    public function getCpSearchResultUrl(): string
    {
        return $this->kind === 'order' ? cp_route('sellissimo.order', $this->row->id)
            : cp_route('sellissimo.rules', ['resource' => 'discounts', 'q' => $this->row->code, 'edit' => $this->row->code]);
    }

    public function getCpSearchResultBadge(): string
    {
        return $this->kind === 'order' ? __('Order') : __('Discount');
    }

    public function getCpSearchResultIcon(): string
    {
        return $this->kind === 'order' ? 'shopping-cart' : 'money-cashier-price-tag';
    }

    public function toSearchResult(): Result
    {
        return new \Statamic\Search\Result($this, 'sellissimo');
    }
}
