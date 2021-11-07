<?php

/**
 * BibleGet I/O Project Service Endpoint
 * listens on both GET requests and POST requests
 * whether ajax or not
 * accepts all cross-domain requests
 * is CORS enabled ( as far as I understand it )
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
 * 
 * Blessed Carlo Acutis, pray for us
 * 
 * MINIMUM PHP REQUIREMENT: PHP 8.1 (allow for type declarations and mixed function return types)
 */

//ini_set( 'display_errors', 1 );
//ini_set( 'display_startup_errors', 1 );
//error_reporting( E_ALL );

define( "ENDPOINT_VERSION", "3.0" );
define( "BIBLEGETIOQUERYSCRIPT", "iknowwhythisishere" );

// Don't allow bots to access this script!
if ( isset( $_SERVER['HTTP_USER_AGENT'] ) && preg_match( '/bot|crawl|slurp|spider/i', $_SERVER['HTTP_USER_AGENT'] ) ) {
  exit( 0 );
}

/*************************************************************
 * SET HEADERS TO ALLOW ANY KIND OF REQUESTS FROM ANY ORIGIN * 
 * AND CONTENT-TYPE BASED ON REQUESTED RETURN TYPE           *
 ************************************************************/

// Allow from any origin
if ( isset( $_SERVER['HTTP_ORIGIN'] ) ) {
    header( "Access-Control-Allow-Origin: {$_SERVER['HTTP_ORIGIN']}" );
    header( 'Access-Control-Allow-Credentials: true' );
    header( 'Access-Control-Max-Age: 86400' );    // cache for 1 day
}
// Access-Control headers are received during OPTIONS requests
if ( isset( $_SERVER['REQUEST_METHOD'] ) ) {
    if ( isset( $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'] ) )
        header( "Access-Control-Allow-Methods: GET, POST" );
    if ( isset( $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'] ) )
        header( "Access-Control-Allow-Headers: {$_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']}" );
}


class BIBLEGET_QUOTE {

    static public array $returnTypes                = [ "json", "xml", "html" ];
    static public array $allowedAcceptHeaders       = [ "application/json", "application/xml", "text/html" ];
    static public array $allowedContentTypes        = [ "application/json", "application/x-www-form-urlencoded" ];
    static public array $allowedRequestMethods      = [ "GET", "POST" ];
    static public array $allowedPreferredOrigins    = [ "GREEK", "HEBREW" ];
    static public array $requestParameters          = [
      "query"         => "",
      "return"        => "",
      "version"       => "",
      "domain"        => "",
      "appid"         => "",
      "pluginversion" => "",
      "forceversion"  => "",
      "forcecopyright"=> "",
      "preferorigin"  => ""
    ];

    static public array $errorMessages = [
        0 => "The first query must start with a valid book indicator.",
        1 => "You must have a valid chapter following a book indicator.",
        2 => "The book indicator is not valid. Please check the documentation for a list of correct book indicators.",
        3 => "You cannot request discontinuous verses without first indicating a chapter for the discontinuous verses.",
        4 => "A request for discontinuous verses must contain two valid verse numbers on either side of a discontinuous verse indicator.",
        5 => "A chapter-verse separator must be preceded by a valid chapter number and followed by a valid verse number.",
        6 => "A request for a range of verses must contain two valid verse numbers on either side of a verse range indicator.",
        7 => "If there is a chapter-verse construct following a dash, there must also be a chapter-verse construct preceding the same dash.",
        8 => "Multiple verse ranges have been requested, but there are not enough verse separators. Multiple verse ranges assume there are verse separators that connect them.",
        9 => "Notation Error. Please check your citation notation.",
        10 => "Please use a cacheing mechanism, you seem to be submitting numerous requests for the same query.",
        11 => "You are submitting too many requests with the same query. You must use a cacheing mechanism. Once you have implemented a cacheing mechanism you may have to wait a couple of days before getting service again. Otherwise contact the service management to request service again.",
        12 => "You are submitting a very large amount of requests to the endpoint. Please slow down. If you believe there has been an error you may contact the service management."
    ];


    public string $jsonEncodedRequestHeaders   = "";
    public string $originHeader                = "";
    public string $requestMethod               = "";
    public string $responseContentType         = "json";     //response Content-type ( json, xml or html )
    public array $WhitelistedDomainsIPs        = [];
    public array $requestHeaders               = [];
    public string $acceptHeader                = "";
    public string $requestContentType          = "";
    //public bool $isAjax                        = false;
    public string $detectedNotation            = "ENGLISH"; //can be "ENGLISH" or "EUROPEAN" or "MIXED" (first two are valid, last value is invalid)

    public mysqli $mysqli;                     //instance of database
    public stdClass|SimpleXMLElement|DOMDocument $bibleQuote; //object with json, xml or html data to return
    //useful for html output:
    public DOMElement $div;
    public DOMElement $err;
    public DOMElement $inf;

    public array $queries                       = [];
    public array $validatedQueries              = [];
    public array $validatedVariants             = [];
    public array $formulatedQueries             = [];
    public array $formulatedVariants            = [];
    public array $originalQueries               = [];
    public array $VALID_VERSIONS                = [];
    public array $VALID_VERSIONS_FULLNAME       = [];
    public array $COPYRIGHT_VERSIONS            = [];
    public array $PROTESTANT_VERSIONS           = [];
    public array $CATHOLIC_VERSIONS             = [];
    public array $REQUESTED_VERSIONS            = [];
    public array $REQUESTED_COPYRIGHTED_VERSIONS = [];
    public array $BIBLEBOOKS                    = [];
    public array $INDEXES                       = [];
    public array $DATA                          = []; //all request parameters
    public bool $DEBUG_REQUESTS                 = false;
    public bool $DEBUG_IPINFO                   = false;
    public string $DEBUGFILE                    = "requests.log";

    function __construct( array $DATA ){
        $this->requestHeaders = getallheaders();
        $this->jsonEncodedRequestHeaders = json_encode( $this->requestHeaders );
        $this->originHeader = key_exists( "ORIGIN", $this->requestHeaders ) ? $this->requestHeaders["ORIGIN"] : "";
        $this->requestContentType = isset( $_SERVER['CONTENT_TYPE'] ) && in_array( $_SERVER['CONTENT_TYPE'], self::$allowedContentTypes ) ? $_SERVER['CONTENT_TYPE'] : "";
        $this->acceptHeader = isset( $this->requestHeaders["Accept"] ) && in_array( $this->requestHeaders["Accept"], self::$allowedAcceptHeaders ) ? ( string ) $this->requestHeaders["Accept"] : "";
        $this->requestMethod = isset( $_SERVER['HTTP_X_REQUESTED_WITH'] ) ? $_SERVER['HTTP_X_REQUESTED_WITH'] : $_SERVER["REQUEST_METHOD"];
        $this->responseContentType = ( isset( $DATA["return"] ) && in_array( strtolower( $DATA["return"] ),self::$returnTypes ) ) ? strtolower( $DATA["return"] ) : ( $this->acceptHeader !== "" ? ( string ) self::$returnTypes[array_search( $this->requestHeaders["Accept"], self::$allowedAcceptHeaders )] : ( string ) self::$returnTypes[0] );
        //$this->isAjax = ( isset( $_SERVER['HTTP_X_REQUESTED_WITH'] ) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest' );
        //let's ensure that we have at least default values for parameters
        $this->DATA = array_merge( self::$requestParameters, $DATA );
        $this->DATA["preferorigin"] = in_array( $this->DATA["preferorigin"], self::$allowedPreferredOrigins ) ? $this->DATA["preferorigin"] : "";
    }


    static public function stringWithUpperAndLowerCaseVariants( string $str ) : bool {
        return preg_match( "/\p{L&}/u", $str );
    }

    static private function toProperCase( string $txt ) : string {
        if( BIBLEGET_QUOTE::stringWithUpperAndLowerCaseVariants( $txt ) === false ){
            return $txt;
        } else {
            preg_match( "/\p{L&}/u", $txt, $mList, PREG_OFFSET_CAPTURE );
            if( $mList && array_key_exists( 0, $mList ) ){
                $offset = $mList[0][1];
                $chr = mb_substr( $txt, $offset, 1, 'UTF-8' );
                $post = mb_substr( $txt, $offset+1, null, 'UTF-8' );
                return mb_substr( $txt, 0, $offset, 'UTF-8' ) . mb_strtoupper( $chr, 'UTF-8' ) . mb_strtolower( $post, 'UTF-8' );
            } else {
                return $txt;
            }
        }
    }

    static public function idxOf( string $needle, array $haystack ) : int|bool {
        foreach ( $haystack as $index => $value ) {
            if ( is_array( $haystack[$index] ) ) {
                foreach ( $haystack[$index] as $index2 => $value2 ) {
                    if ( in_array( $needle, $haystack[$index][$index2] ) ) {
                        return $index;
                    }
                }
            } else if ( in_array( $needle, $haystack[$index] ) ) {
                return $index;
            }
        }
        return false;
    }


    static private function normalizeBibleBook( string $str ) : string {
        return self::toProperCase( preg_replace( "/\s+/", "", trim( $str ) ) );
    }

    static private function detectAndNormalizeNotation( string &$querystr ) : string {
        $detectedNotation = "";

        //if query is written in english notation, convert it to european notation
        $find = [ ".", ",", ":" ];
        $replace = [ "", ".", "," ];

        if ( strpos( $querystr, ":" ) && strpos( $querystr, "." ) ) {
            //can't use both notations
            $detectedNotation = "MIXED";
        } else if ( strpos( $querystr, ":" ) && strpos( $querystr, "," ) && strpos( $querystr, ";" ) ) {
            //check if the comma is used invalidly, this will only happen if there is more than one query
            //this is why we check if there is a semicolon
            //let's split all queries into an array
            $queries = explode( ";", $querystr );
            //let's remove any book and chapter indicators from the beginning of each query
            $queries = preg_replace( "/^([1-3]{0,1}((\p{Lu}\p{Ll}*)*))([1-9][0-9]{0,2})/u", "", $queries );
            //let's get the first character that was following any chapter indicators
            $queries = array_map(function($v) { return substr($v, 0, 1); }, $queries);
            if( in_array( ":", $queries ) && in_array( ",", $queries ) ){
                $detectedNotation = "MIXED";
            } else if ( in_array( ":", $queries ) ){
                $detectedNotation = "ENGLISH";
                $querystr = str_replace( $find, $replace, $querystr );
            } else {
                $detectedNotation = "EUROPEAN";
            }
        } else if ( strpos( $querystr, ":" ) ) {
            $detectedNotation = "ENGLISH";
            $querystr = str_replace( $find, $replace, $querystr );
        } else {
            $detectedNotation = "EUROPEAN";
        }

        return $detectedNotation;
    }

    static private function removeWhitespace( string $querystr ) : string {
        $querystr = preg_replace( '/\s+/', '', $querystr );
        return str_replace( ' ', '', $querystr );
    }

    static private function convertAllDashesToHyphens( string $querystr ) : string {
        return preg_replace( '/[\x{2011}-\x{2015}|\x{2212}|\x{23AF}]/u', '-', $querystr );
    }

    static private function removeEmptyItems( array $queries ) : array {
        return array_values( array_filter( $queries, function ( $var ) {
            return $var !== "";
        } ) );
    }

    private function isValidVersion( string $version ) : bool {
        return( in_array( $version, $this->VALID_VERSIONS ) );
    }

    private function queryStrClean() {

        $querystr = self::removeWhitespace( $this->DATA["query"] );
        $querystr = trim( $querystr );
        $querystr = self::convertAllDashesToHyphens( $querystr );
        $this->detectedNotation = self::detectAndNormalizeNotation( $querystr );

        //if there are multiple queries separated by semicolons, we explode them into an array
        $queries = explode( ";", $querystr );
        $queries = self::removeEmptyItems( $queries );
        $queries = array_map( 'self::toProperCase', $queries );
        $this->queries = $queries;

    }


    public function addErrorMessage( $num, $str="" ) {

        if ( gettype( $num ) === "string" ) {
            self::$errorMessages[13] = $num;
            $num = 13;
        }

        if ( $this->responseContentType === "json" ) {
            $error = [];
            $error["errNum"] = $num;
            $error["errMessage"] = self::$errorMessages[$num] . ( $str !== "" ? " > " . $str : "" );
            $this->bibleQuote->errors[] = $error;
        } elseif ( $this->responseContentType === "xml" ) {
            $err_row = $this->bibleQuote->Errors->addChild( "error", self::$errorMessages[$num] );
            $err_row->addAttribute( "errNum", $num );
        } elseif ( $this->responseContentType === "html" ) {
            $elements = [];
            $errorsTable = $this->bibleQuote->getElementById( "errorsTbl" );
            if ( $errorsTable == null ) {
                $elements[0] = $this->bibleQuote->createElement( "table" );
                $elements[0]->setAttribute( "id","errorsTbl" );
                $elements[0]->setAttribute( "class","errorsTbl" );
                $this->err->appendChild( $elements[0] );
            } else {
                $elements[0] = $errorsTable;
            }

            $elements[1] = $this->bibleQuote->createElement( "tr" );
            $elements[1]->setAttribute( "id","errorsRow" );
            $elements[1]->setAttribute( "class","errorsRow" );
            $elements[0]->appendChild( $elements[1] );
            
            $elements[2] = $this->bibleQuote->createElement( "td", "errNum" );
            $elements[2]->setAttribute( "class", "errNum" );
            $elements[1]->appendChild( $elements[2] );

            $elements[3] = $this->bibleQuote->createElement( "td", $num );
            $elements[3]->setAttribute( "class", "errNumVal" );
            $elements[1]->appendChild( $elements[3] );
        
            $elements[4] = $this->bibleQuote->createElement( "td", "errMessage" );
            $elements[4]->setAttribute( "class", "errMessage" );
            $elements[1]->appendChild( $elements[4] );

            $elements[5] = $this->bibleQuote->createElement( "td", self::$errorMessages[$num] );
            $elements[5]->setAttribute( "class", "errMessageVal" );
            $elements[1]->appendChild( $elements[5] );

        }
    }

    private function outputResult() {

        switch( $this->responseContentType ) {
            case "json":
                $this->bibleQuote->info["detectedNotation"] = $this->detectedNotation;
                echo json_encode( $this->bibleQuote, JSON_UNESCAPED_UNICODE );
                break;
            case "xml":
                $this->bibleQuote->info["detectedNotation"] = $this->detectedNotation;
                echo $this->bibleQuote->asXML();
                break;
            case "html":
                $this->bibleQuote->appendChild( $this->div );
                $this->bibleQuote->appendChild( $this->err ); 
                $info = $this->bibleQuote->createElement( "input" );
                $info->setAttribute( "type", "hidden" );
                $info->setAttribute( "name", "ENDPOINT_VERSION" );
                $info->setAttribute( "value", ENDPOINT_VERSION );
                $info->setAttribute( "class", "BibleGetInfo" );
                $this->inf->appendChild( $info );
                $info1 = $this->bibleQuote->createElement( "input" );
                $info1->setAttribute( "type", "hidden" );
                $info1->setAttribute( "name", "detectedNotation" );
                $info1->setAttribute( "value", $this->detectedNotation );
                $info1->setAttribute( "class", "BibleGetInfo" );
                $this->inf->appendChild( $info1 );
                $this->bibleQuote->appendChild( $this->inf );
                echo $this->bibleQuote->saveHTML( $this->div );
                echo $this->bibleQuote->saveHTML( $this->err );
                echo $this->bibleQuote->saveHTML( $this->inf );
        }

        if ( $this->mysqli && $this->mysqli->thread_id ) {
            $this->mysqli->close();
        }
        exit( 0 );

    }

    private function dbConnect() {

        $dbCredentials = "dbcredentials.php";
        //search for the database credentials file at least three levels up...
        if( file_exists( $dbCredentials ) ){
            include_once( $dbCredentials );
        } else if ( file_exists( "../" . $dbCredentials ) ){
            include_once( "../{$dbCredentials}" );
        } else if ( file_exists( "../../" . $dbCredentials ) ){
            include_once( "../../{$dbCredentials}" );
        }

        $mysqli = new mysqli( SERVER,DBUSER,DBPASS,DATABASE );

        if ( $mysqli->connect_errno ) {
            $this->addErrorMessage( "Failed to connect to MySQL: ( " . $mysqli->connect_errno . " ) " . $mysqli->connect_error );
            $this->outputResult();
        }
        $mysqli->set_charset( "utf8" );
        $this->mysqli = $mysqli;
        if( defined('WHITELISTED_DOMAINS_IPS') ){
            $this->WhitelistedDomainsIPs = WHITELISTED_DOMAINS_IPS;
        }
    }

    private function BibleQuoteInit() {

        switch( $this->responseContentType ){
            case "json":
                $quote = new stdClass();
                $quote->results = [];
                $quote->errors = [];
                $quote->info = ["ENDPOINT_VERSION" => ENDPOINT_VERSION];
                break;
            case "xml":
                $root = "<?xml version=\"1.0\" encoding=\"UTF-8\"?"."><BibleQuote/>";
                $quote = new simpleXMLElement( $root );
                $errors = $quote->addChild( "errors" );
                $info = $quote->addChild( "info" );
                $results = $quote->addChild( "results" );
                $info->addAttribute( "ENDPOINT_VERSION", ENDPOINT_VERSION );
                break;
            case "html":
                $quote = new DOMDocument();
                $html = "<!DOCTYPE HTML><head><title>BibleGet Query Result</title><style>table#errorsTbl { border: 3px double Red; background-color:DarkGray; } table#errorsTbl td { border: 1px solid Black; background-color:LightGray; padding: 3px; } td.errNum,td.errMessage { font-weight:bold; }</style><!-- QUERY.BIBLEGET.IO ENDPOINT VERSION {ENDPOINT_VERSION} --></head><body></body>";
                $quote->loadHTML( $html );
                $div = $quote->createElement( "div" );
                $div->setAttribute( "class","results bibleQuote" );
                $err = $quote->createElement( "div" );
                $err->setAttribute( "class","errors bibleQuote" );
                $inf = $quote->createElement( "div" );
                $inf->setAttribute( "class", "info bibleQuote" );
                $this->div        = $div;
                $this->err        = $err;
                $this->inf        = $inf;
                break;
        }

        $this->bibleQuote = $quote;

    }

    private function populateVersionsInfo(){

        $result = $this->mysqli->query( "SELECT * FROM versions_available WHERE type = 'BIBLE'" );
        if( $result ) {
            while( $row = mysqli_fetch_assoc( $result ) ) {
                $this->VALID_VERSIONS[] = $row["sigla"];
                $this->VALID_VERSIONS_FULLNAME[$row["sigla"]] = $row["fullname"] . "|" . $row["year"];
                if ( $row["copyright"] === 1 ) {
                    $this->COPYRIGHT_VERSIONS[] = $row["sigla"];
                }
                if( $row["canon"] === "CATHOLIC" ){
                    $this->CATHOLIC_VERSIONS[] = $row["sigla"];
                } else if ( $row["canon"] === "PROTESTANT" ){
                    $this->PROTESTANT_VERSIONS[] = $row["sigla"];
                }
            }
        }
        else{
            $this->addErrorMessage( "<p>MySQL ERROR ".$this->mysqli->errno . ": " . $this->mysqli->error."</p>" );
            $this->outputResult();
        }

    }

    private function prepareIndexes(){

        $indexes = [];

        foreach( $this->REQUESTED_VERSIONS as $variant ){

            $abbreviations  = [];
            $bbbooks        = [];
            $chapter_limit  = [];
            $verse_limit    = [];
            $book_num       = [];

            // fetch the index information for the requested version from the database and load it into our arrays
            $result = $this->mysqli->query( "SELECT * FROM ".$variant."_idx" );
            if( $result ){
                while( $row = $result->fetch_assoc() ){
                    $abbreviations[]    = $row["abbrev"];
                    $bbbooks[]          = $row["fullname"];
                    $chapter_limit[]    = $row["chapters"];
                    $verse_limit[]      = explode( ",", $row["verses_last"] );
                    $book_num[]         = $row["book"];
                }
            }

            $indexes[$variant]["abbreviations"]   = $abbreviations;
            $indexes[$variant]["biblebooks"]      = $bbbooks;
            $indexes[$variant]["chapter_limit"]   = $chapter_limit;
            $indexes[$variant]["verse_limit"]     = $verse_limit;
            $indexes[$variant]["book_num"]        = $book_num;  

        }

        $this->INDEXES = $indexes;

    }

    private function prepareBibleBooks() {

        $result1 = $this->mysqli->query( "SELECT * FROM biblebooks_fullname" );
        if ( $result1 ) {
            $cols = mysqli_num_fields( $result1 );
            $names = [];
            $finfo = mysqli_fetch_fields( $result1 );
            foreach ( $finfo as $val ) {
                $names[] = $val->name;
            }
            $result2 = $this->mysqli->query( "SELECT * FROM biblebooks_abbr" );
            if ( $result2 ) {
                $cols2 = mysqli_num_fields( $result2 );
                $rows2 = mysqli_num_rows( $result2 );
                $names2 = [];
                $finfo2 = mysqli_fetch_fields( $result2 );
                foreach ( $finfo2 as $val ) {
                    $names2[] = $val->name;
                }

                $n = 0;
                while ( $row1 = mysqli_fetch_assoc( $result1 ) ) {
                    $row2 = mysqli_fetch_assoc( $result2 );
                    $this->BIBLEBOOKS[$n] = [];

                    for ( $x = 1; $x < $cols; $x++ ) {
                        $temparray = [ $row1[$names[$x]], $row2[$names[$x]] ];

                        $arr1 = explode( " | ", $row1[$names[$x]] );
                        $booknames = array_map( 'self::normalizeBibleBook', $arr1 );

                        $arr2 = explode( " | ", $row2[$names[$x]] );
                        $abbrevs = ( count( $arr2 ) > 1 ) ? array_map( 'self::normalizeBibleBook', $arr2 ) : [];

                        $this->BIBLEBOOKS[$n][$x] = array_merge( $temparray, $booknames, $abbrevs );
                    }
                    $n++;
                }
            } else {
                $this->addErrorMessage( "<p>MySQL ERROR " . $this->mysqli->errno . ": " . $this->mysqli->error . "</p>" );
            }
        } else {
            $this->addErrorMessage( "<p>MySQL ERROR " . $this->mysqli->errno . ": " . $this->mysqli->error . "</p>" );
        }

    }

    private function prepareRequestedVersions() {

        $temp = isset( $this->DATA["version"] ) && $this->DATA["version"] !== "" ? explode( ",", strtoupper( $this->DATA["version"] ) ) : ["CEI2008"];

        foreach ( $temp as $version ) {
            if ( isset( $this->DATA["forceversion"] ) && $this->DATA["forceversion"] === "true" ) {
                $this->REQUESTED_VERSIONS[] = $version;
            } else {
                if ( $this->isValidVersion( $version ) ) {
                    $this->REQUESTED_VERSIONS[] = $version;
                } else {
                    $this->addErrorMessage( "Not a valid version: <" . $version . ">, valid versions are <" . implode( " | ", $this->VALID_VERSIONS ) . ">" );
                }
            }
            if ( isset( $this->DATA["forcecopyright"] ) && $this->DATA["forcecopyright"] === "true" ) {
                $this->REQUESTED_COPYRIGHTED_VERSIONS[] = $version;
            }
        }


        if ( count( $this->REQUESTED_VERSIONS ) < 1 ) {
            $this->outputResult();
        }

    }

    public function incrementBadQueryCount() {
        $this->mysqli->query( "UPDATE counter SET bad = bad + 1" );
    }

    public function incrementGoodQueryCount() {
        $this->mysqli->query( "UPDATE counter SET good = good + 1" );
    }

    private function writeEntryToDebugFile( string $entry ) {
        file_put_contents( $this->DEBUGFILE, date( 'r' ) . "\t" . $entry . PHP_EOL, FILE_APPEND | LOCK_EX );
    }


    public function Init() {

        switch( $this->responseContentType ){
            case "xml":
              header( 'Content-Type: application/xml; charset=utf-8' );
              break;
            case "json":
              header( 'Content-Type: application/json; charset=utf-8' );
              break;
            case "html":
              header( 'Content-Type: text/html; charset=utf-8' );
              break;
            default:
              header( 'Content-Type: application/json; charset=utf-8' );
        }

        $this->BibleQuoteInit();
        $this->dbConnect();
        $this->populateVersionsInfo();
        $this->prepareBibleBooks();
        $this->prepareRequestedVersions();
        $this->prepareIndexes();

        if ( isset( $this->DATA["query"] ) && $this->DATA["query"] !== "" ) {

            $this->queryStrClean();

            if( $this->detectedNotation === "MIXED" ){

                $this->addErrorMessage( "Mixed notations have been detected, please use either english or european notation." );
                $this->outputResult();

            }

            $QUERY_VALIDATOR = new QUERY_VALIDATOR( $this );

            if( $QUERY_VALIDATOR->ValidateQueries() === false ){

                $this->outputResult();

            } else {

                if ( !is_array( $this->validatedVariants ) ) {

                    $this->outputResult();

                } else {

                    // 3 -> TRANSLATE BIBLE NOTATION QUERIES TO MYSQL QUERIES
                    $SQL_QUERY_FORMULATOR = new QUERY_FORMULATOR( $this );
                    $SQL_QUERY_FORMULATOR->FormulateSQLQueries();
    
                    // 5 -> DO MYSQL QUERIES AND COLLECT RESULTS IN OBJECT 
                    $SQL_QUERY_EXECUTOR = new QUERY_EXECUTOR( $this );
                    $SQL_QUERY_EXECUTOR->ExecuteSQLQueries();
                    //$this->doQueries();
    
                    // 6 -> OUTPUT RESULTS FORMATTED ACCORDING TO REQUESTED RETURN TYPE
                    $this->outputResult();

                }

            }

        }

    }

}

class QUERY_VALIDATOR {

    private string $currentVariant      = "";
    private string $currentBook         = "";
    private int $bookIdxBase            = -1;
    private int $nonZeroBookIdx         = -1;
    private string $currentQuery        = "";
    private string $currentFullQuery    = "";
    private BIBLEGET_QUOTE $BBQUOTE;

    const QUERY_MUST_START_WITH_VALID_BOOK_INDICATOR                            = 0;
    const VALID_CHAPTER_MUST_FOLLOW_BOOK                                        = 1;
    const IS_VALID_BOOK_INDICATOR                                               = 2;
    const VERSE_SEPARATOR_MUST_BE_PRECEDED_BY_CHAPTER_VERSE_SEPARATOR           = 3;
    const VERSE_SEPARATOR_MUST_BE_PRECEDED_BY_1_TO_3_DIGITS                     = 4;
    const CHAPTER_VERSE_SEPARATOR_MUST_BE_PRECEDED_BY_1_TO_3_DIGITS             = 5;
    const VERSE_RANGE_MUST_CONTAIN_VALID_VERSE_NUMBERS                          = 6;
    const CORRESPONDING_CHAPTER_VERSE_CONSTRUCTS_IN_VERSE_RANGE_OVER_CHAPTERS   = 7;
    const CORRESPONDING_VERSE_SEPARATORS_FOR_MULTIPLE_VERSE_RANGES              = 8;

    function __construct( BIBLEGET_QUOTE $BBQUOTE ) {
        $this->BBQUOTE = $BBQUOTE;
    }

    static private function matchBookInQuery( string $query ) : array|bool {
        if( BIBLEGET_QUOTE::stringWithUpperAndLowerCaseVariants( $query ) ){
            if( preg_match( "/^([1-4]{0,1}((\p{Lu}\p{Ll}*)+))/u", $query, $res ) ){
                return $res;
            } else {
                return false;
            }
        } else {
            if( preg_match( "/^([1-4]{0,1}((\p{L}\p{M}*)+))/u", $query, $res ) ){
                return $res;
            } else {
                return false;
            }
        }
    }

    static private function validateRuleAgainstQuery( int $rule, string $query ) : bool {
        $validation = false;
        switch( $rule ){
            case self::QUERY_MUST_START_WITH_VALID_BOOK_INDICATOR :
                $validation = (preg_match( "/^[1-4]{0,1}\p{Lu}\p{Ll}*/u", $query ) || preg_match( "/^[1-4]{0,1}(\p{L}\p{M}*)+/u", $query ));
                break;
            case self::VALID_CHAPTER_MUST_FOLLOW_BOOK :
                if(BIBLEGET_QUOTE::stringWithUpperAndLowerCaseVariants( $query ) ){
                    $validation = ( preg_match( "/^[1-3]{0,1}\p{Lu}\p{Ll}*/u", $query ) == preg_match( "/^[1-3]{0,1}\p{Lu}\p{Ll}*[1-9][0-9]{0,2}/u", $query ) );
                } else {
                    $validation = ( preg_match( "/^[1-3]{0,1}( \p{L}\p{M}* )+/u", $query ) == preg_match( "/^[1-3]{0,1}(\p{L}\p{M}*)+[1-9][0-9]{0,2}/u", $query ) );
                }
                break;
            /*case self::IS_VALID_BOOK_INDICATOR :
                $validation = (1===1);
                break;*/
            case self::VERSE_SEPARATOR_MUST_BE_PRECEDED_BY_CHAPTER_VERSE_SEPARATOR :
                $validation = !( !strpos( $query, "," ) || strpos( $query, "," ) > strpos( $query, "." ) );
                break;
            case self::VERSE_SEPARATOR_MUST_BE_PRECEDED_BY_1_TO_3_DIGITS :
                $validation = ( preg_match_all( "/(?<![0-9])(?=([1-9][0-9]{0,2}\.[1-9][0-9]{0,2}))/", $query ) === substr_count( $query, "." ) );
                break;
            case self::CHAPTER_VERSE_SEPARATOR_MUST_BE_PRECEDED_BY_1_TO_3_DIGITS :
                $validation = ( preg_match_all( "/[1-9][0-9]{0,2}\,[1-9][0-9]{0,2}/", $query ) === substr_count( $query, "," ) );
                break;
            case self::VERSE_RANGE_MUST_CONTAIN_VALID_VERSE_NUMBERS :
                $validation = ( preg_match_all( "/[1-9][0-9]{0,2}\-[1-9][0-9]{0,2}/", $query ) === substr_count( $query, "-" ) );
                break;
            case self::CORRESPONDING_CHAPTER_VERSE_CONSTRUCTS_IN_VERSE_RANGE_OVER_CHAPTERS :
                $validation = !( preg_match( "/\-[1-9][0-9]{0,2}\,/", $query ) && ( !preg_match( "/\,[1-9][0-9]{0,2}\-/", $query ) || preg_match_all( "/(?=\,[1-9][0-9]{0,2}\-)/", $query ) > preg_match_all( "/(?=\-[1-9][0-9]{0,2}\,)/", $query ) ) );
                break;
            case self::CORRESPONDING_VERSE_SEPARATORS_FOR_MULTIPLE_VERSE_RANGES :
                $validation = !( substr_count( $query, "-" ) > 1 && ( !strpos( $query, "." ) || ( substr_count( $query, "-" ) - 1 > substr_count( $query, "." ) ) ) );
                break;
        }
        return $validation;
    }

    static private function queryContainsNonConsecutiveVerses( string $query ) : bool {
        return strpos( $query, "." ) !== false;
    }

    static private function forceArray( $element ) : array {
        if ( !is_array( $element ) ) {
            $element = [ $element ];
        }
        return $element;
    }

    static private function getAllVersesAfterDiscontinuousVerseIndicator( string $query ) : array {
        if( preg_match_all( "/\.([1-9][0-9]{0,2})$/", $query, $discontinuousVerses ) ){
            $discontinuousVerses[1] = self::forceArray( $discontinuousVerses[1] );
            return $discontinuousVerses;
        } else {
            return [[],[]];
        }
    }

    static private function getVerseAfterChapterVerseSeparator( string $query ) : array {
        if( preg_match( "/,([1-9][0-9]{0,2})/", $query, $verse ) ){
            return $verse;
        } else {
            return [[],[]];
        }
    }

    static private function chunkContainsChapterVerseConstruct( string $chunk ) : bool {
        return strpos( $chunk, "," ) !== false;
    }

    static private function getAllChapterIndicators( string $query ) : array {
        if( preg_match_all( "/([1-9][0-9]{0,2})\,/", $query, $chapterIndicators ) ){
            $chapterIndicators[1] = self::forceArray( $chapterIndicators[1] );
            return $chapterIndicators;
        } else {
            return ["",[]];
        }
    }

    private function validateAndSetBook( array|bool $matchedBook ) : bool {
        if ( $matchedBook !== false ) {
            $this->currentBook = $matchedBook[0];
            if ( $this->validateBibleBook() === false ) {
                return false;
            } else {
                $this->currentQuery = str_replace( $this->currentBook, "", $this->currentQuery );
                return true;
            }
        } else {
            return true;
        }
    }

    private function validateChapterVerseConstructs() : bool {
        $chapterVerseConstructCount = substr_count( $this->currentQuery, "," );
        if ( $chapterVerseConstructCount > 1 ) {
            return $this->validateMultipleVerseSeparators();
        } elseif ( $chapterVerseConstructCount == 1 ) {
            $parts = explode( ",", $this->currentQuery );
            if ( strpos( $parts[1], '-' ) ) {
                if( $this->validateRightHandSideOfVerseSeparator( $parts ) === false ) {
                    return false;
                }
            } else {
                if( $this->validateVersesAfterChapterVerseSeparators( $parts ) === false ){
                    return false;
                }
            }

            $discontinuousVerses = self::getAllVersesAfterDiscontinuousVerseIndicator( $this->currentQuery );
            $highverse = array_pop( $discontinuousVerses[1] );
            if( $this->highVerseOutOfBounds( $highverse, $parts ) ) {
                return false;
            }
        }
        return true;
    }

    private function queryViolatesAnyRuleOf( string $query, array $rules ) : bool {
        foreach( $rules as $rule ) {
            if( self::validateRuleAgainstQuery( $rule, $query ) === false ){
                $this->BBQUOTE->addErrorMessage( $rule );
                $this->BBQUOTE->incrementBadQueryCount();
                return true;
            }
        }
        return false;
    }

    private function isValidBookForVariant( string $variant ) : bool {
        return ( in_array( $this->currentBook, $this->BBQUOTE->INDEXES[$variant]["biblebooks"] ) || in_array( $this->currentBook, $this->BBQUOTE->INDEXES[$variant]["abbreviations"] ) );
    }

    private function validateBibleBook() : bool {
        $bookIsValid = false;
        foreach ( $this->BBQUOTE->REQUESTED_VERSIONS as $variant ) {
            if ( $this->isValidBookForVariant( $variant ) ) {
                $bookIsValid = true;
                $this->currentVariant = $variant;
                $this->bookIdxBase = $this->BBQUOTE::idxOf( $this->currentBook, $this->BBQUOTE->BIBLEBOOKS );
                break;
            }
        }
        if( !$bookIsValid ) {
            $this->bookIdxBase = $this->BBQUOTE::idxOf( $this->currentBook, $this->BBQUOTE->BIBLEBOOKS );
            if( $this->bookIdxBase !== false){
                $bookIsValid = true;
            } else {
                $this->BBQUOTE->addErrorMessage( sprintf( 'The book %s is not a valid Bible book. Please check the documentation for a list of correct Bible book names, whether full or abbreviated.', $this->currentBook ) );
                $this->BBQUOTE->incrementBadQueryCount();
            }
        }
        $this->nonZeroBookIdx = $this->bookIdxBase + 1;
        return $bookIsValid;
    }

    private function validateChapterIndicators( array $chapterIndicators ) : bool {

        foreach ( $chapterIndicators[1] as $chapterIndicator ) {
            foreach ( $this->BBQUOTE->INDEXES as $jkey => $jindex ) {
                $bookidx = array_search( $this->nonZeroBookIdx, $jindex["book_num"] );
                $chapter_limit = $jindex["chapter_limit"][$bookidx];
                if ( $chapterIndicator > $chapter_limit ) {
                    /* translators: the expressions <%1$d>, <%2$s>, <%3$s>, and <%4$d> must be left as is, they will be substituted dynamically by values in the script. See http://php.net/sprintf. */
                    $msg = 'A chapter in the query is out of bounds: there is no chapter <%1$d> in the book %2$s in the requested version %3$s, the last possible chapter is <%4$d>';
                    $this->BBQUOTE->addErrorMessage( sprintf( $msg, $chapterIndicator, $this->currentBook, $jkey, $chapter_limit ) );
                    $this->BBQUOTE->incrementBadQueryCount();
                    return false;
                }
            }
        }

        return true;

    }

    private function validateMultipleVerseSeparators() : bool {
        if ( !strpos( $this->currentQuery, '-' ) ) {
            $this->BBQUOTE->addErrorMessage( "You cannot have more than one comma and not have a dash!" );
            $this->BBQUOTE->incrementBadQueryCount();
            return false;
        }
        $parts = explode( "-", $this->currentQuery );
        if ( count( $parts ) != 2 ) {
            $this->BBQUOTE->addErrorMessage( "You seem to have a malformed querystring, there should be only one dash." );
            $this->BBQUOTE->incrementBadQueryCount();
            return false;
        }
        foreach ( $parts as $part ) {
            $pp = array_map( "intval", explode( ",", $part ) );
            foreach ( $this->INDEXES as $jkey => $jindex ) {
                $bookidx = array_search( $this->nonZeroBookIdx, $jindex["book_num"] );
                $chapters_verselimit = $jindex["verse_limit"][$bookidx];
                $verselimit = intval( $chapters_verselimit[$pp[0] - 1] );
                if ( $pp[1] > $verselimit ) {
                    $msg = 'A verse in the query is out of bounds: there is no verse <%1$d> in the book %2$s at chapter <%3$d> in the requested version %4$s, the last possible verse is <%5$d>';
                    $this->BBQUOTE->addErrorMessage( sprintf( $msg, $pp[1], $this->currentBook, $pp[0], $jkey, $verselimit ) );
                    $this->BBQUOTE->incrementBadQueryCount();
                    return false;
                }
            }
        }
        return true;
    }

    private function validateRightHandSideOfVerseSeparator( array $parts ) : bool {
        if ( preg_match_all( "/[,\.][1-9][0-9]{0,2}\-([1-9][0-9]{0,2})/", $this->currentQuery, $matches ) ) {
            $matches[1] = self::forceArray( $matches[1] );
            $highverse = intval( array_pop( $matches[1] ) );

            foreach ( $this->BBQUOTE->INDEXES as $jkey => $jindex ) {
                $bookidx = array_search( $this->nonZeroBookIdx, $jindex["book_num"] );
                $chapters_verselimit = $jindex["verse_limit"][$bookidx];
                $verselimit = intval( $chapters_verselimit[intval( $parts[0] ) - 1] );

                if ( $highverse > $verselimit ) {
                    /* translators: the expressions <%1$d>, <%2$s>, <%3$d>, <%4$s> and %5$d must be left as is, they will be substituted dynamically by values in the script. See http://php.net/sprintf. */
                    $msg = 'A verse in the query is out of bounds: there is no verse <%1$d> in the book %2$s at chapter <%3$d> in the requested version %4$s, the last possible verse is <%5$d>';
                    $this->BBQUOTE->addErrorMessage( sprintf( $msg, $highverse, $this->currentBook, $parts[0], $jkey, $verselimit ) );
                    $this->BBQUOTE->incrementBadQueryCount();
                    return false;
                }
            }
        }
        return true;
    }

    private function validateVersesAfterChapterVerseSeparators( array $parts ) : bool {
        $versesAfterChapterVerseSeparators = self::getVerseAfterChapterVerseSeparator( $this->currentQuery );

        $highverse = intval( $versesAfterChapterVerseSeparators[1] );
        foreach ( $this->INDEXES as $jkey => $jindex ) {
            $bookidx = array_search( $this->nonZeroBookIdx, $jindex["book_num"] );
            $chapters_verselimit = $jindex["verse_limit"][$bookidx];
            $verselimit = intval( $chapters_verselimit[intval( $parts[0] ) - 1] );
            if ( $highverse > $verselimit ) {
                /* translators: the expressions <%1$d>, <%2$s>, <%3$d>, <%4$s> and %5$d must be left as is, they will be substituted dynamically by values in the script. See http://php.net/sprintf. */
                $msg = 'A verse in the query is out of bounds: there is no verse <%1$d> in the book %2$s at chapter <%3$d> in the requested version %4$s, the last possible verse is <%5$d>';
                $this->BBQUOTE->addErrorMessage( sprintf( $msg, $highverse, $this->currentBook, $parts[0], $jkey, $verselimit ) );
                $this->BBQUOTE->incrementBadQueryCount();
                return false;
            }
        }
        return true;
    }

    private function highVerseOutOfBounds( $highverse, array $parts ) : bool {
        foreach ( $this->BBQUOTE->INDEXES as $jkey => $jindex ) {
            $bookidx = array_search( $this->nonZeroBookIdx, $jindex["book_num"] );
            $chapters_verselimit = $jindex["verse_limit"][$bookidx];
            $verselimit = intval( $chapters_verselimit[intval( $parts[0] ) - 1] );
            if ( $highverse > $verselimit ) {
                /* translators: the expressions <%1$d>, <%2$s>, <%3$d>, <%4$s> and %5$d must be left as is, they will be substituted dynamically by values in the script. See http://php.net/sprintf. */
                $msg = 'A verse in the query is out of bounds: there is no verse <%1$d> in the book %2$s at chapter <%3$d> in the requested version %4$s, the last possible verse is <%5$d>';
                $this->BBQUOTE->addErrorMessage( sprintf( $msg, $highverse, $this->currentBook, $parts[0], $jkey, $verselimit ) );
                $this->BBQUOTE->incrementBadQueryCount();
                return true;
            }
        }
        return false;
    }

    private function chapterOutOfBounds( array $chapters ) : bool {
        foreach ( $chapters as $zchapter ) {
            foreach ( $this->INDEXES as $jkey => $jindex ) {
                
                $bookidx = array_search( $this->nonZeroBookIdx, $jindex["book_num"] );
                $chapter_limit = $jindex["chapter_limit"][$bookidx];
                if ( intval( $zchapter ) > $chapter_limit ) {
                    $msg = 'A chapter in the query is out of bounds: there is no chapter <%1$d> in the book %2$s in the requested version %3$s, the last possible chapter is <%4$d>';
                    $this->BBQUOTE->addErrorMessage( sprintf( $msg, $zchapter, $this->currentBook, $jkey, $chapter_limit ) );
                    $this->BBQUOTE->incrementBadQueryCount();
                    return true;
                }
            }
        }
        return false;
    }

    public function ValidateQueries() : bool {
        //at least the first query must start with a book reference, which may have a number from 1 to 3 at the beginning
        //echo "matching against: ".$queries[0]."<br />";
        if ( $this->queryViolatesAnyRuleOf( $this->BBQUOTE->queries[0], [ self::QUERY_MUST_START_WITH_VALID_BOOK_INDICATOR ] ) ) {
            $this->BBQUOTE->outputResult();
        }


        foreach ( $this->BBQUOTE->queries as $query ) {
            $this->currentFullQuery = $query;
            $this->currentQuery = $query;

            if( $this->queryViolatesAnyRuleOf( $this->currentQuery, [ self::VALID_CHAPTER_MUST_FOLLOW_BOOK ] ) ){
                return false;
            }

            $matchedBook = self::matchBookInQuery( $this->currentQuery );
            if ( $this->validateAndSetBook( $matchedBook ) === false ) {
                continue;
            }

            if ( self::queryContainsNonConsecutiveVerses( $this->currentQuery ) ) {
                $rules = [ 
                    self::VERSE_SEPARATOR_MUST_BE_PRECEDED_BY_CHAPTER_VERSE_SEPARATOR,
                    self::VERSE_SEPARATOR_MUST_BE_PRECEDED_BY_1_TO_3_DIGITS
                ];
                if ( $this->queryViolatesAnyRuleOf( $this->currentQuery, $rules ) ) {
                    continue;
                }
            }

            if ( self::chunkContainsChapterVerseConstruct( $this->currentQuery ) ) {
                if ( $this->queryViolatesAnyRuleOf( $this->currentQuery, [ self::CHAPTER_VERSE_SEPARATOR_MUST_BE_PRECEDED_BY_1_TO_3_DIGITS ] ) ) {
                    continue;
                } else {
                    $chapterIndicators = self::getAllChapterIndicators( $this->currentQuery );
                    if( $this->validateChapterIndicators( $chapterIndicators ) === false ){
                        continue;
                    }

                    if( $this->validateChapterVerseConstructs() === false ){
                        continue;
                    }

                }
            } else {
                $chapters = explode( "-", $this->currentQuery );
                if( $this->chapterOutOfBounds( $chapters ) ){
                    continue;
                }
            }

            if ( strpos( $this->currentQuery, "-" ) ) {
                $rules = [
                    self::VERSE_RANGE_MUST_CONTAIN_VALID_VERSE_NUMBERS,
                    self::CORRESPONDING_CHAPTER_VERSE_CONSTRUCTS_IN_VERSE_RANGE_OVER_CHAPTERS,
                    self::CORRESPONDING_VERSE_SEPARATORS_FOR_MULTIPLE_VERSE_RANGES
                ];
                if ( $this->queryViolatesAnyRuleOf( $this->currentQuery, $rules ) ) {
                    continue;
                }
            }
            $this->BBQUOTE->validatedVariants[] = $this->currentVariant;
            $this->BBQUOTE->validatedQueries[]  = $this->currentFullQuery;

        } //END FOREACH
        return true;
    }
}

class QUERY_FORMULATOR {

    private int $nn                     = 0;
    private int $i                      = -1;
    private string $currentQuery        = "";
    private string $currentFullQuery    = "";
    private string $sqlQuery            = "";
    private string $previousBook        = "";
    private string $currentBook         = "";
    private string $currentVariant      = "";
    private string $currentPreferOrigin = "";
    private BIBLEGET_QUOTE $BBQUOTE;
    public array $sqlQueries            = [];
    public array $queriesVersions       = [];
    public array $originalQueries       = [];
    public array $queries               = [];

    function __construct( BIBLEGET_QUOTE $BBQUOTE ) {
        $this->BBQUOTE = $BBQUOTE;
        $this->queries = $BBQUOTE->validatedQueries;
    }

    static private function queryContainsNonConsecutiveVerses( string $query ) : bool {
        return strpos( $query, "." ) !== false;
    }

    static private function getNonConsecutiveChunks( string $query ) : array {
        return preg_split( "/\./", $query );
    }

    static private function chunkContainsRange( string $chunk ) : bool {
        return strpos( $chunk, "-" ) !== false;
    }

    static private function chunkContainsChapterVerseConstruct( string $chunk ) : bool {
        return strpos( $chunk, "," ) !== false;
    }

    static private function getRange( string $chunk ) : array {
        [ $from, $to ] = preg_split( "/\-/", $chunk );
        return [
            'from'  => $from,
            'to'    => $to
        ];
    }

    static private function getChapterVerseFromConstruct( string $construct ) : array {
        [ $chapter, $verse ] = preg_split( "/,/", $construct );
        return [
            'chapter'   => $chapter,
            'verse'     => $verse
        ];
    }

    static private function forceArray( $element ) : array {
        if ( !is_array( $element ) ) {
            $element = [ $element ];
        }
        return $element;
    }

    private function captureBookIndicator() : array|bool {
        if( $this->BBQUOTE::stringWithUpperAndLowerCaseVariants( $this->currentQuery ) ){
            if( preg_match( "/^([1-4]{0,1}((\p{Lu}\p{Ll}*)+))/u", $this->currentQuery, $res ) ){
                $this->currentQuery = preg_replace( "/^[1-4]{0,1}\p{Lu}\p{Ll}*/u", "", $this->currentQuery );
                return $res;
            } else {
                return false;
            }
        } else {
            if( preg_match( "/^([1-4]{0,1}((\p{L}\p{M}*)+))/u", $this->currentQuery, $res ) ){
                $this->currentQuery = preg_replace( "/^[1-4]{0,1}(\p{L}\p{M}*)+/u", "", $this->currentQuery );
                return $res;
            } else {
                return false;
            }
        }
    }

    private function validateVerseOriginPreference() {
        $this->currentPreferOrigin = "";
        //if we are dealing with a book that has greek and hebrew variants, we need to distinguish between the two
        if( $this->currentBook == 19 || $this->currentBook == "19" ){ 
            //if a protestant version is requested, it will only have HEBREW origin, not GREEK
            //if preferorigin is not explicitly set, but chapters 11-20 are requested for Esther,
            //then obviously the HEBREW version is being preferred, we will need to translate this request when we know which chapter we are dealing with
            if( in_array( $this->currentRequestedVariant,$this->BBQUOTE->CATHOLIC_VERSIONS ) ){
                $this->currentPreferOrigin = " AND verseorigin = '" . ( $this->BBQUOTE->DATA['preferorigin'] != "" ? $this->BBQUOTE->DATA['preferorigin'] : "GREEK" ) . "'";
            }
        }
    }

    private function initSQLStatement() {
        $this->sqlQuery = "SELECT * FROM " . $this->currentRequestedVariant . " WHERE book = " . $this->currentBook;
    }

    private function setSQLLimit() {
        if ( in_array( $this->currentRequestedVariant, $this->BBQUOTE->COPYRIGHT_VERSIONS ) ) {
            $this->sqlQueries[$this->nn] .= " LIMIT 30";
        }
    }

    private function finalizeQuery() {
        $this->sqlQueries[$this->nn] .= $this->currentPreferOrigin;
        $this->queriesVersions[$this->nn] = $this->currentRequestedVariant;
        //VERSES must be ordered by verseID in order to handle those cases where there are subverses ( usually Greek additions )
        //In these cases, the subverses sometimes come before, sometimes come after the "main" verse
        //Ex. Esther 1,1a-1r precedes Esther 1,1 but Esther 3,13 precedes Esther 3,13a-13g
        //Being this the case, it would not be possible to have coherent ordering by book,chapter,verse,verseequiv
        //The only solution is to make sure the verses are ordered correctly in the table with a unique verseID
        $this->sqlQueries[$this->nn] .= " ORDER BY verseID";
        //$this->sqlQueries[$this->nn] .= " ORDER BY book,chapter,verse,verseequiv";
        $this->setSQLLimit();
    }

    private function bestGuessBookIdx( array $matchedBook ) : int {
        $key1 = $this->currentVariant != "" ? array_search( $matchedBook[0], $this->BBQUOTE->INDEXES[$this->currentVariant]["biblebooks"] ) : false;
        $key2 = $this->currentVariant != "" ? array_search( $matchedBook[0], $this->BBQUOTE->INDEXES[$this->currentVariant]["abbreviations"] ) : false;
        $key3 = $this->BBQUOTE::idxOf( $matchedBook[0], $this->BBQUOTE->BIBLEBOOKS );
        if ( $key1 ) {
            return $this->BBQUOTE->INDEXES[$this->currentVariant]["book_num"][$key1];
        } else if ( $key2 ) {
            return $this->BBQUOTE->INDEXES[$this->currentVariant]["book_num"][$key2];
        } else if ( $key3 ) {
            return $key3 + 1;
        }
    }

    private function setBookAndVariant( array|bool $matchedBook ) {
        if ( $matchedBook ) {
            $this->currentVariant = $this->BBQUOTE->validatedVariants[ $this->i ];
            $this->currentBook = $this->bestGuessBookIdx( $matchedBook );
            $this->previousBook = $this->currentBook;
        } else {
            $this->currentBook = $this->previousBook;
        }
    }

    private function accountForMultipleChapterDifference( $cvConstructLeft, $cvConstructRight ) {
        if( $cvConstructRight['chapter'] - $cvConstructLeft['chapter'] > 1 ) {
            for( $d=1;$d<( $cvConstructRight['chapter'] - $cvConstructLeft['chapter'] );$d++ ){
                $this->sqlQueries[$this->nn] .= " OR ( chapter = " . ( $cvConstructLeft['chapter'] + $d ) . " )";
            }
        }
    }

    private function mapReference( int|string|null $chapter, int|string|null $verse, int|string|null &$toChapter, int|string|null &$toVerse, bool $updatePreferOrigin ) {
        $version        = $this->currentRequestedVariant;
        $book           = $this->currentBook;
        $preferorigin   = $this->currentPreferOrigin;

        if( in_array( $version, $this->BBQUOTE->CATHOLIC_VERSIONS ) ){
            if( $book == 19 || $book == "19" ){ //19 == Esther
                //first let's make sure that $fromChapter is a number
                if( gettype( $fromChapter ) == 'string' ){
                    //the USCCB uses letters A-F to indicate the Greek additions to Esther
                    //however, the BibleGet engine does not allow chapters that are not numbers
                    //therefore these verses have been added to the database in the same fashion as the CEI2008 layout
                    //TODO: see if there is any way of allowing letters as chapter indicators and then map them to the CEI2008 layout
                    $fromChapter = intval( $fromChapter ); 
                }
                if( $version == 'VGCL' || $version == 'DRB' ){
                    if( ( $chapter == 10 && ( ( $verse >= 4 && $verse <= 13 ) || $verse == null ) ) || ( $chapter == 11 && ( $verse == 1 || $verse == null ) ) ){
                        $chapter = 10;
                        $verse = 3;
                        $preferorigin = " AND verseorigin = 'GREEK'";
                    } else if( ( $chapter == 11 && ( ( $verse >= 2 && $verse <= 12 ) || $verse == null ) ) || ( $chapter == 12 && ( ( $verse >=1 && $verse <= 6 ) || $verse == null ) ) ){
                        $chapter = 1;
                        $verse = 1;
                        $preferorigin = " AND verseorigin = 'GREEK'";
                    } else if( $chapter == 13 && ( ( $verse >= 1 && $verse <= 7 ) || $verse == null ) ){
                        $chapter = 3;
                        $verse = 13;
                        $preferorigin = " AND verseorigin = 'GREEK'";
                    } else if( ( $chapter == 13 && ( ( $verse >= 8 && $verse <= 18 ) || $verse == null ) ) || ( $chapter == 14 && ( ( $verse <= 1 && $verse >= 19 ) || $verse == null ) ) ){
                        $chapter = 4;
                        $verse = 17;
                        $preferorigin = " AND verseorigin = 'GREEK'";
                    } else if( $chapter == 15 && ( ( $verse >= 1 && $verse <= 3 ) || $verse == null ) ){
                        $chapter = 4;
                        $verse = 8;
                        $preferorigin = " AND verseorigin = 'GREEK'";
                    } else if( $chapter == 15 && ( ( $verse >= 4 && $verse <= 14 ) || $verse == null ) ){
                        $chapter = 5;
                        $verse = 1;
                        $preferorigin = " AND verseorigin = 'GREEK'";
                    } else if( $chapter == 15 && ( ( $verse >= 15 && $verse <= 19 ) || $verse == null ) ){
                        $chapter = 5;
                        $verse = 2;
                        $preferorigin = " AND verseorigin = 'GREEK'";
                    } else if( $chapter == 16 ){
                        $chapter = 8;
                        $verse = 12;
                        $preferorigin = " AND verseorigin = 'GREEK'";
                    }
                }
            }
        }
        if( $updatePreferOrigin ){
            $this->currentPreferOrigin = $preferorigin;
        }
        $toChapter = $chapter;
        $toVerse = $verse;
    }

    public function FormulateSQLQueries(){
        foreach ( $this->BBQUOTE->REQUESTED_VERSIONS as $version ) {
            $this->i = 0;
            $this->currentRequestedVariant = $version;
            foreach ( $this->queries as $query ) {
                $this->currentQuery = $query;
                $this->currentFullQuery = $query;
                $this->currentChapter = "";

                // Retrieve and store the book in the query string,if applicable
                $matchedBook = $this->captureBookIndicator();

                $this->setBookAndVariant( $matchedBook );

                $this->initSQLStatement();

                $this->validateVerseOriginPreference();

                //NOTE: considering a dash can have multiple meanings (range of verses in same chapter, range of verses over chapters, range of chapters),
                //      whereas a non-consecutive verse indicator has just one meaning;
                //      also considering that the non-consecutive verse indicator has to do with the smallest unit in a Bible reference = verse,
                //      whereas a dash could have to do either with verses or with chapters;
                //      we start our interpretation of the Bible reference from what is clear and certain
                //      and is the smallest possible unit that we have to work with.
                //      So if there is there are non-consecutive verses, we start splitting up the reference around the non-consecutive verses indicator ( or indicators )
                //NOTE: We have already capture the book, so we are not dealing with the book in the following calculations
                //      However for our own sanity, the book is included in the examples to help understand what is happening
                //      This symbol will be used to indicate splitting into left hand and right hand sections: <=|=>

                if ( self::queryContainsNonConsecutiveVerses( $this->currentQuery ) ) {                        // EXAMPLE: John 3,16.18
                    $nonConsecutiveChunks = self::getNonConsecutiveChunks( $this->currentQuery );              // John 3,16 <=|=> 18
                    foreach ( $nonConsecutiveChunks as $chunk ) {
                        $this->originalQueries[$this->nn] = $this->currentFullQuery;
                        //IF the chunk is not simply a single verse, but is a range of consecutive VERSES or CHAPTERS 
                        //    ( EXAMPLE: John 3,16-18.20       OR John 3,16.18-20        OR John 3,16-18.20-22 )
                        //    (         John 3,16-18 <=|=> 20 OR John 3,16 <=|=> 18-20  OR John 3,16-18 <=|=> 20-22 )
                        if ( self::chunkContainsRange( $chunk ) ) {
                            $range = self::getRange( $chunk );
                            //IF we have a CHAPTER indicator on the left hand side of the range of consecutive verses
                            //  ( EXAMPLE: John 3,16-18.20 )
                            //  ( John 3,16-18 <=|=> 20 )
                            //  ( John 3 <=> 16-18 )
                            if ( self::chunkContainsChapterVerseConstruct( $range['from'] ) ) {
                                //THEN we capture the CHAPTER from the left hand side and the range of consecutive VERSEs from the right hand side
                                $cvConstructLeft = self::getChapterVerseFromConstruct( $range['from'] );
                                $this->currentChapter = $cvConstructLeft['chapter'];

                                $this->mapReference( $cvConstructLeft['chapter'], $cvConstructLeft['verse'], $cvConstructLeft['chapter'], $cvConstructLeft['verse'], true );

                                //IF we have a CHAPTER indicator on the right hand side of the range of consecutive verses
                                //  ( EXAMPLE: John 3,16-4,5.8 )
                                //  ( John 3,16 <=|=> 4,5 )
                                if ( self::chunkContainsChapterVerseConstruct( $range['to'] ) ) {
                                    //THEN we capture the CHAPTER from the left hand side and the range of consecutive VERSEs from the right hand side
                                    $cvConstructRight = self::getChapterVerseFromConstruct( $range['to'] );
                                    $this->currentChapter = $cvConstructRight['chapter'];

                                    $this->mapReference( $cvConstructRight['chapter'], $cvConstructRight['verse'], $cvConstructRight['chapter'], $cvConstructRight['verse'], true );

                                    $this->sqlQueries[$this->nn] = $this->sqlQuery . " AND ( ( chapter = " . $cvConstructLeft['chapter'] . " AND verse >= " . $cvConstructLeft['verse'] . " )";
                                    $this->accountForMultipleChapterDifference( $cvConstructLeft, $cvConstructRight );
                                    $this->sqlQueries[$this->nn] .= " OR ( chapter = " . $cvConstructRight['chapter'] . " AND verse <= " . $cvConstructRight['verse'] . " ) )";
                                }
                                //ELSEIF we do NOT have a CHAPTER indicator on the right hand side of the range of consecutive verses
                                // ( EXAMPLE: John 3,16-18.20 )
                                else {
                                    $this->mapReference( $this->currentChapter, $range['to'], $this->currentChapter, $range['to'], true );

                                    $this->sqlQueries[$this->nn] = $this->sqlQuery . " AND ( chapter >= " . $cvConstructLeft['chapter'] . " AND verse >= " . $cvConstructLeft['verse'] . " )";
                                    $this->sqlQueries[$this->nn] .= " AND ( chapter <= " . $this->currentChapter . " AND verse <= " . $range['to'] . " )";
                                }
                            }
                            //ELSEIF we DO NOT have a CHAPTER indicator on the left hand side of the range of consecutive verses
                            //  ( EXAMPLE: John 3,16.18-20 )
                            //  (  John 3,16 <=|=> 18-20 )
                            //  (  18 <=> 20 )
                            else {
                                $this->mapReference( $this->currentChapter, $range['from'], $this->currentChapter, $range['from'], true );
                                $this->mapReference( $this->currentChapter, $range['to'], $nullChapter, $range['to'], false );

                                $this->sqlQueries[$this->nn] = $this->sqlQuery . " AND ( chapter = " . $this->currentChapter . " AND verse >= " . $range['from'] . " AND verse <= " . $range['to'] . " )";
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
                            if ( self::chunkContainsChapterVerseConstruct( $chunk ) ) {
                                $cvConstruct = self::getChapterVerseFromConstruct( $chunk );
                                $this->currentChapter = $cvConstruct['chapter'];

                                $this->mapReference( $cvConstruct['chapter'], $cvConstruct['verse'], $cvConstruct['chapter'], $cvConstruct['verse'], true );

                                $this->sqlQueries[$this->nn] = $this->sqlQuery . " AND ( chapter = " . $cvConstruct['chapter'] . " AND verse = " . $cvConstruct['verse'] . " )";
                            } 
                            //ELSEIF the non consecutive chunk DOES NOT contain a chapter reference
                            //  ( EXAMPLE: John 3,16.18 )
                            //  (    John 3,16 <=|=> 18 )
                            //  ( 18 )
                            else {

                                $this->mapReference( $this->currentChapter, $chunk, $this->currentChapter, $chunk, true );

                                $this->sqlQueries[$this->nn] = $this->sqlQuery . " AND ( chapter = " . $this->currentChapter . " AND verse = " . $chunk . " )";
                            }
                        }

                        $this->finalizeQuery();
                        
                        $this->nn++;
                    }
                }
                else { //ELSEIF the request DOES NOT contain non-consecutive verses ( EXAMPLE: John 3,16 )
                    if ( self::chunkContainsRange( $this->currentQuery ) ) {    // EXAMPLE: John 3,16-18
                        $this->originalQueries[$this->nn] = $this->currentFullQuery;
                        $range = self::getRange( $chunk );                                        // John 3,16 <=|=> 18
                        //IF there is a chapter indicator on the left hand side of the range of consecutive verses
                        //    ( EXAMPLE: John 3,16-18 )
                        //    (    John 3,16 <=|=> 18 )
                        //    (    John 3,16 )
                        if ( self::chunkContainsChapterVerseConstruct( $range['from'] ) ) {
                            $cvConstructLeft = self::getChapterVerseFromConstruct( $range['from'] );
                            $this->currentChapter = $cvConstructLeft['chapter'];

                            $this->mapReference( $cvConstructLeft['chapter'], $cvConstructLeft['verse'], $cvConstructLeft['chapter'], $cvConstructLeft['verse'], true );

                            //IF there is also a chapter indicator on the right hand side of the range of consecutive verses
                            //    ( EXAMPLE: John 3,16-4,5 )
                            //    (    John 3,16 <=|=> 4,5 )
                            //    (    4,5 )
                            if ( self::chunkContainsChapterVerseConstruct( $range['to'] ) ) {
                                $cvConstructRight = self::getChapterVerseFromConstruct( $range['to'] );

                                $this->mapReference( $cvConstructRight['chapter'], $cvConstructRight['verse'], $cvConstructRight['chapter'], $cvConstructRight['verse'], true );

                                $this->sqlQueries[$this->nn] = $this->sqlQuery . " AND ( ( chapter = " . $cvConstructLeft['chapter'] . " AND verse >= " . $cvConstructLeft['verse'] . " )";
                                //what if the difference between chapters is greater than 1? Say: John 3,16-6,2 ?
                                $this->accountForMultipleChapterDifference( $cvConstructLeft, $cvConstructRight );
                                $this->sqlQueries[$this->nn] .= " OR ( chapter = " . $cvConstructRight['chapter'] . " AND verse <= " . $cvConstructRight['verse'] . " ) )";
                            }
                            //ELSEIF there is NOT a chapter indicator on the right hand side of the range of consecutive verses
                            //    ( EXAMPLE: John 3,16-18 )
                            //    (    John 3,16 <=|=> 18 )
                            //    (    18 )
                            else {
                              $this->sqlQueries[$this->nn] = $this->sqlQuery . " AND chapter >= " . $cvConstructLeft['chapter'] . " AND verse >= " . $cvConstructLeft['verse'];

                              $this->mapReference( $cvConstructLeft['chapter'], $range['to'], $mappedChapter, $range['to'], true );

                              $this->sqlQueries[$this->nn] .= " AND chapter <= " . $mappedChapter . " AND verse <= " . $range['to'];
                            }
                        }
                        //ELSEIF there is NOT a chapter/verse indicator on the left hand side of the range of consecutive verses OR chapters
                        //    this means that we are dealing with consecutive CHAPTERS and not VERSES
                        //     ( EXAMPLE: John 3-4 )
                        //     ( EXAMPLE: 3 <=|=> 4 )
                        else {
                            $this->mapReference( $range['from'], null, $range['from'], $nullVerse, true );
                            $this->mapReference( $range['to'], null, $range['to'], $nullVerse, false );

                            $this->sqlQueries[$this->nn] = $this->sqlQuery . " AND chapter >= " . $range['from'] . " AND chapter <= " . $range['to'];
                        }
                    }
                    //ELSEIF the request DOES NOT contain a range of consecutive verses OR chapters
                    //    ( EXAMPLE: John 3,16 )
                    else {
                        //IF we DO have a chapter/verse indicator
                        if ( self::chunkContainsChapterVerseConstruct( $this->currentQuery ) ) {
                            $this->originalQueries[$this->nn] = $this->currentFullQuery;
                            $cvConstruct = self::getChapterVerseFromConstruct( $this->currentQuery );
                            $this->currentChapter = $cvConstruct['chapter'];

                            $this->mapReference( $cvConstruct['chapter'], $cvConstruct['verse'], $cvConstruct['chapter'], $cvConstruct['verse'], true );

                            $this->sqlQueries[$this->nn] = $this->sqlQuery . " AND chapter = " . $cvConstruct['chapter'] . " AND verse = " . $cvConstruct['verse'];
                        } 
                        //ELSEIF we are dealing with just a single chapter
                        //    ( EXAMPLE: John 3 )
                        else {
                            $this->originalQueries[$this->nn] = $this->currentFullQuery;
                            $this->currentChapter = $this->currentQuery;

                            $this->mapReference( $this->currentChapter, null, $mappedChapter, $nullVerse, true );

                            $this->sqlQueries[$this->nn] = $this->sqlQuery . " AND chapter = " . $mappedChapter;
                        }
                    }

                    $this->finalizeQuery();

                    $this->nn++;
                }

                $this->i++;
            }

        }

        $this->BBQUOTE->formulatedQueries = $this->sqlQueries;
        $this->BBQUOTE->originalQueries = $this->originalQueries;
        $this->BBQUOTE->formulatedVariants = $this->queriesVersions;
    }

}

class QUERY_EXECUTOR {

    private BIBLEGET_QUOTE $BBQUOTE;
    private array $sqlqueries       = [];
    private array $queriesversions  = [];
    private array $currentRow       = [];
    private array $currentResponse  = [];
    private string $appid           = "";
    private string $domain          = "";
    private string $pluginversion   = "";
    private string $ipaddress       = "";
    private string $forwardedip     = "";
    private string $remote_address  = "";
    private string $realip          = "";
    private string $clientip        = "";
    private string $xquery          = "";
    private string $curYEAR         = "";
    private string $geoip_json      = "";
    private int $i                  = 0;
    private bool $haveIPAddressOnRecord = false;
    private mysqli_result $currentExecutionResult;

    // First we initialize some variables and flags with default values
    private string $version         = "";
    private string $book            = "";
    private int $chapter            = 0;
    private string $verse           = "";
    private bool $newversion        = false;
    private bool $newbook           = false;
    private bool $newchapter        = false;
    private bool $newverse          = false;

    function __construct( BIBLEGET_QUOTE $BBQUOTE ) {
        $this->BBQUOTE              = $BBQUOTE;
        $this->sqlqueries           = $BBQUOTE->formulatedQueries;
        $this->queriesversions      = $BBQUOTE->formulatedVariants;
        $this->appid                = $BBQUOTE->DATA["appid"]          != "" ? $BBQUOTE->DATA["appid"]            : "unknown";
        $this->domain               = $BBQUOTE->DATA["domain"]         != "" ? $BBQUOTE->DATA["domain"]           : "unknown";
        $this->pluginversion        = $BBQUOTE->DATA["pluginversion"]  != "" ? $BBQUOTE->DATA["pluginversion"]    : "unknown";
        $this->curYEAR              = date( 'Y' );
    }

    static private function validateIPAddress( string $ipaddress ) : string|bool {
        return filter_var( $ipaddress, FILTER_VALIDATE_IP );
    }

    private function getAndValidateIpAddress() {
        $this->forwardedip = isset( $_SERVER["HTTP_X_FORWARDED_FOR"] ) ? $_SERVER["HTTP_X_FORWARDED_FOR"] : "";
        $this->remote_address = isset( $_SERVER["REMOTE_ADDR"] ) ? $_SERVER["REMOTE_ADDR"] : "";
        $this->realip = isset( $_SERVER["HTTP_X_REAL_IP"] ) ? $_SERVER["HTTP_X_REAL_IP"] : "";
        $this->clientip = isset( $_SERVER["HTTP_CLIENT_IP"] ) ? $_SERVER["HTTP_CLIENT_IP"] : "";

        //Do our best to identify an IP address associated with the incoming request, 
        //trying first HTTP_X_FORWARDED_FOR, then REMOTE_ADDR and last resort HTTP_X_REAL_IP
        //This is useful only to protect against high volume requests from specific IP addresses or referers
        $this->ipaddress = $this->forwardedip != "" ? explode( ",", $this->forwardedip )[0] : "";
        if ( $this->ipaddress == "" ) {
            $this->ipaddress = $this->remote_address != "" ? $this->remote_address : "";
        }
        if ( $this->ipaddress == "" ) {
            $this->ipaddress = $this->realip != "" ? $this->realip : "";
        }

        if ( self::validateIPAddress( $this->ipaddress ) === false ) {
            $this->BBQUOTE->addErrorMessage( "The BibleGet API endpoint cannot be used behind a proxy that hides the IP address from which the request is coming. No personal or sensitive data is collected by the API, however IP addresses are monitored to prevent spam requests. If you believe there is an error because this is not the case, please contact the developers so they can look into the situtation.", $this->xquery );
            $this->BBQUOTE->outputResult();
        }
    }

    private function isWhitelisted( string $domainOrIP ) : int|bool {
        return array_search( $domainOrIP, $this->BBQUOTE->WhitelistedDomainsIPs );
    }

    private function checkIPAddressPastTwoDaysWithSameRequest() {
        $ipresult = $this->ipaddress != "" ? $this->BBQUOTE->mysqli->query( "SELECT * FROM requests_log__" . $this->curYEAR . " WHERE WHO_IP = INET6_ATON( '" . $this->ipaddress . "' ) AND QUERY = '" . $this->xquery . "'  AND WHO_WHEN > DATE_SUB( NOW(), INTERVAL 2 DAY )" ) : false;
        if ( $ipresult ) {
            if ( $this->BBQUOTE->DEBUG_IPINFO === true ) {
                file_put_contents( $this->BBQUOTE->DEBUGFILE, "We have seen the IP Address [" . $this->ipaddress . "] in the past 2 days with this same request [" . $this->xquery . "]" . PHP_EOL, FILE_APPEND | LOCK_EX );
            }
            //if more than 10 times in the past two days ( but less than 30 ) simply add message inviting to use cacheing mechanism
            if ( $ipresult->num_rows > 10 && $ipresult->num_rows < 30 ) {
                $this->BBQUOTE->addErrorMessage( 10, $this->xquery );
                $iprow = $ipresult->fetch_assoc();
                $this->geoip_json = $iprow[ "WHO_WHERE_JSON" ];
                $this->haveIPAddressOnRecord = true;
            }
            //if we have more than 30 requests in the past two days for the same query, deny service?
            else if ( $ipresult->num_rows > 29 ) {
                $this->BBQUOTE->addErrorMessage( 11, $xquery );
                $this->BBQUOTE->outputResult(); //this should exit the script right here, closing the mysql connection
            }
        }

    }

    //and if the same IP address is making too many requests( >100? ) with different queries ( like copying the bible texts completely ), deny service
    private function checkQueriesFromSameIPAddress() {
        $ipresult = $this->ipaddress != "" ? $this->BBQUOTE->mysqli->query( "SELECT * FROM requests_log__" . $this->curYEAR . " WHERE WHO_IP = INET6_ATON( '" . $this->ipaddress . "' ) AND WHO_WHEN > DATE_SUB( NOW(), INTERVAL 2 DAY )" ) : false;
        if ( $ipresult ) {
            if ( $this->BBQUOTE->DEBUG_IPINFO === true ) {
                file_put_contents( $this->BBQUOTE->DEBUGFILE, "We have seen the IP Address [" . $this->ipaddress . "] in the past 2 days with many different requests" . PHP_EOL, FILE_APPEND | LOCK_EX );
            }
            //if we 50 or more requests in the past two days, deny service?
            if ( $ipresult->num_rows > 100 ) {
                if ( $this->BBQUOTE->DEBUG_IPINFO === true ) {
                    file_put_contents( $this->BBQUOTE->DEBUGFILE, "We have seen the IP Address [" . $this->ipaddress . "] in the past 2 days with over 50 requests", FILE_APPEND | LOCK_EX );
                }
                $this->BBQUOTE->addErrorMessage( 12, $this->xquery );
                $this->BBQUOTE->outputResult(); //this should exit the script right here, closing the mysql connection
            }
        }
    }

    //let's add another check for "referer" websites and how many similar requests have derived from the same origin in the past couple days
    private function checkRequestsFromSameOrigin() {
        $originres = $this->BBQUOTE->mysqli->query( "SELECT ORIGIN,COUNT( * ) AS ORIGIN_CNT FROM requests_log__" . $this->curYEAR . " WHERE ORIGIN != '' AND ORIGIN = '" . $this->BBQUOTE->originHeader . "' AND QUERY = '" . $this->xquery . "' AND WHO_WHEN > DATE_SUB( NOW(), INTERVAL 2 DAY ) GROUP BY ORIGIN" );
        if ( $originres ) {
            if ( $originres->num_rows > 0 ) {
                $originRow = $originres->fetch_assoc();
                if ( array_key_exists( "ORIGIN_CNT", $originRow ) ) {
                    if ( $originRow["ORIGIN_CNT"] > 10 && $originRow["ORIGIN_CNT"] < 30 ) {
                        $this->BBQUOTE->addErrorMessage( 10, $this->xquery );
                    } else if ( $originRow["ORIGIN_CNT"] > 29 ) {
                        $this->BBQUOTE->addErrorMessage( 11, $this->xquery );
                        $this->BBQUOTE->outputResult(); //this should exit the script right here, closing the mysql connection
                    }
                }
            }
        }
    }

    //and we'll check for diverse requests from the same origin in the past couple days ( >100? )
    private function checkDiverseRequestsFromSameOrigin() {
        $originres = $this->BBQUOTE->mysqli->query( "SELECT ORIGIN,COUNT( * ) AS ORIGIN_CNT FROM requests_log__" . $this->curYEAR . " WHERE ORIGIN != '' AND ORIGIN = '" . $this->BBQUOTE->originHeader . "' AND WHO_WHEN > DATE_SUB( NOW(), INTERVAL 2 DAY ) GROUP BY ORIGIN" );
        if ( $originres ) {
            if ( $originres->num_rows > 0 ) {
                $originRow = $originres->fetch_assoc();
                if ( array_key_exists( "ORIGIN_CNT", $originRow ) ) {
                    if ( $originRow["ORIGIN_CNT"] > 100 ) {
                        $this->BBQUOTE->addErrorMessage( 12, $this->xquery );
                        $this->BBQUOTE->outputResult(); //this should exit the script right here, closing the mysql connection
                    }
                }
            }
        }
    }

    private function enforceQueryLimits() {

        $this->checkIPAddressPastTwoDaysWithSameRequest();

        $this->checkQueriesFromSameIPAddress();

        $this->checkRequestsFromSameOrigin();

        $this->checkDiverseRequestsFromSameOrigin();

    }

    private function getGeoIpInfo() {
        $ch = curl_init( "https://ipinfo.io/" . $this->ipaddress . "?token=" . IPINFO_ACCESS_TOKEN );
        curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
        $this->geoip_json = curl_exec( $ch );
        if ( $this->geoip_json === false ) {
            $this->BBQUOTE->mysqli->query( "INSERT INTO curl_error ( ERRNO,ERROR ) VALUES( " . curl_errno( $ch ) . ",'" . curl_error( $ch ) . "' )" );
        }
        //Check the status of communication with ipinfo.io server
        $http_status = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
        curl_close( $ch );

        if ( $http_status == 429 ) {
            $this->geoip_json = '{"ERROR":"api limit exceeded"}';
        } else if ( $http_status == 200 ) {
            //Clean geopip_json object, ensure it is valid in any case
            //$this->geoip_json = $this->mysqli->real_escape_string( $this->geoip_json); // we don't need to escape it when it's coming from the ipinfo.io server, at least not before inserting into the database
            //Check if it's actually an object or if it's not a string perhaps
            $geoip_JSON_obj = json_decode( $this->geoip_json );
            if ( $geoip_JSON_obj === null || json_last_error() !== JSON_ERROR_NONE ) {
                //we have a problem with our geoip_json, it's probably a string with an error. We should already have escaped it           
                $this->geoip_json = '{"ERROR":"' . json_last_error() . ' <' . $this->geoip_json . '>"}';
            } else {
                $this->geoip_json = json_encode( $geoip_JSON_obj );
            }
        } else {
            $this->geoip_json = '{"ERROR":"wrong http status > ' . $http_status . '"}';
        }
    }

    private function getGeoIPInfoFromLogsElseOnline() {

        $geoIPFromLogs = $this->getGeoIPFromLogs();
        if ( $geoIPFromLogs !== false ) {
            if ( $this->haveGeoIPResultsFromLogs( $geoIPFromLogs ) ) {
                if ( $this->BBQUOTE->DEBUG_IPINFO === true ) {
                    file_put_contents( $this->DEBUGFILE, "We already have valid geo_ip info [" . $this->geoip_json. "] for the IP address [" . $this->ipaddress . "], reusing" . PHP_EOL, FILE_APPEND | LOCK_EX );
                }
                $iprow = $geoIPFromLogs->fetch_assoc();
                $this->geoip_json = $iprow["WHO_WHERE_JSON"];
                $this->haveIPAddressOnRecord = true;
            } else {
                if ( $this->BBQUOTE->DEBUG_IPINFO === true ) {
                    file_put_contents( $this->BBQUOTE->DEBUGFILE, "We do not yet have valid geo_ip info [" . $this->geoip_json. "] for the IP address [" . $this->ipaddress . "], nothing to reuse" . PHP_EOL, FILE_APPEND | LOCK_EX );
                }
                $this->getGeoIpInfo();
                if ( $this->BBQUOTE->DEBUG_IPINFO === true ) {
                    file_put_contents( $this->BBQUOTE->DEBUGFILE, "We have attempted to get geo_ip info [" . $this->geoip_json. "] for the IP address [" . $this->ipaddress . "] from ipinfo.io" . PHP_EOL, FILE_APPEND | LOCK_EX );
                }
            }
        } else if ( $this->ipaddress != "" ) {
            if ( $this->BBQUOTE->DEBUG_IPINFO === true ) {
                file_put_contents( $this->BBQUOTE->DEBUGFILE, "We do however seem to have a valid IP address [" . $this->ipaddress . "] , now trying to fetch info from ipinfo.io" . PHP_EOL, FILE_APPEND | LOCK_EX );
            }
            $this->getGeoIpInfo();
            if ( $this->BBQUOTE->DEBUG_IPINFO === true ) {
                file_put_contents( $this->BBQUOTE->DEBUGFILE, "Even in this case we have attempted to get geo_ip info [" . $this->geoip_json. "] for the IP address [" . $this->ipaddress . "] from ipinfo.io" . PHP_EOL, FILE_APPEND | LOCK_EX );
            }
        }

    }

    private function normalizeErroredGeoIPInfo() {
        if ( $this->geoip_json === "" || $this->geoip_json === null ) {
            $this->geoip_json = '{"ERROR":""}';
        }
    }

    private function logQuery() {
        $stmt = $this->BBQUOTE->mysqli->prepare( "INSERT INTO requests_log__" . $this->curYEAR . " ( WHO_IP,WHO_WHERE_JSON,HEADERS_JSON,ORIGIN,QUERY,ORIGINALQUERY,REQUEST_METHOD,HTTP_CLIENT_IP,HTTP_X_FORWARDED_FOR,HTTP_X_REAL_IP,REMOTE_ADDR,APP_ID,DOMAIN,PLUGINVERSION ) VALUES ( INET6_ATON( ? ), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ? )" );
        $stmt->bind_param( 'ssssssssssssss', $this->ipaddress, $this->geoip_json, $this->BBQUOTE->jsonEncodedRequestHeaders, $this->BBQUOTE->originHeader, $this->xquery, $this->BBQUOTE->originalQueries[$this->i], $this->BBQUOTE->requestMethod, $this->clientip, $this->forwardedip, $this->realip, $this->remote_address, $this->appid, $this->domain, $this->pluginversion );
        if ( $stmt->execute() === false ) {
            $this->BBQUOTE->addErrorMessage( "There has been an error updating the logs: ( " . $this->BBQUOTE->mysqli->errno . " ) " . $this->BBQUOTE->mysqli->error );
        }
        $stmt->close();
    }


    private function geoIPInfoIsEmptyOrIsError() : bool|int {
        $pregmatch = preg_quote( '{"ERROR":"', '/' );
        return $this->haveIPAddressOnRecord === false || $this->geoip_json == "" || $this->geoip_json === null || preg_match( "/" . $pregmatch . "/", $this->geoip_json );
    }

    private function getGeoIPFromLogs() : mysqli_result|bool {
        if( $this->ipaddress != "" ){
            return $this->BBQUOTE->mysqli->query( "SELECT * FROM requests_log__" . $this->curYEAR . " WHERE WHO_IP = INET6_ATON( '" . $this->ipaddress . "' ) AND WHO_WHERE_JSON NOT LIKE '{\"ERROR\":\"%\"}'" );
        } else {
            return false;
        }
    }

    private function haveGeoIPResultsFromLogs( $geoIPFromLogs ) : bool {
        return $geoIPFromLogs->num_rows > 0;
    }

    private function fillIPAddressIfEmpty() {
        $this->ipaddress = $this->ipaddress != "" ? $this->ipaddress : "0.0.0.0";
    }

    private function prepareResponse() {
        $currentVariant = $this->queriesversions[$this->i];
        $row = $this->currentRow;

        $row["version"]             = strtoupper( $currentVariant );
        $row["testament"]           = ( int )$row["testament"];

        $universal_booknum          = $row["book"];
        $booknum                    = array_search( $row["book"], $this->BBQUOTE->INDEXES[$currentVariant]["book_num"] );
        $row["bookabbrev"]          = $this->BBQUOTE->INDEXES[$currentVariant]["abbreviations"][$booknum];
        $row["booknum"]             = $booknum;
        $row["univbooknum"]         = $universal_booknum;
        $row["book"]                = $this->BBQUOTE->INDEXES[$currentVariant]["biblebooks"][$booknum];

        $row["section"]             = ( int ) $row["section"];
        unset( $row["verseID"] );
        $row["chapter"]             = ( int ) $row["chapter"];
        $row["originalquery"]       = $this->BBQUOTE->originalQueries[$this->i];

        $this->currentResponse = $row;
    }

    private function generateResponse() {

        $response = $this->currentResponse;

        if ( $this->BBQUOTE->responseContentType == "xml" ) {

            $thisrow = $this->BBQUOTE->bibleQuote->results->addChild( "result" );
            foreach ( $response as $key => $value ) {
                $thisrow[$key] = $value;
            }

        } elseif ( $this->BBQUOTE->responseContentType == "json" ) {

            $this->BBQUOTE->bibleQuote->results[] = $response;

        } elseif ( $this->BBQUOTE->responseContentType == "html" ) {

            if ( $response["verse"] != $this->verse ) {
                $this->newverse = true;
                $this->verse = $response["verse"];
            } else {
                $this->newverse = false;
            }

            if ( $response["chapter"] != $this->chapter ) {
                $this->newchapter = true;
                $this->newverse = true;
                $this->chapter = $response["chapter"];
            } else {
                $this->newchapter = false;
            }

            if ( $response["book"] != $this->book ) {
                $this->newbook = true;
                $this->newchapter = true;
                $this->newverse = true;
                $this->book = $response["book"];
            } else {
                $this->newbook = false;
            }

            if ( $response["version"] != $this->version ) {
                $this->newversion = true;
                $this->newbook = true;
                $this->newchapter = true;
                $this->newverse = true;
                $this->version = $response["version"];
            } else {
                $this->newversion = false;
            }

            if ( $this->newversion ) {
                $variant = $this->BBQUOTE->bibleQuote->createElement( "p", $response["version"] );
                if ( $this->i > 0 ) {
                    $br = $this->BBQUOTE->bibleQuote->createElement( "br" );
                    $variant->insertBefore( $br, $variant->firstChild );
                }
                $variant->setAttribute( "class", "version bibleVersion" );
                $this->BBQUOTE->div->appendChild( $variant );
            }

            if ( $this->newbook || $this->newchapter ) {
                $citation = $this->BBQUOTE->bibleQuote->createElement( "p", $response["book"] . "&nbsp;" . $response["chapter"] );
                $citation->setAttribute( "class", "book bookChapter" );
                $this->BBQUOTE->div->appendChild( $citation );
                $citation1 = $this->BBQUOTE->bibleQuote->createElement( "p" );
                $citation1->setAttribute( "class", "verses versesParagraph" );
                $this->BBQUOTE->div->appendChild( $citation1 );
                $metainfo = $this->BBQUOTE->bibleQuote->createElement( "input" );
                $metainfo->setAttribute( "type", "hidden" );
                $metainfo->setAttribute( "class", "originalQueries" );
                $metainfo->setAttribute( "value", $response["originalquery"] );
                $this->BBQUOTE->div->appendChild( $metainfo );
                $metainfo1 = $this->BBQUOTE->bibleQuote->createElement( "input" );
                $metainfo1->setAttribute( "type", "hidden" );
                $metainfo1->setAttribute( "class", "bookAbbrev" );
                $metainfo1->setAttribute( "value", $response["bookabbrev"] );
                $this->BBQUOTE->div->appendChild( $metainfo1 );
                $metainfo2 = $this->BBQUOTE->bibleQuote->createElement( "input" );
                $metainfo2->setAttribute( "type", "hidden" );
                $metainfo2->setAttribute( "class", "bookNum" );
                $metainfo2->setAttribute( "value", $response["booknum"] );
                $this->BBQUOTE->div->appendChild( $metainfo2 );
                $metainfo3 = $this->BBQUOTE->bibleQuote->createElement( "input" );
                $metainfo3->setAttribute( "type", "hidden" );
                $metainfo3->setAttribute( "class", "univBookNum" );
                $metainfo3->setAttribute( "value", $response["univbooknum"] );
                $this->BBQUOTE->div->appendChild( $metainfo3 );
            }
            if ( $this->newverse ) {
                $versicle = $this->BBQUOTE->bibleQuote->createElement( "span", $response["verse"] );
                $versicle->setAttribute( "class", "sup verseNum" );
                $citation1->appendChild( $versicle );
            }

            $text = $this->BBQUOTE->bibleQuote->createElement( "span", $response["text"] );
            $text->setAttribute( "class", "text verseText" );
            $citation1->appendChild( $text );

        }
    }


    private function handleCurrentExecutionResult() {
        if ( $this->currentExecutionResult !== false ) {

            $this->BBQUOTE->incrementGoodQueryCount();

            if ( $this->geoIPInfoIsEmptyOrIsError() ) {
                if ( $this->BBQUOTE->DEBUG_IPINFO === true ) {
                    file_put_contents( $this->BBQUOTE->DEBUGFILE, "Either we have not yet seen the IP address [" . $this->ipaddress . "] in the past 2 days or we have no geo_ip info [" . $this->geoip_json. "]" . PHP_EOL, FILE_APPEND | LOCK_EX );
                }
                $this->getGeoIPInfoFromLogsElseOnline();
            }

            $this->fillIPAddressIfEmpty();

            $this->normalizeErroredGeoIPInfo();

            $this->logQuery();

            $this->verse = "";
            $this->newverse = false;
            while ( $row = $this->currentExecutionResult->fetch_assoc() ) {

                $this->currentRow = $row;
                $this->prepareResponse();
                $this->generateResponse();

            }

        } else {
            $this->BBQUOTE->addErrorMessage( 9, $this->xquery );
        }
    }

    public function ExecuteSQLQueries() {
        $this->getAndValidateIpAddress();

        $notWhitelisted = ( $this->isWhitelisted( $this->domain ) === false && $this->isWhitelisted( $this->ipaddress ) === false );

        foreach ( $this->sqlqueries as $xquery ) {

            $this->xquery = $xquery;

            if ( $notWhitelisted ) {
                $this->enforceQueryLimits();
            }

            $this->currentExecutionResult = $this->BBQUOTE->mysqli->query( $xquery );
            $this->handleCurrentExecutionResult();
            $this->i++;
        }

    }
}


if( isset( $_SERVER['CONTENT_TYPE'] ) && !in_array( $_SERVER['CONTENT_TYPE'], BIBLEGET_QUOTE::$allowedContentTypes ) ){
    header( $_SERVER["SERVER_PROTOCOL"]." 415 Unsupported Media Type", true, 415 );
    die( '{"error":"You seem to be forming a strange kind of request? Allowed Content Types are '.implode( ' and ',BIBLEGET_QUOTE::$allowedContentTypes ).', but your Content Type was '.$_SERVER['CONTENT_TYPE'].'"}' );
} else if ( isset( $_SERVER['CONTENT_TYPE'] ) && $_SERVER['CONTENT_TYPE'] === 'application/json' ) {
    $json = file_get_contents( 'php://input' );
    $data = json_decode( $json,true );
    if( NULL === $json || "" === $json ){
        header( $_SERVER["SERVER_PROTOCOL"]." 400 Bad Request", true, 400 );
        die( '{"error":"No JSON data received in the request: <' . $json . '>"' );
    } else if ( json_last_error() !== JSON_ERROR_NONE ) {
        header( $_SERVER["SERVER_PROTOCOL"]." 400 Bad Request", true, 400 );
        die( '{"error":"Malformed JSON data received in the request: <' . $json . '>, ' . json_last_error_msg() . '"}' );
    } else {
        $BIBLEQUOTE = new BIBLEGET_QUOTE( $data );
        //$BIBLEQUOTE->DEBUG_REQUESTS = true;
        $BIBLEQUOTE->Init();
    }
} else {
  switch( strtoupper( $_SERVER["REQUEST_METHOD"] ) ) {
      case 'POST':
          $BIBLEQUOTE = new BIBLEGET_QUOTE( $_POST );
          //$BIBLEQUOTE->DEBUG_REQUESTS = true;
          $BIBLEQUOTE->Init();
          break;
      case 'GET':
          $BIBLEQUOTE = new BIBLEGET_QUOTE( $_GET );
          //$BIBLEQUOTE->DEBUG_REQUESTS = true;
          $BIBLEQUOTE->Init();
          break;
      default:
          header( $_SERVER["SERVER_PROTOCOL"]." 405 Method Not Allowed", true, 405 );
          die( '{"error":"You seem to be forming a strange kind of request? Allowed Request Methods are '.implode( ' and ',BIBLEGET_QUOTE::$allowedRequestMethods ).', but your Request Method was '.strtoupper( $_SERVER['REQUEST_METHOD'] ).'"}' );
  }
}

