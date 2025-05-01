# StoneScriptPHP
## A minimalistic backend framework for building APIs using PHP and PostgreSQL

---------------------------------------------------------------

## Setup 
To setup a PHP project for your backend api, use `composer create-project` command line
```
composer create-project progalaxyelabs/stone-script-php api
```
this will create a project in the `api` folder in your current folder and install the dependencies.

## Run
To run the php project, you can use the `serve.php` script from the project root
```
php serve.php
```
By default, this will use the port 9100.
You can check the server running by opening the browser and navigating to `http://localhost:9100`


## Workflow

Create all the database tables in individual .pssql files in the  `src/App/Database/postgres/tables/` folder

Create all the database queries as SQL functions in individual .pssql files in the  `src/App/Database/postgres/functions/` folder

if there is and seed data, create those as SQl scripts (insert statements) as .pssql files in the `src/App/Database/postgres/seeds/` folder


### create sql functions:

In pgadmin4, develop postgresql functions. test them and once working, save them as individual files under `src/App/Database/postgres/functions/` folder. ex: `src\App\Database\postgresql/functions/function_name.pssql`


### create php modal class for this sql function:

you can use the cli script to generate a PHP class for this sql function that will help in identifying the function arguments and return values

commands to run in terminal:


```shell

cd Framework/cli
php generate-model.php function_name.pssql

```

this will create a `FnFunctionName.php` in `src/App/Database/Functions` folder


This can be used to call the SQl function from PHP with proper arguments with reasonable typing that PHP allows. To see that in action, create an api route.


### create an api route:

```shell

# cd Framework/cli
php generate-route.php update-trophies

```

this will create a `UpdateTrophiesRoute.php` file in `Routes` folder


### create url to class route mapping:

in `src/App/Config/routes.php`, add a url-to-class route mapping

ex: for adding a post route, add the line in the `POST` section

```
return [
    ...
    'POST' => [
         ...
        '/update-trophies' => UpdateTrophiesRoute:class
        ...
    ]
    ...
];


```

### implement the route class's process function:

in `UpdateTrophiesRoute.php`, in `process` function, call the database function and return data


ex:


```php

$data = FnUpdatetrophyDetails::run(
    $user_trophy_id,
    $count
);

return new ApiResponse('ok', '', [
    'course' => $data
]);
        
```

