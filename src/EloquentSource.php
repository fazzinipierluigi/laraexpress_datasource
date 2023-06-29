<?php
/**
 * @author Pierluigi Fazzini <fazzinipielruigi@gmail.com>
 */

namespace Fazzinipierluigi\LaraexpressDatasource;

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
	 * @param \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder $data_set An instance of the query builder on which the filters will be applied
	 * @param \Illuminate\Http\Request $request The instance of the "request" coming from the client
	 * @param array|null $request The instance of the "request" coming from the client
	 * @return void
	 * */
	public function apply($data_set, $request, $field_map = NULL)
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
		$this->total_count = (!empty($data_filters["requireTotalCount"])) ? $this->data_grid_filtered_dataset->count() : NULL;

		if(!empty($data_filters['sort']))
		{
			$sorts = json_decode($data_filters['sort'],false);
			if(!empty($sorts))
			{
				foreach($sorts as $sort)
				{
					if(is_object($sort))
					{
						if(empty($fields_map[$sort->selector]))
							$this->data_grid_filtered_dataset->orderBy($sort->selector, ($sort->desc)?'DESC':'ASC');
						else
						{
							if(is_string($fields_map[$sort->selector]))
								$this->data_grid_filtered_dataset->orderBy($fields_map[$sort->selector]);
							elseif(is_array($fields_map[$sort->selector]))
								$this->data_grid_filtered_dataset->orderBy($fields_map[$sort->selector][0]);
						}
					}
					elseif(is_string($sort))
					{
						if(empty($fields_map[$sort]))
							$this->data_grid_filtered_dataset->orderBy($sort);
						else
						{
							if(is_string($fields_map[$sort]))
								$this->data_grid_filtered_dataset->orderBy($fields_map[$sort]);
							elseif(is_array($fields_map[$sort]))
								$this->data_grid_filtered_dataset->orderBy($fields_map[$sort][0]);
						}
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

			$this->processGroups($group_expression, $field_map, ($data_filters['skip'] ?? 0), ($data_filters['take'] ?? 10));

			# Retrieve group count if required
			$this->group_count = (!empty($data_filters["requireGroupCount"])) ? $this->data_grid_filtered_dataset->groupCount() : NULL;
		}
		else
		{
			$this->data_grid_filtered_dataset->skip(($data_filters['skip'] ?? 0))->take(($data_filters['take'] ?? 10));
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
		if(empty($this->groups_tree))
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
						$response["data"][] = $tmp_data;
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
			$response = ["data" => $this->groups_tree];

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
											$query->{$clause}($expression[0],$operator,$value);
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
		if(is_string($field))
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
			$groupCount = 0;
			$last_group_expanded = true;
			if (is_string($expression))
			{
				$groupCount = count(explode(",", $expression));
				$select_list = [];
				$expression_fields = explode(",", trim($expression));
				foreach($expression_fields as $group_index => $expression_field)
				{
					if(!empty($field_map[$expression_field]))
					{
						if(is_string($field_map[$expression_field]))
						{
							$group_structure[$group_index][] = 'FIELD_'.str_replace('.','_',$field_map[$expression_field]);
							$select_list[] = $field_map[$expression_field].' AS FIELD_'.str_replace('.','_',$field_map[$expression_field]);
							$new_query->groupBy($field_map[$expression_field]);
							$new_query->orderBy($field_map[$expression_field],'ASC');
						}
						elseif(is_array($field_map[$expression_field]))
						{
							foreach($field_map[$expression_field] as $sub_field)
							{
								$group_structure[$group_index][] = 'FIELD_'.str_replace('.','_',$sub_field);
								$select_list[] = $sub_field.' AS FIELD_'.str_replace('.','_',$sub_field);
								$new_query->groupBy($sub_field);
								$new_query->orderBy($sub_field,'ASC');
							}
						}
					}
					else
					{
						$group_structure[$group_index][] = 'FIELD_'.str_replace('.','_',$expression_field);
						$select_list[] = $expression_field.' AS FIELD_'.str_replace('.','_',$expression_field);
						$new_query->groupBy($expression_field);
						$new_query->orderBy($expression_field,'ASC');
					}
				}
				$select_list[] = DB::raw('COUNT(1) AS leaf_count');

				$new_query->select($select_list);
			}
			elseif (is_array($expression))
			{
				$groupCount = count($expression);
				$select_list = [];
				foreach($expression as $group_index => $col)
				{
					if(!empty($field_map[$col->selector]))
					{
						if(is_string($field_map[$col->selector]))
						{
							$group_structure[$group_index][] = 'FIELD_'.str_replace('.','_',$field_map[$col->selector]);
							$select_list[] = $field_map[$col->selector].' AS FIELD_'.str_replace('.','_',$field_map[$col->selector]);
							$new_query->groupBy($field_map[$col->selector]);
							$new_query->orderBy($field_map[$col->selector],(empty($col->desc))?"ASC":"DESC");
						}
						elseif(is_array($field_map[$col->selector]))
						{
							foreach($field_map[$col->selector] as $sub_field)
							{
								$group_structure[$group_index][] = 'FIELD_'.str_replace('.','_',$sub_field);
								$select_list[] = $sub_field.' AS FIELD_'.str_replace('.','_',$sub_field);
								$new_query->groupBy($sub_field);
								$new_query->orderBy($sub_field,(empty($col->desc))?"ASC":"DESC");
							}
						}
					}
					else
					{
						$group_structure[$group_index][] = 'FIELD_'.str_replace('.','_',$col->selector);
						$select_list[] = $col->selector.' AS FIELD_'.str_replace('.','_',$col->selector);
						$new_query->groupBy($col->selector);
						$new_query->orderBy($col->selector,(empty($col->desc))?"ASC":"DESC");
					}
				}
				$select_list[] = DB::raw('COUNT(1) AS leaf_count');

				$new_query->select($select_list);
			}


			$this->groups_tree = [];
			foreach($new_query->get() as $row)
				$this->groups_tree = $this->add_tree($groupCount, $group_structure, $row, $this->groups_tree);

			//dd($group_hierarchy);
		}
	}

	private function add_tree($group_count, $fields, $row, $array, $level = NULL)
	{
		if(is_null($level))
			$level = 0;
		else
		{
			if($level<count($fields))
				$level++;
			else
				return $array;
		}

		foreach($fields[$level] as $column)
		{
			$tmp_val = $row->$column;
			$tmp_key = NULL;
			$filtered = Arr::first($array,function($value, $key) use ($tmp_val, &$tmp_key){
				if(!empty($value['key']) && $value['key'] === $tmp_val)
				{
					$tmp_key = $key;
					return TRUE;
				}

				return FALSE;
			});

			if(!empty($filtered))
				$array[$tmp_key]['items'] = $this->add_tree($group_count, $fields, $row, $filtered['items'] ?? [], $level);
			else
				if($group_count-1 > $level)
					$array[] = [
						'key' => $tmp_val,
						'items' => []
					];
				else
					$array[] = [
						'key' => $tmp_val,
						'count' => $row->leaf_count,
						'items' => null
					];
		}

		return $array;
	}

	/**
	 * @return int
	 */
	private function groupCount()
	{
		return 0;
	}
}
