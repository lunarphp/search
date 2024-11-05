<?php

namespace Lunar\Search\Data\Builder;

use Lunar\Search\Data\SearchFacet\FacetValue;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapName(SnakeCaseMapper::class)]
class SearchQuery extends Data
{
    public function __construct(
        public ?string $query = '',
        public array $facets = [],
        public array $facetFilters = []
    ) {}
}