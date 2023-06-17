# LaraExpress Datasource

This library provides a helper for generating datasources for widgets in the DevExpress javascript library.

## Advancement

- [ ] Load
  - [x] Filters
  - [x] Sorting
  - [x] Pagination
  - [ ] Grouping
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
		return $data_source->getResponse(function($data) {
			// Optionally you can pass a Clojure function
			// to the "getResponse" function to format the
			// data or perform operations. If it is not
			// provided the toArray() method will be used.
			return [
				'username' => $data->username;
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
		paging: true
	},
    // ...
})
```
