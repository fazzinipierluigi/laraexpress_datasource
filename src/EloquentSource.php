<?php
/**
 * @author Pierluigi Fazzini <fazzinipielruigi@gmail.com>
 */

namespace Fazzinipierluigi\LaraexpressDatasource;

use Carbon\Carbon;
use Carbon\CarbonTimeZone;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class EloquentSource
{
	/**
	 * @var
	 */
	private $data_grid_raw_dataset;
	/**
	 * @var
	 */
	private $data_grid_filtered_dataset;
	/**
	 * @var
	 */
	private $filter_request;

	/**
	 * @var null
	 */
	private $total_count = NULL;
	/**
	 * @var null
	 */
	private $total_summary = NULL;
	/**
	 * @var null
	 */
	private $group_count = NULL;
	private $groups_tree = NULL;
	/**
	 * @var int
	 */
	private $groups_full_count = 0;

	private $default_timezone;
	private $utc_timezone;

	public function __construct(string $tz = 'Europe/Rome')
	{
		$this->default_timezone = new CarbonTimeZone($tz);
		$this->utc_timezone = new CarbonTimeZone('UTC');
	}

	/**
	 * @param string $tz The timezone valid rappresenation
	 * @return void
	 * */
	public function setTimezone(?string $tz = NULL)
	{
		if (!empty($tz))
			$this->default_timezone = new CarbonTimeZone($tz);
	}

	/**
	 * @param \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder $data_set An instance of the query builder on which the filters will be applied
	 * @param \Illuminate\Http\Request $request The instance of the "request" coming from the client
	 * @param array|null $request The instance of the "request" coming from the client
	 * @return void
	 * */
	public function apply($data_set, $request, $field_map = NULL, $order_map = NULL)
	{
		if(empty($data_set) && !in_array(get_class($data_set), ["Illuminate\Database\Query\Builder", "Illuminate\Database\Eloquent\Builder"]))
			throw new \Exception('The "data_set" parameter must not be empty, and must be an instance of the classes: "Illuminate\Database\Query\Builder" or "Illuminate\Database\Eloquent\Builder"');

		if(empty($request) && get_class($request) != "Illuminate\Http\Request")
			throw new \Exception('The parameter "request" must not be empty, and must be an instance of the class "Illuminate\Http\Request"');

        if(!is_null($field_map) && !is_array($field_map))
            throw new \Exception('The parameter "field_map" must be null or an array');

		# Save provided query to local variables
		$this->data_grid_raw_dataset = clone $data_set;
		$this->data_grid_filtered_dataset = clone $data_set;
		# Get data from request to array format
		$this->filter_request = $request;
		$data_filters = $request->all();

		if(!empty($data_filters['filter']))
		{
			$filters = json_decode($data_filters['filter']);
			if(!empty($filters))
			{
				# If there are filter, apply
				$this->data_grid_filtered_dataset = $this->processFilters($filters, $this->data_grid_filtered_dataset,$field_map);
			}
		}

		# Retrieve total count if required
		$this->total_count = (!empty($data_filters["requireTotalCount"])) ? $this->data_grid_filtered_dataset->get()->count() : NULL;

		if(!empty($data_filters['sort']))
		{
			$sorts = json_decode($data_filters['sort'],false);
			if(!empty($sorts))
			{
				foreach($sorts as $sort)
				{
					if(is_object($sort))
					{
						if(!empty($order_map[$sort->selector]) && is_string($order_map[$sort->selector]))
						{
							$this->data_grid_filtered_dataset->orderBy($order_map[$sort->selector],(!empty($sort->desc))?'DESC':'ASC');
						}
						elseif(!empty($field_map[$sort->selector]))
						{
							if(is_string($field_map[$sort->selector]))
								$this->data_grid_filtered_dataset->orderBy($field_map[$sort->selector],(!empty($sort->desc))?'DESC':'ASC');
							elseif($field_map[$sort->selector] instanceof Expression)
								$this->data_grid_filtered_dataset->orderBy($field_map[$sort->selector],(!empty($sort->desc))?'DESC':'ASC');
							elseif(is_array($field_map[$sort->selector]))
								$this->data_grid_filtered_dataset->orderBy($field_map[$sort->selector][0],(!empty($sort->desc))?'DESC':'ASC');
						}
						else
							$this->data_grid_filtered_dataset->orderBy($sort->selector, (!empty($sort->desc))?'DESC':'ASC');
					}
					elseif(is_string($sort))
					{
						if(!empty($order_map[$sort]) && is_string($order_map[$sort]))
						{
							$this->data_grid_filtered_dataset->orderBy($order_map[$sort]);
						}
						elseif(!empty($field_map[$sort]))
						{
							if(is_string($field_map[$sort]))
								$this->data_grid_filtered_dataset->orderBy($field_map[$sort]);
							elseif($field_map[$sort] instanceof Expression)
								$this->data_grid_filtered_dataset->orderBy($field_map[$sort]);
							elseif(is_array($field_map[$sort]))
								$this->data_grid_filtered_dataset->orderBy($field_map[$sort][0]);
						}
						else
							$this->data_grid_filtered_dataset->orderBy($sort);

					}
				}
			}
		}

		if(!empty($data_filters['group']))
		{
			$group_expression = json_decode($data_filters["group"],false);
			$group_summary = $data_filters["groupSummary"] ?? NULL;
			if(is_string($group_summary))
				$group_summary = json_decode($group_summary,1);

			$this->processGroups($group_expression, $field_map, ($data_filters['skip'] ?? 0), ($data_filters['take'] ?? 8446744073709551616));

			# Retrieve group count if required (number of top level groups, before paging)
			$this->group_count = (!empty($data_filters["requireGroupCount"])) ? $this->groupCount() : NULL;

			# With remote group paging, skip/take apply to the top level groups
			$this->groups_tree = array_slice($this->groups_tree, (int) ($data_filters['skip'] ?? 0), (int) ($data_filters['take'] ?? PHP_INT_MAX));
		}
		else
		{
			$this->data_grid_filtered_dataset->skip(($data_filters['skip'] ?? 0))->take(($data_filters['take'] ?? 8446744073709551616));
		}
	}

	/**
	 * @return mixed
	 */
	public function getData()
	{
		return $this->data_grid_filtered_dataset;
	}

	/**
	 * @param $output
	 * @return array
	 * @throws \Exception
	 */
	public function getArray($output = NULL)
	{
		if(is_null($this->groups_tree))
		{
			$response = [];

			if(strtolower(get_class($output)) === "closure")
			{
				$response["data"] = [];
				foreach($this->data_grid_filtered_dataset->get() as $data_row)
				{
					$tmp_data = $output($data_row);
					if(!is_array($tmp_data))
						throw new \Exception('The function you provided must return an associative array');

					if(!empty($tmp_data))
						if(!$this->is_multi_array($tmp_data))
							$response["data"][] = $tmp_data;
						else
							$response["data"] = array_merge($response["data"], $tmp_data);
				}
			}
			else
			{
				$response["data"] = $this->data_grid_filtered_dataset->get()->toArray();
			}

			if(!is_null($this->total_count))
			{
				$response["totalCount"] = $this->total_count;
			}
			if(!is_null($this->total_summary))
			{
				$response["summary"] = $this->total_summary;
			}
			if(!is_null($this->group_count))
			{
				$response["groupCount"] = $this->group_count;
			}
		}
		else
		{
			$response = ["data" => $this->groups_tree];

			if(!is_null($this->total_count))
				$response["totalCount"] = $this->total_count;
			if(!is_null($this->group_count))
				$response["groupCount"] = $this->group_count;
			if(!is_null($this->total_summary))
				$response["summary"] = $this->total_summary;
		}

		return $response;
	}

	/**
	 * @param $output
	 * @return \Illuminate\Http\JsonResponse
	 * @throws \Exception
	 */
	public function getResponse($output = NULL)
	{
		$response_array = $this->getArray($output);
		return response()->json($response_array);
	}

	/**
	 * @param $expression
	 * @param $query
	 * @param $fields_map
	 * @return mixed
	 */
	private function processFilters($expression, $query, $fields_map)
	{
		$clause = 'where';
		foreach($expression as $index => $item)
		{
			if (is_string($item))
			{
				if($item === "!")
				{
					$clause = 'whereNot';
					if(!empty($expression[$index-1]) && $expression[$index-1] === "or")
						$clause = 'orWhereNot';

					continue;
				}
				elseif($item === "or")
				{
					$clause = 'orWhere';
					continue;
				}
				elseif($item === "and")
				{
					$clause = 'where';
					continue;
				}

				if ($index == 0)
				{
					if(count($expression) == 2)
                    {
                        if(empty($fields_map[$expression[0]]))
                            $query->where($expression[0], $expression[1]);
                        else
							$this->setFilter($query,'where',$fields_map[$expression[0]],'=',$expression[1]);
                    }
					else
					{
						$operator = trim($expression[1]);
						$value = $expression[2];

						if(is_null($value))
						{
							if($operator === '=')
							{
								if(empty($fields_map[$expression[0]]))
									$query->whereNull($expression[0]);
								else
								{
									if(is_string($fields_map[$expression[0]]))
										$query->whereNull($fields_map[$expression[0]]);
									elseif(is_array($fields_map[$expression[0]]))
									{
										$query->where(function($filter_clause) use ($fields_map, $expression) {
											$tmp_map = $fields_map[$expression[0]];
											$tmp_field = array_shift($tmp_map);

											$filter_clause->whereNull($tmp_field);

											foreach($tmp_map as $curr_field)
												$filter_clause->orWhereNull($curr_field);
										});
									}
								}
							}
							elseif($operator === '<>')
							{
								if(empty($fields_map[$expression[0]]))
									$query->whereNotNull($expression[0]);
								else
								{
									if(is_string($fields_map[$expression[0]]))
										$query->whereNotNull($fields_map[$expression[0]]);
									elseif(is_array($fields_map[$expression[0]]))
									{
										$query->where(function($filter_clause) use ($fields_map, $expression) {
											$tmp_map = $fields_map[$expression[0]];
											$tmp_field = array_shift($tmp_map);

											$filter_clause->whereNotNull($tmp_field);

											foreach($tmp_map as $curr_field)
												$filter_clause->orWhereNotNull($curr_field);
										});
									}
								}
							}
						}
						else
						{
							switch ($operator) {
								case "=":
								case "<>":
								case ">":
								case ">=":
								case "<":
								case "<=":
										if(empty($fields_map[$expression[0]]))
											$this->setFilter($query,$clause,$expression[0],$operator,$value);
										else
											$this->setFilter($query,$clause,$fields_map[$expression[0]],$operator,$value);
									break;

								case "startswith":
										if(empty($fields_map[$expression[0]]))
											$query->{$clause}($expression[0],'LIKE',$value.'%');
										else
											$this->setFilter($query,$clause,$fields_map[$expression[0]],'LIKE',$value.'%');
									break;
								case "endswith":
										if(empty($fields_map[$expression[0]]))
											$query->{$clause}($expression[0],'LIKE','%'.$value);
										else
											$this->setFilter($query,$clause,$fields_map[$expression[0]],'LIKE','%'.$value);
									break;
								case "contains": {
										if(empty($fields_map[$expression[0]]))
											$query->{$clause}($expression[0],'LIKE','%'.$value.'%');
										else
											$this->setFilter($query,$clause,$fields_map[$expression[0]],'LIKE','%'.$value.'%');
									break;
								}
								case "notcontains":
										if(empty($fields_map[$expression[0]]))
											$query->{$clause}($expression[0],'NOT LIKE','%'.$value.'%');
										else
											$this->setFilter($query,$clause,$fields_map[$expression[0]],'NOT LIKE','%'.$value.'%');
									break;
							}
						}
					}
					break;
				}
				continue;
			}
			if (is_array($item))
			{
				$query->{$clause}(function($block) use ($item, $fields_map) {
					return $this->processFilters($item, $block, $fields_map);
				});
			}
		}

		return $query;
	}

	private function setFilter(&$query, $clause, $field, $operator, $value)
	{
		if (preg_match('/^\d{4}-[01]\d-[0-3]\dT[0-2]\d:[0-5]\d:[0-5]\d(?:\.\d+)?Z?$/', $value)) {
			$value = (Carbon::createFromFormat('Y-m-d\TH:i:s', $value, $this->default_timezone))->setTimezone($this->utc_timezone);
		} elseif (preg_match('/^(([12]\d{3})-(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01]))$/', $value)) {
			$value = (Carbon::createFromFormat('Y-m-d', $value, $this->default_timezone))->setTimezone($this->utc_timezone);
		}

		if(is_bool($value))
		{
			$query->{$clause}(function($filter_clause) use ($clause, $field, $operator, $value) {
				$other_clause = '';
				if($value)
				{
					$filter_clause->{$clause}($field, $operator, $value);
				}
				else
				{
					$other_clause = 'orWhereNull';
					$filter_clause->{$clause}($field, $operator, $value)
								  ->{$other_clause}($field);
				}
			});
		}
		elseif($field instanceof Expression)
			$query->{$clause}($field, $operator, $value);
		elseif(is_string($field))
			$query->{$clause}($field, $operator, $value);
		elseif(is_array($field))
		{
			$query->{$clause}(function($filter_clause) use ($clause, $field, $operator, $value) {
				$tmp_map = $field;
				$tmp_field = array_shift($tmp_map);

				$filter_clause->where($tmp_field, $operator, $value);

				foreach($tmp_map as $curr_field)
					$filter_clause->orWhere($curr_field, $operator, $value);
			});
		}
	}

	/**
	 * @param $expression
	 * @param $summary
	 * @param $skip
	 * @param $take
	 * @return void
	 */
	private function processGroups($expression, $field_map, $skip, $take)
	{
		$new_query = clone $this->data_grid_filtered_dataset;
		$group_structure = [];
		if(!empty($expression))
		{
			# Any ORDER BY inherited from the sort options would reference
			# columns that are not part of the GROUP BY (ONLY_FULL_GROUP_BY)
			$new_query->reorder();

			$grammar = $new_query->getQuery()->getGrammar();
			$select_list = [];

			if (is_string($expression))
			{
				$group_items = [];
				foreach(explode(",", trim($expression)) as $selector)
					$group_items[] = (object) ['selector' => trim($selector)];
			}
			else
				$group_items = is_array($expression) ? $expression : [];

			foreach($group_items as $group_index => $col)
			{
				$direction = (empty($col->desc)) ? "ASC" : "DESC";
				$columns = $this->groupColumns($col->selector, $field_map, $col->groupInterval ?? NULL, $grammar);

				foreach($columns as $column_index => $sql)
				{
					$alias = 'FIELD_'.$group_index.'_'.$column_index;
					$group_structure[$group_index][] = $alias;
					$select_list[] = DB::raw($sql.' AS '.$alias);
					$new_query->groupBy(DB::raw($sql));
					$new_query->orderBy(DB::raw($sql), $direction);
				}
			}

			if(!empty($select_list))
			{
				$select_list[] = DB::raw('COUNT(1) AS leaf_count');
				$new_query->select($select_list);
			}
		}

		$this->groups_tree = [];
		if(empty($group_structure))
			return;

		foreach($new_query->get() as $row)
			$this->groups_tree = $this->add_tree($group_structure, $row, $this->groups_tree);

		$this->groups_full_count = count($this->groups_tree);
	}

	/**
	 * Resolves a group selector to the SQL expressions to group by: a mapped
	 * column name, an array of columns or a raw Expression, optionally wrapped
	 * by the DevExtreme groupInterval (date part or numeric bucket).
	 *
	 * @param string $selector
	 * @param array $field_map
	 * @param string|int|null $group_interval
	 * @param \Illuminate\Database\Grammar $grammar
	 * @return string[]
	 */
	private function groupColumns($selector, $field_map, $group_interval, $grammar)
	{
		$mapped = $field_map[$selector] ?? $selector;
		$fields = is_array($mapped) ? $mapped : [$mapped];

		$columns = [];
		foreach($fields as $field)
		{
			$sql = ($field instanceof Expression) ? (string) $field->getValue($grammar) : $grammar->wrap($field);
			$columns[] = $this->applyGroupInterval($sql, $group_interval);
		}

		return $columns;
	}

	/**
	 * @param string $sql
	 * @param string|int|null $group_interval
	 * @return string
	 */
	private function applyGroupInterval($sql, $group_interval)
	{
		if($group_interval === NULL || $group_interval === '')
			return $sql;

		if(is_numeric($group_interval))
			return 'FLOOR(('.$sql.') / '.(float) $group_interval.') * '.(float) $group_interval;

		switch($group_interval)
		{
			case 'year':      return 'YEAR('.$sql.')';
			case 'quarter':   return 'QUARTER('.$sql.')';
			case 'month':     return 'MONTH('.$sql.')';
			case 'day':       return 'DAY('.$sql.')';
			case 'dayOfWeek': return '(DAYOFWEEK('.$sql.') - 1)';
			case 'hour':      return 'HOUR('.$sql.')';
			case 'minute':    return 'MINUTE('.$sql.')';
			case 'second':    return 'SECOND('.$sql.')';
		}

		return $sql;
	}

	/**
	 * Adds a grouped row (one row per distinct combination of the group
	 * columns, with its leaf_count) to the group tree, level by level.
	 *
	 * @param array $fields Aliases of the group columns, per level
	 * @param object $row
	 * @param array $array The groups of the current level
	 * @param int $level
	 * @return array
	 */
	private function add_tree($fields, $row, $array, $level = 0)
	{
		if($level >= count($fields))
			return $array;

		$is_last_level = ($level === count($fields) - 1);

		# A selector mapped to several columns places the row in the group of
		# every column that has a value; without any value it is a blank group
		$values = [];
		foreach($fields[$level] as $column)
		{
			if(!is_null($row->$column) && !in_array($row->$column, $values, TRUE))
				$values[] = $row->$column;
		}
		if(empty($values))
			$values[] = NULL;

		foreach($values as $value)
		{
			$index = NULL;
			foreach($array as $key => $group)
			{
				if($group['key'] === $value)
				{
					$index = $key;
					break;
				}
			}

			if(is_null($index))
			{
				$array[] = [
					'key'   => $value,
					'count' => 0,
					'items' => $is_last_level ? NULL : [],
				];
				$index = array_key_last($array);
			}

			$array[$index]['count'] += (int) $row->leaf_count;

			if(!$is_last_level)
				$array[$index]['items'] = $this->add_tree($fields, $row, $array[$index]['items'], $level + 1);
		}

		return $array;
	}

	/**
	 * Number of top level groups, before paging.
	 *
	 * @return int
	 */
	private function groupCount()
	{
		return $this->groups_full_count;
	}

	private function is_multi_array($a)
	{
		$rv = array_filter($a,'is_array');
		$array_count = count($rv);
		return ($array_count > 0 && count($a) == $array_count);
	}
}
