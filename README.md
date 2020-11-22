# BibleGet I/O service endpoints
There are 3 API endpoints for the BibleGet I/O project, reachable at the URL https://query.bibleget.io. The first official release on Github was v2.4. Since then, the v2 endpoints (currently at v2.9) have also become reachable at the URL https://query.bibleget.io/v2/. 

Starting with the v3 release, the v2 endpoints will ONLY be available at https://query.bibleget.io/v2/, the v3 endpoints will ONLY be available at https://query.bibleget.io/v3/, and NO endpoints will be available at https://query.bibleget.io . This way it will be become clear which version of the endpoint is being used. Any significant changes that would break functionality in a plugin or application will only be applied between major versions, in order to allow plugins or applications to handle the versioning and avoid breaking functionality.

Along with the information provided below, Swaggerhub documentation [can be found here](https://www.bibleget.io/swaggerdocs/dist/) (kudos to @MichaelRShelton for generating the docs from the Swagger docker image).

## [index.php](https://github.com/BibleGet-I-O/endpoint/blob/master/index.php)
This is the main API endpoint for all queries for biblical texts and quotes, reachable at the URL https://query.bibleget.io/.

Both `GET` and `POST` requests are supported. The endpoint is [CORS enabled](https://www.w3.org/wiki/CORS_Enabled), which means that ajax requests can be made directly against the endpoint without getting cross-domain restriction errors. [HSTS](https://en.wikipedia.org/wiki/HTTP_Strict_Transport_Security) is enforced on the whole BibleGet server, both for the website and the API endpoints, so only the `https` protocol can be used for requests. Since the certificate used on the server is a **Let's Encrypt issued certificate**, it should be recognized by most platforms, this is not however the case for many **Java runtimes** in which the `keystore` often does **not** have a copy of the **Let's Encrypt CA or Intermediate certificate** ([see here](https://stackoverflow.com/a/34111150/394921)). In these cases, it may be necessary for the application to ensure that a copy of the Let's Encrypt CA or Intermediate certificate is installed to the keystore in order for valid requests to be made to the BibleGet API endpoint.

For the time being, authorization is not required to access the endpoint. However anyone who intends on using the endpoint is requested to use the `appid` parameter explained below in order to identify the source of the requests to the endpoint. And especially for web-based tools (apps, plugins), it is very important to use some kind of cache mechanism, in order to avoid unnecessary repeated requests to the API endpoint. Restrictions on the maximum amount of requests permitted daily from any given IP address or web host are enforced. 

Think of this scenario: you create a web page that makes an AJAX request for a Bible quote and displays the result. But anyone on the internet can reach this web page. And in just one day, 300 people happen to open that web page for one reason or another. Every time the web page is opened, whether by actual people or by internet crawlers, the page makes an AJAX request to the BibleGet API endpoint just to display the same exact Bible verse every time. So in one day, 300 requests were made to the API endpoint from the same page for the same Bible verse. This is an irresponsible usage of the endpoint. If the web page used a form of local storage, it could store the data retrieved from the API endpoint in local storage. This way when anyone (whether an actual person or a web crawler) accesses the page, the Bible quote will be retrieved from local storage first, and if it not present in local storage it will be retrieved from the API endpoint and saved to local storage. This is not as necessary for desktop applications, where it is usually just one person using the desktop application, it would be very rare for anyone to make 100 requests in one day for the same Bible verse in a desktop application. However this danger does very well exist for web pages.

Types of data that can be returned from the API endpoint are `JSON`, `XML`, and `HTML`.

### PARAMETERS
* **`query`**: *(required)* should contain the reference for the Bible quote. There is no default value, this parameter must be provided. The reference must use standard notation, whether English notation or International notation (e.g. `John3:16` or `Giovanni3,16`), see the page [Standard notation for biblical quotations](https://www.bibleget.io/how-it-works/standard-notation-for-biblical-quotations/) for more information. The endpoint does check for the validity and correctness of the Bible reference, and will return error messages for badly formed references, however the application forming the request should also do validity checking in order to prevent bad requests from even reaching the server. This will help keep unnecessary load off of the server. The source code of the endpoint can be inspected for the REGEX checks that are used to check the validity of the Bible references, as can the source code of the official apps for **WordPress**, for **Google Docs**, for **Microsoft Word**, for **Open Office** and for **LibreOffice**.
* **`version`**: *(optional)* the version of the Bible to retrieve the quote from, default value `CEI2008` (the Italian version of the Catholic Episcopal Conference).  With this parameter you can specify the biblical version you want to retrieve the quote from. You can also specify several versions as a comma separated list, for example to compare multiple versions of the biblical texts. The possible values should be retrieved via the `metadata.php` API endpoint.
* **`return`**: *(optional)* the format in which the structured data should be returned, default value `json`. This parameter takes one of three values: `json`, `xml`, or `html`.
* **`appid`**: *(required)* identifies the application generating the request. For the time being, there is no kind of registration required for authorization with api keys and secrets. Requests can be made freely to the endpoint, which for now is completely open. However responsible usage of the endpoint is required, such as filtering for valid Bible references and using cache to avoid multiple requests for the same Bible reference. In order to help understand and monitor the usage of the endpoint by various applications, the `appid` parameter is required to know where the request is coming from. This will also be useful for the statistics of the requests made to the service endpoint. Using this parameter can be considered the basic registering mechanism for applications that make use of the service endpoint.
* **`pluginversion`**: *(optional)* lets the engine know which version of a plugin is being used to generate the request (the plugin being indicated in the `appid` parameter). Seeing that this is a project in development, and the plugins are developing together with it, in order to maintain the highest compatibility and quality assurance it is useful to know which “version” of a plugin is being used to generate the request. If for example a plugin version becomes incompatible, the engine will better determine how to handle a request coming from an outdated plugin.
* **`domain`**: *(optional)* identifies the domain from which a request is being generated. This is useful for requests that come from websites, as is the case with the **WordPress plugin**. This can help identify which websites are actively using the API endpoint. This also helps monitor usage of the endpoint, seeing that there is no kind of registration required for usage of the endpoint.

### STRUCTURE OF THE RETURNED DATA
I will only take into consideration, for sake of simplicity, the structure of JSON data.

An example of data returned from the query `https://query.bibleget.io/?query=Mt1,1-5&version=NABRE`:

```javascript
{"results":[{"testament":2,"section":5,"book":"Matthew","chapter":1,"versedescr":null,"verse":"1","verseequiv":null,"text":" The book of the genealogy of Jesus Christ, the son of David, the son of Abraham.","title1":"","title2":"","title3":"","version":"NABRE","bookabbrev":"Mt","booknum":46,"univbooknum":"47","originalquery":"Mt1,1-5"},{"testament":2,"section":5,"book":"Matthew","chapter":1,"versedescr":null,"verse":"2","verseequiv":null,"text":" Abraham became the father of Isaac, Isaac the father of Jacob, Jacob the father of Judah and his brothers. ","title1":"","title2":"","title3":"","version":"NABRE","bookabbrev":"Mt","booknum":46,"univbooknum":"47","originalquery":"Mt1,1-5"},{"testament":2,"section":5,"book":"Matthew","chapter":1,"versedescr":null,"verse":"3","verseequiv":null,"text":"Judah became the father of Perez and Zerah, whose mother was Tamar. Perez became the father of Hezron, Hezron the father of Ram, ","title1":"","title2":"","title3":"","version":"NABRE","bookabbrev":"Mt","booknum":46,"univbooknum":"47","originalquery":"Mt1,1-5"},{"testament":2,"section":5,"book":"Matthew","chapter":1,"versedescr":null,"verse":"4","verseequiv":null,"text":" Ram the father of Amminadab. Amminadab became the father of Nahshon, Nahshon the father of Salmon, ","title1":"","title2":"","title3":"","version":"NABRE","bookabbrev":"Mt","booknum":46,"univbooknum":"47","originalquery":"Mt1,1-5"},{"testament":2,"section":5,"book":"Matthew","chapter":1,"versedescr":null,"verse":"5","verseequiv":null,"text":" Salmon the father of Boaz, whose mother was Rahab. Boaz became the father of Obed, whose mother was Ruth. Obed became the father of Jesse, ","title1":"","title2":"","title3":"","version":"NABRE","bookabbrev":"Mt","booknum":46,"univbooknum":"47","originalquery":"Mt1,1-5"}],"errors":[],"info":{"ENDPOINT_VERSION":"2.8"}}
```

* **`results`**: an array containing the data associated with the single verses of the requested Bible quote:
  * **`testament`**: will have a value of `1` = *Old Testament* or `2` = *New Testament*. For now, applications that want to use this data will take care of localization and display, no text values are returned by the API.
  * **`section`**: will have one of the following values:
    * `1` = *Pentateuch*
    * `2` = *Historical Books*
    * `3` = *Wisdom Books*
    * `4` = *Prophets*
    * `5` = *Gospels*
    * `6` = *Acts of the Apostles*
    * `7` = *Letters of Saint Paul*
    * `8` = *Catholic Letters*
    * `9` = *Apocalypse*
    
    For now, applications that want to use this data will take care of localization and display, no text values are returned by the API.
  * **`book`**: display ready name of the Book of the Bible in the language of the Bible version being quoted from
  * **`bookabbrev`**: display ready abbreviated form of the Book of the Bible in the language of the Bible version being quoted from
  * **`booknum`**: the number of the Book of the Bible according to the **0 based index** of the Bible version being quoted from (not all versions have the same books in the same order).The value is returned as a number value ready to be used against index information for the Bible version being quoted from. The corresponding name of the Book of the Bible as used in the printed edition of this Bible version can be retrieved using the `metadata.php` API endpoint.
  * **`univbooknum`**: the number of the Book of the Bible according to the universally recognized Catholic version of the Canon of the Sacred Scriptures (*i.e. universally recognized by the Roman Catholic Church*). This is **not a 0 based index**, `1` = `Genesis`, therefore it is returned as a string rather than a number value, though it can be treated as a number.
  * **`chapter`**: display ready number of the chapter in the Book of the Bible in the Bible version that the verse is being quoted from
  * **`versedescr`**: not currently used, comes back as `null`. Could possible be used for scholarly notes associated with a Bible verse
  * **`verse`**: display ready number of the verse being quoted. *N.B. this is returned as a string because it will not always necessarily be a number value, there are verses that have letters in them. The value must be treated as a string and not as a number.* 
  * **`verseequiv`**: I'm not actually sure if this is currently being used or not, I believe the idea was to have a number value for those verses that have a letter in the verse number... Will mostly return a `null` value.
  * **`text`**: contains the actual text of the verse being quoted. May contain newline characters that may need to be dealt with. The `NABRE` version will contain it's own formatting tags that need to be dealt with, whether that means producing the proper formatting associated with these tags, or removing them to have a basic formatting. The legal requirements for usage of the `NABRE` version require the proper formatting to be used where possible. Please contact [the project author](mailto:admin@bibleget.io) for information on how to deal with these tags and their formatting.
  * **`version`**: the acronym of the Bible version being quoted from. To have information about the Bible version, the `metadata.php` API endpoint can be used.
  * **`title1`**: not currently used. The original idea (which may yet be implemented) was for this to contain any first-level title text preceding the given verse in the version of the Bible being quoted from.
  * **`title2`**: not currently used. The original idea (which may yet be implemented) was for this to contain any second-level title text preceding the given verse in the version of the Bible being quoted from.
  * **`title3`**: not currently used. The original idea (which may yet be implemented) was for this to contain any third-level title text preceding the given verse in the version of the Bible being quoted from.
  * **`originalquery`**: the original query (Bible reference indicated in the `query` parameter of the sent request) that the endpoint received, which produced this result.
* **`errors`**: an array which may contain strings with any error messages that may have been produced, for example for badly formed Bible quotes or for unrecognized Bible versions. An application should always check if the array is not empty, and in that case have a way of displaying the errors to the end user so the end user can understand what is happening. In any case, if the application does a good job of filtering requests in order to send only valid requests to the server, the `errors` array should be empty.
* **`info`**: an object containing information not directly associated with the Bible verses returned, but rather with the endpoint itself. For now only one *key:value* pair is returned with the current version of the endpoint itself: `"ENDPOINT_VERSION":"2.8"`. This may be useful information because the data produced by the endpoint may change over time, it is useful to know which data is associated with which version of the endpoint. For example, if an application caches data returned by the endpoint, but there has been a change to the structure of the data in a new version of the API, the application would know how to deal with emptying the cache and requesting new data from the updated endpoint.


## [metadata.php](https://github.com/BibleGet-I-O/endpoint/blob/master/metadata.php) 
An API endpoint for querying metadata such as Bible versions that are available and their book/chapter/verse indexes, reachable at https://query.bibleget.io/metadata.php.

Both `GET` and `POST` requests are supported. The endpoint is [CORS enabled](https://www.w3.org/wiki/CORS_Enabled), which means that ajax requests can be made directly against the endpoint without getting cross-domain restriction errors. [HSTS](https://en.wikipedia.org/wiki/HTTP_Strict_Transport_Security) is enforced on the whole BibleGet server, both for the website and the API endpoints, so only the `https` protocol can be used for requests. Since the certificate used on the server is a **Let's Encrypt issued certificate**, it should be recognized by most platforms, this is not however the case for many **Java runtimes** in which the `keystore` often does **not** have a copy of the **Let's Encrypt CA or Intermediate certificate** ([see here](https://stackoverflow.com/a/34111150/394921)). In these cases, it may be necessary for the application to ensure that a copy of the Let's Encrypt CA or Intermediate certificate is installed to the keystore in order for valid requests to be made to the BibleGet API endpoint.

### PARAMETERS
* **`query`**: *(required)* can take one of three values:
  * **`biblebooks`**: when used as the value of the `query` parameter, the endpoint will return data about the list of valid **book names** and **abbreviations** in various languages that are currently supported / recognized by the BibleGet main endpoint
  * **`bibleversions`**: when used as the value of the `query` parameter, the metadata endpoint will return data about the **Bible versions** that are currently supported by the BibleGet main endpoint
  * **`versionindex`**: when used as the value of the `query` parameter, the metadata endpoint will return data about the **indices of chapters and verses** for any of the Bible versions currently supported by the BibleGet engine. This value requires the usage of a **second parameter** `versions` other than the `query` parameter
* **`versions`**: *(required in case of a `query=versionindex` request)* indicates for which Bible versions indices data should be returned. This parameter’s value can be either a single version or a comma separated list of versions. The possible values can be retrieved making a `query=bibleversions` request against the metadata endpoint.
* **`return`**: *(optional)* indicates the format in which the structured data should be returned. This parameter takes one of three values: `json`, `xml`, or `html`. If left out, this parameter will default to `json`.

### STRUCTURE OF THE RETURNED DATA
I will only take into consideration, for sake of simplicity, the structure of JSON data.

1. Keys returned when `query=biblebooks` are: `languages`, `results`, `errors`, and `info`.

* **`languages`**: an array containing the languages supported by the main BibleGet endpoint, for the names of the Books of the Bible. The single languages are returned in the English form, all caps. The **implict numbered index** of this array will be useful for the data associated with the `results` key. 

    Example of data returned from the query **https://query.bibleget.io/metadata.php?query=biblebooks** relative to the `languages` key:

    ```javascript
    {"languages":["ENGLISH","AFRIKAANS","ALBANIAN","AMHARIC","ARABIC","CHINESE","CROATIAN","CZECH","FILIPINO","FRENCH","GERMAN","GREEK","HUNGARIAN","ITALIAN","JAPANESE","KOREAN","LATIN","POLISH","PORTUGUESE","ROMANIAN","RUSSIAN","SPANISH","TAMIL","THAI","VIETNAMESE"]}
    ```

* **`results`**: an array containing information about the names of the Bible books that can be used to make queries to the main endpoint. The **implict numbered index** of this array corresponds with the Bible books universally recognized by the Roman Catholic Church. Bible versions used by evangelicals will generally have a few less Bible books, so the index of reference is the Canon of the Scriptures as recognized by the Roman Catholic Church. 
  * Each element of this array will again be another array whose **implicit numbered index** corresponds with the **LANGUAGES** supported by the main endoint, and that can be found in the `languages` key of the returned data. 
    * And again, each element of the second nested array will be a third array in which:
      1. The first element will be a pipe separated list of the possible Full names of the book in the given language (if there are multiple possible forms that is; the pipe will be not be present if there is not a list of values)
      2. The second element will be a pipe separated list of the possible abbreviated forms of the book in the given language (if there are multiple possible forms that is; the pipe will be not be present if there is not a list of values)
      3. Any other elements will not be pipe separated lists but single strings of the possible alternate forms whether full or abbreviated ??? [note to myself: double check this, what was the reasoning behind this kind of structuring of the data?]

    Example of data returned from the query **https://query.bibleget.io/metadata.php?query=biblebooks** relative to the `results` key:

    ```javascript
    { // the main object returned by the endpoint
      "results": [  // the results key contains an array, the elements of which can be used to reconstruct the Canon of the Scriptures as recognized by the Roman Catholic Church
        [ // the first element [0] corresponds with the first book of the Bible, according to the Roman Catholic Canon, the Book of Genesis, and is again an array
          ["Genesis","Gen","Genesis"],          //the first element [0] is an array that contains forms of the book name in the ENGLISH language
          ["Génesis","Gn","Génesis"],           //the second element [1] is an array that contains forms of the book name in the AFRIKAANS language
          ["Zanafilla","Gen","Zanafilla"],      //the third element [2] is an array that contains forms of the book name in the ALBANIAN language
          ["","",""],                           //the fourth element [3] is an array that contains forms of the book name in the AMHARIC language (there actually isn't any data!)
          ["تكوين","Gen","تكوين"],             //the fifth element [4] is an array that contains forms of the book name in the ARABIC language 
          ["創世紀","Gen","創世紀"],             //the sixth element [5] is an array that contains forms of the book name in the CHINESE language
          ...                                   //and so on, using the array of the languages key as a reference
          ["Genesi","Gen | Gn | Ge","Genesi","Gen","Gn","Ge"], //the nth element contains forms of the book name in the ITALIAN language, note that the first element of the array is a simple string (there is only one form of the full name), but the second element is a pipe separated list of possible abbreviated forms
          ...
        ],
        ...
        [ // the nth element of the array corresponds with the book of Ecclesiastes
          ["Ecclesiastes | Qoelet","Eccl | Qo","Ecclesiastes","Qoelet","Eccl","Qo"],  //the first element [0] is an array that contains forms of the book name in the ENGLISH language, note that the first element [0] of this array is a pipe separated list of possible forms of the full name of the book, the second element [1] is a pipe separated list of the possible abbreviated forms of the book name, following are all the single strings of these possible forms whether full or abbreviated
          ...
        ]
        ...
      ] //end of the results array
    }
    ```

* **`errors`**: an array that will contains strings of errors that may have been generated from improper usage of the endpoint, unrecognized requests (or possibly even server / database errors if any). When the API endpoint is used correctly this should generally be an empty array, developers should always check against this array to display any relevant error messages to end users so they understand what might be happening when something doesn't seem to be working correctly, and they can contact the developer with the relevant error messages produced

* **`info`**: an object containing information not directly associated with the Bible verses returned, but rather with the endpoint itself. For now only one *key:value* pair is returned with the current version of the endpoint itself: `"ENDPOINT_VERSION":"2.8"`. This may be useful information because the data produced by the endpoint may change over time, it is useful to know which data is associated with which version of the endpoint. For example, if an application caches data returned by the endpoint, but there has been a change to the structure of the data in a new version of the API, the application would know how to deal with emptying the cache and requesting new data from the updated endpoint.

2. Keys returned when `query=bibleversions` are: `validversions`, `validversions_fullname`, `copyrightversions`, `errors`, `info` (if the `results` key is present it will be an empty array, it is not useful here). Example data returned:

    ```javascript
    {"results":[],"errors":[],"info":{"ENDPOINT_VERSION":"2.4"},"validversions":["CEI2008","LUZZI","NABRE","NVBSE"],"validversions_fullname":{"CEI2008":"Conferenza Episcopale Italiana|2008|it","LUZZI":"Riveduta - Luzzi|1924|it","NABRE":"New American Bible - Revised Edition|2011|en","NVBSE":"Nova Vulgata - Bibliorum Sacrorum Editio|1979|la"},"copyrightversions":["CEI2008","NABRE"]}
    ```

* **`validversions`**: an array containing the acronyms of the Bible versions supported by the BibleGet main endpoint
* **`validversions_fullname`**: an object whose keys are the acronyms of the Bible versions supported. Associated with each key is a string of pipe separated values, when exploded or split these correspond with:
  1. The **Full name of the Bible version**
  2. The **year** in which this Bible version was published
  3. The two letter ISO 639-1 Code of the **language** of the Bible version (in lowercase letters)
* **`copyrightversions`**: an array containing the acronyms of Bible versions that have a copyright holder, the usage of which is regulated under a legally binding agreement with the copyright holder. For example, some copyright holders (usually Episcopal Conferences for Catholic versions) may request that no more than a certain number of verses be issued from a single request to the endpoint (in other words, please don't try to copy the whole Bible through the BibleGet endpoint!). Enforcement of the usage required by the copyright holder is done by the endpoint itself, so applications cannot overcome these limits. If the owner of the endpoint notices that an application attempts in a sly manner to overcome these limitations, access to the main API endpoint may be denied to the application and authorization mechanisms will be necessarily put in place for usage of the endpoints. We are assuming a model of responsible usage for the time being, but if it becomes necessary access will be restricted and authorization will be required.
* **`errors`**: an array that will contains strings of errors that may have been generated from improper usage of the endpoint, unrecognized requests (or possibly even server / database errors if any). When the API endpoint is used correctly this should generally be an empty array, developers should always check against this array to display any relevant error messages to end users so they understand what might be happening when something doesn't seem to be working correctly, and they can contact the developer with the relevant error messages produced
* **`info`**: an object containing information not directly associated with the Bible verses returned, but rather with the endpoint itself. For now only one *key:value* pair is returned with the current version of the endpoint itself: `"ENDPOINT_VERSION":"2.8"`. This may be useful information because the data produced by the endpoint may change over time, it is useful to know which data is associated with which version of the endpoint. For example, if an application caches data returned by the endpoint, but there has been a change to the structure of the data in a new version of the API, the application would know how to deal with emptying the cache and requesting new data from the updated endpoint.

3. Keys returned when `query=versionindex` and assuming usage of the second required parameter (for example `versions=NABRE`) are: `indexes`, `info`, `errors` (if the `results` key is present it will be an empty array, it is not useful here). Example data returned:

    ```javascript
    {"indexes":{"NABRE":{"abbreviations":[...],"biblebooks":[...],"chapter_limit":[...],"verse_limit":[...],"book_num":[..]}},"results":[],"errors":[],"info":{"ENDPOINT_VERSION":"2.4"}}
    ```

* **`indexes`**: an object the ***keys*** of which are the ***acronyms*** of the ***requested Bible versions***, and whos corresponding values are objects with the index information associated with that Bible version. The keys of these last objects are: `abbreviations`, `biblebooks`, `chapter_limit`, `verse_limit`, `book_num`
  * **`abbreviations`**: an array containing the abbreviations of the books of the Bible as found in the published edition of this version of the Bible. These may not necessarily always be used as is for making requests to the main API endpoint because there is some possibility that they may conflict with other abbreviations used by other versions of the Bible in other languages for a different book of the Bible. These are only useful for displaying the exact abbreviations for that version of the Bible. For verification of the validity of a request made to the main API endpoint, the abbreviations produced from the request `query=biblebooks` should be used.
  * **`biblebooks`**: an array containing the full names of the books of the Bible as found in the published edition of this version of the Bible. The implicit numbered index of this array will not necessarily correspond with that of the Canon of the Scriptures as recognized by the Roman Catholic Church and as produced from the request `query=biblebooks`, because some evangelical versions for example may not have all of the books of the Bible as per the Canon recognized by the Roman Catholic Church.
  * **`chapter_limit`**: an array of number values which indicate the last possible chapter for each of the books listed in the above mentioned `biblebooks` array (or in the above mentioned `abbreviations` array). Again, the implicit numerical index of these values will not necessarily correspond with that of the Canon of Scriptures as recognized by the Roman Catholic Church, if the version of the Bible being quoted from does not have all of the books recognized by this same Canon. Each of these values is the actual last chapter of each of the books of the Bible in this version of the Bible, so it is not a zero based index number but it is the actual number of the last chapter of the book
  * **`verse_limit`**: an array of arrays, where the implicit numerical index of each array corresponds with the books listed in the above mentioned `biblebooks` array (or in the above mentioned `abbreviations` array). The internal arrays contain numerical values, the number of values found in each internal array corresponds with the number of chapters for each of these same books. So if the book of Genesis has 50 chapters (as we found out from the `chapter_limit` key), there will be 50 values in the first internal array within the `verse_limit` array. Each one of these 50 values indicates the verse limit (as in the last possible verse) for each of the 50 chapters in the book of Genesis. The same goes for each of the internal arrays contained herein. Again, the numbers in the internal arrays indicate the actual number of the last verse, it is not a zero based index.
  * **`book_num`**: an array containing the number of each book as it would be in the Canon of the Scriptures as recognized by the Roman Catholic Church. These numbers are not based on a zero based index. 1 = Genesis, 2 = Exodus, etc. This can be very helpful in trying to glue all of the metadata information together, between valid book names that can be used with their Roman Catholic Canon based index, and actual book names in a single version of the Bible where the number of elements might not be the same as those in the Roman Catholic Canon.
* **`errors`**: an array that will contains strings of errors that may have been generated from improper usage of the endpoint, unrecognized requests (or possibly even server / database errors if any). When the API endpoint is used correctly this should generally be an empty array, developers should always check against this array to display any relevant error messages to end users so they understand what might be happening when something doesn't seem to be working correctly, and they can contact the developer with the relevant error messages produced
* **`info`**: an object containing information not directly associated with the Bible verses returned, but rather with the endpoint itself. For now only one *key:value* pair is returned with the current version of the endpoint itself: `"ENDPOINT_VERSION":"2.8"`. This may be useful information because the data produced by the endpoint may change over time, it is useful to know which data is associated with which version of the endpoint. For example, if an application caches data returned by the endpoint, but there has been a change to the structure of the data in a new version of the API, the application would know how to deal with emptying the cache and requesting new data from the updated endpoint.

>N.B. Applications or plugins that wish to use the main API endpoint should CACHE the information returned by the METADATA endpoint. This data does not change often, there is no need to request it for every Bible quote. It can be a good idea to refresh this information about, let's say once a month, or create a user interface with a button that will allow the end user to refresh the information from the server if they think the cached information might be old.

## [search.php](https://github.com/BibleGet-I-O/endpoint/blob/master/search.php) 
An API endpoint for issuing search requests using keywords or search by topic, reachable at https://query.bibleget.io/search.php.

Both `GET` and `POST` requests are supported. The endpoint is [CORS enabled](https://www.w3.org/wiki/CORS_Enabled), which means that ajax requests can be made directly against the endpoint without getting cross-domain restriction errors. [HSTS](https://en.wikipedia.org/wiki/HTTP_Strict_Transport_Security) is enforced on the whole BibleGet server, both for the website and the API endpoints, so only the `https` protocol can be used for requests. Since the certificate used on the server is a **Let's Encrypt issued certificate**, it should be recognized by most platforms, this is not however the case for many **Java runtimes** in which the `keystore` often does **not** have a copy of the **Let's Encrypt CA or Intermediate certificate** ([see here](https://stackoverflow.com/a/34111150/394921)). In these cases, it may be necessary for the application to ensure that a copy of the Let's Encrypt CA or Intermediate certificate is installed to the keystore in order for valid requests to be made to the BibleGet API endpoint.

### PARAMETERS
* **`query`**: *(required)* as of version 2.8 of the endpoint it can take only one value: `keywordsearch`, which requires the usage of a second parameter: `keyword`. Search by topic is not yet available.
* **`keyword`**: *(required when making a request where `query=keywordsearch`)* indicates the keyword that will be searched in the text of the Bible verses
* **`exactmatch`**: *(optional)* since the default behaviour for a keyword search is to find any word of 4 or more letters which matches or contains the keyword, this option will try to find only exact matches and will also allow to search for words of even only 3 letters (parts of speech excluded) 
* **`version`**: *(required)* indicates the Bible version to search in. Cannot be a comma separated list, can only be one version, indicated using the acronym for the Bible version among the versions available on the BibleGet server (which are discoverable from the `metadata.php` API endpoint)
* **`return`**: *(optional)* indicates the format in which the structured data should be returned. This parameter takes one of three values: `json`, `xml`, or `html`. If left out, this parameter will default to `json`. To be honest, only `json` is currently fully supported, `html` and `xml` have not had much attention and currently (as of API endpoint version 2.7) do not return coherent results.


### STRUCTURE OF THE RETURNED DATA
The data is structured in a similar manner to the main API endpoint (index.php).

* **`results`**: an array containing the data associated with the single verses that contain the keyword that was searched for within the requested Bible version, whether as a full match or as a match within a word (e.g. a search for the keyword `light` will first return Bible verses that contain exactly the word `light`, then verses that contain the word `lights` seeing that *light* can be found in *lights*). The objects contained in this array are exactly the same as those returned by the main API endpoint, for example:

    ```javascript
    {"testament":"1","section":"1","book":"Genesis","chapter":"1","versedescr":null,"verse":"3","verseequiv":null,"text":"Then God said: Let there be light, and there was light. ","title1":"","title2":"","title3":"","version":"NABRE","bookabbrev":"Gn","booknum":0,"univbooknum":"1","originalquery":"Gn1:3"}
    ```
    
    The `originalquery` key in this case will simply be a reference to the single verse for that search result.
    
* **`errors`**: an array that will contains strings of errors that may have been generated from improper usage of the endpoint, unrecognized requests (or possibly even server / database errors if any). When the API endpoint is used correctly this should generally be an empty array, developers should always check against this array to display any relevant error messages to end users so they understand what might be happening when something doesn't seem to be working correctly, and they can contact the developer with the relevant error messages produced

* **`info`**: an object containing a kind of metadata information about the search that was performed. 

    * **`ENDPOINT_VERSION`**: the current version of the API endpoint. This may be useful information because the data produced by the endpoint may change over time, it is useful to know which data is associated with which version of the endpoint. For example, if an application caches data returned by the endpoint, but there has been a change to the structure of the data in a new version of the API, the application would know how to deal with emptying the cache and requesting new data from the updated endpoint.
    
    * **`keyword`**: just echoes back the keyword that was used to perform the search
    
    * **`version`**: just echoes back the acronym of the Bible version that the search was performed against
    
