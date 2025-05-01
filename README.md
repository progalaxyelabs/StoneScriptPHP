# Sunbird API
## A minimalistic backend framework for building APIs using PHP and PostgreSQL

---------------------------------------------------------------


## Workflow

### create sql function:

in pgadmin4, develop postgresql function. save the function as an pssql file under `postgres/functions` folder. ex: `postgresql/functions/function_name.pssql


### create php modal class for this sql function:

api server: create a file with the function name  `function_name.pssql`

commands to run in terminal:


```shell

cd Framework/cli
php generate-model.php function_name.pssql

```

this will create a `FnFunctionName.php` in `Database/Functions` folder


### create a api route:

```shell

# cd Framework/cli
php generate-route.php route-name

```

this will create a `RouteNameRoute.php` file in `Routes` folder


### create url to class route mapping:

in `Config/routes.php`, add a url-to-class route mapping

ex: for adding a post route, add the line in the `POST` section

`'/update-trophies' => RouteNameRoute:class`


### implement the route class's process function:

in `RouteNameRoute.php`, in `process` function, call the database function and return data


ex:


```php

        $data = FnUpdateCourseDetails::run($this->name, $this->email, $this->course_id, $this->batch_id, $this->section_id);
        return new ApiResponse('ok', '', [
            'course' => $data
        ]);
        
```

