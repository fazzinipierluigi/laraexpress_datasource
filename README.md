# LaraExpress Datasource

This library provides a helper for generating datasources for widgets in the DevExpress javascript library.

## Advancement

- [ ] Load
  - [x] Filters
  - [x] Sorting
  - [x] Pagination
  - [x] Grouping
- [ ] Insert
- [ ] Update
- [ ] Delete

## Usage

Inside the method in your controller:
```php
public function index(Request $request)
{
	if($request->ajax())
	{
		$users = \App\Model\User::where('is_admin', '=', 0);
		
		$data_source = new EloquentSource(); // Remember that you have to add the "use" directive first.
		$data_source->apply($users, $request);
		return $data_source->getResponse();
	}
	else
	{
		return view('user.index')
	}
}
```
You can pass the frontend timezone to the constructor (the default is 'Euurope/Rome'), alternatively you can set it later with the setTimezone method
```php
$my_data_source = new EloquentSource(); // This has as its timezone "Europe/Rome"

// These two options are equivalent
$data_source = new EloquentSource("America/Chicago");
$data_source->setTimezone("America/Chicago");
```

In the frontend we first include the library for the data store (go to [documentation](https://github.com/DevExpress/DevExtreme.AspNet.Data)), in my case:
	
	<script src="https://unpkg.com/devextreme-aspnet-data/js/dx.aspnet.data.js"></script>

after that:
```javascript
$("#dataGridContainer").dxDataGrid({
    dataSource: DevExpress.data.AspNet.createStore({
        loadUrl: '{{ URL::current() }}',
    }),
    remoteOperations: {
		filtering: true,
		sorting: true,
		paging: true,
		grouping: true,
		groupPaging: true,
	},
    // ...
})
```
The apply method accepts a third optional parameter, namely "field_map", this parameter is used to map the database fields in case fields are renamed in the generation of the response or if more than one database column corresponds to a datagrid column:

```php
public function index(Request $request)
{
	if($request->ajax())
	{
		$users = \App\Model\User::select('users.*');
		
		$data_source = new EloquentSource();
		$field_map = [ // I create the array that contains the mapping field name => column name
			'fiscal_reference' => ['users.tax_code', 'users.vat'],
			'creation_date' => 'created_at'
		];
		$data_source->apply($users, $request, $field_map);
		
		return $data_source->getResponse(function($data) {
			// Optionally you can pass a Clojure function
			// to the "getResponse" function to format the
			// data or perform operations. If it is not
			// provided the toArray() method will be used.
			return [
				'username' => $data->username;
				'fiscal_reference' => ($data->is_company())? $data->vat : $data->tax_code;
				'creation_date' => $data->created_at->format('d/m/Y');
				// ... and so on
			];
		});
	}
	else
	{
		return view('user.index')
	}
}
```

The "apply" method also accepts an optional fourth parameter; this is used to override the sorting behavior:

```php
public function index(Request $request)
{
	if($request->ajax())
	{
		$contracts = \App\Model\Contracts::select('contracts.*')
										 ->join('customers', 'customers.id', '=', 'contracts.customer_id');
		
		$data_source = new EloquentSource();
		$field_map = [ // I create the array that contains the mapping field name => column name
			'customer' => 'customers.id'
		];
		$field_sorting = [
			'customer' => 'customers.company_name'
		];
		$data_source->apply($contracts, $request, $field_map, $field_sorting);
		
		return $data_source->getResponse(function($data) {
			// ....
		});
	}
	else
	{
		return view('customer.index')
	}
}
```

In this case if we had passed only the "field_map" array to apply the sorting would have been done based on the customer id, instead by overriding it we can force the sorting by company name while still maintaining the search by id.
