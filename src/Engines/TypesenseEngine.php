<?php

namespace Lunar\Search\Engines;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;
use Laravel\Scout\EngineManager;
use Laravel\Scout\Scout;
use Lunar\Search\Data\SearchFacet;
use Lunar\Search\Data\SearchHit;
use Lunar\Search\Data\SearchResults;
use Typesense\Documents;
use Typesense\Exceptions\ServiceUnavailable;

class TypesenseEngine extends AbstractEngine
{
    protected function buildSearchOptions(array $options, string $query, $useFacetFilters = true): array
    {
        $filters = collect($options['filter_by']);

        foreach ($this->filters as $key => $value) {
            $filters->push($key.':'.collect($value)->join(','));
        }

        if ($useFacetFilters) {
            foreach ($this->facets as $field => $values) {
                $values = collect($values)->map(function ($value) {
                    if ($value == 'false' || $value == 'true') {
                        return $value;
                    }
                    return '`'.$value.'`';
                });


                if ($values->count() > 1) {
                    $filters->push($field.':['.collect($values)->join(',').']');

                    continue;
                }

                $filters->push($field.':'.collect($values)->join(','));
            }
        }

        $options['q'] = $query;
        $facets = $this->getFacetConfig();
        $facetBy = array_keys($facets);

        foreach ($facets as $field => $config) {
            if (!($config['hierarchy'] ?? false)) {
                continue;
            }
            unset(
                $facetBy[array_search($field, $facetBy)]
            );
            $facetBy = [
                ...$facetBy,
                ...array_map(
                fn ($value) => "{$field}.{$value}",
                $config['levels'] ?? []
                )
            ];
        }

        $options['facet_by'] = implode(',', $facetBy);
        $options['max_facet_values'] = 50;

        $options['sort_by'] = $this->sortByIsValid() ? $this->sort : '';

        if ($filters->count()) {
            $options['filter_by'] = $filters->join(' && ');
        }

        return $options;
    }

    public function get(): SearchResults
    {
        try {
            $paginator = $this->getRawResults(function (Documents $documents, string $query, array $options) {
                $engine = app(EngineManager::class)->engine('typesense');

                $searchRequests = [
                    'searches' => [
                        $this->buildSearchOptions($options, $query),
                        $this->buildSearchOptions($options, $query, useFacetFilters: false)
                    ]
                ];

                $response = $engine->getMultiSearch()->perform($searchRequests, [
                    'collection' => (new $this->modelType)->searchableAs(),
                ]);

                return [
                    ...$response['results'][0],
                    'unfaceted_response' => $response['results'][1],
                ];
            });

        } catch (\GuzzleHttp\Exception\ConnectException|ServiceUnavailable  $e) {
            Log::error($e->getMessage());
            $paginator = new LengthAwarePaginator(
                items: [
                    'hits' => [],
                ],
                total: 0,
                perPage: $this->perPage,
                currentPage: 1,
            );
        }

        $results = $paginator->items();

        $documents = collect($results['hits'])->map(fn ($hit) => SearchHit::from([
            'highlights' => collect($hit['highlights'] ?? [])->map(
                fn ($highlight) => SearchHit\Highlight::from([
                    'field' => $highlight['field'],
                    'matches' => $highlight['matched_tokens'],
                    'snippet' => $highlight['snippet'],
                ])
            ),
            'document' => $hit['document'],
        ]));

//        $facets = [];
//        $hierarchyFacets = [];
//
//        foreach ($preResults['facet_counts'] ?? [] as $facet) {
//            $facetConfig = $this->getFacetConfig($facet['field_name']);
//
//            $nested = count(explode('.', $facet['field_name'])) > 1;
//
//            if ($nested) {
//                $nestedField = explode('.', $facet['field_name'])[0];
//
//                $hierarchyFacets[$nestedField][] = $facet;
//
//                continue;
//            }
//
//            $facets[] = SearchFacet::from([
//                'label' => $this->getFacetConfig($facet['field_name'])['label'] ?? '',
//                'field' => $facet['field_name'],
//                'hierarchy' => $nested,
//                'values' => collect($facet['counts'])->map(
//                    fn ($value) => SearchFacet\FacetValue::from([
//                        'label' => $value['value'],
//                        'value' => $value['value'],
//                        'count' => $value['count'],
//                    ])
//                ),
//            ]);
//        }


        $facets = collect($paginator['unfaceted_response']['facet_counts'] ?? [])->map(
            fn ($facet) => SearchFacet::from([
                'label' => $this->getFacetConfig($facet['field_name'])['label'] ?? '',
                'field' => $facet['field_name'],
                'values' => collect($facet['counts'])->map(
                    fn ($value) => SearchFacet\FacetValue::from([
                        'label' => $value['value'],
                        'value' => $value['value'],
                        'count' => $value['count'],
                    ])
                ),
            ])
        );

        foreach ($facets as $facet) {
            $facetConfig = $this->getFacetConfig($facet->field);

            foreach ($facet->values as $facetValue) {
                $valueConfig = $facetConfig['values'][$facetValue->value] ?? null;

                if (! $valueConfig) {
                    continue;
                }

                $facetValue->label = $valueConfig['label'] ?? $facetValue->value;
                unset($valueConfig['label']);

                $facetValue->additional($valueConfig);
            }
        }

        $data = [
            'query' => $this->query,
            'total_pages' => $paginator->lastPage(),
            'page' => $paginator->currentPage(),
            'count' => $paginator->total(),
            'per_page' => $paginator->perPage(),
            'hits' => $documents,
            'facets' => $facets,
            'links' => $paginator->appends([
                'facets' => http_build_query($this->facets),
            ])->links(),
        ];

        return SearchResults::from($data);
    }

    protected function sortByIsValid(): bool
    {
        $sort = $this->sort;

        if (! $sort) {
            return true;
        }

        $parts = explode(':', $sort);

        if (! isset($parts[1])) {
            return false;
        }

        if (! in_array($parts[1], ['asc', 'desc'])) {
            return false;
        }

        $config = $this->getFieldConfig();

        if (empty($config)) {
            return false;
        }

        $field = collect($config)->first(
            fn ($field) => $field['name'] == $parts[0]
        );

        return $field && ($field['sort'] ?? false);
    }

    protected function getFieldConfig(): array
    {
        return config('scout.typesense.model-settings.'.$this->modelType.'.collection-schema.fields', []);
    }
}
