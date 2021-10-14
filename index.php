<?php

/**
 * BibleGet I/O Project Service Endpoint
 * listens on both GET requests and POST requests
 * whether ajax or not
 * accepts all cross-domain requests
 * is CORS enabled (as far as I understand it)
 * 
 * ENDPOINT URL:    https://query.bibleget.io/
 * 
 * AUTHOR:          John Romano D'Orazio
 * AUTHOR EMAIL:    priest@johnromanodorazio.com
 * AUTHOR WEBSITE:  https://www.johnromanodorazio.com
 * PROJECT WEBSITE: https://www.bibleget.io
 * PROJECT EMAIL:   admin@bibleget.io | bibleget.io@gmail.com
 * 
 * Copyright John Romano D'Orazio 2014-2021
 * Licensed under Apache License 2.0
 * 
 * This project is meant to contribute to the human community,
 * the community of mankind itself. 
 * Considering the Bible is among the oldest writings in the world,
 * and is the most read book of all human history
 * containing the wisdom of humanity from the most ancient times
 * and, for those who have faith, is the inspired Word of God himself
 * I deemed it necessary and useful to create a project
 * that would facilitate the usage of the Biblical texts
 * in the modern digital era.
 * 
 * My hope and desire is to be able to add 
 * as many different versions of the Bible in different languages
 * as possible, so that all men may have facilitated access to these texts
 * This project will always only utilize original source texts,
 * untouched by any third parties, so as to guarantee the authenticity of said texts.
 *    Deuteronomy 4:2
 *    "You shall not add to the word which I am commanding you, 
 *    nor take away from it, that you may keep the commandments 
 *    of the Lord your God which I command you."
 * 
 * I have no desire for any kind of economical advantage
 * over this project, nobody should speculate eonomically
 * over the wisdom of humanity or over the Word of God.
 * May it be of service to mankind. 
 * While I wish this endpoint engine to be open source,
 * available to men of good will who might desire to continue this project,
 * especially Biblical societies around the world,
 * and I hope the Pontifical Biblical Commission,
 * I cannot however offer the source texts and the databases they are held in
 * for public access, they are not all open source, 
 * they are often covered by copyright by Episcopal Conferences or by Biblical societies.
 * 
 * I wish for the code of this engine to be open source,
 * so that men of good will might contribute to making it better,
 * more secure, more reliable, to be of better service to mankind.
 */
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

define('DEBUGFILE', "requests.log");
define('DEBUG_REQUESTS', false); //set to true in order to enable logging of requests
define('DEBUG_IPINFO', false);

define("ENDPOINT_VERSION", "3.0");

//TODO: perhaps create a class out of all this, like we did for metadata.php?

if (!function_exists('apache_request_headers')) {
  ///
  function apache_request_headers()
  {
    $arh = array();
    $rx_http = '/\AHTTP_/';
    foreach ($_SERVER as $key => $val) {
      if (preg_match($rx_http, $key)) {
        $arh_key = preg_replace($rx_http, '', $key);
        $rx_matches = array();
        // do some nasty string manipulations to restore the original letter case
        // this should work in most cases
        $rx_matches = explode('_', $arh_key);
        if (count($rx_matches) > 0 and strlen($arh_key) > 2) {
          foreach ($rx_matches as $ak_key => $ak_val) $rx_matches[$ak_key] = ucfirst($ak_val);
          $arh_key = implode('-', $rx_matches);
        }
        $arh[$arh_key] = $val;
      }
    }
    return ($arh);
  }
  ///
}


// Don't allow bots to access this script!
if (isset($_SERVER['HTTP_USER_AGENT']) && preg_match('/bot|crawl|slurp|spider/i', $_SERVER['HTTP_USER_AGENT'])) {
  exit(0);
}

//TODO: PERHAPS IMPLEMENT A BLACKLIST FOR IP ADDRESSES OR ADDRESS RANGES THAT MAKE CONTINUED SPAM REQUESTS
//just an idea, not actually using yet, but keep in mind if it becomes necessary to implement further protections
//it's not enough to blacklist IP addresses, must also blacklist referers that generate requests from multiple IP addresses
//all adding of IP addresses or referers to the blacklists should probably be done where the caching checks are done
//Here we should only check if an IP address or referer is in a blacklist and if so, exit the script right away
//(exit script with error message, saying the IP address or referer was blacklisted, or silently?)
//$ipranges_low = array(ip2long("###.###.###.###"));
//$ipranges_high = array(ip2long("###.###.###.###"));


define("BIBLEGETIOQUERYSCRIPT", "iknowwhythisishere");


/*************************************************************
 * SET HEADERS TO ALLOW ANY KIND OF REQUESTS FROM ANY ORIGIN * 
 * AND CONTENT-TYPE BASED ON REQUESTED RETURN TYPE           *
 ************************************************************/

// Allow from any origin
if (isset($_SERVER['HTTP_ORIGIN'])) {
    header("Access-Control-Allow-Origin: {$_SERVER['HTTP_ORIGIN']}");
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Max-Age: 86400');    // cache for 1 day
}
// Access-Control headers are received during OPTIONS requests
if (isset($_SERVER['REQUEST_METHOD'])) {
    if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD']))
        header("Access-Control-Allow-Methods: GET, POST");
    if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']))
        header("Access-Control-Allow-Headers: {$_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']}");
}

class BIBLEGET_QUOTE {

    static public array $returnTypes                = [ "json", "xml", "html" ];
    static public array $allowedAcceptHeaders       = [ "application/json", "application/xml", "text/html" ];
    static public array $allowedContentTypes        = [ "application/json" , "application/x-www-form-urlencoded" ];
    static public array $allowedRequestMethods      = [ "GET", "POST" ];
    static public array $allowedPreferredOrigins    = [ "GREEK", "HEBREW" ];

    static public array $errorMessages = [
        "First query string must start with a valid book abbreviation!",
        "You must have a valid chapter following the book abbreviation!",
        "The book abbreviation is not a valid abbreviation. Please check the documentation for a list of correct abbreviations.",
        "You cannot use a dot without first using a comma. A dot is a liason between verses, which are separated from the chapter by a comma.",
        "A dot must be preceded and followed by 1 to 3 digits of which the first digit cannot be zero.",
        "A comma must be preceded and followed by 1 to 3 digits of which the first digit cannot be zero.",
        "A dash must be preceded and followed by 1 to 3 digits of which the first digit cannot be zero.",
        "If there is a chapter-verse construct following a dash, there must also be a chapter-verse construct preceding the same dash.",
        "There are multiple dashes in the query, but there are not enough dots. There can only be one more dash than dots.",
        "Notation Error. Please check your citation notation.",
        "Please use a cacheing mechanism, you seem to be submitting numerous requests for the same query.",
        "You are submitting too many requests with the same query. You must use a cacheing mechanism. Once you have implemented a cacheing mechanism you may have to wait a couple of days before getting service again. Otherwise contact the service management to request service again.",
        "You are submitting a very large amount of requests to the endpoint. Please slow down. If you believe there has been an error you may contact the service management."
    ];

    //TODO: these should not be hardcoded in Italian, they should be picked up from a database table with all possible language translations
    static public array $sections = [
        "Pentateuco",
        "Storici",
        "Sapienziali",
        "Profeti",
        "Vangeli",
        "Atti degli Apostoli",
        "Lettere Paoline",
        "Lettere Cattoliche",
        "Apocalisse"
    ];


    private array  $DATA                         = []; //all request parameters
    private string $returnType                   = "json";     //which type of data to return (json, xml or html)
    private array  $requestHeaders               = [];
    private string $acceptHeader                 = "";
    private string $contentType                  = "";
    private object $bibleQuote;          //object with json, xml or html data to return
    private object $mysqli;         //instance of database
    private bool   $isAjax                      = false;
    private array  $WhitelistedDomainsIPs        = [];
    private array  $validversions                = [];
    private array  $validversions_fullname       = [];
    private array  $copyrightversions            = [];
    private array  $PROTESTANT_VERSIONS          = [];
    private array  $CATHOLIC_VERSIONS            = [];
    //private $detectedNotation                  = NOTATION::tryFrom("ENGLISH"); //can be "ENGLISH" or "EUROPEAN"
    private string $detectedNotation             = "ENGLISH"; //can be "ENGLISH" or "EUROPEAN"
    private array  $biblebooks                   = [];
    private array  $requestedVersions            = [];
    private array  $requestedCopyrightedVersions = [];
    private array  $indexes                      = [];


    function __construct(array $DATA){
        $this->requestHeaders = getallheaders();
        $this->DATA = $DATA;
        $this->contentType = isset( $_SERVER['CONTENT_TYPE'] ) && in_array( $_SERVER['CONTENT_TYPE'], self::$allowedContentTypes ) ? $_SERVER['CONTENT_TYPE'] : "";
        $this->acceptHeader = isset( $this->requestHeaders["Accept"] ) && in_array( $this->requestHeaders["Accept"], self::$allowedAcceptHeaders ) ? (string) $this->requestHeaders["Accept"] : "";
        $this->returnType = ( isset($DATA["return"] ) && in_array(strtolower($DATA["return"]),self::$returnTypes)) ? strtolower($DATA["return"]) : ($this->acceptHeader !== "" ? (string) self::$returnTypes[array_search($this->requestHeaders["Accept"], self::$allowedAcceptHeaders)] : (string) self::$returnTypes[0]);
        $this->isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']);
    }

    private function addErrorMessage( int $num, string $str="" ) {

        if (gettype($num) === "string") {
            self::$errorMessages[13] = $num;
            $num = 13;
        }

        if ($this->returnType === "json") {
            $error = [];
            $error["errNum"] = $num;
            $error["errMessage"] = self::$errorMessages[$num] . ($str !== "" ? " > " . $str : "");
            $this->bibleQuote->errors[] = $error;
        } elseif ($this->returnType === "xml") {
            $err_row = $this->bibleQuote->Errors->addChild("error", self::$errorMessages[$num]);
            $err_row->addAttribute("errNum", $num);
        } elseif ($this->returnType === "html") {
            $elements = [];
            $errorsTable = $this->bibleQuote->getElementById("errorsTbl");
            if ($errorsTable == null) {
                $elements[0] = $this->bibleQuote->createElement("table");
                $elements[0]->setAttribute("id","errorsTbl");
                $elements[0]->setAttribute("class","errorsTbl");
                $this->err->appendChild($elements[0]);
            } else {
                $elements[0] = $errorsTable;
            }

            $elements[1] = $this->bibleQuote->createElement("tr");
            $elements[1]->setAttribute("id","errorsRow");
            $elements[1]->setAttribute("class","errorsRow");
            $elements[0]->appendChild($elements[1]);
            
            $elements[2] = $this->bibleQuote->createElement("td", "errNum");
            $elements[2]->setAttribute("class", "errNum");
            $elements[1]->appendChild($elements[2]);

            $elements[3] = $this->bibleQuote->createElement("td", $num);
            $elements[3]->setAttribute("class", "errNumVal");
            $elements[1]->appendChild($elements[3]);
        
            $elements[4] = $this->bibleQuote->createElement("td", "errMessage");
            $elements[4]->setAttribute("class", "errMessage");
            $elements[1]->appendChild($elements[4]);

            $elements[5] = $this->bibleQuote->createElement("td", self::$errorMessages[$num]);
            $elements[5]->setAttribute("class", "errMessageVal");
            $elements[1]->appendChild($elements[5]);

        }
    }

    private function outputResult() {

        switch($this->returnType) {
            case "json":
                $this->bibleQuote->info["keyword"] = $this->DATA["keyword"];
                $this->bibleQuote->info["version"] = $this->DATA["version"];
                echo json_encode($this->bibleQuote, JSON_UNESCAPED_UNICODE);
                break;
            case "xml":
                echo $this->bibleQuote->asXML();
                break;
            case "html":
                $this->bibleQuote->appendChild($this->err); 
                $this->bibleQuote->appendChild($this->div);
                $info = $this->bibleQuote->createElement("input");
                $info->setAttribute("type", "hidden");
                $info->setAttribute("name", "ENDPOINT_VERSION");
                $info->setAttribute("value", ENDPOINT_VERSION);
                $this->bibleQuote->appendChild($info);
                echo $this->bibleQuote->saveHTML($this->div);
                echo $this->bibleQuote->saveHTML($this->err);
                echo $this->bibleQuote->saveHTML($info);
        }

        $this->mysqli->close();
        exit(0);

    }

    private function dbConnect() {

        $dbCredentials = "dbcredentials.php";
        //search for the database credentials file at least three levels up...
        if(file_exists($dbCredentials)){
            include_once($dbCredentials);
        } else if (file_exists("../" . $dbCredentials)){
            include_once("../{$dbCredentials}");
        } else if (file_exists("../../" . $dbCredentials)){
            include_once("../../{$dbCredentials}");
        }

        $mysqli = new mysqli(SERVER,DBUSER,DBPASS,DATABASE);
      
        if ($mysqli->connect_errno) {
            $this->addErrorMessage( "Failed to connect to MySQL: (" . $mysqli->connect_errno . ") " . $mysqli->connect_error );
            $this->outputResult();
        }
        $mysqli->set_charset("utf8");
        $this->mysqli = $mysqli;
    }

    static private function toProperCase(string $txt) {
        preg_match("/\p{L}\p{M}*/u", $txt, $mList, PREG_OFFSET_CAPTURE);
        $idx = $mList[0][1];
        $chr = mb_substr( $txt, $idx, 1, 'UTF-8' );
        if( preg_match("/\p{L&}\p{M}*/u", $chr) ){
            $post = mb_substr( $txt, $idx+1, null, 'UTF-8' );
            return mb_substr( $txt, 0, $idx, 'UTF-8' ) . mb_strtoupper( $chr, 'UTF-8' ) . mb_strtolower( $post, 'UTF-8' );
        }
        else{
            return $txt;
        }
    }

    static private function idxOf(string $needle, array $haystack) {
        foreach ($haystack as $index => $value) {
            if (is_array($haystack[$index])) {
                foreach ($haystack[$index] as $index2 => $value2) {
                    if (in_array($needle, $haystack[$index][$index2])) {
                        return $index;
                    }
                }
            } else if (in_array($needle, $haystack[$index])) {
                return $index;
            }
        }
        return false;
    }


    static private function normalizeBibleBook(string $str){
        return self::toProperCase( preg_replace( "/\s+/", "", trim( $str ) ) );
    }

    private function BibleQuoteInit() {

      $err = NULL;
      $div = NULL;
      $inf = NULL;

      switch($this->returnType){
          case "json":
              $quote = new stdClass();
              $quote->results = [];
              $quote->errors = [];
              $quote->info = ["ENDPOINT_VERSION" => ENDPOINT_VERSION];
              break;
          case "xml":
              $root = "<?xml version=\"1.0\" encoding=\"UTF-8\"?"."><BibleQuote/>";
              $quote = new simpleXMLElement($root);
              $errors = $quote->addChild("errors");
              $info = $quote->addChild("info");
              $results = $quote->addChild("results");
              $info->addAttribute("ENDPOINT_VERSION", ENDPOINT_VERSION);
              break;
          case "html":
              $quote = new DOMDocument();
              $html = "<!DOCTYPE HTML><head><title>BibleGet Query Result</title><style>table#errorsTbl { border: 3px double Red; background-color:DarkGray; } table#errorsTbl td { border: 1px solid Black; background-color:LightGray; padding: 3px; } td.errNum,td.errMessage { font-weight:bold; }</style><!-- QUERY.BIBLEGET.IO ENDPOINT VERSION {ENDPOINT_VERSION} --></head><body></body>";
              $quote->loadHTML($html);
              $div = $quote->createElement("div");
              $div->setAttribute("class","results bibleQuote");
              $err = $quote->createElement("div");
              $err->setAttribute("class","errors bibleQuote");
              $inf = $quote->createElement("div");
              $inf->setAttribute("class", "info bibleQuote");
              break;
        }

        $this->bibleQuote = $quote;
        $this->div        = $div;
        $this->err        = $err;
        $this->inf        = $inf;

    }


    private function populateVersionsInfo(){

        if( $result = $this->mysqli->query( "SELECT * FROM versions_available WHERE type = 'BIBLE'" ) ) {
            while( $row = mysqli_fetch_assoc( $result ) ) {
                $this->validversions[] = $row["sigla"];
                $this->validversions_fullname[$row["sigla"]] = $row["fullname"] . "|" . $row["year"];
                if ($row["copyright"] === 1) {
                    $this->copyrightversions[] = $row["sigla"];
                }
                if($row["canon"] === "CATHOLIC"){
                    $this->CATHOLIC_VERSIONS[] = $row["sigla"];
                } else if ($row["canon"] === "PROTESTANT"){
                    $this->PROTESTANT_VERSIONS[] = $row["sigla"];
                }
            }
        }
        else{
            $this->addErrorMessage( "<p>MySQL ERROR ".$this->mysqli->errno . ": " . $this->mysqli->error."</p>" );
            $this->outputResult();
        }

    }

    private function isValidVersion( string $version ) {
        return( in_array( $version, $this->validversions ) );
    }

    private function prepareIndexes(){

        $indexes = [];

        foreach($this->requestedVersions as $variant){

            $abbreviations = [];
            $bbbooks = [];
            $chapter_limit = [];
            $verse_limit = [];
            $book_num = [];
            
            // fetch the index information for the requested version from the database and load it into our arrays
            if($result = $this->mysqli->query("SELECT * FROM ".$variant."_idx")){
                while($row = $result->fetch_assoc()){
                    $abbreviations[] = $row["abbrev"];
                    $bbbooks[] = $row["fullname"];
                    $chapter_limit[] = $row["chapters"];
                    $verse_limit[] = explode(",",$row["verses_last"]);
                    $book_num[] = $row["book"];
                }
            }
            /*
            else{
              //error
            }
            */
            
            $indexes[$variant]["abbreviations"] = $abbreviations;
            $indexes[$variant]["biblebooks"] = $bbbooks;
            $indexes[$variant]["chapter_limit"] = $chapter_limit;
            $indexes[$variant]["verse_limit"] = $verse_limit;
            $indexes[$variant]["book_num"] = $book_num;  

        }
        
        $this->indexes = $indexes;

    }

    private function prepareBibleBooks(){

        if ( $result1 = $this->mysqli->query( "SELECT * FROM biblebooks_fullname" ) ) {
            $cols = mysqli_num_fields( $result1 );
            $names = [];
            $finfo = mysqli_fetch_fields( $result1 );
            foreach ( $finfo as $val ) {
                $names[] = $val->name;
            }
            if ( $result2 = $this->mysqli->query( "SELECT * FROM biblebooks_abbr" ) ) {
                $cols2 = mysqli_num_fields( $result2 );
                $rows2 = mysqli_num_rows( $result2 );
                $names2 = [];
                $finfo2 = mysqli_fetch_fields( $result2 );
                foreach ( $finfo2 as $val ) {
                    $names2[] = $val->name;
                }

                $n = 0;
                while ( $row1 = mysqli_fetch_assoc($result1) ) {
                    $row2 = mysqli_fetch_assoc( $result2 );
                    $this->biblebooks[$n] = [];

                    for ( $x = 1; $x < $cols; $x++ ) {
                        $temparray = [ $row1[$names[$x]], $row2[$names[$x]] ];

                        $arr1 = explode( " | ", $row1[$names[$x]] );
                        $booknames = array_map( 'self::normalizeBibleBook', $arr1);

                        $arr2 = explode(" | ", $row2[$names[$x]]);
                        $abbrevs = ( count( $arr2 ) > 1 ) ? array_map( 'self::normalizeBibleBook', $arr2) : [];

                        $this->biblebooks[$n][$x] = array_merge( $temparray, $booknames, $abbrevs );
                    }
                    $n++;
                }
            } else {
                $this->addErrorMessage("<p>MySQL ERROR " . $this->mysqli->errno . ": " . $this->mysqli->error . "</p>");
            }
        } else {
            $this->addErrorMessage("<p>MySQL ERROR " . $this->mysqli->errno . ": " . $this->mysqli->error . "</p>");
        }

    }

    private function prepareRequestedVersions() {

        $temp = isset( $this->DATA["version"] ) && $this->DATA["version"] !== "" ? explode( ",", strtoupper( $this->DATA["version"] ) ) : ["CEI2008"];

        foreach ( $temp as $version ) {
            if ( isset( $this->DATA["forceversion"] ) && $this->DATA["forceversion"] === "true" ) {
                $this->requestedVersions[] = $version;
            } else {
                if ( $this->isValidVersion($version) ) {
                    $this->requestedVersions[] = $version;
                } else {
                    $this->addErrorMessage( "Not a valid version: <" . $version . ">, valid versions are <" . implode( " | ", $this->validversions ) . ">" );
                }
            }
            if ( isset( $this->DATA["forcecopyright"] ) && $this->DATA["forcecopyright"] === "true" ) {
                $this->requestedCopyrightedVersions[] = $version;
            }
        }


        if ( count($this->requestedVersions) < 1 ) {
            $this->outputResult();
        }

    }

    private function queryStrClean() {
        $querystr = "";
        // remove all whitespace from the query
        $querystr = preg_replace('/\s+/', '', $this->DATA["query"]);
        // trim shouldn't be necessary now but just in case
        $querystr = trim($querystr);
        // shouldn't have any spaces left but just in case
        $querystr = str_replace(' ', '', $querystr);
        // convert en-dashes, em-dashes, minus signs and any other kind of dashes to simple hyphens
        $querystr = preg_replace('/[\x{2011}-\x{2015}|\x{2212}|\x{23AF}]/u', '-', $querystr);
      
        //if query is written in english notation, convert it to european notation
        $find = array(".", ",", ":");
        $replace = array("", ".", ",");
        //TODO: we need to detect mixed notations even when there are no dots, for example "Mt 5:1;6,2"
        //in other words check what symbol we have immediately after [book &] chapter
        if (strpos($querystr, ":") && strpos($querystr, ".")) {
            //can't use both notations
            $this->addErrorMessage( "Mixed notations have been detected, please use either english or european notation." );
            $this->outputResult();
        } else if (strpos($querystr, ":")) {
            $this->detectedNotation = "ENGLISH";
            $querystr = str_replace($find, $replace, $querystr);
        } else {
            $this->detectedNotation = "EUROPEAN";
        }
      
        //if there are multiple queries separated by semicolons, we explode them into an array
        $queries = explode(";", $querystr);
        //get rid of empty queries
        $queries = array_values(array_filter($queries, function ($var) {
            return $var !== "";
        }));
      
        array_walk($queries,'self::toProperCase');
        return $queries;
    }
    
    private function checkValid($queries) {

        $resultsForCheckValid = new stdClass();
        $resultsForCheckValid->usedvariants = [];
        $resultsForCheckValid->goodqueries  = [];
        $usedvariant                        = "";
        $thisbook                           = "";
        $idx                                = -1;

        foreach ($queries as $query) {
            $fullquery = $query;
            //echo "<p>Now checking validity of query: ".$query."</p>";
            //if(preg_match("/^[1-3]{0,1}[A-Z][a-z]+/u",$query,$res1) != preg_match("/^[1-3]{0,1}[A-Z][a-z]+[1-9][0-9]{0,2}/u",$query,$res2)){
            if (preg_match("/\p{L&}/u", $query)) {
                //echo "<p>We are dealing with a string that has upper/lower case variants.</p>";
                if (preg_match("/^[1-3]{0,1}\p{Lu}\p{Ll}*/u", $query, $res1) != preg_match("/^[1-3]{0,1}\p{Lu}\p{Ll}*[1-9][0-9]{0,2}/u", $query, $res2)) {
                    // error message: every book indication must be followed by a valid chapter indication
                    $this->addErrorMessage(1);
                    // Up the bad counter
                    $this->mysqli->query("UPDATE counter SET bad = bad + 1");
                    return false;
                }

                if (preg_match("/^([1-3]{0,1}((\p{Lu}\p{Ll}*)+))/u", $query, $res)) {
                    $validbookflag = false;
                    $thisbook = $res[0];
                    foreach ($this->requestedVersions as $variant) {
                        //echo "<p>Looping through requested versions: ".$variant."</p>";
                        if (in_array($res[0], $this->indexes[$variant]["biblebooks"]) || in_array($res[0], $this->indexes[$variant]["abbreviations"])) {
                            //echo "<p>Book name ".$res[0]." was found in the indexes of the requested version \"".$variant."\".</p>";
                            $validbookflag = true;
                            $usedvariant = $variant;
                            //we can still use the index for further integrity checks!
                            $idx = self::idxOf($res[0], $this->biblebooks);
                            break;
                        } else {
                            $idx = self::idxOf($res[0], $this->biblebooks);
                            if ($idx !== FALSE) {
                              //echo "<p>Book name ".$res[0]." was recognized as a valid book name, even if not in the indexes of the requested version \"".$variant."\"</p>";
                              $validbookflag = true;
                            }
                        }
                    }
            
                    if (!$validbookflag) {
                        //echo "<p>Book name ".$res[0]." was not recognized as a valid book name.</p>";
                        //echo "<pre>";
                        //print_r($this->biblebooks);
                        //echo "</pre>";
                        // error message: unrecognized book abbreviation
                        $this->addErrorMessage(sprintf('The book abbreviation %s is not a valid abbreviation. Please check the documentation for a list of correct abbreviations.', $thisbook));
                        // Up the bad counter
                        $this->mysqli->query("UPDATE counter SET bad = bad + 1");
                        //return false;
                        continue;
                    } else {
                        $query = str_replace($thisbook, "", $query);
                    }
                } /*else {
                  
                  $var = print_r($res, true);
                  $this->addErrorMessage($var);
                  $this->outputResult();
                  
                }*/
            } else {
                //echo "<p>We are dealing with a string that does not have upper / lower case variants.</p>";
                if (preg_match("/^[1-3]{0,1}(\p{L}\p{M}*)+/u", $query, $res1) != preg_match("/^[1-3]{0,1}(\p{L}\p{M}*)+[1-9][0-9]{0,2}/u", $query, $res2)) {
                    // error message: every book indication must be followed by a valid chapter indication
                    $this->addErrorMessage(1);
                    // Up the bad counter
                    $this->mysqli->query("UPDATE counter SET bad = bad + 1");
                    return false;
                }
                if (preg_match("/^([1-3]{0,1}((\p{L}\p{M}*)+))/u", $query, $res)) {
                    //echo "<p>We have matched the bookname: ".$res[0]."</p>";
                    $thisbook = $res[0];
                    $validbookflag = false;
                    foreach ($this->requestedVersions as $variant) {
                        if (in_array($res[0], $this->indexes[$variant]["biblebooks"]) || in_array($res[0], $this->indexes[$variant]["abbreviations"])) {
                            $validbookflag = true;
                            $usedvariant = $variant;
                            //we can still use the index for further integrity checks!
                            $idx = self::idxOf($res[0], $this->biblebooks);
                            break;
                        } else if (($idx = self::idxOf($res[0], $this->biblebooks)) !== FALSE) {
                            //echo "<p>Book was recognized as valid.</p>";
                            $validbookflag = true;
                        }
                    }
                    if (!$validbookflag) {
                        //echo "<p>ALARM!!! We are getting an invalid book flag.</p>";
                        // error message: unrecognized book abbreviation
                        $this->addErrorMessage(sprintf('The book abbreviation %s is not a valid abbreviation. Please check the documentation for a list of correct abbreviations.', $thisbook));
                        // Up the bad counter
                        $this->mysqli->query("UPDATE counter SET bad = bad + 1");
                        continue;
                    } else {
                        $query = str_replace($thisbook, "", $query);
                    }
                }
            }

            if (strpos($query, ".")) {
                if (!strpos($query, ",") || strpos($query, ",") > strpos($query, ".")) {
                    // error message: You cannot use a dot without first using a comma. A dot is a liason between verses, which are separated from the chapter by a comma.
                    $this->addErrorMessage(3);
                    // Up the bad counter
                    $this->mysqli->query("UPDATE counter SET bad = bad + 1");
                    continue;
                    //return false;
                }
                //if(preg_match_all("/(?=[1-9][0-9]{0,2}\.[1-9][0-9]{0,2})/",$query) != substr_count($query,".") ){
                //if(preg_match_all("/(?=([1-9][0-9]{0,2}\.[1-9][0-9]{0,2}))/",$query) < substr_count($query,".") ){
                if (preg_match_all("/(?<![0-9])(?=([1-9][0-9]{0,2}\.[1-9][0-9]{0,2}))/", $query) != substr_count($query, ".")) {
                    // error message: A dot must be preceded and followed by 1 to 3 digits etc.
                    $this->addErrorMessage(4);
                    // Up the bad counter
                    $this->mysqli->query("UPDATE counter SET bad = bad + 1");
                    continue;
                    //return false;
                }
            }

            if (strpos($query, ",")) {
                if (preg_match_all("/[1-9][0-9]{0,2}\,[1-9][0-9]{0,2}/", $query) != substr_count($query, ",")) {
                    // error message: A comma must be preceded and followed by 1 to 3 digits etc.
                    //echo "There are ".preg_match_all("/(?=[1-9][0-9]{0,2}\,[1-9][0-9]{0,2})/",$query)." matches for commas preceded and followed by valid 1-3 digit sequences;<br>";
                    //echo "There are ".substr_count($query,",")." matches for commas in this query.";
                    $this->addErrorMessage(5);
                    // Up the bad counter
                    $this->mysqli->query("UPDATE counter SET bad = bad + 1");
                    continue;
                    //return false;
                } else {
                    if (preg_match_all("/([1-9][0-9]{0,2})\,/", $query, $matches)) {
                        if (!is_array($matches[1])) {
                            $matches[1] = array(
                                $matches[1]
                            );
                        }
                        $myidx = $idx + 1;
                        foreach ($matches[1] as $match) {
                            foreach ($this->indexes as $jkey => $jindex) {
                                // bibleGetWriteLog("jindex array contains:");
                                // bibleGetWriteLog($jindex);
                                $bookidx = array_search($myidx, $jindex["book_num"]);
                                // bibleGetWriteLog("bookidx for ".$jkey." = ".$bookidx);
                                $chapter_limit = $jindex["chapter_limit"][$bookidx];
                                // bibleGetWriteLog("chapter_limit for ".$jkey." = ".$chapter_limit);
                                // bibleGetWriteLog( "match for " . $jkey . " = " . $match );
                                if ($match > $chapter_limit) {
                                    //addErrorMessage('$myidx = '.$myidx,$returntype);
                                    //addErrorMessage('$bookidx = '.$bookidx,$returntype);
                                    /* translators: the expressions <%1$d>, <%2$s>, <%3$s>, and <%4$d> must be left as is, they will be substituted dynamically by values in the script. See http://php.net/sprintf. */
                                    $msg = 'A chapter in the query is out of bounds: there is no chapter <%1$d> in the book %2$s in the requested version %3$s, the last possible chapter is <%4$d>';
                                    $this->addErrorMessage(sprintf($msg, $match, $thisbook, $jkey, $chapter_limit));
                                    $this->mysqli->query("UPDATE counter SET bad = bad + 1");
                                    continue 3;
                                }
                            }
                        }
              
                        $commacount = substr_count($query, ",");
                        if ($commacount > 1) {
                            if (!strpos($query, '-')) {
                                $this->addErrorMessage("You cannot have more than one comma and not have a dash!");
                                $this->mysqli->query("UPDATE counter SET bad = bad + 1");
                                continue;
                                //return false;
                            }
                            $parts = explode("-", $query);
                            if (count($parts) != 2) {
                                $this->addErrorMessage("You seem to have a malformed querystring, there should be only one dash.");
                                $this->mysqli->query("UPDATE counter SET bad = bad + 1");
                                continue;
                                //return false;
                            }
                            foreach ($parts as $part) {
                                $pp = array_map("intval", explode(",", $part));
                                foreach ($this->indexes as $jkey => $jindex) {
                                    $bookidx = array_search($myidx, $jindex["book_num"]);
                                    $chapters_verselimit = $jindex["verse_limit"][$bookidx];
                                    $verselimit = intval($chapters_verselimit[$pp[0] - 1]);
                                    if ($pp[1] > $verselimit) {
                                        $msg = 'A verse in the query is out of bounds: there is no verse <%1$d> in the book %2$s at chapter <%3$d> in the requested version %4$s, the last possible verse is <%5$d>';
                                        $this->addErrorMessage(sprintf($msg, $pp[1], $thisbook, $pp[0], $jkey, $verselimit));
                                        $this->mysqli->query("UPDATE counter SET bad = bad + 1");
                                        continue 3;
                                        //return false;
                                    }
                                }
                            }
                        } elseif ($commacount == 1) {
                            // bibleGetWriteLog("commacount has been detected as 1, now exploding on comma the query[".$thisquery."]");
                            $parts = explode(",", $query);
                            // bibleGetWriteLog($parts);
                            // bibleGetWriteLog("checking for presence of dashes in the right-side of the comma...");
                            if (strpos($parts[1], '-')) {
                                // bibleGetWriteLog("a dash has been detected in the right-side of the comma(".$parts[1].")");
                                if (preg_match_all("/[,\.][1-9][0-9]{0,2}\-([1-9][0-9]{0,2})/", $query, $matches)) {
                                    if (!is_array($matches[1])) {
                                        $matches[1] = [ $matches[1] ];
                                    }
                                    $highverse = intval(array_pop($matches[1]));
                                    // bibleGetWriteLog("highverse = ".$highverse);
                                    foreach ($this->indexes as $jkey => $jindex) {
                                        $bookidx = array_search($myidx, $jindex["book_num"]);
                                        $chapters_verselimit = $jindex["verse_limit"][$bookidx];
                                        $verselimit = intval($chapters_verselimit[intval($parts[0]) - 1]);
                                        // bibleGetWriteLog("verselimit for ".$jkey." = ".$verselimit);
                                        if ($highverse > $verselimit) {
                                            /* translators: the expressions <%1$d>, <%2$s>, <%3$d>, <%4$s> and %5$d must be left as is, they will be substituted dynamically by values in the script. See http://php.net/sprintf. */
                                            $msg = 'A verse in the query is out of bounds: there is no verse <%1$d> in the book %2$s at chapter <%3$d> in the requested version %4$s, the last possible verse is <%5$d>';
                                            $this->addErrorMessage(sprintf($msg, $highverse, $thisbook, $parts[0], $jkey, $verselimit));
                                            $this->mysqli->query("UPDATE counter SET bad = bad + 1");
                                            continue 2;
                                            //return false;
                                        }
                                    }
                                } /*else {
                                  // bibleGetWriteLog("something is up with the regex check...");
                                }*/
                            } else {
                                if (preg_match("/,([1-9][0-9]{0,2})/", $query, $matches)) {
                                    $highverse = intval($matches[1]);
                                    foreach ($this->indexes as $jkey => $jindex) {
                                        $bookidx = array_search($myidx, $jindex["book_num"]);
                                        $chapters_verselimit = $jindex["verse_limit"][$bookidx];
                                        $verselimit = intval($chapters_verselimit[intval($parts[0]) - 1]);
                                        if ($highverse > $verselimit) {
                                            /* translators: the expressions <%1$d>, <%2$s>, <%3$d>, <%4$s> and %5$d must be left as is, they will be substituted dynamically by values in the script. See http://php.net/sprintf. */
                                            $msg = 'A verse in the query is out of bounds: there is no verse <%1$d> in the book %2$s at chapter <%3$d> in the requested version %4$s, the last possible verse is <%5$d>';
                                            $this->addErrorMessage(sprintf($msg, $highverse, $thisbook, $parts[0], $jkey, $verselimit));
                                            $this->mysqli->query("UPDATE counter SET bad = bad + 1");
                                            continue 2;
                                            //return false;
                                        }
                                    }
                                }
                            }
                
                            if (preg_match_all("/\.([1-9][0-9]{0,2})$/", $query, $matches)) {
                                if (!is_array($matches[1])) {
                                    $matches[1] = [ $matches[1] ];
                                }
                                $highverse = array_pop($matches[1]);
                                foreach ($this->indexes as $jkey => $jindex) {
                                    $bookidx = array_search($myidx, $jindex["book_num"]);
                                    $chapters_verselimit = $jindex["verse_limit"][$bookidx];
                                    $verselimit = intval($chapters_verselimit[intval($parts[0]) - 1]);
                                    if ($highverse > $verselimit) {
                                        /* translators: the expressions <%1$d>, <%2$s>, <%3$d>, <%4$s> and %5$d must be left as is, they will be substituted dynamically by values in the script. See http://php.net/sprintf. */
                                        $msg = 'A verse in the query is out of bounds: there is no verse <%1$d> in the book %2$s at chapter <%3$d> in the requested version %4$s, the last possible verse is <%5$d>';
                                        $this->addErrorMessage(sprintf($msg, $highverse, $thisbook, $parts[0], $jkey, $verselimit));
                                        $this->mysqli->query("UPDATE counter SET bad = bad + 1");
                                        continue 2;
                                        //return false;
                                    }
                                }
                            }
                        }
                    }
                }
            } else {
                $chapters = explode("-", $query);
                foreach ($chapters as $zchapter) {
                    foreach ($this->indexes as $jkey => $jindex) {
                        $myidx = $idx + 1;
                        $bookidx = array_search($myidx, $jindex["book_num"]);
                        $chapter_limit = $jindex["chapter_limit"][$bookidx];
                        //addErrorMessage('$myidx = '.$myidx,$returntype);
                        //addErrorMessage('$bookidx = '.$bookidx,$returntype);
                        //addErrorMessage('$chapter_limit = '.$chapter_limit,$returntype);
                        //addErrorMessage('$zchapter = '.$zchapter,$returntype);
                        //addErrorMessage('$thisbook = '.$thisbook,$returntype);
                        if (intval($zchapter) > $chapter_limit) {
                            $msg = 'A chapter in the query is out of bounds: there is no chapter <%1$d> in the book %2$s in the requested version %3$s, the last possible chapter is <%4$d>';
                            $this->addErrorMessage(sprintf($msg, $zchapter, $thisbook, $jkey, $chapter_limit));
                            $this->mysqli->query("UPDATE counter SET bad = bad + 1");
                            continue 3;
                        }
                    }
                }
            }

            if (strpos($query, "-")) {
                if (preg_match_all("/[1-9][0-9]{0,2}\-[1-9][0-9]{0,2}/", $query) != substr_count($query, "-")) {
                    // error message: A dash must be preceded and followed by 1 to 3 digits etc.
                    //echo "There are ".preg_match("/(?=[1-9][0-9]{0,2}\-[1-9][0-9]{0,2})/",$query)." matches for dashes preceded and followed by valid 1-3 digit sequences;<br>";
                    //echo "There are ".substr_count($query,"-")." matches for dashes in this query.";
                    $this->addErrorMessage(6);
                    // Up the bad counter
                    $this->mysqli->query("UPDATE counter SET bad = bad + 1");
                    continue;
                    //return false;
                }
                if (preg_match("/\-[1-9][0-9]{0,2}\,/", $query) && (!preg_match("/\,[1-9][0-9]{0,2}\-/", $query) || preg_match_all("/(?=\,[1-9][0-9]{0,2}\-)/", $query) > preg_match_all("/(?=\-[1-9][0-9]{0,2}\,)/", $query))) {
                    // error message: there must be as many comma constructs preceding dashes as there are following dashes
                    $this->addErrorMessage(7);
                    // Up the bad counter
                    $this->mysqli->query("UPDATE counter SET bad = bad + 1");
                    continue;
                    //return false;
                }
                if (substr_count($query, "-") > 1 && (!strpos($query, ".") || (substr_count($query, "-") - 1 > substr_count($query, ".")))) {
                    // error message: there cannot be multiple dashes in a query if there are not as many dots minus 1.
                    // Up the bad counter
                    $this->mysqli->query("UPDATE counter SET bad = bad + 1");
                    $this->addErrorMessage(8);
                    continue;
                    //return false;
                }
            }
            $resultsForCheckValid->usedvariants[] = $usedvariant;
            $resultsForCheckValid->goodqueries[] = $fullquery;
            //$usedvariants[] = $usedvariant;

        } //END FOREACH

        //return $usedvariants;
        return $resultsForCheckValid;
    }

    private function formulateQueries($checkedResults) {
        global $biblebooks;
        global $copyrightversions;
        global $versions;
        global $indexes;
        global $BIBLEGET;
        global $CATHOLIC_VERSIONS;
        //global $PROTESTANT_VERSIONS;
        $queries        = $checkedResults->goodqueries;
        $usedvariants   = $checkedResults->usedvariants;
        $sqlqueries = array();
        $queriesversions = array();
        $originalquery = array();
        $nn = 0;
        $sqlquery = "";
        $book = "";
        $usedvariant = "";

        foreach ($this->requestedVersions as $version) {
            $i = 0;
            foreach ($queries as $query) {
                $origquery = $query;
                $book1 = "";
                // Retrieve and store the book in the query string,if applicable
                if (preg_match("/\p{L&}/u", $query)) {
                    if (preg_match("/^[1-4]{0,1}\p{Lu}\p{Ll}*/u", $query, $ret)) {
                        $usedvariant = $usedvariants[$i];
                        // Now that we have captured our book, we can erase it from the query string
                        $query = preg_replace("/^[1-4]{0,1}\p{Lu}\p{Ll}*/u", "", $query);
                        if ($usedvariant != "" && ($key = array_search($ret[0], $this->indexes[$usedvariant]["biblebooks"])) !== FALSE) {
                            $book1 = $book = $this->indexes[$usedvariant]["book_num"][$key];
                        } else if ($usedvariant != "" && ($key = array_search($ret[0], $this->indexes[$usedvariant]["abbreviations"])) !== FALSE) {
                            $book1 = $book = $this->indexes[$usedvariant]["book_num"][$key];
                        } else if (($key = self::idxOf($ret[0], $this->biblebooks)) !== FALSE) {
                            $book1 = $book = $key + 1;
                        }
                    } else {
                        $book1 = $book;
                    }
                } else {
                    if (preg_match("/^[1-4]{0,1}\p{L}+/u", $query, $ret)) {
                        $usedvariant = $usedvariants[$i];
                        // Now that we have captured our book, we can erase it from the query string
                        $query = preg_replace("/^[1-4]{0,1}\p{L}+/u", "", $query);
                        if ($usedvariant != "" && ($key = array_search($ret[0], $this->indexes[$usedvariant]["biblebooks"])) !== FALSE) {
                            $book1 = $book = $this->indexes[$usedvariant]["book_num"][$key];
                        } else if ($usedvariant != "" && ($key = array_search($ret[0], $this->indexes[$usedvariant]["abbreviations"])) !== FALSE) {
                            $book1 = $book = $this->indexes[$usedvariant]["book_num"][$key];
                        } else if (($key = self::idxOf($ret[0], $this->biblebooks)) !== FALSE) {
                            $book1 = $book = $key + 1;
                        }
                    } else {
                        $book1 = $book;
                    }
                }

                $sqlquery = "SELECT * FROM " . $version . " WHERE book = " . $book1;

                $preferorigin = "";
                //if we are dealing with a book that has greek and hebrew variants, we need to distinguish between the two
                if($book1 == 19 || $book1 == "19"){ 
                    //if a protestant version is requested, it will only have HEBREW origin, not GREEK
                    //if preferorigin is not explicitly set, but chapters 11-20 are requested for Esther,
                    //then obviously the HEBREW version is being preferred, we will need to translate this request when we know which chapter we are dealing with
                    if(in_array($version,$CATHOLIC_VERSIONS)){
                        $preferorigin = " AND verseorigin = '" . ( $BIBLEGET['preferorigin'] != "" ? $BIBLEGET['preferorigin'] : "GREEK" ) . "'";
                    }
                }
          
                $xchapter = "";
                //NOTE: all notations have been translated to EUROPEAN notation for the following calculations
                //      1) Therefore we will not find colons, but commas for the chapter-verse separator
                //      2) We will not find commas for non consecutive verses, but dots
                //NOTE: since a dash can have multiple meanings, whereas a dot has one meaning, 
                //      also considering that the dot has to do with the smallest unit in a Bible reference = verse,
                //      whereas a dash could have to do either with verses or with chapters
                //      we start our interpretation of the Bible reference from what is clear and certain
                //      and is the smallest possible unit that we have to work with.
                //      So if there is a dot = non-consecutive verses, we start splitting up the reference around the dot (or dots)
                //NOTE: We have already capture the book, so we are not dealing with the book in the following calculations
                //      However for our own sanity, the book is included in the examples to help understand what is happening
                //      This symbol will be used to indicate splitting into left hand and right hand sections: <=|=>
                
                //IF: non-consecutive verses are requested (EXAMPLE: John 3,16.18)
                if (strpos($query, ".")) {
                    $querysplit = preg_split("/\./", $query);
                    //FOREACH non-consecutive chunk requested (John 3,16 <=|=> 18)
                    foreach ($querysplit as $piece) {
                        $originalquery[$nn] = $origquery;
                        //IF the chunk is not simply a single verse, but is a range of consecutive VERSES or CHAPTERS 
                        //    (EXAMPLE: John 3,16-18.20       OR John 3,16.18-20        OR John 3,16-18.20-22)
                        //    (         John 3,16-18 <=|=> 20 OR John 3,16 <=|=> 18-20  OR John 3,16-18 <=|=> 20-22 )
                        if (strpos($piece, "-")) {
                            $fromto = preg_split("/\-/", $piece);
                            //IF we have a CHAPTER indicator on the left hand side of the range of consecutive verses
                            //  (EXAMPLE: John 3,16-18.20)
                            //  ( John 3,16-18 <=|=> 20 )
                            //  ( John 3 <=> 16-18 )
                            if (strpos($fromto[0], ",")) {
                                //THEN we capture the CHAPTER from the left hand side and the range of consecutive VERSEs from the right hand side
                                $chapterverse = preg_split("/,/", $fromto[0]);
                                $xchapter = $chapterverse[0];
                                $mappedReference = $this->mapReference($version,$book1,$chapterverse[0],$chapterverse[1],$preferorigin);
                                $chapterverse[0] = $mappedReference[0];
                                $chapterverse[1] = $mappedReference[1];
                                $preferorigin = $mappedReference[2];
                                //IF we have a CHAPTER indicator on the right hand side of the range of consecutive verses
                                //  (EXAMPLE: John 3,16-4,5.8 )
                                //  ( John 3,16 <=|=> 4,5 )
                                if (strpos($fromto[1], ",")) {
                                    //THEN we capture the CHAPTER from the left hand side and the range of consecutive VERSEs from the right hand side
                                    $chapterverse1 = preg_split("/,/", $fromto[1]);
                                    $xchapter = $chapterverse1[0];
                                    $mappedReference = $this->mapReference($version,$book1,$chapterverse1[0],$chapterverse1[1],$preferorigin);
                                    $chapterverse1[0] = $mappedReference[0];
                                    $chapterverse1[1] = $mappedReference[1];
                                    $preferorigin = $mappedReference[2];
                                    $sqlqueries[$nn] = $sqlquery . " AND ((chapter = " . $chapterverse[0] . " AND verse >= " . $chapterverse[1] . ")";
                                    if($chapterverse1[0] - $chapterverse[0] > 1) {
                                        for($d=1;$d<($chapterverse1[0] - $chapterverse[0]);$d++){
                                            $sqlqueries[$nn] .= " OR (chapter = " . ($chapterverse[0] + $d) . ")";
                                        }
                                    }
                                    $sqlqueries[$nn] .= " OR (chapter = " . $chapterverse1[0] . " AND verse <= " . $chapterverse1[1] . "))";
                                }
                                //ELSEIF we do NOT have a CHAPTER indicator on the right hand side of the range of consecutive verses
                                // (EXAMPLE: John 3,16-18.20)
                                else {
                                    $mappedReference = $this->mapReference($version,$book1,$xchapter,$fromto[1],$preferorigin);
                                    $xchapter = $mappedReference[0];
                                    $fromto[1] = $mappedReference[1];
                                    $preferorigin = $mappedReference[2];
                                    $sqlqueries[$nn] = $sqlquery . " AND (chapter >= " . $chapterverse[0] . " AND verse >= " . $chapterverse[1] . ")";
                                    $sqlqueries[$nn] .= " AND (chapter <= " . $xchapter . " AND verse <= " . $fromto[1] . ")";
                                }
                            }
                            //ELSEIF we DO NOT have a CHAPTER indicator on the left hand side of the range of consecutive verses
                            //  ( EXAMPLE: John 3,16.18-20 )
                            //  (  John 3,16 <=|=> 18-20 )
                            //  (  18 <=> 20)
                            else {
                                $mappedReference = $this->mapReference($version,$book1,$xchapter,$fromto[0],$preferorigin);
                                $mappedReference1 = $this->mapReference($version,$book1,$xchapter,$fromto[1],$preferorigin);
                                $xchapter = $mappedReference[0];
                                $fromto[0] = $mappedReference[1];
                                $preferorigin = $mappedReference[2];
                                $fromto[1] = $mappedReference1[1];
                                $sqlqueries[$nn] = $sqlquery . " AND (chapter = " . $xchapter . " AND verse >= " . $fromto[0] . " AND verse <= " . $fromto[1] . ")";
                            }
                        }
                        //ELSEIF the non consecutive chunk DOES NOT contain a range of consecutive VERSEs / CHAPTERs
                        //    ( EXAMPLE: John 3,16.18 )
                        //    (   John 3,16 <=|=> 18 )
                        else {
                            //IF the non consecutive chunk however DOES contain a chapter reference
                            //  ( EXAMPLE: John 3,16.18 )
                            //  (   John 3,16 <=|=> 18 )
                            //  (   John 3 <=> 16 )
                            if (strpos($piece, ",")) {
                                $chapterverse = preg_split("/,/", $piece);
                                $xchapter = $chapterverse[0];
                                $mappedReference = $this->mapReference($version,$book1,$chapterverse[0],$chapterverse[1],$preferorigin);
                                $chapterverse[0] = $mappedReference[0];
                                $chapterverse[1] = $mappedReference[1];
                                $preferorigin = $mappedReference[2];
                                $sqlqueries[$nn] = $sqlquery . " AND (chapter = " . $chapterverse[0] . " AND verse = " . $chapterverse[1] . ")";
                            } 
                            //ELSEIF the non consecutive chunk DOES NOT contain a chapter reference
                            //  ( EXAMPLE: John 3,16.18 )
                            //  (    John 3,16 <=|=> 18 )
                            //  ( 18 )
                            else {
                                $mappedReference = $this->mapReference($version,$book1,$xchapter,$piece,$preferorigin);
                                $xchapter = $mappedReference[0];
                                $piece = $mappedReference[1];
                                $preferorigin = $mappedReference[2];
                                $sqlqueries[$nn] = $sqlquery . " AND (chapter = " . $xchapter . " AND verse = " . $piece . ")";
                            }
                        }
              
                        $sqlqueries[$nn] .= $preferorigin;
              
                        $queriesversions[$nn] = $version;
                        //VERSES must be ordered by verseID in order to handle those cases where there are subverses (usually Greek additions)
                        //In these cases, the subverses sometimes come before, sometimes come after the "main" verse
                        //Ex. Esther 1,1a-1r precedes Esther 1,1 but Esther 3,13 precedes Esther 3,13a-13g
                        //Being this the case, it would not be possible to have coherent ordering by book,chapter,verse,verseequiv
                        //The only solution is to make sure the verses are ordered correctly in the table with a unique verseID
                        $sqlqueries[$nn] .= " ORDER BY verseID";
                        //$sqlqueries[$nn] .= " ORDER BY book,chapter,verse,verseequiv";
                        if (in_array($version, $copyrightversions)) {
                            $sqlqueries[$nn] .= " LIMIT 30";
                        }
                        $nn++;
                    }
                } 
                //ELSEIF the request DOES NOT contain non-consecutive verses
                //    ( EXAMPLE: John 3,16 )
                else {
                    //$nn++;
                    //IF the request DOES however contain a range of consecutive verses or chapters
                    //    ( EXAMPLE: John 3,16-18 )
                    //    (    John 3,16 <=|=> 18 )
                    if (strpos($query, "-")) {
                        $originalquery[$nn] = $origquery;
                        $fromto = preg_split("/\-/", $query);
                        //IF there is a chapter indicator on the left hand side of the range of consecutive verses
                        //    ( EXAMPLE: John 3,16-18 )
                        //    (    John 3,16 <=|=> 18 )
                        //    (    John 3,16 )
                        if (strpos($fromto[0], ",")) {
                            //echo "We have a comma in this section of query! ". $fromto[0] . "<br />";
                            $chapterverse = preg_split("/,/", $fromto[0]);
                            $xchapter = $chapterverse[0];
                            $mappedReference = $this->mapReference($version,$book1,$chapterverse[0],$chapterverse[1],$preferorigin);
                            $chapterverse[0] = $mappedReference[0];
                            $chapterverse[1] = $mappedReference[1];
                            $preferorigin = $mappedReference[2];
                            //IF there is also a chapter indicator on the right hand side of the range of consecutive verses
                            //    ( EXAMPLE: John 3,16-4,5 )
                            //    (    John 3,16 <=|=> 4,5 )
                            //    (    4,5 )
                            if (strpos($fromto[1], ",")) {
                                $chapterverse1 = preg_split("/,/", $fromto[1]);
                                $mappedReference = $this->mapReference($version,$book1,$chapterverse1[0],$chapterverse1[1],$preferorigin);
                                $chapterverse1[0] = $mappedReference[0];
                                $chapterverse1[1] = $mappedReference[1];
                                $preferorigin = $mappedReference[2];
                                //what if the difference between chapters is greater than 1? Say: John 3,16-6,2 ?
                                $sqlqueries[$nn] = $sqlquery . " AND ((chapter = " . $chapterverse[0] . " AND verse >= " . $chapterverse[1] . ")";
                                if($chapterverse1[0] - $chapterverse[0] > 1) {
                                    for($d=1;$d<($chapterverse1[0] - $chapterverse[0]);$d++){
                                        $sqlqueries[$nn] .= " OR (chapter = " . ($chapterverse[0] + $d) . ")";
                                    }
                                }
                                $sqlqueries[$nn] .= " OR (chapter = " . $chapterverse1[0] . " AND verse <= " . $chapterverse1[1] . "))";
                            }
                            //ELSEIF there is NOT a chapter indicator on the right hand side of the range of consecutive verses
                            //    ( EXAMPLE: John 3,16-18 )
                            //    (    John 3,16 <=|=> 18 )
                            //    (    18 )
                            else {
                              $sqlqueries[$nn] = $sqlquery . " AND chapter >= " . $chapterverse[0] . " AND verse >= " . $chapterverse[1];
                              $mappedReference = $this->mapReference($version,$book1,$chapterverse[0],$fromto[1],$preferorigin);
                              $fromto[1] = $mappedReference[1];
                              $preferorigin = $mappedReference[2];
                              $sqlqueries[$nn] .= " AND chapter <= " . $mappedReference[0] . " AND verse <= " . $fromto[1];
                            }
                        }
                        //ELSEIF there is NOT a chapter/verse indicator on the left hand side of the range of consecutive verses OR chapters
                        //    this means that we are dealing with consecutive CHAPTERS and not VERSES
                        //     ( EXAMPLE: John 3-4 )
                        //     ( EXAMPLE: 3 <=|=> 4 )
                        else {
                            $mappedReference1 = $this->mapReference($version,$book1,$fromto[0],null,$preferorigin);
                            $mappedReference2 = $this->mapReference($version,$book1,$fromto[1],null,$preferorigin);
                            $fromto[0] = $mappedReference1[0];
                            $fromto[1] = $mappedReference2[0];
                            $preferorigin = $mappedReference1[2];
                            $sqlqueries[$nn] = $sqlquery . " AND chapter >= " . $fromto[0] . " AND chapter <= " . $fromto[1];
                        }
                    }
                    //ELSEIF the request DOES NOT contain a range of consecutive verses OR chapters
                    //    (EXAMPLE: John 3,16)
                    else {
                        //IF we DO have a chapter/verse indicator
                        if (strpos($query, ",")) {
                            $originalquery[$nn] = $origquery;
                            $chapterverse = preg_split("/,/", $query);
                            $xchapter = $chapterverse[0];
                            $mappedReference = $this->mapReference($version,$book1,$chapterverse[0],$chapterverse[1],$preferorigin);
                            $chapterverse[0] = $mappedReference[0];
                            $chapterverse[1] = $mappedReference[1];
                            $preferorigin = $mappedReference[2];
                            $sqlqueries[$nn] = $sqlquery . " AND chapter = " . $chapterverse[0] . " AND verse = " . $chapterverse[1];
                        } 
                        //ELSEIF we are dealing with just a single chapter
                        //    ( EXAMPLE: John 3 )
                        else {
                            $originalquery[$nn] = $origquery;
                            $xchapter = $query;
                            $mappedReference = $this->mapReference($version,$book1,$xchapter,null,$preferorigin);
                            $preferorigin = $mappedReference[2];
                            $sqlqueries[$nn] = $sqlquery . " AND chapter = " . $mappedReference[0]; // . " AND verse = " . $piece;
                        }
                    }
            
                    $sqlqueries[$nn] .= $preferorigin;
            
                    $queriesversions[$nn] = $version;
                    //VERSES must be ordered by verseID in order to handle those cases where there are subverses (usually Greek additions)
                    //In these cases, the subverses sometimes come before, sometimes come after the "main" verse
                    //Ex. Esther 1,1a-1r precedes Esther 1,1 but Esther 3,13 precedes Esther 3,13a-13g
                    //Being this the case, it would not be possible to have coherent ordering by book,chapter,verse,verseequiv
                    //The only solution is to make sure the verses are ordered correctly in the table with a unique verseID
                    $sqlqueries[$nn] .= " ORDER BY verseID";
                    //$sqlqueries[$nn] .= " ORDER BY book,chapter,verse,verseequiv";
                    if (in_array($version, $copyrightversions)) {
                        $sqlqueries[$nn] .= " LIMIT 30";
                    }
                    $nn++;
                }

                $i++;
            }
        }
        return array($sqlqueries, $queriesversions, $originalquery);
        //END formulateQueries
    }
    
    private function mapReference($version,$book,$chapter,$verse,$preferorigin) {
      global $CATHOLIC_VERSIONS;
      if(in_array($version,$CATHOLIC_VERSIONS)){
        if($book == 19 || $book == "19"){ //19 == Esther
          //first let's make sure that $chapter is a number
          if(gettype($chapter) == 'string'){
            //the USCCB uses letters A-F to indicate the Greek additions to Esther
            //however, the BibleGet engine does not allow chapters that are not numbers
            //therefore these verses have been added to the database in the same fashion as the CEI2008 layout
            //TODO: see if there is any way of allowing letters as chapter indicators and then map them to the CEI2008 layout
            $chapter = intval($chapter); 
          }
          if($version == 'VGCL' || $version == 'DRB'){
            if(($chapter == 10 && (($verse >= 4 && $verse <= 13) || $verse == null)) || ($chapter == 11 && ($verse == 1 || $verse == null) )){
              $chapter = 10;
              $verse = 3;
              $preferorigin = " AND verseorigin = 'GREEK'";
            } else if(($chapter == 11 && (($verse >= 2 && $verse <= 12 ) || $verse == null)) || ($chapter == 12 && (($verse >=1 && $verse <= 6) || $verse == null))){
              $chapter = 1;
              $verse = 1;
              $preferorigin = " AND verseorigin = 'GREEK'";
            } else if($chapter == 13 && (($verse >= 1 && $verse <= 7 ) || $verse == null)){
              $chapter = 3;
              $verse = 13;
              $preferorigin = " AND verseorigin = 'GREEK'";
            } else if(($chapter == 13 && (($verse >= 8 && $verse <= 18) || $verse == null)) || ($chapter == 14 && (($verse <= 1 && $verse >= 19) || $verse == null))){
              $chapter = 4;
              $verse = 17;
              $preferorigin = " AND verseorigin = 'GREEK'";
            } else if($chapter == 15 && (($verse >= 1 && $verse <= 3) || $verse == null)){
              $chapter = 4;
              $verse = 8;
              $preferorigin = " AND verseorigin = 'GREEK'";
            } else if($chapter == 15 && (($verse >= 4 && $verse <= 14) || $verse == null)){
              $chapter = 5;
              $verse = 1;
              $preferorigin = " AND verseorigin = 'GREEK'";
            } else if($chapter == 15 && (($verse >= 15 && $verse <= 19) || $verse == null)){
              $chapter = 5;
              $verse = 2;
              $preferorigin = " AND verseorigin = 'GREEK'";
            } else if($chapter == 16){
              $chapter = 8;
              $verse = 12;
              $preferorigin = " AND verseorigin = 'GREEK'";
            }
          }
        }
      }
      return [$chapter,$verse,$preferorigin];
    }
    
    private function getGeoIpInfo($ipaddress) {
      $ch = curl_init("https://ipinfo.io/" . $ipaddress . "?token=" . IPINFO_ACCESS_TOKEN);
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
      if (($geoip_json = curl_exec($ch)) === false) {
        $this->mysqli->query("INSERT INTO curl_error (ERRNO,ERROR) VALUES(" . curl_errno($ch) . ",'" . curl_error($ch) . "')");
      }
      //Check the status of communication with ipinfo.io server
      $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
      curl_close($ch);
    
      if ($http_status == 429) {
        $geoip_json = '{"ERROR":"api limit exceeded"}';
      } else if ($http_status == 200) {
        //Clean geopip_json object, ensure it is valid in any case
        //$geoip_json = $this->mysqli->real_escape_string($geoip_json); // we don't need to escape it when it's coming from the ipinfo.io server, at least not before inserting into the database
        //Check if it's actually an object or if it's not a string perhaps
        $geoip_JSON_obj = json_decode($geoip_json);
        if ($geoip_JSON_obj === null || json_last_error() !== JSON_ERROR_NONE) {
          //we have a problem with our geoip_json, it's probably a string with an error. We should already have escaped it           
          $geoip_json = '{"ERROR":"' . json_last_error() . ' <' . $geoip_json . '>"}';
        } else {
          $geoip_json = json_encode($geoip_JSON_obj);
        }
      } else {
        $geoip_json = '{"ERROR":"wrong http status > ' . $http_status . '"}';
      }
      return $geoip_json;
    }
    
    private function doQueries($sqlqueries, $queriesversions, $originalquery) {
      global $div;
      //global $err;
      //global $sections;
      global $indexes;
      global $returntype;
      global $bbquery;
      global $BIBLEGET;
    
      $requestmethod = isset($_SERVER['HTTP_X_REQUESTED_WITH']) ? $_SERVER['HTTP_X_REQUESTED_WITH'] : $_SERVER["REQUEST_METHOD"];
      $headersObj = apache_request_headers();
      $headers = json_encode($headersObj);
    
      // I need to find a way to check and counter when someone uses in an indiscriminate manner
      // People need to learn to do cacheing of requests (maybe they're trying to turn it into a kind of DDOS attack?)
      // Need to protect ourselves here, but instead of pointing fingers by looking for example at the referring site,
      // we need to base the check on past requests
    
      // First we initialize some variables and flags with default values
      $version = "";
      $newversion = false;
      $book = "";
      $newbook = false;
      $chapter = 0;
      $newchapter = false;
    
      //echo "<pre>";
      //print_r($sqlqueries);
      //echo "</pre>";
      //die("DEBUG");
    
      // HTML return type is a special case, because we must already implement display logic to the structured data that is returned 
      $i = 0;
      $appid = "";
      $domain = "";
      $pluginversion = "";
    
      foreach ($sqlqueries as $xquery) {
    
        //$ipaddress = isset($_SERVER["HTTP_X_FORWARDED_FOR"]) && $_SERVER["HTTP_X_FORWARDED_FOR"] != "" ? explode(",",$_SERVER["HTTP_X_FORWARDED_FOR"])[0] : $_SERVER["REMOTE_ADDR"];
        $forwardedip = isset($_SERVER["HTTP_X_FORWARDED_FOR"]) ? $_SERVER["HTTP_X_FORWARDED_FOR"] : "";
        $remote_address = isset($_SERVER["REMOTE_ADDR"]) ? $_SERVER["REMOTE_ADDR"] : "";
        $realip = isset($_SERVER["HTTP_X_REAL_IP"]) ? $_SERVER["HTTP_X_REAL_IP"] : "";
        $clientip = isset($_SERVER["HTTP_CLIENT_IP"]) ? $_SERVER["HTTP_CLIENT_IP"] : "";
    
        //Do our best to identify an IP address associated with the incoming request, 
        //trying first HTTP_X_FORWARDED_FOR, then REMOTE_ADDR and last resort HTTP_X_REAL_IP
        //This is useful only to protect against high volume requests from specific IP addresses or referers
        $ipaddress = isset($_SERVER["HTTP_X_FORWARDED_FOR"]) && $_SERVER["HTTP_X_FORWARDED_FOR"] != "" ? explode(",", $_SERVER["HTTP_X_FORWARDED_FOR"])[0] : "";
        if ($ipaddress == "") {
          $ipaddress = isset($_SERVER["REMOTE_ADDR"]) && $_SERVER["REMOTE_ADDR"] != "" ? $_SERVER["REMOTE_ADDR"] : "";
        }
        if ($ipaddress == "") {
          $ipaddress = isset($_SERVER["HTTP_X_REAL_IP"]) && $_SERVER["HTTP_X_REAL_IP"] != "" ? $_SERVER["HTTP_X_REAL_IP"] : "";
        }
    
        if (filter_var($ipaddress, FILTER_VALIDATE_IP) === false) {
          $this->addErrorMessage("The BibleGet API endpoint cannot be used behind a proxy that hides the IP address from which the request is coming. No personal or sensitive data is collected by the API, however IP addresses are monitored to prevent spam requests. If you believe there is an error because this is not the case, please contact the developers so they can look into the situtation.", $xquery);
          $this->outputResult(); //this should exit the script right here, closing the mysql connection
        }
    
        //we start off with the supposition that we've never seen this IP address before
        $haveip = false; //"haveip" means "we already have this ip address in our records"
        //request logs are now divided by year, to keep things cleaner and easier to access and read
        $curYEAR = date("Y");
    
        global $WhitelistedDomainsIPs;
        //Don't enforce the max limit for requests from domains that need to do a lot of testing for plugin development
        if (array_search($BIBLEGET["domain"], $WhitelistedDomainsIPs) !== false || array_search($ipaddress, $WhitelistedDomainsIPs) !== false) {
          $originHeader = key_exists("ORIGIN", $headersObj) ? $headersObj["ORIGIN"] : "";
        } else {
          //check if we have already seen this IP Address in the past 2 days and if we have the same request already
          if ($ipaddress != "" && $ipresult = $this->mysqli->query("SELECT * FROM requests_log__" . $curYEAR . " WHERE WHO_IP = INET6_ATON('" . $ipaddress . "') AND QUERY = '" . $xquery . "'  AND WHO_WHEN > DATE_SUB(NOW(), INTERVAL 2 DAY)")) {
            if (DEBUG_IPINFO === true) {
              file_put_contents(DEBUGFILE, "We have seen the IP Address [" . $ipaddress . "] in the past 2 days with this same request [" . $xquery . "]" . PHP_EOL, FILE_APPEND | LOCK_EX);
            }
            //if more than 10 times in the past two days (but less than 30) simply add message inviting to use cacheing mechanism
            if ($ipresult->num_rows > 10 && $ipresult->num_rows < 30) {
              $this->addErrorMessage(10, $xquery);
              $iprow = $ipresult->fetch_assoc();
              $geoip_json = $iprow["WHO_WHERE_JSON"];
              $haveip = true;
            }
            //if we have more than 30 requests in the past two days for the same query, deny service?
            else if ($ipresult->num_rows > 29) {
              $this->addErrorMessage(11, $xquery);
              $this->outputResult(); //this should exit the script right here, closing the mysql connection
            }
          }
    
          //and if the same IP address is making too many requests(>100?) with different queries (like copying the bible texts completely), deny service
          if ($ipaddress != "" && $ipresult = $this->mysqli->query("SELECT * FROM requests_log__" . $curYEAR . " WHERE WHO_IP = INET6_ATON('" . $ipaddress . "') AND WHO_WHEN > DATE_SUB(NOW(), INTERVAL 2 DAY)")) {
            if (DEBUG_IPINFO === true) {
              file_put_contents(DEBUGFILE, "We have seen the IP Address [" . $ipaddress . "] in the past 2 days with many different requests" . PHP_EOL, FILE_APPEND | LOCK_EX);
            }
            //if we 50 or more requests in the past two days, deny service?
            if ($ipresult->num_rows > 100) {
              if (DEBUG_IPINFO === true) {
                file_put_contents(DEBUGFILE, "We have seen the IP Address [" . $ipaddress . "] in the past 2 days with over 50 requests", FILE_APPEND | LOCK_EX);
              }
              $this->addErrorMessage(12, $xquery);
              $this->outputResult(); //this should exit the script right here, closing the mysql connection
            }
          }
    
          //let's add another check for "referer" websites and how many similar requests have derived from the same origin in the past couple days
          $originHeader = key_exists("ORIGIN", $headersObj) ? $headersObj["ORIGIN"] : "";
          if ($originres = $this->mysqli->query("SELECT ORIGIN,COUNT(*) AS ORIGIN_CNT FROM requests_log__" . $curYEAR . " WHERE QUERY = '" . $xquery . "' AND ORIGIN != '' AND ORIGIN = '" . $originHeader . "' AND WHO_WHEN > DATE_SUB(NOW(), INTERVAL 2 DAY) GROUP BY ORIGIN")) {
            if ($originres->num_rows > 0) {
              $originRow = $originres->fetch_assoc();
              if (key_exists("ORIGIN_CNT", $originRow)) {
                if ($originRow["ORIGIN_CNT"] > 10 && $originRow["ORIGIN_CNT"] < 30) {
                  $this->addErrorMessage(10, $xquery);
                } else if ($originRow["ORIGIN_CNT"] > 29) {
                  $this->addErrorMessage(11, $xquery);
                  $this->outputResult(); //this should exit the script right here, closing the mysql connection                
                }
              }
            }
          }
          //and we'll check for diverse requests from the same origin in the past couple days (>100?)
          if ($originres = $this->mysqli->query("SELECT ORIGIN,COUNT(*) AS ORIGIN_CNT FROM requests_log__" . $curYEAR . " WHERE ORIGIN != '' AND ORIGIN = '" . $originHeader . "' AND WHO_WHEN > DATE_SUB(NOW(), INTERVAL 2 DAY) GROUP BY ORIGIN")) {
            if ($originres->num_rows > 0) {
              $originRow = $originres->fetch_assoc();
              if (key_exists("ORIGIN_CNT", $originRow)) {
                if ($originRow["ORIGIN_CNT"] > 100) {
                  $this->addErrorMessage(12, $xquery);
                  $this->outputResult(); //this should exit the script right here, closing the mysql connection
                }
              }
            }
          }
        } // end max request checks
    
        $myversion = $queriesversions[$i];
        //     echo $i.") myversion = ".$myversion."<br />";
        //     echo "about to query the database: &lt;".$xquery."&gt;<br />";
        if ($result = $this->mysqli->query($xquery)) {
          //       echo "<p>We have results from query ".$xquery."</p>";
          // Up the good counter!
          $this->mysqli->query("UPDATE counter SET good = good + 1");
    
    
    
          //if we already have a record of this IP address and we have info on it from ipinfo.io,
          //then we don't need to get info on it from ipinfo.io again (which has limit of 1000 requests per day)
          $pregmatch = preg_quote('{"ERROR":"', '/');
          if ($haveip === false || $geoip_json == "" || $geoip_json === null || preg_match("/" . $pregmatch . "/", $geoip_json)) {
            if (DEBUG_IPINFO === true) {
              file_put_contents(DEBUGFILE, "Either we have not yet seen the IP address [" . $ipaddress . "] in the past 2 days or we have not geo_ip info [" . $geoip_json . "]" . PHP_EOL, FILE_APPEND | LOCK_EX);
            }
            if ($ipaddress != "" && $ipresult = $this->mysqli->query("SELECT * FROM requests_log__" . $curYEAR . " WHERE WHO_IP = INET6_ATON('" . $ipaddress . "') AND WHO_WHERE_JSON NOT LIKE '{\"ERROR\":\"%\"}'")) {
              if ($ipresult->num_rows > 0) {
                if (DEBUG_IPINFO === true) {
                  file_put_contents(DEBUGFILE, "We already have valid geo_ip info [" . $geoip_json . "] for the IP address [" . $ipaddress . "], reusing" . PHP_EOL, FILE_APPEND | LOCK_EX);
                }
                $iprow = $ipresult->fetch_assoc();
                $geoip_json = $iprow["WHO_WHERE_JSON"];
                $haveip = true;
              } else {
                if (DEBUG_IPINFO === true) {
                  file_put_contents(DEBUGFILE, "We do not yet have valid geo_ip info [" . $geoip_json . "] for the IP address [" . $ipaddress . "], nothing to reuse" . PHP_EOL, FILE_APPEND | LOCK_EX);
                }
                $geoip_json = getGeoIpInfo($ipaddress);
                if (DEBUG_IPINFO === true) {
                  file_put_contents(DEBUGFILE, "We have attempted to get geo_ip info [" . $geoip_json . "] for the IP address [" . $ipaddress . "] from ipinfo.io" . PHP_EOL, FILE_APPEND | LOCK_EX);
                }
              }
            } else if ($ipaddress != "") {
              if (DEBUG_IPINFO === true) {
                file_put_contents(DEBUGFILE, "We do however seem to have a valid IP address [" . $ipaddress . "] , now trying to fetch info from ipinfo.io" . PHP_EOL, FILE_APPEND | LOCK_EX);
              }
              $geoip_json = getGeoIpInfo($ipaddress);
              if (DEBUG_IPINFO === true) {
                file_put_contents(DEBUGFILE, "Even in this case we have attempted to get geo_ip info [" . $geoip_json . "] for the IP address [" . $ipaddress . "] from ipinfo.io" . PHP_EOL, FILE_APPEND | LOCK_EX);
              }
            }
          }
    
    
    
          if ($appid === "") {
            $appid = ($BIBLEGET["appid"] != "") ? $BIBLEGET["appid"] : "unknown";
          }
          if ($domain === "") {
            $domain = ($BIBLEGET["domain"] != "") ? $BIBLEGET["domain"] : "unknown";
          }
          if ($pluginversion === "") {
            $pluginversion = ($BIBLEGET["pluginversion"] != "") ? $BIBLEGET["pluginversion"] : "unknown";
          }
          $ipaddress = $ipaddress != "" ? $ipaddress : "0.0.0.0";
          if ($geoip_json === "" || $geoip_json === null) {
            $geoip_json = '{"ERROR":""}';
          }
    
          $stmt = $this->mysqli->prepare("INSERT INTO requests_log__" . $curYEAR . " (WHO_IP,WHO_WHERE_JSON,HEADERS_JSON,ORIGIN,QUERY,ORIGINALQUERY,REQUEST_METHOD,HTTP_CLIENT_IP,HTTP_X_FORWARDED_FOR,HTTP_X_REAL_IP,REMOTE_ADDR,APP_ID,DOMAIN,PLUGINVERSION) VALUES (INET6_ATON(?), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
          $stmt->bind_param('ssssssssssssss', $ipaddress, $geoip_json, $headers, $originHeader, $xquery, $originalquery[$i], $requestmethod, $clientip, $forwardedip, $realip, $remote_address, $appid, $domain, $pluginversion);
          if ($stmt->execute() === false) {
            $this->addErrorMessage("There has been an error updating the logs: (" . $this->mysqli->errno . ") " . $this->mysqli->error);
          }
          $stmt->close();
    
          $verse = "";
          $newverse = false;
          while ($row = $result->fetch_assoc()) {
    
            $row["version"] = strtoupper($myversion);
            $row["testament"] = (int)$row["testament"];
    
            $universal_booknum = $row["book"];
            $booknum = array_search($row["book"], $this->indexes[$myversion]["book_num"]);
            $row["bookabbrev"] = $this->indexes[$myversion]["abbreviations"][$booknum];
            $row["booknum"] = $booknum;
            $row["univbooknum"] = $universal_booknum;
            $row["book"] = $this->indexes[$myversion]["biblebooks"][$booknum];
    
            $row["section"] = (int) $row["section"];
            unset($row["verseID"]);
            //$row["verse"] = (int) $row["verse"];
            $row["chapter"] = (int) $row["chapter"];
            $row["originalquery"] = $originalquery[$i];
    
            if ($returntype == "xml") {
              $thisrow = $bbquery->results->addChild("result");
              foreach ($row as $key => $value) {
                $thisrow[$key] = $value;
              }
            } elseif ($returntype == "json") {
              $bbquery->results[] = $row;
            } elseif ($returntype == "html") {
    
              if ($row["verse"] != $verse) {
                $newverse = true;
                $verse = $row["verse"];
              } else {
                $newverse = false;
              }
    
              if ($row["chapter"] != $chapter) {
                $newchapter = true;
                $newverse = true;
                $chapter = $row["chapter"];
              } else {
                $newchapter = false;
              }
    
              if ($row["book"] != $book) {
                $newbook = true;
                $newchapter = true;
                $newverse = true;
                $book = $row["book"];
              } else {
                $newbook = false;
              }
    
              if ($row["version"] != $version) {
                $newversion = true;
                $newbook = true;
                $newchapter = true;
                $newverse = true;
                $version = $row["version"];
              } else {
                $newversion = false;
              }
    
              if ($newversion) {
                $variant = $bbquery->createElement("p", $row["version"]);
                if ($i > 0) {
                  $br = $bbquery->createElement("br");
                  $variant->insertBefore($br, $variant->firstChild);
                }
                $variant->setAttribute("class", "version bibleVersion");
                $div->appendChild($variant);
              }
              if ($newbook || $newchapter) {
                $citation = $bbquery->createElement("p", $row["book"] . "&nbsp;" . $row["chapter"]);
                $citation->setAttribute("class", "book bookChapter");
                $div->appendChild($citation);
                $citation1 = $bbquery->createElement("p");
                $citation1->setAttribute("class", "verses versesParagraph");
                $div->appendChild($citation1);
                $metainfo = $bbquery->createElement("input");
                $metainfo->setAttribute("type", "hidden");
                $metainfo->setAttribute("class", "originalQuery");
                $metainfo->setAttribute("value", $row["originalquery"]);
                $div->appendChild($metainfo);
                $metainfo1 = $bbquery->createElement("input");
                $metainfo1->setAttribute("type", "hidden");
                $metainfo1->setAttribute("class", "bookAbbrev");
                $metainfo1->setAttribute("value", $row["bookabbrev"]);
                $div->appendChild($metainfo1);
                $metainfo2 = $bbquery->createElement("input");
                $metainfo2->setAttribute("type", "hidden");
                $metainfo2->setAttribute("class", "bookNum");
                $metainfo2->setAttribute("value", $row["booknum"]);
                $div->appendChild($metainfo2);
                $metainfo3 = $bbquery->createElement("input");
                $metainfo3->setAttribute("type", "hidden");
                $metainfo3->setAttribute("class", "univBookNum");
                $metainfo3->setAttribute("value", $row["univbooknum"]);
                $div->appendChild($metainfo3);
              }
              if ($newverse) {
                $versicle = $bbquery->createElement("span", $row["verse"]);
                $versicle->setAttribute("class", "sup verseNum");
                $citation1->appendChild($versicle);
              }
    
              $text = $bbquery->createElement("span", $row["text"]);
              $text->setAttribute("class", "text verseText");
              $citation1->appendChild($text);
            }
          }
        } else {
          $this->addErrorMessage(9, $xquery);
        }
        $i++;
      }
    }


    public function Init() {

        switch($this->returnType){
            case "xml":
              header('Content-Type: application/xml; charset=utf-8');
              break;
            case "json":
              header('Content-Type: application/json; charset=utf-8');
              break;
            case "html":
              header('Content-Type: text/html; charset=utf-8');
              break;
            default:
              header('Content-Type: application/json; charset=utf-8');
        }

        $this->BibleQuoteInit();
        $this->dbConnect();
        $this->populateVersionsInfo();
        $this->prepareBibleBooks();
        $this->prepareRequestedVersions();
        $this->prepareIndexes();

        if (isset($this->DATA["query"]) && $this->DATA["query"] !== "") {
            $queries = queryStrClean($this->DATA["query"]);

            //at least the first query must start with a book reference, which may have a number from 1 to 3 at the beginning
            //echo "matching against: ".$queries[0]."<br />";
            if (!preg_match("/^[1-3]{0,1}\p{Lu}\p{Ll}*/u", $queries[0])) {
                if (!preg_match("/^[1-3]{0,1}(\p{L}\p{M}*)+/u", $queries[0])) {
                    // error message: querystring must have book indication at the very start...
                    $this->addErrorMessage(0);
                    $this->outputResult();
                }
            }
            //enforce rules on each query, return an array of the versions referred to in the queries 
            $resultsForCheckValid = $this->checkValid($queries);
            $usedvariants = property_exists($resultsForCheckValid, 'usedvariants') ? $resultsForCheckValid->usedvariants : false;
            if (!is_array($usedvariants)) {
                $this->outputResult();
            } else {
                //     echo "<pre>";
                //     print_r($usedvariants);
                //     echo "</pre>";
                // 3 -> TRANSLATE BIBLE NOTATION QUERIES TO MYSQL QUERIES
                //$temp = formulateQueries($queries);
                $temp = $this->formulateQueries($resultsForCheckValid);
                $mysqlqueries = $temp[0];
                $queriesversions = $temp[1];
                $originalquery = $temp[2];
                //     echo "<pre>";
                //     print_r($mysqlqueries);
                //     echo "</pre>";

                // 5 -> DO MYSQL QUERIES AND COLLECT RESULTS IN OBJECT 
                $this->doQueries($mysqlqueries, $queriesversions, $originalquery);


                // 6 -> OUTPUT RESULTS FORMATTED ACCORDING TO REQUESTED RETURN TYPE    
                $this->outputResult();
            }

        }

    }

}




// valid return types
$returntypes = array("json", "xml", "html");
$allowed_accept_headers = array("application/json", "application/xml", "text/html");
$allowed_content_types = array("application/json" , "application/x-www-form-urlencoded");
$allowed_request_methods = array("GET","POST");

if(isset($_SERVER['CONTENT_TYPE']) && !in_array($_SERVER['CONTENT_TYPE'],$allowed_content_types)){
    die('{"error":"You seem to be forming a strange kind of request? Allowed Content Types are application/json and application/x-www-form-urlencoded, but your Content Type was '.$_SERVER['CONTENT_TYPE'].'"}');
}

if(!isset($_SERVER['REQUEST_METHOD']) || !in_array(strtoupper($_SERVER['REQUEST_METHOD']),$allowed_request_methods)){
    die('{"error":"You seem to be forming a strange kind of request? Allowed Request Methods are GET and POST, but your Request Method was '.strtoupper($_SERVER['REQUEST_METHOD']).'"}');
}

$requestHeaders = getallheaders();
$acceptHeader = isset($requestHeaders["Accept"]) && in_array($requestHeaders["Accept"], $allowed_accept_headers) ? $returntypes[array_search($requestHeaders["Accept"], $allowed_accept_headers)] : "";

// initialize returntype with default value of "json" if none is given (can be json, xml, or html)
$returntype = ($acceptHeader !== "" ? $acceptHeader : $returntypes[0]);

// declare our global variable object
$BIBLEGET = array();

$allowedPreferredOrigins = ["GREEK","HEBREW"];

// Let's accept both POST requests and GET requests
// However let's also accept a JSON object in the body
if(isset($_SERVER['CONTENT_TYPE']) && $_SERVER['CONTENT_TYPE'] === "application/json"){
    $json = file_get_contents('php://input');
    $data = json_decode($json,true);
    if(NULL === $data){
      //addErrorMessage("No JSON data received in the request: <" . $json . ">", $returntype);
      //we can't user addErrorMessage until we have initted bibleQueryInit
      die("No JSON data received in the request: <" . $json . ">");
    } else if (json_last_error() !== JSON_ERROR_NONE) {
      //addErrorMessage("Malformed JSON data received in the request: <" . $json . ">, " . json_last_error_msg(), $returntype);
      //we can't user addErrorMessage until we have initted bibleQueryInit
      die("Malformed JSON data received in the request: <" . $json . ">, " . json_last_error_msg());
    } else {
      //Seems we have some valid JSON data to work with
      $BIBLEGET["query"]                  = isset($data["query"])            ? $data["query"]           : "";
      $BIBLEGET["return"]                 = isset($data["return"])           ? $data["return"]          : "";
      $BIBLEGET["version"]                = isset($data["version"])          ? $data["version"]         : "";
      $BIBLEGET["domain"]                 = isset($data["domain"])           ? $data["domain"]          : "";
      $BIBLEGET["appid"]                  = isset($data["appid"])            ? $data["appid"]           : "";
      $BIBLEGET["pluginversion"]          = isset($data["pluginversion"])    ? $data["pluginversion"]   : "";
      $BIBLEGET["forceversion"]           = isset($data["forceversion"])     ? $data["forceversion"]    : "";
      $BIBLEGET["forcecopyright"]         = isset($data["forcecopyright"])   ? $data["forcecopyright"]  : "";
      $BIBLEGET["preferorigin"]           = isset($data["preferorigin"]) && in_array($data["preferorigin"],$allowedPreferredOrigins)     ? $data["preferorigin"]    : "";
    }
} else if (strtoupper($_SERVER['REQUEST_METHOD']) === 'POST') {
    $BIBLEGET["query"]                  = isset($_POST["query"])            ? $_POST["query"]           : "";
    $BIBLEGET["return"]                 = isset($_POST["return"])           ? $_POST["return"]          : "";
    $BIBLEGET["version"]                = isset($_POST["version"])          ? $_POST["version"]         : "";
    $BIBLEGET["domain"]                 = isset($_POST["domain"])           ? $_POST["domain"]          : "";
    $BIBLEGET["appid"]                  = isset($_POST["appid"])            ? $_POST["appid"]           : "";
    $BIBLEGET["pluginversion"]          = isset($_POST["pluginversion"])    ? $_POST["pluginversion"]   : "";
    $BIBLEGET["forceversion"]           = isset($_POST["forceversion"])     ? $_POST["forceversion"]    : "";
    $BIBLEGET["forcecopyright"]         = isset($_POST["forcecopyright"])   ? $_POST["forcecopyright"]  : "";
    $BIBLEGET["preferorigin"]           = isset($_POST["preferorigin"]) && in_array($_POST["preferorigin"],$allowedPreferredOrigins)     ? $_POST["preferorigin"]    : "";
} else if (strtoupper($_SERVER['REQUEST_METHOD']) === 'GET') {
    $BIBLEGET["query"]                  = isset($_GET["query"])             ? $_GET["query"]            : "";
    $BIBLEGET["return"]                 = isset($_GET["return"])            ? $_GET["return"]           : "";
    $BIBLEGET["version"]                = isset($_GET["version"])           ? $_GET["version"]          : "";
    $BIBLEGET["domain"]                 = isset($_GET["domain"])            ? $_GET["domain"]           : "";
    $BIBLEGET["appid"]                  = isset($_GET["appid"])             ? $_GET["appid"]            : "";
    $BIBLEGET["pluginversion"]          = isset($_GET["pluginversion"])     ? $_GET["pluginversion"]    : "";
    $BIBLEGET["forceversion"]           = isset($_GET["forceversion"])      ? $_GET["forceversion"]     : "";
    $BIBLEGET["forcecopyright"]         = isset($_GET["forcecopyright"])    ? $_GET["forcecopyright"]   : "";
    $BIBLEGET["preferorigin"]           = isset($_GET["preferorigin"]) && in_array($_GET["preferorigin"],$allowedPreferredOrigins)      ? $_GET["preferorigin"]     : "";
} else if (strtoupper($_SERVER['REQUEST_METHOD']) === 'PUT') {
    die('{"error":"PUT requests are not allowed, please issue GET or POST requests"}');
} else {
    die('{"error":"you seem to be forming a strange kind of request? allowed methods are GET and POST, and allowed Content Types are application/json and application/x-www-form-urlencoded. Your server request method was '.$_SERVER['REQUEST_METHOD'].' and your Content Type was '.$_SERVER['CONTENT_TYPE'].'"}');
}

//returntype set explicitly in parameters can override accept header
$returntype = (isset($BIBLEGET["return"]) && $BIBLEGET["return"] != "" && in_array(strtolower($BIBLEGET["return"]), $returntypes)) ? strtolower($BIBLEGET["return"]) : $returntype;


if (DEBUG_REQUESTS === true) {
  $data = "********************" . PHP_EOL;
  $data .= date('l, F jS Y H:i:s T') . PHP_EOL;
  $data .= print_r($_SERVER, true);
  if (strtoupper($_SERVER['REQUEST_METHOD']) === 'POST') {
    $data .= print_r($_POST, true);
  }
  if (isset($_POST["forceversion"])) {
    $data .= "forceversion parameter is set, value = " . ($_POST["forceversion"] ? "true" : "false") . PHP_EOL;
  }
  $data .= PHP_EOL;
  $fput_result = file_put_contents(DEBUGFILE, $data, FILE_APPEND | LOCK_EX);
  // if($fput_result){
  //   echo "LOG WRITE SUCCESSFUL";
  // }
  // else{
  //   echo "LOG WRITE NOT SUCCESSFUL";
  // }

}

switch ($returntype) {
    case "xml":
        header('Content-Type: application/xml; charset=utf-8');
        break;
    case "json":
        header('Content-Type: application/json; charset=utf-8');
        break;
    case "html":
        header('Content-Type: text/html; charset=utf-8');
        break;
    default:
        header('Content-Type: application/json; charset=utf-8');
}

/*
$current_ip = ip2long($_SERVER['REMOTE_ADDR']);
foreach($ipranges_low as $key => $iprange_low){
  if ( $current_ip >= ip2long($iprange_low) && $current_ip <= ip2long($ipranges_high[$key]) ){
    echo "{\"error\",\"Your ip range has been blocked. If you believe this is an error, please contact the Project Administrator.\"}";
    exit(0);
  }
}
*/

/*****************************************************************************
 * INITIALIZE THE OBJECT THAT WILL COLLECT THE RESULTS, BASED ON RETURN TYPE *
 ****************************************************************************/

$temp = biblequeryInit($returntype);

$bbquery = $temp[0];
$div = $temp[1]; //useful only for html; needs to be appended
$err = $temp[2]; //useful only for html; needs to be appended   
$inf = $temp[3];

/************************************************
 * PREPARE SOME GLOBALS THAT WE WILL BE NEEDING *
 ***********************************************/
$WhitelistedDomainsIPs = [];
// open the connection to the database
$mysqli = dbConnect();

// PREPARE VALIDVERSIONS ARRAY
$validversions = array();
$validversions_fullname = array();
$copyrightversions = array();
$PROTESTANT_VERSIONS = array();
$CATHOLIC_VERSIONS =  array();

if ($result = $mysqli->query("SELECT * FROM versions_available WHERE type = 'BIBLE'")) { //we will only consider BIBLEs here, not LITERATURE
  while ($row = mysqli_fetch_assoc($result)) {
    $validversions[] = $row["sigla"];
    $validversions_fullname[$row["sigla"]] = $row["fullname"] . "|" . $row["year"];
    if ($row["copyright"] == 1) {
      $copyrightversions[] = $row["sigla"];
    }
    if($row["canon"] == "CATHOLIC"){
      $CATHOLIC_VERSIONS[] = $row["sigla"];
    } else if ($row["canon"] == "PROTESTANT"){
      $PROTESTANT_VERSIONS[] = $row["sigla"];
    }
  }
} else {
  addErrorMessage("<p>MySQL ERROR " . $mysqli->errno . ": " . $mysqli->error . "</p>", $returntype);
}

$detectedNotation = "ENGLISH"; //can be "ENGLISH" or "EUROPEAN"

// PREPARE BIBLEBOOKS ARRAY
$biblebooks = array();
if ($result1 = $mysqli->query("SELECT * FROM biblebooks_fullname")) {
  $cols = mysqli_num_fields($result1);
  $names = array();
  $finfo = mysqli_fetch_fields($result1);
  foreach ($finfo as $val) {
    $names[] = $val->name;
  }
  if ($result2 = $mysqli->query("SELECT * FROM biblebooks_abbr")) {
    $cols2 = mysqli_num_fields($result2);
    $rows2 = mysqli_num_rows($result2);
    $names2 = array();
    $finfo2 = mysqli_fetch_fields($result2);
    foreach ($finfo2 as $val) {
      $names2[] = $val->name;
    }

    $n = 0;
    while ($row1 = mysqli_fetch_assoc($result1)) {
      $row2 = mysqli_fetch_assoc($result2);
      $biblebooks[$n] = array();

      for ($x = 1; $x < $cols; $x++) {
        $temparray = array($row1[$names[$x]], $row2[$names[$x]]);

        $arr1 = explode(" | ", $row1[$names[$x]]);
        $booknames = array_map(function ($str) {
          return toProperCase(preg_replace("/\s+/", "", trim($str)));
        }, $arr1);

        $arr2 = explode(" | ", $row2[$names[$x]]);
        $abbrevs = (count($arr2) > 1) ? array_map(function ($str) {
          return toProperCase(preg_replace("/\s+/", "", trim($str)));
        }, $arr2) : array();

        $biblebooks[$n][$x] = array_merge($temparray, $booknames, $abbrevs);
      }
      $n++;
    }
  } else {
    addErrorMessage("<p>MySQL ERROR " . $mysqli->errno . ": " . $mysqli->error . "</p>", $returntype);
  }
} else {
  addErrorMessage("<p>MySQL ERROR " . $mysqli->errno . ": " . $mysqli->error . "</p>", $returntype);
}

// PREPARE VERSIONS ARRAY (AS REQUESTED IN QUERYSTRING)
$versions = array();
$temp = isset($BIBLEGET["version"]) && $BIBLEGET["version"] != "" ? explode(",", strtoupper($BIBLEGET["version"])) : array("CEI2008");

foreach ($temp as $version) {
  if (isset($BIBLEGET["forceversion"]) && $BIBLEGET["forceversion"] == "true") {
    $versions[] = $version;
  } else {
    if (in_array($version, $validversions)) {
      $versions[] = $version;
    } else {
      addErrorMessage("Not a valid version: <" . $version . ">, valid versions are <" . implode(" | ",$validversions) . ">", $returntype);
    }
  }
  if (isset($BIBLEGET["forcecopyright"]) && $BIBLEGET["forcecopyright"] == "true") {
    $copyrightversions[] = $version;
  }
}


if (count($versions) < 1) {
  outputResult($bbquery, $returntype);
}

//echo "<pre>";
//print_r($versions);
//echo "</pre>";

//for each version requested, prepare index arrays
$indexes = prepareIndexes($versions, $mysqli);
//echo "<pre>";
//print_r($indexes);
//echo "</pre>";

$sections = array( //TODO: why have hardcoded strings in Italian? I started making a table for this, should put this info in metadata queries and just return integer values on responses issued from the query endpoint
  "Pentateuco",
  "Storici",
  "Sapienziali",
  "Profeti",
  "Vangeli",
  "Atti degli Apostoli",
  "Lettere Paoline",
  "Lettere Cattoliche",
  "Apocalisse"
);


/*****************************************
 * ELABORATE THE BIBLE QUOTE QUERYSTRING *
 *****************************************/

if (isset($BIBLEGET["query"]) && $BIBLEGET["query"] != "") {

  // 1 -> CLEAN UP THE QUERY STRING OF ALL WHITESPACE AND RETURN PROPER CASED QUERIES IN EUROPEAN FORMAT
  $queries = queryStrClean($BIBLEGET["query"]);
  //   echo "<p>Cleaned queries: </p>";
  //   echo "<pre>";
  //   print_r($queries);
  //   echo "</pre>";

  // 2 -> CHECK VALIDITY OF QUERIES BY ENFORCING A SERIES OF RULES
  //at least the first query must start with a book reference, which may have a number from 1 to 3 at the beginning
  //echo "matching against: ".$queries[0]."<br />";
  if (!preg_match("/^[1-3]{0,1}\p{Lu}\p{Ll}*/u", $queries[0])) {
    if (!preg_match("/^[1-3]{0,1}(\p{L}\p{M}*)+/u", $queries[0])) {
      // error message: querystring must have book indication at the very start...
      addErrorMessage(0, $returntype);
      outputResult($bbquery, $returntype);
    }
  }
  //enforce rules on each query, return an array of the versions referred to in the queries 
  $resultsForCheckValid = checkValid($queries);
  $usedvariants = property_exists($resultsForCheckValid, 'usedvariants') ? $resultsForCheckValid->usedvariants : false;
  if (!is_array($usedvariants)) {
    outputResult($bbquery, $returntype);
  } else {
    //     echo "<pre>";
    //     print_r($usedvariants);
    //     echo "</pre>";
    // 3 -> TRANSLATE BIBLE NOTATION QUERIES TO MYSQL QUERIES
    //$temp = formulateQueries($queries);
    $temp = formulateQueries($resultsForCheckValid);
    $mysqlqueries = $temp[0];
    $queriesversions = $temp[1];
    $originalquery = $temp[2];
    //     echo "<pre>";
    //     print_r($mysqlqueries);
    //     echo "</pre>";

    // 5 -> DO MYSQL QUERIES AND COLLECT RESULTS IN OBJECT 
    doQueries($mysqlqueries, $queriesversions, $originalquery);


    // 6 -> OUTPUT RESULTS FORMATTED ACCORDING TO REQUESTED RETURN TYPE    
    outputResult($bbquery, $returntype);
  }
}

$mysqli->close();


/********************
 * USEFUL FUNCTIONS *
 *******************/

function toProperCase($txt)
{
  preg_match("/\p{L}/u", $txt, $mList, PREG_OFFSET_CAPTURE);
  if( array_key_exists( 0, $mList ) ){
    $idx = $mList[0][1];
    $chr = mb_substr($txt, $idx, 1, 'UTF-8');
    if (preg_match("/\p{L&}/u", $chr)) {
      $post = mb_substr($txt, $idx + 1, null, 'UTF-8');
      return mb_substr($txt, 0, $idx, 'UTF-8') . mb_strtoupper($chr, 'UTF-8') . mb_strtolower($post, 'UTF-8');
    } else {
      return $txt;
    }
  } else {
    return $txt;
  }
}


function idxOf($needle, $haystack)
{
  foreach ($haystack as $index => $value) {
    if (is_array($haystack[$index])) {
      foreach ($haystack[$index] as $index2 => $value2) {
        if (in_array($needle, $haystack[$index][$index2])) {
          return $index;
        }
      }
    } else if (in_array($needle, $haystack[$index])) {
      return $index;
    }
  }
  return false;
}

function addErrorMessage($num, $rettype, $str = "")
{

  global $err;
  global $bbquery;

  $errorMessages = array();
  $errorMessages[0] = "First query string must start with a valid book abbreviation!";
  $errorMessages[1] = "You must have a valid chapter following the book abbreviation!";
  $errorMessages[2] = "The book abbreviation is not a valid abbreviation. Please check the documentation for a list of correct abbreviations.";
  $errorMessages[3] = "You cannot use a dot without first using a comma. A dot is a liason between verses, which are separated from the chapter by a comma.";
  $errorMessages[4] = "A dot must be preceded and followed by 1 to 3 digits of which the first digit cannot be zero.";
  $errorMessages[5] = "A comma must be preceded and followed by 1 to 3 digits of which the first digit cannot be zero.";
  $errorMessages[6] = "A dash must be preceded and followed by 1 to 3 digits of which the first digit cannot be zero.";
  $errorMessages[7] = "If there is a chapter-verse construct following a dash, there must also be a chapter-verse construct preceding the same dash.";
  $errorMessages[8] = "There are multiple dashes in the query, but there are not enough dots. There can only be one more dash than dots.";
  $errorMessages[9] = "Notation Error. Please check your citation notation.";
  $errorMessages[10] = "Please use a cacheing mechanism, you seem to be submitting numerous requests for the same query.";
  $errorMessages[11] = "You are submitting too many requests with the same query. You must use a cacheing mechanism. Once you have implemented a cacheing mechanism you may have to wait a couple of days before getting service again. Otherwise contact the service management to request service again.";
  $errorMessages[12] = "You are submitting a very large amount of requests to the endpoint. Please slow down. If you believe there has been an error you may contact the service management.";

  if (gettype($num) == "string") {
    $errorMessages[13] = $num;
    $num = 13;
  }

  if ($rettype == "xml") {
    $err_row = $bbquery->errors->addChild("error", $errorMessages[$num]);
    $err_row->addAttribute("errNum", $num);
  } elseif ($rettype == "json") {
    $error = array();
    $error["errNum"] = $num;
    $error["errMessage"] = $errorMessages[$num] . ($str != "" ? " > " . $str : "");
    $bbquery->errors[] = $error;
  } elseif ($rettype == "html") {

    $elements = array();
    //$attributes = array();

    //$bbquery->validateOnParse = true;
    $errorsTable = $bbquery->getElementById("errorsTbl");
    if ($errorsTable == null) {
      $elements[0] = $bbquery->createElement("table");
      $elements[0]->setAttribute("id", "errorsTbl");
      $elements[0]->setAttribute("class", "errorsTbl");
      $err->appendChild($elements[0]);
    } else {
      $elements[0] = $errorsTable;
    }

    $elements[1] = $bbquery->createElement("tr");
    $elements[1]->setAttribute("class", "errorsRow");
    $elements[0]->appendChild($elements[1]);

    $elements[2] = $bbquery->createElement("td", "errNum");
    $elements[2]->setAttribute("class", "errNum");
    $elements[1]->appendChild($elements[2]);

    $elements[3] = $bbquery->createElement("td", $num);
    $elements[3]->setAttribute("class", "errNumVal");
    $elements[1]->appendChild($elements[3]);

    $elements[4] = $bbquery->createElement("td", "errMessage");
    $elements[4]->setAttribute("class", "errMessage");
    $elements[1]->appendChild($elements[4]);

    $elements[5] = $bbquery->createElement("td", $errorMessages[$num]);
    $elements[5]->setAttribute("class", "errMessageVal");
    $elements[1]->appendChild($elements[5]);
  }
}

/*********************************************************************************
 * INITIALIZE THE OBJECT THAT WILL COLLECT THE RESULTS FROM THE MYSQL QUERIES,   *
 * BASED ON THE RETURN TYPE                                                      *
 ********************************************************************************/

function biblequeryInit($rettype)
{
  $err = NULL;
  $div = NULL;
  $inf = NULL;
  switch ($rettype) {
    case "xml":
      $root = "<?xml version=\"1.0\" encoding=\"UTF-8\"?" . "><BibleQuote/>";
      $biblequery = new simpleXMLElement($root);
      $biblequery->addChild("results");
      $biblequery->addChild("errors");
      $info = $biblequery->addChild("info");
      $info->addAttribute("ENDPOINT_VERSION", ENDPOINT_VERSION);
      break;
    case "json":
      $biblequery = new stdClass();
      $biblequery->results = array();
      $biblequery->errors = array();
      $biblequery->info = array("ENDPOINT_VERSION" => ENDPOINT_VERSION);
      break;
    case "html":
      $biblequery = new DOMDocument();
      $html = "<!DOCTYPE HTML><head><title>BibleGet Query Result</title><style>table#errorsTbl { border: 3px double Red; background-color:DarkGray; } table#errorsTbl td { border: 1px solid Black; background-color:LightGray; padding: 3px; } td.errNum,td.errMessage { font-weight:bold; }</style><!-- QUERY.BIBLEGET.IO ENDPOINT VERSION {ENDPOINT_VERSION} --></head><body></body>";
      $biblequery->loadHTML($html);
      $div = $biblequery->createElement("div");
      $div->setAttribute("class", "results bibleQuote");
      $err = $biblequery->createElement("div");
      $err->setAttribute("class", "errors bibleQuote");
      $inf = $biblequery->createElement("div");
      $inf->setAttribute("class", "info bibleQuote");
  }
  return array($biblequery, $div, $err, $inf);
}

function dbConnect()
{
  global $bbquery;
  global $returntype;
	
  $dbCredentials = "dbcredentials.php";
  //search for the database credentials file at least three levels up...
  if(file_exists($dbCredentials)){
    include $dbCredentials;
  } else if (file_exists("../" . $dbCredentials)){
    include "../{$dbCredentials}";
  } else if (file_exists("../../" . $dbCredentials)){
    include "../../{$dbCredentials}";
  }

  $mysqli = new mysqli(SERVER, DBUSER, DBPASS, DATABASE);

  if ($mysqli->connect_errno) {
    addErrorMessage("Failed to connect to MySQL: (" . $mysqli->connect_errno . ") " . $mysqli->connect_error, $returntype);
    outputResult($bbquery, $returntype);
  }
  $mysqli->set_charset("utf8");
  /*if (!$mysqli->set_charset("utf8")) {
    //printf("Error loading character set utf8: %s\n", $mysqli->error);
  } else {
    //printf("Current character set: %s\n", $mysqli->character_set_name());
  }*/
  return $mysqli;
}

function prepareIndexes($versions, $mysqli)
{

  $indexes = array();

  foreach ($versions as $variant) {
    $abbreviations = array();
    $bbbooks = array();
    $chapter_limit = array();
    $verse_limit = array();
    $book_num = array();

    // fetch the index information for the requested version from the database and load it into our arrays
    if ($result = $mysqli->query("SELECT * FROM " . $variant . "_idx")) {
      while ($row = $result->fetch_assoc()) {
        $abbreviations[] = $row["abbrev"];
        $bbbooks[] = $row["fullname"];
        $chapter_limit[] = $row["chapters"];
        $verse_limit[] = explode(",", $row["verses_last"]);
        $book_num[] = $row["book"];
      }
    } /*else {
      //error
    }*/

    $indexes[$variant]["abbreviations"] = $abbreviations;
    $indexes[$variant]["biblebooks"] = $bbbooks;
    $indexes[$variant]["chapter_limit"] = $chapter_limit;
    $indexes[$variant]["verse_limit"] = $verse_limit;
    $indexes[$variant]["book_num"] = $book_num;
  }

  return $indexes;
}

function queryStrClean($gtquery)
{
  global $returntype;
  global $bbquery;
  global $detectedNotation;
  // remove all whitespace from the query
  $querystr = preg_replace('/\s+/', '', $gtquery);
  // trim shouldn't be necessary now but just in case
  $querystr = trim($querystr);
  // shouldn't have any spaces left but just in case
  $querystr = str_replace(' ', '', $querystr);
  // convert en-dashes, em-dashes, minus signs and any other kind of dashes to simple hyphens
  $querystr = preg_replace('/[\x{2011}-\x{2015}|\x{2212}|\x{23AF}]/u', '-', $querystr);

  //if query is written in english notation, convert it to european notation
  $find = array(".", ",", ":");
  $replace = array("", ".", ",");
  //TODO: we need to detect mixed notations even when there are no dots, for example "Mt 5:1;6,2"
  //in other words check what symbol we have immediately after [book &] chapter
  if (strpos($querystr, ":") && strpos($querystr, ".")) {
    //can't use both notations
    addErrorMessage("Mixed notations have been detected, please use either english or european notation.", $returntype);
    outputResult($bbquery, $returntype);
  } else if (strpos($querystr, ":")) {
    $detectedNotation = "ENGLISH";
    $querystr = str_replace($find, $replace, $querystr);
  } else {
    $detectedNotation = "EUROPEAN";
  }

  //if there are multiple queries separated by semicolons, we explode them into an array
  $queries = explode(";", $querystr);
  //get rid of empty queries
  $queries = array_values(array_filter($queries, function ($var) {
    return $var !== "";
  }));

  //make all queries proper case (for correct detection of bible books and abbreviations)
  for ($n = 0; $n < count($queries); $n++) {
    //echo "query before propercasing = ".$queries[$n]."<br />";
    $queries[$n] = toProperCase($queries[$n]);
    //echo "same query after propercasing = ".$queries[$n]."<br />";
  }
  return $queries;
}

function checkValid( $queries ) {
  global $err;
  global $bbquery;
  global $biblebooks;
  global $mysqli;
  global $versions;
  global $indexes;
  global $returntype;

  $resultsForCheckValid = new stdClass();
  $resultsForCheckValid->usedvariants = array();
  $resultsForCheckValid->goodqueries = array();

  //$usedvariants = array();
  $usedvariant = "";

  $thisbook = "";
  $idx = -1;
  foreach ($queries as $query) {
    $fullquery = $query;
    //echo "<p>Now checking validity of query: ".$query."</p>";
    //if(preg_match("/^[1-3]{0,1}[A-Z][a-z]+/u",$query,$res1) != preg_match("/^[1-3]{0,1}[A-Z][a-z]+[1-9][0-9]{0,2}/u",$query,$res2)){
    if (preg_match("/\p{L&}/u", $query)) {
      //echo "<p>We are dealing with a string that has upper/lower case variants.</p>";
      if (preg_match("/^[1-3]{0,1}\p{Lu}\p{Ll}*/u", $query, $res1) != preg_match("/^[1-3]{0,1}\p{Lu}\p{Ll}*[1-9][0-9]{0,2}/u", $query, $res2)) {
        // error message: every book indication must be followed by a valid chapter indication
        addErrorMessage(1, $returntype);
        // Up the bad counter
        $mysqli->query("UPDATE counter SET bad = bad + 1");
        return false;
      }

      if (preg_match("/^([1-3]{0,1}((\p{Lu}\p{Ll}*)+))/u", $query, $res)) {
        $validbookflag = false;
        $thisbook = $res[0];
        foreach ($versions as $variant) {
          //echo "<p>Looping through requested versions: ".$variant."</p>";
          if (in_array($res[0], $indexes[$variant]["biblebooks"]) || in_array($res[0], $indexes[$variant]["abbreviations"])) {
            //echo "<p>Book name ".$res[0]." was found in the indexes of the requested version \"".$variant."\".</p>";
            $validbookflag = true;
            $usedvariant = $variant;
            //we can still use the index for further integrity checks!
            $idx = idxOf($res[0], $biblebooks);
            break;
          } else {
            $idx = idxOf($res[0], $biblebooks);
            if ($idx !== FALSE) {
              //echo "<p>Book name ".$res[0]." was recognized as a valid book name, even if not in the indexes of the requested version \"".$variant."\"</p>";
              $validbookflag = true;
            }
          }
        }

        if (!$validbookflag) {
          //echo "<p>Book name ".$res[0]." was not recognized as a valid book name.</p>";
          //echo "<pre>";
          //print_r($biblebooks);
          //echo "</pre>";
          // error message: unrecognized book abbreviation
          addErrorMessage(sprintf('The book abbreviation %s is not a valid abbreviation. Please check the documentation for a list of correct abbreviations.', $thisbook), $returntype);
          // Up the bad counter
          $mysqli->query("UPDATE counter SET bad = bad + 1");
          //return false;
          continue;
        } else {
          $query = str_replace($thisbook, "", $query);
        }
      } /*else {
        
        $var = print_r($res, true);
        addErrorMessage($var,$returntype);
        outputResult();
        
      }*/
    } else {
      //echo "<p>We are dealing with a string that does not have upper / lower case variants.</p>";
      if (preg_match("/^[1-3]{0,1}(\p{L}\p{M}*)+/u", $query, $res1) != preg_match("/^[1-3]{0,1}(\p{L}\p{M}*)+[1-9][0-9]{0,2}/u", $query, $res2)) {
        // error message: every book indication must be followed by a valid chapter indication
        addErrorMessage(1, $returntype);
        // Up the bad counter
        $mysqli->query("UPDATE counter SET bad = bad + 1");
        return false;
      }
      if (preg_match("/^([1-3]{0,1}((\p{L}\p{M}*)+))/u", $query, $res)) {
        //echo "<p>We have matched the bookname: ".$res[0]."</p>";
        $thisbook = $res[0];
        $validbookflag = false;
        foreach ($versions as $variant) {
          if (in_array($res[0], $indexes[$variant]["biblebooks"]) || in_array($res[0], $indexes[$variant]["abbreviations"])) {
            $validbookflag = true;
            $usedvariant = $variant;
            //we can still use the index for further integrity checks!
            $idx = idxOf($res[0], $biblebooks);
            break;
          } else if (($idx = idxOf($res[0], $biblebooks)) !== FALSE) {
            //echo "<p>Book was recognized as valid.</p>";
            $validbookflag = true;
          }
        }
        if (!$validbookflag) {
          //echo "<p>ALARM!!! We are getting an invalid book flag.</p>";
          // error message: unrecognized book abbreviation
          addErrorMessage(sprintf('The book abbreviation %s is not a valid abbreviation. Please check the documentation for a list of correct abbreviations.', $thisbook), $returntype);
          // Up the bad counter
          $mysqli->query("UPDATE counter SET bad = bad + 1");
          continue;
        } else {
          $query = str_replace($thisbook, "", $query);
        }
      }
    }

    if (strpos($query, ".")) {
      if (!strpos($query, ",") || strpos($query, ",") > strpos($query, ".")) {
        // error message: You cannot use a dot without first using a comma. A dot is a liason between verses, which are separated from the chapter by a comma.
        addErrorMessage(3, $returntype);
        // Up the bad counter
        $mysqli->query("UPDATE counter SET bad = bad + 1");
        continue;
        //return false;
      }
      //if(preg_match_all("/(?=[1-9][0-9]{0,2}\.[1-9][0-9]{0,2})/",$query) != substr_count($query,".") ){
      //if(preg_match_all("/(?=([1-9][0-9]{0,2}\.[1-9][0-9]{0,2}))/",$query) < substr_count($query,".") ){
      if (preg_match_all("/(?<![0-9])(?=([1-9][0-9]{0,2}\.[1-9][0-9]{0,2}))/", $query) != substr_count($query, ".")) {
        // error message: A dot must be preceded and followed by 1 to 3 digits etc.
        addErrorMessage(4, $returntype);
        // Up the bad counter
        $mysqli->query("UPDATE counter SET bad = bad + 1");
        continue;
        //return false;
      }
    }


    if (strpos($query, ",")) {
      if (preg_match_all("/[1-9][0-9]{0,2}\,[1-9][0-9]{0,2}/", $query) != substr_count($query, ",")) {
        // error message: A comma must be preceded and followed by 1 to 3 digits etc.
        //echo "There are ".preg_match_all("/(?=[1-9][0-9]{0,2}\,[1-9][0-9]{0,2})/",$query)." matches for commas preceded and followed by valid 1-3 digit sequences;<br>";
        //echo "There are ".substr_count($query,",")." matches for commas in this query.";
        addErrorMessage(5, $returntype);
        // Up the bad counter
        $mysqli->query("UPDATE counter SET bad = bad + 1");
        continue;
        //return false;
      } else {
        if (preg_match_all("/([1-9][0-9]{0,2})\,/", $query, $matches)) {
          if (!is_array($matches[1])) {
            $matches[1] = array(
              $matches[1]
            );
          }
          $myidx = $idx + 1;
          foreach ($matches[1] as $match) {
            foreach ($indexes as $jkey => $jindex) {
              // bibleGetWriteLog("jindex array contains:");
              // bibleGetWriteLog($jindex);
              $bookidx = array_search($myidx, $jindex["book_num"]);
              // bibleGetWriteLog("bookidx for ".$jkey." = ".$bookidx);
              $chapter_limit = $jindex["chapter_limit"][$bookidx];
              // bibleGetWriteLog("chapter_limit for ".$jkey." = ".$chapter_limit);
              // bibleGetWriteLog( "match for " . $jkey . " = " . $match );
              if ($match > $chapter_limit) {
                //addErrorMessage('$myidx = '.$myidx,$returntype);
                //addErrorMessage('$bookidx = '.$bookidx,$returntype);
                /* translators: the expressions <%1$d>, <%2$s>, <%3$s>, and <%4$d> must be left as is, they will be substituted dynamically by values in the script. See http://php.net/sprintf. */
                $msg = 'A chapter in the query is out of bounds: there is no chapter <%1$d> in the book %2$s in the requested version %3$s, the last possible chapter is <%4$d>';
                addErrorMessage(sprintf($msg, $match, $thisbook, $jkey, $chapter_limit), $returntype);
                $mysqli->query("UPDATE counter SET bad = bad + 1");
                continue 3;
              }
            }
          }

          $commacount = substr_count($query, ",");
          if ($commacount > 1) {
            if (!strpos($query, '-')) {
              addErrorMessage("You cannot have more than one comma and not have a dash!", $returntype);
              $mysqli->query("UPDATE counter SET bad = bad + 1");
              continue;
              //return false;
            }
            $parts = explode("-", $query);
            if (count($parts) != 2) {
              addErrorMessage("You seem to have a malformed querystring, there should be only one dash.", $returntype);
              $mysqli->query("UPDATE counter SET bad = bad + 1");
              continue;
              //return false;
            }
            foreach ($parts as $part) {
              $pp = array_map("intval", explode(",", $part));
              foreach ($indexes as $jkey => $jindex) {
                $bookidx = array_search($myidx, $jindex["book_num"]);
                $chapters_verselimit = $jindex["verse_limit"][$bookidx];
                $verselimit = intval($chapters_verselimit[$pp[0] - 1]);
                if ($pp[1] > $verselimit) {
                  $msg = 'A verse in the query is out of bounds: there is no verse <%1$d> in the book %2$s at chapter <%3$d> in the requested version %4$s, the last possible verse is <%5$d>';
                  addErrorMessage(sprintf($msg, $pp[1], $thisbook, $pp[0], $jkey, $verselimit), $returntype);
                  $mysqli->query("UPDATE counter SET bad = bad + 1");
                  continue 3;
                  //return false;
                }
              }
            }
          } elseif ($commacount == 1) {
            // bibleGetWriteLog("commacount has been detected as 1, now exploding on comma the query[".$thisquery."]");
            $parts = explode(",", $query);
            // bibleGetWriteLog($parts);
            // bibleGetWriteLog("checking for presence of dashes in the right-side of the comma...");
            if (strpos($parts[1], '-')) {
              // bibleGetWriteLog("a dash has been detected in the right-side of the comma(".$parts[1].")");
              if (preg_match_all("/[,\.][1-9][0-9]{0,2}\-([1-9][0-9]{0,2})/", $query, $matches)) {
                if (!is_array($matches[1])) {
                  $matches[1] = array(
                    $matches[1]
                  );
                }
                $highverse = intval(array_pop($matches[1]));
                // bibleGetWriteLog("highverse = ".$highverse);
                foreach ($indexes as $jkey => $jindex) {
                  $bookidx = array_search($myidx, $jindex["book_num"]);
                  $chapters_verselimit = $jindex["verse_limit"][$bookidx];
                  $verselimit = intval($chapters_verselimit[intval($parts[0]) - 1]);
                  // bibleGetWriteLog("verselimit for ".$jkey." = ".$verselimit);
                  if ($highverse > $verselimit) {
                    /* translators: the expressions <%1$d>, <%2$s>, <%3$d>, <%4$s> and %5$d must be left as is, they will be substituted dynamically by values in the script. See http://php.net/sprintf. */
                    $msg = 'A verse in the query is out of bounds: there is no verse <%1$d> in the book %2$s at chapter <%3$d> in the requested version %4$s, the last possible verse is <%5$d>';
                    addErrorMessage(sprintf($msg, $highverse, $thisbook, $parts[0], $jkey, $verselimit), $returntype);
                    $mysqli->query("UPDATE counter SET bad = bad + 1");
                    continue 2;
                    //return false;
                  }
                }
              } /*else {
                // bibleGetWriteLog("something is up with the regex check...");
              }*/
            } else {
              if (preg_match("/,([1-9][0-9]{0,2})/", $query, $matches)) {
                $highverse = intval($matches[1]);
                foreach ($indexes as $jkey => $jindex) {
                  $bookidx = array_search($myidx, $jindex["book_num"]);
                  $chapters_verselimit = $jindex["verse_limit"][$bookidx];
                  $verselimit = intval($chapters_verselimit[intval($parts[0]) - 1]);
                  if ($highverse > $verselimit) {
                    /* translators: the expressions <%1$d>, <%2$s>, <%3$d>, <%4$s> and %5$d must be left as is, they will be substituted dynamically by values in the script. See http://php.net/sprintf. */
                    $msg = 'A verse in the query is out of bounds: there is no verse <%1$d> in the book %2$s at chapter <%3$d> in the requested version %4$s, the last possible verse is <%5$d>';
                    addErrorMessage(sprintf($msg, $highverse, $thisbook, $parts[0], $jkey, $verselimit), $returntype);
                    $mysqli->query("UPDATE counter SET bad = bad + 1");
                    continue 2;
                    //return false;
                  }
                }
              }
            }

            if (preg_match_all("/\.([1-9][0-9]{0,2})$/", $query, $matches)) {
              if (!is_array($matches[1])) {
                $matches[1] = array(
                  $matches[1]
                );
              }
              $highverse = array_pop($matches[1]);
              foreach ($indexes as $jkey => $jindex) {
                $bookidx = array_search($myidx, $jindex["book_num"]);
                $chapters_verselimit = $jindex["verse_limit"][$bookidx];
                $verselimit = intval($chapters_verselimit[intval($parts[0]) - 1]);
                if ($highverse > $verselimit) {
                  /* translators: the expressions <%1$d>, <%2$s>, <%3$d>, <%4$s> and %5$d must be left as is, they will be substituted dynamically by values in the script. See http://php.net/sprintf. */
                  $msg = 'A verse in the query is out of bounds: there is no verse <%1$d> in the book %2$s at chapter <%3$d> in the requested version %4$s, the last possible verse is <%5$d>';
                  addErrorMessage(sprintf($msg, $highverse, $thisbook, $parts[0], $jkey, $verselimit), $returntype);
                  $mysqli->query("UPDATE counter SET bad = bad + 1");
                  continue 2;
                  //return false;
                }
              }
            }
          }
        }
      }
    } else {
      $chapters = explode("-", $query);
      foreach ($chapters as $zchapter) {
        foreach ($indexes as $jkey => $jindex) {
          $myidx = $idx + 1;
          $bookidx = array_search($myidx, $jindex["book_num"]);
          $chapter_limit = $jindex["chapter_limit"][$bookidx];
          //addErrorMessage('$myidx = '.$myidx,$returntype);
          //addErrorMessage('$bookidx = '.$bookidx,$returntype);
          //addErrorMessage('$chapter_limit = '.$chapter_limit,$returntype);
          //addErrorMessage('$zchapter = '.$zchapter,$returntype);
          //addErrorMessage('$thisbook = '.$thisbook,$returntype);
          if (intval($zchapter) > $chapter_limit) {
            $msg = 'A chapter in the query is out of bounds: there is no chapter <%1$d> in the book %2$s in the requested version %3$s, the last possible chapter is <%4$d>';
            addErrorMessage(sprintf($msg, $zchapter, $thisbook, $jkey, $chapter_limit), $returntype);
            $mysqli->query("UPDATE counter SET bad = bad + 1");
            continue 3;
          }
        }
      }
    }

    if (strpos($query, "-")) {
      if (preg_match_all("/[1-9][0-9]{0,2}\-[1-9][0-9]{0,2}/", $query) != substr_count($query, "-")) {
        // error message: A dash must be preceded and followed by 1 to 3 digits etc.
        //echo "There are ".preg_match("/(?=[1-9][0-9]{0,2}\-[1-9][0-9]{0,2})/",$query)." matches for dashes preceded and followed by valid 1-3 digit sequences;<br>";
        //echo "There are ".substr_count($query,"-")." matches for dashes in this query.";
        addErrorMessage(6, $returntype);
        // Up the bad counter
        $mysqli->query("UPDATE counter SET bad = bad + 1");
        continue;
        //return false;
      }
      if (preg_match("/\-[1-9][0-9]{0,2}\,/", $query) && (!preg_match("/\,[1-9][0-9]{0,2}\-/", $query) || preg_match_all("/(?=\,[1-9][0-9]{0,2}\-)/", $query) > preg_match_all("/(?=\-[1-9][0-9]{0,2}\,)/", $query))) {
        // error message: there must be as many comma constructs preceding dashes as there are following dashes
        addErrorMessage(7, $returntype);
        // Up the bad counter
        $mysqli->query("UPDATE counter SET bad = bad + 1");
        continue;
        //return false;
      }
      if (substr_count($query, "-") > 1 && (!strpos($query, ".") || (substr_count($query, "-") - 1 > substr_count($query, ".")))) {
        // error message: there cannot be multiple dashes in a query if there are not as many dots minus 1.
        // Up the bad counter
        $mysqli->query("UPDATE counter SET bad = bad + 1");
        addErrorMessage(8, $returntype);
        continue;
        //return false;
      }
    }
    $resultsForCheckValid->usedvariants[] = $usedvariant;
    $resultsForCheckValid->goodqueries[] = $fullquery;
    //$usedvariants[] = $usedvariant;

  } //END FOREACH

  //return $usedvariants;
  return $resultsForCheckValid;
}

function formulateQueries($checkedResults)
{
  global $biblebooks;
  global $copyrightversions;
  global $versions;
  global $indexes;
  global $BIBLEGET;
  global $CATHOLIC_VERSIONS;
  //global $PROTESTANT_VERSIONS;
  $queries        = $checkedResults->goodqueries;
  $usedvariants   = $checkedResults->usedvariants;
  $sqlqueries = array();
  $queriesversions = array();
  $originalquery = array();
  $nn = 0;
  $sqlquery = "";
  $book = "";
  $usedvariant = "";

  foreach ($versions as $version) {
    $i = 0;
    foreach ($queries as $query) {
      $origquery = $query;
      $book1 = "";
      // Retrieve and store the book in the query string,if applicable
      if (preg_match("/\p{L&}/u", $query)) {
        if (preg_match("/^[1-4]{0,1}\p{Lu}\p{Ll}*/u", $query, $ret)) {
          $usedvariant = $usedvariants[$i];
          // Now that we have captured our book, we can erase it from the query string
          $query = preg_replace("/^[1-4]{0,1}\p{Lu}\p{Ll}*/u", "", $query);
          if ($usedvariant != "" && ($key = array_search($ret[0], $indexes[$usedvariant]["biblebooks"])) !== FALSE) {
            $book1 = $book = $indexes[$usedvariant]["book_num"][$key];
          } else if ($usedvariant != "" && ($key = array_search($ret[0], $indexes[$usedvariant]["abbreviations"])) !== FALSE) {
            $book1 = $book = $indexes[$usedvariant]["book_num"][$key];
          } else if (($key = idxOf($ret[0], $biblebooks)) !== FALSE) {
            $book1 = $book = $key + 1;
          }
        } else {
          $book1 = $book;
        }
      } else {
        if (preg_match("/^[1-4]{0,1}\p{L}+/u", $query, $ret)) {
          $usedvariant = $usedvariants[$i];
          // Now that we have captured our book, we can erase it from the query string
          $query = preg_replace("/^[1-4]{0,1}\p{L}+/u", "", $query);
          if ($usedvariant != "" && ($key = array_search($ret[0], $indexes[$usedvariant]["biblebooks"])) !== FALSE) {
            $book1 = $book = $indexes[$usedvariant]["book_num"][$key];
          } else if ($usedvariant != "" && ($key = array_search($ret[0], $indexes[$usedvariant]["abbreviations"])) !== FALSE) {
            $book1 = $book = $indexes[$usedvariant]["book_num"][$key];
          } else if (($key = idxOf($ret[0], $biblebooks)) !== FALSE) {
            $book1 = $book = $key + 1;
          }
        } else {
          $book1 = $book;
        }
      }

      $sqlquery = "SELECT * FROM " . $version . " WHERE book = " . $book1;

      $preferorigin = "";
      //if we are dealing with a book that has greek and hebrew variants, we need to distinguish between the two
      if($book1 == 19 || $book1 == "19"){ 
        //if a protestant version is requested, it will only have HEBREW origin, not GREEK
        //if preferorigin is not explicitly set, but chapters 11-20 are requested for Esther,
        //then obviously the HEBREW version is being preferred, we will need to translate this request when we know which chapter we are dealing with
        if(in_array($version,$CATHOLIC_VERSIONS)){
          $preferorigin = " AND verseorigin = '" . ( $BIBLEGET['preferorigin'] != "" ? $BIBLEGET['preferorigin'] : "GREEK" ) . "'";
        }

      }

      $xchapter = "";
      //NOTE: all notations have been translated to EUROPEAN notation for the following calculations
      //      1) Therefore we will not find colons, but commas for the chapter-verse separator
      //      2) We will not find commas for non consecutive verses, but dots
      //NOTE: since a dash can have multiple meanings, whereas a dot has one meaning, 
      //      also considering that the dot has to do with the smallest unit in a Bible reference = verse,
      //      whereas a dash could have to do either with verses or with chapters
      //      we start our interpretation of the Bible reference from what is clear and certain
      //      and is the smallest possible unit that we have to work with.
      //      So if there is a dot = non-consecutive verses, we start splitting up the reference around the dot (or dots)
      //NOTE: We have already capture the book, so we are not dealing with the book in the following calculations
      //      However for our own sanity, the book is included in the examples to help understand what is happening
      //      This symbol will be used to indicate splitting into left hand and right hand sections: <=|=>
      
      //IF: non-consecutive verses are requested (EXAMPLE: John 3,16.18)
      if (strpos($query, ".")) {
        $querysplit = preg_split("/\./", $query);
        //FOREACH non-consecutive chunk requested (John 3,16 <=|=> 18)
        foreach ($querysplit as $piece) {
          $originalquery[$nn] = $origquery;
          //IF the chunk is not simply a single verse, but is a range of consecutive VERSES or CHAPTERS 
          //    (EXAMPLE: John 3,16-18.20       OR John 3,16.18-20        OR John 3,16-18.20-22)
          //    (         John 3,16-18 <=|=> 20 OR John 3,16 <=|=> 18-20  OR John 3,16-18 <=|=> 20-22 )
          if (strpos($piece, "-")) {
            $fromto = preg_split("/\-/", $piece);
            //IF we have a CHAPTER indicator on the left hand side of the range of consecutive verses
            //  (EXAMPLE: John 3,16-18.20)
            //  ( John 3,16-18 <=|=> 20 )
            //  ( John 3 <=> 16-18 )
            if (strpos($fromto[0], ",")) {
              //THEN we capture the CHAPTER from the left hand side and the range of consecutive VERSEs from the right hand side
              $chapterverse = preg_split("/,/", $fromto[0]);
              $xchapter = $chapterverse[0];
              $mappedReference = mapReference($version,$book1,$chapterverse[0],$chapterverse[1],$preferorigin);
              $chapterverse[0] = $mappedReference[0];
              $chapterverse[1] = $mappedReference[1];
              $preferorigin = $mappedReference[2];
              //IF we have a CHAPTER indicator on the right hand side of the range of consecutive verses
              //  (EXAMPLE: John 3,16-4,5.8 )
              //  ( John 3,16 <=|=> 4,5 )
              if (strpos($fromto[1], ",")) {
                //THEN we capture the CHAPTER from the left hand side and the range of consecutive VERSEs from the right hand side
                $chapterverse1 = preg_split("/,/", $fromto[1]);
                $xchapter = $chapterverse1[0];
                $mappedReference = mapReference($version,$book1,$chapterverse1[0],$chapterverse1[1],$preferorigin);
                $chapterverse1[0] = $mappedReference[0];
                $chapterverse1[1] = $mappedReference[1];
                $preferorigin = $mappedReference[2];
                $sqlqueries[$nn] = $sqlquery . " AND ((chapter = " . $chapterverse[0] . " AND verse >= " . $chapterverse[1] . ")";
                if($chapterverse1[0] - $chapterverse[0] > 1) {
                  for($d=1;$d<($chapterverse1[0] - $chapterverse[0]);$d++){
                    $sqlqueries[$nn] .= " OR (chapter = " . ($chapterverse[0] + $d) . ")";
                  }
                }
                $sqlqueries[$nn] .= " OR (chapter = " . $chapterverse1[0] . " AND verse <= " . $chapterverse1[1] . "))";
              } 
              //ELSEIF we do NOT have a CHAPTER indicator on the right hand side of the range of consecutive verses
              // (EXAMPLE: John 3,16-18.20)
              else {
                $mappedReference = mapReference($version,$book1,$xchapter,$fromto[1],$preferorigin);
                $xchapter = $mappedReference[0];
                $fromto[1] = $mappedReference[1];
                $preferorigin = $mappedReference[2];
                $sqlqueries[$nn] = $sqlquery . " AND (chapter >= " . $chapterverse[0] . " AND verse >= " . $chapterverse[1] . ")";
                $sqlqueries[$nn] .= " AND (chapter <= " . $xchapter . " AND verse <= " . $fromto[1] . ")";
              }
            } 
            //ELSEIF we DO NOT have a CHAPTER indicator on the left hand side of the range of consecutive verses
            //  ( EXAMPLE: John 3,16.18-20 )
            //  (  John 3,16 <=|=> 18-20 )
            //  (  18 <=> 20)
            else {
              $mappedReference = mapReference($version,$book1,$xchapter,$fromto[0],$preferorigin);
              $mappedReference1 = mapReference($version,$book1,$xchapter,$fromto[1],$preferorigin);
              $xchapter = $mappedReference[0];
              $fromto[0] = $mappedReference[1];
              $preferorigin = $mappedReference[2];
              $fromto[1] = $mappedReference1[1];
              $sqlqueries[$nn] = $sqlquery . " AND (chapter = " . $xchapter . " AND verse >= " . $fromto[0] . " AND verse <= " . $fromto[1] . ")";
            }
          } 
          //ELSEIF the non consecutive chunk DOES NOT contain a range of consecutive VERSEs / CHAPTERs
          //    ( EXAMPLE: John 3,16.18 )
          //    (   John 3,16 <=|=> 18 )
          else {
            //IF the non consecutive chunk however DOES contain a chapter reference
            //  ( EXAMPLE: John 3,16.18 )
            //  (   John 3,16 <=|=> 18 )
            //  (   John 3 <=> 16 )
            if (strpos($piece, ",")) {
                $chapterverse = preg_split("/,/", $piece);
                $xchapter = $chapterverse[0];
                $mappedReference = mapReference($version,$book1,$chapterverse[0],$chapterverse[1],$preferorigin);
                $chapterverse[0] = $mappedReference[0];
                $chapterverse[1] = $mappedReference[1];
                $preferorigin = $mappedReference[2];
                $sqlqueries[$nn] = $sqlquery . " AND (chapter = " . $chapterverse[0] . " AND verse = " . $chapterverse[1] . ")";
            } 
            //ELSEIF the non consecutive chunk DOES NOT contain a chapter reference
            //  ( EXAMPLE: John 3,16.18 )
            //  (    John 3,16 <=|=> 18 )
            //  ( 18 )
            else {
                $mappedReference = mapReference($version,$book1,$xchapter,$piece,$preferorigin);
                $xchapter = $mappedReference[0];
                $piece = $mappedReference[1];
                $preferorigin = $mappedReference[2];
                $sqlqueries[$nn] = $sqlquery . " AND (chapter = " . $xchapter . " AND verse = " . $piece . ")";
            }
          }

          $sqlqueries[$nn] .= $preferorigin;

          $queriesversions[$nn] = $version;
          //VERSES must be ordered by verseID in order to handle those cases where there are subverses (usually Greek additions)
          //In these cases, the subverses sometimes come before, sometimes come after the "main" verse
          //Ex. Esther 1,1a-1r precedes Esther 1,1 but Esther 3,13 precedes Esther 3,13a-13g
          //Being this the case, it would not be possible to have coherent ordering by book,chapter,verse,verseequiv
          //The only solution is to make sure the verses are ordered correctly in the table with a unique verseID
          $sqlqueries[$nn] .= " ORDER BY verseID";
          //$sqlqueries[$nn] .= " ORDER BY book,chapter,verse,verseequiv";
          if (in_array($version, $copyrightversions)) {
            $sqlqueries[$nn] .= " LIMIT 30";
          }
          $nn++;
        }
      } 
      //ELSEIF the request DOES NOT contain non-consecutive verses
      //    ( EXAMPLE: John 3,16 )
      else {
        //$nn++;
        //IF the request DOES however contain a range of consecutive verses or chapters
        //    ( EXAMPLE: John 3,16-18 )
        //    (    John 3,16 <=|=> 18 )
        if (strpos($query, "-")) {
          $originalquery[$nn] = $origquery;
          $fromto = preg_split("/\-/", $query);
          //IF there is a chapter indicator on the left hand side of the range of consecutive verses
          //    ( EXAMPLE: John 3,16-18 )
          //    (    John 3,16 <=|=> 18 )
          //    (    John 3,16 )
          if (strpos($fromto[0], ",")) {
            //echo "We have a comma in this section of query! ". $fromto[0] . "<br />";
            $chapterverse = preg_split("/,/", $fromto[0]);
            $xchapter = $chapterverse[0];
            $mappedReference = mapReference($version,$book1,$chapterverse[0],$chapterverse[1],$preferorigin);
            $chapterverse[0] = $mappedReference[0];
            $chapterverse[1] = $mappedReference[1];
            $preferorigin = $mappedReference[2];
            //IF there is also a chapter indicator on the right hand side of the range of consecutive verses
            //    ( EXAMPLE: John 3,16-4,5 )
            //    (    John 3,16 <=|=> 4,5 )
            //    (    4,5 )
            if (strpos($fromto[1], ",")) {
                $chapterverse1 = preg_split("/,/", $fromto[1]);
                $mappedReference = mapReference($version,$book1,$chapterverse1[0],$chapterverse1[1],$preferorigin);
                $chapterverse1[0] = $mappedReference[0];
                $chapterverse1[1] = $mappedReference[1];
                $preferorigin = $mappedReference[2];
                //what if the difference between chapters is greater than 1? Say: John 3,16-6,2 ?
                $sqlqueries[$nn] = $sqlquery . " AND ((chapter = " . $chapterverse[0] . " AND verse >= " . $chapterverse[1] . ")";
                if($chapterverse1[0] - $chapterverse[0] > 1) {
                  for($d=1;$d<($chapterverse1[0] - $chapterverse[0]);$d++){
                    $sqlqueries[$nn] .= " OR (chapter = " . ($chapterverse[0] + $d) . ")";
                  }
                }
                $sqlqueries[$nn] .= " OR (chapter = " . $chapterverse1[0] . " AND verse <= " . $chapterverse1[1] . "))";
              } 
            //ELSEIF there is NOT a chapter indicator on the right hand side of the range of consecutive verses
            //    ( EXAMPLE: John 3,16-18 )
            //    (    John 3,16 <=|=> 18 )
            //    (    18 )
            else {
              $sqlqueries[$nn] = $sqlquery . " AND chapter >= " . $chapterverse[0] . " AND verse >= " . $chapterverse[1];
              $mappedReference = mapReference($version,$book1,$chapterverse[0],$fromto[1],$preferorigin);
              $fromto[1] = $mappedReference[1];
              $preferorigin = $mappedReference[2];
              $sqlqueries[$nn] .= " AND chapter <= " . $mappedReference[0] . " AND verse <= " . $fromto[1];
            }
          } 
          //ELSEIF there is NOT a chapter/verse indicator on the left hand side of the range of consecutive verses OR chapters
          //    this means that we are dealing with consecutive CHAPTERS and not VERSES
          //     ( EXAMPLE: John 3-4 )
          //     ( EXAMPLE: 3 <=|=> 4 )
          else {
            $mappedReference1 = mapReference($version,$book1,$fromto[0],null,$preferorigin);
            $mappedReference2 = mapReference($version,$book1,$fromto[1],null,$preferorigin);
            $fromto[0] = $mappedReference1[0];
            $fromto[1] = $mappedReference2[0];
            $preferorigin = $mappedReference1[2];
            $sqlqueries[$nn] = $sqlquery . " AND chapter >= " . $fromto[0] . " AND chapter <= " . $fromto[1];
          }
        }
        //ELSEIF the request DOES NOT contain a range of consecutive verses OR chapters
        //    (EXAMPLE: John 3,16)
        else {
          //IF we DO have a chapter/verse indicator
          if (strpos($query, ",")) {
            $originalquery[$nn] = $origquery;
            $chapterverse = preg_split("/,/", $query);
            $xchapter = $chapterverse[0];
            $mappedReference = mapReference($version,$book1,$chapterverse[0],$chapterverse[1],$preferorigin);
            $chapterverse[0] = $mappedReference[0];
            $chapterverse[1] = $mappedReference[1];
            $preferorigin = $mappedReference[2];
            $sqlqueries[$nn] = $sqlquery . " AND chapter = " . $chapterverse[0] . " AND verse = " . $chapterverse[1];
          } 
          //ELSEIF we are dealing with just a single chapter
          //    ( EXAMPLE: John 3 )
          else {
            $originalquery[$nn] = $origquery;
            $xchapter = $query;
            $mappedReference = mapReference($version,$book1,$xchapter,null,$preferorigin);
            $preferorigin = $mappedReference[2];
            $sqlqueries[$nn] = $sqlquery . " AND chapter = " . $mappedReference[0]; // . " AND verse = " . $piece;
          }
        }

        $sqlqueries[$nn] .= $preferorigin;

        $queriesversions[$nn] = $version;
        //VERSES must be ordered by verseID in order to handle those cases where there are subverses (usually Greek additions)
        //In these cases, the subverses sometimes come before, sometimes come after the "main" verse
        //Ex. Esther 1,1a-1r precedes Esther 1,1 but Esther 3,13 precedes Esther 3,13a-13g
        //Being this the case, it would not be possible to have coherent ordering by book,chapter,verse,verseequiv
        //The only solution is to make sure the verses are ordered correctly in the table with a unique verseID
        $sqlqueries[$nn] .= " ORDER BY verseID";
        //$sqlqueries[$nn] .= " ORDER BY book,chapter,verse,verseequiv";
        if (in_array($version, $copyrightversions)) {
          $sqlqueries[$nn] .= " LIMIT 30";
        }
        $nn++;
      }

      $i++;
    }
  }
  return array($sqlqueries, $queriesversions, $originalquery);
  //END formulateQueries
}

function mapReference($version,$book,$chapter,$verse,$preferorigin){
  global $CATHOLIC_VERSIONS;
  if(in_array($version,$CATHOLIC_VERSIONS)){
    if($book == 19 || $book == "19"){ //19 == Esther
      //first let's make sure that $chapter is a number
      if(gettype($chapter) == 'string'){
        //the USCCB uses letters A-F to indicate the Greek additions to Esther
        //however, the BibleGet engine does not allow chapters that are not numbers
        //therefore these verses have been added to the database in the same fashion as the CEI2008 layout
        //TODO: see if there is any way of allowing letters as chapter indicators and then map them to the CEI2008 layout
        $chapter = intval($chapter); 
      }
      if($version == 'VGCL' || $version == 'DRB'){
        if(($chapter == 10 && (($verse >= 4 && $verse <= 13) || $verse == null)) || ($chapter == 11 && ($verse == 1 || $verse == null) )){
          $chapter = 10;
          $verse = 3;
          $preferorigin = " AND verseorigin = 'GREEK'";
        } else if(($chapter == 11 && (($verse >= 2 && $verse <= 12 ) || $verse == null)) || ($chapter == 12 && (($verse >=1 && $verse <= 6) || $verse == null))){
          $chapter = 1;
          $verse = 1;
          $preferorigin = " AND verseorigin = 'GREEK'";
        } else if($chapter == 13 && (($verse >= 1 && $verse <= 7 ) || $verse == null)){
          $chapter = 3;
          $verse = 13;
          $preferorigin = " AND verseorigin = 'GREEK'";
        } else if(($chapter == 13 && (($verse >= 8 && $verse <= 18) || $verse == null)) || ($chapter == 14 && (($verse <= 1 && $verse >= 19) || $verse == null))){
          $chapter = 4;
          $verse = 17;
          $preferorigin = " AND verseorigin = 'GREEK'";
        } else if($chapter == 15 && (($verse >= 1 && $verse <= 3) || $verse == null)){
          $chapter = 4;
          $verse = 8;
          $preferorigin = " AND verseorigin = 'GREEK'";
        } else if($chapter == 15 && (($verse >= 4 && $verse <= 14) || $verse == null)){
          $chapter = 5;
          $verse = 1;
          $preferorigin = " AND verseorigin = 'GREEK'";
        } else if($chapter == 15 && (($verse >= 15 && $verse <= 19) || $verse == null)){
          $chapter = 5;
          $verse = 2;
          $preferorigin = " AND verseorigin = 'GREEK'";
        } else if($chapter == 16){
          $chapter = 8;
          $verse = 12;
          $preferorigin = " AND verseorigin = 'GREEK'";
        }
      }
    }
  }
  return [$chapter,$verse,$preferorigin];
}

function getGeoIpInfo($ipaddress, $mysqli)
{
  $ch = curl_init("https://ipinfo.io/" . $ipaddress . "?token=" . IPINFO_ACCESS_TOKEN);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  if (($geoip_json = curl_exec($ch)) === false) {
    $mysqli->query("INSERT INTO curl_error (ERRNO,ERROR) VALUES(" . curl_errno($ch) . ",'" . curl_error($ch) . "')");
  }
  //Check the status of communication with ipinfo.io server
  $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if ($http_status == 429) {
    $geoip_json = '{"ERROR":"api limit exceeded"}';
  } else if ($http_status == 200) {
    //Clean geopip_json object, ensure it is valid in any case
    //$geoip_json = $mysqli->real_escape_string($geoip_json); // we don't need to escape it when it's coming from the ipinfo.io server, at least not before inserting into the database
    //Check if it's actually an object or if it's not a string perhaps
    $geoip_JSON_obj = json_decode($geoip_json);
    if ($geoip_JSON_obj === null || json_last_error() !== JSON_ERROR_NONE) {
      //we have a problem with our geoip_json, it's probably a string with an error. We should already have escaped it           
      $geoip_json = '{"ERROR":"' . json_last_error() . ' <' . $geoip_json . '>"}';
    } else {
      $geoip_json = json_encode($geoip_JSON_obj);
    }
  } else {
    $geoip_json = '{"ERROR":"wrong http status > ' . $http_status . '"}';
  }
  return $geoip_json;
}

function doQueries($sqlqueries, $queriesversions, $originalquery)
{
  global $div;
  //global $err;
  //global $sections;
  global $mysqli;
  global $indexes;
  global $returntype;
  global $bbquery;
  global $BIBLEGET;

  $requestmethod = isset($_SERVER['HTTP_X_REQUESTED_WITH']) ? $_SERVER['HTTP_X_REQUESTED_WITH'] : $_SERVER["REQUEST_METHOD"];
  $headersObj = apache_request_headers();
  $headers = json_encode($headersObj);

  // I need to find a way to check and counter when someone uses in an indiscriminate manner
  // People need to learn to do cacheing of requests (maybe they're trying to turn it into a kind of DDOS attack?)
  // Need to protect ourselves here, but instead of pointing fingers by looking for example at the referring site,
  // we need to base the check on past requests

  // First we initialize some variables and flags with default values
  $version = "";
  $newversion = false;
  $book = "";
  $newbook = false;
  $chapter = 0;
  $newchapter = false;

  //echo "<pre>";
  //print_r($sqlqueries);
  //echo "</pre>";
  //die("DEBUG");

  // HTML return type is a special case, because we must already implement display logic to the structured data that is returned 
  $i = 0;
  $appid = "";
  $domain = "";
  $pluginversion = "";

  foreach ($sqlqueries as $xquery) {

    //$ipaddress = isset($_SERVER["HTTP_X_FORWARDED_FOR"]) && $_SERVER["HTTP_X_FORWARDED_FOR"] != "" ? explode(",",$_SERVER["HTTP_X_FORWARDED_FOR"])[0] : $_SERVER["REMOTE_ADDR"];
    $forwardedip = isset($_SERVER["HTTP_X_FORWARDED_FOR"]) ? $_SERVER["HTTP_X_FORWARDED_FOR"] : "";
    $remote_address = isset($_SERVER["REMOTE_ADDR"]) ? $_SERVER["REMOTE_ADDR"] : "";
    $realip = isset($_SERVER["HTTP_X_REAL_IP"]) ? $_SERVER["HTTP_X_REAL_IP"] : "";
    $clientip = isset($_SERVER["HTTP_CLIENT_IP"]) ? $_SERVER["HTTP_CLIENT_IP"] : "";

    //Do our best to identify an IP address associated with the incoming request, 
    //trying first HTTP_X_FORWARDED_FOR, then REMOTE_ADDR and last resort HTTP_X_REAL_IP
    //This is useful only to protect against high volume requests from specific IP addresses or referers
    $ipaddress = isset($_SERVER["HTTP_X_FORWARDED_FOR"]) && $_SERVER["HTTP_X_FORWARDED_FOR"] != "" ? explode(",", $_SERVER["HTTP_X_FORWARDED_FOR"])[0] : "";
    if ($ipaddress == "") {
      $ipaddress = isset($_SERVER["REMOTE_ADDR"]) && $_SERVER["REMOTE_ADDR"] != "" ? $_SERVER["REMOTE_ADDR"] : "";
    }
    if ($ipaddress == "") {
      $ipaddress = isset($_SERVER["HTTP_X_REAL_IP"]) && $_SERVER["HTTP_X_REAL_IP"] != "" ? $_SERVER["HTTP_X_REAL_IP"] : "";
    }

    if (filter_var($ipaddress, FILTER_VALIDATE_IP) === false) {
      addErrorMessage("The BibleGet API endpoint cannot be used behind a proxy that hides the IP address from which the request is coming. No personal or sensitive data is collected by the API, however IP addresses are monitored to prevent spam requests. If you believe there is an error because this is not the case, please contact the developers so they can look into the situtation.", $returntype, $xquery);
      outputResult($bbquery, $returntype); //this should exit the script right here, closing the mysql connection
    }

    //we start off with the supposition that we've never seen this IP address before
    $haveip = false; //"haveip" means "we already have this ip address in our records"
    //request logs are now divided by year, to keep things cleaner and easier to access and read
    $curYEAR = date("Y");

    global $WhitelistedDomainsIPs;
    //Don't enforce the max limit for requests from domains that need to do a lot of testing for plugin development
    if (array_search($BIBLEGET["domain"], $WhitelistedDomainsIPs) !== false || array_search($ipaddress, $WhitelistedDomainsIPs) !== false) {
      $originHeader = key_exists("ORIGIN", $headersObj) ? $headersObj["ORIGIN"] : "";
    } else {
      //check if we have already seen this IP Address in the past 2 days and if we have the same request already
      if ($ipaddress != "" && $ipresult = $mysqli->query("SELECT * FROM requests_log__" . $curYEAR . " WHERE WHO_IP = INET6_ATON('" . $ipaddress . "') AND QUERY = '" . $xquery . "'  AND WHO_WHEN > DATE_SUB(NOW(), INTERVAL 2 DAY)")) {
        if (DEBUG_IPINFO === true) {
          file_put_contents(DEBUGFILE, "We have seen the IP Address [" . $ipaddress . "] in the past 2 days with this same request [" . $xquery . "]" . PHP_EOL, FILE_APPEND | LOCK_EX);
        }
        //if more than 10 times in the past two days (but less than 30) simply add message inviting to use cacheing mechanism
        if ($ipresult->num_rows > 10 && $ipresult->num_rows < 30) {
          addErrorMessage(10, $returntype, $xquery);
          $iprow = $ipresult->fetch_assoc();
          $geoip_json = $iprow["WHO_WHERE_JSON"];
          $haveip = true;
        }
        //if we have more than 30 requests in the past two days for the same query, deny service?
        else if ($ipresult->num_rows > 29) {
          addErrorMessage(11, $returntype, $xquery);
          outputResult($bbquery, $returntype); //this should exit the script right here, closing the mysql connection
        }
      }

      //and if the same IP address is making too many requests(>100?) with different queries (like copying the bible texts completely), deny service
      if ($ipaddress != "" && $ipresult = $mysqli->query("SELECT * FROM requests_log__" . $curYEAR . " WHERE WHO_IP = INET6_ATON('" . $ipaddress . "') AND WHO_WHEN > DATE_SUB(NOW(), INTERVAL 2 DAY)")) {
        if (DEBUG_IPINFO === true) {
          file_put_contents(DEBUGFILE, "We have seen the IP Address [" . $ipaddress . "] in the past 2 days with many different requests" . PHP_EOL, FILE_APPEND | LOCK_EX);
        }
        //if we 50 or more requests in the past two days, deny service?
        if ($ipresult->num_rows > 100) {
          if (DEBUG_IPINFO === true) {
            file_put_contents(DEBUGFILE, "We have seen the IP Address [" . $ipaddress . "] in the past 2 days with over 50 requests", FILE_APPEND | LOCK_EX);
          }
          addErrorMessage(12, $returntype, $xquery);
          outputResult($bbquery, $returntype); //this should exit the script right here, closing the mysql connection
        }
      }

      //let's add another check for "referer" websites and how many similar requests have derived from the same origin in the past couple days
      $originHeader = key_exists("ORIGIN", $headersObj) ? $headersObj["ORIGIN"] : "";
      if ($originres = $mysqli->query("SELECT ORIGIN,COUNT(*) AS ORIGIN_CNT FROM requests_log__" . $curYEAR . " WHERE QUERY = '" . $xquery . "' AND ORIGIN != '' AND ORIGIN = '" . $originHeader . "' AND WHO_WHEN > DATE_SUB(NOW(), INTERVAL 2 DAY) GROUP BY ORIGIN")) {
        if ($originres->num_rows > 0) {
          $originRow = $originres->fetch_assoc();
          if (key_exists("ORIGIN_CNT", $originRow)) {
            if ($originRow["ORIGIN_CNT"] > 10 && $originRow["ORIGIN_CNT"] < 30) {
              addErrorMessage(10, $returntype, $xquery);
            } else if ($originRow["ORIGIN_CNT"] > 29) {
              addErrorMessage(11, $returntype, $xquery);
              outputResult($bbquery, $returntype); //this should exit the script right here, closing the mysql connection                
            }
          }
        }
      }
      //and we'll check for diverse requests from the same origin in the past couple days (>100?)
      if ($originres = $mysqli->query("SELECT ORIGIN,COUNT(*) AS ORIGIN_CNT FROM requests_log__" . $curYEAR . " WHERE ORIGIN != '' AND ORIGIN = '" . $originHeader . "' AND WHO_WHEN > DATE_SUB(NOW(), INTERVAL 2 DAY) GROUP BY ORIGIN")) {
        if ($originres->num_rows > 0) {
          $originRow = $originres->fetch_assoc();
          if (key_exists("ORIGIN_CNT", $originRow)) {
            if ($originRow["ORIGIN_CNT"] > 100) {
              addErrorMessage(12, $returntype, $xquery);
              outputResult($bbquery, $returntype); //this should exit the script right here, closing the mysql connection
            }
          }
        }
      }
    } // end max request checks

    $myversion = $queriesversions[$i];
    //     echo $i.") myversion = ".$myversion."<br />";
    //     echo "about to query the database: &lt;".$xquery."&gt;<br />";
    if ($result = $mysqli->query($xquery)) {
      //       echo "<p>We have results from query ".$xquery."</p>";
      // Up the good counter!
      $mysqli->query("UPDATE counter SET good = good + 1");



      //if we already have a record of this IP address and we have info on it from ipinfo.io,
      //then we don't need to get info on it from ipinfo.io again (which has limit of 1000 requests per day)
      $pregmatch = preg_quote('{"ERROR":"', '/');
      if ($haveip === false || $geoip_json == "" || $geoip_json === null || preg_match("/" . $pregmatch . "/", $geoip_json)) {
        if (DEBUG_IPINFO === true) {
          file_put_contents(DEBUGFILE, "Either we have not yet seen the IP address [" . $ipaddress . "] in the past 2 days or we have not geo_ip info [" . $geoip_json . "]" . PHP_EOL, FILE_APPEND | LOCK_EX);
        }
        if ($ipaddress != "" && $ipresult = $mysqli->query("SELECT * FROM requests_log__" . $curYEAR . " WHERE WHO_IP = INET6_ATON('" . $ipaddress . "') AND WHO_WHERE_JSON NOT LIKE '{\"ERROR\":\"%\"}'")) {
          if ($ipresult->num_rows > 0) {
            if (DEBUG_IPINFO === true) {
              file_put_contents(DEBUGFILE, "We already have valid geo_ip info [" . $geoip_json . "] for the IP address [" . $ipaddress . "], reusing" . PHP_EOL, FILE_APPEND | LOCK_EX);
            }
            $iprow = $ipresult->fetch_assoc();
            $geoip_json = $iprow["WHO_WHERE_JSON"];
            $haveip = true;
          } else {
            if (DEBUG_IPINFO === true) {
              file_put_contents(DEBUGFILE, "We do not yet have valid geo_ip info [" . $geoip_json . "] for the IP address [" . $ipaddress . "], nothing to reuse" . PHP_EOL, FILE_APPEND | LOCK_EX);
            }
            $geoip_json = getGeoIpInfo($ipaddress, $mysqli);
            if (DEBUG_IPINFO === true) {
              file_put_contents(DEBUGFILE, "We have attempted to get geo_ip info [" . $geoip_json . "] for the IP address [" . $ipaddress . "] from ipinfo.io" . PHP_EOL, FILE_APPEND | LOCK_EX);
            }
          }
        } else if ($ipaddress != "") {
          if (DEBUG_IPINFO === true) {
            file_put_contents(DEBUGFILE, "We do however seem to have a valid IP address [" . $ipaddress . "] , now trying to fetch info from ipinfo.io" . PHP_EOL, FILE_APPEND | LOCK_EX);
          }
          $geoip_json = getGeoIpInfo($ipaddress, $mysqli);
          if (DEBUG_IPINFO === true) {
            file_put_contents(DEBUGFILE, "Even in this case we have attempted to get geo_ip info [" . $geoip_json . "] for the IP address [" . $ipaddress . "] from ipinfo.io" . PHP_EOL, FILE_APPEND | LOCK_EX);
          }
        }
      }



      if ($appid === "") {
        $appid = ($BIBLEGET["appid"] != "") ? $BIBLEGET["appid"] : "unknown";
      }
      if ($domain === "") {
        $domain = ($BIBLEGET["domain"] != "") ? $BIBLEGET["domain"] : "unknown";
      }
      if ($pluginversion === "") {
        $pluginversion = ($BIBLEGET["pluginversion"] != "") ? $BIBLEGET["pluginversion"] : "unknown";
      }
      $ipaddress = $ipaddress != "" ? $ipaddress : "0.0.0.0";
      if ($geoip_json === "" || $geoip_json === null) {
        $geoip_json = '{"ERROR":""}';
      }

      $stmt = $mysqli->prepare("INSERT INTO requests_log__" . $curYEAR . " (WHO_IP,WHO_WHERE_JSON,HEADERS_JSON,ORIGIN,QUERY,ORIGINALQUERY,REQUEST_METHOD,HTTP_CLIENT_IP,HTTP_X_FORWARDED_FOR,HTTP_X_REAL_IP,REMOTE_ADDR,APP_ID,DOMAIN,PLUGINVERSION) VALUES (INET6_ATON(?), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
      $stmt->bind_param('ssssssssssssss', $ipaddress, $geoip_json, $headers, $originHeader, $xquery, $originalquery[$i], $requestmethod, $clientip, $forwardedip, $realip, $remote_address, $appid, $domain, $pluginversion);
      if ($stmt->execute() === false) {
        addErrorMessage("There has been an error updating the logs: (" . $mysqli->errno . ") " . $mysqli->error, $returntype);
      }
      $stmt->close();

      $verse = "";
      $newverse = false;
      while ($row = $result->fetch_assoc()) {

        $row["version"] = strtoupper($myversion);
        $row["testament"] = (int)$row["testament"];

        $universal_booknum = $row["book"];
        $booknum = array_search($row["book"], $indexes[$myversion]["book_num"]);
        $row["bookabbrev"] = $indexes[$myversion]["abbreviations"][$booknum];
        $row["booknum"] = $booknum;
        $row["univbooknum"] = $universal_booknum;
        $row["book"] = $indexes[$myversion]["biblebooks"][$booknum];

        $row["section"] = (int) $row["section"];
        unset($row["verseID"]);
        //$row["verse"] = (int) $row["verse"];
        $row["chapter"] = (int) $row["chapter"];
        $row["originalquery"] = $originalquery[$i];

        if ($returntype == "xml") {
          $thisrow = $bbquery->results->addChild("result");
          foreach ($row as $key => $value) {
            $thisrow[$key] = $value;
          }
        } elseif ($returntype == "json") {
          $bbquery->results[] = $row;
        } elseif ($returntype == "html") {

          if ($row["verse"] != $verse) {
            $newverse = true;
            $verse = $row["verse"];
          } else {
            $newverse = false;
          }

          if ($row["chapter"] != $chapter) {
            $newchapter = true;
            $newverse = true;
            $chapter = $row["chapter"];
          } else {
            $newchapter = false;
          }

          if ($row["book"] != $book) {
            $newbook = true;
            $newchapter = true;
            $newverse = true;
            $book = $row["book"];
          } else {
            $newbook = false;
          }

          if ($row["version"] != $version) {
            $newversion = true;
            $newbook = true;
            $newchapter = true;
            $newverse = true;
            $version = $row["version"];
          } else {
            $newversion = false;
          }

          if ($newversion) {
            $variant = $bbquery->createElement("p", $row["version"]);
            if ($i > 0) {
              $br = $bbquery->createElement("br");
              $variant->insertBefore($br, $variant->firstChild);
            }
            $variant->setAttribute("class", "version bibleVersion");
            $div->appendChild($variant);
          }
          if ($newbook || $newchapter) {
            $citation = $bbquery->createElement("p", $row["book"] . "&nbsp;" . $row["chapter"]);
            $citation->setAttribute("class", "book bookChapter");
            $div->appendChild($citation);
            $citation1 = $bbquery->createElement("p");
            $citation1->setAttribute("class", "verses versesParagraph");
            $div->appendChild($citation1);
            $metainfo = $bbquery->createElement("input");
            $metainfo->setAttribute("type", "hidden");
            $metainfo->setAttribute("class", "originalQuery");
            $metainfo->setAttribute("value", $row["originalquery"]);
            $div->appendChild($metainfo);
            $metainfo1 = $bbquery->createElement("input");
            $metainfo1->setAttribute("type", "hidden");
            $metainfo1->setAttribute("class", "bookAbbrev");
            $metainfo1->setAttribute("value", $row["bookabbrev"]);
            $div->appendChild($metainfo1);
            $metainfo2 = $bbquery->createElement("input");
            $metainfo2->setAttribute("type", "hidden");
            $metainfo2->setAttribute("class", "bookNum");
            $metainfo2->setAttribute("value", $row["booknum"]);
            $div->appendChild($metainfo2);
            $metainfo3 = $bbquery->createElement("input");
            $metainfo3->setAttribute("type", "hidden");
            $metainfo3->setAttribute("class", "univBookNum");
            $metainfo3->setAttribute("value", $row["univbooknum"]);
            $div->appendChild($metainfo3);
          }
          if ($newverse) {
            $versicle = $bbquery->createElement("span", $row["verse"]);
            $versicle->setAttribute("class", "sup verseNum");
            $citation1->appendChild($versicle);
          }

          $text = $bbquery->createElement("span", $row["text"]);
          $text->setAttribute("class", "text verseText");
          $citation1->appendChild($text);
        }
      }
    } else {
      addErrorMessage(9, $returntype, $xquery);
    }
    $i++;
  }
}


/******************************************************************
 * ENCODE OUR OBJECT INTO THE FORMAT REQUESTED BY THE RETURN TYPE *
 *****************************************************************/


function outputResult() {
    global $mysqli;
    global $div;
    global $err;
    global $inf;
    global $bbquery;
    global $returntype;
    global $detectedNotation;


    if ($returntype == "json") {
        $bbquery->info["detectedNotation"] = $detectedNotation;
        echo json_encode($bbquery, JSON_UNESCAPED_UNICODE);
    }
    if ($returntype == "xml") {
        $bbquery->info["detectedNotation"] = $detectedNotation;
        echo $bbquery->asXML();
    }
    if ($returntype == "html") {
        $bbquery->appendChild($div);
        $bbquery->appendChild($err);
        $info = $bbquery->createElement("input");
        $info->setAttribute("type", "hidden");
        $info->setAttribute("name", "ENDPOINT_VERSION");
        $info->setAttribute("value", ENDPOINT_VERSION);
        $info->setAttribute("class", "BibleGetInfo");
        $inf->appendChild($info);
        $info1 = $bbquery->createElement("input");
        $info1->setAttribute("type", "hidden");
        $info1->setAttribute("name", "detectedNotation");
        $info1->setAttribute("value", $detectedNotation);
        $info1->setAttribute("class", "BibleGetInfo");
        $inf->appendChild($info1);
        $bbquery->appendChild($inf);
        echo $bbquery->saveHTML($div);
        echo $bbquery->saveHTML($err);
        echo $bbquery->saveHTML($inf);
    }
    if ($mysqli && $mysqli->thread_id) {
        $mysqli->close();
    }
    exit(0);
}


function startsWith( $needle, $haystack ) {
    return substr( $haystack, 0, strlen($needle) ) === $needle;
}

function endsWith( $needle, $haystack ) {
    return substr( $haystack, -strlen($needle) ) === $needle;
}
