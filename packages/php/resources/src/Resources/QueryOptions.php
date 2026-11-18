<?php
namespace ZoltaGaska\Resources;

use ZoltaGaska\Http\HttpException;
use ZoltaGaska\Http\Request;

final class QueryOptions
{
    public function __construct(
        public readonly int $limit,
        public readonly ?string $cursor,
        public readonly ?string $sort,
        public readonly string $direction,
        public readonly array $filters,
    ) {}

    public static function fromRequest(Request $request, array $sortable, array $filterable): self
    {
        $limit = $request->query('limit', '25');
        $cursor = $request->query('cursor');
        $sort = $request->query('sort');
        $direction = $request->query('direction', 'asc');
        $filters = $request->query('filter', []);
        if (!is_string($limit) || !ctype_digit($limit) || (int) $limit < 1 || (int) $limit > 100
            || ($cursor !== null && (!is_string($cursor) || strlen($cursor) > 1024))
            || ($sort !== null && (!is_string($sort) || !in_array($sort, $sortable, true)))
            || !in_array($direction, ['asc', 'desc'], true) || !is_array($filters)) {
            throw new HttpException(400, 'Invalid query options.');
        }
        foreach ($filters as $field => $value) {
            if (!in_array($field, $filterable, true) || !is_string($value) || strlen($value) > 1024) {
                throw new HttpException(400, 'Invalid filter.');
            }
        }
        return new self((int) $limit, $cursor, $sort, $direction, $filters);
    }
}
