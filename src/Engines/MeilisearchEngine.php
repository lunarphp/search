<?php

namespace Lunar\Search\Engines;

use Lunar\Search\Data\SearchFacet;
use Lunar\Search\Data\SearchHit;
use Lunar\Search\Data\SearchResults;
use Meilisearch\Endpoints\Indexes;

class MeilisearchEngine extends AbstractEngine
{
    public function get(): SearchResults
    {
        $paginator = $this->getRawResults(function (Indexes $indexes, string $query, array $options) {

            $filters = collect();

            foreach ($this->filters as $key => $value) {
                $filters->push(
                    $this->mapFilter($key, $value)
                );
            }

            foreach ($this->facets as $field => $values) {
                $filters->push(
                    $this->mapFilter($field, $values)
                );
            }

            $options['limit'] = $this->perPage;
            $options['sort'] = blank($this->sort) ? null : [$this->sort];

            if ($filters->count()) {
                $options['filter'] = $filters->join('AND');
            }

            $facets = $this->getFacetConfig();

            $options['facets'] = array_keys($facets);

            return $indexes->search($query, $options);
        });

        $results = $paginator->items();

        $documents = collect($results['hits'])->map(fn ($hit) => SearchHit::from([
            'highlights' => collect(),
            'document' => $hit,
        ]));

        $facets = collect($results['facetDistribution'])->map(
            fn ($values, $field) => SearchFacet::from([
                'field' => $field,
                'values' => collect($values)->map(
                    fn ($count, $value) => SearchFacet\FacetValue::from([
                        'value' => $value,
                        'count' => $count,
                    ])
                )->values(),
            ])
        )->values();

        foreach ($facets as $facet) {
            $facetConfig = $this->getFacetConfig($facet->field);
            foreach ($facet->values as $faceValue) {
                if (empty($facetConfig[$faceValue->value])) {
                    continue;
                }
                $faceValue->additional($facetConfig[$faceValue->value]);
            }
        }

        $data = [
            'query' => $results['query'],
            'total_pages' => $paginator->lastPage(),
            'page' => $paginator->currentPage(),
            'count' => $paginator->total(),
            'per_page' => $paginator->perPage(),
            'hits' => $documents,
            'facets' => $facets,
            'links' => $paginator->appends([
                'perPage' => $this->perPage,
                'facets' => http_build_query($this->facets),
            ])->linkCollection()->toArray(),
        ];

        return SearchResults::from($data);
    }

    protected function mapFilter(string $field, mixed $value): string
    {
        $values = collect($value);

        if ($values->count() > 1) {
            $values = $values->map(
                fn ($value) => "{$field} = \"{$value}\""
            );

            return '('.$values->join(' OR ').')';
        }

        return '('.$field.' = "'.$values->first().'")';
    }

    protected function getFieldConfig(): array
    {
        return [];
    }
}
