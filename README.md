# BibleGet I/O service endpoints
There are 3 API endpoints for the BibleGet I/O project, reachable at the URL https://query.bibleget.io.

## [index.php](https://github.com/BibleGet-I-O/endpoint/blob/master/index.php)
This is the main API endpoint for all queries for biblical texts and quotes, reachable at the URL https://query.bibleget.io/.

Both `GET` and `POST` requests are supported. The endpoint is [CORS enabled](https://www.w3.org/wiki/CORS_Enabled), which means that ajax requests can be made directly against the endpoint without getting cross-domain restriction errors.

For the time being, authorization is not required to access the endpoint. However anyone who intends on using the endpoint is requested to use the `appid` parameter explained below in order to identify the source of the requests to the endpoint. And especially for web-based tools (apps, plugins), it is very important to use some kind of cache mechanism, in order to avoid unnecessary repeated requests to the API endpoint. Restrictions on the Maximum amount of requests permitted daily from any given IP address or web host are enforced.

Types of data that can be returned from the API endpoint are `JSON`, `XML`, and `HTML`.

### PARAMETERS
* `query`: *(required)* should contain the reference for the Bible quote (e.g. `John3:16` or `Giovanni3,16`). There is no default value, this parameter must be provided. The reference must use standard notation, whether English notation or International notation. See the section “Standard notation for biblical quotations”. The endpoint does check for the validity and correctness of the Bible reference, and will return error messages for badly formed references, however the application forming the request should also do validity checking in order to avoid bad requests from even reaching the server. This will help keep unnecessary load off of the server. The source code of the endpoint can be inspected for the REGEX checks that are used to check the validity of the Bible references, as can the source code of the official apps for **WordPress**, for **Google Docs**, for **Microsoft Word**, for **Open Office** and for **LibreOffice**.
* `version`: *(optional)* the version of the Bible to retrieve the quote from, default value `CEI2008` (the Italian version of the Catholic Episcopal Conference).  With this parameter you can specify the biblical version you want to retrieve the quote from. You can also specify several versions as a comma separated list, for example to compare multiple versions of the biblical texts. The possible values should be retrieved via the `metadata.php` API endpoint.
* `return`: *(optional)* the format in which the structured data should be returned, default value `json`. This parameter takes one of three values: `json`, `xml`, or `html`.
* `appid`: *(required)* identifies the application generating the request. For the time being, there is no kind of registration required for authorization with api keys and secrets. Requests can be made freely to the endpoint, which for now is completely open. However responsible usage of the endpoint is required, such as filtering for valid Bible references and using cache to avoid multiple requests for the same Bible reference. In order to help understand and monitor the usage of the endpoint by various applications, the `appid` parameter is required to know where the request is coming from. This will also be useful for the statistics of the requests made to the service endpoint. Using this parameter can be considered the basic registering mechanism for applications that make use of the service endpoint.
* `pluginversion`: *(optional)* lets the engine know which version of a plugin is being used to generate the request (the plugin being indicated in the `appid` parameter). Seeing that this is a project in development, and the plugins are developing together with it, in order to maintain the highest compatibility and quality assurance it is useful to know which “version” of a plugin is being used to generate the request. If for example a plugin version becomes incompatible, the engine will better determine how to handle a request coming from an outdated plugin.
* `domain`: *(optional)* identifies the domain from which a request is being generated. This is useful for requests that come from websites, as is the case with the **WordPress plugin**. This can help identify which websites are actively using the API endpoint. This also helps monitor usage of the endpoint, seeing that there is no kind of registration required for usage of the endpoint.

### STRUCTURE OF THE RETURNED DATA


## [metadata.php](https://github.com/BibleGet-I-O/endpoint/blob/master/metadata.php) 
An API endpoint for querying metadata such as Bible versions that are available and their book/chapter/verse indexes.

## [search.php](https://github.com/BibleGet-I-O/endpoint/blob/master/search.php) 
is the service endpoint for search requests using keywords or search by topic (Work in Progress)
