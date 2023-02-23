<?php
/**
 * @author Pierluigi Fazzini <fazzinipielruigi@gmail.com>
 */

namespace Fazzinipierluigi\LaraexpressDatasource;

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

	/**
	 * @param \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder $data_set An instance of the query builder on which the filters will be applied
	 * @param \Illuminate\Http\Request $request The instance of the "request" coming from the client
	 * @return void
	 * */
	public function apply($data_set, $request)
	{
		if(empty($data_set) && !in_array(get_class($data_set), ["Illuminate\Database\Query\Builder", "Illuminate\Database\Eloquent\Builder"]))
			throw new \Exception('The "data_set" parameter must not be empty, and must be an instance of the classes: "Illuminate\Database\Query\Builder" or "Illuminate\Database\Eloquent\Builder"');

		if(empty($request) && get_class($request) != "Illuminate\Http\Request")
			throw new \Exception('The parameter "params" must not be empty, and must be an instance of the class "Illuminate\Http\Request"');

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
				$this->data_grid_filtered_dataset = $this->processFilters($filters, $this->data_grid_filtered_dataset);
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
						$this->data_grid_filtered_dataset->orderBy($sort->selector, ($sort->desc)?'DESC':'ASC');
					}
					elseif(is_string($sort))
					{
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

			$this->processGroups($group_expression, $group_summary, ($data_filters['skip'] ?? 0), ($data_filters['take'] ?? 10));

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

		if (!is_null($this->total_count)) {
			$response["totalCount"] = $this->total_count;
		}
		if (!is_null($this->total_summary)) {
			$response["summary"] = $this->total_summary;
		}
		if (!is_null($this->group_count)) {
			$response["groupCount"] = $this->group_count;
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
	 * @return mixed
	 */
	private function processFilters($expression, $query)
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
						$query->where($expression[0], $expression[1]);
					else
					{
						$operator = trim($expression[1]);
						$value = $expression[2];

						if(is_null($value))
						{
							if($operator === '=')
								$query->isNull($expression[0]);
							elseif($operator === '<>')
								$query->isNotNull($expression[0]);
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
									$query->{$clause}($expression[0],$operator,$value);
									break;

								case "startswith":
									$query->{$clause}($expression[0],'LIKE',$value.'%');
									break;
								case "endswith":
									$query->{$clause}($expression[0],'LIKE','%'.$value);
									break;
								case "contains": {
									$query->{$clause}($expression[0],'LIKE','%'.$value.'%');
									break;
								}
								case "notcontains":
									$query->{$clause}($expression[0],'NOT LIKE','%'.$value);
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
				$query->{$clause}(function($block) use ($item) {
					return $this->processFilters($item, $block);
				});
			}
		}

		return $query;
	}

	/**
	 * @param $expression
	 * @param $summary
	 * @param $skip
	 * @param $take
	 * @return void
	 */
	private function processGroups($expression, $summary, $skip, $take)
	{

	}

	/**
	 * @return int
	 */
	private function groupCount()
	{
		return 0;
	}
}
